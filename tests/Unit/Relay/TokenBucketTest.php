<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use Phlix\Hub\Relay\TokenBucket;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see TokenBucket} — the reusable byte-rate limiter behind the
 * per-user relay throttle (S42, updates.md #50).
 *
 * All timing is driven through the injectable clock so the rate/refill behaviour
 * is deterministic without a running event loop.
 *
 * @package Phlix\Hub\Tests\Unit\Relay
 *
 * @covers \Phlix\Hub\Relay\TokenBucket
 */
final class TokenBucketTest extends TestCase
{
    public function testStartsFullAtCapacity(): void
    {
        // rate 1000 B/s, capacity 2000 B, base clock t=1000.0
        $bucket = new TokenBucket(1000.0, 2000.0, 1000.0);

        $this->assertSame(2000.0, $bucket->tokens(1000.0));
        $this->assertSame(1000.0, $bucket->ratePerSecond());
        $this->assertSame(2000.0, $bucket->capacity());
        $this->assertTrue($bucket->canSpend(1000.0));
    }

    public function testRefillsAtConfiguredRateOverSimulatedTime(): void
    {
        // Empty a full bucket then watch it refill at exactly the rate.
        $bucket = new TokenBucket(1000.0, 1000.0, 100.0);
        $bucket->spend(1000.0); // balance now 0 at t=100.0

        // +0.5s → +500 B
        $this->assertSame(500.0, $bucket->tokens(100.5));
        // +1.0s total → +1000 B, but capped at capacity (1000)
        $this->assertSame(1000.0, $bucket->tokens(101.0));
        // Well past a full window → still capped at capacity, never above
        $this->assertSame(1000.0, $bucket->tokens(200.0));
    }

    public function testDebitReducesBalanceAndCanGoIntoDebt(): void
    {
        $bucket = new TokenBucket(1000.0, 1000.0, 0.0);

        $bucket->spend(400.0);
        $this->assertSame(600.0, $bucket->tokens(0.0));

        // A frame larger than the remaining budget drives the balance negative;
        // canSpend() still allowed it (tokens were > 0 before the debit).
        $bucket->spend(1000.0);
        $this->assertSame(-400.0, $bucket->tokens(0.0));
        $this->assertFalse($bucket->canSpend(0.0));

        // The debt is paid off by refills before any further frame is released:
        // +0.3s → -400 + 300 = -100 (still blocked), +0.5s → -400 + 500 = +100.
        $this->assertFalse($bucket->canSpend(0.3));
        $this->assertTrue($bucket->canSpend(0.5));
    }

    public function testCanSpendUsesPositiveBudgetNotFrameSizeSoOversizedFramesNeverDeadlock(): void
    {
        // Capacity is smaller than a single frame would be — canSpend must still
        // permit release whenever ANY positive budget exists, so an oversized
        // frame can never permanently block the stream.
        $bucket = new TokenBucket(1000.0, 500.0, 0.0);

        $this->assertTrue($bucket->canSpend(0.0));       // starts full (500 > 0)
        $bucket->spend(5000.0);                          // huge frame → deep debt
        $this->assertFalse($bucket->canSpend(0.0));      // must wait it off
        // -4500 balance, needs > 4.5s of refill at 1000 B/s to become positive.
        $this->assertFalse($bucket->canSpend(4.5));      // exactly 0, not > 0
        $this->assertTrue($bucket->canSpend(4.6));       // now positive
    }

    public function testRefillIsMonotonicAndIgnoresClockGoingBackwards(): void
    {
        $bucket = new TokenBucket(1000.0, 1000.0, 50.0);
        $bucket->spend(1000.0); // balance 0 at t=50.0

        // Advance to t=51.0 → +1000 (capped at 1000).
        $this->assertSame(1000.0, $bucket->tokens(51.0));

        // A backwards clock must NOT remove tokens (no negative elapsed).
        $this->assertSame(1000.0, $bucket->tokens(50.0));
    }

    public function testLongRunThroughputConvergesToConfiguredRate(): void
    {
        // Drain-then-refill loop: over a long window with a constant backlog the
        // realised throughput must track the configured rate (this is the S42
        // acceptance property — observed throughput ≈ the cap).
        $rate = 1000.0;      // bytes/sec
        $bucket = new TokenBucket($rate, $rate, 0.0);
        $frame = 100.0;      // 100-byte frames
        $sent = 0.0;
        $tick = 0.05;        // 50 ms drain cadence (matches the Tunnel timer)
        $ticks = 200;        // 10 simulated seconds

        for ($i = 1; $i <= $ticks; $i++) {
            $now = $i * $tick;
            // Release as many frames as the budget allows this tick.
            while ($bucket->canSpend($now)) {
                $bucket->spend($frame);
                $sent += $frame;
            }
        }

        // 10 s at 1000 B/s = ~10 000 B, plus the initial full-capacity burst
        // (1000 B). Allow a two-frame slack for tick granularity / float drift.
        $expected = ($rate * ($ticks * $tick)) + $rate;
        $this->assertGreaterThanOrEqual($expected - (2 * $frame), $sent);
        $this->assertLessThanOrEqual($expected + (2 * $frame), $sent);
    }
}
