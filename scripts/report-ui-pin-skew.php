<?php

/**
 * S181 — enumerate every tag-pinned `@phlix/ui` (and, for free,
 * `@phlix/contracts`) dependency across the estate's four known consumers and
 * report version skew against the LIVE release-tag list, computed at run time.
 *
 * ## The defect this closes, measured 2026-08-03 and re-measured 2026-09-12
 *
 * Four consumers pin `@phlix/ui` by tag on TWO different syntaxes — a GitHub
 * archive tarball URL (`phlix-server/web-ui`, `phlix-hub/web-ui`) and the npm
 * `github:` shorthand (`phlix-windows-client`, `phlix-tizen-client`). The
 * single-syntax sweeps the estate had been using see only one form: a search
 * for the tarball URL missed tizen entirely, and S181's own discovery was that
 * `phlix-windows-client` sat 17 minors behind `phlix-ui` with no gate, no
 * warning and nothing in CI reacting. The skew then WIDENED inside a single
 * day while the rollout runbook repinned only the two tarball consumers.
 *
 * ## Why the skew is computed, never asserted
 *
 * Every version figure written into the step text rotted within weeks of being
 * measured, and the recorded failure class is a check derived from its own
 * subject. This script therefore contains NO version constant: it reads the
 * four pin values, classifies each syntax, then asks `git ls-remote --tags`
 * what `phlix-ui` (and `phlix-contracts`) release RIGHT NOW and counts the
 * stable tags strictly newer than each pin. A pin that matches the newest tag
 * is OK; one that does not is STALE and the exit is red — which is the point:
 * the reporter's job is visibility, and "skew widened while nothing noticed"
 * is the bug being closed.
 *
 * ## It fails when it cannot measure
 *
 * Same doctrine as `assert-cross-repo-hub-paths.php`: an unfetchable consumer
 * file, an unreadable tag list, an empty tag set, a required pin that vanished,
 * a pin ref that is not a live release tag, and a pin written in a syntax the
 * classifier does not know are ALL failures with `::error::` annotations —
 * never a silent skip, never exit 0. Discovery of a pin is KEY-BASED
 * (`@phlix/ui` / `@phlix/contracts` in any dependency section) with a
 * value-based alias scan on top, so a fifth pin in a THIRD syntax is found and
 * reported UNMATCHED rather than swept under the rug: a check that silently
 * returns 3-of-4 is exactly the failure S181 exists to remove.
 *
 * ## Usage
 *
 *   php scripts/report-ui-pin-skew.php
 *       One command, no arguments: hub's own web-ui/package.json is read from
 *       disk; the server/windows/tizen package.json files are fetched
 *       anonymously from raw.githubusercontent.com (no token, ever); live tags
 *       come from `git ls-remote --tags` (public repos need no credential —
 *       the same anonymous rule as the snapshot-currency CI job).
 *
 *   php scripts/report-ui-pin-skew.php \
 *       --package-json=KEY=PATH [--package-json=KEY=PATH ...] \
 *       --tags-file=PATH --contracts-tags-file=PATH
 *       Test/CI seams. `KEY=PATH` replaces the named consumer's source with a
 *       local file — CI uses this after curling the files itself, so the
 *       script performs no network I/O there — or ADDS a labelled extra
 *       consumer when KEY is unknown (the scratch-file sweep). The tags files
 *       feed the same parser as the `git ls-remote --tags` output.
 *
 * Exit 0 means: every required pin was found, classified, and matches the live
 * newest tag. Exit 1 means at least one `::error::` line was printed.
 *
 * Structurally this file mirrors `assert-cross-repo-hub-paths.php`: constants
 * plus closures held in variables, then one straight-line body — a file that
 * declares top-level functions AND runs logic violates PSR-1 side effects, and
 * the corpus gate fails on it.
 *
 * @package Phlix\Hub
 */

declare(strict_types=1);

/**
 * Provenance stamp of this step. Kept in a string literal (not prose) so it
 * survives comment-stripping: a check whose identity lives only in comments can
 * be deleted along with its documentation and leave no trace.
 */
const STEP_STAMP = 'S181PINSKEWX9Q2';

/**
 * The packages this reporter enumerates, and where their live tags live.
 *
 * `required_in` is the positive control: every listed consumer MUST carry a
 * verifiable pin of this package on every run, and a vanished one is a loud
 * failure, not a shorter table. `@phlix/ui` is the block's four-consumer set;
 * `@phlix/contracts` is covered by the same two syntaxes for free (the block's
 * "cover both packages or say why not"), but no consumer is REQUIRED to pin it
 * today — an absent contracts pin prints a non-blocking ABSENT row so the
 * sweep's blind spots stay visible without gating a shape the estate has not
 * adopted.
 *
 * @var array<string, array{repo: string, git_url: string, required_in: list<string>}>
 */
const TARGETS = [
    '@phlix/ui' => [
        'repo' => 'detain/phlix-ui',
        'git_url' => 'https://github.com/detain/phlix-ui',
        'required_in' => ['hub', 'server', 'windows', 'tizen'],
    ],
    '@phlix/contracts' => [
        'repo' => 'detain/phlix-contracts',
        'git_url' => 'https://github.com/detain/phlix-contracts',
        'required_in' => [],
    ],
];

/**
 * The four in-estate consumers, as key => [label, repo, path within repo,
 * URL or null for "this repository, read from disk"].
 *
 * The list is the block's measured set, deliberately DECLARED rather than
 * discovered: GitHub code-search from a CI job needs a token (the estate
 * clones anonymously) and would reintroduce S177's mistake of trusting one
 * syntax's search hits. What this script guarantees is that every declared
 * consumer is SWEPT and that a pin it cannot classify is escalated — see the
 * UNMATCHED contract in the header. A fifth consumer must be added to this
 * list deliberately, in the commit that adds the dependency.
 *
 * @var array<string, array{label: string, repo: string, path: string, url: ?string}>
 */
const CONSUMERS = [
    'hub' => [
        'label' => 'phlix-hub',
        'repo' => 'detain/phlix-hub',
        'path' => 'web-ui/package.json',
        'url' => null,
    ],
    'server' => [
        'label' => 'phlix-server',
        'repo' => 'detain/phlix-server',
        'path' => 'web-ui/package.json',
        'url' => 'https://raw.githubusercontent.com/detain/phlix-server/master/web-ui/package.json',
    ],
    'windows' => [
        'label' => 'phlix-windows-client',
        'repo' => 'detain/phlix-windows-client',
        'path' => 'package.json',
        'url' => 'https://raw.githubusercontent.com/detain/phlix-windows-client/master/package.json',
    ],
    'tizen' => [
        'label' => 'phlix-tizen-client',
        'repo' => 'detain/phlix-tizen-client',
        'path' => 'package.json',
        'url' => 'https://raw.githubusercontent.com/detain/phlix-tizen-client/master/package.json',
    ],
];

/**
 * Every dependency section npm resolves, so a pin moved from dependencies to
 * devDependencies (or hidden in peer/optional) is still swept.
 *
 * @var list<string>
 */
const DEPENDENCY_SECTIONS = ['dependencies', 'devDependencies', 'optionalDependencies', 'peerDependencies'];

/**
 * `git ls-remote --tags` lines look like `sha\trefs/tags/v0.99.1`, plus a
 * peeled duplicate ending `^{}` for annotated tags; everything after
 * refs/tags/ is the tag name.
 */
const TAG_REF_PREFIX = 'refs/tags/';

/** A stable semver release tag, with or without the leading `v`. */
const RELEASE_TAG_PATTERN = '/^v?\d+\.\d+\.\d+$/';

/**
 * Emit one GitHub-annotated error line. Every finding shares this shape so the
 * workflow log, the PR annotations and a local run all say the same thing.
 */
$annotateError = static function (string $message): void {
    $prefix = sprintf("::error::%s S181 @phlix/ui pin skew: ", STEP_STAMP);
    fwrite(STDERR, $prefix . $message . "\n");
};

/**
 * Fail loudly and immediately — the cannot-measure and the not-current paths
 * both end here, so "exit 0" means exactly one thing: everything enumerated
 * and everything current. Declared `: never` so static analysis keeps narrowing
 * types across call sites.
 */
$fail = static function (string $message) use ($annotateError): never {
    $annotateError($message);
    exit(1);
};

/**
 * Read $argv into scalar long options plus the repeated --package-json list.
 *
 * @param array<int, string> $argv
 *
 * @return array{scalars: array<string, string>, package_jsons: list<string>}
 */
$readFlags = static function (array $argv): array {
    $scalars = [];
    $packageJsons = [];
    /** @var list<string> $optionArgs */
    $optionArgs = array_slice($argv, 1);
    foreach ($optionArgs as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }

        $body = substr($arg, 2);
        $eq = strpos($body, '=');
        if ($eq === false) {
            $scalars[$body] = '1';
            continue;
        }

        $name = substr($body, 0, $eq);
        $value = substr($body, $eq + 1);
        if ($name === 'package-json') {
            $packageJsons[] = $value;
            continue;
        }

        $scalars[$name] = $value;
    }

    return ['scalars' => $scalars, 'package_jsons' => $packageJsons];
};

/**
 * Reduce the repeated `KEY=PATH` specs into consumer overrides, rejecting
 * malformed specs loudly instead of ignoring them.
 *
 * @param list<string> $specs
 *
 * @return array<string, string>
 */
$parseOverrides = static function (array $specs) use ($fail): array {
    $overrides = [];
    /** @var list<string> $specList */
    $specList = $specs;
    foreach ($specList as $spec) {
        if (preg_match('/^([a-z0-9_-]+)=(.+)$/s', $spec, $m) !== 1) {
            $fail(sprintf('--package-json wants KEY=PATH, got "%s"', $spec));
        }

        $overrides[$m[1]] = $m[2];
    }

    return $overrides;
};

/**
 * @param array<string, string> $scalars
 */
$optionalPathOption = static function (array $scalars, string $name) use ($fail): ?string {
    /** @var array<string, string> $opts */
    $opts = $scalars;
    $value = $opts[$name] ?? null;
    if ($value === null) {
        return null;
    }

    if (trim($value) === '') {
        $fail(sprintf('--%s was given an empty path', $name));
    }

    return $value;
};

$readLocalFile = static function (string $path, string $what) use ($fail): string {
    if (!is_file($path) || !is_readable($path)) {
        $fail(sprintf('%s (%s) is not a readable file — nothing was measured.', $what, $path));
    }

    $body = file_get_contents($path);
    if (!is_string($body) || trim($body) === '') {
        $fail(sprintf('%s (%s) could not be read or is empty.', $what, $path));
    }

    return $body;
};

$fetchUrl = static function (string $url) use ($fail): string {
    $cmd = sprintf('curl -fsSL --max-time 30 %s 2>/dev/null', escapeshellarg($url));
    $lines = [];
    $status = 1;
    $ok = exec($cmd, $lines, $status);
    /** @var list<string> $bodyLines */
    $bodyLines = $lines;
    if ($ok === false || $status !== 0 || $lines === []) {
        $fail(sprintf(
            'could not anonymously fetch %s (curl exit %d, empty=%s) — nothing was measured for that consumer.',
            $url,
            $status,
            $lines === [] ? 'yes' : 'no',
        ));
    }

    return implode("\n", $bodyLines) . "\n";
};

/**
 * Parse `git ls-remote --tags` text into a de-duplicated, ascending list of
 * stable release tags: `['raw' => displayed name, 'num' => comparator form]`.
 *
 * Prerelease/dev tags are excluded from the LADDER on purpose: the newest
 * stable release is the bar every consumer is expected to meet, and a pin that
 * names a non-stable ref trips the NOT-A-RELEASE verdict rather than silently
 * matching a rung the ladder does not carry.
 *
 * @return list<array{raw: string, num: string}>
 */
$parseTagList = static function (string $lsRemoteOutput, string $package) use ($fail): array {
    $seen = [];
    foreach (preg_split('/\R/', $lsRemoteOutput) ?: [] as $line) {
        $tab = strpos($line, "\t");
        if ($tab === false) {
            continue;
        }

        $ref = trim(substr($line, $tab + 1));
        if (!str_starts_with($ref, TAG_REF_PREFIX)) {
            continue;
        }

        $name = preg_replace('/\^\{\}$/', '', substr($ref, strlen(TAG_REF_PREFIX))) ?? '';
        if (preg_match(RELEASE_TAG_PATTERN, $name) !== 1) {
            continue;
        }

        $seen[ltrim($name, 'vV')] = $name;
    }

    $tags = [];
    foreach ($seen as $num => $raw) {
        $tags[] = ['raw' => $raw, 'num' => $num];
    }

    usort(
        $tags,
        /**
         * @param array{raw: string, num: string} $a
         * @param array{raw: string, num: string} $b
         */
        static fn (array $a, array $b): int => version_compare($a['num'], $b['num'])
    );

    if ($tags === []) {
        $fail(sprintf(
            'the tag list for %s is empty — no stable release tag could be read, so no skew could be '
            . 'computed. A reporter that cannot measure fails; it does not report green.',
            $package,
        ));
    }

    return $tags;
};

/**
 * Live tags for one target: the tags-file seam when given, else anonymous
 * `git ls-remote` (public repos need no token — same rule as snapshot-currency).
 *
 * @param array{repo: string, git_url: string, required_in: list<string>} $target
 *
 * @return list<array{raw: string, num: string}>
 */
$liveTags = static function (
    string $package,
    array $target,
    ?string $tagsFile
) use (
    $fail,
    $readLocalFile,
    $parseTagList,
): array {
    if ($tagsFile !== null) {
        return $parseTagList(
            $readLocalFile($tagsFile, sprintf('tags file for %s', $package)),
            $package,
        );
    }

    /** @var array{repo: string, git_url: string, required_in: list<string>} $typedTarget */
    $typedTarget = $target;
    $lines = [];
    $status = 1;
    $ok = exec(
        sprintf(
            'GIT_TERMINAL_PROMPT=0 git ls-remote --tags %s 2>&1',
            escapeshellarg($typedTarget['git_url']),
        ),
        $lines,
        $status,
    );
    /** @var list<string> $remoteLines */
    $remoteLines = $lines;
    if ($ok === false || $status !== 0) {
        $fail(sprintf(
            'could not run `git ls-remote --tags %s` (exit %d): %s',
            $typedTarget['git_url'],
            $status,
            implode(' | ', array_slice($remoteLines, 0, 3)),
        ));
    }

    return $parseTagList(implode("\n", $remoteLines), $package);
};

/**
 * Classify one pin value against one target repository.
 *
 * Exactly two syntaxes are KNOWN today (the block's "two syntaxes"): the
 * archive tarball URL and the `github:` shorthand. Every other value — a
 * `file:` link, a registry semver range, a `git+ssh:` URL, an alias — returns
 * UNMATCHED so the caller escalates it. An unmatched value is never a miss:
 * the pin was FOUND (by key or by repo reference) and its shape is what is
 * unknown.
 *
 * @return array{syntax: string, tag: ?string}
 */
$classifyPin = static function (string $repo, string $value): array {
    $name = substr($repo, strlen('detain/'));
    $quoted = preg_quote($name, '~');

    $tarball = '~^https://github\.com/detain/' . $quoted . '/archive/refs/tags/([^/]+)\.tar\.gz$~';
    if (preg_match($tarball, $value, $m) === 1) {
        return ['syntax' => 'tarball', 'tag' => $m[1]];
    }

    $shorthand = '~^github:detain/' . $quoted . '(?:\.git)?#([^#]+)$~';
    if (preg_match($shorthand, $value, $m) === 1) {
        return ['syntax' => 'github:', 'tag' => $m[1]];
    }

    return ['syntax' => 'UNMATCHED', 'tag' => null];
};

/**
 * Does this dependency value reference the target repository at all? The
 * negative-lookahead boundary keeps `detain/phlix-ui` from matching inside
 * `detain/phlix-ui-website`.
 */
$valueReferencesRepo = static function (string $repo, string $value): bool {
    return preg_match('~' . preg_quote($repo, '~') . '(?![a-z0-9._-])~', $value) === 1;
};

/**
 * Count of stable tags strictly newer than the pin — the skew figure, computed
 * against the LIVE ladder on every run; zero is measured, never assumed.
 *
 * @param list<array{raw: string, num: string}> $tags ascending
 */
$releasesBehind = static function (array $tags, string $pinNum): int {
    $behind = 0;
    /** @var list<array{raw: string, num: string}> $ladder */
    $ladder = $tags;
    foreach ($ladder as $tag) {
        if (version_compare($tag['num'], $pinNum, '>')) {
            ++$behind;
        }
    }

    return $behind;
};

/**
 * @param list<array{raw: string, num: string}> $tags
 */
$newestTag = static function (array $tags): string {
    /** @var list<array{raw: string, num: string}> $ladder */
    $ladder = $tags;
    return $ladder === [] ? '-' : $ladder[count($ladder) - 1]['raw'];
};

/**
 * Build one output row: what was swept, what it says, what live says, verdict.
 *
 * @param list<array{raw: string, num: string}> $tags
 *
 * @return array{consumer: string, package: string, syntax: string, pin: string,
 *     latest: string, behind: string, verdict: string, blocking: bool, note: string}
 */
$makeRow = static function (
    string $consumer,
    string $package,
    string $syntax,
    string $pin,
    array $tags,
    string $behind,
    string $verdict,
    bool $blocking,
    string $note,
) use ($newestTag): array {
    return [
        'consumer' => $consumer,
        'package' => $package,
        'syntax' => $syntax,
        'pin' => $pin,
        'latest' => $newestTag($tags),
        'behind' => $behind,
        'verdict' => $verdict,
        'blocking' => $blocking,
        'note' => $note,
    ];
};

/**
 * Pull every dependency entry from one package.json body, across all
 * DEPENDENCY_SECTIONS, as [section, key, value] triples.
 *
 * @return list<array{0: string, 1: string, 2: string}>
 */
$dependencyEntries = static function (string $body, string $what) use ($fail): array {
    $json = json_decode($body, true);
    if (!is_array($json)) {
        $fail(sprintf(
            '%s is not a valid JSON object (%s) — the sweep stopped rather than guess.',
            $what,
            json_last_error_msg(),
        ));
    }

    $entries = [];
    foreach (DEPENDENCY_SECTIONS as $section) {
        if (!array_key_exists($section, $json)) {
            continue;
        }

        $map = $json[$section];
        if (!is_array($map)) {
            $fail(sprintf('%s has a non-object "%s" section.', $what, $section));
        }

        // json_decode hands us mixed by construction; the is_string guard two
        // lines down IS the validation, so the loop-variable assignment itself is
        // suppressed rather than faked with an @var assertion (which would make
        // the honest guard look redundant to psalm). Same targeted-suppress
        // pattern as src/Auth/JwtHandler.php.
        /** @psalm-suppress MixedAssignment */
        foreach ($map as $name => $version) {
            if (!is_string($name) || !is_string($version)) {
                $fail(sprintf('%s carries a non-string dependency entry in "%s".', $what, $section));
            }

            $entries[] = [$section, $name, $version];
        }
    }

    return $entries;
};

// ---- main ------------------------------------------------------------------

$flags = $readFlags($argv ?? []);
$overrides = $parseOverrides($flags['package_jsons']);
$uiTagsFile = $optionalPathOption($flags['scalars'], 'tags-file');
$contractsTagsFile = $optionalPathOption($flags['scalars'], 'contracts-tags-file');

$hubRoot = dirname(__DIR__);

/**
 * Resolve consumer sources: hub from this checkout's disk, the other three via
 * anonymous fetch, every one replaceable by --package-json (and extended by an
 * unknown KEY, which ADDS a labelled extra consumer — the scratch-file sweep).
 *
 * @var array<string, array{label: string, body: string}> $sources
 */
$sources = [];
$order = array_keys(CONSUMERS);
foreach (CONSUMERS as $key => $spec) {
    $override = $overrides[$key] ?? null;
    if ($override !== null) {
        $sources[$key] = [
            'label' => $spec['label'],
            'body' => $readLocalFile($override, sprintf('override for consumer %s', $key)),
        ];
        continue;
    }

    if ($spec['url'] === null) {
        $sources[$key] = [
            'label' => $spec['label'],
            'body' => $readLocalFile($hubRoot . '/' . $spec['path'], sprintf('%s %s', $spec['label'], $spec['path'])),
        ];
        continue;
    }

    $sources[$key] = ['label' => $spec['label'], 'body' => $fetchUrl($spec['url'])];
}

foreach ($overrides as $key => $path) {
    if (isset($sources[$key])) {
        continue;
    }

    $sources[$key] = [
        'label' => sprintf('%s (extra)', $key),
        'body' => $readLocalFile($path, sprintf('extra consumer "%s"', $key)),
    ];
    $order[] = $key;
}

/**
 * First pass: collect pins per (consumer, package) BEFORE fetching any ladder,
 * so a contracts tag list nobody needs is never requested.
 *
 * @var array<string, array<string, list<array{key: string, section: string, value: string}>>> $pins
 */
$pins = [];
foreach ($order as $consumerKey) {
    /** @var list<array{0: string, 1: string, 2: string}> $entries */
    $entries = $dependencyEntries(
        $sources[$consumerKey]['body'],
        sprintf('%s package.json', $sources[$consumerKey]['label']),
    );
    foreach (TARGETS as $package => $target) {
        foreach ($entries as [$section, $name, $version]) {
            if ($name === $package || $valueReferencesRepo($target['repo'], $version)) {
                $pins[$consumerKey][$package][] = [
                    'key' => $name,
                    'section' => $section,
                    'value' => $version,
                ];
            }
        }
    }
}

/** @var array<string, list<array{raw: string, num: string}>> $tagSets */
$tagSets = [];
foreach (TARGETS as $package => $target) {
    $anyPin = false;
    foreach ($pins as $byPackage) {
        if (isset($byPackage[$package])) {
            $anyPin = true;
            break;
        }
    }

    $seam = $package === '@phlix/ui' ? $uiTagsFile : $contractsTagsFile;
    if (!$anyPin && $seam === null && $target['required_in'] === []) {
        // Nobody pins it and nothing requires it: the ABSENT rows below carry
        // that truth, and an unused network call proves nothing.
        $tagSets[$package] = [];
        continue;
    }

    $tagSets[$package] = $liveTags($package, $target, $seam);
}

/**
 * Graded rows; one per (consumer, package, matching dependency entry).
 *
 * @var list<array{consumer: string, package: string, syntax: string, pin: string,
 *     latest: string, behind: string, verdict: string, blocking: bool, note: string}> $rows
 */
$rows = [];

/**
 * Second pass: grade every (consumer, package) pair, required or not, and mark
 * required pairs as CHECKED the moment a verdict exists for them.
 *
 * @var array<string, true> $requiredChecked pairs "package|consumer-key"
 */
$requiredChecked = [];
foreach ($order as $consumerKey) {
    $label = $sources[$consumerKey]['label'];
    foreach (TARGETS as $package => $target) {
        $required = in_array($consumerKey, $target['required_in'], true);
        $found = $pins[$consumerKey][$package] ?? [];

        if ($found === []) {
            if ($required) {
                $requiredChecked[$package . '|' . $consumerKey] = true;
            }

            $rows[] = $makeRow(
                $label,
                $package,
                '-',
                '-',
                $tagSets[$package],
                '-',
                $required ? 'MISSING' : 'ABSENT',
                $required,
                $required
                    ? sprintf(
                        '%s no longer carries a %s pin at all — the dependency vanished or the file '
                        . 'restructured. Either is a blind spot; this sweep refuses to grade what it '
                        . 'cannot see.',
                        $label,
                        $package,
                    )
                    : sprintf(
                        '%s does not pin %s (expected today; reported so the absence is never silent).',
                        $label,
                        $package,
                    ),
            );
            continue;
        }

        foreach ($found as $entry) {
            if ($required) {
                $requiredChecked[$package . '|' . $consumerKey] = true;
            }

            /** @var array{syntax: string, tag: ?string} $classification */
            $classification = $classifyPin($target['repo'], $entry['value']);
            /** @var list<array{raw: string, num: string}> $tags */
            $tags = $tagSets[$package];

            if ($classification['syntax'] === 'UNMATCHED') {
                $rows[] = $makeRow(
                    $label,
                    $package,
                    'UNMATCHED',
                    $entry['value'],
                    $tags,
                    '-',
                    'UNMATCHED',
                    true,
                    sprintf(
                        '%s pins %s (as "%s" in %s) with a value no known syntax explains: "%s". '
                        . 'Update the classifier DELIBERATELY with this value in view — never let the '
                        . 'sweep go blind.',
                        $label,
                        $package,
                        $entry['key'],
                        $entry['section'],
                        $entry['value'],
                    ),
                );
                continue;
            }

            $pinTag = $classification['tag'] ?? '';
            $pinNum = ltrim($pinTag, 'vV');
            $aliasNote = $entry['key'] === $package
                ? ''
                : sprintf('found via alias key "%s" in %s; ', $entry['key'], $entry['section']);
            $isLive = preg_match(RELEASE_TAG_PATTERN, $pinTag) === 1
                && in_array($pinNum, array_column($tags, 'num'), true);

            if (!$isLive) {
                $rows[] = $makeRow(
                    $label,
                    $package,
                    $classification['syntax'],
                    $pinTag,
                    $tags,
                    '-',
                    'NOT-A-RELEASE',
                    true,
                    $aliasNote . sprintf(
                        '%s pins %s to "%s", which is not a stable release tag of %s (a branch, a '
                        . 'prerelease, or a tag that no longer exists) — the pin floats and nothing '
                        . 'can grade it.',
                        $label,
                        $package,
                        $pinTag,
                        $package,
                    ),
                );
                continue;
            }

            $behind = $releasesBehind($tags, $pinNum);
            $rows[] = $makeRow(
                $label,
                $package,
                $classification['syntax'],
                $pinTag,
                $tags,
                (string) $behind,
                $behind > 0 ? 'STALE' : 'OK',
                $behind > 0,
                $behind > 0
                    ? $aliasNote . sprintf(
                        '%s pins %s at %s — %d release(s) behind the live newest tag %s.',
                        $label,
                        $package,
                        $pinTag,
                        $behind,
                        $newestTag($tags),
                    )
                    : $aliasNote,
            );
        }
    }
}

// Self-sweep floor: the positive control of the control. Every required
// (package, consumer) pair must have produced a verdict row; a short table
// means the sweep itself broke — the one outcome greener than red must hide.
$requiredPairs = 0;
foreach (TARGETS as $target) {
    $requiredPairs += count($target['required_in']);
}

if (count($requiredChecked) !== $requiredPairs) {
    $rows[] = $makeRow(
        '(enumerator)',
        '(self-sweep)',
        '-',
        '-',
        [],
        '-',
        'BROKEN-SWEEP',
        true,
        sprintf(
            'self-sweep guard: %d required pin verdict(s) expected, %d produced — the enumerator is '
            . 'broken, and a short table is exactly the silent 3-of-4 this step exists to remove.',
            $requiredPairs,
            count($requiredChecked),
        ),
    );
}

echo sprintf(
    "%-24s %-16s %-10s %-44s %-9s %-6s %-14s %s\n",
    'CONSUMER',
    'PACKAGE',
    'SYNTAX',
    'PIN',
    'LATEST',
    'BEHIND',
    'VERDICT',
    'NOTE',
);

foreach ($rows as $row) {
    echo rtrim(sprintf(
        "%-24s %-16s %-10s %-44s %-9s %-6s %-14s %s",
        $row['consumer'],
        $row['package'],
        $row['syntax'],
        $row['pin'],
        $row['latest'],
        $row['behind'],
        $row['verdict'],
        $row['note'] === '' ? '' : '← ' . $row['note'],
    )), "\n";
}

/** @var list<array{consumer: string, package: string, syntax: string, pin: string, latest: string,
 *     behind: string, verdict: string, blocking: bool, note: string}> $blocking */
$blocking = array_values(array_filter(
    $rows,
    /**
     * @param array{consumer: string, package: string, syntax: string, pin: string, latest: string,
     *     behind: string, verdict: string, blocking: bool, note: string} $r
     */
    static fn (array $r): bool => $r['blocking']
));
foreach ($blocking as $row) {
    $annotateError($row['note']);
}

if ($blocking !== []) {
    printf(
        "%s S181 pin-skew report RED: %d row(s) enumerated, %d blocking finding(s) above. "
        . "Latest live tags: @phlix/ui=%s, @phlix/contracts=%s.\n",
        STEP_STAMP,
        count($rows),
        count($blocking),
        $newestTag($tagSets['@phlix/ui']),
        $newestTag($tagSets['@phlix/contracts']),
    );
    exit(1);
}

printf(
    "%s S181 pin-skew report: %d consumer file(s) swept, %d verdict row(s), 0 blocking. "
    . "Latest live tags: @phlix/ui=%s, @phlix/contracts=%s. ALL REQUIRED PINS CURRENT — VERDICT: OK\n",
    STEP_STAMP,
    count($order),
    count($rows),
    $newestTag($tagSets['@phlix/ui']),
    $newestTag($tagSets['@phlix/contracts']),
);
exit(0);
