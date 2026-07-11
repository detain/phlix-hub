<?php

/**
 * Phlix hub component: Relay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Relay;

use Phlix\Hub\Common\Support\Ids;
use Phlix\Hub\Hub\EnrollmentJwtService;
use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\Jwt\JwtHeader;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Relay\FrameDecoder;
use Phlix\Hub\Relay\FrameEncoder;
use Phlix\Shared\Relay\RelayFrame;
use Phlix\Shared\Relay\RelayFrameType;
use Phlix\Shared\Relay\RelayWireCodecInterface;
use SplObjectStorage;
use Throwable;
use Workerman\Connection\ConnectionInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;

use function base64_decode;
use function count;
use function explode;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function strlen;
use function strtr;
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
 */
final class Tunnel implements TunnelInterface
{
    /**
     * Tunnel is awaiting the HELLO handshake from the server.
     */
    public const string STATUS_PENDING = 'pending';

    /**
     * Tunnel is active andFrames can be exchanged.
     */
    public const string STATUS_ACTIVE = 'active';

    /**
     * Tunnel is being closed (clean shutdown in progress).
     */
    public const string STATUS_CLOSING = 'closing';

    /**
     * Tunnel is fully closed and all resources released.
     */
    public const string STATUS_CLOSED = 'closed';

    /**
     * @param string                       $serverId    Server UUID.
     * @param TcpConnection                $serverWs   Workerman connection to the server.
     * @param RelaySessionManager          $sessionManager Session manager for byte accounting.
     * @param RelayWireCodecInterface      $codec      Wire codec for frame encoding/decoding.
     * @param StructuredLogger             $logger     Structured logger for relay events.
     * @param string|null                  $tunnelId   Optional tunnel UUID (generated if null).
     * @param EnrollmentJwtService|null    $jwtService Enrollment-JWT validator. When provided, the
     *                                                 HELLO's enrollment_jwt is cryptographically
     *                                                 verified before the tunnel activates. Null
     *                                                 (test-only) skips validation.
     * @param RelayProxyManager|null       $proxyManager Receives HTTP_RESPONSE frames for the
     *                                                    HTTP-over-relay proxy. Null disables proxying.
     */
    public function __construct(
        public readonly string $serverId,
        public readonly TcpConnection $serverWs,
        private readonly RelaySessionManager $sessionManager,
        private readonly RelayWireCodecInterface $codec,
        private readonly StructuredLogger $logger,
        ?string $tunnelId = null,
        private readonly ?EnrollmentJwtService $jwtService = null,
        private readonly ?RelayProxyManager $proxyManager = null,
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
     * Seconds to wait for a backpressure drain before closing the tunnel.
     * Generous: a live-but-slow connection should never be dropped, only a
     * genuinely stuck one. Mirrors ConnectionResponseSink::BACKPRESSURE_WAIT_SECONDS.
     */
    private const BACKPRESSURE_WAIT_SECONDS = 30.0;

    /**
     * Maximum number of high-priority frames that may be queued while waiting
     * for the server to drain its send buffer. Prevents unbounded memory growth
     * if backpressure persists. Control frames (CLIENT_CONNECT/DISCONNECT,
     * HEARTBEAT, CANCEL) and HTTP_REQUEST_HEAD / HTTP_REQUEST_END frames are
     * queued here so they are always sent before bulk BODY chunks.
     */
    private const MAX_HIGH_PRIORITY_QUEUE = 256;

    /**
     * High-priority frame queue. These frames (control + small JSON) are always
     * sent before any low-priority (body-chunk) frames to prevent a large
     * transfer from stalling browse/segment requests.
     *
     * @var list<RelayFrame>
     */
    private array $pendingHighPriorityFrames = [];

    /**
     * @var int Number of clients with backpressure (send buffer full). When > 0,
     *          serverWs->pauseRecv() has been called to stop receiving from the
     *          server. Each client's onBufferDrain decrements this; when it hits 0,
     *          serverWs->resumeRecv() is called.
     */
    private int $serverBackpressureCount = 0;

    /**
     * @var bool Whether client receives are currently paused due to server
     *          send-buffer backpressure. When true, all clientWs->pauseRecv()
     *          has been called. serverWs->onBufferDrain resumes them.
     */
    private bool $clientBackpressureActive = false;

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

        try {
            $frame = $this->serverDecoder->decode($data);
        } catch (InvalidFrameTypeException $e) {
            // A server that re-handshakes on its existing connection (or a framing
            // desync during a reconnect race) sends a JSON HELLO/HELLO_ACK on an
            // already-ACTIVE tunnel — the 5th byte ('p' of `{"type"`) is read here
            // as frame type 0x70. Letting this bubble out of the Workerman message
            // callback logs a full stack trace per bad frame AND leaves the tunnel
            // wedged in a desynced state (every subsequent frame re-throws). Tear
            // the tunnel down cleanly instead so the server reconnects and
            // re-handshakes from a known-good state.
            $this->logger->warning('Relay: undecodable frame from server, closing tunnel to resync', [
                'server_id' => $this->serverId,
                'tunnel_id' => $this->tunnelId,
                'error' => $e->getMessage(),
            ]);
            $this->close('invalid_frame');
            return;
        }

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

        // Cryptographically validate the enrollment JWT before activating the
        // tunnel. The JWT is Ed25519-signed by the hub at enrollment time, so a
        // valid token proves the connecting server is the one the hub minted
        // for this server_id. Without this check any client could open a tunnel
        // by guessing a server_id (the previous behaviour — trusted blindly).
        if ($this->jwtService !== null && !$this->validateHelloJwt((string) $hello['enrollment_jwt'])) {
            $this->logger->warning('Relay: HELLO enrollment_jwt failed validation, rejecting tunnel', [
                'server_id' => $this->serverId,
                'tunnel_id' => $this->tunnelId,
            ]);
            $this->close('unauthorized');
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
            RelayFrameType::HTTP_RESPONSE => $this->onHttpResponse($frame),
            RelayFrameType::HTTP_CANCEL => $this->onHttpCancel($frame),
            RelayFrameType::HEARTBEAT => $this->onHeartbeat($frame),
            default => $this->onUnexpectedFrameType($frame),
        };
    }

    /**
     * Route an HTTP_RESPONSE frame to the relay proxy manager, which reassembles
     * the streamed chunks and replies to the originating HTTP worker.
     *
     * @param RelayFrame $frame The HTTP_RESPONSE frame.
     *
     * @return void
     */
    private function onHttpResponse(RelayFrame $frame): void
    {
        if ($this->proxyManager === null) {
            $this->logger->warning('Relay: HTTP_RESPONSE received but no proxy manager configured', [
                'server_id' => $this->serverId,
                'tunnel_id' => $this->tunnelId,
            ]);
            return;
        }

        $this->proxyManager->onResponseFrame($frame);
    }

    /**
     * Handle an HTTP_CANCEL frame arriving from a server.
     *
     * This would only occur if a server sent a cancel acknowledgement. The
     * cancel is always initiated by the hub, so this is a no-op with a debug
     * log. It exists for symmetry and to prevent the unexpected-frame
     * close path.
     *
     * @param RelayFrame $frame The HTTP_CANCEL frame (request id in seq).
     *
     * @return void
     */
    private function onHttpCancel(RelayFrame $frame): void
    {
        $this->logger->debug('Relay: HTTP_CANCEL received from server', [
            'server_id' => $this->serverId,
            'tunnel_id' => $this->tunnelId,
            'request_id' => $frame->channelId(),
        ]);
    }

    /**
     * Validate the HELLO enrollment JWT against the hub's enrollment key.
     *
     * @param string $jwt The enrollment JWT from the HELLO payload.
     *
     * @return bool True when the token is valid and scoped to this server_id.
     */
    private function validateHelloJwt(string $jwt): bool
    {
        if ($this->jwtService === null) {
            return true;
        }

        $kid = JwtHeader::kid($jwt);
        if ($kid === null) {
            return false;
        }

        $payload = $this->jwtService->validateEnrollmentJwt($jwt, $kid);
        if ($payload === null) {
            return false;
        }

        return ($payload['server_id'] ?? null) === $this->serverId;
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

        // Fail any in-flight proxy requests for this server.
        $this->proxyManager?->failServer($this->serverId);

        // Close the session in the database
        if ($this->relaySessionId !== null) {
            $this->sessionManager->closeSession($this->relaySessionId, 'server_disconnected');
        }

        $this->status = self::STATUS_CLOSED;
    }

    /**
     * Flush the high-priority frame queue, sending all queued frames in FIFO order.
     * If the server's send buffer is full, backpressure is applied and the
     * queue is left intact so the next call can retry.
     */
    private function flushHighPriorityQueue(): void
    {
        while (!empty($this->pendingHighPriorityFrames)) {
            $frame = array_shift($this->pendingHighPriorityFrames);
            $encoded = $this->codec->encode($frame->type, $frame->seq, $frame->payload);
            if ($this->serverWs->send($encoded) === false) {
                array_unshift($this->pendingHighPriorityFrames, $frame);
                $this->handleServerSendBackpressure();
                return;
            }
            $this->bytesOut += strlen($encoded);
            if ($this->relaySessionId !== null) {
                $this->sessionManager->recordBytesOut($this->relaySessionId, strlen($encoded));
            }
        }
    }

    /**
     * Classify a frame for priority queue placement.
     *
     * CONTROL priority (true) — enqueued and always flushed before low-priority:
     *   - All non-HTTP_REQUEST frames (CLIENT_CONNECT/DISCONNECT, HEARTBEAT,
     *     CANCEL, etc.)
     *   - HTTP_REQUEST frames carrying KIND_HEAD ('head') or KIND_END ('end')
     *
     * BODY priority (false) — sent after flushing the high-priority queue:
     *   - HTTP_REQUEST KIND_BODY ('body') chunks; these are the large bulk-data
     *     frames that could starve control traffic if not paced.
     *
     * @param RelayFrame $frame
     *
     * @return bool true = high-priority (control/JSON), false = low-priority (body)
     */
    private function isHighPriorityFrame(RelayFrame $frame): bool
    {
        if ($frame->type !== RelayFrameType::HTTP_REQUEST) {
            return true;
        }
        $decoded = json_decode($frame->payload, true, 3, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !isset($decoded['kind'])) {
            return true;
        }
        return ($decoded['kind'] ?? '') !== 'body';
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

        $isHighPriority = $this->isHighPriorityFrame($frame);

        // HB-3.3: When sending a LOW-PRIORITY body chunk, always drain any
        // pending HIGH-PRIORITY control frames first. This prevents a large
        // media transfer from stalling browse/segment requests on the same
        // server tunnel — the fairness requirement.
        if (!$isHighPriority) {
            $this->flushHighPriorityQueue();
        }

        $encoded = $this->codec->encode($frame->type, $frame->seq, $frame->payload);

        // HIGH-PRIORITY frames (control/JSON): send immediately unless server
        // send buffer is full. In that case, queue it so it can be drained when
        // backpressure clears (low-priority frames trigger the drain).
        // LOW-PRIORITY frames (body chunks): sent directly after high-priority
        // queue is drained.
        if ($isHighPriority) {
            if ($this->serverWs->send($encoded) === false) {
                if (count($this->pendingHighPriorityFrames) >= self::MAX_HIGH_PRIORITY_QUEUE) {
                    $this->logger->warning('Relay: high-priority queue full, dropping control frame', [
                        'server_id' => $this->serverId,
                        'tunnel_id' => $this->tunnelId,
                        'queue_size' => count($this->pendingHighPriorityFrames),
                    ]);
                    $this->handleServerSendBackpressure();
                    return;
                }
                $this->pendingHighPriorityFrames[] = $frame;
                $this->handleServerSendBackpressure();
                return;
            }
            $this->bytesOut += strlen($encoded);
            if ($this->relaySessionId !== null) {
                $this->sessionManager->recordBytesOut($this->relaySessionId, strlen($encoded));
            }
            return;
        }

        // Apply backpressure if the server's send buffer is full. The tunnel
        // assumes reliable delivery — if we can't apply backpressure, close
        // the tunnel so the client sees a hard failure rather than corruption.
        if ($this->serverWs->send($encoded) === false) {
            $this->handleServerSendBackpressure();
            return;
        }

        $this->bytesOut += strlen($encoded);

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

        // Apply backpressure if the client's send buffer is full. Never silently
        // drop a DATA frame — the tunnel assumes reliable delivery; a dropped
        // frame means silent stream corruption.
        if ($client->sendRaw($encoded) === false) {
            $this->handleClientSendBackpressure($client);
            return;
        }

        // Record bytes in to the session manager for this client (DB)
        if ($this->relaySessionId !== null) {
            $this->sessionManager->recordBytesIn($this->relaySessionId, $frameLen);
        }

        $this->bytesIn += $frameLen;
    }

    /**
     * Handle backpressure when a client's send buffer is full.
     *
     * When a slow client can't accept more DATA frames, we pause receiving
     * from the server (upstream backpressure). Each client's onBufferDrain
     * callback resumes the server when that specific client's buffer drains.
     * The count prevents premature resume if multiple clients are congested.
     *
     * @param ClientConnection $client The client whose send buffer is full.
     *
     * @return void
     */
    private function handleClientSendBackpressure(ClientConnection $client): void
    {
        if ($this->serverBackpressureCount === 0) {
            // First client with backpressure — pause the server.
            $this->serverWs->pauseRecv();
            $this->logger->warning('Relay: client send buffer full, pausing server recv', [
                'server_id' => $this->serverId,
                'tunnel_id' => $this->tunnelId,
                'client_id' => $client->clientId,
            ]);
        }

        $this->serverBackpressureCount++;

        // One-shot drain handler for this specific client. When this client's
        // send buffer drains, decrement the count and resume server recv if
        // no other clients are still congested.
        $client->clientWs->onBufferDrain = function () use ($client): void {
            $client->clientWs->onBufferDrain = null;
            $this->serverBackpressureCount--;

            $this->logger->debug('Relay: client send buffer drained, server backpressure count', [
                'server_id' => $this->serverId,
                'tunnel_id' => $this->tunnelId,
                'client_id' => $client->clientId,
                'remaining_count' => $this->serverBackpressureCount,
            ]);

            if ($this->serverBackpressureCount === 0) {
                $this->serverWs->resumeRecv();
                $this->logger->info('Relay: all client buffers drained, resuming server recv', [
                    'server_id' => $this->serverId,
                    'tunnel_id' => $this->tunnelId,
                ]);
            }
        };

        // Safety timeout: if the drain never comes within BACKPRESSURE_WAIT_SECONDS,
        // close the tunnel so the client sees a hard failure rather than corruption.
        Timer::add(self::BACKPRESSURE_WAIT_SECONDS, function () use ($client): void {
            if ($this->status === self::STATUS_CLOSED) {
                return;
            }
            if ($this->serverBackpressureCount > 0) {
                $this->logger->error('Relay: backpressure timeout, closing tunnel', [
                    'server_id' => $this->serverId,
                    'tunnel_id' => $this->tunnelId,
                    'client_id' => $client->clientId,
                    'backpressure_count' => $this->serverBackpressureCount,
                ]);
                $this->close('backpressure_timeout');
            }
        }, [], false);
    }

    /**
     * Handle backpressure when the server's send buffer is full.
     *
     * When the server can't accept more data (its send buffer is full), we
     * pause all clients' receiving (upstream backpressure on the client side).
     * The server's onBufferDrain callback resumes all clients when it drains.
     *
     * @return void
     */
    private function handleServerSendBackpressure(): void
    {
        if (!$this->clientBackpressureActive) {
            // Pause all clients first.
            foreach ($this->clientConnections as $client) {
                /** @var ClientConnection $client */
                $client->clientWs->pauseRecv();
            }
            $this->clientBackpressureActive = true;

            $this->logger->warning('Relay: server send buffer full, pausing all client recv', [
                'server_id' => $this->serverId,
                'tunnel_id' => $this->tunnelId,
                'client_count' => count($this->clientConnections),
            ]);
        }

        // One-shot drain handler on the server. When the server's send buffer
        // drains, resume all clients and clear the flag.
        $serverWs = $this->serverWs;
        $serverWs->onBufferDrain = function () use ($serverWs): void {
            $serverWs->onBufferDrain = null;
            $this->clientBackpressureActive = false;

            foreach ($this->clientConnections as $client) {
                /** @var ClientConnection $client */
                $client->clientWs->resumeRecv();
            }

            $this->logger->info('Relay: server send buffer drained, resuming all client recv', [
                'server_id' => $this->serverId,
                'tunnel_id' => $this->tunnelId,
            ]);
        };

        // Safety timeout: if the drain never comes, close the tunnel so
        // clients see a hard failure rather than an indefinitely stalled stream.
        Timer::add(self::BACKPRESSURE_WAIT_SECONDS, function (): void {
            if ($this->status === self::STATUS_CLOSED) {
                return;
            }
            if ($this->clientBackpressureActive) {
                $this->logger->error('Relay: server backpressure timeout, closing tunnel', [
                    'server_id' => $this->serverId,
                    'tunnel_id' => $this->tunnelId,
                ]);
                $this->close('backpressure_timeout');
            }
        }, [], false);
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

        // Clean up any backpressure state: resume any paused receives before
        // closing so connections aren't left in a stuck/paused state.
        if ($this->serverBackpressureCount > 0) {
            $this->serverWs->resumeRecv();
            $this->serverBackpressureCount = 0;
        }
        if ($this->clientBackpressureActive) {
            foreach ($this->clientConnections as $client) {
                /** @var ClientConnection $client */
                $client->clientWs->resumeRecv();
            }
            $this->clientBackpressureActive = false;
        }

        // Notify clients
        $this->notifyClientsDisconnected($reason);

        // Fail any in-flight proxy requests for this server.
        $this->proxyManager?->failServer($this->serverId);

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
     * Send an HTTP_CANCEL frame to the server for a given relay request id.
     *
     * Issued by RelayProxyManager when the browser abandons a streaming request
     * so the server can stop transferring bytes for that request early.
     *
     * @param int $requestId The relay (not client) request id to cancel.
     *
     * @return void
     */
    public function sendCancel(int $requestId): void
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return;
        }

        $cancelFrame = new RelayFrame(RelayFrameType::HTTP_CANCEL, $requestId, '');
        $this->sendToServer($cancelFrame);

        $this->logger->debug('Relay: HTTP_CANCEL sent to server', [
            'server_id' => $this->serverId,
            'tunnel_id' => $this->tunnelId,
            'request_id' => $requestId,
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
     * Get the current FrameDecoder buffer size in bytes.
     *
     * @return int Bytes currently buffered (0 if no decoding in progress).
     */
    public function getDecodeBufferSize(): int
    {
        return $this->serverDecoder !== null ? $this->serverDecoder->getBufferSize() : 0;
    }

    /**
     * Get total bytes sent to the server through this tunnel.
     *
     * @return int Bytes out counter.
     *
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
        return Ids::uuidV4();
    }
}
