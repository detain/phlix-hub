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
    'workerman_log' => $envStr('HUB_WORKERMAN_LOG', __DIR__ . '/../.logs/workerman.log'),

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
    ],

    // Relay worker TLS (wss://) settings.
    // Enable TLS on port 8802 for secure WebSocket connections.
    'relay_tls' => filter_var(($v = getenv('HUB_RELAY_TLS')) !== false && $v !== '' ? $v : 'false', FILTER_VALIDATE_BOOLEAN),
    'relay_tls_cert' => ($v = getenv('HUB_RELAY_TLS_CERT')) !== false && $v !== '' ? $v : null,
    'relay_tls_key' => ($v = getenv('HUB_RELAY_TLS_KEY')) !== false && $v !== '' ? $v : null,

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

    // Metrics / live-traffic telemetry (S4). See config/metrics.php for defaults;
    // this array is threaded into the DI container and also read directly by
    // HubServicesProvider::boot() for the flush timer interval.
    'metrics' => (static function (): array {
        // NB: must NOT be written `in_array(...) ?: $d` — in_array() returns
        // false for "false"/"0"/"no"/"off", and `false ?: $d` then falls back to
        // the default, so that form can NEVER return false (it hard-wires the
        // flag on and defeats PHLIX_HUB_METRICS_ENABLED=false). Explicit branch:
        // unset/empty → default, otherwise honour the truthy/falsy value.
        $envBool = static function (string $k, bool $d): bool {
            $raw = getenv($k);
            if ($raw === false || $raw === '') {
                return $d;
            }
            return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
        };
        $envInt = static fn (string $k, int $d): int => is_numeric($v = getenv($k)) ? (int) $v : $d;

        return [
            'enabled'                  => $envBool('PHLIX_HUB_METRICS_ENABLED', true),
            'flush_interval_seconds'   => $envInt('PHLIX_HUB_METRICS_FLUSH_INTERVAL', 5),
            'bucket_seconds'           => $envInt('PHLIX_HUB_METRICS_BUCKET_SECONDS', 10),
            'retention_days'           => $envInt('PHLIX_HUB_METRICS_RETENTION_DAYS', 7),
            'connection_ttl_seconds'   => $envInt('PHLIX_HUB_METRICS_CONNECTION_TTL', 15),
            'route_cardinality_cap'    => $envInt('PHLIX_HUB_METRICS_ROUTE_CAP', 200),
            'latency_buckets_ms'       => [10, 50, 100, 250, 500, 1000, 2500, 5000],
        ];
    })(),

    // Per-surface rate limiting (HB-4.6). Each surface gets its OWN RateLimiter
    // (see Phlix\Hub\Common\RateLimit\RateLimitProfiles); a single login-grade
    // 5/900 limiter is wrong for everything but login. Thresholds are PER-WORKER
    // for five surfaces: proxy/heartbeat/jwks run across the HUB_WORKERS HTTP
    // workers, so each keeps an independent per-worker counter and the soft-global
    // limit is ~max × HUB_WORKERS (mirrors HB-3.4); relay_connect (:8802) and
    // client_mount (:8803) run on count=1 surfaces (per-worker == global).
    // ✅ login is the EXCEPTION — genuinely global: its binding is repointed to
    // the shared DB-backed DbRateLimiter (table login_rate_limit, migration
    // 040_login_rate_limit), so ALL workers share one counter and the 5/900
    // budget is ACTUALLY 5/900, not ~5×HUB_WORKERS/900 as before (HB-4.6
    // "Option B"). Any surface's `max`/`window` — and the shared `cap` (key-count
    // ceiling) — is env-overridable; absent keys fall back to the
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
            // :8803 client-mount (WS) — keyed by IP (+ serverId).
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
        ];
    })(),
];
