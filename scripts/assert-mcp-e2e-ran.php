<?php

/**
 * S329 — fail the build when the live MCP-client session cases did not EXECUTE.
 *
 * ## The defect this closes, measured
 *
 * Before S329, no real MCP client had ever touched the hub. The SSE transport
 * claim behind S62/S63 rested on unit tests that drive
 * `RecordingStreamTimers` and never enter the Workerman event loop, and — the
 * part this script exists for — nothing in CI would ever have noticed that the
 * live proof was missing. PHPUnit exits **0** with "OK, but some tests were
 * skipped!", GitHub records `skipped` as SUCCESS with no branch protection in
 * this estate, and "nothing was verified" arrives as the same green tick as
 * success.
 *
 * This is `scripts/assert-integration-tests-ran.php` (S173) / phlix-server's
 * `scripts/assert-browser-e2e-ran.php` (S305) ported to a NAMED set of cases:
 *
 *  1. **The report exists, is non-empty and parses.** A gate that cannot
 *     measure must not report success — no `|| exit 0`, no silent degradation.
 *  2. **The run contains test cases at all**, and the number is PRINTED — the
 *     commonest neuter of a gate is one that ran and inspected zero items.
 *  3. **Every required case is PRESENT by exact class+name.** Half 4 alone is
 *     satisfiable by DELETING the tests; substring matching is not used
 *     anywhere here, so a sibling name cannot absorb a deleted one.
 *  4. **No required case was skipped**, with the case name quoted.
 *  5. **Every required case recorded at least one assertion.** A case that
 *     executes and asserts nothing proves exactly as much as a skip.
 *
 * A required case that FAILED is deliberately NOT an error here: the PHPUnit
 * step already reds for that, and this gate answering "yes, it ran, and it
 * failed" on the same run is the useful reading. That is why the workflow
 * gives this step `if: always()`.
 *
 * The required set lives in
 * `tests/Support/Mcp/McpE2EProbeEnvironment::REQUIRED_CASES_BY_CLASS`, the
 * same place the prereqs script and the E2E test read from, and is reconciled
 * against the real test class by `tests/Unit/Support/McpE2EGateTest.php`.
 *
 * Usage: php scripts/assert-mcp-e2e-ran.php [path/to/junit.xml]
 *
 * @package Phlix\Hub
 */

declare(strict_types=1);

use Phlix\Hub\Tests\Support\Mcp\McpE2EProbeEnvironment;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "::error::S329 MCP E2E gate: vendor/autoload.php is missing; run composer install.\n");
    exit(1);
}
require_once $autoload;

$reportPath = $argv[1] ?? (dirname(__DIR__) . '/junit-mcp.xml');

/** Every failure path ends here; there is no fallback that returns success. */
$fail = static function (string $message): never {
    fwrite(STDERR, '::error::S329 MCP E2E gate: ' . $message . "\n");
    exit(1);
};

if (!is_file($reportPath)) {
    $fail(sprintf(
        'the JUnit report %s was not produced. The E2E step must run with `--log-junit junit-mcp.xml`; '
        . 'without the report this gate cannot tell a run that drove the REAL MCP SDK against the '
        . 'RUNNING hub from one that skipped every case. Do NOT make this exit 0 when the file is missing.',
        $reportPath,
    ));
}

if (filesize($reportPath) === 0) {
    $fail(sprintf('%s exists but is empty (0 bytes).', $reportPath));
}

if (!class_exists('DOMDocument') || !class_exists('DOMXPath')) {
    $fail(
        'PHP is missing ext-dom (DOMDocument/DOMXPath), so the JUnit report cannot be parsed. Add '
        . '`dom` to the setup-php extensions list — do not add a branch that skips this gate when its '
        . 'parser is absent.',
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
        '%s is not parseable XML (%s).',
        $reportPath,
        $messages === [] ? 'no libxml detail' : implode('; ', $messages),
    ));
}

$xpath = new DOMXPath($document);

$allCases = $xpath->query('//testcase');
$totalCases = $allCases === false ? 0 : $allCases->length;
if ($totalCases === 0) {
    $fail(sprintf('%s contains no <testcase> elements. An empty run is not a pass.', $reportPath));
}

/**
 * The whole-run skip census, by name. Always PRINTED: the before/after of this
 * gate is an accounting of skips, and a census that only a human with the raw
 * report can reproduce is not an accounting.
 *
 * @var list<string> $skipped
 */
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

/**
 * Exact-match lookup. `@class` and `@name` are compared for equality, never
 * with `contains()`: a data-provider variant or a sibling test whose name
 * merely starts the same way must not be able to stand in for the case being
 * demanded.
 *
 * @return array{node: DOMElement, assertions: int, time: float, status: string}|null
 */
$findCase = static function (DOMXPath $xpath, string $class, string $method): ?array {
    $nodes = $xpath->query(sprintf(
        '//testcase[@class = "%s" and @name = "%s"]',
        $class,
        $method,
    ));
    if ($nodes === false || $nodes->length === 0) {
        return null;
    }
    $node = $nodes->item(0);
    if (!$node instanceof DOMElement) {
        return null;
    }

    $status = 'executed';
    foreach (['skipped', 'error', 'failure'] as $child) {
        if ($node->getElementsByTagName($child)->length > 0) {
            $status = $child;
            break;
        }
    }

    return [
        'node' => $node,
        'assertions' => (int) $node->getAttribute('assertions'),
        'time' => (float) $node->getAttribute('time'),
        'status' => $status,
    ];
};

$byClass = McpE2EProbeEnvironment::REQUIRED_CASES_BY_CLASS;
$requiredCount = McpE2EProbeEnvironment::requiredCaseCount();

/** @var list<string> $missing */
$missing = [];
/** @var list<string> $wereSkipped */
$wereSkipped = [];
/** @var list<string> $assertedNothing */
$assertedNothing = [];
/** @var list<string> $lines */
$lines = [];
$wallClock = 0.0;

foreach ($byClass as $class => $required) {
    $lines[] = $class;
    foreach ($required as $method) {
        $case = $findCase($xpath, $class, $method);
        if ($case === null) {
            $missing[] = $class . '::' . $method;
            continue;
        }

        $wallClock += $case['time'];
        $lines[] = sprintf(
            '  %-72s %-8s assertions=%d  %.1fs',
            $method,
            $case['status'],
            $case['assertions'],
            $case['time'],
        );

        if ($case['status'] === 'skipped') {
            $wereSkipped[] = $class . '::' . $method;
            continue;
        }

        if ($case['assertions'] < 1) {
            $assertedNothing[] = $class . '::' . $method;
        }
    }
}

if ($missing !== []) {
    $fail(sprintf(
        "%d of the %d required live-session cases are ABSENT from %s:\n  %s\n"
        . "They were not skipped — they are missing, so either the E2E suite is no longer being run "
        . "(check the --testsuite E2E invocation) or the cases were renamed or deleted. Renaming them "
        . "means renaming them in tests/Support/Mcp/McpE2EProbeEnvironment.php too; deleting them is "
        . "deleting the only evidence that a real MCP client can hold an SSE session with the hub.",
        count($missing),
        $requiredCount,
        basename($reportPath),
        implode("\n  ", $missing),
    ));
}

if ($wereSkipped !== []) {
    $fail(sprintf(
        "%d of the %d required live-session cases were SKIPPED:\n  %s\n"
        . "A skipped test reads as a pass — PHPUnit exits 0 with \"OK, but some tests were skipped!\" "
        . "— so this build proves less than it appears to. PHPUnit's JUnit logger records no reason, "
        . "so read the markTestSkipped() guards in McpClientSseE2ETest::setUp(): a missing "
        . "HUB_MCP_E2E_BASE_URL, a missing tokens file, no node, or no @modelcontextprotocol/sdk. The "
        . "`Assert the MCP E2E prerequisites` step exists to make all four impossible and must have "
        . "gone wrong. Fix the prerequisite. Do NOT relax this gate.",
        count($wereSkipped),
        $requiredCount,
        implode("\n  ", $wereSkipped),
    ));
}

if ($assertedNothing !== []) {
    $fail(sprintf(
        "%d of the %d required live-session cases executed but recorded ZERO assertions:\n  %s\n"
        . "A case that asserts nothing proves exactly as much as one that skipped. (If the run is red "
        . "for another reason, read the PHPUnit output first: a case that errors before its first "
        . "assertion also lands here.)",
        count($assertedNothing),
        $requiredCount,
        implode("\n  ", $assertedNothing),
    ));
}

printf(
    "S329 MCP E2E gate OK: %d/%d required live-session cases across %d classes EXECUTED in %s "
    . "(%d test cases in the run, %d skipped).\n",
    $requiredCount,
    $requiredCount,
    count($byClass),
    basename($reportPath),
    $totalCases,
    count($skipped),
);
printf("%s\n", implode("\n", $lines));
printf("  live-session cases wall clock: %.1fs\n", $wallClock);

if ($skipped === []) {
    fwrite(STDOUT, "No test in this run was skipped.\n");
} else {
    printf("Skips in this run (%d), for the record — none of them a required live-session case:\n", count($skipped));
    foreach ($skipped as $case) {
        printf("  %s\n", $case);
    }
}

exit(0);
