<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Common\RateLimit\RateLimiterInterface;
use Phlix\Hub\Common\RateLimit\RateLimitState;
use Phlix\Hub\Hub\Ed25519KeyManager;
use Phlix\Hub\Hub\EnrollmentJwtService;
use Phlix\Hub\Hub\HeartbeatHandler;
use Phlix\Shared\Hub\HeartbeatDto;
use Phlix\Shared\Hub\LibraryRef;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see HeartbeatHandler}.
 *
 * @package Phlix\Hub\Tests\Unit\Hub
 *
 * @covers \Phlix\Hub\Hub\HeartbeatHandler
 */
final class HeartbeatHandlerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-heartbeat-test-' . uniqid();
        mkdir($this->tmpDir, 0700, true);
    }

    private function createRateLimiter(): RateLimiterInterface
    {
        $limiter = $this->createMock(RateLimiterInterface::class);
        $limiter->method('hit')->willReturn(new RateLimitState(1, 4, time() + 900, false, 5));
        return $limiter;
    }

    private function createHandler(Connection $db, EnrollmentJwtService $jwtService, StructuredLogger $logger): HeartbeatHandler
    {
        return new HeartbeatHandler($db, $jwtService, $logger, $this->createRateLimiter());
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
        $handler = $this->createHandler($db, $jwtService, $logger);

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

    public function testHandleCachesReportedLibraries(): void
    {
        $serverId = 'server-libs-test';

        $insertedLibraries = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use ($serverId, &$insertedLibraries) {
                if (str_contains($sql, 'FOR UPDATE')) {
                    return [['id' => $serverId]];
                }
                if (str_contains($sql, 'INSERT INTO server_libraries')) {
                    $insertedLibraries[] = [
                        'server_id' => $params['server_id'] ?? null,
                        'library_id' => $params['library_id'] ?? null,
                        'library_name' => $params['library_name'] ?? null,
                    ];
                }
                return [];
            }
        );

        $keyManager = new Ed25519KeyManager($this->tmpDir . '/key.pem');
        $jwtService = new EnrollmentJwtService($keyManager, 'https://hub.example.com');
        $logger = $this->createMock(StructuredLogger::class);
        $handler = $this->createHandler($db, $jwtService, $logger);

        $token = $jwtService->createEnrollmentJwt($serverId);
        $heartbeat = new HeartbeatDto(
            serverId: $serverId,
            version: '0.11.0',
            timestamp: time(),
            uptimeSeconds: 100,
            activeSessions: 0,
            activeTranscodes: 0,
            hostnameCandidates: [],
            libraries: [
                LibraryRef::fromPayload(['library_id' => 'lib-1', 'library_name' => 'Movies']),
                LibraryRef::fromPayload(['library_id' => 'lib-2', 'library_name' => 'TV']),
            ],
        );

        $handler->handle($serverId, $token, $heartbeat);

        self::assertSame(
            [
                ['server_id' => $serverId, 'library_id' => 'lib-1', 'library_name' => 'Movies'],
                ['server_id' => $serverId, 'library_id' => 'lib-2', 'library_name' => 'TV'],
            ],
            $insertedLibraries,
        );
    }

    public function testHandleThrowsOnInvalidEnrollmentJwt(): void
    {
        $db = $this->createMock(Connection::class);
        $keyManager = new Ed25519KeyManager($this->tmpDir . '/key.pem');
        $jwtService = new EnrollmentJwtService($keyManager, 'https://hub.example.com');
        $logger = $this->createMock(StructuredLogger::class);
        $handler = $this->createHandler($db, $jwtService, $logger);

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
        $handler = $this->createHandler($db, $jwtService, $logger);

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
        $handler = $this->createHandler($db, $jwtService, $logger);

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
        $handler = $this->createHandler($db, $jwtService, $logger);

        $result = $handler->isServerOwnedByUser('server-not-owned', 'user-2');
        self::assertFalse($result);
    }

    public function testHandleSkipsLibraryUpsertsWhenHashUnchanged(): void
    {
        $serverId = 'server-hash-same';

        $callCount = 0;
        $insertedLibraries = [];

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use ($serverId, &$callCount, &$insertedLibraries): array {
                ++$callCount;
                if (str_contains($sql, 'FOR UPDATE')) {
                    return [['id' => $serverId]];
                }
                if (str_contains($sql, 'SELECT hash FROM server_library_hashes')) {
                    // Return a hash matching the one the handler will compute for these libraries.
                    // Libraries: lib-1(Movies), lib-2(TV) sorted by id -> canonical JSON of
                    // [{"library_id":"lib-1","library_name":"Movies"},{"library_id":"lib-2","library_name":"TV"}]
                    // SHA-256 of that JSON:
                    return [['hash' => '198d10dca935fcae916a75f62b275ac247c3a8a582fa62422b43ab2629d764e6']];
                }
                if (str_contains($sql, 'INSERT INTO server_libraries')) {
                    $insertedLibraries[] = [
                        'server_id' => $params['server_id'] ?? null,
                        'library_id' => $params['library_id'] ?? null,
                        'library_name' => $params['library_name'] ?? null,
                    ];
                }
                return [];
            },
        );

        $keyManager = new Ed25519KeyManager($this->tmpDir . '/key.pem');
        $jwtService = new EnrollmentJwtService($keyManager, 'https://hub.example.com');
        $logger = $this->createMock(StructuredLogger::class);
        $handler = $this->createHandler($db, $jwtService, $logger);

        $token = $jwtService->createEnrollmentJwt($serverId);
        $heartbeat = new HeartbeatDto(
            serverId: $serverId,
            version: '0.11.0',
            timestamp: time(),
            uptimeSeconds: 100,
            activeSessions: 0,
            activeTranscodes: 0,
            hostnameCandidates: [],
            libraries: [
                LibraryRef::fromPayload(['library_id' => 'lib-1', 'library_name' => 'Movies']),
                LibraryRef::fromPayload(['library_id' => 'lib-2', 'library_name' => 'TV']),
            ],
        );

        $handler->handle($serverId, $token, $heartbeat);

        // No library upserts should have been attempted because the hash matched.
        self::assertSame([], $insertedLibraries);
    }

    public function testHandleProceedsWithLibraryUpsertsWhenHashDiffers(): void
    {
        $serverId = 'server-hash-diff';

        $insertedLibraries = [];

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use ($serverId, &$insertedLibraries): array {
                if (str_contains($sql, 'FOR UPDATE')) {
                    return [['id' => $serverId]];
                }
                if (str_contains($sql, 'SELECT hash FROM server_library_hashes')) {
                    // Return a different hash so the handler proceeds with upserts.
                    return [['hash' => '0000000000000000000000000000000000000000000000000000000000000000']];
                }
                if (str_contains($sql, 'INSERT INTO server_libraries')) {
                    $insertedLibraries[] = [
                        'server_id' => $params['server_id'] ?? null,
                        'library_id' => $params['library_id'] ?? null,
                        'library_name' => $params['library_name'] ?? null,
                    ];
                }
                return [];
            },
        );

        $keyManager = new Ed25519KeyManager($this->tmpDir . '/key.pem');
        $jwtService = new EnrollmentJwtService($keyManager, 'https://hub.example.com');
        $logger = $this->createMock(StructuredLogger::class);
        $handler = $this->createHandler($db, $jwtService, $logger);

        $token = $jwtService->createEnrollmentJwt($serverId);
        $heartbeat = new HeartbeatDto(
            serverId: $serverId,
            version: '0.11.0',
            timestamp: time(),
            uptimeSeconds: 100,
            activeSessions: 0,
            activeTranscodes: 0,
            hostnameCandidates: [],
            libraries: [
                LibraryRef::fromPayload(['library_id' => 'lib-1', 'library_name' => 'Movies']),
                LibraryRef::fromPayload(['library_id' => 'lib-2', 'library_name' => 'TV']),
            ],
        );

        $handler->handle($serverId, $token, $heartbeat);

        // Libraries should have been upserted since the hash differed.
        self::assertSame(
            [
                ['server_id' => $serverId, 'library_id' => 'lib-1', 'library_name' => 'Movies'],
                ['server_id' => $serverId, 'library_id' => 'lib-2', 'library_name' => 'TV'],
            ],
            $insertedLibraries,
        );
    }

    public function testHandleProceedsWithLibraryUpsertsWhenNoPreviousHash(): void
    {
        $serverId = 'server-no-hash';

        $insertedLibraries = [];

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use ($serverId, &$insertedLibraries): array {
                if (str_contains($sql, 'FOR UPDATE')) {
                    return [['id' => $serverId]];
                }
                if (str_contains($sql, 'SELECT hash FROM server_library_hashes')) {
                    // No previous hash — empty result set.
                    return [];
                }
                if (str_contains($sql, 'INSERT INTO server_libraries')) {
                    $insertedLibraries[] = [
                        'server_id' => $params['server_id'] ?? null,
                        'library_id' => $params['library_id'] ?? null,
                        'library_name' => $params['library_name'] ?? null,
                    ];
                }
                return [];
            },
        );

        $keyManager = new Ed25519KeyManager($this->tmpDir . '/key.pem');
        $jwtService = new EnrollmentJwtService($keyManager, 'https://hub.example.com');
        $logger = $this->createMock(StructuredLogger::class);
        $handler = $this->createHandler($db, $jwtService, $logger);

        $token = $jwtService->createEnrollmentJwt($serverId);
        $heartbeat = new HeartbeatDto(
            serverId: $serverId,
            version: '0.11.0',
            timestamp: time(),
            uptimeSeconds: 100,
            activeSessions: 0,
            activeTranscodes: 0,
            hostnameCandidates: [],
            libraries: [
                LibraryRef::fromPayload(['library_id' => 'lib-1', 'library_name' => 'Movies']),
            ],
        );

        $handler->handle($serverId, $token, $heartbeat);

        self::assertSame(
            [['server_id' => $serverId, 'library_id' => 'lib-1', 'library_name' => 'Movies']],
            $insertedLibraries,
        );
    }

    public function testPruneServerHeartbeatsReturnsZeroWhenFewerRowsThanKeepLast(): void
    {
        $serverId = 'server-prune-few';

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use ($serverId): array {
                // COUNT query returns 5 rows, keepLast is 100 → no DELETE should run.
                if (str_contains($sql, 'COUNT(*)')) {
                    return [['cnt' => 5]];
                }
                return [];
            },
        );

        $keyManager = new Ed25519KeyManager($this->tmpDir . '/key.pem');
        $jwtService = new EnrollmentJwtService($keyManager, 'https://hub.example.com');
        $logger = $this->createMock(StructuredLogger::class);
        $handler = $this->createHandler($db, $jwtService, $logger);

        $result = $handler->pruneServerHeartbeats($serverId, 100);

        self::assertSame(0, $result);
    }

    public function testPruneServerHeartbeatsDeletesOldRowsWhenMoreRowsThanKeepLast(): void
    {
        $serverId = 'server-prune-many';
        $keepLast = 100;

        $deleteCalled = false;

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use ($serverId, $keepLast, &$deleteCalled): mixed {
                // COUNT query returns 150 rows, keepLast is 100 → DELETE should run.
                if (str_contains($sql, 'COUNT(*)')) {
                    return [['cnt' => 150]];
                }
                // DELETE query should use the ring-delete pattern.
                if (str_contains($sql, 'DELETE FROM server_heartbeats')) {
                    $deleteCalled = true;
                    self::assertSame($serverId, $params['server_id']);
                    self::assertSame($serverId, $params['server_id2']);
                    self::assertSame($keepLast, $params['keep_last']);
                    return 50; // 50 rows deleted.
                }
                return [];
            },
        );

        $keyManager = new Ed25519KeyManager($this->tmpDir . '/key.pem');
        $jwtService = new EnrollmentJwtService($keyManager, 'https://hub.example.com');
        $logger = $this->createMock(StructuredLogger::class);
        $handler = $this->createHandler($db, $jwtService, $logger);

        $result = $handler->pruneServerHeartbeats($serverId, $keepLast);

        self::assertSame(50, $result);
        self::assertTrue($deleteCalled, 'DELETE should have been called');
    }

    public function testPruneServerHeartbeatsReturnsZeroWhenExactlyKeepLastRows(): void
    {
        $serverId = 'server-prune-exact';

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use ($serverId): array {
                // COUNT query returns exactly 100 rows, keepLast is 100 → no DELETE.
                if (str_contains($sql, 'COUNT(*)')) {
                    return [['cnt' => 100]];
                }
                return [];
            },
        );

        $keyManager = new Ed25519KeyManager($this->tmpDir . '/key.pem');
        $jwtService = new EnrollmentJwtService($keyManager, 'https://hub.example.com');
        $logger = $this->createMock(StructuredLogger::class);
        $handler = $this->createHandler($db, $jwtService, $logger);

        $result = $handler->pruneServerHeartbeats($serverId, 100);

        self::assertSame(0, $result);
    }

    public function testPruneAllServerHeartbeatsIteratesAllServers(): void
    {
        $prunedServers = [];

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, ?array $params = null) use (&$prunedServers): mixed {
                // DISTINCT server_id query (no params).
                if (str_contains($sql, 'DISTINCT server_id')) {
                    return [
                        ['server_id' => 'server-1'],
                        ['server_id' => 'server-2'],
                        ['server_id' => 'server-3'],
                    ];
                }
                // COUNT query per server.
                if (str_contains($sql, 'COUNT(*)')) {
                    $serverId = $params !== null ? ($params['server_id'] ?? '') : '';
                    $prunedServers[] = $serverId;
                    return [['cnt' => 150]]; // More than keepLast=100.
                }
                // DELETE queries.
                if (str_contains($sql, 'DELETE FROM server_heartbeats')) {
                    return 50;
                }
                return [];
            },
        );

        $keyManager = new Ed25519KeyManager($this->tmpDir . '/key.pem');
        $jwtService = new EnrollmentJwtService($keyManager, 'https://hub.example.com');
        $logger = $this->createMock(StructuredLogger::class);
        $handler = $this->createHandler($db, $jwtService, $logger);

        $result = $handler->pruneAllServerHeartbeats(100);

        // 3 servers × 50 deleted = 150 total.
        self::assertSame(150, $result);
        self::assertSame(['server-1', 'server-2', 'server-3'], $prunedServers);
    }

    public function testPruneAllServerHeartbeatsHandlesEmptyServerList(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, ?array $params = null): array {
                // DISTINCT server_id query returns empty.
                if (str_contains($sql, 'DISTINCT server_id')) {
                    return [];
                }
                return [];
            },
        );

        $keyManager = new Ed25519KeyManager($this->tmpDir . '/key.pem');
        $jwtService = new EnrollmentJwtService($keyManager, 'https://hub.example.com');
        $logger = $this->createMock(StructuredLogger::class);
        $handler = $this->createHandler($db, $jwtService, $logger);

        $result = $handler->pruneAllServerHeartbeats(100);

        self::assertSame(0, $result);
    }
}
