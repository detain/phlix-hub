<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Hub\LibraryShare;
use Phlix\Hub\Hub\LibrarySharingHandler;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see LibrarySharingHandler}.
 *
 * @package Phlix\Hub\Tests\Unit\Hub
 * @since 0.5.0
 *
 * @covers \Phlix\Hub\Hub\LibrarySharingHandler
 */
final class LibrarySharingHandlerTest extends TestCase
{
    private Connection $db;
    private UserRepository $users;
    private StructuredLogger $logger;
    private LibrarySharingHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->createMock(Connection::class);
        $this->users = $this->createMock(UserRepository::class);
        $this->logger = $this->createMock(StructuredLogger::class);

        $this->handler = new LibrarySharingHandler(
            $this->db,
            $this->users,
            $this->logger,
        );
    }

    public function testShareLibraryCreatesShare(): void
    {
        $this->users->method('findByEmail')->willReturn(['id' => 'collab-1', 'email' => 'friend@example.com']);

        $this->db->method('query')->willReturnCallback(function (string $sql, array $params) {
            if (str_contains($sql, 'SELECT id FROM servers')) {
                return [['id' => 'server-1']];
            }
            if (str_contains($sql, 'SELECT * FROM library_shares')) {
                return [];
            }
            if (str_contains($sql, 'INSERT INTO library_shares')) {
                return [];
            }
            return [];
        });

        $share = $this->handler->shareLibrary(
            ownerId: 'owner-1',
            collaboratorEmail: 'friend@example.com',
            serverId: 'server-1',
            libraryId: 'lib-1',
            libraryName: 'My Movies',
            permission: LibraryShare::PERMISSION_READ,
        );

        self::assertSame('owner-1', $share->ownerUserId);
        self::assertSame('collab-1', $share->collaboratorUserId);
        self::assertSame('server-1', $share->serverId);
        self::assertSame('lib-1', $share->libraryId);
        self::assertSame('My Movies', $share->libraryName);
        self::assertSame('read', $share->permissionLevel);
    }

    public function testShareLibraryThrowsWhenUserNotFound(): void
    {
        $this->users->method('findByEmail')->willReturn(null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(404);

        $this->handler->shareLibrary(
            ownerId: 'owner-1',
            collaboratorEmail: 'nonexistent@example.com',
            serverId: 'server-1',
            libraryId: 'lib-1',
            libraryName: 'My Movies',
        );
    }

    public function testShareLibraryThrowsWhenNotServerOwner(): void
    {
        $this->users->method('findByEmail')->willReturn(['id' => 'collab-1', 'email' => 'friend@example.com']);

        $this->db->method('query')->willReturnCallback(function (string $sql) {
            if (str_contains($sql, 'SELECT id FROM servers')) {
                return [];
            }
            return [];
        });

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(403);

        $this->handler->shareLibrary(
            ownerId: 'owner-1',
            collaboratorEmail: 'friend@example.com',
            serverId: 'server-1',
            libraryId: 'lib-1',
            libraryName: 'My Movies',
        );
    }

    public function testShareLibraryThrowsWhenShareAlreadyExists(): void
    {
        $this->users->method('findByEmail')->willReturn(['id' => 'collab-1', 'email' => 'friend@example.com']);

        $existingShare = new LibraryShare(
            id: 'existing-share',
            ownerUserId: 'owner-1',
            collaboratorUserId: 'collab-1',
            serverId: 'server-1',
            libraryId: 'lib-1',
            libraryName: 'My Movies',
            permissionLevel: 'read',
            createdAt: time(),
        );

        $this->db->method('query')->willReturnCallback(function (string $sql) use ($existingShare) {
            if (str_contains($sql, 'SELECT id FROM servers')) {
                return [['id' => 'server-1']];
            }
            if (str_contains($sql, 'SELECT * FROM library_shares')) {
                return [$existingShare->toPayload()];
            }
            return [];
        });

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(409);

        $this->handler->shareLibrary(
            ownerId: 'owner-1',
            collaboratorEmail: 'friend@example.com',
            serverId: 'server-1',
            libraryId: 'lib-1',
            libraryName: 'My Movies',
        );
    }

    public function testShareLibraryThrowsWhenSharingWithSelf(): void
    {
        $this->users->method('findByEmail')->willReturn(['id' => 'owner-1', 'email' => 'me@example.com']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(400);

        $this->handler->shareLibrary(
            ownerId: 'owner-1',
            collaboratorEmail: 'me@example.com',
            serverId: 'server-1',
            libraryId: 'lib-1',
            libraryName: 'My Movies',
        );
    }

    public function testRevokeShareDeletesRow(): void
    {
        $share = new LibraryShare(
            id: 'share-1',
            ownerUserId: 'owner-1',
            collaboratorUserId: 'collab-1',
            serverId: 'server-1',
            libraryId: 'lib-1',
            libraryName: 'My Movies',
            permissionLevel: 'read',
            createdAt: time(),
        );

        $this->db->method('query')->willReturnCallback(function (string $sql) use ($share) {
            if (str_contains($sql, 'SELECT * FROM library_shares')) {
                return [$share->toPayload()];
            }
            return [];
        });

        $this->handler->revokeShare('owner-1', 'share-1');
        self::assertTrue(true);
    }

    public function testRevokeShareThrowsWhenShareNotFound(): void
    {
        $this->db->method('query')->willReturn([]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(404);

        $this->handler->revokeShare('owner-1', 'nonexistent-share');
    }

    public function testRevokeShareThrowsWhenNotOwner(): void
    {
        $share = new LibraryShare(
            id: 'share-1',
            ownerUserId: 'other-owner',
            collaboratorUserId: 'collab-1',
            serverId: 'server-1',
            libraryId: 'lib-1',
            libraryName: 'My Movies',
            permissionLevel: 'read',
            createdAt: time(),
        );

        $this->db->method('query')->willReturnCallback(function (string $sql) use ($share) {
            if (str_contains($sql, 'SELECT * FROM library_shares')) {
                return [$share->toPayload()];
            }
            return [];
        });

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(403);

        $this->handler->revokeShare('owner-1', 'share-1');
    }

    public function testGetSharesForOwnerReturnsOutgoingShares(): void
    {
        $shares = [
            [
                'id' => 'share-1',
                'owner_user_id' => 'owner-1',
                'collaborator_user_id' => 'collab-1',
                'server_id' => 'server-1',
                'library_id' => 'lib-1',
                'library_name' => 'My Movies',
                'permission_level' => 'read',
                'created_at' => time(),
                'expires_at' => null,
                'revoked_at' => null,
            ],
        ];

        $this->db->method('query')->willReturn($shares);

        $result = $this->handler->getSharesForOwner('owner-1');

        self::assertCount(1, $result);
        self::assertSame('share-1', $result[0]->id);
    }

    public function testGetSharesForOwnerExcludesRevoked(): void
    {
        $shares = [
            [
                'id' => 'share-1',
                'owner_user_id' => 'owner-1',
                'collaborator_user_id' => 'collab-1',
                'server_id' => 'server-1',
                'library_id' => 'lib-1',
                'library_name' => 'My Movies',
                'permission_level' => 'read',
                'created_at' => time() - 3600,
                'expires_at' => null,
                'revoked_at' => time(),
            ],
        ];

        $this->db->method('query')->willReturn($shares);

        $result = $this->handler->getSharesForOwner('owner-1');

        self::assertCount(0, $result);
    }

    public function testGetSharedWithMeReturnsIncomingShares(): void
    {
        $rows = [
            [
                'id' => 'share-1',
                'owner_user_id' => 'owner-1',
                'collaborator_user_id' => 'collab-1',
                'server_id' => 'server-1',
                'library_id' => 'lib-1',
                'library_name' => 'My Movies',
                'permission_level' => 'read',
                'created_at' => time(),
                'expires_at' => null,
                'owner_name' => 'Owner User',
                'server_name' => 'My Server',
                'hostname_candidates_json' => '["https://server.example.com"]',
            ],
        ];

        $this->db->method('query')->willReturn($rows);

        $result = $this->handler->getSharedWithMe('collab-1');

        self::assertCount(1, $result);
        self::assertSame('share-1', $result[0]->shareId);
        self::assertSame('owner-1', $result[0]->ownerUserId);
        self::assertSame('Owner User', $result[0]->ownerName);
        self::assertSame('server-1', $result[0]->serverId);
        self::assertSame('My Server', $result[0]->serverName);
        self::assertSame('lib-1', $result[0]->libraryId);
        self::assertSame('My Movies', $result[0]->libraryName);
    }

    public function testGetSharedWithMeEmpty(): void
    {
        $this->db->method('query')->willReturn([]);

        $result = $this->handler->getSharedWithMe('user-with-no-shares');

        self::assertCount(0, $result);
    }

    public function testIsServerOwnedByUserReturnsTrueWhenOwned(): void
    {
        $this->db->method('query')->willReturn([['id' => 'server-1']]);

        $result = $this->handler->isServerOwnedByUser('server-1', 'user-1');

        self::assertTrue($result);
    }

    public function testIsServerOwnedByUserReturnsFalseWhenNotOwned(): void
    {
        $this->db->method('query')->willReturn([]);

        $result = $this->handler->isServerOwnedByUser('server-1', 'user-2');

        self::assertFalse($result);
    }

    public function testUpdateSharePermissionUpdatesRow(): void
    {
        $share = new LibraryShare(
            id: 'share-1',
            ownerUserId: 'owner-1',
            collaboratorUserId: 'collab-1',
            serverId: 'server-1',
            libraryId: 'lib-1',
            libraryName: 'My Movies',
            permissionLevel: 'read',
            createdAt: time(),
        );

        $this->db->method('query')->willReturnCallback(function (string $sql) use ($share) {
            if (str_contains($sql, 'SELECT * FROM library_shares')) {
                return [$share->toPayload()];
            }
            if (str_contains($sql, 'UPDATE library_shares')) {
                return [];
            }
            return [];
        });

        $this->handler->updateSharePermission('owner-1', 'share-1', LibraryShare::PERMISSION_READWRITE);
        self::assertTrue(true);
    }
}
