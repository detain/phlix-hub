<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support;

use PDO;
use Phlix\Hub\Common\Database\PhlixMySQLConnection;
use ReflectionClass;

/**
 * Test double for {@see PhlixMySQLConnection} that exercises the REAL
 * transaction-scoped coroutine-mutex logic
 * ({@see PhlixMySQLConnection::beginTrans()} / `commitTrans()` /
 * `rollBackTrans()`) without opening a MySQL socket.
 *
 * The connection's transaction primitives delegate to `parent::beginTrans()`
 * etc., which only need a working PDO handle that implements
 * `beginTransaction()` / `commit()` / `rollBack()` / `inTransaction()`. An
 * in-memory SQLite PDO satisfies that contract exactly, so injecting one lets
 * the production lock code run against a real PDO transaction while the test
 * stays hermetic (no DB server, no network, deterministic).
 *
 * The instance is created via {@see ReflectionClass::newInstanceWithoutConstructor()}
 * — the parent constructor would open a real MySQL connection — and the SQLite
 * PDO is injected into the inherited (untyped) `$pdo` property by reflection,
 * mirroring how {@see BindingContractConnection} sidesteps the socket.
 *
 * @psalm-suppress PropertyNotSetInConstructor
 *   The parent lazily initialises its query-builder properties; this factory
 *   never touches them.
 */
final class TransactionLockConnection
{
    /**
     * Build a {@see PhlixMySQLConnection} backed by an in-memory SQLite PDO so
     * the real transaction methods run without a MySQL server.
     */
    public static function create(): PhlixMySQLConnection
    {
        $ref = new ReflectionClass(PhlixMySQLConnection::class);
        /** @var PhlixMySQLConnection $conn */
        $conn = $ref->newInstanceWithoutConstructor();

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdoProp = $ref->getProperty('pdo');
        $pdoProp->setAccessible(true);
        $pdoProp->setValue($conn, $pdo);

        return $conn;
    }
}
