<?php

/**
 * Phlix hub component: Relay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Relay;

use function sprintf;

/**
 * Exception thrown when a {@see FrameDecoder}'s accumulation buffer exceeds the
 * hard ceiling ({@see FrameDecoder::MAX_BUFFER_SIZE}) without ever completing a
 * frame.
 *
 * A malicious or malfunctioning peer can otherwise dribble bytes (or advertise a
 * large payload length and never send the body) to grow the per-connection
 * buffer without bound and exhaust worker memory (finding H-R7). Extends
 * {@see InvalidFrameTypeException} so the SAME tunnel-close path that already
 * handles an undecodable frame tears the connection down cleanly — the overflow
 * is treated as a fatal protocol violation, not an escaping fatal error.
 *
 * @package Phlix\Hub\Relay
 */
final class FrameBufferOverflowException extends InvalidFrameTypeException
{
    /**
     * @param int $bufferSize    The buffer size (bytes) that tripped the cap.
     * @param int $maxBufferSize The configured maximum buffer size (bytes).
     */
    public function __construct(
        public readonly int $bufferSize,
        public readonly int $maxBufferSize,
    ) {
        parent::__construct(
            0,
            sprintf('%d bytes buffered without a complete frame (max %d)', $bufferSize, $maxBufferSize),
        );
    }

    /**
     * @inheritDoc
     */
    protected static function formatMessage(int $type, string $reason): string
    {
        return 'Relay frame buffer overflow: ' . $reason;
    }
}
