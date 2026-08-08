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
 * One authorization request that has been fully validated and is waiting for
 * the user's decision on the consent screen (S92).
 *
 * ## Why this exists as a stored row rather than a hidden form field
 *
 * The consent screen could have re-submitted `client_id`, `redirect_uri`,
 * `scope` and `code_challenge` as hidden inputs and re-validated them on the
 * POST. That shape has two problems and this one has neither:
 *
 *  1. **The POST could disagree with the GET.** If the parameters travel
 *     through the browser a second time, the thing the user read on the consent
 *     screen and the thing the code gets bound to are two different values that
 *     merely happen to agree in the honest case. Here the user's browser carries
 *     only an opaque ticket; every security-relevant parameter is read back out
 *     of the row the GET wrote, so what the user consented to IS what the code
 *     is bound to, structurally.
 *  2. **The consent POST would need a separate CSRF token.** The ticket already
 *     is one: it is single-use, unguessable, bound to `$userId`, and obtainable
 *     only by rendering the consent screen — which a cross-origin page cannot
 *     read. See {@see ConsentTicketService}.
 *
 * @package Phlix\Hub\OAuth
 * @since   S92 (shared OAuth 2.0 Authorization Server)
 */
final class PendingAuthorization
{
    /**
     * @param string       $userId        Hub user who was authenticated when the
     *                                    consent screen was rendered. The POST is
     *                                    refused unless the session user still
     *                                    matches this.
     * @param string       $clientId      Public `client_id` of the requesting client.
     * @param string       $redirectUri   The exact registered redirect URI that was matched.
     * @param list<string> $scopes        Scopes actually displayed to the user.
     * @param string|null  $state         Client's opaque `state`, echoed back verbatim.
     * @param string       $codeChallenge The S256 `code_challenge` to bind the code to.
     */
    public function __construct(
        public readonly string $userId,
        public readonly string $clientId,
        public readonly string $redirectUri,
        public readonly array $scopes,
        public readonly ?string $state,
        public readonly string $codeChallenge,
    ) {
    }
}
