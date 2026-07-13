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
use Phlix\Hub\Common\WebPortal\PageRenderer;
use Phlix\Hub\Http\Middleware\AuthMiddleware;
use Phlix\Hub\Http\Middleware\CsrfMiddleware;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Shared\Events\Auth\UserLoggedOut;
use Throwable;

/**
 * HTTP handlers for the auth endpoints — both the form-driven SSR routes
 * (`POST /signup`, `POST /login`, `POST /logout`) and the JSON API
 * counterparts under `/api/v1/auth/*`.
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
     * @param AuthManager    $auth     Orchestrator.
     * @param PageRenderer   $renderer Smarty wrapper for SSR templates.
     * @param CsrfMiddleware $csrf     Re-stamps the CSRF cookie + hidden
     *                                 field when an SSR form is re-rendered
     *                                 after a validation/auth error so the
     *                                 retry submission carries a valid token.
     */
    public function __construct(
        private readonly AuthManager $auth,
        private readonly PageRenderer $renderer,
        private readonly CsrfMiddleware $csrf,
    ) {
    }

    /**
     * Router entry point. Dispatches to the matching action based on
     * `$request->method` + `$request->path`.
     */
    public function __invoke(Request $request): Response
    {
        return match ([$request->method, $request->path]) {
            ['POST', '/signup']             => $this->signupForm($request),
            ['POST', '/login']              => $this->loginForm($request),
            ['POST', '/logout']             => $this->logout($request),
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
     * Process `POST /signup` from the HTML form. On success: sets cookies
     * and redirects to `/my-servers`. On failure: re-renders the signup
     * template with the error.
     */
    public function signupForm(Request $request): Response
    {
        try {
            $result = $this->auth->register(
                self::stringField($request, 'username'),
                self::stringField($request, 'email'),
                self::stringField($request, 'password'),
            );
            return $this->withSessionCookies(
                (new Response())->redirect('/my-servers'),
                self::asString($result['access_token']),
                self::asString($result['refresh_token']),
            );
        } catch (InvalidArgumentException $e) {
            return $this->csrf->issue($request, (new Response())->html(
                $this->renderer->render('auth/signup.tpl', [
                    'error'    => $e->getMessage(),
                    'username' => self::stringField($request, 'username'),
                    'email'    => self::stringField($request, 'email'),
                ]),
                400,
            ));
        } catch (Throwable $e) {
            return $this->csrf->issue($request, (new Response())->html(
                $this->renderer->render('auth/signup.tpl', [
                    'error' => 'Unable to create account: ' . $e->getMessage(),
                ]),
                500,
            ));
        }
    }

    /**
     * Process `POST /login` from the HTML form. Sets cookies + redirect on
     * success; re-renders with error on failure.
     */
    public function loginForm(Request $request): Response
    {
        try {
            $identifier = self::stringField($request, 'username');
            if ($identifier === '') {
                $identifier = self::stringField($request, 'email');
            }
            $result = $this->auth->login(
                $identifier,
                self::stringField($request, 'password'),
                $request->remoteIp ?: 'unknown',
                self::deviceId($request),
            );
            return $this->withSessionCookies(
                (new Response())->redirect('/my-servers'),
                self::asString($result['access_token']),
                self::asString($result['refresh_token']),
            );
        } catch (RateLimitException $e) {
            // Must precede the generic catches below, which would otherwise
            // swallow a limiter trip into a 401/500 before the central
            // Application 429 mapping ever runs. WS surfaces do NOT reach here.
            return self::rateLimited($e);
        } catch (InvalidArgumentException $e) {
            return $this->csrf->issue($request, (new Response())->html(
                $this->renderer->render('auth/login.tpl', [
                    'error'    => $e->getMessage(),
                    'username' => self::stringField($request, 'username'),
                ]),
                401,
            ));
        } catch (Throwable $e) {
            return $this->csrf->issue($request, (new Response())->html(
                $this->renderer->render('auth/login.tpl', [
                    'error' => 'Login failed: ' . $e->getMessage(),
                ]),
                500,
            ));
        }
    }

    /**
     * Process `POST /logout` from the HTML form. Always clears cookies and
     * redirects to `/`.
     */
    public function logout(Request $request): Response
    {
        $userId = $request->userId ?? '';
        if ($userId !== '') {
            $this->auth->logout($userId, $request->remoteIp ?: 'unknown', UserLoggedOut::REASON_EXPLICIT);
        }
        return $this->withClearedCookies((new Response())->redirect('/'));
    }

    /**
     * JSON signup endpoint. Body: `{username, email, password}`.
     */
    public function signupJson(Request $request): Response
    {
        try {
            $result = $this->auth->register(
                self::stringField($request, 'username'),
                self::stringField($request, 'email'),
                self::stringField($request, 'password'),
            );
            return (new Response())->json([
                'access_token'  => $result['access_token'],
                'refresh_token' => $result['refresh_token'],
                'token_type'    => $result['token_type'],
                'expires_in'    => $result['expires_in'],
                'user'          => $result['user'],
                'claims'        => $result['claims'],
            ], 201);
        } catch (InvalidArgumentException $e) {
            return (new Response())->status(400)->json([
                'error' => 'Bad Request',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * JSON login endpoint. Body: `{username|email, password}`.
     */
    public function loginJson(Request $request): Response
    {
        try {
            $identifier = self::stringField($request, 'username');
            if ($identifier === '') {
                $identifier = self::stringField($request, 'email');
            }
            $result = $this->auth->login(
                $identifier,
                self::stringField($request, 'password'),
                $request->remoteIp ?: 'unknown',
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
            return (new Response())->status(401)->json([
                'error' => 'Unauthorized',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build the shared 429 rate-limit envelope (status + `Retry-After` header
     * + `code: 'rate_limited'`), matching the central mapping in
     * {@see \Phlix\Hub\Application}. Kept local so login trips never fall
     * through to the generic 401/500 paths above.
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
     */
    public function logoutJson(Request $request): Response
    {
        $userId = $request->userId ?? '';
        if ($userId !== '') {
            $this->auth->logout($userId, $request->remoteIp ?: 'unknown', UserLoggedOut::REASON_EXPLICIT);
        }
        return $this->withClearedCookies((new Response())->status(204));
    }

    /**
     * JSON refresh endpoint. Body: `{refresh_token}` OR cookie.
     */
    public function refreshJson(Request $request): Response
    {
        $token = self::stringField($request, 'refresh_token');
        if ($token === '') {
            // Fall back to cookie.
            $cookie = $request->getHeader('Cookie') ?? '';
            foreach (explode(';', $cookie) as $part) {
                $kv = explode('=', trim($part), 2);
                if (count($kv) === 2 && $kv[0] === AuthMiddleware::COOKIE_REFRESH) {
                    $token = urldecode(trim($kv[1]));
                    break;
                }
            }
        }
        if ($token === '') {
            return (new Response())->status(400)->json([
                'error' => 'Bad Request',
                'message' => 'refresh_token is required',
            ]);
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
            return (new Response())->status(401)->json([
                'error' => 'Unauthorized',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Decorate a response with the two session cookies (access + refresh).
     */
    private function withSessionCookies(Response $response, string $accessToken, string $refreshToken): Response
    {
        $accessTtl = $this->auth->jwt()->getAccessTtl();
        $refreshTtl = $this->auth->jwt()->getRefreshTtl();
        // Session cookies are HttpOnly + Secure (the latter via the
        // Response::cookie() default) + SameSite=Strict: the auth flow only
        // performs same-site redirects (`/login` -> `/my-servers`,
        // `/logout` -> `/`), so Strict is safe and gives the strongest
        // cross-site protection.
        return $response
            ->cookie(AuthMiddleware::COOKIE_ACCESS, $accessToken, $accessTtl, '/', true, null, 'Strict')
            ->cookie(AuthMiddleware::COOKIE_REFRESH, $refreshToken, $refreshTtl, '/', true, null, 'Strict');
    }

    /**
     * Decorate a response with empty/expired session cookies (logout).
     */
    private function withClearedCookies(Response $response): Response
    {
        return $response
            ->cookie(AuthMiddleware::COOKIE_ACCESS, '', 0, '/', true, null, 'Strict')
            ->cookie(AuthMiddleware::COOKIE_REFRESH, '', 0, '/', true, null, 'Strict');
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

    /**
     * Coerce mixed → string with empty fallback.
     */
    private static function asString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }
        return '';
    }
}
