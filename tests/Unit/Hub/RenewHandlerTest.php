<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Hub\Ed25519KeyManager;
use Phlix\Hub\Hub\EnrollmentJwtService;
use Phlix\Hub\Hub\RenewHandler;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see RenewHandler}.
 *
 * @package Phlix\Hub\Tests\Unit\Hub
 */
final class RenewHandlerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-renew-test-' . uniqid();
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

    private function makeJwtService(): EnrollmentJwtService
    {
        $keyManager = new Ed25519KeyManager($this->tmpDir . '/key.pem');
        return new EnrollmentJwtService($keyManager, 'https://hub.example.com');
    }

    public function testRenewReturnsFreshJwtForValidToken(): void
    {
        $serverId = 'server-renew';

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([['id' => $serverId]]);

        $jwtService = $this->makeJwtService();
        $logger = $this->createMock(StructuredLogger::class);
        $handler = new RenewHandler($db, $jwtService, $logger);

        $currentToken = $jwtService->createEnrollmentJwt($serverId);
        $newToken = $handler->handle($serverId, $currentToken);

        self::assertNotSame('', $newToken);
        self::assertSame(3, substr_count($newToken, '.') + 1);

        // The freshly minted token must itself validate as a real enrollment JWT.
        $kid = $this->extractKid($newToken);
        $payload = $jwtService->validateEnrollmentJwt($newToken, $kid);
        self::assertNotNull($payload);
        self::assertSame($serverId, $payload['server_id'] ?? null);
    }

    public function testRenewThrowsOnInvalidToken(): void
    {
        $db = $this->createMock(Connection::class);
        $jwtService = $this->makeJwtService();
        $logger = $this->createMock(StructuredLogger::class);
        $handler = new RenewHandler($db, $jwtService, $logger);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ENROLLMENT_TOKEN_EXPIRED');
        $handler->handle('server-bad', 'bad-token');
    }

    public function testRenewThrowsOnExpiredToken(): void
    {
        $db = $this->createMock(Connection::class);
        $jwtService = $this->makeJwtService();
        $logger = $this->createMock(StructuredLogger::class);
        $handler = new RenewHandler($db, $jwtService, $logger);

        // TTL of -1 produces an already-expired token.
        $expiredToken = $jwtService->createEnrollmentJwt('server-exp', -1);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ENROLLMENT_TOKEN_EXPIRED');
        $handler->handle('server-exp', $expiredToken);
    }

    public function testRenewThrowsServerNotFoundOnIdMismatch(): void
    {
        $db = $this->createMock(Connection::class);
        $jwtService = $this->makeJwtService();
        $logger = $this->createMock(StructuredLogger::class);
        $handler = new RenewHandler($db, $jwtService, $logger);

        // Token is for a different server than the path id.
        $token = $jwtService->createEnrollmentJwt('server-a');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SERVER_NOT_FOUND');
        $handler->handle('server-b', $token);
    }

    public function testRenewThrowsServerNotFoundWhenRowMissing(): void
    {
        $serverId = 'server-gone';

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $jwtService = $this->makeJwtService();
        $logger = $this->createMock(StructuredLogger::class);
        $handler = new RenewHandler($db, $jwtService, $logger);

        $token = $jwtService->createEnrollmentJwt($serverId);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SERVER_NOT_FOUND');
        $handler->handle($serverId, $token);
    }

    /**
     * Extract the `kid` from a JWT header for validation in assertions.
     */
    private function extractKid(string $token): string
    {
        $parts = explode('.', $token);
        $decoded = base64_decode(strtr($parts[0], '-_', '+/'), true);
        self::assertNotFalse($decoded);
        /** @var array<string, mixed> $header */
        $header = json_decode($decoded, true);
        /** @var string $kid */
        $kid = $header['kid'] ?? '';
        return $kid;
    }
}
