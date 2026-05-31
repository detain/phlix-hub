<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub\Federation;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\Federation\FederationHubConfig;

/**
 * Unit tests for {@see FederationHubConfig}.
 *
 * @package Phlix\Hub\Tests\Unit\Hub\Federation
 *
 * @covers \Phlix\Hub\Federation\FederationHubConfig
 */
final class FederationHubConfigTest extends TestCase
{
    public function testFromRowCreatesConfigFromDatabaseRow(): void
    {
        $row = [
            'id' => 'hub-123',
            'name' => 'My Hub',
            'url' => 'https://hub.example.com',
            'public_key' => 'ed25519-public-key-abc',
            'role' => 'leaf',
            'is_master' => 0,
            'is_active' => 1,
        ];

        $config = FederationHubConfig::fromRow($row);

        self::assertSame('hub-123', $config->id);
        self::assertSame('My Hub', $config->name);
        self::assertSame('https://hub.example.com', $config->url);
        self::assertSame('ed25519-public-key-abc', $config->publicKey);
        self::assertSame('leaf', $config->role);
        self::assertFalse($config->isMaster);
        self::assertTrue($config->isActive);
    }

    public function testFromRowHandlesMasterRole(): void
    {
        $row = [
            'id' => 'hub-master',
            'name' => 'Master Hub',
            'url' => 'https://master.example.com',
            'public_key' => 'master-key',
            'role' => 'master',
            'is_master' => 1,
            'is_active' => 1,
        ];

        $config = FederationHubConfig::fromRow($row);

        self::assertSame('master', $config->role);
        self::assertTrue($config->isMaster);
    }

    public function testFromRowHandlesInactiveHub(): void
    {
        $row = [
            'id' => 'hub-1',
            'name' => 'Inactive Hub',
            'url' => 'https://inactive.example.com',
            'public_key' => 'key',
            'role' => 'leaf',
            'is_master' => 0,
            'is_active' => 0,
        ];

        $config = FederationHubConfig::fromRow($row);

        self::assertFalse($config->isActive);
    }

    public function testFromRowHandlesStringBooleanFlags(): void
    {
        $row = [
            'id' => 'hub-1',
            'name' => 'Test Hub',
            'url' => 'https://test.example.com',
            'public_key' => 'key',
            'role' => 'leaf',
            'is_master' => '0',
            'is_active' => '1',
        ];

        $config = FederationHubConfig::fromRow($row);

        self::assertFalse($config->isMaster);
        self::assertTrue($config->isActive);
    }

    public function testFromRowHandlesMissingFields(): void
    {
        $row = [];

        $config = FederationHubConfig::fromRow($row);

        self::assertSame('', $config->id);
        self::assertSame('', $config->name);
        self::assertSame('', $config->url);
        self::assertSame('', $config->publicKey);
        self::assertSame('leaf', $config->role);
        self::assertFalse($config->isMaster);
        self::assertFalse($config->isActive);
    }

    public function testToArrayReturnsCorrectStructure(): void
    {
        $row = [
            'id' => 'hub-123',
            'name' => 'My Hub',
            'url' => 'https://hub.example.com',
            'public_key' => 'ed25519-public-key-abc',
            'role' => 'leaf',
            'is_master' => 0,
            'is_active' => 1,
        ];

        $config = FederationHubConfig::fromRow($row);
        $array = $config->toArray();

        self::assertSame('hub-123', $array['id']);
        self::assertSame('My Hub', $array['name']);
        self::assertSame('https://hub.example.com', $array['url']);
        self::assertSame('ed25519-public-key-abc', $array['public_key']);
        self::assertSame('leaf', $array['role']);
        self::assertFalse($array['is_master']);
        self::assertTrue($array['is_active']);
    }

    public function testToArrayHandlesMasterHub(): void
    {
        $row = [
            'id' => 'hub-master',
            'name' => 'Master Hub',
            'url' => 'https://master.example.com',
            'public_key' => 'master-key',
            'role' => 'master',
            'is_master' => 1,
            'is_active' => 1,
        ];

        $config = FederationHubConfig::fromRow($row);
        $array = $config->toArray();

        self::assertTrue($array['is_master']);
        self::assertTrue($array['is_active']);
    }
}
