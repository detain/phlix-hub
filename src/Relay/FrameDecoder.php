<?php

declare(strict_types=1);

namespace Phlix\Hub\Relay;

use InvalidArgumentException;
use Phlix\Hub\Relay\InvalidFrameTypeException;
use Phlix\Shared\Relay\RelayFrame;
use Phlix\Shared\Relay\RelayFrameType;
use Phlix\Shared\Relay\RelayWireCodecInterface;

use function chr;
use function json_encode;
use function ord;
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
        if (strlen($payload) > 65535) {
            throw new InvalidArgumentException(
                sprintf('Payload exceeds maximum size of 65535 bytes (got %d)', strlen($payload)),
            );
        }

        // [4-byte seq (big-endian uint32)][1-byte type][2-byte len (big-endian uint16)][payload]
        return pack('N', $seq)
            . chr($type->value)
            . pack('n', strlen($payload))
            . $payload;
    }

    /**
     * @inheritDoc
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
     */
    public function decode(string $bytes): ?RelayFrame
    {
        // Append new data to buffer
        $this->buffer .= $bytes;

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
