<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Auth\RateLimitException;
use Phlix\Hub\Common\RateLimit\RateLimiter;
use Phlix\Hub\Common\RateLimit\RateLimiterInterface;
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
        // HB-4.6c: exercise the REAL per-surface heartbeat RateLimiter (30/60s)
        // rather than a limited:false stub — a single hit never trips.
        return new RateLimiter(60, 30);
    }

    private function createHandler(
        Connection $db,
        EnrollmentJwtService $jwtService,
        StructuredLogger $logger,
        ?RateLimiterInterface $rateLimiter = null,
    ): HeartbeatHandler {
        return new HeartbeatHandler($db, $jwtService, $logger, $rateLimiter ?? $this->createRateLimiter());
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
        self::addToAssertionCount(1);
    }

    /**
     * R19-04 law pin: the two DATETIME liveness columns a heartbeat writes
     * (servers.last_seen_at, server_heartbeats.received_at) are stamped with
     * the DATABASE clock (NOW()), never a PHP date()-formatted string. Both
     * columns are aged against MySQL NOW() by ServerReaper, so a PHP-written
     * value in a different process timezone skews the freshness/retention
     * maths and false-flips servers offline. Pins: NOW() present in each
     * statement AND no clock param left in the bind arrays.
     */
    public function testHandleStampsLivenessColumnsWithDatabaseClock(): void
    {
        $serverId = 'server-db-clock';

        /** @var array<string, array{sql: string, params: array<string, mixed>}> $writes */
        $writes = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, $params = null) use ($serverId, &$writes): array {
                if (str_contains($sql, 'FOR UPDATE')) {
                    return [['id' => $serverId]];
                }
                if (str_contains($sql, 'UPDATE servers')) {
                    $writes['update_servers'] = ['sql' => $sql, 'params' => is_array($params) ? $params : []];
                }
                if (str_contains($sql, 'INSERT INTO server_heartbeats')) {
                    $writes['insert_heartbeats'] = ['sql' => $sql, 'params' => is_array($params) ? $params : []];
                }
                return [];
            },
        );

        $keyManager = new Ed25519KeyManager($this->tmpDir . '/key.pem');
        $jwtService = new EnrollmentJwtService($keyManager, 'https://hub.example.com');
        $logger = $this->createMock(StructuredLogger::class);
        $handler = $this->createHandler($db, $jwtService, $logger);

        $handler->handle($serverId, $jwtService->createEnrollmentJwt($serverId), new HeartbeatDto(
            serverId: $serverId,
            version: '0.11.0',
            timestamp: time(),
            uptimeSeconds: 86400,
            activeSessions: 0,
            activeTranscodes: 0,
            hostnameCandidates: [],
        ));

        self::assertArrayHasKey('update_servers', $writes);
        self::assertArrayHasKey('insert_heartbeats', $writes);
        self::assertStringContainsString('last_seen_at = NOW()', $writes['update_servers']['sql']);
        self::assertStringContainsString(', NOW())', $writes['insert_heartbeats']['sql']);
        self::assertArrayNotHasKey('last_seen_at', $writes['update_servers']['params']);
        self::assertArrayNotHasKey('received_at', $writes['insert_heartbeats']['params']);
        foreach ($writes as $write) {
            foreach ($write['params'] as $value) {
                self::assertFalse(
                    is_string($value) && preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $value) === 1,
                    'A PHP-clock DATETIME literal must never ride the bind params: ' . $write['sql'],
                );
            }
        }
    }

    /**
     * HB-4.6c headline bug: the old 5/900 login-grade limiter tripped the
     * legitimate ~60s heartbeat cadence. Drive 20 heartbeats at a 60s cadence
     * (simulated advancing clock) through ONE handler backed by the REAL
     * `rate_limiter.heartbeat` profile (30/60s) and assert none is ever refused
     * with {@see RateLimitException}. Keyed by the JWT-proven server id.
     */
    public function testLegitimateHeartbeatCadenceNeverTrips(): void
    {
        $serverId = 'server-cadence-test';

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql) use ($serverId) {
            if (str_contains($sql, 'FOR UPDATE')) {
                return [['id' => $serverId]];
            }
            return [];
        });

        $keyManager = new Ed25519KeyManager($this->tmpDir . '/key.pem');
        $jwtService = new EnrollmentJwtService($keyManager, 'https://hub.example.com');
        $logger = $this->createMock(StructuredLogger::class);

        // Advancing clock: +60s per heartbeat (the legitimate cadence).
        $now = 1_000_000;
        $clock = static function () use (&$now): int {
            return $now;
        };
        $rateLimiter = new RateLimiter(60, 30, 10000, $clock);
        $handler = $this->createHandler($db, $jwtService, $logger, $rateLimiter);

        $token = $jwtService->createEnrollmentJwt($serverId);

        for ($i = 0; $i < 20; $i++) {
            $heartbeat = new HeartbeatDto(
                serverId: $serverId,
                version: '0.11.0',
                timestamp: $now,
                uptimeSeconds: 86400,
                activeSessions: 0,
                activeTranscodes: 0,
                hostnameCandidates: ['https://192.168.1.100:32400'],
            );
            // Must not throw — a legitimate 60s-cadence heartbeat is never limited.
            $handler->handle($serverId, $token, $heartbeat);
            $now += 60;
        }

        self::addToAssertionCount(1);
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
            function (string $sql, array $params = []): array {
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
            function (string $sql, array $params = []): array {
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

    // ---- M-HUB-15: server_libraries size caps + stale-row pruning ----

    /**
     * When a changed library set is accepted, stale cached rows must be pruned
     * in the SAME transaction: the DELETE removes every server_libraries row
     * whose library_id is NOT in the reported set. Without this an enrolled
     * server (hostile or merely rotating ids) grows its rows forever.
     */
    public function testAcceptedLibrarySetPrunesStaleRowsInSameFlow(): void
    {
        $serverId = 'server-prune-test';

        /** @var list<string> $statements */
        $statements = [];
        /** @var array<string, array<string, mixed>> $pruneParams */
        $pruneParams = [];

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, $params = null) use ($serverId, &$statements, &$pruneParams): mixed {
                $statements[] = $sql;
                if (str_contains($sql, 'FOR UPDATE')) {
                    return [['id' => $serverId]];
                }
                if (str_contains($sql, 'SELECT hash FROM server_library_hashes')) {
                    // Differing hash → the set is accepted and the write leg runs.
                    return [['hash' => '0000000000000000000000000000000000000000000000000000000000000000']];
                }
                if (str_contains($sql, 'DELETE FROM server_libraries')) {
                    self::assertIsArray($params);
                    $pruneParams = $params;
                }
                return [];
            },
        );

        $keyManager = new Ed25519KeyManager($this->tmpDir . '/key.pem');
        $jwtService = new EnrollmentJwtService($keyManager, 'https://hub.example.com');
        $logger = $this->createMock(StructuredLogger::class);
        $handler = $this->createHandler($db, $jwtService, $logger);

        $handler->handle($serverId, $jwtService->createEnrollmentJwt($serverId), new HeartbeatDto(
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
        ));

        $deleteSql = null;
        foreach ($statements as $sql) {
            if (str_contains($sql, 'DELETE FROM server_libraries')) {
                $deleteSql = $sql;
            }
        }
        self::assertIsString($deleteSql, 'Accepted library sets must prune stale server_libraries rows.');
        self::assertStringContainsString('NOT IN', $deleteSql);
        // Generated named placeholders, one per reported library (RelaySessionManager precedent).
        self::assertSame(2, substr_count($deleteSql, ':keep_library_id_'));

        // The prune is scoped to this server and keeps exactly the reported ids.
        self::assertSame($serverId, $pruneParams['server_id'] ?? null);
        $keepIds = [];
        foreach ($pruneParams as $key => $value) {
            if (str_starts_with((string) $key, 'keep_library_id_')) {
                $keepIds[] = $value;
            }
        }
        sort($keepIds);
        self::assertSame(['lib-1', 'lib-2'], $keepIds);
    }

    /**
     * The hash-equal skip must stay a FULL write skip: no upserts, no prune
     * DELETE, no hash-row update. A beat identical to the accepted one changes
     * nothing to prune.
     */
    public function testHashEqualBeatWritesNothingToLibraryTables(): void
    {
        $serverId = 'server-hash-skip-no-writes';

        /** @var list<string> $libraryTableStatements */
        $libraryTableStatements = [];

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, $params = null) use ($serverId, &$libraryTableStatements): mixed {
                if (str_contains($sql, 'FOR UPDATE')) {
                    return [['id' => $serverId]];
                }
                if (str_contains($sql, 'SELECT hash FROM server_library_hashes')) {
                    // Same hash the handler computes for [lib-1(Movies), lib-2(TV)].
                    return [['hash' => '198d10dca935fcae916a75f62b275ac247c3a8a582fa62422b43ab2629d764e6']];
                }
                if (str_contains($sql, 'server_libraries') || str_contains($sql, 'server_library_hashes')) {
                    $libraryTableStatements[] = $sql;
                }
                return [];
            },
        );

        $keyManager = new Ed25519KeyManager($this->tmpDir . '/key.pem');
        $jwtService = new EnrollmentJwtService($keyManager, 'https://hub.example.com');
        $logger = $this->createMock(StructuredLogger::class);
        $handler = $this->createHandler($db, $jwtService, $logger);

        $handler->handle($serverId, $jwtService->createEnrollmentJwt($serverId), new HeartbeatDto(
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
        ));

        // The ONLY tolerated statement is the hash SELECT above (it matches
        // 'server_library_hashes'); every other library-table touch is a write.
        foreach ($libraryTableStatements as $sql) {
            self::assertStringStartsWith(
                'SELECT hash FROM server_library_hashes',
                $sql,
                'Hash-equal beats must perform zero library writes (upsert, prune DELETE, or hash update).',
            );
        }
    }

    public function testLibraryListViolationAcceptsListsAtEveryLimit(): void
    {
        $atCap = [];
        for ($i = 0; $i < HeartbeatHandler::MAX_LIBRARY_COUNT; $i++) {
            $atCap[] = LibraryRef::fromPayload([
                'library_id' => str_repeat('i', 36),
                'library_name' => str_repeat('n', 255),
            ]);
        }

        self::assertNull(HeartbeatHandler::libraryListViolation([]));
        self::assertNull(HeartbeatHandler::libraryListViolation($atCap));
    }

    public function testLibraryListViolationRejectsOverCount(): void
    {
        $over = [];
        for ($i = 0; $i <= HeartbeatHandler::MAX_LIBRARY_COUNT; $i++) {
            $over[] = LibraryRef::fromPayload(['library_id' => 'lib-' . $i, 'library_name' => 'L']);
        }

        $violation = HeartbeatHandler::libraryListViolation($over);

        self::assertIsString($violation);
        self::assertStringContainsString('500 entries', $violation);
        self::assertStringContainsString((string) count($over), $violation);
    }

    public function testLibraryListViolationRejectsOversizedFieldsByCharacterCount(): void
    {
        $overId = HeartbeatHandler::libraryListViolation([
            LibraryRef::fromPayload(['library_id' => str_repeat('i', 37), 'library_name' => 'Movies']),
        ]);
        self::assertIsString($overId);
        self::assertStringContainsString('library_id', $overId);
        self::assertStringContainsString('36', $overId);

        // 255 CJK chars = 765 bytes but 255 utf8mb4 characters → legal.
        self::assertNull(HeartbeatHandler::libraryListViolation([
            LibraryRef::fromPayload(['library_id' => 'lib-1', 'library_name' => str_repeat('あ', 255)]),
        ]));

        $overName = HeartbeatHandler::libraryListViolation([
            LibraryRef::fromPayload(['library_id' => 'lib-1', 'library_name' => str_repeat('あ', 256)]),
        ]);
        self::assertIsString($overName);
        self::assertStringContainsString('library_name', $overName);
        self::assertStringContainsString('256', $overName);
    }

    // ------------------------------------------------------------------
    // M-HUB-19 — the server-detail payload's one-law timestamp contract:
    // received_at is converted SQL-side (the hub's established idiom for
    // DATETIME columns: ServerInfoHandler's UNIX_TIMESTAMP(s.last_seen_at),
    // RelaySessionManager's own UNIX_TIMESTAMP(opened_at) idle probe) and
    // the mapper never trades a real value for the provable fake 0 that
    // swallowed EVERY history row on the buggy parent (is_numeric of a
    // "2026-10-28 14:03:22" string is false).
    // ------------------------------------------------------------------

    private function createHistoryHandler(Connection $db): HeartbeatHandler
    {
        $keyManager = new Ed25519KeyManager($this->tmpDir . '/key.pem');
        $jwtService = new EnrollmentJwtService($keyManager, 'https://hub.example.com');
        $logger = $this->createMock(StructuredLogger::class);
        return $this->createHandler($db, $jwtService, $logger);
    }

    public function testGetHeartbeatHistoryConvertsReceivedAtSqlSide(): void
    {
        $capturedSql = '';
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql) use (&$capturedSql): array {
                $capturedSql = $sql;
                return [];
            },
        );

        $this->createHistoryHandler($db)->getHeartbeatHistory('server-1', 5);

        self::assertStringContainsString('UNIX_TIMESTAMP(received_at) AS received_at', $capturedSql);
    }

    public function testGetHeartbeatHistoryShipsReceivedAtAsUnixSecondsInt(): void
    {
        // MySQL emits the UNIX_TIMESTAMP() alias as a numeric string under
        // emulated prepares; the history leg must narrow it to a JSON int.
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            [
                'id' => 'hb-1',
                'version' => '0.11.0',
                'uptime_seconds' => '3600',
                'active_sessions' => '2',
                'active_transcodes' => '0',
                'received_at' => '1762081234',
            ],
        ]);

        $history = $this->createHistoryHandler($db)->getHeartbeatHistory('server-1', 20);

        self::assertCount(1, $history);
        self::assertSame(1762081234, $history[0]['received_at']);
        self::assertSame(3600, $history[0]['uptime_seconds']);
    }

    public function testGetHeartbeatHistoryShipsNullNotZeroForNonNumericReceivedAt(): void
    {
        // If a raw DATETIME ever reaches the mapper (regression or DB anomaly)
        // the honest wire value is null — an unknown — never the fake 0.
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            [
                'id' => 'hb-1',
                'version' => '0.11.0',
                'uptime_seconds' => '3600',
                'active_sessions' => '2',
                'active_transcodes' => '0',
                'received_at' => '2026-10-28 14:03:22',
            ],
        ]);

        $history = $this->createHistoryHandler($db)->getHeartbeatHistory('server-1', 20);

        self::assertNull($history[0]['received_at']);
    }
}
