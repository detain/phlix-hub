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
use Phlix\Hub\Hub\Ed25519KeyManager;
use Phlix\Hub\Hub\HeartbeatHandler;
use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\Mcp\McpTokenService;
use Phlix\Hub\OAuth\AuthorizationCodeService;
use Phlix\Hub\OAuth\ConsentTicketService;
use Phlix\Hub\OAuth\OAuthTokenService;
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
     *                                                            expired-or-revoked tokens are pruned
     *                                                            on each {@see reapDbMaintenance()}
     *                                                            (HB-4.2).
     * @param HeartbeatHandler|null        $heartbeatHandler     Optional heartbeat handler whose
     *                                                            server_heartbeats table is pruned
     *                                                            on each {@see reapDbMaintenance()}
     *                                                            (HB-4.3).
     * @param Ed25519KeyManager|null       $keyManager           Optional Ed25519 key manager whose
     *                                                            expired previous-key sidecar is
     *                                                            purged on each
     *                                                            {@see reapDbMaintenance()} (HB-4.7 /
     *                                                            H-A1) — the low-frequency filesystem
     *                                                            unlink kept off the JWT-verify path.
     * @param McpTokenService|null         $mcpTokenService      Optional MCP personal-access-token
     *                                                            service whose expired-or-revoked
     *                                                            rows are pruned on each
     *                                                            {@see reapDbMaintenance()} (S62).
     * @param OAuthTokenService|null       $oauthTokenService    Optional OAuth 2.0 access/refresh
     *                                                            token store, pruned on each
     *                                                            {@see reapDbMaintenance()} (S286).
     * @param AuthorizationCodeService|null $oauthCodeService    Optional OAuth authorization-code
     *                                                            store, pruned on each
     *                                                            {@see reapDbMaintenance()} (S286).
     * @param ConsentTicketService|null    $oauthTicketService   Optional OAuth consent-ticket /
     *                                                            pending-authorization store, pruned
     *                                                            on each {@see reapDbMaintenance()}
     *                                                            (S286).
     */
    public function __construct(
        private readonly TunnelManagerInterface $tunnelManager,
        private readonly StructuredLogger $logger,
        private readonly int $intervalSeconds = self::DEFAULT_INTERVAL_SECONDS,
        private readonly int $staleThresholdSeconds = self::DEFAULT_STALE_THRESHOLD_SECONDS,
        private readonly ?RelaySessionManager $sessionManager = null,
        private readonly ?HeartbeatHandler $heartbeatHandler = null,
        private readonly ?ClientRelayTokenService $clientRelayTokenService = null,
        private readonly ?Ed25519KeyManager $keyManager = null,
        private readonly ?McpTokenService $mcpTokenService = null,
        private readonly ?OAuthTokenService $oauthTokenService = null,
        private readonly ?AuthorizationCodeService $oauthCodeService = null,
        private readonly ?ConsentTicketService $oauthTicketService = null,
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
     * ## S312 — the callback is GUARDED, and that is load-bearing
     *
     * The timer callback used to be `[$this, 'reapDbMaintenance']`, i.e. the
     * sweep itself. Every statement in that sweep goes through
     * {@see \Phlix\Hub\Common\Database\PooledMySQLConnection}, which connects
     * lazily, so with no reachable database the FIRST tick threw
     * `PDOException: SQLSTATE[HY000] [2002] Connection refused` straight into
     * the event loop. Workerman routes an exception escaping a callback to the
     * event-loop error handler it installs in `Worker::run()`, which is
     * `Worker::stopAll(250, $exception)` — so the whole maintenance worker died
     * and was re-forked, once per interval, for ever. Measured on master under
     * `docker run --network none`: the worker's `etime` was 0:39 against the
     * container master's 4:41, while `docker inspect` said `healthy` and
     * `RestartCount=0`.
     *
     * So the callback below catches, reports and returns. The sweep is
     * idempotent and periodic: a failed sweep costs nothing and the next tick
     * `$intervalSeconds` later retries, which is a backoff the process cannot
     * give itself (Workerman's master re-forks immediately, with none).
     *
     * @param (\Closure(?\Throwable): void)|null $onSweep Called after every completed sweep with the
     *                                                    failure, or null on success. This is how the
     *                                                    maintenance worker's liveness record advances —
     *                                                    see {@see \Phlix\Hub\Health\MaintenanceHeartbeat}
     *                                                    for why it must be stamped HERE and not on a
     *                                                    timer of its own.
     *
     * @return int Timer ID (can be passed to Timer::del() to cancel).
     */
    public function startDbMaintenance(?\Closure $onSweep = null): int
    {
        $timerId = Timer::add(
            $this->intervalSeconds,
            function () use ($onSweep): void {
                $this->runDbMaintenanceGuarded($onSweep);
            },
        );

        $this->logger->debug('Relay: idle reaper DB-maintenance started', [
            'interval_seconds' => $this->intervalSeconds,
        ]);

        return $timerId;
    }

    /**
     * Run one DB-maintenance sweep so that NOTHING escapes to the event loop.
     *
     * Public so a test can invoke exactly what the timer holds without going
     * through Workerman. See {@see startDbMaintenance()} for why the guard is
     * not optional.
     *
     * @param (\Closure(?\Throwable): void)|null $onSweep Sweep-outcome reporter, or null.
     *
     * @return void
     */
    public function runDbMaintenanceGuarded(?\Closure $onSweep = null): void
    {
        $failure = null;

        try {
            $this->reapDbMaintenance();
        } catch (\Throwable $e) {
            $failure = $e;
            $this->logger->error('Relay: idle reaper DB-maintenance sweep failed', [
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);
        }

        if ($onSweep !== null) {
            $onSweep($failure);
        }
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
     *    client relay tokens that expired more than 1 day ago OR were revoked.
     *  - HB-4.7 (H-A1) {@see Ed25519KeyManager::purgeExpiredPreviousKey()}: unlink
     *    the rotated-out previous-key sidecar once its overlap window has lapsed.
     *    The verify path (`loadPreviousKey`) intentionally does NOT unlink on
     *    expiry (no filesystem I/O on the hot JWT-verify path); this periodic
     *    maintenance pass is where the stale sidecar is actually reclaimed
     *    instead of lingering until the next {@see Ed25519KeyManager::rotate()}.
     *  - S62 {@see McpTokenService::pruneExpiredTokens()}: prune MCP personal
     *    access tokens that expired more than 30 days ago OR were revoked. This
     *    pruner lives HERE rather than on a timer of its own deliberately: a
     *    bare `Timer::add(86400, …)` never fires on a box that restarts more
     *    often than once a day, so a daily self-armed timer would silently never
     *    run. This reaper's timer is already armed at
     *    {@see self::DEFAULT_INTERVAL_SECONDS} (60s) on the maintenance worker,
     *    so the first sweep happens within a minute of every boot — no separate
     *    boot catch-up is needed.
     *  - S286 {@see OAuthTokenService::pruneExpired()},
     *    {@see AuthorizationCodeService::pruneExpired()} and
     *    {@see ConsentTicketService::pruneExpired()}: prune the three OAuth 2.0
     *    stores S92 left growing forever. Same placement, for the same reason
     *    written out one line above — and this time the reason is the ONLY
     *    reason, because these three are the exact case
     *    [[project_backup_timer_needs_boot_catchup_2026_07_21]] describes. A
     *    `Timer::add(86400, …)` armed at boot fires its first tick a day later,
     *    so on a hub that is restarted (deploy, update, reboot) more often than
     *    once a day it fires NEVER, and the tables it was supposed to bound grow
     *    exactly as if the timer had not been written. Attaching them to this
     *    already-armed 60-second sweep means the boot catch-up is structural:
     *    there is no first-tick gap to miss, because the first tick is a minute
     *    after every start. Nothing prunes `oauth_clients` — a disabled client is
     *    operator state an audit needs to keep, not garbage.
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

        // HB-4.2: Prune client relay tokens that expired more than 1 day ago OR
        // were revoked (H-D2). Expired-never-revoked tokens are the common case
        // (~1 h TTL, rarely revoked), so the OR is what actually bounds growth.
        $this->clientRelayTokenService?->pruneExpiredTokens();

        // HB-4.7 (H-A1): Purge the rotated-out Ed25519 previous-key sidecar once
        // its overlap window has lapsed. Kept OFF the JWT-verify path
        // (loadPreviousKey does not unlink on expiry); this low-frequency
        // maintenance-worker filesystem unlink is where it is reclaimed.
        $this->keyManager?->purgeExpiredPreviousKey();

        // S62: Prune MCP personal access tokens that expired more than 30 days
        // ago OR were revoked. Same OR-not-AND reasoning as HB-4.2 above; the
        // grace window is wider because MCP PATs are long-lived and an operator
        // debugging "why did my agent stop working" wants to still see the row.
        $this->mcpTokenService?->pruneExpiredTokens();

        // S286: prune the three OAuth 2.0 stores. Each owns one table and one
        // lifetime rule, so each prunes itself — the alternative, one DELETE
        // reaching across four tables from here, would put the retention policy
        // of `oauth_authorization_codes` (60-second codes, purge after an hour)
        // in the same statement as that of `oauth_tokens` (30-day refresh
        // tokens, purge a day after expiry), where changing one would silently
        // change the other.
        $this->oauthTokenService?->pruneExpired();
        $this->oauthCodeService?->pruneExpired();
        $this->oauthTicketService?->pruneExpired();
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
