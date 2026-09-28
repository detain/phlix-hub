<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\Relay\FrameDecoder;
use Phlix\Hub\Relay\Tunnel;
use Phlix\Hub\Relay\TunnelManager;
use Phlix\Shared\Relay\RelayWireCodecInterface;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Tests\Support\WorkermanTimerRuntimeControl;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Workerman\Connection\TcpConnection;

class TunnelManagerTest extends TestCase
{
    // Workerman's Timer statics and Worker registry are process-global; the trait
    // snapshots them before setUp() and restores them after tearDown().
    use WorkermanTimerRuntimeControl;

    private RelayWireCodecInterface $codec;
    private StructuredLogger&MockObject $logger;
    private RelaySessionManager&MockObject $sessionManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->codec = new FrameDecoder();
        $this->logger = $this->createMock(StructuredLogger::class);
        $this->sessionManager = $this->createMock(RelaySessionManager::class);
    }

    public function testAcceptServerCreatesNewTunnel(): void
    {
        $manager = new TunnelManager(
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );

        $serverWs = $this->createMock(TcpConnection::class);

        $sessionId = 'session-123';
        $this->sessionManager
            ->expects($this->any())
            ->method('registerServer')
            ->willReturn($sessionId);

        $tunnel = $manager->acceptServer('server-abc', $serverWs);

        $this->assertInstanceOf(Tunnel::class, $tunnel);
        $this->assertSame('server-abc', $tunnel->serverId);
        $this->assertSame(Tunnel::STATUS_PENDING, $tunnel->status);
    }

    public function testAcceptServerDoesNotCloseIncumbentImmediately(): void
    {
        // HB-2.2: The incumbent tunnel must NOT be closed in acceptServer().
        // It is only closed after the new tunnel's JWT validates successfully
        // (via finalizeServerConnection()). This prevents an attacker from
        // displacing an incumbent by sending a HELLO with a guessed server_id.
        $manager = new TunnelManager(
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );

        $serverWs1 = $this->createMock(TcpConnection::class);
        $serverWs2 = $this->createMock(TcpConnection::class);

        $sessionId = 'session-123';
        $this->sessionManager
            ->method('registerServer')
            ->willReturn($sessionId);

        // First connection — the incumbent, brought ACTIVE (routable).
        $tunnel1 = $manager->acceptServer('server-abc', $serverWs1);
        $tunnel1->relaySessionId = $sessionId;
        $tunnel1->status = Tunnel::STATUS_ACTIVE;

        // Second connection (reconnect) must NOT close the first tunnel yet.
        // The new tunnel is parked pending JWT validation; the incumbent remains
        // active and — critically (HB-2.2 defect #2) — still the routing entry.
        $serverWs1
            ->expects($this->never())
            ->method('close');

        $tunnel2 = $manager->acceptServer('server-abc', $serverWs2);

        // Should be a different tunnel
        $this->assertNotSame($tunnel1, $tunnel2);

        // The incumbent (tunnel1) is STILL the routable tunnel — the unvalidated
        // reconnect has NOT displaced it in the routing map.
        $this->assertSame($tunnel1, $manager->getTunnelForServer('server-abc'));

        // The incumbent tunnel (tunnel1) is NOT closed yet
        $this->assertNotSame(Tunnel::STATUS_CLOSED, $tunnel1->status);
    }

    public function testFinalizeServerConnectionDrainsThenDisplacesIncumbent(): void
    {
        // HB-2.2 + H-R6: After JWT validation succeeds, finalizeServerConnection()
        // promotes the validated tunnel into the routing map and DRAINS the
        // incumbent (moves it to CLOSING for a grace period) before the hard
        // close, so a legitimate reconnect does not instantly kill playback.
        $manager = new TunnelManager(
            $this->sessionManager,
            $this->codec,
            $this->logger,
            null,
            5.0, // reconnect-drain grace
        );

        $serverWs1 = $this->createMock(TcpConnection::class);
        $serverWs2 = $this->createMock(TcpConnection::class);

        $sessionId = 'session-123';
        $this->sessionManager
            ->method('registerServer')
            ->willReturn($sessionId);

        // First connection — incumbent, ACTIVE.
        $tunnel1 = $manager->acceptServer('server-abc', $serverWs1);
        $tunnel1->relaySessionId = $sessionId;
        $tunnel1->status = Tunnel::STATUS_ACTIVE;

        // Second connection — parked pending, incumbent still routable & ACTIVE.
        $tunnel2 = $manager->acceptServer('server-abc', $serverWs2);
        $this->assertSame($tunnel1, $manager->getTunnelForServer('server-abc'));
        $this->assertSame(Tunnel::STATUS_ACTIVE, $tunnel1->status);

        // Finalize (JWT validated). The new tunnel is promoted and the incumbent
        // begins draining (CLOSING) — NOT hard-closed yet.
        $manager->finalizeServerConnection('server-abc');

        $this->assertSame(Tunnel::STATUS_CLOSING, $tunnel1->status, 'incumbent drains before displacement');
        $this->assertSame($tunnel2, $manager->getTunnelForServer('server-abc'), 'new tunnel is promoted');

        // Simulate the drain grace timer firing (armed via Timer::add — a no-op
        // in tests). The incumbent then hard-closes.
        $method = new \ReflectionMethod($tunnel1, 'handleDrainTimeout');
        $method->setAccessible(true);
        $method->invoke($tunnel1);

        $this->assertSame(Tunnel::STATUS_CLOSED, $tunnel1->status);
        // The promoted tunnel is unaffected.
        $this->assertSame($tunnel2, $manager->getTunnelForServer('server-abc'));
    }

    public function testFinalizeServerConnectionImmediateDisplaceWhenGraceZero(): void
    {
        // Grace of 0 disables the drain — the incumbent is displaced immediately.
        $manager = new TunnelManager(
            $this->sessionManager,
            $this->codec,
            $this->logger,
            null,
            0.0,
        );

        $serverWs1 = $this->createMock(TcpConnection::class);
        $serverWs2 = $this->createMock(TcpConnection::class);
        $this->sessionManager->method('registerServer')->willReturn('session-123');

        $tunnel1 = $manager->acceptServer('server-abc', $serverWs1);
        $tunnel1->relaySessionId = 'session-123';
        $tunnel1->status = Tunnel::STATUS_ACTIVE;

        $tunnel2 = $manager->acceptServer('server-abc', $serverWs2);
        $manager->finalizeServerConnection('server-abc');

        $this->assertSame(Tunnel::STATUS_CLOSED, $tunnel1->status);
        $this->assertSame($tunnel2, $manager->getTunnelForServer('server-abc'));
    }

    public function testFinalizeServerConnectionDoesNothingWhenNoIncumbent(): void
    {
        // finalizeServerConnection() is safe to call when there is no incumbent
        // (i.e., this was a fresh connection, not a reconnect).
        $manager = new TunnelManager(
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );

        $serverWs = $this->createMock(TcpConnection::class);

        $sessionId = 'session-123';
        $this->sessionManager
            ->method('registerServer')
            ->willReturn($sessionId);

        $manager->acceptServer('server-abc', $serverWs);

        // No incumbent to close - should not throw
        $manager->finalizeServerConnection('server-abc');

        // Tunnel should still be there
        $this->assertNotNull($manager->getTunnelForServer('server-abc'));
    }

    public function testAbortPendingConnectionLeavesIncumbentRoutable(): void
    {
        // HB-2.2: a rejected reconnect (bad HELLO) must be discarded WITHOUT
        // touching the live incumbent, which stays routable.
        $manager = new TunnelManager(
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );

        $serverWs1 = $this->createMock(TcpConnection::class);
        $serverWs2 = $this->createMock(TcpConnection::class);
        $this->sessionManager->method('registerServer')->willReturn('session-123');

        // Live incumbent.
        $tunnel1 = $manager->acceptServer('server-abc', $serverWs1);
        $tunnel1->relaySessionId = 'session-123';
        $tunnel1->status = Tunnel::STATUS_ACTIVE;

        // The incumbent's connection must never be closed by the aborted reconnect.
        $serverWs1->expects($this->never())->method('close');
        // The attacker's connection IS closed by close('unauthorized').
        $serverWs2->expects($this->atLeastOnce())->method('close');

        // Parked reconnect that then fails validation.
        $tunnel2 = $manager->acceptServer('server-abc', $serverWs2);
        $manager->abortPendingConnection('server-abc', $tunnel2);

        // Incumbent untouched and still routable; rejected tunnel closed.
        $this->assertSame(Tunnel::STATUS_ACTIVE, $tunnel1->status);
        $this->assertSame($tunnel1, $manager->getTunnelForServer('server-abc'));
        $this->assertSame(Tunnel::STATUS_CLOSED, $tunnel2->status);
    }

    public function testAbortPendingConnectionRemovesFreshRejectedTunnel(): void
    {
        // Fresh connection (no incumbent) whose HELLO fails: the rejected tunnel
        // was placed directly in the map and must be removed so getTunnelForServer
        // does not return a dead tunnel.
        $manager = new TunnelManager(
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );

        $serverWs = $this->createMock(TcpConnection::class);
        $this->sessionManager->method('registerServer')->willReturn('session-123');

        $tunnel = $manager->acceptServer('server-fresh', $serverWs);
        $this->assertSame($tunnel, $manager->getTunnelForServer('server-fresh'));

        $manager->abortPendingConnection('server-fresh', $tunnel);

        $this->assertNull($manager->getTunnelForServer('server-fresh'));
        $this->assertSame(Tunnel::STATUS_CLOSED, $tunnel->status);
    }

    public function testGetTunnelForServerReturnsTunnelWhenExists(): void
    {
        $manager = new TunnelManager(
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );

        $serverWs = $this->createMock(TcpConnection::class);

        $sessionId = 'session-123';
        $this->sessionManager
            ->method('registerServer')
            ->willReturn($sessionId);

        $manager->acceptServer('server-abc', $serverWs);

        $tunnel = $manager->getTunnelForServer('server-abc');

        $this->assertInstanceOf(Tunnel::class, $tunnel);
        $this->assertSame('server-abc', $tunnel->serverId);
    }

    public function testGetTunnelForServerReturnsNullWhenNotFound(): void
    {
        $manager = new TunnelManager(
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );

        $tunnel = $manager->getTunnelForServer('nonexistent');

        $this->assertNull($tunnel);
    }

    public function testAcceptClientReturnsNullWhenTunnelNotFound(): void
    {
        $manager = new TunnelManager(
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );

        $clientWs = $this->createMock(TcpConnection::class);

        $result = $manager->acceptClient('server-abc', $clientWs, 'client-1');

        $this->assertNull($result);
    }

    public function testAcceptClientReturnsNullWhenTunnelNotActive(): void
    {
        $manager = new TunnelManager(
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );

        $serverWs = $this->createMock(TcpConnection::class);

        $sessionId = 'session-123';
        $this->sessionManager
            ->method('registerServer')
            ->willReturn($sessionId);

        // Create tunnel but don't activate it (stay in PENDING)
        $manager->acceptServer('server-abc', $serverWs);

        $clientWs = $this->createMock(TcpConnection::class);
        $result = $manager->acceptClient('server-abc', $clientWs, 'client-1');

        $this->assertNull($result);
    }

    public function testAcceptClientCreatesClientConnectionWhenTunnelActive(): void
    {
        $manager = new TunnelManager(
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );

        $serverWs = $this->createMock(TcpConnection::class);

        $sessionId = 'session-123';
        $this->sessionManager
            ->method('registerServer')
            ->willReturn($sessionId);

        $tunnel = $manager->acceptServer('server-abc', $serverWs);

        // Activate the tunnel
        $tunnel->status = Tunnel::STATUS_ACTIVE;
        $tunnel->relaySessionId = $sessionId;

        $clientWs = $this->createMock(TcpConnection::class);

        $result = $manager->acceptClient('server-abc', $clientWs, 'client-1', 'relay-sess-1');

        $this->assertNotNull($result);
        $this->assertSame($clientWs, $result->clientWs);
        $this->assertSame('client-1', $result->clientId);
        // No user id passed → mounted Unlimited (S42): getUserThrottleBps must
        // not even be consulted.
        $this->assertSame(0, $result->throttleBps);
        $this->assertFalse($result->isThrottled());
    }

    public function testAcceptClientAttachesResolvedUserThrottle(): void
    {
        $manager = new TunnelManager(
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );

        $serverWs = $this->createMock(TcpConnection::class);
        $this->sessionManager->method('registerServer')->willReturn('session-123');

        $tunnel = $manager->acceptServer('server-abc', $serverWs);
        $tunnel->status = Tunnel::STATUS_ACTIVE;
        $tunnel->relaySessionId = 'session-123';

        // The owning user's throttle is resolved from S41 storage and attached.
        $this->sessionManager->expects($this->once())
            ->method('getUserThrottleBps')
            ->with('user-42')
            ->willReturn(5_000_000);

        $clientWs = $this->createMock(TcpConnection::class);
        $result = $manager->acceptClient('server-abc', $clientWs, 'client-1', '', 'user-42');

        $this->assertNotNull($result);
        $this->assertSame(5_000_000, $result->throttleBps);
        $this->assertTrue($result->isThrottled());
        $this->assertNotNull($result->throttleBucket);
    }

    public function testAcceptClientUnlimitedWhenUserThrottleIsZero(): void
    {
        $manager = new TunnelManager(
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );

        $serverWs = $this->createMock(TcpConnection::class);
        $this->sessionManager->method('registerServer')->willReturn('session-123');

        $tunnel = $manager->acceptServer('server-abc', $serverWs);
        $tunnel->status = Tunnel::STATUS_ACTIVE;
        $tunnel->relaySessionId = 'session-123';

        // Admin set this user to Unlimited (0).
        $this->sessionManager->method('getUserThrottleBps')->willReturn(0);

        $clientWs = $this->createMock(TcpConnection::class);
        $result = $manager->acceptClient('server-abc', $clientWs, 'client-1', '', 'user-42');

        $this->assertNotNull($result);
        $this->assertSame(0, $result->throttleBps);
        $this->assertFalse($result->isThrottled());
        $this->assertNull($result->throttleBucket);
    }

    public function testAcceptClientFailsOpenToUnlimitedWhenThrottleLookupThrows(): void
    {
        $manager = new TunnelManager(
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );

        $serverWs = $this->createMock(TcpConnection::class);
        $this->sessionManager->method('registerServer')->willReturn('session-123');

        $tunnel = $manager->acceptServer('server-abc', $serverWs);
        $tunnel->status = Tunnel::STATUS_ACTIVE;
        $tunnel->relaySessionId = 'session-123';

        // A transient DB error during the lookup must NOT refuse the mount — the
        // connection is mounted Unlimited (fail-open) and the failure is logged.
        $this->sessionManager->method('getUserThrottleBps')
            ->willThrowException(new \RuntimeException('db down'));
        $this->logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with($this->stringContains('throttle lookup failed'), $this->anything());

        $clientWs = $this->createMock(TcpConnection::class);
        $result = $manager->acceptClient('server-abc', $clientWs, 'client-1', '', 'user-42');

        $this->assertNotNull($result);
        $this->assertSame(0, $result->throttleBps);
        $this->assertFalse($result->isThrottled());
    }

    public function testCloseTunnelClosesServerAndRemovesFromMap(): void
    {
        $manager = new TunnelManager(
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );

        $serverWs = $this->createMock(TcpConnection::class);

        $sessionId = 'session-123';
        $this->sessionManager
            ->method('registerServer')
            ->willReturn($sessionId);

        $tunnel = $manager->acceptServer('server-abc', $serverWs);
        $tunnel->relaySessionId = $sessionId;
        $tunnel->status = Tunnel::STATUS_ACTIVE;

        $serverWs
            ->expects($this->once())
            ->method('close');

        $this->sessionManager
            ->expects($this->once())
            ->method('closeSession')
            ->with($sessionId, 'test_reason');

        $manager->closeTunnel('server-abc', 'test_reason');

        $this->assertNull($manager->getTunnelForServer('server-abc'));
    }

    public function testAllTunnelsYieldsOnlyActiveTunnels(): void
    {
        $manager = new TunnelManager(
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );

        $serverWs1 = $this->createMock(TcpConnection::class);
        $serverWs2 = $this->createMock(TcpConnection::class);

        $sessionId = 'session-123';
        $this->sessionManager
            ->method('registerServer')
            ->willReturn($sessionId);

        $tunnel1 = $manager->acceptServer('server-1', $serverWs1);
        $tunnel2 = $manager->acceptServer('server-2', $serverWs2);

        // Activate only tunnel1
        $tunnel1->relaySessionId = $sessionId;
        $tunnel1->status = Tunnel::STATUS_ACTIVE;
        // tunnel2 stays PENDING

        $activeTunnels = [];
        foreach ($manager->allTunnels() as $serverId => $tunnel) {
            $activeTunnels[$serverId] = $tunnel;
        }

        $this->assertCount(1, $activeTunnels);
        $this->assertArrayHasKey('server-1', $activeTunnels);
        $this->assertArrayNotHasKey('server-2', $activeTunnels);
    }

    public function testGetActiveTunnelCountReturnsCorrectCount(): void
    {
        $manager = new TunnelManager(
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );

        $serverWs1 = $this->createMock(TcpConnection::class);
        $serverWs2 = $this->createMock(TcpConnection::class);

        $sessionId = 'session-123';
        $this->sessionManager
            ->method('registerServer')
            ->willReturn($sessionId);

        $tunnel1 = $manager->acceptServer('server-1', $serverWs1);
        $manager->acceptServer('server-2', $serverWs2);

        $this->assertSame(0, $manager->getActiveTunnelCount());

        // Activate tunnel1
        $tunnel1->relaySessionId = $sessionId;
        $tunnel1->status = Tunnel::STATUS_ACTIVE;

        $this->assertSame(1, $manager->getActiveTunnelCount());
    }

    public function testHasTunnelReturnsTrueWhenActive(): void
    {
        $manager = new TunnelManager(
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );

        $serverWs = $this->createMock(TcpConnection::class);

        $sessionId = 'session-123';
        $this->sessionManager
            ->method('registerServer')
            ->willReturn($sessionId);

        $tunnel = $manager->acceptServer('server-abc', $serverWs);

        $this->assertFalse($manager->hasTunnel('server-abc')); // PENDING, not ACTIVE

        $tunnel->relaySessionId = $sessionId;
        $tunnel->status = Tunnel::STATUS_ACTIVE;

        $this->assertTrue($manager->hasTunnel('server-abc'));
    }

    public function testRemoveTunnelRemovesFromMap(): void
    {
        $manager = new TunnelManager(
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );

        $serverWs = $this->createMock(TcpConnection::class);

        $sessionId = 'session-123';
        $this->sessionManager
            ->method('registerServer')
            ->willReturn($sessionId);

        $tunnel = $manager->acceptServer('server-abc', $serverWs);
        $tunnel->relaySessionId = $sessionId;
        $tunnel->status = Tunnel::STATUS_ACTIVE;

        $this->assertTrue($manager->hasTunnel('server-abc'));

        $manager->removeTunnel('server-abc');

        $this->assertFalse($manager->hasTunnel('server-abc'));
    }

    public function testRemoveTunnelWithIdentityGuardKeepsReplacementTunnel(): void
    {
        // H-1: a displaced tunnel's deferred close must not evict the tunnel
        // that was promoted into its routing slot while the old one drained.
        $manager = new TunnelManager($this->sessionManager, $this->codec, $this->logger);
        $this->sessionManager->method('registerServer')->willReturn('session-123');

        $tunnel = $manager->acceptServer('server-abc', $this->createMock(TcpConnection::class));
        $tunnel->status = Tunnel::STATUS_ACTIVE;

        $impostor = $manager->acceptServer('server-other', $this->createMock(TcpConnection::class));

        // Wrong identity for 'server-abc' → routing entry untouched.
        $manager->removeTunnel('server-abc', $impostor);
        $this->assertSame($tunnel, $manager->getTunnelForServer('server-abc'));

        // Matching identity → removed.
        $manager->removeTunnel('server-abc', $tunnel);
        $this->assertNull($manager->getTunnelForServer('server-abc'));
    }

    public function testRemoveTunnelWithIdentityEvictsParkedPendingTunnel(): void
    {
        // H-1: parked pre-validation tunnels must be removable by identity —
        // previously nothing ever evicted them until the next HELLO superseded
        // them, so a disconnecting reconnect attempt leaked the parked tunnel.
        $manager = new TunnelManager($this->sessionManager, $this->codec, $this->logger);
        $this->sessionManager->method('registerServer')->willReturn('session-123');

        $incumbent = $manager->acceptServer('server-abc', $this->createMock(TcpConnection::class));
        $incumbent->status = Tunnel::STATUS_ACTIVE;

        $parked = $manager->acceptServer('server-abc', $this->createMock(TcpConnection::class));
        // Parked, not promoted: routing still serves the incumbent.
        $this->assertSame($incumbent, $manager->getTunnelForServer('server-abc'));

        // Evicting the parked tunnel by identity must not touch the routing map.
        $manager->removeTunnel('server-abc', $parked);
        $this->assertSame($incumbent, $manager->getTunnelForServer('server-abc'));

        // And the parking slot is genuinely gone: finalizing finds no pending
        // tunnel to promote, so a later validation cannot displace the
        // incumbent with the evicted tunnel.
        $manager->finalizeServerConnection('server-abc');
        $this->assertSame($incumbent, $manager->getTunnelForServer('server-abc'));
    }

    public function testGetTunnelForServerHidesClosedTunnels(): void
    {
        // H-1: a CLOSED tunnel sitting in the raw routing map (close-path race
        // window) must never be handed to traffic-routing callers.
        $manager = new TunnelManager($this->sessionManager, $this->codec, $this->logger);
        $this->sessionManager->method('registerServer')->willReturn('session-123');

        $serverWs = $this->createMock(TcpConnection::class);
        $tunnel = $manager->acceptServer('server-abc', $serverWs);
        $tunnel->status = Tunnel::STATUS_ACTIVE;

        // Force CLOSED without going through closeTunnel() (which also unsets)
        // to simulate the tiny window between Tunnel::close() and eviction.
        $tunnel->status = Tunnel::STATUS_CLOSED;

        $this->assertNull($manager->getTunnelForServer('server-abc'));
    }

    public function testPruneDeadTunnelsSweepsBothMapsAndKeepsLiveOnes(): void
    {
        // H-1 safety net: CLOSED entries in BOTH the routing and pending maps
        // are swept; live entries (ACTIVE routing, PENDING parked) survive.
        $manager = new TunnelManager($this->sessionManager, $this->codec, $this->logger);
        $this->sessionManager->method('registerServer')->willReturn('session-123');

        $dead = $manager->acceptServer('server-dead', $this->createMock(TcpConnection::class));
        $dead->status = Tunnel::STATUS_ACTIVE;
        $dead->close('test'); // leaves the CLOSED tunnel in the raw routing map

        $live = $manager->acceptServer('server-live', $this->createMock(TcpConnection::class));
        $live->status = Tunnel::STATUS_ACTIVE;

        // Parked incumbent setup, then kill the parked tunnel:
        $parkingWs = $this->createMock(TcpConnection::class);
        $manager->acceptServer('server-reconnect', $parkingWs);
        $reconnectIncumbent = $manager->getTunnelForServer('server-reconnect');
        self::assertInstanceOf(Tunnel::class, $reconnectIncumbent);
        $reconnectIncumbent->status = Tunnel::STATUS_ACTIVE;
        $parked = $manager->acceptServer('server-reconnect', $this->createMock(TcpConnection::class));
        $parked->status = Tunnel::STATUS_CLOSED; // dead while still parked

        $pruned = $manager->pruneDeadTunnels();

        $this->assertSame(2, $pruned, 'one dead routing entry + one dead pending entry');
        $this->assertSame($live, $manager->getTunnelForServer('server-live'));
        $this->assertNull($manager->getTunnelForServer('server-dead'));

        // The evicted parked tunnel can no longer be promoted; the live
        // incumbent for that server survives.
        $manager->finalizeServerConnection('server-reconnect');
        $this->assertSame($reconnectIncumbent, $manager->getTunnelForServer('server-reconnect'));
        $this->assertSame(Tunnel::STATUS_ACTIVE, $reconnectIncumbent->status);
    }
}
