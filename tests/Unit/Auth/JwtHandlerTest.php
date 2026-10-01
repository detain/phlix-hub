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
     * The `nbf` gate in {@see JwtHandler::validateToken()} is the vendored
     * shared predicate `Phlix\Shared\Auth\JwtClaims::isNotYetValid()`
     * (detain/phlix-shared v0.50.0), called with its zero-leeway default —
     * the hub mints no `nbf` itself, so any future-dated `nbf` is forgery or
     * clock drift and strictness is the safe policy.
     *
     * These two cases pin that dependency from the hub side: a hand-minted
     * correctly-signed token whose `nbf` is 600 s ahead must be REJECTED
     * exactly because isNotYetValid() is true, and the same token with a
     * past `nbf` must be ACCEPTED exactly because it is false. If the shared
     * predicate's semantics ever move (leeway defaults, null handling, clock
     * injection), these tests trip here rather than silently re-opening the
     * not-yet-valid window on a live hub.
     */
    public function testValidateTokenRejectsFutureNbfViaSharedIsNotYetValid(): void
    {
        self::assertTrue(
            $this->claimsAtNbf(time() + 600)->isNotYetValid(),
            'The shared predicate must call a 600 s future nbf not-yet-valid at zero leeway.'
        );
        self::assertNull(
            (new JwtHandler(self::SECRET))->validateToken($this->tokenWithNbf(time() + 600)),
            'validateToken() must reject a future-nbf token via isNotYetValid().'
        );
    }

    public function testValidateTokenAcceptsPastNbfViaSharedIsNotYetValid(): void
    {
        self::assertFalse(
            $this->claimsAtNbf(time() - 600)->isNotYetValid(),
            'The shared predicate must call a past nbf valid at zero leeway.'
        );
        $claims = (new JwtHandler(self::SECRET))->validateToken($this->tokenWithNbf(time() - 600));
        self::assertNotNull($claims, 'validateToken() must accept a past-nbf token.');
        self::assertSame('user-12', $claims->sub);
    }

    /**
     * @return array<string, mixed>
     */
    private function nbfPayload(int $nbf): array
    {
        $now = time();
        $claims = new JwtClaims(
            iss: JwtClaims::ISS_PHLIX_HUB,
            aud: JwtClaims::AUD_HUB,
            sub: 'user-12',
            iat: $now,
            exp: $now + 3600,
            nbf: $nbf,
            type: JwtClaims::TYPE_ACCESS,
            jti: null,
            scope: [],
            serverId: null,
        );
        return $claims->toPayload();
    }

    private function claimsAtNbf(int $nbf): JwtClaims
    {
        return JwtClaims::fromPayload($this->nbfPayload($nbf));
    }

    /**
     * Mint a correctly-signed HS256 token carrying an explicit `nbf` — the
     * hub's own minting API never sets one, and JwtHandler::encode() is
     * private, so the test replicates the documented recipe (same secret,
     * same algorithm, base64url without padding).
     */
    private function tokenWithNbf(int $nbf): string
    {
        $header = $this->base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = $this->base64UrlEncode(json_encode($this->nbfPayload($nbf), JSON_THROW_ON_ERROR));
        $signature = $this->base64UrlEncode(hash_hmac('sha256', "{$header}.{$payload}", self::SECRET, true));
        return "{$header}.{$payload}.{$signature}";
    }

    private function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
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
