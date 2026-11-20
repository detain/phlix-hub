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
        // Comment-stripped through the lexer (v0.59 upgrade of the srv M2 lesson): code facts, not prose echoes.
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

            // v0.59-bank upgrade: token scan over the RAW file. The retired
            // regex pipeline (strip /* */ then // textually, then match only
            // `throw new PDOException(...)`) had two named blind spots: fake
            // comment markers inside string literals laundered whole spans
            // past the strip, and re-wording under any other exception class
            // escaped the PDOException-only match entirely. token_get_all()
            // sees comments only as their own token type and the throw-span
            // walk is class-agnostic, so both vectors convict here.
            foreach (self::throwArgumentLiterals((string)file_get_contents($file->getPathname())) as $literal) {
                foreach (self::MACHINE_OWNED_MESSAGES as $message) {
                    self::assertStringNotContainsString(
                        $message,
                        $literal,
                        'the wording ' . json_encode($message) . ' is owned by the shared machine '
                        . '(MysqlTxLockState consts / MysqlRetryVerdict::lostInTransactionSuffix()) — '
                        . 'throwing a re-inlined copy from ' . $file->getPathname() . ' forks the contract'
                    );
                }
            }
        }

        // Measured 2026-11-20: hub src carries several hundred PHP files; the
        // floor catches a silently-broken scan, not corpus size.
        self::assertGreaterThan(200, $scanned, 'the src/ corpus scan must actually run');
    }

    /**
     * Teeth of the v0.59 upgrade, vector 1 (bank trigger row): the wording
     * hidden behind fake comment markers inside string literals — exactly the
     * span the retired regex stripper ate. The old pipeline must STILL look
     * blind to this plant (asserted), while the token scanner convicts.
     */
    public function testTheTokenScannerConvictsWordingSmuggledPastFakeCommentMarkers(): void
    {
        $code = <<<'PHP'
            <?php
            $probe = 'a/*';
            throw new PDOException('There is already an active transaction in progress');
            $tail = '*/';
            PHP;

        // The retired weakness, pinned: regex block-strip eats the throw.
        $regexStripped = preg_replace('!/\*.*?\*/!s', '', $code);
        self::assertIsString($regexStripped);
        self::assertStringNotContainsString(
            'There is already an active transaction',
            $regexStripped,
            'this plant must still exemplify what the regex strip was blind to'
        );

        self::assertSame(
            ['There is already an active transaction in progress'],
            self::throwArgumentLiterals($code),
            'the token scanner must see the smuggled throw argument'
        );
    }

    /**
     * Teeth, vector 2 (bank trigger row): same wording, a different
     * exception class. The retired scan matched `throw new PDOException`
     * only; a re-inline under RuntimeException laundered it.
     */
    public function testTheTokenScannerConvictsWordingUnderAnyExceptionClass(): void
    {
        $code = <<<'PHP'
            <?php
            throw new \RuntimeException('Transaction was lost when the connection dropped; refusing to commit.');
            PHP;

        self::assertDoesNotMatchRegularExpression(
            '/throw\s+new\s+\\\\?PDOException\s*\((.*?)\)\s*;/s',
            $code,
            'this plant must still exemplify what the PDOException-only match was blind to'
        );

        self::assertSame(
            ['Transaction was lost when the connection dropped; refusing to commit.'],
            self::throwArgumentLiterals($code)
        );
    }

    /**
     * Teeth, counter-claim: the upgrade must not convict legitimate strings.
     * URLs carrying //, wording living in a docblock or in a comparison
     * OUTSIDE any throw span, and a clean throw's own message all pass —
     * the scan returns only the honest literal.
     */
    public function testTheTokenScannerDoesNotFalseConvictLegitimateStrings(): void
    {
        $code = <<<'PHP'
            <?php
            /**
             * Docblock prose may quote the machine: "There is already an active
             * transaction" is a description, not a throw.
             */
            $url = 'https://example.com//a/*/b';
            $error = readline('');
            if ($error === 'There is already an active transaction') {
                // comparison read of a machine message is a consumer, not a fork
                $retry = true;
            }
            throw new \RuntimeException('pool exhausted: honest message');
            PHP;

        self::assertSame(
            ['pool exhausted: honest message'],
            self::throwArgumentLiterals($code),
            'only the real throw argument is returned; //, /* and docblock wording are not throw spans'
        );
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

    /**
     * Comment removal through PHP's own lexer (v0.59-bank upgrade of the
     * retired regex stripper): comments are their OWN token types, so text
     * that merely mimics a comment marker inside a string literal can no
     * longer launder the code that follows it. String literals survive
     * verbatim; the membership scans below read code, not prose about code.
     */
    private static function stripComments(string $code): string
    {
        $out = '';
        foreach (token_get_all($code) as $token) {
            if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    /**
     * The string literals appearing inside `throw ... ;` spans of any
     * exception class — the class-agnostic throw scan the v0.59 bank
     * promised. Span = from T_THROW to the first top-level `;`; every quoted
     * literal (and every heredoc literal chunk) inside it is collected and
     * unescaped, so concatenation and multi-line throws cannot dodge it,
     * while anything outside a throw span (comparisons, logs, docblocks) is
     * structurally never a candidate.
     *
     * @return list<string>
     */
    private static function throwArgumentLiterals(string $code): array
    {
        $tokens = token_get_all($code);
        $literals = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_THROW) {
                continue;
            }

            $depth = 0;
            for ($j = $i + 1; $j < $count; $j++) {
                $token = $tokens[$j];
                if (is_array($token)) {
                    if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
                        $literals[] = self::stringLiteralValue($token[1]);
                    } elseif ($token[0] === T_ENCAPSED_AND_WHITESPACE) {
                        $literals[] = $token[1];
                    }
                    continue;
                }

                if ($token === '(' || $token === '[' || $token === '{') {
                    $depth++;
                    continue;
                }
                if ($token === ')' || $token === ']' || $token === '}') {
                    $depth--;
                    continue;
                }
                if ($token === ';' && $depth <= 0) {
                    break;
                }
            }
            $i = $j;
        }

        return $literals;
    }

    /**
     * Literal token text -> the string PHP would hold at runtime (single-
     * and double-quote escapes; heredoc chunks pass through raw).
     */
    private static function stringLiteralValue(string $raw): string
    {
        $first = $raw[0] ?? '';
        if ($first === "'") {
            return str_replace(["\\\\", "\\'"], ['\\', "'"], substr($raw, 1, -1));
        }
        if ($first === '"') {
            return stripcslashes(substr($raw, 1, -1));
        }

        return $raw;
    }
}
