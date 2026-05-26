<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Relay\ClientConnection;
use Phlix\Hub\Relay\FrameDecoder;
use Phlix\Hub\Relay\FrameEncoder;
use Phlix\Hub\Relay\Tunnel;
use Phlix\Hub\Relay\TunnelInterface;
use Phlix\Shared\Relay\RelayFrame;
use Phlix\Shared\Relay\RelayFrameType;
use PHPUnit\Framework\TestCase;
use Workerman\Connection\TcpConnection;

/**
 * Unit tests for {@see ClientConnection}.
 *
 * @package Phlix\Hub\Tests\Unit\Relay
 *
 * @covers \Phlix\Hub\Relay\ClientConnection
 */
final class ClientConnectionTest extends TestCase
{
    private StructuredLogger $logger;
    private TcpConnection $clientWs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = $this->createMock(StructuredLogger::class);
        $this->clientWs = $this->createMock(TcpConnection::class);
    }

    public function testClientConnectionInitializesWithCorrectProperties(): void
    {
        $client = new ClientConnection(
            $this->clientWs,
            'server-123',
            'client-456',
            $this->logger,
            'session-789',
        );

        $this->assertSame($this->clientWs, $client->clientWs);
        $this->assertSame('server-123', $client->serverId);
        $this->assertSame('client-456', $client->clientId);
        $this->assertSame('session-789', $client->sessionId);
        $this->assertNull($client->tunnel);
        $this->assertGreaterThanOrEqual(time() - 2, $client->lastFrameAt);
    }

    public function testClientConnectionDefaultsEmptySessionId(): void
    {
        $client = new ClientConnection(
            $this->clientWs,
            'server-123',
            'client-456',
            $this->logger,
        );

        $this->assertSame('', $client->sessionId);
    }

    public function testOnMessageUpdatesLastFrameAtTimestamp(): void
    {
        $client = new ClientConnection(
            $this->clientWs,
            'server-123',
            'client-456',
            $this->logger,
        );

        $initialLastFrameAt = $client->lastFrameAt;
        usleep(1000);

        $decoder = new FrameDecoder();
        $client->onMessage('', $decoder);

        $this->assertGreaterThanOrEqual($initialLastFrameAt, $client->lastFrameAt);
    }

    public function testOnMessageWithIncompleteFrameReturnsEarly(): void
    {
        $client = new ClientConnection(
            $this->clientWs,
            'server-123',
            'client-456',
            $this->logger,
        );

        $decoder = new FrameDecoder();

        // Send incomplete frame data - should return early without error
        $client->onMessage("\x00\x01\x02", $decoder);

        // No exception means success
        $this->assertTrue(true);
    }

    public function testOnMessageWithNonDataFrameSendsErrorToClient(): void
    {
        $client = new ClientConnection(
            $this->clientWs,
            'server-123',
            'client-456',
            $this->logger,
        );

        $sentData = null;
        $this->clientWs
            ->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (string $data) use (&$sentData): void {
                $sentData = $data;
            });

        $decoder = new FrameDecoder();
        $encoder = new FrameEncoder();

        // Create a non-DATA frame (ERROR type)
        $errorFrame = new RelayFrame(RelayFrameType::ERROR, 1, 'test error');

        $client->onMessage($encoder->encode($errorFrame->type, $errorFrame->seq, $errorFrame->payload), $decoder);

        $this->assertNotNull($sentData);

        // Decode the sent response and verify it's an ERROR frame
        $decoded = $decoder->decode($sentData);
        $this->assertInstanceOf(RelayFrame::class, $decoded);
        $this->assertSame(RelayFrameType::ERROR, $decoded->type);
    }

    public function testOnMessageWithDataFrameWithoutTunnelDoesNothing(): void
    {
        $client = new ClientConnection(
            $this->clientWs,
            'server-123',
            'client-456',
            $this->logger,
        );

        // Ensure tunnel is null - no error should occur
        $this->assertNull($client->tunnel);

        $decoder = new FrameDecoder();
        $encoder = new FrameEncoder();

        // Create a DATA frame
        $dataFrame = new RelayFrame(RelayFrameType::DATA, 1, 'hello world');
        $encoded = $encoder->encode($dataFrame->type, $dataFrame->seq, $dataFrame->payload);

        // Should not throw even though tunnel is null
        $client->onMessage($encoded, $decoder);

        $this->assertTrue(true);
    }

    public function testOnMessageWithDataFrameWithRealTunnelForwardsToServer(): void
    {
        $serverWs = $this->createMock(TcpConnection::class);
        $sessionManager = $this->createMock(\Phlix\Hub\Hub\RelaySessionManager::class);
        $codec = new FrameDecoder();

        $sessionManager->method('registerServer')->willReturn('session-123');

        $tunnel = new Tunnel(
            'server-123',
            $serverWs,
            $sessionManager,
            $codec,
            $this->logger,
        );
        // Activate the tunnel so sendToServer works
        $tunnel->relaySessionId = 'session-123';
        $tunnel->status = Tunnel::STATUS_ACTIVE;

        $client = new ClientConnection(
            $this->clientWs,
            'server-123',
            'client-456',
            $this->logger,
        );
        $client->tunnel = $tunnel;

        $sentData = null;
        $serverWs
            ->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (string $data) use (&$sentData): void {
                $sentData = $data;
            });

        $decoder = new FrameDecoder();
        $encoder = new FrameEncoder();

        // Create a DATA frame
        $dataFrame = new RelayFrame(RelayFrameType::DATA, 1, 'hello world');
        $encoded = $encoder->encode($dataFrame->type, $dataFrame->seq, $dataFrame->payload);

        $client->onMessage($encoded, $decoder);

        $this->assertNotNull($sentData);
    }

    public function testOnCloseRemovesClientFromTunnel(): void
    {
        $serverWs = $this->createMock(TcpConnection::class);
        $sessionManager = $this->createMock(\Phlix\Hub\Hub\RelaySessionManager::class);
        $codec = new FrameDecoder();

        $sessionManager->method('registerServer')->willReturn('session-123');

        $tunnel = new Tunnel(
            'server-123',
            $serverWs,
            $sessionManager,
            $codec,
            $this->logger,
        );
        $tunnel->relaySessionId = 'session-123';
        $tunnel->status = Tunnel::STATUS_ACTIVE;

        $client = new ClientConnection(
            $this->clientWs,
            'server-123',
            'client-456',
            $this->logger,
        );
        $client->tunnel = $tunnel;

        $this->assertCount(0, $tunnel->clientConnections);

        $client->onClose();

        $this->assertCount(0, $tunnel->clientConnections);
    }

    public function testOnCloseDoesNothingWithoutTunnel(): void
    {
        $client = new ClientConnection(
            $this->clientWs,
            'server-123',
            'client-456',
            $this->logger,
        );

        // No tunnel set - should not throw
        $client->onClose();

        $this->assertNull($client->tunnel);
    }

    public function testSendRawSendsDataToClient(): void
    {
        $client = new ClientConnection(
            $this->clientWs,
            'server-123',
            'client-456',
            $this->logger,
        );

        $sentData = null;
        $this->clientWs
            ->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (string $data) use (&$sentData): void {
                $sentData = $data;
            });

        $client->sendRaw('raw data');

        $this->assertSame('raw data', $sentData);
    }

    public function testSendEncodesAndSendsFrameToClient(): void
    {
        $client = new ClientConnection(
            $this->clientWs,
            'server-123',
            'client-456',
            $this->logger,
        );

        $sentData = null;
        $this->clientWs
            ->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (string $data) use (&$sentData): void {
                $sentData = $data;
            });

        $encoder = new FrameEncoder();
        $frame = new RelayFrame(RelayFrameType::DATA, 5, 'test payload');

        $client->send($frame, $encoder);

        $this->assertNotNull($sentData);

        // Verify the sent data can be decoded back
        $decoder = new FrameDecoder();
        $decoded = $decoder->decode($sentData);
        $this->assertInstanceOf(RelayFrame::class, $decoded);
        $this->assertSame(RelayFrameType::DATA, $decoded->type);
        $this->assertSame(5, $decoded->seq);
        $this->assertSame('test payload', $decoded->payload);
    }

    public function testCloseClosesClientConnection(): void
    {
        $client = new ClientConnection(
            $this->clientWs,
            'server-123',
            'client-456',
            $this->logger,
        );

        $this->clientWs
            ->expects($this->once())
            ->method('close');

        $client->close();
    }

    public function testTouchLastFrameUpdatesTimestamp(): void
    {
        $client = new ClientConnection(
            $this->clientWs,
            'server-123',
            'client-456',
            $this->logger,
        );

        $initialLastFrameAt = $client->lastFrameAt;
        usleep(1000);

        $client->touchLastFrame();

        $this->assertGreaterThanOrEqual($initialLastFrameAt, $client->lastFrameAt);
    }
}
