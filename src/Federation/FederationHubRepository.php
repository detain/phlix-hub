<?php

/**
 * Phlix hub component: Federation.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Federation;

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
    /**
     * Fixed primary key of the hub's own single config row (review Minor 44).
     *
     * The row identity used to be a random UUID minted at first bootstrap,
     * which made the old SELECT-then-INSERT_IGNORE dance a TOCTOU: two
     * concurrent bootstraps carrying DRIFTED urls both inserted (uq_url only
     * collapses same-URL races), silently breaking the one-row invariant this
     * class documents. With a fixed id, concurrent INSERTs collide on PRIMARY
     * itself — the ON DUPLICATE KEY UPDATE arm turns the race into a
     * last-writer-wins refresh of the ONE row. Format-matched to a UUID v4
     * because the value crosses the wire (federation_peers.leaf_hub_id stores
     * a peer hub's own row id; callers pattern-check it).
     */
    public const string HUB_ROW_ID = '00000000-0000-4000-8000-000000000044';

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
            'SELECT id, name, url, public_key, role, is_master, is_active, created_at, updated_at FROM '
            . 'federation_hubs ORDER BY id LIMIT 1',
        );

        return $rows[0] ?? null;
    }

    /**
     * Ensure the hub row exists. Creates it (fixed {@see self::HUB_ROW_ID})
     * or updates name/url/public_key if already present.
     *
     * Minor 44 determinism: the old form probed with an UN-ordered `LIMIT 1`
     * and answered emptiness with `INSERT IGNORE` — a drifted-URL concurrent
     * bootstrap slipped past uq_url and produced a second row, after which
     * every un-ordered read of "the" hub row became non-deterministic. The
     * create arm now rides the fixed sentinel id, so any race collapses on
     * PRIMARY via ON DUPLICATE KEY UPDATE, and every read ORDERs BY id so the
     * chosen row is stable even against a table already broken by the old
     * code. The update arm is keyed to the row that was actually found
     * (legacy rows keep their id — federation_peers.leaf_hub_id cross-
     * references it) instead of rewriting the whole table.
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
            'SELECT id FROM federation_hubs ORDER BY id LIMIT 1',
        );

        if ($rows === []) {
            $this->upsertSentinelRow($name, $url, $publicKey);

            return;
        }

        /** @var array<string, mixed> $row */
        $row = $rows[0];
        /** @var mixed $rowId */
        $rowId = $row['id'] ?? null;
        if (!is_string($rowId) || $rowId === '') {
            // Defensive: a row whose id does not read back as a non-empty
            // string cannot be targeted by key — stamp the sentinel slot
            // instead of leaving the config un-written (and never a silent
            // no-op: the values land on the very next read).
            $this->upsertSentinelRow($name, $url, $publicKey);

            return;
        }

        $this->db->query(
            'UPDATE federation_hubs SET name = :name, url = :url, public_key = :public_key WHERE id = :id',
            [
                'name' => $name,
                'url' => $url,
                'public_key' => $publicKey,
                'id' => $rowId,
            ],
        );
    }

    /**
     * One idempotent statement that lands the config on the sentinel row:
     * fresh insert when absent, in-place refresh when a concurrent bootstrap
     * (or a legacy same-URL row via uq_url) already occupies a unique key.
     *
     * @return void
     */
    private function upsertSentinelRow(string $name, string $url, string $publicKey): void
    {
        $this->db->query(
            'INSERT INTO federation_hubs (id, name, url, public_key)
             VALUES (:id, :name, :url, :public_key)
             AS new_row
             ON DUPLICATE KEY UPDATE
                 name = new_row.name, url = new_row.url, public_key = new_row.public_key',
            [
                'id' => self::HUB_ROW_ID,
                'name' => $name,
                'url' => $url,
                'public_key' => $publicKey,
            ],
        );
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
     *                     the peer itself (`hub_hello`'s `hub_id` on the master,
     *                     `hub_hello_ack`'s `master_hub_id` on the leaf; see
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
            'SELECT id, name, url, public_key, leaf_hub_id, relay_enabled, admin_delegation_enabled, status, '
             . 'last_seen_at, last_connected_at, created_at, updated_at FROM federation_peers'
             . "\n             WHERE id = :id OR leaf_hub_id = :id"
             . "\n             ORDER BY id = :id DESC"
             . "\n             LIMIT 1",
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
            'SELECT id, name, url, public_key, leaf_hub_id, relay_enabled, admin_delegation_enabled, '
            . 'status, last_seen_at, last_connected_at, created_at, updated_at FROM federation_peers WHERE '
            . 'url = :url LIMIT 1',
            ['url' => $url],
        );

        return $rows[0] ?? null;
    }

    /**
     * Get a peer by its public key.
     *
     * The lookup rides the generated-column UNIQUE index of migration 050:
     * the column holds `LOWER(SHA2(public_key, 256))` and PHP computes the
     * same lowercase hex digest here, so one handshake is one single-row
     * unique-index hit instead of a full scan of the TEXT column (the
     * claim-code norm fix, 048, ported). Byte-exact by design: the old
     * collation-lenient TEXT compare could pair a case-variant key with the
     * wrong row — such a peer failed signature verification anyway, the hash
     * predicate simply refuses earlier and never scans.
     *
     * @param string $publicKey Base64-encoded Ed25519 public key.
     *
     * @return array<string, mixed>|null Peer row or null.
     */
    public function getPeerByPublicKey(string $publicKey): ?array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT id, name, url, public_key, leaf_hub_id, relay_enabled, admin_delegation_enabled, '
            . 'status, last_seen_at, last_connected_at, created_at, updated_at FROM federation_peers WHERE '
            . 'public_key_hash = :public_key_hash LIMIT 1',
            ['public_key_hash' => hash('sha256', $publicKey)],
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
            'SELECT id, name, url, public_key, leaf_hub_id, relay_enabled, admin_delegation_enabled, '
            . 'status, last_seen_at, last_connected_at, created_at, updated_at FROM federation_peers WHERE '
            . 'status = :status ORDER BY name',
            ['status' => 'connected'],
        );

        return $rows;
    }

    /**
     * Get all peers the leaf hub may dial — everything except an operator-
     * suspended peer.
     *
     * C-1(1): dialing only 'connected' peers was self-contradictory because
     * 'connected' is written only AFTER a `hub_hello_ack` over an already-dialed
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
            "SELECT id, name, url, public_key, leaf_hub_id, relay_enabled, admin_delegation_enabled, "
            . "status, last_seen_at, last_connected_at, created_at, updated_at FROM federation_peers WHERE "
            . "status <> :suspended ORDER BY name",
            ['suspended' => 'suspended'],
        );

        return $rows;
    }

    /**
     * Bind a peer to the hub-identity UUID it reports for itself (migration
     * 046). Called from `hub_hello` handling on the master and from `hub_hello_ack`
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
}
