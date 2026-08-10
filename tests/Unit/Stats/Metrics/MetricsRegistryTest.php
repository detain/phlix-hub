<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Stats\Metrics;

use Phlix\Hub\Stats\Metrics\MetricsRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see MetricsRegistry}, the per-worker in-RAM accumulator.
 *
 * The registry is fully deterministic (every method that needs "now" takes an
 * explicit `$nowTs`), so these tests exercise time-bucketing, the latency
 * histogram at its bucket boundaries, error classification, the route
 * cardinality cap and the connection lifecycle.
 *
 * @covers \Phlix\Hub\Stats\Metrics\MetricsRegistry
 */
final class MetricsRegistryTest extends TestCase
{
    /** Default histogram bounds used by the class (and its emptyHistogram()). */
    private const BOUNDS = [10, 50, 100, 250, 500, 1000, 2500, 5000];

    private function registry(int $bucketSeconds = 10, int $cap = 200): MetricsRegistry
    {
        return new MetricsRegistry($bucketSeconds, self::BOUNDS, $cap);
    }

    public function testRecordRequestPopulatesCurrentBucket(): void
    {
        $reg = $this->registry(10);
        $reg->recordRequest('GET', '/api/v1/media', 200, 42.0, 100, 900, 1234);

        $drained = $reg->drainRollups(1234);

        $this->assertArrayHasKey('overall', $drained);
        $this->assertArrayHasKey(1230, $drained['overall']);

        $bucket = $drained['overall'][1230];
        $this->assertSame(1230, $bucket['bucket_started_at']);
        $this->assertSame(1, $bucket['request_count']);
        $this->assertSame(0, $bucket['error_count']);
        $this->assertSame(42, $bucket['duration_ms_sum']);
        $this->assertSame(42, $bucket['duration_ms_max']);
        $this->assertSame(100, $bucket['bytes_in']);
        $this->assertSame(900, $bucket['bytes_out']);
    }

    public function testRequestsLandInSeparateTimeBuckets(): void
    {
        $reg = $this->registry(10);
        $reg->recordRequest('GET', '/a', 200, 5.0, 0, 0, 1000);
        $reg->recordRequest('GET', '/a', 200, 5.0, 0, 0, 1009);
        $reg->recordRequest('GET', '/a', 200, 5.0, 0, 0, 1010);

        $drained = $reg->drainRollups(1010);

        $this->assertCount(2, $drained['overall']);
        $this->assertSame(2, $drained['overall'][1000]['request_count']);
        $this->assertSame(1, $drained['overall'][1010]['request_count']);
    }

    public function testDrainResetsAccumulators(): void
    {
        $reg = $this->registry(10);
        $reg->recordRequest('GET', '/a', 200, 5.0, 0, 0, 1000);

        $first = $reg->drainRollups(1000);
        $this->assertNotEmpty($first['overall']);
        $this->assertNotEmpty($first['routes']);

        $second = $reg->drainRollups(1000);
        $this->assertSame([], $second['overall']);
        $this->assertSame([], $second['routes']);
    }

    public function testErrorClassificationCounts5xxOnly(): void
    {
        $reg = $this->registry(10);
        $reg->recordRequest('GET', '/a', 200, 1.0, 0, 0, 1000);
        $reg->recordRequest('GET', '/a', 404, 1.0, 0, 0, 1000);
        $reg->recordRequest('GET', '/a', 499, 1.0, 0, 0, 1000);
        $reg->recordRequest('GET', '/a', 500, 1.0, 0, 0, 1000);
        $reg->recordRequest('GET', '/a', 503, 1.0, 0, 0, 1000);

        $drained = $reg->drainRollups(1000);

        $this->assertSame(5, $drained['overall'][1000]['request_count']);
        $this->assertSame(2, $drained['overall'][1000]['error_count']);
    }

    public function testDurationSumAndMaxRoundAndTrackPeak(): void
    {
        $reg = $this->registry(10);
        $reg->recordRequest('GET', '/a', 200, 10.4, 0, 0, 1000);
        $reg->recordRequest('GET', '/a', 200, 10.6, 0, 0, 1000);
        $reg->recordRequest('GET', '/a', 200, 100.0, 0, 0, 1000);

        $bucket = $reg->drainRollups(1000)['overall'][1000];
        $this->assertSame(10 + 11 + 100, $bucket['duration_ms_sum']);
        $this->assertSame(100, $bucket['duration_ms_max']);
    }

    public function testHistogramBucketingAtBoundaries(): void
    {
        $reg = $this->registry(10);
        $reg->recordRequest('GET', '/a', 200, 10.0, 0, 0, 1000);
        $reg->recordRequest('GET', '/a', 200, 11.0, 0, 0, 1000);
        $reg->recordRequest('GET', '/a', 200, 50.0, 0, 0, 1000);
        $reg->recordRequest('GET', '/a', 200, 5000.0, 0, 0, 1000);
        $reg->recordRequest('GET', '/a', 200, 5001.0, 0, 0, 1000);

        $h = $reg->drainRollups(1000)['overall'][1000]['histogram'];

        $expectedKeys = array_merge(self::BOUNDS, [-1]);
        $this->assertSame($expectedKeys, array_keys($h));

        $this->assertSame(1, $h[10]);
        $this->assertSame(2, $h[50]);
        $this->assertSame(0, $h[100]);
        $this->assertSame(1, $h[5000]);
        $this->assertSame(1, $h[-1]);
    }

    public function testZeroMsRequestLandsInLowestBucket(): void
    {
        $reg = $this->registry(10);
        $reg->recordRequest('GET', '/a', 200, 0.0, 0, 0, 1000);

        $h = $reg->drainRollups(1000)['overall'][1000]['histogram'];
        $this->assertSame(1, $h[10]);
        $this->assertSame(0, $h[-1]);
    }

    public function testRouteRollupNormalisesMethodAndRecordsPerRoute(): void
    {
        $reg = $this->registry(10);
        $reg->recordRequest('get', '/api/v1/media', 200, 5.0, 0, 0, 1000);
        $reg->recordRequest('GET', '/api/v1/media', 500, 15.0, 0, 0, 1000);
        $reg->recordRequest('POST', '/api/v1/media', 201, 25.0, 0, 0, 1000);

        $routes = $reg->drainRollups(1000)['routes'];
        $this->assertCount(2, $routes);

        $byKey = [];
        foreach ($routes as $r) {
            $byKey[$r['method'] . ' ' . $r['route']] = $r;
        }

        $this->assertArrayHasKey('GET /api/v1/media', $byKey);
        $get = $byKey['GET /api/v1/media'];
        $this->assertSame(1000, $get['bucket_started_at']);
        $this->assertSame(2, $get['request_count']);
        $this->assertSame(1, $get['error_count']);
        $this->assertSame(5 + 15, $get['duration_ms_sum']);
        $this->assertSame(15, $get['duration_ms_max']);

        $this->assertArrayHasKey('POST /api/v1/media', $byKey);
        $this->assertSame(1, $byKey['POST /api/v1/media']['request_count']);
    }

    public function testEmptyRouteIsNormalisedToSlash(): void
    {
        $reg = $this->registry(10);
        $reg->recordRequest('GET', '', 200, 5.0, 0, 0, 1000);

        $routes = $reg->drainRollups(1000)['routes'];
        $this->assertCount(1, $routes);
        $this->assertSame('/', $routes[0]['route']);
    }

    public function testRouteCardinalityCapFoldsToOther(): void
    {
        $cap = 3;
        $reg = $this->registry(10, $cap);

        $reg->recordRequest('GET', '/r0', 200, 5.0, 0, 0, 1000);
        $reg->recordRequest('GET', '/r1', 200, 5.0, 0, 0, 1000);
        $reg->recordRequest('GET', '/r2', 200, 5.0, 0, 0, 1000);

        $reg->recordRequest('GET', '/r3', 500, 5.0, 0, 0, 1000);
        $reg->recordRequest('GET', '/r4', 200, 5.0, 0, 0, 1000);

        $reg->recordRequest('GET', '/r0', 200, 5.0, 0, 0, 1000);

        $routes = $reg->drainRollups(1000)['routes'];
        $byRoute = [];
        foreach ($routes as $r) {
            $byRoute[$r['route']] = $r;
        }

        $this->assertCount(4, $routes);
        $this->assertArrayHasKey(MetricsRegistry::OTHER_ROUTE, $byRoute);

        $this->assertSame(2, $byRoute['/r0']['request_count']);

        $other = $byRoute[MetricsRegistry::OTHER_ROUTE];
        $this->assertSame(2, $other['request_count']);
        $this->assertSame(1, $other['error_count']);
    }

    public function testOpenTouchAndSnapshotConnection(): void
    {
        $reg = $this->registry(10);
        $reg->openConnection('0-1', 'websocket', 'user-1', '10.0.0.5', 'sess-1', 'media-9', 1000);
        $reg->touchConnection('0-1', 500, 1500, 1005);

        $snap = $reg->snapshotConnections();
        $this->assertArrayHasKey('0-1', $snap);

        $c = $snap['0-1'];
        $this->assertSame('websocket', $c['kind']);
        $this->assertSame('user-1', $c['user_id']);
        $this->assertSame('10.0.0.5', $c['remote_ip']);
        $this->assertSame('sess-1', $c['session_id']);
        $this->assertSame('media-9', $c['media_item_id']);
        $this->assertSame(500, $c['bytes_in']);
        $this->assertSame(1500, $c['bytes_out']);
        $this->assertSame(1000, $c['opened_at']);
        $this->assertSame(1005, $c['last_seen_at']);
    }

    public function testOpenConnectionNormalisesUnknownKindToHttp(): void
    {
        $reg = $this->registry(10);
        $reg->openConnection('0-1', 'BOGUS', null, null, null, null, 1000);

        $this->assertSame('http', $reg->snapshotConnections()['0-1']['kind']);
    }

    public function testTouchWithoutOpenCreatesConnection(): void
    {
        $reg = $this->registry(10);
        $reg->touchConnection('0-7', 10, 20, 2000);

        $snap = $reg->snapshotConnections();
        $this->assertArrayHasKey('0-7', $snap);
        $this->assertSame('http', $snap['0-7']['kind']);
        $this->assertSame(10, $snap['0-7']['bytes_in']);
        $this->assertSame(20, $snap['0-7']['bytes_out']);
        $this->assertSame(2000, $snap['0-7']['opened_at']);
        $this->assertSame(2000, $snap['0-7']['last_seen_at']);
    }

    public function testCloseConnectionRemovesItFromSnapshot(): void
    {
        $reg = $this->registry(10);
        $reg->openConnection('0-1', 'http', null, null, null, null, 1000);
        $reg->openConnection('0-2', 'http', null, null, null, null, 1000);

        $reg->closeConnection('0-1');

        $snap = $reg->snapshotConnections();
        $this->assertArrayNotHasKey('0-1', $snap);
        $this->assertArrayHasKey('0-2', $snap);
    }

    public function testSnapshotDoesNotResetConnections(): void
    {
        $reg = $this->registry(10);
        $reg->openConnection('0-1', 'http', null, null, null, null, 1000);

        $this->assertNotEmpty($reg->snapshotConnections());
        $this->assertNotEmpty($reg->snapshotConnections());
    }

    public function testPruneStaleConnectionsEvictsByLastSeen(): void
    {
        $reg = $this->registry(10);
        // 'old' last seen at t=1000; 'fresh' touched forward to t=2000.
        $reg->openConnection('old', 'websocket', null, null, null, null, 1000);
        $reg->openConnection('fresh', 'websocket', null, null, null, null, 1000);
        $reg->touchConnection('fresh', 10, 20, 2000);

        // Cutoff 1500: 'old' (last_seen 1000 < 1500) is evicted, 'fresh' (2000) stays.
        $reg->pruneStaleConnections(1500);

        $snap = $reg->snapshotConnections();
        $this->assertArrayNotHasKey('old', $snap);
        $this->assertArrayHasKey('fresh', $snap);
    }

    public function testPruneStaleConnectionsKeepsAllWhenNoneExpired(): void
    {
        $reg = $this->registry(10);
        $reg->openConnection('a', 'websocket', null, null, null, null, 3000);
        $reg->openConnection('b', 'websocket', null, null, null, null, 3000);

        // Cutoff strictly below both last_seen values — nothing ages out.
        $reg->pruneStaleConnections(2999);

        $this->assertCount(2, $reg->snapshotConnections());
    }

    public function testLatencyBoundsAreSortedAndExposed(): void
    {
        $reg = new MetricsRegistry(10, [500, 10, 100], 200);
        $this->assertSame([10, 100, 500], $reg->latencyBounds());
    }

    public function testBucketSecondsFlooredToOneMinimum(): void
    {
        $reg = new MetricsRegistry(0, self::BOUNDS, 200);
        $reg->recordRequest('GET', '/a', 200, 1.0, 0, 0, 1000);
        $reg->recordRequest('GET', '/a', 200, 1.0, 0, 0, 1001);

        $drained = $reg->drainRollups(1001);
        $this->assertArrayHasKey(1000, $drained['overall']);
        $this->assertArrayHasKey(1001, $drained['overall']);
    }
}
