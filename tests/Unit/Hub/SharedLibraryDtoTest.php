<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use Phlix\Hub\Tests\Support\DecodedJsonAssertions;
use PHPUnit\Framework\TestCase;
use Phlix\Hub\Hub\LibraryShare;
use Phlix\Hub\Hub\SharedLibraryDto;

/**
 * Unit tests for {@see SharedLibraryDto}.
 *
 * @package Phlix\Hub\Tests\Unit\Hub
 */
final class SharedLibraryDtoTest extends TestCase
{
    use DecodedJsonAssertions;

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
            createdAt: 1699999999,
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
        self::assertCount(2, self::arrayNode($payload['access_urls']));
        self::assertSame(1700086400, $payload['expires_at']);
        // The SPA's "Received" column reads `created_at`; it must be on the wire as
        // UNIX seconds (same encoding as `expires_at`), not omitted.
        self::assertArrayHasKey('created_at', $payload);
        self::assertSame(1699999999, $payload['created_at']);
    }

    public function testToPayloadEmitsNullCreatedAtWhenUnknown(): void
    {
        $dto = new SharedLibraryDto(
            shareId: 'share-1',
            ownerUserId: 'owner-1',
            ownerName: 'Owner User',
            serverId: 'server-1',
            serverName: 'My Server',
            libraryId: 'lib-1',
            libraryName: 'My Movies',
            libraryItemCount: 0,
            permissionLevel: LibraryShare::PERMISSION_READ,
            accessUrls: [],
        );

        $payload = $dto->toPayload();

        self::assertArrayHasKey('created_at', $payload);
        self::assertNull($payload['created_at']);
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
            createdAt: 1700000000,
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
        self::assertSame(1700000000, $dto->createdAt);
    }
}
