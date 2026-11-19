<?php

/**
 * Process-isolated smoke for TXMUTEX-A A3 — the CHECKED transaction-mutex pop
 * on {@see \Phlix\Hub\Common\Database\PhlixMySQLConnection::beginTrans()}.
 *
 * Run by `PhlixMySQLConnectionTest::testACancelledWaiterCannotStealTheTransactionMutex()`
 * in a CHILD php process (same S179 isolation rationale and SIGKILL
 * terminator as {@see transaction_lock_smoke.php}).
 *
 * ## What it proves
 *
 * MAIN opens a transaction (it holds the transaction-scoped mutex). Coroutine
 * B parks in `beginTrans()` waiting for that mutex; MAIN then CANCELS B.
 * A cancelled `Channel::pop()` returns `false` — pre-cure the hub ignored that
 * return value (unchecked `acquireQueryLock()`), so the cancelled waiter
 * declared itself the new holder and marched into `parent::beginTrans()`,
 * which either failed inside MAIN's still-open PDO transaction (the vendor
 * throws "cannot start a transaction within a transaction") or — worse in the
 * abstract — left the holder bookkeeping pointing at a dead coroutine whose
 * error-path `releaseQueryLock()` then freed a mutex it never legitimately
 * owned. The cure mirrors the server's `beginTrans()` shape: a `false` pop is
 * a CANCELLATION and throws with the server's exact wording, leaving the
 * holder and the mutex untouched.
 *
 * Markers on success (post-cure behavior):
 *   A:begin, B:threw:cancel, holder:stays-A, A:commit:true,
 *   holderAfter:free, A:inTx:0
 *
 * Pre-cure the child prints `B:threw:other:cannot start ...` and
 * `holder:free` (B's steal-then-release clobbered MAIN's ownership) and
 * `A:inTx:1` (MAIN's transaction never made it through the API) — a clean,
 * arm-attributable divergence.
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

$read = static function (PhlixMySQLConnection $conn, string $prop) use ($ref) {
    $property = $ref->getProperty($prop);
    $property->setAccessible(true);

    return $property->getValue($conn);
};

\Swoole\Coroutine\run(static function () use ($read, &$markers): void {
    $conn = TransactionLockConnection::create();
    /** @var \PDO $pdo */
    $pdo = $read($conn, 'pdo');

    $cidA = \Swoole\Coroutine::getCid();
    if (!$conn->beginTrans()) {
        $markers[] = 'A:begin-failed';

        return;
    }

    $markers[] = 'A:begin';

    $started = new \Swoole\Coroutine\Channel(1);
    $bDone = new \Swoole\Coroutine\Channel(1);

    $cidB = \Swoole\Coroutine::create(static function () use ($conn, $started, $bDone, &$markers): void {
        // Announce, then park in beginTrans() — the pop() inside blocks until
        // this coroutine is cancelled (the cure's path) or steals the mutex.
        $started->push(true);
        try {
            $conn->beginTrans();
            $markers[] = 'B:no-throw';
        } catch (\PDOException $e) {
            $markers[] = str_contains($e->getMessage(), 'cancelled while waiting')
                ? 'B:threw:cancel'
                : 'B:threw:other:' . $e->getMessage();
        }

        $bDone->push(true);
    });

    if ($started->pop(2.0) === false) {
        $markers[] = 'B:never-started';

        return;
    }

    // B is parked in the mutex pop (single-threaded scheduler: it ran until
    // its first suspension point, which is that pop). Cancel it.
    \Swoole\Coroutine::cancel($cidB);

    if ($bDone->pop(2.0) === false) {
        $markers[] = 'B:lost';

        return;
    }

    $markers[] = 'holder:' . ($read($conn, 'queryLockHolder') === $cidA ? 'stays-A' : 'moved');

    try {
        $commit = $conn->commitTrans();
        $markers[] = 'A:commit:' . var_export($commit, true);
    } catch (\Throwable $e) {
        $markers[] = 'A:commit:threw';
    }

    $markers[] = 'holderAfter:' . ($read($conn, 'queryLockHolder') === -1 ? 'free' : 'held');
    $markers[] = 'A:inTx:' . ($pdo->inTransaction() ? '1' : '0');
});

echo implode("\n", $markers) . "\n";

// S179: flush the markers, then die by SIGKILL so PHP's request shutdown never
// runs in a process that used Swoole coroutines. Never returns.
SwooleShutdownIsolation::terminateWithoutRequestShutdown();
