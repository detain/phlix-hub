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
 * Background driver for {@see CoreUpdateCheckService} (S75, reshaped by S308).
 *
 * ## NOTHING here touches the network on the boot path (S308)
 *
 * S75 armed a boot catch-up: `start()` ran one check SYNCHRONOUSLY before
 * scheduling the daily poll, so every hub process reached out to
 * `raw.githubusercontent.com` while it was still starting. Measured in a
 * container on 2026-08-10, that is worse than noisy:
 *
 *  - with DNS answering and the connect refused, one check produced TWO
 *    `stream_socket_client(): Unable to connect to tcp://raw.githubusercontent.com:443`
 *    warnings on stdout (vendor `ConnectionPool::fetch()` dials once, then
 *    vendor `Client::process()` calls `reconnect()` on the CLOSING connection
 *    and dials again) — the repeated warning S308 was raised on;
 *  - with DNS blackholed — any air-gapped or egress-filtered install — the
 *    Swoole-hooked resolver did not return at all. `start()` never returned,
 *    its `Updates: core update check worker started` line was never written,
 *    and **the daily poll timer was never armed**. The feature was dead
 *    precisely where an operator most needs to be told a release shipped, and
 *    an `onWorkerStart` coroutine was parked for the life of the process.
 *
 * ## The shape that replaces it
 *
 * `start()` arms ONE repeating timer at {@see DEFAULT_SWEEP_SECONDS} (60s) and
 * performs no I/O of its own. Each sweep asks
 * {@see CoreUpdateCheckService::checkIfDue()}, which fetches only when
 * `$intervalSeconds` has genuinely elapsed since the last completed check.
 *
 * That keeps the property the boot catch-up existed for. A bare
 * `Timer::add(86400, …)` fires its FIRST tick 86400 seconds after the process
 * starts, so on a hub restarted (deploy, `--update`, reboot, SIGUSR2 reload)
 * more often than the interval it fires NEVER — the defect
 * [[project_backup_timer_needs_boot_catchup_2026_07_21]] records. A 60-second
 * sweep plus a persisted `updates.last_checked_at` makes the catch-up
 * STRUCTURAL rather than a special case: the first sweep is a minute after every
 * boot, and it fetches exactly when a poll was actually missed. It is the same
 * argument, and the same cadence, that
 * {@see \Phlix\Hub\Relay\IdleReaper::reapDbMaintenance()} already documents for
 * the S62/S286 pruners.
 *
 * It is also strictly better than the boot catch-up on a box that restarts
 * often: S75's shape fetched once per PROCESS START (every ten minutes on a
 * flapping host), this one fetches once per INTERVAL.
 *
 * ## Why not inside `IdleReaper::reapDbMaintenance()`
 *
 * That sweep is the repository's established home for periodic maintenance and
 * was considered first. Two reasons against: every task in it is a pure DB
 * prune, and this one is outbound HTTP whose worst measured case parks a
 * coroutine indefinitely; and it would be the THIRTEENTH constructor argument
 * and the NINTH optional/nullable dependency on that class, where PHP-DI's
 * `autowire()` skips optional parameters and a silently-null dependency reads
 * as "the feature is off" ([[project_playback_fixes_paused_2026_07_28]]). The
 * cadence and the reasoning are reused; the coupling is not. This class arms no
 * additional timer — it re-uses the one it already had, at a shorter interval.
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
     * Default sweep cadence: how often the worker ASKS whether a poll is due.
     *
     * 60s, matching {@see \Phlix\Hub\Relay\IdleReaper::DEFAULT_INTERVAL_SECONDS}
     * — the maintenance worker's established maintenance cadence. A sweep is a
     * single `hub_settings` read; the network is touched only when
     * {@see DEFAULT_INTERVAL_SECONDS} has elapsed.
     */
    public const DEFAULT_SWEEP_SECONDS = 60;

    /**
     * @param CoreUpdateCheckService $service         Check service.
     * @param StructuredLogger       $logger          Hub logger.
     * @param int                    $intervalSeconds Steady-state poll interval, seconds.
     * @param int                    $sweepSeconds    How often to ask whether a poll is due, seconds.
     */
    public function __construct(
        private readonly CoreUpdateCheckService $service,
        private readonly StructuredLogger $logger,
        private readonly int $intervalSeconds = self::DEFAULT_INTERVAL_SECONDS,
        private readonly int $sweepSeconds = self::DEFAULT_SWEEP_SECONDS,
    ) {
    }

    /**
     * Arm the due-gated sweep.
     *
     * Performs NO check of its own — see the class docblock. `Timer::add()` is
     * persistent by default, which is what we want: one arming, ticks forever.
     *
     * @return int Timer id of the sweep (pass to `Timer::del()` to cancel).
     */
    public function start(): int
    {
        $timerId = Timer::add($this->sweepSeconds, [$this, 'tick']);

        $this->logger->debug('Updates: core update check sweep armed', [
            'sweep_seconds'    => $this->sweepSeconds,
            'interval_seconds' => $this->intervalSeconds,
        ]);

        return $timerId;
    }

    /**
     * One sweep: check if, and only if, a poll interval has elapsed.
     *
     * Public so the timer can reach it (and so a test can invoke the callback
     * the timer holds). Fully guarded: a throw escaping a Workerman timer
     * callback takes the worker's tick with it.
     *
     * @return void
     */
    public function tick(): void
    {
        try {
            $this->service->checkIfDue($this->intervalSeconds);
        } catch (Throwable $e) {
            $this->logger->error('Updates: core update check tick failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
