<?php

declare(strict_types=1);

namespace Phlex\Hub\Hub;

use InvalidArgumentException;
use Phlex\Hub\Common\Logger\StructuredLogger;
use Phlex\Shared\Hub\HeartbeatDto;
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
 * @package Phlex\Hub\Hub
 * @since 0.3.0
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
        $tokenKid = $this->extractKidFromToken($enrollmentJwt);
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
        $rows = $this->db->query(
            "SELECT id FROM servers WHERE id = :id FOR UPDATE",
            ['id' => $serverId],
        );

        if (empty($rows)) {
            throw new InvalidArgumentException('SERVER_NOT_FOUND');
        }

        $hostnameJson = json_encode($heartbeat->hostnameCandidates, JSON_THROW_ON_ERROR);

        $this->db->query(
            "UPDATE servers SET status = 'online', last_seen_at = :last_seen_at, version = :version,
             hostname_candidates_json = :hostname_candidates_json WHERE id = :id",
            [
                'last_seen_at' => $now,
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
                'received_at' => $now,
            ],
        );

        $this->logger->debug('Heartbeat received', [
            'server_id' => $serverId,
            'version' => $heartbeat->version,
        ]);
    }

    /**
     * Check whether a server is owned by a specific user.
     *
     * @param string $serverId Server UUID.
     * @param string $userId  User UUID.
     */
    public function isServerOwnedByUser(string $serverId, string $userId): bool
    {
        $rows = $this->db->query(
            "SELECT id FROM servers WHERE id = :id AND user_id = :user_id LIMIT 1",
            ['id' => $serverId, 'user_id' => $userId],
        );
        return !empty($rows);
    }

    /**
     * Extract the `kid` from a JWT header without validating the token.
     *
     * @param string $token The JWT to extract from.
     *
     * @return string|null Key ID or null when header is malformed.
     */
    private function extractKidFromToken(string $token): ?string
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        try {
            $decoded = base64_decode(strtr($parts[0], '-_', '+/'), true);
            if ($decoded === false) {
                return null;
            }
            /** @var array<string, mixed> $header */
            $header = json_decode($decoded, true, 2, JSON_THROW_ON_ERROR);
            return is_string($header['kid'] ?? null) ? $header['kid'] : null;
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * Generate a random UUID v4.
     */
    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
        );
    }
}
