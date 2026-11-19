<?php

/**
 * Phlix hub component: Relay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Relay;

use InvalidArgumentException;
use Phlix\Hub\Relay\InvalidFrameTypeException;
use Phlix\Shared\Relay\RelayFrame;
use Phlix\Shared\Relay\RelayFrameType;
use Phlix\Shared\Relay\RelayWireCodecInterface;

use function json_encode;
use function pack;
use function strlen;
use function unpack;

/**
 * Decodes binary WebSocket frames into RelayFrame objects.
 *
 * Implements {@see RelayWireCodecInterface} for the multiplexed relay protocol.
 *
 * Wire format (all integers big-endian):
 *   [4-byte sequence (uint32)][1-byte frame type][2-byte payload length (uint16)][N payload bytes]
 *
 * Maximum frame payload: 65535 bytes.
 *
 * @package Phlix\Hub\Relay
 */
final class FrameDecoder implements RelayWireCodecInterface
{
    /**
     * Maximum buffer size (128 KB = 2× max frame size).
     */
    public const MAX_BUFFER_SIZE = 131072;

    /**
     * L-3: sanity cap on how many complete frames one {@see decodeAll()} call
     * will drain. A WS message carrying more batched frames than this is
     * treated as a protocol violation (no legitimate peer packs thousands of
     * frames into a single message; the cap bounds per-message CPU work).
     */
    public const MAX_FRAMES_PER_MESSAGE = 4096;

    /**
     * Internal buffer for accumulating incoming bytes.
     *
     * @var string
     */
    private string $buffer = '';

    /**
     * @inheritDoc
     *
     * @throws InvalidArgumentException If the payload exceeds 65535 bytes.
     */
    public function encode(RelayFrameType $type, int $seq, string $payload): string
    {
        return self::encodeFrame($type, $seq, $payload);
    }

    /**
     * The single byte-emitting frame encoder of this repo.
     *
     * FRAME lane (scan finding C-06): the server repo carries the twin of
     * this encoder at phlix-server src/Hub/RelayMessageFramer.php::encodeFrame().
     * Both are pinned byte-identical by the triple corpus tests (hub
     * tests/Unit/Relay/FrameWireCorpusTest.php, server
     * tests/Unit/Hub/RelayMessageFramerWireCorpusTest.php and shared
     * tests/Relay/FrameCodecTest.php all pin the same wire digest literal).
     * The eventual single home is phlix-shared src/Relay/FrameCodec.php —
     * added by the FRAME lane, adoption scheduled for the v0.56.0 release;
     * until then each repo keeps its own copy and the corpus pins keep the
     * twins honest. The body now mirrors the server's byte-for-byte
     * (pack('C') replacing the former chr() leg — identical bytes for every
     * valid enum value); exception wording stays per-repo until adoption
     * harmonizes it (that harmonization is the release lane's disclosed job).
     *
     * @param RelayFrameType $type    Frame type.
     * @param int            $seq     32-bit unsigned sequence number.
     * @param string         $payload Raw byte payload.
     *
     * @return string Binary-encoded frame.
     *
     * @throws InvalidArgumentException If the payload exceeds 65535 bytes.
     */
    private static function encodeFrame(RelayFrameType $type, int $seq, string $payload): string
    {
        $len = strlen($payload);

        if ($len > 65535) {
            throw new InvalidArgumentException(
                sprintf('Payload exceeds maximum size of 65535 bytes (got %d)', $len),
            );
        }

        // [4-byte seq (big-endian uint32)][1-byte type][2-byte len (big-endian uint16)][payload]
        return pack('N', $seq)
            . pack('C', $type->value)
            . pack('n', $len)
            . $payload;
    }

    /**
     * @inheritDoc
     *
     * @see phlix-server src/Hub/RelayMessageFramer.php::encodeHello() — wire
     *      twin; shared home phlix-shared src/Relay/FrameCodec.php (v0.56.0 adoption).
     */
    public function encodeHello(string $enrollmentJwt, string $serverId): string
    {
        $payload = [
            'type' => 'hello',
            'enrollment_jwt' => $enrollmentJwt,
            'server_id' => $serverId,
        ];

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /**
     * @inheritDoc
     *
     * @see phlix-server src/Hub/RelayMessageFramer.php::encodeHelloAck() — wire
     *      twin; shared home phlix-shared src/Relay/FrameCodec.php (v0.56.0 adoption).
     */
    public function encodeHelloAck(string $relaySessionId, string $tunnelId): string
    {
        $payload = [
            'type' => 'hello_ack',
            'relay_session_id' => $relaySessionId,
            'tunnel_id' => $tunnelId,
        ];

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /**
     * @inheritDoc
     *
     * Returns null if the data is incomplete (less than 7 bytes for the header).
     *
     * @throws FrameBufferOverflowException If the accumulation buffer exceeds
     *         {@see MAX_BUFFER_SIZE} without a complete frame (H-R7).
     * @throws InvalidFrameTypeException If the frame type byte is unrecognized.
     */
    public function decode(string $bytes): ?RelayFrame
    {
        // Append new data to buffer
        $this->buffer .= $bytes;

        if (strlen($this->buffer) > self::MAX_BUFFER_SIZE) {
            // A dribbling / oversized-length peer grew the buffer past the hard
            // ceiling without ever completing a frame (H-R7). Drop the oversized
            // buffer immediately so we don't keep it resident, and raise a fatal
            // protocol violation — the consumer's existing InvalidFrameType catch
            // closes the tunnel/connection cleanly.
            $overflowSize = strlen($this->buffer);
            $this->buffer = '';
            throw new FrameBufferOverflowException($overflowSize, self::MAX_BUFFER_SIZE);
        }

        // Minimum frame is 7 bytes: 4 (seq) + 1 (type) + 2 (len) = 7
        if (strlen($this->buffer) < 7) {
            return null;
        }

        // Parse header: [4-byte seq][1-byte type][2-byte len]
        /** @var array{seq: int, type: int, len: int} $header */
        $header = unpack('Nseq/Ctype/nlen', $this->buffer);

        $seq = $header['seq'];
        $typeValue = $header['type'];
        $len = $header['len'];

        // Validate frame type
        if (!RelayFrameType::isValid($typeValue)) {
            $this->buffer = '';
            throw new InvalidFrameTypeException($typeValue, 'Unrecognized frame type');
        }

        // Total frame size = 7 bytes header + payload len
        $totalFrameSize = 7 + $len;

        if (strlen($this->buffer) < $totalFrameSize) {
            // Incomplete frame - keep buffering
            return null;
        }

        // Extract payload
        $payload = substr($this->buffer, 7, $len);

        // Remove consumed bytes from buffer
        $this->buffer = substr($this->buffer, $totalFrameSize);

        return new RelayFrame(
            RelayFrameType::fromValue($typeValue),
            $seq,
            $payload,
        );
    }

    /**
     * Decode every complete frame carried by one WebSocket message.
     *
     * L-3: {@see decode()} returns at most one frame per call, which was safe
     * only under the undocumented invariant "the encoder emits one frame per WS
     * message" (FrameEncoder::encode calls are never batched today). Any peer —
     * or future batching encoder — that concatenates frames into a single WS
     * message would silently stall every frame after the first, because the
     * residual stays in the internal buffer until the next message arrives.
     * This loop drains all complete frames (partial trailing bytes stay
     * buffered for the next call, exactly as with {@see decode()}).
     *
     * @param string $bytes Raw WS message payload.
     *
     * @return list<RelayFrame> Decoded frames in wire order; possibly empty
     *         (incomplete data still buffering).
     *
     * @throws FrameBufferOverflowException Propagated from {@see decode()}.
     * @throws InvalidFrameTypeException If a frame type is unrecognized, or if
     *         the message batches more than {@see MAX_FRAMES_PER_MESSAGE}
     *         frames (protocol violation — consumers close cleanly).
     *
     * @since 0.12.0
     */
    public function decodeAll(string $bytes): array
    {
        /** @var list<RelayFrame> $frames */
        $frames = [];

        while (true) {
            $frame = $this->decode($bytes);
            $bytes = '';

            if ($frame === null) {
                return $frames;
            }

            $frames[] = $frame;

            if (count($frames) >= self::MAX_FRAMES_PER_MESSAGE) {
                // Probe once more: if another complete frame is already
                // buffered, the peer batched past the sanity cap.
                if ($this->decode('') !== null) {
                    throw new InvalidFrameTypeException(
                        0,
                        sprintf('peer batched more than %d frames into one message', self::MAX_FRAMES_PER_MESSAGE),
                    );
                }
                return $frames;
            }
        }
    }

    /**
     * Reset the internal buffer.
     *
     * Useful when a session ends and we want to start fresh.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->buffer = '';
    }

    /**
     * Returns the number of bytes currently buffered.
     *
     * @return int
     */
    public function getBufferSize(): int
    {
        return strlen($this->buffer);
    }
}
