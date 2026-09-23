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

use function count;
use function file_get_contents;
use function implode;
use function is_file;
use function json_decode;
use function preg_match;
use function preg_split;
use function sprintf;
use function str_repeat;
use function strlen;
use function strspn;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * Pins `openapi.yaml`'s `Error.code` enum to the vendored `@phlix/contracts`
 * error-code fixture — the S260 mcp-scope precedent applied to the error
 * vocabulary.
 *
 * ## The gate map logic
 *
 * There are three published statements of the hub's error-code vocabulary:
 * the literals in `src/` (what the server actually emits), the vendored
 * fixture `tests/fixtures/contracts/error-codes.json` (what contracts
 * publishes), and `openapi.yaml`'s `Error.code` enum (what spec-generated
 * callers can express). {@see ErrorCodesContractTest} pins the first two to
 * each other. Nothing pinned the third. This file is that missing pin:
 * whole-list, exact, ordered `assertSame` against the fixture — never
 * substring checks.
 *
 * ## Why the YAML is read line by line rather than parsed
 *
 * `phlix-hub` has no `symfony/yaml`, and the phpunit CI job installs no `yaml`
 * extension — `yaml_parse()` would pass locally and fatal in CI. Same reading
 * strategy (and the same `enum:`-dedent parser) as
 * {@see \Phlix\Hub\Tests\Unit\Mcp\McpScopeOpenApiEnumContractTest}.
 *
 * @package Phlix\Hub\Tests\Unit\Contracts
 */
final class ErrorCodesOpenApiEnumContractTest extends TestCase
{
    /**
     * Anti-vacuity floor, asserted on BOTH sides BEFORE any comparison —
     * an extractor that silently found nothing, or a truncated fixture,
     * would otherwise make this `assertSame([], [])`: a gate inspecting zero.
     */
    private const int CODE_FLOOR = 147;

    private const string SPEC = __DIR__ . '/../../../openapi.yaml';

    private const string FIXTURE = __DIR__ . '/../../fixtures/contracts/error-codes.json';

    /** Indentation of a schema name under `components: schemas:`. */
    private const int SCHEMA_INDENT = 4;

    public function testOpenApiErrorCodeEnumMatchesTheVendoredFixture(): void
    {
        $enum = $this->specErrorCodeEnum();
        $fixture = $this->fixtureCodes();

        self::assertGreaterThanOrEqual(
            self::CODE_FLOOR,
            count($enum),
            sprintf(
                'FLOOR: openapi.yaml\'s Error.code enum must carry at least %d members (202 as of '
                . '@phlix/contracts v0.5.1); read %d. Either the enum shrank or the extractor '
                . 'stopped finding it.',
                self::CODE_FLOOR,
                count($enum),
            ),
        );
        self::assertGreaterThanOrEqual(
            self::CODE_FLOOR,
            count($fixture),
            sprintf(
                'FLOOR: the vendored fixture must carry at least %d codes; got %d.',
                self::CODE_FLOOR,
                count($fixture),
            ),
        );

        // EXACT, ORDERED, WHOLE-LIST. The fixture order is the registry
        // emission order (dist/error-codes.json), so a spec-side reorder is
        // a real drift signal, and the enum is what generated clients bake in.
        self::assertSame(
            $fixture,
            $enum,
            'openapi.yaml\'s Error.code enum has drifted from tests/fixtures/contracts/error-codes.json. '
            . 'Fix the CONTRACT first (phlix-contracts src/errors.ts, build, tag), re-vendor the fixture '
            . '+ error-codes.PIN, then regenerate the enum from the fixture. Do NOT hand-edit either side.',
        );
    }

    /**
     * @return list<string>
     */
    private function fixtureCodes(): array
    {
        self::assertFileExists(self::FIXTURE);
        $decoded = json_decode((string) file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, 'the fixture must decode to an object');
        $raw = $decoded['codes'] ?? null;
        self::assertIsArray($raw, 'fixture has no usable `codes` array');

        $codes = [];
        foreach ($raw as $code) {
            self::assertIsString($code, 'every fixture code must be a string');
            $codes[] = $code;
        }
        return $codes;
    }

    /**
     * Extract `components.schemas.Error.properties.code.enum` as an ordered
     * list. Fails loudly (never quietly returns []) when the schema, the
     * `code:` property, the `enum:` key, or any member cannot be found.
     *
     * @return list<string>
     */
    private function specErrorCodeEnum(): array
    {
        $block = $this->schemaBlock($this->specSource(), 'Error');

        $lines = preg_split('/\r\n|\n/', $block);
        self::assertIsArray($lines);

        // Descend into the `code:` property of the Error schema first.
        $start = null;
        $propertyIndent = null;
        foreach ($lines as $index => $line) {
            if (trim($line) === 'code:') {
                $start = $index + 1;
                $propertyIndent = strspn($line, ' ');
                break;
            }
        }
        self::assertNotNull($start, 'components.schemas.Error has no `code:` property, so nothing was read.');
        self::assertNotNull($propertyIndent);

        $enumIndent = null;
        $members = [];
        for ($i = $start, $n = count($lines); $i < $n; $i++) {
            $line = $lines[$i];
            if (trim($line) === '') {
                continue;
            }

            $indent = strspn($line, ' ');
            if ($enumIndent === null) {
                if ($indent <= $propertyIndent) {
                    break; // dedented out of the code property
                }
                if (trim($line) === 'enum:') {
                    $enumIndent = $indent;
                }
                continue;
            }

            if ($indent <= $enumIndent) {
                break; // dedented out of the enum block
            }

            if (preg_match('/^\s*-\s*"([^"]+)"\s*$/', $line, $m) !== 1) {
                self::fail(sprintf('Unparsable member in Error.code.enum: %s', $line));
            }
            $members[] = $m[1];
        }

        self::assertNotNull(
            $enumIndent,
            'components.schemas.Error.properties.code has no `enum:` key, so NOTHING was compared. '
            . 'If the code field stopped being an enum this test must be rewritten, not deleted.',
        );

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

        return $source;
    }
}
