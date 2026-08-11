<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Stats\Metrics;

use Phlix\Hub\Stats\Metrics\MetricsCollector;
use Phlix\Hub\Stats\Metrics\MetricsRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see MetricsCollector}, the per-worker façade.
 */
final class MetricsCollectorTest extends TestCase
{
    /** @var callable(): int A frozen clock returning 1000. */
    private $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = static fn (): int => 1000;
    }

    public function testEnabledCollectorDelegatesRequestToRegistry(): void
    {
        $registry  = new MetricsRegistry(10);
        $collector = new MetricsCollector($registry, true, $this->clock);

        $collector->recordRequest('GET', '/a', 500, 42.0, 11, 22);

        $drained = $registry->drainRollups(1000);
        $this->assertArrayHasKey(1000, $drained['overall']);
        $bucket = $drained['overall'][1000];
        $this->assertSame(1, $bucket['request_count']);
        $this->assertSame(1, $bucket['error_count']);
        $this->assertSame(42, $bucket['duration_ms_sum']);
        $this->assertSame(11, $bucket['bytes_in']);
        $this->assertSame(22, $bucket['bytes_out']);
    }

    public function testDisabledCollectorDoesNotRecordRequests(): void
    {
        $registry  = new MetricsRegistry(10);
        $collector = new MetricsCollector($registry, false, $this->clock);

        $collector->recordRequest('GET', '/a', 200, 42.0, 11, 22);

        $drained = $registry->drainRollups(1000);
        $this->assertSame([], $drained['overall']);
        $this->assertSame([], $drained['routes']);
    }

    public function testEnabledCollectorDelegatesConnectionLifecycle(): void
    {
        $registry  = new MetricsRegistry(10);
        $collector = new MetricsCollector($registry, true, $this->clock);

        $collector->openConnection('0-1', 'stream', 'user-1', '1.2.3.4', 'sess', 'media');
        $collector->touchConnection('0-1', 100, 200);

        $snap = $registry->snapshotConnections();
        $this->assertArrayHasKey('0-1', $snap);
        $this->assertSame('stream', $snap['0-1']['kind']);
        $this->assertSame('user-1', $snap['0-1']['user_id']);
        $this->assertSame(100, $snap['0-1']['bytes_in']);
        $this->assertSame(200, $snap['0-1']['bytes_out']);
        $this->assertSame(1000, $snap['0-1']['opened_at']);

        $collector->closeConnection('0-1');
        $this->assertArrayNotHasKey('0-1', $registry->snapshotConnections());
    }

    public function testDisabledCollectorDoesNotTouchConnections(): void
    {
        $registry  = new MetricsRegistry(10);
        $collector = new MetricsCollector($registry, false, $this->clock);

        $collector->openConnection('0-1', 'http', null, null, null, null);
        $collector->touchConnection('0-1', 100, 200);
        $collector->closeConnection('0-1');

        $this->assertSame([], $registry->snapshotConnections());
    }

    public function testOpenConnectionUsesDefaultsForOptionalArgs(): void
    {
        $registry  = new MetricsRegistry(10);
        $collector = new MetricsCollector($registry, true, $this->clock);

        $collector->openConnection('0-2', 'http');

        $c = $registry->snapshotConnections()['0-2'];
        $this->assertNull($c['user_id']);
        $this->assertNull($c['remote_ip']);
        $this->assertNull($c['session_id']);
        $this->assertNull($c['media_item_id']);
    }

    public function testIsEnabledReflectsConstructorFlag(): void
    {
        $registry = new MetricsRegistry(10);
        $this->assertTrue((new MetricsCollector($registry, true))->isEnabled());
        $this->assertFalse((new MetricsCollector($registry, false))->isEnabled());
    }

    public function testRegistryAccessorReturnsSharedInstance(): void
    {
        $registry  = new MetricsRegistry(10);
        $collector = new MetricsCollector($registry, true);

        $this->assertSame($registry, $collector->registry());
    }

    public function testDefaultClockIsTime(): void
    {
        $registry  = new MetricsRegistry(1);
        $collector = new MetricsCollector($registry, true);

        $before = time();
        $collector->recordRequest('GET', '/a', 200, 1.0, 0, 0);
        $after = time();

        $drained = $registry->drainRollups(time());
        $this->assertNotEmpty($drained['overall']);
        $bucketTs = array_key_first($drained['overall']);
        $this->assertIsInt($bucketTs);
        $this->assertGreaterThanOrEqual($before, $bucketTs);
        $this->assertLessThanOrEqual($after, $bucketTs);
    }
}
