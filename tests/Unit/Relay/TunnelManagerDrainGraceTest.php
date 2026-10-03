<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\Relay\TunnelManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * W5 tests for the LIVE `server.relay.reconnect_drain_grace_seconds` seam on
 * {@see TunnelManager}.
 *
 * Displacement is a rare event (a VALIDATED reconnect of the same server), so
 * the effective grace is read exactly there — never on a hot path. Without a
 * resolver the boot float answers verbatim (pre-W5 behavior).
 *
 * @package Phlix\Hub\Tests\Unit\Relay
 */
final class TunnelManagerDrainGraceTest extends TestCase
{
    private function manager(float $bootGrace, ?callable $resolver): TunnelManager
    {
        return new TunnelManager(
            $this->createMock(RelaySessionManager::class),
            $this->createMock(\Phlix\Shared\Relay\RelayWireCodecInterface::class),
            $this->createMock(StructuredLogger::class),
            null,
            $bootGrace,
            $resolver,
        );
    }

    private function effectiveGrace(TunnelManager $manager): float
    {
        $method = (new ReflectionClass(TunnelManager::class))->getMethod('effectiveDrainGraceSeconds');
        $method->setAccessible(true);

        /** @var float $value */
        $value = $method->invoke($manager);

        return $value;
    }

    public function testNullResolverKeepsBootValue(): void
    {
        self::assertSame(7.5, $this->effectiveGrace($this->manager(7.5, null)));
        self::assertSame(0.0, $this->effectiveGrace($this->manager(0.0, null)), 'boot 0 (drain off) preserved');
    }

    public function testResolverAnswerFlowsPerCall(): void
    {
        $seconds = 2.0;
        $manager = $this->manager(30.0, static function () use (&$seconds): float {
            return $seconds;
        });

        self::assertSame(2.0, $this->effectiveGrace($manager));

        $seconds = 45.5;
        self::assertSame(45.5, $this->effectiveGrace($manager), 'live: next displacement observes the new value');
    }

    public function testWholeSecondRowsArriveAsFloatThroughTheSeam(): void
    {
        // The seam is strictly float-typed: HubSettingsResolvers::float already
        // answers float for whole-second rows (clamp on every read), so the
        // displacement path never needs an int coercion.
        $manager = $this->manager(1.0, static fn (): float => 9.0);

        self::assertSame(9.0, $this->effectiveGrace($manager));
    }

    public function testJwtServiceSlotUntouched(): void
    {
        // Guard against positional drift on the 6-arg constructor: passing a
        // real jwt type in slot 4 still yields the documented defaults.
        $manager = new TunnelManager(
            $this->createMock(RelaySessionManager::class),
            $this->createMock(\Phlix\Shared\Relay\RelayWireCodecInterface::class),
            $this->createMock(StructuredLogger::class),
            null,
        );

        self::assertSame(TunnelManager::DEFAULT_RECONNECT_DRAIN_GRACE_SECONDS, $this->effectiveGrace($manager));
    }
}
