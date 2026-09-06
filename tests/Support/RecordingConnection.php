<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support;

use Workerman\MySQL\Connection;

/**
 * Socket-free {@see Connection} test double for the {@see \Phlix\Hub\Common\Database\PooledMySQLConnection}
 * coroutine-harness fixtures.
 *
 * It deliberately does NOT call the parent constructor (which would open a real
 * MySQL socket); instead every method the pool front delegates to is overridden
 * to RECORD the call so a fixture can prove the pool's lease/release/rollback/
 * eviction bookkeeping without a live server. Each instance carries a unique
 * {@see $id} so a fixture can assert whether two coroutines got the SAME or
 * DIFFERENT physical connection.
 *
 * Liveness: {@see query()} treats the bareword `SELECT 1` probe specially — it
 * throws when {@see $alive} is false, so the pool's dead-connection eviction
 * path (`isConnectionAlive()` → false) can be driven deterministically.
 */
final class RecordingConnection extends Connection
{
    /** @var int Monotonic id so fixtures can tell distinct physical connections apart. */
    public int $id;

    /** @var list<string> Ordered log of every delegated call. */
    public array $calls = [];

    /** @var bool When false, the `SELECT 1` liveness probe throws (simulates a dead socket). */
    public bool $alive = true;

    /** @var int Times {@see closeConnection()} was invoked (FD-release accounting). */
    public int $closes = 0;

    /**
     * @param int $id Caller-assigned instance id (see the factory in each fixture).
     *
     * @psalm-suppress MissingParentConstructorCall Intentional: never open a socket.
     */
    public function __construct(int $id)
    {
        // Deliberately NOT calling parent::__construct() — no socket in tests.
        $this->id = $id;
    }

    /**
     * @param string                        $query
     * @param array<int|string, mixed>|null  $params
     * @param int                            $fetchmode
     * @return mixed
     */
    public function query($query = '', $params = null, $fetchmode = \PDO::FETCH_ASSOC)
    {
        if ($query === 'SELECT 1') {
            $this->calls[] = 'probe';
            if (!$this->alive) {
                throw new \RuntimeException('server has gone away');
            }
            return [['1' => 1]];
        }
        $this->calls[] = 'query:' . $query;
        return [];
    }

    /**
     * @param string                        $query
     * @param array<int|string, mixed>|null  $params
     * @param int                            $fetchmode
     * @return array<array-key, mixed>
     */
    public function row($query = '', $params = null, $fetchmode = \PDO::FETCH_ASSOC)
    {
        $this->calls[] = 'row:' . $query;
        return [];
    }

    /**
     * @param string                        $query
     * @param array<int|string, mixed>|null  $params
     * @return string
     */
    public function single($query = '', $params = null): string
    {
        $this->calls[] = 'single:' . $query;
        return '';
    }

    /**
     * @param string                        $query
     * @param array<int|string, mixed>|null  $params
     * @return array<array-key, mixed>
     */
    public function column($query = '', $params = null)
    {
        $this->calls[] = 'column:' . $query;
        return [];
    }

    public function beginTrans(): bool
    {
        $this->calls[] = 'begin';
        return true;
    }

    public function commitTrans(): bool
    {
        $this->calls[] = 'commit';
        return true;
    }

    public function rollBackTrans(): bool
    {
        $this->calls[] = 'rollback';
        return true;
    }

    /**
     * @return string
     */
    public function lastInsertId()
    {
        $this->calls[] = 'lastInsertId';
        return (string) $this->id;
    }

    public function closeConnection(): void
    {
        $this->closes++;
        $this->calls[] = 'close';
    }
}
