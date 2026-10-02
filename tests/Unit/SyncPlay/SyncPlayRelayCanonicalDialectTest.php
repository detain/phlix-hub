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

use function array_keys;
use function array_merge;
use function array_search;
use function count;
use function end;
use function hash;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function microtime;
use function round;
use function str_contains;
use function time;

/**
 * Unit tests for the CANONICAL `syncplay_*` dialect of the SyncPlay relay
 * (:8804) — owner decision #14: the hub learns the phlix-syncplay SPEC.md §3
 * catalog so relay-mode clients (mobile) can drop their dialect refusal.
 *
 * The companion file {@see SyncPlayRelayWorkerTest} owns the BARE vocabulary;
 * this file owns the canonical one, plus the two laws BETWEEN them:
 *   - dialect LATCH: the first `syncplay_`-prefixed frame picks the
 *     vocabulary every reply to this connection is encoded in;
 *   - dialect ISOLATION: bare frames never reach canonical members and
 *     vice versa — nothing is translated across. (The evidence that this
 *     costs nobody: the bare room vocabulary has zero live consumers — the
 *     worker's class docblock pins it.)
 *
 * Wire truths pinned here were read off the SERVER (phlix-server
 * `SyncPlayManager` / `Messages` / `GroupState`), not guessed:
 * envelope `{type, protocol_version: 1, …, timestamp(ms)}` with the factory
 * owning those keys; group_state nested under `group` with a members DICT and
 * `your_id` top-level; joins announced as `syncplay_info` with TOP-LEVEL
 * member_id/member_name (SPEC §6); host-stamped playback_sync echoed to
 * everyone INCLUDING the reporter (S291); PLAY acked to its sender while
 * PAUSE/SEEK reach only the others; host-only gates answering `NOT_HOST`
 * loudly; server→client types spoken inbound get the same `UNKNOWN_MESSAGE`
 * refusal :8097 gives them.
 *
 * The harness mirrors SyncPlayRelayWorkerTest's discipline on purpose (real
 * upgrade-request auth path, real ClientRelayTokenService over a mocked DB,
 * recording sinks) so the two dialects are proven under identical conditions.
 *
 * @package Phlix\Hub\Tests\Unit\SyncPlay
 */
final class SyncPlayRelayCanonicalDialectTest extends TestCase
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

    protected function setUp(): void
    {
        parent::setUp();

        // Process-global static room/client maps: reset so a prior test cannot
        // leak state into this one.
        SyncPlayRelayWorker::reset();

        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-syncplay-canonical-test-' . uniqid();
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

    // ---- Catalog round trip: create → join → control → sync → leave --------

    public function testGroupCreateAnswersGroupStateWithYourIdHostAndEnvelopeLaw(): void
    {
        $worker = $this->makeWorker();
        $sinkA = [];
        $connA = $this->authedClient($worker, 'token-a', $sinkA);

        $worker->onMessage($connA, $this->canonicalFrame('syncplay_group_create', [
            'group_name' => 'party',
            'member_name' => 'Alpha',
        ]));

        $state = $this->firstSinkFrameOfType($sinkA, 'syncplay_group_state');
        self::assertIsArray($state, 'a canonical create must be answered with syncplay_group_state');

        // Envelope law (SPEC §2): factory-owned keys on a millisecond clock.
        self::assertSame(1, $state['protocol_version']);
        $this->assertUnixMs($state['timestamp']);

        $yourId = $state['your_id'] ?? null;
        self::assertIsString($yourId, 'group_state carries TOP-LEVEL your_id');
        self::assertNotSame('', $yourId);

        /** @var array<string, mixed> $group */
        $group = $state['group'] ?? null;
        self::assertIsArray($group, 'group_state nests its payload under `group`');
        self::assertSame('party', $group['group_id'], 'the friendly name rides back, never the scoped key');
        self::assertSame('party', $group['group_name']);
        self::assertSame(1, $group['member_count']);
        self::assertSame($yourId, $group['host_id'], 'the creator is the host');
        self::assertSame('stopped', $group['playback_state']);
        self::assertSame(0, $group['playback_position']);
        self::assertSame(0, $group['current_media_duration']);
        self::assertNull($group['current_media_id']);
        self::assertSame([], $group['queue']);

        // Members DICT keyed by member id (GroupState::getState law), roster
        // clocks in UNIX SECONDS (SPEC §4) — deliberately NOT the ms envelope clock.
        /** @var array<string, array<string, mixed>> $members */
        $members = $group['members'];
        self::assertSame([$yourId], array_keys($members));
        self::assertSame($yourId, $members[$yourId]['id']);
        self::assertSame('Alpha', $members[$yourId]['name']);
        self::assertTrue($members[$yourId]['is_host']);
        $this->assertUnixSeconds($members[$yourId]['joined_at']);
        $this->assertUnixSeconds($group['created_at']);
        $this->assertUnixSeconds($group['last_activity_at']);
    }

    public function testSecondJoinerGetsStateAndThePresentMemberGetsCanonicalInfo(): void
    {
        $worker = $this->makeWorker();
        $sinkA = [];
        $connA = $this->authedClient($worker, 'token-a', $sinkA);
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_group_create', [
            'group_name' => 'party',
            'member_name' => 'Alpha',
        ]));
        $aId = $this->yourIdOf($sinkA);

        $sinkB = [];
        $connB = $this->authedClient($worker, 'token-b', $sinkB);
        $worker->onMessage($connB, $this->canonicalFrame('syncplay_group_join', [
            'group_id' => 'party',
            'member_name' => 'Bee',
        ]));
        $bId = $this->yourIdOf($sinkB);

        self::assertNotSame($aId, $bId, 'two sockets, two member ids');

        // The joiner learns the full roster from its own state.
        $groupB = $this->groupOf($sinkB);
        self::assertSame(2, $groupB['member_count']);
        /** @var array<string, array<string, mixed>> $membersB */
        $membersB = $groupB['members'];
        self::assertArrayHasKey($aId, $membersB);
        self::assertArrayHasKey($bId, $membersB);
        self::assertSame($aId, $groupB['host_id'], 'joining does not steal the crown');

        // SPEC §6: the join is announced to OTHERS as syncplay_info with
        // TOP-LEVEL member_id/member_name — and never as a bare client_joined.
        $infos = $this->sinkFramesOfType($sinkA, 'syncplay_info');
        self::assertCount(1, $infos, 'the present member hears exactly one join announcement');
        self::assertSame($bId, $infos[0]['member_id']);
        self::assertSame('Bee', $infos[0]['member_name']);
        self::assertSame('Bee joined the group', $infos[0]['message']);
        self::assertFalse(
            $this->sinkHasType($sinkA, 'client_joined'),
            'canonical events never leak the bare vocabulary',
        );
        self::assertFalse(
            $this->sinkHasType($sinkB, 'syncplay_info'),
            'the joiner is excluded from its own announcement',
        );
    }

    // ---- Identity law (§9) --------------------------------------------------

    public function testPlayIsAckedToHostAndRelayedWithAuthoritativeStampAndStrippedForgery(): void
    {
        $worker = $this->makeWorker();
        $sinkA = [];
        $connA = $this->authedClient($worker, 'token-a', $sinkA);
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_group_create', ['group_name' => 'party']));
        $aId = $this->yourIdOf($sinkA);
        $sinkB = [];
        $connB = $this->authedClient($worker, 'token-b', $sinkB);
        $worker->onMessage($connB, $this->canonicalFrame('syncplay_group_join', ['group_id' => 'party']));

        $beforeA = count($sinkA);
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_playback_play', [
            'member_id' => 'spoofed-guest',
            'protocol_version' => 99,
            'timestamp' => 999,
            'position' => 42000,
            'server_time' => 1234567,
        ]));

        $acksA = $this->sinkFramesOfType($sinkA, 'syncplay_playback_play');
        self::assertCount(1, $acksA, 'PLAY returns to its sender as the confirmation the server sends');
        self::assertGreaterThan($beforeA, count($sinkA));
        self::assertSame($aId, $acksA[0]['member_id'], 'SPEC §9: the CONNECTION is the sender, never the claim');
        self::assertSame(42000, $acksA[0]['position']);
        self::assertSame(1234567, $acksA[0]['server_time'], 'server_time passes through, as :8097 echoes it');
        self::assertSame(1, $acksA[0]['protocol_version'], 'a forged protocol_version in the payload is stripped');
        $this->assertUnixMs($acksA[0]['timestamp'], 'a forged timestamp=999 is replaced by the hub clock');
        self::assertGreaterThan(999, $acksA[0]['timestamp'], 'the replaced stamp is on the millisecond scale');
        self::assertArrayNotHasKey(
            'group_id',
            $acksA[0],
            'playback commands carry no group_id out of the factory — server shape',
        );

        $relayB = $this->sinkFramesOfType($sinkB, 'syncplay_playback_play');
        self::assertCount(1, $relayB, 'the same frame reaches the other member');
        self::assertSame($acksA[0], $relayB[0]);
    }

    public function testNonHostPlaybackCommandIsRefusedLoudly(): void
    {
        $worker = $this->makeWorker();
        $sinkA = [];
        $connA = $this->authedClient($worker, 'token-a', $sinkA);
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_group_create', ['group_name' => 'party']));
        $sinkB = [];
        $connB = $this->authedClient($worker, 'token-b', $sinkB);
        $worker->onMessage($connB, $this->canonicalFrame('syncplay_group_join', ['group_id' => 'party']));

        $beforeA = count($sinkA);
        $worker->onMessage($connB, $this->canonicalFrame('syncplay_playback_play', ['position' => 1]));

        $errors = $this->sinkFramesOfType($sinkB, 'syncplay_error');
        self::assertCount(1, $errors);
        self::assertSame('NOT_HOST', $errors[0]['error_code']);
        self::assertSame('Only the host can control playback', $errors[0]['message'], 'the :8097 prose, word for word');
        self::assertSame($beforeA, count($sinkA), 'a refused command must not fan out');
    }

    public function testPauseAndSeekExcludeTheSenderAndSeekLeavesStateAlone(): void
    {
        $worker = $this->makeWorker();
        $sinkA = [];
        $connA = $this->authedClient($worker, 'token-a', $sinkA);
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_group_create', ['group_name' => 'party']));
        $aId = $this->yourIdOf($sinkA);
        $sinkB = [];
        $connB = $this->authedClient($worker, 'token-b', $sinkB);
        $worker->onMessage($connB, $this->canonicalFrame('syncplay_group_join', ['group_id' => 'party']));

        $beforeA = count($sinkA);
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_playback_pause', ['position' => 42000]));
        self::assertSame($beforeA, count($sinkA), 'PAUSE is not acked — the server acks only PLAY');
        $pauses = $this->sinkFramesOfType($sinkB, 'syncplay_playback_pause');
        self::assertCount(1, $pauses);
        self::assertSame($aId, $pauses[0]['member_id']);

        $worker->onMessage($connA, $this->canonicalFrame('syncplay_playback_seek', [
            'from_position' => 42000,
            'to_position' => 90000,
        ]));
        $seeks = $this->sinkFramesOfType($sinkB, 'syncplay_playback_seek');
        self::assertCount(1, $seeks);
        self::assertSame(42000, $seeks[0]['from_position']);
        self::assertSame(90000, $seeks[0]['to_position']);
        self::assertSame($beforeA, count($sinkA), 'SEEK is not acked either');

        // A seek must not flip the state machine: a late joiner still reads PAUSED.
        $sinkC = [];
        $connC = $this->authedClient($worker, 'token-c', $sinkC);
        $worker->onMessage($connC, $this->canonicalFrame('syncplay_group_join', ['group_id' => 'party']));
        $groupC = $this->groupOf($sinkC);
        self::assertSame('paused', $groupC['playback_state']);
        self::assertSame(90000, $groupC['playback_position'], 'but the seek DID move the anchor position');
    }

    // ---- The sync state report (S291 law) -----------------------------------

    public function testPlaybackSyncEchoesRoomStateToEveryoneStampedWithTheHost(): void
    {
        $worker = $this->makeWorker();
        $sinkA = [];
        $connA = $this->authedClient($worker, 'token-a', $sinkA);
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_group_create', ['group_name' => 'party']));
        $aId = $this->yourIdOf($sinkA);
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_playback_play', [
            'position' => 90000,
            'media_id' => 'm1',
        ]));
        $sinkB = [];
        $connB = $this->authedClient($worker, 'token-b', $sinkB);
        $worker->onMessage($connB, $this->canonicalFrame('syncplay_group_join', ['group_id' => 'party']));

        // The reporter claims a drifted position under a spoofed identity.
        $worker->onMessage($connB, $this->canonicalFrame('syncplay_playback_sync', [
            'member_id' => 'spoofed',
            'group_id' => 'party',
            'position' => 555,
            'is_playing' => false,
        ]));

        foreach (['A' => $sinkA, 'B' => $sinkB] as $who => $sink) {
            $syncs = $this->sinkFramesOfType($sink, 'syncplay_playback_sync');
            self::assertCount(1, $syncs, "{$who}: S291 — the echo reaches EVERYONE including the reporter");
            self::assertSame($aId, $syncs[0]['member_id'], 'the echo is HOST-stamped, never reporter-stamped');
            self::assertSame(90000, $syncs[0]['position'], 'the ROOM truth echoes, not the reported 555');
            self::assertTrue($syncs[0]['is_playing']);
            self::assertSame('m1', $syncs[0]['current_media_id']);
            self::assertSame('party', $syncs[0]['group_id']);
            $this->assertUnixMs($syncs[0]['server_time'], 'hub-stamped server_time keeps the ms law');
        }
    }

    public function testLateJoinerStateCarriesTheLiveAnchor(): void
    {
        $worker = $this->makeWorker();
        $sinkA = [];
        $connA = $this->authedClient($worker, 'token-a', $sinkA);
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_group_create', ['group_name' => 'party']));
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_playback_play', [
            'position' => 90000,
            'media_id' => 'm1',
        ]));

        $sinkC = [];
        $connC = $this->authedClient($worker, 'token-c', $sinkC);
        $worker->onMessage($connC, $this->canonicalFrame('syncplay_group_join', ['group_id' => 'party']));
        $group = $this->groupOf($sinkC);
        self::assertSame(90000, $group['playback_position']);
        self::assertSame('playing', $group['playback_state']);
        self::assertSame('m1', $group['current_media_id']);
    }

    // ---- Leave / election / close -------------------------------------------

    public function testPlainLeaveAcksTheLeaverAndRefreshesTheRosterQuietly(): void
    {
        $worker = $this->makeWorker();
        $sinkA = [];
        $connA = $this->authedClient($worker, 'token-a', $sinkA);
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_group_create', ['group_name' => 'party']));
        $aId = $this->yourIdOf($sinkA);
        $sinkB = [];
        $connB = $this->authedClient($worker, 'token-b', $sinkB);
        $worker->onMessage($connB, $this->canonicalFrame('syncplay_group_join', [
            'group_id' => 'party',
            'member_name' => 'Bee',
        ]));
        $bId = $this->yourIdOf($sinkB);

        $worker->onMessage($connB, $this->canonicalFrame('syncplay_group_leave', []));

        $infos = $this->sinkFramesOfType($sinkB, 'syncplay_info');
        self::assertCount(1, $infos, 'the leaver gets the server-shaped info ack');
        self::assertSame('Bee left the group', $infos[0]['message']);

        // A's sink holds its OWN create-state first; the leave refresh is the second.
        $states = $this->sinkFramesOfType($sinkA, 'syncplay_group_state');
        self::assertCount(2, $states, 'the hub delivers the "next group_state" SPEC §6 promises, immediately');
        $leaveGroup = $this->groupOfFrame($states[1]);
        self::assertSame(1, $leaveGroup['member_count']);
        /** @var array<string, array<string, mixed>> $leaveMembers */
        $leaveMembers = $leaveGroup['members'];
        self::assertArrayHasKey($aId, $leaveMembers);
        self::assertArrayNotHasKey($bId, $leaveMembers);
        self::assertFalse($this->sinkHasType($sinkA, 'syncplay_host_elect'), 'a non-host leave triggers no election');
        self::assertFalse(
            $this->sinkHasType($sinkA, 'client_left'),
            'the bare leave notice never crosses into the canonical dialect',
        );

        // Outside any room now, the ex-member fails loud like :8097 makes it.
        $worker->onMessage($connB, $this->canonicalFrame('syncplay_playback_play', ['position' => 1]));
        $errors = $this->sinkFramesOfType($sinkB, 'syncplay_error');
        self::assertSame('NOT_IN_GROUP', $errors[0]['error_code'] ?? null);
    }

    public function testHostLeaveElectsOldestThenAnnouncesStateInThatOrder(): void
    {
        $worker = $this->makeWorker();
        $sinkA = [];
        $connA = $this->authedClient($worker, 'token-a', $sinkA);
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_group_create', ['group_name' => 'party']));
        $sinkB = [];
        $connB = $this->authedClient($worker, 'token-b', $sinkB);
        $worker->onMessage($connB, $this->canonicalFrame('syncplay_group_join', ['group_id' => 'party']));
        $bId = $this->yourIdOf($sinkB);
        $sinkC = [];
        $connC = $this->authedClient($worker, 'token-c', $sinkC);
        $worker->onMessage($connC, $this->canonicalFrame('syncplay_group_join', ['group_id' => 'party']));

        $worker->onMessage($connA, $this->canonicalFrame('syncplay_group_leave', []));

        foreach (['Bee' => $sinkB, 'Cee' => $sinkC] as $who => $sink) {
            $elect = $this->firstSinkFrameOfType($sink, 'syncplay_host_elect');
            self::assertIsArray($elect, "{$who} must hear the election");
            self::assertSame($bId, $elect['elected_id'], 'oldest remaining member wins, as on the server');
            self::assertIsString($elect['elected_by']);

            $types = $this->typesOf($sink);
            $electAt = array_search('syncplay_host_elect', $types, true);
            self::assertIsInt($electAt);
            $stateAt = array_search('syncplay_group_state', array_slice($types, $electAt), true);
            self::assertIsInt($stateAt, "{$who} must hear the refreshed state AFTER the election, in that order");
        }

        // [0] is Bee's own join-state (host was A); [1] is the post-election truth.
        $statesB = $this->sinkFramesOfType($sinkB, 'syncplay_group_state');
        self::assertCount(2, $statesB);
        $electedGroup = $this->groupOfFrame($statesB[1]);
        self::assertSame($bId, $electedGroup['host_id']);
        /** @var array<string, array<string, mixed>> $electedMembers */
        $electedMembers = $electedGroup['members'];
        self::assertTrue($electedMembers[$bId]['is_host']);

        // The departed host, roomless, can command nothing any more.
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_playback_play', ['position' => 1]));
        $errors = $this->sinkFramesOfType($sinkA, 'syncplay_error');
        self::assertSame('NOT_IN_GROUP', $errors[0]['error_code'] ?? null);
    }

    public function testSocketCloseRunsTheCanonicalTeardownViaTheLatch(): void
    {
        $worker = $this->makeWorker();
        $sinkA = [];
        $connA = $this->authedClient($worker, 'token-a', $sinkA);
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_group_create', ['group_name' => 'party']));
        $sinkB = [];
        $connB = $this->authedClient($worker, 'token-b', $sinkB);
        $worker->onMessage($connB, $this->canonicalFrame('syncplay_group_join', ['group_id' => 'party']));
        $bId = $this->yourIdOf($sinkB);

        $worker->onClose($connA);

        self::assertTrue($this->sinkHasType($sinkB, 'syncplay_host_elect'), 'closing a host socket must still elect');
        $statesB = $this->sinkFramesOfType($sinkB, 'syncplay_group_state');
        self::assertCount(2, $statesB, 'join-state, then the post-close truth');
        self::assertSame($bId, $this->groupOfFrame($statesB[1])['host_id']);
        self::assertSame(1, SyncPlayRelayWorker::getActiveConnectionCount());
        self::assertSame(
            1,
            SyncPlayRelayWorker::getActiveRoomCount(),
            'the emptied-by-nobody bucket keeps its pinned sweep lifecycle',
        );
    }

    // ---- Dialect latch & isolation ------------------------------------------

    public function testDialectsShareTheRoomBookButNeverTranslateAcrossMembers(): void
    {
        $worker = $this->makeWorker();

        // A joins the room BARE, B joins the SAME friendly name CANONICAL.
        $sinkA = [];
        $connA = $this->authedClient($worker, 'token-a', $sinkA);
        $worker->onMessage($connA, (string) json_encode([
            'type' => 'group_join',
            'room' => 'party',
            'display_name' => 'A-raw',
        ]));
        self::assertTrue($this->sinkHasType($sinkA, 'room_state'));

        $sinkB = [];
        $connB = $this->authedClient($worker, 'token-b', $sinkB);
        $worker->onMessage($connB, $this->canonicalFrame('syncplay_group_join', ['group_id' => 'party']));

        // The canonical join announced NOTHING to the bare member: its info is
        // a canonical frame, and bare members are not its audience.
        self::assertFalse($this->sinkHasType($sinkA, 'client_joined'));
        self::assertFalse($this->sinkHasType($sinkA, 'syncplay_info'));

        // A's bare playback control never reaches B.
        $beforeB = count($sinkB);
        $worker->onMessage($connA, (string) json_encode(['type' => 'playback_play', 'position' => 1.5]));
        self::assertSame($beforeB, count($sinkB), 'a bare frame crossed into the canonical dialect');

        // B speaks canonical in a room that predates the catalog: no canonical
        // host of record exists, so the relay refuses LOUDLY — a documented
        // mixed-room boundary. Never a silent no, never a bare echo.
        $beforeA = count($sinkA);
        $worker->onMessage($connB, $this->canonicalFrame('syncplay_playback_play', ['position' => 1]));
        $errors = $this->sinkFramesOfType($sinkB, 'syncplay_error');
        self::assertCount(1, $errors);
        self::assertSame('NOT_HOST', $errors[0]['error_code']);
        self::assertSame($beforeA, count($sinkA), 'the refused canonical frame must not fan out in any vocabulary');
    }

    public function testBareExtensionFloorStaysOpenAndCanonicalFloorStaysClosed(): void
    {
        $worker = $this->makeWorker();

        // Bare pair: a named unknown type still relays (the legacy seam).
        $sinkA = [];
        $connA = $this->authedClient($worker, 'token-a', $sinkA);
        $worker->onMessage($connA, (string) json_encode(['type' => 'group_join', 'room' => 'bare-room']));
        $sinkB = [];
        $connB = $this->authedClient($worker, 'token-b', $sinkB);
        $worker->onMessage($connB, (string) json_encode(['type' => 'group_join', 'room' => 'bare-room']));
        $worker->onMessage($connA, (string) json_encode(['type' => 'future_extension', 'x' => 1]));
        self::assertTrue($this->sinkHasType($sinkB, 'future_extension'), 'the bare relay floor is not a closed set');

        // Canonical pair: an unknown syncplay_* name is REFUSED, never relayed.
        $sinkC = [];
        $connC = $this->authedClient($worker, 'token-c', $sinkC);
        $worker->onMessage($connC, $this->canonicalFrame('syncplay_group_create', ['group_name' => 'canon-room']));
        $sinkD = [];
        $connD = $this->authedClient($worker, 'token-d', $sinkD);
        $worker->onMessage($connD, $this->canonicalFrame('syncplay_group_join', ['group_id' => 'canon-room']));
        $beforeD = count($sinkD);
        $worker->onMessage($connC, $this->canonicalFrame('syncplay_some_future_op', ['x' => 1]));
        self::assertTrue($this->sinkHasType($sinkC, 'syncplay_error'));
        self::assertSame($beforeD, count($sinkD), 'an unreadable canonical name must never masquerade as room state');

        // A canonical client's BARE-named unknown rides the legacy floor to
        // BARE members only — its canonical room-mate stays silent.
        $worker->onMessage($connC, (string) json_encode(['type' => 'future_extension', 'x' => 1]));
        self::assertSame($beforeD, count($sinkD));
    }

    // ---- Dialect guard on the bare room family (owner #14 follow-up) -------

    public function testBareGroupJoinFromCanonicalSocketIsRefusedWithoutRehomingOrGhostHost(): void
    {
        $worker = $this->makeWorker();

        $sinkA = [];
        $connA = $this->authedClient($worker, 'token-a', $sinkA);
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_group_create', [
            'group_name' => 'party',
            'member_name' => 'Alpha',
        ]));
        $sinkB = [];
        $connB = $this->authedClient($worker, 'token-b', $sinkB);
        $worker->onMessage($connB, $this->canonicalFrame('syncplay_group_join', ['group_id' => 'party']));

        $beforeB = count($sinkB);

        // THE GHOST-HOST VECTOR: the canonical host of `party` attempts a BARE
        // join to another room. Unguarded, that ran the bare leave (unset with
        // no election, no notice, stale canonical books) and answered with a
        // bare room_state — a bare frame on a canonical socket.
        $worker->onMessage($connA, (string) json_encode([
            'type' => 'group_join',
            'room' => 'other',
            'display_name' => 'ghost',
        ]));

        // Refused with the closed canonical floor's own shape, naming the type.
        $errors = $this->sinkFramesOfType($sinkA, 'syncplay_error');
        self::assertCount(1, $errors, 'a bare join on a canonical latch is refused loudly');
        self::assertSame('UNKNOWN_MESSAGE', $errors[0]['error_code']);
        $refusal = $errors[0]['message'] ?? null;
        self::assertIsString($refusal);
        self::assertTrue(str_contains($refusal, 'group_join'), 'the refusal names the refused frame');
        self::assertSame(1, $errors[0]['protocol_version']);
        $this->assertUnixMs($errors[0]['timestamp']);

        // Wire law: the canonical socket received no bare frame of any kind.
        self::assertFalse($this->sinkHasType($sinkA, 'room_state'), 'a canonical client never sees a bare frame');
        self::assertSame($beforeB, count($sinkB), 'the refusal is sender-addressed; the room hears nothing');

        // Books intact in both directions: A STILL holds canonical host rank —
        // a canonical play passes the host gate and is acked to its sender.
        // (Unguarded, the re-home dropped A into a bare room where no host of
        // record exists, and the same play would answer NOT_HOST.)
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_playback_play', ['position' => 1]));
        self::assertTrue(
            $this->sinkHasType($sinkA, 'syncplay_playback_play'),
            'host rank survived the refused bare join',
        );

        // And the canonical leave still runs the canonical teardown: B finally
        // learns of the departure through election + fresh state — exactly the
        // notices the bare re-home would have silently eaten, leaving A's old
        // room with a ghost host until the sweep.
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_group_leave', []));
        $elects = $this->sinkFramesOfType($sinkB, 'syncplay_host_elect');
        self::assertCount(1, $elects, 'the host slot transfers through the election, not the sweep');
        $statesB = $this->sinkFramesOfType($sinkB, 'syncplay_group_state');
        self::assertCount(2, $statesB, 'own join state + the post-leave state');
        $final = $this->groupOfFrame($statesB[1]);
        self::assertSame(1, $final['member_count']);
        self::assertSame(
            $this->yourIdOf($sinkB),
            $final['host_id'],
            'the election handed B the slot the ghost-host vector would have left vacant',
        );
    }

    public function testBareTimeSyncFromCanonicalSocketIsRefusedNotAnsweredBare(): void
    {
        $worker = $this->makeWorker();

        $sinkA = [];
        $connA = $this->authedClient($worker, 'token-a', $sinkA);
        // Latch WITHOUT a room: the pong is canonical, so the socket is
        // canonical-latched before it ever joins anything.
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_time_ping', ['client_time' => 1]));
        self::assertTrue($this->sinkHasType($sinkA, 'syncplay_time_pong'));

        $worker->onMessage($connA, (string) json_encode(['type' => 'time_sync', 'client_time' => 2]));

        self::assertFalse(
            $this->sinkHasType($sinkA, 'time_sync_reply'),
            'the bare clock answer must never land on a canonical socket',
        );
        $errors = $this->sinkFramesOfType($sinkA, 'syncplay_error');
        self::assertCount(1, $errors);
        self::assertSame('UNKNOWN_MESSAGE', $errors[0]['error_code']);
        $refusal = $errors[0]['message'] ?? null;
        self::assertIsString($refusal);
        self::assertTrue(str_contains($refusal, 'time_sync'));
    }

    public function testBarePlaybackFromCanonicalSocketIsRefusedAndTheSharedAnchorStaysCanonical(): void
    {
        $worker = $this->makeWorker();

        $sinkA = [];
        $connA = $this->authedClient($worker, 'token-a', $sinkA);
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_group_create', [
            'group_name' => 'party',
            'member_name' => 'Alpha',
        ]));
        $sinkB = [];
        $connB = $this->authedClient($worker, 'token-b', $sinkB);
        $worker->onMessage($connB, $this->canonicalFrame('syncplay_group_join', ['group_id' => 'party']));

        // Canonical play seeds the SHARED anchor slot at position 4242.
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_playback_play', ['position' => 4242]));

        $beforeB = count($sinkB);

        // A bare playback frame from the same socket would clobber that slot —
        // it feeds canonical members' `group_state.playback` — with a bare-
        // shaped write at position 99999.
        $worker->onMessage($connA, (string) json_encode(['type' => 'playback_play', 'position' => 99999]));

        $errors = $this->sinkFramesOfType($sinkA, 'syncplay_error');
        self::assertCount(1, $errors, 'the bare control frame is refused, not silently dropped');
        self::assertSame('UNKNOWN_MESSAGE', $errors[0]['error_code']);
        $refusal = $errors[0]['message'] ?? null;
        self::assertIsString($refusal);
        self::assertTrue(str_contains($refusal, 'playback_play'));
        self::assertSame($beforeB, count($sinkB), 'a refused bare frame fans out in no vocabulary');

        // The anchor the room answers with is STILL the canonical one.
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_playback_sync', []));
        $syncs = $this->sinkFramesOfType($sinkA, 'syncplay_playback_sync');
        self::assertCount(1, $syncs);
        self::assertSame(4242, $syncs[0]['position'], 'the refused bare write never touched the shared anchor');
        self::assertTrue($syncs[0]['is_playing']);
    }

    public function testBareLatchedClientRoundTripsTheGuardedFamilyUnchanged(): void
    {
        $worker = $this->makeWorker();
        $sinkC = [];
        $connC = $this->authedClient($worker, 'token-c', $sinkC, 'user-c', 'server-c');

        // Control pin for the guard: a BARE-latched socket keeps the legacy
        // answers byte-for-byte — join, play, and clock probe all answered.
        $worker->onMessage($connC, (string) json_encode(['type' => 'group_join', 'room' => 'bare-only']));
        self::assertTrue($this->sinkHasType($sinkC, 'room_state'));

        $worker->onMessage($connC, (string) json_encode(['type' => 'playback_play', 'position' => 1.5]));
        self::assertTrue(
            $this->sinkHasType($sinkC, 'playback_play'),
            'bare play echoes to its sender (include-self law)',
        );

        $worker->onMessage($connC, (string) json_encode(['type' => 'time_sync', 'client_time' => 7]));
        $reply = $this->firstSinkFrameOfType($sinkC, 'time_sync_reply');
        self::assertIsArray($reply);
        self::assertSame(7, $reply['client_time']);
        $this->assertUnixMs($reply['server_time']);

        self::assertCount(
            0,
            $this->sinkFramesOfType($sinkC, 'syncplay_error'),
            'the guard is latch-only — a bare latch sees no canonical frame, refusal included',
        );
    }

    public function testServerToClientTypesSpokenInboundAreRefusedNotRelayed(): void
    {
        $worker = $this->makeWorker();
        $sinkA = [];
        $connA = $this->authedClient($worker, 'token-a', $sinkA);
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_group_create', ['group_name' => 'party']));
        $sinkB = [];
        $connB = $this->authedClient($worker, 'token-b', $sinkB);
        $worker->onMessage($connB, $this->canonicalFrame('syncplay_group_join', ['group_id' => 'party']));

        $beforeB = count($sinkB);
        $serverOnlyTypes = [
            'syncplay_info',
            'syncplay_group_state',
            'syncplay_host_elect',
            'syncplay_time_pong',
            'syncplay_error',
        ];
        foreach ($serverOnlyTypes as $serverOnlyType) {
            $worker->onMessage($connA, $this->canonicalFrame($serverOnlyType, ['message' => 'pretend']));
        }

        $errors = $this->sinkFramesOfType($sinkA, 'syncplay_error');
        self::assertCount(5, $errors);
        foreach ($errors as $error) {
            self::assertSame('UNKNOWN_MESSAGE', $error['error_code'], 'the same refusal :8097 gives these types');
            self::assertSame('Unknown message type', $error['message']);
        }
        self::assertSame($beforeB, count($sinkB), 'the lie never fanned out to the room');
    }

    // ---- Ping / clock --------------------------------------------------------

    public function testTimePingNeedsNoRoomAndPongsHubClockInMs(): void
    {
        $worker = $this->makeWorker();
        $sinkA = [];
        $connA = $this->authedClient($worker, 'token-a', $sinkA);
        $sinkB = [];
        $this->authedClient($worker, 'token-b', $sinkB);

        $worker->onMessage($connA, $this->canonicalFrame('syncplay_time_ping', ['client_time' => 9876543210]));

        $pongs = $this->sinkFramesOfType($sinkA, 'syncplay_time_pong');
        self::assertCount(1, $pongs);
        self::assertSame(9876543210, $pongs[0]['client_time']);
        $this->assertUnixMs($pongs[0]['server_time']);
        self::assertSame(1, $pongs[0]['protocol_version']);
        self::assertFalse($this->sinkHasType($sinkA, 'syncplay_error'), 'ping is lawful outside any room');
        self::assertCount(0, $sinkB, 'a pong is addressed to its pinger, never a broadcast');
    }

    public function testTimeSyncStatusQueryIsRefusedNotInvented(): void
    {
        $worker = $this->makeWorker();
        $sinkA = [];
        $connA = $this->authedClient($worker, 'token-a', $sinkA);

        $worker->onMessage($connA, $this->canonicalFrame('syncplay_time_sync', []));

        $errors = $this->sinkFramesOfType($sinkA, 'syncplay_error');
        self::assertCount(1, $errors);
        self::assertSame('hub.protocol_unsupported', $errors[0]['error_code']);
        $refusal = $errors[0]['message'] ?? null;
        self::assertIsString($refusal);
        self::assertTrue(
            str_contains($refusal, 'syncplay_time_ping'),
            'the refusal names the working alternative',
        );
    }

    // ---- Chat / typing ---------------------------------------------------------

    public function testChatFansOutToEveryoneUnderTheAuthoritativeIdentity(): void
    {
        $worker = $this->makeWorker();
        $sinkA = [];
        $connA = $this->authedClient($worker, 'token-a', $sinkA);
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_group_create', [
            'group_name' => 'party',
            'member_name' => 'Alpha',
        ]));
        $aId = $this->yourIdOf($sinkA);
        $sinkB = [];
        $connB = $this->authedClient($worker, 'token-b', $sinkB);
        $worker->onMessage($connB, $this->canonicalFrame('syncplay_group_join', ['group_id' => 'party']));

        $worker->onMessage($connA, $this->canonicalFrame('syncplay_chat', [
            'member_id' => 'spoofed',
            'message' => 'hello room',
        ]));

        foreach (['A' => $sinkA, 'B' => $sinkB] as $who => $sink) {
            $chats = $this->sinkFramesOfType($sink, 'syncplay_chat');
            self::assertCount(1, $chats, "chat reaches {$who} — the server broadcasts chat with no exclusion");
            self::assertSame($aId, $chats[0]['member_id']);
            self::assertSame('Alpha', $chats[0]['member_name']);
            self::assertSame('hello room', $chats[0]['message']);
        }

        $beforeA = count($sinkA);
        $beforeB = count($sinkB);
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_chat', ['message' => '   ']));
        self::assertSame($beforeA, count($sinkA), 'a blank chat is dropped in silence, as on :8097');
        self::assertSame($beforeB, count($sinkB));

        // Chat from a roomless stranger fails loud (unlike typing).
        $sinkC = [];
        $connC = $this->authedClient($worker, 'token-c', $sinkC);
        $worker->onMessage($connC, $this->canonicalFrame('syncplay_chat', ['message' => 'anyone?']));
        $errors = $this->sinkFramesOfType($sinkC, 'syncplay_error');
        self::assertSame('NOT_IN_GROUP', $errors[0]['error_code'] ?? null);
    }

    public function testTypingExcludesSenderCoercesTheLegacyFlagAndStaysSilentUnrostered(): void
    {
        $worker = $this->makeWorker();
        $sinkA = [];
        $connA = $this->authedClient($worker, 'token-a', $sinkA);
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_group_create', ['group_name' => 'party']));
        $sinkB = [];
        $connB = $this->authedClient($worker, 'token-b', $sinkB);
        $worker->onMessage($connB, $this->canonicalFrame('syncplay_group_join', ['group_id' => 'party']));
        $bId = $this->yourIdOf($sinkB);

        $worker->onMessage($connB, $this->canonicalFrame('syncplay_typing', ['is_typing' => true]));
        $worker->onMessage($connB, $this->canonicalFrame('syncplay_typing', ['is_typing' => '1']));
        $worker->onMessage($connB, $this->canonicalFrame('syncplay_typing', ['is_typing' => 'nonsense']));

        $typings = $this->sinkFramesOfType($sinkA, 'syncplay_typing');
        self::assertCount(3, $typings);
        self::assertTrue($typings[0]['is_typing']);
        self::assertTrue($typings[1]['is_typing'], "the legacy '1' string means true on :8097");
        self::assertFalse($typings[2]['is_typing'], 'anything else means false — never a passthrough of junk');
        self::assertSame($bId, $typings[0]['member_id']);
        self::assertFalse(
            $this->sinkHasType($sinkB, 'syncplay_typing'),
            'typing never echoes to its own typist',
        );

        // Typing while roomless is SILENTLY ignored — the server's guard shape
        // for this transient indicator (no error frames, no fan-out).
        $sinkC = [];
        $connC = $this->authedClient($worker, 'token-c', $sinkC);
        $worker->onMessage($connC, $this->canonicalFrame('syncplay_typing', ['is_typing' => true]));
        self::assertCount(0, $sinkC);
        self::assertFalse($this->sinkHasType($sinkA, 'syncplay_error'));
    }

    // ---- Queue -------------------------------------------------------------------

    public function testQueueIsHostGatedNormalizedCappedAndBroadcastToAll(): void
    {
        $worker = $this->makeWorker();
        $sinkA = [];
        $connA = $this->authedClient($worker, 'token-a', $sinkA);
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_group_create', ['group_name' => 'party']));
        $sinkB = [];
        $connB = $this->authedClient($worker, 'token-b', $sinkB);
        $worker->onMessage($connB, $this->canonicalFrame('syncplay_group_join', ['group_id' => 'party']));

        // Gate first: a follower's queue is refused loudly and stores nothing.
        $followerQueue = $this->canonicalFrame('syncplay_playback_queue', [
            'queue' => [['media_id' => 'x']],
        ]);
        $worker->onMessage($connB, $followerQueue);
        $errors = $this->sinkFramesOfType($sinkB, 'syncplay_error');
        self::assertSame('NOT_HOST', $errors[0]['error_code'] ?? null);

        // Host submission: junk entries parse away (the server's LOW-2 shape).
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_playback_queue', ['queue' => [
            ['media_id' => 1],
            ['media_id' => 'm1', 'media_info' => ['title' => 'T', 5 => 'dropped-numeric-key']],
            'not-even-an-entry',
            ['media_id' => 'm2'],
        ]]));

        $accepted = [
            ['media_id' => 'm1', 'media_info' => ['title' => 'T']],
            ['media_id' => 'm2', 'media_info' => []],
        ];
        foreach (['A' => $sinkA, 'B' => $sinkB] as $who => $sink) {
            $queues = $this->sinkFramesOfType($sink, 'syncplay_playback_queue');
            self::assertCount(
                1,
                $queues,
                "the accepted queue broadcast includes the host (server excludes nobody): {$who}",
            );
            self::assertSame($accepted, $queues[0]['queue']);
        }

        // Over-cap: refused with the STORED queue untouched (LOW-2, no partial write).
        $oversized = [];
        for ($i = 0; $i <= SyncPlayRelayWorker::CANONICAL_MAX_QUEUE_SIZE; $i++) {
            $oversized[] = ['media_id' => "m{$i}"];
        }
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_playback_queue', ['queue' => $oversized]));
        $errors = $this->sinkFramesOfType($sinkA, 'syncplay_error');
        self::assertSame(
            'syncplay.queue_limit_exceeded',
            $errors[0]['error_code'] ?? null,
            'the registered twin, same name the server emits',
        );
        self::assertCount(1, $this->sinkFramesOfType($sinkB, 'syncplay_playback_queue'), 'a refusal must not fan out');

        // A late joiner confirms the OLD queue survived the refusal.
        $sinkC = [];
        $connC = $this->authedClient($worker, 'token-c', $sinkC);
        $worker->onMessage($connC, $this->canonicalFrame('syncplay_group_join', ['group_id' => 'party']));
        self::assertSame($accepted, $this->groupOf($sinkC)['queue']);
    }

    // ---- Host transfer -------------------------------------------------------------

    public function testHostTransferGuardOrderMatchesTheServerThenFlipsRanks(): void
    {
        $worker = $this->makeWorker();
        $sinkA = [];
        $connA = $this->authedClient($worker, 'token-a', $sinkA);
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_group_create', ['group_name' => 'party']));
        $aId = $this->yourIdOf($sinkA);
        $sinkB = [];
        $connB = $this->authedClient($worker, 'token-b', $sinkB);
        $worker->onMessage($connB, $this->canonicalFrame('syncplay_group_join', ['group_id' => 'party']));
        $bId = $this->yourIdOf($sinkB);

        $refusals = [
            [$connB, ['new_host_id' => $aId], 'NOT_HOST'],
            [$connA, [], 'INVALID_NEW_HOST'],
            [$connA, ['new_host_id' => ''], 'INVALID_NEW_HOST'],
            [$connA, ['new_host_id' => 'ghost'], 'MEMBER_NOT_FOUND'],
            [$connA, ['new_host_id' => $aId], 'SAME_HOST'],
        ];
        foreach ($refusals as [$conn, $payload, $code]) {
            $worker->onMessage($conn, $this->canonicalFrame('syncplay_host_transfer', $payload));
        }
        $errorsB = $this->sinkFramesOfType($sinkB, 'syncplay_error');
        self::assertSame('NOT_HOST', $errorsB[0]['error_code'] ?? null);
        self::assertSame(
            ['INVALID_NEW_HOST', 'INVALID_NEW_HOST', 'MEMBER_NOT_FOUND', 'SAME_HOST'],
            $this->errorCodesOf($sinkA),
        );
        self::assertCount(
            1,
            $this->sinkFramesOfType($sinkA, 'syncplay_group_state'),
            'no refusal may rebroadcast state — A still holds only its create-state',
        );

        // Success: broadcast to EVERYONE (server excludes nobody), ranks flipped.
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_host_transfer', ['new_host_id' => $bId]));
        foreach (['A' => $sinkA, 'B' => $sinkB] as $who => $sink) {
            $states = $this->sinkFramesOfType($sink, 'syncplay_group_state');
            self::assertCount(2, $states, "{$who} hears the transferred state as its second");
            $transferred = $this->groupOfFrame($states[1]);
            self::assertSame($bId, $transferred['host_id']);
            /** @var array<string, array<string, mixed>> $transferredMembers */
            $transferredMembers = $transferred['members'];
            self::assertTrue($transferredMembers[$bId]['is_host']);
            self::assertFalse($transferredMembers[$aId]['is_host']);
        }

        // Ranks are LIVE: the new host commands, the old one gets the loud no.
        $worker->onMessage($connB, $this->canonicalFrame('syncplay_playback_play', ['position' => 7]));
        self::assertTrue(
            $this->sinkHasType($sinkB, 'syncplay_playback_play'),
            'the transferred host gets its own play ack',
        );
        self::assertTrue($this->sinkHasType($sinkA, 'syncplay_playback_play'), 'the demoted ex-host hears it');
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_playback_play', ['position' => 8]));
        self::assertContains('NOT_HOST', $this->errorCodesOf($sinkA));
    }

    // ---- Group list ------------------------------------------------------------------

    public function testGroupListShowsOnlyThisScopesLiveShadowRooms(): void
    {
        $worker = $this->makeWorker();

        // user-a on server-a holds two rooms; user-b on server-b holds one.
        $sinkA = [];
        $connA = $this->authedClient($worker, 'token-a', $sinkA);
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_group_join', ['group_id' => 'r1']));
        $sinkB = [];
        $connB = $this->authedClient($worker, 'token-b', $sinkB);
        $worker->onMessage($connB, $this->canonicalFrame('syncplay_group_join', ['group_id' => 'r2']));
        $sinkC = [];
        $connC = $this->authedClient($worker, 'token-c', $sinkC);
        $worker->onMessage($connC, $this->canonicalFrame('syncplay_group_join', ['group_id' => 'r2']));
        $sinkBob = [];
        $connBob = $this->authedClient($worker, 'token-bob', $sinkBob, 'user-b', 'server-b');
        $worker->onMessage($connBob, $this->canonicalFrame('syncplay_group_join', ['group_id' => 'r3']));

        // Give r1 media truth: A is host of r1 and plays something.
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_playback_play', [
            'position' => 1000,
            'media_id' => 'movie-9',
        ]));

        $worker->onMessage($connA, $this->canonicalFrame('syncplay_group_list', []));
        $lists = $this->sinkFramesOfType($sinkA, 'syncplay_group_list');
        self::assertCount(1, $lists);
        self::assertSame(2, $lists[0]['count'], 'own rooms only: r1 + r2');
        /** @var array<int, array<string, mixed>> $ownGroups */
        $ownGroups = $lists[0]['groups'];
        /** @var array<string, array<string, mixed>> $byId */
        $byId = [];
        foreach ($ownGroups as $entry) {
            $entryId = $entry['id'] ?? null;
            self::assertIsString($entryId);
            $byId[$entryId] = $entry;
        }
        self::assertSame(['r1', 'r2'], array_keys($byId));
        self::assertSame(1, $byId['r1']['member_count']);
        self::assertSame(2, $byId['r2']['member_count']);
        self::assertFalse($byId['r1']['has_password']);
        self::assertTrue($byId['r1']['is_playing']);
        self::assertSame('movie-9', $byId['r1']['current_media']);
        self::assertFalse($byId['r2']['is_playing']);
        self::assertNull($byId['r2']['current_media']);

        // The other (server, owner) sees its own world only.
        $worker->onMessage($connBob, $this->canonicalFrame('syncplay_group_list', []));
        $bobLists = $this->firstSinkFrameOfType($sinkBob, 'syncplay_group_list');
        self::assertIsArray($bobLists);
        $bobGroups = $bobLists['groups'] ?? null;
        self::assertIsArray($bobGroups);
        self::assertSame(['r3'], array_column($bobGroups, 'id'));

        // A room the last members just left is NOT listed (emptied, pre-sweep).
        $worker->onMessage($connB, $this->canonicalFrame('syncplay_group_leave', []));
        $worker->onMessage($connC, $this->canonicalFrame('syncplay_group_leave', []));
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_group_list', []));
        $lists = $this->sinkFramesOfType($sinkA, 'syncplay_group_list');
        self::assertSame(1, $lists[1]['count'], 'only r1 remains live');
        /** @var array<int, array<string, mixed>> $r1Groups */
        $r1Groups = $lists[1]['groups'];
        self::assertSame('r1', $r1Groups[0]['id']);
    }

    // ---- Scope / ownership law over the new catalog ------------------------------------

    public function testCrossOwnerCanonicalRoomsSameNameNeverShare(): void
    {
        $worker = $this->makeWorker();

        $sinkAlice = [];
        $connAlice = $this->authedClient($worker, 'token-a', $sinkAlice, 'user-a', 'server-a');
        $worker->onMessage($connAlice, $this->canonicalFrame('syncplay_group_create', ['group_name' => 'party']));
        $worker->onMessage($connAlice, $this->canonicalFrame('syncplay_playback_play', [
            'position' => 90000,
            'media_id' => 'alice-only',
        ]));

        // Bob creates the SAME friendly name on HIS server: different scope —
        // nothing of Alice's may read as his, and no frame of hers may arrive.
        $sinkBob = [];
        $connBob = $this->authedClient($worker, 'token-bob', $sinkBob, 'user-b', 'server-b');
        $worker->onMessage($connBob, $this->canonicalFrame('syncplay_group_create', ['group_name' => 'party']));

        self::assertFalse($this->sinkHasType($sinkBob, 'syncplay_playback_play'));
        $states = $this->sinkFramesOfType($sinkBob, 'syncplay_group_state');
        self::assertCount(1, $states);
        $bobRoom = $this->groupOfFrame($states[0]);
        self::assertSame(0, $bobRoom['playback_position'], 'a scope breach would show up as borrowed state');
        self::assertNull($bobRoom['current_media_id']);
        self::assertSame(2, SyncPlayRelayWorker::getActiveRoomCount());
    }

    public function testJoinImpliesLeaveRehomesIdentityAndElectsBehind(): void
    {
        $worker = $this->makeWorker();
        $sinkA = [];
        $connA = $this->authedClient($worker, 'token-a', $sinkA);
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_group_create', ['group_name' => 'r1']));
        $aId = $this->yourIdOf($sinkA);
        $sinkB = [];
        $connB = $this->authedClient($worker, 'token-b', $sinkB);
        $worker->onMessage($connB, $this->canonicalFrame('syncplay_group_join', ['group_id' => 'r1']));

        // A re-homes to r2 — r1 must NOT keep a ghost host.
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_group_join', ['group_id' => 'r2']));

        $statesA = $this->sinkFramesOfType($sinkA, 'syncplay_group_state');
        self::assertCount(2, $statesA, 'A keeps create-state plus the new room state');
        $newRoom = $this->groupOfFrame($statesA[1]);
        self::assertSame('r2', $newRoom['group_id']);
        self::assertSame($aId, $newRoom['host_id'], 'first member of a fresh shadow room is its host');

        self::assertTrue($this->sinkHasType($sinkB, 'syncplay_host_elect'), 'the abandoned room elects, not haunts');
        $statesB = $this->sinkFramesOfType($sinkB, 'syncplay_group_state');
        $latestB = end($statesB);
        self::assertIsArray($latestB);
        $beesRoom = $this->groupOfFrame($latestB);
        self::assertSame(1, $beesRoom['member_count']);
        self::assertSame('r1', $beesRoom['group_id'], 'B must not be re-homed to A\'s new room by A\'s move');
        self::assertSame(2, SyncPlayRelayWorker::getActiveRoomCount());

        // The promoted B runs r1; A runs r2; neither can speak for the other's room.
        $worker->onMessage($connB, $this->canonicalFrame('syncplay_playback_play', ['position' => 4000]));
        self::assertTrue($this->sinkHasType($sinkB, 'syncplay_playback_play'));
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_playback_play', ['position' => 5000]));
        self::assertTrue($this->sinkHasType($sinkA, 'syncplay_playback_play'), 'A is host of its NEW room');
        foreach ($sinkB as $raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && ($decoded['type'] ?? null) === 'syncplay_playback_play') {
                self::assertSame(4000, $decoded['position'], 'r1 heard only its own room\'s 4000ms play');
            }
        }
    }

    // ---- Pending-command coexistence (the two-sockets question) ---------------------------

    public function testRoomSocketAndPendingCommandLaneCoexistOnMultipleSocketsPerUser(): void
    {
        $worker = $this->makeWorker();

        $sinkIdle = [];
        $connIdle = $this->authedClient($worker, 'token-a', $sinkIdle);
        $sinkRoom = [];
        $connRoom = $this->authedClient($worker, 'token-b', $sinkRoom);
        $worker->onMessage($connRoom, $this->canonicalFrame('syncplay_group_create', ['group_name' => 'party']));

        self::assertSame(2, SyncPlayRelayWorker::getActiveConnectionCount());

        // The pending_command family addresses the USER's sockets, room or not:
        // hubRelay socket + syncplay socket for one account is the mobile
        // reality, and the hub must take it sanely (S93 law, untouched).
        $frame = '{"type":"pending_command","command":"play_media","media_id":"m7"}';
        self::assertSame(2, SyncPlayRelayWorker::deliverToUser('user-a', 'server-a', $frame));
        self::assertSame(
            0,
            SyncPlayRelayWorker::deliverToUser('user-b', 'server-a', $frame),
            'an identity with no socket gets zero',
        );

        $worker->onMessage($connRoom, $this->canonicalFrame('syncplay_playback_play', ['position' => 6000]));

        self::assertSame(
            ['pending_command'],
            $this->typesOf($sinkIdle),
            'the roomless socket gets commands, not room traffic',
        );
        self::assertSame(
            ['syncplay_group_state', 'pending_command', 'syncplay_playback_play'],
            $this->typesOf($sinkRoom),
        );
    }

    // ---- Wire-boundary sanitation -----------------------------------------------------------

    public function testMalformedPayloadFieldsFallToServerDefaults(): void
    {
        $worker = $this->makeWorker();
        $sinkA = [];
        $connA = $this->authedClient($worker, 'token-a', $sinkA);
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_group_create', ['member_name' => 123]));
        $group = $this->groupOf($sinkA);
        $aId = $this->yourIdOf($sinkA);
        self::assertSame('New Group', $group['group_name'], 'a create without a name groups under the server default');
        /** @var array<string, array<string, mixed>> $sanitizedMembers */
        $sanitizedMembers = $group['members'];
        self::assertSame(
            'Host',
            $sanitizedMembers[$aId]['name'],
            'a non-string member_name takes the create-path default',
        );

        $worker->onMessage($connA, $this->canonicalFrame('syncplay_playback_play', ['position' => 'abc']));
        $acks = $this->sinkFramesOfType($sinkA, 'syncplay_playback_play');
        self::assertSame(0, $acks[0]['position'], 'a non-numeric position sanitizes to 0');
        $this->assertUnixMs($acks[0]['server_time'], 'a missing server_time is filled with the hub clock');

        $worker->onMessage($connA, $this->canonicalFrame('syncplay_time_ping', []));
        $pongs = $this->sinkFramesOfType($sinkA, 'syncplay_time_pong');
        self::assertSame(
            0,
            $pongs[0]['client_time'],
            'a ping without client_time echoes 0, as TimeSync::processPing does',
        );
    }

    public function testJoinWithoutGroupIdIsRefusedAndProtectedJoinsAreShadowAllowed(): void
    {
        $worker = $this->makeWorker();
        $sinkA = [];
        $connA = $this->authedClient($worker, 'token-a', $sinkA);

        $worker->onMessage($connA, $this->canonicalFrame('syncplay_group_join', []));
        $errors = $this->sinkFramesOfType($sinkA, 'syncplay_error');
        self::assertSame('syncplay.group_not_found', $errors[0]['error_code'] ?? null);
        self::assertSame(0, SyncPlayRelayWorker::getActiveRoomCount());

        // A group gate arriving over the relay is accepted-and-ignored ON
        // PURPOSE: the (server_id, owner) scope already keeps a hub room
        // private (worker docblock + SPEC §8.4). Pinned so the choice is
        // visible, not accidental.
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_group_create', [
            'group_name' => 'gate-room',
            'password_hash' => str_repeat('a', 64),
        ]));
        $sinkB = [];
        $connB = $this->authedClient($worker, 'token-b', $sinkB);
        $worker->onMessage($connB, $this->canonicalFrame('syncplay_group_join', [
            'group_id' => 'gate-room',
            'password_hash' => str_repeat('b', 64),
        ]));
        $state = $this->firstSinkFrameOfType($sinkB, 'syncplay_group_state');
        self::assertIsArray($state, 'the wrong gate still joins the owner-scoped shadow room');
        self::assertSame(2, $this->groupOfFrame($state)['member_count']);
        self::assertFalse($this->sinkHasType($sinkB, 'syncplay_error'));
    }

    public function testRoomlessOpsFailLoudWithServerCodes(): void
    {
        $worker = $this->makeWorker();
        $sinkA = [];
        $connA = $this->authedClient($worker, 'token-a', $sinkA);

        $worker->onMessage($connA, $this->canonicalFrame('syncplay_group_leave', []));
        $errors = $this->sinkFramesOfType($sinkA, 'syncplay_error');
        self::assertSame('syncplay.leave_failed', $errors[0]['error_code'] ?? null, 'the server leave-failure shape');

        $worker->onMessage($connA, $this->canonicalFrame('syncplay_playback_sync', []));
        $errors = $this->sinkFramesOfType($sinkA, 'syncplay_error');
        self::assertCount(2, $errors);
        self::assertSame('NOT_IN_GROUP', $errors[1]['error_code']);

        $worker->onMessage($connA, $this->canonicalFrame('syncplay_host_transfer', ['new_host_id' => 'x']));
        $errors = $this->sinkFramesOfType($sinkA, 'syncplay_error');
        self::assertSame('NOT_IN_GROUP', $errors[2]['error_code']);

        $worker->onMessage($connA, $this->canonicalFrame('syncplay_playback_queue', ['queue' => []]));
        $errors = $this->sinkFramesOfType($sinkA, 'syncplay_error');
        self::assertSame('NOT_IN_GROUP', $errors[3]['error_code']);
    }

    public function testPlaybackAnchorsFlowInMillisecondsAcrossEveryCanonicalReader(): void
    {
        $worker = $this->makeWorker();
        $sinkA = [];
        $connA = $this->authedClient($worker, 'token-a', $sinkA);
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_group_create', ['group_name' => 'party']));

        $worker->onMessage($connA, $this->canonicalFrame('syncplay_playback_play', ['position' => 60000]));
        $ack = $this->sinkFramesOfType($sinkA, 'syncplay_playback_play')[0];
        $this->assertUnixMs($ack['timestamp']);

        // Late joiner's snapshot state:
        $sinkB = [];
        $connB = $this->authedClient($worker, 'token-b', $sinkB);
        $worker->onMessage($connB, $this->canonicalFrame('syncplay_group_join', ['group_id' => 'party']));
        self::assertSame(
            60000,
            $this->groupOf($sinkB)['playback_position'],
            'the state anchor rides the same ms number the host sent',
        );

        // And the sync echo agrees:
        $worker->onMessage($connB, $this->canonicalFrame('syncplay_playback_sync', []));
        $echo = $this->sinkFramesOfType($sinkB, 'syncplay_playback_sync')[0];
        self::assertSame(60000, $echo['position']);
    }

    public function testLastMemberLeaveClearsEveryCanonicalBook(): void
    {
        $worker = $this->makeWorker();
        $sinkA = [];
        $connA = $this->authedClient($worker, 'token-a', $sinkA);
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_group_create', ['group_name' => 'party']));
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_playback_play', [
            'position' => 90000,
            'media_id' => 'm1',
        ]));
        $worker->onMessage($connA, $this->canonicalFrame('syncplay_playback_queue', [
            'queue' => [['media_id' => 'q1']],
        ]));

        $worker->onMessage($connA, $this->canonicalFrame('syncplay_group_leave', []));

        // A room re-formed by the same (or any) client starts from truth:
        // never from the dead session's anchor, host, clocks, state or queue.
        $sinkB = [];
        $connB = $this->authedClient($worker, 'token-b', $sinkB);
        $worker->onMessage($connB, $this->canonicalFrame('syncplay_group_join', ['group_id' => 'party']));
        $group = $this->groupOf($sinkB);
        self::assertSame(0, $group['playback_position']);
        self::assertNull($group['current_media_id']);
        self::assertSame('stopped', $group['playback_state']);
        self::assertSame([], $group['queue']);
        self::assertSame(1, $group['member_count']);
        /** @var array<string, array<string, mixed>> $freshMembers */
        $freshMembers = $group['members'];
        self::assertSame(array_keys($freshMembers)[0], $group['host_id'], 'the fresh room\'s host is its first member');
    }

    // =====================================================================
    // Harness (mirrors SyncPlayRelayWorkerTest — same production code paths)
    // =====================================================================

    private function makeWorker(): SyncPlayRelayWorker
    {
        return new SyncPlayRelayWorker(SyncPlayRelayWorker::DEFAULT_PORT, 1, $this->buildContainer());
    }

    /**
     * Grant the token, bind the server owner, and open one authenticated
     * recording client on `$worker` — the full production connect path minus
     * the network. `$sink` receives every frame written to the socket.
     *
     * @param list<string> $sink
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
     * @param array<string, mixed> $payload
     */
    private function canonicalFrame(string $type, array $payload): string
    {
        return (string) json_encode(array_merge(['type' => $type], $payload));
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
     * @param list<string> $sink
     *
     * @return array<string, mixed>
     */
    private function groupOf(array $sink): array
    {
        $state = $this->firstSinkFrameOfType($sink, 'syncplay_group_state');
        self::assertIsArray($state);
        self::assertIsArray($state['group'] ?? null);

        return $state['group'];
    }

    /**
     * The `group` payload of any decoded group_state frame (assertion narrows
     * the mixed offset — a group_state missing its object is a real failure).
     *
     * @param array<string, mixed> $frame
     *
     * @return array<string, mixed>
     */
    private function groupOfFrame(array $frame): array
    {
        self::assertIsArray($frame['group'] ?? null, 'group_state nests its payload under `group`');

        return $frame['group'];
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

    /**
     * Every decoded frame of $type that landed in $sink, in arrival order.
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
     * @param list<string> $sink
     *
     * @return array<string, mixed>|null
     */
    private function firstSinkFrameOfType(array $sink, string $type): ?array
    {
        return $this->sinkFramesOfType($sink, $type)[0] ?? null;
    }

    /**
     * The arrival-order `type` list of a sink. Every sunk frame must decode to
     * an object with a string type — if one doesn't, that IS the bug, so the
     * assertions here narrow `mixed` at the boundary instead of casting it.
     *
     * @param list<string> $sink
     *
     * @return list<string>
     */
    private function typesOf(array $sink): array
    {
        $types = [];
        foreach ($sink as $raw) {
            $decoded = json_decode($raw, true);
            self::assertIsArray($decoded, 'every frame written to a sink is a JSON object');
            $type = $decoded['type'] ?? null;
            self::assertIsString($type, 'every frame carries a string type');
            $types[] = $type;
        }

        return $types;
    }

    /**
     * The arrival-order `error_code` list of the syncplay_error frames in $sink.
     *
     * @param list<string> $sink
     *
     * @return list<string>
     */
    private function errorCodesOf(array $sink): array
    {
        $codes = [];
        foreach ($this->sinkFramesOfType($sink, 'syncplay_error') as $error) {
            $code = $error['error_code'] ?? null;
            self::assertIsString($code, 'every syncplay_error carries a string error_code');
            $codes[] = $code;
        }

        return $codes;
    }

    private function assertUnixMs(mixed $value, string $message = ''): void
    {
        self::assertIsInt($value, $message === '' ? 'expected a unix-milliseconds integer' : $message);
        $now = (int) round(microtime(true) * 1000);
        self::assertGreaterThanOrEqual($now - 60_000, $value, $message);
        self::assertLessThanOrEqual($now + 60_000, $value, $message);
    }

    private function assertUnixSeconds(mixed $value, string $message = ''): void
    {
        self::assertIsInt($value, $message === '' ? 'expected a unix-seconds integer' : $message);
        $now = time();
        self::assertGreaterThanOrEqual($now - 60, $value, $message);
        self::assertLessThanOrEqual($now + 60, $value, $message);
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
     * A mock TcpConnection whose send() appends every JSON frame it is asked
     * to write into $sink (by reference).
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
     * Build a REAL WorkermanRequest from raw text so the token travels the
     * same header path production parses (S237 discipline).
     */
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
