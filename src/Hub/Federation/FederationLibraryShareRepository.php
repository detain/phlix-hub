<?php

declare(strict_types=1);

namespace Phlix\Hub\Federation;

use Workerman\MySQL\Connection;

/**
 * Repository for federation library shares (outgoing and incoming).
 *
 * Handles both outgoing shares (this hub → peers) and incoming share
 * offers (peers → this hub).
 *
 * @package Phlix\Hub\Federation
 */
class FederationLibraryShareRepository
{
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    /**
     * Create an outgoing library share record.
     *
     * @param string $id          Share UUID.
     * @param string $libraryId   Library UUID being shared.
     * @param string $libraryName Name of the library.
     * @param string $peerId      Peer UUID receiving the share.
     * @param string $permission  Either 'read' or 'readwrite'.
     *
     * @return void
     */
    public function createOutgoingShare(
        string $id,
        string $libraryId,
        string $libraryName,
        string $peerId,
        string $permission,
    ): void {
        $this->db->query(
            'INSERT INTO federation_library_shares
             (id, library_id, library_name, peer_id, permission)
             VALUES (:id, :library_id, :library_name, :peer_id, :permission)',
            [
                'id' => $id,
                'library_id' => $libraryId,
                'library_name' => $libraryName,
                'peer_id' => $peerId,
                'permission' => $permission,
            ],
        );
    }

    /**
     * Get all outgoing shares.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getOutgoingShares(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT * FROM federation_library_shares ORDER BY shared_at DESC',
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Get a specific outgoing share by ID.
     *
     * @param string $id Share UUID.
     *
     * @return array<string, mixed>|null Share record or null.
     */
    public function getOutgoingShareById(string $id): ?array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT * FROM federation_library_shares WHERE id = :id LIMIT 1',
            ['id' => $id],
        );

        return $rows[0] ?? null;
    }

    /**
     * Get all active (non-revoked) outgoing shares.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getActiveOutgoingShares(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT * FROM federation_library_shares WHERE status = :status ORDER BY shared_at DESC',
            ['status' => 'active'],
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Revoke an outgoing share.
     *
     * @param string $id Share UUID.
     *
     * @return void
     */
    public function revokeOutgoingShare(string $id): void
    {
        $this->db->query(
            'UPDATE federation_library_shares
             SET status = :status, revoked_at = NOW()
             WHERE id = :id',
            [
                'id' => $id,
                'status' => 'revoked',
            ],
        );
    }

    /**
     * Get all incoming share offers.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getIncomingOffers(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT * FROM federation_incoming_share_offers ORDER BY offered_at DESC',
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Get a specific incoming offer by ID.
     *
     * @param string $id Offer UUID.
     *
     * @return array<string, mixed>|null Offer record or null.
     */
    public function getIncomingOfferById(string $id): ?array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT * FROM federation_incoming_share_offers WHERE id = :id LIMIT 1',
            ['id' => $id],
        );

        return $rows[0] ?? null;
    }

    /**
     * Accept an incoming share offer.
     *
     * @param string $id     Offer UUID.
     * @param string $userId User UUID who accepted.
     *
     * @return void
     */
    public function acceptIncomingOffer(string $id, string $userId): void
    {
        $this->db->query(
            'UPDATE federation_incoming_share_offers
             SET status = :status, accepted_by = :accepted_by, responded_at = NOW()
             WHERE id = :id',
            [
                'id' => $id,
                'status' => 'accepted',
                'accepted_by' => $userId,
            ],
        );
    }

    /**
     * Reject an incoming share offer.
     *
     * @param string $id     Offer UUID.
     * @param string $userId User UUID who rejected.
     *
     * @return void
     */
    public function rejectIncomingOffer(string $id, string $userId): void
    {
        $this->db->query(
            'UPDATE federation_incoming_share_offers
             SET status = :status, accepted_by = :accepted_by, responded_at = NOW()
             WHERE id = :id',
            [
                'id' => $id,
                'status' => 'rejected',
                'accepted_by' => $userId,
            ],
        );
    }

    /**
     * Handle an incoming share offer from a master peer.
     * Upserts by peer_id + library_id to avoid duplicates.
     *
     * @param array<string, mixed> $offer Offer data with keys: id, peer_id, library_id, library_name, permission.
     *
     * @return void
     */
    public function handleIncomingOffer(array $offer): void
    {
        /** @var mixed $rawId */
        $rawId = $offer['id'] ?? null;
        /** @var mixed $rawPeerId */
        $rawPeerId = $offer['peer_id'] ?? null;
        /** @var mixed $rawLibraryId */
        $rawLibraryId = $offer['library_id'] ?? null;
        /** @var mixed $rawLibraryName */
        $rawLibraryName = $offer['library_name'] ?? null;
        /** @var mixed $rawPermission */
        $rawPermission = $offer['permission'] ?? null;

        $id = is_string($rawId) ? $rawId : '';
        $peerId = is_string($rawPeerId) ? $rawPeerId : '';
        $libraryId = is_string($rawLibraryId) ? $rawLibraryId : '';
        $libraryName = is_string($rawLibraryName) ? $rawLibraryName : '';
        $permission = is_string($rawPermission) ? $rawPermission : 'read';

        if ($id === '' || $peerId === '' || $libraryId === '') {
            return;
        }

        $this->db->query(
            'INSERT INTO federation_incoming_share_offers
             (id, peer_id, library_id, library_name, permission)
             VALUES (:id, :peer_id, :library_id, :library_name, :permission)
             ON DUPLICATE KEY UPDATE
               library_name = VALUES(library_name),
               permission = VALUES(permission)',
            [
                'id' => $id,
                'peer_id' => $peerId,
                'library_id' => $libraryId,
                'library_name' => $libraryName,
                'permission' => $permission,
            ],
        );
    }
}
