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
use Phlix\Hub\Relay\ClientConnection;
use Phlix\Hub\Relay\ClientRelayWorker;
use Phlix\Hub\Relay\FrameDecoder;
use Phlix\Hub\Relay\FrameEncoder;
use Phlix\Hub\Relay\Tunnel;
use Phlix\Hub\Relay\TunnelManager;
use Phlix\Hub\Relay\TunnelManagerInterface;
use Phlix\Hub\Stats\Metrics\MetricsCollector;
use Phlix\Hub\Stats\Metrics\MetricsFlushService;
use Phlix\Hub\Stats\Metrics\MetricsRegistry;
use Phlix\Shared\Hub\ServerInfoDto;
use Phlix\Shared\Relay\RelayFrame;
use Phlix\Shared\Relay\RelayFrameType;
use Phlix\Shared\Relay\RelayWireCodecInterface;
use Phlix\Hub\Tests\Support\LoggerFactoryIsolation;
use Phlix\Hub\Tests\Support\WorkermanTimerRuntimeControl;
use PHPUnit\Framework\TestCase;
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
    private RelaySessionManager $sessionManager;
    private RelayWireCodecInterface $codec;
    private StructuredLogger $logger;
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
        $this->controller = new ClientMountController($this->buildContainer());
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
        $connection->expects($this->once())
            ->method('close')
            ->with((string) ClientRelayWorker::CLOSE_UNAUTHORIZED, true);

        $worker->onWebSocketConnect($connection, $request);
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
        $connection->expects($this->once())
            ->method('close')
            ->with((string) ClientRelayWorker::CLOSE_UNAUTHORIZED, true);

        $worker->onWebSocketConnect($connection, $request);
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
        $connection->expects($this->once())
            ->method('close')
            ->with((string) ClientRelayWorker::CLOSE_UNAUTHORIZED, true);

        $worker->onWebSocketConnect($connection, $request);
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

        // No tunnel registered for this server_id → controller closes the conn.
        $connection = $this->createMock(TcpConnection::class);
        $connection->expects($this->once())
            ->method('close')
            ->with('server_offline');

        $worker->onWebSocketConnect($connection, $request);
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

        // Bring up an ACTIVE tunnel so under-limit mounts bind successfully.
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

        // The 2 under-limit mounts from one IP are NOT closed → they bind.
        for ($i = 0; $i < 2; $i++) {
            $conn = $this->createMock(TcpConnection::class);
            $conn->method('getRemoteIp')->willReturn('198.51.100.5');
            $conn->expects($this->never())->method('close');
            $worker->onWebSocketConnect($conn, $request);
        }
        self::assertCount(2, $tunnel->clientConnections, 'the two under-limit mounts must bind');

        // The 3rd mount from the same IP trips the limiter → WS close 1013, and
        // NO tunnel bind occurs (the mount is rejected before binding).
        $limited = $this->createMock(TcpConnection::class);
        $limited->method('getRemoteIp')->willReturn('198.51.100.5');
        $limited->expects($this->once())
            ->method('close')
            ->with((string) ClientRelayWorker::CLOSE_TRY_AGAIN_LATER, true);
        $worker->onWebSocketConnect($limited, $request);
        self::assertCount(2, $tunnel->clientConnections, 'a rate-limited mount must not bind');

        // After the window resets, a legit reconnect from the same IP passes.
        $now += 61;
        $reconnect = $this->createMock(TcpConnection::class);
        $reconnect->method('getRemoteIp')->willReturn('198.51.100.5');
        $reconnect->expects($this->never())->method('close');
        $worker->onWebSocketConnect($reconnect, $request);
        self::assertCount(3, $tunnel->clientConnections, 'a post-window reconnect must bind again');
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
        // 1013 (rate-limited), NOT 4401 (missing token) — proves ordering.
        $connection->expects($this->once())
            ->method('close')
            ->with((string) ClientRelayWorker::CLOSE_TRY_AGAIN_LATER, true);

        $worker->onWebSocketConnect($connection, $request);
    }

    // ---- Trusted-proxy client-mount keying (mirrors SV-4.15) -------------

    /**
     * A recording {@see RateLimiterInterface} double: captures every key passed
     * to hit() and always reports not-limited, so the mount proceeds far enough
     * to record the key without needing a valid token / tunnel.
     */
    private function recordingMountLimiter(): RateLimiterInterface
    {
        return new class implements RateLimiterInterface {
            /** @var list<string> */
            public array $hits = [];

            public function hit(string $key): RateLimitState
            {
                $this->hits[] = $key;

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

    // ---- WS connect: success / binding ----------------------------------

    public function testOnWebSocketConnectBindsToActiveTunnel(): void
    {
        $serverId = 'server-uuid-online';
        $token = 'valid-token-online';
        $this->grantToken($token, self::OWNER_USER_ID, $serverId);
        $this->setServerOwner($serverId, self::OWNER_USER_ID);

        // Bring up an ACTIVE tunnel for this server.
        $serverWs = $this->createMock(TcpConnection::class);
        $tunnel = $this->tunnelManager->acceptServer($serverId, $serverWs);
        $tunnel->relaySessionId = 'session-123';
        $tunnel->status = Tunnel::STATUS_ACTIVE;

        $worker = new ClientRelayWorker($this->buildContainer());
        $request = $this->makeUpgradeRequest('/client/' . $serverId, [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $clientWs = $this->createMock(TcpConnection::class);
        // The client connection should NOT be closed on a successful bind.
        $clientWs->expects($this->never())->method('close');

        $worker->onWebSocketConnect($clientWs, $request);

        // A client connection is now attached to the tunnel.
        self::assertCount(1, $tunnel->clientConnections);
    }

    // ---- S42: per-user throttle attached AT MOUNT -------------------------

    /**
     * The authenticated user's `throttle_bps` must reach the mounted
     * {@see ClientConnection} — the whole of S42's enforcement hangs off this one
     * plumbing chain, and nothing pinned it.
     *
     * Chain under test, end to end and all production code:
     * {@see ClientRelayWorker::onWebSocketConnect()} resolves the owning user from
     * the relay token, forwards it to
     * {@see ClientMountController::onWebSocketConnect()}, which forwards it to
     * {@see \Phlix\Hub\Relay\TunnelManager::acceptClient()}, which reads
     * {@see RelaySessionManager::getUserThrottleBps()} and constructs the
     * connection's token bucket.
     *
     * 🔴 Measured on master (2026-08-03): deleting the `$userId` argument from the
     * worker's `$controller->onWebSocketConnect(...)` call — a one-token edit that
     * mounts EVERY native client Unlimited and makes the entire S42 feature inert
     * in production — left the whole suite green (2532 tests, 19894 assertions,
     * exit 0, byte-identical to baseline). Same for passing `null` instead of
     * `$userId` in the controller's `acceptClient()` call. This is the same
     * "one argument from inert" shape the S42/S43 audit found on the HTTP path at
     * `ServerProxyController.php:1072`.
     *
     * The lookup is asserted to happen exactly ONCE and with the authenticated
     * user id, and the attached rate is asserted to be the value that user (and
     * only that user) resolves to, so neither a dropped argument nor a hardcoded
     * default can satisfy this test.
     */
    public function testOnWebSocketConnectAttachesTheOwningUsersThrottleToTheMountedConnection(): void
    {
        $serverId = 'server-uuid-throttled';
        $token = 'valid-token-throttled';
        $this->grantToken($token, self::OWNER_USER_ID, $serverId);
        $this->setServerOwner($serverId, self::OWNER_USER_ID);

        // Only the authenticated owner resolves to 5 Mbps; anybody else (and the
        // no-user case a dropped argument produces) resolves to Unlimited.
        $this->sessionManager->expects($this->once())
            ->method('getUserThrottleBps')
            ->with(self::OWNER_USER_ID)
            ->willReturn(5_000_000);

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
                $delivered++;
                unset($data);
                return true;
            },
        );

        $worker->onWebSocketConnect($clientWs, $request);

        $client = null;
        foreach ($tunnel->clientConnections as $mounted) {
            $client = $mounted;
        }

        self::assertInstanceOf(ClientConnection::class, $client);
        self::assertSame(
            5_000_000,
            $client->throttleBps,
            'the mounted connection must carry the authenticated user’s throttle_bps',
        );
        self::assertTrue($client->isThrottled());
        self::assertNotNull($client->throttleBucket, 'a capped user must mount WITH a token bucket');
        // bits/sec -> bytes/sec conversion happens once, at construction.
        self::assertSame(625_000.0, $client->throttleBucket->ratePerSecond());
        self::assertSame(625_000.0, $client->throttleBucket->capacity());

        // …and the attached bucket is actually CONSULTED by the send path: with
        // the bucket in debt, a server->client DATA frame must not reach the
        // socket (it is queued for the drain timer instead).
        $client->throttleBucket->spend(1_000_000.0);
        self::assertSame(0, $delivered, 'no client frame should have been delivered before the DATA send');

        $tunnel->sendToClient(
            $client->channelId,
            new RelayFrame(RelayFrameType::DATA, $client->channelId, 'payload'),
        );

        self::assertSame(
            0,
            $delivered,
            'a mounted, throttled connection with an empty bucket must queue rather than deliver',
        );
    }

    /**
     * The negative half of the pair above: a user configured Unlimited (`0`)
     * mounts with NO bucket and takes the unthrottled fast path.
     *
     * Together with the 5 Mbps case this proves the attached cap TRACKS the
     * per-user lookup rather than being a constant, and it independently pins the
     * lookup call itself (a dropped `$userId` argument never queries at all, so
     * `expects($this->once())` fails).
     */
    public function testOnWebSocketConnectMountsAnUnlimitedUserWithNoBucket(): void
    {
        $serverId = 'server-uuid-unlimited';
        $token = 'valid-token-unlimited';
        $this->grantToken($token, self::OWNER_USER_ID, $serverId);
        $this->setServerOwner($serverId, self::OWNER_USER_ID);

        $this->sessionManager->expects($this->once())
            ->method('getUserThrottleBps')
            ->with(self::OWNER_USER_ID)
            ->willReturn(0);

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
                $delivered++;
                unset($data);
                return true;
            },
        );

        $worker->onWebSocketConnect($clientWs, $request);

        $client = null;
        foreach ($tunnel->clientConnections as $mounted) {
            $client = $mounted;
        }

        self::assertInstanceOf(ClientConnection::class, $client);
        self::assertSame(0, $client->throttleBps);
        self::assertFalse($client->isThrottled());
        self::assertNull($client->throttleBucket, 'Unlimited must build no bucket at all');

        // Unlimited delivers immediately — no queue, no timer.
        $tunnel->sendToClient(
            $client->channelId,
            new RelayFrame(RelayFrameType::DATA, $client->channelId, 'payload'),
        );

        self::assertSame(1, $delivered, 'an Unlimited mount must deliver on the fast path');
        self::assertNull($client->throttleDrainTimerId);
    }

    // ---- Frame round-trip through the router ----------------------------

    public function testClientDataFrameIsRelayedToServerTunnel(): void
    {
        $serverId = 'server-uuid-relay';
        $token = 'valid-token-relay';
        $this->grantToken($token, self::OWNER_USER_ID, $serverId);
        $this->setServerOwner($serverId, self::OWNER_USER_ID);

        $serverWs = $this->createMock(TcpConnection::class);
        $tunnel = $this->tunnelManager->acceptServer($serverId, $serverWs);
        $tunnel->relaySessionId = 'session-123';
        $tunnel->status = Tunnel::STATUS_ACTIVE;

        // Capture everything written to the server side. registerClient emits
        // a CLIENT_CONNECT frame first; the DATA frame we send should follow.
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

        // Client sends a DATA frame; the worker routes it to onClientMessage,
        // which forwards it through the tunnel to the server.
        $encoder = new FrameEncoder();
        $dataFrame = $encoder->encode(RelayFrameType::DATA, 7, 'hello-server');

        $worker->onMessage($clientWs, $dataFrame);

        // Decode each server-bound payload and confirm a DATA frame with our
        // payload reached the server.
        $sawData = false;
        foreach ($sentToServer as $bytes) {
            $decoded = (new FrameDecoder())->decode($bytes);
            if ($decoded instanceof RelayFrame && $decoded->type === RelayFrameType::DATA) {
                self::assertSame('hello-server', $decoded->payload);
                $sawData = true;
            }
        }

        self::assertTrue($sawData, 'Expected a DATA frame to be relayed to the server tunnel');
    }

    public function testOnCloseDetachesClientAndNotifiesServer(): void
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
        self::assertCount(1, $tunnel->clientConnections);

        // Client disconnects — worker dispatches to onClientClose, which
        // detaches the client from the tunnel (sending CLIENT_DISCONNECT).
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

        // Bring up an ACTIVE tunnel so the mount binds (not server_offline).
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
        // (guarded) and the connection hooks stay pure no-ops: the mount still
        // binds and no registry state is created.
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
        $clientWs->expects($this->never())->method('close');

        $worker->onWebSocketConnect($clientWs, $request);
        self::assertCount(1, $tunnel->clientConnections);

        // Closing must not raise even though nothing was recorded.
        $worker->onClose($clientWs);
        self::assertCount(0, $tunnel->clientConnections);
    }

    // ---- Helpers ---------------------------------------------------------

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
