<?php

/**
 * Process-isolated smoke for TXMUTEX-A A2 — the stranger-transaction guard on
 * {@see \Phlix\Hub\Common\Database\PhlixMySQLConnection::commitTrans()} and
 * {@see \Phlix\Hub\Common\Database\PhlixMySQLConnection::rollBackTrans()}.
 *
 * Run by `PhlixMySQLConnectionTest::testACoroutineThatNeverHeldTheMutexCannotEndTheHoldersTransaction()`
 * in a CHILD php process (same S179 isolation rationale and SIGKILL terminator
 * as {@see transaction_lock_smoke.php}).
 *
 * ## What it proves
 *
 * Coroutine MAIN opens a transaction and keeps holding it (it is the mutex
 * holder). A second coroutine B — which NEVER took the transaction-scoped
 * mutex — calls `commitTrans()` and `rollBackTrans()`. Pre-guard, both calls
 * reached `parent::commitTrans()`/`parent::rollBackTrans()` on the SHARED PDO
 * handle: B's call committed or erased MAIN's still-open transaction, and the
 * finally-block cleared MAIN's `txOpen`/`txPoisoned` markers while
 * `endTransaction()` (holder-guarded) silently no-op'd — §1.2's destructive
 * half. Post-guard, B is fully inert: the transaction stays open, the markers
 * stay set, and MAIN can still commit its own transaction normally.
 *
 * Markers on success (post-guard behavior):
 *   A:hold, B:commit:true, B:inTx:1, B:txOpen:1, B:holder:stays-A,
 *   B:rollback:true, B:inTx2:1, B:txOpen2:1,
 *   A:commit:true, A:inTx:0, A:holder:free
 *
 * Pre-guard the child prints `B:inTx:0` (MAIN's transaction was committed by
 * the stranger) and `A:commit:threw` (nothing left to commit) — a clean,
 * arm-attributable marker divergence, not a crash.
 */

declare(strict_types=1);

// Silence swoole's reactor/coroutine TRACE/DEBUG chatter so stdout is clean.
if (\function_exists('swoole_async_set')) {
    \swoole_async_set(['log_level' => 5 /* SWOOLE_LOG_ERROR */, 'trace_flags' => 0]);
}

require __DIR__ . '/../../../../../vendor/autoload.php';

use Phlix\Hub\Common\Database\PhlixMySQLConnection;
use Phlix\Hub\Tests\Support\SwooleShutdownIsolation;
use Phlix\Hub\Tests\Support\TransactionLockConnection;

/** @var list<string> $markers */
$markers = [];

$ref = new ReflectionClass(PhlixMySQLConnection::class);

/** @param 'txOpen'|'txState'|'queryLockHolder'|'pdo' $prop */
$read = static function (PhlixMySQLConnection $conn, string $prop) use ($ref) {
    $property = $ref->getProperty($prop);
    $property->setAccessible(true);

    return $property->getValue($conn);
};

$state = new stdClass();
$state->conn = TransactionLockConnection::create();
$state->read = $read;

\Swoole\Coroutine\run(static function () use ($state, &$markers): void {
    $conn = $state->conn;
    $read = $state->read;
    /** @var \PDO $pdo */
    $pdo = $read($conn, 'pdo');

    // MAIN is the holder: it takes the transaction-scoped mutex and does NOT
    // commit until the stranger attempts are recorded.
    if (!$conn->beginTrans()) {
        $markers[] = 'A:begin-failed';

        return;
    }

    $cidA = \Swoole\Coroutine::getCid();
    $markers[] = 'A:hold';

    $done = new \Swoole\Coroutine\Channel(1);

    \Swoole\Coroutine::create(static function () use ($conn, $pdo, $read, $cidA, $done, &$markers): void {
        // A genuinely different coroutine that never called beginTrans():
        // pre-A2, both calls below reach the shared PDO handle.
        $commit = $conn->commitTrans();
        $markers[] = 'B:commit:' . var_export($commit, true);
        $markers[] = 'B:inTx:' . ($pdo->inTransaction() ? '1' : '0');
        $markers[] = 'B:txOpen:' . ($read($conn, 'txOpen') ? '1' : '0');
        $markers[] = 'B:holder:' . ($read($conn, 'queryLockHolder') === $cidA ? 'stays-A' : 'moved');

        $rollback = $conn->rollBackTrans();
        $markers[] = 'B:rollback:' . var_export($rollback, true);
        $markers[] = 'B:inTx2:' . ($pdo->inTransaction() ? '1' : '0');
        $markers[] = 'B:txOpen2:' . ($read($conn, 'txOpen') ? '1' : '0');

        $done->push(true);
    });

    if ($done->pop(2.0) === false) {
        $markers[] = 'B:lost';
    }

    // The holder ends its own transaction as usual — the guards must not
    // make the legitimate path inert. At parent (pre-guard) the stranger's
    // commit already committed MAIN's transaction, so this call has nothing
    // to commit and the vendor throws: recorded, not crashed.
    try {
        $commit = $conn->commitTrans();
        $markers[] = 'A:commit:' . var_export($commit, true);
    } catch (\Throwable $e) {
        $markers[] = 'A:commit:threw';
    }

    $markers[] = 'A:inTx:' . ($pdo->inTransaction() ? '1' : '0');
    $markers[] = 'A:holder:' . ($read($conn, 'queryLockHolder') === -1 ? 'free' : 'held');
});

echo implode("\n", $markers) . "\n";

// S179: flush the markers, then die by SIGKILL so PHP's request shutdown never
// runs in a process that used Swoole coroutines. Never returns.
SwooleShutdownIsolation::terminateWithoutRequestShutdown();
