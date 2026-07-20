<?php

declare(strict_types=1);

$hubPort = (int) (getenv('HUB_PORT') ?: 8800);

// Public-facing base URL of the hub. This is baked into the enrollment
// JWT and the JWKS URL handed to enrolled servers, so it MUST be reachable
// from those servers — not the in-process listen address. Precedence:
//   1. HUB_BASE_URL (explicit override, incl. scheme),
//   2. https://<HUB_PUBLIC_DOMAIN> when a public domain is configured,
//   3. http://localhost:<port> as the single-host / dev fallback.
$hubPublicDomain = getenv('HUB_PUBLIC_DOMAIN') ?: '';
$hubBaseUrl = getenv('HUB_BASE_URL')
    ?: ($hubPublicDomain !== ''
        ? 'https://' . $hubPublicDomain
        : 'http://localhost:' . $hubPort);

return [
    'host'          => getenv('HUB_HOST') ?: '0.0.0.0',
    'port'          => $hubPort,
    'workers'       => (int) (getenv('HUB_WORKERS') ?: 2),
    'workerman_log' => getenv('HUB_WORKERMAN_LOG') ?: __DIR__ . '/../.logs/workerman.log',

    // PID file for graceful reload (SIGUSR2). SINGLE SOURCE OF TRUTH: start.php
    // assigns `Worker::$pidFile` FROM THIS VALUE, and the DI container hands the
    // same value to HubRestartController, so the writer and the reader can never
    // drift again. The default must stay inside the install's var/ directory:
    // the hardened systemd unit mounts the install root read-only and only lists
    // .logs/, var/ and config/ in ReadWritePaths, so a /var/run/... default is
    // both unwritable by Workerman and non-existent for the restart endpoint
    // (which then always 500s with pid_file_not_found).
    'pid_file'      => getenv('HUB_PID_FILE') ?: dirname(__DIR__) . '/var/hub.pid',
    'status_file'   => getenv('HUB_STATUS_FILE') ?: dirname(__DIR__) . '/var/hub.status',

    // Public domain used to build relay URLs for subdomain-allocated servers.
    // Each enrolled server gets `<subdomain>.<public_domain>` (see migration
    // 008 and `Phlix\Hub\Hub\DnsAliasManager`).
    'public_domain' => getenv('HUB_PUBLIC_DOMAIN') ?: 'phlix.media',

    // Public base URL (scheme + host) the hub advertises to enrolled servers
    // for heartbeats and JWKS fetches. See $hubBaseUrl derivation above.
    'hub_base_url'  => $hubBaseUrl,

    // Enrollment-JWT lifetime (seconds). REAL CONSUMER:
    // Phlix\Hub\Hub\EnrollmentJwtService::createEnrollmentJwt() resolves the
    // *effective* value (hub_settings override → this default) on every mint,
    // so an admin override applies to the next enrolled/renewed server with no
    // restart. Exposed as the `server.enrollment_ttl` hub setting.
    'enrollment_ttl' => (int) (getenv('HUB_ENROLLMENT_TTL') ?: 604800),

    // Reverse-tunnel relay tuning.
    'relay' => [
        // Grace window (seconds) an incumbent tunnel keeps draining in-flight
        // requests after a VALIDATED server reconnect displaces it (H-R6), so a
        // deploy/network blip does not instantly kill active playback. `0`
        // disables the drain (immediate hard displacement).
        'reconnect_drain_grace_seconds' => is_numeric(getenv('HUB_RELAY_RECONNECT_DRAIN_GRACE') ?: '')
            ? (float) getenv('HUB_RELAY_RECONNECT_DRAIN_GRACE')
            : 5.0,
    ],

    // Relay worker TLS (wss://) settings.
    // Enable TLS on port 8802 for secure WebSocket connections.
    'relay_tls' => filter_var(getenv('HUB_RELAY_TLS') ?: 'false', FILTER_VALIDATE_BOOLEAN),
    'relay_tls_cert' => getenv('HUB_RELAY_TLS_CERT') ?: null,
    'relay_tls_key' => getenv('HUB_RELAY_TLS_KEY') ?: null,

    // Sonarr/Radarr endpoints used by the request UI.
    // See \Phlix\Shared\Arr\ArrClientFactory for the expected shape.
    'arr' => [
        'sonarr' => [
            'url'     => getenv('HUB_SONARR_URL') ?: 'http://localhost:8989',
            'api_key' => getenv('HUB_SONARR_API_KEY') ?: '',
            'enabled' => filter_var(getenv('HUB_SONARR_ENABLED') ?: '0', FILTER_VALIDATE_BOOLEAN),
        ],
        'radarr' => [
            'url'     => getenv('HUB_RADARR_URL') ?: 'http://localhost:7878',
            'api_key' => getenv('HUB_RADARR_API_KEY') ?: '',
            'enabled' => filter_var(getenv('HUB_RADARR_ENABLED') ?: '0', FILTER_VALIDATE_BOOLEAN),
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
        $envInt = static fn (string $k, int $d): int => is_numeric(getenv($k) ?? '') ? (int) getenv($k) : $d;

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
        $envInt = static fn (string $k, int $d): int => is_numeric(getenv($k) ?? '') ? (int) getenv($k) : $d;

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
        ];
    })(),
];
