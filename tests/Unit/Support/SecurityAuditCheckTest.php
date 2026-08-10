<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * S246 — behaviour of `scripts/security-audit-check.php`, by execution.
 *
 * ## The defect these tests pin shut
 *
 * The "Security Audit" job in `.github/workflows/ci.yml` was:
 *
 * ```yaml
 * - run: composer install --no-interaction --prefer-dist --no-dev
 * - run: composer audit --no-dev
 * ```
 *
 * `--no-dev` drops every `require-dev` package from the audited set, so **no
 * advisory against a development dependency could ever fail that gate**. On
 * 2026-08-06 CVE-2026-67434 (HIGH, OS command injection) landed against
 * `squizlabs/php_codesniffer` `<3.13.6`; this repo's lock pinned 3.13.5 and the
 * job still reported SUCCESS. A green that cannot go red is not evidence.
 *
 * ## What is asserted, and why each half exists
 *
 *  1. **The dev half is really audited.** {@see testAnAdvisoryAgainstADevelopmentDependencyBlocks()}
 *     is the direct regression test: a `require-dev` advisory must exit 1 and be
 *     labelled `[require-dev]` so the reader can still see the scope.
 *  2. **`--no-dev` cannot come back.** The audit flags are a declared constant,
 *     read here rather than pattern-matched out of prose, and the workflow is
 *     parsed with its comments stripped — this file's own explanation of the
 *     defect contains the offending flag, and a detector that matches its own
 *     documentation is not a detector.
 *  3. **The corpus is stated and floored.** A gate that ran and inspected zero
 *     packages is the commonest false pass in this estate and looks exactly like
 *     a clean run, so the printed size is checked against an independent count,
 *     and both floors are driven to failure.
 *  4. **Cannot-measure fails.** Missing lock, unparseable lock, missing payload,
 *     empty payload, unparseable payload, unrecognised payload shape and an
 *     unreachable advisory repository each exit 1 rather than passing.
 *  5. **The blocking/advisory split holds.** Abandonment and config-ignored
 *     advisories are loud but non-blocking; a real advisory blocks even when
 *     they are present, so the advisory half cannot become decorative.
 *
 * @package Phlix\Hub\Tests\Unit\Support
 */
final class SecurityAuditCheckTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../../../scripts/security-audit-check.php';

    private const WORKFLOW = __DIR__ . '/../../../.github/workflows/ci.yml';

    private const REAL_LOCK = __DIR__ . '/../../../composer.lock';

    /** Floors the script enforces; kept in sync deliberately, see testFloorsMatchTheScript(). */
    private const MIN_PACKAGES = 80;

    private const MIN_DEV_PACKAGES = 40;

    private string $workDir = '';

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/hub-s246-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($dir, 0o700, true), 'temp dir for the audit fixtures');
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

        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // The gate exists and is wired into CI.
    // -----------------------------------------------------------------------

    public function testTheGateScriptExists(): void
    {
        self::assertFileExists(self::SCRIPT);
    }

    public function testTheWorkflowRunsTheGateScript(): void
    {
        self::assertStringContainsString(
            'php scripts/security-audit-check.php',
            $this->workflowWithoutComments(),
            '.github/workflows/ci.yml must invoke the audit gate.',
        );
    }

    /**
     * The whole defect in one line. The workflow is read with comments removed
     * because the replacement step carries a comment explaining what `--no-dev`
     * did, and a check that matches its own documentation proves nothing.
     */
    public function testTheWorkflowNoLongerExcludesDevelopmentDependencies(): void
    {
        $yaml = $this->workflowWithoutComments();

        self::assertStringNotContainsString(
            '--no-dev',
            $yaml,
            'S246: excluding require-dev from the audit is what made a HIGH advisory against '
            . 'squizlabs/php_codesniffer invisible to CI. Do not restore it.',
        );

        // Non-vacuity: prove the comment stripper did not simply empty the file.
        self::assertStringContainsString('composer-audit:', $yaml);
        self::assertGreaterThan(2000, strlen($yaml), 'comment stripping removed too much to trust the assertion');
    }

    public function testTheAuditStepIsNotNeutered(): void
    {
        $yaml = $this->workflowWithoutComments();
        $job  = substr($yaml, (int) strpos($yaml, 'composer-audit:'));

        self::assertStringNotContainsString(
            'continue-on-error',
            $job,
            'A security gate that cannot fail the build is the defect this replaces.',
        );
    }

    /**
     * The flags are read from the declared constant rather than grepped out of
     * the script body, so this cannot accidentally match a comment.
     */
    public function testTheAuditIsInvokedWithoutTheDevExclusion(): void
    {
        $flags = $this->auditArguments();

        self::assertContains('audit', $flags);
        self::assertContains('--locked', $flags);
        self::assertContains('--format=json', $flags);
        self::assertNotContains(
            '--no-dev',
            $flags,
            'S246: the audit must cover require-dev packages.',
        );
    }

    public function testFloorsMatchTheScript(): void
    {
        $source = (string) file_get_contents(self::SCRIPT);

        self::assertMatchesRegularExpression(
            '/const MIN_AUDITED_PACKAGES = ' . self::MIN_PACKAGES . ';/',
            $source,
            'Lowering the corpus floor is how this gate would be neutered.',
        );

        self::assertMatchesRegularExpression(
            '/const MIN_AUDITED_DEV_PACKAGES = ' . self::MIN_DEV_PACKAGES . ';/',
            $source,
            'Lowering the require-dev floor re-opens exactly the hole S246 closed.',
        );
    }

    // -----------------------------------------------------------------------
    // The corpus is stated out loud.
    // -----------------------------------------------------------------------

    public function testItPrintsTheCorpusItExamined(): void
    {
        $result = $this->runGate($this->payload(['advisories' => []]), $this->lock(85, 45));

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString(
            'Audit corpus: 130 locked package(s) — 85 require, 45 require-dev',
            $result['output'],
        );
        self::assertStringContainsString(
            'No security advisories affecting the 130 locked package(s)',
            $result['output']
        );
    }

    /**
     * Against the repo's own lock, with no lock argument, the reported corpus
     * must equal an independent count of that lock.
     */
    public function testTheDefaultCorpusIsTheReposOwnLock(): void
    {
        /** @var array{packages: list<array<string, mixed>>, packages-dev: list<array<string, mixed>>} $lock */
        $lock    = json_decode((string) file_get_contents(self::REAL_LOCK), true, 512, JSON_THROW_ON_ERROR);
        $runtime = count($lock['packages']);
        $dev     = count($lock['packages-dev']);

        self::assertGreaterThan(0, $runtime, 'the repo lock must actually contain runtime packages');
        self::assertGreaterThan(0, $dev, 'the repo lock must actually contain dev packages');

        $result = $this->runGate($this->payload(['advisories' => []]));

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString(
            sprintf(
                'Audit corpus: %d locked package(s) — %d require, %d require-dev',
                $runtime + $dev,
                $runtime,
                $dev,
            ),
            $result['output'],
        );
    }

    public function testACorpusBelowTheTotalFloorFails(): void
    {
        $result = $this->runGate($this->payload(['advisories' => []]), $this->lock(10, 45));

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString(
            '::error::Audit corpus is 55 package(s), below the floor of 80',
            $result['output']
        );
    }

    /**
     * The direct anti-regression: a lock whose dev half has been emptied — which
     * is exactly what `--no-dev` produces — must not read as a clean audit.
     */
    public function testACorpusWithNoDevelopmentPackagesFails(): void
    {
        $result = $this->runGate($this->payload(['advisories' => []]), $this->lock(120, 0));

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString(
            '::error::Only 0 require-dev package(s) in the corpus, below the floor of 40',
            $result['output'],
        );
        self::assertStringContainsString('Do NOT restore --no-dev', $result['output']);
    }

    public function testAMissingLockFails(): void
    {
        $result = $this->runGate($this->payload(['advisories' => []]), $this->workDir . '/absent.lock');

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('::error::composer.lock not found', $result['output']);
    }

    public function testAnUnparseableLockFails(): void
    {
        $path = $this->workDir . '/broken.lock';
        file_put_contents($path, '{ "packages": ');

        $result = $this->runGate($this->payload(['advisories' => []]), $path);

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('is not parseable JSON', $result['output']);
    }

    public function testALockWithoutAPackagesKeyFails(): void
    {
        $path = $this->workDir . '/notalock.lock';
        file_put_contents($path, '{"hello":"world"}');

        $result = $this->runGate($this->payload(['advisories' => []]), $path);

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('has no "packages" key', $result['output']);
    }

    // -----------------------------------------------------------------------
    // The blocking verdict — including the dev half, which is the point.
    // -----------------------------------------------------------------------

    /**
     * The exact 2026-08-06 finding, replayed: a HIGH advisory against a package
     * that lives in `require-dev`. Under the old gate this was invisible.
     */
    public function testAnAdvisoryAgainstADevelopmentDependencyBlocks(): void
    {
        $lock = $this->lock(85, 45, ['squizlabs/php_codesniffer' => ['scope' => 'dev', 'version' => '3.13.5']]);

        $result = $this->runGate(
            $this->payload([
                'advisories' => [
                    'squizlabs/php_codesniffer' => [[
                        'advisoryId'       => 'PKSA-vvvv-wwww-xxxx',
                        'packageName'      => 'squizlabs/php_codesniffer',
                        'affectedVersions' => '<3.13.6|>=4.0.0,<4.0.2',
                        'title'            => 'OS command injection in the diff/report writer',
                        'cve'              => 'CVE-2026-67434',
                        'link'             => 'https://github.com/advisories/GHSA-hmqg-cxww-wqhq',
                        'severity'         => 'high',
                    ]],
                ],
            ]),
            $lock,
        );

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString(
            '::error::Security audit FAILED — 1 advisory/ies affecting 1 package(s) (0 require, 1 require-dev).',
            $result['output'],
        );
        self::assertStringContainsString(
            'squizlabs/php_codesniffer [require-dev] locked at 3.13.5 (affected: <3.13.6|>=4.0.0,<4.0.2)',
            $result['output'],
        );
        self::assertStringContainsString('[HIGH] CVE-2026-67434 — OS command injection', $result['output']);
        self::assertStringContainsString('https://github.com/advisories/GHSA-hmqg-cxww-wqhq', $result['output']);
    }

    public function testAnAdvisoryAgainstARuntimeDependencyBlocksAndIsLabelledAsSuch(): void
    {
        $lock = $this->lock(85, 45, ['vendor/runtime-thing' => ['scope' => 'runtime', 'version' => '1.0.0']]);

        $result = $this->runGate(
            $this->payload([
                'advisories' => [
                    'vendor/runtime-thing' => [[
                        'advisoryId'       => 'PKSA-aaaa-bbbb-cccc',
                        'affectedVersions' => '<1.0.1',
                        'title'            => 'Remote code execution',
                        'cve'              => 'CVE-2026-00001',
                        'severity'         => 'critical',
                    ]],
                ],
            ]),
            $lock,
        );

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('(1 require, 0 require-dev)', $result['output']);
        self::assertStringContainsString('vendor/runtime-thing [require] locked at 1.0.0', $result['output']);
        self::assertStringContainsString('[CRITICAL] CVE-2026-00001', $result['output']);
    }

    /**
     * A succeeding control beside the failure: the same corpus, the same script,
     * an empty advisory set. Without this the red above could be produced by
     * anything at all.
     */
    public function testTheSameCorpusPassesWhenThereAreNoAdvisories(): void
    {
        $lock = $this->lock(85, 45, ['squizlabs/php_codesniffer' => ['scope' => 'dev', 'version' => '3.13.6']]);

        $result = $this->runGate($this->payload(['advisories' => [], 'abandoned' => []]), $lock);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('Security audit passed.', $result['output']);
        self::assertStringNotContainsString('::error::', $result['output']);
    }

    // -----------------------------------------------------------------------
    // Advisory-only findings are loud but do not block.
    // -----------------------------------------------------------------------

    public function testAbandonedPackagesWarnLoudlyButDoNotBlock(): void
    {
        $lock = $this->lock(85, 45, ['fgrosse/phpasn1' => ['scope' => 'runtime', 'version' => '2.5.0']]);

        $result = $this->runGate(
            $this->payload([
                'advisories' => [],
                'abandoned'  => ['fgrosse/phpasn1' => '', 'web-auth/metadata-service' => 'web-auth/webauthn-lib'],
            ]),
            $lock,
        );

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('::warning::2 abandoned package(s). ADVISORY ONLY', $result['output']);
        self::assertStringContainsString('fgrosse/phpasn1 [require] — no replacement suggested', $result['output']);
        self::assertStringContainsString(
            'web-auth/metadata-service [not in lock] — replaced by web-auth/webauthn-lib',
            $result['output'],
        );
        self::assertStringContainsString('Security audit passed.', $result['output']);
    }

    public function testAnAdvisoryStillBlocksWhenAbandonedPackagesArePresent(): void
    {
        $lock = $this->lock(85, 45, ['vendor/broken' => ['scope' => 'dev', 'version' => '2.0.0']]);

        $result = $this->runGate(
            $this->payload([
                'advisories' => [
                    'vendor/broken' => [[
                        'advisoryId' => 'PKSA-dddd-eeee-ffff',
                        'title'      => 'Path traversal',
                        'cve'        => 'CVE-2026-00002',
                        'severity'   => 'medium',
                    ]],
                ],
                'abandoned' => ['fgrosse/phpasn1' => ''],
            ]),
            $lock,
        );

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('::warning::1 abandoned package(s)', $result['output']);
        self::assertStringContainsString('::error::Security audit FAILED', $result['output']);
    }

    public function testConfigIgnoredAdvisoriesAreReportedAsAcknowledgedNotHidden(): void
    {
        $lock = $this->lock(85, 45, ['vendor/unfixable' => ['scope' => 'runtime', 'version' => '3.1.0']]);

        $result = $this->runGate(
            $this->payload([
                'advisories'         => [],
                'ignored-advisories' => [
                    'vendor/unfixable' => [[
                        'advisoryId' => 'PKSA-gggg-hhhh-iiii',
                        'title'      => 'Denial of service',
                        'cve'        => 'CVE-2026-00003',
                        'severity'   => 'low',
                    ]],
                ],
            ]),
            $lock,
        );

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString(
            '::notice::1 security advisory/ies affecting 1 package(s) are IGNORED',
            $result['output']
        );
        self::assertStringContainsString('vendor/unfixable [require] locked at 3.1.0', $result['output']);
    }

    // -----------------------------------------------------------------------
    // Cannot-measure must fail, never skip.
    // -----------------------------------------------------------------------

    public function testAnUnreachableAdvisoryRepositoryFails(): void
    {
        $result = $this->runGate(
            $this->payload(['advisories' => [], 'unreachable-repositories' => ['https://repo.packagist.org']]),
            $this->lock(85, 45),
        );

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString(
            '::error::1 advisory repository/ies were unreachable — the audit measured nothing.',
            $result['output'],
        );
    }

    public function testAMissingPayloadFails(): void
    {
        $result = $this->runGate($this->workDir . '/absent.json', $this->lock(85, 45));

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('does not exist', $result['output']);
    }

    public function testAnEmptyPayloadFails(): void
    {
        $path = $this->workDir . '/empty.json';
        file_put_contents($path, "   \n");

        $result = $this->runGate($path, $this->lock(85, 45));

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('is empty', $result['output']);
    }

    public function testAnUnparseablePayloadFails(): void
    {
        $path = $this->workDir . '/bad.json';
        file_put_contents($path, 'not json at all');

        $result = $this->runGate($path, $this->lock(85, 45));

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('is not parseable JSON', $result['output']);
    }

    public function testAPayloadWithoutAnAdvisoriesKeyFails(): void
    {
        $result = $this->runGate($this->payload(['something-else' => []]), $this->lock(85, 45));

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('has no "advisories" key', $result['output']);
    }

    public function testAMissingComposerBinaryFailsInsteadOfSkipping(): void
    {
        $result = $this->runGate(null, $this->lock(85, 45), ['COMPOSER_BIN' => '/nonexistent/composer']);

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString(
            '::error::Cannot run "/nonexistent/composer --version"',
            $result['output'],
        );
    }

    // -----------------------------------------------------------------------
    // Helpers.
    // -----------------------------------------------------------------------

    /**
     * Run the gate as a subprocess and capture its merged output and exit code.
     *
     * @param array<string, string> $env
     *
     * @return array{exit: int, output: string}
     */
    private function runGate(?string $payloadPath, ?string $lockPath = null, array $env = []): array
    {
        $command = ['php', self::SCRIPT];

        if ($payloadPath !== null) {
            $command[] = $payloadPath;

            if ($lockPath !== null) {
                $command[] = $lockPath;
            }
        } elseif ($lockPath !== null) {
            $command[] = '';
            $command[] = $lockPath;
        }

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes       = [];
        $process     = proc_open(
            $command,
            $descriptors,
            $pipes,
            null,
            $env === [] ? null : $env + ['PATH' => (string) getenv('PATH')]
        );

        self::assertIsResource($process, 'could not start the gate script');

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'output' => $stdout . $stderr];
    }

    /**
     * Write a `composer audit --format=json` payload and return its path.
     *
     * @param array<string, mixed> $payload
     */
    private function payload(array $payload): string
    {
        $path = $this->workDir . '/audit-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($path, (string) json_encode($payload, JSON_PRETTY_PRINT));

        return $path;
    }

    /**
     * Write a synthetic `composer.lock` with a known package count.
     *
     * Named packages are placed in the requested section so the scope label can
     * be asserted; the filler packages make up the remainder of the count.
     *
     * @param array<string, array{scope: 'runtime'|'dev', version: string}> $named
     */
    private function lock(int $runtime, int $dev, array $named = []): string
    {
        $sections = ['packages' => [], 'packages-dev' => []];

        foreach ($named as $name => $spec) {
            $key                = $spec['scope'] === 'dev' ? 'packages-dev' : 'packages';
            $sections[$key][]   = ['name' => $name, 'version' => $spec['version']];
        }

        while (count($sections['packages']) < $runtime) {
            $sections['packages'][] = [
                'name' => 'filler/runtime-' . count($sections['packages']),
                'version' => '1.0.0'
            ];
        }

        while (count($sections['packages-dev']) < $dev) {
            $sections['packages-dev'][] = [
                'name' => 'filler/dev-' . count($sections['packages-dev']),
                'version' => '1.0.0'
            ];
        }

        $path = $this->workDir . '/composer-' . bin2hex(random_bytes(4)) . '.lock';
        file_put_contents($path, (string) json_encode($sections, JSON_PRETTY_PRINT));

        return $path;
    }

    /**
     * The workflow with every YAML comment removed.
     *
     * The replacement step documents what `--no-dev` used to do, so a raw
     * substring search over this file would match the explanation rather than
     * the configuration.
     */
    private function workflowWithoutComments(): string
    {
        $lines = explode("\n", (string) file_get_contents(self::WORKFLOW));
        $kept  = [];

        foreach ($lines as $line) {
            $stripped = preg_replace('/(?:^|\s)#.*$/', '', $line);
            $kept[]   = is_string($stripped) ? $stripped : $line;
        }

        return implode("\n", $kept);
    }

    /**
     * The audit flags the script declares, read from the constant itself.
     *
     * @return list<string>
     */
    private function auditArguments(): array
    {
        $source = (string) file_get_contents(self::SCRIPT);

        self::assertSame(
            1,
            preg_match('/const AUDIT_ARGUMENTS = \[(.*?)\];/s', $source, $matches),
            'scripts/security-audit-check.php must declare AUDIT_ARGUMENTS — this test reads the '
            . 'declared flags rather than pattern-matching prose, and an absent constant is a '
            . 'silent pass otherwise.',
        );

        $flags = [];

        if (preg_match_all("/'([^']+)'/", $matches[1], $found) > 0) {
            $flags = $found[1];
        }

        self::assertNotSame(
            [],
            $flags,
            'AUDIT_ARGUMENTS parsed to an empty list — the assertion below would be vacuous.'
        );

        return $flags;
    }
}
