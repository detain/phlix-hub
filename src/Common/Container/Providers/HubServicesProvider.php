<?php

/**
 * Phlix hub component: Providers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Hub\Alexa\AlexaAccountLink;
use Phlix\Hub\Alexa\AlexaRejectionAuditorInterface;
use Phlix\Hub\Alexa\AuditLogAlexaRejectionAuditor;
use Phlix\Hub\Alexa\CurlCertChainFetcher;
use Phlix\Hub\Auth\JwtHandler;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Federation\FederationAdminDelegationRepository;
use Phlix\Hub\Federation\FederationConnectionManager;
use Phlix\Hub\Federation\FederationFrameHandler;
use Phlix\Hub\Federation\FederationHubRepository;
use Phlix\Hub\Federation\FederationLibraryShareRepository;
use Phlix\Hub\Federation\FederationPeerManager;
use Phlix\Hub\Federation\FederationSessionManager;
use Phlix\Hub\Http\Controllers\AdminDashboardController;
use Phlix\Hub\Http\Controllers\AlexaSkillController;
use Phlix\Hub\Http\Controllers\AdminUserController;
use Phlix\Hub\Http\Controllers\AuditLogController;
use Phlix\Hub\Http\Controllers\FederationController;
use Phlix\Hub\Http\Controllers\LogController;
use Phlix\Hub\Http\Controllers\HubRestartController;
use Phlix\Hub\Hub\AuditLogRepository;
use Phlix\Hub\Hub\ClaimRequestHandler;
use Phlix\Hub\Hub\ClientRelayTokenService;
use Phlix\Hub\Hub\DeregisterHandler;
use Phlix\Hub\Hub\DnsAliasManager;
use Phlix\Hub\Hub\Dns\StaticZoneManager;
use Phlix\Hub\Hub\Ed25519KeyManager;
use Phlix\Hub\Hub\EnrollmentJwtService;
use Phlix\Hub\Hub\HeartbeatHandler;
use Phlix\Hub\Hub\RenewHandler;
use Phlix\Hub\Hub\HubSettingsRepository;
use Phlix\Hub\Hub\InviteLinkHandler;
use Phlix\Hub\Hub\LibrarySharingHandler;
use Phlix\Hub\Hub\RelayRouter;
use Phlix\Hub\Hub\RelayServerHandler;
use Phlix\Hub\Hub\RelaySessionManager;
use Phlix\Hub\ServerReaper;
use Phlix\Hub\Relay\FrameDecoder;
use Phlix\Hub\Relay\FrameEncoder;
use Phlix\Hub\Relay\IdleReaper;
use Phlix\Hub\Relay\RelayProxyBridge;
use Phlix\Hub\Relay\RelayProxyManager;
use Phlix\Hub\Relay\TunnelManager;
use Phlix\Hub\Relay\TunnelManagerInterface;
use Phlix\Hub\Hub\ServerInfoHandler;
use Phlix\Hub\Hub\TlsCertificateManager;
use Phlix\Hub\Hub\Updates\AsyncVersionMarkerFetcher;
use Phlix\Hub\Hub\Updates\CoreUpdateCheckService;
use Phlix\Hub\Hub\Updates\CoreUpdateCheckWorker;
use Phlix\Hub\Hub\Updates\VersionMarkerFetcherInterface;
use Phlix\Hub\Http\Controllers\AdminUpdatesController;
use Phlix\Hub\Common\Container\ServiceProviderInterface;
use Phlix\Hub\Common\Database\ConnectionPool;
use Phlix\Hub\Common\RateLimit\RateLimiterInterface;
use Phlix\Hub\Common\RateLimit\RateLimitProfiles;
use Phlix\Hub\Common\Logger\LogChannels;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Http\Controllers\HubJwksController;
use Phlix\Hub\Http\Controllers\HubSettingsController;
use Phlix\Hub\Http\Controllers\InviteLinkController;
use Phlix\Hub\Http\Controllers\LibraryController;
use Phlix\Hub\Http\Controllers\LibraryShareController;
use Phlix\Hub\Http\Controllers\ClientMountController;
use Phlix\Hub\Http\Controllers\ClientRelayTokenController;
use Phlix\Hub\Http\Controllers\FederationRelayController;
use Phlix\Hub\Http\Controllers\RelayController;
use Phlix\Hub\Http\Controllers\Stats\MetricsController;
use Phlix\Hub\Relay\FederationWorker;
use Phlix\Hub\Http\Controllers\RequestController;
use Phlix\Hub\Http\Controllers\UserQuotaController;
use Phlix\Hub\Stats\Metrics\MetricsCollector;
use Phlix\Hub\Stats\Metrics\MetricsRepositoryInterface;
use Phlix\Hub\Http\Controllers\ServerClaimController;
use Phlix\Hub\Http\Controllers\ServerController;
use Phlix\Hub\Http\Controllers\ServerDetailController;
use Phlix\Hub\Http\Controllers\McpController;
use Phlix\Hub\Http\Controllers\McpTokenController;
use Phlix\Hub\Http\Controllers\ServerListController;
use Phlix\Hub\Http\Controllers\ServerProxyController;
use Phlix\Hub\Mcp\McpSseStream;
use Phlix\Hub\Mcp\McpStreamTimers;
use Phlix\Hub\Mcp\McpTokenService;
use Phlix\Hub\Mcp\McpToolInterface;
use Phlix\Hub\Mcp\McpToolRegistry;
use Phlix\Hub\Mcp\Tools\GetMediaTool;
use Phlix\Hub\Mcp\Tools\GetPlaybackInfoTool;
use Phlix\Hub\Mcp\Tools\ListLibrariesTool;
use Phlix\Hub\Mcp\Tools\ListServersTool;
use Phlix\Hub\Mcp\Tools\PlaybackControlTool;
use Phlix\Hub\Mcp\Tools\SearchMediaTool;
use Phlix\Hub\Mcp\WorkermanStreamTimers;
use Phlix\Hub\Http\Controllers\OAuthController;
use Phlix\Hub\OAuth\AuthorizationCodeService;
use Phlix\Hub\OAuth\ConsentTicketService;
use Phlix\Hub\OAuth\OAuthClientRegistry;
use Phlix\Hub\OAuth\OAuthTokenService;
use Phlix\Hub\Http\Controllers\SubdomainController;
use Phlix\Hub\Http\Middleware\AlexaSignatureMiddleware;
use Phlix\Hub\Http\Middleware\EnrollmentJwtMiddleware;
use Phlix\Hub\Http\Middleware\HubProtocolMiddleware;
use Phlix\Hub\Requests\RequestManager;
use Phlix\Hub\Requests\RequestNotification;
use Phlix\Shared\Arr\ArrClientFactory;
use Phlix\Shared\Relay\RelayWireCodecInterface;
use Psr\Container\ContainerInterface;
use Workerman\MySQL\Connection;
use Workerman\Timer;

use function DI\factory;
use function DI\get;

/**
 * Registers the Hub layer (server registry, claim handling, enrollment JWT).
 *
 * Bindings:
 *  - {@see Ed25519KeyManager}       → singleton from config/hub-signing-key.pem
 *  - {@see EnrollmentJwtService}    → singleton with hub_base_url
 *  - {@see ClaimRequestHandler}    → autowired with Connection + KeyManager + Logger
 *  - {@see HeartbeatHandler}        → autowired with Connection + JwtService + Logger
 *  - {@see ServerInfoHandler}        → autowired with Connection
 *  - {@see DeregisterHandler}        → autowired with Connection + JwtService + Logger
 *  - {@see EnrollmentJwtMiddleware} → singleton
 *  - {@see HubProtocolMiddleware}   → singleton
 *  - {@see AlexaSignatureMiddleware} → singleton (holds the per-worker chain cache
 *    and, since S91, the per-worker rate-limit buckets)
 *  - {@see AlexaRejectionAuditorInterface} → {@see AuditLogAlexaRejectionAuditor}
 *  - {@see AlexaAccountLink}         → singleton
 *  - {@see AlexaSkillController}     → singleton
 *  - {@see HubJwksController}        → singleton
 *  - {@see ServerClaimController}    → singleton
 *  - {@see ServerController}         → singleton
 *
 * @package Phlix\Hub\Common\Container\Providers
 */
final class HubServicesProvider implements ServiceProviderInterface
{
    /**
     * @inheritDoc
     */
    public function register(ContainerBuilder $builder, array $appConfig): void
    {
        $keyPath = self::stringOr(
            $appConfig,
            'hub_signing_key_path',
            dirname(__DIR__, 4) . '/config/hub-signing-key.pem',
        );
        $hubBaseUrl = self::stringOr($appConfig, 'hub_base_url', 'http://localhost:8800');

        // 🐛 S269 — the comment that used to sit here claimed "AuditLogRepository
        // must be registered before AuditLogger so PHP-DI can auto-inject it as
        // the optional nullable constructor param". That was wrong on both
        // counts and it is deleted rather than reworded, because it is what kept
        // the defect invisible:
        //
        //  1. PHP-DI does not auto-inject anything into an explicit `factory()`
        //     closure — the closure IS the construction, autowiring is bypassed.
        //     AuthServicesProvider's AuditLogger binding is such a closure, so
        //     for as long as it passed one argument the repository was `null`.
        //  2. Registration ORDER is irrelevant here anyway: PHP-DI merges every
        //     provider's definitions before it resolves any of them, so a
        //     `get(AuditLogRepository::class)` from AuthServicesProvider (which
        //     registers first) resolves this entry perfectly well.
        //
        // AuthServicesProvider now passes the repository explicitly. This entry
        // stays where it is; it just no longer carries a false explanation.
        $builder->addDefinitions([
            AuditLogRepository::class => factory(static function (
                Connection $db,
            ): AuditLogRepository {
                return new AuditLogRepository($db);
            })->parameter('db', get(Connection::class)),
        ]);

        $builder->addDefinitions([
            Ed25519KeyManager::class => factory(static function () use ($keyPath): Ed25519KeyManager {
                return new Ed25519KeyManager($keyPath);
            }),

            // The HubSettingsRepository is injected so createEnrollmentJwt()
            // resolves the EFFECTIVE `server.enrollment_ttl` per mint (admin
            // override → config default) instead of a hardcoded 7 days. That
            // is what keeps the setting's `restart: false` flag honest.
            EnrollmentJwtService::class => factory(static function (
                Ed25519KeyManager $keyManager,
                HubSettingsRepository $settings,
            ) use ($hubBaseUrl): EnrollmentJwtService {
                return new EnrollmentJwtService($keyManager, $hubBaseUrl, $settings);
            })->parameter('keyManager', get(Ed25519KeyManager::class))
                ->parameter('settings', get(HubSettingsRepository::class)),

            ClaimRequestHandler::class => factory(static function (
                Ed25519KeyManager $keyManager,
                AuditLogger $audit,
            ) use ($hubBaseUrl): ClaimRequestHandler {
                return new ClaimRequestHandler(
                    // Dedicated 'txn' connection: isolates the claim transaction
                    // from the cid<0 maintenance reapers on 'mysql' that would
                    // otherwise trip 2014 / "already active transaction" (see
                    // config/database.php).
                    ConnectionPool::getConnection('txn'),
                    $keyManager,
                    LoggerFactory::get(LogChannels::HUB),
                    $audit,
                    $hubBaseUrl,
                );
            })->parameter('keyManager', get(Ed25519KeyManager::class))
                ->parameter('audit', get(AuditLogger::class)),

            HeartbeatHandler::class => factory(static function (
                EnrollmentJwtService $jwtService,
                RateLimiterInterface $rateLimiter,
            ): HeartbeatHandler {
                // Dedicated 'txn' connection (see ClaimRequestHandler above).
                return new HeartbeatHandler(
                    ConnectionPool::getConnection('txn'),
                    $jwtService,
                    LoggerFactory::get(LogChannels::HUB),
                    $rateLimiter,
                );
            })->parameter('jwtService', get(EnrollmentJwtService::class))
                ->parameter('rateLimiter', get(RateLimitProfiles::HEARTBEAT)),

            HubSettingsRepository::class => factory(static function (
                Connection $db,
            ): HubSettingsRepository {
                return new HubSettingsRepository($db);
            })->parameter('db', get(Connection::class)),

            ServerInfoHandler::class => factory(static function (
                Connection $db,
            ): ServerInfoHandler {
                return new ServerInfoHandler($db);
            })->parameter('db', get(Connection::class)),

            // Per-user, server-scoped, revocable client relay token store
            // (Step S2a). TTL configurable via `relay_token_ttl` (seconds);
            // defaults to one hour. Only the SHA-256 hash is persisted.
            ClientRelayTokenService::class => factory(static function (
                Connection $db,
            ) use ($appConfig): ClientRelayTokenService {
                $ttl = is_int($appConfig['relay_token_ttl'] ?? null)
                    ? (int) $appConfig['relay_token_ttl']
                    : ClientRelayTokenService::DEFAULT_TTL_SECONDS;
                return new ClientRelayTokenService($db, $ttl);
            })->parameter('db', get(Connection::class)),

            ClientRelayTokenController::class => factory(static function (
                ClientRelayTokenService $tokens,
                ServerInfoHandler $serverInfo,
                AuditLogger $audit,
            ): ClientRelayTokenController {
                return new ClientRelayTokenController($tokens, $serverInfo, $audit);
            })->parameter('tokens', get(ClientRelayTokenService::class))
                ->parameter('serverInfo', get(ServerInfoHandler::class))
                ->parameter('audit', get(AuditLogger::class)),

            // --- OAuth 2.0 Authorization Server (S92) -----------------------
            // Built once and shared: nothing wired here is Alexa-specific. The
            // Alexa skill is one row in `oauth_clients`; MCP's future
            // spec-correct mode (S63) is another, and `OAuthScopes` already
            // re-exports every `mcp:*` scope so that adoption needs no new
            // vocabulary and no re-issued tokens.
            //
            // The four services are separate bindings rather than one god-object
            // because each owns exactly one table and one lifetime rule, and the
            // token endpoint must be able to consume a code WITHOUT being able to
            // mint one — a single service would put both on the same object.
            OAuthClientRegistry::class => factory(static function (
                Connection $db,
            ): OAuthClientRegistry {
                return new OAuthClientRegistry($db, LoggerFactory::get(LogChannels::AUTH));
            })->parameter('db', get(Connection::class)),

            // TTL configurable via `oauth_consent_ttl` (seconds); defaults to 10
            // minutes. Only the SHA-256 hash of the ticket is persisted.
            ConsentTicketService::class => factory(static function (
                Connection $db,
            ) use ($appConfig): ConsentTicketService {
                $ttl = is_int($appConfig['oauth_consent_ttl'] ?? null)
                    ? (int) $appConfig['oauth_consent_ttl']
                    : ConsentTicketService::DEFAULT_TTL_SECONDS;
                return new ConsentTicketService($db, $ttl);
            })->parameter('db', get(Connection::class)),

            // TTL configurable via `oauth_code_ttl` (seconds); defaults to 60.
            // ⚠ Raising this widens the window in which a leaked code is still
            // redeemable. RFC 6749 §4.1.2 permits up to 10 minutes; the exchange
            // is a server-to-server call that happens within a second.
            AuthorizationCodeService::class => factory(static function (
                Connection $db,
            ) use ($appConfig): AuthorizationCodeService {
                $ttl = is_int($appConfig['oauth_code_ttl'] ?? null)
                    ? (int) $appConfig['oauth_code_ttl']
                    : AuthorizationCodeService::DEFAULT_TTL_SECONDS;
                return new AuthorizationCodeService($db, $ttl);
            })->parameter('db', get(Connection::class)),

            OAuthTokenService::class => factory(static function (
                Connection $db,
            ) use ($appConfig): OAuthTokenService {
                $accessTtl = is_int($appConfig['oauth_access_token_ttl'] ?? null)
                    ? (int) $appConfig['oauth_access_token_ttl']
                    : OAuthTokenService::ACCESS_TTL_SECONDS;
                $refreshTtl = is_int($appConfig['oauth_refresh_token_ttl'] ?? null)
                    ? (int) $appConfig['oauth_refresh_token_ttl']
                    : OAuthTokenService::REFRESH_TTL_SECONDS;
                return new OAuthTokenService($db, $accessTtl, $refreshTtl);
            })->parameter('db', get(Connection::class)),

            OAuthController::class => factory(static function (
                OAuthClientRegistry $clients,
                ConsentTicketService $tickets,
                AuthorizationCodeService $codes,
                OAuthTokenService $tokens,
                AuditLogger $audit,
            ): OAuthController {
                return new OAuthController(
                    $clients,
                    $tickets,
                    $codes,
                    $tokens,
                    $audit,
                    LoggerFactory::get(LogChannels::AUTH),
                );
            })->parameter('clients', get(OAuthClientRegistry::class))
                ->parameter('tickets', get(ConsentTicketService::class))
                ->parameter('codes', get(AuthorizationCodeService::class))
                ->parameter('tokens', get(OAuthTokenService::class))
                ->parameter('audit', get(AuditLogger::class)),

            // --- MCP (S62) --------------------------------------------------
            // Personal access tokens for `POST /mcp`. TTL configurable via
            // `mcp_token_ttl` (seconds); defaults to 90 days. Only the SHA-256
            // hash is persisted, exactly as for the relay token above.
            McpTokenService::class => factory(static function (
                Connection $db,
            ) use ($appConfig): McpTokenService {
                $ttl = is_int($appConfig['mcp_token_ttl'] ?? null)
                    ? (int) $appConfig['mcp_token_ttl']
                    : McpTokenService::DEFAULT_TTL_SECONDS;
                return new McpTokenService($db, $ttl);
            })->parameter('db', get(Connection::class)),

            // S261: the SAME `playback_control` flag that gates registration
            // below also decides what `GET /api/v1/me/mcp-tokens` advertises in
            // `available_scopes`. One reader, one comparison — the token
            // controller must not grow a second interpretation of the flag, or
            // the create form and the tool catalogue can disagree about whether
            // the write capability exists.
            McpTokenController::class => factory(static function (
                McpTokenService $tokens,
                AuditLogger $audit,
            ) use ($appConfig): McpTokenController {
                return new McpTokenController($tokens, $audit, self::mcpPlaybackControlEnabled($appConfig));
            })->parameter('tokens', get(McpTokenService::class))
                ->parameter('audit', get(AuditLogger::class)),

            // The tool catalogue. Listed EXPLICITLY rather than discovered by
            // scanning the Tools/ directory: a scanner would silently publish
            // whatever a future commit happens to drop in there, and the set of
            // capabilities a PAT can reach is not something that should widen by
            // accident. McpToolRegistry throws on a duplicate name or an unknown
            // required scope, so a mis-wired tool fails at container build.
            //
            // S63: `playback_control` is the ONE conditional entry, and the flag
            // gates REGISTRATION rather than invocation. That is the stronger of
            // the two shapes: an unregistered tool is absent from `tools/list`,
            // so a model never sees a capability it cannot use and never spends a
            // turn discovering that. A registered-but-refusing tool would
            // advertise a write the operator has switched off.
            //
            // Default OFF. `mcp.playback_control_enabled` must be `true` — a
            // truthy string, a `1`, or an absent key all leave it off, because
            // `=== true` is the comparison and `config/server.php` resolves the
            // env var to a real bool before it gets here.
            McpToolRegistry::class => factory(static function () use ($appConfig): McpToolRegistry {
                /** @var list<McpToolInterface> $tools */
                $tools = [
                    new ListServersTool(),
                    new ListLibrariesTool(),
                    new SearchMediaTool(),
                    new GetMediaTool(),
                    new GetPlaybackInfoTool(),
                ];

                if (self::mcpPlaybackControlEnabled($appConfig)) {
                    $tools[] = new PlaybackControlTool();
                }

                return new McpToolRegistry($tools);
            }),

            // The SSE transport behind `GET /mcp` (S63). ONE shared instance:
            // it holds no per-stream state (every stream's timers and closed
            // flag live in closures created per connection), so a singleton is
            // correct and a per-request instance would only add allocations in a
            // resident-memory worker.
            McpStreamTimers::class => factory(static fn (): McpStreamTimers => new WorkermanStreamTimers()),

            McpSseStream::class => factory(static function (McpStreamTimers $timers) use ($appConfig): McpSseStream {
                $keepalive = is_int($appConfig['mcp_sse_keepalive_seconds'] ?? null)
                    ? (int) $appConfig['mcp_sse_keepalive_seconds']
                    : McpSseStream::DEFAULT_KEEPALIVE_SECONDS;
                $maxSeconds = is_int($appConfig['mcp_sse_max_seconds'] ?? null)
                    ? (int) $appConfig['mcp_sse_max_seconds']
                    : McpSseStream::DEFAULT_MAX_SECONDS;

                return new McpSseStream($timers, $keepalive, $maxSeconds);
            })->parameter('timers', get(McpStreamTimers::class)),

            // The MCP endpoint itself. It is handed the PRODUCTION
            // ServerProxyController and ServerListController — not copies, not
            // repositories — because their ownership and browse-scope gates are
            // what make a PAT unable to see another user's servers. Anything
            // narrower here would be a second implementation of that check.
            McpController::class => factory(static function (
                McpTokenService $tokens,
                McpToolRegistry $registry,
                ServerProxyController $proxy,
                ServerListController $serverList,
                RateLimiterInterface $rateLimiter,
                McpSseStream $sse,
            ): McpController {
                return new McpController(
                    $tokens,
                    $registry,
                    $proxy,
                    $serverList,
                    $rateLimiter,
                    LoggerFactory::get(LogChannels::AUTH),
                    $sse,
                );
            })->parameter('tokens', get(McpTokenService::class))
                ->parameter('registry', get(McpToolRegistry::class))
                ->parameter('proxy', get(ServerProxyController::class))
                ->parameter('serverList', get(ServerListController::class))
                ->parameter('rateLimiter', get(RateLimitProfiles::MCP))
                ->parameter('sse', get(McpSseStream::class)),

            DeregisterHandler::class => factory(static function (
                Connection $db,
                EnrollmentJwtService $jwtService,
            ): DeregisterHandler {
                return new DeregisterHandler($db, $jwtService, LoggerFactory::get(LogChannels::HUB));
            })->parameter('db', get(Connection::class))
                ->parameter('jwtService', get(EnrollmentJwtService::class)),

            RenewHandler::class => factory(static function (
                Connection $db,
                EnrollmentJwtService $jwtService,
            ): RenewHandler {
                return new RenewHandler($db, $jwtService, LoggerFactory::get(LogChannels::HUB));
            })->parameter('db', get(Connection::class))
                ->parameter('jwtService', get(EnrollmentJwtService::class)),

            EnrollmentJwtMiddleware::class => factory(static function (
                EnrollmentJwtService $jwtService,
            ): EnrollmentJwtMiddleware {
                return new EnrollmentJwtMiddleware($jwtService);
            })->parameter('jwtService', get(EnrollmentJwtService::class)),

            HubProtocolMiddleware::class => factory(static function (): HubProtocolMiddleware {
                return new HubProtocolMiddleware();
            }),

            // --- Alexa (S90 gate + S91 skill) --------------------------------
            // The rejection auditor. Bound to its INTERFACE so the DB write is
            // substitutable in a unit test that has no database, and given the
            // AuditLogRepository DIRECTLY rather than the shared AuditLogger.
            //
            // S269 reconciliation: when S91 wrote this binding, the direct
            // injection was a WORKAROUND — AuditLogger's own repository argument
            // was never supplied, so routing through it would have written
            // nothing. That is fixed; AuditLogger now persists. The direct
            // injection nevertheless STAYS, for a different and now-accurate
            // reason: AuditLogger exposes one method per fixed event slug and
            // none of them accepts `ipAddress`/`userAgent`, which an Alexa
            // signature rejection must record, nor can any of them emit the
            // `alexa_rejected` slug the dashboard filters on. Giving AuditLogger
            // such a method would be "adding a new audit event", explicitly out
            // of S269's scope. These are two writers to one table, not two
            // mechanisms for one job.
            AlexaRejectionAuditorInterface::class => factory(static function (
                AuditLogRepository $auditLogs,
            ): AlexaRejectionAuditorInterface {
                return new AuditLogAlexaRejectionAuditor(
                    $auditLogs,
                    LoggerFactory::get(LogChannels::AUTH),
                );
            })->parameter('auditLogs', get(AuditLogRepository::class)),

            // S90. Registered as a singleton on purpose: the middleware holds
            // the per-worker cache of VERIFIED certificate chains, so a
            // per-request instance would silently turn every Alexa request back
            // into a blocking https fetch inside the worker — AND, since S91, the
            // per-worker rate-limit buckets, which a per-request instance would
            // reset on every call and thereby delete the limit entirely.
            //
            // ⚠ Do NOT let PHP-DI autowire this. `autowire()` SKIPS optional
            // constructor parameters, which would leave $caBundlePaths at its
            // default silently; here the default IS the production value ([] =
            // the system trust store) and it is passed explicitly so that a
            // future non-empty value cannot be lost the same way.
            AlexaSignatureMiddleware::class => factory(static function (
                RateLimiterInterface $rateLimiter,
                AlexaRejectionAuditorInterface $auditor,
            ): AlexaSignatureMiddleware {
                return new AlexaSignatureMiddleware(
                    new CurlCertChainFetcher(),
                    LoggerFactory::get(LogChannels::AUTH),
                    $rateLimiter,
                    $auditor,
                    [],
                );
            })->parameter('rateLimiter', get(RateLimitProfiles::ALEXA))
                ->parameter('auditor', get(AlexaRejectionAuditorInterface::class)),

            // S91. The seam a later step replaces when the hub issues its own
            // OAuth tokens: everything downstream depends on the resolved hub
            // user id, not on how the token was validated.
            AlexaAccountLink::class => factory(static function (
                JwtHandler $jwtHandler,
                UserRepository $users,
            ): AlexaAccountLink {
                return new AlexaAccountLink($jwtHandler, $users);
            })->parameter('jwtHandler', get(JwtHandler::class))
                ->parameter('users', get(UserRepository::class)),

            // S91. Handed the PRODUCTION ServerProxyController and
            // ServerListController — not copies, not repositories — for the same
            // reason McpController is: their ownership and browse-scope gates are
            // what stop an Alexa slot value reaching another user's server, and
            // anything narrower here would be a second implementation of that
            // check. Nothing was added to the proxy's allowlist for this skill.
            AlexaSkillController::class => factory(static function (
                AlexaAccountLink $accountLink,
                ServerProxyController $proxy,
                ServerListController $serverList,
            ) use ($hubBaseUrl): AlexaSkillController {
                return new AlexaSkillController(
                    $accountLink,
                    $proxy,
                    $serverList,
                    LoggerFactory::get(LogChannels::AUTH),
                    $hubBaseUrl,
                );
            })->parameter('accountLink', get(AlexaAccountLink::class))
                ->parameter('proxy', get(ServerProxyController::class))
                ->parameter('serverList', get(ServerListController::class)),

            HubJwksController::class => factory(static function (
                Ed25519KeyManager $keyManager,
                RateLimiterInterface $rateLimiter,
            ): HubJwksController {
                return new HubJwksController($keyManager, $rateLimiter);
            })->parameter('keyManager', get(Ed25519KeyManager::class))
                ->parameter('rateLimiter', get(RateLimitProfiles::JWKS)),

            ServerClaimController::class => factory(static function (
                ClaimRequestHandler $handler,
            ): ServerClaimController {
                return new ServerClaimController($handler);
            })->parameter('handler', get(ClaimRequestHandler::class)),

            ServerController::class => factory(static function (
                HeartbeatHandler $heartbeatHandler,
                ServerInfoHandler $serverInfoHandler,
                DeregisterHandler $deregisterHandler,
                RenewHandler $renewHandler,
            ): ServerController {
                return new ServerController(
                    $heartbeatHandler,
                    $serverInfoHandler,
                    $deregisterHandler,
                    $renewHandler,
                );
            })->parameter('heartbeatHandler', get(HeartbeatHandler::class))
                ->parameter('serverInfoHandler', get(ServerInfoHandler::class))
                ->parameter('deregisterHandler', get(DeregisterHandler::class))
                ->parameter('renewHandler', get(RenewHandler::class)),

            RelaySessionManager::class => factory(static function (
                Connection $db,
            ): RelaySessionManager {
                return new RelaySessionManager($db, LoggerFactory::get(LogChannels::RELAY));
            })->parameter('db', get(Connection::class)),

            RelayServerHandler::class => factory(static function (
                RelaySessionManager $sessionManager,
                EnrollmentJwtService $jwtService,
            ): RelayServerHandler {
                return new RelayServerHandler(
                    $sessionManager,
                    $jwtService,
                    LoggerFactory::get(LogChannels::RELAY),
                    'hub-relay-handler',
                );
            })->parameter('sessionManager', get(RelaySessionManager::class))
                ->parameter('jwtService', get(EnrollmentJwtService::class)),

            RelayController::class => factory(static function (
                EnrollmentJwtService $jwtService,
            ): RelayController {
                return new RelayController($jwtService);
            })->parameter('jwtService', get(EnrollmentJwtService::class)),

            ClientMountController::class => factory(static function (
                ContainerInterface $container,
            ): ClientMountController {
                // No limiter: the HTTP handle() is a dead stub (426/501 → steer to
                // the WS endpoint). The REAL client-mount rate limit lives on the
                // :8803 ClientRelayWorker WS surface (HB-4.6f).
                return new ClientMountController($container);
            })->parameter('container', get(ContainerInterface::class)),

            FrameDecoder::class => factory(static function (): FrameDecoder {
                return new FrameDecoder();
            }),

            FrameEncoder::class => factory(static function (
                FrameDecoder $decoder,
            ): FrameEncoder {
                return new FrameEncoder($decoder);
            })->parameter('decoder', get(FrameDecoder::class)),

            RelayWireCodecInterface::class => factory(static function (
                FrameDecoder $decoder,
            ): RelayWireCodecInterface {
                return $decoder;
            })->parameter('decoder', get(FrameDecoder::class)),

            TunnelManager::class => factory(static function (
                RelaySessionManager $sessionManager,
                RelayWireCodecInterface $codec,
                EnrollmentJwtService $jwtService,
            ): TunnelManager {
                /** @var array<string, mixed> $serverConfig */
                $serverConfig = require dirname(__DIR__, 4) . '/config/server.php';
                /** @var array<string, mixed> $relayConfig */
                $relayConfig = is_array($serverConfig['relay'] ?? null) ? $serverConfig['relay'] : [];
                $graceSeconds = is_numeric($relayConfig['reconnect_drain_grace_seconds'] ?? null)
                    ? (float) $relayConfig['reconnect_drain_grace_seconds']
                    : TunnelManager::DEFAULT_RECONNECT_DRAIN_GRACE_SECONDS;

                return new TunnelManager(
                    $sessionManager,
                    $codec,
                    LoggerFactory::get(LogChannels::RELAY),
                    $jwtService,
                    $graceSeconds,
                );
            })->parameter('sessionManager', get(RelaySessionManager::class))
                ->parameter('codec', get(RelayWireCodecInterface::class))
                ->parameter('jwtService', get(EnrollmentJwtService::class)),

            RelayProxyManager::class => factory(static function (
                TunnelManagerInterface $tunnelManager,
                MetricsCollector $metrics,
            ): RelayProxyManager {
                // The metrics collector is the per-worker SHARED singleton
                // (MetricsServicesProvider) — the SAME instance the relay worker's
                // flush timer drains — so the pending-gauge / reply-drop / latency /
                // 503 / 504 the proxy manager records land in the drained registry.
                // The collector no-ops every record call when metrics are disabled,
                // so injecting it unconditionally is safe.
                return new RelayProxyManager(
                    $tunnelManager,
                    LoggerFactory::get(LogChannels::RELAY),
                    metrics: $metrics,
                );
            })->parameter('tunnelManager', get(TunnelManagerInterface::class))
                ->parameter('metrics', get(MetricsCollector::class)),

            RelayProxyBridge::class => factory(static function (
                MetricsCollector $metrics,
            ): RelayProxyBridge {
                // Same per-worker SHARED MetricsCollector singleton the relay
                // worker's flush timer drains (MetricsServicesProvider) — so the
                // channel-push reply-drop the bridge records in dropReply() lands
                // in the drained relay_reply_drops counter (migration 036). The
                // collector no-ops every record when metrics are disabled, so
                // injecting it unconditionally is safe.
                return new RelayProxyBridge(
                    LoggerFactory::get(LogChannels::RELAY),
                    metrics: $metrics,
                );
            })->parameter('metrics', get(MetricsCollector::class)),

            // Alias the interface to the concrete TunnelManager so callers
            // that depend on the abstraction (RelayWorker, ClientRelayWorker,
            // ClientMountController, IdleReaper) all resolve the *same*
            // singleton tunnel registry. Without this binding the relay
            // workers would fail to resolve TunnelManagerInterface at runtime.
            TunnelManagerInterface::class => factory(static function (
                TunnelManager $tunnelManager,
            ): TunnelManagerInterface {
                return $tunnelManager;
            })->parameter('tunnelManager', get(TunnelManager::class)),

            IdleReaper::class => factory(static function (
                TunnelManager $tunnelManager,
                RelaySessionManager $sessionManager,
                HeartbeatHandler $heartbeatHandler,
                ClientRelayTokenService $clientRelayTokenService,
                Ed25519KeyManager $keyManager,
                McpTokenService $mcpTokenService,
            ) use ($appConfig): IdleReaper {
                /** @var int $interval */
                $interval = is_int($appConfig['relay_idle_reaper_interval'] ?? null)
                    ? (int) $appConfig['relay_idle_reaper_interval']
                    : IdleReaper::DEFAULT_INTERVAL_SECONDS;
                /** @var int $staleThreshold */
                $staleThreshold = is_int($appConfig['relay_stale_threshold'] ?? null)
                    ? (int) $appConfig['relay_stale_threshold']
                    : IdleReaper::DEFAULT_STALE_THRESHOLD_SECONDS;

                return new IdleReaper(
                    $tunnelManager,
                    LoggerFactory::get(LogChannels::RELAY),
                    $interval,
                    $staleThreshold,
                    $sessionManager,
                    $heartbeatHandler,
                    $clientRelayTokenService,
                    $keyManager,
                    $mcpTokenService,
                );
            })->parameter('tunnelManager', get(TunnelManager::class))
                ->parameter('sessionManager', get(RelaySessionManager::class))
                ->parameter('heartbeatHandler', get(HeartbeatHandler::class))
                ->parameter('clientRelayTokenService', get(ClientRelayTokenService::class))
                ->parameter('keyManager', get(Ed25519KeyManager::class))
                // S62: explicit, like every sibling above. PHP-DI's `autowire()`
                // SKIPS optional constructor parameters, so a nullable dependency
                // added without a `->parameter()` line here resolves to null and
                // the pruner silently never runs.
                ->parameter('mcpTokenService', get(McpTokenService::class)),

            ServerReaper::class => factory(static function (
                Connection $db,
            ) use ($appConfig): ServerReaper {
                /** @var int $interval */
                $interval = is_int($appConfig['server_reaper_interval'] ?? null)
                    ? (int) $appConfig['server_reaper_interval']
                    : ServerReaper::DEFAULT_INTERVAL_SECONDS;
                /** @var int $offlineThreshold */
                $offlineThreshold = is_int($appConfig['server_offline_threshold'] ?? null)
                    ? (int) $appConfig['server_offline_threshold']
                    : ServerReaper::DEFAULT_OFFLINE_THRESHOLD_SECONDS;
                /** @var int $retention */
                $retention = is_int($appConfig['heartbeat_retention_days'] ?? null)
                    ? (int) $appConfig['heartbeat_retention_days']
                    : ServerReaper::DEFAULT_HEARTBEAT_RETENTION_DAYS;

                return new ServerReaper(
                    $db,
                    LoggerFactory::get(LogChannels::HUB),
                    $interval,
                    $offlineThreshold,
                    $retention,
                );
            })->parameter('db', get(Connection::class)),

            StaticZoneManager::class => factory(static function () use ($appConfig): StaticZoneManager {
                $zoneDir = self::stringOr($appConfig, 'dns_zone_dir', '/home/phlix/data/dns/zones');
                return new StaticZoneManager($zoneDir);
            }),

            TlsCertificateManager::class => factory(static function () use ($appConfig): TlsCertificateManager {
                $certsDir = self::stringOr($appConfig, 'tls_certs_dir', '/home/phlix/data/tls');
                $acmeEmail = self::stringOr($appConfig, 'acme_email', 'admin@phlix.media');
                return new TlsCertificateManager($certsDir, $acmeEmail, LoggerFactory::get(LogChannels::HUB));
            }),

            DnsAliasManager::class => factory(static function (
                Connection $db,
                StaticZoneManager $zoneManager,
                TlsCertificateManager $certManager,
            ) use ($appConfig): DnsAliasManager {
                $providerType = self::stringOr($appConfig, 'dns_provider', 'static');
                return new DnsAliasManager(
                    $db,
                    $zoneManager,
                    $certManager,
                    LoggerFactory::get(LogChannels::HUB),
                    $providerType,
                );
            })->parameter('db', get(Connection::class))
                ->parameter('zoneManager', get(StaticZoneManager::class))
                ->parameter('certManager', get(TlsCertificateManager::class)),

            RelayRouter::class => factory(static function (
                DnsAliasManager $dnsAliasManager,
                RelaySessionManager $sessionManager,
            ): RelayRouter {
                return new RelayRouter($dnsAliasManager, $sessionManager);
            })->parameter('dnsAliasManager', get(DnsAliasManager::class))
                ->parameter('sessionManager', get(RelaySessionManager::class)),

            SubdomainController::class => factory(static function (
                DnsAliasManager $dnsAliasManager,
                TlsCertificateManager $certManager,
                EnrollmentJwtService $jwtService,
            ): SubdomainController {
                return new SubdomainController($dnsAliasManager, $certManager, $jwtService);
            })->parameter('dnsAliasManager', get(DnsAliasManager::class))
                ->parameter('certManager', get(TlsCertificateManager::class))
                ->parameter('jwtService', get(EnrollmentJwtService::class)),

            LibrarySharingHandler::class => factory(static function (
                Connection $db,
                UserRepository $users,
            ): LibrarySharingHandler {
                return new LibrarySharingHandler(
                    $db,
                    $users,
                    LoggerFactory::get(LogChannels::HUB),
                );
            })->parameter('db', get(Connection::class))
                ->parameter('users', get(UserRepository::class)),

            LibraryShareController::class => factory(static function (
                LibrarySharingHandler $handler,
            ): LibraryShareController {
                return new LibraryShareController($handler);
            })->parameter('handler', get(LibrarySharingHandler::class)),

            InviteLinkHandler::class => factory(static function (
                Connection $db,
                JwtHandler $jwtHandler,
                LibrarySharingHandler $sharingHandler,
            ) use ($hubBaseUrl): InviteLinkHandler {
                return new InviteLinkHandler(
                    $db,
                    $jwtHandler,
                    $sharingHandler,
                    LoggerFactory::get(LogChannels::HUB),
                    $hubBaseUrl,
                );
            })->parameter('db', get(Connection::class))
                ->parameter('jwtHandler', get(JwtHandler::class))
                ->parameter('sharingHandler', get(LibrarySharingHandler::class)),

            InviteLinkController::class => factory(static function (
                InviteLinkHandler $handler,
            ): InviteLinkController {
                return new InviteLinkController($handler);
            })->parameter('handler', get(InviteLinkHandler::class)),

            LibraryController::class => factory(static function (
                LibrarySharingHandler $sharingHandler,
            ): LibraryController {
                return new LibraryController($sharingHandler);
            })->parameter('sharingHandler', get(LibrarySharingHandler::class)),

            ArrClientFactory::class => factory(static function () use ($appConfig): ArrClientFactory {
                /** @var array{sonarr?: array{url?: string, api_key?: string, enabled?: bool}, radarr?: array{url?: string, api_key?: string, enabled?: bool}} $arrConfig */
                $arrConfig = is_array($appConfig['arr'] ?? null) ? $appConfig['arr'] : [];
                return new ArrClientFactory($arrConfig);
            }),

            RequestManager::class => factory(static function (
                Connection $db,
                ArrClientFactory $arrClientFactory,
            ): RequestManager {
                return new RequestManager(
                    $db,
                    $arrClientFactory,
                    LoggerFactory::get(LogChannels::HUB),
                );
            })->parameter('db', get(Connection::class))
                ->parameter('arrClientFactory', get(ArrClientFactory::class)),

            RequestNotification::class => factory(static function (): RequestNotification {
                return new RequestNotification(LoggerFactory::get(LogChannels::HUB));
            }),

            RequestController::class => factory(static function (
                RequestManager $manager,
                RequestNotification $notification,
                UserRepository $users,
                AuditLogger $audit,
            ): RequestController {
                return new RequestController($manager, $notification, $users, $audit);
            })->parameter('manager', get(RequestManager::class))
                ->parameter('notification', get(RequestNotification::class))
                ->parameter('users', get(UserRepository::class))
                ->parameter('audit', get(AuditLogger::class)),

            ServerDetailController::class => factory(static function (
                ServerInfoHandler $serverInfo,
                RelaySessionManager $relayManager,
                HeartbeatHandler $heartbeat,
                TlsCertificateManager $tls,
            ): ServerDetailController {
                return new ServerDetailController($serverInfo, $relayManager, $heartbeat, $tls);
            })->parameter('serverInfo', get(ServerInfoHandler::class))
                ->parameter('relayManager', get(RelaySessionManager::class))
                ->parameter('heartbeat', get(HeartbeatHandler::class))
                ->parameter('tls', get(TlsCertificateManager::class)),

            ServerProxyController::class => factory(static function (
                ServerInfoHandler $serverInfo,
                RelayProxyBridge $bridge,
                RelaySessionManager $sessionManager,
                RateLimiterInterface $rateLimiter,
            ): ServerProxyController {
                return new ServerProxyController(
                    $serverInfo,
                    $bridge,
                    LoggerFactory::get(LogChannels::RELAY),
                    $sessionManager,
                    $rateLimiter,
                );
            })->parameter('serverInfo', get(ServerInfoHandler::class))
                ->parameter('bridge', get(RelayProxyBridge::class))
                ->parameter('sessionManager', get(RelaySessionManager::class))
                ->parameter('rateLimiter', get(RateLimitProfiles::PROXY)),

            HubSettingsController::class => factory(static function (
                HubSettingsRepository $settings,
            ): HubSettingsController {
                return new HubSettingsController($settings);
            })->parameter('settings', get(HubSettingsRepository::class)),

            // --- Core update check (S75 / updates.md #48) -------------------
            // The fetcher is bound to its INTERFACE so the service can never
            // acquire a blocking implementation by accident; the concrete
            // class is the only thing that touches the network, and it does so
            // through workerman/http-client's callback API (event-loop driven,
            // never blocking, no coroutine fork).
            VersionMarkerFetcherInterface::class => factory(
                static function (): AsyncVersionMarkerFetcher {
                    return new AsyncVersionMarkerFetcher(self::updatesInt('timeout_seconds', 10));
                },
            ),

            CoreUpdateCheckService::class => factory(static function (
                HubSettingsRepository $settings,
                VersionMarkerFetcherInterface $fetcher,
            ): CoreUpdateCheckService {
                return new CoreUpdateCheckService(
                    $settings,
                    $fetcher,
                    LoggerFactory::get(LogChannels::HUB),
                    self::updatesString(
                        'marker_url',
                        'https://raw.githubusercontent.com/detain/phlix-hub/master/VERSION',
                    ),
                    self::updatesString(
                        'update_command',
                        'curl -fsSL https://raw.githubusercontent.com/detain/phlix-hub/master/scripts/install.sh'
                        . ' | sudo bash -s -- --update -y',
                    ),
                );
            })->parameter('settings', get(HubSettingsRepository::class))
                ->parameter('fetcher', get(VersionMarkerFetcherInterface::class)),

            CoreUpdateCheckWorker::class => factory(static function (
                CoreUpdateCheckService $service,
            ): CoreUpdateCheckWorker {
                return new CoreUpdateCheckWorker(
                    $service,
                    LoggerFactory::get(LogChannels::HUB),
                    self::updatesInt('poll_seconds', CoreUpdateCheckWorker::DEFAULT_INTERVAL_SECONDS),
                );
            })->parameter('service', get(CoreUpdateCheckService::class)),

            AdminUpdatesController::class => factory(static function (
                CoreUpdateCheckService $updates,
                UserRepository $users,
                AuditLogger $audit,
            ): AdminUpdatesController {
                return new AdminUpdatesController($updates, $users, $audit);
            })->parameter('updates', get(CoreUpdateCheckService::class))
                ->parameter('users', get(UserRepository::class))
                ->parameter('audit', get(AuditLogger::class)),

            // Phase 10: graceful hub reload via SIGUSR2 (POST /api/v1/admin/restart).
            // The pid path MUST be the config/server.php value — start.php assigns
            // Worker::$pidFile from the very same key, so writer and reader cannot
            // drift. The fallback mirrors start.php's own fallback (install var/),
            // never /var/run, which nothing creates under the hardened unit.
            HubRestartController::class => factory(static function (
                ContainerInterface $c,
            ): HubRestartController {
                /** @var array<string, mixed> $appConfig */
                $appConfig = $c->get('app.config');
                /** @var non-empty-string $pidFile */
                $pidFile = is_string($appConfig['pid_file'] ?? null) && $appConfig['pid_file'] !== ''
                    ? $appConfig['pid_file']
                    : dirname(__DIR__, 4) . '/var/hub.pid';
                return new HubRestartController($pidFile);
            }),

            // Per-user relay bandwidth quota + concurrent-stream cap HTTP surface
            // (HB-3.4 G5). Self usage under /api/v1/me/bandwidth; admin set/view
            // under /api/v1/admin/users/{id}/{quota,bandwidth}. Gated
            // [auth]/[auth, admin] at the route group in
            // Application::registerUserQuotaRoutes(), plus the controller's own
            // requireAdmin() defence-in-depth.
            UserQuotaController::class => factory(static function (
                RelaySessionManager $sessionManager,
                UserRepository $users,
                AuditLogger $audit,
            ): UserQuotaController {
                return new UserQuotaController($sessionManager, $users, $audit);
            })->parameter('sessionManager', get(RelaySessionManager::class))
                ->parameter('users', get(UserRepository::class))
                ->parameter('audit', get(AuditLogger::class)),

            // Shared admin console user-management API (hubby.md H1.3). Reuses
            // the existing UserRepository and AuditLogger; gated [auth, admin]
            // at the route group in Application::registerAdminUserRoutes().
            AdminUserController::class => factory(static function (
                UserRepository $users,
                AuditLogger $audit,
            ): AdminUserController {
                return new AdminUserController($users, $audit);
            })->parameter('users', get(UserRepository::class))
                ->parameter('audit', get(AuditLogger::class)),

            AuditLogController::class => factory(static function (
                AuditLogRepository $repo,
            ): AuditLogController {
                return new AuditLogController($repo);
            })->parameter('repo', get(AuditLogRepository::class)),

            // Shared admin console dashboard API (hubby.md H1.4). Injects the
            // Connection for the summary counters and reuses AuditLogRepository
            // for the activity feed; gated [auth, admin] at the route group in
            // Application::registerAdminDashboardRoutes().
            AdminDashboardController::class => factory(static function (
                Connection $db,
                AuditLogRepository $auditLogs,
            ): AdminDashboardController {
                return new AdminDashboardController($db, $auditLogs);
            })->parameter('db', get(Connection::class))
                ->parameter('auditLogs', get(AuditLogRepository::class)),

            // Admin log viewer (mirrors phlix-server). Resolves to the hub's
            // .logs/ directory — the same dir config/logger.php writes to. The
            // path is resolved + jailed inside LogController so traversal and
            // symlinks cannot escape it.
            LogController::class => factory(static function (): LogController {
                return new LogController(dirname(__DIR__, 4) . '/.logs');
            }),

            FederationHubRepository::class => factory(static function (
                Connection $db,
            ): FederationHubRepository {
                return new FederationHubRepository($db);
            })->parameter('db', get(Connection::class)),

            FederationSessionManager::class => factory(static function (
                Connection $db,
                StructuredLogger $logger,
            ): FederationSessionManager {
                return new FederationSessionManager($db, $logger);
            })->parameter('db', get(Connection::class))
                ->parameter('logger', get(StructuredLogger::class)),

            FederationLibraryShareRepository::class => factory(static function (
                Connection $db,
            ): FederationLibraryShareRepository {
                return new FederationLibraryShareRepository($db);
            })->parameter('db', get(Connection::class)),

            FederationAdminDelegationRepository::class => factory(static function (
                Connection $db,
            ): FederationAdminDelegationRepository {
                return new FederationAdminDelegationRepository($db);
            })->parameter('db', get(Connection::class)),

            FederationConnectionManager::class => factory(static function (): FederationConnectionManager {
                return new FederationConnectionManager();
            }),

            FederationFrameHandler::class => factory(static function (
                FederationHubRepository $hubRepo,
                FederationSessionManager $sessions,
                FederationLibraryShareRepository $libraryShares,
                FederationConnectionManager $connMgr,
                AuditLogger $audit,
            ): FederationFrameHandler {
                return new FederationFrameHandler(
                    $hubRepo,
                    $sessions,
                    $libraryShares,
                    $connMgr,
                    $audit,
                );
            })->parameter('hubRepo', get(FederationHubRepository::class))
                ->parameter('sessions', get(FederationSessionManager::class))
                ->parameter('libraryShares', get(FederationLibraryShareRepository::class))
                ->parameter('connMgr', get(FederationConnectionManager::class))
                ->parameter('audit', get(AuditLogger::class)),

            FederationRelayController::class => factory(static function (
                FederationFrameHandler $frameHandler,
                FederationConnectionManager $connMgr,
            ): FederationRelayController {
                return new FederationRelayController($frameHandler, $connMgr);
            })->parameter('frameHandler', get(FederationFrameHandler::class))
                ->parameter('connMgr', get(FederationConnectionManager::class)),

            FederationController::class => factory(static function (
                FederationHubRepository $hubRepo,
                FederationSessionManager $sessions,
                FederationLibraryShareRepository $libraryShares,
                FederationAdminDelegationRepository $adminDel,
                FederationPeerManager $peerManager,
                AuditLogger $audit,
            ): FederationController {
                return new FederationController(
                    $hubRepo,
                    $sessions,
                    $libraryShares,
                    $adminDel,
                    $peerManager,
                    $audit,
                );
            })->parameter('hubRepo', get(FederationHubRepository::class))
                ->parameter('sessions', get(FederationSessionManager::class))
                ->parameter('libraryShares', get(FederationLibraryShareRepository::class))
                ->parameter('adminDel', get(FederationAdminDelegationRepository::class))
                ->parameter('peerManager', get(FederationPeerManager::class))
                ->parameter('audit', get(AuditLogger::class)),

            FederationPeerManager::class => factory(static function (
                FederationHubRepository $hubRepo,
                FederationSessionManager $sessions,
                FederationLibraryShareRepository $libraryShares,
                FederationAdminDelegationRepository $adminDel,
                AuditLogger $audit,
            ): FederationPeerManager {
                return new FederationPeerManager(
                    $hubRepo,
                    $sessions,
                    $libraryShares,
                    $adminDel,
                    $audit,
                );
            })->parameter('hubRepo', get(FederationHubRepository::class))
                ->parameter('sessions', get(FederationSessionManager::class))
                ->parameter('libraryShares', get(FederationLibraryShareRepository::class))
                ->parameter('adminDel', get(FederationAdminDelegationRepository::class))
                ->parameter('audit', get(AuditLogger::class)),

            // Metrics API controller (S4). Read by Application::registerMetricsRoutes().
            MetricsController::class => factory(static function (
                MetricsRepositoryInterface $repo,
            ): MetricsController {
                return new MetricsController($repo);
            })->parameter('repo', get(MetricsRepositoryInterface::class)),
        ]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function stringOr(array $config, string $key, string $default): string
    {
        /** @var mixed $value */
        $value = $config[$key] ?? null;
        return is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * The `playback_control` operator flag (S63) — DEFAULT OFF.
     *
     * The one MCP tool that can change server-side state is off unless an
     * operator has deliberately turned it on. `=== true` is the comparison, not
     * a truthiness test: `config/server.php` resolves
     * `HUB_MCP_PLAYBACK_CONTROL` through `FILTER_VALIDATE_BOOLEAN` before it
     * reaches here, so anything that is not a real `true` — a missing key, the
     * string `"maybe"`, a stray `0` — leaves the write tool unregistered. A
     * loose check would let a mistyped env value publish it.
     *
     * ⚠ This is the whole gate. There is no second check inside the tool, on
     * purpose: two places to switch a feature off is two places for one of them
     * to be wrong, and this one is upstream of everything (the tool is never
     * constructed, never registered, never listed, never callable).
     *
     * 🚨 **Flipping this to `true` is a retroactive grant** (S261). Every MCP
     * token minted before S261 that carries `mcp:playback:control` — which,
     * because the scope used to be the DEFAULT, is most of them — becomes able
     * to drive playback the instant this returns `true`, with no re-mint and no
     * audit event. S261 chose not to strip those rows; the reasoning and the
     * review query an operator should run first are written out in
     * `config/server.php` beside the setting itself, which is where somebody
     * turning it on is actually looking.
     *
     * @param array<string, mixed> $config The `server` config array.
     */
    private static function mcpPlaybackControlEnabled(array $config): bool
    {
        /** @var mixed $value */
        $value = $config['mcp_playback_control_enabled'] ?? null;

        return $value === true;
    }

    /**
     * Decoded `config/updates.php`, memoised per process (S75).
     *
     * Shared read-only config, not request data, so caching it on the class is
     * resident-memory-safe. `null` marks "the file is absent or unusable" and
     * every reader then falls back to its own literal default, so a missing
     * config file degrades the update check rather than breaking boot.
     *
     * @var array<array-key, mixed>|null
     */
    private static ?array $updatesConfig = null;

    /** Whether {@see $updatesConfig} has been resolved yet. */
    private static bool $updatesConfigLoaded = false;

    /**
     * Load (once) and return `config/updates.php`.
     *
     * @return array<array-key, mixed>
     */
    private static function updatesConfig(): array
    {
        if (!self::$updatesConfigLoaded) {
            self::$updatesConfigLoaded = true;
            $path = dirname(__DIR__, 4) . '/config/updates.php';
            if (is_file($path)) {
                /** @var mixed $loaded */
                $loaded = include $path;
                self::$updatesConfig = is_array($loaded) ? $loaded : null;
            }
        }

        return self::$updatesConfig ?? [];
    }

    /**
     * A string entry of `config/updates.php`, or `$default`.
     *
     * @param string $key     Config key.
     * @param string $default Fallback when absent/blank.
     */
    private static function updatesString(string $key, string $default): string
    {
        /** @var mixed $value */
        $value = self::updatesConfig()[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * A positive-int entry of `config/updates.php`, or `$default`.
     *
     * @param string $key     Config key.
     * @param int    $default Fallback when absent/non-positive.
     */
    private static function updatesInt(string $key, int $default): int
    {
        /** @var mixed $value */
        $value = self::updatesConfig()[$key] ?? null;

        return is_int($value) && $value > 0 ? $value : $default;
    }

    /**
     * PSR-11 container instance used during boot.
     *
     * @var ContainerInterface|null
     */
    private static ?ContainerInterface $container = null;

    /**
     * Set the static container instance used by boot().
     *
     * @param ContainerInterface $container PSR-11 container.
     *
     * @return void
     */
    public static function setContainer(ContainerInterface $container): void
    {
        self::$container = $container;
    }

    /**
     * Boot the hub services that must be wired in the MASTER process, before
     * {@see \Workerman\Worker::runAll()} forks the workers.
     *
     * That is limited to work which genuinely belongs pre-fork: creating the
     * {@see FederationWorker} (a Worker must be constructed before runAll) and
     * bootstrapping the leaf→master federation WS connection.
     *
     * The periodic maintenance timers are deliberately NOT armed here — see the
     * inline note below. They are split by data locality (HB-2.6): the in-memory
     * tunnel reapers ({@see startInMemoryReapers()}) run on the relay worker and
     * the DB-only reapers ({@see startDbMaintenanceTimers()}) on the maintenance
     * worker.
     *
     * @return void
     */
    public static function boot(): void
    {
        $container = self::$container;
        if ($container === null) {
            return;
        }

        // The periodic maintenance timers (idle-tunnel reaper, server offline /
        // heartbeat-retention reaper, tunnel heartbeat, federation-session
        // reaper) are deliberately NOT armed here. boot() runs in the MASTER
        // process, where Workerman\Timer::add has no event loop yet and falls
        // back to the pcntl signal scheduler, so the callbacks fire with NO
        // Swoole coroutine context (cid<0). At cid<0 PhlixMySQLConnection::query()
        // BYPASSES its per-connection mutex, so a reaper query lands mid-flight on
        // the shared socket a request coroutine holds a transaction on → 2014 /
        // "There is already an active transaction" → heartbeat/claim/auth 500s.
        // They are armed instead — ONCE, at cid>=0 — from within a running
        // worker's event loop, split by data locality (HB-2.6): the in-memory
        // tunnel reaper + keepalive heartbeat by {@see startInMemoryReapers()} in
        // {@see \Phlix\Hub\Relay\RelayWorker::onWorkerStart()} (which owns the
        // live tunnel registry), and the DB-only reapers by
        // {@see startDbMaintenanceTimers()} on the dedicated maintenance worker.

        // Start the federation WebSocket worker for hub-to-hub connections. This
        // creates a Worker, which MUST happen in the master before runAll().
        try {
            $federationWorker = new FederationWorker($container);
            $federationWorker->start();
        } catch (\Throwable) {
            // FederationWorker not available in this context — skip
        }

        // Metrics flush timer: deliberately NOT armed here. boot() runs in the
        // MASTER process (pre-fork), so a flush timer + collector resolved here
        // would drain a different registry instance than the per-worker request
        // and connection hooks populate. Each producing worker instead resolves
        // its own collector AND arms its own flush timer inside onWorkerStart
        // (see Application::run()'s HTTP worker and RelayWorker::onWorkerStart()).

        // Bootstrap leaf hub WS connection to master hub
        try {
            /** @var mixed $peerManager */
            $peerManager = $container->get(FederationPeerManager::class);
            if ($peerManager instanceof FederationPeerManager) {
                $peerManager->connectToMaster();
            }
        } catch (\Throwable) {
            // FederationPeerManager not available in this context — skip
        }
    }

    /**
     * Arm the hub's IN-MEMORY periodic reapers from WITHIN the RELAY worker's
     * event loop.
     *
     * These tasks operate on state that lives ONLY in the relay-worker process
     * (:8802): the live {@see TunnelManager} registry of {@see Tunnel} objects
     * and the per-session byte/last-frame accumulators inside
     * {@see \Phlix\Hub\Hub\RelaySessionManager}. They therefore MUST be armed
     * here — {@see \Phlix\Hub\Relay\RelayWorker::onWorkerStart()} — and NOT on
     * the dedicated maintenance worker, whose separate fork owns an EMPTY
     * TunnelManager + empty accumulators. (HB-2.6 originally moved ALL reapers to
     * the maintenance worker, which silently broke HB-0.1's idle/half-open
     * tunnel reaping and the tunnel keepalive heartbeat + byte/last-frame
     * persistence, because those scan the live registry that the maintenance
     * fork does not have — this method restores them to the owning process.)
     *
     * Armed from within the running worker's loop so {@see \Workerman\Timer::add()}
     * takes the Swoole event-loop path and callbacks fire inside a coroutine
     * (cid>=0), keeping DB writes serialised behind
     * {@see \Phlix\Hub\Common\Database\PhlixMySQLConnection}'s per-connection
     * mutex (the 2026-06/07 "already active transaction" incident — never arm in
     * the master {@see boot()} at cid<0).
     *
     * Call this ONCE: the relay worker is count=1 (so each timer runs once
     * hub-wide) and it owns the {@see TunnelManager} both timers scan.
     *
     * The DB-only reapers/pruners are armed separately on the maintenance worker
     * by {@see startDbMaintenanceTimers()}.
     *
     * Each timer is armed independently and guarded, so one unavailable service
     * never blocks the others.
     *
     * @param ContainerInterface $container Relay-worker-local PSR-11 container.
     *
     * @return void
     */
    public static function startInMemoryReapers(ContainerInterface $container): void
    {
        $logger = LoggerFactory::get(LogChannels::RELAY);

        // Idle-tunnel reaper (HB-0.1): closes tunnels idle past the stale window
        // and flushes the in-memory byte/last-frame accumulators. Scans the live
        // TunnelManager registry — relay-worker-resident.
        try {
            /** @var mixed $idleReaper */
            $idleReaper = $container->get(IdleReaper::class);
            if ($idleReaper instanceof IdleReaper) {
                $idleReaper->start();
            }
        } catch (\Throwable $e) {
            $logger->error('Maintenance: failed to start IdleReaper timer', ['error' => $e->getMessage()]);
        }

        // Tunnel keepalive heartbeat: ping every active tunnel every 30s so the
        // server receives an inbound hub frame well within its stale window (X9).
        // Iterates the live registry — relay-worker-resident.
        try {
            /** @var mixed $tunnelManager */
            $tunnelManager = $container->get(TunnelManager::class);
            if ($tunnelManager instanceof TunnelManager) {
                Timer::add(
                    30,
                    static function () use ($tunnelManager): void {
                        foreach ($tunnelManager->allTunnels() as $tunnel) {
                            $tunnel->sendHeartbeat();
                        }
                    },
                );
            }
        } catch (\Throwable $e) {
            $logger->error('Maintenance: failed to start tunnel-heartbeat timer', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Arm the hub's DB-ONLY periodic reapers/pruners from WITHIN the dedicated
     * maintenance worker's event loop.
     *
     * These tasks are entirely database-backed and do NOT depend on the live
     * tunnel registry or the in-memory accumulators, so they run correctly on
     * the maintenance worker's own DB connection — the whole point of HB-2.6 is
     * to keep this DB latency off the relay worker's frame-processing loop
     * (H-A3/H-A4/H-D5). The in-memory reapers that DO need the live registry are
     * armed on the relay worker by {@see startInMemoryReapers()}.
     *
     * Armed from within the maintenance worker's loop (cid>=0), same coroutine /
     * per-connection-mutex rationale as {@see startInMemoryReapers()}.
     *
     * Each timer is armed independently and guarded, so one unavailable service
     * never blocks the others.
     *
     * @param ContainerInterface $container Maintenance-worker-local PSR-11 container.
     *
     * @return void
     */
    public static function startDbMaintenanceTimers(ContainerInterface $container): void
    {
        $logger = LoggerFactory::get(LogChannels::RELAY);

        // Idle-reaper DB-maintenance sweep: stale-session reap + heartbeat/token
        // prune (HB-4.2/HB-4.3). DB-only — no tunnel-registry access.
        try {
            /** @var mixed $idleReaper */
            $idleReaper = $container->get(IdleReaper::class);
            if ($idleReaper instanceof IdleReaper) {
                $idleReaper->startDbMaintenance();
            }
        } catch (\Throwable $e) {
            $logger->error(
                'Maintenance: failed to start IdleReaper DB-maintenance timer',
                ['error' => $e->getMessage()],
            );
        }

        // Server offline-reaper + heartbeat-retention sweep (B2 + P2 on one timer).
        try {
            /** @var mixed $serverReaper */
            $serverReaper = $container->get(ServerReaper::class);
            if ($serverReaper instanceof ServerReaper) {
                $serverReaper->start();
            }
        } catch (\Throwable $e) {
            $logger->error('Maintenance: failed to start ServerReaper timer', ['error' => $e->getMessage()]);
        }

        // Federation session reaper: drop federation sessions with no heartbeat
        // for 60s, swept every 60s.
        try {
            /** @var mixed $federationSessionMgr */
            $federationSessionMgr = $container->get(FederationSessionManager::class);
            if ($federationSessionMgr instanceof FederationSessionManager) {
                Timer::add(
                    60,
                    static function () use ($federationSessionMgr): void {
                        $federationSessionMgr->reapDeadSessions(60);
                    },
                );
            }
        } catch (\Throwable $e) {
            $logger->error(
                'Maintenance: failed to start federation-session reaper timer',
                ['error' => $e->getMessage()],
            );
        }

        // Core update check (S75): daily poll of the remote VERSION marker,
        // preceded by a boot catch-up inside CoreUpdateCheckWorker::start().
        // It belongs on THIS worker (count=1, its own DB connection, its own
        // event loop) rather than the HTTP workers: the poll is once-hub-wide,
        // it writes hub_settings rows, and it must never share a tick with a
        // request. It is DB+HTTP only — no tunnel-registry access — so the
        // maintenance fork's empty TunnelManager is irrelevant to it.
        try {
            /** @var mixed $updateWorker */
            $updateWorker = $container->get(CoreUpdateCheckWorker::class);
            if ($updateWorker instanceof CoreUpdateCheckWorker) {
                $updateWorker->start();
            }
        } catch (\Throwable $e) {
            $logger->error(
                'Maintenance: failed to start core update check timer',
                ['error' => $e->getMessage()],
            );
        }
    }
}
