<?php

/**
 * Phlix hub component: Relay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Relay;

use Phlix\Hub\Common\Logger\LogChannels;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Common\RateLimit\RateLimiterInterface;
use Phlix\Hub\Common\RateLimit\RateLimitProfiles;
use Phlix\Hub\Common\Support\Ids;
use Phlix\Hub\Http\Controllers\ClientMountController;
use Phlix\Hub\Hub\ClientRelayTokenService;
use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\Stats\Metrics\MetricsCollector;
use Phlix\Hub\Stats\Metrics\MetricsFlushService;
use Psr\Container\ContainerInterface;
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request as WorkermanRequest;
use Workerman\Timer;
use Workerman\Worker;

use function count;
use function explode;
use function intdiv;
use function is_string;
use function max;
use function preg_match;
use function rawurldecode;
use function spl_object_id;
use function str_starts_with;
use function substr;
use function time;
use function trim;

/**
 * WebSocket worker that handles inbound client relay connections.
 *
 * Remote clients connect via WSS to `ws://hub:8803/client/{server_id}` to
 * reach a NAT'd media server through the hub. This worker is the
 * client-facing counterpart to {@see RelayWorker} (which accepts the
 * server's outbound tunnel on port 8802).
 *
 * Connection lifecycle:
 *   1. WS upgrade — parse `server_id` from the request path and extract the
 *      per-user hub relay token (Authorization: Bearer or
 *      Sec-WebSocket-Protocol; the legacy `?token=` query path was REMOVED in
 *      step S2b so credentials never land in access logs).
 *   2. Validate the relay token via {@see ClientRelayTokenService::validate()},
 *      confirm it is scoped to the path-derived `server_id`, and re-confirm the
 *      resolved user OWNS that server via {@see ServerInfoHandler} — mirroring
 *      the HTTP relay proxy's ownership gate. The server's long-lived 7-day
 *      enrollment JWT is NO LONGER accepted as a client credential. On failure,
 *      close with WS code 4401 (application "unauthorized").
 *   3. On success, delegate to {@see ClientMountController::onWebSocketConnect()}
 *      which binds the client to the matching server tunnel. If no tunnel is
 *      connected for the `server_id`, the connection is closed
 *      (TunnelManager::acceptClient returns null → controller closes).
 *   4. Subsequent binary frames are dispatched to
 *      {@see ClientMountController::onClientMessage()}; close events go to
 *      {@see ClientMountController::onClientClose()}.
 *
 * @package Phlix\Hub\Relay
 */
final class ClientRelayWorker
{
    /**
     * Default client-facing relay WS port (parallel to RelayWorker's 8802).
     */
    public const DEFAULT_PORT = 8803;

    /**
     * WS close code used when client enrollment-JWT auth fails.
     *
     * 4000-4999 is the RFC 6455 range reserved for private/application use.
     * 4401 mirrors HTTP 401 (Unauthorized) for relay clients.
     */
    public const CLOSE_UNAUTHORIZED = 4401;

    /**
     * WS close code used when an inbound client mount is rate-limited.
     *
     * 1013 ("Try Again Later") is the RFC 6455 code for a transient server-side
     * refusal — the client should back off and retry. This is distinct from the
     * auth-failure {@see CLOSE_UNAUTHORIZED} (4401): it throttles the mount rate
     * itself (HB-4.6f), closing the client-mount DoS surface on :8803.
     */
    public const CLOSE_TRY_AGAIN_LATER = 1013;

    /**
     * Map of connection ID => requested server_id, set at WS-connect time so
     * later message/close callbacks know which server the connection targets.
     *
     * @var array<int, string>
     */
    private static array $connServerIds = [];

    /**
     * Live client connections keyed by {@see spl_object_id()}, so the metrics
     * touch timer (armed in {@see onWorkerStart()}) can iterate them and push
     * each one's cumulative `bytesRead`/`bytesWritten` into the registry between
     * flushes. The worker previously kept only {@see $connServerIds}; the touch
     * timer needs the live {@see TcpConnection} objects themselves. Populated
     * only while metrics is enabled, and cleared on close.
     *
     * @var array<int, TcpConnection>
     */
    private static array $liveConnections = [];

    /**
     * Map of connection ID ({@see spl_object_id()}) => stable metrics
     * connection id (a per-connection UUID).
     *
     * `spl_object_id()` is reused once a connection object is destroyed, so it
     * is unsafe as the registry key across a connection's whole lifetime (a new
     * connection could reuse a departed one's id and collide its metrics row).
     * A UUID minted at open time keeps each `metrics_connections` row stable
     * from {@see openConnection} through the final {@see onClose()} touch.
     *
     * @var array<int, string>
     */
    private static array $connMetricsIds = [];

    /**
     * This worker's metrics collector, resolved in {@see onWorkerStart()} when
     * metrics is enabled. Null keeps every connection hook a no-op (so a
     * disabled subsystem carries zero per-connection bookkeeping).
     *
     * @var MetricsCollector|null
     */
    private ?MetricsCollector $metrics = null;

    /**
     * Per-worker client-mount rate limiter (`rate_limiter.client_mount`,
     * 30/60s), resolved in {@see onWorkerStart()} and memoized. Keyed by the
     * connecting client's remote IP in {@see onWebSocketConnect()}. Null keeps
     * the mount hook unlimited (limiter unavailable / not registered). The
     * client-relay worker is `count=1` by default, so this per-worker limit is a
     * true global limit.
     *
     * @var RateLimiterInterface|null
     */
    private ?RateLimiterInterface $clientMountLimiter = null;

    /**
     * @param ContainerInterface $container PSR-11 container for resolving services.
     * @param int                $port      Client-facing WS port (default 8803).
     * @param int                $count     Number of worker processes.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly int $port = self::DEFAULT_PORT,
        private readonly int $count = 1,
    ) {
    }

    /**
     * Start the client relay WebSocket worker.
     *
     * Creates the Workerman Worker instance. The worker is not actually
     * started until Worker::runAll() is called (by Application::boot()).
     *
     * @return Worker The configured worker instance.
     */
    public function start(): Worker
    {
        $worker = new Worker("websocket://0.0.0.0:{$this->port}");
        $worker->name = 'phlix-hub-client-relay-ws';
        $worker->count = $this->count;

        $worker->onWebSocketConnect = [$this, 'onWebSocketConnect'];
        $worker->onMessage = [$this, 'onMessage'];
        $worker->onClose = [$this, 'onClose'];
        // Per-worker metrics wiring: resolve this worker's collector and arm the
        // flush + live-connection touch timers (see onWorkerStart()).
        $worker->onWorkerStart = [$this, 'onWorkerStart'];

        // Close DB connections in onWorkerStop (still in coroutine context) so
        // hooked PDO sockets aren't destroyed at RSHUTDOWN outside a coroutine.
        \Phlix\Hub\Common\Database\ConnectionPool::armWorkerStopCleanup($worker);

        return $worker;
    }

    /**
     * Worker-start hook: resolve this worker's metrics collector and arm its
     * flush + live-connection touch timers.
     *
     * Must be per-worker here (NOT in the master via a service provider) so the
     * connection hooks feed the SAME {@see \Phlix\Hub\Stats\Metrics\MetricsRegistry}
     * instance the flush drains. Unlike {@see RelayWorker::onWorkerStart()} the
     * client worker owns no tunnels and joins no channel broker, so this hook is
     * metrics-only. Guarded end-to-end so a metrics failure never breaks the
     * client relay worker; when metrics is disabled {@see $metrics} stays null
     * and every connection hook no-ops.
     *
     * @return void
     */
    public function onWorkerStart(): void
    {
        // HB-4.6f: resolve this worker's client-mount limiter into a field so the
        // WS-upgrade hook (onWebSocketConnect) can throttle inbound mounts by
        // remote IP. Resolved HERE (onWorkerStart, coroutine context) rather than
        // in the constructor; the limiter is a pure in-memory object (no I/O), so
        // onWebSocketConnect also lazily resolves it if this priming was skipped.
        // Kept OUTSIDE the metrics try/catch below so a metrics-disabled worker
        // still gets the mount limiter primed.
        $this->clientMountLimiter = $this->resolveClientMountLimiter();

        try {
            /** @var mixed $collector */
            $collector = $this->container->get(MetricsCollector::class);
            if (!$collector instanceof MetricsCollector || !$collector->isEnabled()) {
                return;
            }

            $this->metrics = $collector;

            /** @var mixed $flushService */
            $flushService = $this->container->get(MetricsFlushService::class);
            if (!$flushService instanceof MetricsFlushService) {
                return;
            }

            $flushInterval = $flushService->flushIntervalSeconds();
            Timer::add($flushInterval, static function () use ($flushService): void {
                // Best-effort: a transient DB error on a flush tick must never
                // escape the timer and crash the client-relay worker.
                try {
                    // $shouldPrune=false: the client-relay worker flushes + evicts
                    // its own registry but must NOT run the shared-table retention
                    // DELETEs — only the count=1 relay worker prunes, so the
                    // DELETEs aren't multiplied by worker count ([H-W3]).
                    $flushService->flush(0, time(), false);
                } catch (Throwable $e) {
                    LoggerFactory::get(LogChannels::RELAY)->warning(
                        'Metrics: client-relay flush tick failed',
                        ['error' => $e->getMessage()],
                    );
                }
            });

            // Push each live client connection's current cumulative bytes into
            // the registry between flushes so the live-connection panel shows
            // real throughput for the whole connection lifetime (not a zero row
            // until it closes). Touch at least twice per flush window. spl_object_id
            // maps to the stable metrics id via self::$connMetricsIds so a reused
            // object id can never write to a departed connection's row.
            $touchInterval = max(1, intdiv($flushInterval, 2));
            Timer::add($touchInterval, static function () use ($collector): void {
                foreach (self::$liveConnections as $connId => $conn) {
                    $metricsId = self::$connMetricsIds[$connId] ?? null;
                    if ($metricsId === null) {
                        continue;
                    }
                    $collector->touchConnection($metricsId, $conn->bytesRead, $conn->bytesWritten);
                }
            });
        } catch (Throwable $e) {
            LoggerFactory::get(LogChannels::RELAY)->error('Metrics: client relay worker timer init failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle the WebSocket upgrade for an inbound client connection.
     *
     * Fired by the Workerman WebSocket protocol during the handshake. The
     * upgrade {@see WorkermanRequest} carries the path (for `server_id`) and
     * the authentication material (JWT).
     *
     * @param TcpConnection    $connection Client connection being upgraded.
     * @param WorkermanRequest $request    The WS upgrade HTTP request.
     *
     * @return void
     */
    public function onWebSocketConnect(TcpConnection $connection, WorkermanRequest $request): void
    {
        $logger = LoggerFactory::get(LogChannels::RELAY);
        $connId = spl_object_id($connection);

        $serverId = self::parseServerId($request->path());
        if ($serverId === null) {
            $logger->warning('Relay: client WS rejected, missing server_id in path', [
                'path' => $request->path(),
            ]);
            $connection->close('', true);
            return;
        }

        // HB-4.6f: throttle inbound client mounts by remote IP EARLY — before any
        // token extraction / auth / tunnel bind — so a mount flood on :8803 is
        // rejected cheaply and cannot exhaust the auth path. Runs BEFORE
        // validateClientAuth by construction.
        $limiter = $this->clientMountLimiter ??= $this->resolveClientMountLimiter();
        if ($limiter !== null) {
            $ip = $connection->getRemoteIp();
            $state = $limiter->hit('client_mount:' . $ip);
            if ($state->limited) {
                $logger->warning('Relay: client mount rate-limited', [
                    'remote_ip' => $ip,
                    'server_id' => $serverId,
                    'reset_at' => $state->resetAt,
                ]);
                // WS≠HTTP: no HTTP 429 envelope after the upgrade hook — reject by
                // closing with WS code 1013 (try again later). The 429 mapping
                // (HB-4.6g) is HTTP-only. Mirror the rejectUnauthorized pattern.
                $connection->close((string) self::CLOSE_TRY_AGAIN_LATER, true);
                return;
            }
        }

        $token = self::extractClientToken($request);
        if ($token === null) {
            $logger->warning('Relay: client WS rejected, missing relay token', [
                'server_id' => $serverId,
            ]);
            $this->rejectUnauthorized($connection);
            return;
        }

        try {
            $userId = $this->validateClientAuth($token, $serverId);
            if ($userId === null) {
                $logger->warning('Relay: client WS rejected, invalid relay token or ownership', [
                    'server_id' => $serverId,
                ]);
                $this->rejectUnauthorized($connection);
                return;
            }

            $controller = $this->resolveController();
            if ($controller === null) {
                $logger->error('Relay: client WS internal error, controller unavailable', [
                    'server_id' => $serverId,
                ]);
                $connection->close('', true);
                return;
            }

            self::$connServerIds[$connId] = $serverId;

            // S4 metrics: register this client connection as a live connection so
            // the touch timer can push its cumulative byte counts into the
            // registry between flushes, and record the opening row now. Guarded on
            // the collector (resolved in onWorkerStart) so a disabled subsystem
            // mints no id and populates no maps — zero per-connection overhead.
            // Copied to a local so the null-narrowing survives the intervening
            // getRemoteIp() call under strict static analysis.
            $metrics = $this->metrics;
            if ($metrics !== null) {
                $metricsId = Ids::uuidV4();
                self::$connMetricsIds[$connId] = $metricsId;
                self::$liveConnections[$connId] = $connection;
                // kind 'stream': unlike the server tunnel (RelayWorker uses
                // 'websocket' — a control-plane frame multiplexer), a client relay
                // connection IS the media playback path (the fallback used when a
                // direct signed URL is unavailable). sessionId is null (the client
                // relay keeps no relay_sessions row); mediaItemId carries the
                // server_id being reached — the best per-connection correlator
                // available at mount time.
                $metrics->openConnection(
                    $metricsId,
                    'stream',
                    $userId,
                    $connection->getRemoteIp(),
                    null,
                    $serverId,
                );
            }

            // Bind the client to the matching tunnel. The controller closes
            // the connection itself if no active tunnel exists (server_offline);
            // the metrics row is then left for onClose's final touch + the flush
            // TTL to reap (mirrors RelayWorker / the server's WS close hook).
            $controller->onWebSocketConnect($connection, $request, $serverId);
        } catch (Throwable $e) {
            $logger->error('Relay: client WS connect error', [
                'server_id' => $serverId,
                'error' => $e->getMessage(),
            ]);
            unset(
                self::$connServerIds[$connId],
                self::$liveConnections[$connId],
                self::$connMetricsIds[$connId],
            );
            $connection->close('', true);
        }
    }

    /**
     * Handle an inbound binary frame from a connected client.
     *
     * @param TcpConnection $connection Client connection.
     * @param string        $data       Raw WebSocket frame payload.
     *
     * @return void
     */
    public function onMessage(TcpConnection $connection, string $data): void
    {
        $controller = $this->resolveController();
        if ($controller === null) {
            $connection->close();
            return;
        }

        $controller->onClientMessage($connection, $data);
    }

    /**
     * Handle a client connection close.
     *
     * @param TcpConnection $connection Client connection.
     *
     * @return void
     */
    public function onClose(TcpConnection $connection): void
    {
        $connId = spl_object_id($connection);
        unset(self::$connServerIds[$connId]);

        // S4 metrics: record the FINAL cumulative byte counts. Deliberately NOT
        // closeConnection() — that would drop the registry row before the next
        // flush persists it; the flush service TTL-prunes the now-idle row
        // afterwards (mirrors RelayWorker::onClose + the server's WS close hook).
        $metricsId = self::$connMetricsIds[$connId] ?? null;
        if ($metricsId !== null) {
            $this->metrics?->touchConnection($metricsId, $connection->bytesRead, $connection->bytesWritten);
        }
        unset(self::$liveConnections[$connId], self::$connMetricsIds[$connId]);

        $controller = $this->resolveController();
        if ($controller === null) {
            return;
        }

        $controller->onClientClose($connection);
    }

    /**
     * Validate a client's per-user hub relay token for the requested server.
     *
     * This is the security boundary closed by step S2b. The client must
     * present a short-lived, revocable, per-user relay token minted by
     * {@see \Phlix\Hub\Http\Controllers\ClientRelayTokenController} — NOT the
     * server's long-lived 7-day enrollment JWT. The check is three-fold and
     * fails closed at every step:
     *
     *   1. {@see ClientRelayTokenService::validate()} hashes the presented
     *      token and looks up an active (non-expired, non-revoked) row,
     *      returning the bound `user_id` + `server_id` (null on any failure).
     *   2. The token's bound `server_id` must equal the path-derived
     *      `$serverId`, so a token minted for server A cannot mount server B.
     *   3. The resolved user must still OWN that server right now
     *      (`server.user_id === token.user_id`), resolved via
     *      {@see ServerInfoHandler::getServerInfo()} — mirroring the HTTP relay
     *      proxy's ownership gate in
     *      {@see \Phlix\Hub\Http\Controllers\ServerProxyController::proxy()}.
     *
     * Re-confirming ownership at mount (rather than trusting only the token's
     * stored binding) means revoking ownership/transfer of the server cuts
     * relay access immediately, even before the token expires.
     *
     * @param string $token    The per-user relay token presented by the client.
     * @param string $serverId The server ID parsed from the request path.
     *
     * @return string|null The authenticated (owning) user id when the token is
     *                     valid, scoped to $serverId, and the bound user still
     *                     owns that server; null otherwise (fail-closed). The id
     *                     is surfaced so {@see onWebSocketConnect()} can attribute
     *                     the metrics connection row to the user.
     */
    public function validateClientAuth(string $token, string $serverId): ?string
    {
        $tokenService = $this->container->get(ClientRelayTokenService::class);
        if (!$tokenService instanceof ClientRelayTokenService) {
            return null;
        }

        $bound = $tokenService->validate($token);
        if ($bound === null) {
            return null;
        }

        // The token must be scoped to exactly the server being mounted.
        if ($bound['server_id'] !== $serverId) {
            return null;
        }

        $serverInfo = $this->container->get(ServerInfoHandler::class);
        if (!$serverInfo instanceof ServerInfoHandler) {
            return null;
        }

        // Re-confirm current ownership: the bound user must still own the
        // target server (mirrors ServerProxyController::proxy()).  Use the
        // lean getOwnerAndStatus() query to avoid the COUNT(server_libraries)
        // correlated subquery that getServerInfo() runs.
        $owner = $serverInfo->getOwnerAndStatus($serverId);
        if ($owner === null) {
            return null;
        }

        if ($owner['userId'] !== $bound['user_id']) {
            return null;
        }

        return $bound['user_id'];
    }

    /**
     * Parse the `server_id` segment out of a `/client/{server_id}` path.
     *
     * @param string $path Request path (e.g. `/client/abc-123`).
     *
     * @return string|null The decoded server ID, or null if the path does not
     *                      match the client mount shape.
     */
    public static function parseServerId(string $path): ?string
    {
        // Workerman's Request::path() strips any query string, but tolerate a
        // trailing "?..." or "#..." here too in case a full path is passed.
        // Use ~ as the delimiter so the # inside the character classes is literal.
        if (preg_match('~^/client/([^/?#]+)/?(?:[?#].*)?$~', $path, $matches) !== 1) {
            return null;
        }

        $serverId = rawurldecode($matches[1]);
        $serverId = trim($serverId);

        return $serverId !== '' ? $serverId : null;
    }

    /**
     * Extract the per-user hub relay token from the WS upgrade request.
     *
     * Accepts the token in (priority order):
     *   1. `Authorization: Bearer <token>`
     *   2. `Sec-WebSocket-Protocol: bearer, <token>` (browser-friendly — browser
     *      WebSocket APIs cannot set arbitrary headers but can send subprotocols)
     *
     * The legacy `?token=<…>` query-string path was REMOVED in step S2b: query
     * strings routinely land in access/proxy logs and request histories, so a
     * bearer credential must never travel there. Clients must use a header or
     * the WebSocket subprotocol instead.
     *
     * @param WorkermanRequest $request The WS upgrade request.
     *
     * @return string|null The raw relay token, or null when absent.
     */
    public static function extractClientToken(WorkermanRequest $request): ?string
    {
        /** @var mixed $auth */
        $auth = $request->header('authorization');
        if (is_string($auth) && str_starts_with($auth, 'Bearer ')) {
            $token = trim(substr($auth, 7));
            if ($token !== '') {
                return $token;
            }
        }

        /** @var mixed $proto */
        $proto = $request->header('sec-websocket-protocol');
        if (is_string($proto) && $proto !== '') {
            $parts = explode(',', $proto);
            // Format: "bearer, <token>" — pick the segment that is not the marker.
            foreach ($parts as $part) {
                $candidate = trim($part);
                if ($candidate !== '' && $candidate !== 'bearer') {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Get the count of active client connections (diagnostics).
     *
     * @return int Active connection count.
     */
    public static function getActiveConnectionCount(): int
    {
        return count(self::$connServerIds);
    }

    /**
     * Clear all static per-worker connection maps.
     *
     * Intended for test isolation only — the maps are process-global, so tests
     * that assert {@see getActiveConnectionCount()} or the metrics open/close
     * bookkeeping must reset them between cases. Production never needs this
     * (entries are removed on close).
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$connServerIds = [];
        self::$liveConnections = [];
        self::$connMetricsIds = [];
    }

    /**
     * Resolve the client-mount rate limiter (`rate_limiter.client_mount`) from
     * the container.
     *
     * Best-effort: returns null (unlimited mount hook) if the limiter is not
     * registered or cannot be resolved, so a container hiccup never breaks the
     * client-relay worker's mount path.
     *
     * @return RateLimiterInterface|null The client-mount limiter, or null.
     */
    private function resolveClientMountLimiter(): ?RateLimiterInterface
    {
        try {
            /** @var mixed $limiter */
            $limiter = $this->container->get(RateLimitProfiles::CLIENT_MOUNT);
        } catch (Throwable) {
            return null;
        }

        return $limiter instanceof RateLimiterInterface ? $limiter : null;
    }

    /**
     * Reject a connection with the application "unauthorized" close code.
     *
     * @param TcpConnection $connection Connection to close.
     *
     * @return void
     */
    private function rejectUnauthorized(TcpConnection $connection): void
    {
        // The WS handshake has not completed yet at onWebSocketConnect time,
        // so closing here aborts before upgrade. The 4401 code is recorded for
        // observability / parity with the HTTP 401 contract.
        $connection->close((string) self::CLOSE_UNAUTHORIZED, true);
    }

    /**
     * Resolve the ClientMountController from the container.
     *
     * @return ClientMountController|null The controller, or null if unresolvable.
     */
    private function resolveController(): ?ClientMountController
    {
        try {
            /** @var mixed $controller */
            $controller = $this->container->get(ClientMountController::class);
        } catch (Throwable) {
            return null;
        }

        return $controller instanceof ClientMountController ? $controller : null;
    }
}
