<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\OAuth;

use Phlix\Hub\OAuth\OAuthError;
use Phlix\Hub\OAuth\OAuthScopes;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function count;
use function file_get_contents;
use function implode;
use function is_file;
use function is_string;
use function preg_match;
use function preg_split;
use function sort;
use function sprintf;
use function str_repeat;
use function strlen;
use function strspn;
use function trim;

/**
 * Pins `openapi.yaml`'s `OAuthScope` and `OAuthErrorResponse.error` enums to the
 * PHP constants they describe (S92).
 *
 * ## Why these two enums specifically
 *
 * `OpenApiSpecMatchesRouterTest` holds `paths` in an exact bijection with the
 * router — **paths only**. Nothing generic pins a schema `enum` to its PHP
 * source, which is why S260 found `McpScope` silently missing a member. The two
 * enums added by S92 are the two whose drift is most expensive:
 *
 *  - **`OAuthScope`** is the capability vocabulary. A spec that omits a scope
 *    tells a generated client the scope will be REJECTED, while the server grants
 *    it — the exact shape of the S260 defect.
 *  - **`OAuthErrorResponse.error`** is how a client library classifies a failure.
 *    A missing member degrades a specific, actionable error into a generic one
 *    with no remediation path.
 *
 * ## Why the comparison can actually fail
 *
 * Two INDEPENDENT sources: PHP constants on one side, bytes read off the
 * committed YAML on the other. A check derived from its own subject self-adjusts
 * and can never red. Every comparison here is exact and whole-list — never
 * `in_array` or `str_contains`, which would pass a rename.
 *
 * ⚠ `mcp:playback` is a PREFIX of `mcp:playback:control`, and
 * `invalid_client`/`invalid_grant` share a prefix too; a substring test would be
 * decorative.
 *
 * ## Why the YAML is read line by line rather than parsed
 *
 * `.github/workflows/ci.yml` installs `json, pcntl, posix, swoole` for the
 * phpunit job — **no `yaml` extension**, and no `symfony/yaml` in the lock. A
 * `yaml_parse()` here would pass on a dev box and fatal in CI.
 *
 * @package Phlix\Hub\Tests\Unit\OAuth
 */
final class OAuthScopeOpenApiEnumContractTest extends TestCase
{
    /**
     * Anti-vacuity floor for the scope vocabulary: the identity scope plus the
     * four MCP scopes.
     *
     * Asserted on BOTH sides BEFORE any comparison, because `assertSame([], [])`
     * passes happily — an extractor that silently found nothing would otherwise
     * be a gate that inspects zero bytes while wearing the costume of one that
     * inspects everything.
     */
    private const int SCOPE_FLOOR = 5;

    /**
     * Anti-vacuity floor for the error vocabulary: the nine RFC 6749 §5.2
     * Authorization-Server codes plus the two RFC 6750 §3.1 Bearer-token codes
     * the S286 resource server emits (`invalid_token`, `insufficient_scope`).
     */
    private const int ERROR_FLOOR = 11;

    private const string SPEC = __DIR__ . '/../../../openapi.yaml';

    /** Indentation of a schema name under `components: schemas:`. */
    private const int SCHEMA_INDENT = 4;

    public function testOpenApiOAuthScopeEnumMatchesOAuthScopesAll(): void
    {
        $enum = $this->specEnum('OAuthScope');
        $php  = OAuthScopes::all();

        self::assertGreaterThanOrEqual(
            self::SCOPE_FLOOR,
            count($enum),
            sprintf(
                'FLOOR: openapi.yaml\'s OAuthScope enum must carry at least %d members; read %d. '
                . 'Either the schema shrank or the extractor stopped finding it.',
                self::SCOPE_FLOOR,
                count($enum),
            ),
        );
        self::assertGreaterThanOrEqual(
            self::SCOPE_FLOOR,
            count($php),
            sprintf('FLOOR: OAuthScopes::all() must carry at least %d scopes; got %d.', self::SCOPE_FLOOR, count($php)),
        );

        // EXACT, ORDERED, WHOLE-LIST. Order is asserted deliberately: parse()
        // emits in all() order into every `scopes` column, so the order IS part
        // of the stored representation and of what a generated client reproduces.
        self::assertSame(
            $php,
            $enum,
            'openapi.yaml\'s OAuthScope enum has drifted from OAuthScopes::all(). Update '
            . 'components.schemas.OAuthScope — and do NOT add an exclusion list to '
            . 'OpenApiSpecMatchesRouterTest, which is a different pin entirely.',
        );
    }

    public function testOpenApiOAuthErrorEnumMatchesTheOAuthErrorConstants(): void
    {
        $enum = $this->specEnum('OAuthErrorResponse');
        $php  = $this->errorConstants();

        self::assertGreaterThanOrEqual(
            self::ERROR_FLOOR,
            count($enum),
            sprintf(
                'FLOOR: OAuthErrorResponse.error must carry at least %d members; read %d.',
                self::ERROR_FLOOR,
                count($enum)
            ),
        );
        self::assertGreaterThanOrEqual(
            self::ERROR_FLOOR,
            count($php),
            sprintf('FLOOR: OAuthError must declare at least %d codes; got %d.', self::ERROR_FLOOR, count($php)),
        );

        // Sorted on both sides: unlike the scopes, the error-code order is not
        // part of any stored representation, so pinning it would be a check that
        // fails for a reason nobody cares about.
        $enumSorted = $enum;
        $phpSorted  = $php;
        sort($enumSorted);
        sort($phpSorted);

        self::assertSame(
            $phpSorted,
            $enumSorted,
            'openapi.yaml\'s OAuthErrorResponse.error enum has drifted from the OAuthError constants. '
            . 'A client library classifies failures on these strings; a missing member degrades a '
            . 'specific, actionable error into a generic one.',
        );
    }

    /**
     * Every `OAuthError` constant, read by reflection rather than restated.
     *
     * Reflection is safe here precisely BECAUSE the other side of the comparison
     * is a different file: a list derived from the class is compared against
     * bytes on disk, so it cannot self-adjust.
     *
     * @return list<string>
     */
    private function errorConstants(): array
    {
        $values = [];
        /** @var mixed $value */
        foreach ((new ReflectionClass(OAuthError::class))->getConstants() as $value) {
            self::assertTrue(is_string($value), 'every OAuthError constant must be a string');
            /** @var string $value */
            $values[] = $value;
        }

        return $values;
    }

    /**
     * Extract the first `enum:` list inside `components.schemas.<name>`.
     *
     * Fails — never returns an empty list quietly — when the schema, the `enum:`
     * key, or a member cannot be found.
     *
     * @return list<string>
     */
    private function specEnum(string $schema): array
    {
        $block = $this->schemaBlock($this->specSource(), $schema);

        $lines = preg_split('/\r\n|\n/', $block);
        self::assertIsArray($lines);

        $enumIndent = null;
        $members    = [];
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

            if (preg_match('/^\s*-\s*"([^"]+)"\s*$/', $line, $m) !== 1) {
                self::fail(sprintf('Unparsable member in %s.enum: %s', $schema, $line));
            }
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

        return $members;
    }

    /**
     * The raw text of one `components.schemas.<name>:` block.
     */
    private function schemaBlock(string $source, string $schema): string
    {
        $lines = preg_split('/\r\n|\n/', $source);
        self::assertIsArray($lines);

        $header = str_repeat(' ', self::SCHEMA_INDENT) . $schema . ':';
        $start  = null;
        foreach ($lines as $index => $line) {
            if ($line === $header) {
                $start = $index;
                break;
            }
        }

        self::assertNotNull(
            $start,
            sprintf(
                'openapi.yaml has no `components.schemas.%s` at %d-space indent, so nothing could be '
                . 'read out of it. The schema was renamed, removed, or re-indented.',
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

        return $source;
    }
}
