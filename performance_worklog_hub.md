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
- [x] HB-0.3  short-circuit HEAD over relay proxy  RE-AUDIT+COMPLETE 2026-07-12: DONE. X3 resolved (server emits HEAD→END zero-body → buffered path completes promptly; no server change). Added 3 anti-stall tests (RelayProxyManagerTest: buffered HEAD completes on END w/ zero body + HEAD-then-ranged-GET; ServerProxyControllerTest: end-to-end HEAD returns promptly, never 504). Fixed isStreamingPath docblock (GET-only streams). Full suite 1221 pass, phpstan clean, phpcs clean. Commit 6a4759d. HUB W0 COMPLETE 🎉
- [x] HB-0.4  add subdomain to ServerInfoDto, drop redundant query  RE-AUDIT 2026-07-12: DONE — subdomain on ServerInfoDto (shared + vendored), populated in rowToDto, redundant getServerSubdomain query removed (single getServerInfo path); DTO round-trip + controller relay_url tests pass. (shared:008fcc1, hub:489ec7d)
- [x] HB-1.1  drop base64 on internal channel-broker body path  RE-AUDIT 2026-07-12: DONE (code — raw body publish/decode, minimal payload; residual base64 is the REQUEST envelope = HB-2.1 territory, out of scope). Tests SHALLOW (ASCII-only; missing binary round-trip w/ NUL/0xFF/invalid-UTF-8). → optional test hardening queued. (e7ef677, 49913e7, d16b567)
- [~] HB-1.2  raw tunnel data-plane backpressure  FIX LANDED 2026-07-12 → RE-REVIEW spawned (Implementer) — drop hole closed on BOTH paths via re-queue+retry-on-drain (mirrors pendingHighPriorityFrames). sendToClient: per-client `pendingClientFrames[channelId]` re-queues the dropped DATA frame, `flushClientQueue` re-sends on that client's onBufferDrain BEFORE decrementing serverBackpressureCount/resuming server. sendToServer low-priority body: `pendingBodyFrames` re-queues, `flushBodyQueue` re-sends on serverWs onBufferDrain (control-first then body) BEFORE resuming clients. Both caps (256) → close('backpressure_overflow') hard-fail; existing close('backpressure_timeout') safety timer kept (extracted to testable named methods, Timer::add wrapped in try/catch like RelayProxyManager). removeClient releases a congested client's slot so it can't strand the pause. +4 tests (no-drop deliver-on-drain + timeout-close, client & server paths). Commits 728a843,5fedc5f. was PARTIAL (2a2b421, 0aed3e0, b1140b1). REVIEW-2 2026-07-12: 5 findings — the fix INTRODUCED issues on untested seams: #1 CONFIRMED reordering — high-priority server path (:719-733) lacks the enqueue-if-backlog guard the body/client paths have → a new control frame (HEARTBEAT/CANCEL/CLIENT_CONNECT/DISCONNECT) jumps ahead of queued frames (silent reorder). #2 CONFIRMED — high-priority overflow (:721-729) logs+returns (silent drop) instead of close('backpressure_overflow'). #3 lower-conf pre-existing — uncancelled one-shot safety timers test GLOBAL count → stale timer false-closes healthy tunnel (:984-998,:1080-1090). #4 test gap — no multi-frame FIFO / removeClient-release / overflow→close / high-priority-path coverage. #5 hygiene — pendingHighPriorityFrames not cleared in close()/notifyClientsDisconnected. → FIX-2 spawned (#1,#2,#3,#4,#5). FIX-2 LANDED 2026-07-12 → RE-REVIEW (review-3): all 5 fixed — #1 enqueue-if-backlog guard on high-priority path (FIFO preserved: flush control-then-body unchanged); #2 enqueueHighPriorityFrame close('backpressure_overflow'); #3 episode-scoped safety timers (arm on 0→1 / false→true, cancel on drain in armClientDrain+removeClient+server drain+close) so a stale timer can't false-close; #5 close() clears pendingHighPriorityFrames + cancels both timers. +7 tests (FIFO high-priority+backlog, client FIFO, removeClient release, multi-client 2→1→0, 3× overflow→close). Suite 1232 pass/17 skip, phpstan L9 clean, phpcs -n src/ clean. Commits 99fb814 (fix), c10d02b (tests).
- [x] HB-1.3  non-blocking onReply delivery  RE-AUDIT 2026-07-12: DONE, well-tested — push(...,0.0) non-blocking probe, full/closed → own Coroutine::create fiber (deliverReplyInFiber) so one stuck consumer blocks only its fiber; 3 substantive tests. No action. (e3cb349, 8ea42ae, 8d45c85)
- [x] HB-1.4  lean owner/status queries on hot paths  FIX 2026-07-12: DONE. Negative-cache defect closed — AuthMiddleware::userExists now stores the BOOLEAN probe result + timestamp (was a bare ts) and a cache hit returns the cached boolean (was unconditional true), so a deleted/revoked user is rejected for the whole TTL instead of bypassing auth.user_not_found; hot-path optimisation + short-TTL revocation preserved; static cache bounded (USER_EXISTS_CACHE_MAX). +9 tests: getOwnerAndStatus leanness (SQL no-COUNT + proxy/client-mount never()->getServerInfo), never()->findById on hot path, cache-TTL query-count + deleted-user regression guard + TTL-expiry re-probe. Suite 1243 pass/17 skip, phpstan L9 0, phpcs -n src/ clean. Prior wiring (c0461ed, 2f81f37, e5ce01e).
- [~] HB-2.1  request-body chunking over tunnel (enable bodied relay)  RE-AUDIT-2 2026-07-12 (post HB-1.2 fix-3): NOT-DONE — CROSS-REPO BLOCKER, X2 only HALF-landed. Hub side CORRECT (RelayHttpRequestCodec tag-byte HEAD/BODY/END; RelayProxyManager.php:288-319 chunks >64KB; 413 cap lifted; classifier now match($frame->type) → chunked frames flow LOW/pendingBodyFrames without throwing, proven by TunnelTest). BUT **phlix-server never built the request-reassembly half + is NOT repinned to the shared request codec** → a real >64KB bodied relay now fails 400-malformed (RelayConsumer::onHttpRequest @server:745-746 does RelayHttpRequest::fromJson unconditionally, no tag-byte branch/accumulator; server composer.lock detain/phlix-shared v0.19.0 has only RelayHttpResponseCodec). GAPS to close HB-2.1: (a) SERVER repin to shared ≥216ea5d; (b) SERVER onHttpRequest per-requestId chunk reassembly (mirror response side); (c) HUB test: onRequest(>64KB binary body incl NUL/0xFF) emits HEAD+N·BODY+END on one requestId (RelayProxyManager.php:300-318 = 0 coverage today); (d) integration round-trip (writable only after b). Hub unit-codec round-trip + Tunnel classification/ordering ALREADY tested. (commits: phlix-shared:216ea5d, phlix-hub:7b71c190; classifier fb9e7b7). → server task queued (SV-side of X2).
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

## Implementer — 2026-07-12 (HB-1.2 fix + tests)

**Confirmed bug (verified vs vendor):** `TcpConnection::send()` returns false on a full send buffer
and DROPS the package before appending (vendor `.../TcpConnection.php` bufferIsFull). Two Tunnel paths
returned on `send()===false` WITHOUT re-queueing → exactly one DATA/body frame lost per backpressure
episode = silent stream corruption (H-H3 forbids). The high-priority server-control path already
re-queued correctly (`pendingHighPriorityFrames` + `flushHighPriorityQueue` retry) — used as the model.

**Approach: re-queue + retry-on-drain (lossless), mirroring the high-priority machinery.** Chose this
over the `onBufferFull` proactive pause because it reuses the exact idiom already proven on the
control path and needs no new callback wiring.

**Files changed (absolute):**
- `/home/sites/phlix/phlix-hub/src/Relay/Tunnel.php`
  - CLIENT DATA path (`sendToClient`): on `sendRaw()===false`, the encoded frame is re-queued into a
    new per-client `pendingClientFrames[channelId]` (was: dropped) then backpressure applied. New
    `enqueueClientFrame` (bounded by `MAX_CLIENT_QUEUE=256` → `close('backpressure_overflow')`) and
    `flushClientQueue` (re-sends FIFO, byte-exact). `handleClientSendBackpressure` split: `armClientDrain`
    now flushes the client queue on `onBufferDrain` and only decrements `serverBackpressureCount` /
    resumes the server AFTER the queue fully drains; if the buffer refills mid-flush it re-arms and
    stays paused. If a client already has a backlog, new frames enqueue behind it (no reorder).
  - SERVER low-priority BODY path (`sendToServer`): on `send()===false`, the body frame is re-queued
    into a new `pendingBodyFrames` (was: dropped). New `enqueueBodyFrame` (bounded by `MAX_BODY_QUEUE=256`
    → `close('backpressure_overflow')`) and `flushBodyQueue`. If a control/body backlog already exists,
    the body frame enqueues behind it. `handleServerSendBackpressure`'s server `onBufferDrain` now
    flushes high-priority THEN body queue and only resumes clients once BOTH are empty (this also fixes
    a latent gap where queued control frames were only flushed on the next inbound body frame).
  - Safety timeouts kept intact but extracted into testable `handleClientBackpressureTimeout` /
    `handleServerBackpressureTimeout`; the `Timer::add(..., [], false)` one-shots are wrapped in
    try/catch (mirrors `RelayProxyManager`) so they no-op outside a Workerman loop (tests) — one-shot
    flag preserved.
  - `removeClient`: releases a congested client's backpressure slot + drops its queue so a disconnect
    can't strand the server in a permanently-paused state / leak queued frames.
  - `notifyClientsDisconnected` / `close`: reset the new queues.
- `/home/sites/phlix/phlix-hub/tests/Unit/Relay/TunnelTest.php` — +4 tests (both paths):
  (a) zero-drop: re-queued frame delivered byte-exact after drain; (b) opposite side paused on fill;
  (c) resumed on drain; (d) tunnel closes with `backpressure_timeout` if drain never comes.

**Invariant now guaranteed:** no DATA/body frame is discarded — a slow reader pauses upstream and every
frame is delivered on drain; if drain never comes the existing timeout `close('backpressure_timeout')`
(or the queue-cap `close('backpressure_overflow')`) is the hard, visible last resort.

**Verify:** phpunit full suite 1225 pass / 15351 assertions / 17 skipped (baseline 1221 + 4 new);
phpunit `--filter Tunnel` 62 pass; phpstan L9 no errors; `phpcs --standard=PSR12 -n src/` clean.
(psalm skipped — box PHP 8.3.6 < psalm required.)

## Reviewer (per-step, RE-REVIEW / REVIEW-2) — HB-1.2 — 2026-07-12

Range `b1140b1..5fedc5f` (728a843 fix + 5fedc5f tests). Verified: `phpunit --filter Tunnel`
green (62 tests / 187 assertions); `phpstan analyze` 0 errors. Base b1140b1 confirmed to have NO
priority/queue machinery — the entire re-queue + high/low priority split is introduced by this fix,
so all of it is in scope. AC (no silent drop, deliver-on-drain, pause/resume, timeout-close) met on
the two paths the tests exercise. Findings below; #1 CONFIRMED, most severe first.

1. **CONFIRMED (Medium) — reordering hole on the HIGH-PRIORITY server path. `src/Relay/Tunnel.php:719-733`.**
   The body path (`:746`) and the client path (`:807`) both guard "if a backlog already exists,
   enqueue instead of sending" to preserve FIFO. The high-priority branch does NOT: it calls
   `$this->serverWs->send($encoded)` directly with no `!empty($this->pendingHighPriorityFrames)`
   check. Workerman's `onBufferDrain` fires only when the send buffer reaches EMPTY, but `send()`
   starts succeeding again as soon as the buffer drops below the high-watermark. So in the window
   between a failed send (frames H1,H2 queued) and the drain callback, a newly generated
   high-priority frame H3 (HEARTBEAT from the timer, CLIENT_CONNECT from a new mount, CLIENT_DISCONNECT,
   CANCEL, or — once HB-2.1 request-chunking is live — an HTTP_REQUEST head/end) will `send()`
   successfully and land on the wire AHEAD of the still-queued H1,H2. These control frames are
   generated independently of client `pauseRecv`, so congestion does not stop them. Why it matters:
   the tunnel assumes reliable, in-order delivery ("seq is not an ack"); the server processes control
   frames in arrival order, so a CLIENT_CONNECT/CLIENT_DISCONNECT pair reordered on one channel, or a
   CANCEL overtaking its request, corrupts server-side channel/request state. This trades the old
   silent-drop bug for a lower-frequency silent-reorder bug on the control path.
   Fix direction: mirror the body/client guard — at the top of the `if ($isHighPriority)` block,
   `if (!empty($this->pendingHighPriorityFrames)) { <enqueue-or-overflow>; $this->handleServerSendBackpressure(); return; }`
   before attempting the direct `send()`.

2. **CONFIRMED (Low-Med) — high-priority queue overflow SILENTLY DROPS a control frame. `src/Relay/Tunnel.php:721-729`.**
   On `count(pendingHighPriorityFrames) >= MAX_HIGH_PRIORITY_QUEUE` the code logs
   `warning('...dropping control frame')`, calls `handleServerSendBackpressure()` and returns — the
   frame is neither queued nor sent, i.e. dropped. The body path (`enqueueBodyFrame`, `:137-144`) and
   client path (`enqueueClientFrame`, `:847-855`) instead `close('backpressure_overflow')` — a hard,
   visible failure. Dropping a CANCEL or CLIENT_DISCONNECT reintroduces exactly the reliable-delivery
   silent-drop that HB-1.2 exists to eliminate (a dropped CLIENT_DISCONNECT strands a server-side
   channel; a dropped CANCEL leaks an in-flight request). Fix direction: on high-priority overflow,
   `close('backpressure_overflow')` for consistency rather than dropping.

3. **Lower-confidence / PRE-EXISTING (retained + refactored by this fix) — cross-episode false tunnel close.
   `src/Relay/Tunnel.php:984-998` (`handleClientBackpressureTimeout`), `:1080-1090` (`handleServerBackpressureTimeout`).**
   The one-shot safety timers are armed per congestion episode but never cancelled on drain, and their
   bodies test the GLOBAL `serverBackpressureCount > 0` / `clientBackpressureActive`. Sequence: client A
   congests (count=1, Timer_A armed for t+30); A drains at t+5 (count=0, Timer_A still pending); client B
   congests at t+10 (count=1); at t+30 Timer_A fires, sees count>0 (because of B) and
   `close('backpressure_timeout')` — killing a tunnel whose only current congestion (B) is well within
   its own budget. This closes/flaps playback for all clients on that server. Not a regression (base had
   the same global-count check, and worse count bookkeeping), but the fix refactored this exact code and
   left it. Fix direction: cancel the timer on drain (store the timer id, `Timer::del` in the drain
   handler) and/or make the timeout per-client/per-episode rather than gated on the global counter.

4. **Test-coverage gap (the fix's own high-risk paths are unverified). `tests/Unit/Relay/TunnelTest.php:801-980`.**
   The 4 new cases prove single-frame deliver-on-drain (byte-exact) + timeout-close on the client DATA
   and server BODY paths only. They do NOT cover, and therefore would NOT catch a regression in, the
   failure modes this fix is most exposed to: (a) FIFO ordering of ≥2 queued frames on one channel — a
   reordering regression (incl. finding #1) passes silently; (b) `removeClient` releasing a congested
   client's backpressure slot and resuming the server (`:1190-1199`) — the anti-stranding path, entirely
   untested; (c) `MAX_CLIENT_QUEUE`/`MAX_BODY_QUEUE` overflow → `close('backpressure_overflow')`;
   (d) multi-client `serverBackpressureCount` semantics (two congested clients, resume only after both
   drain); (e) the high-priority server path at all. Recommend adding at least (a), (b), (c).

5. **Minor / hygiene — `pendingHighPriorityFrames` not cleared on teardown. `src/Relay/Tunnel.php:1256-1301` (`close`), `:1232-1247` (`notifyClientsDisconnected`).**
   `close()` clears `pendingBodyFrames` and `notifyClientsDisconnected` clears `pendingClientFrames`,
   but nothing clears `pendingHighPriorityFrames`. The Tunnel is discarded by TunnelManager after close
   so GC reclaims it (not a true leak), but for consistency with the sibling queues it should be reset
   in `close()`.

NO other issues: async discipline OK (`Timer::add(..., [], false)` one-shot, wrapped in try/catch for
test harness; no `sleep`/`exit`/`die`; no request data in static/global; queues bounded by 256 caps);
`removeClient` decrement invariant holds (`isset(pendingClientFrames[channelId])` ⟺ this client
contributed +1 to the count; `onBufferDrain` nulled to prevent double-decrement); `close()` idempotent
via the `STATUS_CLOSED` guard; `onBufferDrain` registration is per-connection and does not clobber a
handler the tunnel relies on.

## Implementer — FIX-2 (HB-1.2 review-2 findings) — 2026-07-12

All 5 REVIEW-2 findings fixed in `src/Relay/Tunnel.php` + tests built out. Full suite green
(1232 pass / 15386 assertions / 17 skips, +7 new), phpstan L9 0 errors, phpcs PSR-12 `-n src/` clean.
(psalm skipped — box PHP 8.3.6 < psalm required.)

**#1 reordering (the important one) — enqueue-if-backlog guard on the high-priority path.**
`sendToServer` high-priority branch (~:730): before the direct `serverWs->send()`, added
`if (!empty($this->pendingHighPriorityFrames)) { enqueueHighPriorityFrame($frame); handleServerSendBackpressure(); return; }`
— now mirrors the body path (:756) and client path (:817). A control frame generated while a control
backlog exists (send() succeeds again at the high-watermark but onBufferDrain fires only at EMPTY) is
enqueued at the tail of `pendingHighPriorityFrames` instead of jumping onto the wire ahead of the
still-queued frames. FIFO across the two server queues on the SAME connection is preserved by the
existing flush order: `handleServerSendBackpressure`'s drain handler runs `flushHighPriorityQueue()`
FIRST and only calls `flushBodyQueue()` once the control queue is empty (unchanged) — so control frames
keep strict enqueue order among themselves AND always precede queued body frames (the HB-3.3 intent),
while no late control frame overtakes an earlier-queued one.

**#2 silent overflow drop → hard close.** New `enqueueHighPriorityFrame()` (sibling of
`enqueueBodyFrame`/`enqueueClientFrame`): on `count >= MAX_HIGH_PRIORITY_QUEUE` it logs `error` and
`close('backpressure_overflow')` instead of the old log-and-return that dropped the control frame. A
dropped CANCEL/CLIENT_DISCONNECT can no longer strand server channel/request state.

**#3 cross-episode false close — episode-scoped, cancel-on-drain safety timers.** Added
`?int $clientBackpressureTimerId` / `?int $serverBackpressureTimerId` + `arm*/cancel*BackpressureTimer()`
helpers. The client timer is armed exactly once, when `serverBackpressureCount` goes 0→1 (in
`handleClientSendBackpressure`), and cancelled (`Timer::del`) the moment the count returns to 0 — in the
`armClientDrain` drain handler AND in `removeClient`. The server timer is armed once when
`clientBackpressureActive` goes false→true (in `handleServerSendBackpressure`, so drain-handler re-arms
while still congested do NOT re-arm it) and cancelled when the server fully drains. Both are also
cancelled in `close()`. Result: a timer only ever belongs to the still-open congestion episode, so a
stale timer from a drained episode can no longer fire and `close('backpressure_timeout')` a
within-budget tunnel. `handleClientBackpressureTimeout()` lost its now-meaningless `$client` param
(episode-scoped, logs no single client id). `Timer::add` returns `int` in this Workerman build, so the
id is stored directly (verified via phpstan `alreadyNarrowedType`).

**#5 hygiene.** `close()` now clears `pendingHighPriorityFrames` alongside the sibling `pendingBodyFrames`
(both server-side queues) and cancels both timers; the client queue keeps being cleared in
`notifyClientsDisconnected`. No stale-frame resend if a Tunnel object is reused.

**#4 tests — `tests/Unit/Relay/TunnelTest.php` (+7, all deterministic; fake TcpConnection whose send()
returns false while "full", onBufferDrain invoked manually; no sleeps):**
- `test_high_priority_frames_preserve_fifo_and_never_overtake_backlog` — the #1 guard: queues H1, sets
  buffer un-full, sends H2 (must NOT overtake), queues a body behind them; asserts drain delivers
  CTRL-1, CTRL-2, then the body in exactly that order. A reorder regression fails this.
- `test_client_queue_delivers_multiple_frames_in_fifo_order` — client-path FIFO with 2 queued frames.
- `test_remove_client_releases_backpressure_slot_and_resumes_server` — anti-stranding: count 1→0 and
  server resumed after a congested client is removed.
- `test_two_congested_clients_resume_server_only_after_both_drain` — multi-client count semantics
  (2→1→0; pauseRecv once, resumeRecv once).
- `test_client_queue_overflow_closes_tunnel` / `test_body_queue_overflow_closes_tunnel` /
  `test_high_priority_queue_overflow_closes_tunnel` — MAX_*_QUEUE+1 → `close('backpressure_overflow')`
  (the #2 guard for the high-priority path).
- Updated the existing `test_client_backpressure_timeout_closes_tunnel` to invoke the now-param-less
  `handleClientBackpressureTimeout()`.

## Reviewer (per-step, RE-REVIEW / REVIEW-3, read-only) — HB-1.2 fix-2 — 2026-07-12

Range 99fb814 (fix) + c10d02b (tests). Verified: `phpunit --filter Tunnel` green (69 tests /
222 assertions); `phpstan analyze` 0 errors.

**Fix-2's 5 review-2 findings are all correctly resolved** (no functional regression in any exercised
path):
- #1 high-over-high reorder — CLOSED. `sendToServer` high-priority branch (:775) enqueues when
  `pendingHighPriorityFrames` is non-empty before any direct `send()`; `flushHighPriorityQueue` uses
  array_shift/array_unshift-on-fail (no loss, no infinite loop, refill-safe). Test
  `test_high_priority_frames_preserve_fifo_and_never_overtake_backlog` truly guards it: with buffer
  un-full, CTRL-2 must stay queued — reverting the guard flips that assertion to a direct send. ✔
- #2 high-priority overflow — CLOSED. `enqueueHighPriorityFrame` (:638-650) `close('backpressure_overflow')`
  on MAX; test covers. ✔
- #3 episode-scoped timers — CORRECT. client timer armed only on count 0→1, cancelled on →0 in the
  drain handler (:1054), removeClient (:1322 path) and close(); server timer armed only on
  active false→true, cancelled on full drain (:1144) and close(); re-arm-while-congested does NOT
  duplicate (guarded by the 0/false predicate); Timer::del null-guarded + try/catch; one-shot flag
  intact. No leak / no armed-after-episode path found. ✔
- #4 tests / #5 hygiene — present and adequate as Tunnel-unit tests; close() clears both server queues
  + cancels both timers. ✔

Verdict: **fix-2 introduced no new functional defect in the paths its 5 findings cover.** However, per
review checklist items (b)/(4)/(5) I scrutinized the shared HB-2.1/HB-3.3 seam the body-priority path
lives on and found real bugs. 3 findings, most severe first.

1. **CONFIRMED (High) — the body-priority path fix-2 hardened is DEAD in production; the new tests mask
   it with a wrong-contract payload. PRE-EXISTING (HB-3.3+HB-2.1 seam), not introduced by fix-2.**
   `src/Relay/Tunnel.php:718-728` (`isHighPriorityFrame`) classifies via
   `json_decode($frame->payload, true, 3, JSON_THROW_ON_ERROR)` and reads `$decoded['kind']`. But real
   chunked HTTP_REQUEST frames are emitted by `src/Relay/RelayProxyManager.php:300-318` using
   `RelayHttpRequestCodec::encode{Head,Body,End}()`, whose wire format is a **leading tag byte**
   (`chr(0x01)|chr(0x02)|chr(0x03)` + data), NOT `{"kind":...}` JSON. `json_decode` throws
   `JsonException` on all three (verified empirically: "Control character error"). `sendToServer:748`
   calls `isHighPriorityFrame` first thing and `onRequest` (RelayProxyManager:197-337) has no try/catch
   around the sends → **every chunked (large-body, encoded >65535B) relay request throws uncaught**, i.e.
   the HB-2.1 request-chunking / HB-3.1 bodied write-over-relay path is broken. Consequence for fix-2:
   no production frame is ever classified LOW, so `pendingBodyFrames` (the queue fix-2 made lossless +
   overflow-safe) is unreachable, and the reorder guard's body branch never fires. The new tests
   construct body frames as `json_encode(['kind'=>'body'])` (:1032/1234-area, `test_*body*`) — a payload
   that classifies LOW without throwing — so they validate the machinery against a contract that does
   not match the wire codec (the "mock-encoded-wrong-contract" trap the re-baseline notes call out).
   Why it matters: the central HB-1.2 goal (lossless body backpressure) is not actually exercised on the
   real data plane, and large bodied relays fault. Fix direction (HB-3.3/HB-2.1 owner): classify by the
   codec tag, `ord($frame->payload[0]) === RelayHttpRequestCodec::TAG_BODY` (with a try/catch defaulting
   to high-priority), and rewrite the body tests to use `RelayHttpRequestCodec::encodeBody()`.

2. **CONFIRMED (Medium) — intra-request END-overtakes-BODY reorder on the same channel. PRE-EXISTING,
   currently masked by #1.** The #1 reorder guard added by fix-2 (`Tunnel.php:775`) checks only
   `pendingHighPriorityFrames`, not `pendingBodyFrames`; and the drain flush is unconditionally
   control-before-body (`flushHighPriorityQueue` then `flushBodyQueue`, :1129-1132). For a single chunked
   request HEAD(high)/BODY…(low)/END(high) on one channel, a directly-sent or flushed END lands ahead of
   still-queued BODY chunks → the server sees END before the body → truncated/corrupt request body. The
   per-queue FIFO guards protect each queue in isolation but the cross-class ordering within one request
   is not preserved — control-priority is only safe as *cross-request* fairness, not within a request.
   Only unreachable today because #1 crashes the chunked path first; fixing #1 makes this live. Fix
   direction: keep head/body/end of one channel/request in a single per-channel FIFO rather than splitting
   across priority classes (scope control-priority to inter-request fairness).

3. **CONFIRMED (Low) — safety timer re-armed on a just-closed tunnel (partially new via fix-2's #2).**
   When `enqueueHighPriorityFrame`/`enqueueBodyFrame`/`enqueueClientFrame` trigger
   `close('backpressure_overflow')`, control returns to `sendToServer:777/783` / `sendToClient:872`,
   which then call `handle{Server,Client}SendBackpressure()` on the now-closed tunnel — status is not
   re-checked. Because `close()` emptied `clientConnections` and set `clientBackpressureActive=false`,
   `handleServerSendBackpressure` re-enters its first-frame block, sets `clientBackpressureActive=true`
   again and `armServerBackpressureTimer()` **re-arms a 30s one-shot timer that close() just cancelled** —
   directly against fix-2's own #3 goal ("no stale timer fires on a discarded tunnel"), leaving a stray
   `onBufferDrain` + a `true` flag on a discarded object and keeping it alive ~30s. Not corruption (the
   timeout handler self-guards on `STATUS_CLOSED`; connections are already torn down), and body/client
   overflow paths shared the wart pre-fix-2, but the high-priority overflow-close path is new in fix-2.
   Fix direction: `if ($this->status !== self::STATUS_ACTIVE) { return; }` immediately after each
   `enqueue*Frame()` call (or early-return on non-ACTIVE status at the top of `handle*SendBackpressure`).

NO other issues: timer lifecycle (leak / arm-after-episode / double-arm / null-guard) verified clean;
`removeClient` release + multi-client count semantics correct and tested; `close()` idempotent; async
discipline OK (one-shot `Timer::add(...,[],false)` in try/catch, no sleep/exit/die, no request data in
static/global, queues bounded at 256).

## Implementer — FIX-3 (HB-1.2 review-3 findings + HB-2.1 seam) — 2026-07-12

All 3 review-3 findings fixed in `src/Relay/Tunnel.php`; the masking body tests rewritten to the REAL
`RelayHttpRequestCodec` wire format + 2 new tests. Full suite green (1234 pass / 15405 assertions /
17 skips, +2 net new), phpstan L9 0 errors, phpcs PSR-12 `-n src/` clean. (psalm skipped — box PHP
8.3.6 < psalm required.)

**#1 (High) — classify by frame TYPE, never json_decode a payload. `Tunnel.php:718-755` (`isHighPriorityFrame`).**
The old body did `json_decode($frame->payload, …, JSON_THROW_ON_ERROR)` expecting `{"kind":…}`. Real
chunked `HTTP_REQUEST` sub-frames carry tag-byte `RelayHttpRequestCodec` payloads
(`chr(0x01|0x02|0x03).data`) → `json_decode` THREW `JsonException` → uncaught at `sendToServer` →
every chunked (>65535-byte encoded) bodied relay faulted (HB-2.1/HB-3.1 bodied writes broken; HB-1.2's
body queue unreachable in prod). NEW body is a pure `match ($frame->type)` type switch — the payload is
now NEVER parsed. HIGH = genuine out-of-band control types only: HEARTBEAT, HTTP_CANCEL, CLIENT_CONNECT,
CLIENT_DISCONNECT. Everything else (all HTTP_REQUEST single/HEAD/BODY/END + DATA bulk) = LOW (body FIFO).
Cannot throw on any payload shape.

**#2 (Medium) — within-request ordering (END never overtakes BODY). Resolved by #1 + verified.**
Because #1 puts every HTTP_REQUEST sub-frame in the SAME (LOW) class, HEAD/BODY/END of one request all
land in the single `pendingBodyFrames` FIFO. The low-priority send path already guards
`if (!empty(pendingHighPriorityFrames) || !empty(pendingBodyFrames)) enqueueBodyFrame()` (`:798`), so once
any sub-frame is queued the rest enqueue behind it; `flushBodyQueue` re-sends in `array_shift` FIFO order
→ END can never be flushed ahead of a queued BODY. True out-of-band control (HEARTBEAT/CANCEL) may still
interleave between request sub-frames — harmless, the server routes stream frames by type+request-id, and
only same-request relative order matters. New test
`test_chunked_request_head_body_end_deliver_in_order_under_backpressure` proves HEAD→BODY→BODY→END
delivery order under a full buffer.

**#3 (Low) — no backpressure re-arm after an overflow close. `Tunnel.php` guard at top of
`handleClientSendBackpressure` and `handleServerSendBackpressure`.** After an `enqueue*Frame()` overflow
triggers `close('backpressure_overflow')` (which cancels both safety timers + tears down connections),
control returned to `sendToServer:781/787/810` / `sendToClient` and called `handle*SendBackpressure()`,
re-entering the first-frame block → `pauseRecv` + `armServerBackpressureTimer()` re-armed a 30 s one-shot
timer on a discarded tunnel (directly against fix-2's #3). FIX: `if ($this->status !== STATUS_ACTIVE)
{ return; }` at the very top of both handlers — a single choke point covering every enqueue call site;
no stale timer/pauseRecv is left on a closed tunnel.

**Tests — masking removed + real-codec coverage. `tests/Unit/Relay/TunnelTest.php`.**
- Rewrote the 4 fix-2 body/overflow tests from `json_encode(['kind'=>'body'])` (a contract that did NOT
  match the wire codec — exactly why #1 was masked) to `RelayHttpRequestCodec::encodeBody()` tag-byte
  payloads, so they now exercise the PRODUCTION classification path (which classifies HTTP_REQUEST LOW).
- Added `test_is_high_priority_frame_classifies_by_type_without_decoding_payload`: proves the classifier
  does NOT throw and returns false for real tag-byte HEAD/BODY/END (incl. binary/NUL/0xFF body) + DATA,
  and true only for the 4 genuine control types.
- Added the within-request ordering test above.
- Existing FIFO/overflow/timer/removeClient tests remain green (HTTP_CANCEL is still HIGH under the new
  type switch, so those cases are unchanged).

**HB-2.1 re-audit flag:** #1 means HB-2.1 (request-body chunking / bodied relay) was BROKEN in production
end-to-end — any chunked bodied request faulted before this fix, so HB-2.1's acceptance (">64 KB body
succeeds, not 413") was never actually met on the live data plane despite green unit tests. HB-2.1 should
be RE-AUDITED / re-verified (the fault was in the hub tunnel classifier that HB-2.1's chunked path feeds,
not in HB-2.1's own codec/RelayProxyManager code, which is correct). Recommend an integration-level check
of a real >64 KB bodied relay round-trip now that the classifier no longer faults.

Commits: fb9e7b7 (#1 classify by type, no json_decode), ed008c8 (#3 no re-arm after overflow close),
+ the tests commit (real RelayHttpRequestCodec tag-byte frames + within-request ordering).

## Reviewer (REVIEW-4) — HB-1.2 — 2026-07-12

Reviewed FIX-3 commits fb9e7b7 (#1 classify by type), ed008c8 (#3 no re-arm after
overflow close), 287b635 (tests) against current `src/Relay/Tunnel.php` +
`tests/Unit/Relay/TunnelTest.php`.

**NO FINDINGS**

All 3 REVIEW-3 findings are genuinely resolved and no new defect was introduced:

- **#1 (High) — CLOSED.** `isHighPriorityFrame` (`Tunnel.php:732-742`) is now a pure
  `match ($frame->type)` on the `RelayFrameType` enum with a `default => false` arm.
  The payload is never touched, so it cannot throw on any shape (tag-byte
  HEAD/BODY/END, binary, empty). Verified against the real enum (`phlix-shared`
  `RelayFrameType`): HIGH = HEARTBEAT/HTTP_CANCEL/CLIENT_CONNECT/CLIENT_DISCONNECT;
  everything else (HTTP_REQUEST single-frame JSON envelope + chunked HEAD/BODY/END
  tag-byte sub-frames + DATA) = LOW. Traced every producer into `sendToServer`
  (RelayProxyManager:289/300-318 HTTP_REQUEST; Tunnel internal
  sendClientData=DATA, CLIENT_CONNECT:1312, CLIENT_DISCONNECT:1376,
  HEARTBEAT:1484, HTTP_CANCEL:1509) — no genuine control frame is misclassified
  LOW and no stream frame is misclassified HIGH. The chunked bodied-relay fault is
  gone. `sendToClient` is DATA-only and never calls the classifier, so the change
  scope is correct.

- **#2 (Medium) — CLOSED.** Because every HTTP_REQUEST sub-frame is now the same
  (LOW) class, HEAD/BODY/END of one request share the single `pendingBodyFrames`
  FIFO; the low-priority enqueue-if-backlog guard (`:811`) + `flushBodyQueue`
  `array_shift` FIFO order guarantee END can never overtake a queued BODY. The new
  `test_chunked_request_head_body_end_deliver_in_order_under_backpressure` proves
  HEAD→BODY→BODY→END delivery order under a full buffer. Genuine control frames may
  still interleave between sub-frames — harmless (server demuxes by type+request-id;
  only same-request relative order matters).

- **#3 (Low) — CLOSED.** `if ($this->status !== self::STATUS_ACTIVE) { return; }`
  added at the very top of both `handleClientSendBackpressure` (`:973`) and
  `handleServerSendBackpressure` (`:1123`). This is a single correct choke point:
  the only way to reach these handlers with a non-ACTIVE status is an
  `enqueue*Frame()` overflow that `close()`d the tunnel in the same call
  (`sendToServer`/`sendToClient` already early-return on non-ACTIVE at entry), so no
  stale `pauseRecv`/one-shot timer is left armed on a discarded tunnel, and no
  legitimate backpressure episode is skipped.

**Tests exercise the real production path.** The 4 fix-2 body/overflow tests were
rewritten from `json_encode(['kind'=>'body'])` to `RelayHttpRequestCodec::encodeBody()`
tag-byte payloads, and `test_is_high_priority_frame_classifies_by_type_without_decoding_payload`
asserts the classifier does not throw and returns false for real HEAD/BODY/END (incl.
NUL/0xFF binary) + DATA, true only for the 4 control types. No mock-encoded-wrong-contract
remains.

**Acceptance criteria met:** no silent DATA-frame drop (re-queue + bounded-256 queue →
`close('backpressure_overflow')` hard-fail; timeout close retained); slow reader pauses
upstream and resumes on drain; no silent truncation or within-request reorder.

**Async/resident-memory (§0.4):** classifier no longer throws; no sleep/exit/die; queues
bounded at 256; one-shot safety timers `Timer::add(...,[],false)` cancel-on-drain and on
close; no request data in static/global. Scope clean (only `src/Relay/Tunnel.php` +
`tests/Unit/Relay/TunnelTest.php` touched).

Verification (this box, PHP 8.3.6 + PCOV):
- `php -d max_execution_time=0 ./vendor/bin/phpunit --filter Tunnel` → **OK (71 tests, 241 assertions)**.
- `./vendor/bin/phpstan analyze --no-progress` → **[OK] No errors** (L9, no baseline).
- `./vendor/bin/phpcs --standard=PSR12 -n src/` → **clean (exit 0)**.
- psalm skipped (box PHP 8.3.6 < psalm-required 8.3.16 — environmental, not red).

Verdict: **HB-1.2 DONE** (pending the standard Docs cycle). HB-2.1 still needs its
flagged end-to-end re-audit (a real >64 KB bodied relay round-trip) now that the
classifier fault is fixed.

## Fixer — HB-1.4 — 2026-07-12

Closed the security defect + the three test gaps the RE-AUDIT flagged. The wiring
(`getOwnerAndStatus` omitting the COUNT subquery; used by proxy-admission + WS client-mount;
`AuthMiddleware` using the lean `userExists` probe + `$request->userId` from the validated token)
was already correct — only the negative-cache bug and missing tests remained.

**DEFECT FIXED — `AuthMiddleware::userExists` negative cache (deleted user bypassed the gate).**
`src/Http/Middleware/AuthMiddleware.php`. The old cache stored a bare unix timestamp for BOTH an
existing and a non-existing user, and a cache HIT returned `true` unconditionally. So a
deleted/revoked user was rejected on the first request (cache miss → probe → false) but silently
re-admitted on requests #2..N for the whole 5 s TTL (cache hit → `true`). FIX: the cache now stores
the BOOLEAN probe result with its timestamp (`array{exists: bool, at: int}`), and a cache hit
returns the cached boolean — never an unconditional `true`. A negative result is honoured for the
TTL window exactly like a positive one, so a user that probes as non-existent stays rejected. The
hot-path optimisation (skip the probe for the common existing-user case) is preserved; revocation
latency is bounded by the short `USER_EXISTS_CACHE_TTL` (5 s). Also bounded the per-worker static
cache with `USER_EXISTS_CACHE_MAX` (10000) — clear-on-overflow — so a churn of distinct ids in a
resident Workerman worker can't grow it without limit (no unbounded static state per §0.4).

**TEST GAP 1 — getOwnerAndStatus leanness (proxy + client-mount do NOT run getServerInfo).**
- `tests/Unit/Hub/ServerInfoHandlerTest.php`: `testGetOwnerAndStatusIsLeanWithNoLibraryCountSubquery`
  captures the SQL and asserts it selects only `s.id, s.user_id, s.status` + keeps the fresh
  `EXISTS(` relay-active probe, but contains NO `COUNT(` / `server_libraries` / `library_count`;
  plus shape + null-when-not-found tests.
- `tests/Unit/Http/Controllers/ServerProxyControllerTest.php`:
  `test_proxy_uses_lean_owner_query_and_never_full_getServerInfo` — `expects(once())->getOwnerAndStatus`
  and `expects(never())->getServerInfo` on the per-request proxy admission path.
- `tests/Unit/Relay/ClientRelayWorkerTest.php`:
  `testValidateClientAuthUsesLeanOwnerQueryNotFullServerInfo` — same `once()/never()` assertions on
  the WS client-mount gate (added an optional `$serverInfo` arg to the test's `buildContainer`).

**TEST GAP 2 — never()->findById on the auth hot path.**
`tests/Unit/Http/Middleware/AuthMiddlewareTest.php`: `testHotPathNeverLoadsFullUserRow` proves the
full user-row load is skipped when only existence is needed (`expects(never())->method('findById')`).

**TEST GAP 3 — cache-TTL query-count + the negative-cache regression guard.**
`tests/Unit/Http/Middleware/AuthMiddlewareTest.php`:
- `testExistenceProbeIsCachedWithinTtl` — 5 requests within the window ⇒ `userExists` probed exactly
  ONCE.
- `testDeletedUserIsRejectedForWholeTtlAndProbedOnce` — THE regression guard for the defect: a
  deleted user (probe → false) is rejected 401 `auth.user_not_found` on all 5 requests in the window
  (before the fix, #2..N were admitted) and probed exactly once (negative cached).
- `testExistenceReprobedAfterTtlAndCatchesDeletedUser` — back-dates the cache entry via reflection
  (no blocking sleep) to prove the gate re-evaluates after TTL expiry and catches a now-deleted user
  (`willReturnOnConsecutiveCalls(true, false)` ⇒ authorized then rejected; exactly two probes).

**Verify (this box, PHP 8.3.6 + PCOV):**
- `phpunit --filter 'AuthMiddleware|ServerInfoHandler|ServerProxyController|ClientRelayWorker'` →
  **OK (175 tests, 514 assertions)**.
- Full suite `php -d max_execution_time=0 ./vendor/bin/phpunit` → **OK, 1243 tests / 15459 assertions
  / 17 skipped / 0 failures** (baseline 1234 + 9 new).
- `phpstan analyze --no-progress` → **[OK] No errors** (L9, no baseline).
- `phpcs --standard=PSR12 -n src/` → **clean (exit 0)**.
- psalm skipped (box PHP 8.3.6 < psalm-required 8.3.16 — environmental).

## Reviewer — HB-1.4 — 2026-07-12

NO FINDINGS.

Reviewed the security FIX (commits de87263 fix + 4b2879e tests) against the current source.

Security correctness (CONFIRMED closed):
- `src/Http/Middleware/AuthMiddleware.php:221-251` — the cache stores `array{exists: bool, at: int}`
  and a cache HIT within TTL returns `$entry['exists']` (line 231), NEVER an unconditional `true`.
  A user that probes `false` stays rejected for the whole TTL window; there is no code path that
  positively-caches a now-deleted user as authorized. `challenge('auth.user_not_found')` fires on
  every request in the window for a deleted user. Revocation latency is bounded by
  `USER_EXISTS_CACHE_TTL = 5`; on TTL expiry the entry is stale and re-probed (lines 226, 239).
- The negative result is written the same way as a positive one (line 250), so the gate is symmetric.

Bounded static cache (§0.4 no-unbounded-static-growth):
- `USER_EXISTS_CACHE_MAX = 10000`, clear-on-overflow (lines 243-248) only when inserting a NEW id at
  the cap. Clear-on-overflow at worst forces a re-probe of live entries — correctness-safe (a cleared
  positive re-probes to positive; a cleared negative re-probes to negative). No incorrect admit.

Leanness (H-D3/H-W2/H-W6):
- `ServerInfoHandler::getOwnerAndStatus` (`src/Hub/ServerInfoHandler.php:92-124`) selects only
  `s.id, s.user_id, s.status` + the fresh `EXISTS(relay_sessions)` probe; NO `COUNT(*)` /
  `server_libraries` / `library_count`. The heavy COUNT subquery lives only in `getServerInfo`.
- `ServerProxyController::proxy` (`:397`) and `ClientRelayWorker::validateClientAuth` (`:449`) both
  call `getOwnerAndStatus` and NOT `getServerInfo`.
- `AuthMiddleware` uses the lean `UserRepository::userExists` (`SELECT 1 … LIMIT 1`,
  `src/Auth/UserRepository.php:331-342`) and sets `$request->userId` from validated claims; no full
  `findById` on the hot path.

Tests genuinely guard:
- `AuthMiddlewareTest::testDeletedUserIsRejectedForWholeTtlAndProbedOnce` is a true regression guard —
  it asserts a `false`-probing user is 401 `auth.user_not_found` on ALL 5 requests in the window
  (`assertNotNull($response)` per request). Against the old bare-timestamp+unconditional-`true` code,
  requests #2..5 returned null (admitted) → this test FAILS. It also asserts `once()` probe (negative
  cached).
- `testExistenceReprobedAfterTtlAndCatchesDeletedUser` back-dates the cache entry via reflection
  (`$cache['u-revoke']['at'] -= 3600`) — no blocking sleep — and proves post-TTL re-probe catches the
  now-deleted user (`willReturnOnConsecutiveCalls(true, false)`, `exactly(2)` probes).
- `testHotPathNeverLoadsFullUserRow` → `never()->findById`; `testExistenceProbeIsCachedWithinTtl` →
  `once()` probe across 5 requests.
- `ServerInfoHandlerTest::testGetOwnerAndStatusIsLeanWithNoLibraryCountSubquery` captures the SQL and
  asserts `assertStringNotContainsString('COUNT(' | 'server_libraries' | 'library_count')`.
- `ServerProxyControllerTest::test_proxy_uses_lean_owner_query_and_never_full_getServerInfo` and
  `ClientRelayWorkerTest::testValidateClientAuthUsesLeanOwnerQueryNotFullServerInfo` both assert
  `once()->getOwnerAndStatus` + `never()->getServerInfo`. All assertions are real (not vacuous).
- Static cache reset between tests via `AuthMiddleware::resetCache()` in `setUp()`.

Conventions/async/resident-memory: DB uses `query()` with colon-free bind KEY `['id' => $id]` and a
`:id` SQL placeholder (matches the established pattern); no PDO/mysqli, no `exit`/`die`, no blocking
`sleep`, no per-request static/global state. PSR-12 / PHPStan-L9 clean. Scope confined to the named
files.

Verification (this box, PHP 8.3.6 + PCOV):
- `phpunit --filter 'AuthMiddleware|ServerInfoHandler|ServerProxyController|ClientRelayWorker'` →
  **OK (175 tests, 514 assertions)**.
- Full `php -d max_execution_time=0 ./vendor/bin/phpunit` →
  **OK, 1243 tests / 15459 assertions / 17 skipped / 0 failures**.
- `phpstan analyze --no-progress` → **[OK] No errors** (L9, no baseline).
- `phpcs --standard=PSR12 -n src/` → **clean (exit 0)**.
- psalm skipped (box PHP 8.3.6 < psalm-required 8.3.16 — environmental).

Verdict: **HB-1.4 is code/test-complete** (docs cycle pending). Security defect confirmed closed;
leanness wiring confirmed; tests genuinely guard the regression.
