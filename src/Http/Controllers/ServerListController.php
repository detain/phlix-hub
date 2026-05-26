<?php

declare(strict_types=1);

namespace Phlix\Hub\Http\Controllers;

use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;

/**
 * JSON API for the authenticated user's server list.
 *
 * `GET /api/v1/me/servers` — returns all servers owned by the user.
 *
 * @package Phlix\Hub\Http\Controllers
 */
final class ServerListController
{
    /**
     * @param ServerInfoHandler $serverInfo Used to fetch the user's servers.
     */
    public function __construct(
        private readonly ServerInfoHandler $serverInfo,
    ) {
    }

    /**
     * `GET /api/v1/me/servers` — returns `{servers: ServerInfoDto[]}`.
     */
    public function __invoke(Request $request): Response
    {
        return $this->listServers($request);
    }

    /**
     * `GET /api/v1/me/servers` — returns `{servers: ServerInfoDto[]}`.
     */
    public function listServers(Request $request): Response
    {
        $userId = $request->userId ?? '';
        if ($userId === '') {
            return (new Response())->status(401)->json([
                'error' => 'Unauthorized',
                'code'  => 'auth.required',
            ]);
        }

        $servers = $this->serverInfo->getServersForUser($userId);

        return (new Response())->json([
            'servers' => array_map(fn ($s) => $s->toPayload(), $servers),
        ]);
    }
}
