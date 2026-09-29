<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Typed extraction from decoded federation wire frames inside tests.
 *
 * Frames arrive as `array<string, mixed>` from `json_decode`; reading them
 * with `(string)` casts fights static analysis (mixed → string is a
 * Loud-cast violation). This helper asserts instead: a missing or non-string
 * field fails the test right there, and the narrowed value flows on typed.
 */
trait FederationFrameAssertions
{
    /**
     * @param array<string, mixed> $frame Decoded JSON frame.
     * @param string               $field Field that the wire contract says is a string.
     *
     * @return string The field value once its type has been asserted.
     */
    private static function frameStringField(array $frame, string $field): string
    {
        $value = $frame[$field] ?? null;
        Assert::assertIsString($value, sprintf('federation frame field "%s" must be a string', $field));

        return $value;
    }
}
