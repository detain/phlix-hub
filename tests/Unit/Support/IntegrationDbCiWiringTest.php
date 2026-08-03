<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * S173 — the real-database CI wiring must not be one deletable line away from
 * silent.
 *
 * ## Why a test reads a workflow file
 *
 * The defect S173 fixes was invisible for the life of the workflow: no
 * `services:` block, no `HUB_TEST_DB_*`, so all 31 tests under
 * `tests/Integration/` skipped on every run and the job stayed green. Restoring
 * the wiring fixes today; nothing about that fix stops the next edit from
 * removing it again, and the symptom of removal is *green with skips* — the
 * quietest failure there is.
 *
 * This test is therefore the guard's guard, and it runs everywhere the unit suite
 * runs, including the dev box that has no MySQL. Each assertion corresponds to a
 * mutation that would otherwise be silent:
 *
 * | mutation                                              | this test |
 * | ----------------------------------------------------- | --------- |
 * | delete the `services:` mysql block                     | RED       |
 * | delete or rename any `HUB_TEST_DB_*` variable           | RED       |
 * | drop `--log-junit` from the PHPUnit command             | RED       |
 * | delete the "Assert the integration tests actually ran" step | RED  |
 * | add `continue-on-error: true` to the phpunit job        | RED       |
 * | delete `scripts/assert-integration-tests-ran.php`       | RED       |
 * | delete the migration step / its `HUB_DB_*`              | RED       |
 * | comment any of the above out instead of deleting it     | RED       |
 *
 * The last row is why every assertion runs against the workflow text with
 * **comment-only lines stripped**: `# image: mysql:8.0` must not satisfy a check
 * that the service exists.
 *
 * ⚠ Scope. This pins that the wiring is PRESENT. It cannot defend against an
 * author who rewrites the gate script's logic, and it deliberately does not try —
 * a rule that fights its own maintainer produces churn and gets deleted. What it
 * does guarantee is that the *absence* of the real-database configuration can
 * never again be indistinguishable from success.
 *
 * @package Phlix\Hub\Tests\Unit\Support
 */
final class IntegrationDbCiWiringTest extends TestCase
{
    private const WORKFLOW = __DIR__ . '/../../../.github/workflows/ci.yml';

    private const GATE_SCRIPT = __DIR__ . '/../../../scripts/assert-integration-tests-ran.php';

    private const MIGRATION_SCRIPT = __DIR__ . '/../../../scripts/run-migrations.php';

    /**
     * The `phpunit:` job block of the workflow, with comment-only lines removed.
     *
     * Extracted by indentation: jobs sit at two spaces, so the block runs from
     * `  phpunit:` to the next line that starts a sibling key at that depth.
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

            if (!$inJob) {
                continue;
            }

            // Comment-only lines are not configuration.
            if (preg_match('/^\s*#/', $line) === 1) {
                continue;
            }

            $block[] = $line;
        }

        self::assertNotSame([], $block, 'the workflow must still define a `phpunit:` job');

        return implode("\n", $block);
    }

    public function testThePhpunitJobProvisionsAMysqlService(): void
    {
        $job = $this->phpunitJob();

        self::assertMatchesRegularExpression(
            '/^\s{4}services:\s*$/m',
            $job,
            'The phpunit job must declare a `services:` block. Without a database every test under '
            . 'tests/Integration/ calls markTestSkipped(), phpunit still exits 0 with "OK, but some '
            . 'tests were skipped!", and the job goes green having verified none of the hub\'s '
            . 'real-schema behaviour (S173).',
        );

        self::assertMatchesRegularExpression(
            '/^\s{6}mysql:\s*$/m',
            $job,
            'the phpunit job\'s service must still be named `mysql`',
        );

        self::assertMatchesRegularExpression(
            '/image:\s*mysql:8/',
            $job,
            'the mysql service must run a MySQL 8 image — the deploy target. A different major would '
            . 'test a schema the hub is not deployed on.',
        );

        self::assertMatchesRegularExpression(
            '/MYSQL_DATABASE:\s*\S+/',
            $job,
            'the mysql service must create the test database up front: the integration tests require '
            . 'the named database to already exist (they only DROP and re-create TABLES).',
        );
    }

    /**
     * @return list<array{string}>
     */
    public static function dbEnvVarProvider(): array
    {
        return [
            ['HUB_TEST_DB_HOST'],
            ['HUB_TEST_DB_PORT'],
            ['HUB_TEST_DB_USER'],
            ['HUB_TEST_DB_PASSWORD'],
            ['HUB_TEST_DB_NAME'],
        ];
    }

    /**
     * @dataProvider dbEnvVarProvider
     */
    public function testThePhpunitStepSuppliesEveryTestDbVariable(string $variable): void
    {
        self::assertMatchesRegularExpression(
            '/^\s+' . preg_quote($variable, '/') . ':\s*\S+/m',
            $this->phpunitJob(),
            $variable . ' must be supplied to the PHPUnit step. Every tests/Integration/** case gates '
            . 'on HUB_TEST_DB_HOST / HUB_TEST_DB_NAME and reads the rest, so a missing variable puts '
            . 'all 31 of them back to skipping — green, and proving nothing (S173).',
        );
    }

    public function testThePhpunitStepWritesTheJunitReportTheGateReads(): void
    {
        self::assertMatchesRegularExpression(
            '/vendor\/bin\/phpunit[^\n]*--log-junit\s+junit\.xml/',
            $this->phpunitJob(),
            'The PHPUnit step must pass `--log-junit junit.xml`. It is the only artefact that '
            . 'distinguishes "the real-database tests ran" from "they all skipped" after the fact — '
            . 'both exit 0 — and scripts/assert-integration-tests-ran.php reads exactly that file.',
        );
    }

    public function testTheSkipGateStepStillRuns(): void
    {
        $job = $this->phpunitJob();

        self::assertStringContainsString(
            'scripts/assert-integration-tests-ran.php',
            $job,
            'The phpunit job must still run scripts/assert-integration-tests-ran.php. It is the step '
            . 'that turns "all 31 real-database tests skipped" from a green tick into a red build; '
            . 'without it a misconfigured service is indistinguishable from success (S173).',
        );

        self::assertFileExists(
            self::GATE_SCRIPT,
            'scripts/assert-integration-tests-ran.php must exist for the workflow step to run it',
        );
    }

    /**
     * ⚠ Scoped to the gate step, NOT to the whole job — deliberately.
     * `continue-on-error: true` is already present on the pre-existing Codacy
     * coverage upload, where it is correct (a missing token must not fail the test
     * job). Asserting its absence job-wide would have been a false positive that
     * pressured someone into deleting a legitimate line, and this rule would then
     * be the noisy rule that gets removed. What must never be advisory is the
     * gate: `continue-on-error` makes a FAILED step report success, which is
     * exactly the defect S173 removes.
     *
     * The same reasoning applies to the PHPUnit step itself, so both are checked.
     */
    public function testTheSkipGateAndThePhpunitRunCannotBeNeuteredByContinueOnError(): void
    {
        $gate = $this->stepContaining('scripts/assert-integration-tests-ran.php');

        self::assertStringNotContainsString(
            'continue-on-error',
            $gate,
            'the S173 gate step must not carry `continue-on-error` — that makes a FAILED gate report '
            . 'success, which is the very shape of defect it exists to remove',
        );

        self::assertStringContainsString(
            'if: always()',
            $gate,
            'the S173 gate step must keep `if: always()`, so a red suite still reports whether its '
            . 'real-database half ran at all — the moment that information matters most',
        );

        self::assertStringNotContainsString(
            'continue-on-error',
            $this->stepContaining('vendor/bin/phpunit'),
            'the PHPUnit step must not carry `continue-on-error`',
        );
    }

    /**
     * The single workflow step whose body contains `$needle`, comment lines
     * stripped. Steps start at six spaces (`      - name:`), which bounds the
     * block.
     */
    private function stepContaining(string $needle): string
    {
        $steps = preg_split('/^(?=      - name:)/m', $this->phpunitJob()) ?: [];
        $matches = array_values(array_filter(
            $steps,
            static fn (string $step): bool => str_contains($step, $needle),
        ));

        self::assertCount(
            1,
            $matches,
            sprintf('exactly one step in the phpunit job must contain "%s"', $needle),
        );

        return $matches[0];
    }

    public function testTheMigrationChainIsAppliedAgainstTheServiceDatabase(): void
    {
        $job = $this->phpunitJob();

        self::assertStringContainsString(
            'scripts/run-migrations.php',
            $job,
            'The phpunit job must apply the migration chain against the service database. That is the '
            . 'S173 acceptance criterion "the migration chain applies forward in CI", and it exercises '
            . 'the same standalone runner the deploy scripts use.',
        );

        foreach (['HUB_DB_HOST', 'HUB_DB_PORT', 'HUB_DB_USER', 'HUB_DB_PASSWORD', 'HUB_DB_NAME'] as $var) {
            self::assertMatchesRegularExpression(
                '/^\s+' . preg_quote($var, '/') . ':\s*\S+/m',
                $job,
                $var . ' must be supplied to the migration step: scripts/run-migrations.php resolves '
                . 'its target through config/database.php, which reads HUB_DB_* (not HUB_TEST_DB_*). '
                . 'Without it the runner would aim at the default phlix_hub database and fail.',
            );
        }

        self::assertFileExists(self::MIGRATION_SCRIPT);
    }

    /**
     * A 10-minute cap cannot hold the suite once the real-database tests actually
     * run — measured ~8 minutes for the Integration suite alone, because each of
     * the 31 tests re-applies the whole migration chain in setUp(). A job that
     * times out is red for the wrong reason and invites "just skip the slow ones".
     */
    public function testThePhpunitJobBudgetsTimeForTheRealDatabaseTests(): void
    {
        $job = $this->phpunitJob();

        self::assertMatchesRegularExpression('/timeout-minutes:\s*(\d+)/', $job);
        preg_match('/timeout-minutes:\s*(\d+)/', $job, $matches);

        self::assertGreaterThanOrEqual(
            20,
            (int) ($matches[1] ?? 0),
            'the phpunit job needs at least 20 timeout-minutes now that the 31 real-database tests '
            . 'execute (each drops every table and re-applies the 29-file migration chain)',
        );
    }
}
