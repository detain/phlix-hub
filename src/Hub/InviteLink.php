<?php

declare(strict_types=1);

namespace Phlix\Hub\Hub;

/**
 * DTO representing an invite link for library sharing.
 *
 * @package Phlix\Hub\Hub
 */
final class InviteLink
{
    public const SCOPE_ALL_LIBRARIES = 'all';

    /**
     * @param string      $id               Invite link UUID.
     * @param string      $ownerUserId       Owner's user UUID.
     * @param string      $serverId         Server UUID.
     * @param string|null $libraryId        Library UUID on the server, or null for all libraries.
     * @param string      $permission       Permission level (read or readwrite).
     * @param int         $maxUses         Maximum number of uses.
     * @param int         $useCount       Current use count.
     * @param int|null    $expiresAt       UNIX timestamp when the link expires, or null.
     * @param int         $createdAt       UNIX timestamp when the link was created.
     * @param string      $url             Full invite URL.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $ownerUserId,
        public readonly string $serverId,
        public readonly ?string $libraryId,
        public readonly string $permission,
        public readonly int $maxUses,
        public readonly int $useCount,
        public readonly ?int $expiresAt,
        public readonly int $createdAt,
        public readonly string $url,
    ) {
    }

    /**
     * Returns true when the invite link has expired.
     */
    public function isExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }
        return time() > $this->expiresAt;
    }

    /**
     * Returns true when the invite link has been exhausted (max uses reached).
     */
    public function isExhausted(): bool
    {
        return $this->useCount >= $this->maxUses;
    }

    /**
     * Returns true when the invite link can still be used (not expired and not exhausted).
     */
    public function canUse(): bool
    {
        return !$this->isExpired() && !$this->isExhausted();
    }

    /**
     * Returns true when the link grants access to all libraries (not a specific library).
     */
    public function isForAllLibraries(): bool
    {
        return $this->libraryId === null;
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
            'server_id' => $this->serverId,
            'library_id' => $this->libraryId,
            'permission' => $this->permission,
            'max_uses' => $this->maxUses,
            'use_count' => $this->useCount,
            'expires_at' => $this->expiresAt,
            'created_at' => $this->createdAt,
            'url' => $this->url,
        ];
    }

    /**
     * Create from a database row.
     *
     * @param array<string, mixed> $row
     * @param string $url Full invite URL to include in the DTO.
     */
    public static function fromRow(array $row, string $url): self
    {
        $expiresAt = null;
        if (isset($row['expires_at']) && is_numeric($row['expires_at'])) {
            $expiresAt = (int) $row['expires_at'];
        }

        /** @var mixed $rawId */
        $rawId = $row['id'] ?? null;
        /** @var mixed $rawOwner */
        $rawOwner = $row['owner_user_id'] ?? null;
        /** @var mixed $rawServer */
        $rawServer = $row['server_id'] ?? null;
        /** @var mixed $rawLibrary */
        $rawLibrary = $row['library_id'] ?? null;
        /** @var mixed $rawPermission */
        $rawPermission = $row['permission'] ?? null;

        return new self(
            id: is_string($rawId) ? $rawId : '',
            ownerUserId: is_string($rawOwner) ? $rawOwner : '',
            serverId: is_string($rawServer) ? $rawServer : '',
            libraryId: is_string($rawLibrary) && $rawLibrary !== '' ? $rawLibrary : null,
            permission: is_string($rawPermission) ? $rawPermission : 'read',
            maxUses: is_numeric($row['max_uses'] ?? null) ? (int) $row['max_uses'] : 1,
            useCount: is_numeric($row['use_count'] ?? null) ? (int) $row['use_count'] : 0,
            expiresAt: $expiresAt,
            createdAt: is_numeric($row['created_at'] ?? null) ? (int) $row['created_at'] : 0,
            url: $url,
        );
    }
}
