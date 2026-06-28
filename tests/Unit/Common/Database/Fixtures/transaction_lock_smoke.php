<?php

declare(strict_types=1);

/**
 * Process-isolated end-to-end smoke for the B4 transaction-scoped coroutine
 * mutex on {@see \Phlix\Hub\Common\Database\PhlixMySQLConnection}.
 *
 * Run by {@see \Phlix\Hub\Tests\Unit\Common\Database\PhlixMySQLConnectionTest::testConcurrentTransactionSerialisesSecondCoroutine()}
 * in a CHILD php process: nested Swoole coroutines inside the PHPUnit runner
 * can segfault (exit 139) — the exact runtime fragility this mutex guards
 * against — so the real cross-coroutine Channel hand-off is proven here, out
 * of process, where a crash cannot take the suite down with it.
 *
 * Two coroutines share ONE connection (SQLite-backed PDO, no MySQL server):
 *   - A opens a transaction and yields (sleep) while holding it.
 *   - B (started after A) tries to open a transaction on the SAME connection.
 *
 * B must block on the per-connection mutex until A commits. On success the
 * markers print in the order: A:begin, B:try-begin, A:commit, B:begin.
 *
 * Emits ONLY those markers on stdout (swoole trace/debug noise is silenced via
 * the log level) so the parent can assert the ordering deterministically.
 */

// Silence swoole's reactor/coroutine TRACE/DEBUG chatter so stdout is clean.
if (\function_exists('swoole_async_set')) {
    \swoole_async_set(['log_level' => 5 /* SWOOLE_LOG_ERROR */, 'trace_flags' => 0]);
}

require __DIR__ . '/../../../../../vendor/autoload.php';

use Phlix\Hub\Tests\Support\TransactionLockConnection;

/** @var list<string> $order */
$order = [];

\Swoole\Coroutine\run(static function () use (&$order): void {
    $conn = TransactionLockConnection::create();
    $done = new \Swoole\Coroutine\Channel(2);

    // Coroutine A: hold a transaction, then yield (sleep) so B is scheduled
    // while A's transaction is still open.
    \Swoole\Coroutine::create(static function () use ($conn, &$order, $done): void {
        $conn->beginTrans();
        $order[] = 'A:begin';
        \Swoole\Coroutine::sleep(0.05); // yield while holding the tx lock
        $order[] = 'A:commit';
        $conn->commitTrans();
        $done->push(true);
    });

    // Coroutine B: start slightly later so A grabs the lock first, then try to
    // begin a transaction on the SAME connection — it must wait for A.
    \Swoole\Coroutine::create(static function () use ($conn, &$order, $done): void {
        \Swoole\Coroutine::sleep(0.01);
        $order[] = 'B:try-begin';
        $conn->beginTrans(); // blocks until A commits
        $order[] = 'B:begin';
        $conn->commitTrans();
        $done->push(true);
    });

    $done->pop();
    $done->pop();
});

echo implode("\n", $order) . "\n";
