<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Federation;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Federation\FederationSessionManager;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see FederationSessionManager}.
 *
 * @package Phlix\Hub\Tests\Unit\Federation
 */
final class FederationSessionManagerTest extends TestCase
{
    public function testRegisterSessionInsertsSessionAndUpdatesPeer(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        $db->expects(self::exactly(2))
            ->method('query')
            ->willReturnCallback(function (string $sql, array $params) {
                if (str_contains($sql, 'INSERT INTO federation_sessions')) {
                    self::assertSame('peer-123', $params['peer_id']);
                    return [];
                }
                if (str_contains($sql, 'UPDATE federation_peers')) {
                    self::assertSame('peer-123', $params['id']);
                    self::assertSame('connected', $params['status']);
                    return [];
                }
                return [];
            });

        $logger->expects(self::once())
            ->method('info')
            ->with(
                'Federation session registered',
                self::callback(function (array $context) {
                    return isset($context['session_id']) && isset($context['peer_id'])
                        && $context['peer_id'] === 'peer-123';
                })
            );

        $manager = new FederationSessionManager($db, $logger);
        $sessionId = $manager->registerSession('peer-123');

        self::assertIsString($sessionId);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $sessionId
        );
    }

    public function testTouchHeartbeatUpdatesSession(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        $db->expects(self::once())
            ->method('query')
            ->with(
                self::callback(fn (string $sql) => str_contains($sql, 'UPDATE federation_sessions')),
                ['id' => 'session-456']
            )
            ->willReturn([]);

        $manager = new FederationSessionManager($db, $logger);
        $manager->touchHeartbeat('session-456');
    }

    public function testRecordBytesOutUpdatesBytesSent(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        $db->expects(self::once())
            ->method('query')
            ->with(
                self::callback(fn (string $sql) => str_contains($sql, 'bytes_sent')),
                ['bytes' => 1024, 'id' => 'session-789']
            )
            ->willReturn([]);

        $manager = new FederationSessionManager($db, $logger);
        $manager->recordBytesOut('session-789', 1024);
    }

    public function testRecordBytesInUpdatesBytesReceived(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        $db->expects(self::once())
            ->method('query')
            ->with(
                self::callback(fn (string $sql) => str_contains($sql, 'bytes_received')),
                ['bytes' => 2048, 'id' => 'session-abc']
            )
            ->willReturn([]);

        $manager = new FederationSessionManager($db, $logger);
        $manager->recordBytesIn('session-abc', 2048);
    }

    public function testCloseSessionMarksSessionDeadAndUpdatesPeer(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        $db->expects(self::exactly(3))
            ->method('query')
            ->willReturnCallback(function (string $sql, array $params) {
                if (str_contains($sql, 'SELECT peer_id')) {
                    return [['peer_id' => 'peer-xyz']];
                }
                if (str_contains($sql, 'UPDATE federation_sessions') && str_contains($sql, 'alive = 0')) {
                    return [];
                }
                if (str_contains($sql, 'UPDATE federation_peers')) {
                    self::assertSame('peer-xyz', $params['id']);
                    self::assertSame('disconnected', $params['status']);
                    return [];
                }
                return [];
            });

        $logger->expects(self::once())
            ->method('info')
            ->with(
                'Federation session closed',
                self::callback(function (array $context) {
                    return $context['session_id'] === 'session-close'
                        && $context['peer_id'] === 'peer-xyz';
                })
            );

        $manager = new FederationSessionManager($db, $logger);
        $manager->closeSession('session-close');
    }

    public function testCloseSessionHandlesMissingPeer(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        $db->expects(self::exactly(2))
            ->method('query')
            ->willReturnCallback(function (string $sql, array $params) {
                if (str_contains($sql, 'SELECT peer_id')) {
                    return []; // No peer found
                }
                if (str_contains($sql, 'UPDATE federation_sessions')) {
                    return [];
                }
                return [];
            });

        $logger->expects(self::once())
            ->method('info')
            ->with(
                'Federation session closed',
                self::callback(function (array $context) {
                    return $context['peer_id'] === null;
                })
            );

        $manager = new FederationSessionManager($db, $logger);
        $manager->closeSession('session-no-peer');
    }

    public function testGetActiveSessionReturnsSessionWhenFound(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        $expectedSession = [
            'id' => 'session-active',
            'peer_id' => 'peer-active',
            'alive' => 1,
        ];

        $db->expects(self::once())
            ->method('query')
            ->with(
                self::callback(fn (string $sql) => str_contains($sql, 'SELECT * FROM federation_sessions')),
                ['peer_id' => 'peer-active']
            )
            ->willReturn([$expectedSession]);

        $manager = new FederationSessionManager($db, $logger);
        $session = $manager->getActiveSession('peer-active');

        self::assertSame($expectedSession, $session);
    }

    public function testGetActiveSessionReturnsNullWhenNotFound(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        $db->expects(self::once())
            ->method('query')
            ->willReturn([]);

        $manager = new FederationSessionManager($db, $logger);
        $session = $manager->getActiveSession('peer-inactive');

        self::assertNull($session);
    }

    public function testReapDeadSessionsMarksStaleSessionsDead(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        $deadSessions = [
            ['id' => 'dead-session-1', 'peer_id' => 'dead-peer-1'],
            ['id' => 'dead-session-2', 'peer_id' => 'dead-peer-2'],
        ];

        // 1 select + 2 sessions * (1 update session + 1 update peer) = 5 total
        $db->expects(self::exactly(5))
            ->method('query')
            ->willReturnCallback(function (string $sql) use ($deadSessions) {
                if (str_contains($sql, 'SELECT id, peer_id FROM federation_sessions')) {
                    return $deadSessions;
                }
                return [];
            });

        $logger->expects(self::once())
            ->method('info')
            ->with(
                'Reaped dead federation sessions',
                self::callback(function (array $context) {
                    return $context['count'] === 2 && $context['threshold_seconds'] === 60;
                })
            );

        $manager = new FederationSessionManager($db, $logger);
        $count = $manager->reapDeadSessions(60);

        self::assertSame(2, $count);
    }

    public function testReapDeadSessionsWithCustomThreshold(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        $db->expects(self::once())
            ->method('query')
            ->with(
                self::callback(fn (string $sql) => str_contains($sql, 'DATE_SUB(NOW(), INTERVAL')),
                ['threshold' => 120]
            )
            ->willReturn([]);

        $manager = new FederationSessionManager($db, $logger);
        $count = $manager->reapDeadSessions(120);

        self::assertSame(0, $count);
    }

    public function testReapDeadSessionsNoLogWhenNoDeadSessions(): void
    {
        $db = $this->createMock(Connection::class);
        $logger = $this->createMock(StructuredLogger::class);

        $db->expects(self::once())
            ->method('query')
            ->willReturn([]);

        $logger->expects(self::never())
            ->method('info');

        $manager = new FederationSessionManager($db, $logger);
        $count = $manager->reapDeadSessions();

        self::assertSame(0, $count);
    }
}
