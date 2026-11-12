<?php

/**
 * Phlix hub component: Auth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Auth;

use Phlix\Hub\Common\RateLimit\RateLimitState;
use RuntimeException;

use function intdiv;
use function time;

/**
 * Thrown when a client exceeds the rate limit for authentication attempts.
 *
 * @package Phlix\Hub\Auth
 */
final class RateLimitException extends RuntimeException
{
    /**
     * Leading words every {@see retryAfterMessage()} sentence shares — the
     * marker a client uses to tell the server's exact wait sentence apart
     * from the bare `error: 'Too Many Requests'` machine label.
     */
    public const string MESSAGE_PREFIX = 'Too many attempts — ';

    /** @var int Unix timestamp when the rate limit resets */
    public int $resetAt;

    /** @var int Number of attempts remaining after this rejection */
    public int $remaining;

    public function __construct(int $resetAt, int $remaining = 0)
    {
        $this->resetAt = $resetAt;
        $this->remaining = $remaining;
        parent::__construct('Too many authentication attempts. Please try again later.');
    }

    /**
     * Seconds a client should wait before retrying, relative to `$now`
     * (defaults to the current time). Never negative — suitable for a
     * `Retry-After` header. Delegates the `max(0, resetAt - now)` math to
     * {@see RateLimitState::retryAfter()} so the formula stays single-sourced.
     *
     * @param int|null $now current unix timestamp (defaults to `time()`)
     *
     * @return int<0, max>
     */
    public function retryAfterSeconds(?int $now = null): int
    {
        $now ??= time();

        return (new RateLimitState(
            count: 0,
            remaining: $this->remaining,
            resetAt: $this->resetAt,
            limited: true,
            limit: 0,
        ))->retryAfter($now);
    }

    /**
     * The human sentence paired with a `Retry-After` value (review
     * hub__copywriter). Single source so the wire header and the prose can
     * never disagree:
     * {@see \Phlix\Hub\Http\Controllers\AuthController::rateLimited()}
     * and the test-suite's re-composition pins both call this formatter with
     * the SAME number the header carries.
     *
     * Honest arms, because {@see retryAfterSeconds()} floors at zero:
     * a window that has already rolled says 'now' (never 'in 0 seconds');
     * one second reads as a unit, not as '1 seconds'. A wait of a minute or
     * more speaks MINUTES, rounded UP (HUB-19): the login lockout is a
     * 900 s window ({@see \Phlix\Hub\Common\RateLimit\RateLimitProfiles::LOGIN}),
     * and 'try again in 897 seconds' is exact but unreadable to a person,
     * while rounding down would promise an unlock that has not happened yet
     * — ceil() keeps the sentence a ceiling, never an understatement.
     *
     * The SPA password form recognises this sentence family by its
     * {@see MESSAGE_PREFIX} and lets it win over its own generic copy
     * (web-ui/src/utils/passwordForm.ts — a cross-language pin reads the
     * prefix from this file).
     */
    public static function retryAfterMessage(int $retryAfterSeconds): string
    {
        if ($retryAfterSeconds <= 0) {
            return self::MESSAGE_PREFIX . 'you may try again now.';
        }

        if ($retryAfterSeconds === 1) {
            return self::MESSAGE_PREFIX . 'try again in 1 second.';
        }

        if ($retryAfterSeconds < 60) {
            return self::MESSAGE_PREFIX . 'try again in ' . $retryAfterSeconds . ' seconds.';
        }

        $minutes = intdiv($retryAfterSeconds + 59, 60);

        return $minutes === 1
            ? self::MESSAGE_PREFIX . 'try again in 1 minute.'
            : self::MESSAGE_PREFIX . 'try again in ' . $minutes . ' minutes.';
    }
}
