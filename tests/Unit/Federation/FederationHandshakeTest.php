<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Federation;

use Phlix\Hub\Federation\FederationHandshake;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see FederationHandshake} — the single source of truth for
 * the H-4 signed-ceremony wire canonicals shared by the master
 * (FederationFrameHandler) and leaf (FederationPeerManager) legs.
 *
 * Canonical STRINGS are pinned literally on purpose: both ends of the estate
 * sign/verify these exact bytes, so any drift here is a wire break and must
 * fail loudly in this test rather than silently in production.
 *
 * @package Phlix\Hub\Tests\Unit\Federation
 */
final class FederationHandshakeTest extends TestCase
{
    public function testHelloAckCanonicalIsThePinnedWireShape(): void
    {
        self::assertSame(
            "phlix-federation/hello-ack/v1\nsess-1\nmaster-hub-1\nnonce-1",
            FederationHandshake::helloAckCanonical('sess-1', 'master-hub-1', 'nonce-1'),
        );
    }

    public function testHelloAuthCanonicalIsThePinnedWireShape(): void
    {
        self::assertSame(
            "phlix-federation/hello-auth/v1\nsess-1\nnonce-1\nleaf-hub-1",
            FederationHandshake::helloAuthCanonical('sess-1', 'nonce-1', 'leaf-hub-1'),
        );
    }

    public function testCanonicalsCannotCollideAcrossLegs(): void
    {
        // Same field VALUES, different legs — domains keep the byte strings
        // disjoint so a HELLO_AUTH proof can never be replayed as an ACK.
        $ack = FederationHandshake::helloAckCanonical('x', 'y', 'z');
        $auth = FederationHandshake::helloAuthCanonical('x', 'z', 'y');
        self::assertNotSame($ack, $auth);
    }

    public function testNewNonceIsUrlSafeUniqueAndWellSized(): void
    {
        $seen = [];
        for ($i = 0; $i < 50; $i++) {
            $nonce = FederationHandshake::newNonce();
            // 32 random bytes → 43 base64url chars, no padding.
            self::assertSame(43, strlen($nonce));
            self::assertSame(1, preg_match('~^[A-Za-z0-9_-]+$~', $nonce), 'nonce must be base64url-safe');
            self::assertArrayNotHasKey($nonce, $seen, 'nonces must never repeat');
            $seen[$nonce] = true;
        }
    }

    public function testSignVerifyRoundTripOnRealKeypair(): void
    {
        $kp = sodium_crypto_sign_keypair();
        $canonical = FederationHandshake::helloAckCanonical('s', 'm', 'n');
        $signature = FederationHandshake::sign($canonical, sodium_crypto_sign_secretkey($kp));

        self::assertSame(88, strlen($signature), '64-byte signature as plain base64');
        self::assertTrue(FederationHandshake::verify(
            $canonical,
            $signature,
            base64_encode(sodium_crypto_sign_publickey($kp)),
        ));
    }

    /**
     * Registered keys arrive in whatever encoding the operator stored — the
     * verifier accepts raw, hex (lower/upper), base64 and base64url forms of
     * the SAME 32-byte key.
     */
    public function testVerifyAcceptsEveryPublicKeyEncoding(): void
    {
        $kp = sodium_crypto_sign_keypair();
        $pub = sodium_crypto_sign_publickey($kp);
        $canonical = FederationHandshake::helloAuthCanonical('s', 'n', 'l');
        $signature = FederationHandshake::sign($canonical, sodium_crypto_sign_secretkey($kp));

        $url = rtrim(strtr(base64_encode($pub), '+/', '-_'), '=');
        foreach (
            [
            'raw' => $pub,
            'hex' => bin2hex($pub),
            'hex-upper' => strtoupper(bin2hex($pub)),
            'base64' => base64_encode($pub),
            'base64url' => $url,
            ] as $label => $encoded
        ) {
            self::assertTrue(
                FederationHandshake::verify($canonical, $signature, $encoded),
                "verify() must accept {$label}-encoded public keys",
            );
        }
    }

    public function testVerifyFailsOnTamperedMessage(): void
    {
        $kp = sodium_crypto_sign_keypair();
        $signature = FederationHandshake::sign('canonical-A', sodium_crypto_sign_secretkey($kp));

        self::assertFalse(FederationHandshake::verify(
            'canonical-B',
            $signature,
            base64_encode(sodium_crypto_sign_publickey($kp)),
        ));
    }

    public function testVerifyFailsOnWrongKey(): void
    {
        $real = sodium_crypto_sign_keypair();
        $other = sodium_crypto_sign_keypair();
        $canonical = FederationHandshake::helloAckCanonical('s', 'm', 'n');

        self::assertFalse(FederationHandshake::verify(
            $canonical,
            FederationHandshake::sign($canonical, sodium_crypto_sign_secretkey($other)),
            base64_encode(sodium_crypto_sign_publickey($real)),
        ));
    }

    public function testVerifyFailsLoudlyOnMalformedInputs(): void
    {
        $kp = sodium_crypto_sign_keypair();
        $pubB64 = base64_encode(sodium_crypto_sign_publickey($kp));
        $valid = FederationHandshake::sign('c', sodium_crypto_sign_secretkey($kp));

        self::assertFalse(FederationHandshake::verify('c', '', $pubB64), 'empty signature');
        self::assertFalse(FederationHandshake::verify('c', '!!!not base64!!!', $pubB64), 'undecodable signature');
        self::assertFalse(FederationHandshake::verify('c', base64_encode('short'), $pubB64), 'sub-64B signature');
        self::assertFalse(FederationHandshake::verify('c', $valid, ''), 'empty public key');
        self::assertFalse(FederationHandshake::verify('c', $valid, 'too-short-key'), 'unparseable public key');
    }

    public function testNormalizePublicKeyMatrix(): void
    {
        $pub = random_bytes(32);

        self::assertSame($pub, FederationHandshake::normalizePublicKey($pub), 'raw 32B passes through');
        self::assertSame($pub, FederationHandshake::normalizePublicKey(bin2hex($pub)), 'lower hex');
        self::assertSame($pub, FederationHandshake::normalizePublicKey(strtoupper(bin2hex($pub))), 'upper hex');
        self::assertSame($pub, FederationHandshake::normalizePublicKey(base64_encode($pub)), 'base64');
        self::assertSame(
            $pub,
            FederationHandshake::normalizePublicKey(rtrim(strtr(base64_encode($pub), '+/', '-_'), '=')),
            'base64url without padding',
        );
        self::assertNull(FederationHandshake::normalizePublicKey(''), 'empty rejected');
        self::assertNull(FederationHandshake::normalizePublicKey('not-a-key-at-all'), 'garbage rejected');
        self::assertNull(FederationHandshake::normalizePublicKey(random_bytes(16)), 'wrong-length raw rejected');
        self::assertNull(
            FederationHandshake::normalizePublicKey(base64_encode(random_bytes(31))),
            'wrong-length base64 rejected',
        );
    }

    public function testBase64UrlEncodePadsNothingStaysUrlSafe(): void
    {
        $encoded = FederationHandshake::base64UrlEncode(str_repeat("\xff", 32));

        self::assertStringNotContainsString('=', $encoded);
        self::assertStringNotContainsString('+', $encoded);
        self::assertStringNotContainsString('/', $encoded);
        self::assertSame(
            str_repeat("\xff", 32),
            base64_decode(strtr($encoded, '-_', '+/') . str_repeat('=', (4 - strlen($encoded) % 4) % 4), true),
        );
    }
}
