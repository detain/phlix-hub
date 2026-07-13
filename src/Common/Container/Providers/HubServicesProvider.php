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
use Phlix\Hub\Http\Controllers\AdminUserController;
use Phlix\Hub\Http\Controllers\AuditLogController;
use Phlix\Hub\Http\Controllers\FederationController;
use Phlix\Hub\Http\Controllers\LogController;
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
use Phlix\Hub\Common\Container\ServiceProviderInterface;
use Phlix\Hub\Common\Database\ConnectionPool;
use Phlix\Hub\Common\RateLimit\RateLimiterInterface;
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
use Phlix\Hub\Http\Controllers\ServerProxyController;
use Phlix\Hub\Http\Controllers\SubdomainController;
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

        // AuditLogRepository must be registered before AuditLogger so PHP-DI
        // can auto-inject it as the optional nullable constructor param.
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

            EnrollmentJwtService::class => factory(static function (
                Ed25519KeyManager $keyManager,
            ) use ($hubBaseUrl): EnrollmentJwtService {
                return new EnrollmentJwtService($keyManager, $hubBaseUrl);
            })->parameter('keyManager', get(Ed25519KeyManager::class)),

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
                ->parameter('rateLimiter', get(RateLimiterInterface::class)),

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

            HubJwksController::class => factory(static function (
                Ed25519KeyManager $keyManager,
                RateLimiterInterface $rateLimiter,
            ): HubJwksController {
                return new HubJwksController($keyManager, $rateLimiter);
            })->parameter('keyManager', get(Ed25519KeyManager::class))
                ->parameter('rateLimiter', get(RateLimiterInterface::class)),

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
                RateLimiterInterface $rateLimiter,
            ): ClientMountController {
                return new ClientMountController($container, $rateLimiter);
            })->parameter('container', get(ContainerInterface::class))
                ->parameter('rateLimiter', get(RateLimiterInterface::class)),

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
                );
            })->parameter('tunnelManager', get(TunnelManager::class))
                ->parameter('sessionManager', get(RelaySessionManager::class))
                ->parameter('heartbeatHandler', get(HeartbeatHandler::class))
                ->parameter('clientRelayTokenService', get(ClientRelayTokenService::class)),

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
                return new ServerProxyController($serverInfo, $bridge, LoggerFactory::get(LogChannels::RELAY), $sessionManager, $rateLimiter);
            })->parameter('serverInfo', get(ServerInfoHandler::class))
                ->parameter('bridge', get(RelayProxyBridge::class))
                ->parameter('sessionManager', get(RelaySessionManager::class))
                ->parameter('rateLimiter', get(RateLimiterInterface::class)),

            HubSettingsController::class => factory(static function (
                HubSettingsRepository $settings,
            ): HubSettingsController {
                return new HubSettingsController($settings);
            })->parameter('settings', get(HubSettingsRepository::class)),

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
    }
}
