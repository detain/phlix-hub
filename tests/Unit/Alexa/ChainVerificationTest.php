<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Alexa;

use LogicException;
use Phlix\Hub\Alexa\ChainVerification;
use PHPUnit\Framework\TestCase;

/**
 * S90 — the certificate-chain verification result type.
 *
 * The point of this class is that "verified" and "rejected" cannot be confused,
 * so the tests are about exactly that: a rejection must not be readable as a
 * key, and reading one must be loud rather than yielding an empty string that a
 * caller would then hand to `openssl_pkey_get_public()`.
 *
 * @package Phlix\Hub\Tests\Unit\Alexa
 */
final class ChainVerificationTest extends TestCase
{
    public function testAVerifiedResultCarriesItsKeyAndCacheDeadline(): void
    {
        $result = ChainVerification::verified('-----BEGIN PUBLIC KEY-----', 1234);

        self::assertTrue($result->isVerified());
        self::assertSame('-----BEGIN PUBLIC KEY-----', $result->publicKeyPem());
        self::assertSame(1234, $result->validUntil());
    }

    public function testARejectedResultCarriesItsCodeAndDetail(): void
    {
        $result = ChainVerification::rejected('ALEXA_CERT_EXPIRED', 'leaf expired');

        self::assertFalse($result->isVerified());
        self::assertSame('ALEXA_CERT_EXPIRED', $result->errorCode());
        self::assertSame('leaf expired', $result->detail());
        self::assertSame(0, $result->validUntil());
    }

    public function testReadingTheKeyOffARejectionThrowsRatherThanReturningAnEmptyString(): void
    {
        $result = ChainVerification::rejected('ALEXA_CERT_SAN_MISMATCH', 'wrong SAN');

        $this->expectException(LogicException::class);
        $result->publicKeyPem();
    }

    public function testAVerifiedResultStillReportsASafeErrorCode(): void
    {
        // Never read by the middleware, but it must not be null-shaped either:
        // a caller that got the branch backwards should see a rejection code,
        // not a crash and not an empty string.
        self::assertSame('ALEXA_CERT_CHAIN_MALFORMED', ChainVerification::verified('k', 1)->errorCode());
        self::assertSame('', ChainVerification::verified('k', 1)->detail());
    }
}
