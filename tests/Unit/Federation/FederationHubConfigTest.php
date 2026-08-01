<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Federation;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\Federation\FederationHubConfig;

/**
 * Unit tests for {@see FederationHubConfig}.
 *
 * @package Phlix\Hub\Tests\Unit\Federation
 *
 * @covers \Phlix\Hub\Federation\FederationHubConfig
 */
final class FederationHubConfigTest extends TestCase
{
    public function testFromRowCreatesDtoFromDatabaseRow(): void
    {
        $row = [
            'id' => 'hub-123',
            'name' => 'My Hub',
            'url' => 'https://hub.example.com',
            'public_key' => 'ed25519-public-key-xyz',
            'role' => 'master',
            'is_master' => 1,
            'is_active' => 1,
        ];

        $config = FederationHubConfig::fromRow($row);

        self::assertSame('hub-123', $config->id);
        self::assertSame('My Hub', $config->name);
        self::assertSame('https://hub.example.com', $config->url);
        self::assertSame('ed25519-public-key-xyz', $config->publicKey);
        self::assertSame('master', $config->role);
        self::assertTrue($config->isMaster);
        self::assertTrue($config->isActive);
    }

    public function testFromRowHandlesStringBooleanFlags(): void
    {
        $row = [
            'id' => 'hub-456',
            'name' => 'Leaf Hub',
            'url' => 'https://leaf.example.com',
            'public_key' => 'key',
            'role' => 'leaf',
            'is_master' => '0',
            'is_active' => '1',
        ];

        $config = FederationHubConfig::fromRow($row);

        self::assertFalse($config->isMaster);
        self::assertTrue($config->isActive);
    }

    public function testFromRowHandlesZeroBooleanFlags(): void
    {
        $row = [
            'id' => 'hub-789',
            'name' => 'Inactive Hub',
            'url' => 'https://inactive.example.com',
            'public_key' => 'key',
            'role' => 'leaf',
            'is_master' => 0,
            'is_active' => 0,
        ];

        $config = FederationHubConfig::fromRow($row);

        self::assertFalse($config->isMaster);
        self::assertFalse($config->isActive);
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

    public function testFromRowHandlesNonBooleanNonStringValues(): void
    {
        $row = [
            'id' => 'hub-test',
            'name' => 'Test Hub',
            'url' => 'https://test.example.com',
            'public_key' => 'key',
            'role' => 'master',
            'is_master' => 'yes',  // invalid string
            'is_active' => 'yes',   // invalid string
        ];

        $config = FederationHubConfig::fromRow($row);

        // String 'yes' is not '1', so should be false
        self::assertFalse($config->isMaster);
        self::assertFalse($config->isActive);
    }

    public function testToArrayReturnsCorrectStructure(): void
    {
        $row = [
            'id' => 'hub-array',
            'name' => 'Array Hub',
            'url' => 'https://array.example.com',
            'public_key' => 'test-key',
            'role' => 'master',
            'is_master' => 1,
            'is_active' => 1,
        ];

        $config = FederationHubConfig::fromRow($row);
        $array = $config->toArray();

        self::assertSame('hub-array', $array['id']);
        self::assertSame('Array Hub', $array['name']);
        self::assertSame('https://array.example.com', $array['url']);
        self::assertSame('test-key', $array['public_key']);
        self::assertSame('master', $array['role']);
        self::assertTrue($array['is_master']);
        self::assertTrue($array['is_active']);
    }

    public function testToArrayWithLeafRole(): void
    {
        $row = [
            'id' => 'hub-leaf',
            'name' => 'Leaf Hub',
            'url' => 'https://leaf.example.com',
            'public_key' => 'leaf-key',
            'role' => 'leaf',
            'is_master' => 0,
            'is_active' => 1,
        ];

        $config = FederationHubConfig::fromRow($row);
        $array = $config->toArray();

        self::assertSame('leaf', $array['role']);
        self::assertFalse($array['is_master']);
        self::assertTrue($array['is_active']);
    }
}
