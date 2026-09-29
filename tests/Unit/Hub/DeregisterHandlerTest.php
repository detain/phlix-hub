<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Hub\DeregisterHandler;
use Phlix\Hub\Hub\Ed25519KeyManager;
use Phlix\Hub\Hub\EnrollmentJwtService;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see DeregisterHandler}.
 *
 * @package Phlix\Hub\Tests\Unit\Hub
 */
final class DeregisterHandlerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-deregister-test-' . uniqid();
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

    public function testDeregisterDeletesRow(): void
    {
        $serverId = 'server-to-delete';

        $db = $this->createMock(Connection::class);
        // MySQL dialect: plain DELETE, decision by affected-row count (int),
        // never `DELETE ... RETURNING` (PostgreSQL-only — raised ERROR 1064).
        $db->expects(self::once())
            ->method('query')
            ->with(
                self::logicalAnd(
                    self::stringStartsWith('DELETE FROM servers'),
                    self::logicalNot(self::stringContains('RETURNING')),
                ),
                ['id' => $serverId],
            )
            ->willReturn(1);

        $keyManager = new Ed25519KeyManager($this->tmpDir . '/key.pem');
        $jwtService = new EnrollmentJwtService($keyManager, 'https://hub.example.com');
        $logger = $this->createMock(StructuredLogger::class);
        $handler = new DeregisterHandler($db, $jwtService, $logger);

        $token = $jwtService->createEnrollmentJwt($serverId);
        $handler->handle($serverId, $token);
        self::addToAssertionCount(1);
    }

    public function testDeregisterThrowsWhenDeleteAffectsNoRows(): void
    {
        $serverId = 'server-already-gone';

        $db = $this->createMock(Connection::class);
        // Row vanished between JWT check and DELETE → rowCount 0 → 404 path.
        $db->method('query')->willReturn(0);

        $keyManager = new Ed25519KeyManager($this->tmpDir . '/key.pem');
        $jwtService = new EnrollmentJwtService($keyManager, 'https://hub.example.com');
        $logger = $this->createMock(StructuredLogger::class);
        $handler = new DeregisterHandler($db, $jwtService, $logger);

        $token = $jwtService->createEnrollmentJwt($serverId);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SERVER_NOT_FOUND');
        $handler->handle($serverId, $token);
    }

    public function testDeregisterThrowsOnInvalidToken(): void
    {
        $db = $this->createMock(Connection::class);
        $keyManager = new Ed25519KeyManager($this->tmpDir . '/key.pem');
        $jwtService = new EnrollmentJwtService($keyManager, 'https://hub.example.com');
        $logger = $this->createMock(StructuredLogger::class);
        $handler = new DeregisterHandler($db, $jwtService, $logger);

        $this->expectException(\InvalidArgumentException::class);
        $handler->handle('server-bad', 'bad-token');
    }

    public function testDeregisterThrowsOnUnknownServer(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn(0);

        $keyManager = new Ed25519KeyManager($this->tmpDir . '/key.pem');
        $jwtService = new EnrollmentJwtService($keyManager, 'https://hub.example.com');
        $logger = $this->createMock(StructuredLogger::class);
        $handler = new DeregisterHandler($db, $jwtService, $logger);

        $token = $jwtService->createEnrollmentJwt('unknown-server');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SERVER_NOT_FOUND');
        $handler->handle('unknown-server', $token);
    }
}
