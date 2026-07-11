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

/**
 * Dedicated maintenance worker for reapers (HB-2.6).
 *
 * Runs the idle-tunnel reaper, server offline reaper, heartbeat retention
 * sweep, tunnel heartbeat pinger, and federation-session reaper on a single
 * dedicated worker with its own DB connection. This moves the reaper DB
 * queries off the relay worker so they no longer add jitter to tunnel frame
 * processing.
 *
 * The worker exposes {@see start()} which is called once from the Workerman
 * worker's {@see \Workerman\Worker::onWorkerStart} callback inside the
 * dedicated maintenance process. It delegates to
 * {@see HubServicesProvider::startMaintenanceTimers()} which is already the
 * canonical place for the reaper timer wiring — the only difference is the
 * container context (maintenance worker's own DB connection vs relay worker's
 * tunnel manager).
 *
 * @package Phlix\Hub\Hub
 */
final class MaintenanceWorker
{
    /**
     * Start the maintenance worker by arming the reaper timers.
     *
     * This is called once from the maintenance worker's onWorkerStart callback.
     * It delegates to {@see HubServicesProvider::startMaintenanceTimers()} in
     * this dedicated worker's coroutine context with its own DB connection.
     *
     * @param ContainerInterface $container PSR-11 container (maintenance worker's own).
     *
     * @return void
     */
    public function start(ContainerInterface $container): void
    {
        $logger = LoggerFactory::get(LogChannels::RELAY);

        try {
            HubServicesProvider::startMaintenanceTimers($container);
        } catch (\Throwable $e) {
            $logger->error('Maintenance: failed to start maintenance timers', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
