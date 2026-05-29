<?php

declare(strict_types=1);

namespace Phlix\Hub\Http\Controllers;

use Phlix\Hub\Hub\LibrarySharingHandler;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;

/**
 * API controller for library endpoints.
 *
 * @package Phlix\Hub\Http\Controllers
 */
final class LibraryController
{
    /**
     * @param LibrarySharingHandler $sharingHandler Library sharing handler.
     */
    public function __construct(
        private readonly LibrarySharingHandler $sharingHandler,
    ) {
    }

    /**
     * `GET /api/v1/me/libraries?server_id={id}` — list libraries for a server.
     *
     * Returns distinct library_id/library_name pairs that the user has configured
     * for sharing on the specified server. The result is used to populate the library
     * dropdown in the invite-link creation form.
     */
    public function listForServer(Request $request): Response
    {
        $userId = $request->userId ?? '';
        if ($userId === '') {
            return (new Response())->status(401)->json([
                'error' => 'Unauthorized',
                'code' => 'auth.required',
            ]);
        }

        $serverId = $request->query['server_id'] ?? '';
        if (!is_string($serverId) || $serverId === '') {
            return (new Response())->status(400)->json([
                'error' => 'Bad Request',
                'code' => 'missing_server_id',
            ]);
        }

        // Verify the user owns this server.
        if (!$this->sharingHandler->isServerOwnedByUser($serverId, $userId)) {
            return (new Response())->status(403)->json([
                'error' => 'Forbidden',
                'code' => 'not_server_owner',
            ]);
        }

        // Get distinct (library_id, library_name) pairs from library_shares.
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->sharingHandler->getDistinctLibrariesForServer($userId, $serverId);

        $libraries = [];
        foreach ($rows as $row) {
            /** @var string $libId */
            $libId = is_string($row['library_id'] ?? null) ? $row['library_id'] : '';
            /** @var string $libName */
            $libName = is_string($row['library_name'] ?? null) ? $row['library_name'] : '';
            if ($libId !== '') {
                $libraries[] = [
                    'id' => $libId,
                    'name' => $libName,
                ];
            }
        }

        return (new Response())->json([
            'libraries' => $libraries,
        ]);
    }
}
