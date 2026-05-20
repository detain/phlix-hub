<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\unit\Hub;

use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Shared\Hub\ServerInfoDto;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see ServerInfoHandler}.
 *
 * @package Phlix\Hub\Tests\unit\Hub
 * @since 0.3.0
 *
 * @covers \Phlix\Hub\Hub\ServerInfoHandler
 */
final class ServerInfoHandlerTest extends TestCase
{
    public function testGetServerInfoReturnsNullWhenNotFound(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $handler = new ServerInfoHandler($db);
        $result = $handler->getServerInfo('nonexistent');
        self::assertNull($result);
    }

    public function testGetServerInfoReturnsDto(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([[
            'id' => 'server-1',
            'user_id' => 'user-1',
            'server_name' => 'My NAS',
            'version' => '0.11.0',
            'last_seen_at' => time(),
            'status' => 'online',
            'hostname_candidates_json' => '["https://192.168.1.100:32400"]',
            'created_at' => time(),
        ]]);

        $handler = new ServerInfoHandler($db);
        $result = $handler->getServerInfo('server-1');

        self::assertInstanceOf(ServerInfoDto::class, $result);
        self::assertSame('server-1', $result->serverId);
        self::assertSame('user-1', $result->userId);
        self::assertSame('My NAS', $result->serverName);
        self::assertSame('0.11.0', $result->version);
        self::assertSame('online', $result->status);
    }

    public function testGetServersForUserReturnsEmptyArray(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $handler = new ServerInfoHandler($db);
        $result = $handler->getServersForUser('user-no-servers');
        self::assertSame([], $result);
    }

    public function testGetServersForUserReturnsDtoList(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            [
                'id' => 'server-1',
                'user_id' => 'user-2',
                'server_name' => 'Server A',
                'version' => '0.11.0',
                'last_seen_at' => time(),
                'status' => 'online',
                'hostname_candidates_json' => '[]',
                'created_at' => time(),
            ],
            [
                'id' => 'server-2',
                'user_id' => 'user-2',
                'server_name' => 'Server B',
                'version' => '0.12.0',
                'last_seen_at' => null,
                'status' => 'offline',
                'hostname_candidates_json' => '[]',
                'created_at' => time(),
            ],
        ]);

        $handler = new ServerInfoHandler($db);
        $result = $handler->getServersForUser('user-2');

        self::assertCount(2, $result);
        self::assertSame('server-1', $result[0]->serverId);
        self::assertSame('server-2', $result[1]->serverId);
    }
}
