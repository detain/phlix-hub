<?php

/**
 * Phlix hub component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Http\Controllers;

use InvalidArgumentException;
use Phlix\Hub\Auth\AuthManager;
use Phlix\Hub\Auth\RateLimitException;
use Phlix\Hub\Auth\SignupsDisabledException;
use Phlix\Hub\Hub\CookiePolicy;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Shared\Events\Auth\UserLoggedOut;

/**
 * HTTP handlers for the JSON auth endpoints under `/api/v1/auth/*`
 * (register/signup, login, logout, refresh). The legacy form-driven SSR
 * routes (`POST /signup|/login|/logout`) have been retired with the Smarty
 * UI — the Vue SPA posts to these JSON endpoints.
 *
 * CP-011 (COOKIE-DESIGN.md §4.2, owner ruling CD-8a hub parity): the hub's
 * deleted cookie half is RE-INTRODUCED on a policy seam. signup/login/refresh
 * DUAL-EMIT for browser-class requests on a §2.4-eligible scheme — the
 * body-token envelope stays byte-identical and the `phlix_hub_token` /
 * `phlix_hub_refresh` HttpOnly pair rides as an ADDITIVE Set-Cookie; logout
 * clears both names; device-class requests (X-Phlix-Device-Type marker,
 * foreign/webview Origin, or no browser signal at all) keep today's
 * bearer-only wire byte-for-byte and NEVER receive a Set-Cookie. Every
 * issuance decision consults the ONE {@see CookiePolicy} seam — there is no
 * second minter in this repo (guarded by CookiePolicyWiringGuardTest).
 *
 * Every failure frame carries the machine-readable `code` field on the
 * contract-registered vocabulary (`auth.missing_credentials`,
 * `auth.invalid_credentials`, `auth.invalid_token`, `auth.signups_disabled`,
 * `validation_failed`, `rate_limited`) via {@see Response::error()}, while
 * the pre-existing human text is kept in both `error` and the legacy
 * `message` key so no current reader loses its string.
 *
 * Decision: this class is invokable as a dispatcher-style controller —
 * it inspects {@see Request::$method} and {@see Request::$path} so the
 * existing {@see \Phlix\Hub\Http\Router} signature stays minimal. Future
 * phases that need finer-grained route → method dispatch can split this
 * into per-action invokables.
 *
 * @package Phlix\Hub\Http\Controllers
 */
final class AuthController
{
    /** The one auth-cookie policy seam (see class docblock). */
    private CookiePolicy $cookiePolicy;

    /**
     * @param AuthManager       $auth           Orchestrator.
     * @param CookiePolicy|null $cookiePolicy   Auth-cookie policy; null builds
     *        a default one whose settings arrive through the canonical
     *        HubSettingsResolvers live-read idiom (unit tests and the
     *        degraded no-pool boot then behave exactly like every pre-cookie
     *        release: enabled-default, env-only insecure). Wired in
     *        HttpServicesProvider; the same singleton serves attach, classify
     *        and clear so the three can never disagree.
     */
    public function __construct(
        private readonly AuthManager $auth,
        ?CookiePolicy $cookiePolicy = null,
    ) {
        $this->cookiePolicy = $cookiePolicy ?? new CookiePolicy();
    }

    /**
     * Router entry point. Dispatches to the matching action based on
     * `$request->method` + `$request->path`.
     */
    public function __invoke(Request $request): Response
    {
        return match ([$request->method, $request->path]) {
            // `/register` is the canonical JSON signup path used by the shared
            // @phlix/ui SPA (and phlix-server); `/signup` is kept as an alias.
            ['POST', '/api/v1/auth/register'] => $this->signupJson($request),
            ['POST', '/api/v1/auth/signup'] => $this->signupJson($request),
            ['POST', '/api/v1/auth/login']  => $this->loginJson($request),
            ['POST', '/api/v1/auth/logout'] => $this->logoutJson($request),
            ['POST', '/api/v1/auth/refresh']=> $this->refreshJson($request),
            // R-HUB-W2-C1: the second prose-only 404 the contracts v0.5.5
            // mint registered `not_found` for — now speaks the unified
            // member-facing envelope (machine word in `code`, one sentence
            // co-mirrored in error/message), companion of Router::notFound().
            default => self::errorFrame(404, 'not_found', 'The requested resource was not found'),
        };
    }

    /**
     * JSON signup endpoint. Body: `{username, email, password}`.
     *
     * Failure mapping: absent fields → 400 `auth.missing_credentials`;
     * registrations closed → 403 `auth.signups_disabled`; over the signup
     * budget → 429 `rate_limited`; everything the domain rejected (length,
     * format, already-taken) → 400 `validation_failed`.
     */
    public function signupJson(Request $request): Response
    {
        $username = self::stringField($request, 'username');
        $email = self::stringField($request, 'email');
        $password = self::stringField($request, 'password');
        if ($username === '' || $email === '' || $password === '') {
            return self::errorFrame(400, 'auth.missing_credentials', 'username, email and password are required');
        }

        try {
            $result = $this->auth->register(
                $username,
                $email,
                $password,
                // Trusted-proxy-aware real client IP — the signup limiter
                // buckets on it exactly like the login limiter does (never
                // the raw HAProxy loopback peer).
                $request->getTrustedClientIp() ?: 'unknown',
            );
            $response = (new Response())->json([
                'access_token'  => $result['access_token'],
                'refresh_token' => $result['refresh_token'],
                'token_type'    => $result['token_type'],
                'expires_in'    => $result['expires_in'],
                'user'          => $result['user'],
                'claims'        => $result['claims'],
            ], 201);
            if ($this->classifyRequest($request) === CookiePolicy::MODE_COOKIE) {
                $this->attachAuthCookies($response, $result);
            }

            return $response;
        } catch (SignupsDisabledException $e) {
            return self::errorFrame(403, 'auth.signups_disabled', $e->getMessage());
        } catch (RateLimitException $e) {
            // Before InvalidArgumentException: a budget trip is 429, not 400.
            return self::rateLimited($e);
        } catch (InvalidArgumentException $e) {
            return self::errorFrame(400, 'validation_failed', $e->getMessage());
        }
    }

    /**
     * JSON login endpoint. Body: `{username|email, password}`.
     *
     * Failure mapping: absent fields → 400 `auth.missing_credentials`;
     * over the login budget → 429 `rate_limited`; credentials that simply do
     * not match (unknown account, wrong password — indistinguishable by
     * design) → 401 `auth.invalid_credentials`.
     */
    public function loginJson(Request $request): Response
    {
        $identifier = self::stringField($request, 'username');
        if ($identifier === '') {
            $identifier = self::stringField($request, 'email');
        }
        $password = self::stringField($request, 'password');
        if ($identifier === '' || $password === '') {
            return self::errorFrame(400, 'auth.missing_credentials', 'username (or email) and password are required');
        }

        try {
            $result = $this->auth->login(
                $identifier,
                $password,
                // Trusted-proxy-aware real client IP — NOT the raw peer, which is
                // the HAProxy loopback address for every login and would collapse
                // the limiter into one global bucket (mirrors SV-4.15).
                $request->getTrustedClientIp() ?: 'unknown',
                self::deviceId($request),
            );
            $response = (new Response())->json([
                'access_token'  => $result['access_token'],
                'refresh_token' => $result['refresh_token'],
                'token_type'    => $result['token_type'],
                'expires_in'    => $result['expires_in'],
                'user'          => $result['user'],
                'claims'        => $result['claims'],
            ], 200);
            if ($this->classifyRequest($request) === CookiePolicy::MODE_COOKIE) {
                $this->attachAuthCookies($response, $result);
            }

            return $response;
        } catch (RateLimitException $e) {
            // Precede the InvalidArgumentException 401 so a limiter trip maps
            // to 429 + Retry-After rather than a misleading 401.
            return self::rateLimited($e);
        } catch (InvalidArgumentException $e) {
            return self::errorFrame(401, 'auth.invalid_credentials', $e->getMessage());
        }
    }

    /**
     * The house error frame: `{error, code}` from {@see Response::error()}
     * plus the legacy top-level `message` key carrying the same human text,
     * so readers written against the pre-code envelope keep working while
     * the machine `code` becomes available to new ones.
     */
    private static function errorFrame(int $status, string $code, string $message): Response
    {
        return (new Response())->error($status, $code, $message, ['message' => $message]);
    }

    /**
     * Build the shared 429 rate-limit envelope. The status, the `Retry-After`
     * header and the machine `code: 'rate_limited'` match the central mapping
     * in {@see \Phlix\Hub\Application::rateLimitResponse()}; on top of that
     * pair this local frame also carries the human `message` sentence, which
     * is this class's own house law ({@see errorFrame()} sentences every
     * code, and the test-suite header names it) — an auth throttle in front
     * of a person must never be a bare machine frame. Wire-ADDITIVE against
     * the central `{error, code}` shape: legacy readers are untouched, no
     * new code. Kept local so auth trips never fall through to the generic
     * 401/400/500 paths above.
     */
    private static function rateLimited(RateLimitException $e): Response
    {
        $retryAfter = $e->retryAfterSeconds();

        return (new Response())
            ->status(429)
            ->header('Retry-After', (string) $retryAfter)
            ->json([
                'error' => 'Too Many Requests',
                'code' => 'rate_limited',
                'message' => RateLimitException::retryAfterMessage($retryAfter),
            ]);
    }

    /**
     * JSON logout endpoint. Always 204 No Content (body bytes: none — the
     * status is the entire contract and stays untouched by the cookie
     * program; the clear rides as additive Set-Cookie headers).
     *
     * The auth routes run WITHOUT {@see AuthMiddleware} (a logged-out client
     * must be able to call it), so `$request->userId` is normally empty here —
     * the refresh JWT in the body is what identifies the lineage being cut.
     * CP-011: when the body carries no token, the `phlix_hub_refresh` cookie
     * is the SECOND lineage source (a cookie-mode SPA stops sending the body
     * token; the holder of the JWT is the owner either way — the same
     * validation gates both). When the caller presents one,
     * {@see AuthManager::logout()} validates it, revokes its `jti`, and uses
     * the signed `sub` for the audit trail; this finally makes the spec's
     * "invalidates the refresh token" promise true for cookie clients too.
     * Both cookie names are cleared on every logout, NOT gated by the master
     * switch (§4.2 re-introduction law; {@see self::clearAuthCookies()}
     * carries the rationale).
     */
    public function logoutJson(Request $request): Response
    {
        $userId = $request->userId ?? '';
        $refreshToken = self::stringField($request, 'refresh_token');
        if ($refreshToken === '') {
            $refreshToken = $request->getCookie(CookiePolicy::REFRESH_COOKIE) ?? '';
        }
        if ($userId !== '' || $refreshToken !== '') {
            $this->auth->logout(
                $userId,
                $request->remoteIp ?: 'unknown',
                UserLoggedOut::REASON_EXPLICIT,
                $refreshToken,
            );
        }
        $response = (new Response())->status(204);

        return $this->clearAuthCookies($response);
    }

    /**
     * JSON refresh endpoint. Body: `{refresh_token}` takes PRECEDENCE; when
     * the body carries none, the `phlix_hub_refresh` cookie is consulted
     * (CP-011 dual-carrier law, the hub twin of the server's /auth/refresh —
     * body precedence keeps every legacy bearer client byte-identical).
     * On success the rotated pair dual-emits under the same classifyRequest
     * policy as login: cookie-class responses carry the fresh HttpOnly pair,
     * device-class responses carry NONE (the pre-CP-011 hub never attached
     * cookies at all; the ui/mobile refresh flows stay byte-ident).
     *
     * Failure mapping: absent token (body AND cookie) → 400
     * `auth.missing_credentials`; a token that fails any of the three refresh
     * gates (cryptographic, revoked, user-gone) → 401 `auth.invalid_token`,
     * deliberately not distinguishing which gate fired.
     */
    public function refreshJson(Request $request): Response
    {
        $token = self::stringField($request, 'refresh_token');
        if ($token === '') {
            $token = $request->getCookie(CookiePolicy::REFRESH_COOKIE) ?? '';
        }
        if ($token === '') {
            return self::errorFrame(400, 'auth.missing_credentials', 'refresh_token is required');
        }
        try {
            $result = $this->auth->refresh($token);
            $response = (new Response())->json([
                'access_token'  => $result['access_token'],
                'refresh_token' => $result['refresh_token'],
                'token_type'    => $result['token_type'],
                'expires_in'    => $result['expires_in'],
                'user'          => $result['user'],
                'claims'        => $result['claims'],
            ], 200);
            if ($this->classifyRequest($request) === CookiePolicy::MODE_COOKIE) {
                $this->attachAuthCookies($response, $result);
            }

            return $response;
        } catch (InvalidArgumentException $e) {
            return self::errorFrame(401, 'auth.invalid_token', $e->getMessage());
        }
    }

    /**
     * CP-011 (COOKIE-DESIGN §2.1/§2.4 + shared schema 2683579): the
     * per-request custody classification deciding whether Set-Cookie may ride
     * this response at all. MODE_COOKIE only when ALL of: the request is
     * browser-class (§2.1: no device marker, and either a same-site http(s)
     * Origin or `Sec-Fetch-Site: same-origin` — the hub has no form-era limb,
     * its SSR UI is gone), the `auth.cookie.enabled` master switch is not
     * false, and the §2.4 scheme law holds (https per the first
     * X-Forwarded-Proto hop, localhost Host, or the explicit insecure
     * override — which also drops the Secure attribute downstream, so there
     * is never a cookie flagged `Secure` that a plain-HTTP browser would
     * silently discard). Side-effect-free and per-request: resident workers
     * must never carry a custody decision from one client to the next.
     *
     * @see CookiePolicy for the primitive decisions (pure over header values).
     */
    private function classifyRequest(Request $request): string
    {
        $mode = $this->cookiePolicy->custodyMode(
            $request->getHeader('X-Phlix-Device-Type'),
            $request->getHeader('Origin'),
            $request->getHeader('Host'),
            $request->getHeader('Sec-Fetch-Site'),
        );

        if ($mode !== CookiePolicy::MODE_COOKIE) {
            return CookiePolicy::MODE_BEARER;
        }

        if (!$this->cookiePolicy->cookieModeEnabled()) {
            return CookiePolicy::MODE_BEARER;
        }

        $forwardedProto = $request->getHeader('X-Forwarded-Proto');
        $hostHeader = $request->getHeader('Host');
        if (!$this->cookiePolicy->schemeEligible($forwardedProto, $hostHeader)) {
            return CookiePolicy::MODE_BEARER;
        }

        return CookiePolicy::MODE_COOKIE;
    }

    /**
     * Queue the access + refresh auth cookies onto a response.
     *
     * Single source of truth for the hub auth-cookie attributes: HttpOnly so
     * XSS can't read them, SameSite=Lax so top-level navigations still carry
     * them, Secure per the policy (never a silent downgrade). The access
     * cookie expires alongside its JWT (`expires_in` from the auth payload —
     * the same {@see AuthManager} that minted it backs the fallback), the
     * refresh cookie's lifetime is `authManager->refreshTtl()`; the two
     * cannot drift from their tokens' `exp` when the TTL settings move.
     * Empty token values are skipped.
     *
     * The `auth.cookie.enabled` master switch is honoured AT THIS CHOKE
     * POINT as well as in classifyRequest (belt: any future caller that
     * reaches attach without the classification still cannot mint a cookie
     * while the switch is off — §7 rollback law, "never sets auth cookies at
     * all, on any path"). Classification (who may reach this method at all)
     * lives in {@see self::classifyRequest()}.
     *
     * @param array<string, mixed> $authResponse The AuthManager shape
     *        (access_token, refresh_token, expires_in, ...).
     */
    private function attachAuthCookies(Response $response, array $authResponse): void
    {
        if (!$this->cookiePolicy->cookieModeEnabled()) {
            return;
        }

        /** @var mixed $accessRaw — intentional: array<string,mixed> read, narrowed below. */
        $accessRaw = $authResponse['access_token'] ?? null;
        /** @var mixed $refreshRaw */
        $refreshRaw = $authResponse['refresh_token'] ?? null;
        /** @var mixed $expiresRaw */
        $expiresRaw = $authResponse['expires_in'] ?? null;

        $access = is_string($accessRaw) ? $accessRaw : '';
        $refresh = is_string($refreshRaw) ? $refreshRaw : '';
        $expiresIn = is_int($expiresRaw) ? $expiresRaw : $this->auth->accessTtl();

        $secure = $this->cookiePolicy->cookiesSecure();

        if ($access !== '') {
            $response->cookie(
                CookiePolicy::SESSION_COOKIE,
                $access,
                maxAge: $expiresIn,
                secure: $secure,
                httpOnly: true,
                sameSite: 'Lax',
            );
        }
        if ($refresh !== '') {
            $response->cookie(
                CookiePolicy::REFRESH_COOKIE,
                $refresh,
                maxAge: $this->auth->refreshTtl(),
                secure: $secure,
                httpOnly: true,
                sameSite: 'Lax',
            );
        }
    }

    /**
     * Expire both auth cookies on the response (Max-Age=0, empty value).
     *
     * Deliberately NOT gated by the master switch: clearing is REVOCATION
     * HYGIENE, not credential issuance — a jar must die on logout even when
     * the operator switched issuance off in between (the server's shipped
     * form-logout clear is the same unconditional shape). Attributes match
     * the issuance shape (HttpOnly, Path=/, SameSite=Lax, Secure per the
     * policy's current reading) so browsers treat the clear as the same
     * cookie-name/domain/path tuple it stored, instead of leaving a mismatched
     * sibling row alive.
     */
    private function clearAuthCookies(Response $response): Response
    {
        $secure = $this->cookiePolicy->cookiesSecure();

        return $response
            ->cookie(
                CookiePolicy::SESSION_COOKIE,
                '',
                maxAge: 0,
                secure: $secure,
                httpOnly: true,
                sameSite: 'Lax',
            )
            ->cookie(
                CookiePolicy::REFRESH_COOKIE,
                '',
                maxAge: 0,
                secure: $secure,
                httpOnly: true,
                sameSite: 'Lax',
            );
    }

    /**
     * Read a string field from `$request->body` (JSON) OR fall back to the
     * raw POST body (Workerman form-encoded). Returns "" when missing or
     * not-a-string.
     */
    private static function stringField(Request $request, string $key): string
    {
        /**
         * @var mixed $value
         * @psalm-suppress MixedAssignment
         */
        $value = $request->body[$key] ?? null;
        return is_string($value) ? $value : '';
    }

    /**
     * Resolve the opaque device/session identifier for a login. Prefers an
     * explicit `device_id` body field; falls back to the client IP so the
     * audit log still carries a stable per-client marker when the client
     * does not supply one. Distinct from the rate-limit key, which is always
     * the real client IP.
     */
    private static function deviceId(Request $request): string
    {
        $deviceId = self::stringField($request, 'device_id');
        if ($deviceId !== '') {
            return $deviceId;
        }
        return $request->remoteIp ?: 'unknown';
    }
}
