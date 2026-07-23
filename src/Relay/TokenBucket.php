<?php

/**
 * Phlix hub component: Relay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Relay;

use function microtime;
use function min;

/**
 * A monotonic-time token bucket for byte-rate limiting a single relay stream.
 *
 * Tokens are BYTES: the bucket refills at {@see $ratePerSecond} bytes/sec up to
 * {@see $capacity} bytes of burst, and each sent frame debits its byte length.
 * It is a plain in-memory value object with NO I/O and NO timers — the caller
 * owns the {@see \Workerman\Timer} that periodically drives {@see canSpend()} /
 * {@see spend()} as budget refills (see {@see Tunnel::drainThrottled()}). This
 * keeps the enforcement logic reusable across the WS relay path (S42) and the
 * HTTP-over-relay proxy path (S43) without either owning bucket internals.
 *
 * The clock is injectable on every method (defaulting to {@see microtime()}) so
 * the rate/refill behaviour is deterministically unit-testable without a running
 * event loop.
 *
 * Cardinal-rule note: this holds only per-STREAM state and is owned by a single
 * connection object (never a `static`/`global`), so it introduces no
 * resident-worker memory leak.
 *
 * @package Phlix\Hub\Relay
 */
final class TokenBucket
{
    /**
     * @var float Current token balance in bytes. MAY be negative: a frame larger
     *            than the whole bucket is still released (see {@see canSpend()})
     *            and drives the balance into debt that later refills must pay off
     *            before any further frame is released.
     */
    private float $tokens;

    /**
     * @var float Monotonic timestamp (seconds) of the last refill.
     */
    private float $updatedAt;

    /**
     * @param float      $ratePerSecond Sustained fill rate in BYTES per second.
     * @param float      $capacity      Maximum burst in BYTES (bucket depth). The
     *                                  bucket starts FULL so a freshly-mounted
     *                                  stream may burst up to this many bytes
     *                                  immediately, then settle to the rate.
     * @param float|null $now           Injectable start time (seconds). Null uses
     *                                  {@see microtime()}; tests pass an explicit
     *                                  base for determinism.
     */
    public function __construct(
        private readonly float $ratePerSecond,
        private readonly float $capacity,
        ?float $now = null,
    ) {
        $this->tokens = $capacity;
        $this->updatedAt = $now ?? microtime(true);
    }

    /**
     * Sustained fill rate in bytes/sec (diagnostics).
     *
     * @return float
     */
    public function ratePerSecond(): float
    {
        return $this->ratePerSecond;
    }

    /**
     * Burst capacity in bytes (diagnostics).
     *
     * @return float
     */
    public function capacity(): float
    {
        return $this->capacity;
    }

    /**
     * Current token balance after refilling for elapsed time (diagnostics/tests).
     *
     * @param float|null $now Injectable clock; null uses {@see microtime()}.
     *
     * @return float Token balance in bytes (may be negative).
     */
    public function tokens(?float $now = null): float
    {
        $this->refill($now ?? microtime(true));

        return $this->tokens;
    }

    /**
     * Head-of-line release decision. Refills for elapsed time, then reports
     * whether ANY positive budget remains.
     *
     * Deliberately tests `tokens > 0` (not `tokens >= cost`): a frame larger than
     * the whole bucket is still eventually released — it drives the balance
     * negative and subsequent frames wait the debt off — so an oversized frame
     * can never deadlock the stream.
     *
     * @param float|null $now Injectable clock; null uses {@see microtime()}.
     *
     * @return bool True when a frame may be released now.
     */
    public function canSpend(?float $now = null): bool
    {
        $this->refill($now ?? microtime(true));

        return $this->tokens > 0.0;
    }

    /**
     * Debit `cost` bytes for a frame that was ACTUALLY sent. May drive the
     * balance negative (a debt later refills pay off). Call only after a
     * successful send so tokens are never spent on a frame that did not go out.
     *
     * @param float $cost Byte length of the sent frame.
     *
     * @return void
     */
    public function spend(float $cost): void
    {
        $this->tokens -= $cost;
    }

    /**
     * Add tokens for the time elapsed since the last refill, capped at capacity.
     * No-op when the clock has not advanced (or ran backwards) so refills are
     * always monotonic.
     *
     * @param float $now Current monotonic timestamp in seconds.
     *
     * @return void
     */
    private function refill(float $now): void
    {
        if ($now <= $this->updatedAt) {
            return;
        }

        $elapsed = $now - $this->updatedAt;
        $this->tokens = min($this->capacity, $this->tokens + ($elapsed * $this->ratePerSecond));
        $this->updatedAt = $now;
    }
}
