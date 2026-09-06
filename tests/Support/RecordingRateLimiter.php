<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support;

use Phlix\Hub\Common\RateLimit\RateLimiterInterface;
use Phlix\Hub\Common\RateLimit\RateLimitState;

/**
 * A recording {@see RateLimiterInterface} double: captures every key passed to
 * hit() so tests can assert which IP the login limiter buckets on. Always
 * reports not-limited so login proceeds to the credential check.
 *
 * S306 — hoisted out of AuthControllerTest: PSR-12 allows one class per file.
 */
final class RecordingRateLimiter implements RateLimiterInterface
{
    /** @var list<string> */
    public array $hits = [];

    public function hit(string $key): RateLimitState
    {
        $this->hits[] = $key;

        return new RateLimitState(1, 4, time() + 900, false, 5);
    }

    public function reset(string $key): void
    {
    }

    public function peek(string $key): RateLimitState
    {
        return new RateLimitState(0, 5, 0, false, 5);
    }
}
