<?php

declare(strict_types=1);

namespace Phlix\Hub\Federation;

/**
 * DTO representing an incoming library share offer from a peer.
 *
 * @package Phlix\Hub\Federation
 */
final readonly class FederationIncomingOfferDto
{
    public function __construct(
        public string $id,
        public string $peerId,
        public string $libraryId,
        public string $libraryName,
        public string $permission,
        public string $status, // 'pending'|'accepted'|'rejected'
        public string $offeredAt,
        public ?string $respondedAt,
        public ?string $acceptedBy,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        /** @var mixed $rawId */
        $rawId = $row['id'] ?? null;
        /** @var mixed $rawPeerId */
        $rawPeerId = $row['peer_id'] ?? null;
        /** @var mixed $rawLibraryId */
        $rawLibraryId = $row['library_id'] ?? null;
        /** @var mixed $rawLibraryName */
        $rawLibraryName = $row['library_name'] ?? null;
        /** @var mixed $rawPermission */
        $rawPermission = $row['permission'] ?? null;
        /** @var mixed $rawStatus */
        $rawStatus = $row['status'] ?? null;
        /** @var mixed $rawOfferedAt */
        $rawOfferedAt = $row['offered_at'] ?? null;

        return new self(
            id: is_string($rawId) ? $rawId : '',
            peerId: is_string($rawPeerId) ? $rawPeerId : '',
            libraryId: is_string($rawLibraryId) ? $rawLibraryId : '',
            libraryName: is_string($rawLibraryName) ? $rawLibraryName : '',
            permission: is_string($rawPermission) ? $rawPermission : 'read',
            status: is_string($rawStatus) ? $rawStatus : 'pending',
            offeredAt: is_string($rawOfferedAt) ? $rawOfferedAt : '',
            respondedAt: is_string($row['responded_at'] ?? null) ? $row['responded_at'] : null,
            acceptedBy: is_string($row['accepted_by'] ?? null) ? $row['accepted_by'] : null,
        );
    }
}
