<?php

declare(strict_types=1);

namespace Phlix\Hub\Stats\Metrics;

use Phlix\Hub\Common\Database\ConnectionPool;

/**
 * Persists the hub's {@see MetricsRegistry} state to MySQL.
 *
 * Uses named `:param` placeholders (hub convention). The UPSERTs
 * (`INSERT ... ON DUPLICATE KEY UPDATE col = col + VALUES(col)`) use a fixed
 * `worker_id` of `'hub-relay'` since the hub runs a single relay process.
 * Rates for the live-connection panel are derived from the delta between
 * successive flushes divided by the flush interval.
 *
 * @package Phlix\Hub\Stats\Metrics
 * @since S4
 */
final class MetricsFlushService
{
    /** @var MetricsCollector Owns the registry this service drains. */
    private MetricsCollector $collector;

    /** @var int Days of rollup history to retain before pruning. */
    private int $retentionDays;

    /** @var int Seconds of connection inactivity before a row is pruned. */
    private int $connectionTtlSeconds;

    /** @var int Flush cadence in seconds (rate denominator). */
    private int $flushIntervalSeconds;

    /**
     * Previous cumulative byte counts per connection id, for rate computation.
     *
     * @var array<string, array{in: int, out: int}>
     */
    private array $previousBytes = [];

    /** @var int Flush counter used to throttle pruning to ~once/minute. */
    private int $flushTick = 0;

    /**
     * @param MetricsCollector     $collector Provides the registry to drain.
     * @param array<string, mixed> $config    config/metrics.php array (reads
     *        retention_days, connection_ttl_seconds, flush_interval_seconds).
     */
    public function __construct(MetricsCollector $collector, array $config)
    {
        $this->collector            = $collector;
        $this->retentionDays        = $this->cfgInt($config, 'retention_days', 7);
        $this->connectionTtlSeconds = $this->cfgInt($config, 'connection_ttl_seconds', 15);
        $this->flushIntervalSeconds = max(1, $this->cfgInt($config, 'flush_interval_seconds', 5));
    }

    /**
     * The flush cadence in seconds — also the per-connection rate denominator.
     *
     * Producing workers arm their flush {@see \Workerman\Timer} at exactly this
     * interval so the timer cadence matches the rate maths inside
     * {@see flushConnections()} (otherwise per-connection byte rates would be
     * scaled wrong when `flush_interval_seconds` is overridden from its default).
     *
     * @return int Seconds between flushes (>= 1).
     */
    public function flushIntervalSeconds(): int
    {
        return $this->flushIntervalSeconds;
    }

    /**
     * Drain the registry and persist rollups + live connections for the hub relay.
     *
     * Upserts overall + per-route rollups (accumulating VALUES into existing rows)
     * and the active-connection snapshot (computing per-connection byte rates from
     * the delta since the previous flush). Pruning is invoked but internally
     * throttled so old rows are cleaned up without a DELETE on every 5s tick.
     *
     * @param int $workerId Unused; retained for API symmetry with the server
     *                       flush service. The hub always uses 'hub-relay'.
     * @param int $nowTs     Unix timestamp of the flush.
     *
     * @return void
     */
    public function flush(int $workerId, int $nowTs): void
    {
        if (!$this->collector->isEnabled()) {
            return;
        }

        $registry = $this->collector->registry();
        $drained  = $registry->drainRollups($nowTs);

        $this->flushOverall($drained['overall']);
        $this->flushRoutes($drained['routes']);
        $this->flushConnections($registry->snapshotConnections(), $nowTs);

        // Prune roughly once per minute rather than every flush.
        $this->flushTick++;
        $ticksPerMinute = max(1, (int) round(60 / $this->flushIntervalSeconds));
        if ($this->flushTick % $ticksPerMinute === 0) {
            $this->prune($nowTs);
            // Evict connections from the in-RAM map once they age past the same
            // TTL the persisted rows use, so the registry stays bounded after a
            // relay tunnel closes (the close hook leaves a FINAL touch rather
            // than an immediate delete, mirroring the server).
            $registry->pruneStaleConnections($nowTs - $this->connectionTtlSeconds);
        }
    }

    /**
     * Delete stale connection rows and rollups older than the retention window.
     *
     * @param int $nowTs Unix timestamp used as the "now" reference.
     *
     * @return void
     */
    public function prune(int $nowTs): void
    {
        $db            = ConnectionPool::getConnection();
        $connCutoff    = $this->datetime($nowTs - $this->connectionTtlSeconds);
        $rollupCutoff  = $this->datetime($nowTs - ($this->retentionDays * 86400));

        $db->query(
            "DELETE FROM metrics_connections WHERE last_seen_at < :cutoff",
            [':cutoff' => $connCutoff]
        );
        $db->query(
            "DELETE FROM metrics_rollup WHERE bucket_started_at < :cutoff",
            [':cutoff' => $rollupCutoff]
        );
        $db->query(
            "DELETE FROM metrics_route_rollup WHERE bucket_started_at < :cutoff",
            [':cutoff' => $rollupCutoff]
        );
    }

    /**
     * Upsert the drained overall buckets.
     *
     * @param array<int, array{
     *     bucket_started_at: int,
     *     request_count: int,
     *     error_count: int,
     *     duration_ms_sum: int,
     *     duration_ms_max: int,
     *     bytes_in: int,
     *     bytes_out: int,
     *     histogram: array<int, int>
     * }> $buckets
     *
     * @return void
     */
    private function flushOverall(array $buckets): void
    {
        if ($buckets === []) {
            return;
        }

        $db = ConnectionPool::getConnection();

        foreach ($buckets as $b) {
            $h = $b['histogram'];
            // The histogram bind placeholders are :h0..:h8, NOT :h_le_10..:h_gt_5000
            // (the columns keep their names). Numeric-suffixed names collide by
            // prefix — :h_le_10 ⊂ :h_le_100 ⊂ :h_le_1000, :h_le_50 ⊂ :h_le_500 ⊂
            // :h_le_5000, :h_le_250 ⊂ :h_le_2500 — which PDO mis-rewrites under
            // emulated prepares (SQLSTATE[HY093]). Keep no placeholder a prefix of
            // another. See flushConnections() for the same trap.
            $db->query(
                "INSERT INTO metrics_rollup
                 (bucket_started_at, worker_id, request_count, error_count,
                  duration_ms_sum, duration_ms_max, bytes_in, bytes_out,
                  h_le_10, h_le_50, h_le_100, h_le_250, h_le_500,
                  h_le_1000, h_le_2500, h_le_5000, h_gt_5000)
                 VALUES (:bucket, :worker_id, :request_count, :error_count,
                         :duration_ms_sum, :duration_ms_max, :bytes_in, :bytes_out,
                         :h0, :h1, :h2, :h3, :h4,
                         :h5, :h6, :h7, :h8)
                 ON DUPLICATE KEY UPDATE
                     request_count   = request_count + VALUES(request_count),
                     error_count     = error_count + VALUES(error_count),
                     duration_ms_sum = duration_ms_sum + VALUES(duration_ms_sum),
                     duration_ms_max = GREATEST(duration_ms_max, VALUES(duration_ms_max)),
                     bytes_in        = bytes_in + VALUES(bytes_in),
                     bytes_out       = bytes_out + VALUES(bytes_out),
                     h_le_10         = h_le_10 + VALUES(h_le_10),
                     h_le_50         = h_le_50 + VALUES(h_le_50),
                     h_le_100        = h_le_100 + VALUES(h_le_100),
                     h_le_250        = h_le_250 + VALUES(h_le_250),
                     h_le_500        = h_le_500 + VALUES(h_le_500),
                     h_le_1000       = h_le_1000 + VALUES(h_le_1000),
                     h_le_2500       = h_le_2500 + VALUES(h_le_2500),
                     h_le_5000       = h_le_5000 + VALUES(h_le_5000),
                     h_gt_5000       = h_gt_5000 + VALUES(h_gt_5000)",
                [
                    ':bucket'          => $this->datetime($b['bucket_started_at']),
                    ':worker_id'       => 'hub-relay',
                    ':request_count'   => $b['request_count'],
                    ':error_count'     => $b['error_count'],
                    ':duration_ms_sum' => $b['duration_ms_sum'],
                    ':duration_ms_max' => $b['duration_ms_max'],
                    ':bytes_in'        => $b['bytes_in'],
                    ':bytes_out'       => $b['bytes_out'],
                    ':h0'              => $h[10] ?? 0,
                    ':h1'              => $h[50] ?? 0,
                    ':h2'              => $h[100] ?? 0,
                    ':h3'              => $h[250] ?? 0,
                    ':h4'              => $h[500] ?? 0,
                    ':h5'              => $h[1000] ?? 0,
                    ':h6'              => $h[2500] ?? 0,
                    ':h7'              => $h[5000] ?? 0,
                    ':h8'              => $h[-1] ?? 0,
                ]
            );
        }
    }

    /**
     * Upsert the drained per-route buckets.
     *
     * @param array<int, array{
     *     bucket_started_at: int,
     *     method: string,
     *     route: string,
     *     request_count: int,
     *     error_count: int,
     *     duration_ms_sum: int,
     *     duration_ms_max: int
     * }> $routes
     *
     * @return void
     */
    private function flushRoutes(array $routes): void
    {
        if ($routes === []) {
            return;
        }

        $db = ConnectionPool::getConnection();

        foreach ($routes as $r) {
            $db->query(
                "INSERT INTO metrics_route_rollup
                 (bucket_started_at, worker_id, method, route,
                  request_count, error_count, duration_ms_sum, duration_ms_max)
                 VALUES (:bucket, :worker_id, :method, :route,
                         :request_count, :error_count, :duration_ms_sum, :duration_ms_max)
                 ON DUPLICATE KEY UPDATE
                     request_count   = request_count + VALUES(request_count),
                     error_count     = error_count + VALUES(error_count),
                     duration_ms_sum = duration_ms_sum + VALUES(duration_ms_sum),
                     duration_ms_max = GREATEST(duration_ms_max, VALUES(duration_ms_max))",
                [
                    ':bucket'          => $this->datetime($r['bucket_started_at']),
                    ':worker_id'       => 'hub-relay',
                    ':method'          => $r['method'],
                    ':route'           => $r['route'],
                    ':request_count'   => $r['request_count'],
                    ':error_count'     => $r['error_count'],
                    ':duration_ms_sum' => $r['duration_ms_sum'],
                    ':duration_ms_max' => $r['duration_ms_max'],
                ]
            );
        }
    }

    /**
     * Upsert the active-connection snapshot with computed byte rates.
     *
     * @param array<string, array{
     *     kind: string,
     *     user_id: ?string,
     *     remote_ip: ?string,
     *     session_id: ?string,
     *     media_item_id: ?string,
     *     bytes_in: int,
     *     bytes_out: int,
     *     opened_at: int,
     *     last_seen_at: int
     * }> $connections
     * @param int $nowTs Unix timestamp of the flush.
     *
     * @return void
     */
    private function flushConnections(array $connections, int $nowTs): void
    {
        $db = ConnectionPool::getConnection();

        foreach ($connections as $id => $c) {
            $prev    = $this->previousBytes[$id] ?? ['in' => $c['bytes_in'], 'out' => $c['bytes_out']];
            $inRate  = max(0, intdiv($c['bytes_in'] - $prev['in'], $this->flushIntervalSeconds));
            $outRate = max(0, intdiv($c['bytes_out'] - $prev['out'], $this->flushIntervalSeconds));
            $this->previousBytes[$id] = ['in' => $c['bytes_in'], 'out' => $c['bytes_out']];

            // NOTE: the byte placeholders are :bytes_in_val / :bytes_out_val, NOT
            // :bytes_in / :bytes_out. Under emulated prepares (which this hub's
            // PhlixMySQLConnection requires — native prepares corrupt across
            // coroutine yields) a named placeholder that is a strict PREFIX of
            // another in the same statement (:bytes_in ⊂ :bytes_in_rate) makes PDO
            // rewrite the wrong token and throw SQLSTATE[HY093] "parameter was not
            // defined". Keep no placeholder name a prefix of another here.
            $db->query(
                "INSERT INTO metrics_connections
                 (connection_id, worker_id, kind, user_id, remote_ip, session_id,
                  media_item_id, bytes_in, bytes_out, bytes_in_rate, bytes_out_rate,
                  opened_at, last_seen_at)
                 VALUES (:id, :worker_id, :kind, :user_id, :remote_ip, :session_id,
                         :media_item_id, :bytes_in_val, :bytes_out_val, :bytes_in_rate, :bytes_out_rate,
                         :opened_at, :last_seen_at)
                 ON DUPLICATE KEY UPDATE
                     kind           = VALUES(kind),
                     user_id        = VALUES(user_id),
                     remote_ip      = VALUES(remote_ip),
                     session_id     = VALUES(session_id),
                     media_item_id  = VALUES(media_item_id),
                     bytes_in       = VALUES(bytes_in),
                     bytes_out      = VALUES(bytes_out),
                     bytes_in_rate  = VALUES(bytes_in_rate),
                     bytes_out_rate = VALUES(bytes_out_rate),
                     last_seen_at   = VALUES(last_seen_at)",
                [
                    ':id'             => $id,
                    ':worker_id'      => 'hub-relay',
                    ':kind'           => $c['kind'],
                    ':user_id'        => $c['user_id'],
                    ':remote_ip'      => $c['remote_ip'],
                    ':session_id'     => $c['session_id'],
                    ':media_item_id'  => $c['media_item_id'],
                    ':bytes_in_val'   => $c['bytes_in'],
                    ':bytes_out_val'  => $c['bytes_out'],
                    ':bytes_in_rate'  => $inRate,
                    ':bytes_out_rate' => $outRate,
                    ':opened_at'     => $this->datetime($c['opened_at']),
                    ':last_seen_at'  => $this->datetime($c['last_seen_at']),
                ]
            );
        }

        // Forget rate-tracking state for connections that have gone away.
        foreach (array_keys($this->previousBytes) as $trackedId) {
            if (!isset($connections[$trackedId])) {
                unset($this->previousBytes[$trackedId]);
            }
        }
    }

    /**
     * Format a Unix timestamp as a MySQL DATETIME string.
     *
     * @param int $ts Unix timestamp.
     *
     * @return string `Y-m-d H:i:s`.
     */
    private function datetime(int $ts): string
    {
        return date('Y-m-d H:i:s', $ts);
    }

    /**
     * Read an int config value with a default.
     *
     * @param array<string, mixed> $config
     * @param string               $key
     * @param int                  $default
     *
     * @return int
     */
    private function cfgInt(array $config, string $key, int $default): int
    {
        /** @var mixed $v */
        $v = $config[$key] ?? null;
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && is_numeric($v)) {
            return (int) $v;
        }
        return $default;
    }
}
