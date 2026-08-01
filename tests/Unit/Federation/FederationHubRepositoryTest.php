<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Federation;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\Federation\FederationHubRepository;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see FederationHubRepository}.
 *
 * @package Phlix\Hub\Tests\Unit\Federation
 *
 * @covers \Phlix\Hub\Federation\FederationHubRepository
 */
final class FederationHubRepositoryTest extends TestCase
{
    public function testGetHubConfigReturnsRowWhenExists(): void
    {
        $db = $this->createMock(Connection::class);
        $expectedConfig = [
            'id' => 'hub-1',
            'name' => 'Test Hub',
            'url' => 'https://hub.example.com',
            'role' => 'master',
        ];

        $db->expects(self::once())
            ->method('query')
            ->with(self::stringContains('SELECT * FROM federation_hubs'))
            ->willReturn([$expectedConfig]);

        $repo = new FederationHubRepository($db);
        $config = $repo->getHubConfig();

        self::assertSame($expectedConfig, $config);
    }

    public function testGetHubConfigReturnsNullWhenEmpty(): void
    {
        $db = $this->createMock(Connection::class);

        $db->expects(self::once())
            ->method('query')
            ->willReturn([]);

        $repo = new FederationHubRepository($db);
        $config = $repo->getHubConfig();

        self::assertNull($config);
    }

    public function testEnsureHubExistsCreatesNewWhenNotPresent(): void
    {
        $db = $this->createMock(Connection::class);

        $db->expects(self::exactly(2))
            ->method('query')
            ->willReturnCallback(function (string $sql) {
                if (str_contains($sql, 'SELECT id FROM federation_hubs')) {
                    return []; // No existing hub
                }
                if (str_contains($sql, 'INSERT IGNORE INTO federation_hubs')) {
                    return [];
                }
                return [];
            });

        $repo = new FederationHubRepository($db);
        $repo->ensureHubExists('New Hub', 'https://new.example.com', 'public-key-xyz');
    }

    public function testEnsureHubExistsUpdatesWhenPresent(): void
    {
        $db = $this->createMock(Connection::class);

        $db->expects(self::exactly(2))
            ->method('query')
            ->willReturnCallback(function (string $sql) {
                if (str_contains($sql, 'SELECT id FROM federation_hubs')) {
                    return [['id' => 'existing-hub']]; // Hub exists
                }
                if (str_contains($sql, 'UPDATE federation_hubs SET')) {
                    return [];
                }
                return [];
            });

        $repo = new FederationHubRepository($db);
        $repo->ensureHubExists('Updated Hub', 'https://updated.example.com', 'new-public-key');
    }

    public function testUpdateRoleUpdatesHubRole(): void
    {
        $db = $this->createMock(Connection::class);

        $db->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('UPDATE federation_hubs SET role'),
                ['role' => 'leaf']
            )
            ->willReturn([]);

        $repo = new FederationHubRepository($db);
        $repo->updateRole('leaf');
    }

    public function testUpdateActiveUpdatesIsActiveFlag(): void
    {
        $db = $this->createMock(Connection::class);

        $db->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('UPDATE federation_hubs SET is_active'),
                ['is_active' => 1]
            )
            ->willReturn([]);

        $repo = new FederationHubRepository($db);
        $repo->updateActive(true);
    }

    public function testUpdateActiveSetsZeroWhenFalse(): void
    {
        $db = $this->createMock(Connection::class);

        $db->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('UPDATE federation_hubs SET is_active'),
                ['is_active' => 0]
            )
            ->willReturn([]);

        $repo = new FederationHubRepository($db);
        $repo->updateActive(false);
    }

    public function testGetPeerByIdReturnsPeerWhenFound(): void
    {
        $db = $this->createMock(Connection::class);
        $expectedPeer = [
            'id' => 'peer-1',
            'name' => 'Peer Hub',
            'url' => 'https://peer.example.com',
        ];

        $db->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('SELECT * FROM federation_peers WHERE id'),
                ['id' => 'peer-1']
            )
            ->willReturn([$expectedPeer]);

        $repo = new FederationHubRepository($db);
        $peer = $repo->getPeerById('peer-1');

        self::assertSame($expectedPeer, $peer);
    }

    public function testGetPeerByIdReturnsNullWhenNotFound(): void
    {
        $db = $this->createMock(Connection::class);

        $db->expects(self::once())
            ->method('query')
            ->willReturn([]);

        $repo = new FederationHubRepository($db);
        $peer = $repo->getPeerById('non-existent');

        self::assertNull($peer);
    }

    public function testGetPeerByUrlReturnsPeerWhenFound(): void
    {
        $db = $this->createMock(Connection::class);
        $expectedPeer = ['id' => 'peer-url', 'url' => 'https://peer.example.com'];

        $db->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('SELECT * FROM federation_peers WHERE url'),
                ['url' => 'https://peer.example.com']
            )
            ->willReturn([$expectedPeer]);

        $repo = new FederationHubRepository($db);
        $peer = $repo->getPeerByUrl('https://peer.example.com');

        self::assertSame($expectedPeer, $peer);
    }

    public function testGetPeerByPublicKeyReturnsPeerWhenFound(): void
    {
        $db = $this->createMock(Connection::class);
        $expectedPeer = ['id' => 'peer-key', 'public_key' => 'abc123'];

        $db->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('SELECT * FROM federation_peers WHERE public_key'),
                ['public_key' => 'abc123']
            )
            ->willReturn([$expectedPeer]);

        $repo = new FederationHubRepository($db);
        $peer = $repo->getPeerByPublicKey('abc123');

        self::assertSame($expectedPeer, $peer);
    }

    public function testGetAllPeersReturnsAllPeers(): void
    {
        $db = $this->createMock(Connection::class);
        $peers = [
            ['id' => 'peer-1', 'name' => 'Alpha'],
            ['id' => 'peer-2', 'name' => 'Beta'],
        ];

        $db->expects(self::once())
            ->method('query')
            ->with(self::stringContains('SELECT p.*'))
            ->willReturn($peers);

        $repo = new FederationHubRepository($db);
        $result = $repo->getAllPeers();

        self::assertSame($peers, $result);
    }

    public function testGetConnectedPeersReturnsOnlyConnected(): void
    {
        $db = $this->createMock(Connection::class);
        $connectedPeers = [
            ['id' => 'peer-1', 'status' => 'connected'],
        ];

        $db->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains("WHERE status = :status"),
                ['status' => 'connected']
            )
            ->willReturn($connectedPeers);

        $repo = new FederationHubRepository($db);
        $result = $repo->getConnectedPeers();

        self::assertSame($connectedPeers, $result);
    }

    public function testCreatePeerInsertsNewPeer(): void
    {
        $db = $this->createMock(Connection::class);

        $db->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('INSERT INTO federation_peers'),
                self::callback(function (array $params) {
                    return $params['id'] === 'new-peer'
                        && $params['name'] === 'New Peer'
                        && $params['url'] === 'https://new.example.com'
                        && $params['public_key'] === 'new-key';
                })
            )
            ->willReturn([]);

        $repo = new FederationHubRepository($db);
        $repo->createPeer('new-peer', 'New Peer', 'https://new.example.com', 'new-key');
    }

    public function testUpdatePeerStatusUpdatesStatusAndTimestamps(): void
    {
        $db = $this->createMock(Connection::class);

        $db->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('UPDATE federation_peers'),
                ['id' => 'peer-xyz', 'status' => 'connected']
            )
            ->willReturn([]);

        $repo = new FederationHubRepository($db);
        $repo->updatePeerStatus('peer-xyz', 'connected');
    }

    public function testUpdatePeerTogglesUpdatesRelayAndAdminFlags(): void
    {
        $db = $this->createMock(Connection::class);

        $db->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('UPDATE federation_peers'),
                self::callback(function (array $params) {
                    return $params['id'] === 'peer-toggle'
                        && $params['relay_enabled'] === 1
                        && $params['admin_delegation_enabled'] === 0;
                })
            )
            ->willReturn([]);

        $repo = new FederationHubRepository($db);
        $repo->updatePeerToggles('peer-toggle', true, false);
    }

    public function testDeletePeerRemovesPeer(): void
    {
        $db = $this->createMock(Connection::class);

        $db->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('DELETE FROM federation_peers'),
                ['id' => 'peer-delete']
            )
            ->willReturn([]);

        $repo = new FederationHubRepository($db);
        $repo->deletePeer('peer-delete');
    }
}
