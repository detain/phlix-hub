<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Mcp;

use Phlix\Hub\Mcp\McpScopes;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see McpScopes}.
 *
 * @package Phlix\Hub\Tests\Unit\Mcp
 *
 * @covers \Phlix\Hub\Mcp\McpScopes
 */
final class McpScopesTest extends TestCase
{
    public function test_all_scopes_are_namespaced_and_distinct(): void
    {
        $all = McpScopes::all();

        self::assertNotEmpty($all);
        self::assertSame($all, array_values(array_unique($all)));
        foreach ($all as $scope) {
            self::assertStringStartsWith('mcp:', $scope);
        }
    }

    public function test_parse_keeps_known_scopes_and_drops_unknown_ones(): void
    {
        $parsed = McpScopes::parse(McpScopes::SERVERS_READ . ' not-a-scope ' . McpScopes::LIBRARY_READ);

        self::assertSame([McpScopes::SERVERS_READ, McpScopes::LIBRARY_READ], $parsed);
    }

    /**
     * A typo must fail CLOSED — an unknown scope becomes no scope, never a
     * scope that silently matches nothing later.
     */
    public function test_an_entirely_unknown_list_parses_to_nothing(): void
    {
        self::assertSame([], McpScopes::parse('admin:* root everything'));
    }

    public function test_parse_is_order_independent_and_de_duplicating(): void
    {
        $a = McpScopes::parse(McpScopes::LIBRARY_READ . ' ' . McpScopes::SERVERS_READ);
        $b = McpScopes::parse(McpScopes::SERVERS_READ . '  ' . McpScopes::LIBRARY_READ . ' ' . McpScopes::LIBRARY_READ);

        self::assertSame($a, $b);
    }

    public function test_parse_splits_on_any_whitespace(): void
    {
        $parsed = McpScopes::parse("  " . McpScopes::SERVERS_READ . "\n\t" . McpScopes::PLAYBACK_READ . "  ");

        self::assertSame([McpScopes::SERVERS_READ, McpScopes::PLAYBACK_READ], $parsed);
    }

    public function test_from_array_drops_non_string_members_rather_than_coercing_them(): void
    {
        $parsed = McpScopes::fromArray([McpScopes::SERVERS_READ, 0, null, ['nested'], true]);

        self::assertSame([McpScopes::SERVERS_READ], $parsed);
    }

    public function test_to_storage_round_trips_through_parse(): void
    {
        $stored = McpScopes::toStorage([McpScopes::PLAYBACK_READ, 'bogus', McpScopes::SERVERS_READ]);

        self::assertSame([McpScopes::SERVERS_READ, McpScopes::PLAYBACK_READ], McpScopes::parse($stored));
    }

    public function test_is_known_rejects_a_near_miss(): void
    {
        self::assertTrue(McpScopes::isKnown(McpScopes::LIBRARY_READ));
        self::assertFalse(McpScopes::isKnown(McpScopes::LIBRARY_READ . ' '));
        self::assertFalse(McpScopes::isKnown('mcp:library:write'));
    }

    /**
     * No WRITE scope may exist in S62. `playback_control` and every other
     * mutating tool is S63's, and a scope shipped early would authorise it
     * before the tool is reviewed.
     */
    public function test_no_write_scope_ships_in_this_step(): void
    {
        foreach (McpScopes::all() as $scope) {
            self::assertStringEndsWith(':read', $scope, $scope . ' is not a read-only scope.');
        }
    }
}
