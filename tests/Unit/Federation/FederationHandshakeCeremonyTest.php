<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Federation;

use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Federation\FederationAdminDelegationRepository;
use Phlix\Hub\Federation\FederationConnectionManager;
use Phlix\Hub\Federation\FederationFrameHandler;
use Phlix\Hub\Federation\FederationHubRepository;
use Phlix\Hub\Federation\FederationLibraryShareRepository;
use Phlix\Hub\Federation\FederationPeerManager;
use Phlix\Hub\Federation\FederationSessionManager;
use Phlix\Hub\Hub\Ed25519KeyManager;
use Phlix\Hub\Tests\Support\WorkermanTimerRuntimeControl;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Connection\ConnectionInterface;

/**
 * End-to-end H-4 ceremony test in one process: the MASTER's real
 * FederationFrameHandler emits a signed HELLO_ACK, the LEAF's real
 * FederationPeerManager verifies it and answers with a real HELLO_AUTH built
 * from its own keypair, and the master verifies that proof and flips the
 * channel to VERIFIED.
 *
 * Every byte crosses between the two components exactly as it would on the
 * wire — this is the guard that the two ends of the estate can never drift
 * their canonical strings: any signing/verification disagreement lands here
 * red before it can ship.
 *
 * @package Phlix\Hub\Tests\Unit\Federation
 */
final class FederationHandshakeCeremonyTest extends TestCase
{
    use WorkermanTimerRuntimeControl;

    private ?string $loggerTmp = null;

    private ?string $keyDir = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loggerTmp = sys_get_temp_dir() . '/phlix-hub-fed-ceremony-' . bin2hex(random_bytes(6));
        mkdir($this->loggerTmp, 0700, true);
        file_put_contents(
            $this->loggerTmp . '/logger.php',
            "<?php return ['default' => 'mem', 'handlers' => ['mem' => "
            . "['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug']]];",
        );
        LoggerFactory::reset();
        LoggerFactory::init($this->loggerTmp . '/logger.php');

        $this->keyDir = sys_get_temp_dir() . '/phlix-hub-fed-ceremony-keys-' . bin2hex(random_bytes(6));
        mkdir($this->keyDir, 0700, true);

        $this->forceWorkermanRuntime();
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

    public function testCrossRoleCeremonyVerifiesBothWaysOnRealKeys(): void
    {
        // ---- Real keypairs for both hubs (Ed25519KeyManager = production path)
        $masterKeys = new Ed25519KeyManager($this->keyDir . '/master.pem');
        $leafKeys = new Ed25519KeyManager($this->keyDir . '/leaf.pem');
        $masterPubB64 = base64_encode($masterKeys->getOrCreateKeyPair()['public']);
        $leafPubB64 = base64_encode($leafKeys->getOrCreateKeyPair()['public']);

        // ---- Master-side world ------------------------------------------------
        $connMgr = new FederationConnectionManager();
        $leafSocket = $this->createMock(ConnectionInterface::class);
        $masterSent = [];
        $leafSocket->method('send')->willReturnCallback(
            static function (string $data) use (&$masterSent): void {
                $masterSent[] = $data;
            },
        );
        $connMgr->addConnection('leaf-hub-1', $leafSocket);

        $masterHubRepo = $this->createMock(FederationHubRepository::class);
        $masterHubRepo->method('getPeerByPublicKey')->with($leafPubB64)->willReturn([
            'id' => 'peer-leaf-1',
            'name' => 'Leaf One',
            'url' => 'https://leaf.example.com',
            'status' => 'pending',
            'leaf_hub_id' => 'leaf-hub-1',
            'public_key' => $leafPubB64,
        ]);
        $masterHubRepo->method('getPeerById')->willReturn([
            'id' => 'peer-leaf-1',
            'name' => 'Leaf One',
            'url' => 'https://leaf.example.com',
            'status' => 'pending',
            'leaf_hub_id' => 'leaf-hub-1',
            'public_key' => $leafPubB64,
        ]);
        $masterHubRepo->method('getHubConfig')->willReturn(['id' => 'master-hub-1']);

        /** @var array<string, array{peer_id:string,nonce:string}> $pending */
        $pending = [];
        $masterSessions = $this->handshakeBackedSessions($pending);

        // Leg 3 audits the successful connect (true); the deliberate replay in
        // the negative cross-check audits a refusal (false) — both allowed.
        $masterAudit = $this->createMock(AuditLogger::class);
        $masterAudit->expects(self::exactly(2))->method('logHubConnect')
            ->with('peer-leaf-1', 'Leaf One', 'https://leaf.example.com', self::logicalOr(true, false));

        $masterShares = $this->createMock(FederationLibraryShareRepository::class);
        $masterShares->method('getActiveOutgoingSharesForPeer')->with('peer-leaf-1')->willReturn([]);

        $frameHandler = new FederationFrameHandler(
            $masterHubRepo,
            $masterSessions,
            $masterShares,
            $connMgr,
            $masterAudit,
            $masterKeys,
        );

        // ---- Leaf-side world ---------------------------------------------------
        $leafHubRepo = $this->createMock(FederationHubRepository::class);
        $leafHubRepo->method('getHubConfig')->willReturn(['id' => 'leaf-hub-1']);
        $leafHubRepo->method('getPeerById')->with('peer-master-1')->willReturn([
            'id' => 'peer-master-1',
            'name' => 'Master One',
            'url' => 'https://master.example.com',
            'status' => 'connected',
            'leaf_hub_id' => 'master-hub-1', // bound identity of the master row
            'public_key' => $masterPubB64,
        ]);

        $leafSessions = $this->createMock(FederationSessionManager::class);
        $leafSessions->method('registerSession')->willReturn('local-mirror-sess');

        $leafAudit = $this->createMock(AuditLogger::class);
        $leafAudit->expects(self::once())->method('logHubConnect')
            ->with('leaf-hub-1', 'Master One', 'master-hub-1', true);

        $peerManager = new FederationPeerManager(
            $leafHubRepo,
            $leafSessions,
            $this->createMock(FederationLibraryShareRepository::class),
            $this->createMock(FederationAdminDelegationRepository::class),
            $leafAudit,
            $leafKeys,
        );

        // The leaf dials; stand up its socket + dialed-peer state.
        $leafOutbound = [];
        $dial = $this->createMock(AsyncTcpConnection::class);
        $dial->method('send')->willReturnCallback(
            static function (string $data) use (&$leafOutbound): bool {
                $leafOutbound[] = $data;
                return true;
            },
        );
        (new ReflectionProperty($peerManager, 'masterConnection'))->setValue($peerManager, $dial);
        (new ReflectionProperty($peerManager, 'masterPeerId'))->setValue($peerManager, 'peer-master-1');

        // ---- Leg 1: leaf sends HELLO, master answers with a SIGNED ACK ---------
        $hello = json_encode([
            'type' => 'hub_hello',
            'hub_id' => 'leaf-hub-1',
            'hub_name' => 'Leaf One',
            'public_key' => $leafPubB64,
            'role' => 'leaf',
        ], JSON_THROW_ON_ERROR);
        self::assertNull($frameHandler->handleTextFrame('leaf-hub-1', $hello, $leafSocket));
        self::assertFalse($connMgr->isVerified('leaf-hub-1'));

        $ackJson = $this->lastTextFrame($masterSent, 'hub_hello_ack');
        self::assertNotNull($ackJson, 'Master must answer HELLO with an ACK');

        // ---- Leg 2: leaf verifies the REAL ack, binds, and proves itself -------
        /** @var array<string, mixed> $ack */
        $ack = json_decode((string) $ackJson, true, 4, JSON_THROW_ON_ERROR);
        $this->invokeProtected($peerManager, 'handleHelloAck', [$ack]);

        $leafSession = $this->readProtected($peerManager, 'sessionId');
        self::assertIsString($leafSession);
        self::assertSame('master-sess', $leafSession);
        self::assertTrue((bool) $this->readProtected($peerManager, 'channelVerified'));

        $authJson = $this->lastTextFrame($leafOutbound, 'hub_hello_auth');
        self::assertNotNull($authJson, 'Leaf must answer a verified ACK with HELLO_AUTH');

        // ---- Leg 3: master verifies the REAL proof and flips to VERIFIED -------
        self::assertNull($frameHandler->handleTextFrame('leaf-hub-1', (string) $authJson, $leafSocket));
        self::assertTrue($connMgr->isVerified('leaf-hub-1'), 'Channel must be VERIFIED after the ceremony');

        // ---- Negative cross-check: replayed proof is refused -------------------
        $replayRoute = 'leaf-hub-1';
        $replayProof = (string) $authJson;
        $replay = $frameHandler->handleTextFrame($replayRoute, $replayProof, $leafSocket);
        self::assertIsString($replay, 'A replayed HELLO_AUTH must be refused');
    }

    /**
     * @param array<string, array{peer_id:string,nonce:string}> $pending
     */
    private function handshakeBackedSessions(array &$pending): FederationSessionManager
    {
        $sessions = $this->createMock(FederationSessionManager::class);
        $sessions->method('registerSession')->willReturnCallback(
            static function (string $peerId) use (&$pending): string {
                foreach ($pending as $sid => $entry) {
                    if ($entry['peer_id'] === $peerId) {
                        unset($pending[$sid]);
                    }
                }
                return 'master-sess';
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
                unset($pending[$sid]);
                return $entry;
            },
        );

        return $sessions;
    }

    /**
     * @param list<string> $frames
     */
    private function lastTextFrame(array $frames, string $needle): ?string
    {
        foreach (array_reverse($frames) as $frame) {
            if (str_starts_with($frame, '{') && str_contains($frame, $needle)) {
                return $frame;
            }
        }
        return null;
    }

    /**
     * @param array<int, mixed> $args
     */
    private function invokeProtected(object $target, string $method, array $args): mixed
    {
        $ref = new ReflectionMethod($target, $method);
        $ref->setAccessible(true);

        return $ref->invoke($target, ...$args);
    }

    private function readProtected(object $target, string $property): mixed
    {
        $ref = new ReflectionProperty($target, $property);
        $ref->setAccessible(true);

        return $ref->getValue($target);
    }
}
