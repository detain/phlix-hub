<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Mcp;

use InvalidArgumentException;
use Phlix\Hub\Mcp\McpScopes;
use Phlix\Hub\Mcp\McpToolContext;
use Phlix\Hub\Mcp\McpToolInterface;
use Phlix\Hub\Mcp\McpToolRegistry;
use Phlix\Hub\Mcp\Tools\GetMediaTool;
use Phlix\Hub\Mcp\Tools\GetPlaybackInfoTool;
use Phlix\Hub\Mcp\Tools\ListLibrariesTool;
use Phlix\Hub\Mcp\Tools\ListServersTool;
use Phlix\Hub\Mcp\Tools\SearchMediaTool;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function array_column;
use function count;

/**
 * Unit tests for {@see McpToolRegistry} and the shipped tool descriptors.
 *
 * @package Phlix\Hub\Tests\Unit\Mcp
 *
 * @covers \Phlix\Hub\Mcp\McpToolRegistry
 */
final class McpToolRegistryTest extends TestCase
{
    /**
     * A duplicate name must be a wiring-time failure. `Router::addRoute()`
     * taught this repository what silent replacement costs: the later
     * registration wins and nothing notices. A registry keyed by name has the
     * same hazard, so it throws instead.
     */
    public function test_a_duplicate_tool_name_throws_rather_than_replacing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Duplicate MCP tool name/');

        new McpToolRegistry([new ListServersTool(), new ListServersTool()]);
    }

    /**
     * A tool that requires a scope nobody can hold would be silently dead —
     * present in `tools/list`, refused on every call. Fail at wiring instead.
     */
    public function test_a_tool_requiring_an_unknown_scope_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unknown scope/');

        new McpToolRegistry([new class implements McpToolInterface {
            public function name(): string
            {
                return 'bogus';
            }

            public function description(): string
            {
                return 'A tool with a scope no token can hold.';
            }

            /** @return array<string, mixed> */
            public function inputSchema(): array
            {
                return ['type' => 'object'];
            }

            public function requiredScope(): string
            {
                return 'mcp:does-not-exist';
            }

            /**
             * @param array<string, mixed> $arguments
             *
             * @return array{status: int, payload: array<string, mixed>}
             */
            public function call(array $arguments, McpToolContext $context): array
            {
                return ['status' => 200, 'payload' => []];
            }
        }]);
    }

    public function test_describe_lists_every_tool_with_a_schema_and_a_scope(): void
    {
        $registry = self::productionRegistry();
        $described = $registry->describe();

        self::assertCount(count($registry->names()), $described);
        self::assertSame($registry->names(), array_column($described, 'name'));

        foreach ($described as $tool) {
            self::assertNotSame('', $tool['description']);
            self::assertIsArray($tool['inputSchema']);
            self::assertTrue(McpScopes::isKnown((string) $tool['x-phlix-scope']));
        }
    }

    /**
     * `tools/list` is NOT scope-filtered, deliberately: an MCP client caches it,
     * and a filtered list makes "you lack the scope" indistinguishable from "the
     * tool was removed".
     */
    public function test_describe_is_not_filtered_by_scope(): void
    {
        $registry = self::productionRegistry();

        self::assertCount(5, $registry->describe());
        self::assertTrue($registry->has('get_playback_info'));
    }

    public function test_calling_an_unregistered_tool_is_a_named_404(): void
    {
        $outcome = self::productionRegistry()->call('rm_rf', [], self::context());

        self::assertSame(404, $outcome['status']);
        self::assertSame('mcp.unknown_tool', $outcome['payload']['code'] ?? null);
    }

    /**
     * Every shipped tool must declare a schema that names its required
     * arguments, or the model is guessing.
     *
     * @dataProvider shippedToolProvider
     */
    public function test_every_shipped_tool_declares_a_usable_schema(McpToolInterface $tool): void
    {
        $schema = $tool->inputSchema();

        self::assertSame('object', $schema['type'] ?? null, $tool->name() . ' schema is not an object.');
        self::assertFalse(
            $schema['additionalProperties'] ?? true,
            $tool->name() . ' accepts unknown properties; the model should be told exactly what it may send.',
        );
        self::assertNotSame('', $tool->description());
        self::assertTrue(McpScopes::isKnown($tool->requiredScope()));
    }

    /**
     * @return list<array{0: McpToolInterface}>
     */
    public static function shippedToolProvider(): array
    {
        return [
            [new ListServersTool()],
            [new ListLibrariesTool()],
            [new SearchMediaTool()],
            [new GetMediaTool()],
            [new GetPlaybackInfoTool()],
        ];
    }

    private static function productionRegistry(): McpToolRegistry
    {
        return new McpToolRegistry([
            new ListServersTool(),
            new ListLibrariesTool(),
            new SearchMediaTool(),
            new GetMediaTool(),
            new GetPlaybackInfoTool(),
        ]);
    }

    /**
     * A context that would explode if it were ever used — every assertion in
     * this suite is about the registry refusing BEFORE the context is touched.
     */
    private static function context(): McpToolContext
    {
        return (new ReflectionClass(McpToolContext::class))->newInstanceWithoutConstructor();
    }
}
