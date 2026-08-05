<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use DI\ContainerBuilder;
use Phlix\Hub\Common\Container\Providers\HubServicesProvider;
use Phlix\Hub\Common\Container\Providers\MetricsServicesProvider;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Relay\RelayProxyBridge;
use Phlix\Hub\Stats\Metrics\MetricsCollector;
use Phlix\Hub\Stats\Metrics\MetricsFlushService;
use Phlix\Hub\Stats\Metrics\MetricsRegistry;
use Phlix\Hub\Tests\Support\LoggerFactoryIsolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * HB-4.1 Finding 1 wiring regression guard.
 *
 * The reviewer found the channel-push reply drop H-R8 names
 * ({@see RelayProxyBridge::dropReply()}) was uncounted: the bridge's DI factory
 * constructed it with the logger only, so `relay_reply_drops` never saw the
 * operationally-critical drop. This asserts the {@see HubServicesProvider}
 * factory now injects the per-worker SHARED {@see MetricsCollector}, and that it
 * is the SAME instance (and registry) the {@see MetricsFlushService} drains — so
 * the drop the bridge records is actually persisted (mirrors
 * {@see RelayProxyManagerWiringTest}).
 */
#[CoversClass(HubServicesProvider::class)]
final class RelayProxyBridgeWiringTest extends TestCase
{
    // LoggerFactory's static $configPath/$loggers are process-global; the trait
    // snapshots them before setUp() and restores them after tearDown().
    use LoggerFactoryIsolation;

    /** @var non-empty-string */
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        // The RelayProxyBridge factory resolves its logger via the static
        // LoggerFactory; point it at an in-memory stream so nothing is written.
        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-relaybridge-wiring-' . uniqid();
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
     * Build a container with the metrics + hub providers. Resolving
     * RelayProxyBridge only pulls MetricsCollector (its logger comes from the
     * static LoggerFactory), so nothing else in the graph resolves.
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

        return $builder->build();
    }

    public function testDiConstructedBridgeHasANonNullCollector(): void
    {
        $c = $this->buildContainer();

        $bridge = $c->get(RelayProxyBridge::class);
        self::assertInstanceOf(RelayProxyBridge::class, $bridge);

        $prop = new ReflectionProperty(RelayProxyBridge::class, 'metrics');
        $prop->setAccessible(true);
        $metrics = $prop->getValue($bridge);

        self::assertInstanceOf(
            MetricsCollector::class,
            $metrics,
            'the DI-constructed RelayProxyBridge must be wired with a metrics collector',
        );
    }

    public function testBridgeCollectorIsTheSameOneTheFlushServiceDrains(): void
    {
        $c = $this->buildContainer();

        $bridge = $c->get(RelayProxyBridge::class);
        $prop = new ReflectionProperty(RelayProxyBridge::class, 'metrics');
        $prop->setAccessible(true);
        $bridgeCollector = $prop->getValue($bridge);

        // Same shared MetricsCollector singleton the container hands everyone.
        self::assertSame($c->get(MetricsCollector::class), $bridgeCollector);

        // And it shares the one registry the flush service drains: the flush
        // service is built from get(MetricsCollector::class), so what the bridge
        // records in dropReply() lands in the very registry that flush() drains.
        self::assertInstanceOf(MetricsCollector::class, $bridgeCollector);
        self::assertSame($c->get(MetricsRegistry::class), $bridgeCollector->registry());
        self::assertInstanceOf(MetricsFlushService::class, $c->get(MetricsFlushService::class));
    }
}
