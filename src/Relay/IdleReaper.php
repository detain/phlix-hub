<?php

declare(strict_types=1);

namespace Phlix\Hub\Relay;

use Phlix\Hub\Common\Logger\StructuredLogger;
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
 * @since 0.5.0
 */
final class IdleReaper
{
    /**
     * Default interval between reaper scans in seconds.
     */
    public const DEFAULT_INTERVAL_SECONDS = 60;

    /**
     * Default stale threshold in seconds (tunnels idle longer are reaped).
     */
    public const DEFAULT_STALE_THRESHOLD_SECONDS = 90;

    /**
     * @param TunnelManagerInterface $tunnelManager       Manager owning the tunnels to scan.
     * @param StructuredLogger       $logger              Structured logger for relay events.
     * @param int                     $intervalSeconds     Interval between scans in seconds.
     * @param int                     $staleThresholdSeconds Seconds before a tunnel is considered stale.
     */
    public function __construct(
        private readonly TunnelManagerInterface $tunnelManager,
        private readonly StructuredLogger $logger,
        private readonly int $intervalSeconds = self::DEFAULT_INTERVAL_SECONDS,
        private readonly int $staleThresholdSeconds = self::DEFAULT_STALE_THRESHOLD_SECONDS,
    ) {
    }

    /**
     * Start the periodic idle reaper timer.
     *
     * Registers a Workerman Timer that calls {@see tick()} every
     * $intervalSeconds. The timer persists until the worker stops.
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
     * Perform a single reaper scan.
     *
     * Iterates all active tunnels and closes any that have been idle
     * (no frames received) for longer than the configured stale threshold.
     *
     * This method is public so it can be called directly by tests or
     * manually triggered. Normally it is called automatically by the timer.
     *
     * @return int Number of tunnels that were reaped.
     */
    public function tick(): int
    {
        $reapedCount = 0;

        foreach ($this->tunnelManager->allTunnels() as $serverId => $tunnel) {
            if ($tunnel->isStale($this->staleThresholdSeconds)) {
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
        }

        if ($reapedCount > 0) {
            $this->logger->info('Relay: idle reaper scan complete', [
                'reaped_count' => $reapedCount,
            ]);
        }

        return $reapedCount;
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
