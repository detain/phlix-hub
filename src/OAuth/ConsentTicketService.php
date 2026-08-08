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
 * Issues and redeems the single-use ticket that carries an authorization
 * request from the consent SCREEN to the consent DECISION (S92).
 *
 * ## This class is what makes consent enforceable rather than decorative
 *
 * `GET /oauth/authorize` renders a page. A page is not a security control —
 * a client that simply skips it, or a user who edits the URL, must not end up
 * with an authorization code. The enforcement is structural rather than visual:
 *
 *  - **`GET` mints no code.** It writes a pending row here and renders a form.
 *    There is no code-minting call anywhere in the GET path.
 *  - **`POST` mints a code only against a ticket**, and a ticket exists only
 *    because a `GET` rendered the screen that displayed it.
 *  - **The ticket is single-use**, claimed by the same atomic conditional
 *    `UPDATE` idiom `InviteLinkHandler::redeem()` uses: exactly one of N
 *    concurrent redemptions sees one affected row and the losers see zero, so
 *    a stolen ticket cannot be replayed even in a race.
 *  - **The ticket is bound to a user id.** The controller compares
 *    {@see PendingAuthorization::$userId} against the session user and refuses a
 *    mismatch, so a ticket phished out of one user's screen is inert in
 *    another's session.
 *
 * That last two properties also make the ticket the consent form's CSRF token,
 * which is why the POST needs no separate one: it is unguessable, single-use,
 * user-bound, and readable only from a same-origin document.
 *
 * ## Hashed at rest
 *
 * Only `SHA-256(ticket)` is stored, following {@see \Phlix\Hub\Hub\ClientRelayTokenService}.
 * A database disclosure yields no usable ticket.
 *
 * @package Phlix\Hub\OAuth
 * @since   S92 (shared OAuth 2.0 Authorization Server)
 */
final class ConsentTicketService
{
    /**
     * How long a rendered consent screen stays actionable (10 minutes).
     *
     * Long enough for a human to read the scope list and decide; short enough
     * that an abandoned tab is not a standing authorization waiting to be
     * submitted by a later visitor to the same machine.
     */
    public const int DEFAULT_TTL_SECONDS = 600;

    private int $ttlSeconds;

    /**
     * @param Connection $db         Workerman MySQL connection.
     * @param int        $ttlSeconds Ticket lifetime; non-positive falls back to the default.
     */
    public function __construct(private readonly Connection $db, int $ttlSeconds = self::DEFAULT_TTL_SECONDS)
    {
        $this->ttlSeconds = $ttlSeconds > 0 ? $ttlSeconds : self::DEFAULT_TTL_SECONDS;
    }

    /**
     * Persist a validated authorization request and return the opaque ticket
     * that the consent form will carry.
     *
     * @param PendingAuthorization $pending The already-validated request.
     *
     * @return array{ticket: string, expires_at: int} Plaintext ticket (returned
     *         exactly once) and its absolute Unix expiry.
     */
    public function issue(PendingAuthorization $pending): array
    {
        $ticket    = Ids::token();
        $expiresAt = time() + $this->ttlSeconds;

        $this->db->query(
            'INSERT INTO oauth_consent_requests'
                . ' (id, ticket_hash, user_id, client_id, redirect_uri, scopes, state, code_challenge, expires_at)'
                . ' VALUES (:id, :ticket_hash, :user_id, :client_id, :redirect_uri, :scopes, :state,'
                . ' :code_challenge, FROM_UNIXTIME(:expires_at))',
            [
                'id'             => Ids::uuidV4(),
                'ticket_hash'    => hash('sha256', $ticket),
                'user_id'        => $pending->userId,
                'client_id'      => $pending->clientId,
                'redirect_uri'   => $pending->redirectUri,
                'scopes'         => OAuthScopes::toStorage($pending->scopes),
                'state'          => $pending->state,
                'code_challenge' => $pending->codeChallenge,
                'expires_at'     => $expiresAt,
            ],
        );

        return ['ticket' => $ticket, 'expires_at' => $expiresAt];
    }

    /**
     * Atomically claim a ticket and return what the user was shown.
     *
     * The conditional `UPDATE` is the claim: it succeeds for exactly one caller
     * and only while the row is unconsumed and unexpired. The subsequent
     * `SELECT` deliberately does NOT re-test `consumed_at IS NULL` — this caller
     * is the one that just set it, and re-testing would make every successful
     * claim read back nothing.
     *
     * @param string $ticket The plaintext ticket from the consent form.
     *
     * @return PendingAuthorization|null The authorization request, or null when
     *         the ticket is unknown, expired, or already used.
     */
    public function consume(string $ticket): ?PendingAuthorization
    {
        if ($ticket === '') {
            return null;
        }

        $hash = hash('sha256', $ticket);

        /** @var mixed $updateResult */
        $updateResult = $this->db->query(
            'UPDATE oauth_consent_requests SET consumed_at = NOW()'
                . ' WHERE ticket_hash = :ticket_hash AND consumed_at IS NULL AND expires_at > NOW()',
            ['ticket_hash' => $hash],
        );

        if ((is_numeric($updateResult) ? (int) $updateResult : 0) !== 1) {
            return null;
        }

        /** @var mixed $rows */
        $rows = $this->db->query(
            'SELECT user_id, client_id, redirect_uri, scopes, state, code_challenge'
                . ' FROM oauth_consent_requests WHERE ticket_hash = :ticket_hash LIMIT 1',
            ['ticket_hash' => $hash],
        );

        if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0])) {
            return null;
        }

        $row         = $rows[0];
        $userId      = $row['user_id'] ?? null;
        $clientId    = $row['client_id'] ?? null;
        $redirectUri = $row['redirect_uri'] ?? null;
        $scopes      = $row['scopes'] ?? null;
        $challenge   = $row['code_challenge'] ?? null;

        if (
            !is_string($userId) || !is_string($clientId) || !is_string($redirectUri)
            || !is_string($scopes) || !is_string($challenge)
        ) {
            return null;
        }

        $parsedScopes = OAuthScopes::parse($scopes);
        if ($parsedScopes === []) {
            // A stored grant that no longer resolves to any known scope is a
            // refusal, not an unrestricted one. Reaching here means the scope
            // vocabulary changed under a pending consent screen.
            return null;
        }

        /** @var mixed $state */
        $state = $row['state'] ?? null;

        return new PendingAuthorization(
            $userId,
            $clientId,
            $redirectUri,
            $parsedScopes,
            is_string($state) ? $state : null,
            $challenge,
        );
    }

    /**
     * Delete consent requests that expired more than a day ago, and every
     * consumed one.
     *
     * Note the operator is OR, not AND — with AND only rows that were BOTH
     * long-expired AND consumed would go, leaving the common
     * consumed-immediately rows to accumulate forever.
     *
     * @return int Rows deleted.
     */
    public function pruneExpired(): int
    {
        /** @var mixed $result */
        $result = $this->db->query(
            'DELETE FROM oauth_consent_requests'
                . ' WHERE expires_at < NOW() - INTERVAL 1 DAY OR consumed_at IS NOT NULL',
        );

        return is_numeric($result) ? (int) $result : 0;
    }
}
