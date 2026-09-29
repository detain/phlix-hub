<?php

/**
 * Phlix hub component: Middleware.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Http\Middleware;

use Phlix\Hub\Hub\EnrollmentJwtService;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Hub\Jwt\JwtHeader;

/**
 * Validates Ed25519 enrollment JWTs on server-facing routes.
 *
 * Extracts the `server_id` from the validated enrollment JWT and
 * populates `$request->serverId`. Returns 401 when the token is
 * missing, malformed, or expired.
 *
 * @package Phlix\Hub\Http\Middleware
 */
final class EnrollmentJwtMiddleware
{
    /**
     * @param EnrollmentJwtService $jwtService JWT validation service.
     */
    public function __construct(
        private readonly EnrollmentJwtService $jwtService,
    ) {
    }

    /**
     * Run the middleware. Returns null to continue routing, or a
     * {@see Response} to short-circuit with 401.
     */
    public function __invoke(Request $request): ?Response
    {
        $token = $request->bearerToken;
        if ($token === null || $token === '') {
            return $this->invalidToken();
        }

        $kid = JwtHeader::kid($token);
        if ($kid === null) {
            return $this->invalidToken();
        }

        $payload = $this->jwtService->validateEnrollmentJwt($token, $kid);
        if ($payload === null) {
            // Truthful failure label: only a token that actually aged out is
            // reported as expired. Unknown kid, forgery, and malformed tokens
            // are `auth.invalid_token` so operators (and clients, if they ever
            // branch on it) are not sent down a renew path for a bad signature.
            return $this->jwtService->classifyEnrollmentJwt($token, $kid) === 'expired'
                ? $this->expired()
                : $this->invalidToken();
        }

        /** @var string|null */
        $serverId = $payload['server_id'] ?? null;
        $request->serverId = is_string($serverId) ? $serverId : null;

        return null;
    }

    /**
     * 401 for a token that is well-formed, correctly signed, and simply past
     * its `exp`.
     *
     * W3 emit-wave: the SCREAMING `ENROLLMENT_TOKEN_EXPIRED` literal moves
     * from the `code` channel to the `error` TEXT field (byte-identical, for
     * clients that string-match it today); `code` carries the registered
     * dotted twin `auth.enrollment_expired` (@phlix/contracts).
     */
    private function expired(): Response
    {
        return (new Response())->error(401, 'auth.enrollment_expired', 'ENROLLMENT_TOKEN_EXPIRED');
    }

    /**
     * 401 for everything that is not a genuine expiry: missing/malformed
     * token, unknown kid, or failed signature.
     */
    private function invalidToken(): Response
    {
        return (new Response())->error(401, 'auth.invalid_token', 'ENROLLMENT_TOKEN_INVALID');
    }
}
