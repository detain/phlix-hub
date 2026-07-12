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

        try {
            HubServicesProvider::startDbMaintenanceTimers($container);
        } catch (\Throwable $e) {
            $logger->error('Maintenance: failed to start DB-maintenance timers', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
