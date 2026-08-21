<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Support;

use Phlix\Hub\Tests\E2E\Mcp\McpClientSseE2ETest;
use Phlix\Hub\Tests\Support\Mcp\McpE2EProbeEnvironment;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function array_values;
use function escapeshellarg;
use function exec;
use function file_get_contents;
use function implode;
use function preg_split;
use function sprintf;
use function str_contains;

/**
 * S329 — exercise the live-session GATE scripts' failure paths, and keep the
 * required-case map honest.
 *
 * ## Why this file exists
 *
 * A gate that can no longer fail is not a gate — it is the S146/S173 shape
 * this repository keeps mistaking for coverage. The two new scripts
 * (`scripts/ci-mcp-e2e-prereqs.php` and `scripts/assert-mcp-e2e-ran.php`) are
 * checked here in the same style as phlix-server's `BrowserE2EGateTest`:
 * every failure path is driven against a fixture or an override and must exit
 * non-zero, and the required-case map is reconciled against the REAL test
 * class so a renamed/deleted case cannot silently stop being demanded.
 *
 * @package Phlix\Hub\Tests\Unit\Support
 */
final class McpE2EGateTest extends TestCase
{
    private const REPO_ROOT = __DIR__ . '/../../..';

    private const ASSERT_SCRIPT = self::REPO_ROOT . '/scripts/assert-mcp-e2e-ran.php';

    private const PREREQS_SCRIPT = self::REPO_ROOT . '/scripts/ci-mcp-e2e-prereqs.php';

    private const FIXTURES = __DIR__ . '/Fixtures/mcp-e2e-junit';

    /**
     * Run a script and capture exit code, stdout and stderr together.
     *
     * @return array{exit: int, output: string}
     */
    private function runScript(string $script, array $args = []): array
    {
        $command = 'php ' . escapeshellarg($script);
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }

        /** @var list<string> $out */
        $out = [];
        $exit = 0;
        exec($command . ' 2>&1', $out, $exit);

        return ['exit' => $exit, 'output' => implode("\n", $out)];
    }

    public function testTheGatePassesOnACompleteReport(): void
    {
        $result = $this->runScript(self::ASSERT_SCRIPT, [self::FIXTURES . '/ok.xml']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('6/6 required', $result['output']);
        self::assertStringContainsString('EXECUTED', $result['output']);
    }

    public function testTheGateFailsWhenACaseIsMissing(): void
    {
        $result = $this->runScript(self::ASSERT_SCRIPT, [self::FIXTURES . '/missing.xml']);

        self::assertNotSame(0, $result['exit']);
        self::assertStringContainsString('ABSENT', $result['output']);
        self::assertStringContainsString('testTheProbeFailsAgainstABrokenTransport', $result['output']);
    }

    public function testTheGateFailsWhenACaseIsSkipped(): void
    {
        $result = $this->runScript(self::ASSERT_SCRIPT, [self::FIXTURES . '/skipped.xml']);

        self::assertNotSame(0, $result['exit']);
        self::assertStringContainsString('SKIPPED', $result['output']);
        self::assertStringContainsString('testTheProbeFailsAgainstABrokenTransport', $result['output']);
    }

    public function testTheGateFailsWhenACaseAssertedNothing(): void
    {
        $result = $this->runScript(self::ASSERT_SCRIPT, [self::FIXTURES . '/zero-assertions.xml']);

        self::assertNotSame(0, $result['exit']);
        self::assertStringContainsString('ZERO assertions', $result['output']);
        self::assertStringContainsString('testTheProbeFailsAgainstABrokenTransport', $result['output']);
    }

    public function testTheGateFailsOnAnEmptyReport(): void
    {
        $result = $this->runScript(self::ASSERT_SCRIPT, [self::FIXTURES . '/empty.xml']);

        self::assertNotSame(0, $result['exit']);
        self::assertStringContainsString('no <testcase>', $result['output']);
    }

    public function testTheGateFailsWhenTheReportIsMissing(): void
    {
        $result = $this->runScript(self::ASSERT_SCRIPT, [self::FIXTURES . '/does-not-exist.xml']);

        self::assertNotSame(0, $result['exit']);
        self::assertStringContainsString('not produced', $result['output']);
    }

    public function testTheRequiredCasesMapMatchesTheE2ETestClass(): void
    {
        $reflection = new ReflectionClass(McpClientSseE2ETest::class);

        foreach (McpE2EProbeEnvironment::REQUIRED_CASES_BY_CLASS as $class => $methods) {
            self::assertSame(
                McpClientSseE2ETest::class,
                $class,
                'the map must point at the real E2E test class',
            );
            foreach ($methods as $method) {
                self::assertTrue(
                    $reflection->hasMethod($method),
                    sprintf(
                        'REQUIRED_CASES_BY_CLASS names %s::%s but the class has no such method — a '
                        . 'renamed or deleted case silently stops being demanded',
                        $class,
                        $method,
                    ),
                );
            }
        }

        self::assertSame(6, McpE2EProbeEnvironment::requiredCaseCount());
    }

    public function testThePrereqsScriptFailsWhenNodeIsMissing(): void
    {
        $result = $this->runScript(self::PREREQS_SCRIPT, ['--node=/nonexistent/node']);

        self::assertNotSame(0, $result['exit']);
        self::assertStringContainsString('no node', $result['output']);
    }

    public function testThePrereqsScriptFailsWhenTheSdkIsMissing(): void
    {
        $result = $this->runScript(self::PREREQS_SCRIPT, ['--sdk-dist=/nonexistent/sdk.js']);

        self::assertNotSame(0, $result['exit']);
        self::assertStringContainsString('not installed', $result['output']);
    }

    public function testTheCiStepPassesNoNarrowingOptions(): void
    {
        $workflow = (string) file_get_contents(self::REPO_ROOT . '/.github/workflows/ci.yml');

        $steps = preg_split('/^(?=      - name:)/m', $workflow) ?: [];
        $matching = array_values(array_filter(
            $steps,
            // Match the step BODY (the `run:` line), not any comment that
            // mentions the script's name in passing.
            static fn (string $step): bool => str_contains($step, 'run: php scripts/ci-mcp-e2e-prereqs.php'),
        ));

        self::assertCount(1, $matching, 'exactly one workflow step must run the prereqs script');

        $step = $matching[0];
        self::assertStringNotContainsString(
            '--node=',
            $step,
            'the CI step must not pass --node — that option exists only for offline unit tests and '
            . 'would narrow the gate to nothing',
        );
        self::assertStringNotContainsString(
            '--sdk-dist=',
            $step,
            'the CI step must not pass --sdk-dist — that option exists only for offline unit tests and '
            . 'would narrow the gate to nothing',
        );
    }
}
