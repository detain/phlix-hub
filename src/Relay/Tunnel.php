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
 * multiplexed through this tunnel, per-client channel routing, and session
 * lifecycle.
 *
 * ## Channel multiplexing (multi-client)
 *
 * Each {@see ClientConnection} attached to this tunnel is assigned a stable,
 * monotonically increasing uint32 **channel id** (1, 2, 3, …). The channel id
 * travels in the frame's `seq` field (see {@see RelayFrame}) on every
 * client-scoped frame:
 *
 *   - CLIENT_CONNECT / CLIENT_DISCONNECT carry the client's channel id, so the
 *     server can open/close the matching local connection.
 *   - Client→server DATA is re-tagged with the originating client's channel id
 *     in {@see sendClientData()} before forwarding to the server.
 *   - Server→client DATA is routed to the single client owning that channel via
 *     {@see sendToClient()} (replacing the old broadcast-to-all behaviour). A
 *     DATA frame for an unknown/closed channel is dropped and logged.
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
        $this->channelClients = [];
        $this->nextChannelId = 0;
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
     * Channel id → ClientConnection routing map.
     *
     * The channel id is the uint32 value carried in DATA / CLIENT_CONNECT /
     * CLIENT_DISCONNECT frames' `seq` field. Server→client DATA frames are
     * routed by looking the client up here; a missing key means the channel is
     * unknown/closed (drop + log).
     *
     * @var array<int, ClientConnection>
     */
    private array $channelClients;

    /**
     * @var int Highest channel id assigned so far (channels start at 1).
     */
    private int $nextChannelId;

    /**
     * @var int Timestamp when the tunnel was opened.
     */
    public readonly int $openedAt;

    /**
     * @var int Timestamp of the last frame received from the server.
     */
    public int $lastFrameAt;

    /**
     * @var int Legacy per-tunnel counter. No longer used for frame routing —
     *          client-scoped frames carry a per-client channel id in `seq`
     *          (see {@see registerClient()} / {@see RelayFrame}). Retained at 0
     *          for diagnostics/back-compat; not incremented.
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
     * @var int Total bytes sent to the server through this tunnel.
     */
    private int $bytesOut = 0;

    /**
     * @var int Total bytes received from the server and sent to clients through this tunnel.
     */
    private int $bytesIn = 0;

    /**
     * Handle an incoming message from the server.
     *
     * During PENDING state: expects JSON HELLO frame, transitions to ACTIVE.
     * During ACTIVE state: decodes binary frames via FrameDecoder and handles:
     *   - DATA → route to the single client owning the frame's channel id
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
            RelayFrameType::DATA => $this->sendToClient($frame->channelId(), $frame),
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

        // Track local byte counter for diagnostics
        $this->bytesOut += strlen($encoded);

        // Record bytes sent to the server in session manager (DB)
        if ($this->relaySessionId !== null) {
            $this->sessionManager->recordBytesOut($this->relaySessionId, strlen($encoded));
        }
    }

    /**
     * Route a server→client DATA frame to the single client owning its channel.
     *
     * The frame's channel id ({@see RelayFrame::channelId()}, i.e. its `seq`
     * field) identifies exactly one client. If no client is mapped to that
     * channel (unknown or already-closed channel) the frame is dropped and a
     * warning is logged — this prevents the old broadcast-to-all cross-talk
     * between concurrent clients.
     *
     * @param int        $channelId Channel id the bytes belong to.
     * @param RelayFrame $frame     DATA frame to deliver.
     *
     * @return void
     */
    public function sendToClient(int $channelId, RelayFrame $frame): void
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return;
        }

        $client = $this->channelClients[$channelId] ?? null;
        if ($client === null) {
            $this->logger->warning('Relay: DATA for unknown/closed channel, dropping', [
                'server_id' => $this->serverId,
                'tunnel_id' => $this->tunnelId,
                'channel_id' => $channelId,
                'payload_len' => strlen($frame->payload),
            ]);
            return;
        }

        $encoded = $this->codec->encode($frame->type, $frame->seq, $frame->payload);
        $frameLen = strlen($encoded);

        $client->sendRaw($encoded);

        // Record bytes in to the session manager for this client (DB)
        if ($this->relaySessionId !== null) {
            $this->sessionManager->recordBytesIn($this->relaySessionId, $frameLen);
        }

        $this->bytesIn += $frameLen;
    }

    /**
     * Forward a client→server DATA frame, tagged with the client's channel id.
     *
     * The hub overwrites the DATA frame's channel/seq field with the
     * originating client's channel id before sending to the server, so the
     * server can demultiplex it back to the correct local connection.
     *
     * @param ClientConnection $client The originating client.
     * @param RelayFrame       $frame  DATA frame received from the client.
     *
     * @return void
     */
    public function sendClientData(ClientConnection $client, RelayFrame $frame): void
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return;
        }

        $tagged = new RelayFrame($frame->type, $client->channelId, $frame->payload);
        $this->sendToServer($tagged);
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

        // Assign a stable channel id for this client (1, 2, 3, …) and record the
        // channel → client mapping used to route server→client DATA frames.
        $this->nextChannelId++;
        $channelId = $this->nextChannelId;
        $client->channelId = $channelId;
        $this->channelClients[$channelId] = $client;

        $this->clientConnections->attach($client);

        // Send CLIENT_CONNECT notification to the server. The channel id travels
        // in the frame's seq field; the JSON payload is observability only.
        $payload = json_encode([
            'client_id' => $client->clientId,
            'session_id' => $client->sessionId,
        ], JSON_THROW_ON_ERROR);

        $clientConnectFrame = new RelayFrame(
            RelayFrameType::CLIENT_CONNECT,
            $channelId,
            $payload,
        );

        $this->sendToServer($clientConnectFrame);

        $this->logger->info('Relay: client registered with tunnel', [
            'server_id' => $this->serverId,
            'tunnel_id' => $this->tunnelId,
            'client_id' => $client->clientId,
            'channel_id' => $channelId,
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

        $channelId = $client->channelId;
        if ($channelId > 0) {
            unset($this->channelClients[$channelId]);
        }

        // Send CLIENT_DISCONNECT notification to the server, tagged with the
        // client's channel id so the server closes the matching local conn.
        if ($this->status === self::STATUS_ACTIVE && $channelId > 0) {
            $payload = json_encode([
                'client_id' => $client->clientId,
            ], JSON_THROW_ON_ERROR);

            $clientDisconnectFrame = new RelayFrame(
                RelayFrameType::CLIENT_DISCONNECT,
                $channelId,
                $payload,
            );

            $this->sendToServer($clientDisconnectFrame);
        }

        $this->logger->info('Relay: client removed from tunnel', [
            'server_id' => $this->serverId,
            'tunnel_id' => $this->tunnelId,
            'client_id' => $client->clientId,
            'channel_id' => $channelId,
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
        $this->channelClients = [];
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

        // HEARTBEAT is tunnel-scoped (no channel) — channel id 0.
        $heartbeatFrame = new RelayFrame(RelayFrameType::HEARTBEAT, 0, '');
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
     * Get total bytes sent to the server through this tunnel.
     *
     * @return int Bytes out counter.
     *
     * @since 0.5.0
     */
    public function getBytesOut(): int
    {
        return $this->bytesOut;
    }

    /**
     * Get total bytes received from the server and sent to clients.
     *
     * @return int Bytes in counter.
     *
     * @since 0.5.0
     */
    public function getBytesIn(): int
    {
        return $this->bytesIn;
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
