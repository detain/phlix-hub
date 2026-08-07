<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Mcp;

use Phlix\Hub\Mcp\McpScopes;
use Phlix\Hub\Mcp\McpTokenService;
use PHPUnit\Framework\TestCase;

use function count;
use function file_get_contents;
use function json_decode;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * Pins the hub's MCP scope vocabulary to `@phlix/contracts` (S249).
 *
 * ## Why this file exists
 *
 * The vocabulary in {@see McpScopes} is a CROSS-REPO contract. `phlix-ui`
 * renders it, `phlix-contracts` publishes it, and `mcp_tokens.scopes` stores it.
 * Before S249 the only check on that agreement lived in phlix-ui and was guarded
 * by `it.runIf(existsSync(<sibling phlix-hub path>))` — CI has no such sibling,
 * so it never executed and reported as PASSING. S249's phlix-ui half made
 * phlix-ui unable to drift from contracts. It did NOT stop the HUB drifting:
 * a hub writer adding a fifth scope got green CI in every repo, with phlix-ui
 * and contracts agreeing perfectly while both were wrong about the server.
 * This file is the direction that closes.
 *
 * ## Why a vendored fixture rather than reading the package
 *
 * `phlix-hub` has no `package.json`, and `.github/workflows/ci.yml` has no
 * `setup-node` and no `npm ci`. The gate must therefore be npm-free and
 * network-free. `tests/fixtures/contracts/mcp-scopes.json` is a byte-for-byte
 * copy of `dist/mcp-scopes.json` from the `@phlix/contracts` tag named in
 * `tests/fixtures/contracts/PIN` — which is precisely why contracts emits that
 * artifact at all.
 *
 * ## The failure this file must never become
 *
 * A truncated, empty or wrong-keyed fixture would make the comparison below
 * `assertSame([], [])` and pass — a gate that inspects nothing, wearing the
 * costume of a gate that inspects everything. The anti-vacuity floor is
 * asserted BEFORE any comparison for exactly that reason. Do not move it, do
 * not soften it to a truthiness check, and do not delete it because "the
 * comparison would catch that anyway" — it would not.
 *
 * @package Phlix\Hub\Tests\Unit\Mcp
 *
 * @coversNothing This asserts a cross-repo CONTRACT, not the behaviour of any
 *                one class; {@see McpScopesTest} owns McpScopes' behaviour
 *                coverage. Claiming @covers here would silently DISCARD that
 *                file's attribution for no gain.
 */
final class McpScopesContractTest extends TestCase
{
    /**
     * Anti-vacuity floor: the vocabulary has had four members since S63 added
     * `mcp:playback:control`. Asserted on BOTH sides, BEFORE the comparison.
     *
     * If the hub ever legitimately drops below four scopes this constant must
     * be edited deliberately, in the same commit, with the reason stated. That
     * deliberate edit is the point — it is what makes the shrink visible.
     */
    private const int SCOPE_FLOOR = 4;

    private const string FIXTURE = __DIR__ . '/../../fixtures/contracts/mcp-scopes.json';

    private const string PIN = __DIR__ . '/../../fixtures/contracts/PIN';

    public function testScopeVocabularyMatchesTheContractsPackage(): void
    {
        self::assertFileExists(self::FIXTURE, 'the vendored @phlix/contracts vocabulary is missing');

        $decoded = json_decode((string) file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, 'the vendored artifact must decode to an object');

        $scopes = $decoded['scopes'] ?? null;
        $tokenPrefix = $decoded['tokenPrefix'] ?? null;

        // ---------------------------------------------------------------
        // ANTI-VACUITY, BEFORE any comparison. Without these, a fixture that
        // failed to parse or lost its `scopes` key reduces the assertSame()
        // below to comparing [] with [] — the "gate that inspected zero
        // files" failure in its PHP form. Both sides are floored, because
        // flooring only the fixture still lets an emptied all() slip past.
        // ---------------------------------------------------------------
        self::assertIsArray($scopes, 'FLOOR: the fixture has no usable `scopes` array');
        self::assertGreaterThanOrEqual(
            self::SCOPE_FLOOR,
            count($scopes),
            'FLOOR: the fixture must carry at least ' . self::SCOPE_FLOOR . ' scopes (4 as of S63)',
        );
        self::assertIsString($tokenPrefix, 'FLOOR: the fixture has no usable `tokenPrefix` string');
        self::assertNotSame('', $tokenPrefix, 'FLOOR: the fixture `tokenPrefix` is empty');
        self::assertGreaterThanOrEqual(
            self::SCOPE_FLOOR,
            count(McpScopes::all()),
            'FLOOR: McpScopes::all() must carry at least ' . self::SCOPE_FLOOR . ' scopes (4 as of S63)',
        );

        // ---------------------------------------------------------------
        // EXACT, ORDERED, WHOLE-LIST.
        //
        // assertSame, never assertEquals. Never str_contains / in_array /
        // any substring test: 'mcp:playback' is a PREFIX of
        // 'mcp:playback:control', so a substring check passes a rename and
        // this whole file becomes decorative.
        //
        // Order is asserted deliberately. McpScopes::parse() emits in
        // all() order into the mcp_tokens.scopes column, so the order IS
        // part of the stored representation — appending is safe, reordering
        // rewrites what every existing row compares equal to.
        // ---------------------------------------------------------------
        self::assertSame(
            $scopes,
            McpScopes::all(),
            'McpScopes::all() has drifted from @phlix/contracts (see tests/fixtures/contracts/PIN). '
            . 'Fix the CONTRACT first: add the scope to phlix-contracts src/mcp.ts, npm run build, '
            . 'tag, then re-vendor dist/mcp-scopes.json here. Do NOT edit the fixture alone.',
        );

        self::assertSame(
            $tokenPrefix,
            McpTokenService::TOKEN_PREFIX,
            'McpTokenService::TOKEN_PREFIX has drifted from @phlix/contracts (see '
            . 'tests/fixtures/contracts/PIN). Fix the CONTRACT first, then re-vendor.',
        );
    }

    /**
     * Keeps the FIXTURE honest about being a real copy.
     *
     * Guards the obvious way to "fix" a red above: hand-writing the fixture to
     * match a drifted all(). The contracts generator always emits the marker
     * below; a hand-written stub will not. The PIN assertion means the file
     * also has to say which tag it claims to be a copy of.
     */
    public function testTheVendoredArtifactIsTheGeneratedShape(): void
    {
        $raw = (string) file_get_contents(self::FIXTURE);

        self::assertStringContainsString('GENERATED by scripts/emit-mcp-scopes.mjs', $raw);
        self::assertSame('v0.4.2', trim((string) file_get_contents(self::PIN)));
    }
}
