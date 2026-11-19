<?php

/**
 * Phlix hub component: Hub.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Hub;

use Closure;
use Phlix\Shared\Security\Ed25519Pem;
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
 * and is published in the JWKS — so an enrollment JWT minted before the
 * rotation keeps validating until its overlap window lapses, NOT until the
 * token's own expiry. The window is the key's, not the token's: the default
 * enrollment JWT lives 7 days, so a token already older than the window at
 * rotation time stops validating at rotation + 24 h even though its `exp` is
 * later. Operator consequence: rotating the signing key means every paired
 * server must re-mint its enrollment JWT within 24 hours (the heartbeat
 * re-enrollment path does exactly that). After the overlap window passes, the
 * previous key is dropped (and its sidecar pruned) and its tokens are
 * rejected. Only the public half is retained — the hub never signs with the
 * old key after rotating, so the old private key is intentionally not kept.
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
     * The read-from-disk fast path is lock-free. Only the cold "the file is
     * not there yet" branch takes an exclusive lock, so concurrent hub workers
     * booting for the first time cannot each generate a DIFFERENT keypair and
     * clobber one another: the loser of the lock re-reads the winner's file
     * instead of minting its own (which would invalidate every JWT signed by
     * the first process the moment a second one overwrote the key).
     *
     * @return array{private: string, public: string}
     *
     * @throws RuntimeException When key loading or generation fails.
     */
    public function getOrCreateKeyPair(): array
    {
        if ($this->privateKey !== null) {
            return $this->cachedKeyPair();
        }

        if (is_file($this->keyPath) && $this->loadStoredKeyPair() === true) {
            return $this->cachedKeyPair();
        }

        return $this->generateKeyPairLocked();
    }

    /**
     * Snapshot the in-memory cache as the documented key-pair shape.
     *
     * Every caller populates both halves before returning here; a one-sided
     * cache would be an internal invariant violation, so it fails loud rather
     * than minting a half-typed array.
     *
     * @return array{private: string, public: string}
     *
     * @throws RuntimeException When the cache is not fully populated.
     */
    private function cachedKeyPair(): array
    {
        if (!is_string($this->privateKey) || !is_string($this->publicKey)) {
            throw new RuntimeException('Ed25519 key pair cache is not populated');
        }

        return ['private' => $this->privateKey, 'public' => $this->publicKey];
    }

    /**
     * Read + parse the on-disk PEM into the in-memory cache.
     *
     * Returns false only when the file vanished mid-read (a benign race with a
     * pruning/rotating sibling). A file that exists but cannot be READ is a
     * hard failure — silently regenerating over an unreadable signing key
     * would invalidate every outstanding enrollment JWT.
     *
     * @throws RuntimeException When the key file is present but unreadable.
     */
    private function loadStoredKeyPair(): bool
    {
        $existed = is_file($this->keyPath);
        $pem = @file_get_contents($this->keyPath);
        if ($pem === false) {
            if ($existed) {
                throw new RuntimeException('Failed to read Ed25519 key file: ' . $this->keyPath);
            }
            return false;
        }

        // Adoption of a restored/rotated-in PEM is deliberately permissive —
        // refusing a world-readable key would brick the documented recovery
        // path — but never SILENT: the write path creates 0600-from-birth
        // (see generateKeyPairLocked), so any looser mode on disk arrived via
        // a restore/copy outside this class, and the private half sitting
        // group/world-readable means any local reader can forge enrollment
        // JWTs and federation proofs. Loud warning (error_log, the
        // MigrationRunner boot-warning idiom), boot continues.
        if ($existed) {
            $mode = @fileperms($this->keyPath);
            if (is_int($mode) && ($mode & 0o077) !== 0) {
                error_log(sprintf(
                    '[phlix-hub] SECURITY: Ed25519 signing key %s is mode %03o — group/world-'
                    . 'accessible. Continuing, but restore 0600 (chmod 600 %s) immediately: '
                    . 'any local reader of this file can forge every credential this key signs.',
                    $this->keyPath,
                    $mode & 0o777,
                    $this->keyPath
                ));
            }
        }

        $keyPair = $this->extractKeyPair($pem);
        $this->privateKey = $keyPair['private'];
        $this->publicKey = $keyPair['public'];
        $this->kid = null;

        return true;
    }

    /**
     * Cold path: generate the keypair under an exclusive file lock, re-checking
     * for a file another process may have created while this one waited on the
     * lock before writing its own.
     *
     * @return array{private: string, public: string}
     */
    private function generateKeyPairLocked(): array
    {
        // The directory must exist before the lock file can live in it.
        $this->ensureDirectory(dirname($this->keyPath));
        // Sibling of the key file (and deliberately not a dotfile): teardown in
        // tests globs the directory, and a hidden name would survive it.
        $lockPath = $this->keyPath . '.lock';

        $lock = @fopen($lockPath, 'c');
        if ($lock === false) {
            // No lock file (read-only fs edge) — generate anyway; this is the
            // historical single-process behavior and must not hard-fail boot.
            $this->generateAndStore();
            return $this->cachedKeyPair();
        }

        if (@flock($lock, LOCK_EX) === false) {
            @fclose($lock);
            $this->generateAndStore();
            return $this->cachedKeyPair();
        }

        try {
            // Another process won the race while we blocked on the lock: adopt
            // its key rather than overwriting it.
            if (is_file($this->keyPath) && $this->loadStoredKeyPair() === true) {
                return $this->cachedKeyPair();
            }

            $this->generateAndStore();
            return $this->cachedKeyPair();
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    /**
     * Ensure $dir exists (0700, private) and return its realpath.
     *
     * @throws RuntimeException When the directory cannot be created.
     */
    private function ensureDirectory(string $dir): string
    {
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create key directory: ' . $dir);
        }

        return $dir;
    }

    /**
     * Get the current public key as a JWK-compatible array for JWKS.
     *
     * @return array<string, mixed>
     */
    public function getPublicKeyJwk(): array
    {
        $pair = $this->getOrCreateKeyPair();
        return Ed25519Pem::toJwk($pair['public'], $this->getKid());
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
            $jwks[] = Ed25519Pem::toJwk($key['public'], $key['kid']);
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
        return $this->kid ??= Ed25519Pem::fingerprint($this->getOrCreateKeyPair()['public']);
    }

    /**
     * Rotate: retain the outgoing public key for overlap, generate a new
     * keypair, store it, and persist the previous-key sidecar.
     *
     * The outgoing key's PUBLIC half (kid + raw public key) is retained for
     * {@see OVERLAP_TTL_SECONDS} (24h) so enrollment JWTs minted under it keep
     * validating — and stay published in the JWKS — until that overlap window
     * lapses. Validation is bounded by the window, never by the token's own
     * expiry (the default enrollment JWT lives 7 days), so calling this method
     * requires every paired server to re-mint its enrollment JWT within 24
     * hours. After the window the previous key is dropped on next access and
     * its tokens are rejected.
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

        // Native PEM writer from the shared kernel (wave S2): the server
        // dialect — base64url body, 64-char folding, same `ED25519 PRIVATE
        // KEY` label. Format-local: {@see Ed25519Pem::parsePem()} reads this
        // and the historical one-line plain-base64 hub files alike, so a
        // swapped writer never orphans an existing key.
        $pem = Ed25519Pem::buildPem($secretKey);

        $dir = $this->ensureDirectory(dirname($this->keyPath));
        $this->writeSecretAtomically($pem, $this->keyPath, $dir);

        $this->privateKey = $secretKey;
        $this->publicKey = $publicKey;
        $this->kid = null;
    }

    /**
     * Write secret bytes through a 0600-from-birth temp file, then rename it
     * into place.
     *
     * `tempnam()` creates the file with mode 0600 regardless of the process
     * umask (PHP has no O_CREAT-mode stream context for regular files), and
     * `rename()` is atomic within a filesystem — so the secret is never
     * world-readable in a write-then-chmod window and a crash can never leave
     * a half-written key at the final path. Mirrors the estate fix in
     * phlix-server c9d546d0.
     *
     * @param string $bytes Contents to write.
     * @param string $path  Final destination path.
     * @param string $dir   Directory of the final path (the temp file must
     *                      live on the SAME filesystem for rename() to be atomic).
     *
     * @throws RuntimeException If the temp file cannot be created, written, or moved.
     */
    private function writeSecretAtomically(string $bytes, string $path, string $dir): void
    {
        $tmp = @tempnam($dir, 'ed25519-');
        if ($tmp === false) {
            throw new RuntimeException('Failed to create temp key file in: ' . $dir);
        }

        if (@file_put_contents($tmp, $bytes) === false) {
            @unlink($tmp);
            throw new RuntimeException('Failed to write key file: ' . $path);
        }

        // Belt-and-suspenders: tempnam is already 0600; chmod keeps the
        // guarantee explicit if that ever changes.
        @chmod($tmp, 0600);

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Failed to move key file into place: ' . $path);
        }
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
        $dir = $this->ensureDirectory(dirname($path));
        $this->writeSecretAtomically($payload, $path, $dir);
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
     * Extract the full 64-byte Ed25519 keypair from a PEM string.
     *
     * Two parse dialects, one strict gate (sign-seam differential, r1 class):
     *  1. The LEGACY hub file body — one-line (CR/LF/space-stripped) STANDARD
     *     base64 of the exact 64-byte raw keypair, marked or marker-less —
     *     parses under the hub's historical rules, byte-identical to the
     *     pre-delegation body (wave S2, 87007df1^): an OVERSIZED decoded body
     *     FAILS LOUD with the historical exception. The shared kernel's native
     *     arm would instead truncate to its first 64 bytes (the server's
     *     `strlen >= 64` posture); that softening is NOT admissible on this
     *     load seam feeding every federation sign operation
     *     ({@see \Phlix\Hub\Federation\FederationPeerManager} +
     *     {@see \Phlix\Hub\Federation\FederationFrameHandler} consume the
     *     secret), because a corrupted key file must stop boot loudly rather
     *     than sign with a silently truncated secret.
     *  2. MODERN files — this writer's kernel-folded base64url/64-fold
     *     server-dialect bodies (detected by alphabet) and non-ED25519-labeled
     *     PEM blocks such as PKCS#8 (detected by label) — delegate to
     *     {@see Ed25519Pem::parsePem()}. Both arms are ADDITIVE acceptance
     *     beyond the legacy dialect, required so a generate-then-load
     *     round-trip of freshly written keys succeeds; modernizing the legacy
     *     arm itself (fold-tolerant parsing with kept strictness) is banked
     *     for the v0.57 charter, not shipped here.
     *
     * A malformed hybrid (standard body corrupted into containing `-`/`_`)
     * falls to the kernel arm and fails loud with the modern message; the
     * exception TYPE (RuntimeException) is invariant across both arms and
     * every reject path, which is what callers branch on.
     *
     * @param string $pem PEM-encoded key.
     *
     * @return array{private: string, public: string}
     *
     * @throws RuntimeException When the PEM carries no parseable Ed25519 key.
     */
    private function extractKeyPair(string $pem): array
    {
        if ($this->isModernKeyFileDialect($pem)) {
            $parsed = Ed25519Pem::parsePem($pem);
            if ($parsed === null) {
                throw new RuntimeException(
                    'Ed25519 private key could not be parsed (expected a 64-byte Ed25519 key: native or PKCS#8).',
                );
            }

            return ['private' => $parsed['secret'], 'public' => $parsed['public']];
        }

        // Legacy body below: byte-identical to the pre-delegation parser (wave
        // S2 sign-seam differential; srv ee9f1c4c WebAuthn-parity precedent) —
        // exact-64 standard-base64 rule kept loud, marker-less files kept
        // loadable. Do not "tidy" this into the kernel call.
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
     * Does this stored key file use a MODERN dialect the legacy parser cannot
     * read? Two discriminators, both cheap and content-local: a PEM label
     * other than the hub's ED25519 PRIVATE KEY (PKCS#8 et al.) or a body
     * carrying base64url-only characters (`-`/`_`), which the kernel-folded
     * writer (Ed25519Pem::buildPem) emits but the historical one-line standard
     * alphabet never can. Standard-alphabet bodies — legacy files in both
     * marked and marker-less shapes — deliberately stay on the legacy arm so
     * their exact-64 strictness is enforced by the hub, not the kernel.
     */
    private function isModernKeyFileDialect(string $pem): bool
    {
        $labeled = preg_match('/-----BEGIN ([A-Z0-9 ]+)-----/', $pem, $matches) === 1;
        if ($labeled && $matches[1] !== 'ED25519 PRIVATE KEY') {
            return true;
        }

        $body = trim($pem);
        $body = (string) preg_replace('#-----(BEGIN|END) ED25519 PRIVATE KEY-----#', '', $body);
        $body = str_replace(["\r", "\n", ' '], '', $body);

        return preg_match('/[-_]/', $body) === 1;
    }

    /**
     * Current unix time from the injected clock.
     */
    private function now(): int
    {
        return ($this->clock)();
    }

    /**
     * Standard base64 encode — the PREVIOUS-KEY SIDECAR's own file format
     * (JSON + plain base64), deliberately not base64url and not cryptographic
     * key material on the wire; custody-local, so it stays.
     */
    private function base64Encode(string $data): string
    {
        return base64_encode($data);
    }

    /**
     * Standard base64 decode — inverse of {@see self::base64Encode()}.
     */
    private function base64Decode(string $data): string
    {
        $decoded = base64_decode($data, true);
        return $decoded !== false ? $decoded : '';
    }
}
