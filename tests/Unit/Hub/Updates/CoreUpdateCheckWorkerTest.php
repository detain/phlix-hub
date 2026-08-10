<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub\Updates;

use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Hub\HubSettingsRepository;
use Phlix\Hub\Hub\Updates\CoreUpdateCheckService;
use Phlix\Hub\Hub\Updates\CoreUpdateCheckWorker;
use Phlix\Hub\Hub\Updates\VersionMarkerFetcherInterface;
use Phlix\Hub\Tests\Support\InMemoryHubSettingsConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use Phlix\Hub\Tests\Support\LoggerFactoryIsolation;
use Phlix\Hub\Tests\Support\WorkermanTimerRuntimeControl;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Workerman\Timer;

/**
 * {@see CoreUpdateCheckWorker} — the S308 shape: "no network on the boot path,
 * and a hub restarted more often than the interval still polls once per
 * interval".
 *
 * ## The three arms are pinned SEPARATELY, on purpose
 *
 * A single "a check eventually happened" assertion cannot distinguish them, and
 * the three defects they exclude are different defects:
 *
 *  - **ARM A (the boot path is network-free)** —
 *    {@see testStartPerformsNoFetchAtAll} and
 *    {@see testStartReturnsBeforeAnythingIsFetched}: `start()` must arm a timer
 *    and nothing else. Reinstating S75's `$this->tick();` in `start()` reddens
 *    ONLY these. This is the S308 acceptance criterion at unit level: a boot
 *    that reaches out to `raw.githubusercontent.com` makes an air-gapped
 *    install behave differently from a connected one, and — measured — hangs
 *    the maintenance worker's `onWorkerStart` when DNS blackholes.
 *  - **ARM B (the sweep exists and drives the check)** —
 *    {@see testStartArmsARepeatingSweep} and
 *    {@see testTheArmedSweepCallbackPerformsTheCatchUpCheck}: the task
 *    production handed Workerman must be PERSISTENT at the SWEEP cadence, and
 *    invoking it must produce the catch-up check. `persistent === false` would
 *    sweep exactly once, ever.
 *  - **ARM C (the sweep is DUE-GATED)** —
 *    {@see testASecondSweepInsideTheIntervalDoesNotFetch} and
 *    {@see testASweepAfterTheIntervalHasElapsedFetchesAgain}: sweeping every
 *    60s must NOT mean fetching every 60s. Deleting the `isDue()` gate reddens
 *    ONLY the first of those; making `isDue()` return a constant `false`
 *    reddens ONLY the second. Time is controlled by writing
 *    `updates.last_checked_at` into the shared settings store, not by mocking a
 *    clock.
 *
 * Why a bare `Timer::add(86400, ...)` would not do: its first tick is 86400s
 * after process start, so a hub restarted more often than that (any deploy,
 * `--update`, reboot or SIGUSR2 reload) never checks at all. That exact defect
 * shipped once in this estate. The 60-second sweep plus a persisted
 * `last_checked_at` is what keeps the catch-up without putting a fetch on the
 * boot path.
 *
 * ## How the timer is observed
 *
 * `Timer::add()` refuses to run outside a workerman environment
 * (`Timer.php:155` — `if (!Worker::getAllWorkers()) throw`), so `Worker::$workers`
 * is seeded through reflection, which also routes `Timer::add()` down its
 * task-table path. The registered `[callable, args, persistent, interval]`
 * tuple is then read back out of `Timer::$tasks` — i.e. the assertions are made
 * against what PRODUCTION code handed Workerman, not against a test seam.
 *
 * @package Phlix\Hub\Tests\Unit\Hub\Updates
 */
#[CoversClass(CoreUpdateCheckWorker::class)]
final class CoreUpdateCheckWorkerTest extends TestCase
{
    // Workerman's Worker/Timer statics and LoggerFactory's $configPath are both
    // process-global; the traits snapshot them before setUp() and restore them
    // after tearDown(), so nothing escapes this class.
    use WorkermanTimerRuntimeControl;
    use LoggerFactoryIsolation;

    private string $tmpDir = '';

    private InMemoryHubSettingsConnection $db;

    /** Counting fetcher shared by the service under test. */
    private VersionMarkerFetcherInterface $fetcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-updates-worker-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0700, true);
        file_put_contents(
            $this->tmpDir . '/logger.php',
            "<?php return ['default' => 'mem', 'handlers' => ['mem' => "
            . "['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug']]];",
        );
        file_put_contents($this->tmpDir . '/updates.php', "<?php\n\nreturn ['check_enabled' => true];\n");
        LoggerFactory::reset();
        LoggerFactory::init($this->tmpDir . '/logger.php');

        $this->db      = new InMemoryHubSettingsConnection();
        $this->fetcher = new class implements VersionMarkerFetcherInterface {
            public int $calls = 0;

            public function fetch(string $url, callable $onDone): void
            {
                $this->calls++;
                $onDone('0.9.9', null);
            }
        };

        // Seeds Worker::$workers so the production Timer::add() reaches its
        // task-table path. The trait restores the previous value after tearDown().
        $this->forceWorkermanRuntime();
    }

    protected function tearDown(): void
    {
        LoggerFactory::reset();
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    private function worker(
        int $interval = CoreUpdateCheckWorker::DEFAULT_INTERVAL_SECONDS,
        int $sweep = CoreUpdateCheckWorker::DEFAULT_SWEEP_SECONDS,
    ): CoreUpdateCheckWorker {
        return new CoreUpdateCheckWorker($this->service(), LoggerFactory::get('hub'), $interval, $sweep);
    }

    private function service(): CoreUpdateCheckService
    {
        return new CoreUpdateCheckService(
            new HubSettingsRepository($this->db, $this->tmpDir),
            $this->fetcher,
            LoggerFactory::get('hub'),
            'https://example.invalid/VERSION',
            'curl -fsSL https://example.invalid/install.sh | sudo bash -s -- --update -y',
            '0.5.0',
        );
    }

    /**
     * Pretend the last completed check happened `$secondsAgo` seconds ago, by
     * writing the very row {@see CoreUpdateCheckService::isDue()} reads.
     */
    private function seedLastCheckedAt(int $secondsAgo): void
    {
        (new HubSettingsRepository($this->db, $this->tmpDir))->set(
            CoreUpdateCheckService::STATE_LAST_CHECKED_AT,
            time() - $secondsAgo,
            'int',
        );
    }

    /** Number of fetches performed so far (one per completed check). */
    private function checks(): int
    {
        /** @var object{calls: int} $fetcher */
        $fetcher = $this->fetcher;

        return $fetcher->calls;
    }

    // ---------------------------------------------------------------- ARM A

    /**
     * ARM A — THE S308 criterion. `start()` must reach the network ZERO times.
     *
     * This is the unit-level statement of "a container booted with no egress
     * behaves like a connected one": with S75's `$this->tick()` restored to
     * `start()` this assertion reads 1.
     */
    public function testStartPerformsNoFetchAtAll(): void
    {
        $worker = $this->worker();
        self::assertSame(0, $this->checks());

        $worker->start();

        self::assertSame(
            0,
            $this->checks(),
            'start() must not fetch: a network call on the boot path hangs an '
            . 'egress-filtered install and leaves the poll timer unarmed',
        );
    }

    /**
     * ARM A — and nothing was persisted either, which is the observable an
     * accidental fetch would leave behind even if the counter were bypassed.
     */
    public function testStartReturnsBeforeAnythingIsFetched(): void
    {
        $this->worker()->start();

        self::assertNull($this->statusLatestVersion(), 'start() must persist no marker');
        self::assertNull(
            $this->service()->status()->lastCheckedAt,
            'start() must not stamp a check that never happened',
        );
    }

    // ---------------------------------------------------------------- ARM B

    /**
     * ARM B — the sweep: a REPEATING task at the SWEEP cadence (not the poll
     * interval). `persistent === false` would sweep exactly once and then stop.
     */
    public function testStartArmsARepeatingSweep(): void
    {
        $worker  = $this->worker();
        $timerId = $worker->start();

        $task = $this->registeredTask($timerId);

        self::assertSame(
            (float) CoreUpdateCheckWorker::DEFAULT_SWEEP_SECONDS,
            (float) $task[3],
            'the sweep must be armed at the sweep cadence, not the poll interval — '
            . 'a bare Timer::add(86400) never fires on a box that restarts daily',
        );
        self::assertNotSame(
            (float) CoreUpdateCheckWorker::DEFAULT_INTERVAL_SECONDS,
            (float) $task[3],
            'sweep cadence and poll interval must be different values, or the '
            . 'catch-up property is an accident of them happening to match',
        );
        self::assertTrue(
            $task[2],
            'the sweep timer must be PERSISTENT — a one-shot timer sweeps exactly once, ever',
        );
    }

    /**
     * ARM B — invoking the callback Workerman actually holds performs the
     * catch-up check that `start()` no longer does.
     */
    public function testTheArmedSweepCallbackPerformsTheCatchUpCheck(): void
    {
        $worker  = $this->worker();
        $timerId = $worker->start();

        $task = $this->registeredTask($timerId);
        self::assertIsCallable($task[0]);

        ($task[0])(...$task[1]);

        self::assertSame(1, $this->checks(), 'the first sweep must perform the catch-up check');
        self::assertSame('0.9.9', $this->statusLatestVersion());
    }

    // ---------------------------------------------------------------- ARM C

    /**
     * ARM C — sweeping every 60s must NOT mean fetching every 60s. Delete the
     * `isDue()` gate and this is the assertion that reddens.
     */
    public function testASecondSweepInsideTheIntervalDoesNotFetch(): void
    {
        $worker  = $this->worker();
        $timerId = $worker->start();
        $task    = $this->registeredTask($timerId);

        ($task[0])(...$task[1]);
        self::assertSame(1, $this->checks());

        ($task[0])(...$task[1]);
        ($task[0])(...$task[1]);

        self::assertSame(
            1,
            $this->checks(),
            'a sweep inside the poll interval must not touch the network',
        );
    }

    /**
     * ARM C, the other half — and the gate must OPEN once the interval has
     * elapsed. Without this, `isDue()` returning a constant false would pass
     * the test above.
     */
    public function testASweepAfterTheIntervalHasElapsedFetchesAgain(): void
    {
        $worker  = $this->worker(3600);
        $timerId = $worker->start();
        $task    = $this->registeredTask($timerId);

        ($task[0])(...$task[1]);
        self::assertSame(1, $this->checks());

        // The last check now looks like it happened just over an hour ago.
        $this->seedLastCheckedAt(3601);

        ($task[0])(...$task[1]);

        self::assertSame(2, $this->checks(), 'once the interval has elapsed the sweep must fetch');
    }

    /**
     * ARM C — a hub restarted more often than the poll interval must NOT fetch
     * on every restart. This is the behaviour S75's boot catch-up got wrong in
     * the other direction.
     */
    public function testARestartInsideTheIntervalDoesNotProduceAFetch(): void
    {
        $this->seedLastCheckedAt(60);

        // A fresh worker, as after a restart: same store, brand new objects.
        $timerId = $this->worker()->start();
        $task    = $this->registeredTask($timerId);
        ($task[0])(...$task[1]);

        self::assertSame(
            0,
            $this->checks(),
            'a restart 60 seconds after the last check must not re-poll',
        );
    }

    /**
     * The interval and the sweep are both configurable (the DI factory feeds
     * both from `config/updates.php`), and the constants are the defaults
     * rather than hard-coded literals in `start()`.
     */
    public function testTheIntervalAndSweepComeFromTheConstructor(): void
    {
        $timerId = $this->worker(3600, 5)->start();

        self::assertSame(5.0, (float) $this->registeredTask($timerId)[3]);
        self::assertSame(86400, CoreUpdateCheckWorker::DEFAULT_INTERVAL_SECONDS);
        self::assertSame(60, CoreUpdateCheckWorker::DEFAULT_SWEEP_SECONDS);
    }

    // ------------------------------------------------------------- ROBUSTNESS

    /**
     * A throw inside a check must never escape a tick: in production the tick
     * IS a Workerman timer callback, and an escaping throwable takes the
     * maintenance worker's loop with it.
     *
     * Asserted through the OUTCOME rather than an `assertTrue(true)`: the throw
     * must be recorded as a failed check (so `last_checked_at` advances and the
     * due-gate stops the sweep retrying it every 60 seconds), and the sweep must
     * still be armed afterwards.
     */
    public function testATickSwallowsAThrowingServiceAndRecordsIt(): void
    {
        $throwing = new class implements VersionMarkerFetcherInterface {
            public int $calls = 0;

            public function fetch(string $url, callable $onDone): void
            {
                $this->calls++;
                throw new \RuntimeException('network exploded');
            }
        };

        $service = new CoreUpdateCheckService(
            new HubSettingsRepository($this->db, $this->tmpDir),
            $throwing,
            LoggerFactory::get('hub'),
            'https://example.invalid/VERSION',
            'curl',
            '0.5.0',
        );
        $worker = new CoreUpdateCheckWorker($service, LoggerFactory::get('hub'), 3600, 60);

        $timerId = $worker->start();
        self::assertIsInt($timerId);

        $task = $this->registeredTask($timerId);
        ($task[0])(...$task[1]);

        self::assertSame(1, $throwing->calls, 'the sweep must have reached the fetcher');
        self::assertSame('network exploded', $service->status()->lastError);
        self::assertIsInt($service->status()->lastCheckedAt);

        // And the single-flight guard was released: the next DUE sweep fetches
        // again rather than being locked out for the life of the process.
        $this->seedLastCheckedAt(3601);
        ($task[0])(...$task[1]);
        self::assertSame(2, $throwing->calls, 'a throwing fetch must not wedge the single-flight guard');
    }

    // ---------------------------------------------------------------- helpers

    /** Latest version currently persisted in the shared settings store. */
    private function statusLatestVersion(): ?string
    {
        $service = new CoreUpdateCheckService(
            new HubSettingsRepository($this->db, $this->tmpDir),
            $this->fetcher,
            LoggerFactory::get('hub'),
            'https://example.invalid/VERSION',
            'curl',
            '0.5.0',
        );

        return $service->status()->latestVersion;
    }

    /**
     * The `[callable, args, persistent, interval]` tuple Workerman stored for
     * `$timerId`, read straight out of `Timer::$tasks`.
     *
     * @return array{0: callable, 1: array<int, mixed>, 2: bool, 3: float|int}
     */
    private function registeredTask(int $timerId): array
    {
        $property = (new ReflectionClass(Timer::class))->getProperty('tasks');
        $property->setAccessible(true);
        /** @var array<int, array<int, array{0: callable, 1: array<int, mixed>, 2: bool, 3: float|int}>> $tasks */
        $tasks = $property->getValue();

        foreach ($tasks as $bucket) {
            if (isset($bucket[$timerId])) {
                return $bucket[$timerId];
            }
        }

        self::fail('Timer id ' . $timerId . ' is not in Timer::$tasks — start() armed no repeating poll');
    }
}
