<?php

/**
 * Phlix hub component: Federation.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Federation;

use InvalidArgumentException;
use SodiumException;

use function base64_decode;
use function base64_encode;
use function hex2bin;
use function preg_match;
use function random_bytes;
use function rtrim;
use function sodium_crypto_sign_detached;
use function sodium_crypto_sign_verify_detached;
use function str_repeat;
use function strtr;
use function strlen;
use function strtolower;

/**
 * Canonical byte strings + Ed25519 proofs for the federation handshake (H-4).
 *
 * Peer identity used to be knowledge-of-identifier: HELLO carried a
 * self-declared `public_key` string, and any hub that knew that (public)
 * value completed the handshake. This class is the SINGLE definition of the
 * material each side signs, so master and leaf can never drift:
 *
 *   - HELLO_ACK proof:  master signs {session_id, master_hub_id, nonce}
 *                       with its own Ed25519 hub key.
 *   - HELLO_AUTH proof: leaf signs {session_id, nonce, leaf_hub_id} with
 *                       the private half of its REGISTERED public_key, and
 *                       echoes the master's fresh per-session nonce back —
 *                       binding the proof to exactly one handshake session.
 *
 * Both proofs are sodium "detached" Ed25519 signatures, base64-encoded on
 * the wire. Verification keys are read from the peer rows' `public_key`
 * column, which has always been an operator-supplied string — see
 * {@see normalizePublicKey()} for the accepted encodings.
 *
 * @package Phlix\Hub\Federation
 */
final class FederationHandshake
{
    /** Domain-separation prefix for the HELLO_ACK proof (never on the wire). */
    private const HELLO_ACK_DOMAIN = 'phlix-federation/hello-ack/v1';

    /** Domain-separation prefix for the HELLO_AUTH proof (never on the wire). */
    private const HELLO_AUTH_DOMAIN = 'phlix-federation/hello-auth/v1';

    /** Ed25519 verification key length in bytes (sodium SIGNED_PUBLICKEYBYTES). */
    private const PUBLIC_KEY_BYTES = 32;

    /** Detached Ed25519 signature length in bytes (sodium SIGNED_BYTES). */
    private const SIGNATURE_BYTES = 64;

    /**
     * Generate a fresh handshake nonce (32 random bytes, base64url, no padding).
     *
     * The nonce is minted by the master at HELLO time, lives in the session's
     * pending-handshake state, and is consumed (single-use) when the leaf's
     * HELLO_AUTH proof is checked — replays of a completed proof fail.
     *
     * @return string Nonce token.
     */
    public static function newNonce(): string
    {
        return self::base64UrlEncode(random_bytes(self::PUBLIC_KEY_BYTES));
    }

    /**
     * Canonical bytes a master signs for HELLO_ACK: {session_id, master_hub_id, nonce}.
     *
     * @param string $sessionId  Federation session UUID minted for this handshake.
     * @param string $masterHubId Master hub's own federation_hubs.id ('' when unconfigured).
     * @param string $nonce      Fresh per-session nonce carried in the same ACK.
     *
     * @return string Exact byte string fed to the Ed25519 signer.
     */
    public static function helloAckCanonical(string $sessionId, string $masterHubId, string $nonce): string
    {
        return self::HELLO_ACK_DOMAIN . "\n" . $sessionId . "\n" . $masterHubId . "\n" . $nonce;
    }

    /**
     * Canonical bytes a leaf signs for HELLO_AUTH: {session_id, nonce, leaf_hub_id}.
     *
     * @param string $sessionId  Session UUID from the verified HELLO_ACK.
     * @param string $nonce      Nonce echoed from that same HELLO_ACK.
     * @param string $leafHubId  This leaf's own federation_hubs.id.
     *
     * @return string Exact byte string fed to the Ed25519 signer.
     */
    public static function helloAuthCanonical(string $sessionId, string $nonce, string $leafHubId): string
    {
        return self::HELLO_AUTH_DOMAIN . "\n" . $sessionId . "\n" . $nonce . "\n" . $leafHubId;
    }

    /**
     * Sign canonical bytes with a raw 64-byte sodium secret key; base64 for the wire.
     *
     * @param string $canonical Bytes from {@see helloAckCanonical()}/{@see helloAuthCanonical()}.
     * @param string $secretKey Raw Ed25519 secret key (sodium SIGNED_SECRETKEYBYTES).
     *
     * @return string Standard-base64 detached signature.
     */
    public static function sign(string $canonical, string $secretKey): string
    {
        if ($secretKey === '') {
            throw new InvalidArgumentException('FederationHandshake::sign() requires a non-empty Ed25519 secret key.');
        }

        return base64_encode(sodium_crypto_sign_detached($canonical, $secretKey));
    }

    /**
     * Verify a base64 detached signature against an encoded verification key.
     *
     * Fail-closed: undecodable signature, un-normalizable key, or a wrong
     * signature all return false — they never throw into the frame loop.
     *
     * @param string $canonical         Signed canonical bytes.
     * @param string $signatureBase64   Wire signature (base64, standard or base64url).
     * @param string $encodedPublicKey  Peer-row verification key (see {@see normalizePublicKey()}).
     *
     * @return bool True only when the signature is valid for that key.
     */
    public static function verify(string $canonical, string $signatureBase64, string $encodedPublicKey): bool
    {
        $publicKey = self::normalizePublicKey($encodedPublicKey);
        if ($publicKey === null) {
            return false;
        }

        $signature = self::decodeBase64Tolerant($signatureBase64);
        if ($signature === null || strlen($signature) !== self::SIGNATURE_BYTES) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($signature, $canonical, $publicKey);
        } catch (SodiumException) {
            return false;
        }
    }

    /**
     * Decode a peer-row Ed25519 verification key to its raw 32 bytes.
     *
     * `federation_peers.public_key` is an operator-supplied string with no
     * mandated encoding, so every reasonable encoding is accepted:
     * raw 32-byte binary, lowercase/uppercase hex, standard base64, and
     * base64url (with or without '=' padding). Anything that does not
     * decode to exactly 32 bytes is rejected (null).
     *
     * @param string $encoded Stored public key in any accepted encoding.
     *
     * @return non-empty-string|null Raw 32-byte verification key, or null when invalid.
     */
    public static function normalizePublicKey(string $encoded): ?string
    {
        if ($encoded === '') {
            return null;
        }

        // Raw binary key (the only legal interpretation of a 32-byte string:
        // base64 of 32 bytes is 43/44 chars, hex of 32 bytes is 64).
        if (strlen($encoded) === self::PUBLIC_KEY_BYTES) {
            return $encoded;
        }

        // Hex encoding wins over base64 for the ambiguous 64-char length
        // (both can be 64 chars; hex is the more explicit claim).
        if (preg_match('/^[0-9a-fA-F]{64}$/', $encoded) === 1) {
            $raw = hex2bin(strtolower($encoded));
            if ($raw === false || $raw === '') {
                return null;
            }
            return $raw;
        }

        $decoded = self::decodeBase64Tolerant($encoded);
        if ($decoded !== null && strlen($decoded) === self::PUBLIC_KEY_BYTES) {
            return $decoded;
        }

        return null;
    }

    /**
     * Base64-url encode without padding.
     *
     * @param string $bytes Raw bytes.
     *
     * @return string base64url token.
     */
    public static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * Tolerant base64 decode (standard or base64url, padding optional).
     *
     * @param string $encoded Candidate base64/base64url text.
     *
     * @return non-empty-string|null Raw bytes, or null when the input is not valid base64.
     */
    private static function decodeBase64Tolerant(string $encoded): ?string
    {
        if ($encoded === '') {
            return null;
        }

        $normalized = strtr($encoded, '-_', '+/');
        $remainder = strlen($normalized) % 4;
        if ($remainder === 1) {
            return null;
        }
        if ($remainder > 0) {
            $normalized .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($normalized, true);
        if ($decoded === false || $decoded === '') {
            return null;
        }

        return $decoded;
    }
}
