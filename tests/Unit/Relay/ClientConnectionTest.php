<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Relay\ClientConnection;
use Phlix\Hub\Relay\FrameDecoder;
use Phlix\Hub\Relay\FrameEncoder;
use Phlix\Hub\Relay\Tunnel;
use Phlix\Shared\Relay\RelayFrame;
use Phlix\Shared\Relay\RelayFrameType;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Workerman\Connection\TcpConnection;

/**
 * Unit tests for {@see ClientConnection}.
 *
 * @package Phlix\Hub\Tests\Unit\Relay
 */
final class ClientConnectionTest extends TestCase
{
    private StructuredLogger&MockObject $logger;
    private TcpConnection&MockObject $clientWs;

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

    public function testDefaultsToUnlimitedWithNoThrottleBucket(): void
    {
        // S42: no throttle argument → Unlimited (0), so no bucket is built and
        // the send path bypasses throttling entirely (no timer overhead).
        $client = new ClientConnection(
            $this->clientWs,
            'server-123',
            'client-456',
            $this->logger,
        );

        $this->assertSame(0, $client->throttleBps);
        $this->assertFalse($client->isThrottled());
        $this->assertNull($client->throttleBucket);
        $this->assertNull($client->throttleDrainTimerId);
    }

    public function testZeroThrottleIsUnlimitedAndNeverThrottles(): void
    {
        // Explicit 0 = Unlimited (S41 semantics) — no bucket, isThrottled false.
        $client = new ClientConnection(
            $this->clientWs,
            'server-123',
            'client-456',
            $this->logger,
            '',
            0,
        );

        $this->assertFalse($client->isThrottled());
        $this->assertNull($client->throttleBucket);
    }

    public function testThrottledConnectionBuildsBucketSizedFromBps(): void
    {
        // 8 Mbps = 8_000_000 bits/sec ÷ 8 = 1_000_000 bytes/sec sustained rate;
        // capacity = that rate × the documented 1-second burst window
        // = 1_000_000 bytes.
        //
        // ⚠ S191 — the expected capacity is a hand-derived LITERAL, NOT
        // `1_000_000.0 * ClientConnection::THROTTLE_BURST_SECONDS`. An expectation
        // multiplied by the constant under test self-adjusts to any value the
        // production code takes, so it could never detect a change to the burst
        // window (measured: it passed with the window mutated 1.0 → 5.0). If the
        // window is deliberately changed, update this literal and the comment.
        $client = new ClientConnection(
            $this->clientWs,
            'server-123',
            'client-456',
            $this->logger,
            '',
            8_000_000,
        );

        $this->assertSame(8_000_000, $client->throttleBps);
        $this->assertTrue($client->isThrottled());
        $this->assertNotNull($client->throttleBucket);
        $this->assertSame(1_000_000.0, $client->throttleBucket->ratePerSecond());
        $this->assertSame(1_000_000.0, $client->throttleBucket->capacity());
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
        $this->addToAssertionCount(1);
    }

    public function testOnMessageClosesConnectionOnFrameBufferOverflow(): void
    {
        $client = new ClientConnection(
            $this->clientWs,
            'server-123',
            'client-456',
            $this->logger,
        );

        // The client relay path must NOT leak a decode-buffer overflow (H-R7)
        // out of the Workerman message callback — it closes the connection.
        $this->clientWs->expects($this->once())->method('close');

        $decoder = new FrameDecoder();

        // A single binary message larger than MAX_BUFFER_SIZE (131072) trips the
        // accumulation guard; onMessage must swallow it and close cleanly.
        $client->onMessage(str_repeat("\x00", 140000), $decoder);

        // No exception escaped — the guard closed the connection instead.
        $this->addToAssertionCount(1);
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

        // L-3b: the payload must use the canonical FrameEncoder::error shape
        // {code, message} — NOT the old ad-hoc {error} literal.
        /** @var array<string, mixed> $payload */
        $payload = json_decode($decoded->payload, true, 4, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);
        $this->assertSame('invalid_frame_type', $payload['code'] ?? null);
        $this->assertArrayNotHasKey('error', $payload);
        $this->assertIsString($payload['message'] ?? null);
    }

    public function testOnMessageForwardsEveryFrameOfABatchedMessage(): void
    {
        // L-3 regression: a peer that packs MULTIPLE relay frames into one WS
        // message must not stall — every complete frame is processed, not just
        // the first.
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

        $forwarded = [];
        $serverWs
            ->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(function (mixed $data) use (&$forwarded): bool {
                $this->assertIsString($data);
                $forwarded[] = $data;
                return true;
            });

        $encoder = new FrameEncoder();
        $batch = $encoder->encode(RelayFrameType::DATA, 1, 'first')
            . $encoder->encode(RelayFrameType::DATA, 1, 'second');

        $client->onMessage($batch, new FrameDecoder());

        $this->assertCount(2, $forwarded, 'both batched DATA frames must be forwarded');
        $this->assertSame('first', (new FrameDecoder())->decode($forwarded[0])?->payload);
        $this->assertSame('second', (new FrameDecoder())->decode($forwarded[1])?->payload);
    }

    public function testOnMessageKeepsPartialTailBufferedAcrossBatchedMessages(): void
    {
        // L-3 regression: when a batched message ends mid-frame, the complete
        // frames still flow and the partial tail stays buffered for the next
        // message (which completes it).
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

        $forwarded = [];
        $serverWs
            ->method('send')
            ->willReturnCallback(function (mixed $data) use (&$forwarded): bool {
                $this->assertIsString($data);
                $forwarded[] = $data;
                return true;
            });

        $encoder = new FrameEncoder();
        $complete = $encoder->encode(RelayFrameType::DATA, 1, 'one');
        $tail = $encoder->encode(RelayFrameType::DATA, 1, 'two');

        $decoder = new FrameDecoder();
        // Message 1: one complete frame + the first 5 bytes of the next.
        $client->onMessage($complete . substr($tail, 0, 5), $decoder);
        $this->assertCount(1, $forwarded, 'the complete frame must flow immediately');

        // Message 2: the remaining bytes finish the second frame.
        $client->onMessage(substr($tail, 5), $decoder);
        $this->assertCount(2, $forwarded, 'the completed tail frame must flow next');
        $this->assertSame('two', (new FrameDecoder())->decode($forwarded[1])?->payload);
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

        $this->addToAssertionCount(1);
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
