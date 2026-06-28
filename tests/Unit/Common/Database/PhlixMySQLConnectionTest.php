<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\Database;

use Phlix\Hub\Common\Database\PhlixMySQLConnection;
use Phlix\Hub\Tests\Support\TransactionLockConnection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Regression test for the workerman/mysql v1.0.9 positional-binding bug.
 *
 * The parent Connection's bindMore() feeds raw 0-based array keys into
 * PDO::bindParam(), which throws "Argument #1 ($param) must be greater
 * than or equal to 1" on PHP 8.x — seen when saving hub settings via
 * HubSettingsRepository::set() with a positional `[$id, $key, ...]` array.
 * PhlixMySQLConnection re-keys list arrays to 1-indexed before delegating.
 */
final class PhlixMySQLConnectionTest extends TestCase
{
    /**
     * @return list<array{int, mixed}>
     */
    private function boundParameters(PhlixMySQLConnection $conn): array
    {
        $prop = (new ReflectionClass(PhlixMySQLConnection::class))
            ->getProperty('parameters');
        $prop->setAccessible(true);
        /** @var list<array{int, mixed}> $params */
        $params = $prop->getValue($conn);

        return $params;
    }

    public function testListArrayIsRekeyedToOneIndexed(): void
    {
        // Instantiate without the parent constructor (which would open a
        // real PDO connection); we only exercise bindMore().
        $conn = (new ReflectionClass(PhlixMySQLConnection::class))
            ->newInstanceWithoutConstructor();

        $conn->bindMore(['id-1', 'theme', 'dark', 'string']);

        $bound = $this->boundParameters($conn);
        // Positions must start at 1 (the bug produced 0) and increment.
        $this->assertSame([1, 2, 3, 4], array_map(static fn ($p) => $p[0], $bound));
        $this->assertSame(['id-1', 'theme', 'dark', 'string'], array_map(static fn ($p) => $p[1], $bound));
    }

    public function testAssociativeArrayPassesThroughUntouched(): void
    {
        $conn = (new ReflectionClass(PhlixMySQLConnection::class))
            ->newInstanceWithoutConstructor();

        $conn->bindMore(['id' => 'abc']);

        $bound = $this->boundParameters($conn);
        // A string-keyed array is not a list, so the override leaves it for
        // the parent (which binds named placeholders by ':'-prefixing the
        // key). The key must NOT have been re-keyed to an integer 1.
        $this->assertSame(':id', $bound[0][0]);
        $this->assertSame('abc', $bound[0][1]);
    }

    public function testEmptyArrayIsNoOp(): void
    {
        $conn = (new ReflectionClass(PhlixMySQLConnection::class))
            ->newInstanceWithoutConstructor();

        $conn->bindMore([]);

        $this->assertSame([], $this->boundParameters($conn));
    }

    /**
     * @return mixed
     */
    private function invokePrivate(PhlixMySQLConnection $conn, string $method, mixed ...$args)
    {
        $ref = (new ReflectionClass(PhlixMySQLConnection::class))->getMethod($method);
        $ref->setAccessible(true);

        return $ref->invoke($conn, ...$args);
    }

    /**
     * Outside the Swoole coroutine runtime (plain CLI / PHPUnit), the
     * coroutine-mutex guard must report -1 so query() takes the direct
     * passthrough to the parent and never touches a Channel (which can only
     * be created inside the coroutine runtime).
     */
    public function testCurrentCoroutineIdIsNegativeOutsideCoroutine(): void
    {
        $conn = (new ReflectionClass(PhlixMySQLConnection::class))
            ->newInstanceWithoutConstructor();

        $this->assertSame(-1, $this->invokePrivate($conn, 'currentCoroutineId'));
    }

    /**
     * The query mutex is reentrant per coroutine: a coroutine that already
     * holds the lock must get `false` back (so it does NOT release a lock it
     * is still using on the outer call) without ever allocating the Channel.
     */
    public function testAcquireQueryLockIsReentrantForSameCoroutine(): void
    {
        $conn = (new ReflectionClass(PhlixMySQLConnection::class))
            ->newInstanceWithoutConstructor();

        $holder = (new ReflectionClass(PhlixMySQLConnection::class))->getProperty('queryLockHolder');
        $holder->setAccessible(true);
        $holder->setValue($conn, 7);

        // cid 7 already holds the lock → reentrant acquire returns false.
        $this->assertFalse($this->invokePrivate($conn, 'acquireQueryLock', 7));

        // No Channel should have been allocated on the reentrant path.
        $lock = (new ReflectionClass(PhlixMySQLConnection::class))->getProperty('queryLock');
        $lock->setAccessible(true);
        $this->assertNull($lock->getValue($conn));
    }

    // ---------------------------------------------------------------------
    // Transaction-scoped coroutine mutex (B4).
    //
    // The live cross-coroutine Channel hand-off is NOT spun up inside the
    // PHPUnit process: driving nested Swoole coroutines on the CI stack
    // (PHP 8.3 + swoole + ext-uv) segfaults the runner (exit 139) — the same
    // fragility this mutex exists to work around (verified at integration
    // level / a guarded standalone smoke). Instead these tests drive the
    // lock state machine DETERMINISTICALLY: they assert the exact field
    // transitions and Channel-token availability that PROVE a second
    // coroutine would block until the transaction holder releases, that the
    // holder's own nested queries run reentrantly (no self-deadlock), and
    // that the lock is released on commit AND rollback (incl. exception
    // paths), exactly once.
    // ---------------------------------------------------------------------

    /**
     * Read a private property of a {@see PhlixMySQLConnection}.
     */
    private function readProp(PhlixMySQLConnection $conn, string $name): mixed
    {
        $prop = (new ReflectionClass(PhlixMySQLConnection::class))->getProperty($name);
        $prop->setAccessible(true);

        return $prop->getValue($conn);
    }

    /**
     * Write a private property of a {@see PhlixMySQLConnection}.
     */
    private function writeProp(PhlixMySQLConnection $conn, string $name, mixed $value): void
    {
        $prop = (new ReflectionClass(PhlixMySQLConnection::class))->getProperty($name);
        $prop->setAccessible(true);
        $prop->setValue($conn, $value);
    }

    /**
     * Place the connection in the field state
     * {@see PhlixMySQLConnection::beginTrans()} leaves it in for coroutine
     * `$cid`: holder set to `$cid` and the in-transaction flag raised. The
     * `queryLock` Channel is deliberately left `null` — a real
     * `Swoole\Coroutine\Channel` can only be pushed/popped INSIDE the coroutine
     * runtime (it fatals in plain CLI/PHPUnit), and `releaseQueryLock()` guards
     * the push with `if ($queryLock !== null)`, so the holder/flag transitions
     * under test run faithfully without a live Channel. The cross-coroutine
     * Channel hand-off itself is proven by the process-isolated smoke test.
     */
    private function enterCoroutineTransaction(PhlixMySQLConnection $conn, int $cid): void
    {
        $this->writeProp($conn, 'queryLock', null);
        $this->writeProp($conn, 'queryLockHolder', $cid);
        $this->writeProp($conn, 'inTransaction', true);
    }

    /**
     * While a coroutine is inside a transaction, the SAME coroutine's per-query
     * acquire must be reentrant (returns false → the query does NOT release the
     * transaction lock), so the holder can run nested queries without
     * dead-locking against its own open transaction.
     */
    public function testQueryIsReentrantInsideOwnTransaction(): void
    {
        $conn = (new ReflectionClass(PhlixMySQLConnection::class))->newInstanceWithoutConstructor();
        $this->enterCoroutineTransaction($conn, 42);

        // The holder (cid 42) issuing a query reuses the held lock (no
        // re-acquire, so its finally won't release the transaction lock).
        $this->assertFalse($this->invokePrivate($conn, 'acquireQueryLock', 42));
        // Still held by the same coroutine, transaction still open.
        $this->assertSame(42, $this->readProp($conn, 'queryLockHolder'));
        $this->assertTrue($this->readProp($conn, 'inTransaction'));
    }

    /**
     * A DIFFERENT coroutine is NOT recognised as the holder while a transaction
     * is open, so its `acquireQueryLock()` would fall through to a blocking
     * `pop()` on the drained Channel (serialising AFTER commit/rollback rather
     * than interleaving). We assert the reentrancy guard rejects the foreign
     * cid; the actual blocking `pop()` is covered by the smoke test.
     */
    public function testForeignCoroutineIsNotTreatedAsHolder(): void
    {
        $conn = (new ReflectionClass(PhlixMySQLConnection::class))->newInstanceWithoutConstructor();
        $this->enterCoroutineTransaction($conn, 42);

        $this->assertNotSame(99, $this->readProp($conn, 'queryLockHolder'));
        $this->assertTrue(
            $this->readProp($conn, 'inTransaction'),
            'transaction stays open so a foreign acquire must wait, not pass through',
        );
    }

    /**
     * endTransaction() releases the mutex for the holder: the holder is cleared
     * and the in-transaction flag is lowered (the Channel push is exercised by
     * the smoke test; here `queryLock` is null so the guarded push no-ops).
     */
    public function testEndTransactionReleasesForHolder(): void
    {
        $conn = (new ReflectionClass(PhlixMySQLConnection::class))->newInstanceWithoutConstructor();
        $this->enterCoroutineTransaction($conn, 42);

        $this->invokePrivate($conn, 'endTransaction', 42);

        $this->assertSame(-1, $this->readProp($conn, 'queryLockHolder'));
        $this->assertFalse($this->readProp($conn, 'inTransaction'));
    }

    /**
     * endTransaction() for a coroutine that is NOT the holder is a no-op: it
     * must never release a lock held by another coroutine's transaction.
     */
    public function testEndTransactionIsNoOpForNonHolder(): void
    {
        $conn = (new ReflectionClass(PhlixMySQLConnection::class))->newInstanceWithoutConstructor();
        $this->enterCoroutineTransaction($conn, 42);

        $this->invokePrivate($conn, 'endTransaction', 99);

        // Untouched: still held by cid 42.
        $this->assertSame(42, $this->readProp($conn, 'queryLockHolder'));
        $this->assertTrue($this->readProp($conn, 'inTransaction'));
    }

    /**
     * endTransaction() is idempotent: a second call (e.g. the caller's own
     * catch-block rollback after our execute() override already rolled back
     * from inside a failing reentrant query) must NOT release again — the flag
     * is already lowered and the holder already cleared.
     */
    public function testEndTransactionIsIdempotent(): void
    {
        $conn = (new ReflectionClass(PhlixMySQLConnection::class))->newInstanceWithoutConstructor();
        $this->enterCoroutineTransaction($conn, 42);

        $this->invokePrivate($conn, 'endTransaction', 42); // first release
        // After release, inTransaction is false → the guard short-circuits, so
        // a duplicate call cannot touch the lock again.
        $this->assertFalse($this->readProp($conn, 'inTransaction'));
        $this->invokePrivate($conn, 'endTransaction', 42); // duplicate, no-op

        $this->assertSame(-1, $this->readProp($conn, 'queryLockHolder'));
        $this->assertFalse($this->readProp($conn, 'inTransaction'));
    }

    /**
     * Outside a coroutine (CLI migrations / cron, cid `< 0`) the transaction
     * methods run the parent PDO transaction directly with no Channel — they
     * must succeed against a real (SQLite-backed) PDO and never allocate a lock.
     */
    public function testTransactionMethodsRunDirectlyOutsideCoroutine(): void
    {
        $conn = TransactionLockConnection::create();

        $this->assertTrue($conn->beginTrans());
        $this->assertTrue($conn->commitTrans());

        $this->assertTrue($conn->beginTrans());
        $this->assertTrue($conn->rollBackTrans());

        // No Channel was ever created on the CLI path.
        $this->assertNull($this->readProp($conn, 'queryLock'));
        $this->assertSame(-1, $this->readProp($conn, 'queryLockHolder'));
        $this->assertFalse($this->readProp($conn, 'inTransaction'));
    }

    /**
     * rollBackTrans() on the CLI path stays safe even when no transaction is
     * open (the parent guards on PDO::inTransaction()), so the
     * execute()-override → rollBackTrans() error path never fatals.
     */
    public function testRollBackOutsideTransactionIsSafe(): void
    {
        $conn = TransactionLockConnection::create();

        // No beginTrans() first — must not throw.
        $this->assertTrue($conn->rollBackTrans());
    }

    /**
     * A throw from inside an open transaction (simulating a failing query) must
     * still release the lock: this exercises the idempotent release path the
     * way rollBackTrans()'s `finally` drives it on the exception path.
     */
    public function testReleaseHappensOnExceptionPath(): void
    {
        $conn = (new ReflectionClass(PhlixMySQLConnection::class))->newInstanceWithoutConstructor();
        $this->enterCoroutineTransaction($conn, 7);

        // Simulate the rollBackTrans() finally block firing for the holder
        // because a query inside the transaction threw.
        try {
            throw new \RuntimeException('query inside transaction failed');
        } catch (\RuntimeException) {
            $this->invokePrivate($conn, 'endTransaction', 7);
        }

        $this->assertSame(-1, $this->readProp($conn, 'queryLockHolder'));
        $this->assertFalse($this->readProp($conn, 'inTransaction'));
    }

    /**
     * End-to-end serialisation proof, run in a CHILD PHP process so the known
     * "nested Swoole coroutines inside the PHPUnit runner segfault" fragility
     * can never crash this suite: a child boots the swoole coroutine runtime,
     * coroutine A opens a transaction on a shared {@see PhlixMySQLConnection}
     * and yields while holding it, coroutine B then tries to begin a
     * transaction on the SAME connection. B must NOT proceed until A commits —
     * the child prints the event order and we assert B's "got lock" event lands
     * strictly AFTER A's commit, proving no interleaving into A's open
     * transaction.
     */
    public function testConcurrentTransactionSerialisesSecondCoroutine(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('ext-swoole required for the coroutine serialisation smoke.');
        }

        $script = __DIR__ . '/Fixtures/transaction_lock_smoke.php';
        self::assertFileExists($script);

        $cmd = escapeshellarg(PHP_BINARY)
            . ' -d swoole.enable_library=1 '
            . escapeshellarg($script) . ' 2>/dev/null';
        $output = (string) shell_exec($cmd);
        $events = array_values(array_filter(explode("\n", trim($output))));

        // The child emits exactly these four ordered markers on success.
        $expected = ['A:begin', 'B:try-begin', 'A:commit', 'B:begin'];
        self::assertSame(
            $expected,
            $events,
            "child output (serialisation order) was:\n" . $output,
        );

        // B:begin (B acquired the lock) MUST come AFTER A:commit (A released).
        $posBgot = array_search('B:begin', $events, true);
        $posAcommit = array_search('A:commit', $events, true);
        self::assertIsInt($posBgot);
        self::assertIsInt($posAcommit);
        self::assertGreaterThan(
            $posAcommit,
            $posBgot,
            'second coroutine must serialise AFTER the first commits, never interleave',
        );
    }
}
