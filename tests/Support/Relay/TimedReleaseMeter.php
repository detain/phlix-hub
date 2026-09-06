<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support\Relay;

/**
 * Byte/frame/wall-clock counters for ONE timed throttle-window measurement:
 * the connection double bumps these from its send callback, the harness reads
 * them after the event loop stops.
 *
 * S306: named replacement for the inline anonymous class in
 * TunnelThrottleTimerLoopTest — Psalm's array-shape inference for the harness'
 * `@return array{bytes: int, ...}` went intermittently optional
 * (`bytes?: int`) when the source properties lived on an anonymous class
 * mutated through a captured closure; a named double gives both analysers a
 * stable class symbol (same rationale as {@see ThrottleMeter}).
 */
final class TimedReleaseMeter
{
    public int $bytes = 0;

    public int $frames = 0;

    /** @var list<float> */
    public array $sentAt = [];
}
