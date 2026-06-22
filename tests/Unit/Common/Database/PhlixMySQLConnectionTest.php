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

    // NOTE: the live coroutine-mutex behaviour (Channel acquire/release across
    // concurrent coroutines) is intentionally NOT unit-tested here. Driving
    // nested Swoole coroutines inside the PHPUnit process on the CI stack
    // (PHP 8.3 + swoole + ext-uv) segfaults the test runner (exit 139) — the
    // same runtime fragility this mutex exists to work around. It is verified
    // at the integration level (the hub serving concurrent requests) instead.
}
