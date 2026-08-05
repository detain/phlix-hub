---
paths:
  - src/Relay/**
  - src/Http/ConnectionResponseSink.php
  - src/Hub/RelaySessionManager.php
---

# Per-user relay throttle

- `throttle_bps` is a **durable** per-user rate cap in **bits/sec**, stored in `relay_user_settings` (migration `043_relay_user_settings`) — never on the per-month `relay_user_quotas` rollup, which resets each period and silently reverts operator settings.
- Read with `RelaySessionManager::getUserThrottleBps()`, persist with `setUserThrottle()`. An unconfigured user gets `RelaySessionManager::DEFAULT_THROTTLE_BPS` (3 Mbps); `0` means **Unlimited**.
- Enforcement is a per-connection `src/Relay/TokenBucket.php` whose tokens are **BYTES** (`throttle_bps / 8`), capacity `rate × TokenBucket::THROTTLE_BURST_SECONDS`. Convert bits→bytes once at construction, never in the send loop.
- `0` (Unlimited) must leave the bucket `null` and take the unthrottled fast path — see `ClientConnection::$throttleBucket` / `isThrottled()`.
- Both transports must be covered: the WS relay (`Tunnel` via `ClientConnection::$throttleBucket` + `$throttleDrainTimerId`) and the HTTP-over-relay proxy (`ConnectionResponseSink`).
- Gate with `TokenBucket::canSpend()` then `spend()`; a fragment larger than capacity must still go out — a throttle must never deadlock a stream.
- A throttle lookup failure logs a warning and mounts the client **unthrottled** (`TunnelManager::resolveThrottleBps()`); never fail the mount.
