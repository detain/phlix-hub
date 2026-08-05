<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use ReflectionProperty;
use Workerman\Events\EventInterface;
use Workerman\Timer;
use Workerman\Worker;

/**
 * Put `Workerman\Timer::add()` into a KNOWN arm, deterministically, in any suite
 * order.
 *
 * ## Why this exists
 *
 * `Timer::add()` (vendor/workerman/workerman/src/Timer.php:141-174) picks one of
 * three arms:
 *
 * 1. `Timer::$event !== null` → delegate to the event driver (`repeat`/`delay`);
 * 2. `Timer::$event === null` **and** `Worker::getAllWorkers() === []` → **throw**
 *    `RuntimeException('Timer can only be used in workerman running environment')`;
 * 3. `Timer::$event === null` **and** the registry is NON-empty → the pcntl
 *    task-table path, which **succeeds** and returns a timer id.
 *
 * Under PHPUnit there is never an event loop, so `Timer::$event` is null (see the
 * docblock in `tests/bootstrap.php`) and the arm is decided purely by whether
 * `Worker::$workers` — `protected static array`, `Worker.php:422` — is empty.
 *
 * That registry is a **one-way latch** for the life of the process: the `Worker`
 * constructor registers `static::$workers[$this->workerId] = $this`
 * (`Worker.php:566`) and the only `unset()` (`Worker.php:1725`) lives in the
 * forked-child branch of `forkOneWorkerForLinux()`, which PHPUnit never reaches.
 * Production `start()` methods construct real `Worker`s — e.g.
 * {@see \Phlix\Hub\Relay\ClientRelayWorker::start()} (`ClientRelayWorker.php:185`)
 * and {@see \Phlix\Hub\Relay\RelayWorker::start()} — and the tests that call them
 * (`tests/Unit/Relay/ClientRelayWorkerTest.php`,
 * `tests/Unit/Relay/RelayWorkerTest.php`) never clear the registry afterwards.
 *
 * With `executionOrder="random"` in `phpunit.xml`, whether one of those classes
 * happened to run before a given relay test therefore decided which arm of every
 * `try { Timer::add(...) } catch (Throwable) {}` in `src/Relay/` executed — so the
 * measured line coverage of `Tunnel.php`, `ClientRelayWorker.php` and
 * `RelayProxyManager.php` moved from run to run and `codecov/project` swung on
 * its own noise.
 *
 * ## Contract
 *
 * Force the arm you want with {@see forceNoWorkermanRuntime()} (throw) or
 * {@see forceWorkermanRuntime()} (success). Both are absolute: they do not depend
 * on the state they inherit, so they behave identically whichever tests ran first.
 *
 * Everything mutated here is process-global, so it is snapshotted before each
 * test and restored after each one via PHPUnit's `#[Before]`/`#[After]` hooks —
 * which run even when the test fails or errors. Leaking any of it would create a
 * NEW order dependency, i.e. exactly the defect this trait exists to remove.
 */
trait WorkermanTimerRuntimeControl
{
    /**
     * Snapshot of `Worker::$workers` taken before the current test.
     *
     * @var array<array-key, Worker>
     */
    private array $workermanRegistrySnapshot = [];

    /** Snapshot of `Timer::$event` taken before the current test. */
    private ?EventInterface $workermanEventSnapshot = null;

    /**
     * Snapshot of `Timer::$tasks` taken before the current test.
     *
     * @var array<array-key, mixed>
     */
    private array $workermanTaskSnapshot = [];

    /**
     * Snapshot of `Timer::$status` taken before the current test.
     *
     * @var array<array-key, mixed>
     */
    private array $workermanStatusSnapshot = [];

    /**
     * Snapshot of `Worker::$pidMap` taken before the current test.
     *
     * The `Worker` constructor writes this alongside `$workers`, so seeding a
     * runtime touches it too and it must be put back.
     *
     * @var array<array-key, mixed>
     */
    private array $workermanPidMapSnapshot = [];

    /** Guards {@see restoreWorkermanRuntime()} against restoring a snapshot never taken. */
    private bool $workermanRuntimeCaptured = false;

    /**
     * Capture every Workerman static this trait is allowed to touch.
     */
    #[Before]
    protected function captureWorkermanRuntime(): void
    {
        $this->workermanRegistrySnapshot = self::readWorkerRegistry();
        $this->workermanPidMapSnapshot = self::readWorkerArray('pidMap');
        $this->workermanEventSnapshot = self::readTimerEvent();
        $this->workermanTaskSnapshot = self::readTimerArray('tasks');
        $this->workermanStatusSnapshot = self::readTimerArray('status');
        $this->workermanRuntimeCaptured = true;
    }

    /**
     * Restore every captured static. Runs after EVERY test in the using class,
     * including failing and erroring ones, so no state escapes the test.
     */
    #[After]
    protected function restoreWorkermanRuntime(): void
    {
        if (!$this->workermanRuntimeCaptured) {
            return;
        }

        self::writeWorkerRegistry($this->workermanRegistrySnapshot);
        self::writeWorkerArray('pidMap', $this->workermanPidMapSnapshot);
        self::writeTimerEvent($this->workermanEventSnapshot);
        self::writeTimerArray('tasks', $this->workermanTaskSnapshot);
        self::writeTimerArray('status', $this->workermanStatusSnapshot);

        // A successful Timer::add() arms pcntl_alarm(1); cancel it so no stray
        // SIGALRM is left queued for a later test (tests/bootstrap.php installs a
        // no-op handler, but leaving the alarm armed is still state leakage).
        if (function_exists('pcntl_alarm')) {
            pcntl_alarm(0);
        }

        $this->workermanRuntimeCaptured = false;
    }

    /**
     * Guarantee `Timer::add()` takes its THROWING arm.
     *
     * Empties `Worker::$workers` and clears `Timer::$event`, so the call reaches
     * `if (!Worker::getAllWorkers()) throw`.
     */
    protected function forceNoWorkermanRuntime(): void
    {
        self::writeTimerEvent(null);
        self::writeWorkerRegistry([]);
        self::writeTimerArray('tasks', []);
        self::writeTimerArray('status', []);
    }

    /**
     * Guarantee `Timer::add()` takes its SUCCEEDING (pcntl task-table) arm.
     *
     * Seeds `Worker::$workers` with a single real {@see Worker} and clears
     * `Timer::$event`, so the call falls through the registry guard into the task
     * table and returns a real timer id.
     */
    protected function forceWorkermanRuntime(): void
    {
        self::writeTimerEvent(null);
        self::writeTimerArray('tasks', []);
        self::writeTimerArray('status', []);
        // The Worker constructor registers itself into Worker::$workers; the
        // explicit write afterwards pins the registry to exactly this one entry
        // so the state is identical no matter what ran before.
        $worker = new Worker();
        self::writeWorkerRegistry(['phlix-timer-determinism' => $worker]);
    }

    /**
     * Assert the mechanism itself rather than assuming it: with the registry
     * forced empty `Timer::add()` must throw, and with it seeded it must not.
     *
     * Exposed so the using test class can pin the premise this whole trait rests
     * on; if Workerman ever changes that contract these tests fail loudly instead
     * of silently pinning the wrong arm.
     */
    protected function timerAddOutcome(): string
    {
        try {
            Timer::add(3600.0, static fn(): null => null);
        } catch (\Throwable $e) {
            return 'throw:' . $e->getMessage();
        }

        return 'success';
    }

    /**
     * Number of tasks currently sitting in `Timer::$tasks`, summed across the
     * per-run-time buckets. Used to prove a timer really was registered rather
     * than trusting the returned id alone.
     */
    protected function pendingTimerTaskCount(): int
    {
        $count = 0;
        foreach (self::readTimerArray('tasks') as $bucket) {
            if (is_array($bucket)) {
                $count += count($bucket);
            }
        }

        return $count;
    }

    /**
     * @return array<array-key, Worker>
     */
    private static function readWorkerRegistry(): array
    {
        /** @var array<array-key, Worker> $value */
        $value = (new ReflectionProperty(Worker::class, 'workers'))->getValue();

        return $value;
    }

    /**
     * @param array<array-key, Worker> $workers
     */
    private static function writeWorkerRegistry(array $workers): void
    {
        (new ReflectionProperty(Worker::class, 'workers'))->setValue(null, $workers);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function readWorkerArray(string $name): array
    {
        /** @var array<array-key, mixed> $value */
        $value = (new ReflectionProperty(Worker::class, $name))->getValue();

        return $value;
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private static function writeWorkerArray(string $name, array $value): void
    {
        (new ReflectionProperty(Worker::class, $name))->setValue(null, $value);
    }

    private static function readTimerEvent(): ?EventInterface
    {
        /** @var EventInterface|null $value */
        $value = (new ReflectionProperty(Timer::class, 'event'))->getValue();

        return $value;
    }

    private static function writeTimerEvent(?EventInterface $event): void
    {
        (new ReflectionProperty(Timer::class, 'event'))->setValue(null, $event);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function readTimerArray(string $name): array
    {
        /** @var array<array-key, mixed> $value */
        $value = (new ReflectionProperty(Timer::class, $name))->getValue();

        return $value;
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private static function writeTimerArray(string $name, array $value): void
    {
        (new ReflectionProperty(Timer::class, $name))->setValue(null, $value);
    }
}
