<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Typed access to decoded JSON response bodies in HTTP controller tests.
 *
 * `json_decode($body, true)` yields `mixed`, so array-accessing the result is
 * untyped at PHPStan level 9. Routing every access through {@see self::arrayNode()}
 * turns each hop into a real assertion: a malformed body fails loudly at the
 * offending node instead of silently comparing nulls downstream.
 */
trait DecodedJsonAssertions
{
    /**
     * Assert a decoded-JSON node is an array and return it typed.
     *
     * @return array<array-key, mixed>
     */
    protected static function arrayNode(mixed $node): array
    {
        Assert::assertIsArray($node);

        return $node;
    }

    /**
     * Assert a decoded-JSON node is a string and return it typed.
     */
    protected static function stringNode(mixed $node): string
    {
        Assert::assertIsString($node);

        return $node;
    }
}
