<?php

/**
 * Phlix hub component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

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
 */
final class ServerManageController
{
    /**
     * @param ServerInfoHandler $serverInfo   Used to fetch server info and verify ownership.
     * @param Connection        $db           Used to delete the server row.
     * @param string            $publicDomain Public domain used to build relay URLs (e.g. `phlix.media`).
     */
    public function __construct(
        private readonly ServerInfoHandler $serverInfo,
        private readonly Connection $db,
        private readonly string $publicDomain = 'phlix.media',
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
        $relayUrl = $this->buildRelayUrl($server->subdomain, $relayActive);

        return (new Response())->json([
            'server_id'    => $serverId,
            'direct_url'   => $directUrl,
            'relay_url'    => $relayUrl,
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
            if ($url !== '') {
                return $url;
            }
        }
        return null;
    }

    /**
     * Build the public relay URL for a server.
     *
     * Returns `https://{subdomain}.{publicDomain}` when the relay tunnel
     * is active AND the server has been allocated a subdomain (migration
     * 008 / `DnsAliasManager`). Otherwise returns null — clients should
     * not be handed a URL that is structurally complete but unreachable.
     *
     * @param string|null $subdomain Subdomain from ServerInfoDto (already loaded).
     * @param bool        $relayActive Whether the relay tunnel is currently open.
     *
     * @return string|null Full https URL or null when relay is unreachable.
     */
    private function buildRelayUrl(?string $subdomain, bool $relayActive): ?string
    {
        if (!$relayActive) {
            return null;
        }

        if (!is_string($subdomain) || $subdomain === '') {
            return null;
        }

        if ($this->publicDomain === '') {
            return null;
        }

        return 'https://' . $subdomain . '.' . $this->publicDomain;
    }
}
