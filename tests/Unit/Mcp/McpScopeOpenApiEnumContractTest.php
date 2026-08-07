<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Mcp;

use Phlix\Hub\Mcp\McpScopes;
use PHPUnit\Framework\TestCase;

use function count;
use function file_get_contents;
use function is_file;
use function preg_match;
use function preg_split;
use function sprintf;
use function str_repeat;
use function strlen;
use function strspn;
use function trim;

/**
 * Pins `openapi.yaml`'s `McpScope` enum to {@see McpScopes::all()} (S260).
 *
 * ## The gate map predicted exactly where this would break
 *
 * There are three published statements of the MCP scope vocabulary:
 * {@see McpScopes} (the server), `@phlix/contracts`' `dist/mcp-scopes.json`
 * (the clients), and `openapi.yaml`'s `McpScope` schema (spec-generated
 * callers). S249 pinned the first two to each other in
 * {@see McpScopesContractTest}. `OpenApiSpecMatchesRouterTest` pins `paths` in
 * an exact bijection with the router — **paths only**. Nothing pinned any
 * schema `enum` to its PHP constant.
 *
 * So the two sources with a gate agreed, and the one without a gate was the one
 * that was wrong: the enum listed **three** members and omitted
 * `mcp:playback:control`. That is not a coincidence, it is the gate map. This
 * file is the missing pin.
 *
 * ⚠ The omission was worse than a gap, which is why the fix was a bug fix and
 * not a doc tidy-up. The schema's own description says the set is *closed* and
 * unknown values are *dropped* — so the spec affirmatively told a reader that
 * the fourth scope would be REJECTED, while `McpTokenController::create()`
 * grants it BY DEFAULT whenever `scopes` is omitted. A spec-generated client got
 * a request type that could not express, and a response type that could not
 * parse, the scope the server issues by default.
 *
 * ## Why the comparison can actually fail
 *
 * Two INDEPENDENT sources: PHP constants on one side, bytes read off the
 * committed YAML on the other. An equality assertion between two lists derived
 * from the same source self-adjusts and can never red
 * ([[feedback_a_check_derived_from_its_subject_self_adjusts]]); this one was
 * demonstrated by mutating each side separately, and each mutation reds.
 *
 * ⚠ `mcp:playback` is a PREFIX of `mcp:playback:control`. Every comparison here
 * is exact, ordered and whole-list — never `in_array`, `str_contains` or any
 * substring test, which would pass a rename and make this file decorative.
 *
 * ## Why the YAML is read line by line rather than parsed
 *
 * `phlix-hub` has no `symfony/yaml`, and `.github/workflows/ci.yml` installs
 * `json, pcntl, posix, swoole` for the phpunit job — **no `yaml` extension**. A
 * `yaml_parse()` here would pass on this dev box and fatal in CI. The sibling
 * {@see \Phlix\Hub\Tests\Unit\Http\RouteRegistration\OpenApiSpecMatchesRouterTest}
 * reads the same file the same way for the same reason.
 *
 * @package Phlix\Hub\Tests\Unit\Mcp
 *
 * @coversNothing This asserts a CONTRACT between a spec file and a constant, not
 *                the behaviour of any one class; {@see McpScopesTest} owns
 *                McpScopes' coverage and claiming @covers here would silently
 *                DISCARD that file's attribution for no gain.
 */
final class McpScopeOpenApiEnumContractTest extends TestCase
{
    /**
     * Anti-vacuity floor. The vocabulary has had four members since S63 added
     * `mcp:playback:control`.
     *
     * Asserted on BOTH sides BEFORE any comparison, because `assertSame([], [])`
     * passes happily: an extractor that silently found nothing, or an `all()`
     * that was emptied, would otherwise turn this file into a gate that inspects
     * zero bytes while wearing the costume of one that inspects everything.
     *
     * If the hub ever legitimately drops below four scopes, edit this constant
     * deliberately in the same commit and say why. That deliberate edit is the
     * point — it is what makes a shrink visible.
     */
    private const int SCOPE_FLOOR = 4;

    private const string SPEC = __DIR__ . '/../../../openapi.yaml';

    /** Indentation of a schema name under `components: schemas:`. */
    private const int SCHEMA_INDENT = 4;

    public function testOpenApiMcpScopeEnumMatchesMcpScopesAll(): void
    {
        $enum = $this->specEnum('McpScope');
        $php = McpScopes::all();

        // --- ANTI-VACUITY, BEFORE any comparison. Both sides, independently. --
        self::assertGreaterThanOrEqual(
            self::SCOPE_FLOOR,
            count($enum),
            sprintf(
                'FLOOR: openapi.yaml\'s McpScope enum must carry at least %d members (4 as of S63); '
                . 'read %d. Either the schema shrank or the extractor stopped finding it.',
                self::SCOPE_FLOOR,
                count($enum),
            ),
        );
        self::assertGreaterThanOrEqual(
            self::SCOPE_FLOOR,
            count($php),
            sprintf(
                'FLOOR: McpScopes::all() must carry at least %d scopes (4 as of S63); got %d.',
                self::SCOPE_FLOOR,
                count($php),
            ),
        );

        // --- EXACT, ORDERED, WHOLE-LIST. ------------------------------------
        // Order is asserted deliberately: McpScopes::parse() emits in all()
        // order into the mcp_tokens.scopes column, so the order IS part of the
        // stored representation, and the spec is what a generated client will
        // reproduce.
        self::assertSame(
            $php,
            $enum,
            'openapi.yaml\'s McpScope enum has drifted from McpScopes::all(). The spec is the ONE '
            . 'of the three scope sources with no other gate on it (S249 pins McpScopes against '
            . '@phlix/contracts; OpenApiSpecMatchesRouterTest pins paths only), so it is the one '
            . 'that drifts. Update components.schemas.McpScope in openapi.yaml — and do NOT add an '
            . 'exclusion list to OpenApiSpecMatchesRouterTest, which is a different pin entirely.',
        );
    }

    /**
     * Every schema that references `McpScope` inherits the enum, so a caller
     * cannot express or parse a scope the enum omits. Naming them here is what
     * makes the blast radius of a future omission concrete rather than implied.
     */
    public function testTheSchemasThatDependOnMcpScopeStillReferenceIt(): void
    {
        $source = $this->specSource();

        foreach (
            [
                'McpTokenMintRequest',   // a client cannot REQUEST an omitted scope
                'McpTokenMinted',        // a client cannot PARSE the default grant
                'McpTokenSummary',       // nor read it back off an existing token
                'McpTokenList',          // nor off `available_scopes`
            ] as $schema
        ) {
            $block = $this->schemaBlock($source, $schema);
            self::assertStringContainsString(
                '#/components/schemas/McpScope',
                $block,
                sprintf(
                    '%s no longer references McpScope. If that is deliberate, this test needs '
                    . 'editing; if it is not, a generated client just lost the scope vocabulary.',
                    $schema,
                ),
            );
        }
    }

    /**
     * Extract `components.schemas.<name>.enum` as an ordered list of strings.
     *
     * Fails (never returns an empty list quietly) when the schema, the `enum:`
     * key, or any member cannot be found — a silent `[]` here is precisely the
     * vacuous pass the floor above exists to catch, and catching it twice is
     * cheaper than explaining it once.
     *
     * @return list<string>
     */
    private function specEnum(string $schema): array
    {
        $block = $this->schemaBlock($this->specSource(), $schema);

        $lines = preg_split('/\r\n|\n/', $block);
        self::assertIsArray($lines);

        $enumIndent = null;
        $members = [];
        foreach ($lines as $line) {
            if ($enumIndent === null) {
                if (trim($line) === 'enum:') {
                    $enumIndent = strspn($line, ' ');
                }
                continue;
            }

            if (trim($line) === '') {
                continue;
            }
            if (strspn($line, ' ') <= $enumIndent) {
                break; // dedented out of the enum block
            }

            self::assertSame(
                1,
                preg_match('/^\s*-\s*"([^"]+)"\s*$/', $line, $m),
                sprintf('Unparsable member in %s.enum: %s', $schema, $line),
            );
            $members[] = $m[1];
        }

        self::assertNotNull(
            $enumIndent,
            sprintf(
                'components.schemas.%s has no `enum:` key, so NOTHING was compared. If the schema '
                . 'stopped being an enum this test must be rewritten, not deleted.',
                $schema,
            ),
        );

        /** @var list<string> $members */
        return $members;
    }

    /**
     * The raw text of one `components.schemas.<name>:` block, from its key line
     * to the next line at the same or lower indentation.
     */
    private function schemaBlock(string $source, string $schema): string
    {
        $lines = preg_split('/\r\n|\n/', $source);
        self::assertIsArray($lines);

        $header = str_repeat(' ', self::SCHEMA_INDENT) . $schema . ':';
        $start = null;
        foreach ($lines as $index => $line) {
            if ($line === $header) {
                $start = $index;
                break;
            }
        }

        self::assertNotNull(
            $start,
            sprintf(
                'openapi.yaml has no `components.schemas.%s` at %d-space indent, so nothing could '
                . 'be read out of it. The schema was renamed, removed, or re-indented.',
                $schema,
                self::SCHEMA_INDENT,
            ),
        );

        $block = [$lines[$start]];
        for ($i = $start + 1, $n = count($lines); $i < $n; $i++) {
            $line = $lines[$i];
            if (trim($line) !== '' && strspn($line, ' ') <= self::SCHEMA_INDENT) {
                break;
            }
            $block[] = $line;
        }

        return implode("\n", $block);
    }

    private function specSource(): string
    {
        self::assertTrue(
            is_file(self::SPEC),
            sprintf('%s does not exist, so nothing could be compared against it.', self::SPEC),
        );

        $source = file_get_contents(self::SPEC);
        self::assertTrue($source !== false && strlen($source) > 0, 'openapi.yaml could not be read.');

        /** @var string $source */
        return $source;
    }
}
