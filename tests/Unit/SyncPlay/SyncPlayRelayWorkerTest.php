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
use Workerman\Connection\TcpConnection;
use Workerman\MySQL\Connection;
use Workerman\Protocols\Http\Request as WorkermanRequest;

use function hash;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;

/**
 * Unit tests for {@see SyncPlayRelayWorker} — the SyncPlay relay path (:8804).
 *
 * Covers the three HB-3.2 acceptance criteria:
 *   1. auth-required   — an unauthenticated/bad-token connect is CLOSED.
 *   2. ownership-scope  — two authenticated clients on DIFFERENT (server_id,
 *      owner) that pick the SAME friendly room name do NOT share a room; a
 *      playback broadcast from one is NEVER delivered to the other. (This is
 *      the security guard; it FAILS against the pre-fix raw-key code.)
 *   3. legitimate-flow — two authenticated clients on the SAME server/owner in
 *      the same room DO share it and receive each other's playback control.
 *
 * The worker authenticates from `$_GET['token']` in `onWebSocketConnect`,
 * validating the relay token via {@see ClientRelayTokenService} + re-confirming
 * ownership via {@see ServerInfoHandler}, mirroring {@see ClientRelayWorker}.
 *
 * @package Phlix\Hub\Tests\Unit\SyncPlay
 *
 * @covers \Phlix\Hub\SyncPlay\SyncPlayRelayWorker
 */
final class SyncPlayRelayWorkerTest extends TestCase
{
    // LoggerFactory's static $configPath/$loggers are process-global; the trait
    // snapshots them before setUp() and restores them after tearDown().
    use LoggerFactoryIsolation;

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

    /** Saved copy of $_GET restored in tearDown. */
    private array $savedGet = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Process-global static room/client maps: reset so a prior test cannot
        // leak state into this one.
        SyncPlayRelayWorker::reset();

        $this->savedGet = $_GET;

        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-syncplay-test-' . uniqid();
        mkdir($this->tmpDir, 0700, true);

        // Point the static LoggerFactory at an in-memory stream so tests do not
        // write real log files or emit output.
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
        $_GET = $this->savedGet;

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

    // ---- AC1: auth-required ----------------------------------------------

    public function testConnectWithNoTokenIsRejected(): void
    {
        $this->setServerOwner('server-a', 'user-a');
        $worker = new SyncPlayRelayWorker(SyncPlayRelayWorker::DEFAULT_PORT, 1, $this->buildContainer());

        unset($_GET['token']);
        $request = $this->makeUpgradeRequest('/syncplay/server-a');

        $connection = $this->createMock(TcpConnection::class);
        $connection->expects($this->once())->method('close')->with('', true);

        $worker->onWebSocketConnect($connection, $request);

        // No client should have been registered for an unauthenticated connect.
        self::assertSame(0, SyncPlayRelayWorker::getActiveConnectionCount());
    }

    public function testConnectWithInvalidTokenIsRejected(): void
    {
        $this->setServerOwner('server-a', 'user-a');
        $worker = new SyncPlayRelayWorker(SyncPlayRelayWorker::DEFAULT_PORT, 1, $this->buildContainer());

        $_GET['token'] = 'never-minted';
        $request = $this->makeUpgradeRequest('/syncplay/server-a');

        $connection = $this->createMock(TcpConnection::class);
        $connection->expects($this->once())->method('close')->with('', true);

        $worker->onWebSocketConnect($connection, $request);

        self::assertSame(0, SyncPlayRelayWorker::getActiveConnectionCount());
    }

    // ---- AC2: ownership scoping (the security guard) ---------------------

    /**
     * Two authenticated clients on DIFFERENT (server_id, owner) that pick the
     * SAME friendly room name must NOT share a room. A playback broadcast from
     * one must never reach the other.
     *
     * This FAILS against the pre-fix raw-key code (`self::$rooms['movie-night']`
     * shared across servers/owners), proving cross-user room join was real.
     */
    public function testDifferentServerOwnerSameRoomNameDoNotShareRoom(): void
    {
        // Client A: owns server-a.
        $this->grantToken('token-a', 'user-a', 'server-a');
        $this->setServerOwner('server-a', 'user-a');
        // Client B: owns a DIFFERENT server-b (different owner too).
        $this->grantToken('token-b', 'user-b', 'server-b');
        $this->setServerOwner('server-b', 'user-b');

        $worker = new SyncPlayRelayWorker(SyncPlayRelayWorker::DEFAULT_PORT, 1, $this->buildContainer());

        $sinkA = [];
        $sinkB = [];
        $connA = $this->makeRecordingConnection($sinkA);
        $connB = $this->makeRecordingConnection($sinkB);

        // Both authenticate + connect on their own server path.
        $this->connect($worker, $connA, '/syncplay/server-a', 'token-a');
        $this->connect($worker, $connB, '/syncplay/server-b', 'token-b');

        // Both join the SAME friendly room name.
        $worker->onMessage($connA, $this->joinFrame('movie-night', 'Alice'));
        $worker->onMessage($connB, $this->joinFrame('movie-night', 'Bob'));

        // They resolve to two DIFFERENT scoped rooms.
        self::assertSame(2, SyncPlayRelayWorker::getActiveRoomCount());

        // A issues a playback control; it must NEVER reach B.
        $worker->onMessage($connA, $this->playFrame());

        self::assertFalse(
            $this->sinkHasType($sinkB, 'playback_play'),
            'cross-(server_id,owner) playback control leaked into another user\'s room',
        );
    }

    // ---- AC3: legitimate flow --------------------------------------------

    /**
     * Two authenticated clients on the SAME server/owner joining the same room
     * DO share it and receive each other's playback control end-to-end.
     */
    public function testSameServerOwnerSameRoomShareAndReceivePlayback(): void
    {
        $this->grantToken('token-a', 'user-a', 'server-a');
        $this->grantToken('token-c', 'user-a', 'server-a'); // same owner, same server
        $this->setServerOwner('server-a', 'user-a');

        $worker = new SyncPlayRelayWorker(SyncPlayRelayWorker::DEFAULT_PORT, 1, $this->buildContainer());

        $sinkA = [];
        $sinkC = [];
        $connA = $this->makeRecordingConnection($sinkA);
        $connC = $this->makeRecordingConnection($sinkC);

        $this->connect($worker, $connA, '/syncplay/server-a', 'token-a');
        $this->connect($worker, $connC, '/syncplay/server-a', 'token-c');

        $worker->onMessage($connA, $this->joinFrame('movie-night', 'Alice'));
        $worker->onMessage($connC, $this->joinFrame('movie-night', 'Carol'));

        // Both share ONE scoped room.
        self::assertSame(1, SyncPlayRelayWorker::getActiveRoomCount());

        // C should have been notified of A already present via room_state.
        self::assertTrue($this->sinkHasType($sinkC, 'room_state'));

        // A issues a playback control; C (same room) must receive it.
        $worker->onMessage($connA, $this->playFrame());

        self::assertTrue(
            $this->sinkHasType($sinkC, 'playback_play'),
            'legitimate same-server/owner room did not deliver playback control',
        );
    }

    public function testGroupLeaveEmptiesTheScopedRoomAndCleansUp(): void
    {
        $this->grantToken('token-a', 'user-a', 'server-a');
        $this->setServerOwner('server-a', 'user-a');

        $worker = new SyncPlayRelayWorker(SyncPlayRelayWorker::DEFAULT_PORT, 1, $this->buildContainer());

        $sinkA = [];
        $connA = $this->makeRecordingConnection($sinkA);
        $this->connect($worker, $connA, '/syncplay/server-a', 'token-a');

        $worker->onMessage($connA, $this->joinFrame('movie-night', 'Alice'));
        self::assertSame(1, SyncPlayRelayWorker::getActiveRoomCount());

        // Disconnect removes the client from its room (room becomes empty) and
        // drops the client from the live set (no unbounded static growth).
        $worker->onClose($connA);
        self::assertSame(0, SyncPlayRelayWorker::getActiveConnectionCount());
    }

    // ---- Helpers ---------------------------------------------------------

    private function connect(
        SyncPlayRelayWorker $worker,
        TcpConnection $connection,
        string $path,
        string $token,
    ): void {
        $_GET['token'] = $token;
        $worker->onWebSocketConnect($connection, $this->makeUpgradeRequest($path));
    }

    private function joinFrame(string $room, string $displayName): string
    {
        return (string) json_encode([
            'type' => 'group_join',
            'room' => $room,
            'display_name' => $displayName,
        ]);
    }

    private function playFrame(): string
    {
        return (string) json_encode([
            'type' => 'playback_play',
            'position' => 12.5,
        ]);
    }

    /**
     * @param list<string> $sink
     */
    private function sinkHasType(array $sink, string $type): bool
    {
        foreach ($sink as $raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && ($decoded['type'] ?? null) === $type) {
                return true;
            }
        }
        return false;
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
     * Build a mock TcpConnection whose send() appends every JSON frame it is
     * asked to write into $sink (by reference).
     *
     * @param list<string> $sink
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
                    default => throw new \RuntimeException("Unknown service: {$id}"),
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
     * @param string $path Request path (with optional query).
     */
    private function makeUpgradeRequest(string $path): WorkermanRequest
    {
        $headers = [
            'Host' => 'hub.example.com',
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Version' => '13',
            'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ==',
        ];

        $lines = ["GET {$path} HTTP/1.1"];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        $raw = implode("\r\n", $lines) . "\r\n\r\n";

        return new WorkermanRequest($raw);
    }
}
