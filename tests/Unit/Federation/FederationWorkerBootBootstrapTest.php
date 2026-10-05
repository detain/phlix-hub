<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Federation;

use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Federation\FederationAdminDelegationRepository;
use Phlix\Hub\Federation\FederationHubRepository;
use Phlix\Hub\Federation\FederationLibraryShareRepository;
use Phlix\Hub\Federation\FederationPeerManager;
use Phlix\Hub\Federation\FederationSessionManager;
use Phlix\Hub\Hub\Ed25519KeyManager;
use Phlix\Hub\Relay\FederationWorker;
use Phlix\Hub\Tests\Support\WorkermanTimerRuntimeControl;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionProperty;
use Workerman\Timer;

/**
 * Boot self-heal: the leaf→master chain must be armed from a RUNNING
 * worker's event loop — {@see FederationWorker::onWorkerStart()} — and never
 * from the pre-fork master.
 *
 * Why this exists (STEP-0 repro @229d3d1, 2026-10-05): `Worker::$globalEvent`
 * is null in the master, and `Worker::getEventLoop()` is typed non-nullable,
 * so the old master-side boot dial had two terminal failure shapes — a `ws`
 * peer died at connect() leaving the backoff chain parked in the master's
 * pcntl task table (never tickable once a child set `Timer::$event`, and it
 * FORK-COPIED `reconnectScheduled = true` into every child, poisoning their
 * dedup guard), and a `wss` peer threw from the AsyncTcpConnection
 * CONSTRUCTOR (this vendored workerman has no `Protocols\Wss`) straight
 * through connectToMaster() into boot()'s swallow — no chain, no log, no
 * link. Both made the "a hub that BOOTED … self-heals too" claim a phantom.
 *
 * These tests pin the child-side contract:
 *   1. booted disabled → exactly ONE chain armed in the bootstrap runtime,
 *      parks at the cap, never touches transport or the peer repository;
 *   2. booted enabled, no live connection → dials exactly once;
 *   3. FORK-POISONED latch (reconnectScheduled inherited true) → the
 *      latch-reset law: the child clears it and arms its own chain — the
 *      single pin that dies (zero timers armed) if the reset is removed;
 *   4. booted enabled, dial FAILS (unreachable master) → the backoff chain
 *      survives in the bootstrap runtime and re-dials on the next tick
 *      (the pre-existing enabled-boot gap, closed);
 *   5. forked intentionalDisconnect → the child arms nothing and dials
 *      nothing, without even consulting the settings gate;
 *   6. a `wss`-scheme boot dial that throws from the constructor logs
 *      LOUDLY and hands the chain to the backoff instead of vanishing;
 *   7. an inherited reconnectTimerId is never Timer::del()'d in the child —
 *      the id names a MASTER task-table slot whose numeric value can
 *      collide with a live child-loop timer;
 *   8. the worker's own bootstrap arm is fail-soft on a null or throwing
 *      container, exactly like the push-dispatcher arm beside it;
 *   9. an ENABLED proceed tick whose dial throws re-arms through the
 *      callback's own catch instead of dying on the escape.
 *
 * The pcntl task table reached via forceWorkermanRuntime() is this suite's
 * established observable stand-in for "an arming runtime exists" (see
 * {@see WorkermanTimerRuntimeControl}); the vendor fact that a real child's
 * timers fire in a real loop while the master's never dial is established
 * by the repro above, not re-proved here.
 *
 * @package Phlix\Hub\Tests\Unit\Federation
 */
final class FederationWorkerBootBootstrapTest extends TestCase
{
    use WorkermanTimerRuntimeControl;

    private FederationHubRepository&MockObject $hubRepo;

    private string $keyDir = '';

    private Ed25519KeyManager $keyManager;

    /** Temp file the RELAY log writes to — read back to pin log lines. */
    private string $logFile = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->hubRepo = $this->createMock(FederationHubRepository::class);
        $this->keyDir  = sys_get_temp_dir() . '/phlix-hub-fedboot-keys-' . bin2hex(random_bytes(6));
        mkdir($this->keyDir, 0700, true);
        $this->keyManager = new Ed25519KeyManager($this->keyDir . '/ed25519.pem');

        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'phlix-hub-fedboot-log-');
        $configDir = $this->keyDir . '/logs';
        mkdir($configDir, 0700, true);
        file_put_contents(
            $configDir . '/logger.php',
            "<?php return ['default' => 'mem', 'handlers' => ['mem' => "
            . "['type' => 'stream', 'path' => '" . $this->logFile . "', 'level' => 'debug']]];",
        );
        LoggerFactory::reset();
        LoggerFactory::init($configDir . '/logger.php');
    }

    protected function tearDown(): void
    {
        LoggerFactory::reset();
        if ($this->logFile !== '' && is_file($this->logFile)) {
            unlink($this->logFile);
        }
        $this->logFile = '';
        if ($this->keyDir !== '' && is_dir($this->keyDir . '/logs')) {
            unlink($this->keyDir . '/logs/logger.php');
            @rmdir($this->keyDir . '/logs');
        }
        foreach (glob($this->keyDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @rmdir($this->keyDir);

        parent::tearDown();
    }

    // ------------------------------------------------------------------ 1

    public function testOnWorkerStartWhileDisabledArmsExactlyOneParkedChain(): void
    {
        $this->forceWorkermanRuntime();
        $this->hubRepo->expects(self::never())->method('getHubConfig');
        $this->hubRepo->expects(self::never())->method('getDialablePeers');

        $manager = $this->peerManager(static fn (): bool => false);
        $this->workerHolding($manager)->onWorkerStart();

        self::assertSame(
            5.0,
            $this->soleArmedTimer()['interval'],
            'booted-disabled child arms exactly one fresh chain (initial delay, parks on its first tick)',
        );

        $this->fireArmedTicks();

        $parked = $this->soleArmedTimer();
        self::assertSame(60.0, $parked['interval'], 'the boot chain parks at the cap like every other chain');
        self::assertFalse($manager->isConnected(), 'parking never touches transport');

        $log = (string) file_get_contents($this->logFile);
        self::assertSame(1, substr_count($log, 'reconnect chain parked'), 'one announcement for the off-window');
    }

    // ------------------------------------------------------------------ 2

    public function testOnWorkerStartWhileEnabledWithNoConnectionDialsOnce(): void
    {
        $this->forceWorkermanRuntime();
        $this->hubRepo->expects(self::exactly(1))->method('getHubConfig')->willReturn(null);
        $this->hubRepo->expects(self::never())->method('getDialablePeers');

        $manager = $this->peerManager(static fn (): bool => true);
        $this->workerHolding($manager)->onWorkerStart();

        self::assertCount(0, $this->armedTimers(), 'the enabled bootstrap path DIALS — it does not pre-arm a chain');
        self::assertFalse((bool) $this->readProp($manager, 'reconnectScheduled'));
        self::assertFalse($manager->isConnected());
    }

    // ------------------------------------------------------------------ 3

    /**
     * THE latch-reset law. A fork copies the master's poisoned
     * `reconnectScheduled = true` (with a master-owned timer id behind it);
     * without the reset in bootstrapFromWorker(), the dedup guard would
     * silently swallow the child's ONLY chance to own a tickable chain —
     * remove the two reset lines and this test arms zero timers.
     */
    public function testOnWorkerStartResetsForkInheritedLatchBeforeArming(): void
    {
        $this->forceWorkermanRuntime();

        $manager = $this->peerManager(static fn (): bool => false);
        $this->poisonLatch($manager, inheritedTimerId: 999_999);

        $this->workerHolding($manager)->onWorkerStart();

        self::assertCount(1, $this->armedTimers(), 'reset law: the poisoned child still arms exactly one chain');
        self::assertTrue((bool) $this->readProp($manager, 'reconnectScheduled'));
        self::assertNotSame(
            999_999,
            $this->readProp($manager, 'reconnectTimerId'),
            'the armed chain is the CHILD\'s fresh timer, never the inherited master-side id',
        );
    }

    // ------------------------------------------------------------------ 4

    /**
     * Enabled boot, unreachable master: the dial dies inside
     * establishConnection's catch (the ws connect() failure shape) and the
     * backoff chain must survive IN THE BOOTSTRAP RUNTIME, re-dialing on
     * each tick — this is the enabled-boot half of the self-heal promise
     * (pre-lane, the same failure armed a chain the master could never let
     * anyone else use).
     */
    public function testEnabledBootDialFailureKeepsBackoffChainAliveInChild(): void
    {
        $this->forceWorkermanRuntime();
        $this->hubRepo->method('getHubConfig')->willReturn([
            'id'       => 'leaf-1',
            'role'     => 'leaf',
            'is_active' => 1,
        ]);
        $this->hubRepo->method('getDialablePeers')->willReturn([
            ['id' => 'peer-m', 'url' => 'http://127.0.0.1:1', 'peer_name' => 'm', 'public_key' => ''],
        ]);
        $this->hubRepo->expects(self::exactly(2))->method('getHubConfig');

        $manager = $this->peerManager(static fn (): bool => true);
        $this->workerHolding($manager)->onWorkerStart();

        self::assertSame(
            5.0,
            $this->soleArmedTimer()['interval'],
            'a failed boot dial falls into the backoff chain instead of ending there',
        );

        $this->fireArmedTicks();

        self::assertSame(
            10.0,
            $this->soleArmedTimer()['interval'],
            'tick 2 re-dials, fails again, and climbs the ladder — a live self-heal loop',
        );
        $log = (string) file_get_contents($this->logFile);
        self::assertSame(2, substr_count($log, 'failed to connect'), 'each failed attempt is logged');
    }

    // ------------------------------------------------------------------ 5

    public function testOnWorkerStartHonorsForkedIntentionalDisconnect(): void
    {
        $this->forceWorkermanRuntime();

        $calls = 0;
        $manager = $this->peerManager(static function () use (&$calls): bool {
            $calls++;

            return false;
        });
        $manager->disconnectFromMaster();

        $this->workerHolding($manager)->onWorkerStart();

        self::assertCount(0, $this->armedTimers(), 'a forked deliberate disconnect is honored, not resurrected');
        self::assertSame(0, $calls, 'the M-7 guard sits BEFORE the settings gate — nothing is consulted at all');
        $this->hubRepo->expects(self::never())->method('getHubConfig');
        self::assertFalse($manager->isConnected());
    }

    // ------------------------------------------------------------------ 6

    /**
     * The repro's silent-death shape: an `https` peer maps to the `wss`
     * scheme whose CONSTRUCTOR throws RuntimeException (vendored workerman
     * has no Protocols\Wss) — outside establishConnection's own catch. The
     * bootstrap must turn THAT into a loud log + a backoff chain, which is
     * what makes "boot self-heal" true for every scheme instead of only
     * the ws one. (The wss scheme itself is a separate vendor gap.)
     */
    public function testConstructorThrowingBootDialLogsLoudlyAndArmsBackoff(): void
    {
        $this->forceWorkermanRuntime();
        $this->hubRepo->method('getHubConfig')->willReturn([
            'id'        => 'leaf-1',
            'role'      => 'leaf',
            'is_active' => 1,
        ]);
        $this->hubRepo->method('getDialablePeers')->willReturn([
            ['id' => 'peer-m', 'url' => 'https://master.example', 'peer_name' => 'm', 'public_key' => ''],
        ]);

        $manager = $this->peerManager(static fn (): bool => true);
        $this->workerHolding($manager)->onWorkerStart();

        self::assertSame(
            5.0,
            $this->soleArmedTimer()['interval'],
            'the escaped constructor throw is caught: the chain is armed, not vanished',
        );
        self::assertTrue((bool) $this->readProp($manager, 'reconnectScheduled'));

        $log = (string) file_get_contents($this->logFile);
        self::assertStringContainsString('boot dial threw before the socket existed', $log);
        self::assertStringContainsString('Wss', $log, 'the loud line names the real cause');
    }

    // ------------------------------------------------------------------ 7

    /**
     * The reset must NOT Timer::del() the inherited id: in a real child the
     * numeric id belongs to the master's pcntl table and can collide with a
     * live child-loop timer. Here the unrelated long timer plays that
     * victim — if bootstrapFromWorker ever "cleans up" the inherited id,
     * this task disappears.
     */
    public function testInheritedTimerIdIsNeverDeletedInChild(): void
    {
        $this->forceWorkermanRuntime();
        $unrelated = Timer::add(3600.0, static fn (): null => null);

        $manager = $this->peerManager(static fn (): bool => false);
        $this->poisonLatch($manager, inheritedTimerId: $unrelated);

        $this->workerHolding($manager)->onWorkerStart();

        $ids = array_column($this->armedTimers(), 'timerId');
        self::assertContains($unrelated, $ids, 'the inherited id is forgotton, never cancelled against the child loop');
        self::assertCount(2, $ids, 'one victim + the child\'s own fresh chain');
    }

    // ------------------------------------------------------------------ 8

    public function testWorkerBootstrapIsFailSoftOnUnresolvablePeerManager(): void
    {
        $this->forceWorkermanRuntime();

        $nullish = new FederationWorker(
            new class implements ContainerInterface {
                public function get(string $id): mixed
                {
                    return null;
                }

                public function has(string $id): bool
                {
                    return false;
                }
            },
        );
        $nullish->onWorkerStart();

        $broken = new FederationWorker(
            new class implements ContainerInterface {
                public function get(string $id): mixed
                {
                    throw new \LogicException('container down');
                }

                public function has(string $id): bool
                {
                    throw new \LogicException('container down');
                }
            },
        );
        $broken->onWorkerStart();

        self::assertCount(0, $this->armedTimers(), 'a manager-less boot arms nothing and throws nothing');
        $log = (string) file_get_contents($this->logFile);
        self::assertStringContainsString('could not resolve the peer manager', $log);
        self::assertStringContainsString('leaf link bootstrap failed', $log);
    }

    public function testFederationWorkerIsTheSingleBootDialerByCountOneLaw(): void
    {
        $reflected = new \ReflectionProperty(FederationWorker::class, 'count');
        $constructor = (new \ReflectionClass(FederationWorker::class))->getConstructor();
        self::assertNotNull($constructor, 'FederationWorker must expose its promoted constructor');

        // The count=1 constructor default is the single-dialer invariant:
        // exactly one child process runs bootstrapFromWorker(), so boot
        // dials exactly one link. HubServicesProvider::boot() passes the
        // literal 1; if either ever changes, the boot path stops being a
        // single dialer and this pin must be consciously rotated.
        self::assertSame(
            1,
            $constructor->getParameters()[2]->getDefaultValue(),
            'FederationWorker $count default MUST stay 1 (single boot dialer)',
        );
        self::assertTrue($reflected->isPrivate(), 'count is ctor-private readonly — not rewritable after boot');
    }

    // ------------------------------------------------------------------ 10

    /**
     * The tick-side twin of the constructor-throw hardening: an ENABLED
     * proceed tick whose dial throws (the wss constructor shape) must
     * re-arm through the callback's own catch instead of letting the
     * exception escape the timer callback (chain death on pcntl drivers,
     * crash-loop risk on event-loop drivers). Without the try/catch added
     * around the callback's connectToMaster(), firing this tick throws out
     * of fireArmedTicks() and the test errors — the mutation pin.
     */
    public function testEnabledTickWithThrowingDialReArmsInsteadOfDying(): void
    {
        $this->forceWorkermanRuntime();
        $this->hubRepo->method('getHubConfig')->willReturn([
            'id'        => 'leaf-1',
            'role'      => 'leaf',
            'is_active' => 1,
        ]);
        $this->hubRepo->method('getDialablePeers')->willReturn([
            ['id' => 'peer-m', 'url' => 'https://master.example', 'peer_name' => 'm', 'public_key' => ''],
        ]);

        $manager = $this->peerManager(static fn (): bool => true);
        $arm = new \ReflectionMethod($manager, 'scheduleReconnect');
        $arm->invoke($manager);
        self::assertSame(5.0, $this->soleArmedTimer()['interval']);

        $this->fireArmedTicks(); // dial throws from the ctor → callback catch must hold

        self::assertSame(
            10.0,
            $this->soleArmedTimer()['interval'],
            'the throwing tick climbed the ladder and re-armed — the chain survived the escape shape',
        );
        $log = (string) file_get_contents($this->logFile);
        self::assertStringContainsString('reconnect tick threw', $log);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * A FederationWorker whose container hands back $manager (and nothing
     * else — the push-dispatcher arm beside the bootstrap is covered by
     * FederationPushChannelRoundTripTest and must stay refusal-quiet here).
     */
    private function workerHolding(FederationPeerManager $manager): FederationWorker
    {
        return new FederationWorker(
            new class ($manager) implements ContainerInterface {
                public function __construct(private readonly object $peerManager)
                {
                }

                public function get(string $id): mixed
                {
                    return $id === FederationPeerManager::class ? $this->peerManager : null;
                }

                public function has(string $id): bool
                {
                    return $id === FederationPeerManager::class;
                }
            },
        );
    }

    private function peerManager(?callable $enabledResolver): FederationPeerManager
    {
        return new FederationPeerManager(
            $this->hubRepo,
            $this->createMock(FederationSessionManager::class),
            $this->createMock(FederationLibraryShareRepository::class),
            $this->createMock(FederationAdminDelegationRepository::class),
            $this->createMock(AuditLogger::class),
            $this->keyManager,
            $enabledResolver,
        );
    }

    /** Simulate the exact state a forked child inherits from a poisoned master. */
    private function poisonLatch(FederationPeerManager $manager, int $inheritedTimerId): void
    {
        $scheduled = new ReflectionProperty($manager, 'reconnectScheduled');
        $scheduled->setValue($manager, true);
        $timerId = new ReflectionProperty($manager, 'reconnectTimerId');
        $timerId->setValue($manager, $inheritedTimerId);
    }

    /**
     * @return list<array{runTime: int, timerId: int, interval: float, callback: callable}>
     */
    private function armedTimers(): array
    {
        /** @var array<int, array<int, array{0: callable, 1: array<array-key, mixed>, 2: bool, 3: float}>> $tasks */
        $tasks = (new ReflectionProperty(Timer::class, 'tasks'))->getValue();

        $armed = [];
        foreach ($tasks as $runTime => $bucket) {
            foreach ($bucket as $timerId => $task) {
                $armed[] = [
                    'runTime'  => (int) $runTime,
                    'timerId'  => (int) $timerId,
                    'interval' => (float) $task[3],
                    'callback' => $task[0],
                ];
            }
        }

        return $armed;
    }

    /**
     * @return array{runTime: int, timerId: int, interval: float, callback: callable}
     */
    private function soleArmedTimer(): array
    {
        $armed = $this->armedTimers();
        self::assertCount(1, $armed, 'single-in-flight-timer invariant violated');

        return $armed[0];
    }

    /** Fire every armed one-shot tick, removing table entries before callbacks (vendor tick semantics). */
    private function fireArmedTicks(): void
    {
        $property = new ReflectionProperty(Timer::class, 'tasks');

        foreach ($this->armedTimers() as $timer) {
            /** @var array<int, array<int, mixed>> $tasks */
            $tasks = $property->getValue();
            unset($tasks[$timer['runTime']][$timer['timerId']]);
            if (($tasks[$timer['runTime']] ?? []) === []) {
                unset($tasks[$timer['runTime']]);
            }
            $property->setValue(null, $tasks);

            ($timer['callback'])();
        }
    }

    private function readProp(FederationPeerManager $manager, string $name): mixed
    {
        $property = new ReflectionProperty($manager, $name);

        return $property->getValue($manager);
    }
}
