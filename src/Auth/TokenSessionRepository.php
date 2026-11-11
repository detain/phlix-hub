<?php

/**
 * Phlix hub component: Auth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Auth;

use Phlix\Hub\Common\Support\Ids;
use Workerman\MySQL\Connection;

/**
 * Session-family store for the hub's JWT auth path (CP-029 / D-1a — twin of
 * the server's `Phlix\Auth\TokenSessionRepository` at 413765b6).
 *
 * ## What this is, and what it deliberately is not
 *
 * One row in `auth_token_sessions` is one LOGIN ceremony — a family. Every
 * access/refresh pair minted from that ceremony (including every later
 * rotation of the same refresh chain) carries the family id as the `sid` JWT
 * claim. Revoking one row ends exactly one device's chain. The account-wide
 * bound stays where the hub always had it: the carrier layer — the `jti`
 * revocation registry written by logout ({@see RefreshTokenRevocationService})
 * and the users FK CASCADE that sweeps both tables on account deletion. The
 * hub has no per-user token watermark, so unlike the server twin this class
 * has no choke-point caller for {@see self::revokeAllForUser()}; the
 * primitive ships for schema/API parity with the server store and is
 * convicted by the real-DB leg that pins its exact blast radius.
 *
 * `auth_refresh_grants` is the theft sensor: every MINTED refresh token
 * (identified by its `jti`) gets a row. Rotation marks the presented token
 * consumed. A replay of a consumed token outside the grace window is
 * indistinguishable from theft — the family dies. A replay inside the window
 * is the benign multi-tab race the server's CP-005 grace rule exists for — it
 * rotates on without revoking anything.
 *
 * ## Live-read law (CP-024 lineage, de573d6e)
 *
 * Every method here issues its query directly against the connection. There is
 * deliberately NO in-memory cache in this class: a resident worker that
 * memoized `isSessionActive()` would turn a per-device revocation into a
 * stale-cache window on exactly the security decision it exists to make
 * immediate. The repository layer stays a pure projection of the database
 * clock.
 *
 * SQL uses the hub's named-placeholder convention exclusively (AGENTS law:
 * positional `?` breaks vendored bindMore in this repo — the server twin's
 * positional style must NOT be copied verbatim).
 *
 * Not final so tests can substitute a double (same house note as
 * {@see RefreshTokenRevocationService}).
 *
 * @see \Phlix\Hub\Auth\AuthManager The token-path consumer.
 * @see \Phlix\Hub\Http\Middleware\AuthMiddleware The access-path liveness reader.
 *
 * @since CP-029 (D-1a session-family revocation)
 */
class TokenSessionRepository
{
    /** @var Connection Database connection (Workerman MySQL, the memoised 'txn' handle family) */
    private Connection $db;

    /**
     * @param Connection $db Live MySQL connection.
     */
    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Mint a new login family and return its id (the future `sid` claim).
     *
     * The row is written BEFORE the tokens are signed, so a minted token can
     * never reference a family the database does not have (the fail-closed
     * read path would reject the very first request otherwise). Device
     * columns are optional display metadata — the hub mints without any today
     * (its login `deviceId` stays in the audit trail), so the session list
     * honestly shows NULL.
     *
     * @param string      $userId     Owner of the family.
     * @param string|null $deviceType Display metadata ('tv', 'mobile', …) or null.
     * @param string|null $deviceName Display metadata, untrusted, length-capped
     *                                by the VARCHAR(255) column.
     *
     * @return string The 36-char UUID family id.
     */
    public function createSession(string $userId, ?string $deviceType = null, ?string $deviceName = null): string
    {
        $sessionId = Ids::uuidV4();

        $this->db->query(
            'INSERT INTO auth_token_sessions (id, user_id, device_type, device_name)'
            . ' VALUES (:id, :user_id, :device_type, :device_name)',
            [
                'id' => $sessionId,
                'user_id' => $userId,
                'device_type' => $deviceType,
                'device_name' => $deviceName,
            ],
        );

        return $sessionId;
    }

    /**
     * Is this family still alive? Single PK read, per call, uncached by design.
     *
     * Fail-closed truth table: a missing row returns false exactly like a
     * revoked one — a `sid` this store has never seen cannot authenticate
     * (whether the row was pruned, never applied, or the database moved under
     * the token, the honest answer to "is this live?" is no).
     *
     * @param string $sessionId Family id from the `sid` claim.
     *
     * @return bool True only for a row whose status is literally 'active'.
     */
    public function isSessionActive(string $sessionId): bool
    {
        /** @var mixed $rows */
        $rows = $this->db->query(
            'SELECT status FROM auth_token_sessions WHERE id = :id',
            ['id' => $sessionId],
        );
        if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0])) {
            return false;
        }

        /** @var mixed $status */
        $status = $rows[0]['status'] ?? null;

        return is_string($status) && $status === 'active';
    }

    /**
     * Read the presented refresh token's grant state in ONE joined query.
     *
     * The returned shape is the whole reuse-detection decision table, computed
     * on the DATABASE clock (same basis the `consumed_at` write uses — a
     * PHP-side clock here would make the grace window two clocks wide):
     *
     *  - null                → unknown jti (never minted or the store moved):
     *                          callers MUST fail closed.
     *  - consumed=false      → live token: rotate it.
     *  - consumed=true,
     *    withinGrace=true   → replay inside the grace window: benign multi-tab
     *                          race, rotate WITHOUT revoking the family.
     *  - consumed=true,
     *    withinGrace=false → REUSE DETECTED: revoke the family, audit, reject.
     *  - sessionStatus      → the owning family's status, joined in the same
     *                          read so a revoked family short-circuits before
     *                          any rotation is honored (no two-query TOCTOU).
     *
     * @param string $jti          The refresh token's `jti` claim (32 hex chars).
     * @param int    $graceSeconds Width of the grace window, supplied by the
     *                             caller's design constant (the server CP-005's
     *                             ten seconds, mirrored hub-side) so one number
     *                             rules both the store read and the mint stamp.
     *
     * @return array{jti: string, sessionId: string, sessionStatus: string,
     *     consumed: bool, withinGrace: bool}|null
     */
    public function findGrant(string $jti, int $graceSeconds): ?array
    {
        // The grace width is inlined, never bound as a placeholder —
        // `INTERVAL ? SECOND` is exactly the shape vendored bindMore has been
        // bitten by before, and the native int parameter type makes the
        // interpolation injection-free by construction (server twin ships the
        // same decision).
        /** @var mixed $rows */
        $rows = $this->db->query(
            'SELECT g.jti AS jti, g.session_id AS session_id, s.status AS session_status,'
            . ' (g.consumed_at IS NOT NULL) AS consumed,'
            . ' (g.consumed_at >= DATE_SUB(NOW(), INTERVAL ' . $graceSeconds . ' SECOND)) AS within_grace'
            . ' FROM auth_refresh_grants g'
            . ' JOIN auth_token_sessions s ON s.id = g.session_id'
            . ' WHERE g.jti = :jti',
            ['jti' => $jti],
        );
        if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0])) {
            return null;
        }

        // Parse-don't-validate at the SQL boundary: the SELECT above fixes the
        // column list and the computed flags are MySQL 0/1 integers — the
        // shape annotation is the trust boundary, the same narrowing idiom
        // UserRepository::findById() ships.
        /** @var array{jti: string, session_id: string, session_status: string, consumed: int, within_grace: int} $grant */
        $grant = $rows[0];

        return [
            'jti' => $grant['jti'],
            'sessionId' => $grant['session_id'],
            'sessionStatus' => $grant['session_status'],
            'consumed' => $grant['consumed'] === 1,
            'withinGrace' => $grant['within_grace'] === 1,
        ];
    }

    /**
     * Register a freshly minted refresh token as a grant of this family.
     *
     * Written at MINT time (not first use): a token that is signed, delivered
     * and never registered would be indistinguishable from an unknown forged
     * jti on presentation, so the row must exist the moment the bytes exist.
     *
     * @param string $jti       The new refresh token's `jti`.
     * @param string $sessionId Family that owns it.
     *
     * @return void
     */
    public function recordGrant(string $jti, string $sessionId): void
    {
        $this->db->query(
            'INSERT INTO auth_refresh_grants (jti, session_id) VALUES (:jti, :session_id)',
            ['jti' => $jti, 'session_id' => $sessionId],
        );
    }

    /**
     * Mark the presented refresh token as used (rotation consumed it).
     *
     * The `AND consumed_at IS NULL` guard keeps a double-consume from moving
     * the timestamp — the idempotence the grace window is measured from.
     *
     * @param string $jti Presented token's `jti`.
     *
     * @return void
     */
    public function markGrantConsumed(string $jti): void
    {
        $this->db->query(
            'UPDATE auth_refresh_grants SET consumed_at = NOW() WHERE jti = :jti AND consumed_at IS NULL',
            ['jti' => $jti],
        );
    }

    /**
     * Advance the family's last-used watermark (display metadata for the
     * session list ONLY — deliberately not on the access-token hot path,
     * which stays a pure read).
     *
     * @param string $sessionId Family id.
     *
     * @return void
     */
    public function touchSession(string $sessionId): void
    {
        $this->db->query(
            'UPDATE auth_token_sessions SET last_used_at = NOW() WHERE id = :id',
            ['id' => $sessionId],
        );
    }

    /**
     * End exactly one family (per-device sign-out, reuse revocation).
     *
     * The carrier layer is NOT touched: per-device semantics are the whole
     * point of this capability, and the presented `jti` registry stays
     * exactly where COOKIE-DESIGN left it. Idempotent at the SQL level
     * (`AND status = 'active'`) so a double-revoke rewrites neither the
     * reason nor the timestamp.
     *
     * @param string $sessionId Family id to revoke.
     * @param string $reason    Audit context ('logout', 'refresh_token_reuse',
     *                          'user_revoked' …) — free-form, never a wire code.
     *
     * @return void
     */
    public function revokeSession(string $sessionId, string $reason): void
    {
        $this->db->query(
            "UPDATE auth_token_sessions SET status = 'revoked', revoke_reason = :reason, revoked_at = NOW()"
            . " WHERE id = :id AND status = 'active'",
            ['reason' => $reason, 'id' => $sessionId],
        );
    }

    /**
     * End EVERY family of one user. Shipped for parity with the server store;
     * the hub has NO production call site (no watermark choke point exists to
     * fold it into — see class docblock). User deletion already sweeps these
     * rows through the users FK CASCADE; this primitive is for the explicit
     * account-wide action a future hub surface may want, convicted here so it
     * is never shipped-untested.
     *
     * @param string $userId User whose families all die.
     * @param string $reason Audit context carried into `revoke_reason`.
     *
     * @return void
     */
    public function revokeAllForUser(string $userId, string $reason): void
    {
        $this->db->query(
            "UPDATE auth_token_sessions SET status = 'revoked', revoke_reason = :reason, revoked_at = NOW()"
            . " WHERE user_id = :user_id AND status = 'active'",
            ['reason' => $reason, 'user_id' => $userId],
        );
    }

    /**
     * Does this family belong to this user? Ownership gate for the revoke
     * endpoint (a foreign family id must be indistinguishable from a missing
     * one — same 404, never a 403 oracle).
     *
     * @param string $sessionId Family id from the path.
     * @param string $userId    Authenticated requester.
     *
     * @return bool
     */
    public function sessionBelongsToUser(string $sessionId, string $userId): bool
    {
        /** @var mixed $rows */
        $rows = $this->db->query(
            'SELECT id FROM auth_token_sessions WHERE id = :id AND user_id = :user_id',
            ['id' => $sessionId, 'user_id' => $userId],
        );
        if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0])) {
            return false;
        }

        return ($rows[0]['id'] ?? null) !== null;
    }

    /**
     * The user's still-alive families, newest activity first — the payload
     * behind GET /api/v1/auth/sessions.
     *
     * Deliberately active-only: a revoked family has already done its job (the
     * client saw the 204); resurrecting it in the list would invite "revive"
     * questions this schema has no answer for.
     *
     * @param string $userId Owner.
     *
     * @return list<array{id: string, created_at: string, last_used_at: string,
     *     device_type: string|null, device_name: string|null}>
     */
    public function listActiveSessions(string $userId): array
    {
        /** @var mixed $rows */
        $rows = $this->db->query(
            'SELECT id, created_at, last_used_at, device_type, device_name'
            . " FROM auth_token_sessions WHERE user_id = :user_id AND status = 'active'"
            . ' ORDER BY last_used_at DESC',
            ['user_id' => $userId],
        );
        if (!is_array($rows)) {
            return [];
        }

        $sessions = [];
        /** @var mixed $raw */
        foreach ($rows as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            // Shape proven by the SELECT's fixed column list (same boundary
            // narrowing as findGrant()); timestamps arrive as MySQL
            // DATETIME->string, the two device columns are the only NULLs.
            /** @var array{id: string, created_at: string, last_used_at: string, device_type: string|null, device_name: string|null} $row */
            $row = $raw;
            $sessions[] = [
                'id' => $row['id'],
                'created_at' => $row['created_at'],
                'last_used_at' => $row['last_used_at'],
                'device_type' => $row['device_type'] ?? null,
                'device_name' => $row['device_name'] ?? null,
            ];
        }

        return $sessions;
    }
}
