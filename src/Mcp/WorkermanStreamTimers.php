<?php

/**
 * Phlix hub component: Mcp.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Mcp;

use Throwable;
use Workerman\Timer;

/**
 * The production {@see McpStreamTimers}: Workerman's event-loop timers (S63).
 *
 * A thin adapter and nothing else — every decision about WHEN to schedule and
 * when to cancel lives in {@see McpSseStream}, so that logic stays testable
 * while this class stays too small to be wrong.
 *
 * `Timer::add()` throws when no event loop has been installed (i.e. outside a
 * running worker). That is caught and reported as `false` rather than allowed
 * to escape: a keep-alive that could not be scheduled must degrade the stream
 * to "no keep-alive", never take down the HTTP worker that was serving it.
 *
 * @package Phlix\Hub\Mcp
 * @since   S63 (MCP SSE/protocol correctness + flagged playback tool)
 */
final class WorkermanStreamTimers implements McpStreamTimers
{
    /**
     * {@inheritDoc}
     */
    public function add(int $intervalSeconds, callable $callback, bool $persistent): int|false
    {
        try {
            return Timer::add((float) $intervalSeconds, $callback, [], $persistent);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function del(int $timerId): void
    {
        try {
            Timer::del($timerId);
        } catch (Throwable) {
            // Cancelling an already-fired one-shot is not an error worth
            // propagating into a connection-close path.
        }
    }
}
