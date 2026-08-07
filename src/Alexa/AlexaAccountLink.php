<?php

/**
 * Phlix hub component: Alexa.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Alexa;

use Phlix\Hub\Auth\JwtHandler;
use Phlix\Hub\Auth\UserRepository;

/**
 * Turns Amazon's linked-account access token into a hub user id (S91).
 *
 * ## What "account linking" means here today
 *
 * Amazon holds an opaque bearer token per Alexa user, obtained from whatever
 * OAuth authorization server the skill declares, and replays it on every request
 * as `session.user.accessToken` / `context.System.user.accessToken`. Today the
 * hub issues no OAuth tokens of its own, so the token a linked account carries is
 * an ordinary hub HS256 ACCESS token — the same credential the SPA holds — and
 * this class validates it with the same {@see JwtHandler} the rest of the hub
 * uses.
 *
 * **This class is the seam.** A later step replaces the ISSUER (a real OAuth
 * authorization code flow, so a user links without pasting a JWT anywhere) and
 * nothing else: the skill still asks this one method "whose account is this?",
 * and every caller downstream keeps depending only on the returned hub user id.
 * Scattering `validateAccessToken()` calls through the intent handlers instead
 * would make that swap a change in N places, and the Nth would be missed.
 *
 * ## Why the user is re-checked against the database
 *
 * A signed token proves Amazon replayed something the hub once minted; it does
 * not prove the account still exists. Hub access tokens are long-lived by design
 * and Amazon caches them for the life of the link, so a deleted user's token
 * stays cryptographically valid long after the row is gone. Without the
 * {@see UserRepository::userExists()} probe the skill would keep answering
 * library questions as a user the hub no longer has — and, worse, the id would be
 * handed to {@see AlexaMediaGateway}, where the proxy's ownership gate would
 * compare it against server rows that may since have been re-owned. Returning
 * null instead produces the account-linking prompt, which is the truthful answer.
 *
 * ## Null is the only failure mode
 *
 * Absent token, malformed token, wrong signature, expired token, a REFRESH token
 * presented in place of an access token, or an unknown user all return null. The
 * caller cannot distinguish them, deliberately: the skill's response is the same
 * "please link your account" in every case, and a spoken error that named which
 * check failed would be a credential oracle read out loud in someone's kitchen.
 *
 * @package Phlix\Hub\Alexa
 * @since   S91 (Alexa skill controller + Q&A intent tier)
 */
final class AlexaAccountLink
{
    /**
     * @param JwtHandler     $jwt   Validates the presented access token (HS256,
     *        issuer/audience/expiry checked, and the token TYPE must be `access`).
     * @param UserRepository $users Existence probe for the id inside the token.
     */
    public function __construct(
        private readonly JwtHandler $jwt,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * Resolve the hub user id behind an Alexa linked-account token.
     *
     * @param string|null $accessToken `session.user.accessToken`, or null when the
     *        account is not linked.
     *
     * @return string|null The hub user id, or null when the account is not linked
     *         or the token no longer resolves to a live user.
     */
    public function resolve(?string $accessToken): ?string
    {
        if ($accessToken === null || $accessToken === '') {
            return null;
        }

        $claims = $this->jwt->validateAccessToken($accessToken);
        if ($claims === null) {
            return null;
        }

        $userId = $claims->sub;
        if ($userId === '') {
            return null;
        }

        return $this->users->userExists($userId) ? $userId : null;
    }
}
