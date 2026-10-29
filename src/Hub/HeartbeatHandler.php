<?php

/**
 * Phlix hub component: Hub.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Hub;

use Phlix\Hub\Auth\RateLimitException;
use Phlix\Hub\Common\RateLimit\RateLimiterInterface;
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
 *   4. Syncs the cached server_libraries set (bounded by the
 *      MAX_LIBRARY_* caps enforced at the HTTP boundary; stale rows
 *      pruned whenever an accepted set changes).
 *
 * @package Phlix\Hub\Hub
 */
class HeartbeatHandler
{
    /**
     * M-HUB-15: hard cap on how many libraries one heartbeat may report.
     *
     * A real server exposes a handful of libraries; 500 is a ~5x headroom over
     * any realistic worst case while keeping the accepted set a BOUNDED amount
     * of upsert work per beat. Together with the stale-row prune in
     * {@see self::updateServerLibraries()} this makes server_libraries growth
     * per enrolled server finite: rows ≤ MAX_LIBRARY_COUNT at all times.
     */
    public const int MAX_LIBRARY_COUNT = 500;

    /**
     * M-HUB-15: maximum CHARACTER count of a reported library_id, pinned to the
     * server_libraries.library_id column width CHAR(36) (migration 031).
     * utf8mb4 columns measure in characters, so the check counts characters
     * (mb_strlen), not bytes.
     */
    public const int MAX_LIBRARY_ID_CHARS = 36;

    /**
     * M-HUB-15: maximum CHARACTER count of a reported library_name, pinned to
     * the server_libraries.library_name column width VARCHAR(255) (migration 031).
     */
    public const int MAX_LIBRARY_NAME_CHARS = 255;

    /**
     * @param Connection           $db         MySQL connection.
     * @param EnrollmentJwtService $jwtService JWT validation service.
     * @param StructuredLogger     $logger     Application logger.
     * @param RateLimiterInterface $rateLimiter Bounded, TTL-windowed rate limiter keyed by server id.
     */
    public function __construct(
        private readonly Connection $db,
        private readonly EnrollmentJwtService $jwtService,
        private readonly StructuredLogger $logger,
        private readonly RateLimiterInterface $rateLimiter,
    ) {
    }

    /**
     * Boundary check for the heartbeat libraries list (M-HUB-15).
     *
     * The shared HeartbeatDto guarantees TYPES only; the hub owns the SIZE
     * limits because they are properties of its own storage schema. The single
     * HTTP boundary (ServerController::heartbeat) calls this after DTO parsing
     * and answers 400 Bad Request on a violation, so nothing oversized ever
     * reaches updateServerLibraries() and the write leg may trust its input.
     *
     * @param list<LibraryRef> $libraries
     *
     * @return string|null A descriptive violation message, or null when the
     *                     list is within every limit.
     */
    public static function libraryListViolation(array $libraries): ?string
    {
        if (count($libraries) > self::MAX_LIBRARY_COUNT) {
            return sprintf(
                'Heartbeat "libraries" exceeds the maximum of %d entries (got %d).',
                self::MAX_LIBRARY_COUNT,
                count($libraries),
            );
        }

        foreach ($libraries as $index => $ref) {
            $idChars = mb_strlen($ref->libraryId, 'UTF-8');
            if ($idChars > self::MAX_LIBRARY_ID_CHARS) {
                return sprintf(
                    'Heartbeat library at index %d: "library_id" exceeds the maximum length of %d characters (got %d).',
                    $index,
                    self::MAX_LIBRARY_ID_CHARS,
                    $idChars,
                );
            }

            $nameChars = mb_strlen($ref->libraryName, 'UTF-8');
            if ($nameChars > self::MAX_LIBRARY_NAME_CHARS) {
                return sprintf(
                    'Heartbeat library at index %d: "library_name" '
                    . 'exceeds the maximum length of %d characters (got %d).',
                    $index,
                    self::MAX_LIBRARY_NAME_CHARS,
                    $nameChars,
                );
            }
        }

        return null;
    }

    /**
     * Process a heartbeat from a server.
     *
     * @param string      $serverId       Server UUID from the hub.
     * @param string      $enrollmentJwt  The server's enrollment JWT.
     * @param HeartbeatDto $heartbeat      Heartbeat payload.
     *
     * @throws InvalidArgumentException When JWT is invalid (401) or server not found (404).
     * @throws RateLimitException When the heartbeat rate limit for this server is exceeded (429).
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

        // HB-4.6c: rate limit keyed by the JWT-proven server id, AFTER the
        // enrollment-JWT validation above so an unproven id can never mint a
        // bucket. The `rate_limiter.heartbeat` profile is 30/60s — generous
        // for the legitimate ~60s heartbeat cadence (the old 5/900 login-grade
        // limiter tripped normal heartbeats).
        $state = $this->rateLimiter->hit('heartbeat:' . $serverId);
        if ($state->limited) {
            throw new RateLimitException(
                resetAt: $state->resetAt,
                remaining: 0,
            );
        }

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

            // R19-04: servers.last_seen_at is stamped with the DATABASE clock
            // (NOW()), not PHP's `date()`. The reaper compares this column
            // against MySQL's NOW() (ServerReaper::markStaleServersOffline());
            // a PHP-written value formats in the PHP process timezone while
            // NOW() formats in the MySQL session timezone, and any skew — e.g.
            // host TZ ≠ container TZ — silently shifts the freshness margin
            // and false-flips servers offline (or keeps ghosts online).
            // Single clock, single source of truth.
            $this->db->query(
                "UPDATE servers SET status = 'online', last_seen_at = NOW(), version = :version,
                 hostname_candidates_json = :hostname_candidates_json WHERE id = :id",
                [
                    'version' => $heartbeat->version,
                    'hostname_candidates_json' => $hostnameJson,
                    'id' => $serverId,
                ],
            );

            $this->db->query(
                "INSERT INTO server_heartbeats (id, server_id, version, uptime_seconds, active_sessions,
                 active_transcodes, hostname_candidates_json, received_at)
                 VALUES (:id, :server_id, :version, :uptime_seconds, :active_sessions, :active_transcodes,
                 :hostname_candidates_json, NOW())",
                [
                    // R19-04: server_heartbeats.received_at is stamped with the
                    // DATABASE clock (NOW() in the VALUES list) — the same
                    // column the retention sweep (ServerReaper::sweepHeartbeats)
                    // ages against NOW(). No PHP-clock param rides this write.
                    'id' => $this->generateUuid(),
                    'server_id' => $serverId,
                    'version' => $heartbeat->version,
                    'uptime_seconds' => $heartbeat->uptimeSeconds,
                    'active_sessions' => $heartbeat->activeSessions,
                    'active_transcodes' => $heartbeat->activeTranscodes,
                    'hostname_candidates_json' => $hostnameJson,
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
     * Update the cached libraries for a server — upsert the reported set AND
     * prune rows the server no longer reports (M-HUB-15).
     *
     * Computes a SHA-256 hash of the library list (sorted by libraryId) and
     * compares it against the last stored hash. When the hash matches the
     * previous heartbeat's hash, ALL library-table writes are skipped (no
     * upserts, no prune, no hash-row update) — the cached set is provably
     * already exactly what was reported.
     *
     * When the hash differs, the accepted set is written in the caller's
     * transaction: every reported library is upserted, then a single DELETE
     * prunes every server_libraries row whose library_id was NOT reported.
     * The cache therefore always mirrors the last accepted set, and per-server
     * growth is hard-bounded: the HTTP boundary caps the reported list via
     * {@see self::libraryListViolation()} (count ≤ MAX_LIBRARY_COUNT, fields
     * within the column widths), so a hostile server rotating unique ids each
     * beat churns a bounded window instead of accumulating rows forever.
     *
     * Honest edge of the story: an EMPTY reported list never reaches here (the
     * caller skips it), because the shared DTO cannot distinguish "server
     * reported no libraries" from "server omitted the key" — treating omission
     * as an erasure would wipe caches on every library-less beat. A server that
     * genuinely drops all its libraries keeps the last cached rows until the
     * next non-empty report or until server deletion (FK ON DELETE CASCADE).
     *
     * @param string          $serverId  Server UUID.
     * @param list<LibraryRef> $libraries Library list (non-empty; pre-checked
     *                                    against the size caps at the boundary).
     */
    private function updateServerLibraries(string $serverId, array $libraries): void
    {
        $hash = $this->computeLibraryListHash($libraries);

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT hash FROM server_library_hashes WHERE server_id = :server_id',
            ['server_id' => $serverId],
        );

        /** @var string|null $previousHash */
        $previousHash = $rows[0]['hash'] ?? null;

        // Hash matches previous heartbeat — nothing changed, skip upserts.
        if ($previousHash === $hash) {
            return;
        }

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

        // M-HUB-15: prune stale rows — every cached library this server did NOT
        // report is removed, in the same transaction as the upserts above.
        // Generated named placeholders (one per reported id) follow the
        // RelaySessionManager NOT IN precedent; the list is guaranteed
        // non-empty by both the caller and the boundary caps.
        /** @var array<string, string> $pruneParams */
        $pruneParams = ['server_id' => $serverId];
        $keepPlaceholders = [];
        foreach ($libraries as $index => $ref) {
            $key = 'keep_library_id_' . $index;
            $keepPlaceholders[] = ':' . $key;
            $pruneParams[$key] = $ref->libraryId;
        }
        $this->db->query(
            'DELETE FROM server_libraries
             WHERE server_id = :server_id
               AND library_id NOT IN (' . implode(', ', $keepPlaceholders) . ')',
            $pruneParams,
        );

        // Persist the new hash so the next heartbeat can skip upserts when unchanged.
        $this->db->query(
            'INSERT INTO server_library_hashes (server_id, hash, updated_at)
             VALUES (:server_id, :hash, NOW())
             ON DUPLICATE KEY UPDATE hash = :hash_update, updated_at = NOW()',
            [
                'server_id' => $serverId,
                'hash' => $hash,
                'hash_update' => $hash,
            ],
        );
    }

    /**
     * Compute a SHA-256 hash of the library list.
     *
     * The list is canonicalised by sorting on libraryId before hashing so that
     * semantically identical lists always produce the same hash regardless of
     * the order in which the server sent them.
     *
     * @param list<LibraryRef> $libraries
     */
    private function computeLibraryListHash(array $libraries): string
    {
        // Sort by libraryId for stable, deterministic ordering.
        usort(
            $libraries,
            static fn(LibraryRef $a, LibraryRef $b): int => $a->libraryId <=> $b->libraryId,
        );

        $canonical = array_map(
            static fn(LibraryRef $ref): array => $ref->toPayload(),
            $libraries,
        );

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
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
     * M-HUB-19 encoding law: `received_at` (a DATETIME column) is converted
     * SQL-side via `UNIX_TIMESTAMP()` — the hub's established idiom for
     * shipping timestamps as unix seconds (cf. ServerInfoHandler's
     * `UNIX_TIMESTAMP(s.last_seen_at)` and this manager's own
     * `UNIX_TIMESTAMP(opened_at)` idle probe). A non-numeric value (DB
     * anomaly) yields null — an honest unknown — never the fake 0 that the
     * old `is_numeric ? (int) : 0` mapper shipped for EVERY row, since a
     * raw DATETIME string is never numeric.
     *
     * @param string $serverId Server UUID.
     * @param int    $limit    Maximum number of rows to return.
     *
     * @return list<array<string, mixed>> Rows with `received_at` as
     *                                    unix-seconds int|null.
     */
    public function getHeartbeatHistory(string $serverId, int $limit = 20): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT id, version, uptime_seconds, active_sessions, active_transcodes,
                    UNIX_TIMESTAMP(received_at) AS received_at
             FROM server_heartbeats
             WHERE server_id = :server_id
             ORDER BY received_at DESC
             LIMIT :limit',
            ['server_id' => $serverId, 'limit' => $limit],
        );

        /** @var list<array<string, mixed>> $result */
        $result = [];
        $int = fn (mixed $v): int => is_numeric($v) ? (int) $v : 0;
        /** @var \Closure(mixed): ?int */
        $unixOrNull = static fn (mixed $v): ?int => is_numeric($v) ? (int) $v : null;
        foreach ($rows as $row) {
            /** @var array<string, mixed> $typedRow */
            $typedRow = $row;
            $result[] = [
                'id'               => $typedRow['id'],
                'version'         => $typedRow['version'],
                'uptime_seconds'  => $int($typedRow['uptime_seconds'] ?? ''),
                'active_sessions' => $int($typedRow['active_sessions'] ?? ''),
                'active_transcodes' => $int($typedRow['active_transcodes'] ?? ''),
                'received_at'     => $unixOrNull($typedRow['received_at'] ?? null),
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

    /**
     * Prune old heartbeat rows for a specific server, keeping only the most
     * recent N rows (ring-delete pattern).
     *
     * Only executes the DELETE when the server has more than $keepLast rows,
     * so the query is a no-op for servers with few heartbeats.
     *
     * The ring-delete query uses a double-nested subquery to work around
     * MySQL's restriction on DELETE from a table that appears in a subquery.
     *
     * @param string $serverId Server UUID.
     * @param int    $keepLast Number of most-recent rows to keep per server.
     *
     * @return int Number of rows deleted (0 if not enough rows to prune).
     */
    public function pruneServerHeartbeats(string $serverId, int $keepLast = 100): int
    {
        // Early exit: only prune when there are more rows than we want to keep.
        /** @var list<array<string, mixed>> $countRows */
        $countRows = $this->db->query(
            'SELECT COUNT(*) AS cnt FROM server_heartbeats WHERE server_id = :server_id',
            ['server_id' => $serverId],
        );
        /** @var int|numeric-string|null $rawCount */
        $rawCount = $countRows[0]['cnt'] ?? null;
        $count = is_numeric($rawCount) ? (int) $rawCount : 0;
        if ($count <= $keepLast) {
            return 0;
        }

        /** @var mixed $result */
        $result = $this->db->query(
            'DELETE FROM server_heartbeats
             WHERE server_id = :server_id
               AND id NOT IN (
                   SELECT id FROM (
                       SELECT id FROM server_heartbeats
                       WHERE server_id = :server_id2
                       ORDER BY received_at DESC
                       LIMIT :keep_last
                   ) AS t
               )',
            [
                'server_id' => $serverId,
                'server_id2' => $serverId,
                'keep_last' => $keepLast,
            ],
        );

        $deleted = is_numeric($result) ? (int) $result : 0;

        if ($deleted > 0) {
            $this->logger->debug('HeartbeatHandler: pruned server heartbeats', [
                'server_id' => $serverId,
                'deleted' => $deleted,
                'kept' => $keepLast,
            ]);
        }

        return $deleted;
    }

    /**
     * Prune old heartbeat rows for all servers, keeping only the most recent
     * N rows per server.
     *
     * Iterates all distinct servers and calls {@see pruneServerHeartbeats()} for
     * each one.
     *
     * @param int $keepLast Number of most-recent rows to keep per server.
     *
     * @return int Total number of rows deleted across all servers.
     */
    public function pruneAllServerHeartbeats(int $keepLast = 100): int
    {
        /** @var list<array<string, mixed>> $serverIds */
        $serverIds = $this->db->query(
            'SELECT DISTINCT server_id FROM server_heartbeats',
        );

        $totalDeleted = 0;
        foreach ($serverIds as $row) {
            /** @var mixed $rawServerId */
            $rawServerId = $row['server_id'] ?? null;
            $serverId = is_string($rawServerId) ? $rawServerId : '';
            if ($serverId === '') {
                continue;
            }
            $totalDeleted += $this->pruneServerHeartbeats($serverId, $keepLast);
        }

        if ($totalDeleted > 0) {
            $this->logger->info('HeartbeatHandler: heartbeat prune sweep complete', [
                'total_deleted' => $totalDeleted,
                'servers_checked' => count($serverIds),
                'keep_last' => $keepLast,
            ]);
        }

        return $totalDeleted;
    }
}
