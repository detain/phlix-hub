<?php

declare(strict_types=1);

namespace Phlix\Hub\Hub;

/**
 * DTO representing a library shared with the current user.
 *
 * This is what a collaborator sees when browsing "Shared with me".
 *
 * @package Phlix\Hub\Hub
 * @since 0.5.0
 */
final class SharedLibraryDto
{
    /**
     * @param string      $shareId          Share UUID.
     * @param string      $ownerUserId       Owner's user UUID.
     * @param string      $ownerName         Owner's display name.
     * @param string      $serverId         Server UUID.
     * @param string      $serverName       Human-readable server name.
     * @param string      $libraryId        Library UUID on the server.
     * @param string      $libraryName      Human-readable library name.
     * @param int         $libraryItemCount Approximate item count in library.
     * @param string      $permissionLevel  Permission level (read or readwrite).
     * @param array<int, string> $accessUrls  URLs to access the library (direct + relay).
     * @param int|null    $expiresAt         Unix timestamp when share expires, or null.
     */
    public function __construct(
        public readonly string $shareId,
        public readonly string $ownerUserId,
        public readonly string $ownerName,
        public readonly string $serverId,
        public readonly string $serverName,
        public readonly string $libraryId,
        public readonly string $libraryName,
        public readonly int $libraryItemCount,
        public readonly string $permissionLevel,
        public readonly array $accessUrls,
        public readonly ?int $expiresAt = null,
    ) {
    }

    /**
     * Returns true when the share grants write permission.
     */
    public function canWrite(): bool
    {
        return $this->permissionLevel === LibraryShare::PERMISSION_READWRITE;
    }

    /**
     * Convert to array for JSON serialization.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'share_id' => $this->shareId,
            'owner_user_id' => $this->ownerUserId,
            'owner_name' => $this->ownerName,
            'server_id' => $this->serverId,
            'server_name' => $this->serverName,
            'library_id' => $this->libraryId,
            'library_name' => $this->libraryName,
            'library_item_count' => $this->libraryItemCount,
            'permission_level' => $this->permissionLevel,
            'access_urls' => $this->accessUrls,
            'expires_at' => $this->expiresAt,
        ];
    }
}
