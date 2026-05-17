<?php

declare(strict_types=1);

namespace Phlex\Hub\Tests\unit\Hub;

use PHPUnit\Framework\TestCase;
use Phlex\Hub\Hub\LibraryShare;
use Phlex\Hub\Hub\SharedLibraryDto;

/**
 * Unit tests for {@see SharedLibraryDto}.
 *
 * @package Phlex\Hub\Tests\unit\Hub
 * @since 0.5.0
 *
 * @covers \Phlex\Hub\Hub\SharedLibraryDto
 */
final class SharedLibraryDtoTest extends TestCase
{
    public function testCanWriteReturnsTrueForReadwrite(): void
    {
        $dto = new SharedLibraryDto(
            shareId: 'share-1',
            ownerUserId: 'owner-1',
            ownerName: 'Owner User',
            serverId: 'server-1',
            serverName: 'My Server',
            libraryId: 'lib-1',
            libraryName: 'My Movies',
            libraryItemCount: 42,
            permissionLevel: LibraryShare::PERMISSION_READWRITE,
            accessUrls: ['https://server.example.com'],
        );

        self::assertTrue($dto->canWrite());
    }

    public function testCanWriteReturnsFalseForRead(): void
    {
        $dto = new SharedLibraryDto(
            shareId: 'share-1',
            ownerUserId: 'owner-1',
            ownerName: 'Owner User',
            serverId: 'server-1',
            serverName: 'My Server',
            libraryId: 'lib-1',
            libraryName: 'My Movies',
            libraryItemCount: 42,
            permissionLevel: LibraryShare::PERMISSION_READ,
            accessUrls: ['https://server.example.com'],
        );

        self::assertFalse($dto->canWrite());
    }

    public function testToPayloadReturnsCorrectStructure(): void
    {
        $dto = new SharedLibraryDto(
            shareId: 'share-1',
            ownerUserId: 'owner-1',
            ownerName: 'Owner User',
            serverId: 'server-1',
            serverName: 'My Server',
            libraryId: 'lib-1',
            libraryName: 'My Movies',
            libraryItemCount: 42,
            permissionLevel: LibraryShare::PERMISSION_READ,
            accessUrls: ['https://server.example.com', 'https://192.168.1.100:32400'],
            expiresAt: 1700086400,
        );

        $payload = $dto->toPayload();

        self::assertSame('share-1', $payload['share_id']);
        self::assertSame('owner-1', $payload['owner_user_id']);
        self::assertSame('Owner User', $payload['owner_name']);
        self::assertSame('server-1', $payload['server_id']);
        self::assertSame('My Server', $payload['server_name']);
        self::assertSame('lib-1', $payload['library_id']);
        self::assertSame('My Movies', $payload['library_name']);
        self::assertSame(42, $payload['library_item_count']);
        self::assertSame('read', $payload['permission_level']);
        self::assertCount(2, $payload['access_urls']);
        self::assertSame(1700086400, $payload['expires_at']);
    }

    public function testConstructorSetsAllProperties(): void
    {
        $dto = new SharedLibraryDto(
            shareId: 'share-1',
            ownerUserId: 'owner-1',
            ownerName: 'Owner User',
            serverId: 'server-1',
            serverName: 'My Server',
            libraryId: 'lib-1',
            libraryName: 'My Movies',
            libraryItemCount: 100,
            permissionLevel: LibraryShare::PERMISSION_READWRITE,
            accessUrls: ['https://example.com'],
            expiresAt: null,
        );

        self::assertSame('share-1', $dto->shareId);
        self::assertSame('owner-1', $dto->ownerUserId);
        self::assertSame('Owner User', $dto->ownerName);
        self::assertSame('server-1', $dto->serverId);
        self::assertSame('My Server', $dto->serverName);
        self::assertSame('lib-1', $dto->libraryId);
        self::assertSame('My Movies', $dto->libraryName);
        self::assertSame(100, $dto->libraryItemCount);
        self::assertSame('readwrite', $dto->permissionLevel);
        self::assertSame(['https://example.com'], $dto->accessUrls);
        self::assertNull($dto->expiresAt);
    }
}
