<?php

/**
 * Phlix hub component: OAuth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\OAuth;

use function base64_encode;
use function hash;
use function hash_equals;
use function preg_match;
use function rtrim;
use function strlen;
use function strtr;

/**
 * PKCE (RFC 7636) — **S256 only**.
 *
 * ## The one thing to understand about this class
 *
 * RFC 7636 §4.4 says that when `code_challenge_method` is absent the server
 * MUST default to `plain`, and §4.2 says a server MAY support `plain`. This hub
 * does neither. {@see isSupportedMethod()} recognises exactly one string, and
 * {@see \Phlix\Hub\Http\Controllers\OAuthController::authorize()} demands the
 * parameter be present, so:
 *
 *  - `code_challenge_method=plain` is rejected;
 *  - `code_challenge_method` omitted is rejected — it does NOT fall back to
 *    `plain`, which is the trap the RFC's own default sets;
 *  - and at the token endpoint a missing `code_verifier` is rejected outright
 *    rather than being treated as "this client isn't using PKCE".
 *
 * `plain` is worthless against the attack PKCE exists to stop: an attacker who
 * can intercept the authorization response can read `code_challenge` out of the
 * request that produced it and, under `plain`, that string IS the verifier.
 * Supporting it means an attacker chooses whether PKCE applies.
 *
 * ⚠ Do not "simplify" {@see isSupportedMethod()} into
 * `in_array($method, ['S256', 'plain'], true)` or into a case-insensitive
 * compare. `s256` lower-cased is not the RFC's method name, and accepting it
 * widens the set of strings that reach {@see verify()}.
 *
 * ## Comparisons
 *
 * Every comparison in this file is {@see hash_equals()}, not `===`. The values
 * are secrets (or derived from one) and the constant-time compare is the
 * house convention for them; it also makes it structurally obvious that no
 * comparison here is a prefix or substring test.
 *
 * @package Phlix\Hub\OAuth
 * @since   S92 (shared OAuth 2.0 Authorization Server)
 */
final class Pkce
{
    /** The ONLY challenge method this Authorization Server accepts. */
    public const string METHOD_S256 = 'S256';

    /**
     * The method this server deliberately does NOT accept.
     *
     * Declared so the rejection can be named in tests and in the
     * `error_description` a client sees, rather than being a bare string
     * literal that nothing points at.
     */
    public const string METHOD_PLAIN = 'plain';

    /** RFC 7636 §4.1 minimum `code_verifier` length. */
    public const int MIN_VERIFIER_LENGTH = 43;

    /** RFC 7636 §4.1 maximum `code_verifier` length. */
    public const int MAX_VERIFIER_LENGTH = 128;

    /**
     * Length of a base64url-encoded, unpadded SHA-256 digest — the exact and
     * only length a valid S256 `code_challenge` can have.
     */
    public const int CHALLENGE_LENGTH = 43;

    /**
     * Whether `$method` is a challenge method this server will honour.
     *
     * True for `S256` and for nothing else — see the class docblock for why
     * `plain` and the omitted-parameter default are both absent.
     *
     * @param string $method The raw `code_challenge_method` parameter.
     */
    public static function isSupportedMethod(string $method): bool
    {
        return hash_equals(self::METHOD_S256, $method);
    }

    /**
     * Whether `$verifier` is a syntactically valid RFC 7636 `code_verifier`:
     * 43–128 characters drawn from the unreserved set `[A-Za-z0-9-._~]`.
     *
     * Checked before {@see verify()} does any hashing so that a short or
     * out-of-alphabet verifier is refused on its own terms. Without the length
     * floor a client could present a one-character verifier and PKCE would be
     * brute-forceable in the time it takes to burn the code.
     *
     * @param string $verifier The raw `code_verifier` parameter.
     */
    public static function isValidVerifier(string $verifier): bool
    {
        $length = strlen($verifier);
        if ($length < self::MIN_VERIFIER_LENGTH || $length > self::MAX_VERIFIER_LENGTH) {
            return false;
        }

        // ⚠ `\A` / `\z`, not `^` / `$`. In PCRE `$` also matches immediately
        // BEFORE a final newline, so `/^[A-Za-z0-9\-._~]+$/` accepts
        // "aaa…aaa\n" — a verifier carrying a trailing newline would have been
        // treated as being in the unreserved alphabet. Caught by
        // `PkceTest::testVerifyRefusesAVerifierOutsideTheUnreservedAlphabet`.
        return preg_match('/\A[A-Za-z0-9\-._~]+\z/', $verifier) === 1;
    }

    /**
     * Whether `$challenge` has the exact shape an S256 challenge must have:
     * 43 base64url characters, unpadded.
     *
     * Enforced at the authorize endpoint so a malformed challenge is caught
     * while the user is still in front of a browser, rather than one round trip
     * later when the token request cannot possibly match it.
     *
     * @param string $challenge The raw `code_challenge` parameter.
     */
    public static function isValidChallenge(string $challenge): bool
    {
        if (strlen($challenge) !== self::CHALLENGE_LENGTH) {
            return false;
        }

        // `\A` / `\z` for the same reason as {@see isValidVerifier()} — though
        // the exact-length check above already excludes a trailing newline here,
        // the two guards must not depend on each other to be correct.
        return preg_match('/\A[A-Za-z0-9\-_]+\z/', $challenge) === 1;
    }

    /**
     * Derive the S256 challenge for a verifier:
     * `BASE64URL(SHA256(ASCII(code_verifier)))`, unpadded.
     *
     * @param string $verifier The `code_verifier`.
     *
     * @return string The 43-character base64url challenge.
     */
    public static function challengeFor(string $verifier): string
    {
        $digest = hash('sha256', $verifier, true);

        return rtrim(strtr(base64_encode($digest), '+/', '-_'), '=');
    }

    /**
     * Whether `$verifier` proves possession of the secret behind `$challenge`.
     *
     * Refuses a syntactically invalid verifier before hashing, then compares in
     * constant time. Returns false — never throws — so every caller fails
     * closed on any input at all.
     *
     * @param string $verifier  The `code_verifier` presented at the token endpoint.
     * @param string $challenge The `code_challenge` bound to the authorization code.
     */
    public static function verify(string $verifier, string $challenge): bool
    {
        if (!self::isValidVerifier($verifier)) {
            return false;
        }
        if (!self::isValidChallenge($challenge)) {
            return false;
        }

        return hash_equals($challenge, self::challengeFor($verifier));
    }
}
