<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Federation;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\Federation\FederationLibraryShareDto;

/**
 * Unit tests for {@see FederationLibraryShareDto}.
 *
 * @package Phlix\Hub\Tests\Unit\Federation
 */
final class FederationLibraryShareDtoTest extends TestCase
{
    public function testFromRowCreatesDtoFromDatabaseRow(): void
    {
        $row = [
            'id' => 'share-123',
            'library_id' => 'lib-456',
            'library_name' => 'My Movies',
            'peer_id' => 'peer-789',
            'permission' => 'read',
            'status' => 'active',
            'shared_at' => '2026-01-01 12:00:00',
            'revoked_at' => null,
        ];

        $dto = FederationLibraryShareDto::fromRow($row);

        self::assertSame('share-123', $dto->id);
        self::assertSame('lib-456', $dto->libraryId);
        self::assertSame('My Movies', $dto->libraryName);
        self::assertSame('peer-789', $dto->peerId);
        self::assertSame('read', $dto->permission);
        self::assertSame('active', $dto->status);
        self::assertSame('2026-01-01 12:00:00', $dto->sharedAt);
        self::assertNull($dto->revokedAt);
    }

    public function testFromRowHandlesReadwritePermission(): void
    {
        $row = [
            'id' => 'share-rw',
            'library_id' => 'lib-1',
            'library_name' => 'Writable Library',
            'peer_id' => 'peer-1',
            'permission' => 'readwrite',
            'status' => 'active',
            'shared_at' => '2026-01-01 12:00:00',
            'revoked_at' => null,
        ];

        $dto = FederationLibraryShareDto::fromRow($row);

        self::assertSame('readwrite', $dto->permission);
    }

    public function testFromRowHandlesRevokedShare(): void
    {
        $row = [
            'id' => 'share-revoked',
            'library_id' => 'lib-1',
            'library_name' => 'Revoked Library',
            'peer_id' => 'peer-1',
            'permission' => 'read',
            'status' => 'revoked',
            'shared_at' => '2026-01-01 10:00:00',
            'revoked_at' => '2026-01-01 16:00:00',
        ];

        $dto = FederationLibraryShareDto::fromRow($row);

        self::assertSame('revoked', $dto->status);
        self::assertSame('2026-01-01 16:00:00', $dto->revokedAt);
    }

    public function testFromRowHandlesPendingShare(): void
    {
        $row = [
            'id' => 'share-pending',
            'library_id' => 'lib-1',
            'library_name' => 'Pending Library',
            'peer_id' => 'peer-1',
            'permission' => 'read',
            'status' => 'pending',
            'shared_at' => '2026-01-01 12:00:00',
            'revoked_at' => null,
        ];

        $dto = FederationLibraryShareDto::fromRow($row);

        self::assertSame('pending', $dto->status);
        self::assertNull($dto->revokedAt);
    }

    public function testFromRowHandlesMissingFields(): void
    {
        $row = [];

        $dto = FederationLibraryShareDto::fromRow($row);

        self::assertSame('', $dto->id);
        self::assertSame('', $dto->libraryId);
        self::assertSame('', $dto->libraryName);
        self::assertSame('', $dto->peerId);
        self::assertSame('read', $dto->permission);
        self::assertSame('pending', $dto->status);
        self::assertSame('', $dto->sharedAt);
        self::assertNull($dto->revokedAt);
    }

    public function testFromRowKeepsInvalidPermissionAsIs(): void
    {
        $row = [
            'id' => 'share-1',
            'library_id' => 'lib-1',
            'library_name' => 'Library',
            'peer_id' => 'peer-1',
            'permission' => 'invalid-permission',
            'status' => 'active',
            'shared_at' => '2026-01-01 12:00:00',
            'revoked_at' => null,
        ];

        $dto = FederationLibraryShareDto::fromRow($row);

        // fromRow does not validate permission - it keeps the string value as-is
        self::assertSame('invalid-permission', $dto->permission);
    }

    public function testFromRowKeepsInvalidStatusAsIs(): void
    {
        $row = [
            'id' => 'share-1',
            'library_id' => 'lib-1',
            'library_name' => 'Library',
            'peer_id' => 'peer-1',
            'permission' => 'read',
            'status' => 'invalid-status',
            'shared_at' => '2026-01-01 12:00:00',
            'revoked_at' => null,
        ];

        $dto = FederationLibraryShareDto::fromRow($row);

        // fromRow does not validate status - it keeps the string value as-is
        self::assertSame('invalid-status', $dto->status);
    }
}
