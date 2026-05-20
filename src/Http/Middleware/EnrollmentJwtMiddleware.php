<?php

declare(strict_types=1);

namespace Phlix\Hub\Http\Middleware;

use Phlix\Hub\Hub\EnrollmentJwtService;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;

/**
 * Validates Ed25519 enrollment JWTs on server-facing routes.
 *
 * Extracts the `server_id` from the validated enrollment JWT and
 * populates `$request->serverId`. Returns 401 when the token is
 * missing, malformed, or expired.
 *
 * @package Phlix\Hub\Http\Middleware
 * @since 0.3.0
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

        $kid = $this->extractKid($token);
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
     * Extract the `kid` from a JWT header without validating the token.
     *
     * @return string|null Key ID or null when header is malformed.
     */
    private function extractKid(string $token): ?string
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        try {
            $decoded = base64_decode(strtr($parts[0], '-_', '+/'), true);
            if ($decoded === false) {
                return null;
            }
            /** @var array<string, mixed> $header */
            $header = json_decode($decoded, true, 2, JSON_THROW_ON_ERROR);
            /** @var string|null */
            $kid = $header['kid'] ?? null;
            return is_string($kid) ? $kid : null;
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * Build a 401 JSON response.
     */
    private function unauthorized(string $code): Response
    {
        return (new Response())->status(401)->json([
            'error' => 'Unauthorized',
            'code' => $code,
        ]);
    }
}
