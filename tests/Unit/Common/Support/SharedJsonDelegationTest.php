<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\Support;

use Phlix\Shared\Support\Json;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * HUB-JSON-F14 — shared `Json` delegation pins + the unflagged-encode extinction gate.
 *
 * F-14 measured the hub's encode law spread across per-site flag literals and
 * silent-`false` calls. The live byte law that governs adoption (the S2-r1 adjudication):
 * a site may swap to {@see Json} only when the swap cannot move a wire byte. Applied to
 * the live census, that leaves exactly three conversions in `src/`:
 *
 * - `SmokeJwtCommand` x2 — the only surviving calls with NO flags argument (the
 *   silent-`false` class F-14 names) → `Json::encode($x, 0)`: bytes identical, the
 *   `false` return replaced by a loud throw;
 * - `McpController::toolResult()` — flags already exactly the encodePretty trio
 *   (`PRETTY | UNESCAPED_SLASHES | THROW`), so `Json::encodePretty()` is the same
 *   call by definition;
 * - every other site — non-zero prior flags (THROW-only or custom sets) stay on their
 *   literal flags; converting them through the seam's DEFAULT would unescape slashes
 *   and unicode and move bytes.
 *
 * The assertions below are wire characterizations (the server `SharedPrimitiveDelegationTest`
 * idiom): each `legacy*` helper reproduces a pre-swap body verbatim, and the pairs hold
 * byte-identically before and after the swap — the golden bytes are pinned, never the
 * implementation. The last group is the extinction gate: no `json_encode()` call in `src/`
 * may drop its flags argument again. Following the law forged this program, the scan-based
 * pin asserts its OWN coverage — planted-fixture teeth for the scanner and a `__DIR__`-anchored
 * walk floor — so a vacuous green is impossible.
 *
 * @package Phlix\Hub\Tests\Unit\Common\Support
 */
final class SharedJsonDelegationTest extends TestCase
{
    /**
     * Payloads that separate the three flag postures: bare encodes escape `/` and
     * non-ASCII, the shared default un-escapes both, the pretty trio pretty-prints
     * while keeping slashes raw and unicode escaped. The `{}`-not-`[]` discriminator
     * rides along so empty shapes are compared too.
     *
     * @var list<array<mixed>>
     */
    private const PROBES = [
        [['url' => 'https://phlix.example/テスト/ok', 'empty' => []]],
        [['shares' => [['id' => 'a-b', 'peer_id' => 'テスト', 'status' => 'active']]]],
        [['reason' => 'peer_deleted', 'count' => 3, 'flag' => true, 'none' => null]],
    ];

    public function testZeroFlagEncodeIsByteIdenticalToTheLegacyBareCall(): void
    {
        foreach (self::PROBES as $index => $probe) {
            $legacy = json_encode($probe);
            self::assertIsString($legacy, 'probe payload must be encodable');

            self::assertSame(
                $legacy,
                Json::encode($probe, 0),
                "Json::encode(\$x, 0) must render the legacy bare bytes verbatim (probe {$index}).",
            );
        }
    }

    public function testTheExplicitZeroFlagIsLoadBearingAtTheMigratedConsoleSites(): void
    {
        $probe = 'https://phlix.example/テスト/ok';
        $legacyBare = json_encode($probe);
        self::assertIsString($legacyBare);

        // The shared DEFAULT would move bytes (slashes and unicode un-escaped). This is
        // why the migrated sites pass `0` explicitly — the S2 idiom — never the default.
        self::assertStringContainsString('\\/', $legacyBare);
        self::assertNotSame($legacyBare, Json::encode($probe));
        self::assertSame($legacyBare, Json::encode($probe, 0));
    }

    public function testPrettyEncodeIsByteIdenticalToTheLegacyTrio(): void
    {
        foreach (self::PROBES as $index => $probe) {
            $legacy = json_encode(
                $probe,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );

            self::assertSame(
                $legacy,
                Json::encodePretty($probe),
                "Json::encodePretty() must equal the legacy trio bytes (probe {$index}).",
            );
        }

        // The trio never un-escaped UNICODE — the migrated MCP text keeps `\uXXXX`
        // escapes exactly as before. Prove it on the string the trio actually renders.
        $unicode = json_encode('テスト', JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        self::assertStringContainsString('\\u30c6', $unicode);
        self::assertSame($unicode, Json::encodePretty('テスト'));
    }

    public function testTheMigratedFilesDelegateToTheSharedCodec(): void
    {
        $console = self::srcFileContents('Console/Commands/SmokeJwtCommand.php');
        self::assertStringContainsString('Json::encode($value, 0)', $console);
        self::assertStringContainsString('Json::encode($actual[$key], 0)', $console);
        self::assertSame(
            [],
            self::unflaggedEncodeSites(self::encodeCallSites($console, 'SmokeJwtCommand.php')),
            'the smoke command must not hand-roll encodes any more.',
        );

        $mcp = self::srcFileContents('Http/Controllers/McpController.php');
        self::assertStringContainsString('Json::encodePretty($payload)', $mcp);
        self::assertSame(
            [],
            self::encodeCallSites($mcp, 'McpController.php'),
            'McpController must not call json_encode() directly any more.',
        );
    }

    public function testNoUnflaggedJsonEncodeCallSurvivesInSrc(): void
    {
        $seen = 0;
        $violations = [];

        foreach (self::srcFiles() as $path) {
            $sites = self::encodeCallSites(self::readFile($path), $path);
            $seen += count($sites);
            foreach (self::unflaggedEncodeSites($sites) as $site) {
                $violations[] = $site;
            }
        }

        // Coverage floor: the walker must genuinely be reading the estate (live census:
        // 47 src call sites). A mis-wired walk sees zero sites and would pass the
        // violation assert vacuously forever — the exact failure this program banned.
        self::assertGreaterThanOrEqual(40, $seen, 'census walker saw almost no json_encode sites — it is mis-wired.');
        self::assertSame(
            [],
            $violations,
            "every src/ json_encode() must state its flags — a flag-less call returns false silently,\n"
            . 'which is the F-14 wire bug class (use Json::encode($x, 0) or name your flags).' . "\n"
            . 'Offenders:' . "\n" . implode("\n", $violations),
        );
    }

    public function testTheScannerCountsBareCallsOnlyAndIgnoresDecorations(): void
    {
        $fixture = <<<'PHP'
            <?php
            $a = json_encode($x);
            // json_encode($y) is a comment mention only
            /** docblock: json_encode($z) mention too */
            $w = json_encode($v, JSON_THROW_ON_ERROR);
            $s = json_encode($m) . json_encode($n, JSON_UNESCAPED_UNICODE);
            $tc = json_encode($vv,);
            $o = $obj->json_encode($p);
            C::json_encode($q);
            $j = Json::encode($r, 0);
            function json_encode($arg) { return ''; }
            PHP;

        $sites = self::encodeCallSites($fixture, 'fixture.php');

        // Real calls in the fixture: $a (bare), $w (flagged), $m (bare), $n (flagged),
        // $tc (bare — trailing comma must NOT read as a flag argument, r1 MINOR).
        // Excluded by shape: comment/docblock text, method call, static call, the
        // `Json::encode` name (token 'encode'), and the function declaration itself.
        self::assertCount(
            5,
            $sites,
            'scanner missed or invented a call site: ' . implode(' | ', array_column($sites, 'label')),
        );
        self::assertSame(
            ['fixture.php:2', 'fixture.php:6', 'fixture.php:7'],
            self::unflaggedEncodeSites($sites),
            'the bare-call detector must fire on unflagged calls only.',
        );
    }

    public function testTheWalkActuallyReachesTheProductionTree(): void
    {
        $files = self::srcFiles();

        self::assertGreaterThan(200, count($files), 'src/ walk found suspiciously few files');
        foreach (
            [
                'Http/Response.php',
                'Console/Commands/SmokeJwtCommand.php',
                'Http/Controllers/McpController.php',
                'SyncPlay/SyncPlayRelayWorker.php',
            ] as $relative
        ) {
            self::assertContains(
                self::srcDir() . '/' . $relative,
                $files,
                "the walk is anchored wrong and would scan a partial tree: missing {$relative}",
            );
        }
    }

    // ── scanner ──────────────────────────────────────────────────────────────

    /**
     * Every real `json_encode(...)` function call in a source string.
     *
     * @return list<array{label: string, flagged: bool}>
     */
    private static function encodeCallSites(string $code, string $label): array
    {
        $tokens = token_get_all($code);
        $count = count($tokens);
        $sites = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== 'json_encode') {
                continue;
            }

            $open = self::nextMeaningful($tokens, $i + 1);
            if ($open === null || $tokens[$open] !== '(') {
                continue;
            }

            $previous = self::previousMeaningful($tokens, $i - 1);
            if ($previous !== null && self::isNotFunctionCall($tokens[$previous])) {
                continue;
            }

            $sites[] = ['label' => $label . ':' . $token[2], 'flagged' => self::topArgCount($tokens, $open) >= 2];
        }

        return $sites;
    }

    /**
     * @param list<array{label: string, flagged: bool}> $sites
     *
     * @return list<string>
     */
    private static function unflaggedEncodeSites(array $sites): array
    {
        $offenders = [];
        foreach ($sites as $site) {
            if (!$site['flagged']) {
                $offenders[] = $site['label'];
            }
        }

        return $offenders;
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function isNotFunctionCall(array|string $token): bool
    {
        return is_array($token)
            && in_array($token[0], [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_STRING], true);
    }

    /**
     * Count top-level comma arguments of the call whose open paren sits at $open.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function topArgCount(array $tokens, int $open): int
    {
        $depth = 0;
        $commas = 0;
        $count = count($tokens);

        for ($k = $open; $k < $count; $k++) {
            $text = self::tokenText($tokens[$k]);
            if ($text === null) {
                continue;
            }
            if ($text === '(' || $text === '[') {
                if ($k !== $open) {
                    $depth++;
                }
                continue;
            }
            if ($text === ')' || $text === ']') {
                if ($depth === 0 && $text === ')') {
                    break;
                }
                $depth--;
                continue;
            }
            if ($text === ',' && $depth === 0) {
                // A comma whose very next meaningful token closes the call is a
                // trailing separator, not an argument delimiter: `json_encode($v,)`
                // is behaviorally the flag-less silent-false call this gate exists
                // to extinct (HUB-JSON r1 MINOR).
                $next = self::nextMeaningful($tokens, $k + 1);
                if ($next === null || $tokens[$next] !== ')') {
                    $commas++;
                }
            }
        }

        return $commas + 1;
    }

    /**
     * Significant token text, or null for whitespace/comments (their contents must
     * never feed the bracket counter — docblocks quote `json_encode(` too).
     *
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function tokenText(array|string $token): ?string
    {
        if (!is_array($token)) {
            return $token;
        }
        if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            return null;
        }

        return $token[1];
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function nextMeaningful(array $tokens, int $from): ?int
    {
        $count = count($tokens);
        for ($k = $from; $k < $count; $k++) {
            if (self::tokenText($tokens[$k]) !== null) {
                return $k;
            }
        }

        return null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function previousMeaningful(array $tokens, int $from): ?int
    {
        for ($k = $from; $k >= 0; $k--) {
            if (self::tokenText($tokens[$k]) !== null) {
                return $k;
            }
        }

        return null;
    }

    // ── tree ─────────────────────────────────────────────────────────────────

    private static function srcDir(): string
    {
        $dir = realpath(__DIR__ . '/../../../../src');
        if ($dir === false) {
            throw new RuntimeException('src/ root not reachable from ' . __DIR__);
        }

        return $dir;
    }

    /**
     * @return list<string>
     */
    private static function srcFiles(): array
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::srcDir(), RecursiveDirectoryIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    private static function srcFileContents(string $relative): string
    {
        return self::readFile(self::srcDir() . '/' . $relative);
    }

    private static function readFile(string $path): string
    {
        $code = file_get_contents($path);
        if ($code === false) {
            throw new RuntimeException("cannot read {$path}");
        }

        return $code;
    }
}
