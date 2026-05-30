<?php

declare(strict_types=1);

namespace Phlix\Hub\Federation;

use Workerman\MySQL\Connection;

/**
 * Repository for federation admin delegations.
 *
 * Tracks cross-hub admin privileges granted to users from peer hubs.
 *
 * @package Phlix\Hub\Federation
 */
class FederationAdminDelegationRepository
{
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    /**
     * Grant an admin delegation to a user from a peer hub.
     *
     * @param string $id     Delegation UUID.
     * @param string $peerId Peer UUID granting the delegation.
     * @param string $userId User UUID receiving delegation.
     *
     * @return void
     */
    public function grant(string $id, string $peerId, string $userId): void
    {
        $this->db->query(
            'INSERT IGNORE INTO federation_admin_delegations
             (id, peer_id, user_id)
             VALUES (:id, :peer_id, :user_id)',
            [
                'id' => $id,
                'peer_id' => $peerId,
                'user_id' => $userId,
            ],
        );
    }

    /**
     * Revoke an admin delegation (soft revoke by setting revoked_at).
     *
     * @param string $id Delegation UUID.
     *
     * @return void
     */
    public function revoke(string $id): void
    {
        $this->db->query(
            'UPDATE federation_admin_delegations
             SET revoked_at = NOW()
             WHERE id = :id',
            ['id' => $id],
        );
    }

    /**
     * Get all active delegations for a user.
     *
     * @param string $userId User UUID.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getActiveDelegationsForUser(string $userId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT * FROM federation_admin_delegations
             WHERE user_id = :user_id AND revoked_at IS NULL',
            ['user_id' => $userId],
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Get all active delegations for a peer.
     *
     * @param string $peerId Peer UUID.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getActiveDelegationsForPeer(string $peerId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT * FROM federation_admin_delegations
             WHERE peer_id = :peer_id AND revoked_at IS NULL',
            ['peer_id' => $peerId],
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Get a delegation by ID.
     *
     * @param string $id Delegation UUID.
     *
     * @return array<string, mixed>|null Delegation record or null.
     */
    public function getDelegationById(string $id): ?array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT * FROM federation_admin_delegations WHERE id = :id LIMIT 1',
            ['id' => $id],
        );

        return $rows[0] ?? null;
    }

    /**
     * Check if a user has an active admin delegation from a peer.
     *
     * @param string $peerId Peer UUID.
     * @param string $userId User UUID.
     *
     * @return bool True if an active delegation exists.
     */
    public function isUserAdminOnPeer(string $peerId, string $userId): bool
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT id FROM federation_admin_delegations
             WHERE peer_id = :peer_id AND user_id = :user_id AND revoked_at IS NULL
             LIMIT 1',
            [
                'peer_id' => $peerId,
                'user_id' => $userId,
            ],
        );

        return !empty($rows);
    }
}
