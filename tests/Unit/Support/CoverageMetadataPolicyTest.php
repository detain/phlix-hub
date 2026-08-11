<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Support;

use DOMDocument;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function bin2hex;
use function count;
use function file;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function implode;
use function is_string;
use function ltrim;
use function mkdir;
use function preg_match;
use function random_bytes;
use function realpath;
use function rmdir;
use function sort;
use function sprintf;
use function str_replace;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function unlink;

use const FILE_IGNORE_NEW_LINES;

/**
 * S311 — the coverage-metadata policy for this repository, enforced.
 *
 * ## The defect
 *
 * PHPUnit narrows a test's RECORDED coverage to whatever its coverage metadata
 * names, and throws the rest away without a word. The authoritative reading of
 * this repo's numbers lives in `phpunit.xml`; the short version is that until
 * S311 a `0.00%` here was ambiguous between "never executed" and "executed but
 * attributed elsewhere", and that ambiguity had already cost two pieces of real
 * work and produced one wrongly-filed step.
 *
 * Measured on this tree (PHP 8.3.6 + PCOV 1.0.11, real MySQL 8.0.46, a fixed
 * execution order, the same tree twice with only the 233 metadata lines removed
 * on the second pass; both runs 4160 tests / 29290 assertions / 0 skipped):
 * statements went 79.42% to 81.25%, files at 0.00% went 14 to 10, and NOT ONE
 * FILE LOST coverage. No line was newly executed; the whole delta is
 * attribution.
 *
 * The mechanism is in `php-code-coverage`'s
 * {@see \SebastianBergmann\CodeCoverage\CodeCoverage} `applyCoversAndUsesFilter()`:
 * `false` (from a covers-nothing marker) CLEARS the whole run's data for that
 * test, a non-empty list keeps only the named units' listed lines and DELETES
 * every other file, and `[]` (no metadata at all) keeps everything. None of the
 * three warns.
 *
 * ## The policy
 *
 * No coverage metadata in `tests/`, in any spelling, with no allow-list.
 *
 * ## Why the needles are assembled instead of written out
 *
 * A detector whose own prose matches its rule reports itself, and this estate
 * has had that happen for real more than once. So every needle below is
 * concatenated at runtime and {@see testTheGuardsOwnSourceCannotSatisfyItsOwnRule}
 * proves this file contains none of them literally — which is also why the
 * paragraphs above say "metadata" and "marker" rather than naming the tags.
 *
 * That is not merely tidiness here. Measured on this repo while S311 was being
 * written: a prose mention with WHITESPACE after the tag is parsed as a REAL
 * entry with an invalid target and discards that test's entire contribution
 * (probe: 12/13 covered statements dropped to 0/13), reporting only a PHPUnit
 * *runner* warning that `failOnWarning="true"` does not fail on. The same
 * mention closed immediately by a backtick is inert. One character apart, so the
 * rule is: describe the tag, never spell it.
 *
 * @package Phlix\Hub\Tests\Unit\Support
 */
final class CoverageMetadataPolicyTest extends TestCase
{
    private const REPO = __DIR__ . '/../../..';

    private const PHPUNIT_XML = self::REPO . '/phpunit.xml';

    /**
     * Anti-vacuity floor for the `tests/` walk.
     *
     * The tree holds 248 PHP files at S311. 200 is a floor, not a target: a
     * walk that found almost nothing must read as "nothing was inspected", not
     * as a pass. This is the same trap S299 documented for phpcs.
     */
    private const MINIMUM_FILES_SCANNED = 200;

    /**
     * Every spelling PHPUnit 10.5 accepts, assembled so this file is not its own
     * hit.
     *
     * The doc-comment tags come from `Metadata/Parser/AnnotationParser.php`
     * (`forClass()` and `forMethod()`); the attributes from
     * `PHPUnit\Framework\Attributes\*`. The uses family is deliberately in the
     * list too: `linesToBeUsed()` only ever WIDENS an existing narrowing, so a
     * uses marker with no covers marker is inert — but it is a strong signal
     * that someone is reaching for the mechanism this policy retired.
     *
     * ⚠ The third pattern allows a leading backslash and the fully-qualified
     * `PHPUnit\Framework\Attributes` prefix. That is not padding: a legal,
     * working spelling the scan walked past would let it report a confident
     * zero. {@see testTheScannerActuallyDetectsEverySpelling} builds that case
     * explicitly rather than trusting the short form to stand for it.
     *
     * @return list<non-empty-string> PCRE patterns
     */
    private function needles(): array
    {
        $at = '@';

        return [
            '/' . $at . 'covers(?:Nothing|DefaultClass)?\b/',
            '/' . $at . 'uses(?:DefaultClass)?\b/',
            '/#\[\s*\\\\?(?:PHPUnit\\\\Framework\\\\Attributes\\\\)?(?:Covers|Uses)'
                . '(?:Class|Method|Function|Nothing)?\b/',
        ];
    }

    /**
     * @return list<string> repo-relative paths of every PHP file under tests/
     */
    private function testTreeFiles(): array
    {
        $root = realpath(self::REPO);
        self::assertIsString($root);

        $found = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/tests', FilesystemIterator::SKIP_DOTS),
        );

        foreach ($it as $entry) {
            if (!$entry instanceof SplFileInfo || $entry->getExtension() !== 'php') {
                continue;
            }

            $found[] = ltrim(str_replace($root, '', $entry->getPathname()), '/');
        }

        sort($found);

        return $found;
    }

    /**
     * @param list<non-empty-string> $needles
     *
     * @return list<string> "path:line" for each hit
     */
    private function scan(string $relPath, array $needles): array
    {
        $abs = realpath(self::REPO) . '/' . $relPath;
        $lines = file($abs, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines, $relPath . ' could not be read');

        $hits = [];
        foreach ($lines as $i => $line) {
            foreach ($needles as $needle) {
                if (preg_match($needle, $line) === 1) {
                    $hits[] = $relPath . ':' . ($i + 1);

                    break;
                }
            }
        }

        return $hits;
    }

    public function testNoTestFileCarriesCoverageMetadata(): void
    {
        $needles = $this->needles();
        $offenders = [];
        $scanned = 0;

        foreach ($this->testTreeFiles() as $rel) {
            $scanned++;
            foreach ($this->scan($rel, $needles) as $hit) {
                $offenders[] = $hit;
            }
        }

        self::assertGreaterThan(
            self::MINIMUM_FILES_SCANNED,
            $scanned,
            'the tests/ walk scanned almost nothing, so a clean result proves nothing',
        );

        self::assertSame(
            [],
            $offenders,
            "S311: coverage metadata is not permitted in tests/ — it silently\n"
            . "DISCARDS every other file the test executes, which put four executed\n"
            . "files at 0.00% and understated this repo's statement coverage by\n"
            . "1.83 points. Read the policy in phpunit.xml. Delete the marker; do not\n"
            . "add an allow-list to this test. If the hit is a docblock SENTENCE that\n"
            . "merely names the tag, reword it — PHPUnit parses it out of the prose.\n"
            . "Offending lines:\n  "
            . implode("\n  ", $offenders),
        );
    }

    /**
     * The two settings that would make a MISSING marker a test failure.
     *
     * Assert the DEFAULT as well as the absence: reasoning from "the attribute
     * is absent, therefore the behaviour is off" is only sound while the default
     * is false, and this estate has been burned by a setting that defaulted the
     * other way. `phpunit.xsd:179,203` declare `default="false"` for both, and
     * `TextUI/Configuration/Xml/Loader.php:795,803` initialise both to `false`
     * — including the legacy aliases, which the same loader still honours
     * (`Loader.php:799,807`).
     */
    public function testPhpunitXmlDoesNotRequireCoverageMetadata(): void
    {
        $doc = new DOMDocument();
        self::assertTrue($doc->load(self::PHPUNIT_XML), 'phpunit.xml must parse');

        $root = $doc->documentElement;
        self::assertNotNull($root);

        foreach (
            [
                'requireCoverageMetadata',
                'forceCoversAnnotation',
                'beStrictAboutCoverageMetadata',
                'beStrictAboutCoversAnnotation',
            ] as $attribute
        ) {
            self::assertFalse(
                $root->hasAttribute($attribute),
                sprintf(
                    'phpunit.xml must not set %s: it turns "this test names no unit" into '
                    . 'a risky-test failure, which is direct pressure to re-add the '
                    . 'metadata S311 removed. Both settings default to false '
                    . '(phpunit.xsd:179,203).',
                    $attribute,
                ),
            );
        }

        $xsd = file_get_contents(self::REPO . '/vendor/phpunit/phpunit/phpunit.xsd');
        self::assertIsString($xsd);
        self::assertStringContainsString(
            '<xs:attribute name="requireCoverageMetadata" type="xs:boolean" default="false"/>',
            $xsd,
            'the installed PHPUnit no longer defaults requireCoverageMetadata to false, '
            . 'so absence is no longer proof it is off — re-derive this assertion',
        );
    }

    /**
     * The config half is not the whole gate: the same strictness has a CLI form.
     *
     * `phpunit --strict-coverage` sets `beStrictAboutCoverageMetadata` for the
     * run regardless of what the XML says, so a workflow could reintroduce the
     * pressure this policy removes without touching a file the test above reads.
     *
     * ⚠ Updated by S316. This docblock used to say the hub's CI passes no
     * coverage flag at all; it now passes `--coverage-clover coverage.xml`,
     * because an artifact that existed only by virtue of one line of XML could
     * vanish with the whole pipeline still green. The assertion here is
     * unchanged and is narrower than that sentence ever implied: no workflow may
     * pass the STRICTNESS flag. Report-destination flags are fine.
     */
    public function testNoWorkflowPassesTheStrictCoverageFlag(): void
    {
        $flag = '-' . '-strict-coverage';
        $workflows = glob(self::REPO . '/.github/workflows/*.yml');
        self::assertIsArray($workflows);
        self::assertGreaterThan(
            1,
            count($workflows),
            'no workflow files were read, so a clean result proves nothing',
        );

        foreach ($workflows as $workflow) {
            $yaml = file_get_contents($workflow);
            self::assertIsString($yaml);
            self::assertStringNotContainsString(
                $flag,
                $yaml,
                'a CI invocation must not turn coverage-metadata strictness back on '
                . 'from the command line: it makes every unmarked test risky and '
                . 'failOnRisky is true in phpunit.xml',
            );
        }
    }

    /**
     * The authoritative reading lives in exactly one place, and must stay there.
     *
     * Without this, the policy comment is a comment: deletable in a drive-by
     * edit, with nothing to notice. The fragments are the load-bearing claims —
     * the heading, the sentence that makes a zero mean something, the ban, and
     * the name of this test.
     */
    public function testPhpunitXmlStillStatesTheAuthoritativeReading(): void
    {
        $xml = file_get_contents(self::PHPUNIT_XML);
        self::assertIsString($xml);

        foreach (
            [
                "S311 — HOW TO READ THIS REPO'S COVERAGE NUMBERS",
                'A 0.00% file is a file the suite NEVER EXECUTES',
                'No coverage metadata in tests/, in any spelling',
                'CoverageMetadataPolicyTest',
            ] as $fragment
        ) {
            self::assertStringContainsString(
                $fragment,
                $xml,
                'phpunit.xml is the ONE authoritative statement of how to read this '
                . 'repo\'s coverage numbers (S311). Do not delete it; if the policy '
                . 'changes, rewrite it and update this assertion in the same commit.',
            );
        }
    }

    public function testTheGuardsOwnSourceCannotSatisfyItsOwnRule(): void
    {
        $self = 'tests/' . str_replace(
            '\\',
            '/',
            substr(self::class, strlen('Phlix\\Hub\\Tests\\')),
        ) . '.php';

        self::assertSame(
            [],
            $this->scan($self, $this->needles()),
            'this guard must not contain its own needles literally, or it reports itself '
            . 'and the next author "fixes" the guard instead of the code',
        );
    }

    /**
     * Positive control: the scanner must be able to FIND metadata, or every green
     * result above is green because the detector is broken.
     */
    public function testTheScannerActuallyDetectsEverySpelling(): void
    {
        $dir = sys_get_temp_dir() . '/s311-scan-' . bin2hex(random_bytes(6));
        mkdir($dir . '/tests', 0o700, true);

        $at = '@';
        $samples = [
            'doc-covers' => ' * ' . $at . 'covers \\Phlix\\Hub\\Http\\Router',
            'doc-covers-method' => ' * ' . $at . 'covers \\Phlix\\Hub\\X::y',
            'doc-covers-nothing' => ' * ' . $at . 'coversNothing',
            'doc-covers-default' => ' * ' . $at . 'coversDefaultClass \\Phlix\\Hub\\X',
            'doc-uses' => ' * ' . $at . 'uses \\Phlix\\Hub\\X',
            'doc-covers-in-prose' => ' * claiming ' . $at . 'covers here would discard it',
            'attr-covers-class' => '#[' . 'CoversClass(Router::class)]',
            'attr-covers-fn' => '#[' . 'CoversFunction(\'strlen\')]',
            'attr-covers-none' => '#[' . 'CoversNothing]',
            'attr-uses-class' => '#[' . 'UsesClass(Router::class)]',
            'attr-fqcn' => '#[' . '\\PHPUnit\\Framework\\Attributes\\CoversClass(X::class)]',
        ];

        $needles = $this->needles();
        $missed = [];
        $falsePositives = [];

        try {
            foreach ($samples as $label => $line) {
                $file = $dir . '/tests/Probe.php';
                file_put_contents($file, "<?php\n/**\n" . $line . "\n */\nclass Probe {}\n");

                $hit = false;
                foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $ln) {
                    foreach ($needles as $needle) {
                        if (preg_match($needle, $ln) === 1) {
                            $hit = true;

                            break 2;
                        }
                    }
                }

                if (!$hit) {
                    $missed[] = $label;
                }
            }

            // Negative control: prose that merely TALKS about the mechanism, and
            // a plain test file, must not match — a rule that fires on its own
            // documentation gets deleted rather than obeyed.
            $clean = $dir . '/tests/Clean.php';
            file_put_contents(
                $clean,
                "<?php\n/**\n * This test deliberately names no unit; see the S311 policy.\n"
                . " * Coverage metadata is banned in this repository.\n */\n"
                . "class Clean { public function testX(): void {} }\n",
            );
            foreach (file($clean, FILE_IGNORE_NEW_LINES) ?: [] as $ln) {
                foreach ($needles as $needle) {
                    if (preg_match($needle, $ln) === 1) {
                        $falsePositives[] = $ln;
                    }
                }
            }
        } finally {
            foreach ((array) glob($dir . '/tests/*') as $f) {
                if (is_string($f)) {
                    unlink($f);
                }
            }
            rmdir($dir . '/tests');
            rmdir($dir);
        }

        self::assertSame([], $missed, 'the scanner cannot see these spellings');
        self::assertSame([], $falsePositives, 'the scanner fires on prose about itself');
    }
}
