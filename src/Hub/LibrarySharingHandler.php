<?php

declare(strict_types=1);

namespace Phlix\Hub\Hub;

use InvalidArgumentException;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Workerman\MySQL\Connection;

/**
 * Handles library sharing business logic.
 *
 * @package Phlix\Hub\Hub
 * @since 0.5.0
 */
class LibrarySharingHandler
{
    /**
     * @param Connection        $db         MySQL connection.
     * @param UserRepository    $users      User repository for email lookups.
     * @param StructuredLogger  $logger     Application logger.
     */
    public function __construct(
        private readonly Connection $db,
        private readonly UserRepository $users,
        private readonly StructuredLogger $logger,
    ) {
    }

    /**
     * Share a library with another user.
     *
     * @param string      $ownerId          Owner's user UUID.
     * @param string      $collaboratorEmail Email of the user to share with.
     * @param string      $serverId         Server UUID.
     * @param string      $libraryId        Library UUID on the server.
     * @param string      $libraryName      Human-readable library name.
     * @param string      $permission       Permission level (read or readwrite).
     * @param int|null     $expiresAt        Optional Unix timestamp expiry.
     *
     * @return LibraryShare The created share record.
     *
     * @throws InvalidArgumentException When collaborator email not found (404),
     *                                   when owner doesn't own the server (403),
     *                                   or when share already exists (409).
     */
    public function shareLibrary(
        string $ownerId,
        string $collaboratorEmail,
        string $serverId,
        string $libraryId,
        string $libraryName,
        string $permission = LibraryShare::PERMISSION_READ,
        ?int $expiresAt = null,
    ): LibraryShare {
        $collaborator = $this->users->findByEmail($collaboratorEmail);
        if ($collaborator === null) {
            throw new InvalidArgumentException('User not found', 404);
        }

        /** @var string $collaboratorId */
        $collaboratorId = $collaborator['id'];

        if ($collaboratorId === $ownerId) {
            throw new InvalidArgumentException('Cannot share library with yourself', 400);
        }

        if (!$this->isServerOwnedByUser($serverId, $ownerId)) {
            throw new InvalidArgumentException('You do not own this server', 403);
        }

        if (!in_array($permission, [LibraryShare::PERMISSION_READ, LibraryShare::PERMISSION_READWRITE], true)) {
            throw new InvalidArgumentException('Invalid permission level', 400);
        }

        $existingShare = $this->findExistingShare($ownerId, $collaboratorId, $libraryId);
        if ($existingShare !== null && !$existingShare->isRevoked()) {
            throw new InvalidArgumentException('Share already exists', 409);
        }

        $now = time();
        /** @var string $shareId */
        $shareId = $this->generateUuid();

        $this->db->query(
            'INSERT INTO library_shares
                (id, owner_user_id, collaborator_user_id, server_id, library_id, library_name,
                 permission_level, granted_by, created_at, expires_at)
             VALUES
                (:id, :owner_user_id, :collaborator_user_id, :server_id, :library_id, :library_name,
                 :permission_level, :granted_by, :created_at, :expires_at)',
            [
                'id' => $shareId,
                'owner_user_id' => $ownerId,
                'collaborator_user_id' => $collaboratorId,
                'server_id' => $serverId,
                'library_id' => $libraryId,
                'library_name' => $libraryName,
                'permission_level' => $permission,
                'granted_by' => $ownerId,
                'created_at' => $now,
                'expires_at' => $expiresAt,
            ],
        );

        $this->logger->info('Library shared', [
            'share_id' => $shareId,
            'owner_id' => $ownerId,
            'collaborator_id' => $collaboratorId,
            'library_id' => $libraryId,
            'permission' => $permission,
        ]);

        return new LibraryShare(
            id: $shareId,
            ownerUserId: $ownerId,
            collaboratorUserId: $collaboratorId,
            serverId: $serverId,
            libraryId: $libraryId,
            libraryName: $libraryName,
            permissionLevel: $permission,
            createdAt: $now,
            expiresAt: $expiresAt,
        );
    }

    /**
     * Revoke a share.
     *
     * @param string $ownerId Owner of the share (must match the share owner).
     * @param string $shareId Share UUID to revoke.
     *
     * @throws InvalidArgumentException When share not found (404) or not owned by caller (403).
     */
    public function revokeShare(string $ownerId, string $shareId): void
    {
        $share = $this->findShareById($shareId);
        if ($share === null) {
            throw new InvalidArgumentException('Share not found', 404);
        }

        if ($share->ownerUserId !== $ownerId) {
            throw new InvalidArgumentException('You do not own this share', 403);
        }

        if ($share->isRevoked()) {
            return;
        }

        $now = time();
        $this->db->query(
            'UPDATE library_shares SET revoked_at = :revoked_at WHERE id = :id',
            [
                'revoked_at' => $now,
                'id' => $shareId,
            ],
        );

        $this->logger->info('Library share revoked', [
            'share_id' => $shareId,
            'owner_id' => $ownerId,
        ]);
    }

    /**
     * Update the permission level of a share.
     *
     * @param string $ownerId     Owner of the share.
     * @param string $shareId   Share UUID to update.
     * @param string $permission New permission level (read or readwrite).
     *
     * @throws InvalidArgumentException When share not found (404), not owned (403), or invalid permission.
     */
    public function updateSharePermission(string $ownerId, string $shareId, string $permission): void
    {
        if (!in_array($permission, [LibraryShare::PERMISSION_READ, LibraryShare::PERMISSION_READWRITE], true)) {
            throw new InvalidArgumentException('Invalid permission level', 400);
        }

        $share = $this->findShareById($shareId);
        if ($share === null) {
            throw new InvalidArgumentException('Share not found', 404);
        }

        if ($share->ownerUserId !== $ownerId) {
            throw new InvalidArgumentException('You do not own this share', 403);
        }

        $this->db->query(
            'UPDATE library_shares SET permission_level = :permission WHERE id = :id AND revoked_at IS NULL',
            [
                'permission' => $permission,
                'id' => $shareId,
            ],
        );

        $this->logger->info('Library share permission updated', [
            'share_id' => $shareId,
            'owner_id' => $ownerId,
            'new_permission' => $permission,
        ]);
    }

    /**
     * Get all outgoing shares (libraries I've shared with others).
     *
     * @param string $ownerId User UUID.
     *
     * @return array<int, LibraryShare>
     */
    public function getSharesForOwner(string $ownerId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT * FROM library_shares WHERE owner_user_id = :owner_id AND revoked_at IS NULL
             ORDER BY created_at DESC',
            ['owner_id' => $ownerId],
        );

        $shares = [];
        foreach ($rows as $row) {
            $share = LibraryShare::fromRow($row);
            if ($share->isActive()) {
                $shares[] = $share;
            }
        }
        return $shares;
    }

    /**
     * Get all incoming shares (libraries shared with me).
     *
     * @param string $userId User UUID.
     *
     * @return array<int, SharedLibraryDto>
     */
    public function getSharedWithMe(string $userId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT
                ls.id,
                ls.owner_user_id,
                ls.collaborator_user_id,
                ls.server_id,
                ls.library_id,
                ls.library_name,
                ls.permission_level,
                ls.created_at,
                ls.expires_at,
                u.display_name AS owner_name,
                s.server_name,
                s.hostname_candidates_json
             FROM library_shares ls
             JOIN users u ON u.id = ls.owner_user_id
             JOIN servers s ON s.id = ls.server_id
             WHERE ls.collaborator_user_id = :user_id
               AND ls.revoked_at IS NULL
             ORDER BY ls.created_at DESC',
            ['user_id' => $userId],
        );

        $shares = [];
        foreach ($rows as $row) {
            $share = LibraryShare::fromRow($row);
            if (!$share->isActive()) {
                continue;
            }

            /** @var array<int, string> $hostnames */
            $hostnames = [];
            /** @var mixed $hostnameJson */
            $hostnameJson = $row['hostname_candidates_json'] ?? null;
            if (is_string($hostnameJson) && $hostnameJson !== '') {
                /** @var mixed $decoded */
                $decoded = json_decode($hostnameJson, true);
                if (is_array($decoded)) {
                    /** @var mixed $h */
                    foreach ($decoded as $h) {
                        if (is_string($h)) {
                            $hostnames[] = $h;
                        }
                    }
                }
            }

            /** @var mixed $rawPermission */
            $rawPermission = $row['permission_level'] ?? null;
            $permissionLevel = is_string($rawPermission) ? $rawPermission : 'read';
            $expiresAt = isset($row['expires_at']) && is_numeric($row['expires_at'])
                ? (int) $row['expires_at']
                : null;

            /** @var mixed $rawOwnerName */
            $rawOwnerName = $row['owner_name'] ?? null;
            /** @var mixed $rawServerId */
            $rawServerId = $row['server_id'] ?? null;
            /** @var mixed $rawServerName */
            $rawServerName = $row['server_name'] ?? null;

            $shares[] = new SharedLibraryDto(
                shareId: $share->id,
                ownerUserId: $share->ownerUserId,
                ownerName: is_string($rawOwnerName) ? $rawOwnerName : '',
                serverId: is_string($rawServerId) ? $rawServerId : '',
                serverName: is_string($rawServerName) ? $rawServerName : '',
                libraryId: $share->libraryId,
                libraryName: $share->libraryName,
                libraryItemCount: 0,
                permissionLevel: $permissionLevel,
                accessUrls: $hostnames,
                expiresAt: $expiresAt,
            );
        }
        return $shares;
    }

    /**
     * Get a single share by ID.
     */
    public function getShareById(string $shareId): ?LibraryShare
    {
        return $this->findShareById($shareId);
    }

    /**
     * Check if a server is owned by a specific user.
     */
    public function isServerOwnedByUser(string $serverId, string $userId): bool
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT id FROM servers WHERE id = :id AND user_id = :user_id LIMIT 1',
            ['id' => $serverId, 'user_id' => $userId],
        );
        return !empty($rows);
    }

    /**
     * Find a share by ID (internal helper).
     */
    private function findShareById(string $shareId): ?LibraryShare
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT * FROM library_shares WHERE id = :id',
            ['id' => $shareId],
        );

        if (!isset($rows[0])) {
            return null;
        }

        return LibraryShare::fromRow($rows[0]);
    }

    /**
     * Find an existing non-revoked share for the given tuple.
     */
    private function findExistingShare(string $ownerId, string $collaboratorId, string $libraryId): ?LibraryShare
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT * FROM library_shares
             WHERE owner_user_id = :owner_id
               AND collaborator_user_id = :collaborator_id
               AND library_id = :library_id
               AND revoked_at IS NULL
             LIMIT 1',
            [
                'owner_id' => $ownerId,
                'collaborator_id' => $collaboratorId,
                'library_id' => $libraryId,
            ],
        );

        if (!isset($rows[0])) {
            return null;
        }

        return LibraryShare::fromRow($rows[0]);
    }

    /**
     * Generate a UUID v4 string.
     */
    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
        );
    }
}
