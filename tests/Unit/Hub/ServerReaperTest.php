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

    public function testSweepHeartbeatsSelectsExpiredIdsThenDeletesByPrimaryKey(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        $selectSql = '';
        $selectParams = null;
        $deleteSql = '';
        $deleteParams = null;
        $db->method('query')->willReturnCallback(
            function (
                string $sql,
                $params = null
            ) use (
                &$selectSql,
                &$selectParams,
                &$deleteSql,
                &$deleteParams,
            ): int|array {
                if (str_starts_with(ltrim($sql), 'SELECT')) {
                    $selectSql = $sql;
                    $selectParams = $params;
                    // Three expired rows, returned PK-ordered.
                    return [['id' => 'aaa'], ['id' => 'bbb'], ['id' => 'ccc']];
                }
                // DELETE phase.
                $deleteSql = $sql;
                $deleteParams = $params;
                return 3;
            },
        );

        $reaper = new ServerReaper($db, $logger, 60, 180, 7);
        $count = $reaper->sweepHeartbeats();

        self::assertSame(3, $count);

        // Phase 1: a non-locking, PK-ordered SELECT of expired ids.
        self::assertStringContainsString('SELECT id FROM server_heartbeats', $selectSql);
        self::assertStringContainsString('received_at < NOW() - INTERVAL :retention DAY', $selectSql);
        self::assertStringContainsString('ORDER BY id', $selectSql);
        self::assertSame(['retention' => 7], $selectParams);

        // Phase 2: a keyed DELETE by PRIMARY KEY — NOT a range DELETE on
        // received_at (that is the gap-locking query that deadlocked).
        self::assertStringContainsString('DELETE FROM server_heartbeats WHERE id IN (', $deleteSql);
        self::assertStringNotContainsString('received_at', $deleteSql);
        self::assertSame(
            ['id_0' => 'aaa', 'id_1' => 'bbb', 'id_2' => 'ccc'],
            $deleteParams,
        );
    }

    public function testSweepHeartbeatsUsesDefaultRetention(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        $selectParams = null;
        $db->method('query')->willReturnCallback(
            function (string $sql, $params = null) use (&$selectParams): int|array {
                if (str_starts_with(ltrim($sql), 'SELECT')) {
                    $selectParams = $params;
                    return [];
                }
                return 0;
            },
        );

        $reaper = new ServerReaper($db, $logger); // default retention 7
        $reaper->sweepHeartbeats();

        self::assertSame(['retention' => 7], $selectParams);
    }

    public function testSweepHeartbeatsSkipsDeleteWhenNothingExpired(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        $deleteIssued = false;
        $db->method('query')->willReturnCallback(
            function (string $sql, $params = null) use (&$deleteIssued): int|array {
                if (str_starts_with(ltrim($sql), 'SELECT')) {
                    return []; // no expired rows
                }
                $deleteIssued = true;
                return 0;
            },
        );

        $reaper = new ServerReaper($db, $logger);
        self::assertSame(0, $reaper->sweepHeartbeats());
        self::assertFalse($deleteIssued, 'No DELETE should run when nothing is expired');
    }

    public function testSweepHeartbeatsReturnsZeroWhenSelectResultNotArray(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        $db->method('query')->willReturn(null);

        $reaper = new ServerReaper($db, $logger);
        self::assertSame(0, $reaper->sweepHeartbeats());
    }

    public function testSweepHeartbeatsRetriesOnDeadlockThenSucceeds(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        $deleteAttempts = 0;
        $db->method('query')->willReturnCallback(
            function (string $sql, $params = null) use (&$deleteAttempts): int|array {
                if (str_starts_with(ltrim($sql), 'SELECT')) {
                    return [['id' => 'aaa'], ['id' => 'bbb']];
                }
                $deleteAttempts++;
                if ($deleteAttempts === 1) {
                    throw new \PDOException(
                        'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found',
                    );
                }
                return 2;
            },
        );

        $reaper = new ServerReaper($db, $logger, 60, 180, 7);
        $count = $reaper->sweepHeartbeats();

        self::assertSame(2, $count);
        self::assertSame(2, $deleteAttempts, 'Expected exactly one retry after the deadlock');
    }

    public function testSweepHeartbeatsRethrowsAfterMaxDeadlockRetries(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        $db->method('query')->willReturnCallback(
            function (string $sql, $params = null): int|array {
                if (str_starts_with(ltrim($sql), 'SELECT')) {
                    return [['id' => 'aaa']];
                }
                throw new \PDOException('1213 Deadlock found');
            },
        );

        $reaper = new ServerReaper($db, $logger, 60, 180, 7);
        $this->expectException(\PDOException::class);
        $reaper->sweepHeartbeats();
    }

    public function testTickCallsBothMaintenanceMethods(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        /** @var list<string> $sqlCalls */
        $sqlCalls = [];
        $db->method('query')->willReturnCallback(
            function (string $sql, $params = null) use (&$sqlCalls): int|array {
                $sqlCalls[] = $sql;
                if (str_starts_with(ltrim($sql), 'SELECT id FROM server_heartbeats')) {
                    return [['id' => 'aaa']];
                }
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
