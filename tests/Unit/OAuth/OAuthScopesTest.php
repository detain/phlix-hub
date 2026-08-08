<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\OAuth;

use Phlix\Hub\Mcp\McpScopes;
use Phlix\Hub\OAuth\OAuthScopes;
use PHPUnit\Framework\TestCase;

use function in_array;

/**
 * Unit tests for {@see OAuthScopes}.
 *
 * @package Phlix\Hub\Tests\Unit\OAuth
 *
 * @covers \Phlix\Hub\OAuth\OAuthScopes
 */
final class OAuthScopesTest extends TestCase
{
    /**
     * The load-bearing claim of S92: the Authorization Server was built ONCE and
     * is shared, rather than built for Alexa now and rebuilt for MCP later.
     *
     * If an OAuth token could not carry an `mcp:*` scope, MCP adopting this
     * server would mean minting scope strings that
     * {@see \Phlix\Hub\Mcp\McpToolRegistry::call()} does not compare against —
     * i.e. a rebuild. This test is what makes "shared" a fact rather than an
     * intention, and it goes red the moment somebody adds an MCP scope without
     * re-exporting it.
     */
    public function test_every_mcp_scope_is_grantable_over_oauth(): void
    {
        self::assertNotSame([], McpScopes::all(), 'guard: the MCP vocabulary must not be empty');

        foreach (McpScopes::all() as $mcpScope) {
            self::assertTrue(
                OAuthScopes::isKnown($mcpScope),
                $mcpScope . ' is an MCP scope that an OAuth token cannot carry — the server is not shared',
            );
        }
    }

    public function test_the_vocabulary_is_the_identity_scope_plus_every_mcp_scope(): void
    {
        $expected = [OAuthScopes::PROFILE_READ, ...McpScopes::all()];

        self::assertSame($expected, OAuthScopes::all());
        self::assertSame('phlix:profile:read', OAuthScopes::PROFILE_READ);
    }

    public function test_parse_drops_unknown_values_rather_than_storing_them(): void
    {
        // Control: a known scope survives.
        self::assertSame([OAuthScopes::PROFILE_READ], OAuthScopes::parse('phlix:profile:read'));

        // A typo becomes "no scope", not a scope that silently matches nothing.
        self::assertSame([], OAuthScopes::parse('phlix:profile:reed'));
        self::assertSame([], OAuthScopes::parse('admin:*'));
        self::assertSame([], OAuthScopes::parse('*'));

        // Mixed input keeps only what is known.
        self::assertSame(
            [OAuthScopes::PROFILE_READ, McpScopes::LIBRARY_READ],
            OAuthScopes::parse('mcp:library:read admin:* phlix:profile:read nonsense'),
        );
    }

    public function test_parse_deduplicates_and_fixes_the_order(): void
    {
        // Two equivalent grants must compare equal however they were written.
        $a = OAuthScopes::parse('mcp:library:read phlix:profile:read mcp:library:read');
        $b = OAuthScopes::parse('phlix:profile:read  mcp:library:read');

        self::assertSame($a, $b);
        self::assertSame([OAuthScopes::PROFILE_READ, McpScopes::LIBRARY_READ], $a);
    }

    public function test_an_empty_or_whitespace_scope_string_parses_to_nothing(): void
    {
        // The fail-closed signal every caller keys off. It is deliberately NOT
        // "the usual set" — S261 shipped that shape for MCP tokens and it handed
        // out the write scope to callers that never asked for it.
        self::assertSame([], OAuthScopes::parse(''));
        self::assertSame([], OAuthScopes::parse('   '));
        self::assertSame([], OAuthScopes::parse("\n\t "));

        // Control: the same parser does return something for real input.
        self::assertNotSame([], OAuthScopes::parse('phlix:profile:read'));
    }

    public function test_from_array_drops_non_string_members_rather_than_coercing_them(): void
    {
        self::assertSame(
            [OAuthScopes::PROFILE_READ],
            OAuthScopes::fromArray(['phlix:profile:read', 0, null, true, ['nested']]),
        );
        self::assertSame([], OAuthScopes::fromArray([]));
        self::assertSame([], OAuthScopes::fromArray([1, 2, 3]));
    }

    public function test_to_storage_round_trips_through_parse(): void
    {
        $stored = OAuthScopes::toStorage([McpScopes::PLAYBACK_CONTROL, OAuthScopes::PROFILE_READ, 'bogus']);

        self::assertSame('phlix:profile:read mcp:playback:control', $stored);
        self::assertSame(
            [OAuthScopes::PROFILE_READ, McpScopes::PLAYBACK_CONTROL],
            OAuthScopes::parse($stored),
        );
    }

    public function test_every_scope_has_a_written_description(): void
    {
        foreach (OAuthScopes::all() as $scope) {
            $description = OAuthScopes::describe($scope);

            self::assertNotSame(
                $scope,
                $description,
                $scope . ' has no consent-screen description — the screen would show the raw scope id',
            );
            self::assertNotSame('', $description);
        }
    }

    public function test_an_unknown_scope_describes_as_itself_rather_than_inventing_a_sentence(): void
    {
        // A derived description would give a new scope a plausible-looking
        // sentence automatically, and the consent screen would describe a
        // capability nobody had written down. Returning the raw id reads as
        // obviously unfinished instead.
        self::assertSame('made:up:scope', OAuthScopes::describe('made:up:scope'));
        self::assertSame('', OAuthScopes::describe(''));
    }

    public function test_is_known_is_exact_and_not_a_prefix_match(): void
    {
        self::assertTrue(OAuthScopes::isKnown(McpScopes::PLAYBACK_READ));

        self::assertFalse(OAuthScopes::isKnown('mcp:playback'));
        self::assertFalse(OAuthScopes::isKnown('mcp:playback:read:extra'));
        self::assertFalse(OAuthScopes::isKnown('MCP:PLAYBACK:READ'));
        self::assertFalse(OAuthScopes::isKnown(' mcp:playback:read'));
    }

    public function test_the_identity_scope_is_the_only_non_mcp_member(): void
    {
        foreach (OAuthScopes::all() as $scope) {
            if ($scope === OAuthScopes::PROFILE_READ) {
                continue;
            }
            self::assertTrue(
                in_array($scope, McpScopes::all(), true),
                $scope . ' is neither the identity scope nor an MCP scope — the union has grown a third source',
            );
        }
    }
}
