<?php

declare(strict_types=1);

namespace Phlex\Hub\Hub;

use Phlex\Shared\Hub\ServerInfoDto;
use Workerman\MySQL\Connection;

/**
 * Returns server info for hub dashboard and API consumers.
 *
 * @package Phlex\Hub\Hub
 * @since 0.3.0
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
        $rows = $this->db->query(
            'SELECT id, user_id, server_name, version, last_seen_at, status,
                    hostname_candidates_json, created_at
             FROM servers WHERE id = :id LIMIT 1',
            ['id' => $serverId],
        );

        if (!isset($rows[0])) {
            return null;
        }

        return $this->rowToDto($rows[0]);
    }

    /**
     * Get all servers owned by a user.
     *
     * @param string $userId User UUID.
     *
     * @return list<ServerInfoDto>
     */
    public function getServersForUser(string $userId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT id, user_id, server_name, version, last_seen_at, status,
                    hostname_candidates_json, created_at
             FROM servers
             WHERE user_id = :user_id
             ORDER BY created_at DESC',
            ['user_id' => $userId],
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

        /** @var string */
        $serverId = is_string($row['id'] ?? null) ? $row['id'] : '';
        /** @var string */
        $userId = is_string($row['user_id'] ?? null) ? $row['user_id'] : '';
        /** @var string */
        $serverName = is_string($row['server_name'] ?? null) ? $row['server_name'] : '';
        /** @var string */
        $version = is_string($row['version'] ?? null) ? $row['version'] : '';
        /** @var string */
        $status = is_string($row['status'] ?? null) ? $row['status'] : 'offline';

        return new ServerInfoDto(
            serverId: $serverId,
            userId: $userId,
            serverName: $serverName,
            version: $version,
            lastSeenAt: $lastSeenAt,
            status: $status,
            hostnameCandidates: $hostnames,
            relayActive: false,
        );
    }
}
