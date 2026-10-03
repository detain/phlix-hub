<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Stats\Metrics;

use Phlix\Hub\Stats\Metrics\MetricsCollector;
use Phlix\Hub\Stats\Metrics\MetricsFlushService;
use Phlix\Hub\Stats\Metrics\MetricsRegistry;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Workerman\MySQL\Connection;

/**
 * W5 tests for the LIVE `server.metrics.retention_days` seam on
 * {@see MetricsFlushService::prune()}.
 *
 * Prune ticks run only in the count=1 relay worker at flush cadence — never a
 * hot path — so every tick re-reads the effective value: an admin shrinking
 * the window sees the first DELETE wave within one tick. Without a resolver
 * the boot config value is preserved exactly (existing suite pins that).
 *
 * @package Phlix\Hub\Tests\Unit\Stats\Metrics
 */
final class MetricsFlushRetentionTest extends TestCase
{
    /**
     * @var array<int, array{sql: string, params: array<string, mixed>}>
     */
    private array $queries = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->queries = [];
    }

    protected function tearDown(): void
    {
        $this->restorePool();

        parent::tearDown();
    }

    private function mockConnection(): Connection
    {
        $mock = $this->createMock(Connection::class);
        $mock->method('query')->willReturnCallback(
            /** @param array<string, mixed> $bindings */
            function (string $sql, $bindings = []): array {
                $this->queries[] = ['sql' => $sql, 'params' => (array) $bindings];

                return [];
            },
        );

        return $mock;
    }

    private function mockConnectionPool(Connection $conn): void
    {
        $pool = (new ReflectionClass(\Phlix\Hub\Common\Database\ConnectionPool::class));
        $prop = $pool->getProperty('connections');
        $prop->setAccessible(true);
        $prop->setValue(null, ['mysql' => $conn, 'metrics' => $conn]);
    }

    private function restorePool(): void
    {
        $pool = (new ReflectionClass(\Phlix\Hub\Common\Database\ConnectionPool::class));
        $prop = $pool->getProperty('connections');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    /**
     * @return array<int, array{sql: string, params: array<string, mixed>}>
     */
    private function queriesMatching(string $needle): array
    {
        return array_values(array_filter(
            $this->queries,
            static fn (array $q): bool => str_contains($q['sql'], $needle),
        ));
    }

    public function testResolverValueHonoredOnRollupCutoffs(): void
    {
        $collector = new MetricsCollector(new MetricsRegistry(10), true);
        $this->mockConnectionPool($this->mockConnection());

        $service = new MetricsFlushService(
            $collector,
            ['retention_days' => 7, 'connection_ttl_seconds' => 15],
            static fn (): int => 3,
        );

        $now = 1_000_000;
        $service->prune($now);

        $rollups = $this->queriesMatching('DELETE FROM metrics_rollup');
        $routes  = $this->queriesMatching('DELETE FROM metrics_route_rollup');
        self::assertCount(1, $rollups);
        self::assertCount(1, $routes);
        self::assertSame(date('Y-m-d H:i:s', $now - 3 * 86400), $rollups[0]['params']['cutoff']);
        self::assertSame(date('Y-m-d H:i:s', $now - 3 * 86400), $routes[0]['params']['cutoff']);
        // Connection TTL stays boot-governed (not a shipped setting).
        $connDeletes = $this->queriesMatching('DELETE FROM metrics_connections');
        self::assertSame(date('Y-m-d H:i:s', $now - 15), $connDeletes[0]['params']['cutoff']);
    }

    public function testResolverIsReReadEveryTick(): void
    {
        $collector = new MetricsCollector(new MetricsRegistry(10), true);
        $this->mockConnectionPool($this->mockConnection());

        $days = 30;
        $service = new MetricsFlushService(
            $collector,
            ['retention_days' => 7],
            static function () use (&$days): int {
                return $days;
            },
        );

        $now = 2_000_000;
        $service->prune($now);
        $rollups = $this->queriesMatching('DELETE FROM metrics_rollup');
        self::assertSame(date('Y-m-d H:i:s', $now - 30 * 86400), $rollups[0]['params']['cutoff']);

        $days = 2; // admin shrinks the window between ticks
        $this->queries = [];
        $service->prune($now);
        $rollups = $this->queriesMatching('DELETE FROM metrics_rollup');
        self::assertSame(
            date('Y-m-d H:i:s', $now - 2 * 86400),
            $rollups[0]['params']['cutoff'],
            'live per-tick: new window lands within one tick',
        );
    }

    public function testNullResolverKeepsBootValueForever(): void
    {
        $collector = new MetricsCollector(new MetricsRegistry(10), true);
        $this->mockConnectionPool($this->mockConnection());

        $service = new MetricsFlushService($collector, ['retention_days' => 14]);

        $now = 3_000_000;
        $service->prune($now);
        $service->prune($now);

        foreach ($this->queriesMatching('DELETE FROM metrics_rollup') as $delete) {
            self::assertSame(date('Y-m-d H:i:s', $now - 14 * 86400), $delete['params']['cutoff']);
        }
    }
}
