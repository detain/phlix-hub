<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support\Relay;

/**
 * Mutable counters the load harness shares between the connection double and
 * the assertions. Named (not anonymous) so PHPStan can see the fields through
 * the harness' array-shaped return value.
 *
 * S306 — hoisted out of TunnelThrottleLoadTest: PSR-12 (which the hub applies
 * unchanged to tests/) allows exactly one class per file.
 */
final class ThrottleMeter
{
    /**
     * Read side of the closed flag. The connection double writes `closed` from
     * a mock callback, so PHPStan cannot see the mutation from inside the tick
     * loop; this method keeps the read honest (bool) instead of constant-folded.
     */
    public function isClosed(): bool
    {
        return $this->closed;
    }

    public int $bytes = 0;

    public int $frames = 0;

    public int $queueHighWater = 0;

    public bool $closed = false;

    public int $closedAtTick = 0;

    public int $bytesAtPeerClose = 0;

    public int $bytesAfterPeerClose = 0;
}
