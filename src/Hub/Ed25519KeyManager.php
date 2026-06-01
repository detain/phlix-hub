<?php

declare(strict_types=1);

namespace Phlix\Hub\Hub;

use RuntimeException;

/**
 * Manages the hub's Ed25519 signing keypair for enrollment JWT issuance.
 *
 * On first boot, generates a fresh Ed25519 keypair and stores the private
 * key in PEM format at the configured path. On subsequent boots, loads
 * the existing key. Supports key rotation with an overlap window.
 *
 * The `kid` is a deterministic fingerprint of the public key (base64url of
 * its SHA-256), NOT a per-process timestamp. Because the private key is
 * persisted and reloaded across restarts, the kid is therefore STABLE across
 * restarts and only changes when the key itself rotates. An earlier version
 * set the kid from `date()` in the constructor, so every hub restart re-labelled
 * the same key with a new kid; {@see EnrollmentJwtService::validateEnrollmentJwt()}
 * rejects any token whose kid differs from the current one, so every restart
 * invalidated all outstanding enrollment JWTs (surfacing to servers as a
 * spurious ENROLLMENT_TOKEN_EXPIRED on heartbeat). Deriving the kid from the
 * key keeps it stable and ends that breakage.
 *
 * @package Phlix\Hub\Hub
 */
final class Ed25519KeyManager
{
    private ?string $privateKey = null;

    private ?string $publicKey = null;

    /** Lazily derived from the public key; reset to null whenever the key changes. */
    private ?string $kid = null;

    /**
     * @param string $keyPath Absolute path to the PEM-encoded private key file.
     */
    public function __construct(
        private readonly string $keyPath,
    ) {
    }

    /**
     * Get or create the keypair, loading from disk on subsequent calls.
     *
     * @return array{private: string, public: string}
     *
     * @throws RuntimeException When key loading or generation fails.
     */
    public function getOrCreateKeyPair(): array
    {
        if ($this->privateKey !== null) {
            /** @var array{private: string, public: string} */
            return ['private' => $this->privateKey, 'public' => $this->publicKey];
        }

        if (is_file($this->keyPath)) {
            $pem = file_get_contents($this->keyPath);
            if ($pem === false) {
                throw new RuntimeException('Failed to read Ed25519 key file: ' . $this->keyPath);
            }
            $keyPair = $this->extractKeyPair($pem);
            $this->privateKey = $keyPair['private'];
            $this->publicKey = $keyPair['public'];
            /** @var array{private: string, public: string} */
            return ['private' => $this->privateKey, 'public' => $this->publicKey];
        }

        $this->generateAndStore();
        /** @var array{private: string, public: string} */
        return ['private' => $this->privateKey, 'public' => $this->publicKey];
    }

    /**
     * Get the current public key as a JWK-compatible array for JWKS.
     *
     * @return array<string, mixed>
     */
    public function getPublicKeyJwk(): array
    {
        $pair = $this->getOrCreateKeyPair();
        $publicKey = $pair['public'];
        return [
            'kty' => 'OKP',
            'crv' => 'Ed25519',
            'x' => $this->base64UrlEncode($publicKey),
            'kid' => $this->getKid(),
            'use' => 'sig',
            'alg' => 'EdDSA',
        ];
    }

    /**
     * Get the current key ID — a deterministic fingerprint of the public key
     * (base64url of its SHA-256).
     *
     * Stable across process restarts (the key is persisted and reloaded) and
     * changes only when the key rotates, so enrollment JWTs minted under one
     * boot stay valid after a restart.
     */
    public function getKid(): string
    {
        return $this->kid ??= $this->base64UrlEncode(
            hash('sha256', $this->getOrCreateKeyPair()['public'], true),
        );
    }

    /**
     * Rotate: generate a new keypair, store it, return the new KID.
     *
     * The old key should be retained for up to 24 hours for overlap
     * validation during rotation.
     */
    public function rotate(): void
    {
        $this->privateKey = null;
        $this->publicKey = null;
        // Cleared so getKid() re-derives the fingerprint from the new public key.
        $this->kid = null;
        $this->generateAndStore();
    }

    /**
     * Generate a new Ed25519 keypair and write to disk.
     *
     * @throws RuntimeException When key generation or storage fails.
     */
    private function generateAndStore(): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = substr($keyPair, 0, 64);
        $publicKey = substr($keyPair, 64);

        $pem = "-----BEGIN ED25519 PRIVATE KEY-----\n"
            . $this->base64Encode($secretKey) . "\n"
            . "-----END ED25519 PRIVATE KEY-----\n";

        $dir = dirname($this->keyPath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new RuntimeException('Failed to create key directory: ' . $dir);
            }
        }

        if (file_put_contents($this->keyPath, $pem, LOCK_EX) === false) {
            throw new RuntimeException('Failed to write Ed25519 key file: ' . $this->keyPath);
        }
        chmod($this->keyPath, 0600);

        $this->privateKey = $secretKey;
        $this->publicKey = $publicKey;
    }

    /**
     * Extract the full 64-byte Ed25519 keypair from a PEM string.
     *
     * @param string $pem PEM-encoded key.
     *
     * @return array{private: string, public: string}
     */
    private function extractKeyPair(string $pem): array
    {
        $key = trim($pem);
        $key = (string) preg_replace('#-----(BEGIN|END) ED25519 PRIVATE KEY-----#', '', $key);
        $key = str_replace(["\r", "\n", ' '], '', $key);
        $decoded = $this->base64Decode($key);
        if (strlen($decoded) !== 64) {
            throw new RuntimeException('Ed25519 private key must be exactly 64 bytes.');
        }
        return ['private' => $decoded, 'public' => substr($decoded, 32)];
    }

    /**
     * Standard base64 encode.
     */
    private function base64Encode(string $data): string
    {
        return base64_encode($data);
    }

    /**
     * Standard base64 decode.
     */
    private function base64Decode(string $data): string
    {
        $decoded = base64_decode($data, true);
        return $decoded !== false ? $decoded : '';
    }

    /**
     * Base64URL encode (no padding, '-' instead of '+', '_' instead of '/').
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
