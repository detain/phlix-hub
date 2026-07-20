<?php

/**
 * Phlix hub component: Auth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

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
     * The only signing algorithm this handler mints or accepts.
     */
    private const string ALGORITHM = 'HS256';

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
     * Optional live-TTL resolver. Called with the dotted hub-setting key and
     * the boot-time fallback, and expected to return the *effective* TTL in
     * seconds (hub_settings override → config default).
     *
     * This is what makes `auth.access_ttl` / `auth.refresh_ttl` genuinely
     * live rather than boot-bound: without it, an admin override would sit in
     * the `hub_settings` table and never reach a minted token. Wired by
     * {@see \Phlix\Hub\Common\Container\Providers\AuthServicesProvider}; left
     * null in unit tests and in the CLI smoke command, where the constructor
     * arguments are authoritative.
     *
     * Holds shared configuration lookup logic, never per-request state, so it
     * is safe on a resident-memory singleton.
     *
     * @var (callable(string, int): int)|null
     */
    private $ttlResolver;

    /**
     * Build a JwtHandler.
     *
     * @param string $secretKey  HMAC secret (≥32 bytes for HS256).
     * @param string $issuer     Issuer claim. Defaults to "phlix-hub".
     * @param string $audience   Audience claim. Defaults to "hub".
     * @param int    $accessTtl  Access TTL seconds (default 3600 = 1h). Used as
     *                           the fallback when no resolver is wired or the
     *                           resolver fails.
     * @param int    $refreshTtl Refresh TTL seconds (default 604800 = 7d). Same
     *                           fallback semantics as `$accessTtl`.
     * @param (callable(string, int): int)|null $ttlResolver Optional effective-TTL
     *                           resolver; see {@see self::$ttlResolver}.
     *
     * @throws InvalidArgumentException When the secret is too short.
     */
    public function __construct(
        string $secretKey,
        string $issuer = JwtClaims::ISS_PHLIX_HUB,
        string $audience = JwtClaims::AUD_HUB,
        int $accessTtl = 3600,
        int $refreshTtl = 604800,
        ?callable $ttlResolver = null,
    ) {
        if (strlen($secretKey) < 32) {
            throw new InvalidArgumentException('JWT secret must be at least 32 bytes for HS256.');
        }
        $this->secretKey = $secretKey;
        $this->issuer = $issuer;
        $this->audience = $audience;
        $this->accessTtl = $accessTtl;
        $this->refreshTtl = $refreshTtl;
        $this->ttlResolver = $ttlResolver;
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
            exp: $now + $this->getAccessTtl(),
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
            exp: $now + $this->getRefreshTtl(),
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
     * *Effective* access token TTL, in seconds — the `auth.access_ttl`
     * hub-settings override when one is stored, else the boot-time config
     * default. Also the source of the JSON `expires_in` field in auth
     * responses, so `expires_in` can never disagree with the `exp` claim.
     */
    public function getAccessTtl(): int
    {
        return $this->resolveTtl('auth.access_ttl', $this->accessTtl);
    }

    /**
     * *Effective* refresh token TTL, in seconds (`auth.refresh_ttl` override
     * → config default). Used by the cookie max-age.
     */
    public function getRefreshTtl(): int
    {
        return $this->resolveTtl('auth.refresh_ttl', $this->refreshTtl);
    }

    /**
     * Ask the wired resolver for the effective TTL, falling back to the
     * boot-time value.
     *
     * Fail-safe by construction: a missing resolver, a thrown exception (the
     * DB being unreachable, say), or a non-positive result all degrade to
     * `$fallback`. Token minting must never fail because a settings lookup
     * did.
     *
     * @param string $key      Dotted hub-setting key.
     * @param int    $fallback Boot-time TTL in seconds.
     *
     * @return int Effective TTL in seconds; always > 0.
     */
    private function resolveTtl(string $key, int $fallback): int
    {
        if ($this->ttlResolver === null) {
            return $fallback;
        }

        try {
            $resolved = ($this->ttlResolver)($key, $fallback);
        } catch (Throwable) {
            return $fallback;
        }

        return $resolved > 0 ? $resolved : $fallback;
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
        $header = ['alg' => self::ALGORITHM, 'typ' => 'JWT'];
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

        // Pin alg/typ from the header BEFORE verifying the signature so an
        // attacker can't downgrade to `alg:none` or confuse us into using a
        // different algorithm (alg-confusion). Defense-in-depth: we only ever
        // sign with HS256, so anything else is rejected outright.
        $header = json_decode($this->base64UrlDecode($headerEncoded), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($header)) {
            throw new InvalidArgumentException('JWT header did not decode to an object.');
        }
        if (($header['alg'] ?? null) !== self::ALGORITHM) {
            throw new InvalidArgumentException('Unexpected JWT alg (expected HS256).');
        }
        if (isset($header['typ']) && $header['typ'] !== 'JWT') {
            throw new InvalidArgumentException('Unexpected JWT typ (expected JWT).');
        }

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
