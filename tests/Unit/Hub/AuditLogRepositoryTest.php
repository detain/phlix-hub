<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\Hub\AuditLogRepository;
use Phlix\Hub\Tests\Support\BindingContractConnection;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see AuditLogRepository}.
 *
 * @package Phlix\Hub\Tests\Unit\Hub
 */
final class AuditLogRepositoryTest extends TestCase
{
    public function testLogWritesToDatabase(): void
    {
        $mockDb = $this->createMock(Connection::class);

        $mockDb->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('INSERT INTO audit_logs'),
                self::callback(function (array $params): bool {
                    return $params['event'] === 'login'
                        && $params['userId'] === 'user-123'
                        && $params['deviceId'] === 'device-456'
                        && $params['success'] === 1
                        && $params['reason'] === 'bad_password';
                }),
            );

        $repo = new AuditLogRepository($mockDb);
        $repo->log(
            event: 'login',
            userId: 'user-123',
            deviceId: 'device-456',
            success: true,
            reason: 'bad_password',
        );
    }

    public function testLogAndFindBindColonFreeKeysUnderRealContract(): void
    {
        // Regression guard: workerman's bind() prepends ':' to every param
        // key, so the keys must be colon-free. BindingContractConnection
        // throws HY093 on a leading-colon key exactly as PDO did in production
        // (the audit INSERT + filtered find() silently 500'd before the fix).
        $db = new BindingContractConnection([
            'INSERT INTO audit_logs' => [],
            'COUNT(*)'               => [['cnt' => 0]],
            'FROM audit_logs al'     => [],
        ]);
        $repo = new AuditLogRepository($db);

        // INSERT path — every column placeholder must bind a colon-free key.
        $repo->log(
            event: 'admin_action',
            userId: 'admin-001',
            sessionId: 'sess-1',
            deviceId: 'dev-1',
            resource: 'user-9',
            action: 'user.update',
            success: true,
            reason: 'ok',
            ipAddress: '127.0.0.1',
            userAgent: 'HubAdmin/1.0',
            context: ['k' => 'v'],
        );

        // find() with every filter — each condition must bind a colon-free key.
        $result = $repo->find([
            'event'    => 'admin_action',
            'user_id'  => 'admin-001',
            'resource' => 'user-9',
            'action'   => 'user.update',
            'success'  => true,
            'from'     => 1704067200,
            'to'       => 1704153600,
            'limit'    => 10,
        ]);
        self::assertSame(['entries' => [], 'total' => 0], $result);

        $insert = null;
        foreach ($db->calls as $call) {
            if (str_contains($call['sql'], 'INSERT INTO audit_logs')) {
                $insert = $call;
            }
        }
        self::assertNotNull($insert);
        self::assertArrayHasKey('event', $insert['params']);
        self::assertArrayNotHasKey(':event', $insert['params']);
        self::assertSame('admin_action', $insert['params']['event']);
    }

    public function testFindWithNoFilters(): void
    {
        $mockDb = $this->createMock(Connection::class);

        $mockDb->expects(self::exactly(2))
            ->method('query')
            ->willReturnOnConsecutiveCalls(
                [['cnt' => 0]],
                [],
            );

        $repo = new AuditLogRepository($mockDb);
        $result = $repo->find([]);

        self::assertSame(['entries' => [], 'total' => 0], $result);
    }

    public function testFindWithEventFilter(): void
    {
        $mockDb = $this->createMock(Connection::class);
        $callCount = 0;

        $mockDb->expects(self::exactly(2))
            ->method('query')
            ->willReturnCallback(function (string $sql, array $params) use (&$callCount): array {
                $callCount++;
                if ($callCount === 1) {
                    // Count query
                    self::assertArrayHasKey('event', $params);
                    self::assertSame('login', $params['event']);
                    return [['cnt' => 1]];
                }
                // Select query
                self::assertStringContainsString('event = :event', $sql);
                return [[
                    'id' => 'uuid-123',
                    'event' => 'login',
                    'user_id' => 'user-456',
                    'session_id' => null,
                    'device_id' => 'device-789',
                    'resource' => null,
                    'action' => null,
                    'success' => 1,
                    'reason' => null,
                    'ip_address' => '127.0.0.1',
                    'user_agent' => 'PHPUnit',
                    'context_json' => null,
                    'created_at' => '2026-01-01 00:00:00',
                ]];
            });

        $repo = new AuditLogRepository($mockDb);
        $result = $repo->find(['event' => 'login']);

        self::assertCount(1, $result['entries']);
        self::assertSame(1, $result['total']);
        self::assertSame('login', $result['entries'][0]['event']);
        self::assertTrue($result['entries'][0]['success']);
    }

    public function testFindPaginationClampLimitTo200(): void
    {
        $mockDb = $this->createMock(Connection::class);

        // find() makes 2 calls: count query + select query
        $mockDb->expects(self::exactly(2))
            ->method('query')
            ->willReturnCallback(function (string $sql): array {
                if (str_contains($sql, 'COUNT')) {
                    return [['cnt' => 0]];
                }
                // The select query should have LIMIT 200 (clamped from 500)
                self::assertStringContainsString('LIMIT 200 OFFSET 0', $sql);
                return [];
            });

        $repo = new AuditLogRepository($mockDb);
        $result = $repo->find(['limit' => 500]);
        self::assertArrayHasKey('entries', $result);
    }

    public function testFindPaginationClampOffsetToZero(): void
    {
        $mockDb = $this->createMock(Connection::class);

        // find() makes 2 calls: count query + select query
        $mockDb->expects(self::exactly(2))
            ->method('query')
            ->willReturnCallback(function (string $sql): array {
                if (str_contains($sql, 'COUNT')) {
                    return [['cnt' => 0]];
                }
                // The select query should have LIMIT 50 OFFSET 0 (clamped from -10)
                self::assertStringContainsString('LIMIT 50 OFFSET 0', $sql);
                return [];
            });

        $repo = new AuditLogRepository($mockDb);
        $result = $repo->find(['offset' => -10]);
        self::assertArrayHasKey('entries', $result);
    }

    public function testRowToEntryConvertsTinintToBool(): void
    {
        $mockDb = $this->createMock(Connection::class);

        $mockDb->expects(self::exactly(2))
            ->method('query')
            ->willReturnOnConsecutiveCalls(
                [['cnt' => 1]],
                [[
                    'id' => 'uuid-123',
                    'event' => 'auth_failure',
                    'user_id' => null,
                    'session_id' => null,
                    'device_id' => null,
                    'resource' => null,
                    'action' => null,
                    'success' => 0,
                    'reason' => 'rate_limit',
                    'ip_address' => '10.0.0.1',
                    'user_agent' => null,
                    'context_json' => null,
                    'created_at' => '2026-01-01 00:00:00',
                ]],
            );

        $repo = new AuditLogRepository($mockDb);
        $result = $repo->find(['event' => 'auth_failure']);

        self::assertFalse($result['entries'][0]['success']);
    }

    public function testGenerateUuidReturnsValidFormat(): void
    {
        $mockDb = $this->createMock(Connection::class);

        // Capture the query call to get the UUID that was generated
        $capturedUuid = null;
        $mockDb->expects(self::once())
            ->method('query')
            ->willReturnCallback(function (string $sql, array $params) use (&$capturedUuid): array {
                if (isset($params['id'])) {
                    $capturedUuid = $params['id'];
                }
                return [['cnt' => 0]];
            });

        $repo = new AuditLogRepository($mockDb);
        $repo->log(event: 'test_event');

        self::assertNotNull($capturedUuid);
        // UUID v4 format: 8-4-4-4-12 hex digits
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $capturedUuid,
        );
    }

    public function testFindReturnsEntriesAndTotal(): void
    {
        $mockDb = $this->createMock(Connection::class);

        $mockDb->expects(self::exactly(2))
            ->method('query')
            ->willReturnOnConsecutiveCalls(
                [['cnt' => 2]],
                [
                    [
                        'id' => 'uuid-1',
                        'event' => 'login',
                        'user_id' => 'user-1',
                        'session_id' => null,
                        'device_id' => null,
                        'resource' => null,
                        'action' => null,
                        'success' => 1,
                        'reason' => null,
                        'ip_address' => null,
                        'user_agent' => null,
                        'context_json' => '{"key":"value"}',
                        'created_at' => '2026-01-01 00:00:00',
                    ],
                    [
                        'id' => 'uuid-2',
                        'event' => 'logout',
                        'user_id' => 'user-1',
                        'session_id' => 'session-1',
                        'device_id' => null,
                        'resource' => null,
                        'action' => null,
                        'success' => 1,
                        'reason' => null,
                        'ip_address' => null,
                        'user_agent' => null,
                        'context_json' => null,
                        'created_at' => '2026-01-01 00:01:00',
                    ],
                ],
            );

        $repo = new AuditLogRepository($mockDb);
        $result = $repo->find(['user_id' => 'user-1']);

        self::assertArrayHasKey('entries', $result);
        self::assertArrayHasKey('total', $result);
        self::assertSame(2, $result['total']);
        self::assertCount(2, $result['entries']);

        // Verify first entry has context properly decoded
        self::assertSame(['key' => 'value'], $result['entries'][0]['context']);
        self::assertSame('user-1', $result['entries'][0]['user_id']);
    }

    public function testLogWithAllParameters(): void
    {
        $mockDb = $this->createMock(Connection::class);

        $mockDb->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('INSERT INTO audit_logs'),
                self::callback(function (array $params): bool {
                    return $params['event'] === 'admin_action'
                        && $params['userId'] === 'admin-001'
                        && $params['sessionId'] === 'session-abc'
                        && $params['deviceId'] === 'device-xyz'
                        && $params['resource'] === 'user-123'
                        && $params['action'] === 'user.update'
                        && $params['success'] === 1
                        && $params['reason'] === 'legitimate'
                        && $params['ipAddress'] === '192.168.1.100'
                        && $params['userAgent'] === 'HubAdmin/1.0'
                        && is_string($params['contextJson'])
                        && str_contains($params['contextJson'], 'detail');
                }),
            );

        $repo = new AuditLogRepository($mockDb);
        $repo->log(
            event: 'admin_action',
            userId: 'admin-001',
            sessionId: 'session-abc',
            deviceId: 'device-xyz',
            resource: 'user-123',
            action: 'user.update',
            success: true,
            reason: 'legitimate',
            ipAddress: '192.168.1.100',
            userAgent: 'HubAdmin/1.0',
            context: ['detail' => 'Updated user email'],
        );
    }

    public function testFindWithTimestampFilters(): void
    {
        $mockDb = $this->createMock(Connection::class);
        $callCount = 0;

        $mockDb->expects(self::exactly(2))
            ->method('query')
            ->willReturnCallback(function (string $sql, array $params) use (&$callCount): array {
                $callCount++;
                if ($callCount === 1) {
                    // Count query
                    self::assertArrayHasKey('from', $params);
                    self::assertSame(1704067200, $params['from']);
                    self::assertArrayHasKey('to', $params);
                    self::assertSame(1704153600, $params['to']);
                    return [['cnt' => 0]];
                }
                // Select query
                self::assertStringContainsString('FROM_UNIXTIME(:from)', $sql);
                self::assertStringContainsString('FROM_UNIXTIME(:to)', $sql);
                return [];
            });

        $repo = new AuditLogRepository($mockDb);
        $result = $repo->find([
            'from' => 1704067200,
            'to' => 1704153600,
        ]);

        self::assertArrayHasKey('entries', $result);
    }

    public function testFindWithUserIdFilter(): void
    {
        $mockDb = $this->createMock(Connection::class);

        $mockDb->expects(self::exactly(2))
            ->method('query')
            ->willReturnOnConsecutiveCalls(
                [['cnt' => 1]],
                [[
                    'id' => 'uuid-1',
                    'event' => 'logout',
                    'user_id' => 'user-789',
                    'session_id' => 'session-1',
                    'device_id' => null,
                    'resource' => null,
                    'action' => null,
                    'success' => 1,
                    'reason' => null,
                    'ip_address' => null,
                    'user_agent' => null,
                    'context_json' => null,
                    'created_at' => '2026-01-01 00:00:00',
                ]],
            );

        $repo = new AuditLogRepository($mockDb);
        $result = $repo->find(['user_id' => 'user-789']);

        self::assertCount(1, $result['entries']);
        self::assertSame('user-789', $result['entries'][0]['user_id']);
    }

    public function testFindWithSuccessFilter(): void
    {
        $mockDb = $this->createMock(Connection::class);

        $mockDb->expects(self::exactly(2))
            ->method('query')
            ->willReturnOnConsecutiveCalls(
                [['cnt' => 1]],
                [[
                    'id' => 'uuid-1',
                    'event' => 'login',
                    'user_id' => 'user-1',
                    'session_id' => null,
                    'device_id' => null,
                    'resource' => null,
                    'action' => null,
                    'success' => 1,
                    'reason' => null,
                    'ip_address' => null,
                    'user_agent' => null,
                    'context_json' => null,
                    'created_at' => '2026-01-01 00:00:00',
                ]],
            );

        $repo = new AuditLogRepository($mockDb);
        $result = $repo->find(['success' => true]);

        self::assertCount(1, $result['entries']);
        self::assertTrue($result['entries'][0]['success']);
    }

    public function testFindWithActionFilter(): void
    {
        $mockDb = $this->createMock(Connection::class);

        $mockDb->expects(self::exactly(2))
            ->method('query')
            ->willReturnOnConsecutiveCalls(
                [['cnt' => 1]],
                [[
                    'id' => 'uuid-1',
                    'event' => 'admin_action',
                    'user_id' => 'admin-1',
                    'session_id' => null,
                    'device_id' => null,
                    'resource' => 'user-123',
                    'action' => 'user.delete',
                    'success' => 1,
                    'reason' => null,
                    'ip_address' => null,
                    'user_agent' => null,
                    'context_json' => null,
                    'created_at' => '2026-01-01 00:00:00',
                ]],
            );

        $repo = new AuditLogRepository($mockDb);
        $result = $repo->find(['action' => 'user.delete']);

        self::assertCount(1, $result['entries']);
        self::assertSame('user.delete', $result['entries'][0]['action']);
    }

    public function testFindJoinsUsersAndQualifiesWhereWithAlias(): void
    {
        $mockDb = $this->createMock(Connection::class);
        $callCount = 0;

        $mockDb->expects(self::exactly(2))
            ->method('query')
            ->willReturnCallback(function (string $sql) use (&$callCount): array {
                $callCount++;
                if ($callCount === 1) {
                    // Count query: aliased table, no join, alias-qualified WHERE.
                    self::assertStringContainsString('FROM audit_logs al', $sql);
                    self::assertStringContainsString('al.event = :event', $sql);
                    return [['cnt' => 1]];
                }
                // Select query: LEFT JOIN users for the actor name, alias-qualified order.
                self::assertStringContainsString('LEFT JOIN users u ON u.id = al.user_id', $sql);
                self::assertStringContainsString('u.display_name AS actor_name', $sql);
                self::assertStringContainsString('u.username AS actor_username', $sql);
                self::assertStringContainsString('al.event = :event', $sql);
                self::assertStringContainsString('ORDER BY al.created_at DESC', $sql);
                return [[
                    'id' => 'uuid-1',
                    'event' => 'login',
                    'user_id' => 'user-1',
                    'session_id' => null,
                    'device_id' => null,
                    'resource' => null,
                    'action' => null,
                    'success' => 1,
                    'reason' => null,
                    'ip_address' => null,
                    'user_agent' => null,
                    'context_json' => null,
                    'created_at' => '2026-01-01 00:00:00',
                    'actor_name' => 'Alice Admin',
                    'actor_username' => 'alice',
                ]];
            });

        $repo = new AuditLogRepository($mockDb);
        $result = $repo->find(['event' => 'login']);

        self::assertCount(1, $result['entries']);
        self::assertSame('Alice Admin', $result['entries'][0]['actor']);
    }

    public function testFindActorFallsBackToUsernameWhenDisplayNameEmpty(): void
    {
        $mockDb = $this->createMock(Connection::class);

        $mockDb->expects(self::exactly(2))
            ->method('query')
            ->willReturnOnConsecutiveCalls(
                [['cnt' => 1]],
                [[
                    'id' => 'uuid-1',
                    'event' => 'login',
                    'user_id' => 'user-1',
                    'session_id' => null,
                    'device_id' => null,
                    'resource' => null,
                    'action' => null,
                    'success' => 1,
                    'reason' => null,
                    'ip_address' => null,
                    'user_agent' => null,
                    'context_json' => null,
                    'created_at' => '2026-01-01 00:00:00',
                    'actor_name' => '',
                    'actor_username' => 'bob',
                ]],
            );

        $repo = new AuditLogRepository($mockDb);
        $result = $repo->find(['event' => 'login']);

        self::assertSame('bob', $result['entries'][0]['actor']);
    }

    public function testFindActorIsNullForSystemEvents(): void
    {
        $mockDb = $this->createMock(Connection::class);

        $mockDb->expects(self::exactly(2))
            ->method('query')
            ->willReturnOnConsecutiveCalls(
                [['cnt' => 1]],
                [[
                    'id' => 'uuid-1',
                    'event' => 'system_startup',
                    'user_id' => null,
                    'session_id' => null,
                    'device_id' => null,
                    'resource' => null,
                    'action' => null,
                    'success' => 1,
                    'reason' => null,
                    'ip_address' => null,
                    'user_agent' => null,
                    'context_json' => null,
                    'created_at' => '2026-01-01 00:00:00',
                    'actor_name' => null,
                    'actor_username' => null,
                ]],
            );

        $repo = new AuditLogRepository($mockDb);
        $result = $repo->find([]);

        self::assertArrayHasKey('actor', $result['entries'][0]);
        self::assertNull($result['entries'][0]['actor']);
    }
}
