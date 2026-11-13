<?php

/**
 * Process-isolated smoke for the HUB-2 poison path INSIDE a coroutine.
 *
 * Run by `PhlixMySQLConnectionTest::testPoisonedTransactionReleasesLockInsideCoroutine()`
 * in a CHILD php process (same isolation rationale and SIGKILL terminator as
 * {@see transaction_lock_smoke.php}). The non-coroutine poison test cannot see
 * a leak of the per-connection transaction mutex, because outside a coroutine
 * no mutex is taken. Here, coroutine A opens a transaction (taking the mutex),
 * the PDO answers "server has gone away" (2006) inside it, so execute()
 * poisons and rolls back; the mutex MUST be released, so coroutine B can begin
 * its own transaction. If the poison branch leaked the lock, B would block
 * forever and the watchdog reports B:blocked instead.
 *
 * Markers on success: A:begin, A:poisoned, A:poison-cleared, B:begin.
 */

declare(strict_types=1);

if (\function_exists('swoole_async_set')) {
    \swoole_async_set(['log_level' => 5 /* SWOOLE_LOG_ERROR */, 'trace_flags' => 0]);
}

require __DIR__ . '/../../../../../vendor/autoload.php';

use Phlix\Hub\Common\Database\PhlixMySQLConnection;
use Phlix\Hub\Tests\Support\SwooleShutdownIsolation;
use Phlix\Hub\Tests\Support\TransactionLockConnection;

/** @var list<string> $order */
$order = [];

\Swoole\Coroutine\run(static function () use (&$order): void {
    $conn = TransactionLockConnection::create();
    $done = new \Swoole\Coroutine\Channel(2);

    $pdo = new class ('sqlite::memory:') extends \PDO {
        /**
         * @param array<int, mixed> $options
         */
        #[\ReturnTypeWillChange]
        public function prepare(string $query, array $options = [])
        {
            $e = new \PDOException('MySQL server has gone away');
            $e->errorInfo = ['HY000', 2006, 'MySQL server has gone away'];
            throw $e;
        }
    };
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

    \Swoole\Coroutine::create(static function () use ($conn, $pdo, &$order, $done): void {
        $conn->beginTrans();
        $order[] = 'A:begin';

        $ref = new \ReflectionClass(PhlixMySQLConnection::class);
        $pdoProp = $ref->getProperty('pdo');
        $pdoProp->setAccessible(true);
        $pdoProp->setValue($conn, $pdo);
        $exec = $ref->getMethod('execute');
        $exec->setAccessible(true);
        try {
            $exec->invoke($conn, 'UPDATE t SET a = 1', []);
        } catch (\PDOException) {
            $order[] = 'A:poisoned';
        }
        // The caller's rollback clears the poison flag (and must be a safe no-op on the lock).
        $conn->rollBackTrans();
        // execute() dropped the dead socket (pdo = null); stand in for the
        // reconnect with a working handle so B's beginTrans() exercises ONLY
        // the mutex, not a MySQL dial.
        $fresh = new \PDO('sqlite::memory:');
        $fresh->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdoProp->setValue($conn, $fresh);
        $order[] = 'A:poison-cleared';
        $done->push('A');
    });

    \Swoole\Coroutine::create(static function () use ($conn, &$order, $done): void {
        \Swoole\Coroutine::sleep(0.05); // A has finished its poisoned transaction by now
        $conn->beginTrans();             // blocks forever if the poison branch leaked the mutex
        $order[] = 'B:begin';
        $conn->commitTrans();
        $done->push('B');
    });

    // Watchdog: 1s for both to report. A leak leaves B blocked and A's marker alone.
    $done->pop(1.0);
    if ($done->pop(1.0) === false) {
        $order[] = 'B:blocked';
    }
});

echo implode("\n", $order) . "\n";

SwooleShutdownIsolation::terminateWithoutRequestShutdown();
