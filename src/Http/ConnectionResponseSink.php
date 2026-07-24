<?php

/**
 * Phlix hub component: Http.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Http;

use Phlix\Hub\Relay\RelayResponseSink;
use Phlix\Hub\Relay\TokenBucket;
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Coroutine\Channel;
use Workerman\Protocols\Http\Chunk;
use Workerman\Protocols\Http\Response as WorkermanResponse;
use Workerman\Timer;

use function in_array;
use function is_numeric;
use function microtime;
use function strlen;
use function strpbrk;
use function strtolower;
use function strtoupper;

/**
 * Streams a relayed HTTP response straight to the browser {@see TcpConnection},
 * one body fragment at a time, without ever buffering the whole body.
 *
 * This is the hub side of the streaming pass-through: as the relay worker
 * forwards each `HTTP_RESPONSE` BODY frame from a paired server, the fragment is
 * written directly to the client socket instead of being reassembled into one
 * multi-MB blob first. That removes the per-request whole-body memory spike the
 * old buffered proxy incurred for every HLS/DASH segment and every direct-play
 * stream.
 *
 * ### Framing
 * The status line + headers are emitted on the first {@see self::head()} call.
 * Two framings are supported, chosen from the server's own headers:
 *   - **Fixed-length** — when the server's response carries `Content-Length`
 *     (every HLS/DASH segment and every direct-play `withFile()` response does),
 *     that length (and any `Content-Range`/`206` for a ranged direct-play seek)
 *     is preserved verbatim and the raw body bytes are streamed after the head.
 *     Preserving `Content-Length`/`Content-Range` is what keeps `<video>`
 *     seeking (Range requests) working through the hub.
 *   - **Chunked** — when the length is unknown, `Transfer-Encoding: chunked` is
 *     used and each fragment is wrapped in an HTTP chunk.
 * Hop-by-hop headers (`connection`, `keep-alive`, `transfer-encoding`) are
 * dropped; `content-length`/`content-range` are deliberately kept (the old
 * buffered path stripped `content-length` because Workerman recomputed it from
 * the buffered body — here there is no buffered body to measure).
 *
 * ### Back-pressure
 * A slow browser must not make the hub buffer the response without bound. Before
 * pulling the next fragment the sink respects the connection's send buffer: when
 * Workerman fires `onBufferFull` (the socket send buffer reached
 * {@see TcpConnection::$maxSendBufferSize}) the sink parks the producing
 * coroutine until `onBufferDrain`, so the upstream relay→hub channel fills and
 * exerts real back-pressure rather than letting the hub queue an unbounded body.
 *
 * ### Per-user bandwidth throttle (S43, updates.md #50)
 * When constructed with a {@see TokenBucket} (built from the owning user's durable
 * `throttle_bps` by {@see \Phlix\Hub\Http\Controllers\ServerProxyController}), each
 * body fragment is paced against that bucket in {@see self::body()} before it is
 * released, mirroring the WS relay path's enforcement (S42). `0` = Unlimited passes
 * a `null` bucket and the throttle is bypassed entirely (fast path unchanged). The
 * pace-wait yields the current coroutine via {@see Timer::sleep()} — NEVER a
 * blocking `sleep()` — so the resident worker's event loop keeps serving every
 * other request; and because a paced (slow) sink stops pulling the bridge's
 * capacity-bounded stream channel, throttling exerts real upstream back-pressure
 * through the SAME bounded mechanism as a slow browser, so the hub never buffers
 * without bound.
 *
 * ### Short fixed-length responses force-close the connection
 * A fixed-length head promises exactly N body bytes over what may be a
 * keep-alive connection. If streaming stops before all N bytes are written —
 * the origin errored mid-transfer, the relay's inactivity/absolute-duration
 * ceiling cut the stream short, or the browser itself stalled and this sink
 * gave up on it — leaving the connection keep-alive would let the NEXT request
 * reused on that socket read the stale/absent remainder of this response's
 * body as its own framing, desyncing the browser's parser. Every path that can
 * end a fixed-length response early therefore force-closes the connection
 * (via {@see self::forceCloseIfShort()} in {@see self::body()} and
 * {@see self::end()}) instead of leaving it open for reuse. Chunked framing is
 * self-terminating (the zero-length chunk marks the end) and is unaffected.
 *
 * ### Mid-stream failure (`abort()`)
 * If the consumer driving this sink ({@see \Phlix\Hub\Relay\RelayProxyBridge::stream()})
 * hits an unrecoverable error after `head()`/`body()` may already have written
 * real bytes, it calls {@see self::abort()} instead of `end()` — this
 * force-closes the connection unconditionally rather than attempting to
 * finish or correct the framing, since a second response can never safely be
 * written onto a connection that already carries partial HTTP framing.
 *
 * @package Phlix\Hub\Http
 * @since 0.11.0
 */
final class ConnectionResponseSink implements RelayResponseSink
{
    /**
     * Response headers dropped before streaming to the browser. Only true
     * hop-by-hop headers are stripped — `content-length` and `content-range` are
     * KEPT (unlike the buffered path) so fixed-length framing + Range/206 seek
     * semantics survive the pass-through.
     *
     * @var list<string>
     */
    private const STRIPPED_HEADERS = ['connection', 'keep-alive', 'transfer-encoding'];

    /**
     * Seconds to wait for the browser's send buffer to drain before treating the
     * connection as dead and abandoning the stream. Generous: a live-but-slow
     * client should never be dropped, only a genuinely stuck one.
     */
    private const BACKPRESSURE_WAIT_SECONDS = 30.0;

    /**
     * Minimum coroutine-sleep slice (seconds) used while pacing a throttled body
     * stream ({@see self::awaitThrottleBudget()}). A tiny positive floor so the
     * pacing loop always makes forward progress even when the computed wait rounds
     * to zero — and so a deterministic test whose injected clock advances ONLY via
     * the sleeper can never spin.
     */
    private const float THROTTLE_MIN_SLEEP_SECONDS = 0.001;

    /** @var bool Whether the head (status line + headers) has been emitted. */
    private bool $headSent = false;

    /** @var bool True when chunked transfer-encoding is in use (unknown length). */
    private bool $chunked = false;

    /** @var bool True once the downstream is known gone; further writes no-op. */
    private bool $closed = false;

    /** @var bool True while Workerman reports the send buffer full. */
    private bool $paused = false;

    /**
     * @var int Declared `Content-Length` for a fixed-length response, or -1 when
     *          unknown/chunked. Used to detect an early/short end so the
     *          connection can be force-closed instead of left keep-alive (see
     *          the class docblock).
     */
    private int $declaredLength = -1;

    /** @var int Raw body bytes actually written so far (fixed-length framing only). */
    private int $bytesWritten = 0;

    /**
     * @var int Total raw body bytes streamed to the browser so far, across BOTH
     *          framings (fixed-length and chunked). Unlike {@see self::$bytesWritten}
     *          (fixed-length only, used for the short-body force-close), this is the
     *          authoritative on-the-wire download total the bandwidth-accounting
     *          layer meters (HB-3.4 G1) — the actual bytes delivered, not a header
     *          estimate. Counts only body fragments that {@see self::body()}
     *          successfully handed to the connection.
     */
    private int $bytesStreamed = 0;

    /** @var Channel Capacity-1 wake channel pushed by onBufferDrain. */
    private readonly Channel $resume;

    /**
     * @var TokenBucket|null Per-user byte-rate throttle bucket (S43, updates.md
     *          #50). Null when the owning user is Unlimited (`throttle_bps === 0`),
     *          in which case {@see self::body()} bypasses pacing entirely. Built
     *          once at stream admission by {@see \Phlix\Hub\Http\Controllers\ServerProxyController}
     *          from {@see \Phlix\Hub\Hub\RelaySessionManager::getUserThrottleBps()}.
     */
    private readonly ?TokenBucket $throttleBucket;

    /**
     * @var callable(float): void Coroutine-yielding sleeper used to pace a
     *          throttled stream. Defaults to {@see Timer::sleep()} (suspends only
     *          the current coroutine, never the event loop); tests inject a fake
     *          that advances their virtual clock for deterministic pacing.
     */
    private $sleeper;

    /**
     * @var callable(): float Monotonic clock (seconds). Defaults to
     *          {@see microtime()}; tests inject a virtual clock so the token-bucket
     *          refill/pace behaviour is deterministic without a running event loop.
     */
    private $clock;

    /**
     * @var bool True for a HEAD request. A HEAD response legitimately carries
     *           zero body bytes regardless of its `Content-Length` (RFC 9110
     *           §9.3.2 — the server MUST NOT send content), so the short-body
     *           force-close safeguard ({@see self::forceCloseIfShort()}) must
     *           never apply to it.
     */
    private readonly bool $isHead;

    /**
     * @param TcpConnection         $connection    The live browser connection to stream to.
     * @param string                $method        The inbound request method (used only to
     *                                             exempt HEAD from the short-body safeguard).
     * @param TokenBucket|null      $throttleBucket Per-user throttle bucket, or null for
     *                                             Unlimited (S43, updates.md #50) — null
     *                                             bypasses pacing entirely.
     * @param (callable(float): void)|null $sleeper Coroutine-yielding sleeper; null uses
     *                                             {@see Timer::sleep()}. Overridable for tests.
     * @param (callable(): float)|null     $clock   Monotonic clock; null uses {@see microtime()}.
     *                                             Overridable for deterministic pacing tests.
     */
    public function __construct(
        private readonly TcpConnection $connection,
        string $method = 'GET',
        ?TokenBucket $throttleBucket = null,
        ?callable $sleeper = null,
        ?callable $clock = null,
    ) {
        $this->resume = new Channel(1);
        $this->isHead = strtoupper($method) === 'HEAD';
        $this->throttleBucket = $throttleBucket;
        $this->sleeper = $sleeper ?? static function (float $seconds): void {
            Timer::sleep($seconds);
        };
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * {@inheritDoc}
     */
    public function head(int $status, array $headers): void
    {
        if ($this->headSent) {
            return;
        }
        $this->headSent = true;

        $filtered = [];
        $hasContentLength = false;
        foreach ($headers as $name => $value) {
            $lower = strtolower($name);
            if (in_array($lower, self::STRIPPED_HEADERS, true)) {
                continue;
            }
            // Refuse header names/values that could smuggle CRLF into the head.
            if (strpbrk($name, ":\r\n") !== false || strpbrk($value, "\r\n") !== false) {
                continue;
            }
            if ($lower === 'content-length') {
                $hasContentLength = true;
            }
            $filtered[$name] = $value;
        }

        // Install back-pressure hooks for the lifetime of the stream.
        $this->connection->onBufferFull = function (): void {
            $this->paused = true;
        };
        $this->connection->onBufferDrain = function (): void {
            if ($this->paused) {
                $this->paused = false;
                $this->resume->push(true);
            }
        };

        if ($hasContentLength) {
            // Fixed-length framing: preserve Content-Length / Content-Range / 206
            // verbatim and stream raw body bytes after the head.
            $this->chunked = false;
            $rawLength = $filtered['Content-Length'] ?? '';
            $this->declaredLength = is_numeric($rawLength) ? (int) $rawLength : -1;
            $this->connection->send($this->buildFixedLengthHead($status, $filtered), true);
            return;
        }

        // Unknown length: fall back to chunked transfer-encoding.
        $this->chunked = true;
        $filtered['Transfer-Encoding'] = 'chunked';
        $this->connection->send((string) new WorkermanResponse($status, $filtered, ''), true);
    }

    /**
     * {@inheritDoc}
     */
    public function body(string $bytes): bool
    {
        if ($this->closed) {
            return false;
        }
        if (!$this->headSent || $bytes === '') {
            return true;
        }

        // Per-user bandwidth throttle (S43, updates.md #50). Pace this fragment
        // against the connection's token bucket BEFORE releasing it; Unlimited
        // (null bucket) skips entirely so the fast path is byte-for-byte
        // unchanged. The wait yields the coroutine — never a blocking sleep — and
        // back-pressures the upstream relay→hub channel through the same bounded
        // mechanism as a slow browser, so throttling grows no unbounded buffer.
        $this->awaitThrottleBudget();

        $payload = $this->chunked ? (string) new Chunk($bytes) : $bytes;
        if ($this->connection->send($payload, true) === false) {
            $this->closed = true;
            // The write itself failed — the socket is going/gone. Force-close
            // rather than leave a half-written fixed-length body on a
            // keep-alive connection (see the class docblock).
            $this->forceCloseIfShort();
            return false;
        }
        if (!$this->chunked) {
            $this->bytesWritten += strlen($bytes);
        }
        // Authoritative download total for bandwidth accounting (HB-3.4 G1):
        // count the raw body bytes regardless of framing.
        $this->bytesStreamed += strlen($bytes);

        // Debit the throttle bucket only AFTER a successful send (mirrors the WS
        // relay path): a fragment that never reached the wire is never charged.
        // No-op when Unlimited (null bucket). Meters raw body bytes — the rate the
        // user perceives — regardless of chunk framing overhead.
        $this->throttleBucket?->spend((float) strlen($bytes));

        // Respect the socket send buffer: if it filled, park until it drains so a
        // slow client cannot make the hub queue the body without bound.
        if ($this->paused) {
            if ($this->resume->pop(self::BACKPRESSURE_WAIT_SECONDS) === false) {
                // Drain never came — treat the client as gone. Same short-body
                // risk as an outright send failure: force-close if fixed-length.
                $this->closed = true;
                $this->forceCloseIfShort();
                return false;
            }
        }

        return true;
    }

    /**
     * Wait — by coroutine yield, NEVER a real sleep — until the per-user token
     * bucket has positive budget for the next body fragment, then return so the
     * caller can send it and debit the bucket. A no-op when the stream is
     * Unlimited (null bucket): the fast path takes no clock read and no yield.
     *
     * Pacing is analytic and self-correcting: each iteration refills the bucket
     * for the real elapsed time and, while still in debt, sleeps exactly long
     * enough (deficit ÷ rate, plus a tiny floor) for the balance to turn
     * positive; the loop re-checks in case a sleep under-shot. Gating on
     * "any positive budget" (not "≥ this fragment's size") mirrors the WS path's
     * {@see TokenBucket::canSpend()} so an oversized fragment can never deadlock
     * the stream — it drives the balance into debt that later refills pay off.
     *
     * The sleeper suspends only THIS coroutine (via {@see Timer::sleep()}), so
     * the event loop and every other request on the worker keep running while a
     * throttled stream waits (Cardinal rule: no blocking sleep in a resident
     * worker).
     *
     * @return void
     */
    private function awaitThrottleBudget(): void
    {
        $bucket = $this->throttleBucket;
        if ($bucket === null) {
            return;
        }

        while (true) {
            $available = $bucket->tokens(($this->clock)());
            if ($available > 0.0) {
                return;
            }

            $rate = $bucket->ratePerSecond();
            $wait = $rate > 0.0
                ? ((-$available) / $rate) + self::THROTTLE_MIN_SLEEP_SECONDS
                : self::THROTTLE_MIN_SLEEP_SECONDS;
            ($this->sleeper)($wait);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function end(): void
    {
        if ($this->closed) {
            $this->detach();
            return;
        }
        if ($this->headSent && $this->chunked) {
            // Terminating zero-length chunk ("0\r\n\r\n").
            $this->connection->send((string) new Chunk(''), true);
        } else {
            // Fixed-length (or no body ever sent): if the relay ended the
            // stream (e.g. an inactivity/absolute-duration cutoff) before all
            // declared bytes were written, force-close rather than leave this
            // keep-alive connection short — see the class docblock.
            $this->forceCloseIfShort();
        }
        $this->detach();
    }

    /**
     * {@inheritDoc}
     */
    public function abort(): void
    {
        if ($this->closed) {
            $this->detach();
            return;
        }
        $this->closed = true;
        // Best-effort: this is itself the error-recovery path (see the
        // interface docblock — the caller has nowhere left to route a
        // failure), so a throw here must never happen. `TcpConnection::close()`
        // does not normally throw, but the guard keeps that guarantee absolute.
        try {
            $this->connection->close();
        } catch (Throwable) {
            // Nothing left to do — the connection is being torn down either way.
        }
        $this->detach();
    }

    /**
     * Force-close the connection if a fixed-length response ended having
     * written fewer bytes than its declared `Content-Length` — a no-op for
     * chunked framing (self-terminating) or a response that completed in full.
     *
     * @return void
     */
    private function forceCloseIfShort(): void
    {
        $isShortFixedLengthBody = !$this->isHead
            && $this->headSent
            && !$this->chunked
            && $this->declaredLength >= 0
            && $this->bytesWritten < $this->declaredLength;

        if ($isShortFixedLengthBody) {
            $this->connection->close();
        }
    }

    /**
     * Total raw body bytes successfully streamed to the browser (both framings).
     *
     * The authoritative download byte count the bandwidth-accounting layer meters
     * for the user once the stream ends (HB-3.4 G1) — real on-the-wire bytes, not
     * a header/`Content-Length` estimate.
     *
     * @return int
     */
    public function bytesStreamed(): int
    {
        return $this->bytesStreamed;
    }

    /**
     * Remove the back-pressure hooks so a keep-alive connection reused for a
     * later request is not left carrying this stream's callbacks.
     *
     * @return void
     */
    private function detach(): void
    {
        $this->connection->onBufferFull = null;
        $this->connection->onBufferDrain = null;
    }

    /**
     * Build the raw HTTP head for a fixed-length response.
     *
     * Workerman's {@see WorkermanResponse} would force a `Content-Length` derived
     * from its (empty) body, clobbering the server's real length, so the head is
     * assembled directly here. Header names/values were already CRLF-checked in
     * {@see self::head()}.
     *
     * @param int                   $status  HTTP status code.
     * @param array<string, string> $headers Filtered response headers.
     *
     * @return string The full head, terminated by a blank line.
     */
    private function buildFixedLengthHead(int $status, array $headers): string
    {
        $reason = WorkermanResponse::PHRASES[$status] ?? '';
        $head = "HTTP/1.1 {$status} {$reason}\r\n";
        $hasConnection = false;
        $hasContentType = false;
        foreach ($headers as $name => $value) {
            $head .= "{$name}: {$value}\r\n";
            $lower = strtolower($name);
            if ($lower === 'connection') {
                $hasConnection = true;
            }
            if ($lower === 'content-type') {
                $hasContentType = true;
            }
        }
        if (!$hasConnection) {
            $head .= "Connection: keep-alive\r\n";
        }
        if (!$hasContentType) {
            $head .= "Content-Type: application/octet-stream\r\n";
        }

        return $head . "\r\n";
    }
}
