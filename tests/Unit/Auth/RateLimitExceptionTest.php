<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Auth;

use Phlix\Hub\Auth\RateLimitException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see RateLimitException::retryAfterSeconds()} — the
 * `max(0, resetAt - now)` helper that feeds the HTTP 429 `Retry-After`
 * header (HB-4.6g).
 *
 * @package Phlix\Hub\Tests\Unit\Auth
 *
 * @covers \Phlix\Hub\Auth\RateLimitException
 */
final class RateLimitExceptionTest extends TestCase
{
    public function testRetryAfterSecondsComputesDeltaFromInjectedNow(): void
    {
        $e = new RateLimitException(resetAt: 1_000, remaining: 0);

        self::assertSame(60, $e->retryAfterSeconds(now: 940));
        self::assertSame(1, $e->retryAfterSeconds(now: 999));
    }

    public function testRetryAfterSecondsFloorsAtZeroWhenWindowAlreadyPassed(): void
    {
        $e = new RateLimitException(resetAt: 1_000, remaining: 0);

        self::assertSame(0, $e->retryAfterSeconds(now: 1_000));
        self::assertSame(0, $e->retryAfterSeconds(now: 2_000));
    }

    public function testRetryAfterSecondsDefaultsToCurrentTime(): void
    {
        // resetAt ~30s in the future -> defaulting `now` to time() yields a
        // small positive value (never negative).
        $e = new RateLimitException(resetAt: time() + 30, remaining: 0);

        $retryAfter = $e->retryAfterSeconds();
        self::assertGreaterThan(0, $retryAfter);
        self::assertLessThanOrEqual(30, $retryAfter);
    }
}
