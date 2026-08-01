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
use Phlix\Shared\Relay\RelayHttpRequestCodec;
use Phlix\Shared\Relay\RelayWireCodecInterface;
use SplObjectStorage;
use Throwable;
use Workerman\Connection\ConnectionInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;

use function array_shift;
use function base64_decode;
use function count;
use function explode;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function microtime;
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
     * server's send buffer is full — counted as the AGGREGATE across all
     * per-channel body queues ({@see bodyQueueTotal()}), so the memory footprint
     * stays bounded regardless of how many channels are congested. When the
     * server is congested we pause all client reads, so in practice little lands
     * here; the cap is a safety valve. If it is exceeded we close the tunnel (a
     * hard, visible failure) rather than either dropping a frame (silent
     * corruption) or buffering unbounded (memory leak in the resident worker).
     */
    private const MAX_BODY_QUEUE = 256;

    /**
     * Maximum number of server→client DATA frames that may be re-queued per
     * client while that client's send buffer is full. When a client is congested
     * we pause the server, so at most the single in-flight frame lands here; the
     * cap is a safety valve. Exceeding it closes the tunnel (hard failure) rather
     * than dropping a DATA frame (silent corruption).
     *
     * The SAME per-channel {@see $pendingClientFrames} queue and cap also bound a
     * THROTTLED client's rate-limit backlog (S42); there, overflow closes only
     * that ONE channel ({@see closeThrottledChannel()}), never the whole tunnel,
     * since a slow throttled stream must not tear down the other users
     * multiplexed on the shared server tunnel.
     */
    private const MAX_CLIENT_QUEUE = 256;

    /**
     * Drain-timer interval (seconds) for a throttled client's rate-limit backlog
     * (S42, updates.md #50). When the token bucket runs dry, queued server→client
     * frames are released on this cadence as the bucket refills. 50 ms (20 ticks/s)
     * paces delivery smoothly without busy-spinning the event loop; the timer is
     * armed only while a backlog exists and cancelled the moment it drains, so a
     * stream that keeps up within its cap carries zero idle timer overhead.
     */
    private const float THROTTLE_DRAIN_INTERVAL_SECONDS = 0.05;

    /**
     * High-priority frame queue. These frames (control + small JSON) are always
     * sent before any low-priority (body-chunk) frames to prevent a large
     * transfer from stalling browse/segment requests.
     *
     * @var list<RelayFrame>
     */
    private array $pendingHighPriorityFrames = [];

    /**
     * Per-CHANNEL low-priority BODY frame queues toward the server, keyed by the
     * frame's channel id ({@see RelayFrame::channelId()} — the `seq` field: a
     * client channel id for DATA, a relay request id for HTTP_REQUEST sub-frames).
     *
     * A body/stream frame whose {@see TcpConnection::send()} returned false (send
     * buffer full — Workerman DROPS the package before returning), or that arrives
     * while a backlog already exists, is re-queued into its channel's list instead
     * of being lost. {@see flushBodyQueue()} drains the channels ROUND-ROBIN (one
     * frame per channel per pass) so a large bulk transfer on channel A cannot
     * starve channel B's browse/segment request (the HB-3.3 fairness requirement).
     * Strict intra-channel FIFO is preserved by the per-channel list + array_shift,
     * so the sub-frames of one request (HEAD→BODY→END) never reorder; only
     * DISTINCT channels interleave. Emptied channel keys are unset on drain so a
     * plain `empty($this->pendingBodyFrames)` reliably means "no body backlog".
     *
     * @var array<int, list<RelayFrame>>
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
     * @var int|null Timer id of the reconnect-drain grace timer (H-R6). Armed by
     *          {@see beginDrain()} when a VALIDATED reconnect displaces this
     *          (incumbent) tunnel: the tunnel is moved to CLOSING and kept alive
     *          for a bounded grace period so its in-flight requests can drain
     *          before the hard close, instead of instantly killing playback.
     *          One-shot ({@see Timer::add()} with `[], false`); cleared when it
     *          fires or when the tunnel closes for any other reason.
     */
    private ?int $drainTimerId = null;

    /**
     * @var string Close reason to use when the reconnect-drain grace period
     *          expires (see {@see beginDrain()}).
     */
    private string $drainReason = 'server_replaced';

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
        } catch (FrameBufferOverflowException $e) {
            // A dribbling / oversized-length server grew the decode buffer past
            // the 128 KB hard cap without completing a frame (H-R7). Left
            // unhandled this fatals out of the Workerman message callback and
            // takes down the relay worker. Close the tunnel cleanly instead so
            // clients are notified, the DB session is closed, and in-flight proxy
            // requests are failed.
            $this->logger->warning('Relay: frame buffer overflow from server, closing tunnel', [
                'server_id' => $this->serverId,
                'tunnel_id' => $this->tunnelId,
                'buffer_size' => $e->bufferSize,
                'max_buffer_size' => $e->maxBufferSize,
            ]);
            $this->close('frame_buffer_overflow');
            return;
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

        // Cancel any pending reconnect-drain grace timer so it cannot fire on an
        // already-torn-down tunnel.
        if ($this->drainTimerId !== null) {
            try {
                Timer::del($this->drainTimerId);
            } catch (Throwable) {
                // Timer unavailable (outside the event loop / tests) — no-op.
            }
            $this->drainTimerId = null;
        }

        // Close all client connections with TYPE_DISCONNECTED
        $this->notifyClientsDisconnected('server_closed');

        // Fail any in-flight proxy requests for this server — but ONLY if this
        // tunnel was actually the ACTIVE owner (it holds a relay session). A
        // never-activated tunnel (e.g. one rejected during the HELLO handshake)
        // never owned this server's requests; failServer() is keyed by
        // server_id, so calling it from a rejected tunnel would wrongly 503 the
        // LEGITIMATE incumbent's in-flight requests (HB-2.2 / H-H1 residual DoS).
        if ($this->relaySessionId !== null) {
            $this->proxyManager?->failServer($this->serverId);
        }

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
     * Flush the per-channel low-priority BODY frame queues toward the server,
     * ROUND-ROBIN — at most one frame per channel per pass — so no single channel
     * (e.g. a large media transfer) monopolises the tunnel and starves another
     * channel's browse/segment request (the HB-3.3 fairness requirement). Within
     * a channel the frames stay in strict FIFO order (array_shift of that
     * channel's list), so a request's HEAD/BODY/END sub-frames never reorder;
     * only distinct channels interleave. If the server's send buffer fills again
     * mid-flush the offending frame is left at the head of its channel queue and
     * backpressure is (re-)applied so the next drain retries it — no BODY frame
     * is ever discarded.
     *
     * @return void
     */
    private function flushBodyQueue(): void
    {
        while (!empty($this->pendingBodyFrames)) {
            $progressed = false;

            // One frame per channel per pass = fair interleave across channels.
            foreach (array_keys($this->pendingBodyFrames) as $channel) {
                /** @psalm-suppress RiskyTruthyFalsyComparison */
                if (empty($this->pendingBodyFrames[$channel])) {
                    unset($this->pendingBodyFrames[$channel]);
                    continue;
                }

                $frame = $this->pendingBodyFrames[$channel][0];
                $encoded = $this->codec->encode($frame->type, $frame->seq, $frame->payload);
                if ($this->serverWs->send($encoded) === false) {
                    // Still full — leave every frame queued and re-arm backpressure.
                    $this->handleServerSendBackpressure();
                    return;
                }

                array_shift($this->pendingBodyFrames[$channel]);
                /** @psalm-suppress DocblockTypeContradiction */
                if (empty($this->pendingBodyFrames[$channel])) {
                    unset($this->pendingBodyFrames[$channel]);
                }

                $this->bytesOut += strlen($encoded);
                if ($this->relaySessionId !== null) {
                    $this->sessionManager->recordBytesOut($this->relaySessionId, strlen($encoded));
                }
                $progressed = true;
            }

            // Defensive: if a pass sent nothing (all channels were empty and
            // unset) stop rather than spin. Normal completion is the while guard.
            if (!$progressed) {
                break;
            }
        }
    }

    /**
     * Re-queue a BODY/stream frame that could not be sent (server send buffer
     * full) or that arrived while a backlog already exists, into ITS CHANNEL's
     * queue ({@see $pendingBodyFrames}, keyed by {@see RelayFrame::channelId()})
     * so intra-channel FIFO is preserved and channels are drained fairly. Bounded
     * by {@see MAX_BODY_QUEUE} across ALL channels combined ({@see bodyQueueTotal});
     * exceeding the bound closes the tunnel (hard, visible failure) rather than
     * dropping the frame (silent corruption) or buffering unbounded.
     *
     * @param RelayFrame $frame The BODY/stream frame to re-queue.
     *
     * @return void
     */
    private function enqueueBodyFrame(RelayFrame $frame): void
    {
        if ($this->bodyQueueTotal() >= self::MAX_BODY_QUEUE) {
            $this->logger->error('Relay: body queue full under backpressure, closing tunnel', [
                'server_id' => $this->serverId,
                'tunnel_id' => $this->tunnelId,
                'queue_size' => $this->bodyQueueTotal(),
            ]);
            $this->close('backpressure_overflow');
            return;
        }
        $this->pendingBodyFrames[$frame->channelId()][] = $frame;
    }

    /**
     * Total number of BODY/stream frames queued across ALL per-channel body
     * queues — the aggregate bound checked against {@see MAX_BODY_QUEUE} so the
     * memory footprint stays bounded regardless of channel count.
     *
     * @return int
     */
    private function bodyQueueTotal(): int
    {
        $total = 0;
        foreach ($this->pendingBodyFrames as $frames) {
            $total += count($frames);
        }
        return $total;
    }

    /**
     * Classify a frame for priority queue placement — by frame TYPE only.
     *
     * CONTROL priority (true) — genuine out-of-band control frames, enqueued and
     * always flushed before low-priority stream frames (the HB-3.3 fairness
     * requirement so a bulk transfer can't starve control traffic):
     *   - {@see RelayFrameType::HEARTBEAT} keep-alive probes
     *   - {@see RelayFrameType::HTTP_CANCEL} request cancellations
     *   - {@see RelayFrameType::CLIENT_CONNECT} / {@see RelayFrameType::CLIENT_DISCONNECT}
     *     channel lifecycle notifications
     * These are generated independently of any request stream, so prioritizing
     * them is safe — they carry no per-request ordering constraint.
     *
     * STREAM priority (false) — request/response stream frames whose sub-frames
     * MUST stay in producer order within a request; they share one FIFO body
     * queue so ordering is preserved (END can never overtake a queued BODY):
     *   - {@see RelayFrameType::HTTP_REQUEST} single-frame envelopes AND the
     *     chunked HEAD / BODY / END sub-frames (tag-byte {@see RelayHttpRequestCodec}
     *     payloads — NEVER JSON-decoded here)
     *   - {@see RelayFrameType::DATA} raw client-channel bulk stream bytes
     *
     * Classification is a pure type switch: the frame payload is opaque and is
     * never parsed (a tag-byte HEAD/BODY/END payload is not valid JSON, so the
     * old json_decode faulted on every chunked bodied relay request).
     *
     * @param RelayFrame $frame
     *
     * @return bool true = high-priority (control), false = low-priority (stream/body)
     */
    private function isHighPriorityFrame(RelayFrame $frame): bool
    {
        return match ($frame->type) {
            RelayFrameType::HEARTBEAT,
            RelayFrameType::HTTP_CANCEL,
            RelayFrameType::CLIENT_CONNECT,
            RelayFrameType::CLIENT_DISCONNECT => true,
            default => false,
        };
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
        // flush above, or body frames on ANY channel from a prior drop), this
        // body frame must NOT be sent ahead of it — that would race a full buffer
        // and bypass the fair round-robin drain. Re-queue it into its channel's
        // queue; the round-robin flush on drain delivers it with bounded delay
        // (one frame per channel per pass) while preserving intra-channel FIFO.
        // A non-empty queue implies backpressure is already armed.
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

        // Per-user bandwidth throttle (S42, updates.md #50). A client mounted with
        // a finite cap (throttle_bps > 0) is paced by its own per-connection token
        // bucket; 0 = Unlimited bypasses this entirely and takes the unchanged
        // fast path below (no bucket, no queue, no timer overhead). The throttle
        // is strictly per-CHANNEL: it NEVER pauseRecv()s the shared server tunnel
        // (that would throttle every user multiplexed over it).
        if ($client->isThrottled()) {
            $this->sendToClientThrottled($client, $encoded);
            return;
        }

        $frameLen = strlen($encoded);

        // If this client already has queued frames it is congested — do not send
        // ahead of the backlog (that would reorder its stream). Re-queue to
        // preserve FIFO order; the backlog is flushed on this client's drain.
        /** @psalm-suppress RiskyTruthyFalsyComparison */
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
        $this->recordClientBytesIn($frameLen);
    }

    /**
     * Rate-limited server→client delivery for a THROTTLED client (S42).
     *
     * The frame is appended to the shared per-channel {@see $pendingClientFrames}
     * re-queue (bounded by {@see MAX_CLIENT_QUEUE}; overflow closes only THIS
     * channel — see {@see enqueueThrottledFrame()}) and then {@see drainThrottled()}
     * releases whatever the token budget currently allows. Routing every frame
     * through the queue — even when the bucket has budget to send immediately —
     * keeps strict FIFO and collapses the immediate-send and timer-drain paths
     * into ONE code path, so tokens can never be double-charged or frames
     * reordered.
     *
     * @param ClientConnection $client  The throttled client.
     * @param string           $encoded The already-encoded wire frame.
     *
     * @return void
     */
    private function sendToClientThrottled(ClientConnection $client, string $encoded): void
    {
        if (!$this->enqueueThrottledFrame($client, $encoded)) {
            // Queue overflowed — the channel was closed. Nothing more to do.
            return;
        }

        $this->drainThrottled($client);
    }

    /**
     * Append a throttled frame to its channel's re-queue, bounded by
     * {@see MAX_CLIENT_QUEUE}. On overflow the single congested channel is closed
     * ({@see closeThrottledChannel()}) — a visible per-channel failure — rather
     * than dropping a DATA frame (silent corruption) or buffering without bound
     * (resident-worker memory leak). Crucially it does NOT close the tunnel or
     * pause the shared server, so the other users on it are unaffected.
     *
     * @param ClientConnection $client  The throttled client.
     * @param string           $encoded The already-encoded wire frame.
     *
     * @return bool True when queued; false when the queue overflowed and the
     *              channel was closed.
     */
    private function enqueueThrottledFrame(ClientConnection $client, string $encoded): bool
    {
        $channelId = $client->channelId;
        if (count($this->pendingClientFrames[$channelId] ?? []) >= self::MAX_CLIENT_QUEUE) {
            $this->logger->warning('Relay: throttled client queue full, closing channel', [
                'server_id' => $this->serverId,
                'tunnel_id' => $this->tunnelId,
                'client_id' => $client->clientId,
                'channel_id' => $channelId,
            ]);
            $this->closeThrottledChannel($client);
            return false;
        }

        $this->pendingClientFrames[$channelId][] = $encoded;
        return true;
    }

    /**
     * Release as many queued frames for a throttled client as its token bucket
     * and the client's send buffer currently allow, in strict FIFO order.
     *
     * Stops (leaving the remainder queued) when the bucket runs dry — the drain
     * timer will resume as tokens refill — or when the client's send buffer fills
     * even at the throttled rate (retried on the next tick; the bounded queue is
     * the safety valve, we never pause the shared server). Tokens are debited only
     * after a successful send, so a frame that could not go out is never charged.
     * The drain timer is (re-)armed while a backlog remains and cancelled the
     * moment the queue empties.
     *
     * Exposed with an injectable clock so the rate behaviour is deterministically
     * testable without a running event loop (the timer callback passes null =
     * real time).
     *
     * @param ClientConnection $client The throttled client to drain.
     * @param float|null       $now    Injectable clock (seconds); null = real time.
     *
     * @return void
     */
    private function drainThrottled(ClientConnection $client, ?float $now = null): void
    {
        $channelId = $client->channelId;
        $bucket = $client->throttleBucket;
        $now ??= microtime(true);

        while (($this->pendingClientFrames[$channelId] ?? []) !== []) {
            if ($bucket !== null && !$bucket->canSpend($now)) {
                // No budget this instant — the drain timer resumes on refill.
                break;
            }

            $encoded = $this->pendingClientFrames[$channelId][0];
            if ($client->sendRaw($encoded) === false) {
                // Client socket full even at the throttled rate — leave the frame
                // queued and retry next tick. Do NOT pause the shared server.
                break;
            }

            array_shift($this->pendingClientFrames[$channelId]);
            $frameLen = strlen($encoded);
            $bucket?->spend((float) $frameLen);
            $this->recordClientBytesIn($frameLen);
        }

        if (($this->pendingClientFrames[$channelId] ?? []) === []) {
            unset($this->pendingClientFrames[$channelId]);
            $this->cancelThrottleDrain($client);
        } else {
            $this->armThrottleDrain($client);
        }
    }

    /**
     * Arm the repeating throttle drain timer for a client if one is not already
     * running. {@see Timer::add} throws outside a Workerman event loop (e.g. under
     * PHPUnit) — swallow that so unit tests can exercise the enqueue path; the
     * drain itself is driven directly in tests. Idempotent.
     *
     * @param ClientConnection $client The throttled client.
     *
     * @return void
     */
    private function armThrottleDrain(ClientConnection $client): void
    {
        if ($client->throttleDrainTimerId !== null) {
            return;
        }

        try {
            $client->throttleDrainTimerId = Timer::add(
                self::THROTTLE_DRAIN_INTERVAL_SECONDS,
                function () use ($client): void {
                    $this->drainThrottled($client);
                },
            );
        } catch (Throwable) {
            // Timer unavailable (outside the event loop / tests) — no-op.
            $client->throttleDrainTimerId = null;
        }
    }

    /**
     * Cancel a client's throttle drain timer if one is armed. Idempotent; safe
     * when none is armed or when called outside the event loop.
     *
     * @param ClientConnection $client The throttled client.
     *
     * @return void
     */
    private function cancelThrottleDrain(ClientConnection $client): void
    {
        if ($client->throttleDrainTimerId === null) {
            return;
        }

        try {
            Timer::del($client->throttleDrainTimerId);
        } catch (Throwable) {
            // Timer unavailable (outside the event loop / tests) — no-op.
        }

        $client->throttleDrainTimerId = null;
    }

    /**
     * Close a single throttled client's channel after its rate-limit backlog
     * overflowed {@see MAX_CLIENT_QUEUE}. Cancels the drain timer, drops the
     * backlog, and closes ONLY this client's connection — the shared server
     * tunnel and every other channel multiplexed on it are untouched (no tunnel
     * close, no server pauseRecv). The owning worker's onClose →
     * {@see removeClient()} completes routing teardown (CLIENT_DISCONNECT etc.).
     *
     * @param ClientConnection $client The client whose channel to close.
     *
     * @return void
     */
    private function closeThrottledChannel(ClientConnection $client): void
    {
        $this->cancelThrottleDrain($client);
        $channelId = $client->channelId;
        if ($channelId > 0) {
            unset($this->pendingClientFrames[$channelId]);
        }
        $client->close();
    }

    /**
     * Record a delivered server→client frame's bytes against the relay session
     * (DB accounting) and the tunnel's running total.
     *
     * @param int $frameLen Byte length of the delivered frame.
     *
     * @return void
     */
    private function recordClientBytesIn(int $frameLen): void
    {
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
        /** @psalm-suppress RiskyTruthyFalsyComparison */
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
        // An enqueue* call above may have overflowed and close()d the tunnel
        // (cancelling its timers + tearing down connections). Do not re-arm
        // backpressure — a stale pauseRecv/timer on a discarded tunnel is
        // exactly what fix-2's episode-scoped timers exist to prevent.
        if ($this->status !== self::STATUS_ACTIVE) {
            return;
        }

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
        // An enqueue* call above may have overflowed and close()d the tunnel
        // (cancelling its timers + tearing down connections). Do not re-arm
        // backpressure — a stale pauseRecv/timer on a discarded tunnel is
        // exactly what fix-2's episode-scoped timers exist to prevent.
        if ($this->status !== self::STATUS_ACTIVE) {
            return;
        }

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

        // Cancel any throttle drain timer for this client (S42) so a stale timer
        // cannot fire on a departed connection. Idempotent + null-safe for
        // Unlimited clients that never armed one.
        $this->cancelThrottleDrain($client);

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
            // A THROTTLED client's backlog is a rate-limit queue (S42), NOT a
            // send-buffer backpressure queue: throttled clients never pause the
            // shared server, so they hold no serverBackpressureCount slot.
            // Releasing one here would corrupt the count and could falsely resume
            // or false-close the tunnel — so only unthrottled clients decrement.
            if (!$client->isThrottled() && $this->serverBackpressureCount > 0) {
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

        // Drop any low-priority body frames still queued toward the server on
        // THIS client's channel (its client→server DATA chunks). The client is
        // gone, so they can never complete; leaving them would leak in the
        // resident worker and hold a fair-scheduling slot. HTTP_REQUEST buckets
        // keyed by relay request id are unaffected — a proxied request is not
        // tied to a persistent client channel — so this only reclaims the
        // departing channel's own bulk stream.
        if ($channelId > 0 && isset($this->pendingBodyFrames[$channelId])) {
            unset($this->pendingBodyFrames[$channelId]);
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
            // Cancel any per-client throttle drain timer (S42) before teardown so
            // no stale timer fires on a discarded tunnel/connection.
            $this->cancelThrottleDrain($client);
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
     * @param string $reason      Human-readable close reason.
     * @param bool   $failInFlight When true (default) any in-flight proxy
     *                            requests for this server are 503'd. Set false
     *                            when displacing an incumbent after a validated
     *                            reconnect drain (H-R6): {@see failServer()} is
     *                            keyed by server_id, so failing here would also
     *                            kill the NEW tunnel's freshly accepted requests.
     *
     * @return void
     */
    public function close(string $reason = 'normal', bool $failInFlight = true): void
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

        // Cancel any pending reconnect-drain grace timer (it may be the very
        // caller, in which case drainTimerId is already null — Timer::del is a
        // no-op on null-guarded ids).
        if ($this->drainTimerId !== null) {
            try {
                Timer::del($this->drainTimerId);
            } catch (Throwable) {
                // Timer unavailable (outside the event loop / tests) — no-op.
            }
            $this->drainTimerId = null;
        }

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

        // Fail any in-flight proxy requests for this server — but ONLY if this
        // tunnel was the ACTIVE owner (holds a relay session) AND the caller
        // wants in-flight requests failed. A never-activated tunnel rejected
        // during the HELLO handshake never owned this server's requests; because
        // failServer() is keyed by server_id, failing here would 503 the
        // LEGITIMATE incumbent's in-flight requests (the HB-2.2 residual DoS).
        // $failInFlight is set false when draining an incumbent after a validated
        // reconnect so the NEW tunnel's requests survive the displacement.
        if ($failInFlight && $this->relaySessionId !== null) {
            $this->proxyManager?->failServer($this->serverId);
        }

        // Close server connection
        $this->serverWs->close();

        // Close session in DB
        if ($this->relaySessionId !== null) {
            $this->sessionManager->closeSession($this->relaySessionId, $reason);
        }

        $this->status = self::STATUS_CLOSED;
    }

    /**
     * Begin a reconnect-drain (H-R6) before displacing this incumbent tunnel.
     *
     * Called by {@see TunnelManager::finalizeServerConnection()} when a server
     * reconnects and its NEW tunnel has passed HELLO-JWT validation. Rather than
     * hard-closing this (incumbent) tunnel immediately — which tears down every
     * client and 503s every in-flight request, killing active playback on every
     * legitimate deploy/network blip — the tunnel is moved to CLOSING and kept
     * alive for a bounded grace period. New clients and proxy requests already
     * route to the promoted tunnel; this one simply finishes delivering the
     * responses already in flight on its (still-open) server connection, then
     * closes when the grace timer fires.
     *
     * The grace close uses `failInFlight = false` so the server-scoped
     * {@see failServer()} does not also kill the newly promoted tunnel's
     * requests. Any of this tunnel's own stragglers that have not completed by
     * grace end fall back to their per-request relay timeout.
     *
     * @param float  $graceSeconds Grace window in seconds. `<= 0` displaces
     *                            immediately (drain disabled).
     * @param string $reason      Close reason recorded when the grace expires.
     *
     * @return void
     */
    public function beginDrain(float $graceSeconds, string $reason = 'server_replaced'): void
    {
        if ($this->status === self::STATUS_CLOSED || $this->status === self::STATUS_CLOSING) {
            return;
        }

        if ($graceSeconds <= 0.0) {
            // Drain disabled — displace immediately (legacy hard-kill behaviour).
            $this->close($reason);
            return;
        }

        $this->status = self::STATUS_CLOSING;
        $this->drainReason = $reason;

        $this->logger->info('Relay: incumbent tunnel draining before displacement', [
            'server_id' => $this->serverId,
            'tunnel_id' => $this->tunnelId,
            'relay_session_id' => $this->relaySessionId,
            'grace_seconds' => $graceSeconds,
            'reason' => $reason,
        ]);

        // One-shot grace timer (§0.4: `[], false` — must NOT repeat). Timer::add
        // throws outside a Workerman event loop (e.g. under PHPUnit); swallow that
        // so unit tests can exercise the drain path (they invoke
        // handleDrainTimeout() directly). Production always has a live loop.
        try {
            $this->drainTimerId = Timer::add($graceSeconds, function (): void {
                $this->drainTimerId = null;
                $this->handleDrainTimeout();
            }, [], false);
        } catch (Throwable) {
            $this->drainTimerId = null;
        }
    }

    /**
     * Fire when the reconnect-drain grace period expires: hard-close the
     * incumbent tunnel WITHOUT failing the server's in-flight requests (those
     * now belong to the promoted tunnel — see {@see beginDrain()}).
     *
     * Exposed as a distinct method (not an inline closure) so tests can simulate
     * the timer firing deterministically without a running event loop.
     *
     * @return void
     */
    private function handleDrainTimeout(): void
    {
        if ($this->status === self::STATUS_CLOSED) {
            return;
        }

        $this->close($this->drainReason, false);
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
