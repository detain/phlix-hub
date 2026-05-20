<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\unit\Http\Controllers;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Phlix\Hub\Hub\LibraryShare;
use Phlix\Hub\Hub\LibrarySharingHandler;
use Phlix\Hub\Http\Controllers\LibraryShareController;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Hub\SharedLibraryDto;

/**
 * Unit tests for {@see LibraryShareController}.
 *
 * @package Phlix\Hub\Tests\unit\Http\Controllers
 * @since 0.5.0
 *
 * @covers \Phlix\Hub\Http\Controllers\LibraryShareController
 */
final class LibraryShareControllerTest extends TestCase
{
    private LibrarySharingHandler $handler;
    private LibraryShareController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = $this->createMock(LibrarySharingHandler::class);
        $this->controller = new LibraryShareController($this->handler);
    }

    public function testCreateShareReturns401WhenNotAuthenticated(): void
    {
        $request = new Request();
        $request->path = '/api/v1/me/shares';
        $request->method = 'POST';

        $response = $this->controller->createShare($request);

        self::assertSame(401, $response->statusCode);
    }

    public function testCreateShareReturns400WhenBodyMissing(): void
    {
        $request = new Request();
        $request->path = '/api/v1/me/shares';
        $request->method = 'POST';
        $request->userId = 'user-1';

        $response = $this->controller->createShare($request);

        self::assertSame(400, $response->statusCode);
    }

    public function testCreateShareReturns201OnSuccess(): void
    {
        $share = new LibraryShare(
            id: 'share-1',
            ownerUserId: 'owner-1',
            collaboratorUserId: 'collab-1',
            serverId: 'server-1',
            libraryId: 'lib-1',
            libraryName: 'My Movies',
            permissionLevel: LibraryShare::PERMISSION_READ,
            createdAt: time(),
        );

        $this->handler->method('shareLibrary')->willReturn($share);

        $request = new Request();
        $request->path = '/api/v1/me/shares';
        $request->method = 'POST';
        $request->userId = 'owner-1';
        $request->body = [
            'collaborator_email' => 'friend@example.com',
            'server_id' => 'server-1',
            'library_id' => 'lib-1',
            'library_name' => 'My Movies',
            'permission' => 'read',
        ];

        $response = $this->controller->createShare($request);

        self::assertSame(201, $response->statusCode);
    }

    public function testCreateShareReturns404WhenUserNotFound(): void
    {
        $this->handler->method('shareLibrary')
            ->willThrowException(new InvalidArgumentException('User not found', 404));

        $request = new Request();
        $request->path = '/api/v1/me/shares';
        $request->method = 'POST';
        $request->userId = 'owner-1';
        $request->body = [
            'collaborator_email' => 'nonexistent@example.com',
            'server_id' => 'server-1',
            'library_id' => 'lib-1',
            'library_name' => 'My Movies',
        ];

        $response = $this->controller->createShare($request);

        self::assertSame(404, $response->statusCode);
    }

    public function testCreateShareReturns403WhenNotServerOwner(): void
    {
        $this->handler->method('shareLibrary')
            ->willThrowException(new InvalidArgumentException('You do not own this server', 403));

        $request = new Request();
        $request->path = '/api/v1/me/shares';
        $request->method = 'POST';
        $request->userId = 'owner-1';
        $request->body = [
            'collaborator_email' => 'friend@example.com',
            'server_id' => 'server-1',
            'library_id' => 'lib-1',
            'library_name' => 'My Movies',
        ];

        $response = $this->controller->createShare($request);

        self::assertSame(403, $response->statusCode);
    }

    public function testCreateShareReturns409WhenShareExists(): void
    {
        $this->handler->method('shareLibrary')
            ->willThrowException(new InvalidArgumentException('Share already exists', 409));

        $request = new Request();
        $request->path = '/api/v1/me/shares';
        $request->method = 'POST';
        $request->userId = 'owner-1';
        $request->body = [
            'collaborator_email' => 'friend@example.com',
            'server_id' => 'server-1',
            'library_id' => 'lib-1',
            'library_name' => 'My Movies',
        ];

        $response = $this->controller->createShare($request);

        self::assertSame(409, $response->statusCode);
    }

    public function testListSharesReturnsOutgoingAndIncoming(): void
    {
        $outgoing = [
            new LibraryShare(
                id: 'share-1',
                ownerUserId: 'user-1',
                collaboratorUserId: 'collab-1',
                serverId: 'server-1',
                libraryId: 'lib-1',
                libraryName: 'My Movies',
                permissionLevel: LibraryShare::PERMISSION_READ,
                createdAt: time(),
            ),
        ];

        $incoming = [
            new SharedLibraryDto(
                shareId: 'share-2',
                ownerUserId: 'owner-2',
                ownerName: 'Other User',
                serverId: 'server-2',
                serverName: 'Other Server',
                libraryId: 'lib-2',
                libraryName: 'Their Movies',
                libraryItemCount: 50,
                permissionLevel: LibraryShare::PERMISSION_READ,
                accessUrls: ['https://other.example.com'],
            ),
        ];

        $this->handler->method('getSharesForOwner')->willReturn($outgoing);
        $this->handler->method('getSharedWithMe')->willReturn($incoming);

        $request = new Request();
        $request->path = '/api/v1/me/shares';
        $request->method = 'GET';
        $request->userId = 'user-1';

        $response = $this->controller->listShares($request);

        self::assertSame(200, $response->statusCode);
    }

    public function testListSharesReturns401WhenNotAuthenticated(): void
    {
        $request = new Request();
        $request->path = '/api/v1/me/shares';
        $request->method = 'GET';

        $response = $this->controller->listShares($request);

        self::assertSame(401, $response->statusCode);
    }

    public function testDeleteShareReturns204OnSuccess(): void
    {
        $this->handler->expects(self::once())->method('revokeShare')
            ->with('user-1', 'share-1');

        $request = new Request();
        $request->path = '/api/v1/me/shares/share-1';
        $request->method = 'DELETE';
        $request->userId = 'user-1';

        $response = $this->controller->deleteShare($request, ['id' => 'share-1']);

        self::assertSame(204, $response->statusCode);
    }

    public function testDeleteShareReturns404WhenShareNotFound(): void
    {
        $this->handler->method('revokeShare')
            ->willThrowException(new InvalidArgumentException('Share not found', 404));

        $request = new Request();
        $request->path = '/api/v1/me/shares/nonexistent';
        $request->method = 'DELETE';
        $request->userId = 'user-1';

        $response = $this->controller->deleteShare($request, ['id' => 'nonexistent']);

        self::assertSame(404, $response->statusCode);
    }

    public function testDeleteShareReturns403WhenNotOwner(): void
    {
        $this->handler->method('revokeShare')
            ->willThrowException(new InvalidArgumentException('You do not own this share', 403));

        $request = new Request();
        $request->path = '/api/v1/me/shares/share-1';
        $request->method = 'DELETE';
        $request->userId = 'user-1';

        $response = $this->controller->deleteShare($request, ['id' => 'share-1']);

        self::assertSame(403, $response->statusCode);
    }

    public function testUpdateShareReturnsUpdatedShare(): void
    {
        $updatedShare = new LibraryShare(
            id: 'share-1',
            ownerUserId: 'user-1',
            collaboratorUserId: 'collab-1',
            serverId: 'server-1',
            libraryId: 'lib-1',
            libraryName: 'My Movies',
            permissionLevel: LibraryShare::PERMISSION_READWRITE,
            createdAt: time(),
        );

        $this->handler->expects(self::once())->method('updateSharePermission')
            ->with('user-1', 'share-1', LibraryShare::PERMISSION_READWRITE);
        $this->handler->method('getShareById')->willReturn($updatedShare);

        $request = new Request();
        $request->path = '/api/v1/me/shares/share-1';
        $request->method = 'PATCH';
        $request->userId = 'user-1';
        $request->body = ['permission' => 'readwrite'];

        $response = $this->controller->updateShare($request, ['id' => 'share-1']);

        self::assertSame(200, $response->statusCode);
    }

    public function testUpdateShareReturns400WhenPermissionMissing(): void
    {
        $request = new Request();
        $request->path = '/api/v1/me/shares/share-1';
        $request->method = 'PATCH';
        $request->userId = 'user-1';
        $request->body = [];

        $response = $this->controller->updateShare($request, ['id' => 'share-1']);

        self::assertSame(400, $response->statusCode);
    }
}
