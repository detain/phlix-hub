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
 *   - Track bytes sent/received per session (batched in-memory, flushed on timer or close)
 *   - Close a relay session when the server disconnects
 *
 * @package Phlix\Hub\Hub
 */
class RelaySessionManager
{
    /**
     * Default per-user relay throttle in bits/sec (3 Mbps) — the value a user
     * carries until an admin sets one, and the value read back when the user has
     * no rollup row for the current period (S41, migration 042). 0 = Unlimited.
     * Mirrors the migration-042 column DEFAULT so the "no row" read and the
     * stored default agree, exactly as {@see self::getUserMaxConcurrentStreams()}
     * mirrors the migration-038 column default of 0.
     */
    public const DEFAULT_THROTTLE_BPS = 3000000;

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
     * In-memory count of a user's currently-active relay streams, keyed by user
     * id. Used by the per-user concurrent-stream cap (HB-3.4 G3).
     *
     * This lives in memory ON PURPOSE and is NOT persisted: the live count is a
     * property of THIS worker process (it owns the browser connections it is
     * streaming), so a DB round-trip would neither be authoritative nor cheap.
     * The map is BOUNDED — a user's entry is removed the moment their count
     * returns to zero ({@see self::endUserStream()}) — so it can never grow
     * without limit in the resident Workerman worker (§0.4).
     *
     * @var array<string, int>
     */
    private array $activeStreams = [];

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
     * A live tunnel refreshes `last_frame_at` (via touchLastFrame, recordBytesIn/recordBytesOut,
     * and the 30s heartbeat timer) well within this threshold,
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
     * When {@see $maxConcurrentStreams} is supplied it is written to the
     * migration-038 `max_concurrent_streams` column in the SAME upsert (0 =
     * unlimited). When it is left null the column is untouched, so existing
     * three-argument callers keep their original behaviour unchanged — the
     * concurrent cap is only ever mutated when a caller explicitly provides one
     * (the HB-3.4 G5 admin quota endpoint sets all three caps together).
     *
     * @param string   $userId               Hub user UUID.
     * @param int      $quotaBytesIn         Monthly download cap (0 = unlimited).
     * @param int      $quotaBytesOut        Monthly upload cap (0 = unlimited).
     * @param int|null $maxConcurrentStreams Concurrent-stream cap (0 = unlimited);
     *                                        null leaves the stored value unchanged.
     *
     * @return void
     */
    public function setUserQuota(
        string $userId,
        int $quotaBytesIn,
        int $quotaBytesOut,
        ?int $maxConcurrentStreams = null,
    ): void {
        $periodStart = $this->currentPeriodStart();

        if ($maxConcurrentStreams === null) {
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

            return;
        }

        $this->db->query(
            'INSERT INTO relay_user_quotas
                 (user_id, period_start, bytes_in, bytes_out, quota_bytes_in, quota_bytes_out, max_concurrent_streams)
             VALUES (:user_id, :period_start, 0, 0, :quota_bytes_in, :quota_bytes_out, :max_concurrent_streams)
             ON DUPLICATE KEY UPDATE
                 quota_bytes_in = VALUES(quota_bytes_in),
                 quota_bytes_out = VALUES(quota_bytes_out),
                 max_concurrent_streams = VALUES(max_concurrent_streams)',
            [
                'user_id' => $userId,
                'period_start' => $periodStart,
                'quota_bytes_in' => $quotaBytesIn,
                'quota_bytes_out' => $quotaBytesOut,
                'max_concurrent_streams' => $maxConcurrentStreams,
            ],
        );
    }

    /**
     * Check whether a user is currently within their bandwidth quota AND read
     * their configured concurrent-stream cap from the SAME row (HB-3.4).
     *
     * Enforces BOTH monthly caps recorded for the period (HB-3.4 G2):
     *   - the DOWNLOAD cap `quota_bytes_in` against `bytes_in` (bytes the user
     *     has DOWNLOADED from the server — the dimension a media stream grows),
     *   - the UPLOAD cap `quota_bytes_out` against `bytes_out` (bytes the user
     *     has UPLOADED to the server).
     * (See the column semantics documented on {@see self::recordUserBandwidth()}:
     * `bytes_in` = downloaded-by-user, `bytes_out` = uploaded-by-user.) A cap of
     * 0 on either dimension means "unlimited" for that dimension and is skipped.
     * Denial fires when a set cap has been reached (`used >= quota`).
     *
     * The `max_concurrent_streams` column (migration 038, 0 = unlimited) is
     * folded into this single row read and returned as `maxConcurrentStreams` so
     * the streaming hot path ({@see \Phlix\Hub\Http\Controllers\ServerProxyController::proxy()})
     * consumes the allow/deny verdict AND the concurrent cap from ONE round-trip
     * to `relay_user_quotas` instead of re-reading the identical row via
     * {@see self::getUserMaxConcurrentStreams()}.
     *
     * Best-effort: if the user has no quota row for the current period, they are
     * allowed and the concurrent cap is 0 (unlimited). A refined check accounting
     * for the estimated bytes of an in-flight request may be added later.
     *
     * @param string $userId          Hub user UUID.
     * @param int    $additionalBytesOut Ignored in the simplified implementation.
     *
     * @return array{allowed: bool, reason: string|null, maxConcurrentStreams: int}
     */
    public function checkUserQuota(string $userId, int $additionalBytesOut = 0): array
    {
        $periodStart = $this->currentPeriodStart();

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT bytes_in, bytes_out, quota_bytes_in, quota_bytes_out, max_concurrent_streams
             FROM relay_user_quotas
             WHERE user_id = :user_id AND period_start = :period_start
             LIMIT 1',
            [
                'user_id' => $userId,
                'period_start' => $periodStart,
            ],
        );

        // No record means no quota set — allow, with the concurrent cap unlimited.
        if ($rows === []) {
            return ['allowed' => true, 'reason' => null, 'maxConcurrentStreams' => 0];
        }

        /** @var array<string, mixed> $row */
        $row = $rows[0];

        $bytesIn = is_numeric($row['bytes_in'] ?? null) ? (int) $row['bytes_in'] : 0;
        $bytesOut = is_numeric($row['bytes_out'] ?? null) ? (int) $row['bytes_out'] : 0;
        $quotaBytesIn = is_numeric($row['quota_bytes_in'] ?? null) ? (int) $row['quota_bytes_in'] : 0;
        $quotaBytesOut = is_numeric($row['quota_bytes_out'] ?? null) ? (int) $row['quota_bytes_out'] : 0;
        $maxConcurrentStreams = is_numeric($row['max_concurrent_streams'] ?? null)
            ? (int) $row['max_concurrent_streams']
            : 0;

        // DOWNLOAD cap: bytes_in is what the user has downloaded from the server;
        // this is the dimension a media stream grows, so it is the cap that
        // actually bites for playback over the relay.
        if ($quotaBytesIn > 0 && $bytesIn >= $quotaBytesIn) {
            return [
                'allowed' => false,
                'reason' => 'User has reached their monthly download bandwidth quota.',
                'maxConcurrentStreams' => $maxConcurrentStreams,
            ];
        }

        // UPLOAD cap: bytes_out is what the user has uploaded to the server.
        if ($quotaBytesOut > 0 && $bytesOut >= $quotaBytesOut) {
            return [
                'allowed' => false,
                'reason' => 'User has reached their monthly upload bandwidth quota.',
                'maxConcurrentStreams' => $maxConcurrentStreams,
            ];
        }

        return ['allowed' => true, 'reason' => null, 'maxConcurrentStreams' => $maxConcurrentStreams];
    }

    /**
     * Read a user's operator-configured maximum number of concurrent relay
     * streams for the current period (HB-3.4 G3).
     *
     * Stored on the {@see relay_user_quotas} rollup row (migration 038,
     * `max_concurrent_streams`). 0 (the default, and the value returned when no
     * row exists for the period) means UNLIMITED, so the caller skips the
     * admission check.
     *
     * NOTE: the streaming admission hot path no longer calls this — it reads the
     * concurrent cap from {@see self::checkUserQuota()}'s single folded row read
     * (HB-3.4 fix) to avoid a second identical SELECT per HLS/DASH segment. This
     * standalone accessor is retained for callers that need only the cap (e.g.
     * the forthcoming admin quota-management endpoints, HB-3.4 G5).
     *
     * @param string $userId Hub user UUID.
     *
     * @return int Max concurrent streams (0 = unlimited).
     */
    public function getUserMaxConcurrentStreams(string $userId): int
    {
        $periodStart = $this->currentPeriodStart();

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT max_concurrent_streams FROM relay_user_quotas
             WHERE user_id = :user_id AND period_start = :period_start
             LIMIT 1',
            [
                'user_id' => $userId,
                'period_start' => $periodStart,
            ],
        );

        if ($rows === []) {
            return 0;
        }

        /** @var array<string, mixed> $row */
        $row = $rows[0];

        return is_numeric($row['max_concurrent_streams'] ?? null)
            ? (int) $row['max_concurrent_streams']
            : 0;
    }

    /**
     * Set or update a user's per-user relay THROTTLE (sustained rate cap in
     * bits/sec) for the current period (S41, updates.md #50).
     *
     * Mirrors {@see self::setUserQuota()}: an INSERT ... ON DUPLICATE KEY UPDATE
     * upsert keyed on (user_id, period_start) that creates the current-period
     * rollup row on first write and updates the throttle in place thereafter.
     * Only `throttle_bps` is touched — the monthly byte-cap quota columns and the
     * concurrent-stream cap keep whatever value they already hold (untouched on
     * UPDATE, their column DEFAULTs on a fresh INSERT). 0 = Unlimited.
     *
     * NOT enforced here: S41 only persists the value. Rate limiting lands in
     * S42 (WS relay) / S43 (HTTP proxy).
     *
     * @param string $userId      Hub user UUID.
     * @param int    $throttleBps Sustained rate cap in bits/sec (0 = unlimited).
     *
     * @return void
     */
    public function setUserThrottle(string $userId, int $throttleBps): void
    {
        $periodStart = $this->currentPeriodStart();

        $this->db->query(
            'INSERT INTO relay_user_quotas (user_id, period_start, bytes_in, bytes_out, throttle_bps)
             VALUES (:user_id, :period_start, 0, 0, :throttle_bps)
             ON DUPLICATE KEY UPDATE
                 throttle_bps = VALUES(throttle_bps)',
            [
                'user_id' => $userId,
                'period_start' => $periodStart,
                'throttle_bps' => $throttleBps,
            ],
        );
    }

    /**
     * Read a user's operator-configured relay throttle (bits/sec) for the current
     * period (S41, updates.md #50).
     *
     * Stored on the {@see relay_user_quotas} rollup row (migration 042,
     * `throttle_bps`). Mirrors {@see self::getUserMaxConcurrentStreams()} except
     * the "no row for the period" (and non-numeric) fallback is the migration-042
     * column DEFAULT of {@see self::DEFAULT_THROTTLE_BPS} (3 Mbps) rather than 0 —
     * an unconfigured user carries the 3 Mbps default, not "unlimited". A stored
     * value of 0 means Unlimited.
     *
     * @param string $userId Hub user UUID.
     *
     * @return int Throttle in bits/sec (0 = unlimited).
     */
    public function getUserThrottleBps(string $userId): int
    {
        $periodStart = $this->currentPeriodStart();

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT throttle_bps FROM relay_user_quotas
             WHERE user_id = :user_id AND period_start = :period_start
             LIMIT 1',
            [
                'user_id' => $userId,
                'period_start' => $periodStart,
            ],
        );

        if ($rows === []) {
            return self::DEFAULT_THROTTLE_BPS;
        }

        /** @var array<string, mixed> $row */
        $row = $rows[0];

        return is_numeric($row['throttle_bps'] ?? null)
            ? (int) $row['throttle_bps']
            : self::DEFAULT_THROTTLE_BPS;
    }

    /**
     * Number of relay streams currently active for a user in THIS worker
     * (HB-3.4 G3). In-memory, per-worker.
     *
     * @param string $userId Hub user UUID.
     *
     * @return int
     */
    public function activeUserStreams(string $userId): int
    {
        return $this->activeStreams[$userId] ?? 0;
    }

    /**
     * Mark that a user has begun one relay stream (HB-3.4 G3).
     *
     * Called when a streaming response is admitted. Pairs 1:1 with
     * {@see self::endUserStream()} on stream completion/close/error.
     *
     * @param string $userId Hub user UUID.
     *
     * @return void
     */
    public function beginUserStream(string $userId): void
    {
        $this->activeStreams[$userId] = ($this->activeStreams[$userId] ?? 0) + 1;
    }

    /**
     * Mark that one of a user's relay streams has ended (HB-3.4 G3).
     *
     * Decrements the in-memory active count and REMOVES the key entirely once it
     * reaches zero, so {@see self::$activeStreams} stays bounded by the number of
     * users with a live stream right now (never accumulates dead entries).
     * Clamps at zero so a spurious/double end can never drive the count negative.
     *
     * @param string $userId Hub user UUID.
     *
     * @return void
     */
    public function endUserStream(string $userId): void
    {
        $current = $this->activeStreams[$userId] ?? 0;
        if ($current <= 1) {
            unset($this->activeStreams[$userId]);
            return;
        }
        $this->activeStreams[$userId] = $current - 1;
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
