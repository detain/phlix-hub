<?php

declare(strict_types=1);

namespace Phlex\Hub\Tests\unit\Hub;

use Phlex\Hub\Hub\Ed25519KeyManager;
use Phlex\Hub\Hub\EnrollmentJwtService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see EnrollmentJwtService}.
 *
 * @package Phlex\Hub\Tests\unit\Hub
 * @since 0.3.0
 *
 * @covers \Phlex\Hub\Hub\EnrollmentJwtService
 */
final class EnrollmentJwtServiceTest extends TestCase
{
    private string $tmpDir;
    private Ed25519KeyManager $keyManager;
    private EnrollmentJwtService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/phlex-hub-enrollment-test-' . uniqid();
        mkdir($this->tmpDir, 0700, true);
        $keyPath = $this->tmpDir . '/signing-key.pem';
        $this->keyManager = new Ed25519KeyManager($keyPath);
        $this->service = new EnrollmentJwtService($this->keyManager, 'https://hub.example.com');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $files = glob($this->tmpDir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    public function testCreateEnrollmentJwtReturnsWellFormedJwt(): void
    {
        $token = $this->service->createEnrollmentJwt('server-uuid-123');

        self::assertIsString($token);
        $parts = explode('.', $token);
        self::assertCount(3, $parts);
    }

    public function testCreateEnrollmentJwtRoundTrips(): void
    {
        $serverId = 'server-uuid-456';
        $token = $this->service->createEnrollmentJwt($serverId);
        $kid = $this->keyManager->getKid();

        $payload = $this->service->validateEnrollmentJwt($token, $kid);

        self::assertNotNull($payload);
        self::assertSame('phlex-hub', $payload['iss']);
        self::assertSame('server', $payload['aud']);
        self::assertSame($serverId, $payload['sub']);
        self::assertSame($serverId, $payload['server_id']);
        self::assertSame('https://hub.example.com', $payload['hub_base_url']);
    }

    public function testValidateEnrollmentJwtReturnsNullForWrongKid(): void
    {
        $token = $this->service->createEnrollmentJwt('server-xyz');
        $payload = $this->service->validateEnrollmentJwt($token, 'wrong-kid');
        self::assertNull($payload);
    }

    public function testValidateEnrollmentJwtReturnsNullForTamperedToken(): void
    {
        $token = $this->service->createEnrollmentJwt('server-tampered');
        $tampered = substr($token, 0, -1) . (substr($token, -1) === 'A' ? 'B' : 'A');
        $kid = $this->keyManager->getKid();

        $payload = $this->service->validateEnrollmentJwt($tampered, $kid);
        self::assertNull($payload);
    }

    public function testCreateEnrollmentJwtWithCustomTtl(): void
    {
        $token = $this->service->createEnrollmentJwt('server-ttl', 3600);
        $kid = $this->keyManager->getKid();

        $payload = $this->service->validateEnrollmentJwt($token, $kid);
        self::assertNotNull($payload);
        self::assertGreaterThan(time(), $payload['exp']);
        self::assertLessThanOrEqual(time() + 3605, $payload['exp']);
    }

    public function testGetHubJwksUrlReturnsCorrectUrl(): void
    {
        $url = $this->service->getHubJwksUrl();
        self::assertSame('https://hub.example.com/.well-known/jwks.json', $url);
    }

    public function testGetHubBaseUrlReturnsBaseWithoutTrailingSlash(): void
    {
        $service = new EnrollmentJwtService($this->keyManager, 'https://hub.example.com/');
        self::assertSame('https://hub.example.com', $service->getHubBaseUrl());
    }

    public function testValidateEnrollmentJwtReturnsNullForExpiredToken(): void
    {
        $keyPath = $this->tmpDir . '/expired.pem';
        $km = new Ed25519KeyManager($keyPath);
        $service = new EnrollmentJwtService($km, 'https://hub.example.com');

        $token = $service->createEnrollmentJwt('server-expired', -10);
        $payload = $service->validateEnrollmentJwt($token, $km->getKid());
        self::assertNull($payload);
    }
}
