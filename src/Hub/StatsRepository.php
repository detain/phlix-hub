<?php

/**
 * Phlix hub component: Hub.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Hub;

use Workerman\MySQL\Connection;

/**
 * Read-only dashboard counters (review hub Major 32).
 *
 * These were three bare `COUNT` queries plus a CASE-aggregate issued from
 * `AdminDashboardController` — the only controller in the estate-facing API
 * that touched SQL directly. The dashboard envelope is a projection of
 * {@see self::dashboardCounts()}; the controller does no query work itself,
 * matching how every other controller delegates to a repository.
 *
 * Note: the metrics tables these counters sit beside are written by several hub
 * processes under one shared `worker_id` ('hub-relay'); that row contention is
 * accepted (HUB-24, see MetricsFlushService and migration 033).
 *
 * All queries use named `:params` (never positional `?` — that breaks the
 * hub's `bindMore()`).
 *
 * @package Phlix\Hub\Hub
 */
final class StatsRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Every headline number the admin dashboard renders, in one read.
     *
     * `servers.total` counts every claimed server regardless of status;
     * `online`/`offline` are the live/down subsets (a `claiming`/`disabled`
     * server is in neither).
     *
     * @return array{
     *     servers: array{total: int, online: int, offline: int},
     *     active_relay_sessions: int,
     *     pending_requests: int,
     *     user_count: int
     * }
     */
    public function dashboardCounts(): array
    {
        return [
            'servers' => $this->serverCounts(),
            'active_relay_sessions' => $this->scalarCount(
                'SELECT COUNT(*) AS cnt FROM relay_sessions WHERE closed_at IS NULL',
            ),
            'pending_requests' => $this->scalarCount(
                'SELECT COUNT(*) AS cnt FROM requests WHERE status = :status',
                ['status' => 'pending'],
            ),
            'user_count' => $this->scalarCount('SELECT COUNT(*) AS cnt FROM users'),
        ];
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
     * @param string                     $sql    SQL selecting a `cnt` column.
     * @param array<string, scalar|null> $params Named bind parameters.
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

    /** Coerce a `mixed` query value to int (non-numeric → 0). */
    private static function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
