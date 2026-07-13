<?php

/**
 * Phlix hub component: Metrics.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Stats\Metrics;

/**
 * Per-worker in-memory metrics accumulator for the hub relay.
 *
 * Identical in structure to the server's MetricsRegistry but under the
 * `Phlix\Hub\Stats\Metrics` namespace. A single instance holds the current
 * window's request + per-route counters plus the map of active connections.
 * It performs NO I/O and makes NO clock calls of its own — every method
 * that needs "now" takes an explicit `$nowTs` argument so the class is fully
 * deterministic and unit-testable. {@see MetricsFlushService} periodically
 * calls {@see drainRollups()} to obtain the accumulated deltas (which resets the
 * counters) and {@see snapshotConnections()} to persist the live-connection map.
 *
 * Memory safety: the rollup maps are drained + reset on every flush, and the
 * per-route map is capped at `routeCardinalityCap` distinct routes per bucket
 * (further routes fold into a single "__other__" route).
 *
 * @package Phlix\Hub\Stats\Metrics
 * @since S4
 */
final class MetricsRegistry
{
    /**
     * Sentinel route name that all routes beyond the cardinality cap fold into.
     */
    public const OTHER_ROUTE = '__other__';

    /** @var int Bucket granularity in seconds (buckets aligned to floor(ts/N)*N). */
    private int $bucketSeconds;

    /**
     * @var array<int, int> Latency histogram upper bounds in milliseconds, ascending.
     */
    private array $latencyBucketsMs;

    /** @var int Current count of in-flight relay proxy requests (gauge). */
    private int $relayPendingRequests = 0;

    /** @var int Cumulative count of HTTP_RESPONSE frames dropped for unknown/closed requests. */
    private int $relayReplyDrops = 0;

    /** @var int Cumulative count of HTTP_CANCEL frames the hub emitted (client abandoned an in-flight request). */
    private int $relayCancels = 0;

    /**
     * Per-bucket relay-latency histogram (time from HTTP_REQUEST sent to first HTTP_RESPONSE byte).
     *
     * @var array<int, array<int, int>>
     */
    private array $relayLatencyHistogram = [];

    /** @var int Cumulative count of relay proxy requests that returned 503. */
    private int $relayError503 = 0;

    /** @var int Cumulative count of relay proxy requests that returned 504. */
    private int $relayError504 = 0;

    /** @var int Current FrameDecoder buffer size in bytes (gauge, updated on each decode call). */
    private int $relayDecodeBufferBytes = 0;

    /** @var int Max distinct (method,route) pairs per bucket before folding to OTHER_ROUTE. */
    private int $routeCardinalityCap;

    /**
     * Overall counters keyed by bucket start timestamp.
     *
     * @var array<int, array{
     *     request_count: int,
     *     error_count: int,
     *     duration_ms_sum: int,
     *     duration_ms_max: int,
     *     bytes_in: int,
     *     bytes_out: int,
     *     histogram: array<int, int>
     * }>
     */
    private array $buckets = [];

    /**
     * Per-route counters keyed by bucket start timestamp then by "METHOD route".
     *
     * @var array<int, array<string, array{
     *     method: string,
     *     route: string,
     *     request_count: int,
     *     error_count: int,
     *     duration_ms_sum: int,
     *     duration_ms_max: int
     * }>>
     */
    private array $routeBuckets = [];

    /**
     * Active connections keyed by connection id.
     *
     * @var array<string, array{
     *     kind: string,
     *     user_id: ?string,
     *     remote_ip: ?string,
     *     session_id: ?string,
     *     media_item_id: ?string,
     *     bytes_in: int,
     *     bytes_out: int,
     *     opened_at: int,
     *     last_seen_at: int
     * }>
     */
    private array $connections = [];

    /**
     * @param int              $bucketSeconds       Bucket granularity in seconds (>= 1).
     * @param array<int, int>  $latencyBucketsMs    Ascending histogram upper bounds in ms.
     * @param int              $routeCardinalityCap Max distinct routes per bucket (>= 1).
     */
    public function __construct(
        int $bucketSeconds = 10,
        array $latencyBucketsMs = [10, 50, 100, 250, 500, 1000, 2500, 5000],
        int $routeCardinalityCap = 200
    ) {
        $this->bucketSeconds = max(1, $bucketSeconds);

        $bounds = [];
        foreach ($latencyBucketsMs as $bound) {
            $bounds[] = $bound;
        }
        sort($bounds);
        $this->latencyBucketsMs = $bounds !== [] ? $bounds : [10, 50, 100, 250, 500, 1000, 2500, 5000];

        $this->routeCardinalityCap = max(1, $routeCardinalityCap);
    }

    /**
     * Record a completed request into the current time bucket.
     *
     * @param string $method    HTTP method (e.g. "GET"); upper-cased and truncated to 8 chars.
     * @param string $route     Matched route template (e.g. "/api/v1/media/{id}").
     * @param int    $status    HTTP status code.
     * @param float  $elapsedMs Wall-clock request duration in milliseconds.
     * @param int    $bytesIn   Bytes read from the client for this request.
     * @param int    $bytesOut  Bytes written to the client for this request.
     * @param int    $nowTs     Unix timestamp of the request (caller-supplied).
     *
     * @return void
     */
    public function recordRequest(
        string $method,
        string $route,
        int $status,
        float $elapsedMs,
        int $bytesIn,
        int $bytesOut,
        int $nowTs
    ): void {
        $bucketTs = $this->bucketFor($nowTs);
        $elapsed  = (int) round($elapsedMs);
        $isError  = $status >= 500;

        // --- Overall bucket ---
        if (!isset($this->buckets[$bucketTs])) {
            $this->buckets[$bucketTs] = [
                'request_count'   => 0,
                'error_count'     => 0,
                'duration_ms_sum' => 0,
                'duration_ms_max' => 0,
                'bytes_in'        => 0,
                'bytes_out'       => 0,
                'histogram'       => $this->emptyHistogram(),
            ];
        }
        $overall = $this->buckets[$bucketTs];
        $overall['request_count']++;
        if ($isError) {
            $overall['error_count']++;
        }
        $overall['duration_ms_sum'] += $elapsed;
        if ($elapsed > $overall['duration_ms_max']) {
            $overall['duration_ms_max'] = $elapsed;
        }
        $overall['bytes_in']  += $bytesIn;
        $overall['bytes_out'] += $bytesOut;
        $overall['histogram'][$this->histogramIndex($elapsed)]++;
        $this->buckets[$bucketTs] = $overall;

        // --- Per-route bucket (cardinality-capped) ---
        $normMethod = strtoupper(substr($method, 0, 8));
        $normRoute  = $this->resolveRoute($bucketTs, $normMethod, $route);
        $key        = $normMethod . ' ' . $normRoute;

        if (!isset($this->routeBuckets[$bucketTs][$key])) {
            $this->routeBuckets[$bucketTs][$key] = [
                'method'          => $normMethod,
                'route'           => $normRoute,
                'request_count'   => 0,
                'error_count'     => 0,
                'duration_ms_sum' => 0,
                'duration_ms_max' => 0,
            ];
        }
        $r = $this->routeBuckets[$bucketTs][$key];
        $r['request_count']++;
        if ($isError) {
            $r['error_count']++;
        }
        $r['duration_ms_sum'] += $elapsed;
        if ($elapsed > $r['duration_ms_max']) {
            $r['duration_ms_max'] = $elapsed;
        }
        $this->routeBuckets[$bucketTs][$key] = $r;
    }

    /**
     * Register a newly opened connection in the active-connection map.
     *
     * @param string  $id          Connection id.
     * @param string  $kind        One of "http", "websocket", "stream".
     * @param ?string $userId      Authenticated user UUID, if known.
     * @param ?string $remoteIp    Client IP address.
     * @param ?string $sessionId   Session UUID, if any.
     * @param ?string $mediaItemId Media item UUID being served, if any.
     * @param int     $nowTs       Unix timestamp the connection opened.
     *
     * @return void
     */
    public function openConnection(
        string $id,
        string $kind,
        ?string $userId,
        ?string $remoteIp,
        ?string $sessionId,
        ?string $mediaItemId,
        int $nowTs
    ): void {
        $this->connections[$id] = [
            'kind'          => $this->normalizeKind($kind),
            'user_id'       => $userId,
            'remote_ip'     => $remoteIp,
            'session_id'    => $sessionId,
            'media_item_id' => $mediaItemId,
            'bytes_in'      => 0,
            'bytes_out'     => 0,
            'opened_at'     => $nowTs,
            'last_seen_at'  => $nowTs,
        ];
    }

    /**
     * Update a connection's cumulative byte counts and last-seen timestamp.
     *
     * Stores the LATEST cumulative totals (not deltas). If the connection was
     * never opened via {@see openConnection()} the entry is created with the
     * current timestamp as both opened_at and last_seen_at.
     *
     * @param string $id                 Connection id.
     * @param int    $bytesInCumulative  Total bytes read on the connection so far.
     * @param int    $bytesOutCumulative Total bytes written on the connection so far.
     * @param int    $nowTs              Unix timestamp of the touch.
     *
     * @return void
     */
    public function touchConnection(string $id, int $bytesInCumulative, int $bytesOutCumulative, int $nowTs): void
    {
        if (!isset($this->connections[$id])) {
            $this->connections[$id] = [
                'kind'          => 'http',
                'user_id'       => null,
                'remote_ip'     => null,
                'session_id'    => null,
                'media_item_id' => null,
                'bytes_in'      => 0,
                'bytes_out'     => 0,
                'opened_at'     => $nowTs,
                'last_seen_at'  => $nowTs,
            ];
        }
        $this->connections[$id]['bytes_in']     = $bytesInCumulative;
        $this->connections[$id]['bytes_out']    = $bytesOutCumulative;
        $this->connections[$id]['last_seen_at'] = $nowTs;
    }

    /**
     * Remove a connection from the active-connection map.
     *
     * @param string $id Connection id.
     *
     * @return void
     */
    public function closeConnection(string $id): void
    {
        unset($this->connections[$id]);
    }

    /**
     * Drop connections whose {@see touchConnection()} last_seen_at has aged past
     * the given cutoff.
     *
     * Called by {@see MetricsFlushService} on its prune tick with `now - ttl`, so
     * connections that stopped being touched (a closed relay tunnel / departed
     * client) are evicted from the in-RAM map after the same TTL the persisted
     * `metrics_connections` rows use. Live connections are touched every cycle
     * and so never age out. This keeps the map bounded now that the relay close
     * hook records a FINAL touch (letting the next flush persist the real totals)
     * instead of deleting the row immediately.
     *
     * @param int $cutoffTs Unix timestamp; entries with last_seen_at < this go.
     *
     * @return void
     */
    public function pruneStaleConnections(int $cutoffTs): void
    {
        foreach ($this->connections as $id => $connection) {
            if ($connection['last_seen_at'] < $cutoffTs) {
                unset($this->connections[$id]);
            }
        }
    }

    /**
     * Drain and RESET all accumulated rollups since the last drain.
     *
     * @param int $nowTs Unix timestamp of the drain (accepted for symmetry; not used).
     *
     * @return array{
     *     overall: array<int, array{
     *         bucket_started_at: int,
     *         request_count: int,
     *         error_count: int,
     *         duration_ms_sum: int,
     *         duration_ms_max: int,
     *         bytes_in: int,
     *         bytes_out: int,
     *         histogram: array<int, int>
     *     }>,
     *     routes: array<int, array{
     *         bucket_started_at: int,
     *         method: string,
     *         route: string,
     *         request_count: int,
     *         error_count: int,
     *         duration_ms_sum: int,
     *         duration_ms_max: int
     *     }>
     * }
     */
    public function drainRollups(int $nowTs): array
    {
        $overall = [];
        foreach ($this->buckets as $bucketTs => $data) {
            $overall[$bucketTs] = [
                'bucket_started_at' => $bucketTs,
                'request_count'     => $data['request_count'],
                'error_count'       => $data['error_count'],
                'duration_ms_sum'   => $data['duration_ms_sum'],
                'duration_ms_max'   => $data['duration_ms_max'],
                'bytes_in'          => $data['bytes_in'],
                'bytes_out'         => $data['bytes_out'],
                'histogram'         => $data['histogram'],
            ];
        }

        $routes = [];
        foreach ($this->routeBuckets as $bucketTs => $byKey) {
            foreach ($byKey as $entry) {
                $routes[] = [
                    'bucket_started_at' => $bucketTs,
                    'method'            => $entry['method'],
                    'route'             => $entry['route'],
                    'request_count'     => $entry['request_count'],
                    'error_count'       => $entry['error_count'],
                    'duration_ms_sum'   => $entry['duration_ms_sum'],
                    'duration_ms_max'   => $entry['duration_ms_max'],
                ];
            }
        }

        // Reset accumulators.
        $this->buckets      = [];
        $this->routeBuckets = [];

        return [
            'overall' => $overall,
            'routes'  => $routes,
        ];
    }

    /**
     * Return a copy of the active-connection map (no reset).
     *
     * @return array<string, array{
     *     kind: string,
     *     user_id: ?string,
     *     remote_ip: ?string,
     *     session_id: ?string,
     *     media_item_id: ?string,
     *     bytes_in: int,
     *     bytes_out: int,
     *     opened_at: int,
     *     last_seen_at: int
     * }>
     */
    public function snapshotConnections(): array
    {
        return $this->connections;
    }

    /**
     * Configured latency histogram bounds (ascending), for the flush service.
     *
     * @return array<int, int>
     */
    public function latencyBounds(): array
    {
        return $this->latencyBucketsMs;
    }

    /**
     * Align a timestamp to the start of its bucket.
     *
     * @param int $ts Unix timestamp.
     *
     * @return int Bucket start timestamp (floor(ts / bucketSeconds) * bucketSeconds).
     */
    private function bucketFor(int $ts): int
    {
        return intdiv($ts, $this->bucketSeconds) * $this->bucketSeconds;
    }

    /**
     * Public bucket alignment for the flush service.
     *
     * The relay scalar counters/gauges drained by {@see drainRelayMetrics()} are
     * window totals (not per-observation time-bucketed like the latency
     * histogram), so {@see MetricsFlushService} needs to place them onto the same
     * bucket grid every other rollup row uses. Exposes {@see bucketFor()}.
     *
     * @param int $ts Unix timestamp.
     *
     * @return int Bucket start timestamp.
     */
    public function bucketStart(int $ts): int
    {
        return $this->bucketFor($ts);
    }

    /**
     * Resolve the route to record, folding to OTHER_ROUTE past the cardinality cap.
     *
     * @param int    $bucketTs Bucket start timestamp.
     * @param string $method   Normalised HTTP method.
     * @param string $route    Requested route template (truncated to 191 chars).
     *
     * @return string Either the (possibly truncated) route or OTHER_ROUTE.
     */
    private function resolveRoute(int $bucketTs, string $method, string $route): string
    {
        $route = substr($route, 0, 191);
        if ($route === '') {
            $route = '/';
        }

        $key = $method . ' ' . $route;
        if (isset($this->routeBuckets[$bucketTs][$key])) {
            return $route;
        }
        if ($route === self::OTHER_ROUTE) {
            return self::OTHER_ROUTE;
        }

        $distinct = isset($this->routeBuckets[$bucketTs]) ? count($this->routeBuckets[$bucketTs]) : 0;
        if ($distinct >= $this->routeCardinalityCap) {
            return self::OTHER_ROUTE;
        }

        return $route;
    }

    /**
     * Index of the histogram bucket a duration falls into.
     *
     * Returns the matching configured bound (elapsed <= bound but > previous), or
     * `-1` for the overflow bucket when elapsed exceeds the last bound.
     *
     * @param int $elapsedMs Rounded request duration in milliseconds.
     *
     * @return int Bound value (histogram key) or -1 for overflow.
     */
    private function histogramIndex(int $elapsedMs): int
    {
        foreach ($this->latencyBucketsMs as $bound) {
            if ($elapsedMs <= $bound) {
                return $bound;
            }
        }
        return -1;
    }

    /**
     * A fresh, zeroed histogram map (one key per bound plus the -1 overflow key).
     *
     * @return array<int, int>
     */
    private function emptyHistogram(): array
    {
        $h = [];
        foreach ($this->latencyBucketsMs as $bound) {
            $h[$bound] = 0;
        }
        $h[-1] = 0;
        return $h;
    }

    /**
     * Normalise a connection kind to one of the allowed enum values.
     *
     * @param string $kind Raw kind.
     *
     * @return string One of "http", "websocket", "stream" (defaults to "http").
     */
    private function normalizeKind(string $kind): string
    {
        $kind = strtolower($kind);
        return in_array($kind, ['http', 'websocket', 'stream'], true) ? $kind : 'http';
    }

    /**
     * Set the current in-flight relay-proxy request count.
     *
     * @param int $count Current pending request count.
     *
     * @return void
     */
    public function setRelayPendingRequests(int $count): void
    {
        $this->relayPendingRequests = $count;
    }

    /**
     * Record a relay HTTP_RESPONSE frame that was dropped because the request
     * is unknown (already completed, timed out, or cancelled).
     *
     * @return void
     */
    public function recordRelayReplyDrop(): void
    {
        $this->relayReplyDrops++;
    }

    /**
     * Record that the hub cancelled an in-flight relay request and signalled the
     * server to stop work (an HTTP_CANCEL frame was emitted because the client
     * abandoned the request).
     *
     * @return void
     */
    public function recordRelayCancel(): void
    {
        $this->relayCancels++;
    }

    /**
     * Record the round-trip latency of a completed relay proxy request.
     *
     * @param float $ms Latency in milliseconds (time from sending HTTP_REQUEST
     *                  frame to receiving the first HTTP_RESPONSE byte).
     * @param int   $nowTs Unix timestamp of the recording.
     *
     * @return void
     */
    public function recordRelayLatency(float $ms, int $nowTs): void
    {
        $bucketTs = $this->bucketFor($nowTs);
        if (!isset($this->relayLatencyHistogram[$bucketTs])) {
            $h = [];
            foreach ($this->latencyBucketsMs as $bound) {
                $h[$bound] = 0;
            }
            $h[-1] = 0;
            $this->relayLatencyHistogram[$bucketTs] = $h;
        }
        $elapsed = (int) round($ms);
        $index = -1;
        foreach ($this->latencyBucketsMs as $bound) {
            if ($elapsed <= $bound) {
                $index = $bound;
                break;
            }
        }
        $this->relayLatencyHistogram[$bucketTs][$index]++;
    }

    /**
     * Record a relay proxy error response with the given HTTP status code.
     *
     * Only 503 and 504 are tracked individually; all other non-2xx codes
     * are folded into a single `other` counter.
     *
     * @param int $statusCode HTTP status code returned to the client.
     *
     * @return void
     */
    public function recordRelayError(int $statusCode): void
    {
        if ($statusCode === 503) {
            $this->relayError503++;
        } elseif ($statusCode === 504) {
            $this->relayError504++;
        }
    }

    /**
     * Set the current FrameDecoder buffer size in bytes.
     *
     * @param int $bytes Current buffer byte length.
     *
     * @return void
     */
    public function setRelayDecodeBufferSize(int $bytes): void
    {
        $this->relayDecodeBufferBytes = $bytes;
    }

    /**
     * Drain and RESET all accumulated relay-specific metrics.
     *
     * Returns a snapshot of all relay counters and gauges; the accumulator
     * state is cleared after drain so the next period starts from zero.
     *
     * @param int $nowTs Unix timestamp of the drain.
     *
     * @return array{
     *     pending_requests: int,
     *     reply_drops: int,
     *     cancels: int,
     *     latency_histogram: array<int, array<int, int>>,
     *     error_503: int,
     *     error_504: int,
     *     decode_buffer_bytes: int
     * }
     */
    public function drainRelayMetrics(int $nowTs): array
    {
        $latencyHistogram = $this->relayLatencyHistogram;
        $snapshot = [
            'pending_requests' => $this->relayPendingRequests,
            'reply_drops' => $this->relayReplyDrops,
            'cancels' => $this->relayCancels,
            'latency_histogram' => $latencyHistogram,
            'error_503' => $this->relayError503,
            'error_504' => $this->relayError504,
            'decode_buffer_bytes' => $this->relayDecodeBufferBytes,
        ];

        $this->relayPendingRequests = 0;
        $this->relayReplyDrops = 0;
        $this->relayCancels = 0;
        $this->relayLatencyHistogram = [];
        $this->relayError503 = 0;
        $this->relayError504 = 0;
        $this->relayDecodeBufferBytes = 0;

        return $snapshot;
    }
}
