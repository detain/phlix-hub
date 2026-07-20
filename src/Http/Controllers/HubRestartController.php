<?php

/**
 * Phlix hub component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Http\Controllers;

use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Throwable;
use Workerman\Timer;

/**
 * Graceful hub reload via SIGUSR2.
 *
 * `POST /api/v1/admin/restart` reads the Workerman master PID from the pid
 * file (`config/server.php` → `pid_file`, the same value `start.php` assigns
 * to `Worker::$pidFile`) and asks the master to cycle its workers, so every
 * worker re-runs `onWorkerStart` and re-reads `config/*.php`.
 *
 * ## Signal choice — SIGUSR2, not SIGUSR1
 *
 * Workerman treats BOTH as "reload"; the difference is whether the reload is
 * graceful (`vendor/workerman/workerman/src/Worker.php:1385-1391` sets
 * `static::$gracefulStop = $signal === SIGUSR2`):
 *
 *  - **SIGUSR2 → graceful.** Workers finish their in-flight requests and let
 *    their connections close before exiting. No `SIGKILL` timer is armed
 *    (`Worker.php:2009-2012` only arms it when `getGracefulStop()` is false).
 *  - **SIGUSR1 → NON-graceful.** Workers are stopped immediately and hard-
 *    killed after `Worker::$stopTimeout`, cutting active relay tunnels and
 *    in-flight HLS proxying.
 *
 * The hub multiplexes long-lived relay tunnels, so the non-graceful variant is
 * the wrong choice here. `scripts/install.sh`'s `ExecReload` sends the same
 * SIGUSR2 so `systemctl reload phlix-hub` and this endpoint behave identically.
 *
 * ## Ordering — ack first, signal after the flush
 *
 * plan_settings.md §3.35 requires the JSON ack to reach the client *before*
 * the restart begins. The signal is therefore deferred onto a one-shot
 * {@see Timer} (note the `false` repeat flag — Workerman timers repeat by
 * default) that fires after the current request has been written and the event
 * loop has come back round. Never `sleep()`: this is a resident-memory
 * Workerman worker.
 *
 * Route is gated by {@see \Phlix\Hub\Http\Middleware\AdminMiddleware}.
 *
 * @package Phlix\Hub\Http\Controllers
 * @since   Phase 10
 */
class HubRestartController
{
    /**
     * Seconds to wait before signalling, so the JSON ack is fully flushed to
     * the client first. Short enough to feel instant, long enough for the
     * response write to complete.
     */
    private const float SIGNAL_DELAY_SECONDS = 0.2;

    /** @var string Absolute path to the PID file, sourced from config. */
    private string $pidFile;

    /**
     * @param string $pidFile Absolute path to the PID file (from config/server.php's
     *                        `pid_file`, which start.php also uses for
     *                        `Worker::$pidFile` — single source of truth).
     *
     * @since Phase 10
     */
    public function __construct(string $pidFile)
    {
        $this->pidFile = $pidFile;
    }

    /**
     * Acknowledge, then gracefully reload the hub's workers.
     *
     * POST /api/v1/admin/restart
     *
     * @param Request              $request The HTTP request (unused).
     * @param array<string, string> $params  Path parameters (unused).
     *
     * @return Response JSON `{ success: bool, message?: string, error?: string }`.
     *
     * @since Phase 10
     */
    public function restart(Request $request, array $params): Response
    {
        try {
            if (!is_file($this->pidFile)) {
                return (new Response())->status(500)->json([
                    'success' => false,
                    'error'   => 'pid_file_not_found',
                    'message' => 'Hub may not be running, or pid_file is misconfigured.',
                ]);
            }

            $raw = file_get_contents($this->pidFile);
            if ($raw === false) {
                return (new Response())->status(500)->json([
                    'success' => false,
                    'error'   => 'pid_file_read_failed',
                    'message' => 'Hub may not be running, or pid_file is misconfigured.',
                ]);
            }

            $pid = trim($raw);
            if ($pid === '' || !is_numeric($pid)) {
                return (new Response())->status(500)->json([
                    'success' => false,
                    'error'   => 'invalid_pid',
                    'message' => 'The pid_file contains an invalid value.',
                ]);
            }

            // Liveness check BEFORE acking: signal 0 performs the permission +
            // existence check without delivering anything, so a stale pid file
            // still yields a real error response instead of a cheerful ack the
            // deferred signal would silently contradict.
            if (!$this->sendSignal((int) $pid, 0)) {
                return (new Response())->status(500)->json([
                    'success' => false,
                    'error'   => 'signal_send_failed',
                    'message' => sprintf('Process %d is not running or is not signallable.', (int) $pid),
                ]);
            }

            $this->scheduleSignal((int) $pid);

            return (new Response())->json([
                'success' => true,
                'message' => 'Restart signal sent.',
            ]);
        } catch (Throwable $e) {
            return (new Response())->status(500)->json([
                'success' => false,
                'error'   => 'restart_failed',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Defer the graceful-reload signal until after this response has flushed.
     *
     * Extracted (like {@see sendSignal()}) so tests can assert the deferral
     * without arming a real Workerman timer.
     *
     * @param int $pid Workerman master PID.
     */
    protected function scheduleSignal(int $pid): void
    {
        // One-shot: the trailing `false` is REQUIRED — Workerman's Timer::add()
        // repeats by default, and a repeating reload signal would cycle the
        // workers forever.
        Timer::add(
            self::SIGNAL_DELAY_SECONDS,
            fn (): bool => $this->sendSignal($pid, SIGUSR2),
            [],
            false,
        );
    }

    /**
     * Send a signal to a process.
     *
     * Extracted to a protected method so tests can mock it.
     *
     * @param int $pid    Process ID.
     * @param int $signal Signal constant (`SIGUSR2` to reload, `0` to probe).
     *
     * @return bool True when posix_kill() returned true; false otherwise.
     */
    protected function sendSignal(int $pid, int $signal): bool
    {
        return posix_kill($pid, $signal);
    }
}
