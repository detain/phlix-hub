<?php

declare(strict_types=1);

namespace Phlix\Hub\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Hub\Auth\AuthManager;
use Phlix\Hub\Auth\JwtHandler;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Common\Container\ServiceProviderInterface;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\LogChannels;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Psr\EventDispatcher\EventDispatcherInterface;
use Workerman\MySQL\Connection;

use function DI\factory;
use function DI\get;

/**
 * Registers the auth stack with the container.
 *
 * Bindings:
 *  - {@see JwtHandler} → singleton built from `HUB_JWT_SECRET` (env) or
 *    `config('auth.secret')` (file).
 *  - {@see UserRepository} → autowired from {@see Connection}.
 *  - {@see AuditLogger} → singleton bound to {@see LogChannels::AUDIT}.
 *  - {@see AuthManager} → autowired with dispatcher optional.
 *
 * @package Phlix\Hub\Common\Container\Providers
 */
final class AuthServicesProvider implements ServiceProviderInterface
{
    /**
     * @inheritDoc
     */
    public function register(ContainerBuilder $builder, array $appConfig): void
    {
        $authConfig = self::resolveAuthConfig($appConfig);
        $secret = self::resolveSecret($authConfig);
        $accessTtl = self::intOr($authConfig, 'access_ttl', 3600);
        $refreshTtl = self::intOr($authConfig, 'refresh_ttl', 604800);
        $issuer = self::stringOr($authConfig, 'issuer', 'phlix-hub');
        $audience = self::stringOr($authConfig, 'audience', 'hub');

        $builder->addDefinitions([
            JwtHandler::class => factory(static function () use (
                $secret,
                $issuer,
                $audience,
                $accessTtl,
                $refreshTtl,
            ): JwtHandler {
                return new JwtHandler($secret, $issuer, $audience, $accessTtl, $refreshTtl);
            }),

            UserRepository::class => factory(static function (Connection $db): UserRepository {
                return new UserRepository($db);
            }),

            AuditLogger::class => factory(static function (): AuditLogger {
                return new AuditLogger(LoggerFactory::get(LogChannels::AUDIT));
            }),

            AuthManager::class => factory(static function (
                UserRepository $repo,
                JwtHandler $jwt,
                AuditLogger $audit,
                StructuredLogger $logger,
                ?EventDispatcherInterface $dispatcher,
                Connection $db,
            ): AuthManager {
                return new AuthManager($repo, $jwt, $audit, $logger, $dispatcher, $db);
            })->parameter('logger', get('logger.' . LogChannels::AUTH))
                ->parameter('dispatcher', null),
        ]);
    }

    /**
     * Pull the JWT secret from env first, then config. Generate a process-
     * local random secret as a last resort so the container still boots in
     * dev (logged as a warning on first use). NEVER rely on the fallback
     * in production — set HUB_JWT_SECRET.
     *
     * @param array<string, mixed> $authConfig
     */
    private static function resolveSecret(array $authConfig): string
    {
        $env = getenv('HUB_JWT_SECRET');
        if (is_string($env) && strlen($env) >= 32) {
            return $env;
        }
        /**
         * @var mixed $configured
         * @psalm-suppress MixedAssignment
         */
        $configured = $authConfig['secret'] ?? null;
        if (is_string($configured) && strlen($configured) >= 32) {
            return $configured;
        }
        // Dev fallback only.
        return bin2hex(random_bytes(32));
    }

    /**
     * Load `config/auth.php` if it's referenced in the app config; else
     * return a defaults stub.
     *
     * @param array<string, mixed> $appConfig
     *
     * @return array<string, mixed>
     */
    private static function resolveAuthConfig(array $appConfig): array
    {
        /**
         * @var mixed $path
         * @psalm-suppress MixedAssignment
         */
        $path = $appConfig['auth_config_path'] ?? null;
        if (is_string($path) && is_file($path)) {
            /**
             * @psalm-suppress UnresolvableInclude
             * @var mixed $loaded
             */
            $loaded = include $path;
            if (is_array($loaded)) {
                $out = [];
                /**
                 * @var mixed $v
                 * @psalm-suppress MixedAssignment
                 */
                foreach ($loaded as $k => $v) {
                    if (is_string($k)) {
                        $out[$k] = $v;
                    }
                }
                return $out;
            }
        }
        return [];
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function intOr(array $config, string $key, int $default): int
    {
        /**
         * @var mixed $value
         * @psalm-suppress MixedAssignment
         */
        $value = $config[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        return $default;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function stringOr(array $config, string $key, string $default): string
    {
        /**
         * @var mixed $value
         * @psalm-suppress MixedAssignment
         */
        $value = $config[$key] ?? null;
        return is_string($value) && $value !== '' ? $value : $default;
    }
}
