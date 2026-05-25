<?php

declare(strict_types=1);

namespace Phlix\Hub\Http\Controllers;

use Phlix\Hub\Common\Logger\LogChannels;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Hub\Relay\ClientConnection;
use Phlix\Hub\Relay\TunnelManagerInterface;
use Phlix\Hub\Relay\FrameDecoder;
use Psr\Container\ContainerInterface;
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request as WorkermanRequest;

use function getenv;
use function spl_object_id;

/**
 * Handles inbound client WebSocket connections to reach servers via the relay tunnel.
 *
 * Clients connect via WSS to `GET /client/{server_id}` and authenticate with
 * an enrollment JWT. The hub validates the JWT, looks up the tunnel for the
 * server_id, and if found, creates a ClientConnection and attaches it to
 * the tunnel for frame multiplexing.
 *
 * @package Phlix\Hub\Http\Controllers
 * @since 0.5.0
 */
final class ClientMountController
{
    /**
     * Map of connection ID => ClientConnection for active client connections.
     *
     * @var array<int, ClientConnection>
     */
    private static array $connClients = [];

    /**
     * Map of connection ID => FrameDecoder for streaming frame parsing.
     *
     * @var array<int, FrameDecoder>
     */
    private static array $connDecoders = [];

    /**
     * @param ContainerInterface $container PSR-11 container for resolving TunnelManager.
     */
    public function __construct(
        private readonly ContainerInterface $container,
    ) {
    }

    /**
     * Handle a client connection request to `/client/{server_id}`.
     *
     * Performs JWT validation and upgrades to WebSocket if valid.
     * The first message (after WS handshake) should be the client's
     * authentication token for verification.
     *
     * @param Request          $request Workerman HTTP request.
     * @param array<string, string> $params Route params containing 'server_id'.
     *
     * @return Response
     */
    public function handle(Request $request, array $params): Response
    {
        $serverId = $params['server_id'] ?? '';

        if ($serverId === '') {
            return $this->errorResponse(400, 'MISSING_SERVER_ID', 'Server ID is required');
        }

        // The relay tunnel is established over the dedicated client WS worker
        // (ws://…:8803), NOT over this HTTP route. Authentication, tunnel
        // binding, and bidirectional frame relay all happen there — see
        // {@see \Phlix\Hub\Relay\ClientRelayWorker} and the WS-upgrade
        // handlers below ({@see onWebSocketConnect()} et al).
        //
        // Reaching this method means a caller issued a plain HTTP GET (or a
        // GET without a valid WebSocket upgrade) to the client mount. Mirror
        // RelayController's contract: 426 when no upgrade was requested, and
        // otherwise steer the caller to the WS endpoint with a 501.
        $hubWsHost = getenv('HUB_WS_HOST') ?: getenv('HUB_PUBLIC_DOMAIN') ?: 'your-hub-host';
        $wsEndpoint = 'ws://' . $hubWsHost . ':' . \Phlix\Hub\Relay\ClientRelayWorker::DEFAULT_PORT
            . '/client/' . $serverId;

        if ((($request->headers['Upgrade'] ?? '') !== 'websocket')) {
            return (new Response())
                ->header('X-WS-Endpoint', $wsEndpoint)
                ->status(426)
                ->json([
                    'error' => 'UPGRADE_REQUIRED',
                    'code' => 'relay.client_ws_endpoint',
                    'message' => 'The client relay must be established via WebSocket. Connect to '
                        . $wsEndpoint . ' with your enrollment JWT.',
                    'ws_endpoint' => $wsEndpoint,
                ]);
        }

        return (new Response())
            ->header('X-WS-Endpoint', $wsEndpoint)
            ->status(501)
            ->json([
                'error' => 'NOT_IMPLEMENTED_VIA_HTTP',
                'code' => 'relay.client_ws_endpoint',
                'message' => 'The client relay tunnel must be established over the WebSocket worker. Connect to '
                    . $wsEndpoint . ' with your enrollment JWT.',
                'ws_endpoint' => $wsEndpoint,
            ]);
    }

    /**
     * Handle WebSocket upgrade for a client connection.
     *
     * Invoked by {@see \Phlix\Hub\Relay\ClientRelayWorker} after the
     * enrollment JWT has been validated for $serverId. Binds the client to
     * the matching server tunnel via {@see TunnelManagerInterface::acceptClient()}.
     *
     * The caller (ClientRelayWorker) is responsible for authentication; this
     * method assumes $serverId has already been authorised.
     *
     * @param TcpConnection    $connection Workerman TCP connection.
     * @param WorkermanRequest $request    The WS upgrade request (path/headers).
     * @param string           $serverId   Server UUID the client wants to reach.
     *
     * @return void
     */
    public function onWebSocketConnect(
        TcpConnection $connection,
        WorkermanRequest $request,
        string $serverId,
    ): void {
        $connId = spl_object_id($connection);
        $logger = LoggerFactory::get(LogChannels::RELAY);

        try {
            $tunnelManager = $this->container->get(TunnelManagerInterface::class);
            if (!$tunnelManager instanceof TunnelManagerInterface) {
                $connection->close('internal_error');
                return;
            }

            // Generate a client ID for this connection
            $clientId = $this->generateUuid();

            // Accept the client connection and attach to tunnel
            $client = $tunnelManager->acceptClient($serverId, $connection, $clientId);

            if ($client === null) {
                // No active server tunnel for this server_id — reject.
                // RFC 6455 close: 1011 (server error) is the closest match for
                // "upstream not available"; the client should retry later.
                $logger->info('Relay: client WS closed, no active server tunnel', [
                    'server_id' => $serverId,
                    'client_id' => $clientId,
                ]);
                $connection->close('server_offline');
                return;
            }

            // Store mapping for message handling
            self::$connClients[$connId] = $client;
            self::$connDecoders[$connId] = new FrameDecoder();

            $logger->info('Relay: client WS connected', [
                'server_id' => $serverId,
                'client_id' => $clientId,
            ]);

            // NOTE: connection cleanup is driven by the owning worker's
            // onClose callback ({@see \Phlix\Hub\Relay\ClientRelayWorker::onClose})
            // which dispatches to {@see onClientClose()}. We deliberately do
            // NOT set $connection->onClose here — doing so would override the
            // worker-level callback Workerman copied onto the connection and
            // silently drop the worker's own bookkeeping.
        } catch (Throwable $e) {
            $logger->error('Relay: client mount error', [
                'server_id' => $serverId,
                'error' => $e->getMessage(),
            ]);
            $connection->close('internal_error');
        }
    }

    /**
     * Handle incoming message from a client connection.
     *
     * @param TcpConnection $connection Client connection.
     * @param string        $data       Raw message data.
     *
     * @return void
     */
    public function onClientMessage(TcpConnection $connection, string $data): void
    {
        $connId = spl_object_id($connection);

        $client = self::$connClients[$connId] ?? null;
        $decoder = self::$connDecoders[$connId] ?? null;

        if ($client === null || $decoder === null) {
            $connection->close('unknown_connection');
            return;
        }

        $client->onMessage($data, $decoder);
    }

    /**
     * Handle client connection close.
     *
     * @param TcpConnection $connection Client connection.
     *
     * @return void
     */
    public function onClientClose(TcpConnection $connection): void
    {
        $connId = spl_object_id($connection);

        if (isset(self::$connClients[$connId])) {
            self::$connClients[$connId]->onClose();
            unset(self::$connClients[$connId], self::$connDecoders[$connId]);
        }
    }

    /**
     * Build an error response.
     *
     * @param int    $status HTTP status code.
     * @param string $error  Error code.
     * @param string $message Error message.
     *
     * @return Response
     */
    private function errorResponse(int $status, string $error, string $message): Response
    {
        return (new Response())->status($status)->json([
            'error' => $error,
            'message' => $message,
        ]);
    }

    /**
     * Generate a random UUID v4.
     *
     * @return string UUID string.
     */
    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
        );
    }
}
