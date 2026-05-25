<?php

declare(strict_types=1);

namespace Phlix\Hub\Relay;

use Psr\Container\ContainerInterface;
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Worker;

use function json_decode;
use function json_encode;
use function spl_object_id;

/**
 * WebSocket worker that handles server-to-hub relay tunnel connections.
 *
 * Servers connect via WSS to ws://hub:8802 and send a JSON HELLO message
 * as their first message. The worker then registers the connection with
 * TunnelManager and hands off message handling to the appropriate Tunnel.
 *
 * @package Phlix\Hub\Relay
 * @since 0.5.0
 */
final class RelayWorker
{
    /**
     * Internal map of connection ID => Tunnel for active server connections.
     *
     * @var array<int, Tunnel>
     */
    private static array $connTunnels = [];

    /**
     * @param ContainerInterface $container PSR-11 container for resolving TunnelManager.
     * @param int                 $port     Internal WS port for relay connections.
     * @param int                 $count    Number of worker processes (default 1 for relay ordering).
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly int $port = 8802,
        private readonly int $count = 1,
    ) {
    }

    /**
     * Start the relay WebSocket worker.
     *
     * Creates the Workerman Worker instance. The worker is not actually
     * started until Worker::runAll() is called (by Application::boot()).
     *
     * @return void
     */
    public function start(): void
    {
        $worker = new Worker("websocket://0.0.0.0:{$this->port}");
        $worker->name = 'phlix-hub-relay-ws';
        $worker->count = $this->count;

        // Messages come as raw bytes after WebSocket deframing
        $worker->protocol = null;

        $worker->onMessage = [$this, 'onMessage'];
        $worker->onClose = [$this, 'onClose'];
        $worker->onConnect = [$this, 'onConnect'];
    }

    /**
     * Handle new server connection.
     *
     * @param TcpConnection $connection New server connection.
     *
     * @return void
     */
    public function onConnect(TcpConnection $connection): void
    {
        // Nothing to do on connect — first message carries server_id
    }

    /**
     * Handle incoming message from a server connection.
     *
     * First message must be JSON HELLO with server_id. Subsequent messages
     * are binary frames delegated to the appropriate Tunnel.
     *
     * @param TcpConnection $connection Server connection.
     * @param string          $data       Raw message data.
     *
     * @return void
     */
    public function onMessage(TcpConnection $connection, string $data): void
    {
        $connId = spl_object_id($connection);

        // First message — expect JSON HELLO
        if (!isset(self::$connTunnels[$connId])) {
            $this->handleHello($connection, $data);
            return;
        }

        // Subsequent messages — delegate to tunnel
        self::$connTunnels[$connId]->onServerMessage($data);
    }

    /**
     * Handle the HELLO handshake from a server.
     *
     * @param TcpConnection $connection Server connection.
     * @param string         $data       JSON HELLO payload.
     *
     * @return void
     */
    private function handleHello(TcpConnection $connection, string $data): void
    {
        $connId = spl_object_id($connection);

        try {
            /** @var array<string, mixed>|null $hello */
            $hello = json_decode($data, true, 2, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $connection->close('invalid_hello');
            return;
        }

        if (!is_array($hello)) {
            $connection->close('invalid_hello');
            return;
        }

        $serverId = $hello['server_id'] ?? null;
        if (!is_string($serverId) || $serverId === '') {
            $connection->close('missing_server_id');
            return;
        }

        // Resolve TunnelManager from container and create tunnel
        try {
            $tunnelManager = $this->container->get(TunnelManagerInterface::class);
            if (!$tunnelManager instanceof TunnelManagerInterface) {
                $connection->close('internal_error');
                return;
            }

            $tunnel = $tunnelManager->acceptServer($serverId, $connection);
            self::$connTunnels[$connId] = $tunnel;

            // Let the tunnel process the HELLO (validates + transitions state)
            $tunnel->onServerMessage($data);
        } catch (Throwable $e) {
            $connection->close('internal_error');
        }
    }

    /**
     * Handle server connection close.
     *
     * @param TcpConnection $connection Server connection.
     *
     * @return void
     */
    public function onClose(TcpConnection $connection): void
    {
        $connId = spl_object_id($connection);

        if (isset(self::$connTunnels[$connId])) {
            self::$connTunnels[$connId]->onServerClose();
            unset(self::$connTunnels[$connId]);
        }
    }

    /**
     * Get the count of active server connections.
     *
     * @return int Active connection count.
     */
    public static function getActiveConnectionCount(): int
    {
        return count(self::$connTunnels);
    }
}
