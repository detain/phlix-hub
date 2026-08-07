<?php

/**
 * Phlix hub component: RateLimit.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Common\RateLimit;

/**
 * Type-safe catalogue of the per-surface rate-limiter container ids and their
 * per-worker default thresholds.
 *
 * Each surface (login / proxy / heartbeat / JWKS / relay-connect / client-mount /
 * mcp) gets its OWN limiter instance registered under the matching
 * `rate_limiter.<surface>` container id — a single shared login-grade limiter
 * is wrong for everything but login (HB-4.6). {@see defaults()} maps each
 * container id to the `config/server.php` `rate_limit.<key>` override key plus
 * the per-worker default `{max, window}`.
 *
 * Thresholds are PER-WORKER for five of the seven surfaces. `proxy`, `heartbeat`
 * and `jwks` are enforced on the `HUB_WORKERS` HTTP workers, so each keeps an
 * INDEPENDENT per-worker counter and the effective soft-global limit is roughly
 * `max × HUB_WORKERS` (mirrors HB-3.4); `relay_connect` (the :8802 `RelayWorker`)
 * and `client_mount` (the :8803 `ClientRelayWorker`) run on count=1 surfaces
 * where per-worker == global.
 *
 * ✅ `login` and `mcp` are the EXCEPTIONS — both are genuinely global; both are
 * bound to the shared DB-backed {@see DbRateLimiter}. The `login` reasoning
 * follows; `mcp` (S62) is the same threat (guessing a bearer credential) reached
 * through {@see \Phlix\Hub\Http\Controllers\McpController} and keyed
 * `mcp:auth:<ip>`.
 *
 * `login` is enforced in
 * {@see \Phlix\Hub\Auth\AuthManager} (keyed `auth:login:<ip>`) on the HTTP
 * workers, but its {@see LOGIN} binding is repointed (in
 * {@see \Phlix\Hub\Common\Container\Providers\CommonServicesProvider}) to the
 * shared, DB-backed {@see DbRateLimiter} (table `login_rate_limit`, migration
 * `040_login_rate_limit`), so ALL workers share one counter per key and the
 * 5 / 900 budget is ACTUALLY 5 / 900 — not the ~`5 × HUB_WORKERS` / 900 it was
 * (e.g. ~20 / 900 with 4 workers, first 429 near attempt ~9) while every surface
 * used the worker-local {@see RateLimiter} (HB-4.6 "Option B"). This closes the
 * one surface where per-worker weakening was a genuine brute-force concern; the
 * other five stay worker-local (soft-global) by design.
 *
 * @package Phlix\Hub\Common\RateLimit
 */
final class RateLimitProfiles
{
    /** Container id for the login limiter (5 / 900s; shared DB-backed {@see DbRateLimiter}). */
    public const string LOGIN = 'rate_limiter.login';

    /** Container id for the reverse-proxy limiter (generous, userId-keyed). */
    public const string PROXY = 'rate_limiter.proxy';

    /** Container id for the server-heartbeat limiter. */
    public const string HEARTBEAT = 'rate_limiter.heartbeat';

    /** Container id for the JWKS endpoint limiter. */
    public const string JWKS = 'rate_limiter.jwks';

    /** Container id for the :8802 server relay-connect limiter. */
    public const string RELAY_CONNECT = 'rate_limiter.relay_connect';

    /** Container id for the :8803 client-mount limiter. */
    public const string CLIENT_MOUNT = 'rate_limiter.client_mount';

    /**
     * Container id for the MCP personal-access-token auth limiter (S62).
     *
     * ✅ The SECOND genuinely global surface, and it is bound to the same shared
     * {@see DbRateLimiter} as {@see LOGIN} for the same reason: presenting a
     * bearer PAT to `POST /mcp` is a password guess by another name, and a
     * per-worker counter would hand an attacker ~`max × HUB_WORKERS` tries per
     * window. It is a separate PROFILE rather than a reuse of the login bucket
     * so an operator can tune the two independently (an MCP agent legitimately
     * retries more than a human logging in), but it is the same MECHANISM — no
     * second limiter was invented for this surface.
     *
     * Keyed `mcp:auth:<ip>` and, exactly like login, incremented only on a
     * FAILED validation: a working agent making thousands of tool calls never
     * consumes budget.
     */
    public const string MCP = 'rate_limiter.mcp';

    /**
     * Map of `container id => {config key, default max, default window}`.
     *
     * `key` is the sub-key under `config/server.php`'s `rate_limit` section;
     * `max`/`window` are the per-worker defaults applied when that key (or its
     * `max`/`window`) is absent.
     *
     * @return array<string, array{key: string, max: int, window: int}>
     */
    public static function defaults(): array
    {
        return [
            self::LOGIN         => ['key' => 'login',         'max' => 5,   'window' => 900],
            self::PROXY         => ['key' => 'proxy',         'max' => 600, 'window' => 60],
            self::HEARTBEAT     => ['key' => 'heartbeat',     'max' => 30,  'window' => 60],
            self::JWKS          => ['key' => 'jwks',          'max' => 120, 'window' => 60],
            self::RELAY_CONNECT => ['key' => 'relay_connect', 'max' => 10,  'window' => 60],
            self::CLIENT_MOUNT  => ['key' => 'client_mount',  'max' => 30,  'window' => 60],
            self::MCP           => ['key' => 'mcp',           'max' => 10,  'window' => 900],
        ];
    }
}
