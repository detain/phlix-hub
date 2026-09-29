<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Federation;

use Phlix\Hub\Federation\FederationFrameHandler;
use Phlix\Hub\Federation\FederationHandshake;
use Phlix\Hub\Federation\FederationHubRepository;
use Phlix\Hub\Federation\FederationLibraryShareRepository;
use Phlix\Hub\Federation\FederationSessionManager;
use Phlix\Hub\Federation\FederationConnectionManager;
use Phlix\Hub\Hub\Ed25519KeyManager;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Tests\Support\FederationFrameAssertions;
use PHPUnit\Framework\TestCase;
use Workerman\Connection\ConnectionInterface;

/**
 * Unit tests for {@see FederationFrameHandler}.
 *
 * H-4: the handshake is a mutual Ed25519 proof-of-key ceremony. These tests
 * run it on REAL sodium keypairs (throwaway temp key files) so the wire
 * canonicals, signatures and the verified-channel gate are exercised exactly
 * as production executes them.
 *
 * @package Phlix\Hub\Tests\Unit\Federation
 */
final class FederationFrameHandlerTest extends TestCase
{
    use FederationFrameAssertions;

    private FederationConnectionManager $realConnMgr;

    private ?string $loggerTmp = null;

    private ?string $keyDir = null;

    private Ed25519KeyManager $masterKeyManager;

    /**
     * spl_object_id(ConnectionInterface mock) => list<string> of sent frames.
     *
     * @var array<int, list<string>>
     */
    private array $sentFrames = [];

    protected function setUp(): void
    {
        parent::setUp();
        // FederationConnectionManager is final, so we use a real instance
        $this->realConnMgr = new FederationConnectionManager();
        $this->sentFrames = [];

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

        // Real (temporary) master keypair so HELLO_ACK signing is genuine.
        $this->keyDir = sys_get_temp_dir() . '/phlix-hub-fed-keys-' . bin2hex(random_bytes(6));
        mkdir($this->keyDir, 0700, true);
        $this->masterKeyManager = new Ed25519KeyManager($this->keyDir . '/master-ed25519.pem');
    }

    protected function tearDown(): void
    {
        LoggerFactory::reset();
        if ($this->loggerTmp !== null && is_file($this->loggerTmp . '/logger.php')) {
            unlink($this->loggerTmp . '/logger.php');
            rmdir($this->loggerTmp);
        }
        $this->loggerTmp = null;
        if ($this->keyDir !== null) {
            foreach (glob($this->keyDir . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            @rmdir($this->keyDir);
        }
        $this->keyDir = null;
        parent::tearDown();
    }

    private function handler(
        ?FederationHubRepository $hubRepo = null,
        ?FederationSessionManager $sessions = null,
        ?FederationLibraryShareRepository $libraryShares = null,
        ?AuditLogger $audit = null,
        ?Ed25519KeyManager $keyManager = null,
    ): FederationFrameHandler {
        return new FederationFrameHandler(
            $hubRepo ?? $this->createMock(FederationHubRepository::class),
            $sessions ?? $this->createMock(FederationSessionManager::class),
            $libraryShares ?? $this->createMock(FederationLibraryShareRepository::class),
            $this->realConnMgr,
            $audit ?? $this->createMock(AuditLogger::class),
            $keyManager ?? $this->masterKeyManager,
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
     *
     * H-4: acceptance at HELLO now means only "session registered, signed ACK
     * sent" — shares and the connect-audit are deferred to HELLO_AUTH.
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

        $conn = $this->recordingConnection();
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
            'public_key' => 'valid_key',
        ];
    }

    private function helloJson(): string
    {
        return '{"type":"hub_hello","public_key":"valid_key","hub_id":"leaf-hub-1","hub_name":"Leaf"}';
    }

    // ------------------------------------------ recording-connection plumbing

    /**
     * Connection mock that records every send() into $this->sentFrames.
     */
    private function recordingConnection(): ConnectionInterface
    {
        $conn = $this->createMock(ConnectionInterface::class);
        $id = spl_object_id($conn);
        $this->sentFrames[$id] = [];
        $conn->method('send')->willReturnCallback(
            function (string $data) use ($id): void {
                $this->sentFrames[$id][] = $data;
            },
        );
        $conn->method('close');

        return $conn;
    }

    /**
     * @return list<string>
     */
    private function textFramesOf(ConnectionInterface $conn): array
    {
        return array_values(array_filter(
            $this->sentFrames[spl_object_id($conn)] ?? [],
            static fn (string $f): bool => str_starts_with($f, '{'),
        ));
    }

    /**
     * @return list<string>
     */
    private function binaryFramesOf(ConnectionInterface $conn): array
    {
        return array_values(array_filter(
            $this->sentFrames[spl_object_id($conn)] ?? [],
            static fn (string $f): bool => !str_starts_with($f, '{'),
        ));
    }

    /**
     * Last HELLO_ACK JSON the connection received, decoded.
     *
     * @return array<string, mixed>
     */
    private function lastAckOf(ConnectionInterface $conn): array
    {
        foreach (array_reverse($this->textFramesOf($conn)) as $frame) {
            if (str_contains($frame, 'hub_hello_ack')) {
                /** @var array<string, mixed> $decoded */
                $decoded = json_decode($frame, true, 4, JSON_THROW_ON_ERROR);
                return $decoded;
            }
        }
        self::fail('No HELLO_ACK frame was sent on this connection');
    }

    private function expectHelloAcceptedWithPeerStatus(string $status): void
    {
        $hubRepo = $this->createMock(FederationHubRepository::class);
        $hubRepo->method('getPeerByPublicKey')->willReturn($this->peerRow($status));
        $hubRepo->method('getHubConfig')->willReturn(['id' => 'master-hub-1']);
        $hubRepo->expects(self::once())->method('updatePeerStatus')->with('peer-1', 'connected');

        $sessions = $this->createMock(FederationSessionManager::class);
        $sessions->method('registerSession')->willReturn('session-uuid-1');
        $sessions->expects(self::once())->method('beginHandshake');

        $shares = $this->createMock(FederationLibraryShareRepository::class);
        $shares->expects(self::never())->method('getActiveOutgoingSharesForPeer');

        $audit = $this->createMock(AuditLogger::class);
        $audit->expects(self::never())->method('logHubConnect');

        $conn = $this->recordingConnection();
        $this->realConnMgr->addConnection('leaf-hub-1', $conn);

        $handler = $this->handler($hubRepo, $sessions, $shares, $audit);
        $result = $handler->handleTextFrame('leaf-hub-1', $this->helloJson());

        self::assertNull($result, "HELLO from a '{$status}' peer must be accepted");

        $ack = $this->lastAckOf($conn);
        self::assertSame('session-uuid-1', $ack['session_id']);
        self::assertSame('master-hub-1', $ack['master_hub_id']);
        self::assertIsString($ack['nonce'] ?? null);
        self::assertNotSame('', $ack['nonce'], 'ACK must carry the fresh handshake nonce');
        self::assertIsString($ack['signature'] ?? null);
        self::assertNotSame('', $ack['signature'], 'ACK must carry the master signature (H-4)');

        // The ACK signature must verify against THIS hub's registered key.
        $masterPub = $this->masterKeyManager->getOrCreateKeyPair()['public'];
        self::assertTrue(FederationHandshake::verify(
            FederationHandshake::helloAckCanonical(
                'session-uuid-1',
                'master-hub-1',
                self::frameStringField($ack, 'nonce'),
            ),
            self::frameStringField($ack, 'signature'),
            base64_encode($masterPub),
        ));

        self::assertFalse(
            $this->realConnMgr->isVerified('leaf-hub-1'),
            'HELLO alone must not verify the channel (H-4)',
        );
        self::assertSame([], $this->binaryFramesOf($conn), 'Nothing binary may be pushed before HELLO_AUTH');
    }

    // ------------------------------------------------- H-4 HELLO_AUTH ceremony

    /**
     * Session-mock backing store replicating beginHandshake/consumeHandshake
     * semantics (consume is single-use, exactly like the real manager).
     *
     * @param array<string, array{peer_id:string,nonce:string}> $pending
     */
    private function sessionWithHandshakeState(array &$pending): FederationSessionManager
    {
        $sessions = $this->createMock(FederationSessionManager::class);
        $sessions->method('registerSession')->willReturnCallback(
            static function (string $peerId) use (&$pending): string {
                foreach ($pending as $sid => $entry) {
                    if ($entry['peer_id'] === $peerId) {
                        unset($pending[$sid]); // abandonHandshakesForPeer
                    }
                }
                return 'sess-' . $peerId;
            },
        );
        $sessions->method('beginHandshake')->willReturnCallback(
            static function (string $sid, string $pid, string $nonce) use (&$pending): void {
                foreach ($pending as $existingSid => $entry) {
                    if ($entry['peer_id'] === $pid) {
                        unset($pending[$existingSid]);
                    }
                }
                $pending[$sid] = ['peer_id' => $pid, 'nonce' => $nonce];
            },
        );
        $sessions->method('consumeHandshake')->willReturnCallback(
            static function (string $sid) use (&$pending): ?array {
                $entry = $pending[$sid] ?? null;
                unset($pending[$sid]); // single-use burn (replay-proof)
                return $entry;
            },
        );

        return $sessions;
    }

    /**
     * @param array<string, mixed>                              $peer
     * @param array<string, array{peer_id:string,nonce:string}> $pending
     */
    private function handlerForPeer(
        array $peer,
        array &$pending,
        ?FederationLibraryShareRepository $shares = null,
        ?AuditLogger $audit = null,
    ): FederationFrameHandler {
        $hubRepo = $this->createMock(FederationHubRepository::class);
        $hubRepo->method('getPeerByPublicKey')->willReturn($peer);
        $hubRepo->method('getPeerById')->willReturn($peer);
        $hubRepo->method('getHubConfig')->willReturn(['id' => 'master-hub-1']);
        $hubRepo->method('setPeerLeafHubId');
        $hubRepo->method('updatePeerStatus');

        return $this->handler(
            $hubRepo,
            $this->sessionWithHandshakeState($pending),
            $shares,
            $audit,
        );
    }

    /**
     * @param array<string, mixed> $peer
     */
    private function helloText(array $peer): string
    {
        return json_encode([
            'type' => 'hub_hello',
            'public_key' => $peer['public_key'],
            'hub_id' => $peer['leaf_hub_id'],
            'hub_name' => 'Leaf',
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * A HELLO_AUTH text frame signed by $secret64 over the canonical string.
     */
    private function helloAuthJson(string $sessionId, string $nonce, string $leafHubId, string $secret64): string
    {
        return json_encode([
            'type' => 'hub_hello_auth',
            'leaf_hub_id' => $leafHubId,
            'session_id' => $sessionId,
            'signature' => FederationHandshake::sign(
                FederationHandshake::helloAuthCanonical($sessionId, $nonce, $leafHubId),
                $secret64,
            ),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Peer row whose registered public key is $publicKey32 (raw), encoded b64
     * exactly as an operator would store it.
     *
     * @return array<string, mixed>
     */
    private function ceremonyPeer(string $publicKey32): array
    {
        return [
            'id' => 'peer-1',
            'name' => 'Test Peer',
            'url' => 'https://peer.example.com',
            'status' => 'pending',
            'leaf_hub_id' => 'leaf-hub-1',
            'public_key' => base64_encode($publicKey32),
        ];
    }

    /**
     * Full happy-path ceremony on REAL keys: HELLO → signed ACK → HELLO_AUTH →
     * channel VERIFIED, deferred share push fires, connect audited true.
     */
    public function testCeremonyHappyPathVerifiesChannelAndPushesShares(): void
    {
        $leafKp = sodium_crypto_sign_keypair();
        $peer = $this->ceremonyPeer(sodium_crypto_sign_publickey($leafKp));

        /** @var array<string, array{peer_id:string,nonce:string}> $pending */
        $pending = [];

        $shares = $this->createMock(FederationLibraryShareRepository::class);
        $shares->expects(self::once())->method('getActiveOutgoingSharesForPeer')->with('peer-1')->willReturn([[
            'id' => 'share-1',
            'peer_id' => 'LOCAL-MASTER-ROW-ID',
            'library_id' => 'lib-1',
            'library_name' => 'Movies',
            'permission' => 'read',
            'status' => 'active',
        ]]);

        $audit = $this->createMock(AuditLogger::class);
        $audit->expects(self::once())->method('logHubConnect')
            ->with('peer-1', 'Test Peer', 'https://peer.example.com', true);
        $audit->expects(self::never())->method('logFailedAuth');

        $conn = $this->recordingConnection();
        $this->realConnMgr->addConnection('leaf-hub-1', $conn);

        $handler = $this->handlerForPeer($peer, $pending, $shares, $audit);

        // Leg 1: HELLO — accepted, but NOTHING sensitive happens yet.
        self::assertNull($handler->handleTextFrame('leaf-hub-1', $this->helloText($peer)));
        self::assertFalse($this->realConnMgr->isVerified('leaf-hub-1'));
        self::assertSame([], $this->binaryFramesOf($conn));

        // Leg 2: HELLO_AUTH with the registered key — the full flip.
        $ack = $this->lastAckOf($conn);
        $auth = $this->helloAuthJson(
            self::frameStringField($ack, 'session_id'),
            self::frameStringField($ack, 'nonce'),
            'leaf-hub-1',
            sodium_crypto_sign_secretkey($leafKp),
        );
        self::assertNull($handler->handleTextFrame('leaf-hub-1', $auth));

        self::assertTrue($this->realConnMgr->isVerified('leaf-hub-1'), 'Ceremony must verify the channel');

        $pushed = $this->binaryFramesOf($conn);
        self::assertCount(1, $pushed, 'The deferred share push must fire exactly once');
        self::assertStringContainsString('master-hub-1', $pushed[0]);
        self::assertStringContainsString('share-1', $pushed[0]);
        self::assertStringNotContainsString(
            'LOCAL-MASTER-ROW-ID',
            $pushed[0],
            'The master must not leak its local row ids as the wire peer_id',
        );
    }

    /**
     * Multi-leaf misdelivery regression: an active share targeted at peer A
     * must never be pushed to leaf B. After B's ceremony completes, the repo
     * is consulted with B's LOCAL row id and returns nothing.
     */
    public function testHelloPushNeverDeliversAnotherPeersShares(): void
    {
        $leafBKp = sodium_crypto_sign_keypair();
        $peerB = [
            'id' => 'peer-2',
            'name' => 'Leaf B',
            'url' => 'https://b.example.com',
            'status' => 'pending',
            'leaf_hub_id' => 'leaf-hub-2',
            'public_key' => base64_encode(sodium_crypto_sign_publickey($leafBKp)),
        ];

        /** @var array<string, array{peer_id:string,nonce:string}> $pending */
        $pending = [];

        $shares = $this->createMock(FederationLibraryShareRepository::class);
        $shares->expects(self::once())
            ->method('getActiveOutgoingSharesForPeer')
            ->with('peer-2')
            ->willReturn([]);

        $connB = $this->recordingConnection();
        $this->realConnMgr->addConnection('leaf-hub-2', $connB);

        $handler = $this->handlerForPeer($peerB, $pending, $shares);
        self::assertNull($handler->handleTextFrame('leaf-hub-2', $this->helloText($peerB)));
        self::assertSame([], $this->binaryFramesOf($connB), 'No push before verification (H-4)');

        $ack = $this->lastAckOf($connB);
        self::assertNull($handler->handleTextFrame('leaf-hub-2', $this->helloAuthJson(
            self::frameStringField($ack, 'session_id'),
            self::frameStringField($ack, 'nonce'),
            'leaf-hub-2',
            sodium_crypto_sign_secretkey($leafBKp),
        )));

        self::assertSame([], $this->binaryFramesOf($connB), 'Leaf B must receive no share DATA frame');
    }

    /**
     * H-4: a HELLO_AUTH signed by a key the peer row does NOT vouch for is
     * knowledge-only impersonation — refused, audited false, never verified.
     */
    public function testHelloAuthFromWrongKeyIsRefused(): void
    {
        $registered = sodium_crypto_sign_keypair();
        $attacker = sodium_crypto_sign_keypair();
        $peer = $this->ceremonyPeer(sodium_crypto_sign_publickey($registered));

        /** @var array<string, array{peer_id:string,nonce:string}> $pending */
        $pending = [];

        $audit = $this->createMock(AuditLogger::class);
        $audit->expects(self::once())->method('logHubConnect')
            ->with('peer-1', 'Test Peer', 'https://peer.example.com', false, 'handshake_proof_failed');
        $audit->expects(self::once())->method('logFailedAuth')
            ->with('federation_handshake_handshake_proof_failed', self::anything());

        $conn = $this->recordingConnection();
        $this->realConnMgr->addConnection('leaf-hub-1', $conn);

        $handler = $this->handlerForPeer($peer, $pending, audit: $audit);
        self::assertNull($handler->handleTextFrame('leaf-hub-1', $this->helloText($peer)));

        $ack = $this->lastAckOf($conn);
        $result = $handler->handleTextFrame('leaf-hub-1', $this->helloAuthJson(
            self::frameStringField($ack, 'session_id'),
            self::frameStringField($ack, 'nonce'),
            'leaf-hub-1',
            sodium_crypto_sign_secretkey($attacker),
        ));

        self::assertSame('HELLO_AUTH verification failed', $result);
        self::assertFalse($this->realConnMgr->isVerified('leaf-hub-1'));
    }

    /**
     * H-4: the nonce is single-use — a REPLAYED, otherwise-valid HELLO_AUTH
     * finds its handshake already burned and is refused.
     */
    public function testHelloAuthReplayIsRefused(): void
    {
        $leafKp = sodium_crypto_sign_keypair();
        $peer = $this->ceremonyPeer(sodium_crypto_sign_publickey($leafKp));

        /** @var array<string, array{peer_id:string,nonce:string}> $pending */
        $pending = [];

        $conn = $this->recordingConnection();
        $this->realConnMgr->addConnection('leaf-hub-1', $conn);

        $handler = $this->handlerForPeer($peer, $pending);
        self::assertNull($handler->handleTextFrame('leaf-hub-1', $this->helloText($peer)));

        $ack = $this->lastAckOf($conn);
        $auth = $this->helloAuthJson(
            self::frameStringField($ack, 'session_id'),
            self::frameStringField($ack, 'nonce'),
            'leaf-hub-1',
            sodium_crypto_sign_secretkey($leafKp),
        );

        self::assertNull($handler->handleTextFrame('leaf-hub-1', $auth), 'First proof accepted');
        self::assertTrue($this->realConnMgr->isVerified('leaf-hub-1'));

        $replayRoute = 'leaf-hub-1';
        $replayProof = $auth;
        $again = $handler->handleTextFrame($replayRoute, $replayProof);
        self::assertIsString($again);
        self::assertStringContainsString('no pending handshake', $again);
    }

    /**
     * H-4: a HELLO_AUTH for a session the master never issued (or whose nonce
     * was abandoned) is refused BEFORE any crypto — nothing to verify against.
     */
    public function testHelloAuthWithUnknownSessionIsRefused(): void
    {
        $peer = $this->ceremonyPeer(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));

        /** @var array<string, array{peer_id:string,nonce:string}> $pending */
        $pending = [];

        $audit = $this->createMock(AuditLogger::class);
        $audit->expects(self::once())->method('logHubConnect')
            ->with('peer-1', 'Test Peer', 'https://peer.example.com', false, 'handshake_unknown_or_replayed');

        $conn = $this->recordingConnection();
        $this->realConnMgr->addConnection('leaf-hub-1', $conn);

        $handler = $this->handlerForPeer($peer, $pending, audit: $audit);

        $forged = json_encode([
            'type' => 'hub_hello_auth',
            'leaf_hub_id' => 'leaf-hub-1',
            'session_id' => 'sess-forged',
            'signature' => 'AAAA',
        ], JSON_THROW_ON_ERROR);

        $result = $handler->handleTextFrame('leaf-hub-1', $forged);
        self::assertIsString($result);
        self::assertStringContainsString('no pending handshake', (string) $result);
    }

    /**
     * H-4: the proof must vouch for the BOUND identity — a valid signature
     * over a foreign leaf_hub_id is refused.
     */
    public function testHelloAuthIdentityMismatchIsRefused(): void
    {
        $leafKp = sodium_crypto_sign_keypair();
        $peer = $this->ceremonyPeer(sodium_crypto_sign_publickey($leafKp));

        /** @var array<string, array{peer_id:string,nonce:string}> $pending */
        $pending = [];

        $conn = $this->recordingConnection();
        $this->realConnMgr->addConnection('leaf-hub-1', $conn);

        $audit = $this->createMock(AuditLogger::class);
        $audit->expects(self::once())->method('logHubConnect')
            ->with('peer-1', 'Test Peer', 'https://peer.example.com', false, 'handshake_identity_mismatch');

        $handler = $this->handlerForPeer($peer, $pending, audit: $audit);
        self::assertNull($handler->handleTextFrame('leaf-hub-1', $this->helloText($peer)));

        $ack = $this->lastAckOf($conn);
        $result = $handler->handleTextFrame('leaf-hub-1', $this->helloAuthJson(
            self::frameStringField($ack, 'session_id'),
            self::frameStringField($ack, 'nonce'),
            'IMPOSTOR-hub',
            sodium_crypto_sign_secretkey($leafKp),
        ));

        self::assertIsString($result);
        self::assertStringContainsString('does not match the bound identity', (string) $result);
        self::assertFalse($this->realConnMgr->isVerified('leaf-hub-1'));
    }

    /**
     * H-4: malformed HELLO_AUTH payloads are refused without touching state.
     */
    public function testHelloAuthRejectsMissingFields(): void
    {
        $handler = $this->handler();

        foreach (
            [
            '{"type":"hub_hello_auth"}',
            '{"type":"hub_hello_auth","session_id":"s"}',
            '{"type":"hub_hello_auth","session_id":"s","signature":"x"}',
            '{"type":"hub_hello_auth","session_id":"","signature":"x","leaf_hub_id":"y"}',
            ] as $bad
        ) {
            self::assertSame('Invalid hello_auth payload', $handler->handleTextFrame('leaf-hub-1', $bad));
        }
    }

    /**
     * H-4 unverified-channel gate (master side): DATA payloads from a socket
     * that never completed the ceremony are dropped and audited — offers
     * never reach the repository.
     */
    public function testDataFrameIsDroppedBeforeVerification(): void
    {
        $conn = $this->recordingConnection();
        $this->realConnMgr->addConnection('leaf-hub-1', $conn);

        $hubRepo = $this->createMock(FederationHubRepository::class);
        $hubRepo->expects(self::never())->method('getPeerById');

        $shares = $this->createMock(FederationLibraryShareRepository::class);
        $shares->expects(self::never())->method('handleIncomingOffer');

        $audit = $this->createMock(AuditLogger::class);
        $audit->expects(self::once())->method('logFailedAuth')
            ->with('federation_data_frame_before_verification', self::anything());

        $handler = $this->handler($hubRepo, libraryShares: $shares, audit: $audit);
        $payload = json_encode(
            ['shares' => [['id' => 'offer-1', 'peer_id' => 'leaf-hub-1', 'library_id' => 'l']]],
            JSON_THROW_ON_ERROR,
        );
        $handler->handleBinaryFrame('leaf-hub-1', $payload, 5); // DATA, channel NOT verified

        // Same payload IS processed once the channel is verified — the gate is
        // the only difference between drop and process.
        $this->realConnMgr->markVerified('leaf-hub-1', $conn);
        $hubRepo2 = $this->createMock(FederationHubRepository::class);
        $hubRepo2->method('getPeerById')->willReturn($this->peerRow('connected'));
        $shares2 = $this->createMock(FederationLibraryShareRepository::class);
        $shares2->expects(self::once())->method('handleIncomingOffer');
        $this->handler($hubRepo2, libraryShares: $shares2)->handleBinaryFrame('leaf-hub-1', $payload, 5);
    }

    /**
     * H-4: the verified stamp is bound to the connection object — a socket
     * replaced under the same hub id starts unverified again.
     */
    public function testReplacedConnectionStartsUnverified(): void
    {
        $old = $this->recordingConnection();
        $this->realConnMgr->addConnection('leaf-hub-1', $old);
        self::assertTrue($this->realConnMgr->markVerified('leaf-hub-1', $old));
        self::assertTrue($this->realConnMgr->isVerified('leaf-hub-1'));

        $new = $this->recordingConnection();
        $this->realConnMgr->addConnection('leaf-hub-1', $new);

        self::assertFalse($this->realConnMgr->isVerified('leaf-hub-1'));
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
     * (H-4: the channel is verified first — here via the markVerified seam;
     * the full ceremony is covered by the tests above.)
     */
    public function testDataFrameUpsertsOfferWithLocalPeerFk(): void
    {
        $conn = $this->createMock(ConnectionInterface::class);
        $this->realConnMgr->addConnection('leaf-hub-1', $conn);
        $this->realConnMgr->markVerified('leaf-hub-1', $conn);

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
        $this->realConnMgr->markVerified('leaf-hub-1', $conn);

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
            $this->createMock(AuditLogger::class),
            $this->masterKeyManager,
        );
        // Should not throw - no connection to close
        $handler->handleBinaryFrame('hub-1', '', 7); // DISCONNECTED frame type value
        self::addToAssertionCount(1);
    }
}
