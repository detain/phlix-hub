<?php

declare(strict_types=1);

namespace Phlix\Hub\Relay;

use Phlix\Hub\Common\Logger\LogChannels;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Http\Controllers\ClientMountController;
use Phlix\Hub\Hub\ClientRelayTokenService;
use Phlix\Hub\Hub\ServerInfoHandler;
use Psr\Container\ContainerInterface;
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request as WorkermanRequest;
use Workerman\Worker;

use function count;
use function explode;
use function is_string;
use function preg_match;
use function rawurldecode;
use function spl_object_id;
use function str_starts_with;
use function substr;
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
     * Map of connection ID => requested server_id, set at WS-connect time so
     * later message/close callbacks know which server the connection targets.
     *
     * @var array<int, string>
     */
    private static array $connServerIds = [];

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

        return $worker;
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

        $token = self::extractClientToken($request);
        if ($token === null) {
            $logger->warning('Relay: client WS rejected, missing relay token', [
                'server_id' => $serverId,
            ]);
            $this->rejectUnauthorized($connection);
            return;
        }

        try {
            if (!$this->validateClientAuth($token, $serverId)) {
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

            // Bind the client to the matching tunnel. The controller closes
            // the connection itself if no active tunnel exists (server_offline).
            $controller->onWebSocketConnect($connection, $request, $serverId);
        } catch (Throwable $e) {
            $logger->error('Relay: client WS connect error', [
                'server_id' => $serverId,
                'error' => $e->getMessage(),
            ]);
            unset(self::$connServerIds[$connId]);
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
     * @return bool True only when the token is valid, scoped to $serverId, and
     *              the bound user still owns that server.
     */
    public function validateClientAuth(string $token, string $serverId): bool
    {
        $tokenService = $this->container->get(ClientRelayTokenService::class);
        if (!$tokenService instanceof ClientRelayTokenService) {
            return false;
        }

        $bound = $tokenService->validate($token);
        if ($bound === null) {
            return false;
        }

        // The token must be scoped to exactly the server being mounted.
        if ($bound['server_id'] !== $serverId) {
            return false;
        }

        $serverInfo = $this->container->get(ServerInfoHandler::class);
        if (!$serverInfo instanceof ServerInfoHandler) {
            return false;
        }

        // Re-confirm current ownership: the bound user must still own the
        // target server (mirrors ServerProxyController::proxy()).
        $server = $serverInfo->getServerInfo($serverId);
        if ($server === null) {
            return false;
        }

        return $server->userId === $bound['user_id'];
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
