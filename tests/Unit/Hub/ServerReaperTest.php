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
            function (string $sql, $params = null): array {
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

    public function testSweepAuditLogsSelectsExpiredIdsThenDeletesByPrimaryKey(): void
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
                    return [['id' => 'uuid-a'], ['id' => 'uuid-b']];
                }
                $deleteSql = $sql;
                $deleteParams = $params;
                return 2;
            },
        );

        $reaper = new ServerReaper($db, $logger, 60, 180, 7, 90);
        $count = $reaper->sweepAuditLogs();

        self::assertSame(2, $count);

        // Phase 1: DB-clock cutoff, non-locking PK-ordered SELECT (R19-11).
        self::assertStringContainsString('SELECT id FROM audit_logs', $selectSql);
        self::assertStringContainsString('created_at < NOW() - INTERVAL :retention DAY', $selectSql);
        self::assertStringContainsString('ORDER BY id', $selectSql);
        self::assertStringContainsString('LIMIT 1000', $selectSql);
        self::assertSame(['retention' => 90], $selectParams);

        // Phase 2: keyed DELETE by PRIMARY KEY, never a range delete on
        // created_at (that is the gap-locking shape the heartbeat sweep fixed).
        self::assertStringContainsString('DELETE FROM audit_logs WHERE id IN (', $deleteSql);
        self::assertStringNotContainsString('created_at', $deleteSql);
        self::assertSame(['id_0' => 'uuid-a', 'id_1' => 'uuid-b'], $deleteParams);
    }

    public function testSweepAuditLogsUsesDefaultRetentionOf365Days(): void
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

        $reaper = new ServerReaper($db, $logger);
        $reaper->sweepAuditLogs();

        self::assertSame(['retention' => 365], $selectParams);
        self::assertSame(365, $reaper->getAuditRetentionDays());
    }

    /**
     * REVIEW-FIX (audit-retention footgun): a zero/negative window must NEVER
     * reach SQL. `created_at < NOW() - INTERVAL 0 DAY` matches EVERY row, so
     * HUB_AUDIT_RETENTION_DAYS=0 used to arm a wipe-audit_logs-everything
     * sweep, 1000 rows per tick. The constructor clamps to the default and
     * logs loudly; this pins both halves.
     */
    public function testZeroAuditRetentionIsClampedToDefaultAndLogged(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        $logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('audit-log retention window'),
                $this->callback(static fn (array $context): bool => $context['provided_days'] === 0
                    && $context['effective_days'] === 365),
            );

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

        $reaper = new ServerReaper($db, $logger, 60, 180, 7, 0);
        self::assertSame(365, $reaper->getAuditRetentionDays());
        $reaper->sweepAuditLogs();
        self::assertSame(['retention' => 365], $selectParams, 'SQL must never see retention 0');
    }

    public function testNegativeAuditRetentionIsClampedToDefault(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        $reaper = new ServerReaper($db, $logger, 60, 180, 7, -5);
        self::assertSame(365, $reaper->getAuditRetentionDays());
    }

    public function testRetentionOfExactlyOneDayIsHonored(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        $logger->expects($this->never())->method('warning');

        $reaper = new ServerReaper($db, $logger, 60, 180, 1, 1);
        self::assertSame(1, $reaper->getHeartbeatRetentionDays());
        self::assertSame(1, $reaper->getAuditRetentionDays());
    }

    public function testZeroHeartbeatRetentionIsClampedToItsOwnDefault(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        $logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('heartbeat retention window'),
                $this->callback(static fn (array $context): bool => $context['provided_days'] === 0
                    && $context['effective_days'] === 7),
            );

        $reaper = new ServerReaper($db, $logger, 60, 180, 0, 90);
        self::assertSame(7, $reaper->getHeartbeatRetentionDays());
        // The sibling window stays untouched: one bad dial must not censor good ones.
        self::assertSame(90, $reaper->getAuditRetentionDays());
    }

    public function testOversizedRetentionIsCapped(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        $reaper = new ServerReaper($db, $logger, 60, 180, 40000, 40000);
        self::assertSame(ServerReaper::RETENTION_DAYS_CAP, $reaper->getHeartbeatRetentionDays());
        self::assertSame(ServerReaper::RETENTION_DAYS_CAP, $reaper->getAuditRetentionDays());
    }

    /**
     * The shared normalization function, pinned directly (mirrors the server
     * StatsCollector::retentionDays() table the reviewer asked to mirror).
     */
    public function testNormalizeRetentionDaysCoercionTable(): void
    {
        // Non-numeric / missing → default.
        self::assertSame(365, ServerReaper::normalizeRetentionDays(null, 365));
        self::assertSame(365, ServerReaper::normalizeRetentionDays('abc', 365));
        self::assertSame(365, ServerReaper::normalizeRetentionDays([], 365));
        // Non-positive → default (the wipe-the-table shapes).
        self::assertSame(365, ServerReaper::normalizeRetentionDays(0, 365));
        self::assertSame(365, ServerReaper::normalizeRetentionDays('0', 365));
        self::assertSame(365, ServerReaper::normalizeRetentionDays(-5, 365));
        // Valid → honored.
        self::assertSame(1, ServerReaper::normalizeRetentionDays(1, 365));
        self::assertSame(90, ServerReaper::normalizeRetentionDays('90', 365));
        self::assertSame(36500, ServerReaper::normalizeRetentionDays(36500, 365));
        // Oversized → cap.
        self::assertSame(36500, ServerReaper::normalizeRetentionDays(40000, 365));
    }

    public function testSweepAuditLogsSkipsDeleteWhenNothingExpired(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        $deleteIssued = false;
        $db->method('query')->willReturnCallback(
            function (string $sql, $params = null) use (&$deleteIssued): int|array {
                if (str_starts_with(ltrim($sql), 'SELECT')) {
                    return [];
                }
                $deleteIssued = true;
                return 0;
            },
        );

        $reaper = new ServerReaper($db, $logger);
        self::assertSame(0, $reaper->sweepAuditLogs());
        self::assertFalse($deleteIssued, 'No DELETE should run when nothing is expired');
    }

    public function testTickCallsEveryMaintenanceSweep(): void
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
                if (str_starts_with(ltrim($sql), 'SELECT id FROM audit_logs')) {
                    return [['id' => 'bbb']];
                }
                if (str_starts_with(ltrim($sql), 'SELECT id FROM federation_sessions')) {
                    return [['id' => 'ccc']];
                }
                return 1;
            },
        );

        $reaper = new ServerReaper($db, $logger, 60, 180, 7);
        $reaper->tick();

        // tick() should call markStaleServersOffline, sweepHeartbeats,
        // sweepAuditLogs (R19-11) and sweepDeadFederationSessions (M-HUB-26).
        // Renamed from testTickCallsBothMaintenanceMethods when the third and
        // fourth sweeps landed; the positive-assertion shape is the point —
        // a new sweep joining the tick must be visible here.
        $hasOfflineUpdate = false;
        $hasDelete = false;
        $hasAuditDelete = false;
        $hasFederationDelete = false;
        foreach ($sqlCalls as $sql) {
            if (str_contains($sql, "status = 'offline'")) {
                $hasOfflineUpdate = true;
            }
            if (str_contains($sql, 'DELETE FROM server_heartbeats')) {
                $hasDelete = true;
            }
            if (str_contains($sql, 'DELETE FROM audit_logs')) {
                $hasAuditDelete = true;
            }
            if (str_contains($sql, 'DELETE FROM federation_sessions')) {
                $hasFederationDelete = true;
            }
        }

        self::assertTrue($hasOfflineUpdate, 'Expected an UPDATE to mark servers offline');
        self::assertTrue($hasDelete, 'Expected a DELETE from server_heartbeats');
        self::assertTrue($hasAuditDelete, 'Expected a DELETE from audit_logs');
        self::assertTrue($hasFederationDelete, 'Expected a DELETE from federation_sessions (M-HUB-26)');
    }

    public function testSweepDeadFederationSessionsSelectsClosedExpiredIdsThenDeletesByPrimaryKey(): void
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
                &$deleteParams
            ): int|array {
                if (str_starts_with(ltrim($sql), 'SELECT')) {
                    $selectSql = $sql;
                    $selectParams = $params;
                    return [['id' => 'sess-1'], ['id' => 'sess-2']];
                }
                $deleteSql = $sql;
                $deleteParams = $params;
                return 2;
            },
        );

        $reaper = new ServerReaper($db, $logger);
        $count = $reaper->sweepDeadFederationSessions();

        self::assertSame(2, $count);
        // The predicate must only ever target CLOSED sessions — a live row is
        // the peer manager's, and this sweep has no business erasing it.
        self::assertStringContainsString('FROM federation_sessions', $selectSql);
        self::assertStringContainsString('alive = 0', $selectSql);
        self::assertStringContainsString(
            'last_heartbeat_at < NOW() - INTERVAL :retention DAY',
            $selectSql,
        );
        self::assertStringContainsString('ORDER BY id', $selectSql);
        self::assertSame(
            ['retention' => ServerReaper::DEFAULT_FEDERATION_SESSION_RETENTION_DAYS],
            $selectParams,
        );
        self::assertStringContainsString('DELETE FROM federation_sessions WHERE id IN (:id_0, :id_1)', $deleteSql);
        self::assertSame(['id_0' => 'sess-1', 'id_1' => 'sess-2'], $deleteParams);
    }

    public function testSweepDeadFederationSessionsSkipsDeleteWhenNothingExpired(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        $deleteIssued = false;
        $db->method('query')->willReturnCallback(
            function (string $sql, $params = null) use (&$deleteIssued): int|array {
                if (str_starts_with(ltrim($sql), 'SELECT')) {
                    return [];
                }
                $deleteIssued = true;
                return 0;
            },
        );

        $reaper = new ServerReaper($db, $logger);
        self::assertSame(0, $reaper->sweepDeadFederationSessions());
        self::assertFalse($deleteIssued, 'No DELETE should run when nothing is expired');
    }

    public function testGettersReturnConfiguredValues(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        $reaper = new ServerReaper($db, $logger, 30, 300, 14, 45);

        self::assertSame(30, $reaper->getIntervalSeconds());
        self::assertSame(300, $reaper->getOfflineThresholdSeconds());
        self::assertSame(14, $reaper->getHeartbeatRetentionDays());
        self::assertSame(45, $reaper->getAuditRetentionDays());
    }
}
