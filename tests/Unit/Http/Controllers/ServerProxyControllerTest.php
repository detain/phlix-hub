<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Common\RateLimit\RateLimiterInterface;
use Phlix\Hub\Common\RateLimit\RateLimitState;
use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\Http\Controllers\ServerProxyController;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Hub\Relay\FrameDecoder;
use Phlix\Hub\Relay\RelayProxyBridge;
use Phlix\Hub\Relay\RelayProxyManager;
use Phlix\Hub\Relay\Tunnel;
use Phlix\Hub\Relay\TunnelManagerInterface;
use Phlix\Shared\Hub\ServerInfoDto;
use Phlix\Shared\Relay\RelayFrame;
use Phlix\Shared\Relay\RelayFrameType;
use Phlix\Shared\Relay\RelayHttpRequest;
use Phlix\Shared\Relay\RelayHttpRequestChunk;
use Phlix\Shared\Relay\RelayHttpRequestCodec;
use Phlix\Shared\Relay\RelayHttpResponseCodec;
use Phlix\Shared\Relay\RelayHttpResponseHead;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Workerman\Connection\TcpConnection;

use function base64_encode;
use function count;
use function is_string;
use function json_decode;
use function json_encode;
use function ltrim;
use function ord;
use function str_repeat;
use function strlen;

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

    private function controller(ServerInfoHandler $info, RelayProxyBridge $bridge, ?RelaySessionManager $sessionManager = null): ServerProxyController
    {
        if ($sessionManager === null) {
            $sessionManager = $this->createMock(RelaySessionManager::class);
            // Default: quota check always allows (quota not exceeded), concurrent
            // cap unlimited (0). The concurrent cap is folded into checkUserQuota's
            // single row read (HB-3.4 hot-path fix), so it is returned here too.
            $sessionManager->method('checkUserQuota')->willReturn(
                ['allowed' => true, 'reason' => null, 'maxConcurrentStreams' => 0],
            );
        }
        // Default: rate limiter always returns non-limited state.
        $rateLimiter = $this->createMock(RateLimiterInterface::class);
        $rateLimiter->method('hit')->willReturn(new RateLimitState(
            count: 1,
            remaining: 4,
            resetAt: time() + 900,
            limited: false,
            limit: 5,
        ));
        return new ServerProxyController($info, $bridge, $this->createMock(StructuredLogger::class), $sessionManager, $rateLimiter);
    }

    private function bridge(?callable $publisher): RelayProxyBridge
    {
        return new RelayProxyBridge($this->createMock(StructuredLogger::class), $publisher);
    }

    /**
     * Build a live, ACTIVE relay tunnel whose server-side WebSocket records
     * every frame it sends. Mirrors the harness in RelayProxyManagerTest so the
     * controller test can drive a request all the way through a real
     * {@see RelayProxyManager} + tunnel (not a stub publisher).
     *
     * @param string       $serverId Tunnel's server id.
     * @param TcpConnection $serverWs Server-side WebSocket double.
     */
    private function activeTunnel(string $serverId, TcpConnection $serverWs): Tunnel
    {
        $sessionManager = $this->createMock(RelaySessionManager::class);
        $sessionManager->method('registerServer')->willReturn('sess-1');

        $tunnel = new Tunnel(
            $serverId,
            $serverWs,
            $sessionManager,
            new FrameDecoder(),
            $this->createMock(StructuredLogger::class),
        );
        $tunnel->onServerMessage((string) json_encode([
            'type' => 'hello',
            'enrollment_jwt' => 'a.b.c',
            'server_id' => $serverId,
        ]));

        return $tunnel;
    }

    /**
     * A test double for the browser connection that records everything written.
     * The real {@see TcpConnection} needs a live socket; this subclass skips the
     * parent constructor and captures {@see self::send()} payloads instead.
     */
    private function fakeConnection()
    {
        return new class extends TcpConnection {
            /** @var list<string> */
            public array $written = [];

            public function __construct()
            {
                // Intentionally skips the parent constructor: no live socket is
                // needed — send() just records what would go on the wire.
            }

            public function send(mixed $sendBuffer, bool $raw = false): bool
            {
                $this->written[] = (string) $sendBuffer;
                return true;
            }
        };
    }

    /**
     * Drive a streamed response: the controller returns a producer closure the
     * HTTP worker would invoke with the live connection. For streaming (`/hls`,
     * `/dash`, `/media`) paths this is where the request is actually forwarded
     * and the body written; for buffered paths it is a no-op. Returns the fake
     * connection the stream wrote to (no declared return type so PHPStan keeps
     * the anonymous class's `$written` property visible).
     */
    private function drive(Response $response)
    {
        $connection = $this->fakeConnection();
        if ($response->streamProducer !== null) {
            ($response->streamProducer)($connection);
        }
        return $connection;
    }

    public function test_unauthenticated_returns_401(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $controller = $this->controller($info, $this->bridge(static fn () => null));

        $response = $controller->proxy($this->request('GET', null), ['id' => 'srv-1', 'path' => 'api/v1/libraries']);
        $this->assertSame(401, $response->statusCode);
    }

    /**
     * HB-1.4 leanness [H-D3]: the per-request proxy-admission gate MUST use the
     * lean {@see ServerInfoHandler::getOwnerAndStatus()} query and MUST NOT run
     * the heavy dashboard-shaped {@see ServerInfoHandler::getServerInfo()}
     * (EXISTS + COUNT(server_libraries)) — that COUNT is paid on every one of
     * the dozens of segment requests per playback and admission never uses it.
     */
    public function test_proxy_uses_lean_owner_query_and_never_full_getServerInfo(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->expects(self::once())
            ->method('getOwnerAndStatus')
            ->with('srv-1')
            ->willReturn(['userId' => 'user-1', 'status' => 'online', 'relayActive' => true]);
        $info->expects(self::never())->method('getServerInfo');

        $bridge = null;
        $publisher = function (string $event, array $data) use (&$bridge): void {
            /** @var RelayProxyBridge $bridge */
            $bridge->onReply([
                'request_id' => $data['request_id'],
                'status' => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => '{}',
            ]);
        };
        $bridge = $this->bridge($publisher);

        $controller = $this->controller($info, $bridge);
        $response = $controller->proxy(
            $this->request('GET', 'user-1'),
            ['id' => 'srv-1', 'path' => 'api/v1/libraries'],
        );

        $this->assertSame(200, $response->statusCode);
    }

    public function test_unknown_server_returns_404(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn(null);
        $controller = $this->controller($info, $this->bridge(static fn () => null));

        $response = $controller->proxy($this->request('GET', 'user-1'), ['id' => 'srv-1', 'path' => 'api/v1/libraries']);
        $this->assertSame(404, $response->statusCode);
    }

    public function test_not_owned_returns_403(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn(['userId' => 'someone-else', 'status' => 'online', 'relayActive' => true]);
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
        $info->method('getOwnerAndStatus')->willReturn(
            ['userId' => 'user-1', 'status' => 'online', 'relayActive' => false],
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
        $info->method('getOwnerAndStatus')->willReturn(
            ['userId' => 'user-1', 'status' => 'offline', 'relayActive' => false],
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
        $info->method('getOwnerAndStatus')->willReturn(['userId' => 'user-1', 'status' => 'online', 'relayActive' => true]);

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
                'body' => '{"libraries":[]}',
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
        $info->method('getOwnerAndStatus')->willReturn(['userId' => 'user-1', 'status' => 'online', 'relayActive' => true]);

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
                'body' => '{}',
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
        $info->method('getOwnerAndStatus')->willReturn(['userId' => 'user-1', 'status' => 'online', 'relayActive' => true]);

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
        $info->method('getOwnerAndStatus')->willReturn(['userId' => 'user-1', 'status' => 'online', 'relayActive' => true]);

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
        $info->method('getOwnerAndStatus')->willReturn(['userId' => 'user-1', 'status' => 'online', 'relayActive' => true]);

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
                'body' => '{"id":"abc"}',
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
        $info->method('getOwnerAndStatus')->willReturn(['userId' => 'user-1', 'status' => 'online', 'relayActive' => true]);

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
     * D1 accept matrix: GET and HEAD to representative playback-read paths under
     * the newly-allowed `/hls`, `/dash`, `/media`, `/api/v1/transcode` prefixes
     * must pass the scope gate and be forwarded verbatim over the relay bridge.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function acceptedStreamingScopeProvider(): iterable
    {
        $paths = [
            'hls master playlist' => 'hls/job-abc/master.m3u8',
            'hls variant playlist' => 'hls/job-abc/media_v3.m3u8',
            'hls segment' => 'hls/job-abc/seg-00007.ts',
            'dash manifest' => 'dash/job-abc/manifest.mpd',
            'media direct-play stream' => 'media/item-123/stream',
            'transcode job status' => 'api/v1/transcode/job-abc/status',
        ];

        foreach (['GET', 'HEAD'] as $method) {
            foreach ($paths as $label => $path) {
                yield "{$method} {$label}" => [$method, $path];
            }
        }
    }

    /**
     * A GET/HEAD to a real streaming path must clear the scope gate and reach the
     * relay bridge with the path + method intact (players issue HEAD to probe
     * segment size / range support before a ranged GET).
     *
     * @dataProvider acceptedStreamingScopeProvider
     */
    public function test_streaming_reads_pass_scope_gate_and_are_forwarded(string $method, string $path): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn(['userId' => 'user-1', 'status' => 'online', 'relayActive' => true]);

        /** @var array<string, mixed>|null $forwarded */
        $forwarded = null;
        $bridge = null;
        $publisher = function (string $event, array $data) use (&$bridge, &$forwarded): void {
            $forwarded = $data;
            /** @var RelayProxyBridge $bridge */
            $bridge->onReply([
                'request_id' => $data['request_id'],
                'status' => 200,
                'headers' => ['Content-Type' => 'application/octet-stream'],
                'body' => 'payload',
            ]);
        };
        $bridge = $this->bridge($publisher);

        $controller = $this->controller($info, $bridge);
        $response = $controller->proxy(
            $this->request($method, 'user-1'),
            ['id' => 'srv-1', 'path' => $path],
        );

        // Byte-serving reads (/hls, /dash, /media) are streamed: forwarding
        // happens when the HTTP worker drives the producer with the connection.
        // Status polling stays buffered (forwarded synchronously in proxy()).
        $this->drive($response);

        $this->assertSame(200, $response->statusCode, "{$method} /{$path} must pass the browse-scope gate");
        $this->assertIsArray($forwarded, "{$method} /{$path} must be forwarded over the relay bridge");
        $this->assertSame('/' . $path, $forwarded['path']);
        $this->assertSame($method, $forwarded['method']);
    }

    /**
     * HB-0.3 anti-stall (end-to-end): a HEAD probe over the relay is routed
     * through the BUFFERED bridge path — HEAD is excluded from
     * `isStreamingPath()`, so only GET streams. The paired server's
     * `withFile()` HEAD emits a head frame carrying Content-Length then a
     * zero-body END, with NO body frame. The controller must return PROMPTLY on
     * END — a buffered 200 carrying range support and an empty body — never
     * blocking until the reply timeout (which would surface as a 504).
     *
     * This wires a real {@see RelayProxyManager} + live tunnel and feeds the
     * exact HEAD+END frame sequence the moment the HTTP_REQUEST is sent down
     * the tunnel, proving completion happens on END (not on a body frame or a
     * wall-clock timeout).
     *
     * (Content-Length itself is intentionally re-derived by the hub's response
     * framing — it is in the stripped-response-header set — so the assertion
     * here is on prompt buffered completion + preserved range support, not on
     * the Content-Length value. RelayProxyManagerTest asserts the manager
     * carries Content-Length through to its reply verbatim.)
     */
    public function test_head_over_relay_returns_promptly_on_end_without_body_frames(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn(['userId' => 'user-1', 'status' => 'online', 'relayActive' => true]);

        $proxyManager = null;
        $decoder = new FrameDecoder();
        $serverWs = $this->createMock(TcpConnection::class);
        // The moment the manager sends the HTTP_REQUEST down the tunnel, feed
        // back exactly what a server withFile() HEAD emits: a head frame with
        // Content-Length + a zero-body END. No body frame is ever sent.
        $serverWs->method('send')->willReturnCallback(
            static function (mixed $data) use (&$proxyManager, $decoder): bool {
                // The handshake HELLO_ACK is not a data frame — decode defensively
                // and only react to the HTTP_REQUEST the manager forwards.
                $frame = null;
                if (is_string($data)) {
                    try {
                        $frame = $decoder->decode($data);
                    } catch (\Throwable) {
                        $frame = null;
                    }
                }
                if ($frame !== null && $frame->type === RelayFrameType::HTTP_REQUEST) {
                    /** @var RelayProxyManager $proxyManager */
                    $proxyManager->onResponseFrame(new RelayFrame(
                        RelayFrameType::HTTP_RESPONSE,
                        $frame->seq,
                        RelayHttpResponseCodec::encodeHead(new RelayHttpResponseHead(
                            200,
                            ['Content-Type' => 'video/mp4', 'Content-Length' => '12345', 'Accept-Ranges' => 'bytes'],
                            12345,
                        )),
                    ));
                    $proxyManager->onResponseFrame(new RelayFrame(
                        RelayFrameType::HTTP_RESPONSE,
                        $frame->seq,
                        RelayHttpResponseCodec::encodeEnd(),
                    ));
                }
                return true;
            },
        );

        $tunnel = $this->activeTunnel('srv-1', $serverWs);
        $tunnelManager = $this->createMock(TunnelManagerInterface::class);
        $tunnelManager->method('getTunnelForServer')->willReturn($tunnel);

        $bridge = null;
        $publisher = function (string $event, array $data) use (&$proxyManager): void {
            /** @var RelayProxyManager $proxyManager */
            $proxyManager->onRequest($data);
        };
        $bridge = $this->bridge($publisher);
        $proxyManager = new RelayProxyManager(
            $tunnelManager,
            $this->createMock(StructuredLogger::class),
            30,
            static function (string $event, array $data) use (&$bridge): void {
                /** @var RelayProxyBridge $bridge */
                $bridge->onReply($data);
            },
        );

        $controller = $this->controller($info, $bridge);
        $response = $controller->proxy(
            $this->request('HEAD', 'user-1'),
            ['id' => 'srv-1', 'path' => 'media/item-123/stream'],
        );

        // HEAD is buffered, never streamed — no producer closure.
        $this->assertNull($response->streamProducer, 'HEAD must take the buffered path, not the streaming producer');
        // Prompt completion on END — a 200, never a 504 gateway timeout.
        $this->assertSame(200, $response->statusCode);
        // Range support survives the hub's response framing.
        $this->assertSame('bytes', $response->headers['Accept-Ranges'] ?? null);
        // Completed on END with no body frame → empty body.
        $this->assertSame('', $response->body);
    }

    public function test_streaming_read_returns_a_producer_and_streams_body_to_the_connection(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn(['userId' => 'user-1', 'status' => 'online', 'relayActive' => true]);

        $bridge = null;
        $publisher = function (string $event, array $data) use (&$bridge): void {
            /** @var RelayProxyBridge $bridge */
            $id = $data['request_id'];
            // Relay worker streams the segment back as phased frames.
            $bridge->onReply(['request_id' => $id, 'phase' => 'head', 'status' => 200, 'headers' => [
                'Content-Type' => 'video/mp2t',
                'Content-Length' => '6',
            ]]);
            $bridge->onReply(['request_id' => $id, 'phase' => 'body', 'body' => 'foo']);
            $bridge->onReply(['request_id' => $id, 'phase' => 'body', 'body' => 'bar']);
            $bridge->onReply(['request_id' => $id, 'phase' => 'end']);
        };
        $bridge = $this->bridge($publisher);

        $controller = $this->controller($info, $bridge);
        $response = $controller->proxy(
            $this->request('GET', 'user-1'),
            ['id' => 'srv-1', 'path' => 'hls/job-abc/seg-00007.ts'],
        );

        // A streaming path defers to a producer instead of a buffered body.
        $this->assertNotNull($response->streamProducer);
        $this->assertSame('', $response->body);

        $connection = $this->drive($response);

        // Head (with the server's Content-Length preserved) + the raw body bytes
        // streamed fragment-by-fragment — never buffered into one blob.
        $written = $connection->written;
        $this->assertStringContainsString('HTTP/1.1 200 OK', $written[0]);
        $this->assertStringContainsString('Content-Length: 6', $written[0]);
        $this->assertSame('foo', $written[1]);
        $this->assertSame('bar', $written[2]);
    }

    /**
     * D1 method-denial matrix: mutating methods against the same streaming paths
     * (and the admin `POST /media/merge` sibling) stay always-denied — this is a
     * READ-ONLY proxy, so any method absent from the allowlist fails closed.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function deniedMutatingStreamingProvider(): iterable
    {
        $paths = [
            'media transcode START' => 'media/item-123/transcode',
            'media merge (admin)' => 'media/merge',
            'hls segment' => 'hls/job-abc/seg-00007.ts',
            'dash manifest' => 'dash/job-abc/manifest.mpd',
            'media stream' => 'media/item-123/stream',
            'transcode status' => 'api/v1/transcode/job-abc/status',
        ];

        foreach (['POST', 'PUT', 'DELETE'] as $method) {
            foreach ($paths as $label => $path) {
                yield "{$method} {$label}" => [$method, $path];
            }
        }
    }

    /**
     * Non-GET/HEAD methods must never reach a streaming path: the browse-scope
     * gate returns 403 `proxy.scope_denied` and nothing is forwarded.
     *
     * @dataProvider deniedMutatingStreamingProvider
     */
    public function test_mutating_methods_on_streaming_paths_return_403_and_are_not_forwarded(
        string $method,
        string $path,
    ): void {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn(['userId' => 'user-1', 'status' => 'online', 'relayActive' => true]);

        $forwarded = false;
        $controller = $this->controller($info, $this->bridge(static function (string $e, array $d) use (&$forwarded): void {
            $forwarded = true;
        }));

        $response = $controller->proxy(
            $this->request($method, 'user-1'),
            ['id' => 'srv-1', 'path' => $path],
        );

        $this->assertSame(403, $response->statusCode, "{$method} /{$path} must be denied");
        $this->assertFalse($forwarded, "{$method} /{$path} must not reach the relay bridge");
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame('proxy.scope_denied', $body['code'] ?? null);
    }

    /**
     * D1 sibling-prefix denial: a path that merely shares a textual prefix with a
     * newly-allowed streaming collection is NOT a sub-path of it and must be
     * denied — the gate matches an exact path or a `/`-delimited sub-path only,
     * never a bare sibling like `/hlsX` or `/dashboard`.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function siblingStreamingPrefixProvider(): iterable
    {
        yield 'hlsX sibling of /hls' => ['hlsX/job/master.m3u8'];
        yield 'media-secret sibling of /media' => ['media-secret/item/stream'];
        yield 'dashboard sibling of /dash' => ['dashboard'];
        yield 'transcodeX sibling of /api/v1/transcode' => ['api/v1/transcodeX/job/status'];
    }

    /**
     * @dataProvider siblingStreamingPrefixProvider
     */
    public function test_sibling_streaming_prefixes_are_denied(string $path): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn(['userId' => 'user-1', 'status' => 'online', 'relayActive' => true]);

        $forwarded = false;
        $controller = $this->controller($info, $this->bridge(static function (string $e, array $d) use (&$forwarded): void {
            $forwarded = true;
        }));

        $response = $controller->proxy(
            $this->request('GET', 'user-1'),
            ['id' => 'srv-1', 'path' => $path],
        );

        $this->assertSame(403, $response->statusCode, "Sibling prefix /{$path} must be denied");
        $this->assertFalse($forwarded, "Sibling prefix /{$path} must not reach the relay bridge");
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame('proxy.scope_denied', $body['code'] ?? null);
    }

    /**
     * Ownership is enforced BEFORE the scope gate: a streaming path against a
     * server the user does not own returns 403 `server.not_owned` (not
     * `proxy.scope_denied`) and is never forwarded — the widened allowlist does
     * not weaken the ownership boundary.
     */
    public function test_streaming_path_on_unowned_server_returns_403_not_owned(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn(['userId' => 'someone-else', 'status' => 'online', 'relayActive' => true]);

        $forwarded = false;
        $controller = $this->controller($info, $this->bridge(static function (string $e, array $d) use (&$forwarded): void {
            $forwarded = true;
        }));

        $response = $controller->proxy(
            $this->request('GET', 'user-1'),
            ['id' => 'srv-1', 'path' => 'hls/job-abc/master.m3u8'],
        );

        $this->assertSame(403, $response->statusCode);
        $this->assertFalse($forwarded, 'Unowned server → nothing may be forwarded, even for a streaming path.');
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame('server.not_owned', $body['code'] ?? null);
    }

    /**
     * Relay-liveness is enforced BEFORE the scope gate: a streaming path against
     * an offline server with no live tunnel fails closed with 503
     * `server.offline` and is never forwarded.
     */
    public function test_streaming_path_on_offline_server_fails_closed_503(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn(
            ['userId' => 'user-1', 'status' => 'offline', 'relayActive' => false],
        );

        $forwarded = false;
        $controller = $this->controller($info, $this->bridge(static function (string $e, array $d) use (&$forwarded): void {
            $forwarded = true;
        }));

        $response = $controller->proxy(
            $this->request('GET', 'user-1'),
            ['id' => 'srv-1', 'path' => 'dash/job-abc/manifest.mpd'],
        );

        $this->assertSame(503, $response->statusCode);
        $this->assertFalse($forwarded, 'Offline server → nothing may be forwarded, even for a streaming path.');
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame('server.offline', $body['code'] ?? null);
    }

    // ---------------------------------------------------------------------
    // D2: transcode-START POST — the single permitted write. The prefix
    // BROWSE_SCOPE_ALLOWLIST cannot express `/api/v1/media/{id}/transcode`
    // (variable id in the MIDDLE), so it is matched by the anchored
    // BROWSE_SCOPE_PATTERNS PCRE instead. These tests prove the one write
    // passes and is forwarded (incl. its `?profile=` query string), every
    // mutating sibling stays 403, the `^…$`/`[^/]+` anchoring is tight, and the
    // ownership/liveness/auth gates still run BEFORE the scope gate for POST.
    // ---------------------------------------------------------------------

    /**
     * Accept matrix: `POST /api/v1/media/{id}/transcode` for an owned, online
     * server with a live tunnel passes the scope gate and is forwarded verbatim.
     * The id is a single `[^/]+` segment, so a plain slug and a full UUID both
     * match.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function acceptedTranscodeStartProvider(): iterable
    {
        yield 'slug id' => ['item-123', '/api/v1/media/item-123/transcode'];
        yield 'uuid id' => [
            '550e8400-e29b-41d4-a716-446655440000',
            '/api/v1/media/550e8400-e29b-41d4-a716-446655440000/transcode',
        ];
    }

    /**
     * The transcode-start POST clears the scope gate and reaches the relay
     * bridge with the exact server route + POST method intact (the server's
     * `TranscodeController::start` is `POST /api/v1/media/{id}/transcode`).
     *
     * @dataProvider acceptedTranscodeStartProvider
     */
    public function test_transcode_start_post_passes_scope_gate_and_is_forwarded(
        string $id,
        string $expectedPath,
    ): void {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn(['userId' => 'user-1', 'status' => 'online', 'relayActive' => true]);

        /** @var array<string, mixed>|null $forwarded */
        $forwarded = null;
        $bridge = null;
        $publisher = function (string $event, array $data) use (&$bridge, &$forwarded): void {
            $forwarded = $data;
            /** @var RelayProxyBridge $bridge */
            $bridge->onReply([
                'request_id' => $data['request_id'],
                'status' => 202,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => '{"jobId":"job-1"}',
            ]);
        };
        $bridge = $this->bridge($publisher);

        $controller = $this->controller($info, $bridge);
        $response = $controller->proxy(
            $this->request('POST', 'user-1'),
            ['id' => 'srv-1', 'path' => "api/v1/media/{$id}/transcode"],
        );

        $this->assertSame(202, $response->statusCode, 'transcode-start POST must pass the browse-scope gate');
        $this->assertIsArray($forwarded, 'transcode-start POST must be forwarded over the relay bridge');
        $this->assertSame($expectedPath, $forwarded['path']);
        $this->assertSame('POST', $forwarded['method']);
        // No query on this request → an empty query string is forwarded.
        $this->assertSame('', $forwarded['query']);
    }

    /**
     * The server's `TranscodeController::start` reads the requested profile from
     * the QUERY STRING (`?profile=web`), not the body, so the proxy must forward
     * the raw query string intact alongside the POST.
     */
    public function test_transcode_start_post_forwards_query_string(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn(['userId' => 'user-1', 'status' => 'online', 'relayActive' => true]);

        /** @var array<string, mixed>|null $forwarded */
        $forwarded = null;
        $bridge = null;
        $publisher = function (string $event, array $data) use (&$bridge, &$forwarded): void {
            $forwarded = $data;
            /** @var RelayProxyBridge $bridge */
            $bridge->onReply([
                'request_id' => $data['request_id'],
                'status' => 202,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => '{"jobId":"job-1"}',
            ]);
        };
        $bridge = $this->bridge($publisher);

        $request = $this->request('POST', 'user-1');
        $request->queryString = 'profile=web';

        $controller = $this->controller($info, $bridge);
        $response = $controller->proxy(
            $request,
            ['id' => 'srv-1', 'path' => 'api/v1/media/item-123/transcode'],
        );

        $this->assertSame(202, $response->statusCode);
        $this->assertIsArray($forwarded);
        $this->assertSame('/api/v1/media/item-123/transcode', $forwarded['path']);
        $this->assertSame('POST', $forwarded['method']);
        // The `?profile=web` selector must survive to the server unchanged.
        $this->assertSame('profile=web', $forwarded['query']);
    }

    /**
     * HB-3.1: watched/unwatched via POST and favorite/rating/like_level/poster
     * via PUT are now allowed scope-wise. Only match/apply and admin routes
     * stay 403 `proxy.scope_denied`.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function stillDeniedSiblingMediaPostProvider(): iterable
    {
        yield 'match apply' => ['api/v1/media/item-123/match/apply'];
        yield 'media merge (admin)' => ['media/merge'];
    }

    /**
     * @dataProvider stillDeniedSiblingMediaPostProvider
     */
    public function test_sibling_media_post_routes_still_denied(string $path): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn(['userId' => 'user-1', 'status' => 'online', 'relayActive' => true]);

        $forwarded = false;
        $controller = $this->controller($info, $this->bridge(static function (string $e, array $d) use (&$forwarded): void {
            $forwarded = true;
        }));

        $response = $controller->proxy(
            $this->request('POST', 'user-1'),
            ['id' => 'srv-1', 'path' => $path],
        );

        $this->assertSame(403, $response->statusCode, "POST /{$path} must be denied");
        $this->assertFalse($forwarded, "POST /{$path} must not reach the relay bridge");
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame('proxy.scope_denied', $body['code'] ?? null);
    }

    /**
     * HB-3.1: rating, like (like_level), and poster are the PUT write actions,
     * each anchored to a REAL server route
     * ({@see \Phlix\Server\Http\Controllers\MediaUserDataController::setRating}
     * / `setLikeLevel` / `MediaPosterController::setPoster`). They forward over
     * the relay bridge and the server returns the result. NOTE favorite is NOT a
     * PUT action (server exposes POST/DELETE favorite) — see the anchoring-guard
     * test below, which asserts `PUT …/favorite` is DENIED.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function allowedSiblingMediaPutProvider(): iterable
    {
        yield 'rating' => ['/api/v1/media/item-123/rating'];
        yield 'like' => ['/api/v1/media/item-123/like'];
        yield 'poster' => ['/api/v1/media/item-123/poster'];
    }

    /**
     * @dataProvider allowedSiblingMediaPutProvider
     */
    public function test_sibling_media_put_routes_are_allowed(string $path): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn(['userId' => 'user-1', 'status' => 'online', 'relayActive' => true]);

        /** @var array<string, mixed>|null $forwarded */
        $forwarded = null;
        $controller = $this->controller($info, $this->bridge(static function (string $e, array $d) use (&$forwarded): void {
            $forwarded = $d;
        }));

        $response = $controller->proxy(
            $this->request('PUT', 'user-1'),
            ['id' => 'srv-1', 'path' => $path],
        );

        $this->assertSame(504, $response->statusCode, "PUT /{$path} must be forwarded");
        $this->assertIsArray($forwarded, "PUT /{$path} must reach the relay bridge");
        $this->assertSame($path, $forwarded['path']);
        $this->assertSame('PUT', $forwarded['method']);
    }

    /**
     * HB-3.1: watched and unwatched are allowed via POST.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function allowedSiblingMediaPostWatchedProvider(): iterable
    {
        yield 'watched' => ['/api/v1/media/item-123/watched'];
        yield 'unwatched' => ['/api/v1/media/item-123/unwatched'];
    }

    /**
     * @dataProvider allowedSiblingMediaPostWatchedProvider
     */
    public function test_sibling_media_post_watched_unwatched_are_allowed(string $path): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn(['userId' => 'user-1', 'status' => 'online', 'relayActive' => true]);

        /** @var array<string, mixed>|null $forwarded */
        $forwarded = null;
        $controller = $this->controller($info, $this->bridge(static function (string $e, array $d) use (&$forwarded): void {
            $forwarded = $d;
        }));

        $response = $controller->proxy(
            $this->request('POST', 'user-1'),
            ['id' => 'srv-1', 'path' => $path],
        );

        $this->assertSame(504, $response->statusCode, "POST /{$path} must be forwarded");
        $this->assertIsArray($forwarded, "POST /{$path} must reach the relay bridge");
        $this->assertSame($path, $forwarded['path']);
        $this->assertSame('POST', $forwarded['method']);
    }

    /**
     * HB-3.1: the POST write actions that are NOT `/api/v1/media/{id}/…` toggles
     * — add-favorite and playlist-create — are allowed via anchored patterns.
     * Server: POST /api/v1/media/{id}/favorite, POST /api/v1/playlists.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function allowedMediaPostWriteProvider(): iterable
    {
        yield 'add favorite' => ['/api/v1/media/item-123/favorite'];
        yield 'create playlist' => ['/api/v1/playlists'];
    }

    /**
     * @dataProvider allowedMediaPostWriteProvider
     */
    public function test_post_write_actions_favorite_and_playlist_are_allowed(string $path): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn(['userId' => 'user-1', 'status' => 'online', 'relayActive' => true]);

        /** @var array<string, mixed>|null $forwarded */
        $forwarded = null;
        $controller = $this->controller($info, $this->bridge(static function (string $e, array $d) use (&$forwarded): void {
            $forwarded = $d;
        }));

        $response = $controller->proxy(
            $this->request('POST', 'user-1'),
            ['id' => 'srv-1', 'path' => ltrim($path, '/')],
        );

        $this->assertSame(504, $response->statusCode, "POST /{$path} must be forwarded");
        $this->assertIsArray($forwarded, "POST /{$path} must reach the relay bridge");
        $this->assertSame($path, $forwarded['path']);
        $this->assertSame('POST', $forwarded['method']);
    }

    /**
     * HB-3.1: DELETE write actions — remove-favorite and clear-rating — are
     * allowed via anchored patterns. Server (MediaUserDataController):
     * DELETE /api/v1/media/{id}/favorite, DELETE /api/v1/media/{id}/rating.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function allowedMediaDeleteProvider(): iterable
    {
        yield 'remove favorite' => ['/api/v1/media/item-123/favorite'];
        yield 'clear rating' => ['/api/v1/media/item-123/rating'];
    }

    /**
     * @dataProvider allowedMediaDeleteProvider
     */
    public function test_delete_write_actions_are_allowed(string $path): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn(['userId' => 'user-1', 'status' => 'online', 'relayActive' => true]);

        /** @var array<string, mixed>|null $forwarded */
        $forwarded = null;
        $controller = $this->controller($info, $this->bridge(static function (string $e, array $d) use (&$forwarded): void {
            $forwarded = $d;
        }));

        $response = $controller->proxy(
            $this->request('DELETE', 'user-1'),
            ['id' => 'srv-1', 'path' => ltrim($path, '/')],
        );

        $this->assertSame(504, $response->statusCode, "DELETE /{$path} must be forwarded");
        $this->assertIsArray($forwarded, "DELETE /{$path} must reach the relay bridge");
        $this->assertSame($path, $forwarded['path']);
        $this->assertSame('DELETE', $forwarded['method']);
    }

    /**
     * THE anchoring guard (this test FAILS against the pre-fix broad-prefix
     * allowlist). With the old `PUT /api/v1/media` / `DELETE|PUT /api/v1/playlists`
     * broad prefixes, ANY `PUT /api/v1/media/{id}/<anything>` (and any
     * `/api/v1/playlists/<sub>`) was relayed. The anchored per-action patterns
     * now DENY every write path that is not an intended action — including a
     * would-be media-update `PUT …/{id}` bare, a `PUT …/{id}/delete`, a
     * `PUT …/{id}/scan`, `POST/PUT …/{id}/like_level` (the real route is `/like`),
     * a favorite via the wrong verb (PUT), and an admin write.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function deniedNonListedWriteProvider(): iterable
    {
        // method, path (leading slash trimmed by the caller).
        yield 'PUT bare media id (would-be update)' => ['PUT', '/api/v1/media/item-123'];
        yield 'PUT media delete sub-path' => ['PUT', '/api/v1/media/item-123/delete'];
        yield 'PUT media scan sub-path' => ['PUT', '/api/v1/media/item-123/scan'];
        yield 'PUT wrong like_level route' => ['PUT', '/api/v1/media/item-123/like_level'];
        yield 'PUT favorite (wrong verb)' => ['PUT', '/api/v1/media/item-123/favorite'];
        yield 'POST wrong like_level route' => ['POST', '/api/v1/media/item-123/like_level'];
        yield 'POST rating (wrong verb)' => ['POST', '/api/v1/media/item-123/rating'];
        yield 'PUT admin users' => ['PUT', '/api/v1/admin/users/u-1'];
        yield 'DELETE admin user' => ['DELETE', '/api/v1/admin/users/u-1'];
        yield 'POST admin scan' => ['POST', '/api/v1/admin/libraries/lib-1/scan'];
        yield 'DELETE playlists sub-path' => ['DELETE', '/api/v1/playlists/pl-1'];
        yield 'PUT playlists sub-path' => ['PUT', '/api/v1/playlists/pl-1'];
    }

    /**
     * @dataProvider deniedNonListedWriteProvider
     */
    public function test_non_listed_write_actions_are_denied(string $method, string $path): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn(['userId' => 'user-1', 'status' => 'online', 'relayActive' => true]);

        $forwarded = false;
        $controller = $this->controller($info, $this->bridge(static function (string $e, array $d) use (&$forwarded): void {
            $forwarded = true;
        }));

        $response = $controller->proxy(
            $this->request($method, 'user-1'),
            ['id' => 'srv-1', 'path' => ltrim($path, '/')],
        );

        $this->assertSame(403, $response->statusCode, "{$method} /{$path} must be denied (fail closed)");
        $this->assertFalse($forwarded, "{$method} /{$path} must never reach the relay bridge");
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame('proxy.scope_denied', $body['code'] ?? null);
    }

    /**
     * PATCH decision: the media server exposes NO PATCH write route, so the
     * registered-but-deny PATCH proxy route fails closed for EVERY path (an
     * intended action path, an admin path, and a bare media id alike). This
     * documents that PATCH carries no capability.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function patchAlwaysDeniedProvider(): iterable
    {
        yield 'rating' => ['/api/v1/media/item-123/rating'];
        yield 'favorite' => ['/api/v1/media/item-123/favorite'];
        yield 'bare media id' => ['/api/v1/media/item-123'];
        yield 'admin user' => ['/api/v1/admin/users/u-1'];
        yield 'browse read' => ['/api/v1/libraries'];
    }

    /**
     * @dataProvider patchAlwaysDeniedProvider
     */
    public function test_patch_is_always_denied(string $path): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn(['userId' => 'user-1', 'status' => 'online', 'relayActive' => true]);

        $forwarded = false;
        $controller = $this->controller($info, $this->bridge(static function (string $e, array $d) use (&$forwarded): void {
            $forwarded = true;
        }));

        $response = $controller->proxy(
            $this->request('PATCH', 'user-1'),
            ['id' => 'srv-1', 'path' => ltrim($path, '/')],
        );

        $this->assertSame(403, $response->statusCode, "PATCH /{$path} must fail closed");
        $this->assertFalse($forwarded, "PATCH /{$path} must never reach the relay bridge");
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame('proxy.scope_denied', $body['code'] ?? null);
    }

    /**
     * Bodied round-trip (small body → single-frame HTTP_REQUEST). Drives a
     * `PUT /api/v1/media/{id}/rating` with a JSON body ALL THE WAY through a real
     * {@see RelayProxyManager} + live tunnel, decodes the emitted
     * {@see RelayHttpRequest} off the wire, and asserts method/path/body bytes
     * are forwarded intact (not merely that the request reached the bridge). A
     * 200 is fed back over the tunnel so the controller completes → proves the
     * full write round-trip, not a 504.
     */
    public function test_bodied_put_rating_round_trip_forwards_body_intact(): void
    {
        [$captured, $response, $frames] = $this->roundTripWrite(
            'PUT',
            '/api/v1/media/item-123/rating',
            ['rating' => 7],
        );

        $this->assertSame(200, $response->statusCode, 'The bodied write must complete over the relay, not 504');
        $this->assertSame('PUT', $captured['method']);
        $this->assertSame('/api/v1/media/item-123/rating', $captured['path']);
        $this->assertSame('{"rating":7}', $captured['body'], 'Body bytes must reach the server verbatim');
        $this->assertSame(1, $frames, 'A small body is a single HTTP_REQUEST frame');
    }

    /**
     * Bodied round-trip over the HB-2.1 chunking path (>64 KB body → the emitted
     * request is split into HEAD + N·BODY + END tag-byte sub-frames). Asserts
     * the reassembled body is byte-for-byte identical to what the hub forwarded,
     * proving large bodied writes traverse the proxy→relay bridge intact and are
     * NOT rejected (the old 413 cap / single-frame limit).
     */
    public function test_large_bodied_put_round_trip_chunks_and_preserves_body_bytes(): void
    {
        // A body whose JSON encoding comfortably exceeds the 65535-byte single
        // frame limit, forcing RelayProxyManager onto the chunked HEAD/BODY/END
        // path (HB-2.1). Includes a NUL and 0xFF-adjacent bytes are avoided in
        // JSON, but the large payload alone exercises multi-frame reassembly.
        $note = str_repeat('AB', 40000); // 80 000 chars
        [$captured, $response, $frames] = $this->roundTripWrite(
            'PUT',
            '/api/v1/media/item-123/rating',
            ['rating' => 7, 'note' => $note],
        );

        $expectedBody = json_encode(['rating' => 7, 'note' => $note], JSON_THROW_ON_ERROR);
        $this->assertGreaterThan(65535, strlen($expectedBody), 'Test body must exceed the single-frame limit');
        $this->assertSame(200, $response->statusCode, 'The large bodied write must complete over the relay');
        $this->assertSame('PUT', $captured['method']);
        $this->assertSame('/api/v1/media/item-123/rating', $captured['path']);
        $this->assertSame($expectedBody, $captured['body'], 'Chunked body must reassemble byte-for-byte');
        // HEAD + at least one BODY + END ⇒ strictly more than one HTTP_REQUEST frame.
        $this->assertGreaterThan(1, $frames, 'A >64 KB body must be chunked into multiple frames');
    }

    /**
     * Drive a bodied write all the way through a real {@see RelayProxyManager} +
     * live tunnel (mirrors {@see self::test_head_over_relay_returns_promptly_on_end_without_body_frames}),
     * capturing and reassembling the {@see RelayHttpRequest} the hub emits on the
     * wire — whether a single-frame JSON envelope or the HB-2.1 chunked
     * HEAD/BODY…/END tag-byte sequence — then feeding back a 200 so the
     * controller completes.
     *
     * @param array<string, mixed> $body Parsed body the hub re-encodes + forwards.
     *
     * @return array{0: array{method: string, path: string, query: string, body: string}, 1: Response, 2: int}
     *         [captured request, controller response, HTTP_REQUEST frame count]
     */
    private function roundTripWrite(string $method, string $path, array $body): array
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn(['userId' => 'user-1', 'status' => 'online', 'relayActive' => true]);

        $proxyManager = null;
        $decoder = new FrameDecoder();

        $captured = ['method' => '', 'path' => '', 'query' => '', 'body' => ''];
        $bodyAccumulator = '';
        $frameCount = 0;
        $responded = [];

        $serverWs = $this->createMock(TcpConnection::class);
        $serverWs->method('send')->willReturnCallback(
            function (mixed $data) use (
                &$proxyManager,
                $decoder,
                &$captured,
                &$bodyAccumulator,
                &$frameCount,
                &$responded
            ): bool {
                $frame = null;
                if (is_string($data)) {
                    try {
                        $frame = $decoder->decode($data);
                    } catch (\Throwable) {
                        $frame = null;
                    }
                }
                if ($frame === null || $frame->type !== RelayFrameType::HTTP_REQUEST) {
                    return true;
                }
                $frameCount++;

                $payload = $frame->payload;
                $tag = $payload !== '' ? ord($payload[0]) : 0;
                $complete = false;

                if (
                    $tag === RelayHttpRequestCodec::TAG_HEAD
                    || $tag === RelayHttpRequestCodec::TAG_BODY
                    || $tag === RelayHttpRequestCodec::TAG_END
                ) {
                    // HB-2.1 chunked path.
                    $chunk = RelayHttpRequestCodec::decode($payload);
                    if ($chunk->kind === RelayHttpRequestChunk::KIND_HEAD && $chunk->head !== null) {
                        $captured['method'] = $chunk->head->method;
                        $captured['path'] = $chunk->head->path;
                        $captured['query'] = $chunk->head->query;
                    } elseif ($chunk->kind === RelayHttpRequestChunk::KIND_BODY) {
                        $bodyAccumulator .= $chunk->body;
                    } elseif ($chunk->kind === RelayHttpRequestChunk::KIND_END) {
                        $captured['body'] = $bodyAccumulator;
                        $complete = true;
                    }
                } else {
                    // Single-frame JSON envelope.
                    $decoded = RelayHttpRequest::fromJson($payload);
                    $captured['method'] = $decoded->method;
                    $captured['path'] = $decoded->path;
                    $captured['query'] = $decoded->query;
                    $captured['body'] = $decoded->body;
                    $complete = true;
                }

                if ($complete && !isset($responded[$frame->seq])) {
                    $responded[$frame->seq] = true;
                    /** @var RelayProxyManager $proxyManager */
                    $proxyManager->onResponseFrame(new RelayFrame(
                        RelayFrameType::HTTP_RESPONSE,
                        $frame->seq,
                        RelayHttpResponseCodec::encodeHead(new RelayHttpResponseHead(
                            200,
                            ['Content-Type' => 'application/json'],
                            0,
                        )),
                    ));
                    $proxyManager->onResponseFrame(new RelayFrame(
                        RelayFrameType::HTTP_RESPONSE,
                        $frame->seq,
                        RelayHttpResponseCodec::encodeEnd(),
                    ));
                }

                return true;
            },
        );

        $tunnel = $this->activeTunnel('srv-1', $serverWs);
        $tunnelManager = $this->createMock(TunnelManagerInterface::class);
        $tunnelManager->method('getTunnelForServer')->willReturn($tunnel);

        $bridge = null;
        $publisher = function (string $event, array $data) use (&$proxyManager): void {
            /** @var RelayProxyManager $proxyManager */
            $proxyManager->onRequest($data);
        };
        $bridge = $this->bridge($publisher);
        $proxyManager = new RelayProxyManager(
            $tunnelManager,
            $this->createMock(StructuredLogger::class),
            30,
            static function (string $event, array $data) use (&$bridge): void {
                /** @var RelayProxyBridge $bridge */
                $bridge->onReply($data);
            },
        );

        $req = $this->request($method, 'user-1');
        $req->body = $body;
        $controller = $this->controller($info, $bridge);
        $response = $controller->proxy($req, ['id' => 'srv-1', 'path' => ltrim($path, '/')]);

        return [$captured, $response, $frameCount];
    }

    /**
     * Anchoring edge cases: the pattern is fully anchored (`^…$`) with a single
     * `[^/]+` id segment, so a trailing extra segment, an empty id (double
     * slash), and a `transcode`-prefixed-but-longer final segment all fail to
     * match and stay denied.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function deniedTranscodeEdgeProvider(): iterable
    {
        yield 'trailing extra segment' => ['api/v1/media/x/transcode/y'];
        yield 'empty id (double slash)' => ['api/v1/media//transcode'];
        yield 'transcode-prefixed longer segment' => ['api/v1/media/x/transcodeX'];
    }

    /**
     * @dataProvider deniedTranscodeEdgeProvider
     */
    public function test_transcode_regex_edge_paths_are_denied(string $path): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn(['userId' => 'user-1', 'status' => 'online', 'relayActive' => true]);

        $forwarded = false;
        $controller = $this->controller($info, $this->bridge(static function (string $e, array $d) use (&$forwarded): void {
            $forwarded = true;
        }));

        $response = $controller->proxy(
            $this->request('POST', 'user-1'),
            ['id' => 'srv-1', 'path' => $path],
        );

        $this->assertSame(403, $response->statusCode, "POST /{$path} must not match the anchored transcode pattern");
        $this->assertFalse($forwarded, "POST /{$path} must not reach the relay bridge");
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame('proxy.scope_denied', $body['code'] ?? null);
    }

    /**
     * Ownership runs BEFORE the scope gate: a valid transcode-start POST against
     * a server the user does not own returns 403 `server.not_owned` (not
     * `proxy.scope_denied`) and is never forwarded — the new write does not
     * weaken the ownership boundary.
     */
    public function test_transcode_start_post_on_unowned_server_returns_403_not_owned(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn(['userId' => 'someone-else', 'status' => 'online', 'relayActive' => true]);

        $forwarded = false;
        $controller = $this->controller($info, $this->bridge(static function (string $e, array $d) use (&$forwarded): void {
            $forwarded = true;
        }));

        $response = $controller->proxy(
            $this->request('POST', 'user-1'),
            ['id' => 'srv-1', 'path' => 'api/v1/media/item-123/transcode'],
        );

        $this->assertSame(403, $response->statusCode);
        $this->assertFalse($forwarded, 'Unowned server → the transcode-start POST may not be forwarded.');
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame('server.not_owned', $body['code'] ?? null);
    }

    /**
     * Relay-liveness runs BEFORE the scope gate: a transcode-start POST against
     * an offline server with no live tunnel fails closed with 503
     * `server.offline` and is never forwarded.
     */
    public function test_transcode_start_post_on_offline_server_fails_closed_503(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn(
            ['userId' => 'user-1', 'status' => 'offline', 'relayActive' => false],
        );

        $forwarded = false;
        $controller = $this->controller($info, $this->bridge(static function (string $e, array $d) use (&$forwarded): void {
            $forwarded = true;
        }));

        $response = $controller->proxy(
            $this->request('POST', 'user-1'),
            ['id' => 'srv-1', 'path' => 'api/v1/media/item-123/transcode'],
        );

        $this->assertSame(503, $response->statusCode);
        $this->assertFalse($forwarded, 'Offline server → the transcode-start POST may not be forwarded.');
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame('server.offline', $body['code'] ?? null);
    }

    /**
     * An online server whose secure tunnel isn't connected fails closed with the
     * actionable 503 `server.relay_unavailable` for the transcode-start POST too
     * — the liveness gate precedes the scope gate for every method.
     */
    public function test_transcode_start_post_on_online_server_without_tunnel_returns_503_relay_unavailable(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn(
            ['userId' => 'user-1', 'status' => 'online', 'relayActive' => false],
        );

        $forwarded = false;
        $controller = $this->controller($info, $this->bridge(static function (string $e, array $d) use (&$forwarded): void {
            $forwarded = true;
        }));

        $response = $controller->proxy(
            $this->request('POST', 'user-1'),
            ['id' => 'srv-1', 'path' => 'api/v1/media/item-123/transcode'],
        );

        $this->assertSame(503, $response->statusCode);
        $this->assertFalse($forwarded);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame('server.relay_unavailable', $body['code'] ?? null);
    }

    /**
     * Auth runs FIRST: an unauthenticated transcode-start POST returns 401
     * `auth.required` before ownership, liveness, or scope are ever consulted.
     */
    public function test_transcode_start_post_unauthenticated_returns_401(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $controller = $this->controller($info, $this->bridge(static fn () => null));

        $response = $controller->proxy(
            $this->request('POST', null),
            ['id' => 'srv-1', 'path' => 'api/v1/media/item-123/transcode'],
        );

        $this->assertSame(401, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame('auth.required', $body['code'] ?? null);
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
        // D1 new-prefix streaming paths: the traversal guard runs BEFORE the
        // (now-widened) allowlist, so a dot-segment or encoded separator riding a
        // streaming prefix is still rejected and can never reach a server path.
        yield 'hls streaming dot-dot into admin' => ['hls/job-abc/../../api/v1/admin/users'];
        yield 'media streaming percent-encoded dot-dot' => ['media/%2e%2e/api/v1/admin'];
        yield 'dash streaming encoded separator' => ['dash/job-abc/..%2fadmin'];
        yield 'transcode streaming bare encoded separator' => ['api/v1/transcode%2fadmin'];
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
        $info->method('getOwnerAndStatus')->willReturn(['userId' => 'user-1', 'status' => 'online', 'relayActive' => true]);

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
        $info->method('getOwnerAndStatus')->willReturn(['userId' => 'user-1', 'status' => 'online', 'relayActive' => true]);

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
        $info->method('getOwnerAndStatus')->willReturn(['userId' => 'user-1', 'status' => 'online', 'relayActive' => true]);

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

    // ---------------------------------------------------------------------
    // D3: per-path reply-timeout alignment. Playback-read segment/playlist
    // paths (`/hls`, `/dash`) block on the paired server's on-demand segment
    // encoder, so GET/HEAD to them award the wider streaming ceiling
    // (STREAMING_TIMEOUT_SECONDS = 60s); every other read + the transcode-START
    // POST keep the injected default (DEFAULT_TIMEOUT_SECONDS = 30s). The
    // controller forwards the classified value to the relay bridge so the relay
    // worker's completion timer matches the browser-facing wait.
    // ---------------------------------------------------------------------

    /**
     * IN-SCOPE reply-timeout matrix: paths that clear the browse-scope gate and
     * are forwarded, with the exact reply timeout the controller hands to the
     * relay bridge. Streaming reads → 60.0; every other read + the write → 30.0.
     *
     * @return iterable<string, array{0: string, 1: string, 2: float}>
     */
    public static function forwardedTimeoutProvider(): iterable
    {
        foreach (['GET', 'HEAD'] as $method) {
            // Playback-read segments AND playlists ride the wider ceiling.
            yield "{$method} hls variant playlist -> 60" => [$method, 'hls/job-abc/media_v3.m3u8', 60.0];
            yield "{$method} hls segment -> 60" => [$method, 'hls/job-abc/seg-00007.ts', 60.0];
            yield "{$method} dash manifest -> 60" => [$method, 'dash/job-abc/manifest.mpd', 60.0];
            // Direct-play stream, status polling and JSON browse keep the default.
            yield "{$method} media direct-play stream -> 30" => [$method, 'media/item-123/stream', 30.0];
            yield "{$method} transcode job status -> 30" => [$method, 'api/v1/transcode/job-abc/status', 30.0];
            yield "{$method} json browse -> 30" => [$method, 'api/v1/media', 30.0];
        }

        // The single permitted write (transcode START) keeps the default — only
        // GET/HEAD to a streaming prefix are widened.
        yield 'POST transcode-start -> 30' => ['POST', 'api/v1/media/item-123/transcode', 30.0];
    }

    /**
     * The reply timeout the controller classifies for a request is forwarded to
     * the relay bridge verbatim (the bridge threads it into the published
     * envelope so the relay worker arms an identical completion timer). This is
     * the end-to-end, public-surface proof: drive `proxy()` and capture the
     * `timeout` field of the forwarded envelope.
     *
     * @dataProvider forwardedTimeoutProvider
     */
    public function test_reply_timeout_is_forwarded_to_the_relay_bridge(
        string $method,
        string $path,
        float $expected,
    ): void {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn(['userId' => 'user-1', 'status' => 'online', 'relayActive' => true]);

        /** @var array<string, mixed>|null $forwarded */
        $forwarded = null;
        $bridge = null;
        $publisher = function (string $event, array $data) use (&$bridge, &$forwarded): void {
            $forwarded = $data;
            /** @var RelayProxyBridge $bridge */
            $bridge->onReply([
                'request_id' => $data['request_id'],
                'status' => 200,
                'headers' => ['Content-Type' => 'application/octet-stream'],
                'body' => 'payload',
            ]);
        };
        $bridge = $this->bridge($publisher);

        $controller = $this->controller($info, $bridge);
        $response = $controller->proxy(
            $this->request($method, 'user-1'),
            ['id' => 'srv-1', 'path' => $path],
        );

        // Streaming reads forward from inside the producer; buffered ones already
        // forwarded synchronously. drive() invokes the producer when present.
        $this->drive($response);

        $this->assertSame(200, $response->statusCode);
        $this->assertIsArray($forwarded, "{$method} /{$path} must be forwarded over the relay bridge");
        $this->assertSame(
            $expected,
            $forwarded['timeout'] ?? null,
            "{$method} /{$path} must forward a {$expected}s reply timeout to the relay bridge",
        );
    }

    /**
     * Full classifier matrix for the private `replyTimeoutForPath()` — including
     * cases the end-to-end path cannot observe. A bare sibling of a streaming
     * prefix (`/hlsX`, `/dashboard`) is scope-DENIED (403) so it never reaches
     * the bridge, yet the classifier must still map it to the default (never the
     * wider ceiling); likewise a non-GET/HEAD method against a streaming path.
     * Reflection lets us assert the classifier directly for every case.
     *
     * @return iterable<string, array{0: string, 1: string, 2: float}>
     */
    public static function replyTimeoutClassifierProvider(): iterable
    {
        // Streaming prefixes: exact collection path + representative sub-paths.
        yield 'GET /hls exact' => ['GET', '/hls', 60.0];
        yield 'GET hls variant playlist' => ['GET', '/hls/job-abc/media_v3.m3u8', 60.0];
        yield 'GET hls segment' => ['GET', '/hls/job-abc/seg-00007.ts', 60.0];
        yield 'HEAD hls segment' => ['HEAD', '/hls/job-abc/seg-00007.ts', 60.0];
        yield 'GET /dash exact' => ['GET', '/dash', 60.0];
        yield 'GET dash manifest' => ['GET', '/dash/job-abc/manifest.mpd', 60.0];
        yield 'HEAD dash manifest' => ['HEAD', '/dash/job-abc/manifest.mpd', 60.0];
        // Method is upper-cased before the gate, so a lower-case verb still wins.
        yield 'lower-case get hls segment' => ['get', '/hls/job-abc/seg-00007.ts', 60.0];

        // Non-streaming allowed reads keep the tight default.
        yield 'GET media direct-play stream' => ['GET', '/media/item-123/stream', 30.0];
        yield 'HEAD media stream' => ['HEAD', '/media/item-123/stream', 30.0];
        yield 'GET transcode status' => ['GET', '/api/v1/transcode/job-abc/status', 30.0];
        yield 'GET json browse' => ['GET', '/api/v1/media', 30.0];

        // Bare siblings share a textual prefix but are NOT sub-paths → default,
        // never the wider streaming ceiling (mirrors the browse-scope rule).
        yield 'GET hlsX sibling' => ['GET', '/hlsX/job/master.m3u8', 30.0];
        yield 'GET dashboard sibling' => ['GET', '/dashboard', 30.0];
        yield 'GET dashX sibling' => ['GET', '/dashX/x', 30.0];

        // Method gate: only GET/HEAD are widened; every other verb keeps default
        // even on a real streaming path.
        yield 'POST hls segment' => ['POST', '/hls/job-abc/seg-00007.ts', 30.0];
        yield 'PUT dash manifest' => ['PUT', '/dash/job-abc/manifest.mpd', 30.0];
        yield 'DELETE hls segment' => ['DELETE', '/hls/job-abc/seg-00007.ts', 30.0];
        yield 'POST transcode-start' => ['POST', '/api/v1/media/item-123/transcode', 30.0];
    }

    /**
     * @dataProvider replyTimeoutClassifierProvider
     */
    public function test_reply_timeout_for_path_classifier(string $method, string $path, float $expected): void
    {
        $controller = $this->controller(
            $this->createMock(ServerInfoHandler::class),
            $this->bridge(static fn () => null),
        );

        $reflected = new ReflectionMethod(ServerProxyController::class, 'replyTimeoutForPath');
        $reflected->setAccessible(true);
        /** @var float $timeout */
        $timeout = $reflected->invoke($controller, $method, $path);

        $this->assertSame($expected, $timeout, "{$method} {$path}");
    }

    /**
     * A default injected ABOVE the streaming ceiling is honoured for BOTH
     * families: classifying a path as a stream must never SHORTEN a longer
     * injected wait (the controller takes the max of the default and the
     * streaming ceiling).
     */
    public function test_reply_timeout_never_shortens_a_higher_injected_default(): void
    {
        $rateLimiter = $this->createMock(RateLimiterInterface::class);
        $rateLimiter->method('hit')->willReturn(new RateLimitState(1, 4, time() + 900, false, 5));
        $controller = new ServerProxyController(
            $this->createMock(ServerInfoHandler::class),
            $this->bridge(static fn () => null),
            $this->createMock(StructuredLogger::class),
            $this->createMock(RelaySessionManager::class),
            $rateLimiter,
            90,
        );

        $reflected = new ReflectionMethod(ServerProxyController::class, 'replyTimeoutForPath');
        $reflected->setAccessible(true);

        $this->assertSame(90.0, $reflected->invoke($controller, 'GET', '/hls/job/seg-1.ts'));
        $this->assertSame(90.0, $reflected->invoke($controller, 'GET', '/api/v1/media'));
    }

    /**
     * A default injected BELOW the streaming ceiling is widened to the ceiling
     * for a streaming path (max), while non-streaming reads keep the tighter
     * injected default.
     */
    public function test_reply_timeout_widens_a_lower_injected_default_only_for_streaming(): void
    {
        $rateLimiter = $this->createMock(RateLimiterInterface::class);
        $rateLimiter->method('hit')->willReturn(new RateLimitState(1, 4, time() + 900, false, 5));
        $controller = new ServerProxyController(
            $this->createMock(ServerInfoHandler::class),
            $this->bridge(static fn () => null),
            $this->createMock(StructuredLogger::class),
            $this->createMock(RelaySessionManager::class),
            $rateLimiter,
            10,
        );

        $reflected = new ReflectionMethod(ServerProxyController::class, 'replyTimeoutForPath');
        $reflected->setAccessible(true);

        $this->assertSame(60.0, $reflected->invoke($controller, 'GET', '/dash/job/manifest.mpd'));
        $this->assertSame(10.0, $reflected->invoke($controller, 'GET', '/api/v1/media'));
    }

    // ---------------------------------------------------------------------
    // HB-3.4 G4: cap enforcement at proxy admission.
    // ---------------------------------------------------------------------

    /**
     * HB-3.4 G2/G4: a user over their monthly bandwidth quota is refused with
     * 503 `quota.exceeded` BEFORE the request is forwarded — the quota gate runs
     * for every admitted request (browse and stream alike).
     */
    public function test_over_quota_user_returns_503_quota_exceeded_and_is_not_forwarded(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn(['userId' => 'user-1', 'status' => 'online', 'relayActive' => true]);

        $sessionManager = $this->createMock(RelaySessionManager::class);
        $sessionManager->method('checkUserQuota')->willReturn([
            'allowed' => false,
            'reason' => 'User has reached their monthly download bandwidth quota.',
            'maxConcurrentStreams' => 0,
        ]);

        $forwarded = false;
        $bridge = $this->bridge(static function (string $e, array $d) use (&$forwarded): void {
            $forwarded = true;
        });

        $controller = $this->controller($info, $bridge, $sessionManager);
        $response = $controller->proxy(
            $this->request('GET', 'user-1'),
            ['id' => 'srv-1', 'path' => 'api/v1/libraries'],
        );

        $this->assertSame(503, $response->statusCode);
        $this->assertFalse($forwarded, 'An over-quota request must never reach the relay bridge.');
        /** @var array{error?: array{code?: string, message?: string}} $body */
        $body = json_decode($response->body, true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame('quota.exceeded', $body['error']['code'] ?? null);
    }

    /**
     * HB-3.4 G3/G4: when a user is already streaming at their configured
     * concurrent-stream maximum, a further stream is refused with 503
     * `stream.limit` BEFORE any slot is occupied or anything forwarded.
     */
    public function test_concurrent_stream_cap_reached_returns_503_stream_limit_and_is_not_forwarded(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn(['userId' => 'user-1', 'status' => 'online', 'relayActive' => true]);

        $sessionManager = $this->createMock(RelaySessionManager::class);
        // Max of 2, already at 2 active → the 3rd stream is refused. The cap is
        // folded into the single checkUserQuota row read (HB-3.4 hot-path fix).
        $sessionManager->method('checkUserQuota')->willReturn([
            'allowed' => true,
            'reason' => null,
            'maxConcurrentStreams' => 2,
        ]);
        $sessionManager->method('activeUserStreams')->willReturn(2);
        // The streaming branch must NOT issue a second row read: the separate
        // getUserMaxConcurrentStreams() round-trip is eliminated on the hot path.
        $sessionManager->expects(self::never())->method('getUserMaxConcurrentStreams');
        // The refused stream must NOT occupy a slot.
        $sessionManager->expects(self::never())->method('beginUserStream');

        $forwarded = false;
        $bridge = $this->bridge(static function (string $e, array $d) use (&$forwarded): void {
            $forwarded = true;
        });

        $controller = $this->controller($info, $bridge, $sessionManager);
        $response = $controller->proxy(
            $this->request('GET', 'user-1'),
            ['id' => 'srv-1', 'path' => 'hls/job-abc/seg-00007.ts'],
        );
        // A refused stream returns a buffered 503 JSON error, not a producer.
        $this->assertNull($response->streamProducer, 'A capped stream must not return a streaming producer.');
        $this->assertSame(503, $response->statusCode);
        $this->assertFalse($forwarded, 'A capped stream must never reach the relay bridge.');
        /** @var array{error?: array{code?: string, message?: string}} $body */
        $body = json_decode($response->body, true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame('stream.limit', $body['error']['code'] ?? null);
    }

    /**
     * HB-3.4 G3/G4: a stream UNDER the concurrent cap is admitted — it occupies a
     * slot on start, is forwarded, and releases the slot once the producer
     * completes (no leak).
     */
    public function test_stream_under_concurrent_cap_is_admitted_occupies_then_releases_slot(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn(['userId' => 'user-1', 'status' => 'online', 'relayActive' => true]);

        $sessionManager = $this->createMock(RelaySessionManager::class);
        // The concurrent cap (3) is folded into the single checkUserQuota row
        // read (HB-3.4 hot-path fix) — no second getUserMaxConcurrentStreams read.
        $sessionManager->method('checkUserQuota')->willReturn([
            'allowed' => true,
            'reason' => null,
            'maxConcurrentStreams' => 3,
        ]);
        $sessionManager->method('activeUserStreams')->willReturn(0);
        // The streaming branch must NOT issue a second identical row read.
        $sessionManager->expects(self::never())->method('getUserMaxConcurrentStreams');
        // Slot is occupied exactly once and released exactly once (no leak).
        $sessionManager->expects(self::once())->method('beginUserStream')->with('user-1');
        $sessionManager->expects(self::once())->method('endUserStream')->with('user-1');
        // The real bytes streamed (foo+bar = 6) are metered as the user's download.
        $sessionManager->expects(self::once())->method('recordUserBandwidth')
            ->with('user-1', 6, 0);

        $bridge = null;
        $publisher = function (string $event, array $data) use (&$bridge): void {
            /** @var RelayProxyBridge $bridge */
            $id = $data['request_id'];
            $bridge->onReply(['request_id' => $id, 'phase' => 'head', 'status' => 200, 'headers' => [
                'Content-Type' => 'video/mp2t',
                'Content-Length' => '6',
            ]]);
            $bridge->onReply(['request_id' => $id, 'phase' => 'body', 'body' => 'foo']);
            $bridge->onReply(['request_id' => $id, 'phase' => 'body', 'body' => 'bar']);
            $bridge->onReply(['request_id' => $id, 'phase' => 'end']);
        };
        $bridge = $this->bridge($publisher);

        $controller = $this->controller($info, $bridge, $sessionManager);
        $response = $controller->proxy(
            $this->request('GET', 'user-1'),
            ['id' => 'srv-1', 'path' => 'hls/job-abc/seg-00007.ts'],
        );

        $this->assertNotNull($response->streamProducer);
        $connection = $this->drive($response);
        $this->assertStringContainsString('HTTP/1.1 200 OK', $connection->written[0]);
    }
}
