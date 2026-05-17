<?php

declare(strict_types=1);

namespace Phlex\Hub\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlex\Hub\Auth\JwtHandler;
use Phlex\Hub\Auth\UserRepository;
use Phlex\Hub\Hub\ClaimRequestHandler;
use Phlex\Hub\Hub\DeregisterHandler;
use Phlex\Hub\Hub\DnsAliasManager;
use Phlex\Hub\Hub\Dns\StaticZoneManager;
use Phlex\Hub\Hub\Ed25519KeyManager;
use Phlex\Hub\Hub\EnrollmentJwtService;
use Phlex\Hub\Hub\HeartbeatHandler;
use Phlex\Hub\Hub\InviteLinkHandler;
use Phlex\Hub\Hub\LibrarySharingHandler;
use Phlex\Hub\Hub\RelayRouter;
use Phlex\Hub\Hub\RelayServerHandler;
use Phlex\Hub\Hub\RelaySessionManager;
use Phlex\Hub\Hub\ServerInfoHandler;
use Phlex\Hub\Hub\TlsCertificateManager;
use Phlex\Hub\Common\Container\ServiceProviderInterface;
use Phlex\Hub\Common\Logger\LogChannels;
use Phlex\Hub\Common\Logger\LoggerFactory;
use Phlex\Hub\Common\Logger\StructuredLogger;
use Phlex\Hub\Http\Controllers\HubJwksController;
use Phlex\Hub\Http\Controllers\InviteLinkController;
use Phlex\Hub\Http\Controllers\LibraryShareController;
use Phlex\Hub\Http\Controllers\RelayController;
use Phlex\Hub\Http\Controllers\ServerClaimController;
use Phlex\Hub\Http\Controllers\ServerController;
use Phlex\Hub\Http\Controllers\SubdomainController;
use Phlex\Hub\Http\Middleware\EnrollmentJwtMiddleware;
use Phlex\Hub\Http\Middleware\HubProtocolMiddleware;
use Workerman\MySQL\Connection;

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
 * @package Phlex\Hub\Common\Container\Providers
 * @since 0.3.0
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
                Connection $db,
                Ed25519KeyManager $keyManager,
            ) use ($hubBaseUrl): ClaimRequestHandler {
                return new ClaimRequestHandler(
                    $db,
                    $keyManager,
                    LoggerFactory::get(LogChannels::HUB),
                    $hubBaseUrl,
                );
            })->parameter('db', get(Connection::class))
                ->parameter('keyManager', get(Ed25519KeyManager::class)),

            HeartbeatHandler::class => factory(static function (
                Connection $db,
                EnrollmentJwtService $jwtService,
            ): HeartbeatHandler {
                return new HeartbeatHandler($db, $jwtService, LoggerFactory::get(LogChannels::HUB));
            })->parameter('db', get(Connection::class))
                ->parameter('jwtService', get(EnrollmentJwtService::class)),

            ServerInfoHandler::class => factory(static function (
                Connection $db,
            ): ServerInfoHandler {
                return new ServerInfoHandler($db);
            })->parameter('db', get(Connection::class)),

            DeregisterHandler::class => factory(static function (
                Connection $db,
                EnrollmentJwtService $jwtService,
            ): DeregisterHandler {
                return new DeregisterHandler($db, $jwtService, LoggerFactory::get(LogChannels::HUB));
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
            ): ServerController {
                return new ServerController($heartbeatHandler, $serverInfoHandler, $deregisterHandler);
            })->parameter('heartbeatHandler', get(HeartbeatHandler::class))
                ->parameter('serverInfoHandler', get(ServerInfoHandler::class))
                ->parameter('deregisterHandler', get(DeregisterHandler::class)),

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

            StaticZoneManager::class => factory(static function () use ($appConfig): StaticZoneManager {
                $zoneDir = self::stringOr($appConfig, 'dns_zone_dir', '/home/phlex/data/dns/zones');
                return new StaticZoneManager($zoneDir);
            }),

            TlsCertificateManager::class => factory(static function () use ($appConfig): TlsCertificateManager {
                $certsDir = self::stringOr($appConfig, 'tls_certs_dir', '/home/phlex/data/tls');
                $acmeEmail = self::stringOr($appConfig, 'acme_email', 'admin@phlex.media');
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
}
