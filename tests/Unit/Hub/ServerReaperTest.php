<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\ServerReaper;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see ServerReaper}.
 *
 * @package Phlix\Hub\Tests\Unit\Hub
 *
 * @covers \Phlix\Hub\ServerReaper
 */
final class ServerReaperTest extends TestCase
{
    public function testMarkStaleServersOfflineUpdatesStatusToOffline(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        $capturedSql = '';
        $capturedParams = null;
        $db->method('query')->willReturnCallback(
            function (string $sql, $params = null) use (&$capturedSql, &$capturedParams): int {
                $capturedSql = $sql;
                $capturedParams = $params;
                return 3; // 3 servers marked offline
            },
        );

        $reaper = new ServerReaper($db, $logger, 60, 180, 7);
        $count = $reaper->markStaleServersOffline();

        self::assertSame(3, $count);
        self::assertStringContainsString("UPDATE servers", $capturedSql);
        self::assertStringContainsString("status = 'offline'", $capturedSql);
        self::assertStringContainsString("status != 'offline'", $capturedSql);
        self::assertStringContainsString('last_seen_at < NOW() - INTERVAL :threshold SECOND', $capturedSql);
        self::assertStringContainsString('id NOT IN', $capturedSql);
        self::assertStringContainsString('relay_sessions', $capturedSql);
        self::assertSame(['threshold' => 180], $capturedParams);
    }

    public function testMarkStaleServersOfflineUsesDefaultThreshold(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        $capturedParams = null;
        $db->method('query')->willReturnCallback(
            function (string $sql, $params = null) use (&$capturedParams): int {
                $capturedParams = $params;
                return 0;
            },
        );

        $reaper = new ServerReaper($db, $logger); // default threshold 180
        $reaper->markStaleServersOffline();

        self::assertSame(['threshold' => 180], $capturedParams);
    }

    public function testMarkStaleServersOfflineReturnsZeroWhenResultNotNumeric(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        $db->method('query')->willReturn(null);

        $reaper = new ServerReaper($db, $logger);
        self::assertSame(0, $reaper->markStaleServersOffline());
    }

    public function testSweepHeartbeatsDeletesOldRows(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        $capturedSql = '';
        $capturedParams = null;
        $db->method('query')->willReturnCallback(
            function (string $sql, $params = null) use (&$capturedSql, &$capturedParams): int {
                $capturedSql = $sql;
                $capturedParams = $params;
                return 50; // 50 rows deleted
            },
        );

        $reaper = new ServerReaper($db, $logger, 60, 180, 7);
        $count = $reaper->sweepHeartbeats();

        self::assertSame(50, $count);
        self::assertStringContainsString('DELETE FROM server_heartbeats', $capturedSql);
        self::assertStringContainsString('received_at < NOW() - INTERVAL :retention DAY', $capturedSql);
        self::assertSame(['retention' => 7], $capturedParams);
    }

    public function testSweepHeartbeatsUsesDefaultRetention(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        $capturedParams = null;
        $db->method('query')->willReturnCallback(
            function (string $sql, $params = null) use (&$capturedParams): int {
                $capturedParams = $params;
                return 0;
            },
        );

        $reaper = new ServerReaper($db, $logger); // default retention 7
        $reaper->sweepHeartbeats();

        self::assertSame(['retention' => 7], $capturedParams);
    }

    public function testSweepHeartbeatsReturnsZeroWhenResultNotNumeric(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        $db->method('query')->willReturn(null);

        $reaper = new ServerReaper($db, $logger);
        self::assertSame(0, $reaper->sweepHeartbeats());
    }

    public function testTickCallsBothMaintenanceMethods(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        /** @var list<string> $sqlCalls */
        $sqlCalls = [];
        $db->method('query')->willReturnCallback(
            function (string $sql, $params = null) use (&$sqlCalls): int {
                $sqlCalls[] = $sql;
                return 1;
            },
        );

        $reaper = new ServerReaper($db, $logger, 60, 180, 7);
        $reaper->tick();

        // tick() should call both markStaleServersOffline and sweepHeartbeats.
        $hasOfflineUpdate = false;
        $hasDelete = false;
        foreach ($sqlCalls as $sql) {
            if (str_contains($sql, "status = 'offline'")) {
                $hasOfflineUpdate = true;
            }
            if (str_contains($sql, 'DELETE FROM server_heartbeats')) {
                $hasDelete = true;
            }
        }

        self::assertTrue($hasOfflineUpdate, 'Expected an UPDATE to mark servers offline');
        self::assertTrue($hasDelete, 'Expected a DELETE from server_heartbeats');
    }

    public function testGettersReturnConfiguredValues(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        $reaper = new ServerReaper($db, $logger, 30, 300, 14);

        self::assertSame(30, $reaper->getIntervalSeconds());
        self::assertSame(300, $reaper->getOfflineThresholdSeconds());
        self::assertSame(14, $reaper->getHeartbeatRetentionDays());
    }
}
