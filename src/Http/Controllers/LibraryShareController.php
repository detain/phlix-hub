<?php

declare(strict_types=1);

namespace Phlix\Hub\Http\Controllers;

use InvalidArgumentException;
use Phlix\Hub\Hub\LibraryShare;
use Phlix\Hub\Hub\LibrarySharingHandler;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;

/**
 * API controller for library sharing endpoints.
 *
 * @package Phlix\Hub\Http\Controllers
 * @since 0.5.0
 */
final class LibraryShareController
{
    /**
     * @param LibrarySharingHandler $handler Library sharing handler.
     */
    public function __construct(
        private readonly LibrarySharingHandler $handler,
    ) {
    }

    /**
     * `POST /api/v1/me/shares` — create a new library share.
     */
    public function createShare(Request $request): Response
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

        $email = $body['collaborator_email'] ?? null;
        $serverId = $body['server_id'] ?? null;
        $libraryId = $body['library_id'] ?? null;
        $libraryName = $body['library_name'] ?? '';
        $permission = $body['permission'] ?? LibraryShare::PERMISSION_READ;

        if (!is_string($email) || $email === '') {
            return (new Response())->status(400)->json([
                'error' => 'Bad Request',
                'code' => 'missing_collaborator_email',
            ]);
        }

        if (!is_string($serverId) || $serverId === '') {
            return (new Response())->status(400)->json([
                'error' => 'Bad Request',
                'code' => 'missing_server_id',
            ]);
        }

        if (!is_string($libraryId) || $libraryId === '') {
            return (new Response())->status(400)->json([
                'error' => 'Bad Request',
                'code' => 'missing_library_id',
            ]);
        }

        if (!is_string($libraryName) || $libraryName === '') {
            return (new Response())->status(400)->json([
                'error' => 'Bad Request',
                'code' => 'missing_library_name',
            ]);
        }

        try {
            $share = $this->handler->shareLibrary(
                ownerId: $userId,
                collaboratorEmail: $email,
                serverId: $serverId,
                libraryId: $libraryId,
                libraryName: $libraryName,
                permission: is_string($permission) ? $permission : LibraryShare::PERMISSION_READ,
            );

            return (new Response())->status(201)->json($share->toPayload());
        } catch (InvalidArgumentException $e) {
            $code = $e->getCode();
            if ($code === 404) {
                return (new Response())->status(404)->json([
                    'error' => 'Not Found',
                    'code' => 'user_not_found',
                    'message' => 'No user found with that email address',
                ]);
            }
            if ($code === 403) {
                return (new Response())->status(403)->json([
                    'error' => 'Forbidden',
                    'code' => 'not_server_owner',
                    'message' => 'You do not own this server',
                ]);
            }
            if ($code === 409) {
                return (new Response())->status(409)->json([
                    'error' => 'Conflict',
                    'code' => 'share_exists',
                    'message' => 'This library is already shared with that user',
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
     * `GET /api/v1/me/shares` — list outgoing and incoming shares.
     */
    public function listShares(Request $request): Response
    {
        $userId = $request->userId ?? '';
        if ($userId === '') {
            return (new Response())->status(401)->json([
                'error' => 'Unauthorized',
                'code' => 'auth.required',
            ]);
        }

        $outgoing = $this->handler->getSharesForOwner($userId);
        $incoming = $this->handler->getSharedWithMe($userId);

        return (new Response())->json([
            'outgoing' => array_map(fn (LibraryShare $s) => $s->toPayload(), $outgoing),
            'incoming' => array_map(fn ($dto) => $dto->toPayload(), $incoming),
        ]);
    }

    /**
     * `DELETE /api/v1/me/shares/{id}` — revoke a share.
     *
     * @param array<string, string> $params Route parameters including `id`.
     */
    public function deleteShare(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        if ($userId === '') {
            return (new Response())->status(401)->json([
                'error' => 'Unauthorized',
                'code' => 'auth.required',
            ]);
        }

        $shareId = $params['id'] ?? '';
        if ($shareId === '') {
            return (new Response())->status(400)->json([
                'error' => 'Bad Request',
                'code' => 'missing_share_id',
            ]);
        }

        try {
            $this->handler->revokeShare($userId, $shareId);
            return (new Response())->status(204);
        } catch (InvalidArgumentException $e) {
            $code = $e->getCode();
            if ($code === 404) {
                return (new Response())->status(404)->json([
                    'error' => 'Not Found',
                    'code' => 'share_not_found',
                ]);
            }
            if ($code === 403) {
                return (new Response())->status(403)->json([
                    'error' => 'Forbidden',
                    'code' => 'not_share_owner',
                    'message' => 'You do not own this share',
                ]);
            }
            return (new Response())->status(500)->json([
                'error' => 'Internal Server Error',
                'code' => 'unknown_error',
            ]);
        }
    }

    /**
     * `PATCH /api/v1/me/shares/{id}` — update share permission.
     *
     * @param array<string, string> $params Route parameters including `id`.
     */
    public function updateShare(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        if ($userId === '') {
            return (new Response())->status(401)->json([
                'error' => 'Unauthorized',
                'code' => 'auth.required',
            ]);
        }

        $shareId = $params['id'] ?? '';
        if ($shareId === '') {
            return (new Response())->status(400)->json([
                'error' => 'Bad Request',
                'code' => 'missing_share_id',
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

        $permission = $body['permission'] ?? null;
        if (!is_string($permission)) {
            return (new Response())->status(400)->json([
                'error' => 'Bad Request',
                'code' => 'missing_permission',
            ]);
        }

        try {
            $this->handler->updateSharePermission($userId, $shareId, $permission);
            $updatedShare = $this->handler->getShareById($shareId);
            return (new Response())->json($updatedShare?->toPayload() ?? []);
        } catch (InvalidArgumentException $e) {
            $code = $e->getCode();
            if ($code === 404) {
                return (new Response())->status(404)->json([
                    'error' => 'Not Found',
                    'code' => 'share_not_found',
                ]);
            }
            if ($code === 403) {
                return (new Response())->status(403)->json([
                    'error' => 'Forbidden',
                    'code' => 'not_share_owner',
                    'message' => 'You do not own this share',
                ]);
            }
            if ($code === 400) {
                return (new Response())->status(400)->json([
                    'error' => 'Bad Request',
                    'code' => 'invalid_permission',
                    'message' => $e->getMessage(),
                ]);
            }
            return (new Response())->status(500)->json([
                'error' => 'Internal Server Error',
                'code' => 'unknown_error',
            ]);
        }
    }
}
