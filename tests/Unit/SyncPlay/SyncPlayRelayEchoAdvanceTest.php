<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\SyncPlay;

use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\SyncPlay\SyncPlayRelayWorker;
use Phlix\Hub\Tests\Support\LoggerFactoryIsolation;
use Phlix\Hub\Tests\Support\SyncPlay\RegistersSyncPlayClients;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Workerman\Connection\TcpConnection;

use function array_key_first;
use function array_merge;
use function is_array;
use function json_decode;
use function json_encode;
use function microtime;
use function round;
use function usleep;

/**
 * INCIDENT playjump — hub twin of the server's anchor-advance echo cure
 * (server reference: phlix-server `86427c6d`, report
 * `.reviews/INCIDENT-playjump-anchoradvance.md`).
 *
 * The defect: the relay's `syncplay_playback_sync` echo answers every ~5s
 * member report with the RAW stored room anchor (`$roomPlayback[...]['position']`),
 * a value written only by the host's explicit play/pause/seek. While the
 * group plays, that echo is a frozen position: every drift-correcting
 * client's 'play'-arm watcher re-seeks backward — the rubber-band the era
 * report (`INCIDENT-playjump-era.md` §4) names as the NEW-SERVER-CAUSE of
 * the relay-mode jump-back, introduced with `cc1e1289`.
 *
 * The cure mirrors the landed server shape verbatim, reduced to the hub's
 * honestly smaller state (the relay has no member ingest, no in-sync
 * predicate and no nudge on this path — the worker's own deviations list):
 *
 *     while playing:  position = raw + max(0, nowMs − anchorStamp)  (1×, exact ms)
 *     otherwise:      position = raw anchor (unchanged)
 *
 * with ONE reference tick shared by the advanced position and the
 * `server_time` stamp, so the frame's own two clock-bearing fields can
 * never disagree. That law makes every test here wire-internal: T0 is
 * captured from the PLAY/SEEK frame's payload `server_time` (which equals
 * the anchor stamp whenever the sender omits its own `server_time` — the
 * handler's default), and the advance is pinned as an identity against the
 * echo's OWN `server_time`, not against a test-side clock read.
 *
 * No new fields: unlike the server, the hub anchor ALREADY carries its
 * write-stamp (`playbackAnchor()` stores `timestamp` = the hub ms clock)
 * and the state is process-local static memory with no serialize bridge,
 * so the persistence leg of the server cure is structurally N/A (lane
 * report, Determination §3). Quantization and a raw/predicate split stay
 * REJECTED on record, inherited.
 *
 * Scope law pinned here too: the echo chain ONLY. The `syncplay_group_state`
 * snapshot surface keeps answering the raw anchor (the server keeps
 * getState() raw) — the boundary test asserts BOTH sides in one breath.
 *
 * The room fixtures keep host and member on ONE server_id (`server-a`)
 * because the scoped room key derives from (server_id, owner): two
 * server_ids would form two different rooms, and one shared room is the
 * rubber-band's stage. Two distinct TOKENS under one identity is exactly
 * what the sibling dialect file does.
 *
 * @package Phlix\Hub\Tests\Unit\SyncPlay
 */
final class SyncPlayRelayEchoAdvanceTest extends TestCase
{
    use LoggerFactoryIsolation;
    use RegistersSyncPlayClients;

    private string $tmpDir;

    /** Anchor position for the playing-room fixtures (ms, wire scale). */
    private const PLAY_POSITION = 30_000;

    /** Minimum wall gap the fixtures force between anchor write and echo. */
    private const MIN_GAP_MS = 50;

    protected function setUp(): void
    {
        parent::setUp();

        SyncPlayRelayWorker::reset();

        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-echo-advance-test-' . uniqid();
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

    // ---- The conviction: the echo must advance on the frame's own tick ------

    public function testPlayingRoomEchoAdvancesByExactlyTheTickItStamps(): void
    {
        $sinkHost = [];
        $sinkMember = [];
        [, $member] = $this->playingRoom($sinkHost, $sinkMember, self::PLAY_POSITION);

        usleep(60_000);
        $this->reportSync($member, 555);

        $play = $this->onlyFrame($sinkMember, 'syncplay_playback_play');
        $echo = $this->onlyFrame($sinkMember, 'syncplay_playback_sync');

        $anchorStamp = $play['server_time'];
        self::assertIsInt($anchorStamp, 'the relayed PLAY defaults server_time to the hub stamp');

        // One-reference-tick law: the position rides the SAME clock the frame
        // declares. Raw anchor + (echo's own tick − anchor stamp), exact ms.
        // At parent this reads `Failed asserting that 30000 is identical to
        // 30061`-plus — the frozen echo, convicted against its OWN server_time.
        self::assertSame(
            self::PLAY_POSITION + ($this->intField($echo, 'server_time') - $anchorStamp),
            $echo['position'],
            'the echo position is anchor + elapsed judged on the echo tick itself',
        );
        self::assertGreaterThanOrEqual(
            self::MIN_GAP_MS,
            $this->intField($echo, 'server_time') - $anchorStamp,
            'the fixture forced a real gap, so a raw echo cannot satisfy the identity by luck',
        );
        self::assertGreaterThan(
            self::PLAY_POSITION,
            $echo['position'],
            'while playing the echo never answers the frozen anchor'
        );
        self::assertTrue($echo['is_playing']);
        self::assertSame('m1', $echo['current_media_id']);
        self::assertSame(
            $this->onlyFrame($sinkHost, 'syncplay_group_state')['your_id'] ?? null,
            $echo['member_id'],
            'HOST-stamped law survives the cure (and the stamp is never the reporter)',
        );
    }

    public function testEchoPositionsStrictlyIncreaseAcrossTheCadence(): void
    {
        $sinkHost = [];
        $sinkMember = [];
        [, $member] = $this->playingRoom($sinkHost, $sinkMember, self::PLAY_POSITION);

        $this->reportSync($member, 555);
        $first = $this->onlyFrame($sinkMember, 'syncplay_playback_sync');

        usleep(60_000);
        $this->reportSync($member, 555);
        $frames = $this->framesOfType($sinkMember, 'syncplay_playback_sync');
        self::assertCount(2, $frames);
        $second = $frames[1];

        // The rubber-band fingerprint: two consecutive echoes of the SAME
        // position while playing (era report §4 owner-data item (c)).
        self::assertGreaterThanOrEqual(
            self::MIN_GAP_MS,
            $this->intField($second, 'position') - $this->intField($first, 'position'),
            'consecutive echoes advance by at least the wall gap — never a frozen pair',
        );
        self::assertGreaterThanOrEqual(
            self::MIN_GAP_MS,
            $this->intField($second, 'server_time') - $this->intField($first, 'server_time'),
        );
    }

    public function testSeekWhilePlayingReAnchorsAndAdvanceResumesFromTheSeekTick(): void
    {
        $sinkHost = [];
        $sinkMember = [];
        [$worker, $host, $member] = $this->playingRoom($sinkHost, $sinkMember, 10_000);

        $worker->onMessage($host, $this->frame('syncplay_playback_seek', [
            'from_position' => 10_000,
            'to_position' => 80_000,
        ]));

        usleep(60_000);
        $this->reportSync($member, 555);

        $seek = $this->onlyFrame($sinkMember, 'syncplay_playback_seek');
        $echo = $this->onlyFrame($sinkMember, 'syncplay_playback_sync');

        // The seek is an anchor write (the server's setPlaybackPosition twin):
        // advance resumes from the SEEK stamp against the new raw value, and
        // the PLAY stamp contributes nothing further (no double-count).
        self::assertSame(
            80_000 + ($this->intField($echo, 'server_time') - $this->intField($seek, 'server_time')),
            $echo['position'],
            'a mid-play seek re-anchors: echo = to_position + elapsed since the seek',
        );
        self::assertGreaterThan(80_000, $echo['position']);
        self::assertTrue($echo['is_playing'], 'seek leaves playback_state alone — the anchor still advances');
    }

    // ---- Guards: the cure must NOT over-apply (green at parent, green at tip)

    public function testPausedRoomEchoKeepsTheRawAnchorUnchanged(): void
    {
        $sinkHost = [];
        $sinkMember = [];
        [$worker, $host, $member] = $this->playingRoom($sinkHost, $sinkMember, self::PLAY_POSITION);

        $worker->onMessage($host, $this->frame('syncplay_playback_pause', ['position' => 12_345]));

        usleep(60_000);
        $this->reportSync($member, 555);
        $echo = $this->onlyFrame($sinkMember, 'syncplay_playback_sync');

        // NOT playing → the raw anchor, verbatim: pause is an anchor WRITE
        // and nothing extrapolates from it (the server's paused arm, mirrored).
        self::assertSame(12_345, $echo['position'], 'paused echo answers the exact raw anchor');
        self::assertFalse($echo['is_playing']);
    }

    public function testNeverPlayedRoomEchoesZeroAnchor(): void
    {
        $sinkHost = [];
        $sinkMember = [];
        [, , $member] = $this->emptyRoom($sinkHost, $sinkMember);

        usleep(60_000);
        $this->reportSync($member, 555);
        $echo = $this->onlyFrame($sinkMember, 'syncplay_playback_sync');

        // A null anchor has no stamp to extrapolate from: the advance reads
        // through to 0, the reported 555 never leaks in.
        self::assertSame(0, $echo['position']);
        self::assertFalse($echo['is_playing']);
    }

    public function testFutureStampCannotRewindTheAnchor(): void
    {
        $sinkHost = [];
        $sinkMember = [];
        [, $member] = $this->playingRoom($sinkHost, $sinkMember, self::PLAY_POSITION);

        // Push the anchor stamp into the future (a clock that predates the
        // anchor — the max(0, elapsed) arm). On the cure the echo answers the
        // raw anchor exactly, never anchor−Δ.
        $anchors = new ReflectionProperty(SyncPlayRelayWorker::class, 'roomPlayback');
        /** @var array<string, array<string, mixed>> $map */
        $map = $anchors->getValue();
        self::assertNotEmpty($map, 'the playing-room fixture must have written an anchor');
        $key = array_key_first($map);
        self::assertIsString($key);
        $map[$key]['timestamp'] = (int) round(microtime(true) * 1000) + 5_000_000;
        $anchors->setValue(null, $map);

        $this->reportSync($member, 555);
        $echo = $this->onlyFrame($sinkMember, 'syncplay_playback_sync');

        self::assertSame(
            self::PLAY_POSITION,
            $echo['position'],
            'elapsed clamps at zero: a clock predating the stamp never rewinds the anchor',
        );
        self::assertTrue($echo['is_playing']);
    }

    public function testFloatAnchorTruncatesBeforeAdvancing(): void
    {
        // The echo's pre-existing (int) cast law is preserved by the cure:
        // the float anchor truncates exactly as the raw echo always did, and
        // the elapsed rides on TOP of the truncated raw.
        $sinkHost = [];
        $sinkMember = [];
        [, $member] = $this->playingRoom($sinkHost, $sinkMember, 42.5);

        usleep(60_000);
        $this->reportSync($member, 555);

        $play = $this->onlyFrame($sinkMember, 'syncplay_playback_play');
        $echo = $this->onlyFrame($sinkMember, 'syncplay_playback_sync');

        self::assertSame(
            42 + ($this->intField($echo, 'server_time') - $this->intField($play, 'server_time')),
            $echo['position'],
            'float anchor truncates as the raw echo always did; advance rides the int',
        );
    }

    // ---- Scope law: snapshots stay RAW ----------------------------------------

    public function testGroupStateSnapshotStaysRawWhileTheEchoAdvances(): void
    {
        $sinkHost = [];
        $sinkMember = [];
        [, $member] = $this->playingRoom($sinkHost, $sinkMember, self::PLAY_POSITION);

        usleep(60_000);

        // A late joiner's snapshot surface: the RAW anchor (the server keeps
        // getState() raw — the cure's scope is the echo chain, not the
        // roster payloads).
        $sinkLate = [];
        $this->grantToken('token-late', 'user-a', 'server-a');
        $this->connectSyncPlayClient('/syncplay/server-a', 'token-late', $sinkLate);
        $late = $this->lastSyncPlayConnection();
        $this->syncPlayWorker()->onMessage($late, $this->frame('syncplay_group_join', [
            'group_id' => 'party',
            'member_name' => 'Gamma',
        ]));
        $group = $this->onlyFrame($sinkLate, 'syncplay_group_state')['group'];
        self::assertIsArray($group);
        self::assertSame(
            self::PLAY_POSITION,
            $group['playback_position'],
            'group_state answers the raw anchor by design — scope is the echo chain only',
        );

        // …while the SAME instant's echo already advanced past it.
        $this->reportSync($member, 555);
        $echo = $this->onlyFrame($sinkMember, 'syncplay_playback_sync');
        self::assertGreaterThan(
            self::PLAY_POSITION,
            $echo['position'],
            'the echo chain advances even as snapshots stay raw'
        );
    }

    // ---- Harness --------------------------------------------------------------

    /**
     * A canonical room in state=playing at $position with host + member
     * latched and the PLAY already relayed to both. Sinks are taken BY
     * REFERENCE (the recording closures append through the trait's &$sink
     * binding — a by-value return would freeze a snapshot and lose every
     * later frame).
     *
     * @param list<string> $sinkHost
     * @param list<string> $sinkMember
     * @return array{0: SyncPlayRelayWorker, 1: TcpConnection, 2: TcpConnection} host, member
     */
    private function playingRoom(array &$sinkHost, array &$sinkMember, int|float $position): array
    {
        [$worker, $host, $member] = $this->emptyRoom($sinkHost, $sinkMember);

        $worker->onMessage($host, $this->frame('syncplay_playback_play', [
            'position' => $position,
            'media_id' => 'm1',
        ]));

        return [$worker, $host, $member];
    }

    /**
     * A canonical two-member room (host + member, both latched, never
     * played). Sinks by reference — see {@see self::playingRoom()}.
     *
     * @param list<string> $sinkHost
     * @param list<string> $sinkMember
     * @return array{0: SyncPlayRelayWorker, 1: TcpConnection, 2: TcpConnection} host, member
     */
    private function emptyRoom(array &$sinkHost, array &$sinkMember): array
    {
        $worker = $this->syncPlayWorker();

        $this->grantToken('token-host', 'user-a', 'server-a');
        $this->setServerOwner('server-a', 'user-a');
        $this->connectSyncPlayClient('/syncplay/server-a', 'token-host', $sinkHost);
        $host = $this->lastSyncPlayConnection();
        $worker->onMessage($host, $this->frame('syncplay_group_create', [
            'group_name' => 'party',
            'member_name' => 'Alpha',
        ]));

        $this->grantToken('token-member', 'user-a', 'server-a');
        $this->connectSyncPlayClient('/syncplay/server-a', 'token-member', $sinkMember);
        $member = $this->lastSyncPlayConnection();
        $worker->onMessage($member, $this->frame('syncplay_group_join', [
            'group_id' => 'party',
            'member_name' => 'Beta',
        ]));

        return [$worker, $host, $member];
    }

    /** Fire one member position report (the 5s cadence frame). */
    private function reportSync(TcpConnection $member, int $claimedPosition): void
    {
        $this->syncPlayWorker()->onMessage($member, $this->frame('syncplay_playback_sync', [
            'member_id' => 'spoofed',
            'group_id' => 'party',
            'position' => $claimedPosition,
            'is_playing' => false,
        ]));
    }

    /** @param array<string, mixed> $payload */
    private function frame(string $type, array $payload): string
    {
        return (string) json_encode(array_merge(['type' => $type], $payload));
    }

    /**
     * @param list<string> $sink
     * @return list<array<string, mixed>>
     */
    private function framesOfType(array $sink, string $type): array
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
     * @return array<string, mixed>
     */
    private function onlyFrame(array $sink, string $type): array
    {
        $frames = $this->framesOfType($sink, $type);
        self::assertCount(1, $frames, "expected exactly one {$type} frame");

        return $frames[0];
    }

    /**
     * Wire-frame field narrowed to int — the repo's no-mixed-cast law (the
     * assert-is-X narrows, same idiom as the canonical dialect file).
     *
     * @param array<string, mixed> $frame
     */
    private function intField(array $frame, string $key): int
    {
        $value = $frame[$key] ?? null;
        self::assertIsInt($value, "frame field '{$key}' must carry an int on the wire");

        return $value;
    }
}
