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

    // ---- M-HUB-15: heartbeat library-list size caps at the HTTP boundary ----

    /**
     * Minimal valid heartbeat body; callers override `libraries`.
     *
     * @param list<array{library_id: string, library_name: string}> $libraries
     * @return array<string, mixed>
     */
    private function heartbeatBodyWithLibraries(array $libraries): array
    {
        return [
            'serverId' => 'srv-1',
            'version' => '1.0.0',
            'timestamp' => time(),
            'uptimeSeconds' => 100,
            'activeSessions' => 0,
            'activeTranscodes' => 0,
            'libraries' => $libraries,
        ];
    }

    /**
     * @param list<array{library_id: string, library_name: string}> $libraries
     */
    private function postHeartbeat(HeartbeatHandler $heartbeat, array $libraries): \Phlix\Hub\Http\Response
    {
        $controller = new ServerController(
            $heartbeat,
            $this->createMock(ServerInfoHandler::class),
            $this->createMock(DeregisterHandler::class),
            $this->createMock(RenewHandler::class),
        );

        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/servers/srv-1/heartbeat';
        $request->headers['Accept-Phlix-Protocol'] = 'v1';
        $request->serverId = 'srv-1';
        $request->bearerToken = 'enrollment-jwt';
        $request->body = $this->heartbeatBodyWithLibraries($libraries);

        return $controller->heartbeat($request, ['id' => 'srv-1']);
    }

    /**
     * M-HUB-15 pin: the boundary caps are schema-honest and generous. 36/255 are
     * the exact character widths of server_libraries.library_id CHAR(36) /
     * library_name VARCHAR(255) (migration 031); the count cap is 500 (a ~5x
     * headroom over any realistic single-server library count, documented).
     */
    public function testHeartbeatLibraryCapConstantsMatchSchema(): void
    {
        self::assertSame(500, HeartbeatHandler::MAX_LIBRARY_COUNT);
        self::assertSame(36, HeartbeatHandler::MAX_LIBRARY_ID_CHARS);
        self::assertSame(255, HeartbeatHandler::MAX_LIBRARY_NAME_CHARS);
    }

    public function testHeartbeatRejectsLibraryListOverCountCapWith400(): void
    {
        $libraries = [];
        for ($i = 0; $i <= HeartbeatHandler::MAX_LIBRARY_COUNT; $i++) {
            $libraries[] = ['library_id' => 'lib-' . $i, 'library_name' => 'Lib ' . $i];
        }

        $heartbeat = $this->createMock(HeartbeatHandler::class);
        $heartbeat->expects(self::never())->method('handle');

        $response = $this->postHeartbeat($heartbeat, $libraries);

        self::assertSame(400, $response->statusCode);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($response->body, true);
        // Envelope byte-shape follows the existing strict-DTO parse-failure arm:
        // 'error' => 'Bad Request' + descriptive 'message'. No new wire codes.
        self::assertSame('Bad Request', $decoded['error'] ?? null);
        self::assertIsString($decoded['message'] ?? null);
        self::assertStringContainsString('500 entries', (string) $decoded['message']);
    }

    public function testHeartbeatAcceptsLibraryListAtCountCap(): void
    {
        $libraries = [];
        for ($i = 0; $i < HeartbeatHandler::MAX_LIBRARY_COUNT; $i++) {
            $libraries[] = ['library_id' => 'lib-' . $i, 'library_name' => 'Lib ' . $i];
        }

        $heartbeat = $this->createMock(HeartbeatHandler::class);
        $heartbeat->expects(self::once())->method('handle');

        $response = $this->postHeartbeat($heartbeat, $libraries);

        self::assertSame(200, $response->statusCode);
    }

    public function testHeartbeatRejectsOversizedLibraryNameWith400(): void
    {
        $heartbeat = $this->createMock(HeartbeatHandler::class);
        $heartbeat->expects(self::never())->method('handle');

        $response = $this->postHeartbeat($heartbeat, [
            ['library_id' => 'lib-1', 'library_name' => str_repeat('x', 256)],
        ]);

        self::assertSame(400, $response->statusCode);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($response->body, true);
        self::assertSame('Bad Request', $decoded['error'] ?? null);
        $message = $decoded['message'] ?? null;
        self::assertIsString($message);
        self::assertStringContainsString('library_name', $message);
        self::assertStringContainsString('255', $message);
    }

    public function testHeartbeatRejectsOversizedLibraryIdWith400(): void
    {
        $heartbeat = $this->createMock(HeartbeatHandler::class);
        $heartbeat->expects(self::never())->method('handle');

        $response = $this->postHeartbeat($heartbeat, [
            ['library_id' => str_repeat('i', 37), 'library_name' => 'Movies'],
        ]);

        self::assertSame(400, $response->statusCode);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($response->body, true);
        $message = $decoded['message'] ?? null;
        self::assertIsString($message);
        self::assertStringContainsString('library_id', $message);
    }

    /**
     * The caps are MySQL-character counts (utf8mb4 CHAR/VARCHAR measure chars),
     * not byte counts: a 255-cJK-char name is 765 bytes and stays legal;
     * 256 chars crosses the line.
     */
    public function testHeartbeatLibraryNameCapCountsCharactersNotBytes(): void
    {
        $atLimit = $this->createMock(HeartbeatHandler::class);
        $atLimit->expects(self::once())->method('handle');
        self::assertSame(200, $this->postHeartbeat($atLimit, [
            ['library_id' => 'lib-1', 'library_name' => str_repeat('あ', 255)],
        ])->statusCode);

        $overLimit = $this->createMock(HeartbeatHandler::class);
        $overLimit->expects(self::never())->method('handle');
        self::assertSame(400, $this->postHeartbeat($overLimit, [
            ['library_id' => 'lib-1', 'library_name' => str_repeat('あ', 256)],
        ])->statusCode);
    }
}
