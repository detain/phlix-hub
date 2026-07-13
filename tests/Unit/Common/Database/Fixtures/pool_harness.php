<?php

/**
 * Process-isolated coroutine harness for {@see \Phlix\Hub\Common\Database\PooledMySQLConnection}.
 *
 * Run by {@see \Phlix\Hub\Tests\Unit\Common\Database\PooledMySQLConnectionTest}
 * in a CHILD php process (see the sibling {@see transaction_lock_smoke.php} for
 * why: nested Swoole coroutines inside the PHPUnit runner can segfault). Each
 * scenario boots the real Swoole coroutine runtime and drives the pool's
 * concurrency machinery — per-coroutine lease, Coroutine::defer release,
 * pool-exhaustion, dirty-transaction rollback, dead-connection eviction —
 * against socket-free {@see \Phlix\Hub\Tests\Support\RecordingConnection}
 * doubles, then prints `key=value` marker lines the parent asserts on.
 *
 * Usage: php pool_harness.php <scenario>
 */

declare(strict_types=1);

// Silence swoole's reactor/coroutine chatter so stdout carries only markers.
if (\function_exists('swoole_async_set')) {
    \swoole_async_set(['log_level' => 5 /* SWOOLE_LOG_ERROR */, 'trace_flags' => 0]);
}

require __DIR__ . '/../../../../../vendor/autoload.php';

use Phlix\Hub\Common\Database\PooledMySQLConnection;
use Phlix\Hub\Tests\Support\RecordingConnection;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Workerman\MySQL\Connection;

use function Swoole\Coroutine\run;

$scenario = $argv[1] ?? '';

/**
 * Build a pool whose raw connections are RecordingConnection doubles. The
 * created instances are appended to $conns (by reference) so the caller can
 * inspect lease/release/probe/close bookkeeping after the run.
 *
 * @param list<RecordingConnection> $conns
 */
$makePool = static function (int $maxSize, array &$conns): PooledMySQLConnection {
    $next = 0;
    $factory = static function () use (&$conns, &$next): Connection {
        $conn = new RecordingConnection(++$next);
        $conns[] = $conn;
        return $conn;
    };
    return new PooledMySQLConnection('h', 3306, 'u', 'p', 'db', $maxSize, 'utf8mb4', $factory);
};

/** @param list<RecordingConnection> $conns */
$dump = static function (array $conns): string {
    $out = 'conns=' . count($conns);
    foreach ($conns as $i => $c) {
        $out .= "\nconn{$i}=id:{$c->id};closes:{$c->closes};calls:" . implode('|', $c->calls);
    }
    return $out;
};

/** Which conn index recorded a given delegated call (-1 if none). */
$findCall = static function (array $conns, string $needle): int {
    foreach ($conns as $i => $c) {
        if (in_array($needle, $c->calls, true)) {
            return $i;
        }
    }
    return -1;
};

switch ($scenario) {
    // Two concurrent coroutines, both holding a lease at once, must each get a
    // DIFFERENT physical connection (never multiplex one socket).
    case 'distinct':
        /** @var list<RecordingConnection> $conns */
        $conns = [];
        run(static function () use ($makePool, $findCall, &$conns): void {
            $pool = $makePool(2, $conns);
            $bMay = new Channel(1);
            $done = new Channel(2);
            Coroutine::create(static function () use ($pool, $bMay, $done): void {
                $pool->query('SELECT A'); // A leases + HOLDS its lease
                $bMay->push(true);        // let B lease while A still holds
                Coroutine::sleep(0.05);
                $done->push(true);
            });
            Coroutine::create(static function () use ($pool, $bMay, $done): void {
                $bMay->pop();
                $pool->query('SELECT B');
                $done->push(true);
            });
            $done->pop();
            $done->pop();
            $idxA = $findCall($conns, 'query:SELECT A');
            $idxB = $findCall($conns, 'query:SELECT B');
            echo "idxA={$idxA}\nidxB={$idxB}\n";
        });
        echo $dump($conns) . "\n";
        break;

    // A lease is returned to the idle pool when the coroutine ends and reused by
    // the next coroutine (one physical connection across two sequential leases).
    case 'reuse':
        /** @var list<RecordingConnection> $conns */
        $conns = [];
        run(static function () use ($makePool, &$conns): void {
            $pool = $makePool(4, $conns);
            $d = new Channel(1);
            Coroutine::create(static function () use ($pool, $d): void {
                $pool->query('SELECT A');
                $d->push(true);
            });
            $d->pop();
            Coroutine::sleep(0.02); // let A's Coroutine::defer release run
            $d2 = new Channel(1);
            Coroutine::create(static function () use ($pool, $d2): void {
                $pool->query('SELECT B');
                $d2->push(true);
            });
            $d2->pop();
        });
        echo $dump($conns) . "\n";
        break;

    // Even when the leasing code path RAISES, the connection is still returned
    // to idle (the pool's Coroutine::defer release fires on coroutine end) and
    // reused — no leak, pool not starved. The exception is caught at the
    // coroutine boundary, mirroring the framework's per-request error boundary
    // (an uncaught throw is fatal to the worker under Swoole, so a released
    // lease could never be observed there); the point is that the pool does NOT
    // rely on explicit cleanup in the happy path — defer returns the lease even
    // when the body threw before reaching any manual release.
    case 'reuse_throw':
        /** @var list<RecordingConnection> $conns */
        $conns = [];
        run(static function () use ($makePool, &$conns): void {
            $pool = $makePool(4, $conns);
            $d = new Channel(1);
            Coroutine::create(static function () use ($pool, $d): void {
                try {
                    $pool->query('SELECT A'); // leases + registers the defer release
                    throw new \RuntimeException('boom'); // body raises after leasing
                } catch (\Throwable) {
                    // swallowed at the boundary, like the request error handler
                } finally {
                    $d->push(true);
                }
            });
            $d->pop();
            Coroutine::sleep(0.02);
            $d2 = new Channel(1);
            Coroutine::create(static function () use ($pool, $d2): void {
                $pool->query('SELECT B');
                $d2->push(true);
            });
            $d2->pop();
        });
        echo $dump($conns) . "\n";
        break;

    // maxSize=1: while A holds the only connection, B blocks in acquire() and is
    // handed the SAME connection the instant A releases — pool bound holds, no
    // second socket opened, no deadlock.
    case 'exhaust_handoff':
        /** @var list<RecordingConnection> $conns */
        $conns = [];
        run(static function () use ($makePool, $findCall, &$conns): void {
            $pool = $makePool(1, $conns);
            $leased = new Channel(1);
            $hold = new Channel(1);
            $done = new Channel(2);
            Coroutine::create(static function () use ($pool, $leased, $hold, $done): void {
                $pool->query('SELECT A');
                $leased->push(true);
                $hold->pop();      // keep the lease until told to release
                $done->push(true); // coroutine ends here → defer frees the conn
            });
            $leased->pop();
            Coroutine::create(static function () use ($pool, $done): void {
                $pool->query('SELECT B'); // blocks until A frees the conn
                $done->push(true);
            });
            Coroutine::sleep(0.05); // B is now parked in idle->pop()
            $hold->push(true);      // release A → B unblocks with the same conn
            $done->pop();
            $done->pop();
            $idxB = $findCall($conns, 'query:SELECT B');
            echo "idxB={$idxB}\n";
        });
        echo $dump($conns) . "\n";
        break;

    // maxSize=1: A never releases, so B's acquire() blocks then THROWS a bounded
    // "pool exhausted" RuntimeException rather than hanging forever. (~10 s: the
    // hardcoded idle->pop(10.0) timeout — proves the anti-deadlock guard fires.)
    case 'exhaust_throw':
        run(static function () use ($makePool): void {
            $conns = [];
            $pool = $makePool(1, $conns);
            $leased = new Channel(1);
            $hold = new Channel(1);
            $bDone = new Channel(1);
            Coroutine::create(static function () use ($pool, $leased, $hold): void {
                $pool->query('SELECT A');
                $leased->push(true);
                $hold->pop(); // hold the only connection for the whole test
            });
            $leased->pop();
            Coroutine::create(static function () use ($pool, $bDone): void {
                try {
                    $pool->query('SELECT B');
                    $bDone->push('B:no-throw');
                } catch (\Throwable $e) {
                    $bDone->push('B:threw:' . $e->getMessage());
                }
            });
            $result = $bDone->pop();
            echo $result . "\n";
            $hold->push(true); // let A finish so run() can exit
        });
        break;

    // A connection returned with an OPEN transaction is rolled back before it
    // re-enters idle, so the next lessee starts clean.
    case 'txn_rollback':
        /** @var list<RecordingConnection> $conns */
        $conns = [];
        run(static function () use ($makePool, &$conns): void {
            $pool = $makePool(1, $conns);
            $d = new Channel(1);
            Coroutine::create(static function () use ($pool, $d): void {
                $pool->beginTrans();      // marks the lease tx-pending
                $pool->query('SELECT A');
                $d->push(true);           // ends WITHOUT commit → dirty release
            });
            $d->pop();
            Coroutine::sleep(0.02);       // let the defer rollback + re-pool run
            $d2 = new Channel(1);
            Coroutine::create(static function () use ($pool, $d2): void {
                $pool->query('SELECT B'); // reuses the (rolled-back, clean) conn
                $d2->push(true);
            });
            $d2->pop();
        });
        echo $dump($conns) . "\n";
        break;

    // A pooled connection that fails its SELECT 1 liveness probe is CLOSED and
    // evicted, and a fresh connection is opened for the lessee — never handing
    // out a dead socket.
    case 'dead_evict':
        /** @var list<RecordingConnection> $conns */
        $conns = [];
        run(static function () use ($makePool, $findCall, &$conns): void {
            $pool = $makePool(1, $conns);
            $d = new Channel(1);
            Coroutine::create(static function () use ($pool, $d): void {
                $pool->query('SELECT A');
                $d->push(true); // ends → conn0 returned to idle
            });
            $d->pop();
            Coroutine::sleep(0.02);
            $conns[0]->alive = false; // DB dropped the idle connection
            $d2 = new Channel(1);
            Coroutine::create(static function () use ($pool, $d2): void {
                $pool->query('SELECT B'); // pops dead conn0 → evict → open conn1
                $d2->push(true);
            });
            $d2->pop();
            $idxB = $findCall($conns, 'query:SELECT B');
            echo "idxB={$idxB}\n";
            echo 'conn0_closes=' . $conns[0]->closes . "\n";
        });
        echo $dump($conns) . "\n";
        break;

    default:
        fwrite(STDERR, "unknown scenario: {$scenario}\n");
        exit(2);
}
