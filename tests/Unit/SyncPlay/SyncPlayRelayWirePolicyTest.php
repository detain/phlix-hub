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

use function array_column;
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
use function mb_strlen;
use function mkdir;
use function rmdir;
use function str_contains;
use function str_repeat;
use function str_starts_with;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * Inbound wire-policy pins (minors campaign M-HUB-28/36/38/39/41).
 *
 * The :8804 relay used to accept whatever a member sent it, bounded only by
 * the byte budget: unbounded room size (a relay room could outgrow the
 * :8097 group it mirrors), silent throttle drops the sender could not see,
 * unbounded display names and chat text echoed to every member, verbatim
 * relay of hub-shaped FORGERIES through the extension seam, and a parse
 * depth the sender controlled. Each cure is pinned here on the real worker
 * (no mocks below the connection), over the recording-socket harness
 * copied from {@see SyncPlayRelayFrameGuardTest}.
 *
 * Mutation teeth: strip the room cap → the refused-51st tests see joins
 * succeed (red); remove the one-shot throttle announce → the canonical
 * throttle test sees zero rate_limited frames (red) and its repeat trips
 * see one per frame (red); delete the sanitizer → control chars and
 * 200-character names sail into room_state (red); drop the hub-owned
 * refusal → the forged room_state relays to the roommate (red) while the
 * unknown-name control proves the refusal is name-scoped; remove the depth
 * bound → the deep frame relays (red) while the depth-10 control proves
 * the bound is what refused the deep one.
 *
 * @package Phlix\Hub\Tests\Unit\SyncPlay
 */
final class SyncPlayRelayWirePolicyTest extends TestCase
{
    use LoggerFactoryIsolation;

    private string $tmpDir;

    private string $logFile = '';

    /**
     * @var array<string, array{user_id: string, server_id: string}>
     */
    private array $validTokens = [];

    /**
     * @var array<string, string>
     */
    private array $serverOwners = [];

    protected function setUp(): void
    {
        parent::setUp();

        SyncPlayRelayWorker::reset();

        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-syncplay-wire-policy-' . uniqid();
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

        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    // ---- M-HUB-41: parse depth is bounded -----------------------------------

    public function testFrameDeeperThanMaxInboundJsonDepthIsDroppedWithoutRelay(): void
    {
        $worker = $this->makeWorker();
        $sinkC = [];
        $connC = $this->authedClient($worker, 'token-c', $sinkC);
        $sinkD = [];
        $connD = $this->authedClient($worker, 'token-d', $sinkD);

        // Room over the bare path (same owner scope) so the extension-seam
        // relay is the delivery vector the refusal must stop.
        $worker->onMessage($connC, '{"type":"group_join","room":"depth","display_name":"C"}');
        $worker->onMessage($connD, '{"type":"group_join","room":"depth","display_name":"D"}');
        self::assertNotNull($this->firstFrameOfType($sinkD, 'room_state'));

        // 16 nested arrays under the root object = depth 17 > the bound: the
        // decode fails → malformed-drop. D must receive NOTHING.
        $deep = '{"type":"plugin_deep","d":' . str_repeat('[', 16) . '1' . str_repeat(']', 16) . '}';
        $seenBefore = count($sinkD);
        $worker->onMessage($connC, $deep);
        self::assertSame($seenBefore, count($sinkD), 'an over-depth frame must not reach the room');

        // Control: the SAME extension name at depth 10 clears the bound and
        // takes the documented relay — the refusal above was the depth.
        $ok = '{"type":"plugin_deep","d":' . str_repeat('[', 9) . '1' . str_repeat(']', 9) . '}';
        $worker->onMessage($connC, $ok);
        $relay = array_slice($sinkD, $seenBefore);
        self::assertSame([$ok], $relay, 'the seam stays verbatim inside the bound');
    }

    // ---- M-HUB-36: throttle answers canonical once, bare never -------------

    public function testCanonicalSocketGetsOneRateLimitedAnnouncementPerConnection(): void
    {
        $worker = $this->makeWorker(rate: 1.0, burst: 1.0);
        $sink = [];
        $conn = $this->authedClient($worker, 'token-a', $sink);

        $frame = $this->canonicalFrame('syncplay_group_create', ['group_name' => 'party']);
        $worker->onMessage($conn, $frame); // first frame: seeded bucket pays
        $worker->onMessage($conn, $frame); // throttled — trip 1
        $worker->onMessage($conn, $frame); // throttled — trip 2
        $worker->onMessage($conn, $frame); // throttled — trip 3

        $announcements = $this->framesOfType($sink, 'syncplay_error', 'rate_limited');
        self::assertCount(1, $announcements, 'exactly one wire announcement per connection');
        self::assertSame(
            'You are sending too fast; frames are being dropped',
            $announcements[0]['message'] ?? null,
        );
        // The dropped frames never reached the handler: only the first
        // create produced its group_state.
        self::assertCount(1, $this->framesOfType($sink, 'syncplay_group_state'));
    }

    public function testBareSocketThrottledStaysWireSilent(): void
    {
        $worker = $this->makeWorker(rate: 1.0, burst: 1.0);
        $sink = [];
        $conn = $this->authedClient($worker, 'token-bare', $sink);

        $join = '{"type":"group_join","room":"silent","display_name":"B"}';
        $worker->onMessage($conn, $join); // first frame passes: room_state back
        self::assertNotNull($this->firstFrameOfType($sink, 'room_state'));
        $seen = count($sink);

        $worker->onMessage($conn, $join);
        $worker->onMessage($conn, $join);
        self::assertSame($seen, count($sink), 'the bare dialect has no error-frame path — silence is the law');
    }

    // ---- M-HUB-28: rooms hold the server cap -------------------------------

    public function testRoomCapRefusesThe51stMemberInServerWordsAndBareSilence(): void
    {
        $worker = $this->makeWorker();

        // Fill the shared-scope room to exactly MAX_ROOM_MEMBERS: 1 canonical
        // creator + (cap - 1) canonical joiners.
        $firstSink = [];
        $connFirst = $this->authedClient($worker, 'token-fill-0', $firstSink);
        $worker->onMessage($connFirst, $this->canonicalFrame('syncplay_group_create', [
            'group_name' => 'crowded',
            'member_name' => 'Zero',
        ]));
        self::assertNotNull($this->firstFrameOfType($firstSink, 'syncplay_group_state'));

        $admitted = 1;
        for ($i = 1; $i < SyncPlayRelayWorker::MAX_ROOM_MEMBERS; $i++) {
            $sink = [];
            $conn = $this->authedClient($worker, 'token-fill-' . $i, $sink);
            $worker->onMessage($conn, $this->canonicalFrame('syncplay_group_join', [
                'group_id' => 'crowded',
                'member_name' => 'M' . $i,
            ]));
            self::assertNotNull($this->firstFrameOfType($sink, 'syncplay_group_state'), 'filler ' . $i . ' must join');
            $admitted++;
        }
        self::assertSame(SyncPlayRelayWorker::MAX_ROOM_MEMBERS, $admitted);

        // The 51st canonical join: refused with the SERVER's code and prose.
        $sinkLate = [];
        $connLate = $this->authedClient($worker, 'token-late', $sinkLate);
        $worker->onMessage($connLate, $this->canonicalFrame('syncplay_group_join', [
            'group_id' => 'crowded',
            'member_name' => 'Late',
        ]));
        $errors = $this->framesOfType($sinkLate, 'syncplay_error');
        self::assertCount(1, $errors, 'nothing else may reach a refused joiner');
        self::assertSame('syncplay.group_full', $errors[0]['error_code'] ?? null);
        self::assertSame('Group is full', $errors[0]['message'] ?? null, "the server's exact refusal words");
        self::assertNull($this->firstFrameOfType($sinkLate, 'syncplay_group_state'));

        // A bare join into the same full room: silent (dialect law) and
        // warning-logged (the attempt is visible to operators).
        $sinkBare = [];
        $connBare = $this->authedClient($worker, 'token-bare-join', $sinkBare);
        $worker->onMessage($connBare, '{"type":"group_join","room":"crowded","display_name":"Ghost"}');
        self::assertSame([], $sinkBare, 'bare refusal is silence on the wire');
        $log = (string) file_get_contents($this->logFile);
        self::assertStringContainsString('SyncPlay: rejected group_join, room is full', $log);
    }

    // ---- M-HUB-38: names and chat are sanitized and capped ------------------

    public function testDisplayNameIsControlStrippedTrimmedAndCapped(): void
    {
        $worker = $this->makeWorker();
        $sinkA = [];
        $connA = $this->authedClient($worker, 'token-a', $sinkA);
        $sinkB = [];
        $connB = $this->authedClient($worker, 'token-b', $sinkB);

        $poison = "  ab\x00\x07\x1B" . str_repeat('Z', 200) . "\r\n";
        $worker->onMessage($connA, '{"type":"group_join","room":"names","display_name":'
            . json_encode($poison) . '}');
        $worker->onMessage($connB, '{"type":"group_join","room":"names","display_name":'
            . json_encode(" \t\n ") . '}');

        // B's own room_state lists both members: A stripped/trimmed/capped,
        // B whitespace-only → the documented fallback.
        $state = $this->firstFrameOfType($sinkB, 'room_state');
        self::assertNotNull($state);
        /** @var array<string, array{client_id: string, display_name: string}> $clients */
        $clients = $state['clients'] ?? [];
        $names = array_column($clients, 'display_name');
        self::assertContains('ab' . str_repeat('Z', 126), $names, '200 Zs truncate to the 128-codepoint cap');
        self::assertContains('Anonymous', $names, 'whitespace-only degrades to the documented fallback');
        foreach ($names as $name) {
            self::assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F-\x9F]/', $name);
            self::assertLessThanOrEqual(SyncPlayRelayWorker::MAX_DISPLAY_NAME_CHARS, mb_strlen($name));
        }

        // Canonical member_name takes the same law: create with 300 Qs.
        $sinkC = [];
        $connC = $this->authedClient($worker, 'token-c', $sinkC);
        $worker->onMessage($connC, $this->canonicalFrame('syncplay_group_create', [
            'group_name' => 'canames',
            'member_name' => str_repeat('Q', 300),
        ]));
        $groupState = $this->firstFrameOfType($sinkC, 'syncplay_group_state');
        self::assertNotNull($groupState);
        /** @var array<string, mixed> $group */
        $group = $groupState['group'] ?? [];
        /** @var array<string, array<string, mixed>> $members */
        $members = $group['members'] ?? [];
        // The server's member-dict field is `name` (GroupState::getState law).
        self::assertSame([str_repeat('Q', 128)], array_column($members, 'name'));
    }

    public function testChatIsControlStrippedExceptTabNewlineAndTruncatedAtCap(): void
    {
        $worker = $this->makeWorker();
        $sinkA = [];
        $connA = $this->authedClient($worker, 'token-a', $sinkA);
        $sinkB = [];
        $connB = $this->authedClient($worker, 'token-b', $sinkB);

        $worker->onMessage($connA, $this->canonicalFrame('syncplay_group_create', [
            'group_name' => 'chat',
            'member_name' => 'Alpha',
        ]));
        $worker->onMessage($connB, $this->canonicalFrame('syncplay_group_join', [
            'group_id' => 'chat',
            'member_name' => 'Bravo',
        ]));
        $seenB = count($sinkB);

        $message = "hi\nthe\x00re\x07\t" . str_repeat('M', 2500);
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_chat', ['message' => $message]));

        $chats = $this->framesOfType(array_slice($sinkB, $seenB), 'syncplay_chat');
        self::assertCount(1, $chats);
        $text = $chats[0]['message'] ?? '';
        self::assertIsString($text);
        self::assertTrue(str_starts_with($text, "hi\nthe"), 'newline survives');
        self::assertStringNotContainsString("\x00", $text);
        self::assertStringNotContainsString("\x07", $text);
        self::assertTrue(str_contains($text, "\t"), 'tab survives');
        self::assertSame(
            SyncPlayRelayWorker::MAX_CHAT_MESSAGE_CHARS,
            mb_strlen($text),
            'truncated at the cap, not refused'
        );

        // Control-char-only chat sanitizes to '' and takes the silent path.
        $seenB2 = count($sinkB);
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_chat', ['message' => "\x01\x02\x03"]));
        self::assertSame($seenB2, count($sinkB), 'nothing fans out for a sanitized-blank message');
    }

    // ---- M-HUB-39: the seam relays unknown names, never hub responses ------

    public function testDefaultRelayRefusesHubOwnedBareTypesAndKeepsUnknownOnes(): void
    {
        $worker = $this->makeWorker();
        $sinkC = [];
        $connC = $this->authedClient($worker, 'token-c', $sinkC);
        $sinkD = [];
        $connD = $this->authedClient($worker, 'token-d', $sinkD);

        // Same owner scope, one roommate pair.
        $worker->onMessage($connC, '{"type":"group_join","room":"seam","display_name":"C"}');
        $worker->onMessage($connD, '{"type":"group_join","room":"seam","display_name":"D"}');
        $seenD = count($sinkD);

        // A member FORGING each hub-owned response name: none may fan out.
        foreach (['room_state', 'client_joined', 'client_left', 'time_sync_reply'] as $forged) {
            $worker->onMessage($connC, '{"type":"' . $forged . '","clients":{"evil":{"display_name":"Pwn"}}}');
        }
        self::assertSame($seenD, count($sinkD), 'no hub-shaped forgery reaches the room');

        // Unknown extension names STILL relay verbatim — the documented seam.
        $extension = '{"type":"plugin_liveness_probe","seq":1}';
        $worker->onMessage($connC, $extension);
        $relay = array_slice($sinkD, $seenD);
        self::assertSame([$extension], $relay, 'the seam itself must stay byte-for-byte');
    }

    // =====================================================================
    // Harness (copied from SyncPlayRelayFrameGuardTest — same auth path,
    // recording sinks, file-backed RELAY log).
    // =====================================================================

    private function makeWorker(float $rate = 131072.0, float $burst = 262144.0): SyncPlayRelayWorker
    {
        return new SyncPlayRelayWorker(
            SyncPlayRelayWorker::DEFAULT_PORT,
            1,
            $this->buildContainer(),
            inboundRateBytesPerSecond: $rate,
            inboundBurstBytes: $burst,
        );
    }

    /**
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
     * Every decoded frame of $type (optionally filtered by error_code).
     *
     * @param array<int, string> $sink
     *
     * @return array<int, array<string, mixed>>
     */
    private function framesOfType(array $sink, string $type, ?string $errorCode = null): array
    {
        $frames = [];
        foreach ($sink as $raw) {
            /** @var array<string, mixed>|null $decoded */
            $decoded = json_decode($raw, true);
            if (!is_array($decoded) || ($decoded['type'] ?? null) !== $type) {
                continue;
            }
            if ($errorCode !== null && ($decoded['error_code'] ?? null) !== $errorCode) {
                continue;
            }
            $frames[] = $decoded;
        }

        return $frames;
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
