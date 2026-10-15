<?php

/**
 * Phlix hub component: Hub.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub;

use Phlix\Hub\Common\Logger\StructuredLogger;
use Workerman\Timer;

/**
 * Periodic maintenance task that:
 *
 *  - B2: marks servers as `offline` when they have not sent a heartbeat within
 *    the threshold AND have no live relay session.  This makes `servers.status`
 *    authoritative (the durable/last-known view for the dashboard) rather than
 *    a value that is written but never read.
 *
 *  - P2: purges heartbeat rows older than the retention window so the table
 *    does not grow unboundedly (only the latest ~20 rows are ever read, so
 *    retention can be aggressive).
 *
 *  - R19-11: purges `audit_logs` rows older than the retention window. The
 *    table is INSERT-only in every code path — before this sweeper it grew
 *    without bound forever. Same two-phase keyed delete as the heartbeat
 *    sweep, on the same timer (no second periodic worker, no new fork-time
 *    scheduling surface).
 *
 * All tasks run on the same timer. The reaper is started from
 * {@see HubServicesProvider::boot()}.
 *
 * @package Phlix\Hub\Hub
 */
final class ServerReaper
{
    /**
     * How long a server must be silent before being marked offline.
     *
     * Default 180 s = 3 × the 60 s heartbeat interval.
     *
     * @var int
     */
    public const DEFAULT_OFFLINE_THRESHOLD_SECONDS = 180;

    /**
     * Default heartbeat retention in days.
     *
     * Only the latest ~20 rows per server are ever read, so 7 days is safe.
     *
     * @var int
     */
    public const DEFAULT_HEARTBEAT_RETENTION_DAYS = 7;

    /**
     * Default interval between reaper scans in seconds.
     *
     * @var int
     */
    public const DEFAULT_INTERVAL_SECONDS = 60;

    /**
     * Default audit-log retention in days (R19-11).
     *
     * A year keeps a full incident-investigation window while capping the
     * previously unbounded, INSERT-only `audit_logs` table. Override via
     * `config/server.php['audit_retention_days']` (env
     * HUB_AUDIT_RETENTION_DAYS).
     *
     * @var int
     */
    public const DEFAULT_AUDIT_RETENTION_DAYS = 365;

    /**
     * Maximum heartbeat rows purged per {@see sweepHeartbeats()} call.
     *
     * Bounds the lock footprint of a single sweep; the leftover backlog (if
     * any) is caught by the next tick.
     *
     * @var int
     */
    private const HEARTBEAT_SWEEP_BATCH_SIZE = 1000;

    /**
     * Maximum audit rows purged per {@see sweepAuditLogs()} call (R19-11).
     *
     * Same lock-footprint bound as the heartbeat sweep.
     *
     * @var int
     */
    private const AUDIT_SWEEP_BATCH_SIZE = 1000;

    /**
     * @param \Workerman\MySQL\Connection $db                       Database connection.
     * @param StructuredLogger             $logger                  Structured logger.
     * @param int                          $intervalSeconds         Interval between scans.
     * @param int                          $offlineThresholdSeconds Seconds after last_seen_at before a
     *                                                                server is considered offline.
     * @param int                          $heartbeatRetentionDays  Days of heartbeat history to retain.
     * @param int                          $auditRetentionDays      Days of audit-log history to retain
     *                                                                (R19-11).
     */
    public function __construct(
        private readonly \Workerman\MySQL\Connection $db,
        private readonly StructuredLogger $logger,
        private readonly int $intervalSeconds = self::DEFAULT_INTERVAL_SECONDS,
        private readonly int $offlineThresholdSeconds = self::DEFAULT_OFFLINE_THRESHOLD_SECONDS,
        private readonly int $heartbeatRetentionDays = self::DEFAULT_HEARTBEAT_RETENTION_DAYS,
        private readonly int $auditRetentionDays = self::DEFAULT_AUDIT_RETENTION_DAYS,
    ) {
    }

    /**
     * Start the periodic reaper timer.
     *
     * ## S312 — the callback is GUARDED, and that is load-bearing
     *
     * `[$this, 'tick']` used to be the callback. {@see markStaleServersOffline()}
     * is a bare `$this->db->query(...)` and the connection is lazy, so with no
     * reachable database the first tick threw `PDOException: SQLSTATE[HY000]
     * [2002] Connection refused` into the event loop. Workerman hands an
     * exception that escapes a callback to the error handler installed in
     * `Worker::run()` — `Worker::stopAll(250, $exception)` — which kills the
     * whole maintenance worker; the master then re-forks it, once per interval,
     * silently (the child exits **0**, and the master only logs a NON-zero
     * status). See {@see \Phlix\Hub\Health\MaintenanceHeartbeat} for the
     * measurements.
     *
     * @param (\Closure(?\Throwable): void)|null $onSweep Called after every completed tick with the
     *                                                    failure, or null on success — the maintenance
     *                                                    worker's liveness record.
     *
     * @return int Timer ID (pass to Timer::del() to cancel).
     */
    public function start(?\Closure $onSweep = null): int
    {
        $timerId = Timer::add(
            $this->intervalSeconds,
            function () use ($onSweep): void {
                $this->runTickGuarded($onSweep);
            },
        );

        $this->logger->debug('ServerReaper: started', [
            'interval_seconds' => $this->intervalSeconds,
            'offline_threshold_seconds' => $this->offlineThresholdSeconds,
            'heartbeat_retention_days' => $this->heartbeatRetentionDays,
            'audit_retention_days' => $this->auditRetentionDays,
        ]);

        return $timerId;
    }

    /**
     * Run one reaper tick so that NOTHING escapes to the event loop.
     *
     * Public so a test can invoke exactly what the timer holds without going
     * through Workerman. See {@see start()} for why the guard is not optional.
     *
     * @param (\Closure(?\Throwable): void)|null $onSweep Sweep-outcome reporter, or null.
     *
     * @return void
     */
    public function runTickGuarded(?\Closure $onSweep = null): void
    {
        $failure = null;

        try {
            $this->tick();
        } catch (\Throwable $e) {
            $failure = $e;
            $this->logger->error('ServerReaper: tick failed', [
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);
        }

        if ($onSweep !== null) {
            $onSweep($failure);
        }
    }

    /**
     * Perform a single reaper scan — marks stale servers offline and purges
     * old heartbeat rows.
     *
     * Public so it can be called directly by tests or manually.
     *
     * @return void
     */
    public function tick(): void
    {
        $this->markStaleServersOffline();
        $this->sweepHeartbeats();
        $this->sweepAuditLogs();
    }

    /**
     * Mark servers as `offline` when they have no live relay session and their
     * last heartbeat is older than the configured threshold.
     *
     * Only flips servers that are not already `offline` to avoid unnecessary
     * writes on every tick.
     *
     * @return int Number of servers marked offline.
     */
    public function markStaleServersOffline(): int
    {
        /** @var mixed $result */
        $result = $this->db->query(
            "UPDATE servers
             SET status = 'offline'
             WHERE status != 'offline'
               AND last_seen_at < NOW() - INTERVAL :threshold SECOND
               AND id NOT IN (
                   SELECT DISTINCT server_id
                   FROM relay_sessions
                   WHERE closed_at IS NULL
               )",
            ['threshold' => $this->offlineThresholdSeconds],
        );

        $count = is_numeric($result) ? (int) $result : 0;

        if ($count > 0) {
            $this->logger->info('ServerReaper: marked servers offline', [
                'count' => $count,
                'threshold_seconds' => $this->offlineThresholdSeconds,
            ]);
        }

        return $count;
    }

    /**
     * Delete heartbeat rows older than the retention window.
     *
     * Only the latest ~20 rows per server are ever read (see
     * {@see HeartbeatHandler::getHeartbeatHistory()}), so aggressive retention
     * is safe.
     *
     * Two-phase keyed delete (deadlock avoidance):
     *
     *  1. A plain, non-locking consistent SELECT picks the primary keys of up
     *     to {@see HEARTBEAT_SWEEP_BATCH_SIZE} expired rows, ordered by `id`.
     *     This read takes no row/gap locks at all. The index
     *     `idx_server_heartbeats_received_at` (migration 034) keeps it a tight
     *     range scan.
     *  2. `DELETE ... WHERE id IN (:id_0, …)` removes exactly those rows by
     *     PRIMARY KEY. Locking only the specific PK rows (in PK order) avoids
     *     the next-key/gap locks that a single `DELETE ... WHERE received_at <
     *     :cutoff LIMIT 1000` takes across the whole expired range — which is
     *     what deadlocked against concurrent heartbeat INSERTs (each inserting
     *     a fresh row + touching the same `received_at` index) and the
     *     `servers` UPDATE in {@see markStaleServersOffline()}.
     *
     * Retention semantics are unchanged: at most one batch of
     * {@see HEARTBEAT_SWEEP_BATCH_SIZE} rows older than the cutoff is deleted
     * per call; any leftover backlog is drained by the next tick. The bounded
     * deadlock-retry loop is retained as a backstop.
     *
     * @return int Number of rows deleted.
     */
    public function sweepHeartbeats(): int
    {
        $maxRetries = 3;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                // Phase 1: non-locking read of the expired PKs, PK-ordered so
                // phase 2 acquires row locks in a deterministic order.
                /** @var mixed $rows */
                $rows = $this->db->query(
                    'SELECT id FROM server_heartbeats
                     WHERE received_at < NOW() - INTERVAL :retention DAY
                     ORDER BY id
                     LIMIT ' . self::HEARTBEAT_SWEEP_BATCH_SIZE,
                    ['retention' => $this->heartbeatRetentionDays],
                );

                $ids = [];
                if (is_array($rows)) {
                    /** @var list<array<string, mixed>> $rows */
                    foreach ($rows as $row) {
                        /** @var mixed $rawId */
                        $rawId = $row['id'] ?? null;
                        if (is_string($rawId) && $rawId !== '') {
                            $ids[] = $rawId;
                        }
                    }
                }

                // Nothing expired — no lock-taking DELETE needed.
                if ($ids === []) {
                    return 0;
                }

                // Phase 2: delete exactly those rows by PRIMARY KEY. Colon-free
                // named placeholders (workerman/mysql prepends `:`).
                $placeholders = [];
                $params = [];
                foreach ($ids as $i => $id) {
                    $key = 'id_' . $i;
                    $placeholders[] = ':' . $key;
                    $params[$key] = $id;
                }

                /** @var mixed $result */
                $result = $this->db->query(
                    'DELETE FROM server_heartbeats WHERE id IN (' . implode(', ', $placeholders) . ')',
                    $params,
                );
                $count = is_numeric($result) ? (int) $result : 0;

                if ($count > 0) {
                    $this->logger->info('ServerReaper: heartbeat sweep complete', [
                        'deleted' => $count,
                        'retention_days' => $this->heartbeatRetentionDays,
                    ]);
                }

                return $count;
            } catch (\PDOException $e) {
                if ($attempt === $maxRetries || strpos($e->getMessage(), 'Deadlock') === false) {
                    throw $e;
                }
                $this->logger->debug('ServerReaper: heartbeat sweep deadlock, retrying', [
                    'attempt' => $attempt,
                    'max_retries' => $maxRetries,
                    'error' => $e->getMessage(),
                ]);
                usleep(50000 * $attempt);
            }
        }

        return 0;
    }

    /**
     * Delete audit-log rows older than the retention window (R19-11).
     *
     * `audit_logs` is INSERT-only everywhere in the codebase, so without this
     * sweep the table grows unboundedly. Same two-phase keyed delete as
     * {@see sweepHeartbeats()} — non-locking PK SELECT (bounded batch,
     * PK-ordered) followed by `DELETE ... WHERE id IN (...)` on the primary
     * key — to avoid the next-key/gap-lock storm a ranged
     * `DELETE ... WHERE created_at < ... LIMIT n` would take against the
     * concurrent audit writers. The cutoff is DB-clock (`NOW()`), matching how
     * `created_at` defaults are stamped.
     *
     * @return int Number of rows deleted.
     */
    public function sweepAuditLogs(): int
    {
        $maxRetries = 3;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                // Phase 1: non-locking read of the expired PKs, PK-ordered so
                // phase 2 acquires row locks in a deterministic order.
                // idx_created_at (migration 029) keeps it a tight range scan.
                /** @var mixed $rows */
                $rows = $this->db->query(
                    'SELECT id FROM audit_logs
                     WHERE created_at < NOW() - INTERVAL :retention DAY
                     ORDER BY id
                     LIMIT ' . self::AUDIT_SWEEP_BATCH_SIZE,
                    ['retention' => $this->auditRetentionDays],
                );

                $ids = [];
                if (is_array($rows)) {
                    /** @var list<array<string, mixed>> $rows */
                    foreach ($rows as $row) {
                        /** @var mixed $rawId */
                        $rawId = $row['id'] ?? null;
                        if (is_string($rawId) && $rawId !== '') {
                            $ids[] = $rawId;
                        }
                    }
                }

                // Nothing expired — no lock-taking DELETE needed.
                if ($ids === []) {
                    return 0;
                }

                // Phase 2: delete exactly those rows by PRIMARY KEY. Colon-free
                // named placeholders (workerman/mysql prepends `:`).
                $placeholders = [];
                $params = [];
                foreach ($ids as $i => $id) {
                    $key = 'id_' . $i;
                    $placeholders[] = ':' . $key;
                    $params[$key] = $id;
                }

                /** @var mixed $result */
                $result = $this->db->query(
                    'DELETE FROM audit_logs WHERE id IN (' . implode(', ', $placeholders) . ')',
                    $params,
                );
                $count = is_numeric($result) ? (int) $result : 0;

                if ($count > 0) {
                    $this->logger->info('ServerReaper: audit sweep complete', [
                        'deleted' => $count,
                        'retention_days' => $this->auditRetentionDays,
                    ]);
                }

                return $count;
            } catch (\PDOException $e) {
                if ($attempt === $maxRetries || strpos($e->getMessage(), 'Deadlock') === false) {
                    throw $e;
                }
                $this->logger->debug('ServerReaper: audit sweep deadlock, retrying', [
                    'attempt' => $attempt,
                    'max_retries' => $maxRetries,
                    'error' => $e->getMessage(),
                ]);
                usleep(50000 * $attempt);
            }
        }

        return 0;
    }

    /**
     * Get the scan interval in seconds.
     */
    public function getIntervalSeconds(): int
    {
        return $this->intervalSeconds;
    }

    /**
     * Get the offline threshold in seconds.
     */
    public function getOfflineThresholdSeconds(): int
    {
        return $this->offlineThresholdSeconds;
    }

    /**
     * Get the heartbeat retention window in days.
     */
    public function getHeartbeatRetentionDays(): int
    {
        return $this->heartbeatRetentionDays;
    }

    /**
     * Get the audit-log retention window in days (R19-11).
     */
    public function getAuditRetentionDays(): int
    {
        return $this->auditRetentionDays;
    }
}
