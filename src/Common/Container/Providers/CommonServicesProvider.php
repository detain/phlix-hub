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
use Phlix\Hub\Common\RateLimit\RateLimiter;
use Phlix\Hub\Common\RateLimit\RateLimiterInterface;
use Phlix\Hub\Common\RateLimit\RateLimitProfiles;

use function DI\factory;
use function DI\get;

/**
 * Registers cross-cutting "common" primitives that are not tied to a
 * single subsystem.
 *
 * Binds one {@see RateLimiter} instance PER SURFACE (HB-4.6a) — the historical
 * single login-grade limiter (5 / 900s) was mis-injected into proxy, heartbeat
 * and JWKS, which would trip normal operation. Each surface named in
 * {@see RateLimitProfiles} is registered under its own container id
 * (`rate_limiter.login`, `rate_limiter.proxy`, …) with its own
 * `{max, window}` sourced from `config/server.php`'s `rate_limit` section
 * (see {@see RateLimitProfiles::defaults()} for the per-worker defaults).
 * Every id resolves to a DISTINCT instance so no two surfaces share a window.
 *
 * Back-compat (LANDMINE): the legacy {@see RateLimiterInterface} and
 * {@see RateLimiter} bindings are aliased to the `login` profile so the
 * factories/services that still inject them keep resolving and the container
 * still boots. Those call sites are migrated to the named profiles in the
 * follow-on HB-4.6b–f sub-steps.
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

            // Arrow fn captures $window/$max/$cap BY VALUE at definition time,
            // so each surface gets its own thresholds (and its own instance).
            $definitions[$id] = factory(
                static fn (): RateLimiter => new RateLimiter($window, $max, $cap)
            );
        }

        // Back-compat: legacy consumers still inject the interface/concrete
        // directly; alias both to the login profile until the call sites are
        // migrated to the named profiles (HB-4.6b–f).
        $definitions[RateLimiter::class] = get(RateLimitProfiles::LOGIN);
        $definitions[RateLimiterInterface::class] = get(RateLimitProfiles::LOGIN);

        $builder->addDefinitions($definitions);
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
