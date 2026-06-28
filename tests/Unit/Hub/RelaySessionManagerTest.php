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
}
