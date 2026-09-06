<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * S306 — the static-analysis scope gate for phlix-hub.
 *
 * PHPStan (level 9) and Psalm (measured level 5) analyse `tests/` exactly like
 * `src/` and `scripts/`. Every layer of that arrangement is load-bearing and
 * every layer was reachable by a silent edit: delete the `tests` entry from a
 * config `paths:`/`projectFiles` block, pass `--level` on the CI command line,
 * grow `excludePaths`, add an `ignoreErrors:` entry or a Psalm
 * `<IssueHandler>`/baseline, swap `|| true` into the workflow — in each case
 * the analysis job keeps reporting GREEN over a corpus that quietly stopped
 * containing the tests it was added to protect. This test parses the shipped
 * artefacts (both configs AND the CI workflow) so any of those edits reddens
 * `PHPUnit Test Suite` instead of shipping unnoticed.
 *
 * Mirrors phlix-server's tests/Unit/Support/StaticAnalysisScopeTest.php in
 * shape. Where the hub deliberately differs — one merged PHPStan config at
 * level 9 instead of the server's split src/tests configs at 9/2 — the reason
 * is recorded in phpstan.neon.dist's header: the hub's CI step passes no path
 * or level on the command line, so the config IS the scope, and level 9 over
 * tests/ was reached with zero findings and zero mutes.
 */
final class StaticAnalysisScopeTest extends TestCase
{
    /**
     * S306 lane survival token. Code-resident by design (plan P-1 keeps
     * tokens out of markdown); premerge/merge-under-lock verify this exact
     * literal in this exact file before the squash-merge is allowed.
     */
    public const SURVIVAL_TOKEN = 'S306HUBANALYSISX9K4';

    private const REPO = __DIR__ . '/../../..';

    private const WORKFLOW = self::REPO . '/.github/workflows/ci.yml';

    private const PHPSTAN_CONFIG = self::REPO . '/phpstan.neon.dist';

    private const PSALM_CONFIG = self::REPO . '/psalm.xml';

    /**
     * The single analysis corpus that genuinely cannot be analysed inside this
     * repository (S332 cross-repo dumper — see the written why in BOTH configs).
     */
    private const EXCLUDED_DUMPER = 'tests/Unit/Http/Controllers/Fixtures/dump-phlix-server-route-manifest.php';

    public function testTheSurvivalTokenIsResidentAndIntact(): void
    {
        self::assertSame('S306HUBANALYSISX9K4', self::SURVIVAL_TOKEN);
        self::assertMatchesRegularExpression('/^[A-Z0-9]{16,32}$/', self::SURVIVAL_TOKEN);
    }

    public function testTheAnalysisConfigsAndWorkflowAreReadable(): void
    {
        foreach ([self::WORKFLOW, self::PHPSTAN_CONFIG, self::PSALM_CONFIG] as $path) {
            self::assertIsString(file_get_contents($path), "{$path} must be readable");
        }
    }

    // ---------------------------------------------------------------- PHPStan

    public function testCiPhpstanStepRunsTheShippedConfigWithNoCliOverrides(): void
    {
        $run = $this->runStep('Run PHPStan');

        self::assertSame(
            './vendor/bin/phpstan analyze --no-progress --error-format=github',
            trim($run),
            'the CI step is pinned verbatim: any added argument is a scope/level escape '
            . "hatch, and the server taught us (S146/S128) that a guard-wrapped or "
            . 'soft-failing analysis step is theatre',
        );

        foreach (['--level', '-l ', '--configuration', '-c ', '|| true', '|| exit 0', '; true'] as $escape) {
            self::assertStringNotContainsString(
                $escape,
                $run,
                "a CLI {$escape} overrides or neuters the shipped config",
            );
        }
    }

    public function testPhpstanConfigAnalysesSrcScriptsAndTestsAtLevelNine(): void
    {
        $config = $this->contents(self::PHPSTAN_CONFIG);

        self::assertMatchesRegularExpression(
            '/^\s{4}level:\s*9\s*$/m',
            $config,
            'the hub pins level 9 over the WHOLE corpus',
        );

        self::assertSame(
            ['src', 'scripts', 'tests'],
            $this->neonListBlock($config, 'paths'),
            'dropping tests/ from paths must redden the suite, not just lose coverage silently',
        );
    }

    public function testPhpstanExcludePathsStayTheDocumentedAllowList(): void
    {
        $config = $this->contents(self::PHPSTAN_CONFIG);

        self::assertSame(
            [self::EXCLUDED_DUMPER],
            $this->neonListBlock($config, 'excludePaths'),
            'excludePaths is an allow-list with exactly one written-why entry; a second '
            . 'entry is a mute list by another name',
        );
    }

    public function testPhpstanMuteListIsEmptyAndStaysEmpty(): void
    {
        $config = $this->contents(self::PHPSTAN_CONFIG);

        self::assertMatchesRegularExpression(
            '/^\s*ignoreErrors:\s*\[\]\s*$/m',
            $config,
            'the inline empty list must stay',
        );

        self::assertDoesNotMatchRegularExpression(
            '/^\s*ignoreErrors:\s*(?:\#.*\n)*\s*-\s/m',
            $config,
            'an ignoreErrors entry list is a baseline by another name — fix findings at the source',
        );
    }

    public function testThePhpstanJobInstallsNoSwooleButThePsalmJobDoes(): void
    {
        $phpstanJob = $this->jobText('phpstan');
        $psalmJob = $this->jobText('psalm');

        self::assertMatchesRegularExpression(
            '/^\s+extensions: .*$/m',
            $phpstanJob,
            'the phpstan job must pin its extension list',
        );

        foreach (['swoole', 'uv', 'pcov', 'xdebug'] as $extra) {
            self::assertStringNotContainsString(
                $extra,
                $this->extensionsLine($phpstanJob),
                "CI's phpstan job deliberately lacks {$extra} — a local [OK] with it "
                . 'loaded does not predict CI (S128)',
            );
        }

        self::assertStringContainsString(
            'swoole',
            $this->extensionsLine($psalmJob),
            'Psalm resolves \Swoole\Coroutine\Channel reflectively; removing swoole '
            . "from the psalm job's extensions turns the green gate red",
        );
    }

    public function testEveryPhpstanConfiguredStubAndBootstrapFileExists(): void
    {
        $config = $this->contents(self::PHPSTAN_CONFIG);

        foreach (['bootstrapFiles', 'stubFiles'] as $block) {
            foreach ($this->neonListBlock($config, $block) as $entry) {
                self::assertFileExists(
                    self::REPO . '/' . $entry,
                    "phpstan.neon.dist references {$block} entry '{$entry}' but the file is gone — the config lies",
                );
            }
        }
    }

    // ----------------------------------------------------------------- Psalm

    public function testCiPsalmStepRunsTheShippedConfigWithNoCliOverrides(): void
    {
        $run = $this->runStep('Run Psalm');

        self::assertSame(
            './vendor/bin/psalm --no-progress --show-info=false',
            trim($run),
            'the CI step is pinned verbatim: --error-level or --config on the CLI would '
            . 'let the shipped, measured level drift away from what CI runs',
        );

        foreach (['--error-level', '--config', '|| true', '|| exit 0', '; true'] as $escape) {
            self::assertStringNotContainsString(
                $escape,
                $run,
                "a CLI {$escape} overrides or neuters the shipped config",
            );
        }
    }

    public function testPsalmConfigCoversTheCorpusAtTheMeasuredLevel(): void
    {
        $config = $this->contents(self::PSALM_CONFIG);

        self::assertMatchesRegularExpression(
            '/errorLevel="5"/',
            $config,
            'level 5 is the measured, documented pin (ladder in the config header); '
            . 'raising strictness further must update the pin, not mute it',
        );

        $projectFiles = $this->xmlBlock($config, 'projectFiles');

        self::assertSame(
            ['src', 'scripts', 'tests'],
            $this->xmlDirectoryNames($projectFiles),
            'dropping the tests directory from psalm.xml must redden the suite, not just lose coverage silently',
        );

        self::assertSame(
            [self::EXCLUDED_DUMPER],
            $this->xmlFileNames($projectFiles),
            'the psalm ignoreFiles list is an allow-list with exactly one written-why entry',
        );
    }

    public function testPsalmHasNoIssueHandlerAndNoBaseline(): void
    {
        // Comments excluded: the config's own prose quotes the element name it forbids.
        $config = (string) preg_replace('/<!--.*?-->|<\?.*?\?>/s', '', $this->contents(self::PSALM_CONFIG), -1);

        self::assertStringNotContainsString(
            '<IssueHandler',
            $config,
            'a per-issue-type suppress block is the psalm equivalent of a mute list — '
            . 'fix test code at the source (S306 policy)',
        );

        self::assertFileDoesNotExist(
            self::REPO . '/psalm-baseline.xml',
            'a baseline would reproduce the "gate that proves nothing" defect S146 removed on phlix-server',
        );
    }

    public function testTheLadderEvidenceStaysInThePsalmConfig(): void
    {
        $config = $this->contents(self::PSALM_CONFIG);

        foreach (
            [
                'level 1: 826', 'level 2: 623', 'level 3: 177', 'level 4: 155',
                'level 5:  30', 'level 6:  24', 'level 7:   7',
            ] as $measurement
        ) {
            self::assertStringContainsString(
                $measurement,
                $config,
                "the measured ladder evidence '{$measurement}' was edited out — "
                . 're-measure and record honestly instead of deleting the proof',
            );
        }
    }

    // ------------------------------------------------------- corpus presence

    public function testTheTestsCorpusIsActuallyPopulated(): void
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            self::REPO . '/tests',
            \FilesystemIterator::SKIP_DOTS,
        ));

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }

            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        self::assertGreaterThan(
            250,
            $files,
            'tests/ collapsed below 250 PHP files — the analysers would go green over a shrunk corpus',
        );
    }

    // ------------------------------------------------------------- plumbing

    private function contents(string $path): string
    {
        $raw = file_get_contents($path);
        self::assertIsString($raw, "{$path} must be readable");

        return $raw;
    }

    /**
     * The `run:` body of the uniquely-named workflow step.
     */
    private function runStep(string $stepName): string
    {
        $lines = explode("\n", $this->contents(self::WORKFLOW));
        $hits = [];

        foreach ($lines as $index => $line) {
            if (trim($line) !== "- name: {$stepName}") {
                continue;
            }

            for ($probe = $index + 1; $probe < count($lines) && $probe < $index + 4; $probe++) {
                if (preg_match('/^\s*run:\s*(.+)$/', $lines[$probe], $match) === 1) {
                    $hits[] = $match[1];
                    break;
                }
            }
        }

        self::assertCount(1, $hits, "exactly one CI step must be named '{$stepName}' and carry a run: line");

        return $hits[0];
    }

    /**
     * The workflow text of one job, from its `^  {key}:` header to the next
     * 2-space job header.
     */
    private function jobText(string $jobKey): string
    {
        $raw = $this->contents(self::WORKFLOW);

        if (preg_match('/^  ' . preg_quote($jobKey, '/') . ':\n(?:.*\n)*?(?=^  \w)/m', $raw, $job) !== 1) {
            self::fail("the workflow must contain exactly one job '{$jobKey}' followed by another job header");
        }

        return $job[0];
    }

    private function extensionsLine(string $jobText): string
    {
        if (preg_match('/^\s+extensions: ([^\n]+)$/m', $jobText, $match) !== 1) {
            self::fail('the job must pin its extensions list');
        }

        return $match[1];
    }

    /**
     * Entries of a `key:`-introduced NEON sequence block (four-space key,
     * eight-space dashes), in file order. Prose lives in `#` comments, so a
     * list line is always `        - value`.
     *
     * @return list<string>
     */
    private function neonListBlock(string $config, string $key): array
    {
        $pattern = '/^\s{4}' . preg_quote($key, '/') . ":\n((?:(?:\s{8}-\s+\S+|\s+#\S[^\n]*)\n)+)/m";
        if (preg_match($pattern, $config, $block) !== 1) {
            self::fail("phpstan.neon.dist must declare a '{$key}:' list block");
        }

        preg_match_all('/^\s{8}-\s+(\S+)\s*$/m', $block[1], $entries);

        return $entries[1];
    }

    /**
     * @return array{0: string} the inner text of <element>…</element>
     */
    private function xmlBlock(string $config, string $element): array
    {
        $pattern = '#<' . $element . '>(.*?)</' . $element . '>#s';
        if (preg_match($pattern, $config, $match) !== 1) {
            self::fail("psalm.xml must declare <{$element}>");
        }

        return [$match[1]];
    }

    /**
     * <directory> names directly under projectFiles but NOT inside its
     * <ignoreFiles> child (vendor stays out of the asserted corpus list).
     *
     * @param array{0: string} $projectFiles
     *
     * @return list<string>
     */
    private function xmlDirectoryNames(array $projectFiles): array
    {
        $body = (string) preg_replace('#<ignoreFiles>.*?</ignoreFiles>#s', '', $projectFiles[0]);

        preg_match_all('#<directory name="([^"]+)"\s*/>#s', $body, $names);

        return $names[1];
    }

    /**
     * <file> entries inside projectFiles/<ignoreFiles>.
     *
     * @param array{0: string} $projectFiles
     *
     * @return list<string>
     */
    private function xmlFileNames(array $projectFiles): array
    {
        if (preg_match('#<ignoreFiles>(.*?)</ignoreFiles>#s', $projectFiles[0], $ignored) !== 1) {
            return [];
        }

        preg_match_all('#<file name="([^"]+)"\s*/>#s', $ignored[1], $names);

        return $names[1];
    }
}
