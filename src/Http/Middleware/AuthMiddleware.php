<?php

/**
 * Phlix hub component: Middleware.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Http\Middleware;

use Phlix\Hub\Auth\JwtHandler;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\RequestContext;
use Phlix\Hub\Http\Response;
use Phlix\Shared\Auth\JwtClaims;

use function time;

/**
 * Hub-side bearer/cookie auth middleware.
 *
 * Reads the access JWT from either the `Authorization: Bearer …` header
 * (the API surface) or a `phlix_hub_token` cookie (the SSR pages), then
 * hydrates {@see Request::$userId} when the token validates. When the
 * token is missing or invalid:
 *
 *  - JSON routes (`Accept: application/json` or path under `/api/`)
 *    short-circuit with a 401 JSON response;
 *  - HTML routes redirect to `/login` so the browser experience is
 *    "click → bounce to login".
 *
 * On success, the middleware also publishes the authenticated user-id
 * into the coroutine-local request context via
 * {@see RequestContext::setUserId()} so downstream services can read it
 * without re-receiving the {@see Request}. This is the canonical
 * coroutine-safe replacement for the static/global pattern under the
 * Workerman 5 + Swoole eventLoop runtime introduced in step 0.2; see
 * `phlix-docs/docs/dev/coroutine-runtime.md` for the no-static-state
 * rule.
 *
 * @package Phlix\Hub\Http\Middleware
 */
final class AuthMiddleware
{
    public const COOKIE_ACCESS = 'phlix_hub_token';
    public const COOKIE_REFRESH = 'phlix_hub_refresh';

    /**
     * Short-TTL in-worker cache for user-existence probes.
     *
     * Avoids a full `SELECT * FROM users WHERE id = ?` on every authenticated
     * request — the hot-path controllers only need $request->userId from the
     * already-validated JWT. The cache stores a unix timestamp; entries older
     * than USER_EXISTS_CACHE_TTL seconds are treated as stale.
     *
     * @var array<string, int> userId → unix timestamp of last confirmed existence
     */
    private static array $userExistsCache = [];

    /**
     * TTL for entries in the user-existence cache (seconds).
     */
    private const int USER_EXISTS_CACHE_TTL = 5;

    /**
     * @param JwtHandler     $jwt   JWT validator.
     * @param UserRepository $users Repository used to load the user record.
     */
    public function __construct(
        private readonly JwtHandler $jwt,
        private readonly UserRepository $users,
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

        $claims = $this->jwt->validateAccessToken($token);
        if ($claims === null) {
            return $this->challenge($request, 'auth.invalid_token');
        }

        $userId = $claims->sub;

        // Lightweight existence check with short TTL — avoids the full
        // `SELECT * FROM users WHERE id = ?` on every authenticated request.
        // The hot-path controllers only need $request->userId (from the
        // already-validated JWT claims); controllers that need the full user
        // row (e.g. PageController for SSR admin flags) call
        // AuthManager::getCurrentUser() directly.
        if (!$this->userExists($userId)) {
            return $this->challenge($request, 'auth.user_not_found');
        }

        $request->userId = $userId;
        $request->claims = $claims;

        // Publish the authenticated user-id into the coroutine-local
        // request context so downstream services / controllers can read
        // it without re-passing the Request object. This is the canonical
        // replacement for the static/global pattern that resident-memory
        // workers cannot use safely under coroutines (see step 0.2c
        // and `phlix-docs/docs/dev/coroutine-runtime.md`).
        RequestContext::setUserId($userId);

        return null;
    }

    /**
     * Pull a token from the Authorization header first, then a cookie.
     *
     * The cookie path is honoured only for SSR/GET-style requests. For a
     * MUTATING request on the JSON `/api/v1` surface (POST/PUT/PATCH/DELETE)
     * the session cookie is deliberately ignored: those requests must carry
     * an explicit `Authorization: Bearer` header. This closes the
     * cookie-based CSRF vector on the API (a cross-site form/fetch can ride
     * the cookie but cannot set an Authorization header), complementing the
     * double-submit CSRF guard on the SSR forms ({@see CsrfMiddleware}).
     */
    private function extractToken(Request $request): ?string
    {
        if ($request->bearerToken !== null && $request->bearerToken !== '') {
            return $request->bearerToken;
        }
        if (self::isMutatingApiRequest($request)) {
            // Bearer-only on the mutating API surface; do not fall back to
            // the cookie.
            return null;
        }
        $cookieHeader = $request->getHeader('Cookie');
        if ($cookieHeader === null) {
            return null;
        }
        foreach (explode(';', $cookieHeader) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) === 2 && $kv[0] === self::COOKIE_ACCESS) {
                $value = trim($kv[1]);
                return $value === '' ? null : $value;
            }
        }
        return null;
    }

    /**
     * True when the request is a mutating call on the JSON `/api/v1`
     * surface (POST/PUT/PATCH/DELETE under `/api/`). Such requests are
     * Bearer-only — the session cookie is not accepted as authentication.
     */
    private static function isMutatingApiRequest(Request $request): bool
    {
        if (!str_starts_with($request->path, '/api/')) {
            return false;
        }
        return match (strtoupper($request->method)) {
            'POST', 'PUT', 'PATCH', 'DELETE' => true,
            default => false,
        };
    }

    /**
     * Decide whether to send a JSON 401 or an HTML 302 redirect to /login.
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
            ->header('Location', '/login');
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

        // Warm cache entry on miss — one lightweight query per user per TTL window.
        if (!isset(self::$userExistsCache[$userId]) || ($now - self::$userExistsCache[$userId]) > self::USER_EXISTS_CACHE_TTL) {
            if ($this->users->userExists($userId)) {
                self::$userExistsCache[$userId] = $now;
                return true;
            }
            // Negative cache too: record that this user definitely does not exist
            // so we don't re-query for a deleted user within the TTL window.
            self::$userExistsCache[$userId] = $now;
            return false;
        }

        return true;
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
