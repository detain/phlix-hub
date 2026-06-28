<?php

declare(strict_types=1);

namespace Phlix\Hub\Hub;

use Phlix\Hub\Common\Support\Ids;
use InvalidArgumentException;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Workerman\MySQL\Connection;

/**
 * Manages relay sessions between the hub and enrolled servers.
 *
 * Responsibilities:
 *   - Register a new relay session when a server connects
 *   - Route an inbound HTTP request to the correct server via its relay session
 *   - Track bytes sent/received per session
 *   - Close a relay session when the server disconnects
 *
 * @package Phlix\Hub\Hub
 */
class RelaySessionManager
{
    /**
     * @param Connection       $db     MySQL connection.
     * @param StructuredLogger $logger Application logger.
     */
    public function __construct(
        private readonly Connection $db,
        private readonly StructuredLogger $logger,
    ) {
    }

    /**
     * Register a new relay session for a connected server.
     *
     * @param string $serverId Hub-assigned server UUID.
     * @param string $workerNode Identifier of the Workerman worker handling this connection.
     *
     * @return string The relay session UUID.
     *
     * @throws InvalidArgumentException When server is not found (404).
     *
     */
    public function registerServer(string $serverId, string $workerNode): string
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT id FROM servers WHERE id = :id LIMIT 1',
            ['id' => $serverId],
        );

        if (empty($rows)) {
            throw new InvalidArgumentException('SERVER_NOT_FOUND');
        }

        // Supersede any prior open session(s) for this server before opening a
        // new one. closeSession() is not always reached (worker restart, dropped
        // connection), which left orphaned open rows accumulating in
        // relay_sessions. Enforcing <= 1 open session per server keeps the
        // dashboard's "active relays" count converged on the number of
        // connected servers.
        $this->db->query(
            'UPDATE relay_sessions SET closed_at = NOW(), close_reason = :reason
             WHERE server_id = :server_id AND closed_at IS NULL',
            [
                'reason' => 'superseded',
                'server_id' => $serverId,
            ],
        );

        $sessionId = $this->generateUuid();

        $this->db->query(
            'INSERT INTO relay_sessions (id, server_id, worker_node, opened_at, bytes_in, bytes_out)
             VALUES (:id, :server_id, :worker_node, NOW(), 0, 0)',
            [
                'id' => $sessionId,
                'server_id' => $serverId,
                'worker_node' => $workerNode,
            ],
        );

        $this->logger->info('Relay session registered', [
            'session_id' => $sessionId,
            'server_id' => $serverId,
            'worker_node' => $workerNode,
        ]);

        return $sessionId;
    }

    /**
     * Route an inbound HTTP request to the server via its relay session.
     *
     * Returns the relay session record if the server is connected, or null if no
     * active session exists for this server.
     *
     * @param string $serverId   The target server UUID.
     * @param string $method     HTTP method.
     * @param string $path       HTTP request path.
     * @param array<string, string> $headers HTTP headers.
     * @param string $body       HTTP request body.
     *
     * @return array<string, mixed>|null Relay session record or null if not connected.
     *
     */
    public function routeRequest(
        string $serverId,
        string $method,
        string $path,
        array $headers,
        string $body,
    ): ?array {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT rs.*, s.status FROM relay_sessions rs
             JOIN servers s ON s.id = rs.server_id
             WHERE rs.server_id = :server_id AND rs.closed_at IS NULL
             LIMIT 1',
            ['server_id' => $serverId],
        );

        if (empty($rows)) {
            return null;
        }

        /** @var array<string, mixed> $session */
        $session = $rows[0];

        $bodyLen = strlen($body);
        $this->db->query(
            'UPDATE relay_sessions SET bytes_in = bytes_in + :bytes_in,
             last_frame_at = UNIX_TIMESTAMP() WHERE id = :id',
            [
                'bytes_in' => $bodyLen,
                'id' => $session['id'],
            ],
        );

        return $session;
    }

    /**
     * Record bytes sent to a server over its relay session.
     *
     * @param string $sessionId Relay session UUID.
     * @param int    $bytes     Number of bytes sent.
     *
     * @return void
     *
     */
    public function recordBytesOut(string $sessionId, int $bytes): void
    {
        $this->db->query(
            'UPDATE relay_sessions SET bytes_out = bytes_out + :bytes,
             last_frame_at = UNIX_TIMESTAMP() WHERE id = :id',
            [
                'bytes' => $bytes,
                'id' => $sessionId,
            ],
        );
    }

    /**
     * Close a relay session.
     *
     * @param string $sessionId   Relay session UUID.
     * @param string $reason       Human-readable close reason.
     *
     * @return void
     *
     */
    public function closeSession(string $sessionId, string $reason): void
    {
        $this->db->query(
            'UPDATE relay_sessions SET closed_at = NOW(), close_reason = :reason
             WHERE id = :id',
            [
                'reason' => $reason,
                'id' => $sessionId,
            ],
        );

        $this->logger->info('Relay session closed', [
            'session_id' => $sessionId,
            'reason' => $reason,
        ]);
    }

    /**
     * Close open relay sessions that have had no recent frame activity.
     *
     * A live tunnel refreshes `last_frame_at` (via routeRequest, recordBytes*
     * and touchLastFrame and the 30s heartbeat timer) well within this threshold,
     * so genuinely connected servers are never reaped. This sweeps up orphaned
     * open rows left behind when closeSession() was not reached (worker
     * restart, dropped connection), keeping the dashboard's "active relays"
     * count accurate. Multi-worker-safe: the single relay worker owns sessions
     * and the UPDATE is idempotent.
     *
     * `last_frame_at` is stored as unix seconds; `opened_at` is a DATETIME, so
     * it is wrapped in UNIX_TIMESTAMP() for sessions that never sent a frame.
     *
     * @param int $thresholdSeconds Sessions idle longer than this are closed.
     *
     * @return int Number of sessions closed (best-effort; 0 if not obtainable).
     */
    public function reapStaleSessions(int $thresholdSeconds = 180): int
    {
        /** @var mixed $result */
        $result = $this->db->query(
            "UPDATE relay_sessions SET closed_at = NOW(), close_reason = 'stale'
             WHERE closed_at IS NULL
               AND COALESCE(last_frame_at, UNIX_TIMESTAMP(opened_at)) < (UNIX_TIMESTAMP() - :threshold)",
            ['threshold' => $thresholdSeconds],
        );

        $closed = is_numeric($result) ? (int) $result : 0;

        if ($closed > 0) {
            $this->logger->info('Relay: reaped stale sessions', [
                'closed' => $closed,
                'threshold_seconds' => $thresholdSeconds,
            ]);
        }

        return $closed;
    }

    /**
     * Reconcile open relay sessions against the live in-memory tunnel registry.
     *
     * The `relay_sessions` table (and the `relay_active` flag derived from it)
     * is only a display/bookkeeping mirror of the authoritative signal — the
     * in-memory tunnel registry owned by the single relay worker. When the relay
     * worker crashes/restarts, {@see closeSession()} is never reached, so the
     * open rows it left behind are orphans: a stale `relay_active=1` with no live
     * tunnel behind it. Calling this on relay-worker start (when the registry is
     * the source of truth) closes every open session whose `server_id` is NOT
     * currently backed by a live tunnel, so the DB flag stops authorizing a
     * forward that would only 504.
     *
     * `$liveServerIds` is the set of server UUIDs with a live tunnel right now
     * (empty at a fresh worker start, where every open row is therefore an
     * orphan). The DELETE-free UPDATE marks rows closed with `close_reason`,
     * preserving byte-accounting history. Colon-free named placeholders are used
     * for the IN-list (workerman/mysql prepends `:`).
     *
     * @param list<string> $liveServerIds Server UUIDs with a live tunnel.
     * @param string       $reason        Close reason recorded on each orphan.
     *
     * @return int Number of orphan sessions closed (0 if not obtainable).
     */
    public function closeOrphanedSessions(array $liveServerIds, string $reason = 'orphaned'): int
    {
        $params = ['reason' => $reason];
        $exclusion = '';

        if ($liveServerIds !== []) {
            $placeholders = [];
            foreach (array_values(array_unique($liveServerIds)) as $i => $serverId) {
                $key = 'live_' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = $serverId;
            }
            $exclusion = ' AND server_id NOT IN (' . implode(', ', $placeholders) . ')';
        }

        /** @var mixed $result */
        $result = $this->db->query(
            'UPDATE relay_sessions SET closed_at = NOW(), close_reason = :reason
             WHERE closed_at IS NULL' . $exclusion,
            $params,
        );

        $closed = is_numeric($result) ? (int) $result : 0;

        if ($closed > 0) {
            $this->logger->info('Relay: reconciled orphaned sessions on worker start', [
                'closed' => $closed,
                'live_tunnels' => count($liveServerIds),
                'reason' => $reason,
            ]);
        }

        return $closed;
    }

    /**
     * Get the active relay session for a server, if any.
     *
     * @param string $serverId Server UUID.
     *
     * @return array<string, mixed>|null Session record or null.
     *
     */
    public function getActiveSession(string $serverId): ?array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT * FROM relay_sessions
             WHERE server_id = :server_id AND closed_at IS NULL
             LIMIT 1',
            ['server_id' => $serverId],
        );

        return $rows[0] ?? null;
    }

    /**
     * Record bytes sent from a client to the server through the tunnel.
     *
     * @param string $sessionId Relay session UUID.
     * @param int    $bytes     Number of bytes received.
     *
     * @return void
     *
     */
    public function recordBytesIn(string $sessionId, int $bytes): void
    {
        $this->db->query(
            'UPDATE relay_sessions SET bytes_in = bytes_in + :bytes,
             last_frame_at = UNIX_TIMESTAMP() WHERE id = :id',
            [
                'bytes' => $bytes,
                'id' => $sessionId,
            ],
        );
    }

    /**
     * Touch the last_frame_at timestamp without changing byte counts.
     *
     * Used for HEARTBEAT frames where we want to update activity but
     * not count the heartbeat as data traffic.
     *
     * @param string $sessionId Relay session UUID.
     *
     * @return void
     *
     */
    public function touchLastFrame(string $sessionId): void
    {
        $this->db->query(
            'UPDATE relay_sessions SET last_frame_at = UNIX_TIMESTAMP() WHERE id = :id',
            [
                'id' => $sessionId,
            ],
        );
    }

    /**
     * Generate a random UUID v4.
     */
    private function generateUuid(): string
    {
        return Ids::uuidV4();
    }
}
