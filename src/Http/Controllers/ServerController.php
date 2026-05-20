<?php

declare(strict_types=1);

namespace Phlix\Hub\Http\Controllers;

use Phlix\Hub\Hub\DeregisterHandler;
use Phlix\Hub\Hub\HeartbeatHandler;
use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\Http\Middleware\HubProtocolMiddleware;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Shared\Hub\HeartbeatDto;

/**
 * Handles server lifecycle endpoints.
 *
 * POST   /api/v1/servers/{id}/heartbeat  — server health ping (enrollment JWT)
 * GET    /api/v1/servers/{id}/info         — hub operator info (enrollment JWT)
 * DELETE /api/v1/servers/{id}             — server deregisters (enrollment JWT)
 *
 * @package Phlix\Hub\Http\Controllers
 * @since 0.3.0
 */
final class ServerController
{
    /**
     * @param HeartbeatHandler  $heartbeatHandler Heartbeat processing.
     * @param ServerInfoHandler $serverInfoHandler Server info retrieval.
     * @param DeregisterHandler $deregisterHandler Server deregistration.
     */
    public function __construct(
        private readonly HeartbeatHandler $heartbeatHandler,
        private readonly ServerInfoHandler $serverInfoHandler,
        private readonly DeregisterHandler $deregisterHandler,
    ) {
    }

    /**
     * `POST /api/v1/servers/{id}/heartbeat` — server health ping.
     *
     * Requires enrollment JWT; $request->serverId is set by EnrollmentJwtMiddleware.
     */
    /**
     * @param array<string, string> $params Route parameters.
     */
    public function heartbeat(Request $request, array $params): Response
    {
        $protocolHeader = $request->getHeader(HubProtocolMiddleware::HEADER_NAME);
        if ($protocolHeader !== HubProtocolMiddleware::REQUIRED_VERSION) {
            return (new Response())->status(400)->json([
                'error' => 'HUB_PROTOCOL_UNSUPPORTED',
                'message' => 'Accept-Phlix-Protocol: v1 required',
            ]);
        }

        $serverIdFromPath = $params['id'] ?? '';
        $serverIdFromToken = $request->serverId ?? '';

        if ($serverIdFromPath !== $serverIdFromToken) {
            return (new Response())->status(403)->json([
                'error' => 'AUTHORIZATION_FAILED',
                'message' => 'Server ID mismatch',
            ]);
        }

        try {
            $heartbeat = HeartbeatDto::fromPayload($request->body);
        } catch (\InvalidArgumentException $e) {
            return (new Response())->status(400)->json([
                'error' => 'Bad Request',
                'message' => $e->getMessage(),
            ]);
        }

        $token = $request->bearerToken ?? '';

        try {
            $this->heartbeatHandler->handle($serverIdFromPath, $token, $heartbeat);
            return (new Response())->json(['status' => 'ok']);
        } catch (\InvalidArgumentException $e) {
            return $this->mapError($e->getMessage());
        }
    }

    /**
     * `GET /api/v1/servers/{id}/info` — hub operator info about a server.
     *
     * Requires enrollment JWT.
     */
    /**
     * @param array<string, string> $params Route parameters.
     */
    public function info(Request $request, array $params): Response
    {
        $serverId = $params['id'] ?? '';
        $serverIdFromToken = $request->serverId ?? '';

        if ($serverId !== $serverIdFromToken) {
            return (new Response())->status(403)->json([
                'error' => 'AUTHORIZATION_FAILED',
                'message' => 'Server ID mismatch',
            ]);
        }

        $info = $this->serverInfoHandler->getServerInfo($serverId);
        if ($info === null) {
            return (new Response())->status(404)->json([
                'error' => 'SERVER_NOT_FOUND',
                'message' => 'Server not found',
            ]);
        }

        return (new Response())->json($info->toPayload());
    }

    /**
     * `DELETE /api/v1/servers/{id}` — server deregisters.
     *
     * Requires enrollment JWT.
     */
    /**
     * @param array<string, string> $params Route parameters.
     */
    public function disconnect(Request $request, array $params): Response
    {
        $serverIdFromPath = $params['id'] ?? '';
        $serverIdFromToken = $request->serverId ?? '';

        if ($serverIdFromPath !== $serverIdFromToken) {
            return (new Response())->status(403)->json([
                'error' => 'AUTHORIZATION_FAILED',
                'message' => 'Server ID mismatch',
            ]);
        }

        $token = $request->bearerToken ?? '';

        try {
            $this->deregisterHandler->handle($serverIdFromPath, $token);
            return (new Response())->status(204);
        } catch (\InvalidArgumentException $e) {
            return $this->mapError($e->getMessage());
        }
    }

    /**
     * Map an error code string to an HTTP status + body.
     */
    private function mapError(string $code): Response
    {
        return match ($code) {
            'ENROLLMENT_TOKEN_EXPIRED' => (new Response())->status(401)->json([
                'error' => 'ENROLLMENT_TOKEN_EXPIRED',
                'message' => 'Enrollment token has expired',
            ]),
            'SERVER_NOT_FOUND' => (new Response())->status(404)->json([
                'error' => 'SERVER_NOT_FOUND',
                'message' => 'Server not found',
            ]),
            default => (new Response())->status(500)->json([
                'error' => 'HUB_INTERNAL_ERROR',
                'message' => 'An unexpected error occurred',
            ]),
        };
    }
}
