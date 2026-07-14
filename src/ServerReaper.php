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
     * NOTE: The existing index `ix_server_heartbeats_server_time (server_id,
     * received_at)` has `server_id` as its leading column, so this plain
     * `WHERE received_at < ...` filter cannot use it as a range scan and
     * falls back to a full table scan.  Given the table is bounded (≤20 rows
     * per server × servers × 7 days retention), this is acceptable.  If
     * retention grows or the table becomes large, consider adding a partial
     * index on `received_at` alone, or rewriting as:
     *   DELETE FROM server_heartbeats WHERE id IN (SELECT id FROM ...)
     *   using a server-scoped subquery that can hit the composite index.
     *
     * @return int Number of rows deleted.
     */
    public function sweepHeartbeats(): int
    {
        $maxRetries = 3;
        $totalDeleted = 0;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                /** @var mixed $result */
                $result = $this->db->query(
                    'DELETE FROM server_heartbeats WHERE received_at < NOW() - INTERVAL :retention DAY ORDER BY received_at LIMIT 1000',
                    ['retention' => $this->heartbeatRetentionDays]
                );
                $count = is_numeric($result) ? (int) $result : 0;
                $totalDeleted += $count;

                if ($count === 0) {
                    break;
                }

                if ($count > 0) {
                    $this->logger->info('ServerReaper: heartbeat sweep complete', [
                        'deleted' => $count,
                        'retention_days' => $this->heartbeatRetentionDays,
                    ]);
                }

                if ($count < 1000) {
                    break;
                }

                return $totalDeleted;
            } catch (\PDOException $e) {
                if ($attempt === $maxRetries || strpos($e->getMessage(), 'Deadlock') === false) {
                    throw $e;
                }
                usleep(100000 * $attempt);
            }
        }

        return $totalDeleted;
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
