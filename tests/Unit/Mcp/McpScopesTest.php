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
     * The write scopes are an EXACT, named set.
     *
     * S62 shipped this as `test_no_write_scope_ships_in_this_step` — every scope
     * had to end `:read`. S63 legitimately adds the first write
     * (`mcp:playback:control`), so a blanket "no writes" assertion would have to
     * be deleted, and a deleted assertion protects nothing. It is replaced by
     * the stricter thing it was standing in for: the write set is enumerated
     * here, so adding a SECOND write scope reds this test and has to be argued
     * for, exactly as this one was.
     */
    public function test_the_write_scopes_are_exactly_the_named_set(): void
    {
        $writes = [];
        foreach (McpScopes::all() as $scope) {
            if (!str_ends_with($scope, ':read')) {
                $writes[] = $scope;
            }
        }

        self::assertSame(
            [McpScopes::PLAYBACK_CONTROL],
            $writes,
            'The set of MCP scopes that authorise a WRITE changed. Every entry here lets a personal '
            . 'access token change state on a media server, so a new one needs its own review — and '
            . 'a tool behind it needs its own operator flag, as playback_control has.',
        );
    }

    /**
     * ...with the reads re-asserted beside it, so the test above cannot pass by
     * the list having been emptied.
     */
    public function test_the_read_scopes_are_still_present(): void
    {
        self::assertSame(
            [McpScopes::SERVERS_READ, McpScopes::LIBRARY_READ, McpScopes::PLAYBACK_READ],
            array_values(array_filter(McpScopes::all(), static fn (string $s): bool => str_ends_with($s, ':read'))),
        );
    }

    /**
     * The write scope is NOT granted by asking for the read one. They are
     * separate strings and `parse()` never promotes between them — a token
     * minted to READ playback information must not be able to stop a film.
     */
    public function test_the_read_scope_does_not_imply_the_control_scope(): void
    {
        self::assertSame([McpScopes::PLAYBACK_READ], McpScopes::parse(McpScopes::PLAYBACK_READ));
        self::assertNotContains(McpScopes::PLAYBACK_CONTROL, McpScopes::parse(McpScopes::PLAYBACK_READ));
    }
}
