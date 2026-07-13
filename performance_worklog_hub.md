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
- [x] HB-2.1  request-body chunking over tunnel (enable bodied relay)  CLOSEOUT 2026-07-12 (TestEngineer): gap (c) hub emission test + gap (d) round-trip coverage assessed — both halves now assert against the shared `RelayHttpRequestCodec` (see TestEngineer note below). Server reassembly half already landed+verified byte-for-byte. RE-AUDIT-2 2026-07-12 (post HB-1.2 fix-3): NOT-DONE — CROSS-REPO BLOCKER, X2 only HALF-landed. Hub side CORRECT (RelayHttpRequestCodec tag-byte HEAD/BODY/END; RelayProxyManager.php:288-319 chunks >64KB; 413 cap lifted; classifier now match($frame->type) → chunked frames flow LOW/pendingBodyFrames without throwing, proven by TunnelTest). BUT **phlix-server never built the request-reassembly half + is NOT repinned to the shared request codec** → a real >64KB bodied relay now fails 400-malformed (RelayConsumer::onHttpRequest @server:745-746 does RelayHttpRequest::fromJson unconditionally, no tag-byte branch/accumulator; server composer.lock detain/phlix-shared v0.19.0 has only RelayHttpResponseCodec). GAPS to close HB-2.1: (a) SERVER repin to shared ≥216ea5d; (b) SERVER onHttpRequest per-requestId chunk reassembly (mirror response side); (c) HUB test: onRequest(>64KB binary body incl NUL/0xFF) emits HEAD+N·BODY+END on one requestId (RelayProxyManager.php:300-318 = 0 coverage today); (d) integration round-trip (writable only after b). Hub unit-codec round-trip + Tunnel classification/ordering ALREADY tested. (commits: phlix-shared:216ea5d, phlix-hub:7b71c190; classifier fb9e7b7). → server task queued (SV-side of X2).
- [x] HB-2.2  validate HELLO JWT before displacing incumbent tunnel  RE-FIXED 2026-07-12 (Fixer) — prior fix (7c30723) was INEFFECTIVE (DoS still open). Now CLOSED: displacement gated on validation (not on a never-thrown exception), incumbent stays routable, failServer() guarded, reconnect-drain (H-R6) added, txn test hardened. See Fixer note below.
- [x] HB-2.3  cap FrameDecoder buffer  RE-FIXED 2026-07-12 (Fixer) — prior fix (ec17c9cc) capped the buffer but the "close tunnel" half was UNWIRED: FrameDecoder threw a base `\RuntimeException` that `Tunnel::onServerMessage` (catches only `InvalidFrameTypeException`) let escape the Workerman callback. Now CLOSED: overflow throws `FrameBufferOverflowException extends InvalidFrameTypeException` → existing tunnel catch closes cleanly with reason `frame_buffer_overflow`; all other FrameDecoder consumers (client relay :8803, both federation paths) now also catch+close instead of leaking. Real Tunnel-driven test added (fails pre-fix). See Fixer note below.
- [x] HB-2.4  O(1) cancel index  (commit: 371c17a)  DONE
- [x] HB-2.5  static-asset caching headers + realpath memo  (commit: 4644e72)  DONE — FIXER 2026-07-12: closed AC gaps — added ETag + conditional-GET 304 on NON-hashed assets, a per-worker stat memo (realpath+mtime+size, kills per-hit is_file/stat), and the first tests. See `## Fixer — HB-2.5 — 2026-07-12`.
- [x] HB-2.6  dedicated maintenance worker for reapers  (commit: 4644e72)  DONE — ⚠️ FIXER 2026-07-12: the "move ALL reapers to the maintenance worker" change BROKE the in-memory tunnel reaper (HB-0.1) + keepalive heartbeat (maintenance fork's TunnelManager/accumulators are EMPTY). Re-split by data locality: in-memory tasks back on the relay worker, DB-only reapers stay on maintenance. See `## Fixer — HB-2.6 — 2026-07-12`.
- [x] HB-3.1  write-over-relay (PUT/DELETE/PATCH)  (commits: 6c7a4df, +test-fix c86d6e2)  DONE — FIXER 2026-07-12: replaced the broad `PUT /api/v1/media` + `PUT|DELETE /api/v1/playlists` prefixes with ANCHORED per-action PCREs; PATCH documented as registered-but-deny (no server PATCH write route); added the anchoring guard + real bodied round-trip tests. See `## Fixer — HB-3.1 — 2026-07-12`.
- [x] HB-3.2  SyncPlay relay authentication ✅ (commit e5f4603) — authenticate in onWebSocketConnect, gate handleGroupJoin — FIXER 2026-07-12: closed a SECURITY gap — room namespace was scoped cosmetically (keyed by the RAW client-supplied string) so two authed users owning DIFFERENT servers who picked the same friendly room name shared ONE room + controlled each other's playback. Now rooms are keyed by the scoped `{server_id}:{owner}:{clientRoom}` key + first SyncPlayRelayWorker tests. See `## Fixer — HB-3.2 — 2026-07-12`.
- [~] HB-3.3  per-channel tunnel flow control/fairness  RE-AUDIT+COMPLETE 2026-07-12: was PARTIAL (real commit b5a9dba's HTTP_REQUEST-HEAD/END=HIGH priority approach was correctly REVERTED by HB-1.2 fix-3 8d6c1c3 because it reordered chunked bodies; no replacement fairness existed). NOW DONE: replaced the flat `pendingBodyFrames` FIFO with per-channel body queues keyed by `RelayFrame::channelId()`; `flushBodyQueue` drains ROUND-ROBIN (one frame/channel/pass) so a bulk transfer can't starve a browse request; strict intra-channel FIFO preserved (per-channel array_shift → HEAD/BODY/END never reorder). removeClient + close clear per-channel buckets. +1 two-stream fairness test. See `## Implementer — HB-3.3 — 2026-07-12`.
- [~] HB-3.4  bandwidth accounting + per-user quotas  RE-AUDIT+COMPLETE 2026-07-12 (G1–G4; G5 HTTP exposure = separate sub-step, NOT done here). was PARTIAL (1de552a): streaming path recorded NOTHING (returned before recordUserBandwidth); only the UPLOAD cap enforced; no concurrent-stream cap; no controller cap tests. NOW: G1 real streamed bytes metered from the sink's on-the-wire counter (not header estimates); G2 checkUserQuota enforces BOTH download (bytes_in) + upload (bytes_out) caps; G3 in-memory per-user concurrent-stream cap (migration 038 max_concurrent_streams) enforced at proxy admission → 503 stream.limit, leak-free release in the producer finally; G4 controller cap tests (quota.exceeded + stream.limit + under-cap admit/release). See `## Implementer — HB-3.4 — 2026-07-12`.
- [x] HB-4.1  relay observability metrics  (commit: H-W4-batch)  DONE — pending-request gauge, reply-drop counter, per-request latency histogram, 503/504 counters, decode-buffer-size gauge fully wired in MetricsCollector/Registry/FlushService + RelayProxyManager
- [x] HB-4.2  client_relay_tokens retention sweep  RE-AUDIT+FIX 2026-07-12 (perf-4): was PARTIAL — DELETE predicate used `expires_at<NOW()-1d AND revoked_at IS NOT NULL` (H-D2 requires OR), so expired-never-revoked rows (~1h TTL, rarely revoked) were NEVER pruned → table still grew. FIXED: AND→OR (precedence parses as `(expiry) OR (revoked)`), corrected misleading "both expired AND revoked" docblocks (ClientRelayTokenService + IdleReaper ×3), rewrote the string-contains test into a behavioral fake-DB test proving expired-never-revoked IS deleted (regression-to-AND caught). phpstan L9 0, suite 1360/17skip/0fail, phpcs src clean. See `## Implementer — HB-4.2 — 2026-07-12`. Prior wiring (H-W4-batch: pruneExpiredTokens called from IdleReaper::reapDbMaintenance on the maintenance worker — correct).
- [x] HB-4.3  server_heartbeats growth control  (commit: H-W4-batch)  DONE — pruneAllServerHeartbeats()/pruneServerHeartbeats() ring-delete in HeartbeatHandler, called from IdleReaper tick
- [x] HB-4.4  heartbeat handler hash library list optimization  (commit: H-W4-batch)  DONE — SHA-256 library list hash in server_library_hashes table, skip upserts when unchanged; migration 037
- [x] HB-4.5  metrics prune singleton  RE-AUDIT+COMPLETE 2026-07-12: was NOT-DONE (the "per-worker singleton" claim was orthogonal to the AC — `flush()` unconditionally `prune()`d from every worker: 2 HTTP + relay + client-relay ≈4 procs × 3 retention DELETEs/min = N× churn). NOW DONE: added `bool $shouldPrune=false` to `MetricsFlushService::flush()` gating ONLY the DB retention DELETEs; the count=1 relay worker (guaranteed single-instance, always started in boot()) passes `true`, HTTP + client-relay pass `false`. Per-worker in-RAM `pruneStaleConnections()` eviction kept UNCONDITIONAL on the throttle tick (every worker owns a distinct registry — gating it off would leak the client-relay map). Flush cadence + prune SQL untouched. +3 single-pruner tests; existing throttle/binding-contract tests updated to the pruning path. See `## Implementer — HB-4.5 — 2026-07-12`.
- [x] HB-4.6  rate limiting on proxy/client-mount/heartbeat/JWKS  (commit: H-W4-batch)  DONE — RateLimiterInterface injected into ServerProxyController, ClientMountController, HeartbeatHandler, HubJwksController
- [x] HB-4.7  Ed25519KeyManager in-memory previous-key cache  (commit: H-W4-batch)  DONE — unlink removed from loadPreviousKey hot path; purgeExpiredPreviousKey() available for background cleanup
- [x] HB-4.8  stream-timer sweep instead of per-second del+add  RE-AUDIT+FIX 2026-07-12: was PARTIAL (⚠️ BEHAVIORAL REGRESSION — the 2s sweep measured inactivity from the FIXED `sent_at`, killing ACTIVE streams `timeout`s after they STARTED: direct-play ~30s, /hls,/dash ~60s; the 900s ceiling was unreachable). FIXED: added `lastActivityAt` to the pending entry (seeded to `sent_at`), refreshed per HEAD/BODY/END in `onResponseFrame` via `(float) time()`, sweep now tests `now - lastActivityAt >= timeout`; KEPT the `stream_opened_at + MAX_STREAM_DURATION_SECONDS` (900s) absolute ceiling (terminate on EITHER). No per-frame Timer::del/add churn reintroduced; SWEEP_INTERVAL_SECONDS untouched. +3 behavioral tests (active-long-stream SURVIVES — proven to fail vs fixed-`sent_at`; idle>timeout terminates; ceiling fires for active-but-runaway). Suite 1366 pass/17 skip/0 fail, phpstan L9 0, phpcs src clean. See `## Implementer — HB-4.8`. Prior wiring (H-W4-batch: batch SWEEP_INTERVAL_SECONDS timer + sweepStreamTimers()).
- [x] HB-4.9  verify/implement HTTP_CANCEL server-side stop  (commit: H-W4-batch)  DONE — already implemented; full cancel path verified: Bridge→Channel→ProxyManager→Tunnel::sendCancel()→server
- [x] HB-4.10 remove RelaySessionManager::routeRequest  (commit: H-W4-batch)  DONE — confirmed no callers; method removed; docstrings updated
- [x] HB-4.11 port checksum-divergence detection into the migration ledger — DONE 2026-07-13 (migration `041_migrations_checksum.sql` + `MigrationRunner` checksum-aware `run()`/`loadLedger()`/`recordApplied()`/`backfillChecksum()`/`checksum()`). Editing an already-applied `.sql` now diverges its comment-normalized md5 → WARNING + re-apply + refreshed checksum; unchanged files still skip without executing; day-one NULL-checksum rows are backfilled WITHOUT re-executing (no mass re-apply). Named `:param` (colon-free keys) binding preserved; column-absent (pre-041) reads/writes degrade to name-only. Unit suite 1420/0, phpstan L9 0, phpcs clean. See `## Implementer — HB-4.11`.

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

## Fixer — HB-2.2 — 2026-07-12

**Why reopened.** Audit found the tunnel-displacement DoS (H-H1) still OPEN — the prior fix
(7c30723) was ineffective. `RelayWorker::handleHello` called `finalizeServerConnection($serverId)`
UNCONDITIONALLY after `$tunnel->onServerMessage($data)`, relying on a comment ("If onServerMessage()
throws (invalid JWT…)") that is FACTUALLY WRONG: `Tunnel::handleHelloFrame` on a bad/absent JWT calls
`$this->close('unauthorized'); return;` — it does NOT throw. So control still reached
`finalizeServerConnection`, which closed the incumbent (`server_replaced`). Also `acceptServer`
overwrote `$this->tunnels[serverId]` with the new PENDING tunnel BEFORE validation, so after a bad
HELLO `getTunnelForServer()` returned the attacker's now-closed tunnel and the live incumbent was
unroutable. Net: an unauthenticated HELLO with a known `server_id` evicted the live tunnel.

### How the DoS was closed (validate-before-displace restructure)
- `TunnelManager` now parks the new, UNVALIDATED tunnel in a `$pendingTunnels` slot and LEAVES the
  incumbent in the routing map (`$tunnels`) — reachable — until validation succeeds. (`$closingTunnels`
  removed.) On a fresh connect with no live incumbent the new tunnel takes the routing slot directly.
- `finalizeServerConnection` is now called by `handleHello` ONLY when `$tunnel->status ===
  STATUS_ACTIVE` (i.e. the HELLO JWT validated). It promotes the parked tunnel and displaces the
  incumbent. The wrong comment is gone; orchestration is gated on the tunnel's post-HELLO auth STATE,
  not on an exception that never comes.
- New `TunnelManager::abortPendingConnection()` — called on the `!== ACTIVE` path — discards the
  rejected tunnel and leaves any live incumbent untouched and routable.
- **failServer residual sub-vector closed.** `Tunnel::close()`/`onServerClose()` call
  `proxyManager->failServer($serverId)`, which is keyed by server_id. A rejected tunnel shares the
  victim's server_id, so its `close('unauthorized')` (fired inside `handleHelloFrame`, before
  orchestration) would 503 the LEGITIMATE incumbent's in-flight requests. Both call sites are now
  guarded with `$this->relaySessionId !== null` (a never-activated tunnel never owned the server's
  requests), so a bad HELLO cannot fail the incumbent's requests either.

### Reconnect-drain (H-R6) — DONE (same session)
- On a VALIDATED reconnect, `finalizeServerConnection` promotes the new tunnel and calls
  `incumbent->beginDrain($graceSeconds, 'server_replaced')` instead of an immediate hard close. The
  incumbent moves to CLOSING, keeps its clients + server connection alive for a bounded, config-driven
  grace period (default 5s, `HUB_RELAY_RECONNECT_DRAIN_GRACE` / `config/server.php`
  `relay.reconnect_drain_grace_seconds`; wired in `HubServicesProvider`), then hard-closes when a
  ONE-SHOT `Timer::add(…, [], false)` fires (§0.4). Grace `<= 0` = immediate displacement (legacy).
- The drain-end close uses `close($reason, failInFlight: false)` so the server-scoped `failServer()`
  does NOT also kill the newly promoted tunnel's freshly accepted requests. Caveat (honest): any of
  the OLD tunnel's own stragglers not complete by grace end fall back to their per-request relay
  timeout rather than a server-wide fail (which would wrongly nuke the new tunnel's requests) —
  RelayProxyManager pending entries are keyed by server_id, not per-session, so per-request scoping is
  out of scope here. `Timer::add`/`Timer::del` wrapped in try/catch (same pattern as the backpressure
  timers) so unit tests without a live loop don't throw.

### Transaction (H-D4) test hardening
- Kept `RelaySessionManager::registerServer`'s `beginTrans`/commit/rollBack wrapping.
- `testRegisterServerSupersedesOpenSessionsBeforeInsert` now asserts the STRICT ordering
  `select → begin → update → insert → commit` (beginTrans once, commitTrans once, rollBackTrans never).
- New `testRegisterServerRollsBackWhenInsertFails`: INSERT throws mid-txn → asserts
  `begin → update → insert → rollback`, NO commit, and the exception is re-thrown.

### Tests (the guards that were missing)
- `RelayWorkerTest::testInvalidHelloDoesNotDisplaceLiveIncumbent` — drives the REAL orchestration
  (`onMessage → handleHello → Tunnel::onServerMessage → abortPendingConnection`) through a
  TunnelManager wired with a REAL (mocked) `EnrollmentJwtService` that REJECTS — mirroring production
  DI, unlike the jwtService-less manager that hid the DoS. Asserts incumbent stays ACTIVE, still
  routable, client not disconnected. **Verified FAILS pre-fix** (git-stashed the 6 production files;
  incumbent went to 'closing' — evicted — instead of 'active').
- `TunnelManagerTest`: incumbent stays routable while a reconnect is pending; finalize drains→CLOSING
  then (simulated timer) →CLOSED with the new tunnel promoted; grace-0 immediate displace;
  abortPendingConnection leaves incumbent routable / removes a fresh rejected tunnel.
- `TunnelTest`: drain keeps clients + in-flight request during grace then closes WITHOUT failServer
  (real RelayProxyManager, pending seeded via reflection since the class is final); never-activated
  tunnel close does NOT failServer the incumbent's requests.

### Files changed (absolute)
- `/home/sites/phlix/phlix-hub/src/Relay/RelayWorker.php` — gate finalize on STATUS_ACTIVE; abort path.
- `/home/sites/phlix/phlix-hub/src/Relay/TunnelManager.php` — pendingTunnels model; acceptServer /
  finalizeServerConnection / abortPendingConnection; reconnect-drain grace param.
- `/home/sites/phlix/phlix-hub/src/Relay/TunnelManagerInterface.php` — abortPendingConnection; doc.
- `/home/sites/phlix/phlix-hub/src/Relay/Tunnel.php` — failServer guard (close + onServerClose);
  close($reason, $failInFlight); beginDrain/handleDrainTimeout + drain-timer lifecycle.
- `/home/sites/phlix/phlix-hub/src/Common/Container/Providers/HubServicesProvider.php` — wire grace.
- `/home/sites/phlix/phlix-hub/config/server.php` — `relay.reconnect_drain_grace_seconds`.
- Tests: `tests/Unit/Relay/RelayWorkerTest.php`, `tests/Unit/Relay/TunnelManagerTest.php`,
  `tests/Unit/Relay/TunnelTest.php`, `tests/Unit/Hub/RelaySessionManagerTest.php`.

### Verification (actual)
- `phpunit --filter 'RelayWorker|TunnelManager|Tunnel|RelaySessionManager'` → **OK (127 tests, 429
  assertions)**; also green in isolation (`--filter TunnelManager` 17, `--filter TunnelTest` 36).
- Full `php -d max_execution_time=0 ./vendor/bin/phpunit` → **Tests: 1250, Assertions: 15494,
  Skipped: 17, Failures: 0** (baseline ~1243 pass +7 new tests).
- `phpstan analyze --no-progress` → **[OK] No errors**.
- `phpcs --standard=PSR12 -n src/` → **clean (exit 0)**. (phpcs on tests/ flags PRE-EXISTING snake_case
  method names — the whole TunnelTest/TunnelManagerTest use that convention; not a CI gate.)
- psalm skipped (box PHP 8.3.6 < psalm-required 8.3.16 — environmental).

### Batch audit verdicts (recorded per instruction)
- **HB-2.2** — fixed here (DoS + drain + tests). Marked [x].
- **HB-2.3** — **REOPENED**: the FrameDecoder buffer-overflow `RuntimeException` is NOT caught by
  `Tunnel::onServerMessage` (only `InvalidFrameTypeException` is) → it escapes the Workerman message
  callback and the tunnel is never closed. Separate queued task; NOT fixed here.
- **HB-2.4** — **DONE** (O(1) cancel index confirmed).

## Reviewer — HB-2.2 (post-deps verify) — 2026-07-12

Combined VERIFY + confirming-REVIEW after the external release/deps/migration commits
(`b9032fe` @phlix/ui v0.79.0, `847668b` phlix-shared → Packagist ^0.20.0, `548fb8f`
migrations 034-036 MySQL-8 compat) landed on master over the HB-2.2 fix.

### Job A — master GREEN (actual gate output)
- `composer install` → **Nothing to install, update or remove** (lock resolves clean).
- `detain/phlix-shared` vendored at **0.20.0** from Packagist; `vendor/detain/phlix-shared/src/Relay/`
  present with all codecs (RelayFrame, RelayFrameType, RelayHttpRequest{,Chunk,Codec,Head},
  RelayHttpResponse{,Chunk,Codec,Head}, RelayWireCodecInterface). Relay wire contract intact.
- `./vendor/bin/phpstan analyze --no-progress` → **[OK] No errors**.
- `php -d max_execution_time=0 ./vendor/bin/phpunit` → **Tests: 1250, Assertions: 15494,
  Skipped: 17, Failures: 0** — matches the pre-deps baseline exactly (1250 pass / 17 skip / 0 fail).
  **No new red from the deps or migration change.**
- `./vendor/bin/phpcs --standard=PSR12 -n src/` → **clean (exit 0)**.
- `./vendor/bin/phpunit --filter Migration` → **160 tests, 323 assertions, 5 skipped, 0 fail**
  (MigrationFileTest + migration suite green). Reviewed the 034-036 diff: drops MariaDB-only
  `CREATE INDEX IF NOT EXISTS` / `ADD COLUMN IF NOT EXISTS` (rejected by MySQL 8), folds the dangling
  `KEY` into the 035 CREATE TABLE; idempotency provided by the runner's tracking table. DDL is
  portable to both engines; header/engine/PK contract preserved.
- psalm SKIPPED (box PHP 8.3.6 < psalm-required 8.3.16 — environmental, per instruction).

**IS MASTER GREEN: YES.** No new failure attributable to the deps/migration commits.

### Job B — confirming review of the HB-2.2 tunnel-displacement-DoS fix

**DoS closure (security-critical) — CONFIRMED CLOSED.**
- `TunnelManager::acceptServer` (`:150-171`) parks a new tunnel that arrives for a live `server_id` in
  `$pendingTunnels` and LEAVES the incumbent in the `$tunnels` routing map, reachable. It never
  overwrites `$tunnels[serverId]` while an incumbent is live.
- `RelayWorker::handleHello` (`:372-385`) calls `finalizeServerConnection` ONLY when
  `$tunnel->status === Tunnel::STATUS_ACTIVE` after `onServerMessage` — i.e. the enrollment JWT
  validated. The prior false "if onServerMessage throws" comment is gone; gating is on post-HELLO auth
  STATE, not a never-thrown exception. The `!== ACTIVE` branch calls `abortPendingConnection`.
- `abortPendingConnection` (`TunnelManager:336-359`) removes the rejected tunnel from
  pending/routing and leaves any live incumbent untouched and routable.
- `failServer` residual sub-vector closed: both `Tunnel::close` (`:1518`) and
  `Tunnel::onServerClose` (`:627`) guard `proxyManager->failServer($serverId)` with
  `$this->relaySessionId !== null`. `relaySessionId` is only set AFTER JWT validation
  (`handleHelloFrame:442`), so a rejected same-`server_id` tunnel's `close('unauthorized')` cannot
  503 the legitimate incumbent's in-flight requests.
- Regression test `RelayWorkerTest::testInvalidHelloDoesNotDisplaceLiveIncumbent` (`:197-250`) drives
  the REAL orchestration through a `TunnelManager` wired with a REAL (mocked) `EnrollmentJwtService`
  that rejects all tokens (prod-DI parity), asserts the incumbent stays ACTIVE + routable + client
  attached, and pins `expects($this->never())->method('close')` on both the incumbent WS and its
  client WS. Worklog records it verified-FAILS against pre-fix code. Genuine guard.

**Reconnect-drain (H-R6) — CONFIRMED.** `finalizeServerConnection` (`:295-320`) promotes the pending
tunnel then `incumbent->beginDrain($grace,'server_replaced')`. `beginDrain` (`:1557-1592`) moves the
incumbent to CLOSING, keeps clients + server conn alive, and arms a ONE-SHOT timer
(`Timer::add(..., [], false)` — §0.4). `handleDrainTimeout` (`:1604-1611`) → `close($reason, false)`
so `failServer` does NOT nuke the promoted tunnel's requests. Grace `<= 0` displaces immediately.
Timer id nulled inside the closure and on `close`/`onServerClose` (`:1477-1484`, `:609-616`) with
`Timer::del` in try/catch — no timer leak, no double-fire. Grace is config-driven
(`config/server.php` `relay.reconnect_drain_grace_seconds`, env `HUB_RELAY_RECONNECT_DRAIN_GRACE`,
default 5.0) and wired in `HubServicesProvider` (`:318-340`) with a REAL `EnrollmentJwtService`.
Documented straggler caveat (per-request relay timeout fallback) is acceptable.

**Transaction (H-D4) — CONFIRMED.** `RelaySessionManager::registerServer` (`:89-122`) wraps
UPDATE+INSERT in `beginTrans`/`commitTrans` with `catch → rollBackTrans → rethrow`. Named colon-free
`:param` bind keys. Tests `testRegisterServerSupersedesOpenSessionsBeforeInsert` (begin once / commit
once / rollBack never) and `testRegisterServerRollsBackWhenInsertFails` (begin→rollback, no commit,
rethrow) present and assert strict ordering.

**Async/timer discipline (§0.4):** one-shot drain + backpressure timers, `Timer::del` guarded, no
`exit`/`die`/blocking `sleep`, no request state in static/global. DB uses named `:param` placeholders.
Clean.

### Findings
NO FINDINGS.

Informational (non-blocking, no fix required): during the drain window the incumbent is in CLOSING,
and `Tunnel::sendToClient` (`:886`) early-returns on `status !== STATUS_ACTIVE`, so raw DATA-channel
frames to directly-attached clients are dropped during the grace period (only the HTTP_RESPONSE proxy
path — via `onHttpResponse`/`proxyManager->onResponseFrame`, which is NOT status-gated — actually
drains). This is outcome-neutral: those raw-data-plane clients were being displaced anyway (pre-fix
they got an immediate hard disconnect; now a brief freeze then disconnect). The drain delivers its
intended value on the dominant SPA HTTP-over-relay playback path. Recorded for the record only.

**Verdict: master GREEN (no new red from deps/migration); HB-2.2 fix CONFIRMED correct — NO FINDINGS.**

## Fixer — HB-2.3 — 2026-07-12

**Reopened defect (from HB-2.2 batch audit):** the 128 KB `FrameDecoder` buffer cap existed, but the
"close tunnel with invalid_frame" half of the AC was never wired. `FrameDecoder::decode` threw a
**base `\RuntimeException('invalid_frame')`** on overflow; `Tunnel::onServerMessage` catches only
`InvalidFrameTypeException`, and a base RuntimeException is NOT one → the overflow escaped
`onServerMessage`. `RelayWorker::onMessage` delegates with no try/catch → the exception escaped the
Workerman message callback (which `Worker::stopAll`s the worker), and the tunnel's clean
`close(...)` (notify clients / close DB session / fail in-flight) never ran. Verified pre-fix: the new
Tunnel test errored with `RuntimeException: invalid_frame` escaping at `Tunnel.php:349`.

**Approach chosen (option a — typed exception, minimal blast radius):**
- New `src/Relay/FrameBufferOverflowException.php` **extends `InvalidFrameTypeException`** (which extends
  `RuntimeException`, code 1011). Because it is a subclass, **every existing `catch
  (InvalidFrameTypeException)` boundary that already closes the tunnel now also handles overflow** — no
  new catch was strictly required for correctness, only for a distinct close reason.
- `src/Relay/InvalidFrameTypeException.php`: dropped `final`; message building moved to an overridable
  `protected static formatMessage()` (late-static-bound) so the subclass gets a clean
  `"Relay frame buffer overflow: …"` message instead of `"Invalid frame type 0x0: …"`.
- `src/Relay/FrameDecoder.php:111-119`: throws `FrameBufferOverflowException($size, MAX_BUFFER_SIZE)` on
  overflow and **clears the oversized buffer first** (don't keep it resident — §0.4). Kept the 128 KB
  ceiling (`MAX_BUFFER_SIZE = 131072`). Added `@throws` PHPDoc.
- `src/Relay/Tunnel.php:onServerMessage`: added a `catch (FrameBufferOverflowException)` **before** the
  existing `InvalidFrameTypeException` catch — logs buffer_size/max and closes with the distinct reason
  **`frame_buffer_overflow`** (runs the full clean close: notify+close clients, `closeSession`, fail
  in-flight via `failServer`, `serverWs->close`).

**No other FrameDecoder consumer leaks the exception** (grepped all `->decode(` callers):
- `src/Relay/ClientConnection.php:onMessage` (client relay :8803) — wrapped decode; catches
  `InvalidFrameTypeException` → `close()` the client WS.
- `src/Http/Controllers/FederationRelayController.php:handleBinaryMessage` — wrapped → `close('invalid_frame')`.
- `src/Federation/FederationPeerManager.php:handleBinaryFrame` — wrapped → `masterConnection?->close()`
  (reconnect timer re-establishes). These paths previously would also have leaked an *invalid-frame-type*
  exception; now both overflow and invalid-type close cleanly. `FrameEncoder::decodeStatic` is a one-shot
  test/util helper (no accumulation), left as-is.
- `Tunnel::onServerMessage` (server relay :8802) — the H-R7 attack surface — fixed as above.

**Tests (all fail pre-fix, verified by temporarily reverting the throw):**
- `tests/Unit/Relay/TunnelTest.php::test_tunnel_closes_on_frame_buffer_overflow` — **AC guard**: drives a
  REAL `Tunnel` via `onServerMessage` (as `RelayWorker` does) with a >128 KB message; asserts the
  registered client is notified (`send`) + `close`d, `closeSession(..., 'frame_buffer_overflow')`,
  `serverWs->close`, status `CLOSED`, and clients detached — and that `onServerMessage` does NOT throw.
- `tests/Unit/Relay/FrameDecoderTest.php::test_buffer_overflow_throws_typed_exception` — low-level:
  packs many complete 7-byte frames per chunk (one consumed per `decode()` call) so the backlog grows
  past the cap; asserts a `FrameBufferOverflowException` that is-a `InvalidFrameTypeException`, code 1011,
  `bufferSize > 131072`, `maxBufferSize == 131072`, and buffer dropped to 0.
- `tests/Unit/Relay/ClientConnectionTest.php::testOnMessageClosesConnectionOnFrameBufferOverflow` —
  covers the :8803 path: oversized message → `clientWs->close()`, no escape.

Note on overflow mechanics: a single advertised frame maxes at 65542 bytes (< 131072), so it always
completes below the cap; unbounded growth comes from (a) a single WS message larger than the cap, or
(b) a backlog of complete frames consumed one-per-`decode()` call. Both trip the guard (checked on
every append, before parsing). Tests exercise both.

**Verify (actual output):**
- `phpunit --filter 'FrameDecoder|Tunnel|RelayWorker|ClientRelay|ClientConnection'` → `OK (154 tests, 548 assertions)`.
- FULL `php -d max_execution_time=0 ./vendor/bin/phpunit` → `Tests: 1252, Assertions: 15505, Skipped: 17` (0 failures; baseline 1250 + 2 new).
- `phpstan analyze --no-progress` → `[OK] No errors`.
- `phpcs --standard=PSR12 -n src/` → clean (no output).
- Pre-fix confirmation: with the old `throw new \RuntimeException('invalid_frame')` restored, both new
  overflow tests ERROR with the exception escaping at `Tunnel.php:349` / `FrameDecoder.php` — proving the
  tests guard the real defect.

**Files touched (absolute):**
- `/home/sites/phlix/phlix-hub/src/Relay/FrameBufferOverflowException.php` (new)
- `/home/sites/phlix/phlix-hub/src/Relay/InvalidFrameTypeException.php`
- `/home/sites/phlix/phlix-hub/src/Relay/FrameDecoder.php`
- `/home/sites/phlix/phlix-hub/src/Relay/Tunnel.php`
- `/home/sites/phlix/phlix-hub/src/Relay/ClientConnection.php`
- `/home/sites/phlix/phlix-hub/src/Http/Controllers/FederationRelayController.php`
- `/home/sites/phlix/phlix-hub/src/Federation/FederationPeerManager.php`
- `/home/sites/phlix/phlix-hub/tests/Unit/Relay/FrameDecoderTest.php`
- `/home/sites/phlix/phlix-hub/tests/Unit/Relay/TunnelTest.php`
- `/home/sites/phlix/phlix-hub/tests/Unit/Relay/ClientConnectionTest.php`

## TestEngineer — HB-2.1 closeout — 2026-07-12

Closed HB-2.1 gap (c) (hub chunk-emission test = 0 coverage) and assessed gap (d)
(integration round-trip). **Test build-out only — no production code changed.**

### Gap (c) — hub emission test (RelayProxyManager.php:288-319 chunked path)

Added to `tests/Unit/Relay/RelayProxyManagerTest.php` (2 new tests + 1 private helper):

- **`test_large_binary_body_emits_head_body_end_chunks`** — drives `onRequest` with a
  **140 000-byte BINARY body** cycling all 256 byte values (asserts it contains NUL 0x00
  and 0xFF; not valid UTF-8/JSON). 140000 > 2·MAX_BODY_CHUNK(65534) so it must span
  **3 BODY chunks**. Decodes every emitted HTTP_REQUEST frame with the **REAL vendored
  `RelayHttpRequestCodec::decode()`** (not a hand-rolled parser) and asserts the exact
  sequence on the one requestId: **1 HEAD (tag 0x01) → 3 BODY (tag 0x02) → 1 END (tag 0x03)**.
  HEAD decodes to a real `RelayHttpRequestHead` with method=`PUT`, path/query/Content-Type
  correct and **bodySize** `Content-Length === (string)strlen($body)`. The concatenated BODY
  bytes are asserted **byte-for-byte identical** to the source (`$body === $reassembled`,
  length + strict `===`) — NUL/0xFF preserved, no base64/UTF-8 corruption. Each BODY chunk
  is `<= MAX_BODY_CHUNK`.
- **`test_body_size_boundary_single_envelope_vs_chunked`** — pins the **65535 decision
  boundary**. Binary-searches (via the shared `RelayHttpRequest::toJson()`) the smallest body
  whose JSON envelope exceeds 65535, then asserts: just-**under** → exactly **one**
  HTTP_REQUEST frame that decodes via `RelayHttpRequest::fromJson()` with body round-tripping
  identically (legacy single-frame envelope, back-compat preserved); just-**over** → the
  chunked **HEAD + BODY(s) + END** path with reassembled body byte-identical. Guarantees
  chunking never fires early (regressing the envelope) nor late (413-capping a bodied request).
- Both drive the real `RelayProxyManager::onRequest` + real `Tunnel::sendToServer`; the
  hub's raw-JSON HELLO_ACK (begins `{`) is filtered, binary frames decoded with `FrameDecoder`.

Coverage: `RelayProxyManager.php` lines **292–318 (the entire chunked-emission branch)** go
from **0 → covered (count=2)**, confirmed from `coverage.xml`.

### Gap (d) — round-trip coverage conclusion

A true cross-process **hub-emit → server-reassemble** integration test is **not runnable inside
one repo's unit suite** — phlix-hub and phlix-server are separate deployables and there is no
in-repo hub↔server integration harness that could stand up both event loops. The practical,
authoritative coverage is therefore the **two-halves-against-one-shared-codec** guarantee:

- **Hub emit half** (this step): `RelayProxyManagerTest` asserts the emitted frames decode via
  the vendored `Phlix\Shared\Relay\RelayHttpRequestCodec` (tags 0x01/0x02/0x03,
  MAX_BODY_CHUNK=65534) to the exact HEAD/BODY*/END contract, body byte-identical.
- **Server reassembly half** (already landed + verified): per the phlix-server worklog
  `## Reviewer — X2/HB-2.1 server side (post-deps verify) — 2026-07-12`, `RelayConsumer::onHttpRequest`
  reassembles the **same** `RelayHttpRequestCodec` contract byte-for-byte (first-byte branch on
  TAG_HEAD/BODY/END, unambiguous vs the legacy `{`-prefixed JSON envelope), covered by
  `RelayConsumerTest` (`phpunit --filter 'RelayConsumer|RelayHttpRequest'` → 45 tests OK) against
  phlix-shared `^0.20.0` — the same `@since 0.17.0` codec API the hub vendors.

Both ends encode/decode against **one shared codec module**, so the hub's emission and the
server's reassembly are contract-locked: this is a **codec-contract-level round-trip guarantee**
and is the recorded closeout for gap (d). No hub↔server integration harness exists in-repo to run
a live cross-process trip; if one is later added, a single 140KB-body live-trip assertion would
be the natural addition.

### Verify (actual output)

- `phpunit --filter 'RelayProxyManager|RelayHttpRequest'` → **OK (40 tests, 190 assertions)**
  (was 38 → +2 new emission tests).
- FULL `php -d max_execution_time=0 ./vendor/bin/phpunit` →
  **`Tests: 1254, Assertions: 15555, Skipped: 17`** (0 failures; baseline 1252 + 2 new).
- `phpstan analyze --no-progress` → **`[OK] No errors`**.
- `phpcs --standard=PSR12 -n src/` → clean (no output; test-only change, src untouched).
- psalm SKIPPED (environmental: box PHP 8.3.6 < psalm's required 8.3.16).

**Files touched (absolute):**
- `/home/sites/phlix/phlix-hub/tests/Unit/Relay/RelayProxyManagerTest.php` (tests only)
- `/home/sites/phlix/phlix-hub/performance_worklog_hub.md` (this note + HB-2.1 → [x])

**Status: GREEN.** HB-2.1 closed — hub emission test green, byte-for-byte against the shared
`RelayHttpRequestCodec`, matching the server reassembly half.

## Fixer — HB-2.6 — 2026-07-12

**CRITICAL production regression closed.** The HB-2.6 change (dedicated `MaintenanceWorker`,
count=1, own fork + own container) moved *every* reaper off the relay worker. That was correct for
the DB-based reapers but SILENTLY BROKE the in-memory tunnel reaper (HB-0.1) + keepalive heartbeat:
the maintenance worker is a separate fork with its OWN container (`Application.php:1543`), so its
`TunnelManager` and its `RelaySessionManager` byte/last-frame accumulators are EMPTY. All three
in-memory tasks therefore ran against empty state on the maintenance worker:
1. `IdleReaper::tick()` scanned the maintenance fork's empty `allTunnels()` → reaped 0 stale/half-open
   tunnels (HB-0.1 dead).
2. The 30s tunnel-heartbeat pinger iterated the empty registry → pinged nothing (server could
   false-reap a quiet-but-healthy tunnel; X9 keepalive gone).
3. `flushAll()` drained the maintenance fork's empty accumulators → `relay_sessions.bytes_in/out` +
   `last_frame_at` writes lost (accumulators are populated by `recordBytesIn/Out` on the relay
   worker's data plane only).

### The data-locality split implemented

**Stayed on the MAINTENANCE worker (DB-only — genuinely benefits from being off the relay loop,
H-A3/H-A4/H-D5):** `ServerReaper` (UPDATE servers / DELETE server_heartbeats), the federation-session
reaper, and — moved out of `IdleReaper::tick()` into a new `IdleReaper::reapDbMaintenance()` — the
DB-only pruners `RelaySessionManager::reapStaleSessions()`, `HeartbeatHandler::pruneAllServerHeartbeats(100)`
(HB-4.3), and `ClientRelayTokenService::pruneExpiredTokens()` (HB-4.2). None depend on the live tunnel
registry. `reapStaleSessions` reads `last_frame_at`, which the relay worker keeps fresh via `flushAll()`,
so cross-worker coordination happens correctly through the DB (threshold 180s ≫ 60s flush cadence).

**Moved BACK to the RELAY worker (in-memory — only that process holds the live `Tunnel` objects +
accumulators):** `IdleReaper::start()` → `tick()` (now scans `allTunnels()` for stale/half-open +
`closeTunnel` + `flushAll()` of accumulators) and the 30s tunnel keepalive heartbeat pinger.

### Restructuring (no txn/mutex machinery touched)
- `IdleReaper`: `tick()` trimmed to the in-memory work (tunnel scan + `flushAll`); new
  `reapDbMaintenance()` holds the 3 DB pruners; new `startDbMaintenance()` arms a repeating timer for
  it. `start()` (arms `tick`) unchanged in behavior. Both timers repeat (correct — not one-shot).
- `HubServicesProvider::startMaintenanceTimers()` split into `startInMemoryReapers()` (IdleReaper
  `start()` + heartbeat pinger — armed on the relay worker) and `startDbMaintenanceTimers()`
  (IdleReaper `startDbMaintenance()` + ServerReaper + federation reaper — armed on the maintenance
  worker). Both still arm each timer independently under its own guard and from within the worker's
  loop (cid≥0), preserving the per-connection-mutex rationale from the 2026-06/07 txn incident.
- `MaintenanceWorker::start()` now calls `startDbMaintenanceTimers()` (was `startMaintenanceTimers`);
  class docblock documents WHY the in-memory reapers are deliberately not run here.
- `RelayWorker::onWorkerStart()` now calls `HubServicesProvider::startInMemoryReapers($this->container)`
  (guarded) exactly once (relay worker is count=1) — the HB-2.6 "moved to maintenance" comment block
  it replaced armed nothing. No double-arming: relay arms only the in-memory set, maintenance only the
  DB set.
- Verified no other consumer assumed these ran on the maintenance worker (grepped `startMaintenanceTimers`
  — only `MaintenanceWorker` + tests referenced it). HB-2.2 reconnect-drain / HB-1.2 backpressure
  timers are Tunnel-resident and untouched.

### Restores HB-0.1
This restores HB-0.1's runtime behavior: the idle/half-open tunnel reaper again scans the POPULATED
relay-worker registry and reaps stale tunnels within the window, healthy tunnels receiving inbound
frames stay alive, and the keepalive heartbeat again reaches the server within its stale window (X9).

### The regression guard that was missing
- `IdleReaperTest::test_tick_flushes_accumulators_and_scans_tunnels_but_runs_no_db_pruners` — drives
  `tick()` with a POPULATED registry (one stale tunnel): asserts `closeTunnel('server-stale','timeout')`
  + `flushAll()` once, and `reapStaleSessions`/`pruneAllServerHeartbeats` NEVER. **Verified FAILS
  pre-fix** (re-added the DB pruners into `tick()` → the `never()` expectations tripped:
  `Tests: 1, Failures: 1`).
- `IdleReaperTest::test_reap_db_maintenance_runs_db_pruners_but_never_touches_tunnel_registry_or_flush`
  — `reapDbMaintenance()` runs the pruners but `allTunnels`/`closeTunnel`/`flushAll` NEVER (proves the
  maintenance path never touches the empty registry). The pre-existing DB-reaper tests were adjusted
  to call `reapDbMaintenance()` (kept the maintenance-side coverage).
- `HubServicesProviderTest::testDbMaintenanceTimersNeverResolveTunnelManager` — the wiring guard: the
  maintenance set must NOT resolve `TunnelManager` (the heartbeat pinger's dependency). Pre-fix ALL
  reapers incl. the pinger were armed on the maintenance worker, so `TunnelManager` WAS resolved there
  — this assertion fails against that wiring. Plus `testInMemoryReapersNeverResolveDbOnlyReapers` and
  exact resolved-service-order tests for both new methods.

### Files changed (absolute)
- `/home/sites/phlix/phlix-hub/src/Relay/IdleReaper.php` — split tick() / reapDbMaintenance() /
  startDbMaintenance().
- `/home/sites/phlix/phlix-hub/src/Common/Container/Providers/HubServicesProvider.php` —
  startInMemoryReapers() + startDbMaintenanceTimers() (replaces startMaintenanceTimers()).
- `/home/sites/phlix/phlix-hub/src/MaintenanceWorker.php` — call startDbMaintenanceTimers(); docblock.
- `/home/sites/phlix/phlix-hub/src/Relay/RelayWorker.php` — arm startInMemoryReapers() in onWorkerStart().
- `/home/sites/phlix/phlix-hub/tests/Unit/Relay/IdleReaperTest.php` — DB tests → reapDbMaintenance()
  + 2 data-locality guards.
- `/home/sites/phlix/phlix-hub/tests/Unit/Common/Container/Providers/HubServicesProviderTest.php` —
  split into in-memory vs DB wiring coverage + regression guards.

### Verify (actual output)
- `phpunit --filter 'IdleReaper|MaintenanceWorker|RelayWorker|HubServicesProvider|RelaySessionManager|ServerReaper'`
  → **OK (87 tests, 251 assertions)**.
- FULL `php -d max_execution_time=0 ./vendor/bin/phpunit` →
  **Tests: 1260, Assertions: 15567, Skipped: 17** (0 failures; baseline 1254 + 6 net new).
- `phpstan analyze --no-progress` → **[OK] No errors** (L9, no baseline).
- `phpcs --standard=PSR12 -n src/` → **clean (exit 0)**.
- Pre-fix confirmation: re-adding the DB pruners into `tick()` makes
  `test_tick_flushes_accumulators_and_scans_tunnels_but_runs_no_db_pruners` FAIL (1 failure) — proves
  the guard bites.
- psalm SKIPPED (box PHP 8.3.6 < psalm-required 8.3.16 — environmental).

## Fixer — HB-2.5 — 2026-07-12

Audit found the hashed-immutable Cache-Control + realpath memo present & correct, but three AC
gaps: (1) NO ETag / conditional-GET on non-hashed assets; (2) the memo was realpath-only so
`is_file()` (and now the ETag stat) re-syscalled every hit; (3) ZERO tests. All closed. The
immutable-hashed path is unchanged.

**Files changed (absolute):**
- `/home/sites/phlix/phlix-hub/src/Application.php`
  - **Gap 1 — ETag + 304.** Extracted the header/ETag/304 decision into a pure, side-effect-free
    `public static computeStaticCacheDecision(mime, isHashedAsset, mtime, size, ifNoneMatch,
    ifModifiedSince): array{status,headers}`. Hashed assets keep `public, max-age=31536000,
    immutable` with NO validators (browser never revalidates). Non-hashed assets get
    `public, max-age=86400` + a strong `ETag` (`"<mtime-hex>-<size-hex>"`) + `Last-Modified`. A
    matching `If-None-Match` (weak comparison per RFC 7232: `*`, comma-lists, and `W/`-weak tags
    handled via `etagMatches`/`stripWeakEtag`) — or, in its absence, an `If-Modified-Since` not
    older than mtime (`isStaticAssetNotModified`, If-None-Match takes precedence) — returns
    **304** carrying the validators and NO `Content-Type`. The static-serve closure
    (`onMessage`) now reads `If-None-Match`/`If-Modified-Since` off the Workerman request, calls
    the decision fn, and for `status===304` sends a bodiless `Response(304, validators)` (never
    `withFile`); otherwise `Response(200, headers)->withFile($real)` as before. `$status` is set
    to 304 so per-route metrics record correctly.
  - **Gap 2 — stat memo.** New `getStaticFileMemo(candidate): array{real,mtime,size}|false` — a
    per-worker `static` memo that reuses the existing `getRealPathMemo()` for the realpath leg
    (behavior preserved) and additionally caches `is_file` + `stat()` (mtime/size). The closure
    now resolves the file once via this memo, so `realpath()`/`is_file()`/`stat()` no longer fire
    on every asset hit and the ETag is derived without an extra syscall. Bounded by
    `STATIC_FILE_MEMO_MAX = 4096` (clear-on-overflow) so a flood of distinct 404 candidates can't
    grow the memo unbounded in a resident worker (§0.4). Docblock notes the deploy-staleness
    caveat: a negative result and the mtime/size are cached for the worker's lifetime, so
    in-place asset replacement without a worker recycle serves stale validators until restart —
    acceptable for the finite public/ asset set + hashed-immutable assets.
- `/home/sites/phlix/phlix-hub/tests/Unit/ApplicationStaticAssetCacheTest.php` (NEW, +14 tests):
  - Header-presence: non-hashed carries `ETag` + `public, max-age=86400` (+ not `immutable`) +
    `Last-Modified` + `Content-Type`; hashed carries `immutable` long max-age and NO `ETag`/
    `Last-Modified`; hashed ignores conditional headers → still 200.
  - Conditional GET: matching `If-None-Match` → 304 with validators + NO `Content-Type` (no body);
    non-matching → 200 with body + ETag; weak `W/` tag matches; `*` matches; comma-list containing
    the tag matches; `If-Modified-Since` ≥ mtime → 304, older → 200; `If-None-Match` precedence
    over `If-Modified-Since`.
  - Memo: `getStaticFileMemo` (reflection) resolves real/mtime/size; a second lookup returns the
    memoized stat byte-identically even after the file is deleted (proves no re-stat/re-realpath);
    missing path → false.

**AC mapping:** "hashed bundles cached by the browser" → immutable path unchanged + test.
"short max-age + ETag for others" → Gap 1. "fewer FS syscalls per asset request" → Gap 2 stat
memo. "header-presence test; memo hit test" → the 14 new tests.

**Verify (this box, PHP 8.3.6 + PCOV):**
- `phpunit --filter ApplicationStaticAssetCache` → **OK (14 tests, 37 assertions)**.
- Full suite `php -d max_execution_time=0 ./vendor/bin/phpunit` → **OK, 1274 tests / 15604
  assertions / 17 skipped / 0 failures** (baseline 1260 + 14 new).
- `./vendor/bin/phpstan analyze --no-progress` → **[OK] No errors** (L9, no baseline).
- `./vendor/bin/phpcs --standard=PSR12 -n src/` → **clean (exit 0)**. (Test method names use the
  project's snake_case convention like `ApplicationRouteTemplateTest`; CI runs phpcs on `src/` only.)
- psalm skipped (box PHP 8.3.6 < psalm-required 8.3.16 — environmental, not red).

## Fixer — HB-3.1 — 2026-07-12

Audit found the step functional + failing-closed, but the write allowlist used BROAD prefixes for 4
of 6 named actions, PATCH was a dead route with no documented decision, and there was no true bodied
round-trip test. Closed all three gaps. Scope: `src/Http/Controllers/ServerProxyController.php`,
`src/Application.php`, `tests/Unit/Http/Controllers/ServerProxyControllerTest.php`. No traversal-guard
or watched/unwatched/transcode anchor regressed; fail-closed posture preserved (never widened to
admin/scan).

**Gap 1 — anchored per-action write allowlist (aligned to REAL phlix-server routes, verified).**
Removed the broad `PUT => ['/api/v1/media', '/api/v1/playlists']` and `DELETE => ['/api/v1/playlists']`
entries from `BROWSE_SCOPE_ALLOWLIST` (they let ANY `PUT /api/v1/media/{id}/<anything>` and any
`/api/v1/playlists/<sub>` be relayed). Every write action is now a fully-anchored (`^…$`)
single-segment-`[^/]+`-id PCRE in `BROWSE_SCOPE_PATTERNS`, keyed by method. Confirmed each against
phlix-server source (`MediaUserDataController` @ `WebPortalRouter.php:305-309` + `Core/Application.php:530-531`,
`MediaPosterController` @ `WebPortalRouter.php:356`/`Core/Application.php:451`, playlist create @
`Core/Application.php:1402`):
  - `POST`  `#^/api/v1/media/[^/]+/transcode$#` (unchanged), `#…/watched$#`, `#…/unwatched$#`
    (unchanged), **`#^/api/v1/media/[^/]+/favorite$#`** (add-favorite), **`#^/api/v1/playlists$#`**
    (create playlist → collection; server route is a POST, not PUT/DELETE).
  - `PUT`   **`#^/api/v1/media/[^/]+/rating$#`**, **`#^/api/v1/media/[^/]+/like$#`** (like_level; the
    server route is `/like`, NOT `/like_level`), **`#^/api/v1/media/[^/]+/poster$#`**.
  - `DELETE` **`#^/api/v1/media/[^/]+/favorite$#`** (remove favorite), **`#^/api/v1/media/[^/]+/rating$#`**
    (clear rating).
Consequence: a future/non-intended `PUT /api/v1/media/{id}/<anything-else>` (a would-be media-update
route, `/delete`, `/scan`), the wrong-verb/wrong-name variants (`PUT …/favorite`, `POST …/rating`,
`…/like_level`), and every admin/scan/`/api/v1/playlists/<sub>` path now stay 403 `proxy.scope_denied`.
The old broad `PUT|DELETE /api/v1/playlists` prefixes were also DEAD against the real server (it has no
such routes) — dropped, not re-anchored.

**Gap 2 — PATCH decision: registered-but-deny, documented.** The media server exposes NO PATCH write
route for any action (favorite/rating/like/watched/poster/playlist are POST/PUT/DELETE; grep of
phlix-server confirmed zero `->patch(`/`@api_endpoint PATCH`). Kept the PATCH proxy route registered in
`Application.php` (so a PATCH gets a deliberate 403 `proxy.scope_denied` rather than a bare 404) with NO
allowlist/pattern entry → every PATCH fails closed carrying no capability. Documented at the route
registration, the `BROWSE_SCOPE_ALLOWLIST`/`BROWSE_SCOPE_PATTERNS` docblocks, and `isWithinBrowseScope`.

**Gap 3 — real bodied round-trip tests (codec-contract level).** Added `roundTripWrite()` harness that
drives a bodied write ALL THE WAY through a real `RelayProxyManager` + live `Tunnel` (mirrors the HEAD
harness), decodes the emitted request off the wire, and feeds back a 200 so the controller completes:
  - `test_bodied_put_rating_round_trip_forwards_body_intact` — small `PUT …/rating` with `{"rating":7}`:
    single-frame `RelayHttpRequest`, asserts method/path/**body bytes** forwarded verbatim + 200 (not 504).
  - `test_large_bodied_put_round_trip_chunks_and_preserves_body_bytes` — >64 KB body forces the HB-2.1
    chunked HEAD+N·BODY+END tag-byte path (`RelayHttpRequestCodec`); reassembles and asserts the body is
    byte-for-byte identical + strictly >1 HTTP_REQUEST frame + 200.

**Other tests.** Fixed `allowedSiblingMediaPutProvider` (dropped `favorite` — not a PUT action; renamed
`like_level`→`like` to the real route). Added `test_post_write_actions_favorite_and_playlist_are_allowed`,
`test_delete_write_actions_are_allowed`, `test_patch_is_always_denied` (5 paths), and — the key guard —
`test_non_listed_write_actions_are_denied` (12 paths). Proven to FAIL pre-fix: temporarily re-injecting
the broad prefixes made 7/12 guard cases relay (504) instead of deny (403).

**Verify (this box, PHP 8.3.6 + PCOV):**
- `phpunit --filter 'ServerProxyController|RelayProxy'` → **OK (194 tests, 655 assertions)**.
- Full suite `php -d max_execution_time=0 ./vendor/bin/phpunit` → **OK, 1296 tests / 15678 assertions /
  17 skipped / 0 failures** (baseline 1274 + 22 net new).
- `./vendor/bin/phpstan analyze --no-progress` → **[OK] No errors** (L9, no baseline).
- `./vendor/bin/phpcs --standard=PSR12 -n src/` → **clean (exit 0)**.
- psalm skipped (box PHP 8.3.6 < psalm-required 8.3.16 — environmental, not red).

## Fixer — HB-3.2 — 2026-07-12

Closed the SECURITY gap the audit flagged: SyncPlay relay auth-reject was real, but the room
**namespace was scoped only cosmetically** — rooms were keyed by the RAW client-supplied string
(`self::$rooms[$room]`, `$client->room = $room`). The connect-side ownership check only restricts
WHICH `server_id` path a client may attach to; it did NOT scope the room namespace. So two
authenticated users owning DIFFERENT servers (different owners) who both picked room `"movie-night"`
landed in the SAME `self::$rooms['movie-night']` and broadcast play/pause/seek to each other. AC
"cross-user/cross-server room join is IMPOSSIBLE" was UNMET.

**Fix — scope the room key by the authenticated (server_id, owner) identity.** New helper
`SyncPlayRelayWorker::scopedRoomKey(SyncPlayClient $client, string $clientRoom)` composing
`"{$client->serverId}:{$client->userId}:{$clientRoom}"`. `server_id` and `owner` are UUIDs (hex +
hyphens, never a colon), so the first two `:` delimiters unambiguously separate the scope prefix from
the arbitrary client friendly name → two different servers/owners can never collide even with the same
friendly name. (The `:` in the in-memory array key is NOT a DB `:param`; §0.4 colon-free rule does not
apply — no DB is touched.)

**Where applied (`src/SyncPlay/SyncPlayRelayWorker.php`):** the scoped key is computed once in
`handleGroupJoin` and stored on `$client->room`. Because every other consumer already reads
`$client->room`, the single stored scoped key propagates EVERYWHERE the raw room was used with no other
call-site edits needed:
- `handleGroupJoin` — `self::$rooms[$scopedRoom]` create + member insert (was `self::$rooms[$room]`);
  `getRoomState($scopedRoom)`; `broadcastToRoom($scopedRoom, …)` for `client_joined`. The `room_state`
  reply now echoes the FRIENDLY name (`$clientRoom`) back to the client, not the internal scoped key.
- `handleGroupLeave` — `unset(self::$rooms[$client->room][$clientId])` + `broadcastToRoom($client->room…)`
  now operate on the scoped key (member can only ever be in a room within its own scope).
- `onMessage` default relay + `handlePlayback` — `broadcastToRoom($client->room, …)` scoped.
- `onClose` — routes through `handleGroupLeave` (scoped) then drops the client; empty-room cleanup timer
  keys by the scoped key. No unbounded `self::$rooms` growth (empty rooms swept + removed on leave).
Auth-reject (`onWebSocketConnect` → `rejectUnauthorized`) and the `handleGroupJoin` unauth gate are
unchanged / not regressed.

**Tests — new `tests/Unit/SyncPlay/SyncPlayRelayWorkerTest.php` (5 tests; none existed before):**
- **auth-required:** `testConnectWithNoTokenIsRejected` + `testConnectWithInvalidTokenIsRejected` — an
  absent/unknown relay token → `connection->close('', true)`, zero clients registered.
- **ownership-scoping (the guard):** `testDifferentServerOwnerSameRoomNameDoNotShareRoom` — two authed
  clients on DIFFERENT (server_id, owner) picking the SAME friendly name resolve to TWO scoped rooms
  (`getActiveRoomCount() === 2`) and A's `playback_play` is NEVER delivered to B. **This test FAILS
  against the pre-fix raw-key code** (verified: `git show HEAD:…SyncPlayRelayWorker.php` restored →
  the two clients share ONE room, `Failed asserting that 1 is identical to 2` — cross-user join was
  real), then passes with the fix.
- **legitimate-flow:** `testSameServerOwnerSameRoomShareAndReceivePlayback` — two authed clients on the
  SAME server/owner joining the same room share ONE scoped room and B receives A's `playback_play`.
- **cleanup:** `testGroupLeaveEmptiesTheScopedRoomAndCleansUp` — disconnect drops the client from its
  scoped room + live set (no static leak).
Conventions mirrored from `ClientRelayWorkerTest`: real `ClientRelayTokenService` over a mock
`Workerman\MySQL\Connection` (the service is `final`, cannot be mocked) keyed by the sha256 `token_hash`
param; mock `ServerInfoHandler::getOwnerAndStatus`; recording mock `TcpConnection`; in-memory
`LoggerFactory`; `SyncPlayRelayWorker::reset()` + `$_GET` restore for static isolation.

**Verify (this box, PHP 8.3.6 + PCOV):**
- `phpunit --filter 'SyncPlay'` → **OK (5 tests, 11 assertions)**.
- Full suite `php -d max_execution_time=0 ./vendor/bin/phpunit` → **OK, 1301 tests / 15689 assertions /
  17 skipped / 0 failures** (baseline 1296 + 5 new).
- `phpstan analyze --no-progress` → **[OK] No errors** (L9, no baseline).
- `phpcs --standard=PSR12 -n src/` → **clean (exit 0)** (new test file also clean).
- psalm skipped (box PHP 8.3.6 < psalm-required 8.3.16 — environmental).

## Implementer — HB-3.3 — 2026-07-12 (per-channel fair scheduling / anti head-of-line-blocking)

**Problem (H-R4 / audit PARTIAL).** After HB-1.2 fix-3 all stream frames (HTTP_REQUEST browse/segment,
chunked HEAD/BODY/END, DATA) correctly share ONE priority class (LOW) so intra-request ordering is
preserved — but they shared a SINGLE FLAT `pendingBodyFrames` FIFO. Under server backpressure a large
transfer on channel A monopolised that FIFO and a browse HTTP_REQUEST on channel B landed dead-last
behind A's entire backlog → head-of-line blocking, one client starving another on the same tunnel. The
naive fix (re-prioritising HEAD/END as HIGH) was already tried in the real HB-3.3 commit b5a9dba and
REVERTED by HB-1.2 fix-3 (8d6c1c3) because it reordered chunked request bodies (END overtaking BODY).

**Fix = per-channel fair scheduling, NOT priority reclassification.** `isHighPriorityFrame` is UNCHANGED
(HIGH = HEARTBEAT/HTTP_CANCEL/CLIENT_CONNECT/CLIENT_DISCONNECT only). Intra-request ordering stays
strictly FIFO; interleaving happens ACROSS channels.

**Files changed (absolute):**
- `/home/sites/phlix/phlix-hub/src/Relay/Tunnel.php`
  - **HB-3.3a (data structure):** `pendingBodyFrames` changed from `list<RelayFrame>` to
    `array<int, list<RelayFrame>>` keyed by `RelayFrame::channelId()` (the `seq` field — client channel
    id for DATA, relay request id for HTTP_REQUEST sub-frames). Mirrors the shape of `pendingClientFrames`.
    Emptied channel keys are unset so `empty($this->pendingBodyFrames)` reliably means "no backlog"
    (the existing enqueue-if-backlog guard at `sendToServer` + the drain-handler check both rely on it,
    unchanged).
  - **HB-3.3b (scheduler):** `flushBodyQueue()` rewritten to ROUND-ROBIN — an outer `while (!empty)` with
    an inner `foreach (array_keys(...))` sending AT MOST ONE frame per channel per pass. Strict
    intra-channel FIFO via `[0]` + `array_shift` on each channel's list (HEAD→BODY→END of one request
    never reorder). On `send()===false` mid-flush it leaves everything queued and re-arms backpressure
    (no frame dropped — HB-1.2 invariant preserved). Control-before-body ordering in the server
    `onBufferDrain` (flushHighPriorityQueue then flushBodyQueue) is UNCHANGED.
  - **HB-3.3c (wiring/lifecycle):** `enqueueBodyFrame()` now appends to `pendingBodyFrames[$frame->channelId()]`;
    the MAX_BODY_QUEUE=256 cap is enforced as the AGGREGATE across all channels via a new `bodyQueueTotal()`
    helper (memory bound independent of channel count → still `close('backpressure_overflow')`). `removeClient`
    now also `unset($this->pendingBodyFrames[$channelId])` for a departing client's channel (no leak/stranded
    slot); `close()` already reset the whole array (works for array-of-arrays). The low-priority enqueue
    guard in `sendToServer` and the `onBufferDrain` flush logic were left intact.
- `/home/sites/phlix/phlix-hub/tests/Unit/Relay/TunnelTest.php`
  - **HB-3.3d:** added `test_body_queue_round_robin_prevents_one_channel_starving_another` — channel A
    enqueues an 8-frame BODY burst, then channel B enqueues one browse HTTP_REQUEST while congested;
    on drain asserts B is delivered at index 1 (first round-robin pass — bounded delay, not index 8),
    AND channel A's 8 chunks stay in strict FIFO order. Relabeled
    `test_is_high_priority_frame_classifies_by_type_without_decoding_payload`'s docblock to disclaim any
    fairness guarantee (it documents ONLY type-classification; points at the new fairness test).

**AC mapping:** "concurrent clients on one server's tunnel get fair progress; a large transfer doesn't
stall browse/segment requests" → the round-robin drain delivers channel B's request on the first pass
regardless of channel A's backlog size (proved by the new test). Intra-request framing integrity
("HEAD/BODY/END never reorder") preserved by per-channel FIFO (existing chunked-order test + the new
test's channel-A FIFO assertion still green).

**Out of scope (noted only, NOT built):** a separate tunnel connection for bulk media vs control
(the longer-term H-R4 option) — future work.

**Verify (this box, PHP 8.3.6 + PCOV):**
- `phpunit --filter Tunnel` → **OK (80 tests, 294 assertions)**.
- Full suite `php -d max_execution_time=0 ./vendor/bin/phpunit` → **OK, 1302 tests / 15705 assertions /
  17 skipped / 0 failures** (baseline 1301 + 1 new).
- `phpstan analyze --no-progress` → **[OK] No errors** (L9, no baseline).
- `phpcs --standard=PSR12 -n src/` → **clean (exit 0)**.
- psalm skipped (box PHP 8.3.6 < psalm-required 8.3.16 — environmental).

## Reviewer (per-step, read-only) — HB-3.3 — 2026-07-12

Reviewed commit `5c57ce4` (parent `b965a79`) — diff `b965a79..5c57ce4` scoped to
`src/Relay/Tunnel.php` + `tests/Unit/Relay/TunnelTest.php` (+ worklog). Gates re-run on this
box: phpstan L9 `[OK] No errors`; `phpcs -n src/Relay/Tunnel.php` exit 0; `phpunit --filter Tunnel`
OK (80 tests / 294 assertions); full suite 1302 pass / 0 fail / 17 skip (baseline held).

Verified:
- **AC met.** `flushBodyQueue()` is round-robin (one frame per channel per outer-while pass via
  `array_keys` snapshot + per-channel `[0]`/`array_shift`); the new
  `test_body_queue_round_robin_prevents_one_channel_starving_another` proves channel B's browse
  request lands at wire index 1 (bounded, first pass) instead of index 8 behind channel A's 8-frame
  bulk backlog. The `assertSame(1, $bIndex)` genuinely fails against the old flat-FIFO behavior, so
  the test really guards the H-R4 seam.
- **Intra-channel FIFO integrity intact.** Channel key = `RelayFrame::channelId()` = `seq`. Confirmed
  in `RelayProxyManager::forwardRequest` (:289/:300-318) that HEAD, every BODY chunk, and END of one
  chunked request all carry the SAME `$requestId` as `seq` → one bucket, drained in order via
  `array_shift`. Distinct requests get distinct request ids (`FIRST_REQUEST_ID=0x80000001` monotonic)
  and DATA carries the client channel id (small positive from `nextChannelId++`), so the two id spaces
  never collide in the shared map — no bucket cross-contamination. The `sendToServer` LOW-priority
  enqueue-if-backlog guard (:920, `!empty(pendingBodyFrames)` = ANY channel) ensures a later same-channel
  frame is never direct-sent ahead of a queued earlier one.
- **HB-1.2 invariants not regressed.** No-drop preserved (false send → `enqueueBodyFrame` re-queue +
  `handleServerSendBackpressure`; mid-flush false send in `flushBodyQueue` leaves everything queued and
  returns). Overflow → `close('backpressure_overflow')` retained, now via aggregate `bodyQueueTotal()`
  (semantically equivalent to the old flat `count()` — total frames, not channels). Control-before-body
  drain order in the server `onBufferDrain` (flushHighPriorityQueue then, only if empty, flushBodyQueue)
  unchanged; episode-scoped safety timer semantics untouched.
- **No leaks / stranded pause.** Emptied channel keys are `unset` on drain so `empty()` == "no backlog";
  `removeClient` drops the departing DATA channel's bucket (:1479-1481) leaving HTTP_REQUEST buckets
  (request-id-keyed, not client-tied) correctly untouched; the client-congestion decrement +
  server-resume path (:1455-1470) is separate and intact; `close()` resets `pendingBodyFrames = []`
  (:1589), valid for the array-of-arrays shape.
- `flushBodyQueue` cannot spin (progressed-guard + unset-on-empty + return on false send).
- Scope clean (only the two in-scope files + worklog); repo conventions and §0.4 respected.

NO FINDINGS

## Implementer — HB-3.4 — 2026-07-12

Audit-and-complete of the CORE enforcement path (gaps G1–G4). **G5 (HTTP endpoints to
set caps / view usage via setUserQuota/getUserBandwidth) is deliberately left for the
separate follow-up sub-step — NOT touched here.** Did NOT touch Tunnel.php (HB-3.3
round-robin / HB-1.2 backpressure untouched — accounting taps the sink's byte count,
it does not reorder frames).

**G1 — meter REAL streamed bytes (authoritative, not header estimates).**
- `src/Http/ConnectionResponseSink.php`: added `$bytesStreamed` incremented on every
  body fragment the sink SUCCESSFULLY hands to the connection, across BOTH framings
  (fixed-length and chunked); it counts only raw body bytes (chunk framing overhead
  excluded) and a failed send is not counted. New `bytesStreamed(): int` getter.
  **Byte-metering locus = the sink's on-the-wire counter** — this is authoritative (the
  actual bytes delivered to the browser socket) rather than the old `strlen($body)+1024`
  header estimate the buffered path used.
- `src/Http/Controllers/ServerProxyController.php`: `buildStreamingResponse()` now takes
  `$userId`; its producer wraps `$bridge->stream(...)` in try/finally and, in the finally,
  calls `recordUserBandwidth($userId, $sink->bytesStreamed(), strlen($body))` — download =
  real streamed bytes, upload = real request body. Runs on every exit (completion,
  browser-gone, mid-stream error). The streaming path used to `return` before the only
  `recordUserBandwidth()` calls, recording nothing — that gap is closed.

**G2 — enforce BOTH caps.** `RelaySessionManager::checkUserQuota()` now SELECTs
`bytes_in, bytes_out, quota_bytes_in, quota_bytes_out` and denies when EITHER cap is set
(`quota > 0`) and reached (`used >= quota`). Per the column docstrings (`bytes_in` =
downloaded-by-user, `bytes_out` = uploaded-by-user), the DOWNLOAD cap (`quota_bytes_in`
vs `bytes_in`) is the one that bites for playback and was previously never checked; the
UPLOAD cap behaviour is preserved.

**G3 — per-user concurrent-stream cap (in-memory, migration-configurable).**
- Migration `038_relay_user_quotas_concurrency.sql`: plain
  `ALTER TABLE relay_user_quotas ADD COLUMN max_concurrent_streams INT UNSIGNED NOT NULL
  DEFAULT 0` (0 = unlimited). No `IF NOT EXISTS` (MySQL-8 1064); header
  `-- migration: 038_relay_user_quotas_concurrency`; added to `MigrationFileTest`
  expected-files list (146 tests pass).
- `RelaySessionManager`: in-memory `$activeStreams` map keyed by userId +
  `beginUserStream`/`endUserStream`/`activeUserStreams` and
  `getUserMaxConcurrentStreams()` (reads the new column for the current period; 0 when no
  row → unlimited). The map is BOUNDED — a user's key is `unset` the moment their count
  hits 0, and `endUserStream` clamps at 0 — so no unbounded static growth in the resident
  worker; the live count is NOT persisted (it is a property of this worker's open
  connections).
- `ServerProxyController::proxy()`: BEFORE `buildStreamingResponse`, if
  `maxStreams > 0 && activeUserStreams >= maxStreams` → 503 `stream.limit` (never occupies
  a slot, never forwards). The slot is occupied in `buildStreamingResponse` (begin) and
  **released in the producer's finally** via a one-shot idempotent `$release` closure —
  the finally is the true "stream over" boundary (`stream()` always returns OR throws, and
  the producer runs synchronously in the same HTTP worker per `Application::onMessage`), so
  the slot cannot leak on normal completion, browser-disconnect, or a pre-head exception.

**G4 — controller cap tests** (`tests/Unit/Http/Controllers/ServerProxyControllerTest.php`):
- `test_over_quota_user_returns_503_quota_exceeded_and_is_not_forwarded`
- `test_concurrent_stream_cap_reached_returns_503_stream_limit_and_is_not_forwarded`
  (asserts `beginUserStream` is NEVER called + not forwarded)
- `test_stream_under_concurrent_cap_is_admitted_occupies_then_releases_slot`
  (begin once / end once / `recordUserBandwidth('user-1', 6, 0)` once — no leak)
The existing allow-path case (`checkUserQuota` → allow) still passes.
Also added: `RelaySessionManagerTest` download-cap + both-under + concurrent-counter-bounds
+ getUserMaxConcurrentStreams tests; `ConnectionResponseSinkTest` bytesStreamed (fixed /
chunked / failed-send) tests.

**⚠️ Concurrency-cap scope note:** the HTTP worker (`:8800`) defaults to `HUB_WORKERS=2`
(config/server.php), NOT count=1 (only the relay worker `:8802` is count=1). Streaming +
this admission both run in the HTTP worker, so the in-memory concurrent counter is
per-HTTP-worker → the cap is effectively enforced per worker (≈ N×max globally). This
matches the task's explicit "in-memory counter, do NOT store the live count in the DB"
directive and is correct within a worker; a strict GLOBAL cap would need a shared store
(Redis/DB) — flagged as future work alongside G5.

**Verify (this box, PHP 8.3.6 + PCOV):**
- `phpstan analyze --no-progress` → **[OK] No errors** (L9, no baseline).
- `phpcs --standard=PSR12 -n src/` → **clean (exit 0)**.
- `phpunit --filter 'RelaySessionManager|ServerProxyController|ConnectionResponseSink|Migration'`
  → **OK (353 tests, 916 assertions, 5 skipped)**.
- Full suite → **1318 pass / 15752 assertions / 17 skipped / 0 failures** (baseline 1302,
  +16 new).
- `MigrationFileTest` → **OK (146 tests)** — validates 038 header/plain-ALTER + expected list.
- `php bin/phlix migrate` → could NOT run live (DB unreachable on this box:
  `SQLSTATE[HY000] [2002] Connection refused`, environmental — same class as the psalm
  skip). 038 is a single plain `ALTER … ADD COLUMN`; the MigrationRunner tracking table is
  what makes each file apply once (re-runnable). Needs a box with the DB up to confirm the
  live apply.
- psalm skipped (box PHP 8.3.6 < psalm-required 8.3.16 — environmental).

**G5 explicitly left open:** HTTP exposure of `setUserQuota`/`getUserBandwidth`/
`max_concurrent_streams` (admin/user endpoints to set caps + view usage) is a SEPARATE
follow-up sub-step and was not implemented here.

## Reviewer (per-step) — HB-3.4 (G1–G4 core enforcement) — 2026-07-12

Range `5c57ce4..dbafd72`. Gates re-run on this box: phpstan L9 **0 errors**; targeted
`--filter 'RelaySessionManager|ServerProxyController|ConnectionResponseSink|Migration'`
**353 pass / 916 assert / 5 skip**; full suite **1318 pass / 15752 assert / 0 fail / 17 skip**
(matches stated baseline); `phpcs PSR-12 -n` on the 3 touched src files **clean**; `Tunnel.php`
**untouched** (HB-3.3/HB-1.2 not regressed). `bin/phlix migrate` not run (no DB on box —
environmental, not a finding). psalm skipped (env).

Adversarial verification of the high-risk items — all CLEAR:
- **G1 arg-order end-to-end — NO SWAP (verified).** `recordUserBandwidth($userId,
  $sink->bytesStreamed(), strlen($body))` → sig `(userId, bytesIn, bytesOut)`; docstring
  `bytesIn = downloaded-by-user`, `bytesOut = uploaded-by-user`; DB `bytes_in`/`bytes_out`;
  `checkUserQuota` download cap = `quota_bytes_in` vs `bytes_in`. Streamed-to-browser bytes land
  in `bytes_in` and the download cap reads `bytes_in` — aligned metering→column→check. The under-cap
  test asserts `recordUserBandwidth('user-1', 6, 0)` (foo+bar=6 download, 0 upload), locking the order.
- **G1 metering correctness.** `bytesStreamed` increments by `strlen($bytes)` (raw body, NOT the
  chunk-framed `$payload`) only after `send() !== false`; head fragments/empty bodies skip; failed
  send returns before the increment. Chunk overhead + failed sends excluded, both framings counted.
  Tests cover fixed/chunked/failed-send.
- **No double-count / no spurious zero row.** Streaming branch `return`s via `buildStreamingResponse`
  (records once in the producer `finally`); the buffered branch records separately — no request hits
  both. The `finally` guards `if ($bytesStreamed > 0 || $requestBodyBytes > 0)`, so a pre-head
  exception with no bytes writes nothing.
- **G3 slot-leak — none.** `beginUserStream` runs synchronously inside `buildStreamingResponse`;
  `Application.php:1654` invokes the producer synchronously and unconditionally whenever
  `streamProducer !== null` (same onMessage), and the producer wraps `$bridge->stream()` in
  try/finally whose `$release` (one-shot, idempotent) runs on completion, browser-gone, and thrown
  exception (the throw then hits Application's backstop `catch`→`close`). No path sets a producer
  without the paired begin, and none skips producer invocation. `endUserStream` clamps ≥0 and unsets
  the key at 0 (map bounded). Admission→begin critical section has no suspension point between the
  `activeUserStreams()` read and `beginUserStream()`, so it is not racy even under coroutine hooks;
  per-worker soft cap accepted as first cut (implementer documented strict-global deferral) — the AC
  says "optional per-user concurrent-stream cap", which this satisfies. NOT a finding.
- **G2** enforces both caps (`quota > 0 && used >= quota`), download cap genuinely bites; base
  migration 035 has all four columns. **Migration 038** plain ADD COLUMN, correct header, no
  `IF NOT EXISTS`, added to `MigrationFileTest` (146 pass); re-runnability via the tracking table.
- **G4 tests** are genuine and would fail pre-fix (concurrent/under-cap tests call
  `getUserMaxConcurrentStreams`/`begin`/`end`/`recordUserBandwidth` that did not exist / were never
  called before). Bind-key convention OK (params keys colon-free), no SQL injection, projections lean.

### Findings

1. **`src/Http/Controllers/ServerProxyController.php:545` + `src/Hub/RelaySessionManager.php`
   (`getUserMaxConcurrentStreams` vs `checkUserQuota`) — Low (perf redundancy on the hot path).**
   On the STREAMING branch, `proxy()` issues `checkUserQuota()` (SELECT `bytes_in, bytes_out,
   quota_bytes_in, quota_bytes_out FROM relay_user_quotas WHERE user_id=:u AND period_start=:p`) at
   :474 and then `getUserMaxConcurrentStreams()` (SELECT `max_concurrent_streams FROM
   relay_user_quotas WHERE user_id=:u AND period_start=:p`) at :545 — two round-trips reading the
   IDENTICAL row, milliseconds apart, on every HLS/DASH segment and direct-play request (the highest-
   frequency path this remediation program targets), and the second fires even in the common
   default case (`max_concurrent_streams = 0`, no cap). Failure scenario: N concurrent players ×
   frequent segment fetches double the quota-related DB QPS on the relay hot path for no functional
   gain. Fix direction: fold `max_concurrent_streams` into `checkUserQuota()`'s existing SELECT (or a
   single combined `getUserQuotaState()` read) and have `proxy()` consume both the allow/deny verdict
   and the cap from one row read. Low severity — correct and consistent with the existing per-request
   query pattern, but a concrete, easily-removed redundancy on the exact path the perf pass exists to
   improve.

## Fixer — HB-3.4 (Reviewer Finding #1: single-row read on the streaming hot path) — 2026-07-12

Closed the one Low finding. The streaming branch of `ServerProxyController::proxy()` now reads the
`relay_user_quotas` row ONCE per request (via `checkUserQuota`) instead of twice (`checkUserQuota` +
`getUserMaxConcurrentStreams`). Behaviour preserved exactly. Did NOT touch `Tunnel.php`
(HB-3.3/HB-1.2 not regressed). G5 NOT started.

**What changed:**
- `src/Hub/RelaySessionManager.php` — `checkUserQuota()`: folded `max_concurrent_streams` into the
  existing SELECT projection (`SELECT bytes_in, bytes_out, quota_bytes_in, quota_bytes_out,
  max_concurrent_streams FROM relay_user_quotas WHERE user_id=:user_id AND period_start=:period_start
  LIMIT 1` — one lean row read, colon-free bind keys, no interpolation). The return shape gained a
  third key: `array{allowed: bool, reason: string|null, maxConcurrentStreams: int}`. `maxConcurrentStreams`
  is populated on ALL post-row return paths (allow + both deny paths) via the same
  `is_numeric(...) ? (int) ... : 0` guard used elsewhere; the no-row early-return returns
  `maxConcurrentStreams => 0` (unlimited), identical to the old default. `getUserMaxConcurrentStreams()`
  is UNCHANGED (not deleted) — its docblock now notes the hot path no longer calls it and it is retained
  for the forthcoming G5 admin endpoints.
- `src/Http/Controllers/ServerProxyController.php` — `proxy()` streaming branch: replaced
  `$maxStreams = $this->sessionManager->getUserMaxConcurrentStreams($userId);` (the second identical
  SELECT ~:545) with `$maxStreams = $quotaCheck['maxConcurrentStreams'];`, consuming the cap from the
  `checkUserQuota()` result already computed at :474. The admission logic
  (`if ($maxStreams > 0 && activeUserStreams >= $maxStreams) → 503 stream.limit`), the begin/end
  concurrent-slot accounting, and the `finally`-released slot are all UNCHANGED. Default
  `max_concurrent_streams = 0` still means "no cap" (skip admission).

**Behaviour unchanged (verified):** default no-cap (0) skips the admission check; over-cap still
returns 503 `stream.limit` without occupying a slot / forwarding; under-cap admits, occupies once,
releases once. Over-quota still returns 503 `quota.exceeded` before the streaming branch. The only
observable difference is one fewer identical SELECT per streaming request.

**`getUserMaxConcurrentStreams()` callers after this change:** zero PRODUCTION callers (only the
`RelaySessionManagerTest` unit test exercises it directly). Per §0.1 it is LEFT IN PLACE, not deleted;
it will be used by G5's admin quota-management endpoints. Flagged for the §6 removal queue only if G5
ends up not needing it.

**Tests:**
- `tests/Unit/Http/Controllers/ServerProxyControllerTest.php`: updated the 3 HB-3.4 mocks + the default
  `controller()` mock to return the folded `maxConcurrentStreams` key; the two streaming-branch tests
  (`test_concurrent_stream_cap_reached_*`, `test_stream_under_concurrent_cap_*`) now assert
  `expects(self::never())->method('getUserMaxConcurrentStreams')` — proving the streaming branch issues
  ONE quota-row read, not two (the query-count spy the finding asked for).
- `tests/Unit/Hub/RelaySessionManagerTest.php`: +2 tests —
  `testCheckUserQuotaFoldsInMaxConcurrentStreamsFromSingleRowRead` (SELECT projects
  `max_concurrent_streams`, verdict carries it, exactly ONE query issued) and
  `testCheckUserQuotaNoRowReturnsUnlimitedConcurrentStreams` (no row → allowed + cap 0).

**Verify (this box, PHP 8.3.6 + PCOV):**
- `./vendor/bin/phpstan analyze --no-progress` → **[OK] No errors** (L9, no baseline).
- `./vendor/bin/phpcs --standard=PSR12 -n src/Hub/RelaySessionManager.php
  src/Http/Controllers/ServerProxyController.php` → **clean (exit 0)**. (Pre-existing `test_snake_case`
  method-name notices in `tests/` are outside the repo gate, which lints `src/` only, and I added no
  new snake_case methods.)
- `phpunit --filter 'RelaySessionManager|ServerProxyController|ConnectionResponseSink'` →
  **OK (189 tests, 592 assertions)**.
- Full suite → **1320 pass / 15763 assertions / 17 skipped / 0 failures** (baseline 1318 + 2 new).
- psalm skipped (box PHP 8.3.6 < psalm-required 8.3.16 — environmental). `bin/phlix migrate` not run
  (no DB on box — environmental; no migration touched by this fix anyway).

## Reviewer (re-review) — HB-3.4 fix — 2026-07-12

Re-reviewed the fix for the one Low finding (double `relay_user_quotas` row read on the
streaming hot path). Range `9386359..47bc368` (f52b97a fix + 47bc368 tests). Read the current
`src/Hub/RelaySessionManager.php`, `src/Http/Controllers/ServerProxyController.php`, and the two
touched test files.

**NO FINDINGS**

1. **Finding CLOSED — one row read per streaming request.** `ServerProxyController::proxy()`
   streaming branch (`:546`) now consumes `$maxStreams = $quotaCheck['maxConcurrentStreams']`
   from the single `checkUserQuota()` result read at `:474`; the second
   `getUserMaxConcurrentStreams()` SELECT is gone from the hot path.
   `grep -rn getUserMaxConcurrentStreams src/` → only its own definition + a docblock @see; ZERO
   production callers remain.

2. **Behaviour preserved exactly.** `checkUserQuota()` return shape
   `array{allowed, reason, maxConcurrentStreams}` populates the cap on ALL row paths — no-row
   early-return → `maxConcurrentStreams => 0` (unlimited, `:599`), download over-cap (`:620`),
   upload over-cap (`:629`), and allow (`:633`). In `proxy()`: over-quota → 503 `quota.exceeded`
   before the streaming branch (`:475-482`); over-cap (`$maxStreams > 0 && active >= maxStreams`)
   → 503 `stream.limit` returning before `buildStreamingResponse` so NO slot is occupied
   (`:547-561`); under-cap → `buildStreamingResponse` occupies once (`beginUserStream :692`) and
   releases once via the idempotent `$release` in the producer `finally` (`:697-743`). Default
   `max_concurrent_streams = 0` still skips admission.

3. **SQL correct (§0.4).** Combined SELECT projects only the needed columns
   (`bytes_in, bytes_out, quota_bytes_in, quota_bytes_out, max_concurrent_streams`); colon-free
   bind keys (`user_id`, `period_start`); named `:param` placeholders, no interpolation; predicate
   `WHERE user_id = :user_id AND period_start = :period_start`; `LIMIT 1`; `$this->db->query` (no
   raw PDO/mysqli).

4. **No collateral regression.** `Tunnel.php` untouched (not in the changed-files set —
   HB-3.3/HB-1.2 intact); the begin/end slot accounting + `finally` release in
   `buildStreamingResponse` are byte-for-byte unchanged; `getUserMaxConcurrentStreams()` is LEFT
   IN PLACE (§0.1), docblock updated to note the hot path no longer calls it and it is retained for
   G5; its only remaining caller is the standalone unit test (correct).

5. **Tests genuinely guard the change.** `ServerProxyControllerTest` — the two streaming-branch
   tests now assert `expects(self::never())->method('getUserMaxConcurrentStreams')` (fails against
   the pre-fix code, which called it) plus begin-once/end-once and never-begin on over-cap.
   `RelaySessionManagerTest::testCheckUserQuotaFoldsInMaxConcurrentStreamsFromSingleRowRead` asserts
   the projection contains `max_concurrent_streams`, the verdict carries it (=4), and exactly ONE
   query is issued (fails against the pre-fix 2-key shape without the column);
   `testCheckUserQuotaNoRowReturnsUnlimitedConcurrentStreams` covers the no-row → cap 0 path. The
   default `controller()` mock and the over-quota/over-cap/under-cap admission tests still cover
   default-no-cap / over-cap / under-cap.

**Gates (this box, PHP 8.3.6 + PCOV):** `phpstan analyse --no-progress` → **[OK] No errors** (L9);
`phpunit --filter 'RelaySessionManager|ServerProxyController'` → **OK (168 tests, 539 assertions)**;
`phpcs --standard=PSR12 -n` on both touched src files → **clean (exit 0)**. psalm skipped (env);
`bin/phlix migrate` not run (no DB) — neither is a finding.

Verdict: **HB-3.4 hot-path fix DONE** (the one Low finding is closed; no new defect introduced).

## Implementer — HB-3.4 G5 (HTTP exposure of per-user quota controls) — 2026-07-12

Built out the previously-unwired HTTP surface for the per-user relay bandwidth quotas + concurrent-stream
cap (the `RelaySessionManager` accounting methods existed but were unreachable over HTTP). Build-out per
§0.1 — real controller + routes + DI + tests, no stubs. `Tunnel.php` / reaper / txn machinery untouched.

**Routes added (in `src/Application.php`, new `registerUserQuotaRoutes()` + `resolveUserQuotaController()`):**
- `GET /api/v1/me/bandwidth` — behind `[$authMiddleware]` (auth only). The caller reads their OWN
  current-period usage + caps (`$request->userId`); no admin needed.
- `GET /api/v1/admin/users/{id}/bandwidth` — behind `[$authMiddleware, $adminMiddleware]`. Admin reads
  ANY user's usage + caps.
- `PUT /api/v1/admin/users/{id}/quota` — behind `[$authMiddleware, $adminMiddleware]`. Admin sets a user's
  monthly download/upload caps + concurrent-stream cap for the current period.
These are hub-local admin/self endpoints — deliberately NOT added to the relay-proxy allowlist. The
admin routes share the `/api/v1/admin/users/{id}/...` prefix with `AdminUserController` but are distinct
sub-paths (`/bandwidth`, `/quota`) — no route collision.

**Admin-vs-self enforcement (matched the existing convention, did NOT invent one):**
- Self endpoint = a separate `me/*` route (auth-only), so a normal user only ever reaches their own data.
- Admin endpoints = the same double gate the rest of the hub admin API uses: `AdminMiddleware` on the route
  group **plus** an inline `requireAdmin()` in the controller (defence-in-depth), copied verbatim from
  `RequestController::requireAdmin()` — `UserRepository::findAdminById()` → 403 `admin_required` +
  `AuditLogger::logPermissionDenied(...)`. A non-admin hitting either admin route → 403 (a non-admin
  requesting another user's bandwidth is thus forbidden, per AC).

**Controller:** `src/Http/Controllers/UserQuotaController.php` — `final class`, `declare(strict_types=1)`,
namespace `Phlix\Hub\Http\Controllers`. Injects `RelaySessionManager`, `UserRepository`, `AuditLogger`
(promoted `private readonly`). Methods: `viewOwnBandwidth`, `viewUserBandwidth`, `setUserQuota`. Every
method gates `$request->userId` 401 `auth.required` first. Body validation → `{error, code}` 400
`invalid_quota` (non-negative ints; `max_concurrent_streams` ≤ 1000, byte caps ≤ 1 PiB; 0 = unlimited);
missing path id → 400 `missing_user_id`. Successful set is audited via
`AuditLogger::logAdminAction(admin, 'user.quota.set', targetId, {...caps})`. Response payload is a real
rollup `{user_id, bytes_in, bytes_out, quota_bytes_in, quota_bytes_out, max_concurrent_streams}` (zeroed
usage + unlimited caps when no row exists — a meaningful 200, not a 404).

**DI:** registered in `src/Common/Container/Providers/HubServicesProvider.php` (domain controller) via a
`factory()` injecting `RelaySessionManager` + `UserRepository` + `AuditLogger`, mirroring the
`RequestController` registration exactly.

**Domain signatures wired (confirmed unchanged EXCEPT one backward-compatible extension):**
- `getUserBandwidth(string $userId): ?array` — UNCHANGED.
- `getUserMaxConcurrentStreams(string $userId): int` — UNCHANGED (retained per plan; this endpoint restores
  its first production caller, exactly as its docblock anticipated for G5).
- `setUserQuota(string $userId, int $quotaBytesIn, int $quotaBytesOut, ?int $maxConcurrentStreams = null)` —
  EXTENDED with an optional 4th nullable param. Reason: the G5 admin endpoint must set all THREE caps
  (incl. `max_concurrent_streams`) but the prior 3-arg method had no way to write that column and there was
  no other setter. The extension is backward-compatible — when the param is null the original SQL/behaviour
  is preserved byte-for-byte (existing 3-arg callers + the `testSetUserQuota` domain test are unaffected);
  when provided, `max_concurrent_streams` is folded into the SAME upsert. Colon-free bind keys, named
  `:param` placeholders, no interpolation, no new SQL method.

**Tests:**
- `tests/Unit/Http/Controllers/UserQuotaControllerTest.php` (+20) — `@covers UserQuotaController`. Covers:
  admin sets quota (200 + all three caps forwarded to the 4-arg signature + `logAdminAction` audited),
  non-admin set-quota → 403 (`setUserQuota` never called), invalid body → 400 (8-case data provider:
  missing field, negative, float, non-numeric string, streams>1000, bytes>1 PiB), auth-less → 401,
  self reads own bandwidth (200; admin repo never consulted), non-admin reading another → 403
  (`getUserBandwidth` never called), admin reading any → 200, missing id → 400, zero-as-unlimited.
- `tests/Unit/Hub/RelaySessionManagerTest.php` (+1) — `testSetUserQuotaWithMaxConcurrentStreamsWritesTheColumn`
  asserts the 4-arg path folds `max_concurrent_streams` into the upsert; the existing 3-arg
  `testSetUserQuota` now also asserts the column is NOT touched (backward compat guard).

**Acceptance criteria mapping:** (1) admin set-quota exposed + admin-gated + body-validated → `setUserQuota`
route/method; (2) bandwidth view self-or-admin with 403 on cross-user by a non-admin → `viewOwnBandwidth`
(self) + `viewUserBandwidth` (admin, 403 otherwise); (3) both wired in `Application.php` behind
`AuthMiddleware` matching `me/*`+admin shapes, not on the relay allowlist; (4) DI registered in
`HubServicesProvider`; (5) build-out, no stubs, `getUserMaxConcurrentStreams` retained.

**Gates (this box, PHP 8.3.6 + PCOV):** `phpstan analyze --no-progress` → **[OK] No errors** (L9, no
baseline); `phpcs --standard=PSR12 -n` on all 4 touched src files → **clean (exit 0)**; full suite
`phpunit` → **OK — 1341 tests / 15836 assertions / 17 skipped / 0 failures** (baseline 1320 + 21 new).
psalm skipped (env: PHP 8.3.6 < psalm-required 8.3.16); `bin/phlix migrate` not run (no DB) — no new
migration needed (mig 038 `max_concurrent_streams` already exists). Neither is a finding.

## Reviewer (per-step) — HB-3.4 G5 — 2026-07-12

Reviewed commits `125fabc` + `ab1ab49` (range `06f9b1a..ab1ab49`): new `UserQuotaController`,
route wiring in `Application.php` (`registerUserQuotaRoutes`), DI in `HubServicesProvider`,
`RelaySessionManager::setUserQuota` 4th-arg extension, and tests.

**NO FINDINGS**

All 7 review checkpoints pass:

1. **Route collision — clear.** `Router::addRoute` compiles `{id}` to `(?P<id>[^/]+)` (single
   segment, no slash) and anchors every pattern `#^...$#`. The admin group registers GET
   `/{id}/bandwidth` → `#^/api/v1/admin/users/(?P<id>[^/]+)/bandwidth$#` and PUT `/{id}/quota` →
   `#^.../(?P<id>[^/]+)/quota$#`. `AdminUserController` registers `/{id}` (GET/PUT/DELETE),
   `/{id}/set-admin`, `/{id}/reset-password`, `/{id}/profiles` — all mutually exclusive with the
   new sub-paths (the `/{id}` update/get patterns require `$` immediately after the id, so a
   single `[^/]+` id can never swallow `abc/bandwidth` or `abc/quota`). No `{path:.*}`/catch-all in
   the admin-users group, no duplicate pattern key (no shadowing), correct verb separation
   (PUT `/{id}` update vs PUT `/{id}/quota` are distinct keys in `routes['PUT']`; the new read is GET).
2. **Authorization — correct.** `viewOwnBandwidth` derives the subject solely from `$request->userId`
   (no path id), so a normal user can only read their own row. Both admin methods call the inline
   `requireAdmin()` first; it is a genuine gate — `UserRepository::findAdminById($userId) === null` →
   403 `admin_required` + `AuditLogger::logPermissionDenied` — a verbatim copy of
   `RequestController::requireAdmin()` (only the audit label `admin.user_quota` differs). Behind it
   sits `AdminMiddleware` on the route group (defence-in-depth). Auth-less → 401 `auth.required`;
   non-admin (incl. requesting another user's bandwidth) → 403.
3. **`setUserQuota` extension — backward compatible.** The `$maxConcurrentStreams === null` branch is
   byte-for-byte identical to the prior 3-arg upsert (no `max_concurrent_streams` column touched);
   the non-null branch folds the column into the SAME upsert with colon-free named binds, no
   interpolation. Grep confirms the only production caller of `setUserQuota` is the new controller;
   `testSetUserQuota` now also guards the column stays untouched on the 3-arg path.
4. **Validation — correct.** `parseBoundedInt` accepts a native int or digit-only string, rejects
   float / negative / non-numeric / missing / out-of-range (byte cap ≤ 1 PiB, streams ≤ 1000),
   0 = unlimited; bad input → 400 `{error, code:'invalid_quota', message}`, missing id → 400
   `missing_user_id` — matches the repo `{error, code}` convention.
5. **DI + conventions — correct.** Registered in `HubServicesProvider` via `factory()` injecting the
   real `RelaySessionManager` + `UserRepository` + `AuditLogger`, mirroring `RequestController`; no
   raw PDO/mysqli. `Tunnel.php`, reaper, and txn machinery untouched (not in the diff). Endpoints are
   hub-local — registered only in `registerUserQuotaRoutes`, not added to any relay-proxy allowlist.
6. **Build-out policy — met.** No stubs/TODO; `getUserMaxConcurrentStreams` retained and G5 restores
   its first production caller (`bandwidthPayload`). No deletions.
7. **Tests genuine.** Non-admin paths assert `expects(self::never())->method('setUserQuota')` /
   `->method('getUserBandwidth')` and the self path asserts `never()->findAdminById` — each fails if
   the corresponding gate is removed. Auth-less → 401 and invalid-body (8-case provider) → 400 with
   `setUserQuota` never() are covered.

Read-only gates on this box: `phpstan analyse --no-progress` → **[OK] No errors** (L9, no baseline);
`phpunit --filter 'UserQuota|RelaySessionManager|AdminUser'` → **OK (86 tests, 332 assertions)**.
psalm skipped (env: PHP 8.3.6 < psalm-required 8.3.16); `bin/phlix migrate` not run (no DB) — neither
is a finding.

Verdict: **HB-3.4 G5 DONE** — NO FINDINGS.

## Orchestrator — HB-4.1–4.5 RE-AUDIT roll-up (2026-07-12, perf-4 session)

Audit agent verdicts vs the "H-W4-batch" claim (all 5 claimed DONE in one commit `70e32e2`):
- **HB-4.1 [H-R8] metrics → NOT-DONE.** MetricsCollector/Registry defined but INERT end-to-end. Producer: `RelayProxyManager` records under `$this->metrics?->…` but DI (`HubServicesProvider.php:343-350`) constructs it with NO metrics arg (ctor default null, no `setMetrics()` anywhere) → pending-gauge/reply-drop/latency/503/504 are all no-ops; only decode-buffer gauge is fed (`RelayWorker.php:224`). Drain: `MetricsRegistry::drainRelayMetrics()` has ZERO callers; `MetricsFlushService::flush()` never drains relay metrics → migration-036 columns never written. Also: no_tunnel-503 branch (`RelayProxyManager.php:220-228`) records no metric; latency AC "first-byte+total" only records one point (buffered KIND_END) and streaming path records none. Tests assert nothing about emission. → Complete.
- **HB-4.2 [H-D2] token sweep → PARTIAL.** Wired + runs on the maintenance worker (real), but `ClientRelayTokenService::pruneExpiredTokens()` (`:225-229`) uses `expires_at < NOW()-INTERVAL 1 DAY AND revoked_at IS NOT NULL` — AC/finding require `OR`. Expired-never-revoked tokens (common, 1h TTL) never pruned → table still grows. Test (`ClientRelayTokenServiceTest:185-204`) is a string-contains that locks in the bug. → Complete (AND→OR + rewrite test behaviorally).
- **HB-4.3 [H-D1] heartbeat growth → DONE (confirmed).** `HeartbeatHandler::pruneServerHeartbeats/pruneAllServerHeartbeats(100)` keep-last-N ring-delete on the maintenance-worker Timer; index-backed; genuine behavioral tests. No action.
- **HB-4.4 [H-A2] library-hash dedupe → DONE (confirmed).** `updateServerLibraries()` SHA-256 short-circuit vs `server_library_hashes` (mig 037), real skip-when-unchanged; behavioral tests. (Non-AC note: library sync still inside the heartbeat txn.) No action.
- **HB-4.5 [H-W3] prune singleton → NOT-DONE.** `MetricsFlushService::flush()` unconditionally calls `prune()` every ~min; `flush()` armed from every HTTP worker (HUB_WORKERS=2) + relay + client-relay ≈4 procs → N× DELETE churn. "per-process singleton" is orthogonal to the AC; no worker-id-0/leader gate. → Complete (gate prune to one worker; flushes stay per-worker; add single-pruner test).

Queue: HB-4.1 (Complete→review→test) → HB-4.2 → HB-4.5. Then RE-AUDIT batch 2 (HB-4.6–4.10).

## Implementer — HB-4.1 — 2026-07-12 (all 4 audit gaps closed)

Commits: **b2feb66** (impl) + **a25a478** (tests), pushed to master (was 7bd1324). Tree clean,
local==origin==a25a478. Suite **1356 pass / 0 fail / 17 skip** (baseline 1341 + 15 new), phpstan L9
**0 errors**, phpcs PSR-12 `-n src/` **clean** (psalm skipped — box PHP 8.3.6 < required; migrate can't
run — no DB; neither is a finding).

**Gap 1 — collector wiring (the regression).** Injected the per-worker SHARED `MetricsCollector` into
the `RelayProxyManager` DI factory (`HubServicesProvider.php` — added `use …MetricsCollector;` + a
`MetricsCollector $metrics` factory param bound `->parameter('metrics', get(MetricsCollector::class))`,
constructed via named arg `metrics: $metrics` so the defaulted timeout/publisher are unchanged). Chose
the DI-factory wire (the audit's second option) over a `RelayWorker::setMetrics()` because it makes the
production proxy manager ALWAYS carry the collector and is directly unit-testable. PROOF of "same
instance the worker drains": all four metrics services are SHARED singletons per worker
(`MetricsServicesProvider`), and `MetricsFlushService` is built from `get(MetricsCollector::class)` —
so proxy-manager collector === worker collector (`RelayWorker.php:183`) === flush collector === one
`MetricsRegistry`. `RelayProxyManagerWiringTest` asserts `proxyManager.metrics ===
container.get(MetricsCollector) === registry the flush drains`. The collector no-ops every record when
metrics are disabled, so injecting unconditionally is safe.

**Gap 2 — drain + persist (migration-036 columns).** `MetricsFlushService::flush()` now calls
`registry->drainRelayMetrics($nowTs)` and the new `flushRelay()` UPSERTs into `metrics_rollup`:
`relay_pending_requests` (gauge → `GREATEST`), `relay_reply_drops`/`relay_error_503`/`relay_error_504`
(counters → `col + VALUES`), `relay_decode_buffer_bytes` (gauge → `GREATEST`), and the
`relay_latency_h_le_10..h_gt_5000` histogram (counters). The latency histogram is already time-bucketed
so each bucket's row gets its histogram; the window-total scalars go into the current flush bucket
(new `MetricsRegistry::bucketStart()` exposes the private bucket alignment). All-zero windows are
skipped. Bind names are non-prefixing (`:rl0..:rl8`, `:re503`, `:re504`, `:rpending`, `:rdrops`,
`:rbuffer`) — same emulated-prepares/HY093 guard as `flushOverall`. **No new migration** — 036 already
added every column.

**Gap 3 — no_tunnel-503 counter.** The `server.no_tunnel` fail-fast branch (`RelayProxyManager::onRequest`)
now calls `$this->metrics?->recordRelayError(503)` before replying — previously only `failServer`
(server.offline) counted a 503.

**Gap 4 — first-byte + total latency, incl. the streaming path.** Added a per-request
`first_byte_recorded` flag; `onResponseFrame` records first-byte (TTFB = send→first response frame) once
on the first decoded frame for BOTH streaming and buffered, and records TOTAL (send→END) on KIND_END for
BOTH paths (moved out of the buffered-only block so a STREAMING response — which returns early — now
contributes a latency observation at completion). The single migration-036 relay-latency histogram thus
receives both the first-byte and the total observation per completed request (the AC's "first-byte +
total").

**Tests (+15).** `RelayProxyManagerTest` (+7): a REAL `MetricsCollector`+`MetricsRegistry` injected,
asserting recorded state end-to-end — pending gauge inc-on-request/dec-on-completion; `recordRelayError(503)`
on BOTH no_tunnel + failServer; `recordRelayError(504)` on timeout; reply-drop on an unknown-request
frame; latency = 2 observations (first-byte + total) on BOTH buffered and streaming. `MetricsFlushServiceTest`
(+6): flush drains + persists all relay columns with the drained values; idle all-zero window skipped;
drain resets so a 2nd flush writes nothing; relay-insert bind names non-prefixing; relay UPSERT passes
the `BindingContractConnection` colon-free contract; latency's own-bucket vs scalar-bucket placement.
`RelayProxyManagerWiringTest` (+2, new file): the DI-constructed proxy manager has a non-null collector
that is the same shared instance/registry the flush drains (guards the exact audit regression).

Scope kept to HB-4.1: did NOT touch `Tunnel.php`, reapers, or txn-locking; decode-buffer gauge feed
(`RelayWorker.php:224`) was already correct and is now covered end-to-end by the drain-persist test.
Docs cycle (CHANGELOG/README/docblocks-beyond-touched) still owed per the batched sweep.

## ⏸ PERF-4 PAUSE STATE (2026-07-12) — hub resume point
- **HB-3.3** ✅ (prior). **HB-3.4** ✅ FULLY DONE: core G1–G4 `f8dc4e3`/`dbafd72`, fix (double quota-row read → single combined `checkUserQuota` read) `f52b97a`/`47bc368` re-review NO FINDINGS, **G5** (quota HTTP endpoints: `GET /me/bandwidth`, `GET|PUT /admin/users/{id}/bandwidth|quota`) `125fabc`/`ab1ab49` review NO FINDINGS. Suite 1341/0.
- **HB-4.1 (relay observability metrics)** — **IMPLEMENTED + committed** `b2feb66` (impl) / `a25a478` (tests) / `868fcd4` (worklog): wired shared MetricsCollector into RelayProxyManager via DI + wiring test (closed the inert-metrics regression); drain+persist to migration-036 columns; no_tunnel-503 counter; first-byte+total latency incl. streaming. Suite 1356/0. **BUT its REVIEW agent DIED on a session API limit mid-review (no verdict).** **RE-SPAWN the HB-4.1 REVIEW first on resume** — key checks: DI singleton identity (proxy collector === drained collector), drain-RESET so counters don't double-count each flush, migration-036 column-name match, and the DESIGN QUESTION of blending first-byte + total into the single latency histogram (acceptable vs. a finding).
- **Hub queue after HB-4.1 review:** HB-4.2 (`AND`→`OR` in `ClientRelayTokenService::pruneExpiredTokens` + rewrite the string-contains test behaviorally — expired-never-revoked tokens currently never pruned) → HB-4.5 (gate `MetricsFlushService::prune()` to ONE worker; flushes stay per-worker; single-pruner test) → RE-AUDIT HB-4.6–4.10.
- **Audit roll-up already recorded above:** HB-4.3/4.4 confirmed genuinely DONE; HB-4.1 NOT-DONE (now impl'd), HB-4.2 PARTIAL, HB-4.5 NOT-DONE.

## Reviewer (per-step) — HB-4.1 — 2026-07-12

Reviewed `git diff 7bd1324..868fcd4` (impl b2feb66 + tests a25a478) against the H-R8 finding, the
migration-036 schema, and the four audit gaps. Read-only gates on this box: **phpstan L9 [OK] No
errors**; `phpunit --filter 'RelayProxyManager|MetricsFlush|MetricsRegistry'` **OK (87 tests, 1088
assertions)**. (psalm/migrate skipped — env, not findings.)

**The core regression IS genuinely closed** (task item #1): the `HubServicesProvider` factory now
injects `MetricsCollector` via a bound `metrics` param + named-arg construction; all three metrics
services are PHP-DI singletons (one instance per worker), so proxy-manager collector ===
`get(MetricsCollector::class)` === the registry `MetricsFlushService::flush()` drains via
`$this->collector->registry()`. `RelayProxyManagerWiringTest` asserts identity with `assertSame`, not
just non-null. Drain-reset is correct: `drainRelayMetrics()` zeroes every accumulator and
`test_relay_metrics_are_drained_so_a_second_flush_writes_nothing` proves a 2nd flush writes no row (no
double-count). Column names/semantics match migration 036 EXACTLY — counters additive
(`col + VALUES`), gauges `GREATEST`, latency buckets [10,50,100,250,500,1000,2500,5000]+(-1→h_gt_5000)
map correctly; bind keys colon-free/non-prefixing and guarded by the prefix + BindingContractConnection
tests. no_tunnel-503 records once before replying then returns (no double vs failServer/504); the
first-byte flag is per-`pending`-entry (per requestId), initialised false at admission — no cross-request
carryover. Scope clean: `Tunnel.php`/reapers/txn untouched; the decode-buffer gauge feed
(`RelayWorker.php:224`) is intact and now covered end-to-end. Emission tests inject a REAL
collector+registry and assert registry state (fail against the pre-fix null collector) — not tautological.

**2 findings**, most severe first.

1. **`src/Relay/RelayProxyBridge.php:576-582` (`dropReply`) — MEDIUM — the reply-drop counter is wired
   at the wrong site; the drop the H-R8 finding NAMED is still uncounted.** H-R8's location text is
   explicit: "channel-push drops (`RelayProxyBridge.php:527-530` logs but no counter)". That drop —
   a reply discarded because the client consumer channel is full/closed (the H-H6 stall / H-H3 back-
   pressure failure mode, the exact thing an operator needs to alert on) — still only
   `logger->warning(...)` and increments NO counter (`RelayProxyBridge` has no `MetricsCollector`; its
   factory `HubServicesProvider.php:363` constructs it with the logger only). The impl instead put
   `recordRelayReplyDrop()` in `RelayProxyManager::onResponseFrame` (`:351`) — a DIFFERENT event: an
   inbound HTTP_RESPONSE frame for an already-torn-down/unknown request. **Failure scenario:** a slow or
   gone browser causes the hub to throw away its replies via `dropReply`; the operator sees zero
   `relay_reply_drops` and stays blind to precisely the stall H-R8 exists to surface, while the counter
   that IS populated measures orphan server frames. **Fix direction:** inject the shared
   `MetricsCollector` into `RelayProxyBridge` (same DI pattern just added for `RelayProxyManager`) and
   call `recordRelayReplyDrop()` inside `dropReply()`; keep the `RelayProxyManager` orphan-frame counter
   as a separate signal if desired, but the named channel-push drop must be counted.

2. **`src/Relay/RelayProxyManager.php:413-417 (+367-377)` — MEDIUM — blending first-byte AND total into
   the single migration-036 latency histogram muddies it, and for STREAMING responses records a
   stream-DURATION as "latency".** Each completed request now records TWO observations into the one
   `relay_latency_h_*` histogram: first-byte (`:376`) and total (`:417`, moved out of the buffered-only
   block onto both paths). Migration 036 documents that histogram as "time from HTTP_REQUEST sent to
   first response byte" — first-byte only — so recording total contradicts the column's documented
   meaning, and blending doubles the sample count with mixed semantics so any percentile is a
   meaningless TTFB/total mix. **Worse for streaming:** for a streamed response `total = sent_at → END`
   = the entire stream/session duration, which the sweep allows up to the 900s absolute ceiling (and a
   direct media stream runs for the whole playback). Every media stream therefore dumps a multi-minute
   observation into `relay_latency_h_gt_5000`, so that bucket is dominated by stream lengths, not
   latencies — a "latency > 5s" alert would fire on every normal playback. `test_streaming_request_
   records_first_byte_and_total_latency` locks this blend in. **This is not acceptable as-is** (it
   defeats H-R8's operational intent). **Fix direction:** record ONLY first-byte into the histogram
   (a true latency for both buffered and streaming, matching the migration doc); drop the
   `recordRelayLatency($totalMs)` call. If a round-trip/total metric is genuinely wanted it belongs in a
   SEPARATE scalar column (needs a new migration — out of this step's schema) and must NOT record a
   streaming send→END as "latency" (it is a session duration). Simplest in-scope resolution: restrict
   the histogram to first-byte and adjust the streaming/buffered latency tests to assert one observation.

Accepted-as-designed (not findings): resetting the `relay_pending_requests` / `relay_decode_buffer_bytes`
GAUGES to 0 on drain is correct for the migration's documented "high-water mark across the flush window"
semantics (they are re-set on the next lifecycle/decode event; `GREATEST` merges same-bucket flushes) —
only a fully activity-free window under standing streams under-reports, which carries no new signal.

**2 FINDINGS.**

## Fixer — HB-4.1 (2 review findings) — 2026-07-12

Both Medium findings fixed; scope kept to them (did NOT touch `Tunnel.php`, reapers, or txn-locking;
reused the existing `MetricsCollector` API — no new recorders). Commits: **fix** `9c4cdd9` (src) +
**tests** `4d68b1e`. Full suite **1359 pass / 0 fail / 17 skip** (baseline 1356 + 3 net new),
phpstan L9 **0 errors**, phpcs PSR-12 `-n src/` **clean** (psalm skipped — box PHP 8.3.6 < required;
migrate/DB N/A — not findings).

**Finding 1 (reply-drop at the wrong site) — CLOSED.** Injected the SAME per-worker shared
`MetricsCollector` singleton into `RelayProxyBridge` and count the operationally-critical channel-push
drop where it actually happens:
- `src/Relay/RelayProxyBridge.php` — added `use …MetricsCollector;` + a promoted
  `private readonly ?MetricsCollector $metrics = null` (last ctor param, BC-safe for the existing
  positional test constructions); `dropReply()` now calls `$this->metrics?->recordRelayReplyDrop()`
  before its existing `warning()`. `dropReply` is the H-R8-named drop (a reply discarded because the
  client's consumer channel is full/gone — the H-H6 stall / H-H3 back-pressure failure mode).
- `src/Common/Container/Providers/HubServicesProvider.php:~362` — the `RelayProxyBridge` DI factory now
  takes `MetricsCollector $metrics` bound `->parameter('metrics', get(MetricsCollector::class))` and
  constructs via named arg `metrics: $metrics`, mirroring the `RelayProxyManager` factory. Both metrics
  services are PHP-DI singletons per worker, so bridge collector === `get(MetricsCollector::class)` ===
  the registry `MetricsFlushService::flush()` drains — proven by the new wiring test (`assertSame`).
- **Orphan-frame count decision: KEPT.** The impl's existing count at `RelayProxyManager::onResponseFrame`
  (`:351`, an inbound HTTP_RESPONSE for an unknown/torn-down request) is a DISTINCT event from the
  bridge's channel-push drop; both legitimately feed `relay_reply_drops` and are counted at different
  sites (manager = orphan inbound server frame; bridge = reply the client couldn't take). No single
  event is double-counted — a given reply is either dropped at the manager (unknown request) OR handed
  to the bridge and possibly dropped there, never both.

**Finding 2 (latency histogram streaming-duration pollution) — CLOSED.** Record ONLY first-byte into
the migration-036 histogram (its documented meaning); removed the total observation.
- `src/Relay/RelayProxyManager.php:~413` — deleted the `$totalMs = … ; recordRelayLatency($totalMs)`
  call in the KIND_END block (the one that, for a streaming response, recorded send→END = the whole
  stream/session duration, up to the ~900s ceiling, into `relay_latency_h_gt_5000` → a "latency > 5s"
  alert on every normal playback). First-byte recording is unchanged and still fires once per request
  for BOTH buffered and streaming (legit + useful for both). `sent_at` is retained (still used by
  first-byte + the sweep timer). No other path feeds `total` into the histogram (grep-verified: the
  only `recordRelayLatency` caller is now the first-byte site).

**Tests (+3 net new; 2 adjusted).**
- `tests/Unit/Relay/RelayProxyBridgeTest.php` — `test_drop_reply_records_a_reply_drop_on_the_injected_collector`:
  a REAL `MetricsCollector`+`MetricsRegistry`; a full/gone fake channel + no coroutine context (cid=-1
  under plain PHPUnit) drives `onReply` → drop path → `dropReply`; asserts `relayReplyDrops === 1`.
- `tests/Unit/Relay/RelayProxyBridgeWiringTest.php` (new) — DI-constructed bridge has a non-null
  collector that is the SAME shared instance/registry the flush drains (mirrors
  `RelayProxyManagerWiringTest`; guards the exact injection regression).
- `tests/Unit/Relay/RelayProxyManagerTest.php` — the two latency tests now assert exactly ONE
  observation per request: `test_buffered_request_records_only_first_byte_latency` (was "…first_byte_and_total",
  final assert 2→1) and `test_streaming_request_records_only_first_byte_latency_not_stream_duration`
  (was "…first_byte_and_total"). The streaming test BACKDATES `sent_at` by 1000s (after first-byte was
  recorded) so a would-be total would land in the `>5000ms` bucket, then asserts total observations == 1
  AND the `>5s` overflow bucket == 0 — proving a long stream never pollutes the histogram.

Verify (this box, PHP 8.3.6 + PCOV):
- `phpunit --filter 'RelayProxyBridge|RelayProxyManager|MetricsFlush|MetricsRegistry'` → OK (106 tests).
- full suite → **OK 1359 / 16132 assertions / 17 skipped / 0 failures**.
- `phpstan analyse --no-progress` → **[OK] No errors** (L9).
- `phpcs --standard=PSR12 -n src/` → **clean (exit 0)**.

## Reviewer (re-review) — HB-4.1 fix — 2026-07-12

Reviewed `git diff a447294..374a972` (fix `9c4cdd9` src + `4d68b1e` tests) against the 2 prior
Medium findings, the H-R8 finding, and migration 036. Read-only gates on this box: phpstan L9
**[OK] No errors**; `phpunit --filter 'RelayProxyBridge|RelayProxyManager|MetricsFlush'` **OK (87
tests)**; full suite **OK 1359 / 16132 assertions / 17 skipped / 0 failures** (baseline 1356 + 3 net
new); phpcs PSR-12 `-n src/` **clean (exit 0)**. (psalm/migrate skipped — env, not findings.)

**NO FINDINGS**

Both Medium findings are genuinely closed and no new defect was introduced:

- **Finding 1 (reply-drop at the wrong site) — CLOSED.** `RelayProxyBridge::dropReply()` (`:590`) now
  calls `$this->metrics?->recordRelayReplyDrop()` — the correct recorder name (exists at
  `MetricsCollector.php:167`) matching HB-4.1's API. `dropReply` is the H-R8-named channel-push drop:
  reached from `onReply` (cid<=0 path, `:551`) and `deliverReplyInFiber` (`:569`, push-timeout) — the
  reply the client's consumer channel is full/gone (H-H6 stall / H-H3 back-pressure). The
  `MetricsCollector` is injected via the `HubServicesProvider` factory (`:362`, `metrics: $metrics` bound
  to `get(MetricsCollector::class)`), and `RelayProxyBridgeWiringTest` asserts IDENTITY (`assertSame`) —
  bridge collector === `get(MetricsCollector::class)` AND bridge collector->registry() ===
  `get(MetricsRegistry::class)` the flush drains. The ctor param is BC (`private readonly ?MetricsCollector
  $metrics = null`, nullable, LAST, defaulted) so existing positional constructions are unbroken. The
  retained orphan-frame count at `RelayProxyManager::onResponseFrame` (`:351`) is a DISTINCT event — an
  inbound HTTP_RESPONSE for an unknown/torn-down request, which `return`s immediately and never reaches
  the bridge; a given reply is dropped at the manager (unknown request) OR the bridge (channel full/gone),
  never both. No double-count.

- **Finding 2 (streaming-duration pollution) — CLOSED.** The `recordRelayLatency($totalMs)` call in the
  KIND_END block is removed. Grep-confirmed the ONLY remaining `recordRelayLatency` caller in `src/` is
  the first-byte site (`RelayProxyManager.php:379`). First-byte still fires once per request for BOTH
  buffered and streaming (`:376-380`, unchanged). `sent_at` is retained (first-byte + sweep). The
  streaming test backdates `sent_at` by 1000s AFTER first-byte was recorded, then asserts exactly ONE
  latency observation AND `>5000ms` overflow bucket == 0 — proving a long stream never lands in the
  histogram (a re-added total would produce a ~1,000,000ms observation in the overflow bucket, failing
  both assertions). No other code path feeds a total/session duration into the histogram.

- **No new regression / scope.** Diff touches only `HubServicesProvider.php`, `RelayProxyBridge.php`,
  `RelayProxyManager.php` + 3 test files. `Tunnel.php`, reapers, and txn-locking are untouched. The
  accepted HB-4.1 emitters are intact: no_tunnel-503 (`RelayProxyManager.php:225`), failServer-503
  (`:454`), timeout-504 (`:578`), pending gauge (`:288/414/469/495/577`), drain/persist to migration-036
  columns, and the DI wiring. phpstan L9 clean; full suite green.

- **Tests genuine (not tautological).** The reply-drop test injects a REAL `MetricsCollector`+`MetricsRegistry`,
  a full/gone fake channel + no coroutine context (cid=-1 under plain PHPUnit) to drive the real
  `onReply → dropReply` path and asserts `relayReplyDrops === 1` (fails against the pre-fix null
  collector). The wiring test guards the injection via `assertSame`. The first-byte-only latency tests
  would fail if the total recording were re-added (observation count 2 and overflow bucket 1).

Verdict: **HB-4.1 fix DONE — NO FINDINGS.** Loop may exit (docs cycle still owed per the batched sweep).

## Implementer — HB-4.2 — 2026-07-12

**The bug (H-D2):** `ClientRelayTokenService::pruneExpiredTokens()` deleted only rows that were BOTH
expired-by->1-day AND explicitly revoked. Tokens have a ~1 h TTL and are rarely revoked, so the common
expired-never-revoked row was never pruned → `client_relay_tokens` grows unbounded (defeating the sweep).
The sweep is genuinely wired (runs on the maintenance worker via `IdleReaper::reapDbMaintenance`); only
the predicate was wrong.

**Fix (predicate):** `src/Hub/ClientRelayTokenService.php:225-229`.
- Before: `WHERE expires_at < NOW() - INTERVAL 1 DAY AND revoked_at IS NOT NULL`
- After:  `WHERE expires_at < NOW() - INTERVAL 1 DAY OR  revoked_at IS NOT NULL`
- **Operator precedence confirmed:** `<` and `IS NOT NULL` bind tighter than `OR`, so the WHERE parses
  as `(expires_at < NOW() - INTERVAL 1 DAY) OR (revoked_at IS NOT NULL)` — a revoked token is deleted
  regardless of expiry; an expired-by->1-day token is deleted regardless of revocation. No parentheses
  needed / added; parameterization idiom otherwise identical (bare DELETE, no binds). Uses the existing
  `expires_at` index; no new index added (per AC — a full OR may not use it as tightly; acceptable).

**Docs corrected (the misleading "both expired AND revoked" wording):**
- `ClientRelayTokenService::pruneExpiredTokens` docblock (`:211-232`) — rewritten to OR semantics +
  a precedence note + the rationale (expired-never-revoked is the common growth case).
- `IdleReaper` class-level bullet (`:229-230`) and the inline comment at the call site (`:249-252`) —
  "expired, already-revoked … older than 1 day" → "expired more than 1 day ago OR were revoked".
- `IdleReaper` constructor `@param $clientRelayTokenService` (`:63-66`) — "expired revoked" →
  "expired-or-revoked".

**Test rewritten (it previously locked in the bug).** `tests/Unit/Hub/ClientRelayTokenServiceTest.php`
— the old `test_prune_expired_tokens_issues_correct_delete_query` was a string-contains that asserted
`revoked_at IS NOT NULL` with NO AND-vs-OR check. Replaced with two tests:
- `test_prune_expired_tokens_or_joins_expiry_and_revoked_predicates` — keeps the DELETE/predicate
  substring checks AND extracts the joining operator via regex
  (`/INTERVAL 1 DAY (AND|OR) revoked_at IS NOT NULL/i`) and asserts it is `OR`, not `AND`; retains the
  null-params assertion.
- `test_prune_deletes_expired_never_revoked_row_behaviorally` — the important one. A fake DB
  (createMock + willReturnCallback) parses the emitted WHERE's operator and EVALUATES it over four
  fixture rows (expired-never-revoked, revoked-not-expired, fresh-active, expired-and-revoked). Asserts
  3 of 4 pruned, the **expired-never-revoked** row IS removed (fails under the old AND), the revoked
  and expired-and-revoked rows removed, and the fresh-active row survives. A regression back to AND is
  evaluated as AND by the fake → the expired-never-revoked assertion fails → the bug is caught.
- `test_prune_expired_tokens_returns_zero_when_result_not_int` unchanged. IdleReaperTest wiring
  coverage unaffected (docstring-only edits there).

**Verify (this box, PHP 8.3.6 + PCOV):**
- `./vendor/bin/phpstan analyse --no-progress` → **[OK] No errors** (L9, no baseline).
- `php -d max_execution_time=0 ./vendor/bin/phpunit` → **OK, 1360 tests / 16140 assertions / 17 skipped
  / 0 failures** (baseline 1359 + 1 net-new from the test rewrite).
- `./vendor/bin/phpcs --standard=PSR12 -n src/` → **clean (exit 0)**. (The touched TEST file uses the
  file's pre-existing snake_case method names, which are outside the `src/`-only phpcs gate.)
- psalm skipped (box PHP 8.3.6 < psalm-required 8.3.16 — environmental).

**AC mapping:** (1) predicate AND→OR with correct precedence ✅; (2) misleading docblocks corrected ✅;
(3) test rewritten behaviorally incl. expired-never-revoked deletion proof ✅.

## Reviewer (per-step) — HB-4.2 — 2026-07-12

Reviewed `git diff 847b0a1..0322fa8` (0abc3d2 fix+docs, 0322fa8 tests) against
`src/Hub/ClientRelayTokenService.php`, `src/Relay/IdleReaper.php`, and
`tests/Unit/Hub/ClientRelayTokenServiceTest.php`.

**NO FINDINGS**

1. **Predicate correctness — confirmed.** `pruneExpiredTokens()` (`ClientRelayTokenService.php:237-241`)
   now emits `DELETE FROM client_relay_tokens WHERE expires_at < NOW() - INTERVAL 1 DAY OR revoked_at
   IS NOT NULL`. This is a single `A OR B` with no `AND`, so there is no misgrouping risk: `-`/`INTERVAL`
   bind tighter than `<`, and `<` / `IS NOT NULL` bind tighter than `OR`, yielding exactly
   `(expires_at < (NOW() - INTERVAL 1 DAY)) OR (revoked_at IS NOT NULL)`. No parens needed. Case check:
   expired-never-revoked → A true → deleted (the bug's core case); revoked-but-fresh → B true → deleted;
   fresh-active → A false, B false → survives (so NOT a delete-all). Matches the plan AC (line 758) and
   H-D2 recommended fix verbatim. Static SQL, no user input, no binds → no injection / colon-free binds
   N/A.

2. **Docs accurate — confirmed.** The `pruneExpiredTokens` docblock (`:211-229`) states OR semantics,
   an explicit "operator is OR, not AND" note, and a precedence note; `IdleReaper` `@param` (`:64`),
   class-level bullet (`:229-230`), and call-site comment (`:249-251`) all describe "expired more than
   1 day ago OR were revoked". Grep of `src/` finds no lingering "both … AND …" prune wording (remaining
   `AND revoked_at` hits are unrelated revoke-UPDATE / sharing / federation queries).

3. **Tests genuinely behavioral, not tautological — confirmed.**
   `test_prune_deletes_expired_never_revoked_row_behaviorally` extracts the WHERE operator from the
   emitted SQL and evaluates it (`usesOr ? (A||B) : (A&&B)`) over four fixtures; it asserts the
   expired-never-revoked row IS pruned and 3-of-4 deleted with fresh-active surviving — a regression to
   AND evaluates the fixture as AND, keeps expired-never-revoked, and fails both assertions.
   `test_prune_expired_tokens_or_joins_expiry_and_revoked_predicates` regex-extracts the join operator
   and asserts `OR` not `AND`. Both genuinely guard the fix.

4. **Scope / no regression — confirmed.** Diff touches only the two source files, the test, and the
   worklog. No new index (relies on the existing `expires_at` index per AC — full-OR not using it
   tightly is acceptable). Sweep wiring intact: `IdleReaper::reapDbMaintenance` still calls
   `pruneExpiredTokens()` on the maintenance worker (`IdleReaper.php:252`), only docblocks/comments
   changed there. `Tunnel.php` / reaper machinery / txn-locking untouched.

Gates (this box, PHP 8.3.6): `phpstan analyse` L9 → [OK] No errors;
`phpunit --filter 'ClientRelayTokenService|IdleReaper'` → OK (26 tests, 89 assertions). Verdict:
**NO FINDINGS**.

## Implementer — HB-4.5 — 2026-07-12 (gate metrics prune to a single worker)

**Bug (RE-AUDIT verdict NOT-DONE):** `MetricsFlushService::flush()` unconditionally called
`prune()` on its throttled (~1/min) tick — the three retention `DELETE`s
(`metrics_connections` / `metrics_rollup` / `metrics_route_rollup`). `flush()` is armed from
EVERY worker: each HTTP worker (`HUB_WORKERS`, default 2), the relay worker, and the client-relay
worker ≈4 procs, so ~4 processes each issued the same three DELETEs every minute — the N× retention
churn [H-W3] describes. The prior "per-process singleton in the factory" change was orthogonal to
the AC (it de-duped the *object per worker*, not the *prune across workers*); no worker gate existed.

**Gating mechanism (flag, not split):** added a `bool $shouldPrune = false` parameter to
`MetricsFlushService::flush(int $workerId, int $nowTs, bool $shouldPrune = false)`. Inside the
existing once-a-minute throttle block:
- the DB retention `prune($nowTs)` now runs ONLY when `$shouldPrune === true`;
- the in-RAM `$registry->pruneStaleConnections(...)` eviction stays UNCONDITIONAL (runs on every
  worker's tick) — it is per-worker registry hygiene; each worker owns a DISTINCT registry, so gating
  it off would let the client-relay worker's connection map grow unbounded (CARDINAL bounded-memory
  rule). Only the shared-table DELETEs were ever the multiplicity problem.
Default `false` is defensive: a worker never prunes unless it is the explicitly-designated single
pruner, so a future arming site can't silently re-introduce N× churn. Flush cadence and the prune SQL
are unchanged (only the prune's multiplicity was wrong).

**Which worker prunes + why it's guaranteed single-instance:** the **count=1 relay worker**
(`RelayWorker`). It is constructed unconditionally in `Application::boot()`
(`Application.php:1819` — `new RelayWorker($this->container, $relayPort, 1, …)`) and always started;
its Workerman worker is `count = 1` (`RelayWorker::start()` `$worker->count = $this->count` = 1). It
is the semantic owner of the tunnels and already arms `flush()`, so it is the natural, always-present
single pruner — no config can disable it (unlike gating on an HTTP `$worker->id === 0`, which would
still be correct but the relay worker is the cleaner guaranteed-single choice per the finding's hint).

**Arming call sites changed (so exactly one prunes):**
- `src/Relay/RelayWorker.php` (~:200) — the single pruner → `flush(0, time(), true)`.
- `src/Application.php` (~:1534) — HTTP workers → `flush(0, time(), false)`.
- `src/Relay/ClientRelayWorker.php` (~:205) — client-relay worker → `flush(0, time(), false)`.
All three still FLUSH their own per-worker registry every tick (unchanged); only the relay worker
also runs the retention DELETEs.

**Files changed (absolute):**
- `/home/sites/phlix/phlix-hub/src/Stats/Metrics/MetricsFlushService.php` — `flush()` signature +
  `$shouldPrune` gate on `prune()`; docblock.
- `/home/sites/phlix/phlix-hub/src/Relay/RelayWorker.php` — pass `true` (single pruner) + comment.
- `/home/sites/phlix/phlix-hub/src/Application.php` — HTTP workers pass `false` + comment.
- `/home/sites/phlix/phlix-hub/src/Relay/ClientRelayWorker.php` — pass `false` + comment.
- `/home/sites/phlix/phlix-hub/tests/Unit/Stats/Metrics/MetricsFlushServiceTest.php` — +3 tests;
  updated the two existing DELETE-asserting tests to the pruning path.

**Single-pruner tests added:**
- `test_flush_does_not_prune_when_should_prune_is_false` — a non-pruning worker crosses the prune
  tick (12 flushes) with real request activity: rollup INSERTs ARE written (registry still flushed),
  but ZERO `DELETE FROM metrics_{connections,rollup,route_rollup}` fire — the gate that removes the
  ~4× churn.
- `test_flush_prunes_only_on_the_designated_pruning_worker` — with `$shouldPrune=true`, exactly one
  DELETE per table fires at the throttled prune tick.
- `test_in_ram_connection_eviction_runs_even_when_prune_is_gated_off` — decoupling proof: an idle
  connection is still evicted from the in-RAM registry map with `$shouldPrune=false` and NO DB DELETE.
- `test_prune_is_throttled_across_flushes` (existing, kept green) + the binding-contract test updated
  to pass `true` so they still exercise the throttled DELETEs on the pruning path.

**AC mapping:** (1) every worker still flushes its own registry — the flush* calls are untouched and
`test_flush_does_not_prune_when_should_prune_is_false` proves rollups persist on a non-pruning worker;
(2) prune runs from exactly one worker — only the count=1 relay worker passes `true`; (3) HTTP +
client-relay flush but don't prune — both pass `false`; (4) flush cadence + prune SQL unchanged.

**Verify (this box, PHP 8.3.6 + PCOV):**
- `./vendor/bin/phpstan analyze --no-progress` → **[OK] No errors** (L9, no baseline).
- Full suite `php -d max_execution_time=0 ./vendor/bin/phpunit` → **OK, 1363 tests / 16149
  assertions / 17 skipped / 0 failures** (baseline 1360 + 3 new).
- `./vendor/bin/phpcs --standard=PSR12 -n src/…` (4 touched src files) → **clean (exit 0)**.
- psalm skipped (box PHP 8.3.6 < psalm-required 8.3.16 — environmental, not red); migrate not run
  (no DB; no migration touched).

## Reviewer (per-step) — HB-4.5 — 2026-07-12

**NO FINDINGS.**

Reviewed `git diff c63aa62..6b29113` (06f1d31 fix + 6b29113 tests) against current
`src/Stats/Metrics/MetricsFlushService.php`, `src/Relay/RelayWorker.php`,
`src/Relay/ClientRelayWorker.php`, `src/Application.php` (both the flush arming AND the
relay-worker construction in `boot()`), and `tests/Unit/Stats/Metrics/MetricsFlushServiceTest.php`.

- **#1 Exactly once, never zero — CONFIRMED.** Only the count=1 relay worker passes
  `flush(0, time(), true)` (`RelayWorker.php:207`); the HTTP arming site
  (`Application.php:1540`) and the client-relay arming site (`ClientRelayWorker.php:209`) both
  pass `false`. `grep` over `src/` finds no other `flush()` caller. **Never zero:** the
  `RelayWorker` is constructed unconditionally in `Application::boot()`
  (`Application.php:1825` — `new RelayWorker($this->container, $relayPort, 1, …)`) and
  `->start()`ed at `:1826` with ctor `count=1` — NOT behind any relay-enabled/config flag, so
  the designated pruner is guaranteed to run. The relay flush-timer arming and BOTH non-pruning
  arming sites gate on the SAME `MetricsCollector::isEnabled()`, so there is no divergence where
  HTTP workers flush (growing `metrics_rollup`) while the relay worker fails to prune: metrics
  off ⇒ nobody flushes/prunes (nothing to prune); metrics on ⇒ all arm, relay prunes. (The relay
  arming additionally requires `TunnelManager` to resolve — a pre-existing structural coupling the
  relay worker is fundamentally built on, not introduced by this diff and not a config toggle.)
- **#2 Per-worker flush preserved — CONFIRMED.** `flushBuckets/Routes/Connections/Relay` run
  unconditionally before the throttle/prune block; only the DB `prune()` is gated.
  `test_flush_does_not_prune_when_should_prune_is_false` asserts `INSERT INTO metrics_rollup`
  fires with `$shouldPrune=false`.
- **#3 In-RAM eviction correctly unconditional — CONFIRMED.** `$registry->pruneStaleConnections()`
  sits OUTSIDE the `if ($shouldPrune)` block (still inside the once-a-minute tick), so it runs on
  every worker; `test_in_ram_connection_eviction_runs_even_when_prune_is_gated_off` proves a stale
  connection is evicted with `$shouldPrune=false` and zero DB DELETE. Only the shared-table DELETEs
  are gated.
- **#4 Throttle intact — CONFIRMED.** `$this->flushTick % $ticksPerMinute === 0` is unchanged; the
  `if ($shouldPrune)` nests inside it. The updated throttle test proves 11 flushes → 0 DELETE, the
  12th → 1 DELETE.
- **#5 No regression / scope — CONFIRMED.** `git diff --stat`: only the 4 source files + the one
  test + this worklog. `prune()` SQL and flush cadence unchanged; `$shouldPrune` default `false` is
  defensive; no migration touched; `Tunnel.php`/reaper/txn-locking untouched.
- **#6 Tests genuine — CONFIRMED.** All three new tests fail if the gate is removed/mis-wired
  (ungated prune → DELETEs on tick 12 → `does_not_prune` fails; gating eviction behind `$shouldPrune`
  → `stale-1` not evicted → `eviction` fails).

Read-only gates (this box, PHP 8.3.6 + PCOV):
- `phpstan analyze --no-progress` (4 changed files) → **[OK] No errors** (L9, no baseline).
- `phpunit --filter 'MetricsFlush'` → **OK (22 tests, 813 assertions)**.
- Full suite `phpunit --no-coverage` → **OK, 1363 tests / 16149 assertions / 17 skipped / 0 failures**
  (baseline 1360 + 3 new).
- `phpcs --standard=PSR12 -n` (4 changed src files) → **clean (exit 0)**.
- psalm skipped (box PHP 8.3.6 < psalm-required 8.3.16 — environmental, not red).

Verdict: **HB-4.5 DONE** (pending the standard Docs cycle).

## Orchestrator — HB-4.6–4.10 RE-AUDIT roll-up (2026-07-12, perf-4) — all claimed in commit 70e32e2
- **HB-4.6 [H-W5] rate limiting → PARTIAL.** Wired on proxy/JWKS/heartbeat + a DEAD `ClientMountController` HTTP stub. GAPS: (a) `:8802` `RelayWorker::onWebSocketConnect` unlimited; (b) the REAL client mount is `ClientRelayWorker::onWebSocketConnect`/`validateClientAuth` (:8803) — unlimited (the limited `ClientMountController` only returns 426/501); (c) only a worker-local in-memory `RateLimiter` (no shared store → AC "global limit" unmet); (d) one shared login-grade singleton 5/900 injected into proxy+heartbeat → HLS segment GETs / 60s heartbeats trip it (wired-but-WRONG, breaks normal ops); (e) NO `RateLimitException`→429 mapping (falls through to 500, no Retry-After); (f) heartbeat key = unauth caller serverId (flood/lockout); tests all stub `limited:false`. → Complete (big).
- **HB-4.7 [H-A1] key cache → PARTIAL.** In-mem previous-key cache + `@unlink` OFF the verify path both DONE. GAP: `purgeExpiredPreviousKey()` has ZERO production callers — never wired to a maintenance timer (AC's "move to a maintenance timer" unmet; stale sidecar lingers until next rotate). → Complete (small: wire the timer).
- **HB-4.8 [H-R5] stream-timer sweep → PARTIAL (⚠️ BEHAVIORAL REGRESSION).** Per-second del+add churn removed + single 2s sweep armed (good). BUG: sweep inactivity test uses FIXED `sent_at` (`now - sent_at >= timeout`, `RelayProxyManager.php:646`), never refreshed on frames → an actively streaming entry is killed `timeout`s after it STARTED: direct-play `/media/{id}/stream` (timeout=30s, excluded from STREAMING_TIMEOUT_PREFIXES) dies ~30s, /hls,/dash ~60s; MAX_STREAM_DURATION_SECONDS=900 unreachable. FIX: add `lastActivityAt` to the pending entry, refresh in `onResponseFrame` per KIND_HEAD/BODY, change sweep to `now - lastActivityAt >= timeout` while keeping `stream_opened_at + MAX_STREAM_DURATION` as absolute ceiling; add a test that an ACTIVE long stream SURVIVES. → Complete (HIGH priority).
- **HB-4.9 [H-I3] HTTP_CANCEL → PARTIAL.** Hub PROPAGATION wired (browser-gone → publish CANCEL → onCancel → `tunnel->sendCancel` HTTP_CANCEL frame). GAPS (hub-side, do now): (1) cancel-to-stop metric MISSING (`cancelRequest` only decrements pending gauge; no cancel counter in MetricsCollector); (2) shared-protocol doc MISSING (`phlix-shared/src/Relay/RelayFrameType.php` HTTP_CANCEL=0x12 undocumented in the class contract). SERVER-DEPENDENT (flag, NOT hub's): actually stopping ffmpeg = server SV-4.2/X1. → Complete (hub-half: metric + shared doc).
- **HB-4.10 [H-I5,R5] remove routeRequest → DONE (confirmed).** `routeRequest` removed (0 callers), docblock fixed, live path uses in-memory registry via `RelayProxyManager::onRequest`, `recordBytesIn` retained. No action.

Hub Complete queue (priority): HB-4.8 (regression) → HB-4.7 (small) → HB-4.9 hub-half (metric+shared doc; X1 server-stop deferred to SV-4.2) → HB-4.6 (large rate-limit rework).

## Implementer — HB-4.8 (regression fix + behavioral tests) — 2026-07-12

**Fixed the BEHAVIORAL REGRESSION** the RE-AUDIT flagged: the 2s periodic sweep (`sweepStreamTimers`)
measured inactivity from the FIXED `sent_at`, so an actively-streaming entry was killed `timeout`s
after it STARTED (direct-play `/media/{id}/stream` ~30s, `/hls`,`/dash` ~60s; the 900s
`MAX_STREAM_DURATION` ceiling was unreachable). Restored true inactivity semantics; kept the ceiling.

**Files changed (absolute):**
- `/home/sites/phlix/phlix-hub/src/Relay/RelayProxyManager.php`
  1. Added `lastActivityAt: float` to the pending-entry `@var` shape (`:141`).
  2. Seeded `'lastActivityAt' => $now` when the entry is created (`:293`) — equals `sent_at` until
     the first frame arrives, so a request that never gets a response still times out from send time.
  3. Refresh `lastActivityAt` in `onResponseFrame` on every decoded activity frame (HEAD/BODY/END),
     `:379` — `$this->pending[$requestId]['lastActivityAt'] = (float) time();` (coarse second
     granularity: per-FRAME, not per-byte; 30-60s timeouts swept every 2s need no finer clock; kept
     the shape `float` via the cast so PHPStan L9 stays clean). Added `use function time;`.
  4. Sweep inactivity test (`:669`) changed from `$now - $entry['sent_at']` to
     `$now - $entry['lastActivityAt'] >= $entry['timeout']` — terminate only when GENUINELY IDLE.
  5. KEPT the absolute ceiling unchanged (`:662`, `$now - stream_opened_at >= MAX_STREAM_DURATION_SECONDS`
     for streaming entries) — the sweep terminates on EITHER idle>timeout OR duration>900s. Did NOT
     touch `SWEEP_INTERVAL_SECONDS` (2.0) or the sweep arming. Updated two stale comments (`onTimeout`
     "timer re-armed each frame" → sweep-on-`lastActivityAt`).
- `/home/sites/phlix/phlix-hub/tests/Unit/Relay/RelayProxyManagerTest.php`
  - **NEW `test_active_long_running_stream_survives_sweep_despite_old_start_time`** (the regression
    test): stream `sent_at`/`stream_opened_at` back-dated 300s (>30s timeout, <900s ceiling) but fresh
    HEAD+BODY frames refresh `lastActivityAt` → the sweep must NOT terminate it; asserts entry still
    pending, no END, and `lastActivityAt > sent_at`. **Proven to FAIL against the fixed-`sent_at` code**
    via a temporary mutation (sweep terminated it → 3 publishes vs expected 2).
  - **NEW `test_idle_stream_beyond_timeout_is_terminated_by_sweep`**: HEAD then server silent;
    `lastActivityAt` back-dated 45s (>30s) with `stream_opened_at` recent (isolates the inactivity
    path) → sweep terminates with head+END, entry gone.
  - **NEW `test_absolute_ceiling_terminates_active_stream_with_recent_activity`**: `stream_opened_at`
    back-dated 1000s (>900s) but a fresh BODY keeps `lastActivityAt` recent → sweep terminates via the
    CEILING despite ongoing activity (head+body+END, entry gone).
  - Updated the existing `test_sweep_times_out_inactive_request` (a "no response ever" idle case) to
    back-date `lastActivityAt` too (it's seeded to `sent_at`; no frame refreshed it) — faithful to the
    new inactivity field. Existing `test_sweep_terminates_stream_exceeding_absolute_duration` /
    `test_streaming_frames_preserve_pending_entry_until_sweep_timeout` still pass unchanged.

**Acceptance mapping:** (1) `lastActivityAt` field added+seeded ✔; (2) refreshed per HEAD/BODY(/END)
in `onResponseFrame` cheaply ✔; (3) sweep tests `now - lastActivityAt >= timeout` ✔; (4) absolute
`stream_opened_at + 900s` ceiling retained — terminate on EITHER condition ✔; (5) no per-frame
`Timer::del/add` churn reintroduced, `SWEEP_INTERVAL_SECONDS`/arming untouched ✔.

**Verify (this box, PHP 8.3.6):**
- `./vendor/bin/phpstan analyze --no-progress` → **[OK] No errors** (L9, no baseline).
- `./vendor/bin/phpcs --standard=PSR12 -n src/` → **clean (exit 0)**.
- Full suite `php -d max_execution_time=0 ./vendor/bin/phpunit --no-coverage` → **OK, 1366 tests /
  16165 assertions / 17 skipped / 0 failures** (baseline 1363 + 3 new).
- `--filter RelayProxyManager` → **OK (52 tests, 230 assertions)**.
- Mutation check: reverting the sweep to `sent_at` makes the new regression test FAIL (proof it guards
  the bug); restored after.
- (psalm skipped — box PHP 8.3.6 < psalm-required 8.3.16, environmental.)

## Reviewer (per-step) — HB-4.8 — 2026-07-12

**NO FINDINGS.**

Reviewed `git diff ed6f945..1823d6f` (dd007bb fix + 1823d6f tests) against
`src/Relay/RelayProxyManager.php` + `tests/Unit/Relay/RelayProxyManagerTest.php`.
Read-only; no code touched.

Regression genuinely fixed (H-R5 / the fixed-`sent_at` behavioral regression):
- `lastActivityAt: float` added to the pending-entry shape (docblock :140), seeded at
  entry creation to `$now = microtime(true)` (== `sent_at`/`stream_opened_at`) at
  RelayProxyManager.php:293.
- Refreshed on EVERY decoded response frame (HEAD/BODY/END) in `onResponseFrame` at
  :379 via `(float) time()`, placed after the unknown-request and malformed-chunk
  guards (correct — only a valid decoded frame proves liveness).
- Sweep now measures idleness as `now - lastActivityAt >= timeout` (:669), not
  `now - sent_at`. Traced: an actively-streaming entry (frames arriving) keeps
  `lastActivityAt` recent → NOT terminated even when `now - sent_at` far exceeds
  `timeout`. Confirmed by the regression guard test.

Absolute ceiling preserved:
- `sweepStreamTimers` still terminates on `now - stream_opened_at >=
  MAX_STREAM_DURATION_SECONDS` (900.0, unchanged) at :657 as a separate check with
  `continue`, evaluated BEFORE the idle check — so EITHER condition terminates. A
  runaway/active-forever stream is still capped.

"No response ever" still times out:
- `lastActivityAt` is seeded to `sent_at` and never refreshed when no frame arrives, so
  an idle-from-start request times out from send time. The adjusted existing
  `test_sweep_times_out_inactive_request` back-dates BOTH `sent_at` and `lastActivityAt`
  to now-60s (faithful — they are equal in production when no frame ever arrives) and
  still asserts the 504 reply. Adjustment is correct, not masking a break.

Cheap refresh / no hot-path cost:
- Coarse `(float) time()` assignment, per-frame (frame handler), never per-byte. No new
  syscall-per-byte. Documented sub-second-precision tradeoff is sound against 30-60s
  timeouts + a 2s sweep. L9-clean (float cast; array-shape docblock declares
  `lastActivityAt: float`).

No churn reintroduced / scope:
- No `touchStreamTimer` / `STREAM_TIMER_REARM` / per-frame `Timer::del`/`Timer::add`
  residue (grep clean). Single sweep `Timer::add(SWEEP_INTERVAL_SECONDS=2.0, …, true)`
  at :182 unchanged. Only `src/Relay/RelayProxyManager.php` + the test + this worklog
  touched (`git diff --name-only`). `Tunnel.php` / reaper machinery / txn-locking
  untouched.

Tests genuine:
- `test_active_long_running_stream_survives_sweep_despite_old_start_time` is a true
  regression guard: it back-dates `sent_at`/`stream_opened_at`/`lastActivityAt` to
  now-300s, then delivers HEAD+BODY (refreshing `lastActivityAt` to ~now) and sweeps,
  asserting NO new publish. Against the pre-fix fixed-`sent_at` sweep (`300 >= 30` →
  true) `onTimeout` would publish a terminating `end` phase → `assertCount` fails.
  Mutation-real (verified by trace of the pre-fix predicate + `onTimeout` publishing an
  `end` for a `stream_started` entry). `test_idle_stream_beyond_timeout_is_terminated_by_sweep`
  isolates the inactivity path (backdated `lastActivityAt`, recent `stream_opened_at`);
  `test_absolute_ceiling_terminates_active_stream_with_recent_activity` isolates the
  ceiling (backdated `stream_opened_at`, recent `lastActivityAt`).

Gates (this box, PHP 8.3.6 + PCOV):
- `./vendor/bin/phpstan analyse --no-progress` → **[OK] No errors** (L9, no baseline).
- `phpunit --filter 'RelayProxyManager'` → **OK (52 tests, 230 assertions)**.
- `phpcs --standard=PSR12 -n src/Relay/RelayProxyManager.php` → clean (exit 0).
- psalm skipped (box PHP 8.3.6 < psalm-required — environmental, not red).

Verdict: **HB-4.8 DONE** (pending the standard Docs cycle).

## Implementer — HB-4.7 (wire the zero-caller key purge into the maintenance reap) — 2026-07-12

**Gap closed (the ONLY gap; H-A1):** `Ed25519KeyManager::purgeExpiredPreviousKey()` was correct but had
ZERO production callers — the in-memory previous-key cache (`$previousKey` false-sentinel, load-once) and
the OFF-verify-path `@unlink` (loadPreviousKey sets `previousKey=null` on expiry, does NOT unlink) were
already done, but nothing periodically purged the stale sidecar so it lingered until the next `rotate()`
overwrite. Wired the purge to the maintenance worker's periodic DB-maintenance reap.

**Wiring choice — inject into IdleReaper (mirrors HB-4.2/HB-4.3), NOT a new dedicated timer.** The
maintenance worker already runs the DB pruners via `IdleReaper::reapDbMaintenance()` on a REPEATING timer
armed by `IdleReaper::startDbMaintenance()` (called only from
`HubServicesProvider::startDbMaintenanceTimers()` → `MaintenanceWorker::onWorkerStart`). Adding the purge
there reuses the exact existing maintenance-reaper idiom and guarantees it runs on the MAINTENANCE worker
(not every worker, not the relay hot loop) and RECURS (repeating timer). The purge is a low-frequency
filesystem `@unlink` — acceptable on the maintenance worker, which is exactly where the AC wants it
("off the verify path, on a maintenance timer").

**Files changed (absolute):**
- `/home/sites/phlix/phlix-hub/src/Relay/IdleReaper.php`
  - New optional ctor param `?Ed25519KeyManager $keyManager = null` (last position, mirroring the
    `?HeartbeatHandler`/`?ClientRelayTokenService` optional deps) + import + PHPDoc.
  - `reapDbMaintenance()` now calls `$this->keyManager?->purgeExpiredPreviousKey();` after the
    heartbeat/token pruners; added an HB-4.7 bullet to the method docblock explaining why it lives on the
    DB-maintenance (maintenance-worker) path and not the verify path. `tick()` (the RELAY-worker in-memory
    path) is UNCHANGED — the purge only runs on the maintenance sweep.
- `/home/sites/phlix/phlix-hub/src/Common/Container/Providers/HubServicesProvider.php`
  - `IdleReaper::class` factory: added the `Ed25519KeyManager $keyManager` factory arg, passes it to the
    `new IdleReaper(...)` ctor, and `->parameter('keyManager', get(Ed25519KeyManager::class))`. DI stays
    coherent — `Ed25519KeyManager` is the same container singleton the JWKS/enrollment services resolve
    (hub is start.php-only; no `public/index.php` to mirror).
- `/home/sites/phlix/phlix-hub/tests/Unit/Relay/IdleReaperTest.php` — +2 tests:
  - `test_reap_db_maintenance_purges_expired_previous_key_when_key_manager_wired` — THE wiring guard:
    `Ed25519KeyManager` is `final` (unmockable), so it uses a REAL instance over a temp key path with an
    injectable clock — rotate() to persist the previous-key sidecar, advance past the 24h overlap, call
    `reapDbMaintenance()`, assert the expired sidecar FILE is unlinked. Removing the wiring leaves the file
    → assertion fails (guards the exact inert-wiring gap this step fixes).
  - `test_reap_db_maintenance_is_noop_for_key_manager_when_null` — null-safe path (mirrors the sibling
    null-noop tests). The existing `Ed25519KeyManagerTest` purge-BEHAVIOR test stays green.

**Did NOT touch** the verify path, the in-memory cache, or the unlink location (all already correct) — only
added the periodic caller.

**Verify (this box, PHP 8.3.6 + PCOV):**
- `./vendor/bin/phpstan analyse --no-progress` → **[OK] No errors** (L9, no baseline).
- `phpunit --filter 'IdleReaper|Ed25519KeyManager'` → **OK (26 tests, 78 assertions)**.
- Full suite `php -d max_execution_time=0 ./vendor/bin/phpunit` → **OK, 1368 tests / 16168 assertions /
  17 skipped / 0 failures** (baseline 1366 + 2 new).
- `phpcs --standard=PSR12 -n src/` → clean (exit 0); the two touched src files clean. (The IdleReaperTest
  snake_case method names are pre-existing repo convention for the whole file; tests/ is outside the phpcs
  `src/` gate.)
- psalm skipped (box PHP 8.3.6 < psalm-required 8.3.16 — environmental, not red).

**Acceptance mapping:** purge now has a production caller on a maintenance timer (AC "move to a maintenance
timer" met); runs on the MAINTENANCE worker and RECURS; verify path/cache/unlink-location unchanged.

Verdict: **HB-4.7 DONE** (pending the standard Docs cycle).

## Reviewer (per-step) — HB-4.7 — 2026-07-12

Reviewed `git diff 0d39b46..66a10ee` (c6fdf58 fix + 66a10ee tests) against current
`src/Relay/IdleReaper.php`, `src/Common/Container/Providers/HubServicesProvider.php`,
`src/Hub/Ed25519KeyManager.php` (confirmed untouched), and `tests/Unit/Relay/IdleReaperTest.php`.

**NO FINDINGS**

All five verification points confirmed:

1. **Wiring correct + on the right worker.** `reapDbMaintenance()` (`IdleReaper.php:272`) now calls
   `$this->keyManager?->purgeExpiredPreviousKey()`. `reapDbMaintenance` is armed ONLY via
   `startDbMaintenance()` (`:139`, a repeating `Timer::add($interval, [$this,'reapDbMaintenance'])` — no
   `false` 3rd arg, so it RECURS) → `HubServicesProvider::startDbMaintenanceTimers()` (`:955-966`) →
   `MaintenanceWorker::onWorkerStart()` (`MaintenanceWorker.php:66`). It is NOT on the relay worker's
   `tick()` (which only reaps stale tunnels + `flushAll()`), NOT on any hot/per-request path. The relay
   worker's `startInMemoryReapers()` arms only `start()`/`tick()`, so the purge never runs there.

2. **DI coherent.** The `IdleReaper` factory (`HubServicesProvider.php:388-418`) passes
   `->parameter('keyManager', get(Ed25519KeyManager::class))` — the same PHP-DI shared singleton the
   JWKS/enrollment services resolve (`:146,:163,:245`). The new ctor param is optional + last-position
   (`?Ed25519KeyManager $keyManager = null`, `IdleReaper.php:87`), mirroring the existing optional
   `?RelaySessionManager`/`?HeartbeatHandler`/`?ClientRelayTokenService` deps → BC preserved; the `?->`
   null-guard makes any key-manager-less construction a clean no-op. Cross-worker correctness holds:
   `purgeExpiredPreviousKey()` reads the sidecar FRESH from disk via `readPreviousKeyFile()` (does NOT
   rely on in-memory `$previousKey`), so the maintenance worker's own key-manager instance — which never
   rotated in-process — correctly reclaims the on-disk sidecar written by whichever worker performed the
   rotate, and only unlinks when `expiresAt <= now()` (never deletes a still-valid previous key).

3. **Nothing else changed.** `Ed25519KeyManager.php` is not in the changeset (diff touches only the 4
   files below). The verify path (`loadPreviousKey`/`getActivePublicKeys`), the in-memory `$previousKey`
   cache, and the `@unlink` in `deletePreviousKeyFile()` are all UNTOUCHED and still off the verify path
   (`loadPreviousKey` still does not unlink on expiry). Only the periodic caller was added.

4. **Test genuine — not tautological.** `test_reap_db_maintenance_purges_expired_previous_key_when_key_manager_wired`
   uses a REAL `Ed25519KeyManager` (it is `final`/unmockable) over a temp key path with an injectable
   clock: `getOrCreateKeyPair()` → `rotate()` (persists sidecar) → assert sidecar exists → advance the
   clock by `OVERLAP_TTL_SECONDS + 1` → `reapDbMaintenance()` → assert the sidecar file is unlinked.
   Deleting the `$this->keyManager?->purgeExpiredPreviousKey()` line leaves the file on disk and fails
   the `assertFileDoesNotExist`, so it genuinely guards the inert-wiring gap. The
   `test_reap_db_maintenance_is_noop_for_key_manager_when_null` no-op test holds.

5. **Scope / gates.** Diff touches only `performance_worklog_hub.md`,
   `src/Common/Container/Providers/HubServicesProvider.php`, `src/Relay/IdleReaper.php`,
   `tests/Unit/Relay/IdleReaperTest.php`. `src/Relay/Tunnel.php` and the txn-locking machinery are
   UNTOUCHED. Gates this box (PHP 8.3.6 + PCOV): phpstan L9 `[OK] No errors`; phpcs `--standard=PSR12 -n src/`
   clean; `phpunit --filter 'IdleReaper|Ed25519KeyManager'` OK (26/78); full suite OK 1368 tests / 17 skip /
   0 fail (baseline 1366 → 1368, the 2 new tests). psalm skipped (environmental).

Verdict: **HB-4.7 — NO FINDINGS** (ready for the Docs cycle).

## Implementer — 2026-07-12 — HB-4.9 (hub-half: cancel metric + shared doc)

Closed both audited hub-side gaps from the perf-4 RE-AUDIT roll-up (worklog ~line 2172).

**Gap 1 — cancel-to-stop metric (hub `6c0aa5f`, pushed to master):**
- `src/Stats/Metrics/MetricsRegistry.php` — added `relayCancels` counter field + `recordRelayCancel()`; wired into `drainRelayMetrics()` snapshot (new `cancels` key) + zero-reset. Follows the `relayReplyDrops` idiom exactly.
- `src/Stats/Metrics/MetricsCollector.php` — added `recordRelayCancel()` façade (no-op when disabled), mirroring `recordRelayReplyDrop()`.
- `src/Relay/RelayProxyManager.php` — `cancelRequest()` now calls `$this->metrics?->recordRelayCancel()` at the REAL cancel site (right after the pending-gauge decrement, before `sendCancel()`). This is the same place the pending gauge is decremented, as specified.
- `src/Stats/Metrics/MetricsFlushService.php` — `flushRelay()` persists the counter to the new `relay_cancels` column (accumulating `col = col + VALUES(col)`; non-prefixing `:rcancels` bind); idle-skip check + docblock/type updated.
- `migrations/039_relay_cancel_metric.sql` — `ALTER TABLE metrics_rollup ADD COLUMN relay_cancels INT NOT NULL DEFAULT 0` (plain DDL, MySQL-8 safe; idempotency via runner tracking table). Added to `MigrationFileTest` expected list.
- Tests: `RelayProxyManagerTest::test_cancel_records_the_cancel_metric` (increments once on a real cancel; stays 0 for an unknown-request cancel); extended `MetricsFlushServiceTest` persistence test to assert `rcancels`/`relay_cancels` SQL.

**Gap 2 — shared HTTP_CANCEL doc (phlix-shared `45edc01`, pushed to master):**
- `phlix-shared/src/Relay/RelayFrameType.php` — documented `HTTP_CANCEL = 0x12` in the class contract: Types-list line + a "## Request cancellation (0x12)" section (hub→server only; client abandoned the request → hub asks server to STOP in-flight work; server stop-work half = SV-4.2, out of scope; frame is advisory, no response). Also corrected `fromValue` param doc range to `0x01–0x12`. Documentation only — NO behavioral change (the enum case already existed).

**Out of scope (not touched):** server ffmpeg stop = SV-4.2 (X1, different repo); cancel PROPAGATION path (already wired/verified).

**Verification (all green):**
- Hub `phpunit`: OK 1375 tests, 16216 assertions (17 pre-existing skips — DB-integration).
- Hub `phpstan` (level 9, no baseline): No errors.
- Hub `phpcs` on changed src: 0 errors (1 pre-existing >120-char WARNING on an untouched docblock line).
- phlix-shared `phpunit`/`phpstan`/`phpcs` on RelayFrameType: green.
- psalm not run — box PHP 8.3.6 < required (environmental, not red).

**phlix-shared repin:** NOT required. Change is comment-only; hub already consumes `detain/phlix-shared ^0.20.0` from Packagist and has no behavioral dependency on the doc. The doc lands at phlix-shared HEAD and reaches consumers at the next natural release/tag; no version bump forced.

## Reviewer (per-step HB-4.9 hub-half) — 2026-07-12

NO FINDINGS

Verified: `relayCancels` mirrors the `relayReplyDrops` idiom exactly (field decl, `recordRelayCancel()`, `cancels` drain key + zero-reset in `drainRelayMetrics()`, façade no-op-when-disabled in `MetricsCollector`). Counter increments exactly once at the REAL cancel site in `cancelRequest()` (after pending-gauge decrement, before `sendCancel()`); the `$entry === null` early return + `onCancel`'s unknown/mismatch guards prevent phantom/double-count — `cancelRequest` has no caller other than `onCancel` (browser-gone → publish CANCEL → onCancel → cancelRequest → sendCancel). Migration 039 is plain `ADD COLUMN` (no MariaDB `IF NOT EXISTS`), header comment present, `AFTER relay_decode_buffer_bytes` (exists in mig 036), appended to `MigrationFileTest` — consistent with mig 036's ADD COLUMNs. Flush `:rcancels` bind is colon-free-compliant and accumulates (`relay_cancels = relay_cancels + VALUES(...)`) like the sibling counters; idle-skip includes cancels. Shared doc is comment-only, no behavioral change; `fromValue` range `0x01–0x12` correct (HTTP_CANCEL=0x12 is the max enum case). No scope bleed into SV-4.2 server stop-work or the already-wired propagation path.

Gates: hub phpunit (247 relevant tests OK), hub phpstan L9 clean on changed files, shared RelayFrameType tests green.

## Orchestrator — HB-4.9 hub-half DONE (2026-07-12, perf-5)
- [x] HB-4.9 hub-half — hub 6c0aa5f (relayCancels counter mirroring relayReplyDrops; incremented at real cancelRequest site; migration 039 relay_cancels col, MySQL-8 safe; colon-free :rcancels bind) + phlix-shared 45edc01 (HTTP_CANCEL=0x12 contract documented, comment-only, NO repin needed). REVIEW = NO FINDINGS. Hub suite 1375 green, phpstan L9 clean.
- Server-STOP half = SV-4.2 (server repo, IN PROGRESS). X1 closes once SV-4.2 lands + on-box cancel round-trip verified (owed).
- Docs cycle batched. Hub next: HB-4.6 (large rate-limit rework) — planning pass in progress.

## Orchestrator — HB-4.6 DECOMPOSITION (2026-07-12, perf-5, from Plan pass)
Shared-store decision: KEEP per-worker in-memory RateLimiter (NO DB limiter on proxy hot path). :8802 RelayWorker + :8803 ClientRelayWorker are count=1 ⇒ per-worker==global (the DoS surfaces). proxy/jwks/heartbeat run on HUB_WORKERS(2) ⇒ soft-global ≈2×, set per-worker threshold = target÷HUB_WORKERS + document (mirror HB-3.4). DB limiter sketched (mig 040 rate_limits table, LAST_INSERT_ID atomic) = documented FUTURE work only.
Per-surface policy (per-worker): login 5/900 (unchanged, own instance); proxy ~600/60s key proxy:{userId} (hit AFTER userId gate); heartbeat ~30/60s key heartbeat:{JWT-serverId}; jwks ~120/60s key jwks:{ip}; relay_connect ~10/60s key {ip} (WS close on limit); client_mount ~30/60s key {ip}(+serverId) (WS close).
Sub-steps (one active per repo, sequential on hub):
- [x] HB-4.6a FOUNDATION (15d6404e; RateLimitProfiles + 6 distinct limiters; suite 1377 green) — per-surface named limiter DI in CommonServicesProvider + `rate_limit` section in config/server.php (mirror `metrics` block); keep RateLimiterInterface→login as back-compat alias. BLOCKS all others.
- [x] HB-4.6b/c/d wired (f9ee4bd8; proxy:{userId} below 401 gate + 100-GET burst test; heartbeat below JWT-validate; jwks; suite 1381 green)
- [x] HB-4.6e :8802 relay-connect limiter NEW (RelayWorker::onWebSocketConnect; resolve from container in onWorkerStart; WS close 1013 on limit). DONE — see `## Implementer — HB-4.6e/f`.
- [x] HB-4.6f :8803 client-mount limiter NEW (ClientRelayWorker::onWebSocketConnect, EARLY/before auth; WS close 1013) + retired dead ClientMountController::handle limiter (clean removal: ctor param + factory injection dropped). DONE — see `## Implementer — HB-4.6e/f`.
- [x] HB-4.6g 429 mapping: central catch(RateLimitException) before Application.php:1724 + explicit catch in AuthController loginForm/loginJson + ServerController::heartbeat; add RateLimitException::retryAfterSeconds(); WS paths reject (1013) NOT 429. DONE — see `## Implementer — HB-4.6g`.
- [x] HB-4.6h test hardening folded into comprehensive review (NO surviving limited:false stubs)
LANDMINES: proxy limiter MUST be generous+userId-keyed or HLS breaks (test real multi-seg burst); local catches in AuthController/ServerController swallow 429→500 (need explicit catch before generic); WS≠HTTP (no 429 body, close 1013); keep RateLimiterInterface alias valid until all sites migrated or container fails to boot.

## Implementer — HB-4.6a — 2026-07-12
FOUNDATION landed (blocks HB-4.6b–h). All work committed+pushed to master.
- **NEW** `src/Common/RateLimit/RateLimitProfiles.php` — type-safe catalogue of the per-surface container ids + per-worker default `{max,window}`. Container ids chosen: `rate_limiter.login` (5/900), `rate_limiter.proxy` (600/60), `rate_limiter.heartbeat` (30/60), `rate_limiter.jwks` (120/60), `rate_limiter.relay_connect` (10/60), `rate_limiter.client_mount` (30/60). `defaults()` maps `id => {key, max, window}` (key = the config sub-key).
- **EDIT** `src/Common/Container/Providers/CommonServicesProvider.php` — now iterates `RateLimitProfiles::defaults()`, reads each surface's `{max,window}` (+ shared `cap`) from `$appConfig['rate_limit']`, and registers ONE distinct `RateLimiter($window,$max,$cap)` factory per surface (arrow-fn captures by value → distinct thresholds AND distinct instances). Back-compat: `RateLimiterInterface::class` AND `RateLimiter::class` both `get(RateLimitProfiles::LOGIN)` → the login instance, so the ~5 un-migrated factories (HubServicesProvider :178/:246/:302/:593, AuthServicesProvider :113) keep resolving and the container still boots. Removed the now-dead single-limiter `resolveRateLimitConfig()`; kept `intOr()`.
- **EDIT** `config/server.php` — new `rate_limit` block mirroring the `metrics` IIFE: `cap` + per-surface `{max,window}`, each env-overridable (`PHLIX_HUB_RATELIMIT_*`), defaults matching the policy table.
- **EDIT** `tests/Unit/Common/Container/Providers/CommonServicesProviderTest.php` — rewritten for per-surface DI: (1) each `rate_limiter.<surface>` resolves to a RateLimiter with the expected `(max,window)` (max via `peek()->limit`, window via `hit()->resetAt` range-check); (2) login is 5/900; (3) surfaces are DISTINCT instances (spl_object_id uniqueness); (4) a `rate_limit.proxy` override takes effect while others keep defaults; (5) legacy `RateLimiterInterface`/`RateLimiter` still resolve to the login profile.
Acceptance mapping: config section ✓ (server.php mirrors metrics), named per-surface instances ✓ (RateLimitProfiles + provider loop), back-compat alias ✓ (interface+concrete → login). Call sites intentionally UNTOUCHED (deferred to 4.6b–f).
Verification: `phpunit --filter CommonServicesProvider` 5/61 green; full suite **1377 pass / 17 skip / 0 fail**; `phpstan analyze` (L9) **0 errors** (full src); `phpcs` PSR12 clean on changed files. psalm = env-skip (PHP 8.3.6 < 8.3.16).

## Implementer — HB-4.6b/c/d — 2026-07-12
Wired the per-surface named limiters (from 4.6a) into the three HTTP surfaces. All committed+pushed to master.

**4.6b — Proxy (LANDMINE):**
- `src/Common/Container/Providers/HubServicesProvider.php` — proxy factory now injects `get(RateLimitProfiles::PROXY)` (600/60s) instead of the shared `RateLimiterInterface` login-grade singleton.
- `src/Http/Controllers/ServerProxyController.php` — MOVED the `hit()` from before the auth gate to AFTER the `$userId==''` 401 gate, and re-keyed it `proxy:{userId}`. Unauth floods now take the cheap 401 with NO limiter write (they never reach the hit). Key = literally `'proxy:' . $userId` (PHPStan proved the IP fallback is dead code because the 401 gate guarantees non-empty `$userId` — dropped the ternary, documented why in the comment). The hit stays synchronous/pre-stream, so a trip throws `RateLimitException` into the OUTER `Application.php` catch, not the streaming-producer catch. No 429 mapping added (that's 4.6g).

**4.6c — Heartbeat:**
- `HubServicesProvider.php` — HeartbeatHandler factory injects `get(RateLimitProfiles::HEARTBEAT)` (30/60s).
- `src/Hub/HeartbeatHandler.php` — MOVED the `hit()` to AFTER `validateEnrollmentJwt` + the `payload.server_id === serverId` check, so an unproven id can't mint a bucket. Key unchanged: `heartbeat:{serverId}` (now JWT-proven). Fixes the old 5/900 mis-injection that tripped the legit ~60s cadence.

**4.6d — JWKS:**
- `HubServicesProvider.php` — HubJwksController factory injects `get(RateLimitProfiles::JWKS)` (120/60s). `HubJwksController` source already keyed `jwks:{ip}` at the correct spot — no source change needed there.

NOT touched: `ClientMountController` factory (still `RateLimiterInterface` → login) — that's 4.6f; the `RateLimiterInterface`/`RateLimiter` back-compat alias — kept per constraints; no 429 mapping (4.6g); no WS surfaces (4.6e/f).

## Implementer — HB-4.6e/f — 2026-07-12
Wired the per-surface named limiters (from 4.6a) onto the two WebSocket surfaces — the H-H1 DoS
surfaces. WS handshakes reject via `$connection->close((string)1013, true)` (WS≠HTTP: no 429 body;
the 429 mapping is 4.6g/HTTP-only). No `RateLimitException` is thrown out of any WS hook. All
committed+pushed to master.

**4.6e — :8802 relay-connect (`src/Relay/RelayWorker.php`):**
- Added `RateLimiterInterface $relayConnectLimiter` field + `CLOSE_TRY_AGAIN_LATER = 1013` const.
- `onWorkerStart()` primes the field via a new guarded `resolveRelayConnectLimiter()` (resolves
  `RateLimitProfiles::RELAY_CONNECT` = 10/60s from the container — coroutine context, NOT the ctor).
- `onWebSocketConnect()` (was an empty no-op) now `hit('relay_connect:' . $connection->getRemoteIp())`
  BEFORE any HELLO processing; on `limited` → warning log + `close('1013', true)` + return. Lazy
  `??=` fallback resolve keeps it robust/testable (RateLimiter is pure in-memory, no I/O). Relay
  worker is `count=1` (Application.php:1825) → per-worker == true global limit.

**4.6f — :8803 client-mount (`src/Relay/ClientRelayWorker.php`):**
- Added `RateLimiterInterface $clientMountLimiter` field + `CLOSE_TRY_AGAIN_LATER = 1013` const.
- `onWorkerStart()` primes the field via a new guarded `resolveClientMountLimiter()` (resolves
  `RateLimitProfiles::CLIENT_MOUNT` = 30/60s), kept OUTSIDE the metrics try/catch so a
  metrics-disabled worker still gets the limiter.
- `onWebSocketConnect()` now hits `client_mount:{ip}` EARLY — right after `parseServerId`, BEFORE
  token extraction / `validateClientAuth` / bind; on `limited` → warning log + `close('1013', true)`
  + return (no auth, no mount). `count=1` default (ctor:137) → per-worker == global.

**Retired the dead stub limiter (CLEAN REMOVAL — grep proved it was used ONLY by the dead `hit()`):**
- `src/Http/Controllers/ClientMountController.php` — dropped the `RateLimiterInterface $rateLimiter`
  ctor param + the dead `hit('client_mount:'.$ip)`/`throw RateLimitException` block in `handle()`
  (a 426/501 stub that never mounts), and the now-unused `RateLimitException` +
  `RateLimiterInterface` imports. Controller class + routes LEFT IN PLACE (§0.1) — only the dead
  limiter usage was de-wired.
- `src/Common/Container/Providers/HubServicesProvider.php` (~:297) — `ClientMountController` factory
  no longer injects a limiter (single-arg ctor now). The `RateLimiterInterface` import stays (still
  used by the heartbeat/jwks/proxy factories); the `RateLimiterInterface`/`RateLimiter`→login
  back-compat alias in `CommonServicesProvider` stays valid (other factories still resolve it).

**Tests (real `RateLimiter` with an injected clock — no `limited:false` stub on these paths):**
- `tests/Unit/Relay/RelayWorkerTest.php` — `testRelayConnectRateLimitedAfterBurstThenReconnectPasses`
  (3/window: 2 connects from one IP bind/never-close, 3rd trips → `close('1013',true)`, then
  clock+61s → reconnect passes) + `testRelayConnectLimiterIsKeyedPerIp` (IP A trips at max=2 while
  quiet IP B's first connect still passes — proves per-IP buckets, not a global counter). Added a
  memory-stream `LoggerFactory` init to setUp (the limited path logs a warning) + `makeUpgradeRequest`
  + `buildContainerWithLimiter` helpers.
- `tests/Unit/Relay/ClientRelayWorkerTest.php` — `testClientMountRateLimitedAfterBurstThenReconnectPasses`
  (2 mounts bind, 3rd `close('1013',true)` + assert `clientConnections` did NOT grow, then post-window
  reconnect binds) + `testClientMountLimiterRunsBeforeAuth` (NO token in request → without the limiter
  it'd 4401; a tripped limiter closes 1013 AND `ServerInfoHandler::getOwnerAndStatus` is `never()`
  called — proves the limiter precedes auth). `buildContainer` gained a `?RateLimiterInterface
  $mountLimiter` param exposed under `RateLimitProfiles::CLIENT_MOUNT`; the controller build dropped
  its limiter arg.
- `tests/Unit/Http/Controllers/ClientMountControllerTest.php` — dropped the limiter stub + arg
  (single-arg ctor); the 400/426/501 contract tests unchanged and green.

Verification: `phpunit --filter 'RelayWorker|ClientRelayWorker|ClientMountController'` 51/51 green;
FULL suite **1385 pass / 17 skip / 0 fail** (baseline 1381 + 4 new); `phpstan analyze` (L9) **0 errors**;
`phpcs` PSR-12 `-n` clean on all changed src + test files. psalm = env-skip (PHP 8.3.6 < 8.3.16).

**Tests (no `limited:false` stubs left on these three surfaces):**
- `tests/Unit/Http/Controllers/ServerProxyControllerTest.php` — `controller()` helper now uses a REAL `RateLimiter(60,600)` (optional 4th param to inject a clocked one); the two reply-timeout inline mocks swapped to real limiters. ADDED `test_normal_hls_segment_burst_of_100_never_trips_proxy_limiter` (100 authed segment GETs, frozen clock/one window → all pass) and `test_proxy_limiter_trips_after_exceeding_600_in_window` (599 pass, 600th throws `RateLimitException` — note `limited` is `count>=max`).
- `tests/Unit/Hub/HeartbeatHandlerTest.php` — `createRateLimiter()` returns a real `RateLimiter(60,30)`; ADDED `testLegitimateHeartbeatCadenceNeverTrips` (20 heartbeats at a simulated +60s cadence through ONE handler → never trips).
- `tests/Unit/Hub/JwksControllerTest.php` — real `RateLimiter(60,120)`; ADDED `testJwksLimiterTripsAfterExceeding120PerIpInWindow` (119 pass, 120th trips, keyed by ip).

Verification: affected filters green; FULL suite **1381 pass / 17 skip / 0 fail**; `phpstan analyze` (L9) **0 errors**; `phpcs` PSR12 on changed src clean (one pre-existing 142-char WARNING on the untouched ServerProxyController factory return line). psalm = env-skip (PHP 8.3.6 < 8.3.16).

## Implementer — HB-4.6g — 2026-07-12
429 + `Retry-After` mapping for `RateLimitException` landed. Committed+pushed to master.

**Helper:**
- `src/Auth/RateLimitException.php` — added `retryAfterSeconds(?int $now = null): int`. Defaults `$now` to `time()`; delegates the `max(0, resetAt - now)` math to `RateLimitState::retryAfter()` (single-sourced formula) by constructing a throwaway state from the exception's own `resetAt`/`remaining`. Returns `int<0,max>`.

**Central HTTP catch (proxy/jwks + everything else):**
- `src/Application.php` — added `catch (RateLimitException $e)` IMMEDIATELY before the generic `catch (Throwable)` at the outer request catch (was ~1724). It sets `$status = 429` (so the metrics `finally` records 429) and sends `self::rateLimitResponse($e)->toWorkermanResponse()`.
- Added a testable `public static function rateLimitResponse(RateLimitException $e): Response` (next to `computeStaticCacheDecision`) building `status(429)` + `header('Retry-After', (string)$e->retryAfterSeconds())` + `json(['error'=>'Too Many Requests','code'=>'rate_limited'])`. Extracted so the proxy/jwks central-mapping path (controllers throw, don't map locally) gets direct unit coverage without booting Workerman.
- **Proxy reaches the OUTER catch, confirmed by reading both blocks:** `ServerProxyController::proxy()` calls `hit()` synchronously at the top (before any `stream()` producer is set); dispatch happens at `Application.php` `$response = $router->dispatch($hubRequest)` — the throw propagates from `dispatch()` BEFORE the `$response->streamProducer !== null` check, so it can never reach the streaming-producer `catch` (that one only wraps the `($response->streamProducer)($connection)` invocation and merely `close()`s the socket). It lands in the outer catch → 429. ✓

**Explicit local catches (the LANDMINE — these controllers swallow into generic paths first):**
- `src/Http/Controllers/AuthController.php` — added `catch (RateLimitException $e)` BEFORE the `InvalidArgumentException`/`Throwable` catches in both `loginForm` (was `Throwable`→500) and `loginJson` (was `InvalidArgumentException`→401), both returning a shared private `rateLimited()` 429 envelope (same shape as the central one).
- `src/Http/Controllers/ServerController.php` — added `catch (RateLimitException $e)` before the `\InvalidArgumentException` mapping in `heartbeat`, returning the same 429 envelope inline.
- `src/Hub/HeartbeatHandler.php` — added `@throws RateLimitException` to `handle()`'s docblock. PHPStan treats an explicit `@throws` list as authoritative, so without this the new `ServerController` catch tripped `catch.neverThrown` (L9). `AuthManager::login` already declared its `@throws RateLimitException`, so `AuthController` needed no such doc fix.
- WS paths (:8802/:8803) LEFT ALONE — they reject with close 1013 (no 429 body post-upgrade), per 4.6e/f.

**Tests:**
- `tests/Unit/Auth/RateLimitExceptionTest.php` (NEW) — `retryAfterSeconds()` with injected `now` (60, 1), floor-at-0 for a past/equal window, and default-to-`time()` positive.
- `tests/Unit/ApplicationRateLimitResponseTest.php` (NEW) — `Application::rateLimitResponse()` → 429 + positive `Retry-After` + `code:'rate_limited'` (the proxy/jwks central-mapping coverage) + never-negative floor.
- `tests/Unit/Http/Controllers/AuthControllerTest.php` — added `testLoginFormRateLimitedReturns429WithRetryAfter` + `testLoginJsonRateLimitedReturns429WithRetryAfter`: build a REAL `AuthManager` (mock `UserRepository` always-null → each attempt records a failure) + REAL `RateLimiter(900,2,1000)`; two bad-cred attempts (401), the 3rd trips → controller returns 429 + `Retry-After` + `code:'rate_limited'` (real limiter to a trip, not a mocked exception).
- `tests/Unit/Http/Controllers/ServerControllerTest.php` — added `testHeartbeatRateLimitedReturns429WithRetryAfter`: heartbeat handler mock throws a real `RateLimitException(resetAt: now+45)`; controller maps to 429 + bounded `Retry-After` + envelope.

Acceptance mapping: retryAfterSeconds helper ✓; central 429 catch before generic Throwable ✓ (proxy/jwks land here — confirmed by reading both catch blocks); explicit local catches in loginForm/loginJson/heartbeat ✓; WS untouched ✓; back-compat alias + limiter thresholds/keys untouched ✓.
Verification: `phpunit --filter 'RateLimitException|ApplicationRateLimitResponse|AuthControllerTest|ServerControllerTest'` 35/35 green; FULL suite **1393 pass / 17 skip / 0 fail** (baseline 1385 + 8 new); `phpstan analyze` (L9) **0 errors**; `phpcs -n` PSR-12 clean on all changed src + new test files. psalm = env-skip (PHP 8.3.6 < 8.3.16).

## Reviewer (cumulative HB-4.6 a–g) — 2026-07-12

NO FINDINGS

Reviewed the full rework across 15d6404e (4.6a), f9ee4bd8 (4.6b/c/d), 255a4a3 (4.6e/f), 48fec762 (4.6g) plus final file state. Gates: full `phpunit` 1393 pass / 17 skip / 0 fail; `phpstan analyze` (L9) 0 errors; `grep -rn "limited.*false" tests/ src/` finds ONLY comment references (no surviving `limited:false` stub on any surface).

Verified against the 8 acceptance/completeness criteria:
1. No login-grade singleton on non-login surfaces — proxy/heartbeat/jwks inject `get(RateLimitProfiles::{PROXY,HEARTBEAT,JWKS})`; :8802/:8803 resolve `RELAY_CONNECT`/`CLIENT_MOUNT`. Zero `get(RateLimiterInterface::class)` remain in src/. The back-compat `RateLimiterInterface`/`RateLimiter`→LOGIN alias still resolves (container boots; legacy test green) and is injected ONLY on the login surface (AuthManager autowires by type-hint → login 5/900), never on a reworked surface.
2. Proxy streaming-safety — `hit('proxy:'.$userId)` is BELOW the `$userId===''` 401 gate (unauth floods take the cheap 401, no bucket write); profile 600/60s; 100-segment burst test passes, 600th trips. Confirmed by reading Application.php: dispatch() at :1686 runs inside the outer try and throws BEFORE `$response->streamProducer` is set, so the trip lands in the OUTER `catch (RateLimitException)` at :1742 (→429), never the streaming-producer backstop at :1729.
3. Heartbeat — `hit('heartbeat:'.$serverId)` moved AFTER `validateEnrollmentJwt` + server-found check (JWT-proven id); 30/60s; 20-heartbeat @60s cadence test never trips; old 5/900 mis-injection gone.
4. WS DoS closure — RelayWorker::onWebSocketConnect and ClientRelayWorker::onWebSocketConnect both `hit()` BEFORE HELLO/auth and reject via `close((string)1013, true)` (matches the existing CLOSE_UNAUTHORIZED close signature); no `RateLimitException` thrown from any WS hook. Client-mount limiter runs before token-extract/validateClientAuth (proven by `testClientMountLimiterRunsBeforeAuth`). Limiters resolved in `onWorkerStart` (coroutine ctx) with a lazy `??=` container fallback, not the ctor. Both workers count=1 (RelayWorker ctor 3rd arg = 1 at Application.php:1853; ClientRelayWorker default `$count=1`) → per-worker == global.
5. Dead ClientMountController limiter cleanly removed (ctor param, factory injection, dead `hit()`/throw, and unused imports all dropped); controller class + routes preserved; factory now single-arg.
6. 429 mapping — central `catch (RateLimitException)` precedes the generic `catch (Throwable)`; explicit local catches added in AuthController::loginForm (was Throwable→500) and ::loginJson (was InvalidArgumentException→401) and ServerController::heartbeat (before mapError), each returning the 429 + `Retry-After` + `code:rate_limited` envelope; `RateLimitException::retryAfterSeconds()` delegates to `RateLimitState::retryAfter()` = `max(0, resetAt-now)`; `@throws RateLimitException` added to HeartbeatHandler::handle so PHPStan L9 accepts the new catch; WS documented as reject-not-429.
7. Test hardening (folds 4.6h) — real `RateLimiter` + injected clock on every reworked surface; behavioral tests present: proxy burst-survives + trips, heartbeat cadence-survives, JWKS per-IP trip, WS over-limit close-1013 + per-IP keying + before-auth, login 429+Retry-After (loginForm/loginJson), heartbeat 429, Application::rateLimitResponse + retryAfterSeconds floor-at-0.
8. Conventions — no new DB binds (colon-free N/A); no reaper/txn machinery touched (RelayWorker/ClientRelayWorker changes are additive limiter field + hook + resolve helper only); PHPStan L9 clean; per-worker soft-global caveat for the HUB_WORKERS surfaces documented in config/server.php and RateLimitProfiles docblock; no exit/die/blocking sleep introduced.

## Orchestrator — HB-4.6 DONE (2026-07-12, perf-5)
- [x] HB-4.6 rate-limit rework COMPLETE. a=15d6404e (per-surface limiter DI), b/c/d=f9ee4bd8 (proxy/heartbeat/jwks wiring, streaming-safe), e/f=255a4a3 (:8802/:8803 WS limits + dead ClientMountController limiter removed), g=48fec762 (429+Retry-After). Comprehensive REVIEW = NO FINDINGS. Suite 1393 green, phpstan L9 clean. Per-worker caveat documented (:8802/:8803 count=1 ⇒ global; proxy/jwks/heartbeat HUB_WORKERS soft-global ≈2×). New operator knobs: config/server.php `rate_limit` section (PHLIX_HUB_RATELIMIT_*). Docs cycle owed (batched sweep started).
- HUB W0–W4 code-complete for this pass. Remaining hub: DOCS sweep + on-box verifies (SV-0.5-class start.php items don't apply; hub on-box = live relay 429/WS-close behavior).

## Scribe — 2026-07-12

DOCS DEBT sweep for the notable shipped hub changes (HB-4.6 rate-limiting rework, HB-3.4
bandwidth/quotas + endpoints, HB-4.9 relay_cancels metric, HB-4.1 relay metrics, plus
HB-3.3/4.2/4.5/4.7/4.8). NO behavioral code changes — prose + docblocks only. phlix-docs IS
present in the working tree (`/home/sites/phlix/phlix-docs`). Version NOT bumped: repo convention
accumulates entries under `## [Unreleased]` and `Version::VERSION` (0.2.0) tracks the last
released heading — entries added to Unreleased, no tag.

Doc files changed (absolute):
- `/home/sites/phlix/phlix-hub/CHANGELOG.md` — Unreleased: Added entries for HB-4.6 (per-surface
  rate limiting + `PHLIX_HUB_RATELIMIT_*` + 429/`Retry-After` + WS 1013 + per-worker caveat),
  HB-3.4 (bandwidth quotas + concurrent-stream cap + `/me/bandwidth` & admin quota endpoints +
  migration 038), HB-4.1 (relay observability metrics), HB-4.9 (`relay_cancels` + migration 039 +
  HTTP_CANCEL 0x12 contract); Changed entries for HB-3.3, HB-4.2, HB-4.5, HB-4.7, HB-4.8.
- `/home/sites/phlix/phlix-hub/README.md` — new "Rate limiting" subsection under Configuration
  reference (env table + per-worker caveat); "Bandwidth & quotas" API subsection + admin quota
  endpoints; `relay_user_quotas` + `metrics_rollup` rows in the schema table.
- `/home/sites/phlix/phlix-docs/docs/hub-admin/relay-tuning.md` — replaced the 21-line stub with a
  full operator page: rate limiting (config + env + 429/WS-1013 + per-worker caveat), per-user
  bandwidth quotas + concurrent-stream cap + HTTP endpoints, and relay metrics (`metrics_rollup`
  columns incl. `relay_cancels`).

Verified (no edit needed): migrations `038_relay_user_quotas_concurrency.sql` and
`039_relay_cancel_metric.sql` already carry accurate header comments; `RateLimitProfiles.php` and
`config/server.php` `rate_limit` block already have complete/accurate docblocks. NOT covered
(honest gaps): server-side SV-4.2 stop-work half (different repo); a strict global rate/quota cap
(needs a shared store — documented as future work); live on-box verification of 429/WS-close/quota
behavior is owed.

## Orchestrator — HUB FULLY COMPLETE for this pass (2026-07-12, perf-5)
- Code: HB W0–W4 all done/confirmed. Docs: scribe 7767589 (hub CHANGELOG+README) + phlix-docs a9e08dd (relay-tuning page). Version NOT bumped (Unreleased convention; VERSION=0.2.0 tracks last release).
- ONLY remaining hub work = on-box verifies (live 429 / WS close-1013 / quota + concurrent-stream cap behavior on the box) — cannot run here.

## ON-BOX VERIFY (perf-5, 2026-07-13) — deploy hub 868fcd4→7767589, server 679a4ea5→3c73fe0a; both healthy, migrations clean
PASS: HB-4.9 relay_cancels metric live; >64KB bodied-relay round-trip OK.
CANNOT-VERIFY: WS close-1013 (no ws tooling on box; source wired CLOSE_TRY_AGAIN_LATER=1013).
🔴 CRITICAL (NEW live bug): authenticated hub API (/api/v1/me, /auth/me, /me/servers, ownership) returns INCONSISTENT per-request for the SAME valid token — cycles 401 auth.user_not_found / 404 user.not_found / 500 "ServerInfoHandler: row missing or null user_id" (ServerInfoHandler.php:194); only ~30-50% succeed. Correlates with which of 4 HUB_WORKERS serves it. Reproduced w/ minted + real-register tokens, new + admin(detain) accounts; user confirmed present in phlix_hub.users via SQL. Smells like [[project_workerman_mysql_coroutine_sharing]] resurfacing in ServerInfoHandler multi-param query. Breaks SPA /auth/me init ~half of loads. → URGENT FIX (in progress).
⚠️ HB-4.6 login 429 PARTIAL FAIL: 5/900 NOT reliably enforced — HUB_WORKERS=4, per-worker in-memory limiter ⇒ effective ~20/900 (first 429 at attempt ~9). RateLimitProfiles docblock wrongly implies login is count=1/global. Login is the one surface where per-worker weakening = real brute-force concern. → FOLLOW-UP: tighten login threshold ÷HUB_WORKERS OR shared-store for the login bucket.

## 🔴 HUB AUTH INCIDENT — investigation (2026-07-13, perf-5) — NO speculative fix pushed (agent correctly refused)
Symptom: /api/v1/me,/auth/me,/me/servers,ownership intermittently 401 auth.user_not_found / 404 / 500 "ServerInfoHandler: row missing or null user_id" (:194); ~30-50% ok; correlates w/ which of 4 HTTP workers.
Diagnosis: 500 signature = a users-table row bled into a servers-table query = cross-coroutine result contamination on one shared 'mysql' socket. BUT PhlixMySQLConnection defenses (per-conn coroutine mutex $queryLock, txn-scoped mutex, emulated+buffered prepares) are ALL PRESENT + unmodified (last touched only by header commit). Under this code HTTP-worker crossing shouldn't be reachable → something environmental defeats the guard.
PRIME SUSPECT: Swoole 6.2.1 on box (tree has 6.2.1; mutex/getCid validated on older Swoole; 6.x changed coroutine/hook semantics). If getCid()==-1 in HTTP worker OR Channel-mutex changed → guard universally bypassed → crossing. Runtime-bump regression, not app code (explains no-code-change onset). update_hub.sh runs composer install → could have bumped Swoole.
Confirmed landmine (but WRONG process for this symptom): RelaySessionManager::registerServer (:104-137) runs an explicit txn on shared 'mysql' (HubServicesProvider:276 get(Connection::class)) — the only explicit txn left on 'mysql' (others moved to 'txn' conn in #150/#151). Runs on RelayWorker(:8802,count=1), NOT HTTP workers. → FIX #1 (hardening, correct regardless): wire RelaySessionManager to ConnectionPool::getConnection('txn') + test.
Amplifier: AuthMiddleware negative-existence cache (HB-1.4 de33313) caches userExists=false 5s/worker → sticky 401 per worker.
DECISIVE NEXT (box diag, read-only): (1) git -C /opt/phlix-hub rev-parse HEAD + status (is it 7767589 unmodified?); (2) php --ri swoole version + composer.lock swoole pin; (3) reproduce 200/401/404/500 distribution; (4) cid+concurrency probe: does getCid() return ≥0 in hub's coroutine ctx, and does result-crossing reproduce through PhlixMySQLConnection under Swoole 6.2.1? → determines the real fix (don't-gate-mutex-on-getCid / per-coroutine conn lease / pin Swoole).

## 🔴 HUB AUTH INCIDENT — box diagnosis (2026-07-13) — Swoole-defeats-getCid hypothesis REFUTED
- Deployed 7767589 clean; Swoole 6.2.1 (installed ~3wk ago, NOT by today's deploy); PHP 8.5.4.
- getCid() CORRECT (−1 outside, unique cids in concurrent coroutines). PhlixMySQLConnection mutex HELD 0/20 crossings in a controlled 20-coroutine real-connection+ServerInfoHandler test.
- Live symptom REPRODUCES under real HTTP load, scales w/ concurrency: 40 concurrent → 17×200/12×500/11×401. 9× the 500 sig in .logs today post-restart.
- Verdict (ii): mutex present+functioning in minimal test but live crossing reproduces under real load; couldn't shrink to minimal DB repro.
- NEW LEADS (diagnostic): (a) instrument queryLockHolder/cid under real traffic; (b) audit whether /api/v1/me path (AuthMiddleware UserRepository probe, AuthManager::getCurrentUser, ServerInfoHandler::getServersForUser) touches the SEPARATE 'txn' connection instance concurrently with 'mysql' (AuthServicesProvider:127, HubServicesProvider:158/173).
- ★ ORCHESTRATOR PRIME SUSPECT: Http/RequestContext.php coroutine-local userId via support\Context — if NOT correctly coroutine-isolated under Workerman Events\Swoole + Swoole 6, request A's userId bleeds to request B → wrong/empty user → 401 user_not_found / ServerInfoHandler servers-query with crossed/empty user_id → the 500. DB-FREE testable (N concurrent coroutines set+yield+read own userId). → investigation+fix in progress.

## 🔴→✅ HUB AUTH INCIDENT FIXED + DEPLOYED (2026-07-13, perf-5) — commit ed8a5ad
ROOT CAUSE (confirmed live): all request coroutines share ONE workerman/mysql socket (1 PhlixMySQLConnection/worker DI singleton). Per-query mutex correctly SERIALISES but that's insufficient — MySQL-protocol response DESYNC "lag-by-one" on the shared socket: each fetchAll() returns the result set of whichever query ran immediately before it. /api/v1/me: getServersForUser gets the users row → ServerInfoHandler:194 500; userExists/getCurrentUser get wrong/empty → 401/404. RequestContext hypothesis REFUTED (getUserId never read = dead code).
FIX: ported phlix-server PooledMySQLConnection — each coroutine leases its OWN connection (transaction-affine, released via Coroutine::defer). config/database.php pool_enabled default ON (DB_POOL_ENABLED/DB_POOL_SIZE); disable→single mutex socket. Files: src/Common/Database/PooledMySQLConnection.php (new), ConnectionPool.php (pool selection), config/database.php, tests/Unit/Common/Database/PooledMySQLConnectionTest.php (new).
VERIFIED LIVE: 40-concurrent ~29/7/4 → 100% 200 (tested 4×40, 2×80); MySQL threads peaked 43 < 151. Gates: phpunit 1399 pass, phpstan L9 clean, phpcs clean (psalm env-skip). DEPLOYED via update_hub.sh → box at ed8a5ad, /health 200, diagnostics cleaned, box git-clean.
phlix-server: NO change (already ships PooledMySQLConnection pool-ON — already protected). sugarcraft: N/A.
REVIEW owed (resume). Follow-up: remove dead RequestContext (§6); reconsider HB-4.6 login limiter now a shared DB-backed limiter is feasible with the pool.

## Reviewer (per-step, hub PooledMySQLConnection ed8a5ad) — 2026-07-13
Scope: commit ed8a5ad (per-coroutine DB connection pool; already deployed). READ-ONLY.

Verdict: the fix is sound. The `PooledMySQLConnection` class body (from `final class` onward)
is a BYTE-IDENTICAL port of phlix-server's production-proven implementation (verified by diff),
and the only hub-specific new code is the `ConnectionPool` selection branch + the `config/database.php`
pool knobs, both correct. Confirmed:
- Per-coroutine lease + reliable release: lease keyed by `getCid()`; released via `Coroutine::defer`
  which fires on EVERY coroutine exit (incl. exception unwind). Dirty (open-txn) connections are
  rolled back before return to idle, so an interrupted coroutine can't poison the next lessee.
- Transaction affinity: first DB call leases; all later calls (incl. begin/commit/rollback) reuse
  `$this->leases[$cid]` → a txn is affine to ONE physical socket. Cannot span two leases.
- Cross-coroutine crossing genuinely closed: every hub DB access funnels through the delegated
  `query()` (159 sites) + `beginTrans/commitTrans/rollBackTrans` (+row/single/column/lastInsertId);
  NO code calls the fluent builder or any un-delegated Connection method on the pooled instance
  (the `->select/->update/->delete/->execute` hits are repository/router/PDO-internal, not the
  pooled socket — e.g. MetricsRepository::select is a private helper over `$db->query`), so the
  test's feared "un-constructed parent crash" path is not reachable.
- 'metrics'/'txn' isolation preserved and improved: those named connections become their OWN
  PooledMySQLConnection instances; the cid<0 timer/reaper paths use the dedicated single `cliConn`,
  request coroutines lease their own — strictly more isolated than before. RelaySessionManager's
  explicit txn on 'mysql' (RelayWorker count=1) is now lease-affine per coroutine → safe as-is; the
  worklog's proposed "wire to 'txn'" hardening is superseded and unnecessary.
- Pool bounds/config: `max(1, maxSize)` clamp; exhaustion BLOCKS on `idle->pop(10.0)` then THROWS
  (no deadlock); CLI path bounded-polls 10s then throws; `DB_POOL_ENABLED=0` cleanly restores the
  single mutex socket; default-ON parsing correct; headroom documented (live peaked 43 < 151).
- Conventions: no new colon/`:` binds introduced; `SELECT 1` liveness probe is SELECT-first and
  param-free; per-connection mutex machinery untouched; start.php-only bootstrap unaffected.

FINDINGS:

1. tests/Unit/Common/Database/PooledMySQLConnectionTest.php (whole file) — LOW/MEDIUM.
   Problem: the new unit test exercises only the non-coroutine (CLI) delegation path via mocks —
   query/row/single/column/txn delegation, single-CLI-connection reuse, type-compat, CLI close.
   The concurrency behavior that IS the fix — distinct lease per `cid`, `Coroutine::defer` release
   back to idle, pool-exhaustion timeout/throw, txn-rollback-on-dirty-release, dead-connection
   eviction — has ZERO unit coverage (the file self-documents this deferral to "a live restart").
   Impact: the exact regression this critical incident targets (cross-coroutine result crossing)
   could silently regress with the suite still green; the only proof so far is one manual on-box
   load run. The plan's twin S-W2 acceptance explicitly mandates "txn rolled back on release",
   "pool-exhaustion timeout", and "dead-connection accounting" tests. The box has Swoole 6.2.1, so
   `Swoole\Coroutine\run()` harness tests (guarded by `extension_loaded('swoole')`) are feasible.
   Suggested fix: add coroutine-harness tests asserting (a) two concurrent coroutines get distinct
   leased connections, (b) a lease is returned to idle and reused after the coroutine ends,
   (c) an exhausted pool throws within the timeout instead of hanging, (d) a coroutine that leaves
   a txn open is rolled back on release (next lessee is clean).

2. src/Common/Database/PooledMySQLConnection.php:~297 (acquire(), dead-idle branch) — LOW
   (pre-existing in the byte-identical server twin; noted for completeness, not a regression).
   Problem: when `isConnectionAlive($conn)` is false, the dead connection is dropped with only
   `$this->created--; return $this->acquire();` — `$conn->closeConnection()` is never called, so a
   not-fully-dead socket FD can linger until GC. Impact: minor/bounded FD churn on DB-side connection
   drops (idle timeout/failover). Suggested fix: `$conn->closeConnection();` before recursing. Since
   this matches the production-proven server implementation, fixing it upstream (server) too would
   keep the twins in sync.

## TestEngineer — hub PooledMySQLConnection ed8a5ad (2026-07-13, perf-5) — GREEN
BOTH review findings closed. Finding 1 (test gap): added 7 coroutine-harness tests in
tests/Unit/Common/Database/PooledMySQLConnectionTest.php driving the REAL Swoole 6.2.1 scheduler via
a child-process fixture (tests/Unit/Common/Database/Fixtures/pool_harness.php + socket-free double
tests/Support/RecordingConnection.php; same process-isolation rationale as the existing
transaction_lock_smoke): distinct per-cid lease (2 concurrent coroutines get DIFFERENT connections),
defer-release + reuse, defer-release even when the leasing body throws, pool-exhaustion block→handoff
(bound holds, no deadlock), pool-exhaustion bounded THROW (~10s idle->pop(10.0), @group slow, no hang),
dirty-txn rollback on release, dead-connection SELECT-1-probe eviction. Finding 2 (FD-churn, HUB COPY
ONLY): acquire() dead-idle branch now calls `$conn->closeConnection()` before discarding the evicted
dead connection — verified by the dead_evict test (asserts conn0_closes=1). phlix-server was NOT
touched (its identical twin edited by another agent).
VERIFY (actual): `phpunit --filter PooledMySQLConnection` → OK (13 tests, 38 assertions), 00:11.
Full Unit suite → OK 1406/0, 17188 assertions, 17 skipped (was 1399; +7). phpstan L9 (configured
src/ gate) → [OK] No errors; PooledMySQLConnection.php clean in isolation. phpcs PSR12 → 0 errors on
all changed files (src file clean; tests/fixtures also clean). psalm skipped (env: PHP < psalm req).
COVERAGE (PooledMySQLConnection.php): in-process pcov 41.6% (CLI/delegation only — concurrency runs
in child procs, invisible to parent instrumentation); union of in-process + child-harness pcov = 78.6%.
Remaining uncovered = the default real-MySQL factory closure (needs a live server), the defensive
CLI-only acquire() poll loop (unreachable when lease() short-circuits the CLI path), the pop-timeout
throw lines (covered by the exhaust_throw test's own child, not the union collector), and 2 trivial
edge branches. The new FD-fix line IS covered.
SERVER-TWIN FOLLOW-UP: phlix-server/src/Common/Database/PooledMySQLConnection.php has the identical
FD-churn line in acquire()'s dead-idle branch and needs the same one-line `$conn->closeConnection()`
fix (server follow-up; not done here per scope).

## Implementer — HB-4.6 login-limiter follow-up, sub-steps 1+2 of 6 — 2026-07-13
Scope: ONLY the FIRST TWO sub-steps of the "Option B: shared DB-backed login limiter" decomposition
(the on-box verify found the login 5/900 bucket is enforced PER-WORKER in-memory, so with HUB_WORKERS=4
the effective budget is ~20/900 — a real brute-force weakening on the one surface where it matters).
Sub-steps 3–6 are intentionally NOT done here (left for a follow-up agent — see below).

Root cause recap: the login limiter is enforced in `AuthManager::checkRateLimit/recordFailedAttempt/
clearRateLimit` (keyed `auth:login:<ip>`) on the HTTP workers (count = HUB_WORKERS), NOT on a count=1
surface. Only `relay_connect` (:8802 RelayWorker) and `client_mount` (:8803 ClientRelayWorker) are truly
count=1 == global. The prior docblocks wrongly grouped `login` with those count=1 surfaces.

**Sub-step 1 — corrected the misleading docblocks (factually accurate; does NOT overclaim a fix):**
- `src/Common/RateLimit/RateLimitProfiles.php` (class docblock) — split the per-worker paragraph so
  only `relay_connect`/`client_mount` are described as count=1==global; `login`/`proxy`/`heartbeat`/
  `jwks` are documented as INDEPENDENT per-worker counters across HUB_WORKERS. Added an explicit ⚠️
  note that `login` is NOT a global 5/900 bucket (real budget ~5×HUB_WORKERS/900, first 429 near
  attempt ~9), that migration 040 lays the DB-backed-store groundwork, and that until the `DbRateLimiter`
  is built + the `LOGIN` binding repointed, login stays per-worker in-memory.
- `config/server.php` (`rate_limit` section header comment + the inline `login` sub-key comment) — same
  correction: only relay_connect/client_mount are count=1; login/proxy/heartbeat/jwks are per-worker
  across HUB_WORKERS; added the login-weakening ⚠️ note + the migration-040 groundwork pointer.

**Sub-step 2 — added migration `040_login_rate_limit.sql` (+ registered in MigrationFileTest):**
- `migrations/040_login_rate_limit.sql` — NEW. `CREATE TABLE IF NOT EXISTS login_rate_limit`,
  ENGINE=InnoDB utf8mb4 utf8mb4_unicode_ci, hub `-- migration: 040_login_rate_limit` header, plain DDL
  (no MariaDB-only `IF [NOT] EXISTS` on columns/indexes — MySQL-8 target). Shape models phlix-server's
  `login_rate_limit` (migration 074: attempts / reset_at Unix-ts / created_at / index on reset_at for
  the TTL sweep) but is keyed by the hub `RateLimiterInterface` OPAQUE bucket key (`rate_key VARCHAR(191)`
  natural PK, values like `auth:login:<ip>`) instead of the server's bare `ip` column — because the hub's
  forthcoming `DbRateLimiter` implements the generic `peek/hit/reset` interface, not an IP-specific store.
  Natural-key table (no `id` column) → exempt from the CHAR(36) rule exactly like `relay_user_quotas`/the
  telemetry rollups (per MigrationFileTest's own comments). Header comment states plainly that nothing
  reads the table yet.
- `tests/Unit/Migrations/MigrationFileTest.php` — added `'040_login_rate_limit.sql'` to the
  `testExpectedMigrationsExist` allow-list (hub convention).

NOT done here (explicitly out of scope — OPEN for the follow-up agent):
  (3) build `DbRateLimiter implements RateLimiterInterface` (peek/hit/reset; NAMED `:param` binds only —
      hub rule; bind LIMIT as int; inject the pooled `Connection` via `'mysql'`, NOT the txn conn);
  (4) repoint ONLY the `RateLimitProfiles::LOGIN` binding to the DB limiter (leave the other 5 in-memory);
  (5) tests (`DbRateLimiterTest` + `CommonServicesProviderTest` update; existing AuthManager tests keep
      injecting a real in-memory limiter and stay green);
  (6) docs (CHANGELOG/README/phlix-docs).

Files changed (absolute):
- /home/sites/phlix/phlix-hub/src/Common/RateLimit/RateLimitProfiles.php
- /home/sites/phlix/phlix-hub/config/server.php
- /home/sites/phlix/phlix-hub/migrations/040_login_rate_limit.sql (NEW)
- /home/sites/phlix/phlix-hub/tests/Unit/Migrations/MigrationFileTest.php

Verify (this box, PHP 8.3.6 + PCOV):
- `phpunit --filter MigrationFileTest` → OK (158 tests, 332 assertions) — new migration registered + all
  static-shape checks (header, InnoDB/utf8mb4, CREATE TABLE IF NOT EXISTS, balanced parens, natural-key
  exemption) pass for 040.
- Full Unit suite → OK 1412/0 (17 skipped, 17201 assertions) — was 1406 at HEAD a203070; +6 = the new
  migration's per-file dataProvider tests.
- `phpstan analyze --no-progress` (L9, no baseline) → [OK] No errors.
- `phpcs --standard=PSR12 -n src/Common/RateLimit/RateLimitProfiles.php config/server.php
  tests/Unit/Migrations/MigrationFileTest.php` → clean (exit 0).
- psalm skipped (env: box PHP < psalm-required).

## Implementer — HB-4.6 login-limiter follow-up, sub-steps 3+4 of 6 — 2026-07-13
Scope: ONLY sub-steps 3 (new `DbRateLimiter`) and 4 (repoint the `RateLimitProfiles::LOGIN` binding).
Built on top of sub-1+2 (commit `e0568fd`: docblock fix + migration `040_login_rate_limit.sql`). Sub-steps
5 (dedicated test-hardening pass) and 6 (docs: CHANGELOG/README/phlix-docs) remain OPEN for a follow-up
agent — see NOT-done note below.

Root-cause recap (from the on-box verify): the login 5/900 bucket was enforced PER-WORKER in-memory
(`RateLimiter` instance bound at `rate_limiter.login`), so HUB_WORKERS=4 ⇒ effective ~20/900. Option B =
back the login bucket with a shared DB row per key so every HTTP worker shares one counter.

**Sub-step 3 — new `src/Common/RateLimit/DbRateLimiter.php` (`implements RateLimiterInterface`):**
- Backs the generic `hit()/peek()/reset()` contract against the `login_rate_limit` table (migration 040:
  `rate_key VARCHAR(191)` natural PK, `attempts INT UNSIGNED`, `reset_at INT UNSIGNED`, `created_at`).
- Semantics are IDENTICAL to the in-memory `RateLimiter` (verified by a behavioral fake-DB test that
  models the table): `hit()` = atomic `INSERT … ON DUPLICATE KEY UPDATE attempts=IF(reset_at<=now,1,
  attempts+1), reset_at=IF(reset_at<=now, now+window, reset_at)` (fresh/expired window restarts at 1 and
  renews reset_at; active window increments and KEEPS its reset_at) followed by a `SELECT` to return the
  authoritative post-write `RateLimitState` (`limited` once `attempts>=max`); `peek()` = read-only SELECT
  (NO writes on the login hot path — it runs on every attempt), absent/expired ⇒ empty unlimited state;
  `reset()` = `DELETE … WHERE rate_key`.
- Hub DB rules honored: NAMED `:param` placeholders only, keys COLON-FREE; every REPEATED placeholder in
  the upsert (`now`, `reset`) is bound under a DISTINCT key (`nowCheck`/`nowReset`, `freshReset`/
  `renewReset`) so emulated prepares stay unambiguous — no reused named params.
- Bounded sweep: `hit()` runs `DELETE … WHERE reset_at <= :threshold LIMIT :batch` (batch=100) to bound
  the table (one row per distinct key; expired keys that never return would otherwise accumulate),
  mirroring phlix-server's store. `:batch` and `:threshold` bound as NATIVE INTS — defense against the
  server's `SV-DI LIMIT-bind` 1064 incident (emulated prepares quote a string LIMIT → `LIMIT '100'`).
  `PhlixMySQLConnection::execute()` already binds ints as PARAM_INT (unquoted), so passing ints is the
  verified-correct type; a `testSweepBindsNumericParamsAsInt` regression guard asserts `is_int`.
- Injected the pooled `'mysql'` `Connection` (autowired `Connection::class` → `ConnectionPool::
  getConnection('mysql')` in `CoreServicesProvider`), NOT the dedicated `'txn'` connection — these are
  single-statement reads/writes, not a multi-statement transaction. Clock injectable (like `RateLimiter`)
  for deterministic tests. `windowSeconds`/`maxAttempts` clamp to ≥1.

**Sub-step 4 — repointed ONLY the LOGIN binding (`src/Common/Container/Providers/CommonServicesProvider.php`):**
- In the per-surface registration loop, `RateLimitProfiles::LOGIN` now resolves to
  `factory(fn(Connection $db) => new DbRateLimiter($db, $window, $max))`; the other five surfaces
  (proxy/heartbeat/jwks/relay_connect/client_mount) are UNCHANGED — still worker-local in-memory
  `RateLimiter`s. Login `{max,window}` still config-driven (`rate_limit.login`, default 5/900).
- The legacy `RateLimiterInterface::class => get(LOGIN)` alias is KEPT (so `AuthManager`'s autowired
  `RateLimiterInterface` now gets the DB-backed limiter). The concrete `RateLimiter::class => get(LOGIN)`
  alias was DROPPED: the login profile is a `DbRateLimiter`, so aliasing the concrete class to it would be
  a type lie; no call site injects the concrete class (all use the named profiles or the interface), and
  PHP-DI still autowires a default `RateLimiter` if the concrete is ever requested. Updated the class
  docblock to describe the login exception + the dropped alias.
- AuthManager, the 5 other surfaces, and their tests are untouched. AuthManager tests that inject a real
  in-memory limiter directly (bypassing DI) keep passing unchanged.

**Tests:**
- NEW `tests/Unit/Common/RateLimit/DbRateLimiterTest.php` (11 tests) — a behavioral fake `Connection`
  models the `login_rate_limit` table (upsert/select/sweep/delete parsed from SQL keywords) so the tests
  prove FULL hit/peek/reset/window-expiry/independent-key/limit-boundary behavior, NOT just query-string
  shape: fresh-window-at-1, increment-and-trip-within-window (reset_at doesn't slide), window-restart-
  after-expiry, peek-empty-when-no-record, peek-empty-for-expired-WITHOUT-writing (read-only hot path),
  reset-clears, upsert+bounded-sweep issued, sweep-binds-int (1064 regression guard), every-query-named-
  colon-free-params (hub rule), non-positive-thresholds-clamp. (Coroutine-socket safety lives in and is
  tested on `PhlixMySQLConnection`; repository-style classes here use mock-`Connection` unit tests, the
  established hub idiom.)
- UPDATED `tests/Unit/Common/Container/Providers/CommonServicesProviderTest.php` — the 5 in-memory
  surfaces still assert `RateLimiter` + thresholds; NEW `testLoginProfileResolvesToSharedDbRateLimiter`
  asserts LOGIN is a `DbRateLimiter` at 5/900; config-override + login-override + distinct-instance +
  legacy-interface-alias (interface → login DbRateLimiter; concrete `RateLimiter::class` autowires a
  fresh, unrelated in-memory instance) tests updated. `buildContainer` now registers a mock `Connection`
  so the DB-backed login profile resolves.

NOT done here (explicitly out of scope — OPEN for the follow-up agent):
  (5) dedicated test-hardening pass (e.g. an on-box / real-MySQL integration test that a second worker
      shares the same counter — the cross-worker guarantee is the whole point; unit tests can only prove
      per-instance semantics);
  (6) docs (CHANGELOG `Unreleased`, README/phlix-docs rate-limit section noting login is now shared
      DB-backed while the other five stay per-worker).

Files changed (absolute):
- /home/sites/phlix/phlix-hub/src/Common/RateLimit/DbRateLimiter.php (NEW)
- /home/sites/phlix/phlix-hub/src/Common/Container/Providers/CommonServicesProvider.php
- /home/sites/phlix/phlix-hub/tests/Unit/Common/RateLimit/DbRateLimiterTest.php (NEW)
- /home/sites/phlix/phlix-hub/tests/Unit/Common/Container/Providers/CommonServicesProviderTest.php

Verify (this box, PHP 8.3.6 + PCOV):
- `phpunit --filter 'DbRateLimiter|CommonServicesProvider'` → OK (17 tests, 147 assertions).
- Full Unit suite → OK 1424/0 (17 skipped, 17287 assertions) — was 1412 at HEAD e0568fd; +12 = 11 new
  DbRateLimiterTest + 1 net-new CommonServicesProviderTest.
- `phpstan analyze --no-progress` (L9, no baseline) on the 4 changed files → [OK] No errors.
- `phpcs --standard=PSR12` on the 4 changed files → clean (exit 0).
- psalm skipped (env: box PHP < psalm-required).

## Reviewer (per-step, HB-4.6 login-limiter sub-1-4, commits e0568fd+352e0e9) — 2026-07-13 (perf-7)
**NO FINDINGS.** Reviewed as security-sensitive auth brute-force code. Verified (not just trusted the
worklog): every DbRateLimiter query uses named `:param` colon-free placeholders with DISTINCT keys for
repeated values (nowCheck/nowReset, freshReset/renewReset); the LIMIT-as-string 1064 class of bug
(server's real incident) is genuinely avoided — traced the actual bind path (PhlixMySQLConnection
pdoParamType() int→PARAM_INT unquoted) and confirmed the regression test captures REAL production params
(would go red on a `(string)` cast, not a mock that passes regardless); the atomic
INSERT...ON DUPLICATE KEY UPDATE hit/check logic is genuinely race-free under InnoDB row-lock (traced the
column-assignment ORDER: attempts assigned first reading OLD reset_at, THEN reset_at reassigned — correct
restart-vs-increment semantics, a swap would break it); peek() is read-only (can't be abused to reset);
reset() only fires after successful login; injected connection is the pooled 'mysql' conn, not 'txn';
repoint scope confirmed LOGIN-only (grepped for the dropped concrete RateLimiter::class alias — zero
consumers); AuthManager tests inject a real limiter directly, unaffected. Suite 1424/0 reconfirmed,
phpstan/phpcs clean. **Sub-1-4 CODE-COMPLETE + review-clean.**
Non-blocking note for sub-5: the column-assignment ordering correctness above was verified by READING the
SQL, not by a real-MySQL test — the current behavioral fake models correct semantics regardless of actual
column order, so it would NOT catch an accidental column swap. Sub-5 (real-MySQL/cross-worker concurrency
test) should close this gap. Remaining: sub-5 (dedicated test hardening) → sub-6 (docs).

## TestEngineer — HB-4.6 login-limiter sub-5 — 2026-07-13 (perf-7) — GREEN
Scope: sub-step 5 of 6 — dedicated real-MySQL test hardening for `DbRateLimiter`, closing the Reviewer's
one non-blocking sub-5 note above (the ODKU column-assignment ordering was verified only by READING the
SQL; the behavioural fake models correct semantics regardless of the ACTUAL SQL, so it cannot catch a
column swap). Sub-6 (docs: CHANGELOG/README/phlix-docs) is the last remaining piece.

**Added (both NEW, absolute paths):**
- `/home/sites/phlix/phlix-hub/tests/Integration/Common/RateLimit/DbRateLimiterIntegrationTest.php` —
  8 tests, `@group integration`, exercising the ACTUAL `hit()/peek()/reset()` SQL against a REAL MySQL
  row. Uses the established `HUB_TEST_DB_*` env-var convention + graceful `markTestSkipped` when unset
  (mirrors `MigrationRunnerIntegrationTest`), so it self-skips in CI / any DB-less env. The real
  `login_rate_limit` table is (re)created fresh at setUp() from the ACTUAL migration-040 DDL (reads
  `migrations/040_login_rate_limit.sql`, strips `--` comments) so it also surfaces schema drift; dropped
  in tearDown(). Tests:
  - `testFreshKeyStartsAttemptsAtOne` — first hit on a fresh key persists attempts=1 (not 0/phantom).
  - `testRepeatedHitsIncrementWithinWindow` — hits increment the same row; reset_at anchored to the FIRST
    window (no slide); `limited` trips at max.
  - `testHitAfterWindowExpiryRestartsAtOne` — **THE column-ORDER guard.** Builds a stale window
    (attempts=2), forces `reset_at` into the past, hits again → asserts attempts RESTARTS at 1 (not
    incremented) and reset_at renewed. This is exactly the semantics that breaks on a SET-clause swap.
  - `testPeekDoesNotMutateLiveRow` / `testPeekReportsEmptyForExpiredWindowWithoutWriting` — peek() is
    read-only on the hot path; a live counter is unchanged by repeated peeks; an expired-window peek
    reports empty AND leaves the stale row physically present (no DELETE/upsert).
  - `testResetDeletesRow` — reset() removes the bucket row.
  - `testHitSweepsExpiredRowsButKeepsLiveRow` — the bounded in-`hit()` sweep reclaims OTHER keys' expired
    rows while never touching the freshly-written live row.
  - `testConcurrentHitsDoNotLoseIncrements` (`@group slow`) — genuine concurrent-writer proof (see below).
- `/home/sites/phlix/phlix-hub/tests/Integration/Common/RateLimit/Fixtures/concurrent_hit_worker.php` —
  isolated child-process writer (one MySQL session per process; same child-process idiom as
  `Fixtures/pool_harness.php`, which avoids the nested-Swoole-coroutine-in-PHPUnit segfault). Opens its
  OWN `Connection` + `DbRateLimiter`, busy-wait barrier on a shared start epoch (pure microtime spin, NO
  blocking sleep), fires N hits at one shared key.

**Real concurrent-writer coverage: ACHIEVED (not just sequential).** `testConcurrentHitsDoNotLoseIncrements`
launches 6 concurrent OS processes (via `proc_open`, each a distinct MySQL session) that each fire 25
`hit()`s at the SAME fresh key under a large window, then asserts the final persisted `attempts` == 150
(writers × iterations). Zero lost increments → proves the atomic `INSERT … ON DUPLICATE KEY UPDATE
attempts = … attempts + 1` is genuinely serialized by the InnoDB row-lock under real contention — the
shared-store race the in-memory `RateLimiter` cannot even have (its per-worker counters never contend).
This goes beyond the mandatory sequential minimum. (Note on rigor: correctness — no lost updates — holds
regardless of overlap degree because the upsert is a single atomic statement; the barrier maximizes
actual temporal overlap so the row-lock is genuinely exercised, not just theoretically.)

**Deliberate-swap-goes-RED verification (empirical, per the brief): CONFIRMED.**
1. Baseline against real MySQL 8.0.46: all 8 integration tests GREEN (`OK, 8 tests, 56 assertions`).
2. Temporarily SWAPPED the two SET clauses in the REAL source
   (`src/Common/RateLimit/DbRateLimiter.php`: `reset_at = …` moved BEFORE `attempts = …`) and re-ran:
   `testHitAfterWindowExpiryRestartsAtOne` went **RED** — `Failed asserting that 3 is identical to 1`
   (the expired window INCREMENTED the stale 2→3 instead of restarting at 1, because MySQL evaluates ODKU
   assignments left-to-right and the swapped `attempts` clause then reads the already-renewed future
   `reset_at`). Independently reproduced the same 1-vs-3 divergence with raw SQL against the cluster.
3. Re-ran the behavioural UNIT test (`DbRateLimiterTest`) WITH the swapped source: STILL GREEN (11/11) —
   empirically confirming the Reviewer's point that the fake cannot catch the swap; only this real-MySQL
   test does.
4. RESTORED the source (`git diff src/Common/RateLimit/DbRateLimiter.php` → EMPTY) and re-ran the
   integration suite → GREEN again (8/8). Source is unchanged from HEAD 352e0e9; ONLY tests were added.

**No general-hardening gaps left open** beyond the above (sweep-behavior, peek read-only, reset delete all
now covered against real MySQL).

VERIFY (actual output, this box, PHP 8.3.6):
- Integration suite vs real MySQL 8.0.46 (env `HUB_TEST_DB_HOST=127.0.0.1 PORT=6446` via the local
  mysqlrouter RW endpoint → InnoDB cluster, dedicated ad-hoc DB created+dropped, replication_user full
  grants): `OK (8 tests, 56 assertions)`, ~2.4s.
- Full hub suite, NO DB env (CI-equivalent — integration self-skips): `Tests: 1432, Assertions: 17287,
  Skipped: 25`, 0 failures (was 1424 Unit at HEAD; +8 new integration tests, all skipped without DB env).
  No regression.
- `phpstan analyze --no-progress` (L9, no baseline, default `src` gate) → `[OK] No errors`; explicit L9 on
  both new test files → `[OK] No errors` (fixed 2 `mixed`→int casts via an `is_numeric` coercion helper).
- `phpcs --standard=PSR12 -n` on both new files → clean (exit 0).
- psalm skipped (env: box PHP < psalm-required).

CI note: CI has no MySQL service, so `DbRateLimiterIntegrationTest` (like `MigrationRunnerIntegrationTest`)
self-skips there — the real-DB run above is the authoritative verification. To re-run: create a MySQL DB,
then `HUB_TEST_DB_HOST/PORT/USER/PASSWORD/NAME=… php -d max_execution_time=0 ./vendor/bin/phpunit
--testsuite Integration --filter DbRateLimiterIntegrationTest`.

Remaining for HB-4.6: sub-6 (docs) is the last piece.

## Scribe — HB-4.6 login-limiter sub-6 — 2026-07-13 (perf-7)
Sub-step 6 of 6 — DOCS for the "Option B" shared DB-backed login limiter (sub-1..5). NO behavioral
code changes: prose (CHANGELOG/README/phlix-docs) + docblock-only corrections. Extended/corrected the
prior 2026-07-12 rate-limiting docs sweep (did NOT write a duplicate section). Version NOT bumped
(repo convention: accumulate under `## [Unreleased]`; `Version::VERSION` tracks the last release tag).

What was documented: the `login` profile is now the ONE DB-backed/shared limiter — its
`RateLimitProfiles::LOGIN` binding is repointed to the new `DbRateLimiter` backed by the
`login_rate_limit` table (migration `040_login_rate_limit`), so all HTTP workers share one counter
per key and the 5/900 login budget is ACTUALLY 5/900 (was ~5×HUB_WORKERS/900 ≈ 20/900 with 4 workers,
first 429 near attempt ~9). The other five surfaces (proxy/heartbeat/jwks/relay_connect/client_mount)
remain per-worker in-memory — documented as an accepted soft-global tradeoff, not a bug.

Files changed (absolute):
- `/home/sites/phlix/phlix-hub/CHANGELOG.md` — new `## [Unreleased]` › Added entry for HB-4.6
  "Option B" (login now shared/DB-backed via DbRateLimiter + `login_rate_limit` table + migration 040;
  the other five stay per-worker soft-global; no threshold change).
- `/home/sites/phlix/phlix-hub/README.md` — "Rate limiting" subsection: intro + per-worker caveat now
  state `login` is the genuinely-global exception; the other five remain per-worker soft-global. Added
  a `login_rate_limit` row to the Database schema table.
- `/home/sites/phlix/phlix-hub/src/Common/RateLimit/RateLimitProfiles.php` — DOCBLOCK ONLY: corrected
  the class docblock (stale "login stays per-worker in-memory until DbRateLimiter lands" — it has
  landed) + the `LOGIN` const comment (was "unchanged" → now notes shared DB-backed store).
- `/home/sites/phlix/phlix-hub/config/server.php` — COMMENT ONLY: corrected the `rate_limit` header +
  inline `login` comment (same stale "per-worker in-memory today" language, now DB-backed/global).
- `/home/sites/phlix/phlix-docs/docs/hub-admin/relay-tuning.md` — "Rate limiting" section: intro +
  "Per-worker vs global" now document the `login_rate_limit` table as the one DB-backed profile and
  the operational implication (5/900 is now genuinely 5/900, not ~20/900). Committed+pushed separately.

Verify: full hub suite `phpunit` → OK 1432 tests / 0 failures / 25 skipped (unchanged baseline;
docblock-only). phpstan L9 + phpcs PSR-12 clean on the touched `src` file. psalm env-skipped.

**This closes HB-4.6 login-limiter ENTIRELY (sub-1 through sub-6 all done).**

## Implementer — HB-4.11 — 2026-07-13

Ported phlix-server's SV-4.9 checksum-divergence detection into hub's `MigrationRunner`,
adapted to hub conventions (named `:param` / colon-free bind keys, MySQL-8-safe plain
DDL, and a backfill-safe retrofit onto the pre-existing `migrations` table).

**Files changed (absolute):**
- `/home/sites/phlix/phlix-hub/migrations/041_migrations_checksum.sql` (NEW) — plain
  `ALTER TABLE migrations ADD COLUMN checksum CHAR(32) NULL` (no `IF NOT EXISTS` on the
  column — MySQL-8 rejects it per AGENTS.md). Nullable because hub's `migrations` table
  already holds ~40 real applied rows with no checksum. Header `-- migration:
  041_migrations_checksum`; ALTER-only (exempt from the CREATE-TABLE/CHAR(36) file checks).
- `/home/sites/phlix/phlix-hub/src/Common/Database/MigrationRunner.php` —
  - `run()` rewritten to a checksum-aware loop: reads a `filename => checksum|null`
    ledger via new `loadLedger()`; **recorded + match → skip without executing**;
    **recorded + diverged → `warnDivergence()` + re-apply + refresh checksum**;
    **recorded + NULL checksum → `backfillChecksum()` WITHOUT re-executing**;
    **un-recorded → apply + record with checksum**. Uses `array_key_exists()` (NOT
    `isset()`) so a NULL-checksum row is not mis-read as un-recorded.
  - `checksum()` (NEW, `public static`) — comment-normalized md5 ported verbatim from
    server (drops full-line `--`/`#` comments + per-line trailing whitespace before
    hashing, so doc-only edits don't spuriously re-trigger; any real SQL token change
    still diverges). Made public purely so tests can compute expected checksums.
  - `recordApplied($filename, ?$checksum)` — now `INSERT ... (filename, checksum)
    ... ON DUPLICATE KEY UPDATE checksum = VALUES(checksum)` with **named `:filename` /
    `:checksum` (colon-free array keys)** per the hub `bindMore()` rule; falls back to a
    name-only insert when the checksum column is absent (pre-041).
  - `loadLedger()` (NEW, private) — `SELECT filename, checksum`; on `Unknown column
    'checksum'` degrades to a name-only `SELECT filename` so pre-041 recorded files are
    still recognised (mapped to null → backfilled), never mass-re-applied; any other read
    failure degrades to an empty map.
  - `backfillChecksum()` (NEW, private) — `UPDATE ... SET checksum WHERE filename`,
    failure-tolerant (retries next run if the column is not present yet).
  - `warnDivergence()` (NEW, private) — emits the WARNING via `error_log()` (no logger
    dependency added: migrations run one-shot from the CLI, which does not initialise
    `LoggerFactory`; `error_log()` surfaces on stderr / the configured log). Matches the
    class's existing minimal-dependency style.
  - `isMissingChecksumColumnError()` (NEW, private static) — matches "unknown column" +
    "checksum" to drive the pre-041 name-only degrade paths.
  - `listApplied()` (name-only, public) and `ensureTrackingTable()` LEFT UNCHANGED —
    `ensureTrackingTable()` deliberately does NOT add the checksum column, so migration
    041 is the sole column-adder and its plain ADD COLUMN never collides (no dup-column
    abort on fresh or existing DBs).
- Tests:
  - `/home/sites/phlix/phlix-hub/tests/Common/Database/MigrationRunnerTest.php` — 8 new
    tests + 2 existing "already applied" tests updated to seed matching checksums (query
    counts preserved) + `testRunRecordsAppliedFilename` now also asserts the recorded
    checksum. New: comment-normalized-md5 semantics; clean-apply records checksum;
    match-skips-without-executing; **divergence warns + re-applies + refreshes** (captures
    the `error_log` WARNING via `ini_set('error_log', ...)`); **NULL-checksum backfill does
    NOT re-execute the migration** (asserts the CREATE statement is never issued while the
    checksum IS backfilled); pre-041 name-only read (no mass re-apply); recordApplied
    name-only fallback when the column is missing.
  - `/home/sites/phlix/phlix-hub/tests/Integration/Migrations/MigrationRunnerIntegrationTest.php`
    — 3 new real-DB methods (clean-apply populates checksum; diverged checksum re-applies +
    refreshes; NULL checksum backfills without re-apply). Self-skip without `HUB_TEST_DB_*`.
  - `/home/sites/phlix/phlix-hub/tests/Unit/Migrations/MigrationFileTest.php` — added
    `041_migrations_checksum.sql` to the expected-files list.

**Backfill-safety verification (the critical requirement):**
`testRunBackfillsNullChecksumWithoutReapplying` seeds a ledger row `{filename: 001_a.sql,
checksum: null}` (the exact day-one post-migration state) and asserts `run()` returns `[]`
(NOT re-applied), that **no `CREATE TABLE foo` statement is ever executed** (captured all
executed SQL and filtered — count 0), AND that a `UPDATE migrations SET checksum` fires with
the current on-disk checksum. This proves the row is backfilled without re-running its
statements. `testRunDoesNotMassReapplyWhenChecksumColumnMissing` further proves the pre-041
column-absent read degrades to name-only and does not re-apply already-recorded files.
Conversely `testRunWarnsAndReappliesAndRefreshesOnDivergedChecksum` proves an ALREADY-non-NULL
checksum that no longer matches DOES trigger the WARNING + re-apply + refresh.

**Acceptance-criteria mapping:**
- "editing an already-applied migration → WARNING + re-apply + refreshed checksum" →
  `run()` divergence branch + `testRunWarnsAndReappliesAndRefreshesOnDivergedChecksum` +
  integration `testDivergedChecksumTriggersReapplyAndRefresh`.
- "an untouched already-applied file is still skipped without executing" →
  `testRunSkipsRecordedFileWithMatchingChecksumWithoutExecuting` +
  `testRunReturnsEmptyArrayWhenEverythingAlreadyApplied`.
- "day-one NULL-checksum backfill does not force a mass re-apply" →
  `testRunBackfillsNullChecksumWithoutReapplying` +
  `testRunDoesNotMassReapplyWhenChecksumColumnMissing` + integration
  `testNullChecksumBackfillsWithoutReapplying`.

**Verification output:**
- `phpunit --filter 'MigrationRunner|MigrationFileTest'` → 192 tests OK, 8 skipped (DB-gated).
- Full Unit suite → **1420 tests / 0 failures**. Full default suite → **1448 / 0 failures /
  28 skipped** (integration self-skips; +8 new unit + 3 new integration vs baseline).
- `phpstan analyze --no-progress` (L9, no baseline) → **0 errors**.
- `phpcs --standard=PSR12` on all changed files → **clean**.
- `php -l` on the runner → no syntax errors.
- psalm NOT run: pre-existing environment gate (`Psalm requires PHP >= 8.3.16`, env has
  8.3.6) — unrelated to this change (fails before analysing any code).
