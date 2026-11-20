<?php

/**
 * Phlix hub component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Http\Controllers;

use Phlix\Hub\Hub\LibrarySharingHandler;
use Phlix\Hub\Http\ControllerErrorFrames;
use Phlix\Hub\Http\ControllerRequests;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Shared\Database\RowMap;

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
        $userId = ControllerRequests::authenticatedUserId($request);
        if ($userId === null) {
            return ControllerErrorFrames::unauthorized();
        }

        $serverId = $request->query['server_id'] ?? '';
        if (!is_string($serverId) || $serverId === '') {
            return (new Response())->error(400, 'missing_server_id', 'Bad Request');
        }

        // Verify the user owns this server.
        if (!$this->sharingHandler->isServerOwnedByUser($serverId, $userId)) {
            return (new Response())->error(403, 'not_server_owner', 'Forbidden');
        }

        // Get distinct (library_id, library_name) pairs from library_shares.
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->sharingHandler->getDistinctLibrariesForServer($userId, $serverId);

        $libraries = [];
        foreach ($rows as $row) {
            $libId = RowMap::str($row, 'library_id', '');
            $libName = RowMap::str($row, 'library_name', '');
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
