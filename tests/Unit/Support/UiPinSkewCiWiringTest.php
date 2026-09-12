<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

use function escapeshellarg;
use function exec;
use function file_get_contents;
use function implode;
use function preg_match;
use function preg_split;
use function sprintf;
use function str_contains;

/**
 * S181 — the pin-skew reporter must keep having an automated home, and that
 * home must keep being on a CLOCK.
 *
 * ## Why a test reads a workflow file
 *
 * The measured history is the argument: `phlix-windows-client` sat 17 minors
 * behind `phlix-ui` with every gate in the estate green, and the skew widened
 * inside a single day (S181 block, re-measured 2026-09-12: all four pins now
 * current at v0.99.1 — which is exactly why the guard below tests WIRING and
 * {@see UiPinSkewReporterTest} tests RED-ABILITY with planted fixtures, and
 * neither hardcodes a version). The deliverable is the `ui-pin-skew:` job in
 * `.github/workflows/ci.yml`; this class is that job's guard, in the shape
 * S411 established for `cross-repo-paths:`: each assertion corresponds to a
 * mutation that would put the blind spot back.
 *
 * | mutation                                          | this test |
 * | ------------------------------------------------- | --------- |
 * | delete / comment out the `ui-pin-skew:` job        | RED       |
 * | rename the check (`name:` drifts off UI Pin Skew) | RED       |
 * | drop any of the three anonymous consumer fetches  | RED       |
 * | drop either live tag-ladder ls-remote             | RED       |
 * | sever the fetch→script join (paths no longer fed) | RED       |
 * | stop executing scripts/report-ui-pin-skew.php     | RED       |
 * | add `continue-on-error:` or a `needs:` to the job | RED       |
 * | remove the workflow `schedule:` trigger           | RED       |
 * | add a top-level `paths:` filter to the workflow   | RED       |
 * | delete or break the script the job points at      | RED       |
 *
 * The `schedule:` row matters most here: pins move on OTHER repositories'
 * clocks, and GitHub Actions triggers are workflow-level — so the schedule
 * lives in `on:` and this test reads that block (comments stripped: the S181
 * comment justifying the schedule literally contains the word `schedule:`, and
 * a check satisfied by its own documentation is not a check).
 *
 * @package Phlix\Hub\Tests\Unit\Support
 */
final class UiPinSkewCiWiringTest extends TestCase
{
    private const WORKFLOW = __DIR__ . '/../../../.github/workflows/ci.yml';

    private const SCRIPT = __DIR__ . '/../../../scripts/report-ui-pin-skew.php';

    /**
     * The `ui-pin-skew:` job block, comment-only lines removed, extracted by
     * indentation exactly like CrossRepoPathAssertCiWiringTest: jobs sit at two
     * spaces; the block runs from the job key to the next sibling key.
     */
    private function uiPinJob(): string
    {
        self::assertFileExists(self::WORKFLOW, 'the CI workflow must exist');

        $lines = preg_split('/\R/', (string) file_get_contents(self::WORKFLOW)) ?: [];
        $block = [];
        $inJob = false;

        foreach ($lines as $line) {
            if (preg_match('/^  ui-pin-skew:\s*(#.*)?$/', $line) === 1) {
                $inJob = true;
                continue;
            }

            if ($inJob && preg_match('/^  \S/', $line) === 1) {
                break;
            }

            if (!$inJob) {
                continue;
            }

            if (preg_match('/^\s*#/', $line) === 1) {
                continue;
            }

            $block[] = $line;
        }

        self::assertNotSame(
            [],
            $block,
            'the workflow must still define a `ui-pin-skew:` job — S181 gave the pin-skew reporter '
            . 'its only automated home, and a deleted job is the exact silent-green state this test '
            . 'exists to catch',
        );

        return implode("\n", $block);
    }

    /**
     * The workflow's `on:` block with comment-only lines removed — the trigger
     * set the job lives behind.
     */
    private function triggers(): string
    {
        $lines = preg_split('/\R/', (string) file_get_contents(self::WORKFLOW)) ?: [];
        $block = [];
        $inOn = false;

        foreach ($lines as $line) {
            if (preg_match('/^on:\s*$/', $line) === 1) {
                $inOn = true;
                continue;
            }

            if ($inOn && preg_match('/^\S/', $line) === 1) {
                break;
            }

            if (!$inOn || preg_match('/^\s*#/', $line) === 1) {
                continue;
            }

            $block[] = $line;
        }

        self::assertNotSame([], $block, 'the workflow must still declare an `on:` trigger block');

        return implode("\n", $block);
    }

    public function testTheJobCarriesThePinnedCheckName(): void
    {
        self::assertMatchesRegularExpression(
            '/^\s+name: UI Pin Skew\s*$/m',
            $this->uiPinJob(),
            'the check name is the coordinator-visible identity (expect-checks file, MCP tool set, '
            . 'merge gates); silently renaming it strands every one of them',
        );
    }

    public function testTheJobFetchesEveryRemoteConsumerAnonymously(): void
    {
        $job = $this->uiPinJob();

        foreach (
            [
                'https://raw.githubusercontent.com/detain/phlix-server/master/web-ui/package.json',
                'https://raw.githubusercontent.com/detain/phlix-windows-client/master/package.json',
                'https://raw.githubusercontent.com/detain/phlix-tizen-client/master/package.json',
            ] as $url
        ) {
            self::assertStringContainsString(
                $url,
                $job,
                "the ui-pin-skew job must fetch $url — the repo is public and the estate's rule is "
                . 'anonymous reads (no token ever); dropping one fetch silently drops one consumer',
            );
        }

        self::assertStringNotContainsString(
            'GH_TOKEN',
            $job,
            'the fetch must stay anonymous — a token here would leak into a job reading four other repos',
        );
    }

    public function testTheJobReadsBothLiveTagLadders(): void
    {
        $job = $this->uiPinJob();

        self::assertStringContainsString(
            'git ls-remote --tags https://github.com/detain/phlix-ui',
            $job,
            'the skew must be graded against LIVE tags — the step\'s rot warning: every written-down '
            . 'skew figure went stale within weeks',
        );
        self::assertStringContainsString(
            'git ls-remote --tags https://github.com/detain/phlix-contracts',
            $job,
            'the @phlix/contracts coverage the block demanded ("cover both packages or say why not") '
            . 'needs its own live ladder',
        );
    }

    public function testFetchFailuresAreJobFailuresNotSkips(): void
    {
        $job = $this->uiPinJob();

        self::assertStringContainsString(
            'set -euo pipefail',
            $job,
            'without pipefail+errexit a failed curl continues the step and the reporter runs short — '
            . 'the cannot-measure state must be RED, like cross-repo-paths demands',
        );
        self::assertStringContainsString(
            '::error::could not anonymously fetch',
            $job,
            'a dropped fetch must annotate which consumer vanished rather than print a shorter table',
        );
    }

    public function testTheJobExecutesTheReporterWithEveryFetchedFile(): void
    {
        $job = $this->uiPinJob();

        self::assertStringContainsString(
            'php scripts/report-ui-pin-skew.php',
            $job,
            'the ui-pin-skew job must execute scripts/report-ui-pin-skew.php — S411 proved a script '
            . 'without a runner is a script that never runs',
        );

        // Pin the JOIN, not just the ends: the fetched artifacts must be the very
        // paths the script is pointed at, or the fetch is theatre.
        foreach (
            [
                '--package-json="server=$D/server-package.json"',
                '--package-json="windows=$D/windows-package.json"',
                '--package-json="tizen=$D/tizen-package.json"',
                '--tags-file="$D/ui-tags.txt"',
                '--contracts-tags-file="$D/contracts-tags.txt"',
            ] as $seam
        ) {
            self::assertStringContainsString(
                $seam,
                $job,
                "the invocation must feed the script its fetched file: $seam",
            );
        }
    }

    public function testTheJobCannotBeNeuteredByContinueOnError(): void
    {
        self::assertStringNotContainsString(
            'continue-on-error',
            $this->uiPinJob(),
            'a pin-skew gate whose failure is demoted to a warning reports success without having '
            . 'protected anything — the exact S173/S258/S299/S411 class',
        );
    }

    public function testTheJobIsNotBehindANeedsChain(): void
    {
        self::assertStringNotContainsString(
            'needs:',
            $this->uiPinJob(),
            'skipped jobs read as SUCCESS at the checks API; a needs: chain would stop the S181 '
            . 'visibility gate on precisely the runs where upstream went red',
        );
    }

    public function testTheWorkflowStillRunsOnScheduleAndPushAndStaysUnfiltered(): void
    {
        $on = $this->triggers();

        self::assertMatchesRegularExpression('/^\s+schedule:\s*$/m', $on, 'S181 exists because pins '
            . 'move between pushes to THIS repo; losing the schedule turns the time check back into a diff check');
        self::assertMatchesRegularExpression(
            '/^\s+-\s+cron:\s+.*\S\s*$/m',
            $on,
            'the schedule needs a cron expression',
        );
        self::assertMatchesRegularExpression('/^\s+push:\s*$/m', $on, 'push must remain a trigger');
        self::assertMatchesRegularExpression('/^\s+pull_request:\s*$/m', $on, 'pull_request must remain a trigger');
        self::assertStringNotContainsString(
            'paths:',
            $on,
            'a top-level path filter would make this job report NOTHING on the commits that move '
            . 'pins elsewhere — the spa-bundle doctrine: the always-on workflow must stay unfiltered'
        );
    }

    public function testTheReporterScriptExistsAndParses(): void
    {
        self::assertFileExists(
            self::SCRIPT,
            'scripts/report-ui-pin-skew.php must exist — the ui-pin-skew CI job executes exactly this file',
        );

        $output = [];
        $exit = 0;
        exec(sprintf('%s -l %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg(self::SCRIPT)), $output, $exit);

        self::assertSame(0, $exit, "scripts/report-ui-pin-skew.php does not parse:\n" . implode("\n", $output));
        self::assertStringContainsString(
            'No syntax errors detected',
            implode("\n", $output),
            'the lint must have actually inspected the script',
        );
    }

    public function testTheScriptStillKnowsItsStep(): void
    {
        $source = (string) file_get_contents(self::SCRIPT);

        self::assertTrue(
            str_contains($source, "'S181PINSKEWX9Q2'"),
            'the provenance stamp must live in the script as a string literal, not only in prose — '
            . 'a rewritten-out stamp is a silent ownership loss (php -w strips comments; literals survive)',
        );
    }
}
