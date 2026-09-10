<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_values;
use function file_get_contents;
use function implode;
use function preg_match;
use function preg_split;
use function str_contains;
use function str_replace;
use function substr_count;

/**
 * S253 — pin the CI wiring of the hub SPA bundle build-and-compare gate.
 *
 * ## Why a test reads a workflow file
 *
 * The S253 deliverable lives ENTIRELY in `.github/workflows/ci.yml`'s
 * `spa-bundle:` job: no PHP calls it, and a green PHP suite proves nothing
 * about whether the bundle gate still runs, still compares, and still prints
 * the corpus it examined. So the job's shape is as much the deliverable as a
 * controller's body is, and it is pinned here in the same
 * "parse the workflow text, fail on the edit" style as
 * {@see McpE2ECiWiringTest} and {@see CrossRepoPathAssertCiWiringTest}.
 *
 * ⚠ No YAML library is used. `symfony/yaml` is not a dependency and `ext-yaml`
 * exists on the dev box but not in the CI PHPUnit runner, so `yaml_parse()`
 * "passes here and fatals in CI" — the exact trap
 * {@see \Phlix\Hub\Tests\Unit\Mcp\McpScopeOpenApiEnumContractTest} documents.
 * The workflow is parsed structurally by indentation instead, which is stable
 * across both environments.
 *
 * | mutation                                            | this test |
 * | --------------------------------------------------- | --------- |
 * | delete / rename the `spa-bundle:` job               | RED       |
 * | strip `git diff --exit-code -- public/assets/app/`  | RED       |
 * | drop the corpus-count print or its empty-set guard  | RED       |
 * | add `|| true` or `continue-on-error` to the verdict | RED       |
 * | float or change the node pin off the exact patch    | RED       |
 * | move the survival token out of the workflow         | RED       |
 *
 * ⚠ Scope. This pins that the wiring is PRESENT and unneutered. It cannot
 * defend against an author who rewrites the gate's shell logic, and it
 * deliberately does not try — a rule that fights its own maintainer gets
 * deleted.
 *
 * @package Phlix\Hub\Tests\Unit\Support
 */
final class SpaBundleCiWiringTest extends TestCase
{
    private const WORKFLOW = __DIR__ . '/../../../.github/workflows/ci.yml';

    private const CHANGELOG = __DIR__ . '/../../../CHANGELOG.md';

    /**
     * Verbatim name of the verdict step — the step that decides the job.
     */
    private const VERDICT_STEP = 'Assert the served bundle equals a fresh build';

    /**
     * The exact comparison the gate's verdict rests on, pinned verbatim so an
     * edit to a weaker check (no --exit-code, wrong path) reddens the suite.
     */
    private const VERDICT_NEEDLE = 'git diff --exit-code -- public/assets/app/';

    /**
     * Code-resident survival token for S253. Declared as PHP source, so the
     * merge ritual's comment-stripped `--token` corpus (every tracked *.php
     * through php_strip_whitespace) finds it, alongside its inline home in the
     * `spa-bundle:` key comment. Zero homes in any markdown.
     */
    public const SURVIVAL_TOKEN = 'S253HUBGATEX7H9';

    /**
     * The `spa-bundle:` job block with comment-only lines removed, extracted by
     * indentation. The start pattern tolerates an optional trailing comment on
     * the key line (where the survival token lives).
     */
    private function spaBundleJob(): string
    {
        self::assertFileExists(self::WORKFLOW, 'the CI workflow must exist');

        $lines = preg_split('/\R/', (string) file_get_contents(self::WORKFLOW)) ?: [];
        $block = [];
        $inJob = false;

        foreach ($lines as $line) {
            if (preg_match('/^  spa-bundle:(?:\s+#.*)?$/', $line) === 1) {
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
            'the workflow must still define a `spa-bundle:` job — S253 gave the hub its only SPA '
            . 'build-and-compare gate and this repo has no bundle-drift detector without it'
        );

        return implode("\n", $block);
    }

    /**
     * The single step whose name matches VERDICT_STEP. assertCount(1) is the
     * non-vacuity control: zero matches (renamed/removed) or two (duplicated)
     * both fail here rather than returning a silently-empty step.
     */
    private function verdictStep(): string
    {
        $steps = preg_split('/^(?=      - name:)/m', $this->spaBundleJob()) ?: [];
        $matches = array_values(array_filter(
            $steps,
            static fn (string $step): bool => str_contains($step, self::VERDICT_STEP),
        ));

        self::assertCount(
            1,
            $matches,
            'exactly one step of the spa-bundle job must be the "' . self::VERDICT_STEP . '" verdict'
        );

        return $matches[0];
    }

    public function testTheSpaBundleJobIsDefined(): void
    {
        $job = $this->spaBundleJob();

        self::assertStringContainsString(
            'actions/setup-node@',
            $job,
            'the spa-bundle job must set up Node explicitly (unlike mcp-e2e, which floats the '
            . 'runner default): the verdict is byte-reproducibility, so the toolchain is pinned'
        );
        self::assertStringContainsString(
            'npm ci',
            $job,
            'the job must install with `npm ci` from the committed lockfile — a bare `npm install` '
            . 'would float resolution and red the gate for a reason unrelated to the diff'
        );
        self::assertStringContainsString(
            'NPM_CONFIG_USERCONFIG: /dev/null',
            $job,
            'the job must neutralise the host ~/.npmrc so dependency resolution is a pure function '
            . 'of package-lock.json (the estate npm venue rule)'
        );
    }

    public function testTheJobIsNotBehindANeedsChain(): void
    {
        // A skipped job counts as SUCCESS with no branch protection, so a
        // `needs:` here would let the gate go quiet on exactly the PRs where
        // the other gates fail and drift is most likely.
        self::assertStringNotContainsString(
            'needs:',
            $this->spaBundleJob(),
            'the spa-bundle job must declare no `needs:` — GitHub skips dependents of failed jobs, '
            . 'a skipped job reads as SUCCESS, and the S253 bundle gate would silently stop running '
            . 'on the PRs that most need it'
        );
    }

    public function testTheVerdictStepPinsTheExitCodeComparison(): void
    {
        self::assertStringContainsString(
            self::VERDICT_NEEDLE,
            $this->verdictStep(),
            'the verdict step must contain the exact byte-comparison the gate is built on — a drift '
            . 'in this string (dropped --exit-code, wrong path) turns the gate into theatre'
        );
    }

    public function testTheVerdictStepPrintsAndGuardsTheCorpusCount(): void
    {
        $step = $this->verdictStep();

        // The gate states how much it examined, and refuses to pass on nothing.
        self::assertStringContainsString(
            'git ls-files -- public/assets/app | wc -l',
            $step,
            'the step must count the tracked files it is comparing so a future narrowing of the '
            . 'corpus toward zero is visible, not silent'
        );
        self::assertStringContainsString(
            '-gt 0',
            $step,
            'the step must guard against an EMPTY corpus — a gate that compares zero files is '
            . 'indistinguishable from one that passes (the S299 rule)'
        );
    }

    public function testTheVerdictStepCannotBeNeutered(): void
    {
        $step = $this->verdictStep();

        self::assertStringNotContainsString(
            '|| true',
            $step,
            'the verdict step must not swallow its own failure with `|| true` — the gate exits via '
            . '`exit "${status}"`, and any `|| true` on it is the soft-fail this job forbids'
        );
        self::assertStringNotContainsString(
            'continue-on-error',
            $step,
            'the verdict step must not carry `continue-on-error` — that reports a failed gate as '
            . 'success, the exact shape of defect S253 exists to remove'
        );

        // Anti-vacuity: the detector above must actually bite. Re-introducing a
        // `|| true` into the step text MUST be visible to the assertion, so the
        // "cannot be neutered" check cannot pass on a neutered step.
        $neutered = str_replace(
            'exit "${status}"',
            'exit "${status}" || true',
            $step
        );
        self::assertNotSame(
            $step,
            $neutered,
            'the mutation must actually bite the step text (the exit line must be present to mutate)'
        );
        self::assertStringContainsString(
            '|| true',
            $neutered,
            'the neutered mutant must now contain `|| true` — if the real assertion cannot see a '
            . 'planted soft-fail it would miss a real one'
        );
    }

    public function testNodeIsPinnedToTheExactReproducibilityPatch(): void
    {
        self::assertMatchesRegularExpression(
            "/^          node-version: '24\\.20\\.0'$/m",
            $this->spaBundleJob(),
            'the build must run on the EXACT node patch the bundle was regenerated under, mirroring '
            . 'the phlix-server S253 twin — a floated `\'24\'` lets the toolchain drift under a '
            . 'green bundle and the byte-comparison stops meaning what it says'
        );
    }

    public function testTheSurvivalTokenHasExactlyTwoCodeHomesAndNoneInMarkdown(): void
    {
        $workflow = (string) file_get_contents(self::WORKFLOW);

        // Home 1: inline in the workflow (so a plain grep of ci.yml finds it,
        // and the merge ritual could assert --token-in on the yml alone).
        self::assertSame(
            1,
            substr_count($workflow, self::SURVIVAL_TOKEN),
            'the survival token must appear exactly once inline in ci.yml (its workflow home)'
        );

        // Home 2: this PHP const — verified by the class being loaded to run
        // this test at all; assert the literal so a rename breaks it loudly.
        self::assertSame(
            'S253HUBGATEX7H9',
            self::SURVIVAL_TOKEN,
            'the code-resident survival token literal is the merge ritual\'s --token search key; '
            . 'changing it must be a deliberate edit, not a silent drift'
        );

        // Zero homes in markdown: the token must not leak into CHANGELOG/docs,
        // which is why --token uses the tokenized PHP corpus, not a text grep.
        $changelog = (string) file_get_contents(self::CHANGELOG);
        self::assertSame(
            0,
            substr_count($changelog, self::SURVIVAL_TOKEN),
            'the survival token must NOT appear in CHANGELOG.md — a doc copy would recreate the '
            . 'exact string the survival check greps for and defeat it (S345 lesson)'
        );
    }
}
