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
 * {@see CoreUpdateCheckWorker} — the S75 acceptance criterion "the worker fires
 * on BOTH fresh boot AND the steady-state daily poll".
 *
 * ## The two arms are pinned SEPARATELY, on purpose
 *
 * A single assertion covering "a check happened" cannot distinguish the two:
 * deleting the boot catch-up leaves a timer that still eventually ticks, and a
 * timer that was armed one-shot still produces the boot check. So:
 *
 *  - **ARM A (boot catch-up)** — {@see testStartRunsOneCheckImmediatelyAtBoot}
 *    and {@see testTheBootCheckHappensWithoutAnyTimerTick}: `start()` must have
 *    already performed a check by the time it returns, with the timer never
 *    invoked. Deleting `$this->tick();` from `start()` reddens ONLY these.
 *  - **ARM B (steady-state poll)** —
 *    {@see testStartArmsARepeatingTimerAtTheConfiguredInterval} and
 *    {@see testTheArmedTimerCallbackPerformsAFurtherCheck}: the task the
 *    production `Timer::add()` registered must be PERSISTENT, at the configured
 *    interval, and invoking it must produce a further check. Arm B counts
 *    checks RELATIVE to whatever `start()` left behind, so it stays green when
 *    the boot catch-up is removed — which is what makes the two red sets
 *    disjoint.
 *
 * Why a bare `Timer::add(86400, ...)` is not enough on its own: its first tick
 * is 86400s after process start, so a hub restarted more often than that (any
 * deploy, `--update`, reboot or SIGUSR2 reload) never checks at all. That exact
 * defect shipped once in this estate.
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

    private function worker(int $interval = CoreUpdateCheckWorker::DEFAULT_INTERVAL_SECONDS): CoreUpdateCheckWorker
    {
        $service = new CoreUpdateCheckService(
            new HubSettingsRepository($this->db, $this->tmpDir),
            $this->fetcher,
            LoggerFactory::get('hub'),
            'https://example.invalid/VERSION',
            'curl -fsSL https://example.invalid/install.sh | sudo bash -s -- --update -y',
            '0.5.0',
        );

        return new CoreUpdateCheckWorker($service, LoggerFactory::get('hub'), $interval);
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
     * ARM A — the boot catch-up. `start()` must have checked ALREADY by the
     * time it returns.
     */
    public function testStartRunsOneCheckImmediatelyAtBoot(): void
    {
        $worker = $this->worker();
        self::assertSame(0, $this->checks());

        $worker->start();

        self::assertSame(
            1,
            $this->checks(),
            'start() must perform a boot catch-up check — a bare Timer::add(86400) '
            . 'never fires on a hub that restarts more often than the interval',
        );
    }

    /**
     * ARM A — and that boot check must come from `start()` itself, not from a
     * timer: no registered task has been invoked at this point.
     */
    public function testTheBootCheckHappensWithoutAnyTimerTick(): void
    {
        $worker = $this->worker();
        $worker->start();

        // Nothing here invokes a timer callback, yet the marker has been
        // fetched and the result persisted.
        self::assertSame(1, $this->checks());
        self::assertSame('0.9.9', $this->statusLatestVersion());
    }

    // ---------------------------------------------------------------- ARM B

    /**
     * ARM B — the steady-state poll: a REPEATING task at the configured
     * interval. `persistent === false` would fire exactly once and then stop.
     */
    public function testStartArmsARepeatingTimerAtTheConfiguredInterval(): void
    {
        $worker  = $this->worker();
        $timerId = $worker->start();

        $task = $this->registeredTask($timerId);

        self::assertSame(
            (float) CoreUpdateCheckWorker::DEFAULT_INTERVAL_SECONDS,
            (float) $task[3],
            'the steady-state poll must be armed at the configured interval',
        );
        self::assertTrue(
            $task[2],
            'the poll timer must be PERSISTENT — a one-shot timer polls exactly once, ever',
        );
    }

    /**
     * ARM B — invoking the callback Workerman actually holds must produce a
     * FURTHER check. Counted RELATIVE to whatever `start()` left behind, so
     * this arm survives (and therefore does not mask) a removed boot catch-up.
     */
    public function testTheArmedTimerCallbackPerformsAFurtherCheck(): void
    {
        $worker  = $this->worker();
        $timerId = $worker->start();

        $before = $this->checks();
        $task   = $this->registeredTask($timerId);

        self::assertIsCallable($task[0]);
        ($task[0])(...$task[1]);
        self::assertSame($before + 1, $this->checks(), 'the daily tick must perform a check');

        ($task[0])(...$task[1]);
        self::assertSame($before + 2, $this->checks(), 'every subsequent tick must check too');
    }

    /**
     * ARM B — the interval is configurable (the DI factory feeds it from
     * `config/updates.php`), and the constant is the default rather than a
     * hard-coded literal in `start()`.
     */
    public function testTheIntervalComesFromTheConstructor(): void
    {
        $worker  = $this->worker(3600);
        $timerId = $worker->start();

        self::assertSame(3600.0, (float) $this->registeredTask($timerId)[3]);
        self::assertSame(86400, CoreUpdateCheckWorker::DEFAULT_INTERVAL_SECONDS);
    }

    // ------------------------------------------------------------- ROBUSTNESS

    /**
     * A throw inside a check must never escape a tick: in production the tick
     * IS a Workerman timer callback, and an escaping throwable takes the
     * maintenance worker's loop with it.
     */
    public function testATickSwallowsAThrowingService(): void
    {
        $throwing = new class implements VersionMarkerFetcherInterface {
            public function fetch(string $url, callable $onDone): void
            {
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
        $worker = new CoreUpdateCheckWorker($service, LoggerFactory::get('hub'), 60);

        $timerId = $worker->start();
        self::assertIsInt($timerId);

        $task = $this->registeredTask($timerId);
        ($task[0])(...$task[1]);

        self::assertTrue(true, 'neither the boot catch-up nor a tick may throw');
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
