<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit;

use PDOException;
use Phlix\Hub\Common\Container\Providers\HubServicesProvider;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Health\MaintenanceHeartbeat;
use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\Relay\IdleReaper;
use Phlix\Hub\Relay\TunnelInterface;
use Phlix\Hub\Relay\TunnelManagerInterface;
use Phlix\Hub\ServerReaper;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use Workerman\MySQL\Connection;

/**
 * S312 — the maintenance sweeps must not be able to throw into the event loop.
 *
 * ## What these tests are actually pinning
 *
 * Workerman installs exactly ONE error handler on a worker's event loop
 * (`Worker::run()`, `vendor/workerman/workerman/src/Worker.php:1590`):
 *
 * ```php
 * static::$globalEvent->setErrorHandler(fn ($e) => static::stopAll(250, $e));
 * ```
 *
 * and the Swoole driver funnels every callback through it
 * (`Events/Swoole.php::safeCall()`). So an exception escaping a 60-second
 * reaper tick does not lose the tick — it ENDS THE WORKER. In a child,
 * `stopAll()` sets `STATUS_SHUTDOWN`, stops the loop, and `Worker::run()` falls
 * through to `exit(0)`; the master only logs a NON-zero status, so it logs
 * nothing, and `RestartCount` counts container restarts, not re-forks.
 *
 * Measured on master @ 65763eb, `docker run --network none`: three
 * `PDOException: SQLSTATE[HY000] [2002] Connection refused` traces every 60s,
 * the maintenance worker's `etime` 0:39 against the container master's 4:41,
 * `docker inspect` `healthy`, `RestartCount=0`. The three throwers were
 * `RelaySessionManager.php:231` (via `IdleReaper::reapDbMaintenance()`),
 * `ServerReaper.php:135` (via `ServerReaper::tick()`), and
 * `FederationSessionManager.php:201`.
 *
 * A test that only asserted "the sweep succeeded" would have been green
 * throughout. These assert the FAILING path: the sweep throws, the guard eats
 * it, and the outcome reaches the liveness record.
 *
 * No coverage metadata: it discards other files' coverage in this repository
 * (S311). Do not write the tag name here either — spelled out in a docblock it
 * is parsed as a real, invalid entry and throws this file's attribution away.
 *
 * @package Phlix\Hub\Tests\Unit
 */
final class MaintenanceSweepGuardTest extends TestCase
{
    /**
     * The exact exception the container produced, so the guard is exercised
     * against the real thing rather than a convenient RuntimeException.
     */
    private function connectionRefused(): PDOException
    {
        return new PDOException('SQLSTATE[HY000] [2002] Connection refused');
    }

    // -----------------------------------------------------------------------
    // IdleReaper — the first of the three measured throwers
    // -----------------------------------------------------------------------

    public function testIdleReaperDbSweepDoesNotRethrowWhenTheDatabaseIsUnreachable(): void
    {
        $sessions = $this->createMock(RelaySessionManager::class);
        $sessions->method('reapStaleSessions')->willThrowException($this->connectionRefused());

        $reaper = new IdleReaper(
            $this->createMock(TunnelManagerInterface::class),
            $this->createMock(StructuredLogger::class),
            60,
            90,
            $sessions,
        );

        $seen = 'not-called';
        $reaper->runDbMaintenanceGuarded(static function (?Throwable $e) use (&$seen): void {
            $seen = $e;
        });

        self::assertInstanceOf(PDOException::class, $seen);
        self::assertStringContainsString('Connection refused', $seen->getMessage());
    }

    /**
     * The control. Without it, the assertion above would also pass against a
     * reporter that is called unconditionally with a hard-coded exception.
     */
    public function testIdleReaperDbSweepReportsNullWhenItSucceeds(): void
    {
        $sessions = $this->createMock(RelaySessionManager::class);
        $sessions->method('reapStaleSessions')->willReturn(0);

        $reaper = new IdleReaper(
            $this->createMock(TunnelManagerInterface::class),
            $this->createMock(StructuredLogger::class),
            60,
            90,
            $sessions,
        );

        $seen = 'not-called';
        $reaper->runDbMaintenanceGuarded(static function (?Throwable $e) use (&$seen): void {
            $seen = $e;
        });

        self::assertNull($seen, 'a successful sweep must report success, not "no failure seen"');
    }

    public function testIdleReaperDbSweepLogsTheFailure(): void
    {
        $sessions = $this->createMock(RelaySessionManager::class);
        $sessions->method('reapStaleSessions')->willThrowException($this->connectionRefused());

        $logger = $this->createMock(StructuredLogger::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(
                self::stringContains('idle reaper DB-maintenance sweep failed'),
                self::callback(
                    static fn (array $ctx): bool => isset($ctx['error'])
                        && is_string($ctx['error'])
                        && str_contains($ctx['error'], 'Connection refused'),
                ),
            );

        $reaper = new IdleReaper(
            $this->createMock(TunnelManagerInterface::class),
            $logger,
            60,
            90,
            $sessions,
        );

        $reaper->runDbMaintenanceGuarded(null);
    }

    public function testIdleReaperGuardToleratesNoReporter(): void
    {
        $sessions = $this->createMock(RelaySessionManager::class);
        $sessions->method('reapStaleSessions')->willThrowException($this->connectionRefused());

        $reaper = new IdleReaper(
            $this->createMock(TunnelManagerInterface::class),
            $this->createMock(StructuredLogger::class),
            60,
            90,
            $sessions,
        );

        $reaper->runDbMaintenanceGuarded(null);

        self::addToAssertionCount(1); // reached without a rethrow
    }

    // -----------------------------------------------------------------------
    // IdleReaper::tick() — C07: the relay-worker reaper carried the SAME
    // S312 hazard with the largest blast radius in the hub. It is armed on the
    // count=1 :8802 relay child, and closeTunnel()/flushAll() reach the DB
    // (Tunnel::close -> RelaySessionManager::closeSession; flushSession
    // UPDATEs), so one transient MySQL failure with any stale tunnel or
    // non-empty accumulator threw into the event loop -> stopAll(250) ->
    // EVERY server tunnel estate-wide dropped, master silently re-forking and
    // crash-cycling the worker once per interval while the DB stayed sick.
    // -----------------------------------------------------------------------

    /**
     * Helper: a stale tunnel as {@see IdleReaper::tick()} sees it.
     */
    private function staleTunnel(string $tunnelId): TunnelInterface
    {
        $tunnel = $this->createMock(TunnelInterface::class);
        $tunnel->method('getTunnelId')->willReturn($tunnelId);
        $tunnel->method('getLastFrameAt')->willReturn(1_700_000_000);
        $tunnel->method('isStale')->willReturn(true);

        return $tunnel;
    }

    /**
     * Helper: a TunnelManagerInterface yielding exactly these tunnels.
     *
     * The return type keeps the MockObject intersection so PHPStan sees the
     * PHPUnit fluent `method()` API rather than the bare interface.
     *
     * @param array<string, TunnelInterface> $tunnels
     */
    private function managerWithTunnels(
        array $tunnels,
    ): TunnelManagerInterface & \PHPUnit\Framework\MockObject\MockObject {
        $manager = $this->createMock(TunnelManagerInterface::class);
        $manager->method('allTunnels')->willReturn(
            (static function () use ($tunnels): \Generator {
                foreach ($tunnels as $serverId => $tunnel) {
                    yield $serverId => $tunnel;
                }
            })(),
        );

        return $manager;
    }

    public function testIdleReaperTickDoesNotEscapeWhenCloseTunnelThrows(): void
    {
        $manager = $this->managerWithTunnels(['srv-a' => $this->staleTunnel('tun-a')]);
        $manager->method('closeTunnel')->willThrowException($this->connectionRefused());

        $logger = $this->createMock(StructuredLogger::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(
                self::stringContains('failed to close stale tunnel'),
                self::callback(
                    static fn (array $ctx): bool => ($ctx['server_id'] ?? null) === 'srv-a'
                        && is_string($ctx['error'] ?? null)
                        && str_contains($ctx['error'], 'Connection refused'),
                ),
            );

        $reaper = new IdleReaper(
            $manager,
            $logger,
            60,
            90,
            $this->createMock(RelaySessionManager::class),
        );

        // Pre-C07 this call propagated the PDOException straight out of the
        // timer callback; now it returns, having reaped nothing.
        self::assertSame(0, $reaper->tick());
    }

    public function testIdleReaperTickDoesNotEscapeWhenFlushAllThrows(): void
    {
        $sessions = $this->createMock(RelaySessionManager::class);
        $sessions->method('flushAll')->willThrowException($this->connectionRefused());

        $logger = $this->createMock(StructuredLogger::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(
                self::stringContains('accumulator flush failed'),
                self::callback(
                    static fn (array $ctx): bool => is_string($ctx['error'] ?? null)
                        && str_contains($ctx['error'], 'Connection refused'),
                ),
            );

        $reaper = new IdleReaper(
            $this->managerWithTunnels([]),
            $logger,
            60,
            90,
            $sessions,
        );

        self::assertSame(0, $reaper->tick());
    }

    /**
     * Interruption correctness (the reaping-semantics half of C07): a DB
     * failure on ONE close must not skip the rest of the pass — tunnel B is
     * still closed and counted, the byte-accumulator flush still runs, and
     * only the FAILED close is excluded from the reaped count. No double
     * delete across ticks is structurally impossible: a half-closed tunnel
     * leaves the ACTIVE-only allTunnels() scan for ever (its registry entry
     * is unset only on the success path, and its orphaned DB row is the
     * documented job of the maintenance worker's reapStaleSessions()).
     */
    public function testIdleReaperTickKeepsReapingTheRestOfThePassWhenOneCloseFails(): void
    {
        $closed = [];
        $manager = $this->managerWithTunnels([
            'srv-a' => $this->staleTunnel('tun-a'),
            'srv-b' => $this->staleTunnel('tun-b'),
        ]);
        $manager->method('closeTunnel')->willReturnCallback(
            static function (string $serverId) use (&$closed): void {
                if ($serverId === 'srv-a') {
                    throw new PDOException('SQLSTATE[HY000] [2002] Connection refused');
                }
                $closed[] = $serverId;
            },
        );

        $sessions = $this->createMock(RelaySessionManager::class);
        $sessions->expects(self::once())->method('flushAll');

        $logger = $this->createMock(StructuredLogger::class);
        $logger->expects(self::once())->method('error'); // only srv-a's failure

        $reaper = new IdleReaper($manager, $logger, 60, 90, $sessions);

        self::assertSame(1, $reaper->tick(), 'the failed close must not be counted, the good one must');
        self::assertSame(['srv-b'], $closed, 'the pass must continue past the failing tunnel');
    }

    public function testIdleReaperRunTickGuardedSwallowsAnyOtherEscapeAndLogs(): void
    {
        // pruneDeadTunnels() is deliberately NOT section-guarded inside
        // tick() — this throws PAST tick()'s guards, and only the wrapper the
        // timer actually holds may catch it.
        $manager = $this->createMock(TunnelManagerInterface::class);
        $manager->method('pruneDeadTunnels')->willThrowException(
            new RuntimeException('registry exploded'),
        );

        $logger = $this->createMock(StructuredLogger::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(
                self::stringContains('idle reaper tick failed'),
                self::callback(
                    static fn (array $ctx): bool => ($ctx['error'] ?? null) === 'registry exploded'
                        && ($ctx['exception'] ?? null) === RuntimeException::class,
                ),
            );

        $reaper = new IdleReaper(
            $manager,
            $logger,
            60,
            90,
            $this->createMock(RelaySessionManager::class),
        );

        self::assertSame(0, $reaper->runTickGuarded());
    }

    /**
     * The relay reaper's timer must be armed THROUGH the guard. Asserting the
     * guard works says nothing about whether the arming site uses it — the
     * same omission shape as {@see testTheFederationTimerIsArmedThroughTheGuard}
     * pins for the federation sweep.
     */
    public function testTheIdleReaperTimerIsArmedThroughTheGuard(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Relay/IdleReaper.php',
        );

        self::assertMatchesRegularExpression(
            '/Timer::add\(\s*\$this->intervalSeconds,\s*function \(\): void \{\s*\$this->runTickGuarded\(\);/',
            $source,
            'IdleReaper::start() must arm the guarded tick, not a bare [$this, \'tick\']',
        );
    }

    // -----------------------------------------------------------------------
    // ServerReaper — the second measured thrower
    // -----------------------------------------------------------------------

    public function testServerReaperTickDoesNotRethrowWhenTheDatabaseIsUnreachable(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willThrowException($this->connectionRefused());

        $reaper = new ServerReaper($db, $this->createMock(StructuredLogger::class), 60, 180, 7);

        $seen = 'not-called';
        $reaper->runTickGuarded(static function (?Throwable $e) use (&$seen): void {
            $seen = $e;
        });

        self::assertInstanceOf(PDOException::class, $seen);
    }

    public function testServerReaperTickReportsNullWhenItSucceeds(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn(0);

        $reaper = new ServerReaper($db, $this->createMock(StructuredLogger::class), 60, 180, 7);

        $seen = 'not-called';
        $reaper->runTickGuarded(static function (?Throwable $e) use (&$seen): void {
            $seen = $e;
        });

        self::assertNull($seen);
    }

    public function testServerReaperTickLogsTheFailure(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willThrowException($this->connectionRefused());

        $logger = $this->createMock(StructuredLogger::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(self::stringContains('ServerReaper: tick failed'), self::anything());

        $reaper = new ServerReaper($db, $logger, 60, 180, 7);
        $reaper->runTickGuarded(null);
    }

    // -----------------------------------------------------------------------
    // The federation sweep — the third measured thrower.
    //
    // Its guard lives in HubServicesProvider::runGuardedSweep() rather than
    // inline in the Timer closure precisely so it can be reached from here: an
    // inline try/catch in a `Timer::add()` callback is testable only by arming a
    // real Workerman timer, which would have left this thrower pinned by the
    // container run alone.
    // -----------------------------------------------------------------------

    public function testGuardedSweepSwallowsTheThrowAndReportsIt(): void
    {
        $thrown = $this->connectionRefused();

        $seen = 'not-called';
        HubServicesProvider::runGuardedSweep(
            'federation-session reaper',
            static function () use ($thrown): void {
                throw $thrown;
            },
            static function (?Throwable $e) use (&$seen): void {
                $seen = $e;
            },
            $this->createMock(StructuredLogger::class),
        );

        self::assertSame($thrown, $seen);
    }

    public function testGuardedSweepReportsNullWhenTheWorkSucceeds(): void
    {
        $ran = false;
        $seen = 'not-called';
        HubServicesProvider::runGuardedSweep(
            'federation-session reaper',
            static function () use (&$ran): void {
                $ran = true;
            },
            static function (?Throwable $e) use (&$seen): void {
                $seen = $e;
            },
            $this->createMock(StructuredLogger::class),
        );

        self::assertTrue($ran, 'the guard must still RUN the work');
        self::assertNull($seen);
    }

    public function testGuardedSweepLogsTheFailureWithTheTaskName(): void
    {
        $logger = $this->createMock(StructuredLogger::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(
                self::identicalTo('Maintenance: federation-session reaper sweep failed'),
                self::anything(),
            );

        HubServicesProvider::runGuardedSweep(
            'federation-session reaper',
            fn () => throw $this->connectionRefused(),
            null,
            $logger,
        );
    }

    public function testGuardedSweepToleratesNoReporter(): void
    {
        HubServicesProvider::runGuardedSweep(
            'federation-session reaper',
            fn () => throw $this->connectionRefused(),
            null,
            $this->createMock(StructuredLogger::class),
        );

        self::addToAssertionCount(1); // reached without a rethrow
    }

    /**
     * The federation timer's closure must go THROUGH the guard. Asserting the
     * guard works says nothing about whether the arming site uses it — that is
     * the same omission shape as an unregistered DI parameter.
     */
    public function testTheFederationTimerIsArmedThroughTheGuard(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Common/Container/Providers/HubServicesProvider.php',
        );

        self::assertStringContainsString('self::runGuardedSweep(', $source);
        self::assertMatchesRegularExpression(
            '/runGuardedSweep\(\s*\'federation-session reaper\'/',
            $source,
        );
    }

    // -----------------------------------------------------------------------
    // Guard -> heartbeat: the failure has to REACH the operator-visible record
    // -----------------------------------------------------------------------

    public function testAFailingSweepAdvancesTheHeartbeatIntoDegradedNotDown(): void
    {
        $dir = sys_get_temp_dir() . '/phlix-hub-s312-guard-' . uniqid('', true);
        mkdir($dir, 0700, true);
        $path = $dir . '/hb.json';

        try {
            $heartbeat = new MaintenanceHeartbeat($path, 180, true);
            $heartbeat->arm(4242);

            $db = $this->createMock(Connection::class);
            $db->method('query')->willThrowException($this->connectionRefused());
            $reaper = new ServerReaper($db, $this->createMock(StructuredLogger::class), 60, 180, 7);

            $reaper->runTickGuarded(static function (?Throwable $e) use ($heartbeat): void {
                $heartbeat->recordSweep('server_reaper', $e);
            });

            $snapshot = $heartbeat->snapshot();

            // DEGRADED, not DOWN: the worker completed the sweep and is alive.
            // Before S312 it would have been killed at this exact point, and
            // the record would have stayed at `no_sweep_completed` for ever.
            self::assertSame(MaintenanceHeartbeat::STATUS_DEGRADED, $snapshot['status']);
            self::assertSame(1, $snapshot['consecutive_failures']);
            self::assertSame(1, $snapshot['incarnations']);
            self::assertIsString($snapshot['last_error']);
            self::assertStringContainsString('Connection refused', $snapshot['last_error']);
        } finally {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }
}
