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

use function array_column;
use function array_diff;
use function array_keys;
use function array_merge;
use function array_pop;
use function array_splice;
use function array_unique;
use function array_values;
use function count;
use function file_get_contents;
use function in_array;
use function is_array;
use function is_dir;
use function json_decode;
use function json_encode;
use function ksort;
use function max;
use function preg_match;
use function preg_match_all;
use function sort;
use function str_replace;
use function stripcslashes;
use function substr;
use function token_get_all;
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
 * ## The variable-blind class is now EXECUTABLE, not manually claimed
 *
 * The three regexes above see literals only. A helper that writes
 * `'code' => $code` (or forwards its parameter into such a site) hides every
 * call-site argument from them. That blind class produced TWO real violations
 * that this file slept through: `FederationController::badRequest
 * ('invalid_leaf_hub_id')` (d483f29, caught by review at f30e8a7) and
 * `FrameEncoder::error(0, 'invalid_frame_type', …)` at
 * `Relay/ClientConnection.php` (fad2f2a-era, caught by the independent
 * re-review of f30e8a7 — the "sole violation" claim of the first sweep was
 * wrong precisely because its completeness relied on manual checking).
 * The blind class is therefore now policed by two executable tests instead
 * of prose:
 *  - `testEveryCodeRidingVariableSiteIsEnumerated()` censuses every
 *    `'code' => $var` / `'error_code' => $var` occurrence in `src/` and
 *    requires it to appear in {@see self::CODE_RIDING_VARIABLE_SITES},
 *    classified `swept:…` (call-site args checked) or `excluded:<written
 *    reason>` (not an error-code vocabulary: OAuth/claim codes, JSON-RPC
 *    integers, log/audit context, REJECTION_CODE_MAP sites pinned by
 *    AlexaRejectionCodeMapLawTest).
 *  - `testCodeRidingHelperCallSitesEmitRegisteredCodes()` token-walks every
 *    call site of each swept helper ({@see self::CODE_HELPER_SWEEPS}) and
 *    requires the literal at the code-carrying argument position to be a
 *    registered code.
 *
 * Residual limit (stated, not silently assumed): a helper whose code argument
 * is ITSELF fed from a runtime variable across a hop (e.g.
 * `AuthController::errorFrame` internals, or a map lookup like
 * `REJECTION_CODE_MAP`) cannot be literal-traced; the census forces such a
 * site to be enumerated with a reason, and each exclusion names the law test
 * or registry argument that covers it.
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

    /**
     * Census of EVERY `'code' => $var` / `'error_code' => $var` occurrence in
     * `src/` — the shapes the literal scanner cannot see. Key:
     * `<src-relative path>::<enclosing named method>` (an occurrence inside a
     * closure attributes to its enclosing named method); a NEW occurrence
     * makes the census test RED until it is enumerated here, classified:
     *  - `swept:<call>@<pos>` — the variable is a helper parameter whose
     *    code-carrying call sites are registry-checked by
     *    {@see self::CODE_HELPER_SWEEPS};
     *  - `excluded:<written reason>` — the `code` field is not a member of
     *    the error-code vocabulary (RFC 6749 authorization codes, claim
     *    pairing codes, JSON-RPC integer codes, log/audit context) or is
     *    governed by a dedicated law test. The reason must say which.
     *
     * @var array<string, string>
     */
    private const array CODE_RIDING_VARIABLE_SITES = [
        // — swept helper parameter sites (wire `code` written through a variable) —
        'Http/Response.php::error' => 'swept:error@2',
        'Http/Response.php::errorBody' => 'swept:errorBody@1',
        'Relay/FrameEncoder.php::error' => 'swept:error@2',
        'Http/Controllers/FederationController.php::badRequest' => 'swept:badRequest@1',
        'Http/Controllers/AdminUserController.php::badRequest' => 'swept:badRequest@2',
        'Http/Controllers/McpController.php::unauthorized' => 'swept:unauthorized@1',
        'Http/Middleware/AuthMiddleware.php::challenge' => 'swept:challenge@2',
        // — excluded, with reasons —
        'Http/Middleware/AlexaSignatureMiddleware.php::reject' =>
            'excluded: the emitted code flows through REJECTION_CODE_MAP (registered alexa.* dotted'
            . ' twins landed in the W3 hub emit wave); map values AND the whole frame shape are'
            . ' pinned by \Phlix\Hub\Tests\Unit\Http\Middleware\AlexaRejectionCodeMapLawTest',
        'Hub/ClaimRequestHandler.php::handleClaimCode' =>
            'excluded: `code` is the user-facing CLAIM PAIRING CODE (redeemable secret echoed back'
            . ' to the claiming client), not a member of the error-code vocabulary',
        'OAuth/AuthorizationCodeService.php::mint' =>
            'excluded: RFC 6749 §4.1.2 authorization code returned by the mint record —'
            . ' registry-excluded by design (see class docblock)',
        'Http/Controllers/OAuthController.php::consent' =>
            'excluded: RFC 6749 authorization code in the consent redirect query params —'
            . ' registry-excluded by design (see class docblock)',
        'Federation/FederationPeerManager.php::establishConnection' =>
            'excluded: Workerman onError($conn, int $code, $reason) callback context — the `code` is'
            . ' a numeric socket error written to the LOG, never to a wire body',
        'Alexa/AuditLogAlexaRejectionAuditor.php::record' =>
            'excluded: audit-log context column (operator-facing rejection record), not an HTTP/relay'
            . ' wire body; the wire emit is AlexaSignatureMiddleware::reject, policed by its law test',
        'Mcp/JsonRpc.php::error' =>
            'excluded: JSON-RPC 2.0 numeric error code (int $code parameter, spec-reserved negative'
            . ' integers) — registry-excluded by design (see class docblock)',
        'Mcp/McpRequestValidator.php::error' =>
            'excluded: JSON-RPC error-object constructor (int $code) — registry-excluded by design;'
            . ' its call sites carry integer constants at position 1, human text at position 2',
    ];

    /**
     * Helper call-site sweeps: every call `<recv>-><call>(…)` /
     * `<Recv>::<call>(…)` visible to the walker, where the argument at each
     * listed 1-based position lands on the wire's `code` field. Literals found
     * there must be registered. `file` scopes a private helper to its
     * declaring file (calls are lexically inside); `staticReceivers` gates
     * `::`-form calls to the named classes so same-position parameters of
     * unrelated `error()` helpers (JSON-RPC messages) cannot masquerade as
     * wire codes; instance `->` calls are always eligible within scope.
     *
     * @var list<array{file: string|null, call: string, positions: list<int>, staticReceivers: list<string>|null}>
     */
    private const array CODE_HELPER_SWEEPS = [
        // Response->error(status, CODE, message, extra) — instance-only in practice.
        ['file' => null, 'call' => 'error', 'positions' => [2], 'staticReceivers' => ['FrameEncoder']],
        // Response::errorBody(CODE, message) — relay-frame JSON bodies.
        ['file' => null, 'call' => 'errorBody', 'positions' => [1], 'staticReceivers' => ['Response']],
        [
            'file' => 'Http/Controllers/FederationController.php',
            'call' => 'badRequest',
            'positions' => [1],
            'staticReceivers' => null,
        ],
        [
            'file' => 'Http/Controllers/AdminUserController.php',
            'call' => 'badRequest',
            'positions' => [2],
            'staticReceivers' => null,
        ],
        [
            'file' => 'Http/Controllers/McpController.php',
            'call' => 'unauthorized',
            'positions' => [1],
            'staticReceivers' => null,
        ],
        [
            'file' => 'Http/Middleware/AuthMiddleware.php',
            'call' => 'challenge',
            'positions' => [2],
            'staticReceivers' => null,
        ],
        // AuthController::errorFrame(status, CODE, message) — forwards into
        // Response->error() internals (variable hop), so its CALL-SITE literals
        // are swept directly rather than relying on the inner ->error hop.
        [
            'file' => 'Http/Controllers/AuthController.php',
            'call' => 'errorFrame',
            'positions' => [2],
            'staticReceivers' => null,
        ],
    ];

    /**
     * Anti-decay floor for the helper call-site sweep: the live sweep must
     * extract at least this many registered literals across src/. If the
     * walker breaks (token handling, scope filter) the count collapses and
     * this fires BEFORE the membership assertion can pass vacuously.
     */
    private const int HELPER_SCAN_FLOOR = 40;

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
     * The census half of the blind-class sweep: every `'code' => $var` /
     * `'error_code' => $var` occurrence in `src/` (token-level, so docblock
     * prose mentioning the shape never counts) must be enumerated in
     * {@see self::CODE_RIDING_VARIABLE_SITES} — and each `swept:`
     * classification must name a call-site sweep that actually exists.
     * Set-equality is asserted in BOTH directions: a removed variable site
     * prunes its stale entry, a new one goes red until it is enumerated with
     * a written reason.
     */
    public function testEveryCodeRidingVariableSiteIsEnumerated(): void
    {
        $found = [];
        foreach (self::srcFiles() as $rel => $abs) {
            foreach (self::extractCodeRidingSites((string) file_get_contents($abs)) as $method) {
                $found[$rel . '::' . $method] = true;
            }
        }

        $actual = array_keys($found);
        sort($actual);
        $expected = array_keys(self::CODE_RIDING_VARIABLE_SITES);
        sort($expected);

        self::assertSame(
            $expected,
            $actual,
            'Census drift on the variable code-channel (the shape the literal scanner is blind to).'
            . ' Added: ' . json_encode(array_values(array_diff($actual, $expected)), JSON_UNESCAPED_SLASHES)
            . ' — enumerate with swept:/excluded: + reason in CODE_RIDING_VARIABLE_SITES.'
            . ' Removed: ' . json_encode(array_values(array_diff($expected, $actual)), JSON_UNESCAPED_SLASHES)
            . ' — prune the stale entry.',
        );

        $sweeps = [];
        foreach (self::CODE_HELPER_SWEEPS as $sweep) {
            foreach ($sweep['positions'] as $position) {
                $sweeps[$sweep['call'] . '@' . $position] = true;
            }
        }

        foreach (self::CODE_RIDING_VARIABLE_SITES as $site => $classification) {
            if (preg_match('/^swept:([A-Za-z]+)@(\d+)$/', $classification, $m) === 1) {
                self::assertArrayHasKey(
                    $m[1] . '@' . $m[2],
                    $sweeps,
                    'Census site ' . $site . ' claims to be swept by ' . $classification
                    . ', but no such CODE_HELPER_SWEEPS entry exists.',
                );
                continue;
            }

            self::assertSame(
                1,
                preg_match('/^excluded:.{40,}/su', $classification),
                'Census site ' . $site . ' must be `swept:<call>@<pos>` or `excluded:` + an'
                . ' explanatory reason of at least 40 characters (whitelist doctrine: reasons, not bare allows).',
            );
        }
    }

    /**
     * The call-site half of the blind-class sweep: for each helper in
     * {@see self::CODE_HELPER_SWEEPS}, every string literal sitting at a
     * code-carrying argument position of a call to it in `src/` must be a
     * registered code. This is the executable version of the registry checks
     * that f30e8a7 performed by hand — and unlike prose, it fails on the next
     * `badRequest('some_new_invention')` or `FrameEncoder::error(0, 'x_y')`.
     */
    public function testCodeRidingHelperCallSitesEmitRegisteredCodes(): void
    {
        $allowed = self::fixtureCodes();
        $violations = [];
        $extracted = 0;

        foreach (self::CODE_HELPER_SWEEPS as $sweep) {
            $files = $sweep['file'] === null
                ? self::srcFiles()
                : [$sweep['file'] => self::SRC_DIR . '/' . $sweep['file']];

            foreach ($files as $rel => $abs) {
                $hits = self::extractCallSiteCodeLiterals(
                    (string) file_get_contents($abs),
                    $sweep['call'],
                    $sweep['positions'],
                    $sweep['staticReceivers'],
                );

                foreach ($hits as $hit) {
                    $extracted++;
                    if (!in_array($hit['code'], $allowed, true)) {
                        $violations[] = $rel . ':' . $hit['line'] . ' -> ' . $sweep['call'] . '(…) carries '
                            . "'" . $hit['code'] . "'";
                    }
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            'Unregistered literals reached the hub `code` wire channel through a variable-riding'
            . ' helper (invisible to the three-shape literal scan): '
            . json_encode($violations, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
            . '. Fix the CONTRACT first (phlix-contracts src/errors.ts, build, tag), then re-vendor'
            . ' tests/fixtures/contracts/error-codes.json + error-codes.PIN, or emit a registered'
            . ' code with the condition in the human field (invalid_request precedent: f30e8a7; '
            . ' relay ERROR-frame precedent: the ClientConnection re-anchor).',
        );

        self::assertGreaterThanOrEqual(
            self::HELPER_SCAN_FLOOR,
            $extracted,
            'the helper call-site sweep must extract at least ' . self::HELPER_SCAN_FLOOR
            . ' code-position literals from src/ (it found ' . $extracted
            . ') — a collapse here means the token walker broke, not that the hub got clean',
        );
    }

    /**
     * Red-green self-proof for the sweep: both HISTORICAL violations — the
     * d483f29 `badRequest('invalid_leaf_hub_id')` and the fad2f2a-era
     * `FrameEncoder::error(0, 'invalid_frame_type', …)` — are re-planted as
     * in-memory snippets and must be caught by the very walker the live test
     * uses. The real `src/` is never modified. If either shape ever slips out
     * of the sweep's view (regex/token rot), this test goes red first.
     */
    public function testTheHelperSweepCatchesBothHistoricalViolations(): void
    {
        // Neither planted code may be registered — otherwise the leg proves nothing.
        $allowed = self::fixtureCodes();
        self::assertNotContains('invalid_frame_type', $allowed);
        self::assertNotContains('invalid_leaf_hub_id', $allowed);

        // Leg 1: the FrameEncoder::error blind class (missed by the f30e8a7 sweep).
        $frameLeg = self::extractCallSiteCodeLiterals(
            '<?php' . "\n"
            . "\$raw = FrameEncoder::error(0, 'invalid_frame_type', 'Unexpected frame type: ERROR');\n",
            'error',
            [2],
            ['FrameEncoder'],
        );
        self::assertContains(
            'invalid_frame_type',
            array_column($frameLeg, 'code'),
            'the sweep lost sight of the FrameEncoder::error(0, CODE, …) shape it was built for',
        );

        // Leg 2: the FederationController::badRequest blind class (d483f29).
        $badRequestLeg = self::extractCallSiteCodeLiterals(
            '<?php' . "\n"
            . "class FederationController {\n"
            . "    public function x(): void { \$this->badRequest('invalid_leaf_hub_id'); }\n"
            . "    private function badRequest(string \$code, string \$reason = ''): Response {}\n"
            . "}\n",
            'badRequest',
            [1],
            null,
        );
        self::assertContains(
            'invalid_leaf_hub_id',
            array_column($badRequestLeg, 'code'),
            'the sweep lost sight of the $this->badRequest(CODE, …) shape it was built for',
        );
        // The helper DEFINITION line above must not register as a call site.
        self::assertCount(
            1,
            $badRequestLeg,
            'a badRequest() definition was mistaken for a call site — definitions must be skipped',
        );
    }

    /**
     * @return array<string, string> src-relative path => absolute path (ksorted)
     */
    private static function srcFiles(): array
    {
        $dir = self::SRC_DIR;
        self::assertTrue(is_dir($dir), 'helper sweep root missing: ' . $dir);

        $map = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $abs = (string) $file->getPathname();
            $map[str_replace($dir . '/', '', $abs)] = $abs;
        }
        ksort($map);
        return $map;
    }

    /**
     * Token-level census of `'code' => $var` occurrences, each attributed to
     * its enclosing NAMED method (closures inherit). Comment/docblock prose
     * cannot match because it never appears as separate string/arrow tokens.
     *
     * @return list<string> enclosing-method names, one per occurrence
     */
    private static function extractCodeRidingSites(string $php): array
    {
        $tokens = token_get_all($php);
        $sites = [];
        /** @var list<array{name: string|null, depth: int|null}> $functions */
        $functions = [];
        $brace = 0;
        $paren = 0;

        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && $token[0] === T_FUNCTION) {
                $name = self::functionNameAfter($tokens, $i);
                $functions[] = ['name' => $name, 'depth' => null];
                continue;
            }

            $isOpenBrace = $token === '{'
                || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true));
            if ($isOpenBrace) {
                $brace++;
                if ($paren === 0) {
                    for ($k = count($functions) - 1; $k >= 0; $k--) {
                        if ($functions[$k]['depth'] === null) {
                            $functions[$k]['depth'] = $brace;
                            break;
                        }
                    }
                }
                continue;
            }

            if ($token === '}') {
                for ($k = count($functions) - 1; $k >= 0; $k--) {
                    if ($functions[$k]['depth'] === $brace) {
                        array_pop($functions);
                    }
                }
                $brace--;
                continue;
            }

            if ($token === '(' || $token === '[') {
                $paren += $token === '(' ? 1 : 0;
                continue;
            }
            if ($token === ')') {
                $paren = max(0, $paren - 1);
                continue;
            }

            if (
                is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING
                && in_array($token[1], ["'code'", "'error_code'", '"code"', '"error_code"'], true)
            ) {
                $arrow = self::nextSignificant($tokens, $i);
                if ($arrow !== null && is_array($tokens[$arrow]) && $tokens[$arrow][0] === T_DOUBLE_ARROW) {
                    $value = self::nextSignificant($tokens, $arrow);
                    if ($value !== null && is_array($tokens[$value]) && $tokens[$value][0] === T_VARIABLE) {
                        $enclosing = '{top-level}';
                        for ($k = count($functions) - 1; $k >= 0; $k--) {
                            if ($functions[$k]['name'] !== null) {
                                $enclosing = (string) $functions[$k]['name'];
                                break;
                            }
                        }
                        $sites[] = $enclosing;
                    }
                }
                continue;
            }

            // Interface/abstract signatures end at ';' without ever opening a
            // body — drop pending entries so later real bodies attribute right.
            if ($token === ';' && $paren === 0) {
                for ($k = count($functions) - 1; $k >= 0; $k--) {
                    if ($functions[$k]['depth'] === null) {
                        array_splice($functions, $k, 1);
                    }
                }
            }
        }

        return $sites;
    }

    /**
     * Walk `->name(…)` / `::name(…)` call sites and return the code-shaped
     * single-string-literal arguments at the requested 1-based positions.
     * Definitions (T_FUNCTION-preceded) are skipped; `::` receivers must be
     * whitelisted when `staticReceivers` is given, so an unrelated helper's
     * same-position human text cannot impersonate a wire code; instance
     * `->` calls are always eligible. Literals that are not code-shaped
     * (spaces, punctuation outside `[A-Za-z0-9_.-]`) are human text.
     *
     * @param list<int>          $positions
     * @param list<string>|null  $staticReceivers
     *
     * @return list<array{line: int, code: string}>
     */
    private static function extractCallSiteCodeLiterals(
        string $php,
        string $call,
        array $positions,
        ?array $staticReceivers
    ): array {
        $tokens = token_get_all($php);
        $hits = [];

        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== $call) {
                continue;
            }

            $prev = self::prevSignificant($tokens, $i);
            if ($prev === null) {
                continue;
            }
            $prevToken = $tokens[$prev];
            $isInstance = is_array($prevToken)
                && in_array($prevToken[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true);
            $isStatic = is_array($prevToken) && $prevToken[0] === T_DOUBLE_COLON;

            if ($isStatic && $staticReceivers !== null) {
                $receiver = self::prevSignificant($tokens, $prev);
                if (
                    $receiver === null || !is_array($tokens[$receiver])
                    || $tokens[$receiver][0] !== T_STRING
                    || !in_array($tokens[$receiver][1], $staticReceivers, true)
                ) {
                    continue;
                }
            } elseif (!$isInstance && !$isStatic) {
                continue;
            }

            $open = self::nextSignificant($tokens, $i);
            if ($open === null || $tokens[$open] !== '(') {
                continue;
            }

            foreach (self::callSiteLiteralArgs($tokens, $open, $positions) as $position => $literal) {
                if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\-]*$/', $literal['value']) === 1) {
                    $hits[] = ['line' => $literal['line'], 'code' => $literal['value']];
                }
            }
        }

        return $hits;
    }

    /**
     * Collect single-T_CONSTANT_ENCAPSED_STRING arguments at the requested
     * positions of the call whose '(' sits at $open.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens     full token list
     * @param list<int>                                     $positions  1-based argument positions
     *
     * @return array<int, array{line: int, value: string}> position => literal
     */
    private static function callSiteLiteralArgs(array $tokens, int $open, array $positions): array
    {
        $found = [];
        $depth = 0;
        $argIndex = 1; // positions are 1-based
        $argTokens = [];

        for ($i = $open, $n = count($tokens); $i < $n; $i++) {
            $token = $tokens[$i];
            $text = is_array($token) ? $token[1] : $token;

            if (
                $text === '(' || $text === '[' || $text === '{'
                || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))
            ) {
                $depth++;
                if ($depth === 1) {
                    continue; // the call's own '('
                }
            } elseif ($text === ')' || $text === ']' || $text === '}') {
                $depth--;
                if ($depth === 0) {
                    if (in_array($argIndex, $positions, true) && $argTokens !== []) {
                        $literal = self::soleStringLiteral($argTokens);
                        if ($literal !== null) {
                            $found[$argIndex] = $literal;
                        }
                    }
                    break;
                }
            } elseif ($text === ',' && $depth === 1) {
                if (in_array($argIndex, $positions, true) && $argTokens !== []) {
                    $literal = self::soleStringLiteral($argTokens);
                    if ($literal !== null) {
                        $found[$argIndex] = $literal;
                    }
                }
                $argIndex++;
                $argTokens = [];
                continue;
            }

            if ($depth >= 1) {
                $argTokens[] = $token;
            }
        }

        return $found;
    }

    /**
     * The argument's literal value iff it consists of exactly one
     * T_CONSTANT_ENCAPSED_STRING (ignoring whitespace/comments); concatenation,
     * interpolation and variables are NOT code-channel literals here.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $argTokens raw tokens for one argument
     *
     * @return array{line: int, value: string}|null
     */
    private static function soleStringLiteral(array $argTokens): ?array
    {
        $significant = [];
        foreach ($argTokens as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $significant[] = $token;
        }

        if (count($significant) !== 1) {
            return null;
        }

        $token = $significant[0];
        if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        $raw = $token[1];
        $quote = $raw[0];
        $inner = substr($raw, 1, -1);
        $value = $quote === "'"
            ? str_replace(["\\'", '\\\\'], ["'", '\\'], $inner)
            : stripcslashes($inner);

        return ['line' => $token[2], 'value' => $value];
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return int|null index of next non-whitespace/non-comment token
     */
    private static function nextSignificant(array $tokens, int $from): ?int
    {
        for ($i = $from + 1, $n = count($tokens); $i < $n; $i++) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $i;
        }
        return null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return int|null index of previous non-whitespace/non-comment token
     */
    private static function prevSignificant(array $tokens, int $from): ?int
    {
        for ($i = $from - 1; $i >= 0; $i--) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $i;
        }
        return null;
    }

    /**
     * The declared name following the `function` keyword at $fnIndex, or null
     * for closures (`function (` / `function (&`).
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function functionNameAfter(array $tokens, int $fnIndex): ?string
    {
        $i = self::nextSignificant($tokens, $fnIndex);
        if ($i === null) {
            return null;
        }
        if (is_array($tokens[$i]) && $tokens[$i][0] === T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG) {
            $i = self::nextSignificant($tokens, $i);
            if ($i === null) {
                return null;
            }
        }
        return is_array($tokens[$i]) && $tokens[$i][0] === T_STRING ? $tokens[$i][1] : null;
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
