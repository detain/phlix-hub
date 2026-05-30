<?php

declare(strict_types=1);

namespace Phlix\Hub\Federation;

/**
 * DTO representing a federation library share.
 *
 * @package Phlix\Hub\Federation
 */
final readonly class FederationLibraryShareDto
{
    public function __construct(
        public string $id,
        public string $libraryId,
        public string $libraryName,
        public string $peerId,
        public string $permission, // 'read'|'readwrite'
        public string $status, // 'pending'|'active'|'revoked'
        public string $sharedAt,
        public ?string $revokedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        /** @var mixed $rawId */
        $rawId = $row['id'] ?? null;
        /** @var mixed $rawLibraryId */
        $rawLibraryId = $row['library_id'] ?? null;
        /** @var mixed $rawLibraryName */
        $rawLibraryName = $row['library_name'] ?? null;
        /** @var mixed $rawPeerId */
        $rawPeerId = $row['peer_id'] ?? null;
        /** @var mixed $rawPermission */
        $rawPermission = $row['permission'] ?? null;
        /** @var mixed $rawStatus */
        $rawStatus = $row['status'] ?? null;
        /** @var mixed $rawSharedAt */
        $rawSharedAt = $row['shared_at'] ?? null;

        return new self(
            id: is_string($rawId) ? $rawId : '',
            libraryId: is_string($rawLibraryId) ? $rawLibraryId : '',
            libraryName: is_string($rawLibraryName) ? $rawLibraryName : '',
            peerId: is_string($rawPeerId) ? $rawPeerId : '',
            permission: is_string($rawPermission) ? $rawPermission : 'read',
            status: is_string($rawStatus) ? $rawStatus : 'pending',
            sharedAt: is_string($rawSharedAt) ? $rawSharedAt : '',
            revokedAt: is_string($row['revoked_at'] ?? null) ? $row['revoked_at'] : null,
        );
    }
}
