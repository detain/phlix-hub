<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\unit\Http\Controllers;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Phlix\Hub\Hub\InviteLink;
use Phlix\Hub\Hub\InviteLinkHandler;
use Phlix\Hub\Http\Controllers\InviteLinkController;
use Phlix\Hub\Http\Request;

/**
 * Unit tests for {@see InviteLinkController}.
 *
 * @package Phlix\Hub\Tests\unit\Http\Controllers
 * @since 0.6.0
 *
 * @covers \Phlix\Hub\Http\Controllers\InviteLinkController
 */
final class InviteLinkControllerTest extends TestCase
{
    private InviteLinkHandler $handler;
    private InviteLinkController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = $this->createMock(InviteLinkHandler::class);
        $this->controller = new InviteLinkController($this->handler);
    }

    public function testCreateInviteLinkReturns401WhenNotAuthenticated(): void
    {
        $request = new Request();
        $request->path = '/api/v1/me/invite-links';
        $request->method = 'POST';

        $response = $this->controller->createInviteLink($request);

        self::assertSame(401, $response->statusCode);
    }

    public function testCreateInviteLinkReturns400WhenBodyMissing(): void
    {
        $request = new Request();
        $request->path = '/api/v1/me/invite-links';
        $request->method = 'POST';
        $request->userId = 'user-1';

        $response = $this->controller->createInviteLink($request);

        self::assertSame(400, $response->statusCode);
    }

    public function testCreateInviteLinkReturns400WhenServerIdMissing(): void
    {
        $request = new Request();
        $request->path = '/api/v1/me/invite-links';
        $request->method = 'POST';
        $request->userId = 'user-1';
        $request->body = [
            'library_id' => 'lib-1',
        ];

        $response = $this->controller->createInviteLink($request);

        self::assertSame(400, $response->statusCode);
    }

    public function testCreateInviteLinkReturns201OnSuccess(): void
    {
        $link = new InviteLink(
            id: 'link-1',
            ownerUserId: 'user-1',
            serverId: 'server-1',
            libraryId: 'lib-1',
            permission: 'read',
            maxUses: 1,
            useCount: 0,
            expiresAt: time() + 604800,
            createdAt: time(),
            url: 'https://hub.example.com/invite/token123',
        );

        $this->handler->method('createInviteLink')->willReturn($link);

        $request = new Request();
        $request->path = '/api/v1/me/invite-links';
        $request->method = 'POST';
        $request->userId = 'user-1';
        $request->body = [
            'server_id' => 'server-1',
            'library_id' => 'lib-1',
            'permission' => 'read',
            'max_uses' => 1,
            'expires_in' => 604800,
        ];

        $response = $this->controller->createInviteLink($request);

        self::assertSame(201, $response->statusCode);
    }

    public function testCreateInviteLinkReturns403WhenNotServerOwner(): void
    {
        $this->handler->method('createInviteLink')
            ->willThrowException(new InvalidArgumentException('You do not own this server', 403));

        $request = new Request();
        $request->path = '/api/v1/me/invite-links';
        $request->method = 'POST';
        $request->userId = 'user-1';
        $request->body = [
            'server_id' => 'server-1',
            'library_id' => 'lib-1',
        ];

        $response = $this->controller->createInviteLink($request);

        self::assertSame(403, $response->statusCode);
    }

    public function testListInviteLinksReturns401WhenNotAuthenticated(): void
    {
        $request = new Request();
        $request->path = '/api/v1/me/invite-links';
        $request->method = 'GET';

        $response = $this->controller->listInviteLinks($request);

        self::assertSame(401, $response->statusCode);
    }

    public function testListInviteLinksReturns200WithLinks(): void
    {
        $links = [
            new InviteLink(
                id: 'link-1',
                ownerUserId: 'user-1',
                serverId: 'server-1',
                libraryId: 'lib-1',
                permission: 'read',
                maxUses: 5,
                useCount: 2,
                expiresAt: null,
                createdAt: time(),
                url: 'https://hub.example.com/invite/token123',
            ),
        ];

        $this->handler->method('listForOwner')->willReturn($links);

        $request = new Request();
        $request->path = '/api/v1/me/invite-links';
        $request->method = 'GET';
        $request->userId = 'user-1';

        $response = $this->controller->listInviteLinks($request);

        self::assertSame(200, $response->statusCode);
    }

    public function testDeleteInviteLinkReturns401WhenNotAuthenticated(): void
    {
        $request = new Request();
        $request->path = '/api/v1/me/invite-links/link-1';
        $request->method = 'DELETE';

        $response = $this->controller->deleteInviteLink($request, ['id' => 'link-1']);

        self::assertSame(401, $response->statusCode);
    }

    public function testDeleteInviteLinkReturns400WhenIdMissing(): void
    {
        $request = new Request();
        $request->path = '/api/v1/me/invite-links/';
        $request->method = 'DELETE';
        $request->userId = 'user-1';

        $response = $this->controller->deleteInviteLink($request, []);

        self::assertSame(400, $response->statusCode);
    }

    public function testDeleteInviteLinkReturns204OnSuccess(): void
    {
        $this->handler->expects(self::once())->method('revokeInviteLink')
            ->with('user-1', 'link-1');

        $request = new Request();
        $request->path = '/api/v1/me/invite-links/link-1';
        $request->method = 'DELETE';
        $request->userId = 'user-1';

        $response = $this->controller->deleteInviteLink($request, ['id' => 'link-1']);

        self::assertSame(204, $response->statusCode);
    }

    public function testDeleteInviteLinkReturns404WhenLinkNotFound(): void
    {
        $this->handler->method('revokeInviteLink')
            ->willThrowException(new InvalidArgumentException('Invite link not found', 404));

        $request = new Request();
        $request->path = '/api/v1/me/invite-links/nonexistent';
        $request->method = 'DELETE';
        $request->userId = 'user-1';

        $response = $this->controller->deleteInviteLink($request, ['id' => 'nonexistent']);

        self::assertSame(404, $response->statusCode);
    }

    public function testDeleteInviteLinkReturns403WhenNotOwner(): void
    {
        $this->handler->method('revokeInviteLink')
            ->willThrowException(new InvalidArgumentException('You do not own this invite link', 403));

        $request = new Request();
        $request->path = '/api/v1/me/invite-links/link-1';
        $request->method = 'DELETE';
        $request->userId = 'user-1';

        $response = $this->controller->deleteInviteLink($request, ['id' => 'link-1']);

        self::assertSame(403, $response->statusCode);
    }

    public function testShowAcceptInvitePageReturnsToken(): void
    {
        $request = new Request();
        $request->path = '/invite/token123';
        $request->method = 'GET';
        $request->userId = 'user-1';

        $response = $this->controller->showAcceptInvitePage($request, ['token' => 'token123']);

        self::assertSame(200, $response->statusCode);
    }

    public function testShowAcceptInvitePageReturns404WhenTokenMissing(): void
    {
        $request = new Request();
        $request->path = '/invite/';
        $request->method = 'GET';

        $response = $this->controller->showAcceptInvitePage($request, []);

        self::assertSame(404, $response->statusCode);
    }
}