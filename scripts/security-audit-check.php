<?php

/**
 * S246 — make the "Security Audit" job in `.github/workflows/ci.yml` audit the
 * WHOLE lock, state the corpus it examined, and be capable of failing.
 *
 * ## The defect this replaces, measured 2026-08-06
 *
 * The job was two steps:
 *
 * ```yaml
 * - name: Install Composer dependencies (no-dev)
 *   run: composer install --no-interaction --prefer-dist --no-dev
 * - name: Run Composer Audit
 *   run: composer audit --no-dev
 * ```
 *
 * `--no-dev` removes every `require-dev` package from the audited set, so **no
 * advisory against a development dependency could ever fail this gate**. That is
 * not a hypothetical. On 2026-08-06 a HIGH advisory landed against
 * `squizlabs/php_codesniffer` — CVE-2026-67434, OS command injection,
 * GHSA-hmqg-cxww-wqhq, affecting `<3.13.6` and `>=4.0.0,<4.0.2`. This repo's
 * lock pinned **3.13.5**, squarely inside the range. Across the estate only
 * phlix-server went red; this job reported SUCCESS against a vulnerable lock,
 * and that green was indistinguishable from a real pass.
 *
 * A gate that silently covers half its subject is worse than no gate, because it
 * is read as evidence. Same shape as the Psalm job that spent its life green
 * having analysed zero files, and as the sibling repo's "Run Security Audit"
 * step that dispatched on two binaries which had never existed.
 *
 * ## The policy chosen: audit EVERYTHING, and block on everything
 *
 * The two honest shapes were (a) audit everything and fix promptly, or (b) audit
 * runtime dependencies as blocking and development dependencies as a separate,
 * clearly-labelled non-blocking report. **This gate implements (a).**
 *
 * `--no-dev` was presumably chosen to stop a dev-only advisory blocking a
 * release, and that concern is real — but it is answered by *labelling and an
 * explicit escape hatch*, not by blindness:
 *
 *  1. **A dev dependency is not a safe dependency.** The advisory that exposed
 *     this hole is command injection in a linter that CI runs, in a checkout,
 *     against pull-request-authored content. The developer workstation and the
 *     CI runner are exactly the machines that hold the signing keys and the
 *     deploy credentials. "It does not ship" is not the same as "it cannot hurt
 *     you".
 *  2. **Every advisory here is labelled with its scope.** The report prints
 *     `[require]` or `[require-dev]` against each affected package, so a reader
 *     can tell in one glance whether production is exposed or only the toolchain
 *     is. Option (b) buys that same information at the price of a second,
 *     non-blocking channel that nobody reads.
 *  3. **There is a recorded escape hatch.** An advisory that genuinely cannot be
 *     actioned is acknowledged in `composer.json` under `config.audit.ignore`
 *     with a written reason, which this script then reports as a loud IGNORED
 *     notice. That is an explicit, reviewable, in-repo decision — the opposite
 *     of a flag that quietly excludes two thirds of the lock.
 *
 * There is deliberately **no baseline file and no ignore list in this script**.
 * The only way to pass an advisory is to fix it or to acknowledge it in
 * `composer.json`, where it is committed, diffed and reviewed.
 *
 * ## Blocking vs advisory
 *
 * `composer audit` returns one exit code for several very different findings, so
 * the verdict here is computed from `--format=json` rather than inherited from
 * `$?`:
 *
 * | finding                       | verdict                                   |
 * | ----------------------------- | ----------------------------------------- |
 * | security advisory (any scope) | **BLOCKING** — exit 1                     |
 * | abandoned package             | ADVISORY — loud `::warning::`, exit 0     |
 * | advisory ignored via config   | ADVISORY — loud `::notice::`, exit 0      |
 * | unreachable advisory repo     | **BLOCKING** — exit 1 (audited nothing)   |
 * | missing / unparseable JSON    | **BLOCKING** — exit 1                     |
 * | corpus below its floor        | **BLOCKING** — exit 1 (audited too little)|
 * | composer absent or < 2.4      | **BLOCKING** — exit 1                     |
 *
 * Abandonment is not a vulnerability and is usually unfixable from this repo, so
 * it warns rather than blocks: a gate that goes red for a reason nobody here can
 * act on gets switched off, and that is precisely how blind gates come to exist.
 * The unreachable-repository row is the same rule as the corpus floor — a gate
 * that could not measure must fail, never report success.
 *
 * ## The corpus, and why it is printed
 *
 * `composer audit` never says how many packages it looked at, and "inspected
 * zero files" is the commonest false pass in this estate: it looks exactly like
 * a clean run. So this script counts the audited set out of `composer.lock`
 * itself and prints it — total, `require`, and `require-dev` — then refuses to
 * pass if either the total or the dev half falls below its floor. The dev floor
 * is the specific anti-regression for the defect above: if `--no-dev` ever
 * returns, or `packages-dev` is emptied, the corpus line says so out loud and
 * the gate fails instead of reporting a clean audit of a third of the lock.
 *
 * Usage:
 *   php scripts/security-audit-check.php                       # runs composer audit itself
 *   php scripts/security-audit-check.php audit.json            # reads a captured payload
 *   php scripts/security-audit-check.php audit.json other.lock # ...against another lock
 *
 * The optional arguments exist so the guard tests can exercise every verdict
 * offline, without a network round-trip to Packagist.
 *
 * Environment:
 *   COMPOSER_BIN  path to the composer binary (default: `composer` on PATH)
 *
 * Exit codes: 0 = no blocking finding. 1 = a security advisory was found, OR the
 * audit could not run over a credible corpus (which is a failure, not a skip).
 *
 * @package Phlix\Hub
 */

declare(strict_types=1);

// phpcs:disable PSR1.Files.SideEffects -- S333 waiver: 19 named functions form a self-contained
// CLI audit tool; converting them all to closures is the estate convention but is a separate
// refactor, out of scope for S333. Expires when this file is refactored to the estate's closure
// convention (tracked as an S333 follow-up) or the next phpcs major upgrade — whichever comes first.

/** `composer audit` was added in Composer 2.4; older is unusable. */
const MIN_COMPOSER_VERSION = '2.4.0';

/**
 * The composer arguments the audit is invoked with.
 *
 * Declared as a constant so {@see \Phlix\Hub\Tests\Unit\Support\SecurityAuditCheckTest}
 * can assert the list directly instead of pattern-matching prose — in particular
 * that `--no-dev` is **not** present and `--locked` **is**.
 *
 * `--locked` audits `composer.lock` and needs no `vendor/` at all. That matters:
 * the lock is the committed artifact a pull request actually changes, and
 * Composer 2.10 refuses to *install* packages carrying known advisories
 * (`policy.advisories.block`), so auditing after `composer install` would mean a
 * vulnerable lock died in the solver with an opaque resolution error before the
 * audit ever ran.
 */
const AUDIT_ARGUMENTS = ['audit', '--locked', '--format=json', '--no-interaction'];

/**
 * Floor for the total number of locked packages the audit covers.
 *
 * Measured on master at 68c0e5c8: 97 packages (33 require + 64 require-dev).
 * The floor sits below that so ordinary dependency pruning does not trip it,
 * while a gutted or half-read lock does. Lowering it is how this gate would be
 * neutered, so a lower value deserves the same scrutiny as deleting the check.
 */
const MIN_AUDITED_PACKAGES = 80;

/**
 * Floor for the `require-dev` half of the corpus.
 *
 * This is the direct anti-regression for S246. `--no-dev` leaves the total
 * looking plausible while silently dropping two thirds of the packages, and a
 * clean audit of a truncated corpus is indistinguishable from a clean audit.
 * Measured on master: 64 dev packages.
 */
const MIN_AUDITED_DEV_PACKAGES = 40;

/**
 * Emit a GitHub Actions error annotation and stop.
 *
 * Annotations go to STDOUT because that is the stream the runner scans for
 * workflow commands.
 */
function fail(string $headline, string ...$detail): never
{
    fwrite(STDOUT, '::error::' . $headline . "\n");

    foreach ($detail as $line) {
        fwrite(STDOUT, '  ' . $line . "\n");
    }

    exit(1);
}

/**
 * Emit a non-blocking annotation. The whole point of this script is that these
 * are VISIBLE rather than swallowed, so they are real workflow commands and not
 * a bare echo.
 *
 * @param 'notice'|'warning' $level
 */
function annotate(string $level, string $headline, string ...$detail): void
{
    fwrite(STDOUT, '::' . $level . '::' . $headline . "\n");

    foreach ($detail as $line) {
        fwrite(STDOUT, '  ' . $line . "\n");
    }
}

/**
 * Run a command without a shell and capture both streams separately.
 *
 * @param list<string> $command
 *
 * @return array{stdout: string, stderr: string, exit: int}
 */
function runProcess(array $command): array
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $pipes   = [];
    $process = @proc_open($command, $descriptors, $pipes);

    if (!is_resource($process)) {
        return ['stdout' => '', 'stderr' => 'proc_open() failed', 'exit' => 127];
    }

    fclose($pipes[0]);

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['stdout' => $stdout, 'stderr' => $stderr, 'exit' => proc_close($process)];
}

/**
 * Read the audited corpus out of `composer.lock`.
 *
 * `composer audit --locked` audits exactly `packages` + `packages-dev`, so this
 * is the set the verdict below is a statement about. An unreadable lock is a
 * failed audit, not an empty one.
 *
 * @return array{runtime: array<string, string>, dev: array<string, string>}
 */
function readCorpus(string $lockPath): array
{
    if (!is_file($lockPath)) {
        fail(
            sprintf('composer.lock not found at "%s" — there is nothing to audit.', $lockPath),
            'The audit is over the committed lock; without it the gate has measured nothing.',
        );
    }

    $raw = (string) file_get_contents($lockPath);

    if (trim($raw) === '') {
        fail(sprintf('composer.lock at "%s" is empty.', $lockPath));
    }

    try {
        /** @var mixed $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        fail(
            sprintf('composer.lock at "%s" is not parseable JSON.', $lockPath),
            'json_decode: ' . $e->getMessage(),
        );
    }

    if (!is_array($decoded) || !array_key_exists('packages', $decoded)) {
        fail(
            sprintf('composer.lock at "%s" has no "packages" key — this is not a composer lock.', $lockPath),
            'Failing rather than assuming an empty corpus.',
        );
    }

    /** @var array<string, mixed> $decoded */
    return [
        'runtime' => collectPackages($decoded['packages'] ?? []),
        'dev'     => collectPackages($decoded['packages-dev'] ?? []),
    ];
}

/**
 * Reduce a lock section to a name => version map.
 *
 * @return array<string, string>
 */
function collectPackages(mixed $section): array
{
    if (!is_array($section)) {
        return [];
    }

    $packages = [];

    foreach (array_filter($section, 'is_array') as $package) {
        $name = stringField($package, 'name');

        if ($name === '') {
            continue;
        }

        $packages[$name] = stringField($package, 'version', 'unknown');
    }

    return $packages;
}

/**
 * Read a string field out of a decoded JSON structure, or fall back.
 *
 * Every payload this script reads is `mixed` all the way down, so the narrowing
 * is centralised here rather than repeated as `is_string($x['k'] ?? null)`
 * ternaries that re-read the offset.
 *
 * @param array<array-key, mixed> $data
 */
function stringField(array $data, string $key, string $default = ''): string
{
    return is_string($data[$key] ?? null) ? (string) $data[$key] : $default;
}

/**
 * Render an arbitrary decoded value as something printable.
 */
function stringify(mixed $value): string
{
    if (is_string($value)) {
        return $value;
    }

    if (is_scalar($value)) {
        return (string) $value;
    }

    $encoded = json_encode($value);

    return is_string($encoded) ? $encoded : '<unprintable>';
}

/**
 * Coerce a decoded JSON value to a list of printable strings.
 *
 * @return list<string>
 */
function toStringList(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    return array_values(array_map(stringify(...), $value));
}

/**
 * Coerce a decoded JSON object to a string => string map.
 *
 * @return array<string, string>
 */
function toStringMap(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $map = [];

    foreach (array_keys($value) as $key) {
        $map[(string) $key] = stringify($value[$key]);
    }

    return $map;
}

/**
 * Print the corpus and refuse to continue if it is too small to be believed.
 *
 * "The gate ran and inspected zero packages" is the commonest false pass in this
 * estate and looks exactly like a clean run, so the size is stated out loud and
 * checked, not assumed.
 *
 * @param array{runtime: array<string, string>, dev: array<string, string>} $corpus
 */
function reportAndCheckCorpus(array $corpus, string $lockPath): void
{
    $runtime = count($corpus['runtime']);
    $dev     = count($corpus['dev']);
    $total   = $runtime + $dev;

    fwrite(STDOUT, sprintf(
        "Audit corpus: %d locked package(s) — %d require, %d require-dev (%s).\n",
        $total,
        $runtime,
        $dev,
        $lockPath,
    ));

    if ($total < MIN_AUDITED_PACKAGES) {
        fail(
            sprintf(
                'Audit corpus is %d package(s), below the floor of %d — the audit covered too little to mean anything.',
                $total,
                MIN_AUDITED_PACKAGES,
            ),
            'A clean audit of a truncated corpus is indistinguishable from a clean audit.',
            'Check that composer.lock is the real, committed lock.',
        );
    }

    if ($dev < MIN_AUDITED_DEV_PACKAGES) {
        fail(
            sprintf(
                'Only %d require-dev package(s) in the corpus, below the floor of %d.',
                $dev,
                MIN_AUDITED_DEV_PACKAGES,
            ),
            'S246: this gate exists because `--no-dev` silently excluded every development',
            'dependency, so a HIGH advisory against squizlabs/php_codesniffer could not fail it.',
            'Do NOT restore --no-dev and do NOT lower this floor to make the gate pass.',
        );
    }
}

/**
 * Resolve the composer binary and prove it can audit, or die trying.
 *
 * The tool being absent must fail LOUDLY. It must never degrade back into
 * `if [ -f ... ]; then ... fi`, which is how a sibling repo's audit step spent
 * its entire life green having run nothing.
 */
function assertComposerCanAudit(string $composerBin): string
{
    $probe = runProcess([$composerBin, '--version', '--no-interaction']);

    if ($probe['exit'] !== 0 || $probe['stdout'] === '') {
        fail(
            sprintf('Cannot run "%s --version" — the security audit tool is not available.', $composerBin),
            'composer must be on PATH (setup-php provides it) or COMPOSER_BIN must point at it.',
            sprintf(
                'exit=%d stderr=%s',
                $probe['exit'],
                trim($probe['stderr']) !== '' ? trim($probe['stderr']) : '<empty>',
            ),
            'Do NOT wrap the audit in a conditional that skips when the tool is absent.',
        );
    }

    if (preg_match('/Composer(?:\s+version)?\s+(\d+\.\d+\.\d+)/i', $probe['stdout'], $matches) !== 1) {
        fail(
            sprintf('Could not parse a version out of "%s --version".', $composerBin),
            'Output was: ' . trim($probe['stdout']),
        );
    }

    $version = $matches[1];

    if (version_compare($version, MIN_COMPOSER_VERSION, '<')) {
        fail(
            sprintf('Composer %s is too old — `composer audit` was added in %s.', $version, MIN_COMPOSER_VERSION),
            'Pin a newer composer in the setup-php step (tools: composer:v2).',
        );
    }

    fwrite(STDOUT, sprintf("Auditing with composer %s (%s)\n", $version, $composerBin));

    return $composerBin;
}

/**
 * Ask composer for the audit payload.
 *
 * The exit code is deliberately NOT used as the verdict: it folds abandoned
 * packages in with real advisories. It is only reported when the payload fails
 * to parse, where it helps explain why.
 */
function captureAuditPayload(string $composerBin): string
{
    $result = runProcess(array_merge([$composerBin], AUDIT_ARGUMENTS));

    if (trim($result['stdout']) === '') {
        fail(
            'composer audit produced no output — the audit did not run.',
            sprintf('exit=%d', $result['exit']),
            'stderr: ' . (trim($result['stderr']) !== '' ? trim($result['stderr']) : '<empty>'),
            'A common cause is a missing or stale composer.lock (--locked needs one).',
        );
    }

    return $result['stdout'];
}

/**
 * Decode the payload, or fail. An unreadable audit is a failed audit.
 *
 * @return array<string, mixed>
 */
function decodePayload(string $raw, string $origin): array
{
    try {
        /** @var mixed $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        fail(
            sprintf('Audit payload from %s is not parseable JSON.', $origin),
            'json_decode: ' . $e->getMessage(),
            'First 200 bytes: ' . substr(trim($raw), 0, 200),
        );
    }

    if (!is_array($decoded)) {
        fail(sprintf('Audit payload from %s is not a JSON object.', $origin));
    }

    if (!array_key_exists('advisories', $decoded)) {
        fail(
            sprintf('Audit payload from %s has no "advisories" key.', $origin),
            'composer audit --format=json always emits one, so this is not a composer audit',
            'payload and the gate cannot read it. Failing rather than assuming "no advisories".',
        );
    }

    /** @var array<string, mixed> $decoded */
    return $decoded;
}

/**
 * `composer audit --format=json` emits `[]` for an empty advisory set and a
 * package-keyed object when populated. Normalise both to a map.
 *
 * @return array<string, list<array<array-key, mixed>>>
 */
function normaliseAdvisoryMap(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $map = [];

    foreach ($value as $package => $entries) {
        if (!is_array($entries)) {
            continue;
        }

        $list = [];

        foreach (array_filter($entries, 'is_array') as $entry) {
            $list[] = $entry;
        }

        $map[(string) $package] = $list;
    }

    return $map;
}

/**
 * Where in the lock a package sits, for the scope label on each finding.
 *
 * This is the information option (b) would have bought with a second reporting
 * channel: a reader sees immediately whether production ships the affected
 * package or whether only the toolchain is exposed.
 *
 * @param array{runtime: array<string, string>, dev: array<string, string>} $corpus
 */
function scopeOf(string $package, array $corpus): string
{
    if (array_key_exists($package, $corpus['runtime'])) {
        return 'require';
    }

    if (array_key_exists($package, $corpus['dev'])) {
        return 'require-dev';
    }

    return 'not in lock';
}

/**
 * @param array<array-key, mixed> $advisory
 */
function describeAdvisory(array $advisory): string
{
    $severity = stringField($advisory, 'severity');
    $cve      = stringField($advisory, 'cve');
    $id       = stringField($advisory, 'advisoryId');

    return sprintf(
        '[%s] %s — %s',
        $severity !== '' ? strtoupper($severity) : 'UNKNOWN',
        $cve !== '' ? $cve : ($id !== '' ? $id : 'unidentified'),
        stringField($advisory, 'title', '(no title)'),
    );
}

/**
 * @param array<string, list<array<array-key, mixed>>>                         $advisories
 * @param array{runtime: array<string, string>, dev: array<string, string>} $corpus
 *
 * @return list<string>
 */
function renderAdvisoryLines(array $advisories, array $corpus): array
{
    $lines = [];

    foreach ($advisories as $package => $entries) {
        $affected = '';

        foreach ($entries as $entry) {
            $candidate = stringField($entry, 'affectedVersions');

            if ($candidate !== '') {
                $affected = $candidate;

                break;
            }
        }

        $locked  = $corpus['runtime'][$package] ?? $corpus['dev'][$package] ?? '';
        $lines[] = sprintf(
            '%s [%s]%s%s',
            $package,
            scopeOf($package, $corpus),
            $locked !== '' ? ' locked at ' . $locked : '',
            $affected !== '' ? ' (affected: ' . $affected . ')' : '',
        );

        foreach ($entries as $entry) {
            $lines[] = '  ' . describeAdvisory($entry);
            $link    = stringField($entry, 'link');

            if ($link !== '') {
                $lines[] = '    ' . $link;
            }
        }
    }

    return $lines;
}

/**
 * @param array<string, list<array<array-key, mixed>>> $advisories
 */
function countAdvisories(array $advisories): int
{
    $total = 0;

    foreach ($advisories as $entries) {
        $total += count($entries);
    }

    return $total;
}

/**
 * @param array<string, list<array<array-key, mixed>>>                         $advisories
 * @param array{runtime: array<string, string>, dev: array<string, string>} $corpus
 *
 * @return array{require: int, 'require-dev': int, 'not in lock': int}
 */
function countByScope(array $advisories, array $corpus): array
{
    $require = 0;
    $dev     = 0;
    $unknown = 0;

    foreach (array_keys($advisories) as $package) {
        $scope = scopeOf($package, $corpus);

        if ($scope === 'require') {
            ++$require;
        } elseif ($scope === 'require-dev') {
            ++$dev;
        } else {
            ++$unknown;
        }
    }

    return ['require' => $require, 'require-dev' => $dev, 'not in lock' => $unknown];
}

// ---------------------------------------------------------------------------
// The corpus comes first: state what is being audited before saying anything
// about it.
// ---------------------------------------------------------------------------

$lockPath = $argv[2] ?? (dirname(__DIR__) . '/composer.lock');
$corpus   = readCorpus($lockPath);

reportAndCheckCorpus($corpus, $lockPath);

// ---------------------------------------------------------------------------
// Acquire the payload — either from a file (tests) or from composer (CI).
// ---------------------------------------------------------------------------

$payloadPath = $argv[1] ?? null;

if (is_string($payloadPath) && $payloadPath !== '') {
    if (!is_file($payloadPath)) {
        fail(sprintf('Audit payload "%s" does not exist.', $payloadPath));
    }

    $raw    = (string) file_get_contents($payloadPath);
    $origin = $payloadPath;

    if (trim($raw) === '') {
        fail(sprintf('Audit payload "%s" is empty.', $payloadPath));
    }
} else {
    $composerBin = getenv('COMPOSER_BIN');

    if (!is_string($composerBin) || trim($composerBin) === '') {
        $composerBin = 'composer';
    }

    $raw    = captureAuditPayload(assertComposerCanAudit(trim($composerBin)));
    $origin = 'composer ' . implode(' ', AUDIT_ARGUMENTS);
}

$payload = decodePayload($raw, $origin);

// ---------------------------------------------------------------------------
// Guard — an audit that could not reach its advisory source measured NOTHING.
//
// Same rule as the corpus floor: cannot-measure must fail, never pass.
// ---------------------------------------------------------------------------

$unreachable = toStringList($payload['unreachable-repositories'] ?? []);

if ($unreachable !== []) {
    $names = [];

    foreach ($unreachable as $repo) {
        $names[] = '  ' . $repo;
    }

    fail(
        sprintf('%d advisory repository/ies were unreachable — the audit measured nothing.', count($names)),
        ...$names,
    );
}

// ---------------------------------------------------------------------------
// Advisory-only findings. LOUD, but they do not block.
// ---------------------------------------------------------------------------

$ignored = normaliseAdvisoryMap($payload['ignored-advisories'] ?? []);

if ($ignored !== []) {
    annotate(
        'notice',
        sprintf(
            '%d security advisory/ies affecting %d package(s) are IGNORED by composer config '
            . '— acknowledged, not fixed.',
            countAdvisories($ignored),
            count($ignored),
        ),
        ...renderAdvisoryLines($ignored, $corpus),
    );
}

$abandoned = toStringMap($payload['abandoned'] ?? []);

if ($abandoned !== []) {
    $lines = [];

    foreach ($abandoned as $package => $replacement) {
        $lines[] = sprintf(
            '%s [%s] — %s',
            $package,
            scopeOf($package, $corpus),
            $replacement !== ''
                ? 'replaced by ' . $replacement
                : 'no replacement suggested',
        );
    }

    annotate(
        'warning',
        sprintf('%d abandoned package(s). ADVISORY ONLY — this does NOT fail the build.', count($lines)),
        ...array_merge($lines, [
            'Abandonment is not a vulnerability, and these are usually transitive dependencies',
            'that cannot be fixed from this repo. Blocking on them would make every pull request',
            'red for a reason nobody can act on, and a gate that is red for unrelated reasons',
            'gets switched off — which is how a blind gate comes to exist in the first place.',
        ]),
    );
}

// ---------------------------------------------------------------------------
// The blocking verdict — every scope, including require-dev. That is the whole
// point of S246.
// ---------------------------------------------------------------------------

$advisories = normaliseAdvisoryMap($payload['advisories']);

if ($advisories !== []) {
    $byScope = countByScope($advisories, $corpus);

    fail(
        sprintf(
            'Security audit FAILED — %d advisory/ies affecting %d package(s) (%d require, %d require-dev).',
            countAdvisories($advisories),
            count($advisories),
            $byScope['require'],
            $byScope['require-dev'],
        ),
        ...array_merge(renderAdvisoryLines($advisories, $corpus), [
            'Update the affected package(s). A require-dev advisory blocks too: the toolchain',
            'runs on the machines holding the deploy credentials, so "it does not ship" is not',
            '"it cannot hurt you".',
            'If an advisory genuinely cannot be actioned, acknowledge it explicitly under',
            'config.audit.ignore in composer.json — with a written reason — so it is recorded in',
            'the repo and reported above as IGNORED. Do not disable this gate and do not restore',
            '--no-dev.',
        ]),
    );
}

fwrite(STDOUT, sprintf(
    "No security advisories affecting the %d locked package(s) audited.\n",
    count($corpus['runtime']) + count($corpus['dev']),
));
fwrite(STDOUT, "Security audit passed.\n");

exit(0);
