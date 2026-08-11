<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use Phlix\Hub\Auth\RateLimitException;
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

    public function testHeartbeatRateLimitedReturns429WithRetryAfter(): void
    {
        // The heartbeat handler trips its per-surface limiter and throws
        // RateLimitException; the local catch (before the generic
        // InvalidArgumentException mapping) must surface 429 + Retry-After,
        // NOT a 500 or a misclassified error.
        $resetAt = time() + 45;
        $heartbeat = $this->createMock(HeartbeatHandler::class);
        $heartbeat->method('handle')
            ->willThrowException(new RateLimitException(resetAt: $resetAt, remaining: 0));
        $serverInfo = $this->createMock(ServerInfoHandler::class);
        $deregister = $this->createMock(DeregisterHandler::class);
        $renew = $this->createMock(RenewHandler::class);
        $controller = new ServerController($heartbeat, $serverInfo, $deregister, $renew);

        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/servers/srv-1/heartbeat';
        $request->headers['Accept-Phlix-Protocol'] = 'v1';
        $request->serverId = 'srv-1';
        $request->bearerToken = 'enrollment-jwt';
        $request->body = [
            'serverId' => 'srv-1',
            'version' => '1.0.0',
            'timestamp' => time(),
            'uptimeSeconds' => 100,
            'activeSessions' => 0,
            'activeTranscodes' => 0,
        ];

        $response = $controller->heartbeat($request, ['id' => 'srv-1']);

        self::assertSame(429, $response->statusCode);
        self::assertArrayHasKey('Retry-After', $response->headers);
        $retryAfter = (int) $response->headers['Retry-After'];
        self::assertGreaterThan(0, $retryAfter);
        self::assertLessThanOrEqual(45, $retryAfter);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($response->body, true);
        self::assertSame('rate_limited', $decoded['code']);
        self::assertSame('Too Many Requests', $decoded['error']);
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
