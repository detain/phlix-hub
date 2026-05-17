<?php

declare(strict_types=1);

namespace Phlex\Hub\Tests\unit\Hub;

use Phlex\Hub\Hub\Ed25519KeyManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for {@see Ed25519KeyManager}.
 *
 * @package Phlex\Hub\Tests\unit\Hub
 * @since 0.3.0
 *
 * @covers \Phlex\Hub\Hub\Ed25519KeyManager
 */
final class Ed25519KeyManagerTest extends TestCase
{
    private string $tmpDir;
    private string $keyPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/phlex-hub-test-' . uniqid();
        mkdir($this->tmpDir, 0700, true);
        $this->keyPath = $this->tmpDir . '/test-signing-key.pem';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_file($this->keyPath)) {
            unlink($this->keyPath);
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
        self::assertSame(32, strlen(base64_decode(strtr($jwk['x'], '-_', '+/'))));
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
}
