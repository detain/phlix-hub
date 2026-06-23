<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\Http\Controllers\ServerProxyController;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Relay\RelayProxyBridge;
use Phlix\Shared\Hub\ServerInfoDto;
use PHPUnit\Framework\TestCase;

use function base64_encode;
use function json_decode;

/**
 * @covers \Phlix\Hub\Http\Controllers\ServerProxyController
 */
final class ServerProxyControllerTest extends TestCase
{
    private function dto(string $userId, bool $relayActive): ServerInfoDto
    {
        return new ServerInfoDto(
            'srv-1',
            $userId,
            'My Server',
            '1.0.0',
            null,
            ServerInfoDto::STATUS_ONLINE,
            [],
            $relayActive,
        );
    }

    private function request(string $method, ?string $userId): Request
    {
        $req = new Request();
        $req->method = $method;
        $req->path = '/api/v1/servers/srv-1/proxy/api/v1/libraries';
        $req->userId = $userId;
        $req->headers = ['Accept' => 'application/json'];
        return $req;
    }

    private function controller(ServerInfoHandler $info, RelayProxyBridge $bridge): ServerProxyController
    {
        return new ServerProxyController($info, $bridge, $this->createMock(StructuredLogger::class));
    }

    private function bridge(?callable $publisher): RelayProxyBridge
    {
        return new RelayProxyBridge($this->createMock(StructuredLogger::class), $publisher);
    }

    public function test_unauthenticated_returns_401(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $controller = $this->controller($info, $this->bridge(static fn () => null));

        $response = $controller->proxy($this->request('GET', null), ['id' => 'srv-1', 'path' => 'api/v1/libraries']);
        $this->assertSame(401, $response->statusCode);
    }

    public function test_unknown_server_returns_404(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getServerInfo')->willReturn(null);
        $controller = $this->controller($info, $this->bridge(static fn () => null));

        $response = $controller->proxy($this->request('GET', 'user-1'), ['id' => 'srv-1', 'path' => 'api/v1/libraries']);
        $this->assertSame(404, $response->statusCode);
    }

    public function test_not_owned_returns_403(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getServerInfo')->willReturn($this->dto('someone-else', true));
        $controller = $this->controller($info, $this->bridge(static fn () => null));

        $response = $controller->proxy($this->request('GET', 'user-1'), ['id' => 'srv-1', 'path' => 'api/v1/libraries']);
        $this->assertSame(403, $response->statusCode);
    }

    public function test_offline_server_returns_503(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getServerInfo')->willReturn($this->dto('user-1', false));
        $controller = $this->controller($info, $this->bridge(static fn () => null));

        $response = $controller->proxy($this->request('GET', 'user-1'), ['id' => 'srv-1', 'path' => 'api/v1/libraries']);
        $this->assertSame(503, $response->statusCode);
    }

    public function test_successful_proxy_returns_server_response(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getServerInfo')->willReturn($this->dto('user-1', true));

        /** @var array<string, mixed>|null $forwarded */
        $forwarded = null;
        $bridge = null;
        $publisher = function (string $event, array $data) use (&$bridge, &$forwarded): void {
            $forwarded = $data;
            /** @var RelayProxyBridge $bridge */
            $bridge->onReply([
                'request_id' => $data['request_id'],
                'status' => 200,
                'headers' => ['Content-Type' => 'application/json', 'Transfer-Encoding' => 'chunked'],
                'body_b64' => base64_encode('{"libraries":[]}'),
            ]);
        };
        $bridge = $this->bridge($publisher);

        $controller = $this->controller($info, $bridge);
        $response = $controller->proxy(
            $this->request('GET', 'user-1'),
            ['id' => 'srv-1', 'path' => 'api/v1/libraries'],
        );

        $this->assertSame(200, $response->statusCode);
        $this->assertSame('{"libraries":[]}', $response->body);
        // Content-Type passes through; hop-by-hop Transfer-Encoding is stripped.
        $this->assertSame('application/json', $response->headers['Content-Type'] ?? null);
        $this->assertArrayNotHasKey('Transfer-Encoding', $response->headers);

        // Forwarded request carries the relay trust markers, not the hub auth header.
        $this->assertIsArray($forwarded);
        $this->assertSame('/api/v1/libraries', $forwarded['path']);
        $this->assertSame('user-1', $forwarded['headers']['X-Phlix-Relay-User'] ?? null);
        $this->assertSame('1', $forwarded['headers']['X-Phlix-Relay'] ?? null);
    }

    public function test_timeout_returns_504(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getServerInfo')->willReturn($this->dto('user-1', true));

        // Publisher never replies → bridge times out → controller 504.
        $controller = $this->controller($info, $this->bridge(static function (string $e, array $d): void {
        }));

        $response = $controller->proxy(
            $this->request('GET', 'user-1'),
            ['id' => 'srv-1', 'path' => 'api/v1/libraries'],
        );
        $this->assertSame(504, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame('gateway.timeout', $body['code'] ?? null);
    }
}
