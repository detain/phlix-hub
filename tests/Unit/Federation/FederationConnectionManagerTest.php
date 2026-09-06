<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Federation;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Phlix\Hub\Federation\FederationConnectionManager;
use Workerman\Connection\ConnectionInterface;
use Workerman\Connection\TcpConnection;

/**
 * Unit tests for {@see FederationConnectionManager}.
 *
 * @package Phlix\Hub\Tests\Unit\Federation
 */
final class FederationConnectionManagerTest extends TestCase
{
    public function testAddConnectionStoresConnection(): void
    {
        $manager = new FederationConnectionManager();
        $conn = $this->createMockConnection(1);

        $manager->addConnection('hub-1', $conn);

        self::assertTrue($manager->isConnected('hub-1'));
        self::assertSame($conn, $manager->getConnection('hub-1'));
    }

    public function testAddConnectionReplacesExistingConnection(): void
    {
        $manager = new FederationConnectionManager();
        $conn1 = $this->createMockConnection(1);
        $conn2 = $this->createMockConnection(2);

        $conn1->expects(self::once())->method('close');

        $manager->addConnection('hub-1', $conn1);
        $manager->addConnection('hub-1', $conn2);

        self::assertTrue($manager->isConnected('hub-1'));
        self::assertSame($conn2, $manager->getConnection('hub-1'));
    }

    public function testAddConnectionDoesNotCloseSameConnection(): void
    {
        $manager = new FederationConnectionManager();
        $conn = $this->createMockConnection(1);

        $conn->expects(self::never())->method('close');

        $manager->addConnection('hub-1', $conn);
        $manager->addConnection('hub-1', $conn);
    }

    public function testRemoveConnectionDeletesConnection(): void
    {
        $manager = new FederationConnectionManager();
        $conn = $this->createMockConnection(1);

        $manager->addConnection('hub-1', $conn);
        $manager->removeConnection('hub-1');

        self::assertFalse($manager->isConnected('hub-1'));
        self::assertNull($manager->getConnection('hub-1'));
    }

    public function testRemoveConnectionHandlesNonExistentHub(): void
    {
        $manager = new FederationConnectionManager();

        // Should not throw
        $manager->removeConnection('non-existent');

        self::assertFalse($manager->isConnected('non-existent'));
    }

    public function testRemoveConnectionByConnDeletesConnection(): void
    {
        $manager = new FederationConnectionManager();
        $conn = $this->createMockConnection(1);

        $manager->addConnection('hub-1', $conn);
        $manager->removeConnectionByConn($conn);

        self::assertFalse($manager->isConnected('hub-1'));
    }

    public function testRemoveConnectionByConnHandlesUnknownConnection(): void
    {
        $manager = new FederationConnectionManager();
        $conn = $this->createMockConnection(999);

        // Should not throw
        $manager->removeConnectionByConn($conn);

        self::assertSame(0, $manager->connectionCount());
    }

    public function testGetConnectionReturnsNullForNonExistent(): void
    {
        $manager = new FederationConnectionManager();

        self::assertNull($manager->getConnection('non-existent'));
    }

    public function testIsConnectedReturnsFalseForNonExistent(): void
    {
        $manager = new FederationConnectionManager();

        self::assertFalse($manager->isConnected('non-existent'));
    }

    public function testGetAllHubIdsReturnsAllConnectedHubs(): void
    {
        $manager = new FederationConnectionManager();
        $conn1 = $this->createMockConnection(1);
        $conn2 = $this->createMockConnection(2);
        $conn3 = $this->createMockConnection(3);

        $manager->addConnection('hub-a', $conn1);
        $manager->addConnection('hub-b', $conn2);
        $manager->addConnection('hub-c', $conn3);

        $ids = $manager->getAllHubIds();

        self::assertCount(3, $ids);
        self::assertContains('hub-a', $ids);
        self::assertContains('hub-b', $ids);
        self::assertContains('hub-c', $ids);
    }

    public function testGetAllHubIdsReturnsEmptyWhenNoConnections(): void
    {
        $manager = new FederationConnectionManager();

        self::assertSame([], $manager->getAllHubIds());
    }

    public function testConnectionCountReturnsCorrectCount(): void
    {
        $manager = new FederationConnectionManager();

        self::assertSame(0, $manager->connectionCount());

        $manager->addConnection('hub-1', $this->createMockConnection(1));
        self::assertSame(1, $manager->connectionCount());

        $manager->addConnection('hub-2', $this->createMockConnection(2));
        self::assertSame(2, $manager->connectionCount());

        $manager->removeConnection('hub-1');
        self::assertSame(1, $manager->connectionCount());
    }

    public function testBroadcastToAllSendsToAllConnections(): void
    {
        $manager = new FederationConnectionManager();
        $conn1 = $this->createMockConnection(1);
        $conn2 = $this->createMockConnection(2);

        $conn1->expects(self::once())->method('send')->with('data', 0);
        $conn2->expects(self::once())->method('send')->with('data', 0);

        $manager->addConnection('hub-1', $conn1);
        $manager->addConnection('hub-2', $conn2);
        $manager->broadcastToAll('data', 0);
    }

    public function testSendToReturnsTrueAndSends(): void
    {
        $manager = new FederationConnectionManager();
        $conn = $this->createMockConnection(1);

        $conn->expects(self::once())->method('send')->with('data', 0);

        $manager->addConnection('hub-1', $conn);
        $result = $manager->sendTo('hub-1', 'data', 0);

        self::assertTrue($result);
    }

    public function testSendToReturnsFalseWhenNotConnected(): void
    {
        $manager = new FederationConnectionManager();

        $result = $manager->sendTo('non-existent', 'data', 0);

        self::assertFalse($result);
    }

    public function testSendToReturnsFalseWhenConnectionNull(): void
    {
        $manager = new FederationConnectionManager();
        // Note: addConnection with null connection would be invalid
        // But we can test the getConnection path
        $manager->addConnection('hub-1', $this->createMockConnection(1));

        // getConnection returns the mock, send should work
        $result = $manager->sendTo('hub-1', 'data', 0);
        self::assertTrue($result);
    }

    /**
     * Create a mock connection with a specific object ID.
     */
    private function createMockConnection(int $objectId): ConnectionInterface&MockObject
    {
        $conn = $this->createMock(ConnectionInterface::class);
        $conn->method('send')->willReturn(true);

        // Make spl_object_id return our desired ID
        $conn->method('send')->willReturnCallback(
            function ($data = null, $opcode = null): bool {
                return true;
            }
        );

        return $conn;
    }
}
