<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Federation;

use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Federation\FederationConnectionManager;
use Phlix\Hub\Federation\FederationPushBridge;
use Phlix\Hub\Federation\FederationPushDispatcher;
use Phlix\Hub\Federation\FederationPushProtocol;
use Phlix\Hub\Relay\FederationWorker;
use Phlix\Hub\Relay\RelayProxyProtocol;
use Phlix\Hub\Tests\Support\LoggerFactoryIsolation;
use Phlix\Hub\Tests\Support\WorkermanTimerRuntimeControl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use Workerman\Connection\ConnectionInterface;

use function array_keys;
use function glob;
use function is_dir;
use function is_file;
use function json_encode;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * The federation master push crossing the broker — and every way a delivery
 * verdict must refuse to be invented.
 *
 * ## The defect this suite exists to catch
 *
 * The `:8805` FederationWorker is the only process holding the leaf's live
 * socket AND its H-4 verified stamp; the pusher runs on an HTTP worker whose
 * connection map is empty. The bridge/dispatcher pair moves the INTENT across
 * the workerman/channel broker — and every point between the two ends is a
 * place a delivery verdict could be FABRICATED:
 *
 *  - a dispatcher that replied to a command it could not parse would state a
 *    delivery it never measured;
 *  - a dispatcher that wrote on an UNVERIFIED channel would turn the bridge
 *    into a gate bypass — the exact law H-4 exists to hold;
 *  - a bridge that read a malformed reply (or none) as success would log
 *    revocations that never left the process.
 *
 * So the round trip is wired for real — the bridge's publisher hands the
 * command SYNCHRONOUSLY to a real dispatcher driving a REAL
 * FederationConnectionManager (verified via the production stamp API), whose
 * publisher hands the reply back to the bridge's `onReply()` — mirroring the
 * S93 `PendingCommandChannelRoundTripTest` idiom that crosses this repo's
 * identical process boundary.
 *
 * ## What this suite does NOT prove, stated rather than implied
 *
 * Under plain PHPUnit CLI `Worker::$eventLoopClass` is empty, so
 * {@see \Workerman\Coroutine\Channel} selects its non-blocking `Memory` driver:
 * `pop($timeout)` returns instantly instead of suspending for
 * {@see FederationPushProtocol::REPLY_TIMEOUT_SECONDS}. The BRANCH taken when
 * no reply arrives is exercised exactly as production takes it (no array ⇒
 * false), but the real-time bound is not — same caveat recorded on the SyncPlay
 * and RelayProxyBridge suites.
 *
 * @package Phlix\Hub\Tests\Unit\Federation
 */
final class FederationPushChannelRoundTripTest extends TestCase
{
    use LoggerFactoryIsolation;
    // The worker-boot test below touches Timer/Worker process-global latches;
    // this trait snapshots and restores them so random suite order cannot skew
    // the result (same reason the SyncPlay round-trip suite uses it).
    use WorkermanTimerRuntimeControl;

    /** Every envelope field {@see FederationPushDispatcher::onCommand()} requires. */
    private const REQUIRED_COMMAND_FIELDS = [
        'request_id',
        'reply_event',
        'action',
        'leaf_hub_id',
        'payload',
    ];

    private string $tmpDir;

    private FederationConnectionManager $connMgr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connMgr = new FederationConnectionManager();

        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-fed-push-roundtrip-' . uniqid();
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
    // 1. The verdict crosses the broker unchanged
    // ==================================================================

    /**
     * A verified leaf connected: the frame must land on its socket (real bytes,
     * asserted on the captured send) and TRUE must cross back to the caller.
     */
    public function testADeliveryToAVerifiedLeafCrossesTheBrokerAsTrue(): void
    {
        $conn = $this->createMock(ConnectionInterface::class);
        $captured = '';
        $conn->method('send')->willReturnCallback(
            static function (string $data) use (&$captured): void {
                $captured = $data;
            },
        );
        $this->connMgr->addConnection('leaf-uuid', $conn);
        $this->connMgr->markVerified('leaf-uuid', $conn);

        $published = [];
        $bridge = $this->wiredBridge($published);

        $payload = (string) json_encode(['share_id' => 'share-1']);
        self::assertTrue(
            $bridge->dispatch(FederationPushProtocol::ACTION_REVOCATION, 'leaf-uuid', $payload),
            'a frame written to a verified live socket must come back as delivered=true',
        );
        self::assertStringContainsString('"share_id":"share-1"', $captured);

        self::assertCount(1, $published, 'exactly one command must have been published');
        self::assertSame(
            FederationPushProtocol::COMMAND_EVENT,
            $published[0]['event'],
            'the command must be published on FederationPushProtocol::COMMAND_EVENT — the :8805 '
            . 'worker subscribes to that name and nothing else',
        );
        self::assertSame(
            $bridge->replyEvent(),
            $published[0]['data']['reply_event'] ?? null,
            'the command must carry THIS worker\'s reply event, or the verdict comes back to nobody',
        );
        self::assertSame(
            self::REQUIRED_COMMAND_FIELDS,
            array_keys($published[0]['data']),
            'the published command must carry exactly the five envelope fields the dispatcher validates',
        );
    }

    /**
     * THE GATE LAW, enforced in the process that owns the stamp: a command for
     * a REGISTERED-BUT-UNVERIFIED connection is refused with a measured false —
     * the bridge must not become a side door around H-4.
     */
    public function testADeliveryToAnUnverifiedChannelIsRefusedFalseAndNeverWritten(): void
    {
        $conn = $this->createMock(ConnectionInterface::class);
        $conn->expects(self::never())->method('send');
        $this->connMgr->addConnection('leaf-uuid', $conn); // connected, NOT verified

        $published = [];
        $bridge = $this->wiredBridge($published);

        self::assertFalse(
            $bridge->dispatch(FederationPushProtocol::ACTION_OFFER, 'leaf-uuid', '{"shares":[]}'),
            'the bridge delivered over an unverified channel — the H-4 gate is bypassed cross-process',
        );
        self::assertTrue(
            $this->connMgr->isConnected('leaf-uuid'),
            'control: the connection is still registered, so the false above measures the refusal, '
            . 'not a missing socket',
        );
    }

    /**
     * No connection at all: `isVerified()` is false, the refusal is answered
     * honestly with delivered=false (the command WAS understood).
     */
    public function testADeliveryToAnAbsentConnectionRepliesFalse(): void
    {
        $published = [];
        $bridge = $this->wiredBridge($published);

        self::assertFalse(
            $bridge->dispatch(FederationPushProtocol::ACTION_REVOCATION, 'ghost-leaf', '{"share_id":"s"}'),
        );
    }

    /**
     * close_peer needs no verified stamp (parity with the in-process path: the
     * teardown carries no claims and dropping a dead socket is safe) — but it
     * must run the FULL local ceremony in the :8805 process: unmap, goodbye,
     * close.
     */
    public function testAClosePeerCommandGoodbyesUnmapsAndClosesEvenUnverified(): void
    {
        $conn = $this->createMock(ConnectionInterface::class);
        $captured = '';
        $conn->method('send')->willReturnCallback(
            static function (string $data) use (&$captured): void {
                $captured = $data;
            },
        );
        $conn->expects(self::once())->method('close');
        $this->connMgr->addConnection('leaf-uuid', $conn); // deliberately NOT verified

        $published = [];
        $bridge = $this->wiredBridge($published);

        self::assertTrue(
            $bridge->dispatch(FederationPushProtocol::ACTION_CLOSE_PEER, 'leaf-uuid', ''),
        );
        self::assertStringContainsString('peer_deleted', $captured);
        self::assertNull($this->connMgr->getConnection('leaf-uuid'), 'connection must be unmapped');
    }

    /**
     * close_peer for a peer with no live socket is the desired end state:
     * true, "nothing to close" (in-process parity).
     */
    public function testAClosePeerWithoutALiveConnectionRepliesTrue(): void
    {
        $published = [];
        $bridge = $this->wiredBridge($published);

        self::assertTrue(
            $bridge->dispatch(FederationPushProtocol::ACTION_CLOSE_PEER, 'ghost-leaf', ''),
        );
    }

    // ==================================================================
    // 2. A malformed command produces NO reply, so no verdict is fabricated
    // ==================================================================

    #[DataProvider('eachRequiredFieldMissing')]
    public function testAMalformedCommandProducesNoFabricatedVerdict(string $missingField): void
    {
        $conn = $this->createMock(ConnectionInterface::class);
        $conn->expects(self::never())->method('send');
        $this->connMgr->addConnection('leaf-uuid', $conn);
        $this->connMgr->markVerified('leaf-uuid', $conn);

        $replies = [];
        $dispatcher = $this->collectingDispatcher($replies);

        $command = self::wellFormedCommand();
        unset($command[$missingField]);

        $dispatcher->onCommand($command);

        self::assertSame(
            [],
            $replies,
            'a command missing "' . $missingField . '" produced a reply. Any reply carries a '
            . '`delivered` verdict, and a verdict for a command the dispatcher never understood '
            . 'is a statement nobody measured.',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function eachRequiredFieldMissing(): iterable
    {
        foreach (self::REQUIRED_COMMAND_FIELDS as $field) {
            yield 'missing ' . $field => [$field];
        }
    }

    /**
     * An unknown action is not a command either — no reply.
     */
    public function testAnUnknownActionProducesNoReply(): void
    {
        $replies = [];
        $dispatcher = $this->collectingDispatcher($replies);

        $command = self::wellFormedCommand();
        $command['action'] = 'drop_table';

        $dispatcher->onCommand($command);

        self::assertSame([], $replies);
    }

    /**
     * A DATA action with an empty payload: understood envelope, impossible
     * content — refused with no reply (close_peer is the only '' payload the
     * protocol admits, and it is dispatched before this check).
     */
    public function testADataActionWithEmptyPayloadProducesNoReply(): void
    {
        $replies = [];
        $dispatcher = $this->collectingDispatcher($replies);

        $command = self::wellFormedCommand();
        $command['payload'] = '';

        $dispatcher->onCommand($command);

        self::assertSame([], $replies);
    }

    /**
     * A payload that is not an array at all.
     */
    public function testACommandPayloadThatIsNotAnArrayProducesNoReply(): void
    {
        $replies = [];
        $dispatcher = $this->collectingDispatcher($replies);

        $dispatcher->onCommand('not an array');
        $dispatcher->onCommand(null);

        self::assertSame([], $replies);
    }

    /**
     * The succeeding control for every "no reply" assertion above: the SAME
     * dispatcher, a WELL-FORMED command — and a reply does arrive. Without it,
     * a dispatcher whose publisher was never wired would pass every malformed
     * case perfectly.
     */
    public function testAWellFormedCommandDoesProduceAReply(): void
    {
        $conn = $this->createMock(ConnectionInterface::class);
        $this->connMgr->addConnection('leaf-uuid', $conn);
        $this->connMgr->markVerified('leaf-uuid', $conn);

        $replies = [];
        $dispatcher = $this->collectingDispatcher($replies);

        $dispatcher->onCommand(self::wellFormedCommand());

        self::assertSame(
            [['reply.event.test', ['request_id' => 'req-1', 'delivered' => true]]],
            $replies,
            'control: a well-formed command must reply with the measured verdict on the command\'s '
            . 'own reply_event, or every "no reply" assertion in this suite measures nothing',
        );
    }

    // ==================================================================
    // 3. A reply with no usable verdict reads as FALSE, never as "delivered"
    // ==================================================================

    /**
     * @param array<string, mixed> $reply
     */
    #[DataProvider('repliesWithNoUsableVerdict')]
    public function testAReplyWithNoUsableDeliveredFlagIsReadAsFalse(array $reply, string $label): void
    {
        $bridge = null;
        $bridge = new FederationPushBridge(
            new StructuredLogger('fed-push-roundtrip-test', []),
            static function (string $event, array $data) use (&$bridge, $reply): void {
                /** @var mixed $requestId */
                $requestId = $data['request_id'] ?? null;
                /** @var FederationPushBridge $bridge */
                $bridge->onReply(['request_id' => $requestId] + $reply);
            },
        );

        self::assertFalse(
            $bridge->dispatch(FederationPushProtocol::ACTION_REVOCATION, 'leaf-uuid', '{"share_id":"s"}'),
            $label . ': a reply carrying no usable delivered flag must read as false. Reading it '
            . 'as anything else turns a malformed reply into a logged delivery that never happened.',
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function repliesWithNoUsableVerdict(): iterable
    {
        yield 'a truthy string' => [['delivered' => 'yes'], 'delivered = "yes"'];
        yield 'the int 1' => [['delivered' => 1], 'delivered = 1'];
        yield 'no delivered key' => [[], 'no delivered key'];
        yield 'null' => [['delivered' => null], 'delivered = null'];
        yield 'an array' => [['delivered' => [true]], 'delivered = [true]'];
    }

    /**
     * The succeeding control beside those: a well-shaped bool IS read. Vendor
     * `serialize()` crosses the broker type-preserving, so bool is the only
     * spelling the protocol admits.
     */
    public function testAWellShapedBoolVerdictIsRead(): void
    {
        foreach ([true, false] as $verdict) {
            $bridge = null;
            $bridge = new FederationPushBridge(
                new StructuredLogger('fed-push-roundtrip-test', []),
                static function (string $event, array $data) use (&$bridge, $verdict): void {
                    /** @var mixed $requestId */
                    $requestId = $data['request_id'] ?? null;
                    /** @var FederationPushBridge $bridge */
                    $bridge->onReply(['request_id' => $requestId, 'delivered' => $verdict]);
                },
            );

            self::assertSame(
                $verdict,
                $bridge->dispatch(FederationPushProtocol::ACTION_REVOCATION, 'leaf-uuid', '{"share_id":"s"}'),
                'control: a delivered value of ' . var_export($verdict, true) . ' must read as itself',
            );
        }
    }

    // ==================================================================
    // 4. A late reply for an abandoned command is dropped
    // ==================================================================

    public function testALateReplyForAnAbandonedCommandIsDroppedRatherThanCorruptingTheNextOne(): void
    {
        $conn = $this->createMock(ConnectionInterface::class);
        $this->connMgr->addConnection('leaf-uuid', $conn);
        $this->connMgr->markVerified('leaf-uuid', $conn);

        $published = [];
        $bridge = $this->wiredBridge($published);

        // Stray replies onReply() must survive without throwing — including a
        // bool the shape a fabricated 99-delivery might carry.
        $bridge->onReply(['request_id' => 'a-request-nobody-is-waiting-on', 'delivered' => true]);
        $bridge->onReply('not an array');
        $bridge->onReply(['delivered' => true]);
        $bridge->onReply(['request_id' => 12345, 'delivered' => true]);

        self::assertSame([], $published, 'control: stray replies must publish nothing');

        self::assertTrue(
            $bridge->dispatch(FederationPushProtocol::ACTION_REVOCATION, 'leaf-uuid', '{"share_id":"s"}'),
            'a legitimate command after a run of stray replies reported the wrong verdict — a late '
            . 'reply must be dropped, never applied to whichever request happens to be waiting.',
        );
    }

    // ==================================================================
    // 5. Failure posture: no subscriber, no broker, no throw — ever
    // ==================================================================

    /**
     * Nobody listening: the command is published into the void and no reply
     * ever arrives. That must degrade to false WITHOUT throwing — the DB truth
     * is already committed at dispatch time, and the admin request must not
     * 500 because an advisory push had nobody to take it.
     */
    public function testACommandNobodyRepliesToDegradesToFalseWithoutThrowing(): void
    {
        $bridge = new FederationPushBridge(
            new StructuredLogger('fed-push-roundtrip-test', []),
            static function (string $event, array $data): void {
                // Published into the void: nothing subscribes, nothing replies.
            },
        );

        self::assertFalse(
            $bridge->dispatch(FederationPushProtocol::ACTION_REVOCATION, 'leaf-uuid', '{"share_id":"s"}'),
        );
    }

    /**
     * The broker REFUSES the publish (no connection, transport died mid-write):
     * the exception must be swallowed into a logged false. This is the
     * deliberate deviation from the SyncPlay pusher, which lets publish throw —
     * the admin controller has no try/catch around the pusher, and a throwing
     * bridge would 500 a request whose DB write already succeeded.
     */
    public function testAPublishingBrokerDegradesToFalseWithoutThrowing(): void
    {
        $bridge = new FederationPushBridge(
            new StructuredLogger('fed-push-roundtrip-test', []),
            static function (string $event, array $data): void {
                throw new \RuntimeException('channel broker unavailable');
            },
        );

        self::assertFalse(
            $bridge->dispatch(FederationPushProtocol::ACTION_CLOSE_PEER, 'leaf-uuid', ''),
        );
    }

    /**
     * The reply event is unique per instance — what stops one HTTP worker's
     * verdict being handed to another's waiting coroutine.
     */
    public function testEachBridgeOwnsAUniqueReplyEvent(): void
    {
        $logger = new StructuredLogger('fed-push-roundtrip-test', []);
        $first = new FederationPushBridge($logger, static function (): void {
        });
        $second = new FederationPushBridge($logger, static function (): void {
        });

        self::assertNotSame($first->replyEvent(), $second->replyEvent());
        self::assertStringStartsWith('phlix.federation.push.reply.', $first->replyEvent());
    }

    // ==================================================================
    // 6. Worker boot: the channel join must never take the worker down
    // ==================================================================

    /**
     * {@see FederationWorker::onWorkerStart()} joins the broker. Under PHPUnit
     * there IS no broker, so this exercises the failing arm: the join must LOG
     * AND CONTINUE, never throw — a throw at boot inside the resident :8805
     * process would take every federation socket on the hub down with it.
     * The succeeding arm (real subscription) needs a real broker and event
     * loop and is stated rather than tested here, same note the SyncPlay boot
     * test carries.
     */
    public function testTheFederationWorkerBootSurvivesAnAbsentChannelBroker(): void
    {
        $this->forceWorkermanRuntime();

        $worker = new FederationWorker(
            new class implements ContainerInterface {
                public function get(string $id): mixed
                {
                    return null;
                }

                public function has(string $id): bool
                {
                    return false;
                }
            },
            FederationWorker::DEFAULT_PORT,
            1,
            '127.0.0.1',
            RelayProxyProtocol::DEFAULT_CHANNEL_PORT,
        );

        // Must not throw: there is no broker, and that is a normal state.
        $worker->onWorkerStart();

        self::assertSame(
            0,
            FederationWorker::getActiveConnectionCount(),
            'control: the failed join must leave the WS surface untouched',
        );
    }

    /**
     * A container that cannot produce the dispatcher must also not throw —
     * the instanceof-refusal arm logs and returns.
     */
    public function testTheFederationWorkerBootRefusesSilentlyWhenTheDispatcherIsUnresolvable(): void
    {
        $this->forceWorkermanRuntime();

        // The get() above returns null; with a live ChannelClient connection
        // impossible here, this pins the same no-throw contract from the other
        // arm. (Both arms share one entry point; kept as a separate test so a
        // future refactor that drops either log-and-continue fails distinctly.)
        $worker = new FederationWorker(
            new class implements ContainerInterface {
                public function get(string $id): mixed
                {
                    throw new \LogicException('container down');
                }

                public function has(string $id): bool
                {
                    throw new \LogicException('container down');
                }
            },
        );

        $worker->onWorkerStart();

        $this->addToAssertionCount(1);
    }

    // ==================================================================
    // 7. The protocol constants
    // ==================================================================

    public function testTheProtocolIsAConstantsHolderThatCannotBeInstantiated(): void
    {
        $reflection = new ReflectionClass(FederationPushProtocol::class);

        self::assertFalse(
            $reflection->isInstantiable(),
            'FederationPushProtocol must stay a constants holder — an instantiable one invites '
            . 'per-instance state on a value both processes must read identically',
        );
        self::assertTrue($reflection->isFinal(), 'the protocol must not be subclassable');

        self::assertNotSame('', FederationPushProtocol::COMMAND_EVENT);
        self::assertNotSame(
            FederationPushProtocol::ACTION_OFFER,
            FederationPushProtocol::ACTION_REVOCATION,
        );
        self::assertNotSame(
            FederationPushProtocol::ACTION_REVOCATION,
            FederationPushProtocol::ACTION_CLOSE_PEER,
        );
    }

    /**
     * The admin path waits on this constant whenever the :8805 process is
     * gone. Pinned small on purpose (the DB truth is committed before the
     * dispatch; a generous timeout buys nothing but a stalled request) and
     * positive so a typo cannot make every command self-timeout-before-reply.
     */
    public function testTheReplyTimeoutIsPositiveAndShort(): void
    {
        self::assertGreaterThan(0.0, FederationPushProtocol::REPLY_TIMEOUT_SECONDS);
        self::assertLessThanOrEqual(
            3.0,
            FederationPushProtocol::REPLY_TIMEOUT_SECONDS,
            'the command is published AFTER the DB commit on an admin request; waiting long on a '
            . 'dead :8805 process only delays a response whose truth is already stored',
        );
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    /**
     * A bridge whose publisher hands the command SYNCHRONOUSLY to a real
     * dispatcher driving the REAL test connection manager, whose publisher
     * hands the reply back to the bridge — the whole cross-process round trip,
     * in one process, with no doubles in the middle (the Workerman connection
     * is the only mock, standing in for the socket itself).
     *
     * The dispatcher's reply is forwarded only when addressed to THIS bridge's
     * reply event, so a dispatcher publishing on the wrong event shows up as a
     * timeout rather than being quietly accepted.
     *
     * @param list<array{event: string, data: array<string, mixed>}> $published
     */
    private function wiredBridge(array &$published): FederationPushBridge
    {
        $published = [];

        $bridge = null;
        $dispatcher = new FederationPushDispatcher(
            new StructuredLogger('fed-push-roundtrip-test', []),
            $this->connMgr,
            static function (string $event, array $data) use (&$bridge): void {
                /** @var FederationPushBridge $bridge */
                if ($event !== $bridge->replyEvent()) {
                    return;
                }
                $bridge->onReply($data);
            },
        );

        $bridge = new FederationPushBridge(
            new StructuredLogger('fed-push-roundtrip-test', []),
            static function (string $event, array $data) use ($dispatcher, &$published): void {
                $published[] = ['event' => $event, 'data' => $data];
                $dispatcher->onCommand($data);
            },
        );

        return $bridge;
    }

    /**
     * @param list<array{0: string, 1: array<string, mixed>}> $replies
     */
    private function collectingDispatcher(array &$replies): FederationPushDispatcher
    {
        return new FederationPushDispatcher(
            new StructuredLogger('fed-push-roundtrip-test', []),
            $this->connMgr,
            static function (string $event, array $data) use (&$replies): void {
                $replies[] = [$event, $data];
            },
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function wellFormedCommand(): array
    {
        return [
            'request_id' => 'req-1',
            'reply_event' => 'reply.event.test',
            'action' => FederationPushProtocol::ACTION_REVOCATION,
            'leaf_hub_id' => 'leaf-uuid',
            'payload' => '{"share_id":"share-1"}',
        ];
    }
}
