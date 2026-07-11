<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\Relay\FrameDecoder;
use Phlix\Hub\Relay\FrameEncoder;
use Phlix\Hub\Relay\RelayFrameType;
use Phlix\Hub\Relay\Tunnel;
use Phlix\Hub\Relay\TunnelManager;
use Phlix\Shared\Relay\RelayFrame;
use Phlix\Shared\Relay\RelayWireCodecInterface;
use Phlix\Hub\Common\Logger\StructuredLogger;
use PHPUnit\Framework\TestCase;
use Workerman\Connection\TcpConnection;

class TunnelManagerTest extends TestCase
{
    private RelayWireCodecInterface $codec;
    private StructuredLogger $logger;
    private RelaySessionManager $sessionManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->codec = new FrameDecoder();
        $this->logger = $this->createMock(StructuredLogger::class);
        $this->sessionManager = $this->createMock(RelaySessionManager::class);
    }

    public function test_accept_server_creates_new_tunnel(): void
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

    public function test_accept_server_does_not_close_incumbent_immediately(): void
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

        // First connection
        $tunnel1 = $manager->acceptServer('server-abc', $serverWs1);

        // Second connection (reconnect) must NOT close the first tunnel yet.
        // The incumbent is stored in $closingTunnels and remains active.
        $serverWs1
            ->expects($this->never())
            ->method('close');

        $tunnel2 = $manager->acceptServer('server-abc', $serverWs2);

        // Should be a different tunnel
        $this->assertNotSame($tunnel1, $tunnel2);

        // The new tunnel (tunnel2) is now the active one
        $this->assertSame($tunnel2, $manager->getTunnelForServer('server-abc'));

        // The incumbent tunnel (tunnel1) is NOT closed yet
        $this->assertNotSame(Tunnel::STATUS_CLOSED, $tunnel1->status);
    }

    public function test_finalize_server_connection_closes_incumbent(): void
    {
        // HB-2.2: After JWT validation succeeds, finalizeServerConnection()
        // must close the incumbent tunnel that was stored during acceptServer().
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

        // First connection
        $tunnel1 = $manager->acceptServer('server-abc', $serverWs1);
        $tunnel1->relaySessionId = $sessionId;
        $tunnel1->status = Tunnel::STATUS_ACTIVE;

        // Second connection - incumbent stored, not closed
        $tunnel2 = $manager->acceptServer('server-abc', $serverWs2);

        // The incumbent tunnel should still be in PENDING (not closed yet)
        $this->assertNotSame(Tunnel::STATUS_CLOSED, $tunnel1->status);

        // Now finalize the connection (JWT validated successfully).
        // The incumbent tunnel's close() is called, which calls serverWs1->close().
        $manager->finalizeServerConnection('server-abc');

        // The incumbent tunnel should now be closed
        $this->assertSame(Tunnel::STATUS_CLOSED, $tunnel1->status);

        // The new tunnel should still be active
        $this->assertSame($tunnel2, $manager->getTunnelForServer('server-abc'));
    }

    public function test_finalize_server_connection_does_nothing_when_no_incumbent(): void
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

    public function test_get_tunnel_for_server_returns_tunnel_when_exists(): void
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

    public function test_get_tunnel_for_server_returns_null_when_not_found(): void
    {
        $manager = new TunnelManager(
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );

        $tunnel = $manager->getTunnelForServer('nonexistent');

        $this->assertNull($tunnel);
    }

    public function test_accept_client_returns_null_when_tunnel_not_found(): void
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

    public function test_accept_client_returns_null_when_tunnel_not_active(): void
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

    public function test_accept_client_creates_client_connection_when_tunnel_active(): void
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
    }

    public function test_close_tunnel_closes_server_and_removes_from_map(): void
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

    public function test_all_tunnels_yields_only_active_tunnels(): void
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

    public function test_get_active_tunnel_count_returns_correct_count(): void
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

    public function test_has_tunnel_returns_true_when_active(): void
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

    public function test_remove_tunnel_removes_from_map(): void
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
}
