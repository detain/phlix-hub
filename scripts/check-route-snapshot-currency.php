<?php

/**
 * S492 — judge the vendored phlix-server route snapshot by CONTENT, not by a
 * commit-sha string.
 *
 * ## The defect this replaces
 *
 * The `Server Route Snapshot Currency` job used to be a single exact-string
 * test: `fixture.source_sha === phlix-server live master`. That made provenance
 * the definition of freshness, so EVERY merge into a busy phlix-server — bundle
 * bumps, docs, CI churn, anything that moved master without touching a route —
 * reddened EVERY open phlix-hub PR. Spawn case: server #781 (a bundle-only merge,
 * zero route change) turned hub PR #308's currency job red for a drift that did
 * not exist.
 *
 * ## The owner-relaxed definition (batch39 F2 option (b), ruled 2026-09-14)
 *
 *     currency := the snapshot's route CONTENT still matches what live
 *     phlix-server registers.
 *
 * - content digest EQUAL  -> GREEN, even when source_sha lags; a lag prints a
 *   loud, NON-BLOCKING `::warning::` so the snapshot does not rot silently.
 * - content digest DIFFERS -> RED: a real route drifted and the snapshot is
 *   lying to every S107/S332 deny-enumeration that reads it.
 * - the other side cannot be measured (no checkout, unreadable consts, an empty
 *   parse) -> RED. Network/measure failure is NEVER a pass (the original
 *   doctrine, ci.yml line :87).
 *
 * ## Why the consts are a faithful, boot-free content oracle
 *
 * The snapshot's `sha256` is proven (dump-phlix-server-route-manifest.php :246)
 * to cover the sorted-unique `METHOD path` corpus ONLY — source_sha, generator,
 * route_count and JSON formatting sit inside the fixture bytes but OUTSIDE the
 * digest. That same corpus is what phlix-server commits as two wire-guard
 * constants:
 *
 *   - ApplicationRouterWirePathGuardTest::ROUTE_MANIFEST   (367 entries)
 *   - WebPortalRouterWirePathGuardTest::ROUTE_MANIFEST     ( 48 entries)
 *
 * whose union minus shared rails is the 404-tuple snapshot. Both server CI jobs
 * compare their const against the FULL rendered production router table
 * VERBATIM, so an unregistered-in-const route reddens phlix-server itself. The
 * faithfulness of this gate is therefore INHERITED from phlix-server's guards,
 * not re-proven here — exactly what makes a content compare IFF-clean where the
 * sha compare was over-strict. The parse below is the concatenation-aware one
 * already proven by phlix-contracts/scripts/generate-server-route-manifest.mjs,
 * and it reproduces the pinned digest `97d6e62e…` byte-for-byte at the fixture's
 * source_sha (see the S492 §5A fidelity transcript in the PR body).
 *
 * ## Why a script and not a test
 *
 * Same reason as scripts/assert-cross-repo-hub-paths.php: hub CI checks out ONE
 * repository, so a cross-repo comparison can only live in a script the job runs
 * after it fetches the other side. The guard for THIS script's wiring, and its
 * executable behavioural proof (synthetic lag = GREEN, planted route = RED), is
 * tests/Unit/Support/SnapshotCurrencyCiWiringTest.php.
 *
 * Usage:
 *   php scripts/check-route-snapshot-currency.php [phlix-server-root] [fixture] [live-sha]
 *
 * Defaults: sibling `<hub>/../phlix-server`, the vendored fixture, and a live sha
 * read from `git rev-parse HEAD` in the server root.
 *
 * @package Phlix\Hub
 */

declare(strict_types=1);

/**
 * The two committed phlix-server route tables this gate re-derives the snapshot
 * from. Both are `private const ROUTE_MANIFEST = [ ... ]` lists of exact
 * `'VERB path -> Handler [Middleware]'` strings, whole-list-guarded at server CI.
 *
 * @var list<string>
 */
const S492_MANIFEST_SOURCES = [
    'ApplicationRouterWirePathGuardTest::ROUTE_MANIFEST'
        => 'tests/Unit/Server/Core/ApplicationRouterWirePathGuardTest.php',
    'WebPortalRouterWirePathGuardTest::ROUTE_MANIFEST'
        => 'tests/Unit/Server/WebPortal/WebPortalRouterWirePathGuardTest.php',
];

const S492_HTTP_VERBS = 'GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS';

/**
 * Provenance marker for this gate. Declared here (not only in a comment) so
 * tests/Unit/Support/SnapshotCurrencyCiWiringTest.php can pin, at run time,
 * that the script it wires is the S492 content-currency gate and not a
 * substituted look-alike.
 */
const S492_CURRENCY_GATE = 'S492CURRCONTEX9P1';

/**
 * Emit a GitHub-annotated error and halt. Every failure path ends here —
 * a comparison that cannot be made must never report success.
 */
$fail = static function (string $message): never {
    fwrite(STDERR, '::error::S492 route snapshot currency: ' . $message . "\n");
    exit(1);
};

$hubRoot = dirname(__DIR__);
$serverRoot = $argv[1] ?? (dirname($hubRoot) . '/phlix-server');
$fixturePath = $argv[2] ?? ($hubRoot . '/tests/Unit/Http/Controllers/Fixtures/phlix-server-route-manifest.json');
$liveShaArg = $argv[3] ?? null;

/**
 * Read and type-check the vendored snapshot ONCE, at the boundary, so the rest of
 * the script works on trusted locals and never indexes a `mixed` value (and never
 * casts just to appease an analyser).
 *
 * It takes the failure emitter as a `callable(string): never` PARAMETER rather than
 * capturing the top-level `$fail` closure: that is the one form PHPStan refuses to
 * short-circuit at the call site, so the explicit unreachable `exit(1)` after each
 * guard survives — which is exactly what gives BOTH PHPStan and Psalm the type
 * narrowing across the guard. (Same idiom as the `$extract` closure in
 * scripts/assert-cross-repo-hub-paths.php.) A top-level local-`$fail` call, by
 * contrast, PHPStan treats as terminating, so an `exit(1)` after it is dead code.
 *
 * @return array{0: string, 1: string, 2: int, 3: list<string>}
 */
$parseFixture = static function (string $path, callable $fail): array {
    if (!is_file($path)) {
        $fail(sprintf('the route snapshot fixture %s is missing — nothing to compare against.', $path));
        exit(1); // unreachable
    }

    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        $fail('the route snapshot fixture is not a JSON object — cannot judge currency.');
        exit(1); // unreachable
    }

    $sourceShaField = $decoded['source_sha'] ?? null;
    $sha256Field = $decoded['sha256'] ?? null;
    $routeCountField = $decoded['route_count'] ?? null;
    $routesField = $decoded['routes'] ?? null;

    if (!is_string($sourceShaField) || $sourceShaField === '') {
        $fail('the fixture is missing a usable "source_sha" string — cannot judge currency.');
        exit(1); // unreachable
    }
    if (!is_string($sha256Field) || $sha256Field === '') {
        $fail('the fixture is missing a usable "sha256" string — cannot judge currency.');
        exit(1); // unreachable
    }
    if (!is_int($routeCountField)) {
        $fail('the fixture is missing an integer "route_count" — cannot judge currency.');
        exit(1); // unreachable
    }
    if (!is_array($routesField)) {
        $fail('the fixture "routes" is not a list — it cannot describe what drifted.');
        exit(1); // unreachable
    }

    // `METHOD path` tuples straight from the snapshot, for the drift diff only — the
    // verdict itself is the digest compare. A malformed route entry is a fixture
    // defect and must not vanish silently, so it fails loud rather than being skipped.
    $fixtureTuples = [];
    foreach ($routesField as $route) {
        if (
            !is_array($route) || !isset($route['method'], $route['path'])
            || !is_string($route['method']) || !is_string($route['path'])
        ) {
            $fail('a fixture route is malformed — it cannot describe what drifted.');
            exit(1); // unreachable
        }
        $fixtureTuples[] = $route['method'] . ' ' . $route['path'];
    }

    return [$sourceShaField, $sha256Field, $routeCountField, array_values(array_unique($fixtureTuples))];
};

[$fixtureSourceSha, $fixtureSha256, $fixtureRouteCount, $fixtureTuples] = $parseFixture($fixturePath, $fail);

// ── the live phlix-server side must be measurable ────────────────────────────
if (!is_dir($serverRoot)) {
    $fail(sprintf(
        'phlix-server was not found at %s, so its route table could not be read and the snapshot '
        . 'could not be judged. This gate does NOT pass when it cannot measure the other side — '
        . 'the CI job must clone phlix-server master anonymously first (see the job in ci.yml).',
        $serverRoot,
    ));
}

/**
 * Extract `METHOD path` tuples from one ROUTE_MANIFEST const block.
 *
 * Concatenation-aware port of the proven phlix-contracts generator: entries may
 * span lines (`'VERB /path' . ' -> Handler [MW]'`); the ` -> Handler [Middleware]`
 * suffix is dropped; only `VERB` + the first path token is kept. Fails loud (via
 * `$fail` → `exit(1)`) when the const is absent, a line is unparseable, or the final
 * entry is unterminated — a restructured guard table must not silently yield a short
 * set. `$fail` is a `callable(string): never` PARAMETER (see `$parseFixture` above
 * for why it is never captured).
 *
 * @param callable(string): never $fail
 *
 * @return list<string>
 */
$extractTuples = static function (string $phpSource, string $label, callable $fail): array {
    $start = strpos($phpSource, 'private const ROUTE_MANIFEST = [');
    if ($start === false) {
        $fail(sprintf('%s: ROUTE_MANIFEST constant not found — the wire-guard was renamed or removed.', $label));
        exit(1); // unreachable
    }
    $open = strpos($phpSource, '[', $start);
    if ($open === false) {
        $fail(sprintf('%s: could not locate the ROUTE_MANIFEST array.', $label));
        exit(1); // unreachable
    }
    $bodyStart = $open + 1;
    $close = strpos($phpSource, '];', $bodyStart);
    if ($close === false) {
        $fail(sprintf('%s: could not delimit the ROUTE_MANIFEST block.', $label));
        exit(1); // unreachable
    }

    $entries = [];
    $buffer = '';
    $inBlockComment = false;
    foreach (explode("\n", substr($phpSource, $bodyStart, $close - $bodyStart)) as $rawLine) {
        $line = trim($rawLine);
        if ($line === '') {
            continue;
        }
        if ($inBlockComment) {
            if (str_contains($line, '*/')) {
                $inBlockComment = false;
            }
            continue;
        }
        if (str_starts_with($line, '//')) {
            continue;
        }
        if (str_starts_with($line, '/*')) {
            if (!str_contains($line, '*/')) {
                $inBlockComment = true;
            }
            continue;
        }
        if (str_starts_with($line, '*')) {
            continue;
        }

        if (preg_match("/^(?:\.\s*)?'((?:[^'\\\\]|\\\\.)*)'/", $line, $m) !== 1) {
            $fail(sprintf('%s: unparseable manifest line: %s', $label, $line));
            exit(1); // unreachable
        }
        $buffer .= $m[1];
        if (str_ends_with($line, ',')) {
            $entries[] = $buffer;
            $buffer = '';
        }
    }
    if ($buffer !== '') {
        $fail(sprintf('%s: unterminated manifest entry: %s', $label, $buffer));
    }

    $tuples = [];
    foreach ($entries as $entry) {
        if (preg_match('/^(' . S492_HTTP_VERBS . ') (\S+)/', $entry, $mm) !== 1) {
            $fail(sprintf('%s: manifest entry is not `VERB path -> …`: %s', $label, $entry));
            exit(1); // unreachable
        }
        $tuples[] = $mm[1] . ' ' . $mm[2];
    }

    if ($tuples === []) {
        $fail(sprintf('%s: parsed ZERO routes — refusing to treat an empty read as agreement.', $label));
    }

    return $tuples;
};

// ── rebuild the dumper's exact digest corpus from the live consts ─────────────
$union = [];
$perSource = [];
foreach (S492_MANIFEST_SOURCES as $label => $relative) {
    $path = $serverRoot . '/' . $relative;
    if (!is_file($path)) {
        $fail(sprintf('%s (%s) is unreadable — phlix-server restructured its route guards.', $label, $relative));
    }
    $tuples = $extractTuples((string) file_get_contents($path), $label, $fail);
    $perSource[$label] = count($tuples);
    foreach ($tuples as $tuple) {
        $union[$tuple] = true;
    }
}

$lines = array_keys($union);
$lines = array_values(array_unique($lines));
sort($lines);

// Byte-for-byte the same corpus the dumper hashes (:228-246): sorted-unique
// `METHOD path`, newline-joined.
$liveSha256 = hash('sha256', implode("\n", $lines));

// ── live provenance sha (for the lag WARNING only — never the pass verdict) ───
$liveSha = is_string($liveShaArg) && $liveShaArg !== '' ? trim($liveShaArg) : null;
if ($liveSha === null) {
    // The clone's HEAD is the live master sha (the job fetches master --depth 1
    // right before this runs). `exec()` not `shell_exec()`: the estate's Psalm
    // config forbids shell_exec (ci-mcp-e2e-prereqs.php:159 is the house idiom —
    // a pre-declared list<string> out-param, re-annotated after the mixed reset).
    /** @var list<string> $headOut */
    $headOut = [];
    $headExit = 0;
    exec(
        sprintf('git -C %s rev-parse HEAD 2>/dev/null', escapeshellarg($serverRoot)),
        $headOut,
        $headExit
    );
    /** @var list<string> $headOut */
    $probe = $headExit === 0 ? trim($headOut[0] ?? '') : '';
    $liveSha = preg_match('/^[0-9a-f]{40}$/', $probe) === 1 ? $probe : null;
}

$short = static fn (string $sha): string => substr($sha, 0, 12);

// ── verdict ladder ────────────────────────────────────────────────────────────
if ($liveSha256 !== $fixtureSha256) {
    // CONTENT DRIFT — this is the whole point of the gate; it MUST go red.
    $onlyInLive = array_values(array_diff($lines, $fixtureTuples));
    $onlyInSnapshot = array_values(array_diff($fixtureTuples, $lines));

    $fail(sprintf(
        "ROUTE CONTENT DRIFT.\n"
        . "  live phlix-server routes : %d tuple(s), sha256=%s\n"
        . "  vendored snapshot        : %d tuple(s), sha256=%s\n"
        . "  only in live (%d): %s\n"
        . "  only in snapshot (%d): %s\n"
        . 'The snapshot no longer describes what phlix-server registers. Regenerate it '
        . '(php tests/Unit/Http/Controllers/Fixtures/dump-phlix-server-route-manifest.php <server-root>) '
        . 'and bump S332_EXPECTED_SERVER_SOURCE_SHA in the SAME commit.',
        count($lines),
        $liveSha256,
        count($fixtureTuples),
        $fixtureSha256,
        count($onlyInLive),
        implode(', ', array_slice($onlyInLive, 0, 15)) ?: '-',
        count($onlyInSnapshot),
        implode(', ', array_slice($onlyInSnapshot, 0, 15)) ?: '-',
    ));
}

// Content is equal — the snapshot is current in every way that matters.
printf(
    "route CONTENT digest matches (sha256=%s, %d tuple(s) derived from %s; snapshot route_count=%d).\n",
    $liveSha256,
    count($lines),
    implode(' + ', $perSource),
    $fixtureRouteCount,
);

if ($liveSha === null) {
    fwrite(STDOUT, '::warning::S492 could not read a live phlix-server master sha to compare provenance '
        . "against; route content is equal so the snapshot is current, but the source_sha lag check was skipped "
        . "(snapshot source_sha={$fixtureSourceSha}).\n");
    exit(0);
}

if ($liveSha !== $fixtureSourceSha) {
    fwrite(STDOUT, sprintf(
        '::warning::snapshot source_sha %s lags live phlix-server master %s; route content is equal, so the '
        . "snapshot is CURRENT — regenerate it and bump S332_EXPECTED_SERVER_SOURCE_SHA when convenient "
        . "(provenance-only server merges do not block).\n",
        $short($fixtureSourceSha),
        $short($liveSha),
    ));
}

printf(
    "S492 route snapshot currency OK: content digest equal (source_sha %s%s).\n",
    $short($fixtureSourceSha),
    $liveSha === $fixtureSourceSha ? ' == live master' : ' (lags live master ' . $short($liveSha) . ', content equal)',
);
exit(0);
