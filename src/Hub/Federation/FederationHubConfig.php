<?php

declare(strict_types=1);

namespace Phlix\Hub\Federation;

/**
 * DTO representing this hub's own configuration.
 *
 * @package Phlix\Hub\Federation
 */
final readonly class FederationHubConfig
{
    public function __construct(
        public string $id,
        public string $name,
        public string $url,
        public string $publicKey,
        public string $role,  // 'master'|'leaf'
        public bool $isMaster,
        public bool $isActive,
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
        /** @var mixed $rawRole */
        $rawRole = $row['role'] ?? null;
        /** @var mixed $rawIsMaster */
        $rawIsMaster = $row['is_master'] ?? null;
        /** @var mixed $rawIsActive */
        $rawIsActive = $row['is_active'] ?? null;

        return new self(
            id: is_string($rawId) ? $rawId : '',
            name: is_string($rawName) ? $rawName : '',
            url: is_string($rawUrl) ? $rawUrl : '',
            publicKey: is_string($rawPublicKey) ? $rawPublicKey : '',
            role: is_string($rawRole) ? $rawRole : 'leaf',
            isMaster: is_int($rawIsMaster) ? $rawIsMaster === 1 : (is_string($rawIsMaster) && $rawIsMaster === '1'),
            isActive: is_int($rawIsActive) ? $rawIsActive === 1 : (is_string($rawIsActive) && $rawIsActive === '1'),
        );
    }
}
