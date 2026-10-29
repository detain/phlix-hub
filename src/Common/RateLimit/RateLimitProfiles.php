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
 * mcp / alexa / signup / server-claims) gets its OWN limiter instance registered
 * under the matching `rate_limiter.<surface>` container id — a single shared
 * login-grade limiter is wrong for everything but login (HB-4.6).
 * {@see defaults()} maps each container id to the `config/server.php`
 * `rate_limit.<key>` override key plus the per-worker default `{max, window}`.
 *
 * Thresholds are PER-WORKER for seven of the ten surfaces. `proxy`, `heartbeat`,
 * `jwks`, `alexa` and `server_claims` are enforced on the `HUB_WORKERS` HTTP
 * workers, so each keeps an INDEPENDENT per-worker counter and the effective
 * soft-global limit is roughly `max × HUB_WORKERS` (mirrors HB-3.4);
 * `relay_connect` (the :8802 `RelayWorker`) and `client_mount` (the :8803
 * `ClientRelayWorker`) run on count=1 surfaces where per-worker == global.
 *
 * ✅ `login`, `mcp` and `signup` are the EXCEPTIONS — all genuinely global; all
 * bound to the shared DB-backed {@see DbRateLimiter}. The `login` reasoning
 * follows; `mcp` (S62) is the same threat (guessing a bearer credential) reached
 * through {@see \Phlix\Hub\Http\Controllers\McpController} and keyed
 * `mcp:auth:<ip>`; `signup` (auth audit fix 3) caps the ANONYMOUS account-
 * creation surface — every attempt costs a 64 MiB Argon2id hash, so a
 * per-worker multiplier would multiply both the account-farm budget and the
 * DoS amplification.
 *
 * `login` is enforced in
 * {@see \Phlix\Hub\Auth\AuthManager} (keyed `auth:login:<ip>`) on the HTTP
 * workers, but its {@see LOGIN} binding is repointed (in
 * {@see \Phlix\Hub\Common\Container\Providers\CommonServicesProvider}) to the
 * shared, DB-backed {@see DbRateLimiter} (table `login_rate_limit`, migration
 * `040_login_rate_limit`), so ALL workers share one counter per key and the
 * 5 / 900 budget is ACTUALLY 5 / 900 — not the ~`5 × HUB_WORKERS` / 900 it was
 * (e.g. ~20 / 900 with 4 workers, first 429 near attempt ~9) while every surface
 * used the worker-local {@see RateLimiter} (HB-4.6 "Option B"). This closed the
 * one surface where per-worker weakening was a genuine brute-force concern; the
 * other six stay worker-local (soft-global) by design.
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
     * Container id for the Alexa skill endpoint limiter (S91).
     *
     * PER-WORKER (soft-global), like the other IP-keyed surfaces above:
     * `POST /alexa/skill` is served by the `HUB_WORKERS` HTTP workers, each of
     * which keeps an INDEPENDENT counter, so the effective limit is roughly
     * `max × HUB_WORKERS` per window. That is deliberate and is NOT the
     * `login`/`mcp` situation: nothing about this surface is a bearer-credential
     * guess — Amazon's RSA signature is the credential, and it cannot be brute
     * forced by repetition. The limiter exists to cap the cost of an unauthenticated
     * flood against a public endpoint (a cert-chain fetch on a cache miss, and one
     * `audit_logs` row per rejection), for which a soft-global bound is enough.
     *
     * Keyed `alexa:<trusted client ip>` inside
     * {@see \Phlix\Hub\Http\Middleware\AlexaSignatureMiddleware}, which resolves
     * the caller through `TRUSTED_PROXIES` — an IP-keyed limiter reading
     * `remoteIp` directly would bucket the HAProxy front, not the caller.
     */
    public const string ALEXA = 'rate_limiter.alexa';

    /**
     * Container id for the account-signup limiter.
     *
     * ✅ The THIRD genuinely global surface, bound to the shared DB-backed
     * {@see DbRateLimiter} for the same reason as {@see LOGIN} / {@see MCP}:
     * self-service registration is an ANONYMOUS surface where every accepted
     * attempt costs the server a 64 MiB Argon2id hash, so an uncapped (or
     * per-worker-multiplied) budget is simultaneously an unbounded account-
     * creation farm and a cheap DoS amplifier. Keyed `auth:signup:<ip>` and —
     * unlike login, which counts only failures — EVERY attempt is counted,
     * because a successfully created account is exactly the event being
     * bounded. 3 / 3600s: enough for a real human behind a CGNAT edge to
     * recover from typos, far too little to farm accounts.
     */
    public const string SIGNUP = 'rate_limiter.signup';

    /**
     * Container id for the server-claim pairing surfaces (review hub__exploiter M1).
     *
     * PER-WORKER (soft-global), the {@see ALEXA} posture, NOT the login/mcp/signup
     * DB-backed posture — and the distinction is deliberate. The three
     * `/api/v1/server-claims/*` endpoints each guard a DIFFERENT secret:
     * `POST …/new` and `GET …/{claimId}` are public but their bearer IS the
     * claim id (a UUIDv4, ~2^122 — unguessable by repetition, so no counter
     * hardens a guess; the bucket only caps the cost of an anonymous flood
     * against a public endpoint that INSERTs rows / polls the DB per hit),
     * while `POST …/claim` is already behind {@see \Phlix\Hub\Http\Middleware\AuthMiddleware}
     * — its guesser is an AUTHENTICATED user, attributable and account-metered,
     * and an 8-char code over a 32-char alphabet is ~2^40 with a 600 s TTL, so
     * even a line-rate grind is centuries against the window. A per-worker
     * ×HUB_WORKERS multiplier therefore does NOT reopen a credential-guess path
     * (unlike login, whose credential is unbounded-lifetime and user-chosen);
     * it only multiplies a flood-cost bound by the worker count, which is
     * exactly the trade {@see ALEXA} already makes for the same reason.
     *
     * ONE limiter instance serves all three endpoints, keyed per action:
     * `server_claims:new:<ip>`, `server_claims:status:<ip>`,
     * `server_claims:claim:<ip>` (trusted-proxy-resolved IP — the raw peer is
     * the HAProxy loopback). Per-action keys keep the generous poll budget of
     * `status` (the pairing CLI polls every 2 s = 30/min) from being burned by
     * `claim`/`new` traffic on the same IP, without needing three profiles.
     * 60 / 60s per bucket per worker: 2 concurrent CLI pollers fit with slack
     * (requests round-robin across workers, so the practical ceiling is higher);
     * an attacker's sustained guess rate is bounded at roughly
     * `60 × HUB_WORKERS` codes/minute/IP — ≈3,456,000 guesses per 10-minute
     * claim window against a 1,099,511,627,776-code space.
     */
    public const string SERVER_CLAIMS = 'rate_limiter.server_claims';

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
            self::ALEXA         => ['key' => 'alexa',         'max' => 60,  'window' => 60],
            self::SIGNUP        => ['key' => 'signup',        'max' => 3,   'window' => 3600],
            self::SERVER_CLAIMS => ['key' => 'server_claims', 'max' => 60,  'window' => 60],
        ];
    }
}
