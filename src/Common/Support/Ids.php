<?php

/**
 * Phlix hub component: Support.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Common\Support;

use Random\RandomException;

/**
 * Cryptographically secure identifier helpers.
 *
 * Centralises identifier minting so every UUID and bearer-ish token is
 * derived from {@see random_bytes()} (CSPRNG) rather than the non-crypto
 * {@see mt_rand()} that previously backed the duplicated `generateUuid()`
 * bodies scattered across the codebase. Treat any value produced here as
 * suitable for use as a secret/bearer where the byte length is adequate.
 */
final class Ids
{
    /**
     * Build an RFC-4122 version-4 UUID from 16 CSPRNG bytes.
     *
     * The version nibble is forced to `4` and the two most-significant
     * bits of the variant byte are set to `10` (RFC 4122 variant), so the
     * canonical `xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx` shape is guaranteed
     * (where `y` is one of 8, 9, a, b).
     *
     * @throws RandomException if no appropriate randomness source is available
     */
    public static function uuidV4(): string
    {
        $bytes = random_bytes(16);

        // Set version to 4 (0100xxxx in the 7th byte).
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        // Set variant to RFC 4122 (10xxxxxx in the 9th byte).
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    /**
     * Generate a CSPRNG hex token of `$bytes` random bytes (default 32 →
     * 256 bits → 64 hex chars). Suitable for opaque bearer secrets.
     *
     * @param int<1, max> $bytes number of random bytes to draw
     *
     * @throws RandomException if no appropriate randomness source is available
     */
    public static function token(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }
}
