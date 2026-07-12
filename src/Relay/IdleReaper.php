<?php

/**
 * Phlix hub component: Relay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Relay;

use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Hub\ClientRelayTokenService;
use Phlix\Hub\Hub\HeartbeatHandler;
use Phlix\Hub\Hub\RelaySessionManager;
use Workerman\Timer;

/**
 * Periodically scans all tunnels and closes stale ones that have exceeded
 * the idle threshold without receiving any frames.
 *
 * The reaper runs on a configurable interval (default 60 seconds) and
 * checks each tunnel's lastFrameAt timestamp. Tunnels idle for longer
 * than the stale threshold (default 90 seconds) are closed with reason
 * "timeout" and removed from the TunnelManager.
 *
 * @package Phlix\Hub\Relay
 */
final class IdleReaper
{
    /**
     * Default interval between reaper scans in seconds.
     */
    public const DEFAULT_INTERVAL_SECONDS = 60;

    /**
     * Default stale threshold in seconds (tunnels idle longer are reaped).
     *
     * HB-0.1 COUPLING: this window MUST stay strictly greater than the server's
     * relay ping interval (`PHLIX_RELAY_PING_INTERVAL`, server default 30s) or a
     * healthy-but-quiet tunnel is false-reaped. The server sends a HEARTBEAT
     * every ping interval and also echoes hub pings, and only INBOUND frames
     * refresh `lastFrameAt` (`sendHeartbeat` no longer self-refreshes), so as
     * long as reap window > ping interval every live tunnel receives at least
     * one inbound frame per window and stays alive. The default 90s ≫ 30s gives
     * ~3 heartbeats of headroom; keep that margin if either value is tuned.
     */
    public const DEFAULT_STALE_THRESHOLD_SECONDS = 90;

    /**
     * @param TunnelManagerInterface      $tunnelManager        Manager owning the tunnels to scan.
     * @param StructuredLogger            $logger               Structured logger for relay events.
     * @param int                         $intervalSeconds      Interval between scans in seconds.
     * @param int                         $staleThresholdSeconds Seconds before a tunnel is considered stale.
     * @param RelaySessionManager|null     $sessionManager       Optional session manager: its in-memory
     *                                                            accumulators are flushed on each
     *                                                            {@see tick()} (relay worker) and its
     *                                                            orphaned open DB rows are reaped on each
     *                                                            {@see reapDbMaintenance()} (maintenance
     *                                                            worker).
     * @param ClientRelayTokenService|null $clientRelayTokenService Optional token service whose
     *                                                            expired revoked tokens are pruned
     *                                                            on each {@see reapDbMaintenance()}
     *                                                            (HB-4.2).
     * @param HeartbeatHandler|null        $heartbeatHandler     Optional heartbeat handler whose
     *                                                            server_heartbeats table is pruned
     *                                                            on each {@see reapDbMaintenance()}
     *                                                            (HB-4.3).
     */
    public function __construct(
        private readonly TunnelManagerInterface $tunnelManager,
        private readonly StructuredLogger $logger,
        private readonly int $intervalSeconds = self::DEFAULT_INTERVAL_SECONDS,
        private readonly int $staleThresholdSeconds = self::DEFAULT_STALE_THRESHOLD_SECONDS,
        private readonly ?RelaySessionManager $sessionManager = null,
        private readonly ?HeartbeatHandler $heartbeatHandler = null,
        private readonly ?ClientRelayTokenService $clientRelayTokenService = null,
    ) {
    }

    /**
     * Start the periodic idle reaper timer (IN-MEMORY work).
     *
     * Registers a Workerman Timer that calls {@see tick()} every
     * $intervalSeconds. The timer persists until the worker stops.
     *
     * HB-2.6 DATA-LOCALITY: this MUST be armed on the RELAY worker
     * ({@see \Phlix\Hub\Relay\RelayWorker::onWorkerStart()}), because {@see tick()}
     * scans the live {@see TunnelManager} registry and flushes the in-memory
     * per-session byte/last-frame accumulators — both of which live ONLY in the
     * relay-worker process that owns the {@see Tunnel} objects. Arming it on the
     * dedicated maintenance worker (a separate fork with its own, EMPTY
     * TunnelManager) would scan zero tunnels and flush an empty accumulator, so
     * the idle/half-open reaper (HB-0.1) and the byte/last-frame persistence
     * would silently do nothing. The DB-only pruners live in
     * {@see startDbMaintenance()} and run on the maintenance worker instead.
     *
     * @return int Timer ID (can be passed to Timer::del() to cancel).
     */
    public function start(): int
    {
        $timerId = Timer::add(
            $this->intervalSeconds,
            [$this, 'tick'],
        );

        $this->logger->debug('Relay: idle reaper started', [
            'interval_seconds' => $this->intervalSeconds,
            'stale_threshold_seconds' => $this->staleThresholdSeconds,
        ]);

        return $timerId;
    }

    /**
     * Start the periodic DB-maintenance timer (DB-ONLY work).
     *
     * Registers a Workerman Timer that calls {@see reapDbMaintenance()} every
     * $intervalSeconds. Unlike {@see start()}, this touches NO in-memory tunnel
     * state — it only runs off-worker DB reapers/pruners — so it is armed on the
     * dedicated maintenance worker ({@see \Phlix\Hub\MaintenanceWorker}) to keep
     * that DB latency off the relay worker's frame-processing loop (HB-2.6
     * intent, H-A3/H-A4/H-D5). It is safe to run against the maintenance worker's
     * own DB connection because none of its work depends on the live tunnel
     * registry.
     *
     * @return int Timer ID (can be passed to Timer::del() to cancel).
     */
    public function startDbMaintenance(): int
    {
        $timerId = Timer::add(
            $this->intervalSeconds,
            [$this, 'reapDbMaintenance'],
        );

        $this->logger->debug('Relay: idle reaper DB-maintenance started', [
            'interval_seconds' => $this->intervalSeconds,
        ]);

        return $timerId;
    }

    /**
     * Perform a single IN-MEMORY reaper scan (relay-worker only).
     *
     * Iterates all active tunnels and closes any that have been idle
     * (no frames received) for longer than the configured stale threshold,
     * then flushes the in-memory per-session byte/last-frame accumulators to
     * the DB. Both operate on state that lives ONLY in the relay-worker process
     * (the live {@see Tunnel} registry + the {@see RelaySessionManager}
     * accumulators populated by `recordBytesIn/Out`), so this must run there and
     * NOT on the maintenance worker (whose registry/accumulators are empty).
     *
     * The DB-only reapers/pruners (stale-session reap, heartbeat + token prune)
     * were split out to {@see reapDbMaintenance()} which the maintenance worker
     * runs off the relay loop.
     *
     * This method is public so it can be called directly by tests or
     * manually triggered. Normally it is called automatically by the timer.
     *
     * @return int Number of tunnels that were reaped.
     */
    public function tick(): int
    {
        $reapedCount = 0;

        // Collect stale tunnels first to avoid concurrent modification
        // when closeTunnel() is called (which modifies the tunnel collection).
        $staleTunnels = [];
        foreach ($this->tunnelManager->allTunnels() as $serverId => $tunnel) {
            if ($tunnel->isStale($this->staleThresholdSeconds)) {
                $staleTunnels[$serverId] = $tunnel;
            }
        }

        // Close stale tunnels after iteration is complete.
        foreach ($staleTunnels as $serverId => $tunnel) {
            $this->logger->info('Relay: reaping stale tunnel', [
                'server_id' => $serverId,
                'tunnel_id' => $tunnel->getTunnelId(),
                'last_frame_at' => $tunnel->getLastFrameAt(),
                'stale_threshold_seconds' => $this->staleThresholdSeconds,
                'reason' => 'timeout',
            ]);

            $this->tunnelManager->closeTunnel($serverId, 'timeout');
            $reapedCount++;
        }

        if ($reapedCount > 0) {
            $this->logger->info('Relay: idle reaper scan complete', [
                'reaped_count' => $reapedCount,
            ]);
        }

        // Flush all pending byte counters and last-frame timestamps from the
        // in-memory accumulators to the database.  This runs on the same
        // 60-second tick so the relay-path DB write is bounded to one flush
        // per session per tick instead of one UPDATE per frame.
        //
        // HB-2.6: this MUST stay on the relay worker — the accumulators
        // (populated by RelaySessionManager::recordBytesIn/Out on the tunnel
        // data plane) live only in this process. Running it on the maintenance
        // worker's separate RelaySessionManager instance would flush an empty
        // accumulator and the relay_sessions bytes_in/out + last_frame_at writes
        // would be lost.
        $this->sessionManager?->flushAll();

        return $reapedCount;
    }

    /**
     * Perform a single DB-ONLY maintenance sweep (maintenance-worker only).
     *
     * Runs the reapers/pruners whose work is entirely in the database and does
     * NOT depend on the live tunnel registry or the in-memory accumulators, so
     * they can safely execute on the dedicated maintenance worker's own DB
     * connection, off the relay worker's frame-processing loop (HB-2.6):
     *
     *  - {@see RelaySessionManager::reapStaleSessions()}: close orphaned open
     *    `relay_sessions` rows left behind when a session's close path was never
     *    reached (worker restart, dropped connection). It reads `last_frame_at`,
     *    which the relay worker keeps fresh via {@see tick()}'s `flushAll()`, so
     *    a healthy session is not falsely reaped across the worker boundary.
     *  - HB-4.3 {@see HeartbeatHandler::pruneAllServerHeartbeats()}: keep only
     *    the most recent ~100 `server_heartbeats` rows per server.
     *  - HB-4.2 {@see ClientRelayTokenService::pruneExpiredTokens()}: prune
     *    expired, already-revoked client relay tokens older than 1 day.
     *
     * This method is public so it can be called directly by tests or manually
     * triggered. Normally it is called automatically by the timer armed in
     * {@see startDbMaintenance()}.
     *
     * @return void
     */
    public function reapDbMaintenance(): void
    {
        // Reap orphaned open relay_sessions DB rows. This keeps the dashboard
        // "active relays" count accurate even for rows that no longer have a
        // live in-memory tunnel.
        $this->sessionManager?->reapStaleSessions();

        // HB-4.3: Prune server_heartbeats rows, keeping only the most recent
        // ~100 rows per server to prevent unbounded table growth.
        $this->heartbeatHandler?->pruneAllServerHeartbeats(100);

        // HB-4.2: Prune expired, already-revoked client relay tokens older than
        // 1 day. Tokens are only removed once both expired AND revoked so audit
        // logs can still reference a revoked token before it naturally expires.
        $this->clientRelayTokenService?->pruneExpiredTokens();
    }

    /**
     * Get the interval in seconds between reaper scans.
     *
     * @return int Interval in seconds.
     */
    public function getIntervalSeconds(): int
    {
        return $this->intervalSeconds;
    }

    /**
     * Get the stale threshold in seconds.
     *
     * @return int Threshold in seconds.
     */
    public function getStaleThresholdSeconds(): int
    {
        return $this->staleThresholdSeconds;
    }
}
