<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Real run-time narrowing of `mixed` test values (decoded JSON, DB rows, glob()).
 *
 * PHPUnit 12 annotates `assertIsArray()` as `array<mixed>` (explicit mixed), so the
 * assertion no longer launders elements for PHPStan level 9 the way PHPUnit 10's bare
 * `array` did. Each helper here asserts the shape and returns the narrowed value, so a
 * malformed node fails loudly at the offending read instead of being cast away.
 */
final class Narrow
{
    /**
     * @return array<array-key, mixed>
     */
    public static function arr(mixed $node): array
    {
        Assert::assertIsArray($node);

        return $node;
    }

    /**
     * A DB result set: a list of associative rows.
     *
     * @return array<array-key, array<array-key, mixed>>
     */
    public static function rows(mixed $node): array
    {
        Assert::assertIsArray($node);
        foreach ($node as $row) {
            Assert::assertIsArray($row);
        }

        /** @var array<array-key, array<array-key, mixed>> $node */
        return $node;
    }

    public static function string(mixed $node): string
    {
        Assert::assertIsString($node);

        return $node;
    }

    /**
     * (string) cast of a scalar-or-null node (DB column values, JSON scalars).
     */
    public static function castString(mixed $node): string
    {
        Assert::assertTrue(
            $node === null || is_scalar($node) || $node instanceof \Stringable,
            'expected a scalar-or-null node',
        );

        return (string) $node;
    }

    /**
     * (int) cast of a scalar-or-null node (DB COUNT(*) columns arrive as strings or ints).
     */
    public static function castInt(mixed $node): int
    {
        Assert::assertTrue($node === null || is_scalar($node), 'expected a scalar-or-null node');

        return (int) $node;
    }

    /**
     * @return list<string>
     */
    public static function globFiles(string $pattern): array
    {
        $found = glob($pattern);
        Assert::assertIsArray($found);
        Assert::assertNotEmpty($found, 'no file matches ' . $pattern);

        return $found;
    }
}
