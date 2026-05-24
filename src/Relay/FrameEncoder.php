<?php

declare(strict_types=1);

namespace Phlix\Hub\Relay;

use InvalidArgumentException;
use Phlix\Shared\Relay\RelayFrame;
use Phlix\Shared\Relay\RelayFrameType;
use Phlix\Shared\Relay\RelayWireCodecInterface;

use function json_encode;
use function pack;
use function strlen;

/**
 * Encodes RelayFrame objects into binary WebSocket frames.
 *
 * Provides static factory methods for each frame type to simplify encoding.
 * Uses the shared {@see RelayWireCodecInterface} for the underlying binary format.
 *
 * Wire format (all integers big-endian):
 *   [4-byte sequence (uint32)][1-byte frame type][2-byte payload length (uint16)][N payload bytes]
 *
 * Maximum frame payload: 65535 bytes.
 *
 * @package Phlix\Hub\Relay
 * @since 0.5.0
 */
final class FrameEncoder
{
    /**
     * @var RelayWireCodecInterface
     */
    private RelayWireCodecInterface $codec;

    /**
     * @param RelayWireCodecInterface|null $codec Optional codec (uses FrameDecoder if null).
     */
    public function __construct(?RelayWireCodecInterface $codec = null)
    {
        $this->codec = $codec ?? new FrameDecoder();
    }

    /**
     * Encode a generic frame.
     *
     * @param RelayFrameType $type    Frame type.
     * @param int            $seq     32-bit unsigned sequence number.
     * @param string         $payload Raw byte payload.
     *
     * @return string Binary-encoded frame.
     *
     * @throws InvalidArgumentException If the payload exceeds 65535 bytes.
     */
    public function encode(RelayFrameType $type, int $seq, string $payload): string
    {
        return $this->codec->encode($type, $seq, $payload);
    }

    /**
     * Encode a HELLO handshake message (JSON text, sent before binary mode).
     *
     * @param string $enrollmentJwt JWT from stored enrollment.
     * @param string $serverId     Server UUID.
     *
     * @return string JSON text frame (not binary-encoded).
     */
    public function encodeHello(string $enrollmentJwt, string $serverId): string
    {
        return $this->codec->encodeHello($enrollmentJwt, $serverId);
    }

    /**
     * Encode a HELLO_ACK handshake response (JSON text, sent before binary mode).
     *
     * @param string $relaySessionId Relay session UUID assigned by hub.
     * @param string $tunnelId        Tunnel UUID assigned by hub.
     *
     * @return string JSON text frame (not binary-encoded).
     */
    public function encodeHelloAck(string $relaySessionId, string $tunnelId): string
    {
        return $this->codec->encodeHelloAck($relaySessionId, $tunnelId);
    }

    /**
     * Encode a HELLO handshake message statically.
     *
     * @param string $enrollmentJwt JWT from stored enrollment.
     * @param string $serverId     Server UUID.
     *
     * @return string JSON text frame (not binary-encoded).
     */
    public static function encodeHelloStatic(string $enrollmentJwt, string $serverId): string
    {
        return (new self())->encodeHello($enrollmentJwt, $serverId);
    }

    /**
     * Encode a HELLO_ACK handshake response statically.
     *
     * @param string $relaySessionId Relay session UUID assigned by hub.
     * @param string $tunnelId        Tunnel UUID assigned by hub.
     *
     * @return string JSON text frame (not binary-encoded).
     */
    public static function encodeHelloAckStatic(string $relaySessionId, string $tunnelId): string
    {
        return (new self())->encodeHelloAck($relaySessionId, $tunnelId);
    }

    /**
     * Encode a DATA frame (raw bytes forwarded verbatim).
     *
     * @param int    $seq     32-bit unsigned sequence number.
     * @param string $payload Raw byte payload (max 65535 bytes).
     *
     * @return string Binary-encoded frame.
     *
     * @throws InvalidArgumentException If the payload exceeds 65535 bytes.
     */
    public static function data(int $seq, string $payload): string
    {
        return (new self())->encode(RelayFrameType::DATA, $seq, $payload);
    }

    /**
     * Encode a CLIENT_CONNECT frame (notification that a client connected).
     *
     * @param int    $seq      32-bit unsigned sequence number.
     * @param string $clientId Client UUID.
     * @param string $sessionId Relay session UUID.
     *
     * @return string Binary-encoded frame.
     */
    public static function clientConnect(int $seq, string $clientId, string $sessionId): string
    {
        $payload = json_encode([
            'client_id' => $clientId,
            'session_id' => $sessionId,
        ], JSON_THROW_ON_ERROR);

        return (new self())->encode(RelayFrameType::CLIENT_CONNECT, $seq, $payload);
    }

    /**
     * Encode a CLIENT_DISCONNECT frame (notification that a client disconnected).
     *
     * @param int    $seq      32-bit unsigned sequence number.
     * @param string $clientId Client UUID.
     *
     * @return string Binary-encoded frame.
     */
    public static function clientDisconnect(int $seq, string $clientId): string
    {
        $payload = json_encode([
            'client_id' => $clientId,
        ], JSON_THROW_ON_ERROR);

        return (new self())->encode(RelayFrameType::CLIENT_DISCONNECT, $seq, $payload);
    }

    /**
     * Encode a HEARTBEAT frame (keep-alive probe/ack).
     *
     * @param int $seq 32-bit unsigned sequence number.
     *
     * @return string Binary-encoded frame.
     */
    public static function heartbeat(int $seq): string
    {
        return (new self())->encode(RelayFrameType::HEARTBEAT, $seq, '');
    }

    /**
     * Encode a DISCONNECTED frame (server tunnel closed, client should reconnect).
     *
     * @param int    $seq    32-bit unsigned sequence number.
     * @param string $reason Human-readable close reason.
     *
     * @return string Binary-encoded frame.
     */
    public static function disconnected(int $seq, string $reason): string
    {
        $payload = json_encode([
            'reason' => $reason,
        ], JSON_THROW_ON_ERROR);

        return (new self())->encode(RelayFrameType::DISCONNECTED, $seq, $payload);
    }

    /**
     * Encode an ERROR frame.
     *
     * @param int    $seq    32-bit unsigned sequence number.
     * @param string $code  Error code.
     * @param string $message Error message.
     *
     * @return string Binary-encoded frame.
     */
    public static function error(int $seq, string $code, string $message): string
    {
        $payload = json_encode([
            'code' => $code,
            'message' => $message,
        ], JSON_THROW_ON_ERROR);

        return (new self())->encode(RelayFrameType::ERROR, $seq, $payload);
    }

    /**
     * Create a RelayFrame from encoded bytes.
     *
     * @param string $bytes Raw encoded bytes.
     *
     * @return RelayFrame|null Decoded frame, or null if incomplete.
     */
    public static function decode(string $bytes): ?RelayFrame
    {
        $decoder = new FrameDecoder();
        return $decoder->decode($bytes);
    }
}
