<?php

/**
 * Phlix hub component: Support.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Common\Support;

use Phlix\Shared\Database\Uuid;
use Random\RandomException;

/**
 * Cryptographically secure identifier helpers.
 *
 * Centralises identifier minting so every UUID and bearer-ish token is
 * derived from a CSPRNG rather than the non-crypto {@see mt_rand()} that
 * previously backed the duplicated `generateUuid()` bodies scattered
 * across the codebase. The mint bodies themselves are shared-kernel
 * {@see Uuid} (v0.57 S443/M-9 leg): this class stays the hub's naming
 * seam — the one auditable surface where "every UUID and token this hub
 * mints" is enumerable — and delegates entropy there so no second
 * entropy seam can drift. Treat any value produced here as suitable for
 * use as a secret/bearer where the byte length is adequate.
 */
final class Ids
{
    /**
     * Build an RFC-4122 version-4 UUID from 16 CSPRNG bytes.
     *
     * Delegates to the shared {@see Uuid::v4()} kernel: the version
     * nibble is forced to `4` and the two most-significant bits of the
     * variant byte are set to `10` (RFC 4122 variant), so the canonical
     * `xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx` shape is guaranteed
     * (where `y` is one of 8, 9, a, b) and draws cannot be steered by
     * `mt_srand()`.
     *
     * @throws RandomException if no appropriate randomness source is available
     */
    public static function uuidV4(): string
    {
        return Uuid::v4();
    }

    /**
     * Generate a CSPRNG hex token of `$bytes` random bytes (default 32 →
     * 256 bits → 64 hex chars). Suitable for opaque bearer secrets.
     *
     * Delegates to the shared {@see Uuid::randomHex()} kernel — the same
     * one-liner over the same CSPRNG this method used to carry verbatim.
     *
     * @param int<1, max> $bytes number of random bytes to draw
     *
     * @throws RandomException if no appropriate randomness source is available
     */
    public static function token(int $bytes = 32): string
    {
        return Uuid::randomHex($bytes);
    }
}
