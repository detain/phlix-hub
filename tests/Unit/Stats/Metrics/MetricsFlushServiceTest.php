<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Stats\Metrics;

use Phlix\Hub\Stats\Metrics\MetricsCollector;
use Phlix\Hub\Stats\Metrics\MetricsFlushService;
use Phlix\Hub\Stats\Metrics\MetricsRegistry;
use Phlix\Hub\Tests\Support\BindingContractConnection;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see MetricsFlushService}, which drains a registry into MySQL.
 *
 * The Workerman MySQL {@see Connection} is mocked and every `query()` call is
 * captured (SQL + bindings) so we can assert the UPSERT / DELETE SQL fragments.
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

    public function testFlushNoopsWhenCollectorDisabled(): void
    {
        $registry  = new MetricsRegistry(10);
        $collector = new MetricsCollector($registry, false);
        $this->mockConnectionPool($this->mockConnection());

        $registry->recordRequest('GET', '/a', 200, 5.0, 0, 0, 1000);

        $this->service($collector)->flush(3, 1000);

        $this->assertSame([], $this->queries);
    }

    public function testFlushUpsertsOverallRollupWithHistogramColumns(): void
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
        $this->assertStringContainsString(
            'duration_ms_max = GREATEST(duration_ms_max, VALUES(duration_ms_max))',
            $q['sql']
        );

        $p = $q['params'];
        $this->assertSame(date('Y-m-d H:i:s', 1000), $p['bucket']);
        $this->assertSame('hub-relay', $p['worker_id']);
        $this->assertSame(2, $p['request_count']);
        $this->assertSame(1, $p['error_count']);
        $this->assertSame(605, $p['duration_ms_sum']);
        $this->assertSame(600, $p['duration_ms_max']);
        $this->assertSame(150, $p['bytes_in']);
        $this->assertSame(950, $p['bytes_out']);
        // Histogram bind placeholders are :h0..:h8 (prefix-collision-safe); the
        // COLUMNS keep their h_le_* names. :h0 = <=10ms bucket, :h5 = <=1000ms,
        // :h8 = >5000ms overflow.
        $this->assertSame(1, $p['h0']);
        $this->assertSame(1, $p['h5']);
        $this->assertSame(0, $p['h8']);
    }

    public function testFlushUpsertsRouteRollup(): void
    {
        $registry  = new MetricsRegistry(10);
        $collector = new MetricsCollector($registry, true);
        $this->mockConnectionPool($this->mockConnection());

        $registry->recordRequest('POST', '/api/v1/media', 201, 12.0, 0, 0, 1000);

        $this->service($collector)->flush(2, 1000);

        $routes = $this->queriesMatching('INSERT INTO metrics_route_rollup');
        $this->assertCount(1, $routes);

        $p = $routes[0]['params'];
        $this->assertSame(date('Y-m-d H:i:s', 1000), $p['bucket']);
        $this->assertSame('hub-relay', $p['worker_id']);
        $this->assertSame('POST', $p['method']);
        $this->assertSame('/api/v1/media', $p['route']);
        $this->assertSame(1, $p['request_count']);
    }

    public function testConnectionRateIsZeroOnFirstFlushAndDeltaOnSecond(): void
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
        $this->assertSame(0, $first[0]['params']['bytes_in_rate']);
        $this->assertSame(0, $first[0]['params']['bytes_out_rate']);

        // Second flush 5s later.
        $this->queries = [];
        $registry->touchConnection('0-1', 3500, 15000, 1009);
        $service->flush(1, 1010);

        $second = $this->queriesMatching('INSERT INTO metrics_connections');
        $this->assertCount(1, $second);
        $this->assertSame(500, $second[0]['params']['bytes_in_rate']);
        $this->assertSame(2000, $second[0]['params']['bytes_out_rate']);
        $this->assertSame(3500, $second[0]['params']['bytes_in_val']);
        $this->assertSame(15000, $second[0]['params']['bytes_out_val']);
    }

    public function testConnectionRateNeverNegative(): void
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
        $this->assertGreaterThanOrEqual(0, $conn[0]['params']['bytes_in_rate']);
        $this->assertGreaterThanOrEqual(0, $conn[0]['params']['bytes_out_rate']);
    }

    public function testPruneEmitsThreeDeletesWithCutoffs(): void
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

        $this->assertSame(date('Y-m-d H:i:s', $now - 15), $connDeletes[0]['params']['cutoff']);
        $this->assertSame(date('Y-m-d H:i:s', $now - 7 * 86400), $rollupDeletes[0]['params']['cutoff']);
    }

    public function testPruneIsThrottledAcrossFlushes(): void
    {
        $registry  = new MetricsRegistry(10);
        $collector = new MetricsCollector($registry, true);
        $this->mockConnectionPool($this->mockConnection());
        $service = $this->service($collector, ['flush_interval_seconds' => 5]);

        // Pruning worker ($shouldPrune=true): the retention DELETEs are still
        // throttled to ~once/minute (the 12th flush), not run on every tick.
        for ($i = 1; $i <= 11; $i++) {
            $service->flush(1, 1000 + $i, true);
        }
        $this->assertCount(0, $this->queriesMatching('DELETE FROM metrics_rollup'));

        $service->flush(1, 1012, true);
        $this->assertCount(1, $this->queriesMatching('DELETE FROM metrics_rollup'));
    }

    public function testFlushDoesNotPruneWhenShouldPruneIsFalse(): void
    {
        // [H-W3] single-pruner gate — the NON-pruning worker (an HTTP or the
        // client-relay worker). It still flushes its own registry every tick,
        // but must NEVER issue the shared-table retention DELETEs. Cross the
        // once-a-minute prune tick (12 flushes at the 5s cadence) with real
        // request activity and assert: rollups are persisted, but NO DELETE ever
        // fires on this worker (that is what was multiplying churn ~4×).
        $registry  = new MetricsRegistry(10);
        $collector = new MetricsCollector($registry, true);
        $this->mockConnectionPool($this->mockConnection());
        $service = $this->service($collector, ['flush_interval_seconds' => 5]);

        for ($i = 1; $i <= 12; $i++) {
            $registry->recordRequest('GET', '/a', 200, 5.0, 1, 1, 1000 + $i);
            // Default $shouldPrune=false is the non-pruning-worker contract.
            $service->flush(0, 1000 + $i);
        }

        // The registry WAS flushed (per-worker metrics persisted) ...
        $this->assertNotEmpty(
            $this->queriesMatching('INSERT INTO metrics_rollup'),
            'a non-pruning worker must still flush its own registry',
        );
        // ... but the single-pruner gate held: no retention DELETE on this worker.
        $this->assertCount(0, $this->queriesMatching('DELETE FROM metrics_connections'));
        $this->assertCount(0, $this->queriesMatching('DELETE FROM metrics_rollup'));
        $this->assertCount(0, $this->queriesMatching('DELETE FROM metrics_route_rollup'));
    }

    public function testFlushPrunesOnlyOnTheDesignatedPruningWorker(): void
    {
        // The single designated pruner (the count=1 relay worker) passes
        // $shouldPrune=true, so on the throttled prune tick it — and only it —
        // runs the three retention DELETEs, exactly once each.
        $registry  = new MetricsRegistry(10);
        $collector = new MetricsCollector($registry, true);
        $this->mockConnectionPool($this->mockConnection());
        $service = $this->service($collector, ['flush_interval_seconds' => 5]);

        for ($i = 1; $i <= 12; $i++) {
            $service->flush(0, 1000 + $i, true);
        }

        $this->assertCount(1, $this->queriesMatching('DELETE FROM metrics_connections'));
        $this->assertCount(1, $this->queriesMatching('DELETE FROM metrics_rollup'));
        $this->assertCount(1, $this->queriesMatching('DELETE FROM metrics_route_rollup'));
    }

    public function testInRamConnectionEvictionRunsEvenWhenPruneIsGatedOff(): void
    {
        // Decoupling proof: the per-worker in-RAM registry eviction is NOT gated
        // by $shouldPrune — it must keep every worker's connection map bounded
        // (else the client-relay worker leaks). With $shouldPrune=false and thus
        // NO DB prune, an idle connection is still evicted from the in-RAM map on
        // the prune tick.
        $registry  = new MetricsRegistry(10);
        $collector = new MetricsCollector($registry, true);
        $this->mockConnectionPool($this->mockConnection());
        $service = $this->service($collector, [
            'flush_interval_seconds' => 5,
            'connection_ttl_seconds' => 15,
        ]);

        // Idle connection, last seen at t=900, never touched again.
        $registry->openConnection('stale-1', 'websocket', null, null, null, null, 900);

        for ($i = 1; $i <= 12; $i++) {
            $service->flush(0, 1000 + $i, false);
        }

        // Evicted from RAM (bounded) ...
        $this->assertArrayNotHasKey('stale-1', $registry->snapshotConnections());
        // ... without any shared-table DELETE (prune gated off on this worker).
        $this->assertCount(0, $this->queriesMatching('DELETE FROM metrics_connections'));
    }

    public function testFlushForgetsRateStateForDepartedConnections(): void
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
        $this->assertSame(0, $conn[0]['params']['bytes_in_rate']);
        $this->assertSame(0, $conn[0]['params']['bytes_out_rate']);
    }

    public function testNoMetricsInsertHasPrefixCollidingBindParams(): void
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

    public function testFlushQueriesHonourTheWorkermanBindingContract(): void
    {
        // The real proof: BindingContractConnection replays workerman's bind()
        // rule — every :placeholder must have a colon-FREE key, and a ':'-prefixed
        // key throws SQLSTATE[HY093] (the double-colon bug that crash-looped the
        // relay + HTTP workers in production). Drive a full flush (rollup + route +
        // connection UPSERTs) AND a prune tick (the three DELETEs) through it: any
        // mis-keyed query would throw here.
        $registry  = new MetricsRegistry(10);
        $collector = new MetricsCollector($registry, true);
        $conn      = new BindingContractConnection();
        $this->mockConnectionPool($conn);

        $registry->recordRequest('GET', '/api/v1/x', 200, 5.0, 10, 20, 1000);
        $registry->openConnection('c-1', 'websocket', null, '1.2.3.4', null, null, 1000);
        $registry->touchConnection('c-1', 100, 200, 1004);

        // 12 flushes at the 5s cadence trip the once-a-minute prune tick. Pass
        // $shouldPrune=true so the three DELETEs are driven through the binding
        // contract too (only the single pruning worker runs them in production).
        $service = $this->service($collector, ['flush_interval_seconds' => 5]);
        for ($i = 1; $i <= 12; $i++) {
            $service->flush(1, 1000 + $i, true);
        }

        // Reaching here means no HY093 was thrown; assert the writes + prune ran.
        $sqls = array_map(static fn (array $c): string => $c['sql'], $conn->calls);
        $has  = static fn (string $needle): bool
            => $sqls !== [] && array_filter($sqls, static fn (string $s): bool => str_contains($s, $needle)) !== [];
        $this->assertTrue($has('INSERT INTO metrics_rollup'));
        $this->assertTrue($has('INSERT INTO metrics_route_rollup'));
        $this->assertTrue($has('INSERT INTO metrics_connections'));
        $this->assertTrue($has('DELETE FROM metrics_connections'));
    }

    public function testFlushIntervalSecondsExposesConfiguredCadence(): void
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

    public function testPruneTickEvictsStaleRegistryConnections(): void
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

    public function testPruneTickKeepsLiveRegistryConnections(): void
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

    public function testFlushDrainsAndPersistsRelayMetricsIntoRollupColumns(): void
    {
        $registry  = new MetricsRegistry(10);
        $collector = new MetricsCollector($registry, true);
        $this->mockConnectionPool($this->mockConnection());

        // Populate every relay counter/gauge the migration-036 columns hold.
        $registry->setRelayPendingRequests(4);
        $registry->recordRelayReplyDrop();
        $registry->recordRelayReplyDrop();
        $registry->recordRelayError(503);
        $registry->recordRelayError(504);
        $registry->recordRelayError(504);
        $registry->setRelayDecodeBufferSize(9000);
        $registry->recordRelayCancel();
        $registry->recordRelayCancel();
        $registry->recordRelayCancel();
        $registry->recordRelayLatency(5.0, 1000);    // -> <=10ms bucket (rl0)
        $registry->recordRelayLatency(300.0, 1000);  // -> <=500ms bucket (rl4)

        $this->service($collector)->flush(1, 1000);

        // The relay row is the only metrics_rollup INSERT (no request/route/conn
        // activity here). Distinguish it by its relay column list.
        $relay = $this->queriesMatching('relay_pending_requests');
        $this->assertCount(1, $relay);

        $q = $relay[0];
        $this->assertStringContainsString('INSERT INTO metrics_rollup', $q['sql']);
        $this->assertStringContainsString(
            'relay_reply_drops         = relay_reply_drops + VALUES(relay_reply_drops)',
            $q['sql'],
        );
        $this->assertStringContainsString(
            'relay_pending_requests    = GREATEST(relay_pending_requests, VALUES(relay_pending_requests))',
            $q['sql'],
        );

        $p = $q['params'];
        $this->assertSame(date('Y-m-d H:i:s', 1000), $p['bucket']);
        $this->assertSame('hub-relay', $p['worker_id']);
        $this->assertSame(4, $p['rpending']);
        $this->assertSame(2, $p['rdrops']);
        $this->assertSame(1, $p['re503']);
        $this->assertSame(2, $p['re504']);
        $this->assertSame(9000, $p['rbuffer']);
        $this->assertSame(3, $p['rcancels']);
        $this->assertStringContainsString(
            'relay_cancels             = relay_cancels + VALUES(relay_cancels)',
            $q['sql'],
        );
        // The two latency observations land in the <=10ms (rl0) and <=500ms (rl4)
        // histogram buckets.
        $this->assertSame(1, $p['rl0']);
        $this->assertSame(1, $p['rl4']);
        $this->assertSame(0, $p['rl8']);
    }

    public function testRelayFlushSkipsAnIdleAllZeroWindow(): void
    {
        $registry  = new MetricsRegistry(10);
        $collector = new MetricsCollector($registry, true);
        $this->mockConnectionPool($this->mockConnection());

        // No relay activity at all → no relay row is written.
        $this->service($collector)->flush(1, 1000);

        $this->assertCount(0, $this->queriesMatching('relay_pending_requests'));
    }

    public function testRelayMetricsAreDrainedSoASecondFlushWritesNothing(): void
    {
        $registry  = new MetricsRegistry(10);
        $collector = new MetricsCollector($registry, true);
        $this->mockConnectionPool($this->mockConnection());
        $service = $this->service($collector);

        $registry->recordRelayError(503);
        $registry->recordRelayLatency(20.0, 1000);
        $service->flush(1, 1000);
        $this->assertCount(1, $this->queriesMatching('relay_pending_requests'));

        // drainRelayMetrics() reset the accumulators — a second flush with no new
        // activity writes no relay row.
        $this->queries = [];
        $service->flush(1, 1005);
        $this->assertCount(0, $this->queriesMatching('relay_pending_requests'));
    }

    public function testRelayInsertHasNoPrefixCollidingBindParams(): void
    {
        // Same emulated-prepares guard as test_no_metrics_insert_has_prefix_...
        // but for the relay UPSERT: :rl0..:rl8 / :re503 / :re504 / :rpending etc.
        // must have no name that is a strict prefix of another.
        $registry  = new MetricsRegistry(10);
        $collector = new MetricsCollector($registry, true);
        $this->mockConnectionPool($this->mockConnection());

        $registry->setRelayPendingRequests(3);
        $registry->recordRelayReplyDrop();
        $registry->recordRelayError(503);
        $registry->recordRelayError(504);
        $registry->setRelayDecodeBufferSize(1000);
        $registry->recordRelayLatency(42.0, 1000);
        $this->service($collector)->flush(1, 1000);

        $relay = $this->queriesMatching('relay_pending_requests');
        $this->assertCount(1, $relay);

        $keys = array_keys($relay[0]['params']);
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

    public function testRelayFlushHonoursTheWorkermanBindingContract(): void
    {
        // Drive the relay UPSERT through BindingContractConnection: any colon-in-
        // key or double-colon mis-keying would throw HY093 here.
        $registry  = new MetricsRegistry(10);
        $collector = new MetricsCollector($registry, true);
        $conn      = new BindingContractConnection();
        $this->mockConnectionPool($conn);

        $registry->setRelayPendingRequests(2);
        $registry->recordRelayError(504);
        $registry->recordRelayLatency(75.0, 1000);
        $this->service($collector)->flush(1, 1000);

        $sqls = array_map(static fn (array $c): string => $c['sql'], $conn->calls);
        $has  = static fn (string $needle): bool
            => $sqls !== [] && array_filter($sqls, static fn (string $s): bool => str_contains($s, $needle)) !== [];
        $this->assertTrue($has('relay_pending_requests'), 'the relay UPSERT must have run without an HY093');
    }

    public function testRelayLatencyHistogramBucketingWritesItsOwnBucket(): void
    {
        // A latency observed in an EARLIER bucket than the flush's scalar bucket
        // is written into that earlier bucket's row (the histogram is
        // time-bucketed), while the scalar counters go into the flush bucket.
        $registry  = new MetricsRegistry(10);
        $collector = new MetricsCollector($registry, true);
        $this->mockConnectionPool($this->mockConnection());

        $registry->recordRelayLatency(5.0, 1000);   // bucket 1000
        $registry->setRelayPendingRequests(1);      // scalar -> flush bucket 1020
        $this->service($collector)->flush(1, 1025);

        $relay = $this->queriesMatching('relay_pending_requests');
        // Two rows: bucket 1000 (histogram only) + bucket 1020 (scalars).
        $this->assertCount(2, $relay);

        $byBucket = [];
        foreach ($relay as $q) {
            $byBucket[$q['params']['bucket']] = $q['params'];
        }
        $this->assertArrayHasKey(date('Y-m-d H:i:s', 1000), $byBucket);
        $this->assertArrayHasKey(date('Y-m-d H:i:s', 1020), $byBucket);
        // Histogram bucket carries the observation but no pending gauge.
        $this->assertSame(1, $byBucket[date('Y-m-d H:i:s', 1000)]['rl0']);
        $this->assertSame(0, $byBucket[date('Y-m-d H:i:s', 1000)]['rpending']);
        // Scalar bucket carries the pending gauge.
        $this->assertSame(1, $byBucket[date('Y-m-d H:i:s', 1020)]['rpending']);
    }

    /**
     * Mock ConnectionPool::getConnection() to return our mock connection.
     */
    private function mockConnectionPool(Connection $conn): void
    {
        $reflPool = new \ReflectionClass(\Phlix\Hub\Common\Database\ConnectionPool::class);
        $connProp = $reflPool->getProperty('connections');
        $connProp->setAccessible(true);
        // The flush uses the dedicated 'metrics' connection (see
        // MetricsFlushService::CONNECTION); register the mock under both so the
        // test exercises the same handle the flush resolves.
        $connProp->setValue(null, ['mysql' => $conn, 'metrics' => $conn]);
    }
}
