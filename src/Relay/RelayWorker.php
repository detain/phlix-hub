<?php

declare(strict_types=1);

namespace Phlix\Hub\Relay;

use Psr\Container\ContainerInterface;
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request as WorkermanRequest;
use Workerman\Worker;

use function count;
use function is_array;
use function is_string;
use function json_decode;
use function spl_object_id;

/**
 * WebSocket worker that handles server-to-hub relay tunnel connections.
 *
 * Servers connect via WSS to ws://hub:8802 and send a JSON HELLO message
 * as their first message. The worker then registers the connection with
 * TunnelManager and hands off message handling to the appropriate Tunnel.
 *
 * @package Phlix\Hub\Relay
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
     * The `websocket://` scheme makes Workerman bind the
     * {@see \Workerman\Protocols\Websocket} application protocol to the
     * connection. That protocol performs the HTTP `Upgrade` handshake and
     * deframes inbound WebSocket frames, so {@see onMessage()} receives clean,
     * already-deframed payloads (text payloads for the JSON HELLO/HELLO_ACK
     * handshake, binary payloads for the relay frames). The protocol MUST NOT
     * be nulled — doing so disables both the handshake and deframing, leaving
     * onMessage with raw HTTP/WS bytes and the tunnel unable to establish. This
     * mirrors the working {@see ClientRelayWorker} (port 8803) pattern.
     *
     * @return Worker The configured worker instance.
     */
    public function start(): Worker
    {
        $worker = new Worker("websocket://0.0.0.0:{$this->port}");
        $worker->name = 'phlix-hub-relay-ws';
        $worker->count = $this->count;

        // Fired during the WS upgrade handshake (before any frames arrive).
        $worker->onWebSocketConnect = [$this, 'onWebSocketConnect'];
        // Fired with each deframed WS message payload (HELLO text, then frames).
        $worker->onMessage = [$this, 'onMessage'];
        $worker->onClose = [$this, 'onClose'];

        return $worker;
    }

    /**
     * Handle the WebSocket upgrade for an inbound server tunnel connection.
     *
     * Fired by the Workerman WebSocket protocol once the HTTP `Upgrade`
     * handshake completes. The server_id is not yet known here — it is carried
     * in the first deframed message (the JSON HELLO) handled by {@see onMessage()}.
     *
     * @param TcpConnection    $connection New server connection being upgraded.
     * @param WorkermanRequest $request    The WS upgrade HTTP request.
     *
     * @return void
     */
    public function onWebSocketConnect(TcpConnection $connection, WorkermanRequest $request): void
    {
        // Nothing to do on upgrade — the first deframed message carries the
        // JSON HELLO (and the server_id) which onMessage handles.
    }

    /**
     * Handle a deframed message from a server connection.
     *
     * The Websocket protocol has already performed the upgrade handshake and
     * deframing, so $data is a single complete WebSocket message payload. The
     * first such payload is the JSON HELLO (text frame) carrying the server_id;
     * subsequent payloads are binary relay frames delegated to the Tunnel
     * (which buffers + decodes them via its own FrameDecoder).
     *
     * @param TcpConnection $connection Server connection.
     * @param string        $data       Deframed WebSocket message payload.
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

    /**
     * Clear the static connection→tunnel map.
     *
     * Intended for test isolation only — the static map is process-global, so
     * tests that assert {@see getActiveConnectionCount()} must reset it between
     * cases. Production never needs this (connections are removed on close).
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$connTunnels = [];
    }
}
