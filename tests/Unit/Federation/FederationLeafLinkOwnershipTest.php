<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Federation;

use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Federation\FederationAdminDelegationRepository;
use Phlix\Hub\Federation\FederationConnectionManager;
use Phlix\Hub\Federation\FederationHubRepository;
use Phlix\Hub\Federation\FederationLibraryShareRepository;
use Phlix\Hub\Federation\FederationPeerManager;
use Phlix\Hub\Federation\FederationPushBridge;
use Phlix\Hub\Federation\FederationPushDispatcher;
use Phlix\Hub\Federation\FederationPushProtocol;
use Phlix\Hub\Federation\FederationSessionManager;
use Phlix\Hub\Hub\Ed25519KeyManager;
use Phlix\Hub\Relay\FrameDecoder;
use Phlix\Hub\Tests\Support\WorkermanTimerRuntimeControl;
use Phlix\Shared\Relay\RelayFrameType;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Throwable;
use Workerman\Connection\AsyncTcpConnection;

/**
 * Ownership law for the leaf federation link (review hub__asphp Critical).
 *
 * The leaf link is owned by the single :8805 federation worker process —
 * the same seat that runs its timers and reconnect ladder. These tests pin
 * the four post-fix truths:
 *
 *  1. a NON-owner instance refuses connect/disconnect/push loudly and
 *     touches nothing (no DB reads, no sockets, no ladders of its own);
 *  2. the HTTP→worker boundary (the same push bridge the master side uses)
 *     executes lifecycle and pushes in the OWNER seat only — commands can
 *     not smuggle ownership;
 *  3. disconnect in the owner seat truly closes the socket and kills the
 *     reconnect re-dial (measured, not theater);
 *  4. outbound pushes without a live verified link fail LOUD (warning +
 *     counter + false verdict) instead of vanishing, and the leaf's active
 *     outgoing shares are replayed the moment the H-4 channel verifies —
 *     the symmetry the master side always had.
 *
 * Sockets are doubles (AsyncTcpConnection mocks); timer legs run under the
 * pcntl task-table stand-in from WorkermanTimerRuntimeControl.
 */
final class FederationLeafLinkOwnershipTest extends TestCase
{
    use WorkermanTimerRuntimeControl;

    private FederationHubRepository&MockObject $hubRepo;
    private FederationSessionManager&MockObject $sessions;
    private FederationLibraryShareRepository&MockObject $libraryShares;
    private FederationAdminDelegationRepository&MockObject $adminDel;
    private AuditLogger&MockObject $audit;
    private string $keyDir;
    private string $loggerTmp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hubRepo = $this->createMock(FederationHubRepository::class);
        $this->sessions = $this->createMock(FederationSessionManager::class);
        $this->libraryShares = $this->createMock(FederationLibraryShareRepository::class);
        $this->adminDel = $this->createMock(FederationAdminDelegationRepository::class);
        $this->audit = $this->createMock(AuditLogger::class);

        $this->keyDir = sys_get_temp_dir() . '/phlix-hub-fed-own-keys-' . bin2hex(random_bytes(6));
        mkdir($this->keyDir, 0700, true);

        // Refusals log through LoggerFactory::get(RELAY) — seed a memory sink.
        $this->loggerTmp = sys_get_temp_dir() . '/phlix-hub-fed-own-' . bin2hex(random_bytes(6));
        mkdir($this->loggerTmp, 0700, true);
        file_put_contents(
            $this->loggerTmp . '/logger.php',
            "<?php return ['default' => 'mem', 'handlers' => ['mem' => "
            . "['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug']]];",
        );
        LoggerFactory::reset();
        LoggerFactory::init($this->loggerTmp . '/logger.php');
    }

    protected function tearDown(): void
    {
        LoggerFactory::reset();
        $this->rrmdir($this->keyDir);
        $this->rrmdir($this->loggerTmp);

        parent::tearDown();
    }

    // ------------------------------------------------------------------ 1
    // The non-owner seat refuses loudly and touches NOTHING.

    public function testNonOwnerConnectRefusesWithoutAnyStateTouch(): void
    {
        $resolverCalls = 0;
        $manager = $this->manager(static function () use (&$resolverCalls): bool {
            $resolverCalls++;

            return true;
        });

        // A refusal may not read config, dial, or even consult the kill
        // switch — the guard sits before everything.
        $this->hubRepo->expects(self::never())->method('getHubConfig');
        $this->hubRepo->expects(self::never())->method('getDialablePeers');

        $manager->connectToMaster();

        self::assertSame(1, $manager->getRefusedUnownedCalls());
        self::assertSame(0, $resolverCalls, 'the enabled gate must not run for a non-owner');
        self::assertFalse($manager->isConnected());
        self::assertFalse($manager->ownsLeafLink());
    }

    public function testNonOwnerDisconnectRefusesAndLeavesLatchedStateAlone(): void
    {
        $manager = $this->manager();
        // Pretend an armed ladder exists; an OWNER disconnect clears it — a
        // NON-owner disconnect must leave the state bit-identical.
        $this->writeProp($manager, 'reconnectScheduled', true);

        $manager->disconnectFromMaster();

        self::assertSame(1, $manager->getRefusedUnownedCalls());
        self::assertTrue((bool) $this->readProp($manager, 'reconnectScheduled'));
        self::assertFalse((bool) $this->readProp($manager, 'intentionalDisconnect'));
    }

    public function testNonOwnerPushesRefuseWithFalseVerdicts(): void
    {
        $manager = $this->manager();

        self::assertFalse($manager->pushLibraryShare('share-1', 'lib-1', 'Movies', 'read'));
        self::assertFalse($manager->pushLibraryShareRevoked('share-1'));

        // Unowned refusals count on the unowned counter — loud, measured,
        // and they never reach the send-verdict path.
        self::assertSame(2, $manager->getRefusedUnownedCalls());
        self::assertSame(0, $manager->getRefusedOutboundPushes());
    }

    public function testBootstrapFromWorkerIsTheSoleClaim(): void
    {
        $manager = $this->manager(static fn (): bool => false);
        self::assertFalse($manager->ownsLeafLink());

        // No Workerman runtime in CLI: the parked-hold arm inside throws and
        // is swallowed exactly like production; the CLAIM precedes it.
        $manager->bootstrapFromWorker();

        self::assertTrue($manager->ownsLeafLink());
        self::assertSame(0, $manager->getRefusedUnownedCalls());
    }

    // ------------------------------------------------------------------ 2
    // Owner-seat push law.

    public function testOwnerPushWithoutLinkRefusesLoudly(): void
    {
        $manager = $this->manager();
        $manager->claimLeafLinkOwnership();

        self::assertFalse($manager->pushLibraryShare('share-1', 'lib-1', 'Movies', 'read'));
        self::assertFalse($manager->pushLibraryShareRevoked('share-1'));

        self::assertSame(2, $manager->getRefusedOutboundPushes());
        self::assertSame(0, $manager->getDroppedOutboundFrames());
    }

    public function testOwnerPushOnUnverifiedLinkIsRefused(): void
    {
        // H-4 outbound symmetry: a dialing, unverified channel carries the
        // handshake only — DATA pushes wait for the verified stamp.
        $manager = $this->manager();
        $manager->claimLeafLinkOwnership();
        $conn = $this->createMock(AsyncTcpConnection::class);
        $conn->expects(self::never())->method('send');
        $this->writeProp($manager, 'masterConnection', $conn);

        self::assertFalse($manager->pushLibraryShareRevoked('share-1'));
        self::assertSame(1, $manager->getRefusedOutboundPushes());
    }

    public function testOwnerPushOnVerifiedLinkSends(): void
    {
        $this->hubRepo->method('getHubConfig')->willReturn(['id' => 'own-hub-uuid']);
        $manager = $this->manager();
        $manager->claimLeafLinkOwnership();
        $sent = [];
        $this->attachVerifiedLink($manager, $sent);

        self::assertTrue($manager->pushLibraryShare('share-1', 'lib-1', 'Movies', 'read'));
        self::assertCount(1, $sent);
        self::assertSame(0, $manager->getRefusedOutboundPushes());
    }

    public function testOwnerConnectWithLiveLinkSpawnsNoSecondSocket(): void
    {
        // The second half of the rogue-link cure: even the OWNER cannot
        // double-dial — establishConnection's identity guard rejects while a
        // socket exists, so no second AsyncTcpConnection is ever built.
        $this->hubRepo->method('getHubConfig')->willReturn([
            'id' => 'own-hub-uuid',
            'role' => 'leaf',
            'is_active' => 1,
        ]);
        $this->hubRepo->method('getDialablePeers')->willReturn([[
            'id' => 'peer-1',
            'url' => 'ws://127.0.0.1:9/',
        ]]);

        $manager = $this->manager(static fn (): bool => true);
        $manager->claimLeafLinkOwnership();
        $conn = $this->createMock(AsyncTcpConnection::class);
        $conn->expects(self::never())->method('send');
        $this->writeProp($manager, 'masterConnection', $conn);
        $before = spl_object_id($conn);

        $manager->connectToMaster();

        $after = $this->readProp($manager, 'masterConnection');
        self::assertNotFalse($after);
        /** @var object $after */
        self::assertSame($before, spl_object_id($after), 'the one link stays the one link');
    }

    public function testOwnerDisconnectClosesSocketAndStopsRedial(): void
    {
        // The no-theater law, measured in the owner seat with a REAL timer
        // runtime: closing kills the socket AND the armed ladder.
        $this->forceWorkermanRuntime();

        $manager = $this->manager(static fn (): bool => false);
        $manager->claimLeafLinkOwnership();
        $conn = $this->createMock(AsyncTcpConnection::class);
        $conn->expects(self::once())->method('close');
        $this->writeProp($manager, 'masterConnection', $conn);
        // An armed reconnect tick exists (what "re-dial" means here).
        $this->writeProp($manager, 'reconnectScheduled', true);
        $this->writeProp($manager, 'reconnectTimerId', 4242);

        $manager->disconnectFromMaster();

        self::assertNull($this->readProp($manager, 'masterConnection'));
        self::assertFalse($manager->isConnected());
        self::assertFalse($manager->linkLiveOrArmed());
        self::assertFalse((bool) $this->readProp($manager, 'reconnectScheduled'));
    }

    // ------------------------------------------------------------------ 3
    // The durable boundary: leaf commands execute in the owner seat, and
    // nothing else. Same bridge/dispatcher pair production runs.

    public function testBridgeOfferCannotSmuggleOwnershipIntoUnclaimedManager(): void
    {
        $manager = $this->manager();
        $published = [];
        $bridge = $this->wiredLeafBridge($published, $manager);

        $delivered = $bridge->dispatchLeaf(
            FederationPushProtocol::ACTION_LEAF_SHARE_OFFER,
            '{"share_id":"s-1","library_id":"l-1","library_name":"M","permission":"read"}',
        );

        self::assertFalse($delivered, 'a command executed against a non-owner measures false');
        self::assertCount(1, $published);
        self::assertSame('', $published[0]['data']['leaf_hub_id'], 'the leaf family self-addresses');
        self::assertSame(1, $manager->getRefusedUnownedCalls());
    }

    public function testBridgeConnectOnDisabledOwnerArmsTheParkedLadder(): void
    {
        // enable/connect from HTTP lands as an ARMED ladder in the worker —
        // observable worker state, not just a DB flag.
        $this->forceWorkermanRuntime();

        $manager = $this->manager(static fn (): bool => false);
        $manager->claimLeafLinkOwnership();
        $published = [];
        $bridge = $this->wiredLeafBridge($published, $manager);

        self::assertTrue($bridge->dispatchLeaf(FederationPushProtocol::ACTION_LEAF_CONNECT));
        self::assertTrue($manager->linkLiveOrArmed());
        self::assertTrue((bool) $this->readProp($manager, 'reconnectScheduled'));
    }

    public function testBridgeDisconnectClosesLinkAndKillsReconnect(): void
    {
        $this->forceWorkermanRuntime();

        $manager = $this->manager(static fn (): bool => false);
        $manager->claimLeafLinkOwnership();
        $conn = $this->createMock(AsyncTcpConnection::class);
        $conn->expects(self::once())->method('close');
        $this->writeProp($manager, 'masterConnection', $conn);
        $published = [];
        $bridge = $this->wiredLeafBridge($published, $manager);

        // First CONNECT against a live link reports LIVE (the socket exists;
        // the disabled park-arm is skipped while a link stands).
        self::assertTrue($bridge->dispatchLeaf(FederationPushProtocol::ACTION_LEAF_CONNECT));
        self::assertFalse((bool) $this->readProp($manager, 'reconnectScheduled'));

        self::assertTrue($bridge->dispatchLeaf(FederationPushProtocol::ACTION_LEAF_DISCONNECT));

        self::assertNull($this->readProp($manager, 'masterConnection'));
        self::assertFalse($manager->linkLiveOrArmed(), 'disconnect stopped the re-dial — no theater');

        // The M-7 flag survives the boundary: a follow-up connect while
        // federation is off arms nothing — the deliberate disconnect is final
        // until an explicit enable makes the next dial meaningful.
        self::assertFalse($bridge->dispatchLeaf(FederationPushProtocol::ACTION_LEAF_CONNECT));
        self::assertFalse((bool) $this->readProp($manager, 'reconnectScheduled'));
    }

    public function testBridgeOfferEndToEndDeliversTrueOverLiveVerifiedLink(): void
    {
        $this->hubRepo->method('getHubConfig')->willReturn(['id' => 'own-hub-uuid']);
        $manager = $this->manager();
        $manager->claimLeafLinkOwnership();
        $sent = [];
        $this->attachVerifiedLink($manager, $sent);
        $published = [];
        $bridge = $this->wiredLeafBridge($published, $manager);

        self::assertTrue($bridge->dispatchLeaf(
            FederationPushProtocol::ACTION_LEAF_SHARE_OFFER,
            '{"share_id":"s-1","library_id":"l-1","library_name":"Movies","permission":"read"}',
        ));
        self::assertCount(1, $sent);
    }

    public function testBridgeOfferWithoutLinkDeliversFalseAndCountsLoud(): void
    {
        $manager = $this->manager();
        $manager->claimLeafLinkOwnership();
        $published = [];
        $bridge = $this->wiredLeafBridge($published, $manager);

        self::assertFalse($bridge->dispatchLeaf(
            FederationPushProtocol::ACTION_LEAF_SHARE_REVOCATION,
            '{"share_id":"s-1"}',
        ));
        self::assertSame(1, $manager->getRefusedOutboundPushes());
    }

    public function testLeafCommandCarryingForeignLeafHubIdGetsNoReply(): void
    {
        // The envelope law: the leaf family self-addresses. A forged
        // non-empty leaf_hub_id is malformed — refused with NO reply.
        $replies = [];
        $dispatcher = $this->collectingDispatcher($replies, $this->manager());

        $dispatcher->onCommand($this->envelope(FederationPushProtocol::ACTION_LEAF_CONNECT, 'x', 'not-leaf-uuid'));

        self::assertSame([], $replies);
    }

    public function testLifecycleVerbCarryingPayloadGetsNoReply(): void
    {
        $replies = [];
        $dispatcher = $this->collectingDispatcher($replies, $this->manager());

        $dispatcher->onCommand($this->envelope(FederationPushProtocol::ACTION_LEAF_DISCONNECT, 'oops'));

        self::assertSame([], $replies);
    }

    public function testShareVerbWithEmptyPayloadGetsNoReply(): void
    {
        $replies = [];
        $dispatcher = $this->collectingDispatcher($replies, $this->manager());

        $dispatcher->onCommand($this->envelope(FederationPushProtocol::ACTION_LEAF_SHARE_OFFER, ''));

        self::assertSame([], $replies);
    }

    public function testShareVerbWithNonJsonPayloadRepliesFalse(): void
    {
        // Well-formed request, broken request-body: the worker ANSWERS with
        // a measured negative (this is a real verdict, not a lost message).
        $replies = [];
        $dispatcher = $this->collectingDispatcher($replies, $this->manager());

        $dispatcher->onCommand($this->envelope(FederationPushProtocol::ACTION_LEAF_SHARE_OFFER, 'not-json{'));

        self::assertCount(1, $replies);
        self::assertSame('reply.leaf.test', $replies[0][0]);
        self::assertFalse($replies[0][1]['delivered']);
    }

    public function testMasterSideVerbWithEmptyLeafHubIdGetsNoReply(): void
    {
        // Rotation pin: the pre-existing master family now REQUIRES a
        // non-empty leaf_hub_id — the leaf family is the only '' citizen.
        $replies = [];
        $dispatcher = $this->collectingDispatcher($replies, $this->manager());

        $dispatcher->onCommand($this->envelope(FederationPushProtocol::ACTION_REVOCATION, '{"share_id":"s"}', ''));

        self::assertSame([], $replies);
    }

    // ------------------------------------------------------------------ 4
    // Hello-time replay: the leaf-side mirror of the master's
    // pushLibrarySharesToLeaf (FederationFrameHandler) — convergence after
    // any gap, idempotent by the master's ON DUPLICATE KEY upsert.

    public function testReplayBatchesActiveOutgoingSharesIntoOneFrame(): void
    {
        $manager = $this->manager();
        $manager->claimLeafLinkOwnership();
        $sent = [];
        $this->attachVerifiedLink($manager, $sent);

        $this->libraryShares->method('getActiveOutgoingSharesForPeer')->willReturn([
            [
                'id' => 's-1',
                'library_id' => 'l-1',
                'library_name' => 'Movies',
                'permission' => 'read',
                'status' => 'active',
            ],
            ['library_id' => 'l-2'], // malformed row — skipped, never half-encoded
            ['id' => 's-3', 'library_id' => 'l-3', 'library_name' => 'Music', 'permission' => 'write', 'status' => ''],
        ]);

        $this->replay($manager, ['id' => 'own-hub-uuid']);

        self::assertCount(1, $sent, 'one batched frame, mirroring the master-side replay');
        $frame = (new FrameDecoder())->decode($sent[0]);
        self::assertNotNull($frame);
        self::assertSame(RelayFrameType::DATA, $frame->type);
        /** @var array{shares: list<array<string, string>>} $decoded */
        $decoded = json_decode($frame->payload, true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(2, $decoded['shares']);
        self::assertSame('own-hub-uuid', $decoded['shares'][0]['peer_id'], 'M-5: origin identity');
        self::assertSame('active', $decoded['shares'][1]['status'], 'blank status defaults to active');
    }

    public function testReplayIsFailSoftAgainstRepoThrow(): void
    {
        // A throwing catalog read must NEVER take the freshly verified link
        // down (the heartbeat/ack ceremony just completed on it).
        $manager = $this->manager();
        $manager->claimLeafLinkOwnership();
        $sent = [];
        $this->attachVerifiedLink($manager, $sent);
        $this->libraryShares->method('getActiveOutgoingSharesForPeer')
            ->willThrowException(new RuntimeException('catalog down'));

        $this->replay($manager, ['id' => 'own-hub-uuid']);

        self::assertSame([], $sent);
        self::assertSame(1, $manager->getRefusedOutboundPushes(), 'the refusal was counted, loudly');
    }

    public function testReplayDoesNothingWithoutVerifiedLink(): void
    {
        $manager = $this->manager();
        $manager->claimLeafLinkOwnership();
        $this->libraryShares->expects(self::never())->method('getActiveOutgoingSharesForPeer');

        $this->replay($manager, ['id' => 'own-hub-uuid']);
    }

    // ------------------------------------------------------------------ harness

    /**
     * @param (callable(): bool)|null $enabledResolver
     */
    private function manager(?callable $enabledResolver = null): FederationPeerManager
    {
        return new FederationPeerManager(
            $this->hubRepo,
            $this->sessions,
            $this->libraryShares,
            $this->adminDel,
            $this->audit,
            new Ed25519KeyManager($this->keyDir . '/leaf-ed25519.pem'),
            $enabledResolver,
        );
    }

    /**
     * Attach the observable state of an established, H-4-verified link whose
     * send() takes every frame (null verdict = buffered) and records bytes.
     *
     * @param list<string> $sent
     */
    private function attachVerifiedLink(FederationPeerManager $manager, array &$sent): void
    {
        $conn = $this->createMock(AsyncTcpConnection::class);
        $conn->method('send')->willReturnCallback(
            static function (string $frame) use (&$sent): bool {
                $sent[] = $frame;

                return true;
            },
        );
        $this->writeProp($manager, 'masterConnection', $conn);
        $this->writeProp($manager, 'channelVerified', true);
        $this->writeProp($manager, 'masterPeerId', 'peer-1');
    }

    /**
     * @param array<array-key, mixed> $hubConfig
     */
    private function replay(FederationPeerManager $manager, array $hubConfig): void
    {
        $method = new ReflectionMethod($manager, 'replayActiveOutgoingShares');
        $method->setAccessible(true);
        $method->invoke($manager, $hubConfig);
    }

    /**
     * @param list<array{event: string, data: array<string, mixed>}> $published
     */
    private function wiredLeafBridge(array &$published, FederationPeerManager $manager): FederationPushBridge
    {
        $published = [];

        $bridge = null;
        $dispatcher = new FederationPushDispatcher(
            new StructuredLogger('fed-leaf-ownership-test', []),
            new FederationConnectionManager(),
            static function (string $event, array $data) use (&$bridge): void {
                /** @var FederationPushBridge $bridge */
                if ($event !== $bridge->replyEvent()) {
                    return;
                }
                $bridge->onReply($data);
            },
            $manager,
        );

        $bridge = new FederationPushBridge(
            new StructuredLogger('fed-leaf-ownership-test', []),
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
    private function collectingDispatcher(array &$replies, FederationPeerManager $manager): FederationPushDispatcher
    {
        $replies = [];

        return new FederationPushDispatcher(
            new StructuredLogger('fed-leaf-ownership-test', []),
            new FederationConnectionManager(),
            static function (string $event, array $data) use (&$replies): void {
                $replies[] = [$event, $data];
            },
            $manager,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function envelope(string $action, string $payload, string $leafHubId = ''): array
    {
        return [
            'request_id' => 'req-leaf-1',
            'reply_event' => 'reply.leaf.test',
            'action' => $action,
            'leaf_hub_id' => $leafHubId,
            'payload' => $payload,
        ];
    }

    private function readProp(object $object, string $name): mixed
    {
        $property = new ReflectionProperty($object, $name);
        $property->setAccessible(true);

        return $property->getValue($object);
    }

    private function writeProp(object $object, string $name, mixed $value): void
    {
        $property = new ReflectionProperty($object, $name);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * Keep Throwable referenced even when the fail-soft leg is trimmed —
     * documents that replay swallows EVERY throw, not just RuntimeException.
     */
    public function testReplaySwallowsEveryThrowableShape(): void
    {
        $manager = $this->manager();
        $manager->claimLeafLinkOwnership();
        $sent = [];
        $this->attachVerifiedLink($manager, $sent);
        $this->libraryShares->method('getActiveOutgoingSharesForPeer')
            ->willThrowException(new class extends RuntimeException implements Throwable {
            });

        $this->replay($manager, ['id' => 'own-hub-uuid']);

        self::assertSame([], $sent);
        self::assertSame(1, $manager->getRefusedOutboundPushes());
    }
}
