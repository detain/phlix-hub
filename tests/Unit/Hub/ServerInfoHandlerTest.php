<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Shared\Hub\ServerInfoDto;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see ServerInfoHandler}.
 *
 * @package Phlix\Hub\Tests\Unit\Hub
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
        $now = time();
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([[
            'id' => 'server-1',
            'user_id' => 'user-1',
            'server_name' => 'My NAS',
            'version' => '0.11.0',
            'last_seen_at' => $now,
            'status' => 'online',
            'hostname_candidates_json' => '["https://192.168.1.100:32400"]',
            'created_at' => $now,
            'relay_active' => 1,
            'library_count' => 4,
        ]]);

        $handler = new ServerInfoHandler($db);
        $result = $handler->getServerInfo('server-1');

        self::assertInstanceOf(ServerInfoDto::class, $result);
        self::assertSame('server-1', $result->serverId);
        self::assertSame('user-1', $result->userId);
        self::assertSame('My NAS', $result->serverName);
        self::assertSame('0.11.0', $result->version);
        self::assertSame('online', $result->status);
        self::assertTrue($result->relayActive);
        self::assertSame($now, $result->lastSeenAt);
        self::assertSame(4, $result->libraryCount);
    }

    public function testGetServerInfoSelectsUnixTimestampAndLibraryCount(): void
    {
        $db = $this->createMock(Connection::class);
        $captured = '';
        $db->method('query')->willReturnCallback(
            function (string $sql) use (&$captured): array {
                $captured = $sql;
                return [];
            },
        );

        $handler = new ServerInfoHandler($db);
        $handler->getServerInfo('server-1');

        self::assertStringContainsString('UNIX_TIMESTAMP(s.last_seen_at) AS last_seen_at', $captured);
        self::assertStringContainsString('FROM server_libraries sl', $captured);
        self::assertStringContainsString('AS library_count', $captured);
    }

    /**
     * HB-1.4 [H-D3]: the proxy-admission / WS-mount gate query
     * `getOwnerAndStatus()` must be LEAN — it selects only the admission
     * fields (`id`, `user_id`, `status`) plus the fresh relay-active EXISTS
     * probe, and must NEVER run the heavy dashboard `COUNT(server_libraries)`
     * correlated subquery that `getServerInfo()` carries.
     */
    public function testGetOwnerAndStatusIsLeanWithNoLibraryCountSubquery(): void
    {
        $db = $this->createMock(Connection::class);
        $captured = '';
        $db->method('query')->willReturnCallback(
            function (string $sql) use (&$captured): array {
                $captured = $sql;
                return [];
            },
        );

        $handler = new ServerInfoHandler($db);
        $handler->getOwnerAndStatus('server-1');

        // Selects only the admission fields.
        self::assertStringContainsString('SELECT s.id, s.user_id, s.status', $captured);
        // Keeps the fresh relay-active liveness probe.
        self::assertStringContainsString('EXISTS(', $captured);
        // But NEVER the heavy dashboard COUNT(server_libraries) subquery.
        self::assertStringNotContainsString('COUNT(', $captured);
        self::assertStringNotContainsString('server_libraries', $captured);
        self::assertStringNotContainsString('library_count', $captured);
    }

    public function testGetOwnerAndStatusReturnsShapeWhenFound(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([[
            'id' => 'server-1',
            'user_id' => 'owner-9',
            'status' => 'online',
            'relay_active' => 1,
        ]]);

        $handler = new ServerInfoHandler($db);
        $owner = $handler->getOwnerAndStatus('server-1');

        self::assertSame(
            ['userId' => 'owner-9', 'status' => 'online', 'relayActive' => true],
            $owner,
        );
    }

    public function testGetOwnerAndStatusReturnsNullWhenNotFound(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $handler = new ServerInfoHandler($db);
        self::assertNull($handler->getOwnerAndStatus('nonexistent'));
    }

    public function testGetServersForUserSelectsUnixTimestampAndLibraryCount(): void
    {
        $db = $this->createMock(Connection::class);
        $captured = '';
        $db->method('query')->willReturnCallback(
            function (string $sql) use (&$captured): array {
                $captured = $sql;
                return [];
            },
        );

        $handler = new ServerInfoHandler($db);
        $handler->getServersForUser('user-1');

        self::assertStringContainsString('UNIX_TIMESTAMP(s.last_seen_at) AS last_seen_at', $captured);
        self::assertStringContainsString('LEFT JOIN server_libraries sl ON sl.server_id = s.id', $captured);
        self::assertStringContainsString('AS library_count', $captured);
    }

    public function testRowToDtoMapsNumericLastSeenAndLibraryCount(): void
    {
        $now = time();
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([[
            'id' => 'server-9',
            'user_id' => 'user-9',
            'server_name' => 'Mapped NAS',
            'version' => '1.0.0',
            'last_seen_at' => (string) $now,
            'status' => 'online',
            'hostname_candidates_json' => '[]',
            'created_at' => $now,
            'relay_active' => 1,
            'library_count' => '7',
        ]]);

        $handler = new ServerInfoHandler($db);
        $result = $handler->getServerInfo('server-9');

        self::assertInstanceOf(ServerInfoDto::class, $result);
        self::assertSame($now, $result->lastSeenAt);
        self::assertSame(7, $result->libraryCount);
    }

    public function testRowToDtoLeavesLibraryCountNullWhenAbsent(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([[
            'id' => 'server-x',
            'user_id' => 'user-x',
            'server_name' => 'No Libs',
            'version' => '1.0.0',
            'last_seen_at' => null,
            'status' => 'offline',
            'hostname_candidates_json' => '[]',
            'created_at' => time(),
            'relay_active' => 0,
        ]]);

        $handler = new ServerInfoHandler($db);
        $result = $handler->getServerInfo('server-x');

        self::assertInstanceOf(ServerInfoDto::class, $result);
        self::assertNull($result->libraryCount);
        self::assertNull($result->lastSeenAt);
    }

    public function testGetServerInfoMapsRelayInactive(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([[
            'id' => 'server-1',
            'user_id' => 'user-1',
            'server_name' => 'Idle NAS',
            'version' => '0.12.0',
            'last_seen_at' => time(),
            'status' => 'offline',
            'hostname_candidates_json' => '[]',
            'created_at' => time(),
            'relay_active' => 0,
        ]]);

        $handler = new ServerInfoHandler($db);
        $result = $handler->getServerInfo('server-1');

        self::assertInstanceOf(ServerInfoDto::class, $result);
        self::assertFalse($result->relayActive);
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

    /**
     * The `GET /api/v1/me/servers` list payload MUST expose both `status` and
     * `relayActive` so the SPA can gate the "Browse" button on a live tunnel
     * (relayActive) rather than on heartbeat liveness (status) alone — the two
     * are set by independent paths and can disagree.
     */
    public function testGetServersForUserPayloadExposesStatusAndRelayActive(): void
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
                // Heartbeating (status=online) but no open relay session.
                'relay_active' => 0,
                'library_count' => 3,
            ],
        ]);

        $handler = new ServerInfoHandler($db);
        $payload = $handler->getServersForUser('user-2')[0]->toPayload();

        self::assertArrayHasKey('status', $payload);
        self::assertArrayHasKey('relayActive', $payload);
        self::assertSame('online', $payload['status']);
        self::assertFalse($payload['relayActive']);
    }
}
