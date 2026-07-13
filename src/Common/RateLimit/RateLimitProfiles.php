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
 * Each surface (login / proxy / heartbeat / JWKS / relay-connect / client-mount)
 * gets its OWN {@see RateLimiter} instance registered under the matching
 * `rate_limiter.<surface>` container id — a single shared login-grade limiter
 * is wrong for everything but login (HB-4.6). {@see defaults()} maps each
 * container id to the `config/server.php` `rate_limit.<key>` override key plus
 * the per-worker default `{max, window}`.
 *
 * Thresholds are PER-WORKER for five of the six surfaces. `proxy`, `heartbeat`
 * and `jwks` are enforced on the `HUB_WORKERS` HTTP workers, so each keeps an
 * INDEPENDENT per-worker counter and the effective soft-global limit is roughly
 * `max × HUB_WORKERS` (mirrors HB-3.4); `relay_connect` (the :8802 `RelayWorker`)
 * and `client_mount` (the :8803 `ClientRelayWorker`) run on count=1 surfaces
 * where per-worker == global.
 *
 * ✅ `login` is the EXCEPTION — it is genuinely global. It is enforced in
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
        ];
    }
}
