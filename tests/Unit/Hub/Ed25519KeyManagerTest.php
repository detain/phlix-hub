<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use Phlix\Hub\Hub\Ed25519KeyManager;
use Phlix\Hub\Tests\Support\DecodedJsonAssertions;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for {@see Ed25519KeyManager}.
 *
 * @package Phlix\Hub\Tests\Unit\Hub
 */
final class Ed25519KeyManagerTest extends TestCase
{
    use DecodedJsonAssertions;

    private string $tmpDir;
    private string $keyPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-test-' . uniqid();
        mkdir($this->tmpDir, 0700, true);
        $this->keyPath = $this->tmpDir . '/test-signing-key.pem';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    public function testGeneratesKeyPairOnFirstCall(): void
    {
        $manager = new Ed25519KeyManager($this->keyPath);
        $pair = $manager->getOrCreateKeyPair();

        self::assertArrayHasKey('private', $pair);
        self::assertArrayHasKey('public', $pair);
        self::assertSame(64, strlen($pair['private']));
        self::assertSame(32, strlen($pair['public']));
    }

    public function testLoadsExistingKeyOnSubsequentCalls(): void
    {
        $manager1 = new Ed25519KeyManager($this->keyPath);
        $pair1 = $manager1->getOrCreateKeyPair();

        $manager2 = new Ed25519KeyManager($this->keyPath);
        $pair2 = $manager2->getOrCreateKeyPair();

        self::assertSame($pair1['private'], $pair2['private']);
        self::assertSame($pair1['public'], $pair2['public']);
    }

    public function testGetPublicKeyJwkReturnsValidStructure(): void
    {
        $manager = new Ed25519KeyManager($this->keyPath);
        $jwk = $manager->getPublicKeyJwk();

        self::assertSame('OKP', $jwk['kty']);
        self::assertSame('Ed25519', $jwk['crv']);
        self::assertSame(32, strlen(base64_decode(strtr(self::stringNode($jwk['x']), '-_', '+/'))));
        self::assertSame('sig', $jwk['use']);
        self::assertSame('EdDSA', $jwk['alg']);
        self::assertNotEmpty($jwk['kid']);
    }

    public function testGetKidReturnsNonEmptyString(): void
    {
        $manager = new Ed25519KeyManager($this->keyPath);
        self::assertNotEmpty($manager->getKid());
    }

    public function testRotateGeneratesNewKey(): void
    {
        $manager = new Ed25519KeyManager($this->keyPath);
        $pair1 = $manager->getOrCreateKeyPair();

        $manager->rotate();
        $pair2 = $manager->getOrCreateKeyPair();

        self::assertNotSame($pair1['private'], $pair2['private']);
        self::assertNotSame($pair1['public'], $pair2['public']);
    }

    public function testKeyFileHasCorrectPermissions(): void
    {
        $manager = new Ed25519KeyManager($this->keyPath);
        $manager->getOrCreateKeyPair();

        $perms = fileperms($this->keyPath) & 0777;
        self::assertSame(0600, $perms);
    }

    /**
     * S7: a never-rotated manager exposes exactly one active key (and one JWK)
     * and no previous-key sidecar — the single-key path is unchanged.
     */
    public function testSingleKeyManagerExposesOnlyCurrentKey(): void
    {
        $manager = new Ed25519KeyManager($this->keyPath);
        $manager->getOrCreateKeyPair();

        self::assertCount(1, $manager->getActivePublicKeys());
        self::assertCount(1, $manager->getPublicKeyJwks());
        self::assertFalse(
            is_file($this->keyPath . '.previous.json'),
            'never-rotated manager must not create a previous-key sidecar',
        );
    }

    /**
     * S7: during the overlap window the rotated-out key is still active for
     * verification AND published in the JWKS alongside the new current key.
     */
    public function testRotateRetainsPreviousKeyDuringOverlap(): void
    {
        $now = 1_000_000;
        $manager = new Ed25519KeyManager($this->keyPath, static fn (): int => $now);
        $oldPublic = $manager->getOrCreateKeyPair()['public'];
        $oldKid = $manager->getKid();

        $manager->rotate();
        $newKid = $manager->getKid();

        self::assertNotSame($oldKid, $newKid);

        // Previous key resolvable for verification.
        self::assertSame($oldPublic, $manager->getPublicKeyForKid($oldKid));
        self::assertNotNull($manager->getPublicKeyForKid($newKid));

        // Both kids present in active keys + JWKS.
        $activeKids = array_map(static fn (array $k): string => $k['kid'], $manager->getActivePublicKeys());
        self::assertEqualsCanonicalizing([$newKid, $oldKid], $activeKids);

        $jwkKids = array_map(static fn (array $k): string => self::stringNode($k['kid']), $manager->getPublicKeyJwks());
        self::assertEqualsCanonicalizing([$newKid, $oldKid], $jwkKids);

        // The current key is always first / listed.
        self::assertContains($newKid, $jwkKids);
    }

    /**
     * S7: once the overlap window lapses the previous key is dropped — gone from
     * active keys, the JWKS, and unresolvable by kid. The sidecar is NOT pruned
     * on the hot path (no unlink during verification) — it is left on disk and
     * can be cleaned up via {@see Ed25519KeyManager::purgeExpiredPreviousKey()}.
     */
    public function testPreviousKeyDroppedAfterOverlapExpiry(): void
    {
        $now = 2_000_000;
        $clock = static function () use (&$now): int {
            return $now;
        };
        $manager = new Ed25519KeyManager($this->keyPath, $clock);
        $manager->getOrCreateKeyPair();
        $oldKid = $manager->getKid();

        $manager->rotate();
        $newKid = $manager->getKid();

        // Still inside the window.
        self::assertNotNull($manager->getPublicKeyForKid($oldKid));

        // Advance past the 24h overlap window.
        $now += Ed25519KeyManager::OVERLAP_TTL_SECONDS + 1;

        self::assertNull($manager->getPublicKeyForKid($oldKid), 'previous kid must be rejected after overlap');
        self::assertNotNull($manager->getPublicKeyForKid($newKid), 'current kid must still resolve');

        $activeKids = array_map(static fn (array $k): string => $k['kid'], $manager->getActivePublicKeys());
        self::assertSame([$newKid], $activeKids);
        self::assertCount(1, $manager->getPublicKeyJwks());

        // Sidecar is NOT deleted on the verification path (no I/O on hot path).
        self::assertTrue(
            is_file($this->keyPath . '.previous.json'),
            'expired previous-key sidecar must NOT be pruned on verification path — use purgeExpiredPreviousKey()',
        );
    }

    /**
     * S7: {@see Ed25519KeyManager::purgeExpiredPreviousKey()} removes the sidecar
     * when the overlap window has lapsed.
     */
    public function testPurgeExpiredPreviousKeyRemovesSidecar(): void
    {
        $now = 2_000_000;
        $clock = static function () use (&$now): int {
            return $now;
        };
        $manager = new Ed25519KeyManager($this->keyPath, $clock);
        $manager->getOrCreateKeyPair();
        $oldKid = $manager->getKid();
        $manager->rotate();
        $newKid = $manager->getKid();

        // Advance past the 24h overlap window.
        $now += Ed25519KeyManager::OVERLAP_TTL_SECONDS + 1;

        // Before purge: old kid not served but file still exists.
        self::assertNull($manager->getPublicKeyForKid($oldKid), 'previous kid must not be served after expiry');
        self::assertNotNull($manager->getPublicKeyForKid($newKid), 'current kid must still be served');
        self::assertTrue(is_file($this->keyPath . '.previous.json'));

        // Purge removes the sidecar.
        $manager->purgeExpiredPreviousKey();
        self::assertFalse(
            is_file($this->keyPath . '.previous.json'),
            'purgeExpiredPreviousKey() must remove the expired sidecar',
        );
    }

    /**
     * S7: the retained previous key survives a process restart (a fresh manager
     * loading the same key file + sidecar still honours the overlap window).
     */
    public function testPreviousKeySurvivesManagerReloadDuringOverlap(): void
    {
        $now = 3_000_000;
        $manager = new Ed25519KeyManager($this->keyPath, static fn (): int => $now);
        $manager->getOrCreateKeyPair();
        $oldKid = $manager->getKid();
        $manager->rotate();
        $newKid = $manager->getKid();

        // Simulate a restart: new manager, same files, same wall clock.
        $reloaded = new Ed25519KeyManager($this->keyPath, static fn (): int => $now);

        self::assertSame($newKid, $reloaded->getKid(), 'current kid stable across reload');
        self::assertNotNull($reloaded->getPublicKeyForKid($oldKid), 'previous kid still valid after reload');
        self::assertCount(2, $reloaded->getActivePublicKeys());

        // A reload AFTER the window must drop the previous key.
        $afterExpiry = $now + Ed25519KeyManager::OVERLAP_TTL_SECONDS + 1;
        $expired = new Ed25519KeyManager($this->keyPath, static fn (): int => $afterExpiry);
        self::assertNull($expired->getPublicKeyForKid($oldKid));
        self::assertCount(1, $expired->getActivePublicKeys());
    }
}
