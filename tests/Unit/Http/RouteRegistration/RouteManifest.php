<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\RouteRegistration;

/**
 * Hand-written golden manifest of every route the hub's NINETEEN
 * `Application::register*Routes()` methods are expected to register.
 *
 * WHY A HAND-WRITTEN LIST — the whole point of S174 is that a test must fail
 * when a production route line disappears. A manifest DERIVED from the router
 * (or from Application.php) would self-adjust with the code it is supposed to
 * pin and could never go red. Every entry below is therefore a literal,
 * transcribed by hand from `src/Application.php`, and the suites that consume it
 * compare it against the table the REAL private registrars produce.
 *
 * Each entry is:
 *
 *  - `method`    HTTP verb the route is registered under.
 *  - `path`      Route template EXACTLY as `Router::addRoute()` stores it
 *                (group prefix + path, `{id}` placeholders intact).
 *  - `url`       A concrete URL that must match `path`, used for dispatch.
 *  - `gate`      Which middleware chain the route sits behind — see
 *                {@see self::GATE_*}. Drives the expected unauthenticated
 *                status in {@see RegistrarAuthGateTest}.
 *  - `redirect`  (optional) For an ungated static-closure route that is safe to
 *                dispatch, the Location header it must answer with.
 *
 * @psalm-type RouteSpec = array{
 *     method: string,
 *     path: string,
 *     url: string,
 *     gate: string,
 *     redirect?: string
 * }
 *
 * @package Phlix\Hub\Tests\Unit\Http\RouteRegistration
 */
final class RouteManifest
{
    /** Auth-gated JSON route: no credentials ⇒ 401 `auth.required`. */
    public const GATE_AUTH_JSON = 'auth-json';

    /** Auth-gated HTML route: no credentials ⇒ 302 to `/app/login`. */
    public const GATE_AUTH_HTML = 'auth-html';

    /** Auth + admin: no credentials ⇒ 401; authenticated non-admin ⇒ 403. */
    public const GATE_ADMIN = 'admin';

    /**
     * Auth + admin on an HTML page path (S206). Same middleware chain as
     * {@see self::GATE_ADMIN}, but the UNAUTHENTICATED outcome differs: the
     * chain short-circuits inside {@see \Phlix\Hub\Http\Middleware\AuthMiddleware},
     * which answers a non-`/api/` path with a 302 to `/app/login` rather than a
     * JSON 401 — so an anonymous caller never reaches the admin check at all.
     * An authenticated NON-admin does reach it and gets the JSON 403.
     */
    public const GATE_ADMIN_HTML = 'admin-html';

    /** Ed25519 enrollment JWT (server-facing): no token ⇒ 401. */
    public const GATE_ENROLLMENT = 'enrollment';

    /** `Accept-Phlix-Protocol: v1` required: header absent ⇒ 400. */
    public const GATE_HUB_PROTOCOL = 'hub-protocol';

    /**
     * Amazon request-signature required (S91): no `SignatureCertChainUrl` ⇒ 400
     * `ALEXA_MISSING_CERT_CHAIN_URL`.
     *
     * A gate of its own rather than a reuse of {@see self::GATE_HUB_PROTOCOL},
     * even though both answer 400: the two 400s come from different middleware
     * for different reasons, and a route that lost its signature gate but kept a
     * protocol gate (or vice versa) would still be a 400 and would pass a shared
     * arm. The suites assert the CODE as well as the status for exactly that
     * reason.
     */
    public const GATE_ALEXA = 'alexa';

    /** Deliberately public (or authenticated inside the controller). */
    public const GATE_PUBLIC = 'public';

    /**
     * The registrars that take an {@see \Phlix\Hub\Http\Middleware\AuthMiddleware}
     * as their only argument. Everything else is invoked with no arguments.
     *
     * @var list<string>
     */
    public const REGISTRARS_TAKING_AUTH_MIDDLEWARE = [
        'registerServerRoutes',
        'registerSharingRoutes',
    ];

    /**
     * The service ids each registrar is expected to pull out of the container.
     * Pins the controller/middleware wiring at registrar granularity: deleting a
     * `resolve*Controller()` call, or swapping a registrar onto a different
     * controller, changes this set.
     *
     * @var array<string, list<class-string>>
     */
    private const RESOLVED_SERVICES = [
        'registerRequestRoutes' => [
            \Phlix\Hub\Http\Middleware\AuthMiddleware::class,
            \Phlix\Hub\Http\Controllers\RequestController::class,
            \Phlix\Hub\Http\Middleware\AdminMiddleware::class,
        ],
        'registerUserQuotaRoutes' => [
            \Phlix\Hub\Http\Middleware\AuthMiddleware::class,
            \Phlix\Hub\Http\Middleware\AdminMiddleware::class,
            \Phlix\Hub\Http\Controllers\UserQuotaController::class,
        ],
        'registerServerRoutes' => [
            \Phlix\Hub\Http\Middleware\EnrollmentJwtMiddleware::class,
            \Phlix\Hub\Http\Controllers\ServerClaimController::class,
            \Phlix\Hub\Http\Controllers\ServerController::class,
            \Phlix\Hub\Http\Controllers\HubJwksController::class,
            \Phlix\Hub\Http\Controllers\RelayController::class,
            \Phlix\Hub\Http\Controllers\SubdomainController::class,
            \Phlix\Hub\Http\Controllers\ClientMountController::class,
        ],
        'registerSharingRoutes' => [
            \Phlix\Hub\Http\Controllers\LibraryShareController::class,
        ],
        'registerInviteLinkRoutes' => [
            \Phlix\Hub\Http\Controllers\InviteLinkController::class,
            \Phlix\Hub\Http\Middleware\AuthMiddleware::class,
        ],
        'registerHubSettingsRoutes' => [
            \Phlix\Hub\Http\Middleware\AuthMiddleware::class,
            \Phlix\Hub\Http\Middleware\AdminMiddleware::class,
            \Phlix\Hub\Http\Controllers\HubSettingsController::class,
        ],
        'registerAuditLogRoutes' => [
            \Phlix\Hub\Http\Middleware\AuthMiddleware::class,
            \Phlix\Hub\Http\Middleware\AdminMiddleware::class,
            \Phlix\Hub\Http\Controllers\AuditLogController::class,
        ],
        'registerLogRoutes' => [
            \Phlix\Hub\Http\Middleware\AuthMiddleware::class,
            \Phlix\Hub\Http\Middleware\AdminMiddleware::class,
            \Phlix\Hub\Http\Controllers\LogController::class,
        ],
        'registerAdminLogRoutes' => [
            \Phlix\Hub\Http\Middleware\AuthMiddleware::class,
            \Phlix\Hub\Http\Middleware\AdminMiddleware::class,
            \Phlix\Hub\Http\Controllers\LogController::class,
        ],
        'registerAdminSettingsRoutes' => [
            \Phlix\Hub\Http\Middleware\AuthMiddleware::class,
            \Phlix\Hub\Http\Middleware\AdminMiddleware::class,
            \Phlix\Hub\Http\Controllers\HubSettingsController::class,
        ],
        'registerAdminRestartRoutes' => [
            \Phlix\Hub\Http\Middleware\AuthMiddleware::class,
            \Phlix\Hub\Http\Middleware\AdminMiddleware::class,
            \Phlix\Hub\Http\Controllers\HubRestartController::class,
        ],
        'registerAdminUpdatesRoutes' => [
            \Phlix\Hub\Http\Middleware\AuthMiddleware::class,
            \Phlix\Hub\Http\Middleware\AdminMiddleware::class,
            \Phlix\Hub\Http\Controllers\AdminUpdatesController::class,
        ],
        'registerAdminUserRoutes' => [
            \Phlix\Hub\Http\Middleware\AuthMiddleware::class,
            \Phlix\Hub\Http\Middleware\AdminMiddleware::class,
            \Phlix\Hub\Http\Controllers\AdminUserController::class,
        ],
        'registerAdminDashboardRoutes' => [
            \Phlix\Hub\Http\Middleware\AuthMiddleware::class,
            \Phlix\Hub\Http\Middleware\AdminMiddleware::class,
            \Phlix\Hub\Http\Controllers\AdminDashboardController::class,
        ],
        'registerFederationRoutes' => [
            \Phlix\Hub\Http\Middleware\AuthMiddleware::class,
            \Phlix\Hub\Http\Middleware\AdminMiddleware::class,
            \Phlix\Hub\Http\Controllers\FederationController::class,
        ],
        'registerMetricsRoutes' => [
            \Phlix\Hub\Http\Middleware\AuthMiddleware::class,
            \Phlix\Hub\Http\Middleware\AdminMiddleware::class,
            \Phlix\Hub\Http\Controllers\Stats\MetricsController::class,
        ],
        // S91. Neither AuthMiddleware nor AdminMiddleware: an Alexa request
        // carries no hub session, so the ONLY gate is Amazon's signature.
        'registerAlexaRoutes' => [
            \Phlix\Hub\Http\Middleware\AlexaSignatureMiddleware::class,
            \Phlix\Hub\Http\Controllers\AlexaSkillController::class,
        ],
        // S92. AuthMiddleware IS resolved here — but it is attached to the two
        // `/oauth/authorize` verbs only, never to `/oauth/token`. Resolving it
        // and attaching it are two different facts; this list pins the first and
        // `subRegistrarRoutes()` + `ApplicationRouteCompositionTest` pin the
        // second.
        'registerOAuthRoutes' => [
            \Phlix\Hub\Http\Controllers\OAuthController::class,
            \Phlix\Hub\Http\Middleware\AuthMiddleware::class,
        ],
    ];

    /**
     * The routes `Application::registerRoutes()` registers DIRECTLY (i.e. not
     * via one of the eighteen sub-registrars it also calls).
     *
     * @return list<array<string, string>>
     */
    public static function topLevelRoutes(): array
    {
        return [
            ['method' => 'GET', 'path' => '/health', 'url' => '/health', 'gate' => self::GATE_PUBLIC],
            [
                'method' => 'GET', 'path' => '/', 'url' => '/',
                'gate' => self::GATE_PUBLIC, 'redirect' => '/app/servers',
            ],
            [
                'method' => 'GET', 'path' => '/signup', 'url' => '/signup',
                'gate' => self::GATE_PUBLIC, 'redirect' => '/app/signup',
            ],
            [
                'method' => 'GET', 'path' => '/login', 'url' => '/login',
                'gate' => self::GATE_PUBLIC, 'redirect' => '/app/login',
            ],
            ['method' => 'GET', 'path' => '/app', 'url' => '/app', 'gate' => self::GATE_PUBLIC],
            [
                'method' => 'GET', 'path' => '/app/{path:.*}', 'url' => '/app/admin/settings',
                'gate' => self::GATE_PUBLIC,
            ],

            // JSON auth API — public by design (these MINT the credentials).
            [
                'method' => 'POST', 'path' => '/api/v1/auth/register',
                'url' => '/api/v1/auth/register', 'gate' => self::GATE_PUBLIC,
            ],
            [
                'method' => 'POST', 'path' => '/api/v1/auth/signup',
                'url' => '/api/v1/auth/signup', 'gate' => self::GATE_PUBLIC,
            ],
            [
                'method' => 'POST', 'path' => '/api/v1/auth/login',
                'url' => '/api/v1/auth/login', 'gate' => self::GATE_PUBLIC,
            ],
            [
                'method' => 'POST', 'path' => '/api/v1/auth/logout',
                'url' => '/api/v1/auth/logout', 'gate' => self::GATE_PUBLIC,
            ],
            [
                'method' => 'POST', 'path' => '/api/v1/auth/refresh',
                'url' => '/api/v1/auth/refresh', 'gate' => self::GATE_PUBLIC,
            ],

            // Legacy SSR page paths — auth-gated redirects into the SPA.
            [
                'method' => 'GET', 'path' => '/my-servers', 'url' => '/my-servers',
                'gate' => self::GATE_AUTH_HTML,
            ],
            [
                'method' => 'GET', 'path' => '/claim-server', 'url' => '/claim-server',
                'gate' => self::GATE_AUTH_HTML,
            ],
            [
                'method' => 'GET', 'path' => '/invite-links', 'url' => '/invite-links',
                'gate' => self::GATE_AUTH_HTML,
            ],
            [
                'method' => 'GET', 'path' => '/hub-settings', 'url' => '/hub-settings',
                'gate' => self::GATE_AUTH_HTML,
            ],
            [
                'method' => 'GET', 'path' => '/audit-logs', 'url' => '/audit-logs',
                'gate' => self::GATE_AUTH_HTML,
            ],
            ['method' => 'GET', 'path' => '/logs', 'url' => '/logs', 'gate' => self::GATE_AUTH_HTML],
            [
                'method' => 'GET', 'path' => '/federation', 'url' => '/federation',
                'gate' => self::GATE_AUTH_HTML,
            ],
            [
                'method' => 'GET', 'path' => '/federation/shares', 'url' => '/federation/shares',
                'gate' => self::GATE_AUTH_HTML,
            ],
            [
                'method' => 'GET', 'path' => '/servers/{id}', 'url' => '/servers/srv-1',
                'gate' => self::GATE_AUTH_HTML,
            ],

            // `/api/v1` JSON surface.
            ['method' => 'GET', 'path' => '/api/v1/me', 'url' => '/api/v1/me', 'gate' => self::GATE_AUTH_JSON],
            [
                'method' => 'GET', 'path' => '/api/v1/auth/me', 'url' => '/api/v1/auth/me',
                'gate' => self::GATE_AUTH_JSON,
            ],
            [
                'method' => 'GET', 'path' => '/api/v1/me/servers', 'url' => '/api/v1/me/servers',
                'gate' => self::GATE_AUTH_JSON,
            ],
            [
                'method' => 'DELETE', 'path' => '/api/v1/me/servers/{id}', 'url' => '/api/v1/me/servers/srv-1',
                'gate' => self::GATE_AUTH_JSON,
            ],
            [
                'method' => 'GET', 'path' => '/api/v1/me/servers/{id}/access-info',
                'url' => '/api/v1/me/servers/srv-1/access-info', 'gate' => self::GATE_AUTH_JSON,
            ],
            [
                'method' => 'GET', 'path' => '/api/v1/me/libraries', 'url' => '/api/v1/me/libraries',
                'gate' => self::GATE_AUTH_JSON,
            ],
            [
                'method' => 'GET', 'path' => '/api/v1/me/servers/{id}', 'url' => '/api/v1/me/servers/srv-1',
                'gate' => self::GATE_AUTH_JSON,
            ],
            [
                'method' => 'POST', 'path' => '/api/v1/me/servers/{id}/relay-token',
                'url' => '/api/v1/me/servers/srv-1/relay-token', 'gate' => self::GATE_AUTH_JSON,
            ],

            // MCP (S62). The management surface is auth-gated like the rest of
            // `/api/v1/me`; `POST /mcp` itself is UNGATED because an MCP client
            // presents a personal access token, not a hub session, and
            // McpController authenticates it internally.
            [
                'method' => 'GET', 'path' => '/api/v1/me/mcp-tokens',
                'url' => '/api/v1/me/mcp-tokens', 'gate' => self::GATE_AUTH_JSON,
            ],
            [
                'method' => 'POST', 'path' => '/api/v1/me/mcp-tokens',
                'url' => '/api/v1/me/mcp-tokens', 'gate' => self::GATE_AUTH_JSON,
            ],
            [
                'method' => 'DELETE', 'path' => '/api/v1/me/mcp-tokens/{id}',
                'url' => '/api/v1/me/mcp-tokens/tok-1', 'gate' => self::GATE_AUTH_JSON,
            ],
            [
                'method' => 'POST', 'path' => '/mcp', 'url' => '/mcp', 'gate' => self::GATE_PUBLIC,
            ],
            // S63: the SSE half of the same Streamable HTTP endpoint. Ungated
            // for the same reason as the POST — McpController authenticates the
            // PAT itself on BOTH verbs.
            [
                'method' => 'GET', 'path' => '/mcp', 'url' => '/mcp', 'gate' => self::GATE_PUBLIC,
            ],
            [
                'method' => 'GET', 'path' => '/api/v1/servers/{id}/proxy/{path:.*}',
                'url' => '/api/v1/servers/srv-1/proxy/api/v1/media', 'gate' => self::GATE_AUTH_JSON,
            ],
            [
                'method' => 'PUT', 'path' => '/api/v1/servers/{id}/proxy/{path:.*}',
                'url' => '/api/v1/servers/srv-1/proxy/api/v1/media', 'gate' => self::GATE_AUTH_JSON,
            ],
            [
                'method' => 'DELETE', 'path' => '/api/v1/servers/{id}/proxy/{path:.*}',
                'url' => '/api/v1/servers/srv-1/proxy/api/v1/media', 'gate' => self::GATE_AUTH_JSON,
            ],
            [
                'method' => 'PATCH', 'path' => '/api/v1/servers/{id}/proxy/{path:.*}',
                'url' => '/api/v1/servers/srv-1/proxy/api/v1/media', 'gate' => self::GATE_AUTH_JSON,
            ],
            [
                'method' => 'POST', 'path' => '/api/v1/servers/{id}/proxy/{path:.*}',
                'url' => '/api/v1/servers/srv-1/proxy/api/v1/media', 'gate' => self::GATE_AUTH_JSON,
            ],
        ];
    }

    /**
     * Golden route table for each of the eighteen sub-registrars.
     *
     * @return array<string, list<array<string, string>>>
     */
    public static function subRegistrarRoutes(): array
    {
        return [
            'registerRequestRoutes' => [
                [
                    'method' => 'GET', 'path' => '/requests', 'url' => '/requests',
                    'gate' => self::GATE_AUTH_HTML,
                ],
                [
                    'method' => 'GET', 'path' => '/admin/requests', 'url' => '/admin/requests',
                    'gate' => self::GATE_ADMIN_HTML,
                ],
                [
                    'method' => 'POST', 'path' => '/api/v1/me/requests', 'url' => '/api/v1/me/requests',
                    'gate' => self::GATE_AUTH_JSON,
                ],
                [
                    'method' => 'GET', 'path' => '/api/v1/me/requests', 'url' => '/api/v1/me/requests',
                    'gate' => self::GATE_AUTH_JSON,
                ],
                [
                    'method' => 'GET', 'path' => '/api/v1/me/requests/{id}', 'url' => '/api/v1/me/requests/r-1',
                    'gate' => self::GATE_AUTH_JSON,
                ],
                [
                    'method' => 'DELETE', 'path' => '/api/v1/me/requests/{id}', 'url' => '/api/v1/me/requests/r-1',
                    'gate' => self::GATE_AUTH_JSON,
                ],
                [
                    'method' => 'GET', 'path' => '/api/v1/admin/requests', 'url' => '/api/v1/admin/requests',
                    'gate' => self::GATE_ADMIN,
                ],
                [
                    'method' => 'POST', 'path' => '/api/v1/admin/requests/{id}/approve',
                    'url' => '/api/v1/admin/requests/r-1/approve', 'gate' => self::GATE_ADMIN,
                ],
                [
                    'method' => 'POST', 'path' => '/api/v1/admin/requests/{id}/deny',
                    'url' => '/api/v1/admin/requests/r-1/deny', 'gate' => self::GATE_ADMIN,
                ],
            ],

            'registerUserQuotaRoutes' => [
                [
                    'method' => 'GET', 'path' => '/api/v1/me/bandwidth', 'url' => '/api/v1/me/bandwidth',
                    'gate' => self::GATE_AUTH_JSON,
                ],
                [
                    'method' => 'GET', 'path' => '/api/v1/admin/users/{id}/bandwidth',
                    'url' => '/api/v1/admin/users/u-1/bandwidth', 'gate' => self::GATE_ADMIN,
                ],
                [
                    'method' => 'PUT', 'path' => '/api/v1/admin/users/{id}/quota',
                    'url' => '/api/v1/admin/users/u-1/quota', 'gate' => self::GATE_ADMIN,
                ],
                [
                    'method' => 'PUT', 'path' => '/api/v1/admin/users/{id}/throttle',
                    'url' => '/api/v1/admin/users/u-1/throttle', 'gate' => self::GATE_ADMIN,
                ],
            ],

            'registerServerRoutes' => [
                [
                    'method' => 'GET', 'path' => '/.well-known/jwks.json', 'url' => '/.well-known/jwks.json',
                    'gate' => self::GATE_PUBLIC,
                ],
                [
                    'method' => 'POST', 'path' => '/api/v1/server-claims/new',
                    'url' => '/api/v1/server-claims/new', 'gate' => self::GATE_HUB_PROTOCOL,
                ],
                [
                    'method' => 'GET', 'path' => '/api/v1/server-claims/{claimId}',
                    'url' => '/api/v1/server-claims/c-1', 'gate' => self::GATE_HUB_PROTOCOL,
                ],
                [
                    'method' => 'POST', 'path' => '/api/v1/server-claims/claim',
                    'url' => '/api/v1/server-claims/claim', 'gate' => self::GATE_AUTH_JSON,
                ],
                [
                    'method' => 'POST', 'path' => '/api/v1/servers/{id}/heartbeat',
                    'url' => '/api/v1/servers/srv-1/heartbeat', 'gate' => self::GATE_ENROLLMENT,
                ],
                [
                    'method' => 'POST', 'path' => '/api/v1/servers/{id}/renew',
                    'url' => '/api/v1/servers/srv-1/renew', 'gate' => self::GATE_ENROLLMENT,
                ],
                [
                    'method' => 'GET', 'path' => '/api/v1/servers/{id}/info',
                    'url' => '/api/v1/servers/srv-1/info', 'gate' => self::GATE_ENROLLMENT,
                ],
                [
                    'method' => 'DELETE', 'path' => '/api/v1/servers/{id}',
                    'url' => '/api/v1/servers/srv-1', 'gate' => self::GATE_ENROLLMENT,
                ],
                // The four routes below carry NO route middleware — they
                // authenticate INSIDE the controller. See
                // ApplicationRouteCompositionTest::testUngatedRoutesAreExactlyTheKnownSet().
                //
                // ⚠ S204: the relay and the two subdomain paths carry `/api/v1`
                // because that is what phlix-server addresses (SubdomainClient
                // and config/relay.php) and what phlix-docs publishes. They were
                // registered BARE from 19d05b7 until S204 and 404'd for every
                // real caller. `/client/{server_id}` is the exception and is
                // deliberately bare — it mirrors ClientRelayWorker's `:8803`
                // path parser.
                [
                    'method' => 'POST', 'path' => '/api/v1/servers/{id}/relay',
                    'url' => '/api/v1/servers/srv-1/relay', 'gate' => self::GATE_PUBLIC,
                ],
                [
                    'method' => 'GET', 'path' => '/client/{server_id}', 'url' => '/client/srv-1',
                    'gate' => self::GATE_PUBLIC,
                ],
                [
                    'method' => 'POST', 'path' => '/api/v1/servers/{id}/subdomain',
                    'url' => '/api/v1/servers/srv-1/subdomain', 'gate' => self::GATE_PUBLIC,
                ],
                [
                    'method' => 'DELETE', 'path' => '/api/v1/servers/{id}/subdomain',
                    'url' => '/api/v1/servers/srv-1/subdomain', 'gate' => self::GATE_PUBLIC,
                ],
            ],

            'registerSharingRoutes' => [
                [
                    'method' => 'GET', 'path' => '/shared-with-me', 'url' => '/shared-with-me',
                    'gate' => self::GATE_AUTH_HTML,
                ],
                [
                    'method' => 'GET', 'path' => '/manage-shares', 'url' => '/manage-shares',
                    'gate' => self::GATE_AUTH_HTML,
                ],
                [
                    'method' => 'POST', 'path' => '/api/v1/me/shares', 'url' => '/api/v1/me/shares',
                    'gate' => self::GATE_AUTH_JSON,
                ],
                [
                    'method' => 'POST', 'path' => '/api/v1/me/shares/', 'url' => '/api/v1/me/shares/',
                    'gate' => self::GATE_AUTH_JSON,
                ],
                [
                    'method' => 'GET', 'path' => '/api/v1/me/shares', 'url' => '/api/v1/me/shares',
                    'gate' => self::GATE_AUTH_JSON,
                ],
                [
                    'method' => 'GET', 'path' => '/api/v1/me/shares/', 'url' => '/api/v1/me/shares/',
                    'gate' => self::GATE_AUTH_JSON,
                ],
                [
                    'method' => 'DELETE', 'path' => '/api/v1/me/shares/{id}', 'url' => '/api/v1/me/shares/s-1',
                    'gate' => self::GATE_AUTH_JSON,
                ],
                [
                    'method' => 'PATCH', 'path' => '/api/v1/me/shares/{id}', 'url' => '/api/v1/me/shares/s-1',
                    'gate' => self::GATE_AUTH_JSON,
                ],
            ],

            'registerInviteLinkRoutes' => [
                [
                    'method' => 'GET', 'path' => '/invite/{token}', 'url' => '/invite/tok-1',
                    'gate' => self::GATE_PUBLIC, 'redirect' => '/app/invite/tok-1',
                ],
                [
                    'method' => 'POST', 'path' => '/api/v1/me/invite-links', 'url' => '/api/v1/me/invite-links',
                    'gate' => self::GATE_AUTH_JSON,
                ],
                [
                    'method' => 'POST', 'path' => '/api/v1/me/invite-links/', 'url' => '/api/v1/me/invite-links/',
                    'gate' => self::GATE_AUTH_JSON,
                ],
                [
                    'method' => 'GET', 'path' => '/api/v1/me/invite-links', 'url' => '/api/v1/me/invite-links',
                    'gate' => self::GATE_AUTH_JSON,
                ],
                [
                    'method' => 'GET', 'path' => '/api/v1/me/invite-links/', 'url' => '/api/v1/me/invite-links/',
                    'gate' => self::GATE_AUTH_JSON,
                ],
                [
                    'method' => 'DELETE', 'path' => '/api/v1/me/invite-links/{id}',
                    'url' => '/api/v1/me/invite-links/i-1', 'gate' => self::GATE_AUTH_JSON,
                ],
                [
                    'method' => 'POST', 'path' => '/api/v1/me/invite-links/{token}/redeem',
                    'url' => '/api/v1/me/invite-links/tok-1/redeem', 'gate' => self::GATE_AUTH_JSON,
                ],
            ],

            'registerHubSettingsRoutes' => [
                [
                    'method' => 'GET', 'path' => '/api/v1/me/hub-settings', 'url' => '/api/v1/me/hub-settings',
                    'gate' => self::GATE_ADMIN,
                ],
                [
                    'method' => 'PUT', 'path' => '/api/v1/me/hub-settings', 'url' => '/api/v1/me/hub-settings',
                    'gate' => self::GATE_ADMIN,
                ],
            ],

            'registerAuditLogRoutes' => [
                [
                    'method' => 'GET', 'path' => '/api/v1/me/audit-logs', 'url' => '/api/v1/me/audit-logs',
                    'gate' => self::GATE_ADMIN,
                ],
            ],

            'registerLogRoutes' => [
                [
                    'method' => 'GET', 'path' => '/api/v1/me/logs', 'url' => '/api/v1/me/logs',
                    'gate' => self::GATE_ADMIN,
                ],
                [
                    'method' => 'GET', 'path' => '/api/v1/me/logs/tail-all', 'url' => '/api/v1/me/logs/tail-all',
                    'gate' => self::GATE_ADMIN,
                ],
                [
                    'method' => 'GET', 'path' => '/api/v1/me/logs/tail', 'url' => '/api/v1/me/logs/tail',
                    'gate' => self::GATE_ADMIN,
                ],
            ],

            'registerAdminLogRoutes' => [
                [
                    'method' => 'GET', 'path' => '/api/v1/admin/logs', 'url' => '/api/v1/admin/logs',
                    'gate' => self::GATE_ADMIN,
                ],
                [
                    'method' => 'GET', 'path' => '/api/v1/admin/logs/tail-all',
                    'url' => '/api/v1/admin/logs/tail-all', 'gate' => self::GATE_ADMIN,
                ],
                [
                    'method' => 'GET', 'path' => '/api/v1/admin/logs/tail', 'url' => '/api/v1/admin/logs/tail',
                    'gate' => self::GATE_ADMIN,
                ],
            ],

            'registerAdminSettingsRoutes' => [
                [
                    'method' => 'GET', 'path' => '/api/v1/admin/settings', 'url' => '/api/v1/admin/settings',
                    'gate' => self::GATE_ADMIN,
                ],
                [
                    'method' => 'PUT', 'path' => '/api/v1/admin/settings', 'url' => '/api/v1/admin/settings',
                    'gate' => self::GATE_ADMIN,
                ],
            ],

            'registerAdminRestartRoutes' => [
                [
                    'method' => 'POST', 'path' => '/api/v1/admin/restart', 'url' => '/api/v1/admin/restart',
                    'gate' => self::GATE_ADMIN,
                ],
            ],

            'registerAdminUpdatesRoutes' => [
                [
                    'method' => 'GET', 'path' => '/api/v1/admin/updates/status',
                    'url' => '/api/v1/admin/updates/status', 'gate' => self::GATE_ADMIN,
                ],
                [
                    'method' => 'PUT', 'path' => '/api/v1/admin/updates/settings',
                    'url' => '/api/v1/admin/updates/settings', 'gate' => self::GATE_ADMIN,
                ],
            ],

            'registerAdminUserRoutes' => [
                [
                    'method' => 'GET', 'path' => '/api/v1/admin/users', 'url' => '/api/v1/admin/users',
                    'gate' => self::GATE_ADMIN,
                ],
                [
                    'method' => 'POST', 'path' => '/api/v1/admin/users', 'url' => '/api/v1/admin/users',
                    'gate' => self::GATE_ADMIN,
                ],
                [
                    'method' => 'GET', 'path' => '/api/v1/admin/users/{id}', 'url' => '/api/v1/admin/users/u-1',
                    'gate' => self::GATE_ADMIN,
                ],
                [
                    'method' => 'PUT', 'path' => '/api/v1/admin/users/{id}', 'url' => '/api/v1/admin/users/u-1',
                    'gate' => self::GATE_ADMIN,
                ],
                [
                    'method' => 'DELETE', 'path' => '/api/v1/admin/users/{id}', 'url' => '/api/v1/admin/users/u-1',
                    'gate' => self::GATE_ADMIN,
                ],
                [
                    'method' => 'POST', 'path' => '/api/v1/admin/users/{id}/set-admin',
                    'url' => '/api/v1/admin/users/u-1/set-admin', 'gate' => self::GATE_ADMIN,
                ],
                [
                    'method' => 'POST', 'path' => '/api/v1/admin/users/{id}/reset-password',
                    'url' => '/api/v1/admin/users/u-1/reset-password', 'gate' => self::GATE_ADMIN,
                ],
                [
                    'method' => 'GET', 'path' => '/api/v1/admin/users/{id}/profiles',
                    'url' => '/api/v1/admin/users/u-1/profiles', 'gate' => self::GATE_ADMIN,
                ],
            ],

            'registerAdminDashboardRoutes' => [
                [
                    'method' => 'GET', 'path' => '/api/v1/admin/dashboard/summary',
                    'url' => '/api/v1/admin/dashboard/summary', 'gate' => self::GATE_ADMIN,
                ],
                [
                    'method' => 'GET', 'path' => '/api/v1/admin/dashboard/activity',
                    'url' => '/api/v1/admin/dashboard/activity', 'gate' => self::GATE_ADMIN,
                ],
            ],

            'registerFederationRoutes' => [
                [
                    'method' => 'GET', 'path' => '/api/v1/me/federation/hub-config',
                    'url' => '/api/v1/me/federation/hub-config', 'gate' => self::GATE_ADMIN,
                ],
                [
                    'method' => 'PUT', 'path' => '/api/v1/me/federation/hub-config',
                    'url' => '/api/v1/me/federation/hub-config', 'gate' => self::GATE_ADMIN,
                ],
                [
                    'method' => 'GET', 'path' => '/api/v1/me/federation/peers',
                    'url' => '/api/v1/me/federation/peers', 'gate' => self::GATE_ADMIN,
                ],
                [
                    'method' => 'POST', 'path' => '/api/v1/me/federation/peers',
                    'url' => '/api/v1/me/federation/peers', 'gate' => self::GATE_ADMIN,
                ],
                [
                    'method' => 'DELETE', 'path' => '/api/v1/me/federation/peers/{id}',
                    'url' => '/api/v1/me/federation/peers/p-1', 'gate' => self::GATE_ADMIN,
                ],
                [
                    'method' => 'PUT', 'path' => '/api/v1/me/federation/peers/{id}/relay',
                    'url' => '/api/v1/me/federation/peers/p-1/relay', 'gate' => self::GATE_ADMIN,
                ],
                [
                    'method' => 'PUT', 'path' => '/api/v1/me/federation/peers/{id}/admin-delegation',
                    'url' => '/api/v1/me/federation/peers/p-1/admin-delegation', 'gate' => self::GATE_ADMIN,
                ],
                [
                    'method' => 'GET', 'path' => '/api/v1/me/federation/library-shares/outgoing',
                    'url' => '/api/v1/me/federation/library-shares/outgoing', 'gate' => self::GATE_ADMIN,
                ],
                [
                    'method' => 'POST', 'path' => '/api/v1/me/federation/library-shares/outgoing',
                    'url' => '/api/v1/me/federation/library-shares/outgoing', 'gate' => self::GATE_ADMIN,
                ],
                [
                    'method' => 'DELETE', 'path' => '/api/v1/me/federation/library-shares/outgoing/{id}',
                    'url' => '/api/v1/me/federation/library-shares/outgoing/o-1', 'gate' => self::GATE_ADMIN,
                ],
                [
                    'method' => 'GET', 'path' => '/api/v1/me/federation/library-shares/incoming',
                    'url' => '/api/v1/me/federation/library-shares/incoming', 'gate' => self::GATE_ADMIN,
                ],
                [
                    'method' => 'POST', 'path' => '/api/v1/me/federation/library-shares/incoming/{id}/accept',
                    'url' => '/api/v1/me/federation/library-shares/incoming/i-1/accept', 'gate' => self::GATE_ADMIN,
                ],
                [
                    'method' => 'POST', 'path' => '/api/v1/me/federation/library-shares/incoming/{id}/reject',
                    'url' => '/api/v1/me/federation/library-shares/incoming/i-1/reject', 'gate' => self::GATE_ADMIN,
                ],
                [
                    'method' => 'GET', 'path' => '/api/v1/me/federation/admin-delegations',
                    'url' => '/api/v1/me/federation/admin-delegations', 'gate' => self::GATE_ADMIN,
                ],
                [
                    'method' => 'POST', 'path' => '/api/v1/me/federation/admin-delegations',
                    'url' => '/api/v1/me/federation/admin-delegations', 'gate' => self::GATE_ADMIN,
                ],
                [
                    'method' => 'DELETE', 'path' => '/api/v1/me/federation/admin-delegations/{id}',
                    'url' => '/api/v1/me/federation/admin-delegations/d-1', 'gate' => self::GATE_ADMIN,
                ],
            ],

            'registerMetricsRoutes' => [
                [
                    'method' => 'GET', 'path' => '/api/v1/admin/metrics/snapshot',
                    'url' => '/api/v1/admin/metrics/snapshot', 'gate' => self::GATE_ADMIN,
                ],
                [
                    'method' => 'GET', 'path' => '/api/v1/admin/metrics/history',
                    'url' => '/api/v1/admin/metrics/history', 'gate' => self::GATE_ADMIN,
                ],
                [
                    'method' => 'GET', 'path' => '/api/v1/admin/metrics/connections',
                    'url' => '/api/v1/admin/metrics/connections', 'gate' => self::GATE_ADMIN,
                ],
                [
                    'method' => 'GET', 'path' => '/api/v1/admin/metrics/routes',
                    'url' => '/api/v1/admin/metrics/routes', 'gate' => self::GATE_ADMIN,
                ],
            ],

            // S91. Exactly one route, and its gate is Amazon's signature — not
            // the hub session. A second route appearing here (or this one losing
            // its middleware) is the change this entry exists to surface.
            'registerAlexaRoutes' => [
                [
                    'method' => 'POST', 'path' => '/alexa/skill', 'url' => '/alexa/skill',
                    'gate' => self::GATE_ALEXA,
                ],
            ],

            // S92 — the OAuth 2.0 Authorization Server. Three routes, and the
            // SPLIT between them is the security property, not an implementation
            // detail: the GET renders consent and mints nothing, the POST is the
            // only path to an authorization code, and the token endpoint is
            // ungated because its caller is a client rather than a hub user.
            //
            // The two `/oauth/authorize` verbs are GATE_AUTH_HTML, not
            // GATE_AUTH_JSON: the path is not under `/api/`, so an
            // unauthenticated caller short-circuits inside AuthMiddleware with a
            // 302 to `/app/login`. That is the correct outcome for a surface a
            // human arrives at from a third-party app — and it is asserted, so a
            // change that made the consent screen answer 200 to an anonymous
            // visitor would surface here.
            'registerOAuthRoutes' => [
                [
                    'method' => 'GET', 'path' => '/oauth/authorize', 'url' => '/oauth/authorize',
                    'gate' => self::GATE_AUTH_HTML,
                ],
                [
                    'method' => 'POST', 'path' => '/oauth/authorize', 'url' => '/oauth/authorize',
                    'gate' => self::GATE_AUTH_HTML,
                ],
                [
                    'method' => 'POST', 'path' => '/oauth/token', 'url' => '/oauth/token',
                    'gate' => self::GATE_PUBLIC,
                ],
            ],
        ];
    }

    /**
     * Every route in the manifest — the eighteen sub-registrars plus the routes
     * `registerRoutes()` registers directly.
     *
     * @return list<array<string, string>>
     */
    public static function allRoutes(): array
    {
        $all = self::topLevelRoutes();
        foreach (self::subRegistrarRoutes() as $routes) {
            foreach ($routes as $route) {
                $all[] = $route;
            }
        }

        return $all;
    }

    /**
     * Service ids a registrar must resolve from the container.
     *
     * @return list<class-string>
     */
    public static function resolvedServices(string $registrar): array
    {
        return self::RESOLVED_SERVICES[$registrar] ?? [];
    }

    /**
     * `"METHOD path"` key used to compare route tables as sets.
     *
     * @param array<string, string> $route
     */
    public static function key(array $route): string
    {
        return $route['method'] . ' ' . $route['path'];
    }
}
