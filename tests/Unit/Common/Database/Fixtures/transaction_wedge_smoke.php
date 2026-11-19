<?php

/**
 * Process-isolated smoke for TXMUTEX-A A3 (Q-C fold) — a falsy `beginTrans()`
 * result must NOT leave the hub claiming a transaction it never started, and
 * must hand the transaction-scoped mutex back instead of stranding it.
 *
 * Run by `PhlixMySQLConnectionTest::testAFailedBeginTransNeverClaimsTheTransactionLock()`
 * in a CHILD php process (same S179 isolation rationale and SIGKILL
 * terminator as {@see transaction_lock_smoke.php}).
 *
 * ## What it proves
 *
 * The vendor `beginTrans()` answers `false` without throwing (a `PDO` handle
 * whose `beginTransaction()` declines). Pre-cure, hub recorded the outcome
 * unconditionally — `inTransaction = true` (the LIE that makes every later
 * lock decision act as if this coroutine owns a transaction) and
 * `txOpen = false` — and never returned the mutex token, so the next
 * `beginTrans()` would park on a lock nobody holds. The cure mirrors the
 * server's `beginTrans()`: the lock is only kept when the parent actually
 * began the transaction; a falsy answer releases it.
 *
 * Markers on success (post-cure behavior):
 *   begin:false, inTx:0, txOpen:0, holder:free, token:back
 *
 * Pre-cure the child prints `inTx:1` (the claim), `holder:self` and
 * `token:stranded` — a clean, arm-attributable divergence.
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

    // A handle that declines the transaction without throwing anything.
    $declining = new class ('sqlite::memory:') extends \PDO {
        public function beginTransaction(): bool
        {
            return false;
        }
    };

    $property = (new ReflectionClass(\Phlix\Hub\Common\Database\PhlixMySQLConnection::class))->getProperty('pdo');
    $property->setAccessible(true);
    $property->setValue($conn, $declining);

    $cid = \Swoole\Coroutine::getCid();

    $began = $conn->beginTrans();
    $markers[] = 'begin:' . var_export($began, true);
    $markers[] = 'inTx:' . ($read($conn, 'inTransaction') ? '1' : '0');
    $markers[] = 'txOpen:' . ($read($conn, 'txOpen') ? '1' : '0');
    $markers[] = 'holder:' . ($read($conn, 'queryLockHolder') === -1 ? 'free' : 'self');

    // Was the mutex token handed back? Pop it (short timeout — never blocks
    // the fixture) and put it straight back so the state stays consistent.
    $lock = $read($conn, 'queryLock');
    if (!$lock instanceof \Swoole\Coroutine\Channel) {
        $markers[] = 'token:never-minted';

        return;
    }

    $token = $lock->pop(0.05);
    $markers[] = 'token:' . ($token === false ? 'stranded' : 'back');
    if ($token !== false) {
        $lock->push($token);
    }

    unset($cid);
});

echo implode("\n", $markers) . "\n";

// S179: flush the markers, then die by SIGKILL so PHP's request shutdown never
// runs in a process that used Swoole coroutines. Never returns.
SwooleShutdownIsolation::terminateWithoutRequestShutdown();
