<?php

declare(strict_types=1);

namespace Phlix\Hub\Relay;

use Phlix\Hub\Common\Logger\LogChannels;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Http\Controllers\ClientMountController;
use Phlix\Hub\Hub\EnrollmentJwtService;
use Psr\Container\ContainerInterface;
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request as WorkermanRequest;
use Workerman\Worker;

use function base64_decode;
use function count;
use function explode;
use function is_array;
use function is_string;
use function json_decode;
use function preg_match;
use function rawurldecode;
use function spl_object_id;
use function str_starts_with;
use function strtr;
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
 *      enrollment JWT (Authorization: Bearer, Sec-WebSocket-Protocol, or the
 *      `token` query parameter).
 *   2. Validate the JWT for that `server_id` via {@see EnrollmentJwtService}.
 *      On failure, close with WS code 4401 (application "unauthorized").
 *   3. On success, delegate to {@see ClientMountController::onWebSocketConnect()}
 *      which binds the client to the matching server tunnel. If no tunnel is
 *      connected for the `server_id`, the connection is closed
 *      (TunnelManager::acceptClient returns null → controller closes).
 *   4. Subsequent binary frames are dispatched to
 *      {@see ClientMountController::onClientMessage()}; close events go to
 *      {@see ClientMountController::onClientClose()}.
 *
 * @package Phlix\Hub\Relay
 * @since 0.5.0
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

        $jwt = self::extractJwt($request);
        if ($jwt === null) {
            $logger->warning('Relay: client WS rejected, missing enrollment JWT', [
                'server_id' => $serverId,
            ]);
            $this->rejectUnauthorized($connection);
            return;
        }

        try {
            if (!$this->validateClientAuth($jwt, $serverId)) {
                $logger->warning('Relay: client WS rejected, invalid enrollment JWT', [
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
     * Validate a client's enrollment JWT for the requested server.
     *
     * Reuses {@see EnrollmentJwtService::validateEnrollmentJwt()} — the same
     * verification path used for the server-facing relay endpoint — rather
     * than implementing any token parsing or crypto here. The JWT's
     * `server_id` claim must match the path-derived server ID.
     *
     * @param string $jwt      The enrollment JWT presented by the client.
     * @param string $serverId The server ID parsed from the request path.
     *
     * @return bool True when the token is valid and scoped to $serverId.
     */
    public function validateClientAuth(string $jwt, string $serverId): bool
    {
        $jwtService = $this->container->get(EnrollmentJwtService::class);
        if (!$jwtService instanceof EnrollmentJwtService) {
            return false;
        }

        $kid = self::extractKid($jwt);
        if ($kid === null) {
            return false;
        }

        $payload = $jwtService->validateEnrollmentJwt($jwt, $kid);
        if ($payload === null) {
            return false;
        }

        return ($payload['server_id'] ?? null) === $serverId;
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
     * Extract the enrollment JWT from the WS upgrade request.
     *
     * Accepts the token in (priority order):
     *   1. `Authorization: Bearer <jwt>`
     *   2. `Sec-WebSocket-Protocol: bearer, <jwt>` (browser-friendly — browser
     *      WebSocket APIs cannot set arbitrary headers but can send subprotocols)
     *   3. `?token=<jwt>` query parameter (last resort; logged-URL exposure)
     *
     * @param WorkermanRequest $request The WS upgrade request.
     *
     * @return string|null The raw JWT, or null when absent.
     */
    public static function extractJwt(WorkermanRequest $request): ?string
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
            // Format: "bearer, <jwt>" — pick the segment that is not the marker.
            foreach ($parts as $part) {
                $candidate = trim($part);
                if ($candidate !== '' && $candidate !== 'bearer') {
                    return $candidate;
                }
            }
        }

        /** @var mixed $queryToken */
        $queryToken = $request->get('token');
        if (is_string($queryToken) && $queryToken !== '') {
            return $queryToken;
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

    /**
     * Extract the `kid` from a JWT header without validating the token.
     *
     * Mirrors the extraction in {@see \Phlix\Hub\Http\Controllers\RelayController}
     * and {@see \Phlix\Hub\Http\Middleware\EnrollmentJwtMiddleware}.
     *
     * @param string $token JWT string.
     *
     * @return string|null Key ID, or null when the header is malformed.
     */
    private static function extractKid(string $token): ?string
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        $decoded = base64_decode(strtr($parts[0], '-_', '+/'), true);
        if ($decoded === false) {
            return null;
        }

        try {
            /** @var mixed $header */
            $header = json_decode($decoded, true, 2, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (!is_array($header)) {
            return null;
        }

        /** @var mixed $kid */
        $kid = $header['kid'] ?? null;

        return is_string($kid) ? $kid : null;
    }
}
