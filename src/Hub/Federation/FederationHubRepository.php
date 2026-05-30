<?php

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
            'UPDATE federation_hubs SET is_master = CASE WHEN role = :role THEN 1 ELSE 0 END',
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
     * Get a peer by its UUID.
     *
     * @param string $id Peer UUID.
     *
     * @return array<string, mixed>|null Peer row or null.
     */
    public function getPeerById(string $id): ?array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT * FROM federation_peers WHERE id = :id LIMIT 1',
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
            'SELECT * FROM federation_peers ORDER BY name',
        );

        return is_array($rows) ? $rows : [];
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

        return is_array($rows) ? $rows : [];
    }

    /**
     * Create a new peer record.
     *
     * @param string $id        Peer UUID.
     * @param string $name      Human-readable peer name.
     * @param string $url       Public-facing peer URL.
     * @param string $publicKey Base64-encoded Ed25519 public key.
     *
     * @return void
     */
    public function createPeer(string $id, string $name, string $url, string $publicKey): void
    {
        $this->db->query(
            'INSERT INTO federation_peers (id, name, url, public_key)
             VALUES (:id, :name, :url, :public_key)',
            [
                'id' => $id,
                'name' => $name,
                'url' => $url,
                'public_key' => $publicKey,
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
