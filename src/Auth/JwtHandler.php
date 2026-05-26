<?php

declare(strict_types=1);

namespace Phlix\Hub\Auth;

use InvalidArgumentException;
use Phlix\Shared\Auth\JwtClaims;
use Throwable;

/**
 * HS256 JWT token handler for the Phlix Hub.
 *
 * Differences from `phlix-server`'s {@see \Phlix\Auth\JwtHandler}:
 *
 *  - Issuer (`iss`) defaults to {@see JwtClaims::ISS_PHLIX_HUB} ("phlix-hub"),
 *    not "phlix". Hub-minted tokens never collide with server-minted ones.
 *  - Audience (`aud`) defaults to {@see JwtClaims::AUD_HUB} ("hub"); the
 *    server expects {@see JwtClaims::AUD_SERVER}.
 *  - {@see self::validateToken()} returns a `?\Phlix\Shared\Auth\JwtClaims`
 *    instance, not an `array`. This is the proof-of-design for the
 *    cross-repo DTO from `phlix-shared` v0.2.0.
 *
 * Symmetric HMAC-SHA256 signing only — no support for RS256/ES256
 * (a potential future hardening task).
 *
 * @package Phlix\Hub\Auth
 */
final class JwtHandler
{
    /**
     * Secret used for HMAC signing. Must be at least 32 bytes for HS256.
     */
    private string $secretKey;

    /**
     * Issuer string stamped into every token. Defaults to "phlix-hub".
     */
    private string $issuer;

    /**
     * Audience string stamped into every token. Defaults to "hub".
     */
    private string $audience;

    /**
     * Access token TTL in seconds.
     */
    private int $accessTtl;

    /**
     * Refresh token TTL in seconds.
     */
    private int $refreshTtl;

    /**
     * Build a JwtHandler.
     *
     * @param string $secretKey  HMAC secret (≥32 bytes for HS256).
     * @param string $issuer     Issuer claim. Defaults to "phlix-hub".
     * @param string $audience   Audience claim. Defaults to "hub".
     * @param int    $accessTtl  Access TTL seconds (default 3600 = 1h).
     * @param int    $refreshTtl Refresh TTL seconds (default 604800 = 7d).
     *
     * @throws InvalidArgumentException When the secret is too short.
     */
    public function __construct(
        string $secretKey,
        string $issuer = JwtClaims::ISS_PHLIX_HUB,
        string $audience = JwtClaims::AUD_HUB,
        int $accessTtl = 3600,
        int $refreshTtl = 604800,
    ) {
        if (strlen($secretKey) < 32) {
            throw new InvalidArgumentException('JWT secret must be at least 32 bytes for HS256.');
        }
        $this->secretKey = $secretKey;
        $this->issuer = $issuer;
        $this->audience = $audience;
        $this->accessTtl = $accessTtl;
        $this->refreshTtl = $refreshTtl;
    }

    /**
     * Mint a signed access token for a user.
     *
     * Output is `<header>.<payload>.<sig>` base64url-encoded. Caller can
     * feed the result back through {@see self::validateToken()} to round-trip
     * into a {@see JwtClaims}.
     *
     * @param string       $userId       Subject — the user UUID.
     * @param list<string> $scope        Permission strings; empty for unscoped.
     * @param ?string      $serverId     Optional `serverId` claim (used for
     *                                   hub-minted client tokens that target
     *                                   a specific server).
     *
     * @return string Encoded JWT.
     */
    public function createAccessToken(string $userId, array $scope = [], ?string $serverId = null): string
    {
        $now = time();
        $claims = new JwtClaims(
            iss: $this->issuer,
            aud: $this->audience,
            sub: $userId,
            iat: $now,
            exp: $now + $this->accessTtl,
            nbf: null,
            type: JwtClaims::TYPE_ACCESS,
            jti: null,
            scope: $scope,
            serverId: $serverId,
        );
        return $this->encode($claims->toPayload());
    }

    /**
     * Mint a signed refresh token for a user. Refresh tokens carry a `jti`
     * so server-side revocation can be added later; the hub itself
     * does not track refresh JTIs.
     *
     * @param string $userId Subject — the user UUID.
     *
     * @return string Encoded JWT.
     */
    public function createRefreshToken(string $userId): string
    {
        $now = time();
        $claims = new JwtClaims(
            iss: $this->issuer,
            aud: $this->audience,
            sub: $userId,
            iat: $now,
            exp: $now + $this->refreshTtl,
            nbf: null,
            type: JwtClaims::TYPE_REFRESH,
            jti: bin2hex(random_bytes(16)),
            scope: [],
            serverId: null,
        );
        return $this->encode($claims->toPayload());
    }

    /**
     * Validate `$token` and return the decoded claims, or null when:
     * - the format is malformed,
     * - the signature does not verify,
     * - the issuer does not match this handler's configured `$issuer`,
     * - the audience does not match this handler's configured `$audience`,
     * - the token is expired (`exp` < now),
     * - or the payload cannot be coerced into a {@see JwtClaims}.
     *
     * @param string $token Encoded JWT.
     *
     * @return JwtClaims|null Hydrated claims when valid; null otherwise.
     */
    public function validateToken(string $token): ?JwtClaims
    {
        try {
            $payload = $this->decode($token);
        } catch (Throwable) {
            return null;
        }

        try {
            $claims = JwtClaims::fromPayload($payload);
        } catch (InvalidArgumentException) {
            return null;
        }

        if ($claims->iss !== $this->issuer) {
            return null;
        }
        if ($claims->aud !== $this->audience) {
            return null;
        }
        if ($claims->isExpired()) {
            return null;
        }

        return $claims;
    }

    /**
     * Convenience: validate `$token` AND check the token type matches
     * {@see JwtClaims::TYPE_ACCESS}. Returns null when the token is
     * invalid, expired, or a refresh token.
     */
    public function validateAccessToken(string $token): ?JwtClaims
    {
        $claims = $this->validateToken($token);
        if ($claims === null) {
            return null;
        }
        return $claims->type === JwtClaims::TYPE_ACCESS ? $claims : null;
    }

    /**
     * Convenience: validate `$token` AND check the token type matches
     * {@see JwtClaims::TYPE_REFRESH}. Returns null when the token is
     * invalid, expired, or an access token.
     */
    public function validateRefreshToken(string $token): ?JwtClaims
    {
        $claims = $this->validateToken($token);
        if ($claims === null) {
            return null;
        }
        return $claims->type === JwtClaims::TYPE_REFRESH ? $claims : null;
    }

    /**
     * Access token TTL, in seconds. Exposed for the JSON `expires_in`
     * field in auth responses.
     */
    public function getAccessTtl(): int
    {
        return $this->accessTtl;
    }

    /**
     * Refresh token TTL, in seconds. Used by the cookie max-age.
     */
    public function getRefreshTtl(): int
    {
        return $this->refreshTtl;
    }

    /**
     * Encode a payload as a signed JWT string.
     *
     * @param array<string, mixed> $payload Already shaped per {@see JwtClaims::toPayload()}.
     *
     * @throws \JsonException When the payload contains non-encodable data.
     */
    private function encode(array $payload): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $headerEncoded = $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = hash_hmac('sha256', "{$headerEncoded}.{$payloadEncoded}", $this->secretKey, true);
        $signatureEncoded = $this->base64UrlEncode($signature);
        return "{$headerEncoded}.{$payloadEncoded}.{$signatureEncoded}";
    }

    /**
     * Decode and signature-verify a JWT string.
     *
     * @return array<string, mixed> Decoded payload.
     *
     * @throws InvalidArgumentException When the token is malformed or the signature does not verify.
     * @throws \JsonException When the payload is not valid JSON.
     */
    private function decode(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new InvalidArgumentException('Malformed JWT (expected 3 dot-separated segments).');
        }
        [$headerEncoded, $payloadEncoded, $signature] = $parts;

        $expectedSignature = $this->base64UrlEncode(
            hash_hmac('sha256', "{$headerEncoded}.{$payloadEncoded}", $this->secretKey, true),
        );
        if (!hash_equals($expectedSignature, $signature)) {
            throw new InvalidArgumentException('JWT signature mismatch.');
        }

        $decoded = json_decode($this->base64UrlDecode($payloadEncoded), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('JWT payload did not decode to an object.');
        }
        /** @var array<string, mixed> $payload */
        $payload = [];
        /**
         * @var mixed $value
         * @psalm-suppress MixedAssignment
         */
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $payload[$key] = $value;
            }
        }
        return $payload;
    }

    /**
     * Base64URL encode (no padding, '+' → '-', '/' → '_').
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64URL decode (inverse of {@see self::base64UrlEncode()}).
     */
    private function base64UrlDecode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }
}
