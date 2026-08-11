<?php

/**
 * Phlix hub component: Hub.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub;

use Phlix\Hub\Common\Container\Providers\HubServicesProvider;
use Phlix\Hub\Common\Logger\LogChannels;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Psr\Container\ContainerInterface;
use Workerman\Worker;

/**
 * Dedicated maintenance worker for the DB-only reapers (HB-2.6).
 *
 * Runs the DB-backed reapers/pruners — stale-session reap, server offline
 * reaper, heartbeat retention sweep, client-relay-token prune, and the
 * federation-session reaper — on a single dedicated worker with its own DB
 * connection, so the reaper DB queries no longer add jitter to tunnel frame
 * processing on the relay worker.
 *
 * IMPORTANT (HB-2.6 data-locality split): the IN-MEMORY reapers — the idle
 * tunnel reaper (HB-0.1), the tunnel keepalive heartbeat pinger, and the
 * flush of the in-memory byte/last-frame accumulators — are DELIBERATELY NOT
 * run here. This worker is a separate fork with its OWN container, so its
 * {@see \Phlix\Hub\Relay\TunnelManager} and its
 * {@see \Phlix\Hub\Hub\RelaySessionManager} accumulators are EMPTY. Those tasks
 * scan the live tunnel registry that lives only in the relay worker (:8802), so
 * they are armed there by {@see HubServicesProvider::startInMemoryReapers()}.
 * Running them here would reap/ping/flush against an empty registry (a no-op)
 * and silently break HB-0.1 and the keepalive heartbeat.
 *
 * The worker exposes {@see start()} which is called once from the Workerman
 * worker's {@see \Workerman\Worker::onWorkerStart} callback inside the
 * dedicated maintenance process. It delegates to
 * {@see HubServicesProvider::startDbMaintenanceTimers()}.
 *
 * @package Phlix\Hub\Hub
 */
final class MaintenanceWorker
{
    /**
     * Start the maintenance worker by arming the DB-only reaper timers.
     *
     * This is called once from the maintenance worker's onWorkerStart callback.
     * It delegates to {@see HubServicesProvider::startDbMaintenanceTimers()} in
     * this dedicated worker's coroutine context with its own DB connection. It
     * does NOT arm the in-memory tunnel reaper / keepalive heartbeat — those are
     * relay-worker-resident (see the class docblock).
     *
     * @param ContainerInterface $container PSR-11 container (maintenance worker's own).
     *
     * @return void
     */
    public function start(ContainerInterface $container): void
    {
        $logger = LoggerFactory::get(LogChannels::RELAY);

        $this->installEventLoopBackstop($logger);

        try {
            HubServicesProvider::startDbMaintenanceTimers($container);
        } catch (\Throwable $e) {
            $logger->error('Maintenance: failed to start DB-maintenance timers', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Stop an exception escaping ANY callback on this worker from killing it.
     *
     * ## The mechanism this inverts (S312, measured — do not remove without re-measuring)
     *
     * `Worker::run()` installs exactly one error handler on the worker's event
     * loop (`vendor/workerman/workerman/src/Worker.php:1590`):
     *
     * ```php
     * static::$globalEvent->setErrorHandler(function ($exception) {
     *     static::stopAll(250, $exception);
     * });
     * ```
     *
     * and the Swoole driver routes every callback through it
     * (`Events/Swoole.php::safeCall()` — `catch (Throwable $e) { ($this->errorHandler)($e); }`).
     * So a `PDOException` out of a 60-second reaper tick does not merely lose
     * the tick: it calls `Worker::stopAll()`, which in a CHILD sets
     * `static::$status = STATUS_SHUTDOWN`, stops the loop, and lets
     * `Worker::run()` fall through to **`exit(0)`**.
     *
     * Exit code 0 is why the loop was invisible.
     * `Worker::monitorWorkersForLinux()` logs a dead child only when its status
     * is NON-zero, and `docker inspect`'s `RestartCount` counts *container*
     * restarts, not worker re-forks. Measured on master @ 65763eb under
     * `docker run --network none`: the maintenance worker's `etime` was 0:39
     * against the container master's 4:41, three identical stack traces landed
     * every ~60s, `RestartCount` was `0` and health was `healthy`. An isolated
     * control in the same image — one worker, one 3s timer, `Worker::$onWorkerExit`
     * printing the raw wait status — produced `raw_status=0 exitcode=0
     * signalled=no` on every re-fork when the callback threw, and a single
     * process surviving every tick when the identical callback caught its own
     * exception.
     *
     * Each sweep now guards itself (see
     * {@see HubServicesProvider::startDbMaintenanceTimers()}), which is where
     * the failure is ATTRIBUTED to a task and recorded in the heartbeat. This is
     * the backstop underneath that: a callback nobody thought to guard — a
     * future timer, a signal handler, a stream event — logs and leaves the
     * worker running instead of ending it with a status nothing reports.
     *
     * ⚠ It is installed on THIS FORK's loop only. `Worker::$globalEvent` is a
     * per-process instance created after the fork, so replacing the handler here
     * cannot affect the HTTP or relay workers, which keep Workerman's
     * fail-fast behaviour.
     *
     * @param \Phlix\Hub\Common\Logger\StructuredLogger $logger Relay-channel logger.
     *
     * @return void
     */
    private function installEventLoopBackstop(\Phlix\Hub\Common\Logger\StructuredLogger $logger): void
    {
        $loop = Worker::$globalEvent;
        if ($loop === null) {
            // No loop yet (unit tests calling start() directly, or a Workerman
            // version that builds it later). Nothing to back up, and nothing to
            // fail: the per-sweep guards stand on their own.
            return;
        }

        $loop->setErrorHandler(static function (\Throwable $e) use ($logger): void {
            $logger->error(
                'Maintenance: a callback threw; the worker stays up (S312 backstop)',
                [
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ],
            );
        });
    }
}
