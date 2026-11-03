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
            ->with(self::stringContains('FROM federation_hubs'))
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

        $captured = [];
        $db->expects(self::exactly(2))
            ->method('query')
            ->willReturnCallback(function (string $sql, ?array $params = null) use (&$captured): array {
                $captured[] = [$sql, $params];
                return [];
            });

        $repo = new FederationHubRepository($db);
        $repo->ensureHubExists('New Hub', 'https://new.example.com', 'public-key-xyz');

        // Law (review Minor 44): the probe read is ORDERED (`ORDER BY id`) so
        // even an already-broken table yields a stable row choice, and the
        // create arm rides the FIXED sentinel id through ON DUPLICATE KEY
        // UPDATE — never a random UUID, never INSERT IGNORE. A drifted-URL
        // concurrent bootstrap collides on PRIMARY, not into a second row.
        self::assertStringContainsString('SELECT id FROM federation_hubs ORDER BY id LIMIT 1', $captured[0][0]);
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $captured[1][0]);
        self::assertStringNotContainsString('INSERT IGNORE', $captured[1][0]);
        self::assertStringContainsString('INSERT INTO federation_hubs', $captured[1][0]);
        self::assertSame(FederationHubRepository::HUB_ROW_ID, $captured[1][1]['id'] ?? null);
    }

    public function testEnsureHubExistsUpdatesWhenPresent(): void
    {
        $db = $this->createMock(Connection::class);

        $captured = [];
        $db->expects(self::exactly(2))
            ->method('query')
            ->willReturnCallback(function (string $sql, ?array $params = null) use (&$captured): array {
                $captured[] = [$sql, $params];
                if (str_contains($sql, 'SELECT id FROM federation_hubs')) {
                    return [['id' => 'existing-hub']];
                }
                return [];
            });

        $repo = new FederationHubRepository($db);
        $repo->ensureHubExists('Updated Hub', 'https://updated.example.com', 'new-public-key');

        // Law (review Minor 44): the update arm is KEYED to the row that was
        // actually found (`WHERE id = :id`) — the legacy statement rewrote
        // the WHOLE table, silently relabeling any drifted second row. The
        // found id (legacy rows carry random UUIDs referenced from
        // federation_peers.leaf_hub_id) is never re-minted.
        self::assertStringContainsString('UPDATE federation_hubs SET', $captured[1][0]);
        self::assertStringContainsString('WHERE id = :id', $captured[1][0]);
        self::assertSame('existing-hub', $captured[1][1]['id'] ?? null);
    }

    public function testEnsureHubExistsFallsBackToSentinelOnUnreadableRowId(): void
    {
        $db = $this->createMock(Connection::class);

        $captured = [];
        $db->expects(self::exactly(2))
            ->method('query')
            ->willReturnCallback(function (string $sql, ?array $params = null) use (&$captured): array {
                $captured[] = [$sql, $params];
                if (str_contains($sql, 'SELECT id FROM federation_hubs')) {
                    return [['id' => '']]; // unreadable key — defensive arm
                }
                return [];
            });

        $repo = new FederationHubRepository($db);
        $repo->ensureHubExists('Edge Hub', 'https://edge.example.com', 'edge-key');

        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $captured[1][0]);
        self::assertSame(FederationHubRepository::HUB_ROW_ID, $captured[1][1]['id'] ?? null);
    }

    public function testTheSentinelIdIsWellFormedForTheWire(): void
    {
        // The row id crosses the wire (federation_peers.leaf_hub_id binds a
        // PEER's own row id, and the controller UUID-pattern-checks incoming
        // bindings), so the sentinel must keep the canonical UUID shape.
        self::assertSame(
            1,
            preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                FederationHubRepository::HUB_ROW_ID,
            ),
            'HUB_ROW_ID must be a well-formed UUID v4 shape',
        );
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
                self::callback(function (string $sql) {
                    return str_contains($sql, 'FROM federation_peers')
                        && str_contains($sql, 'WHERE id = :id OR leaf_hub_id = :id')
                        && str_contains($sql, 'ORDER BY id = :id DESC');
                }),
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

    /**
     * C-1(3) regression: getPeerById must resolve BOTH id spaces — the local
     * row UUID and the peer-bound leaf_hub_id — so a leaf dialing
     * /relay/federation/{own federation_hubs.id} passes the master's gate.
     */
    public function testGetPeerByIdMatchesEitherIdSpace(): void
    {
        $db = $this->createMock(Connection::class);
        $expectedPeer = ['id' => 'peer-1', 'leaf_hub_id' => 'leaf-own-hub-id'];

        $db->expects(self::once())
            ->method('query')
            ->with(
                self::logicalAnd(
                    self::stringContains('WHERE id = :id'),
                    self::stringContains('OR leaf_hub_id = :id'),
                ),
                ['id' => 'leaf-own-hub-id'],
            )
            ->willReturn([$expectedPeer]);

        $repo = new FederationHubRepository($db);

        self::assertSame($expectedPeer, $repo->getPeerById('leaf-own-hub-id'));
    }

    /**
     * C-1(3): binding a peer to the hub-identity it reports for itself.
     */
    public function testSetPeerLeafHubIdUpdatesTheRow(): void
    {
        $db = $this->createMock(Connection::class);

        $db->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('SET leaf_hub_id = :leaf_hub_id WHERE id = :id'),
                ['leaf_hub_id' => 'leaf-own-hub-id', 'id' => 'peer-1'],
            )
            ->willReturn([]);

        $repo = new FederationHubRepository($db);
        $repo->setPeerLeafHubId('peer-1', 'leaf-own-hub-id');
    }

    /**
     * C-1(1) regression: the dial loop iterates ELIGIBLE peers (everything
     * but 'suspended'), not 'connected' ones — 'connected' is written only
     * after a HELLO-ACK over an already-dialed link, so a pending fresh peer
     * could never bootstrap and a disconnected peer never came back.
     */
    public function testGetDialablePeersExcludesOnlySuspended(): void
    {
        $db = $this->createMock(Connection::class);
        $peers = [
            ['id' => 'peer-1', 'status' => 'pending'],
            ['id' => 'peer-2', 'status' => 'disconnected'],
            ['id' => 'peer-3', 'status' => 'connected'],
        ];

        $db->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains("WHERE status <> :suspended"),
                ['suspended' => 'suspended'],
            )
            ->willReturn($peers);

        $repo = new FederationHubRepository($db);

        self::assertSame($peers, $repo->getDialablePeers());
    }

    /**
     * C-1(3): createPeer accepts an optional pre-bound remote hub identity
     * so first-contact dials already resolve at the master's gate.
     */
    public function testCreatePeerPersistsOptionalLeafHubId(): void
    {
        $db = $this->createMock(Connection::class);

        $db->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('INSERT INTO federation_peers (id, name, url, public_key, leaf_hub_id)'),
                self::callback(static fn (array $p): bool => $p['leaf_hub_id'] === 'prebound-hub-id'
                    && $p['id'] === 'peer-new'),
            )
            ->willReturn([]);

        $repo = new FederationHubRepository($db);
        $repo->createPeer('peer-new', 'New', 'https://new.example.com', 'pk', 'prebound-hub-id');
    }

    public function testGetPeerByUrlReturnsPeerWhenFound(): void
    {
        $db = $this->createMock(Connection::class);
        $expectedPeer = ['id' => 'peer-url', 'url' => 'https://peer.example.com'];

        $db->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('FROM federation_peers WHERE url'),
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
                self::stringContains('FROM federation_peers WHERE public_key_hash'),
                // Migration 050 law: the predicate is the byte-exact SHA-256
                // of the key, matching the generated column's stored value.
                ['public_key_hash' => hash('sha256', 'abc123')]
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
