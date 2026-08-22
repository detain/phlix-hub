<?php

/**
 * S329 — install-time PROOF that the live MCP-client session can run.
 *
 * ## The defect this closes, measured
 *
 * The hub's MCP endpoint had 14 unit test files and not one of them had ever
 * been exercised by a real MCP client. The transport claim behind S62/S63
 * rested on tests that drive `RecordingStreamTimers` and never enter the
 * Workerman event loop — the same class of "verified against a double" that
 * S57's caption tests were. The fix is a CI job that boots the REAL hub and
 * drives it with the OFFICIAL MCP SDK client.
 *
 * This script is the SUPPLY half of that fix, mirroring phlix-server's
 * S305 `ci-browser-e2e-prereqs.php`: it fails CHEAPLY when a prerequisite is
 * missing — node, the pinned `@modelcontextprotocol/sdk`, the PHP extensions
 * the hub needs to boot, and the phpunit E2E wiring — so a missing ingredient
 * reds in seconds instead of surfacing as a confusing failure halfway through
 * the job. The PROOF half is `scripts/assert-mcp-e2e-ran.php`, which reads
 * the JUnit report afterwards and fails when a required case did not execute.
 * Neither substitutes for the other: this one can only say the ingredients are
 * present, that one says the cases ran.
 *
 * ## The SDK pin
 *
 * The SDK is installed by `npm ci` in `tests/E2E/Mcp`, whose committed
 * `package-lock.json` pins `@modelcontextprotocol/sdk` to an exact version and
 * verifies its integrity. This script READS that pin and compares it with the
 * INSTALLED version, so the CI job cannot silently drift to a newer SDK than
 * the probe was written against. It does not fetch anything itself — an
 * unpinned network fetch in a merge-deciding job is the S309 regression.
 *
 * ## Usage
 *
 *   php scripts/ci-mcp-e2e-prereqs.php
 *
 * The `--node=` / `--sdk-dist=` options exist ONLY so
 * `tests/Unit/Support/McpE2EGateTest.php` can exercise the failure paths
 * offline. Using them in the workflow would narrow the gate, so that same test
 * asserts the CI step passes neither.
 *
 * @package Phlix\Hub
 */

declare(strict_types=1);

use Phlix\Hub\Tests\Support\Mcp\McpE2EProbeEnvironment;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "::error::S329 MCP E2E prerequisites: vendor/autoload.php is missing; run composer install.\n");
    exit(1);
}
require_once $autoload;

/** Every failure path ends here. There is no branch that returns success unmeasured. */
$fail = static function (string $message): never {
    fwrite(STDERR, '::error::S329 MCP E2E prerequisites: ' . $message . "\n");
    exit(1);
};

$say = static function (string $message): void {
    fwrite(STDOUT, $message . "\n");
};

/**
 * @param list<string> $argv
 */
$option = /** @param list<string> $argv @return ?string */ static function (array $argv, string $name): ?string {
    foreach ($argv as $arg) {
        if (str_starts_with($arg, "--{$name}=")) {
            return substr($arg, strlen($name) + 3);
        }
    }

    return null;
};

/** @var list<string> $args */
$args = array_slice($argv, 1);
$nodeOverride = $option($args, 'node');
$sdkDistOverride = $option($args, 'sdk-dist');

// ---------------------------------------------------------------------------
// 1. node — the probe runs on it.
// ---------------------------------------------------------------------------

$node = null;
if ($nodeOverride !== null) {
    // Unit-test override: the FAILURE path is what matters offline, and the
    // override is still verified — a path that is not a working node is a
    // missing node, not a pass.
    $node = McpE2EProbeEnvironment::nodeAt($nodeOverride);
} else {
    $node = McpE2EProbeEnvironment::node();
}
if ($node === null) {
    $fail(sprintf(
        'no node >= %d was found. The probe drives the official MCP SDK, which is a node package. '
        . 'The ubuntu-latest runner ships node; if it ever stops doing so, this job reds HERE rather '
        . 'than three skipped tests later.',
        McpE2EProbeEnvironment::MIN_NODE_MAJOR,
    ));
}
$say(sprintf('node: %s', $node));

// ---------------------------------------------------------------------------
// 2. The pinned MCP SDK — installed and EXACTLY the pinned version.
// ---------------------------------------------------------------------------

$sdkDist = null;
if ($sdkDistOverride !== null) {
    // Unit-test override: still verified — a path that is not the SDK's dist
    // file is a missing SDK, not a pass.
    $sdkDist = is_file($sdkDistOverride) ? $sdkDistOverride : null;
} else {
    $sdkDist = McpE2EProbeEnvironment::sdkDistFile();
}
if ($sdkDist === null) {
    $fail(sprintf(
        '%s is not installed. Run `npm ci` in tests/E2E/Mcp (the committed package-lock.json pins '
        . 'the exact version). Do NOT make this step non-fatal — a missing SDK means the live-session '
        . 'suite skips, and a skipped test reads as a pass.',
        McpE2EProbeEnvironment::SDK_PACKAGE_DIR,
    ));
}
$say(sprintf('sdk dist: %s', $sdkDist));

$installed = McpE2EProbeEnvironment::installedSdkVersion();
$pinned = McpE2EProbeEnvironment::pinnedSdkVersion();
if ($installed === null || $pinned === null) {
    $fail('could not read the installed or pinned SDK version.');
}
$say(sprintf('sdk installed: %s', $installed));
$say(sprintf('sdk pinned   : %s', $pinned));
if ($installed !== $pinned) {
    $fail(sprintf(
        'the installed SDK version %s does not match the pinned %s in tests/E2E/Mcp/package-lock.json. '
        . 'Re-run `npm ci` or update the pin deliberately — a silently drifted SDK is how the probe '
        . 'stops proving what it was reviewed against.',
        $installed,
        $pinned,
    ));
}

// 2b. The pinned SDK must also LOAD in node. Existence plus version equality
// can still pass a package whose `exports` map broke or whose dist was
// truncated; importing the very entry point the probe imports proves the
// whole module graph resolves. A packaging regression then reds HERE, seconds
// into the job, instead of as a confusing E2E failure after the hub has booted.
$loadScript = sprintf(
    'await import("file://%s"); console.log("sdk load: ok");',
    $sdkDist,
);
/** @var list<string> $loadOut */
$loadOut = [];
$loadExit = 0;
exec(
    escapeshellarg($node) . ' --input-type=module -e ' . escapeshellarg($loadScript) . ' 2>&1',
    $loadOut,
    $loadExit,
);
// exec()'s by-reference output param resets the type to `mixed`; the lines it
// appended are strings, so re-state that for the analysers before any use.
/** @var list<string> $loadOut */
if ($loadExit !== 0) {
    $fail(sprintf(
        'the pinned SDK does not LOAD in node %s (%s): %s',
        $node,
        $sdkDist,
        implode("\n", $loadOut),
    ));
}
$say('sdk loads in node: yes');

// ---------------------------------------------------------------------------
// 3. The PHP extensions the hub needs to BOOT on this runner.
// ---------------------------------------------------------------------------

$bootExtensions = ['swoole', 'uv', 'pcntl', 'posix'];
/** @var list<string> $missing */
$missing = array_values(array_filter(
    $bootExtensions,
    static fn (string $extension): bool => !extension_loaded($extension),
));
if ($missing !== []) {
    $fail(sprintf(
        'the hub cannot boot without %s; the mcp-e2e job must install them (setup-php + the php-uv '
        . 'build) exactly as the phpunit job does.',
        implode(', ', $missing),
    ));
}
$say('php boot extensions: ' . implode(', ', $bootExtensions) . ' — all present');

// ---------------------------------------------------------------------------
// 4. The phpunit E2E wiring — the suite the job runs must exist and must be
//    OUT of the default Unit suite (which would otherwise skip it in CI and
//    red the S173 whole-suite zero-skip gate).
// ---------------------------------------------------------------------------

$phpunitXml = __DIR__ . '/../phpunit.xml';
if (!is_file($phpunitXml)) {
    $fail('phpunit.xml is missing.');
}
$xml = (string) file_get_contents($phpunitXml);
if (!str_contains($xml, '<testsuite name="E2E">')) {
    $fail('phpunit.xml has no E2E testsuite; the CI job runs --testsuite E2E and would fail.');
}
if (!str_contains($xml, '<exclude>tests/E2E</exclude>')) {
    $fail(
        'phpunit.xml does not exclude tests/E2E from the default Unit suite. Without the exclude, the '
        . 'phpunit job would run the live-session cases against a non-existent hub, they would skip, '
        . 'and the S173 whole-suite zero-skip gate would red.',
    );
}
$say('phpunit.xml: E2E testsuite registered and excluded from the default Unit suite');

$required = McpE2EProbeEnvironment::requiredCaseCount();
$say(sprintf(
    'S329 MCP E2E prerequisites OK: node %s, sdk %s (pinned %s), boot extensions present, '
    . '%d required live-session cases wired — nothing may skip.',
    $node,
    $installed,
    $pinned,
    $required,
));

exit(0);
