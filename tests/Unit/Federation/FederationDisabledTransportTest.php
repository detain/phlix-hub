<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Federation;

use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Federation\FederationAdminDelegationRepository;
use Phlix\Hub\Federation\FederationConnectionManager;
use Phlix\Hub\Federation\FederationFrameHandler;
use Phlix\Hub\Federation\FederationHubRepository;
use Phlix\Hub\Federation\FederationLibraryShareRepository;
use Phlix\Hub\Federation\FederationPeerManager;
use Phlix\Hub\Federation\FederationSessionManager;
use Phlix\Hub\Hub\Ed25519KeyManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Workerman\Connection\ConnectionInterface;

/**
 * W5 `federation.enabled` gate tests for the wire-transport halves: the frame
 * handler (inbound) and the peer manager (outbound dial).
 *
 * Pins: disabled -> inbound text refused with an audit trail, inbound binary
 * dropped without touching session state, outbound dial returns BEFORE any
 * repository read or state mutation (self-heal property: the reconnect timer
 * re-arms, the gate only short-circuits the dial). Null resolver keeps the
 * pre-W5 always-on path exactly — asserted by the unchanged answers.
 *
 * @package Phlix\Hub\Tests\Unit\Federation
 */
final class FederationDisabledTransportTest extends TestCase
{
    private FederationConnectionManager $connMgr;

    private string $keyDir = '';

    private Ed25519KeyManager $keyManager;

    private FederationHubRepository&MockObject $hubRepo;

    private FederationSessionManager&MockObject $sessions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connMgr = new FederationConnectionManager();
        $this->keyDir  = sys_get_temp_dir() . '/phlix-hub-fedoff-keys-' . bin2hex(random_bytes(6));
        mkdir($this->keyDir, 0700, true);
        $this->keyManager = new Ed25519KeyManager($this->keyDir . '/master-ed25519.pem');

        $this->hubRepo  = $this->createMock(FederationHubRepository::class);
        $this->sessions = $this->createMock(FederationSessionManager::class);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->keyDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @rmdir($this->keyDir);

        parent::tearDown();
    }

    private function frameHandler(?callable $enabledResolver, ?AuditLogger $audit = null): FederationFrameHandler
    {
        return new FederationFrameHandler(
            $this->hubRepo,
            $this->sessions,
            $this->createMock(FederationLibraryShareRepository::class),
            $this->connMgr,
            $audit ?? $this->createMock(AuditLogger::class),
            $this->keyManager,
            $enabledResolver,
        );
    }

    private function peerManager(?callable $enabledResolver): FederationPeerManager
    {
        return new FederationPeerManager(
            $this->hubRepo,
            $this->sessions,
            $this->createMock(FederationLibraryShareRepository::class),
            $this->createMock(FederationAdminDelegationRepository::class),
            $this->createMock(AuditLogger::class),
            $this->keyManager,
            $enabledResolver,
        );
    }

    // -------------------------------------------------------- frame handler

    public function testDisabledTextFrameIsRefusedWithAuditTrail(): void
    {
        $audit = $this->createMock(AuditLogger::class);
        $audit->expects(self::once())
            ->method('logFailedAuth')
            ->with('FEDERATION_DISABLED', ['hub_id' => 'hub-9']);
        $this->hubRepo->expects(self::never())->method('getPeerByPublicKey');

        $handler = $this->frameHandler(static fn (): bool => false, $audit);

        $refused = $handler->handleTextFrame(
            'hub-9',
            '{"type":"hub_hello","public_key":"abc"}',
            $this->createMock(ConnectionInterface::class),
        );

        self::assertSame('federation_disabled', $refused);
    }

    public function testDisabledBinaryFrameDropsWithoutTouchingPeerState(): void
    {
        // Register a live connection so the ENABLED heartbeat path WOULD
        // reach the repository — the differential proves the gate, not an
        // empty connection table, is what drops the frame.
        $this->connMgr->addConnection('hub-9', $this->createMock(ConnectionInterface::class));
        $this->hubRepo->expects(self::never())->method('getPeerById');

        $handler = $this->frameHandler(static fn (): bool => false);

        // DATA(0x05)/HEARTBEAT(0x06)/DISCONNECTED(0x07) — all must no-op.
        $handler->handleBinaryFrame('hub-9', '{"shares":[]}', 0x05);
        $handler->handleBinaryFrame('hub-9', '', 0x06);
        $handler->handleBinaryFrame('hub-9', '', 0x07);
    }

    public function testControlEnabledHeartbeatDoesReachTheRepository(): void
    {
        $this->connMgr->addConnection('hub-9', $this->createMock(ConnectionInterface::class));
        $this->hubRepo->method('getPeerById')->willReturn(null);
        $this->hubRepo->expects(self::once())->method('getPeerById')->with('hub-9');

        // No resolver: always-on (pre-W5) — HEARTBEAT walks into the lookup.
        $this->frameHandler(null)->handleBinaryFrame('hub-9', '', 0x06);
    }

    public function testNullResolverKeepsPreW5Answers(): void
    {
        $handler = $this->frameHandler(null);

        $malformed = $handler->handleTextFrame('hub-9', '{bogus', $this->createMock(ConnectionInterface::class));

        self::assertSame('Invalid JSON payload', $malformed);
        // Binary with unknown frame type also no-ops exactly as before (no throw).
        $handler->handleBinaryFrame('hub-9', '', 0xFF);
    }

    public function testEnabledAnswerIsLivePerFrame(): void
    {
        $on    = true;
        $audit = $this->createMock(AuditLogger::class);
        $audit->expects(self::once())->method('logFailedAuth')->with('FEDERATION_DISABLED', ['hub_id' => 'hub-9']);

        $handler = $this->frameHandler(static function () use (&$on): bool {
            return $on;
        }, $audit);

        $conn = $this->createMock(ConnectionInterface::class);

        // Enabled: reaches frame parsing (unknown types ignored => null).
        self::assertNull($handler->handleTextFrame('hub-9', '{"type":"unknown_type"}', $conn));

        $on = false;
        self::assertSame('federation_disabled', $handler->handleTextFrame('hub-9', '{"type":"unknown_type"}', $conn));
    }

    // -------------------------------------------------------- peer manager

    public function testDisabledDialReturnsBeforeAnyRepositoryRead(): void
    {
        $this->hubRepo->expects(self::never())->method('getHubConfig');
        $this->hubRepo->expects(self::never())->method('getDialablePeers');

        $this->peerManager(static fn (): bool => false)->connectToMaster();
    }

    public function testNullResolverDialRunsThePreW5GuardChain(): void
    {
        // Pre-W5 behavior: the hub-config role guard still governs. With no
        // config row the dial returns on `hubConfig === null` — meaning the
        // repository WAS consulted (opposite of the disabled pin above).
        $this->hubRepo->expects(self::once())->method('getHubConfig')->willReturn(null);

        $this->peerManager(null)->connectToMaster();
    }
}
