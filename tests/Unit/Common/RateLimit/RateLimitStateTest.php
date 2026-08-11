<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\RateLimit;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\Common\RateLimit\RateLimitState;

/**
 * Unit tests for {@see RateLimitState}.
 *
 * @package Phlix\Hub\Tests\Unit\Common\RateLimit
 */
final class RateLimitStateTest extends TestCase
{
    public function testConstructorSetsAllProperties(): void
    {
        $state = new RateLimitState(
            count: 5,
            remaining: 95,
            resetAt: 1700000000,
            limited: false,
            limit: 100,
        );

        self::assertSame(5, $state->count);
        self::assertSame(95, $state->remaining);
        self::assertSame(1700000000, $state->resetAt);
        self::assertFalse($state->limited);
        self::assertSame(100, $state->limit);
    }

    public function testConstructorWithLimitedState(): void
    {
        $state = new RateLimitState(
            count: 100,
            remaining: 0,
            resetAt: 1700000000,
            limited: true,
            limit: 100,
        );

        self::assertSame(100, $state->count);
        self::assertSame(0, $state->remaining);
        self::assertTrue($state->limited);
    }

    public function testRetryAfterReturnsPositiveDelta(): void
    {
        $state = new RateLimitState(
            count: 5,
            remaining: 95,
            resetAt: 1700000060, // 60 seconds in future
            limited: false,
            limit: 100,
        );

        // Use a fixed "now" of 1 second before resetAt
        $retryAfter = $state->retryAfter(1700000000);

        self::assertSame(60, $retryAfter);
    }

    public function testRetryAfterReturnsZeroWhenInPast(): void
    {
        $state = new RateLimitState(
            count: 100,
            remaining: 0,
            resetAt: 1700000000, // already expired
            limited: true,
            limit: 100,
        );

        // Now is after resetAt
        $retryAfter = $state->retryAfter(1700000001);

        self::assertSame(0, $retryAfter);
    }

    public function testRetryAfterReturnsZeroWhenExactlyNow(): void
    {
        $resetAt = 1700000000;

        $state = new RateLimitState(
            count: 50,
            remaining: 50,
            resetAt: $resetAt,
            limited: false,
            limit: 100,
        );

        $retryAfter = $state->retryAfter($resetAt);

        self::assertSame(0, $retryAfter);
    }

    public function testReadonlyPropertiesCannotBeModified(): void
    {
        $state = new RateLimitState(
            count: 10,
            remaining: 90,
            resetAt: 1700000000,
            limited: false,
            limit: 100,
        );

        // Attempting to modify readonly properties should cause a compile-time
        // error in PHP 8.1+, but at runtime we can verify the values
        self::assertSame(10, $state->count);
        self::assertSame(90, $state->remaining);
        self::assertSame(1700000000, $state->resetAt);
        self::assertFalse($state->limited);
        self::assertSame(100, $state->limit);
    }

    public function testZeroResetAtReturnsZeroRetryAfter(): void
    {
        $state = new RateLimitState(
            count: 0,
            remaining: 0,
            resetAt: 0, // no window active
            limited: false,
            limit: 0,
        );

        $retryAfter = $state->retryAfter(1700000000);

        self::assertSame(0, $retryAfter);
    }
}
