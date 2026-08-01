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
 * Both tasks run on the same timer to avoid scheduling two separate periodic
 * workers.  The reaper is started from {@see HubServicesProvider::boot()}.
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
     * Maximum heartbeat rows purged per {@see sweepHeartbeats()} call.
     *
     * Bounds the lock footprint of a single sweep; the leftover backlog (if
     * any) is caught by the next tick.
     *
     * @var int
     */
    private const HEARTBEAT_SWEEP_BATCH_SIZE = 1000;

    /**
     * @param \Workerman\MySQL\Connection $db                       Database connection.
     * @param StructuredLogger             $logger                  Structured logger.
     * @param int                          $intervalSeconds         Interval between scans.
     * @param int                          $offlineThresholdSeconds Seconds after last_seen_at before a
     *                                                                server is considered offline.
     * @param int                          $heartbeatRetentionDays  Days of heartbeat history to retain.
     */
    public function __construct(
        private readonly \Workerman\MySQL\Connection $db,
        private readonly StructuredLogger $logger,
        private readonly int $intervalSeconds = self::DEFAULT_INTERVAL_SECONDS,
        private readonly int $offlineThresholdSeconds = self::DEFAULT_OFFLINE_THRESHOLD_SECONDS,
        private readonly int $heartbeatRetentionDays = self::DEFAULT_HEARTBEAT_RETENTION_DAYS,
    ) {
    }

    /**
     * Start the periodic reaper timer.
     *
     * @return int Timer ID (pass to Timer::del() to cancel).
     */
    public function start(): int
    {
        $timerId = Timer::add(
            $this->intervalSeconds,
            [$this, 'tick'],
        );

        $this->logger->debug('ServerReaper: started', [
            'interval_seconds' => $this->intervalSeconds,
            'offline_threshold_seconds' => $this->offlineThresholdSeconds,
            'heartbeat_retention_days' => $this->heartbeatRetentionDays,
        ]);

        return $timerId;
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
}
