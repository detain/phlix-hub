<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Federation;

use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Federation\FederationAdminDelegationRepository;
use Phlix\Hub\Federation\FederationHubRepository;
use Phlix\Hub\Federation\FederationLibraryShareRepository;
use Phlix\Hub\Federation\FederationPeerManager;
use Phlix\Hub\Federation\FederationSessionManager;
use Phlix\Hub\Tests\Support\WorkermanTimerRuntimeControl;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Unit tests for {@see FederationPeerManager} leaf-side logic that can be
 * exercised without sockets: dial-URL construction (M-8), HELLO_ACK identity
 * handling (C-1(3)), incoming-offer rebasing (M-5), and the deliberate-
 * disconnect guard (M-7).
 *
 * @package Phlix\Hub\Tests\Unit\Federation
 */
final class FederationPeerManagerTest extends TestCase
{
    use WorkermanTimerRuntimeControl;

    /**
     * @var FederationHubRepository&\PHPUnit\Framework\MockObject\MockObject
     */
    private $hubRepo;

    /**
     * @var FederationSessionManager&\PHPUnit\Framework\MockObject\MockObject
     */
    private $sessions;

    /**
     * @var FederationLibraryShareRepository&\PHPUnit\Framework\MockObject\MockObject
     */
    private $libraryShares;

    /**
     * @var AuditLogger&\PHPUnit\Framework\MockObject\MockObject
     */
    private $audit;

    private FederationPeerManager $manager;

    private ?string $loggerTmp = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hubRepo = $this->createMock(FederationHubRepository::class);
        $this->sessions = $this->createMock(FederationSessionManager::class);
        $this->libraryShares = $this->createMock(FederationLibraryShareRepository::class);
        $adminDel = $this->createMock(FederationAdminDelegationRepository::class);
        $this->audit = $this->createMock(AuditLogger::class);

        $this->manager = new FederationPeerManager(
            $this->hubRepo,
            $this->sessions,
            $this->libraryShares,
            $adminDel,
            $this->audit,
        );

        // Ack/rebase paths log through LoggerFactory::get(RELAY).
        $this->loggerTmp = sys_get_temp_dir() . '/phlix-hub-fed-peer-' . bin2hex(random_bytes(6));
        mkdir($this->loggerTmp, 0700, true);
        file_put_contents(
            $this->loggerTmp . '/logger.php',
            "<?php return ['default' => 'mem', 'handlers' => ['mem' => "
            . "['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug']]];",
        );
        LoggerFactory::reset();
        LoggerFactory::init($this->loggerTmp . '/logger.php');

        // Successful ack arms the 15s heartbeat timer; a refused ack arms the
        // reconnect timer. Seed the Workerman registry so Timer::add() takes
        // its succeeding task-table arm instead of throwing outside a real
        // runtime; the trait restores everything after each test.
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
        parent::tearDown();
    }

    // ------------------------------------------------- buildMasterWsUrl (M-8)

    public function testDialUrlHonorsConfiguredPortAndDefaultsToWss(): void
    {
        self::assertSame(
            'wss://master.example.com:8805/relay/federation/leaf%20id',
            $this->buildUrl('https://master.example.com:8805', 'leaf id'),
        );
    }

    public function testDialUrlMapsHttpSchemeToPlainWs(): void
    {
        self::assertSame(
            'ws://master.lan/relay/federation/l1',
            $this->buildUrl('http://master.lan', 'l1'),
        );
    }

    public function testDialUrlOmitsPortWhenNotConfigured(): void
    {
        self::assertSame(
            'wss://master.example.com/relay/federation/l1',
            $this->buildUrl('https://master.example.com', 'l1'),
        );
    }

    public function testDialUrlFallsBackToWssForBareHost(): void
    {
        self::assertSame(
            'wss://master.example.com/relay/federation/l1',
            $this->buildUrl('master.example.com', 'l1'),
        );
    }

    private function buildUrl(string $configured, string $leafHubId): string
    {
        $method = new ReflectionMethod($this->manager, 'buildMasterWsUrl');
        $method->setAccessible(true);

        /** @var string */
        return $method->invoke($this->manager, $configured, $leafHubId);
    }

    // ----------------------------------------------- HELLO_ACK identity (M-8)

    public function testHelloAckWithoutDialedPeerAuditsFailure(): void
    {
        $this->hubRepo->method('getHubConfig')->willReturn(['id' => 'own-leaf-id']);
        $this->audit->expects(self::once())
            ->method('logHubConnect')
            ->with(self::identicalTo('own-leaf-id'), self::identicalTo('master'), self::anything(), false);

        $this->invokeHandleHelloAck(['session_id' => 's1', 'master_hub_id' => 'master-uuid']);

        self::assertNull($this->readProperty('sessionId'));
    }

    /**
     * M-8: the ack's master_hub_id must match the peer's BOUND identity.
     * A contradicting ack is refused — never silently adopted.
     */
    public function testHelloAckRejectsContradictingMasterIdentity(): void
    {
        $this->setMasterPeerId('peer-master-1');
        $this->hubRepo->method('getHubConfig')->willReturn(['id' => 'own-leaf-id']);
        $this->hubRepo->method('getPeerById')->with('peer-master-1')->willReturn([
            'id' => 'peer-master-1',
            'name' => 'Master',
            'leaf_hub_id' => 'the-real-master-uuid',
        ]);
        $this->hubRepo->expects(self::never())->method('setPeerLeafHubId');
        $this->sessions->expects(self::never())->method('registerSession');
        $this->audit->expects(self::once())
            ->method('logHubConnect')
            ->with(self::anything(), self::anything(), self::anything(), false);

        $this->invokeHandleHelloAck(['session_id' => 's1', 'master_hub_id' => 'IMPOSTOR-uuid']);

        self::assertNull($this->readProperty('sessionId'), 'Refused ack must not adopt the session');
    }

    /**
     * M-8: first successful ack binds the reported master identity and the
     * leaf registers its local mirror session under the DIALED peer id —
     * not via the old getConnectedPeers()[0] probe that found nothing while
     * the master row was still 'pending' (C-1(1)/H-2 bootstrap breakage).
     */
    public function testHelloAckBindsIdentityAndRegistersLocalSession(): void
    {
        $this->setMasterPeerId('peer-master-1');
        $this->hubRepo->method('getHubConfig')->willReturn(['id' => 'own-leaf-id']);
        $this->hubRepo->method('getPeerById')->with('peer-master-1')->willReturn([
            'id' => 'peer-master-1',
            'name' => 'Master',
            'leaf_hub_id' => null,
        ]);
        $this->hubRepo->expects(self::once())
            ->method('setPeerLeafHubId')
            ->with('peer-master-1', 'master-uuid-77');
        $this->sessions->expects(self::once())
            ->method('registerSession')
            ->with('peer-master-1')
            ->willReturn('local-sess-1');
        $this->audit->expects(self::once())
            ->method('logHubConnect')
            ->with(self::anything(), self::anything(), self::anything(), true);

        $this->invokeHandleHelloAck(['session_id' => 'master-sess-9', 'master_hub_id' => 'master-uuid-77']);

        self::assertSame('master-sess-9', $this->readProperty('sessionId'));
        // Tear down the heartbeat timer the ack armed, so it cannot leak.
        $this->manager->disconnectFromMaster();
    }

    // --------------------------------------------------- offer rebasing (M-5)

    public function testOfferWithoutDialedPeerIsDropped(): void
    {
        self::assertNull($this->rebase(['id' => 'o1', 'peer_id' => 'x', 'library_id' => 'l1']));
    }

    public function testOfferRebasesToMasterPeerLocalRowId(): void
    {
        $this->setMasterPeerId('peer-master-1');
        $this->hubRepo->method('getPeerById')->willReturn([
            'id' => 'peer-master-1',
            'leaf_hub_id' => 'master-uuid',
        ]);

        $out = $this->rebase(['id' => 'o1', 'peer_id' => 'master-uuid', 'library_id' => 'l1']);

        self::assertNotNull($out);
        self::assertSame('peer-master-1', $out['peer_id']);
    }

    public function testOfferWithContradictingWirePeerIdIsDropped(): void
    {
        $this->setMasterPeerId('peer-master-1');
        $this->hubRepo->method('getPeerById')->willReturn([
            'id' => 'peer-master-1',
            'leaf_hub_id' => 'master-uuid',
        ]);

        self::assertNull($this->rebase(['id' => 'o1', 'peer_id' => 'OTHER-uuid', 'library_id' => 'l1']));
    }

    /**
     * @param array<string, mixed> $offer
     *
     * @return array<string, mixed>|null
     */
    private function rebase(array $offer): ?array
    {
        $method = new ReflectionMethod($this->manager, 'rebaseOfferIdentity');
        $method->setAccessible(true);

        /** @var array<string, mixed>|null $result */
        $result = $method->invoke($this->manager, $offer);
        return $result;
    }

    // ------------------------------------------------ reconnect guard (M-7)

    /**
     * M-7 regression: after a deliberate disconnect, scheduleReconnect must
     * refuse to arm — the close its own onClose handler sees may no longer
     * resurrect the link the operator dropped.
     */
    public function testDeliberateDisconnectSuppressesReconnect(): void
    {
        $this->manager->disconnectFromMaster();

        $schedule = new ReflectionMethod($this->manager, 'scheduleReconnect');
        $schedule->setAccessible(true);
        $schedule->invoke($this->manager);

        self::assertFalse((bool) $this->readProperty('reconnectScheduled'));
        self::assertTrue((bool) $this->readProperty('intentionalDisconnect'));
    }

    // ---------------------------------------------------------------- helpers

    private function setMasterPeerId(string $peerId): void
    {
        $property = new ReflectionProperty($this->manager, 'masterPeerId');
        $property->setAccessible(true);
        $property->setValue($this->manager, $peerId);
    }

    private function readProperty(string $name): mixed
    {
        $property = new ReflectionProperty($this->manager, $name);
        $property->setAccessible(true);

        return $property->getValue($this->manager);
    }

    /**
     * @param array<string, mixed> $msg
     */
    private function invokeHandleHelloAck(array $msg): void
    {
        $method = new ReflectionMethod($this->manager, 'handleHelloAck');
        $method->setAccessible(true);
        $method->invoke($this->manager, $msg);
    }
}
