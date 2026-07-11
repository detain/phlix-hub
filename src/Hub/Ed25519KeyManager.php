<?php

/**
 * Phlix hub component: Hub.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Hub;

use Closure;
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
 * Rotation overlap (S7): {@see rotate()} retains the PUBLIC half of the
 * outgoing key (kid + raw public key) in a sidecar file alongside the new key,
 * stamped with an expiry {@see OVERLAP_TTL_SECONDS} into the future. While that
 * expiry is in the future, the previous key is still "active" for verification
 * and is published in the JWKS, so 7-day enrollment JWTs minted before the
 * rotation keep validating until they naturally expire. After the overlap
 * window passes, the previous key is dropped (and its sidecar pruned) and its
 * tokens are rejected. Only the public half is retained — the hub never signs
 * with the old key after rotating, so the old private key is intentionally not
 * kept.
 *
 * @phpstan-type ActiveKey array{kid: string, public: string, expiresAt: int|null}
 *
 * @package Phlix\Hub\Hub
 */
final class Ed25519KeyManager
{
    /**
     * Overlap window during which a rotated-out key remains valid for
     * verification and is still published in the JWKS. The class docblock and
     * the original {@see rotate()} contract promise 24 hours.
     */
    public const int OVERLAP_TTL_SECONDS = 86400;

    private ?string $privateKey = null;

    private ?string $publicKey = null;

    /** Lazily derived from the public key; reset to null whenever the key changes. */
    private ?string $kid = null;

    /**
     * Cached previous-key record loaded from / persisted to the sidecar, or
     * null when there is no retained previous key. Loaded lazily.
     *
     * @var ActiveKey|null|false `false` = not yet loaded, `null` = loaded but absent.
     */
    private array|null|false $previousKey = false;

    /** @var Closure(): int Unix-time source; injectable for deterministic tests. */
    private readonly Closure $clock;

    /**
     * @param string             $keyPath Absolute path to the PEM-encoded private key file.
     * @param (Closure(): int)|null $clock   Unix-time source (defaults to {@see time()}).
     */
    public function __construct(
        private readonly string $keyPath,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
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
        return $this->jwkFor($pair['public'], $this->getKid());
    }

    /**
     * Get ALL currently-active public keys as JWK arrays: the current key plus
     * any retained previous key still inside its overlap window.
     *
     * During the overlap after a {@see rotate()}, this returns two entries; once
     * the previous key's overlap expires (or the hub never rotated), it returns
     * just the current key.
     *
     * @return list<array<string, mixed>>
     */
    public function getPublicKeyJwks(): array
    {
        $jwks = [];
        foreach ($this->getActivePublicKeys() as $key) {
            $jwks[] = $this->jwkFor($key['public'], $key['kid']);
        }
        return $jwks;
    }

    /**
     * Get all public keys valid for signature verification right now: the
     * current key first, then a retained previous key if it is still within its
     * overlap window. Used by {@see EnrollmentJwtService} to resolve a token's
     * `kid` to the public key it was signed under.
     *
     * @return list<ActiveKey>
     */
    public function getActivePublicKeys(): array
    {
        $pair = $this->getOrCreateKeyPair();
        $keys = [[
            'kid' => $this->getKid(),
            'public' => $pair['public'],
            'expiresAt' => null,
        ]];

        $previous = $this->loadPreviousKey();
        if ($previous !== null && $previous['kid'] !== $this->getKid()) {
            $keys[] = $previous;
        }

        return $keys;
    }

    /**
     * Resolve the raw public key for a given `kid` among the currently-active
     * keys (current + non-expired previous), or null when the kid is unknown or
     * its overlap window has lapsed.
     */
    public function getPublicKeyForKid(string $kid): ?string
    {
        foreach ($this->getActivePublicKeys() as $key) {
            if ($key['kid'] === $kid) {
                return $key['public'];
            }
        }
        return null;
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
        return $this->kid ??= $this->fingerprint($this->getOrCreateKeyPair()['public']);
    }

    /**
     * Rotate: retain the outgoing public key for overlap, generate a new
     * keypair, store it, and persist the previous-key sidecar.
     *
     * The outgoing key's PUBLIC half (kid + raw public key) is retained for
     * {@see OVERLAP_TTL_SECONDS} (24h) so enrollment JWTs minted under it keep
     * validating — and stay published in the JWKS — until they naturally
     * expire. After that window the previous key is dropped on next access.
     */
    public function rotate(): void
    {
        // Capture the outgoing key BEFORE we replace it.
        $outgoing = $this->getOrCreateKeyPair();
        $previous = [
            'kid' => $this->getKid(),
            'public' => $outgoing['public'],
            'expiresAt' => $this->now() + self::OVERLAP_TTL_SECONDS,
        ];

        $this->privateKey = null;
        $this->publicKey = null;
        // Cleared so getKid() re-derives the fingerprint from the new public key.
        $this->kid = null;
        $this->generateAndStore();

        // Persist + cache the retained previous key (after the new key exists so
        // the sidecar never points at the just-superseded current key).
        $this->previousKey = $previous;
        $this->storePreviousKey($previous);
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
     * Load the retained previous key from the sidecar, transparently pruning it
     * once the overlap window has lapsed. Returns null when none is retained
     * (the never-rotated case) or it has expired.
     *
     * @return ActiveKey|null
     */
    private function loadPreviousKey(): ?array
    {
        if ($this->previousKey === false) {
            $this->previousKey = $this->readPreviousKeyFile();
        }

        if ($this->previousKey === null) {
            return null;
        }

        $expiresAt = $this->previousKey['expiresAt'];
        if ($expiresAt !== null && $expiresAt <= $this->now()) {
            // Overlap window lapsed — drop the previous key for good.
            // NOTE: we do NOT delete the sidecar file here (unlink is I/O on the
            // hot verification path). The file will be overwritten on the next
            // rotate(), or can be cleaned up via purgeExpiredPreviousKey().
            $this->previousKey = null;
            return null;
        }

        return $this->previousKey;
    }

    /**
     * Read and validate the previous-key sidecar file.
     *
     * @return ActiveKey|null
     */
    private function readPreviousKeyFile(): ?array
    {
        $path = $this->previousKeyPath();
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $kid = $decoded['kid'] ?? null;
        $publicB64 = $decoded['public'] ?? null;
        $expiresAt = $decoded['expiresAt'] ?? null;

        if (!is_string($kid) || $kid === '' || !is_string($publicB64) || !is_int($expiresAt)) {
            return null;
        }

        $public = $this->base64Decode($publicB64);
        if (strlen($public) !== 32) {
            return null;
        }

        return ['kid' => $kid, 'public' => $public, 'expiresAt' => $expiresAt];
    }

    /**
     * Persist the retained previous key alongside the main key file.
     *
     * @param ActiveKey $previous
     */
    private function storePreviousKey(array $previous): void
    {
        $payload = json_encode([
            'kid' => $previous['kid'],
            'public' => $this->base64Encode($previous['public']),
            'expiresAt' => $previous['expiresAt'],
        ], JSON_THROW_ON_ERROR);

        $path = $this->previousKeyPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new RuntimeException('Failed to create key directory: ' . $dir);
            }
        }

        if (file_put_contents($path, $payload, LOCK_EX) === false) {
            throw new RuntimeException('Failed to write previous-key file: ' . $path);
        }
        chmod($path, 0600);
    }

    /**
     * Remove the previous-key sidecar (best-effort; an expired/absent file is
     * not an error).
     */
    private function deletePreviousKeyFile(): void
    {
        $path = $this->previousKeyPath();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Purge the previous-key sidecar if it has expired.
     *
     * This is NOT called automatically on the verification path (loadPreviousKey
     * does not unlink on expiry) to avoid I/O on every JWT check. Call this
     * from a periodic maintenance timer or during non-peak hours.
     */
    public function purgeExpiredPreviousKey(): void
    {
        $record = $this->readPreviousKeyFile();
        if ($record === null) {
            return;
        }

        $expiresAt = $record['expiresAt'];
        if ($expiresAt !== null && $expiresAt <= $this->now()) {
            $this->previousKey = false;
            $this->deletePreviousKeyFile();
        }
    }

    /**
     * Path of the previous-key sidecar, derived from the main key path.
     */
    private function previousKeyPath(): string
    {
        return $this->keyPath . '.previous.json';
    }

    /**
     * Build a JWK array for a raw 32-byte Ed25519 public key + kid.
     *
     * @return array<string, mixed>
     */
    private function jwkFor(string $publicKey, string $kid): array
    {
        return [
            'kty' => 'OKP',
            'crv' => 'Ed25519',
            'x' => $this->base64UrlEncode($publicKey),
            'kid' => $kid,
            'use' => 'sig',
            'alg' => 'EdDSA',
        ];
    }

    /**
     * Deterministic kid fingerprint of a raw public key.
     */
    private function fingerprint(string $publicKey): string
    {
        return $this->base64UrlEncode(hash('sha256', $publicKey, true));
    }

    /**
     * Current unix time from the injected clock.
     */
    private function now(): int
    {
        return ($this->clock)();
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
