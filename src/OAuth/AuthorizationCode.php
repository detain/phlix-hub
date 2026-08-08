<?php

/**
 * Phlix hub component: OAuth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\OAuth;

/**
 * A redeemed authorization code, with everything it was bound to at mint time
 * (S92).
 *
 * An instance only ever comes out of {@see AuthorizationCodeService::consume()},
 * which means: the code existed, had not expired, and had not been redeemed
 * before. Possession of one of these is therefore proof of a valid single use —
 * but NOT yet proof that the redeeming party is entitled to it. The token
 * endpoint still has to check all four bindings against what the caller
 * presented:
 *
 *  - {@see $clientId}      vs the authenticated `client_id`
 *  - {@see $redirectUri}   vs the presented `redirect_uri`
 *  - {@see $codeChallenge} vs `PKCE(code_verifier)`
 *  - {@see $scopes}        is what gets issued — never the caller's re-request
 *
 * The last one is easy to lose: if the token endpoint read `scope` from the
 * token request instead of from here, a client could redeem a code the user
 * consented to for `phlix:profile:read` and receive `mcp:playback:control`.
 * The scope on a token is decided at consent time and nowhere else.
 *
 * @package Phlix\Hub\OAuth
 * @since   S92 (shared OAuth 2.0 Authorization Server)
 */
final class AuthorizationCode
{
    /**
     * @param string       $id            Row UUID — the handle used to revoke every
     *                                    token descended from this code if it is
     *                                    later replayed.
     * @param string       $clientId      `client_id` the code was issued to.
     * @param string       $userId        Hub user who consented.
     * @param string       $redirectUri   Exact `redirect_uri` the code was issued against.
     * @param list<string> $scopes        Scopes the user consented to.
     * @param string       $codeChallenge S256 challenge the redeemer must prove.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $clientId,
        public readonly string $userId,
        public readonly string $redirectUri,
        public readonly array $scopes,
        public readonly string $codeChallenge,
    ) {
    }
}
