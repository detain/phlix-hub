<?php

/**
 * Phlix hub component: Runtime tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Runtime;

use Phlix\Hub\Runtime\CoroutineRuntime;
use PHPUnit\Framework\TestCase;
use Workerman\Events\Event as EventLoop;
use Workerman\Events\EventInterface;
use Workerman\Events\Fiber as FiberLoop;
use Workerman\Events\Select as SelectLoop;
use Workerman\Events\Swoole as SwooleLoop;
use Workerman\Events\Swow as SwowLoop;
use Workerman\Worker;

/**
 * Truth table for {@see CoroutineRuntime::isAwaitCapable()} (review hub__asphp
 * SH-047 family). The resident HTTP worker's streaming/buffered relay paths
 * await on coroutine primitives (`Channel::pop()` with a timeout,
 * `Timer::sleep()`); under the degraded event loops those calls DO NOT await —
 * the vendor Channel silently falls back to the non-blocking Memory driver
 * (workerman/coroutine `Channel.php` driver match on `Worker::$eventLoopClass`)
 * and `Timer::sleep()` falls back to a raw `usleep()` on its default arm. The
 * gate's verdict is what keeps that machinery off the request path, so the
 * per-loop classification is the law under test, not an implementation detail.
 *
 * `Worker::$eventLoopClass` is a process-global public static and is NOT
 * snapshotted by WorkermanTimerRuntimeControl, so every case here saves and
 * restores it explicitly.
 *
 * @package Phlix\Hub\Tests\Unit\Runtime
 */
final class CoroutineRuntimeTest extends TestCase
{
    /**
     * `Worker::$eventLoopClass` is typed `class-string<EventInterface>|null`
     * — save/restore through a docblock-typed slot (a plain ?string property
     * would widen the vendor's narrow static type at the restore).
     *
     * @var class-string<EventInterface>|null
     */
    private $previousLoop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousLoop = Worker::$eventLoopClass;
    }

    protected function tearDown(): void
    {
        Worker::$eventLoopClass = $this->previousLoop;
        parent::tearDown();
    }

    public function testSwooleLoopIsAwaitCapable(): void
    {
        Worker::$eventLoopClass = SwooleLoop::class;
        self::assertTrue(CoroutineRuntime::isAwaitCapable());
    }

    public function testFiberLoopIsAwaitCapable(): void
    {
        Worker::$eventLoopClass = FiberLoop::class;
        self::assertTrue(CoroutineRuntime::isAwaitCapable());
    }

    public function testSelectLoopIsNotAwaitCapable(): void
    {
        // The degraded resident value when ext-event is absent: Select drives
        // no coroutine scheduler, so Channel/Timer awaits degrade to
        // instant-false / blocking usleep.
        Worker::$eventLoopClass = SelectLoop::class;
        self::assertFalse(CoroutineRuntime::isAwaitCapable());
    }

    public function testEventLoopIsNotAwaitCapable(): void
    {
        // The other degraded resident value (ext-event present): same
        // absence of a coroutine scheduler.
        Worker::$eventLoopClass = EventLoop::class;
        self::assertFalse(CoroutineRuntime::isAwaitCapable());
    }

    public function testSwowLoopIsNotAwaitCapable(): void
    {
        // Deliberate exclusion. The vendor Channel does build a real Swow
        // driver, but `Timer::sleep()` has no Swow arm — on the Swow loop the
        // throttle pacing still falls to the blocking `usleep()` default.
        // The hub never configures Swow (start.php names only Swoole), so
        // classifying it with the degraded loops is the honest conservative
        // verdict: one gate, one requirement (every await primitive used).
        Worker::$eventLoopClass = SwowLoop::class;
        self::assertFalse(CoroutineRuntime::isAwaitCapable());
    }

    public function testNullLoopBeforeRunIsTreatedAsAwaitCapable(): void
    {
        // Outside a running event loop `Worker::$eventLoopClass` is still
        // null (Worker::initGlobalEvent() assigns it at runAll time). The
        // gate exists for resident worker requests; a null here is a
        // non-resident context (CLI, bootstrap, unit harness) where no
        // degraded-loop misclassification can happen, so it must not flip
        // the resident behavior by accident — fail open by design.
        Worker::$eventLoopClass = null;
        self::assertTrue(CoroutineRuntime::isAwaitCapable());
    }
}
