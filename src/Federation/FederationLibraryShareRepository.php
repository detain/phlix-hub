<?php

/**
 * Phlix hub component: Federation.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Federation;

use Phlix\Hub\Common\Logger\StructuredLogger;
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
    /**
     * Wire-facing shape law for every id in an incoming offer (M-HUB-29).
     *
     * `id`, `peer_id` and `library_id` land in CHAR(36) columns whose values
     * are minted as UUID v4 by the ORIGIN hub; anything else is a malformed
     * or hostile frame, not data to coerce. Same pattern as
     * {@see \Phlix\Hub\Http\Controllers\FederationController}'s peer-id
     * route guard — the boundary parses, the SQL below never re-validates.
     */
    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** VARCHAR(255) on `library_name`; MySQL counts CHARACTERS, bytes are the
     *  conservative bound we can check without a collation-aware strlen. */
    private const int MAX_LIBRARY_NAME_BYTES = 255;

    public function __construct(
        private readonly Connection $db,
        private readonly ?StructuredLogger $logger = null,
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
        // Status is stamped 'active' at creation: the ENUM default 'pending'
        // left rows invisible to every active-share push (`hub_hello` sync and
        // live create-push alike) and nothing ever flipped them — a share
        // could never converge. Revocation moves the row to 'revoked'.
        $this->db->query(
            'INSERT INTO federation_library_shares
             (id, library_id, library_name, peer_id, permission, status)
             VALUES (:id, :library_id, :library_name, :peer_id, :permission, :status)',
            [
                'id' => $id,
                'library_id' => $libraryId,
                'library_name' => $libraryName,
                'peer_id' => $peerId,
                'permission' => $permission,
                'status' => 'active',
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

        return $rows;
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
     * Get the active (non-revoked) outgoing shares targeted at ONE peer.
     *
     * Misdelivery guard: an unfiltered variant used to feed every connecting
     * leaf the shares meant for all other peers. `peer_id` stores the LOCAL
     * federation_peers.id the share was created for (FK, indexed by
     * idx_peer_status), so the connecting leaf's resolved row id is the
     * exact filter.
     *
     * @param string $peerId Local federation_peers.id of the target peer.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getActiveOutgoingSharesForPeer(string $peerId): array
    {
        if ($peerId === '') {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT * FROM federation_library_shares
             WHERE status = :status AND peer_id = :peer_id
             ORDER BY shared_at DESC',
            [
                'status' => 'active',
                'peer_id' => $peerId,
            ],
        );

        return $rows;
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

        return $rows;
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
        // `status = 'pending'` is the atomically-enforced decision boundary:
        // two admins responding simultaneously (or a response racing a peer
        // re-push/revocation) settle exactly one UPDATE. The controller's
        // read-then-act 409 pre-check stays — it produces the friendly
        // error — but it is no longer the only thing standing between a race
        // and a clobbered decision (M-HUB-29).
        $this->db->query(
            "UPDATE federation_incoming_share_offers
             SET status = :status, accepted_by = :accepted_by, responded_at = NOW()
             WHERE id = :id
               AND status = 'pending'",
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
        // Atomic pending guard, identical law to acceptIncomingOffer (M-HUB-29).
        $this->db->query(
            "UPDATE federation_incoming_share_offers
             SET status = :status, accepted_by = :accepted_by, responded_at = NOW()
             WHERE id = :id
               AND status = 'pending'",
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
     * M-5: the ON DUPLICATE clause must NOT reset `status` — re-pushing an
     * offer the local admin already accepted/rejected used to flip it back to
     * 'pending' on every reconnect, silently revoking (or un-deciding) local
     * decisions. Re-pushes refresh descriptive metadata only; removals travel
     * as explicit revocations via {@see deleteIncomingOffer()}.
     *
     * @param array<array-key, mixed> $offer Offer data with keys: id, peer_id,
     *                                       library_id, library_name,
     *                                       permission. Keys are parsed
     *                                       defensively below — callers may
     *                                       pass JSON-decoded maps directly.
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
        $libraryName = is_string($rawLibraryName) ? trim($rawLibraryName) : '';
        $permission = is_string($rawPermission) && $rawPermission !== ''
            ? $rawPermission
            : 'read';

        // M-HUB-29: this is a WIRE SINK — a peer hub (or anything that has
        // forged a frame past the verified-channel gates) supplies every byte
        // below. Pre-fix, only emptiness was checked, so a hostile offer
        // reached the SQL as a guaranteed PDOException (non-UUID strings over
        // 36 chars or violating the FK/ENUM/NOT-NULL surface) thrown into the
        // frame handler, or — with a non-strict sql_mode venue — as silently
        // truncated garbage stored as trusted rows. The sink now PARSES: bad
        // shape means refuse the offer loudly in the relay log and keep the
        // channel alive; nothing downstream re-validates what lands here.
        if (
            !preg_match(self::UUID_PATTERN, $id)
            || !preg_match(self::UUID_PATTERN, $peerId)
            || !preg_match(self::UUID_PATTERN, $libraryId)
        ) {
            $this->refuseOffer('malformed offer id (id/peer_id/library_id must be UUIDs)', $id, $peerId, $libraryId);
            return;
        }

        if ($permission !== 'read' && $permission !== 'readwrite') {
            $this->refuseOffer('unknown permission in offer', $id, $peerId, $libraryId);
            return;
        }

        if ($libraryName === '' || strlen($libraryName) > self::MAX_LIBRARY_NAME_BYTES) {
            $this->refuseOffer('library_name empty or oversized in offer', $id, $peerId, $libraryId);
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

    /**
     * Drop an incoming offer whose source share was revoked on the remote hub.
     *
     * M-5 revocation path: the `status` enum has no 'revoked' member, and a
     * revoked share is no longer an offer awaiting a decision — so the row is
     * deleted rather than re-labelled.
     *
     * Scoped by the LOCAL peer FK, not just the wire offer id: share ids are
     * minted by each origin hub's row space, so two peers could theoretically
     * collide on an id — a revocation from B must never delete A's offer.
     *
     * @param string $id     Offer UUID (the share row id as minted by the origin hub).
     * @param string $peerId Local federation_peers.id of the offering peer.
     *
     * @return void
     */
    public function deleteIncomingOffer(string $id, string $peerId): void
    {
        if ($id === '' || $peerId === '') {
            return;
        }

        $this->db->query(
            'DELETE FROM federation_incoming_share_offers WHERE id = :id AND peer_id = :peer_id',
            [
                'id' => $id,
                'peer_id' => $peerId,
            ],
        );
    }

    /**
     * Loud refusal of a malformed incoming offer (M-HUB-29).
     *
     * Deliberately a warning-and-drop, not a throw: the callers are the two
     * verified-channel frame sinks ({@see FederationFrameHandler} and
     * {@see FederationPeerManager}); an exception there would tear down a
     * live, mutually-authenticated federation link over one bad frame worth
     * less than the link. The identity fields are echoed so operators can
     * attribute repeat abuse to a peer.
     */
    private function refuseOffer(string $reason, string $id, string $peerId, string $libraryId): void
    {
        $this->logger?->warning('Federation incoming share offer refused', [
            'reason' => $reason,
            'offer_id' => $id,
            'peer_id' => $peerId,
            'library_id' => $libraryId,
        ]);
    }
}
