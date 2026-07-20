<?php

/**
 * Phlix hub component: Hub.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

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

    /** Fallback TTL (7 days) when no setting and no explicit override apply. */
    public const int DEFAULT_TTL = 604800;

    /**
     * @param Ed25519KeyManager           $keyManager Key manager for signing.
     * @param string                      $hubBaseUrl Hub's public base URL
     *                                                (e.g. "https://hub.example.com").
     * @param HubSettingsRepository|null  $settings   Optional hub-settings store. When
     *                                                supplied, {@see createEnrollmentJwt()}
     *                                                resolves the EFFECTIVE
     *                                                `server.enrollment_ttl` on every
     *                                                mint, so an admin override applies
     *                                                to the next claim/renewal without a
     *                                                restart. Null in unit tests and the
     *                                                CLI, where {@see DEFAULT_TTL} applies.
     */
    public function __construct(
        private readonly Ed25519KeyManager $keyManager,
        private readonly string $hubBaseUrl,
        private readonly ?HubSettingsRepository $settings = null,
    ) {
    }

    /**
     * Effective enrollment-JWT TTL in seconds.
     *
     * Resolution order: the `server.enrollment_ttl` hub-settings override →
     * `config/server.php`'s `enrollment_ttl` → {@see DEFAULT_TTL}. Fail-safe:
     * an unreachable store or a non-positive value degrades to the default
     * rather than minting a token that is already expired.
     *
     * @return int Effective TTL in seconds; always > 0.
     */
    public function effectiveTtl(): int
    {
        if ($this->settings === null) {
            return self::DEFAULT_TTL;
        }

        try {
            /** @var mixed $value */
            $value = $this->settings->getEffective('server.enrollment_ttl');
        } catch (\Throwable) {
            return self::DEFAULT_TTL;
        }

        if (!is_numeric($value)) {
            return self::DEFAULT_TTL;
        }

        $ttl = (int) $value;

        return $ttl > 0 ? $ttl : self::DEFAULT_TTL;
    }

    /**
     * Mint an enrollment JWT for a newly claimed server.
     *
     * @param string   $serverId Hub-assigned server UUID.
     * @param int|null $ttl      Explicit TTL in seconds. `null` (the normal
     *                           case) resolves the effective
     *                           `server.enrollment_ttl` hub setting via
     *                           {@see effectiveTtl()}.
     *
     * @return string Encoded JWT signed with Ed25519.
     */
    public function createEnrollmentJwt(string $serverId, ?int $ttl = null): string
    {
        $ttl ??= $this->effectiveTtl();
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
     * The `kid` is matched against the hub's currently-active signing keys: the
     * current key OR a still-valid (non-expired) previous key retained during a
     * rotation overlap window. The signature is then verified against the
     * public key for the matched kid, so a 7-day enrollment JWT minted just
     * before a rotation keeps validating until its overlap window lapses.
     *
     * @param string $token        The JWT to validate.
     * @param string $expectedKid  Expected key ID (from the token header matched against known keys).
     *
     * @return array<string, mixed>|null Decoded payload when valid; null when invalid/expired.
     */
    public function validateEnrollmentJwt(string $token, string $expectedKid): ?array
    {
        // Resolve the kid to one of the hub's currently-active public keys
        // (current or non-expired previous). An unknown/expired kid is rejected.
        $publicKey = $this->keyManager->getPublicKeyForKid($expectedKid);
        if ($publicKey === null || $publicKey === '') {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;

        // Pin alg/typ from the header BEFORE verifying the signature so a
        // forged token can't downgrade to `alg:none` or swap in a different
        // algorithm (alg-confusion). We only ever sign enrollment JWTs with
        // EdDSA, so reject anything else outright.
        try {
            /** @var mixed $header */
            $header = json_decode($this->base64UrlDecode($headerEncoded), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($header)) {
            return null;
        }
        if (($header['alg'] ?? null) !== self::ALGORITHM) {
            return null;
        }
        if (isset($header['typ']) && $header['typ'] !== 'JWT') {
            return null;
        }
        // The header kid must match the kid we resolved the key under, so an
        // attacker can't present a token signed under key A while passing kid B.
        if (($header['kid'] ?? null) !== $expectedKid) {
            return null;
        }

        $signature = $this->base64UrlDecode($signatureEncoded);

        if ($signature === '') {
            return null;
        }

        $message = "{$headerEncoded}.{$payloadEncoded}";
        if (!sodium_crypto_sign_verify_detached($signature, $message, $publicKey)) {
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
