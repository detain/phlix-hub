<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use DI\ContainerBuilder;
use Phlix\Hub\Common\Container\Providers\HubServicesProvider;
use Phlix\Hub\Common\Container\Providers\MetricsServicesProvider;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Relay\RelayProxyManager;
use Phlix\Hub\Relay\TunnelManagerInterface;
use Phlix\Hub\Stats\Metrics\MetricsCollector;
use Phlix\Hub\Stats\Metrics\MetricsFlushService;
use Phlix\Hub\Stats\Metrics\MetricsRegistry;
use Phlix\Hub\Tests\Support\LoggerFactoryIsolation;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * HB-4.1 wiring regression guard.
 *
 * The audit found the DI-constructed {@see RelayProxyManager} was built with NO
 * metrics collector (constructor default null, nothing wired), so every
 * `$this->metrics?->…` record — pending gauge, reply-drop, latency, 503/504 —
 * was a silent no-op end-to-end. This asserts the {@see HubServicesProvider}
 * factory now injects the per-worker SHARED {@see MetricsCollector}, and that it
 * is the SAME instance (and registry) the {@see MetricsFlushService} drains — so
 * everything the proxy manager records is actually persisted.
 */
final class RelayProxyManagerWiringTest extends TestCase
{
    // LoggerFactory's static $configPath/$loggers are process-global; the trait
    // snapshots them before setUp() and restores them after tearDown().
    use LoggerFactoryIsolation;

    /** @var non-empty-string */
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        // The RelayProxyManager factory resolves its logger via the static
        // LoggerFactory; point it at an in-memory stream so nothing is written.
        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-relayproxy-wiring-' . uniqid();
        mkdir($this->tmpDir, 0700, true);
        file_put_contents(
            $this->tmpDir . '/logger.php',
            "<?php return ['default' => 'mem', 'handlers' => ['mem' => "
            . "['type' => 'stream', 'path' => 'php://memory', 'level' => 'debug']]];",
        );
        LoggerFactory::reset();
        LoggerFactory::init($this->tmpDir . '/logger.php');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        LoggerFactory::reset();
        @unlink($this->tmpDir . '/logger.php');
        @rmdir($this->tmpDir);
    }

    /**
     * Build a container with the metrics + hub providers, stubbing the tunnel
     * registry so resolving RelayProxyManager stays cheap (no DB / JWT graph).
     */
    private function buildContainer(): \DI\Container
    {
        $appConfig = [
            'metrics' => [
                'enabled'               => true,
                'bucket_seconds'        => 10,
                'route_cardinality_cap' => 200,
                'latency_buckets_ms'    => [10, 50, 100, 250, 500, 1000, 2500, 5000],
            ],
            'hub_base_url' => 'http://localhost:8800',
        ];

        $builder = new ContainerBuilder();
        (new MetricsServicesProvider())->register($builder, $appConfig);
        (new HubServicesProvider())->register($builder, $appConfig);
        // Override the tunnel registry with a stub — RelayProxyManager only needs
        // TunnelManagerInterface + MetricsCollector, so nothing else resolves.
        $builder->addDefinitions([
            TunnelManagerInterface::class => $this->createMock(TunnelManagerInterface::class),
        ]);

        return $builder->build();
    }

    public function testDiConstructedProxyManagerHasANonNullCollector(): void
    {
        $c = $this->buildContainer();

        $proxyManager = $c->get(RelayProxyManager::class);
        self::assertInstanceOf(RelayProxyManager::class, $proxyManager);

        $prop = new ReflectionProperty(RelayProxyManager::class, 'metrics');
        $prop->setAccessible(true);
        $metrics = $prop->getValue($proxyManager);

        self::assertInstanceOf(
            MetricsCollector::class,
            $metrics,
            'the DI-constructed RelayProxyManager must be wired with a metrics collector',
        );
    }

    public function testProxyManagerCollectorIsTheSameOneTheFlushServiceDrains(): void
    {
        $c = $this->buildContainer();

        $proxyManager = $c->get(RelayProxyManager::class);
        self::assertInstanceOf(RelayProxyManager::class, $proxyManager);
        $prop = new ReflectionProperty(RelayProxyManager::class, 'metrics');
        $prop->setAccessible(true);
        $proxyCollector = $prop->getValue($proxyManager);

        // Same shared MetricsCollector singleton the container hands everyone.
        self::assertSame($c->get(MetricsCollector::class), $proxyCollector);

        // And it shares the one registry the flush service drains: the flush
        // service is built from get(MetricsCollector::class), so what the proxy
        // manager records lands in the very registry that flush() drains.
        self::assertInstanceOf(MetricsCollector::class, $proxyCollector);
        self::assertSame($c->get(MetricsRegistry::class), $proxyCollector->registry());
        self::assertInstanceOf(MetricsFlushService::class, $c->get(MetricsFlushService::class));
    }
}
