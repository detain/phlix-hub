<?php

/**
 * S204 — assert that phlix-hub and phlix-server name the SAME server-facing
 * paths, by reading both repositories' real source.
 *
 * ## The defect this closes, measured on 2026-08-03
 *
 * phlix-server requested `/api/v1/servers/{id}/subdomain` (`src/Hub/
 * SubdomainClient.php`, claim and release) while phlix-hub registered
 * `/servers/{id}/subdomain` at the router ROOT. Subdomain claim and release
 * 404'd in production from hub commit `19d05b7` onward with every gate in both
 * repositories green, because the mismatch is BETWEEN repositories and neither
 * side's suite can see the other's source: the hub's controller tests call
 * `SubdomainController` directly so the path string never reaches a `Router`,
 * and phlix-server's `SubdomainClientTest` stubs its HTTP client and asserts no
 * path at all.
 *
 * ## Why this is a script and not a PHPUnit test
 *
 * The hub's CI clones ONE repository, so a test that reads phlix-server can only
 * be conditional — and a conditional test in this suite is exactly the lie
 * {@see assert-integration-tests-ran.php} exists to catch: it `markTestSkipped`s
 * in CI, PHPUnit still exits 0, and the build reports coverage that never ran.
 * (That is not hypothetical — the first cut of this check shipped as a skipping
 * test and reddened the S173 gate on PR #209.) The hub-side half of the pin does
 * NOT live here: it is four unconditional dispatch tests in
 * `tests/Unit/Http/RouteRegistration/HubServerPathContractTest.php`, which run
 * everywhere and never skip. This script is the other half — the half that can
 * see phlix-server MOVE — and it is run wherever both checkouts exist.
 *
 * ## It fails when it cannot measure
 *
 * There is no `exit 0` for "phlix-server was not found". A checker that reports
 * success without having compared anything is worse than no checker, so an
 * absent sibling checkout, an unreadable file, or a path literal that cannot be
 * extracted from either side is a FAILURE with an explanation, never a pass.
 *
 * Usage:
 *   php scripts/assert-cross-repo-hub-paths.php [/path/to/phlix-server]
 *
 * Default sibling location: `<hub-repo>/../phlix-server`.
 *
 * @package Phlix\Hub
 */

declare(strict_types=1);

/**
 * Placeholder both sides are normalised to before comparison.
 *
 * The two repositories spell the variable segment differently — the hub's router
 * template uses `{id}`, phlix-server interpolates `{$this->serverId}` into a
 * double-quoted string — so a raw `===` would report a false mismatch. Only the
 * variable segment is normalised; every other character must match exactly.
 */
const PATH_PLACEHOLDER = '{SERVER_ID}';

/**
 * The comparisons to perform, as
 * `label => [hub extractor, server extractor]`, each extractor being
 * `[relative file, PCRE with one capturing group, expected match count]`.
 *
 * Both sides are EXTRACTED rather than hard-coded: a constant restating what the
 * code should say would self-adjust with neither repo and could never go red.
 * The expected counts are exact, not floors — a second, divergent call site on
 * either side is itself the drift this guards against.
 *
 * @var array<string, array{
 *     hub: array{0: string, 1: non-empty-string, 2: int},
 *     server: array{0: string, 1: non-empty-string, 2: int}
 * }>
 */
const CONTRACTS = [
    'subdomain claim + release' => [
        // Application::registerServerRoutes() — POST and DELETE.
        'hub' => [
            'src/Application.php',
            '/->(?:post|delete)\(\s*\'([^\']*\/subdomain)\'/',
            2,
        ],
        // SubdomainClient::claimSubdomain() and ::releaseSubdomain().
        'server' => [
            'src/Hub/SubdomainClient.php',
            '/"([^"]*\/subdomain)"/',
            2,
        ],
    ],
    'relay tunnel endpoint' => [
        'hub' => [
            'src/Application.php',
            '/->post\(\s*\'([^\']*\/relay)\'/',
            1,
        ],
        // config/relay.php `hub_wss_url` — a full wss:// URL; the path is taken
        // from it below.
        'server' => [
            'config/relay.php',
            '/\'hub_wss_url\'\s*=>\s*\'([^\']+)\'/',
            1,
        ],
    ],
];

$hubRoot = dirname(__DIR__);
$serverRoot = $argv[1] ?? (dirname($hubRoot) . '/phlix-server');

/**
 * Emit a GitHub-annotated error and exit non-zero. Every failure path ends here.
 */
$fail = static function (string $message): never {
    fwrite(STDERR, '::error::S204 cross-repo path contract: ' . $message . "\n");
    exit(1);
};

if (!is_dir($serverRoot)) {
    $fail(sprintf(
        'phlix-server was not found at %s, so nothing could be compared. Pass the checkout explicitly '
        . '(`php scripts/assert-cross-repo-hub-paths.php /path/to/phlix-server`). This script does NOT '
        . 'exit 0 when the sibling repository is absent: reporting success without having read the '
        . 'other side is the failure mode it exists to prevent.',
        $serverRoot,
    ));
}

/**
 * Read one side and return every distinct path literal the pattern captures.
 *
 * @param string           $root     Repository root.
 * @param string           $relative File to read, relative to `$root`.
 * @param non-empty-string $pattern  PCRE with exactly one capturing group.
 * @param int              $expected Exact number of matches required.
 * @param callable(string): never $fail Failure emitter.
 *
 * @return list<string> Distinct captured literals.
 */
$extract = static function (
    string $root,
    string $relative,
    string $pattern,
    int $expected,
    callable $fail
): array {
    $path = $root . '/' . $relative;

    if (!is_file($path)) {
        $fail(sprintf('%s does not exist, so its path literals could not be read.', $path));
    }

    $source = file_get_contents($path);
    if (!is_string($source) || $source === '') {
        $fail(sprintf('%s could not be read (or is empty).', $path));
        // Unreachable — $fail() exits. Present so static analysis can see the
        // branch terminate and narrow $source, rather than widening a type.
        exit(1);
    }

    if (preg_match_all($pattern, $source, $matches) === false) {
        $fail(sprintf('the extraction pattern %s failed against %s.', $pattern, $path));
    }

    /** @var list<string> $captured */
    $captured = $matches[1];

    if (count($captured) !== $expected) {
        $fail(sprintf(
            'expected %d path literal(s) matching %s in %s, found %d (%s). The file has been '
            . 'restructured: fix the pattern deliberately rather than loosening the count, or this '
            . 'check stops measuring what it claims to.',
            $expected,
            $pattern,
            $path,
            count($captured),
            $captured === [] ? 'none' : implode(', ', $captured),
        ));
    }

    return array_values(array_unique($captured));
};

/**
 * Reduce a path literal from either repository to a comparable template:
 * strip any scheme/host, then normalise the variable segment.
 *
 * @param string $literal Raw literal as written in source.
 */
$normalise = static function (string $literal): string {
    $path = $literal;

    if (preg_match('#^[a-z]+://#i', $path) === 1) {
        $parsed = parse_url($path, PHP_URL_PATH);
        $path = is_string($parsed) ? $parsed : $path;
    }

    return (string) preg_replace(
        ['/\{\$this->serverId\}/', '/\{id\}/', '/\{serverId\}/', '/\{server_id\}/'],
        PATH_PLACEHOLDER,
        $path,
    );
};

$checked = 0;

foreach (CONTRACTS as $label => $sides) {
    [$hubFile, $hubPattern, $hubCount] = $sides['hub'];
    [$serverFile, $serverPattern, $serverCount] = $sides['server'];

    $hubPaths = $extract($hubRoot, $hubFile, $hubPattern, $hubCount, $fail);
    $serverPaths = $extract($serverRoot, $serverFile, $serverPattern, $serverCount, $fail);

    $hubTemplates = array_values(array_unique(array_map($normalise, $hubPaths)));
    $serverTemplates = array_values(array_unique(array_map($normalise, $serverPaths)));

    if (count($hubTemplates) !== 1) {
        $fail(sprintf(
            '%s: phlix-hub registers more than one distinct path for it (%s) in %s.',
            $label,
            implode(', ', $hubTemplates),
            $hubFile,
        ));
    }

    if (count($serverTemplates) !== 1) {
        $fail(sprintf(
            '%s: phlix-server calls more than one distinct path for it (%s) in %s.',
            $label,
            implode(', ', $serverTemplates),
            $serverFile,
        ));
    }

    if ($hubTemplates[0] !== $serverTemplates[0]) {
        $fail(sprintf(
            "%s: THE TWO REPOSITORIES DISAGREE.\n"
            . "  phlix-hub    registers %s  (%s/%s)\n"
            . "  phlix-server calls     %s  (%s/%s)\n"
            . '  A request to the second reaches nothing in the first — this is the S204 production '
            . '404. One of the two has to move; they cannot both be right.',
            $label,
            $hubTemplates[0],
            $hubRoot,
            $hubFile,
            $serverTemplates[0],
            $serverRoot,
            $serverFile,
        ));
    }

    printf("OK  %-26s both repositories name %s\n", $label, $hubTemplates[0]);
    ++$checked;
}

if ($checked !== count(CONTRACTS)) {
    $fail(sprintf('only %d of %d contracts were checked.', $checked, count(CONTRACTS)));
}

printf(
    "S204 cross-repo path contract OK: %d contract(s) compared between %s and %s.\n",
    $checked,
    $hubRoot,
    $serverRoot,
);

exit(0);
