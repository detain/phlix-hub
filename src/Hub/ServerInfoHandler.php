<?php

/**
 * Phlix hub component: Hub.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Hub;

use Phlix\Shared\Hub\ServerInfoDto;
use Workerman\MySQL\Connection;

/**
 * Returns server info for hub dashboard and API consumers.
 *
 * @package Phlix\Hub\Hub
 */
class ServerInfoHandler
{
    /**
     * @param Connection $db MySQL connection.
     */
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    /**
     * Get info for a single server.
     *
     * @param string $serverId Server UUID.
     *
     * @return ServerInfoDto|null Server info DTO or null when not found.
     */
    public function getServerInfo(string $serverId): ?ServerInfoDto
    {
        /** @var list<array<string, mixed>> $rows */
        // `relay_active` here is derived from the open-session bookkeeping table
        // for DISPLAY only (dashboard / server-detail badge). It can lag the
        // truth after a relay-worker crash/restart, so it MUST NOT be treated as
        // authorization to forward a proxy request: the authoritative liveness
        // gate is the in-memory tunnel registry, cross-checked at admission by
        // {@see \Phlix\Hub\Relay\RelayProxyManager::onRequest()} (no live tunnel
        // → fast 503 `server.no_tunnel`). Step B7 also reconciles stale open
        // rows on relay-worker start so this flag re-converges.
        $rows = $this->db->query(
            'SELECT s.id, s.user_id, s.server_name, s.version,
                    UNIX_TIMESTAMP(s.last_seen_at) AS last_seen_at, s.status,
                    s.hostname_candidates_json, s.created_at, s.subdomain,
                    EXISTS(
                        SELECT 1 FROM relay_sessions r
                        WHERE r.server_id = s.id AND r.closed_at IS NULL
                    ) AS relay_active,
                    (
                        SELECT COUNT(*) FROM server_libraries sl
                        WHERE sl.server_id = s.id
                    ) AS library_count
             FROM servers s WHERE s.id = :id LIMIT 1',
            ['id' => $serverId],
        );

        if (!isset($rows[0])) {
            return null;
        }

        return $this->rowToDto($rows[0]);
    }

    /**
     * Get the subdomain for a server.
     *
     * @param string $serverId Server UUID.
     *
     * @return string|null Subdomain label or null when not found/no subdomain.
     */
    public function getServerSubdomain(string $serverId): ?string
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT subdomain FROM servers WHERE id = :id LIMIT 1',
            ['id' => $serverId],
        );

        if (!isset($rows[0])) {
            return null;
        }

        /** @var string|null */
        return is_string($rows[0]['subdomain'] ?? null) ? $rows[0]['subdomain'] : null;
    }

    /**
     * Get all servers owned by a user.
     *
     * @param string   $userId User UUID.
     * @param int|null  $limit  Maximum number of results to return (default 1000).
     * @param int       $offset Number of results to skip (default 0).
     *
     * @return list<ServerInfoDto>
     */
    public function getServersForUser(string $userId, ?int $limit = 1000, int $offset = 0): array
    {
        if ($limit < 0) {
            $limit = 1000;
        }
        if ($offset < 0) {
            $offset = 0;
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT s.id, s.user_id, s.server_name, s.version,
                    UNIX_TIMESTAMP(s.last_seen_at) AS last_seen_at, s.status,
                    s.hostname_candidates_json, s.created_at,
                    (r.server_id IS NOT NULL) AS relay_active,
                    COUNT(DISTINCT sl.id) AS library_count
             FROM servers s
             LEFT JOIN relay_sessions r ON r.server_id = s.id AND r.closed_at IS NULL
             LEFT JOIN server_libraries sl ON sl.server_id = s.id
             WHERE s.user_id = :user_id
             GROUP BY s.id, s.user_id, s.server_name, s.version,
                      s.last_seen_at, s.status, s.hostname_candidates_json, s.created_at
             ORDER BY s.created_at DESC
             LIMIT :limit OFFSET :offset',
            ['user_id' => $userId, 'limit' => $limit, 'offset' => $offset],
        );

        $dtos = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed> $typedRow */
            $typedRow = $row;
            $dtos[] = $this->rowToDto($typedRow);
        }
        return $dtos;
    }

    /**
     * Convert a DB row to a ServerInfoDto.
     *
     * @param array<string, mixed> $row
     */
    private function rowToDto(array $row): ServerInfoDto
    {
        /** @var string */
        $hostnameJson = is_string($row['hostname_candidates_json'] ?? null) ? $row['hostname_candidates_json'] : '[]';
        /** @var list<string> */
        $hostnames = json_decode($hostnameJson, true) ?? [];

        $lastSeenAt = null;
        /** @var mixed */
        $lastSeenRaw = $row['last_seen_at'] ?? null;
        if (is_numeric($lastSeenRaw)) {
            $lastSeenAt = (int) $lastSeenRaw;
        }

        if (!is_string($row['id'] ?? null)) {
            throw new \RuntimeException('ServerInfoHandler: row missing or null server id');
        }
        if (!is_string($row['user_id'] ?? null)) {
            throw new \RuntimeException('ServerInfoHandler: row missing or null user_id');
        }

        /** @var string */
        $serverId = $row['id'];
        /** @var string */
        $userId = $row['user_id'];

        /** @var string */
        $serverName = is_string($row['server_name'] ?? null) ? $row['server_name'] : '';
        /** @var string */
        $version = is_string($row['version'] ?? null) ? $row['version'] : '';
        /** @var string */
        $status = is_string($row['status'] ?? null) ? $row['status'] : 'offline';

        /** @var mixed */
        $relayActiveRaw = $row['relay_active'] ?? false;
        $relayActive = is_numeric($relayActiveRaw) ? (int) $relayActiveRaw === 1 : (bool) $relayActiveRaw;

        $libraryCount = is_numeric($row['library_count'] ?? null) ? (int) $row['library_count'] : null;

        return new ServerInfoDto(
            serverId: $serverId,
            userId: $userId,
            serverName: $serverName,
            version: $version,
            lastSeenAt: $lastSeenAt,
            status: $status,
            hostnameCandidates: $hostnames,
            relayActive: $relayActive,
            libraryCount: $libraryCount,
        );
    }
}
