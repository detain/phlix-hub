<?php

/**
 * Phlix hub component: Federation.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Federation;

use Phlix\Hub\Common\Support\Ids;
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
 *   - Hold the single-use HELLO_ACK nonce of each pending handshake (H-4):
 *     the master mints a fresh nonce per session at HELLO, and consumes it
 *     exactly once when verifying the leaf's HELLO_AUTH proof. A replayed
 *     proof therefore has no live nonce left to satisfy.
 *
 * @package Phlix\Hub\Federation
 */
class FederationSessionManager
{
    /**
     * session UUID → pending handshake state, in-process (master side only).
     *
     * Lives here — not in the frame handler — because the handshake is
     * session-scoped state, and this manager is the session lifecycle owner.
     * Bounded: at most one entry per peer (beginHandshake prunes the peer's
     * earlier attempts), and every teardown path abandons the peer's entry.
     *
     * @var array<string, array{peer_id: string, nonce: string}>
     */
    private array $pendingHandshakes = [];

    public function __construct(
        private readonly Connection $db,
        private readonly StructuredLogger $logger,
    ) {
    }

    /**
     * Register a new federation session for a connected peer.
     *
     * Any previously-live session row for the same peer is marked dead first:
     * a re-hello after a crash must not leave the old row `alive = 1`
     * forever (L-6 hygiene — orphan live sessions masked the real state).
     *
     * @param string $peerId Peer UUID.
     *
     * @return string The session UUID.
     */
    public function registerSession(string $peerId): string
    {
        $sessionId = $this->generateUuid();

        // A fresh session supersedes any handshake still pending under an
        // older attempt for this peer (re-hello without the old conn having
        // been closed out yet).
        $this->abandonHandshakesForPeer($peerId);

        $this->db->query(
            'UPDATE federation_sessions SET alive = 0 WHERE peer_id = :peer_id AND alive = 1',
            ['peer_id' => $peerId],
        );

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
     * Touch the heartbeat of the peer's LIVE session, addressed by peer.
     *
     * H-2: callers on the frame paths only know the PEER identity (the WS
     * route/HELLO carries hub UUIDs, never the session UUID minted by
     * registerSession). The old code fed a hub UUID to touchHeartbeat(),
     * whose WHERE clause is `id = :id`, so every heartbeat updated 0 rows
     * and the reaper killed live links after 60s.
     *
     * @param string $peerId Peer UUID whose alive session should be refreshed.
     *
     * @return bool True when a live session row was refreshed.
     */
    public function touchHeartbeatByPeerId(string $peerId): bool
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT id FROM federation_sessions
             WHERE peer_id = :peer_id AND alive = 1
             ORDER BY established_at DESC
             LIMIT 1',
            ['peer_id' => $peerId],
        );

        $rawSessionId = $rows[0]['id'] ?? null;
        if (!is_string($rawSessionId) || $rawSessionId === '') {
            return false;
        }

        $this->touchHeartbeat($rawSessionId);

        return true;
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

        /** @var mixed $peerId */
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

        // The session is gone — its single-use handshake nonce goes with it.
        unset($this->pendingHandshakes[$sessionId]);

        $this->logger->info('Federation session closed', [
            'session_id' => $sessionId,
            'peer_id' => $peerId,
        ]);
    }

    /**
     * Record the single-use HELLO_ACK nonce minted for a fresh handshake (H-4).
     *
     * Called by the master immediately before sending the signed HELLO_ACK;
     * the nonce lives in the pending-handshake state until {@see consumeHandshake()}
     * verifies the leaf's HELLO_AUTH proof exactly once.
     *
     * @param string $sessionId Session UUID this handshake belongs to.
     * @param string $peerId    Local peer row UUID the session addresses.
     * @param string $nonce     Fresh nonce embedded in the signed HELLO_ACK.
     *
     * @return void
     */
    public function beginHandshake(string $sessionId, string $peerId, string $nonce): void
    {
        $this->abandonHandshakesForPeer($peerId);

        $this->pendingHandshakes[$sessionId] = [
            'peer_id' => $peerId,
            'nonce' => $nonce,
        ];
    }

    /**
     * Consume a session's pending handshake exactly once (H-4).
     *
     * The entry is removed whether or not the caller's proof later verifies,
     * which is what makes a replayed HELLO_AUTH fail: there is no second read
     * of a live nonce.
     *
     * @param string $sessionId Session UUID carried by the leaf's HELLO_AUTH.
     *
     * @return array{peer_id: string, nonce: string}|null Pending state, or null
     *                                                     when no handshake is pending.
     */
    public function consumeHandshake(string $sessionId): ?array
    {
        $pending = $this->pendingHandshakes[$sessionId] ?? null;

        unset($this->pendingHandshakes[$sessionId]);

        return $pending;
    }

    /**
     * Drop every pending handshake belonging to a peer (teardown / supersede).
     *
     * @param string $peerId Local peer row UUID.
     *
     * @return void
     */
    public function abandonHandshakesForPeer(string $peerId): void
    {
        foreach ($this->pendingHandshakes as $sessionId => $state) {
            if ($state['peer_id'] === $peerId) {
                unset($this->pendingHandshakes[$sessionId]);
            }
        }
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
        return Ids::uuidV4();
    }
}
