<?php

/**
 * Phlix hub component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Http\Controllers;

use Phlix\Hub\Hub\AuditLogRepository;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Workerman\MySQL\Connection;

/**
 * Admin JSON API for the hub dashboard (hubby.md H1.4).
 *
 * Serves the `/api/v1/admin/dashboard/*` surface the redesigned shared
 * `@phlix/ui` admin console (`AdminHubDashboardApi`, the `HubDashboardPage`
 * client) calls. Two read-only endpoints aggregate hub-scoped metrics from the
 * existing tables — there is no hub-dashboard table to maintain:
 *
 *  - `GET /summary` — server fleet health (`servers`), live relay sessions
 *    (`relay_sessions`), the pending media-request queue (`requests`) and the
 *    registered-user count (`users`);
 *  - `GET /activity?limit=` — the most recent audit events as the dashboard's
 *    activity feed, reusing {@see AuditLogRepository::find()} (which already
 *    LEFT JOINs `users` to resolve a friendly actor name).
 *
 * The {@see Connection} is injected directly because the summary counters are
 * trivial aggregate `COUNT`s that do not warrant a dedicated repository; all
 * queries use named `:params` (never positional `?`, which breaks the hub's
 * `bindMore()`), and the only dynamic input — the activity `limit` — is parsed
 * to a clamped integer before it reaches a query.
 *
 * Both routes are gated by {@see \Phlix\Hub\Http\Middleware\AuthMiddleware} +
 * {@see \Phlix\Hub\Http\Middleware\AdminMiddleware} (wired in
 * {@see \Phlix\Hub\Application::registerAdminDashboardRoutes()}); this
 * controller therefore assumes the caller is already an authenticated admin.
 *
 * Responses use the shared `{ success, data }` envelope (matching the H1.2
 * settings API) with snake_case `data` keys; the shared client normalises both
 * snake_case and camelCase and degrades to zeros / an empty list on a malformed
 * payload, so these shapes are what it renders for real data.
 *
 * @package Phlix\Hub\Http\Controllers
 */
final class AdminDashboardController
{
    /** Default number of activity-feed events when no `limit` is given. */
    private const DEFAULT_ACTIVITY_LIMIT = 20;

    /** Upper bound on the activity-feed `limit` query parameter. */
    private const MAX_ACTIVITY_LIMIT = 100;

    /**
     * @param Connection         $db        Async MySQL connection for the summary counters.
     * @param AuditLogRepository $auditLogs Source of the recent-activity feed.
     */
    public function __construct(
        private readonly Connection $db,
        private readonly AuditLogRepository $auditLogs,
    ) {
    }

    /**
     * `GET /api/v1/admin/dashboard/summary` — hub-scoped headline counters.
     *
     * Returns `{ success: true, data: { servers: { total, online, offline },
     * active_relay_sessions, pending_requests, user_count } }`. `total` counts
     * every claimed server regardless of status; `online`/`offline` are the
     * live/down subsets (a `claiming`/`disabled` server is in neither).
     */
    public function summary(Request $request): Response
    {
        return (new Response())->json([
            'success' => true,
            'data' => [
                'servers' => $this->serverCounts(),
                'active_relay_sessions' => $this->scalarCount(
                    'SELECT COUNT(*) AS cnt FROM relay_sessions WHERE closed_at IS NULL',
                ),
                'pending_requests' => $this->scalarCount(
                    'SELECT COUNT(*) AS cnt FROM requests WHERE status = :status',
                    ['status' => 'pending'],
                ),
                'user_count' => $this->scalarCount('SELECT COUNT(*) AS cnt FROM users'),
            ],
        ]);
    }

    /**
     * `GET /api/v1/admin/dashboard/activity?limit=` — recent audit events.
     *
     * Returns `{ success: true, data: [ { id, action, actor, target,
     * created_at } ] }`, newest first. `limit` defaults to 20 and is clamped to
     * 1..100. Each entry is projected from an {@see AuditLogRepository} row: the
     * action prefers the explicit action tag and falls back to the event slug;
     * the actor prefers the joined display name/username and falls back to the
     * user id, else `"system"`; the target is the audited resource.
     */
    public function activity(Request $request): Response
    {
        $result = $this->auditLogs->find(['limit' => self::parseLimit($request->query['limit'] ?? null)]);

        $activity = array_map(
            static fn (array $entry): array => self::toActivity($entry),
            $result['entries'],
        );

        return (new Response())->json([
            'success' => true,
            'data' => $activity,
        ]);
    }

    /**
     * Count claimed servers split by heartbeat-derived status in one query.
     *
     * @return array{total: int, online: int, offline: int}
     */
    private function serverCounts(): array
    {
        /** @var mixed $rows */
        $rows = $this->db->query(
            'SELECT'
            . ' COUNT(*) AS total,'
            . " SUM(CASE WHEN status = 'online' THEN 1 ELSE 0 END) AS online,"
            . " SUM(CASE WHEN status = 'offline' THEN 1 ELSE 0 END) AS offline"
            . ' FROM servers',
        );

        $total = 0;
        $online = 0;
        $offline = 0;
        if (is_array($rows) && isset($rows[0]) && is_array($rows[0])) {
            /** @var array<string, mixed> $row */
            $row = $rows[0];
            $total = self::intOf($row['total'] ?? null);
            $online = self::intOf($row['online'] ?? null);
            $offline = self::intOf($row['offline'] ?? null);
        }

        return ['total' => $total, 'online' => $online, 'offline' => $offline];
    }

    /**
     * Run a single-row `COUNT(*) AS cnt` query and return the count.
     *
     * @param string                      $sql    SQL selecting a `cnt` column.
     * @param array<string, scalar|null>  $params Named bind parameters.
     */
    private function scalarCount(string $sql, array $params = []): int
    {
        /** @var mixed $rows */
        $rows = $this->db->query($sql, $params);
        if (is_array($rows) && isset($rows[0]) && is_array($rows[0])) {
            /** @var array<string, mixed> $row */
            $row = $rows[0];
            return self::intOf($row['cnt'] ?? null);
        }
        return 0;
    }

    /**
     * Project an {@see AuditLogRepository} entry onto the activity-feed shape
     * the dashboard client renders.
     *
     * @param array<string, mixed> $entry Reshaped audit-log entry.
     *
     * @return array{id: string, action: string, actor: string, target: string, created_at: string}
     */
    private static function toActivity(array $entry): array
    {
        $action = self::stringOf($entry['action'] ?? null);
        if ($action === '') {
            $action = self::stringOf($entry['event'] ?? null);
        }

        $actor = self::stringOf($entry['actor'] ?? null);
        if ($actor === '') {
            $actor = self::stringOf($entry['user_id'] ?? null);
        }
        if ($actor === '') {
            $actor = 'system';
        }

        return [
            'id' => self::stringOf($entry['id'] ?? null),
            'action' => $action,
            'actor' => $actor,
            'target' => self::stringOf($entry['resource'] ?? null),
            'created_at' => self::stringOf($entry['created_at'] ?? null),
        ];
    }

    /**
     * Parse the activity `limit` query parameter to a clamped integer.
     *
     * Non-numeric input falls back to {@see self::DEFAULT_ACTIVITY_LIMIT}; a
     * numeric value is clamped to 1..{@see self::MAX_ACTIVITY_LIMIT}. Takes
     * `mixed` so the raw query value is passed straight in, without an
     * intermediate assignment.
     */
    private static function parseLimit(mixed $raw): int
    {
        if (!is_numeric($raw)) {
            return self::DEFAULT_ACTIVITY_LIMIT;
        }
        return max(1, min(self::MAX_ACTIVITY_LIMIT, (int) $raw));
    }

    /** Coerce a `mixed` query value to int (non-numeric → 0). */
    private static function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /** Coerce a `mixed` value to string (non-string → empty string). */
    private static function stringOf(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
