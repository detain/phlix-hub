<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support;

use Phlix\Hub\Mcp\McpStreamTimers;

use function array_key_exists;

/**
 * A {@see McpStreamTimers} that records every schedule and cancellation, and
 * lets a test FIRE a timer by hand.
 *
 * ## Why a hand-driven fake and not the real Workerman timer
 *
 * `Workerman\Timer::add()` needs an installed event loop. Outside a running
 * worker it falls back to a `pcntl_alarm`, which in a PHPUnit process means the
 * callback fires (or does not) on a signal nobody is waiting for — the test
 * would either hang or pass without the callback ever running. Neither outcome
 * says anything about the code under test.
 *
 * Driving the callbacks directly makes three otherwise-unobservable behaviours
 * assertable: that the keep-alive writes what it claims to write, that the
 * lifetime ceiling terminates the stream cleanly, and — the one that matters in
 * a resident-memory worker — that BOTH timers are cancelled when the client
 * hangs up. A timer that outlives its connection leaks once per dropped client
 * and goes on writing to a dead socket; nothing but a test like this sees it.
 *
 * @package Phlix\Hub\Tests\Support
 */
final class RecordingStreamTimers implements McpStreamTimers
{
    /** @var array<int, array{interval: int, callback: callable, persistent: bool}> Live timers by id. */
    public array $live = [];

    /** @var list<int> Ids passed to {@see del()}, in order. */
    public array $cancelled = [];

    /** @var list<array{interval: int, persistent: bool}> Every schedule, in order, live or not. */
    public array $scheduled = [];

    /** Next id to hand out. */
    private int $nextId = 1;

    /**
     * When true, {@see add()} refuses — the "no event loop" case
     * {@see \Phlix\Hub\Mcp\WorkermanStreamTimers} degrades to.
     */
    public function __construct(private readonly bool $refuse = false)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function add(int $intervalSeconds, callable $callback, bool $persistent): int|false
    {
        $this->scheduled[] = ['interval' => $intervalSeconds, 'persistent' => $persistent];
        if ($this->refuse) {
            return false;
        }

        $id = $this->nextId++;
        $this->live[$id] = ['interval' => $intervalSeconds, 'callback' => $callback, 'persistent' => $persistent];

        return $id;
    }

    /**
     * {@inheritDoc}
     */
    public function del(int $timerId): void
    {
        $this->cancelled[] = $timerId;
        unset($this->live[$timerId]);
    }

    /**
     * Run the callback of the (still live) timer with this id.
     *
     * @return bool True when a live timer with that id was run.
     */
    public function fire(int $timerId): bool
    {
        if (!array_key_exists($timerId, $this->live)) {
            return false;
        }

        ($this->live[$timerId]['callback'])();

        return true;
    }

    /**
     * The id of the first live timer scheduled with `$persistent`, or null.
     */
    public function firstLiveIdWithPersistence(bool $persistent): ?int
    {
        foreach ($this->live as $id => $timer) {
            if ($timer['persistent'] === $persistent) {
                return $id;
            }
        }

        return null;
    }
}
