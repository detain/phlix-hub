<?php

/**
 * Phlix hub component: OAuth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\OAuth;

use Phlix\Hub\Common\Support\Ids;
use Workerman\MySQL\Connection;

use function hash;
use function is_array;
use function is_numeric;
use function is_string;
use function str_starts_with;
use function time;

/**
 * Mints, validates, rotates and revokes OAuth 2.0 access and refresh tokens
 * (S92).
 *
 * A near-clone of {@see \Phlix\Hub\Hub\ClientRelayTokenService} and
 * {@see \Phlix\Hub\Mcp\McpTokenService}, deliberately: the hashed-at-rest,
 * revocable, prune-on-a-timer convention is already established in this repo
 * and a fourth token store that invented its own would be the odd one out.
 * What it adds over `McpTokenService` is:
 *
 *  - **two kinds in one table** ({@see KIND_ACCESS}/{@see KIND_REFRESH}) with
 *    different TTLs, so `validateAccess()` can never be satisfied by a refresh
 *    token and vice versa;
 *  - **a client id**, because an OAuth token belongs to a third-party client
 *    and not only to a user; and
 *  - **a lineage handle** (`code_id`), so every token descended from one
 *    authorization code can be revoked in a single statement when that code is
 *    replayed (RFC 6749 §4.1.2) or when a rotated refresh token is re-presented
 *    (OAuth 2.0 Security BCP §4.14.2).
 *
 * ## Rotation
 *
 * {@see consumeRefresh()} revokes the presented refresh token in the same
 * atomic conditional `UPDATE` that authorises its use, so a refresh token is
 * strictly single-use. The caller then issues a fresh pair. That makes a
 * *stolen* refresh token detectable: the legitimate client will eventually
 * present the token the thief already burned, {@see consumeRefresh()} will
 * refuse it, and {@see revokedLineageFor()} identifies the whole lineage to cut.
 *
 * @package Phlix\Hub\OAuth
 * @since   S92 (shared OAuth 2.0 Authorization Server)
 */
final class OAuthTokenService
{
    /** Token kind: a bearer credential presented to protected resources. */
    public const string KIND_ACCESS = 'access';

    /** Token kind: a credential exchangeable for a fresh access token. */
    public const string KIND_REFRESH = 'refresh';

    /**
     * Access-token prefix.
     *
     * Distinct from the refresh prefix so a token pasted into a bug report is
     * identifiable at a glance, and so a client that swaps the two gets a clean
     * refusal rather than a confusing one. The prefix is NOT a security control
     * — {@see validateAccess()} still filters on the `kind` column, which is
     * what actually decides.
     */
    public const string ACCESS_TOKEN_PREFIX = 'phlix-oat-';

    /** Refresh-token prefix. See {@see ACCESS_TOKEN_PREFIX}. */
    public const string REFRESH_TOKEN_PREFIX = 'phlix-ort-';

    /** Access-token lifetime (1 hour). */
    public const int ACCESS_TTL_SECONDS = 3600;

    /** Refresh-token lifetime (30 days). */
    public const int REFRESH_TTL_SECONDS = 2592000;

    private int $accessTtl;

    private int $refreshTtl;

    /**
     * @param Connection $db         Workerman MySQL connection.
     * @param int        $accessTtl  Access-token lifetime; non-positive falls back to the default.
     * @param int        $refreshTtl Refresh-token lifetime; non-positive falls back to the default.
     */
    public function __construct(
        private readonly Connection $db,
        int $accessTtl = self::ACCESS_TTL_SECONDS,
        int $refreshTtl = self::REFRESH_TTL_SECONDS,
    ) {
        $this->accessTtl  = $accessTtl > 0 ? $accessTtl : self::ACCESS_TTL_SECONDS;
        $this->refreshTtl = $refreshTtl > 0 ? $refreshTtl : self::REFRESH_TTL_SECONDS;
    }

    /**
     * Whether a presented string looks like an access token this server issued.
     *
     * A cheap discriminator for a future resource-server middleware that has to
     * tell an OAuth access token apart from an HS256 session JWT and an MCP PAT
     * on the same `Authorization` header. Not an authorisation decision.
     *
     * @param string $token Presented bearer credential.
     */
    public static function looksLikeAccessToken(string $token): bool
    {
        return str_starts_with($token, self::ACCESS_TOKEN_PREFIX);
    }

    /**
     * Issue an access/refresh pair for a consented grant.
     *
     * ⚠ `$scopes` must be the scope set the USER consented to
     * ({@see AuthorizationCode::$scopes}), never a scope list re-read from the
     * token request. A client that could restate its scopes here would be able
     * to redeem a code consented for `phlix:profile:read` and receive
     * `mcp:playback:control`.
     *
     * @param string       $clientId Client the tokens belong to.
     * @param string       $userId   Hub user the tokens act as.
     * @param list<string> $scopes   Consented scopes.
     * @param string|null  $codeId   Lineage handle — the authorization code these
     *                               tokens descend from.
     *
     * @return array{
     *     access_token: string,
     *     refresh_token: string,
     *     token_type: string,
     *     expires_in: int,
     *     scope: string
     * } An RFC 6749 §5.1 token response body. Both plaintexts are returned
     *   exactly once and are unrecoverable afterwards.
     */
    public function issue(string $clientId, string $userId, array $scopes, ?string $codeId): array
    {
        $accessToken  = self::ACCESS_TOKEN_PREFIX . Ids::token();
        $refreshToken = self::REFRESH_TOKEN_PREFIX . Ids::token();
        $storedScopes = OAuthScopes::toStorage($scopes);
        $now          = time();

        $this->insert(
            $accessToken,
            self::KIND_ACCESS,
            $clientId,
            $userId,
            $storedScopes,
            $now + $this->accessTtl,
            $codeId,
        );
        $this->insert(
            $refreshToken,
            self::KIND_REFRESH,
            $clientId,
            $userId,
            $storedScopes,
            $now + $this->refreshTtl,
            $codeId,
        );

        return [
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type'    => 'Bearer',
            'expires_in'    => $this->accessTtl,
            'scope'         => $storedScopes,
        ];
    }

    /**
     * Persist one token row.
     *
     * @param string      $token     Plaintext token (hashed here; never stored).
     * @param string      $kind      {@see KIND_ACCESS} or {@see KIND_REFRESH}.
     * @param string      $clientId  Owning client.
     * @param string      $userId    Owning user.
     * @param string      $scopes    Space-delimited stored scope list.
     * @param int         $expiresAt Absolute Unix expiry.
     * @param string|null $codeId    Lineage handle.
     */
    private function insert(
        string $token,
        string $kind,
        string $clientId,
        string $userId,
        string $scopes,
        int $expiresAt,
        ?string $codeId,
    ): void {
        $this->db->query(
            'INSERT INTO oauth_tokens (id, token_hash, kind, client_id, user_id, scopes, code_id, expires_at)'
                . ' VALUES (:id, :token_hash, :kind, :client_id, :user_id, :scopes, :code_id,'
                . ' FROM_UNIXTIME(:expires_at))',
            [
                'id'         => Ids::uuidV4(),
                'token_hash' => hash('sha256', $token),
                'kind'       => $kind,
                'client_id'  => $clientId,
                'user_id'    => $userId,
                'scopes'     => $scopes,
                'code_id'    => $codeId,
                'expires_at' => $expiresAt,
            ],
        );
    }

    /**
     * Validate a presented access token.
     *
     * Filters on `kind = 'access'`, so a refresh token presented as a bearer
     * credential is rejected even though both live in the same table.
     *
     * @param string $token Presented plaintext access token.
     *
     * @return OAuthGrant|null The bound identity + scopes, or null when the
     *         token is unknown, expired, revoked, or of the wrong kind.
     */
    public function validateAccess(string $token): ?OAuthGrant
    {
        return $this->lookupActive($token, self::KIND_ACCESS);
    }

    /**
     * Atomically claim a refresh token exactly once, revoking it in the same
     * statement.
     *
     * Refresh tokens ROTATE: the caller must issue a replacement pair. The
     * revocation and the authorisation decision are one conditional `UPDATE`,
     * so two concurrent refreshes cannot both succeed.
     *
     * @param string $token Presented plaintext refresh token.
     *
     * @return OAuthGrant|null What the token was bound to, or null when it is
     *         unknown, expired, already rotated, or of the wrong kind.
     */
    public function consumeRefresh(string $token): ?OAuthGrant
    {
        if ($token === '') {
            return null;
        }

        $hash = hash('sha256', $token);

        /** @var mixed $updateResult */
        $updateResult = $this->db->query(
            'UPDATE oauth_tokens SET revoked_at = NOW()'
                . ' WHERE token_hash = :token_hash AND kind = :kind'
                . ' AND revoked_at IS NULL AND expires_at > NOW()',
            ['token_hash' => $hash, 'kind' => self::KIND_REFRESH],
        );

        if ((is_numeric($updateResult) ? (int) $updateResult : 0) !== 1) {
            return null;
        }

        /** @var mixed $rows */
        $rows = $this->db->query(
            'SELECT id, kind, client_id, user_id, scopes, code_id FROM oauth_tokens'
                . ' WHERE token_hash = :token_hash LIMIT 1',
            ['token_hash' => $hash],
        );

        return self::hydrate($rows);
    }

    /**
     * The lineage handle of a refresh token that EXISTS but is no longer usable
     * because it was already rotated or revoked.
     *
     * Returned so the caller can cut the whole family when a burned refresh
     * token reappears — the signature of a stolen one. Returns null for a token
     * that never existed (a typo is not a breach).
     *
     * @param string $token Presented plaintext refresh token.
     *
     * @return string|null The `code_id` lineage handle, when one is recorded.
     */
    public function revokedLineageFor(string $token): ?string
    {
        if ($token === '') {
            return null;
        }

        /** @var mixed $rows */
        $rows = $this->db->query(
            'SELECT code_id FROM oauth_tokens'
                . ' WHERE token_hash = :token_hash AND kind = :kind AND revoked_at IS NOT NULL LIMIT 1',
            ['token_hash' => hash('sha256', $token), 'kind' => self::KIND_REFRESH],
        );

        if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0])) {
            return null;
        }

        /** @var mixed $codeId */
        $codeId = $rows[0]['code_id'] ?? null;

        return is_string($codeId) && $codeId !== '' ? $codeId : null;
    }

    /**
     * Look up an active token of a specific kind.
     *
     * @param string $token Plaintext token.
     * @param string $kind  {@see KIND_ACCESS} or {@see KIND_REFRESH}.
     */
    private function lookupActive(string $token, string $kind): ?OAuthGrant
    {
        if ($token === '') {
            return null;
        }

        /** @var mixed $rows */
        $rows = $this->db->query(
            'SELECT id, kind, client_id, user_id, scopes, code_id FROM oauth_tokens'
                . ' WHERE token_hash = :token_hash AND kind = :kind'
                . ' AND revoked_at IS NULL AND expires_at > NOW() LIMIT 1',
            ['token_hash' => hash('sha256', $token), 'kind' => $kind],
        );

        return self::hydrate($rows);
    }

    /**
     * Turn a result set into an {@see OAuthGrant}, failing closed.
     *
     * @param mixed $rows Raw query result.
     */
    private static function hydrate(mixed $rows): ?OAuthGrant
    {
        if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0])) {
            return null;
        }

        $row      = $rows[0];
        $id       = $row['id'] ?? null;
        $kind     = $row['kind'] ?? null;
        $clientId = $row['client_id'] ?? null;
        $userId   = $row['user_id'] ?? null;
        $scopes   = $row['scopes'] ?? null;

        if (
            !is_string($id) || !is_string($kind) || !is_string($clientId)
            || !is_string($userId) || !is_string($scopes)
        ) {
            return null;
        }

        $parsed = OAuthScopes::parse($scopes);
        if ($parsed === []) {
            // A token whose stored scopes no longer resolve to anything known
            // grants nothing. Refuse it rather than returning a grant with an
            // empty capability list that a caller might read as "unrestricted".
            return null;
        }

        /** @var mixed $codeId */
        $codeId = $row['code_id'] ?? null;

        return new OAuthGrant(
            $id,
            $kind,
            $clientId,
            $userId,
            $parsed,
            is_string($codeId) && $codeId !== '' ? $codeId : null,
        );
    }

    /**
     * Revoke every live token descended from one authorization code.
     *
     * @param string $codeId Lineage handle.
     *
     * @return int Rows revoked.
     */
    public function revokeForCode(string $codeId): int
    {
        if ($codeId === '') {
            return 0;
        }

        /** @var mixed $result */
        $result = $this->db->query(
            'UPDATE oauth_tokens SET revoked_at = NOW() WHERE code_id = :code_id AND revoked_at IS NULL',
            ['code_id' => $codeId],
        );

        return is_numeric($result) ? (int) $result : 0;
    }

    /**
     * Revoke every live token a user granted to one client — the "disconnect
     * this app" action.
     *
     * @param string $userId   Hub user.
     * @param string $clientId Client to disconnect.
     *
     * @return int Rows revoked.
     */
    public function revokeForUserClient(string $userId, string $clientId): int
    {
        /** @var mixed $result */
        $result = $this->db->query(
            'UPDATE oauth_tokens SET revoked_at = NOW()'
                . ' WHERE user_id = :user_id AND client_id = :client_id AND revoked_at IS NULL',
            ['user_id' => $userId, 'client_id' => $clientId],
        );

        return is_numeric($result) ? (int) $result : 0;
    }

    /**
     * Revoke every live token issued to one client — used when a client is
     * decommissioned or compromised.
     *
     * @param string $clientId Client to cut off.
     *
     * @return int Rows revoked.
     */
    public function revokeForClient(string $clientId): int
    {
        /** @var mixed $result */
        $result = $this->db->query(
            'UPDATE oauth_tokens SET revoked_at = NOW() WHERE client_id = :client_id AND revoked_at IS NULL',
            ['client_id' => $clientId],
        );

        return is_numeric($result) ? (int) $result : 0;
    }

    /**
     * Delete tokens that expired more than a day ago, OR were revoked.
     *
     * Note the operator is OR, not AND: with AND only tokens that were BOTH
     * long-expired AND revoked would go, leaving the common
     * expired-never-revoked rows to accumulate forever. Same reasoning, and the
     * same predicate, as `ClientRelayTokenService::pruneExpiredTokens()`.
     *
     * @return int Rows deleted.
     */
    public function pruneExpired(): int
    {
        /** @var mixed $result */
        $result = $this->db->query(
            'DELETE FROM oauth_tokens WHERE expires_at < NOW() - INTERVAL 1 DAY OR revoked_at IS NOT NULL',
        );

        return is_numeric($result) ? (int) $result : 0;
    }
}
