# Changelog

All notable changes to `detain/phlix-hub` are documented here.

This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Web UI
- **Legacy Smarty UI retired — the `/app` Vue SPA is the ONLY hub web UI; `smarty/smarty` removed.**
  Every legacy server-rendered (Smarty) route now issues a **302 redirect** to its `/app` equivalent
  (registered in `src/Application.php`): `/` → `/app/servers`; `/login` → `/app/login`; `/signup` →
  `/app/signup`; `/my-servers` → `/app/servers`; `/servers/{id}` → `/app/servers/{id}`; `/invite-links`
  → `/app/invite-links`; `/settings` → `/app/admin/settings`; `/audit-logs` → `/app/admin/audit-logs`;
  `/logs` → `/app/admin/logs`; `/federation` → `/app/federation`; `/federation/shares` →
  `/app/federation/shares`; `/requests` → `/app/requests`; `/admin/requests` → `/app/admin/requests`; and
  the public `GET /invite/{token}` → `/app/invite/{token}` acceptance page. Deleted: `PageRenderer`,
  `PageController`, `CsrfMiddleware`, every `public/templates/**/*.tpl`, and the legacy `public/assets/js/*.js`
  + `public/assets/css/app.css`. The `smarty/smarty` composer dependency was **removed entirely** — the hub
  has no newsletter (or any other) email, so nothing on the hub renders Smarty anymore. This supersedes the
  earlier "Smarty retirement pending owner verification" note.
- **WS-D — owner/user surfaces migrated to the `/app` Vue SPA.**
  The hub's owner/user surfaces — **My Servers**, **Server Detail**, **Federation**, **Federation Shares**,
  **Shares**, **Shared With Me**, **Invite Links**, and **Requests** (plus the admin request-approval queue) —
  are served by the shared `@phlix/ui` Vue SPA at `/app/*`, alongside the existing admin console at
  `/app/admin/*`. Route + nav registration lives in `web-ui/src/main.ts`.
- **WS-D — public invite acceptance route `/app/invite/:token` (fixes the previously-broken `/invite/{token}` 404).**
  The public `GET /invite/{token}` link now redirects to the SPA `AcceptInvitePage` (`meta.public`, so an
  unauthenticated invitee reaches it without the auth guard). Not logged in → Log In / Sign Up buttons that
  carry a safe `?redirect=/app/invite/<token>` hop back here; logged in → an **Accept Invite** button calling
  `POST /api/v1/me/invite-links/{token}/redeem`, then a **View Shared Libraries** link to `/app/shared-with-me`.
  This restores parity with the retired Smarty `accept-invite` flow, whose PHP redirect had no matching Vue route.
- **WS-D — safe post-auth redirect.** `LoginForm`/`SignupForm` now honour a validated internal `?redirect`
  query (via the shared `@phlix/ui` `safeRedirect` guard, which only accepts same-origin `/app/` paths), so
  logging in / signing up from the invite page returns the user to accept the invite. Vitest specs cover all
  of the above pages.

### Tests
- **HB-0.3 HEAD-over-relay anti-stall hardening** (`tests/Unit/Relay/RelayProxyManagerTest.php`,
  `tests/Unit/Http/Controllers/ServerProxyControllerTest.php`). Added coverage proving a HEAD probe
  over the relay completes PROMPTLY. A server `withFile()` HEAD emits a head frame (carrying
  Content-Length) followed by a zero-body END with no body frames; the buffered path now has explicit
  tests that it assembles and returns on END — carrying status + Content-Length + range support with
  an empty body — rather than stalling on a body frame that never arrives (which would surface as a
  504). Also added a HEAD-then-ranged-GET ordering test (HEAD returns headers/Content-Length, the
  ranged GET returns the requested bytes). The earlier HEAD test supplied a pre-assembled buffered
  reply and so did not exercise the head+END-only assembly; these do. No production logic changed.

### Docs
- **HB-0.3**: fixed docblock rot on `ServerProxyController::isStreamingPath()` — it now states that
  only **GET** streams and HEAD is deliberately routed through the buffered path (it previously said
  "GET/HEAD").
- **HB-0.1**: documented the reap-window/ping-interval coupling on
  `IdleReaper::DEFAULT_STALE_THRESHOLD_SECONDS` — the stale window (default 90s) MUST stay strictly
  greater than the server's relay ping interval (`PHLIX_RELAY_PING_INTERVAL`, default 30s) or a
  healthy-but-quiet tunnel is false-reaped. The server sends a HEARTBEAT every ping interval and
  echoes hub pings, and only inbound frames refresh `lastFrameAt`, so the margin keeps live tunnels
  alive.

### Added
- **Tunnel data-plane backpressure (HB-1.2)** (`src/Relay/Tunnel.php`, `src/Relay/ClientConnection.php`).
  When `send()` returns `false` because the socket send buffer is full, the tunnel now applies
  real backpressure: it pauses reads on the opposite connection (`pauseRecv()`) and resumes them
  when the `onBufferDrain` event fires. A 30-second timeout closes the tunnel if buffer drain
  never arrives. No data frames are silently dropped — the tunnel treats frame loss as a fatal
  protocol error rather than recoverable overload.
- **Invite links: `POST /api/v1/me/invite-links/{token}/redeem` — accept an invite link and create a library share**
  (`src/Http/Controllers/InviteLinkController.php`, `src/Application.php`). An authenticated user can
  redeem a valid, unexpired invite link token and receive a library share grant for the invited
  library and permission level. Double-redeem (exhausted or expired token) is rejected with 410.
  The endpoint is auth-gated via `AuthMiddleware` and delegates to the existing
  `InviteLinkHandler::redeemInviteLink()` handler.
- **Per-surface rate limiting on the hub's abuse-prone entry points (HB-4.6)**
  (`src/Common/RateLimit/RateLimitProfiles.php` (new),
  `src/Common/Container/Providers/CommonServicesProvider.php`, `config/server.php`,
  `src/Http/Controllers/ServerProxyController.php`, `src/Hub/HeartbeatHandler.php`,
  `src/Http/Controllers/HubJwksController.php`, `src/Relay/RelayWorker.php`,
  `src/Relay/ClientRelayWorker.php`, `src/Application.php`, `src/Auth/RateLimitException.php`).
  Each surface now gets its OWN in-memory `RateLimiter` instance (a single login-grade
  5/900 limiter was wrong for everything but login). A new `rate_limit` section in
  `config/server.php` sets each surface's `{max, window}` plus a shared key-count `cap`,
  all env-overridable via `PHLIX_HUB_RATELIMIT_*`. Per-worker defaults:
  - `login` — **5 / 900s**, keyed by identity (unchanged).
  - `proxy` — **600 / 60s**, keyed `proxy:{userId}` and hit only AFTER the 401 auth gate
    (generous so normal HLS/DASH segment bursts never trip; unauth floods take the cheap
    401 with no bucket write).
  - `heartbeat` — **30 / 60s**, keyed `heartbeat:{serverId}` after the enrollment JWT is
    validated (so an unproven id can't mint a bucket).
  - `jwks` — **120 / 60s**, keyed `jwks:{ip}`.
  - `relay_connect` — **10 / 60s**, keyed `{ip}` on the :8802 server relay-connect handshake.
  - `client_mount` — **30 / 60s**, keyed `{ip}` on the :8803 client-mount handshake (before auth).
  HTTP surfaces that trip return **429 Too Many Requests** with a `Retry-After` header and
  `{"error":"Too Many Requests","code":"rate_limited"}`; the two WebSocket handshakes
  (:8802/:8803) instead reject the connection with close code **1013** (Try Again Later),
  since a post-upgrade socket has no HTTP status/body. **Per-worker caveat:** the :8802 and
  :8803 relay workers are `count=1`, so per-worker == global there; `proxy`/`heartbeat`/`jwks`
  run across `HUB_WORKERS` HTTP workers, so their effective soft-global limit is roughly
  `max × HUB_WORKERS` (a strict global cap would need a shared store — documented future work).
- **Login rate limit is now shared & DB-backed (HB-4.6 "Option B")**
  (`src/Common/RateLimit/DbRateLimiter.php` (new),
  `src/Common/Container/Providers/CommonServicesProvider.php`,
  migration `040_login_rate_limit.sql` + `login_rate_limit` table). An on-box check found the
  `login` 5/900 bucket was enforced PER-WORKER in-memory like every other surface, so with
  `HUB_WORKERS=4` the real brute-force budget was ~`5 × HUB_WORKERS` / 900 (≈20/900, the first 429
  landing around attempt ~9 instead of ~5) — a genuine weakening on the one surface where it
  matters. A new `DbRateLimiter` (a shared, cross-worker `RateLimiterInterface` backed by the
  `login_rate_limit` table — one row per opaque bucket key, atomic
  `INSERT … ON DUPLICATE KEY UPDATE` increment, read-only `peek()` on the hot path, and a bounded
  TTL sweep) now backs **only** the `login` profile: its `RateLimitProfiles::LOGIN` binding is
  repointed to it, so ALL HTTP workers share one counter per key and the 5/900 login budget is
  genuinely global (actually 5/900). The other five surfaces
  (proxy/heartbeat/jwks/relay_connect/client_mount) intentionally stay on the worker-local
  in-memory `RateLimiter` — a documented, accepted soft-global tradeoff (`max × HUB_WORKERS`), not
  a bug. Requires migration `040_login_rate_limit`. No threshold changed (still 5/900, still
  env-overridable via `PHLIX_HUB_RATELIMIT_LOGIN_MAX` / `_LOGIN_WINDOW`).
- **Per-user relay bandwidth accounting + quotas + concurrent-stream cap (HB-3.4)**
  (`src/Hub/RelaySessionManager.php`, `src/Http/ConnectionResponseSink.php`,
  `src/Http/Controllers/ServerProxyController.php`,
  `src/Http/Controllers/UserQuotaController.php` (new),
  `src/Common/Container/Providers/HubServicesProvider.php`, `src/Application.php`,
  migration `038_relay_user_quotas_concurrency.sql`). The relay proxy now meters the REAL
  streamed bytes off the on-the-wire counter in `ConnectionResponseSink` (recorded in a
  `finally` on every stream exit — completion, browser-gone, or mid-stream error), and
  `checkUserQuota()` enforces BOTH the download (`quota_bytes_in`) and upload
  (`quota_bytes_out`) caps (the download cap was previously never checked). A new
  operator-configurable per-user concurrent-stream cap (`relay_user_quotas.max_concurrent_streams`,
  migration 038; `0` = unlimited) is enforced at proxy admission — over the cap returns
  **503** `stream.limit` and never occupies a slot; slots are released in the producer
  `finally` (leak-free). New HTTP endpoints expose the controls:
  - `GET /api/v1/me/bandwidth` — a user reads their own current-period usage + caps (auth only).
  - `GET /api/v1/admin/users/{id}/bandwidth` — admin reads any user's usage + caps.
  - `PUT /api/v1/admin/users/{id}/quota` — admin sets a user's download/upload byte caps +
    concurrent-stream cap (validated: non-negative ints, byte caps ≤ 1 PiB, streams ≤ 1000,
    `0` = unlimited; audited via `AuditLogger::logAdminAction('user.quota.set', …)`).
  These are hub-local admin/self endpoints (behind `AuthMiddleware` / `AdminMiddleware`), NOT
  on the relay-proxy allowlist. **Caveat:** the concurrent-stream counter is in-memory
  per-HTTP-worker, so the cap is enforced per worker (soft-global ≈ `max × HUB_WORKERS`); a
  strict global cap needs a shared store (future work).
- **Relay observability metrics (HB-4.1)**
  (`src/Stats/Metrics/MetricsCollector.php`, `src/Stats/Metrics/MetricsRegistry.php`,
  `src/Stats/Metrics/MetricsFlushService.php`, `src/Relay/RelayProxyManager.php`,
  `src/Common/Container/Providers/HubServicesProvider.php`). The shared per-worker
  `MetricsCollector` is now injected into `RelayProxyManager` (it was previously never
  wired, so all relay metrics were inert). `MetricsFlushService` drains and UPSERTs them
  into the migration-036 `metrics_rollup` columns: `relay_pending_requests` (gauge),
  `relay_reply_drops`, `relay_error_503`/`relay_error_504` (counters — the `no_tunnel`
  fast-fail now also counts a 503), `relay_decode_buffer_bytes` (gauge), and the
  `relay_latency_h_le_10..h_gt_5000` histogram. Latency records a first-byte (TTFB)
  observation on the first response frame and a total (send→END) observation at completion,
  for BOTH buffered and streaming responses.
- **`relay_cancels` metric — count of HTTP_CANCEL frames the hub emits to a server (HB-4.9)**
  (`src/Stats/Metrics/MetricsRegistry.php`, `src/Stats/Metrics/MetricsCollector.php`,
  `src/Relay/RelayProxyManager.php`, `src/Stats/Metrics/MetricsFlushService.php`,
  migration `039_relay_cancel_metric.sql`). When a client abandons an in-flight proxied
  request the hub sends the server an `HTTP_CANCEL` (0x12) frame to stop in-flight work;
  each such cancel is now counted at the real `cancelRequest()` site and persisted to a new
  `metrics_rollup.relay_cancels` column (migration 039). The `HTTP_CANCEL = 0x12` wire
  contract is documented in `phlix-shared`'s `RelayFrameType` (hub→server only, advisory,
  no response); the server-side stop-work half is tracked as SV-4.2 in `phlix-server`.

### Changed
- **WS-D: D-HUB SPA migration — phase 2 (WS-D)** (`src/Application.php`). Six Smarty-rendered
  routes (`/requests`, `/admin/requests`, `/invite-links`, `/servers/{id}`, `/federation/shares`,
  `/invite/{token}`) now redirect to the Vue SPA (`/app/…`) instead of rendering their
  Smarty templates. Only `/shared-with-me` was migrated in phase 1. The original Smarty pages
  remain on disk as a deprecated fallback; they will be removed in a future release.
- **Migration ledger: detect & safely re-apply an edited already-applied migration (checksum divergence, HB-4.11)**
  (`src/Common/Database/MigrationRunner.php`, migration `041_migrations_checksum.sql`). The
  `MigrationRunner` ledger was **name-only**: once a `migrations/*.sql` file was recorded as applied,
  the runner skipped it forever on filename alone — so hand-editing an already-applied migration file
  (a "rewrite-class" migration) was silently invisible and never re-ran. The runner now also tracks a
  **comment-normalized md5 checksum** of each file's SQL. A new `checksum CHAR(32) NULL` column is added
  to the existing `migrations` tracking table (migration `041_migrations_checksum.sql`, a plain
  MySQL-8-safe `ALTER TABLE … ADD COLUMN` — no MariaDB-only `IF NOT EXISTS`). Per-file behaviour on
  `php bin/phlix migrate` / `scripts/run-migrations.php`:
  - **recorded + checksum matches** → skipped without executing (unchanged behaviour);
  - **recorded + checksum diverged** (the file's SQL was edited since it was applied) → logs a
    **WARNING** via `error_log()` (visible in the deploy output / CLI stderr), **re-applies** the file,
    and **refreshes** the stored checksum;
  - **un-recorded** → applied and recorded with its checksum.
  The checksum strips full-line `--`/`#` comments and per-line trailing whitespace before hashing, so
  header/doc-only edits do **not** spuriously trigger a replay — only a real SQL-token change diverges.
  **Backfill safety:** the ~40 pre-existing migration rows land with `checksum IS NULL` the moment the
  column is added. A NULL recorded checksum means "recorded, no baseline yet" — it is **backfilled** from
  the current on-disk file's checksum **without re-executing** the migration, and is **never** treated as
  diverged, so day-one there is **no mass re-apply** of the existing ledger. Only a subsequent edit against
  that now-populated baseline triggers a real divergence re-apply. Ported from phlix-server's SV-4.9 twin,
  adapted to hub conventions: named `:param` (colon-free) bind keys per the `bindMore()` rule, and
  column-absent (pre-041) reads/writes degrade gracefully to name-only.
- **Relay proxy: dropped base64 encoding from the internal channel-broker body path (HB-1.1)**
  (`src/Relay/RelayProxyManager.php`, `src/Relay/RelayProxyBridge.php`,
  `src/Http/Controllers/ServerProxyController.php`). The relay's internal hub-to-hub
  channel broker now passes raw binary body fragments directly instead of base64-encoding
  them. This removes the 33% byte inflation and two CPU encoding/decoding passes per
  fragment on every relayed response, reducing loopback bandwidth and CPU overhead on
   the hub worker.
- **Relay proxy: `onReply()` now uses non-blocking delivery, preventing Head-Of-Line
  blocking on slow consumers (HB-1.3)**
  (`src/Relay/RelayProxyBridge.php`).
  `onReply()` is the **single shared subscriber** for every in-flight request on this
  HTTP worker, so its push must never block. The delivery strategy is now two-phase:
  first it attempts a non-blocking push (timeout 0) directly into the consumer's
  channel; if the channel is temporarily full (consumer draining slowly) and a
  coroutine context is available, it spawns a dedicated fiber to perform the bounded
  push — the stuck consumer blocks only its own fiber, not the shared subscriber. When
  no coroutine context exists (e.g. a `pcntl` signal handler), the reply is dropped
  immediately to avoid blocking. Previously a single slow consumer could add up to
  45 seconds of latency to every unrelated concurrent request on the same worker.
- **Hot-path queries: lean `getOwnerAndStatus()` replaces heavier `getServerInfo()` + `findById()` on relay and auth paths (HB-1.4)**
  (`src/Hub/ServerInfoHandler.php`, `src/Http/Middleware/AuthMiddleware.php`,
  `src/Auth/UserRepository.php`, `src/Relay/ClientRelayWorker.php`,
  `src/Http/Controllers/ServerProxyController.php`). The relay and auth hot paths
  no longer load a full `ServerInfo` with all columns or a full `User` record when
  only ownership and online status are needed:
  - `ServerInfoHandler::getOwnerAndStatus(serverId)` queries only `id`, `user_id`,
    and `status` plus an `EXISTS(SELECT 1 FROM relay_sessions …)` check — no
    `COUNT(*)` subquery, no joining the `user` table.
  - `AuthMiddleware` replaced its `UserRepository::findById()` call with a new
    `userExists(userId)` that only checks `SELECT 1 FROM users WHERE id = ?` with
    a 5-second TTL cache — the user record itself is never needed on these paths.
  - `getServerInfo()` is retained for the dashboard and server-detail views where
    the full object is genuinely required.
  This reduces per-request DB payload on every heartbeat, client-mount, and
  proxied request where ownership is checked.
- **web-ui: bumped `@phlix/ui` pin from `v0.73.1` to `v0.74.0`** (F2) and rebuilt the
  committed `public/assets/app/**` bundle. Brings the stream-quality/ABR player UI
  (Track E: hls.js level API, `QualityMenu`, Auto/pinned-rung selection, visual + a11y
  baselines) to the hub's inline browse-via-relay SPA. No hub-side code change; content
  hashes in the rebuilt bundle churn on every rebuild (Vite/Rollup non-determinism,
  not a regression) — verified via smoke-import against the installed `@phlix/ui`
  package instead of raw diffing (`createPhlixApp`/`Player`/`QualityMenu` resolve to
  real exports, `package.json` `main`/`module`/`types` targets all exist).
- **Relay proxy: large HLS/DASH/direct-play response bodies now stream through the hub
  instead of being fully buffered first (D3, streaming half — closes out Track D
  alongside D1/D2)** (`src/Http/Controllers/ServerProxyController.php`,
  `src/Http/Response.php`, `src/Application.php`, `src/Relay/RelayProxyBridge.php`,
  `src/Relay/RelayProxyManager.php`; **new** `src/Relay/RelayResponseSink.php`,
  `src/Http/ConnectionResponseSink.php`). Previously the browse-proxy buffered every
  response body at **two** hops: the relay worker reassembled every `HTTP_RESPONSE`
  BODY frame into one blob before publishing it on `END`, and the HTTP worker then
  base64-decoded that whole blob before forwarding it to the browser. For a multi-MB
  HLS/DASH segment (× concurrent viewers, all funnelled through the single relay
  worker) that was a real resident-memory spike, and it defeated S3's origin-side
  streaming work (`phlix-server` #431, `TranscodeFileServer::serveJobFile()` via
  Workerman `withFile()`). The proxy now reuses the **existing** chunked
  `RelayHttpResponseCodec` HEAD/BODY/END frame protocol end-to-end so nothing buffers a
  whole body anywhere:
  - `ServerProxyController` classifies GET/HEAD under `/hls`, `/dash`, `/media` (a
    **path-based** heuristic — the hub can't know a body's size before the first frame
    arrives, and these are exactly the byte-serving route families) as streaming;
    JSON browse, `/api/v1/transcode/{jobId}/status` polling, and the transcode-start
    `POST` are unaffected and stay on the original buffered
    `bridge->request()`/`buildResponse()` path, byte-for-byte unchanged.
  - A streaming request returns a deferred producer
    (`Response::stream()`/`$streamProducer`) instead of a built body; `Application`'s
    message dispatch invokes it with the live browser `TcpConnection` — connection
    ownership stays in the worker layer, the controller never touches the socket.
  - `RelayProxyManager` publishes each `HTTP_RESPONSE` frame individually
    (`{phase:'head'}` once, `{phase:'body'}` per fragment with **no accumulation**,
    `{phase:'end'}`) instead of reassembling a whole body; `RelayProxyBridge::stream()`
    consumes the phased channel and drives the new **`RelayResponseSink`** contract
    (`head()`/`write()`/`end()`/`abort()`) — a transport-agnostic sink interface so the
    relay/bridge layer knows nothing about HTTP wire framing.
  - **`ConnectionResponseSink`** (the concrete sink) writes each fragment straight to
    the socket: fixed-length framing that preserves the origin's real
    `Content-Length`/`Content-Range`/`206` verbatim when the server sent a
    `Content-Length` (every HLS/DASH segment and every direct-play `withFile()`
    response does, post-S3) — so `<video>` Range seeking keeps working straight
    through the hub — or chunked transfer-encoding when the length is unknown.
    Hop-by-hop headers (`connection`/`keep-alive`/`transfer-encoding`) are dropped;
    header names/values are CRLF-checked against header-injection.
  - **Back-pressure is end-to-end, not unbounded:** the sink installs
    `onBufferFull`/`onBufferDrain` on the browser connection (the producing coroutine
    parks on a resume channel until the socket send buffer drains), and the
    relay→HTTP transport channel is capacity-bounded (32 fragments, ≈2 MB), so a
    stalled consumer stops draining the channel and blocks the upstream push.
  - **Timeout semantics changed from total-transfer to time-to-first-byte-then-
    inactivity.** The prior 60s timeout (`RelayProxyProtocol::STREAMING_TIMEOUT_SECONDS`,
    shipped in the D3 timeout-align half, hub v0.2.0) was a total-transfer cap —
    correct only because the old model delivered a reply once, on `END`. A true stream
    now awaits only the head frame under that same per-path timeout
    (`replyTimeoutForPath()` — 60s for `/hls`/`/dash`, 30s for `/media`), then re-arms an
    **inactivity** timer (`RelayProxyManager::touchStreamTimer()`, throttled to ≤1/s) on
    every subsequent frame — so a steadily-flowing body streams past the old fixed
    ceiling and only a genuine mid-transfer stall trips it, bounded by an absolute
    900s (`MAX_STREAM_DURATION_SECONDS`) safety ceiling. This also fixes a **latent**
    bug folded in from the same change: `/media/{id}/stream` (large, un-ranged
    direct-play through the hub) previously truncated at a 30-second **total**-transfer
    timeout; it is now first-byte + inactivity, and direct-play's first byte is
    effectively instant (server `withFile()`), so the truncation is gone.
  - **`RelayResponseSink::abort()` — a real wire-corruption bug this contract exists to
    prevent.** Caught during review (round 2): without it, a mid-stream exception
    occurring *after* the response head was already written to the browser connection
    could fall through to the ordinary error path and write a **second**, fully
    buffered error response onto the same connection — corrupting HTTP framing for
    that request. Every sink implementation now exposes `abort()` (force-close the
    connection, detach back-pressure hooks, MUST NOT throw even if the underlying
    close throws) and callers track `$headSent` to route a post-head failure through
    `abort()` instead of a second buffered write; `Application::onMessage` also carries
    a defense-in-depth backstop for this case. `RelayProxyManager::onTimeout()`/
    `failServer()` end an already-started stream (publish `{phase:'end'}`) rather than
    substituting a fresh 504/503 body once the head is on the wire, for the same
    reason.
  - Reviewed across four independent rounds — round 1 found 1 MAJOR (a channel
    resource leak) + 2 MINOR + 2 INFO findings (all fixed); round 2 (the deeper
    re-review) found the `abort()` wire-corruption bug above plus a test-fidelity gap
    and an INFO follow-up marker (all fixed); rounds 3–4 found one documentation-only
    MINOR finding, then **`NO FINDINGS`**. TestEngineer's fresh coverage pass closed 4
    genuine gaps in the new code (`ConnectionResponseSink::abort()` was previously
    exercised only through a fake sink; the `touchStreamTimer()` re-arm branch and the
    `onTimeout()` started-stream branch were previously untested) — final per-file
    coverage: `ConnectionResponseSink.php` 98.9%, `RelayProxyBridge.php` 94.4%,
    `RelayProxyManager.php` 79.7% (raised, not regressed — a large pre-existing
    untested block predates this change), `ServerProxyController.php` 97.4%.
  - **This closes out Track D's D1–D3s scope** (streaming GET/HEAD allowlisting,
    transcode-start POST, and now true streaming pass-through with the timeout fix).
    A separate D4-style version bump (`src/Version.php` + this CHANGELOG's version
    heading) follows once this lands on `master`.
  - **Known caveat: `psalm` is un-runnable on the development box used for every
    review/test round** — it requires PHP ≥8.3.16 and the box runs PHP 8.3.6. All
    other local gates (`phpstan` L9 no-baseline, `phpcs` PSR-12, full `phpunit`,
    `composer validate --strict`) are green. This is a PHP-version gate, not a code
    finding — `psalm` must be confirmed clean on CI / the live box (PHP 8.5.4) before
    this change is considered fully verified.
  - See the "Hub relay: streaming pass-through (D1–D3s)" section of
    [`phlix-docs` — Stream Quality / ABR](https://detain.github.io/phlix-docs/developers/stream-quality-abr)
    for the full architecture write-up.
- **Per-channel round-robin fairness on the tunnel data plane (HB-3.3)**
  (`src/Relay/Tunnel.php`). The flat body FIFO was replaced with per-channel body queues
  keyed by `RelayFrame::channelId()`; `flushBodyQueue` now drains round-robin (one frame per
  channel per pass) so a bulk transfer can no longer starve a concurrent browse request.
  Strict intra-channel FIFO is preserved (HEAD/BODY/END of one request never reorder).
- **`client_relay_tokens` retention sweep now prunes expired-never-revoked rows (HB-4.2)**
  (`src/Hub/ClientRelayTokenService.php`, `src/Relay/IdleReaper.php`). The prune predicate
  was `expires_at < NOW()-1d AND revoked_at IS NOT NULL`, so short-TTL tokens that expired
  but were never explicitly revoked (the common case) were never deleted and the table grew.
  Changed to OR semantics — a row is pruned once it is expired-past-the-grace-window **or**
  revoked.
- **Metrics DB retention prune gated to a single worker (HB-4.5)**
  (`src/Stats/Metrics/MetricsFlushService.php`). `flush()` took a `bool $shouldPrune` flag;
  only the `count=1` relay worker passes `true`, so the retention DELETEs run once per tick
  instead of from every HTTP/relay/client-relay worker (~4× churn). Per-worker in-RAM
  connection eviction stays unconditional (each worker owns a distinct registry).
- **Stream-timer sweep now measures inactivity, not time-since-start (HB-4.8)**
  (`src/Relay/RelayProxyManager.php`). The sweep keyed inactivity off the fixed `sent_at`,
  which terminated ACTIVE streams once they ran past the per-path timeout (~30s direct-play,
  ~60s HLS/DASH). Added a `lastActivityAt` refreshed on every HEAD/BODY/END frame; the sweep
  now tests `now - lastActivityAt >= timeout` and keeps the absolute 900s
  (`MAX_STREAM_DURATION_SECONDS`) ceiling as a runaway backstop.
- **Ed25519 previous-key purge wired into the maintenance reap (HB-4.7)**
  (`src/Hub/Ed25519KeyManager.php`, `src/Relay/IdleReaper.php`). The zero-caller
  `purgeExpiredPreviousKey()` is now invoked from the maintenance-worker reap so a rotated,
  expired previous signing key is cleaned up in the background.

## [0.2.0] — 2026-07-07

### Fixed
- **Relay proxy: HLS/DASH playback reads through the hub no longer 504 a slow-but-successful
  first on-demand segment** (`src/Http/Controllers/ServerProxyController.php`,
  `src/Relay/RelayProxyProtocol.php`, `src/Relay/RelayProxyBridge.php`,
  `src/Relay/RelayProxyManager.php`). GET/HEAD requests under the `/hls` and `/dash`
  prefixes — the multi-variant master and per-variant playlists *and* their segments,
  matched by path prefix rather than filename — now await a wider, path-scoped reply
  timeout, the new `RelayProxyProtocol::STREAMING_TIMEOUT_SECONDS` (60s), instead of the
  flat `DEFAULT_TIMEOUT_SECONDS` (30s) every proxied read previously shared. 60s clears the
  paired server's own on-demand segment-encode first-byte ceiling — the new
  `RelayProxyProtocol::SEGMENT_ENCODE_CEILING_SECONDS`, mirroring phlix-server
  `TranscodeManager::SEGMENT_MAX_WAIT_MS` (30_000ms) — plus margin for moving the segment
  body across the tunnel, while staying under the hls.js client's own `maxLoadTimeMs`
  (120_000ms) fragment abandon-and-retry threshold: a cold HEVC transcode's
  slow-but-successful first segment, fetched through a paired hub, is no longer
  prematurely cut off with a 504 while the server is still successfully producing it. The
  new `ServerProxyController::replyTimeoutForPath()` classifies the timeout by an
  exact-or-`/`-subpath match — the same rule `BROWSE_SCOPE_ALLOWLIST` uses, so a sibling
  like `/hlsX` is never mis-classified — and the chosen value is threaded all the way
  through: `RelayProxyBridge::request()` now forwards it as a `timeout` field on the
  published request envelope, and the relay worker's own completion `Timer`
  (`RelayProxyManager::asTimeout()`) uses that same value. Previously only the HTTP
  worker's own channel wait respected a per-request timeout; the relay worker that
  actually owns the tunnel still timed every request out at its injected default, undoing
  any widening on the controller side. An absent or non-positive `timeout` field falls
  back to the worker's configured default, so older callers are unaffected. Every other
  proxied path — JSON browse, `/media/{id}/stream` direct-play,
  `/api/v1/transcode/{jobId}/status` polling, and the transcode-start POST — is untouched
  and keeps the 30s default. **Buffer-free streaming of large segment/manifest bodies
  through the hub (so the relay stops fully buffering a response before forwarding it) is
  a separate, still-pending step** — this change only widens how long the hub is willing
  to wait for that still-fully-buffered response to complete.

### Added
- **Relay proxy: a signed-in owner can now START an on-demand transcode on a paired server
  through the hub relay, not just poll one already running**
  (`src/Http/Controllers/ServerProxyController.php`). `POST /api/v1/media/{id}/transcode` is
  the proxy's first (and only) permitted write — matched by a new, fully-anchored pattern
  layer, `BROWSE_SCOPE_PATTERNS['POST'] = '#^/api/v1/media/[^/]+/transcode$#'`, checked
  *after* the existing GET/HEAD prefix allowlist. It is a separate mechanism from
  `BROWSE_SCOPE_ALLOWLIST` because the media id sits in the *middle* of the path, which a
  prefix match can't express. The request's query string (so `?profile=` reaches the
  server's quality selector) and headers such as `X-Phlix-Device-Type` forward unchanged, as
  for any other proxied request. Every other mutating route — the item's
  `favorite`/`rating`/`like`/`watched`/`unwatched`/`poster`/`match/apply` siblings, and the
  admin `POST /api/v1/admin/media/merge` — still fails closed with 403 `proxy.scope_denied`;
  the existing ownership (404 `server.not_found` → 403 `server.not_owned`), relay-online (503
  `server.relay_unavailable`/`server.offline`), and path-traversal (`hasTraversalSegment()`)
  gates all run unchanged before the widened scope check. Buffer-free streaming of large
  proxied responses (segments/manifests) is still a separate, later step.

### Added
- **Relay proxy: browse-scope allowlist now passes through read-only streaming playback of a
  paired server's media, not just JSON browse** (`src/Http/Controllers/ServerProxyController.php`).
  `BROWSE_SCOPE_ALLOWLIST` gains four GET/HEAD path prefixes alongside the existing JSON-browse set:
  `/hls` (the multi-variant master playlist, per-variant `media_v{V}.m3u8` playlists, and `seg-*.ts`
  segments), `/dash` (the MPD manifest and its segments), `/media` (the direct-play byte stream —
  the only route registered under bare `/media/` at all; the admin merge endpoint lives at a
  different prefix, `POST /api/v1/admin/media/merge`, and stays unreachable either way), and
  `/api/v1/transcode` (job-status polling at `/api/v1/transcode/{jobId}/status`). A signed-in owner
  can now play a paired server's media through the hub relay, not just browse its catalog. The
  existing ownership (404 `server.not_found` → 403 `server.not_owned`), relay-online (503
  `server.relay_unavailable`/`server.offline`), and path-traversal (`hasTraversalSegment()`) gates
  all run unchanged before the widened allowlist is consulted, and — **at the time this landed** —
  every mutating method, including transcode-start, still failed closed with 403
  `proxy.scope_denied`. **Update (D2, see the newer Added entry above):** transcode-**start**
  (`POST /api/v1/media/{id}/transcode` only) is now the proxy's one narrowly-scoped exception and
  is forwarded; every other mutating method/path is unaffected and still fails closed.
  Buffer-free streaming of large response bodies through the hub is still a separate follow-on step.

### Fixed
- **Heartbeat/claim/auth 500s: the maintenance reapers now run inside a worker's event loop (cid≥0), not the master's signal scheduler**
  (`src/Common/Container/Providers/HubServicesProvider.php`, `src/Relay/RelayWorker.php`). Root-cause
  fix for the production incident (`PDOException: There is already an active transaction` +
  `SQLSTATE[HY000] 2014 … unbuffered queries active`, ~90×/day since 2026-06-22) that the dedicated
  `'txn'` connection only mitigated for the transactional handlers. The idle-tunnel reaper, server
  offline/heartbeat-retention reaper, tunnel heartbeat, and federation-session reaper were armed in
  `HubServicesProvider::boot()`, which runs in the **master** before `Worker::runAll()`. There
  `Workerman\Timer::add` has no event loop yet and falls back to the pcntl signal (`SIGALRM`)
  scheduler, so each callback fired with **no Swoole coroutine context** (`cid<0`). At `cid<0`
  `PhlixMySQLConnection::query()` takes its direct passthrough and **bypasses the per-connection
  coroutine mutex**, so a reaper query barged onto the shared socket mid-flight while a request
  coroutine held a transaction → error 2014 → corrupted transaction → the next `beginTrans()`
  throwing "already active transaction". The four timers now arm via
  `HubServicesProvider::startMaintenanceTimers()`, called once from `RelayWorker::onWorkerStart()`
  — inside the running worker's event loop (`cid≥0`, so every reaper query is mutex-serialised) and
  in the single `count=1` relay worker that already owns the `TunnelManager` they scan (so each
  reaper runs exactly once hub-wide, no longer once per HTTP worker). `boot()` retains only the true
  master-pre-fork concerns (creating the `FederationWorker`, the leaf→master federation WS connect).
  Each timer is guarded independently so one unavailable service never blocks the others.
- **Relay: a re-handshake on an active tunnel no longer spams uncaught `InvalidFrameTypeException`s**
  (`src/Relay/Tunnel.php`). When a server re-sends its JSON `HELLO`/`HELLO_ACK` on an
  already-ACTIVE tunnel (a reconnect race, or a framing desync), the binary `FrameDecoder`
  reads the 5th byte of `{"type"` — `'p'` — as frame type `0x70` and threw
  `InvalidFrameTypeException`. That propagated **uncaught** out of the Workerman message
  callback (a full stack trace per bad frame) and left the tunnel wedged in a desynced state,
  re-throwing on every subsequent frame. `onServerMessage()` now catches it, logs one
  warning, and closes the tunnel cleanly so the server reconnects and re-handshakes from a
  known-good state.
- **Relay logs: no more false "A possible infinite logging loop was detected and aborted"**
  (`src/Common/Logger/StructuredLogger.php`). Monolog's loop guard keys recursion depth on the
  current PHP `Fiber`, but Swoole coroutines are not Fibers — so under `SWOOLE_HOOK_ALL` a
  handler's file write yields the coroutine mid-`addRecord`, a concurrent coroutine re-enters
  the shared singleton logger, and the shared depth counter trips the guard into a false
  positive that **drops the record**. No handler or processor emits its own log (so there is no
  real cycle), so the guard is now disabled via `Logger::useLoggingLoopDetection(false)`.
- **Metrics: hub no longer fatals at boot with "MetricsRepositoryInterface … not instantiable"**
  (`src/Common/Container/Providers/MetricsServicesProvider.php`). The S4 metrics provider registered
  the concrete `MetricsRepository` but never bound the read-side `MetricsRepositoryInterface`, while
  the admin `MetricsController` type-hints the interface and `Application::registerMetricsRoutes()`
  resolves the controller **eagerly at boot** — so PHP-DI tried to instantiate the interface and
  killed the process (`status=255/EXCEPTION`). The provider now aliases
  `MetricsRepositoryInterface::class => get(MetricsRepository::class)` (reusing the shared singleton).
  Also conforms `migrations/033_metrics_schema.sql` to the migration-file conventions (lowercase
  `-- migration:` header; registered in `MigrationFileTest`; the telemetry rollup tables use natural
  composite keys, so the `id CHAR(36)` rule now applies only to tables that declare an `id` column).

### Security
- **systemd unit hardened to phlix-server parity (and then some)** (`scripts/install.sh`,
  `start.php`). The hub unit previously ran with almost no sandboxing. It now applies the
  same protections as the media server — `ProtectSystem=strict`, `ProtectHome=true`,
  `NoNewPrivileges`, `PrivateTmp`, `RestrictNamespaces`, `LockPersonality`, `RemoveIPC`,
  a restrictive `ReadWritePaths` (`.logs/`, `var/`, `config/` only), plus `ExecReload`/
  `ExecStop`, start-rate limiting, and journal logging — and goes further with
  `ProtectKernelTunables/Modules`, `ProtectControlGroups`, `ProtectHostname`,
  `ProtectClock`, `RestrictSUIDSGID`, and `RestrictRealtime`. Unlike the server, the
  **install root stays read-only**: `start.php` now pins Workerman's pid/status files into
  `var/` (their defaults land in the read-only root, and `saveMasterPid()` throws if it
  can't write). Deliberately omits `MemoryDenyWriteExecute`/`PrivateDevices`/
  `SystemCallFilter` (would break PHP JIT/opcache or Swoole's io_uring). Verified in a
  `systemd-run` sandbox on the target host: writes to the three ReadWritePaths succeed, a
  write to the install root is denied, and Swoole's HOME-based lock dir is writable.

### Fixed
- **systemd: set `HOME` to a writable path so Swoole's RemoteObject lock can't fail**
  (`scripts/install.sh`). The hub service user is created with
  `useradd --system --no-create-home`, so its `$HOME` is `/` (unwritable). When a
  coroutine runs a hooked stream op (e.g. an outbound HTTPS fetch), Swoole's
  `RemoteObject` bridge writes a lock to `$HOME/.swoole/remote-object-server.lock` and
  throws `failed to open lock file[…]` → the request 500s. The unit now sets
  `Environment="HOME=${INSTALL_PATH}/var"` (a service-user-owned dir, created up front),
  so the lock lands somewhere writable. Mirrors the phlix-server fix.
- **Relay proxy: clearer "relay tunnel unavailable" error when Browse is used on an
  online-but-untunnelled server** (`src/Http/Controllers/ServerProxyController.php`).
  A server's `status` (set to `online` by heartbeats in `HeartbeatHandler`) and its
  `relayActive` flag (derived from an open `relay_sessions` row, created only when the
  reverse tunnel connects+authenticates on `:8802`) are set by **independent** paths and
  can legitimately disagree: a server can heartbeat fine yet have no relay tunnel. The
  hub "My Servers" SPA previously gated Browse on `status` alone, so it enabled Browse on
  such a server and the proxy then returned a bare `503 server.offline` ("No active relay
  tunnel"). The proxy now distinguishes the two cases: when `status='online'` but the
  tunnel is absent it returns `503 server.relay_unavailable` with an actionable message
  ("This server is online but its secure relay tunnel isn't connected…"); when the server
  is genuinely down it keeps `503 server.offline` ("Server is offline."). The security
  invariant is unchanged — the proxy still refuses to forward when there is no open relay
  tunnel (the tunnel is the trust boundary). No relay-session lifecycle bug was found:
  `relayActive` faithfully reflects tunnel presence; the fix is the clearer contract plus
  SPA gating on `relayActive` (handled in `web-ui`). The `GET /api/v1/me/servers` list
  payload already exposes both `status` and `relayActive` (`ServerInfoDto::toPayload()`),
  so the SPA can gate the Browse button on `relayActive`.

### Added
- **Metrics: the client-facing relay worker (`:8803`) now produces live-connection
  telemetry** (`src/Relay/ClientRelayWorker.php`). Completes the S4 producer wiring
  begun for HTTP requests + relay **server** tunnels (`RelayWorker`, `:8802`).
  `ClientRelayWorker` gained an `onWorkerStart` that resolves this worker's
  `MetricsCollector` + `MetricsFlushService` and arms the flush timer plus a
  live-connection **touch** timer. Each authenticated client mount now opens a
  `metrics_connections` row (`kind='stream'`, attributed to the owning user,
  correlated by `server_id`), its cumulative `bytesRead`/`bytesWritten` are pushed
  into the registry between flushes, and a **final touch** is left on close (NOT an
  immediate delete) so the next flush persists real totals before the TTL prunes the
  idle row. Live connections are tracked in a per-worker map keyed by a stable
  per-connection UUID because `spl_object_id()` is reused after a connection is
  destroyed and is unsafe as the registry key. To attribute the row,
  `ClientRelayWorker::validateClientAuth()` now returns the resolved owner `user_id`
  (or `null` on failure) instead of a bare `bool`. The hub relay is the **fallback**
  playback path (primary is direct signed URLs), so these connections are relatively
  rare; the wiring is guarded end-to-end, so with metrics disabled the connection
  hooks are pure no-ops.
- **`web-ui`: bumped `@phlix/ui` `v0.56.0` → `v0.57.0` and rebuilt the committed SPA
  bundle (`public/assets/app/`) (Wave 1 bump).** Keeps the hub's shared SPA in lockstep
  with the media server. Picks up the per-user favorites wiring (`MediaCard` favorite
  button, Browse "Favorites" row, Browse/Detail persistence + hydrate), the multi-level
  **Love** control (`LoveButton.vue` 4-state component on cards + detail), and the player
  favorite/Love controls. `package.json`/`package-lock.json` pin the new `v0.57.0` git
  tag; the Vite bundle was regenerated. No hub PHP changed.

  **Known limitation — favorite/Love writes degrade through the relay proxy** (tracked
  as [#122](https://github.com/detain/phlix-hub/issues/122)): the hub's media relay proxy
  allowlists **`GET`/`HEAD` only**, so when a server is browsed *through the hub*, the
  favorites/Love **writes** do not persist — `POST .../favorite` returns
  `403 proxy.scope_denied` and `PUT .../like` + `DELETE .../favorite` are not routed.
  These controls work normally over a **direct** session to the server. A future fix is a
  separate POST-capable command path reusing the per-user relay token (not a browse-scope
  allowlist widening). A later, unrelated step (D2) added exactly one narrow exception to
  the browse-scope gate — a transcode-start `POST` — which does not touch favorites/Love/rating.

### Known issues
- **Relay proxy is `GET`/`HEAD`-only for media-state writes — favorite/Love writes do not
  persist over the hub relay** ([#122](https://github.com/detain/phlix-hub/issues/122)). See
  the v0.57.0 bump note above. Direct sessions are unaffected. (Since D2, the proxy's only
  non-GET/HEAD route is that single, unrelated transcode-start `POST` — see the newer Added
  entry above.)

- **`web-ui`: bumped `@phlix/ui` `v0.55.0` → `v0.56.0` and rebuilt the committed SPA
  bundle (`public/assets/app/`) (Wave 0 bump).** Keeps the hub's shared SPA in lockstep
  with the media server. Picks up the shared admin **Duplicates** page and the
  **Metadata** settings tab's per-media-type source-priority editor (server-only admin
  surfaces — they have no effect on the hub itself, which has no library/scan subsystem,
  but the bump keeps both consumers on one `@phlix/ui` version). `package.json`/
  `package-lock.json` pin the new git tag; the Vite bundle was regenerated. No hub PHP
  changed.
- **HTTP-over-relay proxy: `GET|POST /api/v1/servers/{id}/proxy/{path:.*}`.** Lets a
  browser on the hub fetch a paired media server's API over the existing reverse
  tunnel (Phase 1 of hub inline media browsing). The endpoint authenticates the
  user, verifies they own the server, and confirms a live relay session, then
  round-trips the request to the server and returns its response. Pieces:
  - `Relay\RelayProxyBridge` (HTTP-worker side) publishes the request over a
    `workerman/channel` broker and awaits the reply on a per-worker event,
    blocking only the request's coroutine.
  - `Relay\RelayProxyManager` (relay-ws-worker side) owns the tunnels: it sends an
    `HTTP_REQUEST` frame, reassembles the streamed `HTTP_RESPONSE` chunks
    (`HEAD → BODY* → END`), and publishes the response back. Per-request timeout
    → 504; tunnel drop → 503.
  - `Relay\RelayProxyProtocol` constants; channel broker started in
    `Application::boot()`; `RelayWorker` joins the broker + wires the proxy on
    `onWorkerStart`.
  - `Http\Controllers\ServerProxyController` (owner-gated; 401/404/403/503/504).
  - Consumes `detain/phlix-shared` ^0.10.0 (`HTTP_REQUEST`/`HTTP_RESPONSE` types).
- **`workerman/channel`** dependency for cross-process (HTTP-worker ↔ relay-worker)
  request/response delivery.

### Security
- **Invite-link redemption is now atomic single-use (B5).** `Hub\InviteLinkHandler::redeemInviteLink()`
  previously read `use_count`, decided the invite was still valid, then issued a SEPARATE
  unconditional `UPDATE … use_count + 1` — a check-then-act race in which two concurrent
  redemptions could both pass the read and both increment, redeeming a `max_uses = 1`
  invite twice. The read-decide-then-increment is replaced by ONE conditional UPDATE the
  database evaluates atomically — `UPDATE invite_links SET use_count = use_count + 1 WHERE
  token_hash = :token_hash AND use_count < max_uses AND (expires_at IS NULL OR expires_at >
  :now)` — so of N concurrent redemptions exactly `max_uses - use_count` affect a row and
  the rest affect zero. Zero affected rows is treated as "already used / expired / invalid"
  and rejected with the existing exhausted error (410); the share is granted only on exactly
  one affected row. The invite metadata (owner/server/library/permission) is read from the
  authoritative `invite_links` row so the claim and the resulting share always agree with the
  persisted invite. Multi-use invites still allow up to `max_uses` redemptions; expired,
  not-found, and self-redeem cases keep their existing errors.
- **Client relay mount now requires a per-user, revocable hub relay token (S2, closing half).**
  `Relay\ClientRelayWorker` no longer accepts the media server's long-lived 7-day
  **enrollment JWT** as a client credential. At WS mount (`/client/{server_id}`) it now
  (1) validates the short-lived, revocable, per-user relay token via
  `Hub\ClientRelayTokenService::validate()`, (2) requires the token's bound `server_id`
  to equal the path-derived server, and (3) re-confirms the bound user still **owns** that
  server via `Hub\ServerInfoHandler` — mirroring the HTTP relay proxy's ownership gate in
  `ServerProxyController::proxy()`. A mount that presents only an enrollment JWT, a token
  scoped to a different server, a token for a non-owned server, or a revoked/expired token
  is rejected with WS close code 4401. The legacy `?token=` query-string credential path
  was **removed** (`extractJwt` → `extractClientToken`), so bearer secrets never land in
  access/proxy logs or request histories — the token must be presented via
  `Authorization: Bearer` or the `Sec-WebSocket-Protocol` subprotocol. Builds on S2a (the
  `client_relay_tokens` table, migration 032, and the mint endpoint).
- **All bearer-ish ids are CSPRNG (S4).** Audited every id/secret mint point
  (`grep -rn 'mt_rand\|uniqid(\|rand(\|random_int(' src/`): there are no
  non-crypto RNG usages left in any id/secret path. The claim `id` — the
  unguessable poll secret a headless server uses to fetch its one-time
  enrollment JWT from `GET /api/v1/server-claims/{id}` — is a 128-bit CSPRNG
  RFC-4122 v4 UUID minted via `Common\Support\Ids::uuidV4()` (`random_bytes(16)`),
  as are the server, heartbeat, relay-session, client-mount, federation, invite,
  audit, request, and user ids consolidated under `Ids` in Q1. Added a focused
  regression test (`ClaimRequestHandlerTest::testHandleNewClaimMintsCsprngClaimIds`)
  asserting the persisted claim id is canonical-UUIDv4-shaped and unique across a
  large sample (the observable signature of a CSPRNG mint), plus a shape assertion
  on the single-claim insert path. The remaining `random_int()` callers are the
  display-only claim code and admin reset-password generator — both already CSPRNG.
- **Relay tunnel HELLO now cryptographically validates the `enrollment_jwt`.**
  `Relay\Tunnel` verifies the Ed25519-signed enrollment JWT (via
  `EnrollmentJwtService`) and requires its `server_id` to match before activating
  a tunnel — previously the token was accepted without validation, so any client
  could open a tunnel by guessing a `server_id`.
- **Enrollment-key rotation now keeps a 24h overlap window (S7).** Previously
  `Ed25519KeyManager::rotate()` discarded the old key the instant a new one was
  generated, so every outstanding 7-day enrollment JWT was invalidated at the
  next heartbeat (spurious `ENROLLMENT_TOKEN_EXPIRED`) the moment an operator
  rotated. `rotate()` now retains the PUBLIC half of the outgoing key (kid + raw
  public key) in a `<key>.previous.json` sidecar, stamped with a 24h overlap
  expiry (`Ed25519KeyManager::OVERLAP_TTL_SECONDS`). During the overlap:
  - `EnrollmentJwtService::validateEnrollmentJwt()` accepts a token whose `kid`
    matches **either** the current key **or** the still-valid previous key, and
    verifies the signature against the matched key's public half (it also now
    pins the header `kid` to the resolved kid to block a key-confusion swap).
  - the JWKS at `GET /.well-known/jwks.json` publishes **both** keys, so a
    standards-compliant consumer (the media server) selects the right key by
    `kid` and 7-day JWTs minted before the rotation keep validating until they
    naturally expire.
  After the overlap window lapses the previous key is dropped on next access
  (active-key list, JWKS, and verification all reject it) and the sidecar is
  pruned. Only the public half is retained — the hub never signs with the old
  key after rotating. The never-rotated single-key path is unchanged and writes
  no sidecar. A `Closure(): int` clock is now injectable into `Ed25519KeyManager`
  for deterministic overlap-expiry testing.
- **Relay proxy admission is gated on the live tunnel registry, not the stale
  `relay_active` DB flag (B7).** The `relay_active` column (derived from
  `EXISTS(relay_sessions … closed_at IS NULL)`) can lag the truth after a
  relay-worker crash/restart, where `Hub\RelaySessionManager::closeSession()`
  is never reached and open rows are left behind. Previously
  `ServerProxyController` trusted that stale flag and forwarded, so the request
  hung until it timed out into a slow **504**. Now the in-memory tunnel registry
  owned by the relay worker is the authoritative gate: `Relay\RelayProxyManager`
  cross-checks `TunnelManager::getTunnelForServer()` at admission and, when there
  is no live, ACTIVE tunnel, fails fast with **503** carrying the distinct code
  `server.no_tunnel` (was the ambiguous `server.offline`) instead of forwarding.
  On relay-worker start, `Relay\RelayWorker::onWorkerStart()` now reconciles
  `relay_sessions` via the new
  `RelaySessionManager::closeOrphanedSessions(list<string> $liveServerIds)`:
  every open session whose `server_id` is not backed by a live tunnel is marked
  closed (`close_reason = 'reconciled_on_start'`), so orphaned `relay_active=1`
  rows left by a crash re-converge. The DB flag remains for display only
  (dashboard / server-detail badge).

### Fixed
- **`PhlixMySQLConnection`: hold the per-connection coroutine mutex for the WHOLE explicit transaction (B4).** The query-mutex (below) only made a *single* `query()` atomic w.r.t. other coroutines, so a multi-statement `beginTrans()`…`commitTrans()` — or a `SELECT … FOR UPDATE` immediately followed by an `UPDATE` — released the mutex **between** statements, letting a second coroutine's query interleave onto the shared PDO socket *inside* the first coroutine's open transaction (running its statement in, and even committing/rolling back, the first coroutine's uncommitted work; defeating `FOR UPDATE` row-lock guards). `beginTrans()` now acquires the mutex for the whole transaction and `commitTrans()`/`rollBackTrans()` release it — always, including on exception paths (`try`/`finally`) and idempotently (our `execute()` override's in-query rollback plus the caller's own catch-block rollback release exactly once). The transaction holder is tracked in the existing `queryLockHolder` field, so the holder's own nested `query()` calls run **reentrantly** (no self-deadlock); a different coroutine's `query()`/`beginTrans()` **blocks** until the holder commits or rolls back. CLI paths (coroutine id `< 0`: migrations, cron) still run directly with no Channel. The two read-then-write callers that relied on a bare `SELECT … FOR UPDATE` (`Hub\ClaimRequestHandler::handleClaimCode()` double-claim guard; `Hub\HeartbeatHandler::handle()` existence check → `UPDATE`/`INSERT`) are now wrapped in an explicit `beginTrans()`/`commitTrans()`/`rollBackTrans()` so the row lock actually spans the read and the writes (under autocommit it was released the instant the `SELECT` returned). `Auth\AuthManager::register()` already used explicit transactions and gains the protection automatically.
- **`PhlixMySQLConnection`: type-aware parameter binding for emulated prepares (`LIMIT`/`OFFSET` fix).** Emulated prepares (below) send bound params as strings by default, so `LIMIT :limit`/`OFFSET :offset` became `LIMIT '50'` and MySQL rejected them with a 1064 syntax error (e.g. `HeartbeatHandler` recent-server lookup, any paginated query). `execute()` is now overridden to bind each value with its natural PDO type (`int → PARAM_INT`, `bool → PARAM_BOOL`, `null → PARAM_NULL`, else `PARAM_STR`) — mirroring the parent's prepare/execute + one-shot 2006/2013 reconnect — so integer placeholders stay unquoted. Verified on the live hub: bound + positional + mixed `LIMIT`/`OFFSET` queries succeed and 120 concurrent claim POSTs stay corruption-free.
- **`PhlixMySQLConnection`: force emulated + buffered prepared statements (the actual fix for the pairing/login 500s).** The per-connection mutex (below) serialises `query()` calls, but the hub still 500'd under concurrent requests with `Call to a member function bindParam() on false` / `HY093 Invalid parameter number`. Root cause: the parent connects with **native, unbuffered** prepares, and because mysqlnd's socket is coroutine-hooked under Swoole, each statement keeps per-statement server-side state on that socket which **leaks across coroutine yields** — wedging the shared connection so the next `prepare()` returns `false`. `connect()` now sets `PDO::ATTR_EMULATE_PREPARES = true` (prepare is client-side only, no socket round trip) and `PDO::MYSQL_ATTR_USE_BUFFERED_QUERY = true` (every result row is consumed immediately, so nothing pending survives a yield). Verified on the live hub: **150 concurrent claim POSTs with zero connection corruption** (was ~5-10% before). Parameterisation stays injection-safe (PDO still quotes bound values; charset is utf8mb4).
- **`PhlixMySQLConnection`: serialise queries on a per-connection coroutine mutex (+ default the charset to `utf8mb4`).** Under the Swoole event loop the DI container shares ONE `Workerman\MySQL\Connection` across every per-request coroutine, but it wraps a single PDO socket. With nothing guarding it, a second coroutine could start a query mid-flight on that socket (Swoole's runtime hook yields while a query waits) — corrupting the connection so `prepare()` silently returned `false` and every later query threw `Call to a member function bindParam() on false`, crashing the HTTP worker (500s). This surfaced on the **pairing/claim** flow and the parallel **My Servers / dashboard** widget fetches right after login. `query()` now runs each query under a reentrant per-coroutine `Swoole\Coroutine\Channel` mutex (direct passthrough outside a coroutine, e.g. CLI migrations). Also defaults the connection charset to `utf8mb4` (parent defaults to legacy `utf8`/utf8mb3, which makes MySQL 8 refuse to bind utf8mb3 params into utf8mb4 columns — error 3988 — on writes). Brings the hub's connection subclass to parity with phlix-server's. CI: the Psalm job now loads `ext-swoole` so it can reflect `Swoole\Coroutine\Channel`.

### Changed
- **`web-ui`: bumped `@phlix/ui` `v0.41.0` → `v0.44.0` and rebuilt the committed SPA bundle (`public/assets/app/`).** Keeps the hub's shared SPA in lockstep with the media server. Picks up: random-access (sparse) library-grid paging so the A-Z jump rail loads the titles at the jumped-to letter (v0.42.0); synchronous scroll measurement so the grid window can't freeze under scroll load (v0.43.0); and the player's signed direct-play `stream_url` consumption (v0.44.0 — the client half of the media server's signed-URL gate; harmless for the hub, which doesn't serve media bytes, but keeps both consumers on one `@phlix/ui` version). `package.json`/`package-lock.json` pin the new git tag; the Vite bundle was regenerated. No hub PHP changed.
- **`web-ui`: bumped `@phlix/ui` `v0.39.0` → `v0.41.0` and rebuilt the committed SPA bundle (`public/assets/app/`).** Fixes the **My Servers** and **Federation** pages showing "unauthorized" with a Retry button that never cleared, and makes the **"Add server"** button functional. Root cause: the SPA's default API client used a no-op token store, so those pages never sent the user's Bearer token and the hub's `AuthMiddleware` returned 401. v0.41.0 sends the session token by default; "Add server" now opens a modal that posts a claim code to `POST /api/v1/server-claims/claim`. `package.json`/`package-lock.json` pin the new git tag; the Vite bundle was regenerated. No hub PHP changed.
- **`web-ui`: bumped `@phlix/ui` `v0.30.0` → `v0.36.0` and rebuilt the committed SPA bundle (`public/assets/app/`).** Picks up the shared-UI work served at `/app/*`: full-width layout, clicking a poster opens the info/detail page, the matched/unmatched metadata filter, clickable cast (each cast name opens that title's library filtered to the actor), the listing grid pre-sized to the full result count with on-demand paging, and the A-Z jump rail on long library listings. `package.json`/`package-lock.json` pin the new git tag; the Vite bundle was regenerated. No hub PHP changed.

### Added
- **Shared admin console — `/api/v1/admin/*` API + the hub SPA admin section.** The hub now mounts
  the shared `@phlix/ui` admin console at `/app/admin/*` (Hub Dashboard, Users, Logs, Settings,
  Audit Logs), exposed via a `requiresAdmin` nav entry and gated server-side by
  `[AuthMiddleware, AdminMiddleware]` (401 `auth.required` / 403 `auth.not_admin`). It is backed by a
  new `/api/v1/admin/*` surface the shared admin clients call: `GET /api/v1/admin/logs`(+`/tail`,
  `/tail-all`) and `GET/PUT /api/v1/admin/settings` reuse the existing `LogController` /
  `HubSettingsController` (mirroring the back-compat `/api/v1/me/logs*` and `/api/v1/me/hub-settings`
  routes — `putSettings()` now also returns the re-resolved `{settings, overridden}` so the page
  refreshes its custom-badges); a new `AdminUserController` serves `/api/v1/admin/users`
  (list/create + `{id}` get/update/delete + `set-admin` + `reset-password` + an always-empty
  `{id}/profiles`, since the hub has no profiles table) on top of `UserRepository` (gained
  `findAll`/`update`/`delete`/`countAdmins`); and a new `AdminDashboardController` serves
  `GET /api/v1/admin/dashboard/summary` (server fleet total/online/offline, active relay sessions,
  pending requests, user count) and `GET /api/v1/admin/dashboard/activity?limit=` (recent audit
  events). The hub SPA (`web-ui`) bumped `@phlix/ui` to `v0.19.0` and wires the section via
  `buildHubAdminRoutes()`; the built bundle is committed to `public/assets/app/`. Documented in the
  README and `phlix-docs` (new Hub Admin Console page). (PRs #75–#79.)

- **Hub: server detail page at `/servers/{id}` — server info, active relay session, and heartbeat history (H.3).** New `GET /api/v1/me/servers/{id}` API endpoint (`ServerDetailController`) returns server info (`ServerInfoHandler`), active relay session (`RelaySessionManager::getActiveSession()`), and the last 20 heartbeat rows (`HeartbeatHandler::getHeartbeatHistory()`). Ownership validated (403 if not owner). The SSR shell `home/servers.tpl` is populated client-side by vanilla JS (`servers.js`) — `formatRelativeTime()`, `formatUptime()`, `formatBytes()` helpers — with `credentials: 'include'` and `encodeURIComponent()` on the path param. "View Details" button added to each server card in `server-card.tpl`; "Servers" nav link added to `layouts/base.tpl`. PHPUnit suite: 575 tests unchanged. PHPStan level 9 clean (0 new errors introduced by this step).

- **Hub: library share management UI at `/manage-shares` and `/shared-with-me` — create/revoke shares, inline permission edit (H.2).** New SSR pages (`manage-shares.tpl`, `shared-with-me.tpl`) backed by vanilla JS (`manage-shares.js`, `shared-libraries.js`). The "Share Library" modal lets you select a server, library, collaborator email, permission level, and optional expiry, then create the share via `POST /api/v1/me/shares`. The "Libraries I've Shared" table supports inline permission changes (`PATCH /api/v1/me/shares/{id}`) and one-click revoke (`DELETE /api/v1/me/shares/{id}`). The "Shared With Me" page renders incoming shares as cards with a "Browse Library" link. Navigation items wired into `layouts/base.tpl`. PHPUnit suite: 575 tests unchanged.

- **`webman/console` CLI — `bin/phlix` (step 0.8).** Added `webman/console` and a custom `bin/phlix` entrypoint that registers `Phlix\Hub\Console\Commands\*` instances on a `Webman\Console\Command` application (Symfony Console under the hood). Two commands ship: `migrate` (applies `migrations/*.sql` via the existing `Phlix\Hub\Common\Database\MigrationRunner`, idempotent through its tracking table) and `smoke:jwt` (proves the `JwtHandler` ↔ `Phlix\Shared\Auth\JwtClaims` create/validate round-trip). The CLI is a one-shot process — no Swoole loop — and all database access is lazy, so `php bin/phlix list` works with no database. `MigrationRunner` is no longer `final` so the `migrate` command can inject and unit-test a mocked runner; `scripts/run-migrations.php` is unchanged. Run with `php bin/phlix <command>`.

- **Bare-metal Swoole + php-uv build (step 0.3).** `scripts/install.sh` now compiles the Swoole and php-uv extensions from source during a fresh install (and on the `--update` repair path), giving the step 0.2c coroutine runtime real extensions on Debian/Ubuntu hosts — not just in Docker. The Swoole `./configure` flag set and php-uv `--with-uv` build mirror `phlix-server` exactly. The build is **idempotent**: each step short-circuits via `php -m` when the extension already loads, so re-running the installer never triggers the slow recompile.

- **Workerman disable-function preflight (step 0.3).** A new preflight in `scripts/install.sh` fails loudly and early if `disable_functions` blocks any process-control / posix / socket primitive Workerman needs.

- **Swoole + php-uv loaded in the PHPUnit CI job (step 0.3).** The `phpunit` job in `.github/workflows/ci.yml` now loads both extensions and verifies them with `php -m | grep -iE '^(swoole|uv)$'` before the suite runs.

- **Coroutine runtime enabled (step 0.2c).** `start.php` now sets `Worker::$eventLoopClass = \Workerman\Events\Swoole::class` before any `Worker` is instantiated and calls `Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL)` in the master process, mirroring `phlix-server/start.php`. The block is guarded by `extension_loaded('swoole')` with a `trigger_error(E_USER_WARNING)` fallback so dev hosts without ext-swoole still boot (the loaded-extension assertion lands in CI in step 0.3). Audit of `src/` for `protected|private|public static $`, `global $`, and `$GLOBALS[…]` carrying per-request data found **zero offenders** (output recorded in `/tmp/0.2-hub-static-audit.txt`). Introduced `Phlix\Hub\Http\RequestContext` — a thin typed wrapper around `support\Context` with `setUserId/getUserId/hasUserId/clearUserId` — as the canonical place to publish and read per-request data; mirrors `Phlix\Server\Http\RequestContext`. `AuthMiddleware` now publishes the authenticated user-id into the coroutine-local context on a successful auth and explicitly does NOT publish on any rejection path (missing token, invalid token, unknown user). New `tests/Unit/Coroutine/ContextIsolationTest.php` (10 tests, 100% coverage on `RequestContext.php`) proves per-fiber isolation and exercises the ext-swoole graceful-fallback branch. `AuthMiddlewareTest` extended with `Context::destroy()` setUp + two new tests verifying the publish/no-publish behavior. Documented in `phlix-docs/docs/dev/coroutine-runtime.md`.

### Changed
- **Upgraded to Webman 2.2 / Workerman 5.1.** Added `workerman/webman-framework:~2.2` and pinned `workerman/workerman:~5.1` as a prerequisite for coroutine support (step 0.2). No other changes.
- `Phlix\Hub\Hub\TlsCertificateManager::provisionCertificate()` (and
  the underlying `runAcmeChallenge()` flow) now throws a
  `\RuntimeException` with a stable, machine-grep-able message —
  `'ACME certificate provisioning is not implemented in this build.
  Provision certs out-of-band — see docs/hub-admin/tls.md.'` — instead
  of silently shelling out to `openssl`, generating an account key
  and CSR, and then returning `file_exists(...fullchain.pem)` as if
  it had actually issued anything. Read-side helpers
  (`getCertificatePath`, `getPrivateKeyPath`, `needsRenewal`) still
  tell the truth from on-disk material. New `isProvisioned(string
  $subdomain): bool` helper exposes that truth directly. Shell-safety
  pass: the cert-expiry openssl call is now routed through
  `proc_open` with an argv array (no shell, no `escapeshellcmd`),
  and the temp-file cleanup in CSR generation no longer needs to
  swallow `@unlink` errors because that code path is gone.
  `DnsAliasManager::allocateSubdomain()` catches the new exception
  and logs a warning so subdomain (DNS) allocation still succeeds —
  TLS is now an out-of-band step. `DnsAliasManager::refreshCertificate()`
  lets the exception propagate. `SubdomainController::allocate()` no
  longer invokes cert provisioning at all — DNS allocation succeeds
  unconditionally and the cert paths come back as empty strings until
  material is installed out-of-band, so clients can distinguish "DNS
  wired, TLS pending" from a fully provisioned state. The explicit
  cert-refresh entry point `SubdomainController::refreshCertificate()`
  (not yet routed publicly) catches `\RuntimeException` and returns
  **HTTP 501 Not Implemented** with
  `{"error":"NOT_IMPLEMENTED","code":"tls.acme_not_implemented",...}`
  and a `Link: </docs/hub-admin/tls.md>; rel="help"` header.
  Previously the manager looked complete on paper but in practice
  silently failed every provisioning attempt.
- `Phlix\Hub\Http\Controllers\RelayController::handle()` — the
  post-auth, post-`Upgrade: websocket` HTTP path now returns
  **HTTP 501 Not Implemented** (RFC 9110 §15.6.2) instead of
  HTTP 500. This endpoint is HTTP-only by design: the relay tunnel is
  actually established over the dedicated WebSocket worker (`ws://…:8802`,
  see `RelayWorker`), so the HTTP handler exists only to redirect
  callers there. The body carries a stable machine-readable shape:
  `{"error":"NOT_IMPLEMENTED_VIA_HTTP","code":"relay.ws_http_endpoint",
  "message":"…","ws_endpoint":"ws://…:8802","protocol":"…",
  "docs":"https://detain.github.io/phlix-docs/dev/relay-protocol"}`
  plus `Link: <docs-url>; rel="help"` and `X-WS-Endpoint: ws://…:8802`
  headers. Auth gates (401, 426) are unchanged — only the terminal
  status code and body shape changed. Previously the 500 misled clients
  into retrying as if this were a transient server fault.

### Known Limitations
- **Step C.8 ACME / Let's Encrypt provisioning is not implemented in
  this build.** `TlsCertificateManager::provisionCertificate()` throws
  `\RuntimeException` with the stable message
  `'ACME certificate provisioning is not implemented in this build.
  Provision certs out-of-band — see docs/hub-admin/tls.md.'`, and the
  cert-refresh path of `POST /api/v1/servers/{id}/subdomain` returns
  HTTP 501 `code=tls.acme_not_implemented`. Subdomain allocation (DNS
  record + DB row) still works; operators install TLS material out-of-
  band — see [`docs/hub-admin/tls.md`](docs/hub-admin/tls.md).
- **Hub relay TLS depends on out-of-band certificates.** The relay URL is
  advertised as `https://{subdomain}.{public_domain}`, but automatic
  certificate provisioning is the stubbed ACME flow above — operators must
  install TLS material out-of-band (see `docs/hub-admin/tls.md`) before the
  relay endpoint presents a valid certificate. The relay tunnel itself
  (server-side and client-facing) is implemented; see "Added".

### Fixed
- **Admin console no longer renders for an unvalidated session or a non-admin (`@phlix/ui` → v0.20.0).**
  The shared SPA router guard treated a token's mere *presence* in `localStorage` as "logged in" and applied
  no admin-role check, so after a reload a stale/expired token still rendered every `/app/*` route — including
  the whole `/app/admin/*` console — and the account badge fell back to a generic "A" because the user was
  never rehydrated. (The hub API already returned 401/403 via `[AuthMiddleware, AdminMiddleware]`, so this was
  client-side broken access control, not data exposure.) v0.20.0 validates a restored token once via
  `/auth/me` before the first protected route resolves — clearing it and redirecting to login when invalid —
  and redirects a logged-in non-admin away from the admin section. `web-ui/package.json` pins `#v0.20.0`
  (commit `426e1ba`); the committed `public/assets/app/` bundle is rebuilt; no hub code changed.
- **`scripts/install.sh --update` no longer exits 1 after a successful update.** `do_update()`'s final
  statement was `[ "$prev_commit" = "$new_commit" ] && info "(already up to date)"`; when the commit
  actually changed (a real update — the common case) that test evaluated to 1, and because `do_update`
  runs as a bare statement under `set -euo pipefail`, the non-zero return aborted the script before the
  trailing `exit 0` — so a successful update reported failure to anyone checking `$?` (a no-op update,
  where the commits matched, exited 0 and masked it). The check is now an `if` block followed by an
  explicit `return 0`.
- **Admin dashboard summary 500 + audit-log binding — named query params must be colon-free.**
  `GET /api/v1/admin/dashboard/summary` returned HTTP 500 (`SQLSTATE[HY093] Invalid parameter
  number: parameter was not defined`) because `AdminDashboardController` bound the pending-requests
  counter with a colon-prefixed key (`[':status' => 'pending']`). `workerman/mysql`'s `Connection::bind()`
  prepends the `':'` itself, so a leading colon produces the placeholder `'::status'`, which PDO never
  matches. Corrected to the colon-free form (`['status' => 'pending']`) the rest of the codebase and
  `.claude/rules/database-queries.md` already use. The same latent bug in `AuditLogRepository` (the
  `log()` INSERT and every `find()` filter) is fixed too — DB-backed audit logging and filtered audit
  queries silently failed before this. The misleading `[':id' => $id]` example in
  `PhlixMySQLConnection`'s docblock (which propagated the mistake) is corrected. A new
  `tests/Support/BindingContractConnection` test double replays workerman's real binding rule (throws
  HY093 on a colon-prefixed key) so this class of bug can no longer pass a green test.
- `Phlix\Hub\Http\Controllers\ServerManageController::accessInfo()` now
  populates `relay_url` when the relay tunnel is active and the server has
  been allocated a subdomain (via migration 008's `servers.subdomain`).
  The URL is built as `https://{subdomain}.{public_domain}` using the new
  `public_domain` key in `config/server.php` (default `phlix.media`,
  overridable via the `HUB_PUBLIC_DOMAIN` env var). Previously the field
  was hardcoded to `null`, so the response never exposed the relay URL
  at all. The response shape (`relay_url` key) is unchanged. With the
  client-facing relay worker now implemented (see "Added"), `relay_url`
  is reachable end-to-end once the server's tunnel is active and TLS
  material is installed for the subdomain (cert provisioning is still
  out-of-band — see "Known Limitations").
- `migrations/012_enrolled_at_and_last_frame_at.sql` — creates the
  `servers.enrolled_at` and `relay_sessions.last_frame_at` columns
  that `ClaimRequestHandler` and `RelaySessionManager` write to.
  Without this migration a fresh database could not complete a
  server claim or record relay frame activity. The same migration
  also back-fills `enrolled_at` from `created_at` for any rows that
  already exist. Migration `007_server_claims_and_servers.sql` was
  also updated to (a) drop its forward reference to `enrolled_at`
  in an `AFTER` clause (column position is cosmetic) and (b) use
  `ADD COLUMN IF NOT EXISTS` so a re-run on a partly-patched
  database is a no-op instead of an error.
- `Phlix\Hub\Hub\ServerInfoHandler::getServerInfo()` and `getServersForUser()`
  now populate `ServerInfoDto::relayActive` from the actual database state
  (an `EXISTS` subquery against `relay_sessions` for rows where
  `closed_at IS NULL`). The field was previously hardcoded to `false`,
  so `GET /api/v1/me/servers/{id}/access-info` and the My Servers dashboard
  always reported the relay tunnel as down regardless of its real state.
  The DTO contract is unchanged; only the value source was fixed. NOTE:
  `relayActive=true` means a *server* has an open relay session with the
  hub (the server-side tunnel is up). Clients reach the server through the
  hub via the client-facing relay worker (`ws://…:8803`, see "Added"),
  authenticating with their enrollment JWT.

### Added
- **Client-facing hub relay worker (Section 9) — remote access through the
  hub is now wired end-to-end.** Previously only the *server-side* tunnel
  half existed; the client-facing half is now implemented and unit-tested:
  - `Phlix\Hub\Relay\ClientRelayWorker` (`src/Relay/ClientRelayWorker.php`) —
    a Workerman WebSocket worker on `ws://…:8803` (`ClientRelayWorker::DEFAULT_PORT`,
    overridable via the `client_relay_port` config). Started by
    `Application::run()` alongside the server-side `RelayWorker` (`:8802`).
    It extracts the enrollment JWT from `Authorization: Bearer`,
    `Sec-WebSocket-Protocol: bearer, <jwt>`, or `?token=` (in that order),
    validates it for the requested `server_id` via `EnrollmentJwtService`,
    and on success delegates to `ClientMountController`.
  - `ClientMountController::onWebSocketConnect/onClientMessage/onClientClose`
    now have a real caller (the worker). `acceptClient()` binds the client
    to the matching server `Tunnel` via `TunnelManager`; frames are parsed
    with `FrameDecoder` and routed per channel so a single server tunnel can
    multiplex many concurrent clients. Idle sessions are swept by `IdleReaper`.
  - `ClientMountController::handle()` (the plain-HTTP `GET /client/{server_id}`
    route) is no longer a 401 stub: it returns **426 Upgrade Required** when
    no WebSocket upgrade is requested and **501** (with `X-WS-Endpoint`)
    steering callers to the `ws://…:8803` worker — mirroring `RelayController`'s
    HTTP-endpoint contract.
  - Requires `phlix-shared ^0.5.1` (relay channel-mux). The server side
    (`phlix-server`) rewrote `RelayConsumer` to the same multiplexed tunnel
    protocol with per-channel DATA routing.
  - Tests: `ClientRelayWorkerTest`, `TunnelTest`, `TunnelManagerTest`,
    `RelayWorkerTest` under `tests/Unit/Relay/`.
- **Step D.5 — Invite-Link Sharing**: Single-use invite link sharing for library access.
  - `InviteLink` DTO — represents an invite link with expiry, max uses, and status checks (`isExpired()`, `isExhausted()`, `canUse()`).
  - `InviteLinkHandler` — business logic for creating, redeeming, listing, and revoking invite links.
  - `InviteLinkController` — API controller with endpoints:
    - `POST /api/v1/me/invite-links` — create a new invite link
    - `GET /api/v1/me/invite-links` — list all invite links for the authenticated user
    - `DELETE /api/v1/me/invite-links/{id}` — revoke an invite link
    - `GET /invite/{token}` — public invite acceptance page
  - `migrations/009_invite_links.sql` — creates `invite_links` table with token hash, max uses, and expiry tracking.
  - Smarty templates: `home/invite-link.tpl` (link display card), `home/accept-invite.tpl` (acceptance page).
  - Tests: `InviteLinkTest` (13 tests), `InviteLinkHandlerTest` (7 tests), `InviteLinkControllerTest` (11 tests).
  - `HubServicesProvider` registration for `InviteLinkHandler` and `InviteLinkController`.
  - `docs/hub/invite-links.md` — end-user guide for invite link sharing.
  - `docs/reference/api/hub-invite-links.md` — API reference documentation.

- **Step C.9 — Shared Libraries (Friends/Family)**: Library sharing between Hub users.
  - `LibraryShare` DTO — represents a library share record with permission levels (read/readwrite), expiry, and revocation state.
  - `SharedLibraryDto` DTO — represents a library shared with the current user, including access URLs and permission level.
  - `LibrarySharingHandler` — business logic for share creation, revocation, permission updates, and listing outgoing/incoming shares.
  - `LibraryShareController` — API controller with endpoints:
    - `POST /api/v1/me/shares` — create a new library share
    - `GET /api/v1/me/shares` — list outgoing and incoming shares
    - `DELETE /api/v1/me/shares/{id}` — revoke a share
    - `PATCH /api/v1/me/shares/{id}` — update share permission
  - `migrations/009_library_shares.sql` — creates `library_shares` table with permission levels, expiry, and proper indexes.
  - SSR pages: `GET /shared-with-me` (libraries shared with you), `GET /manage-shares` (libraries you've shared).
  - Smarty templates: `home/shared-with-me.tpl`, `home/manage-shares.tpl`.
  - Tests: `LibraryShareTest` (13 tests), `LibrarySharingHandlerTest` (12 tests), `SharedLibraryDtoTest` (4 tests), `LibraryShareControllerTest` (12 tests).
  - `HubServicesProvider` registration for `LibrarySharingHandler` and `LibraryShareController`.
  - `docs/hub/shared-with-friends.md` — end-user guide for library sharing.

- **Step C.8 — Public Hostname (`*.phlix.media`)**: Subdomain allocation for enrolled servers.
  - `DnsAliasManager` — allocates deterministic 8-char subdomains (sha256 of server_id), stores in `servers.subdomain`, creates DNS records via pluggable `StaticZoneManager`.
  - `TlsCertificateManager` — read-side cert helpers (path/expiry lookups, `isProvisioned()`, `needsRenewal()`) over a configurable certs directory. NOTE: automated ACME issuance is NOT implemented — `provisionCertificate()` throws and operators install certs out-of-band (see [`docs/hub-admin/tls.md`](docs/hub-admin/tls.md)); see the "Changed" / "Known Limitations" sections above.
  - `RelayRouter` — routes inbound requests by Host header to the correct relay session based on subdomain.
  - `SubdomainController` — `POST /api/v1/servers/{id}/subdomain` (allocate/retrieve) and `DELETE /api/v1/servers/{id}/subdomain` (revoke).
  - `StaticZoneManager` — static zone file writer for DNS record management.
  - `migrations/008_subdomain_allocation.sql` — adds `subdomain` column to `servers`, creates `dns_challenges` table for ACME DNS-01.
  - Tests: `DnsAliasManagerTest` (8 tests), `TlsCertificateManagerTest` (6 tests), `RelayRouterTest` (11 tests), `SubdomainControllerTest` (6 tests).
  - `HubServicesProvider` registration for `StaticZoneManager`, `TlsCertificateManager`, `DnsAliasManager`, `RelayRouter`, `SubdomainController`.
  - `config/hub.php` keys: `dns_zone_dir`, `tls_certs_dir`, `acme_email`, `dns_provider`.

- User signup, login, logout, and `/my-servers` dashboard MVP. Routes: `GET /signup`, `POST /signup`, `GET /login`, `POST /login`, `POST /logout`, `GET /my-servers`, plus JSON variants `POST /api/v1/auth/{signup,login,logout,refresh}` and `GET /api/v1/me`.
- **Step C.4 — My Servers dashboard**: `GET /api/v1/me/servers` (JSON list of claimed servers), `DELETE /api/v1/me/servers/{id}` (remove a claimed server), `GET /api/v1/me/servers/{id}/access-info` (best direct/relay URL). SSR pages: `GET /my-servers` (server cards with status badges, last-seen, version, hostnames), `GET /claim-server` (claim-code entry form). Smarty templates: `home/my-servers.tpl`, `home/claim-server.tpl`, `partials/server-card.tpl`. Client-side `my-servers.js` handles remove-with-confirmation. CSS in `app.css` covers server cards, status badges, empty states, claim form.
- JWT auth using the shared `Phlix\Shared\Auth\JwtClaims` shape. `JwtHandler::validateAccessToken()` returns a hydrated `JwtClaims` instance — the cross-repo wire is now live.
- `AuthMiddleware` (Bearer or cookie; redirects to `/login` for HTML, 401 for JSON) and `AdminMiddleware` (gates routes on `users.is_admin`).
- `AuditLogger` writing to a new `audit` log channel (`.logs/audit.log` by default) for signup, login, logout, permission-denied, and generic auth-failure events.
- PSR-14 dispatch for `UserCreated`, `UserLoggedIn`, `UserLoggedOut` events via the shared FQCNs in `Phlix\Shared\Events\Auth\*`.
- First-user auto-promotion to admin during signup (matches phlix-server's bootstrap policy from SESSION_HANDOFF.md decision #7).
- Smarty templates under `public/templates/{layouts,auth,home}/` for the SSR pages.
- `config/auth.php` plus `HUB_JWT_SECRET`, `HUB_JWT_ACCESS_TTL`, `HUB_JWT_REFRESH_TTL` env vars.
- Two new service providers in the container: `AuthServicesProvider`, `HttpServicesProvider`.
- `scripts/smoke-jwt-roundtrip.php` — minimal smoke proving the JwtHandler ↔ JwtClaims round-trip.
- `docs/hub/signup-login.md` — end-user guide for the signup/login flow.
- Expanded `docs/dev/architecture-hub.md` with request lifecycle, auth flow Mermaid diagrams, and the JwtClaims wire description.
- `docs/reference/api/hub-auth.yaml` — OpenAPI 3.0 spec for `/api/v1/auth/*` and `/api/v1/me`.
- Database schema: `users`, `servers`, `server_claims`, `server_heartbeats`, `shared_libraries`, `relay_sessions`, `webhooks` (migrations `001_users.sql` through `005_webhooks.sql`).
- `Phlix\Hub\Common\Database\MigrationRunner` — idempotent runner backed by a `migrations` tracking table; replaces the placeholder migration runner.
- `tests/Common/Database/MigrationRunnerTest.php` — unit coverage for the runner (file discovery, idempotency, statement splitting, error wrapping).
- `tests/Unit/Migrations/MigrationFileTest.php` — static checks on every migration file (header comment, InnoDB + utf8mb4 declaration, balanced parens, `CHAR(36)` PKs).
- `tests/Integration/Migrations/MigrationRunnerIntegrationTest.php` — live-DB integration test driven by `HUB_TEST_DB_*` env vars; skipped automatically when env is missing or the cluster runs Group Replication multi-primary.
- `docs/dev/schema.md` — canonical schema reference with mermaid ER diagram and per-table documentation.
- `migrations/006_server_heartbeats_sent_at.sql` — adds nullable `sent_at DATETIME` column to `server_heartbeats` for clock-skew detection against `received_at`. Persists `HeartbeatDto::$timestamp` ahead of the C.3 heartbeat handler.

### Removed
- `migrations/001_placeholder.sql` — superseded by the real migrations.

### Fixed
- **My Servers "Last seen" stuck on "never".** `ServerInfoHandler`'s two SELECTs
  returned `last_seen_at` as a MySQL DATETIME string, but `rowToDto()` only mapped
  it when numeric, so `lastSeenAt` was always null. The column is now returned as
  `UNIX_TIMESTAMP(s.last_seen_at)` so the existing `is_numeric` → `(int)` path
  populates `lastSeenAt`.
- **My Servers "Libraries" stuck on "--".** The per-server library count cached in
  `server_libraries` was never surfaced. Both `ServerInfoHandler` SELECTs now
  include a `COUNT(*)` subquery over `server_libraries`, and `rowToDto()` maps it
  to the new optional `ServerInfoDto.libraryCount` (phlix-shared 0.10.1).
- **Dashboard "active relays" growing without bound (~9718 with one server).**
  `RelaySessionManager::registerServer()` inserted a new `relay_sessions` row on
  every (re)connect without ever closing the prior open session, and
  `closeSession()` is not always reached (worker restart, dropped connection), so
  open rows accumulated. `registerServer()` now supersedes any prior open session
  for the server before inserting (`close_reason = 'superseded'`), and a new
  `RelaySessionManager::reapStaleSessions()` closes open sessions with no recent
  frame activity (`close_reason = 'stale'`), wired into `IdleReaper::tick()` (runs
  every 60s on the single relay worker), so the count converges on the number of
  connected servers.

### Changed
- Bumped `detain/phlix-shared` constraint to `^0.10.1` (adds optional
  `ServerInfoDto.libraryCount`).

## [0.1.0] — 2026-05-17

### Added
- Initial scaffolding: Workerman 5 HTTP application, PSR-11 container (PHP-DI 7), structured logger (Monolog 3), `/health` endpoint.
- Composer dependency on `detain/phlix-shared:^0.2` consumed via Composer VCS repository (Packagist publication deferred to v1.0).
- 5-check CI workflow (composer-validate, phpcs PSR-12, phpstan 2.x level 9, psalm v5, security audit) + phpunit.
- `migrations/` directory with placeholder; real schema lands in B.6.
- `docs/reference/env-vars.md` listing every `HUB_*` env var.
- `docs/dev/architecture-hub.md` placeholder pointing at the cross-repo design docs in `detain/phlix`.

### Notes
- DB schema and migrations land in B.6. Signup/login MVP lands in B.7.
