<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use InvalidArgumentException;
use Phlix\Hub\Relay\FrameDecoder;
use Phlix\Hub\Relay\FrameEncoder;
use Phlix\Hub\Tests\Support\DecodedJsonAssertions;
use Phlix\Shared\Relay\RelayFrame;
use Phlix\Shared\Relay\RelayFrameType;
use PHPUnit\Framework\TestCase;

class FrameEncoderTest extends TestCase
{
    use DecodedJsonAssertions;

    /**
     * Helper: decode a frame using the same codec.
     */
    private function decodeFrame(string $bytes): ?RelayFrame
    {
        $decoder = new FrameDecoder();
        return $decoder->decode($bytes);
    }

    public function testDataFrameRoundtrip(): void
    {
        $payload = 'Hello, World!';
        $seq = 42;

        $encoded = FrameEncoder::data($seq, $payload);
        $decoded = $this->decodeFrame($encoded);

        $this->assertInstanceOf(RelayFrame::class, $decoded);
        $this->assertSame(RelayFrameType::DATA, $decoded->type);
        $this->assertSame($seq, $decoded->seq);
        $this->assertSame($payload, $decoded->payload);
    }

    public function testClientConnectFrameRoundtrip(): void
    {
        $seq = 10;
        $clientId = 'client-abc-123';
        $sessionId = 'session-xyz-789';

        $encoded = FrameEncoder::clientConnect($seq, $clientId, $sessionId);
        $decoded = $this->decodeFrame($encoded);

        $this->assertInstanceOf(RelayFrame::class, $decoded);
        $this->assertSame(RelayFrameType::CLIENT_CONNECT, $decoded->type);
        $this->assertSame($seq, $decoded->seq);

        $payload = self::arrayNode(json_decode($decoded->payload, true, 2, JSON_THROW_ON_ERROR));
        $this->assertSame($clientId, $payload['client_id']);
        $this->assertSame($sessionId, $payload['session_id']);
    }

    public function testClientDisconnectFrameRoundtrip(): void
    {
        $seq = 11;
        $clientId = 'client-abc-123';

        $encoded = FrameEncoder::clientDisconnect($seq, $clientId);
        $decoded = $this->decodeFrame($encoded);

        $this->assertInstanceOf(RelayFrame::class, $decoded);
        $this->assertSame(RelayFrameType::CLIENT_DISCONNECT, $decoded->type);
        $this->assertSame($seq, $decoded->seq);

        $payload = self::arrayNode(json_decode($decoded->payload, true, 2, JSON_THROW_ON_ERROR));
        $this->assertSame($clientId, $payload['client_id']);
    }

    public function testHeartbeatFrameRoundtrip(): void
    {
        $seq = 99;

        $encoded = FrameEncoder::heartbeat($seq);
        $decoded = $this->decodeFrame($encoded);

        $this->assertInstanceOf(RelayFrame::class, $decoded);
        $this->assertSame(RelayFrameType::HEARTBEAT, $decoded->type);
        $this->assertSame($seq, $decoded->seq);
        $this->assertSame('', $decoded->payload);
    }

    public function testDisconnectedFrameRoundtrip(): void
    {
        $seq = 12;
        $reason = 'server_replaced';

        $encoded = FrameEncoder::disconnected($seq, $reason);
        $decoded = $this->decodeFrame($encoded);

        $this->assertInstanceOf(RelayFrame::class, $decoded);
        $this->assertSame(RelayFrameType::DISCONNECTED, $decoded->type);
        $this->assertSame($seq, $decoded->seq);

        $payload = self::arrayNode(json_decode($decoded->payload, true, 2, JSON_THROW_ON_ERROR));
        $this->assertSame($reason, $payload['reason']);
    }

    public function testErrorFrameRoundtrip(): void
    {
        $seq = 13;
        $code = 'PROTOCOL_ERROR';
        $message = 'Invalid frame type received';

        $encoded = FrameEncoder::error($seq, $code, $message);
        $decoded = $this->decodeFrame($encoded);

        $this->assertInstanceOf(RelayFrame::class, $decoded);
        $this->assertSame(RelayFrameType::ERROR, $decoded->type);
        $this->assertSame($seq, $decoded->seq);

        $payload = self::arrayNode(json_decode($decoded->payload, true, 2, JSON_THROW_ON_ERROR));
        $this->assertSame($code, $payload['code']);
        $this->assertSame($message, $payload['message']);
    }

    public function testEncodeHelloJson(): void
    {
        $jwt = 'eyJhbGciOiJFUzI1NiJ9.test.test';
        $serverId = 'server-123';

        $encoder = new FrameEncoder();
        $result = $encoder->encodeHello($jwt, $serverId);

        $decoded = self::arrayNode(json_decode($result, true, 2, JSON_THROW_ON_ERROR));
        $this->assertSame('hello', $decoded['type']);
        $this->assertSame($jwt, $decoded['enrollment_jwt']);
        $this->assertSame($serverId, $decoded['server_id']);
    }

    public function testEncodeHelloAckJson(): void
    {
        $sessionId = 'session-456';
        $tunnelId = 'tunnel-789';

        $encoder = new FrameEncoder();
        $result = $encoder->encodeHelloAck($sessionId, $tunnelId);

        $decoded = self::arrayNode(json_decode($result, true, 2, JSON_THROW_ON_ERROR));
        $this->assertSame('hello_ack', $decoded['type']);
        $this->assertSame($sessionId, $decoded['relay_session_id']);
        $this->assertSame($tunnelId, $decoded['tunnel_id']);
    }

    public function testInstanceEncode(): void
    {
        $encoder = new FrameEncoder();
        $payload = 'test';
        $seq = 5;

        $encoded = $encoder->encode(RelayFrameType::DATA, $seq, $payload);
        $decoded = $this->decodeFrame($encoded);

        $this->assertInstanceOf(RelayFrame::class, $decoded);
        $this->assertSame($seq, $decoded->seq);
        $this->assertSame($payload, $decoded->payload);
    }

    public function testStaticDecodeHelper(): void
    {
        $payload = 'test payload';
        $seq = 42;

        // Encode using static method
        $encoded = FrameEncoder::data($seq, $payload);

        // Decode using static helper
        $decoded = FrameEncoder::decode($encoded);

        $this->assertInstanceOf(RelayFrame::class, $decoded);
        $this->assertSame($payload, $decoded->payload);
        $this->assertSame($seq, $decoded->seq);
    }

    public function testStaticDecodeReturnsNullForIncomplete(): void
    {
        // Only 5 bytes — not enough for 7-byte header (4 seq + 1 type + 2 len minimum)
        $result = FrameEncoder::decode('abc');

        $this->assertNull($result);
    }

    public function testEncoderAcceptsCustomCodec(): void
    {
        $customDecoder = new FrameDecoder();
        $encoder = new FrameEncoder($customDecoder);

        $payload = 'custom codec test';
        $seq = 100;

        $encoded = $encoder->encode(RelayFrameType::DATA, $seq, $payload);
        $decoded = $customDecoder->decode($encoded);

        $this->assertInstanceOf(RelayFrame::class, $decoded);
        $this->assertSame($payload, $decoded->payload);
    }

    public function testDataThrowsForPayloadExceedingMax(): void
    {
        $payload = str_repeat('x', 65536);

        $this->expectException(InvalidArgumentException::class);
        FrameEncoder::data(1, $payload);
    }
}
