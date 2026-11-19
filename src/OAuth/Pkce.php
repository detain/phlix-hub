<?php

/**
 * Phlix hub component: OAuth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\OAuth;

use Phlix\Shared\Security\Pkce as SharedPkce;

/**
 * PKCE (RFC 7636) — **S256 only**. Thin hub delegate of the shared kernel
 * {@see SharedPkce} (wave S2 adoption; DESIGN §0 / M-02).
 *
 * This class WAS the canonical source — the shared component was extracted
 * from it verbatim — so every method here forwards and every constant
 * aliases the shared value. The wire law is unchanged:
 *
 *  - `code_challenge_method=plain` is rejected;
 *  - `code_challenge_method` omitted is rejected — the RFC's `plain` default
 *    is deliberately NOT honoured, because under `plain` the challenge IS the
 *    verifier and an attacker who can read the authorization request
 *    therefore chooses whether PKCE applies;
 *  - at the token endpoint a missing `code_verifier` is rejected outright
 *    rather than being treated as "this client isn't using PKCE";
 *  - every comparison in the kernel is `hash_equals()`, never `===`.
 *
 * ⚠ Do not re-widen {@see isSupportedMethod()} in the kernel or here; the
 * kernel docblock carries the full argument, and the suite's call-shape pins
 * here keep it enforced at the hub boundary.
 *
 * @package Phlix\Hub\OAuth
 * @since   S92 (shared OAuth 2.0 Authorization Server)
 * @see     \Phlix\Shared\Security\Pkce for the single-source implementation
 */
final class Pkce
{
    /** The ONLY challenge method this server accepts (shared SSOT alias). */
    public const string METHOD_S256 = SharedPkce::METHOD_S256;

    /** The method this server deliberately does NOT accept (shared SSOT alias). */
    public const string METHOD_PLAIN = SharedPkce::METHOD_PLAIN;

    /** RFC 7636 §4.1 minimum `code_verifier` length (shared SSOT alias). */
    public const int MIN_VERIFIER_LENGTH = SharedPkce::MIN_VERIFIER_LENGTH;

    /** RFC 7636 §4.1 maximum `code_verifier` length (shared SSOT alias). */
    public const int MAX_VERIFIER_LENGTH = SharedPkce::MAX_VERIFIER_LENGTH;

    /** Length of a valid unpadded base64url S256 challenge (shared SSOT alias). */
    public const int CHALLENGE_LENGTH = SharedPkce::CHALLENGE_LENGTH;

    /**
     * Whether `$method` is a challenge method this server will honour.
     *
     * True for `S256` and for nothing else — see the class docblock.
     *
     * @param string $method The raw `code_challenge_method` parameter.
     */
    public static function isSupportedMethod(string $method): bool
    {
        return SharedPkce::isSupportedMethod($method);
    }

    /**
     * Whether `$verifier` is a syntactically valid RFC 7636 `code_verifier`:
     * 43–128 characters drawn from the unreserved set `[A-Za-z0-9-._~]`.
     *
     * @param string $verifier The raw `code_verifier` parameter.
     */
    public static function isValidVerifier(string $verifier): bool
    {
        return SharedPkce::isValidVerifier($verifier);
    }

    /**
     * Whether `$challenge` has the exact shape an S256 challenge must have:
     * 43 base64url characters, unpadded.
     *
     * @param string $challenge The raw `code_challenge` parameter.
     */
    public static function isValidChallenge(string $challenge): bool
    {
        return SharedPkce::isValidChallenge($challenge);
    }

    /**
     * Derive the S256 challenge for a verifier:
     * `BASE64URL(SHA-256(ASCII(code_verifier)))`, unpadded.
     *
     * @param string $verifier The `code_verifier`.
     *
     * @return string The 43-character base64url challenge.
     */
    public static function challengeFor(string $verifier): string
    {
        return SharedPkce::challengeFor($verifier);
    }

    /**
     * Whether `$verifier` proves possession of the secret behind `$challenge`.
     *
     * Returns false — never throws — so every caller fails closed on any
     * input at all.
     *
     * @param string $verifier  The `code_verifier` presented at the token endpoint.
     * @param string $challenge The `code_challenge` bound to the authorization code.
     */
    public static function verify(string $verifier, string $challenge): bool
    {
        return SharedPkce::verify($verifier, $challenge);
    }
}
