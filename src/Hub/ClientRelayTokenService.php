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
use Workerman\MySQL\Connection;

use function hash;
use function is_array;
use function is_string;
use function time;

/**
 * Mint, validate, and revoke per-user, server-scoped client relay tokens
 * (Step S2a).
 *
 * A client relay token is a short-lived bearer credential issued to an
 * authenticated hub user and scoped to a single server the user owns. It
 * exists so the client relay worker can authenticate a browser/native client
 * mounting a tunnel WITHOUT handing out (or accepting) the server's
 * long-lived 7-day enrollment JWT. Tokens are independently revocable and
 * expire quickly, so a user's relay access can be cut without rotating the
 * Ed25519 enrollment keys.
 *
 * Security model:
 *  - The plaintext token is drawn from {@see Ids::token()} (CSPRNG,
 *    256 bits by default) and returned to the caller exactly ONCE at mint
 *    time. Only its SHA-256 hash is persisted, so a database disclosure
 *    never yields a usable credential.
 *  - {@see self::validate()} hashes the presented token and looks the hash
 *    up, rejecting anything expired or revoked. It returns the bound
 *    `user_id` + `server_id` so the worker (S2b) can re-confirm ownership.
 *
 * NOTE (scope): this service is the S2a half — minting + the validation/
 * revocation primitives + the storage table. Wiring the worker to require
 * one of these tokens (and dropping the `?token=` query path) is the
 * separate S2b follow-up; this class does not touch the relay worker.
 *
 * Database access is exclusively through the async
 * {@see \Workerman\MySQL\Connection} client with named parameterised queries
 * — never PDO/mysqli, never string-interpolated SQL — per the hub runtime
 * rules. Named placeholder keys are colon-free (`['id' => $x]`).
 *
 * @package Phlix\Hub\Hub
 * @since   S2a (per-user revocable hub relay token — mint + table)
 */
final class ClientRelayTokenService
{
    /**
     * Default token lifetime in seconds (1 hour) when no override is given.
     */
    public const int DEFAULT_TTL_SECONDS = 3600;

    /** @var Connection Async MySQL connection used for all queries. */
    private Connection $db;

    /** @var int Token lifetime in seconds applied at mint time. */
    private int $ttlSeconds;

    /**
     * @param Connection $db         Workerman MySQL connection.
     * @param int        $ttlSeconds Token lifetime in seconds (clamped to a
     *                               positive value; defaults to one hour).
     *
     * @since S2a
     */
    public function __construct(Connection $db, int $ttlSeconds = self::DEFAULT_TTL_SECONDS)
    {
        $this->db = $db;
        $this->ttlSeconds = $ttlSeconds > 0 ? $ttlSeconds : self::DEFAULT_TTL_SECONDS;
    }

    /**
     * Mint a fresh per-user, server-scoped relay token.
     *
     * The plaintext token is returned to the caller and must be handed to the
     * client immediately — it is NOT recoverable afterwards (only its hash is
     * stored). The caller is responsible for having verified that `$userId`
     * owns `$serverId` BEFORE calling this (the mint controller enforces this
     * via {@see ServerInfoHandler}).
     *
     * @param string $userId   Hub user UUID the token authenticates as.
     * @param string $serverId Server UUID the token is scoped to.
     *
     * @return array{token: string, expires_at: int} The plaintext token and
     *         its absolute Unix expiry timestamp.
     *
     * @since S2a
     */
    public function mint(string $userId, string $serverId): array
    {
        $token = Ids::token();
        $hash = $this->hashToken($token);
        $id = Ids::uuidV4();
        $expiresAt = time() + $this->ttlSeconds;

        $this->db->query(
            'INSERT INTO client_relay_tokens (id, token_hash, user_id, server_id, expires_at)'
                . ' VALUES (:id, :token_hash, :user_id, :server_id, FROM_UNIXTIME(:expires_at))',
            [
                'id' => $id,
                'token_hash' => $hash,
                'user_id' => $userId,
                'server_id' => $serverId,
                'expires_at' => $expiresAt,
            ],
        );

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    /**
     * Validate a presented plaintext token.
     *
     * Hashes the token, looks up an active row (matching hash, not expired,
     * not revoked) and returns the bound identity. Returns null for unknown,
     * expired, or revoked tokens so callers fail closed.
     *
     * @param string $token The plaintext token presented by the client.
     *
     * @return array{user_id: string, server_id: string}|null The bound
     *         identity, or null when the token is invalid.
     *
     * @since S2a
     */
    public function validate(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        $hash = $this->hashToken($token);

        /** @var mixed $rows */
        $rows = $this->db->query(
            'SELECT user_id, server_id FROM client_relay_tokens'
                . ' WHERE token_hash = :token_hash'
                . ' AND revoked_at IS NULL'
                . ' AND expires_at > NOW()'
                . ' LIMIT 1',
            ['token_hash' => $hash],
        );

        if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0])) {
            return null;
        }

        $row = $rows[0];
        $userId = $row['user_id'] ?? null;
        $serverId = $row['server_id'] ?? null;
        if (!is_string($userId) || !is_string($serverId)) {
            return null;
        }

        return ['user_id' => $userId, 'server_id' => $serverId];
    }

    /**
     * Revoke a token by its plaintext value.
     *
     * Sets `revoked_at` on the matching active row so the token can no longer
     * validate. Idempotent: revoking an unknown / already-revoked token is a
     * no-op.
     *
     * @param string $token The plaintext token to revoke.
     *
     * @since S2a
     */
    public function revoke(string $token): void
    {
        if ($token === '') {
            return;
        }

        $this->db->query(
            'UPDATE client_relay_tokens SET revoked_at = NOW()'
                . ' WHERE token_hash = :token_hash AND revoked_at IS NULL',
            ['token_hash' => $this->hashToken($token)],
        );
    }

    /**
     * Revoke every active token for a (user, server) pair.
     *
     * Useful when a user's access to a specific server is cut without
     * needing the individual plaintext tokens.
     *
     * @param string $userId   Hub user UUID.
     * @param string $serverId Server UUID.
     *
     * @since S2a
     */
    public function revokeForUserServer(string $userId, string $serverId): void
    {
        $this->db->query(
            'UPDATE client_relay_tokens SET revoked_at = NOW()'
                . ' WHERE user_id = :user_id AND server_id = :server_id AND revoked_at IS NULL',
            ['user_id' => $userId, 'server_id' => $serverId],
        );
    }

    /**
     * Prune tokens that expired more than 1 day ago OR were revoked.
     *
     * Runs the retention sweep on each idle-reaper tick so that rows are
     * cleaned up without extra cron or manual maintenance. A row is removed
     * when EITHER predicate holds (H-D2):
     *
     *  - it expired more than a day ago — the common case, since tokens have a
     *    ~1 h TTL and are rarely revoked, so this is what actually bounds table
     *    growth (the 1-day grace keeps a just-expired token queryable briefly); or
     *  - it has been explicitly revoked (removed regardless of expiry).
     *
     * Note the operator is OR, not AND: with AND only tokens that are BOTH
     * expired-by->1-day AND revoked would be pruned, leaving the common
     * expired-never-revoked rows to accumulate forever.
     *
     * Precedence: the comparison / `IS NOT NULL` predicates bind tighter than
     * `OR`, so the WHERE clause parses as
     * `(expires_at < NOW() - INTERVAL 1 DAY) OR (revoked_at IS NOT NULL)`.
     *
     * @return int Number of rows deleted.
     *
     * @since HB-4.2
     */
    public function pruneExpiredTokens(): int
    {
        $result = $this->db->query(
            'DELETE FROM client_relay_tokens'
                . ' WHERE expires_at < NOW() - INTERVAL 1 DAY'
                . ' OR revoked_at IS NOT NULL',
        );

        return is_int($result) ? $result : 0;
    }

    /**
     * Compute the storage hash of a plaintext token.
     *
     * @param string $token Plaintext token.
     *
     * @return string Lower-case SHA-256 hex digest (64 chars).
     */
    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
