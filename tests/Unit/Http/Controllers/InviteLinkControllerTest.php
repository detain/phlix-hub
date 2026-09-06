<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Phlix\Hub\Hub\InviteLink;
use Phlix\Hub\Hub\InviteLinkHandler;
use Phlix\Hub\Hub\LibraryShare;
use Phlix\Hub\Http\Controllers\InviteLinkController;
use Phlix\Hub\Http\Request;

/**
 * Unit tests for {@see InviteLinkController}.
 *
 * @package Phlix\Hub\Tests\Unit\Http\Controllers
 */
final class InviteLinkControllerTest extends TestCase
{
    private InviteLinkHandler&MockObject $handler;
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

    public function testRedeemReturns401WhenNotAuthenticated(): void
    {
        $request = new Request();
        $request->path = '/api/v1/me/invite-links/token123/redeem';
        $request->method = 'POST';

        $response = $this->controller->redeem($request, ['token' => 'token123']);

        self::assertSame(401, $response->statusCode);
    }

    public function testRedeemReturns400WhenTokenMissing(): void
    {
        $request = new Request();
        $request->path = '/api/v1/me/invite-links//redeem';
        $request->method = 'POST';
        $request->userId = 'user-1';

        $response = $this->controller->redeem($request, []);

        self::assertSame(400, $response->statusCode);
    }

    public function testRedeemReturns201OnSuccess(): void
    {
        $share = new LibraryShare(
            id: 'share-1',
            ownerUserId: 'user-owner',
            collaboratorUserId: 'user-1',
            serverId: 'server-1',
            libraryId: 'lib-1',
            libraryName: 'My Library',
            permissionLevel: 'read',
            createdAt: time(),
        );

        $this->handler->expects(self::once())->method('redeemInviteLink')
            ->with('jwt-token-123', 'user-1')
            ->willReturn($share);

        $request = new Request();
        $request->path = '/api/v1/me/invite-links/jwt-token-123/redeem';
        $request->method = 'POST';
        $request->userId = 'user-1';

        $response = $this->controller->redeem($request, ['token' => 'jwt-token-123']);

        self::assertSame(201, $response->statusCode);
        $payload = json_decode($response->body, true);
        self::assertIsArray($payload);
        self::assertSame('share-1', $payload['id']);
        self::assertSame('user-owner', $payload['owner_user_id']);
        self::assertSame('user-1', $payload['collaborator_user_id']);
        self::assertSame('server-1', $payload['server_id']);
        self::assertSame('lib-1', $payload['library_id']);
        self::assertSame('read', $payload['permission_level']);
    }

    public function testRedeemReturns400ForInvalidToken(): void
    {
        $this->handler->method('redeemInviteLink')
            ->willThrowException(new InvalidArgumentException('Invalid or expired invite token', 400));

        $request = new Request();
        $request->path = '/api/v1/me/invite-links/bad-token/redeem';
        $request->method = 'POST';
        $request->userId = 'user-1';

        $response = $this->controller->redeem($request, ['token' => 'bad-token']);

        self::assertSame(400, $response->statusCode);
        $payload = json_decode($response->body, true);
        self::assertIsArray($payload);
        self::assertSame('invalid_invite', $payload['code']);
    }

    public function testRedeemReturns400ForSelfRedemption(): void
    {
        $this->handler->method('redeemInviteLink')
            ->willThrowException(new InvalidArgumentException('Cannot redeem your own invite link', 400));

        $request = new Request();
        $request->path = '/api/v1/me/invite-links/own-token/redeem';
        $request->method = 'POST';
        $request->userId = 'user-1';

        $response = $this->controller->redeem($request, ['token' => 'own-token']);

        self::assertSame(400, $response->statusCode);
        $payload = json_decode($response->body, true);
        self::assertIsArray($payload);
        self::assertSame('invalid_invite', $payload['code']);
    }

    public function testRedeemReturns404WhenLinkNotFound(): void
    {
        $this->handler->method('redeemInviteLink')
            ->willThrowException(new InvalidArgumentException('Invite link not found', 404));

        $request = new Request();
        $request->path = '/api/v1/me/invite-links/nonexistent/redeem';
        $request->method = 'POST';
        $request->userId = 'user-1';

        $response = $this->controller->redeem($request, ['token' => 'nonexistent']);

        self::assertSame(404, $response->statusCode);
        $payload = json_decode($response->body, true);
        self::assertIsArray($payload);
        self::assertSame('invite_link_not_found', $payload['code']);
    }

    public function testRedeemReturns410WhenExpired(): void
    {
        $this->handler->method('redeemInviteLink')
            ->willThrowException(new InvalidArgumentException('Invite link has expired', 410));

        $request = new Request();
        $request->path = '/api/v1/me/invite-links/expired-token/redeem';
        $request->method = 'POST';
        $request->userId = 'user-1';

        $response = $this->controller->redeem($request, ['token' => 'expired-token']);

        self::assertSame(410, $response->statusCode);
        $payload = json_decode($response->body, true);
        self::assertIsArray($payload);
        self::assertSame('invite_expired_or_exhausted', $payload['code']);
    }

    public function testRedeemReturns410WhenExhausted(): void
    {
        $this->handler->method('redeemInviteLink')
            ->willThrowException(new InvalidArgumentException('Invite link has been exhausted', 410));

        $request = new Request();
        $request->path = '/api/v1/me/invite-links/exhausted-token/redeem';
        $request->method = 'POST';
        $request->userId = 'user-1';

        $response = $this->controller->redeem($request, ['token' => 'exhausted-token']);

        self::assertSame(410, $response->statusCode);
        $payload = json_decode($response->body, true);
        self::assertIsArray($payload);
        self::assertSame('invite_expired_or_exhausted', $payload['code']);
    }
}
