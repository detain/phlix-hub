<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Support;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

use function array_diff;
use function array_filter;
use function array_keys;
use function array_slice;
use function array_values;
use function count;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function in_array;
use function is_array;
use function is_finite;
use function is_numeric;
use function is_string;
use function json_decode;
use function realpath;
use function sort;
use function sprintf;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function substr_count;
use function unlink;

/**
 * S468 — the committed LPT packing input must track the Unit suite, loudly.
 *
 * ## Why this guard exists
 *
 * `scripts/parallel/test-durations.json` is the LPT input the parallel PHPUnit
 * runner reads at CI time. The runner's own docblock says it "must track the
 * suite", yet nothing in CI or the suite ever re-derives the Unit inventory and
 * compares it against the cache's key set. The damage of silence is concrete:
 * an inventory file with no cache entry sorts and packs at a cost of `0.0`
 * (`($durations[$f] ?? 0.0)` in ParallelTestRunner's packing loop), so a
 * forgotten file lands free on top of an already-busy shard, and CI stays green
 * while packing degrades — the silent-drift class S253/S184 guards target.
 * `blessDurationCache` already refuses a partial inventory, but only AT BLESS
 * TIME; between blesses the cache and the suite drift apart and nothing
 * notices. This guard closes that window: the moment the cache stops matching
 * the inventory, the Unit suite goes red carrying the exact regenerate
 * incantation from the runner docblock.
 *
 * ## What counts as drift here (and what deliberately does not)
 *
 * - MISSING key (file in inventory, not in cache) → RED. Discrete, silent, and
 *   the exact zero-cost failure the docblock names. Non-negotiable.
 * - STALE key (cache entry, no such file) → RED. The cache is a faithful
 *   projection of the inventory or it is stale: `blessDurationCache` (its only
 *   sanctioned writer) cannot emit one, so a stale key means a hand-edit or an
 *   unblessed deletion/rename. Both directions are asserted so the match is set
 *   equality, not one-sided coverage a rename walks straight through.
 * - DURATION SKEW (recorded seconds vs reality) → DEFERRED by design, v1 is a
 *   pure inventory guard. A skew check needs a ground truth, and the only
 *   ground truth is a fresh serial profile run — box-clock timings asserted
 *   inside the suite, which is the flake class this estate refuses (cf. the
 *   S173-era rule against time-based assertions). Skew degrades packing
 *   balance; a missing key silently mis-packs. v1 catches the discrete failure
 *   and stays deterministic; skew needs its own design pass, not a bolt-on.
 * - PROVENANCE RECENCY (`generatedFrom` predating a suite-changing commit) →
 *   SHAPE-ONLY by design. The stamp is a short sha; every hub CI checkout is
 *   the actions/checkout default with no fetch-depth override in ci.yml, so a
 *   `git merge-base --is-ancestor` probe cannot resolve arbitrary history in
 *   CI, and falling back to the date string re-introduces box-clock fragility.
 *   The guard checks the field exists and is non-empty, and says nothing more.
 *
 * ## Design note: re-implementation over reflection
 *
 * The inventory is re-walked here instead of invoking the runner's private
 * `inventoryFiles()` via Reflection: a guard wired to a private method reddens
 * on refactors instead of on drift. The shared RULE is pinned from its public
 * sources instead — the literal phpunit.xml Unit-suite grammar, the runner's
 * `DURATION_CACHE` path and both zero-cost defaults in its sort/pack lines —
 * so any change to what this guard mirrors must pass through this file
 * deliberately.
 *
 * Known limit (S345 rule 4, stated not hidden): this guard proves the KEY SET
 * tracks the suite. It does not re-time files; a cached 4.9s that is now 40s
 * passes v1. The zero-cost mis-packing of an un-cached file was the silent
 * failure the step measured; skew remains a visible-in-profile failure.
 *
 * | mutation                                        | this test |
 * | ----------------------------------------------- | --------- |
 * | drop a key from test-durations.json             | RED       |
 * | commit a new *Test.php without blessing         | RED       |
 * | add a cache key for a non-existent file         | RED       |
 * | rewrite the docblock's regenerate command       | RED       |
 * | move DURATION_CACHE or drop a 0.0 default       | RED       |
 * | weaken phpunit.xml's Unit excludes silently     | RED       |
 * | bypass the LPT runner in the CI job             | RED       |
 *
 * @package Phlix\Hub\Tests\Unit\Support
 */
final class DurationCacheTracksSuiteTest extends TestCase
{
    /**
     * Code-resident survival token for S468. Declared as PHP source so the merge
     * ritual's comment-stripped `--token` corpus (every tracked *.php through
     * php_strip_whitespace) finds it. Zero homes in any markdown.
     */
    public const SURVIVAL_TOKEN = 'S468DURGUARDX9L1';

    private const ROOT = __DIR__ . '/../../..';

    private const CACHE = self::ROOT . '/scripts/parallel/test-durations.json';

    private const RUNNER = self::ROOT . '/scripts/parallel/ParallelTestRunner.php';

    private const WORKFLOW = self::ROOT . '/.github/workflows/ci.yml';

    private const PHPUNIT_XML = self::ROOT . '/phpunit.xml';

    private const CHANGELOG = self::ROOT . '/CHANGELOG.md';

    /**
     * Floor for the Unit-file walk. Measured 215 at S468 filing (2026-09-11);
     * the floor exists only so a blind walk (moved tests/, broken path) cannot
     * make the coverage assertions vacuously true. It sits far below any
     * plausible deliberate reorganisation and far above zero.
     */
    private const MIN_EXPECTED_UNIT_FILES = 150;

    /**
     * The sanctioned repair, quoted verbatim from the runner docblock's
     * "REGENERATING THE DURATION CACHE" section. Printed in every drift failure
     * so the repair is copy-paste, never archaeology.
     */
    private const REGENERATE_INCANTATION = <<<'TXT'
        php -d max_execution_time=0 ./vendor/bin/phpunit --testsuite Unit,Integration \
          --coverage-clover /tmp/s458-coverage.xml --log-junit /tmp/s458-junit.xml
        php scripts/parallel/run-parallel-tests.php --bless-from-junit=/tmp/s458-junit.xml \
          --generated-from="$(git rev-parse --short HEAD) serial junit, pcov"
        TXT;

    /**
     * The docblock incantation split at its line-wraps. Each fragment is
     * asserted present in BOTH the runner source and REGENERATE_INCANTATION, so
     * the repair this guard prints cannot rot away from the repair the runner
     * sanctions (and vice versa).
     *
     * @var list<string>
     */
    private const REGENERATE_FRAGMENTS = [
        'php -d max_execution_time=0 ./vendor/bin/phpunit --testsuite Unit,Integration',
        '--coverage-clover /tmp/s458-coverage.xml --log-junit /tmp/s458-junit.xml',
        'php scripts/parallel/run-parallel-tests.php --bless-from-junit=/tmp/s458-junit.xml',
        '--generated-from="$(git rev-parse --short HEAD) serial junit, pcov"',
    ];

    /**
     * The positive-control probe path. It ends in Test.php because the whole
     * point is to prove the walk, the cache and the classifier have eyes for a
     * fresh un-cached Unit test file (S345 rule 3). It never persists: the test
     * that creates it deletes it and re-asserts the pipeline is clean after.
     */
    private const PROBE_RELATIVE = 'tests/Unit/Support/ZZDurationCacheGuardProbeTest.php';

    private const PROBE_SOURCE = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Phlix\Hub\Tests\Unit\Support;

        use PHPUnit\Framework\TestCase;

        /**
         * Transient control probe written by DurationCacheTracksSuiteTest during
         * the same run. If you are reading this in a working tree, the guard test
         * was killed mid-run: delete this file and re-run the suite.
         */
        final class ZZDurationCacheGuardProbeTest extends TestCase
        {
            public function testProbeIsInert(): void
            {
                self::assertTrue(true);
            }
        }

        PHP;

    /**
     * Resolve the repository root once, fail fast if the layout moved.
     */
    private static function root(): string
    {
        $root = realpath(self::ROOT);

        if (!is_string($root)) {
            throw new RuntimeException('the repository root does not resolve: ' . self::ROOT);
        }

        return $root;
    }

    /**
     * Re-derive the Unit inventory exactly as phpunit.xml defines it: every
     * *Test.php under tests/, minus tests/Integration, minus tests/E2E — the
     * same rule ParallelTestRunner::inventoryFiles() implements for packing.
     *
     * @return list<string> repository-relative paths, sorted
     */
    private static function unitInventoryFromDisk(): array
    {
        $root = self::root();

        $unit = [];
        $directory = new RecursiveDirectoryIterator($root . '/tests', FilesystemIterator::SKIP_DOTS);
        $iterator = new RecursiveIteratorIterator($directory);

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            if (!str_ends_with($file->getFilename(), 'Test.php')) {
                continue;
            }

            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

            if (str_starts_with($rel, 'tests/Integration/') || str_starts_with($rel, 'tests/E2E/')) {
                continue;
            }

            $unit[] = $rel;
        }

        sort($unit);

        return $unit;
    }

    /**
     * Parse the committed cache at the boundary and hand trusted shapes to the
     * assertions — exactly the two keys `blessDurationCache` writes, in that
     * order, with finite non-negative durations. Structural violations throw:
     * they are not drift to reason about, they are a cache this guard cannot
     * certify anything about (fail fast, Law 4).
     *
     * @return array{generatedFrom: string, unit: array<string, float>}
     */
    private static function parseDurationCache(): array
    {
        if (!file_exists(self::CACHE)) {
            throw new RuntimeException(
                'the duration cache is absent at ' . self::CACHE . ' — the runner exits 2 without '
                . 'it; ' . self::repairHint()
            );
        }

        $decoded = json_decode((string) file_get_contents(self::CACHE), true);

        if (!is_array($decoded)) {
            throw new RuntimeException(
                'the duration cache does not decode to a JSON object; ' . self::repairHint()
            );
        }

        if (array_keys($decoded) !== ['generatedFrom', 'unit']) {
            throw new RuntimeException(
                'the duration cache must carry exactly the two keys blessDurationCache writes '
                . '(generatedFrom, unit), got: ' . implode(', ', array_keys($decoded)) . '; '
                . self::repairHint()
            );
        }

        $generatedFrom = $decoded['generatedFrom'];
        if (!is_string($generatedFrom) || $generatedFrom === '') {
            throw new RuntimeException('the provenance header must be a non-empty string; ' . self::repairHint());
        }

        $rawUnit = $decoded['unit'];
        if (!is_array($rawUnit)) {
            throw new RuntimeException('the unit node must be a JSON object; ' . self::repairHint());
        }

        /** @var array<string, float> $unit */
        $unit = [];
        $malformed = [];

        foreach ($rawUnit as $file => $seconds) {
            if (!is_numeric($seconds) || !is_finite((float) $seconds) || (float) $seconds < 0.0) {
                $malformed[] = (string) $file;

                continue;
            }

            $unit[(string) $file] = (float) $seconds;
        }

        if ($malformed !== []) {
            throw new RuntimeException(
                'every cached duration must be a finite, non-negative number; offending keys: '
                . implode(', ', array_slice($malformed, 0, 10)) . '; ' . self::repairHint()
            );
        }

        return ['generatedFrom' => $generatedFrom, 'unit' => $unit];
    }

    /**
     * Pure classifier: inventory files the cache never heard of — the files the
     * runner would pack at zero cost.
     *
     * @param list<string>         $inventory
     * @param array<string, float> $cache
     *
     * @return list<string>
     */
    private static function filesMissingFromCache(array $inventory, array $cache): array
    {
        return array_values(array_diff($inventory, array_keys($cache)));
    }

    /**
     * Pure classifier: cache keys with no file behind them. blessDurationCache
     * cannot emit one, so each is evidence of a hand-edit or an unblessed
     * deletion/rename.
     *
     * @param list<string>         $inventory
     * @param array<string, float> $cache
     *
     * @return list<string>
     */
    private static function staleCacheKeys(array $inventory, array $cache): array
    {
        return array_values(array_diff(array_keys($cache), $inventory));
    }

    private static function repairHint(): string
    {
        return "regenerate the cache with:\n" . self::REGENERATE_INCANTATION;
    }

    /**
     * @param list<string> $names
     */
    private static function driftMessage(string $defect, array $names): string
    {
        return sprintf(
            'test-durations.json no longer tracks the Unit suite: %s (%d file(s): %s). '
            . 'An un-cached file is LPT-packed at cost 0.0 and a stale key packs a file that no '
            . 'longer exists — either way the committed packing input lies about the suite. %s',
            $defect,
            count($names),
            implode(', ', array_slice($names, 0, 10)),
            self::repairHint()
        );
    }

    private static function probePath(): string
    {
        return self::root() . '/' . self::PROBE_RELATIVE;
    }

    public function testTheInventoryWalkSeesWhatPhpunitWouldRun(): void
    {
        $inventory = self::unitInventoryFromDisk();

        // Anti-vacuity: a blind walk would make every coverage assertion below
        // trivially true. The floor turns "moved tests/" into a loud red.
        self::assertGreaterThanOrEqual(
            self::MIN_EXPECTED_UNIT_FILES,
            count($inventory),
            sprintf(
                'the Unit walk found only %d files (floor %d) — the guard is blind and must not '
                . 'be trusted to certify the cache',
                count($inventory),
                self::MIN_EXPECTED_UNIT_FILES
            )
        );

        $misclassified = array_values(array_filter(
            $inventory,
            static fn (string $file): bool => !str_ends_with($file, 'Test.php')
                || str_starts_with($file, 'tests/Integration/')
                || str_starts_with($file, 'tests/E2E/'),
        ));

        self::assertSame(
            [],
            $misclassified,
            'the walk must classify exactly like the phpunit.xml Unit suite grammar it mirrors'
        );
    }

    public function testTheGuardMirrorsPinnedPublicContracts(): void
    {
        // The re-implementation above is only honest while the public sources
        // still state this rule. Each pin reddens on a silent redefinition.
        $phpunitXml = (string) file_get_contents(self::PHPUNIT_XML);

        self::assertStringContainsString(
            '<directory suffix="Test.php">tests</directory>',
            $phpunitXml,
            'phpunit.xml must keep defining the Unit suite as "every Test.php under tests" — the '
            . 'inventory this guard walks derives from exactly that grammar'
        );
        self::assertStringContainsString('<exclude>tests/Integration</exclude>', $phpunitXml);
        self::assertStringContainsString('<exclude>tests/E2E</exclude>', $phpunitXml);

        $runner = (string) file_get_contents(self::RUNNER);

        self::assertStringContainsString(
            "DURATION_CACHE = __DIR__ . '/test-durations.json'",
            $runner,
            'the guard reads scripts/parallel/test-durations.json directly; if the runner moves '
            . 'its cache path, this file must move with it deliberately — not find nothing and '
            . 'certify the void'
        );

        // The harm mechanism the guard exists for: unknown files pack at zero cost.
        // If that default ever changes, this pin says so instead of the guard
        // silently defending a hazard that moved.
        self::assertStringContainsString('$durations[$b] ?? 0.0', $runner);
        self::assertStringContainsString('$durations[$f] ?? 0.0', $runner);
    }

    public function testTheRegenerateIncantationMatchesTheRunnerDocblock(): void
    {
        $runner = (string) file_get_contents(self::RUNNER);

        foreach (self::REGENERATE_FRAGMENTS as $fragment) {
            self::assertStringContainsString(
                $fragment,
                $runner,
                'the runner docblock no longer contains the sanctioned regenerate fragment — the '
                . 'incantation this guard prints must be updated FROM the docblock, not from memory'
            );
            self::assertStringContainsString(
                $fragment,
                self::REGENERATE_INCANTATION,
                'the incantation printed on drift must be the docblock text verbatim, never a '
                . 'paraphrase'
            );
        }

        self::assertStringContainsString(
            'must track the suite',
            $runner,
            'the docblock sentence this guard enforces must stay in the runner — if the contract '
            . 'leaves the docblock, the guard is retired deliberately, not drifted away'
        );
    }

    public function testEveryInventoryFileHasADurationEntry(): void
    {
        $missing = self::filesMissingFromCache(self::unitInventoryFromDisk(), self::parseDurationCache()['unit']);

        self::assertSame([], $missing, self::driftMessage('no duration entry for files the suite runs', $missing));
    }

    public function testTheCacheHoldsNoStaleKeys(): void
    {
        $stale = self::staleCacheKeys(self::unitInventoryFromDisk(), self::parseDurationCache()['unit']);

        self::assertSame([], $stale, self::driftMessage('cache entries for files the suite no longer has', $stale));
    }

    public function testTheCiJobStillRunsTheSuiteThroughThePackingRunner(): void
    {
        // The guard matters only while CI consumes the cache. The PHPUnit job
        // drives the suite through the parallel runner with the canonical
        // phpunit argv after the ` -- ` separator.
        $workflow = (string) file_get_contents(self::WORKFLOW);

        self::assertStringContainsString(
            'scripts/parallel/run-parallel-tests.php -- ./vendor/bin/phpunit --testsuite Unit,Integration',
            $workflow,
            'the PHPUnit job must keep driving the suite through the LPT runner — if the runner is '
            . 'bypassed, this guard certifies an input nobody reads, and one of the two must be '
            . 'retired deliberately'
        );
    }

    public function testBothClassifiersActuallyBite(): void
    {
        // S345 rule 3, stage one: a "nothing matched" pass must be
        // distinguishable from a classifier that cannot see. Both mutation
        // controls run against the REAL parsed cache and the REAL walk, so the
        // pure functions are proven alive, not mocked.
        $inventory = self::unitInventoryFromDisk();
        $cache = self::parseDurationCache()['unit'];

        self::assertSame([], self::filesMissingFromCache($inventory, $cache), 'the tip must be clean');
        self::assertSame([], self::staleCacheKeys($inventory, $cache), 'the tip must be clean');

        $victim = $inventory[0];
        $mutated = $cache;
        unset($mutated[$victim]);

        self::assertSame([$victim], self::filesMissingFromCache($inventory, $mutated));
        self::assertSame([], self::staleCacheKeys($inventory, $mutated));

        $ghost = 'tests/Unit/Support/SyntheticStaleDurationControlTest.php';
        self::assertFalse(in_array($ghost, $inventory, true), 'the ghost must not exist on disk');

        $mutated[$ghost] = 0.5;
        self::assertSame([$ghost], self::staleCacheKeys($inventory, $mutated));
        self::assertSame([$victim], self::filesMissingFromCache($inventory, $mutated));
    }

    public function testAFreshUnCachedTestFileRedsTheGuard(): void
    {
        // S345 rule 3, stage two: prove end to end that a NEW un-cached test
        // file is seen — the walk includes it, the committed cache lacks it, the
        // classifier reports it — and that restoring the tree returns the
        // pipeline to empty. The probe exists only inside this try/finally.
        $probeAbsolute = self::probePath();

        self::assertFileDoesNotExist(
            $probeAbsolute,
            'a previous run left its control probe behind — delete ' . self::PROBE_RELATIVE
            . ' and re-run; this guard refuses to delete files it did not just create'
        );

        try {
            self::assertNotFalse(
                file_put_contents($probeAbsolute, self::PROBE_SOURCE),
                'the control probe could not be written — the guard cannot certify what it cannot plant'
            );

            $inventory = self::unitInventoryFromDisk();
            $cache = self::parseDurationCache()['unit'];

            self::assertContains(
                self::PROBE_RELATIVE,
                $inventory,
                'the inventory walk must see a freshly created Unit *Test.php — if it does not, '
                . 'the coverage assertion it feeds is blind'
            );
            self::assertArrayNotHasKey(
                self::PROBE_RELATIVE,
                $cache,
                'the committed cache must not already know a probe name it was never blessed for'
            );
            self::assertContains(
                self::PROBE_RELATIVE,
                self::filesMissingFromCache($inventory, $cache),
                'the missing-from-cache classifier must report the planted probe — this is the '
                . 'exact path whose failure message prints the regenerate incantation'
            );
        } finally {
            if (file_exists($probeAbsolute)) {
                unlink($probeAbsolute);
            }
        }

        self::assertFileDoesNotExist($probeAbsolute, 'the probe must not survive its own test');

        // Restoration control: the identical pipeline is empty again — the red
        // above was the probe and nothing else.
        $inventory = self::unitInventoryFromDisk();

        self::assertNotContains(self::PROBE_RELATIVE, $inventory);
        self::assertSame(
            [],
            self::filesMissingFromCache($inventory, self::parseDurationCache()['unit']),
            'after restoring, the classifier must return empty on the same tree it certifies in CI'
        );
    }

    public function testTheSurvivalTokenHasOneCodeHomeAndNoMarkdownHome(): void
    {
        // Home: this const, pinned by literal so a rename is a deliberate edit,
        // never a silent drift of the merge ritual's --token search key.
        self::assertSame(
            'S468DURGUARDX9L1',
            self::SURVIVAL_TOKEN,
            'the code-resident survival token literal is the merge ritual\'s --token search key'
        );

        // Zero homes in markdown: a doc copy would recreate the exact string the
        // survival check greps for and defeat it (S345 lesson). CHANGELOG is the
        // file every step is tempted to write this into.
        $changelog = (string) file_get_contents(self::CHANGELOG);

        self::assertStringNotContainsString(
            self::SURVIVAL_TOKEN,
            $changelog,
            'the survival token must NOT appear in CHANGELOG.md — prose describes the guard, the '
            . 'token lives only in code'
        );

        // Exactly two homes in this file: the declaration and the pin above.
        $self = (string) file_get_contents(__FILE__);

        self::assertSame(
            2,
            substr_count($self, self::SURVIVAL_TOKEN),
            'the survival token appears exactly twice in this file — its const declaration and '
            . 'its literal pin; a third copy is how a grep target recreates itself and defeats '
            . 'the survival check'
        );
    }
}
