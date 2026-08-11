<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Auth;

use InvalidArgumentException;
use Phlix\Hub\Auth\JwtHandler;
use Phlix\Shared\Auth\JwtClaims;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see JwtHandler}.
 *
 * @package Phlix\Hub\Tests\Unit\Auth
 */
final class JwtHandlerTest extends TestCase
{
    private const SECRET = 'a-pretty-long-test-secret-that-is-32+-bytes';

    public function testConstructorRejectsShortSecret(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new JwtHandler('too-short');
    }

    public function testCreateAccessTokenRoundTripsJwtClaims(): void
    {
        $handler = new JwtHandler(self::SECRET);
        $token = $handler->createAccessToken('user-123', ['library:read'], 'server-1');

        $claims = $handler->validateToken($token);
        self::assertInstanceOf(JwtClaims::class, $claims);
        self::assertSame('phlix-hub', $claims->iss);
        self::assertSame('hub', $claims->aud);
        self::assertSame('user-123', $claims->sub);
        self::assertSame(JwtClaims::TYPE_ACCESS, $claims->type);
        self::assertSame(['library:read'], $claims->scope);
        self::assertSame('server-1', $claims->serverId);
    }

    public function testValidateExpiredTokenReturnsNull(): void
    {
        $handler = new JwtHandler(self::SECRET, JwtClaims::ISS_PHLIX_HUB, JwtClaims::AUD_HUB, -1, 1);
        $token = $handler->createAccessToken('user-123');
        // The token's exp is in the past (now + -1).
        self::assertNull($handler->validateToken($token));
    }

    public function testValidateWrongIssReturnsNull(): void
    {
        $handler = new JwtHandler(self::SECRET, 'phlix-hub');
        $token = $handler->createAccessToken('user-123');

        $otherHandler = new JwtHandler(self::SECRET, 'phlix'); // server-side iss
        self::assertNull($otherHandler->validateToken($token));
    }

    public function testValidateWrongAudReturnsNull(): void
    {
        $handler = new JwtHandler(self::SECRET, 'phlix-hub', 'hub');
        $token = $handler->createAccessToken('user-123');

        $otherHandler = new JwtHandler(self::SECRET, 'phlix-hub', 'server');
        self::assertNull($otherHandler->validateToken($token));
    }

    public function testValidateMalformedTokenReturnsNull(): void
    {
        $handler = new JwtHandler(self::SECRET);
        self::assertNull($handler->validateToken('not-a-jwt'));
        self::assertNull($handler->validateToken('a.b'));
        self::assertNull($handler->validateToken('a.b.c.d'));
    }

    public function testValidateBadSignatureReturnsNull(): void
    {
        $handler = new JwtHandler(self::SECRET);
        $token = $handler->createAccessToken('user-123');
        // Flip the last byte of the signature.
        $tampered = substr($token, 0, -1) . (substr($token, -1) === 'a' ? 'b' : 'a');
        self::assertNull($handler->validateToken($tampered));
    }

    public function testScopeRoundTripsThroughClaims(): void
    {
        $handler = new JwtHandler(self::SECRET);
        $token = $handler->createAccessToken('user-1', ['library:read', 'playback:write']);
        $claims = $handler->validateAccessToken($token);
        self::assertNotNull($claims);
        self::assertSame(['library:read', 'playback:write'], $claims->scope);
    }

    public function testRefreshTokenCarriesJti(): void
    {
        $handler = new JwtHandler(self::SECRET);
        $token = $handler->createRefreshToken('user-2');
        $claims = $handler->validateRefreshToken($token);
        self::assertNotNull($claims);
        self::assertSame(JwtClaims::TYPE_REFRESH, $claims->type);
        self::assertNotNull($claims->jti);
        self::assertNotSame('', $claims->jti);
    }

    public function testValidateAccessTokenRejectsRefreshToken(): void
    {
        $handler = new JwtHandler(self::SECRET);
        $refresh = $handler->createRefreshToken('user-3');
        self::assertNull($handler->validateAccessToken($refresh));
    }

    public function testValidateRefreshTokenRejectsAccessToken(): void
    {
        $handler = new JwtHandler(self::SECRET);
        $access = $handler->createAccessToken('user-4');
        self::assertNull($handler->validateRefreshToken($access));
    }

    public function testTtlGettersReturnConfigured(): void
    {
        $handler = new JwtHandler(self::SECRET, 'phlix-hub', 'hub', 600, 1200);
        self::assertSame(600, $handler->getAccessTtl());
        self::assertSame(1200, $handler->getRefreshTtl());
    }

    public function testTokenWithoutNbfFieldStillValidates(): void
    {
        // The hub never emits nbf; ensure that's accepted.
        $handler = new JwtHandler(self::SECRET);
        $token = $handler->createAccessToken('user-9');
        $claims = $handler->validateToken($token);
        self::assertNotNull($claims);
        self::assertNull($claims->nbf);
    }

    /**
     * S8: a token whose header advertises `alg:none` (an unsigned token) must
     * be rejected before any signature work, even if it carries a payload the
     * handler would otherwise accept.
     */
    public function testValidateRejectsAlgNoneToken(): void
    {
        $handler = new JwtHandler(self::SECRET);
        $token = $this->forgeToken(['alg' => 'none', 'typ' => 'JWT'], 'user-none');
        self::assertNull($handler->validateToken($token));
    }

    /**
     * S8: a token whose header advertises a different (but real) algorithm
     * must be rejected — defends against alg-confusion downgrade.
     */
    public function testValidateRejectsMismatchedAlgToken(): void
    {
        $handler = new JwtHandler(self::SECRET);
        $token = $this->forgeToken(['alg' => 'RS256', 'typ' => 'JWT'], 'user-rs256');
        self::assertNull($handler->validateToken($token));
    }

    /**
     * S8: a non-`JWT` `typ` (e.g. a JWE/unexpected type) must be rejected.
     */
    public function testValidateRejectsUnexpectedTypHeader(): void
    {
        $handler = new JwtHandler(self::SECRET);
        $token = $this->forgeToken(['alg' => 'HS256', 'typ' => 'JWE'], 'user-jwe');
        self::assertNull($handler->validateToken($token));
    }

    /**
     * Build a JWT with an attacker-chosen header but a VALID HS256 signature
     * over that header — so only the alg/typ pin (not the signature check)
     * can reject it.
     *
     * @param array<string, string> $header
     */
    private function forgeToken(array $header, string $userId): string
    {
        $b64 = static fn (string $d): string => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
        $now = time();
        $payload = [
            'iss' => 'phlix-hub',
            'aud' => 'hub',
            'sub' => $userId,
            'iat' => $now,
            'exp' => $now + 3600,
            'type' => 'access',
        ];
        $h = $b64((string) json_encode($header));
        $p = $b64((string) json_encode($payload));
        $sig = $b64(hash_hmac('sha256', "{$h}.{$p}", self::SECRET, true));
        return "{$h}.{$p}.{$sig}";
    }
}
