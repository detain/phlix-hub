# IdleReaper

**Applies to:** Hub (phlix-hub)

Periodic cleanup of stale server tunnels that have exceeded the idle threshold without receiving any frames.

## Overview

The IdleReaper runs on a configurable interval (default 60 seconds) and checks each tunnel's `lastFrameAt` timestamp. Tunnels idle for longer than the stale threshold (default 90 seconds) are closed with reason `"timeout"` and removed from the `TunnelManager`.

```
TunnelManager → [IdleReaper] → closeTunnel(serverId, 'timeout')
                      ↑
                  Workerman Timer
```

## IdleReaper

`Phlix\Hub\Relay\IdleReaper`

### Construction

```php
use Phlix\Hub\Relay\IdleReaper;
use Phlix\Hub\Relay\TunnelManager;
use Phlix\Hub\Common\Logger\StructuredLogger;

$reaper = new IdleReaper(
    tunnelManager: $manager,
    logger: $logger,
    intervalSeconds: 60,           // Optional, default 60
    staleThresholdSeconds: 90,       // Optional, default 90
);
```

### Configuration

| Parameter | Type | Default | Description |
|---|---|---|---|
| `intervalSeconds` | `int` | `60` | Interval between reaper scans. |
| `staleThresholdSeconds` | `int` | `90` | Seconds without frames before a tunnel is considered stale. |

#### Constants

| Constant | Value | Description |
|---|---|---|
| `DEFAULT_INTERVAL_SECONDS` | `60` | Default scan interval. |
| `DEFAULT_STALE_THRESHOLD_SECONDS` | `90` | Default stale threshold. |

### Lifecycle

#### `start(): int`

Register a Workerman `Timer` that calls `tick()` every `$intervalSeconds`. The timer persists until the worker stops.

```php
$timerId = $reaper->start();

// $timerId can be passed to Timer::del() to cancel (not exposed by IdleReaper itself)
```

Returns the Workerman timer ID.

#### `tick(): int`

Perform a single reaper scan. Public so it can be called directly by tests or manually triggered.

```php
$reapedCount = $reaper->tick();
// Returns number of tunnels closed (0 if none were stale)
```

**Behavior:**
1. Iterates all active tunnels via `TunnelManager::allTunnels()`.
2. Collects tunnels where `$tunnel->isStale($threshold)` returns `true`.
3. Calls `$tunnelManager->closeTunnel($serverId, 'timeout')` for each stale tunnel.

> **Note:** Tunnels are collected first to avoid concurrent modification during iteration when `closeTunnel()` modifies the tunnel collection.

```php
// Manual trigger (e.g., from a test)
$reaper = new IdleReaper($manager, $logger, 60, 90);
$reaper->tick();
```

### Getters

```php
$reaper->getIntervalSeconds();         // e.g., 60
$reaper->getStaleThresholdSeconds(); // e.g., 90
```

## Integration with TunnelManager

The IdleReaper is wired into the tunnel lifecycle at startup:

```php
use Phlix\Hub\Relay\IdleReaper;
use Phlix\Hub\Relay\TunnelManager;
use React\EventLoop\Loop;

$manager = new TunnelManager($sessionManager, $codec, $logger);
$reaper = new IdleReaper(
    tunnelManager: $manager,
    logger: $logger,
    intervalSeconds: 60,
    staleThresholdSeconds: 90,
);

// Start reaper — timer runs until worker stops
$reaper->start();
```

The reaper does not expose a `stop()` method. The timer persists for the lifetime of the worker process. To restart with new settings, create a new `IdleReaper` instance with updated configuration.

### Interaction with Tunnel `isStale()`

`IdleReaper::tick()` delegates staleness detection to `Tunnel::isStale()`:

```php
// Tunnel.php
public function isStale(int $staleThresholdSeconds = 90): bool
{
    return (time() - $this->lastFrameAt) > $staleThresholdSeconds;
}
```

A tunnel with `lastFrameAt` older than the threshold is considered stale and eligible for reaping.

## Error Handling

| Scenario | Behavior |
|---|---|
| No stale tunnels | `tick()` returns `0`, no log entries. |
| All tunnels active | `tick()` returns `0`. |
| Multiple stale tunnels | All are closed in a single pass. |
| Empty tunnel list | `tick()` returns `0`. |

Each reaped tunnel logs at `INFO` level:

```php
$this->logger->info('Relay: reaping stale tunnel', [
    'server_id' => $serverId,
    'tunnel_id' => $tunnel->getTunnelId(),
    'last_frame_at' => $tunnel->getLastFrameAt(),
    'stale_threshold_seconds' => $this->staleThresholdSeconds,
    'reason' => 'timeout',
]);
```

A summary is logged when any tunnels were reaped:

```php
$this->logger->info('Relay: idle reaper scan complete', [
    'reaped_count' => $reapedCount,
]);
```
