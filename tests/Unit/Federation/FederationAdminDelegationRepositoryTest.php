<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Federation;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\Federation\FederationAdminDelegationRepository;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see FederationAdminDelegationRepository}.
 *
 * @package Phlix\Hub\Tests\Unit\Federation
 */
final class FederationAdminDelegationRepositoryTest extends TestCase
{
    public function testGrantInsertsDelegation(): void
    {
        $db = $this->createMock(Connection::class);

        $db->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('INSERT INTO federation_admin_delegations'),
                self::callback(function (array $params) {
                    return $params['id'] === 'delegation-1'
                        && $params['peer_id'] === 'peer-admin'
                        && $params['user_id'] === 'user-delegated';
                })
            )
            ->willReturn([]);

        $repo = new FederationAdminDelegationRepository($db);
        $repo->grant('delegation-1', 'peer-admin', 'user-delegated');
    }

    public function testRevokeSetsRevokedAtTimestamp(): void
    {
        $db = $this->createMock(Connection::class);

        $db->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('UPDATE federation_admin_delegations'),
                ['id' => 'delegation-revoke']
            )
            ->willReturn([]);

        $repo = new FederationAdminDelegationRepository($db);
        $repo->revoke('delegation-revoke');
    }

    public function testGetActiveDelegationsForUserReturnsDelegations(): void
    {
        $db = $this->createMock(Connection::class);
        $delegations = [
            ['id' => 'del-1', 'user_id' => 'user-123', 'peer_id' => 'peer-a'],
            ['id' => 'del-2', 'user_id' => 'user-123', 'peer_id' => 'peer-b'],
        ];

        $db->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('SELECT * FROM federation_admin_delegations'),
                ['user_id' => 'user-123']
            )
            ->willReturn($delegations);

        $repo = new FederationAdminDelegationRepository($db);
        $result = $repo->getActiveDelegationsForUser('user-123');

        self::assertSame($delegations, $result);
    }

    public function testGetActiveDelegationsForUserReturnsEmptyWhenNone(): void
    {
        $db = $this->createMock(Connection::class);

        $db->expects(self::once())
            ->method('query')
            ->willReturn([]);

        $repo = new FederationAdminDelegationRepository($db);
        $result = $repo->getActiveDelegationsForUser('user-no-delegations');

        self::assertSame([], $result);
    }

    public function testGetActiveDelegationsForPeerReturnsDelegations(): void
    {
        $db = $this->createMock(Connection::class);
        $delegations = [
            ['id' => 'del-peer-1', 'peer_id' => 'peer-x', 'user_id' => 'user-1'],
        ];

        $db->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('SELECT * FROM federation_admin_delegations'),
                ['peer_id' => 'peer-x']
            )
            ->willReturn($delegations);

        $repo = new FederationAdminDelegationRepository($db);
        $result = $repo->getActiveDelegationsForPeer('peer-x');

        self::assertSame($delegations, $result);
    }

    public function testGetDelegationByIdReturnsDelegationWhenFound(): void
    {
        $db = $this->createMock(Connection::class);
        $delegation = [
            'id' => 'del-find',
            'peer_id' => 'peer-del',
            'user_id' => 'user-del',
        ];

        $db->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('SELECT * FROM federation_admin_delegations WHERE id'),
                ['id' => 'del-find']
            )
            ->willReturn([$delegation]);

        $repo = new FederationAdminDelegationRepository($db);
        $result = $repo->getDelegationById('del-find');

        self::assertSame($delegation, $result);
    }

    public function testGetDelegationByIdReturnsNullWhenNotFound(): void
    {
        $db = $this->createMock(Connection::class);

        $db->expects(self::once())
            ->method('query')
            ->willReturn([]);

        $repo = new FederationAdminDelegationRepository($db);
        $result = $repo->getDelegationById('non-existent-delegation');

        self::assertNull($result);
    }

    public function testIsUserAdminOnPeerReturnsTrueWhenDelegationExists(): void
    {
        $db = $this->createMock(Connection::class);

        $db->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('SELECT id FROM federation_admin_delegations'),
                self::callback(function (array $params) {
                    return $params['peer_id'] === 'peer-admin-check'
                        && $params['user_id'] === 'user-admin-check';
                })
            )
            ->willReturn([['id' => 'del-existing']]);

        $repo = new FederationAdminDelegationRepository($db);
        $result = $repo->isUserAdminOnPeer('peer-admin-check', 'user-admin-check');

        self::assertTrue($result);
    }

    public function testIsUserAdminOnPeerReturnsFalseWhenNoDelegation(): void
    {
        $db = $this->createMock(Connection::class);

        $db->expects(self::once())
            ->method('query')
            ->willReturn([]);

        $repo = new FederationAdminDelegationRepository($db);
        $result = $repo->isUserAdminOnPeer('peer-no-admin', 'user-no-admin');

        self::assertFalse($result);
    }

    public function testIsUserAdminOnPeerReturnsFalseWhenOnlyRevoked(): void
    {
        $db = $this->createMock(Connection::class);

        // isUserAdminOnPeer only looks for non-revoked delegations
        // If the query returns empty, the delegation was either revoked or doesn't exist
        $db->expects(self::once())
            ->method('query')
            ->willReturn([]);

        $repo = new FederationAdminDelegationRepository($db);
        $result = $repo->isUserAdminOnPeer('peer-revoked', 'user-revoked');

        self::assertFalse($result);
    }
}
