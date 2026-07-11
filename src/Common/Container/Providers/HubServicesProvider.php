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
            ): HeartbeatHandler {
                // Dedicated 'txn' connection (see ClaimRequestHandler above).
                return new HeartbeatHandler(
                    ConnectionPool::getConnection('txn'),
                    $jwtService,
                    LoggerFactory::get(LogChannels::HUB),
                );
            })->parameter('jwtService', get(EnrollmentJwtService::class)),

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
            ): HubJwksController {
                return new HubJwksController($keyManager);
            })->parameter('keyManager', get(Ed25519KeyManager::class)),

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
                return new ClientMountController($container);
            }),

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
                return new TunnelManager(
                    $sessionManager,
                    $codec,
                    LoggerFactory::get(LogChannels::RELAY),
                    $jwtService,
                );
            })->parameter('sessionManager', get(RelaySessionManager::class))
                ->parameter('codec', get(RelayWireCodecInterface::class))
                ->parameter('jwtService', get(EnrollmentJwtService::class)),

            RelayProxyManager::class => factory(static function (
                TunnelManagerInterface $tunnelManager,
            ): RelayProxyManager {
                return new RelayProxyManager(
                    $tunnelManager,
                    LoggerFactory::get(LogChannels::RELAY),
                );
            })->parameter('tunnelManager', get(TunnelManagerInterface::class)),

            RelayProxyBridge::class => factory(static function (): RelayProxyBridge {
                return new RelayProxyBridge(LoggerFactory::get(LogChannels::RELAY));
            }),

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
                );
            })->parameter('tunnelManager', get(TunnelManager::class))
                ->parameter('sessionManager', get(RelaySessionManager::class)),

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
            ): ServerProxyController {
                return new ServerProxyController($serverInfo, $bridge, LoggerFactory::get(LogChannels::RELAY), $sessionManager);
            })->parameter('serverInfo', get(ServerInfoHandler::class))
                ->parameter('bridge', get(RelayProxyBridge::class))
                ->parameter('sessionManager', get(RelaySessionManager::class)),

            HubSettingsController::class => factory(static function (
                HubSettingsRepository $settings,
            ): HubSettingsController {
                return new HubSettingsController($settings);
            })->parameter('settings', get(HubSettingsRepository::class)),

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
     * The periodic DB-touching maintenance timers (idle reaper, server reaper,
     * tunnel heartbeat, federation-session reaper) are deliberately NOT armed
     * here — see the inline note below and {@see startMaintenanceTimers()}.
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
        // They are armed instead — ONCE, at cid>=0 — from within the running
        // relay worker's event loop by {@see startMaintenanceTimers()} (called in
        // {@see \Phlix\Hub\Relay\RelayWorker::onWorkerStart()}).

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
     * Arm the hub's periodic maintenance timers from WITHIN a running worker's
     * event loop, so {@see \Workerman\Timer::add()} takes the Swoole event-loop
     * path (Timer\Swoole → Coroutine::create) and every callback fires inside a
     * coroutine (cid>=0). That keeps each reaper's DB queries serialised behind
     * {@see \Phlix\Hub\Common\Database\PhlixMySQLConnection}'s per-connection
     * mutex, alongside the request coroutines.
     *
     * Arming them in {@see boot()} instead — the master process, before the
     * event loop exists — used Workerman's pcntl signal-timer fallback, whose
     * callbacks run with NO coroutine context (cid<0). At cid<0 the connection
     * mutex is bypassed, so a reaper query barged into a request coroutine's
     * in-flight transaction on the shared socket → "SQLSTATE[HY000] 2014
     * unbuffered queries active" → corrupted transaction → the next
     * beginTrans() throwing "There is already an active transaction" →
     * heartbeat/claim/auth 500s (the 2026-06/07 production incident).
     *
     * Call this ONCE, from {@see \Phlix\Hub\Relay\RelayWorker::onWorkerStart()}:
     * the relay worker is count=1 (so each timer runs once hub-wide, not once
     * per HTTP worker) and it already owns the {@see TunnelManager} the idle
     * reaper and tunnel-heartbeat loop scan.
     *
     * Each timer is armed independently and guarded, so one unavailable service
     * never blocks the others.
     *
     * @param ContainerInterface $container Worker-local PSR-11 container.
     *
     * @return void
     */
    public static function startMaintenanceTimers(ContainerInterface $container): void
    {
        $logger = LoggerFactory::get(LogChannels::RELAY);

        // Idle-tunnel reaper: closes tunnels idle past the window and reaps
        // stale relay sessions (via its RelaySessionManager).
        try {
            /** @var mixed $idleReaper */
            $idleReaper = $container->get(IdleReaper::class);
            if ($idleReaper instanceof IdleReaper) {
                $idleReaper->start();
            }
        } catch (\Throwable $e) {
            $logger->error('Maintenance: failed to start IdleReaper timer', ['error' => $e->getMessage()]);
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

        // Tunnel heartbeat: ping every active tunnel every 30s.
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
