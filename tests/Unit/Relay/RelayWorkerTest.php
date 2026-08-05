<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Common\RateLimit\RateLimiter;
use Phlix\Hub\Common\RateLimit\RateLimiterInterface;
use Phlix\Hub\Common\RateLimit\RateLimitProfiles;
use Phlix\Hub\Hub\EnrollmentJwtService;
use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\Relay\ClientConnection;
use Phlix\Hub\Relay\FrameDecoder;
use Phlix\Hub\Relay\RelayWorker;
use Phlix\Hub\Relay\Tunnel;
use Phlix\Hub\Relay\TunnelManager;
use Phlix\Hub\Relay\TunnelManagerInterface;
use Phlix\Shared\Relay\RelayFrameType;
use Phlix\Shared\Relay\RelayWireCodecInterface;
use Phlix\Hub\Tests\Support\LoggerFactoryIsolation;
use Phlix\Hub\Tests\Support\WorkermanTimerRuntimeControl;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request as WorkermanRequest;
use Workerman\Worker;

use function chr;
use function in_array;
use function json_decode;
use function json_encode;
use function pack;
use function strlen;

/**
 * Unit tests for {@see RelayWorker} — the server-facing relay tunnel path.
 *
 * These tests pin two things that were previously unverified:
 *
 *   1. {@see RelayWorker::start()} keeps the Workerman `Websocket` application
 *      protocol bound to the worker. Nulling the protocol would disable the
 *      HTTP upgrade handshake and frame deframing (see vendor
 *      {@see \Workerman\Connection\TcpConnection::baseRead()} which only calls
 *      `protocol::input()`/`decode()` when `$this->protocol !== null`).
 *
 *   2. The worker consumes the EXACT wire bytes the media server emits: the
 *      JSON HELLO text, then binary relay frames in the shared layout
 *      `[4B seq][1B type][2B len][payload]`. This is a cross-codec conformance
 *      assertion — the server-side bytes are reconstructed independently here
 *      (not via the hub's own encoder) and fed through onMessage → Tunnel.
 *
 * @package Phlix\Hub\Tests\Unit\Relay
 *
 * @covers \Phlix\Hub\Relay\RelayWorker
 */
final class RelayWorkerTest extends TestCase
{
    // `start()` builds a real Workerman `Worker`, which latches itself into the
    // process-global `Worker::$workers` and is never cleared; `setUp()` points the
    // static LoggerFactory at a temp config it later deletes. Both traits snapshot
    // before setUp() and restore after tearDown(), so neither escapes this class.
    use WorkermanTimerRuntimeControl;
    use LoggerFactoryIsolation;

    private RelaySessionManager $sessionManager;
    private RelayWireCodecInterface $codec;
    private StructuredLogger $logger;
    private TunnelManager $tunnelManager;
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        // The relay-connect rate-limit path (HB-4.6e) logs a warning through the
        // static LoggerFactory when it closes an over-limit handshake. Point it at
        // a memory-stream config so tests neither write real log files nor fail on
        // an uninitialised factory. Reset in tearDown().
        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-relay-worker-test-' . uniqid();
        mkdir($this->tmpDir, 0700, true);
        $loggerConfig = $this->tmpDir . '/logger.php';
        file_put_contents(
            $loggerConfig,
            "<?php return ['default' => 'mem', 'handlers' => ['mem' => "
            . "['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug']]];",
        );
        LoggerFactory::reset();
        LoggerFactory::init($loggerConfig);

        // RelayWorker resolves nothing through LoggerFactory directly, but the
        // Tunnel it creates does not either (it takes an injected logger).
        $this->logger = $this->createMock(StructuredLogger::class);
        $this->sessionManager = $this->createMock(RelaySessionManager::class);
        $this->sessionManager->method('registerServer')->willReturn('session-123');

        $this->codec = new FrameDecoder();
        $this->tunnelManager = new TunnelManager($this->sessionManager, $this->codec, $this->logger);

        RelayWorker::reset();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        RelayWorker::reset();
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

    // ---- TASK 1: protocol must stay bound ---------------------------------

    public function testStartDoesNotNullWebsocketProtocol(): void
    {
        $worker = (new RelayWorker($this->buildContainer(), 0))->start();

        // Workerman resolves the application protocol class lazily in
        // parseSocketAddress() (called from listen() during runAll()), so
        // $worker->protocol is still null right after start(). The defect we
        // guard against is start() explicitly assigning `protocol = null` AND
        // (historically) relying on raw onConnect — both of which fight the WS
        // handshake/deframing. Resolve the protocol the same way Workerman does
        // at listen time and assert it lands on the Websocket class.
        $resolve = new \ReflectionMethod(Worker::class, 'parseSocketAddress');
        $resolve->invoke($worker);

        self::assertSame(
            'Workerman\\Protocols\\Websocket',
            $worker->protocol,
            'RelayWorker must resolve to the Websocket protocol so the WS handshake + deframing run',
        );
    }

    public function testStartWiresWebSocketConnectAndMessageHandlers(): void
    {
        $relay = new RelayWorker($this->buildContainer(), 0);
        $worker = $relay->start();

        self::assertSame([$relay, 'onWebSocketConnect'], $worker->onWebSocketConnect);
        self::assertSame([$relay, 'onMessage'], $worker->onMessage);
        self::assertSame([$relay, 'onClose'], $worker->onClose);
        self::assertSame('phlix-hub-relay-ws', $worker->name);
    }

    // ---- HELLO handshake (server's exact text bytes) ----------------------

    public function testFirstMessageHelloCreatesTunnelAndAcksWithSharedLayout(): void
    {
        $relay = new RelayWorker($this->buildContainer(), 0);

        // Exactly the bytes the server's RelayMessageFramer::encodeHello emits.
        $helloJson = (string) json_encode([
            'type' => 'hello',
            'enrollment_jwt' => 'jwt.value.here',
            'server_id' => 'server-uuid-aaa',
        ], JSON_THROW_ON_ERROR);

        $serverWs = $this->createMock(TcpConnection::class);

        $ackBytes = null;
        $serverWs->method('send')->willReturnCallback(
            function (string $data) use (&$ackBytes): bool {
                $ackBytes = $data;
                return true;
            },
        );

        $relay->onMessage($serverWs, $helloJson);

        // A tunnel now exists for the server and is active.
        $tunnel = $this->tunnelManager->getTunnelForServer('server-uuid-aaa');
        self::assertInstanceOf(Tunnel::class, $tunnel);
        self::assertSame(Tunnel::STATUS_ACTIVE, $tunnel->status);
        self::assertSame(1, RelayWorker::getActiveConnectionCount());

        // The HELLO_ACK the server will parse is JSON text (not a binary frame).
        self::assertIsString($ackBytes);
        /** @var array<string, mixed> $ack */
        $ack = json_decode($ackBytes, true, 4, JSON_THROW_ON_ERROR);
        self::assertSame('hello_ack', $ack['type'] ?? null);
        self::assertArrayHasKey('relay_session_id', $ack);
        self::assertArrayHasKey('tunnel_id', $ack);
    }

    public function testMalformedHelloClosesConnection(): void
    {
        $relay = new RelayWorker($this->buildContainer(), 0);

        $serverWs = $this->createMock(TcpConnection::class);
        $serverWs->expects($this->once())->method('close')->with('invalid_hello');

        $relay->onMessage($serverWs, "\x00\x01not-json");

        self::assertSame(0, RelayWorker::getActiveConnectionCount());
    }

    public function testHelloMissingServerIdClosesConnection(): void
    {
        $relay = new RelayWorker($this->buildContainer(), 0);

        $serverWs = $this->createMock(TcpConnection::class);
        $serverWs->expects($this->once())->method('close')->with('missing_server_id');

        $relay->onMessage($serverWs, (string) json_encode([
            'type' => 'hello',
            'enrollment_jwt' => 'jwt',
        ], JSON_THROW_ON_ERROR));
    }

    // ---- HB-2.2: tunnel-displacement DoS regression guard -----------------

    /**
     * REGRESSION GUARD for the HB-2.2 tunnel-displacement DoS (finding H-H1).
     *
     * An unauthenticated/invalid HELLO carrying a KNOWN server_id must NOT evict
     * the live tunnel for that server. This drives the REAL orchestration
     * (RelayWorker::onMessage → handleHello → Tunnel::onServerMessage →
     * abortPendingConnection) through a TunnelManager wired with a jwtService
     * that REJECTS — mirroring production DI (HubServicesProvider). The
     * setUp() manager has NO jwtService (validateHelloJwt returns true blindly),
     * which is precisely the blind spot that hid this live DoS; here we plug it.
     *
     * Pre-fix this fails: handleHello called finalizeServerConnection()
     * unconditionally, closing the incumbent even on a rejected HELLO.
     */
    public function testInvalidHelloDoesNotDisplaceLiveIncumbent(): void
    {
        $jwtService = $this->createMock(EnrollmentJwtService::class);
        $jwtService->method('validateEnrollmentJwt')->willReturn(null); // reject all
        $tunnelManager = new TunnelManager($this->sessionManager, $this->codec, $this->logger, $jwtService);

        $relay = new RelayWorker($this->buildContainerFor($tunnelManager), 0);

        // A legitimate server holds a live, ACTIVE tunnel with a connected client.
        $incumbentWs = $this->createMock(TcpConnection::class);
        $incumbentWs->method('send')->willReturn(true);
        // The incumbent's connection must NEVER be closed by the attack.
        $incumbentWs->expects($this->never())->method('close');

        $incumbent = $tunnelManager->acceptServer('victim-server', $incumbentWs);
        $incumbent->relaySessionId = 'sess-incumbent';
        $incumbent->status = Tunnel::STATUS_ACTIVE;

        $clientWs = $this->createMock(TcpConnection::class);
        $clientWs->method('send')->willReturn(true);
        // The incumbent's client must NEVER be disconnected by the attack.
        $clientWs->expects($this->never())->method('close');
        $client = new ClientConnection($clientWs, 'victim-server', 'client-1', $this->logger, 'cs1');
        $incumbent->registerClient($client);

        // Attacker connects to :8802 and HELLOs with the KNOWN server_id but an
        // invalid enrollment JWT.
        $attackerWs = $this->createMock(TcpConnection::class);
        $attackerWs->method('send')->willReturn(true);
        $attackerHello = (string) json_encode([
            'type' => 'hello',
            'enrollment_jwt' => 'in.valid.jwt',
            'server_id' => 'victim-server',
        ], JSON_THROW_ON_ERROR);

        $relay->onMessage($attackerWs, $attackerHello);

        // The incumbent survives: still ACTIVE, still routable, client attached.
        self::assertSame(
            Tunnel::STATUS_ACTIVE,
            $incumbent->status,
            'incumbent must survive an invalid HELLO (not displaced)',
        );
        self::assertSame(
            $incumbent,
            $tunnelManager->getTunnelForServer('victim-server'),
            'incumbent must remain the routable tunnel',
        );
        self::assertCount(
            1,
            $incumbent->clientConnections,
            'incumbent client must not be disconnected by the attack',
        );
    }

    // ---- Binary frames: server's EXACT wire bytes -------------------------

    public function testServerBinaryHeartbeatFrameTouchesTunnel(): void
    {
        $relay = new RelayWorker($this->buildContainer(), 0);
        $serverWs = $this->createMock(TcpConnection::class);
        $serverWs->method('send')->willReturn(true);

        // HELLO first to bring the tunnel ACTIVE.
        $relay->onMessage($serverWs, $this->encodeServerHello('server-bin'));
        $tunnel = $this->tunnelManager->getTunnelForServer('server-bin');
        self::assertInstanceOf(Tunnel::class, $tunnel);
        $tunnel->lastFrameAt = 0;

        // The server emits a HEARTBEAT frame with the shared byte layout.
        $heartbeat = $this->encodeServerFrame(RelayFrameType::HEARTBEAT, 1, '');
        $relay->onMessage($serverWs, $heartbeat);

        // The tunnel consumed it (lastFrameAt was touched away from 0).
        self::assertGreaterThan(0, $tunnel->lastFrameAt);
    }

    public function testServerBinaryDataFrameRoutesToOwningChannel(): void
    {
        $relay = new RelayWorker($this->buildContainer(), 0);
        $serverWs = $this->createMock(TcpConnection::class);
        $serverWs->method('send')->willReturn(true);

        $relay->onMessage($serverWs, $this->encodeServerHello('server-data'));
        $tunnel = $this->tunnelManager->getTunnelForServer('server-data');
        self::assertInstanceOf(Tunnel::class, $tunnel);

        // Register a real ClientConnection (gets channel id 1) whose underlying
        // WS captures the raw bytes the tunnel routes to it (sendToClient →
        // ClientConnection::sendRaw → clientWs->send).
        $received = '';
        $clientWs = $this->createMock(TcpConnection::class);
        $clientWs->method('send')->willReturnCallback(
            function (mixed $data) use (&$received): bool {
                $received .= (string) $data;
                return true;
            },
        );
        $client = new ClientConnection(
            $clientWs,
            'server-data',
            'client-1',
            $this->logger,
            'session-1',
        );
        $tunnel->registerClient($client);
        self::assertSame(1, $client->channelId);

        // The server emits a DATA frame on channel 1 (the client's channel).
        $payload = "HTTP/1.1 200 OK\r\nContent-Type: application/vnd.apple.mpegurl\r\n\r\n#EXTM3U";
        $relay->onMessage($serverWs, $this->encodeServerFrame(RelayFrameType::DATA, $client->channelId, $payload));

        // The tunnel re-encoded and routed the DATA frame to channel 1. Decode
        // what the client received and confirm the payload + channel survived.
        $decoded = (new FrameDecoder())->decode($received);
        self::assertNotNull($decoded);
        self::assertSame(RelayFrameType::DATA, $decoded->type);
        self::assertSame($payload, $decoded->payload);
        self::assertSame(1, $decoded->channelId());
    }

    public function testServerBinaryDataFrameForUnknownChannelIsDropped(): void
    {
        $relay = new RelayWorker($this->buildContainer(), 0);
        $serverWs = $this->createMock(TcpConnection::class);
        $serverWs->method('send')->willReturn(true);

        $relay->onMessage($serverWs, $this->encodeServerHello('server-drop'));
        $tunnel = $this->tunnelManager->getTunnelForServer('server-drop');
        self::assertInstanceOf(Tunnel::class, $tunnel);

        // Register a client on channel 1.
        $clientWs = $this->createMock(TcpConnection::class);
        $clientWs->expects($this->never())->method('send');
        $client = new ClientConnection($clientWs, 'server-drop', 'client-1', $this->logger, 'session-1');
        $tunnel->registerClient($client);

        // The server emits DATA on a channel that does not exist (42) — dropped.
        $relay->onMessage($serverWs, $this->encodeServerFrame(RelayFrameType::DATA, 42, 'orphan-bytes'));

        // No bytes were delivered to any client.
        self::assertSame(0, $tunnel->getBytesIn());
    }

    public function testTwoClientsOnDistinctChannelsDoNotCrossTalk(): void
    {
        $relay = new RelayWorker($this->buildContainer(), 0);
        $serverWs = $this->createMock(TcpConnection::class);
        $serverWs->method('send')->willReturn(true);

        $relay->onMessage($serverWs, $this->encodeServerHello('server-mux'));
        $tunnel = $this->tunnelManager->getTunnelForServer('server-mux');
        self::assertInstanceOf(Tunnel::class, $tunnel);

        // Two clients register and get channels 1 and 2.
        $recv1 = '';
        $clientWs1 = $this->createMock(TcpConnection::class);
        $clientWs1->method('send')->willReturnCallback(function (mixed $d) use (&$recv1): bool {
            $recv1 .= (string) $d;
            return true;
        });
        $recv2 = '';
        $clientWs2 = $this->createMock(TcpConnection::class);
        $clientWs2->method('send')->willReturnCallback(function (mixed $d) use (&$recv2): bool {
            $recv2 .= (string) $d;
            return true;
        });

        $client1 = new ClientConnection($clientWs1, 'server-mux', 'client-1', $this->logger, 's1');
        $client2 = new ClientConnection($clientWs2, 'server-mux', 'client-2', $this->logger, 's2');
        $tunnel->registerClient($client1);
        $tunnel->registerClient($client2);
        self::assertSame(1, $client1->channelId);
        self::assertSame(2, $client2->channelId);

        // Server DATA for channel 1 reaches only client 1; channel 2 only client 2.
        $relay->onMessage($serverWs, $this->encodeServerFrame(RelayFrameType::DATA, 1, 'to-one'));
        $relay->onMessage($serverWs, $this->encodeServerFrame(RelayFrameType::DATA, 2, 'to-two'));

        $d1 = (new FrameDecoder())->decode($recv1);
        $d2 = (new FrameDecoder())->decode($recv2);
        self::assertNotNull($d1);
        self::assertNotNull($d2);
        self::assertSame('to-one', $d1->payload);
        self::assertSame(1, $d1->channelId());
        self::assertSame('to-two', $d2->payload);
        self::assertSame(2, $d2->channelId());
    }

    // ---- Close lifecycle --------------------------------------------------

    public function testOnCloseTearsDownTunnelMapping(): void
    {
        $relay = new RelayWorker($this->buildContainer(), 0);
        $serverWs = $this->createMock(TcpConnection::class);
        $serverWs->method('send')->willReturn(true);

        $relay->onMessage($serverWs, $this->encodeServerHello('server-close'));
        self::assertSame(1, RelayWorker::getActiveConnectionCount());

        $relay->onClose($serverWs);
        self::assertSame(0, RelayWorker::getActiveConnectionCount());
    }

    // ---- Startup reconciliation (Step B7) ---------------------------------

    public function testReconcileOrphanedSessionsClosesOrphansWhenNoTunnels(): void
    {
        // A real RelaySessionManager over a DB mock so we can assert the exact
        // reconciliation UPDATE it issues. The registry has no live tunnels, so
        // every open session is an orphan and must be closed.
        $capturedSql = '';
        $capturedParams = null;
        $db = $this->createMock(\Workerman\MySQL\Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, $params = null) use (&$capturedSql, &$capturedParams): int {
                $capturedSql = $sql;
                $capturedParams = $params;
                return 2;
            },
        );
        $sessionManager = new RelaySessionManager($db, $this->logger);

        // Empty tunnel registry (TunnelManager built in setUp has no tunnels).
        $relay = new RelayWorker($this->buildContainerWithSessions($sessionManager), 0);
        $relay->reconcileOrphanedSessions($this->tunnelManager, $this->logger);

        self::assertStringContainsString('UPDATE relay_sessions SET closed_at = NOW()', $capturedSql);
        self::assertStringContainsString('closed_at IS NULL', $capturedSql);
        self::assertStringNotContainsString('NOT IN', $capturedSql);
        self::assertSame(['reason' => 'reconciled_on_start'], $capturedParams);
    }

    public function testReconcileOrphanedSessionsPreservesLiveTunnelServers(): void
    {
        // Bring one tunnel ACTIVE in the registry, then reconcile: its server's
        // open session must be excluded from the close (preserved), while every
        // other open session is reconciled closed.
        $capturedParams = null;
        $db = $this->createMock(\Workerman\MySQL\Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, $params = null) use (&$capturedParams): int {
                $capturedParams = $params;
                return 0;
            },
        );
        $sessionManager = new RelaySessionManager($db, $this->logger);

        $serverWs = $this->createMock(TcpConnection::class);
        $serverWs->method('send')->willReturn(true);
        $relay = new RelayWorker($this->buildContainerWithSessions($sessionManager), 0);
        // HELLO brings a live tunnel for 'server-live' into the registry.
        $relay->onMessage($serverWs, $this->encodeServerHello('server-live'));
        self::assertInstanceOf(Tunnel::class, $this->tunnelManager->getTunnelForServer('server-live'));

        $relay->reconcileOrphanedSessions($this->tunnelManager, $this->logger);

        self::assertSame(
            ['reason' => 'reconciled_on_start', 'live_0' => 'server-live'],
            $capturedParams,
        );
    }

    // ---- HB-4.6e: relay-connect rate limit (WS close 1013) ---------------

    /**
     * The :8802 relay-connect surface must throttle inbound server connects by
     * remote IP and REJECT the over-limit handshake with WS code 1013 (WS≠HTTP:
     * no 429 body here). A legit reconnect after the window resets must pass.
     * This closes the H-H1 tunnel-displacement DoS (a flood of connects).
     */
    public function testRelayConnectRateLimitedAfterBurstThenReconnectPasses(): void
    {
        $now = 2_000_000;
        $clock = function () use (&$now): int {
            return $now;
        };
        // 3 connects/window/IP; advanceable clock so the reconnect is post-window.
        $limiter = new RateLimiter(60, 3, 10000, $clock);
        $relay = new RelayWorker($this->buildContainerWithLimiter($limiter), 0);

        // The 2 under-limit connects from one IP are NOT closed.
        for ($i = 0; $i < 2; $i++) {
            $conn = $this->createMock(TcpConnection::class);
            $conn->method('getRemoteIp')->willReturn('192.0.2.10');
            $conn->expects($this->never())->method('close');
            $relay->onWebSocketConnect($conn, $this->makeUpgradeRequest());
        }

        // The 3rd connect from the same IP trips the limiter (count >= max) → the
        // handshake is closed with WS code 1013 before any HELLO processing.
        $limited = $this->createMock(TcpConnection::class);
        $limited->method('getRemoteIp')->willReturn('192.0.2.10');
        $limited->expects($this->once())
            ->method('close')
            ->with((string) RelayWorker::CLOSE_TRY_AGAIN_LATER, true);
        $relay->onWebSocketConnect($limited, $this->makeUpgradeRequest());

        // After the window resets, a legit reconnect from the same IP passes.
        $now += 61;
        $reconnect = $this->createMock(TcpConnection::class);
        $reconnect->method('getRemoteIp')->willReturn('192.0.2.10');
        $reconnect->expects($this->never())->method('close');
        $relay->onWebSocketConnect($reconnect, $this->makeUpgradeRequest());
    }

    /**
     * Buckets are keyed per remote IP, not a shared global counter — one noisy
     * IP tripping its limit must not starve a different, quiet IP.
     */
    public function testRelayConnectLimiterIsKeyedPerIp(): void
    {
        $limiter = new RateLimiter(60, 2);
        $relay = new RelayWorker($this->buildContainerWithLimiter($limiter), 0);

        // IP A: first connect passes, second trips (count >= 2).
        $a1 = $this->createMock(TcpConnection::class);
        $a1->method('getRemoteIp')->willReturn('192.0.2.1');
        $a1->expects($this->never())->method('close');
        $relay->onWebSocketConnect($a1, $this->makeUpgradeRequest());

        $a2 = $this->createMock(TcpConnection::class);
        $a2->method('getRemoteIp')->willReturn('192.0.2.1');
        $a2->expects($this->once())
            ->method('close')
            ->with((string) RelayWorker::CLOSE_TRY_AGAIN_LATER, true);
        $relay->onWebSocketConnect($a2, $this->makeUpgradeRequest());

        // IP B has its OWN bucket: its first connect passes even though IP A is
        // already tripped (a shared global counter would have closed this too).
        $b1 = $this->createMock(TcpConnection::class);
        $b1->method('getRemoteIp')->willReturn('192.0.2.2');
        $b1->expects($this->never())->method('close');
        $relay->onWebSocketConnect($b1, $this->makeUpgradeRequest());
    }

    // ---- Helpers ----------------------------------------------------------

    /**
     * Build a minimal Workerman WS-upgrade Request. RelayWorker does not read the
     * request in onWebSocketConnect (the server_id arrives in the first HELLO
     * message), so a bare upgrade buffer is sufficient.
     */
    private function makeUpgradeRequest(): WorkermanRequest
    {
        $raw = "GET / HTTP/1.1\r\nHost: hub.example.com\r\nUpgrade: websocket\r\n"
            . "Connection: Upgrade\r\nSec-WebSocket-Version: 13\r\n"
            . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n\r\n";

        return new WorkermanRequest($raw);
    }

    /**
     * PSR-11 container exposing the TunnelManager, the shared session manager,
     * and a specific relay-connect rate limiter under its per-surface container
     * id ({@see RateLimitProfiles::RELAY_CONNECT}).
     */
    private function buildContainerWithLimiter(RateLimiterInterface $limiter): ContainerInterface
    {
        $tunnelManager = $this->tunnelManager;
        $sessionManager = $this->sessionManager;

        return new class ($tunnelManager, $sessionManager, $limiter) implements ContainerInterface {
            public function __construct(
                private readonly TunnelManager $tunnelManager,
                private readonly RelaySessionManager $sessionManager,
                private readonly RateLimiterInterface $limiter,
            ) {
            }

            public function get(string $id): mixed
            {
                return match ($id) {
                    TunnelManager::class, TunnelManagerInterface::class => $this->tunnelManager,
                    RelaySessionManager::class => $this->sessionManager,
                    RateLimitProfiles::RELAY_CONNECT => $this->limiter,
                    default => throw new \RuntimeException("Unknown service: {$id}"),
                };
            }

            public function has(string $id): bool
            {
                return in_array($id, [
                    TunnelManager::class,
                    TunnelManagerInterface::class,
                    RelaySessionManager::class,
                    RateLimitProfiles::RELAY_CONNECT,
                ], true);
            }
        };
    }

    /**
     * Build the JSON HELLO text exactly as the media server emits it.
     */
    private function encodeServerHello(string $serverId): string
    {
        return (string) json_encode([
            'type' => 'hello',
            'enrollment_jwt' => 'jwt.value.here',
            'server_id' => $serverId,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Build a binary relay frame independently of the hub's own encoder, in the
     * shared `[4B seq big-endian][1B type][2B len big-endian][payload]` layout.
     * This is what the server's RelayMessageFramer::encode() puts on the wire.
     */
    private function encodeServerFrame(RelayFrameType $type, int $seq, string $payload): string
    {
        return pack('N', $seq)
            . chr($type->value)
            . pack('n', strlen($payload))
            . $payload;
    }

    /**
     * Minimal PSR-11 container exposing the TunnelManager the worker resolves.
     */
    private function buildContainer(): ContainerInterface
    {
        return $this->buildContainerWithSessions($this->sessionManager);
    }

    /**
     * PSR-11 container exposing a SPECIFIC TunnelManager (e.g. one wired with a
     * rejecting jwtService for the DoS regression guard) plus the shared session
     * manager.
     */
    private function buildContainerFor(TunnelManager $tunnelManager): ContainerInterface
    {
        $sessionManager = $this->sessionManager;

        return new class ($tunnelManager, $sessionManager) implements ContainerInterface {
            public function __construct(
                private readonly TunnelManager $tunnelManager,
                private readonly RelaySessionManager $sessionManager,
            ) {
            }

            public function get(string $id): mixed
            {
                return match ($id) {
                    TunnelManager::class, TunnelManagerInterface::class => $this->tunnelManager,
                    RelaySessionManager::class => $this->sessionManager,
                    default => throw new \RuntimeException("Unknown service: {$id}"),
                };
            }

            public function has(string $id): bool
            {
                return in_array($id, [
                    TunnelManager::class,
                    TunnelManagerInterface::class,
                    RelaySessionManager::class,
                ], true);
            }
        };
    }

    /**
     * PSR-11 container exposing the TunnelManager plus a specific
     * RelaySessionManager (so the startup-reconciliation path can be exercised
     * with a DB-backed session manager).
     */
    private function buildContainerWithSessions(RelaySessionManager $sessionManager): ContainerInterface
    {
        $tunnelManager = $this->tunnelManager;

        return new class ($tunnelManager, $sessionManager) implements ContainerInterface {
            public function __construct(
                private readonly TunnelManager $tunnelManager,
                private readonly RelaySessionManager $sessionManager,
            ) {
            }

            public function get(string $id): mixed
            {
                return match ($id) {
                    TunnelManager::class, TunnelManagerInterface::class => $this->tunnelManager,
                    RelaySessionManager::class => $this->sessionManager,
                    default => throw new \RuntimeException("Unknown service: {$id}"),
                };
            }

            public function has(string $id): bool
            {
                return in_array($id, [
                    TunnelManager::class,
                    TunnelManagerInterface::class,
                    RelaySessionManager::class,
                ], true);
            }
        };
    }
}
