<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Common\RateLimit\RateLimiter;
use Phlix\Hub\Common\RateLimit\RateLimiterInterface;
use Phlix\Hub\Common\RateLimit\RateLimitProfiles;
use Phlix\Hub\Common\RateLimit\RateLimitState;
use Phlix\Hub\Http\Controllers\ClientMountController;
use Phlix\Hub\Hub\ClientRelayTokenService;
use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\Relay\ClientRelayWorker;
use Phlix\Hub\Relay\FrameDecoder;
use Phlix\Hub\Relay\FrameEncoder;
use Phlix\Hub\Relay\Tunnel;
use Phlix\Hub\Relay\TunnelManager;
use Phlix\Hub\Relay\TunnelManagerInterface;
use Phlix\Hub\Relay\WebSocketCloseFrame;
use Phlix\Hub\Stats\Metrics\MetricsCollector;
use Phlix\Hub\Stats\Metrics\MetricsFlushService;
use Phlix\Hub\Stats\Metrics\MetricsRegistry;
use Phlix\Shared\Relay\RelayFrameType;
use Phlix\Shared\Relay\RelayWireCodecInterface;
use Phlix\Hub\Tests\Support\LoggerFactoryIsolation;
use Phlix\Hub\Tests\Support\Relay\RecordingMountLimiter;
use Phlix\Hub\Tests\Support\WorkermanTimerRuntimeControl;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Container\ContainerInterface;
use Workerman\Connection\TcpConnection;
use Workerman\MySQL\Connection;
use Workerman\Protocols\Http\Request as WorkermanRequest;

use function hash;

/**
 * Unit tests for {@see ClientRelayWorker} — the client-facing relay path.
 *
 * Covers per-user relay-token accept/reject (step S2b), path parsing, token
 * extraction from the upgrade request (header / subprotocol only — the legacy
 * `?token=` query path was removed), ownership enforcement at mount, binding
 * to an existing tunnel, the no-tunnel-available close, and a DATA frame
 * round-trip through the router to the server tunnel.
 *
 * The worker no longer accepts the server's long-lived enrollment JWT as a
 * client credential. It validates a short-lived, revocable, per-user hub relay
 * token via {@see ClientRelayTokenService} and re-confirms the bound user owns
 * the target server via {@see ServerInfoHandler}.
 *
 * @package Phlix\Hub\Tests\Unit\Relay
 */
final class ClientRelayWorkerTest extends TestCase
{
    // `start()` builds a real Workerman `Worker`, which latches itself into the
    // process-global `Worker::$workers` and is never cleared; `setUp()` points the
    // static LoggerFactory at a temp config it later deletes. Both traits snapshot
    // before setUp() and restore after tearDown(), so neither escapes this class.
    use WorkermanTimerRuntimeControl;
    use LoggerFactoryIsolation;

    /** Server id used by the "owned, online" happy-path fixtures. */
    private const OWNER_USER_ID = 'user-owner-1';

    private string $tmpDir;
    private RelaySessionManager&MockObject $sessionManager;
    private RelayWireCodecInterface $codec;
    private StructuredLogger&MockObject $logger;
    private TunnelManager $tunnelManager;
    private ClientMountController $controller;

    /**
     * Map of plaintext relay token => bound {user_id, server_id} the fake
     * token service treats as ACTIVE (not expired, not revoked).
     *
     * @var array<string, array{user_id: string, server_id: string}>
     */
    private array $activeTokens = [];

    /**
     * Map of server_id => owning user_id used by the fake ServerInfoHandler.
     *
     * @var array<string, string>
     */
    private array $serverOwners = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The worker's connection/metrics maps are process-global statics; reset
        // so a prior test's leftovers cannot leak into this case.
        ClientRelayWorker::reset();

        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-client-relay-test-' . uniqid();
        mkdir($this->tmpDir, 0700, true);

        // The production ClientMountController resolves its logger through the
        // static LoggerFactory. Point it at a memory-stream config so tests do
        // not write to real log files or emit output. Reset in tearDown().
        $loggerConfig = $this->tmpDir . '/logger.php';
        file_put_contents(
            $loggerConfig,
            "<?php return ['default' => 'mem', 'handlers' => ['mem' => "
            . "['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug']]];",
        );
        LoggerFactory::reset();
        LoggerFactory::init($loggerConfig);

        $this->logger = $this->createMock(StructuredLogger::class);
        $this->sessionManager = $this->createMock(RelaySessionManager::class);
        $this->sessionManager->method('registerServer')->willReturn('session-123');

        $this->codec = new FrameDecoder();
        $this->tunnelManager = new TunnelManager($this->sessionManager, $this->codec, $this->logger);
        // HB-4.6f: the controller no longer takes a rate limiter — the real
        // client-mount limit lives on the ClientRelayWorker WS surface.
        $this->controller = new ClientMountController();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        ClientRelayWorker::reset();
        LoggerFactory::reset();

        $files = glob($this->tmpDir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    // ---- Path parsing ----------------------------------------------------

    public function testParseServerIdExtractsSegment(): void
    {
        self::assertSame('abc-123', ClientRelayWorker::parseServerId('/client/abc-123'));
        self::assertSame('abc-123', ClientRelayWorker::parseServerId('/client/abc-123/'));
        self::assertSame('abc-123', ClientRelayWorker::parseServerId('/client/abc-123?token=x'));
    }

    public function testParseServerIdUrlDecodes(): void
    {
        self::assertSame('a b', ClientRelayWorker::parseServerId('/client/a%20b'));
    }

    public function testParseServerIdRejectsNonClientPaths(): void
    {
        self::assertNull(ClientRelayWorker::parseServerId('/relay/abc-123'));
        self::assertNull(ClientRelayWorker::parseServerId('/client/'));
        self::assertNull(ClientRelayWorker::parseServerId('/client'));
        self::assertNull(ClientRelayWorker::parseServerId('/client/a/b'));
    }

    // ---- Token extraction ------------------------------------------------

    public function testExtractClientTokenFromAuthorizationHeader(): void
    {
        $request = $this->makeUpgradeRequest('/client/s1', ['Authorization' => 'Bearer relay-tok-123']);
        self::assertSame('relay-tok-123', ClientRelayWorker::extractClientToken($request));
    }

    public function testExtractClientTokenFromSecWebSocketProtocol(): void
    {
        $request = $this->makeUpgradeRequest('/client/s1', ['Sec-WebSocket-Protocol' => 'bearer, relay-tok-123']);
        self::assertSame('relay-tok-123', ClientRelayWorker::extractClientToken($request));
    }

    public function testExtractClientTokenIgnoresQueryParam(): void
    {
        // The legacy `?token=` query path was removed in S2b so secrets never
        // land in access logs — it must NOT be honoured even when present.
        $request = $this->makeUpgradeRequest('/client/s1?token=query.tok');
        self::assertNull(ClientRelayWorker::extractClientToken($request));
    }

    public function testExtractClientTokenReturnsNullWhenAbsent(): void
    {
        $request = $this->makeUpgradeRequest('/client/s1');
        self::assertNull(ClientRelayWorker::extractClientToken($request));
    }

    // ---- Token validation + ownership -----------------------------------

    public function testValidateClientAuthAcceptsValidOwnedToken(): void
    {
        $serverId = 'server-uuid-aaa';
        $token = 'valid-relay-token';
        $this->grantToken($token, self::OWNER_USER_ID, $serverId);
        $this->setServerOwner($serverId, self::OWNER_USER_ID);

        $worker = new ClientRelayWorker($this->buildContainer());

        // Returns the resolved (owning) user id on success so the caller can
        // attribute the metrics connection row to the user.
        self::assertSame(self::OWNER_USER_ID, $worker->validateClientAuth($token, $serverId));
    }

    /**
     * HB-1.4 leanness: the WS client-mount ownership re-confirmation MUST use
     * the lean {@see ServerInfoHandler::getOwnerAndStatus()} query
     * (SELECT id,user_id,status — no COUNT subquery) and MUST NOT touch the
     * heavy dashboard-shaped {@see ServerInfoHandler::getServerInfo()} on this
     * hot, reconnect-frequent path.
     */
    public function testValidateClientAuthUsesLeanOwnerQueryNotFullServerInfo(): void
    {
        $serverId = 'server-uuid-lean';
        $token = 'valid-relay-token-lean';
        $this->grantToken($token, self::OWNER_USER_ID, $serverId);

        $leanInfo = $this->createMock(ServerInfoHandler::class);
        $leanInfo->expects(self::once())
            ->method('getOwnerAndStatus')
            ->with($serverId)
            ->willReturn(['userId' => self::OWNER_USER_ID, 'status' => 'online', 'relayActive' => true]);
        // The dashboard-shaped, two-correlated-subquery getServerInfo() must
        // never run on the mount gate.
        $leanInfo->expects(self::never())->method('getServerInfo');

        $worker = new ClientRelayWorker($this->buildContainer(null, null, $leanInfo));

        self::assertSame(self::OWNER_USER_ID, $worker->validateClientAuth($token, $serverId));
    }

    public function testValidateClientAuthRejectsServerIdMismatch(): void
    {
        // Token is minted for server A but presented at server B's mount path.
        $token = 'token-for-server-a';
        $this->grantToken($token, self::OWNER_USER_ID, 'server-a');
        $this->setServerOwner('server-a', self::OWNER_USER_ID);
        $this->setServerOwner('server-b', self::OWNER_USER_ID);

        $worker = new ClientRelayWorker($this->buildContainer());

        self::assertNull($worker->validateClientAuth($token, 'server-b'));
    }

    public function testValidateClientAuthRejectsNonOwnedServer(): void
    {
        // Token is valid and scoped to the server, but the server is now owned
        // by someone else — mount must be rejected.
        $serverId = 'server-uuid-shared';
        $token = 'valid-but-not-owner';
        $this->grantToken($token, self::OWNER_USER_ID, $serverId);
        $this->setServerOwner($serverId, 'a-different-owner');

        $worker = new ClientRelayWorker($this->buildContainer());

        self::assertNull($worker->validateClientAuth($token, $serverId));
    }

    public function testValidateClientAuthRejectsUnknownOrRevokedToken(): void
    {
        // No token granted => the fake service returns null (covers unknown,
        // expired, and revoked — all return null from validate()).
        $serverId = 'server-uuid-aaa';
        $this->setServerOwner($serverId, self::OWNER_USER_ID);

        $worker = new ClientRelayWorker($this->buildContainer());

        self::assertNull($worker->validateClientAuth('never-minted', $serverId));
        self::assertNull($worker->validateClientAuth('', $serverId));
    }

    public function testValidateClientAuthRejectsRevokedToken(): void
    {
        // Mint then revoke the token before the mount: validate() must fail.
        $serverId = 'server-uuid-rev';
        $token = 'soon-to-be-revoked';
        $this->grantToken($token, self::OWNER_USER_ID, $serverId);
        $this->setServerOwner($serverId, self::OWNER_USER_ID);

        $worker = new ClientRelayWorker($this->buildContainer());
        self::assertSame(self::OWNER_USER_ID, $worker->validateClientAuth($token, $serverId));

        // Revoke: drop it from the active set.
        unset($this->activeTokens[hash('sha256', $token)]);

        self::assertNull($worker->validateClientAuth($token, $serverId));
    }

    public function testValidateClientAuthRejectsEnrollmentJwt(): void
    {
        // A JWT-looking credential is NOT a relay token — it is unknown to the
        // token table and must be rejected (closes the S2 hole).
        $serverId = 'server-uuid-aaa';
        $this->setServerOwner($serverId, self::OWNER_USER_ID);

        $worker = new ClientRelayWorker($this->buildContainer());

        self::assertNull($worker->validateClientAuth('header.payload.signature', $serverId));
    }

    // ---- WS connect: rejection paths ------------------------------------

    public function testOnWebSocketConnectRejectsMissingServerId(): void
    {
        $worker = new ClientRelayWorker($this->buildContainer());
        $request = $this->makeUpgradeRequest('/relay/not-a-client-path');

        $connection = $this->createMock(TcpConnection::class);
        $connection->expects($this->once())->method('close');

        $worker->onWebSocketConnect($connection, $request);
    }

    public function testOnWebSocketConnectRejectsMissingToken(): void
    {
        $worker = new ClientRelayWorker($this->buildContainer());
        $request = $this->makeUpgradeRequest('/client/server-uuid-aaa');

        $connection = $this->createMock(TcpConnection::class);
        // L-4: real WS close frame with code 4401 after the 101 completes.
        $worker->onWebSocketConnect($connection, $request);
        $this->assertRejectedWithCloseFrame($connection, ClientRelayWorker::CLOSE_UNAUTHORIZED);
    }

    public function testOnWebSocketConnectRejectsEnrollmentJwtOnly(): void
    {
        // Presenting only the server enrollment JWT (no relay token) is the
        // exact attack S2 closes: it must be rejected.
        $serverId = 'server-uuid-aaa';
        $this->setServerOwner($serverId, self::OWNER_USER_ID);

        $worker = new ClientRelayWorker($this->buildContainer());
        $request = $this->makeUpgradeRequest('/client/' . $serverId, [
            'Authorization' => 'Bearer header.payload.signature',
        ]);

        $connection = $this->createMock(TcpConnection::class);
        // L-4: 4401 delivered as a real WS close frame post-101.
        $worker->onWebSocketConnect($connection, $request);
        $this->assertRejectedWithCloseFrame($connection, ClientRelayWorker::CLOSE_UNAUTHORIZED);
    }

    public function testOnWebSocketConnectRejectsNonOwnedServer(): void
    {
        $serverId = 'server-uuid-foreign';
        $token = 'valid-token-foreign';
        $this->grantToken($token, self::OWNER_USER_ID, $serverId);
        $this->setServerOwner($serverId, 'a-different-owner');

        $worker = new ClientRelayWorker($this->buildContainer());
        $request = $this->makeUpgradeRequest('/client/' . $serverId, [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $connection = $this->createMock(TcpConnection::class);
        // L-4: 4401 delivered as a real WS close frame post-101.
        $worker->onWebSocketConnect($connection, $request);
        $this->assertRejectedWithCloseFrame($connection, ClientRelayWorker::CLOSE_UNAUTHORIZED);
    }

    public function testOnWebSocketConnectClosesWhenNoTunnelAvailable(): void
    {
        $serverId = 'server-uuid-offline';
        $token = 'valid-token-offline';
        $this->grantToken($token, self::OWNER_USER_ID, $serverId);
        $this->setServerOwner($serverId, self::OWNER_USER_ID);

        $worker = new ClientRelayWorker($this->buildContainer());
        $request = $this->makeUpgradeRequest('/client/' . $serverId, [
            'Authorization' => 'Bearer ' . $token,
        ]);

        // M2 (review hub__asphp): the mount bridge is DECLARED UNAVAILABLE in the
        // deployed multi-process topology — the controller refuses with 1011
        // WITHOUT consulting the tunnel registry. 1011 (not 1013 "Try Again
        // Later"): retrying this surface can never succeed until the
        // architecture changes; do not induce client retry loops.
        $connection = $this->createMock(TcpConnection::class);
        $worker->onWebSocketConnect($connection, $request);
        $this->assertRejectedWithCloseFrame($connection, ClientMountController::CLOSE_INTERNAL_ERROR);
    }

    // ---- HB-4.6f: client-mount rate limit (WS close 1013) ---------------

    /**
     * The :8803 client-mount surface must throttle inbound mounts by remote IP
     * and REJECT the over-limit handshake with WS code 1013 (WS≠HTTP: no 429
     * body here). A legit reconnect after the window resets must pass.
     */
    public function testClientMountRateLimitedAfterBurstThenReconnectPasses(): void
    {
        $serverId = 'server-uuid-mount-rl';
        $token = 'valid-token-mount-rl';
        $this->grantToken($token, self::OWNER_USER_ID, $serverId);
        $this->setServerOwner($serverId, self::OWNER_USER_ID);

        // Bring up an ACTIVE tunnel: under the old law this made under-limit
        // mounts BIND; under the M2 declared-unavailable law every mount
        // refuses (1011) regardless — so the ONLY per-connection difference the
        // limiter can still produce is 1013 (throttled, never reaches the
        // controller) vs 1011 (passed the limiter, refused at the controller).
        $serverWs = $this->createMock(TcpConnection::class);
        $tunnel = $this->tunnelManager->acceptServer($serverId, $serverWs);
        $tunnel->relaySessionId = 'session-123';
        $tunnel->status = Tunnel::STATUS_ACTIVE;

        $now = 1_000_000;
        $clock = function () use (&$now): int {
            return $now;
        };
        // 3 mounts/window/IP; advanceable clock so the reconnect is post-window.
        $limiter = new RateLimiter(60, 3, 10000, $clock);

        $worker = new ClientRelayWorker($this->buildContainer(null, null, null, $limiter));
        $request = $this->makeUpgradeRequest('/client/' . $serverId, [
            'Authorization' => 'Bearer ' . $token,
        ]);

        // The 2 under-limit mounts pass the limiter → they reach the controller
        // and receive the structural 1011 refusal (NOT the 1013 throttle).
        for ($i = 0; $i < 2; $i++) {
            $conn = $this->createMock(TcpConnection::class);
            $conn->method('getRemoteIp')->willReturn('198.51.100.5');
            $worker->onWebSocketConnect($conn, $request);
            $this->assertRejectedWithCloseFrame($conn, ClientMountController::CLOSE_INTERNAL_ERROR);
        }
        self::assertCount(0, $tunnel->clientConnections, 'no mount binds in this topology (M2)');

        // The 3rd mount from the same IP trips the limiter → real WS close frame
        // 1013 (L-4): the throttle code, never the controller's 1011 — proving
        // the limiter short-circuits ahead of the mount path.
        $limited = $this->createMock(TcpConnection::class);
        $limited->method('getRemoteIp')->willReturn('198.51.100.5');
        $worker->onWebSocketConnect($limited, $request);
        $this->assertRejectedWithCloseFrame($limited, ClientRelayWorker::CLOSE_TRY_AGAIN_LATER);
        self::assertCount(0, $tunnel->clientConnections, 'a rate-limited mount must not reach the controller');

        // After the window resets, a legit reconnect from the same IP passes the
        // limiter again (controller refusal 1011, not throttle 1013).
        $now += 61;
        $reconnect = $this->createMock(TcpConnection::class);
        $reconnect->method('getRemoteIp')->willReturn('198.51.100.5');
        $worker->onWebSocketConnect($reconnect, $request);
        $this->assertRejectedWithCloseFrame($reconnect, ClientMountController::CLOSE_INTERNAL_ERROR);
    }

    /**
     * The mount limiter must run BEFORE authentication. Proof: the request
     * carries NO relay token, so without the mount limiter onWebSocketConnect
     * would reject with 4401 (missing token). A tripped limiter instead closes
     * with 1013 AND the ownership re-confirmation ({@see ServerInfoHandler})
     * never runs — proving the limiter short-circuits ahead of the auth path.
     */
    public function testClientMountLimiterRunsBeforeAuth(): void
    {
        $serverId = 'server-uuid-before-auth';
        $this->setServerOwner($serverId, self::OWNER_USER_ID);

        // max=1 → the very first hit is limited.
        $limiter = new RateLimiter(60, 1);

        // The ownership re-confirmation (part of auth) must NEVER run.
        $ownerInfo = $this->createMock(ServerInfoHandler::class);
        $ownerInfo->expects($this->never())->method('getOwnerAndStatus');
        $ownerInfo->expects($this->never())->method('getServerInfo');

        $worker = new ClientRelayWorker($this->buildContainer(null, null, $ownerInfo, $limiter));
        // No Authorization header / no token — the pre-limiter path would 4401.
        $request = $this->makeUpgradeRequest('/client/' . $serverId);

        $connection = $this->createMock(TcpConnection::class);
        $connection->method('getRemoteIp')->willReturn('198.51.100.9');
        $worker->onWebSocketConnect($connection, $request);
        // 1013 (rate-limited), NOT 4401 (missing token) — proves ordering.
        // L-4: asserted via the real close frame delivered post-handshake.
        $this->assertRejectedWithCloseFrame($connection, ClientRelayWorker::CLOSE_TRY_AGAIN_LATER);
    }

    // ---- Trusted-proxy client-mount keying (mirrors SV-4.15) -------------

    /**
     * A recording {@see RateLimiterInterface} double: captures every key passed
     * to hit() and always reports not-limited, so the mount proceeds far enough
     * to record the key without needing a valid token / tunnel.
     */
    private function recordingMountLimiter(): RecordingMountLimiter
    {
        return new RecordingMountLimiter();
    }

    /**
     * Behind the shipped loopback HAProxy front the WS peer is `127.0.0.1` for
     * EVERY client and the forged leftmost X-Forwarded-For entry is
     * client-controlled. The client-mount limiter must bucket on the REAL client
     * (the appended rightmost hop) — NOT `127.0.0.1` and NOT the forged value.
     */
    public function testClientMountKeysOnTrustedClientIpBehindLoopbackProxy(): void
    {
        $limiter = $this->recordingMountLimiter();
        $worker = new ClientRelayWorker($this->buildContainer(null, null, null, $limiter));

        // Loopback proxy peer + forged leftmost XFF, real client appended rightmost.
        $conn = $this->createMock(TcpConnection::class);
        $conn->method('getRemoteIp')->willReturn('127.0.0.1');
        $request = $this->makeUpgradeRequest('/client/server-uuid-xff', [
            'X-Forwarded-For' => '198.51.100.66, 203.0.113.50',
        ]);

        $worker->onWebSocketConnect($conn, $request);

        self::assertSame(['client_mount:203.0.113.50'], $limiter->hits);
        self::assertNotContains('client_mount:127.0.0.1', $limiter->hits);
        self::assertNotContains('client_mount:198.51.100.66', $limiter->hits);
    }

    /**
     * Two DISTINCT real clients arriving through the SAME loopback proxy must
     * land in DISTINCT buckets — the availability regression (one shared loopback
     * bucket throttling everyone) is closed. Same forged leftmost hop for both,
     * so the distinct keys prove the REAL client (rightmost) drives the bucket.
     */
    public function testDistinctRealClientsBehindLoopbackProxyGetDistinctMountBuckets(): void
    {
        $limiter = $this->recordingMountLimiter();
        $worker = new ClientRelayWorker($this->buildContainer(null, null, null, $limiter));

        foreach (['203.0.113.1', '203.0.113.2'] as $client) {
            $conn = $this->createMock(TcpConnection::class);
            $conn->method('getRemoteIp')->willReturn('127.0.0.1');
            $request = $this->makeUpgradeRequest('/client/server-uuid-distinct', [
                'X-Forwarded-For' => '10.9.9.9, ' . $client,
            ]);
            $worker->onWebSocketConnect($conn, $request);
        }

        self::assertSame(
            ['client_mount:203.0.113.1', 'client_mount:203.0.113.2'],
            $limiter->hits,
        );
        self::assertCount(2, array_unique($limiter->hits));
    }

    // ---- WS connect: declared-unavailable refusal (M2, review hub__asphp) --

    /**
     * M2 (review hub__asphp): the client-mount bridge is architecturally
     * unavailable in the deployed multi-process topology. A same-process tunnel
     * CAN be conjured here (the unit harness shares one container) — and the
     * mount STILL must refuse, because the refusal is STRUCTURAL (the :8803
     * worker's process-local TunnelManager can never hold the :8802 process's
     * registry), not "this server happens to be offline". Pins the 1011
     * (no-retry) code: 1013 "Try Again Later" would invite eternal reconnect
     * loops against a surface that can never serve.
     */
    public function testMountIsRefusedEvenWithActiveTunnel(): void
    {
        $serverId = 'server-uuid-online';
        $token = 'valid-token-online';
        $this->grantToken($token, self::OWNER_USER_ID, $serverId);
        $this->setServerOwner($serverId, self::OWNER_USER_ID);

        // Bring up an ACTIVE tunnel for this server in THIS process — the exact
        // state production :8803 can never have. The mount still refuses.
        $serverWs = $this->createMock(TcpConnection::class);
        $tunnel = $this->tunnelManager->acceptServer($serverId, $serverWs);
        $tunnel->relaySessionId = 'session-123';
        $tunnel->status = Tunnel::STATUS_ACTIVE;

        $worker = new ClientRelayWorker($this->buildContainer());
        $request = $this->makeUpgradeRequest('/client/' . $serverId, [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $clientWs = $this->createMock(TcpConnection::class);
        $worker->onWebSocketConnect($clientWs, $request);

        $this->assertRejectedWithCloseFrame($clientWs, ClientMountController::CLOSE_INTERNAL_ERROR);
        // The refusal happens BEFORE any registry lookup: no client is ever
        // attached, no tunnel bookkeeping mutates.
        self::assertCount(0, $tunnel->clientConnections);
    }

    // ---- M2 refusal is structural: S42's mount plumbing is never reached ---

    /**
     * The S42 per-user throttle chain (worker → controller →
     * TunnelManager::acceptClient → RelaySessionManager::getUserThrottleBps)
     * sat BEHIND the mount bind. Since the bind is now refused structurally
     * (M2, review hub__asphp), nothing in that chain may be consulted on the
     * :8803 mount path — and a refused mount must attach no ClientConnection.
     * (The throttle mechanics themselves stay unit-pinned at their own level:
     * ClientConnectionTest / TunnelManagerTest.)
     */
    public function testMountRefusalNeverConsultsUserThrottle(): void
    {
        $serverId = 'server-uuid-throttled';
        $token = 'valid-token-throttled';
        $this->grantToken($token, self::OWNER_USER_ID, $serverId);
        $this->setServerOwner($serverId, self::OWNER_USER_ID);

        $this->sessionManager->expects($this->never())->method('getUserThrottleBps');

        $serverWs = $this->createMock(TcpConnection::class);
        $serverWs->method('send')->willReturn(true);
        $tunnel = $this->tunnelManager->acceptServer($serverId, $serverWs);
        $tunnel->relaySessionId = 'session-123';
        $tunnel->status = Tunnel::STATUS_ACTIVE;

        $worker = new ClientRelayWorker($this->buildContainer());
        $request = $this->makeUpgradeRequest('/client/' . $serverId, [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $delivered = 0;
        $clientWs = $this->createMock(TcpConnection::class);
        $clientWs->method('send')->willReturnCallback(
            static function (mixed $data) use (&$delivered): bool {
                if (!is_string($data)) {
                    Assert::fail('the relay must only send string frames to the client');
                }
                $delivered++;
                return true;
            },
        );

        $worker->onWebSocketConnect($clientWs, $request);

        $this->assertRejectedWithCloseFrame($clientWs, ClientMountController::CLOSE_INTERNAL_ERROR);
        self::assertCount(0, $tunnel->clientConnections, 'a refused mount must attach no client to the tunnel');
        self::assertSame(0, $delivered, 'no frames may flow to a refused mount');
    }

    // ---- Frame round-trip through the router ----------------------------

    /**
     * M2 flip: a refused mount must leave NOTHING that can carry frames. The
     * old test proved client DATA reached the server tunnel through the mount;
     * under the declared-unavailable law, DATA arriving on a refused (or
     * merely unmounted) connection must reach no server tunnel at all.
     */
    public function testClientFramesAfterRefusalReachNoServerTunnel(): void
    {
        $serverId = 'server-uuid-relay';
        $token = 'valid-token-relay';
        $this->grantToken($token, self::OWNER_USER_ID, $serverId);
        $this->setServerOwner($serverId, self::OWNER_USER_ID);

        $serverWs = $this->createMock(TcpConnection::class);
        $tunnel = $this->tunnelManager->acceptServer($serverId, $serverWs);
        $tunnel->relaySessionId = 'session-123';
        $tunnel->status = Tunnel::STATUS_ACTIVE;

        // Capture everything written to the server side. Under the old law
        // registerClient emitted a CLIENT_CONNECT frame first; under the
        // declared-unavailable law the server side must see NOTHING.
        $sentToServer = [];
        $serverWs->method('send')->willReturnCallback(
            function (string $data) use (&$sentToServer): bool {
                $sentToServer[] = $data;
                return true;
            },
        );

        $worker = new ClientRelayWorker($this->buildContainer());
        $request = $this->makeUpgradeRequest('/client/' . $serverId, [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $clientWs = $this->createMock(TcpConnection::class);
        $worker->onWebSocketConnect($clientWs, $request);

        // The connect itself is refused (close frame armed on the socket). Even
        // if bytes arrive before that lands, onClientMessage finds NO
        // ClientConnection bookkeeping and must write nothing server-ward.
        $encoder = new FrameEncoder();
        $dataFrame = $encoder->encode(RelayFrameType::DATA, 7, 'hello-server');

        $worker->onMessage($clientWs, $dataFrame);

        self::assertSame(
            [],
            $sentToServer,
            'a refused mount must never emit CLIENT_CONNECT or DATA toward the server tunnel',
        );
    }

    public function testRefusedMountLeavesTunnelUntouched(): void
    {
        $serverId = 'server-uuid-close';
        $token = 'valid-token-close';
        $this->grantToken($token, self::OWNER_USER_ID, $serverId);
        $this->setServerOwner($serverId, self::OWNER_USER_ID);

        $serverWs = $this->createMock(TcpConnection::class);
        $tunnel = $this->tunnelManager->acceptServer($serverId, $serverWs);
        $tunnel->relaySessionId = 'session-123';
        $tunnel->status = Tunnel::STATUS_ACTIVE;
        $serverWs->method('send')->willReturn(true);

        $worker = new ClientRelayWorker($this->buildContainer());
        $request = $this->makeUpgradeRequest('/client/' . $serverId, [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $clientWs = $this->createMock(TcpConnection::class);
        $worker->onWebSocketConnect($clientWs, $request);
        self::assertCount(0, $tunnel->clientConnections, 'a refused mount never attaches a client');

        // Closing the refused connection must neither raise nor mutate:
        // onClientClose finds no bookkeeping and no-ops.
        $worker->onClose($clientWs);

        self::assertCount(0, $tunnel->clientConnections);
    }

    // ---- start(): worker wiring -----------------------------------------

    public function testStartWiresWorkerStartAndConnectionHandlers(): void
    {
        $worker = new ClientRelayWorker($this->buildContainer(), 0);
        $wm = $worker->start();

        // onWorkerStart must be bound so the per-worker metrics collector +
        // flush/touch timers get resolved/armed when the worker boots.
        self::assertSame([$worker, 'onWorkerStart'], $wm->onWorkerStart);
        self::assertSame([$worker, 'onWebSocketConnect'], $wm->onWebSocketConnect);
        self::assertSame([$worker, 'onMessage'], $wm->onMessage);
        self::assertSame([$worker, 'onClose'], $wm->onClose);
        self::assertSame('phlix-hub-client-relay-ws', $wm->name);
    }

    // ---- S4 metrics: open / final-touch ---------------------------------

    public function testOnWebSocketConnectRecordsStreamConnectionWhenMetricsEnabled(): void
    {
        $serverId = 'server-uuid-metrics';
        $token = 'valid-token-metrics';
        $this->grantToken($token, self::OWNER_USER_ID, $serverId);
        $this->setServerOwner($serverId, self::OWNER_USER_ID);

        // Bring up an ACTIVE tunnel — under the M2 declared-unavailable law the
        // mount refuses regardless; the metrics row is opened by the WORKER flow
        // before the controller runs, which is what this case pins.
        $serverWs = $this->createMock(TcpConnection::class);
        $tunnel = $this->tunnelManager->acceptServer($serverId, $serverWs);
        $tunnel->relaySessionId = 'session-123';
        $tunnel->status = Tunnel::STATUS_ACTIVE;

        $registry = new MetricsRegistry();
        $collector = new MetricsCollector($registry, true);
        $flush = new MetricsFlushService($collector, []);

        $worker = new ClientRelayWorker($this->buildContainer($collector, $flush));
        // Resolves + stores the collector (and arms the flush/touch timers, which
        // the test bootstrap's no-op SIGALRM handler renders harmless off-loop).
        $worker->onWorkerStart();

        $request = $this->makeUpgradeRequest('/client/' . $serverId, [
            'Authorization' => 'Bearer ' . $token,
        ]);
        $clientWs = $this->createMock(TcpConnection::class);
        $clientWs->method('getRemoteIp')->willReturn('203.0.113.7');

        $worker->onWebSocketConnect($clientWs, $request);

        $rows = array_values($registry->snapshotConnections());
        self::assertCount(1, $rows);
        // Client relay connections are the media playback path → kind 'stream',
        // attributed to the owning user, no relay session, correlated by server id.
        self::assertSame('stream', $rows[0]['kind']);
        self::assertSame(self::OWNER_USER_ID, $rows[0]['user_id']);
        self::assertSame('203.0.113.7', $rows[0]['remote_ip']);
        self::assertNull($rows[0]['session_id']);
        self::assertSame($serverId, $rows[0]['media_item_id']);

        // Tracked live so the touch timer can iterate it between flushes.
        self::assertSame(1, ClientRelayWorker::getActiveConnectionCount());
    }

    public function testOnCloseLeavesFinalTouchInsteadOfDroppingTheRow(): void
    {
        $serverId = 'server-uuid-metrics-close';
        $token = 'valid-token-metrics-close';
        $this->grantToken($token, self::OWNER_USER_ID, $serverId);
        $this->setServerOwner($serverId, self::OWNER_USER_ID);

        $serverWs = $this->createMock(TcpConnection::class);
        $serverWs->method('send')->willReturn(true);
        $tunnel = $this->tunnelManager->acceptServer($serverId, $serverWs);
        $tunnel->relaySessionId = 'session-123';
        $tunnel->status = Tunnel::STATUS_ACTIVE;

        $registry = new MetricsRegistry();
        $collector = new MetricsCollector($registry, true);
        $flush = new MetricsFlushService($collector, []);

        $worker = new ClientRelayWorker($this->buildContainer($collector, $flush));
        $worker->onWorkerStart();

        $request = $this->makeUpgradeRequest('/client/' . $serverId, [
            'Authorization' => 'Bearer ' . $token,
        ]);
        $clientWs = $this->createMock(TcpConnection::class);
        $worker->onWebSocketConnect($clientWs, $request);

        // Simulate cumulative traffic on the connection, then close it.
        $clientWs->bytesRead = 4096;
        $clientWs->bytesWritten = 8192;
        $worker->onClose($clientWs);

        // onClose records a FINAL touch (updated cumulative bytes) rather than
        // closeConnection() — the row survives for the next flush to persist,
        // and the flush service TTL-prunes the now-idle row afterwards.
        $rows = array_values($registry->snapshotConnections());
        self::assertCount(1, $rows);
        self::assertSame(4096, $rows[0]['bytes_in']);
        self::assertSame(8192, $rows[0]['bytes_out']);

        // The live/id maps are cleared so the touch timer stops touching it.
        self::assertSame(0, ClientRelayWorker::getActiveConnectionCount());
    }

    public function testMetricsAreNoOpWhenCollectorUnavailable(): void
    {
        // No metrics services in the container → onWorkerStart resolves nothing
        // (guarded) and the connection hooks stay pure no-ops: the mount is
        // still refused (declared-unavailable, M2) and no registry state is
        // created.
        $serverId = 'server-uuid-no-metrics';
        $token = 'valid-token-no-metrics';
        $this->grantToken($token, self::OWNER_USER_ID, $serverId);
        $this->setServerOwner($serverId, self::OWNER_USER_ID);

        $serverWs = $this->createMock(TcpConnection::class);
        $tunnel = $this->tunnelManager->acceptServer($serverId, $serverWs);
        $tunnel->relaySessionId = 'session-123';
        $tunnel->status = Tunnel::STATUS_ACTIVE;

        $worker = new ClientRelayWorker($this->buildContainer());
        $worker->onWorkerStart();

        $request = $this->makeUpgradeRequest('/client/' . $serverId, [
            'Authorization' => 'Bearer ' . $token,
        ]);
        $clientWs = $this->createMock(TcpConnection::class);

        $worker->onWebSocketConnect($clientWs, $request);
        // Refused regardless of metrics state — the refusal is structural.
        $this->assertRejectedWithCloseFrame($clientWs, ClientMountController::CLOSE_INTERNAL_ERROR);
        self::assertCount(0, $tunnel->clientConnections);

        // Closing must not raise even though nothing was recorded.
        $worker->onClose($clientWs);
    }

    // ---- Helpers ---------------------------------------------------------

    /**
     * L-4: assert a pre-upgrade rejection delivered a REAL WS close frame.
     *
     * The mocked TcpConnection has no completed handshake (`context` is null),
     * so {@see WebSocketCloseFrame::reject()} must have armed
     * `onWebSocketConnected` instead of closing early. Simulate Workerman
     * finishing the `101` by invoking that callback, and assert the connection
     * is then closed with the encoded close frame (raw).
     */
    private function assertRejectedWithCloseFrame(TcpConnection&MockObject $connection, int $code): void
    {
        $armed = $connection->onWebSocketConnected ?? null;
        self::assertInstanceOf(
            \Closure::class,
            $armed,
            'pre-upgrade rejection must arm onWebSocketConnected for a real close frame',
        );

        $connection->expects($this->once())
            ->method('close')
            ->with(WebSocketCloseFrame::bytes($code), true);

        $armed($connection);
    }

    /**
     * Mark a plaintext token as ACTIVE and bound to (userId, serverId) in the
     * fake token service.
     */
    private function grantToken(string $token, string $userId, string $serverId): void
    {
        $this->activeTokens[hash('sha256', $token)] = [
            'user_id' => $userId,
            'server_id' => $serverId,
        ];
    }

    /**
     * Record that $serverId is owned by $userId for the fake ServerInfoHandler.
     */
    private function setServerOwner(string $serverId, string $userId): void
    {
        $this->serverOwners[$serverId] = $userId;
    }

    /**
     * Build a real {@see ClientRelayTokenService} over a mock Connection whose
     * `query()` resolves the active-token lookup against {@see $activeTokens}.
     *
     * The service's validate() SQL filters by `token_hash` (a colon-free named
     * param) and expects a `[{user_id, server_id}]` row shape.
     */
    private function buildTokenService(): ClientRelayTokenService
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []): array {
                if (!str_contains($sql, 'SELECT')) {
                    return [];
                }
                $hash = $params['token_hash'] ?? null;
                if (is_string($hash) && isset($this->activeTokens[$hash])) {
                    return [$this->activeTokens[$hash]];
                }
                return [];
            },
        );

        return new ClientRelayTokenService($db);
    }

    /**
     * Build a {@see ServerInfoHandler} test double whose getOwnerAndStatus()
     * resolves ownership from {@see $serverOwners}.
     */
    private function buildServerInfoHandler(): ServerInfoHandler
    {
        $handler = $this->createMock(ServerInfoHandler::class);
        $handler->method('getOwnerAndStatus')->willReturnCallback(
            function (string $serverId): ?array {
                if (!isset($this->serverOwners[$serverId])) {
                    return null;
                }

                return [
                    'userId' => $this->serverOwners[$serverId],
                    'status' => 'online',
                    'relayActive' => true,
                ];
            },
        );

        return $handler;
    }

    /**
     * Build a minimal PSR-11 container exposing the relay services the
     * worker and controller resolve.
     *
     * When $metrics / $flush are supplied the container also resolves
     * {@see MetricsCollector} / {@see MetricsFlushService}, so the worker's
     * {@see ClientRelayWorker::onWorkerStart()} metrics wiring can be exercised.
     * Left null (the default) they are unknown services — matching production
     * when metrics is disabled.
     *
     * @param MetricsCollector|null    $metrics Collector to expose (or null).
     * @param MetricsFlushService|null $flush   Flush service to expose (or null).
     */
    private function buildContainer(
        ?MetricsCollector $metrics = null,
        ?MetricsFlushService $flush = null,
        ?ServerInfoHandler $serverInfo = null,
        ?RateLimiterInterface $mountLimiter = null,
    ): ContainerInterface {
        $tokenService = $this->buildTokenService();
        $serverInfo ??= $this->buildServerInfoHandler();
        $tunnelManager = $this->tunnelManager;
        $controllerFactory = fn (): ClientMountController => $this->controller;
        // Default client-mount limiter: never limits (matches the prior test
        // harness so the existing mount cases are unaffected). The rate-limit
        // cases below inject a real, clocked RateLimiter instead.
        $mountLimiter ??= new class implements RateLimiterInterface {
            public function hit(string $key): RateLimitState
            {
                return new RateLimitState(1, 4, time() + 900, false, 5);
            }

            public function reset(string $key): void
            {
            }

            public function peek(string $key): RateLimitState
            {
                return new RateLimitState(0, 5, 0, false, 5);
            }
        };

        return new class (
            $tokenService,
            $serverInfo,
            $tunnelManager,
            $controllerFactory,
            $metrics,
            $flush,
            $mountLimiter,
        ) implements ContainerInterface {
            /** @param callable():ClientMountController $controllerFactory */
            public function __construct(
                private readonly ClientRelayTokenService $tokenService,
                private readonly ServerInfoHandler $serverInfo,
                private readonly TunnelManager $tunnelManager,
                private $controllerFactory,
                private readonly ?MetricsCollector $metrics,
                private readonly ?MetricsFlushService $flush,
                private readonly RateLimiterInterface $mountLimiter,
            ) {
            }

            public function get(string $id): mixed
            {
                return match ($id) {
                    ClientRelayTokenService::class => $this->tokenService,
                    ServerInfoHandler::class => $this->serverInfo,
                    TunnelManager::class, TunnelManagerInterface::class => $this->tunnelManager,
                    ClientMountController::class => ($this->controllerFactory)(),
                    MetricsCollector::class => $this->metrics
                        ?? throw new \RuntimeException("Unknown service: {$id}"),
                    MetricsFlushService::class => $this->flush
                        ?? throw new \RuntimeException("Unknown service: {$id}"),
                    // HB-4.6f: the WS mount limiter is resolved by its per-surface
                    // container id (rate_limiter.client_mount).
                    RateLimitProfiles::CLIENT_MOUNT => $this->mountLimiter,
                    default => throw new \RuntimeException("Unknown service: {$id}"),
                };
            }

            public function has(string $id): bool
            {
                $known = [
                    ClientRelayTokenService::class,
                    ServerInfoHandler::class,
                    TunnelManager::class,
                    TunnelManagerInterface::class,
                    ClientMountController::class,
                    RateLimitProfiles::CLIENT_MOUNT,
                ];
                if ($this->metrics !== null) {
                    $known[] = MetricsCollector::class;
                }
                if ($this->flush !== null) {
                    $known[] = MetricsFlushService::class;
                }

                return in_array($id, $known, true);
            }
        };
    }

    /**
     * Build a real Workerman WS-upgrade Request from a raw HTTP buffer.
     *
     * @param string                $path    Request path (with optional query).
     * @param array<string, string> $headers Extra headers to inject.
     */
    private function makeUpgradeRequest(string $path, array $headers = []): WorkermanRequest
    {
        $allHeaders = array_merge([
            'Host' => 'hub.example.com',
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Version' => '13',
            'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ==',
        ], $headers);

        $lines = ["GET {$path} HTTP/1.1"];
        foreach ($allHeaders as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        $raw = implode("\r\n", $lines) . "\r\n\r\n";

        return new WorkermanRequest($raw);
    }
}
