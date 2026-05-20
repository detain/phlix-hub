<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\unit\Http\Controllers;

use Phlix\Hub\Hub\DeregisterHandler;
use Phlix\Hub\Hub\HeartbeatHandler;
use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\Http\Controllers\ServerController;
use Phlix\Hub\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see ServerController}.
 *
 * @package Phlix\Hub\Tests\unit\Http\Controllers
 * @since 0.3.0
 *
 * @covers \Phlix\Hub\Http\Controllers\ServerController
 */
final class ServerControllerTest extends TestCase
{
    public function testHeartbeatRejectsWrongProtocolHeader(): void
    {
        $heartbeat = $this->createMock(HeartbeatHandler::class);
        $serverInfo = $this->createMock(ServerInfoHandler::class);
        $deregister = $this->createMock(DeregisterHandler::class);
        $controller = new ServerController($heartbeat, $serverInfo, $deregister);

        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/servers/srv-1/heartbeat';
        $request->headers['Accept-Phlix-Protocol'] = 'v2';
        $request->body = [];

        $response = $controller->heartbeat($request, ['id' => 'srv-1']);

        self::assertSame(400, $response->statusCode);
        self::assertStringContainsString('HUB_PROTOCOL_UNSUPPORTED', $response->body);
    }

    public function testHeartbeatReturns403OnServerIdMismatch(): void
    {
        $heartbeat = $this->createMock(HeartbeatHandler::class);
        $serverInfo = $this->createMock(ServerInfoHandler::class);
        $deregister = $this->createMock(DeregisterHandler::class);
        $controller = new ServerController($heartbeat, $serverInfo, $deregister);

        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/servers/srv-1/heartbeat';
        $request->headers['Accept-Phlix-Protocol'] = 'v1';
        $request->serverId = 'srv-2';
        $request->body = [];

        $response = $controller->heartbeat($request, ['id' => 'srv-1']);

        self::assertSame(403, $response->statusCode);
        self::assertStringContainsString('AUTHORIZATION_FAILED', $response->body);
    }

    public function testInfoReturns403OnServerIdMismatch(): void
    {
        $heartbeat = $this->createMock(HeartbeatHandler::class);
        $serverInfo = $this->createMock(ServerInfoHandler::class);
        $deregister = $this->createMock(DeregisterHandler::class);
        $controller = new ServerController($heartbeat, $serverInfo, $deregister);

        $request = new Request();
        $request->method = 'GET';
        $request->serverId = 'srv-wrong';

        $response = $controller->info($request, ['id' => 'srv-1']);

        self::assertSame(403, $response->statusCode);
    }

    public function testDisconnectReturns403OnServerIdMismatch(): void
    {
        $heartbeat = $this->createMock(HeartbeatHandler::class);
        $serverInfo = $this->createMock(ServerInfoHandler::class);
        $deregister = $this->createMock(DeregisterHandler::class);
        $controller = new ServerController($heartbeat, $serverInfo, $deregister);

        $request = new Request();
        $request->method = 'DELETE';
        $request->serverId = 'srv-wrong';

        $response = $controller->disconnect($request, ['id' => 'srv-1']);

        self::assertSame(403, $response->statusCode);
    }
}
