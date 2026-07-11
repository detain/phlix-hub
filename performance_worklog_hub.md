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
- [ ] HB-0.1  restore idle reaper (remove lastFrameAt self-refresh)
- [ ] HB-0.2  wire invite-link redeem route
- [ ] HB-0.3  short-circuit HEAD over relay proxy
- [ ] HB-0.4  add subdomain to ServerInfoDto, drop redundant query
- [ ] HB-1.1  drop base64 on internal channel-broker body path
- [ ] HB-1.2  raw tunnel data-plane backpressure
- [ ] HB-1.3  non-blocking onReply delivery
- [ ] HB-1.4  lean owner/status queries on hot paths
- [ ] HB-2.1  request-body chunking over tunnel (enable bodied relay)
- [ ] HB-2.2  validate HELLO JWT before displacing incumbent tunnel
- [ ] HB-2.3  cap FrameDecoder buffer
- [ ] HB-2.4  O(1) cancel index
- [ ] HB-2.5  static-asset caching headers + realpath memo
- [ ] HB-2.6  dedicated maintenance worker for reapers
- [ ] HB-3.1  write-over-relay (PUT/DELETE/PATCH)
- [ ] HB-3.2  SyncPlay relay authentication
- [ ] HB-3.3  per-channel tunnel flow control/fairness
- [ ] HB-3.4  bandwidth accounting + per-user quotas
- [ ] HB-4.1  relay observability metrics
- [ ] HB-4.2  client_relay_tokens retention sweep
- [ ] HB-4.3  server_heartbeats growth control
- [ ] HB-4.4  heartbeat handler hash library list optimization
- [ ] HB-4.5  metrics prune singleton
- [ ] HB-4.6  rate limiting on proxy/client-mount/heartbeat/JWKS
- [ ] HB-4.7  Ed25519KeyManager in-memory previous-key cache
- [ ] HB-4.8  stream-timer sweep instead of per-second del+add
- [ ] HB-4.9  verify/implement HTTP_CANCEL server-side stop
- [ ] HB-4.10 remove RelaySessionManager::routeRequest

## Notes / cross-repo blockers
- X1: Scrub→encode→cancel chain (UI-0.3 first, then SV-4.2, then HB-4.9)
- X3: HB-0.3 needs server HEAD behavior confirmation
- X9: HB-0.1 waiting on server heartbeat-echo confirmation
