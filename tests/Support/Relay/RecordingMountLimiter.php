<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support\Relay;

use Phlix\Hub\Common\RateLimit\RateLimiterInterface;
use Phlix\Hub\Common\RateLimit\RateLimitState;

use function time;

/**
 * Rate limiter double that records every hit key in order — the assertion
 * target for "which bucket did the mount handshake key on" in relay tests.
 */
final class RecordingMountLimiter implements RateLimiterInterface
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
