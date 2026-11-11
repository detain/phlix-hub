<?php

/**
 * Phlix hub component: Middleware.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Http\Middleware;

use Phlix\Hub\Auth\JwtHandler;
use Phlix\Hub\Auth\TokenSessionRepository;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Hub\CookiePolicy;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Shared\Auth\JwtClaims;

use function count;
use function time;

/**
 * Hub-side auth middleware — bearer first, session cookie second.
 *
 * Reads the access JWT from the `Authorization: Bearer …` header; when none
 * is presented it falls back to the `phlix_hub_token` cookie minted by
 * {@see \Phlix\Hub\Http\Controllers\AuthController}'s CP-011 dual-emit
 * (COOKIE-DESIGN.md §4.2, owner ruling CD-8a — the hub twin of the server's
 * RequestAuthenticator precedence). The deleted-surface note this text
 * replaced claimed the cookie read was unreachable dead code; CP-011 makes
 * that history false on purpose: the hub's same-origin /app SPA moves to
 * HttpOnly cookie custody and this is the read seam it rides.
 *
 * The fallback is deliberately NOT gated by the `auth.cookie.enabled`
 * master switch — presented artifacts are VALIDATED, never re-adjudicated by
 * the toggle that governs issuance (the same law as the server read path:
 * flipping the switch off stops NEW cookies; a still-unexpired JWT in a jar
 * stays as valid — or as invalid — as the signature says). Bearer precedence
 * is absolute: a request presenting BOTH carries the bearer's identity, so
 * every pre-cookie client's wire behavior is byte-identical.
 *
 * CSRF posture of the cookie carrier (CP-011 + CP-012, the shipped pair):
 * SameSite=Lax rides the cookie itself (cross-site POSTs never attach it),
 * and every cookie-LINEAGE request this seam authenticates passes the
 * request-level {@see CsrfOriginGuard} — the hub twin of the server's
 * ratified validateCsrfOrigin, riding the same-origin law on
 * {@see CookiePolicy} (§2.5/§4.2). Bearer lineage is NEVER gated — not by
 * omission but by law: browsers do not auto-attach Authorization
 * cross-origin, so gating a bearer on Origin headers would brick the every
 * headerless native client in the estate (the guard's non-regression
 * centerpiece, pinned by name in CsrfOriginGuardMiddlewareTest). The hub's
 * CORS stays never-credentials and the guard refuses the same-site-malicious
 * residue Lax cannot see: the cookie surface is same-origin /app only —
 * STRICTER than the server and consistent (§1.6).
 *
 * Hydrates {@see Request::$userId} when the token validates. When the
 * token is missing or invalid:
 *
 *  - JSON routes (`Accept: application/json` or path under `/api/`)
 *    short-circuit with a 401 JSON response;
 *  - HTML routes redirect to `/app/login` (the Vue SPA login) so the
 *    browser experience is "click → bounce to login".
 *
 * All per-request state travels on the {@see Request} itself — the
 * middleware holds no cross-request state, per the no-static-state rule
 * in `phlix-docs/docs/dev/coroutine-runtime.md` (Workerman 5 + Swoole
 * eventLoop runtime, step 0.2).
 *
 * @package Phlix\Hub\Http\Middleware
 */
final class AuthMiddleware
{
    /**
     * Short-TTL in-worker cache for user-existence probes.
     *
     * Avoids a full user-row read (`UserRepository::findById`) on every authenticated
     * request — the hot-path controllers only need $request->userId from the
     * already-validated JWT. Each entry stores the BOOLEAN probe RESULT
     * (`exists`) alongside the unix timestamp it was taken (`at`); entries
     * older than USER_EXISTS_CACHE_TTL seconds are treated as stale and
     * re-probed. Crucially the cache distinguishes existence: a user that
     * probes as NON-existent is cached as `false` and keeps being rejected
     * within the window — a deleted/revoked user can never be served a stale
     * `true` (the auth.user_not_found gate is honoured for negatives too).
     *
     * @var array<string, array{exists: bool, at: int}>
     *      userId → last existence-probe result + the unix timestamp it was taken
     */
    private static array $userExistsCache = [];

    /**
     * Reset the user-existence cache between test runs.
     *
     * @internal Tests call this via setUp() to prevent cache pollution.
     */
    public static function resetCache(): void
    {
        self::$userExistsCache = [];
    }

    /**
     * TTL for entries in the user-existence cache (seconds).
     */
    private const int USER_EXISTS_CACHE_TTL = 5;

    /**
     * Hard cap on distinct cached user-ids per worker. A resident Workerman
     * worker sees an unbounded stream of ids over its lifetime, so the cache
     * is cleared wholesale once it would exceed this bound rather than growing
     * without limit (no unbounded static state). The short TTL means a clear
     * costs at most one extra lean probe per active user.
     */
    private const int USER_EXISTS_CACHE_MAX = 10000;

    /**
     * @param JwtHandler     $jwt   JWT validator.
     * @param UserRepository $users Repository used to load the user record.
     * @param TokenSessionRepository|null $tokenSessions CP-029 / D-1a family
     *        store consulted for liveness of a `sid`-bearing credential. Null
     *        keeps a family-carrying token REFUSED (fail-closed — the store
     *        existed when the token was minted; see the arm), and never
     *        touches a claim-less (pre-055) request, whose behavior is
     *        byte-ident either way. Wired by the HttpServicesProvider factory
     *        as the same container singleton the token path writes through
     *        (instance identity pinned by AuthTokenSessionWiringGuardTest).
     */
    public function __construct(
        private readonly JwtHandler $jwt,
        private readonly UserRepository $users,
        private readonly ?TokenSessionRepository $tokenSessions = null,
    ) {
    }

    /**
     * Run the middleware. Returns null to continue routing, or a
     * {@see Response} to short-circuit.
     */
    public function __invoke(Request $request): ?Response
    {
        $token = $this->extractToken($request);
        if ($token === null) {
            return $this->challenge($request, 'auth.required');
        }

        $lineage = $this->jwt->validateAccessTokenWithSession($token);
        if ($lineage === null) {
            return $this->challenge($request, 'auth.invalid_token');
        }
        $claims = $lineage['claims'];

        // CP-029 / D-1a: a credential that NAMES a family must find it alive.
        // The store being gone while a family token is in the wild is exactly
        // the operator-removed-the-rug case — refuse it (fail closed) with the
        // same invalid_token challenge any dead credential gets; no new
        // envelope, no oracle. A claim-less (pre-055) token never consults
        // the store: its path is the historical one, byte-ident.
        $sessionId = $lineage['session_id'];
        if (
            $sessionId !== null
            && ($this->tokenSessions === null || !$this->tokenSessions->isSessionActive($sessionId))
        ) {
            return $this->challenge($request, 'auth.invalid_token');
        }

        $userId = $claims->sub;

        // Lightweight existence check with short TTL — avoids the full
        // user-row read (`UserRepository::findById`) on every authenticated request.
        // The hot-path controllers only need $request->userId (from the
        // already-validated JWT claims); controllers that need the full user
        // row call AuthManager::getCurrentUser() directly.
        if (!$this->userExists($userId)) {
            return $this->challenge($request, 'auth.user_not_found');
        }

        // CP-012: the CSRF Origin guard, reached ONLY on a fully-authenticated
        // request whose token came from the session cookie (bearer precedence
        // in extractToken is absolute, so a bearer-presenting request never
        // falls through here — gating a valid bearer on Origin would be the
        // estate-wide Critical regression the guard exists to avoid). The
        // gate runs BEFORE hydration so a refused request leaves no identity
        // on the object; the auth-failure envelopes above answer first, so
        // the 403-vs-401 order is never a carrier oracle (server parity).
        if (!self::presentsBearer($request)) {
            $rejected = CsrfOriginGuard::enforce($request);
            if ($rejected !== null) {
                return $rejected;
            }
        }

        $request->userId = $userId;
        $request->claims = $claims;
        // Hydrated only past every gate above (the CP-012 "refused requests
        // leave no identity" law extended to the family id): by here the
        // credential is verified, the user exists, the CSRF lineage passed,
        // and any named family is live.
        $request->tokenSessionId = $sessionId;

        return null;
    }

    /**
     * Whether the request presented a bearer token (the ONLY question that
     * decides CSRF lineage on this seam: bearer-present → authenticated by
     * it and immune; bearer-absent → the cookie fallback carries any
     * identity and the Origin guard applies). Shared with
     * {@see self::extractToken()} so the precedence test and the lineage
     * test cannot drift.
     */
    private static function presentsBearer(Request $request): bool
    {
        return $request->bearerToken !== null && $request->bearerToken !== '';
    }

    /**
     * Pull the access token: `Authorization: Bearer …` FIRST, then the
     * `phlix_hub_token` session cookie (CP-011 read-path fallback — see the
     * class docblock for the precedence, ungating and CSRF-posture laws).
     * A cookie that fails validation falls through to the existing
     * `auth.invalid_token` challenge exactly like a bad bearer: one gate,
     * one envelope, no carrier oracle.
     */
    private function extractToken(Request $request): ?string
    {
        if (self::presentsBearer($request)) {
            return $request->bearerToken;
        }
        return $request->getCookie(CookiePolicy::SESSION_COOKIE);
    }

    /**
     * Decide whether to send a JSON 401 or an HTML 302 redirect to /app/login.
     *
     * The bare `/app/login` target (no `?redirect=`) is honest for what is
     * left of the HTML family after review hub Minor 54: the twelve
     * identity-free SSR pointers were de-gated, so they never reach this
     * challenge — an unauthenticated visitor gets the pointer's own SPA
     * 302 and the SPA router guard re-bounces with `?redirect=<full path>`.
     * What still 302s from HERE is a route whose RESULT would differ with a
     * session (e.g. `/admin/requests`' auth+admin chain); there the bare
     * login page is the correct destination because the target page is not
     * a constant — it depends on who logs in.
     */
    private function challenge(Request $request, string $code): Response
    {
        if (self::isJsonRequest($request)) {
            return (new Response())->status(401)->json([
                'error' => 'Unauthorized',
                'code'  => $code,
            ]);
        }
        return (new Response())
            ->status(302)
            ->header('Location', '/app/login');
    }

    /**
     * Probe user existence using a lean `SELECT 1 … LIMIT 1` query
     * with a short in-worker TTL cache.
     *
     * Controllers that need the full user row call
     * {@see \Phlix\Hub\Auth\AuthManager::getCurrentUser()} directly.
     */
    private function userExists(string $userId): bool
    {
        $now = time();

        $entry = self::$userExistsCache[$userId] ?? null;
        if ($entry !== null && ($now - $entry['at']) <= self::USER_EXISTS_CACHE_TTL) {
            // Cache hit within TTL: honour the CACHED BOOLEAN RESULT, never an
            // unconditional `true`. A user probed as non-existent stays
            // rejected for the rest of the window, so a deleted/revoked user
            // cannot bypass the auth.user_not_found gate.
            return $entry['exists'];
        }

        // Miss or stale: run the lean existence probe once and cache the
        // boolean result with its timestamp. The common (existing-user) case
        // still skips this probe on the hot path via the branch above, while a
        // negative result is honoured for the TTL window just like a positive
        // one. Revocation latency is bounded by USER_EXISTS_CACHE_TTL.
        $exists = $this->users->userExists($userId);

        // Bound the per-worker cache so an unbounded churn of distinct ids in
        // a resident worker can't grow it without limit.
        if (
            !isset(self::$userExistsCache[$userId])
            && count(self::$userExistsCache) >= self::USER_EXISTS_CACHE_MAX
        ) {
            self::$userExistsCache = [];
        }

        self::$userExistsCache[$userId] = ['exists' => $exists, 'at' => $now];
        return $exists;
    }

    /**
     * True when the request is an API call (path under `/api/` OR an
     * `Accept` header that prefers JSON).
     */
    public static function isJsonRequest(Request $request): bool
    {
        if (str_starts_with($request->path, '/api/')) {
            return true;
        }
        $accept = $request->getHeader('Accept') ?? '';
        return str_contains($accept, 'application/json');
    }

    /**
     * Static helper exposed for the readiness of the bare access-token
     * scenario in tests and the SignupLoginFlow integration suite.
     */
    public static function claimsForUser(JwtHandler $jwt, string $token): ?JwtClaims
    {
        return $jwt->validateAccessToken($token);
    }
}
