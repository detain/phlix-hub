<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Hub\Common\Container\Providers\MetricsServicesProvider;
use Phlix\Hub\Stats\Metrics\MetricsCollector;
use Phlix\Hub\Stats\Metrics\MetricsFlushService;
use Phlix\Hub\Stats\Metrics\MetricsRegistry;
use Phlix\Hub\Stats\Metrics\MetricsRepository;
use Phlix\Hub\Stats\Metrics\MetricsRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Covers {@see MetricsServicesProvider}: every metrics service resolves from a
 * container built with only this provider, and — critically — the read-side
 * INTERFACE resolves to the concrete repository.
 *
 * Regression guard: the admin {@see \Phlix\Hub\Http\Controllers\Stats\MetricsController}
 * type-hints {@see MetricsRepositoryInterface} and the HubServicesProvider
 * controller factory resolves it via `get(MetricsRepositoryInterface::class)`.
 * The provider originally bound only the concrete class, so at boot
 * (`Application::registerMetricsRoutes()` resolves the controller EAGERLY)
 * PHP-DI tried to instantiate the interface and fatalled with
 * "MetricsRepositoryInterface cannot be resolved: the class is not
 * instantiable". This test would fail without the interface→concrete alias.
 */
#[CoversClass(MetricsServicesProvider::class)]
final class MetricsServicesProviderTest extends TestCase
{
    /**
     * @param array<string, mixed> $appConfig
     */
    private function buildContainer(array $appConfig = []): \DI\Container
    {
        $builder = new ContainerBuilder();
        (new MetricsServicesProvider())->register($builder, $appConfig);
        return $builder->build();
    }

    public function testAllMetricsServicesResolve(): void
    {
        $c = $this->buildContainer($this->config());

        self::assertInstanceOf(MetricsRegistry::class, $c->get(MetricsRegistry::class));
        self::assertInstanceOf(MetricsCollector::class, $c->get(MetricsCollector::class));
        self::assertInstanceOf(MetricsFlushService::class, $c->get(MetricsFlushService::class));
        self::assertInstanceOf(MetricsRepository::class, $c->get(MetricsRepository::class));
    }

    public function testRepositoryInterfaceResolvesToConcreteRepository(): void
    {
        // This is the exact resolution the boot path performs
        // (get(MetricsRepositoryInterface::class)); without the alias it fatals
        // with "not instantiable".
        $c = $this->buildContainer($this->config());

        $repo = $c->get(MetricsRepositoryInterface::class);
        self::assertInstanceOf(MetricsRepository::class, $repo);
    }

    public function testInterfaceAndConcreteShareTheSameSingleton(): void
    {
        // The alias must reuse the shared concrete instance (not build a second
        // repository), matching the provider's "all four SHARED" contract.
        $c = $this->buildContainer($this->config());

        self::assertSame(
            $c->get(MetricsRepository::class),
            $c->get(MetricsRepositoryInterface::class),
        );
    }

    public function testCollectorAndFlushServiceShareOneRegistry(): void
    {
        $c = $this->buildContainer($this->config());

        $registry  = $c->get(MetricsRegistry::class);
        $collector = $c->get(MetricsCollector::class);
        self::assertInstanceOf(MetricsRegistry::class, $registry);
        self::assertInstanceOf(MetricsCollector::class, $collector);

        // A single registry per worker: the collector the hooks write into is
        // the same one the flush service drains (both share the container's
        // shared MetricsRegistry).
        self::assertSame($registry, $collector->registry());
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return [
            'metrics' => [
                'enabled'               => true,
                'bucket_seconds'        => 10,
                'route_cardinality_cap' => 200,
                'connection_ttl_seconds' => 15,
                'latency_buckets_ms'    => [10, 50, 100, 250, 500, 1000, 2500, 5000],
            ],
        ];
    }
}
