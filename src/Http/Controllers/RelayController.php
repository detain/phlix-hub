<?php

declare(strict_types=1);

namespace Phlix\Hub\Http\Controllers;

use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Hub\Hub\EnrollmentJwtService;

/**
 * Handles the relay tunnel HTTP endpoint and WebSocket upgrade.
 *
 * The server connects to `POST /api/v1/servers/{id}/relay` to:
 *   - Perform HTTP/WebSocket upgrade to establish a persistent relay tunnel
 *   - The hub then multiplexes inbound client requests over this tunnel
 *
 * @package Phlix\Hub\Http\Controllers
 * @since 0.12.0
 */
final class RelayController
{
    /** @var EnrollmentJwtService */
    private EnrollmentJwtService $jwtService;

    /**
     * @param EnrollmentJwtService $jwtService JWT service for token validation.
     */
    public function __construct(
        EnrollmentJwtService $jwtService,
    ) {
        $this->jwtService = $jwtService;
    }

    /**
     * Handle relay tunnel connection and upgrade.
     *
     * @param Request          $request Workerman HTTP request.
     * @param array<string, string> $params Route params containing 'id' (server UUID).
     *
     * @return Response
     *
     * @since 0.12.0
     */
    public function handle(Request $request, array $params): Response
    {
        $serverId = $params['id'] ?? '';

        if ($serverId === '') {
            return (new Response())->status(400)->json([
                'error' => 'MISSING_SERVER_ID',
                'message' => 'Server ID is required',
            ]);
        }

        $authHeader = $request->headers['Authorization'] ?? '';
        if (!str_starts_with($authHeader, 'Bearer ')) {
            return (new Response())->status(401)->json([
                'error' => 'UNAUTHORIZED',
                'message' => 'Missing or invalid Authorization header',
            ]);
        }

        $enrollmentJwt = substr($authHeader, 7);

        try {
            $kid = $this->extractKid($enrollmentJwt);
            if ($kid === null) {
                return $this->unauthorized('Invalid token format');
            }

            $payload = $this->jwtService->validateEnrollmentJwt($enrollmentJwt, $kid);
            if ($payload === null) {
                return $this->unauthorized('Invalid or expired enrollment token');
            }

            if (($payload['server_id'] ?? '') !== $serverId) {
                return $this->unauthorized('Server ID mismatch');
            }
        } catch (\InvalidArgumentException $e) {
            return $this->unauthorized($e->getMessage());
        }

        if ((($request->headers['Upgrade'] ?? '') !== 'websocket')) {
            return (new Response())->status(426)->json([
                'error' => 'UPGRADE_REQUIRED',
                'message' => 'This endpoint requires a WebSocket upgrade. Please connect via WSS.',
                'upgrade' => 'websocket',
            ]);
        }

        return (new Response())->status(500)->json([
            'error' => 'NOT_IMPLEMENTED',
            'message' => 'WebSocket relay support is not yet fully implemented in this build.',
        ]);
    }

    /**
     * Build a 401 Unauthorized response.
     *
     * @param string $message Error message.
     *
     * @return Response
     */
    private function unauthorized(string $message): Response
    {
        return (new Response())->status(401)->json([
            'error' => 'UNAUTHORIZED',
            'message' => $message,
        ]);
    }

    /**
     * Extract the `kid` from a JWT header.
     *
     * @param string $token JWT string.
     *
     * @return string|null Key ID or null.
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
            /** @var string|null $kid */
            $kid = $header['kid'] ?? null;
            return is_string($kid) ? $kid : null;
        } catch (\JsonException) {
            return null;
        }
    }
}
