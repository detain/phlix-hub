<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\Http\Controllers\ServerManageController;
use Phlix\Hub\Http\Request;
use PHPUnit\Framework\TestCase;
use Phlix\Shared\Hub\ServerInfoDto;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see ServerManageController}.
 *
 * @package Phlix\Hub\Tests\Unit\Http\Controllers
 * @since 0.4.0
 *
 * @covers \Phlix\Hub\Http\Controllers\ServerManageController
 */
final class ServerManageControllerTest extends TestCase
{
    private function controller(ServerInfoHandler $serverInfo, Connection $db): ServerManageController
    {
        return new ServerManageController($serverInfo, $db);
    }

    public function testDeleteServerReturns401WhenUserIdMissing(): void
    {
        $serverInfo = $this->createMock(ServerInfoHandler::class);
        $db = $this->createMock(Connection::class);
        $ctrl = $this->controller($serverInfo, $db);

        $request = new Request();
        $response = $ctrl->deleteServer($request, ['id' => 'server-1']);

        self::assertSame(401, $response->statusCode);
        self::assertStringContainsString('auth.required', $response->body);
    }

    public function testDeleteServerReturns404WhenServerNotFound(): void
    {
        $serverInfo = $this->createMock(ServerInfoHandler::class);
        $serverInfo->method('getServerInfo')->with('server-no')->willReturn(null);

        $db = $this->createMock(Connection::class);
        $ctrl = $this->controller($serverInfo, $db);

        $request = new Request();
        $request->userId = 'user-1';

        $response = $ctrl->deleteServer($request, ['id' => 'server-no']);
        self::assertSame(404, $response->statusCode);
        self::assertStringContainsString('server.not_found', $response->body);
    }

    public function testDeleteOwnedServerReturns204(): void
    {
        $dto = new ServerInfoDto(
            serverId: 'server-1',
            userId: 'user-1',
            serverName: 'My NAS',
            version: '0.11.0',
            lastSeenAt: time(),
            status: 'online',
            hostnameCandidates: [],
            relayActive: false,
        );

        $serverInfo = $this->createMock(ServerInfoHandler::class);
        $serverInfo->method('getServerInfo')->with('server-1')->willReturn($dto);

        $db = $this->createMock(Connection::class);
        $db->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('DELETE FROM servers'),
                self::callback(fn ($args): bool => $args['id'] === 'server-1' && $args['user_id'] === 'user-1'),
            );

        $ctrl = $this->controller($serverInfo, $db);

        $request = new Request();
        $request->userId = 'user-1';

        $response = $ctrl->deleteServer($request, ['id' => 'server-1']);
        self::assertSame(204, $response->statusCode);
    }

    public function testDeleteOtherUsersServerReturns403(): void
    {
        $dto = new ServerInfoDto(
            serverId: 'server-1',
            userId: 'other-user',
            serverName: 'My NAS',
            version: '0.11.0',
            lastSeenAt: time(),
            status: 'online',
            hostnameCandidates: [],
            relayActive: false,
        );

        $serverInfo = $this->createMock(ServerInfoHandler::class);
        $serverInfo->method('getServerInfo')->with('server-1')->willReturn($dto);

        $db = $this->createMock(Connection::class);
        $ctrl = $this->controller($serverInfo, $db);

        $request = new Request();
        $request->userId = 'user-1';

        $response = $ctrl->deleteServer($request, ['id' => 'server-1']);
        self::assertSame(403, $response->statusCode);
        self::assertStringContainsString('server.not_owned', $response->body);
    }

    public function testGetAccessInfoReturns401WhenUserIdMissing(): void
    {
        $serverInfo = $this->createMock(ServerInfoHandler::class);
        $db = $this->createMock(Connection::class);
        $ctrl = $this->controller($serverInfo, $db);

        $request = new Request();
        $response = $ctrl->accessInfo($request, ['id' => 'server-1']);

        self::assertSame(401, $response->statusCode);
    }

    public function testGetAccessInfoReturns404WhenServerNotFound(): void
    {
        $serverInfo = $this->createMock(ServerInfoHandler::class);
        $serverInfo->method('getServerInfo')->with('server-no')->willReturn(null);

        $db = $this->createMock(Connection::class);
        $ctrl = $this->controller($serverInfo, $db);

        $request = new Request();
        $request->userId = 'user-1';

        $response = $ctrl->accessInfo($request, ['id' => 'server-no']);
        self::assertSame(404, $response->statusCode);
    }

    public function testGetAccessInfoReturns403WhenNotOwned(): void
    {
        $dto = new ServerInfoDto(
            serverId: 'server-1',
            userId: 'other-user',
            serverName: 'My NAS',
            version: '0.11.0',
            lastSeenAt: time(),
            status: 'online',
            hostnameCandidates: [],
            relayActive: false,
        );

        $serverInfo = $this->createMock(ServerInfoHandler::class);
        $serverInfo->method('getServerInfo')->with('server-1')->willReturn($dto);

        $db = $this->createMock(Connection::class);
        $ctrl = $this->controller($serverInfo, $db);

        $request = new Request();
        $request->userId = 'user-1';

        $response = $ctrl->accessInfo($request, ['id' => 'server-1']);
        self::assertSame(403, $response->statusCode);
    }

    public function testGetAccessInfoReturnsBestUrl(): void
    {
        $dto = new ServerInfoDto(
            serverId: 'server-1',
            userId: 'user-1',
            serverName: 'My NAS',
            version: '0.11.0',
            lastSeenAt: time(),
            status: 'online',
            hostnameCandidates: ['https://192.168.1.100:32400'],
            relayActive: false,
        );

        $serverInfo = $this->createMock(ServerInfoHandler::class);
        $serverInfo->method('getServerInfo')->with('server-1')->willReturn($dto);

        $db = $this->createMock(Connection::class);
        $ctrl = $this->controller($serverInfo, $db);

        $request = new Request();
        $request->userId = 'user-1';

        $response = $ctrl->accessInfo($request, ['id' => 'server-1']);
        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('192.168.1.100', $response->body);
        self::assertStringContainsString('relay_active', $response->body);
    }

    public function testGetAccessInfoPrefersDirectUrl(): void
    {
        $dto = new ServerInfoDto(
            serverId: 'server-2',
            userId: 'user-1',
            serverName: 'Remote Server',
            version: '0.12.0',
            lastSeenAt: time(),
            status: 'online',
            hostnameCandidates: [
                'https://example.com:32400',
                'https://192.168.1.50:32400',
            ],
            relayActive: false,
        );

        $serverInfo = $this->createMock(ServerInfoHandler::class);
        $serverInfo->method('getServerInfo')->with('server-2')->willReturn($dto);

        $db = $this->createMock(Connection::class);
        $ctrl = $this->controller($serverInfo, $db);

        $request = new Request();
        $request->userId = 'user-1';

        $response = $ctrl->accessInfo($request, ['id' => 'server-2']);
        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('https://example.com:32400', $response->body);
    }
}
