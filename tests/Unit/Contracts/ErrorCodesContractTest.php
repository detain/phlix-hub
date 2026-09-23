<?php

/**
 * Phlix hub component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Contracts;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_keys;
use function array_merge;
use function array_unique;
use function array_values;
use function count;
use function file_get_contents;
use function in_array;
use function is_dir;
use function json_decode;
use function json_encode;
use function ksort;
use function preg_match_all;
use function sort;
use function trim;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Pins the hub's error-code vocabulary to `@phlix/contracts` v0.5.1 and
 * enforces the hub-side wire law: every literal the hub places on a
 * `code`/`error_code` wire field is a REGISTERED stable code.
 *
 * ## Why this file exists
 *
 * The W3 emit-wave doctrine is error-code-first: stable registered codes on
 * the wire, English text as debug fallback. The SSOT vocabulary is
 * `@phlix/contracts` `dist/error-codes.json` (202 codes / 37 domains at
 * v0.5.1). This test is the hub half of the vendored-fixture law modelled on
 * {@see \Phlix\Hub\Tests\Unit\Mcp\McpScopesContractTest}: same byte-copied
 * artifact, same one-line PIN, same anti-vacuity floor asserted BEFORE any
 * comparison, same fixture-honesty marker check, same hardcoded tag literal
 * lockstep.
 *
 * ## The wire law
 *
 * `testEveryHubWireCodeLiteralIsRegistered()` statically scans `src/` for
 * string literals reaching the wire's `code` channel in any of its three
 * shapes:
 *  1. `'code' => '…'` / `'error_code' => '…'` array entries,
 *  2. the second positional argument of `Response::error(…)`,
 *  3. the first argument of `Response::errorBody(…)`.
 *
 * Every literal so found must exist in the vendored fixture. The whitelist
 * is documented below and is EMPTY — any entry added to it needs a written
 * reason in the same commit.
 *
 * ## Known static-scan limits (deliberate, documented)
 *
 * Sites that pass a code through a VARIABLE (`'code' => $code`) are invisible
 * to a literal scan and are verified by registry reference instead: the 14
 * `ALEXA_*` rejections (`AlexaSignatureMiddleware::reject()` — its own
 * deferred emit-wave per the registry's `alexa.*` note), and JSON-RPC /
 * OAuth-authorization-code `code` fields, which are NOT members of this
 * vocabulary at all (RFC 6749/6750 values and integer JSON-RPC codes are
 * registry-excluded by design).
 *
 * ## The failure this file must never become
 *
 * A truncated, empty or wrong-keyed fixture would make every comparison below
 * vacuous — a gate that inspects nothing, wearing the costume of a gate that
 * inspects everything. Hence the floor BEFORE comparisons, and hence
 * `testTheWireLawScannerActuallyDetectsAnUnknownCode()`, which proves the
 * scanner is not decorative by pointing it at a synthetic snippet carrying a
 * planted unknown code.
 *
 * @package Phlix\Hub\Tests\Unit\Contracts
 */
final class ErrorCodesContractTest extends TestCase
{
    /**
     * Anti-vacuity floor: the registry carried 147 codes at its first hub
     * audit and 202 at v0.5.1. Asserted on the fixture BEFORE any comparison.
     * If the registry ever legitimately drops below this, this constant is
     * edited deliberately, in the same commit, with the reason stated —
     * that edit is what makes the shrink visible.
     */
    private const int CODE_FLOOR = 147;

    /**
     * Minimum distinct literals the live src/ scan must see. Guards the wire
     * law against path/iterator rot: a scanner that silently finds nothing
     * would otherwise pass by comparing [] to []. As of the W3 emit-wave the
     * scan sees 77 distinct registered literals.
     */
    private const int LIVE_SCAN_FLOOR = 40;

    /**
     * Hardcoded tag lockstep (mcp-scopes law): bump together with
     * `tests/fixtures/contracts/error-codes.PIN` and the re-vendored fixture
     * in one commit.
     */
    private const string CONTRACT_TAG = 'v0.5.1';

    private const string FIXTURE = __DIR__ . '/../../fixtures/contracts/error-codes.json';

    private const string PIN = __DIR__ . '/../../fixtures/contracts/error-codes.PIN';

    private const string SRC_DIR = __DIR__ . '/../../../src';

    /**
     * Wire-law whitelist: literals exempt from registry membership.
     *
     * EMPTY as of the W3 emit-wave — every code-channel literal in src/ is a
     * registered code. A future entry must carry an inline reason comment
     * (which field, why it is not an error code, registry reference).
     *
     * @var list<string>
     */
    private const array WIRE_LAW_WHITELIST = [];

    public function testVendoredVocabularyIsWellFormedAndFloored(): void
    {
        self::assertFileExists(self::FIXTURE, 'the vendored @phlix/contracts error-code vocabulary is missing');

        $decoded = json_decode((string) file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, 'the vendored artifact must decode to an object');
        self::assertSame(['$comment', 'codes'], array_keys($decoded), 'unexpected generated shape');

        $codes = $decoded['codes'] ?? null;

        // ANTI-VACUITY, BEFORE any comparison (see class docblock).
        self::assertIsArray($codes, 'FLOOR: the fixture has no usable `codes` array');
        self::assertGreaterThanOrEqual(
            self::CODE_FLOOR,
            count($codes),
            'FLOOR: the fixture must carry at least ' . self::CODE_FLOOR
            . ' codes (202 as of ' . self::CONTRACT_TAG . ')',
        );
        self::assertSame(count($codes), count(array_unique($codes)), 'FLOOR: the fixture carries duplicate codes');
    }

    /**
     * Keeps the FIXTURE honest about being a real copy of the generated tag
     * artifact — guards the obvious way to "fix" a red above: hand-writing the
     * fixture. The contracts generator always emits the marker below; a
     * hand-written stub will not. The PIN file names the tag this copy claims
     * to come from, and the hardcoded lockstep constant is the third witness.
     *
     * NOTE: `tests/fixtures/contracts/PIN` (singular) is the separate,
     * mcp-scopes-era pin file locked to its own tag by
     * {@see \Phlix\Hub\Tests\Unit\Mcp\McpScopesContractTest}; the error-code
     * vocabulary rolls independently and uses `error-codes.PIN`.
     */
    public function testTheVendoredArtifactIsTheGeneratedShape(): void
    {
        $raw = (string) file_get_contents(self::FIXTURE);

        self::assertStringContainsString('GENERATED by scripts/emit-error-codes.mjs', $raw);
        self::assertSame(self::CONTRACT_TAG, trim((string) file_get_contents(self::PIN)));
    }

    public function testEveryHubWireCodeLiteralIsRegistered(): void
    {
        $allowed = array_merge(self::fixtureCodes(), self::WIRE_LAW_WHITELIST);
        $violations = [];

        foreach (self::extractFileMap() as $literal => $files) {
            $code = (string) $literal;
            if (!in_array($code, $allowed, true)) {
                $violations[$code] = $files;
            }
        }

        self::assertSame(
            [],
            $violations,
            'Unregistered literals reached the hub `code` wire channel: '
            . json_encode($violations, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
            . '. Fix the CONTRACT first (phlix-contracts src/errors.ts, build, tag), then re-vendor '
            . 'tests/fixtures/contracts/error-codes.json + error-codes.PIN, or emit a registered code instead.',
        );
    }

    /**
     * Red-green self-proof: the scanner in
     * {@see self::testEveryHubWireCodeLiteralIsRegistered()} is pointed at a
     * synthetic in-memory snippet with a planted unknown code. If the scanner
     * ever stops seeing literals (regex rot, path bugs, empty iterator) this
     * test goes red FIRST, so the wire-law test above can never silently
     * degrade into `assertSame([], [])`. The real `src/` is never modified.
     */
    public function testTheWireLawScannerActuallyDetectsAnUnknownCode(): void
    {
        $snippet = '<?php' . "\n"
            . "return (new Response())->status(400)->json(['error' => 'x', 'code' => 'PLANTED_UNKNOWN_CODE']);\n";

        self::assertContains(
            'PLANTED_UNKNOWN_CODE',
            self::extractLiterals($snippet),
            'the scanner is decorative — it missed a planted literal on the `code` channel',
        );

        // Positive controls: all three sanctioned shapes must be extracted.
        $shapes = self::extractLiterals('<?php' . "\n"
            . "            \$a = ['error_code' => 'shape.one'];\n"
            . "            \$b = (new Response())->error(400, 'shape.two', 'x');\n"
            . "            \$c = Response::errorBody('shape.three', 'x');\n");
        sort($shapes);
        self::assertSame(
            ['shape.one', 'shape.three', 'shape.two'],
            $shapes,
            'the scanner lost sight of one of the three sanctioned code-channel shapes',
        );

        // The live scan must still see real traffic (guards SRC_DIR rot).
        $live = self::extractFileMap();
        self::assertGreaterThanOrEqual(
            self::LIVE_SCAN_FLOOR,
            count($live),
            'the src/ scan must find at least ' . self::LIVE_SCAN_FLOOR . ' distinct code-channel literals '
            . '(it found ' . count($live) . ') — a collapse here means the scanner broke, not that the hub got clean',
        );
    }

    /**
     * @return list<string>
     */
    private static function fixtureCodes(): array
    {
        $decoded = json_decode((string) file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, 'the vendored artifact must decode to an object');
        $raw = $decoded['codes'] ?? null;
        self::assertIsArray($raw, 'the fixture has no usable `codes` array');

        $codes = [];
        foreach ($raw as $code) {
            self::assertIsString($code, 'every fixture code must be a string');
            $codes[] = $code;
        }
        return $codes;
    }

    /**
     * Scan every .php file under src/ for code-channel literals.
     *
     * @return array<string, list<string>> literal => sorted unique file paths
     */
    private static function extractFileMap(): array
    {
        $dir = self::SRC_DIR;
        self::assertTrue(is_dir($dir), 'wire-law scan root missing: ' . $dir);

        $found = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace($dir . '/', '', (string) $file->getPathname());
            foreach (self::extractLiterals((string) file_get_contents((string) $file->getPathname())) as $literal) {
                $found[$literal][$path] = true;
            }
        }

        $map = [];
        foreach ($found as $literal => $paths) {
            $list = array_keys($paths);
            sort($list);
            $map[$literal] = $list;
        }
        ksort($map);
        return $map;
    }

    /**
     * The three sanctioned shapes that put a string on the wire's `code` field.
     *
     * @return list<string>
     */
    private static function extractLiterals(string $php): array
    {
        $patterns = [
            '/[\'"](?:code|error_code)[\'"]\s*=>\s*\'([A-Za-z0-9_.\-]+)\'/',
            '/->error\(\s*\d+\s*,\s*\'([A-Za-z0-9_.\-]+)\'/',
            '/Response::errorBody\(\s*\'([A-Za-z0-9_.\-]+)\'/',
        ];

        $found = [];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $php, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $found[] = $match[1];
                }
            }
        }
        return array_values(array_unique($found));
    }
}
