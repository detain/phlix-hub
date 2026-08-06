<?php

/**
 * Phlix hub component: Mcp.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Mcp;

use Phlix\Hub\Common\Support\Ids;
use Workerman\MySQL\Connection;

use function hash;
use function is_array;
use function is_int;
use function is_string;
use function str_starts_with;
use function substr;
use function time;

/**
 * Mint, validate, list, and revoke MCP personal access tokens (S62).
 *
 * Cloned from {@see \Phlix\Hub\Hub\ClientRelayTokenService} — same security
 * model, same storage discipline — with the two differences the MCP surface
 * needs (see `migrations/044_mcp_tokens.sql`):
 *
 *  - the token is scoped to a USER, not to a single server, because
 *    `list_servers` must enumerate every server that user owns; and
 *  - it carries a {@see McpScopes} list so a user can mint a token narrower
 *    than their own account.
 *
 * Security model (identical to the relay token):
 *
 *  - the plaintext is drawn from {@see Ids::token()} (CSPRNG, 256 bits) and
 *    returned to the caller exactly ONCE at mint time. Only its SHA-256 hash is
 *    persisted, so a database disclosure never yields a usable credential;
 *  - {@see validate()} hashes the presented token and looks the hash up,
 *    rejecting anything expired or revoked, and returns the bound identity so
 *    the caller can re-derive `user_id` — callers must never take a user id from
 *    the request envelope.
 *
 * ⚠ Ownership is NOT this class's job and must not be read into it. A valid
 * token says only "this is user X". Which servers user X may reach is decided
 * by {@see \Phlix\Hub\Http\Controllers\ServerProxyController}'s existing 404/403
 * gate, reached through {@see McpToolContext}. Scopes narrow that; they never
 * widen it.
 *
 * Database access is exclusively through the async {@see Connection} client with
 * named parameterised queries — never PDO/mysqli, never string-interpolated SQL
 * — per the hub runtime rules. Named placeholder keys are colon-free.
 *
 * @package Phlix\Hub\Mcp
 * @since   S62 (MCP core route/dispatcher/tools + PAT auth)
 */
final class McpTokenService
{
    /**
     * Default token lifetime in seconds (90 days) when no override is given.
     *
     * Deliberately far longer than {@see \Phlix\Hub\Hub\ClientRelayTokenService::DEFAULT_TTL_SECONDS}
     * (1 hour): an MCP client is a long-lived desktop/agent process that pastes
     * the token into a config file once, not a browser that can silently
     * re-mint. It is still FINITE — a perpetual token is not offered — and
     * {@see revoke()} cuts access immediately without waiting for expiry.
     */
    public const int DEFAULT_TTL_SECONDS = 7776000;

    /**
     * Human-visible prefix on every minted plaintext token.
     *
     * Two reasons, both operational rather than cryptographic: a leaked token
     * is recognisable as a Phlix MCP credential in a log or a paste (so secret
     * scanners can match it), and {@see looksLikeMcpToken()} lets the MCP
     * endpoint tell "you presented the wrong KIND of credential" from "you
     * presented a bad token". The entropy is entirely in the suffix.
     */
    public const string TOKEN_PREFIX = 'phlix-mcp-';

    /** @var Connection Async MySQL connection used for all queries. */
    private Connection $db;

    /** @var int Token lifetime in seconds applied at mint time. */
    private int $ttlSeconds;

    /**
     * @param Connection $db         Workerman MySQL connection.
     * @param int        $ttlSeconds Token lifetime in seconds (clamped to a
     *                               positive value; defaults to 90 days).
     */
    public function __construct(Connection $db, int $ttlSeconds = self::DEFAULT_TTL_SECONDS)
    {
        $this->db = $db;
        $this->ttlSeconds = $ttlSeconds > 0 ? $ttlSeconds : self::DEFAULT_TTL_SECONDS;
    }

    /**
     * Whether a presented credential is shaped like an MCP PAT at all.
     *
     * Used only to choose the error message; it is NOT an authentication
     * decision and must never gate one. {@see validate()} is the only authority.
     *
     * @param string $token Presented bearer credential.
     */
    public static function looksLikeMcpToken(string $token): bool
    {
        return str_starts_with($token, self::TOKEN_PREFIX);
    }

    /**
     * Mint a fresh user-scoped MCP personal access token.
     *
     * The plaintext is returned to the caller and must be shown immediately —
     * it is NOT recoverable afterwards (only its hash is stored). The caller is
     * responsible for having authenticated `$userId` first; this method trusts
     * its argument, exactly as `ClientRelayTokenService::mint()` does.
     *
     * @param string       $userId Hub user UUID the token authenticates as.
     * @param string       $name   Operator-supplied label for the token list.
     * @param list<string> $scopes Requested scopes; unknown values are dropped.
     *
     * @return array{id: string, token: string, expires_at: int, scopes: list<string>}
     *         The row id, the plaintext token, its absolute Unix expiry, and
     *         the scopes actually granted (after {@see McpScopes} filtering).
     */
    public function mint(string $userId, string $name, array $scopes): array
    {
        $granted = McpScopes::parse(McpScopes::toStorage($scopes));
        $token = self::TOKEN_PREFIX . Ids::token();
        $id = Ids::uuidV4();
        $expiresAt = time() + $this->ttlSeconds;

        $this->db->query(
            'INSERT INTO mcp_tokens (id, token_hash, user_id, name, scopes, expires_at)'
                . ' VALUES (:id, :token_hash, :user_id, :name, :scopes, FROM_UNIXTIME(:expires_at))',
            [
                'id' => $id,
                'token_hash' => $this->hashToken($token),
                'user_id' => $userId,
                'name' => $name,
                'scopes' => McpScopes::toStorage($granted),
                'expires_at' => $expiresAt,
            ],
        );

        return [
            'id' => $id,
            'token' => $token,
            'expires_at' => $expiresAt,
            'scopes' => $granted,
        ];
    }

    /**
     * Validate a presented plaintext token.
     *
     * Hashes the token and looks up an active row (matching hash, not expired,
     * not revoked). Returns null for unknown, expired or revoked tokens so
     * callers fail closed.
     *
     * @param string $token The plaintext token presented by the client.
     *
     * @return McpToken|null The bound identity, or null when invalid.
     */
    public function validate(string $token): ?McpToken
    {
        if ($token === '') {
            return null;
        }

        /** @var mixed $rows */
        $rows = $this->db->query(
            'SELECT id, user_id, scopes FROM mcp_tokens'
                . ' WHERE token_hash = :token_hash'
                . ' AND revoked_at IS NULL'
                . ' AND expires_at > NOW()'
                . ' LIMIT 1',
            ['token_hash' => $this->hashToken($token)],
        );

        if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0])) {
            return null;
        }

        $row = $rows[0];
        /** @var mixed $id */
        $id = $row['id'] ?? null;
        /** @var mixed $userId */
        $userId = $row['user_id'] ?? null;
        /** @var mixed $scopes */
        $scopes = $row['scopes'] ?? '';
        if (!is_string($id) || !is_string($userId) || $userId === '') {
            return null;
        }

        return new McpToken($id, $userId, McpScopes::parse(is_string($scopes) ? $scopes : ''));
    }

    /**
     * Record that a token was just used successfully.
     *
     * Separate from {@see validate()} (which must stay a pure read) so the
     * write happens once per authenticated request rather than once per lookup.
     *
     * @param string $tokenId `mcp_tokens.id` of the row to touch.
     */
    public function touch(string $tokenId): void
    {
        if ($tokenId === '') {
            return;
        }

        $this->db->query(
            'UPDATE mcp_tokens SET last_used_at = NOW() WHERE id = :id',
            ['id' => $tokenId],
        );
    }

    /**
     * List a user's tokens for the management surface.
     *
     * Returns metadata ONLY — never `token_hash`, and there is no column that
     * could return the plaintext. Revoked and expired rows are included so the
     * owner can see the history; each row carries the flags to render that.
     *
     * @param string $userId Hub user UUID whose tokens to list.
     *
     * @return list<array{
     *     id: string,
     *     name: string,
     *     scopes: list<string>,
     *     created_at: int,
     *     expires_at: int,
     *     last_used_at: int|null,
     *     revoked: bool,
     *     expired: bool
     * }>
     */
    public function listForUser(string $userId): array
    {
        /** @var mixed $rows */
        $rows = $this->db->query(
            'SELECT id, name, scopes,'
                . ' UNIX_TIMESTAMP(created_at) AS created_ts,'
                . ' UNIX_TIMESTAMP(expires_at) AS expires_ts,'
                . ' UNIX_TIMESTAMP(last_used_at) AS last_used_ts,'
                . ' revoked_at'
                . ' FROM mcp_tokens WHERE user_id = :user_id'
                . ' ORDER BY created_at DESC',
            ['user_id' => $userId],
        );

        if (!is_array($rows)) {
            return [];
        }

        $now = time();
        $out = [];
        /** @var mixed $row */
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            /** @var mixed $id */
            $id = $row['id'] ?? null;
            if (!is_string($id)) {
                continue;
            }
            /** @var mixed $name */
            $name = $row['name'] ?? '';
            /** @var mixed $scopes */
            $scopes = $row['scopes'] ?? '';
            $expiresAt = self::toTimestamp($row['expires_ts'] ?? null) ?? 0;

            $out[] = [
                'id' => $id,
                'name' => is_string($name) ? $name : '',
                'scopes' => McpScopes::parse(is_string($scopes) ? $scopes : ''),
                'created_at' => self::toTimestamp($row['created_ts'] ?? null) ?? 0,
                'expires_at' => $expiresAt,
                'last_used_at' => self::toTimestamp($row['last_used_ts'] ?? null),
                'revoked' => ($row['revoked_at'] ?? null) !== null,
                'expired' => $expiresAt <= $now,
            ];
        }

        return $out;
    }

    /**
     * Revoke one of a user's tokens by row id.
     *
     * The `user_id` predicate is load-bearing, not decoration: without it a
     * caller who learned any token id could revoke another user's credential (a
     * denial-of-service on someone else's account). Returns false when the id
     * is unknown, already revoked, or belongs to somebody else — the caller
     * cannot distinguish those three, which is deliberate.
     *
     * @param string $userId  Hub user UUID that must own the row.
     * @param string $tokenId `mcp_tokens.id` to revoke.
     *
     * @return bool True when a row was revoked by this call.
     */
    public function revokeForUser(string $userId, string $tokenId): bool
    {
        if ($userId === '' || $tokenId === '') {
            return false;
        }

        /** @var mixed $result */
        $result = $this->db->query(
            'UPDATE mcp_tokens SET revoked_at = NOW()'
                . ' WHERE id = :id AND user_id = :user_id AND revoked_at IS NULL',
            ['id' => $tokenId, 'user_id' => $userId],
        );

        return is_int($result) && $result > 0;
    }

    /**
     * Prune tokens that expired more than 30 days ago OR were revoked.
     *
     * Mirrors {@see \Phlix\Hub\Hub\ClientRelayTokenService::pruneExpiredTokens()},
     * with a wider grace window because MCP tokens are long-lived and an
     * operator investigating "why did my agent stop working" wants the expired
     * row to still be listable for a while.
     *
     * Note the operator is OR, not AND: with AND only rows that were BOTH
     * expired-by->30-days AND revoked would go, leaving the common
     * expired-never-revoked rows to accumulate forever.
     *
     * @return int Number of rows deleted.
     */
    public function pruneExpiredTokens(): int
    {
        /** @var mixed $result */
        $result = $this->db->query(
            'DELETE FROM mcp_tokens'
                . ' WHERE expires_at < NOW() - INTERVAL 30 DAY'
                . ' OR revoked_at IS NOT NULL',
        );

        return is_int($result) ? $result : 0;
    }

    /**
     * Coerce a `UNIX_TIMESTAMP()` column (which the driver may hand back as an
     * int, a numeric string, or null) into an int, or null when absent.
     *
     * @param mixed $value Raw column value.
     */
    private static function toTimestamp(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '' && (string) (int) $value === $value) {
            return (int) $value;
        }

        return null;
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
