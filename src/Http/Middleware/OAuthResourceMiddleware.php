<?php

/**
 * Phlix hub component: Middleware.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Http\Middleware;

use InvalidArgumentException;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\RequestContext;
use Phlix\Hub\Http\Response;
use Phlix\Hub\OAuth\OAuthError;
use Phlix\Hub\OAuth\OAuthScopes;
use Phlix\Hub\OAuth\OAuthTokenService;

use function implode;

/**
 * The RESOURCE-SERVER half of the hub's OAuth 2.0 deployment (S286).
 *
 * S92 shipped a complete Authorization Server and nothing that consumed its
 * output: an access token could be minted, hashed, rotated and revoked, and it
 * authorised exactly nothing, because no middleware anywhere read one. This is
 * that middleware. It turns a `phlix-oat-…` bearer credential into
 * {@see Request::$userId} + {@see Request::$oauthGrant} for the routes it
 * guards, and refuses everything else.
 *
 * ## It is NOT AuthMiddleware, and must not be confused with it
 *
 * {@see AuthMiddleware} authenticates a HUB USER holding an HS256 session JWT
 * (or the session cookie). This authenticates a THIRD-PARTY CLIENT acting on a
 * user's behalf, holding a token that user consented to at
 * `GET /oauth/authorize`. The two are deliberately separate objects on separate
 * routes rather than one middleware with two arms:
 *
 *  - a session JWT carries no scopes, so folding OAuth into `AuthMiddleware`
 *    would mean deciding what an unscoped credential is allowed to reach, and
 *    the only safe answer to that ("everything the user can do") is exactly the
 *    fail-open this class exists to avoid;
 *  - the cookie fallback in `AuthMiddleware` is meaningless for a third-party
 *    client and would be a CSRF vector on a scoped surface;
 *  - and the refusals differ: an unauthenticated browser on an `/oauth/…` page
 *    path is 302'd to `/app/login`, whereas a client with a bad token must get a
 *    401 with a `WWW-Authenticate: Bearer` challenge it can act on (RFC 6750).
 *
 * ⚠ This is **not** the S62 PAT migration. `POST /mcp` still authenticates with
 * an `McpToken`; nothing about that path changes here. What S92 made possible —
 * and what {@see OAuthScopes} already guarantees by re-exporting every
 * `McpScopes` member verbatim — is that an MCP client can later present an
 * OAuth-issued token carrying the SAME scope strings. That migration is a
 * separate, deliberate step.
 *
 * ## The scope check fails CLOSED, at construction
 *
 * `$requiredScopes` is normalised through {@see OAuthScopes::fromArray()}, which
 * drops anything this build does not recognise, and an EMPTY result throws out
 * of the constructor. That is the whole point:
 *
 *  - an empty allow-list has already fail-OPENed twice in this estate — a rating
 *    cap built from `[]` emitted no `WHERE` clause and authorised everything,
 *    and S261 shipped an MCP token minted without a `scopes` field that received
 *    the WRITE scope. A middleware that treated `[]` as "no scope required"
 *    would be the same defect a third time, on a surface reachable by any
 *    third-party client that holds any token;
 *  - a TYPO in a required scope (`phlix:profile:reed`) is dropped by
 *    `fromArray()` and therefore also produces `[]`. Without the throw that
 *    route would end up gated on nothing at all, which is strictly worse than
 *    the misconfiguration it came from.
 *
 * Throwing at construction rather than refusing at request time is deliberate:
 * the middleware is built while `Application::registerOAuthRoutes()` composes
 * the route table, so a mis-scoped route stops the hub from BOOTING instead of
 * serving a surface whose gate is decorative. There is no runtime branch that
 * can be reached with an empty requirement, because such an object cannot exist.
 *
 * ## Order of the checks, and why
 *
 *  1. **Is a bearer token present?** No → 401 `invalid_request`, with a BARE
 *     `WWW-Authenticate: Bearer realm="…"` challenge that names no error code
 *     (RFC 6750 §3 — nothing has been tried and rejected yet). Distinct from
 *     step 2 on purpose; see {@see bareChallenge()}.
 *  2. **Does it validate as an ACCESS token?** {@see OAuthTokenService::validateAccess()}
 *     filters on `kind = 'access'`, `revoked_at IS NULL` and `expires_at > NOW()`,
 *     so a refresh token, a revoked token and an expired token all fail here.
 *     No → 401 `invalid_token`.
 *  3. **Does the bound user still exist?** A token outlives the account it was
 *     issued for — the same reasoning as
 *     {@see \Phlix\Hub\Alexa\AlexaAccountLink}'s existence probe. A deleted
 *     user's token is answered 401, not 403: the token no longer identifies
 *     anybody, so "insufficient scope" would be a lie.
 *  4. **Does the grant carry every required scope?** No → **403**
 *     `insufficient_scope`.
 *
 * The 401/403 split is load-bearing for anybody reading a log or a test: a 403
 * proves the token authenticated and the scope check is what refused it. A test
 * that only ever sees refusals cannot tell "the scope gate worked" apart from
 * "the token was rejected for some other reason", which is why the suite for
 * this class always asserts a 200 control beside each refusal.
 *
 * @package Phlix\Hub\Http\Middleware
 * @since   S286 (OAuth resource server, admin surface and prune timer)
 * @link    https://www.rfc-editor.org/rfc/rfc6750#section-3
 */
final class OAuthResourceMiddleware
{
    /**
     * Protection realm named in every `WWW-Authenticate` challenge (RFC 6750
     * §3). One value for the whole hub: a per-route realm would let a client
     * cache one credential per route, which is not how these tokens work.
     */
    public const string REALM = 'phlix-hub';

    /**
     * Scopes a request must carry, normalised and guaranteed non-empty.
     *
     * @var non-empty-list<string>
     */
    private readonly array $requiredScopes;

    /**
     * @param OAuthTokenService $tokens         Access-token validator (real SQL, not a cache).
     * @param UserRepository    $users          Existence probe for the token's subject.
     * @param list<string>      $requiredScopes Every scope a caller must hold. MUST
     *                                          contain at least one scope this build
     *                                          knows, or construction throws.
     *
     * @throws InvalidArgumentException When no recognised scope is required — see
     *         the class docblock. An unrecognised scope string is dropped by
     *         {@see OAuthScopes::fromArray()} and therefore also throws.
     */
    public function __construct(
        private readonly OAuthTokenService $tokens,
        private readonly UserRepository $users,
        array $requiredScopes,
    ) {
        $known = OAuthScopes::fromArray($requiredScopes);
        if ($known === []) {
            throw new InvalidArgumentException(
                'OAuthResourceMiddleware requires at least one scope this build recognises; '
                . 'received [' . implode(', ', $requiredScopes) . ']. An empty requirement would '
                . 'let every OAuth token reach this route, which is the fail-open shape S261 shipped.',
            );
        }

        $this->requiredScopes = $known;
    }

    /**
     * The scopes this instance enforces, normalised.
     *
     * Exposed so the route suites can pin what a given surface demands without
     * reaching into a private property, and so the `WWW-Authenticate` challenge
     * can name them.
     *
     * @return non-empty-list<string>
     */
    public function requiredScopes(): array
    {
        return $this->requiredScopes;
    }

    /**
     * Run the middleware. Returns null to continue routing, or a
     * {@see Response} to short-circuit.
     *
     * @param Request $request Incoming request.
     */
    public function __invoke(Request $request): ?Response
    {
        $token = $request->bearerToken;
        if ($token === null || $token === '') {
            // ⚠ NOT `invalid_token`, and the challenge carries no `error=` at
            // all. RFC 6750 §3: "If the request lacks any authentication
            // information … the resource server SHOULD NOT include an error
            // code". Nothing has been tried and rejected yet.
            //
            // 🔴 It is also what makes this branch OBSERVABLE. Mutation testing
            // found that deleting this guard changed no outcome: an empty token
            // falls through to `OAuthTokenService::validateAccess()`, which has
            // its own `$token === ''` guard and answers null, so the request was
            // still refused 401 — by a different line, one layer down. The
            // refusal was real and the attribution was wrong, which is the S92
            // M5/M8 shape exactly. Giving "no credential" its own code and its
            // own challenge means this line, and only this line, produces that
            // outcome.
            return $this->bareChallenge();
        }

        $grant = $this->tokens->validateAccess($token);
        if ($grant === null) {
            // Unknown, expired, revoked, or a refresh token presented as a
            // bearer credential. One code for all four, deliberately: telling a
            // holder WHICH is true tells them what to try next.
            return $this->challenge(
                401,
                OAuthError::INVALID_TOKEN,
                'The access token is invalid, expired or revoked',
            );
        }

        if (!$this->users->userExists($grant->userId)) {
            return $this->challenge(
                401,
                OAuthError::INVALID_TOKEN,
                'The access token is invalid, expired or revoked',
            );
        }

        foreach ($this->requiredScopes as $scope) {
            if (!$grant->hasScope($scope)) {
                return $this->challenge(
                    403,
                    OAuthError::INSUFFICIENT_SCOPE,
                    'The access token does not carry the scope this resource requires',
                );
            }
        }

        $request->userId     = $grant->userId;
        $request->oauthGrant = $grant;

        // Same coroutine-local publication AuthMiddleware performs, so a service
        // downstream of an OAuth-authenticated request reads the same user id it
        // would for a session-authenticated one. Never a static/global — this is
        // `support\Context`-backed and dies with the coroutine.
        RequestContext::setUserId($grant->userId);

        return null;
    }

    /**
     * The refusal for a request that carried NO credential at all.
     *
     * A bare `WWW-Authenticate: Bearer realm="…"` with no `error=` (RFC 6750
     * §3), and `invalid_request` in the body — "you did not send one" rather
     * than "the one you sent is bad". Kept separate from {@see challenge()}
     * rather than expressed as a flag on it, so the two cannot converge by
     * accident: the day they produce the same bytes is the day this branch stops
     * being testable.
     */
    private function bareChallenge(): Response
    {
        $description = 'An OAuth 2.0 Bearer access token is required';

        return (new Response())
            ->status(401)
            ->header('WWW-Authenticate', 'Bearer realm="' . self::REALM . '"')
            ->json([
                'error'             => OAuthError::INVALID_REQUEST,
                'error_description' => $description,
            ]);
    }

    /**
     * Build an RFC 6750 §3 refusal for a credential that WAS presented: the JSON
     * error body the OAuth surface uses everywhere else, plus the
     * `WWW-Authenticate` challenge a compliant client reads to decide whether to
     * re-authorise or to ask for more scope.
     *
     * @param int    $status      401 or 403.
     * @param string $error       {@see OAuthError} code.
     * @param string $description Non-diagnostic, client-facing detail.
     */
    private function challenge(int $status, string $error, string $description): Response
    {
        $challenge = 'Bearer realm="' . self::REALM . '", error="' . $error . '"'
            . ', error_description="' . $description . '"';
        if ($error === OAuthError::INSUFFICIENT_SCOPE) {
            $challenge .= ', scope="' . implode(' ', $this->requiredScopes) . '"';
        }

        return (new Response())
            ->status($status)
            ->header('WWW-Authenticate', $challenge)
            ->json([
                'error'             => $error,
                'error_description' => $description,
            ]);
    }
}
