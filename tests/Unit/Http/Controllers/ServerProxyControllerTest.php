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
use Phlix\Hub\Tests\Support\WorkermanTimerRuntimeControl;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Workerman\Connection\TcpConnection;

use function array_keys;
use function array_pop;
use function array_slice;
use function chr;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function filesize;
use function fopen;
use function fread;
use function fseek;
use function is_array;
use function is_file;
use function max;
use function md5;
use function md5_file;
use function min;
use function preg_match;
use function preg_match_all;
use function str_ends_with;
use function strtolower;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function base64_encode;
use function count;
use function dechex;
use function explode;
use function hrtime;
use function implode;
use function is_string;
use function sprintf;
use function json_decode;
use function json_encode;
use function ltrim;
use function ord;
use function str_repeat;
use function str_split;
use function strlen;
use function strtoupper;
use function substr;

final class ServerProxyControllerTest extends TestCase
{
    // Workerman's Timer statics and Worker registry are process-global; the trait
    // snapshots them before setUp() and restores them after tearDown().
    use WorkermanTimerRuntimeControl;

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
        return new ServerProxyController(
            $info,
            $bridge,
            $this->createMock(StructuredLogger::class),
            $sessionManager,
            $rateLimiter
        );
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

    public function testUnauthenticatedReturns401(): void
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
    public function testNormalHlsSegmentBurstOf100NeverTripsProxyLimiter(): void
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
    public function testProxyLimiterTripsAfterExceeding600InWindow(): void
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
    public function testProxyUsesLeanOwnerQueryAndNeverFullGetServerInfo(): void
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

    public function testUnknownServerReturns404(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn(null);
        $controller = $this->controller($info, $this->bridge(static fn () => null));

        $response = $controller->proxy(
            $this->request('GET', 'user-1'),
            ['id' => 'srv-1', 'path' => 'api/v1/libraries']
        );
        $this->assertSame(404, $response->statusCode);
    }

    public function testNotOwnedReturns403(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'someone-else',
            'status' => 'online',
            'relayActive' => true
        ]);
        $controller = $this->controller($info, $this->bridge(static fn () => null));

        $response = $controller->proxy(
            $this->request('GET', 'user-1'),
            ['id' => 'srv-1', 'path' => 'api/v1/libraries']
        );
        $this->assertSame(403, $response->statusCode);
    }

    public function testOnlineServerWithoutRelayTunnelReturns503RelayUnavailable(): void
    {
        // status=online (heartbeating) but no open relay session → the tunnel
        // simply isn't connected. The proxy must still refuse (503) but with the
        // actionable `server.relay_unavailable` code so the UI can explain why.
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn(
            ['userId' => 'user-1', 'status' => 'online', 'relayActive' => false],
        );

        $forwarded = false;
        $controller = $this->controller($info, $this->bridge(static function (
            string $e,
            array $d,
        ) use (&$forwarded): void {
            $forwarded = true;
        }));

        $response = $controller->proxy(
            $this->request('GET', 'user-1'),
            ['id' => 'srv-1', 'path' => 'api/v1/libraries']
        );

        $this->assertSame(503, $response->statusCode);
        $this->assertFalse($forwarded, 'No tunnel → nothing may be forwarded over the relay bridge.');
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame('server.relay_unavailable', $body['code'] ?? null);
    }

    public function testOfflineServerReturns503ServerOffline(): void
    {
        // status != online (genuinely down) AND no relay session → the classic
        // "server is offline" case keeps its `server.offline` code.
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn(
            ['userId' => 'user-1', 'status' => 'offline', 'relayActive' => false],
        );

        $forwarded = false;
        $controller = $this->controller($info, $this->bridge(static function (
            string $e,
            array $d,
        ) use (&$forwarded): void {
            $forwarded = true;
        }));

        $response = $controller->proxy(
            $this->request('GET', 'user-1'),
            ['id' => 'srv-1', 'path' => 'api/v1/libraries']
        );

        $this->assertSame(503, $response->statusCode);
        $this->assertFalse($forwarded, 'Offline server → nothing may be forwarded over the relay bridge.');
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame('server.offline', $body['code'] ?? null);
    }

    public function testSuccessfulProxyReturnsServerResponse(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

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

    public function testForgedTrustHeadersAreOverwrittenByHubValues(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

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

    public function testDisallowedMethodPathReturns403AndIsNotForwarded(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

        $forwarded = false;
        $controller = $this->controller($info, $this->bridge(static function (
            string $e,
            array $d,
        ) use (&$forwarded): void {
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

    public function testDisallowedAdminGetReturns403(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

        $forwarded = false;
        $controller = $this->controller($info, $this->bridge(static function (
            string $e,
            array $d,
        ) use (&$forwarded): void {
            $forwarded = true;
        }));

        $response = $controller->proxy(
            $this->request('GET', 'user-1'),
            ['id' => 'srv-1', 'path' => 'api/v1/admin/dashboard'],
        );

        $this->assertSame(403, $response->statusCode);
        $this->assertFalse($forwarded);
    }

    public function testAllowedBrowseSubpathIsForwarded(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

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

    public function testSiblingPrefixIsNotTreatedAsInScope(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

        $forwarded = false;
        $controller = $this->controller($info, $this->bridge(static function (
            string $e,
            array $d,
        ) use (&$forwarded): void {
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
     * every music path** — S100 fix round 1 removed the inert HEAD mirror, and
     * S247 deleted the HEAD prefix key entirely: HEAD is ROUTABLE now (see
     * {@see self::testHeadIsRoutedOnlyForTheDirectPlayByteStream()}),
     * so a music HEAD entry would be LIVE surface making the server render a
     * whole payload — plus one HMAC signed URL per track row — for the hub to
     * discard; (f) **every
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
            // (e) HEAD is REFUSED, and since S247 that refusal is live rather
            // than incidental: HEAD reaches the controller now, and its entire
            // scope is the one anchored byte-stream pattern.
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
    public function testMusicBrowseScopeGate(string $method, string $path, bool $expected): void
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
    public function testMusicArtistsGetIsForwarded(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

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
    public function testMusicTrackByIdGetIsForwarded(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

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
    public function testDeniedMusicPathsReturn403AndAreNotForwarded(string $method, string $path): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

        $forwarded = false;
        $controller = $this->controller($info, $this->bridge(static function (
            string $e,
            array $d,
        ) use (&$forwarded): void {
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
    public function testDlnaPathsRemainOutOfBrowseScope(string $path): void
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
     * S247, END-TO-END THROUGH THE REAL ROUTER — the whole point of this test is
     * that it does NOT use reflection on a private constant.
     *
     * ## What this replaced
     *
     * Until S247 this test was `test_head_is_never_routed_to_the_relay_proxy()`,
     * and it pinned the OPPOSITE behaviour: {@see Router} had no `head()`
     * registrar, {@see Router::dispatch()} 404s an unregistered method with no
     * HEAD→GET fallback, and {@see \Phlix\Hub\Application} registered the proxy
     * catch-all for GET/PUT/DELETE/PATCH/POST only — so a HEAD died in the
     * router while `BROWSE_SCOPE_ALLOWLIST` carried a `HEAD` key that read as
     * working behaviour. That test named itself the tripwire for this change and
     * listed the four things that had to land together; all four did, and this
     * is the same test rewritten to the new contract.
     *
     * ## What it now pins
     *
     * A media player probes the direct-play byte stream with HEAD before it
     * opens it, so HEAD is routable — but for that ONE path and no other:
     *  - the router now HAS a `HEAD` bucket;
     *  - `HEAD /media/{id}/stream` is routed, cleared and FORWARDED, and the
     *    reply carries no body while keeping the server's `Content-Length`;
     *  - `HEAD` on a JSON browse path, a music path and an HLS segment — all
     *    three of which the deleted HEAD prefix key used to list — reach the
     *    controller and take a deliberate 403 `proxy.scope_denied`, never a
     *    router 404 and never a forward;
     *  - the identical `GET` on every one of those paths is forwarded and
     *    answered 200, so the 403 is about the METHOD and nothing else.
     *
     * ⚠ The GET control is not decoration. Without it a blanket failure (a
     * mis-registered route, a broken bridge double) would produce the same
     * refusals and read as a pass.
     */
    public function testHeadIsRoutedOnlyForTheDirectPlayByteStream(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

        /** @var list<array<string, mixed>> $forwarded */
        $forwarded = [];
        $bridge = null;
        $publisher = function (string $event, array $data) use (&$bridge, &$forwarded): void {
            $forwarded[] = $data;
            /** @var RelayProxyBridge $bridge */
            $bridge->onReply([
                'request_id' => $data['request_id'],
                'status' => 200,
                'headers' => [
                    'Content-Type' => 'video/x-matroska',
                    'Content-Length' => '362807',
                    'Accept-Ranges' => 'bytes',
                ],
                'body' => '',
            ]);
        };
        $bridge = $this->bridge($publisher);
        $controller = $this->controller($info, $bridge);

        // Mirror Application::registerRoutes(): the proxy catch-all lives under
        // the `/api/v1` group and is registered for GET/HEAD/PUT/DELETE/PATCH/POST.
        $handler = static function (Request $req, array $params) use ($controller): Response {
            /** @var array<string, string> $typedParams */
            $typedParams = $params;
            return $controller->proxy($req, $typedParams);
        };
        $router = new Router();
        $router->group('/api/v1', static function (Router $r) use ($handler): void {
            $r->get('/servers/{id}/proxy/{path:.*}', $handler);
            $r->head('/servers/{id}/proxy/{path:.*}', $handler);
            $r->put('/servers/{id}/proxy/{path:.*}', $handler);
            $r->delete('/servers/{id}/proxy/{path:.*}', $handler);
            $r->patch('/servers/{id}/proxy/{path:.*}', $handler);
            $r->post('/servers/{id}/proxy/{path:.*}', $handler);
        });

        $this->assertArrayHasKey(
            'HEAD',
            $router->getRoutes(),
            'S247: the hub router must have a HEAD bucket — a HEAD allowlist entry would otherwise '
            . 'be dead configuration again',
        );

        $dispatch = static function (string $method, string $tail) use ($router): Response {
            $req = new Request();
            $req->method = $method;
            $req->path = '/api/v1/servers/srv-1/proxy/' . $tail;
            $req->userId = 'user-1';
            $req->headers = ['Accept' => 'application/json'];
            return $router->dispatch($req);
        };

        // ---- the one HEAD that IS served ---------------------------------
        $headStream = $dispatch('HEAD', 'media/item-123/stream');
        $this->assertSame(200, $headStream->statusCode, 'HEAD on the byte stream must be routed and forwarded');
        $this->assertCount(1, $forwarded, 'HEAD on the byte stream must reach the relay bridge');
        $this->assertSame('/media/item-123/stream', $forwarded[0]['path']);
        $this->assertSame('HEAD', $forwarded[0]['method']);
        // RFC 9110 §9.3.2: no body, and the Content-Length a GET would return.
        $this->assertSame('', $headStream->body, 'a HEAD reply must carry no body');
        $this->assertSame(
            '362807',
            $headStream->headers['Content-Length'] ?? null,
            'a HEAD reply must keep the paired server\'s real Content-Length, not a recomputed 0',
        );
        $this->assertTrue($headStream->headOnly, 'the HEAD reply must select the BodylessResponse encoder');
        $this->assertNull($headStream->streamProducer, 'HEAD must take the buffered path, not the producer');

        // ---- every other HEAD: 403 from the SCOPE gate, with a GET control --
        $denied = [
            'api/v1/media' => 'JSON browse collection',
            'api/v1/media/item-123' => 'JSON browse detail',
            'api/v1/music/artists' => 'music browse (would mint an HMAC URL per row)',
            'hls/job-abc/seg-00007.ts' => 'HLS segment',
            'api/v1/transcode/job-abc/status' => 'transcode status poll',
        ];
        $seen = 1;
        foreach ($denied as $tail => $why) {
            // CONTROL first: the same path IS served for GET, and IS forwarded.
            // A byte-serving GET (`/hls`) is STREAMED, so its forward happens
            // when the HTTP worker drives the producer — drive it here so the
            // control counts the same way a buffered read does.
            $getResponse = $dispatch('GET', $tail);
            $this->drive($getResponse);
            $this->assertSame(200, $getResponse->statusCode, "control: GET /{$tail} ({$why}) must be served");
            $seen++;
            $this->assertCount($seen, $forwarded, "control: GET /{$tail} must reach the relay bridge");

            $headResponse = $dispatch('HEAD', $tail);
            $this->assertSame(
                403,
                $headResponse->statusCode,
                "HEAD /{$tail} ({$why}) must be refused by the SCOPE gate, not routed",
            );
            /** @var array<string, mixed> $body */
            $body = json_decode($headResponse->body, true, 8, JSON_THROW_ON_ERROR);
            $this->assertSame(
                'proxy.scope_denied',
                $body['code'] ?? null,
                "HEAD /{$tail} must be refused by ServerProxyController's scope gate (not a router 404)",
            );
            $this->assertCount($seen, $forwarded, "HEAD /{$tail} must never reach the relay bridge");
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
    public function testLegitimateMusicReadsStillPassTheHardenedGuard(string $path): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

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
     * D1 accept matrix: reads to representative playback paths must pass the
     * scope gate and be forwarded verbatim over the relay bridge.
     *
     * GET covers the `/hls`, `/dash` and `/api/v1/transcode` prefixes plus the
     * anchored `/media/{id}/stream`. HEAD covers ONLY the byte stream (S247 gave
     * it exactly that one anchored entry and no prefix) — the HEAD rows for the
     * other families were removed, and their refusal is now asserted positively,
     * with a GET control beside it, by
     * {@see self::testS247HeadScopeIsOnlyTheByteStream()}.
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

        foreach ($paths as $label => $path) {
            yield "GET {$label}" => ['GET', $path];
        }

        yield 'HEAD media direct-play stream' => ['HEAD', 'media/item-123/stream'];
    }

    /**
     * A GET/HEAD to a real streaming path must clear the scope gate and reach the
     * relay bridge with the path + method intact.
     *
     * S247: the HEAD row is no longer controller-level intent — HEAD is routed
     * for the byte stream, pinned end-to-end through the real router by
     * {@see self::testHeadIsRoutedOnlyForTheDirectPlayByteStream()}.
     *
     * @dataProvider acceptedStreamingScopeProvider
     */
    public function testStreamingReadsPassScopeGateAndAreForwarded(string $method, string $path): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

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
    public function testHeadOverRelayReturnsPromptlyOnEndWithoutBodyFrames(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

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

    public function testStreamingReadReturnsAProducerAndStreamsBodyToTheConnection(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

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

    public function testStreamingReadConsultsThePerUserThrottleForTheOwner(): void
    {
        // S43: the streaming path must resolve the OWNING user's durable throttle
        // (getUserThrottleBps) at admission so the response-body sink can pace
        // against it. Assert it is read exactly once, for the authenticated
        // owner. Return 0 = Unlimited so the produced sink streams without pacing
        // (a positive cap would engage the real Timer::sleep and is exercised
        // deterministically in ConnectionResponseSinkTest, not here).
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

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
     * S43 AC — the resolved bucket must actually REACH the response-body sink.
     *
     * The sibling test above returns `0` = Unlimited, so the sink it produces is
     * byte-for-byte identical whether the bucket is handed over or thrown away.
     * Deleting the `$throttleBucket` argument from the
     * `new ConnectionResponseSink($connection, $method, $throttleBucket)` call —
     * which turns S43 off completely in production, every relayed stream
     * Unlimited — left the ENTIRE suite green (2502 tests, 19744 assertions).
     * This test pins that wire by driving a POSITIVE cap and observing that the
     * body is genuinely paced.
     *
     * Sizing is analytic, not a guess: `8_000_000` bits/sec is 1 000 000 B/s with
     * a `TokenBucket::THROTTLE_BURST_SECONDS` (1 s) burst, so the bucket holds
     * 1 000 000 bytes. The first fragment is 1 050 000 bytes: it empties the
     * bucket and leaves 50 000 bytes of debt, so the second fragment cannot be
     * released for 50 000 / 1 000 000 = 50 ms (plus the 1 ms sleep floor). With
     * the bucket disconnected the two writes land microseconds apart, so the
     * separation between pass and fail is three orders of magnitude.
     */
    public function testTheResolvedThrottleBucketActuallyReachesTheResponseBodySink(): void
    {
        $capBps = 8_000_000;                                    // 1 000 000 B/s
        $first = str_repeat('a', 1_050_000);                    // empties the 1 s burst
        $second = str_repeat('b', 1_000);
        $expectedWaitSeconds = 50_000 / ($capBps / 8);          // 0.05 s

        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

        $sessionManager = $this->createMock(RelaySessionManager::class);
        $sessionManager->method('checkUserQuota')->willReturn(
            ['allowed' => true, 'reason' => null, 'maxConcurrentStreams' => 0],
        );
        $sessionManager->expects($this->once())
            ->method('getUserThrottleBps')
            ->with('user-1')
            ->willReturn($capBps);

        $bridge = null;
        $publisher = function (string $event, array $data) use (&$bridge, $first, $second): void {
            /** @var RelayProxyBridge $bridge */
            $id = $data['request_id'];
            $bridge->onReply(['request_id' => $id, 'phase' => 'head', 'status' => 200, 'headers' => [
                'Content-Type' => 'video/mp2t',
                'Content-Length' => (string) (strlen($first) + strlen($second)),
            ]]);
            $bridge->onReply(['request_id' => $id, 'phase' => 'body', 'body' => $first]);
            $bridge->onReply(['request_id' => $id, 'phase' => 'body', 'body' => $second]);
            $bridge->onReply(['request_id' => $id, 'phase' => 'end']);
        };
        $bridge = $this->bridge($publisher);

        $controller = $this->controller($info, $bridge, $sessionManager);
        $response = $controller->proxy(
            $this->request('GET', 'user-1'),
            ['id' => 'srv-1', 'path' => 'hls/job-abc/seg-00007.ts'],
        );

        // A connection double that timestamps every write, so the pacing gap
        // between the two body fragments is directly observable.
        $connection = new class extends TcpConnection {
            /** @var list<int> Bytes written, per call. */
            public array $sizes = [];
            /** @var list<float> Monotonic seconds at each write. */
            public array $at = [];

            public function __construct()
            {
            }

            public function send(mixed $sendBuffer, bool $raw = false): bool
            {
                $this->sizes[] = strlen((string) $sendBuffer);
                $this->at[] = hrtime(true) / 1_000_000_000;

                return true;
            }
        };

        $this->assertNotNull($response->streamProducer);
        ($response->streamProducer)($connection);

        // head + two body fragments, all bytes delivered.
        $this->assertCount(3, $connection->sizes);
        $this->assertSame(strlen($first), $connection->sizes[1]);
        $this->assertSame(strlen($second), $connection->sizes[2]);

        $gap = $connection->at[2] - $connection->at[1];
        $this->assertGreaterThan(
            $expectedWaitSeconds * 0.5,
            $gap,
            sprintf(
                'the second fragment was released after only %.4f s — the resolved throttle '
                . 'bucket never reached ConnectionResponseSink (expected a ~%.3f s pacing wait)',
                $gap,
                $expectedWaitSeconds,
            ),
        );
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
    public function testMutatingMethodsOnStreamingPathsReturn403AndAreNotForwarded(
        string $method,
        string $path,
    ): void {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

        $forwarded = false;
        $controller = $this->controller($info, $this->bridge(static function (
            string $e,
            array $d,
        ) use (&$forwarded): void {
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
    public function testSiblingStreamingPrefixesAreDenied(string $path): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

        $forwarded = false;
        $controller = $this->controller($info, $this->bridge(static function (
            string $e,
            array $d,
        ) use (&$forwarded): void {
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
    public function testStreamingPathOnUnownedServerReturns403NotOwned(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'someone-else',
            'status' => 'online',
            'relayActive' => true
        ]);

        $forwarded = false;
        $controller = $this->controller($info, $this->bridge(static function (
            string $e,
            array $d,
        ) use (&$forwarded): void {
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
    public function testStreamingPathOnOfflineServerFailsClosed503(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn(
            ['userId' => 'user-1', 'status' => 'offline', 'relayActive' => false],
        );

        $forwarded = false;
        $controller = $this->controller($info, $this->bridge(static function (
            string $e,
            array $d,
        ) use (&$forwarded): void {
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
    public function testTranscodeStartPostPassesScopeGateAndIsForwarded(
        string $id,
        string $expectedPath,
    ): void {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

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
    public function testTranscodeStartPostForwardsQueryString(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

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
     * S108 — the hub-side PRECONDITION for making `AC/DC` reachable.
     *
     * The traversal guard is applied to the forward PATH only
     * ({@see ServerProxyController::hasTraversalSegment()} takes `$path`), while
     * `$request->queryString` is handed to the relay bridge verbatim. That is
     * what makes S108's chosen upstream design — key music by id / carry the NAME
     * in a QUERY parameter — implementable at all: a query value may legally
     * contain the very characters the path guard refuses outright.
     *
     * ⚠ **This is not decoration.** S108's fix lands in phlix-server + the
     * clients, not here, and nothing in this suite currently observes the query
     * string on a music read. If a later "hardening" pass fed
     * `$path . '?' . $queryString` (or the raw request URI) to the traversal
     * guard, every S108-fixed name containing `/`, `\` or `%2F` would start
     * 403ing again and the SPA would return to the same invisible empty state
     * S99/S100/S108 exist to eliminate — with every other test in this file still
     * green. This pins the boundary so that change cannot land silently.
     *
     * Each row is what `encodeURIComponent()` emits for a real artist/album name
     * carried as a query value.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function musicNameInQueryStringProvider(): iterable
    {
        yield 'AC/DC as an encoded query value' => ['artist=AC%2FDC'];
        yield 'AC\\DC as an encoded query value' => ['artist=AC%5CDC'];
        yield 'N/A as an encoded query value' => ['artist=N%2FA'];
        yield '+/- as an encoded query value' => ['artist=%2B%2F-'];
        yield 'a name with a space' => ['artist=Pink%20Floyd'];
        yield 'a name with an ampersand' => ['artist=Simon%20%26%20Garfunkel'];
        yield 'album title plus artist filter' => ['album=Back%20In%20Black&artist=AC%2FDC'];
    }

    /**
     * A music NAME carried in the query string reaches the relay bridge
     * byte-for-byte, including separators the PATH guard refuses.
     *
     * @dataProvider musicNameInQueryStringProvider
     */
    public function testMusicNameInTheQueryStringSurvivesThePathTraversalGuard(string $queryString): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

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
                'body' => '{"artist":{}}',
            ]);
        };
        $bridge = $this->bridge($publisher);

        $request = $this->request('GET', 'user-1');
        $request->queryString = $queryString;

        $controller = $this->controller($info, $bridge);
        $response = $controller->proxy(
            $request,
            ['id' => 'srv-1', 'path' => 'api/v1/music/artists'],
        );

        $this->assertSame(
            200,
            $response->statusCode,
            "A music name in the query string must not be treated as a path traversal: ?{$queryString}",
        );
        $this->assertIsArray($forwarded, "GET ?{$queryString} must reach the relay bridge");
        $this->assertSame('/api/v1/music/artists', $forwarded['path']);
        $this->assertSame(
            $queryString,
            $forwarded['query'],
            'The query string must arrive at phlix-server byte-for-byte — S108 keys music by a name carried here',
        );
    }

    /**
     * Control for {@see self::testMusicNameInTheQueryStringSurvivesThePathTraversalGuard()}:
     * the SAME name in the SAME request, moved into the PATH, is still refused.
     *
     * Without this the test above would pass just as happily against a guard that
     * had been deleted outright — "the query string forwards" is only meaningful
     * alongside "the path spelling does not".
     */
    public function testTheSameMusicNameInThePathIsStillRefused(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

        $forwarded = false;
        $controller = $this->controller($info, $this->bridge(static function (
            string $e,
            array $d,
        ) use (&$forwarded): void {
            $forwarded = true;
        }));

        $request = $this->request('GET', 'user-1');
        $request->queryString = 'artist=AC%2FDC';

        $response = $controller->proxy(
            $request,
            ['id' => 'srv-1', 'path' => 'api/v1/music/artists/AC%2FDC'],
        );

        $this->assertSame(403, $response->statusCode);
        $this->assertFalse(
            $forwarded,
            'The PATH spelling must still be refused even when the query carries the name too'
        );
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
    public function testSiblingMediaPostRoutesStillDenied(string $path): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

        $forwarded = false;
        $controller = $this->controller($info, $this->bridge(static function (
            string $e,
            array $d,
        ) use (&$forwarded): void {
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
    public function testSiblingMediaPutRoutesAreAllowed(string $path): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

        /** @var array<string, mixed>|null $forwarded */
        $forwarded = null;
        $controller = $this->controller($info, $this->bridge(static function (
            string $e,
            array $d,
        ) use (&$forwarded): void {
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
    public function testSiblingMediaPostWatchedUnwatchedAreAllowed(string $path): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

        /** @var array<string, mixed>|null $forwarded */
        $forwarded = null;
        $controller = $this->controller($info, $this->bridge(static function (
            string $e,
            array $d,
        ) use (&$forwarded): void {
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
    public function testPostWriteActionsFavoriteAndPlaylistAreAllowed(string $path): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

        /** @var array<string, mixed>|null $forwarded */
        $forwarded = null;
        $controller = $this->controller($info, $this->bridge(static function (
            string $e,
            array $d,
        ) use (&$forwarded): void {
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
    public function testDeleteWriteActionsAreAllowed(string $path): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

        /** @var array<string, mixed>|null $forwarded */
        $forwarded = null;
        $controller = $this->controller($info, $this->bridge(static function (
            string $e,
            array $d,
        ) use (&$forwarded): void {
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
    public function testNonListedWriteActionsAreDenied(string $method, string $path): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

        $forwarded = false;
        $controller = $this->controller($info, $this->bridge(static function (
            string $e,
            array $d,
        ) use (&$forwarded): void {
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
    public function testPatchIsAlwaysDenied(string $path): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

        $forwarded = false;
        $controller = $this->controller($info, $this->bridge(static function (
            string $e,
            array $d,
        ) use (&$forwarded): void {
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
    public function testBodiedPutRatingRoundTripForwardsBodyIntact(): void
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
    public function testLargeBodiedPutRoundTripChunksAndPreservesBodyBytes(): void
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
     * live tunnel (mirrors {@see self::testHeadOverRelayReturnsPromptlyOnEndWithoutBodyFrames}),
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
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

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
    public function testTranscodeRegexEdgePathsAreDenied(string $path): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

        $forwarded = false;
        $controller = $this->controller($info, $this->bridge(static function (
            string $e,
            array $d,
        ) use (&$forwarded): void {
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
    public function testTranscodeStartPostOnUnownedServerReturns403NotOwned(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'someone-else',
            'status' => 'online',
            'relayActive' => true
        ]);

        $forwarded = false;
        $controller = $this->controller($info, $this->bridge(static function (
            string $e,
            array $d,
        ) use (&$forwarded): void {
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
    public function testTranscodeStartPostOnOfflineServerFailsClosed503(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn(
            ['userId' => 'user-1', 'status' => 'offline', 'relayActive' => false],
        );

        $forwarded = false;
        $controller = $this->controller($info, $this->bridge(static function (
            string $e,
            array $d,
        ) use (&$forwarded): void {
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
    public function testTranscodeStartPostOnOnlineServerWithoutTunnelReturns503RelayUnavailable(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn(
            ['userId' => 'user-1', 'status' => 'online', 'relayActive' => false],
        );

        $forwarded = false;
        $controller = $this->controller($info, $this->bridge(static function (
            string $e,
            array $d,
        ) use (&$forwarded): void {
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
    public function testTranscodeStartPostUnauthenticatedReturns401(): void
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
     *
     *    ⚠ **This provider is a pin on the HUB's behaviour, not a statement of
     *    S108's scope — do not size the upstream step from it.** Measured by
     *    execution on 2026-08-05: because phlix-server never decodes a route
     *    parameter, EVERY name `encodeURIComponent()` touches is unreachable, not
     *    just the ones with a separator. `Pink%20Floyd` is forwarded by this hub
     *    and then 404s at the server, where `WHERE a.name = 'Pink%20Floyd'`
     *    matches 0 production rows against 1 for `'Pink Floyd'`. On production
     *    `music_artists`: 4,679 names, 296 with `/`, **4,006 with a space**. The
     *    rows below are the subset THIS gate refuses; the other ~3,700 names fail
     *    one layer further downstream and no hub change can reach them.
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
    public function testKnownUnreachableMusicNamesAreDeniedByDesign(string $path): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

        $forwarded = false;
        $controller = $this->controller($info, $this->bridge(static function (
            string $e,
            array $d,
        ) use (&$forwarded): void {
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
    public function testTraversalPathsReturn403AndAreNotForwarded(string $path): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

        $forwarded = false;
        $controller = $this->controller($info, $this->bridge(static function (
            string $e,
            array $d,
        ) use (&$forwarded): void {
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

    public function testTimeoutReturns504(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

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
    public function testStaleRelayActiveWithNoLiveTunnelReturns503Not504(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        // DB says the server is online (stale flag) — but there is no tunnel.
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

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
        // Playback-read segments AND playlists ride the wider ceiling.
        yield 'GET hls variant playlist -> 60' => ['GET', 'hls/job-abc/media_v3.m3u8', 60.0];
        yield 'GET hls segment -> 60' => ['GET', 'hls/job-abc/seg-00007.ts', 60.0];
        yield 'GET dash manifest -> 60' => ['GET', 'dash/job-abc/manifest.mpd', 60.0];
        // Direct-play stream, status polling and JSON browse keep the default.
        yield 'GET media direct-play stream -> 30' => ['GET', 'media/item-123/stream', 30.0];
        yield 'GET transcode job status -> 30' => ['GET', 'api/v1/transcode/job-abc/status', 30.0];
        yield 'GET json browse -> 30' => ['GET', 'api/v1/media', 30.0];

        // S247: HEAD is in scope for the byte stream ONLY, so it is the only
        // HEAD row that can be FORWARDED at all. The other families' HEAD rows
        // were removed with the HEAD prefix key; `replyTimeoutForPath()` still
        // branches on HEAD, and that classifier is exercised directly (without
        // the scope gate in the way) by `testReplyTimeoutForPathClassifier`.
        yield 'HEAD media direct-play stream -> 30' => ['HEAD', 'media/item-123/stream', 30.0];

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
    public function testReplyTimeoutIsForwardedToTheRelayBridge(
        string $method,
        string $path,
        float $expected,
    ): void {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

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
    public function testReplyTimeoutForPathClassifier(string $method, string $path, float $expected): void
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
    public function testReplyTimeoutNeverShortensAHigherInjectedDefault(): void
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
    public function testReplyTimeoutWidensALowerInjectedDefaultOnlyForStreaming(): void
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
    public function testOverQuotaUserReturns503QuotaExceededAndIsNotForwarded(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

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
    public function testConcurrentStreamCapReachedReturns503StreamLimitAndIsNotForwarded(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

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
    public function testStreamUnderConcurrentCapIsAdmittedOccupiesThenReleasesSlot(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

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
     *   :660 subtitles/download · :1787 regenerate-assets (S284/S332).
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
            'library regenerate-assets' => '/api/v1/libraries/lib-1/regenerate-assets',
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
        // A route without a variable segment (e.g. `/api/v1/music/scan`) has no
        // id at index 4; the id-shape spellings then produce a DIFFERENT path,
        // which callers with no `{param}` route skip. `?? null` suppresses the
        // undefined-key warning while preserving the exact generated strings.
        $id = $segments[4] ?? null;

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
    public function testS107WriteActionPathsAreDeniedUnderAReadVerb(string $route, string $path): void
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
    public function testS107WriteActionPathsAreDeniedForEveryMethod(string $method, string $path): void
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
     * Rows: all fourteen routes under GET (the verb the prefix forwarded) and
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
    public function testS107DeniedActionPaths403AndAreNotForwarded(string $method, string $path): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

        $forwarded = false;
        $controller = $this->controller($info, $this->bridge(static function (
            string $e,
            array $d,
        ) use (&$forwarded): void {
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
        yield 'library regenerate-assets sibling sub-path' => ['api/v1/libraries/lib-1/regenerate-assetsX'];
        yield 'library regenerate-assets-v2 sub-path' => ['api/v1/libraries/lib-1/regenerate-assets-v2'];
        yield 'library id literally regenerate-assets' => ['api/v1/libraries/regenerate-assets'];
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
    public function testS107LegitimateReadsUnderTheSweptPrefixesStillForward(string $path): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

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
    public function testS107UnpinnedWriteRoutesKeepTheirDisposition(
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
    public function testS107SweepDoesNotBreakTheAllowedWrites(string $method, string $path): void
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
    // S332: the S107 deny enumeration is SELF-MAINTAINING — it is re-derived
    // from phlix-server's OWN route table, so a write route added upstream can
    // no longer land under an allowlisted read prefix unseen (that is exactly
    // how `regenerate-assets` slipped through in S284, six days after S107).
    //
    // The enumeration source is a VENDORED SNAPSHOT of phlix-server's two
    // production route tables (`Application::loadRoutes()` +
    // `WebPortalRouter::registerRoutes()`, the pair
    // `RelayRequestDispatcher::dispatch()` consults), generated by
    // `tests/Unit/Http/Controllers/Fixtures/dump-phlix-server-route-manifest.php` from a phlix-server
    // checkout and committed as
    // `tests/Unit/Http/Controllers/Fixtures/phlix-server-route-manifest.json`.
    //
    // ## How the manifest crosses the repo boundary and how staleness is caught
    //
    // The hub's CI clones ONE repository, so it cannot boot phlix-server at
    // test time. The snapshot is therefore vendored, and staleness is caught by
    // THREE independent mechanisms, each loud rather than silent:
    //  - **the `Server Route Snapshot Currency` CI job** compares the
    //    snapshot's recorded `source_sha` against phlix-server's live master
    //    on every PR and fails the build until the snapshot is regenerated.
    //    This is the true drift detector: the pin test below is only a
    //    consistency anchor between the fixture and its pin.
    //  - **the pin test below** ({@see S332_EXPECTED_SERVER_SOURCE_SHA}) keeps
    //    the fixture and the pin in lockstep — regenerating the fixture
    //    without bumping the pin (or bumping the pin without regenerating)
    //    goes RED here, so re-snapshotting is a deliberate, reviewed event
    //    rather than a silent half-edit.
    //  - **`sha256` is re-derived from the route list** in the test, so the
    //    fixture cannot be hand-edited into a different route set than it
    //    claims (e.g. someone "fixing" a route spelling by hand).
    //  - **`tests/Unit/Http/Controllers/Fixtures/dump-phlix-server-route-manifest.php
    //    --check`** regenerates the snapshot from a live phlix-server checkout
    //    and fails on ANY byte drift; the S332 premerge gate runs it, so the
    //    snapshot that merges is proven byte-identical to the real server tree.
    //
    // ## The inclusion criteria (S107's four, made mechanical)
    //
    // A manifest route must be denied by `SCOPE_DENY_PATTERNS` when it is:
    //  1. a WRITE verb (POST/PUT/PATCH/DELETE) — the class a read-verb relay
    //     request could otherwise forward into a server-side mutation;
    //  2. reachable under an allowlisted GET read prefix — otherwise the hub's
    //     own method gate already refuses it and there is no peer dependency
    //     to remove;
    //  3. an ACTION route, not a resource: the last segment is a literal word
    //     (a `{param}` tail is a resource mutation a GET would merely read);
    //  4. NOT already authoritative in the hub's own maps — a GET/HEAD twin at
    //     the same path (denying it would 403 the browse read it shares), or
    //     an anchored `BROWSE_SCOPE_PATTERNS` entry for exactly its verb (the
    //     HB-3.1 allowed writes, which the deny layer must not pre-empt).
    //
    // The residual non-mechanical dispositions from S107 — write routes that
    // are deliberately NOT denied although no rule above excludes them — are
    // enumerated in {@see S332_RESIDUAL_DISPOSITIONS} with the reason each
    // stays unpinned. The list is tiny on purpose: a new upstream route that
    // meets all four criteria fails the deriving test until it is denied OR
    // deliberately added there with a written rationale.
    // -----------------------------------------------------------------------

    /**
     * phlix-server master SHA the vendored snapshot was generated from.
     *
     * This pin is a CONSISTENCY ANCHOR between the fixture and this constant:
     * the test below goes red when one side is updated without the other, so
     * re-snapshotting is a deliberate, reviewed event. It is NOT the drift
     * detector — phlix-server master moving by itself leaves both sides
     * unchanged, and that drift is caught by the `Server Route Snapshot
     * Currency` CI job (and by the generator's `--check` gate at premerge).
     * Bump this constant only by re-running
     * `php tests/Unit/Http/Controllers/Fixtures/dump-phlix-server-route-manifest.php
     * [server-root]` and committing the regenerated fixture in the same commit.
     */
    private const S332_EXPECTED_SERVER_SOURCE_SHA = 'e74cdc884358e3b8f1ab6e9dbdc357d5471dff48';

    /**
     * Write routes under an allowlisted read prefix that are deliberately NOT
     * pinned in `SCOPE_DENY_PATTERNS` although no mechanical rule above
     * excludes them (S107 disposition class 2 — resource-shaped: a GET at the
     * path would READ the resource, not trigger it; denying it would
     * pre-emptively break a legitimate future read).
     *
     * Each entry must carry the S107 disposition reason in prose. A new route
     * landing here is a deliberate, reviewed classification, never a default.
     *
     * @var array<string, string> "METHOD path" => reason
     */
    private const S332_RESIDUAL_DISPOSITIONS = [
        'PATCH /api/v1/media/{id}/metadata' => 'resource-shaped (S107 disposition class 2): a GET at '
            . 'this path reads the media metadata resource, it cannot trigger a mutation, and PATCH has '
            . 'no hub map at all so every PATCH fails closed at the method gate.',
    ];

    /**
     * Load and structurally validate the vendored phlix-server route snapshot.
     *
     * @return array{
     *     generator: string,
     *     source_repo: string,
     *     source_sha: string,
     *     route_count: int,
     *     sha256: string,
     *     routes: list<array{method: string, path: string}>
     * }
     */
    private static function s332ServerRouteManifest(): array
    {
        $file = __DIR__ . '/Fixtures/phlix-server-route-manifest.json';
        $json = file_get_contents($file);
        if (!is_string($json) || $json === '') {
            throw new \RuntimeException(
                "S332: the vendored phlix-server route manifest at {$file} could not be read. "
                . 'Regenerate it with `php tests/Unit/Http/Controllers/Fixtures/dump-phlix-server-route-manifest.php`.',
            );
        }

        try {
            /** @var array{
             *     generator: string,
             *     source_repo: string,
             *     source_sha: string,
             *     route_count: int,
             *     sha256: string,
             *     routes: list<array{method: string, path: string}>
             * } $manifest */
            $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(
                'S332: the vendored route manifest is not valid JSON: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        return $manifest;
    }

    /**
     * The exact normalized `"METHOD path"` lines the generator hashes — the
     * same dedupe+sort the generator applies, so the sha256 re-derivation is
     * byte-identical to what `tests/Unit/Http/Controllers/Fixtures/dump-phlix-server-route-manifest.php`
     * computed at snapshot time.
     *
     * @param list<array{method: string, path: string}> $routes
     *
     * @return list<string>
     */
    private static function s332ManifestLines(array $routes): array
    {
        $lines = [];
        foreach ($routes as $route) {
            $lines[] = $route['method'] . ' ' . $route['path'];
        }

        $lines = array_values(array_unique($lines));
        sort($lines);

        return $lines;
    }

    /**
     * Substitute every `{param}` template segment with a concrete value, so the
     * derived template can be run through the REAL production gate.
     */
    private static function s332ConcretePath(string $template): string
    {
        return (string) preg_replace('/\{[^}]+\}/', 'x', $template);
    }

    /**
     * The hub's GET read prefixes, as production reads them.
     *
     * @return list<string>
     */
    private static function s332BrowsePrefixes(): array
    {
        $raw = (new ReflectionClass(ServerProxyController::class))
            ->getConstant('BROWSE_SCOPE_ALLOWLIST');
        /** @var array<string, list<string>> $allowlist */
        $allowlist = is_array($raw) ? $raw : [];

        return $allowlist['GET'] ?? [];
    }

    /**
     * The hub's anchored per-verb write patterns, as production reads them.
     *
     * @return array<string, list<string>>
     */
    private static function s332BrowsePatterns(): array
    {
        $raw = (new ReflectionClass(ServerProxyController::class))
            ->getConstant('BROWSE_SCOPE_PATTERNS');
        /** @var array<string, list<string>> $patterns */
        $patterns = is_array($raw) ? $raw : [];

        return $patterns;
    }

    /**
     * Does the manifest contain a GET or HEAD route at the SAME path template?
     * If it does, pinning the write would 403 the browse read that shares the
     * path (S107 disposition class 1) — the write is already refused by the
     * hub's own method gate.
     *
     * @param list<array{method: string, path: string}> $routes
     */
    private static function s332HasReadTwin(array $routes, string $path): bool
    {
        foreach ($routes as $route) {
            if ($route['path'] !== $path) {
                continue;
            }
            if ($route['method'] === 'GET' || $route['method'] === 'HEAD') {
                return true;
            }
        }

        return false;
    }

    /**
     * Every route in the vendored manifest that meets S107's four mechanical
     * inclusion criteria. This is the ROUTES-driven half of S332: the set is
     * derived from phlix-server's route table, never from the deny list, so
     * the tests grow with the ROUTES — the exact property S107 lacked.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function s332ManifestDerivedInScopeProvider(): iterable
    {
        $manifest = self::s332ServerRouteManifest();
        $routes = $manifest['routes'];
        $prefixes = self::s332BrowsePrefixes();
        $patterns = self::s332BrowsePatterns();
        $writeVerbs = ['POST', 'PUT', 'PATCH', 'DELETE'];

        foreach ($routes as $route) {
            $method = $route['method'];
            $path = $route['path'];

            if (!in_array($method, $writeVerbs, true)) {
                continue;
            }

            $underPrefix = false;
            foreach ($prefixes as $prefix) {
                if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                    $underPrefix = true;
                    break;
                }
            }
            if (!$underPrefix) {
                continue;
            }

            $segments = explode('/', $path);
            $last = (string) end($segments);
            if (str_starts_with($last, '{')) {
                continue;
            }

            if (self::s332HasReadTwin($routes, $path)) {
                continue;
            }

            if (isset(self::S332_RESIDUAL_DISPOSITIONS[$method . ' ' . $path])) {
                continue;
            }

            $alreadyAuthoritative = false;
            foreach ($patterns[$method] ?? [] as $pattern) {
                if (preg_match($pattern, $path) === 1) {
                    $alreadyAuthoritative = true;
                    break;
                }
            }
            if ($alreadyAuthoritative) {
                continue;
            }

            yield "{$method} {$path}" => [$method, $path];
        }
    }

    /**
     * Every route the derivation discovers is denied by the hub's own gate in
     * every one of the 21 S107 evasion spellings, and its SIBLING segment
     * (action + a trailing non-separator character) stays browsable — the
     * exact/anchored compare, never a substring one.
     *
     * @dataProvider s332ManifestDerivedInScopeProvider
     */
    public function testS332DerivedInScopeRoutesAreDeniedInEverySpelling(string $method, string $path): void
    {
        $controller = $this->controller(
            $this->createMock(ServerInfoHandler::class),
            $this->bridge(static fn () => null),
        );

        $reflected = new ReflectionMethod(ServerProxyController::class, 'isWithinBrowseScope');
        $reflected->setAccessible(true);

        $concrete = self::s332ConcretePath($path);

        $this->assertFalse(
            $reflected->invoke($controller, 'GET', $concrete),
            "GET {$concrete} ({$method} {$path}) is derived from the server manifest and must be "
                . "hard-denied by the hub's own gate",
        );

        foreach (self::s107EvasionSpellings($concrete) as $shape => $spelling) {
            // The four S107 id-shape spellings substitute a value into the
            // `{id}` segment. A route WITHOUT a variable segment has no id to
            // substitute — the spelling is a DIFFERENT path (e.g.
            // `/api/v1/music/;x/scan`), not a spelling of the same route, so
            // asserting it is denied would pin the wrong thing. music/scan's
            // action-level spellings are already S100's territory; only the
            // variable-segment class gets the id-shape treatment.
            $idShapes = [
                'empty id segment',
                'whitespace-only encoded id',
                'id with a trailing dot',
                'id with a path parameter',
            ];
            if (!str_contains($path, '{') && in_array($shape, $idShapes, true)) {
                continue;
            }
            $this->assertFalse(
                $reflected->invoke($controller, 'GET', $spelling),
                "GET {$spelling} ({$method} {$path}, {$shape}) must be hard-denied — the derived route "
                    . 'is pinned in every S107 evasion spelling, not just its literal one',
            );
        }
    }

    /**
     * The near-miss sibling check — kept separate so the no-`{param}` route
     * (`/api/v1/music/scan`) still gets it.
     *
     * @dataProvider s332ManifestDerivedInScopeProvider
     */
    public function testS332DerivedRouteSiblingIsNotAbsorbed(string $method, string $path): void
    {
        $controller = $this->controller(
            $this->createMock(ServerInfoHandler::class),
            $this->bridge(static fn () => null),
        );

        $reflected = new ReflectionMethod(ServerProxyController::class, 'isWithinBrowseScope');
        $reflected->setAccessible(true);

        $concrete = self::s332ConcretePath($path);

        // The near-miss sibling must stay browsable: no anchored deny pattern
        // may absorb `action` + a trailing non-separator character.
        $tail = explode('/', $path);
        $action = (string) array_pop($tail);
        $sibling = substr($concrete, 0, -strlen($action)) . $action . 'X';
        $this->assertTrue(
            $reflected->invoke($controller, 'GET', $sibling),
            "GET {$sibling} must stay in browse scope: {$path} is pinned EXACTLY, so a sibling segment "
                . "like {$sibling} must not be absorbed by its deny pattern",
        );
    }

    /**
     * The deriving check is a gate with a DENOMINATOR, never a bare pass: it
     * prints how many routes it examined and FAILS when it examined nothing or
     * when the route this step exists for is not among them.
     */
    public function testS332DerivedSetIsNonVacuousAndCoversRegenerateAssets(): void
    {
        $manifest = self::s332ServerRouteManifest();
        $derived = [];
        foreach (self::s332ManifestDerivedInScopeProvider() as $label => $route) {
            $derived[$label] = $route;
        }

        $denominator = count($derived);
        fwrite(STDERR, sprintf(
            "S332 deriving check examined %d of %d manifest route(s); %d route(s) met the S107 "
            . "inclusion criteria and are asserted denied\n",
            $denominator,
            count($manifest['routes']),
            $denominator,
        ));

        $this->assertGreaterThanOrEqual(
            15,
            $denominator,
            'the derivation found fewer than the known 15 in-scope routes — an empty or gutted '
            . "scan is not a pass. If phlix-server's route table genuinely shrank, re-snapshot and "
            . 're-derive this floor in the same commit.',
        );

        $this->assertArrayHasKey(
            'POST /api/v1/libraries/{id}/regenerate-assets',
            $derived,
            'S284\'s `regenerate-assets` route is in the manifest and meets all four criteria — it '
            . 'MUST be in the derived in-scope set or the derivation is not measuring what it claims.',
        );
    }

    /**
     * The vendored snapshot is GENUINE and CONSISTENT: its sha256 is re-derived
     * from the route list (a hand-edited fixture cannot claim a route set it
     * does not contain), and its `source_sha` matches the pinned phlix-server
     * master. Server DRIFT (master moving past the snapshot without anyone
     * regenerating) is caught by the `Server Route Snapshot Currency` CI job —
     * this test only keeps the fixture and the pin in lockstep.
     */
    public function testS332ServerRouteManifestIsGenuineAndCurrent(): void
    {
        $manifest = self::s332ServerRouteManifest();

        $this->assertSame(
            'tests/Unit/Http/Controllers/Fixtures/dump-phlix-server-route-manifest.php',
            $manifest['generator'],
            'the manifest must record the script that generated it',
        );
        $this->assertSame(
            'detain/phlix-server',
            $manifest['source_repo'],
            'the manifest must name the repository it was generated from',
        );
        $this->assertSame(
            count($manifest['routes']),
            $manifest['route_count'],
            'route_count must equal the number of route entries',
        );

        $recomputed = hash('sha256', implode("\n", self::s332ManifestLines($manifest['routes'])));
        $this->assertSame(
            $manifest['sha256'],
            $recomputed,
            'the recorded sha256 does not match the route list — the fixture has been hand-edited, '
            . 'which is exactly the silent decay this snapshot exists to prevent. Regenerate it with '
            . '`php tests/Unit/Http/Controllers/Fixtures/dump-phlix-server-route-manifest.php` and commit the result.',
        );

        $this->assertSame(
            self::S332_EXPECTED_SERVER_SOURCE_SHA,
            $manifest['source_sha'],
            'the recorded source_sha does not match the S332 pin. The fixture and '
            . 'S332_EXPECTED_SERVER_SOURCE_SHA must move in lockstep: regenerate with '
            . '`php tests/Unit/Http/Controllers/Fixtures/dump-phlix-server-route-manifest.php '
            . '[server-root]` and bump the pin in the same commit. (If phlix-server master moved '
            . 'and nobody regenerated, the Server Route Snapshot Currency CI job is the detector '
            . 'for that.)',
        );
    }

    /**
     * S350 — the exact/anchored compare the prune is measured against: the
     * vendored phlix-server route snapshot carries EXACTLY the three trickplay
     * routes the server registers, and neither of the two pruned pattern
     * families (`thumb-[0-9]+\.(jpg|png)` and `index.xml`) matches ANY manifest
     * path. The trickplay route set is printed as the denominator (S345
     * lesson 3): a `thumb-*` or `index.xml` route appearing here would mean the
     * prune removed surface a live server route needs.
     */
    public function testS350TrickplayManifestContainsOnlyTheLiveRoutes(): void
    {
        $manifest = self::s332ServerRouteManifest();

        $trickplay = [];
        foreach ($manifest['routes'] as $route) {
            if (str_starts_with($route['path'], '/trickplay/')) {
                $trickplay[] = $route['method'] . ' ' . $route['path'];
            }
        }
        sort($trickplay);

        fwrite(STDERR, sprintf(
            "S350 trickplay route set (denominator, %d route(s)):\n  %s\n",
            count($trickplay),
            implode("\n  ", $trickplay),
        ));

        $this->assertSame(
            [
                'GET /trickplay/{jobId}/sprite.jpg',
                'GET /trickplay/{jobId}/thumbs.bif',
                'GET /trickplay/{jobId}/timeline.json',
            ],
            $trickplay,
            'the S332-derived snapshot must carry EXACTLY the three trickplay routes the server '
            . 'registers — a `thumb-*` or `index.xml` route here would mean S350 pruned surface a '
            . 'live route needs',
        );

        $prunedPatterns = [
            '#^/trickplay/[^/]+/thumb-[0-9]+\.(jpg|png)$#',
            '#^/trickplay/[^/]+/index\.xml$#',
        ];
        foreach ($manifest['routes'] as $route) {
            foreach ($prunedPatterns as $pattern) {
                $this->assertSame(
                    0,
                    preg_match($pattern, $route['path']),
                    sprintf(
                        'the pruned pattern %s must match NO manifest path — it matched %s %s',
                        $pattern,
                        $route['method'],
                        $route['path'],
                    ),
                );
            }
        }
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
    public function testBrowseScopeAllowlistMatchesThePinnedUpstreamBackedSet(): void
    {
        $raw = (new ReflectionClass(ServerProxyController::class))
            ->getConstant('BROWSE_SCOPE_ALLOWLIST');
        $this->assertIsArray($raw, 'BROWSE_SCOPE_ALLOWLIST must still be an array constant');
        /** @var array<string, list<string>> $allowlist */
        $allowlist = $raw;

        $this->assertSame(
            ['GET'],
            array_keys($allowlist),
            'Only GET may carry a broad PREFIX; every write is an anchored '
            . 'BROWSE_SCOPE_PATTERNS entry (adding a write key here re-opens the S107 sweep), and '
            . 'S247 deleted the HEAD key outright — HEAD scope is ONE anchored pattern, so no '
            . 'buffered browse family can be reached by a HEAD',
        );

        $this->assertSame(
            [
                '/api/v1/libraries',
                '/api/v1/media',
                '/api/v1/collections',
                '/api/v1/music',
                '/hls',
                '/dash',
                '/api/v1/transcode',
            ],
            $allowlist['GET'],
            'GET browse scope changed: every prefix must name a REAL phlix-server family, '
            . 'and adding one requires re-running the S107 write-route enumeration in the same commit. '
            . 'S247 REMOVED `/media` from this list — the direct-play byte stream is now the anchored '
            . '`#^/media/[^/]+/stream$#` in BROWSE_SCOPE_PATTERNS[GET]. Re-adding the prefix here is a '
            . 'widening that would re-open the S107 sweep.',
        );

        $this->assertArrayNotHasKey(
            'HEAD',
            $allowlist,
            'S247: there must be NO HEAD prefix key. HEAD is routable now, so an entry here would be '
            . 'LIVE surface, not documentation — and a HEAD to a buffered JSON browse prefix makes the '
            . 'paired server render a whole body (and, for music, mint an HMAC URL per row) purely for '
            . 'the hub to discard. That cost was avoided, not accepted.',
        );
    }

    /**
     * S247 — the direct-play byte stream is admitted by an ANCHORED pattern that
     * mirrors phlix-server's own matcher, never by the `/media` PREFIX it
     * replaced.
     *
     * This is the "compare exactly, never by substring" rule made executable.
     * `HttpHandler::serveMediaStream()` matches `#^/media/(?P<id>[^/]+)/stream$#`
     * and nothing else; the prefix admitted every `/media/…` path the server
     * might ever register, present or future. The accept row is the control that
     * stops the deny rows reading as "the request failed for an unrelated
     * reason": all seven go through the SAME reflected gate, and exactly one is
     * true.
     *
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function s247ByteStreamMatcherProvider(): iterable
    {
        // The one shape the server serves — the SUCCEEDING control.
        yield 'the byte stream itself' => ['/media/item-123/stream', true];
        yield 'byte stream, uuid id' => ['/media/550e8400-e29b-41d4-a716-446655440000/stream', true];

        // Everything the `/media` PREFIX used to forward and the anchor does not.
        yield 'bare collection path' => ['/media', false];
        yield 'item without the stream tail' => ['/media/item-123', false];
        yield 'sub-path under the stream tail' => ['/media/item-123/stream/extra', false];
        yield 'tail that merely starts with stream' => ['/media/item-123/streamX', false];
        yield 'two-segment id' => ['/media/a/b/stream', false];
        yield 'hypothetical future server route' => ['/media/item-123/delete', false];
        // Siblings — already denied before S247, still denied after.
        yield 'sibling prefix' => ['/media-secret/item-123/stream', false];
    }

    /**
     * @dataProvider s247ByteStreamMatcherProvider
     */
    public function testS247ByteStreamIsMatchedExactlyNotByPrefix(string $path, bool $expected): void
    {
        $controller = $this->controller(
            $this->createMock(ServerInfoHandler::class),
            $this->bridge(static fn () => null),
        );

        $reflected = new ReflectionMethod(ServerProxyController::class, 'isWithinBrowseScope');
        $reflected->setAccessible(true);

        $this->assertSame(
            $expected,
            $reflected->invoke($controller, 'GET', $path),
            "GET {$path}: the byte stream is admitted by an ANCHORED matcher mirroring "
            . 'phlix-server\'s `#^/media/(?P<id>[^/]+)/stream$#`, never by a `/media` prefix',
        );
        // HEAD carries the SAME anchored matcher and nothing else, so the two
        // verbs must agree exactly — a divergence here means a player's probe
        // and its fetch disagree about what is reachable.
        $this->assertSame(
            $expected,
            $reflected->invoke($controller, 'HEAD', $path),
            "HEAD {$path} must agree with GET: the probe and the fetch address the same route",
        );
    }

    /**
     * S247 — HEAD grants nothing beyond the byte stream.
     *
     * Every one of these is a GET-allowed read (so the refusal cannot be blamed
     * on the path being out of scope generally) that a HEAD must NOT reach,
     * because reaching it would make the paired server render a whole buffered
     * body for the hub to discard. The GET control on the same path is asserted
     * in the same test, so a blanket failure cannot read as a pass.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function s247HeadDeniedProvider(): iterable
    {
        yield 'json browse collection' => ['/api/v1/media'];
        yield 'json browse detail' => ['/api/v1/media/item-123'];
        yield 'libraries' => ['/api/v1/libraries'];
        yield 'collections' => ['/api/v1/collections'];
        yield 'music browse' => ['/api/v1/music/artists'];
        yield 'hls segment' => ['/hls/job-abc/seg-00007.ts'];
        yield 'hls master playlist' => ['/hls/job-abc/master.m3u8'];
        yield 'dash manifest' => ['/dash/job-abc/manifest.mpd'];
        yield 'transcode status' => ['/api/v1/transcode/job-abc/status'];
        yield 'artwork' => ['/api/v1/artwork/item-123'];
    }

    /**
     * @dataProvider s247HeadDeniedProvider
     */
    public function testS247HeadScopeIsOnlyTheByteStream(string $path): void
    {
        $controller = $this->controller(
            $this->createMock(ServerInfoHandler::class),
            $this->bridge(static fn () => null),
        );

        $reflected = new ReflectionMethod(ServerProxyController::class, 'isWithinBrowseScope');
        $reflected->setAccessible(true);

        // The SUCCEEDING control: the identical path IS in GET scope, so the
        // HEAD refusal below is about the METHOD and nothing else.
        $this->assertTrue(
            $reflected->invoke($controller, 'GET', $path),
            "GET {$path} must stay in scope — otherwise the HEAD assertion proves nothing",
        );
        $this->assertFalse(
            $reflected->invoke($controller, 'HEAD', $path),
            "HEAD {$path} must be out of scope: S247 gave HEAD exactly one anchored entry "
            . '(the direct-play byte stream) and no prefix at all',
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
     * The trailing rows are the load-bearing half. One of the six has a real
     * upstream family that is still NOT relay surface, and the tempting "fix"
     * for a removed entry is to allowlist the real one:
     *  - `/opds/v1.2[/…]` — the real OPDS catalog, mounted at the ROOT per the
     *    OPDS 1.2 spec. It is also an UNAUTHENTICATED-adjacent surface with its
     *    own Basic-auth story, so exposing it over the hub tunnel is a product
     *    decision, not a typo fix.
     *
     * ⚠ `/api/v1/artwork/{id}` used to be a row here, pinning it DENIED. S238
     * removed it: the user decided relayed browse must render images, so artwork
     * is now in scope via an anchored `BROWSE_SCOPE_PATTERNS['GET']` entry and is
     * pinned ALLOWED by `s238ImageReadProvider()` instead. Do not re-add it — a
     * denied row here and an allowed row there cannot both be green, which is the
     * point: the two providers are each other's control.
     * The `/api/v1/images` rows stay: that spelling still names no server route
     * (the real one is `/api/v1/artwork/{id}`), so it is still dead surface.
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
    public function testS107FollowupDeadPrefixesAreOutOfBrowseScope(string $path): void
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
    public function testS107FollowupDeadPrefixes403AndAreNotForwarded(string $path): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

        $forwarded = false;
        $controller = $this->controller($info, $this->bridge(static function (
            string $e,
            array $d,
        ) use (&$forwarded): void {
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
    public function testS107FollowupRemovalDoesNotBreakSurvivingReads(string $path): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

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

    // -----------------------------------------------------------------------
    // S238 — artwork + avatars over the relay.
    //
    // Measured 2026-08-05: `GET /api/v1/artwork/{id}` and
    // `GET /api/v1/users/{id}/avatar` were DOUBLY unreachable over the relay —
    // 403 here (in no scope map) and then 404 at
    // `Hub\RelayRequestDispatcher::dispatch()` even if they had been, because
    // both are PRE-ROUTER fast paths in `Server\Workerman\HttpHandler`
    // (`serveArtwork()`, `serveUserAvatar()`) and so appear in neither production
    // route table. ⇒ relayed inline browse could render NO posters and NO
    // avatars. The user decided (2026-08-05) that relayed images must work; this
    // is the HUB half — the 403. The 404 is phlix-server's half and lands
    // separately, so until it does these paths 404 instead of 403, which is a
    // strict improvement and not a regression.
    //
    // ⚠ Shape choice, and it is the security-relevant part: BOTH landed as
    // ANCHORED `BROWSE_SCOPE_PATTERNS['GET']` entries, NOT as prefixes in
    // `BROWSE_SCOPE_ALLOWLIST`. A `/api/v1/users` prefix would have admitted the
    // whole `WebPortalRouter` user surface (`me/settings`, `me/favorites`,
    // `me/history`, `me/continue-watching`, `me/next-up`, `me/recently-watched`);
    // a `/api/v1/artwork` prefix would have admitted an arbitrary sub-tree the
    // server's single-segment matcher does not serve. Every one of those is
    // pinned DENIED by `s238MustStayDeniedProvider()` — the control beside the
    // allow rows.
    // -----------------------------------------------------------------------

    /**
     * Anti-vacuity floor for `BROWSE_SCOPE_PATTERNS['GET']`.
     *
     * The S238 rows below are only meaningful if the map they read is populated.
     * Twelve is the count at the time of writing (2 HLS audio + 1 chapter
     * thumbnail + the 1 direct-play byte stream + 2 trickplay + the 2 S238 image
     * reads + the 4 S63 cast/DLNA reads); the assertion is `>=`, so adding an
     * entry is fine and hollowing the map fails LOUDLY with the count in the
     * message rather than passing trivially. Proved by emptying the `GET` key.
     */
    private const S238_MIN_GET_PATTERNS = 9;

    /**
     * The two S238 patterns, exactly as production must hold them.
     *
     * Exact string equality, never `str_contains`/`assertStringContainsString`:
     * `'#^/api/v1/artwork/[^/]+$#'` is a substring of a MUTATED
     * `'#^/api/v1/artwork/[^/]+$#x'`, so a substring assertion would pass the very
     * mutation this exists to catch (cf. S37/S236).
     *
     * @var list<string>
     */
    private const S238_EXPECTED_PATTERNS = [
        '#^/api/v1/artwork/[^/]+$#',
        '#^/api/v1/users/[^/]+/avatar$#',
    ];

    /**
     * Anti-vacuity + presence, read off the REAL constant (not a hand-made copy).
     *
     * Order of assertions matters: the map must be found and be above the floor
     * BEFORE the membership check runs, so a hollowed/renamed constant fails with
     * an explicit "nothing to check" message instead of a bare "value not in
     * array".
     */
    public function testS238ImageReadPatternsArePresentInTheRealConstant(): void
    {
        $raw = (new ReflectionClass(ServerProxyController::class))
            ->getConstant('BROWSE_SCOPE_PATTERNS');
        $this->assertIsArray(
            $raw,
            'BROWSE_SCOPE_PATTERNS must still be an array constant — if it was renamed or '
            . 'removed, every S238 row below is vacuous',
        );
        /** @var array<string, list<string>> $patterns */
        $patterns = $raw;

        $this->assertArrayHasKey(
            'GET',
            $patterns,
            "BROWSE_SCOPE_PATTERNS['GET'] is missing — the S238 image reads have nowhere to live",
        );
        $get = $patterns['GET'];

        $this->assertGreaterThanOrEqual(
            self::S238_MIN_GET_PATTERNS,
            count($get),
            sprintf(
                'ANTI-VACUITY: BROWSE_SCOPE_PATTERNS[GET] holds %d entries, below the floor of %d. '
                . 'The map has been hollowed, so no membership assertion below proves anything.',
                count($get),
                self::S238_MIN_GET_PATTERNS,
            ),
        );

        foreach (self::S238_EXPECTED_PATTERNS as $pattern) {
            // assertContains is strict (===) in PHPUnit 10 — exact literal, so a
            // one-character mutation of the pattern reds this.
            $this->assertContains(
                $pattern,
                $get,
                sprintf(
                    "S238: BROWSE_SCOPE_PATTERNS['GET'] must carry the exact literal %s — "
                    . 'without it relayed browse renders no posters/avatars (403 proxy.scope_denied)',
                    $pattern,
                ),
            );
        }
    }

    /**
     * The image reads S238 opens, in the shapes the server actually serves.
     *
     * `serveArtwork()` matches `#^/api/v1/artwork/([^/]+)$#` and `serveUserAvatar()`
     * matches `#^/api/v1/users/([^/]+)/avatar$#`, so the id classes below mirror
     * what really reaches them: a UUID, the literal `me` (what `AvatarStorage`
     * emits for the current user), and a percent-encoded id.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function s238ImageReadProvider(): iterable
    {
        yield 'artwork by uuid' => ['/api/v1/artwork/550e8400-e29b-41d4-a716-446655440000'];
        yield 'artwork by short id' => ['/api/v1/artwork/item-1'];
        yield 'artwork id needing percent-encoding' => ['/api/v1/artwork/item%201'];
        yield 'avatar by uuid' => ['/api/v1/users/550e8400-e29b-41d4-a716-446655440000/avatar'];
        yield 'avatar for me' => ['/api/v1/users/me/avatar'];
        yield 'avatar by short id' => ['/api/v1/users/user-1/avatar'];
    }

    /**
     * The gate itself: each image read is in browse scope under GET.
     *
     * @dataProvider s238ImageReadProvider
     */
    public function testS238ImageReadsAreWithinBrowseScope(string $path): void
    {
        $controller = $this->controller(
            $this->createMock(ServerInfoHandler::class),
            $this->bridge(static fn () => null),
        );

        $reflected = new ReflectionMethod(ServerProxyController::class, 'isWithinBrowseScope');
        $reflected->setAccessible(true);

        $this->assertTrue(
            $reflected->invoke($controller, 'GET', $path),
            "GET {$path} is an S238 image read and must be forwarded over the relay",
        );
    }

    /**
     * End-to-end through `proxy()`: the request reaches the relay bridge with a
     * byte-identical path and the reply's BINARY body survives intact.
     *
     * The binary assertion is the point of this row rather than a status check:
     * the buffered reply path is what an image takes (images are NOT in
     * `STREAMING_BODY_PREFIXES`), and a body that is mangled anywhere between the
     * bridge and `buildResponse()` would render as a broken image while a status
     * assertion stayed green. The payload carries a NUL byte and a `\r\n` on
     * purpose.
     *
     * @dataProvider s238ImageReadProvider
     */
    public function testS238ImageReadsForwardAndReturnImageBytes(string $path): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

        $png = "\x89PNG\r\n\x1a\n\x00\x00\x00\x0dIHDR\x00\xff\xfe binary";

        /** @var array<string, mixed>|null $forwarded */
        $forwarded = null;
        $bridge = null;
        $publisher = function (string $event, array $data) use (&$bridge, &$forwarded, $png): void {
            $forwarded = $data;
            /** @var RelayProxyBridge $bridge */
            $bridge->onReply([
                'request_id' => $data['request_id'],
                'status' => 200,
                'headers' => ['Content-Type' => 'image/png'],
                'body' => $png,
            ]);
        };
        $bridge = $this->bridge($publisher);

        $controller = $this->controller($info, $bridge);
        $response = $controller->proxy(
            $this->request('GET', 'user-1'),
            ['id' => 'srv-1', 'path' => ltrim($path, '/')],
        );

        $this->assertSame(200, $response->statusCode, "GET {$path} must be forwarded, not 403 scope_denied");
        $this->assertIsArray($forwarded, "GET {$path} must reach the relay bridge");
        $this->assertSame($path, $forwarded['path'], 'the forward path must be byte-identical');
        $this->assertSame($png, $response->body, "GET {$path} must return the image bytes unmangled");
        $this->assertSame('image/png', $response->headers['Content-Type'] ?? null);
    }

    /**
     * Artwork's `?size=` — and a SIGNED artwork URL's `exp`/`sig` — reach the
     * server byte-for-byte, and the scope gate never sees them.
     *
     * This is the row the brief's caution asks for. `serveArtwork()` rebuilds the
     * signed resource path as `'/api/v1/artwork/'.$id.'?size='.$size` from
     * `$wr->path()` + `$wr->get('size')`, so if the hub dropped, reordered or
     * re-encoded the query string the HMAC would not verify and every relayed
     * poster would 401. The gate takes the PATH only (`$params['path']` carries no
     * query), which is also why a `sig` full of base64 characters cannot trip
     * `hasTraversalSegment()`.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function s238ImageQueryStringProvider(): iterable
    {
        yield 'artwork size only' => ['/api/v1/artwork/item-1', 'size=w342'];
        yield 'artwork original size' => ['/api/v1/artwork/item-1', 'size=original'];
        yield 'signed artwork url' => [
            '/api/v1/artwork/item-1',
            'size=w342&exp=1893456000&sig=aB3-_9xQz0KkLmNoPqRsTuVwXyZ012345678',
        ];
        yield 'signed avatar url' => [
            '/api/v1/users/me/avatar',
            'exp=1893456000&sig=aB3-_9xQz0KkLmNoPqRsTuVwXyZ012345678',
        ];
    }

    /**
     * @dataProvider s238ImageQueryStringProvider
     */
    public function testS238ImageQueryStringReachesTheBridgeByteForByte(
        string $path,
        string $queryString,
    ): void {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

        /** @var array<string, mixed>|null $forwarded */
        $forwarded = null;
        $bridge = null;
        $publisher = function (string $event, array $data) use (&$bridge, &$forwarded): void {
            $forwarded = $data;
            /** @var RelayProxyBridge $bridge */
            $bridge->onReply([
                'request_id' => $data['request_id'],
                'status' => 200,
                'headers' => ['Content-Type' => 'image/jpeg'],
                'body' => "\xff\xd8\xff\xe0 jpeg",
            ]);
        };
        $bridge = $this->bridge($publisher);

        $request = $this->request('GET', 'user-1');
        $request->queryString = $queryString;

        $controller = $this->controller($info, $bridge);
        $response = $controller->proxy($request, ['id' => 'srv-1', 'path' => ltrim($path, '/')]);

        $this->assertSame(200, $response->statusCode, "GET {$path}?{$queryString} must be forwarded");
        $this->assertIsArray($forwarded, "GET {$path}?{$queryString} must reach the relay bridge");
        $this->assertSame($path, $forwarded['path'], 'the forward path must be byte-identical');
        $this->assertSame(
            $queryString,
            $forwarded['query'] ?? null,
            'the query string must reach the server byte-for-byte or a signed image URL will not verify',
        );
    }

    /**
     * THE CONTROL. Everything the two anchored patterns must NOT admit.
     *
     * An allowlist widening is only as good as what it still refuses, so each row
     * is a path a BROADER shape would have admitted:
     *  - the whole `/api/v1/users` surface a `/api/v1/users` PREFIX would have
     *    opened (`WebPortalRouter.php:332-373`): settings, continue-watching,
     *    next-up, recently-watched, favorites, history — plus the bare prefix and
     *    a bare user resource;
     *  - anything DEEPER than the server's single-segment matchers: an artwork
     *    sub-tree, a two-segment user id, a path below `/avatar`;
     *  - the bare collection paths (`/api/v1/artwork`, `/api/v1/users`), which
     *    the server serves for nobody;
     *  - SIBLING bleed (`/api/v1/artworkX/…`, `/api/v1/users-admin/…`,
     *    `…/avatars`), the classic prefix-match failure;
     *  - `/api/v1/admin/…` and a plain `/api/v1/users/{id}` — nothing about
     *    letting an avatar through may let a user record through.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function s238MustStayDeniedProvider(): iterable
    {
        // The `/api/v1/users` surface a prefix would have admitted.
        yield 'user settings' => ['/api/v1/users/me/settings'];
        yield 'user continue-watching' => ['/api/v1/users/me/continue-watching'];
        yield 'user next-up' => ['/api/v1/users/me/next-up'];
        yield 'user recently-watched' => ['/api/v1/users/me/recently-watched'];
        yield 'user favorites' => ['/api/v1/users/me/favorites'];
        yield 'user history' => ['/api/v1/users/me/history'];
        yield 'user history item' => ['/api/v1/users/me/history/item-1'];
        yield 'bare users collection' => ['/api/v1/users'];
        yield 'user resource' => ['/api/v1/users/user-1'];
        yield 'user me resource' => ['/api/v1/users/me'];

        // Deeper than the server's single-segment matchers.
        yield 'artwork sub-tree' => ['/api/v1/artwork/item-1/original'];
        yield 'artwork two-segment id' => ['/api/v1/artwork/item-1/w342'];
        yield 'avatar with a two-segment user id' => ['/api/v1/users/a/b/avatar'];
        yield 'path below avatar' => ['/api/v1/users/me/avatar/original'];

        // Bare collection paths the server serves for nobody.
        yield 'bare artwork collection' => ['/api/v1/artwork'];

        // Sibling bleed.
        yield 'artwork sibling prefix' => ['/api/v1/artworkX/item-1'];
        yield 'users sibling prefix' => ['/api/v1/users-admin/user-1/avatar'];
        yield 'avatars plural' => ['/api/v1/users/me/avatars'];
        yield 'avatar with a suffix' => ['/api/v1/users/me/avatarX'];

        // Unrelated privileged surface, re-asserted so this change cannot be read
        // as having relaxed anything else.
        yield 'admin users' => ['/api/v1/admin/users'];
    }

    /**
     * @dataProvider s238MustStayDeniedProvider
     */
    public function testS238WideningAdmitsNothingBeyondTheTwoImageReads(string $path): void
    {
        $controller = $this->controller(
            $this->createMock(ServerInfoHandler::class),
            $this->bridge(static fn () => null),
        );

        $reflected = new ReflectionMethod(ServerProxyController::class, 'isWithinBrowseScope');
        $reflected->setAccessible(true);

        $this->assertFalse(
            $reflected->invoke($controller, 'GET', $path),
            "GET {$path} must stay out of browse scope: S238 opens the two image reads and nothing else",
        );
        $this->assertFalse(
            $reflected->invoke($controller, 'HEAD', $path),
            "HEAD {$path} must stay out of browse scope too",
        );
    }

    /**
     * End-to-end control: a denied neighbour 403s `proxy.scope_denied` and never
     * reaches the bridge — the SUCCEEDING sibling being
     * `testS238ImageReadsForwardAndReturnImageBytes()` above, so a 403
     * here is provably "the scope gate refused it" and not "the harness never got
     * anywhere".
     *
     * @dataProvider s238MustStayDeniedProvider
     */
    public function testS238DeniedNeighbours403AndAreNotForwarded(string $path): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

        $forwarded = false;
        $controller = $this->controller($info, $this->bridge(static function (
            string $e,
            array $d,
        ) use (&$forwarded): void {
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
     * The image reads are opened for GET ONLY.
     *
     * The load-bearing rows are `POST /api/v1/users/me/avatar` (avatar UPLOAD,
     * `WebPortalRouter.php:372`) and `DELETE /api/v1/users/me/avatar` (avatar
     * DELETE, `:373`) — two REAL server writes at the exact path S238 now admits
     * under GET. They stay refused by the hub's OWN method gate (no avatar entry
     * under any write key), which is why they need no `SCOPE_DENY_PATTERNS`
     * entry: nothing here depends on the server's route table.
     *
     * HEAD is included because it is inert by design (no `Router::head()`
     * registrar) and must stay that way — S238 does not make HEAD live.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function s238NonGetVerbProvider(): iterable
    {
        $paths = [
            'artwork' => '/api/v1/artwork/item-1',
            'avatar' => '/api/v1/users/me/avatar',
        ];
        foreach ($paths as $label => $path) {
            foreach (['HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
                yield "{$method} {$label}" => [$method, $path];
            }
        }
    }

    /**
     * @dataProvider s238NonGetVerbProvider
     */
    public function testS238ImageReadsAreGetOnly(string $method, string $path): void
    {
        $controller = $this->controller(
            $this->createMock(ServerInfoHandler::class),
            $this->bridge(static fn () => null),
        );

        $reflected = new ReflectionMethod(ServerProxyController::class, 'isWithinBrowseScope');
        $reflected->setAccessible(true);

        $this->assertFalse(
            $reflected->invoke($controller, $method, $path),
            "{$method} {$path} must fail closed — S238 opens a READ, never a write "
            . '(POST/DELETE /api/v1/users/me/avatar are real server writes at this exact path)',
        );
    }

    // -----------------------------------------------------------------------
    // S350 — the trickplay allow patterns mirror ONLY the routes the server
    // still registers. `sprite.jpg` and `timeline.json` survive; S275 deleted
    // the `thumb-{index}.jpg`/`index.xml` route family (only the deleted
    // `TrickplayGenerator` wrote the `bif_NN.jpg`/`index.xml` files behind
    // them), so the hub patterns mirroring those routes were dead relay
    // surface — forwarded here, 404 at the server. The deny rows are the guard
    // the prune needs: a re-added `thumb-*`/`index.xml` pattern would make one
    // of them green, and a dropped live pattern would make one of the allow
    // rows red.
    // -----------------------------------------------------------------------

    /**
     * The trickplay surface the hub admits, and the two families S350 pruned.
     *
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function s350TrickplayScopeProvider(): iterable
    {
        // The two route families the server still registers
        // (`Server\Core\Application::loadTrickplayRoutes()`: sprite.jpg,
        // thumbs.bif, timeline.json) and the hub still mirrors.
        yield 'trickplay sprite sheet' => ['/trickplay/job-abc/sprite.jpg', true];
        yield 'trickplay sprite sheet, uuid job' => [
            '/trickplay/550e8400-e29b-41d4-a716-446655440000/sprite.jpg',
            true,
        ];
        yield 'trickplay timeline json' => ['/trickplay/job-abc/timeline.json', true];

        // S275 removed `thumb-{index}.jpg` and `index.xml` from the server; the
        // hub patterns mirroring them are pruned, so every shape in those two
        // families now fails closed HERE (403) instead of 404-ing at the server.
        yield 'trickplay thumb-0 jpg' => ['/trickplay/job-abc/thumb-0.jpg', false];
        yield 'trickplay thumb-0 png' => ['/trickplay/job-abc/thumb-0.png', false];
        yield 'trickplay thumb-12345 jpg' => ['/trickplay/job-abc/thumb-12345.jpg', false];
        yield 'trickplay index xml' => ['/trickplay/job-abc/index.xml', false];

        // `thumbs.bif` is a LIVE server route the hub has never mirrored — it
        // stays outside browse scope, pinned so a future accidental allow
        // pattern for it cannot land unpinned.
        yield 'trickplay thumbs bif' => ['/trickplay/job-abc/thumbs.bif', false];

        // Sibling bleed: the anchored patterns are exact, so a tail that merely
        // shares a prefix must not ride a surviving pattern.
        yield 'trickplay thumb without index' => ['/trickplay/job-abc/thumb.jpg', false];
        yield 'trickplay thumb-0 gif sibling' => ['/trickplay/job-abc/thumb-0.gif', false];
        yield 'trickplay index html sibling' => ['/trickplay/job-abc/index.html', false];
        yield 'trickplay two-segment job id' => ['/trickplay/a/b/sprite.jpg', false];
    }

    /**
     * @dataProvider s350TrickplayScopeProvider
     */
    public function testS350TrickplayScopeMatchesTheSurvivingServerRoutes(
        string $path,
        bool $expected,
    ): void {
        $controller = $this->controller(
            $this->createMock(ServerInfoHandler::class),
            $this->bridge(static fn () => null),
        );

        $reflected = new ReflectionMethod(ServerProxyController::class, 'isWithinBrowseScope');
        $reflected->setAccessible(true);

        $this->assertSame(
            $expected,
            $reflected->invoke($controller, 'GET', $path),
            $expected
                ? "GET {$path} is a live trickplay route and must be forwarded over the relay"
                : "GET {$path} mirrors a route S275 deleted and must fail closed (out of browse scope)",
        );
    }

    /**
     * The S275-deleted trickplay families, for the end-to-end refusal rows.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function s350TrickplayDeadFamilyProvider(): iterable
    {
        yield 'thumb-0 jpg' => ['/trickplay/job-abc/thumb-0.jpg'];
        yield 'thumb-0 png' => ['/trickplay/job-abc/thumb-0.png'];
        yield 'thumb-12345 jpg' => ['/trickplay/job-abc/thumb-12345.jpg'];
        yield 'index xml' => ['/trickplay/job-abc/index.xml'];
    }

    /**
     * End-to-end control: the dead families now 403 `proxy.scope_denied` and
     * never reach the bridge. Before S350 they were forwarded and 404'd only at
     * the server — relying on the peer's route table for a path the hub itself
     * had decided to admit. After the prune the hub's own gate is the refusal.
     *
     * @dataProvider s350TrickplayDeadFamilyProvider
     */
    public function testS350TrickplayDeadFamilies403AndAreNotForwarded(string $path): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true,
        ]);

        $forwarded = false;
        $controller = $this->controller($info, $this->bridge(static function (
            string $e,
            array $d,
        ) use (&$forwarded): void {
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

    // -----------------------------------------------------------------------
    // S63 — cast/DLNA transport control for the flagged `playback_control` MCP
    // tool. Same SHAPE as S238 and for the same reason: anchored
    // `BROWSE_SCOPE_PATTERNS` entries, never a `/api/v1/cast` or `/api/v1/dlna`
    // PREFIX. A prefix would admit the two session-START routes the tool
    // deliberately does not expose, and (for dlna) would sit beside the whole
    // UPnP surface `dlnaStillDeniedProvider()` pins DENIED — the one change
    // that could make two providers in this file contradict each other.
    // -----------------------------------------------------------------------

    /**
     * The eleven S63 patterns, exactly as production must hold them.
     *
     * Exact string equality, never `str_contains`: `'#^/api/v1/cast/devices$#'`
     * is a substring of a MUTATED `'#^/api/v1/cast/devices$#x'`, so a substring
     * assertion would pass the very mutation this exists to catch.
     *
     * @var array<string, list<string>>
     */
    private const S63_EXPECTED_PATTERNS = [
        'GET' => [
            '#^/api/v1/cast/devices$#',
            '#^/api/v1/cast/devices/[^/]+/status$#',
            '#^/api/v1/dlna/renderers$#',
            '#^/api/v1/dlna/renderers/[^/]+/status$#',
        ],
        'POST' => [
            '#^/api/v1/cast/devices/[^/]+/play$#',
            '#^/api/v1/cast/devices/[^/]+/pause$#',
            '#^/api/v1/cast/devices/[^/]+/stop$#',
            '#^/api/v1/cast/devices/[^/]+/seek$#',
            '#^/api/v1/dlna/renderers/[^/]+/pause$#',
            '#^/api/v1/dlna/renderers/[^/]+/stop$#',
            '#^/api/v1/dlna/renderers/[^/]+/seek$#',
        ],
    ];

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function s63ExpectedPatternProvider(): iterable
    {
        foreach (self::S63_EXPECTED_PATTERNS as $method => $patterns) {
            foreach ($patterns as $pattern) {
                yield "{$method} {$pattern}" => [$method, $pattern];
            }
        }
    }

    /**
     * Each pattern is present in the REAL constant, byte for byte.
     *
     * One test per pattern (a data provider), so DELETING any single entry reds
     * a NAMED test that says which one — rather than one aggregate assertion
     * whose message has to be read to find out.
     *
     * @dataProvider s63ExpectedPatternProvider
     */
    public function testS63CastPatternsArePresentInTheRealConstant(string $method, string $pattern): void
    {
        $raw = (new ReflectionClass(ServerProxyController::class))
            ->getConstant('BROWSE_SCOPE_PATTERNS');
        $this->assertIsArray($raw, 'BROWSE_SCOPE_PATTERNS must still be an array constant');
        /** @var array<string, list<string>> $patterns */
        $patterns = $raw;

        $this->assertArrayHasKey($method, $patterns, "BROWSE_SCOPE_PATTERNS['{$method}'] is missing");
        $this->assertGreaterThanOrEqual(
            count(self::S63_EXPECTED_PATTERNS[$method]),
            count($patterns[$method]),
            sprintf(
                'ANTI-VACUITY: BROWSE_SCOPE_PATTERNS[%s] holds %d entries, fewer than the %d S63 alone '
                . 'requires. The map has been hollowed.',
                $method,
                count($patterns[$method]),
                count(self::S63_EXPECTED_PATTERNS[$method]),
            ),
        );

        // assertContains is strict (===) in PHPUnit 10 — a one-character
        // mutation of the pattern reds this.
        $this->assertContains(
            $pattern,
            $patterns[$method],
            sprintf(
                "S63: BROWSE_SCOPE_PATTERNS['%s'] must carry the exact literal %s — without it the "
                . 'playback_control tool gets 403 proxy.scope_denied for that action.',
                $method,
                $pattern,
            ),
        );
    }

    /**
     * The paths `playback_control` actually forwards, in the shapes the server
     * serves them.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function s63AllowedPathProvider(): iterable
    {
        yield 'chromecast device list' => ['GET', '/api/v1/cast/devices'];
        yield 'chromecast status by uuid'
            => ['GET', '/api/v1/cast/devices/550e8400-e29b-41d4-a716-446655440000/status'];
        yield 'chromecast status by friendly id' => ['GET', '/api/v1/cast/devices/living-room/status'];
        yield 'dlna renderer list' => ['GET', '/api/v1/dlna/renderers'];
        // A DLNA renderer id is a UPnP UDN, which really does look like this.
        yield 'dlna status by udn' => ['GET', '/api/v1/dlna/renderers/uuid:5f9ec1b3-ed59-79bb/status'];

        yield 'chromecast play' => ['POST', '/api/v1/cast/devices/living-room/play'];
        yield 'chromecast pause' => ['POST', '/api/v1/cast/devices/living-room/pause'];
        yield 'chromecast stop' => ['POST', '/api/v1/cast/devices/living-room/stop'];
        yield 'chromecast seek' => ['POST', '/api/v1/cast/devices/living-room/seek'];
        yield 'dlna pause' => ['POST', '/api/v1/dlna/renderers/uuid:5f9ec1b3-ed59-79bb/pause'];
        yield 'dlna stop' => ['POST', '/api/v1/dlna/renderers/uuid:5f9ec1b3-ed59-79bb/stop'];
        yield 'dlna seek' => ['POST', '/api/v1/dlna/renderers/uuid:5f9ec1b3-ed59-79bb/seek'];
    }

    /**
     * @dataProvider s63AllowedPathProvider
     */
    public function testS63CastPathsAreWithinBrowseScope(string $method, string $path): void
    {
        $controller = $this->controller(
            $this->createMock(ServerInfoHandler::class),
            $this->bridge(static fn () => null),
        );

        $reflected = new ReflectionMethod(ServerProxyController::class, 'isWithinBrowseScope');
        $reflected->setAccessible(true);

        $this->assertTrue(
            $reflected->invoke($controller, $method, $path),
            "{$method} {$path} is an S63 playback-control path and must be forwarded over the relay",
        );
    }

    /**
     * End-to-end through `proxy()`: the POST reaches the bridge with the path
     * AND the JSON body intact.
     *
     * The body assertion is the load-bearing half. A seek that arrives without
     * its `position_ms` is a seek to zero — a silent, wrong action rather than a
     * visible failure — so "it was forwarded" is not enough to assert.
     */
    public function testS63ASeekPostForwardsItsBodyToTheBridge(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn(
            ['userId' => 'user-1', 'status' => 'online', 'relayActive' => true],
        );

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
                'body' => '{"success":true,"position_ms":42000}',
            ]);
        };
        $bridge = $this->bridge($publisher);

        $request = $this->request('POST', 'user-1');
        $request->body = ['position_ms' => 42000];

        $controller = $this->controller($info, $bridge);
        $response = $controller->proxy($request, [
            'id' => 'srv-1',
            'path' => 'api/v1/cast/devices/living-room/seek',
        ]);

        $this->assertSame(200, $response->statusCode, 'the seek POST must be forwarded, not 403 scope_denied');
        $this->assertIsArray($forwarded, 'the seek POST must reach the relay bridge');
        $this->assertSame('/api/v1/cast/devices/living-room/seek', $forwarded['path']);
        $this->assertSame('POST', $forwarded['method'] ?? null);
        // The bridge base64s the body onto the wire; decode rather than assert
        // the encoded form, so this row keeps meaning if the encoding changes.
        $this->assertIsString($forwarded['body_b64'] ?? null);
        /** @var string $encoded */
        $encoded = $forwarded['body_b64'];
        $this->assertSame('{"position_ms":42000}', base64_decode($encoded, true));
    }

    /**
     * THE CONTROL. Everything the eleven anchored patterns must NOT admit.
     *
     * Each row is a path a BROADER shape would have let through:
     *  - the two session-START routes the tool deliberately does not expose
     *    (`.../cast` and DLNA's `.../play`, whose body carries a caller-supplied
     *    URI the renderer is told to fetch);
     *  - the Roku and AirPlay surfaces, which no tool wraps;
     *  - anything DEEPER than the server's single-segment device id, and any
     *    path below an allowed action;
     *  - SIBLING bleed (`/api/v1/castX/...`, `.../pauses`, `.../seekX`);
     *  - the ROOT DLNA/UPnP surface `dlnaStillDeniedProvider()` already pins,
     *    re-asserted HERE so a future `/api/v1/dlna` prefix cannot pass one
     *    provider while breaking the other silently;
     *  - `/api/v1/admin/users`, re-asserted so this change cannot be read as
     *    having relaxed anything else.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function s63MustStayDeniedProvider(): iterable
    {
        // The two session STARTs — real server routes at these exact paths.
        yield 'chromecast session start' => ['POST', '/api/v1/cast/devices/living-room/cast'];
        yield 'dlna playTo session start' => ['POST', '/api/v1/dlna/renderers/uuid:5f9ec1b3/play'];

        // Roku / AirPlay: real server surfaces, no tool wraps them.
        yield 'roku device list' => ['GET', '/api/v1/roku/devices'];
        yield 'roku send media' => ['POST', '/api/v1/roku/devices/dev-1/send'];
        yield 'roku keypress' => ['POST', '/api/v1/roku/devices/dev-1/key/Home'];
        yield 'roku launch channel' => ['POST', '/api/v1/roku/devices/dev-1/launch/12345'];
        yield 'airplay device list' => ['GET', '/api/v1/airplay/devices'];
        yield 'airplay stream' => ['POST', '/api/v1/airplay/devices/dev-1/stream'];
        yield 'airplay resume' => ['POST', '/api/v1/airplay/devices/dev-1/resume'];

        // Deeper than the server's single-segment id, or below an action.
        yield 'cast two-segment device id' => ['POST', '/api/v1/cast/devices/a/b/pause'];
        yield 'path below a cast action' => ['POST', '/api/v1/cast/devices/dev-1/seek/0'];
        yield 'path below a cast status' => ['GET', '/api/v1/cast/devices/dev-1/status/detail'];
        yield 'dlna two-segment renderer id' => ['POST', '/api/v1/dlna/renderers/a/b/stop'];

        // Bare device resources the server serves for nobody.
        yield 'bare cast device' => ['GET', '/api/v1/cast/devices/dev-1'];
        yield 'bare dlna renderer' => ['GET', '/api/v1/dlna/renderers/dev-1'];
        yield 'bare cast root' => ['GET', '/api/v1/cast'];
        yield 'bare dlna root' => ['GET', '/api/v1/dlna'];

        // Sibling bleed.
        yield 'cast sibling prefix' => ['GET', '/api/v1/castX/devices'];
        yield 'devices sibling' => ['GET', '/api/v1/cast/devicesX'];
        yield 'renderers sibling' => ['GET', '/api/v1/dlna/renderersX'];
        yield 'pause plural' => ['POST', '/api/v1/cast/devices/dev-1/pauses'];
        yield 'seek with a suffix' => ['POST', '/api/v1/cast/devices/dev-1/seekX'];
        yield 'status with a suffix' => ['GET', '/api/v1/cast/devices/dev-1/statusX'];

        // The ROOT UPnP surface must stay out, whatever /api/v1/dlna does.
        yield 'upnp description' => ['GET', '/dlna/description.xml'];
        yield 'upnp content directory' => ['GET', '/dlna/content_directory'];
        yield 'upnp cds control' => ['POST', '/cds/control'];
        yield 'upnp scpd' => ['GET', '/scpd/ContentDirectory.xml'];

        // Unrelated privileged surface.
        yield 'admin users' => ['GET', '/api/v1/admin/users'];

        // ---- Systematic over-match rows, one set PER opened action ---------
        // The hand-written rows above are a sample; these are the rule. Each
        // anchored pattern can be broken in exactly three ways, and a control
        // that covers only SOME actions lets the other actions' mutations
        // survive — which is precisely what happened when this provider was
        // first written: widening `.../seek$` to `.../[^/]*.+/seek$` and
        // de-anchoring `.../stop$` were killed only by the literal-presence
        // test, because the sampled rows happened to name `pause` and `seek`.
        //
        //  (a) the id class stops being single-segment  -> a two-segment id;
        //  (b) the trailing `$` anchor is lost           -> a sub-path below it;
        //  (c) the leading `^` anchor is lost            -> a prefixed path.
        $opened = [
            'GET' => [
                '/api/v1/cast/devices/%s/status',
                '/api/v1/dlna/renderers/%s/status',
            ],
            'POST' => [
                '/api/v1/cast/devices/%s/play',
                '/api/v1/cast/devices/%s/pause',
                '/api/v1/cast/devices/%s/stop',
                '/api/v1/cast/devices/%s/seek',
                '/api/v1/dlna/renderers/%s/pause',
                '/api/v1/dlna/renderers/%s/stop',
                '/api/v1/dlna/renderers/%s/seek',
            ],
        ];
        foreach ($opened as $method => $templates) {
            foreach ($templates as $template) {
                $single = sprintf($template, 'dev-1');
                yield "two-segment id: {$method} {$template}" => [$method, sprintf($template, 'a/b')];
                yield "sub-path below: {$method} {$single}" => [$method, $single . '/extra'];
                yield "prefixed path: {$method} {$single}" => [$method, '/xyz' . $single];
                yield "action suffix: {$method} {$single}" => [$method, $single . 'X'];
            }
        }
        // ...and the same three shapes for the two static collection reads.
        foreach (['/api/v1/cast/devices', '/api/v1/dlna/renderers'] as $collection) {
            yield "sub-path below: GET {$collection}" => ['GET', $collection . '/dev-1'];
            yield "prefixed path: GET {$collection}" => ['GET', '/xyz' . $collection];
            yield "collection suffix: GET {$collection}" => ['GET', $collection . 'X'];
        }
    }

    /**
     * @dataProvider s63MustStayDeniedProvider
     */
    public function testS63WideningAdmitsNothingBeyondTheNamedActions(string $method, string $path): void
    {
        $controller = $this->controller(
            $this->createMock(ServerInfoHandler::class),
            $this->bridge(static fn () => null),
        );

        $reflected = new ReflectionMethod(ServerProxyController::class, 'isWithinBrowseScope');
        $reflected->setAccessible(true);

        $this->assertFalse(
            $reflected->invoke($controller, $method, $path),
            "{$method} {$path} must stay out of browse scope: S63 opens the named cast/DLNA transport "
            . 'actions and nothing else',
        );
    }

    /**
     * End-to-end control: a denied neighbour 403s `proxy.scope_denied` and never
     * reaches the bridge — the SUCCEEDING sibling being
     * `testS63ASeekPostForwardsItsBodyToTheBridge()` above, so a 403
     * here is provably "the scope gate refused it" and not "the harness never
     * got anywhere".
     *
     * @dataProvider s63MustStayDeniedProvider
     */
    public function testS63DeniedNeighbours403AndAreNotForwarded(string $method, string $path): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn(
            ['userId' => 'user-1', 'status' => 'online', 'relayActive' => true],
        );

        $forwarded = false;
        $controller = $this->controller($info, $this->bridge(static function () use (&$forwarded): void {
            $forwarded = true;
        }));

        $response = $controller->proxy(
            $this->request($method, 'user-1'),
            ['id' => 'srv-1', 'path' => ltrim($path, '/')],
        );

        $this->assertSame(403, $response->statusCode, "{$method} {$path} must fail closed");
        $this->assertFalse($forwarded, "{$method} {$path} must never reach the relay bridge");
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame('proxy.scope_denied', $body['code'] ?? null);
    }

    /**
     * Each S63 action is opened for exactly ONE verb.
     *
     * The reads must not be writable and the writes must not be readable: a
     * `GET /api/v1/cast/devices/{id}/pause` that the hub forwarded would 404
     * server-side today only because that route happens to be POST-only, and
     * this gate must not depend on the peer's route table (the S100/S107
     * lesson).
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function s63WrongVerbProvider(): iterable
    {
        $reads = ['/api/v1/cast/devices', '/api/v1/cast/devices/dev-1/status', '/api/v1/dlna/renderers'];
        $writes = ['/api/v1/cast/devices/dev-1/pause', '/api/v1/dlna/renderers/dev-1/seek'];

        foreach ($reads as $path) {
            foreach (['HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
                yield "{$method} {$path}" => [$method, $path];
            }
        }
        foreach ($writes as $path) {
            foreach (['GET', 'HEAD', 'PUT', 'PATCH', 'DELETE'] as $method) {
                yield "{$method} {$path}" => [$method, $path];
            }
        }
    }

    /**
     * @dataProvider s63WrongVerbProvider
     */
    public function testS63EachActionIsOpenedForExactlyOneVerb(string $method, string $path): void
    {
        $controller = $this->controller(
            $this->createMock(ServerInfoHandler::class),
            $this->bridge(static fn () => null),
        );

        $reflected = new ReflectionMethod(ServerProxyController::class, 'isWithinBrowseScope');
        $reflected->setAccessible(true);

        $this->assertFalse(
            $reflected->invoke($controller, $method, $path),
            "{$method} {$path} must fail closed — S63 opens each action under one verb only",
        );
    }

    /**
     * S63 adds no PREFIX, so the S107 enumeration is not re-opened — asserted
     * rather than argued, exactly as `testS238AddedNoNewBrowseScopePrefix()`
     * does for its own change.
     *
     * This test fails if a future change quietly turns one of the eleven
     * anchored patterns into a prefix, which is the shortcut the enumeration
     * rule exists to catch — and, for `/api/v1/dlna`, the shortcut that would
     * make this file's own `dlnaStillDeniedProvider()` and
     * `s63AllowedPathProvider()` start disagreeing.
     */
    public function testS63AddedNoNewBrowseScopePrefix(): void
    {
        $raw = (new ReflectionClass(ServerProxyController::class))
            ->getConstant('BROWSE_SCOPE_ALLOWLIST');
        $this->assertIsArray($raw, 'BROWSE_SCOPE_ALLOWLIST must still be an array constant');
        /** @var array<string, list<string>> $allowlist */
        $allowlist = $raw;

        $this->assertSame(
            ['GET'],
            array_keys($allowlist),
            'S63 forwards WRITES, and they must ride anchored BROWSE_SCOPE_PATTERNS entries — never a '
            . 'write key here, which would re-open the S107 sweep for a whole prefix. (S247 removed the '
            . 'HEAD key: HEAD scope is one anchored pattern, so GET is the only prefix-carrying verb.)',
        );

        foreach (['GET'] as $method) {
            $prefixes = $allowlist[$method] ?? [];
            $this->assertNotSame([], $prefixes, "ANTI-VACUITY: BROWSE_SCOPE_ALLOWLIST[{$method}] is empty");
            foreach (['/api/v1/cast', '/api/v1/dlna', '/api/v1/roku', '/api/v1/airplay', '/dlna'] as $forbidden) {
                $this->assertNotContains(
                    $forbidden,
                    $prefixes,
                    sprintf(
                        '%s must NEVER be a %s PREFIX. For /api/v1/cast it would admit the session-START '
                        . 'route; for /api/v1/dlna and /dlna it would collide with the UPnP surface '
                        . 'dlnaStillDeniedProvider() pins denied. S63 uses anchored '
                        . 'BROWSE_SCOPE_PATTERNS entries instead.',
                        $forbidden,
                        $method,
                    ),
                );
            }
        }
    }

    /**
     * S238 adds no PREFIX, so the S107 enumeration is not re-opened — asserted
     * rather than argued.
     *
     * `SCOPE_DENY_PATTERNS` is derived from `BROWSE_SCOPE_ALLOWLIST['GET']`
     * prefixes: a path is swept when it lies UNDER one. Neither image read does,
     * so the sweep is untouched. This test fails if a future change quietly turns
     * one of the anchored patterns into a prefix — the exact shortcut the
     * enumeration rule exists to catch.
     */
    public function testS238AddedNoNewBrowseScopePrefix(): void
    {
        $raw = (new ReflectionClass(ServerProxyController::class))
            ->getConstant('BROWSE_SCOPE_ALLOWLIST');
        $this->assertIsArray($raw, 'BROWSE_SCOPE_ALLOWLIST must still be an array constant');
        /** @var array<string, list<string>> $allowlist */
        $allowlist = $raw;

        foreach (['GET'] as $method) {
            $prefixes = $allowlist[$method] ?? [];
            $this->assertNotSame([], $prefixes, "ANTI-VACUITY: BROWSE_SCOPE_ALLOWLIST[{$method}] is empty");
            // S247 adds `/media` to the forbidden-prefix list for the same
            // reason S238 kept artwork/users out: the id is a MIDDLE segment, so
            // a prefix admits every present and future `/media/…` route.
            foreach (['/api/v1/artwork', '/api/v1/users', '/media'] as $forbidden) {
                $this->assertNotContains(
                    $forbidden,
                    $prefixes,
                    sprintf(
                        '%s must NEVER be a %s PREFIX: it would admit an arbitrary sub-tree '
                        . '(for /api/v1/users, the whole me/settings|favorites|history surface). '
                        . 'S238 uses anchored BROWSE_SCOPE_PATTERNS entries instead.',
                        $forbidden,
                        $method,
                    ),
                );
            }
        }
    }

    // =====================================================================
    // S247 — the direct-play byte stream over the relay: Range/206, HEAD, and
    // WHICH gate actually fires.
    // =====================================================================

    /**
     * Build a real file on disk with deterministic, non-repeating content, so a
     * range slice of it cannot accidentally equal a different slice.
     *
     * Real bytes matter here: the whole point of the range assertions is that
     * the bytes the browser receives are the bytes on the server's disk, and a
     * fixture of `str_repeat('a', N)` would make every wrong offset produce the
     * right md5.
     *
     * @param int $size Bytes to write.
     *
     * @return string Absolute path to the fixture (removed in tearDown).
     */
    private function byteStreamFixture(int $size): string
    {
        $path = tempnam(sys_get_temp_dir(), 's247-');
        self::assertIsString($path, 'the fixture file could not be created');
        // Deterministic but position-dependent: byte i is a function of i.
        $buffer = '';
        for ($i = 0; $i < $size; $i++) {
            $buffer .= chr(($i * 31 + ($i >> 8) * 7 + 11) % 256);
        }
        file_put_contents($path, $buffer);
        self::assertSame($size, filesize($path), 'the fixture is not the size it claims');
        $this->fixtures[] = $path;

        return $path;
    }

    /** @var list<string> Fixture files to remove after each test. */
    private array $fixtures = [];

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->fixtures = [];
        parent::tearDown();
    }

    /**
     * A publisher that behaves like phlix-server's `serveMediaStream()`: it
     * parses the forwarded `Range` request header against a REAL file on disk
     * and replies with either 200 (whole file), 206 + `Content-Range` (the
     * requested slice, read from disk with `fseek`/`fread`), or 416.
     *
     * It is written against `$data['headers']` — the headers the controller
     * actually forwarded — so a controller that STRIPPED `Range` would silently
     * fall back to the 200 branch and the 206 assertions would fail. That is
     * deliberate: it makes "the Range header survived the hub" an observable
     * consequence rather than a separate claim.
     *
     * @param string                 $path   Fixture file on disk.
     * @param RelayProxyBridge|null  $bridge By-reference bridge handle.
     *
     * @return callable(string, array<string, mixed>): void
     */
    private function diskRangePublisher(string $path, &$bridge): callable
    {
        return function (string $event, array $data) use ($path, &$bridge): void {
            /** @var RelayProxyBridge $bridge */
            $id = $data['request_id'];
            $size = (int) filesize($path);

            /** @var array<string, string> $headers */
            $headers = is_array($data['headers'] ?? null) ? $data['headers'] : [];
            $range = null;
            foreach ($headers as $name => $value) {
                if (strtolower((string) $name) === 'range' && is_string($value)) {
                    $range = $value;
                }
            }

            $start = 0;
            $end = $size - 1;
            $partial = false;
            if ($range !== null && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m) === 1) {
                $partial = true;
                if ($m[1] === '' && $m[2] !== '') {
                    // Suffix range: the LAST N bytes.
                    $start = max(0, $size - (int) $m[2]);
                    $end = $size - 1;
                } else {
                    $start = (int) $m[1];
                    $end = $m[2] === '' ? $size - 1 : (int) $m[2];
                }
                if ($start >= $size || $start > $end) {
                    $bridge->onReply(['request_id' => $id, 'phase' => 'head', 'status' => 416, 'headers' => [
                        'Content-Type' => 'video/x-matroska',
                        'Content-Range' => "bytes */{$size}",
                        'Content-Length' => '0',
                    ]]);
                    $bridge->onReply(['request_id' => $id, 'phase' => 'end']);
                    return;
                }
            }

            $length = $end - $start + 1;
            $replyHeaders = [
                'Content-Type' => 'video/x-matroska',
                'Content-Length' => (string) $length,
                'Accept-Ranges' => 'bytes',
            ];
            if ($partial) {
                $replyHeaders['Content-Range'] = "bytes {$start}-{$end}/{$size}";
            }
            $bridge->onReply([
                'request_id' => $id,
                'phase' => 'head',
                'status' => $partial ? 206 : 200,
                'headers' => $replyHeaders,
            ]);

            // Stream the slice off disk in fragments, exactly as the tunnel
            // delivers it — never one buffered blob.
            $fh = fopen($path, 'rb');
            self::assertIsResource($fh);
            fseek($fh, $start);
            $remaining = $length;
            while ($remaining > 0) {
                $chunk = fread($fh, min(65536, $remaining));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $remaining -= strlen($chunk);
                $bridge->onReply(['request_id' => $id, 'phase' => 'body', 'body' => $chunk]);
            }
            fclose($fh);
            $bridge->onReply(['request_id' => $id, 'phase' => 'end']);
        };
    }

    /**
     * S247 — the `Range` REQUEST header must reach the paired server verbatim.
     *
     * `<video>` seeks by byte range. If the hub stripped `Range` the server
     * would answer 200 with the whole file and playback would appear to "work"
     * while every seek silently restarted the download — the failure this item
     * exists to prevent.
     *
     * The assertion is on the forwarded envelope captured OUTSIDE the closure
     * (recorded into a variable, asserted after the call returns) so an upstream
     * `catch (Throwable)` cannot swallow it. The stripped-header controls are in
     * the same test so "everything was forwarded" cannot pass as "Range was
     * forwarded".
     */
    public function testS247RangeRequestHeaderReachesThePairedServerVerbatim(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

        /** @var array<string, mixed>|null $forwarded */
        $forwarded = null;
        $bridge = null;
        $publisher = function (string $event, array $data) use (&$bridge, &$forwarded): void {
            $forwarded = $data;
            /** @var RelayProxyBridge $bridge */
            $bridge->onReply(['request_id' => $data['request_id'], 'phase' => 'head', 'status' => 206, 'headers' => [
                'Content-Length' => '1000',
                'Content-Range' => 'bytes 1000-1999/362807',
            ]]);
            $bridge->onReply(['request_id' => $data['request_id'], 'phase' => 'end']);
        };
        $bridge = $this->bridge($publisher);

        $controller = $this->controller($info, $bridge);
        $response = $controller->proxy(
            $this->requestWith('GET', 'user-1', [
                'Range' => 'bytes=1000-1999',
                'Accept' => '*/*',
                // Controls: these MUST be stripped, so a test that passes only
                // because "every header is forwarded" cannot exist.
                'Authorization' => 'Bearer hub-session-token',
                'Cookie' => 'phlix_hub_token=abc',
                'X-Phlix-Relay-User' => 'forged-user',
            ]),
            ['id' => 'srv-1', 'path' => 'media/item-123/stream'],
        );
        $this->drive($response);

        $this->assertIsArray($forwarded, 'the ranged byte-stream GET must reach the relay bridge');
        /** @var array<string, string> $sent */
        $sent = $forwarded['headers'];
        $this->assertSame(
            'bytes=1000-1999',
            $sent['Range'] ?? null,
            'Range must be forwarded verbatim: without it every <video> seek silently '
            . 'restarts the whole download while playback still appears to work',
        );
        // Succeeding controls beside the claim.
        $this->assertArrayNotHasKey('Authorization', $sent, 'the hub credential is not the server\'s');
        $this->assertArrayNotHasKey('Cookie', $sent, 'the hub cookie is not the server\'s');
        $this->assertSame('user-1', $sent['X-Phlix-Relay-User'] ?? null, 'the forged trust marker must be overwritten');
    }

    /**
     * S247 range matrix. Each row is a `Range` header (or null for an
     * un-ranged GET) plus the expected status.
     *
     * @return iterable<string, array{0: string|null, 1: int}>
     */
    public static function s247RangeProvider(): iterable
    {
        yield 'no range -> 200 whole file' => [null, 200];
        yield 'leading slice' => ['bytes=0-1023', 206];
        yield 'mid slice' => ['bytes=100000-200000', 206];
        yield 'open-ended tail' => ['bytes=300000-', 206];
        yield 'suffix range' => ['bytes=-4096', 206];
        yield 'single byte' => ['bytes=42-42', 206];
        yield 'final byte' => ['bytes=362806-362806', 206];
        yield 'unsatisfiable' => ['bytes=999999-1000000', 416];
    }

    /**
     * S247 AC — a real range request over the relay returns the right status,
     * the right `Content-Range`, and BYTES THAT MATCH DISK.
     *
     * This drives the whole hub pass-through: `proxy()` → the streaming
     * producer → {@see \Phlix\Hub\Relay\RelayProxyBridge::stream()} →
     * {@see \Phlix\Hub\Http\ConnectionResponseSink} → the browser socket, with a
     * 362 807-byte file physically on disk at the far end and the slice read
     * back with `fseek`/`fread`.
     *
     * ⚠ The `Content-Range` VALUE is asserted, not just the status. A 206 with
     * the wrong range is exactly the bug this item exists to prevent: the player
     * would seek to the wrong offset and the failure would look like corrupt
     * media rather than a proxy defect. The body is md5'd against the file on
     * disk for the same reason.
     *
     * @dataProvider s247RangeProvider
     */
    public function testS247RangedDirectPlayMatchesDiskByteForByte(?string $range, int $expectedStatus): void
    {
        $size = 362807;
        $path = $this->byteStreamFixture($size);

        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

        $bridge = null;
        $bridge = $this->bridge($this->diskRangePublisher($path, $bridge));

        $controller = $this->controller($info, $bridge);
        $headers = ['Accept' => '*/*'];
        if ($range !== null) {
            $headers['Range'] = $range;
        }
        $response = $controller->proxy(
            $this->requestWith('GET', 'user-1', $headers),
            ['id' => 'srv-1', 'path' => 'media/item-123/stream'],
        );

        $this->assertNotNull($response->streamProducer, 'the byte stream must take the STREAMING path');
        $connection = $this->drive($response);

        $written = $connection->written;
        $this->assertNotSame([], $written, 'nothing was written to the browser connection');
        $head = $written[0];
        $body = implode('', array_slice($written, 1));

        // ---- status ----------------------------------------------------
        $this->assertStringStartsWith(
            "HTTP/1.1 {$expectedStatus} ",
            $head,
            "the server's status must survive the pass-through verbatim (expected {$expectedStatus})",
        );

        if ($expectedStatus === 416) {
            $this->assertStringContainsString("Content-Range: bytes */{$size}", $head);
            $this->assertSame('', $body, 'a 416 carries no entity');
            return;
        }

        // ---- expected slice, computed from the file on DISK -------------
        [$start, $end] = self::expectedSlice($range, $size);
        $expectedBytes = (string) file_get_contents($path, false, null, $start, $end - $start + 1);
        $this->assertSame($end - $start + 1, strlen($expectedBytes), 'the fixture slice is the wrong size');

        // ---- Content-Range, by VALUE ------------------------------------
        if ($expectedStatus === 206) {
            $this->assertStringContainsString(
                "Content-Range: bytes {$start}-{$end}/{$size}",
                $head,
                'a 206 with the wrong Content-Range makes the player seek to the wrong offset',
            );
        } else {
            $this->assertStringNotContainsString(
                'Content-Range:',
                $head,
                'an un-ranged 200 must not claim to be partial',
            );
        }
        $this->assertStringContainsString('Content-Length: ' . ($end - $start + 1), $head);

        // ---- the bytes themselves ---------------------------------------
        $this->assertSame(
            md5($expectedBytes),
            md5($body),
            sprintf(
                'the relayed body must be byte-identical to disk (range=%s, %d bytes)',
                $range ?? '<none>',
                strlen($expectedBytes),
            ),
        );
        $this->assertSame($expectedBytes, $body, 'md5 agreement is necessary but the bytes are asserted directly too');
    }

    /**
     * Resolve `[start, end]` for a `Range` header against a known file size.
     * Independent of the production matcher on purpose — a helper derived from
     * its subject would self-adjust with it.
     *
     * @return array{0: int, 1: int}
     */
    private static function expectedSlice(?string $range, int $size): array
    {
        if ($range === null) {
            return [0, $size - 1];
        }
        if (preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m) !== 1) {
            return [0, $size - 1];
        }
        if ($m[1] === '' && $m[2] !== '') {
            return [$size - (int) $m[2], $size - 1];
        }
        $start = (int) $m[1];
        $end = $m[2] === '' ? $size - 1 : (int) $m[2];

        return [$start, $end];
    }

    /**
     * S247 — the un-ranged whole-file case, proven to be the WHOLE file.
     *
     * The provider row above asserts the same thing, but only through the
     * md5. This one states the size explicitly so an off-by-one that happened to
     * hash the same (it cannot, but the assertion should not depend on that)
     * would still be visible, and it pins the 362 807-byte figure S238 measured
     * across the real frame encoder.
     */
    public function testS247UnrangedDirectPlayStreamsTheWholeFile(): void
    {
        $size = 362807;
        $path = $this->byteStreamFixture($size);

        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

        $bridge = null;
        $bridge = $this->bridge($this->diskRangePublisher($path, $bridge));

        $controller = $this->controller($info, $bridge);
        $response = $controller->proxy(
            $this->requestWith('GET', 'user-1', ['Accept' => '*/*']),
            ['id' => 'srv-1', 'path' => 'media/item-123/stream'],
        );
        $connection = $this->drive($response);

        $body = implode('', array_slice($connection->written, 1));
        $this->assertSame($size, strlen($body), 'every byte of the file must reach the browser');
        $this->assertSame(md5_file($path), md5($body), 'the relayed file must be md5-identical to disk');
    }

    // ---------------------------------------------------------------------
    // S247 item 4 — WHICH gate fires on the relayed byte-stream path.
    // ---------------------------------------------------------------------

    /**
     * S247 AC — name the gate that refuses a relayed direct-play stream, with a
     * SUCCEEDING control beside it.
     *
     * The gate is `RelaySessionManager::activeUserStreams()` measured against
     * the operator's `max_concurrent_streams`, enforced HUB-SIDE in
     * {@see ServerProxyController::proxy()} → 503 `stream.limit`. It has to be
     * hub-side: `project_relay_auth_is_hub_side_only` records that phlix-server's
     * `AuthMiddleware` is a no-op for relayed requests, and the server's own
     * inline direct-play stream limit resolves a PROFILE from the server's user
     * rows — which a hub UUID never matches — so a server-side gate is not
     * protecting this path.
     *
     * Three things are asserted together, because any one alone is ambiguous:
     *  1. the refusal, with its code;
     *  2. that it is a DECISION and not a timeout — the bridge publisher is
     *     never invoked at all, and the call returns in well under the 30 s
     *     reply timeout (asserted on a real clock);
     *  3. the CONTROL: the identical request, differing ONLY in the active
     *     stream count, is admitted, occupies a slot and is forwarded.
     */
    public function testS247StreamLimitGateFiresOnTheRelayedByteStream(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

        // ---- the REFUSAL ------------------------------------------------
        $denySessions = $this->createMock(RelaySessionManager::class);
        $denySessions->method('checkUserQuota')->willReturn(
            ['allowed' => true, 'reason' => null, 'maxConcurrentStreams' => 2],
        );
        $denySessions->method('activeUserStreams')->willReturn(2);
        $denySessions->expects(self::never())->method('beginUserStream');

        $denyForwarded = 0;
        $denyBridge = $this->bridge(static function (string $e, array $d) use (&$denyForwarded): void {
            $denyForwarded++;
        });

        $startedAt = hrtime(true);
        $denied = $this->controller($info, $denyBridge, $denySessions)->proxy(
            $this->requestWith('GET', 'user-1', ['Range' => 'bytes=0-1023']),
            ['id' => 'srv-1', 'path' => 'media/item-123/stream'],
        );
        $elapsedSeconds = (hrtime(true) - $startedAt) / 1e9;

        $this->assertSame(503, $denied->statusCode);
        /** @var array{error?: array{code?: string}} $body */
        $body = json_decode($denied->body, true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame(
            'stream.limit',
            $body['error']['code'] ?? null,
            'the gate that refuses a relayed direct-play stream is the HUB-side concurrent-stream cap '
            . '(RelaySessionManager::activeUserStreams vs max_concurrent_streams), NOT anything in phlix-server',
        );
        $this->assertNull($denied->streamProducer, 'a refused stream must not return a producer');
        // (2) a DECISION, not a timeout: the bridge is never touched, and the
        // refusal is orders of magnitude faster than the 30 s reply timeout.
        $this->assertSame(0, $denyForwarded, 'the refusal must happen BEFORE anything is forwarded');
        $this->assertLessThan(
            1.0,
            $elapsedSeconds,
            'the refusal must be a decision — a cost bounded by the reply timeout would mean this test '
            . 'measures the timeout constant rather than the gate',
        );

        // ---- the CONTROL: same request, one fewer active stream ---------
        $allowSessions = $this->createMock(RelaySessionManager::class);
        $allowSessions->method('checkUserQuota')->willReturn(
            ['allowed' => true, 'reason' => null, 'maxConcurrentStreams' => 2],
        );
        $allowSessions->method('activeUserStreams')->willReturn(1);
        $allowSessions->expects(self::once())->method('beginUserStream')->with('user-1');
        $allowSessions->expects(self::once())->method('endUserStream')->with('user-1');

        $allowForwarded = 0;
        $allowBridge = null;
        $allowBridge = $this->bridge(function (string $e, array $d) use (&$allowBridge, &$allowForwarded): void {
            $allowForwarded++;
            /** @var RelayProxyBridge $allowBridge */
            $allowBridge->onReply(['request_id' => $d['request_id'], 'phase' => 'head', 'status' => 206, 'headers' => [
                'Content-Length' => '1024',
                'Content-Range' => 'bytes 0-1023/362807',
            ]]);
            $allowBridge->onReply([
                'request_id' => $d['request_id'],
                'phase' => 'body',
                'body' => str_repeat('x', 1024)
            ]);
            $allowBridge->onReply(['request_id' => $d['request_id'], 'phase' => 'end']);
        });

        $allowed = $this->controller($info, $allowBridge, $allowSessions)->proxy(
            $this->requestWith('GET', 'user-1', ['Range' => 'bytes=0-1023']),
            ['id' => 'srv-1', 'path' => 'media/item-123/stream'],
        );
        $connection = $this->drive($allowed);

        $this->assertNotNull($allowed->streamProducer, 'the control must be admitted as a stream');
        $this->assertSame(1, $allowForwarded, 'the control must reach the relay bridge');
        $this->assertStringStartsWith('HTTP/1.1 206 ', $connection->written[0]);
    }

    /**
     * S247 AC — the bandwidth quota gate, likewise hub-side, likewise with a
     * succeeding control. `RelaySessionManager::checkUserQuota()` → 503
     * `quota.exceeded`, refused before the stream is admitted.
     */
    public function testS247QuotaGateFiresOnTheRelayedByteStream(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

        $overQuota = $this->createMock(RelaySessionManager::class);
        $overQuota->method('checkUserQuota')->willReturn([
            'allowed' => false,
            'reason' => 'User has reached their monthly download bandwidth quota.',
            'maxConcurrentStreams' => 0,
        ]);
        $overQuota->expects(self::never())->method('beginUserStream');

        $forwarded = 0;
        $bridge = $this->bridge(static function (string $e, array $d) use (&$forwarded): void {
            $forwarded++;
        });

        $denied = $this->controller($info, $bridge, $overQuota)->proxy(
            $this->requestWith('GET', 'user-1', ['Range' => 'bytes=0-1023']),
            ['id' => 'srv-1', 'path' => 'media/item-123/stream'],
        );

        $this->assertSame(503, $denied->statusCode);
        /** @var array{error?: array{code?: string}} $body */
        $body = json_decode($denied->body, true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame('quota.exceeded', $body['error']['code'] ?? null);
        $this->assertSame(0, $forwarded, 'an over-quota byte stream must never reach the relay bridge');

        // CONTROL: identical request, quota allowed → admitted and forwarded.
        $inQuota = $this->createMock(RelaySessionManager::class);
        $inQuota->method('checkUserQuota')->willReturn(
            ['allowed' => true, 'reason' => null, 'maxConcurrentStreams' => 0],
        );
        $okForwarded = 0;
        $okBridge = null;
        $okBridge = $this->bridge(function (string $e, array $d) use (&$okBridge, &$okForwarded): void {
            $okForwarded++;
            /** @var RelayProxyBridge $okBridge */
            $okBridge->onReply(['request_id' => $d['request_id'], 'phase' => 'head', 'status' => 206, 'headers' => [
                'Content-Length' => '1024',
                'Content-Range' => 'bytes 0-1023/362807',
            ]]);
            $okBridge->onReply(['request_id' => $d['request_id'], 'phase' => 'end']);
        });

        $allowed = $this->controller($info, $okBridge, $inQuota)->proxy(
            $this->requestWith('GET', 'user-1', ['Range' => 'bytes=0-1023']),
            ['id' => 'srv-1', 'path' => 'media/item-123/stream'],
        );
        $this->drive($allowed);
        $this->assertSame(1, $okForwarded, 'the control must reach the relay bridge');
    }

    /**
     * S247 — the PARENTAL RATING GATE is documented as INERT on this path, and
     * that claim is pinned rather than left in a comment.
     *
     * `Phlix\Media\RatingGate` lives in phlix-server and resolves a filter from
     * that server's own user/profile rows. The only identity the hub can supply
     * is `X-Phlix-Relay-User`, the HUB user's UUID, which matches no row there —
     * so the gate is a strict no-op for every relayed request, byte stream
     * included. The hub cannot substitute for it (it holds no parental-control
     * profile and no per-item rating).
     *
     * What this test can assert, and does, is the hub-side half of that
     * statement: the ONLY user identity crossing the tunnel is the hub UUID, and
     * no parental/rating marker is added alongside it. If a future change starts
     * mapping the hub identity to a server identity, this test goes red and the
     * caveat in `buildForwardHeaders()` has to be revisited in the same commit.
     */
    public function testS247OnlyTheHubUuidCrossesTheTunnelSoTheServerRatingGateStaysInert(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

        /** @var array<string, mixed>|null $forwarded */
        $forwarded = null;
        $bridge = null;
        $bridge = $this->bridge(function (string $e, array $d) use (&$bridge, &$forwarded): void {
            $forwarded = $d;
            /** @var RelayProxyBridge $bridge */
            $bridge->onReply(['request_id' => $d['request_id'], 'phase' => 'head', 'status' => 200, 'headers' => [
                'Content-Length' => '0',
            ]]);
            $bridge->onReply(['request_id' => $d['request_id'], 'phase' => 'end']);
        });

        $response = $this->controller($info, $bridge)->proxy(
            $this->requestWith('GET', 'user-1', ['Accept' => '*/*']),
            ['id' => 'srv-1', 'path' => 'media/item-123/stream'],
        );
        $this->drive($response);

        $this->assertIsArray($forwarded);
        /** @var array<string, string> $sent */
        $sent = $forwarded['headers'];
        $this->assertSame('user-1', $sent['X-Phlix-Relay-User'] ?? null);
        $this->assertSame('1', $sent['X-Phlix-Relay'] ?? null);
        foreach (array_keys($sent) as $name) {
            $this->assertStringNotContainsStringIgnoringCase(
                'profile',
                (string) $name,
                'no server-side PROFILE identity crosses the tunnel — which is exactly why phlix-server\'s '
                . 'RatingGate cannot fire for a relayed request. Closing that needs a hub->server identity '
                . 'mapping in phlix-server; it is OWED, not shipped.',
            );
        }
    }

    /**
     * S247 item 3, end-to-end and ON THE WIRE — a relayed HEAD probe renders
     * exactly ONE `Content-Length`, and it is the paired server's.
     *
     * The previous test asserts the builder's state; this one renders the reply
     * the way the HTTP worker does (`toWorkermanResponse()` then string cast)
     * and counts the field, because the defect this closes lived entirely in the
     * encoder. The GET control immediately below it renders through the stock
     * encoder and is unaffected.
     */
    public function testS247RelayedHeadRendersExactlyOneContentLengthOnTheWire(): void
    {
        $info = $this->createMock(ServerInfoHandler::class);
        $info->method('getOwnerAndStatus')->willReturn([
            'userId' => 'user-1',
            'status' => 'online',
            'relayActive' => true
        ]);

        $reply = static function (RelayProxyBridge $bridge, string $id, string $body): void {
            $bridge->onReply([
                'request_id' => $id,
                'status' => 200,
                'headers' => [
                    'Content-Type' => 'video/x-matroska',
                    'Content-Length' => '362807',
                    'Accept-Ranges' => 'bytes',
                ],
                'body' => $body,
            ]);
        };

        // ---- the HEAD probe ---------------------------------------------
        $headBridge = null;
        $headBridge = $this->bridge(function (string $e, array $d) use (&$headBridge, $reply): void {
            /** @var RelayProxyBridge $headBridge */
            $reply($headBridge, $d['request_id'], '');
        });
        $head = $this->controller($info, $headBridge)->proxy(
            $this->requestWith('HEAD', 'user-1', ['Accept' => '*/*']),
            ['id' => 'srv-1', 'path' => 'media/item-123/stream'],
        );
        $wire = (string) $head->toWorkermanResponse();

        $this->assertSame(
            1,
            preg_match_all('/^Content-Length:/mi', $wire),
            'a relayed HEAD must put exactly ONE Content-Length on the wire — two conflicting values '
            . 'make the message invalid (RFC 9110 §8.6) and break the player rather than erroring',
        );
        $this->assertStringContainsString('Content-Length: 362807', $wire);
        $this->assertStringNotContainsString('Content-Length: 0', $wire);
        $this->assertStringContainsString('Accept-Ranges: bytes', $wire);
        $this->assertTrue(str_ends_with($wire, "\r\n\r\n"), 'a HEAD reply must end at the head terminator');

        // ---- the GET control, same upstream reply ------------------------
        // Buffered GET is not a byte stream (that streams), so use a JSON browse
        // path: it renders through the STOCK encoder and Workerman recomputes
        // Content-Length from the real body. Unchanged by S247.
        $getBridge = null;
        $getBridge = $this->bridge(function (string $e, array $d) use (&$getBridge, $reply): void {
            /** @var RelayProxyBridge $getBridge */
            $reply($getBridge, $d['request_id'], '{"ok":true}');
        });
        $get = $this->controller($info, $getBridge)->proxy(
            $this->requestWith('GET', 'user-1', ['Accept' => 'application/json']),
            ['id' => 'srv-1', 'path' => 'api/v1/media/item-123'],
        );
        $getWire = (string) $get->toWorkermanResponse();

        $this->assertFalse($get->headOnly, 'CONTROL: a GET must not select the bodyless encoder');
        $this->assertSame(1, preg_match_all('/^Content-Length:/mi', $getWire));
        $this->assertStringContainsString('Content-Length: 11', $getWire);
        $this->assertStringContainsString('{"ok":true}', $getWire);
        $this->assertStringNotContainsString(
            'Content-Length: 362807',
            $getWire,
            'CONTROL: on a GET the server\'s stale Content-Length is stripped and recomputed from the body',
        );
    }
}
