<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub\Federation;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\Federation\FederationHubRepository;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see FederationHubRepository}.
 *
 * @package Phlix\Hub\Tests\Unit\Hub\Federation
 *
 * @covers \Phlix\Hub\Federation\FederationHubRepository
 */
final class FederationHubRepositoryTest extends TestCase
{
    public function testGetAllPeersSelectsSharedLibraryCountSubquery(): void
    {
        $mockDb = $this->createMock(Connection::class);

        $mockDb->expects(self::once())
            ->method('query')
            ->willReturnCallback(function (string $sql): array {
                // Correlated subquery counting active shares per peer.
                self::assertStringContainsString('FROM federation_peers p', $sql);
                self::assertStringContainsString('SELECT COUNT(*) FROM federation_library_shares fls', $sql);
                self::assertStringContainsString('fls.peer_id = p.id', $sql);
                self::assertStringContainsString("fls.status = 'active'", $sql);
                self::assertStringContainsString('AS shared_library_count', $sql);
                self::assertStringContainsString('ORDER BY p.name', $sql);
                return [
                    [
                        'id' => 'peer-1',
                        'name' => 'Peer Hub A',
                        'url' => 'https://peer-a.example.com',
                        'public_key' => 'key-a',
                        'relay_enabled' => 1,
                        'admin_delegation_enabled' => 0,
                        'status' => 'connected',
                        'shared_library_count' => '4',
                    ],
                ];
            });

        $repo = new FederationHubRepository($mockDb);
        $peers = $repo->getAllPeers();

        self::assertCount(1, $peers);
        self::assertSame('peer-1', $peers[0]['id']);
        self::assertSame('4', $peers[0]['shared_library_count']);
    }

    public function testGetAllPeersReturnsEmptyWhenNoPeers(): void
    {
        $mockDb = $this->createMock(Connection::class);
        $mockDb->method('query')->willReturn([]);

        $repo = new FederationHubRepository($mockDb);

        self::assertSame([], $repo->getAllPeers());
    }
}
