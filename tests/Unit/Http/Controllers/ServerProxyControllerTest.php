<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\Http\Controllers\ServerProxyController;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Relay\RelayProxyBridge;
use Phlix\Hub\Relay\RelayProxyManager;
use Phlix\Hub\Relay\TunnelManagerInterface;
use Phlix\Shared\Hub\ServerInfoDto;
use PHPUnit\Framework\TestCase;

use function base64_encode;
use function json_decode;

/**
 * @covers \Phlix\Hub\Http\Controllers\ServerProxyController
 */
final class ServerProxyControllerTest extends TestCase
{
    private function dto(
        string $userId,
        bool $relayActive,
        string $status = ServerInfoDto::STATUS_ONLINE,
    ): ServerInfoDto {
        return new ServerInfoDto(
            'srv-1',
            $userId,
            'My Server',
            '1.0.0',
            null,
            $status,
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

    /**
     * @param array<string, string> $headers
     */
    private function requestWith(string $method, ?string $userId, array $headers): Request
    {
        $req = $this->request($method, $userId);
        $req->headers = $headers;
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

    public function test_online_server_without_relay_tunnel_returns_503_relay_unavailable(): void
    {
        // status=online (heartbeating) but no open relay session → the tunnel
        // simply isn't connected. The proxy must still refuse (503) but with the
        // actionable `server.relay_unavailable` code so the UI can explain why.
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getServerInfo')->willReturn(
            $this->dto('user-1', false, ServerInfoDto::STATUS_ONLINE),
        );

        $forwarded = false;
        $controller = $this->controller($info, $this->bridge(static function (string $e, array $d) use (&$forwarded): void {
            $forwarded = true;
        }));

        $response = $controller->proxy($this->request('GET', 'user-1'), ['id' => 'srv-1', 'path' => 'api/v1/libraries']);

        $this->assertSame(503, $response->statusCode);
        $this->assertFalse($forwarded, 'No tunnel → nothing may be forwarded over the relay bridge.');
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame('server.relay_unavailable', $body['code'] ?? null);
    }

    public function test_offline_server_returns_503_server_offline(): void
    {
        // status != online (genuinely down) AND no relay session → the classic
        // "server is offline" case keeps its `server.offline` code.
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getServerInfo')->willReturn(
            $this->dto('user-1', false, ServerInfoDto::STATUS_OFFLINE),
        );

        $forwarded = false;
        $controller = $this->controller($info, $this->bridge(static function (string $e, array $d) use (&$forwarded): void {
            $forwarded = true;
        }));

        $response = $controller->proxy($this->request('GET', 'user-1'), ['id' => 'srv-1', 'path' => 'api/v1/libraries']);

        $this->assertSame(503, $response->statusCode);
        $this->assertFalse($forwarded, 'Offline server → nothing may be forwarded over the relay bridge.');
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame('server.offline', $body['code'] ?? null);
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

    public function test_forged_trust_headers_are_overwritten_by_hub_values(): void
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
                'headers' => ['Content-Type' => 'application/json'],
                'body_b64' => base64_encode('{}'),
            ]);
        };
        $bridge = $this->bridge($publisher);

        $controller = $this->controller($info, $bridge);

        // Client tries to pre-set every trust/forwarding marker, in mixed case.
        $req = $this->requestWith('GET', 'user-1', [
            'Accept' => 'application/json',
            'X-Phlix-Relay' => 'evil',
            'X-Phlix-Relay-User' => 'admin-attacker',
            'x-phlix-relay-foo' => 'sneaky',
            'X-Forwarded-For' => '10.0.0.1',
            'x-forwarded-host' => 'attacker.example',
            'X-Real-IP' => '10.0.0.2',
        ]);
        $req->remoteIp = '203.0.113.7';

        $response = $controller->proxy($req, ['id' => 'srv-1', 'path' => 'api/v1/libraries']);

        $this->assertSame(200, $response->statusCode);
        $this->assertIsArray($forwarded);
        /** @var array<string, string> $fwdHeaders */
        $fwdHeaders = $forwarded['headers'];

        // Trust markers reflect the hub's authenticated values, not the client's.
        $this->assertSame('1', $fwdHeaders['X-Phlix-Relay'] ?? null);
        $this->assertSame('user-1', $fwdHeaders['X-Phlix-Relay-User'] ?? null);
        $this->assertSame('203.0.113.7', $fwdHeaders['X-Forwarded-For'] ?? null);

        // No client-supplied trust/forwarding header survives in any case.
        $this->assertArrayNotHasKey('x-phlix-relay-foo', $fwdHeaders);
        $this->assertArrayNotHasKey('x-forwarded-host', $fwdHeaders);
        $this->assertArrayNotHasKey('X-Real-IP', $fwdHeaders);
        $this->assertArrayNotHasKey('x-real-ip', $fwdHeaders);
        // The hub value 'admin-attacker' must never appear.
        $this->assertNotSame('admin-attacker', $fwdHeaders['X-Phlix-Relay-User'] ?? null);
    }

    public function test_disallowed_method_path_returns_403_and_is_not_forwarded(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getServerInfo')->willReturn($this->dto('user-1', true));

        $forwarded = false;
        $controller = $this->controller($info, $this->bridge(static function (string $e, array $d) use (&$forwarded): void {
            $forwarded = true;
        }));

        $response = $controller->proxy(
            $this->request('POST', 'user-1'),
            ['id' => 'srv-1', 'path' => 'api/v1/admin/users'],
        );

        $this->assertSame(403, $response->statusCode);
        $this->assertFalse($forwarded, 'Out-of-scope request must not reach the relay bridge.');
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame('proxy.scope_denied', $body['code'] ?? null);
    }

    public function test_disallowed_admin_get_returns_403(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getServerInfo')->willReturn($this->dto('user-1', true));

        $forwarded = false;
        $controller = $this->controller($info, $this->bridge(static function (string $e, array $d) use (&$forwarded): void {
            $forwarded = true;
        }));

        $response = $controller->proxy(
            $this->request('GET', 'user-1'),
            ['id' => 'srv-1', 'path' => 'api/v1/admin/dashboard'],
        );

        $this->assertSame(403, $response->statusCode);
        $this->assertFalse($forwarded);
    }

    public function test_allowed_browse_subpath_is_forwarded(): void
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
                'headers' => ['Content-Type' => 'application/json'],
                'body_b64' => base64_encode('{"id":"abc"}'),
            ]);
        };
        $bridge = $this->bridge($publisher);

        $controller = $this->controller($info, $bridge);
        $response = $controller->proxy(
            $this->request('GET', 'user-1'),
            ['id' => 'srv-1', 'path' => 'api/v1/media/abc'],
        );

        $this->assertSame(200, $response->statusCode);
        $this->assertIsArray($forwarded);
        $this->assertSame('/api/v1/media/abc', $forwarded['path']);
    }

    public function test_sibling_prefix_is_not_treated_as_in_scope(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getServerInfo')->willReturn($this->dto('user-1', true));

        $forwarded = false;
        $controller = $this->controller($info, $this->bridge(static function (string $e, array $d) use (&$forwarded): void {
            $forwarded = true;
        }));

        // `/api/v1/mediaXYZ` shares a prefix with `/api/v1/media` but is a sibling.
        $response = $controller->proxy(
            $this->request('GET', 'user-1'),
            ['id' => 'srv-1', 'path' => 'api/v1/mediaXYZ'],
        );

        $this->assertSame(403, $response->statusCode);
        $this->assertFalse($forwarded);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function traversalPathProvider(): iterable
    {
        yield 'literal dot-dot into admin' => ['api/v1/libraries/../admin/users'];
        yield 'double literal dot-dot into admin' => ['api/v1/media/../../admin/dashboard'];
        yield 'percent-encoded dot-dot' => ['api/v1/media/%2e%2e/admin'];
        yield 'upper percent-encoded dot-dot' => ['api/v1/media/%2E%2E/admin'];
        yield 'encoded separator smuggling' => ['api/v1/libraries/..%2fadmin'];
        yield 'bare encoded separator' => ['api/v1/media%2fadmin'];
        yield 'trailing single dot' => ['api/v1/media/.'];
        yield 'back-slash separator' => ['api/v1/libraries\\..\\admin'];
    }

    /**
     * Dot-segment / traversal paths must be rejected with 403
     * proxy.scope_denied BEFORE the allowlist runs and must never be forwarded
     * over the relay bridge.
     *
     * @dataProvider traversalPathProvider
     */
    public function test_traversal_paths_return_403_and_are_not_forwarded(string $path): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getServerInfo')->willReturn($this->dto('user-1', true));

        $forwarded = false;
        $controller = $this->controller($info, $this->bridge(static function (string $e, array $d) use (&$forwarded): void {
            $forwarded = true;
        }));

        $response = $controller->proxy(
            $this->request('GET', 'user-1'),
            ['id' => 'srv-1', 'path' => $path],
        );

        $this->assertSame(403, $response->statusCode, "Traversal path must be denied: {$path}");
        $this->assertFalse($forwarded, "Traversal path must not reach the relay bridge: {$path}");
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame('proxy.scope_denied', $body['code'] ?? null);
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

    /**
     * Step B7: a stale `relay_active=1` (DB flag true) with NO live tunnel must
     * yield a FAST 503, not a forward that times out into a 504. The DB flag is
     * display-only; the authoritative liveness gate is the in-memory tunnel
     * registry, cross-checked by RelayProxyManager at admission. This wires the
     * full round-trip end to end: the controller forwards (relay_active=true),
     * the bridge publishes to a real RelayProxyManager whose tunnel registry is
     * EMPTY, and the manager replies 503 `server.no_tunnel` straight back —
     * proving the registry, not the stale flag, decides admission.
     */
    public function test_stale_relay_active_with_no_live_tunnel_returns_503_not_504(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        // DB says the server is online (stale flag) — but there is no tunnel.
        $info->method('getServerInfo')->willReturn($this->dto('user-1', true));

        // Empty in-memory tunnel registry: no live tunnel for any server.
        $tunnelManager = $this->createMock(TunnelManagerInterface::class);
        $tunnelManager->method('getTunnelForServer')->willReturn(null);

        $bridge = null;
        $proxyManager = null;
        // The publisher routes the HTTP-worker request into the relay-worker's
        // RelayProxyManager::onRequest (cross-process channel hop simulated
        // in-process); the manager's reply is fed back via the bridge.
        $publisher = function (string $event, array $data) use (&$bridge, &$proxyManager): void {
            /** @var RelayProxyManager $proxyManager */
            $proxyManager->onRequest($data);
        };
        $bridge = $this->bridge($publisher);
        $proxyManager = new RelayProxyManager(
            $tunnelManager,
            $this->createMock(StructuredLogger::class),
            30,
            // The manager publishes its reply onto the bridge's reply event,
            // which the bridge delivers to the waiting request coroutine.
            static function (string $event, array $data) use (&$bridge): void {
                /** @var RelayProxyBridge $bridge */
                $bridge->onReply($data);
            },
        );

        $controller = $this->controller($info, $bridge);
        $response = $controller->proxy(
            $this->request('GET', 'user-1'),
            ['id' => 'srv-1', 'path' => 'api/v1/libraries'],
        );

        // Fast 503 with the registry's authoritative code — never a 504.
        $this->assertSame(503, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame('server.no_tunnel', $body['code'] ?? null);
    }
}
