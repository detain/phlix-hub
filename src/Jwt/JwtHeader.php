<?php

/**
 * Phlix hub component: Jwt.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Jwt;

use JsonException;
use Phlix\Shared\Security\Base64Url;

/**
 * Parses JWT header bytes without validating the token signature.
 *
 * Provides a single, testable entry point for the `kid` (Key ID) that
 * appears in the JWT header JSON. All Ed25519 enrollment JWT handling
 * across the hub extracts this value identically — this class eliminates
 * the duplicated parse-logic that previously lived in 8 separate files.
 *
 * The segment decode is the shared {@see Base64Url} STRICT arm since wave
 * S2 — out-of-alphabet input collapses to `''`, which the JSON parse below
 * rejects exactly as the historical `false` check did.
 *
 * @package Phlix\Hub\Jwt
 */
final class JwtHeader
{
    /**
     * Extract the `kid` from a JWT header.
     *
     * @param string $token A signed JWT string (three dot-separated base64url segments).
     *
     * @return string|null Key ID when present and a non-empty string, null when
     *                     the token is malformed or the header lacks a `kid`.
     */
    public static function kid(string $token): ?string
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        try {
            /** @var array<string, mixed> $header */
            $header = json_decode(Base64Url::decode($parts[0], true), true, 2, JSON_THROW_ON_ERROR);
            /** @var string|null $kid */
            $kid = $header['kid'] ?? null;

            return is_string($kid) && $kid !== '' ? $kid : null;
        } catch (JsonException) {
            return null;
        }
    }
}
