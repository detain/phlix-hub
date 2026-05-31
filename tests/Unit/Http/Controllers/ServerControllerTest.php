<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use Phlix\Hub\Hub\DeregisterHandler;
use Phlix\Hub\Hub\HeartbeatHandler;
use Phlix\Hub\Hub\RenewHandler;
use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\Http\Controllers\ServerController;
use Phlix\Hub\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see ServerController}.
 *
 * @package Phlix\Hub\Tests\Unit\Http\Controllers
 *
 * @covers \Phlix\Hub\Http\Controllers\ServerController
 */
final class ServerControllerTest extends TestCase
{
    /**
     * Build a controller with the given (optional) renew handler; other
     * handlers are mocked.
     */
    private function makeController(?RenewHandler $renew = null): ServerController
    {
        $heartbeat = $this->createMock(HeartbeatHandler::class);
        $serverInfo = $this->createMock(ServerInfoHandler::class);
        $deregister = $this->createMock(DeregisterHandler::class);
        $renew ??= $this->createMock(RenewHandler::class);

        return new ServerController($heartbeat, $serverInfo, $deregister, $renew);
    }

    public function testHeartbeatRejectsWrongProtocolHeader(): void
    {
        $controller = $this->makeController();

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
        $controller = $this->makeController();

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
        $controller = $this->makeController();

        $request = new Request();
        $request->method = 'GET';
        $request->serverId = 'srv-wrong';

        $response = $controller->info($request, ['id' => 'srv-1']);

        self::assertSame(403, $response->statusCode);
    }

    public function testDisconnectReturns403OnServerIdMismatch(): void
    {
        $controller = $this->makeController();

        $request = new Request();
        $request->method = 'DELETE';
        $request->serverId = 'srv-wrong';

        $response = $controller->disconnect($request, ['id' => 'srv-1']);

        self::assertSame(403, $response->statusCode);
    }

    public function testRenewRejectsWrongProtocolHeader(): void
    {
        $controller = $this->makeController();

        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/servers/srv-1/renew';
        $request->headers['Accept-Phlix-Protocol'] = 'v2';

        $response = $controller->renew($request, ['id' => 'srv-1']);

        self::assertSame(400, $response->statusCode);
        self::assertStringContainsString('HUB_PROTOCOL_UNSUPPORTED', $response->body);
    }

    public function testRenewReturns403OnServerIdMismatch(): void
    {
        $controller = $this->makeController();

        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/servers/srv-1/renew';
        $request->headers['Accept-Phlix-Protocol'] = 'v1';
        $request->serverId = 'srv-2';

        $response = $controller->renew($request, ['id' => 'srv-1']);

        self::assertSame(403, $response->statusCode);
        self::assertStringContainsString('AUTHORIZATION_FAILED', $response->body);
    }

    public function testRenewReturnsFreshJwtAndExpiresIn(): void
    {
        $renew = $this->createMock(RenewHandler::class);
        $renew->method('handle')
            ->with('srv-1', 'current-jwt')
            ->willReturn('fresh-jwt');
        $controller = $this->makeController($renew);

        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/servers/srv-1/renew';
        $request->headers['Accept-Phlix-Protocol'] = 'v1';
        $request->serverId = 'srv-1';
        $request->bearerToken = 'current-jwt';

        $response = $controller->renew($request, ['id' => 'srv-1']);

        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('fresh-jwt', $response->body);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($response->body, true);
        self::assertSame('fresh-jwt', $decoded['enrollment_jwt']);
        self::assertSame(604800, $decoded['expires_in']);
    }

    public function testRenewReturns401OnExpiredToken(): void
    {
        $renew = $this->createMock(RenewHandler::class);
        $renew->method('handle')
            ->willThrowException(new \InvalidArgumentException('ENROLLMENT_TOKEN_EXPIRED'));
        $controller = $this->makeController($renew);

        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/servers/srv-1/renew';
        $request->headers['Accept-Phlix-Protocol'] = 'v1';
        $request->serverId = 'srv-1';
        $request->bearerToken = 'expired-jwt';

        $response = $controller->renew($request, ['id' => 'srv-1']);

        self::assertSame(401, $response->statusCode);
        self::assertStringContainsString('ENROLLMENT_TOKEN_EXPIRED', $response->body);
    }

    public function testRenewReturns404OnServerNotFound(): void
    {
        $renew = $this->createMock(RenewHandler::class);
        $renew->method('handle')
            ->willThrowException(new \InvalidArgumentException('SERVER_NOT_FOUND'));
        $controller = $this->makeController($renew);

        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/servers/srv-1/renew';
        $request->headers['Accept-Phlix-Protocol'] = 'v1';
        $request->serverId = 'srv-1';
        $request->bearerToken = 'mismatch-jwt';

        $response = $controller->renew($request, ['id' => 'srv-1']);

        self::assertSame(404, $response->statusCode);
        self::assertStringContainsString('SERVER_NOT_FOUND', $response->body);
    }
}
