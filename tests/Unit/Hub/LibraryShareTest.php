<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\Hub\LibraryShare;

/**
 * Unit tests for {@see LibraryShare}.
 *
 * @package Phlix\Hub\Tests\Unit\Hub
 *
 * @covers \Phlix\Hub\Hub\LibraryShare
 */
final class LibraryShareTest extends TestCase
{
    public function testIsExpiredReturnsFalseWhenNoExpiry(): void
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

        self::assertFalse($share->isExpired());
    }

    public function testIsExpiredReturnsTrueWhenExpired(): void
    {
        $share = new LibraryShare(
            id: 'share-1',
            ownerUserId: 'owner-1',
            collaboratorUserId: 'collab-1',
            serverId: 'server-1',
            libraryId: 'lib-1',
            libraryName: 'My Movies',
            permissionLevel: LibraryShare::PERMISSION_READ,
            createdAt: time() - 3600,
            expiresAt: time() - 1800,
        );

        self::assertTrue($share->isExpired());
    }

    public function testIsExpiredReturnsFalseWhenNotYetExpired(): void
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
            expiresAt: time() + 3600,
        );

        self::assertFalse($share->isExpired());
    }

    public function testIsRevokedReturnsFalseWhenNotRevoked(): void
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

        self::assertFalse($share->isRevoked());
    }

    public function testIsRevokedReturnsTrueWhenRevoked(): void
    {
        $share = new LibraryShare(
            id: 'share-1',
            ownerUserId: 'owner-1',
            collaboratorUserId: 'collab-1',
            serverId: 'server-1',
            libraryId: 'lib-1',
            libraryName: 'My Movies',
            permissionLevel: LibraryShare::PERMISSION_READ,
            createdAt: time() - 3600,
            revokedAt: time(),
        );

        self::assertTrue($share->isRevoked());
    }

    public function testIsActiveReturnsTrueWhenNotExpiredAndNotRevoked(): void
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
            expiresAt: time() + 3600,
        );

        self::assertTrue($share->isActive());
    }

    public function testIsActiveReturnsFalseWhenExpired(): void
    {
        $share = new LibraryShare(
            id: 'share-1',
            ownerUserId: 'owner-1',
            collaboratorUserId: 'collab-1',
            serverId: 'server-1',
            libraryId: 'lib-1',
            libraryName: 'My Movies',
            permissionLevel: LibraryShare::PERMISSION_READ,
            createdAt: time() - 7200,
            expiresAt: time() - 3600,
        );

        self::assertFalse($share->isActive());
    }

    public function testIsActiveReturnsFalseWhenRevoked(): void
    {
        $share = new LibraryShare(
            id: 'share-1',
            ownerUserId: 'owner-1',
            collaboratorUserId: 'collab-1',
            serverId: 'server-1',
            libraryId: 'lib-1',
            libraryName: 'My Movies',
            permissionLevel: LibraryShare::PERMISSION_READ,
            createdAt: time() - 3600,
            revokedAt: time(),
        );

        self::assertFalse($share->isActive());
    }

    public function testCanWriteReturnsTrueForReadwrite(): void
    {
        $share = new LibraryShare(
            id: 'share-1',
            ownerUserId: 'owner-1',
            collaboratorUserId: 'collab-1',
            serverId: 'server-1',
            libraryId: 'lib-1',
            libraryName: 'My Movies',
            permissionLevel: LibraryShare::PERMISSION_READWRITE,
            createdAt: time(),
        );

        self::assertTrue($share->canWrite());
    }

    public function testCanWriteReturnsFalseForRead(): void
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

        self::assertFalse($share->canWrite());
    }

    public function testToPayloadReturnsCorrectStructure(): void
    {
        $createdAt = time();
        $expiresAt = time() + 86400;
        $share = new LibraryShare(
            id: 'share-1',
            ownerUserId: 'owner-1',
            collaboratorUserId: 'collab-1',
            serverId: 'server-1',
            libraryId: 'lib-1',
            libraryName: 'My Movies',
            permissionLevel: LibraryShare::PERMISSION_READ,
            createdAt: $createdAt,
            expiresAt: $expiresAt,
        );

        $payload = $share->toPayload();

        self::assertSame('share-1', $payload['id']);
        self::assertSame('owner-1', $payload['owner_user_id']);
        self::assertSame('collab-1', $payload['collaborator_user_id']);
        self::assertSame('server-1', $payload['server_id']);
        self::assertSame('lib-1', $payload['library_id']);
        self::assertSame('My Movies', $payload['library_name']);
        self::assertSame('read', $payload['permission_level']);
        self::assertSame($createdAt, $payload['created_at']);
        self::assertSame($expiresAt, $payload['expires_at']);
        self::assertNull($payload['revoked_at']);
    }

    public function testFromRowCreatesCorrectInstance(): void
    {
        $row = [
            'id' => 'share-1',
            'owner_user_id' => 'owner-1',
            'collaborator_user_id' => 'collab-1',
            'server_id' => 'server-1',
            'library_id' => 'lib-1',
            'library_name' => 'My Movies',
            'permission_level' => 'readwrite',
            'created_at' => 1700000000,
            'expires_at' => 1700086400,
            'revoked_at' => null,
        ];

        $share = LibraryShare::fromRow($row);

        self::assertSame('share-1', $share->id);
        self::assertSame('owner-1', $share->ownerUserId);
        self::assertSame('collab-1', $share->collaboratorUserId);
        self::assertSame('server-1', $share->serverId);
        self::assertSame('lib-1', $share->libraryId);
        self::assertSame('My Movies', $share->libraryName);
        self::assertSame('readwrite', $share->permissionLevel);
        self::assertSame(1700000000, $share->createdAt);
        self::assertSame(1700086400, $share->expiresAt);
        self::assertNull($share->revokedAt);
        self::assertTrue($share->canWrite());
    }
}
