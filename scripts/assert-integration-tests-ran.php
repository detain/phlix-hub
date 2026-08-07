<?php

/**
 * S173 — fail the build when the test suite reported success without running the
 * tests that need a real database.
 *
 * ## The defect this closes, measured on 2026-08-02
 *
 * `.github/workflows/ci.yml` had **no `services:` block, no mysql and no
 * `HUB_TEST_DB_*`**. Every test under `tests/Integration/` gates on those
 * variables, so all 31 of them called `markTestSkipped()` on every single CI run
 * since the workflow was written — identically on master and on every branch.
 * Auth, login rate-limiting, the migration chain and the relay throttle all have
 * real-DB tests that had never executed in CI. One of them
 * (`RelaySessionManagerThrottleIntegrationTest`) was run for the first time ever
 * by hand during the S41 audit.
 *
 * 🔴 **A skipped test is not a neutral absence — it reads as a pass.** PHPUnit
 * exits **0** with `OK, but some tests were skipped!`, so the check goes green
 * and the "nothing was verified" signal is the same green tick as success. This
 * is the "a job may never have RUN" / "a gate can PASS a broken artifact" shape.
 *
 * ## What this script asserts, and why each half exists
 *
 *  1. **The report exists, is non-empty and parses.** A gate that cannot measure
 *     must not report success — no `|| exit 0`, no silent degradation. (The
 *     sibling repo spent its whole life reporting success from a coverage gate
 *     whose parser was not installed.)
 *  2. **The run contains test cases at all.** An empty report is not a pass.
 *  3. **Nothing was skipped.** This is the direct fix: a misconfigured MySQL
 *     service leaves the integration tests skipping and the job green, which is
 *     exactly the failure being repaired. Every skip is listed by name.
 *  4. **At least {@see MIN_INTEGRATION_TESTS} integration test cases ran.** Half
 *     3 alone is satisfiable by DELETING the integration tests, which would be a
 *     green build that verifies even less. This is a floor, not an equality, so
 *     adding integration tests never breaks it.
 *
 * The skip check is deliberately whole-suite rather than integration-only: a unit
 * test that starts skipping (a missing extension, a `markTestSkipped()` left in
 * after debugging) is the same lie in a different place. When a skip is genuinely
 * warranted, add it to {@see ALLOWED_SKIPS} with a written justification — the
 * allowance is explicit and reviewable, never a lowered number.
 *
 * Usage: php scripts/assert-integration-tests-ran.php [path/to/junit.xml]
 *
 * @package Phlix\Hub
 */

declare(strict_types=1);

/**
 * Floor for the number of executed `Phlix\Hub\Tests\Integration\*` test cases.
 *
 * 31 is the count the five integration files produced on master when they were
 * all skipping (measured: `Tests: 2504 … Skipped: 31`, and the Integration
 * testsuite reports `Tests: 31` on its own). Raise it when integration coverage
 * grows; lowering it is how this gate would be neutered, so a lower value needs
 * the same scrutiny as deleting the check.
 */
const MIN_INTEGRATION_TESTS = 31;

/** Class-name prefix that identifies a real-database test case. */
const INTEGRATION_CLASS_PREFIX = 'Phlix\\Hub\\Tests\\Integration\\';

/**
 * Skips that are explicitly accepted, as `Class::method` => justification.
 *
 * Empty on purpose: on CI (swoole + a MySQL service) nothing legitimately skips.
 * Anything added here must name WHY the skip is correct in CI, not merely that it
 * happens.
 *
 * @var array<string, string>
 */
const ALLOWED_SKIPS = [];

$reportPath = $argv[1] ?? (dirname(__DIR__) . '/junit.xml');

/**
 * Emit a GitHub-annotated error and exit non-zero. Every failure path in this
 * script ends here; there is no fallback that returns success.
 */
$fail = static function (string $message): never {
    fwrite(STDERR, '::error::' . $message . "\n");
    exit(1);
};

if (!is_file($reportPath)) {
    $fail(sprintf(
        'S173 integration-test gate: the JUnit report %s was not produced. The PHPUnit step must run '
        . 'with `--log-junit junit.xml`; without the report this gate cannot tell a run that executed '
        . 'the real-database tests from one that skipped all of them. Do NOT make this exit 0 when the '
        . 'file is missing.',
        $reportPath,
    ));
}

if (filesize($reportPath) === 0) {
    $fail(sprintf('S173 integration-test gate: %s exists but is empty (0 bytes).', $reportPath));
}

if (!class_exists('DOMDocument') || !class_exists('DOMXPath')) {
    $fail(
        'S173 integration-test gate: PHP is missing ext-dom (DOMDocument/DOMXPath), so the JUnit '
        . 'report cannot be parsed. Add `dom` to the setup-php extensions list — do not add a branch '
        . 'that skips this gate when its parser is absent.',
    );
}

$document = new DOMDocument();
$previous = libxml_use_internal_errors(true);
$loaded = $document->load($reportPath);
$xmlErrors = libxml_get_errors();
libxml_clear_errors();
libxml_use_internal_errors($previous);

if ($loaded === false) {
    $messages = array_map(static fn (LibXMLError $e): string => trim($e->message), $xmlErrors);
    $fail(sprintf(
        'S173 integration-test gate: %s is not parseable XML (%s).',
        $reportPath,
        $messages === [] ? 'no libxml detail' : implode('; ', $messages),
    ));
}

$xpath = new DOMXPath($document);

$allCases = $xpath->query('//testcase');
if ($allCases === false || $allCases->length === 0) {
    $fail(sprintf(
        'S173 integration-test gate: %s contains no <testcase> elements. An empty run is not a pass.',
        $reportPath,
    ));
}

/** @var list<string> $skipped */
$skipped = [];
$skippedNodes = $xpath->query('//testcase[skipped]');
if ($skippedNodes !== false) {
    foreach ($skippedNodes as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }
        $class = $node->getAttribute('class');
        $name = $node->getAttribute('name');
        $skipped[] = ($class === '' ? '?' : $class) . '::' . ($name === '' ? '?' : $name);
    }
}

$unjustified = array_values(array_filter(
    $skipped,
    static fn (string $case): bool => !array_key_exists($case, ALLOWED_SKIPS),
));

/**
 * The skips this run actually consumed an allowance for.
 *
 * This is what "an allowance is in effect" means: an entry of
 * {@see ALLOWED_SKIPS} that a real skip in THIS report matched. Reporting the
 * declared constant instead would say the same thing on every run — including
 * runs where nothing skipped at all — which is why it is derived from
 * `$skipped` here.
 *
 * @var list<string> $justified
 */
$justified = array_values(array_filter(
    $skipped,
    static fn (string $case): bool => array_key_exists($case, ALLOWED_SKIPS),
));

$integrationCases = $xpath->query(
    sprintf('//testcase[starts-with(@class, "%s")]', INTEGRATION_CLASS_PREFIX),
);
$integrationCount = $integrationCases === false ? 0 : $integrationCases->length;

$integrationSkipped = array_values(array_filter(
    $skipped,
    static fn (string $case): bool => str_starts_with($case, INTEGRATION_CLASS_PREFIX),
));

if ($unjustified !== []) {
    $shown = array_slice($unjustified, 0, 40);
    $suffix = count($unjustified) > count($shown)
        ? sprintf("\n  … and %d more", count($unjustified) - count($shown))
        : '';

    $fail(sprintf(
        "S173 integration-test gate: %d test(s) were SKIPPED, %d of them real-database tests under %s. "
        . "A skipped test reads as a pass — PHPUnit exits 0 with \"OK, but some tests were skipped!\" — "
        . "so this build proves less than it appears to.\n"
        . "  Most likely cause: the MySQL service is unreachable or HUB_TEST_DB_HOST / HUB_TEST_DB_PORT "
        . "/ HUB_TEST_DB_USER / HUB_TEST_DB_PASSWORD / HUB_TEST_DB_NAME are missing or wrong for the "
        . "PHPUnit step. Fix the configuration; do NOT relax this gate.\n  %s%s",
        count($unjustified),
        count($integrationSkipped),
        INTEGRATION_CLASS_PREFIX,
        implode("\n  ", $shown),
        $suffix,
    ));
}

if ($integrationCount < MIN_INTEGRATION_TESTS) {
    $fail(sprintf(
        'S173 integration-test gate: only %d test case(s) under %s executed, expected at least %d. '
        . 'Nothing was skipped, so they were not gated out — they are MISSING. Deleting or renaming '
        . 'the real-database tests must not be a way to make this gate green.',
        $integrationCount,
        INTEGRATION_CLASS_PREFIX,
        MIN_INTEGRATION_TESTS,
    ));
}

printf(
    "S173 integration-test gate OK: %d test cases in %s, 0 skipped, %d of them real-database tests "
    . "under %s (floor %d).\n",
    $allCases->length,
    basename($reportPath),
    $integrationCount,
    INTEGRATION_CLASS_PREFIX,
    MIN_INTEGRATION_TESTS,
);

if ($justified !== []) {
    printf(
        "Justified skip allowances applied to this run (%d): %s\n",
        count($justified),
        implode(', ', $justified),
    );
}

exit(0);
