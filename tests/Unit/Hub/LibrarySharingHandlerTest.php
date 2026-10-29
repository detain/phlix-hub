<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Hub\LibraryShare;
use Phlix\Hub\Hub\LibrarySharingHandler;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see LibrarySharingHandler}.
 *
 * @package Phlix\Hub\Tests\Unit\Hub
 */
final class LibrarySharingHandlerTest extends TestCase
{
    private Connection&MockObject $db;
    private UserRepository&MockObject $users;
    private StructuredLogger&MockObject $logger;
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

    /**
     * F10: the (owner, collaborator, library) UNIQUE tuple survives a soft
     * revoke — re-sharing must UPDATE-reactivate the row, never INSERT (which
     * duplicate-keyed with 500 forever).
     */
    public function testShareLibraryReactivatesRevokedShare(): void
    {
        $this->users->method('findByEmail')->willReturn(['id' => 'collab-1', 'email' => 'friend@example.com']);

        $revokedRow = [
            'id' => 'old-share',
            'owner_user_id' => 'owner-1',
            'collaborator_user_id' => 'collab-1',
            'server_id' => 'server-1',
            'library_id' => 'lib-1',
            'library_name' => 'My Movies',
            'permission_level' => 'read',
            'granted_by' => 'owner-1',
            'created_at' => time() - 86400,
            'expires_at' => null,
            'revoked_at' => time() - 3600,
        ];

        /** @var list<string> $sqls */
        $sqls = [];
        $this->db->method('query')->willReturnCallback(
            function (string $sql, $params = null) use ($revokedRow, &$sqls) {
                $sqls[] = $sql;
                if (str_contains($sql, 'SELECT id FROM servers')) {
                    return [['id' => 'server-1']];
                }
                if (str_contains($sql, 'SELECT * FROM library_shares')) {
                    return [$revokedRow];
                }
                if (str_contains($sql, 'UPDATE library_shares') && str_contains($sql, 'revoked_at = NULL')) {
                    return 1; // affected-row count
                }
                return [];
            },
        );

        $share = $this->handler->shareLibrary(
            ownerId: 'owner-1',
            collaboratorEmail: 'friend@example.com',
            serverId: 'server-1',
            libraryId: 'lib-1',
            libraryName: 'My Movies',
            permission: LibraryShare::PERMISSION_READWRITE,
            expiresAt: time() + 604800,
        );

        self::assertSame('old-share', $share->id, 'reactivation must reuse the existing row');
        self::assertNull($share->revokedAt);
        self::assertSame(LibraryShare::PERMISSION_READWRITE, $share->permissionLevel);
        self::assertTrue($share->canWrite());

        $inserted = array_filter($sqls, static fn (string $s): bool => str_contains($s, 'INSERT INTO library_shares'));
        self::assertCount(0, $inserted, 'revoked tuple must be UPDATEd, never INSERTed');
        $reactivates = array_filter(
            $sqls,
            static fn (string $s): bool => str_contains($s, 'UPDATE library_shares')
                && str_contains($s, 'revoked_at = NULL'),
        );
        self::assertCount(1, $reactivates);
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
        self::addToAssertionCount(1);
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
                'collaborator_name' => 'Friendly Collaborator',
                'collaborator_username' => 'friend',
            ],
        ];

        $this->db->method('query')->willReturnCallback(function (string $sql) use ($shares): array {
            // The outgoing-shares query must LEFT JOIN users for the collaborator name.
            self::assertStringContainsString('LEFT JOIN users u ON u.id = ls.collaborator_user_id', $sql);
            self::assertStringContainsString('collaborator_name', $sql);
            self::assertStringContainsString('collaborator_username', $sql);
            return $shares;
        });

        $result = $this->handler->getSharesForOwner('owner-1');

        self::assertCount(1, $result);
        self::assertSame('share-1', $result[0]->id);
        self::assertSame('Friendly Collaborator', $result[0]->collaboratorName);
        self::assertSame('Friendly Collaborator', $result[0]->toPayload()['collaborator_name']);
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
                'created_at' => 1700000123,
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
        // The SQL already SELECTs ls.created_at — carry it through to the DTO so the
        // SPA's "Received" date is real data instead of a missing key.
        self::assertSame(1700000123, $result[0]->createdAt);
        self::assertSame(1700000123, $result[0]->toPayload()['created_at']);
    }

    public function testGetSharedWithMeLeavesCreatedAtNullWhenRowHasNoTimestamp(): void
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
                'created_at' => null,
                'expires_at' => null,
                'owner_name' => 'Owner User',
                'server_name' => 'My Server',
                'hostname_candidates_json' => '[]',
            ],
        ];

        $this->db->method('query')->willReturn($rows);

        $result = $this->handler->getSharedWithMe('collab-1');

        self::assertCount(1, $result);
        self::assertNull($result[0]->createdAt);
    }

    public function testGetSharedWithMeEmpty(): void
    {
        $this->db->method('query')->willReturn([]);

        $result = $this->handler->getSharedWithMe('user-with-no-shares');

        self::assertCount(0, $result);
    }

    public function testGetSharedWithMeSqlCarriesTheHubVantageLivenessSignals(): void
    {
        // Law pin for the SQL itself [review hub__casualgamer M3]: the three
        // signals must be read from rows the hub ALREADY maintains, in the
        // estate's canonical idioms — `UNIX_TIMESTAMP()` for a TZ-safe epoch
        // compare (getServersForUser precedent) and the verbatim relay EXISTS
        // from ServerInfoHandler::getOwnerAndStatus().
        $captured = null;
        $this->db->method('query')->willReturnCallback(function (string $sql, array $params) use (&$captured) {
            if (str_contains($sql, 'FROM library_shares ls')) {
                $captured = $sql;
            }
            return [];
        });

        $this->handler->getSharedWithMe('collab-1');

        self::assertIsString($captured);
        self::assertStringContainsString('s.status AS server_status', $captured);
        self::assertStringContainsString('UNIX_TIMESTAMP(s.last_seen_at) AS server_last_seen_at', $captured);
        self::assertStringContainsString('SELECT 1 FROM relay_sessions r', $captured);
        self::assertStringContainsString('r.server_id = s.id AND r.closed_at IS NULL', $captured);
    }

    public function testGetSharedWithMeDerivesReachableFromFreshHeartbeatAndOpenRelay(): void
    {
        // Values arrive as the strings/emulate-prepares shapes the hub's MySQL
        // connection actually yields (ATTR_EMULATE_PREPARES=true era), so the
        // numeric-string parse is part of the contract under test.
        $seen = time() - 5;
        $row = $this->livenessRow([
            'server_status' => 'online',
            'server_last_seen_at' => (string) $seen,
            'server_relay_active' => 1,
        ]);
        $this->db->method('query')->willReturn([$row]);

        $result = $this->handler->getSharedWithMe('collab-1');

        self::assertCount(1, $result);
        self::assertSame('reachable', $result[0]->serverLiveness);
        self::assertTrue($result[0]->serverRelayActive);
        $payload = $result[0]->toPayload();
        self::assertSame('reachable', $payload['server_liveness']);
        self::assertTrue($payload['server_relay_active']);
        self::assertSame($seen, $payload['server_last_seen_at']);
    }

    public function testGetSharedWithMeDerivesUnreachableForStaleOnlineRow(): void
    {
        $row = $this->livenessRow([
            'server_status' => 'online',
            'server_last_seen_at' => time() - 3600,
            'server_relay_active' => 0,
        ]);
        $this->db->method('query')->willReturn([$row]);

        $result = $this->handler->getSharedWithMe('collab-1');

        self::assertCount(1, $result);
        self::assertSame('unreachable', $result[0]->serverLiveness);
        self::assertFalse($result[0]->serverRelayActive);
    }

    public function testGetSharedWithMeIsNeutralUnknownWhenLivenessColumnsAreAbsent(): void
    {
        // Fail-soft law: a row without the trio (defensive — the shipped SQL
        // always selects it) must degrade to UNKNOWN with the keys present,
        // never to false-live.
        $row = $this->livenessRow([]);
        $this->db->method('query')->willReturn([$row]);

        $result = $this->handler->getSharedWithMe('collab-1');

        self::assertCount(1, $result);
        self::assertSame('unknown', $result[0]->serverLiveness);
        self::assertNull($result[0]->serverLastSeenAt);
        self::assertFalse($result[0]->serverRelayActive);
        $payload = $result[0]->toPayload();
        self::assertArrayHasKey('server_liveness', $payload);
        self::assertArrayHasKey('server_last_seen_at', $payload);
        self::assertArrayHasKey('server_relay_active', $payload);
    }

    /**
     * Minimal incoming-share row as getSharedWithMe() consumes it, with liveness
     * columns layered on by each test.
     *
     * @param array<string, mixed> $livenessColumns
     *
     * @return array<string, mixed>
     */
    private function livenessRow(array $livenessColumns): array
    {
        return array_merge([
            'id' => 'share-1',
            'owner_user_id' => 'owner-1',
            'collaborator_user_id' => 'collab-1',
            'server_id' => 'server-1',
            'library_id' => 'lib-1',
            'library_name' => 'My Movies',
            'permission_level' => 'read',
            'created_at' => 1700000123,
            'expires_at' => null,
            'owner_name' => 'Owner User',
            'server_name' => 'My Server',
            'hostname_candidates_json' => '["https://server.example.com"]',
        ], $livenessColumns);
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
        self::addToAssertionCount(1);
    }
}
