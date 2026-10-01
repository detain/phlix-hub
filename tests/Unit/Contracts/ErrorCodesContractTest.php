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
 * Pins the hub's error-code vocabulary to `@phlix/contracts` v0.5.3 and
 * enforces the hub-side wire law: every literal the hub places on a
 * `code`/`error_code` wire field is a REGISTERED stable code.
 *
 * ## Why this file exists
 *
 * The W3 emit-wave doctrine is error-code-first: stable registered codes on
 * the wire, English text as debug fallback. The SSOT vocabulary is
 * `@phlix/contracts` `dist/error-codes.json` (204 codes / 37 domains at
 * v0.5.3). This test is the hub half of the vendored-fixture law modelled on
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
 * The blind class is therefore now policed by three executable tests instead
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
 *  - `testEveryOpaqueCodeFeedIntoSweptHelpersIsEnumerated()` closes the gap
 *    BETWEEN those two: a swept helper called with a non-literal at its code
 *    position (a variable hop like `AuthController::errorFrame`'s internal
 *    `->error(…, $code, …)`, or a map lookup like `REJECTION_CODE_MAP`) is
 *    invisible to the literal sweep, so this census enumerates every such
 *    feed in {@see self::OPAQUE_CODE_FEEDS} with a `hop:` reason naming the
 *    law or registry argument that keeps the runtime value registered.
 *
 * Residual limits (stated, not silently assumed):
 *  - Dynamic dispatch bypasses the token walkers: `$fn = 'error';
 *    $svc->$fn(…)` (or `Cls::$fn(…)`) never presents the swept helper NAME as
 *    a T_STRING at the gated position, so neither the literal sweep nor the
 *    opaque-feed census sees the call — an inherent limit of static token
 *    analysis, caught only by review.
 *  - Both censuses key by SHAPE, not by occurrence: a new path, method,
 *    helper or argument position produces a NEW key and goes RED, but a
 *    repeat occurrence of an already-enumerated shape inside the same key
 *    (a second `'code' => $var` in an already-listed `file::method`, or a
 *    second opaque feed at the same `file::method::call@pos`) collapses into
 *    that key and passes silently.
 *  - Enumeration reasons stay human-authored: the censuses force a new shape
 *    to be WRITTEN DOWN with a reason; they cannot prove the reason is true.
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
     * audit, 202 at v0.5.1 and 204 at v0.5.3. Asserted on the fixture BEFORE
     * any comparison.
     * If the registry ever legitimately drops below this, this constant is
     * edited deliberately, in the same commit, with the reason stated —
     * that edit is what makes the shrink visible.
     */
    private const int CODE_FLOOR = 147;

    /**
     * Minimum distinct literals the live src/ scan must see. Guards the wire
     * law against path/iterator rot: a scanner that silently finds nothing
     * would otherwise pass by comparing [] to []. The scan currently sees
     * 79 distinct registered literals.
     */
    private const int LIVE_SCAN_FLOOR = 40;

    /**
     * Hardcoded tag lockstep (mcp-scopes law): bump together with
     * `tests/fixtures/contracts/error-codes.PIN` and the re-vendored fixture
     * in one commit.
     */
    private const string CONTRACT_TAG = 'v0.5.3';

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
     * closure attributes to its enclosing named method). Keys are SETS, not
     * counts: a NEW KEY (new path or new method) makes the census test RED
     * until it is enumerated here — but a repeat occurrence of the shape
     * inside an already-enumerated key collapses into that key and passes
     * silently (stated limit, see class docblock). Classified:
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
        // Response->error(status, CODE, message, extra) — instance-only in
        // practice; 'Response' is whitelisted for the `::` form anyway so a
        // future static literal call cannot impersonate its way past the sweep.
        ['file' => null, 'call' => 'error', 'positions' => [2], 'staticReceivers' => ['FrameEncoder', 'Response']],
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

    /**
     * Census of every NON-LITERAL argument at a code-carrying position of a
     * swept-helper call in `src/` — the feeds the literal sweep structurally
     * cannot trace. Key:
     * `<src-relative path>::<enclosing named method>::<call>@<pos>`; value:
     * `hop:` + a reason of at least 40 characters naming the law test or
     * registry argument that keeps the runtime value registered. Same
     * set-key doctrine as the variable census: a new path/method/helper/
     * position is a new key and goes RED; a repeat feed inside an
     * enumerated key collapses silently. Excluded from counting BY
     * CONSTRUCTION:
     *  - sole code-shaped string literals — registry territory, policed by
     *    the literal sweep itself;
     *  - array literals at the code position — structurally impossible on
     *    the wire: every swept code parameter is typed `string` and all
     *    src/ files declare strict_types=1, so the ~50 PSR-3 logger
     *    `$logger->error($msg, ['context' => …])` calls that land at
     *    `error@2` cannot put their array on a code field without a
     *    TypeError long before any wire;
     *  - empty significant-token slots (trailing-comma call artifacts).
     *
     * @var array<string, string>
     */
    private const array OPAQUE_CODE_FEEDS = [
        'Http/Controllers/AuthController.php::errorFrame::error@2' =>
            'hop: errorFrame(int $status, string $code, string $message) forwards its own'
            . ' string-typed $code parameter into (new Response())->error(...) — the inner'
            . ' variable hop inherits the direct call-site sweep at errorFrame@2 above',
        'Http/Middleware/AlexaSignatureMiddleware.php::reject::error@2' =>
            'hop: reject() emits (new Response())->error(400, $wireCode, $code) where $wireCode'
            . ' is a REJECTION_CODE_MAP value — registered dotted twins AND the whole frame'
            . ' shape are pinned by \\Phlix\\Hub\\Tests\\Unit\\Http\\Middleware\\AlexaRejectionCodeMapLawTest',
    ];

    /**
     * Anti-vacuity floor for the opaque-feed census: the live src/ walk must
     * keep finding at least this many enumerated feeds. A walker collapse
     * (gate, stack, classifier) drops the count to 0 and this fires BEFORE
     * the set-equality assertion could pass vacuously against an emptied
     * view. If both real hops were ever legitimately traced away, this
     * constant is edited deliberately, in the same commit, with the reason.
     */
    private const int OPAQUE_FEED_FLOOR = 1;

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
            . ' codes (204 as of ' . self::CONTRACT_TAG . ')',
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
     * The opaque-feed census: every call to a swept helper whose code-position
     * argument is NOT a traceable literal (variable, map lookup, call, concat,
     * human text) must be enumerated in {@see self::OPAQUE_CODE_FEEDS} with a
     * `hop:` reason. This is the executable closing of the residual limit the
     * first sweep left as prose — the over-claim was that such feeds were
     * "forced to be enumerated" when in fact nothing saw them; now a new feed
     * really does go RED. Floor first (anti-vacuity), then set-equality in
     * BOTH directions: an added key must be enumerated, a removed key pruned.
     */
    public function testEveryOpaqueCodeFeedIntoSweptHelpersIsEnumerated(): void
    {
        $found = [];
        foreach (self::CODE_HELPER_SWEEPS as $sweep) {
            $files = $sweep['file'] === null
                ? self::srcFiles()
                : [$sweep['file'] => self::SRC_DIR . '/' . $sweep['file']];

            foreach ($files as $rel => $abs) {
                $feeds = self::extractOpaqueCodeFeeds(
                    (string) file_get_contents($abs),
                    $sweep['call'],
                    $sweep['positions'],
                    $sweep['staticReceivers'],
                );
                foreach ($feeds as $feed) {
                    $found[$rel . '::' . $feed['method'] . '::' . $sweep['call'] . '@' . $feed['position']] = true;
                }
            }
        }

        $actual = array_keys($found);
        sort($actual);

        self::assertGreaterThanOrEqual(
            self::OPAQUE_FEED_FLOOR,
            count($actual),
            'the opaque-feed census must keep finding at least ' . self::OPAQUE_FEED_FLOOR
            . ' enumerated non-literal feeds in src/ (it found ' . count($actual)
            . ') — a collapse here means the walker broke, not that the hub got clean',
        );

        $expected = array_keys(self::OPAQUE_CODE_FEEDS);
        sort($expected);

        self::assertSame(
            $expected,
            $actual,
            'Census drift on opaque (non-literal) feeds into swept helpers — the class of site'
            . ' the literal sweep structurally cannot trace. Added: '
            . json_encode(array_values(array_diff($actual, $expected)), JSON_UNESCAPED_SLASHES)
            . ' — enumerate in OPAQUE_CODE_FEEDS with a `hop:` reason naming the law or registry'
            . ' argument covering the value. Removed: '
            . json_encode(array_values(array_diff($expected, $actual)), JSON_UNESCAPED_SLASHES)
            . ' — prune the stale entry.',
        );

        foreach (self::OPAQUE_CODE_FEEDS as $site => $reason) {
            self::assertSame(
                1,
                preg_match('/^hop:.{40,}/su', $reason),
                'Opaque feed ' . $site . ' must be `hop:` + an explanatory reason of at least 40'
                . ' characters (census doctrine: reasons, not bare allows).',
            );
        }
    }

    /**
     * Red-green self-proof for the opaque-feed walker: planted variable feeds
     * and forward hops must be counted, while every exclusion class (code-
     * shaped literal, logger array context, trailing-comma slot, definition)
     * must NOT be. Even a sole string literal that is not code-shaped counts —
     * human text at a code position currently escapes every other sweep. The
     * real `src/` is never modified.
     */
    public function testTheOpaqueFeedCensusCatchesPlantedFeeds(): void
    {
        $gates = ['FrameEncoder', 'Response'];

        // Leg 1: the FrameEncoder::error blind class, now fed by a VARIABLE
        // instead of a literal — exactly the shape the literal sweep cannot see.
        $varFeed = self::extractOpaqueCodeFeeds(
            '<?php' . "\n"
            . "if (\$kind === 'x') {\n"
            . "    \$guessedCode = parse(\$frame);\n"
            . "    \$raw = FrameEncoder::error(0, \$guessedCode, 'Unexpected frame type');\n"
            . "}\n",
            'error',
            [2],
            $gates,
        );
        self::assertCount(1, $varFeed, 'a variable fed to FrameEncoder::error position 2 must count as opaque');
        self::assertSame('{top-level}', $varFeed[0]['method']);
        self::assertSame(2, $varFeed[0]['position']);

        // Leg 1b: the instance-forward hop (the AuthController::errorFrame shape).
        $hopFeed = self::extractOpaqueCodeFeeds(
            '<?php' . "\n"
            . "class C {\n"
            . "    private function errorFrame(int \$status, string \$code, string \$message): Response\n"
            . "    {\n"
            . "        return (new Response())->error(\$status, \$code, \$message);\n"
            . "    }\n"
            . "}\n",
            'error',
            [2],
            $gates,
        );
        self::assertCount(1, $hopFeed, 'a parameter forwarded into ->error(…) must count as opaque');
        self::assertSame('errorFrame', $hopFeed[0]['method']);

        // Leg 2: a SOLE string literal that is not code-shaped is census
        // material too — human text at a code position escapes every sweep.
        $humanText = self::extractOpaqueCodeFeeds(
            '<?php' . "\n"
            . "\$raw = FrameEncoder::error(0, 'Something bad happened', 'x');\n",
            'error',
            [2],
            $gates,
        );
        self::assertCount(1, $humanText, 'a non-code-shaped literal at a code position must count as opaque');

        // Control A: sole code-shaped literal — registry territory, never census.
        self::assertCount(
            0,
            self::extractOpaqueCodeFeeds(
                '<?php' . "\n" . "\$raw = FrameEncoder::error(0, 'invalid_frame_type', 'x');\n",
                'error',
                [2],
                $gates,
            ),
            'a code-shaped literal must not appear in the opaque census',
        );

        // Control B: PSR-3 logger call whose context array can never land on a
        // typed-string code parameter under strict_types — excluded by design.
        self::assertCount(
            0,
            self::extractOpaqueCodeFeeds(
                '<?php' . "\n" . "\$logger->error('boom', ['error' => \$e->getMessage()]);\n",
                'error',
                [2],
                $gates,
            ),
            'an array-literal context argument must not be census material',
        );

        // Control C: trailing-comma single-arg call — the post-comma slot is
        // empty and must not be mistaken for an opaque feed.
        self::assertCount(
            0,
            self::extractOpaqueCodeFeeds(
                "<?php\n\$logger->error(\n    'boom',\n);\n",
                'error',
                [2],
                $gates,
            ),
            'a trailing-comma empty slot must not be counted as an opaque feed',
        );

        // Control D: the definition itself must not register as a call site.
        self::assertCount(
            0,
            self::extractOpaqueCodeFeeds(
                '<?php' . "\n"
                . "class R {\n"
                . "    public function error(int \$status, string \$code, string \$message): Response {}\n"
                . "}\n",
                'error',
                [2],
                $gates,
            ),
            'an error() definition must not be mistaken for a call site',
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
                        $sites[] = self::enclosingNamedMethod($functions);
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

            $open = self::sweptCallSiteOpen($tokens, $i, $staticReceivers);
            if ($open === null) {
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
     * The call-site gate shared by the literal sweep and the opaque-feed
     * census so the two can never drift apart: given the index of a T_STRING
     * whose text equals a swept helper name, return the index of the call's
     * '(' — or null when the site is not eligible. Preceded by `->`/`?->` the
     * call is always eligible; preceded by `::` it needs a whitelisted T_STRING
     * receiver when `$staticReceivers` is given, so an unrelated helper's
     * same-position human text cannot impersonate a wire code; definitions
     * (T_FUNCTION-preceded) and bare function calls fail the gate.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param list<string>|null                             $staticReceivers
     */
    private static function sweptCallSiteOpen(array $tokens, int $i, ?array $staticReceivers): ?int
    {
        $prev = self::prevSignificant($tokens, $i);
        if ($prev === null) {
            return null;
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
                return null;
            }
        } elseif (!$isInstance && !$isStatic) {
            return null;
        }

        $open = self::nextSignificant($tokens, $i);
        if ($open === null || $tokens[$open] !== '(') {
            return null;
        }

        return $open;
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
        foreach (self::collectCallSiteArgs($tokens, $open, $positions) as $argIndex => $argTokens) {
            $literal = self::soleStringLiteral($argTokens);
            if ($literal !== null) {
                $found[$argIndex] = $literal;
            }
        }
        return $found;
    }

    /**
     * Slice the raw token lists of the requested 1-based argument positions of
     * the call whose '(' sits at $open. Depth-tracked: commas at the call's
     * own depth split arguments; nested parens/brackets/braces — including
     * string interpolation — travel inside the argument that contains them.
     * Empty slots (trailing commas) are never emitted.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param list<int>                                     $positions
     *
     * @return array<int, list<array{0: int, 1: string, 2: int}|string>> position => raw arg tokens
     */
    private static function collectCallSiteArgs(array $tokens, int $open, array $positions): array
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
                        $found[$argIndex] = $argTokens;
                    }
                    break;
                }
            } elseif ($text === ',' && $depth === 1) {
                if (in_array($argIndex, $positions, true) && $argTokens !== []) {
                    $found[$argIndex] = $argTokens;
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
     * Walk the same function/brace/paren stack as
     * {@see self::extractCodeRidingSites()} and the same call-site gate as
     * {@see self::extractCallSiteCodeLiterals()}, returning every gated call
     * to $call whose argument at a requested position is opaque
     * ({@see self::isOpaqueCodeArg()}), attributed to its enclosing NAMED
     * method (closures inherit; interface signatures ending at ';' without
     * ever opening a body are pruned so later real bodies attribute right).
     *
     * @param list<int>         $positions
     * @param list<string>|null $staticReceivers
     *
     * @return list<array{method: string, position: int, line: int}>
     */
    private static function extractOpaqueCodeFeeds(
        string $php,
        string $call,
        array $positions,
        ?array $staticReceivers
    ): array {
        $tokens = token_get_all($php);
        $feeds = [];
        /** @var list<array{name: string|null, depth: int|null}> $functions */
        $functions = [];
        $brace = 0;
        $paren = 0;

        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && $token[0] === T_FUNCTION) {
                $functions[] = ['name' => self::functionNameAfter($tokens, $i), 'depth' => null];
                continue;
            }

            // Call-site probe. The lookahead is read-only; the main stack
            // tracking below still walks through the argument tokens normally,
            // so nested bodies and parens keep the function stack honest.
            if (is_array($token) && $token[0] === T_STRING && $token[1] === $call) {
                $open = self::sweptCallSiteOpen($tokens, $i, $staticReceivers);
                if ($open !== null) {
                    foreach (self::collectCallSiteArgs($tokens, $open, $positions) as $position => $argTokens) {
                        if (self::isOpaqueCodeArg($argTokens)) {
                            $feeds[] = [
                                'method' => self::enclosingNamedMethod($functions),
                                'position' => $position,
                                'line' => $token[2],
                            ];
                        }
                    }
                }
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

        return $feeds;
    }

    /**
     * Is the argument at a code position OPAQUE to the literal sweep? NOT
     * opaque — by construction, never census material:
     *  - a sole code-shaped string literal (registry territory, policed by
     *    the literal sweep);
     *  - an argument opening with an array literal (`[…]` or `array(…)`) —
     *    impossible on a typed-string code parameter under strict_types;
     *  - no significant tokens at all (trailing-comma artifact).
     * Everything else — variables, map lookups, calls, constant fetches via
     * `::`, concatenation, interpolation, integers, and human-text literals
     * too spaced or long to be codes — is opaque census material.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $argTokens
     */
    private static function isOpaqueCodeArg(array $argTokens): bool
    {
        $significant = [];
        foreach ($argTokens as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $significant[] = $token;
        }

        if ($significant === []) {
            return false;
        }

        $first = $significant[0];
        if ($first === '[' || (is_array($first) && $first[0] === T_ARRAY)) {
            return false;
        }

        $literal = self::soleStringLiteral($argTokens);
        if ($literal !== null && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\-]*$/', $literal['value']) === 1) {
            return false;
        }

        return true;
    }

    /**
     * The innermost NAMED function on the stack (closures inherit their
     * enclosing named method), or '{top-level}' outside any function.
     *
     * @param list<array{name: string|null, depth: int|null}> $functions
     */
    private static function enclosingNamedMethod(array $functions): string
    {
        for ($k = count($functions) - 1; $k >= 0; $k--) {
            if ($functions[$k]['name'] !== null) {
                return (string) $functions[$k]['name'];
            }
        }
        return '{top-level}';
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
