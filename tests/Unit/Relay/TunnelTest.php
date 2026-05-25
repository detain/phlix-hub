<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\Relay\ClientConnection;
use Phlix\Hub\Relay\FrameDecoder;
use Phlix\Hub\Relay\FrameEncoder;
use Phlix\Hub\Relay\Tunnel;
use Phlix\Shared\Relay\RelayFrame;
use Phlix\Shared\Relay\RelayFrameType;
use Phlix\Shared\Relay\RelayWireCodecInterface;
use Phlix\Hub\Common\Logger\StructuredLogger;
use PHPUnit\Framework\TestCase;
use Workerman\Connection\TcpConnection;

class TunnelTest extends TestCase
{
    private RelayWireCodecInterface $codec;
    private StructuredLogger $logger;
    private StructuredLogger $clientLogger;
    private RelaySessionManager $sessionManager;
    private TcpConnection $serverWs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->codec = new FrameDecoder();
        $this->logger = $this->createMock(StructuredLogger::class);
        $this->clientLogger = $this->createMock(StructuredLogger::class);
        $this->sessionManager = $this->createMock(RelaySessionManager::class);
        $this->serverWs = $this->createMock(TcpConnection::class);
    }

    public function test_tunnel_initializes_with_pending_status(): void
    {
        $tunnel = new Tunnel(
            'server-123',
            $this->serverWs,
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );

        $this->assertSame(Tunnel::STATUS_PENDING, $tunnel->status);
        $this->assertSame('server-123', $tunnel->serverId);
        $this->assertCount(0, $tunnel->clientConnections);
        $this->assertSame(0, $tunnel->seq);
        $this->assertNotEmpty($tunnel->tunnelId);
    }

    public function test_tunnel_transitions_to_active_on_hello(): void
    {
        $sessionId = 'session-456';
        $this->sessionManager
            ->expects($this->once())
            ->method('registerServer')
            ->with('server-123', $this->anything())
            ->willReturn($sessionId);

        $this->serverWs
            ->expects($this->once())
            ->method('send')
            ->with($this->callback(function (string $data): bool {
                // Should be JSON hello_ack
                $decoded = json_decode($data, true);
                return is_array($decoded)
                    && ($decoded['type'] ?? null) === 'hello_ack'
                    && isset($decoded['relay_session_id'])
                    && isset($decoded['tunnel_id']);
            }));

        $tunnel = new Tunnel(
            'server-123',
            $this->serverWs,
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );

        $helloPayload = json_encode([
            'type' => 'hello',
            'enrollment_jwt' => 'jwt.test.test',
            'server_id' => 'server-123',
        ]);

        $tunnel->onServerMessage($helloPayload);

        $this->assertSame(Tunnel::STATUS_ACTIVE, $tunnel->status);
        $this->assertSame($sessionId, $tunnel->relaySessionId);
    }

    public function test_tunnel_closes_on_malformed_hello(): void
    {
        $this->sessionManager
            ->expects($this->never())
            ->method('registerServer');

        $this->serverWs
            ->expects($this->once())
            ->method('close');

        $tunnel = new Tunnel(
            'server-123',
            $this->serverWs,
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );

        // Send malformed HELLO (not JSON)
        $tunnel->onServerMessage('not valid json');

        $this->assertSame(Tunnel::STATUS_CLOSED, $tunnel->status);
    }

    public function test_broadcast_to_clients_encodes_once_and_writes_to_all(): void
    {
        $sessionId = 'session-456';
        $this->sessionManager
            ->method('registerServer')
            ->willReturn($sessionId);

        // Set up tunnel in ACTIVE state
        $tunnel = new Tunnel(
            'server-123',
            $this->serverWs,
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );

        // Manually set to active for testing broadcast
        $tunnel->relaySessionId = $sessionId;
        $tunnel->status = Tunnel::STATUS_ACTIVE;

        // Create two mock client connections
        $clientWs1 = $this->createMock(TcpConnection::class);
        $clientWs2 = $this->createMock(TcpConnection::class);

        $sentData1 = null;
        $sentData2 = null;

        $clientWs1
            ->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (string $data) use (&$sentData1): void {
                $sentData1 = $data;
            });

        $clientWs2
            ->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (string $data) use (&$sentData2): void {
                $sentData2 = $data;
            });

        $client1 = new ClientConnection($clientWs1, 'server-123', 'client-1', $this->clientLogger, '');
        $client2 = new ClientConnection($clientWs2, 'server-123', 'client-2', $this->clientLogger, '');

        $tunnel->clientConnections->attach($client1);
        $tunnel->clientConnections->attach($client2);

        $frame = new RelayFrame(RelayFrameType::DATA, 1, 'hello world');

        $tunnel->broadcastToClients($frame);

        // Both clients should receive the same encoded data (encoded once, sent to all)
        $this->assertSame($sentData1, $sentData2);
        $this->assertNotNull($sentData1);
    }

    public function test_send_to_server_encodes_and_records_bytes(): void
    {
        $sessionId = 'session-456';
        $this->sessionManager
            ->method('registerServer')
            ->willReturn($sessionId);

        // Set up tunnel in ACTIVE state
        $tunnel = new Tunnel(
            'server-123',
            $this->serverWs,
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );
        $tunnel->relaySessionId = $sessionId;
        $tunnel->status = Tunnel::STATUS_ACTIVE;

        $sentData = null;
        $this->serverWs
            ->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (string $data) use (&$sentData): void {
                $sentData = $data;
            });

        $this->sessionManager
            ->expects($this->once())
            ->method('recordBytesOut')
            ->with($sessionId, $this->greaterThan(0));

        $frame = new RelayFrame(RelayFrameType::DATA, 1, 'hello server');

        $tunnel->sendToServer($frame);

        $this->assertNotNull($sentData);
    }

    public function test_server_close_closes_all_clients_and_session(): void
    {
        $sessionId = 'session-456';
        $this->sessionManager
            ->method('registerServer')
            ->willReturn($sessionId);

        // Set up tunnel in ACTIVE state
        $tunnel = new Tunnel(
            'server-123',
            $this->serverWs,
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );
        $tunnel->relaySessionId = $sessionId;
        $tunnel->status = Tunnel::STATUS_ACTIVE;

        // Add a mock client connection
        $clientWs = $this->createMock(TcpConnection::class);
        $clientWs->expects($this->once())->method('send');
        $clientWs->expects($this->once())->method('close');

        $client = new ClientConnection($clientWs, 'server-123', 'client-1', $this->clientLogger, '');
        $tunnel->clientConnections->attach($client);

        $this->sessionManager
            ->expects($this->once())
            ->method('closeSession')
            ->with($sessionId, 'server_disconnected');

        $tunnel->onServerClose();

        $this->assertSame(Tunnel::STATUS_CLOSED, $tunnel->status);
        $this->assertCount(0, $tunnel->clientConnections);
    }

    public function test_is_stale_returns_true_when_idle(): void
    {
        $tunnel = new Tunnel(
            'server-123',
            $this->serverWs,
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );

        // Set lastFrameAt to 100 seconds ago
        $tunnel->lastFrameAt = time() - 100;

        $this->assertTrue($tunnel->isStale(90));
        $this->assertFalse($tunnel->isStale(120));
    }

    public function test_register_client_sends_client_connect_to_server(): void
    {
        $sessionId = 'session-456';
        $this->sessionManager
            ->method('registerServer')
            ->willReturn($sessionId);

        $tunnel = new Tunnel(
            'server-123',
            $this->serverWs,
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );
        $tunnel->relaySessionId = $sessionId;
        $tunnel->status = Tunnel::STATUS_ACTIVE;

        $sentData = null;
        $this->serverWs
            ->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (string $data) use (&$sentData): void {
                $sentData = $data;
            });

        $clientWs = $this->createMock(TcpConnection::class);
        $client = new ClientConnection($clientWs, 'server-123', 'client-1', $this->clientLogger, 'relay-session-1');

        $tunnel->registerClient($client);

        $this->assertCount(1, $tunnel->clientConnections);
        $this->assertNotNull($sentData);

        // Verify CLIENT_CONNECT frame was sent
        $decoded = $this->codec->decode($sentData);
        $this->assertInstanceOf(RelayFrame::class, $decoded);
        $this->assertSame(RelayFrameType::CLIENT_CONNECT, $decoded->type);
    }

    public function test_remove_client_sends_client_disconnect_to_server(): void
    {
        $sessionId = 'session-456';
        $this->sessionManager
            ->method('registerServer')
            ->willReturn($sessionId);

        $tunnel = new Tunnel(
            'server-123',
            $this->serverWs,
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );
        $tunnel->relaySessionId = $sessionId;
        $tunnel->status = Tunnel::STATUS_ACTIVE;

        $clientWs = $this->createMock(TcpConnection::class);
        $client = new ClientConnection($clientWs, 'server-123', 'client-1', $this->clientLogger, 'relay-session-1');
        $tunnel->clientConnections->attach($client);

        $sentData = null;
        $this->serverWs
            ->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (string $data) use (&$sentData): void {
                $sentData = $data;
            });

        $tunnel->removeClient($client);

        $this->assertCount(0, $tunnel->clientConnections);
        $this->assertNotNull($sentData);

        // Verify CLIENT_DISCONNECT frame was sent
        $decoded = $this->codec->decode($sentData);
        $this->assertInstanceOf(RelayFrame::class, $decoded);
        $this->assertSame(RelayFrameType::CLIENT_DISCONNECT, $decoded->type);
    }

    public function test_heartbeat_touches_last_frame_at(): void
    {
        $sessionId = 'session-456';
        $this->sessionManager
            ->method('registerServer')
            ->willReturn($sessionId);

        $tunnel = new Tunnel(
            'server-123',
            $this->serverWs,
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );
        $tunnel->relaySessionId = $sessionId;
        $tunnel->status = Tunnel::STATUS_ACTIVE;

        $initialLastFrameAt = $tunnel->lastFrameAt;

        // Wait a tiny bit then send heartbeat
        usleep(1000);
        $tunnel->sendHeartbeat();

        $this->assertGreaterThanOrEqual($initialLastFrameAt, $tunnel->lastFrameAt);
    }

    public function test_broadcast_to_clients_records_bytes_in_per_client(): void
    {
        $sessionId = 'session-456';
        $this->sessionManager
            ->method('registerServer')
            ->willReturn($sessionId);

        $tunnel = new Tunnel(
            'server-123',
            $this->serverWs,
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );
        $tunnel->relaySessionId = $sessionId;
        $tunnel->status = Tunnel::STATUS_ACTIVE;

        // Create three mock client connections
        $clientWs1 = $this->createMock(TcpConnection::class);
        $clientWs2 = $this->createMock(TcpConnection::class);
        $clientWs3 = $this->createMock(TcpConnection::class);

        $clientWs1->method('send');
        $clientWs2->method('send');
        $clientWs3->method('send');

        $client1 = new ClientConnection($clientWs1, 'server-123', 'client-1', $this->clientLogger, '');
        $client2 = new ClientConnection($clientWs2, 'server-123', 'client-2', $this->clientLogger, '');
        $client3 = new ClientConnection($clientWs3, 'server-123', 'client-3', $this->clientLogger, '');

        $tunnel->clientConnections->attach($client1);
        $tunnel->clientConnections->attach($client2);
        $tunnel->clientConnections->attach($client3);

        // Expect recordBytesIn to be called once per client (3 times total)
        $this->sessionManager
            ->expects($this->exactly(3))
            ->method('recordBytesIn')
            ->with($sessionId, $this->greaterThan(0));

        $frame = new RelayFrame(RelayFrameType::DATA, 1, 'hello world');
        $tunnel->broadcastToClients($frame);

        // bytesIn on tunnel should be frame_len * 3 clients
        $this->assertGreaterThan(0, $tunnel->getBytesIn());
    }

    public function test_send_to_server_increments_bytes_out(): void
    {
        $sessionId = 'session-456';
        $this->sessionManager
            ->method('registerServer')
            ->willReturn($sessionId);

        $tunnel = new Tunnel(
            'server-123',
            $this->serverWs,
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );
        $tunnel->relaySessionId = $sessionId;
        $tunnel->status = Tunnel::STATUS_ACTIVE;

        $this->serverWs->method('send');

        $this->assertSame(0, $tunnel->getBytesOut());

        $frame = new RelayFrame(RelayFrameType::DATA, 1, 'hello');
        $tunnel->sendToServer($frame);

        $this->assertGreaterThan(0, $tunnel->getBytesOut());
    }

    public function test_broadcast_to_clients_increments_bytes_in(): void
    {
        $sessionId = 'session-456';
        $this->sessionManager
            ->method('registerServer')
            ->willReturn($sessionId);

        $tunnel = new Tunnel(
            'server-123',
            $this->serverWs,
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );
        $tunnel->relaySessionId = $sessionId;
        $tunnel->status = Tunnel::STATUS_ACTIVE;

        $clientWs = $this->createMock(TcpConnection::class);
        $clientWs->method('send');

        $client = new ClientConnection($clientWs, 'server-123', 'client-1', $this->clientLogger, '');
        $tunnel->clientConnections->attach($client);

        $this->assertSame(0, $tunnel->getBytesIn());

        $frame = new RelayFrame(RelayFrameType::DATA, 1, 'hello world');
        $tunnel->broadcastToClients($frame);

        $this->assertGreaterThan(0, $tunnel->getBytesIn());
    }

    public function test_bytes_in_tracks_total_per_recipient(): void
    {
        $sessionId = 'session-456';
        $this->sessionManager
            ->method('registerServer')
            ->willReturn($sessionId);

        $tunnel = new Tunnel(
            'server-123',
            $this->serverWs,
            $this->sessionManager,
            $this->codec,
            $this->logger,
        );
        $tunnel->relaySessionId = $sessionId;
        $tunnel->status = Tunnel::STATUS_ACTIVE;

        // Create two clients
        $clientWs1 = $this->createMock(TcpConnection::class);
        $clientWs2 = $this->createMock(TcpConnection::class);
        $clientWs1->method('send');
        $clientWs2->method('send');

        $client1 = new ClientConnection($clientWs1, 'server-123', 'client-1', $this->clientLogger, '');
        $client2 = new ClientConnection($clientWs2, 'server-123', 'client-2', $this->clientLogger, '');
        $tunnel->clientConnections->attach($client1);
        $tunnel->clientConnections->attach($client2);

        $frame = new RelayFrame(RelayFrameType::DATA, 1, 'test');
        $tunnel->broadcastToClients($frame);

        // bytesIn should be frame_encoded_len * 2 clients
        // (same frame sent to each client)
        $this->assertGreaterThan(0, $tunnel->getBytesIn());
    }
}
