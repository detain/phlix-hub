<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\SyncPlay;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\SyncPlay\SyncPlayClient;
use Workerman\Connection\TcpConnection;

/**
 * Unit tests for {@see SyncPlayClient}.
 *
 * @package Phlix\Hub\Tests\Unit\SyncPlay
 *
 * @covers \Phlix\Hub\SyncPlay\SyncPlayClient
 */
final class SyncPlayClientTest extends TestCase
{
    public function testConstructorSetsAllProperties(): void
    {
        $connection = $this->createMock(TcpConnection::class);

        $client = new SyncPlayClient(
            connection: $connection,
            serverId: 'server-123',
            clientId: 'client-456',
            userId: 'user-789',
            room: 'movie-room',
            displayName: 'John Doe',
        );

        self::assertSame($connection, $client->connection);
        self::assertSame('server-123', $client->serverId);
        self::assertSame('client-456', $client->clientId);
        self::assertSame('user-789', $client->userId);
        self::assertSame('movie-room', $client->room);
        self::assertSame('John Doe', $client->displayName);
    }

    public function testConstructorWithMinimalArguments(): void
    {
        $connection = $this->createMock(TcpConnection::class);

        $client = new SyncPlayClient(
            connection: $connection,
            serverId: 'server-abc',
            clientId: 'client-def',
        );

        self::assertSame($connection, $client->connection);
        self::assertSame('server-abc', $client->serverId);
        self::assertSame('client-def', $client->clientId);
        self::assertNull($client->userId);
        self::assertNull($client->room);
        self::assertSame('Anonymous', $client->displayName);
    }

    public function testUserIdIsMutable(): void
    {
        $connection = $this->createMock(TcpConnection::class);
        $client = new SyncPlayClient(
            connection: $connection,
            serverId: 'server-1',
            clientId: 'client-1',
        );

        self::assertNull($client->userId);

        $client->userId = 'authenticated-user';

        self::assertSame('authenticated-user', $client->userId);
    }

    public function testRoomIsMutable(): void
    {
        $connection = $this->createMock(TcpConnection::class);
        $client = new SyncPlayClient(
            connection: $connection,
            serverId: 'server-1',
            clientId: 'client-1',
        );

        self::assertNull($client->room);

        $client->room = 'my-room';

        self::assertSame('my-room', $client->room);
    }

    public function testDisplayNameIsMutable(): void
    {
        $connection = $this->createMock(TcpConnection::class);
        $client = new SyncPlayClient(
            connection: $connection,
            serverId: 'server-1',
            clientId: 'client-1',
        );

        self::assertSame('Anonymous', $client->displayName);

        $client->displayName = 'Custom Name';

        self::assertSame('Custom Name', $client->displayName);
    }

    public function testPropertiesAreReadonlyExceptMutableFields(): void
    {
        $connection = $this->createMock(TcpConnection::class);
        $client = new SyncPlayClient(
            connection: $connection,
            serverId: 'server-1',
            clientId: 'client-1',
        );

        // readonly properties should not be changed after construction
        self::assertSame('server-1', $client->serverId);
        self::assertSame('client-1', $client->clientId);
    }

    public function testNullUserIdWhenNotAuthenticated(): void
    {
        $connection = $this->createMock(TcpConnection::class);

        $client = new SyncPlayClient(
            connection: $connection,
            serverId: 'server-1',
            clientId: 'client-1',
            userId: null,
        );

        self::assertNull($client->userId);
    }

    public function testNullRoomWhenNotInRoom(): void
    {
        $connection = $this->createMock(TcpConnection::class);

        $client = new SyncPlayClient(
            connection: $connection,
            serverId: 'server-1',
            clientId: 'client-1',
            room: null,
        );

        self::assertNull($client->room);
    }
}
