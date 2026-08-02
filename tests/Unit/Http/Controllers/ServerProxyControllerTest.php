<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Controllers;

use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Auth\RateLimitException;
use Phlix\Hub\Common\RateLimit\RateLimiter;
use Phlix\Hub\Common\RateLimit\RateLimiterInterface;
use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\Http\Controllers\ServerProxyController;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Hub\Http\Router;
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
use ReflectionClass;
use ReflectionMethod;
use Workerman\Connection\TcpConnection;

use function array_keys;
use function array_pop;
use function base64_encode;
use function count;
use function dechex;
use function explode;
use function implode;
use function is_string;
use function json_decode;
use function json_encode;
use function ltrim;
use function ord;
use function str_repeat;
use function str_split;
use function strlen;
use function strtoupper;
use function substr;

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

    private function controller(
        ServerInfoHandler $info,
        RelayProxyBridge $bridge,
        ?RelaySessionManager $sessionManager = null,
        ?RateLimiterInterface $rateLimiter = null,
    ): ServerProxyController {
        if ($sessionManager === null) {
            $sessionManager = $this->createMock(RelaySessionManager::class);
            // Default: quota check always allows (quota not exceeded), concurrent
            // cap unlimited (0). The concurrent cap is folded into checkUserQuota's
            // single row read (HB-3.4 hot-path fix), so it is returned here too.
            $sessionManager->method('checkUserQuota')->willReturn(
                ['allowed' => true, 'reason' => null, 'maxConcurrentStreams' => 0],
            );
        }
        // HB-4.6b: use the REAL per-surface proxy RateLimiter (600/60s) rather
        // than a limited:false stub, so tests exercise the actual limiter. A
        // single hit per test never trips 600/60s.
        $rateLimiter ??= new RateLimiter(60, 600);
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
     * HB-4.6b LANDMINE: a normal multi-segment HLS playback burst (tens of
     * segment/playlist GETs per minute across variants) must NEVER trip the
     * proxy limiter. Drive 100 authenticated proxy GETs through a REAL
     * `rate_limiter.proxy` (600/60s) with a frozen clock (single window) and
     * assert none throw {@see RateLimitException} — the limiter is keyed by the
     * authenticated user (`proxy:{userId}`) placed AFTER the auth gate.
     */
    public function test_normal_hls_segment_burst_of_100_never_trips_proxy_limiter(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn(null); // owner null → 404 after the hit

        // Frozen clock → all 100 hits fall in ONE window (worst case for a burst).
        $rateLimiter = new RateLimiter(60, 600, 10000, static fn (): int => 1_000_000);
        $controller = $this->controller($info, $this->bridge(static fn () => null), null, $rateLimiter);

        for ($i = 0; $i < 100; $i++) {
            $response = $controller->proxy(
                $this->request('GET', 'user-1'),
                ['id' => 'srv-1', 'path' => 'hls/job/seg-' . $i . '.ts'],
            );
            // 404 (owner null) proves we passed the limiter without a trip.
            $this->assertSame(404, $response->statusCode, 'segment #' . $i . ' should pass the limiter');
        }
    }

    /**
     * HB-4.6b: exceeding the 600/60s proxy budget within a single window DOES
     * trip — the 601st hit throws {@see RateLimitException} (mapped to 429 later
     * in HB-4.6g). Confirms the limiter is real, not a `limited:false` stub.
     */
    public function test_proxy_limiter_trips_after_exceeding_600_in_window(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn(null);

        $rateLimiter = new RateLimiter(60, 600, 10000, static fn (): int => 1_000_000);
        $controller = $this->controller($info, $this->bridge(static fn () => null), null, $rateLimiter);

        // First 599 hits are within budget (owner null → 404); the limiter trips
        // when the count reaches the 600/60s ceiling (limited when count >= max).
        for ($i = 0; $i < 599; $i++) {
            $response = $controller->proxy(
                $this->request('GET', 'user-1'),
                ['id' => 'srv-1', 'path' => 'hls/job/seg-' . $i . '.ts'],
            );
            $this->assertSame(404, $response->statusCode, 'hit #' . $i . ' should be within budget');
        }

        // The 600th hit reaches the ceiling and trips the limiter.
        $this->expectException(RateLimitException::class);
        $controller->proxy(
            $this->request('GET', 'user-1'),
            ['id' => 'srv-1', 'path' => 'hls/job/seg-600.ts'],
        );
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

    // ---------------------------------------------------------------------
    // S100: music library browse scope (`/api/v1/music`).
    // ---------------------------------------------------------------------

    /**
     * Every alternative SPELLING of `POST /api/v1/music/scan` that the raw-path
     * deny check used to FORWARD (S100 fix r2, MED-1). Shared by the gate-level
     * matrix and the end-to-end deny test so both layers pin the same seventeen.
     *
     * Four evasion families, each corresponding to one normalisation a downstream
     * stack plausibly performs and phlix-server happens not to:
     *  - percent-encoding of the route literal itself (`%73can`, `%2573can` — the
     *    hub must decode to a fixed point, not compare the raw bytes);
     *  - duplicate separators (`proxy()` collapses only LEADING slashes);
     *  - path parameters and trailing dot/space (`scan;x`, `scan.`, `scan%20`);
     *  - **COMPOSITIONS of two of the above** (S100 fix r3): a trailing dot/space
     *    on the `scan` segment stops being trailing on the PATH the instant
     *    anything follows it, so a single tail `rtrim()` denied `scan.` while
     *    forwarding `scan./`, `scan.;x`, `scan%20/` and `scan./status` — each one
     *    character from a row already pinned as denied. The fix trims per SEGMENT;
     *    these rows are what stop it regressing to a tail trim.
     *
     * @return array<string, string> label => `/`-prefixed path
     */
    private static function scanSpellingEvasions(): array
    {
        return [
            'double slash' => '/api/v1/music//scan',
            'triple slash' => '/api/v1/music///scan',
            'path parameter' => '/api/v1/music/scan;x',
            'bare semicolon' => '/api/v1/music/scan;',
            'trailing dot' => '/api/v1/music/scan.',
            'trailing encoded space' => '/api/v1/music/scan%20',
            'trailing encoded dot' => '/api/v1/music/scan%2e',
            'encoded s' => '/api/v1/music/%73can',
            'encoded c' => '/api/v1/music/s%63an',
            'encoded n' => '/api/v1/music/sca%6e',
            'fully encoded upper-case' => '/api/v1/music/%53%43%41%4e',
            'double-encoded s' => '/api/v1/music/%2573can',
            // Compositions (r3): dot/space + a following separator or parameter.
            'trailing dot then slash' => '/api/v1/music/scan./',
            'trailing dot then bare semicolon' => '/api/v1/music/scan.;',
            'trailing dot then path parameter' => '/api/v1/music/scan.;x',
            'encoded trailing space then slash' => '/api/v1/music/scan%20/',
            'trailing dot then sub-path' => '/api/v1/music/scan./status',
        ];
    }

    /**
     * S100 scope matrix for the music prefix, asserted DIRECTLY against
     * {@see ServerProxyController::isWithinBrowseScope()} so both the allow and
     * the DENY side are pinned — a test that only asserted the allow case would
     * still pass if the gate were removed altogether.
     *
     * Allowed: every music READ the SPA issues, under **GET**, reached via the
     * single `/api/v1/music` prefix (exact collection path + `/`-delimited
     * sub-paths, including the two-segment `tracks/{id}` the player calls at play
     * time to mint a `stream_url`), and including the percent-encoded artist/album
     * NAMES the SPA actually sends (`encodeURIComponent(name)`).
     *
     * Denied: (a) `POST /api/v1/music/scan` — a REAL server route registered
     * under the same prefix (`WebPortalRouter`), so it proves the prefix did not
     * leak a write — plus every READ verb on that same scan path, which
     * `SCOPE_DENY_PATTERNS` refuses so the hub does not rely on the server having
     * registered it POST-only; (b) every other write verb on a music path, incl.
     * PATCH; (c) bare textual siblings (`/api/v1/musicXYZ`,
     * `/api/v1/music-admin`) which share the prefix string but are not sub-paths;
     * (d) an unlisted sibling API family (`/api/v1/musicbrainz`); (e) **HEAD on
     * every music path** — S100 fix round 1 removed the inert HEAD mirror (the hub
     * router registers no HEAD route at all, see
     * {@see self::test_head_is_never_routed_to_the_relay_proxy()}); (f) **every
     * alternative SPELLING of the scan path** (S100 fix r2, MED-1) — see below.
     *
     * @return iterable<string, array{0: string, 1: string, 2: bool}>
     */
    public static function musicBrowseScopeProvider(): iterable
    {
        $allowedPaths = [
            'music collection root' => '/api/v1/music',
            'artists list' => '/api/v1/music/artists',
            'artist detail' => '/api/v1/music/artists/mbid-123',
            'albums list' => '/api/v1/music/albums',
            'album detail' => '/api/v1/music/albums/mbid-456',
            'tracks list' => '/api/v1/music/tracks',
            'track by id' => '/api/v1/music/tracks/track-789',
            'now playing' => '/api/v1/music/now-playing',
        ];

        foreach ($allowedPaths as $label => $path) {
            yield "GET {$label} allowed" => ['GET', $path, true];
            // (e) HEAD grants nothing: the hub registers no HEAD proxy route, so
            // mirroring the prefix under HEAD would be dead configuration.
            yield "HEAD {$label} denied" => ['HEAD', $path, false];
        }

        // Lower-case verbs are upper-cased before the gate.
        yield 'lower-case get artists allowed' => ['get', '/api/v1/music/artists', true];

        // The scan deny is anchored on the FULL path, never a bare `scan`
        // segment: artist/album ids are NAMES, so a band called "Scan" must still
        // be browsable.
        yield 'GET artist named scan allowed' => ['GET', '/api/v1/music/artists/Scan', true];
        yield 'GET album named rescan allowed' => ['GET', '/api/v1/music/albums/Rescan', true];
        // Percent-encoded names are how the SPA sends multi-word artists/albums.
        yield 'GET percent-encoded artist name allowed' => ['GET', '/api/v1/music/artists/Pink%20Floyd', true];

        // (a) The scan POST is a real route on the SAME prefix — must stay denied,
        // and so must every READ verb on it (SCOPE_DENY_PATTERNS).
        yield 'POST music scan denied' => ['POST', '/api/v1/music/scan', false];
        yield 'GET music scan denied' => ['GET', '/api/v1/music/scan', false];
        yield 'HEAD music scan denied' => ['HEAD', '/api/v1/music/scan', false];
        yield 'GET music scan sub-path denied' => ['GET', '/api/v1/music/scan/status', false];
        yield 'GET music scan mixed case denied' => ['GET', '/api/v1/music/SCAN', false];
        yield 'POST artists denied' => ['POST', '/api/v1/music/artists', false];

        // (f) S100 fix r2 (MED-1): the deny pin is matched against every DECODING
        // of the path, each normalised (`;` as a segment terminator, duplicate `/`
        // collapsed, trailing `.`/space stripped) — not against the raw literal
        // spelling. All twelve of these were FORWARDED before that change, and
        // 404'd only because phlix-server happens not to decode `Request::$path`,
        // not to collapse `//`, and not to strip path parameters. Relying on that
        // is exactly the accidental peer dependency `SCOPE_DENY_PATTERNS` exists
        // to remove, so each spelling is pinned here.
        foreach (self::scanSpellingEvasions() as $label => $path) {
            yield "GET music scan via {$label} denied" => ['GET', $path, false];
        }
        // ...and the write landmine stays shut for every one of them too.
        yield 'POST music scan percent-encoded s denied' => ['POST', '/api/v1/music/%73can', false];
        yield 'POST music scan double-slash denied' => ['POST', '/api/v1/music//scan', false];

        // Near-misses that the new deny normalisation must NOT catch. A 403 on
        // real browse traffic is the exact failure mode S100 was created to
        // remove, so the false-positive boundary is pinned as tightly as the
        // deny side: `scan` must be a WHOLE segment at the head of the music
        // path, never a prefix of a longer segment and never a deeper segment.
        yield 'GET scanner sibling route allowed' => ['GET', '/api/v1/music/scanner', true];
        yield 'GET artist named Scanner Darkly allowed' => ['GET', '/api/v1/music/artists/Scanner%20Darkly', true];
        yield 'GET artist named Scandal allowed' => ['GET', '/api/v1/music/artists/Scandal', true];
        yield 'GET albums of a band called Scan allowed' => ['GET', '/api/v1/music/artists/Scan/albums', true];
        yield 'GET track named scan allowed' => ['GET', '/api/v1/music/tracks/scan', true];
        // A semicolon inside a NAME is normalised to `/` for deny matching only;
        // that must not manufacture a match, and must not affect the allowlist.
        yield 'GET artist name containing a semicolon allowed' => ['GET', '/api/v1/music/artists/A;B', true];
        // r3: the trim is now PER SEGMENT, so it reaches names it previously could
        // not. It must still only ever match `scan` directly under `/api/v1/music`.
        yield 'GET album named Etc. allowed' => ['GET', '/api/v1/music/albums/Etc.', true];
        yield 'GET scanner with a path parameter allowed' => ['GET', '/api/v1/music/scanner;x', true];
        yield 'GET band called Scan. with a trailing dot allowed' => [
            'GET',
            '/api/v1/music/artists/Scan./albums',
            true,
        ];

        // (b) Every other write verb on a music path fails closed.
        yield 'PUT music track denied' => ['PUT', '/api/v1/music/tracks/track-789', false];
        yield 'DELETE music track denied' => ['DELETE', '/api/v1/music/tracks/track-789', false];
        yield 'PATCH music track denied' => ['PATCH', '/api/v1/music/tracks/track-789', false];
        yield 'PATCH music root denied' => ['PATCH', '/api/v1/music', false];

        // (c) Bare textual siblings are NOT sub-paths of the prefix.
        yield 'GET musicXYZ sibling denied' => ['GET', '/api/v1/musicXYZ', false];
        yield 'GET musicXYZ sub-path denied' => ['GET', '/api/v1/musicXYZ/artists', false];
        yield 'GET music-admin sibling denied' => ['GET', '/api/v1/music-admin/scan', false];
        yield 'HEAD musicXYZ sibling denied' => ['HEAD', '/api/v1/musicXYZ', false];

        // (d) An unlisted neighbouring API family stays denied.
        yield 'GET musicbrainz denied' => ['GET', '/api/v1/musicbrainz/artists', false];

        // Control: an admin path must never ride the widened allowlist.
        yield 'GET admin music denied' => ['GET', '/api/v1/admin/music/scan', false];
    }

    /**
     * @dataProvider musicBrowseScopeProvider
     */
    public function test_music_browse_scope_gate(string $method, string $path, bool $expected): void
    {
        $controller = $this->controller(
            $this->createMock(ServerInfoHandler::class),
            $this->bridge(static fn () => null),
        );

        $reflected = new ReflectionMethod(ServerProxyController::class, 'isWithinBrowseScope');
        $reflected->setAccessible(true);
        /** @var bool $inScope */
        $inScope = $reflected->invoke($controller, $method, $path);

        $this->assertSame(
            $expected,
            $inScope,
            $expected
                ? "{$method} {$path} must be within browse scope"
                : "{$method} {$path} must fail closed (out of browse scope)",
        );
    }

    /**
     * S100 end-to-end: a music browse GET clears every gate and is forwarded over
     * the relay bridge with the path intact. Before the `/api/v1/music` allowlist
     * entry this was 403 `proxy.scope_denied`, which the SPA's
     * `catch { artists.value = [] }` rendered as an EMPTY music library.
     */
    public function test_music_artists_get_is_forwarded(): void
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
                'body' => '{"artists":[]}',
            ]);
        };
        $bridge = $this->bridge($publisher);

        $controller = $this->controller($info, $bridge);
        $response = $controller->proxy(
            $this->request('GET', 'user-1'),
            ['id' => 'srv-1', 'path' => 'api/v1/music/artists'],
        );

        $this->assertSame(200, $response->statusCode);
        $this->assertIsArray($forwarded);
        $this->assertSame('/api/v1/music/artists', $forwarded['path']);
    }

    /**
     * S100 end-to-end: the two-segment `tracks/{id}` read (the player mints a
     * `stream_url` from it at play time) is covered by the same prefix.
     */
    public function test_music_track_by_id_get_is_forwarded(): void
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
                'body' => '{"track":{"id":"track-789"}}',
            ]);
        };
        $bridge = $this->bridge($publisher);

        $controller = $this->controller($info, $bridge);
        $response = $controller->proxy(
            $this->request('GET', 'user-1'),
            ['id' => 'srv-1', 'path' => 'api/v1/music/tracks/track-789'],
        );

        $this->assertSame(200, $response->statusCode);
        $this->assertIsArray($forwarded);
        $this->assertSame('/api/v1/music/tracks/track-789', $forwarded['path']);
    }

    /**
     * S100 deny side, end-to-end. The widened READ prefix must not have leaked a
     * write: `POST /api/v1/music/scan` is a real server route under the same
     * prefix, and a bare sibling (`/api/v1/musicXYZ`) is not a sub-path. Both
     * must 403 `proxy.scope_denied` and never reach the relay bridge.
     *
     * The READ verbs on that same scan path are pinned here too (S100 fix round 1,
     * MED-3): before `SCOPE_DENY_PATTERNS` they were FORWARDED and 404'd only
     * because phlix-server registers `scan` POST-only — the server's route table
     * doing the hub gate's job.
     *
     * S100 fix r2 (MED-1) extends that end-to-end pin to every alternative
     * SPELLING of the scan path ({@see self::scanSpellingEvasions()}): all twelve
     * cleared the raw-path deny check and reached the relay bridge, which is what
     * made the docblock's "the hub's own gate is authoritative" claim false. S100
     * fix r3 adds the five COMPOSITION spellings (`scan./`, `scan.;`, `scan.;x`,
     * `scan%20/`, `scan./status`) that the tail-only trim still forwarded.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function deniedMusicScopeProvider(): iterable
    {
        yield 'POST music scan (write on the read prefix)' => ['POST', 'api/v1/music/scan'];
        yield 'GET music scan (read verb on the scan trigger)' => ['GET', 'api/v1/music/scan'];
        yield 'GET music scan sub-path' => ['GET', 'api/v1/music/scan/status'];
        yield 'GET music scan upper-case' => ['GET', 'api/v1/music/SCAN'];
        yield 'PUT music track' => ['PUT', 'api/v1/music/tracks/track-789'];
        yield 'DELETE music track' => ['DELETE', 'api/v1/music/tracks/track-789'];
        yield 'PATCH music artists' => ['PATCH', 'api/v1/music/artists'];
        // POST on an ORDINARY music read path — the gap a mutation exposed. Every
        // other write verb (PUT/DELETE/PATCH) was pinned end to end here, but POST
        // only ever appeared on `/scan`, where `SCOPE_DENY_PATTERNS` refuses it
        // before either scope map is read. So a widening of the POST scope — the
        // verb every real server write actually uses, and the one an S107-style
        // sweep is most likely to touch — was pinned only by the reflection-level
        // gate matrix, never through `proxy()`. Both shapes: the prefix itself and
        // a sub-path under it.
        yield 'POST music root (write verb on the prefix itself)' => ['POST', 'api/v1/music'];
        yield 'POST music artists (write verb on a read sub-path)' => ['POST', 'api/v1/music/artists'];
        yield 'GET musicXYZ sibling' => ['GET', 'api/v1/musicXYZ'];
        yield 'GET musicbrainz sibling family' => ['GET', 'api/v1/musicbrainz/artists'];

        // MED-1: every spelling, end to end — 403 and never forwarded.
        foreach (self::scanSpellingEvasions() as $label => $path) {
            yield "GET music scan via {$label}" => ['GET', ltrim($path, '/')];
        }
        yield 'POST music scan via encoded s' => ['POST', 'api/v1/music/%73can'];
        yield 'POST music scan via double slash' => ['POST', 'api/v1/music//scan'];
    }

    /**
     * @dataProvider deniedMusicScopeProvider
     */
    public function test_denied_music_paths_return_403_and_are_not_forwarded(string $method, string $path): void
    {
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

        $this->assertSame(403, $response->statusCode, "{$method} /{$path} must fail closed");
        $this->assertFalse($forwarded, "{$method} /{$path} must never reach the relay bridge");
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame('proxy.scope_denied', $body['code'] ?? null);
    }

    /**
     * S100 must not weaken the DLNA posture. The hub allowlist has no `/dlna`
     * entry (phlix-server's `RelayRequestDispatcher` additionally hard-denies
     * `/dlna/`, `/cds/`, `/scpd/` and `/description.xml` precisely because it
     * trusts that omission). `/api/v1/music` and `/dlna` are separate surfaces:
     * allowing music must leave every DLNA path scope-denied.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function dlnaStillDeniedProvider(): iterable
    {
        yield 'dlna description' => ['/dlna/description.xml'];
        yield 'dlna content directory' => ['/dlna/content_directory'];
        yield 'dlna stream' => ['/dlna/stream/item-123'];
        yield 'cds control' => ['/cds/control'];
        yield 'scpd service' => ['/scpd/ContentDirectory.xml'];
        yield 'bare description.xml' => ['/description.xml'];
    }

    /**
     * @dataProvider dlnaStillDeniedProvider
     */
    public function test_dlna_paths_remain_out_of_browse_scope(string $path): void
    {
        $controller = $this->controller(
            $this->createMock(ServerInfoHandler::class),
            $this->bridge(static fn () => null),
        );

        $reflected = new ReflectionMethod(ServerProxyController::class, 'isWithinBrowseScope');
        $reflected->setAccessible(true);

        foreach (['GET', 'HEAD'] as $method) {
            $this->assertFalse(
                $reflected->invoke($controller, $method, $path),
                "{$method} {$path} must stay out of browse scope (DLNA is a separate surface)",
            );
        }
    }

    /**
     * S100 fix round 1 (MED-2), END-TO-END THROUGH THE REAL ROUTER — the whole
     * point of this test is that it does NOT use reflection on a private constant.
     *
     * `ServerProxyController::BROWSE_SCOPE_ALLOWLIST` used to carry a `HEAD` key
     * (music included), and the docblock + CHANGELOG advertised HEAD as working.
     * It never was: {@see Router} has no `head()` registrar,
     * {@see Router::dispatch()} 404s an unregistered method with no HEAD→GET
     * fallback, and {@see \Phlix\Hub\Application} registers the proxy catch-all for
     * GET/PUT/DELETE/PATCH/POST only. Reflection-only assertions over the constant
     * could never catch that class of dead configuration; a dispatch-level test
     * can.
     *
     * Registers exactly the five methods `Application::registerRoutes()` registers,
     * then proves:
     *  - the router has NO `HEAD` bucket at all;
     *  - `HEAD` on an allowlisted music path 404s in the ROUTER (not 403 from the
     *    controller) and never reaches the relay bridge;
     *  - the same for a playback path, whose HEAD machinery does exist (HB-0.3);
     *  - the identical `GET` is forwarded and answered 200, so the 404 is about the
     *    METHOD and nothing else.
     *
     * ⚠ If HEAD is ever made routable, this test is the tripwire: it must be
     * updated TOGETHER with a `Router::head()` registrar, a HEAD proxy route, a
     * HEAD allowlist key, and body suppression on the buffered reply path. That
     * coupling is exactly what this asserts.
     */
    public function test_head_is_never_routed_to_the_relay_proxy(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn(['userId' => 'user-1', 'status' => 'online', 'relayActive' => true]);

        /** @var list<array<string, mixed>> $forwarded */
        $forwarded = [];
        $bridge = null;
        $publisher = function (string $event, array $data) use (&$bridge, &$forwarded): void {
            $forwarded[] = $data;
            /** @var RelayProxyBridge $bridge */
            $bridge->onReply([
                'request_id' => $data['request_id'],
                'status' => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => '{"artists":[]}',
            ]);
        };
        $bridge = $this->bridge($publisher);
        $controller = $this->controller($info, $bridge);

        // Mirror Application::registerRoutes(): the proxy catch-all lives under the
        // `/api/v1` group and is registered for GET/PUT/DELETE/PATCH/POST — and
        // NOTHING else.
        $handler = static function (Request $req, array $params) use ($controller): Response {
            /** @var array<string, string> $typedParams */
            $typedParams = $params;
            return $controller->proxy($req, $typedParams);
        };
        $router = new Router();
        $router->group('/api/v1', static function (Router $r) use ($handler): void {
            $r->get('/servers/{id}/proxy/{path:.*}', $handler);
            $r->put('/servers/{id}/proxy/{path:.*}', $handler);
            $r->delete('/servers/{id}/proxy/{path:.*}', $handler);
            $r->patch('/servers/{id}/proxy/{path:.*}', $handler);
            $r->post('/servers/{id}/proxy/{path:.*}', $handler);
        });

        $this->assertArrayNotHasKey(
            'HEAD',
            $router->getRoutes(),
            'The hub router has no HEAD bucket: a HEAD allowlist entry would be dead configuration',
        );

        $dispatch = static function (string $method, string $tail) use ($router): Response {
            $req = new Request();
            $req->method = $method;
            $req->path = '/api/v1/servers/srv-1/proxy/' . $tail;
            $req->userId = 'user-1';
            $req->headers = ['Accept' => 'application/json'];
            return $router->dispatch($req);
        };

        // GET is routed, cleared and forwarded — the control case.
        $getResponse = $dispatch('GET', 'api/v1/music/artists');
        $this->assertSame(200, $getResponse->statusCode);
        $this->assertCount(1, $forwarded, 'GET must reach the relay bridge');
        $this->assertSame('/api/v1/music/artists', $forwarded[0]['path']);

        // HEAD dies in the router with a 404 — not a 403 from the scope gate, and
        // never a forward.
        foreach (['api/v1/music/artists', 'media/item-123/stream', 'hls/job-abc/seg-00007.ts'] as $tail) {
            $headResponse = $dispatch('HEAD', $tail);
            $this->assertSame(
                404,
                $headResponse->statusCode,
                "HEAD /{$tail} must 404 in the router (no HEAD route is registered)",
            );
            /** @var array<string, mixed> $body */
            $body = json_decode($headResponse->body, true, 8, JSON_THROW_ON_ERROR);
            $this->assertSame(
                'Not Found',
                $body['error'] ?? null,
                "HEAD /{$tail} must be refused by the ROUTER, not reach the controller's scope gate",
            );
            $this->assertCount(1, $forwarded, "HEAD /{$tail} must never reach the relay bridge");
        }
    }

    /**
     * S100 fix round 1 (MED-3) regression guard for the DECODE-SAFE traversal
     * check: the eight legitimate music reads must still be forwarded, including
     * the PERCENT-ENCODED artist/album names the SPA actually sends
     * (`ApiClient` builds `/api/v1/music/artists/${encodeURIComponent(name)}` —
     * artist and album ids are NAMES, not MBIDs). This is why the guard decodes
     * until stable instead of rejecting every literal `%`: a blanket `%` rejection
     * would 403 every multi-word artist and album.
     *
     * S100 fix r2 adds three groups of rows, all guarding against a FALSE POSITIVE
     * — a 403 on real browse traffic is precisely the failure mode S100 exists to
     * remove, and the SPA renders it as an empty library rather than an error:
     *  - **MED-2**, the decode CAP's value: `%2525252520` is an artist literally
     *    named `%25252520`, needing exactly `MAX_TRAVERSAL_DECODE_PASSES` (5)
     *    decodings to reach a fixed point. Lowering the cap makes the guard's
     *    "still decoding at the cap → reject" branch fire on a legitimate name, so
     *    this row fails the moment anyone "simplifies" the constant.
     *  - **LOW-4**, names that CONTAIN dots: the dot-segment test is a strict
     *    whole-segment `=== '.'`/`=== '..'`, so `...`, `S.C.I.E.N.C.E.` and
     *    `... And Justice For All` must forward. Widening it to a `str_contains`
     *    to "harden" traversal would 403 all three.
     *  - **MED-1**, the deny-normalisation boundary: `scanner`, `Scanner Darkly`
     *    and a `;` inside a name must not be caught by the now decode-aware +
     *    normalised `SCOPE_DENY_PATTERNS` match.
     *
     * S100 fix r3 adds a fourth group for the PER-SEGMENT trim: names carrying a
     * trailing dot, a trailing space or a semicolon somewhere OTHER than the last
     * segment (`Etc.`, `Wish You Were Here.`, `Vol. 1 `, `Sun;set`, `scanner;x`,
     * `Scan./albums`). The trim now reaches them, and each row asserts the path is
     * still forwarded BYTE-IDENTICALLY — normalisation is deny-match only.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function legitimateMusicReadProvider(): iterable
    {
        yield 'artists list' => ['api/v1/music/artists'];
        yield 'artist detail' => ['api/v1/music/artists/mbid-123'];
        yield 'albums list' => ['api/v1/music/albums'];
        yield 'album detail' => ['api/v1/music/albums/mbid-456'];
        yield 'tracks list' => ['api/v1/music/tracks'];
        yield 'track by id' => ['api/v1/music/tracks/track-789'];
        yield 'now playing' => ['api/v1/music/now-playing'];
        yield 'music collection root' => ['api/v1/music'];
        // Percent-encoded names: space, ampersand, apostrophe, non-ASCII.
        yield 'encoded space in artist name' => ['api/v1/music/artists/Pink%20Floyd'];
        yield 'encoded ampersand in artist name' => ['api/v1/music/artists/Earth%2C%20Wind%20%26%20Fire'];
        yield 'encoded apostrophe in album name' => ['api/v1/music/albums/Guns%20N%27%20Roses'];
        yield 'encoded non-ascii in artist name' => ['api/v1/music/artists/Bj%C3%B6rk'];
        yield 'encoded dot in album name' => ['api/v1/music/albums/Vol%2E%201'];
        yield 'artist literally named scan' => ['api/v1/music/artists/Scan'];
        // MED-2: pins the VALUE of MAX_TRAVERSAL_DECODE_PASSES. `%2525252520`
        // decodes 5× (`%25252520` → `%252520` → `%2520` → `%20` → ' ') and only
        // then reaches a fixed point, so it is forwarded at cap 5 and REJECTED at
        // any lower cap. Deliberately paired with the ≥6-layer traversal row in
        // `traversalPathProvider`, which pins the same branch in the other
        // direction.
        yield 'artist name needing exactly 5 decodings' => ['api/v1/music/artists/%2525252520'];
        // LOW-4: dots INSIDE a name are not dot-segments.
        yield 'album named three dots' => ['api/v1/music/albums/...'];
        yield 'album named four dots' => ['api/v1/music/albums/....'];
        yield 'album with dotted initials' => ['api/v1/music/albums/S.C.I.E.N.C.E.'];
        yield 'album starting with an ellipsis' => ['api/v1/music/albums/...%20And%20Justice%20For%20All'];
        // MED-1: the deny normalisation must not over-reach.
        yield 'scanner is not the scan route' => ['api/v1/music/scanner'];
        yield 'artist named Scanner Darkly' => ['api/v1/music/artists/Scanner%20Darkly'];
        yield 'albums of a band called Scan' => ['api/v1/music/artists/Scan/albums'];
        yield 'artist name containing a semicolon' => ['api/v1/music/artists/A;B'];
        // r3: the deny trim is now PER SEGMENT rather than on the path tail, so it
        // sees trailing dots/spaces on names in the MIDDLE of a path for the first
        // time. These rows pin that widening as deny-match-only: the name keeps its
        // dot/space/semicolon on the wire (the test asserts the forwarded path is
        // byte-identical), and nothing under `artists|albums|tracks` can normalise
        // into `/api/v1/music/scan`.
        yield 'album named Etc. with a trailing dot' => ['api/v1/music/albums/Etc.'];
        yield 'album ending in a dot' => ['api/v1/music/albums/Wish%20You%20Were%20Here.'];
        yield 'album with a trailing encoded space' => ['api/v1/music/albums/Vol.%201%20'];
        yield 'artist name with an encoded semicolon' => ['api/v1/music/artists/Sun%3Bset'];
        yield 'scanner with a path parameter' => ['api/v1/music/scanner;x'];
        yield 'band called Scan. with a trailing dot' => ['api/v1/music/artists/Scan./albums'];
    }

    /**
     * @dataProvider legitimateMusicReadProvider
     */
    public function test_legitimate_music_reads_still_pass_the_hardened_guard(string $path): void
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
                'body' => '{"artists":[]}',
            ]);
        };
        $bridge = $this->bridge($publisher);

        $controller = $this->controller($info, $bridge);
        $response = $controller->proxy(
            $this->request('GET', 'user-1'),
            ['id' => 'srv-1', 'path' => $path],
        );

        $this->assertSame(200, $response->statusCode, "GET /{$path} must still be forwarded");
        $this->assertIsArray($forwarded, "GET /{$path} must reach the relay bridge");
        // Forwarded VERBATIM — the guard inspects decoded forms but never rewrites
        // the path, so the server still receives exactly what the client sent.
        $this->assertSame('/' . $path, $forwarded['path']);
    }

    /**
     * D1 accept matrix: GET and HEAD to representative playback-read paths under
     * the newly-allowed `/hls`, `/dash`, `/media`, `/api/v1/transcode` prefixes
     * must pass the scope gate and be forwarded verbatim over the relay bridge.
     * (The HEAD rows are controller-level intent only — see the test's docblock.)
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
     * relay bridge with the path + method intact.
     *
     * ⚠ The HEAD rows here are CONTROLLER-level only: no HEAD ever arrives via
     * {@see \Phlix\Hub\Http\Router} (no `head()` registrar, no HEAD proxy route —
     * pinned by {@see self::test_head_is_never_routed_to_the_relay_proxy()}), so
     * they assert what the playback families WOULD do for a player probing
     * segment size / range support if HEAD were ever registered, not what is
     * reachable today. Do not cite them as evidence that HEAD works.
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

    public function test_streaming_read_consults_the_per_user_throttle_for_the_owner(): void
    {
        // S43: the streaming path must resolve the OWNING user's durable throttle
        // (getUserThrottleBps) at admission so the response-body sink can pace
        // against it. Assert it is read exactly once, for the authenticated
        // owner. Return 0 = Unlimited so the produced sink streams without pacing
        // (a positive cap would engage the real Timer::sleep and is exercised
        // deterministically in ConnectionResponseSinkTest, not here).
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn(['userId' => 'user-1', 'status' => 'online', 'relayActive' => true]);

        $sessionManager = $this->createMock(RelaySessionManager::class);
        $sessionManager->method('checkUserQuota')->willReturn(
            ['allowed' => true, 'reason' => null, 'maxConcurrentStreams' => 0],
        );
        $sessionManager->expects($this->once())
            ->method('getUserThrottleBps')
            ->with('user-1')
            ->willReturn(0);

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

        // Unlimited → the full body streams straight through.
        $connection = $this->drive($response);
        $written = $connection->written;
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
        // S100 fix round 1 (MED-3): these three were FORWARDED by the old
        // single-decode guard. They only 404'd because phlix-server never decodes
        // Request::$path and anchors every route — one rawurldecode() or one
        // multi-segment route there would have turned each into a live traversal
        // out of browse scope. The guard now decodes to a fixed point, treats `;`
        // as a segment terminator, and rejects NUL/control bytes.
        yield 'double-encoded dot-dot into admin' => ['api/v1/music/%252e%252e%252fadmin%252fusers'];
        yield 'double-encoded dot-dot on a browse prefix' => ['api/v1/media/%252e%252e%252fadmin'];
        yield 'triple-encoded dot-dot into admin' => ['api/v1/music/%25252e%25252e%25252fadmin'];
        yield 'path-parameter dot-dot (semicolon trick)' => ['api/v1/music/..;/admin/users'];
        yield 'path-parameter dot-dot on a browse prefix' => ['api/v1/libraries/..;/admin/users'];
        yield 'NUL byte before dot-dot' => ['api/v1/music/artists/%00../admin'];
        yield 'bare NUL byte truncation' => ['api/v1/music/artists/name%00.mp3'];
        yield 'encoded newline control byte' => ['api/v1/music/artists/name%0aX-Injected'];
        // Double-encoded back-slash separator survives no decoding pass either.
        yield 'double-encoded back-slash' => ['api/v1/music/%255c..%255cadmin'];
        // S100 fix r2 (MED-2): the SOLE defence against an encoding nested deeper
        // than MAX_TRAVERSAL_DECODE_PASSES is the guard's "still decoding at the
        // cap → reject" branch, and nothing covered it. `..%2f` re-encoded seven
        // times is chosen deliberately: within 5 passes NO candidate form contains
        // `%2f`, `%5c`, `\`, a control byte or a dot-segment, so this row can only
        // pass because that branch fails CLOSED. Flip it to `return false` and this
        // is FORWARDED to the relay bridge.
        yield 'encoding nested past the decode cap' => ['api/v1/music/..%2525252525252fadmin'];
        yield 'deeper encoding nested past the decode cap' => ['api/v1/music/..%25252525252525252fadmin'];
    }

    /**
     * Music names that are UNREACHABLE over the relay by design — a KNOWN,
     * DOCUMENTED bound of the traversal guard, pinned so it stays deliberate
     * (S100 review r2, LOW-3 + LOW-4).
     *
     * These are not defects to be "fixed" by relaxing
     * {@see ServerProxyController::hasTraversalSegment()} — each relaxation
     * reopens a live traversal class:
     *  - **LOW-3, `/` or `\` in a NAME.** Music artist/album ids are NAMES, so
     *    `AC/DC` arrives as `AC%2FDC`; an encoded separator is refused outright
     *    because it is how every double-decode traversal in the attack matrix
     *    travels. The name is equally unreachable DIRECT (phlix-server does not
     *    decode route params either), so this is a cross-repo limitation, not a
     *    hub regression — the real fix is upstream: key music by id and pass the
     *    name as a QUERY parameter. Until then a library containing AC/DC shows an
     *    empty artist page over the hub (the SPA swallows the 403).
     *  - **LOW-4, a name that IS `.` or `..`.** A dot is unreserved so
     *    `encodeURIComponent()` leaves it literal and the segment arrives as a
     *    genuine dot-segment. Names that merely contain dots are unaffected and
     *    are pinned allowed in {@see self::legitimateMusicReadProvider()}.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function knownUnreachableMusicNameProvider(): iterable
    {
        // LOW-3: a separator inside the name.
        yield 'artist AC/DC' => ['api/v1/music/artists/AC%2FDC'];
        yield 'artist N/A' => ['api/v1/music/artists/N%2FA'];
        yield 'artist +/-' => ['api/v1/music/artists/%2B%2F-'];
        yield 'artist AC\\DC (back-slash)' => ['api/v1/music/artists/AC%5CDC'];
        yield 'album with a slash' => ['api/v1/music/albums/Guns%20N%27%20Roses%2FSlash'];
        // LOW-4: the name IS a dot-segment.
        yield 'artist named a single dot' => ['api/v1/music/artists/.'];
        yield 'artist named dot-dot' => ['api/v1/music/artists/..'];
        yield 'album named a single dot' => ['api/v1/music/albums/.'];
    }

    /**
     * @dataProvider knownUnreachableMusicNameProvider
     */
    public function test_known_unreachable_music_names_are_denied_by_design(string $path): void
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

        $this->assertSame(
            403,
            $response->statusCode,
            "Known limitation: GET /{$path} is refused by design — see the provider docblock",
        );
        $this->assertFalse($forwarded, "GET /{$path} must not reach the relay bridge");
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame('proxy.scope_denied', $body['code'] ?? null);
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
        $rateLimiter = new RateLimiter(60, 600);
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
        $rateLimiter = new RateLimiter(60, 600);
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

    // ---------------------------------------------------------------------
    // S107: sweep EVERY write route sitting under an allowlisted READ prefix.
    // ---------------------------------------------------------------------

    /**
     * The S107 enumeration, as executable data: every phlix-server mutating
     * ACTION route that sits under a prefix in
     * `ServerProxyController::BROWSE_SCOPE_ALLOWLIST['GET']` and that the server
     * registers for a WRITE verb only.
     *
     * Each of these was FORWARDED under a read verb before S107 — the hub's
     * `/api/v1/libraries`, `/api/v1/collections` and `/api/v1/media` prefixes
     * cover them as `/`-delimited sub-paths — and 404'd only because phlix-server
     * happens to register them POST-only. That is the server's route table doing
     * the hub gate's job, which is exactly the accidental peer dependency S100
     * removed for `/api/v1/music/scan` and S107 removes for the whole class. The
     * exposure is availability/consistency, not privilege escalation: relay
     * traffic cannot pass phlix-server's `AdminMiddleware` (the hub's UUID matches
     * no `users` row), so the risk is "someone kicks off a scan/prune they should
     * not", not "someone becomes admin".
     *
     * Server registration sites (phlix-server `69497171`), read-only:
     *   Application.php:1698 scan · :1699 rescan · :1712 match-metadata ·
     *   :1717 refresh-metadata · :1727 prune · :1728 clear-metadata ·
     *   :1729 clear-artwork · :1730 delete-all · :1734 theme-media/scan ·
     *   :1783 bulk-add · :1784 collection refresh · :587 match/apply ·
     *   :660 subtitles/download.
     *
     * @return array<string, string> label => `/`-prefixed concrete path
     */
    private static function s107DeniedActionPaths(): array
    {
        return [
            'library scan' => '/api/v1/libraries/lib-1/scan',
            'library rescan' => '/api/v1/libraries/lib-1/rescan',
            'library match-metadata' => '/api/v1/libraries/lib-1/match-metadata',
            'library refresh-metadata' => '/api/v1/libraries/lib-1/refresh-metadata',
            'library prune' => '/api/v1/libraries/lib-1/prune',
            'library clear-metadata' => '/api/v1/libraries/lib-1/clear-metadata',
            'library clear-artwork' => '/api/v1/libraries/lib-1/clear-artwork',
            'library delete-all' => '/api/v1/libraries/lib-1/delete-all',
            'library theme-media scan' => '/api/v1/libraries/lib-1/theme-media/scan',
            'collection bulk-add' => '/api/v1/collections/col-1/bulk-add',
            'collection refresh' => '/api/v1/collections/col-1/refresh',
            'media match apply' => '/api/v1/media/item-1/match/apply',
            'media subtitle download' => '/api/v1/media/item-1/subtitles/download',
        ];
    }

    /**
     * Twenty-one alternative SPELLINGS of one denied action path, generated from
     * the path itself so every route in the sweep gets the same treatment rather
     * than one hand-picked plant.
     *
     * The shapes deliberately VARY rather than repeat — each family corresponds to
     * a distinct normalisation a downstream stack plausibly applies and
     * phlix-server happens not to, and each is a spelling that reaches the same
     * server route:
     *  - duplicate separators (`//`, `///`) — `proxy()` collapses only LEADING
     *    slashes;
     *  - path parameters (`;x`, `;`) and a `;` used INSTEAD of the separator
     *    between the id and the action;
     *  - trailing `.` / encoded space / encoded dot on the action segment;
     *  - percent-encoding of the action literal (first char, last char,
     *    double-encoded, fully encoded) and a bare upper-case spelling;
     *  - COMPOSITIONS of a trailing dot/space with a following separator,
     *    parameter or sub-path — the S100-r3 family that a tail-only `rtrim()`
     *    forwarded;
     *  - **S107-specific: four shapes on the `{id}` SEGMENT** — an EMPTY id, a
     *    whitespace-only encoded id, an id with a trailing dot, and an id carrying
     *    a path parameter. These only exist once a deny pattern has a variable
     *    segment, and the first two are precisely the forms
     *    `normaliseForDenyMatch()` DESTROYS (the id is trimmed to nothing and then
     *    collapsed away), so they are the rows that prove the raw-form match and
     *    the `[^/]*` id class are load-bearing.
     *
     * @return array<string, string> label => `/`-prefixed path
     */
    private static function s107EvasionSpellings(string $path): array
    {
        $segments = explode('/', $path);
        $action = (string) array_pop($segments);
        $head = implode('/', $segments);
        $id = $segments[4];

        $withId = static function (string $replacement) use ($segments, $action): string {
            $copy = $segments;
            $copy[4] = $replacement;
            return implode('/', $copy) . '/' . $action;
        };

        $hex = static fn (string $char): string => '%' . strtoupper(dechex(ord($char)));

        $fullyEncoded = '';
        foreach (str_split(strtoupper($action)) as $char) {
            $fullyEncoded .= $hex($char);
        }

        return [
            'double slash before the action' => $head . '//' . $action,
            'triple slash before the action' => $head . '///' . $action,
            'path parameter' => $path . ';x',
            'bare semicolon' => $path . ';',
            'trailing dot' => $path . '.',
            'trailing encoded space' => $path . '%20',
            'trailing encoded dot' => $path . '%2e',
            'encoded first character' => $head . '/' . $hex($action[0]) . substr($action, 1),
            'encoded last character' => $head . '/' . substr($action, 0, -1) . $hex($action[strlen($action) - 1]),
            'double-encoded first character' => $head . '/%25' . substr($hex($action[0]), 1) . substr($action, 1),
            'fully encoded upper case' => $head . '/' . $fullyEncoded,
            'upper-case action' => $head . '/' . strtoupper($action),
            'semicolon instead of the separator' => $head . ';' . $action,
            'trailing dot then slash' => $path . './',
            'trailing dot then path parameter' => $path . '.;x',
            'encoded trailing space then slash' => $path . '%20/',
            'trailing dot then sub-path' => $path . './status',
            'empty id segment' => $withId(''),
            'whitespace-only encoded id' => $withId('%20'),
            'id with a trailing dot' => $withId($id . '.'),
            'id with a path parameter' => $withId($id . ';x'),
        ];
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function s107DeniedActionEvasionProvider(): iterable
    {
        foreach (self::s107DeniedActionPaths() as $route => $path) {
            yield "{$route} (literal)" => [$route, $path];
            foreach (self::s107EvasionSpellings($path) as $shape => $spelling) {
                yield "{$route} via {$shape}" => [$route, $spelling];
            }
        }
    }

    /**
     * Every S107 route, in every spelling, is out of browse scope under the READ
     * verb the prefix allowlist would otherwise forward.
     *
     * GET is the verb that matters here: the write verbs were already refused by
     * the hub's own method gate (no write method has a `/api/v1/libraries`,
     * `/api/v1/collections` or `/api/v1/media` prefix entry). It is the READ verb
     * that the prefix forwarded, leaving phlix-server's POST-only registration as
     * the only thing standing between the relay and a scan trigger.
     *
     * @dataProvider s107DeniedActionEvasionProvider
     */
    public function test_s107_write_action_paths_are_denied_under_a_read_verb(string $route, string $path): void
    {
        $controller = $this->controller(
            $this->createMock(ServerInfoHandler::class),
            $this->bridge(static fn () => null),
        );

        $reflected = new ReflectionMethod(ServerProxyController::class, 'isWithinBrowseScope');
        $reflected->setAccessible(true);

        $this->assertFalse(
            $reflected->invoke($controller, 'GET', $path),
            "GET {$path} ({$route}) must be hard-denied by the hub's own gate",
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function s107DeniedActionMethodProvider(): iterable
    {
        foreach (self::s107DeniedActionPaths() as $route => $path) {
            foreach (['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
                yield "{$method} {$route}" => [$method, $path];
            }
        }
    }

    /**
     * The deny layer is method-INDEPENDENT: it is consulted before both scope maps
     * for every verb, so no future widening of a write map can re-expose one of
     * these action routes.
     *
     * @dataProvider s107DeniedActionMethodProvider
     */
    public function test_s107_write_action_paths_are_denied_for_every_method(string $method, string $path): void
    {
        $controller = $this->controller(
            $this->createMock(ServerInfoHandler::class),
            $this->bridge(static fn () => null),
        );

        $reflected = new ReflectionMethod(ServerProxyController::class, 'isWithinBrowseScope');
        $reflected->setAccessible(true);

        $this->assertFalse(
            $reflected->invoke($controller, $method, $path),
            "{$method} {$path} must fail closed for every method",
        );
    }

    /**
     * End-to-end through `proxy()`, not just the private gate: every S107 route
     * 403s `proxy.scope_denied` and never reaches the relay bridge.
     *
     * Rows: all thirteen routes under GET (the verb the prefix forwarded) and
     * under POST (the verb the server actually registers — proving the refusal now
     * comes from the deny layer, which runs BEFORE both scope maps, rather than
     * from the absence of a POST entry), plus all twenty-one evasion spellings of
     * the flagship `GET /api/v1/libraries/{id}/scan` that S107 was filed for.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function s107DeniedEndToEndProvider(): iterable
    {
        foreach (self::s107DeniedActionPaths() as $route => $path) {
            yield "GET {$route}" => ['GET', ltrim($path, '/')];
            yield "POST {$route}" => ['POST', ltrim($path, '/')];
        }

        foreach (self::s107EvasionSpellings('/api/v1/libraries/lib-1/scan') as $shape => $spelling) {
            yield "GET library scan via {$shape}" => ['GET', ltrim($spelling, '/')];
        }
    }

    /**
     * @dataProvider s107DeniedEndToEndProvider
     */
    public function test_s107_denied_action_paths_403_and_are_not_forwarded(string $method, string $path): void
    {
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

        $this->assertSame(403, $response->statusCode, "{$method} /{$path} must fail closed");
        $this->assertFalse($forwarded, "{$method} /{$path} must never reach the relay bridge");
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame('proxy.scope_denied', $body['code'] ?? null);
    }

    /**
     * The false-positive boundary. A deny sweep that breaks reads is worse than
     * the bug it fixes, so every read the swept prefixes serve is pinned as
     * ALLOWED — with the boundary rows chosen to fail if a pattern is loosened in
     * any of the four ways that would over-reach:
     *  - **the `(/|$)` anchor**: `scan-status` and `scan-history`
     *    (`Application.php:1653-1654`) are the reads the whole S107 sweep must not
     *    touch, and they differ from `scan` by a single `-`;
     *  - **the `[^/]*` id class**: a library whose id is literally `scan`,
     *    `prune` or `delete-all` must stay browsable — `[^/]*` deliberately
     *    cannot match the collapsed `/api/v1/libraries/scan`;
     *  - **segment-prefix bleed**: `scanner`, `rescanned`, `refresh-metadata-v2`;
     *  - **sibling literals under the same parent**: `/match/search` next to
     *    `/match/apply`, `/subtitles`, `/subtitles/search`, `/subtitles/0` next to
     *    `/subtitles/download`, and `/theme-media` next to `/theme-media/scan`.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function s107LegitimateReadProvider(): iterable
    {
        // --- /api/v1/libraries -------------------------------------------
        yield 'libraries list' => ['api/v1/libraries'];
        yield 'library detail' => ['api/v1/libraries/lib-1'];
        yield 'library items' => ['api/v1/libraries/lib-1/items'];
        yield 'library scan-status' => ['api/v1/libraries/lib-1/scan-status'];
        yield 'library scan-history' => ['api/v1/libraries/lib-1/scan-history'];
        yield 'library theme-media' => ['api/v1/libraries/lib-1/theme-media'];
        yield 'library collections' => ['api/v1/libraries/lib-1/collections'];
        yield 'library id literally scan' => ['api/v1/libraries/scan'];
        yield 'library id literally rescan' => ['api/v1/libraries/rescan'];
        yield 'library id literally prune' => ['api/v1/libraries/prune'];
        yield 'library id literally delete-all' => ['api/v1/libraries/delete-all'];
        yield 'library id scan then a read sub-path' => ['api/v1/libraries/scan/scan-status'];
        yield 'library scanner sub-path' => ['api/v1/libraries/lib-1/scanner'];
        yield 'library rescanned sub-path' => ['api/v1/libraries/lib-1/rescanned'];
        yield 'library prune-history sub-path' => ['api/v1/libraries/lib-1/prune-history'];
        yield 'library refresh-metadata-v2 sub-path' => ['api/v1/libraries/lib-1/refresh-metadata-v2'];
        yield 'library theme-media-scan hyphenated' => ['api/v1/libraries/lib-1/theme-media-scan'];
        yield 'encoded library name with a space' => ['api/v1/libraries/My%20Movies'];

        // --- /api/v1/collections -----------------------------------------
        yield 'collections list' => ['api/v1/collections'];
        yield 'collection detail' => ['api/v1/collections/col-1'];
        yield 'collection id literally refresh' => ['api/v1/collections/refresh'];
        yield 'collection id literally bulk-add' => ['api/v1/collections/bulk-add'];
        yield 'collection refreshed sub-path' => ['api/v1/collections/col-1/refreshed'];
        yield 'collection bulk-added sub-path' => ['api/v1/collections/col-1/bulk-added'];

        // --- /api/v1/media -------------------------------------------------
        yield 'media list' => ['api/v1/media'];
        yield 'media detail' => ['api/v1/media/item-1'];
        yield 'media match search (sibling of match/apply)' => ['api/v1/media/item-1/match/search'];
        yield 'media subtitle list' => ['api/v1/media/item-1/subtitles'];
        yield 'media subtitle search' => ['api/v1/media/item-1/subtitles/search'];
        yield 'media embedded subtitle track by index' => ['api/v1/media/item-1/subtitles/0'];
        yield 'media subtitle downloads sub-path' => ['api/v1/media/item-1/subtitles/downloads'];
        yield 'media markers list' => ['api/v1/media/item-1/markers'];
        yield 'media intro marker' => ['api/v1/media/item-1/markers/intro'];
        yield 'media outro marker' => ['api/v1/media/item-1/markers/outro'];
        yield 'media marker search' => ['api/v1/media/item-1/markers/search'];
        yield 'media ratings' => ['api/v1/media/item-1/ratings'];
        yield 'media chapters' => ['api/v1/media/item-1/chapters'];
        yield 'media playback info' => ['api/v1/media/item-1/playback-info'];
        yield 'media download' => ['api/v1/media/item-1/download'];
        yield 'media facets' => ['api/v1/media/facets'];
    }

    /**
     * Each legitimate read still clears every gate, reaches the relay bridge and
     * is forwarded BYTE-IDENTICALLY (deny normalisation never rewrites the path).
     *
     * @dataProvider s107LegitimateReadProvider
     */
    public function test_s107_legitimate_reads_under_the_swept_prefixes_still_forward(string $path): void
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
                'body' => '{"ok":true}',
            ]);
        };
        $bridge = $this->bridge($publisher);

        $controller = $this->controller($info, $bridge);
        $response = $controller->proxy(
            $this->request('GET', 'user-1'),
            ['id' => 'srv-1', 'path' => $path],
        );

        $this->assertSame(200, $response->statusCode, "GET /{$path} must still be forwarded");
        $this->assertIsArray($forwarded, "GET /{$path} must reach the relay bridge");
        // Deny normalisation is match-only: the server still gets the exact bytes.
        $this->assertSame('/' . $path, $forwarded['path'], 'the deny match must never rewrite the path');
    }

    /**
     * The rest of the S107 enumeration, as executable dispositions: every write
     * route under an allowlisted read prefix that is deliberately NOT pinned in
     * `SCOPE_DENY_PATTERNS`, with the reason encoded as an assertion.
     *
     * Two disposition classes, and the rows prove BOTH halves of each — that the
     * read still passes AND that the write still fails — because a sweep can break
     * either one:
     *
     *  (1) **Has a real GET twin at the same path.** Pinning the path would 403
     *      the browse read that shares it. The write is already refused by the
     *      hub's OWN method gate (no write method carries these prefixes and no
     *      anchored pattern matches), so there is no peer dependency to remove.
     *  (2) **Resource-shaped, no GET twin.** A GET at a resource path reads the
     *      resource; it cannot trigger it. Denying it would pre-emptively break a
     *      legitimate future read, and for `markers/{markerId}` a wildcard pin
     *      would 403 the REAL reads `markers/intro`, `markers/outro` and
     *      `markers/search` today.
     *
     * @return iterable<string, array{0: string, 1: string, 2: bool}>
     */
    public static function s107DispositionProvider(): iterable
    {
        // (1) GET twin exists — read allowed, write refused by the method gate.
        $withGetTwin = [
            'libraries collection' => ['/api/v1/libraries', ['POST']],
            'library detail' => ['/api/v1/libraries/lib-1', ['PUT', 'DELETE']],
            'library theme-media' => ['/api/v1/libraries/lib-1/theme-media', ['DELETE']],
            'collections collection' => ['/api/v1/collections', ['POST']],
            'collection detail' => ['/api/v1/collections/col-1', ['PUT', 'DELETE']],
            'media detail' => ['/api/v1/media/item-1', ['DELETE']],
            'media markers' => ['/api/v1/media/item-1/markers', ['POST']],
            'media ratings' => ['/api/v1/media/item-1/ratings', ['POST']],
        ];
        foreach ($withGetTwin as $label => [$path, $writeMethods]) {
            yield "GET {$label} allowed (real read on the shared path)" => ['GET', $path, true];
            foreach ($writeMethods as $method) {
                yield "{$method} {$label} denied by the method gate" => [$method, $path, false];
            }
        }

        // (2) Resource-shaped, no GET twin — a GET would READ it, not trigger it.
        $resourceShaped = [
            'media metadata' => ['/api/v1/media/item-1/metadata', ['PATCH']],
            'media marker by id' => ['/api/v1/media/item-1/markers/mk-1', ['DELETE']],
            'collection item' => ['/api/v1/collections/col-1/items/item-9', ['POST', 'DELETE']],
        ];
        foreach ($resourceShaped as $label => [$path, $writeMethods]) {
            yield "GET {$label} allowed (resource read, not an action)" => ['GET', $path, true];
            foreach ($writeMethods as $method) {
                yield "{$method} {$label} denied by the method gate" => [$method, $path, false];
            }
        }
    }

    /**
     * @dataProvider s107DispositionProvider
     */
    public function test_s107_unpinned_write_routes_keep_their_disposition(
        string $method,
        string $path,
        bool $expected,
    ): void {
        $controller = $this->controller(
            $this->createMock(ServerInfoHandler::class),
            $this->bridge(static fn () => null),
        );

        $reflected = new ReflectionMethod(ServerProxyController::class, 'isWithinBrowseScope');
        $reflected->setAccessible(true);
        /** @var bool $inScope */
        $inScope = $reflected->invoke($controller, $method, $path);

        $this->assertSame(
            $expected,
            $inScope,
            $expected
                ? "{$method} {$path} must stay within browse scope after the S107 sweep"
                : "{$method} {$path} must stay out of browse scope",
        );
    }

    /**
     * The intentionally-allowed HB-3.1 writes are the third disposition class, and
     * the one a deny sweep is most likely to break: `SCOPE_DENY_PATTERNS` is
     * consulted BEFORE both scope maps, so a pattern that over-reaches by one
     * segment silently kills a shipped feature. Every allowed write action is
     * re-asserted here against the widened deny list.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function s107AllowedWriteStillAllowedProvider(): iterable
    {
        yield 'POST transcode start' => ['POST', '/api/v1/media/item-1/transcode'];
        yield 'POST watched' => ['POST', '/api/v1/media/item-1/watched'];
        yield 'POST unwatched' => ['POST', '/api/v1/media/item-1/unwatched'];
        yield 'POST favorite' => ['POST', '/api/v1/media/item-1/favorite'];
        yield 'POST playlists' => ['POST', '/api/v1/playlists'];
        yield 'PUT rating' => ['PUT', '/api/v1/media/item-1/rating'];
        yield 'PUT like' => ['PUT', '/api/v1/media/item-1/like'];
        yield 'PUT poster' => ['PUT', '/api/v1/media/item-1/poster'];
        yield 'DELETE favorite' => ['DELETE', '/api/v1/media/item-1/favorite'];
        yield 'DELETE rating' => ['DELETE', '/api/v1/media/item-1/rating'];
    }

    /**
     * @dataProvider s107AllowedWriteStillAllowedProvider
     */
    public function test_s107_sweep_does_not_break_the_allowed_writes(string $method, string $path): void
    {
        $controller = $this->controller(
            $this->createMock(ServerInfoHandler::class),
            $this->bridge(static fn () => null),
        );

        $reflected = new ReflectionMethod(ServerProxyController::class, 'isWithinBrowseScope');
        $reflected->setAccessible(true);

        $this->assertTrue(
            $reflected->invoke($controller, $method, $path),
            "{$method} {$path} is an intended HB-3.1 write and must survive the S107 deny sweep",
        );
    }

    // -----------------------------------------------------------------------
    // S107 follow-up: an allowlist prefix that matches NO phlix-server route is
    // dead relay surface — it widens what the hub forwards without delivering
    // any feature, and it is surface a future server route can land inside
    // without ever passing the S107 enumeration. SIX such entries shipped with
    // the original allowlist: five removed in the S107 follow-up, and
    // `/api/v1/search` removed in S165 (the real endpoint is
    // `GET /api/v1/media/search`, already inside the surviving `/api/v1/media`
    // prefix).
    // -----------------------------------------------------------------------

    /**
     * The allowlist as PRODUCTION reads it, pinned whole.
     *
     * This asserts against the real `BROWSE_SCOPE_ALLOWLIST` constant — not a
     * hand-made copy — so it is RED in BOTH directions a reviewer cares about:
     *  - **re-adding a dead prefix** (`/api/v1/images`, `/api/v1/opds`,
     *    `/api/v1/genres`, `/api/v1/studios`, `/api/v1/people`, `/api/v1/search`)
     *    fails the `assertSame()` on the affected key;
     *  - **any other widening** — a fifth browse family, a `/api/v1/admin`
     *    prefix, a write-method key, a HEAD mirror of `/api/v1/music` — fails
     *    too, because the lists are pinned EXACTLY and by order, and the key set
     *    is pinned to `GET`+`HEAD`;
     *  - **an over-removal** — dropping a LIVE prefix such as `/api/v1/media`
     *    (which is what actually carries search, facets, detail and the chapter
     *    thumbnail) fails the same `assertSame()`. That is the dangerous
     *    direction on a removal change, so it is asserted explicitly here rather
     *    than left to the forward-path rows alone.
     *
     * The strictness is the point: `SCOPE_DENY_PATTERNS` is derived from this
     * list (a prefix's write routes must be swept when the prefix is added), so
     * this list must not be able to grow silently. Widening it legitimately means
     * updating this expectation IN THE SAME COMMIT as the deny-sweep re-run,
     * which is exactly the review moment the enumeration rule asks for.
     *
     * Every entry below names a family that phlix-server really registers —
     * verified by booting BOTH production registrars (`Application::loadRoutes()`
     * and `WebPortalRouter::registerRoutes()`, the pair
     * `RelayRequestDispatcher::dispatch()` consults for a relayed request) and
     * dumping `Router::getRoutes()`.
     */
    public function test_browse_scope_allowlist_matches_the_pinned_upstream_backed_set(): void
    {
        $raw = (new ReflectionClass(ServerProxyController::class))
            ->getConstant('BROWSE_SCOPE_ALLOWLIST');
        $this->assertIsArray($raw, 'BROWSE_SCOPE_ALLOWLIST must still be an array constant');
        /** @var array<string, list<string>> $allowlist */
        $allowlist = $raw;

        $this->assertSame(
            ['GET', 'HEAD'],
            array_keys($allowlist),
            'Only read verbs may carry a broad PREFIX; every write is an anchored '
            . 'BROWSE_SCOPE_PATTERNS entry (adding a write key here re-opens the S107 sweep)',
        );

        $this->assertSame(
            [
                '/api/v1/libraries',
                '/api/v1/media',
                '/api/v1/collections',
                '/api/v1/music',
                '/hls',
                '/dash',
                '/media',
                '/api/v1/transcode',
            ],
            $allowlist['GET'],
            'GET browse scope changed: every prefix must name a REAL phlix-server family, '
            . 'and adding one requires re-running the S107 write-route enumeration in the same commit',
        );

        $this->assertSame(
            [
                '/api/v1/libraries',
                '/api/v1/media',
                '/api/v1/collections',
                '/hls',
                '/dash',
                '/media',
                '/api/v1/transcode',
            ],
            $allowlist['HEAD'],
            'HEAD browse scope changed. HEAD is inert (no Router::head() registrar), so a new '
            . 'entry here grants nothing and only documents intent — and a DEAD entry here is '
            . 'dead twice over. `/api/v1/music` stays deliberately absent (S100 fix round 1).',
        );
    }

    /**
     * The six removed prefixes, plus the REAL upstream twins they were probably
     * meant to reach — all of which must stay out of scope.
     *
     * The first rows are the removed spellings themselves (bare prefix + a
     * `/`-sub-path, because {@see ServerProxyController::isWithinBrowseScope()}
     * matches both shapes, so a re-added entry has two ways to go green).
     *
     * The trailing rows are the load-bearing half. Two of the six have a real
     * upstream family that is still NOT relay surface, and the tempting "fix"
     * for a removed entry is to allowlist the real one:
     *  - `/api/v1/artwork/{id}` — the poster/image surface, served by
     *    `HttpHandler::serveArtwork()` as a pre-router fast path that the relay
     *    dispatcher never even reaches, so allowlisting it would be relay surface
     *    with no relay handler behind it;
     *  - `/opds/v1.2[/…]` — the real OPDS catalog, mounted at the ROOT per the
     *    OPDS 1.2 spec. It is also an UNAUTHENTICATED-adjacent surface with its
     *    own Basic-auth story, so exposing it over the hub tunnel is a product
     *    decision, not a typo fix.
     * The other two twins need nothing at all, because they are already inside
     * the surviving `/api/v1/media` prefix and are pinned ALLOWED below:
     * `GET /api/v1/media/facets` (the real `/api/v1/genres`) and
     * `GET /api/v1/media/search` + `/api/v1/media/search/by-marker` (the real
     * `/api/v1/search`, S165).
     *
     * @return iterable<string, array{0: string}>
     */
    public static function s107FollowupDeadPrefixProvider(): iterable
    {
        $removed = [
            '/api/v1/images' => '/api/v1/images/poster-1.jpg',
            '/api/v1/opds' => '/api/v1/opds/v1.2/libraries',
            '/api/v1/genres' => '/api/v1/genres/Action',
            '/api/v1/studios' => '/api/v1/studios/A24',
            '/api/v1/people' => '/api/v1/people/nm0000123',
            // S165. Verified by booting BOTH production registrars and dumping
            // `Router::getRoutes()` (379 routes): no route is registered at or
            // under `/api/v1/search` for ANY method, no parametric route matches
            // it, and a live `Application::dispatch()` → `WebPortalRouter::
            // dispatch()` of `GET /api/v1/search` 404s while
            // `GET /api/v1/media/search` 401s (i.e. the route exists, auth-gated).
            // None of the four pre-router `HttpHandler` fast paths matches it
            // either (`serveStatic` returns null for every `/api/` path;
            // `serveArtwork`, `serveUserAvatar` and `serveMediaStream` are
            // anchored regexes that do not).
            '/api/v1/search' => '/api/v1/search/movies',
        ];
        foreach ($removed as $prefix => $subPath) {
            yield "removed prefix {$prefix}" => [$prefix];
            yield "removed prefix sub-path {$subPath}" => [$subPath];
        }

        yield 'real artwork route (pre-router fast path, not relay-reachable)' => ['/api/v1/artwork/item-1'];
        yield 'real OPDS root (root-mounted, never under /api/v1)' => ['/opds/v1.2'];
        yield 'real OPDS library feed' => ['/opds/v1.2/libraries/lib-1'];
        yield 'real OPDS book download' => ['/opds/v1.2/books/book-1/download'];
    }

    /**
     * The gate itself, for BOTH read keys.
     *
     * `GET` is the verb that would actually forward; `HEAD` is asserted too
     * because the entries were removed from that key as well and a partial
     * re-add (GET only, or HEAD only) must not slip through.
     *
     * @dataProvider s107FollowupDeadPrefixProvider
     */
    public function test_s107_followup_dead_prefixes_are_out_of_browse_scope(string $path): void
    {
        $controller = $this->controller(
            $this->createMock(ServerInfoHandler::class),
            $this->bridge(static fn () => null),
        );

        $reflected = new ReflectionMethod(ServerProxyController::class, 'isWithinBrowseScope');
        $reflected->setAccessible(true);

        $this->assertFalse(
            $reflected->invoke($controller, 'GET', $path),
            "GET {$path} names no relay-reachable phlix-server route and must not be relay surface",
        );
        $this->assertFalse(
            $reflected->invoke($controller, 'HEAD', $path),
            "HEAD {$path} must be out of scope under the HEAD key too (no partial re-add)",
        );
    }

    /**
     * End-to-end through `proxy()`: a removed prefix 403s `proxy.scope_denied`
     * and never reaches the relay bridge.
     *
     * @dataProvider s107FollowupDeadPrefixProvider
     */
    public function test_s107_followup_dead_prefixes_403_and_are_not_forwarded(string $path): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn(['userId' => 'user-1', 'status' => 'online', 'relayActive' => true]);

        $forwarded = false;
        $controller = $this->controller($info, $this->bridge(static function (string $e, array $d) use (&$forwarded): void {
            $forwarded = true;
        }));

        $response = $controller->proxy(
            $this->request('GET', 'user-1'),
            ['id' => 'srv-1', 'path' => ltrim($path, '/')],
        );

        $this->assertSame(403, $response->statusCode, "GET {$path} must fail closed");
        $this->assertFalse($forwarded, "GET {$path} must never reach the relay bridge");
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame('proxy.scope_denied', $body['code'] ?? null);
    }

    /**
     * The false-positive boundary for THIS removal, mirroring
     * {@see self::s107LegitimateReadProvider()}'s role for the deny sweep.
     *
     * Dropping six prefixes must not drop a read that a real client issues. The
     * genre/image/person/SEARCH surfaces phlix-server actually serves all live
     * INSIDE a prefix that survives, so each is asserted still forwarded:
     *  - `GET /api/v1/media/facets` — the genre facet list the filter UI reads
     *    (`ItemRepository::distinctGenres()`), i.e. what `/api/v1/genres` looked
     *    like it was for;
     *  - `GET /api/v1/media/search` — the REAL search endpoint (`WebPortalRouter`,
     *    inside its auth group), i.e. what `/api/v1/search` looked like it was
     *    for. This is the load-bearing row for S165: the SPA's `SearchPage.vue`
     *    issues exactly this path through the relay-proxy base, so if dropping
     *    `/api/v1/search` had broken search, THIS row would be red;
     *  - `GET /api/v1/media/search/by-marker` — its sibling, proving the whole
     *    `search` sub-tree still forwards, not just the exact leaf;
     *  - `GET /api/v1/media/{id}` — carries the item's genres/studios/cast in its
     *    metadata payload, which is the only people/studio surface there is;
     *  - `GET /api/v1/media/{id}/chapters/{n}/thumbnail` — a real image read, and
     *    one that survives via BROWSE_SCOPE_PATTERNS rather than a prefix, so it
     *    proves the removal did not disturb that map either.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function s107FollowupSurvivingReadProvider(): iterable
    {
        yield 'genre facets (the real /api/v1/genres)' => ['/api/v1/media/facets'];
        yield 'media search (the real /api/v1/search, S165)' => ['/api/v1/media/search'];
        yield 'media search by-marker (sibling of the real search leaf)'
            => ['/api/v1/media/search/by-marker'];
        yield 'media detail (carries genres/studios/cast)' => ['/api/v1/media/item-1'];
        yield 'media list' => ['/api/v1/media'];
        yield 'chapter thumbnail (a real image read, via BROWSE_SCOPE_PATTERNS)'
            => ['/api/v1/media/item-1/chapters/3/thumbnail'];
        yield 'library detail' => ['/api/v1/libraries/lib-1'];
        yield 'collections' => ['/api/v1/collections'];
        yield 'music browse' => ['/api/v1/music/artists'];
    }

    /**
     * @dataProvider s107FollowupSurvivingReadProvider
     */
    public function test_s107_followup_removal_does_not_break_surviving_reads(string $path): void
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
        $response = $controller->proxy(
            $this->request('GET', 'user-1'),
            ['id' => 'srv-1', 'path' => ltrim($path, '/')],
        );

        $this->assertSame(200, $response->statusCode, "GET {$path} must still be forwarded");
        $this->assertIsArray($forwarded, "GET {$path} must reach the relay bridge");
        $this->assertSame($path, $forwarded['path'], 'the forward path must be byte-identical');
    }
}
