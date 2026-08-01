<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Federation;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\Federation\FederationIncomingOfferDto;

/**
 * Unit tests for {@see FederationIncomingOfferDto}.
 *
 * @package Phlix\Hub\Tests\Unit\Federation
 *
 * @covers \Phlix\Hub\Federation\FederationIncomingOfferDto
 */
final class FederationIncomingOfferDtoTest extends TestCase
{
    public function testFromRowCreatesDtoFromDatabaseRow(): void
    {
        $row = [
            'id' => 'offer-123',
            'peer_id' => 'peer-456',
            'library_id' => 'lib-789',
            'library_name' => 'My Movies',
            'permission' => 'read',
            'status' => 'pending',
            'offered_at' => '2026-01-01 12:00:00',
            'responded_at' => null,
            'accepted_by' => null,
        ];

        $dto = FederationIncomingOfferDto::fromRow($row);

        self::assertSame('offer-123', $dto->id);
        self::assertSame('peer-456', $dto->peerId);
        self::assertSame('lib-789', $dto->libraryId);
        self::assertSame('My Movies', $dto->libraryName);
        self::assertSame('read', $dto->permission);
        self::assertSame('pending', $dto->status);
        self::assertSame('2026-01-01 12:00:00', $dto->offeredAt);
        self::assertNull($dto->respondedAt);
        self::assertNull($dto->acceptedBy);
    }

    public function testFromRowHandlesAcceptedOffer(): void
    {
        $row = [
            'id' => 'offer-abc',
            'peer_id' => 'peer-def',
            'library_id' => 'lib-ghi',
            'library_name' => 'Shared Library',
            'permission' => 'readwrite',
            'status' => 'accepted',
            'offered_at' => '2026-01-01 10:00:00',
            'responded_at' => '2026-01-01 14:00:00',
            'accepted_by' => 'user-123',
        ];

        $dto = FederationIncomingOfferDto::fromRow($row);

        self::assertSame('accepted', $dto->status);
        self::assertSame('2026-01-01 14:00:00', $dto->respondedAt);
        self::assertSame('user-123', $dto->acceptedBy);
        self::assertSame('readwrite', $dto->permission);
    }

    public function testFromRowHandlesRejectedOffer(): void
    {
        $row = [
            'id' => 'offer-rejected',
            'peer_id' => 'peer-x',
            'library_id' => 'lib-y',
            'library_name' => 'Declined Library',
            'permission' => 'read',
            'status' => 'rejected',
            'offered_at' => '2026-01-01 10:00:00',
            'responded_at' => '2026-01-01 15:00:00',
            'accepted_by' => null,
        ];

        $dto = FederationIncomingOfferDto::fromRow($row);

        self::assertSame('rejected', $dto->status);
        self::assertSame('2026-01-01 15:00:00', $dto->respondedAt);
        self::assertNull($dto->acceptedBy);
    }

    public function testFromRowHandlesMissingFields(): void
    {
        $row = [];

        $dto = FederationIncomingOfferDto::fromRow($row);

        self::assertSame('', $dto->id);
        self::assertSame('', $dto->peerId);
        self::assertSame('', $dto->libraryId);
        self::assertSame('', $dto->libraryName);
        self::assertSame('read', $dto->permission);
        self::assertSame('pending', $dto->status);
        self::assertSame('', $dto->offeredAt);
        self::assertNull($dto->respondedAt);
        self::assertNull($dto->acceptedBy);
    }

    public function testFromRowKeepsInvalidPermissionAsIs(): void
    {
        $row = [
            'id' => 'offer-1',
            'peer_id' => 'peer-1',
            'library_id' => 'lib-1',
            'library_name' => 'Library',
            'permission' => 'invalid-permission',
            'status' => 'pending',
            'offered_at' => '2026-01-01 12:00:00',
            'responded_at' => null,
            'accepted_by' => null,
        ];

        $dto = FederationIncomingOfferDto::fromRow($row);

        // fromRow does not validate permission - it keeps the string value as-is
        self::assertSame('invalid-permission', $dto->permission);
    }

    public function testFromRowKeepsInvalidStatusAsIs(): void
    {
        $row = [
            'id' => 'offer-1',
            'peer_id' => 'peer-1',
            'library_id' => 'lib-1',
            'library_name' => 'Library',
            'permission' => 'read',
            'status' => 'invalid-status',
            'offered_at' => '2026-01-01 12:00:00',
            'responded_at' => null,
            'accepted_by' => null,
        ];

        $dto = FederationIncomingOfferDto::fromRow($row);

        // fromRow does not validate status - it keeps the string value as-is
        self::assertSame('invalid-status', $dto->status);
    }
}
