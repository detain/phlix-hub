<?php

declare(strict_types=1);

namespace Phlix\Hub\Federation;

/**
 * DTO representing a federation peer.
 *
 * @package Phlix\Hub\Federation
 */
final readonly class FederationPeerDto
{
    public function __construct(
        public string $id,
        public string $name,
        public string $url,
        public string $publicKey,
        public bool $relayEnabled,
        public bool $adminDelegationEnabled,
        public string $status, // 'pending'|'connected'|'suspended'|'disconnected'
        public ?string $lastSeenAt,
        public ?string $lastConnectedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        /** @var mixed $rawId */
        $rawId = $row['id'] ?? null;
        /** @var mixed $rawName */
        $rawName = $row['name'] ?? null;
        /** @var mixed $rawUrl */
        $rawUrl = $row['url'] ?? null;
        /** @var mixed $rawPublicKey */
        $rawPublicKey = $row['public_key'] ?? null;
        /** @var mixed $rawRelayEnabled */
        $rawRelayEnabled = $row['relay_enabled'] ?? null;
        /** @var mixed $rawAdminDelegationEnabled */
        $rawAdminDelegationEnabled = $row['admin_delegation_enabled'] ?? null;
        /** @var mixed $rawStatus */
        $rawStatus = $row['status'] ?? null;

        return new self(
            id: is_string($rawId) ? $rawId : '',
            name: is_string($rawName) ? $rawName : '',
            url: is_string($rawUrl) ? $rawUrl : '',
            publicKey: is_string($rawPublicKey) ? $rawPublicKey : '',
            relayEnabled: is_int($rawRelayEnabled) ? $rawRelayEnabled === 1 : false,
            adminDelegationEnabled: is_int($rawAdminDelegationEnabled) ? $rawAdminDelegationEnabled === 1 : false,
            status: is_string($rawStatus) ? $rawStatus : 'pending',
            lastSeenAt: is_string($row['last_seen_at'] ?? null) ? $row['last_seen_at'] : null,
            lastConnectedAt: is_string($row['last_connected_at'] ?? null) ? $row['last_connected_at'] : null,
        );
    }
}
