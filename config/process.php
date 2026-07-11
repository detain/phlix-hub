<?php

/**
 * Managed worker-process settings (HB-2.6).
 *
 * Single source of truth for the long-running worker processes this app
 * supervises alongside its HTTP worker and relay workers.
 *
 * Each entry:
 *   - `enabled`      bool — when false, `start.php` does not spawn the worker.
 *   - `count`        int  — number of worker processes (1 = single dedicated worker).
 *   - `poll_seconds` int  — `Workerman\Timer` poll interval for the loop.
 *
 * @return array<string, array{enabled: bool, count: int, poll_seconds: int}>
 */

declare(strict_types=1);

return [
    // HB-2.6: Dedicated maintenance worker for reapers (IdleReaper,
    // ServerReaper, tunnel heartbeat, federation-session reaper). Runs on its
    // own count=1 worker with its own DB connection, so reaper DB queries do
    // not add jitter to tunnel frame processing on the relay worker.
    'maintenance' => [
        'enabled'      => true,
        'count'        => 1,
        'poll_seconds' => 60,   // matches reaper tick intervals
    ],
];
