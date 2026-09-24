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
            return $this->unauthorized('ENROLLMENT_TOKEN_EXPIRED');
        }

        $kid = JwtHeader::kid($token);
        if ($kid === null) {
            return $this->unauthorized('ENROLLMENT_TOKEN_EXPIRED');
        }

        $payload = $this->jwtService->validateEnrollmentJwt($token, $kid);
        if ($payload === null) {
            return $this->unauthorized('ENROLLMENT_TOKEN_EXPIRED');
        }

        /** @var string|null */
        $serverId = $payload['server_id'] ?? null;
        $request->serverId = is_string($serverId) ? $serverId : null;

        return null;
    }

    /**
     * Build a 401 JSON response.
     *
     * W3 emit-wave: the SCREAMING `ENROLLMENT_TOKEN_EXPIRED` literal moves
     * from the `code` channel to the `error` TEXT field (byte-identical, for
     * clients that string-match it today); `code` carries the registered
     * dotted twin `auth.enrollment_expired` (@phlix/contracts).
     */
    private function unauthorized(string $code): Response
    {
        return (new Response())->error(401, 'auth.enrollment_expired', $code);
    }
}
