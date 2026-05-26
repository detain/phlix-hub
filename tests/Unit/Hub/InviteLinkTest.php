<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\Hub\InviteLink;

/**
 * Unit tests for {@see InviteLink}.
 *
 * @package Phlix\Hub\Tests\Unit\Hub
 *
 * @covers \Phlix\Hub\Hub\InviteLink
 */
final class InviteLinkTest extends TestCase
{
    public function testIsExpiredReturnsFalseWhenNoExpiry(): void
    {
        $link = new InviteLink(
            id: 'link-1',
            ownerUserId: 'owner-1',
            serverId: 'server-1',
            libraryId: 'lib-1',
            permission: 'read',
            maxUses: 1,
            useCount: 0,
            expiresAt: null,
            createdAt: time(),
            url: 'https://hub.example.com/invite/token123',
        );

        self::assertFalse($link->isExpired());
    }

    public function testIsExpiredReturnsTrueWhenExpired(): void
    {
        $link = new InviteLink(
            id: 'link-1',
            ownerUserId: 'owner-1',
            serverId: 'server-1',
            libraryId: 'lib-1',
            permission: 'read',
            maxUses: 1,
            useCount: 0,
            expiresAt: time() - 3600,
            createdAt: time() - 7200,
            url: 'https://hub.example.com/invite/token123',
        );

        self::assertTrue($link->isExpired());
    }

    public function testIsExpiredReturnsFalseWhenNotYetExpired(): void
    {
        $link = new InviteLink(
            id: 'link-1',
            ownerUserId: 'owner-1',
            serverId: 'server-1',
            libraryId: 'lib-1',
            permission: 'read',
            maxUses: 1,
            useCount: 0,
            expiresAt: time() + 3600,
            createdAt: time(),
            url: 'https://hub.example.com/invite/token123',
        );

        self::assertFalse($link->isExpired());
    }

    public function testIsExhaustedReturnsFalseWhenNotExhausted(): void
    {
        $link = new InviteLink(
            id: 'link-1',
            ownerUserId: 'owner-1',
            serverId: 'server-1',
            libraryId: 'lib-1',
            permission: 'read',
            maxUses: 5,
            useCount: 2,
            expiresAt: null,
            createdAt: time(),
            url: 'https://hub.example.com/invite/token123',
        );

        self::assertFalse($link->isExhausted());
    }

    public function testIsExhaustedReturnsTrueWhenMaxUsesReached(): void
    {
        $link = new InviteLink(
            id: 'link-1',
            ownerUserId: 'owner-1',
            serverId: 'server-1',
            libraryId: 'lib-1',
            permission: 'read',
            maxUses: 3,
            useCount: 3,
            expiresAt: null,
            createdAt: time(),
            url: 'https://hub.example.com/invite/token123',
        );

        self::assertTrue($link->isExhausted());
    }

    public function testCanUseReturnsTrueWhenValid(): void
    {
        $link = new InviteLink(
            id: 'link-1',
            ownerUserId: 'owner-1',
            serverId: 'server-1',
            libraryId: 'lib-1',
            permission: 'read',
            maxUses: 5,
            useCount: 2,
            expiresAt: time() + 3600,
            createdAt: time(),
            url: 'https://hub.example.com/invite/token123',
        );

        self::assertTrue($link->canUse());
    }

    public function testCanUseReturnsFalseWhenExpired(): void
    {
        $link = new InviteLink(
            id: 'link-1',
            ownerUserId: 'owner-1',
            serverId: 'server-1',
            libraryId: 'lib-1',
            permission: 'read',
            maxUses: 5,
            useCount: 2,
            expiresAt: time() - 3600,
            createdAt: time() - 7200,
            url: 'https://hub.example.com/invite/token123',
        );

        self::assertFalse($link->canUse());
    }

    public function testCanUseReturnsFalseWhenExhausted(): void
    {
        $link = new InviteLink(
            id: 'link-1',
            ownerUserId: 'owner-1',
            serverId: 'server-1',
            libraryId: 'lib-1',
            permission: 'read',
            maxUses: 3,
            useCount: 3,
            expiresAt: time() + 3600,
            createdAt: time(),
            url: 'https://hub.example.com/invite/token123',
        );

        self::assertFalse($link->canUse());
    }

    public function testIsForAllLibrariesReturnsTrueWhenLibraryIdNull(): void
    {
        $link = new InviteLink(
            id: 'link-1',
            ownerUserId: 'owner-1',
            serverId: 'server-1',
            libraryId: null,
            permission: 'read',
            maxUses: 5,
            useCount: 0,
            expiresAt: null,
            createdAt: time(),
            url: 'https://hub.example.com/invite/token123',
        );

        self::assertTrue($link->isForAllLibraries());
    }

    public function testIsForAllLibrariesReturnsFalseWhenLibraryIdSet(): void
    {
        $link = new InviteLink(
            id: 'link-1',
            ownerUserId: 'owner-1',
            serverId: 'server-1',
            libraryId: 'lib-1',
            permission: 'read',
            maxUses: 5,
            useCount: 0,
            expiresAt: null,
            createdAt: time(),
            url: 'https://hub.example.com/invite/token123',
        );

        self::assertFalse($link->isForAllLibraries());
    }

    public function testToPayloadReturnsCorrectStructure(): void
    {
        $createdAt = time();
        $expiresAt = time() + 86400;
        $link = new InviteLink(
            id: 'link-1',
            ownerUserId: 'owner-1',
            serverId: 'server-1',
            libraryId: 'lib-1',
            permission: 'readwrite',
            maxUses: 5,
            useCount: 2,
            expiresAt: $expiresAt,
            createdAt: $createdAt,
            url: 'https://hub.example.com/invite/token123',
        );

        $payload = $link->toPayload();

        self::assertSame('link-1', $payload['id']);
        self::assertSame('owner-1', $payload['owner_user_id']);
        self::assertSame('server-1', $payload['server_id']);
        self::assertSame('lib-1', $payload['library_id']);
        self::assertSame('readwrite', $payload['permission']);
        self::assertSame(5, $payload['max_uses']);
        self::assertSame(2, $payload['use_count']);
        self::assertSame($expiresAt, $payload['expires_at']);
        self::assertSame($createdAt, $payload['created_at']);
        self::assertSame('https://hub.example.com/invite/token123', $payload['url']);
    }

    public function testFromRowCreatesCorrectInstance(): void
    {
        $row = [
            'id' => 'link-1',
            'owner_user_id' => 'owner-1',
            'server_id' => 'server-1',
            'library_id' => 'lib-1',
            'permission' => 'read',
            'max_uses' => 5,
            'use_count' => 2,
            'expires_at' => 1700086400,
            'created_at' => 1700000000,
        ];

        $link = InviteLink::fromRow($row, 'https://hub.example.com/invite/token123');

        self::assertSame('link-1', $link->id);
        self::assertSame('owner-1', $link->ownerUserId);
        self::assertSame('server-1', $link->serverId);
        self::assertSame('lib-1', $link->libraryId);
        self::assertSame('read', $link->permission);
        self::assertSame(5, $link->maxUses);
        self::assertSame(2, $link->useCount);
        self::assertSame(1700086400, $link->expiresAt);
        self::assertSame(1700000000, $link->createdAt);
        self::assertSame('https://hub.example.com/invite/token123', $link->url);
    }

    public function testFromRowHandlesNullLibraryId(): void
    {
        $row = [
            'id' => 'link-1',
            'owner_user_id' => 'owner-1',
            'server_id' => 'server-1',
            'library_id' => null,
            'permission' => 'read',
            'max_uses' => 1,
            'use_count' => 0,
            'expires_at' => null,
            'created_at' => 1700000000,
        ];

        $link = InviteLink::fromRow($row, 'https://hub.example.com/invite/token123');

        self::assertNull($link->libraryId);
        self::assertTrue($link->isForAllLibraries());
    }
}