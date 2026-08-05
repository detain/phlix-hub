<?php

/**
 * Phlix hub component: Updates.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Hub\Updates;

use Phlix\Hub\Common\Logger\StructuredLogger;
use Throwable;
use Workerman\Timer;

/**
 * Background driver for {@see CoreUpdateCheckService} (S75).
 *
 * ## The boot catch-up is not optional
 *
 * A bare `Timer::add(86400, ...)` fires its FIRST tick 86400 seconds after the
 * process starts. On a hub that is restarted (deploy, `--update`, reboot,
 * SIGUSR2 reload) more often than the interval, that tick never happens and
 * the feature silently does nothing — this exact defect shipped once already
 * in the estate ("backup timer boot catch-up", 2026-07-21). {@see start()}
 * therefore runs ONE check immediately and only then schedules the repeating
 * poll. The two arms are independent and are pinned by separate tests.
 *
 * ## Where it runs
 *
 * On the dedicated `count=1` maintenance worker
 * ({@see \Phlix\Hub\MaintenanceWorker}), armed from inside that worker's event
 * loop by
 * {@see \Phlix\Hub\Common\Container\Providers\HubServicesProvider::startDbMaintenanceTimers()}.
 * Never in the master's `boot()`: `Timer::add` there falls back to the pcntl
 * signal scheduler and the callback would run at `cid < 0`, where
 * {@see \Phlix\Hub\Common\Database\PhlixMySQLConnection::query()} bypasses its
 * per-connection mutex. `count=1` also means the poll runs once hub-wide
 * rather than once per HTTP worker.
 *
 * @package Phlix\Hub\Hub\Updates
 * @since   S75 (core update check)
 */
final class CoreUpdateCheckWorker
{
    /** Default steady-state poll interval: once a day. */
    public const DEFAULT_INTERVAL_SECONDS = 86400;

    /**
     * @param CoreUpdateCheckService $service         Check service.
     * @param StructuredLogger       $logger          Hub logger.
     * @param int                    $intervalSeconds Steady-state poll interval, seconds.
     */
    public function __construct(
        private readonly CoreUpdateCheckService $service,
        private readonly StructuredLogger $logger,
        private readonly int $intervalSeconds = self::DEFAULT_INTERVAL_SECONDS,
    ) {
    }

    /**
     * Arm the worker: one immediate BOOT CATCH-UP check, then the repeating
     * daily poll.
     *
     * @return int Timer id of the repeating poll (pass to `Timer::del()` to cancel).
     */
    public function start(): int
    {
        // ARM 1 — boot catch-up. Runs before anything is scheduled so a hub
        // that restarts daily still checks daily.
        $this->tick();

        // ARM 2 — steady-state poll. `Timer::add()` is persistent by default,
        // which is what we want here: one arming, ticks forever.
        $timerId = Timer::add($this->intervalSeconds, [$this, 'tick']);

        $this->logger->debug('Updates: core update check worker started', [
            'interval_seconds' => $this->intervalSeconds,
        ]);

        return $timerId;
    }

    /**
     * Perform a single check.
     *
     * Public so both the boot catch-up and the timer can reach it (and so a
     * test can invoke the callback the timer holds). Fully guarded: a throw
     * escaping a Workerman timer callback takes the worker's tick with it.
     *
     * @return void
     */
    public function tick(): void
    {
        try {
            $this->service->check();
        } catch (Throwable $e) {
            $this->logger->error('Updates: core update check tick failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
