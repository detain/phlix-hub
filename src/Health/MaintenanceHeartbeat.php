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
 * and the worker was killed and re-forked **every 60 seconds** — the container
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
 * is the number that tells an operator "this worker has re-forked 47 times",
 * which is precisely what `RestartCount=0` refused to say.
 *
 * ## Writes are atomic
 *
 * Write to a sibling temp file then `rename()`, so an HTTP worker reading
 * concurrently sees either the previous record or the next one, never a
 * half-written one. A read that fails for any reason is reported as
 * {@see STATUS_DOWN} with a reason, never as an exception: this object sits on
 * the `/health` path and must not be able to take it down.
 *
 * @package Phlix\Hub\Health
 * @since   S312
 */
final class MaintenanceHeartbeat
{
    /** The maintenance worker completed its most recent sweep cleanly. */
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
     * No sweep has completed inside the stale window: the worker is looping,
     * hung, dead, or was never armed. THIS is the probe failure.
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
        $pid ??= getmypid() === false ? 0 : (int) getmypid();
        $now ??= time();

        $previous = $this->read();
        $continues = $previous !== null && ($now - $this->lastWriteOf($previous)) <= $this->staleAfterSeconds;

        if ($continues) {
            /** @var array<string, mixed> $previous */
            $record = $previous;
            $record['incarnations'] = $this->intOf($previous, 'incarnations') + ($pid === $this->intOf($previous, 'pid') ? 0 : 1);
        } else {
            $record = [
                'armed_at' => $now,
                'incarnations' => 1,
                'last_sweep_at' => null,
                'last_success_at' => null,
                'consecutive_failures' => 0,
                'last_error' => null,
                'last_failed_task' => null,
            ];
        }

        $record['pid'] = $pid;
        $record['started_at'] = $now;

        $this->write($record);
    }

    /**
     * Record the outcome of ONE completed maintenance sweep.
     *
     * Call this from inside the guarded timer callback, after the work — see
     * the class docblock for why it must not be on a timer of its own.
     *
     * @param string    $task  Sweep identifier, e.g. `idle_reaper_db`.
     * @param Throwable $error The failure, or null when the sweep succeeded.
     * @param int|null  $now   Unix seconds; injectable for tests.
     *
     * @return void
     */
    public function recordSweep(string $task, ?Throwable $error = null, ?int $now = null): void
    {
        $now ??= time();

        $record = $this->read() ?? [
            'armed_at' => $now,
            'incarnations' => 1,
            'last_sweep_at' => null,
            'last_success_at' => null,
            'consecutive_failures' => 0,
            'last_error' => null,
            'last_failed_task' => null,
            'pid' => getmypid() === false ? 0 : (int) getmypid(),
            'started_at' => $now,
        ];

        $record['last_sweep_at'] = $now;

        if ($error === null) {
            $record['last_success_at'] = $now;
            $record['consecutive_failures'] = 0;
            $record['last_error'] = null;
            $record['last_failed_task'] = null;
        } else {
            $record['consecutive_failures'] = $this->intOf($record, 'consecutive_failures') + 1;
            // Clipped: this string is served over /health and written on every
            // failing tick. A driver message can carry a DSN, and an unbounded
            // one would grow the record without bound.
            $record['last_error'] = substr($error::class . ': ' . $error->getMessage(), 0, 300);
            $record['last_failed_task'] = $task;
        }

        $this->write($record);
    }

    /**
     * The verdict, for {@see HealthController}.
     *
     * Never throws: an unreadable, absent or malformed record is DOWN with a
     * reason, because this runs on the `/health` path.
     *
     * @param int|null $now Unix seconds; injectable for tests.
     *
     * @return array{
     *     status: string,
     *     reason: string,
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
            return $this->verdict(self::STATUS_DISABLED, 'maintenance_worker_disabled', null, null);
        }

        $record = $this->read();
        if ($record === null) {
            return $this->verdict(self::STATUS_DOWN, 'no_heartbeat_record', null, null);
        }

        $pid = $this->intOf($record, 'pid');
        $incarnations = $this->intOf($record, 'incarnations');
        $failures = $this->intOf($record, 'consecutive_failures');
        /** @var mixed $lastErrorRaw */
        $lastErrorRaw = $record['last_error'] ?? null;
        $lastError = is_string($lastErrorRaw) ? $lastErrorRaw : null;

        /** @var mixed $lastSweepRaw */
        $lastSweepRaw = $record['last_sweep_at'] ?? null;
        $lastSweep = is_int($lastSweepRaw) ? $lastSweepRaw : null;

        // No sweep has EVER completed in this lineage. Legitimate for the first
        // interval after boot; a crash-loop never leaves this branch, and
        // because `armed_at` is lineage-scoped it cannot renew the grace.
        if ($lastSweep === null) {
            $age = $now - $this->intOf($record, 'armed_at');
            if ($age > $this->staleAfterSeconds) {
                return $this->verdict(
                    self::STATUS_DOWN,
                    'no_sweep_completed',
                    $pid,
                    $incarnations,
                    $age,
                    $failures,
                    $lastError,
                );
            }

            return $this->verdict(self::STATUS_OK, 'starting_up', $pid, $incarnations, $age, $failures, $lastError);
        }

        $age = $now - $lastSweep;
        if ($age > $this->staleAfterSeconds) {
            return $this->verdict(self::STATUS_DOWN, 'stale_sweep', $pid, $incarnations, $age, $failures, $lastError);
        }

        if ($failures > 0) {
            return $this->verdict(
                self::STATUS_DEGRADED,
                'sweep_failing',
                $pid,
                $incarnations,
                $age,
                $failures,
                $lastError,
            );
        }

        return $this->verdict(self::STATUS_OK, 'sweeping', $pid, $incarnations, $age, $failures, $lastError);
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
     * Atomic write: temp sibling + rename, so a concurrent reader on an HTTP
     * worker never observes a partial record.
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

        $tmp = $this->path . '.' . (getmypid() === false ? '0' : (string) getmypid()) . '.tmp';
        if (@file_put_contents($tmp, $json) === false) {
            return;
        }
        if (!@rename($tmp, $this->path)) {
            @unlink($tmp);
        }
    }

    /**
     * The most recent moment this record was touched by any writer.
     *
     * @param array<string, mixed> $record Decoded record.
     */
    private function lastWriteOf(array $record): int
    {
        return max($this->intOf($record, 'started_at'), $this->intOf($record, 'last_sweep_at'));
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
        ?int $pid,
        ?int $incarnations,
        ?int $ageSeconds = null,
        ?int $failures = null,
        ?string $lastError = null,
    ): array {
        return [
            'status' => $status,
            'reason' => $reason,
            'pid' => $pid,
            'incarnations' => $incarnations,
            'age_seconds' => $ageSeconds,
            'consecutive_failures' => $failures,
            'last_error' => $lastError,
            'stale_after_seconds' => $this->staleAfterSeconds,
        ];
    }
}
