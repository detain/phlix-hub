<?php

declare(strict_types=1);

namespace Phlex\Hub\Http\Controllers;

use InvalidArgumentException;
use Phlex\Hub\Hub\InviteLink;
use Phlex\Hub\Hub\InviteLinkHandler;
use Phlex\Hub\Http\Request;
use Phlex\Hub\Http\Response;

/**
 * API controller for invite link endpoints.
 *
 * @package Phlex\Hub\Http\Controllers
 * @since 0.6.0
 */
final class InviteLinkController
{
    /**
     * @param InviteLinkHandler $handler Invite link handler.
     */
    public function __construct(
        private readonly InviteLinkHandler $handler,
    ) {
    }

    /**
     * `POST /api/v1/me/invite-links` — create a new invite link.
     */
    public function createInviteLink(Request $request): Response
    {
        $userId = $request->userId ?? '';
        if ($userId === '') {
            return (new Response())->status(401)->json([
                'error' => 'Unauthorized',
                'code' => 'auth.required',
            ]);
        }

        /** @var array<string, mixed> $body */
        $body = $request->body;
        if (!is_array($body) || $body === []) {
            return (new Response())->status(400)->json([
                'error' => 'Bad Request',
                'code' => 'invalid_body',
            ]);
        }

        $serverId = $body['server_id'] ?? null;
        $libraryId = $body['library_id'] ?? null;
        $permission = $body['permission'] ?? 'read';
        $maxUses = $body['max_uses'] ?? 1;
        $expiresIn = $body['expires_in'] ?? 604800;

        if (!is_string($serverId) || $serverId === '') {
            return (new Response())->status(400)->json([
                'error' => 'Bad Request',
                'code' => 'missing_server_id',
            ]);
        }

        $expiresAt = null;
        if (is_numeric($expiresIn) && (int) $expiresIn > 0) {
            $expiresAt = time() + (int) $expiresIn;
        }

        try {
            $link = $this->handler->createInviteLink(
                ownerId: $userId,
                serverId: $serverId,
                libraryId: is_string($libraryId) && $libraryId !== '' ? $libraryId : null,
                permission: is_string($permission) ? $permission : 'read',
                maxUses: is_numeric($maxUses) ? max(1, (int) $maxUses) : 1,
                expiresAt: $expiresAt,
            );

            return (new Response())->status(201)->json([
                'url' => $link->url,
                'expires_at' => $link->expiresAt,
                'id' => $link->id,
            ]);
        } catch (InvalidArgumentException $e) {
            $code = $e->getCode();
            if ($code === 403) {
                return (new Response())->status(403)->json([
                    'error' => 'Forbidden',
                    'code' => 'not_server_owner',
                    'message' => 'You do not own this server',
                ]);
            }
            if ($code === 400) {
                return (new Response())->status(400)->json([
                    'error' => 'Bad Request',
                    'code' => 'invalid_request',
                    'message' => $e->getMessage(),
                ]);
            }
            return (new Response())->status(500)->json([
                'error' => 'Internal Server Error',
                'code' => 'unknown_error',
            ]);
        }
    }

    /**
     * `GET /api/v1/me/invite-links` — list invite links for the authenticated user.
     */
    public function listInviteLinks(Request $request): Response
    {
        $userId = $request->userId ?? '';
        if ($userId === '') {
            return (new Response())->status(401)->json([
                'error' => 'Unauthorized',
                'code' => 'auth.required',
            ]);
        }

        $links = $this->handler->listForOwner($userId);

        return (new Response())->json([
            'invite_links' => array_map(fn (InviteLink $link) => $link->toPayload(), $links),
        ]);
    }

    /**
     * `DELETE /api/v1/me/invite-links/{id}` — revoke an invite link.
     *
     * @param array<string, string> $params Route parameters including `id`.
     */
    public function deleteInviteLink(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        if ($userId === '') {
            return (new Response())->status(401)->json([
                'error' => 'Unauthorized',
                'code' => 'auth.required',
            ]);
        }

        $linkId = $params['id'] ?? '';
        if ($linkId === '') {
            return (new Response())->status(400)->json([
                'error' => 'Bad Request',
                'code' => 'missing_link_id',
            ]);
        }

        try {
            $this->handler->revokeInviteLink($userId, $linkId);
            return (new Response())->status(204);
        } catch (InvalidArgumentException $e) {
            $code = $e->getCode();
            if ($code === 404) {
                return (new Response())->status(404)->json([
                    'error' => 'Not Found',
                    'code' => 'invite_link_not_found',
                ]);
            }
            if ($code === 403) {
                return (new Response())->status(403)->json([
                    'error' => 'Forbidden',
                    'code' => 'not_link_owner',
                    'message' => 'You do not own this invite link',
                ]);
            }
            return (new Response())->status(500)->json([
                'error' => 'Internal Server Error',
                'code' => 'unknown_error',
            ]);
        }
    }

    /**
     * `GET /invite/{token}` — render the invite acceptance page.
     *
     * @param array<string, string> $params Route parameters including `token`.
     */
    public function showAcceptInvitePage(Request $request, array $params): Response
    {
        $token = $params['token'] ?? '';
        if ($token === '') {
            return (new Response())->status(404)->html('<h1>Not Found</h1>');
        }

        return (new Response())->json([
            'token' => $token,
            'is_authenticated' => $request->userId !== null,
        ]);
    }
}
