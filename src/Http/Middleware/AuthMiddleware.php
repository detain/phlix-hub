<?php

declare(strict_types=1);

namespace Phlix\Hub\Http\Middleware;

use Phlix\Hub\Auth\JwtHandler;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Shared\Auth\JwtClaims;

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
 * @package Phlix\Hub\Http\Middleware
 */
final class AuthMiddleware
{
    public const COOKIE_ACCESS = 'phlix_hub_token';
    public const COOKIE_REFRESH = 'phlix_hub_refresh';

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

        $user = $this->users->findById($claims->sub);
        if ($user === null) {
            return $this->challenge($request, 'auth.user_not_found');
        }

        $request->userId = $claims->sub;
        // Stash the claims + user in pathParams (the Request struct doesn't
        // expose typed bags yet; controllers can pull these via $request->pathParams).
        // Note: keep this minimal and unobtrusive so we don't need to change
        // the Request shape.
        unset($user['password_hash']);
        $request->user = $user;
        $request->claims = $claims;

        return null;
    }

    /**
     * Pull a token from the Authorization header first, then a cookie.
     */
    private function extractToken(Request $request): ?string
    {
        if ($request->bearerToken !== null && $request->bearerToken !== '') {
            return $request->bearerToken;
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
