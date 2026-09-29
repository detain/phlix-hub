<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Http\Middleware;

use Phlix\Hub\Hub\Ed25519KeyManager;
use Phlix\Hub\Hub\EnrollmentJwtService;
use Phlix\Hub\Http\Middleware\EnrollmentJwtMiddleware;
use Phlix\Hub\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see EnrollmentJwtMiddleware}.
 *
 * @package Phlix\Hub\Tests\Unit\Http\Middleware
 */
final class EnrollmentJwtMiddlewareTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-mw-test-' . uniqid();
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $files = glob($this->tmpDir . '/*');
        self::assertIsArray($files);
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    public function testValidTokenSetsServerId(): void
    {
        $keyManager = new Ed25519KeyManager($this->tmpDir . '/key.pem');
        $jwtService = new EnrollmentJwtService($keyManager, 'https://hub.example.com');
        $middleware = new EnrollmentJwtMiddleware($jwtService);

        $serverId = 'server-test-123';
        $token = $jwtService->createEnrollmentJwt($serverId);
        $kid = $keyManager->getKid();

        $request = new Request();
        $request->bearerToken = $token;

        $result = $middleware($request);

        self::assertNull($result);
        self::assertSame($serverId, $request->serverId);
    }

    public function testGenuinelyExpiredTokenReturnsExpired401(): void
    {
        $keyManager = new Ed25519KeyManager($this->tmpDir . '/key.pem');
        $jwtService = new EnrollmentJwtService($keyManager, 'https://hub.example.com');
        $middleware = new EnrollmentJwtMiddleware($jwtService);

        // Negative TTL: correctly signed by the hub's own key, but past `exp`.
        $token = $jwtService->createEnrollmentJwt('server-old', -10);

        $request = new Request();
        $request->bearerToken = $token;

        $result = $middleware($request);

        self::assertNotNull($result);
        self::assertSame(401, $result->statusCode);
        self::assertStringContainsString('ENROLLMENT_TOKEN_EXPIRED', $result->body);
        self::assertStringContainsString('auth.enrollment_expired', $result->body);
    }

    /**
     * A token signed by a DIFFERENT hub key is an unknown kid, not an expiry —
     * labelling it EXPIRED was the misdiagnosis this middleware fix targets.
     */
    public function testUnknownKidTokenReportsInvalidNotExpired(): void
    {
        $keyManager = new Ed25519KeyManager($this->tmpDir . '/key.pem');
        $jwtService = new EnrollmentJwtService($keyManager, 'https://hub.example.com');
        $middleware = new EnrollmentJwtMiddleware($jwtService);

        $keyManager2 = new Ed25519KeyManager($this->tmpDir . '/key2.pem');
        $jwtService2 = new EnrollmentJwtService($keyManager2, 'https://hub.example.com');
        $token = $jwtService2->createEnrollmentJwt('server-foreign');

        $request = new Request();
        $request->bearerToken = $token;

        $result = $middleware($request);

        self::assertNotNull($result);
        self::assertSame(401, $result->statusCode);
        self::assertStringContainsString('ENROLLMENT_TOKEN_INVALID', $result->body);
        self::assertStringContainsString('auth.invalid_token', $result->body);
        self::assertStringNotContainsString('ENROLLMENT_TOKEN_EXPIRED', $result->body);
    }

    public function testTamperedSignatureReportsInvalid(): void
    {
        $keyManager = new Ed25519KeyManager($this->tmpDir . '/key.pem');
        $jwtService = new EnrollmentJwtService($keyManager, 'https://hub.example.com');
        $middleware = new EnrollmentJwtMiddleware($jwtService);

        $token = $jwtService->createEnrollmentJwt('server-tampered');
        [$h, $p, $s] = explode('.', $token);
        // Flip the token's payload (server_id) while keeping the old signature.
        $tamperedPayload = json_encode([
            'iss' => 'phlix-hub', 'aud' => 'phlix-server', 'exp' => time() + 60, 'server_id' => 'evil',
        ], JSON_THROW_ON_ERROR);
        $tampered = $h . '.' . rtrim(strtr(base64_encode($tamperedPayload), '+/', '-_'), '=') . '.' . $s;

        $request = new Request();
        $request->bearerToken = $tampered;

        $result = $middleware($request);

        self::assertNotNull($result);
        self::assertSame(401, $result->statusCode);
        self::assertStringContainsString('auth.invalid_token', $result->body);
    }

    public function testMissingTokenReturns401(): void
    {
        $keyManager = new Ed25519KeyManager($this->tmpDir . '/key.pem');
        $jwtService = new EnrollmentJwtService($keyManager, 'https://hub.example.com');
        $middleware = new EnrollmentJwtMiddleware($jwtService);

        $request = new Request();

        $result = $middleware($request);

        self::assertNotNull($result);
        self::assertSame(401, $result->statusCode);
    }

    public function testMalformedTokenReturns401(): void
    {
        $keyManager = new Ed25519KeyManager($this->tmpDir . '/key.pem');
        $jwtService = new EnrollmentJwtService($keyManager, 'https://hub.example.com');
        $middleware = new EnrollmentJwtMiddleware($jwtService);

        $request = new Request();
        $request->bearerToken = 'not-a-valid-jwt';

        $result = $middleware($request);

        self::assertNotNull($result);
        self::assertSame(401, $result->statusCode);
    }
}
