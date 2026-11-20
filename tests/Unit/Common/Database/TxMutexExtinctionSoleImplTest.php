<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\Database;

use Phlix\Hub\Common\Database\PhlixMySQLConnection;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * TXMUTEX-B retrofit sole-impl tripwires for the hub connection.
 *
 * The transaction-mutex mechanics retired onto the shared machine
 * `Phlix\Shared\Database\MysqlTxLockState` / `TxEnd` / `MysqlRetryPolicy`
 * (design D-02: one implementation estate-wide). The hub keeps its genuinely
 * local shapes: the per-query latch `$queryLockHolder`, the `txOpen` liveness
 * flag the execute() arm consults, the single Channel + unchecked per-query
 * pop (F1 law), and the refusal-path release (register row F-1 — the hub's
 * landed `finally` releases even a refused commit, unlike the server).
 *
 * Own-coverage law: the test asserts its own canonical path exists, so a
 * rename or deletion reddens the suite from inside the suite. The server
 * carries a sibling of this file because each repo guards its own wiring.
 */
final class TxMutexExtinctionSoleImplTest extends TestCase
{
    private const CONNECTION_FILE = __DIR__ . '/../../../../src/Common/Database/PhlixMySQLConnection.php';

    /** Retired members of the hub connection (state moved into the machine). */
    private const RETIRED_MEMBERS = [
        '$this->inTransaction',
        '$this->txPoisoned',
        'endTransaction(',
    ];

    /** Phrases the shared machine owns — no src/ THROW may carry them inline. */
    private const MACHINE_OWNED_MESSAGES = [
        'There is already an active transaction',
        'Could not acquire the transaction mutex: the coroutine was cancelled while waiting',
        'Transaction was lost when the connection dropped; refusing to commit.',
        '(connection lost inside a transaction; not retried)',
    ];

    public function testTheTripwireItselfIsPresentAtItsCanonicalPath(): void
    {
        self::assertFileExists(
            __DIR__ . '/TxMutexExtinctionSoleImplTest.php',
            'the TXMUTEX-B sole-impl tripwire must live at tests/Unit/Common/Database/'
        );
    }

    public function testRetiredTransactionLedgerMembersAreGoneFromTheConnection(): void
    {
        $code = self::connectionCodeWithoutComments();

        foreach (self::RETIRED_MEMBERS as $member) {
            self::assertStringNotContainsString(
                $member,
                $code,
                "TXMUTEX-B retired '$member' — the ledger lives in MysqlTxLockState; "
                . 're-adding a local copy forks the state machine.'
            );
        }

        foreach (['inTransaction', 'txPoisoned'] as $name) {
            self::assertStringNotContainsString(
                'private bool $' . $name,
                $code,
                "the $name property declaration must stay retired"
            );
        }
    }

    public function testTheConnectionDelegatesThroughTheSharedMachineApi(): void
    {
        // Comment-stripped (srv M2 lesson): code facts, not prose echoes.
        $code = self::connectionCodeWithoutComments();

        foreach (
            [
                'use Phlix\Shared\Database\MysqlTxLockState;',
                'use Phlix\Shared\Database\TxEnd;',
                'use Phlix\Shared\Database\MysqlRetryPolicy;',
                '->claimCommit(',
                'claimRollback(',
                '->acquire(',
                '->markOpen(',
                '->abandonIfHeld(',
                '->clearPoison(',
                '->markPoisoned()',
                'clearPoison: false',
            ] as $needle
        ) {
            self::assertStringContainsString(
                $needle,
                $code,
                "the retrofit wiring '$needle' must stay present"
            );
        }
    }

    /**
     * A-LAW anti-drift pin (register): the hub's guards read the LOCAL
     * $queryLockHolder latch for the reentrant/stranger decisions — the
     * machine holder is the transaction-scoped identity, and the two agree
     * only while the consumer keeps maintaining the shadow. If a future
     * edit swaps these to machine reads, per-query reentrancy breaks.
     */
    public function testTheConsumerStillReadsTheLocalLatchWhereTheLandedGuardsDid(): void
    {
        $code = self::connectionCodeWithoutComments();

        // Per-form floors (mutation HM5 caught the sum version too generous:
        // acquireQueryLock's own reentrant read padded it). commitTrans +
        // rollBackTrans stranger guards = two `!== $cid` latch reads; beginTrans
        // reentry + acquireQueryLock = two `=== $cid` reads. Swapping any of
        // these to machine-holder reads redds this pin.
        self::assertGreaterThanOrEqual(
            2,
            substr_count($code, '$this->queryLockHolder !== $cid'),
            'commit/rollBack stranger guards must keep the landed local-latch reads'
        );
        self::assertGreaterThanOrEqual(
            2,
            substr_count($code, '$this->queryLockHolder === $cid'),
            'beginTrans reentry (and the per-query latch) must keep local-latch reads'
        );
    }

    public function testMachineOwnedMessageWordingsAreNeverReInlinedInThrowStatements(): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__ . '/../../../../src', RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $scanned = 0;
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $scanned++;
            $code = self::stripComments((string)file_get_contents($file->getPathname()));
            if ($code === '') {
                continue;
            }

            preg_match_all('/throw\s+new\s+\\\\?PDOException\s*\((.*?)\)\s*;/s', $code, $throws);

            foreach ($throws[1] as $argumentList) {
                foreach (self::MACHINE_OWNED_MESSAGES as $message) {
                    self::assertStringNotContainsString(
                        $message,
                        $argumentList,
                        'the wording ' . json_encode($message) . ' is owned by the shared machine '
                        . '(MysqlTxLockState consts / MysqlRetryVerdict::lostInTransactionSuffix()) — '
                        . 'throwing a re-inlined copy in ' . $file->getPath() . ' forks the contract'
                    );
                }
            }
        }

        // Measured 2026-11-20: hub src carries several hundred PHP files; the
        // floor catches a silently-broken scan, not corpus size.
        self::assertGreaterThan(200, $scanned, 'the src/ corpus scan must actually run');
    }

    /**
     * Reflection-level extinction of the retired property DECLARATIONS
     * (the string scans above could be laundered by a renamed read).
     */
    public function testTheRetiredPropertiesAreNotDeclaredOnTheClass(): void
    {
        $declared = [];
        foreach ((new ReflectionClass(PhlixMySQLConnection::class))->getProperties() as $property) {
            $declared[] = $property->getName();
        }

        self::assertSame(
            [],
            array_values(array_intersect(['inTransaction', 'txPoisoned'], $declared)),
            'the retired bool fields must never come back (the machine owns them)'
        );
        self::assertContains('txState', $declared, 'the machine ledger property stays declared');
        self::assertContains('queryLockHolder', $declared, 'the local latch stays (kept-arms law)');
        self::assertContains('txOpen', $declared, 'the hub liveness flag stays local (register D-4)');
    }

    private static function connectionCodeWithoutComments(): string
    {
        $code = file_get_contents(self::CONNECTION_FILE);
        self::assertIsString($code);

        return self::stripComments($code);
    }

    private static function stripComments(string $code): string
    {
        $out = preg_replace('!/\*.*?\*/!s', '', $code);
        self::assertIsString($out);
        $out = preg_replace('!//[^\n]*!', '', $out);
        self::assertIsString($out);

        return $out;
    }
}
