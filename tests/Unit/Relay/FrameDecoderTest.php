<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use InvalidArgumentException;
use Phlix\Hub\Relay\FrameDecoder;
use Phlix\Hub\Relay\InvalidFrameTypeException;
use Phlix\Shared\Relay\RelayFrame;
use Phlix\Shared\Relay\RelayFrameType;
use PHPUnit\Framework\TestCase;

class FrameDecoderTest extends TestCase
{
    private FrameDecoder $decoder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->decoder = new FrameDecoder();
    }

    /**
     * Helper: encode a frame manually for testing.
     */
    private function encodeFrame(RelayFrameType $type, int $seq, string $payload): string
    {
        return pack('N', $seq)
            . chr($type->value)
            . pack('n', strlen($payload))
            . $payload;
    }

    public function test_encode_decode_roundtrip_data_frame(): void
    {
        $payload = 'Hello, World!';
        $seq = 42;
        $type = RelayFrameType::DATA;

        $encoded = $this->decoder->encode($type, $seq, $payload);
        $decoded = $this->decoder->decode($encoded);

        $this->assertInstanceOf(RelayFrame::class, $decoded);
        $this->assertSame($type, $decoded->type);
        $this->assertSame($seq, $decoded->seq);
        $this->assertSame($payload, $decoded->payload);
    }

    public function test_encode_decode_roundtrip_all_frame_types(): void
    {
        $payload = 'test payload';
        $seq = 100;

        foreach (RelayFrameType::cases() as $type) {
            // Skip HELLO and HELLO_ACK - they use JSON text encoding
            if ($type === RelayFrameType::HELLO || $type === RelayFrameType::HELLO_ACK) {
                continue;
            }

            $encoded = $this->decoder->encode($type, $seq, $payload);
            $decoded = $this->decoder->decode($encoded);

            $this->assertInstanceOf(RelayFrame::class, $decoded, "Failed for type: {$type->label()}");
            $this->assertSame($type, $decoded->type);
            $this->assertSame($seq, $decoded->seq);
            $this->assertSame($payload, $decoded->payload);
        }
    }

    public function test_decode_returns_null_for_incomplete_header(): void
    {
        // Only 5 bytes (not enough for 7-byte header)
        $partial = pack('N', 123) . chr(0x05);

        $result = $this->decoder->decode($partial);

        $this->assertNull($result);
        $this->assertSame(5, $this->decoder->getBufferSize());
    }

    public function test_decode_returns_null_for_incomplete_payload(): void
    {
        // Header only (7 bytes): seq=1, type=DATA(0x05), len=100
        $header = pack('N', 1) . chr(0x05) . pack('n', 100);

        $result = $this->decoder->decode($header);

        $this->assertNull($result);
        $this->assertSame(7, $this->decoder->getBufferSize());
    }

    public function test_decode_extracts_complete_frame_from_buffer(): void
    {
        $payload = 'test';
        $seq = 1;
        $type = RelayFrameType::DATA;

        $frame = $this->encodeFrame($type, $seq, $payload);
        $extra = 'extra bytes';

        $result = $this->decoder->decode($frame . $extra);

        $this->assertInstanceOf(RelayFrame::class, $result);
        $this->assertSame($type, $result->type);
        $this->assertSame($seq, $result->seq);
        $this->assertSame($payload, $result->payload);
        $this->assertSame(strlen($extra), $this->decoder->getBufferSize());
    }

    public function test_decode_multiple_frames_in_one_chunk(): void
    {
        $frame1 = $this->encodeFrame(RelayFrameType::DATA, 1, 'A');
        $frame2 = $this->encodeFrame(RelayFrameType::DATA, 2, 'B');
        $frame3 = $this->encodeFrame(RelayFrameType::DATA, 3, 'C');

        // Decode first frame
        $result1 = $this->decoder->decode($frame1);
        $this->assertInstanceOf(RelayFrame::class, $result1);
        $this->assertSame(1, $result1->seq);

        // Decode second frame
        $result2 = $this->decoder->decode($frame2);
        $this->assertInstanceOf(RelayFrame::class, $result2);
        $this->assertSame(2, $result2->seq);

        // Decode third frame
        $result3 = $this->decoder->decode($frame3);
        $this->assertInstanceOf(RelayFrame::class, $result3);
        $this->assertSame(3, $result3->seq);
    }

    public function test_decode_empty_payload(): void
    {
        $frame = $this->encodeFrame(RelayFrameType::HEARTBEAT, 1, '');

        $result = $this->decoder->decode($frame);

        $this->assertInstanceOf(RelayFrame::class, $result);
        $this->assertSame('', $result->payload);
    }

    public function test_decode_max_payload_size(): void
    {
        $payload = str_repeat('x', 65535);
        $frame = $this->encodeFrame(RelayFrameType::DATA, 1, $payload);

        $result = $this->decoder->decode($frame);

        $this->assertInstanceOf(RelayFrame::class, $result);
        $this->assertSame($payload, $result->payload);
    }

    public function test_encode_throws_for_payload_exceeding_max(): void
    {
        $payload = str_repeat('x', 65536);

        $this->expectException(InvalidArgumentException::class);
        $this->decoder->encode(RelayFrameType::DATA, 1, $payload);
    }

    public function test_encode_hello_json(): void
    {
        $jwt = 'eyJhbGciOiJFUzI1NiJ9.test.test';
        $serverId = 'server-123';

        $result = $this->decoder->encodeHello($jwt, $serverId);

        $decoded = json_decode($result, true, 2, JSON_THROW_ON_ERROR);
        $this->assertSame('hello', $decoded['type']);
        $this->assertSame($jwt, $decoded['enrollment_jwt']);
        $this->assertSame($serverId, $decoded['server_id']);
    }

    public function test_encode_hello_ack_json(): void
    {
        $sessionId = 'session-456';
        $tunnelId = 'tunnel-789';

        $result = $this->decoder->encodeHelloAck($sessionId, $tunnelId);

        $decoded = json_decode($result, true, 2, JSON_THROW_ON_ERROR);
        $this->assertSame('hello_ack', $decoded['type']);
        $this->assertSame($sessionId, $decoded['relay_session_id']);
        $this->assertSame($tunnelId, $decoded['tunnel_id']);
    }

    public function test_reset_clears_buffer(): void
    {
        // Add partial data (5 bytes - not enough for 7-byte header)
        $partial = pack('N', 123) . chr(0x05);
        $this->decoder->decode($partial);
        $this->assertSame(5, $this->decoder->getBufferSize());

        // Reset
        $this->decoder->reset();
        $this->assertSame(0, $this->decoder->getBufferSize());
    }

    public function test_get_buffer_size_initially_zero(): void
    {
        $decoder = new FrameDecoder();
        $this->assertSame(0, $decoder->getBufferSize());
    }

    public function test_decode_boundary_size_0(): void
    {
        $frame = $this->encodeFrame(RelayFrameType::DATA, 1, '');
        $result = $this->decoder->decode($frame);

        $this->assertInstanceOf(RelayFrame::class, $result);
        $this->assertSame('', $result->payload);
    }

    public function test_decode_boundary_size_1(): void
    {
        $frame = $this->encodeFrame(RelayFrameType::DATA, 1, 'x');
        $result = $this->decoder->decode($frame);

        $this->assertInstanceOf(RelayFrame::class, $result);
        $this->assertSame('x', $result->payload);
    }

    public function test_decode_boundary_size_255(): void
    {
        $payload = str_repeat('x', 255);
        $frame = $this->encodeFrame(RelayFrameType::DATA, 1, $payload);
        $result = $this->decoder->decode($frame);

        $this->assertInstanceOf(RelayFrame::class, $result);
        $this->assertSame($payload, $result->payload);
    }

    public function test_decode_boundary_size_256(): void
    {
        $payload = str_repeat('x', 256);
        $frame = $this->encodeFrame(RelayFrameType::DATA, 1, $payload);
        $result = $this->decoder->decode($frame);

        $this->assertInstanceOf(RelayFrame::class, $result);
        $this->assertSame($payload, $result->payload);
    }

    public function test_decode_boundary_size_65534(): void
    {
        $payload = str_repeat('x', 65534);
        $frame = $this->encodeFrame(RelayFrameType::DATA, 1, $payload);
        $result = $this->decoder->decode($frame);

        $this->assertInstanceOf(RelayFrame::class, $result);
        $this->assertSame($payload, $result->payload);
    }

    public function test_decode_boundary_size_65535(): void
    {
        $payload = str_repeat('x', 65535);
        $frame = $this->encodeFrame(RelayFrameType::DATA, 1, $payload);
        $result = $this->decoder->decode($frame);

        $this->assertInstanceOf(RelayFrame::class, $result);
        $this->assertSame($payload, $result->payload);
    }

    public function test_decode_invalid_frame_type_throws(): void
    {
        // 4-byte seq (0x00000001) + invalid type (0xFF) + 2-byte len (0x0000)
        $invalidFrame = pack('N', 1) . chr(0xFF) . pack('n', 0);

        $this->expectException(InvalidFrameTypeException::class);
        $this->expectExceptionCode(1011);

        $this->decoder->decode($invalidFrame);
    }
}
