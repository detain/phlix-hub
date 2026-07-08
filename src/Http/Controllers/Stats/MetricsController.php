<?php

/**
 * Phlix hub component: Stats.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Http\Controllers\Stats;

use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Hub\Stats\Metrics\MetricsRepositoryInterface;

/**
 * JSON API for metrics endpoints (S4).
 *
 * All routes are wired under `/api/v1/admin/metrics/` with
 * {@see \Phlix\Hub\Http\Middleware\AdminMiddleware} in front.
 *
 * Endpoints:
 *
 *  - `GET /api/v1/admin/metrics/snapshot`     → current metrics snapshot
 *  - `GET /api/v1/admin/metrics/history`      → historical metrics time-series
 *  - `GET /api/v1/admin/metrics/connections` → live tunnel connections
 *  - `GET /api/v1/admin/metrics/routes`       → top routes by request count
 *
 * @package Phlix\Hub\Http\Controllers\Stats
 * @since S4
 */
final class MetricsController
{
    public function __construct(
        private readonly MetricsRepositoryInterface $repo,
    ) {
    }

    /**
     * Get a snapshot of current metrics.
     *
     * `GET /api/v1/admin/metrics/snapshot?window=60` →
     * `200 { "success": true, "data": { bytes_in_per_sec, bytes_out_per_sec,
     *   active_connections, requests_per_sec, error_rate, p50_ms, p95_ms, p99_ms } }`
     *
     * @param Request              $request The HTTP request (query.window seconds)
     * @param array<string,string>  $params  Path parameters (unused)
     *
     * @return Response JSON-encoded metrics snapshot
     */
    public function snapshot(Request $request, array $params): Response
    {
        $window = max(1, $this->parseInt($request->query['window'] ?? null, 60));
        $data = $this->repo->snapshot($window);

        return (new Response())->json(['success' => true, 'data' => $data]);
    }

    /**
     * Get historical metrics time-series.
     *
     * `GET /api/v1/admin/metrics/history?minutes=60&resolution=60` →
     * `200 { "success": true, "data": [{ bucket, bytes_in, bytes_out, requests,
     *   errors, p50_ms, p95_ms }, ...] }`
     *
     * @param Request              $request The HTTP request
     *                                       (query.minutes, query.resolution)
     * @param array<string,string>  $params  Path parameters (unused)
     *
     * @return Response JSON-encoded metrics history
     */
    public function history(Request $request, array $params): Response
    {
        $minutes    = max(1, $this->parseInt($request->query['minutes'] ?? null, 60));
        $resolution = max(1, $this->parseInt($request->query['resolution'] ?? null, 60));
        $data = $this->repo->history($minutes, $resolution);

        return (new Response())->json(['success' => true, 'data' => $data]);
    }

    /**
     * Get live tunnel connections.
     *
     * `GET /api/v1/admin/metrics/connections?ttl=15` →
     * `200 { "success": true, "data": [{ id, kind, user_id, remote_ip,
     *   opened_at, last_seen_at }, ...] }`
     *
     * @param Request              $request The HTTP request (query.ttl seconds)
     * @param array<string,string>  $params  Path parameters (unused)
     *
     * @return Response JSON-encoded live connections
     */
    public function connections(Request $request, array $params): Response
    {
        $ttl = max(1, $this->parseInt($request->query['ttl'] ?? null, 15));
        $data = $this->repo->liveConnections($ttl);

        return (new Response())->json(['success' => true, 'data' => $data]);
    }

    /**
     * Get top routes by request count.
     *
     * `GET /api/v1/admin/metrics/routes?minutes=15&limit=20` →
     * `200 { "success": true, "data": [{ route, method, request_count,
     *   error_count, avg_ms, max_ms }, ...] }`
     *
     * @param Request              $request The HTTP request
     *                                       (query.minutes, query.limit)
     * @param array<string,string>  $params  Path parameters (unused)
     *
     * @return Response JSON-encoded top routes
     */
    public function routes(Request $request, array $params): Response
    {
        $minutes = max(1, $this->parseInt($request->query['minutes'] ?? null, 15));
        $limit = max(1, $this->parseInt($request->query['limit'] ?? null, 20));
        $data = $this->repo->topRoutes($minutes, $limit);

        return (new Response())->json(['success' => true, 'data' => $data]);
    }

    /**
     * Parse an integer from a mixed input with a default fallback.
     *
     * @param mixed $input
     * @param int   $default
     *
     * @return int
     */
    private function parseInt(mixed $input, int $default): int
    {
        if (!is_scalar($input)) {
            return $default;
        }
        $parsed = filter_var((string) $input, FILTER_VALIDATE_INT);
        return $parsed !== false ? $parsed : $default;
    }
}
