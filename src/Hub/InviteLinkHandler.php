<?php

/**
 * Phlix hub component: Hub.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Hub;

use Phlix\Hub\Common\Support\Ids;
use InvalidArgumentException;
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
     * @param LibrarySharingHandler $sharingHandler Library sharing handler for redeeming links.
     * @param StructuredLogger     $logger         Application logger.
     * @param string               $hubBaseUrl     Base URL of the hub for building invite URLs.
     */
    public function __construct(
        private readonly Connection $db,
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

        // The URL carries the OPAQUE token only. It is never a session-grade
        // credential: holding the URL grants exactly one invite redemption,
        // bounded by max_uses/expiry, and revocable via revokeInviteLink().
        $inviteUrl = sprintf('%s/invite/%s', $this->hubBaseUrl, $token);

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
            token: $token,
        );
    }

    /**
     * Redeem an invite link.
     *
     * @param string $token          The opaque invite token from the invite URL.
     * @param string $redeemerUserId The user UUID redeeming the link.
     *
     * @return LibraryShare The created library share.
     *
     * @throws InvalidArgumentException When token is malformed (400), expired (410),
     *                                   exhausted (410), or not found (404).
     */
    public function redeemInviteLink(string $token, string $redeemerUserId): LibraryShare
    {
        // Parse, don't validate: the only redeemable shape is the 64-hex opaque
        // token minted by createInviteLink(). Anything else (including legacy
        // JWT URLs minted before this fix) can never match a stored hash, so
        // reject it up front instead of hashing junk at the database.
        if (preg_match('/\A[0-9a-f]{64}\z/', $token) !== 1) {
            throw new InvalidArgumentException('Malformed invite token', 400);
        }

        // Hashed lookup: the row is keyed by sha256(token), so a stolen DB dump
        // yields no usable tokens, and the comparison itself never touches the
        // plaintext beyond this one-way derivation.
        $tokenHash = hash('sha256', $token);

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT * FROM invite_links WHERE token_hash = :token_hash LIMIT 1',
            ['token_hash' => $tokenHash],
        );

        if (!isset($rows[0])) {
            throw new InvalidArgumentException('Invite link not found', 404);
        }

        $row = $rows[0];

        // The invite_links row is the authoritative record for ownership, target
        // and permission — read them from the row so the conditional UPDATE below
        // and the resulting share both agree with the persisted invite.
        $ownerId = is_string($row['owner_user_id'] ?? null) ? (string) $row['owner_user_id'] : '';
        $serverId = is_string($row['server_id'] ?? null) ? (string) $row['server_id'] : '';
        $libraryId = is_string($row['library_id'] ?? null) ? (string) $row['library_id'] : null;
        $permission = is_string($row['permission'] ?? null) ? (string) $row['permission'] : 'read';
        $rowExpiresAt = isset($row['expires_at']) && is_numeric($row['expires_at'])
            ? (int) $row['expires_at']
            : null;

        if ($rowExpiresAt !== null && time() > $rowExpiresAt) {
            throw new InvalidArgumentException('Invite link has expired', 410);
        }

        if ($redeemerUserId === $ownerId) {
            throw new InvalidArgumentException('Cannot redeem your own invite link', 400);
        }

        // Atomic single-use claim. The previous code read `use_count`, decided the
        // invite was still valid, then UPDATEd `use_count = use_count + 1` in a
        // SEPARATE statement — a check-then-act race where two concurrent
        // redemptions could both pass the read and both increment, redeeming a
        // `max_uses = 1` invite twice. Collapse the decision and the increment
        // into ONE conditional UPDATE that the database evaluates atomically:
        // the row is incremented if and only if it still has uses left and has
        // not expired. `use_count < max_uses` compares the columns DB-side (the
        // authoritative source), and the expiry predicate is re-checked in the
        // WHERE so an invite that expires between the SELECT and the UPDATE is
        // not claimed. Exactly one of N concurrent redemptions will see one
        // affected row; the losers see zero. Affected-rows is reported correctly
        // under the connection's emulated + buffered prepares (see B4).
        // workerman's Connection::query() returns the PDOStatement rowCount for
        // UPDATE statements (an int); narrow the mixed return defensively.
        /** @var mixed $updateResult */
        $updateResult = $this->db->query(
            'UPDATE invite_links
                SET use_count = use_count + 1
             WHERE token_hash = :token_hash
               AND use_count < max_uses
               AND (expires_at IS NULL OR expires_at > :now)',
            ['token_hash' => $tokenHash, 'now' => time()],
        );
        $affected = is_numeric($updateResult) ? (int) $updateResult : 0;

        if ($affected !== 1) {
            // Zero affected rows: another redemption already consumed the last
            // use, or the invite expired/was revoked in the race window. Reject
            // with the same error an exhausted invite has always returned.
            throw new InvalidArgumentException('Invite link has been exhausted', 410);
        }

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
     * Listed links carry NO shareable URL: the plaintext token is shown once at
     * creation and only its sha256 is stored, so a usable URL cannot (and by
     * design never again will) be rebuilt from the row.
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
            $links[] = InviteLink::fromRow($row, null);
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
     * Generate a UUID v4 string.
     */
    private function generateUuid(): string
    {
        return Ids::uuidV4();
    }
}
