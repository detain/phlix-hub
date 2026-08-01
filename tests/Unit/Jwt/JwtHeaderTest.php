<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Jwt;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\Jwt\JwtHeader;

/**
 * Unit tests for {@see JwtHeader}.
 *
 * @package Phlix\Hub\Tests\Unit\Jwt
 *
 * @covers \Phlix\Hub\Jwt\JwtHeader
 */
final class JwtHeaderTest extends TestCase
{
    /**
     * Build a minimal three-segment JWT with a given header JSON.
     */
    private function buildJwt(array $header): string
    {
        $b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        return $b64((string) json_encode($header)) . '.' . $b64('payload') . '.' . $b64('signature');
    }

    public function testKidReturnsKidWhenPresent(): void
    {
        $jwt = $this->buildJwt(['alg' => 'EdDSA', 'kid' => 'my-key-id']);

        $kid = JwtHeader::kid($jwt);

        self::assertSame('my-key-id', $kid);
    }

    public function testKidReturnsNullWhenHeaderHasNoKid(): void
    {
        $jwt = $this->buildJwt(['alg' => 'EdDSA']);

        $kid = JwtHeader::kid($jwt);

        self::assertNull($kid);
    }

    public function testKidReturnsNullWhenKidIsEmptyString(): void
    {
        $jwt = $this->buildJwt(['alg' => 'EdDSA', 'kid' => '']);

        $kid = JwtHeader::kid($jwt);

        self::assertNull($kid);
    }

    public function testKidReturnsNullWhenKidIsNotAString(): void
    {
        $jwt = $this->buildJwt(['alg' => 'EdDSA', 'kid' => 123]);

        $kid = JwtHeader::kid($jwt);

        self::assertNull($kid);
    }

    public function testKidReturnsNullForMalformedJwt(): void
    {
        self::assertNull(JwtHeader::kid('not-a-jwt'));
        self::assertNull(JwtHeader::kid('only-two-segments.here'));
        self::assertNull(JwtHeader::kid('single'));
    }

    public function testKidReturnsNullForInvalidBase64(): void
    {
        // Valid structure but invalid base64 in the header segment
        $jwt = '!!!invalid!!!.payload.signature';

        $kid = JwtHeader::kid($jwt);

        self::assertNull($kid);
    }

    public function testKidReturnsNullForInvalidJsonInHeader(): void
    {
        $b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        // Valid base64 but not JSON
        $jwt = $b64('not-json') . '.payload.signature';

        $kid = JwtHeader::kid($jwt);

        self::assertNull($kid);
    }

    public function testKidHandlesMultipleKidOccurrences(): void
    {
        // JSON parse takes last when multiple 'kid' keys exist
        $jwt = $this->buildJwt(['alg' => 'EdDSA', 'kid' => 'first', 'kid' => 'last']);

        $kid = JwtHeader::kid($jwt);

        self::assertSame('last', $kid);
    }

    public function testKidHandlesJwtWithNoAdditionalHeaderFields(): void
    {
        $jwt = $this->buildJwt(['kid' => 'only-field']);

        $kid = JwtHeader::kid($jwt);

        self::assertSame('only-field', $kid);
    }

    public function testKidHandlesBase64PaddingVariants(): void
    {
        $b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        // JWT with URL-safe base64 (already uses -_ instead of +/)
        $headerJson = json_encode(['alg' => 'EdDSA', 'kid' => 'test-key']);
        $headerB64 = rtrim(strtr(base64_encode((string) $headerJson), '+/', '-_'), '=');
        $jwt = $headerB64 . '.payload.signature';

        $kid = JwtHeader::kid($jwt);

        self::assertSame('test-key', $kid);
    }
}
