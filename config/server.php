<?php

declare(strict_types=1);

$envStr = static fn (string $k, string $d): string => ($v = getenv($k)) !== false && $v !== '' ? $v : $d;
$envInt = static fn (string $k, int $d): int => is_numeric($v = getenv($k)) ? (int) $v : $d;
$envFloat = static fn (string $k, float $d): float => is_numeric($v = getenv($k)) ? (float) $v : $d;

$hubPort = $envInt('HUB_PORT', 8800);

// Public-facing base URL of the hub. This is baked into the enrollment
// JWT and the JWKS URL handed to enrolled servers, so it MUST be reachable
// from those servers — not the in-process listen address. Precedence:
//   1. HUB_BASE_URL (explicit override, incl. scheme),
//   2. https://<HUB_PUBLIC_DOMAIN> when a public domain is configured,
//   3. http://localhost:<port> as the single-host / dev fallback.
$hubPublicDomain = $envStr('HUB_PUBLIC_DOMAIN', '');
$hubBaseUrl = ($v = getenv('HUB_BASE_URL')) !== false && $v !== ''
    ? $v
    : ($hubPublicDomain !== ''
        ? 'https://' . $hubPublicDomain
        : 'http://localhost:' . $hubPort);

return [
    'host'          => $envStr('HUB_HOST', '0.0.0.0'),
    'port'          => $hubPort,
    'workers'       => $envInt('HUB_WORKERS', 2),

    // R14-15 — auxiliary listener ports. Before this block these keys were
    // read at their call sites ONLY through `?? <default>` fallbacks that no
    // config ever set — configurability theater. Each is now wired through
    // the $envInt seam with the defaults byte-identical to the literals the
    // consumers fall back to, so behavior with the env unset is unchanged:
    //   8802 relay-ws worker (server tunnels)       — Application relay site
    //   8803 client relay    (remote client sockets) — ClientRelayWorker::DEFAULT_PORT
    //   8804 syncplay relay  (watch-party clients)   — SyncPlayRelayWorker::DEFAULT_PORT
    //   8805 federation link (hub-to-hub WS; wss:// opt-in) — FederationWorker::DEFAULT_PORT
    //   2206 channel broker  (cross-process RPC)     — RelayProxyProtocol::DEFAULT_CHANNEL_PORT
    'relay_port'        => $envInt('HUB_RELAY_PORT', 8802),
    'client_relay_port' => $envInt('HUB_CLIENT_RELAY_PORT', 8803),
    'syncplay_port'     => $envInt('HUB_SYNCPLAY_PORT', 8804),
    'federation_port'   => $envInt('HUB_FEDERATION_PORT', 8805),
    'channel_port'      => $envInt('HUB_CHANNEL_PORT', 2206),

    // Swoole coroutine runtime (R14-01/R16-01). Consumed by start.php's
    // master gate and by every worker's CoroutineHooks::apply through
    // src/Runtime/SwooleRuntime.php. The hub ports the server's curated hook
    // ALLOWLIST: SWOOLE_HOOK_ALL crashed workers with general-protection
    // faults inside swoole.so (exit status 139) on PHP 8.5 / Swoole 6.2.1 /
    // kernel-7 io_uring. Only socket/sleep/stream hooks stay enabled; the
    // per-worker behavioral probe (HookDelivery) is the delivery verdict.
    'coroutine' => [
        // Set false to disable the coroutine runtime hook entirely while keeping
        // Swoole as the event loop — the most conservative option.
        'enabled' => true,
        // Override the curated default with an explicit SWOOLE_HOOK_* bitmask if
        // a specific hook must be re-enabled, e.g.
        //   'hook_flags' => SWOOLE_HOOK_ALL & ~SWOOLE_HOOK_FILE,
        // Leave unset to use SwooleRuntime::safeHookFlags().
        // 'hook_flags' => null,
    ],

    // Per-user server quota. `0` (default) = unlimited, preserving the
    // pre-setting behavior where claim codes inserted servers with no cap.
    // Load-bearing twin: `server.max_servers_per_user` in ALLOWED_KEYS; LIVE
    // consumer: ClaimRequestHandler::handleClaimCode() pre-INSERT count.
    'max_servers_per_user' => $envInt('HUB_MAX_SERVERS_PER_USER', 0),
    // Per-server collaborator quota (distinct active users shared into a
    // server's libraries). `0` (default) = unlimited. Load-bearing twin:
    // `server.max_users_per_server` in ALLOWED_KEYS; LIVE consumer:
    // LibrarySharingHandler::shareLibrary() INSERT-branch count.
    'max_users_per_server' => $envInt('HUB_MAX_USERS_PER_SERVER', 0),
    // Workerman daemon log. SINGLE SOURCE OF TRUTH: start.php assigns
    // `Worker::$logFile` FROM THIS VALUE (SH-059 — before that pin this key
    // had zero consumers and HUB_WORKERMAN_LOG was a documented dead knob).
    // Keep the default inside .logs/: the hardened systemd unit only lists
    // .logs/, var/ and config/ in ReadWritePaths, and the install.sh logrotate
    // drop-in rotates THIS default path — a custom value needs its own unit.
    'workerman_log' => $envStr('HUB_WORKERMAN_LOG', __DIR__ . '/../.logs/workerman.log'),

    // SH-050: the skill identity the /alexa/skill gate pins every verified
    // request to. The signature chain proves a request came from AMAZON; this
    // value is what proves it was addressed to THIS skill (any Echo developer
    // can point any skill at any endpoint, and their requests carry valid
    // Amazon signatures). Copy it from the Skills Console — it is printed in
    // JSON under "Skill Endpoint Information" as applicationId.
    // Nullable on purpose: unset on dev/test hubs means "identity gate
    // inactive" (PHPUnit posture, config/database.php's dev-detection family);
    // unset on a PRODUCTION boot is a fail-closed rejection of every request,
    // resolved by HubServicesProvider — the same "misconfiguration is loud,
    // not permissive" doctrine as the JWT secret.
    'alexa_application_id' => ($v = getenv('HUB_ALEXA_APPLICATION_ID')) !== false && $v !== '' ? $v : null,

    // PID file for graceful reload (SIGUSR2). SINGLE SOURCE OF TRUTH: start.php
    // assigns `Worker::$pidFile` FROM THIS VALUE, and the DI container hands the
    // same value to HubRestartController, so the writer and the reader can never
    // drift again. The default must stay inside the install's var/ directory:
    // the hardened systemd unit mounts the install root read-only and only lists
    // .logs/, var/ and config/ in ReadWritePaths, so a /var/run/... default is
    // both unwritable by Workerman and non-existent for the restart endpoint
    // (which then always 500s with pid_file_not_found).
    'pid_file'      => $envStr('HUB_PID_FILE', dirname(__DIR__) . '/var/hub.pid'),
    'status_file'   => $envStr('HUB_STATUS_FILE', dirname(__DIR__) . '/var/hub.status'),

    // S312 — the dedicated maintenance worker's own liveness record.
    //
    // The maintenance worker is a SEPARATE FORK from the HTTP workers that
    // answer /health, so it cannot be observed in-process: this file is how it
    // tells them it is still completing sweeps. Written by
    // `Phlix\Hub\Health\MaintenanceHeartbeat` from inside the guarded sweep
    // callback, read by `Phlix\Hub\Health\HealthController`.
    //
    // It lives in var/ for the same reason pid_file/status_file do: under the
    // hardened systemd unit the install root is read-only and only .logs/, var/
    // and config/ are ReadWritePaths. In the container image var/ is created and
    // chowned to `nobody` by the Dockerfile.
    //
    // ⚠ Do NOT move this onto a tmpfs that is not shared between the forks — the
    // whole point is that a DIFFERENT process reads what the maintenance worker
    // wrote.
    'maintenance_heartbeat_file' => $envStr(
        'HUB_MAINTENANCE_HEARTBEAT_FILE',
        dirname(__DIR__) . '/var/maintenance-heartbeat.json',
    ),

    // Seconds without a COMPLETED maintenance sweep before /health calls the
    // maintenance worker down (and the container stops reporting `healthy`).
    // Default 180 = 3 x config/process.php's `maintenance.poll_seconds`.
    'maintenance_stale_seconds' => $envInt('HUB_MAINTENANCE_STALE_SECONDS', 180),

    // Days of `audit_logs` history the maintenance reaper retains (R19-11).
    // REAL CONSUMER: Phlix\Hub\ServerReaper::sweepAuditLogs(), armed on the
    // existing reaper timer inside the count=1 maintenance worker (fork-safe:
    // no new timer). Deliberately an ENV knob, not a `hub_settings` key — the
    // settings allow-list lives in the phlix-shared schema and adding a key
    // there crosses repos for a dial only an operator (not the admin UI)
    // needs. Default 365 days; the table was previously INSERT-only and
    // unbounded. Literal 365 mirrors ServerReaper::DEFAULT_AUDIT_RETENTION_DAYS
    // (config files must stay loadable without class autoload).
    // SAFETY: this seam forwards ANY numeric verbatim (0, -5 included) — the
    // consumer clamps: ServerReaper normalizes non-positive/oversized windows
    // back to the default and logs a warning, so a mis-set 0 can never arm
    // the delete-everything sweep. See ServerReaper::normalizeRetentionDays().
    'audit_retention_days' => $envInt('HUB_AUDIT_RETENTION_DAYS', 365),

    // Public domain used to build relay URLs for subdomain-allocated servers.
    // Each enrolled server gets `<subdomain>.<public_domain>` (see migration
    // 008 and `Phlix\Hub\Hub\DnsAliasManager`).
    'public_domain' => $envStr('HUB_PUBLIC_DOMAIN', 'phlix.media'),

    // Public base URL (scheme + host) the hub advertises to enrolled servers
    // for heartbeats and JWKS fetches. See $hubBaseUrl derivation above.
    'hub_base_url'  => $hubBaseUrl,

    // Enrollment-JWT lifetime (seconds). REAL CONSUMER:
    // Phlix\Hub\Hub\EnrollmentJwtService::createEnrollmentJwt() resolves the
    // *effective* value (hub_settings override → this default) on every mint,
    // so an admin override applies to the next enrolled/renewed server with no
    // restart. Exposed as the `server.enrollment_ttl` hub setting.
    'enrollment_ttl' => $envInt('HUB_ENROLLMENT_TTL', 604800),

    // ---- MCP (Model Context Protocol) -----------------------------------
    // The `playback_control` MCP tool (S63). DEFAULT OFF, and it must stay that
    // way unless an operator opts in: it is the only MCP tool that changes
    // server-side state, and the casting backends it drives (Chromecast / Roku /
    // AirPlay) are NOT production-functional, so a call can be accepted and have
    // no effect on any device. When this is false the tool is not registered at
    // all — it appears in no `tools/list` and a `tools/call` naming it is
    // `mcp.unknown_tool`. Read by
    // `Common\Container\Providers\HubServicesProvider::mcpPlaybackControlEnabled()`,
    // which compares with `=== true`, which is why this is resolved to a real
    // bool here rather than left as the raw env string.
    // A token ALSO needs the `mcp:playback:control` scope; the flag and the
    // scope are independent gates and neither substitutes for the other.
    //
    // 🚨 BEFORE YOU SET THIS TO true, READ THIS (S261).
    // Turning this on does not only publish a tool. It ALSO makes the
    // `mcp:playback:control` scope meaningful on every token that already holds
    // it — retroactively, with no mint, no notification and no audit event.
    // Until S261 that scope was granted BY DEFAULT: `McpTokenController::create()`
    // fell back to `McpScopes::all()` whenever the request omitted `scopes`, and
    // the `/app/mcp-tokens` create form pre-ticked every advertised scope. So a
    // token minted before 2026-08-07 very likely carries the write scope
    // whether or not its owner ever wanted it.
    // S261 DELIBERATELY DID NOT STRIP THOSE ROWS — the stored `scopes` column
    // cannot tell an explicit grant from a defaulted one, so a blanket UPDATE
    // would silently revoke a capability some owners did choose, trading one
    // silent change for another. The decision was to leave the data alone and
    // put the review here, at the moment the flag is flipped. Review first:
    //
    //   SELECT id, user_id, name, created_at, last_used_at
    //     FROM mcp_tokens
    //    WHERE revoked_at IS NULL
    //      AND expires_at > NOW()
    //      AND FIND_IN_SET('mcp:playback:control', REPLACE(scopes, ' ', ','));
    //
    // (`FIND_IN_SET` over the space-delimited column matches WHOLE scopes;
    // a `LIKE '%mcp:playback%'` would also match `mcp:playback:read`.)
    // Revoke anything on that list you did not intend to grant — the owner can
    // re-mint, and with this flag on the create form will offer the scope
    // explicitly.
    'mcp_playback_control_enabled' => filter_var(
        ($v = getenv('HUB_MCP_PLAYBACK_CONTROL')) !== false && $v !== '' ? $v : 'false',
        FILTER_VALIDATE_BOOLEAN,
    ),

    // `GET /mcp` SSE stream tuning (S63). The keep-alive comment interval and
    // the hard lifetime ceiling, both in seconds. The ceiling exists so an
    // abandoned stream is reclaimed inside a resident-memory worker; a
    // conformant client reconnects on the `retry:` hint, so it is invisible.
    'mcp_sse_keepalive_seconds' => $envInt('HUB_MCP_SSE_KEEPALIVE', 15),
    'mcp_sse_max_seconds'       => $envInt('HUB_MCP_SSE_MAX_SECONDS', 900),

    // Reverse-tunnel relay tuning.
    'relay' => [
        // Grace window (seconds) an incumbent tunnel keeps draining in-flight
        // requests after a VALIDATED server reconnect displaces it (H-R6), so a
        // deploy/network blip does not instantly kill active playback. `0`
        // disables the drain (immediate hard displacement).
        'reconnect_drain_grace_seconds' => $envFloat('HUB_RELAY_RECONNECT_DRAIN_GRACE', 5.0),
        // HOST-3 (H3-3): deadline (seconds) an inbound WebSocket listener of
        // the governed trio — :8802 relay, :8803 client-mount, :8804
        // SyncPlay — waits for the HTTP `Upgrade` request after TCP-accept
        // (TLS handshake included when the surface speaks wss://). A client
        // that completes transport then goes silent used to be held
        // ~indefinitely (10 min observed live); the hub now closes it and
        // logs `reason=handshake_timeout`. Default 30 s clears the worst
        // legitimate case (a TCP retransmit storm reaches ≈ 9-11 s before the
        // upgrade lands — REVIEW-HUB-GUARD §5), while the stall class this
        // defends against holds sockets for MINUTES (10 min observed live);
        // `0` disables the deadline
        // (same "`0` = off" law as the drain grace beside it); a negative
        // value is a typo, not a policy → parsed to the default. Federation
        // :8805 is governed too (HUB-S32): same deadline, same env knob.
        // Consumer: \Phlix\Hub\Relay\InboundHandshakeGuard via Application.
        'ws_handshake_timeout_seconds' => $envFloat('HUB_RELAY_WS_HANDSHAKE_TIMEOUT_SECONDS', 30.0),
    ],

    // Relay worker TLS (wss://) settings.
    // Enable TLS on port 8802 for secure WebSocket connections.
    'relay_tls' => filter_var(($v = getenv('HUB_RELAY_TLS')) !== false && $v !== '' ? $v : 'false', FILTER_VALIDATE_BOOLEAN),
    'relay_tls_cert' => ($v = getenv('HUB_RELAY_TLS_CERT')) !== false && $v !== '' ? $v : null,
    'relay_tls_key' => ($v = getenv('HUB_RELAY_TLS_KEY')) !== false && $v !== '' ? $v : null,
    // M21: listener-side mirror of the server's fail-closed knob
    // (phlix-server config/relay.php `require_tls` / PHLIX_RELAY_REQUIRE_TLS,
    // R17-01). When true (HUB_RELAY_REQUIRE_TLS=1), RelayWorker::start()
    // REFUSES to bind :8802 plaintext — the tunnel carries each server's
    // Ed25519 enrollment JWT (a durable tunnel bearer) and all relayed HTTP
    // in the clear, and, unlike :8803, is not fronted by HAProxy.
    // Default false = warn-only: one loud boot advisory when the socket comes
    // up ws://, byte-for-byte today's bind behavior plus the announcement.
    'relay_require_tls' => filter_var(($v = getenv('HUB_RELAY_REQUIRE_TLS')) !== false && $v !== '' ? $v : 'false', FILTER_VALIDATE_BOOLEAN),

    // Federation worker (hub-to-hub :8805) TLS (wss://) settings — M22, the
    // listen-side sibling of the relay block above (review hub__secnet M-3).
    // The listener historically bound plaintext ws:// with no native TLS path
    // at all, delegating link confidentiality to an upstream proxy nothing in
    // the repo verifies. These keys give it the same tri-state as :8802:
    // opt-in native wss:// (federation_tls + cert/key) and a fail-closed
    // mandate (federation_require_tls) that refuses a plaintext bind outright;
    // default false keeps warn-only with the boot advisory (estate law: a
    // mandate flip moves both sides of a link in one wave, never one alone —
    // and the managed install's proxy-terminated :8805 is the documented
    // common production shape a default-on mandate would break).
    'federation_tls' => filter_var(($v = getenv('HUB_FEDERATION_TLS')) !== false && $v !== '' ? $v : 'false', FILTER_VALIDATE_BOOLEAN),
    'federation_tls_cert' => ($v = getenv('HUB_FEDERATION_TLS_CERT')) !== false && $v !== '' ? $v : null,
    'federation_tls_key' => ($v = getenv('HUB_FEDERATION_TLS_KEY')) !== false && $v !== '' ? $v : null,
    'federation_require_tls' => filter_var(($v = getenv('HUB_FEDERATION_REQUIRE_TLS')) !== false && $v !== '' ? $v : 'false', FILTER_VALIDATE_BOOLEAN),

    // SH-064 (review hub__secnet#1): the M21/M22 posture family extended to the
    // two CLIENT-facing WS surfaces that never got it — :8803 (client relay;
    // browsers present their relay bearer token on it) and :8804 (SyncPlay
    // relay; join frames carry member auth tokens). Same tri-state as :8802/
    // :8805: opt-in native wss:// (+ cert/key), fail-closed mandate, default
    // false = warn-only boot advisory (estate default-off law — a mandate
    // flip moves topology docs and both tunnel sides in one owner wave).
    'client_relay_tls' => filter_var(
        ($v = getenv('HUB_CLIENT_RELAY_TLS')) !== false && $v !== '' ? $v : 'false',
        FILTER_VALIDATE_BOOLEAN,
    ),
    'client_relay_tls_cert' => ($v = getenv('HUB_CLIENT_RELAY_TLS_CERT')) !== false && $v !== '' ? $v : null,
    'client_relay_tls_key' => ($v = getenv('HUB_CLIENT_RELAY_TLS_KEY')) !== false && $v !== '' ? $v : null,
    'client_relay_require_tls' => filter_var(
        ($v = getenv('HUB_CLIENT_RELAY_REQUIRE_TLS')) !== false && $v !== '' ? $v : 'false',
        FILTER_VALIDATE_BOOLEAN,
    ),
    'syncplay_tls' => filter_var(
        ($v = getenv('HUB_SYNCPLAY_TLS')) !== false && $v !== '' ? $v : 'false',
        FILTER_VALIDATE_BOOLEAN,
    ),
    'syncplay_tls_cert' => ($v = getenv('HUB_SYNCPLAY_TLS_CERT')) !== false && $v !== '' ? $v : null,
    'syncplay_tls_key' => ($v = getenv('HUB_SYNCPLAY_TLS_KEY')) !== false && $v !== '' ? $v : null,
    'syncplay_require_tls' => filter_var(
        ($v = getenv('HUB_SYNCPLAY_REQUIRE_TLS')) !== false && $v !== '' ? $v : 'false',
        FILTER_VALIDATE_BOOLEAN,
    ),
    // Listen hosts for :8802 (relay) and :8805 (federation) — same shape and
    // default (0.0.0.0, no wire-behavior change) as ws_bind_host below.
    'relay_bind_host' => ($v = getenv('HUB_RELAY_BIND')) !== false && $v !== '' ? $v : '0.0.0.0',
    'federation_bind_host' => ($v = getenv('HUB_FEDERATION_BIND')) !== false && $v !== '' ? $v : '0.0.0.0',
    // SH-064: listen host for the client-facing pair (:8803/:8804). Default
    // '0.0.0.0' is the historical hardcoded literal — NOT flipped (an estate-
    // wide bind default change is an owner call; this key makes the documented
    // "front it with a local proxy, pin the bind" escape REAL: set it to
    // 127.0.0.1/::1 when HAProxy is loopback-local). It governs ONLY these two
    // surfaces; :8802 (relay) and :8805 (federation) use the relay_bind_host /
    // federation_bind_host knobs above.
    'ws_bind_host' => ($v = getenv('HUB_WS_BIND')) !== false && $v !== '' ? $v : '0.0.0.0',

    // Sonarr/Radarr endpoints used by the request UI.
    // See \Phlix\Shared\Arr\ArrClientFactory for the expected shape.
    'arr' => [
        'sonarr' => [
            'url'     => $envStr('HUB_SONARR_URL', 'http://localhost:8989'),
            'api_key' => $envStr('HUB_SONARR_API_KEY', ''),
            'enabled' => filter_var($envStr('HUB_SONARR_ENABLED', '0'), FILTER_VALIDATE_BOOLEAN),
        ],
        'radarr' => [
            'url'     => $envStr('HUB_RADARR_URL', 'http://localhost:7878'),
            'api_key' => $envStr('HUB_RADARR_API_KEY', ''),
            'enabled' => filter_var($envStr('HUB_RADARR_ENABLED', '0'), FILTER_VALIDATE_BOOLEAN),
        ],
    ],

    // Metrics / live-traffic telemetry (S4). THE single source is
    // config/metrics.php — requiring it here (as that file's own header
    // documents) replaces a hand-copied twin whose two copies of seven env
    // knobs could only drift. The shape metrics.php returns is key-identical
    // to the former inline block. This value is threaded into the DI container
    // and also read directly by HubServicesProvider::boot() for the flush timer
    // interval; the metrics provider's own @include fallback now lands on the
    // same file this key comes from.
    'metrics' => require __DIR__ . '/metrics.php',

    // Per-surface rate limiting (HB-4.6). Each surface gets its OWN RateLimiter
    // (see Phlix\Hub\Common\RateLimit\RateLimitProfiles); a single login-grade
    // 5/900 limiter is wrong for everything but login. Thresholds are PER-WORKER
    // for eleven surfaces: proxy/heartbeat/jwks/alexa/server_claims/invite_redeem
    // run across the HUB_WORKERS HTTP workers, so each keeps an independent
    // per-worker counter
    // and the soft-global limit is ~max × HUB_WORKERS (mirrors HB-3.4);
    // relay_connect (:8802), client_mount (:8803), syncplay_mount (:8804) and
    // the two federation surfaces (:8805, both roles) run on count=1 surfaces
    // (per-worker == global).
    // ✅ login, mcp and signup are the EXCEPTIONS — genuinely global: their
    // bindings are repointed to the shared DB-backed DbRateLimiter (table
    // login_rate_limit, migration 040_login_rate_limit), so ALL workers share
    // one counter per bucket and the budgets are ACTUAL, not
    // ~max×HUB_WORKERS as they were (HB-4.6 "Option B"). Every env-seamed
    // surface's `max`/`window` — and the shared `cap` (key-count
    // ceiling) — is env-overridable; only client_relay_token and
    // client_request_create are literal-only (no PHLIX_HUB_RATELIMIT_*
    // seam). Absent keys fall back to the
    // RateLimitProfiles defaults.
    'rate_limit' => (static function (): array {
        $envInt = static fn (string $k, int $d): int => is_numeric($v = getenv($k)) ? (int) $v : $d;

        return [
            'cap'           => $envInt('PHLIX_HUB_RATELIMIT_CAP', 10000),
            // Login: 5 attempts / 15 min — shared DB-backed (DbRateLimiter, table
            // login_rate_limit) so the budget is global across all HTTP workers.
            'login'         => [
                'max'    => $envInt('PHLIX_HUB_RATELIMIT_LOGIN_MAX', 5),
                'window' => $envInt('PHLIX_HUB_RATELIMIT_LOGIN_WINDOW', 900),
            ],
            // Proxy: generous (HLS segment bursts) — keyed by userId downstream.
            'proxy'         => [
                'max'    => $envInt('PHLIX_HUB_RATELIMIT_PROXY_MAX', 600),
                'window' => $envInt('PHLIX_HUB_RATELIMIT_PROXY_WINDOW', 60),
            ],
            // Heartbeat: ~one per server every 60s + slack.
            'heartbeat'     => [
                'max'    => $envInt('PHLIX_HUB_RATELIMIT_HEARTBEAT_MAX', 30),
                'window' => $envInt('PHLIX_HUB_RATELIMIT_HEARTBEAT_WINDOW', 60),
            ],
            // JWKS: keyed by client IP.
            'jwks'          => [
                'max'    => $envInt('PHLIX_HUB_RATELIMIT_JWKS_MAX', 120),
                'window' => $envInt('PHLIX_HUB_RATELIMIT_JWKS_WINDOW', 60),
            ],
            // :8802 server relay-connect (WS) — keyed by IP.
            'relay_connect' => [
                'max'    => $envInt('PHLIX_HUB_RATELIMIT_RELAY_CONNECT_MAX', 10),
                'window' => $envInt('PHLIX_HUB_RATELIMIT_RELAY_CONNECT_WINDOW', 60),
            ],
            // :8803 client-mount (WS) — keyed by IP only
            // (`client_mount:<ip>`, ClientRelayWorker::onWebSocketConnect).
            'client_mount'  => [
                'max'    => $envInt('PHLIX_HUB_RATELIMIT_CLIENT_MOUNT_MAX', 30),
                'window' => $envInt('PHLIX_HUB_RATELIMIT_CLIENT_MOUNT_WINDOW', 60),
            ],
            // MCP personal-access-token auth (S62): 10 FAILED presentations /
            // 15 min, keyed `mcp:auth:<ip>`. Shared DB-backed (DbRateLimiter, the
            // same store login uses) so the budget is global across HTTP workers
            // — guessing a PAT is a password guess by another name. Slightly
            // more generous than login's 5 because an agent retries on its own
            // schedule; a SUCCESSFUL call consumes nothing, so a working client
            // never approaches it.
            'mcp'           => [
                'max'    => $envInt('PHLIX_HUB_RATELIMIT_MCP_MAX', 10),
                'window' => $envInt('PHLIX_HUB_RATELIMIT_MCP_WINDOW', 900),
            ],
            // Alexa skill endpoint (S91): 60 requests / 60s, keyed
            // `alexa:<trusted client ip>` in AlexaSignatureMiddleware. PER-WORKER
            // (soft-global, ~max × HUB_WORKERS) like the other IP-keyed surfaces
            // — this is not a credential-guessing surface (Amazon's RSA signature
            // is the credential), so the limiter only exists to cap the cost of an
            // unauthenticated flood against a public endpoint: a cert-chain fetch
            // on a cache miss, plus one audit_logs row per rejection.
            'alexa'         => [
                'max'    => $envInt('PHLIX_HUB_RATELIMIT_ALEXA_MAX', 60),
                'window' => $envInt('PHLIX_HUB_RATELIMIT_ALEXA_WINDOW', 60),
            ],
            // Server-claim pairing surfaces (review hub__exploiter M1): 60
            // requests / 60s PER BUCKET, keyed `server_claims:{new,status,claim}:<trusted ip>`
            // in ServerClaimController. PER-WORKER (soft-global) — the alexa
            // posture, deliberately NOT the DB-backed login posture: the status
            // poll is UUID-bearer (2^122, unguessable; the bucket caps flood
            // cost only), the claim POST is authenticated (grinding ~2^40 codes
            // against a 600 s TTL at <=60xHUB_WORKERS/min is centuries), and a
            // DB-backed counter would add a row-write per 2 s poll to the very
            // hot path this endpoint serves. Full reasoning: RateLimitProfiles::SERVER_CLAIMS.
            'server_claims' => [
                'max'    => $envInt('PHLIX_HUB_RATELIMIT_SERVER_CLAIMS_MAX', 60),
                'window' => $envInt('PHLIX_HUB_RATELIMIT_SERVER_CLAIMS_WINDOW', 60),
            ],
            // Account signup (auth audit fix 3): 3 attempts / hour, keyed
            // `auth:signup:<ip>` in AuthManager. Shared DB-backed (DbRateLimiter,
            // same store as login) so the budget is global across HTTP workers —
            // this anonymous surface costs a 64 MiB Argon2id hash per ACCEPTED
            // attempt and creates persistent state, so unlike login it counts
            // every attempt, successes included: bounding account creation is
            // the point. Typos by a real human fit comfortably inside 3/hour;
            // an account farm does not.
            'signup'        => [
                'max'    => $envInt('PHLIX_HUB_RATELIMIT_SIGNUP_MAX', 3),
                'window' => $envInt('PHLIX_HUB_RATELIMIT_SIGNUP_WINDOW', 3600),
            ],
            // :8805 federation inbound connects, master role (review
            // hub__exploiter M3 leg 2): 10 / 60s keyed
            // `federation_connect:<trusted client ip>` in FederationWorker.
            // BEFORE the federation_peers DB read — a connect→HELLO→never-auth→
            // reconnect loop costs 3 session writes + a nonce mint + an ACK
            // signature per ceremony; a legit leaf reconnects ≤1/min (the dial
            // backoff ladder caps at 60 s), so 10/min/IP is order-of-magnitude
            // headroom that still starves the loop. Full reasoning:
            // RateLimitProfiles::FEDERATION_CONNECT.
            'federation_connect' => [
                'max'    => $envInt('PHLIX_HUB_RATELIMIT_FEDERATION_CONNECT_MAX', 10),
                'window' => $envInt('PHLIX_HUB_RATELIMIT_FEDERATION_CONNECT_WINDOW', 60),
            ],
            // :8805 federation frame budget, BOTH roles (review hub__exploiter
            // M3 legs 1+3): 120 / 60s keyed `federation_frame:<remote peer hub
            // id>` — one instance shared by the master-side listener and the
            // leaf-side outbound link (the count=1 federation worker process
            // owns both; keys are remote hub ids, no cross-role collision).
            // Bounds pre-verification DATA audit-amp and the unbounded
            // shares[] inline-INSERT fan-out at the choke point, before
            // dispatch; legit traffic is heartbeat-dominated (4/min/link)
            // with event-driven offer bursts. Full reasoning:
            // RateLimitProfiles::FEDERATION_FRAME.
            'federation_frame'   => [
                'max'    => $envInt('PHLIX_HUB_RATELIMIT_FEDERATION_FRAME_MAX', 120),
                'window' => $envInt('PHLIX_HUB_RATELIMIT_FEDERATION_FRAME_WINDOW', 60),
            ],
            // Invite-link redemption (minors campaign M-HUB-21): 20 / 60s PER
            // MEMBER, keyed `invite_redeem:<userId>` in InviteLinkController —
            // authenticated surface, server-claims posture (per-worker soft
            // global), NOT DB-backed: the bucket caps the SELECT + burn UPDATE
            // + share-write chain cost one attributable member can charge the
            // DB, and races against other members' last invite use. Full
            // reasoning: RateLimitProfiles::INVITE_REDEEM.
            'invite_redeem'      => [
                'max'    => $envInt('PHLIX_HUB_RATELIMIT_INVITE_REDEEM_MAX', 20),
                'window' => $envInt('PHLIX_HUB_RATELIMIT_INVITE_REDEEM_WINDOW', 60),
            ],
            // :8804 SyncPlay pre-auth connect budget (minors campaign M-HUB-37):
            // 30 / 60s keyed `syncplay_mount:<real client ip>` in
            // SyncPlayRelayWorker::onWebSocketConnect — the client_mount
            // posture on the sibling surface (this worker is count=1, so the
            // per-worker cap is a true global cap; the managed install fronts
            // it with HAProxy over loopback, hence the trusted-proxy resolve).
            // Before this leg the connect path had NO limiter: every
            // unauthenticated upgrade ran two SQL queries (token validate +
            // owner probe) before its close. Full reasoning:
            // RateLimitProfiles::SYNCPLAY_MOUNT.
            'syncplay_mount'     => [
                'max'    => $envInt('PHLIX_HUB_RATELIMIT_SYNCPLAY_MOUNT_MAX', 30),
                'window' => $envInt('PHLIX_HUB_RATELIMIT_SYNCPLAY_MOUNT_WINDOW', 60),
            ],
        ];
    })(),
];
