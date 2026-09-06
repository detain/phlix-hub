<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * S299 — behaviour of `scripts/assert-phpcs-corpus.php`, by execution.
 *
 * ## The defect these tests pin shut
 *
 * `phpcs.xml.dist` named `src` and `scripts`. The CI step ran phpcs with an
 * explicit PSR12 standard over `src/` only, which ignores the ruleset file
 * entirely — so **`tests/` had never been linted by either path since the
 * repository was created**. Measured at `origin/master` @ `f4ab19f`:
 * 696 errors and 141 warnings across 69 of 241 files, while the
 * "PHP CodeSniffer (PSR-12)" check was green on every commit.
 *
 * 🔴 **A gate that inspects zero files reads exactly like a gate that passes** —
 * same exit 0, same green tick, usually LESS output. Nothing on the old happy
 * path ever stated a file count, so "0 files in tests/" and "241 clean files in
 * tests/" produced the same green tick. That is why this survived for years.
 *
 * ## What is asserted, and why each half exists
 *
 *  1. **The corpus is declared.** {@see testTheRulesetDeclaresEveryExpectedPath()}
 *     and {@see testTheScriptDeclaresExactlyThisCorpusContract()} keep
 *     `phpcs.xml.dist`, the script's constants and this file's literals in an
 *     exact three-way agreement, so dropping `tests` from any of them is red.
 *  2. **The gate is wired into CI and cannot be neutered.** The workflow is
 *     parsed with its comments stripped — the replacement step's own comment
 *     quotes the defect, and a detector that matches its own documentation is
 *     not a detector.
 *  3. **The gate really fails.** {@see testTheGateFailsOnAPlantedViolationAndNamesTheFile()}
 *     drives the script against a directory containing one deliberately
 *     non-PSR-12 file and requires exit 1 with that file named. Without this the
 *     failure path would be unobserved.
 *  4. **A zero-file corpus is a FAILURE, not a pass.**
 *     {@see testAZeroFileCorpusIsAFailureNotAPass()} is the direct regression
 *     test for the trap: `0 / 0 (100%)` must go red.
 *  5. **A positive control sits beside every negative one.** The same invocation
 *     that goes red prints the real corpus lines, and those are asserted to be
 *     at or above the floors — so a red caused by "phpcs found nothing at all"
 *     cannot be mistaken for a red caused by the planted violation.
 *  6. **No warning-suppression flag in the ruleset.** S333: `-n` (e.g.
 *     `<arg value="np"/>`) was live in `phpcs.xml.dist` and hid 6 warnings
 *     across 5 files under scripts/. Warnings are gate failures per S109, so the
 *     gate now fails on any `<arg value>` that re-introduces `n`, and
 *     {@see testTheGateFailsWhenWarningSuppressionIsReAdded()} drives that
 *     failure red through the test-only `--ruleset=` override.
 *
 * ⚠ `--extra-path` can only ADD a directory to the inspected corpus. It is
 * structurally incapable of making the gate pass something it would otherwise
 * fail, which is why it is safe for a test to drive the production script with it
 * rather than a weakened copy.
 *
 * @package Phlix\Hub\Tests\Unit\Support
 */
final class PhpcsCorpusGateTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../../../scripts/assert-phpcs-corpus.php';

    private const RULESET = __DIR__ . '/../../../phpcs.xml.dist';

    private const WORKFLOW = __DIR__ . '/../../../.github/workflows/ci.yml';

    /**
     * The corpus contract, as a literal. Mirrors `EXPECTED_PATHS` in the script
     * and the `<file>` entries in the ruleset; all three are compared.
     *
     * @var list<string>
     */
    private const EXPECTED_PATHS = ['src', 'scripts', 'tests'];

    /**
     * The floors the script enforces, as literals.
     *
     * @var array<string, int>
     */
    private const EXPECTED_FLOORS = ['src' => 180, 'scripts' => 6, 'tests' => 220];

    private string $workDir = '';

    private static string $cacheFile = '';

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/hub-s299-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($dir, 0o700, true), 'temp dir for the phpcs fixtures');
        $this->workDir = $dir;

        if (self::$cacheFile === '') {
            self::$cacheFile = sys_get_temp_dir() . '/hub-s299-phpcs-cache-' . bin2hex(random_bytes(6)) . '.json';
        }
    }

    protected function tearDown(): void
    {
        if ($this->workDir !== '') {
            foreach ((array) glob($this->workDir . '/*') as $file) {
                if (is_string($file) && is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($this->workDir)) {
                rmdir($this->workDir);
            }
            $this->workDir = '';
        }

        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$cacheFile !== '' && is_file(self::$cacheFile)) {
            unlink(self::$cacheFile);
        }
        self::$cacheFile = '';

        parent::tearDownAfterClass();
    }

    // -----------------------------------------------------------------------
    // 1. The corpus contract: ruleset <-> script <-> this file.
    // -----------------------------------------------------------------------

    public function testTheGateScriptExists(): void
    {
        self::assertFileExists(self::SCRIPT);
    }

    public function testTheRulesetDeclaresEveryExpectedPath(): void
    {
        $xml = simplexml_load_string((string) file_get_contents(self::RULESET));
        self::assertNotFalse($xml, 'phpcs.xml.dist must be parseable XML');

        $declared = array_map(
            static fn (\SimpleXMLElement $e): string => trim((string) $e),
            iterator_to_array($xml->file, false),
        );

        // Non-vacuity: a parser that matched nothing would read as a pass.
        self::assertNotSame([], $declared, 'no <file> entries were parsed out of phpcs.xml.dist');

        self::assertSame(
            self::EXPECTED_PATHS,
            $declared,
            'S299: phpcs.xml.dist must lint src, scripts AND tests. tests/ was unlinted for the whole '
            . 'life of this repository (696 errors / 141 warnings when first measured).',
        );
    }

    /**
     * S333 — the ruleset must not re-introduce phpcs's `-n` (suppress warnings).
     *
     * Parsed with simplexml exactly like the gate script parses it. Non-vacuity
     * first: prove the `<arg>` elements were actually read, then assert that no
     * dash-stripped `value` contains `n`. Warnings are gate failures per S109,
     * and `-n` would hide exactly the 6 warnings S333 fixed under scripts/.
     */
    public function testTheRulesetHasNoWarningSuppressionFlag(): void
    {
        $xml = simplexml_load_string((string) file_get_contents(self::RULESET));
        self::assertNotFalse($xml, 'phpcs.xml.dist must be parseable XML');

        $args = iterator_to_array($xml->arg, false);
        self::assertNotSame([], $args, 'no <arg> elements were parsed out of phpcs.xml.dist');

        foreach ($args as $arg) {
            $value = (string) $arg['value'];
            if ($value === '') {
                continue;
            }
            self::assertStringNotContainsString(
                'n',
                ltrim($value, '-'),
                sprintf(
                    'S333: <arg value="%s"/> would suppress warnings (phpcs `-n`), and warnings are '
                    . 'gate failures per S109. Removing it fixed 6 warnings across 5 scripts/ files.',
                    $value,
                ),
            );
        }
    }

    /**
     * S333 — prove the gate goes red when `-n` comes back.
     *
     * Copies the REAL ruleset text and inserts `<arg value="np"/>`, then drives
     * the gate with the test-only `--ruleset=` override. The copy still names
     * every expected path, so the S299 path check passes and the S333 np-flag
     * check is what fails — BEFORE phpcs even runs. A gate whose failure path
     * cannot be driven red in a test is not a gate.
     */
    public function testTheGateFailsWhenWarningSuppressionIsReAdded(): void
    {
        $ruleset = (string) file_get_contents(self::RULESET);

        // The S333 comment in the real ruleset mentions `<arg value="np"/>` as
        // prose, so parse the elements instead of scanning the raw text: no
        // actual `<arg value>` may already carry the `n` flag.
        $xml = simplexml_load_string($ruleset);
        self::assertNotFalse($xml, 'phpcs.xml.dist must be parseable XML');
        $argValues = array_map(
            static fn (\SimpleXMLElement $e): string => ltrim((string) $e['value'], '-'),
            iterator_to_array($xml->arg, false),
        );
        self::assertNotContains(
            'n',
            $argValues,
            'the real ruleset must not already carry a warning-suppression flag',
        );

        $withNp = str_replace(
            '<arg name="extensions" value="php"/>',
            "<arg name=\"extensions\" value=\"php\"/>\n    <arg value=\"np\"/>",
            $ruleset,
        );
        $rulesetPath = $this->workDir . '/ruleset-with-np.xml';
        file_put_contents($rulesetPath, $withNp);

        $result = $this->runGate(['--ruleset=' . $rulesetPath]);

        self::assertSame(1, $result['exit'], "re-adding np must go RED:\n" . $result['output']);
        self::assertStringContainsString('<arg value="np"/>', $result['output']);
        self::assertStringContainsString('S333', $result['output']);
    }

    /**
     * The script's own constants, read out of its source rather than trusted.
     *
     * Keys AND order are compared so no drift is possible between the contract
     * declared here and the one the gate actually enforces.
     */
    public function testTheScriptDeclaresExactlyThisCorpusContract(): void
    {
        $source = (string) file_get_contents(self::SCRIPT);

        if (preg_match("/const EXPECTED_PATHS = \[(.*?)\];/s", $source, $pathMatch) !== 1) {
            self::fail('EXPECTED_PATHS not found in the gate script');
        }
        preg_match_all("/'([a-z]+)'/", $pathMatch[1], $paths);
        self::assertNotSame([], $paths[1], 'the EXPECTED_PATHS parser matched nothing');
        self::assertSame(self::EXPECTED_PATHS, $paths[1]);

        if (preg_match('/const CORPUS_FLOORS = \[(.*?)\];/s', $source, $floorMatch) !== 1) {
            self::fail('CORPUS_FLOORS not found in the gate script');
        }
        preg_match_all("/'([a-z]+)'\s*=>\s*(\d+)/", $floorMatch[1], $floors, PREG_SET_ORDER);
        self::assertNotSame([], $floors, 'the CORPUS_FLOORS parser matched nothing');

        $parsed = [];
        foreach ($floors as $row) {
            $parsed[$row[1]] = (int) $row[2];
        }
        self::assertSame(self::EXPECTED_FLOORS, $parsed);

        foreach ($parsed as $path => $floor) {
            self::assertGreaterThan(
                0,
                $floor,
                sprintf('a floor of 0 for "%s" would let an empty traversal pass', $path),
            );
        }
    }

    /**
     * The verdict must not come from phpcs's exit code, which lies.
     *
     * Read with comments stripped: the script's docblock explains exactly this,
     * so a naive substring search would match its own documentation.
     */
    public function testTheGateTakesItsVerdictFromTheReportNotTheExitCode(): void
    {
        $source = $this->scriptWithoutComments();

        self::assertStringContainsString('--report=json', $source);
        self::assertStringContainsString("\$report['totals']", $source);
        self::assertGreaterThan(2000, strlen($source), 'comment stripping removed too much to trust this');
    }

    // -----------------------------------------------------------------------
    // 2. Wired into CI, and not neutered.
    // -----------------------------------------------------------------------

    public function testTheWorkflowRunsTheGateScript(): void
    {
        self::assertStringContainsString(
            'php scripts/assert-phpcs-corpus.php',
            $this->workflowWithoutComments(),
            '.github/workflows/ci.yml must invoke the S299 phpcs corpus gate.',
        );
    }

    /**
     * The whole defect in one line: a phpcs invocation scoped to `src/` cannot
     * see tests/, and it is what the check ran for years.
     */
    public function testTheWorkflowNoLongerRunsPhpcsOverSrcAlone(): void
    {
        $yaml = $this->workflowWithoutComments();

        self::assertStringNotContainsString(
            'phpcs --standard=PSR12 --colors src/',
            $yaml,
            'S299: a phpcs run scoped to src/ ignores phpcs.xml.dist and never inspects tests/. '
            . 'Do not restore it.',
        );

        // Non-vacuity: prove the comment stripper did not simply empty the file.
        self::assertStringContainsString('phpcs:', $yaml);
        self::assertGreaterThan(2000, strlen($yaml), 'comment stripping removed too much to trust this');
    }

    public function testThePhpcsStepIsNotNeutered(): void
    {
        $yaml = $this->workflowWithoutComments();
        $start = strpos($yaml, "\n  phpcs:");
        self::assertNotFalse($start, 'the phpcs job was not found in ci.yml');
        $end = strpos($yaml, "\n  phpstan:", $start);
        self::assertNotFalse($end, 'the phpstan job was not found after the phpcs job');

        $job = substr($yaml, $start, $end - $start);

        self::assertStringContainsString('assert-phpcs-corpus.php', $job);
        self::assertStringNotContainsString(
            'continue-on-error',
            $job,
            'A linter gate that cannot fail the build is the defect this replaces.',
        );
    }

    // -----------------------------------------------------------------------
    // 3 + 4 + 5. The gate can fail, and it inspects a non-zero corpus.
    // -----------------------------------------------------------------------

    /**
     * Plant one deliberately non-PSR-12 file, require exit 1 naming it, and in
     * the SAME output require the real corpus lines to be at or above their
     * floors — the succeeding control beside the failing one.
     */
    public function testTheGateFailsOnAPlantedViolationAndNamesTheFile(): void
    {
        // `class  Bad{` — double space, brace on the same line, no strict_types,
        // no trailing newline. Written at runtime, never committed as a .php
        // file, so it can never enter the real corpus.
        file_put_contents(
            $this->workDir . '/PlantedViolation.php',
            "<?php\nclass  Bad{\npublic function x(){return 1;}\n}",
        );

        $result = $this->runGate(['--extra-path=' . $this->workDir]);

        self::assertSame(1, $result['exit'], "the gate must go RED on a planted violation:\n" . $result['output']);
        self::assertStringContainsString('PlantedViolation.php', $result['output']);
        self::assertStringContainsString('S299 phpcs corpus gate FAILED', $result['output']);

        $this->assertRealCorpusIsAboveItsFloors($result['output']);
    }

    /**
     * The trap, stated as a test: an inspected count of zero must be a failure.
     */
    public function testAZeroFileCorpusIsAFailureNotAPass(): void
    {
        $empty = $this->workDir . '/empty';
        self::assertTrue(mkdir($empty, 0o700));

        $result = $this->runGate(['--extra-path=' . $empty]);

        rmdir($empty);

        self::assertSame(1, $result['exit'], "0 files inspected must NOT be a pass:\n" . $result['output']);
        self::assertMatchesRegularExpression('/inspected 0 file\(s\) under/', $result['output']);
        self::assertStringContainsString('below the literal floor of 1', $result['output']);

        $this->assertRealCorpusIsAboveItsFloors($result['output']);
    }

    /**
     * Prove `--extra-path` cannot be the thing that makes the gate red: the same
     * directory, minus the violation, leaves the gate green.
     */
    public function testACleanExtraPathLeavesTheGateGreen(): void
    {
        file_put_contents(
            $this->workDir . '/CleanFixture.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace Phlix\\Hub\\Tests\\Fixture;\n\n"
            . "final class CleanFixture\n{\n    public const OK = true;\n}\n",
        );

        $result = $this->runGate(['--extra-path=' . $this->workDir]);

        self::assertSame(0, $result['exit'], "a clean corpus must PASS:\n" . $result['output']);
        self::assertStringContainsString('S299 phpcs corpus gate OK', $result['output']);

        $this->assertRealCorpusIsAboveItsFloors($result['output']);
    }

    public function testTheGateRejectsAnUnknownArgument(): void
    {
        $result = $this->runGate(['--paths=nothing']);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('unknown argument', $result['output']);
    }

    // -----------------------------------------------------------------------
    // Helpers.
    // -----------------------------------------------------------------------

    /**
     * Assert the printed corpus lines report a real, floored number of files.
     *
     * This is the positive control: without it, a red produced by phpcs
     * inspecting nothing at all would be indistinguishable from a red produced
     * by the planted violation.
     */
    private function assertRealCorpusIsAboveItsFloors(string $output): void
    {
        foreach (self::EXPECTED_FLOORS as $path => $floor) {
            $matched = preg_match(
                '/^\s+' . preg_quote($path, '/') . '\s+(\d+) \/ (\d+) \((\d+)%\) files inspected/m',
                $output,
                $m,
            );
            self::assertSame(
                1,
                $matched,
                sprintf("the gate printed no corpus line for \"%s\":\n%s", $path, $output),
            );
            self::assertGreaterThanOrEqual(
                $floor,
                (int) $m[1],
                sprintf('"%s" inspected %s file(s), below its floor of %d', $path, $m[1], $floor),
            );
            self::assertSame(
                (int) $m[1],
                (int) $m[2],
                sprintf('"%s" has files on disk that phpcs did not inspect', $path),
            );
            self::assertSame(100, (int) $m[3], sprintf('"%s" was not 100%% inspected', $path));
        }
    }

    /**
     * @param list<string> $args
     *
     * @return array{exit: int, output: string}
     */
    private function runGate(array $args): array
    {
        $command = array_merge(
            [PHP_BINARY, self::SCRIPT, '--cache=' . self::$cacheFile],
            $args,
        );

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            implode(' ', array_map('escapeshellarg', $command)),
            $descriptors,
            $pipes,
            dirname(self::SCRIPT, 2),
        );
        self::assertIsResource($process, 'could not start the gate script');

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return ['exit' => $exit, 'output' => $stdout . $stderr];
    }

    private function workflowWithoutComments(): string
    {
        return $this->withoutHashComments((string) file_get_contents(self::WORKFLOW));
    }

    private function scriptWithoutComments(): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents(self::SCRIPT)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    private function withoutHashComments(string $yaml): string
    {
        $kept = [];
        foreach (explode("\n", $yaml) as $line) {
            $stripped = preg_replace('/(?:^|\s)#.*$/', '', $line);
            $kept[] = is_string($stripped) ? $stripped : $line;
        }

        return implode("\n", $kept);
    }
}
