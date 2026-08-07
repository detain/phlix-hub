<?php

/**
 * Phlix hub component: Mcp.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Mcp;

/**
 * The two timer operations {@see McpSseStream} needs, behind an interface (S63).
 *
 * ## Why this exists rather than calling `Workerman\Timer` directly
 *
 * An SSE stream is a long-lived connection whose whole behaviour IS its timing:
 * a keep-alive every N seconds, a hard close at the lifetime ceiling, and —
 * the part that actually matters in a resident-memory worker — cancelling both
 * of those the moment the client disconnects. A timer that outlives its
 * connection is a leak that grows with every dropped client, and it is exactly
 * the kind of defect a unit test can catch and a code review cannot.
 *
 * Calling `Workerman\Timer::add()` statically would make all of that untestable
 * (a test would have to install real timers, in a process with no event loop,
 * where Workerman falls back to a `pcntl` alarm). Behind this interface a test
 * drives the callbacks by hand and asserts the cancellations happened.
 *
 * @package Phlix\Hub\Mcp
 * @since   S63 (MCP SSE/protocol correctness + flagged playback tool)
 */
interface McpStreamTimers
{
    /**
     * Schedule `$callback`.
     *
     * @param int      $intervalSeconds Delay, and repeat period when persistent.
     * @param callable $callback        Work to run. Must not block.
     * @param bool     $persistent      True to repeat, false to fire once.
     *
     * @return int|false The timer id, or false when it could not be scheduled.
     */
    public function add(int $intervalSeconds, callable $callback, bool $persistent): int|false;

    /**
     * Cancel a timer previously returned by {@see add()}.
     *
     * @param int $timerId The id to cancel.
     */
    public function del(int $timerId): void;
}
