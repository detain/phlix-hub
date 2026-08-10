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
    public function testAllScopesAreNamespacedAndDistinct(): void
    {
        $all = McpScopes::all();

        self::assertNotEmpty($all);
        self::assertSame($all, array_values(array_unique($all)));
        foreach ($all as $scope) {
            self::assertStringStartsWith('mcp:', $scope);
        }
    }

    public function testParseKeepsKnownScopesAndDropsUnknownOnes(): void
    {
        $parsed = McpScopes::parse(McpScopes::SERVERS_READ . ' not-a-scope ' . McpScopes::LIBRARY_READ);

        self::assertSame([McpScopes::SERVERS_READ, McpScopes::LIBRARY_READ], $parsed);
    }

    /**
     * A typo must fail CLOSED — an unknown scope becomes no scope, never a
     * scope that silently matches nothing later.
     */
    public function testAnEntirelyUnknownListParsesToNothing(): void
    {
        self::assertSame([], McpScopes::parse('admin:* root everything'));
    }

    public function testParseIsOrderIndependentAndDeDuplicating(): void
    {
        $a = McpScopes::parse(McpScopes::LIBRARY_READ . ' ' . McpScopes::SERVERS_READ);
        $b = McpScopes::parse(McpScopes::SERVERS_READ . '  ' . McpScopes::LIBRARY_READ . ' ' . McpScopes::LIBRARY_READ);

        self::assertSame($a, $b);
    }

    public function testParseSplitsOnAnyWhitespace(): void
    {
        $parsed = McpScopes::parse("  " . McpScopes::SERVERS_READ . "\n\t" . McpScopes::PLAYBACK_READ . "  ");

        self::assertSame([McpScopes::SERVERS_READ, McpScopes::PLAYBACK_READ], $parsed);
    }

    public function testFromArrayDropsNonStringMembersRatherThanCoercingThem(): void
    {
        $parsed = McpScopes::fromArray([McpScopes::SERVERS_READ, 0, null, ['nested'], true]);

        self::assertSame([McpScopes::SERVERS_READ], $parsed);
    }

    public function testToStorageRoundTripsThroughParse(): void
    {
        $stored = McpScopes::toStorage([McpScopes::PLAYBACK_READ, 'bogus', McpScopes::SERVERS_READ]);

        self::assertSame([McpScopes::SERVERS_READ, McpScopes::PLAYBACK_READ], McpScopes::parse($stored));
    }

    public function testIsKnownRejectsANearMiss(): void
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
    public function testTheWriteScopesAreExactlyTheNamedSet(): void
    {
        $writes = [];
        foreach (McpScopes::all() as $scope) {
            if (!str_ends_with($scope, ':read')) {
                $writes[] = $scope;
            }
        }

        // ⚠ The expectation is a LITERAL, not `McpScopes::PLAYBACK_CONTROL`.
        // Writing the constant here makes the check derive from its own subject:
        // renaming the constant renames the expectation too, and mutation M51
        // (`'mcp:playback:control'` -> `'mcp:playback:read2'`) survived exactly
        // that way. The literal is also the value stored in `mcp_tokens.scopes`
        // and pasted into an operator's client config, so changing it silently
        // invalidates every minted token.
        self::assertSame(
            ['mcp:playback:control'],
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
    public function testTheReadScopesAreStillPresent(): void
    {
        // Literals for the same reason as above: these strings are stored in the
        // database and pasted into client configs, so they are an interface, not
        // an implementation detail.
        self::assertSame(
            ['mcp:servers:read', 'mcp:library:read', 'mcp:playback:read'],
            array_values(array_filter(McpScopes::all(), static fn (string $s): bool => str_ends_with($s, ':read'))),
        );
        self::assertSame('mcp:servers:read', McpScopes::SERVERS_READ);
        self::assertSame('mcp:library:read', McpScopes::LIBRARY_READ);
        self::assertSame('mcp:playback:read', McpScopes::PLAYBACK_READ);
        self::assertSame('mcp:playback:control', McpScopes::PLAYBACK_CONTROL);
    }

    /**
     * The write scope is NOT granted by asking for the read one. They are
     * separate strings and `parse()` never promotes between them — a token
     * minted to READ playback information must not be able to stop a film.
     */
    public function testTheReadScopeDoesNotImplyTheControlScope(): void
    {
        self::assertSame([McpScopes::PLAYBACK_READ], McpScopes::parse(McpScopes::PLAYBACK_READ));
        self::assertNotContains(McpScopes::PLAYBACK_CONTROL, McpScopes::parse(McpScopes::PLAYBACK_READ));
    }

    // ------------------------------------------------------------------
    // S261 — readOnly(), the set an omitted `scopes` field grants
    // ------------------------------------------------------------------

    /**
     * `readOnly()` is an exact, ordered, named list.
     *
     * Literals for the same reason the tests above use them: this is the set a
     * caller receives for saying nothing, so it is an interface. Writing
     * `McpScopes::SERVERS_READ` here would let a constant rename move the
     * expectation along with the subject and assert nothing.
     */
    public function testReadOnlyIsExactlyTheThreeReadScopes(): void
    {
        self::assertSame(
            ['mcp:servers:read', 'mcp:library:read', 'mcp:playback:read'],
            McpScopes::readOnly(),
            'The DEFAULT GRANT for an MCP token changed. Every entry here is handed to an API '
            . 'caller who omitted `scopes` entirely, i.e. who expressed no opinion — so adding one '
            . 'widens what "no opinion" means and needs its own review.',
        );
    }

    /**
     * The write scope is absent, asserted by EXACT comparison per member.
     *
     * ⚠ Not `assertNotContains` alone and never a substring test: `mcp:playback`
     * is a prefix of `mcp:playback:control`, so a `str_contains` check would
     * also flag `mcp:playback:read` and would go on "passing" after a rename it
     * cannot see. `assertNotSame` against the whole literal is the only
     * comparison that means what this test says.
     */
    public function testReadOnlyExcludesTheWriteScopeByExactComparison(): void
    {
        foreach (McpScopes::readOnly() as $scope) {
            self::assertNotSame('mcp:playback:control', $scope);
        }

        // ...and the write scope is a real member of the vocabulary, so the
        // loop above is excluding something that exists rather than something
        // that was deleted.
        self::assertContains('mcp:playback:control', McpScopes::all());
    }

    /**
     * `readOnly()` is a SUBSET of `all()`, in `all()`'s order.
     *
     * Order is not cosmetic: `parse()` emits in `all()` order into
     * `mcp_tokens.scopes`, so a `readOnly()` that listed its members in a
     * different order would be silently re-ordered on the way to the column and
     * the minted response would not match what a caller could predict.
     */
    public function testReadOnlyIsAnOrderedSubsetOfAll(): void
    {
        $all = McpScopes::all();
        $readOnly = McpScopes::readOnly();

        foreach ($readOnly as $scope) {
            self::assertContains($scope, $all, $scope . ' is granted by default but is not a known scope.');
        }

        // Round-tripping through parse() must be the identity. If it is not,
        // readOnly() is either mis-ordered or carries something all() dropped.
        self::assertSame($readOnly, McpScopes::parse(implode(' ', $readOnly)));
        self::assertLessThan(count($all), count($readOnly), 'readOnly() must be strictly narrower than all().');
    }

    /**
     * The omission guard, and the reason `readOnly()` may safely be enumerated
     * by hand.
     *
     * `readOnly()` is a literal list so that a NEW scope stays outside the
     * default grant until somebody puts it inside — fail closed. The risk that
     * swaps in is the opposite one: a genuinely read-only scope added to
     * `all()` and forgotten here, so the default silently narrows. This catches
     * that, using the `:read` suffix convention as an INDEPENDENT cross-check
     * rather than as the implementation.
     *
     * ⚠ Deliberately one-directional. It does not assert the converse
     * (`readOnly()` ⊆ the `:read`-suffixed scopes), because that would make the
     * suffix rule authoritative again and re-open the fail-open hole the
     * enumeration exists to close.
     */
    public function testEveryReadScopeInAllIsAlsoInReadOnly(): void
    {
        $readOnly = McpScopes::readOnly();
        $missed = [];
        foreach (McpScopes::all() as $scope) {
            if (str_ends_with($scope, ':read') && !in_array($scope, $readOnly, true)) {
                $missed[] = $scope;
            }
        }

        self::assertSame(
            [],
            $missed,
            'a read scope was added to McpScopes::all() but not to readOnly(), so the default grant '
            . 'silently narrowed. Add it to readOnly() if it belongs in the default, or say in the '
            . 'docblock why it does not.',
        );
    }
}
