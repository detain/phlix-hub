<?php

declare(strict_types=1);

namespace Phlix\Hub\Relay;

use Phlix\Hub\Relay\FrameDecoder;
use Phlix\Hub\Relay\FrameEncoder;
use Phlix\Shared\Relay\RelayFrame;
use Phlix\Shared\Relay\RelayFrameType;
use Workerman\Connection\TcpConnection;

use function strlen;
use function time;

/**
 * Represents a single client WebSocket connection multiplexed through a tunnel.
 *
 * Each remote client connects to the hub via WSS and is tracked as a
 * ClientConnection attached to a specific server Tunnel.
 *
 * @package Phlix\Hub\Relay
 * @since 0.5.0
 */
final class ClientConnection
{
    /**
     * @param TcpConnection  $clientWs  Workerman connection to the client.
     * @param string         $serverId Server UUID this client is connected through.
     * @param string         $clientId Client UUID (assigned by the hub).
     * @param string         $sessionId Optional relay session ID for this client.
     */
    public function __construct(
        public readonly TcpConnection $clientWs,
        public readonly string $serverId,
        public readonly string $clientId,
        public readonly string $sessionId = '',
    ) {
        $this->lastFrameAt = time();
        $this->tunnel = null;
    }

    /**
     * @var Tunnel|null Tunnel this client is attached to.
     */
    public ?Tunnel $tunnel;

    /**
     * @var int Timestamp of the last frame received from the client.
     */
    public int $lastFrameAt;

    /**
     * Handle an incoming message from the client.
     *
     * Only TYPE_DATA frames are forwarded to the server.
     * Other frame types are logged and discarded.
     *
     * @param string            $data   Raw bytes from the client WebSocket.
     * @param FrameDecoder      $decoder Frame decoder for parsing binary frames.
     *
     * @return void
     */
    public function onMessage(string $data, FrameDecoder $decoder): void
    {
        $this->lastFrameAt = time();

        $frame = $decoder->decode($data);

        if ($frame === null) {
            // Incomplete frame — continue buffering
            return;
        }

        // Only TYPE_DATA frames are forwarded to the server
        if ($frame->type !== RelayFrameType::DATA) {
            $this->onNonDataFrame($frame);
            return;
        }

        // Forward DATA frames to the server via the tunnel
        if ($this->tunnel !== null) {
            $this->tunnel->broadcastToClients($frame);
        }
    }

    /**
     * Handle a non-DATA frame from the client.
     *
     * Logs a warning and discards the frame. Only DATA frames are
     * forwarded through the tunnel.
     *
     * @param RelayFrame $frame The non-DATA frame.
     *
     * @return void
     */
    private function onNonDataFrame(RelayFrame $frame): void
    {
        // Log warning about unexpected frame type but don't forward
        // (clients should only send DATA frames in the multiplexed protocol)
    }

    /**
     * Handle client WebSocket close event.
     *
     * Notifies the tunnel to send TYPE_CLIENT_DISCONNECT upstream.
     *
     * @return void
     */
    public function onClose(): void
    {
        if ($this->tunnel !== null) {
            $this->tunnel->removeClient($this);
        }
    }

    /**
     * Send a raw encoded frame to the client.
     *
     * @param string $encodedFrame Already-encoded binary frame.
     *
     * @return void
     */
    public function sendRaw(string $encodedFrame): void
    {
        $this->clientWs->send($encodedFrame);
    }

    /**
     * Send a frame to the client.
     *
     * @param RelayFrame $frame Frame to send.
     * @param FrameEncoder $encoder Encoder to use.
     *
     * @return void
     */
    public function send(RelayFrame $frame, FrameEncoder $encoder): void
    {
        $encoded = $encoder->encode($frame->type, $frame->seq, $frame->payload);
        $this->clientWs->send($encoded);
    }

    /**
     * Close the client connection.
     *
     * @return void
     */
    public function close(): void
    {
        $this->clientWs->close();
    }

    /**
     * Touch the last frame timestamp (called on any activity).
     *
     * @return void
     */
    public function touchLastFrame(): void
    {
        $this->lastFrameAt = time();
    }
}
