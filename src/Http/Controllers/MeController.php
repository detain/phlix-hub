<?php

declare(strict_types=1);

namespace Phlix\Hub\Http\Controllers;

use Phlix\Hub\Auth\AuthManager;
use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;

/**
 * JSON endpoint returning the current user's record and decoded claims.
 *
 * Wired behind {@see \Phlix\Hub\Http\Middleware\AuthMiddleware}, so by
 * the time this controller runs `$request->userId` and `$request->claims`
 * are populated.
 *
 * @package Phlix\Hub\Http\Controllers
 */
final class MeController
{
    /**
     * @param AuthManager     $auth          Used to fetch the user row by id.
     * @param ServerInfoHandler $serverInfo  Used to fetch user's servers.
     */
    public function __construct(
        private readonly AuthManager $auth,
        private readonly ServerInfoHandler $serverInfo,
    ) {
    }

    /**
     * `GET /api/v1/me` — returns `{user, claims}`.
     */
    public function __invoke(Request $request): Response
    {
        $userId = $request->userId ?? '';
        if ($userId === '') {
            // Defensive: middleware should have caught this. Return 401
            // so the surface is consistent.
            return (new Response())->status(401)->json([
                'error' => 'Unauthorized',
                'code'  => 'auth.required',
            ]);
        }
        $user = $this->auth->getCurrentUser($userId);
        if ($user === null) {
            return (new Response())->status(404)->json([
                'error' => 'Not Found',
                'code'  => 'user.not_found',
            ]);
        }
        $servers = $this->serverInfo->getServersForUser($userId);
        return (new Response())->json([
            'user'   => $user,
            'claims' => $request->claims?->toPayload() ?? [],
            'servers' => array_map(fn ($s) => $s->toPayload(), $servers),
        ]);
    }
}
