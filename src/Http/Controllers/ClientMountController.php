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
use Workerman\Timer;

use function json_encode;
use function spl_object_id;
use function time;

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

        // Validate the Upgrade header
        if ((($request->headers['Upgrade'] ?? '') !== 'websocket')) {
            return $this->errorResponse(426, 'UPGRADE_REQUIRED', 'This endpoint requires a WebSocket upgrade');
        }

        // TODO: JWT validation from client
        // The client should send an enrollment JWT for the server_id
        // For now, we'll accept connections and validate later

        // Return response indicating WS upgrade should proceed
        // The actual WS handling is done via onWebSocketConnect callback
        // In Workerman, we can't easily intercept the upgrade from a controller
        // Instead, we handle the WS in the Application's HTTP worker with a special handler

        return (new Response())->status(401)->json([
            'error' => 'UNAUTHORIZED',
            'message' => 'Client relay authentication not yet implemented',
        ]);
    }

    /**
     * Handle WebSocket upgrade for client connection.
     *
     * This is called from the HTTP worker's onMessage when a WS upgrade
     * is detected for the /client/{server_id} path.
     *
     * @param TcpConnection $connection Workerman TCP connection.
     * @param Request      $request    The HTTP request (already parsed).
     * @param string       $serverId   Server UUID the client wants to reach.
     *
     * @return void
     */
    public function onWebSocketConnect(TcpConnection $connection, Request $request, string $serverId): void
    {
        $connId = spl_object_id($connection);
        $logger = LoggerFactory::get(LogChannels::RELAY);

        try {
            $tunnelManager = $this->container->get(TunnelManagerInterface::class);
            if (!$tunnelManager instanceof \Phlix\Hub\Relay\TunnelManager) {
                $connection->close('internal_error');
                return;
            }

            // Generate a client ID for this connection
            $clientId = $this->generateUuid();

            // Accept the client connection and attach to tunnel
            $client = $tunnelManager->acceptClient($serverId, $connection, $clientId);

            if ($client === null) {
                // Tunnel not found or not active — reject with 503
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

            // Set up close handler
            $connection->onClose = static function (TcpConnection $conn): void {
                $connId = spl_object_id($conn);
                if (isset(self::$connClients[$connId])) {
                    self::$connClients[$connId]->onClose();
                    unset(self::$connClients[$connId], self::$connDecoders[$connId]);
                }
            };
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
