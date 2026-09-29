<?php

/**
 * Phlix hub component: Auth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Auth;

use Workerman\MySQL\Connection;

use function is_array;
use function is_numeric;

/**
 * Server-side revocation registry for hub refresh-token JWTs.
 *
 * Refresh JWTs are stateless by design, which left the old `logout()` a
 * no-op beyond an audit line: the presented refresh token stayed mintable
 * for its full 7-day rolling TTL, in direct contradiction of what the
 * openapi logout description promises ("…invalidates the refresh token").
 * The spec is the authority, so the statelessness trade-off is closed with
 * the smallest store that ends a lineage: one row per revoked `jti`,
 * consulted by {@see AuthManager::refresh()} before it mints.
 *
 * Only the `jti` (a random 32-hex id this handler minted) is persisted —
 * never the token itself — so the table leaks nothing usable even if read.
 *
 * Rows are self-cleaning: a revoked `jti` is dead weight the moment the
 * token it names expires on its own, so {@see revoke()} prunes rows whose
 * `expires_at` has passed on every write. This table deliberately does NOT
 * hang off the Relay `IdleReaper` sweep — the reaper's cadence and worker
 * ownership belong to a surface this class must not depend on, and a
 * write-time prune on a registry this small is both simpler and structural
 * (it cannot silently stop firing).
 *
 * Not `final` so {@see AuthManager} unit tests can substitute a double
 * without a database.
 *
 * @package Phlix\Hub\Auth
 */
class RefreshTokenRevocationService
{
    /** Retention grace kept for symmetry with the OAuth stores' prune arm. */
    private const int PRUNE_GRACE_SECONDS = 86400;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Record `$jti` as revoked. Idempotent — re-logout of the same token is
     * a no-op that still returns true.
     *
     * @param string $jti       Token id claim of the refresh JWT.
     * @param string $userId    Subject the token was minted for (audit column).
     * @param int    $expiresAt Unix expiry of the token itself; prunes the row later.
     */
    public function revoke(string $jti, string $userId, int $expiresAt): bool
    {
        if ($jti === '') {
            return false;
        }

        $this->pruneExpired();

        /** @var mixed $result */
        $result = $this->db->query(
            'INSERT IGNORE INTO auth_revoked_refresh_tokens (jti, user_id, expires_at)'
                . ' VALUES (:jti, :user_id, FROM_UNIXTIME(:expires_at))',
            [
                'jti'        => $jti,
                'user_id'    => $userId,
                'expires_at' => $expiresAt,
            ],
        );

        return is_numeric($result) || $this->isRevoked($jti);
    }

    /**
     * Whether `$jti` was revoked before its own expiry (an expired row is a
     * dead token either way, so the cutoff keeps the answer honest even
     * between prunes).
     */
    public function isRevoked(string $jti): bool
    {
        if ($jti === '') {
            return false;
        }

        /** @var mixed $rows */
        $rows = $this->db->query(
            'SELECT 1 AS revoked FROM auth_revoked_refresh_tokens'
                . ' WHERE jti = :jti AND expires_at > NOW() LIMIT 1',
            ['jti' => $jti],
        );

        return is_array($rows) && isset($rows[0]);
    }

    /**
     * Delete rows whose token has itself expired (plus a short grace so a
     * clock-skewed mint cannot resurrect a pruned jti inside the window).
     *
     * @return int Rows deleted.
     */
    public function pruneExpired(): int
    {
        /** @var mixed $result */
        $result = $this->db->query(
            'DELETE FROM auth_revoked_refresh_tokens'
                . ' WHERE expires_at < NOW() - INTERVAL ' . self::PRUNE_GRACE_SECONDS . ' SECOND',
        );

        return is_numeric($result) ? (int) $result : 0;
    }
}
