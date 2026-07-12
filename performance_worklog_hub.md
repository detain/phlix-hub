# Worklog — phlix-hub

## Tooling (from Recon)

**PHP version:** 8.3 (requires ext: json, pcntl, posix, swoole for full coroutine support;
swoole is optional but without it coroutine runtime is inactive with E_USER_WARNING at bootstrap)

**Bootstrap gotchas:**
- Dual entry points: `start.php` (canonical daemon) and `public/index.php` (thin shim for
  existing systemd units pointing at `public/index.php start`);
  both require the same config bootstrap
- Swoole eventLoop driver must be set via `Worker::$eventLoopClass =
  \Workerman\Events\Swoole::class` **before** any Worker instantiation; Swoole
  hooks enabled via `Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL)` in start.php:57-71
- `ConnectionPool::init()` is deliberately **NOT** called at bootstrap — the pool is
  lazily initialized on first container request, keeping /health reachable even when MySQL
  is unreachable
- Workerman log/pid/status files are pinned to `.logs/workerman.log` and `var/hub.{pid,status}`
  (not the install root, which may be read-only under hardened systemd)
- Psalm CI step requires ext-swoole to resolve `\Swoole\Coroutine\Channel` (UndefinedClass
  at errorLevel 1 without it)

**Test command:**
```bash
php -d max_execution_time=0 ./vendor/bin/phpunit --colors=always
```
(composer script: `composer test` → `phpunit`; CI caps execution time at 0 to avoid
SIGALRM exit 142 on long suites with Xdebug coverage)

**Static analysis — PHPStan (level 9, no baseline):**
```bash
./vendor/bin/phpstan analyze --no-progress
```
(composer script: `composer stan`; CI uses `--error-format=github`)

**Static analysis — Psalm (errorLevel 1, no baseline):**
```bash
./vendor/bin/psalm --no-progress --show-info=false
```
(composer script: `composer psalm`; CI uses `--show-info=false`; requires ext-swoole in CI)

**Lint — PHPCS PSR-12:**
```bash
./vendor/bin/phpcs --standard=PSR12 -n --colors src/
```
(composer script: `composer cs`; CI uses `-n --colors` flags; any phpcs violations are
considered blocking)

**Build:** N/A — hub is a pure PHP daemon (no frontend asset pipeline)

**Migration:**
```bash
php bin/phlix migrate
```
(webman/console command; runs `MigrationRunner` against `migrations/*.sql`; idempotent via
`CREATE TABLE IF NOT EXISTS` / `ENGINE=InnoDB utf8mb4` / `CHAR(36)` PK)

**Deploy/verify:**
```bash
/root/update_hub.sh
```
(remote script on the hub box; pulls latest master and restarts the hub service on port :8800)

## Progress
- [x] HB-0.1  restore idle reaper  RE-AUDIT 2026-07-12: DONE (hub side) — sendHeartbeat no longer stamps lastFrameAt (only inbound paths do: onServerMessage/handleBinaryFrame/onHeartbeat); isStale reflects last INBOUND frame; reaper wired in maintenance worker; real tests pass (TunnelTest+IdleReaperTest). ⚠️ RUNTIME correctness depends on X9: server MUST emit an inbound frame (heartbeat/echo) within the 90s stale window or a healthy-but-quiet tunnel is false-reaped. → server X9 audit spawned.
- [x] HB-0.2  wire invite-link redeem route  RE-AUDIT 2026-07-12: DONE — POST /api/v1/me/invite-links/{token}/redeem auth-gated → controller → handler; double-redeem guarded by atomic conditional UPDATE (410 exhausted); 8 controller + concurrency/exhausted handler tests pass. (f057aaf, 3a7067d)
- [~] HB-0.3  short-circuit HEAD over relay proxy  RE-AUDIT 2026-07-12: PARTIAL. Chose Option 2 (HEAD excluded from isStreamingPath → buffered bridge->request()), BUT the buffered path only assembles/returns on KIND_END, so HEAD STILL waits for a server END chunk → still 30-60s stall if server withFile() HEAD emits no END. Blocked on X3 (server HEAD framing). Tests inadequate: no head-only/no-END anti-stall test, no HEAD-then-ranged-GET sub-second integration test. Minor: isStreamingPath docblock still says "GET/HEAD". → server X3 audit spawned; fix decision pending.
- [x] HB-0.4  add subdomain to ServerInfoDto, drop redundant query  RE-AUDIT 2026-07-12: DONE — subdomain on ServerInfoDto (shared + vendored), populated in rowToDto, redundant getServerSubdomain query removed (single getServerInfo path); DTO round-trip + controller relay_url tests pass. (shared:008fcc1, hub:489ec7d)
- [x] HB-1.1  drop base64 on internal channel-broker body path  (commits: e7ef677, 49913e7, d16b567)  DONE
- [x] HB-1.2  raw tunnel data-plane backpressure  (commits: 2a2b421, 0aed3e0, b1140b1)  DONE
- [x] HB-1.3  non-blocking onReply delivery  (commits: e3cb349, 8ea42ae, 8d45c85)  DONE
- [x] HB-1.4  lean owner/status queries on hot paths  (commits: c0461ed, 2f81f37, e5ce01e)  DONE
- [x] HB-2.1  request-body chunking over tunnel (enable bodied relay)  (commits: phlix-shared:216ea5d, phlix-hub:7b71c190)  DONE
- [x] HB-2.2  validate HELLO JWT before displacing incumbent tunnel  (commit: 7c30723)  DONE
- [x] HB-2.3  cap FrameDecoder buffer  (commit: ec17c9cc)  DONE
- [x] HB-2.4  O(1) cancel index  (commit: 371c17a)  DONE
- [x] HB-2.5  static-asset caching headers + realpath memo  (commit: 4644e72)  DONE
- [x] HB-2.6  dedicated maintenance worker for reapers  (commit: 4644e72)  DONE
- [x] HB-3.1  write-over-relay (PUT/DELETE/PATCH)  (commits: 6c7a4df, +test-fix c86d6e2)  DONE
- [x] HB-3.2  SyncPlay relay authentication ✅ (commit e5f4603) — authenticate in onWebSocketConnect, gate handleGroupJoin
- [x] HB-3.3  per-channel tunnel flow control/fairness  (commit: cbc29cc)  DONE
- [x] HB-3.4  bandwidth accounting + per-user quotas  (commit: 1de552a)  DONE
- [x] HB-4.1  relay observability metrics  (commit: H-W4-batch)  DONE — pending-request gauge, reply-drop counter, per-request latency histogram, 503/504 counters, decode-buffer-size gauge fully wired in MetricsCollector/Registry/FlushService + RelayProxyManager
- [x] HB-4.2  client_relay_tokens retention sweep  (commit: H-W4-batch)  DONE — pruneExpiredTokens() in ClientRelayTokenService, called from IdleReaper tick
- [x] HB-4.3  server_heartbeats growth control  (commit: H-W4-batch)  DONE — pruneAllServerHeartbeats()/pruneServerHeartbeats() ring-delete in HeartbeatHandler, called from IdleReaper tick
- [x] HB-4.4  heartbeat handler hash library list optimization  (commit: H-W4-batch)  DONE — SHA-256 library list hash in server_library_hashes table, skip upserts when unchanged; migration 037
- [x] HB-4.5  metrics prune singleton  (commit: H-W4-batch)  DONE — MetricsFlushService registered as per-worker singleton via static variable in factory
- [x] HB-4.6  rate limiting on proxy/client-mount/heartbeat/JWKS  (commit: H-W4-batch)  DONE — RateLimiterInterface injected into ServerProxyController, ClientMountController, HeartbeatHandler, HubJwksController
- [x] HB-4.7  Ed25519KeyManager in-memory previous-key cache  (commit: H-W4-batch)  DONE — unlink removed from loadPreviousKey hot path; purgeExpiredPreviousKey() available for background cleanup
- [x] HB-4.8  stream-timer sweep instead of per-second del+add  (commit: H-W4-batch)  DONE — batch SWEEP_INTERVAL_SECONDS timer replaces per-request timer delete+add; sweepStreamTimers() method
- [x] HB-4.9  verify/implement HTTP_CANCEL server-side stop  (commit: H-W4-batch)  DONE — already implemented; full cancel path verified: Bridge→Channel→ProxyManager→Tunnel::sendCancel()→server
- [x] HB-4.10 remove RelaySessionManager::routeRequest  (commit: H-W4-batch)  DONE — confirmed no callers; method removed; docstrings updated

## Re-baseline — Claude Code orchestrator pass (2026-07-12)

**Subagent capability:** git / phpunit / phpstan / phpcs all run OK, no prompts. **psalm CANNOT run**
on this box (PHP 8.3.6 < psalm's required 8.3.16) — workers must SKIP psalm; it's environmental, not
red. ext-swoole NOT loaded (did not affect any gate). => full-delegation model, workers self-verify
(phpunit+phpstan+phpcs) and commit+push themselves.

**Entrypoint correction:** hub has NO `public/index.php` — it is `start.php`-only (Workerman resident).
Ignore any dual-entrypoint mirroring for hub; there is a single entrypoint.

**MASTER HEALTH AT PASS START = GREEN:**
- PHPUnit full suite: 1218 tests / 15304 assertions, 0 errors, 0 failures, 17 self-skips. Lines 56%.
- PHPStan L9 (phpstan.neon.dist): No errors.
- PHPCS PSR-12 -n src/: clean.
- MigrationFileTest: 140 tests pass.
Prior (opencode) run committed all HB-0.x/1.x/2.x/3.x + SV-4.7. Green master means it compiles/passes
— but per plan §I each step still needs an acceptance/completeness/test-depth AUDIT this pass (green
tests can hide mock-encoded-wrong contracts — cf. the 0.4 auth-contract incident in memory).

## Notes / cross-repo blockers
- X1: Scrub→encode→cancel chain (UI-0.3 first, then SV-4.2, then HB-4.9)
- X3: RESOLVED 2026-07-12 — server-side confirmed: HEAD to a withFile() route emits HEAD→END(zero-body)
  →HTTP_CANCEL (RelayConsumer::sendHttpResponse END emitted unconditionally @:1010). So HB-0.3's buffered
  path DOES complete promptly; NO server change. Fix stays hub-side = add anti-stall tests + docblock.
- X9: RESOLVED 2026-07-12 — server-side confirmed SAFE: server sends HEARTBEAT every 30s (repeating
  timer, RelayConsumer::startHeartbeatTimer @:1517, RelayConfig::pingInterval default 30) + echoes hub
  heartbeats. 30s ≪ 90s reap window. HB-0.1 fully cleared. CAVEAT: document that hub reap window must
  stay > PHLIX_RELAY_PING_INTERVAL (default 30) or tunnels false-reap.
