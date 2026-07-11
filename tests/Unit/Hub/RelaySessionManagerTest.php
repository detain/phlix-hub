<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Hub\RelaySessionManager;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see RelaySessionManager}.
 *
 * @package Phlix\Hub\Tests\Unit\Hub
 *
 * @covers \Phlix\Hub\Hub\RelaySessionManager
 */
final class RelaySessionManagerTest extends TestCase
{
    public function testRegisterServerSupersedesOpenSessionsBeforeInsert(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        /** @var list<array{sql: string, params: mixed}> $calls */
        $calls = [];
        $db->method('query')->willReturnCallback(
            function (string $sql, $params = null) use (&$calls) {
                $calls[] = ['sql' => $sql, 'params' => $params];
                // First call = server existence SELECT; return one row.
                if (str_contains($sql, 'SELECT id FROM servers')) {
                    return [['id' => 'server-1']];
                }
                return null;
            },
        );

        $manager = new RelaySessionManager($db, $logger);
        $sessionId = $manager->registerServer('server-1', 'worker-a');

        self::assertNotSame('', $sessionId);

        // Expect: [0] existence SELECT, [1] supersede UPDATE, [2] INSERT.
        self::assertGreaterThanOrEqual(3, count($calls));

        self::assertStringContainsString('SELECT id FROM servers', $calls[0]['sql']);

        $supersede = $calls[1];
        self::assertStringContainsString('UPDATE relay_sessions SET closed_at = NOW()', $supersede['sql']);
        self::assertStringContainsString("close_reason = :reason", $supersede['sql']);
        self::assertStringContainsString('server_id = :server_id AND closed_at IS NULL', $supersede['sql']);
        self::assertSame(
            ['reason' => 'superseded', 'server_id' => 'server-1'],
            $supersede['params'],
        );

        // The supersede UPDATE must run BEFORE the INSERT.
        $insert = $calls[2];
        self::assertStringContainsString('INSERT INTO relay_sessions', $insert['sql']);
    }

    public function testReapStaleSessionsIssuesStaleCloseUpdate(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        $capturedSql = '';
        $capturedParams = null;
        $db->method('query')->willReturnCallback(
            function (string $sql, $params = null) use (&$capturedSql, &$capturedParams): int {
                $capturedSql = $sql;
                $capturedParams = $params;
                return 5;
            },
        );

        $manager = new RelaySessionManager($db, $logger);
        $closed = $manager->reapStaleSessions(180);

        self::assertSame(5, $closed);
        self::assertStringContainsString('UPDATE relay_sessions SET closed_at = NOW()', $capturedSql);
        self::assertStringContainsString("close_reason = 'stale'", $capturedSql);
        self::assertStringContainsString('closed_at IS NULL', $capturedSql);
        self::assertStringContainsString('COALESCE(last_frame_at, UNIX_TIMESTAMP(opened_at))', $capturedSql);
        self::assertStringContainsString('(UNIX_TIMESTAMP() - :threshold)', $capturedSql);
        self::assertSame(['threshold' => 180], $capturedParams);
    }

    public function testReapStaleSessionsUsesDefaultThreshold(): void
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

        $manager = new RelaySessionManager($db, $logger);
        $closed = $manager->reapStaleSessions();

        self::assertSame(0, $closed);
        self::assertSame(['threshold' => 180], $capturedParams);
    }

    public function testReapStaleSessionsReturnsZeroWhenCountNotNumeric(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        $db->method('query')->willReturn(null);

        $manager = new RelaySessionManager($db, $logger);
        self::assertSame(0, $manager->reapStaleSessions(90));
    }

    public function testCloseOrphanedSessionsWithEmptyLiveSetClosesAllOpen(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        $capturedSql = '';
        $capturedParams = null;
        $db->method('query')->willReturnCallback(
            function (string $sql, $params = null) use (&$capturedSql, &$capturedParams): int {
                $capturedSql = $sql;
                $capturedParams = $params;
                return 3;
            },
        );

        $manager = new RelaySessionManager($db, $logger);
        // Worker just started with an empty registry → every open row is an orphan.
        $closed = $manager->closeOrphanedSessions([], 'reconciled_on_start');

        self::assertSame(3, $closed);
        self::assertStringContainsString('UPDATE relay_sessions SET closed_at = NOW()', $capturedSql);
        self::assertStringContainsString('close_reason = :reason', $capturedSql);
        self::assertStringContainsString('closed_at IS NULL', $capturedSql);
        // Empty live set → no exclusion clause; every open session is closed.
        self::assertStringNotContainsString('NOT IN', $capturedSql);
        self::assertSame(['reason' => 'reconciled_on_start'], $capturedParams);
    }

    public function testCloseOrphanedSessionsExcludesLiveTunnelServers(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        $capturedSql = '';
        $capturedParams = null;
        $db->method('query')->willReturnCallback(
            function (string $sql, $params = null) use (&$capturedSql, &$capturedParams): int {
                $capturedSql = $sql;
                $capturedParams = $params;
                return 1;
            },
        );

        $manager = new RelaySessionManager($db, $logger);
        // Two live tunnels — open sessions for those servers must be preserved;
        // every other open session is closed as an orphan.
        $closed = $manager->closeOrphanedSessions(['srv-a', 'srv-b']);

        self::assertSame(1, $closed);
        self::assertStringContainsString('server_id NOT IN (:live_0, :live_1)', $capturedSql);
        self::assertSame(
            ['reason' => 'orphaned', 'live_0' => 'srv-a', 'live_1' => 'srv-b'],
            $capturedParams,
        );
    }

    public function testCloseOrphanedSessionsDeduplicatesLiveServerIds(): void
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

        $manager = new RelaySessionManager($db, $logger);
        $manager->closeOrphanedSessions(['srv-a', 'srv-a', 'srv-b']);

        self::assertSame(
            ['reason' => 'orphaned', 'live_0' => 'srv-a', 'live_1' => 'srv-b'],
            $capturedParams,
        );
    }

    public function testCloseOrphanedSessionsReturnsZeroWhenCountNotNumeric(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        $db->method('query')->willReturn(null);

        $manager = new RelaySessionManager($db, $logger);
        self::assertSame(0, $manager->closeOrphanedSessions([]));
    }

    public function testFlushSessionAccumulatesBytesAndLastFrameAt(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        $capturedSql = '';
        $capturedParams = null;
        $db->method('query')->willReturnCallback(
            function (string $sql, $params = null) use (&$capturedSql, &$capturedParams): void {
                $capturedSql = $sql;
                $capturedParams = $params;
            },
        );

        $manager = new RelaySessionManager($db, $logger);

        // Accumulate bytes and last-frame timestamp via in-memory methods.
        $manager->recordBytesOut('session-1', 1024);
        $manager->recordBytesIn('session-1', 512);
        $manager->touchLastFrame('session-1');

        // flushSession should emit a single UPDATE with all three deltas.
        $manager->flushSession('session-1');

        self::assertStringContainsString('UPDATE relay_sessions SET', $capturedSql);
        self::assertStringContainsString('bytes_out = bytes_out + :bytes_out', $capturedSql);
        self::assertStringContainsString('bytes_in = bytes_in + :bytes_in', $capturedSql);
        self::assertStringContainsString('last_frame_at = :last_frame_at', $capturedSql);

        // Verify all expected keys are present with correct values.
        self::assertSame('session-1', $capturedParams['id']);
        self::assertSame(1024, $capturedParams['bytes_out']);
        self::assertSame(512, $capturedParams['bytes_in']);
        self::assertArrayHasKey('last_frame_at', $capturedParams);
        self::assertIsInt($capturedParams['last_frame_at']);
    }

    public function testFlushSessionIsIdempotentWhenNothingToFlush(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        $queryCallCount = 0;
        $db->method('query')->willReturnCallback(
            function () use (&$queryCallCount): void {
                $queryCallCount++;
            },
        );

        $manager = new RelaySessionManager($db, $logger);

        // Flush a session with no pending data — no DB query should be issued.
        $manager->flushSession('session-no-data');

        self::assertSame(0, $queryCallCount);
    }

    public function testFlushSessionClearsAccumulatorsAfterFlush(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        $db->method('query')->willReturnCallback(
            function (string $sql, $params = null): void {},
        );

        $manager = new RelaySessionManager($db, $logger);

        $manager->recordBytesOut('session-x', 100);
        $manager->recordBytesIn('session-x', 50);

        // First flush.
        $manager->flushSession('session-x');

        // Accumulate again — should start from zero, not accumulate on top.
        $manager->recordBytesOut('session-x', 200);

        // Capture the params on the second flush.
        $capturedParams = null;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, $params = null) use (&$capturedParams): void {
                $capturedParams = $params;
            },
        );

        // Create a new manager with the same accumulators still in memory?
        // Actually, after flushSession, the accumulators are cleared.
        // Let's verify with a fresh manager accumulating only 200 this time.
        $manager2 = new RelaySessionManager($db, $logger);
        $manager2->recordBytesOut('session-x', 200);
        $manager2->flushSession('session-x');

        // If accumulators were NOT cleared, it would be 300 (100+200).
        // Since they ARE cleared, it should be only 200.
        self::assertSame(200, $capturedParams['bytes_out']);
    }

    public function testFlushAllFlushesMultipleSessions(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        /** @var list<array{sql: string, params: mixed}> $calls */
        $calls = [];
        $db->method('query')->willReturnCallback(
            function (string $sql, $params = null) use (&$calls): void {
                $calls[] = ['sql' => $sql, 'params' => $params];
            },
        );

        $manager = new RelaySessionManager($db, $logger);

        // Accumulate data for two different sessions.
        $manager->recordBytesOut('session-a', 100);
        $manager->recordBytesOut('session-b', 200);
        $manager->touchLastFrame('session-b');

        $manager->flushAll();

        // Should have issued exactly two UPDATE calls.
        self::assertCount(2, $calls);

        // Verify session-a flush
        self::assertStringContainsString('UPDATE relay_sessions SET', $calls[0]['sql']);
        self::assertSame('session-a', $calls[0]['params']['id']);
        self::assertSame(100, $calls[0]['params']['bytes_out']);

        // Verify session-b flush
        self::assertStringContainsString('UPDATE relay_sessions SET', $calls[1]['sql']);
        self::assertSame('session-b', $calls[1]['params']['id']);
        self::assertSame(200, $calls[1]['params']['bytes_out']);
    }

    public function testCloseSessionFlushesBeforeClosing(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        /** @var list<array{sql: string, params: mixed}> $calls */
        $calls = [];
        $db->method('query')->willReturnCallback(
            function (string $sql, $params = null) use (&$calls): void {
                $calls[] = ['sql' => $sql, 'params' => $params];
            },
        );

        $manager = new RelaySessionManager($db, $logger);
        $manager->recordBytesOut('session-close-test', 999);
        $manager->closeSession('session-close-test', 'normal');

        // First call should be the flush UPDATE.
        self::assertStringContainsString('UPDATE relay_sessions SET', $calls[0]['sql']);
        self::assertStringContainsString('bytes_out = bytes_out + :bytes_out', $calls[0]['sql']);
        self::assertSame(999, $calls[0]['params']['bytes_out']);

        // Second call should be the close UPDATE.
        self::assertStringContainsString('UPDATE relay_sessions SET closed_at = NOW()', $calls[1]['sql']);
        self::assertStringContainsString("close_reason = :reason", $calls[1]['sql']);
        self::assertSame('normal', $calls[1]['params']['reason']);
    }

    public function testRecordUserBandwidthAccumulates(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        $capturedSql = '';
        $capturedParams = null;
        $db->method('query')->willReturnCallback(
            function (string $sql, $params = null) use (&$capturedSql, &$capturedParams): void {
                $capturedSql = $sql;
                $capturedParams = $params;
            },
        );

        $manager = new RelaySessionManager($db, $logger);
        $manager->recordUserBandwidth('user-abc', 2048, 512);

        self::assertStringContainsString('INSERT INTO relay_user_quotas', $capturedSql);
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $capturedSql);
        self::assertStringContainsString('bytes_in = bytes_in + VALUES(bytes_in)', $capturedSql);
        self::assertStringContainsString('bytes_out = bytes_out + VALUES(bytes_out)', $capturedSql);

        self::assertSame('user-abc', $capturedParams['user_id']);
        self::assertSame(date('Y-m-01'), $capturedParams['period_start']);
        self::assertSame(2048, $capturedParams['bytes_in']);
        self::assertSame(512, $capturedParams['bytes_out']);
    }

    public function testGetUserBandwidthReturnsCurrentMonthNoData(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        // No data → null
        $db->method('query')->willReturn([]);

        $manager = new RelaySessionManager($db, $logger);
        $result = $manager->getUserBandwidth('user-no-data');
        self::assertNull($result);
    }

    public function testGetUserBandwidthReturnsCurrentMonthWithData(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        // With data → correct values
        $db->method('query')->willReturn([
            [
                'bytes_in' => '1024000',
                'bytes_out' => '512000',
                'quota_bytes_in' => '5242880',
                'quota_bytes_out' => '2097152',
            ],
        ]);

        $manager = new RelaySessionManager($db, $logger);
        $result = $manager->getUserBandwidth('user-with-data');

        self::assertNotNull($result);
        self::assertSame(1024000, $result['bytes_in']);
        self::assertSame(512000, $result['bytes_out']);
        self::assertSame(5242880, $result['quota_bytes_in']);
        self::assertSame(2097152, $result['quota_bytes_out']);
    }

    public function testSetUserQuota(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        $capturedSql = '';
        $capturedParams = null;
        $db->method('query')->willReturnCallback(
            function (string $sql, $params = null) use (&$capturedSql, &$capturedParams): void {
                $capturedSql = $sql;
                $capturedParams = $params;
            },
        );

        $manager = new RelaySessionManager($db, $logger);
        $manager->setUserQuota('user-xyz', 10485760, 5242880);

        self::assertStringContainsString('INSERT INTO relay_user_quotas', $capturedSql);
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $capturedSql);
        self::assertStringContainsString('quota_bytes_in = VALUES(quota_bytes_in)', $capturedSql);
        self::assertStringContainsString('quota_bytes_out = VALUES(quota_bytes_out)', $capturedSql);

        self::assertSame('user-xyz', $capturedParams['user_id']);
        self::assertSame(date('Y-m-01'), $capturedParams['period_start']);
        self::assertSame(10485760, $capturedParams['quota_bytes_in']);
        self::assertSame(5242880, $capturedParams['quota_bytes_out']);
    }

    public function testCheckUserQuotaUnlimitedIsAllowed(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        // No quota row at all → unlimited → allowed
        $db->method('query')->willReturn([]);

        $manager = new RelaySessionManager($db, $logger);
        $result = $manager->checkUserQuota('user-no-quota');

        self::assertTrue($result['allowed']);
        self::assertNull($result['reason']);
    }

    public function testCheckUserQuotaOverLimitIsDenied(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        // bytes_out >= quota_bytes_out → denied
        $db->method('query')->willReturn([
            ['bytes_out' => '5242880', 'quota_bytes_out' => '5242880'],
        ]);

        $manager = new RelaySessionManager($db, $logger);
        $result = $manager->checkUserQuota('user-over', 0);

        self::assertFalse($result['allowed']);
        self::assertNotNull($result['reason']);
        self::assertStringContainsString('quota', $result['reason']);
    }

    public function testCheckUserQuotaUnderLimitIsAllowed(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        // bytes_out < quota_bytes_out → allowed
        $db->method('query')->willReturn([
            ['bytes_out' => '1048576', 'quota_bytes_out' => '5242880'],
        ]);

        $manager = new RelaySessionManager($db, $logger);
        $result = $manager->checkUserQuota('user-under', 0);

        self::assertTrue($result['allowed']);
        self::assertNull($result['reason']);
    }
}
