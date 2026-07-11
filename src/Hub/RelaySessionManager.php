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
use Workerman\MySQL\Connection;

/**
 * Manages relay sessions between the hub and enrolled servers.
 *
 * Responsibilities:
 *   - Register a new relay session when a server connects
 *   - Route an inbound HTTP request to the correct server via its relay session
 *   - Track bytes sent/received per session (batched in-memory, flushed on timer or close)
 *   - Close a relay session when the server disconnects
 *
 * @package Phlix\Hub\Hub
 */
class RelaySessionManager
{
    /**
     * In-memory accumulator: sessionId => bytes to add to bytes_out.
     *
     * @var array<string, int>
     */
    private array $pendingBytesOut = [];

    /**
     * In-memory accumulator: sessionId => bytes to add to bytes_in.
     *
     * @var array<string, int>
     */
    private array $pendingBytesIn = [];

    /**
     * In-memory accumulator: sessionId => UNIX timestamp for last_frame_at.
     *
     * @var array<string, int>
     */
    private array $pendingLastFrameAt = [];

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

        // Wrap UPDATE + INSERT in an explicit transaction so they are atomic.
        // Without it, a crash after the UPDATE but before the INSERT would leave
        // the server with no open session (the UPDATE already closed the old one
        // and the INSERT never happened), causing the relay to be unreachable.
        $this->db->beginTrans();
        try {
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

            $this->db->commitTrans();
        } catch (\Throwable $e) {
            $this->db->rollBackTrans();
            throw $e;
        }

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

        // Use in-memory accumulation instead of immediate DB write.
        /** @var string $sessionId */
        $sessionId = $session['id'];
        $this->recordBytesIn($sessionId, strlen($body));

        return $session;
    }

    /**
     * Record bytes sent to a server over its relay session.
     *
     * Accumulates in memory and flushes to the DB on the next periodic tick
     * (via {@see flushAll}) or on session close (via {@see flushSession}).
     * This replaces a per-frame UPDATE and eliminates the dominant relay-path
     * DB write under high frame rates.
     *
     * @param string $sessionId Relay session UUID.
     * @param int    $bytes     Number of bytes sent.
     *
     * @return void
     *
     */
    public function recordBytesOut(string $sessionId, int $bytes): void
    {
        $this->pendingBytesOut[$sessionId] = ($this->pendingBytesOut[$sessionId] ?? 0) + $bytes;
        $this->pendingLastFrameAt[$sessionId] = time();
    }

    /**
     * Close a relay session.
     *
     * Flushes any pending byte counts for this session before closing so that
     * final accounting is not lost.
     *
     * @param string $sessionId   Relay session UUID.
     * @param string $reason       Human-readable close reason.
     *
     * @return void
     *
     */
    public function closeSession(string $sessionId, string $reason): void
    {
        $this->flushSession($sessionId);

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
     * Accumulates in memory and flushes to the DB on the next periodic tick
     * (via {@see flushAll}) or on session close (via {@see flushSession}).
     *
     * @param string $sessionId Relay session UUID.
     * @param int    $bytes     Number of bytes received.
     *
     * @return void
     *
     */
    public function recordBytesIn(string $sessionId, int $bytes): void
    {
        $this->pendingBytesIn[$sessionId] = ($this->pendingBytesIn[$sessionId] ?? 0) + $bytes;
        $this->pendingLastFrameAt[$sessionId] = time();
    }

    /**
     * Touch the last_frame_at timestamp without changing byte counts.
     *
     * Used for HEARTBEAT frames where we want to update activity but
     * not count the heartbeat as data traffic.
     *
     * Accumulates in memory and flushes to the DB on the next periodic tick
     * (via {@see flushAll}) or on session close (via {@see flushSession}).
     *
     * @param string $sessionId Relay session UUID.
     *
     * @return void
     *
     */
    public function touchLastFrame(string $sessionId): void
    {
        $this->pendingLastFrameAt[$sessionId] = time();
    }

    /**
     * Flush accumulated byte counters and last-frame timestamps for a session
     * to the database with a single atomic UPDATE.
     *
     * Uses `bytes_out = bytes_out + :delta` and `bytes_in = bytes_in + :delta`
     * so concurrent increments from other coroutines are not clobbered.
     * Clears the session's entries from all three accumulator maps after the
     * flush so they are never double-counted.
     *
     * Idempotent: if there is nothing to flush for this session, the UPDATE
     * touches zero rows and the maps are cleared regardless.
     *
     * @param string $sessionId Relay session UUID.
     *
     * @return void
     */
    public function flushSession(string $sessionId): void
    {
        $deltaOut = $this->pendingBytesOut[$sessionId] ?? 0;
        $deltaIn = $this->pendingBytesIn[$sessionId] ?? 0;
        $lastFrameAt = $this->pendingLastFrameAt[$sessionId] ?? null;

        // Clear accumulators before the DB write so any subsequent accumulate
        // between this write and the return is not lost (it will be in the
        // next flush).
        unset(
            $this->pendingBytesOut[$sessionId],
            $this->pendingBytesIn[$sessionId],
            $this->pendingLastFrameAt[$sessionId],
        );

        // Nothing to do — skip the query entirely.
        if ($deltaOut === 0 && $deltaIn === 0 && $lastFrameAt === null) {
            return;
        }

        // Build a minimal UPDATE that covers whatever is present.
        $sets = [];
        $params = ['id' => $sessionId];

        if ($deltaOut > 0) {
            $sets[] = 'bytes_out = bytes_out + :bytes_out';
            $params['bytes_out'] = $deltaOut;
        }

        if ($deltaIn > 0) {
            $sets[] = 'bytes_in = bytes_in + :bytes_in';
            $params['bytes_in'] = $deltaIn;
        }

        if ($lastFrameAt !== null) {
            $sets[] = 'last_frame_at = :last_frame_at';
            $params['last_frame_at'] = $lastFrameAt;
        }

        if ($sets === []) {
            return;
        }

        $this->db->query(
            'UPDATE relay_sessions SET ' . implode(', ', $sets) . ' WHERE id = :id',
            $params,
        );
    }

    /**
     * Flush all pending (in-memory) byte counters and timestamps for every
     * active session to the database.
     *
     * Called by the periodic timer (e.g. from {@see \Phlix\Hub\Relay\IdleReaper})
     * so that pending counts do not grow unboundedly even for sessions that
     * have not been closed.
     *
     * @return void
     */
    public function flushAll(): void
    {
        // Flush every session that has accumulated data.
        $sessionIds = array_unique(array_merge(
            array_keys($this->pendingBytesOut),
            array_keys($this->pendingBytesIn),
            array_keys($this->pendingLastFrameAt),
        ));

        foreach ($sessionIds as $sessionId) {
            $this->flushSession($sessionId);
        }
    }

    /**
     * Generate a random UUID v4.
     */
    private function generateUuid(): string
    {
        return Ids::uuidV4();
    }

    /**
     * Record per-user bandwidth usage for the current calendar month.
     *
     * Uses INSERT ... ON DUPLICATE KEY UPDATE so the row is created on the
     * first write of the period and subsequently updated in-place.
     *
     * @param string $userId   Hub user UUID.
     * @param int    $bytesIn  Bytes received from the server (downloaded by user).
     * @param int    $bytesOut Bytes sent to the server (uploaded by user).
     *
     * @return void
     */
    public function recordUserBandwidth(string $userId, int $bytesIn, int $bytesOut): void
    {
        $periodStart = $this->currentPeriodStart();

        $this->db->query(
            'INSERT INTO relay_user_quotas (user_id, period_start, bytes_in, bytes_out)
             VALUES (:user_id, :period_start, :bytes_in, :bytes_out)
             ON DUPLICATE KEY UPDATE
                 bytes_in = bytes_in + VALUES(bytes_in),
                 bytes_out = bytes_out + VALUES(bytes_out)',
            [
                'user_id' => $userId,
                'period_start' => $periodStart,
                'bytes_in' => $bytesIn,
                'bytes_out' => $bytesOut,
            ],
        );
    }

    /**
     * Get the current month's bandwidth record for a user.
     *
     * @param string $userId Hub user UUID.
     *
     * @return array{bytes_in: int, bytes_out: int, quota_bytes_in: int, quota_bytes_out: int}|null
     *                      Null when no record exists for the current period.
     */
    public function getUserBandwidth(string $userId): ?array
    {
        $periodStart = $this->currentPeriodStart();

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT bytes_in, bytes_out, quota_bytes_in, quota_bytes_out
             FROM relay_user_quotas
             WHERE user_id = :user_id AND period_start = :period_start
             LIMIT 1',
            [
                'user_id' => $userId,
                'period_start' => $periodStart,
            ],
        );

        if ($rows === []) {
            return null;
        }

        /** @var array<string, mixed> $row */
        $row = $rows[0];

        return [
            'bytes_in' => is_numeric($row['bytes_in'] ?? null) ? (int) $row['bytes_in'] : 0,
            'bytes_out' => is_numeric($row['bytes_out'] ?? null) ? (int) $row['bytes_out'] : 0,
            'quota_bytes_in' => is_numeric($row['quota_bytes_in'] ?? null) ? (int) $row['quota_bytes_in'] : 0,
            'quota_bytes_out' => is_numeric($row['quota_bytes_out'] ?? null) ? (int) $row['quota_bytes_out'] : 0,
        ];
    }

    /**
     * Set or update the bandwidth quota for a user for the current month.
     *
     * @param string $userId        Hub user UUID.
     * @param int    $quotaBytesIn  Monthly download cap (0 = unlimited).
     * @param int    $quotaBytesOut Monthly upload cap (0 = unlimited).
     *
     * @return void
     */
    public function setUserQuota(string $userId, int $quotaBytesIn, int $quotaBytesOut): void
    {
        $periodStart = $this->currentPeriodStart();

        $this->db->query(
            'INSERT INTO relay_user_quotas (user_id, period_start, bytes_in, bytes_out, quota_bytes_in, quota_bytes_out)
             VALUES (:user_id, :period_start, 0, 0, :quota_bytes_in, :quota_bytes_out)
             ON DUPLICATE KEY UPDATE
                 quota_bytes_in = VALUES(quota_bytes_in),
                 quota_bytes_out = VALUES(quota_bytes_out)',
            [
                'user_id' => $userId,
                'period_start' => $periodStart,
                'quota_bytes_in' => $quotaBytesIn,
                'quota_bytes_out' => $quotaBytesOut,
            ],
        );
    }

    /**
     * Check whether a user is currently within their bandwidth quota.
     *
     * For the initial implementation the check is best-effort: if the user
     * has no quota row or both quotas are 0 (unlimited), they are allowed.
     * A more refined check accounting for the estimated bytes of an in-flight
     * request may be added later.
     *
     * @param string $userId          Hub user UUID.
     * @param int    $additionalBytesOut Ignored in the simplified implementation.
     *
     * @return array{allowed: bool, reason: string|null}
     */
    public function checkUserQuota(string $userId, int $additionalBytesOut = 0): array
    {
        $periodStart = $this->currentPeriodStart();

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT bytes_out, quota_bytes_out FROM relay_user_quotas
             WHERE user_id = :user_id AND period_start = :period_start
             LIMIT 1',
            [
                'user_id' => $userId,
                'period_start' => $periodStart,
            ],
        );

        // No record means no quota set — allow.
        if ($rows === []) {
            return ['allowed' => true, 'reason' => null];
        }

        /** @var array<string, mixed> $row */
        $row = $rows[0];

        $bytesOut = is_numeric($row['bytes_out'] ?? null) ? (int) $row['bytes_out'] : 0;
        $quotaBytesOut = is_numeric($row['quota_bytes_out'] ?? null) ? (int) $row['quota_bytes_out'] : 0;

        // Unlimited when both caps are 0.
        if ($quotaBytesOut === 0) {
            return ['allowed' => true, 'reason' => null];
        }

        if ($bytesOut >= $quotaBytesOut) {
            return [
                'allowed' => false,
                'reason' => 'User has reached their monthly bandwidth quota.',
            ];
        }

        return ['allowed' => true, 'reason' => null];
    }

    /**
     * Return the first day of the current calendar month as a DATE string.
     *
     * @return string YYYY-MM-DD format.
     */
    private function currentPeriodStart(): string
    {
        return date('Y-m-01');
    }
}
