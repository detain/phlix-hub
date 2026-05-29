<?php

declare(strict_types=1);

namespace Phlix\Hub\Http\Controllers;

use Phlix\Hub\Hub\HeartbeatHandler;
use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;

/**
 * Server detail API controller.
 *
 * `GET /api/v1/me/servers/{id}` — returns server info, active relay session,
 * and recent heartbeat history for the authenticated user.
 *
 * @package Phlix\Hub\Http\Controllers
 */
final class ServerDetailController
{
    /**
     * @param ServerInfoHandler   $serverInfo   Fetches server info and validates ownership.
     * @param RelaySessionManager $relayManager Fetches the active relay session.
     * @param HeartbeatHandler    $heartbeat    Fetches heartbeat history.
     */
    public function __construct(
        private readonly ServerInfoHandler $serverInfo,
        private readonly RelaySessionManager $relayManager,
        private readonly HeartbeatHandler $heartbeat,
    ) {
    }

    /**
     * `GET /api/v1/me/servers/{id}` — return detailed server info.
     *
     * @param array<string, string> $params Route parameters including `id`.
     *
     * Response shape:
     * {
     *   "server": { "id", "server_name", "version", "status", "last_seen_at", "hostname_candidates": [], "relay_active" },
     *   "relay_session": { "id", "worker_node", "opened_at", "bytes_in", "bytes_out", "last_frame_at" } | null,
     *   "heartbeat_history": [{ "id", "version", "uptime_seconds", "active_sessions", "active_transcodes", "received_at" }]
     * }
     *
     * Status codes:
     * - 200: success
     * - 401: not authenticated
     * - 403: server not owned by user
     * - 404: server not found
     */
    public function getServerDetail(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        if ($userId === '') {
            return (new Response())->status(401)->json([
                'error' => 'Unauthorized',
                'code'  => 'auth.required',
            ]);
        }

        $serverId = $params['id'] ?? '';

        $server = $this->serverInfo->getServerInfo($serverId);
        if ($server === null) {
            return (new Response())->status(404)->json([
                'error' => 'Not Found',
                'code'  => 'server.not_found',
            ]);
        }

        if ($server->userId !== $userId) {
            return (new Response())->status(403)->json([
                'error' => 'Forbidden',
                'code'  => 'server.not_owned',
            ]);
        }

        $relaySession = $this->relayManager->getActiveSession($serverId);
        $heartbeatHistory = $this->heartbeat->getHeartbeatHistory($serverId, 20);

        return (new Response())->json([
            'server' => [
                'id'                   => $server->serverId,
                'server_name'           => $server->serverName,
                'version'              => $server->version,
                'status'               => $server->status,
                'last_seen_at'         => $server->lastSeenAt,
                'hostname_candidates'  => $server->hostnameCandidates,
                'relay_active'         => $server->relayActive,
            ],
            'relay_session' => $relaySession !== null ? [
                'id'          => $relaySession['id'],
                'worker_node' => $relaySession['worker_node'],
                'opened_at'  => $relaySession['opened_at'],
                'bytes_in'   => $relaySession['bytes_in'],
                'bytes_out'  => $relaySession['bytes_out'],
                'last_frame_at' => $relaySession['last_frame_at'],
            ] : null,
            'heartbeat_history' => $heartbeatHistory,
        ]);
    }
}
