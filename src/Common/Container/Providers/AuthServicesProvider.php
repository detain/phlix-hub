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
use Phlix\Hub\Auth\AuthManager;
use Phlix\Hub\Auth\JwtHandler;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Common\Container\MissingJwtSecretException;
use Phlix\Hub\Common\Container\ServiceProviderInterface;
use Phlix\Hub\Common\Database\ConnectionPool;
use Phlix\Hub\Common\Logger\AuditLogger;
use Phlix\Hub\Common\Logger\LogChannels;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Common\RateLimit\RateLimiterInterface;
use Phlix\Hub\Hub\HubSettingsRepository;
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
        // ⚠️ These key names MUST match config/auth.php verbatim. intOr()
        // falls back to its literal when the key is absent, so a rename there
        // does not fail loudly — it just silently ignores HUB_JWT_ACCESS_TTL /
        // HUB_JWT_REFRESH_TTL. Covered by AuthServicesProviderTest's
        // "env var reaches the minted token" regression tests.
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
                return new JwtHandler(
                    $secret,
                    $issuer,
                    $audience,
                    $accessTtl,
                    $refreshTtl,
                    self::makeTtlResolver(),
                );
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
            ): AuthManager {
                // Dedicated 'txn' connection: AuthManager wraps login/register in
                // an explicit transaction, so isolate it from the cid<0
                // maintenance reapers on 'mysql' that would otherwise trip 2014 /
                // "already active transaction" (see config/database.php).
                return new AuthManager(
                    $repo,
                    $jwt,
                    $audit,
                    $logger,
                    $rateLimiter,
                    $dispatcher,
                    ConnectionPool::getConnection('txn'),
                );
            })->parameter('logger', get('logger.' . LogChannels::AUTH))
                ->parameter('dispatcher', null),
        ]);
    }

    /**
     * Build the live-TTL resolver handed to {@see JwtHandler}.
     *
     * This is the wiring that makes the `auth.access_ttl` / `auth.refresh_ttl`
     * hub settings genuinely live: the returned closure reads the EFFECTIVE
     * value ({@see HubSettingsRepository::getEffective()} — override row, else
     * `config/auth.php` default) at token-mint time, so an admin edit applies
     * to the very next login with no worker restart.
     *
     * Deliberately does NOT resolve {@see HubSettingsRepository} out of the
     * PHP-DI container: first-time container resolution from inside a
     * coroutine can trip PHP-DI's `entriesBeingResolved` race. The repository
     * is a thin wrapper over a {@see Connection}, so it is built directly from
     * the static {@see ConnectionPool} and memoised in the closure instead.
     *
     * The memoised repository is shared *configuration* access, never
     * per-request state, so holding it in the closure is resident-memory-safe.
     * Every failure path (pool not initialised under PHPUnit, DB down, a
     * non-numeric override) degrades to the caller's boot-time fallback.
     *
     * @return callable(string, int): int
     */
    private static function makeTtlResolver(): callable
    {
        $repository = null;

        return static function (string $key, int $fallback) use (&$repository): int {
            if (!$repository instanceof HubSettingsRepository) {
                // Not booted (unit tests, CLI smoke commands): there is no
                // store to consult, so the config default IS the effective
                // value. Checked explicitly rather than caught, because
                // ConnectionPool::getConnection() on an uninitialised pool
                // emits warnings instead of throwing.
                if (ConnectionPool::getInstance() === null) {
                    return $fallback;
                }
                $repository = new HubSettingsRepository(ConnectionPool::getConnection('mysql'));
            }

            /** @var mixed $value */
            $value = $repository->getEffective($key);

            if (is_int($value)) {
                return $value;
            }

            return is_numeric($value) ? (int) $value : $fallback;
        };
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
