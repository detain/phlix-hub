<?php

/**
 * S299 — run phpcs over the WHOLE repository corpus and fail the build unless it
 * actually inspected a non-zero, floor-checked number of files in every path.
 *
 * ## The defect this closes, measured on 2026-08-10
 *
 * `phpcs.xml.dist` listed `src` and `scripts`; the CI step ran
 * `./vendor/bin/phpcs --standard=PSR12 --colors src/`, which ignores the ruleset
 * file entirely. **`tests/` had never been linted, by either path, since the
 * repository was created.** Measured at `origin/master` @ `f4ab19f`:
 * `phpcs --standard=PSR12 tests/` reported **696 errors and 141 warnings across
 * 69 of 241 files**, while the "PHP CodeSniffer (PSR-12)" check was green on every
 * commit.
 *
 * 🔴 **A gate that inspects zero files reads exactly like a gate that passes.**
 * Same exit 0, same green tick, and usually LESS output than a real run. That is
 * why this survived for years: nothing on the check's happy path ever stated how
 * many files it had looked at, so "0 files in tests/" and "241 clean files in
 * tests/" were indistinguishable.
 *
 * ## What this script asserts, and why each half exists
 *
 *  1. **The ruleset still names every expected path.** {@see EXPECTED_PATHS} is a
 *     literal in this file; deleting `<file>tests</file>` from `phpcs.xml.dist`
 *     fails here instead of silently shrinking the corpus to nothing.
 *  2. **phpcs ran and produced a parseable report.** A gate that cannot measure
 *     must not report success — there is no `|| exit 0` anywhere below.
 *  3. **Every path was inspected at or above a LITERAL floor** ({@see CORPUS_FLOORS}).
 *     Floors are hard-coded numbers, not counts derived from the tree: a count
 *     derived from its own subject self-adjusts and can never fail. This is the
 *     half that catches "0 / 0 (100%)", an excluded directory, and phpcs walking
 *     into a symlinked path and quietly finding nothing.
 *  4. **phpcs's file list matches the files actually on disk.** Half 3 alone
 *     passes if phpcs sees 240 of 241 files, which is how a stray
 *     `<exclude-pattern>` would hide a violation. This half is derived from the
 *     tree on purpose — it is a CROSS-CHECK on half 3, never a replacement for it.
 *  5. **Zero errors and zero warnings.** ⚠ phpcs's own exit code is not read
 *     anywhere in this script: it is unreliable (it has reported 0 with findings
 *     present, and 1 for reasons unrelated to the code). The verdict comes from
 *     the report's `totals`, and every offending file is named.
 *  6. **No warning-suppression flag in the ruleset.** S333: the `<arg value="np"/>`
 *     flag (phpcs `-n` + `-p`) was live once this gate ran `phpcs` against
 *     `phpcs.xml.dist` directly, and it hid 6 warnings across 5 files under
 *     scripts/. Warnings are gate failures per S109, so the PARSED ruleset is
 *     checked for any `<arg value>` whose dash-stripped content contains `n` —
 *     re-adding one fails the build on the ruleset content, never on exit codes.
 *
 * ## Usage
 *
 * ```
 * php scripts/assert-phpcs-corpus.php [--extra-path=DIR] [--cache=FILE] [--ruleset=FILE]
 * ```
 *
 * `--extra-path=DIR` ADDS a directory to the corpus and requires it to contain at
 * least one inspected `.php` file. It is structurally incapable of making the gate
 * pass anything it would otherwise fail, which is why
 * {@see \Phlix\Hub\Tests\Unit\Support\PhpcsCorpusGateTest} uses it to prove both
 * that the gate goes red on a planted violation and that a zero-file corpus is a
 * failure rather than a pass. `--cache=FILE` is a phpcs speed-up used by that test;
 * CI deliberately runs without it. `--ruleset=FILE` is TEST-ONLY: it overrides the
 * ruleset path so the failure path can be driven red in a test — a gate whose
 * failure path cannot be driven red is not a gate.
 *
 * @package Phlix\Hub
 */

declare(strict_types=1);

/**
 * The paths `phpcs.xml.dist` must declare, as literal strings.
 *
 * `tests` is the whole point of S299. Removing an entry here AND from the ruleset
 * would neuter the gate, so treat a change to this list exactly like deleting the
 * CI step.
 */
const EXPECTED_PATHS = ['src', 'scripts', 'tests'];

/**
 * Per-path floors for the number of `.php` files phpcs must report inspecting.
 *
 * Measured on 2026-08-10 at `f4ab19f`: src 197, scripts 7, tests 241. The floors
 * sit a little below those so that deleting a file is not a build break, and far
 * enough above zero that an empty traversal cannot pass. **Never lower one to
 * make a build green** — a shrinking floor is how this gate gets neutered, and it
 * deserves the same scrutiny as deleting the check.
 *
 * @var array<string, int>
 */
const CORPUS_FLOORS = [
    'src' => 180,
    'scripts' => 6,
    'tests' => 220,
];

$repoRoot = dirname(__DIR__);
$rulesetPath = $repoRoot . '/phpcs.xml.dist';

/** Emit a GitHub-annotated error and exit non-zero. Every failure path ends here. */
$fail = static function (string $message): never {
    fwrite(STDERR, '::error::' . $message . "\n");
    exit(1);
};

$extraPath = null;
$cacheFile = null;
$rulesetOverride = null;
/** @var list<string> $arguments */
$arguments = array_slice(array_map('strval', $argv), 1);
foreach ($arguments as $arg) {
    if (str_starts_with($arg, '--extra-path=')) {
        $extraPath = substr($arg, strlen('--extra-path='));
        continue;
    }
    if (str_starts_with($arg, '--cache=')) {
        $cacheFile = substr($arg, strlen('--cache='));
        continue;
    }
    if (str_starts_with($arg, '--ruleset=')) {
        $rulesetOverride = substr($arg, strlen('--ruleset='));
        continue;
    }
    $fail(sprintf('S299 phpcs corpus gate: unknown argument "%s".', $arg));
}

if ($extraPath !== null && !is_dir($extraPath)) {
    $fail(sprintf('S299 phpcs corpus gate: --extra-path "%s" is not a directory.', $extraPath));
}

// `--ruleset=FILE` is a TEST-ONLY override so the S333 np-flag check and the
// missing-path check can be driven red without editing the real ruleset. It is
// applied after argument parsing — exactly like --cache/--extra-path — and
// before the ruleset is read below.
if ($rulesetOverride !== null) {
    $rulesetPath = $rulesetOverride;
}

// ---------------------------------------------------------------------------
// 1. The ruleset still names every expected path.
// ---------------------------------------------------------------------------
if (!is_file($rulesetPath)) {
    $fail(sprintf('S299 phpcs corpus gate: %s is missing.', $rulesetPath));
}

$previous = libxml_use_internal_errors(true);
$ruleset = simplexml_load_string((string) file_get_contents($rulesetPath));
libxml_clear_errors();
libxml_use_internal_errors($previous);

if ($ruleset === false) {
    $fail(sprintf('S299 phpcs corpus gate: %s is not parseable XML.', $rulesetPath));
}

$declared = array_map(
    static fn (SimpleXMLElement $e): string => trim((string) $e),
    iterator_to_array($ruleset->file, false),
);

$missingPaths = array_values(array_diff(EXPECTED_PATHS, $declared));
if ($missingPaths !== []) {
    $fail(sprintf(
        'S299 phpcs corpus gate: phpcs.xml.dist declares [%s] but must declare [%s]. Missing: %s. '
        . 'tests/ was unlinted for the whole life of this repository (696 errors / 141 warnings when '
        . 'first measured) precisely because nothing asserted the corpus — do not shrink it.',
        implode(', ', $declared),
        implode(', ', EXPECTED_PATHS),
        implode(', ', $missingPaths),
    ));
}

// ---------------------------------------------------------------------------
// 1b. S333 — no warning-suppression flag may come back.
//
// This asserts on PARSED RULESET CONTENT, never on phpcs's exit code: `-n`
// (e.g. `<arg value="np"/>`) makes phpcs silent about warnings, which are gate
// failures per S109, and the suppressed set is invisible to every later check.
// ---------------------------------------------------------------------------
foreach ($ruleset->arg as $arg) {
    $value = (string) $arg['value'];
    if ($value === '') {
        continue;
    }
    $stripped = ltrim($value, '-');
    if (str_contains($stripped, 'n')) {
        $fail(sprintf(
            'S333 phpcs corpus gate: the ruleset re-introduces a warning-suppression flag '
            . '(<arg value="%s"/>). The `n` flag suppresses warnings, which are gate failures '
            . 'per S109 — re-adding it is a build break.',
            $value,
        ));
    }
}

// ---------------------------------------------------------------------------
// 2. Run phpcs and parse its JSON report.
// ---------------------------------------------------------------------------
$phpcs = $repoRoot . '/vendor/bin/phpcs';
if (!is_file($phpcs)) {
    $fail(sprintf(
        'S299 phpcs corpus gate: %s is missing — run `composer install` before this gate. Do NOT add '
        . 'a branch that exits 0 when the linter is absent.',
        $phpcs,
    ));
}

$command = [
    PHP_BINARY,
    $phpcs,
    '--standard=' . $rulesetPath,
    '--report=json',
    '--runtime-set', 'ignore_warnings_on_exit', '0',
    '-q',
];
if ($cacheFile !== null) {
    $command[] = '--cache=' . $cacheFile;
}
if ($extraPath !== null) {
    $command[] = $extraPath;
    foreach (EXPECTED_PATHS as $path) {
        $command[] = $repoRoot . '/' . $path;
    }
}

$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$process = proc_open(
    implode(' ', array_map('escapeshellarg', $command)),
    $descriptors,
    $pipes,
    $repoRoot,
);
if (!is_resource($process)) {
    $fail('S299 phpcs corpus gate: could not start phpcs.');
}
$stdout = (string) stream_get_contents($pipes[1]);
$stderr = (string) stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
// ⚠ Deliberately NOT used as the verdict. phpcs's exit code is read only to
// report it; the pass/fail decision is made from the report below.
$phpcsExit = proc_close($process);

$report = json_decode($stdout, true);
if (!is_array($report) || !isset($report['files']) || !is_array($report['files'])) {
    $fail(sprintf(
        "S299 phpcs corpus gate: phpcs produced no parseable JSON report (exit %d). A gate that cannot "
        . "measure must not report success.\n--- stdout (first 2000 bytes) ---\n%s\n--- stderr ---\n%s",
        $phpcsExit,
        substr($stdout, 0, 2000),
        substr($stderr, 0, 2000),
    ));
}

// ---------------------------------------------------------------------------
// 3 + 4. The corpus: floor per path, and parity with the files on disk.
// ---------------------------------------------------------------------------
/**
 * Every `.php` file under $dir, following symlinks.
 *
 * Symlinks are followed on purpose: phpcs can traverse a symlinked directory and
 * silently inspect nothing, so if this walk sees files phpcs did not, the parity
 * check below turns that silence into a failure.
 *
 * @return list<string>
 */
$diskFiles = static function (string $dir): array {
    $out = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $dir,
            FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS,
        ),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    /** @var mixed $entry */
    foreach ($iterator as $entry) {
        if ($entry instanceof SplFileInfo && $entry->isFile() && $entry->getExtension() === 'php') {
            $out[] = $entry->getPathname();
        }
    }
    sort($out);
    return $out;
};

/** @var list<string> $inspected */
$inspected = array_map('strval', array_keys($report['files']));

/** @var array<string, int> $perPath */
$perPath = [];
$corpus = EXPECTED_PATHS;
if ($extraPath !== null) {
    $corpus[] = $extraPath;
}

$problems = [];
foreach ($corpus as $path) {
    $absolute = str_starts_with($path, '/') ? $path : $repoRoot . '/' . $path;
    $real = realpath($absolute);
    if ($real === false) {
        $problems[] = sprintf('corpus path "%s" does not exist on disk', $path);
        continue;
    }
    $prefix = rtrim($real, '/') . '/';
    $seen = array_values(array_filter(
        $inspected,
        static fn (string $file): bool => str_starts_with($file, $prefix),
    ));
    $perPath[$path] = count($seen);

    $onDisk = $diskFiles($real);
    $floor = CORPUS_FLOORS[$path] ?? 1;

    printf(
        "  %-10s %d / %d (%d%%) files inspected   [floor %d]\n",
        $path,
        count($seen),
        count($onDisk),
        count($onDisk) === 0 ? 0 : (int) round(count($seen) / count($onDisk) * 100),
        $floor,
    );

    if (count($seen) < $floor) {
        $problems[] = sprintf(
            'phpcs inspected %d file(s) under "%s", below the literal floor of %d. An empty or '
            . 'near-empty traversal exits 0 and looks exactly like a clean run — that is the S299 '
            . 'defect. Check for a symlinked directory, an <exclude-pattern>, or a deleted <file> entry',
            count($seen),
            $path,
            $floor,
        );
    }

    $notInspected = array_values(array_diff($onDisk, $seen));
    if ($notInspected !== []) {
        $problems[] = sprintf(
            '%d .php file(s) under "%s" exist on disk but were NOT inspected: %s',
            count($notInspected),
            $path,
            implode(', ', array_slice($notInspected, 0, 10)),
        );
    }
}

$total = array_sum($perPath);
printf(
    "  %-10s %d file(s) inspected in total (phpcs exit code %d, NOT used as the verdict)\n",
    'TOTAL',
    $total,
    $phpcsExit,
);

if ($total === 0) {
    $problems[] = 'phpcs inspected ZERO files in total. That is a failure, not a pass.';
}

// ---------------------------------------------------------------------------
// 5. Zero errors and zero warnings, named.
// ---------------------------------------------------------------------------

/** Read an untrusted report field as an int without silently swallowing a wrong shape. */
$asInt = static fn (mixed $value): int => is_int($value) || is_string($value) ? (int) $value : 0;

/** Read an untrusted report field as text for the failure message only. */
$asText = static fn (mixed $value): string => is_scalar($value) ? (string) $value : '?';

$totals = $report['totals'] ?? null;
if (!is_array($totals)) {
    $fail(sprintf(
        'S299 phpcs corpus gate: the phpcs JSON report has no "totals" object, so the error/warning '
        . 'verdict cannot be read. A gate that cannot measure must not report success. Exit code was %d.',
        $phpcsExit,
    ));
}

$errors = $asInt($totals['errors'] ?? null);
$warnings = $asInt($totals['warnings'] ?? null);

if ($errors > 0 || $warnings > 0) {
    $lines = [];
    foreach ($report['files'] as $file => $data) {
        if (!is_array($data)) {
            continue;
        }
        $fileErrors = $asInt($data['errors'] ?? null);
        $fileWarnings = $asInt($data['warnings'] ?? null);
        if ($fileErrors === 0 && $fileWarnings === 0) {
            continue;
        }
        $first = '';
        /** @var mixed $messages */
        $messages = $data['messages'] ?? null;
        if (is_array($messages) && isset($messages[0]) && is_array($messages[0])) {
            $first = sprintf(
                ' — line %s: %s (%s)',
                $asText($messages[0]['line'] ?? null),
                $asText($messages[0]['message'] ?? null),
                $asText($messages[0]['source'] ?? null),
            );
        }
        $lines[] = sprintf('%s: %d error(s), %d warning(s)%s', $file, $fileErrors, $fileWarnings, $first);
    }
    $problems[] = sprintf(
        "phpcs reported %d error(s) and %d warning(s) across %d file(s):\n    %s",
        $errors,
        $warnings,
        count($lines),
        implode("\n    ", array_slice($lines, 0, 40)),
    );
}

if ($problems !== []) {
    $fail("S299 phpcs corpus gate FAILED:\n  - " . implode("\n  - ", $problems));
}

printf(
    "S299 phpcs corpus gate OK: %d file(s) inspected across [%s], 0 errors, 0 warnings.\n",
    $total,
    implode(', ', $corpus),
);

exit(0);
