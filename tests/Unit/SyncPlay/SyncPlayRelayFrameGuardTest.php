<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\SyncPlay;

use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Hub\ClientRelayTokenService;
use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\SyncPlay\SyncPlayRelayWorker;
use Phlix\Hub\Tests\Support\LoggerFactoryIsolation;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Workerman\Connection\TcpConnection;
use Workerman\MySQL\Connection;
use Workerman\Protocols\Http\Request as WorkermanRequest;

use function array_filter;
use function array_merge;
use function array_slice;
use function count;
use function file_get_contents;
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
use function str_contains;
use function substr_count;
use function sys_get_temp_dir;
use function unlink;
use function uniqid;

/**
 * Per-frame dispatch guard tests (review hub__asphp M3 / M-HUB-03).
 *
 * The :8804 relay worker runs count=1: before the guard existed, ONE frame
 * whose handler threw escaped {@see SyncPlayRelayWorker::onMessage()} and
 * Workerman took the sole process down — every relayed room died with it.
 * The guard absorbs a fan-out throw per frame: log (routing facts only,
 * never payload), answer a canonical socket with the registered
 * `HANDLER_ERROR` syncplay_error (zero new wire codes), stay silent on a
 * bare socket (the hub's bare dialect has no error-frame path), and keep
 * the worker and every OTHER connection alive and bookable.
 *
 * The throwers below are REAL production paths, not mocks: payloads crafted
 * to pass every parse edge (valid JSON object, string type, budget intact)
 * and then hit a `json_encode(..., JSON_THROW_ON_ERROR)` inside the handler.
 * `1e999` decodes to float INF with no json_decode error, and no sanitizer
 * on these arms rejects it before the encoder does:
 *   - bare `time_sync` echoes `client_time` raw (handleTimeSync);
 *   - canonical `syncplay_playback_queue` keeps media_info values as-is
 *     (parse-don't-validate passthrough), and the fan-out re-encode throws.
 *
 * Mutation teeth: delete the guard (let the switch throw out of onMessage)
 * and every test here reddens with the escaped JsonException; drop the
 * canonical reply and test 1 reddens; invent a bare error frame and test 2
 * reddens; strip the inner reply-failure catch and test 3 reddens.
 *
 * Harness discipline is copied from {@see SyncPlayRelayCanonicalDialectTest}
 * (real upgrade-request auth path, real ClientRelayTokenService over a
 * mocked DB, recording sinks) with the file-backed RELAY log of the
 * Federation tests, because the log lines are load-bearing assertions here.
 *
 * @package Phlix\Hub\Tests\Unit\SyncPlay
 */
final class SyncPlayRelayFrameGuardTest extends TestCase
{
    // LoggerFactory's static $configPath/$loggers are process-global; the trait
    // snapshots them before setUp() and restores them after tearDown().
    use LoggerFactoryIsolation;

    /**
     * Value placed inside the throwing payloads. It reaches the handler and
     * the broadcast encoder — if it ever shows up in the RELAY log, the
     * guard is logging payloads (the estate's log-leak class).
     */
    private const CANARY = 'leak-canary-media-9';

    private string $tmpDir;

    private string $logFile = '';

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

        // Process-global static room/client maps: reset so a prior test cannot
        // leak state into this one.
        SyncPlayRelayWorker::reset();

        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-syncplay-guard-test-' . uniqid();
        mkdir($this->tmpDir, 0700, true);
        $this->logFile = $this->tmpDir . '/relay.log';

        $loggerConfig = $this->tmpDir . '/logger.php';
        file_put_contents(
            $loggerConfig,
            "<?php return ['default' => 'mem', 'handlers' => ['mem' => "
            . "['type' => 'stream', 'path' => '" . $this->logFile . "', 'level' => 'debug']]];",
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

    // ---- Canonical socket: logged, answered, everyone else untouched --------

    public function testThrowingCanonicalHandlerAbsorbedWithErrorFrameAndRoommatesAlive(): void
    {
        $worker = $this->makeWorker();
        $sinkA = [];
        $connA = $this->authedClient($worker, 'token-a', $sinkA);
        $sinkB = [];
        $connB = $this->authedClient($worker, 'token-b', $sinkB);

        // Room setup over the NORMAL path: A creates (latches both sockets
        // canonical, A becomes host), B joins.
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_group_create', [
            'group_name' => 'party',
            'member_name' => 'Alpha',
        ]));
        self::assertNotNull(
            $this->firstFrameOfType($sinkA, 'syncplay_group_state'),
            'the normal path must work for the throw test to mean anything',
        );
        $worker->onMessage($connB, $this->canonicalFrame('syncplay_group_join', [
            'group_id' => 'party',
            'member_name' => 'Bravo',
        ]));

        $seenA = count($sinkA);
        $seenB = count($sinkB);

        // The poison frame: passes every edge, host-gate passes, media_info
        // values pass through raw, and the fan-out re-encode throws. Written
        // as literal wire text on purpose: `1e999` decodes to float INF with
        // no json_decode error (a PHP-side json_encode of INF would instead
        // produce invalid "Infinity" text that the parse edge rightfully
        // drops — never reaching a handler at all).
        $poison = '{"type":"syncplay_playback_queue","queue":[{"media_id":"'
            . self::CANARY . '","media_info":{"depth":1e999}}]}';
        $worker->onMessage($connA, $poison);
        // Reaching this line IS the first assertion: no throw escaped
        // onMessage(), the count=1 worker survived the frame.

        // The offender is answered in its own dialect with the registered
        // wire code — generic text, never the exception message.
        $frames = array_slice($sinkA, $seenA);
        self::assertCount(1, $frames, 'a canonical offender gets exactly one reply');
        /** @var array<string, mixed> $error */
        $error = json_decode($frames[0], true);
        self::assertSame('syncplay_error', $error['type'] ?? null);
        self::assertSame(
            'HANDLER_ERROR',
            $error['error_code'] ?? null,
            'registered contracts code, zero new wire codes',
        );
        self::assertSame('Internal relay error', $error['message'] ?? null);
        self::assertSame(1, $error['protocol_version'] ?? null);

        // Roommate isolation: B received NOTHING from the poison frame and
        // its connection was never torn down.
        self::assertSame($seenB, count($sinkB), 'the failed broadcast must not partially deliver');
        $worker->onMessage($connB, $this->canonicalFrame('syncplay_time_ping', ['client_time' => 777]));
        $pong = $this->firstFrameOfType(array_slice($sinkB, $seenB), 'syncplay_time_pong');
        self::assertNotNull($pong, 'B must still dispatch normally after A poisoned the room');
        self::assertSame(777, $pong['client_time'] ?? null);
        self::assertSame(2, SyncPlayRelayWorker::getActiveConnectionCount(), 'neither socket was closed');

        // Log law: the failure is on the RELAY channel with routing facts,
        // and payload values are nowhere in it.
        $log = (string) file_get_contents($this->logFile);
        self::assertSame(
            1,
            substr_count($log, 'SyncPlay: relay message handler error'),
            'exactly one absorbed-failure line per poison frame',
        );
        self::assertStringContainsString('syncplay_playback_queue', $log, 'the frame type is the routing fact');
        self::assertStringContainsString('server-a', $log);
        self::assertStringNotContainsString(self::CANARY, $log, 'payload values must never reach the log');
        self::assertStringNotContainsString('media_info', $log, 'the raw frame body must never reach the log');
    }

    // ---- Bare socket: honest log-and-drop, no invented error vocabulary -----

    public function testThrowingBareHandlerIsLoggedAndDroppedWithoutErrorFrame(): void
    {
        $worker = $this->makeWorker();
        $sinkC = [];
        $connC = $this->authedClient($worker, 'token-c', $sinkC);

        // handleTimeSync echoes client_time raw into a THROW_ON_ERROR encode;
        // 1e999 → INF → JsonException. The `room` field is ignored by the arm
        // and serves purely as a payload canary for the log assertions.
        $worker->onMessage($connC, '{"type":"time_sync","client_time":1e999,"room":"canary-bare-room-7"}');

        // Bare dialect law: the hub's bare vocabulary has no error-frame path
        // — an absorbed throw must be SILENT on the wire, not a new message
        // shape clients never learned.
        self::assertSame([], $sinkC, 'bare sockets are answered with nothing; the drop is honest');

        // Still alive, still serving: the next legitimate frame round-trips.
        $worker->onMessage($connC, '{"type":"time_sync","client_time":5,"room":"canary-bare-room-7"}');
        $reply = $this->firstFrameOfType($sinkC, 'time_sync_reply');
        self::assertNotNull($reply, 'the surviving worker must answer the next frame normally');
        self::assertSame(5, $reply['client_time'] ?? null);
        self::assertSame(1, SyncPlayRelayWorker::getActiveConnectionCount());

        $log = (string) file_get_contents($this->logFile);
        self::assertStringContainsString('SyncPlay: relay message handler error', $log);
        self::assertStringContainsString('"type":"time_sync"', $log);
        self::assertStringNotContainsString('canary-bare-room-7', $log, 'payload values must never reach the log');
    }

    // ---- The error reply itself must never escalate -------------------------

    public function testFailingErrorReplyDoesNotEscalateBackIntoDispatch(): void
    {
        $worker = $this->makeWorker();
        $sinkA = [];
        $connA = $this->explodingOnErrorClient($worker, 'token-a', $sinkA);

        // Normal create over the flaky socket: the group_state send succeeds.
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_group_create', [
            'group_name' => 'party',
            'member_name' => 'Alpha',
        ]));
        self::assertNotNull($this->firstFrameOfType($sinkA, 'syncplay_group_state'));

        // Poison frame → handler throws → guard tries its syncplay_error
        // reply → that send throws too. The inner catch must swallow it:
        // reaching the next line is the assertion. (Literal wire text; see
        // the first test's note on why PHP-side INF is not encodable here.)
        $worker->onMessage($connA, '{"type":"syncplay_playback_queue","queue":[{"media_id":"'
            . self::CANARY . '","media_info":{"depth":1e999}}]}');

        self::assertSame(
            [],
            array_filter($sinkA, static fn (string $f): bool => str_contains($f, 'syncplay_error')),
            'the failed error frame must not have been (partially) delivered',
        );
        self::assertSame(1, SyncPlayRelayWorker::getActiveConnectionCount(), 'a dead reply socket must not escalate');
        $log = (string) file_get_contents($this->logFile);
        self::assertStringContainsString(
            'SyncPlay: relay message handler error',
            $log,
            'the original thrower is still on the channel',
        );
    }

    // =====================================================================
    // Harness (mirrors SyncPlayRelayCanonicalDialectTest; the file-backed log
    // follows the Federation tests' established stream-handler pattern).
    // =====================================================================

    private function makeWorker(): SyncPlayRelayWorker
    {
        return new SyncPlayRelayWorker(SyncPlayRelayWorker::DEFAULT_PORT, 1, $this->buildContainer());
    }

    /**
     * Connect an authenticated client whose sends are recorded into $sink.
     *
     * @param array<int, string> $sink
     */
    private function authedClient(
        SyncPlayRelayWorker $worker,
        string $token,
        array &$sink,
        string $userId = 'user-a',
        string $serverId = 'server-a',
    ): TcpConnection {
        $this->grantToken($token, $userId, $serverId);
        $this->setServerOwner($serverId, $userId);

        $connection = $this->makeRecordingConnection($sink);
        $worker->onWebSocketConnect($connection, $this->makeUpgradeRequest("/syncplay/{$serverId}", $token));

        return $connection;
    }

    /**
     * Like {@see self::authedClient()} but every send of a syncplay_error
     * frame throws, simulating a socket that died between the handler
     * failure and its error reply.
     *
     * @param array<int, string> $sink
     */
    private function explodingOnErrorClient(
        SyncPlayRelayWorker $worker,
        string $token,
        array &$sink,
        string $userId = 'user-a',
        string $serverId = 'server-a',
    ): TcpConnection {
        $this->grantToken($token, $userId, $serverId);
        $this->setServerOwner($serverId, $userId);

        $connection = $this->createMock(TcpConnection::class);
        $connection->method('send')->willReturnCallback(
            function (mixed $data) use (&$sink): bool {
                if (is_string($data)) {
                    if (str_contains($data, 'syncplay_error')) {
                        throw new RuntimeException('socket died while replying');
                    }
                    $sink[] = $data;
                }

                return true;
            },
        );
        $worker->onWebSocketConnect($connection, $this->makeUpgradeRequest("/syncplay/{$serverId}", $token));

        return $connection;
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
     * @param array<string, mixed> $payload
     */
    private function canonicalFrame(string $type, array $payload): string
    {
        /** @var array<string, mixed> $frame */
        $frame = array_merge(['type' => $type], $payload);

        return (string) json_encode($frame);
    }

    /**
     * First decoded frame of $type recorded in $sink, or null.
     *
     * @param array<int, string> $sink
     *
     * @return array<string, mixed>|null
     */
    private function firstFrameOfType(array $sink, string $type): ?array
    {
        foreach ($sink as $raw) {
            /** @var array<string, mixed>|null $decoded */
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && ($decoded['type'] ?? null) === $type) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $sink
     */
    private function makeRecordingConnection(array &$sink): TcpConnection
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
        // ClientRelayTokenService is final and cannot be mocked; build a real
        // one over a mock Connection whose query() resolves the token lookup
        // against $validTokens (keyed by the sha256 token_hash param, exactly
        // as production filters).
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []): array {
                if (!str_contains($sql, 'SELECT')) {
                    return [];
                }
                $hash = $params['token_hash'] ?? null;
                if (is_string($hash) && isset($this->validTokens[$hash])) {
                    return [$this->validTokens[$hash]];
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

    private function makeUpgradeRequest(string $path, string $token): WorkermanRequest
    {
        $headers = [
            'Host' => 'hub.example.com',
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Version' => '13',
            'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ==',
            'Authorization' => 'Bearer ' . $token,
        ];

        $lines = ["GET {$path} HTTP/1.1"];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }

        return new WorkermanRequest(implode("\r\n", $lines) . "\r\n\r\n");
    }
}
