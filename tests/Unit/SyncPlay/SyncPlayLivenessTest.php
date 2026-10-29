<?php

/**
 * Phlix hub test: SyncPlay Relay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\SyncPlay;

use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Hub\ClientRelayTokenService;
use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\Relay\WebSocketCloseFrame;
use Phlix\Hub\SyncPlay\SyncPlayClient;
use Phlix\Hub\SyncPlay\SyncPlayRelayWorker;
use Phlix\Hub\Tests\Support\LoggerFactoryIsolation;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use RuntimeException;
use stdClass;
use Workerman\Connection\TcpConnection;
use Workerman\MySQL\Connection;
use Workerman\Protocols\Http\Request as WorkermanRequest;

use function array_merge;
use function count;
use function end;
use function file_put_contents;
use function glob;
use function hash;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function json_decode;
use function json_encode;
use function mkdir;
use function rmdir;
use function spl_object_id;
use function str_contains;
use function sys_get_temp_dir;
use function time;
use function unlink;
use function uniqid;

/**
 * M-HUB-18 — the `:8804` server-driven liveness contract (review hub__gameux M3).
 *
 * Before this shipped the surface had NO keepalive: liveness was pure TCP, a
 * half-open HOST stayed in the room books forever, and every member's
 * host-gated command died behind a sender-only `NOT_HOST` — the watch party
 * froze until the ghost's socket happened to error. These tests pin the cure:
 *
 *   1. every live socket gets a real WS ping (0x89, empty payload, raw) each
 *      `LIVENESS_PING_INTERVAL_SECONDS` tick;
 *   2. silence at or past `LIVENESS_REAP_AFTER_SILENCE_SECONDS` is reaped with
 *      a REAL close frame carrying 1013 (reconnect-cured, not 1011 do-not-retry);
 *   3. ANY inbound traffic refreshes the budget: a data frame, a client ping
 *      (which we must also ANSWER — registering `onWebSocketPing` displaces the
 *      vendor auto-pong), or a pong to our ping. A quiet-but-healthy client is
 *      never falsely evicted, and a throttled-but-loud client is never evicted
 *      for silence it demonstrably did not produce;
 *   4. the party thaws: reaping the ghost host routes through the SAME
 *      `onClose()` canonical leave a graceful disconnect uses, so the oldest
 *      remaining member is elected and hears `syncplay_host_elect`.
 *
 * The reap-vs-ping coupling law (HB-0.1, imported from `IdleReaper`) is pinned
 * on the constants themselves, so a future tune that lets the window catch up
 * with the cadence reddens here instead of shipping false evictions.
 *
 * Harness mirrors {@see SyncPlayRelayCanonicalDialectTest} (real auth path over
 * a faked DB, recording sinks) — the liveness machinery is proven under
 * identical conditions to the dialect it guards.
 *
 * @package Phlix\Hub\Tests\Unit\SyncPlay
 */
final class SyncPlayLivenessTest extends TestCase
{
    // LoggerFactory's static $configPath/$loggers are process-global; the trait
    // snapshots them before setUp() and restores them after tearDown().
    use LoggerFactoryIsolation;

    /** The wire shape of the liveness probe: FIN+opcode 0x9, empty payload. */
    private const PING_WIRE = "\x89\x00";

    private string $tmpDir;

    /**
     * Map of token_hash (sha256 of the plaintext token) => bound
     * {user_id, server_id} the fake token-lookup query treats as ACTIVE.
     *
     * @var array<string, array{user_id: string, server_id: string}>
     */
    private array $validTokens = [];

    /**
     * Map of server_id => owning user_id used by the fake ServerInfoHandler.
     *
     * @var array<string, string>
     */
    private array $serverOwners = [];

    protected function setUp(): void
    {
        parent::setUp();

        SyncPlayRelayWorker::reset();

        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-liveness-test-' . uniqid();
        mkdir($this->tmpDir, 0700, true);

        $loggerConfig = $this->tmpDir . '/logger.php';
        file_put_contents(
            $loggerConfig,
            "<?php return ['default' => 'mem', 'handlers' => ['mem' => "
            . "['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug']]];",
        );
        LoggerFactory::reset();
        LoggerFactory::init($loggerConfig);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        SyncPlayRelayWorker::reset();
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

    // ---- coupling law (mutation-toothed constant pins) --------------------

    public function testReapWindowStaysStrictlyGreaterThanThePingCadence(): void
    {
        self::assertGreaterThan(
            SyncPlayRelayWorker::LIVENESS_PING_INTERVAL_SECONDS,
            SyncPlayRelayWorker::LIVENESS_REAP_AFTER_SILENCE_SECONDS,
            'HB-0.1: a reap window at or below the ping cadence evicts clients '
            . 'before their ping answer can ever be counted.',
        );
        self::assertGreaterThanOrEqual(
            3 * SyncPlayRelayWorker::LIVENESS_PING_INTERVAL_SECONDS,
            SyncPlayRelayWorker::LIVENESS_REAP_AFTER_SILENCE_SECONDS,
            'The IdleReaper precedent ships a 3x margin (90s vs 30s); narrowing '
            . 'it silently changes the false-positive math every socket-layer '
            . 'ping-answerer relies on.',
        );
    }

    public function testLivenessCloseCodeIsTheReconnectCuredFamily(): void
    {
        self::assertSame(1013, SyncPlayRelayWorker::CLOSE_LIVENESS_TIMEOUT);
        self::assertNotSame(
            SyncPlayRelayWorker::CLOSE_DO_NOT_RETRY,
            SyncPlayRelayWorker::CLOSE_LIVENESS_TIMEOUT,
            'A reap IS cured by reconnecting; borrowing 1011 would tell a '
            . 'transiently-disconnected device to give up.',
        );
    }

    // ---- the tick: ping the living, reap the silent -----------------------

    public function testTickPingsALiveSocketWithTheRawWirePingAndClosesNothing(): void
    {
        $worker = $this->makeWorker();
        $sink = [];
        $connection = $this->authedClient($worker, 'token-a', $sink);

        $worker->livenessTick();

        self::assertSame(
            [self::PING_WIRE],
            $sink,
            'a freshly connected client is proof-of-life: exactly one raw ping, nothing else',
        );
        self::assertNull(
            $connection->onWebSocketConnected ?? null,
            'a live client must not have a reap armed',
        );
        self::assertSame(1, SyncPlayRelayWorker::getActiveConnectionCount());
    }

    public function testTickReapsOnlyTheSilentGhostWhileTheNeighbourKeepsItsPing(): void
    {
        $worker = $this->makeWorker();

        $liveSink = [];
        $this->authedClient($worker, 'token-live', $liveSink);

        $ghostSink = [];
        $ghost = $this->authedClient($worker, 'token-ghost', $ghostSink);
        $this->makeHandshakeComplete($ghost);
        $this->clientFor($ghost)->lastSeenAt = time() - SyncPlayRelayWorker::LIVENESS_REAP_AFTER_SILENCE_SECONDS;

        // Production shape: the handshake completed long ago, so reject() must
        // close IMMEDIATELY with the real 1013 frame — Workerman then fires
        // onClose() (the leave/election payoff is its own test below).
        $ghost->expects($this->once())
            ->method('close')
            ->with(WebSocketCloseFrame::bytes(SyncPlayRelayWorker::CLOSE_LIVENESS_TIMEOUT), true);

        $worker->livenessTick();

        self::assertSame([], $ghostSink, 'a reaped socket gets a close frame, never a ping it cannot answer');
        self::assertSame([self::PING_WIRE], $liveSink, 'the live neighbour is untouched by the reap');
    }

    public function testSilenceOneSecondShortOfTheWindowSurvives(): void
    {
        $worker = $this->makeWorker();
        $sink = [];
        $connection = $this->authedClient($worker, 'token-a', $sink);
        $this->clientFor($connection)->lastSeenAt =
            time() - (SyncPlayRelayWorker::LIVENESS_REAP_AFTER_SILENCE_SECONDS - 1);

        $worker->livenessTick();

        self::assertSame([self::PING_WIRE], $sink, '89s of silence is inside the grace the coupling law promises');
        self::assertNull($connection->onWebSocketConnected ?? null);
    }

    public function testAnyDataFrameRefreshesTheReapBudget(): void
    {
        $worker = $this->makeWorker();

        // Control socket at the SAME age, no traffic: proves the age really is
        // reap-inducing, so the survivor's green below is the stamp's doing.
        $controlSink = [];
        $control = $this->authedClient($worker, 'token-control', $controlSink);
        $this->clientFor($control)->lastSeenAt = time() - SyncPlayRelayWorker::LIVENESS_REAP_AFTER_SILENCE_SECONDS;

        $sink = [];
        $connection = $this->authedClient($worker, 'token-a', $sink);
        $this->clientFor($connection)->lastSeenAt = time() - SyncPlayRelayWorker::LIVENESS_REAP_AFTER_SILENCE_SECONDS;

        $worker->onMessage($connection, $this->canonicalFrame('syncplay_group_create', ['group_name' => 'party']));

        $control->expects($this->once())->method('close')->with(
            WebSocketCloseFrame::bytes(SyncPlayRelayWorker::CLOSE_LIVENESS_TIMEOUT),
            true,
        );
        $this->makeHandshakeComplete($control);

        $worker->livenessTick();

        self::assertNull(
            $connection->onWebSocketConnected ?? null,
            'the client that sent a frame at the reap threshold was evicted for silence',
        );
        self::assertSame(self::PING_WIRE, end($sink), 'the refreshed client is pinged, not reaped');
    }

    public function testThrottledDroppedFramesStillCountAsLife(): void
    {
        // A 1-byte bucket: the first frame is never throttled (TokenBucket
        // starts FULL), everything after is in debt — so frames 2 and 3 below
        // are DROPPED before any handling.
        $worker = new SyncPlayRelayWorker(
            SyncPlayRelayWorker::DEFAULT_PORT,
            1,
            $this->buildContainer(),
            inboundRateBytesPerSecond: 1.0,
            inboundBurstBytes: 1.0,
        );

        $controlSink = [];
        $control = $this->authedClient($worker, 'token-control', $controlSink);

        $sink = [];
        $connection = $this->authedClient($worker, 'token-a', $sink);
        $frame = $this->canonicalFrame('syncplay_group_create', ['group_name' => 'party']);
        $worker->onMessage($connection, $frame);   // passes (seeded bucket), drains it
        $worker->onMessage($connection, $frame);    // dropped: in debt

        // A client silent long enough to reap, then ONE more dropped frame.
        $this->makeHandshakeComplete($connection);
        $this->makeHandshakeComplete($control);
        $this->clientFor($connection)->lastSeenAt = time() - SyncPlayRelayWorker::LIVENESS_REAP_AFTER_SILENCE_SECONDS;
        $this->clientFor($control)->lastSeenAt = time() - SyncPlayRelayWorker::LIVENESS_REAP_AFTER_SILENCE_SECONDS;

        // …the dropped frame is still traffic: a loud client is not a ghost,
        // and evicting one for "silence" it demonstrably did not produce is
        // the false-positive the stamp-before-throttle ordering prevents.
        $worker->onMessage($connection, $frame);

        $control->expects($this->once())->method('close')->with(
            WebSocketCloseFrame::bytes(SyncPlayRelayWorker::CLOSE_LIVENESS_TIMEOUT),
            true,
        );

        $worker->livenessTick();

        self::assertSame(
            1,
            count($this->sinkFramesOfType($sink, 'syncplay_group_state')),
            'control: the last frame really WAS throttled away — survival is the stamp, not the handling',
        );
        self::assertSame(
            self::PING_WIRE,
            end($sink),
            'the throttled-but-loud client is pinged, not reaped',
        );
    }

    // ---- client-initiated control frames ----------------------------------

    public function testInboundPingIsAnsweredWithARawPongAndCountsAsLife(): void
    {
        $worker = $this->makeWorker();
        $sink = [];
        $connection = $this->authedClient($worker, 'token-a', $sink);
        $this->clientFor($connection)->lastSeenAt = time() - SyncPlayRelayWorker::LIVENESS_REAP_AFTER_SILENCE_SECONDS;

        $worker->onWebSocketPing($connection, 'x');

        // The vendor auto-pong is DISPLACED by our handler (Websocket::input()
        // only synthesizes the reply when no callback exists) — the answer is
        // ours to send: FIN+0xA, unmasked, echoing the payload.
        self::assertSame(["\x8a\x01x"], $sink);
        self::assertLessThanOrEqual(1, time() - $this->clientFor($connection)->lastSeenAt);

        // And the stamp is load-bearing: the same reap-budget-aged client is
        // now spared by the tick.
        $worker->livenessTick();
        self::assertNull($connection->onWebSocketConnected ?? null);
        self::assertSame(["\x8a\x01x", self::PING_WIRE], $sink);
    }

    public function testPongToOurPingRefreshesTheBudgetAndSendsNothingBack(): void
    {
        $worker = $this->makeWorker();
        $sink = [];
        $connection = $this->authedClient($worker, 'token-a', $sink);
        $this->clientFor($connection)->lastSeenAt = time() - SyncPlayRelayWorker::LIVENESS_REAP_AFTER_SILENCE_SECONDS;

        $worker->onWebSocketPong($connection, '');

        self::assertLessThanOrEqual(1, time() - $this->clientFor($connection)->lastSeenAt);
        self::assertSame([], $sink, 'a pong is terminal; nothing echoes it back');

        $worker->livenessTick();
        self::assertNull($connection->onWebSocketConnected ?? null);
        self::assertSame([self::PING_WIRE], $sink);
    }

    public function testControlFramesAfterTeardownAnswerProtocolWithoutTouchingState(): void
    {
        $worker = $this->makeWorker();
        $sink = [];
        $connection = $this->authedClient($worker, 'token-a', $sink);
        // A control frame racing the event loop after the client map was torn
        // down: no stamp target exists, and that must not fatal — answering the
        // ping is protocol duty, not app state.
        SyncPlayRelayWorker::reset();

        $worker->onWebSocketPing($connection, 'q');
        $worker->onWebSocketPong($connection, '');

        self::assertSame(["\x8a\x01q"], $sink, 'pong answered, ping answered, pong echoed nothing');
    }

    // ---- the cure's payoff: a reaped ghost host unfreezes the party -------

    public function testReapedGhostHostElectsTheRemainingMember(): void
    {
        $worker = $this->makeWorker();

        $sinkHost = [];
        $host = $this->authedClient($worker, 'token-host', $sinkHost);
        $worker->onMessage($host, $this->canonicalFrame('syncplay_group_create', ['group_name' => 'party']));

        $sinkBee = [];
        $bee = $this->authedClient($worker, 'token-bee', $sinkBee);
        $worker->onMessage($bee, $this->canonicalFrame('syncplay_group_join', ['group_id' => 'party']));
        $beeId = $this->yourIdOf($sinkBee);

        // The host half-opens: silence to the reap threshold.
        $this->makeHandshakeComplete($host);
        $this->clientFor($host)->lastSeenAt = time() - SyncPlayRelayWorker::LIVENESS_REAP_AFTER_SILENCE_SECONDS;

        $host->expects($this->once())
            ->method('close')
            ->with(WebSocketCloseFrame::bytes(SyncPlayRelayWorker::CLOSE_LIVENESS_TIMEOUT), true);
        $worker->livenessTick();

        // Workerman fires onClose after the close completes — the canonical
        // leave path is what a graceful disconnect already proved (see
        // SyncPlayRelayCanonicalDialectTest); the reap must reach it unchanged.
        $worker->onClose($host);

        $elect = $this->firstSinkFrameOfType($sinkBee, 'syncplay_host_elect');
        self::assertIsArray($elect, 'the party must hear the election the reap triggered');
        self::assertSame($beeId, $elect['elected_id']);

        $states = $this->sinkFramesOfType($sinkBee, 'syncplay_group_state');
        $lastState = end($states);
        self::assertIsArray($lastState, 'post-election state must have landed');
        self::assertSame($beeId, $this->groupOfFrame($lastState)['host_id']);

        self::assertSame(1, SyncPlayRelayWorker::getActiveConnectionCount());
    }

    // ---- harness -----------------------------------------------------------

    private function makeWorker(): SyncPlayRelayWorker
    {
        return new SyncPlayRelayWorker(SyncPlayRelayWorker::DEFAULT_PORT, 1, $this->buildContainer());
    }

    /**
     * Grant the token, bind the server owner, and open one authenticated
     * recording client on `$worker` — the full production connect path minus
     * the network (same discipline as the canonical-dialect file).
     *
     * @param list<string> $sink
     */
    private function authedClient(
        SyncPlayRelayWorker $worker,
        string $token,
        array &$sink,
        string $userId = 'user-a',
        string $serverId = 'server-a',
    ): TcpConnection&MockObject {
        $this->grantToken($token, $userId, $serverId);
        $this->setServerOwner($serverId, $userId);

        $connection = $this->makeRecordingConnection($sink);
        $worker->onWebSocketConnect($connection, $this->makeUpgradeRequest("/syncplay/{$serverId}", $token));

        return $connection;
    }

    /**
     * The registered SyncPlayClient behind a mocked socket — the map is a
     * private static; reflection is how a unit test drives the silence clock
     * without waiting on it.
     */
    private function clientFor(TcpConnection $connection): SyncPlayClient
    {
        /** @var array<int, SyncPlayClient> $clients */
        $clients = (new ReflectionClass(SyncPlayRelayWorker::class))->getStaticPropertyValue('clients');

        $client = $clients[spl_object_id($connection)] ?? null;
        self::assertInstanceOf(
            SyncPlayClient::class,
            $client,
            'the connection was never registered — auth failed inside the harness',
        );

        return $client;
    }

    /**
     * Mark the mock's handshake complete so reject() takes the PRODUCTION
     * immediate-close branch (a liveness reap only ever meets live sockets).
     */
    private function makeHandshakeComplete(TcpConnection $connection): void
    {
        $context = new stdClass();
        $context->websocketHandshake = 'open';
        $connection->context = $context;
    }

    private function grantToken(string $token, string $userId, string $serverId): void
    {
        $this->validTokens[hash('sha256', $token)] = ['user_id' => $userId, 'server_id' => $serverId];
    }

    private function setServerOwner(string $serverId, string $userId): void
    {
        $this->serverOwners[$serverId] = $userId;
    }

    /**
     * Build a mock TcpConnection whose send() appends every frame written to
     * the socket into $sink (by reference) — raw control frames included.
     *
     * @param list<string> $sink
     */
    private function makeRecordingConnection(array &$sink): TcpConnection&MockObject
    {
        $connection = $this->createMock(TcpConnection::class);
        $connection->method('send')->willReturnCallback(
            function (mixed $data) use (&$sink): bool {
                if (is_string($data)) {
                    $sink[] = $data;
                }
                return true;
            },
        );
        return $connection;
    }

    private function buildContainer(): ContainerInterface
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []): array {
                if (!str_contains($sql, 'SELECT')) {
                    return [];
                }
                $tokenHash = $params['token_hash'] ?? null;
                if (is_string($tokenHash) && isset($this->validTokens[$tokenHash])) {
                    return [$this->validTokens[$tokenHash]];
                }
                return [];
            },
        );
        $tokenService = new ClientRelayTokenService($db);

        $serverInfo = $this->createMock(ServerInfoHandler::class);
        $serverInfo->method('getOwnerAndStatus')->willReturnCallback(
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

        return new class ($tokenService, $serverInfo) implements ContainerInterface {
            public function __construct(
                private readonly ClientRelayTokenService $tokenService,
                private readonly ServerInfoHandler $serverInfo,
            ) {
            }

            public function get(string $id): mixed
            {
                return match ($id) {
                    ClientRelayTokenService::class => $this->tokenService,
                    ServerInfoHandler::class => $this->serverInfo,
                    default => throw new RuntimeException("Unknown service: {$id}"),
                };
            }

            public function has(string $id): bool
            {
                return in_array($id, [
                    ClientRelayTokenService::class,
                    ServerInfoHandler::class,
                ], true);
            }
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function canonicalFrame(string $type, array $payload): string
    {
        return (string) json_encode(array_merge(['type' => $type], $payload));
    }

    /**
     * @param list<string> $sink
     *
     * @return array<string, mixed>|null
     */
    private function firstSinkFrameOfType(array $sink, string $type): ?array
    {
        foreach ($this->sinkFramesOfType($sink, $type) as $frame) {
            return $frame;
        }

        return null;
    }

    /**
     * Decodes ONLY the JSON-object writes; control frames (the ping bytes) are
     * silently skipped — their absence/presence is asserted on the raw sink.
     *
     * @param list<string> $sink
     *
     * @return list<array<string, mixed>>
     */
    private function sinkFramesOfType(array $sink, string $type): array
    {
        $frames = [];
        foreach ($sink as $raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && ($decoded['type'] ?? null) === $type) {
                /** @var array<string, mixed> $decoded */
                $frames[] = $decoded;
            }
        }

        return $frames;
    }

    /**
     * @param array<string, mixed> $frame
     *
     * @return array<string, mixed>
     */
    private function groupOfFrame(array $frame): array
    {
        self::assertIsArray($frame['group'] ?? null, 'group_state nests its payload under `group`');

        /** @var array<string, mixed> $group */
        $group = $frame['group'];

        return $group;
    }

    /**
     * @param list<string> $sink
     */
    private function yourIdOf(array $sink): string
    {
        $state = $this->firstSinkFrameOfType($sink, 'syncplay_group_state');
        self::assertIsArray($state, 'expected a group_state carrying your_id');
        self::assertIsString($state['your_id'] ?? null);

        return (string) $state['your_id'];
    }

    /**
     * Real upgrade-request auth path, `Authorization: Bearer` carrier only —
     * the carriers themselves belong to {@see SyncPlayRelayWorkerTest}.
     */
    private function makeUpgradeRequest(string $path, string $token): WorkermanRequest
    {
        $lines = [
            "GET {$path} HTTP/1.1",
            'Host: hub.example.com',
            'Upgrade: websocket',
            'Connection: Upgrade',
            'Sec-WebSocket-Version: 13',
            'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==',
            'Authorization: Bearer ' . $token,
        ];
        $raw = implode("\r\n", $lines) . "\r\n\r\n";

        return new WorkermanRequest($raw);
    }
}
