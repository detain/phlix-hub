<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub\Updates;

use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Hub\HubSettingsRepository;
use Phlix\Hub\Hub\Updates\CoreUpdateCheckService;
use Phlix\Hub\Hub\Updates\CoreUpdateStatus;
use Phlix\Hub\Hub\Updates\VersionMarkerFetcherInterface;
use Phlix\Hub\Tests\Support\InMemoryHubSettingsConnection;
use Phlix\Hub\Tests\Support\LoggerFactoryIsolation;
use Phlix\Hub\Tests\Support\WorkermanTimerRuntimeControl;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Workerman\Timer;

/**
 * {@see CoreUpdateCheckService} — S75 / updates.md #48.
 *
 * Everything is driven over a round-tripping `hub_settings` double
 * ({@see InMemoryHubSettingsConnection}) so a "the check persisted it" claim
 * and a "the status endpoint reads it" claim are checked against the SAME
 * store rather than two independent stubs.
 *
 * @package Phlix\Hub\Tests\Unit\Hub\Updates
 */
#[CoversClass(CoreUpdateCheckService::class)]
#[CoversClass(CoreUpdateStatus::class)]
final class CoreUpdateCheckServiceTest extends TestCase
{
    // LoggerFactory's static $configPath/$loggers are process-global; the trait
    // snapshots them before setUp() and restores them after tearDown().
    use LoggerFactoryIsolation;
    // S308's deadline is a real `Workerman\Timer`. Outside a workerman runtime
    // `Timer::add()` THROWS, and the service degrades to "no deadline armed" —
    // so without seeding the registry every deadline assertion below would be
    // testing the degraded arm production never takes. The trait restores every
    // Workerman static afterwards.
    use WorkermanTimerRuntimeControl;

    private const MARKER_URL = 'https://example.invalid/VERSION';

    private const UPDATE_COMMAND = 'curl -fsSL https://example.invalid/install.sh | sudo bash -s -- --update -y';

    private string $tmpDir = '';

    private InMemoryHubSettingsConnection $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-updates-svc-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0700, true);
        file_put_contents(
            $this->tmpDir . '/logger.php',
            "<?php return ['default' => 'mem', 'handlers' => ['mem' => "
            . "['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug']]];",
        );
        LoggerFactory::reset();
        LoggerFactory::init($this->tmpDir . '/logger.php');

        // Config fixture mirroring config/updates.php's shape.
        file_put_contents(
            $this->tmpDir . '/updates.php',
            "<?php\n\nreturn ['check_enabled' => true];\n",
        );

        $this->db = new InMemoryHubSettingsConnection();

        // Route Timer::add() down its task-table path so the deadline the
        // service arms is the one production arms.
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

    /**
     * A fetcher that answers synchronously with a fixed body/error and counts
     * its calls, so "did a fetch happen at all" is directly observable.
     */
    private function fetcher(?string $body, ?string $error = null): VersionMarkerFetcherInterface
    {
        return new class ($body, $error) implements VersionMarkerFetcherInterface {
            public int $calls = 0;

            /** @var list<string> */
            public array $urls = [];

            public function __construct(
                private readonly ?string $body,
                private readonly ?string $error,
            ) {
            }

            public function fetch(string $url, callable $onDone): void
            {
                $this->calls++;
                $this->urls[] = $url;
                $onDone($this->body, $this->error);
            }
        };
    }

    /**
     * A fetcher that NEVER calls back — the shape measured on an egress-filtered
     * box, where the Swoole-hooked DNS resolution inside `stream_socket_client()`
     * does not return and no callback ever arrives.
     */
    private function silentFetcher(): VersionMarkerFetcherInterface
    {
        return new class implements VersionMarkerFetcherInterface {
            public int $calls = 0;

            public function fetch(string $url, callable $onDone): void
            {
                $this->calls++;
            }
        };
    }

    private function service(
        VersionMarkerFetcherInterface $fetcher,
        string $currentVersion = '0.5.0',
        int $deadlineSeconds = CoreUpdateCheckService::DEFAULT_DEADLINE_SECONDS,
    ): CoreUpdateCheckService {
        return new CoreUpdateCheckService(
            new HubSettingsRepository($this->db, $this->tmpDir),
            $fetcher,
            LoggerFactory::get('hub'),
            self::MARKER_URL,
            self::UPDATE_COMMAND,
            $currentVersion,
            $deadlineSeconds,
        );
    }

    public function testStatusBeforeAnyCheckReportsNoUpdateAndNoTimestamp(): void
    {
        $status = $this->service($this->fetcher('0.9.9'))->status();

        self::assertSame('0.5.0', $status->currentVersion);
        self::assertNull($status->latestVersion);
        self::assertFalse($status->updateAvailable);
        self::assertNull($status->lastCheckedAt);
        self::assertNull($status->lastError);
        self::assertTrue($status->checkEnabled);
        self::assertSame(self::UPDATE_COMMAND, $status->updateCommand);
    }

    /**
     * THE headline acceptance criterion at service level: a seeded NEWER
     * marker must make the persisted status report an update.
     */
    public function testCheckAgainstANewerMarkerPersistsAnUpdateAvailableStatus(): void
    {
        $fetcher = $this->fetcher("0.9.9\n");
        $service = $this->service($fetcher);

        $seen = null;
        $service->check(static function (CoreUpdateStatus $status) use (&$seen): void {
            $seen = $status;
        });

        self::assertSame(1, $fetcher->calls);
        self::assertSame([self::MARKER_URL], $fetcher->urls);

        self::assertInstanceOf(CoreUpdateStatus::class, $seen);
        self::assertTrue($seen->updateAvailable);
        self::assertSame('0.9.9', $seen->latestVersion);

        // Re-read through a FRESH service over the same store: this is the read
        // path the HTTP status endpoint takes.
        $reread = $this->service($this->fetcher(null, 'must not be fetched'))->status();
        self::assertTrue($reread->updateAvailable);
        self::assertSame('0.9.9', $reread->latestVersion);
        self::assertNull($reread->lastError);
        self::assertIsInt($reread->lastCheckedAt);
        self::assertGreaterThan(0, $reread->lastCheckedAt);
    }

    public function testCheckAgainstAnIdenticalMarkerReportsNoUpdate(): void
    {
        $service = $this->service($this->fetcher("0.5.0\n"));
        $service->check();

        $status = $service->status();
        self::assertFalse($status->updateAvailable);
        self::assertSame('0.5.0', $status->latestVersion);
    }

    public function testCheckAgainstAnOlderMarkerReportsNoUpdate(): void
    {
        $service = $this->service($this->fetcher('0.4.9'));
        $service->check();

        self::assertFalse($service->status()->updateAvailable);
    }

    /**
     * A captive-portal / error page must NOT be stored as a version, and must
     * never raise a false "update available".
     */
    public function testAnUnparseableMarkerIsRecordedAsAnErrorAndNeverAsAVersion(): void
    {
        $service = $this->service($this->fetcher('<html>404 not found</html>'));
        $service->check();

        $status = $service->status();
        self::assertFalse($status->updateAvailable);
        self::assertNull($status->latestVersion);
        self::assertIsString($status->lastError);
        self::assertStringContainsString('semver', $status->lastError);
        self::assertIsInt($status->lastCheckedAt);
    }

    public function testATransportErrorIsRecordedAndLeavesTheLastKnownVersionIntact(): void
    {
        $good = $this->service($this->fetcher('0.9.9'));
        $good->check();
        self::assertSame('0.9.9', $good->status()->latestVersion);

        $bad = $this->service($this->fetcher(null, 'connection refused'));
        $bad->check();

        $status = $bad->status();
        self::assertSame('connection refused', $status->lastError);
        // The previously-learned version survives a failed poll.
        self::assertSame('0.9.9', $status->latestVersion);
        self::assertTrue($status->updateAvailable);
    }

    /**
     * The toggle must gate the FETCH, not just the reporting: a disabled check
     * that still hits the network every day is not disabled.
     */
    public function testADisabledCheckPerformsNoFetchAtAll(): void
    {
        $fetcher = $this->fetcher('0.9.9');
        $service = $this->service($fetcher);
        $service->setCheckEnabled(false);

        $seen = null;
        $service->check(static function (CoreUpdateStatus $status) use (&$seen): void {
            $seen = $status;
        });

        self::assertSame(0, $fetcher->calls, 'a disabled update check must not reach the network');
        self::assertInstanceOf(CoreUpdateStatus::class, $seen);
        self::assertFalse($seen->checkEnabled);
        self::assertNull($seen->latestVersion);
    }

    public function testTheToggleRoundTripsAndDefaultsToTheConfigValue(): void
    {
        $service = $this->service($this->fetcher('0.9.9'));
        self::assertTrue($service->isCheckEnabled(), 'default must be true');

        $service->setCheckEnabled(false);
        self::assertFalse($service->isCheckEnabled());

        $service->setCheckEnabled(true);
        self::assertTrue($service->isCheckEnabled());
    }

    /**
     * With no `config/updates.php` at all the check must fail OPEN — an
     * operator who never learns about a security release is the worse outcome.
     */
    public function testAnAbsentConfigDefaultFailsOpen(): void
    {
        @unlink($this->tmpDir . '/updates.php');

        self::assertTrue($this->service($this->fetcher('0.9.9'))->isCheckEnabled());
    }

    /**
     * @return list<array{0: string, 1: string, 2: bool}>
     */
    public static function comparisons(): array
    {
        return [
            ['0.5.1', '0.5.0', true],
            ['1.0.0', '0.9.9', true],
            ['0.5.10', '0.5.9', true],
            ['v0.6.0', '0.5.0', true],
            ["0.6.0\n", '0.5.0', true],
            ['0.5.0', '0.5.0', false],
            ['0.4.9', '0.5.0', false],
            ['0.5.0-rc1', '0.5.0', false],
            ['0.5.0', '0.5.0-rc1', true],
            ['not-a-version', '0.5.0', false],
            ['', '0.5.0', false],
            ['0.5', '0.5.0', false],
            ['<html>0.9.9</html>', '0.5.0', false],
        ];
    }

    #[DataProvider('comparisons')]
    public function testIsNewer(string $candidate, string $current, bool $expected): void
    {
        self::assertSame($expected, CoreUpdateCheckService::isNewer($candidate, $current));
    }

    public function testNormaliseStripsDecorationAndRejectsNonVersions(): void
    {
        self::assertSame('1.2.3', CoreUpdateCheckService::normalise("  v1.2.3\n"));
        self::assertSame('1.2.3-beta.1', CoreUpdateCheckService::normalise('1.2.3-beta.1'));
        self::assertSame('1.2.3+build5', CoreUpdateCheckService::normalise('1.2.3+build5'));
        self::assertNull(CoreUpdateCheckService::normalise(''));
        self::assertNull(CoreUpdateCheckService::normalise('   '));
        self::assertNull(CoreUpdateCheckService::normalise('1.2'));
        self::assertNull(CoreUpdateCheckService::normalise('latest'));
    }

    /**
     * The status read path must not issue writes — it is called from an HTTP
     * handler on every admin page load.
     */
    public function testStatusPerformsOnlyReads(): void
    {
        $service = $this->service($this->fetcher('0.9.9'));
        $service->check();

        $this->db->statements = [];
        $service->status();

        foreach ($this->db->statements as $statement) {
            self::assertStringNotContainsString('INSERT', $statement);
            self::assertStringNotContainsString('UPDATE', $statement);
        }
        self::assertNotSame([], $this->db->statements, 'status() must actually read the store');
    }

    /**
     * A completion callback that throws must not escape into the caller (in
     * production that caller is a Workerman timer tick).
     */
    public function testAThrowingCompletionCallbackIsContained(): void
    {
        $service = $this->service($this->fetcher('0.9.9'));

        $service->check(static function (CoreUpdateStatus $status): void {
            throw new \RuntimeException('boom ' . $status->currentVersion);
        });

        self::assertSame('0.9.9', $service->status()->latestVersion);
    }

    // ------------------------------------------------------------------ S308

    /**
     * The due-gate, stated directly. A never-checked install is due; one that
     * checked a moment ago is not; one that checked longer ago than the
     * interval is.
     */
    public function testIsDueReflectsThePersistedLastCheckedAt(): void
    {
        $service = $this->service($this->fetcher('0.9.9'));
        self::assertTrue($service->isDue(3600), 'a hub that has never checked is always due');

        $service->check();
        self::assertFalse($service->isDue(3600), 'a check a moment ago is not due again');
        self::assertTrue($service->isDue(0), 'a zero interval means always due');

        $this->seedLastCheckedAt(3601);
        self::assertTrue($service->isDue(3600), 'past the interval it is due again');
    }

    /**
     * A clock that moved BACKWARDS must not silence the check for years. A
     * stored timestamp in the future is treated as due.
     */
    public function testAFutureLastCheckedAtIsTreatedAsDue(): void
    {
        $service = $this->service($this->fetcher('0.9.9'));
        $this->seedLastCheckedAt(-86400 * 365);

        self::assertTrue($service->isDue(3600));
    }

    /**
     * `checkIfDue()` is what the sweep calls: it must gate the FETCH, and it
     * must still hand the caller a status either way.
     */
    public function testCheckIfDueGatesTheFetchAndAlwaysCompletes(): void
    {
        $fetcher = $this->fetcher('0.9.9');
        $service = $this->service($fetcher);

        $seen = [];
        $onDone = static function (CoreUpdateStatus $status) use (&$seen): void {
            $seen[] = $status;
        };

        self::assertTrue($service->checkIfDue(3600, $onDone));
        self::assertSame(1, $fetcher->calls);

        self::assertFalse($service->checkIfDue(3600, $onDone), 'inside the interval nothing is due');
        self::assertSame(1, $fetcher->calls, 'a not-due sweep must not reach the network');

        self::assertCount(2, $seen, 'the caller is completed whether or not a fetch happened');
        self::assertSame('0.9.9', $seen[1]->latestVersion);
    }

    /**
     * SINGLE FLIGHT. The measured worst case is a transport that never calls
     * back at all; without this guard a 60-second sweep would start a new one
     * every minute for the life of the process.
     */
    public function testASecondCheckWhileOneIsInFlightIsRefused(): void
    {
        $fetcher = $this->silentFetcher();
        $service = $this->service($fetcher);

        $service->check();
        self::assertSame(1, $fetcher->calls);

        $service->check();
        $service->checkIfDue(0);

        self::assertSame(
            1,
            $fetcher->calls,
            'a check must not be issued while one is outstanding',
        );
    }

    /**
     * THE DEADLINE, arm 1: a real one-shot `Timer` task is registered when the
     * fetch is issued — read out of `Timer::$tasks`, i.e. what production
     * handed Workerman.
     */
    public function testAnInFlightCheckArmsAOneShotDeadlineTimer(): void
    {
        $before = $this->pendingTimerTaskCount();

        $this->service($this->silentFetcher(), '0.5.0', 20)->check();

        $tasks = $this->pendingTimerTasks();
        self::assertCount($before + 1, $tasks, 'the fetch must arm exactly one deadline task');
        self::assertSame(20.0, (float) $tasks[0][3], 'armed at the configured deadline');
        self::assertFalse($tasks[0][2], 'the deadline must be ONE-SHOT, not a repeating timer');
    }

    /**
     * THE DEADLINE, arm 2: firing the callback Workerman holds records a
     * timeout — so `last_checked_at` advances (the due-gate stops re-firing)
     * and the single-flight guard is released.
     */
    public function testFiringTheDeadlineRecordsATimeoutAndReleasesTheGuard(): void
    {
        $fetcher = $this->silentFetcher();
        $service = $this->service($fetcher, '0.5.0', 20);

        $service->check();
        self::assertNull($service->status()->lastCheckedAt, 'nothing recorded while in flight');

        foreach ($this->pendingTimerTasks() as $task) {
            ($task[0])(...$task[1]);
        }

        $status = $service->status();
        self::assertIsString($status->lastError);
        self::assertStringContainsString('no response within 20 seconds', $status->lastError);
        self::assertIsInt($status->lastCheckedAt);

        // The guard is released: a due check fetches again.
        $this->seedLastCheckedAt(86401);
        $service->checkIfDue(86400);
        self::assertSame(2, $fetcher->calls, 'the timeout must free the single-flight guard');
    }

    /**
     * A completed fetch must CANCEL its deadline: a stray one-shot left in the
     * table would later record a bogus timeout over a good result.
     */
    public function testACompletedFetchCancelsItsDeadline(): void
    {
        $before = $this->pendingTimerTaskCount();

        $service = $this->service($this->fetcher('0.9.9'), '0.5.0', 20);
        $service->check();

        self::assertSame($before, $this->pendingTimerTaskCount(), 'the deadline must be cancelled');
        self::assertSame('0.9.9', $service->status()->latestVersion);
    }

    /**
     * A deadline of zero disables the timer entirely — an operator escape hatch
     * that must not silently arm a `Timer::add(0)`.
     */
    public function testAZeroDeadlineArmsNoTimer(): void
    {
        $before = $this->pendingTimerTaskCount();

        $this->service($this->silentFetcher(), '0.5.0', 0)->check();

        self::assertSame($before, $this->pendingTimerTaskCount());
    }

    /**
     * LOGGED ONCE. The same failure repeating (an air-gapped install answers
     * identically every day, forever) must be escalated once and then demoted,
     * and a success must re-arm the escalation.
     */
    public function testAnIdenticalRepeatedFailureIsLoggedOnceThenDemoted(): void
    {
        $logFile = $this->tmpDir . '/dedupe.log';

        $failing = new CoreUpdateCheckService(
            new HubSettingsRepository($this->db, $this->tmpDir),
            $this->fetcher(null, 'connection refused'),
            $this->fileLogger($logFile),
            self::MARKER_URL,
            self::UPDATE_COMMAND,
            '0.5.0',
            0,
        );

        $failing->check();
        $failing->check();
        $failing->check();

        self::assertSame(
            ['WARNING', 'DEBUG', 'DEBUG'],
            $this->loggedLevelsFor($logFile, 'core update check failed'),
            'the first failure warns; identical repeats drop to debug',
        );
    }

    /**
     * The mirror image, two ways round: a DIFFERENT error text is a different
     * condition and must be escalated on its own, and a SUCCESS must re-arm the
     * escalation so the next failure is not silently demoted forever.
     */
    public function testADifferentFailureAndAFailureAfterASuccessAreEscalatedAgain(): void
    {
        $logFile = $this->tmpDir . '/escalate.log';

        // One service instance, a scripted sequence of outcomes: the dedupe key
        // is the error text, and a success clears it.
        $scripted = new class implements VersionMarkerFetcherInterface {
            /** @var list<array{0: string|null, 1: string|null}> */
            public array $script = [
                [null, 'connection refused'],
                [null, 'connection refused'],
                [null, 'host unreachable'],
                ['0.9.9', null],
                [null, 'host unreachable'],
            ];

            public function fetch(string $url, callable $onDone): void
            {
                $next = array_shift($this->script) ?? [null, 'script exhausted'];
                $onDone($next[0], $next[1]);
            }
        };

        $service = new CoreUpdateCheckService(
            new HubSettingsRepository($this->db, $this->tmpDir),
            $scripted,
            $this->fileLogger($logFile),
            self::MARKER_URL,
            self::UPDATE_COMMAND,
            '0.5.0',
            0,
        );

        for ($i = 0; $i < 5; $i++) {
            $service->check();
        }

        self::assertSame(
            ['WARNING', 'DEBUG', 'WARNING', 'WARNING'],
            $this->loggedLevelsFor($logFile, 'core update check failed'),
            'a repeat is demoted; a different error re-escalates; and a success '
            . 're-arms the warning for the failure after it',
        );
    }

    /** A real {@see StructuredLogger} writing to `$path`, at debug level. */
    private function fileLogger(string $path): StructuredLogger
    {
        return new StructuredLogger('hub', [
            'default'  => 'file',
            'handlers' => ['file' => ['type' => 'stream', 'path' => $path, 'level' => 'debug']],
        ]);
    }

    /**
     * The Monolog level of every line in `$path` whose message contains
     * `$needle`, in order.
     *
     * @return list<string>
     */
    private function loggedLevelsFor(string $path, string $needle): array
    {
        $contents = is_file($path) ? (string) file_get_contents($path) : '';
        $levels   = [];
        foreach (explode("\n", $contents) as $line) {
            if ($line === '' || !str_contains($line, $needle)) {
                continue;
            }
            foreach (['WARNING', 'DEBUG', 'INFO', 'ERROR'] as $level) {
                if (str_contains($line, 'hub.' . $level . ':')) {
                    $levels[] = $level;
                    break;
                }
            }
        }

        return $levels;
    }

    /**
     * Pretend the last completed check happened `$secondsAgo` seconds ago, by
     * writing the very row {@see CoreUpdateCheckService::isDue()} reads. A
     * negative value puts it in the FUTURE.
     */
    private function seedLastCheckedAt(int $secondsAgo): void
    {
        (new HubSettingsRepository($this->db, $this->tmpDir))->set(
            CoreUpdateCheckService::STATE_LAST_CHECKED_AT,
            time() - $secondsAgo,
            'int',
        );
    }

    /**
     * Every `[callable, args, persistent, interval]` tuple currently queued in
     * `Timer::$tasks`.
     *
     * @return list<array{0: callable, 1: array<int, mixed>, 2: bool, 3: float|int}>
     */
    private function pendingTimerTasks(): array
    {
        $property = (new ReflectionClass(Timer::class))->getProperty('tasks');
        $property->setAccessible(true);
        /** @var array<int, array<int, array{0: callable, 1: array<int, mixed>, 2: bool, 3: float|int}>> $tasks */
        $tasks = $property->getValue();

        $out = [];
        foreach ($tasks as $bucket) {
            foreach ($bucket as $task) {
                $out[] = $task;
            }
        }

        return $out;
    }
}
