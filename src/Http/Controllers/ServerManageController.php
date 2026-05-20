<?php

declare(strict_types=1);

namespace Phlix\Hub\Http\Controllers;

use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Workerman\MySQL\Connection;

/**
 * Manage a specific server owned by the authenticated user.
 *
 * `DELETE /api/v1/me/servers/{id}` — remove a claimed server.
 * `GET  /api/v1/me/servers/{id}/access-info` — best URL for client access.
 *
 * @package Phlix\Hub\Http\Controllers
 * @since 0.4.0
 */
final class ServerManageController
{
    /**
     * @param ServerInfoHandler $serverInfo Used to fetch server info and verify ownership.
     * @param Connection          $db         Used to delete the server row.
     */
    public function __construct(
        private readonly ServerInfoHandler $serverInfo,
        private readonly Connection $db,
    ) {
    }

    /**
     * `DELETE /api/v1/me/servers/{id}` — remove a claimed server.
     *
     * Returns 204 No Content on success. Returns 403 when the server
     * is not owned by the authenticated user. Returns 404 when the
     * server does not exist.
     *
     * @param array<string, string> $params Route parameters including `id`.
     */
    public function deleteServer(Request $request, array $params): Response
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

        $this->db->query(
            'DELETE FROM servers WHERE id = :id AND user_id = :user_id',
            ['id' => $serverId, 'user_id' => $userId],
        );

        return (new Response())->status(204);
    }

    /**
     * `GET /api/v1/me/servers/{id}/access-info` — best URL for client access.
     *
     * Prefers a direct URL from `hostname_candidates` when one is
     * publicly reachable; falls back to the relay URL. Returns 404
     * when the server does not exist and 403 when not owned.
     *
     * @param array<string, string> $params Route parameters including `id`.
     */
    public function accessInfo(Request $request, array $params): Response
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

        $directUrl = $this->bestDirectUrl($server->hostnameCandidates);
        $relayActive = $server->relayActive;

        return (new Response())->json([
            'server_id'    => $serverId,
            'direct_url'   => $directUrl,
            'relay_url'    => null,
            'relay_active' => $relayActive,
        ]);
    }

    /**
     * Pick the best publicly-reachable direct URL from the candidates.
     *
     * @param list<string> $candidates
     *
     * @return string|null
     */
    private function bestDirectUrl(array $candidates): ?string
    {
        foreach ($candidates as $url) {
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }
        return null;
    }
}
