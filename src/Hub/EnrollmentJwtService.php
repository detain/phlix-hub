<?php

declare(strict_types=1);

namespace Phlix\Hub\Hub;

/**
 * Issues and validates Ed25519-signed enrollment JWTs for servers.
 *
 * Enrollment JWTs are distinct from user-session JWTs:
 *   - Algorithm: EdDSA (Ed25519) instead of HS256
 *   - Audience: "server" (not "hub")
 *   - Issuer: "phlix-hub"
 *   - Subject: server UUID assigned by the hub at claim time
 *   - TTL: 7 days (604800 seconds)
 *
 * @package Phlix\Hub\Hub
 */
class EnrollmentJwtService
{
    private const string ALGORITHM = 'EdDSA';
    private const string ISSUER = 'phlix-hub';
    private const string AUDIENCE = 'server';

    /**
     * @param Ed25519KeyManager $keyManager Key manager for signing.
     * @param string            $hubBaseUrl  Hub's public base URL (e.g. "https://hub.example.com").
     */
    public function __construct(
        private readonly Ed25519KeyManager $keyManager,
        private readonly string $hubBaseUrl,
    ) {
    }

    /**
     * Mint an enrollment JWT for a newly claimed server.
     *
     * @param string $serverId  Hub-assigned server UUID.
     * @param int    $ttl        Token TTL in seconds (default 604800 = 7 days).
     *
     * @return string Encoded JWT signed with Ed25519.
     */
    public function createEnrollmentJwt(string $serverId, int $ttl = 604800): string
    {
        $now = time();
        $kid = $this->keyManager->getKid();
        $header = [
            'alg' => self::ALGORITHM,
            'typ' => 'JWT',
            'kid' => $kid,
        ];
        $payload = [
            'iss' => self::ISSUER,
            'sub' => $serverId,
            'aud' => self::AUDIENCE,
            'exp' => $now + $ttl,
            'iat' => $now,
            'kid' => $kid,
            'hub_base_url' => $this->hubBaseUrl,
            'server_id' => $serverId,
        ];

        $headerEncoded = $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));

        $keyPair = $this->keyManager->getOrCreateKeyPair();
        /** @var non-empty-string $privateKey */
        $privateKey = $keyPair['private'];
        $signature = sodium_crypto_sign_detached(
            "{$headerEncoded}.{$payloadEncoded}",
            $privateKey,
        );
        $signatureEncoded = $this->base64UrlEncode($signature);

        return "{$headerEncoded}.{$payloadEncoded}.{$signatureEncoded}";
    }

    /**
     * Validate an enrollment JWT and return the decoded payload.
     *
     * @param string $token        The JWT to validate.
     * @param string $expectedKid  Expected key ID (from the token header matched against known keys).
     *
     * @return array<string, mixed>|null Decoded payload when valid; null when invalid/expired.
     */
    public function validateEnrollmentJwt(string $token, string $expectedKid): ?array
    {
        if ($expectedKid !== $this->keyManager->getKid()) {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;

        $keyPair = $this->keyManager->getOrCreateKeyPair();
        $signature = $this->base64UrlDecode($signatureEncoded);

        if ($signature === '' || $keyPair['public'] === '') {
            return null;
        }

        $message = "{$headerEncoded}.{$payloadEncoded}";
        if (!sodium_crypto_sign_verify_detached($signature, $message, $keyPair['public'])) {
            return null;
        }

        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($this->base64UrlDecode($payloadEncoded), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (($payload['iss'] ?? '') !== self::ISSUER) {
            return null;
        }
        if (($payload['aud'] ?? '') !== self::AUDIENCE) {
            return null;
        }
        if (($payload['exp'] ?? 0) < time()) {
            return null;
        }

        return $payload;
    }

    /**
     * Get the JWKS URL for this hub.
     */
    public function getHubJwksUrl(): string
    {
        return rtrim($this->hubBaseUrl, '/') . '/.well-known/jwks.json';
    }

    /**
     * Get the hub's base URL.
     */
    public function getHubBaseUrl(): string
    {
        return rtrim($this->hubBaseUrl, '/');
    }

    /**
     * Base64URL encode (no padding).
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64URL decode.
     */
    private function base64UrlDecode(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        return $decoded !== false ? $decoded : '';
    }
}
