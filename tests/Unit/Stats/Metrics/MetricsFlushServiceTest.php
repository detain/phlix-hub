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
        $this->assertSame(1, $p[':h_le_10']);
        $this->assertSame(1, $p[':h_le_1000']);
        $this->assertSame(0, $p[':h_gt_5000']);
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
        $this->assertSame(3500, $second[0]['params'][':bytes_in']);
        $this->assertSame(15000, $second[0]['params'][':bytes_out']);
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
