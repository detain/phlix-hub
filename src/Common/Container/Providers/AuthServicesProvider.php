<?php

declare(strict_types=1);

namespace Phlix\Hub\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Hub\Auth\AuthManager;
use Phlix\Hub\Auth\JwtHandler;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Common\Container\MissingJwtSecretException;
use Phlix\Hub\Common\Container\ServiceProviderInterface;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\LogChannels;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Common\RateLimit\RateLimiterInterface;
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
 *  - {@see AuthManager} → autowired with the {@see RateLimiterInterface}
 *    (login attempt limiter, registered by {@see CommonServicesProvider})
 *    injected and the dispatcher optional.
 *
 * @package Phlix\Hub\Common\Container\Providers
 */
final class AuthServicesProvider implements ServiceProviderInterface
{
    /**
     * Env flag that explicitly opts a non-dev/local environment into the
     * insecure random dev-secret fallback. Set to a truthy value
     * ("1"/"true"/"yes"/"on") ONLY for local development. Production must
     * provide a real `HUB_JWT_SECRET`.
     */
    public const DEV_SECRET_ENV_FLAG = 'HUB_JWT_ALLOW_DEV_SECRET';

    /**
     * Test seam for the loud dev-fallback warning. Defaults to the AUTH
     * channel logger; tests may swap in a spy so no output leaks under
     * PHPUnit's strict output checking.
     *
     * @var (callable(string): void)|null
     */
    private static $devFallbackWarner = null;

    /**
     * Override the dev-fallback warning sink (tests only).
     *
     * @param (callable(string): void)|null $warner
     *
     * @internal
     */
    public static function setDevFallbackWarner(?callable $warner): void
    {
        self::$devFallbackWarner = $warner;
    }

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
                RateLimiterInterface $rateLimiter,
                ?EventDispatcherInterface $dispatcher,
                Connection $db,
            ): AuthManager {
                return new AuthManager($repo, $jwt, $audit, $logger, $rateLimiter, $dispatcher, $db);
            })->parameter('logger', get('logger.' . LogChannels::AUTH))
                ->parameter('dispatcher', null),
        ]);
    }

    /**
     * Pull the JWT secret from env first, then config.
     *
     * When neither a `HUB_JWT_SECRET` env (≥32 bytes) nor a `secret` config
     * value (≥32 bytes) is present:
     *  - In production (the env is NOT dev/local and the dev-secret flag is
     *    not set) this THROWS {@see MissingJwtSecretException}, so the hub
     *    refuses to boot rather than silently minting a random per-process
     *    secret (which would invalidate tokens on restart and, with
     *    `workers > 1`, mint a different secret per worker → intermittent
     *    auth failures).
     *  - In development (env `dev`/`local`, or the explicit
     *    {@see self::DEV_SECRET_ENV_FLAG} truthy) it falls back to a random
     *    secret and logs a loud warning.
     *
     * @param array<string, mixed> $authConfig
     *
     * @throws MissingJwtSecretException When no secret is configured in a
     *                                   non-development environment.
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

        if (!self::devFallbackAllowed()) {
            throw new MissingJwtSecretException(
                'No JWT secret configured: set the HUB_JWT_SECRET environment variable '
                . '(at least 32 bytes) or the auth.secret config value. The insecure '
                . 'random fallback is only available in development (APP_ENV/HUB_ENV '
                . 'dev|local, or ' . self::DEV_SECRET_ENV_FLAG . '=1).',
            );
        }

        self::warnDevFallback();
        return bin2hex(random_bytes(32));
    }

    /**
     * Is the insecure random dev-secret fallback permitted in this
     * environment? True only when the runtime env is `dev`/`local` or the
     * explicit {@see self::DEV_SECRET_ENV_FLAG} is truthy.
     */
    private static function devFallbackAllowed(): bool
    {
        if (self::isTruthyEnv(self::DEV_SECRET_ENV_FLAG)) {
            return true;
        }

        foreach (['APP_ENV', 'HUB_ENV'] as $key) {
            $value = getenv($key);
            if (is_string($value)) {
                $normalized = strtolower(trim($value));
                if ($normalized === 'dev' || $normalized === 'development' || $normalized === 'local') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Truthy-env test for boolean-ish flags ("1"/"true"/"yes"/"on").
     */
    private static function isTruthyEnv(string $key): bool
    {
        $value = getenv($key);
        if (!is_string($value)) {
            return false;
        }
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Emit the loud warning for the dev-only random-secret fallback through
     * the test seam (default: AUTH-channel structured logger).
     */
    private static function warnDevFallback(): void
    {
        $message = 'HUB_JWT_SECRET is not set; using an insecure random per-process JWT '
            . 'secret. This is for development only — tokens will not survive a restart '
            . 'and will break across multiple workers. Set HUB_JWT_SECRET in production.';

        if (self::$devFallbackWarner !== null) {
            (self::$devFallbackWarner)($message);
            return;
        }

        LoggerFactory::get(LogChannels::AUTH)->warning($message);
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
