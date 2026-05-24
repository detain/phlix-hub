<?php

declare(strict_types=1);

namespace Phlix\Hub\Relay;

use RuntimeException;

/**
 * Exception thrown when an invalid WebSocket frame type is received.
 *
 * Per RFC 6455 §7.4.1, an invalid frame type should close the tunnel
 * with status 1011 (Protocol Error).
 *
 * @package Phlix\Hub\Relay
 * @since 0.5.0
 */
final class InvalidFrameTypeException extends RuntimeException
{
    /**
     * @param int    $type  The invalid frame type byte value.
     * @param string $reason Optional human-readable reason for the error.
     */
    public function __construct(int $type, string $reason = '')
    {
        $msg = $reason !== ''
            ? "Invalid frame type 0x" . dechex($type) . ": $reason"
            : "Invalid frame type 0x" . dechex($type);
        parent::__construct($msg, 1011);
    }
}
