<?php

/**
 * Phlix hub component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Http\Controllers;

use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Hub\OAuth\OAuthError;
use Phlix\Hub\OAuth\OAuthGrant;

use function implode;
use function is_string;

/**
 * `GET /oauth/userinfo` — the FIRST protected resource an OAuth access token
 * issued by this hub can reach (S286).
 *
 * ## Why this endpoint, and why only this one
 *
 * S92 left the hub with a complete Authorization Server whose tokens authorised
 * nothing. Fixing that means deciding what an OAuth token is allowed to reach,
 * and the honest first answer is the narrowest one that makes the vocabulary
 * mean something:
 *
 *  - {@see \Phlix\Hub\OAuth\OAuthScopes::PROFILE_READ} is the only scope in the
 *    vocabulary with **no MCP counterpart**. It exists precisely because a
 *    third-party client starts out knowing nothing about the user, and the first
 *    question every account-linking flow asks is "whose account is this?".
 *    Serving it here consumes a scope that would otherwise be unreachable, and
 *    treads on nothing the deferred S62 PAT migration owns.
 *  - Everything else in the vocabulary is an `mcp:*` scope, and each one is
 *    already served — by `POST /mcp`, authenticated by an `McpToken`. Pointing
 *    an OAuth token at those surfaces is the S62 migration: it means giving
 *    `McpToolRegistry` a second credential type and re-deciding the ownership
 *    gate for a caller that is a third party rather than the user. That is a
 *    deliberate step of its own and is **not** done here. Wiring OAuth into
 *    every existing route because it is easier would be exactly the "not done
 *    casually" the brief warns against — an authorisation decision on every
 *    request of every guarded route, taken in passing.
 *
 * So: one route, one scope, read-only, and it returns strictly less than the
 * user's own `/api/v1/me` does. A client holding `phlix:profile:read` learns the
 * hub user id, the display name and the scopes it was actually granted — and
 * nothing about the user's servers, libraries, email or admin status.
 *
 * ## The response is enumerated, not spread
 *
 * `UserRepository::findById()` does `SELECT *`, so returning the row (or the row
 * minus a deny-list) would publish every column a future migration adds to
 * `users` — to a third party — with nothing to notice. The three fields below
 * are written out one at a time for that reason; a new column reaches this
 * response only when somebody adds a line here.
 *
 * @package Phlix\Hub\Http\Controllers
 * @since   S286 (OAuth resource server, admin surface and prune timer)
 * @link    https://www.rfc-editor.org/rfc/rfc6750
 */
final class OAuthUserInfoController
{
    /**
     * @param UserRepository $users Source of the display name.
     */
    public function __construct(private readonly UserRepository $users)
    {
    }

    /**
     * Answer the linked account's identity.
     *
     * Reached only behind {@see \Phlix\Hub\Http\Middleware\OAuthResourceMiddleware},
     * which has already established that the bearer token is a live ACCESS token,
     * that its user exists, and that it carries `phlix:profile:read`.
     *
     * ⚠ The grant is re-read from {@see Request::$oauthGrant} and a null one is
     * REFUSED rather than falling back to {@see Request::$userId}. The fallback
     * is what would make this controller serve a session JWT if the route were
     * ever re-gated with `AuthMiddleware` by mistake — an unscoped credential on
     * a scoped surface. Refusing costs nothing on the wired route (the
     * middleware always populates it) and closes that door.
     *
     * @param Request              $request Incoming request.
     * @param array<string,string> $params  Route parameters (unused).
     */
    public function userInfo(Request $request, array $params = []): Response
    {
        unset($params);

        $grant = $request->oauthGrant;
        if (!$grant instanceof OAuthGrant) {
            return (new Response())->status(401)->json([
                'error'             => OAuthError::INVALID_TOKEN,
                'error_description' => 'An OAuth 2.0 Bearer access token is required',
            ]);
        }

        $user = $this->users->findById($grant->userId);
        if ($user === null) {
            // The middleware probed existence a moment ago, so this is a
            // deletion that raced the request. 401 rather than 404: the token no
            // longer identifies anybody.
            return (new Response())->status(401)->json([
                'error'             => OAuthError::INVALID_TOKEN,
                'error_description' => 'The access token is invalid, expired or revoked',
            ]);
        }

        /** @var mixed $displayName */
        $displayName = $user['display_name'] ?? null;
        /** @var mixed $username */
        $username = $user['username'] ?? null;

        $name = is_string($displayName) && $displayName !== ''
            ? $displayName
            : (is_string($username) ? $username : '');

        return (new Response())->json([
            // `sub` rather than `user_id`: this is the identity claim name every
            // OAuth/OIDC client library already looks for.
            'sub'   => $grant->userId,
            'name'  => $name,
            'scope' => implode(' ', $grant->scopes),
        ]);
    }
}
