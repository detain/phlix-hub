<?php

declare(strict_types=1);

namespace Phlex\Hub\Tests\unit\Hub;

use Phlex\Hub\Common\Logger\StructuredLogger;
use Phlex\Hub\Hub\Ed25519KeyManager;
use Phlex\Hub\Hub\EnrollmentJwtService;
use Phlex\Hub\Hub\HeartbeatHandler;
use Phlex\Shared\Hub\HeartbeatDto;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see HeartbeatHandler}.
 *
 * @package Phlex\Hub\Tests\unit\Hub
 * @since 0.3.0
 *
 * @covers \Phlex\Hub\Hub\HeartbeatHandler
 */
final class HeartbeatHandlerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/phlex-hub-heartbeat-test-' . uniqid();
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

    public function testHandleUpdatesLastSeenAndStatus(): void
    {
        $serverId = 'server-update-test';

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql) use ($serverId) {
            if (str_contains($sql, 'FOR UPDATE')) {
                return [['id' => $serverId]];
            }
            if (str_contains($sql, 'UPDATE servers')) {
                self::assertStringContainsString("status = 'online'", $sql);
                self::assertStringContainsString('last_seen_at', $sql);
            }
            return [];
        });

        $keyManager = new Ed25519KeyManager($this->tmpDir . '/key.pem');
        $jwtService = new EnrollmentJwtService($keyManager, 'https://hub.example.com');
        $logger = $this->createMock(StructuredLogger::class);
        $handler = new HeartbeatHandler($db, $jwtService, $logger);

        $token = $jwtService->createEnrollmentJwt($serverId);
        $heartbeat = new HeartbeatDto(
            serverId: $serverId,
            version: '0.11.0',
            timestamp: time(),
            uptimeSeconds: 86400,
            activeSessions: 2,
            activeTranscodes: 1,
            hostnameCandidates: ['https://192.168.1.100:32400'],
        );

        $handler->handle($serverId, $token, $heartbeat);
        self::assertTrue(true);
    }

    public function testHandleThrowsOnInvalidEnrollmentJwt(): void
    {
        $db = $this->createMock(Connection::class);
        $keyManager = new Ed25519KeyManager($this->tmpDir . '/key.pem');
        $jwtService = new EnrollmentJwtService($keyManager, 'https://hub.example.com');
        $logger = $this->createMock(StructuredLogger::class);
        $handler = new HeartbeatHandler($db, $jwtService, $logger);

        $heartbeat = new HeartbeatDto(
            serverId: 'server-invalid',
            version: '0.11.0',
            timestamp: time(),
            uptimeSeconds: 100,
            activeSessions: 0,
            activeTranscodes: 0,
            hostnameCandidates: [],
        );

        $this->expectException(\InvalidArgumentException::class);
        $handler->handle('server-invalid', 'invalid-token', $heartbeat);
    }

    public function testHandleThrowsOnUnknownServer(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $keyManager = new Ed25519KeyManager($this->tmpDir . '/key.pem');
        $jwtService = new EnrollmentJwtService($keyManager, 'https://hub.example.com');
        $logger = $this->createMock(StructuredLogger::class);
        $handler = new HeartbeatHandler($db, $jwtService, $logger);

        $token = $jwtService->createEnrollmentJwt('unknown-server');
        $heartbeat = new HeartbeatDto(
            serverId: 'unknown-server',
            version: '0.11.0',
            timestamp: time(),
            uptimeSeconds: 100,
            activeSessions: 0,
            activeTranscodes: 0,
            hostnameCandidates: [],
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SERVER_NOT_FOUND');
        $handler->handle('unknown-server', $token, $heartbeat);
    }

    public function testIsServerOwnedByUserReturnsTrueWhenOwned(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([['id' => 'server-owned']]);

        $keyManager = new Ed25519KeyManager($this->tmpDir . '/key.pem');
        $jwtService = new EnrollmentJwtService($keyManager, 'https://hub.example.com');
        $logger = $this->createMock(StructuredLogger::class);
        $handler = new HeartbeatHandler($db, $jwtService, $logger);

        $result = $handler->isServerOwnedByUser('server-owned', 'user-1');
        self::assertTrue($result);
    }

    public function testIsServerOwnedByUserReturnsFalseWhenNotOwned(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $keyManager = new Ed25519KeyManager($this->tmpDir . '/key.pem');
        $jwtService = new EnrollmentJwtService($keyManager, 'https://hub.example.com');
        $logger = $this->createMock(StructuredLogger::class);
        $handler = new HeartbeatHandler($db, $jwtService, $logger);

        $result = $handler->isServerOwnedByUser('server-not-owned', 'user-2');
        self::assertFalse($result);
    }
}
