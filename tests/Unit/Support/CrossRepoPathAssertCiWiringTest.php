<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

use function escapeshellarg;
use function exec;
use function file_get_contents;
use function implode;
use function preg_match;
use function preg_quote;
use function preg_split;
use function sprintf;
use function str_contains;

/**
 * S411 — the cross-repo path assertion must keep having an automated home.
 *
 * ## Why a test reads a workflow file
 *
 * S204 was a production 404 that every gate in BOTH repositories missed, and
 * the fix shipped two halves: four unconditional dispatch tests pinning the
 * hub side, and `scripts/assert-cross-repo-hub-paths.php` — the half that can
 * see phlix-server MOVE. S411 measured the missing third half: NOTHING ran the
 * script. A gate that only fires when a human remembers to invoke it is a gate
 * that does not fire, and the audit found no CI hook anywhere in the estate.
 *
 * So the deliverable is the `cross-repo-paths:` job in `.github/workflows/ci.yml`
 * — and this test is the guard's guard, because the job set of ci.yml is
 * otherwise unpinned: a dummy tenth job added and then quietly deleted passes
 * every pre-existing parser (measured). Each assertion corresponds to a
 * mutation that would put the silent gap back:
 *
 * | mutation                                            | this test |
 * | --------------------------------------------------- | --------- |
 * | delete (or comment out) the `cross-repo-paths:` job  | RED       |
 * | drop the phlix-server fetch (URL or clone step)      | RED       |
 * | stop executing the assertion script in the job       | RED       |
 * | add `continue-on-error:` anywhere in the job         | RED       |
 * | give the job a `needs:` chain                        | RED       |
 * | delete or break the script the job points at         | RED       |
 *
 * The `needs:` and `continue-on-error` rows carry the same weight here as in
 * S173/S329: a job skipped by a failed dependency, or a step whose failure is
 * demoted to a warning, reports SUCCESS without comparing a single byte —
 * exactly the "green that proved nothing" shape this whole family of tests
 * exists to remove. The gate must run unconditionally (see the doctrine
 * comment on the mcp-e2e job).
 *
 * ⚠ Scope. This pins that the wiring is PRESENT. It cannot defend against an
 * author who rewrites the assertion script's logic, and it deliberately does
 * not try — a rule that fights its own maintainer produces churn and gets
 * deleted.
 *
 * @package Phlix\Hub\Tests\Unit\Support
 */
final class CrossRepoPathAssertCiWiringTest extends TestCase
{
    private const WORKFLOW = __DIR__ . '/../../../.github/workflows/ci.yml';

    private const SCRIPT = __DIR__ . '/../../../scripts/assert-cross-repo-hub-paths.php';

    /**
     * The `cross-repo-paths:` job block of the workflow, with comment-only
     * lines removed. Extracted by indentation exactly like
     * {@see IntegrationDbCiWiringTest::phpunitJob()}: jobs sit at two spaces,
     * so the block runs from `  cross-repo-paths:` to the next line that
     * starts a sibling key at that depth (or the end of the file).
     *
     * Comment stripping is load-bearing, not cosmetic: this class's own prose
     * and the job's doctrine comment both MENTION the fetch URL and the script
     * path, and a commented-out wiring must never satisfy a check that the
     * wiring is live.
     */
    private function crossRepoJob(): string
    {
        self::assertFileExists(self::WORKFLOW, 'the CI workflow must exist');

        $lines = preg_split('/\R/', (string) file_get_contents(self::WORKFLOW)) ?: [];
        $block = [];
        $inJob = false;

        foreach ($lines as $line) {
            if (preg_match('/^  cross-repo-paths:\s*$/', $line) === 1) {
                $inJob = true;
                continue;
            }

            if ($inJob && preg_match('/^  \S/', $line) === 1) {
                break;
            }

            if (!$inJob) {
                continue;
            }

            // Comment-only lines are not configuration.
            if (preg_match('/^\s*#/', $line) === 1) {
                continue;
            }

            $block[] = $line;
        }

        self::assertNotSame(
            [],
            $block,
            'the workflow must still define a `cross-repo-paths:` job — S411 gave the S204 '
            . 'cross-repo path assertion its only automated home, and a deleted job is the exact '
            . 'silent-return state this test exists to catch',
        );

        return implode("\n", $block);
    }

    /**
     * (a) The job must fetch the phlix-server source it compares against.
     *
     * The URL is the whole claim: the repo is public, so the anonymous HTTPS
     * clone is what makes the check possible from a hub-side job with no
     * second checkout and no token. Losing it means the script runs against a
     * missing sibling and — by its own design — exits 1, but a deleted clone
     * step is still the mutation this pins.
     */
    public function testTheJobFetchesThePhlixServerSource(): void
    {
        $job = $this->crossRepoJob();

        self::assertStringContainsString(
            'git clone',
            $job,
            'the cross-repo-paths job must clone phlix-server — its checkout of phlix-hub alone '
            . 'contains only one side of the S204 contract',
        );

        self::assertStringContainsString(
            'https://github.com/detain/phlix-server.git',
            $job,
            'the fetch must target detain/phlix-server over anonymous HTTPS (phlix-server is a '
            . 'public repo; ci.yml already reads it anonymously via ls-remote in '
            . 'snapshot-currency) — no token, no other remote',
        );
    }

    /**
     * (b) The job must actually execute the assertion script. Without this the
     * fetch measures nothing and the green tick is decorative.
     */
    public function testTheJobExecutesTheCrossRepoAssertionScript(): void
    {
        self::assertStringContainsString(
            'php scripts/assert-cross-repo-hub-paths.php',
            $this->crossRepoJob(),
            'the cross-repo-paths job must execute scripts/assert-cross-repo-hub-paths.php — the '
            . 'script is the S204 measurement, and S411 exists because nothing ran it',
        );
    }

    /**
     * (c) `continue-on-error` turns a FAILED comparison into a green tick —
     * the precise defect class (S173/S258/S299) this job belongs to. Scoped to
     * this job block (comments stripped), so the legitimate Codacy uploader in
     * the phpunit job is untouched.
     */
    public function testTheJobCannotBeNeuteredByContinueOnError(): void
    {
        self::assertStringNotContainsString(
            'continue-on-error',
            $this->crossRepoJob(),
            'the cross-repo-paths job must carry no `continue-on-error` — a gate whose failure is '
            . 'demoted to a warning reports success without having protected anything',
        );
    }

    /**
     * (d) A skipped job counts as SUCCESS to the checks API and with no branch
     * protection anywhere in this estate, a `needs:` chain would let a merge
     * decision be taken on "nothing failed" while the cross-repo gate never
     * ran. Same doctrine as the mcp-e2e job.
     */
    public function testTheJobIsNotBehindANeedsChain(): void
    {
        self::assertStringNotContainsString(
            'needs:',
            $this->crossRepoJob(),
            'the cross-repo-paths job must have no `needs:` — GitHub skips dependents of failed '
            . 'jobs, a skipped job reads as SUCCESS, and the S204 gate would silently stop running '
            . 'on exactly the PRs where the other gates went red'
            . "\n" . '  (that is when drift is most likely and the gate is most needed)',
        );
    }

    /**
     * (e) The script the job points at must exist and parse — a deleted or
     * syntactically broken target turns the CI step red only at run time,
     * hours after the merge that broke it; this catches it in the suite of the
     * PR that does. `php -l` is the lightest real parser available (the hub has
     * no YAML/PHP-parse library in its test environment and exec-based linting
     * is the precedent in RequiredPhpExtensionsTest).
     */
    public function testTheAssertionScriptExistsAndParses(): void
    {
        self::assertFileExists(
            self::SCRIPT,
            'scripts/assert-cross-repo-hub-paths.php must exist — the cross-repo-paths CI job '
            . 'executes exactly this file',
        );

        $output = [];
        $exit = 0;
        exec(
            sprintf('%s -l %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg(self::SCRIPT)),
            $output,
            $exit
        );

        self::assertSame(
            0,
            $exit,
            "scripts/assert-cross-repo-hub-paths.php does not parse:\n" . implode("\n", $output)
        );

        // Non-vacuity: `php -l` exiting 0 on an unreadable path would be the
        // self-adjusting failure mode, so the success line is required too.
        self::assertStringContainsString(
            'No syntax errors detected',
            implode("\n", $output),
            'the lint must have actually inspected the script',
        );
    }

    /**
     * (b2) The executed command must point the script at the fetched sibling,
     * with the SAME relative location the clone step creates. A clone to
     * ../phlix-server plus a script run against a different path leaves the
     * comparison reading a directory that cannot exist — pin the join, not
     * just the two ends.
     */
    public function testTheScriptIsInvokedAgainstTheFetchedSiblingPath(): void
    {
        $job = $this->crossRepoJob();

        $steps = preg_split('/^(?=      - name:)/m', $job) ?: [];
        $running = array_values(array_filter(
            $steps,
            static fn (string $step): bool => str_contains($step, 'php scripts/assert-cross-repo-hub-paths.php'),
        ));

        self::assertCount(
            1,
            $running,
            'exactly one step of the cross-repo-paths job may execute the assertion script'
        );

        self::assertMatchesRegularExpression(
            '/assert-cross-repo-hub-paths\.php\s+\.\.\/phlix-server\b/',
            $running[0],
            'the script invocation must name the fetched sibling checkout explicitly '
            . '(../phlix-server) so the intent is readable in the workflow itself — '
            . '"make intent readable" is part of the S411 spec'
        );
    }
}
