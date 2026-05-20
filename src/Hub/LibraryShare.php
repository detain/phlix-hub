<?php

declare(strict_types=1);

namespace Phlix\Hub\Hub;

/**
 * DTO representing a library share record.
 *
 * @package Phlix\Hub\Hub
 * @since 0.5.0
 */
final class LibraryShare
{
    public const PERMISSION_READ = 'read';
    public const PERMISSION_READWRITE = 'readwrite';

    /**
     * @param string      $id               Share UUID.
     * @param string      $ownerUserId       Owner's user UUID.
     * @param string      $collaboratorUserId Collaborator's user UUID.
     * @param string      $serverId         Server UUID.
     * @param string      $libraryId         Library UUID on the server.
     * @param string      $libraryName      Human-readable library name.
     * @param string      $permissionLevel  Permission level (read or readwrite).
     * @param int         $createdAt        Unix timestamp when share was created.
     * @param int|null    $expiresAt       Unix timestamp when share expires, or null.
     * @param int|null    $revokedAt        Unix timestamp when share was revoked, or null.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $ownerUserId,
        public readonly string $collaboratorUserId,
        public readonly string $serverId,
        public readonly string $libraryId,
        public readonly string $libraryName,
        public readonly string $permissionLevel,
        public readonly int $createdAt,
        public readonly ?int $expiresAt = null,
        public readonly ?int $revokedAt = null,
    ) {
    }

    /**
     * Returns true when the share has expired.
     */
    public function isExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }
        return time() > $this->expiresAt;
    }

    /**
     * Returns true when the share has been revoked.
     */
    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    /**
     * Returns true when the share is active (not expired and not revoked).
     */
    public function isActive(): bool
    {
        return !$this->isExpired() && !$this->isRevoked();
    }

    /**
     * Returns true when the share grants write permission.
     */
    public function canWrite(): bool
    {
        return $this->permissionLevel === self::PERMISSION_READWRITE;
    }

    /**
     * Convert to array for JSON serialization.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'owner_user_id' => $this->ownerUserId,
            'collaborator_user_id' => $this->collaboratorUserId,
            'server_id' => $this->serverId,
            'library_id' => $this->libraryId,
            'library_name' => $this->libraryName,
            'permission_level' => $this->permissionLevel,
            'created_at' => $this->createdAt,
            'expires_at' => $this->expiresAt,
            'revoked_at' => $this->revokedAt,
        ];
    }

    /**
     * Create from a database row.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $expiresAt = null;
        if (isset($row['expires_at']) && is_numeric($row['expires_at'])) {
            $expiresAt = (int) $row['expires_at'];
        }

        $revokedAt = null;
        if (isset($row['revoked_at']) && is_numeric($row['revoked_at'])) {
            $revokedAt = (int) $row['revoked_at'];
        }

        return new self(
            id: is_string($row['id'] ?? null) ? $row['id'] : '',
            ownerUserId: is_string($row['owner_user_id'] ?? null) ? $row['owner_user_id'] : '',
            collaboratorUserId: is_string($row['collaborator_user_id'] ?? null) ? $row['collaborator_user_id'] : '',
            serverId: is_string($row['server_id'] ?? null) ? $row['server_id'] : '',
            libraryId: is_string($row['library_id'] ?? null) ? $row['library_id'] : '',
            libraryName: is_string($row['library_name'] ?? null) ? $row['library_name'] : '',
            permissionLevel: is_string($row['permission_level'] ?? null) ? $row['permission_level'] : 'read',
            createdAt: is_numeric($row['created_at'] ?? null) ? (int) $row['created_at'] : 0,
            expiresAt: $expiresAt,
            revokedAt: $revokedAt,
        );
    }
}
