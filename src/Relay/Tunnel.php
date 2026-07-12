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
     * Maximum number of low-priority BODY frames that may be re-queued while the
     * server's send buffer is full. When the server is congested we pause all
     * client reads, so in practice at most the single in-flight frame lands here;
     * the cap is a safety valve. If it is exceeded we close the tunnel (a hard,
     * visible failure) rather than either dropping a frame (silent corruption)
     * or buffering unbounded (memory leak in the resident worker).
     */
    private const MAX_BODY_QUEUE = 256;

    /**
     * Maximum number of server→client DATA frames that may be re-queued per
     * client while that client's send buffer is full. When a client is congested
     * we pause the server, so at most the single in-flight frame lands here; the
     * cap is a safety valve. Exceeding it closes the tunnel (hard failure) rather
     * than dropping a DATA frame (silent corruption).
     */
    private const MAX_CLIENT_QUEUE = 256;

    /**
     * High-priority frame queue. These frames (control + small JSON) are always
     * sent before any low-priority (body-chunk) frames to prevent a large
     * transfer from stalling browse/segment requests.
     *
     * @var list<RelayFrame>
     */
    private array $pendingHighPriorityFrames = [];

    /**
     * Low-priority BODY frame queue toward the server. A BODY frame whose
     * {@see TcpConnection::send()} returned false (send buffer full — Workerman
     * DROPS the package before returning) is re-queued here instead of being
     * lost, and re-sent (in FIFO order, after the high-priority queue) when the
     * server's send buffer drains. This closes the silent-drop hole on the
     * server body path — the tunnel assumes reliable delivery.
     *
     * @var list<RelayFrame>
     */
    private array $pendingBodyFrames = [];

    /**
     * Per-client re-queue of server→client DATA frames, keyed by channel id.
     * A DATA frame whose {@see ClientConnection::sendRaw()} returned false (the
     * client's send buffer is full — Workerman DROPS the package before
     * returning) is re-queued here (as an already-encoded wire string) instead
     * of being lost, and re-sent when that client's send buffer drains. This
     * closes the silent-drop hole on the client DATA path.
     *
     * @var array<int, list<string>>
     */
    private array $pendingClientFrames = [];

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
     * @var int|null Timer id of the safety timer for the CURRENT client
     *          backpressure episode (server paused because ≥1 client is
     *          congested). Armed when serverBackpressureCount goes 0→1 and
     *          cancelled (Timer::del) when it returns to 0, so a stale timer
     *          left over from an already-drained episode cannot fire and
     *          false-close a tunnel whose current congestion is within budget.
     */
    private ?int $clientBackpressureTimerId = null;

    /**
     * @var int|null Timer id of the safety timer for the CURRENT server
     *          backpressure episode (all clients paused because the server send
     *          buffer is full). Armed when clientBackpressureActive goes
     *          false→true and cancelled when the server drains, so a stale timer
     *          from a drained episode cannot false-close a healthy tunnel.
     */
    private ?int $serverBackpressureTimerId = null;

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
     * Re-queue a HIGH-PRIORITY (control) frame that could not be sent because the
     * server's send buffer was full, or because a control backlog already exists
     * (preserving strict FIFO). Bounded by {@see MAX_HIGH_PRIORITY_QUEUE};
     * exceeding the bound closes the tunnel (a hard, visible failure) — mirroring
     * {@see enqueueBodyFrame}/{@see enqueueClientFrame} — rather than dropping the
     * frame: a dropped CANCEL/CLIENT_DISCONNECT would reintroduce exactly the
     * silent reliable-delivery drop HB-1.2 exists to eliminate.
     *
     * @param RelayFrame $frame The control frame to re-queue.
     *
     * @return void
     */
    private function enqueueHighPriorityFrame(RelayFrame $frame): void
    {
        if (count($this->pendingHighPriorityFrames) >= self::MAX_HIGH_PRIORITY_QUEUE) {
            $this->logger->error('Relay: high-priority queue full under backpressure, closing tunnel', [
                'server_id' => $this->serverId,
                'tunnel_id' => $this->tunnelId,
                'queue_size' => count($this->pendingHighPriorityFrames),
            ]);
            $this->close('backpressure_overflow');
            return;
        }
        $this->pendingHighPriorityFrames[] = $frame;
    }

    /**
     * Flush the low-priority BODY frame queue toward the server, in FIFO order.
     * If the server's send buffer fills again, the frame is left at the head of
     * the queue and backpressure is (re-)applied so the next drain retries it —
     * no BODY frame is ever discarded.
     *
     * @return void
     */
    private function flushBodyQueue(): void
    {
        while (!empty($this->pendingBodyFrames)) {
            $frame = $this->pendingBodyFrames[0];
            $encoded = $this->codec->encode($frame->type, $frame->seq, $frame->payload);
            if ($this->serverWs->send($encoded) === false) {
                // Still full — leave the frame queued and re-arm backpressure.
                $this->handleServerSendBackpressure();
                return;
            }
            array_shift($this->pendingBodyFrames);
            $this->bytesOut += strlen($encoded);
            if ($this->relaySessionId !== null) {
                $this->sessionManager->recordBytesOut($this->relaySessionId, strlen($encoded));
            }
        }
    }

    /**
     * Re-queue a BODY frame that could not be sent because the server's send
     * buffer was full. Bounded by {@see MAX_BODY_QUEUE}; exceeding the bound
     * closes the tunnel (hard, visible failure) rather than dropping the frame
     * (silent corruption) or buffering unbounded.
     *
     * @param RelayFrame $frame The BODY frame to re-queue.
     *
     * @return void
     */
    private function enqueueBodyFrame(RelayFrame $frame): void
    {
        if (count($this->pendingBodyFrames) >= self::MAX_BODY_QUEUE) {
            $this->logger->error('Relay: body queue full under backpressure, closing tunnel', [
                'server_id' => $this->serverId,
                'tunnel_id' => $this->tunnelId,
                'queue_size' => count($this->pendingBodyFrames),
            ]);
            $this->close('backpressure_overflow');
            return;
        }
        $this->pendingBodyFrames[] = $frame;
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
            // If a control backlog already exists this frame must NOT be sent
            // ahead of it. onBufferDrain fires only when the send buffer reaches
            // EMPTY, but send() resumes succeeding once the buffer drops below
            // the high-watermark — so a direct send in the window between a
            // failed send and the drain callback would let a newly generated
            // control frame (HEARTBEAT, CLIENT_CONNECT/DISCONNECT, CANCEL, or an
            // HTTP_REQUEST head/end) overtake still-queued control frames. The
            // tunnel assumes in-order reliable delivery, so enqueue to preserve
            // strict FIFO; the queue is flushed (before any body frame) on drain.
            if (!empty($this->pendingHighPriorityFrames)) {
                $this->enqueueHighPriorityFrame($frame);
                $this->handleServerSendBackpressure();
                return;
            }

            if ($this->serverWs->send($encoded) === false) {
                $this->enqueueHighPriorityFrame($frame);
                $this->handleServerSendBackpressure();
                return;
            }
            $this->bytesOut += strlen($encoded);
            if ($this->relaySessionId !== null) {
                $this->sessionManager->recordBytesOut($this->relaySessionId, strlen($encoded));
            }
            return;
        }

        // If a backlog already exists (control frames still queued after the
        // flush above, or body frames from a prior drop), this body frame must
        // NOT be sent ahead of it — that would both reorder the stream and race
        // a full buffer. Re-queue it to preserve FIFO order; it is flushed on
        // drain. A non-empty queue implies backpressure is already armed.
        if (!empty($this->pendingHighPriorityFrames) || !empty($this->pendingBodyFrames)) {
            $this->enqueueBodyFrame($frame);
            return;
        }

        // Apply backpressure if the server's send buffer is full. Workerman
        // DROPS the package before send() returns false, so the frame must be
        // re-queued here — never silently dropped (the tunnel assumes reliable
        // delivery; a lost BODY frame is silent stream corruption). It is
        // re-sent when the server's send buffer drains; if the drain never
        // comes the backpressure timeout closes the tunnel (hard failure).
        if ($this->serverWs->send($encoded) === false) {
            $this->enqueueBodyFrame($frame);
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

        // If this client already has queued frames it is congested — do not send
        // ahead of the backlog (that would reorder its stream). Re-queue to
        // preserve FIFO order; the backlog is flushed on this client's drain.
        if (!empty($this->pendingClientFrames[$channelId])) {
            $this->enqueueClientFrame($client, $encoded);
            return;
        }

        // Apply backpressure if the client's send buffer is full. Workerman
        // DROPS the package before send() returns false, so the frame must be
        // re-queued here — never silently dropped (the tunnel assumes reliable
        // delivery; a lost DATA frame is silent stream corruption). It is
        // re-sent when this client's send buffer drains; if the drain never
        // comes the backpressure timeout closes the tunnel (hard failure).
        if ($client->sendRaw($encoded) === false) {
            $this->enqueueClientFrame($client, $encoded);
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
     * Re-queue an already-encoded server→client DATA frame that could not be
     * sent because the client's send buffer was full. Bounded per client by
     * {@see MAX_CLIENT_QUEUE}; exceeding the bound closes the tunnel (hard,
     * visible failure) rather than dropping the frame (silent corruption) or
     * buffering unbounded in the resident worker.
     *
     * @param ClientConnection $client  The congested client.
     * @param string           $encoded The already-encoded wire frame.
     *
     * @return void
     */
    private function enqueueClientFrame(ClientConnection $client, string $encoded): void
    {
        $channelId = $client->channelId;
        if (count($this->pendingClientFrames[$channelId] ?? []) >= self::MAX_CLIENT_QUEUE) {
            $this->logger->error('Relay: client queue full under backpressure, closing tunnel', [
                'server_id' => $this->serverId,
                'tunnel_id' => $this->tunnelId,
                'client_id' => $client->clientId,
                'channel_id' => $channelId,
            ]);
            $this->close('backpressure_overflow');
            return;
        }
        $this->pendingClientFrames[$channelId][] = $encoded;
    }

    /**
     * Re-send any queued DATA frames for a client whose send buffer has drained.
     * Frames are sent in FIFO order; if the buffer fills again the remaining
     * frames stay queued and the caller re-arms this client's drain handler —
     * no DATA frame is ever discarded.
     *
     * @param ClientConnection $client The client whose buffer drained.
     *
     * @return bool True when the client's queue fully drained; false if the
     *              send buffer filled again and frames remain queued.
     */
    private function flushClientQueue(ClientConnection $client): bool
    {
        $channelId = $client->channelId;
        while (!empty($this->pendingClientFrames[$channelId])) {
            $encoded = $this->pendingClientFrames[$channelId][0];
            if ($client->sendRaw($encoded) === false) {
                return false;
            }
            array_shift($this->pendingClientFrames[$channelId]);
            $frameLen = strlen($encoded);
            if ($this->relaySessionId !== null) {
                $this->sessionManager->recordBytesIn($this->relaySessionId, $frameLen);
            }
            $this->bytesIn += $frameLen;
        }
        unset($this->pendingClientFrames[$channelId]);
        return true;
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
            // First client of this congestion episode — pause the server and arm
            // ONE episode-scoped safety timer. Arming it here (count 0→1) and
            // cancelling it when the count returns to 0 keeps the timeout a
            // genuine last resort for a truly stuck drain: a stale timer from an
            // already-drained episode cannot fire and false-close a tunnel whose
            // current congestion is within budget.
            $this->serverWs->pauseRecv();
            $this->logger->warning('Relay: client send buffer full, pausing server recv', [
                'server_id' => $this->serverId,
                'tunnel_id' => $this->tunnelId,
                'client_id' => $client->clientId,
            ]);
            $this->armClientBackpressureTimer();
        }

        $this->serverBackpressureCount++;

        $this->armClientDrain($client);
    }

    /**
     * Arm the one-shot safety timer for the current CLIENT backpressure episode
     * and store its id so it can be cancelled on drain
     * ({@see cancelClientBackpressureTimer}). Timer::add throws outside a
     * Workerman event loop (e.g. under PHPUnit) — swallow that so unit tests can
     * exercise the backpressure path; production always has a live loop. The
     * timeout body lives in a named method so it is directly testable.
     *
     * @return void
     */
    private function armClientBackpressureTimer(): void
    {
        try {
            $this->clientBackpressureTimerId = Timer::add(self::BACKPRESSURE_WAIT_SECONDS, function (): void {
                $this->clientBackpressureTimerId = null;
                $this->handleClientBackpressureTimeout();
            }, [], false);
        } catch (Throwable) {
            // Timer unavailable (outside the event loop / tests) — no-op.
            $this->clientBackpressureTimerId = null;
        }
    }

    /**
     * Cancel the CLIENT backpressure safety timer if one is armed (the episode
     * drained within budget). Idempotent; safe when no timer is armed.
     *
     * @return void
     */
    private function cancelClientBackpressureTimer(): void
    {
        if ($this->clientBackpressureTimerId === null) {
            return;
        }
        try {
            Timer::del($this->clientBackpressureTimerId);
        } catch (Throwable) {
            // Timer unavailable (outside the event loop / tests) — no-op.
        }
        $this->clientBackpressureTimerId = null;
    }

    /**
     * (Re-)arm the one-shot drain handler for a congested client. On drain we
     * first re-send any DATA frames that were re-queued while the client was
     * congested; only once its queue fully drains do we decrement the
     * backpressure count and resume server recv (when no client is still
     * congested). If the buffer fills again mid-flush we re-arm and stay paused.
     *
     * @param ClientConnection $client The congested client.
     *
     * @return void
     */
    private function armClientDrain(ClientConnection $client): void
    {
        $client->clientWs->onBufferDrain = function () use ($client): void {
            $client->clientWs->onBufferDrain = null;

            // Re-send queued frames BEFORE resuming upstream. If the buffer fills
            // again, keep this client congested and re-arm — nothing is dropped.
            if (!$this->flushClientQueue($client)) {
                $this->armClientDrain($client);
                return;
            }

            $this->serverBackpressureCount--;

            $this->logger->debug('Relay: client send buffer drained, server backpressure count', [
                'server_id' => $this->serverId,
                'tunnel_id' => $this->tunnelId,
                'client_id' => $client->clientId,
                'remaining_count' => $this->serverBackpressureCount,
            ]);

            if ($this->serverBackpressureCount === 0) {
                // Episode drained within budget — cancel its safety timer so it
                // can't fire later and false-close a healthy tunnel.
                $this->cancelClientBackpressureTimer();
                $this->serverWs->resumeRecv();
                $this->logger->info('Relay: all client buffers drained, resuming server recv', [
                    'server_id' => $this->serverId,
                    'tunnel_id' => $this->tunnelId,
                ]);
            }
        };
    }

    /**
     * Backpressure-timeout handler for the current client congestion episode.
     * Episode-scoped (armed on count 0→1, cancelled on drain), so if it fires
     * at all the episode genuinely never drained: if any client is still
     * congested the tunnel is closed so clients see a hard failure rather than
     * an indefinitely stalled stream.
     *
     * @return void
     */
    private function handleClientBackpressureTimeout(): void
    {
        if ($this->status === self::STATUS_CLOSED) {
            return;
        }
        if ($this->serverBackpressureCount > 0) {
            $this->logger->error('Relay: client backpressure timeout, closing tunnel', [
                'server_id' => $this->serverId,
                'tunnel_id' => $this->tunnelId,
                'backpressure_count' => $this->serverBackpressureCount,
            ]);
            $this->close('backpressure_timeout');
        }
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
            // First frame of this congestion episode — pause all clients and arm
            // ONE episode-scoped safety timer (cancelled when the server drains).
            // Re-arms of the drain handler while still congested do NOT re-arm
            // the timer, so it stays a genuine last resort for a stuck drain and
            // never becomes a stale timer that could false-close a healthy tunnel.
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

            $this->armServerBackpressureTimer();
        }

        // One-shot drain handler on the server. When the server's send buffer
        // drains, first re-send any queued frames (control frames first, then
        // body frames) that were re-queued while congested — never dropped —
        // and only resume all clients once BOTH queues are empty. If a flush
        // re-fills the buffer it re-arms backpressure and we stay paused.
        $serverWs = $this->serverWs;
        $serverWs->onBufferDrain = function () use ($serverWs): void {
            $serverWs->onBufferDrain = null;

            $this->flushHighPriorityQueue();
            if (empty($this->pendingHighPriorityFrames)) {
                $this->flushBodyQueue();
            }

            if (!empty($this->pendingHighPriorityFrames) || !empty($this->pendingBodyFrames)) {
                // Still congested — the flush re-armed backpressure (and this
                // drain handler); keep all clients paused until it clears.
                return;
            }

            $this->clientBackpressureActive = false;

            // Episode drained within budget — cancel its safety timer so it
            // can't fire later and false-close a healthy tunnel.
            $this->cancelServerBackpressureTimer();

            foreach ($this->clientConnections as $client) {
                /** @var ClientConnection $client */
                $client->clientWs->resumeRecv();
            }

            $this->logger->info('Relay: server send buffer drained, resuming all client recv', [
                'server_id' => $this->serverId,
                'tunnel_id' => $this->tunnelId,
            ]);
        };
    }

    /**
     * Arm the one-shot safety timer for the current SERVER backpressure episode
     * and store its id so it can be cancelled on drain
     * ({@see cancelServerBackpressureTimer}). Timer::add throws outside a
     * Workerman event loop (e.g. under PHPUnit) — swallow that so unit tests can
     * exercise the path. The timeout body lives in a named method so it is
     * directly testable.
     *
     * @return void
     */
    private function armServerBackpressureTimer(): void
    {
        try {
            $this->serverBackpressureTimerId = Timer::add(self::BACKPRESSURE_WAIT_SECONDS, function (): void {
                $this->serverBackpressureTimerId = null;
                $this->handleServerBackpressureTimeout();
            }, [], false);
        } catch (Throwable) {
            // Timer unavailable (outside the event loop / tests) — no-op.
            $this->serverBackpressureTimerId = null;
        }
    }

    /**
     * Cancel the SERVER backpressure safety timer if one is armed (the episode
     * drained within budget). Idempotent; safe when no timer is armed.
     *
     * @return void
     */
    private function cancelServerBackpressureTimer(): void
    {
        if ($this->serverBackpressureTimerId === null) {
            return;
        }
        try {
            Timer::del($this->serverBackpressureTimerId);
        } catch (Throwable) {
            // Timer unavailable (outside the event loop / tests) — no-op.
        }
        $this->serverBackpressureTimerId = null;
    }

    /**
     * Backpressure-timeout handler for a congested server connection. If the
     * server is still congested when the safety timer fires the tunnel is
     * closed so clients see a hard failure rather than an indefinite stall.
     *
     * @return void
     */
    private function handleServerBackpressureTimeout(): void
    {
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

        // If this client was congested (frames re-queued for it), it will never
        // fire its drain handler now that it is gone. Release its backpressure
        // slot and drop its queue so the server isn't left paused forever (and
        // the queued frames aren't leaked in the resident worker).
        if ($channelId > 0 && isset($this->pendingClientFrames[$channelId])) {
            unset($this->pendingClientFrames[$channelId]);
            $client->clientWs->onBufferDrain = null;
            if ($this->serverBackpressureCount > 0) {
                $this->serverBackpressureCount--;
                if ($this->serverBackpressureCount === 0) {
                    // Last congested client gone — cancel the episode safety
                    // timer so it can't false-close the tunnel, and resume the
                    // server (it was paused for this now-cleared congestion).
                    $this->cancelClientBackpressureTimer();
                    if ($this->status === self::STATUS_ACTIVE) {
                        $this->serverWs->resumeRecv();
                    }
                }
            }
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
        $this->pendingClientFrames = [];
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

        // Clean up any backpressure state: cancel the episode safety timers and
        // resume any paused receives before closing so connections aren't left
        // in a stuck/paused state and no stale timer fires on a discarded tunnel.
        $this->cancelClientBackpressureTimer();
        $this->cancelServerBackpressureTimer();
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
        // Clear the server-side queues (the client queue is cleared in
        // notifyClientsDisconnected) so a reused Tunnel never resends stale frames.
        $this->pendingBodyFrames = [];
        $this->pendingHighPriorityFrames = [];

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
