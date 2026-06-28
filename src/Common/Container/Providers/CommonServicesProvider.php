<?php

declare(strict_types=1);

namespace Phlix\Hub\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Hub\Common\Container\ServiceProviderInterface;
use Phlix\Hub\Common\RateLimit\RateLimiter;
use Phlix\Hub\Common\RateLimit\RateLimiterInterface;

use function DI\factory;
use function DI\get;

/**
 * Registers cross-cutting "common" primitives that are not tied to a
 * single subsystem.
 *
 * Currently binds the {@see RateLimiterInterface} to the worker-local
 * {@see RateLimiter} (finding B1 — bounded, actively-evicted TTL store
 * replacing the unbounded static map). Consumers depend on the interface,
 * so a cluster-safe backend can be swapped here later (Feature #6) without
 * touching call sites. The concrete {@see RateLimiter} is aliased to the
 * same singleton so type-hinting either the interface or the class
 * resolves the one instance per worker.
 *
 * Window/attempt defaults mirror the historical login limiter
 * (5 attempts / 900s) and can be overridden via `config/auth.php`
 * (`rate_limit.window_seconds`, `rate_limit.max_attempts`, `rate_limit.cap`).
 *
 * @package Phlix\Hub\Common\Container\Providers
 */
final class CommonServicesProvider implements ServiceProviderInterface
{
    /** Default counting window in seconds (15 minutes). */
    private const int DEFAULT_WINDOW_SECONDS = 900;

    /** Default attempts allowed within a window. */
    private const int DEFAULT_MAX_ATTEMPTS = 5;

    /** Default ceiling on retained keys per worker. */
    private const int DEFAULT_CAP = 10000;

    /**
     * @inheritDoc
     */
    public function register(ContainerBuilder $builder, array $appConfig): void
    {
        $rateLimit = self::resolveRateLimitConfig($appConfig);
        $window = $rateLimit['window_seconds'];
        $max = $rateLimit['max_attempts'];
        $cap = $rateLimit['cap'];

        $builder->addDefinitions([
            RateLimiter::class => factory(
                static fn (): RateLimiter => new RateLimiter($window, $max, $cap)
            ),
            RateLimiterInterface::class => get(RateLimiter::class),
        ]);
    }

    /**
     * Resolve rate-limit knobs from the merged app config, falling back to
     * the historical login-limiter defaults.
     *
     * @param array<string, mixed> $appConfig
     *
     * @return array{window_seconds: int, max_attempts: int, cap: int}
     */
    private static function resolveRateLimitConfig(array $appConfig): array
    {
        /**
         * @var mixed $section
         * @psalm-suppress MixedAssignment
         */
        $section = $appConfig['rate_limit'] ?? null;
        $rateLimit = is_array($section) ? $section : [];

        return [
            'window_seconds' => self::intOr($rateLimit, 'window_seconds', self::DEFAULT_WINDOW_SECONDS),
            'max_attempts' => self::intOr($rateLimit, 'max_attempts', self::DEFAULT_MAX_ATTEMPTS),
            'cap' => self::intOr($rateLimit, 'cap', self::DEFAULT_CAP),
        ];
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
