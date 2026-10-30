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
 * mcp / alexa / signup / server-claims / federation-connect / federation-frame /
 * invite-redeem / syncplay-mount / client-relay-token)
 * gets its OWN limiter instance registered
 * under the matching `rate_limiter.<surface>` container id — a single shared
 * login-grade limiter is wrong for everything but login (HB-4.6).
 * {@see defaults()} maps each container id to the `config/server.php`
 * `rate_limit.<key>` override key plus the per-worker default `{max, window}`.
 *
 * Thresholds are PER-WORKER for twelve of the fifteen surfaces (count rotated by
 * M-HUB-22, which added `client_relay_token`). `proxy`, `heartbeat`,
 * `jwks`, `alexa`, `server_claims`, `invite_redeem` and `client_relay_token`
 * are enforced on the
 * `HUB_WORKERS` HTTP
 * workers, so each keeps an INDEPENDENT per-worker counter and the effective
 * soft-global limit is roughly `max × HUB_WORKERS` (mirrors HB-3.4);
 * `relay_connect` (the :8802 `RelayWorker`), `client_mount` (the :8803
 * `ClientRelayWorker`), `syncplay_mount` (the :8804 `SyncPlayRelayWorker`,
 * M-HUB-37) and the two federation surfaces (the :8805
 * `FederationWorker`, both roles) run on count=1 surfaces where per-worker ==
 * global.
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
 * other nine stay worker-local (soft-global) by design.
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
     * Container id for the :8804 SyncPlay-connect limiter (minors campaign
     * M-HUB-37).
     *
     * Same posture as {@see CLIENT_MOUNT}: the :8804 `SyncPlayRelayWorker` is
     * `count=1` (Application::run() constructs it with count 1), so the
     * per-worker limit is a TRUE global limit, and the key is the REAL client
     * IP resolved by the trusted-proxy-aware {@see
     * \Phlix\Hub\Common\Http\TrustedProxyResolver} (on the managed install the
     * raw TCP peer is the loopback HAProxy — keying on the peer alone would
     * collapse every household into ONE bucket, the same law HB-4.6f shipped
     * for :8803).
     *
     * Before this profile existed, every unauthenticated `GET
     * /syncplay/{server_id}` upgrade went straight into the auth path — TWO
     * SQL queries per attempt (`ClientRelayTokenService::validate()` plus the
     * ownership probe `ServerInfoHandler::getOwnerAndStatus()`) — so a cheap
     * close was an expensive close: a pure DB-pressure amplifier in front of
     * an anonymous socket. 30 / 60s mirrors the :8803 mount budget; the
     * legitimate population is device apps (a TV, a phone, a tablet behind
     * one CGNAT exit) reconnecting at single-digit-per-minute rates, while a
     * flood's per-attempt DB cost is capped at thirty queries a minute per IP
     * before the refusals become free.
     *
     * Keyed `syncplay_mount:<ip>`, hit in the connect hook BEFORE token
     * extraction, refused with WS close frame `1013` (try again later) — WS ≠
     * HTTP, no 429 envelope after the upgrade hook.
     */
    public const string SYNCPLAY_MOUNT = 'rate_limiter.syncplay_mount';

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
     * Container id for the federation (:8805) inbound-connect limiter, master
     * role (review hub__exploiter M3, leg 2).
     *
     * PER-WORKER == GLOBAL: the `FederationWorker` is count=1 (production law
     * documented on its own constructor — the push dispatcher subscribes the
     * process-local connection map), so this worker-local {@see RateLimiter}
     * enforces an actually-global budget. Deliberately NOT a DbRateLimiter:
     * the limiter's whole purpose here is to refuse DB work CHEAPLY — every
     * pre-throttle connect performed a `federation_peers` read and each
     * accepted HELLO ceremony costs three session-row writes, so routing the
     * guard through the same DB it protects would trade the write-amp for a
     * read-amp.
     *
     * Keyed `federation_connect:<trusted client ip>` — the raw TCP peer under
     * the managed install is the HAProxy loopback (82bd762d routed :8805
     * through it too), so keying on the peer address would collapse every
     * leaf hub into one bucket and turn the guard into a self-DoS lever.
     * 10 / 60s: a legitimate leaf hub reconnects at most once per minute
     * (the leaf backoff ladder CAPS at 60 s), so a whole CGNAT exit sharing
     * up to ten neighbor hubs pairs without friction, while a
     * connect→HELLO→never-auth→reconnect keyless write-amp loop is bounded
     * at ten ceremonies/minute/IP.
     */
    public const string FEDERATION_CONNECT = 'rate_limiter.federation_connect';

    /**
     * Container id for the federation (:8805) frame-budget limiter, BOTH
     * roles (review hub__exploiter M3, legs 1 + 3).
     *
     * ONE instance serves the master-side inbound server and the leaf-side
     * outbound link: both live in the same count=1 :8805 worker process
     * (`FederationWorker::onWorkerStart()` installs it into the peer
     * manager), and every key is the REMOTE peer's hub id, so the two roles
     * can never collide on a bucket — a hub is simultaneously at most one
     * leaf's master and its own master's leaf under distinct ids.
     *
     * Keyed `federation_frame:<remote peer hub id>`, hit per deframed
     * payload BEFORE any dispatch — on the master side before the
     * {@see \Phlix\Hub\Http\Controllers\FederationRelayController} fan-out
     * (which bounds the pre-verification per-frame warning + audit rows of
     * leg 1 AND the unbounded `shares[]` inline-INSERT fan-out of leg 3 at
     * one choke point), on the leaf side before parse+dispatch of master
     * frames. 120 / 60s: legitimate traffic is heartbeat-dominated (one
     * 15 s keepalive per link = 4/min) plus event-driven offer/revocation
     * bursts and a 5-frame handshake ceremony; 120/min leaves an order of
     * magnitude of headroom for a busy share-churn moment while capping a
     * hostile-or-compromised PAIRED peer's frame flood (the gate class:
     * needs admin-provisioned peer material, not an anonymous internet
     * attacker) at one link-drop per window.
     */
    public const string FEDERATION_FRAME = 'rate_limiter.federation_frame';

    /**
     * Container id for the invite-link redemption limiter (minors campaign
     * M-HUB-21).
     *
     * PER-WORKER (soft-global), the {@see SERVER_CLAIMS} `claim` posture — and
     * deliberately NOT the DB-backed login/mcp/signup posture: this surface is
     * fully AUTHENTICATED (AuthMiddleware first, 401 without a member) and
     * keyed by the redeemer's OWN user id (`invite_redeem:<userId>`), so the
     * attacker class is one attributable member, not an anonymous guesser.
     * The budget caps the cost the redeem path charges the DB per request —
     * the `token_hash` SELECT, the conditional burn UPDATE, and a
     * `shareLibrary` write chain — against token-space grinding (a 64-hex
     * token is ~2^256, unguessable; the bucket exists to bound flood cost and
     * any member's ability to burn OTHERS' invite inventories by racing their
     * last use). 20 / 60s per member: no legitimate human redeems more than a
     * couple of invites a minute; the ×HUB_WORKERS multiplier of a
     * user-keyed bucket bounds one member's sustained rate, which stays far
     * under any real abuse shape.
     */
    public const string INVITE_REDEEM = 'rate_limiter.invite_redeem';

    /**
     * M-HUB-22 (review hub__exploiter minor #2): `POST /me/servers/{id}/
     * relay-token` — the mint ceremony.
     *
     * Pre-cure the route was registered BARE in the auth group: every
     * authenticated member could mint unbounded short-lived relay
     * credentials, each one a table row and a live bearer artifact. Same
     * posture as {@see INVITE_REDEEM}, and same reasoning: fully
     * authenticated, keyed by the member's OWN id (`client_relay_token:
     * <userId>`), so the attacker class is one attributable member; the
     * budget bounds per-request DB cost (INSERT + overflow sweep) and, with
     * the service-side active-token cap, one member's standing population of
     * live credentials per (user, server) scope. 10 / 60s: a client re-mints
     * on expiry and a user has a handful of servers; nobody legitimately
     * mints more than a couple per minute.
     */
    public const string CLIENT_RELAY_TOKEN = 'rate_limiter.client_relay_token';

    /**
     * M-HUB-23 (review hub__exploiter minor #3): `POST /api/v1/me/requests` —
     * media-request creation.
     *
     * Pre-cure any member could pump admin-queue rows and notification/log
     * volume at will: the route was bare in the auth group with no per-member
     * bound, no dedup and no standing cap. Same user-keyed posture as
     * {@see CLIENT_RELAY_TOKEN} (every caller already carries membership; an
     * IP key would collapse NAT'd households and be trivially refilled).
     * 20 / 60s is generous for genuine browsing (a member queueing a watchlist
     * in one sitting) while bounding sustained row production by orders of
     * magnitude; the manager-side pending dedup + standing cap close the
     * per-target and total-queue vectors the time window alone cannot.
     */
    public const string CLIENT_REQUEST_CREATE = 'rate_limiter.client_request_create';

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
            self::LOGIN               => ['key' => 'login',               'max' => 5,   'window' => 900],
            self::PROXY               => ['key' => 'proxy',               'max' => 600, 'window' => 60],
            self::HEARTBEAT           => ['key' => 'heartbeat',           'max' => 30,  'window' => 60],
            self::JWKS                => ['key' => 'jwks',                'max' => 120, 'window' => 60],
            self::RELAY_CONNECT       => ['key' => 'relay_connect',       'max' => 10,  'window' => 60],
            self::CLIENT_MOUNT        => ['key' => 'client_mount',        'max' => 30,  'window' => 60],
            self::SYNCPLAY_MOUNT      => ['key' => 'syncplay_mount',      'max' => 30,  'window' => 60],
            self::MCP                 => ['key' => 'mcp',                 'max' => 10,  'window' => 900],
            self::ALEXA               => ['key' => 'alexa',               'max' => 60,  'window' => 60],
            self::SIGNUP              => ['key' => 'signup',              'max' => 3,   'window' => 3600],
            self::SERVER_CLAIMS       => ['key' => 'server_claims',       'max' => 60,  'window' => 60],
            self::FEDERATION_CONNECT  => ['key' => 'federation_connect',  'max' => 10,  'window' => 60],
            self::FEDERATION_FRAME    => ['key' => 'federation_frame',    'max' => 120, 'window' => 60],
            self::INVITE_REDEEM       => ['key' => 'invite_redeem',       'max' => 20,  'window' => 60],
            self::CLIENT_RELAY_TOKEN  => ['key' => 'client_relay_token',  'max' => 10,  'window' => 60],
            self::CLIENT_REQUEST_CREATE => ['key' => 'client_request_create', 'max' => 20, 'window' => 60],
        ];
    }
}
