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
 *
 * @covers \Phlix\Hub\Http\Middleware\EnrollmentJwtMiddleware
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

    public function testExpiredTokenReturns401(): void
    {
        $keyManager = new Ed25519KeyManager($this->tmpDir . '/key.pem');
        $jwtService = new EnrollmentJwtService($keyManager, 'https://hub.example.com');
        $middleware = new EnrollmentJwtMiddleware($jwtService);

        $keyManager2 = new Ed25519KeyManager($this->tmpDir . '/key2.pem');
        $jwtService2 = new EnrollmentJwtService($keyManager2, 'https://hub.example.com');
        $token = $jwtService2->createEnrollmentJwt('server-expired');

        $request = new Request();
        $request->bearerToken = $token;

        $result = $middleware($request);

        self::assertNotNull($result);
        self::assertSame(401, $result->statusCode);
        self::assertStringContainsString('ENROLLMENT_TOKEN_EXPIRED', $result->body);
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
