<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

use function array_merge;
use function bin2hex;
use function dechex;
use function file_put_contents;
use function glob;
use function implode;
use function is_file;
use function json_encode;
use function is_string;
use function mkdir;
use function preg_match_all;
use function proc_open;
use function random_bytes;
use function sprintf;
use function str_contains;
use function str_pad;
use function stream_get_contents;
use function substr_count;
use function sys_get_temp_dir;
use function unlink;

/**
 * S181 — behaviour of `scripts/report-ui-pin-skew.php`, by execution.
 *
 * ## The defect this pins shut
 *
 * Four in-estate consumers pin `@phlix/ui` by tag on TWO syntaxes. S181
 * measured a windows pin 17 minors behind with every gate green, the skew
 * WIDENING inside a single day because the rollout sweep could only see the
 * tarball form. The step's landmine is precise: "a sweep that silently returns
 * 3-of-4 is exactly the failure this step exists to remove". So these tests do
 * not grade table wording — they grade the failure-class contract:
 *
 * | scenario                                       | required outcome       |
 * | ---------------------------------------------- | ---------------------- |
 * | four known pins, both syntaxes                  | exit 0, 4/4 enumerated |
 * | planted SKEW (v0.81.0 under a newer live tag)   | STALE row, exit 1      |
 * | planted 5th pin in a THIRD syntax               | UNMATCHED row, exit 1  |
 * | planted ALIASED pin (key renamed, value same)   | found via value scan   |
 * | required pin VANISHED from a consumer           | MISSING row, exit 1    |
 * | pin ref is a branch / prerelease                | NOT-A-RELEASE, exit 1  |
 * | source unreadable / tag ladder empty            | exit 1, loud message   |
 *
 * ## Why everything is a fixture — including the v0.81.0
 *
 * The live estate skew measured ZERO on 2026-09-12 (all four pins at v0.99.1),
 * so the step's literal AC "the v0.81.0 skew is reported" cannot be satisfied
 * against the real repos without hardcoding a skew figure — the exact rot the
 * step itself warns about ("any skew number … rots within days"). It is
 * satisfied HERE instead: v0.81.0 is pinned inside a throwaway fixture with its
 * own synthetic ladder, and the script must COMPUTE "4 release(s) behind" from
 * fixture data at run time. No version number in these tests describes the
 * live estate; the planted-synthetic world proves red-ability, and the CI job
 * proves liveness against the real tags.
 *
 * Every test passes `--package-json` overrides plus `--tags-file` seams, so
 * the script performs NO network I/O inside the suite — hermetic by
 * construction, like {@see SecurityAuditCheckTest}'s payload files.
 *
 * @package Phlix\Hub\Tests\Unit\Support
 */
final class UiPinSkewReporterTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../../../scripts/report-ui-pin-skew.php';

    /** The S181 provenance stamp — asserted in OUTPUT so a neutered script fails here. */
    private const STAMP = 'S181PINSKEWX9Q2';

    private const TARBALL_99 = 'https://github.com/detain/phlix-ui/archive/refs/tags/v0.99.1.tar.gz';

    private string $workDir = '';

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/hub-s181-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($dir, 0o700, true), 'temp dir for the pin fixtures');
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

        rmdir($this->workDir);
    }

    public function testPositiveControlEnumeratesAllFourPinsInBothSyntaxes(): void
    {
        $result = $this->report($this->args());

        self::assertSame(0, $result['exit'], 'a zero-skew fixture world must exit green: ' . $result['output']);
        $out = $result['output'];

        foreach (['phlix-hub', 'phlix-server', 'phlix-windows-client', 'phlix-tizen-client'] as $label) {
            self::assertStringContainsString(
                $label,
                $out,
                "the sweep must enumerate $label — a silent 3-of-4 is exactly the S181 failure class",
            );
        }

        self::assertSame(
            2,
            substr_count($out, 'tarball'),
            'exactly the two tarball pins (hub, server) must classify as tarball',
        );
        self::assertSame(
            3,
            substr_count($out, 'github:'),
            'windows + tizen @phlix/ui and the windows @phlix/contracts pin must classify as github:',
        );
        self::assertSame(
            4,
            preg_match_all('/@phlix\/ui\s+\S+\s+v0\.99\.1\s+v0\.99\.1\s+0\s+OK/', $out),
            'all four @phlix/ui pins must appear as zero-skew OK rows — both syntaxes, every consumer',
        );
        self::assertStringContainsString('ALL REQUIRED PINS CURRENT', $out);
        self::assertStringContainsString(self::STAMP, $out, 'the report footer must carry the step stamp');
        self::assertStringNotContainsString('::error::', $out);
    }

    public function testPlantedSyntheticSkewRedsTheReporterWithAComputedFigure(): void
    {
        $result = $this->report($this->args([
            'windows' => [
                'dependencies' => [
                    '@phlix/ui' => 'github:detain/phlix-ui#v0.81.0',
                    '@phlix/contracts' => 'github:detain/phlix-contracts#v0.4.6',
                ],
            ],
        ]));

        self::assertSame(
            1,
            $result['exit'],
            'a planted v0.81.0 pin under a v0.99.1 ladder MUST redden the reporter: ' . $result['output'],
        );
        $out = $result['output'];

        // The figure is COMPUTED from the synthetic ladder (v0.98.34, v0.98.35,
        // v0.99.0, v0.99.1 — four tags strictly newer than v0.81.0). Shorten
        // the fixture ladder and this assertion must change: no constant baked
        // anywhere describes the live estate.
        self::assertStringContainsString('v0.81.0 — 4 release(s) behind the live newest tag v0.99.1', $out);
        self::assertStringContainsString('STALE', $out);
        self::assertStringContainsString(
            '::error::' . self::STAMP,
            $out,
            'the stale row must arrive as a CI annotation, not only as a table cell',
        );

        // The table stays COMPLETE while red: the other three OK rows still
        // print, or the reporter degenerates into first-failure-abort and hides
        // the blast radius it exists to show.
        self::assertSame(
            3,
            preg_match_all('/@phlix\/ui\s+\S+\s+v0\.99\.1\s+v0\.99\.1\s+0\s+OK/', $out),
            'a red run must still enumerate the non-stale consumers',
        );
    }

    public function testPlantedThirdSyntaxFifthPinIsReportedUnmatchedNotDropped(): void
    {
        // The block's landmine, honoured: "add a fifth in a scratch file in a
        // THIRD syntax and confirm it is either found or explicitly reported as
        // unmatched". git+ssh:// is the planted third syntax; a bare file: link
        // is a fourth shape — every unknown must surface.
        $scratch = $this->pkg('scratch', [
            'devDependencies' => ['@phlix/ui' => 'git+ssh://git@github.com/detain/phlix-ui.git#v0.99.1'],
        ]);
        $filelink = $this->pkg('filelink', ['dependencies' => ['@phlix/ui' => 'file:../phlix-ui']]);

        $result = $this->report(array_merge($this->args(), [
            '--package-json=scratch=' . $scratch,
            '--package-json=filelink=' . $filelink,
        ]));

        self::assertSame(
            1,
            $result['exit'],
            'an unclassifiable syntax is red, never a silently skipped row: ' . $result['output'],
        );
        $out = $result['output'];

        self::assertStringContainsString(
            'git+ssh://git@github.com/detain/phlix-ui.git#v0.99.1',
            $out,
            'the planted value must appear verbatim in its UNMATCHED row',
        );
        self::assertStringContainsString('file:../phlix-ui', $out);
        self::assertSame(
            2,
            preg_match_all('/UNMATCHED {2,}(?:←|$)/m', $out),
            'both unknown-syntax pins must carry an UNMATCHED verdict in their own row',
        );
        self::assertStringContainsString(
            'scratch (extra)',
            $out,
            'the extra consumer must be labelled, not merged into the known four',
        );
        self::assertStringContainsString('Update the classifier DELIBERATELY', $out);
    }

    public function testAliasedPinIsFoundByTheValueScanAndNamed(): void
    {
        // Dependency key renamed so the key-based scan cannot see it; the value
        // still references detain/phlix-ui — the alias scan must enumerate it,
        // name the alias, and grade it (planted stale so it is unmistakable).
        $result = $this->report($this->args([
            'windows' => [
                'dependencies' => ['ui-under-alias' => self::TARBALL_99],
                'devDependencies' => ['ui-alias-stale' => 'github:detain/phlix-ui#v0.98.34'],
            ],
        ]));

        $out = $result['output'];
        self::assertSame(1, $result['exit'], 'the stale alias alone must redden the run: ' . $out);
        self::assertStringContainsString(
            'found via alias key "ui-alias-stale"',
            $out,
            'the value scan must NAME the aliased key instead of dropping it',
        );
        self::assertStringContainsString(
            'found via alias key "ui-under-alias"',
            $out,
            'even the OK alias row must disclose the rename — visibility over tidiness',
        );
        self::assertStringContainsString('STALE', $out);
    }

    public function testVanishedRequiredPinFailsInsteadOfShorteningTheTable(): void
    {
        $result = $this->report($this->args([
            'server' => ['dependencies' => ['vue' => '^3.5.0']],
        ]));

        self::assertSame(
            1,
            $result['exit'],
            'a consumer whose required pin vanished IS the silent 3-of-4: ' . $result['output'],
        );
        self::assertStringContainsString('MISSING', $result['output']);
        self::assertStringContainsString('phlix-server no longer carries a @phlix/ui pin', $result['output']);
    }

    public function testBranchAndPrereleaseRefsAreNotReleaseTags(): void
    {
        $result = $this->report($this->args([
            'windows' => [
                'dependencies' => [
                    '@phlix/ui' => 'github:detain/phlix-ui#master',
                    '@phlix/contracts' => 'github:detain/phlix-contracts#v0.4.6',
                ],
            ],
            'tizen' => ['dependencies' => ['@phlix/ui' => 'github:detain/phlix-ui#v1.0.0-rc.1']],
        ]));

        self::assertSame(1, $result['exit']);
        self::assertSame(
            2,
            substr_count($result['output'], 'NOT-A-RELEASE'),
            'a branch ref and a prerelease ref must BOTH trip NOT-A-RELEASE — the ladder carries stable releases only',
        );
    }

    public function testUnreadableSourceFailsLoudly(): void
    {
        $missing = $this->workDir . '/never-written.json';

        $result = $this->report(array_merge($this->args(), ['--package-json=hub=' . $missing]));

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('not a readable file', $result['output']);
    }

    public function testEmptyTagLadderFailsLoudly(): void
    {
        $emptyLadder = $this->workDir . '/junk-tags.txt';
        file_put_contents($emptyLadder, "abc\trefs/heads/master\ndef\trefs/tags/not-a-version\n");

        $result = $this->report(array_merge($this->args(), ['--tags-file=' . $emptyLadder]));

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('tag list for @phlix/ui is empty', $result['output']);
    }

    // ---- fixture builders ----------------------------------------------------

    /**
     * The zero-skew argument set: every consumer overridden to a fixture, both
     * tag ladders from fixture files — no network, no real pins. Each entry of
     * $overrides replaces that consumer's default fixture sections wholesale
     * (later repeated --package-json values win in the script, and the script's
     * hub row still reads THIS checkout's real web-ui/package.json unless
     * overridden — so hub is overridden here like every other consumer).
     *
     * @param array<string, array<string, array<string, string>>> $overrides
     *
     * @return list<string>
     */
    private function args(array $overrides = []): array
    {
        $defaults = [
            'hub' => ['dependencies' => ['@phlix/ui' => self::TARBALL_99]],
            'server' => ['dependencies' => ['@phlix/ui' => self::TARBALL_99]],
            'windows' => [
                'dependencies' => [
                    '@phlix/ui' => 'github:detain/phlix-ui#v0.99.1',
                    '@phlix/contracts' => 'github:detain/phlix-contracts#v0.4.6',
                ],
            ],
            'tizen' => ['dependencies' => ['@phlix/ui' => 'github:detain/phlix-ui#v0.99.1']],
        ];

        $args = [];
        foreach ($defaults as $consumer => $sections) {
            $sections = $overrides[$consumer] ?? $sections;
            $args[] = sprintf('--package-json=%s=%s', $consumer, $this->pkg($consumer, $sections));
        }

        $args[] = '--tags-file=' . $this->tagsFixture(
            'ui-tags.txt',
            ['v0.81.0', 'v0.98.34', 'v0.98.35', 'v0.99.0', 'v0.99.1', 'branch-ref'],
        );
        $args[] = '--contracts-tags-file=' . $this->tagsFixture('contracts-tags.txt', ['v0.4.5', 'v0.4.6']);

        return $args;
    }

    /**
     * @param array<string, array<string, string>> $sections section => dependency map
     */
    private function pkg(string $name, array $sections): string
    {
        $json = ['name' => 'fixture-' . $name];
        foreach ($sections as $section => $deps) {
            $json[$section] = $deps;
        }

        $path = $this->workDir . '/' . $name . '.json';
        file_put_contents($path, (string) json_encode($json, JSON_PRETTY_PRINT));

        return $path;
    }

    /**
     * `git ls-remote --tags` shape: annotated tags appear twice, once peeled;
     * a name containing a dash is emitted as refs/heads/... to prove the
     * parser ignores non-tag refs.
     *
     * @param list<string> $names
     */
    private function tagsFixture(string $fileName, array $names): string
    {
        $lines = [];
        $i = 0;
        foreach ($names as $name) {
            $sha = str_pad(dechex($i++), 40, '0', STR_PAD_LEFT);
            if (str_contains($name, '-')) {
                $lines[] = $sha . "\t" . 'refs/heads/' . $name;
                continue;
            }

            $lines[] = $sha . "\trefs/tags/" . $name;
            $lines[] = $sha . "\trefs/tags/" . $name . '^{}';
        }

        $path = $this->workDir . '/' . $fileName;
        file_put_contents($path, implode("\n", $lines) . "\n");

        return $path;
    }

    /**
     * @param list<string> $scriptArgs
     *
     * @return array{exit: int, output: string}
     */
    private function report(array $scriptArgs): array
    {
        $command = array_merge(['php', self::SCRIPT], $scriptArgs);

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $process = proc_open($command, $descriptors, $pipes, $this->workDir);

        self::assertIsResource($process, 'could not start the reporter script');

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'output' => $stdout . $stderr];
    }
}
