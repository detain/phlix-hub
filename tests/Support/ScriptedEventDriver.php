<?php

/**
 * Phlix hub component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support;

use Workerman\Events\EventInterface;

use function count;

/**
 * Scripted Workerman event driver for timer-behavior tests (no real sleeps).
 *
 * Installed into `Workerman\Timer::$event`, it makes `Timer::add()` take its
 * REAL delegation branch (`delay()` for one-shot, `repeat()` for persistent)
 * and hands every armed callback to the test via {@see self::fire()}.
 *
 * Two adversarial-by-design properties pin guard code, not vendor behavior:
 *
 *  - ids are allocated from 0 (Events\Event and StreamSelect both hand out 0
 *    as their first timer id — the estate's M-S1 lesson: a guard must never
 *    treat a falsy-but-valid id as "no timer");
 *  - {@see self::offDelay()} RECORDS the cancel but LEAVES THE CALLBACK
 *    FIREABLE, so a test can simulate a driver that fires a cancelled timer
 *    late; code under test must be inert by its own bookkeeping gates, not
 *    by trusting vendor teardown timing.
 *
 * @package Phlix\Hub\Tests\Support
 */
final class ScriptedEventDriver implements EventInterface
{
    /** @var list<float> one-shot arm intervals, in arm order */
    public array $delays = [];

    /** @var list<float> repeating arm intervals, in arm order */
    public array $repeats = [];

    /** @var list<int> timer ids passed to offDelay()/offRepeat(), in order */
    public array $cancelled = [];

    /** @var array<int, callable> armed callbacks, keyed by allocated id */
    public array $callbacks = [];

    /** Id handed out by the most recent delay() arm (null = never armed). */
    public ?int $lastDelayId = null;

    /** Next id to allocate — starts at 0 ON PURPOSE (see class docblock). */
    private int $nextId = 0;

    /**
     * @param array<array-key, mixed> $args
     */
    public function delay(float $delay, callable $func, array $args = []): int
    {
        $id = $this->nextId++;
        $this->delays[] = $delay;
        $this->callbacks[$id] = $func;
        $this->lastDelayId = $id;

        return $id;
    }

    /**
     * @param array<array-key, mixed> $args
     */
    public function repeat(float $interval, callable $func, array $args = []): int
    {
        $id = $this->nextId++;
        $this->repeats[] = $interval;
        $this->callbacks[$id] = $func;

        return $id;
    }

    /**
     * Run the callback armed as id $timerId (no-op when none exists).
     */
    public function fire(int $timerId): void
    {
        $callback = $this->callbacks[$timerId] ?? null;
        if ($callback !== null) {
            $callback();
        }
    }

    public function offDelay(int $timerId): bool
    {
        // Adversarial by design: recorded, but the callback STAYS armed so
        // tests exercise the code's own identity gate (see class docblock).
        $this->cancelled[] = $timerId;

        return true;
    }

    public function offRepeat(int $timerId): bool
    {
        $this->cancelled[] = $timerId;

        return true;
    }

    /** @param resource $stream */
    public function onReadable($stream, callable $func): void
    {
    }

    /** @param resource $stream */
    public function offReadable($stream): bool
    {
        return true;
    }

    /** @param resource $stream */
    public function onWritable($stream, callable $func): void
    {
    }

    /** @param resource $stream */
    public function offWritable($stream): bool
    {
        return true;
    }

    public function onSignal(int $signal, callable $func): void
    {
    }

    public function offSignal(int $signal): bool
    {
        return true;
    }

    public function deleteAllTimer(): void
    {
    }

    public function run(): void
    {
    }

    public function stop(): void
    {
    }

    public function getTimerCount(): int
    {
        return count($this->callbacks);
    }

    public function setErrorHandler(callable $errorHandler): void
    {
    }
}
