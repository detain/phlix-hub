<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\Http\Controllers\ServerListController;
use Phlix\Hub\Http\Request;
use PHPUnit\Framework\TestCase;
use Phlix\Shared\Hub\ServerInfoDto;

/**
 * Unit tests for {@see ServerListController}.
 *
 * @package Phlix\Hub\Tests\Unit\Http\Controllers
 *
 * @covers \Phlix\Hub\Http\Controllers\ServerListController
 */
final class ServerListControllerTest extends TestCase
{
    public function testReturns401WhenUserIdMissing(): void
    {
        $serverInfo = $this->createMock(ServerInfoHandler::class);
        $controller = new ServerListController($serverInfo);

        $request = new Request();
        $response = $controller->listServers($request);

        self::assertSame(401, $response->statusCode);
        self::assertStringContainsString('auth.required', $response->body);
    }

    public function testReturnsServersForAuthenticatedUser(): void
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
        $serverInfo->method('getServersForUser')
            ->with('user-1')
            ->willReturn([$dto]);

        $controller = new ServerListController($serverInfo);

        $request = new Request();
        $request->userId = 'user-1';

        $response = $controller->listServers($request);
        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('"servers"', $response->body);
        self::assertStringContainsString('server-1', $response->body);
        self::assertStringContainsString('My NAS', $response->body);
    }

    public function testReturnsEmptyArrayWhenNoServers(): void
    {
        $serverInfo = $this->createMock(ServerInfoHandler::class);
        $serverInfo->method('getServersForUser')
            ->with('user-2')
            ->willReturn([]);

        $controller = new ServerListController($serverInfo);

        $request = new Request();
        $request->userId = 'user-2';

        $response = $controller->listServers($request);
        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('"servers": []', $response->body);
    }
}
