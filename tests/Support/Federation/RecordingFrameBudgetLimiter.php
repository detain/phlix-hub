<?php

/**
 * Phlix hub test: Support\Federation.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support\Federation;

use Phlix\Hub\Common\RateLimit\RateLimiterInterface;
use Phlix\Hub\Common\RateLimit\RateLimitState;

/**
 * Always-unlimited {@see RateLimiterInterface} double recording every key it
 * was asked to charge — the pins observe the CHARGE set, not the verdict.
 *
 * Review hub__asphp R3 (F-R3-3): hoisted verbatim (numbers included) out of
 * FederationPeerManagerFrameBudgetTest::recordingLimiter(). The inline
 * anonymous class could only be typed through an intersection `@return`
 * (`RateLimiterInterface&object{hits: list<string>}`) that psalm cannot
 * infer — InvalidReturnType + InvalidReturnStatement (psalm.dev/011/128),
 * red in the CI "Psalm (tests)" job on every hub push since 42693425. A
 * named class carries the property type directly: no intersection needed.
 *
 * The window shape mirrors the real :8805 frame budget (120 hits / 60 s)
 * exactly as the inline double did; S306's shared RecordingRateLimiter was
 * NOT reused deliberately — its 4/900 login-window numbers would silently
 * re-shape this suite's fixture narrative.
 */
final class RecordingFrameBudgetLimiter implements RateLimiterInterface
{
    /** @var list<string> */
    public array $hits = [];

    public function hit(string $key): RateLimitState
    {
        $this->hits[] = $key;

        return new RateLimitState(1, 120, time() + 60, false, 121);
    }

    public function reset(string $key): void
    {
    }

    public function peek(string $key): RateLimitState
    {
        return new RateLimitState(0, 120, 0, false, 120);
    }
}
