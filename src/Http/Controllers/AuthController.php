<?php

/**
 * Phlix hub component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Http\Controllers;

use InvalidArgumentException;
use Phlix\Hub\Auth\AuthManager;
use Phlix\Hub\Auth\RateLimitException;
use Phlix\Hub\Auth\SignupsDisabledException;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Shared\Events\Auth\UserLoggedOut;

/**
 * HTTP handlers for the JSON auth endpoints under `/api/v1/auth/*`
 * (register/signup, login, logout, refresh). The legacy form-driven SSR
 * routes (`POST /signup|/login|/logout`) have been retired with the Smarty
 * UI — the Vue SPA posts to these JSON endpoints.
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
    /**
     * @param AuthManager $auth Orchestrator.
     */
    public function __construct(
        private readonly AuthManager $auth,
    ) {
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
            default => (new Response())->status(404)->json(['error' => 'Not Found']),
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
            return (new Response())->json([
                'access_token'  => $result['access_token'],
                'refresh_token' => $result['refresh_token'],
                'token_type'    => $result['token_type'],
                'expires_in'    => $result['expires_in'],
                'user'          => $result['user'],
                'claims'        => $result['claims'],
            ], 201);
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
            return (new Response())->json([
                'access_token'  => $result['access_token'],
                'refresh_token' => $result['refresh_token'],
                'token_type'    => $result['token_type'],
                'expires_in'    => $result['expires_in'],
                'user'          => $result['user'],
                'claims'        => $result['claims'],
            ], 200);
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
     * Build the shared 429 rate-limit envelope (status + `Retry-After` header
     * + `code: 'rate_limited'`), matching the central mapping in
     * {@see \Phlix\Hub\Application}. Kept local so auth trips never fall
     * through to the generic 401/400/500 paths above.
     */
    private static function rateLimited(RateLimitException $e): Response
    {
        return (new Response())
            ->status(429)
            ->header('Retry-After', (string) $e->retryAfterSeconds())
            ->json(['error' => 'Too Many Requests', 'code' => 'rate_limited']);
    }

    /**
     * JSON logout endpoint. Always 204 No Content.
     *
     * The auth routes run WITHOUT {@see AuthMiddleware} (a logged-out client
     * must be able to call it), so `$request->userId` is normally empty here —
     * the refresh JWT in the body is what identifies the lineage being cut.
     * When the caller presents one, {@see AuthManager::logout()} validates it,
     * revokes its `jti`, and uses the signed `sub` for the audit trail; this
     * finally makes the spec's "invalidates the refresh token" promise true.
     * There are no cookies to clear: nothing in the hub ever set them (the
     * legacy cookie surface was deleted as unreachable code).
     */
    public function logoutJson(Request $request): Response
    {
        $userId = $request->userId ?? '';
        $refreshToken = self::stringField($request, 'refresh_token');
        if ($userId !== '' || $refreshToken !== '') {
            $this->auth->logout(
                $userId,
                $request->remoteIp ?: 'unknown',
                UserLoggedOut::REASON_EXPLICIT,
                $refreshToken,
            );
        }
        return (new Response())->status(204);
    }

    /**
     * JSON refresh endpoint. Body: `{refresh_token}` (bearer-token surface
     * only — the cookie fallback was deleted along with the dead cookie
     * surface; no code path ever set it).
     *
     * Failure mapping: absent token → 400 `auth.missing_credentials`;
     * a token that fails any of the three refresh gates (cryptographic,
     * revoked, user-gone) → 401 `auth.invalid_token`, deliberately not
     * distinguishing which gate fired.
     */
    public function refreshJson(Request $request): Response
    {
        $token = self::stringField($request, 'refresh_token');
        if ($token === '') {
            return self::errorFrame(400, 'auth.missing_credentials', 'refresh_token is required');
        }
        try {
            $result = $this->auth->refresh($token);
            return (new Response())->json([
                'access_token'  => $result['access_token'],
                'refresh_token' => $result['refresh_token'],
                'token_type'    => $result['token_type'],
                'expires_in'    => $result['expires_in'],
                'user'          => $result['user'],
                'claims'        => $result['claims'],
            ], 200);
        } catch (InvalidArgumentException $e) {
            return self::errorFrame(401, 'auth.invalid_token', $e->getMessage());
        }
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
