<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Health;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\Health\MaintenanceHeartbeat;
use RuntimeException;

/**
 * Unit tests for {@see MaintenanceHeartbeat} — the S312 cross-process liveness
 * record for the dedicated maintenance worker.
 *
 * Every test here is written against the MEASURED failure, not against the
 * class's own shape: on master, under `docker run --network none`, the
 * maintenance worker was killed and re-forked every 60 seconds while `/health`
 * answered `{"status":"ok"}` and `docker inspect` reported `healthy` with
 * `RestartCount=0`. The two properties that make this record able to say so —
 * that a sweep, not a beat, advances it, and that a re-fork cannot renew the
 * startup grace — each have a test whose control is the same scenario one step
 * away from failing.
 *
 * No `@covers` annotation on purpose: in this repository that annotation
 * DISCARDS every other file's coverage from the test's contribution, and one
 * step has already been mis-filed off a `0.00%` that meant "not attributed".
 *
 * @package Phlix\Hub\Tests\Health
 */
final class MaintenanceHeartbeatTest extends TestCase
{
    private string $dir;
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/phlix-hub-s312-' . uniqid('', true);
        mkdir($this->dir, 0700, true);
        $this->path = $this->dir . '/maintenance-heartbeat.json';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function heartbeat(int $staleAfter = 180, bool $enabled = true): MaintenanceHeartbeat
    {
        return new MaintenanceHeartbeat($this->path, $staleAfter, $enabled);
    }

    // -----------------------------------------------------------------------
    // The verdict
    // -----------------------------------------------------------------------

    public function testAbsentRecordIsDown(): void
    {
        $snapshot = $this->heartbeat()->snapshot(1_000);

        self::assertSame(MaintenanceHeartbeat::STATUS_DOWN, $snapshot['status']);
        self::assertSame('no_heartbeat_record', $snapshot['reason']);
        self::assertTrue(MaintenanceHeartbeat::isProbeFailure($snapshot));
    }

    public function testMalformedRecordIsDownRatherThanAnException(): void
    {
        file_put_contents($this->path, 'not json at all');

        $snapshot = $this->heartbeat()->snapshot(1_000);

        self::assertSame(MaintenanceHeartbeat::STATUS_DOWN, $snapshot['status']);
        self::assertSame('no_heartbeat_record', $snapshot['reason']);
    }

    public function testJustArmedIsOkInsideTheStartupGrace(): void
    {
        $hb = $this->heartbeat(180);
        $hb->arm(4242, 1_000);

        $snapshot = $hb->snapshot(1_050);

        self::assertSame(MaintenanceHeartbeat::STATUS_OK, $snapshot['status']);
        self::assertSame('starting_up', $snapshot['reason']);
        self::assertSame(4242, $snapshot['pid']);
        self::assertFalse(MaintenanceHeartbeat::isProbeFailure($snapshot));
    }

    public function testArmedButNeverSweepingGoesDownWhenTheGraceExpires(): void
    {
        $hb = $this->heartbeat(180);
        $hb->arm(4242, 1_000);

        // 179s: still inside the grace — the CONTROL for the assertion below.
        self::assertSame(MaintenanceHeartbeat::STATUS_OK, $hb->snapshot(1_179)['status']);

        $snapshot = $hb->snapshot(1_181);

        self::assertSame(MaintenanceHeartbeat::STATUS_DOWN, $snapshot['status']);
        self::assertSame('no_sweep_completed', $snapshot['reason']);
    }

    public function testCompletedSweepIsOk(): void
    {
        $hb = $this->heartbeat(180);
        $hb->arm(4242, 1_000);
        $hb->recordSweep('idle_reaper_db', null, 1_060);

        $snapshot = $hb->snapshot(1_070);

        self::assertSame(MaintenanceHeartbeat::STATUS_OK, $snapshot['status']);
        self::assertSame('sweeping', $snapshot['reason']);
        self::assertSame(10, $snapshot['age_seconds']);
        self::assertSame(0, $snapshot['consecutive_failures']);
    }

    public function testSweepThatRanButFailedIsDegradedAndNotAProbeFailure(): void
    {
        $hb = $this->heartbeat(180);
        $hb->arm(4242, 1_000);
        $hb->recordSweep('server_reaper', new RuntimeException('Connection refused'), 1_060);

        $snapshot = $hb->snapshot(1_070);

        self::assertSame(MaintenanceHeartbeat::STATUS_DEGRADED, $snapshot['status']);
        self::assertSame('sweep_failing', $snapshot['reason']);
        self::assertSame(1, $snapshot['consecutive_failures']);
        self::assertIsString($snapshot['last_error']);
        self::assertStringContainsString('Connection refused', $snapshot['last_error']);
        // The worker is ALIVE. Pulling a reachable hub out of a pool because its
        // database blipped is a different decision from S312's.
        self::assertFalse(MaintenanceHeartbeat::isProbeFailure($snapshot));
    }

    public function testASweepThatStopsHappeningGoesStale(): void
    {
        $hb = $this->heartbeat(180);
        $hb->arm(4242, 1_000);
        $hb->recordSweep('idle_reaper_db', null, 1_060);

        // 179s after the last sweep: still ok — the control.
        self::assertSame(MaintenanceHeartbeat::STATUS_OK, $hb->snapshot(1_239)['status']);

        $snapshot = $hb->snapshot(1_241);
        self::assertSame(MaintenanceHeartbeat::STATUS_DOWN, $snapshot['status']);
        self::assertSame('stale_sweep', $snapshot['reason']);
    }

    public function testSuccessAfterFailuresClearsTheDegradedState(): void
    {
        $hb = $this->heartbeat(180);
        $hb->arm(4242, 1_000);
        $hb->recordSweep('server_reaper', new RuntimeException('nope'), 1_060);
        $hb->recordSweep('server_reaper', new RuntimeException('nope'), 1_120);
        self::assertSame(2, $hb->snapshot(1_121)['consecutive_failures']);

        $hb->recordSweep('server_reaper', null, 1_180);

        $snapshot = $hb->snapshot(1_181);
        self::assertSame(MaintenanceHeartbeat::STATUS_OK, $snapshot['status']);
        self::assertSame(0, $snapshot['consecutive_failures']);
        self::assertNull($snapshot['last_error']);
    }

    // -----------------------------------------------------------------------
    // Per-task accounting — a single counter would hide a stuck sweep
    // -----------------------------------------------------------------------

    /**
     * Four sweeps share this record. With one top-level counter, whichever task
     * reported LAST would decide the verdict, so three healthy sweeps would
     * paint over one that has been failing since boot — the same uninformative
     * green as the defect this class exists to remove, one level down.
     */
    public function testOneFailingTaskIsNotMaskedByThreeHealthyOnes(): void
    {
        $hb = $this->heartbeat(180);
        $hb->arm(4242, 1_000);

        // The failing one reports FIRST, so a last-writer-wins counter would
        // have been reset by the three that follow.
        $hb->recordSweep('server_reaper', new RuntimeException('Connection refused'), 1_060);
        $hb->recordSweep('idle_reaper_db', null, 1_060);
        $hb->recordSweep('federation_session_reaper', null, 1_060);
        $hb->recordSweep('core_update_check', null, 1_060);

        $snapshot = $hb->snapshot(1_070);

        self::assertSame(MaintenanceHeartbeat::STATUS_DEGRADED, $snapshot['status']);
        self::assertSame('server_reaper', $snapshot['task']);
        self::assertSame(1, $snapshot['consecutive_failures']);
    }

    /**
     * The control for the test above: with every task succeeding, the same four
     * calls read `ok`. Without it, the assertion above would also pass against
     * a snapshot hard-wired to `degraded`.
     */
    public function testAllTasksSucceedingReadsOk(): void
    {
        $hb = $this->heartbeat(180);
        $hb->arm(4242, 1_000);
        foreach (['server_reaper', 'idle_reaper_db', 'federation_session_reaper'] as $task) {
            $hb->recordSweep($task, null, 1_060);
        }

        $snapshot = $hb->snapshot(1_070);

        self::assertSame(MaintenanceHeartbeat::STATUS_OK, $snapshot['status']);
        self::assertNull($snapshot['task']);
    }

    /**
     * Freshness is taken over the OLDEST task, so a sweep that quietly stops
     * ticking is visible even while its siblings keep reporting.
     */
    public function testOneTaskThatStopsSweepingGoesStaleDespiteHealthySiblings(): void
    {
        $hb = $this->heartbeat(180);
        $hb->arm(4242, 1_000);
        $hb->recordSweep('server_reaper', null, 1_060);
        $hb->recordSweep('idle_reaper_db', null, 1_060);

        // `idle_reaper_db` keeps going; `server_reaper` never reports again.
        for ($t = 1_120; $t <= 1_300; $t += 60) {
            $hb->recordSweep('idle_reaper_db', null, $t);
        }

        // Control: at 179s past the last server_reaper sweep, still ok.
        self::assertSame(MaintenanceHeartbeat::STATUS_OK, $hb->snapshot(1_239)['status']);

        $snapshot = $hb->snapshot(1_241);
        self::assertSame(MaintenanceHeartbeat::STATUS_DOWN, $snapshot['status']);
        self::assertSame('stale_sweep', $snapshot['reason']);
        self::assertSame('server_reaper', $snapshot['task']);
    }

    // -----------------------------------------------------------------------
    // The measured writer defect (see the class docblock)
    // -----------------------------------------------------------------------

    /**
     * 🚨 REGRESSION for a defect this implementation actually shipped and a
     * container run caught.
     *
     * The first cut re-read the record from disk on every `recordSweep()` and
     * wrote through a temp path keyed only by pid. The four sweeps are four
     * COROUTINES IN ONE PROCESS, so their writes interleaved and the control
     * container's record ended `…"started_at":1786408197}}1786408197}`.
     * `json_decode()` failed, `read()` returned null, and `/health` answered
     * 503 `no_heartbeat_record` in the arm that was supposed to be green.
     *
     * ⚠ PHPUnit cannot enter a coroutine, so this cannot reproduce the
     * interleaving. It pins the two properties that fix it instead: the record
     * is held in memory, so a clobbered file does not reset the counters; and
     * every write gets its own temp name.
     */
    public function testTheRecordIsHeldInMemorySoAClobberedFileDoesNotResetCounters(): void
    {
        $hb = $this->heartbeat(180);
        $hb->arm(4242, 1_000);
        $hb->recordSweep('server_reaper', new RuntimeException('one'), 1_060);

        // Simulate the interleaved write that corrupted the container's record.
        file_put_contents($this->path, '{"armed_at":1,"tasks":{}}}1786408197}');

        $hb->recordSweep('server_reaper', new RuntimeException('two'), 1_120);

        $snapshot = $hb->snapshot(1_130);
        self::assertSame(
            2,
            $snapshot['consecutive_failures'],
            'a re-read from a clobbered file would have restarted the count at 1',
        );
        self::assertSame(4242, $snapshot['pid'], 'the lineage must survive the clobber too');
        self::assertSame(MaintenanceHeartbeat::STATUS_DEGRADED, $snapshot['status']);
    }

    public function testEveryWriteUsesADistinctTempPath(): void
    {
        $hb = $this->heartbeat();
        $method = new \ReflectionMethod(MaintenanceHeartbeat::class, 'tempPath');

        $paths = [];
        for ($i = 0; $i < 8; $i++) {
            $paths[] = (string) $method->invoke($hb);
        }

        self::assertCount(8, array_unique($paths), 'a shared temp name is what corrupted the record');
        foreach ($paths as $p) {
            self::assertStringStartsWith($this->path . '.', $p);
            self::assertStringEndsWith('.tmp', $p);
        }
    }

    /**
     * The falsifying control for the test above: the ORIGINAL, defective naming
     * scheme — path + pid only — produces the SAME string every time. Without
     * this, `assertCount(8, array_unique(...))` would look like a strong
     * assertion about a property that any implementation happens to satisfy.
     */
    public function testTheOriginalPidOnlyTempNameWouldHaveCollided(): void
    {
        $defective = [];
        for ($i = 0; $i < 8; $i++) {
            $defective[] = $this->path . '.' . (string) getmypid() . '.tmp';
        }

        self::assertCount(1, array_unique($defective));
    }

    public function testDisabledWorkerIsNotAProbeFailure(): void
    {
        $snapshot = $this->heartbeat(180, false)->snapshot(1_000);

        self::assertSame(MaintenanceHeartbeat::STATUS_DISABLED, $snapshot['status']);
        self::assertFalse(MaintenanceHeartbeat::isProbeFailure($snapshot));
    }

    // -----------------------------------------------------------------------
    // Lineage — the property that makes a crash-loop detectable at all
    // -----------------------------------------------------------------------

    /**
     * THE regression test for the measured defect.
     *
     * Replays the master behaviour exactly: a worker that arms, never completes
     * a sweep, dies, and is re-forked every 60 seconds. Each re-fork calls
     * `arm()` again. If `armed_at` were per-process the grace would be renewed
     * on every fork and the verdict would be `ok` for ever — an uninformative
     * green, which is the whole failure S312 exists to remove.
     */
    public function testACrashLoopingWorkerCannotRenewItsOwnStartupGrace(): void
    {
        $hb = $this->heartbeat(180);

        $now = 1_000;
        $pid = 100;
        $hb->arm($pid, $now);

        // Five re-forks at the measured 60-second cadence.
        for ($i = 0; $i < 5; $i++) {
            $now += 60;
            $pid += 93; // the measured pid stride between incarnations
            $hb->arm($pid, $now);
        }

        $snapshot = $hb->snapshot($now + 1);

        self::assertSame(MaintenanceHeartbeat::STATUS_DOWN, $snapshot['status']);
        self::assertSame('no_sweep_completed', $snapshot['reason']);
        self::assertSame(6, $snapshot['incarnations'], 'every re-fork must be counted');
        self::assertSame($pid, $snapshot['pid']);
    }

    /**
     * The control for the test above: the same re-arm, but far enough apart
     * that it is a genuine restart rather than a loop. A restart must get a
     * genuine grace period, or every deploy would flap the probe.
     */
    public function testARestartAfterTheStaleWindowStartsAFreshLineage(): void
    {
        $hb = $this->heartbeat(180);
        $hb->arm(100, 1_000);

        $hb->arm(200, 1_000 + 181);

        $snapshot = $hb->snapshot(1_000 + 182);
        self::assertSame(MaintenanceHeartbeat::STATUS_OK, $snapshot['status']);
        self::assertSame('starting_up', $snapshot['reason']);
        self::assertSame(1, $snapshot['incarnations'], 'a fresh lineage restarts the counter');
    }

    public function testRearmingWithTheSamePidDoesNotCountAnIncarnation(): void
    {
        $hb = $this->heartbeat(180);
        $hb->arm(100, 1_000);
        $hb->arm(100, 1_030);

        self::assertSame(1, $hb->snapshot(1_031)['incarnations']);
    }

    public function testASweepSurvivesARefork(): void
    {
        $hb = $this->heartbeat(180);
        $hb->arm(100, 1_000);
        $hb->recordSweep('idle_reaper_db', null, 1_060);

        // A new incarnation continues the same lineage and inherits the sweep.
        $hb->arm(193, 1_090);

        $snapshot = $hb->snapshot(1_100);
        self::assertSame(2, $snapshot['incarnations']);
        self::assertSame(MaintenanceHeartbeat::STATUS_OK, $snapshot['status']);
        self::assertSame('sweeping', $snapshot['reason']);
    }

    // -----------------------------------------------------------------------
    // Robustness — this object sits on the /health path
    // -----------------------------------------------------------------------

    public function testWriteIsAtomicSoNoReaderSeesAPartialRecord(): void
    {
        $hb = $this->heartbeat();
        $hb->arm(4242, 1_000);
        $hb->recordSweep('idle_reaper_db', null, 1_060);

        $leftovers = array_values(array_filter(
            glob($this->dir . '/*') ?: [],
            static fn (string $f): bool => str_ends_with($f, '.tmp'),
        ));

        self::assertSame([], $leftovers, 'the temp sibling must be renamed away, not left behind');
        self::assertIsArray(json_decode((string) file_get_contents($this->path), true));
    }

    public function testAnUnwritableDirectoryDoesNotThrow(): void
    {
        $hb = new MaintenanceHeartbeat('/proc/s312-cannot-exist/heartbeat.json', 180, true);

        $hb->arm(1, 1_000);
        $hb->recordSweep('idle_reaper_db', null, 1_060);

        // An absent record reads as DOWN — the honest answer — and nothing threw.
        self::assertSame(MaintenanceHeartbeat::STATUS_DOWN, $hb->snapshot(1_070)['status']);
    }

    public function testLastErrorIsClipped(): void
    {
        $hb = $this->heartbeat();
        $hb->arm(1, 1_000);
        $hb->recordSweep('t', new RuntimeException(str_repeat('x', 5_000)), 1_010);

        $lastError = $hb->snapshot(1_011)['last_error'];
        self::assertIsString($lastError);
        self::assertSame(300, strlen($lastError));
    }

    public function testAccessorsReportTheConfiguredValues(): void
    {
        $hb = new MaintenanceHeartbeat($this->path, 42, false);

        self::assertSame($this->path, $hb->path());
        self::assertSame(42, $hb->staleAfterSeconds());
        self::assertFalse($hb->enabled());
    }
}
