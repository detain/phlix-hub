<?php

/**
 * Phlix hub component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Docs;

use Phlix\Hub\Tests\Support\Narrow;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_diff;
use function array_unique;
use function array_values;
use function count;
use function ctype_digit;
use function file_get_contents;
use function implode;
use function in_array;
use function preg_match_all;
use function preg_replace;
use function sort;
use function sprintf;
use function strlen;
use function strpos;
use function strtolower;
use function substr;

/**
 * The README's "the N tables the migrations create" claim is a tripwire, not prose.
 *
 * ## Why this guard exists
 *
 * The Database-schema section of README.md advertises the complete inventory of
 * tables the SQL migrations create, as a hand-maintained count. That count has
 * drifted silently three times in this repo's history (a 31-era string in the
 * CHANGELOG, a 35-era claim cured to 37 by the docs pass in commit 401d96d6,
 * each refresh manual). The file-count side of the same family is already
 * guarded by MigrationFileTest, which pins the enumerated migration list; the
 * table-count side of the README claim had no executable counterpart, so every
 * new migration quietly made the docs wrong. This guard closes that window:
 * the claim is parsed from README.md and compared against the tables computed
 * from migrations/*.sql on disk. Adding a migration that creates a table
 * without updating the README reddens the Unit suite with both lists printed.
 *
 * ## Count-up discipline (why no number appears in this file's body)
 *
 * The expected count is never restated here. It is READ from the README claim
 * and COMPUTED from disk every run, so shipping a new migration means updating
 * exactly one README row (the claim sentence + its inventory table), and the
 * README can never again disagree with disk while this suite stays green.
 *
 * ## Counting rules (mirrored from the shipped code, deliberately)
 *
 * - Migration discovery: every `migrations/*.sql` file in lexicographic order —
 *   identical to MigrationRunner::discoverFiles() and to the provider in
 *   tests/Unit/Migrations/MigrationFileTest.php.
 * - Comment normalization: whole-line `-- ...` comments are stripped before the
 *   CREATE TABLE scan, the same way migration 041 normalized checksums against
 *   the runner (README documents "whole-line comments are stripped before
 *   hashing"). Without this, prose like "-- CREATE TABLE IF NOT EXISTS so the
 *   runner..." inside comments would mint phantom tables.
 * - The `migrations` tracking table is the runner's own, created at bootstrap,
 *   and the README scopes it OUT of the advertised count ("plus the `migrations`
 *   tracking table the runner creates for itself") — so it is excluded from the
 *   inventory rows here and asserted ABSENT from them.
 * - README grammar contract: the claim sentence
 *   "the <N> tables the migrations create" must occur exactly once, and the
 *   inventory rows are the table lines "| `name` | ..." between that sentence
 *   and the next `## ` heading.
 *
 * ## What each mutation reddens
 *
 * - README count left stale by a new migration: claimed-vs-disk and
 *   claimed-vs-inventory arms go red; names still match.
 * - A migration added without README inventory rows: disk-vs-inventory red too.
 * - An inventory row deleted or a ghost row added: inventory count and
 *   inventory-names arms go red; the disk claim is untouched.
 * - The claim sentence removed, reworded, or duplicated: every arm fails loudly
 *   with the grammar quoted, rather than passing on a parse that found nothing.
 */
final class ReadmeMigrationCountTripwireTest extends TestCase
{
    private const README_PATH = __DIR__ . '/../../../README.md';
    private const MIGRATIONS_DIR = __DIR__ . '/../../../migrations';

    /** The advertised count, tolerating the sentence wrapping across lines. */
    private const CLAIM_PATTERN = '/the (\d+) tables the migrations\s+create/';

    /** CREATE TABLE names, as the migrations themselves spell them. */
    private const CREATE_TABLE_PATTERN = '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z0-9_]+)`?/i';

    /** Inventory rows: markdown table lines opening with a backticked name. */
    private const INVENTORY_ROW_PATTERN = '/^\| `([a-z0-9_]+)` \|/m';

    /** Whole-line SQL comments, stripped before scanning, per the 041-era convention. */
    private const LINE_COMMENT_PATTERN = '/^\s*--.*$/m';

    /** The runner's own tracking table, scoped out of the advertised count. */
    private const TRACKING_TABLE = 'migrations';

    public function testClaimedTableCountMatchesTablesCreatedOnDisk(): void
    {
        $claimed = $this->claimedTableCount();
        $created = $this->diskCreatedTables();

        self::assertSame(
            count($created),
            $claimed,
            sprintf(
                'README.md claims %d tables but the migrations create %d. Update the '
                . '"tables the migrations create" claim, or add the missing rows; disk set: %s',
                $claimed,
                count($created),
                implode(', ', $created),
            ),
        );
    }

    public function testClaimedTableCountMatchesInventoryRowsBelowTheClaim(): void
    {
        $claimed = $this->claimedTableCount();
        $rows = $this->readmeInventoryTables();

        self::assertSame(
            count($rows),
            $claimed,
            sprintf(
                'README.md claims %d tables but lists %d inventory rows below the claim. '
                . 'The sentence and the table must move together.',
                $claimed,
                count($rows),
            ),
        );
    }

    public function testInventoryRowsBelowTheClaimAreExactlyTheDiskTables(): void
    {
        $rows = $this->readmeInventoryTables();
        $created = $this->diskCreatedTables();

        $missingFromReadme = array_values(array_diff($created, $rows));
        $notOnDisk = array_values(array_diff($rows, $created));

        self::assertSame(
            $created,
            $rows,
            sprintf(
                'README inventory and migrations/*.sql diverge. Advertised but never created: %s. '
                . 'Created but not advertised: %s.',
                $notOnDisk === [] ? '(none)' : implode(', ', $notOnDisk),
                $missingFromReadme === [] ? '(none)' : implode(', ', $missingFromReadme),
            ),
        );
    }

    public function testTrackingTableIsScopedOutOfTheAdvertisedInventory(): void
    {
        self::assertNotContains(
            self::TRACKING_TABLE,
            $this->readmeInventoryTables(),
            'The README scopes the `migrations` tracking table out of the advertised '
            . 'count ("plus the migrations tracking table"); it must not appear as an inventory row.',
        );
    }

    /**
     * The number the README advertises, parsed from the live claim sentence.
     *
     * @throws RuntimeException when the claim grammar is absent, ambiguous, or duplicated
     */
    private function claimedTableCount(): int
    {
        $readme = $this->readme();

        $found = preg_match_all(self::CLAIM_PATTERN, $readme, $matches, PREG_SET_ORDER);
        if ($found !== 1) {
            throw new RuntimeException(sprintf(
                'Expected the README claim grammar %s to occur exactly once, found %d. '
                . 'If the sentence was deliberately reworded, update this tripwire\'s grammar in the same commit.',
                self::CLAIM_PATTERN,
                $found === false ? 0 : $found,
            ));
        }

        $claim = Narrow::castString($matches[0][1]);
        if ($claim === '' || !ctype_digit($claim) || (int) $claim <= 0) {
            throw new RuntimeException('README claim parsed but carries no positive count: ' . $claim);
        }

        return (int) $claim;
    }

    /**
     * Every table the migrations create, computed from disk, sorted and deduplicated.
     *
     * @return list<string>
     */
    private function diskCreatedTables(): array
    {
        $tables = [];
        foreach (Narrow::globFiles(self::MIGRATIONS_DIR . '/*.sql') as $file) {
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException('Migration file unreadable: ' . $file);
            }

            $stripped = preg_replace(self::LINE_COMMENT_PATTERN, '', $sql);
            if ($stripped === null) {
                throw new RuntimeException('Comment normalization failed for: ' . $file);
            }

            if (preg_match_all(self::CREATE_TABLE_PATTERN, $stripped, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $tables[] = strtolower(Narrow::castString($match[1]));
                }
            }
        }

        if ($tables === []) {
            throw new RuntimeException('No CREATE TABLE found in any migration — the scan or the dir is broken.');
        }

        $tables = array_values(array_unique($tables));
        sort($tables);

        return $tables;
    }

    /**
     * The inventory rows the README advertises below the claim, tracking table excluded.
     *
     * @return list<string>
     */
    private function readmeInventoryTables(): array
    {
        $readme = $this->readme();

        // The claim sentence itself must bound the section from above; locate its end.
        $claimOffset = strpos($readme, 'tables the migrations');
        if ($claimOffset === false) {
            throw new RuntimeException('README claim sentence not found; cannot bound the inventory section.');
        }

        $sectionStart = $claimOffset + strlen('tables the migrations');
        $nextHeading = strpos($readme, "\n## ", $sectionStart);
        $sectionEnd = $nextHeading === false ? strlen($readme) : $nextHeading;
        $section = substr($readme, $sectionStart, $sectionEnd - $sectionStart);

        $rows = [];
        if (preg_match_all(self::INVENTORY_ROW_PATTERN, $section, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $name = strtolower(Narrow::castString($match[1]));
                if (in_array($name, [self::TRACKING_TABLE], true)) {
                    continue;
                }
                $rows[] = $name;
            }
        }

        if ($rows === []) {
            throw new RuntimeException('No inventory rows matched below the README claim; grammar or layout changed.');
        }

        $rows = array_values(array_unique($rows));
        sort($rows);

        return $rows;
    }

    private function readme(): string
    {
        $readme = file_get_contents(self::README_PATH);
        if ($readme === false) {
            throw new RuntimeException('README.md unreadable at ' . self::README_PATH);
        }

        return $readme;
    }
}
