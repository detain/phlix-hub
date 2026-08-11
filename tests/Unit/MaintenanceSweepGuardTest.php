<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit;

use PDOException;
use Phlix\Hub\Common\Container\Providers\HubServicesProvider;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Health\MaintenanceHeartbeat;
use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\Relay\IdleReaper;
use Phlix\Hub\Relay\TunnelManagerInterface;
use Phlix\Hub\ServerReaper;
use PHPUnit\Framework\TestCase;
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
 * No `@covers`: it discards other files' coverage in this repository.
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

        self::assertTrue(true, 'reached without a rethrow');
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

        self::assertTrue(true, 'reached without a rethrow');
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
