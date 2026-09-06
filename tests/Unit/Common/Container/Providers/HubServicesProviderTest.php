<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\Container\Providers;

use Phlix\Hub\Common\Container\Providers\HubServicesProvider;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Federation\FederationSessionManager;
use Phlix\Hub\Health\MaintenanceHeartbeat;
use Phlix\Hub\Hub\Updates\CoreUpdateCheckWorker;
use Phlix\Hub\Relay\IdleReaper;
use Phlix\Hub\Relay\TunnelManager;
use Phlix\Hub\ServerReaper;
use Phlix\Hub\Tests\Support\Container\RecordingThrowingContainer;
use Phlix\Hub\Tests\Support\Container\RecordingWrongTypeContainer;
use Phlix\Hub\Tests\Support\LoggerFactoryIsolation;
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
final class HubServicesProviderTest extends TestCase
{
    // LoggerFactory's static $configPath/$loggers are process-global; the trait
    // snapshots them before setUp() and restores them after tearDown().
    use LoggerFactoryIsolation;

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
            // S312: resolved FIRST and armed before any sweep is due, so
            // /health has a record to read during the first interval instead of
            // reporting a maintenance worker that does not exist yet. It is
            // also the reason the two tests above matter: a heartbeat whose
            // resolution threw and aborted the rest of this method would leave
            // the hub with no reapers at all AND no signal saying so.
            MaintenanceHeartbeat::class,
            IdleReaper::class,
            ServerReaper::class,
            FederationSessionManager::class,
            // S75: the core update check polls a remote VERSION marker and
            // writes hub_settings rows — DB + outbound HTTP, no tunnel
            // registry — so it belongs on this count=1 worker.
            CoreUpdateCheckWorker::class,
        ];
    }

    /**
     * A container recording every get() id, then throwing — so if a set's arms
     * were not each independently guarded, the first throw would abort the rest.
     */
    private function recordingThrowingContainer(): RecordingThrowingContainer
    {
        return new RecordingThrowingContainer();
    }

    /**
     * A container recording every get() id, then returning the WRONG type — so
     * the instanceof guards are all false, no reaper starts, no Timer is armed,
     * and every service is still attempted.
     */
    private function recordingWrongTypeContainer(): RecordingWrongTypeContainer
    {
        return new RecordingWrongTypeContainer();
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

    /**
     * S62 regression gate: EVERY nullable service dependency of
     * {@see IdleReaper} must be named in the provider's `->parameter()` chain.
     *
     * This is the PHP-DI optional-parameter trap, and it fails SILENTLY. PHP-DI
     * skips a constructor parameter that has a default value, so a nullable
     * dependency added to {@see IdleReaper} without a matching `->parameter()`
     * line resolves to `null`, the `?->` call in
     * {@see IdleReaper::reapDbMaintenance()} short-circuits, and the pruner
     * simply never runs. Nothing goes red: the class still constructs, the timer
     * still arms, and every unit test that passes the dependency in by hand
     * still passes. Only a check at the DEFINITION is capable of seeing it.
     *
     * The assertion is on the constructor rather than on a hard-coded list, so a
     * dependency added in a future step is covered without anybody remembering
     * to extend this test.
     */
    public function testEveryNullableIdleReaperDependencyIsExplicitlyWiredInTheDefinition(): void
    {
        $wired = self::factoryParameterNamesFor(IdleReaper::class);

        // Non-vacuity: if the definition could not be located, or PHP-DI's
        // internals changed shape, `$wired` would be empty and every assertion
        // below would be trivially true. Assert the corpus first.
        self::assertNotEmpty(
            $wired,
            'No PHP-DI factory parameters were extracted for IdleReaper, so this test is measuring '
            . 'nothing. The definition lookup below has broken — fix it rather than deleting the test.',
        );

        $nullableServiceParameters = [];
        foreach ((new \ReflectionClass(IdleReaper::class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin() || !$type->allowsNull()) {
                continue;
            }
            $nullableServiceParameters[] = $parameter->getName();
        }

        self::assertGreaterThanOrEqual(
            5,
            count($nullableServiceParameters),
            'IdleReaper was expected to carry at least the five optional collaborators it had when this '
            . 'gate was written (sessionManager, heartbeatHandler, clientRelayTokenService, keyManager, '
            . 'mcpTokenService). Finding fewer means the reflection is not seeing the constructor.',
        );

        foreach ($nullableServiceParameters as $name) {
            self::assertContains(
                $name,
                $wired,
                sprintf(
                    'IdleReaper::$%s is an optional service dependency that the container definition never '
                    . 'supplies. PHP-DI SKIPS optional parameters, so it resolves to null at runtime and the '
                    . '`?->` call in reapDbMaintenance() silently does nothing. Add '
                    . '`->parameter(\'%s\', get(...))` to the IdleReaper definition in HubServicesProvider.',
                    $name,
                    $name,
                ),
            );
        }
    }

    /**
     * Pull the `->parameter()` names PHP-DI holds for a factory definition
     * registered by {@see HubServicesProvider}.
     *
     * Reads the builder's `definitionSources` because `ContainerBuilder` exposes
     * no getter. Deliberately does NOT build the container: resolving these
     * services would need a live database.
     *
     * @param class-string $id Entry to look up.
     *
     * @return list<string> Parameter names, or `[]` when not found.
     */
    private static function factoryParameterNamesFor(string $id): array
    {
        $builder = new \DI\ContainerBuilder();
        (new HubServicesProvider())->register($builder, []);

        $sources = new \ReflectionProperty(\DI\ContainerBuilder::class, 'definitionSources');
        /** @var mixed $rawSources */
        $rawSources = $sources->getValue($builder);
        if (!is_array($rawSources)) {
            return [];
        }

        /** @var mixed $source */
        foreach ($rawSources as $source) {
            if (!is_array($source) || !isset($source[$id])) {
                continue;
            }
            /** @var mixed $helper */
            $helper = $source[$id];
            if (!$helper instanceof \DI\Definition\Helper\FactoryDefinitionHelper) {
                continue;
            }
            $definition = $helper->getDefinition($id);

            return array_values(array_map(strval(...), array_keys($definition->getParameters())));
        }

        return [];
    }
}
