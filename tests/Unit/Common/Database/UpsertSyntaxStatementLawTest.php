<?php

/**
 * Phlix hub component: tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * FX-HUB statement law for the hub upsert corpus (mirrors the server's
 * UpsertSyntaxAdoptionTest discipline).
 *
 * Every `INSERT ... ON DUPLICATE KEY UPDATE` statement in src/ must be
 * either kernel-adopted behind `UpsertSyntax::adapt()` or land as an
 * adjudicated KEEP with a verbatim pin and register evidence. The census
 * is token-level: ODKU text is counted inside string tokens ONLY (both
 * T_CONSTANT_ENCAPSED_STRING and T_ENCAPSED_AND_WHITESPACE — comments and
 * prose are excluded by token type), and adapt() callsites are counted by
 * the UpsertSyntax :: adapt token sequence (comment mentions such as
 * PhlixMySQLConnection's HUB-S14 note cannot inflate it).
 *
 * The arithmetic law adaptCallsites + keepSites === totalStatements is
 * what reddens a smuggled raw upsert: the total moves, the other two
 * pins do not, and the equation fails with the site list printed.
 *
 * KEEP adjudication (1 site): src/Common/RateLimit/DbRateLimiter.php:128
 * — the IF()-increment shape, byte-shape twin of the server site the
 * UPSERT-SRV.md KEEP table adjudicated at src/Common/RateLimit/
 * DbRateLimiter.php:137. Zero VALUES()/new_row refs, so the kernel has
 * nothing to rewrite (adapt would be the identity); its bare store-column
 * legs (`attempts + 1`, the `reset_at <= :...` predicates) are exactly
 * the refs the srv register proved become AMBIGUOUS (ERROR 1052) under
 * `AS new_row`, and the qualified tbl.col alternative renders a MariaDB
 * arm the kernel cannot reproduce byte-identically (its rewrite maps
 * new_row.col -> VALUES(col) and preserves tbl.col untouched — shared
 * UpsertSyntax rewrite()). Non-convertible, KEEP-with-evidence; the same
 * OWNER DUTY row applies (RE-VERIFY-BY 2027-06-04, MySQL 8.x LTS removal
 * of VALUES(): a designed accept-set change, never a mechanical sweep).
 *
 * Converted this wave (1 site): src/Hub/HeartbeatHandler.php server-library
 * hash persist — the old `hash = :hash_update` double-bind became the
 * alias form behind adapt() after a real-MySQL 8.0.46 zero-drift
 * differential: final row state AND raw affectedRows compared by value
 * across the bound / alias / VALUES() arms (fresh insert 1, changed
 * update 2, forced-old-timestamp same-value 2, same-second same-value 0
 * — identical in all three arms; log gates/FXHUB-diff-mysql.log). The
 * converted site is bytes-pinned below so it cannot silently drift.
 */
#[Group('unit')]
final class UpsertSyntaxStatementLawTest extends TestCase
{
    /** Total ON DUPLICATE statements in src/ (token census, string tokens only). */
    private const EXPECTED_TOTAL_STATEMENTS = 18;

    /** src/ callsites of UpsertSyntax::adapt (token-sequence census). */
    private const EXPECTED_ADAPT_CALLSITES = 17;

    /**
     * Verbatim legacy pins for the adjudicated KEEP sites: each raw
     * statement bytes must survive untouched. Flipping a KEEP to the
     * row-alias form (adapted or raw) without a designed accept-set
     * change and both-arm re-proof reddens here.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const KEEP_STATEMENT_SLICES = [
        'src/Common/RateLimit/DbRateLimiter.php:128#1' => [
            'src/Common/RateLimit/DbRateLimiter.php',
            "ON DUPLICATE KEY UPDATE '
            . 'attempts = IF(reset_at <= :nowCheck, 1, attempts + 1), '
            . 'reset_at = IF(reset_at <= :nowReset, :renewReset, reset_at)'",
        ],
    ];

    /**
     * Verbatim pin for the statement converted behind adapt() in this
     * wave (the fidelity arm of the differential above, as authored).
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const CONVERTED_STATEMENT_SLICES = [
        'src/Hub/HeartbeatHandler.php#hash-persist' => [
            'src/Hub/HeartbeatHandler.php',
            "'INSERT INTO server_library_hashes (server_id, hash, updated_at)
                 VALUES (:server_id, :hash, NOW())
                  AS new_row
                 ON DUPLICATE KEY UPDATE hash = new_row.hash, updated_at = NOW()'",
        ],
    ];

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function keepStatementSliceProvider(): array
    {
        return self::KEEP_STATEMENT_SLICES;
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function convertedStatementSliceProvider(): array
    {
        return self::CONVERTED_STATEMENT_SLICES;
    }

    #[DataProvider('keepStatementSliceProvider')]
    public function testTheKeepSitesStillCarryTheirLegacyStatementVerbatim(string $file, string $slice): void
    {
        $path = self::repoRoot() . '/' . $file;
        self::assertFileExists($path);
        self::assertStringContainsString(
            $slice,
            (string) file_get_contents($path),
            "KEEP site {$file} moved off its pinned legacy statement — the wave adjudication "
            . '(FR-SSOT.md / UPSERT-SRV.md KEEP discipline) demands a designed accept-set change '
            . 'with both-arm re-proof, never a silent edit.',
        );
    }

    #[DataProvider('convertedStatementSliceProvider')]
    public function testTheConvertedSiteCarriesItsDifferentialPinnedStatementVerbatim(string $file, string $slice): void
    {
        $path = self::repoRoot() . '/' . $file;
        self::assertFileExists($path);
        self::assertStringContainsString(
            $slice,
            (string) file_get_contents($path),
            "converted site {$file} drifted from the statement bytes the real-MySQL "
            . 'differential verified — a re-differential is owed before any edit.',
        );
    }

    public function testTheCountedUpsertStatementsArePinned(): void
    {
        self::assertSame(
            self::EXPECTED_TOTAL_STATEMENTS,
            self::countStatementsInSrc(),
            'The src/ ON DUPLICATE statement census moved. 18 statements are verified at tip: '
            . '17 kernel-adopted behind adapt(), one adjudicated KEEP (DbRateLimiter '
            . 'IF()-increment, FR-SSOT.md). '
            . 'A new upsert statement must join the corpus with differential evidence or land '
            . 'as a designed KEEP with a verbatim pin — never a silent addition.',
        );
    }

    public function testTheAdaptCallsitesArePinned(): void
    {
        self::assertSame(
            self::EXPECTED_ADAPT_CALLSITES,
            self::countAdaptCallsitesInSrc(),
            'The src/ UpsertSyntax::adapt callsite census moved (token-sequence walk; comment mentions never count). '
            . 'Rotations ride the wave that changes an upsert site.',
        );
    }

    /**
     * The law: every counted statement is kernel-adopted or a registered
     * KEEP. A raw-ODKU smuggle reddens here even if only the total moves.
     */
    public function testTheCorpusArithmeticCloses(): void
    {
        $total = self::countStatementsInSrc();
        $adapt = self::countAdaptCallsitesInSrc();
        $keep = count(self::KEEP_STATEMENT_SLICES);

        self::assertSame(
            $total,
            $adapt + $keep,
            "src/ upsert arithmetic broken: {$total} statements != {$adapt} adapt callsites "
            . "+ {$keep} KEEP sites — an unregistered raw ON DUPLICATE KEY UPDATE statement "
            . 'exists (smuggle guard).',
        );
        self::assertSame(
            self::EXPECTED_TOTAL_STATEMENTS,
            $total,
            'statement count pin drifted independently of the arithmetic — rotate both with the wave evidence',
        );
    }

    public function testTheVerificationTablesAreCounted(): void
    {
        self::assertCount(
            1,
            self::KEEP_STATEMENT_SLICES,
            'the KEEP pin table moved — a KEEP may change only through a designed '
            . 'accept-set change with both-arm re-proof',
        );
        self::assertCount(
            1,
            self::CONVERTED_STATEMENT_SLICES,
            'the converted-site fidelity table moved — rotate only with the wave that changes a converted site',
        );
    }

    /**
     * Anti-vacuity: the walkers must actually walk (a silently emptied
     * src/ or a broken path would pass an equality pin against 0).
     */
    public function testTheCensusWalkersSeeTheCorpus(): void
    {
        self::assertGreaterThan(
            100,
            self::walkedPhpFileCount(),
            'src/ walk saw too few files to be real (measured 238+ at tip).',
        );
        self::assertGreaterThan(0, self::countStatementsInSrc());
        self::assertGreaterThan(0, self::countAdaptCallsitesInSrc());
    }

    // ── census machinery ─────────────────────────────────────────────────────

    /**
     * Count 'ON DUPLICATE' occurrences inside string tokens only, src/.
     */
    private static function countStatementsInSrc(): int
    {
        $count = 0;
        foreach (self::phpFiles() as $path) {
            foreach (token_get_all((string) file_get_contents($path)) as $token) {
                if (!is_array($token)) {
                    continue;
                }
                if ($token[0] !== T_CONSTANT_ENCAPSED_STRING && $token[0] !== T_ENCAPSED_AND_WHITESPACE) {
                    continue;
                }
                $count += substr_count($token[1], 'ON DUPLICATE');
            }
        }

        return $count;
    }

    /**
     * Count `UpsertSyntax::adapt` callsites by token sequence (whitespace
     * and comments skipped), src/.
     */
    private static function countAdaptCallsitesInSrc(): int
    {
        $count = 0;
        foreach (self::phpFiles() as $path) {
            $significant = [];
            foreach (token_get_all((string) file_get_contents($path)) as $token) {
                if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $significant[] = $token;
            }
            for ($i = 2; $i < count($significant); $i++) {
                $third = $significant[$i];
                $second = $significant[$i - 1];
                $first = $significant[$i - 2];
                if (!is_array($third) || !is_array($second) || !is_array($first)) {
                    continue;
                }
                if (
                    $third[0] === T_STRING && $third[1] === 'adapt'
                    && $second[0] === T_DOUBLE_COLON
                    && $first[0] === T_STRING && $first[1] === 'UpsertSyntax'
                ) {
                    $count++;
                }
            }
        }

        return $count;
    }

    private static function walkedPhpFileCount(): int
    {
        return count(self::phpFiles());
    }

    /**
     * @return list<string> every .php file under src/, deterministic order
     */
    private static function phpFiles(): array
    {
        $paths = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::srcDir(), RecursiveDirectoryIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }
        sort($paths);

        return $paths;
    }

    private static function srcDir(): string
    {
        $dir = realpath(__DIR__ . '/../../../../src');
        if ($dir === false) {
            throw new RuntimeException('src/ root not reachable from ' . __DIR__);
        }

        return $dir;
    }

    private static function repoRoot(): string
    {
        $dir = realpath(__DIR__ . '/../../../../');
        if ($dir === false) {
            throw new RuntimeException('repo root not reachable from ' . __DIR__);
        }

        return $dir;
    }
}
