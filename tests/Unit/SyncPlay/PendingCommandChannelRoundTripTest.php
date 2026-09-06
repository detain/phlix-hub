<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\SyncPlay;

use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\SyncPlay\ChannelPendingCommandPusher;
use Phlix\Hub\SyncPlay\PendingCommandDispatcher;
use Phlix\Hub\SyncPlay\PendingCommandProtocol;
use Phlix\Hub\SyncPlay\SyncPlayRelayWorker;
use Phlix\Hub\Tests\Support\LoggerFactoryIsolation;
use Phlix\Hub\Tests\Support\SyncPlay\RegistersSyncPlayClients;
use Phlix\Hub\Tests\Support\WorkermanTimerRuntimeControl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function array_keys;
use function glob;
use function is_array;
use function is_callable;
use function is_dir;
use function is_file;
use function json_encode;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * S93 — the delivered COUNT crossing the broker, and every way it must refuse to
 * be invented.
 *
 * ## The defect this suite exists to catch
 *
 * The Alexa skill speaks a confirmation if and only if this number is >= 1. The
 * number is produced in one process ({@see PendingCommandDispatcher}, on `:8804`,
 * the only process holding the sockets) and consumed in another
 * ({@see ChannelPendingCommandPusher}, on an HTTP worker). Everything between
 * them is a place a number could be FABRICATED:
 *
 *  - a dispatcher that replied to a push it could not understand would send a
 *    count it never measured;
 *  - a pusher that read a non-numeric `delivered` as "something arrived" would
 *    turn a malformed reply into a confirmation;
 *  - a late reply for an abandoned push, if it were not dropped, would hand one
 *    request's count to a different request.
 *
 * Every one of those produces a cheerful sentence and no error anywhere. So the
 * round trip is wired for real — the pusher's publisher hands the payload
 * SYNCHRONOUSLY to a real dispatcher, whose publisher hands the reply back to the
 * pusher's `onReply()` — mirroring the `RelayProxyBridge` idiom the rest of this
 * repo tests the identical process boundary with.
 *
 * ## What this suite does NOT prove, stated rather than implied
 *
 * Under plain PHPUnit CLI `Worker::$eventLoopClass` is empty, so
 * {@see \Workerman\Coroutine\Channel} selects its non-blocking `Memory` driver:
 * `pop($timeout)` returns instantly instead of suspending for
 * {@see PendingCommandProtocol::REPLY_TIMEOUT_SECONDS}. The BRANCH taken when no
 * reply arrives is therefore exercised exactly as production takes it (a `false`
 * from `pop()` ⇒ return 0), but the real-time bound of the wait is not — that is
 * Swoole's documented `Channel::pop(float)` semantics, and the constant itself is
 * pinned by {@see testTheReplyTimeoutStaysInsideTheAlexaResponseBudget()}. The
 * same caveat is recorded on `RelayProxyBridgeTest`, which crosses this boundary
 * the same way.
 *
 * @package Phlix\Hub\Tests\Unit\SyncPlay
 */
final class PendingCommandChannelRoundTripTest extends TestCase
{
    use LoggerFactoryIsolation;
    use RegistersSyncPlayClients;
    // Timer::add()'s arm is decided by a PROCESS-GLOBAL Worker registry latch, so
    // without this trait the boot test's result would depend on which other suite
    // happened to run first under executionOrder="random".
    use WorkermanTimerRuntimeControl;

    /** Every field {@see PendingCommandDispatcher::onPush()} requires. */
    private const REQUIRED_PUSH_FIELDS = [
        'request_id',
        'reply_event',
        'user_id',
        'server_id',
        'media_id',
        'title',
    ];

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        SyncPlayRelayWorker::reset();

        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-pending-roundtrip-test-' . uniqid();
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
    // 1. The count crosses the broker unchanged
    // ==================================================================

    /**
     * Parameterised over 0, 1 and 2 open apps precisely so the return value is
     * proved to be a real COUNT. A `pushPlayMedia()` that returned a constant —
     * or a boolean widened to an int — would satisfy any single-N test, and the
     * one it would satisfy most easily is `1`, the value that makes the skill
     * speak its confirmation.
     */
    #[DataProvider('openAppCounts')]
    public function testTheDeliveredCountCrossesTheBrokerBackToTheCaller(int $openApps): void
    {
        $this->setServerOwner('srv-A', 'u-1');
        for ($i = 0; $i < $openApps; $i++) {
            $token = 'tok-app-' . $i;
            $this->grantToken($token, 'u-1', 'srv-A');
            $sink = [];
            $this->connectSyncPlayClient('/syncplay/srv-A', $token, $sink);
        }

        self::assertSame(
            $openApps,
            SyncPlayRelayWorker::getActiveConnectionCount(),
            'control: the fixture must have registered exactly ' . $openApps . ' live sockets, or the '
            . 'expected count below is not a fact about delivery',
        );

        $published = [];
        $pusher = $this->wiredPusher($published);

        $delivered = $pusher->pushPlayMedia('u-1', 'srv-A', 'm-9', 'Inception');

        self::assertSame(
            $openApps,
            $delivered,
            'the delivered count did not survive the round trip: ' . $openApps . ' live sockets were '
            . 'written to but the caller was told ' . $delivered . '. The Alexa skill gates its '
            . 'confirmation on exactly this number.',
        );

        // The push really did cross the broker on the documented event, carrying
        // the caller's own reply event — the two facts a wrong constant here
        // would break silently (a push nobody subscribes to just times out to 0).
        self::assertCount(1, $published, 'exactly one push must have been published');
        self::assertSame(
            PendingCommandProtocol::PUSH_EVENT,
            $published[0]['event'],
            'the push must be published on PendingCommandProtocol::PUSH_EVENT — the :8804 worker '
            . 'subscribes to that name and nothing else',
        );
        self::assertSame(
            $pusher->replyEvent(),
            $published[0]['data']['reply_event'] ?? null,
            'the push must carry THIS worker\'s reply event, or the count comes back to nobody',
        );
        self::assertSame(
            ['request_id', 'reply_event', 'user_id', 'server_id', 'media_id', 'title'],
            array_keys($published[0]['data']),
            'the published push must carry exactly the six fields the dispatcher validates',
        );
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function openAppCounts(): iterable
    {
        yield 'nobody has the app open' => [0];
        yield 'one open app' => [1];
        yield 'two open apps' => [2];
    }

    /**
     * The reply event is unique per instance, which is what stops one HTTP
     * worker's count being delivered to another's waiting coroutine.
     */
    public function testEachPusherOwnsAUniqueReplyEvent(): void
    {
        $logger = new StructuredLogger('pending-command-roundtrip-test', []);
        $first = new ChannelPendingCommandPusher($logger, static function (): void {
        });
        $second = new ChannelPendingCommandPusher($logger, static function (): void {
        });

        self::assertNotSame(
            $first->replyEvent(),
            $second->replyEvent(),
            'two pushers share a reply event, so one worker\'s delivered count can be handed to the '
            . 'other worker\'s waiting request',
        );
        self::assertStringStartsWith('phlix.syncplay.pending_command.reply.', $first->replyEvent());
    }

    // ==================================================================
    // 2. A malformed push produces NO reply, so no count is fabricated
    // ==================================================================

    /**
     * The dispatcher is driven DIRECTLY here rather than through the pusher: a
     * malformed push produces no reply at all, so a round-trip version would sit
     * out the reply timeout for no extra evidence.
     *
     * Staying silent is the point. The only reply a dispatcher could make for a
     * push it never understood is a `delivered` it never measured, and the
     * publisher's own timeout already degrades to the honest 0.
     */
    #[DataProvider('eachRequiredFieldMissing')]
    public function testAMalformedPushProducesNoFabricatedCount(string $missingField): void
    {
        $this->setServerOwner('srv-A', 'u-1');
        $this->grantToken('tok-1a', 'u-1', 'srv-A');
        $sink = [];
        $this->connectSyncPlayClient('/syncplay/srv-A', 'tok-1a', $sink);

        $replies = [];
        $dispatcher = new PendingCommandDispatcher(
            new StructuredLogger('pending-command-roundtrip-test', []),
            static function (string $event, array $data) use (&$replies): void {
                $replies[] = [$event, $data];
            },
        );

        $payload = self::wellFormedPush();
        unset($payload[$missingField]);

        $dispatcher->onPush($payload);

        self::assertSame(
            [],
            $replies,
            'a push missing "' . $missingField . '" produced a reply. Any reply carries a `delivered` '
            . 'count, and a count for a push the dispatcher never understood is a number nobody '
            . 'measured — which the skill would speak as a confirmation.',
        );
        self::assertSame(
            [],
            $sink,
            'a malformed push still reached a live socket: the frame was built from fields that were '
            . 'never validated',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function eachRequiredFieldMissing(): iterable
    {
        foreach (self::REQUIRED_PUSH_FIELDS as $field) {
            yield 'missing ' . $field => [$field];
        }
    }

    /**
     * An empty string is not a usable identity either — and an empty `user_id`
     * reaching the delivery side is the fan-out hazard `deliverToUser()` guards.
     *
     * @param mixed $badValue
     */
    #[DataProvider('unusableFieldValues')]
    public function testAPushFieldThatIsNotANonEmptyStringIsAlsoMalformed(mixed $badValue, string $label): void
    {
        $replies = [];
        $dispatcher = new PendingCommandDispatcher(
            new StructuredLogger('pending-command-roundtrip-test', []),
            static function (string $event, array $data) use (&$replies): void {
                $replies[] = [$event, $data];
            },
        );

        $payload = self::wellFormedPush();
        $payload['user_id'] = $badValue;

        $dispatcher->onPush($payload);

        self::assertSame([], $replies, 'a user_id of ' . $label . ' must not produce a delivered count');
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function unusableFieldValues(): iterable
    {
        yield 'the empty string' => ['', 'the empty string'];
        yield 'null' => [null, 'null'];
        yield 'an int' => [7, 'an int'];
        yield 'an array' => [['u-1'], 'an array'];
    }

    /**
     * A payload that is not an array at all — what a broker hands over when
     * something upstream published a scalar.
     */
    public function testAPushPayloadThatIsNotAnArrayProducesNoReply(): void
    {
        $replies = [];
        $dispatcher = new PendingCommandDispatcher(
            new StructuredLogger('pending-command-roundtrip-test', []),
            static function (string $event, array $data) use (&$replies): void {
                $replies[] = [$event, $data];
            },
        );

        $dispatcher->onPush('not an array');
        $dispatcher->onPush(null);

        self::assertSame([], $replies, 'a non-array push payload produced a delivered count');
    }

    /**
     * A title carrying malformed UTF-8 — the one input that gets past the
     * field validation and then makes `json_encode(..., JSON_THROW_ON_ERROR)`
     * throw.
     *
     * The title originates in an Alexa slot value, i.e. outside this hub, so this
     * is a live path rather than a defensive one. An uncaught `JsonException`
     * inside the `:8804` worker's channel subscriber would take down the process
     * that owns every SyncPlay socket on the hub — for one bad byte in one
     * spoken title.
     */
    public function testATitleThatCannotBeEncodedIsLoggedAndProducesNoReply(): void
    {
        $this->setServerOwner('srv-A', 'u-1');
        $this->grantToken('tok-1a', 'u-1', 'srv-A');
        $sink = [];
        $this->connectSyncPlayClient('/syncplay/srv-A', 'tok-1a', $sink);

        $replies = [];
        $dispatcher = new PendingCommandDispatcher(
            new StructuredLogger('pending-command-roundtrip-test', []),
            static function (string $event, array $data) use (&$replies): void {
                $replies[] = [$event, $data];
            },
        );

        $payload = self::wellFormedPush();
        // Control: a lone 0xB1 byte is a non-empty string, so it passes the
        // field validation and reaches json_encode — which is the point.
        $payload['title'] = "Bad \xB1 title";
        self::assertNotSame('', $payload['title'], 'control: the bad title must be a non-empty string');

        $dispatcher->onPush($payload);

        self::assertSame(
            [],
            $replies,
            'an unencodable frame produced a delivered count anyway — a number for a frame that was '
            . 'never built, let alone written to a socket',
        );
        self::assertSame([], $sink, 'an unencodable frame must not reach a socket');
    }

    /**
     * The succeeding control for every "no reply" assertion above: the SAME
     * dispatcher, the SAME fixture, a WELL-FORMED push — and a reply does arrive.
     * Without it, a dispatcher whose publisher was never wired would pass every
     * malformed case perfectly.
     */
    public function testAWellFormedPushDoesProduceAReply(): void
    {
        $this->setServerOwner('srv-A', 'u-1');
        $this->grantToken('tok-1a', 'u-1', 'srv-A');
        $sink = [];
        $this->connectSyncPlayClient('/syncplay/srv-A', 'tok-1a', $sink);

        $replies = [];
        $dispatcher = new PendingCommandDispatcher(
            new StructuredLogger('pending-command-roundtrip-test', []),
            static function (string $event, array $data) use (&$replies): void {
                $replies[] = [$event, $data];
            },
        );

        $dispatcher->onPush(self::wellFormedPush());

        self::assertSame(
            [['reply.event.test', ['request_id' => 'req-1', 'delivered' => 1]]],
            $replies,
            'control: a well-formed push must reply with the measured count on the push\'s own '
            . 'reply_event, or every "no reply" assertion in this suite measures nothing',
        );
        self::assertCount(1, $sink, 'control: the well-formed push must have reached the live socket');
    }

    // ==================================================================
    // 3. A reply with no usable count reads as ZERO, never as "something"
    // ==================================================================

    /**
     * `delivered` is the only field that decides whether a user is told their
     * screen was asked to start something. Anything that is not a number is not
     * evidence of delivery, and the safe reading of "I cannot tell" is 0.
     *
     * @param array<string, mixed> $reply
     */
    #[DataProvider('repliesWithNoUsableCount')]
    public function testAReplyWithNoUsableDeliveredCountIsReadAsZero(array $reply, string $label): void
    {
        $pusher = null;
        $pusher = new ChannelPendingCommandPusher(
            new StructuredLogger('pending-command-roundtrip-test', []),
            static function (string $event, array $data) use (&$pusher, $reply): void {
                /** @var mixed $requestId */
                $requestId = $data['request_id'] ?? null;
                /** @var ChannelPendingCommandPusher $pusher */
                $pusher->onReply(['request_id' => $requestId] + $reply);
            },
        );

        self::assertSame(
            0,
            $pusher->pushPlayMedia('u-1', 'srv-A', 'm-9', 'Inception'),
            $label . ': a reply that carries no usable delivered count must read as 0. Reading it as '
            . 'anything else turns a malformed reply into a spoken confirmation for a command that '
            . 'was never shown to have reached a socket.',
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function repliesWithNoUsableCount(): iterable
    {
        yield 'a non-numeric string' => [['delivered' => 'not a number'], 'delivered = "not a number"'];
        yield 'no delivered key at all' => [[], 'no delivered key'];
        yield 'null' => [['delivered' => null], 'delivered = null'];
        yield 'a bare true' => [['delivered' => true], 'delivered = true'];
        yield 'an array' => [['delivered' => [1]], 'delivered = [1]'];
    }

    /**
     * The succeeding control beside those five: a well-shaped reply IS read.
     * Both spellings a JSON round trip through a broker can produce are covered,
     * and a negative count is clamped rather than passed through to a `< 1` gate
     * as a number that reads "less than nobody".
     *
     * @param int|string $delivered
     */
    #[DataProvider('repliesWithAUsableCount')]
    public function testAWellShapedDeliveredCountIsRead(int|string $delivered, int $expected): void
    {
        $pusher = null;
        $pusher = new ChannelPendingCommandPusher(
            new StructuredLogger('pending-command-roundtrip-test', []),
            static function (string $event, array $data) use (&$pusher, $delivered): void {
                /** @var mixed $requestId */
                $requestId = $data['request_id'] ?? null;
                /** @var ChannelPendingCommandPusher $pusher */
                $pusher->onReply(['request_id' => $requestId, 'delivered' => $delivered]);
            },
        );

        self::assertSame(
            $expected,
            $pusher->pushPlayMedia('u-1', 'srv-A', 'm-9', 'Inception'),
            'control: a delivered value of ' . var_export($delivered, true) . ' must read as ' . $expected,
        );
    }

    /**
     * @return iterable<string, array{int|string, int}>
     */
    public static function repliesWithAUsableCount(): iterable
    {
        yield 'an int 2' => [2, 2];
        yield 'an int 0' => [0, 0];
        yield 'the numeric string "3"' => ['3', 3];
        yield 'a negative int is clamped to 0' => [-4, 0];
        yield 'a negative numeric string is clamped to 0' => ['-4', 0];
    }

    // ==================================================================
    // 4. A late reply for an abandoned push is dropped
    // ==================================================================

    /**
     * A reply whose push already gave up has nobody to be handed to. Dropping it
     * is correct; the failure mode being pinned is the alternative — a late reply
     * resolving whichever channel happens to be in the map, i.e. handing one
     * request's count to a DIFFERENT request.
     */
    public function testALateReplyForAnAbandonedPushIsDroppedRatherThanCorruptingTheNextPush(): void
    {
        $this->setServerOwner('srv-A', 'u-1');
        $this->grantToken('tok-1a', 'u-1', 'srv-A');
        $sink = [];
        $this->connectSyncPlayClient('/syncplay/srv-A', 'tok-1a', $sink);

        $published = [];
        $pusher = $this->wiredPusher($published);

        // A reply for a request id that was never issued (or has long since been
        // untracked). It must be dropped without throwing.
        $pusher->onReply(['request_id' => 'a-request-nobody-is-waiting-on', 'delivered' => 99]);
        $pusher->onReply(['request_id' => 'a-request-nobody-is-waiting-on', 'delivered' => 99]);
        // And the shapes `onReply()` must also survive.
        $pusher->onReply('not an array');
        $pusher->onReply(['delivered' => 5]);
        $pusher->onReply(['request_id' => 12345, 'delivered' => 5]);

        self::assertSame(
            [],
            $published,
            'control: none of those replies should have published anything — nothing has been pushed yet',
        );

        $delivered = $pusher->pushPlayMedia('u-1', 'srv-A', 'm-9', 'Inception');

        self::assertSame(
            1,
            $delivered,
            'a legitimate push after a run of stray replies reported the wrong count. A late reply '
            . 'must be dropped, never applied to whichever request happens to be waiting — that '
            . 'would hand one user\'s delivered count to another user\'s utterance.',
        );
        self::assertNotSame(
            99,
            $delivered,
            'the stray reply\'s count (99) leaked into the next push',
        );
    }

    // ==================================================================
    // 5. No subscriber at all
    // ==================================================================

    /**
     * No broker, no `:8804` worker, nobody listening: the push is published into
     * the void and no reply ever arrives. That must degrade to 0 — the honest
     * "no open app" answer — and must NOT throw, because an exception inside a
     * resident worker is a 500 the user hears as a broken skill.
     *
     * ⚠ Under PHPUnit this costs no real time: the `Memory` channel driver's
     * `pop()` returns instantly rather than waiting out
     * {@see PendingCommandProtocol::REPLY_TIMEOUT_SECONDS}. The BRANCH is the
     * production branch; the wall-clock bound is not exercised here (see the
     * class docblock).
     */
    public function testAPushNobodyRepliesToDegradesToZeroWithoutThrowing(): void
    {
        $this->setServerOwner('srv-A', 'u-1');
        $this->grantToken('tok-1a', 'u-1', 'srv-A');
        $sink = [];
        $this->connectSyncPlayClient('/syncplay/srv-A', 'tok-1a', $sink);

        $pusher = new ChannelPendingCommandPusher(
            new StructuredLogger('pending-command-roundtrip-test', []),
            static function (string $event, array $data): void {
                // Published into the void: nothing subscribes, nothing replies.
            },
        );

        self::assertSame(
            0,
            $pusher->pushPlayMedia('u-1', 'srv-A', 'm-9', 'Inception'),
            'a push that is never answered must degrade to 0 delivered. Under-claiming is the safe '
            . 'direction: the user hears "open the Phlix app", which is true whenever it is spoken.',
        );

        // ⚠ And note what is NOT true here: a live socket EXISTS and was not
        // written to, because nothing carried the push to the process that owns
        // it. The count is a statement about the round trip, not about the map.
        self::assertSame(
            [],
            $sink,
            'control: with no subscriber, no frame can have reached the socket — so the 0 above is '
            . 'about the missing round trip and not about a missing client',
        );
    }

    /**
     * The timeout constant itself, pinned because the `Memory` driver means no
     * test can observe it elapsing. It sits inside Alexa's ~8 s budget for a
     * self-hosted skill, alongside the account-link resolve, the server list and
     * the relayed search.
     */
    public function testTheReplyTimeoutStaysInsideTheAlexaResponseBudget(): void
    {
        self::assertGreaterThan(
            0.0,
            PendingCommandProtocol::REPLY_TIMEOUT_SECONDS,
            'a zero/negative reply timeout would make every push report 0 before the :8804 worker '
            . 'could possibly answer',
        );
        self::assertLessThanOrEqual(
            3.0,
            PendingCommandProtocol::REPLY_TIMEOUT_SECONDS,
            'the wait sits inside Alexa\'s ~8 second budget for a self-hosted skill, next to the '
            . 'account-link resolve, the server list and the relayed library search. A generous '
            . 'timeout here spends the whole budget waiting for a process that usually has nothing '
            . 'to deliver.',
        );
    }

    // ==================================================================
    // 6. Worker boot: the channel join must never take the worker down
    // ==================================================================

    /**
     * S93 added a channel join to {@see SyncPlayRelayWorker::onWorkerStart()}.
     * Two things must hold there and neither is visible from any other test.
     *
     *  1. **The pre-existing room-cleanup timer is still armed.** It is the only
     *     thing that stops `self::$rooms` growing without bound in a resident
     *     worker, and a new block added above it is exactly how a boot hook loses
     *     an old responsibility.
     *  2. **A broker that is not there must LOG AND CONTINUE.** The whole
     *     SyncPlay surface — every live socket, every room broadcast — must keep
     *     working when no pending command can be delivered. A throw here happens
     *     at boot, inside a resident worker, and takes the process with it.
     *
     * This test runs with NO channel broker (there is none under PHPUnit;
     * `Channel\Client::connect()` fails on the absent event loop), which is the
     * failing arm. That the SUCCEEDING arm cannot be exercised here is stated
     * rather than hidden: `Channel\Client` is a static vendor class with no seam,
     * so a real subscription needs a real broker and a real event loop.
     */
    public function testTheWorkerBootArmsItsCleanupTimerAndSurvivesAnAbsentChannelBroker(): void
    {
        $this->forceWorkermanRuntime();

        self::assertSame(
            0,
            $this->pendingTimerTaskCount(),
            'control: no timer may be pending before boot, or the count below is not evidence',
        );

        $worker = new SyncPlayRelayWorker(
            SyncPlayRelayWorker::DEFAULT_PORT,
            1,
            $this->buildSyncPlayContainer(),
        );

        // Must not throw: there is no broker, and that is a normal state.
        $worker->onWorkerStart();

        self::assertSame(
            1,
            $this->pendingTimerTaskCount(),
            'onWorkerStart() must still arm the 60s room-cleanup timer. Without it `self::$rooms` '
            . 'grows without bound in a resident worker — and a failed channel join must not be '
            . 'allowed to skip it either.',
        );

        // The surface itself is untouched by the failed join.
        self::assertSame(0, SyncPlayRelayWorker::getActiveConnectionCount());
        self::assertSame(0, SyncPlayRelayWorker::getActiveRoomCount());
    }

    /**
     * The timer that boot arms must still DO its job — not merely exist.
     *
     * `pendingTimerTaskCount()` above proves a timer was registered; it cannot
     * see what the callback does. An `onWorkerStart()` that armed a no-op (or one
     * whose body was lost when the S93 channel block was inserted around it)
     * would pass that count and still leak `self::$rooms` for the life of a
     * resident worker. So the registered callback is pulled out of Workerman's
     * task table and INVOKED.
     */
    public function testTheArmedCleanupTimerActuallyRemovesAnEmptyRoom(): void
    {
        $this->forceWorkermanRuntime();

        $this->setServerOwner('srv-A', 'u-1');
        $this->grantToken('tok-1a', 'u-1', 'srv-A');
        $sink = [];
        $this->connectSyncPlayClient('/syncplay/srv-A', 'tok-1a', $sink);

        $worker = $this->syncPlayWorker();
        $worker->onMessage(
            $this->lastSyncPlayConnection(),
            (string) json_encode(['type' => 'group_join', 'room' => 'movie-night']),
        );
        self::assertSame(1, SyncPlayRelayWorker::getActiveRoomCount(), 'control: one room must exist');

        // The client leaves; the room entry survives as an EMPTY bucket, which is
        // precisely what the cleanup timer exists to reap.
        $worker->onClose($this->lastSyncPlayConnection());
        self::assertSame(
            1,
            SyncPlayRelayWorker::getActiveRoomCount(),
            'control: the empty room must still be present, or the timer below has nothing to reap',
        );

        $worker->onWorkerStart();
        $callback = $this->armedTimerCallback();

        $callback();

        self::assertSame(
            0,
            SyncPlayRelayWorker::getActiveRoomCount(),
            'the armed timer did not remove the empty room. `self::$rooms` is a process-global map in '
            . 'a resident worker: a cleanup callback that no longer reaps grows it without bound, and '
            . 'the "a timer was armed" assertion above cannot see the difference.',
        );
    }

    // ==================================================================
    // 7. The protocol constants
    // ==================================================================

    /**
     * `PendingCommandProtocol` is a constants holder with a private constructor.
     * The two ends of the boundary agree only because they read the SAME
     * constants; a literal on either side would be a wire mismatch nothing could
     * detect until a real client existed to not receive anything.
     */
    public function testTheProtocolIsAConstantsHolderThatCannotBeInstantiated(): void
    {
        $reflection = new ReflectionClass(PendingCommandProtocol::class);

        self::assertFalse(
            $reflection->isInstantiable(),
            'PendingCommandProtocol must stay a constants holder — an instantiable one invites '
            . 'per-instance state on a value both processes must read identically',
        );
        self::assertTrue($reflection->isFinal(), 'the protocol must not be subclassable');

        self::assertNotSame(
            '',
            PendingCommandProtocol::PUSH_EVENT,
            'an empty push event would publish to a name nobody subscribes to',
        );
        self::assertNotSame(
            PendingCommandProtocol::FRAME_TYPE,
            PendingCommandProtocol::COMMAND_PLAY_MEDIA,
            'the frame type and the command discriminator must stay distinct values',
        );
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    /**
     * A pusher whose publisher hands the payload SYNCHRONOUSLY to a real
     * dispatcher, whose publisher hands the reply back to the pusher — the whole
     * cross-process round trip, in one process, with no doubles in the middle.
     *
     * The dispatcher's reply is forwarded only when it is addressed to THIS
     * pusher's reply event, so a dispatcher that published on the wrong event
     * would show up as a timeout rather than being quietly accepted.
     *
     * @param list<array{event: string, data: array<string, mixed>}> $published
     *        Receives every payload the pusher published, by reference.
     */
    private function wiredPusher(array &$published): ChannelPendingCommandPusher
    {
        $published = [];

        $pusher = null;
        $dispatcher = new PendingCommandDispatcher(
            new StructuredLogger('pending-command-roundtrip-test', []),
            static function (string $event, array $data) use (&$pusher): void {
                /** @var ChannelPendingCommandPusher $pusher */
                if ($event !== $pusher->replyEvent()) {
                    return;
                }
                $pusher->onReply($data);
            },
        );

        $pusher = new ChannelPendingCommandPusher(
            new StructuredLogger('pending-command-roundtrip-test', []),
            static function (string $event, array $data) use ($dispatcher, &$published): void {
                $published[] = ['event' => $event, 'data' => $data];
                $dispatcher->onPush($data);
            },
        );

        return $pusher;
    }

    /**
     * The single callback Workerman's task table holds after `onWorkerStart()`.
     *
     * Pulled out of `Timer::$tasks` (shape `[runTime][timerId] = [callable,
     * args, persistent, interval]`, `Timer.php:170`) so the timer can be FIRED
     * rather than merely counted.
     *
     * @return callable(): void
     */
    private function armedTimerCallback(): callable
    {
        $callbacks = [];
        foreach (self::readTimerArray('tasks') as $bucket) {
            if (!is_array($bucket)) {
                continue;
            }
            /** @var mixed $task */
            foreach ($bucket as $task) {
                if (is_array($task) && isset($task[0]) && is_callable($task[0])) {
                    $callbacks[] = $task[0];
                }
            }
        }

        self::assertCount(
            1,
            $callbacks,
            'exactly one timer callback must be armed for this test to fire the right one',
        );

        /** @var callable(): void $callback */
        $callback = $callbacks[0];

        return $callback;
    }

    /**
     * @return array<string, mixed>
     */
    private static function wellFormedPush(): array
    {
        return [
            'request_id' => 'req-1',
            'reply_event' => 'reply.event.test',
            'user_id' => 'u-1',
            'server_id' => 'srv-A',
            'media_id' => 'm-9',
            'title' => 'Inception',
        ];
    }
}
