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
use function time;

/**
 * Mints and redeems OAuth 2.0 authorization codes (S92).
 *
 * ## The four properties a code must have, and where each one lives
 *
 * 1. **Short-lived** — {@see DEFAULT_TTL_SECONDS} is 60 seconds. RFC 6749 §4.1.2
 *    permits up to 10 minutes; a code is exchanged by a server-to-server call
 *    that happens within a second of the redirect, so a minute is generous and
 *    ten is a window somebody could act inside. The expiry is enforced DB-side
 *    inside the claiming `UPDATE`, not by comparing timestamps in PHP after the
 *    row is read.
 *
 * 2. **Single-use** — {@see consume()} claims the row with one conditional
 *    `UPDATE` whose `WHERE` includes `consumed_at IS NULL`. Exactly one of N
 *    concurrent redemptions sees one affected row; the losers see zero. The
 *    same idiom as `InviteLinkHandler::redeem()`, for the same reason: a
 *    read-then-write pair is a check-then-act race and would let two token
 *    requests both redeem one code.
 *
 * 3. **Bound** — {@see AuthorizationCode} carries the `client_id`,
 *    `redirect_uri`, `code_challenge` and scope set the code was minted
 *    against. This service does not check them; it hands them to
 *    {@see \Phlix\Hub\Http\Controllers\OAuthController::token()} to compare
 *    against what the redeemer presented. Keeping the comparison at the
 *    endpoint keeps all four checks visible in one place rather than half here
 *    and half there.
 *
 * 4. **Hashed at rest** — only `SHA-256(code)` is stored, per
 *    {@see \Phlix\Hub\Hub\ClientRelayTokenService}.
 *
 * ## Replay detection is a separate question from consumption
 *
 * {@see consume()} answers "may I use this?" and {@see replayedCodeId()}
 * answers "was this a code that has already been used?". They are separate
 * methods because a `null` from `consume()` covers three different situations —
 * never existed, expired, already used — and only the third one warrants
 * revoking the tokens that were issued the first time (RFC 6749 §4.1.2: the
 * authorization server SHOULD revoke all tokens previously issued based on a
 * replayed code). Folding them together would either revoke on a typo or fail
 * to revoke on a genuine replay.
 *
 * @package Phlix\Hub\OAuth
 * @since   S92 (shared OAuth 2.0 Authorization Server)
 */
final class AuthorizationCodeService
{
    /** Authorization code lifetime in seconds. */
    public const int DEFAULT_TTL_SECONDS = 60;

    private int $ttlSeconds;

    /**
     * @param Connection $db         Workerman MySQL connection.
     * @param int        $ttlSeconds Code lifetime; non-positive falls back to the default.
     */
    public function __construct(private readonly Connection $db, int $ttlSeconds = self::DEFAULT_TTL_SECONDS)
    {
        $this->ttlSeconds = $ttlSeconds > 0 ? $ttlSeconds : self::DEFAULT_TTL_SECONDS;
    }

    /**
     * Mint a code bound to everything the token endpoint will later re-check.
     *
     * @param string       $clientId      Client the code is issued to.
     * @param string       $userId        Hub user who consented.
     * @param string       $redirectUri   Exact registered redirect URI in use.
     * @param list<string> $scopes        Scopes the user consented to.
     * @param string       $codeChallenge S256 challenge the redeemer must prove.
     *
     * @return array{code: string, id: string, expires_at: int} Plaintext code
     *         (returned exactly once), its row id, and its absolute Unix expiry.
     */
    public function mint(
        string $clientId,
        string $userId,
        string $redirectUri,
        array $scopes,
        string $codeChallenge,
    ): array {
        $code      = Ids::token();
        $id        = Ids::uuidV4();
        $expiresAt = time() + $this->ttlSeconds;

        $this->db->query(
            'INSERT INTO oauth_authorization_codes'
                . ' (id, code_hash, client_id, user_id, redirect_uri, scopes, code_challenge, expires_at)'
                . ' VALUES (:id, :code_hash, :client_id, :user_id, :redirect_uri, :scopes, :code_challenge,'
                . ' FROM_UNIXTIME(:expires_at))',
            [
                'id'             => $id,
                'code_hash'      => hash('sha256', $code),
                'client_id'      => $clientId,
                'user_id'        => $userId,
                'redirect_uri'   => $redirectUri,
                'scopes'         => OAuthScopes::toStorage($scopes),
                'code_challenge' => $codeChallenge,
                'expires_at'     => $expiresAt,
            ],
        );

        return ['code' => $code, 'id' => $id, 'expires_at' => $expiresAt];
    }

    /**
     * Atomically claim a code exactly once.
     *
     * The `UPDATE` is the claim; the `SELECT` that follows deliberately does not
     * re-test `consumed_at IS NULL`, because this caller is the one that just
     * set it.
     *
     * @param string $code Plaintext code from the token request.
     *
     * @return AuthorizationCode|null The bindings to check, or null when the
     *         code is unknown, expired, or already redeemed.
     */
    public function consume(string $code): ?AuthorizationCode
    {
        if ($code === '') {
            return null;
        }

        $hash = hash('sha256', $code);

        /** @var mixed $updateResult */
        $updateResult = $this->db->query(
            'UPDATE oauth_authorization_codes SET consumed_at = NOW()'
                . ' WHERE code_hash = :code_hash AND consumed_at IS NULL AND expires_at > NOW()',
            ['code_hash' => $hash],
        );

        if ((is_numeric($updateResult) ? (int) $updateResult : 0) !== 1) {
            return null;
        }

        /** @var mixed $rows */
        $rows = $this->db->query(
            'SELECT id, client_id, user_id, redirect_uri, scopes, code_challenge'
                . ' FROM oauth_authorization_codes WHERE code_hash = :code_hash LIMIT 1',
            ['code_hash' => $hash],
        );

        if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0])) {
            return null;
        }

        $row         = $rows[0];
        $id          = $row['id'] ?? null;
        $clientId    = $row['client_id'] ?? null;
        $userId      = $row['user_id'] ?? null;
        $redirectUri = $row['redirect_uri'] ?? null;
        $scopes      = $row['scopes'] ?? null;
        $challenge   = $row['code_challenge'] ?? null;

        if (
            !is_string($id) || !is_string($clientId) || !is_string($userId)
            || !is_string($redirectUri) || !is_string($scopes) || !is_string($challenge)
        ) {
            return null;
        }

        $parsedScopes = OAuthScopes::parse($scopes);
        if ($parsedScopes === []) {
            return null;
        }

        return new AuthorizationCode($id, $clientId, $userId, $redirectUri, $parsedScopes, $challenge);
    }

    /**
     * The row id of a code that EXISTS and has ALREADY been redeemed.
     *
     * Returns null for a code that never existed and for one that merely
     * expired unused — neither is a replay, and neither should cost a client
     * its live tokens.
     *
     * @param string $code Plaintext code that {@see consume()} just refused.
     *
     * @return string|null Row id when this is a genuine replay.
     */
    public function replayedCodeId(string $code): ?string
    {
        if ($code === '') {
            return null;
        }

        /** @var mixed $rows */
        $rows = $this->db->query(
            'SELECT id FROM oauth_authorization_codes'
                . ' WHERE code_hash = :code_hash AND consumed_at IS NOT NULL LIMIT 1',
            ['code_hash' => hash('sha256', $code)],
        );

        if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0])) {
            return null;
        }

        $id = $rows[0]['id'] ?? null;

        return is_string($id) ? $id : null;
    }

    /**
     * Delete codes that expired more than a day ago, and every consumed one.
     *
     * ⚠ The 1-day grace on the expiry arm is what keeps {@see replayedCodeId()}
     * able to answer for a code redeemed moments ago — but the consumed arm
     * deletes those immediately, which would defeat it. Consumed rows are
     * therefore kept for the same day: the predicate is
     * `expires_at < NOW() - INTERVAL 1 DAY`, applied to both arms.
     *
     * @return int Rows deleted.
     */
    public function pruneExpired(): int
    {
        /** @var mixed $result */
        $result = $this->db->query(
            'DELETE FROM oauth_authorization_codes WHERE expires_at < NOW() - INTERVAL 1 DAY',
        );

        return is_numeric($result) ? (int) $result : 0;
    }
}
