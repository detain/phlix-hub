<?php

/**
 * S458 — parallel PHPUnit runner for the hub "PHPUnit Test Suite" CI job.
 *
 * WHY THIS SHAPE
 * --------------
 * The suite is not flat. A handful of classes carry almost all of the wall time
 * (measured at 419b796, single process, pcov: Unit 63.1s, Integration 30.8s; the
 * top five Unit files alone are 51s of the 63s). Round-robin by filename is
 * therefore worthless — whichever shard inherits PhpcsCorpusGateTest decides the
 * whole wall. So the Unit files are packed LPT (longest-processing-time-first)
 * against a committed duration cache.
 *
 * INTEGRATION IS NEVER SHARDED. Every tests/Integration/** case extends
 * RealDatabaseTestCase, which drops all tables, re-applies the migration chain
 * and asserts an empty schema around every test — all against ONE schema
 * ($HUB_TEST_DB_NAME). Two processes on that schema destroy each other. Running
 * the whole Integration suite as a single dedicated worker keeps it exactly as
 * safe as today (one owner of the schema) while the Unit shards — which touch no
 * real schema (no Unit case extends RealDatabaseTestCase; the only real-DB pool
 * helper, ConnectionPoolTestControl, is used solely by Integration/OAuth/
 * OAuthPruneTimerTest) — fan out across the remaining cores.
 *
 * THE ARGV CONTRACT (why the CI step still spells the canonical phpunit command)
 * -------------------------------------------------------------------------------
 * The S173 and S316 anti-tamper guards
 * (tests/Unit/Support/IntegrationDbCiWiringTest.php,
 * tests/Unit/Support/CoverageArtifactGateTest.php) parse ci.yml with literal
 * regexes pinned to `vendor/bin/phpunit … --coverage-clover coverage.xml` and
 * `… --log-junit junit.xml`. Those guards are the anti-tamper mechanism itself,
 * so the runner does NOT replace the canonical command — it RECEIVES it after
 * the `--` separator and drives one PHPUnit worker per bucket with the same
 * artifact semantics:
 *
 *   php scripts/parallel/run-parallel-tests.php -- \
 *     ./vendor/bin/phpunit --testsuite Unit,Integration --colors=always \
 *       --coverage-clover coverage.xml --log-junit junit.xml
 *
 * `--log-junit` and `--coverage-clover` name where the MERGED artifacts land
 * (relative paths resolve against the current directory, exactly as PHPUnit
 * resolves them); `--coverage-clover` being present is what enables per-worker
 * `--coverage-php` collection and the Clover merge. Anything the runner cannot
 * account for — an unknown flag, a different suite selection, a missing
 * `--log-junit` — is a hard exit at the boundary, never a silently dropped
 * argument that would change what the job proves.
 *
 * COVERAGE
 * --------
 * Each worker writes a php-code-coverage binary (--coverage-php); the runner
 * merges them with CodeCoverage::merge() and emits ONE Clover document, so the
 * existing S316 floor gate (scripts/assert-coverage-report.php: >= 70%
 * statements, >= 12000 statements, >= 150 files) reads the same artifact shape it
 * reads today. The per-bucket PHPUnit configs strip the <coverage><report> block
 * so no worker clobbers the shared coverage.xml / HTML mid-run.
 *
 * This class lives under scripts/, NOT src/, on purpose: phpunit.xml's <source>
 * counts every file under src/ as coverable, so a dev/CI tool placed there would
 * add a block of permanently-uncovered statements and push the S316 percentage
 * down. scripts/ is in the phpcs + phpstan + psalm corpora but not in coverage.
 *
 * REGENERATING THE DURATION CACHE
 * -------------------------------
 * test-durations.json is the LPT input and must track the suite. After adding,
 * deleting or renaming test files, regenerate it from ONE serial profile run:
 *
 *   php -d max_execution_time=0 ./vendor/bin/phpunit --testsuite Unit,Integration \
 *     --coverage-clover /tmp/s458-coverage.xml --log-junit /tmp/s458-junit.xml
 *   php scripts/parallel/run-parallel-tests.php --bless-from-junit=/tmp/s458-junit.xml \
 *     --generated-from="$(git rev-parse --short HEAD) serial junit, pcov"
 *
 * bless refuses to write unless every Unit file in the current inventory appears
 * in the profile — a cache that quietly forgot a file is a cache that packs it
 * at zero cost into an already-busy shard.
 */

declare(strict_types=1);

namespace Phlix\Hub\Scripts;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMNodeList;
use FilesystemIterator;
use ReflectionClass;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Report\Clover;
use SplFileInfo;

/**
 * Runs buckets of test files through independent PHPUnit workers and merges the
 * junit + php-code-coverage outputs back into the single artifacts the CI gates
 * already consume.
 *
 * A "job" (one launched bucket) is passed around as the shape
 * `array{id: int, cmd: string, log: string}` — declared inline at each signature so
 * level 9 sees a concrete shape and every field read needs no defensive cast.
 */
final class ParallelTestRunner
{
    private const ROOT = __DIR__ . '/../..';

    private const DURATION_CACHE = __DIR__ . '/test-durations.json';

    /** S458 lane ritual token — code-resident, mirrors the cs## closer precedent. */
    private const S458_RITUAL_TOKEN = 'CS458HUBPARX9U';

    /** The only suite selection this runner is allowed to implement. */
    private const CANONICAL_TESTSUITES = 'Unit,Integration';

    private const USAGE = <<<TXT
        Usage: php scripts/parallel/run-parallel-tests.php [runner options] -- <canonical phpunit argv>

        Runner options (all before the `--` separator):
          --unit-shards=N        parallel buckets for the Unit suite (default cores-1)
          --concurrency=N        max simultaneous phpunit processes (default cores)
          --cpu-set=LIST         taskset CPU list to pin every worker, e.g. 0-3
          --out-dir=DIR          where to write per-bucket intermediates/logs (default temp dir)
          --bless-from-junit=P   regenerate the committed duration cache from a serial junit
          --generated-from=S     provenance stamp written into the duration cache
          --help                 print this text

        Everything after `--` is the canonical PHPUnit argv of the CI step, e.g.
          ./vendor/bin/phpunit --testsuite Unit,Integration --colors=always \
            --coverage-clover coverage.xml --log-junit junit.xml
        --log-junit and --coverage-clover name the MERGED artifacts the runner writes;
        --coverage-clover being present is what turns coverage collection on.

        TXT;

    /**
     * @param list<string> $argv raw CLI arguments (including the script name)
     * @return int process exit code: 0 only when every worker exits green
     */
    public static function main(array $argv): int
    {
        $separator = array_search('--', $argv, true);
        $runnerArgs = $separator === false ? array_slice($argv, 1) : array_slice($argv, 1, $separator - 1);
        $phpunitArgs = $separator === false ? [] : array_slice($argv, $separator + 1);

        $options = self::parseRunnerOptions($runnerArgs);
        if ($options === null) {
            return 2;
        }

        if (($options['help'] ?? null) === true) {
            fwrite(STDOUT, self::USAGE . "\n");

            return 0;
        }

        $blessSource = $options['bless-from-junit'] ?? null;
        if (is_string($blessSource)) {
            $generatedFrom = $options['generated-from'] ?? null;

            return self::blessDurationCache($blessSource, is_string($generatedFrom) ? $generatedFrom : null);
        }

        $command = self::parseCanonicalArgv($phpunitArgs);
        if ($command === null) {
            fwrite(STDERR, self::USAGE . "\n");

            return 2;
        }

        $cores = self::detectCores();
        $unitShards = max(1, (int) ($options['unit-shards'] ?? max(1, $cores - 1)));
        $concurrency = max(1, (int) ($options['concurrency'] ?? $cores));
        $cpuSet = $options['cpu-set'] ?? null;
        $outDirRaw = $options['out-dir'] ?? null;
        $outDir = is_string($outDirRaw)
            ? $outDirRaw
            : sys_get_temp_dir() . '/phlix-hub-parallel-' . bin2hex(random_bytes(6));

        if (!is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
            fwrite(STDERR, "cannot create out dir: {$outDir}\n");

            return 2;
        }

        return self::run($outDir, $unitShards, $concurrency, is_string($cpuSet) ? $cpuSet : null, $command);
    }

    /**
     * Parse the runner's own `--key=value` flags into a trusted array.
     *
     * Hand-rolled rather than getopt() because the real CLI grammar is
     * `runner-opts -- phpunit-opts` and getopt()'s view of what sits after the
     * separator is the global argv, not this function's input. Every unknown or
     * malformed option is named on stderr and rejected here, at the boundary,
     * so nothing downstream has to guess what a flag meant.
     *
     * @param list<string> $args
     *
     * @return array<string, string|bool>|null null when any argument was rejected
     */
    private static function parseRunnerOptions(array $args): ?array
    {
        $valueFlags = [
            'unit-shards', 'concurrency', 'cpu-set', 'out-dir', 'bless-from-junit', 'generated-from',
        ];

        /** @var array<string, string|bool> $options */
        $options = [];

        foreach ($args as $arg) {
            if ($arg === '--help') {
                $options['help'] = true;

                continue;
            }

            if (preg_match('/^--([a-z-]+)=(.+)$/', $arg, $parts) === 1) {
                $key = $parts[1];
                if (!in_array($key, $valueFlags, true)) {
                    fwrite(STDERR, "unknown runner option: --{$key}\n");

                    return null;
                }
                $options[$key] = $parts[2];

                continue;
            }

            fwrite(STDERR, "malformed runner argument: {$arg} (runner options use --key=value, "
                . "everything for phpunit goes after ' -- ')\n");

            return null;
        }

        return $options;
    }

    /**
     * Parse the forwarded canonical PHPUnit argv into a trusted command shape.
     *
     * @param list<string> $args everything that followed the `--` separator
     *
     * @return array{phpunit: string, junit: string, clover: string|null, colors: bool}|null
     */
    private static function parseCanonicalArgv(array $args): ?array
    {
        if ($args === []) {
            fwrite(STDERR, "no canonical phpunit argv after ' -- ' — this runner is invoked as\n"
                . "  php scripts/parallel/run-parallel-tests.php -- ./vendor/bin/phpunit <canonical flags>\n");

            return null;
        }

        $binary = array_shift($args);
        if (basename($binary) !== 'phpunit') {
            fwrite(STDERR, "the forwarded argv must start with the phpunit binary, got: {$binary}\n");

            return null;
        }

        $junit = null;
        $clover = null;
        $colors = false;
        $testsuite = null;

        for ($i = 0; $i < count($args); $i++) {
            $flag = $args[$i];

            if ($flag === '--log-junit' || $flag === '--coverage-clover' || $flag === '--testsuite') {
                $value = $args[$i + 1] ?? null;
                if (!is_string($value) || str_starts_with($value, '--')) {
                    fwrite(STDERR, "{$flag} requires a value in the forwarded argv\n");

                    return null;
                }
                $i++;

                if ($flag === '--log-junit') {
                    if ($junit !== null) {
                        fwrite(STDERR, "duplicate --log-junit in the forwarded argv\n");

                        return null;
                    }
                    $junit = $value;
                } elseif ($flag === '--coverage-clover') {
                    if ($clover !== null) {
                        fwrite(STDERR, "duplicate --coverage-clover in the forwarded argv\n");

                        return null;
                    }
                    $clover = $value;
                } else {
                    if ($testsuite !== null) {
                        fwrite(STDERR, "duplicate --testsuite in the forwarded argv\n");

                        return null;
                    }
                    $testsuite = $value;
                }

                continue;
            }

            if ($flag === '--colors' || $flag === '--colors=always') {
                $colors = true;

                continue;
            }

            if (str_starts_with($flag, '--colors')) {
                fwrite(STDERR, "unsupported colors spelling: {$flag} (only --colors/--colors=always)\n");

                return null;
            }

            fwrite(STDERR, "the parallel runner does not forward flag: {$flag} — running plain "
                . "vendor/bin/phpunit instead keeps every flag in play\n");

            return null;
        }

        if ($testsuite !== self::CANONICAL_TESTSUITES) {
            fwrite(STDERR, sprintf(
                "the parallel runner implements exactly the canonical selection --testsuite %s, got: %s\n",
                self::CANONICAL_TESTSUITES,
                $testsuite ?? '<absent>',
            ));

            return null;
        }

        if ($junit === null) {
            fwrite(STDERR, "--log-junit is required: the S173 gate reads exactly that artifact\n");

            return null;
        }

        $relative = str_starts_with($binary, './') ? substr($binary, 2) : $binary;
        $php = str_starts_with($relative, '/') ? $relative : self::ROOT . '/' . $relative;
        $resolved = realpath($php);
        if ($resolved === false || !is_file($resolved)) {
            fwrite(STDERR, "the forwarded phpunit binary does not exist: {$php}\n");

            return null;
        }

        return [
            'phpunit' => $resolved,
            'junit' => self::resolveArtifact($junit),
            'clover' => $clover === null ? null : self::resolveArtifact($clover),
            'colors' => $colors,
        ];
    }

    /** Artifact paths resolve against the current directory, exactly as PHPUnit does. */
    private static function resolveArtifact(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        $cwd = getcwd();

        return ($cwd === false ? self::ROOT : rtrim($cwd, '/')) . '/' . $path;
    }

    /**
     * @param array{phpunit: string, junit: string, clover: string|null, colors: bool} $command
     */
    private static function run(
        string $outDir,
        int $unitShards,
        int $concurrency,
        ?string $cpuSet,
        array $command,
    ): int {
        $durations = self::loadDurations();
        [$unitFiles, $integrationFiles] = self::inventoryFiles();

        // Every Unit file must have a duration entry; a silent zero would let an
        // unknown (possibly slow) file hide inside an already-busy shard.
        $unknown = array_values(array_filter(
            $unitFiles,
            static fn (string $f): bool => !array_key_exists($f, $durations),
        ));
        if ($unknown !== []) {
            fwrite(STDERR, sprintf(
                "duration cache has no entry for %d Unit file(s) — regenerate it (see --bless-from-junit): %s\n",
                count($unknown),
                implode(', ', array_slice($unknown, 0, 5)),
            ));

            return 2;
        }

        $stale = array_values(array_filter(
            array_keys($durations),
            static fn (string $f): bool => !in_array($f, $unitFiles, true),
        ));
        if ($stale !== []) {
            fwrite(STDERR, sprintf(
                "note: duration cache carries %d file(s) no longer in the inventory (harmless, "
                . "clears on the next --bless-from-junit): %s\n",
                count($stale),
                implode(', ', array_slice($stale, 0, 5)),
            ));
        }

        $buckets = self::packUnitByDuration($unitFiles, $durations, $unitShards);
        // Integration last, as one worker that owns the schema exclusively.
        $buckets[] = $integrationFiles;

        $bucketCount = count($buckets);
        /** @var list<array{id: int, cmd: string, log: string}> $jobs */
        $jobs = [];
        foreach ($buckets as $i => $files) {
            if ($files === []) {
                continue;
            }
            $config = self::writeBucketConfig($outDir, $i, $files);
            $jobs[] = self::buildJob($i, $config, $outDir, $command, $cpuSet);
        }

        return self::drive($jobs, $outDir, $bucketCount, $unitShards, $concurrency, $cpuSet, $command);
    }

    /**
     * Capped worker pool: never more than $concurrency PHPUnit processes alive at
     * once. Each worker's stdout/stderr goes to its own log file so the parent
     * never blocks on an unread pipe.
     *
     * @param list<array{id: int, cmd: string, log: string}>                    $jobs
     * @param array{phpunit: string, junit: string, clover: string|null, colors: bool} $command
     */
    private static function drive(
        array $jobs,
        string $outDir,
        int $bucketCount,
        int $unitShards,
        int $concurrency,
        ?string $cpuSet,
        array $command,
    ): int {
        $started = hrtime(true);
        /** @var array<int, resource> $running */
        $running = [];
        /** @var list<array{id: int, code: int, log: string}> $failed */
        $failed = [];
        /** @var array<int, float> $finishedAt */
        $finishedAt = [];

        while ($jobs !== [] || $running !== []) {
            while ($concurrency > count($running) && $jobs !== []) {
                /* $jobs is proven non-empty by the loop condition, so shift yields a job. */
                $job = array_shift($jobs);
                $running[$job['id']] = self::launch($job);
            }

            usleep(100_000);

            foreach ($running as $id => $proc) {
                $status = proc_get_status($proc);
                if ($status['running'] === true) {
                    continue;
                }
                $code = proc_close($proc);
                $finishedAt[$id] = (hrtime(true) - $started) / 1e9;
                unset($running[$id]);
                if ($code !== 0) {
                    $failed[] = ['id' => $id, 'code' => $code, 'log' => self::logPath($outDir, $id)];
                }
            }
        }

        $wall = (hrtime(true) - $started) / 1e9;

        $mergedJunit = self::mergeJunit($outDir, $bucketCount, $command['junit']);
        $clover = $command['clover'];
        $mergedCoverage = $clover === null ? null : self::mergeCoverage($outDir, $bucketCount, $clover);

        self::report(
            $finishedAt,
            $wall,
            $unitShards,
            $concurrency,
            $cpuSet,
            $command['clover'] !== null,
            $mergedJunit,
            $mergedCoverage,
        );

        if ($failed !== []) {
            fwrite(STDERR, "\nFAILURES:\n");
            foreach ($failed as $f) {
                fwrite(STDERR, sprintf(
                    "  bucket #%d exit %d — see %s\n",
                    $f['id'],
                    $f['code'],
                    $f['log'],
                ));
            }

            return 1;
        }

        return 0;
    }

    private static function detectCores(): int
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']];
        $process = proc_open(['nproc'], $descriptors, $pipes);
        if (!is_resource($process)) {
            return 4;
        }
        $probe = is_resource($pipes[1]) ? (string) stream_get_contents($pipes[1]) : '';
        if (is_resource($pipes[1])) {
            fclose($pipes[1]);
        }
        proc_close($process);

        $n = (int) trim($probe);

        return $n > 0 ? $n : 4;
    }

    /**
     * @return array<string,float> test file path => seconds
     */
    private static function loadDurations(): array
    {
        if (!is_file(self::DURATION_CACHE)) {
            fwrite(STDERR, 'missing ' . self::DURATION_CACHE . "\n");
            exit(2);
        }
        $raw = json_decode((string) file_get_contents(self::DURATION_CACHE), true);
        if (!is_array($raw) || !isset($raw['unit']) || !is_array($raw['unit'])) {
            fwrite(STDERR, "malformed duration cache\n");
            exit(2);
        }

        /** @var array<string,float> $unit */
        $unit = $raw['unit'];

        return $unit;
    }

    /**
     * Mirror phpunit.xml exactly: Unit = tests/**\/*Test.php minus Integration
     * minus E2E; Integration = tests/Integration/**\/*Test.php.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function inventoryFiles(): array
    {
        $unit = [];
        $integration = [];
        $directory = new RecursiveDirectoryIterator(self::ROOT . '/tests', FilesystemIterator::SKIP_DOTS);
        $iterator = new RecursiveIteratorIterator($directory);

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            if (!self::isTestFile($file->getFilename())) {
                continue;
            }
            $rel = substr($file->getPathname(), strlen(self::ROOT) + 1);
            if (str_starts_with($rel, 'tests/Integration/')) {
                $integration[] = $rel;
            } elseif (str_starts_with($rel, 'tests/E2E/')) {
                // E2E is not part of this job (mcp-e2e boots the hub separately).
                continue;
            } else {
                $unit[] = $rel;
            }
        }

        sort($unit);
        sort($integration);

        return [$unit, $integration];
    }

    private static function isTestFile(string $name): bool
    {
        return str_ends_with($name, 'Test.php');
    }

    /**
     * LPT bin-packing: sort files longest-first, drop each into the currently
     * lightest bucket. Keeps the heaviest class from deciding the wall.
     *
     * @param list<string>        $files
     * @param array<string,float> $durations
     *
     * @return list<list<string>>
     */
    private static function packUnitByDuration(array $files, array $durations, int $k): array
    {
        usort($files, static fn (string $a, string $b): int => ($durations[$b] ?? 0.0) <=> ($durations[$a] ?? 0.0));

        /** @var list<list<string>> $buckets */
        $buckets = [];
        /** @var list<float> $load */
        $load = [];
        for ($slot = 0; $slot < $k; $slot++) {
            $buckets[] = [];
            $load[] = 0.0;
        }
        foreach ($files as $f) {
            $lightest = 0;
            $best = $load[0];
            foreach ($load as $index => $value) {
                if ($value < $best) {
                    $best = $value;
                    $lightest = $index;
                }
            }
            $buckets[$lightest][] = $f;
            $load[$lightest] = $best + ($durations[$f] ?? 0.0);
        }

        return array_values($buckets);
    }

    /**
     * Typed snapshot of a DOMNodeList as elements.
     *
     * Foreach over DOMNodeList yields `mixed` to the static analysers (PHP's own
     * stubs), which is fatal under psalm errorLevel 1; DOMNodeList::item() is
     * typed, so this loop narrows once, here. The lists this runner snapshots
     * (getElementsByTagName, childNodes) are static per the DOM spec, so
     * removeChild during iteration stays safe the way iterator_to_array made it.
     *
     * @psalm-suppress TooManyTemplateParams — psalm's DOM stubs model DOMNodeList
     *   as non-generic; phpstan level 9 requires the TNode parameter. The extra
     *   parameter is inert for psalm, so the docblock serves the stricter tool.
     *
     * @param DOMNodeList<DOMNode> $nodes
     *
     * @return list<DOMElement>
     */
    private static function elements(DOMNodeList $nodes): array
    {
        $elements = [];
        $count = $nodes->length;
        for ($i = 0; $i < $count; $i++) {
            $node = $nodes->item($i);
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    /**
     * Clone phpunit.xml so every worker inherits the identical runner contract
     * (bootstrap, executionOrder=random, failOnRisky/failOnWarning, strict output,
     * cacheDirectory, <source> include) and only the <testsuites> selection — a
     * flat list of explicit <file> entries — differs.
     *
     * @param list<string> $files
     */
    private static function writeBucketConfig(string $outDir, int $i, array $files): string
    {
        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;
        if (!$doc->load(self::ROOT . '/phpunit.xml')) {
            fwrite(STDERR, "cannot load phpunit.xml\n");
            exit(2);
        }

        $root = $doc->documentElement;
        if ($root === null) {
            fwrite(STDERR, "phpunit.xml has no document element\n");
            exit(2);
        }

        // The generated config lives in $outDir, and PHPUnit resolves every relative
        // path in a config (bootstrap, cacheDirectory, <source> dirs) against that
        // config's own directory — not the checkout. So absolutize each one, or the
        // worker can't find tests/bootstrap.php and dies before a single test runs.
        $base = realpath(self::ROOT);
        if ($base === false) {
            fwrite(STDERR, "cannot resolve checkout root\n");
            exit(2);
        }

        foreach (self::elements($root->getElementsByTagName('testsuites')) as $testsuites) {
            $root->removeChild($testsuites);
        }

        $testsuites = $doc->createElement('testsuites');
        // PHPUnit's XSD: a <testsuite> carries a REQUIRED name attribute and only
        // element children (<file>/<directory>) — never character content.
        $suite = $doc->createElement('testsuite');
        $suite->setAttribute('name', 'bucket-' . $i);
        foreach ($files as $f) {
            $suite->appendChild($doc->createElement('file', $base . '/' . $f));
        }
        $testsuites->appendChild($suite);
        $root->insertBefore($testsuites, $root->firstChild);

        // Drop <coverage><report> so no worker writes the shared coverage.xml/HTML.
        foreach (self::elements($doc->getElementsByTagName('coverage')) as $coverage) {
            $parent = $coverage->parentNode;
            if ($parent instanceof DOMElement) {
                $parent->removeChild($coverage);
            }
        }

        $root->setAttribute('bootstrap', $base . '/tests/bootstrap.php');

        // A unique code-coverage cache per worker: the shared .phpunit.cache would be
        // one write-contended directory across four concurrent processes.
        $bucketCache = $outDir . "/cache-{$i}";
        if (!is_dir($bucketCache) && !mkdir($bucketCache, 0777, true) && !is_dir($bucketCache)) {
            fwrite(STDERR, "cannot create bucket cache dir: {$bucketCache}\n");
            exit(2);
        }
        $root->setAttribute('cacheDirectory', $bucketCache);

        foreach (self::elements($doc->getElementsByTagName('source')) as $source) {
            foreach (self::elements($source->getElementsByTagName('directory')) as $dir) {
                if (!str_starts_with($dir->textContent, '/')) {
                    $dir->textContent = $base . '/' . $dir->textContent;
                }
            }
        }

        $path = $outDir . "/bucket-{$i}.phpunit.xml";
        $doc->save($path);

        return $path;
    }

    /**
     * @param array{phpunit: string, junit: string, clover: string|null, colors: bool} $command
     *
     * @return array{id: int, cmd: string, log: string}
     */
    private static function buildJob(
        int $i,
        string $config,
        string $outDir,
        array $command,
        ?string $cpuSet,
    ): array {
        $junit = $outDir . "/junit-{$i}.xml";
        $cov = $outDir . "/cov-{$i}.php";

        $cmd = 'exec php -d max_execution_time=0 ' . escapeshellarg($command['phpunit'])
            . ' --configuration ' . escapeshellarg($config)
            . ' --log-junit ' . escapeshellarg($junit);
        if ($command['clover'] !== null) {
            $cmd .= ' --coverage-php ' . escapeshellarg($cov);
        }
        if ($command['colors'] === true) {
            $cmd .= ' --colors=always';
        }

        if ($cpuSet !== null) {
            $cmd = 'taskset -c ' . escapeshellarg($cpuSet) . ' bash -c ' . escapeshellarg($cmd);
        }

        return [
            'id' => $i,
            'cmd' => $cmd,
            'log' => self::logPath($outDir, $i),
        ];
    }

    /** Shared log-file path for a bucket, so launch() and the failure report agree. */
    private static function logPath(string $outDir, int $id): string
    {
        return $outDir . "/log-{$id}.txt";
    }

    /**
     * @param array{id: int, cmd: string, log: string} $job
     *
     * @return resource
     */
    private static function launch(array $job)
    {
        $logHandle = fopen($job['log'], 'wb');
        if ($logHandle === false) {
            fwrite(STDERR, "cannot open log for bucket #{$job['id']}\n");
            exit(2);
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => $logHandle,
            2 => $logHandle,
        ];
        $proc = proc_open($job['cmd'], $descriptors, $pipes);
        if (!is_resource($proc)) {
            fwrite(STDERR, "proc_open failed for bucket #{$job['id']}\n");
            exit(2);
        }

        // The child inherited the log fd via $descriptors; the only real pipe is
        // stdin, which we never write to — close the parent's copy so the child
        // sees EOF and the parent's fd table stays clean across many buckets.
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        fclose($logHandle);

        return $proc;
    }

    /**
     * Merge every bucket junit into one <testsuites> document written at the exact
     * path the canonical argv named, and read the totals back off the merged tree
     * so the printed "N tests / M assertions" is a measurement of what CI's gates
     * will actually parse, not a hope.
     *
     * @return array{path: string, tests: int, assertions: int, skipped: int}
     */
    private static function mergeJunit(string $outDir, int $bucketCount, string $finalPath): array
    {
        $merged = new DOMDocument('1.0', 'UTF-8');
        $mergedRoot = $merged->createElement('testsuites');
        $mergedRoot->setAttribute('name', 'merged');
        $merged->appendChild($mergedRoot);
        $merged->formatOutput = true;

        $tests = 0;
        $assertions = 0;
        $skipped = 0;

        for ($i = 0; $i < $bucketCount; $i++) {
            $f = $outDir . "/junit-{$i}.xml";
            if (!is_file($f)) {
                continue;
            }
            $doc = new DOMDocument();
            if (!@$doc->load($f)) {
                continue;
            }
            $element = $doc->documentElement;
            if ($element === null) {
                continue;
            }
            foreach (self::elements($element->childNodes) as $child) {
                foreach (self::elements($child->getElementsByTagName('testcase')) as $case) {
                    $tests++;
                    $assertions += (int) $case->getAttribute('assertions');
                    if ($case->getElementsByTagName('skipped')->length > 0) {
                        $skipped++;
                    }
                }

                $mergedRoot->appendChild($merged->importNode($child, true));
            }
        }

        $merged->save($finalPath);

        return ['path' => $finalPath, 'tests' => $tests, 'assertions' => $assertions, 'skipped' => $skipped];
    }

    /**
     * Merge the per-worker php-code-coverage binaries into ONE Clover document at
     * the path the canonical argv named (--coverage-clover).
     */
    private static function mergeCoverage(string $outDir, int $bucketCount, string $finalPath): string
    {
        $combined = null;
        for ($i = 0; $i < $bucketCount; $i++) {
            $f = $outDir . "/cov-{$i}.php";
            if (!is_file($f)) {
                continue;
            }

            // PHPUnit's --coverage-php writes a PHP file of the form
            //   <?php return \unserialize(<<<EOT <payload> EOT);
            // so the object is obtained by REQUIRE (executing the return), not by
            // unserialize(file_get_contents()).
            $obj = require $f;
            if (!$obj instanceof CodeCoverage) {
                fwrite(STDERR, "bucket cov-{$i}.php did not return a CodeCoverage object\n");

                continue;
            }

            if ($combined === null) {
                $combined = $obj;
            } else {
                $combined->merge($obj);
            }
        }

        if ($combined === null) {
            throw new RuntimeException('no coverage parts to merge');
        }

        (new Clover())->process($combined, $finalPath, 'phlix-hub');

        return $finalPath;
    }

    /**
     * Rewrite the committed duration cache from ONE serial junit.
     *
     * Per-file weight = the summed @time of every <testcase> whose @class
     * reflects onto that file. Integration/E2E classes are skipped on purpose —
     * Integration is never LPT-packed (it is one exclusive bucket) and E2E is a
     * different job. The write fails closed: an autoload-failure on any Unit
     * class, or a Unit file the profile never executed, aborts with exit 2
     * instead of blessing a cache that under-weights an unknown file to zero.
     */
    private static function blessDurationCache(string $junitPath, ?string $generatedFrom): int
    {
        if (!is_file($junitPath)) {
            fwrite(STDERR, "bless: no such junit profile: {$junitPath}\n");

            return 2;
        }
        $doc = new DOMDocument();
        if (!@$doc->load($junitPath)) {
            fwrite(STDERR, "bless: {$junitPath} is not parseable XML\n");

            return 2;
        }

        [$unitFiles, , ] = self::inventoryFiles();
        $unitSet = array_flip($unitFiles);

        /** @var array<string,float> $perFile */
        $perFile = [];
        /** @var list<string> $unresolved */
        $unresolved = [];

        foreach (self::elements($doc->getElementsByTagName('testcase')) as $case) {
            $class = $case->getAttribute('class');
            if ($class === '') {
                continue;
            }
            $file = self::classToUnitFile($class, $unitSet, $unresolved);
            if ($file === null) {
                continue;
            }
            $perFile[$file] = ($perFile[$file] ?? 0.0) + (float) $case->getAttribute('time');
        }

        if ($unresolved !== []) {
            fwrite(STDERR, sprintf(
                "bless: %d test class(es) in the profile could not be reflected onto a Unit file: %s\n",
                count($unresolved),
                implode(', ', array_slice($unresolved, 0, 5)),
            ));

            return 2;
        }

        $missing = array_values(array_diff($unitFiles, array_keys($perFile)));
        if ($missing !== []) {
            fwrite(STDERR, sprintf(
                "bless: the profile never executed %d Unit file(s) — it is not a full serial Unit,"
                . "Integration run: %s\n",
                count($missing),
                implode(', ', array_slice($missing, 0, 5)),
            ));

            return 2;
        }

        ksort($perFile);
        $rounded = [];
        foreach ($perFile as $file => $seconds) {
            $rounded[$file] = round($seconds, 3);
        }

        $payload = [
            'generatedFrom' => $generatedFrom ?? 'serial junit ' . date('Y-m-d\TH:i:s')
                . ' (' . PHP_VERSION . ', ' . (extension_loaded('pcov') ? 'pcov' : 'no-pcov') . ')',
            'unit' => $rounded,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            fwrite(STDERR, "bless: encoding the duration cache failed\n");

            return 2;
        }

        if (file_put_contents(self::DURATION_CACHE, $json . "\n") === false) {
            fwrite(STDERR, 'bless: cannot write ' . self::DURATION_CACHE . "\n");

            return 2;
        }

        fwrite(STDOUT, sprintf("blessed %d unit durations -> %s\n", count($rounded), self::DURATION_CACHE));

        return 0;
    }

    /**
     * Map a junit @class onto its repo-relative Unit file, or null when the class
     * lives in Integration/E2E (weights for those are not packed).
     *
     * @param array<string,int> $unitSet class-file => 0 (flip map)
     * @param list<string>      $unresolved collected by reference; a class the
     *                                    autoloader cannot see fails the bless
     */
    private static function classToUnitFile(string $class, array $unitSet, array &$unresolved): ?string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $class) !== 1) {
            $unresolved[] = $class;

            return null;
        }

        if (!class_exists($class)) {
            $unresolved[] = $class;

            return null;
        }

        $reflection = new ReflectionClass($class);
        $fileName = $reflection->getFileName();
        if ($fileName === false) {
            $unresolved[] = $class;

            return null;
        }

        $rel = substr($fileName, strlen((string) realpath(self::ROOT)) + 1);
        if (str_starts_with($rel, 'tests/Integration/') || str_starts_with($rel, 'tests/E2E/')) {
            return null;
        }

        if (!isset($unitSet[$rel])) {
            // A Unit suite file whose class does not live where PSR-4 put it —
            // guessing the mapping here would silently drop its weight.
            $unresolved[] = $class;

            return null;
        }

        return $rel;
    }

    /**
     * @param array<int,float> $finishedAt
     * @param array{path: string, tests: int, assertions: int, skipped: int} $mergedJunit
     */
    private static function report(
        array $finishedAt,
        float $wall,
        int $unitShards,
        int $concurrency,
        ?string $cpuSet,
        bool $withCoverage,
        array $mergedJunit,
        ?string $mergedCoverage,
    ): void {
        fwrite(STDOUT, "\n=== S458 parallel run [" . self::S458_RITUAL_TOKEN . "] ===\n");
        fwrite(STDOUT, sprintf(
            "unit-shards=%d concurrency=%d cpu-set=%s coverage=%s\n",
            $unitShards,
            $concurrency,
            $cpuSet ?? 'unpinned',
            $withCoverage ? 'on(merged)' : 'off',
        ));
        foreach ($finishedAt as $id => $t) {
            fwrite(STDOUT, sprintf("  bucket #%d done @ %.1fs\n", (int) $id, $t));
        }
        fwrite(STDOUT, sprintf(
            "merged totals: %d tests, %d assertions, %d skipped\n",
            $mergedJunit['tests'],
            $mergedJunit['assertions'],
            $mergedJunit['skipped'],
        ));
        fwrite(STDOUT, sprintf("WALL = %.1fs\n", $wall));
        fwrite(STDOUT, "merged junit: {$mergedJunit['path']}\n");
        if ($mergedCoverage !== null) {
            fwrite(STDOUT, "merged coverage: {$mergedCoverage}\n");
        }
    }
}
