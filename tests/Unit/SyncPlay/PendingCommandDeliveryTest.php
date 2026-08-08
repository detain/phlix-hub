<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\SyncPlay;

use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\SyncPlay\PendingCommandDispatcher;
use Phlix\Hub\SyncPlay\PendingCommandProtocol;
use Phlix\Hub\SyncPlay\SyncPlayRelayWorker;
use Phlix\Hub\Tests\Support\LoggerFactoryIsolation;
use Phlix\Hub\Tests\Support\SyncPlay\RegistersSyncPlayClients;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workerman\Protocols\Http\Request as WorkermanRequest;

use function abs;
use function glob;
use function is_dir;
use function is_file;
use function json_decode;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function time;
use function uniqid;
use function unlink;

/**
 * S93 — {@see SyncPlayRelayWorker::deliverToUser()}: who receives a pending
 * command, and who must not.
 *
 * ## The defect this suite exists to catch
 *
 * `deliverToUser()` walks a PROCESS-GLOBAL client map and writes a frame to the
 * sockets it matches. Two failures are possible and neither produces an error:
 *
 *  1. **Over-delivery.** A match on the user alone (or a loose comparison against
 *     a client whose `userId` is null) fans one person's spoken command out to
 *     sockets that were never addressed — including, for an empty identity, every
 *     socket on the hub. Nothing throws; the count merely comes back larger.
 *  2. **Under-delivery.** A match that is too narrow (say, one that also required
 *     room membership) delivers to nobody, the count is 0, and the Alexa skill
 *     speaks its honest "no open app" answer forever. The feature is dead and
 *     every test that only asserts "the wrong sockets stayed empty" still passes.
 *
 * Failure 2 is why every leak assertion in this file sits beside a **succeeding
 * control**: a `deliverToUser()` that delivered to nobody would satisfy every
 * "did not receive" assertion perfectly. The empty sinks only mean something when
 * a sibling sink, in the same test, received the frame and returned a count of 1.
 *
 * ## Clients are registered exactly as production registers them
 *
 * Through {@see SyncPlayRelayWorker::onWebSocketConnect()} with a real
 * {@see WorkermanRequest} built from raw upgrade text and a real relay token on a
 * sanctioned carrier — the S237 idiom from {@see SyncPlayRelayWorkerTest}. A test
 * that reached into the static map directly would be asserting about a map shape
 * production might no longer produce.
 *
 * ⚠ **What that costs, stated rather than hidden.** `validateClientAuth()`
 * re-confirms that the token's user is the server's CURRENT OWNER, so two
 * different users can never both be registered against the same `server_id` by
 * the production path. The "different user, same server" leak therefore cannot be
 * built as two live sockets. The user half of the match is isolated instead by
 * {@see testTheUserHalfOfTheMatchIsAppliedOnItsOwn()}, which addresses a DIFFERENT
 * user on the SAME server with everything else held constant, and pairs the
 * expected 0 with a succeeding control on the same map.
 *
 * @package Phlix\Hub\Tests\Unit\SyncPlay
 */
final class PendingCommandDeliveryTest extends TestCase
{
    use LoggerFactoryIsolation;
    use RegistersSyncPlayClients;

    private const FRAME = '{"type":"pending_command","probe":"s93"}';

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        SyncPlayRelayWorker::reset();

        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-pending-command-test-' . uniqid();
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

    // ==================================================================
    // 1. Addressing
    // ==================================================================

    /**
     * Four clients, one address. The three that were not addressed are asserted
     * INDIVIDUALLY, with distinct messages, so a failure names which one leaked
     * — "some sink was not empty" is not a diagnosis.
     *
     * The fourth client failed authentication, so production never put it in the
     * map at all. It is included because the pre-registration guard is the only
     * thing between an unauthenticated socket and a delivered command, and a
     * regression there would show up here as a count of 2.
     */
    public function testDeliversOnlyToTheAddressedUserAndServer(): void
    {
        $this->grantToken('tok-1a', 'u-1', 'srv-A');
        $this->grantToken('tok-1b', 'u-1', 'srv-B');
        $this->grantToken('tok-2c', 'u-2', 'srv-C');
        $this->setServerOwner('srv-A', 'u-1');
        $this->setServerOwner('srv-B', 'u-1');
        $this->setServerOwner('srv-C', 'u-2');

        $addressed = [];
        $sameUserOtherServer = [];
        $otherUser = [];
        $unauthenticated = [];

        $this->connectSyncPlayClient('/syncplay/srv-A', 'tok-1a', $addressed);
        $this->connectSyncPlayClient('/syncplay/srv-B', 'tok-1b', $sameUserOtherServer);
        $this->connectSyncPlayClient('/syncplay/srv-C', 'tok-2c', $otherUser);
        $this->connectSyncPlayClient('/syncplay/srv-A', 'never-minted', $unauthenticated);

        self::assertSame(
            3,
            SyncPlayRelayWorker::getActiveConnectionCount(),
            'control: exactly three clients must be registered (the unauthenticated one is refused '
            . 'before registration). A different number means this test is not measuring what it thinks.',
        );

        $delivered = SyncPlayRelayWorker::deliverToUser('u-1', 'srv-A', self::FRAME);

        self::assertSame(
            1,
            $delivered,
            'deliverToUser() must report exactly the number of sockets it wrote to; one client is '
            . 'bound to (u-1, srv-A) and the count is what the Alexa skill speaks on',
        );
        self::assertSame(
            [self::FRAME],
            $addressed,
            'the addressed (u-1, srv-A) client did not receive the frame — the feature is dead and '
            . 'every "did not receive" assertion below would pass vacuously',
        );
        self::assertSame(
            [],
            $sameUserOtherServer,
            'LEAK: the same user\'s client on a DIFFERENT server received the command. That client '
            . 'cannot play a media id that only exists on srv-A, so the frame would fail silently on '
            . 'arrival while inflating the delivered count the skill confirms on.',
        );
        self::assertSame(
            [],
            $otherUser,
            'LEAK: ANOTHER USER\'s client received the command. One person\'s spoken intent landed on '
            . 'a stranger\'s screen.',
        );
        self::assertSame(
            [],
            $unauthenticated,
            'LEAK: a connection that failed authentication received the command',
        );
    }

    /**
     * The user half of the match, isolated: SAME server, SAME single registered
     * socket, only the addressed user id differs.
     *
     * The refusal is asserted first and the succeeding control second, on the very
     * same map — so a `deliverToUser()` that delivered to nobody at all cannot
     * pass the first assertion and hide behind it.
     */
    public function testTheUserHalfOfTheMatchIsAppliedOnItsOwn(): void
    {
        $this->grantToken('tok-1a', 'u-1', 'srv-A');
        $this->setServerOwner('srv-A', 'u-1');

        $sink = [];
        $this->connectSyncPlayClient('/syncplay/srv-A', 'tok-1a', $sink);

        self::assertSame(
            0,
            SyncPlayRelayWorker::deliverToUser('u-2', 'srv-A', self::FRAME),
            'LEAK: a command addressed to u-2 was delivered to u-1\'s socket on the same server — '
            . 'the user half of the match is not being applied',
        );
        self::assertSame([], $sink, 'LEAK: u-1\'s socket received a frame addressed to u-2');

        // The succeeding control, same map, same server, correct user.
        self::assertSame(
            1,
            SyncPlayRelayWorker::deliverToUser('u-1', 'srv-A', self::FRAME),
            'control: the SAME socket must receive the frame when it IS the addressed user, or the '
            . 'zero above means only that delivery is broken for everybody',
        );
        self::assertSame([self::FRAME], $sink, 'control: the addressed socket received nothing');
    }

    /**
     * The succeeding control for the test above, addressing the OTHER server.
     *
     * Without it, a `deliverToUser()` that always matched only the first client
     * in the map — or only ever `srv-A` — would look correct above. Here the same
     * three-client map is addressed at (u-1, srv-B) and the OPPOSITE client must
     * receive it.
     */
    public function testTheSecondServerOfTheSameUserIsReachableWithTheSameMap(): void
    {
        $this->grantToken('tok-1a', 'u-1', 'srv-A');
        $this->grantToken('tok-1b', 'u-1', 'srv-B');
        $this->setServerOwner('srv-A', 'u-1');
        $this->setServerOwner('srv-B', 'u-1');

        $onA = [];
        $onB = [];
        $this->connectSyncPlayClient('/syncplay/srv-A', 'tok-1a', $onA);
        $this->connectSyncPlayClient('/syncplay/srv-B', 'tok-1b', $onB);

        self::assertSame(2, SyncPlayRelayWorker::getActiveConnectionCount(), 'control: two clients registered');

        $delivered = SyncPlayRelayWorker::deliverToUser('u-1', 'srv-B', self::FRAME);

        self::assertSame(1, $delivered, 'addressing srv-B must reach exactly the srv-B client');
        self::assertSame([self::FRAME], $onB, 'the srv-B client did not receive a frame addressed to srv-B');
        self::assertSame(
            [],
            $onA,
            'LEAK: the srv-A client received a frame addressed to srv-B — the server half of the '
            . 'match is not being applied',
        );
    }

    /**
     * Two apps, one user, one server: both must receive it and the count must be
     * 2. This is what stops the count being a boolean wearing an int's clothes.
     */
    public function testEveryOpenAppOfTheAddressedUserOnThatServerReceivesIt(): void
    {
        $this->grantToken('tok-laptop', 'u-1', 'srv-A');
        $this->grantToken('tok-tv', 'u-1', 'srv-A');
        $this->setServerOwner('srv-A', 'u-1');

        $laptop = [];
        $tv = [];
        $this->connectSyncPlayClient('/syncplay/srv-A', 'tok-laptop', $laptop);
        $this->connectSyncPlayClient('/syncplay/srv-A', 'tok-tv', $tv);

        $delivered = SyncPlayRelayWorker::deliverToUser('u-1', 'srv-A', self::FRAME);

        self::assertSame(2, $delivered, 'two open apps must produce a delivered count of 2, not 1');
        self::assertSame([self::FRAME], $laptop, 'the first open app did not receive the frame');
        self::assertSame([self::FRAME], $tv, 'the second open app did not receive the frame');
    }

    // ==================================================================
    // 2. An empty identity never fans out
    // ==================================================================

    /**
     * `SyncPlayClient::$userId` is nullable and a blank identity is exactly what a
     * malformed push carries. If an empty string were allowed to reach the match,
     * one blank field would turn into a broadcast to every socket on the hub —
     * and the skill would confirm it, because the count would be large.
     */
    #[DataProvider('emptyIdentities')]
    public function testAnEmptyIdentityNeverFansOut(string $userId, string $serverId): void
    {
        $this->grantToken('tok-1a', 'u-1', 'srv-A');
        $this->grantToken('tok-1b', 'u-1', 'srv-B');
        $this->grantToken('tok-2c', 'u-2', 'srv-C');
        $this->setServerOwner('srv-A', 'u-1');
        $this->setServerOwner('srv-B', 'u-1');
        $this->setServerOwner('srv-C', 'u-2');

        $a = [];
        $b = [];
        $c = [];
        $this->connectSyncPlayClient('/syncplay/srv-A', 'tok-1a', $a);
        $this->connectSyncPlayClient('/syncplay/srv-B', 'tok-1b', $b);
        $this->connectSyncPlayClient('/syncplay/srv-C', 'tok-2c', $c);

        self::assertSame(
            3,
            SyncPlayRelayWorker::getActiveConnectionCount(),
            'control: three live clients must exist, or "nobody received it" is trivially true',
        );

        $delivered = SyncPlayRelayWorker::deliverToUser($userId, $serverId, self::FRAME);

        $label = 'user "' . $userId . '" / server "' . $serverId . '"';
        self::assertSame(
            0,
            $delivered,
            $label . ': an EMPTY identity must never fan out. A blank field reaching the match would '
            . 'broadcast one user\'s command to every socket on the hub and report a count the skill '
            . 'would confirm.',
        );
        self::assertSame([], $a, $label . ': client (u-1, srv-A) received a frame addressed to nobody');
        self::assertSame([], $b, $label . ': client (u-1, srv-B) received a frame addressed to nobody');
        self::assertSame([], $c, $label . ': client (u-2, srv-C) received a frame addressed to nobody');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function emptyIdentities(): iterable
    {
        yield 'empty user id' => ['', 'srv-A'];
        yield 'empty server id' => ['u-1', ''];
        yield 'both empty' => ['', ''];
    }

    // ==================================================================
    // 3. Room membership is irrelevant
    // ==================================================================

    /**
     * A pending command is addressed to a USER'S OPEN APP, not to a room. The
     * primary case — a user who has just opened Phlix and asked Alexa to start
     * something — has no room at all, so routing this through the room broadcast
     * would make the feature unreachable exactly when it is wanted.
     */
    public function testDeliveryDoesNotRequireRoomMembership(): void
    {
        $this->grantToken('tok-1a', 'u-1', 'srv-A');
        $this->setServerOwner('srv-A', 'u-1');

        $sink = [];
        $this->connectSyncPlayClient('/syncplay/srv-A', 'tok-1a', $sink);

        // Control: this client genuinely has NOT joined a room, so a green below
        // is about room-independence and not about a room that happens to exist.
        self::assertSame(
            0,
            SyncPlayRelayWorker::getActiveRoomCount(),
            'control: the client must not be in any room, or this test proves nothing about '
            . 'room-independent delivery',
        );

        $delivered = SyncPlayRelayWorker::deliverToUser('u-1', 'srv-A', self::FRAME);

        self::assertSame(
            1,
            $delivered,
            'a client that never sent group_join did not receive the command. A pending command is '
            . 'addressed to a user\'s open app, not to a SyncPlay room — requiring membership makes '
            . 'the feature unreachable in its primary case.',
        );
        self::assertSame([self::FRAME], $sink, 'the room-less client received nothing');
    }

    // ==================================================================
    // 4. The shape a client would actually need
    // ==================================================================

    /**
     * Driven through {@see PendingCommandDispatcher::onPush()} rather than from a
     * hand-built string, because a hand-built frame would pin what this TEST
     * thinks the wire looks like. What lands on the socket is decoded and every
     * field a consumer would need is asserted by name.
     */
    public function testTheDeliveredFrameCarriesTheCommandShapeAClientWouldNeed(): void
    {
        $this->grantToken('tok-1a', 'u-1', 'srv-A');
        $this->setServerOwner('srv-A', 'u-1');

        $sink = [];
        $this->connectSyncPlayClient('/syncplay/srv-A', 'tok-1a', $sink);

        $replies = [];
        $dispatcher = new PendingCommandDispatcher(
            new StructuredLogger('pending-command-test', []),
            static function (string $event, array $data) use (&$replies): void {
                $replies[] = [$event, $data];
            },
        );

        $before = time();
        $dispatcher->onPush([
            'request_id' => 'req-1',
            'reply_event' => 'reply.event.test',
            'user_id' => 'u-1',
            'server_id' => 'srv-A',
            'media_id' => 'm-9',
            'title' => 'Inception',
        ]);
        $after = time();

        self::assertCount(1, $sink, 'exactly one frame must have been written to the addressed socket');

        /** @var mixed $frame */
        $frame = json_decode($sink[0], true);
        self::assertIsArray($frame, 'what landed on the socket is not decodable JSON');

        self::assertSame(
            PendingCommandProtocol::FRAME_TYPE,
            $frame['type'] ?? null,
            'the frame type must be PendingCommandProtocol::FRAME_TYPE — a consumer dispatches on it, '
            . 'and a frame it cannot recognise is a frame it drops',
        );
        self::assertSame(
            PendingCommandProtocol::COMMAND_PLAY_MEDIA,
            $frame['command'] ?? null,
            'the command discriminator must be COMMAND_PLAY_MEDIA',
        );
        self::assertSame('srv-A', $frame['server_id'] ?? null, 'the frame must name the server it is scoped to');
        self::assertSame(
            'm-9',
            $frame['media_id'] ?? null,
            'the media id is the only thing that tells the app WHAT to start',
        );
        self::assertSame('Inception', $frame['title'] ?? null, 'the human title the app shows in its own UI');
        self::assertSame(
            'alexa',
            $frame['source'] ?? null,
            'the source field is how a client can tell an out-of-band command from a room broadcast',
        );

        /** @var mixed $issuedAt */
        $issuedAt = $frame['issued_at'] ?? null;
        self::assertIsInt($issuedAt, 'issued_at must be an int unix timestamp, not a formatted string');
        self::assertGreaterThanOrEqual($before, $issuedAt, 'issued_at predates the push that created it');
        self::assertLessThanOrEqual($after, $issuedAt, 'issued_at is in the future relative to the push');
        self::assertLessThanOrEqual(
            5,
            abs($issuedAt - $before),
            'issued_at is not a live timestamp — a stale one lets a client silently act on an old command',
        );

        // And the dispatcher reported the REAL count back on the push's own
        // reply event, which is what the HTTP worker gates the confirmation on.
        self::assertSame(
            [['reply.event.test', ['request_id' => 'req-1', 'delivered' => 1]]],
            $replies,
            'the dispatcher must publish the MEASURED delivered count back on the push\'s reply_event',
        );
    }
}
