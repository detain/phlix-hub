<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Auth;

use Phlix\Hub\Auth\RateLimitException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see RateLimitException::retryAfterSeconds()} — the
 * `max(0, resetAt - now)` helper that feeds the HTTP 429 `Retry-After`
 * header (HB-4.6g) — and for {@see RateLimitException::retryAfterMessage()},
 * the human sentence that must speak the same number truthfully
 * (review hub__copywriter: no "0 seconds", no "1 seconds").
 *
 * @package Phlix\Hub\Tests\Unit\Auth
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

    public function testRetryAfterMessageSpeaksThePluralWindow(): void
    {
        self::assertSame(
            'Too many attempts — try again in 45 seconds.',
            RateLimitException::retryAfterMessage(45),
        );
    }

    public function testRetryAfterMessageUsesTheSingularUnitForOneSecond(): void
    {
        // 1 is a legitimate retryAfter() value (the window resets one second
        // out); the sentence must not read "1 seconds".
        self::assertSame(
            'Too many attempts — try again in 1 second.',
            RateLimitException::retryAfterMessage(1),
        );
    }

    public function testRetryAfterMessageNeverSaysZeroSeconds(): void
    {
        // The floored arm: a window that already rolled reads as 'now',
        // never as "0 seconds".
        self::assertSame(
            'Too many attempts — you may try again now.',
            RateLimitException::retryAfterMessage(0),
        );
        self::assertSame(
            'Too many attempts — you may try again now.',
            RateLimitException::retryAfterMessage(-5),
        );
    }

    public function testRetryAfterMessageStaysInSecondsBelowAMinute(): void
    {
        self::assertSame(
            'Too many attempts — try again in 59 seconds.',
            RateLimitException::retryAfterMessage(59),
        );
    }

    public function testRetryAfterMessageSpeaksMinutesRoundedUp(): void
    {
        // HUB-19: the login lockout is a 900 s window; a person reads
        // minutes, and rounding UP keeps the sentence a ceiling — never an
        // unlock promise that arrives early.
        self::assertSame(
            'Too many attempts — try again in 1 minute.',
            RateLimitException::retryAfterMessage(60),
        );
        self::assertSame(
            'Too many attempts — try again in 2 minutes.',
            RateLimitException::retryAfterMessage(61),
        );
        self::assertSame(
            'Too many attempts — try again in 15 minutes.',
            RateLimitException::retryAfterMessage(897),
        );
        self::assertSame(
            'Too many attempts — try again in 15 minutes.',
            RateLimitException::retryAfterMessage(900),
        );
    }

    public function testEveryArmSharesTheClientRecognisedPrefix(): void
    {
        // The SPA password form lets a sentence starting with this prefix
        // win over its generic copy (web-ui security-settings-page pins the
        // literal against this file).
        self::assertSame('Too many attempts — ', RateLimitException::MESSAGE_PREFIX);
        foreach ([-5, 0, 1, 45, 60, 61, 900, 3600] as $seconds) {
            self::assertStringStartsWith(
                RateLimitException::MESSAGE_PREFIX,
                RateLimitException::retryAfterMessage($seconds),
            );
        }
    }
}
