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
    public function test_required_string_returns_a_trimmed_value(): void
    {
        self::assertSame('dune', McpArguments::requiredString(['query' => '  dune  '], 'query'));
    }

    /**
     * @dataProvider unusableStringProvider
     */
    public function test_required_string_throws_on_anything_unusable(mixed $value): void
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

    public function test_id_accepts_a_uuid(): void
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
    public function test_id_rejects_anything_that_is_not_one_path_segment(string $value): void
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
    public function test_id_accepts_legitimate_identifiers(string $value): void
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

    public function test_bounded_int_defaults_when_absent_or_unusable(): void
    {
        self::assertSame(20, McpArguments::boundedInt([], 'limit', 20, 100));
        self::assertSame(20, McpArguments::boundedInt(['limit' => 'many'], 'limit', 20, 100));
        self::assertSame(20, McpArguments::boundedInt(['limit' => ['5']], 'limit', 20, 100));
    }

    public function test_bounded_int_clamps_rather_than_rejecting(): void
    {
        self::assertSame(100, McpArguments::boundedInt(['limit' => 5000], 'limit', 20, 100));
        self::assertSame(1, McpArguments::boundedInt(['limit' => 0], 'limit', 20, 100));
        self::assertSame(1, McpArguments::boundedInt(['limit' => -7], 'limit', 20, 100));
        self::assertSame(37, McpArguments::boundedInt(['limit' => 37], 'limit', 20, 100));
        self::assertSame(37, McpArguments::boundedInt(['limit' => '37'], 'limit', 20, 100));
    }
}
