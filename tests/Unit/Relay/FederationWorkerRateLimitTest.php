<?php

/**
 * Phlix hub test: Relay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Common\Logger\LogChannels;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Common\RateLimit\RateLimiter;
use Phlix\Hub\Common\RateLimit\RateLimitProfiles;
use Phlix\Hub\Federation\FederationConnectionManager;
use Phlix\Hub\Federation\FederationFrameHandler;
use Phlix\Hub\Federation\FederationHubRepository;
use Phlix\Hub\Federation\FederationLibraryShareRepository;
use Phlix\Hub\Federation\FederationPeerManager;
use Phlix\Hub\Federation\FederationSessionManager;
use Phlix\Hub\Hub\Ed25519KeyManager;
use Phlix\Hub\Http\Controllers\FederationRelayController;
use Phlix\Hub\Relay\FederationWorker;
use Phlix\Hub\Relay\WebSocketCloseFrame;
use Phlix\Hub\Tests\Support\LoggerFactoryIsolation;
use Phlix\Hub\Tests\Support\Relay\RecordingMountLimiter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionProperty;
use RuntimeException;
use stdClass;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request as WorkermanRequest;

/**
 * M-HUB-14 (review hub__exploiter M3): the :8805 federation worker must
 * rate-limit inbound connects (master role, BEFORE the federation_peers DB
 * read) and per-peer inbound frames (BEFORE controller dispatch), refusing
 * over-budget traffic with a real WS close frame 1013 — the sibling law of
 * :8802 {@see \Phlix\Hub\SyncPlay\RelayWorker} and :8803
 * {@see \Phlix\Hub\Relay\ClientRelayWorker}. Limiter-unavailable must fail
 * OPEN (unlimited), never deny federation.
 */
final class FederationWorkerRateLimitTest extends TestCase
{
    use LoggerFactoryIsolation;

    /** Hub id used by the canonical upgrade path below. */
    private const HUB_ID = 'hub-alpha-uuid';

    private string $tmpDir = '';

    protected function setUp(): void
    {
        FederationWorker::reset();
        // The REAL controller instance(s) built here share their static
        // connection/decoder maps process-wide; ids of freed mocks from a
        // previous test (or another suite's connections) can be REUSED by a
        // fresh mock and alias into stale entries. Wipe both before every
        // test so the unknown-connection refusal pin cannot flake on id
        // recycling (matters when this file runs alongside the full suite).
        (new ReflectionProperty(FederationRelayController::class, 'connHubIds'))->setValue(null, []);
        (new ReflectionProperty(FederationRelayController::class, 'connDecoders'))->setValue(null, []);

        // The production code logs (warnings + the fail-open error lines);
        // point the global factory at a memory stream so neither real files
        // nor an uninitialised-config ValueError is involved (house idiom,
        // RelayWorkerTest/ClientRelayWorkerTest).
        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-fed-rl-' . uniqid('', true);
        mkdir($this->tmpDir, 0700, true);
        file_put_contents(
            $this->tmpDir . '/logger.php',
            "<?php return ['default' => 'mem', 'handlers' => ['mem' => "
            . "['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug']]];",
        );
        LoggerFactory::reset();
        LoggerFactory::init($this->tmpDir . '/logger.php');
    }

    protected function tearDown(): void
    {
        FederationWorker::reset();
        LoggerFactory::reset();
        if (is_file($this->tmpDir . '/logger.php')) {
            unlink($this->tmpDir . '/logger.php');
        }
        if (is_dir($this->tmpDir)) {
            @rmdir($this->tmpDir);
        }
        parent::tearDown();
    }

    // ------------------------------------------------------ connect limiter

    /**
     * Over-budget connect: refused with the REAL 1013 close frame and — the
     * ordering law — with ZERO `federation_peers` reads. The whole point of
     * this limiter is that the refused path performs no DB work; a limiter
     * wired after the getPeerById() probe (the r1 defect shape) would still
     * pay the SELECT per hostile socket and would keep this test red.
     */
    public function testOverBudgetConnectCloses1013BeforeAnyDbRead(): void
    {
        $dbReads = 0;
        $hubRepo = $this->createMock(FederationHubRepository::class);
        $hubRepo->method('getPeerById')->willReturnCallback(
            function (string $id) use (&$dbReads): array {
                $dbReads++;

                return ['id' => $id];
            }
        );

        // RateLimiter semantics (pinned by the sibling suites): the hit whose
        // count REACHES max is the limited one — max=2 lets one through.
        $connectLimiter = new RateLimiter(60, 2, 10000, static fn (): int => 1_000_000);

        $worker = $this->worker([
            FederationHubRepository::class => $hubRepo,
            RateLimitProfiles::FEDERATION_CONNECT => $connectLimiter,
            RateLimitProfiles::FEDERATION_FRAME => new RateLimiter(60, 1000, 10000),
        ]);
        $request = $this->upgradeRequest();

        $first = $this->connection('198.51.100.7');
        $worker->onWebSocketConnect($first, $request);
        self::assertSame(1, $dbReads, 'under-budget connect must still validate via the DB');

        $second = $this->connection('198.51.100.7');
        $worker->onWebSocketConnect($second, $request);
        self::assertSame(1, $dbReads, 'over-budget connect must NOT reach the federation_peers read');
        $this->assertRejectedWithCloseFrame($second, FederationWorker::CLOSE_TRY_AGAIN_LATER);
        self::assertSame(
            "\x88\x02\x03\xf5",
            WebSocketCloseFrame::bytes(FederationWorker::CLOSE_TRY_AGAIN_LATER),
            'the refused peer must observe RFC 6455 code 1013 (0x03F5) on the wire',
        );
        /** @var array<int, string> $map */
        $map = (new ReflectionProperty(FederationWorker::class, 'connHubIds'))->getValue();
        self::assertSame(
            [self::HUB_ID],
            array_values($map),
            'the refused connect must not enter the connection map (only the first one did)',
        );
    }

    /**
     * The connect bucket keys on the PROXY-RESOLVED client IP, never the raw
     * TCP peer: on the managed install every leaf arrives via the :8805
     * HAProxy leg, so a raw-peer key would collapse all leaves into one
     * bucket (self-DoS lever). Same fixture law as the client-mount suite's
     * trusted-proxy test.
     */
    public function testConnectLimiterKeysOnTrustedClientIpNotRawPeer(): void
    {
        $hubRepo = $this->createMock(FederationHubRepository::class);
        $hubRepo->method('getPeerById')->willReturn(['id' => self::HUB_ID]);

        $recorder = new RecordingMountLimiter();
        $worker = $this->worker([
            FederationHubRepository::class => $hubRepo,
            RateLimitProfiles::FEDERATION_CONNECT => $recorder,
        ]);
        $request = $this->upgradeRequest('/relay/federation/' . self::HUB_ID, [
            'X-Forwarded-For' => '203.0.113.50',
        ]);

        $connection = $this->connection('127.0.0.1');
        $worker->onWebSocketConnect($connection, $request);

        self::assertSame(['federation_connect:203.0.113.50'], $recorder->hits);
        self::assertNotContains('federation_connect:127.0.0.1', $recorder->hits);
    }

    /**
     * Fail-open law (identical to :8802/:8803): when the container cannot
     * provide the limiter, federation stays reachable — the connect proceeds
     * to normal validation, and exactly ONE named error line records the
     * degraded posture (never silence, never a throw out of the hot path).
     */
    public function testConnectLimiterUnavailableFailsOpenWithOneErrorLine(): void
    {
        $dbReads = 0;
        $hubRepo = $this->createMock(FederationHubRepository::class);
        $hubRepo->method('getPeerById')->willReturnCallback(
            function (string $id) use (&$dbReads): array {
                $dbReads++;

                return ['id' => $id];
            }
        );

        // Container with the FEDERATION_CONNECT entry FORCED to throw, exactly
        // the production misconfiguration shape.
        $worker = $this->worker(
            [FederationHubRepository::class => $hubRepo],
            [RateLimitProfiles::FEDERATION_CONNECT],
        );

        $logger = $this->injectRelayLogger();
        $logger->expects(self::once())
            ->method('error')
            ->with(
                self::stringContains('failing open (unlimited)'),
                self::anything(),
            );

        $connection = $this->connection('198.51.100.7');
        $worker->onWebSocketConnect($connection, $this->upgradeRequest());

        self::assertSame(1, $dbReads, 'fail-open must let the connect reach validation');
        self::assertNull($connection->onWebSocketConnected ?? null);
    }

    // ------------------------------------------------------- frame limiter

    /**
     * Per-peer frame budget at the master-side choke point, with the
     * N/(N+1) transition and the dispatch-order law: under budget frames
     * REACH the controller (invalid JSON → the controller's own
     * 'Invalid JSON payload' close), the first over-budget frame gets 1013
     * and dispatch never runs. Keyed per REMOTE peer hub id, not per socket:
     * a peer reconnecting cannot reset the window by swapping connections
     * within the bucket window (a second socket for the same hub hits the
     * shared bucket).
     */
    public function testFrameBudgetRefusesOverBudgetPeerBeforeDispatch(): void
    {
        $hubRepo = $this->createMock(FederationHubRepository::class);
        $hubRepo->method('getPeerById')->willReturnCallback(
            fn (string $id): array => ['id' => $id]
        );

        $frameLimiter = new RateLimiter(60, 3, 10000, static fn (): int => 2_000_000);
        $worker = $this->worker([
            FederationHubRepository::class => $hubRepo,
            RateLimitProfiles::FEDERATION_FRAME => $frameLimiter,
        ]);

        $invalidText = '[1,2]';
        // (isTextFrame's law: only FLAT JSON arrays/scalars classify as text
        // — `json_decode($data, false, 2)` answers objects with stdClass,
        // which is neither array nor scalar. Genuine JSON-object payloads
        // currently take the binary branch: a pre-existing controller quirk
        // disclosed in the fixlog, NOT this lane's change.)

        // First two frames are under budget (3rd hit is the limited one):
        // they reach the controller, which closes with its parse error —
        // proof the dispatch happened (1013 would prove the opposite).
        $first = $this->connection('198.51.100.9');
        $worker->onWebSocketConnect($first, $this->upgradeRequest());
        $first->context = $this->established();
        $worker->onMessage($first, $invalidText);
        self::assertSame(['Missing frame type'], $this->closePayloads($first));

        $second = $this->connection('198.51.100.9');
        $worker->onWebSocketConnect($second, $this->upgradeRequest());
        $second->context = $this->established();
        $worker->onMessage($second, $invalidText);
        self::assertSame(['Missing frame type'], $this->closePayloads($second));

        // Third frame from the SAME hub identity — different socket, same
        // bucket (the per-hub, not per-socket, law) — trips the budget: one
        // warning + real close 1013, controller dispatch never ran (a
        // reached-controller close payload would read 'Invalid JSON
        // payload', never the raw frame).
        $logger = $this->injectRelayLogger();
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                self::stringContains('frame budget exceeded'),
                self::callback(
                    static fn (array $ctx): bool => ($ctx['hub_id'] ?? null) === self::HUB_ID
                        && is_int($ctx['reset_at'] ?? null)
                ),
            );

        $third = $this->connection('198.51.100.9');
        $worker->onWebSocketConnect($third, $this->upgradeRequest());
        $third->context = $this->established();
        $worker->onMessage($third, $invalidText);

        self::assertSame(
            ["\x88\x02\x03\xf5"],
            array_map(static fn (array $call): mixed => $call[0], $this->closeCalls($third)),
            'over-budget frames must die at the 1013 envelope (0x03F5), never at the controller',
        );
    }

    /**
     * Budgets are PER PEER: exhausting hub B's bucket must not throttle hub
     * A's traffic. One recorder proves distinct keys end-to-end.
     */
    public function testFrameBudgetIsPerPeerNotGlobal(): void
    {
        $hubRepo = $this->createMock(FederationHubRepository::class);
        $hubRepo->method('getPeerById')->willReturnCallback(
            fn (string $id): array => ['id' => $id]
        );

        $recorder = new RecordingMountLimiter();
        $worker = $this->worker([
            FederationHubRepository::class => $hubRepo,
            RateLimitProfiles::FEDERATION_FRAME => $recorder,
        ]);

        $a = $this->connection('198.51.100.10');
        $worker->onWebSocketConnect($a, $this->upgradeRequest('/relay/federation/hub-a'));
        $a->context = $this->established();
        $worker->onMessage($a, '[1,2]');

        $b = $this->connection('198.51.100.11');
        $worker->onWebSocketConnect($b, $this->upgradeRequest('/relay/federation/hub-b'));
        $b->context = $this->established();
        $worker->onMessage($b, '[1,2]');

        self::assertSame(
            ['federation_frame:hub-a', 'federation_frame:hub-b'],
            $recorder->hits,
            'frame keys must be the remote peer hub ids, one bucket each',
        );
    }

    /**
     * Frames on connections ABSENT from the worker map keep the unchanged
     * pass-through law (the controller owns that refusal and closes with
     * 'unknown_connection'): the limiter must neither hit a bucket nor
     * invent a 1013 there — no new behaviour on a path with no identity.
     */
    public function testFramesOnUnknownConnectionsPassThroughLimiterUnchanged(): void
    {
        $recorder = new RecordingMountLimiter();
        $worker = $this->worker([
            RateLimitProfiles::FEDERATION_FRAME => $recorder,
        ]);

        $connection = $this->connection('198.51.100.12');
        $connection->context = $this->established();
        $worker->onMessage($connection, '{"foo":"bar"}');

        self::assertSame([], $recorder->hits, 'no hub identity — no bucket to hit');
        self::assertSame(
            ['unknown_connection'],
            $this->closePayloads($connection),
            'the controller must still own the unknown-connection refusal',
        );
    }

    /**
     * Frame-limiter fail-open: container entry throws → onMessage dispatches
     * exactly as before the lane (controller reached, never a 1013), with
     * ONE named error line.
     */
    public function testFrameLimiterUnavailableFailsOpenToDispatch(): void
    {
        $hubRepo = $this->createMock(FederationHubRepository::class);
        $hubRepo->method('getPeerById')->willReturnCallback(
            fn (string $id): array => ['id' => $id]
        );

        $worker = $this->worker(
            [FederationHubRepository::class => $hubRepo],
            [RateLimitProfiles::FEDERATION_FRAME],
        );

        $logger = $this->injectRelayLogger();
        $logger->expects(self::once())
            ->method('error')
            ->with(self::stringContains('frame rate limiter unavailable'), self::anything());

        $connection = $this->connection('198.51.100.13');
        $worker->onWebSocketConnect($connection, $this->upgradeRequest());
        $connection->context = $this->established();
        $worker->onMessage($connection, '[1,2]');

        self::assertSame(
            ['Missing frame type'],
            $this->closePayloads($connection),
            'fail-open must fall through to normal controller dispatch',
        );
    }

    // ------------------------------------------------------ shared-instance

    /**
     * The leaf leg must ride the SAME limiter instance as the master-side
     * listener (one :8805 process owns both roles; keys are remote hub ids
     * so the roles never share a bucket). Wiring law: FederationWorker's
     * onWorkerStart resolves the frame limiter and installs it into the peer
     * manager BEFORE bootstrapFromWorker(), so the very first dialled link is
     * guarded; the install must survive even when the broker join fails (the
     * existing guard's log-and-continue law is not weakened).
     */
    public function testWorkerStartInstallsFrameLimiterIntoPeerManagerFirst(): void
    {
        $frameLimiter = new RateLimiter(60, 120);
        $order = [];

        $peerManager = $this->createMock(FederationPeerManager::class);
        $peerManager->expects(self::once())
            ->method('setReceiveFrameLimiter')
            ->with($frameLimiter)
            ->willReturnCallback(
                function () use (&$order): void {
                    $order[] = 'install';
                }
            );
        $peerManager->expects(self::once())
            ->method('bootstrapFromWorker')
            ->willReturnCallback(
                function () use (&$order): void {
                    $order[] = 'bootstrap';
                }
            );

        $worker = $this->worker([
            FederationPeerManager::class => $peerManager,
            RateLimitProfiles::FEDERATION_FRAME => $frameLimiter,
        ]);

        // ChannelClient::connect to the absent broker throws inside the
        // already-guarded join block; the peer-manager block precedes it and
        // must have completed both calls regardless.
        $worker->onWorkerStart();

        self::assertSame(['install', 'bootstrap'], $order);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Fake container: every entry resolves; ids named in $throwOn ALWAYS
     * throw (the production misconfiguration shape the fail-open pins
     * measure) even when a default provides one.
     *
     * @param array<string, object> $entries
     * @param list<string>          $throwOn
     */
    private function worker(array $entries, array $throwOn = []): FederationWorker
    {
        $defaults = [
            FederationRelayController::class => $this->controller(),
            // Every frame/wiring test runs real connects first; give them an
            // inert unlimited connect limiter so the only limiter noise is the
            // one under test. (Fail-open pins override via $throwOn.)
            RateLimitProfiles::FEDERATION_CONNECT => new RateLimiter(60, PHP_INT_MAX, 10000),
        ];

        $container = new class (array_merge($defaults, $entries), $throwOn) implements ContainerInterface {
            /**
             * @param array<string, object> $entries
             * @param list<string>          $throwOn
             */
            public function __construct(
                private readonly array $entries,
                private readonly array $throwOn,
            ) {
            }

            public function get(string $id): object
            {
                if (in_array($id, $this->throwOn, true) || !isset($this->entries[$id])) {
                    throw new RuntimeException("unresolved: $id");
                }

                return $this->entries[$id];
            }

            public function has(string $id): bool
            {
                return !in_array($id, $this->throwOn, true) && isset($this->entries[$id]);
            }
        };

        return new FederationWorker($container);
    }

    /**
     * A REAL FederationRelayController over a REAL FederationFrameHandler
     * (both final — composition, not mocks) whose collaborators are inert:
     * invalid-JSON text frames are refused by the handler's first guard, so
     * no collaborator is touched and the 'Invalid JSON payload' close is the
     * observable "dispatch reached the controller" marker. The frame-handler
     * stack mirrors FederationFrameHandlerTest's venue exactly.
     */
    private function controller(): FederationRelayController
    {
        $connMgr = new FederationConnectionManager();
        $keyDir = $this->tmpDir . '/keys';
        mkdir($keyDir, 0700, true);

        $handler = new FederationFrameHandler(
            $this->createMock(FederationHubRepository::class),
            $this->createMock(FederationSessionManager::class),
            $this->createMock(FederationLibraryShareRepository::class),
            $connMgr,
            $this->createMock(AuditLogger::class),
            new Ed25519KeyManager($keyDir . '/master-ed25519.pem'),
        );

        return new FederationRelayController($handler, $connMgr);
    }

    /** Mock TCP connection with a fixed raw peer address + close recorder. */
    private function connection(string $remoteIp): TcpConnection&MockObject
    {
        $connection = $this->createMock(TcpConnection::class);
        $connection->method('getRemoteIp')->willReturn($remoteIp);

        /** @var \ArrayObject<int, array<array-key, mixed>> $recorder */
        $recorder = new \ArrayObject();
        $connection->method('close')->willReturnCallback(
            static function (mixed ...$args) use ($recorder): void {
                $recorder->append($args);
            }
        );
        $this->closeRecorders[spl_object_id($connection)] = $recorder;

        return $connection;
    }

    /** @var array<int, \ArrayObject<int, array<array-key, mixed>>> side table: connection id → close calls */
    private array $closeRecorders = [];

    /** @return list<mixed> close() first arguments, in call order */
    private function closePayloads(TcpConnection $connection): array
    {
        return array_map(
            static fn (array $call): mixed => $call[0],
            $this->closeCalls($connection),
        );
    }

    /** @return list<array<array-key, mixed>> every close(...) argument list, in call order */
    private function closeCalls(TcpConnection $connection): array
    {
        $recorder = $this->closeRecorders[spl_object_id($connection)] ?? null;
        self::assertNotNull($recorder, 'close recorder must exist for a mocked connection');

        /** @var list<array<array-key, mixed>> $calls */
        $calls = $recorder->getArrayCopy();

        return $calls;
    }

    /** Mark a mocked connection as post-upgrade (reject() closes directly). */
    private function established(): stdClass
    {
        $context = new stdClass();
        $context->websocketHandshake = true;

        return $context;
    }

    /**
     * Real Workerman WS-upgrade request for the canonical federation path.
     *
     * @param array<string, string> $headers
     */
    private function upgradeRequest(?string $path = null, array $headers = []): WorkermanRequest
    {
        $allHeaders = array_merge([
            'Host' => 'master.example.com',
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Version' => '13',
            'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ==',
        ], $headers);

        $lines = ['GET ' . ($path ?? '/relay/federation/' . self::HUB_ID) . ' HTTP/1.1'];
        foreach ($allHeaders as $name => $value) {
            $lines[] = "$name: $value";
        }

        return new WorkermanRequest(implode("\r\n", $lines) . "\r\n\r\n");
    }

    /**
     * Pre-upgrade rejection assertion (identical law to the sibling suites):
     * the refusal arms onWebSocketConnected so the close frame FOLLOWS the
     * 101 bytes, then delivers close(<frame>, true). Asserted on the shared
     * close recorder — one exact call, cleanup flag set.
     */
    private function assertRejectedWithCloseFrame(TcpConnection&MockObject $connection, int $code): void
    {
        $armed = $connection->onWebSocketConnected ?? null;
        self::assertInstanceOf(
            \Closure::class,
            $armed,
            'pre-upgrade rejection must arm onWebSocketConnected for a real close frame',
        );

        $armed($connection);

        self::assertSame(
            [[WebSocketCloseFrame::bytes($code), true]],
            $this->closeCalls($connection),
            'the peer must observe exactly one real close frame with that code',
        );
    }

    /**
     * Replace the memoised RELAY channel logger with a strict mock, letting a
     * test pin "exactly ONE line, with these context keys".
     */
    private function injectRelayLogger(): StructuredLogger&MockObject
    {
        $logger = $this->createMock(StructuredLogger::class);
        LoggerFactory::reset();

        $property = new ReflectionProperty(LoggerFactory::class, 'loggers');
        $property->setValue(null, [LogChannels::RELAY => $logger]);

        return $logger;
    }
}
