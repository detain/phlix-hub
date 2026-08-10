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
    public function testEveryMcpScopeIsGrantableOverOauth(): void
    {
        self::assertNotSame([], McpScopes::all(), 'guard: the MCP vocabulary must not be empty');

        foreach (McpScopes::all() as $mcpScope) {
            self::assertTrue(
                OAuthScopes::isKnown($mcpScope),
                $mcpScope . ' is an MCP scope that an OAuth token cannot carry — the server is not shared',
            );
        }
    }

    public function testTheVocabularyIsTheIdentityScopePlusEveryMcpScope(): void
    {
        $expected = [OAuthScopes::PROFILE_READ, ...McpScopes::all()];

        self::assertSame($expected, OAuthScopes::all());
        self::assertSame('phlix:profile:read', OAuthScopes::PROFILE_READ);
    }

    public function testParseDropsUnknownValuesRatherThanStoringThem(): void
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

    public function testParseDeduplicatesAndFixesTheOrder(): void
    {
        // Two equivalent grants must compare equal however they were written.
        $a = OAuthScopes::parse('mcp:library:read phlix:profile:read mcp:library:read');
        $b = OAuthScopes::parse('phlix:profile:read  mcp:library:read');

        self::assertSame($a, $b);
        self::assertSame([OAuthScopes::PROFILE_READ, McpScopes::LIBRARY_READ], $a);
    }

    public function testAnEmptyOrWhitespaceScopeStringParsesToNothing(): void
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

    public function testFromArrayDropsNonStringMembersRatherThanCoercingThem(): void
    {
        self::assertSame(
            [OAuthScopes::PROFILE_READ],
            OAuthScopes::fromArray(['phlix:profile:read', 0, null, true, ['nested']]),
        );
        self::assertSame([], OAuthScopes::fromArray([]));
        self::assertSame([], OAuthScopes::fromArray([1, 2, 3]));
    }

    public function testToStorageRoundTripsThroughParse(): void
    {
        $stored = OAuthScopes::toStorage([McpScopes::PLAYBACK_CONTROL, OAuthScopes::PROFILE_READ, 'bogus']);

        self::assertSame('phlix:profile:read mcp:playback:control', $stored);
        self::assertSame(
            [OAuthScopes::PROFILE_READ, McpScopes::PLAYBACK_CONTROL],
            OAuthScopes::parse($stored),
        );
    }

    public function testEveryScopeHasAWrittenDescription(): void
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

    public function testAnUnknownScopeDescribesAsItselfRatherThanInventingASentence(): void
    {
        // A derived description would give a new scope a plausible-looking
        // sentence automatically, and the consent screen would describe a
        // capability nobody had written down. Returning the raw id reads as
        // obviously unfinished instead.
        self::assertSame('made:up:scope', OAuthScopes::describe('made:up:scope'));
        self::assertSame('', OAuthScopes::describe(''));
    }

    public function testIsKnownIsExactAndNotAPrefixMatch(): void
    {
        self::assertTrue(OAuthScopes::isKnown(McpScopes::PLAYBACK_READ));

        self::assertFalse(OAuthScopes::isKnown('mcp:playback'));
        self::assertFalse(OAuthScopes::isKnown('mcp:playback:read:extra'));
        self::assertFalse(OAuthScopes::isKnown('MCP:PLAYBACK:READ'));
        self::assertFalse(OAuthScopes::isKnown(' mcp:playback:read'));
    }

    public function testTheIdentityScopeIsTheOnlyNonMcpMember(): void
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
