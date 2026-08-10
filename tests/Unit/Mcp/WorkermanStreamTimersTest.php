<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Mcp;

use Phlix\Hub\Mcp\McpStreamTimers;
use Phlix\Hub\Mcp\WorkermanStreamTimers;
use Phlix\Hub\Tests\Support\WorkermanTimerRuntimeControl;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see WorkermanStreamTimers} — the production
 * {@see McpStreamTimers} (S63).
 *
 * ## The behaviour under test is the FAILURE path
 *
 * `Workerman\Timer::add()` has three arms, and which one runs depends on
 * process-global state this suite pins with {@see WorkermanTimerRuntimeControl}
 * (see that trait for why it is a one-way latch and why suite order used to
 * decide the answer). One of those arms THROWS: outside a workerman runtime it
 * raises `RuntimeException('Timer can only be used in workerman running
 * environment')`.
 *
 * The adapter swallows that and reports `false`. That is the whole reason the
 * class exists rather than a bare static call: a keep-alive that cannot be
 * scheduled must degrade the SSE stream to "no keep-alive", never take down the
 * HTTP worker that was in the middle of serving it. Both arms are asserted here,
 * so "returns false" is known to be a caught exception rather than an accident.
 *
 * @package Phlix\Hub\Tests\Unit\Mcp
 *
 * @covers \Phlix\Hub\Mcp\WorkermanStreamTimers
 */
final class WorkermanStreamTimersTest extends TestCase
{
    use WorkermanTimerRuntimeControl;

    /**
     * Outside a workerman runtime, scheduling REPORTS failure rather than
     * throwing.
     */
    public function testSchedulingOutsideAWorkerReportsFalseInsteadOfThrowing(): void
    {
        $this->forceNoWorkermanRuntime();
        self::assertStringStartsWith(
            'throw',
            $this->timerAddOutcome(),
            'the fixture did not put Timer::add() in its throwing arm, so this test proves nothing.',
        );

        $ran = false;
        $result = (new WorkermanStreamTimers())->add(15, static function () use (&$ran): void {
            $ran = true;
        }, true);

        self::assertFalse($result, 'a timer that could not be scheduled must be reported, not thrown.');
        self::assertFalse($ran, 'the callback ran without a timer.');
    }

    /**
     * The SUCCEEDING control: inside a (simulated) workerman runtime the same
     * call schedules a real timer and returns its id.
     *
     * Without this row an adapter that returned `false` unconditionally — i.e.
     * one that never scheduled anything — would satisfy the test above
     * perfectly, and every SSE stream would silently have no keep-alive.
     */
    public function testSchedulingInsideAWorkerReturnsATimerId(): void
    {
        $this->forceWorkermanRuntime();
        self::assertSame(
            'success',
            $this->timerAddOutcome(),
            'the fixture did not put Timer::add() in its succeeding arm.',
        );

        $timers = new WorkermanStreamTimers();
        $before = $this->pendingTimerTaskCount();

        $id = $timers->add(15, static function (): void {
        }, true);

        self::assertIsInt($id);
        self::assertGreaterThan($before, $this->pendingTimerTaskCount(), 'no task was actually queued.');

        // ...and cancelling it removes the task again, which is the operation
        // the SSE stream depends on to not leak one timer per dropped client.
        $timers->del($id);
        self::assertSame($before, $this->pendingTimerTaskCount(), 'del() left the task queued.');
    }

    /**
     * Cancelling an id that is not (or is no longer) live is a no-op, not an
     * exception.
     *
     * This runs from a connection-close path, which may fire after a one-shot
     * deadline timer has already consumed itself. Throwing there would surface
     * as an unhandled error while a client was merely hanging up.
     */
    public function testCancellingAnUnknownTimerIsSilent(): void
    {
        $this->forceNoWorkermanRuntime();

        (new WorkermanStreamTimers())->del(999999);

        $this->addToAssertionCount(1);
    }
}
