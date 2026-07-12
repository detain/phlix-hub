<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\Container\Providers;

use Phlix\Hub\Common\Container\Providers\HubServicesProvider;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Federation\FederationSessionManager;
use Phlix\Hub\Relay\IdleReaper;
use Phlix\Hub\Relay\TunnelManager;
use Phlix\Hub\ServerReaper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Covers the HB-2.6 DATA-LOCALITY split of the periodic reaper wiring:
 * {@see HubServicesProvider::startInMemoryReapers()} (armed on the RELAY worker,
 * for tasks that scan the live in-memory tunnel registry + accumulators) and
 * {@see HubServicesProvider::startDbMaintenanceTimers()} (armed on the dedicated
 * MAINTENANCE worker, for DB-only reapers/pruners).
 *
 * Both are armed from within a worker's event loop (cid>=0) instead of
 * {@see HubServicesProvider::boot()} (the master's pcntl signal scheduler,
 * cid<0, which bypassed {@see \Phlix\Hub\Common\Database\PhlixMySQLConnection}'s
 * per-connection mutex and 500ed heartbeat/claim with "already active
 * transaction").
 *
 * The wiring's contract is that each timer is resolved and armed INDEPENDENTLY
 * under its own try/catch, so one unavailable — or mis-typed — service can never
 * block the others, AND that each set resolves ONLY the services whose data
 * lives in that worker: the relay set must NOT resolve the DB-only reapers and,
 * critically for the regression this class guards, the maintenance set must NOT
 * resolve the {@see TunnelManager} the idle reaper / keepalive heartbeat scan
 * (the maintenance fork's registry is empty, so arming them there is a no-op
 * that silently breaks HB-0.1 + the keepalive heartbeat). Tests stay hermetic
 * (no event loop) by never letting a real reaper resolve, so no
 * {@see \Workerman\Timer::add()} is ever reached.
 */
#[CoversClass(HubServicesProvider::class)]
final class HubServicesProviderTest extends TestCase
{
    /** @var non-empty-string */
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        // The wiring resolves its logger through the static LoggerFactory; point
        // it at a memory stream so the guarded failures it logs go nowhere (no
        // real log files, no PHPUnit output).
        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-hubservices-test-' . uniqid();
        mkdir($this->tmpDir, 0700, true);
        file_put_contents(
            $this->tmpDir . '/logger.php',
            "<?php return ['default' => 'mem', 'handlers' => ['mem' => "
            . "['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug']]];",
        );
        LoggerFactory::reset();
        LoggerFactory::init($this->tmpDir . '/logger.php');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        LoggerFactory::reset();
        @unlink($this->tmpDir . '/logger.php');
        @rmdir($this->tmpDir);
    }

    /**
     * The RELAY-worker (in-memory) set, in the exact order it arms its timers:
     * the idle-tunnel reaper and the tunnel keepalive heartbeat (which scans the
     * {@see TunnelManager}).
     *
     * @return list<class-string>
     */
    private function expectedInMemoryServices(): array
    {
        return [
            IdleReaper::class,
            TunnelManager::class,
        ];
    }

    /**
     * The MAINTENANCE-worker (DB-only) set, in the exact order it arms its
     * timers. Crucially does NOT include {@see TunnelManager}: the maintenance
     * fork's tunnel registry is empty, so the in-memory reaper/heartbeat that
     * scan it belong on the relay worker only.
     *
     * @return list<class-string>
     */
    private function expectedDbServices(): array
    {
        return [
            IdleReaper::class,
            ServerReaper::class,
            FederationSessionManager::class,
        ];
    }

    /**
     * A container recording every get() id, then throwing — so if a set's arms
     * were not each independently guarded, the first throw would abort the rest.
     */
    private function recordingThrowingContainer(): ContainerInterface
    {
        return new class implements ContainerInterface {
            /** @var list<string> */
            public array $seen = [];

            public function get(string $id): mixed
            {
                $this->seen[] = $id;
                throw new \RuntimeException("unavailable: {$id}");
            }

            public function has(string $id): bool
            {
                return true;
            }
        };
    }

    /**
     * A container recording every get() id, then returning the WRONG type — so
     * the instanceof guards are all false, no reaper starts, no Timer is armed,
     * and every service is still attempted.
     */
    private function recordingWrongTypeContainer(): ContainerInterface
    {
        return new class implements ContainerInterface {
            /** @var list<string> */
            public array $seen = [];

            public function get(string $id): mixed
            {
                $this->seen[] = $id;
                return new \stdClass();
            }

            public function has(string $id): bool
            {
                return true;
            }
        };
    }

    public function testInMemoryReapersResolveEveryServiceEvenWhenEachResolutionThrows(): void
    {
        $container = $this->recordingThrowingContainer();

        HubServicesProvider::startInMemoryReapers($container);

        self::assertSame($this->expectedInMemoryServices(), $container->seen);
    }

    public function testInMemoryReapersSkipWiringWhenServicesResolveToUnexpectedTypes(): void
    {
        $container = $this->recordingWrongTypeContainer();

        HubServicesProvider::startInMemoryReapers($container);

        self::assertSame($this->expectedInMemoryServices(), $container->seen);
    }

    public function testDbMaintenanceTimersResolveEveryServiceEvenWhenEachResolutionThrows(): void
    {
        $container = $this->recordingThrowingContainer();

        HubServicesProvider::startDbMaintenanceTimers($container);

        self::assertSame($this->expectedDbServices(), $container->seen);
    }

    public function testDbMaintenanceTimersSkipWiringWhenServicesResolveToUnexpectedTypes(): void
    {
        $container = $this->recordingWrongTypeContainer();

        HubServicesProvider::startDbMaintenanceTimers($container);

        self::assertSame($this->expectedDbServices(), $container->seen);
    }

    /**
     * HB-2.6 regression guard: the maintenance worker must NEVER arm the
     * in-memory tunnel reaper / keepalive heartbeat, i.e. it must never resolve
     * the {@see TunnelManager} (its registry is empty). Before the fix ALL
     * reapers — including the heartbeat pinger that iterates allTunnels() — were
     * armed on the maintenance worker, so TunnelManager WAS resolved there; this
     * test FAILS against that wiring.
     */
    public function testDbMaintenanceTimersNeverResolveTunnelManager(): void
    {
        $container = $this->recordingThrowingContainer();

        HubServicesProvider::startDbMaintenanceTimers($container);

        self::assertNotContains(
            TunnelManager::class,
            $container->seen,
            'The maintenance worker must not resolve TunnelManager: the in-memory '
            . 'tunnel reaper + keepalive heartbeat scan the live registry that '
            . 'lives only in the relay worker, so they belong on the relay worker.',
        );
    }

    /**
     * Complementary guard: the relay worker's in-memory set must NOT resolve the
     * DB-only reapers (ServerReaper, FederationSessionManager) — those run off
     * the relay loop on the maintenance worker (the HB-2.6 intent).
     */
    public function testInMemoryReapersNeverResolveDbOnlyReapers(): void
    {
        $container = $this->recordingThrowingContainer();

        HubServicesProvider::startInMemoryReapers($container);

        self::assertNotContains(ServerReaper::class, $container->seen);
        self::assertNotContains(FederationSessionManager::class, $container->seen);
    }
}
