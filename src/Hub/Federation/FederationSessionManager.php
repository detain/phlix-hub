<?php

declare(strict_types=1);

namespace Phlix\Hub\Federation;

use Phlix\Hub\Common\Logger\StructuredLogger;
use Workerman\MySQL\Connection;

/**
 * Manages federation sessions between hub peers.
 *
 * Responsibilities:
 *   - Register a new federation session when a peer connects
 *   - Track heartbeats and bytes sent/received per session
 *   - Close sessions gracefully
 *   - Reap stale dead sessions
 *
 * @package Phlix\Hub\Federation
 */
class FederationSessionManager
{
    public function __construct(
        private readonly Connection $db,
        private readonly StructuredLogger $logger,
    ) {
    }

    /**
     * Register a new federation session for a connected peer.
     *
     * @param string $peerId Peer UUID.
     *
     * @return string The session UUID.
     */
    public function registerSession(string $peerId): string
    {
        $sessionId = $this->generateUuid();

        $this->db->query(
            'INSERT INTO federation_sessions (id, peer_id)
             VALUES (:id, :peer_id)',
            [
                'id' => $sessionId,
                'peer_id' => $peerId,
            ],
        );

        $this->db->query(
            'UPDATE federation_peers SET last_connected_at = NOW(), status = :status
             WHERE id = :id',
            [
                'id' => $peerId,
                'status' => 'connected',
            ],
        );

        $this->logger->info('Federation session registered', [
            'session_id' => $sessionId,
            'peer_id' => $peerId,
        ]);

        return $sessionId;
    }

    /**
     * Touch the heartbeat timestamp and increment byte counters.
     *
     * @param string $sessionId Session UUID.
     *
     * @return void
     */
    public function touchHeartbeat(string $sessionId): void
    {
        $this->db->query(
            'UPDATE federation_sessions
             SET last_heartbeat_at = NOW(),
                 bytes_sent = bytes_sent + 1,
                 bytes_received = bytes_received + 1
             WHERE id = :id',
            ['id' => $sessionId],
        );
    }

    /**
     * Record bytes sent to a peer.
     *
     * @param string $sessionId Session UUID.
     * @param int    $bytes     Number of bytes sent.
     *
     * @return void
     */
    public function recordBytesOut(string $sessionId, int $bytes): void
    {
        $this->db->query(
            'UPDATE federation_sessions SET bytes_sent = bytes_sent + :bytes WHERE id = :id',
            [
                'bytes' => $bytes,
                'id' => $sessionId,
            ],
        );
    }

    /**
     * Record bytes received from a peer.
     *
     * @param string $sessionId Session UUID.
     * @param int    $bytes     Number of bytes received.
     *
     * @return void
     */
    public function recordBytesIn(string $sessionId, int $bytes): void
    {
        $this->db->query(
            'UPDATE federation_sessions SET bytes_received = bytes_received + :bytes WHERE id = :id',
            [
                'bytes' => $bytes,
                'id' => $sessionId,
            ],
        );
    }

    /**
     * Close a federation session and update peer status.
     *
     * @param string $sessionId Session UUID.
     *
     * @return void
     */
    public function closeSession(string $sessionId): void
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT peer_id FROM federation_sessions WHERE id = :id LIMIT 1',
            ['id' => $sessionId],
        );

        $peerId = $rows[0]['peer_id'] ?? null;

        $this->db->query(
            'UPDATE federation_sessions SET alive = 0 WHERE id = :id',
            ['id' => $sessionId],
        );

        if ($peerId !== null) {
            $this->db->query(
                'UPDATE federation_peers SET status = :status WHERE id = :id',
                [
                    'id' => $peerId,
                    'status' => 'disconnected',
                ],
            );
        }

        $this->logger->info('Federation session closed', [
            'session_id' => $sessionId,
            'peer_id' => $peerId,
        ]);
    }

    /**
     * Get the active session for a peer, if any.
     *
     * @param string $peerId Peer UUID.
     *
     * @return array<string, mixed>|null Session record or null.
     */
    public function getActiveSession(string $peerId): ?array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT * FROM federation_sessions
             WHERE peer_id = :peer_id AND alive = 1
             ORDER BY established_at DESC
             LIMIT 1',
            ['peer_id' => $peerId],
        );

        return $rows[0] ?? null;
    }

    /**
     * Reap sessions that have not received a heartbeat within the threshold.
     *
     * @param int $thresholdSeconds Sessions alive longer than this without heartbeat are reaped.
     *
     * @return int Number of sessions reaped.
     */
    public function reapDeadSessions(int $thresholdSeconds = 60): int
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT id, peer_id FROM federation_sessions
             WHERE alive = 1
               AND last_heartbeat_at < DATE_SUB(NOW(), INTERVAL :threshold SECOND)',
            ['threshold' => $thresholdSeconds],
        );

        $count = 0;
        foreach ($rows as $row) {
            /** @var mixed $rawSessionId */
            $rawSessionId = $row['id'] ?? null;
            /** @var mixed $rawPeerId */
            $rawPeerId = $row['peer_id'] ?? null;
            $sessionId = is_string($rawSessionId) ? $rawSessionId : '';
            $peerId = is_string($rawPeerId) ? $rawPeerId : '';

            $this->db->query(
                'UPDATE federation_sessions SET alive = 0 WHERE id = :id',
                ['id' => $sessionId],
            );

            $this->db->query(
                'UPDATE federation_peers SET status = :status WHERE id = :id',
                [
                    'id' => $peerId,
                    'status' => 'disconnected',
                ],
            );

            $count++;
        }

        if ($count > 0) {
            $this->logger->info('Reaped dead federation sessions', [
                'count' => $count,
                'threshold_seconds' => $thresholdSeconds,
            ]);
        }

        return $count;
    }

    /**
     * Generate a random UUID v4.
     *
     * @return string Formatted UUID string.
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
