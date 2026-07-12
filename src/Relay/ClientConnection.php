<?php

/**
 * Phlix hub component: Relay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Relay;

use Phlix\Hub\Common\Logger\StructuredLogger;
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
 */
final class ClientConnection
{
    /**
     * @param TcpConnection        $clientWs  Workerman connection to the client.
     * @param string               $serverId  Server UUID this client is connected through.
     * @param string               $clientId  Client UUID (assigned by the hub).
     * @param StructuredLogger     $logger    Structured logger for relay events.
     * @param string               $sessionId Optional relay session ID for this client.
     */
    public function __construct(
        public readonly TcpConnection $clientWs,
        public readonly string $serverId,
        public readonly string $clientId,
        StructuredLogger $logger,
        public readonly string $sessionId = '',
    ) {
        $this->lastFrameAt = time();
        $this->tunnel = null;
        $this->logger = $logger;
    }

    /**
     * @var Tunnel|null Tunnel this client is attached to.
     */
    public ?Tunnel $tunnel;

    /**
     * @var int Per-client channel id assigned by the tunnel at register time
     *          (1, 2, 3, …). 0 means "not yet assigned". Travels in the `seq`
     *          field of this client's CLIENT_CONNECT / DATA / CLIENT_DISCONNECT
     *          frames so the server can demultiplex per client.
     */
    public int $channelId = 0;

    /**
     * @var StructuredLogger Logger for relay events.
     */
    private readonly StructuredLogger $logger;

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

        try {
            $frame = $decoder->decode($data);
        } catch (InvalidFrameTypeException $e) {
            // Undecodable frame or a buffer-overflow attack from the client
            // (H-R7: a dribbling / oversized-length client can otherwise grow the
            // decode buffer without bound). Left unhandled this escapes the
            // Workerman message callback and stops the client relay worker. Close
            // this client's connection cleanly instead.
            $this->logger->warning('Relay: undecodable frame from client, closing connection', [
                'server_id' => $this->serverId,
                'client_id' => $this->clientId,
                'error' => $e->getMessage(),
            ]);
            $this->close();
            return;
        }

        if ($frame === null) {
            // Incomplete frame — continue buffering
            return;
        }

        // Only TYPE_DATA frames are forwarded to the server
        if ($frame->type !== RelayFrameType::DATA) {
            $this->onNonDataFrame($frame);
            return;
        }

        // Forward DATA frames to the server via the tunnel, tagged with this
        // client's channel id so the server routes them to the right local conn.
        if ($this->tunnel !== null) {
            $this->tunnel->sendClientData($this, $frame);
        }
    }

    /**
     * Handle a non-DATA frame from the client.
     *
     * Logs a warning and sends TYPE_ERROR back to the client.
     * Only DATA frames are forwarded through the tunnel.
     *
     * @param RelayFrame $frame The non-DATA frame.
     *
     * @return void
     */
    private function onNonDataFrame(RelayFrame $frame): void
    {
        // Log warning about unexpected frame type
        $this->logger->warning('Relay: unexpected frame type from client, sending error', [
            'server_id' => $this->serverId,
            'client_id' => $this->clientId,
            'frame_type' => $frame->type->label(),
            'seq' => $frame->seq,
        ]);

        // Send TYPE_ERROR back to the client
        $errorPayload = json_encode(['error' => 'Unexpected frame type'], JSON_THROW_ON_ERROR);
        $errorFrame = new RelayFrame(RelayFrameType::ERROR, 0, $errorPayload);
        $encoder = new FrameEncoder();
        $this->send($errorFrame, $encoder);
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
     * @return bool True if the frame was placed in the send buffer, false if
     *              the send buffer is full (caller should apply backpressure).
     */
    public function sendRaw(string $encodedFrame): bool
    {
        $result = $this->clientWs->send($encodedFrame);
        return $result !== false;
    }

    /**
     * Send a frame to the client.
     *
     * @param RelayFrame $frame Frame to send.
     * @param FrameEncoder $encoder Encoder to use.
     *
     * @return bool True if the frame was placed in the send buffer, false if
     *              the send buffer is full (caller should apply backpressure).
     */
    public function send(RelayFrame $frame, FrameEncoder $encoder): bool
    {
        $encoded = $encoder->encode($frame->type, $frame->seq, $frame->payload);
        $result = $this->clientWs->send($encoded);
        return $result !== false;
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
