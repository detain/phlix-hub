<?php

/**
 * S316 — fail the build when the Clover coverage report is missing, empty, or
 * measured nothing.
 *
 * ## The defect this closes, measured on 2026-08-10
 *
 * Three individually reasonable facts composed into a silent fail-open:
 *
 *  1. `.github/workflows/ci.yml` passed NO `--coverage-*` flag. `coverage.xml`
 *     existed **only** because of the `<coverage><report><clover/>` block in
 *     `phpunit.xml`, driven by `coverage: xdebug` in setup-php.
 *  2. Both uploaders swallow failure by design and must keep doing so:
 *     `codecov/codecov-action` runs with `fail_ci_if_error: false` and the Codacy
 *     reporter with `continue-on-error: true`. That is CORRECT — flipping either
 *     one makes every unrelated PR depend on a third party's availability, and
 *     this program already has a case of a mid-job network fetch reddening an
 *     innocent PR. But it does mean neither step can be the guard.
 *  3. There was no guard on the LOCAL artifact.
 *
 * ⇒ Deleting one line of XML stopped all coverage reporting and the whole
 * pipeline stayed **green**. Nothing anywhere said a word.
 *
 * ## Where the guard belongs
 *
 * On the artifact, in the same job that produced it, BEFORE the uploads. Not on
 * the upload steps: an upload that fails because Codecov is down is not the same
 * event as a report that was never written, and conflating them buys a real gate
 * at the price of a flaky one — the noisy rule that eventually gets deleted.
 *
 * ## Why this asserts MAGNITUDE and not just existence
 *
 * The commonest neutered gate in this estate is one that ran and inspected zero
 * files: same exit 0, same green tick, usually LESS output than a real pass. A
 * report can exist, parse, and still be worthless — `<source>` narrowed to a
 * single directory, a coverage driver that half-loaded, a run that collected
 * nothing. So every threshold below is a floor on a number this script PRINTS:
 *
 *  | floor                                  | what its absence would hide          |
 *  | -------------------------------------- | ------------------------------------ |
 *  | report is a real file, non-zero bytes   | the report was never written         |
 *  | {@see MIN_FILES} files                  | `<source>` no longer includes src/   |
 *  | {@see MIN_STATEMENTS} statements        | the corpus silently shrank           |
 *  | coveredstatements > 0                   | the driver collected nothing         |
 *  | {@see MIN_STATEMENT_COVERAGE}%          | the suite stopped exercising the code|
 *
 * ⚠ Every floor is a **literal** below, derived from a measured baseline that is
 * written down beside it. None of them is read out of the report being checked:
 * a threshold computed from its own subject self-adjusts and can never fail.
 *
 * ## The contract
 *
 * Every failure mode is `exit 1`. There is deliberately no `exit 0` fallback, no
 * "no report — nothing to check" branch, and no `|| true` at the call site. A
 * gate that cannot measure must not report success; that silent-skip is the
 * entire reason this went unnoticed on every push and every PR.
 *
 * Usage: php scripts/assert-coverage-report.php [path/to/coverage.xml]
 *
 * @package Phlix\Hub
 */

declare(strict_types=1);

/**
 * Floor for statement coverage, as a percentage.
 *
 * Baseline measured for S316 on 2026-08-10, at `d0be6b2` (the commit S311
 * merged): **81.25%** locally (PHP 8.3.6 + PCOV 1.0.11, real MySQL, full suite)
 * and **81.87%** on the CI runner as reported by `codecov/project`. Before S311
 * removed the coverage metadata from `tests/`, the CI headline was ~69.5% — the
 * 12-point jump was attribution, not new tests.
 *
 * 70 is set ~12 points BELOW the baseline on purpose. This is a collapse
 * detector, not a ratchet: a floor placed at the current figure reds on ordinary
 * work (deleting a well-tested class lowers the percentage), and a gate that
 * reds on ordinary work is the one that gets deleted. Raising it is a policy
 * decision; lowering it is how this gate would be neutered, so a lower value
 * deserves the same scrutiny as deleting the check.
 *
 * ⚠ CI collects with Xdebug and the local baseline above was collected with
 * PCOV. The two drivers do not always agree on the executable-line set, which is
 * a second reason the floor carries this much headroom.
 */
const MIN_STATEMENT_COVERAGE = 70.0;

/**
 * Floor for the total number of statements the report accounts for.
 *
 * Measured at `d0be6b2` on the full suite against a real MySQL: **15,468**
 * (`988,793`-byte report, 198 files, 12,567 covered). This is the denominator,
 * and it is the
 * number that goes to ~0 when `<source>` stops including `src/` — a change that
 * would otherwise RAISE the percentage while measuring almost nothing.
 */
const MIN_STATEMENTS = 12000;

/**
 * Floor for the number of source files appearing in the report.
 *
 * Measured at `d0be6b2`: **198**. The most literal form of "a gate that
 * inspected zero files reads exactly like a clean pass".
 */
const MIN_FILES = 150;

/**
 * Emit a GitHub-annotated error and exit non-zero.
 *
 * Every failure path in this script ends here. There is no path that returns
 * success without having read a real number out of a real report.
 */
$coverageGateFail = static function (string $headline, string ...$detail): never {
    fwrite(STDERR, '::error::' . $headline . "\n");

    foreach ($detail as $line) {
        fwrite(STDERR, '  ' . $line . "\n");
    }

    exit(1);
};

/**
 * Read one project-level Clover metric as a non-negative integer, or die trying.
 *
 * An attribute that is absent, empty or non-numeric means the file is not the
 * shape this gate understands. The honest response is to fail, never to invent a
 * zero and carry on.
 */
$coverageGateMetric = static function (
    DOMXPath $xpath,
    string $attribute,
    string $reportPath,
) use ($coverageGateFail): int {
    $expression = '/coverage/project/metrics/@' . $attribute;
    $nodes = $xpath->query($expression);

    if ($nodes === false || $nodes->length === 0) {
        $coverageGateFail(
            sprintf('S316 coverage gate: %s has no %s at %s.', $reportPath, $attribute, $expression),
            'PHPUnit\'s Clover writer always emits this attribute on the project element, so its',
            'absence means this file is not PHPUnit Clover XML. Do not switch to another report',
            'format without updating this gate — a format it cannot read must fail, not skip.',
        );
    }

    $raw = trim((string) $nodes->item(0)?->nodeValue);

    if (preg_match('/^\d+$/', $raw) !== 1) {
        $coverageGateFail(
            sprintf('S316 coverage gate: metric %s is not a non-negative integer.', $attribute),
            sprintf('Parsed value: "%s" (from %s)', $raw, $expression),
            'A gate that cannot read its own input must fail, not skip.',
        );
    }

    return (int) $raw;
};

$reportPath = $argv[1] ?? (dirname(__DIR__) . '/coverage.xml');

// -----------------------------------------------------------------------------
// 1. The artifact exists.
//
// ⚠ The shape here matters. `if [ -f coverage.xml ]; then <check>; fi` is the
// exact anti-pattern this gate exists to remove: it turns a vanished artifact
// into a no-op that exits 0. The report being absent IS the failure.
// -----------------------------------------------------------------------------

if (!is_file($reportPath)) {
    $coverageGateFail(
        sprintf('S316 coverage gate: the coverage report "%s" was NOT produced.', $reportPath),
        'The "Run PHPUnit" step passes `--coverage-clover coverage.xml`, and phpunit.xml also',
        'configures <coverage><report><clover outputFile="coverage.xml"/>. Both would have to be',
        'gone — or the coverage driver missing — for this file to be absent.',
        'The coverage gate cannot run: fix the test run, do not skip the gate.',
    );
}

$bytes = filesize($reportPath);

if ($bytes === false || $bytes === 0) {
    $coverageGateFail(
        sprintf('S316 coverage gate: the coverage report "%s" exists but is EMPTY (0 bytes).', $reportPath),
        'A zero-byte report is a truncated or interrupted write, not an empty result set.',
        'Uploading it would report a coverage collapse to Codecov as if it were real.',
    );
}

// -----------------------------------------------------------------------------
// 2. A parser is actually present.
//
// ext-dom ships with PHP and is enabled by default, which is precisely why this
// is an assertion rather than an assumption: the sibling repo spent its whole
// life reporting SUCCESS from a coverage gate whose parser (`xmllint`) was not
// installed on the runner at all, because a missing tool degraded into a skip.
// -----------------------------------------------------------------------------

if (!class_exists('DOMDocument') || !class_exists('DOMXPath')) {
    $coverageGateFail(
        'S316 coverage gate: PHP is missing ext-dom (DOMDocument/DOMXPath), so the coverage report '
        . 'cannot be parsed.',
        'Add "dom" to the setup-php `extensions:` list in .github/workflows/ci.yml.',
        'Do NOT add a branch that skips this gate when its parser is absent.',
    );
}

// -----------------------------------------------------------------------------
// 3. The report parses.
//
// Parsed with DOM rather than by grepping the text. `coverage.xml` is ~1 MB, and
// with `set -o pipefail` a `grep -q` that exits early on a pipeline carrying more
// than a pipe buffer reports the SIGPIPE as a failure — which inverts the sense
// of the test. There is no pipeline here at all.
// -----------------------------------------------------------------------------

$document = new DOMDocument();
$previous = libxml_use_internal_errors(true);
libxml_clear_errors();
$loaded = $document->load($reportPath, LIBXML_NONET);
$xmlErrors = libxml_get_errors();
libxml_clear_errors();
libxml_use_internal_errors($previous);

if ($loaded === false) {
    $messages = array_map(static fn (LibXMLError $error): string => trim($error->message), $xmlErrors);

    $coverageGateFail(
        sprintf('S316 coverage gate: "%s" is not parseable XML.', $reportPath),
        $messages === [] ? 'libxml reported no detail.' : 'libxml: ' . implode('; ', $messages),
    );
}

$xpath = new DOMXPath($document);

$files = $coverageGateMetric($xpath, 'files', $reportPath);
$statements = $coverageGateMetric($xpath, 'statements', $reportPath);
$covered = $coverageGateMetric($xpath, 'coveredstatements', $reportPath);

// -----------------------------------------------------------------------------
// 4. The magnitudes. Printed first, so a run that measured nothing cannot look
//    like a clean pass by being quieter than one that did.
// -----------------------------------------------------------------------------

printf(
    "S316 coverage gate: %s — %s bytes, %d files, %d statements, %d covered.\n",
    basename($reportPath),
    number_format((float) $bytes),
    $files,
    $statements,
    $covered,
);

if ($files < MIN_FILES) {
    $coverageGateFail(
        sprintf(
            'S316 coverage gate: the report accounts for only %d file(s), expected at least %d.',
            $files,
            MIN_FILES,
        ),
        'A report covering almost no files is what `<source>` no longer including src/ looks like,',
        'and it can carry a HIGHER percentage than a healthy run while measuring nearly nothing.',
    );
}

if ($statements === 0) {
    $coverageGateFail(
        'S316 coverage gate: the report contains 0 statements — the run measured NOTHING.',
        'This is a broken report, not an empty one. Check that a coverage driver is loaded',
        '(`coverage: xdebug` in setup-php) and that phpunit.xml\'s <source> still includes src/.',
        'Treating this as "nothing to check, pass" is the defect this gate exists to prevent.',
    );
}

if ($statements < MIN_STATEMENTS) {
    $coverageGateFail(
        sprintf(
            'S316 coverage gate: only %d statements are accounted for, expected at least %d.',
            $statements,
            MIN_STATEMENTS,
        ),
        'The denominator collapsed. Something removed source from the measured set; the coverage',
        'percentage below is meaningless until that is fixed.',
    );
}

if ($covered === 0) {
    $coverageGateFail(
        sprintf('S316 coverage gate: 0 of %d statements are marked covered.', $statements),
        'The report was written but the coverage driver collected nothing — the usual cause is a',
        'driver that is present but disabled for the run.',
    );
}

if ($covered > $statements) {
    $coverageGateFail(sprintf(
        'S316 coverage gate: the report is internally inconsistent — coveredstatements (%d) exceeds '
        . 'statements (%d).',
        $covered,
        $statements,
    ));
}

// -----------------------------------------------------------------------------
// 5. The floor. Rounded before comparing so the printed figure and the verdict
//    can never disagree at the boundary.
// -----------------------------------------------------------------------------

$percentage = round($covered * 100 / $statements, 2);

printf(
    "S316 coverage gate: statement coverage %.2f%% (%d / %d), floor %.2f%%.\n",
    $percentage,
    $covered,
    $statements,
    MIN_STATEMENT_COVERAGE,
);

if ($percentage < MIN_STATEMENT_COVERAGE) {
    $coverageGateFail(
        sprintf(
            'S316 coverage gate: statement coverage %.2f%% is below the floor of %.2f%%.',
            $percentage,
            MIN_STATEMENT_COVERAGE,
        ),
        'The floor sits ~12 points under the measured baseline, so this is not boundary noise —',
        'either a large tested area stopped being exercised, or the report is not what it seems.',
        'Fix the run. Lowering the floor is neutering the gate.',
    );
}

fwrite(STDOUT, "S316 coverage gate OK.\n");

exit(0);
