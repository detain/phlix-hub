<?php

/**
 * Phlix hub component: Hub.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Hub;

use Phlix\Hub\Common\Support\Ids;
use InvalidArgumentException;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Jwt\JwtHeader;
use Phlix\Shared\Hub\HeartbeatDto;
use Phlix\Shared\Hub\LibraryRef;
use Workerman\MySQL\Connection;

/**
 * Processes server heartbeat messages from enrolled servers.
 *
 * On `handle`:
 *   1. Validates the enrollment JWT (signature + expiry).
 *   2. Finds the server by serverId.
 *   3. Updates servers.last_seen_at, status='online', version,
 *      hostname_candidates_json, heartbeat_interval.
 *
 * @package Phlix\Hub\Hub
 */
class HeartbeatHandler
{
    /**
     * @param Connection           $db         MySQL connection.
     * @param EnrollmentJwtService $jwtService JWT validation service.
     * @param StructuredLogger     $logger     Application logger.
     */
    public function __construct(
        private readonly Connection $db,
        private readonly EnrollmentJwtService $jwtService,
        private readonly StructuredLogger $logger,
    ) {
    }

    /**
     * Process a heartbeat from a server.
     *
     * @param string      $serverId       Server UUID from the hub.
     * @param string      $enrollmentJwt  The server's enrollment JWT.
     * @param HeartbeatDto $heartbeat      Heartbeat payload.
     *
     * @throws InvalidArgumentException When JWT is invalid (401) or server not found (404).
     */
    public function handle(string $serverId, string $enrollmentJwt, HeartbeatDto $heartbeat): void
    {
        $tokenKid = JwtHeader::kid($enrollmentJwt);
        if ($tokenKid === null) {
            throw new InvalidArgumentException('ENROLLMENT_TOKEN_EXPIRED');
        }
        $payload = $this->jwtService->validateEnrollmentJwt($enrollmentJwt, $tokenKid);
        if ($payload === null) {
            throw new InvalidArgumentException('ENROLLMENT_TOKEN_EXPIRED');
        }

        if (($payload['server_id'] ?? '') !== $serverId) {
            throw new InvalidArgumentException('SERVER_NOT_FOUND');
        }

        $now = time();
        $hostnameJson = json_encode($heartbeat->hostnameCandidates, JSON_THROW_ON_ERROR);

        // Wrap the SELECT … FOR UPDATE existence check and the dependent
        // UPDATE/INSERT in an explicit transaction. Under autocommit the
        // FOR UPDATE row lock would be released the instant the SELECT
        // returned, so it could not actually serialise a concurrent
        // deregister/update of the same server against this heartbeat;
        // PhlixMySQLConnection::beginTrans() additionally holds the
        // per-connection coroutine mutex for the whole transaction so no
        // other coroutine can interleave a query onto the shared socket
        // between the existence check and the writes.
        $this->db->beginTrans();
        try {
            /** @var list<array<string, mixed>> $rows */
            $rows = $this->db->query(
                'SELECT id FROM servers WHERE id = :id FOR UPDATE',
                ['id' => $serverId],
            );

            if (empty($rows)) {
                throw new InvalidArgumentException('SERVER_NOT_FOUND');
            }

            $this->db->query(
                "UPDATE servers SET status = 'online', last_seen_at = :last_seen_at, version = :version,
                 hostname_candidates_json = :hostname_candidates_json WHERE id = :id",
                [
                    // servers.last_seen_at is a DATETIME column.
                    'last_seen_at' => date('Y-m-d H:i:s', $now),
                    'version' => $heartbeat->version,
                    'hostname_candidates_json' => $hostnameJson,
                    'id' => $serverId,
                ],
            );

            $this->db->query(
                "INSERT INTO server_heartbeats (id, server_id, version, uptime_seconds, active_sessions,
                 active_transcodes, hostname_candidates_json, received_at)
                 VALUES (:id, :server_id, :version, :uptime_seconds, :active_sessions, :active_transcodes,
                 :hostname_candidates_json, :received_at)",
                [
                    'id' => $this->generateUuid(),
                    'server_id' => $serverId,
                    'version' => $heartbeat->version,
                    'uptime_seconds' => $heartbeat->uptimeSeconds,
                    'active_sessions' => $heartbeat->activeSessions,
                    'active_transcodes' => $heartbeat->activeTranscodes,
                    'hostname_candidates_json' => $hostnameJson,
                    // server_heartbeats.received_at is a DATETIME column.
                    'received_at' => date('Y-m-d H:i:s', $now),
                ],
            );

            // Store libraries reported by the server (same transaction so the
            // cached library list is consistent with the heartbeat row).
            if (!empty($heartbeat->libraries)) {
                $this->updateServerLibraries($serverId, $heartbeat->libraries);
            }

            $this->db->commitTrans();
        } catch (\Throwable $e) {
            $this->db->rollBackTrans();
            throw $e;
        }

        $this->logger->debug('Heartbeat received', [
            'server_id' => $serverId,
            'version' => $heartbeat->version,
        ]);
    }

    /**
     * Update the cached libraries for a server.
     *
     * @param string             $serverId  Server UUID.
     * @param list<LibraryRef>    $libraries Library list.
     */
    private function updateServerLibraries(string $serverId, array $libraries): void
    {
        foreach ($libraries as $ref) {
            // Upsert each library
            $this->db->query(
                'INSERT INTO server_libraries (id, server_id, library_id, library_name, created_at, updated_at)
                 VALUES (:id, :server_id, :library_id, :library_name, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE library_name = :library_name_update, updated_at = NOW()',
                [
                    'id' => $this->generateUuid(),
                    'server_id' => $serverId,
                    'library_id' => $ref->libraryId,
                    'library_name' => $ref->libraryName,
                    'library_name_update' => $ref->libraryName,
                ],
            );
        }
    }

    /**
     * Check whether a server is owned by a specific user.
     *
     * @param string $serverId Server UUID.
     * @param string $userId  User UUID.
     */
    public function isServerOwnedByUser(string $serverId, string $userId): bool
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT id FROM servers WHERE id = :id AND user_id = :user_id LIMIT 1',
            ['id' => $serverId, 'user_id' => $userId],
        );
        return !empty($rows);
    }

    /**
     * Get recent heartbeat history for a server.
     *
     * @param string $serverId Server UUID.
     * @param int    $limit    Maximum number of rows to return.
     *
     * @return list<array<string, mixed>>
     */
    public function getHeartbeatHistory(string $serverId, int $limit = 20): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT id, version, uptime_seconds, active_sessions, active_transcodes, received_at
             FROM server_heartbeats
             WHERE server_id = :server_id
             ORDER BY received_at DESC
             LIMIT :limit',
            ['server_id' => $serverId, 'limit' => $limit],
        );

        /** @var list<array<string, mixed>> $result */
        $result = [];
        $int = fn (mixed $v): int => is_numeric($v) ? (int) $v : 0;
        foreach ($rows as $row) {
            /** @var array<string, mixed> $typedRow */
            $typedRow = $row;
            $result[] = [
                'id'               => $typedRow['id'],
                'version'         => $typedRow['version'],
                'uptime_seconds'  => $int($typedRow['uptime_seconds'] ?? ''),
                'active_sessions' => $int($typedRow['active_sessions'] ?? ''),
                'active_transcodes' => $int($typedRow['active_transcodes'] ?? ''),
                'received_at'     => $int($typedRow['received_at'] ?? ''),
            ];
        }
        return $result;
    }

    /**
     * Generate a random UUID v4.
     */
    private function generateUuid(): string
    {
        return Ids::uuidV4();
    }
}
