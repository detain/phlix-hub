<?php

declare(strict_types=1);

namespace Phlix\Hub\Relay;

use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Relay\FrameDecoder;
use Phlix\Hub\Relay\FrameEncoder;
use Phlix\Shared\Relay\RelayFrame;
use Phlix\Shared\Relay\RelayFrameType;
use Phlix\Shared\Relay\RelayWireCodecInterface;
use SplObjectStorage;
use Workerman\Connection\ConnectionInterface;
use Workerman\Connection\TcpConnection;

use function json_decode;
use function json_encode;
use function strlen;
use function time;

/**
 * Represents a bidirectional WebSocket tunnel between the hub and a server.
 *
 * Manages the server-side WebSocket connection, all client connections
 * multiplexed through this tunnel, frame sequencing, and session lifecycle.
 *
 * State transitions:
 *   PENDING → ACTIVE  (after successful HELLO handshake)
 *   ACTIVE  → CLOSING (on server close or explicit close)
 *   CLOSING → CLOSED  (after cleanup completes)
 *
 * @package Phlix\Hub\Relay
 * @since 0.5.0
 */
final class Tunnel implements TunnelInterface
{
    /**
     * Tunnel is awaiting the HELLO handshake from the server.
     */
    public const STATUS_PENDING = 'pending';

    /**
     * Tunnel is active andFrames can be exchanged.
     */
    public const STATUS_ACTIVE = 'active';

    /**
     * Tunnel is being closed (clean shutdown in progress).
     */
    public const STATUS_CLOSING = 'closing';

    /**
     * Tunnel is fully closed and all resources released.
     */
    public const STATUS_CLOSED = 'closed';

    /**
     * @param string                       $serverId    Server UUID.
     * @param TcpConnection                $serverWs   Workerman connection to the server.
     * @param RelaySessionManager          $sessionManager Session manager for byte accounting.
     * @param RelayWireCodecInterface      $codec      Wire codec for frame encoding/decoding.
     * @param StructuredLogger             $logger     Structured logger for relay events.
     * @param string|null                  $tunnelId   Optional tunnel UUID (generated if null).
     */
    public function __construct(
        public readonly string $serverId,
        public readonly TcpConnection $serverWs,
        private readonly RelaySessionManager $sessionManager,
        private readonly RelayWireCodecInterface $codec,
        private readonly StructuredLogger $logger,
        ?string $tunnelId = null,
    ) {
        $this->tunnelId = $tunnelId ?? $this->generateUuid();
        $this->clientConnections = new SplObjectStorage();
        $this->openedAt = time();
        $this->lastFrameAt = time();
        $this->seq = 0;
        $this->status = self::STATUS_PENDING;
    }

    /**
     * @var string Unique tunnel UUID.
     */
    public readonly string $tunnelId;

    /**
     * @var SplObjectStorage<ClientConnection, mixed> Client connections attached to this tunnel.
     */
    public readonly SplObjectStorage $clientConnections;

    /**
     * @var int Timestamp when the tunnel was opened.
     */
    public readonly int $openedAt;

    /**
     * @var int Timestamp of the last frame received from the server.
     */
    public int $lastFrameAt;

    /**
     * @var int Next sequence number for frames sent to the server.
     */
    public int $seq;

    /**
     * @var string Current tunnel status (STATUS_PENDING|STATUS_ACTIVE|STATUS_CLOSING|STATUS_CLOSED).
     */
    public string $status;

    /**
     * @var string|null Relay session ID (set after HELLO handshake completes).
     */
    public ?string $relaySessionId = null;

    /**
     * @var FrameDecoder|null Stateful decoder for the server connection.
     */
    private ?FrameDecoder $serverDecoder = null;

    /**
     * Handle an incoming message from the server.
     *
     * During PENDING state: expects JSON HELLO frame, transitions to ACTIVE.
     * During ACTIVE state: decodes binary frames via FrameDecoder and handles:
     *   - DATA → broadcast to all clients
     *   - HEARTBEAT → touch lastFrameAt
     *   - other types → log warning and close
     *
     * @param string $data Raw bytes from the server WebSocket.
     *
     * @return void
     */
    public function onServerMessage(string $data): void
    {
        if ($this->status === self::STATUS_CLOSED) {
            return;
        }

        if ($this->status === self::STATUS_PENDING) {
            $this->handleHelloFrame($data);
            return;
        }

        // Active tunnel — decode binary frames
        $this->lastFrameAt = time();

        // Use FrameDecoder to decode binary frames from the server
        if (!isset($this->serverDecoder)) {
            $this->serverDecoder = new FrameDecoder();
        }

        $frame = $this->serverDecoder->decode($data);

        if ($frame === null) {
            // Incomplete frame — continue buffering
            return;
        }

        $this->handleBinaryFrame($frame);
    }

    /**
     * Handle the HELLO handshake frame (JSON text, sent before binary mode).
     *
     * @param string $data JSON text containing HELLO payload.
     *
     * @return void
     */
    private function handleHelloFrame(string $data): void
    {
        try {
            /** @var array<string, mixed>|null $hello */
            $hello = json_decode($data, true, 2, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->warning('Relay: malformed HELLO payload', [
                'server_id' => $this->serverId,
                'tunnel_id' => $this->tunnelId,
                'error' => $e->getMessage(),
            ]);
            $this->close('malformed_hello');
            return;
        }

        if (!is_array($hello)) {
            $this->logger->warning('Relay: malformed HELLO payload', [
                'server_id' => $this->serverId,
                'tunnel_id' => $this->tunnelId,
            ]);
            $this->close('malformed_hello');
            return;
        }

        // Validate HELLO structure
        if (($hello['type'] ?? null) !== 'hello') {
            $this->logger->warning('Relay: expected hello type in HELLO frame', [
                'server_id' => $this->serverId,
                'tunnel_id' => $this->tunnelId,
            ]);
            $this->close('invalid_hello');
            return;
        }

        if (!is_string($hello['enrollment_jwt'] ?? null) || !is_string($hello['server_id'] ?? null)) {
            $this->logger->warning('Relay: missing enrollment_jwt or server_id in HELLO', [
                'server_id' => $this->serverId,
                'tunnel_id' => $this->tunnelId,
            ]);
            $this->close('invalid_hello_payload');
            return;
        }

        // Register the session with RelaySessionManager
        $workerNode = $this->getWorkerNode();
        $this->relaySessionId = $this->sessionManager->registerServer($this->serverId, $workerNode);

        // Transition to active
        $this->status = self::STATUS_ACTIVE;
        $this->lastFrameAt = time();

        $this->logger->info('Relay: tunnel activated', [
            'server_id' => $this->serverId,
            'tunnel_id' => $this->tunnelId,
            'session_id' => $this->relaySessionId,
        ]);

        // Send HELLO_ACK back to the server
        $helloAck = $this->codec->encodeHelloAck($this->relaySessionId, $this->tunnelId);
        $this->serverWs->send($helloAck);
    }

    /**
     * Handle a binary frame decoded from the server.
     *
     * @param RelayFrame $frame Decoded binary frame.
     *
     * @return void
     */
    private function handleBinaryFrame(RelayFrame $frame): void
    {
        $this->lastFrameAt = time();

        match ($frame->type) {
            RelayFrameType::DATA => $this->broadcastToClients($frame),
            RelayFrameType::HEARTBEAT => $this->onHeartbeat($frame),
            default => $this->onUnexpectedFrameType($frame),
        };
    }

    /**
     * Handle a HEARTBEAT frame from the server.
     *
     * @param RelayFrame $frame The heartbeat frame.
     *
     * @return void
     */
    private function onHeartbeat(RelayFrame $frame): void
    {
        $this->lastFrameAt = time();

        // Touch last_frame_at in the session manager (no bytes delta for heartbeats)
        if ($this->relaySessionId !== null) {
            $this->sessionManager->touchLastFrame($this->relaySessionId);
        }

        $this->logger->debug('Relay: heartbeat received from server', [
            'server_id' => $this->serverId,
            'tunnel_id' => $this->tunnelId,
        ]);
    }

    /**
     * Handle an unexpected frame type from the server.
     *
     * @param RelayFrame $frame The unexpected frame.
     *
     * @return void
     */
    private function onUnexpectedFrameType(RelayFrame $frame): void
    {
        $this->logger->warning('Relay: unexpected frame type from server, closing tunnel', [
            'server_id' => $this->serverId,
            'tunnel_id' => $this->tunnelId,
            'frame_type' => $frame->type->label(),
            'seq' => $frame->seq,
        ]);

        $this->close('protocol_error');
    }

    /**
     * Handle server WebSocket close event.
     *
     * @return void
     */
    public function onServerClose(): void
    {
        if ($this->status === self::STATUS_CLOSED) {
            return;
        }

        $this->status = self::STATUS_CLOSING;

        $this->logger->info('Relay: server connection closed', [
            'server_id' => $this->serverId,
            'tunnel_id' => $this->tunnelId,
            'relay_session_id' => $this->relaySessionId,
        ]);

        // Close all client connections with TYPE_DISCONNECTED
        $this->notifyClientsDisconnected('server_closed');

        // Close the session in the database
        if ($this->relaySessionId !== null) {
            $this->sessionManager->closeSession($this->relaySessionId, 'server_disconnected');
        }

        $this->status = self::STATUS_CLOSED;
    }

    /**
     * Send a frame to the server.
     *
     * @param RelayFrame $frame Frame to send.
     *
     * @return void
     */
    public function sendToServer(RelayFrame $frame): void
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            $this->logger->warning('Relay: attempt to send to inactive tunnel', [
                'server_id' => $this->serverId,
                'tunnel_id' => $this->tunnelId,
                'status' => $this->status,
            ]);
            return;
        }

        $encoded = $this->codec->encode($frame->type, $frame->seq, $frame->payload);
        $this->serverWs->send($encoded);

        // Record bytes sent to the server
        if ($this->relaySessionId !== null) {
            $this->sessionManager->recordBytesOut($this->relaySessionId, strlen($encoded));
        }
    }

    /**
     * Broadcast a DATA frame to all connected clients.
     *
     * The frame is encoded once and written to each client connection.
     *
     * @param RelayFrame $frame DATA frame to broadcast.
     *
     * @return void
     */
    public function broadcastToClients(RelayFrame $frame): void
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return;
        }

        // Encode once
        $encoded = $this->codec->encode($frame->type, $frame->seq, $frame->payload);
        $frameLen = strlen($encoded);

        foreach ($this->clientConnections as $client) {
            /** @var ClientConnection $client */
            $client->sendRaw($encoded);

            // Record bytes in to the session manager per client
            if ($this->relaySessionId !== null) {
                $this->sessionManager->recordBytesIn($this->relaySessionId, $frameLen);
            }
        }
    }

    /**
     * Register a new client connection with this tunnel.
     *
     * @param ClientConnection $client Client connection to register.
     *
     * @return void
     */
    public function registerClient(ClientConnection $client): void
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            $this->logger->warning('Relay: attempt to register client on inactive tunnel', [
                'server_id' => $this->serverId,
                'tunnel_id' => $this->tunnelId,
                'status' => $this->status,
            ]);
            return;
        }

        $this->clientConnections->attach($client);

        // Send CLIENT_CONNECT notification to the server
        $this->seq++;
        $payload = json_encode([
            'client_id' => $client->clientId,
            'session_id' => $client->sessionId,
        ], JSON_THROW_ON_ERROR);

        $clientConnectFrame = new RelayFrame(
            RelayFrameType::CLIENT_CONNECT,
            $this->seq,
            $payload,
        );

        $this->sendToServer($clientConnectFrame);

        $this->logger->info('Relay: client registered with tunnel', [
            'server_id' => $this->serverId,
            'tunnel_id' => $this->tunnelId,
            'client_id' => $client->clientId,
        ]);
    }

    /**
     * Remove a client connection from this tunnel.
     *
     * @param ClientConnection $client Client connection to remove.
     *
     * @return void
     */
    public function removeClient(ClientConnection $client): void
    {
        if (!$this->clientConnections->contains($client)) {
            return;
        }

        $this->clientConnections->detach($client);

        // Send CLIENT_DISCONNECT notification to the server
        if ($this->status === self::STATUS_ACTIVE) {
            $this->seq++;
            $payload = json_encode([
                'client_id' => $client->clientId,
            ], JSON_THROW_ON_ERROR);

            $clientDisconnectFrame = new RelayFrame(
                RelayFrameType::CLIENT_DISCONNECT,
                $this->seq,
                $payload,
            );

            $this->sendToServer($clientDisconnectFrame);
        }

        $this->logger->info('Relay: client removed from tunnel', [
            'server_id' => $this->serverId,
            'tunnel_id' => $this->tunnelId,
            'client_id' => $client->clientId,
        ]);
    }

    /**
     * Notify all clients that the tunnel is closing.
     *
     * @param string $reason Human-readable reason for disconnection.
     *
     * @return void
     */
    private function notifyClientsDisconnected(string $reason): void
    {
        $seq = 0;
        $payload = json_encode(['reason' => $reason], JSON_THROW_ON_ERROR);
        $encoded = $this->codec->encode(RelayFrameType::DISCONNECTED, $seq, $payload);

        foreach ($this->clientConnections as $client) {
            /** @var ClientConnection $client */
            $client->sendRaw($encoded);
            $client->close();
        }

        $this->clientConnections->removeAll($this->clientConnections);
    }

    /**
     * Close the tunnel with the given reason.
     *
     * @param string $reason Human-readable close reason.
     *
     * @return void
     */
    public function close(string $reason = 'normal'): void
    {
        if ($this->status === self::STATUS_CLOSED) {
            return;
        }

        $this->status = self::STATUS_CLOSING;

        $this->logger->info('Relay: tunnel closing', [
            'server_id' => $this->serverId,
            'tunnel_id' => $this->tunnelId,
            'relay_session_id' => $this->relaySessionId,
            'reason' => $reason,
        ]);

        // Notify clients
        $this->notifyClientsDisconnected($reason);

        // Close server connection
        $this->serverWs->close();

        // Close session in DB
        if ($this->relaySessionId !== null) {
            $this->sessionManager->closeSession($this->relaySessionId, $reason);
        }

        $this->status = self::STATUS_CLOSED;
    }

    /**
     * Send a heartbeat frame to the server.
     *
     * @return void
     */
    public function sendHeartbeat(): void
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return;
        }

        $this->seq++;
        $heartbeatFrame = new RelayFrame(RelayFrameType::HEARTBEAT, $this->seq, '');
        $this->sendToServer($heartbeatFrame);

        $this->lastFrameAt = time();

        $this->logger->debug('Relay: heartbeat sent to server', [
            'server_id' => $this->serverId,
            'tunnel_id' => $this->tunnelId,
        ]);
    }

    /**
     * Check if the tunnel is stale (no frames received within the threshold).
     *
     * @param int $staleThresholdSeconds Threshold in seconds to consider stale.
     *
     * @return bool True if the tunnel is stale.
     */
    public function isStale(int $staleThresholdSeconds = 90): bool
    {
        return (time() - $this->lastFrameAt) > $staleThresholdSeconds;
    }

    /**
     * @inheritDoc
     */
    public function getTunnelId(): string
    {
        return $this->tunnelId;
    }

    /**
     * @inheritDoc
     */
    public function getServerId(): string
    {
        return $this->serverId;
    }

    /**
     * @inheritDoc
     */
    public function getLastFrameAt(): int
    {
        return $this->lastFrameAt;
    }

    /**
     * @inheritDoc
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * @inheritDoc
     */
    public function getClientConnections(): SplObjectStorage
    {
        return $this->clientConnections;
    }

    /**
     * Get the worker node identifier for this process.
     *
     * @return string Worker node identifier.
     */
    private function getWorkerNode(): string
    {
        /** @var string $hostname */
        $hostname = @gethostname();
        return $hostname ?: 'unknown';
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
