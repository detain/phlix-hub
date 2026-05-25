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

        // The WS upgrade + multiplex tunnel is not implemented in this build.
        // Return 501 Not Implemented (RFC 9110 §15.6.2) — the canonical status
        // for "this server doesn't know how to fulfill the request method"
        // — instead of 500 (which would imply a transient server fault).
        // Auth still runs above so unauth attempts still get 401/403.
        $docsUrl = 'https://detain.github.io/phlix-docs/dev/relay-protocol';
        $hubWsHost = getenv('HUB_WS_HOST') ?: getenv('HUB_PUBLIC_DOMAIN') ?: 'your-hub-host';

        return (new Response())
            ->header('Link', '<' . $docsUrl . '>; rel="help"')
            ->header('X-WS-Endpoint', 'ws://' . $hubWsHost . ':8802')
            ->status(501)
            ->json([
                'error'   => 'NOT_IMPLEMENTED_VIA_HTTP',
                'code'    => 'relay.ws_http_endpoint',
                'message' => 'Relay tunnel must be established via WebSocket.'
                    . ' Connect to ws://' . $hubWsHost . ':8802 with your enrollment JWT.',
                'ws_endpoint' => 'ws://' . $hubWsHost . ':8802',
                'protocol'    => 'See docs/dev/relay-protocol for the WS framing specification',
                'docs'        => $docsUrl,
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
