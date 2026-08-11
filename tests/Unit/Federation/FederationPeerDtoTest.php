<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Federation;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\Federation\FederationPeerDto;

/**
 * Unit tests for {@see FederationPeerDto}.
 *
 * @package Phlix\Hub\Tests\Unit\Federation
 */
final class FederationPeerDtoTest extends TestCase
{
    public function testFromRowCreatesDtoFromDatabaseRow(): void
    {
        $row = [
            'id' => 'peer-123',
            'name' => 'Remote Hub',
            'url' => 'https://remote.example.com',
            'public_key' => 'ed25519-public-key-xyz',
            'relay_enabled' => 1,
            'admin_delegation_enabled' => 0,
            'status' => 'connected',
            'last_seen_at' => '2026-01-01 12:00:00',
            'last_connected_at' => '2026-01-01 10:00:00',
        ];

        $dto = FederationPeerDto::fromRow($row);

        self::assertSame('peer-123', $dto->id);
        self::assertSame('Remote Hub', $dto->name);
        self::assertSame('https://remote.example.com', $dto->url);
        self::assertSame('ed25519-public-key-xyz', $dto->publicKey);
        self::assertTrue($dto->relayEnabled);
        self::assertFalse($dto->adminDelegationEnabled);
        self::assertSame('connected', $dto->status);
        self::assertSame('2026-01-01 12:00:00', $dto->lastSeenAt);
        self::assertSame('2026-01-01 10:00:00', $dto->lastConnectedAt);
    }

    public function testFromRowHandlesStringBooleanFlags(): void
    {
        $row = [
            'id' => 'peer-456',
            'name' => 'Test Peer',
            'url' => 'https://test.example.com',
            'public_key' => 'key',
            'relay_enabled' => '1',
            'admin_delegation_enabled' => '1',
            'status' => 'pending',
            'last_seen_at' => null,
            'last_connected_at' => null,
        ];

        $dto = FederationPeerDto::fromRow($row);

        self::assertTrue($dto->relayEnabled);
        self::assertTrue($dto->adminDelegationEnabled);
    }

    public function testFromRowHandlesZeroBooleanFlags(): void
    {
        $row = [
            'id' => 'peer-789',
            'name' => 'Disabled Peer',
            'url' => 'https://disabled.example.com',
            'public_key' => 'key',
            'relay_enabled' => 0,
            'admin_delegation_enabled' => 0,
            'status' => 'disconnected',
            'last_seen_at' => null,
            'last_connected_at' => null,
        ];

        $dto = FederationPeerDto::fromRow($row);

        self::assertFalse($dto->relayEnabled);
        self::assertFalse($dto->adminDelegationEnabled);
    }

    public function testFromRowHandlesMissingFields(): void
    {
        $row = [];

        $dto = FederationPeerDto::fromRow($row);

        self::assertSame('', $dto->id);
        self::assertSame('', $dto->name);
        self::assertSame('', $dto->url);
        self::assertSame('', $dto->publicKey);
        self::assertFalse($dto->relayEnabled);
        self::assertFalse($dto->adminDelegationEnabled);
        self::assertSame('pending', $dto->status);
        self::assertNull($dto->lastSeenAt);
        self::assertNull($dto->lastConnectedAt);
    }

    public function testFromRowHandlesAllStatusValues(): void
    {
        foreach (['pending', 'connected', 'suspended', 'disconnected'] as $status) {
            $row = [
                'id' => 'peer-1',
                'name' => 'Peer',
                'url' => 'https://peer.example.com',
                'public_key' => 'key',
                'relay_enabled' => 1,
                'admin_delegation_enabled' => 1,
                'status' => $status,
                'last_seen_at' => null,
                'last_connected_at' => null,
            ];

            $dto = FederationPeerDto::fromRow($row);
            self::assertSame($status, $dto->status);
        }
    }

    public function testFromRowKeepsInvalidStatusAsIs(): void
    {
        $row = [
            'id' => 'peer-1',
            'name' => 'Peer',
            'url' => 'https://peer.example.com',
            'public_key' => 'key',
            'relay_enabled' => 0,
            'admin_delegation_enabled' => 0,
            'status' => 'invalid-status',
            'last_seen_at' => null,
            'last_connected_at' => null,
        ];

        $dto = FederationPeerDto::fromRow($row);

        // fromRow does not validate status - it keeps the string value as-is
        self::assertSame('invalid-status', $dto->status);
    }
}
