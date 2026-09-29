<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Federation;

use Phlix\Hub\Federation\FederationFrameHandler;
use Phlix\Hub\Federation\FederationHubRepository;
use Phlix\Hub\Federation\FederationLibraryShareRepository;
use Phlix\Hub\Federation\FederationSessionManager;
use Phlix\Hub\Federation\FederationConnectionManager;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\LoggerFactory;
use PHPUnit\Framework\TestCase;
use Workerman\Connection\ConnectionInterface;

/**
 * Unit tests for {@see FederationFrameHandler}.
 *
 * @package Phlix\Hub\Tests\Unit\Federation
 */
final class FederationFrameHandlerTest extends TestCase
{
    private FederationConnectionManager $realConnMgr;

    private ?string $loggerTmp = null;

    protected function setUp(): void
    {
        parent::setUp();
        // FederationConnectionManager is final, so we use a real instance
        $this->realConnMgr = new FederationConnectionManager();

        // The mismatch/zombie/dataframe paths log via LoggerFactory::get(RELAY);
        // unit context has no config path — seed the established in-memory one.
        $this->loggerTmp = sys_get_temp_dir() . '/phlix-hub-fed-frame-' . bin2hex(random_bytes(6));
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
        if ($this->loggerTmp !== null && is_file($this->loggerTmp . '/logger.php')) {
            unlink($this->loggerTmp . '/logger.php');
            rmdir($this->loggerTmp);
        }
        $this->loggerTmp = null;
        parent::tearDown();
    }

    private function handler(
        ?FederationHubRepository $hubRepo = null,
        ?FederationSessionManager $sessions = null,
        ?FederationLibraryShareRepository $libraryShares = null,
        ?AuditLogger $audit = null,
    ): FederationFrameHandler {
        return new FederationFrameHandler(
            $hubRepo ?? $this->createMock(FederationHubRepository::class),
            $sessions ?? $this->createMock(FederationSessionManager::class),
            $libraryShares ?? $this->createMock(FederationLibraryShareRepository::class),
            $this->realConnMgr,
            $audit ?? $this->createMock(AuditLogger::class),
        );
    }

    // -------------------------------------------------------- handleTextFrame

    public function testHandleTextFrameRejectsInvalidJson(): void
    {
        $handler = $this->handler();
        $result = $handler->handleTextFrame('hub-1', 'not valid json {');

        self::assertSame('Invalid JSON payload', $result);
    }

    public function testHandleTextFrameRejectsNonArrayPayload(): void
    {
        $handler = $this->handler();
        $result = $handler->handleTextFrame('hub-1', '"just a string"');

        self::assertSame('Invalid frame payload', $result);
    }

    public function testHandleTextFrameRejectsMissingType(): void
    {
        $handler = $this->handler();
        $result = $handler->handleTextFrame('hub-1', '{"foo":"bar"}');

        self::assertSame('Missing frame type', $result);
    }

    public function testHandleTextFrameAcceptsUnknownTypeAsNoOp(): void
    {
        $hubRepo = $this->createMock(FederationHubRepository::class);
        $hubRepo->expects(self::never())->method('getPeerByPublicKey');

        $handler = $this->handler($hubRepo);
        $result = $handler->handleTextFrame('hub-1', '{"type":"unknown_type"}');

        self::assertNull($result);
    }

    public function testHandleTextFrameHubHelloRejectsEmptyPublicKey(): void
    {
        $handler = $this->handler();
        $result = $handler->handleTextFrame('hub-1', '{"type":"hub_hello","public_key":""}');

        self::assertSame('Invalid peer key', $result);
    }

    public function testHandleTextFrameHubHelloRejectsUnknownPeer(): void
    {
        $hubRepo = $this->createMock(FederationHubRepository::class);
        $hubRepo->method('getPeerByPublicKey')->willReturn(null);

        $handler = $this->handler($hubRepo);
        $result = $handler->handleTextFrame('hub-1', '{"type":"hub_hello","public_key":"unknown_key"}');

        self::assertSame('Invalid peer key', $result);
    }

    /**
     * C-1(2) regression: a peer whose row is 'connected' (stale row after a
     * crash without onClose) or 'disconnected' (clean drop / reap) MUST be
     * accepted on re-HELLO. The old gate required 'pending', which the first
     * HELLO consumed forever — every reconnect was rejected permanently.
     */
    public function testHubHelloAcceptsConnectedPeerReHello(): void
    {
        $this->expectHelloAcceptedWithPeerStatus('connected');
    }

    public function testHubHelloAcceptsDisconnectedPeerReHello(): void
    {
        $this->expectHelloAcceptedWithPeerStatus('disconnected');
    }

    public function testHubHelloAcceptsPendingPeer(): void
    {
        $this->expectHelloAcceptedWithPeerStatus('pending');
    }

    public function testHubHelloRejectsSuspendedPeer(): void
    {
        $hubRepo = $this->createMock(FederationHubRepository::class);
        $hubRepo->method('getPeerByPublicKey')->willReturn($this->peerRow('suspended'));
        $sessions = $this->createMock(FederationSessionManager::class);
        $sessions->expects(self::never())->method('registerSession');

        $handler = $this->handler($hubRepo, $sessions);
        $result = $handler->handleTextFrame('leaf-hub-1', $this->helloJson());

        self::assertSame('Peer suspended', $result);
    }

    /**
     * L-6 regression: HELLO arriving before the connection is registered
     * must reject (no ACK-less orphan) and must NOT register a session.
     * The old code registered first, looked up the connection second, and
     * returned null — leaving a live session row with no ack ever sent.
     */
    public function testHubHelloRejectsWhenConnectionNotRegistered(): void
    {
        $hubRepo = $this->createMock(FederationHubRepository::class);
        $hubRepo->method('getPeerByPublicKey')->willReturn($this->peerRow('pending'));
        $sessions = $this->createMock(FederationSessionManager::class);
        $sessions->expects(self::never())->method('registerSession');

        // No addConnection() on realConnMgr → getConnection() is null.
        $handler = $this->handler($hubRepo, $sessions);
        $result = $handler->handleTextFrame('hub-unknown', $this->helloJson());

        self::assertSame('Connection not registered', $result);
    }

    /**
     * C-1(3) regression: an unbound peer gets its reported hub_id bound to
     * federation_peers.leaf_hub_id (migration 046) during HELLO.
     */
    public function testHubHelloBindsLeafHubIdWhenUnbound(): void
    {
        $hubRepo = $this->createMock(FederationHubRepository::class);
        $hubRepo->method('getPeerByPublicKey')->willReturn($this->peerRow('pending', ''));
        $hubRepo->expects(self::once())
            ->method('setPeerLeafHubId')
            ->with('peer-1', 'leaf-hub-1');

        $conn = $this->createMock(ConnectionInterface::class);
        $conn->method('send');
        $this->realConnMgr->addConnection('leaf-hub-1', $conn);

        $handler = $this->handler($hubRepo);
        $result = $handler->handleTextFrame('leaf-hub-1', $this->helloJson());

        self::assertNull($result);
    }

    /**
     * C-1(3) / L-6 regression: once bound, a HELLO reporting a DIFFERENT
     * hub_id is a key/identity confusion — refuse loudly.
     */
    public function testHubHelloRejectsMismatchedHubIdAfterBinding(): void
    {
        $hubRepo = $this->createMock(FederationHubRepository::class);
        $hubRepo->method('getPeerByPublicKey')->willReturn($this->peerRow('pending', 'leaf-hub-1'));
        $hubRepo->expects(self::never())->method('setPeerLeafHubId');
        $sessions = $this->createMock(FederationSessionManager::class);
        $sessions->expects(self::never())->method('registerSession');

        $handler = $this->handler($hubRepo, $sessions);
        $result = $handler->handleTextFrame(
            'leaf-hub-1',
            '{"type":"hub_hello","public_key":"valid_key","hub_id":"OTHER-hub","hub_name":"L"}',
        );

        self::assertSame('Peer hub_id mismatch', $result);
    }

    /**
     * @return array<string, mixed>
     */
    private function peerRow(string $status, string $leafHubId = 'leaf-hub-1'): array
    {
        return [
            'id' => 'peer-1',
            'name' => 'Test Peer',
            'url' => 'https://peer.example.com',
            'status' => $status,
            'leaf_hub_id' => $leafHubId,
        ];
    }

    private function helloJson(): string
    {
        return '{"type":"hub_hello","public_key":"valid_key","hub_id":"leaf-hub-1","hub_name":"Leaf"}';
    }

    private function expectHelloAcceptedWithPeerStatus(string $status): void
    {
        $hubRepo = $this->createMock(FederationHubRepository::class);
        $hubRepo->method('getPeerByPublicKey')->willReturn($this->peerRow($status));
        $hubRepo->method('getHubConfig')->willReturn(['id' => 'master-hub-1']);
        $hubRepo->expects(self::once())->method('updatePeerStatus')->with('peer-1', 'connected');

        $sessions = $this->createMock(FederationSessionManager::class);
        $sessions->method('registerSession')->willReturn('session-uuid-1');

        $shares = $this->createMock(FederationLibraryShareRepository::class);
        $shares->method('getActiveOutgoingSharesForPeer')->willReturn([]);

        $audit = $this->createMock(AuditLogger::class);
        $audit->expects(self::once())->method('logHubConnect');

        $conn = $this->createMock(ConnectionInterface::class);
        $sentAcks = 0;
        $conn->method('send')->willReturnCallback(
            static function (string $data) use (&$sentAcks): void {
                if (str_contains($data, 'hub_hello_ack')) {
                    $sentAcks++;
                }
            },
        );
        $this->realConnMgr->addConnection('leaf-hub-1', $conn);

        $handler = $this->handler($hubRepo, $sessions, $shares, $audit);
        $result = $handler->handleTextFrame('leaf-hub-1', $this->helloJson());

        self::assertNull($result, "HELLO from a '{$status}' peer must be accepted");
        self::assertSame(1, $sentAcks, 'Exactly one HELLO_ACK must be sent');
    }

    // ---------------------------------------------------- heartbeat / reaping

    /**
     * H-2 regression: master-side heartbeats must refresh the session row
     * ADDRESSED BY PEER (touchHeartbeatByPeerId), never by feeding a hub UUID
     * to the session-UUID-keyed touchHeartbeat (0 rows updated → reaper ate
     * live links).
     */
    public function testHeartbeatTouchesSessionByPeerId(): void
    {
        $hubRepo = $this->createMock(FederationHubRepository::class);
        $hubRepo->method('getPeerById')->willReturn($this->peerRow('connected'));

        $sessions = $this->createMock(FederationSessionManager::class);
        $sessions->expects(self::once())->method('touchHeartbeatByPeerId')->with('peer-1')->willReturn(true);
        $sessions->expects(self::never())->method('touchHeartbeat');

        $conn = $this->createMock(ConnectionInterface::class);
        $conn->expects(self::never())->method('close');
        $this->realConnMgr->addConnection('leaf-hub-1', $conn);

        $handler = $this->handler($hubRepo, $sessions);
        $handler->handleBinaryFrame('leaf-hub-1', '', 6); // HEARTBEAT = 0x06
        self::addToAssertionCount(1);
    }

    /**
     * H-2 zombie convergence: heartbeat on a reaped session (no live row)
     * must make the master send DISCONNECTED and close the WS so the leaf
     * reconnects and re-hellos instead of lingering half-dead.
     */
    public function testHeartbeatOnReapedSessionClosesZombieLink(): void
    {
        $hubRepo = $this->createMock(FederationHubRepository::class);
        $hubRepo->method('getPeerById')->willReturn($this->peerRow('connected'));

        $sessions = $this->createMock(FederationSessionManager::class);
        $sessions->method('touchHeartbeatByPeerId')->willReturn(false);

        $conn = $this->createMock(ConnectionInterface::class);
        $conn->expects(self::once())->method('close');
        $conn->expects(self::atLeastOnce())->method('send');
        $this->realConnMgr->addConnection('leaf-hub-1', $conn);

        $handler = $this->handler($hubRepo, $sessions);
        $handler->handleBinaryFrame('leaf-hub-1', '', 6);

        self::assertFalse($this->realConnMgr->isConnected('leaf-hub-1'), 'Zombie link must be unmapped');
    }

    // ------------------------------------------------------------ close paths

    /**
     * M-6 regression: a late onClose from a SUPERSEDED connection must not
     * unmap the new registration nor mark the peer disconnected.
     */
    public function testHandleConnectionClosedIgnoresSupersededConnection(): void
    {
        $oldConn = $this->createMock(ConnectionInterface::class);
        $newConn = $this->createMock(ConnectionInterface::class);
        $this->realConnMgr->addConnection('leaf-hub-1', $oldConn);
        // addConnection replaces and closes the old one — suppress the close:
        $oldConn->method('close')->willReturnCallback(
            static function (): void {
            },
        );
        $this->realConnMgr->addConnection('leaf-hub-1', $newConn);

        $hubRepo = $this->createMock(FederationHubRepository::class);
        $hubRepo->expects(self::never())->method('getPeerById');
        $sessions = $this->createMock(FederationSessionManager::class);
        $sessions->expects(self::never())->method('getActiveSession');

        $handler = $this->handler($hubRepo, $sessions);
        $handler->handleConnectionClosed('leaf-hub-1', $oldConn);

        self::assertTrue($this->realConnMgr->isConnected('leaf-hub-1'), 'New conn must stay mapped');
        self::assertSame($newConn, $this->realConnMgr->getConnection('leaf-hub-1'));
    }

    /**
     * M-6: onClose for the CURRENT connection closes the live session row
     * (peer → disconnected) instead of leaving alive=1 rows behind.
     */
    public function testHandleConnectionClosedMarksSessionDeadAndPeerDisconnected(): void
    {
        $conn = $this->createMock(ConnectionInterface::class);
        $this->realConnMgr->addConnection('leaf-hub-1', $conn);

        $hubRepo = $this->createMock(FederationHubRepository::class);
        $hubRepo->method('getPeerById')->willReturn($this->peerRow('connected'));

        $sessions = $this->createMock(FederationSessionManager::class);
        $sessions->method('getActiveSession')->with('peer-1')
            ->willReturn(['id' => 'sess-1', 'peer_id' => 'peer-1']);
        $sessions->expects(self::once())->method('closeSession')->with('sess-1');

        $audit = $this->createMock(AuditLogger::class);
        $audit->expects(self::once())->method('logHubDisconnect');

        $handler = $this->handler($hubRepo, $sessions, audit: $audit);
        $handler->handleConnectionClosed('leaf-hub-1', $conn);

        self::assertFalse($this->realConnMgr->isConnected('leaf-hub-1'));
    }

    /**
     * handleDisconnected must resolve the local peer row via the dual-lookup
     * getPeerById (route id may be the peer id OR the bound leaf hub id) and
     * close the session under the RESOLVED id.
     */
    public function testHandleDisconnectedClosesSessionByResolvedPeerId(): void
    {
        $conn = $this->createMock(ConnectionInterface::class);
        $conn->method('close');
        $this->realConnMgr->addConnection('leaf-via-hubid', $conn);

        $hubRepo = $this->createMock(FederationHubRepository::class);
        // Route carries the LEAF's own hub id; dual lookup resolves row 'peer-1'.
        $hubRepo->method('getPeerById')->willReturnCallback(
            static fn (string $v) => $v === 'leaf-via-hubid' ? ['id' => 'peer-1', 'name' => 'Test Peer'] : null,
        );

        $sessions = $this->createMock(FederationSessionManager::class);
        $sessions->method('getActiveSession')->with('peer-1')
            ->willReturn(['id' => 'sess-9', 'peer_id' => 'peer-1']);
        $sessions->expects(self::once())->method('closeSession')->with('sess-9');

        $audit = $this->createMock(AuditLogger::class);

        $handler = $this->handler($hubRepo, $sessions, audit: $audit);
        $handler->handleBinaryFrame('leaf-via-hubid', '{"reason":"bye"}', 7); // DISCONNECTED = 0x07

        self::assertFalse($this->realConnMgr->isConnected('leaf-via-hubid'));
    }

    // ------------------------------------------------------------- DATA sync

    /**
     * M-5 regression: leaf → master DATA offers are persisted with the
     * sender's LOCAL row id as FK (never the wire value), instead of the old
     * silent no-op that made one whole direction non-functional.
     */
    public function testDataFrameUpsertsOfferWithLocalPeerFk(): void
    {
        $conn = $this->createMock(ConnectionInterface::class);
        $this->realConnMgr->addConnection('leaf-hub-1', $conn);

        $hubRepo = $this->createMock(FederationHubRepository::class);
        $hubRepo->method('getPeerById')->willReturn($this->peerRow('connected', 'leaf-hub-1'));

        $shares = $this->createMock(FederationLibraryShareRepository::class);
        $shares->expects(self::once())->method('handleIncomingOffer')->with(
            self::callback(static fn (array $o): bool => $o['peer_id'] === 'peer-1' && $o['library_id'] === 'lib-1'),
        );

        $payload = json_encode([
            'shares' => [[
                'id' => 'offer-1',
                'peer_id' => 'leaf-hub-1',
                'library_id' => 'lib-1',
                'library_name' => 'L',
                'permission' => 'read',
            ]],
        ], JSON_THROW_ON_ERROR);

        $handler = $this->handler($hubRepo, libraryShares: $shares);
        $handler->handleBinaryFrame('leaf-hub-1', $payload, 5); // DATA = 0x05
    }

    /**
     * M-5: a DATA offer whose wire peer_id contradicts the bound identity is
     * dropped, never persisted under a mismatched row.
     */
    public function testDataFrameSkipsOfferWithMismatchedWirePeerId(): void
    {
        $conn = $this->createMock(ConnectionInterface::class);
        $this->realConnMgr->addConnection('leaf-hub-1', $conn);

        $hubRepo = $this->createMock(FederationHubRepository::class);
        $hubRepo->method('getPeerById')->willReturn($this->peerRow('connected', 'leaf-hub-1'));

        $shares = $this->createMock(FederationLibraryShareRepository::class);
        $shares->expects(self::never())->method('handleIncomingOffer');

        $payload = json_encode([
            'shares' => [['id' => 'offer-x', 'peer_id' => 'IMPOSTOR-hub', 'library_id' => 'lib-1']],
        ], JSON_THROW_ON_ERROR);

        $handler = $this->handler($hubRepo, libraryShares: $shares);
        $handler->handleBinaryFrame('leaf-hub-1', $payload, 5);
    }

    /**
     * M-5: master-pushed share frames carry peer_id = master's own hub id
     * (leaf needs it to rebase; handleIncomingOffer rejects offers without it).
     */
    public function testHelloAckPushIncludesOriginPeerIdInSharePayload(): void
    {
        $hubRepo = $this->createMock(FederationHubRepository::class);
        $hubRepo->method('getPeerByPublicKey')->willReturn($this->peerRow('pending', ''));
        $hubRepo->method('getHubConfig')->willReturn(['id' => 'master-hub-uuid']);

        $sessions = $this->createMock(FederationSessionManager::class);
        $sessions->method('registerSession')->willReturn('sess-1');

        $shares = $this->createMock(FederationLibraryShareRepository::class);
        $shares->method('getActiveOutgoingSharesForPeer')->willReturnCallback(
            static fn (string $peerId): array => $peerId === 'peer-1'
                ? [
                    [
                        'id' => 'share-1',
                        'peer_id' => 'LOCAL-LEAF-ROW-ID',
                        'library_id' => 'lib-1',
                        'library_name' => 'Movies',
                        'permission' => 'read',
                        'status' => 'active',
                    ],
                ]
                : [],
        );

        $conn = $this->createMock(ConnectionInterface::class);
        $binaryPush = '';
        $conn->method('send')->willReturnCallback(
            static function (string $data) use (&$binaryPush): void {
                if (!str_starts_with($data, '{')) {
                    $binaryPush = $data;
                }
            },
        );
        $this->realConnMgr->addConnection('leaf-hub-1', $conn);

        $handler = $this->handler($hubRepo, $sessions, $shares);
        self::assertNull($handler->handleTextFrame('leaf-hub-1', $this->helloJson()));

        // The captured push is typed `string` from the sentinel onward — no
        // nullable narrowing dance needed; empty means "never sent".
        self::assertNotSame('', $binaryPush, 'A binary DATA frame must be pushed');
        // Frame payload sits behind the wire header; search the raw bytes for
        // the JSON identity rather than decoding the full frame here.
        self::assertStringContainsString('master-hub-uuid', $binaryPush);
        self::assertStringNotContainsString(
            'LOCAL-LEAF-ROW-ID',
            $binaryPush,
            'The master must not leak its local row ids as the wire peer_id',
        );
    }

    /**
     * Multi-leaf misdelivery regression: an active share targeted at peer A
     * must never be pushed to leaf B on B's hello. The repo is consulted with
     * the connecting peer's LOCAL row id, so B's query returns nothing.
     */
    public function testHelloPushNeverDeliversAnotherPeersShares(): void
    {
        $peerB = [
            'id' => 'peer-2',
            'name' => 'Leaf B',
            'url' => 'https://b.example.com',
            'status' => 'pending',
            'leaf_hub_id' => 'leaf-hub-2',
        ];

        $hubRepo = $this->createMock(FederationHubRepository::class);
        $hubRepo->method('getPeerByPublicKey')->willReturn($peerB);
        $hubRepo->method('getHubConfig')->willReturn(['id' => 'master-hub-uuid']);

        $sessions = $this->createMock(FederationSessionManager::class);
        $sessions->method('registerSession')->willReturn('sess-2');

        // share-for-A is the active row targeted at peer-1 ('peer-A' scope):
        $shares = $this->createMock(FederationLibraryShareRepository::class);
        $shares->expects(self::once())
            ->method('getActiveOutgoingSharesForPeer')
            ->with('peer-2')
            ->willReturn([]);

        $connB = $this->createMock(ConnectionInterface::class);
        $binaryToB = '';
        $connB->method('send')->willReturnCallback(
            static function (string $data) use (&$binaryToB): void {
                if (!str_starts_with($data, '{')) {
                    $binaryToB = $data;
                }
            },
        );
        $this->realConnMgr->addConnection('leaf-hub-2', $connB);

        $handler = $this->handler($hubRepo, $sessions, $shares);
        $helloB = '{"type":"hub_hello","public_key":"valid_key","hub_id":"leaf-hub-2","hub_name":"Leaf B"}';

        self::assertNull($handler->handleTextFrame('leaf-hub-2', $helloB));
        self::assertSame('', $binaryToB, 'Leaf B must receive no share DATA frame');
        self::assertStringNotContainsString('share-for-A', $binaryToB);
    }

    public function testHandleTextFrameHubHelloAckIsNoOpOnMaster(): void
    {
        $hubRepo = $this->createMock(FederationHubRepository::class);
        $hubRepo->expects(self::never())->method('getPeerByPublicKey');

        $handler = $this->handler($hubRepo);
        $result = $handler->handleTextFrame('hub-1', '{"type":"hub_hello_ack","session_id":"sess-1"}');

        // Master hub ignores HELLO_ACK from other hubs
        self::assertNull($result);
    }

    // ------------------------------------------------------ handleBinaryFrame

    public function testHandleBinaryFrameIgnoresUnknownFrameType(): void
    {
        $handler = $this->handler();
        // Invalid frame type 9999 should be ignored
        $handler->handleBinaryFrame('hub-1', 'payload', 9999);
        // No exception means success - we're just verifying it doesn't crash
        self::addToAssertionCount(1);
    }

    public function testHandleBinaryFrameDisconnectedIgnoresWhenNoConnection(): void
    {
        $connMgr = new FederationConnectionManager();
        $connMgr->removeConnection('non-existent'); // just to ensure it's clean

        $handler = new FederationFrameHandler(
            $this->createMock(FederationHubRepository::class),
            $this->createMock(FederationSessionManager::class),
            $this->createMock(FederationLibraryShareRepository::class),
            $connMgr,
            $this->createMock(AuditLogger::class)
        );
        // Should not throw - no connection to close
        $handler->handleBinaryFrame('hub-1', '', 7); // DISCONNECTED frame type value
        self::addToAssertionCount(1);
    }
}
