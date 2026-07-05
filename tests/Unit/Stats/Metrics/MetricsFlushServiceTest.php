<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Stats\Metrics;

use Phlix\Hub\Stats\Metrics\MetricsCollector;
use Phlix\Hub\Stats\Metrics\MetricsFlushService;
use Phlix\Hub\Stats\Metrics\MetricsRegistry;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see MetricsFlushService}, which drains a registry into MySQL.
 *
 * The Workerman MySQL {@see Connection} is mocked and every `query()` call is
 * captured (SQL + bindings) so we can assert the UPSERT / DELETE SQL fragments.
 *
 * @covers \Phlix\Hub\Stats\Metrics\MetricsFlushService
 */
final class MetricsFlushServiceTest extends TestCase
{
    /**
     * @var array<int, array{sql: string, params: array<string, mixed>}>
     */
    private array $queries = [];

    private function mockConnection(): Connection
    {
        $mock = $this->createMock(Connection::class);
        $mock->method('query')->willReturnCallback(
            function (string $sql, array $bindings = []): array {
                $this->queries[] = ['sql' => $sql, 'params' => $bindings];
                return [];
            }
        );
        return $mock;
    }

    private function service(MetricsCollector $collector, array $configOverrides = []): MetricsFlushService
    {
        $config = array_merge([
            'retention_days'         => 7,
            'connection_ttl_seconds' => 15,
            'flush_interval_seconds' => 5,
        ], $configOverrides);

        return new MetricsFlushService($collector, $config);
    }

    /**
     * @return array<int, array{sql: string, params: array<string, mixed>}>
     */
    private function queriesMatching(string $needle): array
    {
        return array_values(array_filter(
            $this->queries,
            static fn (array $q): bool => str_contains($q['sql'], $needle)
        ));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->queries = [];
    }

    public function test_flush_noops_when_collector_disabled(): void
    {
        $registry  = new MetricsRegistry(10);
        $collector = new MetricsCollector($registry, false);
        $this->mockConnectionPool($this->mockConnection());

        $registry->recordRequest('GET', '/a', 200, 5.0, 0, 0, 1000);

        $this->service($collector)->flush(3, 1000);

        $this->assertSame([], $this->queries);
    }

    public function test_flush_upserts_overall_rollup_with_histogram_columns(): void
    {
        $registry  = new MetricsRegistry(10);
        $collector = new MetricsCollector($registry, true);
        $this->mockConnectionPool($this->mockConnection());

        $registry->recordRequest('GET', '/a', 200, 5.0, 100, 900, 1000);
        $registry->recordRequest('GET', '/a', 500, 600.0, 50, 50, 1000);

        $this->service($collector)->flush(3, 1000);

        $overall = $this->queriesMatching('INSERT INTO metrics_rollup');
        $this->assertCount(1, $overall);

        $q = $overall[0];
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $q['sql']);
        $this->assertStringContainsString('request_count   = request_count + VALUES(request_count)', $q['sql']);
        $this->assertStringContainsString('duration_ms_max = GREATEST(duration_ms_max, VALUES(duration_ms_max))', $q['sql']);

        $p = $q['params'];
        $this->assertSame(date('Y-m-d H:i:s', 1000), $p[':bucket']);
        $this->assertSame('hub-relay', $p[':worker_id']);
        $this->assertSame(2, $p[':request_count']);
        $this->assertSame(1, $p[':error_count']);
        $this->assertSame(605, $p[':duration_ms_sum']);
        $this->assertSame(600, $p[':duration_ms_max']);
        $this->assertSame(150, $p[':bytes_in']);
        $this->assertSame(950, $p[':bytes_out']);
        // Histogram bind placeholders are :h0..:h8 (prefix-collision-safe); the
        // COLUMNS keep their h_le_* names. :h0 = <=10ms bucket, :h5 = <=1000ms,
        // :h8 = >5000ms overflow.
        $this->assertSame(1, $p[':h0']);
        $this->assertSame(1, $p[':h5']);
        $this->assertSame(0, $p[':h8']);
    }

    public function test_flush_upserts_route_rollup(): void
    {
        $registry  = new MetricsRegistry(10);
        $collector = new MetricsCollector($registry, true);
        $this->mockConnectionPool($this->mockConnection());

        $registry->recordRequest('POST', '/api/v1/media', 201, 12.0, 0, 0, 1000);

        $this->service($collector)->flush(2, 1000);

        $routes = $this->queriesMatching('INSERT INTO metrics_route_rollup');
        $this->assertCount(1, $routes);

        $p = $routes[0]['params'];
        $this->assertSame(date('Y-m-d H:i:s', 1000), $p[':bucket']);
        $this->assertSame('hub-relay', $p[':worker_id']);
        $this->assertSame('POST', $p[':method']);
        $this->assertSame('/api/v1/media', $p[':route']);
        $this->assertSame(1, $p[':request_count']);
    }

    public function test_connection_rate_is_zero_on_first_flush_and_delta_on_second(): void
    {
        $registry  = new MetricsRegistry(10);
        $collector = new MetricsCollector($registry, true);
        $conn = $this->mockConnection();
        $this->mockConnectionPool($conn);
        $service = $this->service($collector, ['flush_interval_seconds' => 5]);

        $registry->openConnection('0-1', 'stream', 'user-1', '9.9.9.9', null, 'media-1', 1000);
        $registry->touchConnection('0-1', 1000, 5000, 1004);
        $service->flush(1, 1005);

        $first = $this->queriesMatching('INSERT INTO metrics_connections');
        $this->assertCount(1, $first);
        $this->assertSame(0, $first[0]['params'][':bytes_in_rate']);
        $this->assertSame(0, $first[0]['params'][':bytes_out_rate']);

        // Second flush 5s later.
        $this->queries = [];
        $registry->touchConnection('0-1', 3500, 15000, 1009);
        $service->flush(1, 1010);

        $second = $this->queriesMatching('INSERT INTO metrics_connections');
        $this->assertCount(1, $second);
        $this->assertSame(500, $second[0]['params'][':bytes_in_rate']);
        $this->assertSame(2000, $second[0]['params'][':bytes_out_rate']);
        $this->assertSame(3500, $second[0]['params'][':bytes_in_val']);
        $this->assertSame(15000, $second[0]['params'][':bytes_out_val']);
    }

    public function test_connection_rate_never_negative(): void
    {
        $registry  = new MetricsRegistry(10);
        $collector = new MetricsCollector($registry, true);
        $this->mockConnectionPool($this->mockConnection());
        $service = $this->service($collector);

        $registry->openConnection('0-1', 'http', null, null, null, null, 1000);
        $registry->touchConnection('0-1', 5000, 5000, 1004);
        $service->flush(1, 1005);

        $this->queries = [];
        $registry->touchConnection('0-1', 10, 10, 1009);
        $service->flush(1, 1010);

        $conn = $this->queriesMatching('INSERT INTO metrics_connections');
        $this->assertGreaterThanOrEqual(0, $conn[0]['params'][':bytes_in_rate']);
        $this->assertGreaterThanOrEqual(0, $conn[0]['params'][':bytes_out_rate']);
    }

    public function test_prune_emits_three_deletes_with_cutoffs(): void
    {
        $registry  = new MetricsRegistry(10);
        $collector = new MetricsCollector($registry, true);
        $conn = $this->mockConnection();
        $this->mockConnectionPool($conn);
        $service = $this->service($collector, [
            'retention_days'         => 7,
            'connection_ttl_seconds' => 15,
        ]);

        $now = 1_000_000;
        $service->prune($now);

        $connDeletes = $this->queriesMatching('DELETE FROM metrics_connections');
        $rollupDeletes = $this->queriesMatching('DELETE FROM metrics_rollup');
        $routeDeletes = $this->queriesMatching('DELETE FROM metrics_route_rollup');

        $this->assertCount(1, $connDeletes);
        $this->assertCount(1, $rollupDeletes);
        $this->assertCount(1, $routeDeletes);

        $this->assertSame(date('Y-m-d H:i:s', $now - 15), $connDeletes[0]['params'][':cutoff']);
        $this->assertSame(date('Y-m-d H:i:s', $now - 7 * 86400), $rollupDeletes[0]['params'][':cutoff']);
    }

    public function test_prune_is_throttled_across_flushes(): void
    {
        $registry  = new MetricsRegistry(10);
        $collector = new MetricsCollector($registry, true);
        $this->mockConnectionPool($this->mockConnection());
        $service = $this->service($collector, ['flush_interval_seconds' => 5]);

        for ($i = 1; $i <= 11; $i++) {
            $service->flush(1, 1000 + $i);
        }
        $this->assertCount(0, $this->queriesMatching('DELETE FROM metrics_rollup'));

        $service->flush(1, 1012);
        $this->assertCount(1, $this->queriesMatching('DELETE FROM metrics_rollup'));
    }

    public function test_flush_forgets_rate_state_for_departed_connections(): void
    {
        $registry  = new MetricsRegistry(10);
        $collector = new MetricsCollector($registry, true);
        $this->mockConnectionPool($this->mockConnection());
        $service = $this->service($collector);

        $registry->openConnection('0-1', 'http', null, null, null, null, 1000);
        $registry->touchConnection('0-1', 100, 100, 1004);
        $service->flush(1, 1005);

        $registry->closeConnection('0-1');
        $this->queries = [];
        $service->flush(1, 1010);

        $this->assertCount(0, $this->queriesMatching('INSERT INTO metrics_connections'));

        // Re-open -> fresh rate baseline (rate 0 again).
        $registry->openConnection('0-1', 'http', null, null, null, null, 1015);
        $registry->touchConnection('0-1', 100, 100, 1016);
        $this->queries = [];
        $service->flush(1, 1017);

        $conn = $this->queriesMatching('INSERT INTO metrics_connections');
        $this->assertCount(1, $conn);
        $this->assertSame(0, $conn[0]['params'][':bytes_in_rate']);
        $this->assertSame(0, $conn[0]['params'][':bytes_out_rate']);
    }

    public function test_no_metrics_insert_has_prefix_colliding_bind_params(): void
    {
        // Regression: under emulated prepares (which PhlixMySQLConnection requires)
        // a named placeholder that is a strict PREFIX of another in the same
        // statement makes PDO rewrite the wrong token and throw SQLSTATE[HY093]
        // "parameter was not defined". This crash-looped the workers once the
        // producers were wired — :bytes_in ⊂ :bytes_in_rate (connections) AND
        // :h_le_10 ⊂ :h_le_100 ⊂ :h_le_1000 … (rollup). Guard EVERY metrics UPSERT
        // against ever reintroducing such a pair.
        $registry  = new MetricsRegistry(10);
        $collector = new MetricsCollector($registry, true);
        $this->mockConnectionPool($this->mockConnection());

        $registry->recordRequest('GET', '/api/v1/x', 200, 5.0, 10, 20, 1000);   // -> rollup + route_rollup
        $registry->openConnection('c-1', 'websocket', null, '1.2.3.4', null, null, 1000);
        $registry->touchConnection('c-1', 100, 200, 1004);                       // -> connections
        $this->service($collector)->flush(1, 1005);

        $inserts = $this->queriesMatching('INSERT INTO');
        $this->assertNotEmpty($inserts, 'expected rollup + route + connection INSERTs');

        foreach ($inserts as $q) {
            $keys = array_keys($q['params']);
            foreach ($keys as $a) {
                foreach ($keys as $b) {
                    if ($a !== $b) {
                        $this->assertStringStartsNotWith(
                            $a,
                            $b,
                            "bind param '$a' is a prefix of '$b' — breaks emulated prepares"
                        );
                    }
                }
            }
        }
    }

    public function test_flush_interval_seconds_exposes_configured_cadence(): void
    {
        $registry  = new MetricsRegistry(10);
        $collector = new MetricsCollector($registry, true);

        $this->assertSame(5, $this->service($collector)->flushIntervalSeconds());
        $this->assertSame(
            10,
            $this->service($collector, ['flush_interval_seconds' => 10])->flushIntervalSeconds()
        );
        // Clamped to a 1s minimum (rate denominator must never be zero).
        $this->assertSame(
            1,
            $this->service($collector, ['flush_interval_seconds' => 0])->flushIntervalSeconds()
        );
    }

    public function test_prune_tick_evicts_stale_registry_connections(): void
    {
        $registry  = new MetricsRegistry(10);
        $collector = new MetricsCollector($registry, true);
        $this->mockConnectionPool($this->mockConnection());
        $service = $this->service($collector, [
            'flush_interval_seconds' => 5,
            'connection_ttl_seconds' => 15,
        ]);

        // Idle connection, last seen at t=900, never touched again.
        $registry->openConnection('stale-1', 'websocket', null, null, null, null, 900);

        // 12 flushes at the 5s cadence trip the once-a-minute prune tick. At the
        // 12th flush (nowTs=1012) the registry cutoff is 1012-15=997 > 900, so
        // the idle connection is evicted from the in-RAM map — not merely DELETEd
        // from the table (which the pre-existing prune() already did).
        for ($i = 1; $i <= 12; $i++) {
            $service->flush(1, 1000 + $i);
        }

        $this->assertArrayNotHasKey('stale-1', $registry->snapshotConnections());
    }

    public function test_prune_tick_keeps_live_registry_connections(): void
    {
        $registry  = new MetricsRegistry(10);
        $collector = new MetricsCollector($registry, true);
        $this->mockConnectionPool($this->mockConnection());
        $service = $this->service($collector, [
            'flush_interval_seconds' => 5,
            'connection_ttl_seconds' => 15,
        ]);

        $registry->openConnection('live-1', 'websocket', null, null, null, null, 900);

        // Touched forward every cycle so its last_seen stays inside the TTL
        // window — it must survive the prune tick.
        for ($i = 1; $i <= 12; $i++) {
            $registry->touchConnection('live-1', $i * 10, $i * 20, 1000 + $i);
            $service->flush(1, 1000 + $i);
        }

        $this->assertArrayHasKey('live-1', $registry->snapshotConnections());
    }

    /**
     * Mock ConnectionPool::getConnection() to return our mock connection.
     */
    private function mockConnectionPool(Connection $conn): void
    {
        $reflPool = new \ReflectionClass(\Phlix\Hub\Common\Database\ConnectionPool::class);
        $connProp = $reflPool->getProperty('connections');
        $connProp->setAccessible(true);
        $connProp->setValue(null, ['mysql' => $conn]);
    }
}
