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
use Phlix\Hub\Common\Container\ServiceProviderInterface;
use Phlix\Hub\Common\RateLimit\DbRateLimiter;
use Phlix\Hub\Common\RateLimit\RateLimiter;
use Phlix\Hub\Common\RateLimit\RateLimiterInterface;
use Phlix\Hub\Common\RateLimit\RateLimitProfiles;
use Phlix\Hub\Health\MaintenanceHeartbeat;
use Workerman\MySQL\Connection;

use function DI\factory;
use function DI\get;

/**
 * Registers cross-cutting "common" primitives that are not tied to a
 * single subsystem.
 *
 * Binds one limiter instance PER SURFACE (HB-4.6a) — the historical single
 * login-grade limiter (5 / 900s) was mis-injected into proxy, heartbeat and
 * JWKS, which would trip normal operation. Each surface named in
 * {@see RateLimitProfiles} is registered under its own container id
 * (`rate_limiter.login`, `rate_limiter.proxy`, …) with its own
 * `{max, window}` sourced from `config/server.php`'s `rate_limit` section
 * (see {@see RateLimitProfiles::defaults()} for the per-worker defaults).
 * Every id resolves to a DISTINCT instance so no two surfaces share a window.
 *
 * The `login` surface is the ONE exception: it resolves to the shared,
 * DB-backed {@see DbRateLimiter} (migration 040 `login_rate_limit`) so the
 * brute-force counter is unified across all `HUB_WORKERS` HTTP workers
 * (HB-4.6 Option B) — the per-worker in-memory limiter left the effective
 * login budget at ~`max × HUB_WORKERS`. The other five surfaces stay on the
 * worker-local in-memory {@see RateLimiter} (per-worker weakening is
 * acceptable there).
 *
 * Back-compat: the legacy {@see RateLimiterInterface} binding is aliased to the
 * `login` profile so services that inject the interface directly (notably
 * {@see \Phlix\Hub\Auth\AuthManager}) keep resolving — now to the DB-backed
 * limiter. The concrete {@see RateLimiter}-class alias is deliberately dropped:
 * the login profile is a {@see DbRateLimiter}, so aliasing the concrete class to
 * it would be a type lie, and no call site injects the concrete class.
 *
 * Config shape (mirrors the `metrics` block in `config/server.php`):
 * ```php
 * 'rate_limit' => [
 *     'cap'   => 10000,                       // shared key-count ceiling
 *     'login' => ['max' => 5,   'window' => 900],
 *     'proxy' => ['max' => 600, 'window' => 60],
 *     // … one entry per surface; each falls back to RateLimitProfiles defaults
 * ],
 * ```
 *
 * @package Phlix\Hub\Common\Container\Providers
 */
final class CommonServicesProvider implements ServiceProviderInterface
{
    /** Default ceiling on retained keys per worker. */
    private const int DEFAULT_CAP = 10000;

    /**
     * @inheritDoc
     */
    public function register(ContainerBuilder $builder, array $appConfig): void
    {
        /**
         * @var mixed $section
         * @psalm-suppress MixedAssignment
         */
        $section = $appConfig['rate_limit'] ?? null;
        $rateLimit = is_array($section) ? $section : [];

        $cap = self::intOr($rateLimit, 'cap', self::DEFAULT_CAP);

        $definitions = [];

        foreach (RateLimitProfiles::defaults() as $id => $spec) {
            /**
             * @var mixed $surfaceRaw
             * @psalm-suppress MixedAssignment
             */
            $surfaceRaw = $rateLimit[$spec['key']] ?? null;
            $surface = is_array($surfaceRaw) ? $surfaceRaw : [];

            $max = self::intOr($surface, 'max', $spec['max']);
            $window = self::intOr($surface, 'window', $spec['window']);

            if ($id === RateLimitProfiles::LOGIN || $id === RateLimitProfiles::MCP) {
                // HB-4.6 (Option B) + S62: the two bearer-CREDENTIAL-GUESSING
                // surfaces — password login and MCP personal-access-token auth —
                // are the ones where per-worker weakening (~max × HUB_WORKERS) is
                // a real brute-force concern, so both are backed by the shared,
                // DB-backed limiter (migration 040 `login_rate_limit`, whose
                // `rate_key` column is an OPAQUE bucket key, not an IP, precisely
                // so more than one surface can share the store) that unifies the
                // counter across every HTTP worker. They keep SEPARATE profiles
                // (`rate_limit.login` vs `rate_limit.mcp`) so an operator can tune
                // them apart; the mechanism is one, not two. The Connection is the
                // pooled 'mysql' one (autowired via Connection::class — bound to
                // ConnectionPool::getConnection('mysql') in CoreServicesProvider),
                // NOT the dedicated 'txn' connection: these are single-statement
                // reads/writes, not a multi-statement transaction. The other five
                // surfaces stay worker-local in-memory below.
                $definitions[$id] = factory(
                    static fn (Connection $db): DbRateLimiter => new DbRateLimiter($db, $window, $max)
                );
                continue;
            }

            // Arrow fn captures $window/$max/$cap BY VALUE at definition time,
            // so each surface gets its own thresholds (and its own instance).
            $definitions[$id] = factory(
                static fn (): RateLimiter => new RateLimiter($window, $max, $cap)
            );
        }

        // Back-compat: legacy consumers still inject the RateLimiterInterface
        // directly (notably AuthManager's login limiter); alias it to the login
        // profile — now the shared DB-backed limiter. The concrete-class alias is
        // intentionally NOT registered: the login profile is a DbRateLimiter, not
        // a RateLimiter, so aliasing RateLimiter::class to it would be a type lie;
        // no call site injects the concrete class (all use the named profiles or
        // the interface), and PHP-DI can still autowire a default RateLimiter if
        // one is ever requested directly.
        $definitions[RateLimiterInterface::class] = get(RateLimitProfiles::LOGIN);

        // S312 — the maintenance worker's cross-process liveness record.
        //
        // Registered HERE, and not in HubServicesProvider, because BOTH forks
        // need it from the same definition: the maintenance worker WRITES it
        // from inside its guarded sweep, and the HTTP workers READ it to answer
        // /health. Every fork builds its own container from the same providers,
        // so one registration serves both.
        //
        // Explicit `factory()` rather than autowiring: the constructor takes a
        // path, an int and a bool, none of which PHP-DI can invent, and this
        // repository has been bitten before by `autowire()` silently SKIPPING
        // optional constructor parameters and leaving a dependency null.
        $heartbeatPath = self::stringOr(
            $appConfig,
            'maintenance_heartbeat_file',
            dirname(__DIR__, 4) . '/var/maintenance-heartbeat.json',
        );
        $staleAfter = self::intOr(
            $appConfig,
            'maintenance_stale_seconds',
            MaintenanceHeartbeat::DEFAULT_STALE_AFTER_SECONDS,
        );
        $maintenanceEnabled = self::maintenanceEnabled($appConfig);

        $definitions[MaintenanceHeartbeat::class] = factory(
            static fn (): MaintenanceHeartbeat => new MaintenanceHeartbeat(
                $heartbeatPath,
                $staleAfter,
                $maintenanceEnabled,
            )
        );

        $builder->addDefinitions($definitions);
    }

    /**
     * Whether `config/process.php` enables the dedicated maintenance worker.
     *
     * The path is injected by `start.php` as `process_config_path` (the same
     * idiom as `db_config_path` / `logger_config_path`). When it is absent — a
     * unit test building a container from a bare config array, say — the answer
     * is the shipped default, `true`, which is also the conservative one: it
     * makes an absent heartbeat record a DOWN verdict rather than a silent
     * `disabled` that could never fail.
     *
     * @param array<array-key, mixed> $appConfig Application config.
     */
    private static function maintenanceEnabled(array $appConfig): bool
    {
        /** @var mixed $path */
        $path = $appConfig['process_config_path'] ?? null;
        if (!is_string($path) || $path === '' || !is_file($path)) {
            return true;
        }

        /**
         * @psalm-suppress UnresolvableInclude
         * @var mixed $process
         */
        $process = require $path;
        if (!is_array($process)) {
            return true;
        }

        /** @var mixed $maintenance */
        $maintenance = $process['maintenance'] ?? null;
        if (!is_array($maintenance)) {
            return true;
        }

        return ($maintenance['enabled'] ?? true) === true;
    }

    /**
     * @param array<array-key, mixed> $config  Config array.
     * @param string                  $key     Field name.
     * @param string                  $default Fallback.
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

    /**
     * @param array<array-key, mixed> $config
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
}
