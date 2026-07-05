<?php

declare(strict_types=1);

namespace Phlix\Hub\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Hub\Common\Container\ServiceProviderInterface;
use Phlix\Hub\Stats\Metrics\MetricsCollector;
use Phlix\Hub\Stats\Metrics\MetricsFlushService;
use Phlix\Hub\Stats\Metrics\MetricsRegistry;
use Phlix\Hub\Stats\Metrics\MetricsRepository;
use Phlix\Hub\Stats\Metrics\MetricsRepositoryInterface;
use Psr\Container\ContainerInterface;

use function DI\factory;
use function DI\get;

/**
 * Registers the metrics / live-traffic telemetry subsystem
 * ({@see \Phlix\Hub\Stats\Metrics}).
 *
 * All four services are registered as SHARED (singleton) instances so that a
 * single {@see MetricsRegistry} lives per worker: the request / connection hooks
 * write into the very same registry that the flush timer drains.
 *
 * Configuration is read from `$appConfig['metrics']` (threaded in by
 * config/server.php). When that sub-array is absent, the provider falls back
 * to a direct include of config/metrics.php, and finally to the classes' own
 * built-in defaults, so the bindings always resolve.
 *
 * @internal Phlix-internal service provider; consumed by ContainerFactory only.
 *
 * @package Phlix\Hub\Common\Container\Providers
 * @since S4
 */
final class MetricsServicesProvider implements ServiceProviderInterface
{
    /**
     * Register the metrics bindings.
     *
     * @param ContainerBuilder<\DI\Container> $builder
     * @param array<string, mixed>            $appConfig
     *
     * @return void
     *
     * @since S4
     */
    public function register(ContainerBuilder $builder, array $appConfig): void
    {
        $config = $this->resolveConfig($appConfig);

        $enabled             = $this->cfgBool($config, 'enabled', true);
        $bucketSeconds       = $this->cfgInt($config, 'bucket_seconds', 10);
        $routeCardinalityCap = $this->cfgInt($config, 'route_cardinality_cap', 200);
        $latencyBuckets      = $this->cfgIntList(
            $config,
            'latency_buckets_ms',
            [10, 50, 100, 250, 500, 1000, 2500, 5000]
        );

        $builder->addDefinitions([
            // One in-RAM accumulator per worker. SHARED so the hooks and the
            // flush timer share the same counters.
            MetricsRegistry::class => factory(
                static fn (): MetricsRegistry => new MetricsRegistry(
                    $bucketSeconds,
                    $latencyBuckets,
                    $routeCardinalityCap
                )
            ),

            // Thin façade over the shared registry. The `enabled` flag makes
            // every record call a no-op when metrics are disabled.
            MetricsCollector::class => factory(
                static function (ContainerInterface $c) use ($enabled): MetricsCollector {
                    /** @var MetricsRegistry $registry */
                    $registry = $c->get(MetricsRegistry::class);
                    return new MetricsCollector($registry, $enabled);
                }
            ),

            // Drains the registry to MySQL on the flush timer. Shares the same
            // collector and reads its own tuning knobs from the raw config array.
            MetricsFlushService::class => factory(
                static function (ContainerInterface $c) use ($config): MetricsFlushService {
                    /** @var MetricsCollector $collector */
                    $collector = $c->get(MetricsCollector::class);
                    return new MetricsFlushService($collector, $config);
                }
            ),

            // Read side consumed by the admin controller. Aggregates across
            // the single 'hub-relay' worker rows.
            MetricsRepository::class => factory(
                static function (ContainerInterface $c) use ($config): MetricsRepository {
                    return new MetricsRepository($config);
                }
            ),

            // Bind the read-side interface to the concrete repository. The admin
            // MetricsController type-hints MetricsRepositoryInterface (and the
            // HubServicesProvider MetricsController factory resolves it via
            // get(MetricsRepositoryInterface::class)); without this alias PHP-DI
            // tries to instantiate the interface directly and fatals at boot with
            // "MetricsRepositoryInterface cannot be resolved: the class is not
            // instantiable" (Application::registerMetricsRoutes resolves the
            // controller eagerly). The alias reuses the shared concrete singleton.
            MetricsRepositoryInterface::class => get(MetricsRepository::class),
        ]);
    }

    /**
     * Resolve the effective metrics config array.
     *
     * Prefers `$appConfig['metrics']`; falls back to a direct include of
     * config/metrics.php so an entry point that does not compose the full
     * server.php still gets the real defaults; finally yields an empty array.
     *
     * @param array<string, mixed> $appConfig
     *
     * @return array<string, mixed>
     */
    private function resolveConfig(array $appConfig): array
    {
        /** @var mixed $raw */
        $raw = $appConfig['metrics'] ?? null;
        if (!is_array($raw)) {
            /** @var mixed $included */
            $included = @include __DIR__ . '/../../../../config/metrics.php';
            $raw = is_array($included) ? $included : [];
        }

        // Keep only string-keyed entries (a config array), dropping any numeric
        // keys. array_filter avoids a per-entry mixed offset assignment.
        /** @var array<string, mixed> $out */
        $out = array_filter(
            $raw,
            static fn (int|string $key): bool => is_string($key),
            ARRAY_FILTER_USE_KEY,
        );

        return $out;
    }

    /**
     * Read a boolean config value with a default.
     *
     * @param array<string, mixed> $config
     * @param string               $key
     * @param bool                 $default
     *
     * @return bool
     */
    private function cfgBool(array $config, string $key, bool $default): bool
    {
        /** @var mixed $v */
        $v = $config[$key] ?? null;
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v)) {
            return $v !== 0;
        }
        if (is_string($v)) {
            return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
        }
        return $default;
    }

    /**
     * Read an int config value with a default.
     *
     * @param array<string, mixed> $config
     * @param string               $key
     * @param int                  $default
     *
     * @return int
     */
    private function cfgInt(array $config, string $key, int $default): int
    {
        /** @var mixed $v */
        $v = $config[$key] ?? null;
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && is_numeric($v)) {
            return (int) $v;
        }
        return $default;
    }

    /**
     * Read a list of ints from config with a default, dropping non-numeric entries.
     *
     * @param array<string, mixed> $config
     * @param string               $key
     * @param list<int>            $default
     *
     * @return list<int>
     */
    private function cfgIntList(array $config, string $key, array $default): array
    {
        $v = $config[$key] ?? null;
        if (!is_array($v)) {
            return $default;
        }

        $out = [];
        /** @var mixed $entry */
        foreach ($v as $entry) {
            if (is_int($entry)) {
                $out[] = $entry;
            } elseif (is_string($entry) && is_numeric($entry)) {
                $out[] = (int) $entry;
            } elseif (is_float($entry)) {
                $out[] = (int) $entry;
            }
        }

        return $out !== [] ? $out : $default;
    }
}
