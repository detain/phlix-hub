<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * S173 — behaviour of `scripts/assert-integration-tests-ran.php`, by execution.
 *
 * The script is the gate that turns "all 31 real-database tests skipped" from a
 * green tick into a red build. A gate is only worth its presence if its own
 * failure paths are proven, so every branch is driven here against a crafted
 * JUnit report: a good run, a skipped integration test, a skipped UNIT test, a
 * deleted integration suite, a missing report, an empty report and unparseable
 * XML. Each asserts the exit code AND the message, because a gate that exits 1
 * with a useless message gets "fixed" by deleting it.
 *
 * @package Phlix\Hub\Tests\Unit\Support
 */
final class AssertIntegrationTestsRanTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../../../scripts/assert-integration-tests-ran.php';

    /** Floor the script enforces; kept in sync deliberately, see testFloorMatchesTheScript(). */
    private const FLOOR = 31;

    private string $workDir = '';

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/hub-s173-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($dir, 0o700, true), 'temp dir for the JUnit fixtures');
        $this->workDir = $dir;
    }

    protected function tearDown(): void
    {
        if ($this->workDir === '') {
            return;
        }

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

    public function testTheGateScriptExists(): void
    {
        self::assertFileExists(self::SCRIPT);
    }

    /**
     * The floor this test reasons about must be the floor the script enforces —
     * otherwise these tests could pass against a script whose gate had been
     * lowered to 0.
     */
    public function testFloorMatchesTheScript(): void
    {
        $source = (string) file_get_contents(self::SCRIPT);

        self::assertMatchesRegularExpression(
            '/const MIN_INTEGRATION_TESTS = ' . self::FLOOR . ';/',
            $source,
            'scripts/assert-integration-tests-ran.php must still require at least ' . self::FLOOR
            . ' executed integration tests. Lowering the floor is how this gate would be neutered.',
        );
    }

    public function testAcceptsARunWhereEveryIntegrationTestExecuted(): void
    {
        $report = $this->writeReport($this->junit(integration: self::FLOOR, unit: 9));

        [$code, $stdout, $stderr] = $this->runGate($report);

        self::assertSame(0, $code, "gate must accept a clean run.\n{$stdout}\n{$stderr}");
        self::assertStringContainsString('S173 integration-test gate OK', $stdout);
        self::assertStringContainsString('0 skipped', $stdout);
        self::assertStringContainsString((string) self::FLOOR . ' of them real-database tests', $stdout);
    }

    /**
     * The live defect: MySQL absent or misconfigured, so the real-database tests
     * skip and PHPUnit still exits 0.
     */
    public function testRejectsARunWhereTheIntegrationTestsSkipped(): void
    {
        $report = $this->writeReport($this->junit(integration: self::FLOOR, unit: 9, skipIntegration: true));

        [$code, , $stderr] = $this->runGate($report);

        self::assertSame(1, $code, 'a run whose real-database tests skipped must FAIL');
        self::assertStringContainsString('were SKIPPED', $stderr);
        self::assertStringContainsString('real-database tests', $stderr);
        // Names the offending test, so the failure is actionable.
        self::assertStringContainsString('IntegrationCase0::testSomething', $stderr);
        // Points at the actual cause rather than at the gate.
        self::assertStringContainsString('HUB_TEST_DB_HOST', $stderr);
    }

    /**
     * The skip check is whole-suite: a unit test that starts skipping is the same
     * lie in a different place.
     */
    public function testRejectsARunWhereAUnitTestSkipped(): void
    {
        $report = $this->writeReport($this->junit(integration: self::FLOOR, unit: 9, skipUnit: true));

        [$code, , $stderr] = $this->runGate($report);

        self::assertSame(1, $code, 'a skipped unit test must FAIL too');
        self::assertStringContainsString('UnitCase0::testSomething', $stderr);
    }

    /**
     * The half that stops the obvious way of gaming the first half: delete the
     * integration tests and nothing is skipped any more.
     */
    public function testRejectsARunWithFewerIntegrationTestsThanTheFloor(): void
    {
        $report = $this->writeReport($this->junit(integration: self::FLOOR - 1, unit: 9));

        [$code, , $stderr] = $this->runGate($report);

        self::assertSame(1, $code, 'a run missing integration tests must FAIL');
        self::assertStringContainsString('expected at least ' . self::FLOOR, $stderr);
        self::assertStringContainsString('they are MISSING', $stderr);
    }

    public function testRejectsAReportThatWasNeverWritten(): void
    {
        [$code, , $stderr] = $this->runGate($this->workDir . '/never-written.xml');

        self::assertSame(1, $code, 'a missing report must FAIL, never exit 0');
        self::assertStringContainsString('was not produced', $stderr);
        self::assertStringContainsString('--log-junit', $stderr);
    }

    public function testRejectsAnEmptyReport(): void
    {
        $report = $this->writeReport('');

        [$code, , $stderr] = $this->runGate($report);

        self::assertSame(1, $code, 'an empty report must FAIL');
        self::assertStringContainsString('empty (0 bytes)', $stderr);
    }

    public function testRejectsAnUnparseableReport(): void
    {
        $report = $this->writeReport("<testsuites><testsuite oops='\n");

        [$code, , $stderr] = $this->runGate($report);

        self::assertSame(1, $code, 'an unparseable report must FAIL');
        self::assertStringContainsString('not parseable XML', $stderr);
    }

    public function testRejectsAReportWithNoTestCasesAtAll(): void
    {
        $report = $this->writeReport('<?xml version="1.0" encoding="UTF-8"?>' . "\n<testsuites/>\n");

        [$code, , $stderr] = $this->runGate($report);

        self::assertSame(1, $code, 'a run with no test cases must FAIL');
        self::assertStringContainsString('no <testcase> elements', $stderr);
    }

    /**
     * Build a JUnit report in PHPUnit 10's shape: `class` carries the namespaced
     * class name and a skip is a `<skipped/>` child element.
     */
    private function junit(
        int $integration,
        int $unit,
        bool $skipIntegration = false,
        bool $skipUnit = false,
    ): string {
        $cases = '';

        for ($i = 0; $i < $integration; $i++) {
            $class = 'Phlix\\Hub\\Tests\\Integration\\Fake\\IntegrationCase' . $i;
            $skipped = ($skipIntegration && $i === 0) ? '<skipped/>' : '';
            $cases .= sprintf(
                '    <testcase name="testSomething" class="%s" assertions="1" time="0.1">%s</testcase>' . "\n",
                $class,
                $skipped,
            );
        }

        for ($i = 0; $i < $unit; $i++) {
            $class = 'Phlix\\Hub\\Tests\\Unit\\Fake\\UnitCase' . $i;
            $skipped = ($skipUnit && $i === 0) ? '<skipped/>' : '';
            $cases .= sprintf(
                '    <testcase name="testSomething" class="%s" assertions="1" time="0.1">%s</testcase>' . "\n",
                $class,
                $skipped,
            );
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<testsuites>' . "\n"
            . '  <testsuite name="phpunit.xml">' . "\n"
            . $cases
            . '  </testsuite>' . "\n"
            . '</testsuites>' . "\n";
    }

    private function writeReport(string $xml): string
    {
        $path = $this->workDir . '/junit.xml';
        self::assertNotFalse(file_put_contents($path, $xml));

        return $path;
    }

    /**
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function runGate(string $reportPath): array
    {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, self::SCRIPT, $reportPath],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process, 'the gate script must be launchable');

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
