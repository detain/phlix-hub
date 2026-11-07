<?php

/**
 * Phlix hub component: Runtime.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Runtime;

use Workerman\Events\Fiber as FiberLoop;
use Workerman\Events\Swoole as SwooleLoop;
use Workerman\Worker;

/**
 * One question, answered for the resident worker: can this process AWAIT?
 *
 * The hub's relay delivery machinery parks on coroutine primitives — the
 * proxy bridge waits for the server's reply on a `Channel::pop()` with a
 * timeout, and the streaming response sink parks on buffer-drain and
 * token-budget waits paced by `Timer::sleep()`. Both primitives are
 * loop-class-dependent, not extension-dependent:
 *
 *  - `Workerman\Coroutine\Channel` picks its driver from
 *    `Worker::$eventLoopClass` (vendor workerman/coroutine `src/Channel.php`):
 *    only the Swoole/Swow/Fiber classes get a real blocking driver; EVERY
 *    other value — including the degraded resident loops `Select` and `Event`
 *    that `Worker::initGlobalEvent()` assigns when ext-swoole is absent
 *    (start.php continues booting in that state on purpose) — silently falls
 *    back to the `Channel\Memory` driver, whose `pop()` returns `false`
 *    IMMEDIATELY when the channel is empty. The timeout argument is ignored,
 *    so "wait up to 30 s for the reply" becomes "answer wrong at once": a
 *    living tunnel reads as a timeout (504 `gateway.timeout`), and the sink's
 *    back-pressure park reads as a dead browser.
 *  - `Workerman\Timer::sleep()` yields only for `Fiber` and `Swoole` loop
 *    classes (vendor workerman/workerman `src/Timer.php`); its default arm is
 *    a raw `usleep()`, which freezes the entire resident HTTP worker for the
 *    whole pace-wait.
 *
 * The union of both requirements is exactly Swoole-or-Fiber, and that is the
 * verdict below. `Swow` is deliberately CLASSIFIED INCAPABLE even though the
 * vendor Channel does build a Swow driver: `Timer::sleep()` has no Swow arm,
 * so a Swow-loop hub would still freeze on every throttle pace. The hub
 * itself never configures Swow — start.php's healthy branch names
 * `Swoole::class` — so excluding it costs nothing real and keeps one gate
 * with one honest requirement.
 *
 * A `null` loop class means no event loop has been initialised (CLI,
 * bootstrap, unit harness) — a context the degraded misclassification cannot
 * occur in; resident degradation always leaves `Select` or `Event` stamped.
 * Fail-open there so the gate never becomes a boot-time dependency.
 *
 * @package Phlix\Hub\Runtime
 * @since 1.3.0
 */
final class CoroutineRuntime
{
    /**
     * Whether coroutine awaits in THIS process actually suspend and resume.
     *
     * True: a running loop that schedules fibers/coroutines (Swoole, Fiber),
     * or no loop yet (non-resident context). False: the degraded resident
     * loops (`Select`, `Event`) and the `Swow` gap documented above.
     */
    public static function isAwaitCapable(): bool
    {
        $loopClass = Worker::$eventLoopClass;
        if ($loopClass === null) {
            return true;
        }

        return $loopClass === SwooleLoop::class || $loopClass === FiberLoop::class;
    }
}
