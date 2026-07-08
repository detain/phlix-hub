<?php

/**
 * Phlix hub component: Middleware.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Http\Middleware;

use Phlix\Hub\Common\Support\Ids;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Random\RandomException;

/**
 * Double-submit-cookie CSRF protection for the SSR (form-driven) surface.
 *
 * The hub renders a handful of state-changing HTML forms — login, signup
 * and the per-page logout form in the base layout. Because those POSTs are
 * authenticated by the {@see AuthMiddleware} session cookie, they are
 * vulnerable to cross-site request forgery unless a non-cookie secret also
 * accompanies the request. This middleware implements the standard
 * double-submit-cookie defence:
 *
 *  - {@see self::issue()} stamps a random token into a readable
 *    `phlix_hub_csrf` cookie when an SSR page is rendered, and substitutes
 *    the same token into the `{@see self::PLACEHOLDER}` sentinel emitted by
 *    the form templates (via `partials/csrf-field.tpl`). The cookie token is
 *    reused across renders so multiple open tabs share one valid token.
 *  - {@see self::__invoke()} runs as route middleware on the SSR mutating
 *    routes and rejects the request with a 403 unless the submitted `_csrf`
 *    form field matches the cookie token (constant-time compare).
 *
 * The JSON `/api/v1` surface is protected separately: it requires the
 * `Authorization: Bearer` header for mutating methods and no longer honours
 * the session cookie there (see {@see AuthMiddleware::extractToken()}), so a
 * cross-site request cannot ride the cookie and CSRF tokens are unnecessary
 * for the API.
 *
 * @package Phlix\Hub\Http\Middleware
 */
final class CsrfMiddleware
{
    /** Name of the readable double-submit cookie. */
    public const COOKIE_CSRF = 'phlix_hub_csrf';

    /** Name of the hidden form field carrying the submitted token. */
    public const FIELD = '_csrf';

    /**
     * Sentinel emitted by the form templates and replaced with the live
     * token by {@see self::issue()}. Kept deliberately distinctive so the
     * substitution cannot collide with legitimate page content.
     */
    public const PLACEHOLDER = '__PHLIX_CSRF_TOKEN__';

    /** Cookie lifetime in seconds (session-ish; refreshed on every render). */
    private const COOKIE_TTL = 86400;

    /**
     * Validate a state-changing SSR POST. Returns null to continue routing,
     * or a 403 {@see Response} to short-circuit when the double-submit check
     * fails.
     */
    public function __invoke(Request $request): ?Response
    {
        $cookieToken = self::cookieToken($request);
        $submitted = self::submittedToken($request);

        if ($cookieToken === null || $cookieToken === '' || $submitted === '') {
            return $this->reject();
        }
        if (!hash_equals($cookieToken, $submitted)) {
            return $this->reject();
        }
        return null;
    }

    /**
     * Ensure the response carries a CSRF cookie and that any
     * {@see self::PLACEHOLDER} sentinel in its body is replaced with the
     * live token. Reuses the request's existing CSRF cookie token when
     * present so concurrent tabs stay valid; otherwise mints a fresh
     * CSPRNG token.
     *
     * @throws RandomException If the CSPRNG is unavailable when minting.
     */
    public function issue(Request $request, Response $response): Response
    {
        $token = self::cookieToken($request);
        if ($token === null || $token === '') {
            $token = Ids::token(32);
        }

        // Readable (non-HttpOnly) by design: the double-submit cookie is not
        // a session secret, and keeping it readable lets a SPA echo it back
        // in a header if needed. Secure + SameSite=Strict still apply.
        $response->cookie(
            self::COOKIE_CSRF,
            $token,
            self::COOKIE_TTL,
            '/',
            false,
            null,
            'Strict',
        );

        if (str_contains($response->body, self::PLACEHOLDER)) {
            $response->body = str_replace(self::PLACEHOLDER, $token, $response->body);
        }

        return $response;
    }

    /**
     * Read the CSRF token from the request's `phlix_hub_csrf` cookie.
     */
    public static function cookieToken(Request $request): ?string
    {
        $cookieHeader = $request->getHeader('Cookie');
        if ($cookieHeader === null) {
            return null;
        }
        foreach (explode(';', $cookieHeader) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) === 2 && $kv[0] === self::COOKIE_CSRF) {
                $value = trim($kv[1]);
                return $value === '' ? null : urldecode($value);
            }
        }
        return null;
    }

    /**
     * Read the submitted CSRF token from the request body (`_csrf` field).
     * Returns "" when missing or not a string.
     */
    public static function submittedToken(Request $request): string
    {
        /**
         * @var mixed $value
         * @psalm-suppress MixedAssignment
         */
        $value = $request->body[self::FIELD] ?? null;
        return is_string($value) ? $value : '';
    }

    /**
     * 403 response for a failed CSRF check. HTML because the SSR forms are
     * browser-driven.
     */
    private function reject(): Response
    {
        return (new Response())
            ->status(403)
            ->html(
                '<h1>Forbidden</h1>'
                . '<p>Your session token was missing or invalid. Please reload the page and try again.</p>'
                . '<p><a href="/login">Back to login</a></p>',
                403,
            );
    }
}
