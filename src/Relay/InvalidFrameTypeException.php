<?php

/**
 * Phlix hub component: Relay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Relay;

use RuntimeException;

use function dechex;

/**
 * Exception thrown when an invalid WebSocket frame type is received.
 *
 * Per RFC 6455 §7.4.1, an invalid frame type should close the tunnel
 * with status 1011 (Protocol Error).
 *
 * Not final: {@see FrameBufferOverflowException} extends it so every existing
 * `catch (InvalidFrameTypeException)` boundary that already closes the tunnel
 * on an undecodable frame also handles a buffer-overflow attack (H-R7) without
 * a new catch clause.
 *
 * @package Phlix\Hub\Relay
 */
class InvalidFrameTypeException extends RuntimeException
{
    /**
     * @param int    $type  The invalid frame type byte value.
     * @param string $reason Optional human-readable reason for the error.
     */
    public function __construct(int $type, string $reason = '')
    {
        // Late static binding: subclasses ({@see FrameBufferOverflowException})
        // override formatMessage() to produce a message that is not framed as an
        // "invalid frame type".
        parent::__construct(static::formatMessage($type, $reason), 1011);
    }

    /**
     * Build the exception message. Overridable so subclasses can describe a
     * different protocol violation while reusing the shared close semantics.
     *
     * @param int    $type   The invalid frame type byte value.
     * @param string $reason Optional human-readable reason.
     *
     * @return string
     */
    protected static function formatMessage(int $type, string $reason): string
    {
        return $reason !== ''
            ? 'Invalid frame type 0x' . dechex($type) . ': ' . $reason
            : 'Invalid frame type 0x' . dechex($type);
    }
}
