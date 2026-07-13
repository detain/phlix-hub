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
 * Thresholds are PER-WORKER. Only `relay_connect` (the :8802 `RelayWorker`) and
 * `client_mount` (the :8803 `ClientRelayWorker`) run on count=1 surfaces where
 * per-worker == global. `login`, `proxy`, `heartbeat` and `jwks` are all enforced
 * on the `HUB_WORKERS` HTTP workers, so each keeps an INDEPENDENT per-worker
 * counter and the effective soft-global limit is roughly `max × HUB_WORKERS`
 * (mirrors HB-3.4).
 *
 * ⚠️ `login` is therefore NOT a global 5/900 bucket: it is enforced in
 * {@see \Phlix\Hub\Auth\AuthManager} (keyed `auth:login:<ip>`) on the HTTP
 * workers, so with `HUB_WORKERS=4` the real budget is ~`5 × HUB_WORKERS` / 900 and
 * the first 429 lands around attempt ~9 rather than 5 — a genuine brute-force
 * weakening (HB-4.6 follow-up). Migration `040_login_rate_limit` adds a shared
 * DB-backed store to unify the login bucket across workers; until the
 * forthcoming `DbRateLimiter` is built and the {@see LOGIN} binding is repointed
 * to it, the login limiter remains per-worker in-memory.
 *
 * @package Phlix\Hub\Common\RateLimit
 */
final class RateLimitProfiles
{
    /** Container id for the login limiter (5 / 900s — unchanged). */
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
