<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function implode;
use function preg_match;
use function preg_quote;
use function preg_split;
use function str_contains;

/**
 * S329 — pin the CI wiring of the real-MCP-client live-session job.
 *
 * ## Why a test reads a workflow file
 *
 * The defect S329 closes was invisible for the life of the MCP surface: 14
 * unit test files, no real client, and — the half this test defends — no CI
 * job that could ever have noticed. The live-session proof lives entirely in
 * `.github/workflows/ci.yml`'s `mcp-e2e:` job, so that job's shape is as much
 * the deliverable as the test files are. The guard-test rows below pin the
 * same properties S173/S258/S299/S316 pin for their jobs, in the same
 * "parse the workflow text, fail on the edit" style:
 *
 * | mutation                                                    | this test |
 * | ----------------------------------------------------------- | --------- |
 * | `mcp-e2e:` gains a `needs:` (skipped gate == green)          | RED       |
 * | the mysql service is removed / not MySQL 8                   | RED       |
 * | a HUB_DB_* variable is dropped from the migration step       | RED       |
 * | the prereqs script is not run                                | RED       |
 * | the E2E run drops `--testsuite E2E` or `--log-junit`         | RED       |
 * | the gate script is not run, or loses `if: always()`          | RED       |
 * | the gate or the E2E run gains `continue-on-error`            | RED       |
 * | the SDK is not installed from the pinned lockfile            | RED       |
 * | the E2E testsuite disappears from / is not excluded in phpunit.xml | RED |
 *
 * ⚠ Scope. This pins that the wiring is PRESENT. It cannot defend against an
 * author who rewrites the gate script's logic, and it deliberately does not
 * try — a rule that fights its own maintainer produces churn and gets deleted.
 *
 * @package Phlix\Hub\Tests\Unit\Support
 */
final class McpE2ECiWiringTest extends TestCase
{
    private const WORKFLOW = __DIR__ . '/../../../.github/workflows/ci.yml';

    private const PHPUNIT_XML = __DIR__ . '/../../../phpunit.xml';

    /**
     * The `mcp-e2e:` job block of the workflow, with comment-only lines
     * removed. Extracted by indentation exactly like
     * {@see IntegrationDbCiWiringTest::phpunitJob()}.
     */
    private function mcpE2eJob(): string
    {
        self::assertFileExists(self::WORKFLOW, 'the CI workflow must exist');

        $lines = preg_split('/\R/', (string) file_get_contents(self::WORKFLOW)) ?: [];
        $block = [];
        $inJob = false;

        foreach ($lines as $line) {
            if (preg_match('/^  mcp-e2e:\s*$/', $line) === 1) {
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

        self::assertNotSame([], $block, 'the workflow must still define an `mcp-e2e:` job');

        return implode("\n", $block);
    }

    /**
     * The single workflow step whose body contains `$needle`, comment lines
     * stripped. Steps start at six spaces (`      - name:`), which bounds the
     * block.
     */
    private function stepContaining(string $needle): string
    {
        $steps = preg_split('/^(?=      - name:)/m', $this->mcpE2eJob()) ?: [];
        $matches = array_values(array_filter(
            $steps,
            static fn (string $step): bool => str_contains($step, $needle),
        ));

        self::assertCount(
            1,
            $matches,
            sprintf('exactly one step in the mcp-e2e job must contain "%s"', $needle),
        );

        return $matches[0];
    }

    public function testTheLiveSessionJobIsNotBehindANeedsChain(): void
    {
        // A skipped gate counts as SUCCESS with no branch protection, so the
        // one property of this job that matters more than any other is that it
        // cannot be skipped by a failed dependency. `needs:` at the job level
        // is the ONLY thing that makes GitHub skip a job.
        self::assertDoesNotMatchRegularExpression(
            '/^\s+needs:\s*\S/m',
            $this->mcpE2eJob(),
            'the mcp-e2e job must NOT declare `needs:`. A job behind a needs: chain that skips counts '
            . 'as SUCCESS to the checks API, which is exactly the "skipped gate == green" defect S329 '
            . 'exists to remove (the same rule the docker-boot-gate job documents).',
        );
    }

    public function testTheJobProvisionsAMysqlService(): void
    {
        $job = $this->mcpE2eJob();

        self::assertMatchesRegularExpression(
            '/^\s{4}services:\s*$/m',
            $job,
            'the mcp-e2e job must declare a `services:` block. The hub\'s PAT auth is DB-backed '
            . '(mcp_tokens), so without a real MySQL the minted tokens cannot validate and every '
            . 'probe 401s.',
        );

        self::assertMatchesRegularExpression(
            '/^\s{6}mysql:\s*$/m',
            $job,
            'the mcp-e2e job\'s service must still be named `mysql`',
        );

        self::assertMatchesRegularExpression(
            '/image:\s*mysql:8/',
            $job,
            'the mysql service must run a MySQL 8 image — the deploy target.',
        );

        self::assertMatchesRegularExpression(
            '/MYSQL_DATABASE:\s*\S+/',
            $job,
            'the mysql service must create the test database up front.',
        );
    }

    public function testTheMigrationStepSuppliesEveryHubDbVariable(): void
    {
        $job = $this->mcpE2eJob();

        foreach (['HUB_DB_HOST', 'HUB_DB_PORT', 'HUB_DB_USER', 'HUB_DB_PASSWORD', 'HUB_DB_NAME'] as $var) {
            self::assertMatchesRegularExpression(
                '/^\s+' . preg_quote($var, '/') . ':\s*\S+/m',
                $job,
                $var . ' must be supplied to the migration step: scripts/run-migrations.php resolves '
                . 'its target through config/database.php, which reads HUB_DB_* (not HUB_TEST_DB_*).',
            );
        }

        self::assertFileExists(__DIR__ . '/../../../scripts/run-migrations.php');
    }

    public function testTheJobRunsThePrereqsScript(): void
    {
        self::assertStringContainsString(
            'scripts/ci-mcp-e2e-prereqs.php',
            $this->mcpE2eJob(),
            'the mcp-e2e job must run the S329 prereqs gate. It is the cheap supply-side check that '
            . 'turns a missing node/SDK/extension into a red build instead of skipped tests.',
        );

        self::assertFileExists(__DIR__ . '/../../../scripts/ci-mcp-e2e-prereqs.php');
    }

    public function testTheSdkIsInstalledFromThePinnedLockfile(): void
    {
        $job = $this->mcpE2eJob();

        self::assertStringContainsString(
            'npm ci',
            $job,
            'the mcp-e2e job must install the MCP SDK with `npm ci` from the committed '
            . 'package-lock.json — a bare `npm install` would float the version and silently change '
            . 'what the probe proves.',
        );
        self::assertFileExists(__DIR__ . '/../../../tests/E2E/Mcp/package-lock.json');
    }

    public function testTheE2EStepWritesTheJunitReportTheGateReads(): void
    {
        self::assertMatchesRegularExpression(
            '/vendor\/bin\/phpunit[^\n]*--testsuite\s+E2E/',
            $this->mcpE2eJob(),
            'the E2E step must run the dedicated E2E testsuite — the live-session cases are excluded '
            . 'from the default Unit suite on purpose.',
        );

        self::assertMatchesRegularExpression(
            '/vendor\/bin\/phpunit[^\n]*--log-junit\s+junit-mcp\.xml/',
            $this->mcpE2eJob(),
            'the E2E step must pass `--log-junit junit-mcp.xml`. It is the only artefact that '
            . 'distinguishes "the real client ran against the hub" from "it all skipped" after the '
            . 'fact — both exit 0 — and scripts/assert-mcp-e2e-ran.php reads exactly that file.',
        );
    }

    public function testTheGateStepStillRuns(): void
    {
        $job = $this->mcpE2eJob();

        self::assertStringContainsString(
            'scripts/assert-mcp-e2e-ran.php',
            $job,
            'The mcp-e2e job must still run scripts/assert-mcp-e2e-ran.php. It is the step that turns '
            . '"all live-session cases skipped" from a green tick into a red build.',
        );

        self::assertFileExists(__DIR__ . '/../../../scripts/assert-mcp-e2e-ran.php');
    }

    public function testTheGateAndTheE2ERunCannotBeNeuteredByContinueOnError(): void
    {
        $gate = $this->stepContaining('scripts/assert-mcp-e2e-ran.php');

        self::assertStringNotContainsString(
            'continue-on-error',
            $gate,
            'the S329 gate step must not carry `continue-on-error` — that makes a FAILED gate report '
            . 'success, which is the very shape of defect it exists to remove',
        );

        self::assertStringContainsString(
            'if: always()',
            $gate,
            'the S329 gate step must keep `if: always()`, so a red suite still reports whether its '
            . 'live-session cases ran at all',
        );

        self::assertStringNotContainsString(
            'continue-on-error',
            $this->stepContaining('--testsuite E2E'),
            'the E2E run step must not carry `continue-on-error`',
        );
    }

    public function testTheE2ESuiteIsRegisteredAndExcludedFromTheDefaultUnitSuite(): void
    {
        $xml = (string) file_get_contents(self::PHPUNIT_XML);

        self::assertStringContainsString(
            '<testsuite name="E2E">',
            $xml,
            'phpunit.xml must register an E2E testsuite so `--testsuite E2E` works.',
        );

        self::assertStringContainsString(
            '<exclude>tests/E2E</exclude>',
            $xml,
            'phpunit.xml must exclude tests/E2E from the default Unit suite. Without the exclude, '
            . 'the phpunit job runs the live-session cases against a non-existent hub, they skip, '
            . 'and the S173 whole-suite zero-skip gate reds.',
        );
    }

    public function testTheJobBootsTheHubAndPointsTheSuiteAtIt(): void
    {
        $job = $this->mcpE2eJob();

        self::assertStringContainsString(
            'start.php start',
            $job,
            'the mcp-e2e job must BOOT the hub — the whole point of this step is a RUNNING hub.',
        );

        self::assertMatchesRegularExpression(
            '/HUB_MCP_E2E_BASE_URL:\s*\S+/',
            $job,
            'the E2E step must point the suite at the running hub via HUB_MCP_E2E_BASE_URL.',
        );

        self::assertMatchesRegularExpression(
            '/HUB_MCP_E2E_TOKENS_FILE:\s*\S+/',
            $job,
            'the E2E step must hand the suite the seeded tokens via HUB_MCP_E2E_TOKENS_FILE.',
        );
    }
}
