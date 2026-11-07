<?php

/**
 * Phlix hub component: Auth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Auth;

use Phlix\Hub\Common\RateLimit\RateLimitState;
use RuntimeException;

use function time;

/**
 * Thrown when a client exceeds the rate limit for authentication attempts.
 *
 * @package Phlix\Hub\Auth
 */
final class RateLimitException extends RuntimeException
{
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
     * Three honest arms, because {@see retryAfterSeconds()} floors at zero:
     * a window that has already rolled says 'now' (never 'in 0 seconds');
     * one second reads as a unit, not as '1 seconds'.
     */
    public static function retryAfterMessage(int $retryAfterSeconds): string
    {
        if ($retryAfterSeconds <= 0) {
            return 'Too many attempts — you may try again now.';
        }

        if ($retryAfterSeconds === 1) {
            return 'Too many attempts — try again in 1 second.';
        }

        return 'Too many attempts — try again in ' . $retryAfterSeconds . ' seconds.';
    }
}
