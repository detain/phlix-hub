<?php

/**
 * Phlix hub component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

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
 * Every failure here is shown to a signed-in member in the portal, so every
 * envelope goes through {@see self::memberError()} — human sentence in
 * `error`, registered machine code in `code`, `message` mirroring `error`.
 *
 * @package Phlix\Hub\Http\Controllers
 */
final class LibraryShareController
{
    /**
     * Fail-safe copy for pass-through arms whose handler exception carries no
     * message at all. The pinned portal client renders the `error` field
     * verbatim even when it is the empty string, so the wire must never ship
     * one (review hub__casualgamer M5).
     */
    private const GENERIC_PROBLEM = 'Something went wrong on the hub. Please try again.';

    /**
     * @param LibrarySharingHandler $handler Library sharing handler.
     */
    public function __construct(
        private readonly LibrarySharingHandler $handler,
    ) {
    }

    /**
     * Canonical member-facing error envelope.
     *
     * The portal's pinned client (`extractError` in @phlix/ui
     * `src/api/client.ts`) checks `error` before `message`, so the human
     * sentence lives in `error`; `code` carries the registered
     * `@phlix/contracts` machine code (contracts law — stable codes, free
     * text). `message` mirrors `error` so the same sentence renders under
     * either client precedence. Whitespace-only pass-through messages are
     * normalized to {@see self::GENERIC_PROBLEM}.
     */
    private function memberError(int $status, string $code, string $message): Response
    {
        $human = trim($message);
        if ($human === '') {
            $human = self::GENERIC_PROBLEM;
        }

        return (new Response())->error($status, $code, $human, ['message' => $human]);
    }

    /**
     * `POST /api/v1/me/shares` — create a new library share.
     */
    public function createShare(Request $request): Response
    {
        $userId = $request->userId ?? '';
        if ($userId === '') {
            return $this->memberError(401, 'auth.required', 'Sign in to share your libraries');
        }

        $body = $request->body;
        if ($body === []) {
            return $this->memberError(400, 'invalid_body', 'A request body is required');
        }

        /** @var mixed $email */
        $email = $body['collaborator_email'] ?? null;
        /** @var mixed $serverId */
        $serverId = $body['server_id'] ?? null;
        /** @var mixed $libraryId */
        $libraryId = $body['library_id'] ?? null;
        /** @var mixed $libraryName */
        $libraryName = $body['library_name'] ?? '';
        /** @var mixed $permission */
        $permission = $body['permission'] ?? LibraryShare::PERMISSION_READ;

        if (!is_string($email) || $email === '') {
            return $this->memberError(
                400,
                'missing_collaborator_email',
                'Enter the email address of the person you want to share with',
            );
        }

        if (!is_string($serverId) || $serverId === '') {
            return $this->memberError(400, 'missing_server_id', 'Select which server the library lives on');
        }

        if (!is_string($libraryId) || $libraryId === '') {
            return $this->memberError(400, 'missing_library_id', 'Select which library to share');
        }

        if (!is_string($libraryName) || $libraryName === '') {
            return $this->memberError(400, 'missing_library_name', 'Give the shared library a name');
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
            // W5 share quota: discriminated by message before the generic 409
            // (share_exists) arm — both carry code 409.
            if ($e->getMessage() === 'SERVER_SHARE_CAP_REACHED') {
                return $this->memberError(
                    409,
                    'quota.exceeded',
                    'This server has reached its collaborator limit',
                );
            }
            if ($code === 404) {
                return $this->memberError(404, 'user_not_found', 'No user found with that email address');
            }
            if ($code === 403) {
                return $this->memberError(403, 'not_server_owner', 'You do not own this server');
            }
            if ($code === 409) {
                return $this->memberError(409, 'share_exists', 'This library is already shared with that user');
            }
            if ($code === 400) {
                return $this->memberError(400, 'invalid_request', $e->getMessage());
            }
            return $this->memberError(500, 'unknown_error', self::GENERIC_PROBLEM);
        }
    }

    /**
     * `GET /api/v1/me/shares` — list outgoing and incoming shares.
     */
    public function listShares(Request $request): Response
    {
        $userId = $request->userId ?? '';
        if ($userId === '') {
            return $this->memberError(401, 'auth.required', 'Sign in to see your shares');
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
            return $this->memberError(401, 'auth.required', 'Sign in to remove a share');
        }

        $shareId = $params['id'] ?? '';
        if ($shareId === '') {
            return $this->memberError(400, 'missing_share_id', 'Select the share you want to remove');
        }

        try {
            $this->handler->revokeShare($userId, $shareId);
            return (new Response())->status(204);
        } catch (InvalidArgumentException $e) {
            $code = $e->getCode();
            if ($code === 404) {
                return $this->memberError(404, 'share_not_found', 'That share no longer exists');
            }
            if ($code === 403) {
                return $this->memberError(403, 'not_share_owner', 'You do not own this share');
            }
            return $this->memberError(500, 'unknown_error', self::GENERIC_PROBLEM);
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
            return $this->memberError(401, 'auth.required', 'Sign in to update a share');
        }

        $shareId = $params['id'] ?? '';
        if ($shareId === '') {
            return $this->memberError(400, 'missing_share_id', 'Select the share you want to change');
        }

        $body = $request->body;
        if ($body === []) {
            return $this->memberError(400, 'invalid_body', 'A request body is required');
        }

        /** @var mixed $permission */
        $permission = $body['permission'] ?? null;
        if (!is_string($permission)) {
            return $this->memberError(400, 'missing_permission', 'Choose a permission level for this share');
        }

        try {
            $this->handler->updateSharePermission($userId, $shareId, $permission);
            $updatedShare = $this->handler->getShareById($shareId);
            return (new Response())->json($updatedShare?->toPayload() ?? []);
        } catch (InvalidArgumentException $e) {
            $code = $e->getCode();
            if ($code === 404) {
                return $this->memberError(404, 'share_not_found', 'That share no longer exists');
            }
            if ($code === 403) {
                return $this->memberError(403, 'not_share_owner', 'You do not own this share');
            }
            if ($code === 400) {
                return $this->memberError(400, 'invalid_permission', $e->getMessage());
            }
            return $this->memberError(500, 'unknown_error', self::GENERIC_PROBLEM);
        }
    }
}
