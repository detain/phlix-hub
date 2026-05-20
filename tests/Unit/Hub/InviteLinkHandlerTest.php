<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Phlix\Hub\Auth\JwtClaims;
use Phlix\Hub\Auth\JwtHandler;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Hub\InviteLink;
use Phlix\Hub\Hub\InviteLinkHandler;
use Phlix\Hub\Hub\LibraryShare;
use Phlix\Hub\Hub\LibrarySharingHandler;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see InviteLinkHandler}.
 *
 * @package Phlix\Hub\Tests\Unit\Hub
 * @since 0.6.0
 *
 * @covers \Phlix\Hub\Hub\InviteLinkHandler
 */
final class InviteLinkHandlerTest extends TestCase
{
    private Connection $db;
    private JwtHandler $jwtHandler;
    private LibrarySharingHandler $sharingHandler;
    private StructuredLogger $logger;
    private InviteLinkHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->createMock(Connection::class);
        $this->jwtHandler = new JwtHandler('test-secret-key-that-is-at-least-32-bytes-long');
        $this->sharingHandler = $this->createMock(LibrarySharingHandler::class);
        $this->logger = $this->createMock(StructuredLogger::class);

        $this->handler = new InviteLinkHandler(
            $this->db,
            $this->jwtHandler,
            $this->sharingHandler,
            $this->logger,
            'http://localhost:8800',
        );
    }

    public function testCreateInviteLinkSuccess(): void
    {
        $this->sharingHandler->method('isServerOwnedByUser')->willReturn(true);

        $this->db->method('query')->willReturnCallback(function (string $sql) {
            if (str_contains($sql, 'INSERT INTO invite_links')) {
                return [];
            }
            return [];
        });

        $link = $this->handler->createInviteLink(
            ownerId: 'owner-1',
            serverId: 'server-1',
            libraryId: 'lib-1',
            permission: 'read',
            maxUses: 1,
            expiresAt: null,
        );

        self::assertSame('owner-1', $link->ownerUserId);
        self::assertSame('server-1', $link->serverId);
        self::assertSame('lib-1', $link->libraryId);
        self::assertSame('read', $link->permission);
        self::assertSame(1, $link->maxUses);
        self::assertSame(0, $link->useCount);
        self::assertStringContainsString('http://localhost:8800/invite/', $link->url);
    }

    public function testCreateInviteLinkNotOwnerThrows(): void
    {
        $this->sharingHandler->method('isServerOwnedByUser')->willReturn(false);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(403);

        $this->handler->createInviteLink(
            ownerId: 'owner-1',
            serverId: 'server-1',
            libraryId: 'lib-1',
        );
    }

    public function testCreateInviteLinkInvalidPermissionThrows(): void
    {
        $this->sharingHandler->method('isServerOwnedByUser')->willReturn(true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(400);

        $this->handler->createInviteLink(
            ownerId: 'owner-1',
            serverId: 'server-1',
            libraryId: 'lib-1',
            permission: 'invalid',
        );
    }

    public function testCreateInviteLinkWithAllLibraries(): void
    {
        $this->sharingHandler->method('isServerOwnedByUser')->willReturn(true);

        $this->db->method('query')->willReturn([]);

        $link = $this->handler->createInviteLink(
            ownerId: 'owner-1',
            serverId: 'server-1',
            libraryId: null,
            permission: 'read',
            maxUses: 5,
        );

        self::assertNull($link->libraryId);
        self::assertTrue($link->isForAllLibraries());
        self::assertSame(5, $link->maxUses);
    }

    public function testListForOwnerReturnsEmptyArray(): void
    {
        $this->db->method('query')->willReturn([]);

        $result = $this->handler->listForOwner('owner-1');

        self::assertCount(0, $result);
    }

    public function testListForOwnerReturnsLinks(): void
    {
        $rows = [
            [
                'id' => 'link-1',
                'owner_user_id' => 'owner-1',
                'server_id' => 'server-1',
                'library_id' => 'lib-1',
                'permission' => 'read',
                'max_uses' => 5,
                'use_count' => 2,
                'expires_at' => null,
                'created_at' => time(),
                'token_hash' => 'abc123',
            ],
        ];

        $this->db->method('query')->willReturn($rows);

        $result = $this->handler->listForOwner('owner-1');

        self::assertCount(1, $result);
        self::assertSame('link-1', $result[0]->id);
    }

    public function testRevokeInviteLinkSuccess(): void
    {
        $row = [
            'id' => 'link-1',
            'owner_user_id' => 'owner-1',
            'server_id' => 'server-1',
            'library_id' => 'lib-1',
            'permission' => 'read',
            'max_uses' => 5,
            'use_count' => 2,
            'expires_at' => null,
            'created_at' => time(),
            'token_hash' => 'abc123',
        ];

        $this->db->method('query')->willReturnCallback(function (string $sql) use ($row) {
            if (str_contains($sql, 'SELECT * FROM invite_links')) {
                return [$row];
            }
            return [];
        });

        $this->handler->revokeInviteLink('owner-1', 'link-1');
        self::assertTrue(true);
    }

    public function testRevokeInviteLinkNotFoundThrows(): void
    {
        $this->db->method('query')->willReturn([]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(404);

        $this->handler->revokeInviteLink('owner-1', 'nonexistent');
    }

    public function testRevokeInviteLinkNotOwnerThrows(): void
    {
        $row = [
            'id' => 'link-1',
            'owner_user_id' => 'other-owner',
            'server_id' => 'server-1',
            'library_id' => 'lib-1',
            'permission' => 'read',
            'max_uses' => 5,
            'use_count' => 2,
            'expires_at' => null,
            'created_at' => time(),
            'token_hash' => 'abc123',
        ];

        $this->db->method('query')->willReturnCallback(function (string $sql) use ($row) {
            if (str_contains($sql, 'SELECT * FROM invite_links')) {
                return [$row];
            }
            return [];
        });

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(403);

        $this->handler->revokeInviteLink('owner-1', 'link-1');
    }
}