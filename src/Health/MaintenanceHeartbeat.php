<?php

/**
 * Phlix hub component: Health.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Health;

use Throwable;

/**
 * Cross-process liveness record for the dedicated maintenance worker (S312).
 *
 * ## The failure this exists to make visible
 *
 * Measured 2026-08-10 against master @ 65763eb, `docker run --network none`:
 * the maintenance worker's DB sweeps threw `PDOException: SQLSTATE[HY000]
 * [2002] Connection refused`, the throw escaped the Workerman timer callback,
 * and the worker was killed and re-forked **every 61 seconds** — the container
 * master's process `etime` reached 4:41 while the maintenance worker's was
 * 0:39. Throughout, `docker inspect` reported `healthy` and `RestartCount=0`.
 *
 * Both of those signals are structurally incapable of seeing it:
 *
 *   * `/health` is answered by the **HTTP workers**, a different fork, which
 *     were perfectly healthy the whole time;
 *   * `RestartCount` counts *container* restarts, and the container never
 *     restarted — a supervisor faithfully re-forking a dying child is
 *     invisible to it. Workerman's master does not even log the death: the
 *     child exits **0** (see {@see \Phlix\Hub\MaintenanceWorker}), and
 *     `Worker::monitorWorkersForLinux()` only logs a NON-zero status.
 *
 * So the maintenance worker has to say for itself that it is alive, in a place
 * an HTTP worker can read. That is this file: a small JSON record on disk,
 * written by the maintenance fork and read by whichever HTTP fork answers
 * `/health`.
 *
 * ## Why the record is stamped by the SWEEP and not by a timer of its own
 *
 * ⚠ A heartbeat armed on its own timer would be an **uninformative green** —
 * exactly the thing this step exists to remove. In the measured failure the
 * worker lived a full 60 seconds per incarnation, so a 15-second "I am alive"
 * timer would have stamped four fresh beats per incarnation and then four more
 * after the re-fork, and the record would never have gone stale. And a beat
 * written at *fork* time renews itself on every re-fork by construction.
 *
 * Therefore {@see recordSweep()} is called from INSIDE the guarded maintenance
 * callback, AFTER the work: the record advances only when a sweep actually ran
 * to completion. A worker that dies mid-sweep — or hangs, or never arms —
 * stamps nothing, and {@see snapshot()} reports {@see STATUS_DOWN}.
 *
 * ## Per-TASK accounting, because a per-record counter hides a stuck sweep
 *
 * Four sweeps share this record. A single top-level `consecutive_failures`
 * would be reset by whichever task happened to report last, so one permanently
 * failing sweep beside three healthy ones would read `ok` — the same shape of
 * uninformative green, one level down. Each task therefore keeps its own row,
 * and the verdict is taken over the WORST of them: the freshness test uses the
 * OLDEST `last_sweep_at`, so a task that stops sweeping is visible even while
 * the others keep ticking.
 *
 * ## Lineage, so a crash-loop cannot renew its own grace period
 *
 * `armed_at` is the start of the current *lineage*, not of the current
 * process: {@see arm()} preserves it from an existing record whose last write
 * is still fresh. A looping worker therefore keeps the ORIGINAL `armed_at`,
 * the startup grace expires, and the verdict flips. A container that has been
 * down longer than the stale window starts a new lineage, so a genuine restart
 * gets a genuine grace period.
 *
 * `incarnations` counts the distinct pids that have written the current
 * lineage. It is diagnostic only — the verdict is the staleness rule — but it
 * is the number that tells an operator "this worker has re-forked 9 times",
 * which is precisely what `RestartCount=0` refused to say.
 *
 * ## Writes: unique temp file, then rename — and MEASURED, not assumed
 *
 * 🚨 The first cut of this class used ONE temp path, `<path>.<pid>.tmp`, on the
 * theory that a pid makes it unique. It does not: the four sweeps run as four
 * COROUTINES IN THE SAME PROCESS, their `file_put_contents()` calls interleave
 * at the Swoole hook, and the control container produced a record ending
 * `…"started_at":1786408197}}1786408197}` — a longer previous write with a
 * shorter one laid over its head. `json_decode()` then failed, `read()`
 * returned null, and `/health` reported `no_heartbeat_record` → 503 in an arm
 * that was supposed to be green. The signal was honest; the writer was broken.
 *
 * So the temp name now carries a per-write sequence as well as the pid, and the
 * record is held IN MEMORY between writes: two coroutines racing the file can
 * no longer lose each other's updates, because neither re-reads it. `rename()`
 * is atomic, so a reader sees one complete version or another.
 *
 * ⚠ This depends on there being exactly ONE instance per fork. PHP-DI caches
 * `factory()` definitions, and `MaintenanceHealthWiringTest` asserts the
 * container hands out the same object. If that ever stops being true,
 * {@see recordSweep()} falls back to re-reading the file, which is correct but
 * loses concurrent updates again.
 *
 * A read that fails for any reason is reported as {@see STATUS_DOWN} with a
 * reason, never as an exception: this object sits on the `/health` path and
 * must not be able to take it down.
 *
 * @package Phlix\Hub\Health
 * @since   S312
 */
final class MaintenanceHeartbeat
{
    /** The maintenance worker completed its most recent sweeps cleanly. */
    public const string STATUS_OK = 'ok';

    /**
     * Sweeps are completing, but the work inside them is failing (the common
     * case: the database is unreachable).
     *
     * DELIBERATELY NOT a probe failure. The worker is alive, its timers are
     * armed, and it will succeed on its own the moment the dependency returns
     * — which is a different condition from "the worker is gone", and pulling
     * a reachable hub out of an HAProxy pool because its database blipped is
     * not this step's decision to make. The condition is reported in the
     * `/health` BODY where an operator and a dashboard can both see it.
     */
    public const string STATUS_DEGRADED = 'degraded';

    /**
     * No sweep completed inside the stale window: the worker is looping, hung,
     * dead, or was never armed. THIS is the probe failure.
     */
    public const string STATUS_DOWN = 'down';

    /** `config/process.php` has `maintenance.enabled = false`; there is nothing to be alive. */
    public const string STATUS_DISABLED = 'disabled';

    /**
     * Default stale window: three sweep intervals (`config/process.php`
     * `maintenance.poll_seconds` is 60).
     *
     * Three rather than two so a single skipped tick — a slow sweep, a paused
     * container, a clock nudge — is not an outage. The measured crash-loop
     * stamps NOTHING at all, so it trips this at `armed_at + 180s` regardless
     * of how generous the window is; widening it only delays the verdict, it
     * cannot mask it.
     */
    public const int DEFAULT_STALE_AFTER_SECONDS = 180;

    /** Clip for `last_error`: it is served over /health and rewritten every failing tick. */
    private const int MAX_ERROR_LENGTH = 300;

    /**
     * The record this process owns, between writes. See the class docblock:
     * re-reading the file on every {@see recordSweep()} loses updates when two
     * sweep coroutines interleave.
     *
     * @var array<string, mixed>|null
     */
    private ?array $state = null;

    /** Per-write counter, so two coroutines never share a temp filename. */
    private int $writeSequence = 0;

    /**
     * @param string $path              Absolute path of the JSON record.
     * @param int    $staleAfterSeconds Seconds without a completed sweep before the verdict is DOWN.
     * @param bool   $enabled           Whether the maintenance worker is enabled at all.
     */
    public function __construct(
        private readonly string $path,
        private readonly int $staleAfterSeconds = self::DEFAULT_STALE_AFTER_SECONDS,
        private readonly bool $enabled = true,
    ) {
    }

    /**
     * Path of the on-disk record (for diagnostics and tests).
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Whether the maintenance worker is enabled in `config/process.php`.
     */
    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Seconds without a completed sweep before the verdict flips to DOWN.
     */
    public function staleAfterSeconds(): int
    {
        return $this->staleAfterSeconds;
    }

    /**
     * Called once by the maintenance worker when it arms its timers.
     *
     * Continues the existing lineage when the record on disk is still fresh
     * (i.e. this process is a RE-FORK, not a fresh boot), which is what stops a
     * crash-loop from handing itself a new grace period every minute.
     *
     * @param int|null $pid Worker pid; defaults to the current process.
     * @param int|null $now Unix seconds; injectable for tests.
     *
     * @return void
     */
    public function arm(?int $pid = null, ?int $now = null): void
    {
        $pid ??= self::currentPid();
        $now ??= time();

        $previous = $this->read();
        $continues = $previous !== null && ($now - $this->lastWriteOf($previous)) <= $this->staleAfterSeconds;

        if ($continues) {
            /** @var array<string, mixed> $record */
            $record = $previous;
            $record['incarnations'] = $this->intOf($previous, 'incarnations')
                + ($pid === $this->intOf($previous, 'pid') ? 0 : 1);
        } else {
            $record = [
                'armed_at' => $now,
                'incarnations' => 1,
                'tasks' => [],
            ];
        }

        $record['pid'] = $pid;
        $record['started_at'] = $now;

        $this->state = $record;
        $this->write($record);
    }

    /**
     * Record the outcome of ONE completed maintenance sweep.
     *
     * Call this from inside the guarded timer callback, after the work — see
     * the class docblock for why it must not be on a timer of its own.
     *
     * @param string         $task  Sweep identifier, e.g. `idle_reaper_db`.
     * @param Throwable|null $error The failure, or null when the sweep succeeded.
     * @param int|null       $now   Unix seconds; injectable for tests.
     *
     * @return void
     */
    public function recordSweep(string $task, ?Throwable $error = null, ?int $now = null): void
    {
        $now ??= time();

        // In-memory first. Falling back to the file is correct but loses a
        // concurrent coroutine's update, so it is the exception, not the rule.
        $record = $this->state ?? $this->read() ?? [
            'armed_at' => $now,
            'incarnations' => 1,
            'tasks' => [],
            'pid' => self::currentPid(),
            'started_at' => $now,
        ];

        /** @var mixed $tasksRaw */
        $tasksRaw = $record['tasks'] ?? null;
        /** @var array<string, mixed> $tasks */
        $tasks = is_array($tasksRaw) ? $tasksRaw : [];

        /** @var mixed $rowRaw */
        $rowRaw = $tasks[$task] ?? null;
        /** @var array<string, mixed> $row */
        $row = is_array($rowRaw) ? $rowRaw : [];

        $row['last_sweep_at'] = $now;
        if ($error === null) {
            $row['last_success_at'] = $now;
            $row['consecutive_failures'] = 0;
            $row['last_error'] = null;
        } else {
            $row['consecutive_failures'] = $this->intOf($row, 'consecutive_failures') + 1;
            $row['last_error'] = substr($error::class . ': ' . $error->getMessage(), 0, self::MAX_ERROR_LENGTH);
        }

        $tasks[$task] = $row;
        $record['tasks'] = $tasks;

        $this->state = $record;
        $this->write($record);
    }

    /**
     * The verdict, for {@see HealthController}.
     *
     * Never throws: an unreadable, absent or malformed record is DOWN with a
     * reason, because this runs on the `/health` path. It reads from DISK on
     * purpose — the caller is an HTTP fork, which never writes.
     *
     * @param int|null $now Unix seconds; injectable for tests.
     *
     * @return array{
     *     status: string,
     *     reason: string,
     *     task: string|null,
     *     pid: int|null,
     *     incarnations: int|null,
     *     age_seconds: int|null,
     *     consecutive_failures: int|null,
     *     last_error: string|null,
     *     stale_after_seconds: int
     * }
     */
    public function snapshot(?int $now = null): array
    {
        $now ??= time();

        if (!$this->enabled) {
            return $this->verdict(self::STATUS_DISABLED, 'maintenance_worker_disabled', null, null, null);
        }

        $record = $this->read();
        if ($record === null) {
            return $this->verdict(self::STATUS_DOWN, 'no_heartbeat_record', null, null, null);
        }

        $pid = $this->intOf($record, 'pid');
        $incarnations = $this->intOf($record, 'incarnations');

        /** @var mixed $tasksRaw */
        $tasksRaw = $record['tasks'] ?? null;
        /** @var array<string, mixed> $tasks */
        $tasks = is_array($tasksRaw) ? $tasksRaw : [];

        // No sweep has EVER completed in this lineage. Legitimate for the first
        // interval after boot; a crash-loop never leaves this branch, and
        // because `armed_at` is lineage-scoped it cannot renew the grace.
        if ($tasks === []) {
            $age = $now - $this->intOf($record, 'armed_at');
            if ($age > $this->staleAfterSeconds) {
                return $this->verdict(self::STATUS_DOWN, 'no_sweep_completed', null, $pid, $incarnations, $age);
            }

            return $this->verdict(self::STATUS_OK, 'starting_up', null, $pid, $incarnations, $age);
        }

        // The verdict is taken over the WORST task, never an average and never
        // the last writer: one permanently stuck sweep beside three healthy
        // ones must not read as healthy.
        // PHP_INT_MAX rather than null: `$tasks` is non-empty here (checked
        // above), so the first iteration always lowers it, and keeping it a
        // plain int spares the rest of the method a nullable both analysers
        // then argue about in opposite directions.
        $stalestTask = null;
        $stalestAt = PHP_INT_MAX;
        $worstTask = null;
        $worstFailures = 0;
        $worstError = null;

        /** @var mixed $rowRaw */
        foreach ($tasks as $name => $rowRaw) {
            /** @var array<string, mixed> $row */
            $row = is_array($rowRaw) ? $rowRaw : [];
            $sweptAt = $this->intOf($row, 'last_sweep_at');
            if ($sweptAt < $stalestAt) {
                $stalestAt = $sweptAt;
                $stalestTask = $name;
            }
            $failures = $this->intOf($row, 'consecutive_failures');
            if ($failures > $worstFailures) {
                $worstFailures = $failures;
                $worstTask = $name;
                /** @var mixed $err */
                $err = $row['last_error'] ?? null;
                $worstError = is_string($err) ? $err : null;
            }
        }

        $age = $now - $stalestAt;
        if ($age > $this->staleAfterSeconds) {
            return $this->verdict(
                self::STATUS_DOWN,
                'stale_sweep',
                $stalestTask,
                $pid,
                $incarnations,
                $age,
                $worstFailures,
                $worstError,
            );
        }

        if ($worstFailures > 0) {
            return $this->verdict(
                self::STATUS_DEGRADED,
                'sweep_failing',
                $worstTask,
                $pid,
                $incarnations,
                $age,
                $worstFailures,
                $worstError,
            );
        }

        return $this->verdict(self::STATUS_OK, 'sweeping', null, $pid, $incarnations, $age, 0, null);
    }

    /**
     * Whether {@see snapshot()} is a PROBE FAILURE — i.e. whether `/health`
     * must answer non-200 and the container must stop reporting `healthy`.
     *
     * Only {@see STATUS_DOWN} qualifies; see the {@see STATUS_DEGRADED}
     * docblock for why a failing-but-alive sweep deliberately does not.
     *
     * @param array{status: string, ...} $snapshot A {@see snapshot()} result.
     */
    public static function isProbeFailure(array $snapshot): bool
    {
        return $snapshot['status'] === self::STATUS_DOWN;
    }

    /**
     * Read and decode the record, or null when it is absent/unusable.
     *
     * @return array<string, mixed>|null
     */
    private function read(): ?array
    {
        if (!is_file($this->path) || !is_readable($this->path)) {
            return null;
        }

        $raw = @file_get_contents($this->path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Atomic write: UNIQUE temp sibling + rename.
     *
     * The uniqueness is the part that was measured wrong once — see the class
     * docblock. A pid alone is not unique across the coroutines of one process.
     *
     * Silently gives up when the directory is not writable. A hub whose var/
     * directory is read-only should not have its maintenance sweeps start
     * throwing because of it — the resulting absent record already reads as
     * DOWN, which is the honest answer.
     *
     * @param array<string, mixed> $record Record to persist.
     */
    private function write(array $record): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $json = json_encode($record, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return;
        }

        $tmp = $this->tempPath();
        if (@file_put_contents($tmp, $json) === false) {
            return;
        }
        if (!@rename($tmp, $this->path)) {
            @unlink($tmp);
        }
    }

    /**
     * A temp path no concurrent writer can be using.
     *
     * Pid AND a per-instance sequence: the pid separates forks, the sequence
     * separates the coroutines within one fork, which is the case that actually
     * corrupted the record in a measured container run.
     */
    private function tempPath(): string
    {
        $this->writeSequence++;

        return sprintf('%s.%d.%d.tmp', $this->path, self::currentPid(), $this->writeSequence);
    }

    /**
     * Current pid, or 0 where the platform will not say.
     */
    private static function currentPid(): int
    {
        $pid = getmypid();

        return $pid === false ? 0 : $pid;
    }

    /**
     * The most recent moment this record was touched by any writer.
     *
     * @param array<string, mixed> $record Decoded record.
     */
    private function lastWriteOf(array $record): int
    {
        $latest = $this->intOf($record, 'started_at');

        /** @var mixed $tasksRaw */
        $tasksRaw = $record['tasks'] ?? null;
        if (is_array($tasksRaw)) {
            /** @var mixed $rowRaw */
            foreach ($tasksRaw as $rowRaw) {
                /** @var array<string, mixed> $row */
                $row = is_array($rowRaw) ? $rowRaw : [];
                $latest = max($latest, $this->intOf($row, 'last_sweep_at'));
            }
        }

        return $latest;
    }

    /**
     * @param array<string, mixed> $record Decoded record.
     * @param string               $key    Field name.
     */
    private function intOf(array $record, string $key): int
    {
        /** @var mixed $value */
        $value = $record[$key] ?? null;

        return is_int($value) ? $value : 0;
    }

    /**
     * @return array{
     *     status: string,
     *     reason: string,
     *     task: string|null,
     *     pid: int|null,
     *     incarnations: int|null,
     *     age_seconds: int|null,
     *     consecutive_failures: int|null,
     *     last_error: string|null,
     *     stale_after_seconds: int
     * }
     */
    private function verdict(
        string $status,
        string $reason,
        ?string $task,
        ?int $pid,
        ?int $incarnations,
        ?int $ageSeconds = null,
        ?int $failures = null,
        ?string $lastError = null,
    ): array {
        return [
            'status' => $status,
            'reason' => $reason,
            'task' => $task,
            'pid' => $pid,
            'incarnations' => $incarnations,
            'age_seconds' => $ageSeconds,
            'consecutive_failures' => $failures,
            'last_error' => $lastError,
            'stale_after_seconds' => $this->staleAfterSeconds,
        ];
    }
}
