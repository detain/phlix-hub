<?php

declare(strict_types=1);

namespace Phlix\Hub\Hub;

use InvalidArgumentException;
use Phlix\Hub\Auth\JwtClaims;
use Phlix\Hub\Auth\JwtHandler;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Workerman\MySQL\Connection;

/**
 * Handles invite link business logic.
 *
 * @package Phlix\Hub\Hub
 */
class InviteLinkHandler
{
    private string $hubBaseUrl;

    /**
     * @param Connection            $db             MySQL connection.
     * @param JwtHandler            $jwtHandler     JWT handler for signing/verifying invite tokens.
     * @param LibrarySharingHandler $sharingHandler Library sharing handler for redeeming links.
     * @param StructuredLogger     $logger         Application logger.
     * @param string               $hubBaseUrl     Base URL of the hub for building invite URLs.
     */
    public function __construct(
        private readonly Connection $db,
        private readonly JwtHandler $jwtHandler,
        private readonly LibrarySharingHandler $sharingHandler,
        private readonly StructuredLogger $logger,
        string $hubBaseUrl = 'http://localhost:8800',
    ) {
        $this->hubBaseUrl = rtrim($hubBaseUrl, '/');
    }

    /**
     * Create a new invite link.
     *
     * @param string      $ownerId          Owner's user UUID.
     * @param string      $serverId         Server UUID.
     * @param string|null $libraryId        Library UUID on the server, or null for all libraries.
     * @param string      $permission       Permission level (read or readwrite).
     * @param int         $maxUses          Maximum number of uses (default 1).
     * @param int|null    $expiresAt        Optional UNIX timestamp expiry.
     *
     * @return InviteLink The created invite link.
     *
     * @throws InvalidArgumentException When the owner doesn't own the server (403).
     */
    public function createInviteLink(
        string $ownerId,
        string $serverId,
        ?string $libraryId,
        string $permission = 'read',
        int $maxUses = 1,
        ?int $expiresAt = null,
    ): InviteLink {
        if (!$this->sharingHandler->isServerOwnedByUser($serverId, $ownerId)) {
            throw new InvalidArgumentException('You do not own this server', 403);
        }

        if (!in_array($permission, [LibraryShare::PERMISSION_READ, LibraryShare::PERMISSION_READWRITE], true)) {
            throw new InvalidArgumentException('Invalid permission level', 400);
        }

        if ($maxUses < 1) {
            throw new InvalidArgumentException('max_uses must be at least 1', 400);
        }

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);

        $now = time();
        /** @var string $inviteId */
        $inviteId = $this->generateUuid();

        $jwtToken = $this->jwtHandler->createAccessToken(
            userId: $ownerId,
            scope: ['invite_link'],
            serverId: $serverId,
        );

        $this->db->query(
            'INSERT INTO invite_links
                (id, owner_user_id, server_id, library_id, permission, token_hash,
                 max_uses, use_count, expires_at, created_at)
             VALUES
                (:id, :owner_user_id, :server_id, :library_id, :permission, :token_hash,
                 :max_uses, :use_count, :expires_at, :created_at)',
            [
                'id' => $inviteId,
                'owner_user_id' => $ownerId,
                'server_id' => $serverId,
                'library_id' => $libraryId,
                'permission' => $permission,
                'token_hash' => $tokenHash,
                'max_uses' => $maxUses,
                'use_count' => 0,
                'expires_at' => $expiresAt,
                'created_at' => $now,
            ],
        );

        $inviteUrl = sprintf('%s/invite/%s', $this->hubBaseUrl, $jwtToken);

        $this->logger->info('Invite link created', [
            'invite_id' => $inviteId,
            'owner_id' => $ownerId,
            'server_id' => $serverId,
            'library_id' => $libraryId,
            'permission' => $permission,
            'max_uses' => $maxUses,
            'expires_at' => $expiresAt,
        ]);

        return new InviteLink(
            id: $inviteId,
            ownerUserId: $ownerId,
            serverId: $serverId,
            libraryId: $libraryId,
            permission: $permission,
            maxUses: $maxUses,
            useCount: 0,
            expiresAt: $expiresAt,
            createdAt: $now,
            url: $inviteUrl,
        );
    }

    /**
     * Redeem an invite link.
     *
     * @param string $token          The invite link token (signed JWT).
     * @param string $redeemerUserId The user UUID redeeming the link.
     *
     * @return LibraryShare The created library share.
     *
     * @throws InvalidArgumentException When token is invalid (400), expired (410),
     *                                   exhausted (410), or not found (404).
     */
    public function redeemInviteLink(string $token, string $redeemerUserId): LibraryShare
    {
        $claims = $this->jwtHandler->validateAccessToken($token);
        if ($claims === null) {
            throw new InvalidArgumentException('Invalid or expired invite token', 400);
        }

        /** @var array<string, mixed> $claimsData */
        $claimsData = $claims->toPayload();
        /** @var mixed $inviteTokenRaw */
        $inviteTokenRaw = $claimsData['token'] ?? null;
        /** @var mixed $ownerIdRaw */
        $ownerIdRaw = $claimsData['owner_id'] ?? null;
        /** @var mixed $serverIdRaw */
        $serverIdRaw = $claimsData['server_id'] ?? null;
        /** @var mixed $libraryIdRaw */
        $libraryIdRaw = $claimsData['library_id'] ?? null;
        /** @var mixed $permissionRaw */
        $permissionRaw = $claimsData['permission'] ?? 'read';
        /** @var mixed $maxUsesRaw */
        $maxUsesRaw = $claimsData['max_uses'] ?? 1;
        /** @var mixed $expiresAtRaw */
        $expiresAtRaw = $claimsData['expires_at'] ?? null;

        if (
            !is_string($inviteTokenRaw)
            || !is_string($ownerIdRaw)
            || !is_string($serverIdRaw)
        ) {
            throw new InvalidArgumentException('Malformed invite token', 400);
        }

        /** @var string $inviteToken */
        $inviteToken = $inviteTokenRaw;
        /** @var string $ownerId */
        $ownerId = $ownerIdRaw;
        /** @var string $serverId */
        $serverId = $serverIdRaw;
        /** @var string|null $libraryId */
        $libraryId = is_string($libraryIdRaw) ? $libraryIdRaw : null;
        /** @var string $permission */
        $permission = is_string($permissionRaw) ? $permissionRaw : 'read';
        /** @var int $maxUses */
        $maxUses = is_numeric($maxUsesRaw) ? (int) $maxUsesRaw : 1;

        $tokenHash = hash('sha256', $inviteToken);

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT * FROM invite_links WHERE token_hash = :token_hash LIMIT 1',
            ['token_hash' => $tokenHash],
        );

        if (!isset($rows[0])) {
            throw new InvalidArgumentException('Invite link not found', 404);
        }

        $row = $rows[0];
        $useCount = is_numeric($row['use_count'] ?? null) ? (int) $row['use_count'] : 0;
        $rowExpiresAt = isset($row['expires_at']) && is_numeric($row['expires_at'])
            ? (int) $row['expires_at']
            : null;

        if ($rowExpiresAt !== null && time() > $rowExpiresAt) {
            throw new InvalidArgumentException('Invite link has expired', 410);
        }

        if ($useCount >= $maxUses) {
            throw new InvalidArgumentException('Invite link has been exhausted', 410);
        }

        if ($redeemerUserId === $ownerId) {
            throw new InvalidArgumentException('Cannot redeem your own invite link', 400);
        }

        $this->db->query(
            'UPDATE invite_links SET use_count = use_count + 1 WHERE token_hash = :token_hash',
            ['token_hash' => $tokenHash],
        );

        $serverName = $this->getServerName($serverId);
        /** @var string $libraryName */
        $libraryName = $libraryId !== null ? $this->getLibraryName($serverId, $libraryId) : 'All Libraries';

        $redeemerEmail = $this->getUserEmail($redeemerUserId);
        if ($redeemerEmail === null) {
            throw new InvalidArgumentException('User not found', 404);
        }

        $share = $this->sharingHandler->shareLibrary(
            ownerId: $ownerId,
            collaboratorEmail: $redeemerEmail,
            serverId: $serverId,
            libraryId: $libraryId ?? '',
            libraryName: $libraryName,
            permission: $permission,
        );

        $this->logger->info('Invite link redeemed', [
            'owner_id' => $ownerId,
            'redeemer_id' => $redeemerUserId,
            'server_id' => $serverId,
            'library_id' => $libraryId,
            'permission' => $permission,
        ]);

        return $share;
    }

    /**
     * List all invite links for an owner.
     *
     * @param string $ownerId User UUID.
     *
     * @return array<int, InviteLink>
     */
    public function listForOwner(string $ownerId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT * FROM invite_links WHERE owner_user_id = :owner_id ORDER BY created_at DESC',
            ['owner_id' => $ownerId],
        );

        $links = [];
        foreach ($rows as $row) {
            /** @var string $tokenHash */
            $tokenHash = is_string($row['token_hash'] ?? null) ? $row['token_hash'] : '';
            /** @var string $serverId */
            $serverId = is_string($row['server_id'] ?? null) ? $row['server_id'] : '';
            $url = $this->buildInviteUrlFromHash($tokenHash, $ownerId, $serverId);
            $links[] = InviteLink::fromRow($row, $url);
        }
        return $links;
    }

    /**
     * Revoke an invite link.
     *
     * @param string $ownerId Owner of the invite link.
     * @param string $linkId Invite link UUID to revoke.
     *
     * @throws InvalidArgumentException When link not found (404) or not owned by caller (403).
     */
    public function revokeInviteLink(string $ownerId, string $linkId): void
    {
        $link = $this->findLinkById($linkId);
        if ($link === null) {
            throw new InvalidArgumentException('Invite link not found', 404);
        }

        if (($link['owner_user_id'] ?? '') !== $ownerId) {
            throw new InvalidArgumentException('You do not own this invite link', 403);
        }

        $this->db->query(
            'UPDATE invite_links SET max_uses = use_count WHERE id = :id',
            ['id' => $linkId],
        );

        $this->logger->info('Invite link revoked', [
            'invite_id' => $linkId,
            'owner_id' => $ownerId,
        ]);
    }

    /**
     * Find an invite link by ID (internal helper).
     *
     * @return array<string, mixed>|null
     */
    private function findLinkById(string $linkId): ?array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT * FROM invite_links WHERE id = :id',
            ['id' => $linkId],
        );

        return $rows[0] ?? null;
    }

    /**
     * Get server name by ID.
     */
    private function getServerName(string $serverId): string
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT server_name FROM servers WHERE id = :id LIMIT 1',
            ['id' => $serverId],
        );

        /** @var string $serverName */
        $serverName = $rows[0]['server_name'] ?? 'Unknown Server';
        return $serverName;
    }

    /**
     * Get library name by ID.
     */
    private function getLibraryName(string $serverId, string $libraryId): string
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT library_name FROM library_shares
             WHERE server_id = :server_id AND library_id = :library_id
             LIMIT 1',
            ['server_id' => $serverId, 'library_id' => $libraryId],
        );

        /** @var string $libraryName */
        $libraryName = $rows[0]['library_name'] ?? 'Shared Library';
        return $libraryName;
    }

    /**
     * Get user email by user ID.
     */
    private function getUserEmail(string $userId): ?string
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT email FROM users WHERE id = :id LIMIT 1',
            ['id' => $userId],
        );

        if (!isset($rows[0])) {
            return null;
        }

        /** @var string $email */
        $email = is_string($rows[0]['email'] ?? null) ? $rows[0]['email'] : null;
        return $email;
    }

    /**
     * Build invite URL from token hash (for listing).
     */
    private function buildInviteUrlFromHash(string $tokenHash, string $ownerId, string $serverId): string
    {
        $payload = [
            'token_hash' => $tokenHash,
            'owner_id' => $ownerId,
            'server_id' => $serverId,
        ];

        $encoded = json_encode($payload);
        if ($encoded === false) {
            $encoded = '{}';
        }

        return sprintf('%s/invite/%s', $this->hubBaseUrl, base64_encode($encoded));
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
