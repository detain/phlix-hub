<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\Database;

use Phlix\Hub\Common\Database\PooledMySQLConnection;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Unit coverage for the parts of {@see PooledMySQLConnection} that DON'T need a
 * live coroutine scheduler: the non-coroutine (CLI) lease path, delegation of
 * every public method to the leased connection, and the injected raw-connection
 * factory seam. The in-coroutine pool path (idle channel, per-coroutine lease,
 * defer-release) requires the Swoole runtime and is validated separately on a
 * live restart — see the class docblock.
 */
final class PooledMySQLConnectionTest extends TestCase
{
    /**
     * Build a pool whose raw connections come from the given factory, so tests
     * never open a real socket.
     *
     * @param callable():Connection $factory
     */
    private function pool(callable $factory, int $maxSize = 4): PooledMySQLConnection
    {
        return new PooledMySQLConnection('h', 3306, 'u', 'p', 'db', $maxSize, 'utf8mb4', $factory);
    }

    public function testQueryDelegatesToLeasedConnection(): void
    {
        $raw = $this->createMock(Connection::class);
        $raw->expects($this->once())
            ->method('query')
            ->with('SELECT 1', [1, 2])
            ->willReturn([['ok' => 1]]);

        $pool = $this->pool(static fn (): Connection => $raw);

        $this->assertSame([['ok' => 1]], $pool->query('SELECT 1', [1, 2]));
    }

    public function testNonCoroutineReusesASingleConnection(): void
    {
        $calls = 0;
        $raw = $this->createMock(Connection::class);
        $raw->method('query')->willReturn([]);

        // Outside a coroutine every call must reuse the one CLI connection, so
        // the factory is invoked exactly once across many queries.
        $pool = $this->pool(static function () use (&$calls, $raw): Connection {
            $calls++;
            return $raw;
        });

        $pool->query('SELECT 1');
        $pool->query('SELECT 2');
        $pool->query('SELECT 3');

        $this->assertSame(1, $calls, 'CLI path should open exactly one connection');
    }

    public function testRowSingleAndColumnDelegateToLeasedConnection(): void
    {
        // S9: the pool front is now the DEFAULT connection, so it must be a
        // faithful drop-in for the row-returning literal-SQL read helpers too —
        // not just query(). row() in particular is the only primitive that
        // fetches a row for statements query() doesn't special-case (e.g.
        // EXPLAIN); before this delegation existed, a caller hitting the pooled
        // connection's un-constructed parent row() crashed with a socket connect
        // (SQLSTATE[HY000] [2002]) instead of running the query.
        $raw = $this->createMock(Connection::class);
        $raw->expects($this->once())
            ->method('row')
            ->with('EXPLAIN SELECT 1', [7])
            ->willReturn(['id' => 1, 'type' => 'ref']);
        $raw->expects($this->once())
            ->method('single')
            ->with('SELECT COUNT(*) FROM t WHERE x = ?', [3])
            ->willReturn('5');
        $raw->expects($this->once())
            ->method('column')
            ->with('SELECT name FROM t', null)
            ->willReturn(['a', 'b']);

        $pool = $this->pool(static fn (): Connection => $raw);

        $this->assertSame(['id' => 1, 'type' => 'ref'], $pool->row('EXPLAIN SELECT 1', [7]));
        $this->assertSame('5', $pool->single('SELECT COUNT(*) FROM t WHERE x = ?', [3]));
        $this->assertSame(['a', 'b'], $pool->column('SELECT name FROM t'));
    }

    public function testTransactionMethodsDelegate(): void
    {
        $raw = $this->createMock(Connection::class);
        $raw->expects($this->once())->method('beginTrans')->willReturn(true);
        $raw->expects($this->once())->method('commitTrans')->willReturn(true);
        $raw->expects($this->once())->method('rollBackTrans')->willReturn(true);
        $raw->expects($this->once())->method('lastInsertId')->willReturn('42');

        $pool = $this->pool(static fn (): Connection => $raw);

        $this->assertTrue($pool->beginTrans());
        $this->assertTrue($pool->commitTrans());
        $this->assertTrue($pool->rollBackTrans());
        $this->assertSame('42', $pool->lastInsertId());
    }

    public function testCloseConnectionClosesTheCliConnection(): void
    {
        $raw = $this->createMock(Connection::class);
        $raw->method('query')->willReturn([]);
        $raw->expects($this->once())->method('closeConnection');

        $pool = $this->pool(static fn (): Connection => $raw);
        $pool->query('SELECT 1'); // opens the CLI connection
        $pool->closeConnection();
    }

    public function testIsTypeCompatibleWithWorkermanConnection(): void
    {
        // Every Phlix service type-hints Workerman\MySQL\Connection; the pool
        // front MUST satisfy that hint.
        $pool = $this->pool(fn (): Connection => $this->createMock(Connection::class));
        $this->assertInstanceOf(Connection::class, $pool);
    }

    // ---------------------------------------------------------------------
    // Coroutine-runtime harness tests. The in-coroutine pool path (idle
    // channel, per-cid lease, Coroutine::defer release, exhaustion, dirty-tx
    // rollback, dead-connection eviction) IS the fix for the cross-coroutine
    // result-crossing incident, but it needs the real Swoole scheduler. Each
    // is driven in a CHILD php process (nested Swoole coroutines in the PHPUnit
    // runner can segfault — same rationale as PhlixMySQLConnectionTest's
    // serialisation smoke) against socket-free RecordingConnection doubles, so
    // lease/release/rollback/close bookkeeping is asserted without a live MySQL.
    // ---------------------------------------------------------------------

    /**
     * Run one scenario from tests/.../Fixtures/pool_harness.php in a child
     * process and return its stdout marker lines.
     */
    private function runHarness(string $scenario): string
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('ext-swoole required for the pool coroutine harness.');
        }

        $script = __DIR__ . '/Fixtures/pool_harness.php';
        self::assertFileExists($script);

        $cmd = escapeshellarg(PHP_BINARY)
            . ' -d swoole.enable_library=1 '
            . escapeshellarg($script) . ' ' . escapeshellarg($scenario) . ' 2>/dev/null';

        return (string) shell_exec($cmd);
    }

    /**
     * Distinct per-cid lease: two coroutines holding a lease at the same time
     * must each get a DIFFERENT physical connection (the whole point of the
     * pool — never multiplex one socket).
     */
    public function testConcurrentCoroutinesGetDistinctConnections(): void
    {
        $out = $this->runHarness('distinct');

        self::assertMatchesRegularExpression('/^idxA=(\d+)$/m', $out, "harness output:\n{$out}");
        preg_match('/^idxA=(\d+)$/m', $out, $a);
        preg_match('/^idxB=(\d+)$/m', $out, $b);
        self::assertNotSame('', $a[1] ?? '', "harness output:\n{$out}");
        self::assertNotSame(
            $a[1],
            $b[1] ?? '',
            "the two coroutines must lease DIFFERENT connections\n{$out}",
        );
        self::assertStringContainsString('conns=2', $out, "harness output:\n{$out}");
    }

    /**
     * Defer-release: a lease is returned to the idle pool when the coroutine
     * ends and reused by the next coroutine — exactly ONE physical connection
     * across two sequential leases.
     */
    public function testLeaseIsReturnedToIdleAndReusedAfterCoroutineEnds(): void
    {
        $out = $this->runHarness('reuse');

        self::assertStringContainsString('conns=1', $out, "expected a single reused connection\n{$out}");
        self::assertStringContainsString('query:SELECT A|probe|query:SELECT B', $out, $out);
    }

    /**
     * Defer-release also fires when the leasing code path RAISES: the
     * connection is still returned (no leak / starvation) and reused.
     */
    public function testLeaseIsReleasedEvenWhenLeasingCodeThrows(): void
    {
        $out = $this->runHarness('reuse_throw');

        self::assertStringContainsString(
            'conns=1',
            $out,
            "a throwing coroutine must still release its lease for reuse\n{$out}",
        );
        self::assertStringContainsString('query:SELECT A|probe|query:SELECT B', $out, $out);
    }

    /**
     * Pool-exhaustion (bound holds, no deadlock): with maxSize=1, while A holds
     * the only connection B blocks in acquire() and is handed the SAME
     * connection the instant A releases — no second socket opened.
     */
    public function testExhaustedPoolBlocksThenHandsOffTheFreedConnection(): void
    {
        $out = $this->runHarness('exhaust_handoff');

        self::assertStringContainsString('idxB=0', $out, "B must reuse A's freed connection (index 0)\n{$out}");
        self::assertStringContainsString('conns=1', $out, "pool must NOT exceed maxSize=1\n{$out}");
    }

    /**
     * Pool-exhaustion (anti-hang guard): when the only connection is never
     * released, acquire() blocks then THROWS a bounded "pool exhausted"
     * RuntimeException instead of deadlocking. ~10 s (the hardcoded
     * idle->pop(10.0) timeout) — grouped 'slow' so it can be excluded.
     *
     * @group slow
     */
    public function testExhaustedPoolThrowsBoundedInsteadOfHanging(): void
    {
        $out = $this->runHarness('exhaust_throw');

        self::assertStringContainsString('B:threw:', $out, "acquisition must throw, not hang\n{$out}");
        self::assertStringContainsString('pool exhausted', $out, "harness output:\n{$out}");
    }

    /**
     * Txn-rollback-on-dirty-release: a connection returned with an open,
     * uncommitted transaction is rolled back BEFORE it re-enters idle, so the
     * next lessee starts clean (an interrupted coroutine can't poison it).
     */
    public function testDirtyTransactionIsRolledBackOnRelease(): void
    {
        $out = $this->runHarness('txn_rollback');

        self::assertStringContainsString('conns=1', $out, $out);
        // begin (A) → rollback (on dirty release) → probe + query (B reuses it).
        self::assertStringContainsString('begin|query:SELECT A|rollback|probe|query:SELECT B', $out, $out);
    }

    /**
     * Dead-connection eviction: a pooled connection that fails its SELECT 1
     * liveness probe is CLOSED (FD released) and evicted, and a fresh
     * connection is opened for the lessee — never handing out a dead socket.
     * The `conn0_closes=1` assertion also pins the FD-churn fix (the evicted
     * dead connection must be closeConnection()'d, not merely dropped).
     */
    public function testDeadConnectionIsClosedAndEvictedNotHandedOut(): void
    {
        $out = $this->runHarness('dead_evict');

        self::assertStringContainsString('conns=2', $out, "a replacement connection must be opened\n{$out}");
        self::assertStringContainsString('idxB=1', $out, "lessee must get the fresh conn, not the dead one\n{$out}");
        self::assertStringContainsString(
            'conn0_closes=1',
            $out,
            "the evicted dead connection must be closeConnection()'d (FD-churn fix)\n{$out}",
        );
    }
}
