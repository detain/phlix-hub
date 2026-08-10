<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Mcp;

use Phlix\Hub\Mcp\McpArguments;
use Phlix\Hub\Mcp\McpInvalidArgumentsException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see McpArguments}.
 *
 * @package Phlix\Hub\Tests\Unit\Mcp
 *
 * @covers \Phlix\Hub\Mcp\McpArguments
 */
final class McpArgumentsTest extends TestCase
{
    public function testRequiredStringReturnsATrimmedValue(): void
    {
        self::assertSame('dune', McpArguments::requiredString(['query' => '  dune  '], 'query'));
    }

    /**
     * @dataProvider unusableStringProvider
     */
    public function testRequiredStringThrowsOnAnythingUnusable(mixed $value): void
    {
        $this->expectException(McpInvalidArgumentsException::class);

        /** @var array<string, mixed> $arguments */
        $arguments = ['query' => $value];
        McpArguments::requiredString($arguments, 'query');
    }

    /**
     * @return list<array{0: mixed}>
     */
    public static function unusableStringProvider(): array
    {
        return [
            'absent' => [null],
            'blank' => [''],
            'whitespace only' => ["  \t "],
            'integer' => [42],
            'array' => [['a']],
            'bool' => [true],
        ];
    }

    public function testIdAcceptsAUuid(): void
    {
        self::assertSame(
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            McpArguments::id(['server_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'], 'server_id'),
        );
    }

    /**
     * The single-segment rule. Each of these would otherwise let a tool address
     * a DIFFERENT endpoint than the one it advertises.
     *
     * @dataProvider multiSegmentIdProvider
     */
    public function testIdRejectsAnythingThatIsNotOnePathSegment(string $value): void
    {
        $this->expectException(McpInvalidArgumentsException::class);

        McpArguments::id(['media_id' => $value], 'media_id');
    }

    /**
     * @return list<array{0: string}>
     */
    public static function multiSegmentIdProvider(): array
    {
        return [
            'slash' => ['abc/subtitles/download'],
            'leading slash' => ['/abc'],
            'dot segment' => ['..'],
            'traversal' => ['../../admin/users'],
            'percent encoded slash' => ['abc%2Fdef'],
            'percent encoded dot segment' => ['%2e%2e'],
            'backslash' => ['abc\\def'],
            'space' => ['abc def'],
            'query smuggling' => ['abc?admin=1'],
            'fragment' => ['abc#frag'],
            'starts with a dot' => ['.hidden'],
            'starts with a dash' => ['-abc'],
        ];
    }

    /**
     * Control for the rejections above: a plainly legitimate id is NOT rejected,
     * so the rule is discriminating rather than "reject everything".
     *
     * @dataProvider legitimateIdProvider
     */
    public function testIdAcceptsLegitimateIdentifiers(string $value): void
    {
        self::assertSame($value, McpArguments::id(['media_id' => $value], 'media_id'));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function legitimateIdProvider(): array
    {
        return [
            'uuid' => ['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'],
            'numeric' => ['123456'],
            'underscored' => ['media_item_9'],
            'dotted' => ['tt0111161.v2'],
            'musicbrainz style' => ['5b11f4ce-a62d-471e-81fc-a69a8278c751'],
        ];
    }

    public function testBoundedIntDefaultsWhenAbsentOrUnusable(): void
    {
        self::assertSame(20, McpArguments::boundedInt([], 'limit', 20, 100));
        self::assertSame(20, McpArguments::boundedInt(['limit' => 'many'], 'limit', 20, 100));
        self::assertSame(20, McpArguments::boundedInt(['limit' => ['5']], 'limit', 20, 100));
    }

    public function testBoundedIntClampsRatherThanRejecting(): void
    {
        self::assertSame(100, McpArguments::boundedInt(['limit' => 5000], 'limit', 20, 100));
        self::assertSame(1, McpArguments::boundedInt(['limit' => 0], 'limit', 20, 100));
        self::assertSame(1, McpArguments::boundedInt(['limit' => -7], 'limit', 20, 100));
        self::assertSame(37, McpArguments::boundedInt(['limit' => 37], 'limit', 20, 100));
        self::assertSame(37, McpArguments::boundedInt(['limit' => '37'], 'limit', 20, 100));
    }

    // ------------------------------------------------------------------
    // S63
    // ------------------------------------------------------------------

    /**
     * `oneOf()` accepts a member of the closed set — and returns it verbatim.
     */
    public function testOneOfAcceptsAMemberOfTheSet(): void
    {
        self::assertSame('pause', McpArguments::oneOf(['action' => 'pause'], 'action', ['play', 'pause']));
        self::assertSame('play', McpArguments::oneOf(['action' => '  play  '], 'action', ['play', 'pause']));
    }

    /**
     * ...and REJECTS a non-member rather than defaulting to one.
     *
     * ⚠ This test exists because mutation M52 (delete the `in_array` check)
     * survived the whole suite when `oneOf()` was only exercised THROUGH
     * `PlaybackControlTool`. There, an unknown ACTION was still caught further
     * down (by the action→path table) and an unknown TARGET produced a PHP
     * warning rather than a failure — so the tool's tests could not see the
     * guard at all. The guard is tested where it lives.
     *
     * @dataProvider notInTheSetProvider
     */
    public function testOneOfRejectsAValueOutsideTheSet(mixed $value): void
    {
        $this->expectException(McpInvalidArgumentsException::class);

        McpArguments::oneOf(['action' => $value], 'action', ['play', 'pause']);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function notInTheSetProvider(): array
    {
        return [
            'a different word' => ['reboot'],
            'a prefix of a member' => ['pau'],
            'a member with a suffix' => ['pauseX'],
            'a member in the wrong case' => ['PAUSE'],
            'the empty string' => [''],
            'a number' => [7],
            'null' => [null],
            'an array' => [['pause']],
        ];
    }

    /**
     * `oneOf()` names the permitted values, so a model can correct itself in one
     * round trip rather than guessing.
     */
    public function testOneOfNamesThePermittedValuesInItsMessage(): void
    {
        try {
            McpArguments::oneOf(['action' => 'reboot'], 'action', ['play', 'pause']);
            self::fail('an out-of-set value was accepted.');
        } catch (McpInvalidArgumentsException $e) {
            self::assertStringContainsString('play, pause', $e->getMessage());
        }
    }

    /**
     * The membership test is STRICT, and that flag is load-bearing.
     *
     * `oneOf()` is a general-purpose helper, so its allowed set may one day
     * contain numeric-looking values. PHP 8's loose `==` still folds two NUMERIC
     * strings together (`'1e3' == '1000'` is true), so a non-strict `in_array()`
     * would admit a spelling the caller never listed. Mutation M55 (drop the
     * `true` flag) survives every other row in this file, because none of them
     * uses a numeric set — which is exactly why this row exists.
     */
    public function testOneOfComparesStrictlySoNumericSpellingsDoNotCollide(): void
    {
        self::assertSame('1000', McpArguments::oneOf(['action' => '1000'], 'action', ['1000']));

        $this->expectException(McpInvalidArgumentsException::class);
        McpArguments::oneOf(['action' => '1e3'], 'action', ['1000']);
    }

    public function testNonNegativeIntAcceptsWholeNumbersAndNumericStrings(): void
    {
        self::assertSame(0, McpArguments::nonNegativeInt(['p' => 0], 'p', 100));
        self::assertSame(42, McpArguments::nonNegativeInt(['p' => 42], 'p', 100));
        self::assertSame(42, McpArguments::nonNegativeInt(['p' => '42'], 'p', 100));
        self::assertSame(100, McpArguments::nonNegativeInt(['p' => 100], 'p', 100));
    }

    /**
     * ...and REJECTS rather than clamping. A clamped seek is a silent jump to a
     * timestamp nobody asked for; an error is the better answer.
     *
     * @dataProvider unusablePositionProvider
     */
    public function testNonNegativeIntRejectsRatherThanClamping(mixed $value): void
    {
        $this->expectException(McpInvalidArgumentsException::class);

        McpArguments::nonNegativeInt(['p' => $value], 'p', 100);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function unusablePositionProvider(): array
    {
        return [
            'negative' => [-1],
            'above the bound' => [101],
            'a float' => [1.5],
            'a float string' => ['1.5'],
            'a negative string' => ['-1'],
            'not a number' => ['soon'],
            'a boolean' => [true],
            'null' => [null],
            'an array' => [[1]],
            'hex' => ['0x10'],
            'exponent notation' => ['1e2'],
        ];
    }

    public function testNonNegativeIntIsRequired(): void
    {
        $this->expectException(McpInvalidArgumentsException::class);

        McpArguments::nonNegativeInt([], 'p', 100);
    }
}
