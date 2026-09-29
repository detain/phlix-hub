<?php

/**
 * Phlix hub component: Federation.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Federation;

use Phlix\Hub\Common\Support\Ids;
use Workerman\MySQL\Connection;

/**
 * Repository for the federation_hubs table (self-referential hub config).
 *
 * There is exactly ONE row in this table — the hub's own configuration.
 * All methods operate on or query that single row.
 *
 * @package Phlix\Hub\Federation
 */
class FederationHubRepository
{
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    /**
     * Get the hub's own configuration row.
     *
     * @return array<string, mixed>|null Row data or null if not yet configured.
     */
    public function getHubConfig(): ?array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT * FROM federation_hubs LIMIT 1',
        );

        return $rows[0] ?? null;
    }

    /**
     * Ensure the hub row exists. Creates it with INSERT IGNORE if absent,
     * or updates url/name/public_key if already present.
     *
     * @param string $name      Human-readable hub name.
     * @param string $url       Public-facing hub URL.
     * @param string $publicKey  Ed25519 public key (base64-encoded).
     *
     * @return void
     */
    public function ensureHubExists(string $name, string $url, string $publicKey): void
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT id FROM federation_hubs LIMIT 1',
        );

        if ($rows === []) {
            $id = $this->generateUuid();
            $this->db->query(
                'INSERT IGNORE INTO federation_hubs (id, name, url, public_key)
                 VALUES (:id, :name, :url, :public_key)',
                [
                    'id' => $id,
                    'name' => $name,
                    'url' => $url,
                    'public_key' => $publicKey,
                ],
            );
        } else {
            $this->db->query(
                'UPDATE federation_hubs SET name = :name, url = :url, public_key = :public_key',
                [
                    'name' => $name,
                    'url' => $url,
                    'public_key' => $publicKey,
                ],
            );
        }
    }

    /**
     * Update the hub's role and sync the is_master flag accordingly.
     *
     * @param string $role Either 'master' or 'leaf'.
     *
     * @return void
     */
    public function updateRole(string $role): void
    {
        $this->db->query(
            'UPDATE federation_hubs SET role = :role, is_master = CASE WHEN role = :role THEN 1 ELSE 0 END',
            ['role' => $role],
        );
    }

    /**
     * Update the hub's active flag.
     *
     * @param bool $active Whether the hub is active.
     *
     * @return void
     */
    public function updateActive(bool $active): void
    {
        $this->db->query(
            'UPDATE federation_hubs SET is_active = :is_active',
            ['is_active' => $active ? 1 : 0],
        );
    }

    /**
     * Resolve a peer from EITHER federation id space (C-1(3) reconciliation).
     *
     * Two UUIDs can name the same remote hub:
     *   1. `id`         — the row's primary key, minted by the LOCAL hub when
     *                     the peer was added via the admin API.
     *   2. `leaf_hub_id` — the peer's OWN `federation_hubs.id`, learned from
     *                     the peer itself (HUB_HELLO's `hub_id` on the master,
     *                     HELLO_ACK's `master_hub_id` on the leaf; see
     *                     {@see FederationFrameHandler::handleHubHello()} and
     *                     {@see FederationPeerManager::handleHelloAck()}).
     *
     * A leaf dials `/relay/federation/{its own federation_hubs.id}`, and the
     * WS-upgrade gate (FederationWorker) resolves that path segment here, so
     * the lookup must accept both forms. Bootstrapping a brand-new peer still
     * requires its `leaf_hub_id` to be recorded up-front — pass it to
     * {@see createPeer()} or bind it later with {@see setPeerLeafHubId()}.
     *
     * @param string $id Peer row UUID or the peer's own hub UUID.
     *
     * @return array<string, mixed>|null Peer row or null.
     */
    public function getPeerById(string $id): ?array
    {
        // Deterministic tie-break: if one hub's row PK equals ANOTHER peer's
        // bound leaf_hub_id (astronomically unlikely for v4 UUIDs, but the
        // dual-address query would then match two rows), the exact PK hit wins
        // — callers that hold a row id must always get that row back. The
        // repeated :id placeholder is safe because of the PDO layer, NOT
        // Workerman's: bindMore() only collects `:name => value` pairs (no
        // textual substitution), and the vendor Connection::connect() actually
        // sets ATTR_EMULATE_PREPARES=false (native). What makes reuse work is
        // PhlixMySQLConnection::connect(), which FORCES ATTR_EMULATE_PREPARES
        // back to true after the parent connects — PDO then inlines the bound
        // value at EVERY occurrence of :id client-side. Every query through
        // this repository lands on that subclass (ConnectionPool leases it;
        // the pool-disabled fallback constructs it), so the semantics hold.
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT * FROM federation_peers
             WHERE id = :id OR leaf_hub_id = :id
             ORDER BY id = :id DESC
             LIMIT 1',
            ['id' => $id],
        );

        return $rows[0] ?? null;
    }

    /**
     * Get a peer by its URL.
     *
     * @param string $url Peer URL.
     *
     * @return array<string, mixed>|null Peer row or null.
     */
    public function getPeerByUrl(string $url): ?array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT * FROM federation_peers WHERE url = :url LIMIT 1',
            ['url' => $url],
        );

        return $rows[0] ?? null;
    }

    /**
     * Get a peer by its public key.
     *
     * @param string $publicKey Base64-encoded Ed25519 public key.
     *
     * @return array<string, mixed>|null Peer row or null.
     */
    public function getPeerByPublicKey(string $publicKey): ?array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT * FROM federation_peers WHERE public_key = :public_key LIMIT 1',
            ['public_key' => $publicKey],
        );

        return $rows[0] ?? null;
    }

    /**
     * Get all registered peers.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllPeers(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT p.*,
                    (SELECT COUNT(*) FROM federation_library_shares fls
                     WHERE fls.peer_id = p.id AND fls.status = \'active\') AS shared_library_count
             FROM federation_peers p
             ORDER BY p.name',
        );

        return $rows;
    }

    /**
     * Get all peers with 'connected' status.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getConnectedPeers(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT * FROM federation_peers WHERE status = :status ORDER BY name',
            ['status' => 'connected'],
        );

        return $rows;
    }

    /**
     * Get all peers the leaf hub may dial — everything except an operator-
     * suspended peer.
     *
     * C-1(1): dialing only 'connected' peers was self-contradictory because
     * 'connected' is written only AFTER a HELLO-ACK over an already-dialed
     * connection, so a freshly created ('pending') peer was never dialed and
     * a dropped ('disconnected') peer never came back. Liveness is tracked by
     * the session layer; the dial loop iterates eligibility.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDialablePeers(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            "SELECT * FROM federation_peers WHERE status <> :suspended ORDER BY name",
            ['suspended' => 'suspended'],
        );

        return $rows;
    }

    /**
     * Bind a peer to the hub-identity UUID it reports for itself (migration
     * 046). Called from HUB_HELLO handling on the master and from HELLO_ACK
     * handling on the leaf; also the seam for a future pairing API to
     * pre-bind an identity before the first dial.
     *
     * @param string $peerId    Local peer row UUID.
     * @param string $leafHubId The remote hub's own federation_hubs.id.
     *
     * @return void
     */
    public function setPeerLeafHubId(string $peerId, string $leafHubId): void
    {
        $this->db->query(
            'UPDATE federation_peers SET leaf_hub_id = :leaf_hub_id WHERE id = :id',
            [
                'leaf_hub_id' => $leafHubId,
                'id' => $peerId,
            ],
        );
    }


    /**
     * Create a new peer record.
     *
     * @param string      $id        Peer UUID.
     * @param string      $name      Human-readable peer name.
     * @param string      $url       Public-facing peer URL.
     * @param string      $publicKey Base64-encoded Ed25519 public key.
     * @param string|null $leafHubId Optional pre-bound remote hub identity
     *                               (the peer's own federation_hubs.id) so a
     *                               leaf can already dial `/relay/federation/
     *                               {own id}` on the very first connect —
     *                               see {@see getPeerById()} (C-1(3)).
     *
     * @return void
     */
    public function createPeer(
        string $id,
        string $name,
        string $url,
        string $publicKey,
        ?string $leafHubId = null,
    ): void {
        $this->db->query(
            'INSERT INTO federation_peers (id, name, url, public_key, leaf_hub_id)
             VALUES (:id, :name, :url, :public_key, :leaf_hub_id)',
            [
                'id' => $id,
                'name' => $name,
                'url' => $url,
                'public_key' => $publicKey,
                'leaf_hub_id' => $leafHubId,
            ],
        );
    }

    /**
     * Update a peer's status and timestamps.
     *
     * @param string $id     Peer UUID.
     * @param string $status New status value.
     *
     * @return void
     */
    public function updatePeerStatus(string $id, string $status): void
    {
        $now = $status === 'connected' ? 'NOW()' : null;
        $this->db->query(
            'UPDATE federation_peers
             SET status = :status,
                 last_seen_at = NOW()
                 ' . ($now !== null ? ', last_connected_at = NOW()' : '') . '
             WHERE id = :id',
            ['id' => $id, 'status' => $status],
        );
    }

    /**
     * Update a peer's feature toggles.
     *
     * @param string $id                    Peer UUID.
     * @param bool   $relayEnabled          Whether relay is enabled.
     * @param bool   $adminDelegationEnabled Whether admin delegation is enabled.
     *
     * @return void
     */
    public function updatePeerToggles(string $id, bool $relayEnabled, bool $adminDelegationEnabled): void
    {
        $this->db->query(
            'UPDATE federation_peers
             SET relay_enabled = :relay_enabled,
                 admin_delegation_enabled = :admin_delegation_enabled
             WHERE id = :id',
            [
                'id' => $id,
                'relay_enabled' => $relayEnabled ? 1 : 0,
                'admin_delegation_enabled' => $adminDelegationEnabled ? 1 : 0,
            ],
        );
    }

    /**
     * Delete a peer and cascade.
     *
     * @param string $id Peer UUID.
     *
     * @return void
     */
    public function deletePeer(string $id): void
    {
        $this->db->query(
            'DELETE FROM federation_peers WHERE id = :id',
            ['id' => $id],
        );
    }

    /**
     * Generate a random UUID v4.
     *
     * @return string Formatted UUID string.
     */
    private function generateUuid(): string
    {
        return Ids::uuidV4();
    }
}
