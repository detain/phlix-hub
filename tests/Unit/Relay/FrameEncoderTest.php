<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use InvalidArgumentException;
use Phlix\Hub\Relay\FrameDecoder;
use Phlix\Hub\Relay\FrameEncoder;
use Phlix\Shared\Relay\RelayFrame;
use Phlix\Shared\Relay\RelayFrameType;
use PHPUnit\Framework\TestCase;

class FrameEncoderTest extends TestCase
{
    /**
     * Helper: decode a frame using the same codec.
     */
    private function decodeFrame(string $bytes): ?RelayFrame
    {
        $decoder = new FrameDecoder();
        return $decoder->decode($bytes);
    }

    public function test_data_frame_roundtrip(): void
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

    public function test_client_connect_frame_roundtrip(): void
    {
        $seq = 10;
        $clientId = 'client-abc-123';
        $sessionId = 'session-xyz-789';

        $encoded = FrameEncoder::clientConnect($seq, $clientId, $sessionId);
        $decoded = $this->decodeFrame($encoded);

        $this->assertInstanceOf(RelayFrame::class, $decoded);
        $this->assertSame(RelayFrameType::CLIENT_CONNECT, $decoded->type);
        $this->assertSame($seq, $decoded->seq);

        $payload = json_decode($decoded->payload, true, 2, JSON_THROW_ON_ERROR);
        $this->assertSame($clientId, $payload['client_id']);
        $this->assertSame($sessionId, $payload['session_id']);
    }

    public function test_client_disconnect_frame_roundtrip(): void
    {
        $seq = 11;
        $clientId = 'client-abc-123';

        $encoded = FrameEncoder::clientDisconnect($seq, $clientId);
        $decoded = $this->decodeFrame($encoded);

        $this->assertInstanceOf(RelayFrame::class, $decoded);
        $this->assertSame(RelayFrameType::CLIENT_DISCONNECT, $decoded->type);
        $this->assertSame($seq, $decoded->seq);

        $payload = json_decode($decoded->payload, true, 2, JSON_THROW_ON_ERROR);
        $this->assertSame($clientId, $payload['client_id']);
    }

    public function test_heartbeat_frame_roundtrip(): void
    {
        $seq = 99;

        $encoded = FrameEncoder::heartbeat($seq);
        $decoded = $this->decodeFrame($encoded);

        $this->assertInstanceOf(RelayFrame::class, $decoded);
        $this->assertSame(RelayFrameType::HEARTBEAT, $decoded->type);
        $this->assertSame($seq, $decoded->seq);
        $this->assertSame('', $decoded->payload);
    }

    public function test_disconnected_frame_roundtrip(): void
    {
        $seq = 12;
        $reason = 'server_replaced';

        $encoded = FrameEncoder::disconnected($seq, $reason);
        $decoded = $this->decodeFrame($encoded);

        $this->assertInstanceOf(RelayFrame::class, $decoded);
        $this->assertSame(RelayFrameType::DISCONNECTED, $decoded->type);
        $this->assertSame($seq, $decoded->seq);

        $payload = json_decode($decoded->payload, true, 2, JSON_THROW_ON_ERROR);
        $this->assertSame($reason, $payload['reason']);
    }

    public function test_error_frame_roundtrip(): void
    {
        $seq = 13;
        $code = 'PROTOCOL_ERROR';
        $message = 'Invalid frame type received';

        $encoded = FrameEncoder::error($seq, $code, $message);
        $decoded = $this->decodeFrame($encoded);

        $this->assertInstanceOf(RelayFrame::class, $decoded);
        $this->assertSame(RelayFrameType::ERROR, $decoded->type);
        $this->assertSame($seq, $decoded->seq);

        $payload = json_decode($decoded->payload, true, 2, JSON_THROW_ON_ERROR);
        $this->assertSame($code, $payload['code']);
        $this->assertSame($message, $payload['message']);
    }

    public function test_encode_hello_json(): void
    {
        $jwt = 'eyJhbGciOiJFUzI1NiJ9.test.test';
        $serverId = 'server-123';

        $encoder = new FrameEncoder();
        $result = $encoder->encodeHello($jwt, $serverId);

        $decoded = json_decode($result, true, 2, JSON_THROW_ON_ERROR);
        $this->assertSame('hello', $decoded['type']);
        $this->assertSame($jwt, $decoded['enrollment_jwt']);
        $this->assertSame($serverId, $decoded['server_id']);
    }

    public function test_encode_hello_ack_json(): void
    {
        $sessionId = 'session-456';
        $tunnelId = 'tunnel-789';

        $encoder = new FrameEncoder();
        $result = $encoder->encodeHelloAck($sessionId, $tunnelId);

        $decoded = json_decode($result, true, 2, JSON_THROW_ON_ERROR);
        $this->assertSame('hello_ack', $decoded['type']);
        $this->assertSame($sessionId, $decoded['relay_session_id']);
        $this->assertSame($tunnelId, $decoded['tunnel_id']);
    }

    public function test_instance_encode(): void
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

    public function test_static_decode_helper(): void
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

    public function test_static_decode_returns_null_for_incomplete(): void
    {
        $result = FrameEncoder::decode('not enough bytes');

        $this->assertNull($result);
    }

    public function test_encoder_accepts_custom_codec(): void
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

    public function test_data_throws_for_payload_exceeding_max(): void
    {
        $payload = str_repeat('x', 65536);

        $this->expectException(InvalidArgumentException::class);
        FrameEncoder::data(1, $payload);
    }
}
