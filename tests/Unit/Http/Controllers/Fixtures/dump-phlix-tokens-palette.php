<?php

/**
 * S-tokens-20 — regenerate the vendored palette snapshot that pins the hub's
 * `ThemesController` token maps to the `@phlix/tokens` package.
 *
 * Finding phlix-tokens-015: `ThemesController::PAYLOADS` hand-copies the full
 * three-theme token table (3 x 53 served keys) with no dependency on the
 * package, so the hand-copy silently ages when the palette moves. This script
 * closes that decay mode at its source, mirroring the sanctioned hub fixture
 * pattern (`dump-phlix-server-route-manifest.php`): it reads the package's
 * committed `dist/tokens.json` and dumps the SERVED surface into
 * `tests/Unit/Http/Controllers/Fixtures/phlix-tokens-palette.json`.
 * `ThemesControllerTest` then compares the controller against that snapshot —
 * drift goes RED and names the repair ritual below.
 *
 * ## What is dumped
 *
 * For each of nocturne, daylight, midnight: exactly the 53 CSS custom-property
 * keys the controller serves, in the controller's serving order, with the
 * package's resolved values. The served-key list is the literal constant
 * below — deliberately NOT read from the controller, because deriving the
 * expected set from the thing under test would make key deletions invisible
 * (the vacuity trap this estate repeatedly pins). The package carries extra
 * per-theme keys (ramp/shadow/glow) that the hub does not serve; they are
 * outside the snapshot by design — the parity direction is "every value the
 * hub serves must equal the package value", never the reverse.
 *
 * ## Cross-repo boundary and staleness
 *
 * phlix-hub's CI clones ONE repository, so it cannot read the tokens package
 * at test time. The snapshot is therefore VENDORED, and currency is enforced
 * outside CI: regenerate at tokens tag time (after checking out the new tag in
 * a sibling `phlix-tokens` clone) and commit the regenerated fixture alongside
 * any resulting `ThemesController` value corrections, in the SAME commit.
 * `--check` byte-compares a live regeneration against the committed fixture
 * and fails (exit 1) without writing — run it whenever tokens moves.
 *
 * ## Usage
 *
 * ```
 * php tests/Unit/Http/Controllers/Fixtures/dump-phlix-tokens-palette.php [tokens-root] [--check]
 * ```
 *
 *  - `tokens-root` defaults to `<hub-repo>/../phlix-tokens`.
 *  - Without `--check`: writes the fixture in place (and prints the pair
 *    count + dist sha256 + source sha/tag).
 *  - With `--check`: regenerates in memory and fails (exit 1) unless a
 *    byte-identical copy is already committed. Writes nothing.
 *
 * @package Phlix\Hub
 */

declare(strict_types=1);

const FIXTURE_PATH = 'tests/Unit/Http/Controllers/Fixtures/phlix-tokens-palette.json';

const GENERATOR_PATH = 'tests/Unit/Http/Controllers/Fixtures/dump-phlix-tokens-palette.php';

/**
 * The three built-ins in the hub's serving order — the same order
 * `ThemesController::PAYLOADS` emits and `GET /api/v1/themes` returns.
 *
 * @var list<string>
 */
const SERVED_THEME_IDS = ['nocturne', 'daylight', 'midnight'];

/**
 * The exact served token-key surface of `ThemesController::PAYLOADS`, in
 * serving order. Code-resident on purpose: a controller that adds, drops or
 * re-spells a key must fail the parity test, never quietly redefine the
 * snapshot at regeneration time. Rotating this list is a deliberate act
 * (change the controller's serving surface AND this list AND the gate counts
 * in one commit).
 *
 * @var list<string>
 */
const SERVED_TOKEN_KEYS = [
    '--accent',
    '--accent-hover',
    '--accent-active',
    '--accent-soft',
    '--accent-ring',
    '--accent-text',
    '--bg',
    '--surface',
    '--surface-2',
    '--surface-3',
    '--surface-glass',
    '--surface-glass-strong',
    '--text',
    '--text-muted',
    '--text-subtle',
    '--text-faint',
    '--text-on-accent',
    '--border',
    '--border-subtle',
    '--border-strong',
    '--error',
    '--error-bg',
    '--success',
    '--success-bg',
    '--warning',
    '--warning-bg',
    '--info',
    '--info-bg',
    '--grain-opacity',
    '--vignette',
    '--ambient',
    '--color-bg',
    '--color-surface',
    '--color-surface-hover',
    '--color-surface-elevated',
    '--color-surface-active',
    '--color-text',
    '--color-text-secondary',
    '--color-text-muted',
    '--color-text-subtle',
    '--color-primary',
    '--color-primary-hover',
    '--color-primary-active',
    '--color-border',
    '--color-border-subtle',
    '--color-error',
    '--color-error-bg',
    '--color-success',
    '--color-success-bg',
    '--color-warning',
    '--color-warning-bg',
    '--color-info',
    '--color-info-bg',
];

/**
 * Run a git command inside the tokens checkout; fail loud (not a skip) when
 * the checkout is not usable, so the provenance stamp can never be a lie.
 *
 * @param list<string> $args
 */
$gitStamp = static function (string $repoRoot, array $args): string {
    $command = 'git -C ' . escapeshellarg($repoRoot);
    foreach ($args as $argument) {
        $command .= ' ' . escapeshellarg($argument);
    }

    /** @var list<string> $outputLines */
    $outputLines = [];
    /** @var int $status */
    $status = 0;
    exec($command . ' 2>/dev/null', $outputLines, $status);
    if ($status !== 0) {
        fwrite(STDERR, sprintf(
            "git %s failed inside %s — cannot stamp fixture provenance\n",
            implode(' ', $args),
            $repoRoot,
        ));
        exit(2);
    }

    return trim(implode("\n", $outputLines));
};

/** @var list<string> $cliArgs */
$cliArgs = $_SERVER['argv'] ?? [];

$check = false;
$tokensRootArg = null;
foreach ($cliArgs as $index => $argument) {
    if ($index === 0) {
        continue;
    }

    if ($argument === '--check') {
        $check = true;

        continue;
    }

    if ($tokensRootArg !== null) {
        fwrite(STDERR, 'usage: php ' . GENERATOR_PATH . " [tokens-root] [--check]\n");
        exit(2);
    }

    $tokensRootArg = $argument;
}

$hubRoot = rtrim(dirname(__DIR__, 5), '/');
$tokensRoot = rtrim($tokensRootArg ?? $hubRoot . '/../phlix-tokens', '/');

if (!is_dir($tokensRoot)) {
    fwrite(STDERR, sprintf(
        "tokens repo not found at %s — pass the path as the first argument\n",
        $tokensRoot,
    ));
    exit(2);
}

$distPath = $tokensRoot . '/dist/tokens.json';
$distBytes = file_get_contents($distPath);
if (!is_string($distBytes)) {
    fwrite(STDERR, sprintf(
        "cannot read %s — run this from a phlix-tokens checkout (the committed dist is the "
        . "source; it is never regenerated from src here)\n",
        $distPath,
    ));
    exit(2);
}

/** @var array{tokens?: array<string, array<string, mixed>>} $dist */
$dist = json_decode($distBytes, true, 512, JSON_THROW_ON_ERROR);
if (!isset($dist['tokens']) || !is_array($dist['tokens'])) {
    fwrite(STDERR, "dist/tokens.json is missing its resolved `tokens` map — wrong artifact?\n");
    exit(2);
}

$sourceSha = $gitStamp($tokensRoot, ['rev-parse', 'HEAD']);
$sourceTag = $gitStamp($tokensRoot, ['describe', '--tags', '--abbrev=0']);
$distSha256 = hash_file('sha256', $distPath);

$themes = [];
foreach (SERVED_THEME_IDS as $themeId) {
    if (!isset($dist['tokens'][$themeId]) || !is_array($dist['tokens'][$themeId])) {
        fwrite(STDERR, sprintf(
            "package dist/tokens.json has no resolved map for theme '%s' — the hub serves a "
            . "theme the package dropped\n",
            $themeId,
        ));
        exit(1);
    }

    $resolved = $dist['tokens'][$themeId];
    $map = [];
    foreach (SERVED_TOKEN_KEYS as $key) {
        if (!array_key_exists($key, $resolved)) {
            fwrite(STDERR, sprintf(
                "package dist/tokens.json theme '%s' no longer carries served key '%s' — the "
                . "hub's serving surface drifted from the package; rotate SERVED_TOKEN_KEYS "
                . "deliberately or restore the key\n",
                $themeId,
                $key,
            ));
            exit(1);
        }

        $value = $resolved[$key];
        if (!is_string($value)) {
            fwrite(STDERR, sprintf(
                "package dist/tokens.json theme '%s' key '%s' is not a string value\n",
                $themeId,
                $key,
            ));
            exit(2);
        }

        $map[$key] = $value;
    }

    $themes[$themeId] = $map;
}

$pairCount = count(SERVED_TOKEN_KEYS) * count($themes);

$fixture = [
    '$comment' => 'GENERATED by ' . GENERATOR_PATH . ' from the phlix-tokens package\'s '
        . 'committed dist/tokens.json. Do not edit. Vendored palette snapshot pinning '
        . 'ThemesController::PAYLOADS token maps to the package (finding phlix-tokens-015): '
        . 'every value the hub serves must equal the package value here; drift goes RED. '
        . 'Refresh ritual: check out the new tokens tag in the sibling clone, re-run the '
        . 'generator (or --check first), and commit the regenerated fixture together with any '
        . 'ThemesController value corrections, in the SAME commit.',
    'generator' => GENERATOR_PATH,
    'source_repo' => 'detain/phlix-tokens',
    'source_sha' => $sourceSha,
    'source_tag' => $sourceTag,
    'source_dist_sha256' => $distSha256,
    'theme_count' => count($themes),
    'token_count' => count(SERVED_TOKEN_KEYS),
    'pair_count' => $pairCount,
    'themes' => $themes,
];

$rendered = json_encode(
    $fixture,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
) . "\n";
$fixtureFile = $hubRoot . '/' . FIXTURE_PATH;

if (!$check) {
    if (file_put_contents($fixtureFile, $rendered) === false) {
        fwrite(STDERR, "could not write the fixture\n");
        exit(2);
    }

    printf(
        "wrote %s: %d themes x %d tokens = %d pairs; source_sha=%s source_tag=%s dist_sha256=%s\n",
        FIXTURE_PATH,
        count($themes),
        count(SERVED_TOKEN_KEYS),
        $pairCount,
        $sourceSha,
        $sourceTag,
        $distSha256,
    );
    exit(0);
}

$committed = file_get_contents($fixtureFile);
if ($committed === $rendered) {
    printf(
        "OK: committed fixture is byte-identical to a live regeneration (dist_sha256=%s)\n",
        $distSha256,
    );
    exit(0);
}

fwrite(STDERR, sprintf(
    "STALE: the committed fixture is not byte-identical to a live regeneration of %s "
    . "(live dist_sha256=%s). Re-run the generator and commit the regenerated fixture together "
    . "with any ThemesController value corrections.\n",
    $distPath,
    $distSha256,
));
exit(1);
