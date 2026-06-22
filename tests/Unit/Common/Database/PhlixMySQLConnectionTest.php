<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\Database;

use Phlix\Hub\Common\Database\PhlixMySQLConnection;
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

    /**
     * Exercise the real coroutine-mutex lifecycle inside the Swoole runtime:
     * the first acquire allocates the Channel and takes the token (true), a
     * nested acquire by the same coroutine is reentrant (false, no extra
     * token consumed), and release resets the holder to free (-1) and returns
     * the token so the next coroutine can proceed.
     */
    public function testQueryLockLifecycleInsideCoroutine(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required for the coroutine-mutex test.');
        }

        $conn = (new ReflectionClass(PhlixMySQLConnection::class))
            ->newInstanceWithoutConstructor();

        $captured = [];
        \Swoole\Coroutine\run(function () use ($conn, &$captured): void {
            $cid = $this->invokePrivate($conn, 'currentCoroutineId');
            $captured['cidPositive'] = is_int($cid) && $cid > 0;
            $captured['firstAcquire'] = $this->invokePrivate($conn, 'acquireQueryLock', $cid);
            $captured['reentrantAcquire'] = $this->invokePrivate($conn, 'acquireQueryLock', $cid);
            $this->invokePrivate($conn, 'releaseQueryLock');
        });

        $holder = (new ReflectionClass(PhlixMySQLConnection::class))->getProperty('queryLockHolder');
        $holder->setAccessible(true);

        $this->assertTrue($captured['cidPositive'], 'getCid() should be positive inside a coroutine');
        $this->assertTrue($captured['firstAcquire'], 'first acquire takes the lock');
        $this->assertFalse($captured['reentrantAcquire'], 'same-coroutine re-acquire is reentrant');
        $this->assertSame(-1, $holder->getValue($conn), 'release frees the lock holder');
    }

    /**
     * Two coroutines contending for the same connection must be serialised:
     * the second cannot enter its critical section until the first releases.
     */
    public function testQueryLockSerialisesConcurrentCoroutines(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required for the coroutine-mutex test.');
        }

        $conn = (new ReflectionClass(PhlixMySQLConnection::class))
            ->newInstanceWithoutConstructor();

        $order = [];
        \Swoole\Coroutine\run(function () use ($conn, &$order): void {
            $started = new \Swoole\Coroutine\Channel(1);

            \Swoole\Coroutine::create(function () use ($conn, &$order, $started): void {
                $cid = $this->invokePrivate($conn, 'currentCoroutineId');
                $this->invokePrivate($conn, 'acquireQueryLock', $cid);
                $order[] = 'A-enter';
                $started->push(true);          // let B start trying to acquire
                \Swoole\Coroutine::sleep(0.02); // hold the lock while B waits
                $order[] = 'A-leave';
                $this->invokePrivate($conn, 'releaseQueryLock');
            });

            \Swoole\Coroutine::create(function () use ($conn, &$order, $started): void {
                $started->pop();               // ensure A acquired first
                $cid = $this->invokePrivate($conn, 'currentCoroutineId');
                $this->invokePrivate($conn, 'acquireQueryLock', $cid); // blocks until A releases
                $order[] = 'B-enter';
                $this->invokePrivate($conn, 'releaseQueryLock');
            });
        });

        // B must only enter after A left — proves mutual exclusion.
        $this->assertSame(['A-enter', 'A-leave', 'B-enter'], $order);
    }
}
