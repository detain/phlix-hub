<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * S316 — a missing or empty `coverage.xml` must fail CI, and must keep failing.
 *
 * ## The defect, measured 2026-08-10
 *
 * Three innocuous facts composed into a silent fail-open:
 *
 *  1. The PHPUnit step in `.github/workflows/ci.yml` passed NO `--coverage-*`
 *     flag. `coverage.xml` was produced **solely** by the
 *     `<coverage><report><clover/>` block in `phpunit.xml`.
 *  2. Both uploaders swallow failure — `fail_ci_if_error: false` on Codecov,
 *     `continue-on-error: true` on Codacy — and both are RIGHT to. Flipping
 *     either makes unrelated PRs depend on a third party's availability.
 *  3. There was no guard on the artifact itself.
 *
 * ⇒ Deleting one line of XML stopped all coverage reporting and the entire
 * pipeline stayed green. Measured on this tree: with the `<clover>` line removed
 * and no CLI flag, `coverage.xml` is simply ABSENT and PHPUnit says nothing.
 *
 * ## What each assertion here defends
 *
 * | mutation                                                    | this test |
 * | ------------------------------------------------------------ | --------- |
 * | delete the guard step from ci.yml                             | RED       |
 * | give the guard step `continue-on-error`                       | RED       |
 * | wrap the guard in an `if:` that can be false                  | RED       |
 * | move the guard AFTER the upload steps                         | RED       |
 * | drop `--coverage-clover` from the PHPUnit command             | RED       |
 * | point the guard at a path the run does not write              | RED       |
 * | delete `scripts/assert-coverage-report.php`                   | RED       |
 * | make the script `exit 0` on a missing / empty / broken report  | RED       |
 * | lower a floor in the script without changing it here          | RED       |
 * | flip either uploader into a blocking gate                     | RED       |
 *
 * The last row runs in the opposite direction to all the others, and is
 * deliberate: this step's whole point is that the guard is LOCAL. A future
 * author "strengthening" CI by making the Codecov upload blocking would undo
 * that, so the test states the intent where the edit would happen.
 *
 * ⚠ Every workflow assertion runs against the job text with comment-only lines
 * stripped, so a commented-out step cannot satisfy a check that it exists — and
 * so that this file's own prose, quoted into a workflow comment, cannot either.
 *
 * @package Phlix\Hub\Tests\Unit\Support
 */
final class CoverageArtifactGateTest extends TestCase
{
    private const REPO_ROOT = __DIR__ . '/../../..';

    private const WORKFLOW = self::REPO_ROOT . '/.github/workflows/ci.yml';

    private const SCRIPT = self::REPO_ROOT . '/scripts/assert-coverage-report.php';

    /**
     * The artifact path, written in one place so the two halves of the wiring —
     * the flag that writes it and the argument that checks it — are compared
     * against a constant rather than against each other's spelling.
     */
    private const ARTIFACT = 'coverage.xml';

    /**
     * The floors, duplicated from `scripts/assert-coverage-report.php` ON
     * PURPOSE, exactly as {@see RequiredPhpExtensionsTest} duplicates its
     * extension contract. {@see testTheScriptDeclaresExactlyTheseFloors} pins the
     * two copies together so a floor cannot be quietly lowered in the script
     * alone — which is what neutering this gate would look like.
     *
     * ⚠ These are literals on both sides. Neither is read out of a coverage
     * report: a threshold derived from the file it is checking self-adjusts and
     * can never fail.
     *
     * @var array<string, int|float>
     */
    private const FLOORS = [
        'MIN_STATEMENT_COVERAGE' => 70.0,
        'MIN_STATEMENTS' => 12000,
        'MIN_FILES' => 150,
    ];

    // -----------------------------------------------------------------------
    // Workflow parsing (the local idiom: by indentation, comments stripped)
    // -----------------------------------------------------------------------

    /**
     * The `phpunit:` job block of ci.yml with comment-only lines removed.
     */
    private function phpunitJob(): string
    {
        self::assertFileExists(self::WORKFLOW, 'the CI workflow must exist');

        $lines = preg_split('/\R/', (string) file_get_contents(self::WORKFLOW)) ?: [];
        $block = [];
        $inJob = false;

        foreach ($lines as $line) {
            if (preg_match('/^  phpunit:\s*$/', $line) === 1) {
                $inJob = true;
                continue;
            }

            if ($inJob && preg_match('/^  \S/', $line) === 1) {
                break;
            }

            if (!$inJob || preg_match('/^\s*#/', $line) === 1) {
                continue;
            }

            $block[] = $line;
        }

        self::assertNotSame([], $block, 'ci.yml must still define a `phpunit:` job');

        return implode("\n", $block);
    }

    /**
     * Every step of the phpunit job, in file order, comments already stripped.
     *
     * @return list<string>
     */
    private function phpunitSteps(): array
    {
        $steps = preg_split('/^(?=      - name:)/m', $this->phpunitJob()) ?: [];
        $steps = array_values(array_filter(
            $steps,
            static fn (string $step): bool => str_starts_with(ltrim($step, "\n"), '      - name:'),
        ));

        // A splitter that matched nothing would let every "the step is not
        // neutered" assertion below pass vacuously.
        self::assertGreaterThan(
            5,
            count($steps),
            'the step splitter found almost no steps, so a clean result proves nothing about them',
        );

        return $steps;
    }

    /**
     * The index of the single step whose body contains $needle.
     */
    private function stepIndexContaining(string $needle): int
    {
        $steps = $this->phpunitSteps();
        $found = [];

        foreach ($steps as $index => $step) {
            if (str_contains($step, $needle)) {
                $found[] = $index;
            }
        }

        self::assertCount(
            1,
            $found,
            sprintf('exactly one step of the phpunit job must contain "%s"', $needle),
        );

        return $found[0];
    }

    // -----------------------------------------------------------------------
    // 1. The guard is wired, and cannot be made advisory
    // -----------------------------------------------------------------------

    public function testThePhpunitJobRunsTheCoverageArtifactGate(): void
    {
        self::assertFileExists(
            self::SCRIPT,
            'scripts/assert-coverage-report.php must exist for the workflow step to run it',
        );

        self::assertStringContainsString(
            'scripts/assert-coverage-report.php',
            $this->phpunitJob(),
            'The phpunit job must run scripts/assert-coverage-report.php. It is the only thing that '
            . 'turns a vanished coverage.xml from a green tick into a red build: the PHPUnit step '
            . 'exits 0 without writing a report, and both uploaders below are non-failing by design '
            . '(S316).',
        );
    }

    public function testTheGuardStepCannotBeNeuteredByContinueOnError(): void
    {
        $step = $this->phpunitSteps()[$this->stepIndexContaining('scripts/assert-coverage-report.php')];

        self::assertStringNotContainsString(
            'continue-on-error',
            $step,
            'the S316 coverage-artifact gate must not carry `continue-on-error` — that makes a FAILED '
            . 'gate report success, which is the exact shape of defect it exists to remove',
        );
    }

    /**
     * `if [ -f coverage.xml ]; then <check>; fi` is the precise anti-pattern this
     * gate replaces — an absent artifact becomes a no-op that exits 0. The
     * workflow-level spelling of the same mistake is an `if:` on the step, so the
     * only condition permitted is `always()`, which cannot evaluate false.
     */
    public function testTheGuardStepIsNotConditionalOnAnythingThatCanBeFalse(): void
    {
        $step = $this->phpunitSteps()[$this->stepIndexContaining('scripts/assert-coverage-report.php')];

        preg_match_all('/^\s+if:\s*(.+)$/m', $step, $matches);

        foreach ($matches[1] as $condition) {
            self::assertSame(
                'always()',
                trim($condition),
                'the S316 coverage-artifact gate may only be conditioned on `always()`. Any other '
                . '`if:` lets a missing report skip its own guard, which is the workflow-level form '
                . 'of wrapping the check in `if [ -f coverage.xml ]`.',
            );
        }
    }

    /**
     * Ordering is load-bearing: a report that failed its floors must never reach
     * Codecov, where a collapsed number would be recorded as this commit's real
     * coverage and become the base every later PR is compared against.
     */
    public function testTheGuardRunsBeforeBothUploadSteps(): void
    {
        $guard = $this->stepIndexContaining('scripts/assert-coverage-report.php');

        foreach (['codecov/codecov-action', 'codacy/codacy-coverage-reporter-action'] as $uploader) {
            self::assertLessThan(
                $this->stepIndexContaining($uploader),
                $guard,
                'the S316 coverage-artifact gate must run BEFORE the ' . $uploader . ' step, so a '
                . 'report that measured nothing is never published as if it were real',
            );
        }
    }

    // -----------------------------------------------------------------------
    // 2. The artifact the guard checks is the artifact the run writes
    // -----------------------------------------------------------------------

    public function testThePhpunitCommandNamesTheCoverageArtifactExplicitly(): void
    {
        self::assertMatchesRegularExpression(
            '/vendor\/bin\/phpunit[^\n]*--coverage-clover\s+' . preg_quote(self::ARTIFACT, '/') . '\b/',
            $this->phpunitJob(),
            'The PHPUnit step must pass `--coverage-clover ' . self::ARTIFACT . '`. Without it the '
            . 'artifact exists only because of the <clover/> line in phpunit.xml, and tidying that '
            . 'one line stops all coverage reporting (S316). Measured on this tree: PHPUnit emits '
            . 'Clover exactly once with both present, and the flag overrides — never duplicates — '
            . 'the XML target.',
        );
    }

    /**
     * The two halves could each be present and still not meet: a flag writing
     * `build/coverage.xml` with a guard reading `coverage.xml` would fail in CI
     * for a reason nobody could read off the diff.
     */
    public function testTheGuardChecksTheSameFileThePhpunitStepWrites(): void
    {
        $job = $this->phpunitJob();

        preg_match('/--coverage-clover\s+(\S+)/', $job, $written);
        preg_match('/scripts\/assert-coverage-report\.php\s+(\S+)/', $job, $checked);

        self::assertSame(
            self::ARTIFACT,
            $written[1] ?? null,
            'the PHPUnit step must write the coverage report to ' . self::ARTIFACT,
        );

        self::assertSame(
            self::ARTIFACT,
            $checked[1] ?? null,
            'the guard step must be pointed at the same path the PHPUnit step writes, and both must '
            . 'be the path the upload steps read',
        );

        foreach (['codecov/codecov-action', 'codacy/codacy-coverage-reporter-action'] as $uploader) {
            self::assertStringContainsString(
                './' . self::ARTIFACT,
                $this->phpunitSteps()[$this->stepIndexContaining($uploader)],
                'the ' . $uploader . ' step must upload the file the guard just verified',
            );
        }
    }

    /**
     * The counterweight. This gate is on the LOCAL artifact precisely so that CI
     * never depends on a third party being reachable; S309 saw a mid-job network
     * fetch turn an unrelated PR red. Making either uploader blocking would trade
     * this gate's honesty for someone else's uptime.
     */
    public function testNeitherUploaderIsAllowedToBecomeABlockingGate(): void
    {
        $codecov = $this->phpunitSteps()[$this->stepIndexContaining('codecov/codecov-action')];
        $codacy = $this->phpunitSteps()[$this->stepIndexContaining('codacy/codacy-coverage-reporter-action')];

        self::assertMatchesRegularExpression(
            '/fail_ci_if_error:\s*false/',
            $codecov,
            'the Codecov upload must keep `fail_ci_if_error: false`. The coverage guard belongs on '
            . 'the local artifact (the step above), not on a third party\'s availability — a gate '
            . 'that reds when Codecov is down is the gate that gets deleted (S316).',
        );

        self::assertMatchesRegularExpression(
            '/continue-on-error:\s*true/',
            $codacy,
            'the Codacy upload must keep `continue-on-error: true`, for the same reason',
        );
    }

    // -----------------------------------------------------------------------
    // 3. The script is CAPABLE of failing — every arm, driven for real
    // -----------------------------------------------------------------------

    /**
     * Run the gate against $path and return `[exitCode, combined output]`.
     *
     * @return array{0: int, 1: string}
     */
    private function runGate(string $path): array
    {
        $output = [];
        $exit = 0;

        exec(
            sprintf('%s %s %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg(self::SCRIPT), escapeshellarg($path)),
            $output,
            $exit,
        );

        return [$exit, implode("\n", $output)];
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/phlix-hub-s316-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($dir, 0o700, true), 'the fixture directory must be creatable');

        return $dir;
    }

    /**
     * A syntactically real Clover report with the project metrics dialled to
     * whatever the case under test needs.
     */
    private function cloverFixture(string $dir, int $files, int $statements, int $covered): string
    {
        $path = $dir . '/clover.xml';

        $xml = sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<coverage generated="1786419144">' . "\n"
            . '  <project timestamp="1786419144">' . "\n"
            . '    <metrics files="%d" loc="55163" ncloc="31288" classes="183" methods="1427" '
            . 'coveredmethods="964" conditionals="0" coveredconditionals="0" statements="%d" '
            . 'coveredstatements="%d" elements="16920" coveredelements="13531"/>' . "\n"
            . '  </project>' . "\n"
            . '</coverage>' . "\n",
            $files,
            $statements,
            $covered,
        );

        self::assertNotFalse(file_put_contents($path, $xml));

        return $path;
    }

    private function removeDir(string $dir): void
    {
        foreach ((glob($dir . '/*') ?: []) as $entry) {
            unlink($entry);
        }

        rmdir($dir);
    }

    /**
     * The positive control, and it comes FIRST on purpose: without a case that
     * passes, every red below is equally satisfied by a script that always exits
     * 1, and "the gate fails" would prove nothing about the gate.
     *
     * It also pins the printed magnitudes. A gate that inspected zero files reads
     * exactly like a clean pass unless it states its denominator, so the byte
     * count, the file count and the statement count are asserted to be in the
     * output, not merely computed inside it.
     */
    public function testTheGateAcceptsAHealthyReportAndPrintsItsMagnitudes(): void
    {
        $dir = $this->tempDir();

        try {
            $path = $this->cloverFixture($dir, 198, 15468, 12567);
            [$exit, $text] = $this->runGate($path);

            self::assertSame(0, $exit, 'a healthy report must pass: ' . $text);
            self::assertStringContainsString('198 files', $text, 'the gate must state how many files it saw');
            self::assertStringContainsString('15468 statements', $text, 'the gate must state its denominator');
            self::assertStringContainsString('12567 covered', $text);
            self::assertStringContainsString('81.25%', $text, 'the gate must state the percentage it computed');
            self::assertStringContainsString(
                number_format((float) filesize($path)) . ' bytes',
                $text,
                'the gate must state the byte size it inspected',
            );
            self::assertStringContainsString('S316 coverage gate OK', $text);
        } finally {
            $this->removeDir($dir);
        }
    }

    /**
     * Arm 1 of the two the acceptance criteria name: the report is ABSENT.
     */
    public function testTheGateFailsWhenTheReportIsMissing(): void
    {
        $dir = $this->tempDir();

        try {
            $path = $dir . '/' . self::ARTIFACT;
            self::assertFileDoesNotExist($path);

            [$exit, $text] = $this->runGate($path);

            self::assertSame(1, $exit, 'a missing report must exit 1: ' . $text);
            self::assertStringContainsString('::error::', $text, 'the failure must be annotated for GitHub');
            self::assertStringContainsString(
                $path,
                $text,
                'the failure must NAME the artifact that is missing — "something went wrong" is not a '
                . 'gate anyone can act on',
            );
            self::assertStringContainsString('NOT produced', $text);
        } finally {
            $this->removeDir($dir);
        }
    }

    /**
     * Arm 2: the report EXISTS and is zero bytes. Distinct from arm 1 because
     * `is_file()` is true here — a guard written only as an existence check
     * passes this and uploads a coverage collapse as if it were real.
     */
    public function testTheGateFailsWhenTheReportIsEmpty(): void
    {
        $dir = $this->tempDir();

        try {
            $path = $dir . '/' . self::ARTIFACT;
            self::assertNotFalse(file_put_contents($path, ''));
            self::assertSame(0, filesize($path));

            [$exit, $text] = $this->runGate($path);

            self::assertSame(1, $exit, 'a 0-byte report must exit 1: ' . $text);
            self::assertStringContainsString('EMPTY (0 bytes)', $text);
            self::assertStringContainsString($path, $text, 'the failure must name the artifact');
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testTheGateFailsWhenTheReportIsNotParseableXml(): void
    {
        $dir = $this->tempDir();

        try {
            $path = $dir . '/' . self::ARTIFACT;
            self::assertNotFalse(file_put_contents($path, "<coverage><project>\n"));

            [$exit, $text] = $this->runGate($path);

            self::assertSame(1, $exit, 'a truncated report must exit 1: ' . $text);
            self::assertStringContainsString('not parseable XML', $text);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testTheGateFailsWhenTheProjectMetricsAreAbsent(): void
    {
        $dir = $this->tempDir();

        try {
            $path = $dir . '/' . self::ARTIFACT;
            self::assertNotFalse(file_put_contents(
                $path,
                "<?xml version=\"1.0\"?>\n<coverage><project timestamp=\"1\"/></coverage>\n",
            ));

            [$exit, $text] = $this->runGate($path);

            self::assertSame(1, $exit, 'a report with no project metrics must exit 1: ' . $text);
            self::assertStringContainsString('has no files at', $text);
        } finally {
            $this->removeDir($dir);
        }
    }

    /**
     * `statements="0"` is a BROKEN report, not an empty one, and this is the
     * exact line the sibling repo's old gate got backwards: it read zero as
     * "nothing to check" and rewarded it with exit 0.
     */
    public function testTheGateFailsWhenTheReportMeasuredZeroStatements(): void
    {
        $dir = $this->tempDir();

        try {
            [$exit, $text] = $this->runGate($this->cloverFixture($dir, 198, 0, 0));

            self::assertSame(1, $exit, 'a zero-statement report must exit 1: ' . $text);
            self::assertStringContainsString('measured NOTHING', $text);
        } finally {
            $this->removeDir($dir);
        }
    }

    /**
     * The denominator collapsing is the failure mode a percentage alone cannot
     * see: a report restricted to a handful of files can show a HIGHER
     * percentage than a healthy run while measuring almost none of the code.
     */
    public function testTheGateFailsWhenTheMeasuredCorpusCollapses(): void
    {
        $dir = $this->tempDir();

        try {
            [$exit, $text] = $this->runGate($this->cloverFixture($dir, 4, 300, 300));

            self::assertSame(1, $exit, 'a collapsed corpus must exit 1 even at 100%: ' . $text);
            self::assertStringContainsString('only 4 file(s)', $text);
        } finally {
            $this->removeDir($dir);
        }

        $dir = $this->tempDir();

        try {
            [$exit, $text] = $this->runGate($this->cloverFixture($dir, 198, 900, 900));

            self::assertSame(1, $exit, 'a collapsed statement count must exit 1 even at 100%: ' . $text);
            self::assertStringContainsString('only 900 statements', $text);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testTheGateFailsWhenNothingWasCovered(): void
    {
        $dir = $this->tempDir();

        try {
            [$exit, $text] = $this->runGate($this->cloverFixture($dir, 198, 15468, 0));

            self::assertSame(1, $exit, 'a report covering nothing must exit 1: ' . $text);
            self::assertStringContainsString('0 of 15468 statements', $text);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testTheGateFailsBelowTheCoverageFloor(): void
    {
        $dir = $this->tempDir();

        try {
            // 69.99% — one hundredth of a point under the floor, so the boundary
            // itself is what is being tested, not a landslide.
            [$exit, $text] = $this->runGate($this->cloverFixture($dir, 198, 20000, 13998));

            self::assertSame(1, $exit, 'a report below the floor must exit 1: ' . $text);
            self::assertStringContainsString('69.99%', $text);
            self::assertStringContainsString('below the floor of 70.00%', $text);
        } finally {
            $this->removeDir($dir);
        }

        $dir = $this->tempDir();

        try {
            // …and 70.00% exactly is accepted, so the comparison is not off by one
            // in the direction that would red every honest run.
            [$exit, $text] = $this->runGate($this->cloverFixture($dir, 198, 20000, 14000));

            self::assertSame(0, $exit, 'exactly at the floor must pass: ' . $text);
            self::assertStringContainsString('70.00%', $text);
        } finally {
            $this->removeDir($dir);
        }
    }

    // -----------------------------------------------------------------------
    // 4. The floors cannot be lowered in the script alone
    // -----------------------------------------------------------------------

    public function testTheScriptDeclaresExactlyTheseFloors(): void
    {
        self::assertFileExists(self::SCRIPT);
        $source = (string) file_get_contents(self::SCRIPT);

        preg_match_all('/^const (MIN_[A-Z_]+) = ([0-9.]+);$/m', $source, $matches, PREG_SET_ORDER);

        self::assertNotSame(
            [],
            $matches,
            'the constant parser matched NOTHING in ' . self::SCRIPT . ' — a parser that matches '
            . 'nothing reads exactly like a pass, so it is asserted non-empty before it is compared',
        );

        $declared = [];
        foreach ($matches as $match) {
            $declared[$match[1]] = str_contains($match[2], '.') ? (float) $match[2] : (int) $match[2];
        }

        self::assertSame(
            self::FLOORS,
            $declared,
            'scripts/assert-coverage-report.php and self::FLOORS here must declare the same floors, '
            . 'in the same order. Lowering one of them in the script alone is what neutering this '
            . 'gate looks like, and it would otherwise be invisible.',
        );
    }

    /**
     * The floors must sit BELOW the measured baseline with headroom, and the
     * baseline is written down rather than read from a report. A floor pinned to
     * the current figure reds on ordinary work and gets deleted; a floor read out
     * of the report it checks can never fail at all.
     */
    public function testTheCoverageFloorKeepsHeadroomUnderTheMeasuredBaseline(): void
    {
        // Measured at d0be6b2 for S316: 81.25% locally (PCOV, full suite, real
        // MySQL) and 81.87% on the runner as reported by codecov/project.
        $measuredBaseline = 81.25;
        $floor = self::FLOORS['MIN_STATEMENT_COVERAGE'];

        self::assertLessThan(
            $measuredBaseline - 5.0,
            $floor,
            'the coverage floor must keep at least 5 points of headroom under the measured baseline: '
            . 'CI collects with Xdebug and that baseline was measured with PCOV, and ordinary work '
            . 'that deletes well-tested code moves the number down',
        );

        self::assertGreaterThan(
            50.0,
            $floor,
            'a floor this low would no longer detect a coverage collapse, which is the only thing it '
            . 'is for',
        );
    }
}
