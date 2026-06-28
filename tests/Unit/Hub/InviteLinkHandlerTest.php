<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Phlix\Hub\Auth\JwtHandler;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Hub\InviteLink;
use Phlix\Hub\Hub\InviteLinkHandler;
use Phlix\Hub\Hub\LibraryShare;
use Phlix\Hub\Hub\LibrarySharingHandler;
use Phlix\Hub\Tests\Support\SingleUseInviteConnection;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see InviteLinkHandler}.
 *
 * @package Phlix\Hub\Tests\Unit\Hub
 *
 * @covers \Phlix\Hub\Hub\InviteLinkHandler
 */
final class InviteLinkHandlerTest extends TestCase
{
    private Connection $db;
    private JwtHandler $jwtHandler;
    private LibrarySharingHandler $sharingHandler;
    private StructuredLogger $logger;
    private InviteLinkHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->createMock(Connection::class);
        $this->jwtHandler = new JwtHandler('test-secret-key-that-is-at-least-32-bytes-long');
        $this->sharingHandler = $this->createMock(LibrarySharingHandler::class);
        $this->logger = $this->createMock(StructuredLogger::class);

        $this->handler = new InviteLinkHandler(
            $this->db,
            $this->jwtHandler,
            $this->sharingHandler,
            $this->logger,
            'http://localhost:8800',
        );
    }

    public function testCreateInviteLinkSuccess(): void
    {
        $this->sharingHandler->method('isServerOwnedByUser')->willReturn(true);

        $this->db->method('query')->willReturnCallback(function (string $sql) {
            if (str_contains($sql, 'INSERT INTO invite_links')) {
                return [];
            }
            return [];
        });

        $link = $this->handler->createInviteLink(
            ownerId: 'owner-1',
            serverId: 'server-1',
            libraryId: 'lib-1',
            permission: 'read',
            maxUses: 1,
            expiresAt: null,
        );

        self::assertSame('owner-1', $link->ownerUserId);
        self::assertSame('server-1', $link->serverId);
        self::assertSame('lib-1', $link->libraryId);
        self::assertSame('read', $link->permission);
        self::assertSame(1, $link->maxUses);
        self::assertSame(0, $link->useCount);
        self::assertStringContainsString('http://localhost:8800/invite/', $link->url);
    }

    public function testCreateInviteLinkNotOwnerThrows(): void
    {
        $this->sharingHandler->method('isServerOwnedByUser')->willReturn(false);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(403);

        $this->handler->createInviteLink(
            ownerId: 'owner-1',
            serverId: 'server-1',
            libraryId: 'lib-1',
        );
    }

    public function testCreateInviteLinkInvalidPermissionThrows(): void
    {
        $this->sharingHandler->method('isServerOwnedByUser')->willReturn(true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(400);

        $this->handler->createInviteLink(
            ownerId: 'owner-1',
            serverId: 'server-1',
            libraryId: 'lib-1',
            permission: 'invalid',
        );
    }

    public function testCreateInviteLinkWithAllLibraries(): void
    {
        $this->sharingHandler->method('isServerOwnedByUser')->willReturn(true);

        $this->db->method('query')->willReturn([]);

        $link = $this->handler->createInviteLink(
            ownerId: 'owner-1',
            serverId: 'server-1',
            libraryId: null,
            permission: 'read',
            maxUses: 5,
        );

        self::assertNull($link->libraryId);
        self::assertTrue($link->isForAllLibraries());
        self::assertSame(5, $link->maxUses);
    }

    public function testListForOwnerReturnsEmptyArray(): void
    {
        $this->db->method('query')->willReturn([]);

        $result = $this->handler->listForOwner('owner-1');

        self::assertCount(0, $result);
    }

    public function testListForOwnerReturnsLinks(): void
    {
        $rows = [
            [
                'id' => 'link-1',
                'owner_user_id' => 'owner-1',
                'server_id' => 'server-1',
                'library_id' => 'lib-1',
                'permission' => 'read',
                'max_uses' => 5,
                'use_count' => 2,
                'expires_at' => null,
                'created_at' => time(),
                'token_hash' => 'abc123',
            ],
        ];

        $this->db->method('query')->willReturn($rows);

        $result = $this->handler->listForOwner('owner-1');

        self::assertCount(1, $result);
        self::assertSame('link-1', $result[0]->id);
    }

    public function testRevokeInviteLinkSuccess(): void
    {
        $row = [
            'id' => 'link-1',
            'owner_user_id' => 'owner-1',
            'server_id' => 'server-1',
            'library_id' => 'lib-1',
            'permission' => 'read',
            'max_uses' => 5,
            'use_count' => 2,
            'expires_at' => null,
            'created_at' => time(),
            'token_hash' => 'abc123',
        ];

        $this->db->method('query')->willReturnCallback(function (string $sql) use ($row) {
            if (str_contains($sql, 'SELECT * FROM invite_links')) {
                return [$row];
            }
            return [];
        });

        $this->handler->revokeInviteLink('owner-1', 'link-1');
        self::assertTrue(true);
    }

    public function testRevokeInviteLinkNotFoundThrows(): void
    {
        $this->db->method('query')->willReturn([]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(404);

        $this->handler->revokeInviteLink('owner-1', 'nonexistent');
    }

    public function testRevokeInviteLinkNotOwnerThrows(): void
    {
        $row = [
            'id' => 'link-1',
            'owner_user_id' => 'other-owner',
            'server_id' => 'server-1',
            'library_id' => 'lib-1',
            'permission' => 'read',
            'max_uses' => 5,
            'use_count' => 2,
            'expires_at' => null,
            'created_at' => time(),
            'token_hash' => 'abc123',
        ];

        $this->db->method('query')->willReturnCallback(function (string $sql) use ($row) {
            if (str_contains($sql, 'SELECT * FROM invite_links')) {
                return [$row];
            }
            return [];
        });

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(403);

        $this->handler->revokeInviteLink('owner-1', 'link-1');
    }

    // --- B5: atomic single-use invite redemption ------------------------------

    /**
     * Build a handler wired to a {@see SingleUseInviteConnection} double and a
     * real {@see JwtHandler} (so the JWT auth gate is exercised for real), with
     * the sharing handler stubbed to echo a share.
     */
    private function handlerWith(SingleUseInviteConnection $db): InviteLinkHandler
    {
        $jwt = new JwtHandler('test-secret-key-that-is-at-least-32-bytes-long');

        $sharing = $this->createMock(LibrarySharingHandler::class);
        $sharing->method('shareLibrary')->willReturnCallback(
            static fn (
                string $ownerId,
                string $collaboratorEmail,
                string $serverId,
                string $libraryId,
                string $libraryName,
                string $permission,
            ): LibraryShare => new LibraryShare(
                id: 'share-1',
                ownerUserId: $ownerId,
                collaboratorUserId: 'collab-1',
                serverId: $serverId,
                libraryId: $libraryId,
                libraryName: $libraryName,
                permissionLevel: $permission,
                createdAt: time(),
            ),
        );

        return new InviteLinkHandler(
            $db,
            $jwt,
            $sharing,
            $this->createMock(StructuredLogger::class),
            'http://localhost:8800',
        );
    }

    /** A valid JWT the redeem auth-gate accepts. */
    private function validInviteJwt(string $ownerId = 'owner-1'): string
    {
        return (new JwtHandler('test-secret-key-that-is-at-least-32-bytes-long'))
            ->createAccessToken($ownerId, ['invite_link'], 'server-1');
    }

    /**
     * Two concurrent redemptions of a max_uses=1 invite: EXACTLY ONE succeeds,
     * the other is rejected as exhausted. The {@see SingleUseInviteConnection}
     * double replays the atomic conditional UPDATE (`use_count < max_uses`), so
     * the second redemption's UPDATE affects zero rows.
     */
    public function testConcurrentRedemptionOfSingleUseInviteOnlyOneSucceeds(): void
    {
        $db = new SingleUseInviteConnection(maxUses: 1, useCount: 0);
        $handler = $this->handlerWith($db);
        $token = $this->validInviteJwt();

        $successes = 0;
        $exhausted = 0;
        // Interleaved attempts against the SAME shared invite row, modelling two
        // requests racing through the conditional UPDATE.
        for ($i = 0; $i < 2; $i++) {
            try {
                $share = $handler->redeemInviteLink($token, 'redeemer-' . $i);
                self::assertInstanceOf(LibraryShare::class, $share);
                $successes++;
            } catch (InvalidArgumentException $e) {
                self::assertSame(410, $e->getCode());
                self::assertSame('Invite link has been exhausted', $e->getMessage());
                $exhausted++;
            }
        }

        self::assertSame(1, $successes, 'exactly one redemption must succeed');
        self::assertSame(1, $exhausted, 'the losing redemption must be rejected');
        self::assertSame(1, $db->useCount(), 'use_count must never exceed max_uses');
    }

    /**
     * The redemption path issues EXACTLY ONE conditional UPDATE carrying the
     * `use_count < max_uses` guard (the atomic single-use claim) — never the old
     * unconditional `use_count = use_count + 1` write.
     */
    public function testRedemptionIssuesSingleConditionalUpdate(): void
    {
        $db = new SingleUseInviteConnection(maxUses: 1, useCount: 0);
        $handler = $this->handlerWith($db);

        $handler->redeemInviteLink($this->validInviteJwt(), 'redeemer-1');

        $updates = array_values(array_filter(
            $db->calls,
            static fn (array $call): bool => str_contains($call['sql'], 'UPDATE invite_links'),
        ));

        self::assertCount(1, $updates, 'exactly one UPDATE statement issued');
        self::assertStringContainsString('use_count < max_uses', $updates[0]['sql']);
        self::assertStringContainsString('use_count = use_count + 1', $updates[0]['sql']);
        // Colon-free named placeholders per the workerman binding contract.
        self::assertArrayHasKey('token_hash', $updates[0]['params']);
        self::assertArrayHasKey('now', $updates[0]['params']);
        self::assertArrayNotHasKey(':token_hash', $updates[0]['params']);
    }

    /** Zero affected rows ⇒ rejection with the exhausted 410 error. */
    public function testRedemptionRejectedWhenAlreadyExhausted(): void
    {
        // useCount already at max_uses: the conditional UPDATE affects no rows.
        $db = new SingleUseInviteConnection(maxUses: 1, useCount: 1);
        $handler = $this->handlerWith($db);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(410);
        $this->expectExceptionMessage('Invite link has been exhausted');

        $handler->redeemInviteLink($this->validInviteJwt(), 'redeemer-1');
    }

    /** A multi-use invite still allows up to max_uses distinct redemptions. */
    public function testMultiUseInviteAllowsUpToMaxUses(): void
    {
        $db = new SingleUseInviteConnection(maxUses: 3, useCount: 0);
        $handler = $this->handlerWith($db);
        $token = $this->validInviteJwt();

        $successes = 0;
        $exhausted = 0;
        for ($i = 0; $i < 5; $i++) {
            try {
                $handler->redeemInviteLink($token, 'redeemer-' . $i);
                $successes++;
            } catch (InvalidArgumentException $e) {
                self::assertSame(410, $e->getCode());
                $exhausted++;
            }
        }

        self::assertSame(3, $successes, 'max_uses redemptions must be allowed');
        self::assertSame(2, $exhausted, 'redemptions beyond max_uses must be rejected');
        self::assertSame(3, $db->useCount());
    }

    /** Expired invites are still rejected with the existing 410 error. */
    public function testRedemptionRejectedWhenExpired(): void
    {
        $db = new SingleUseInviteConnection(
            maxUses: 1,
            useCount: 0,
            expiresAt: time() - 60,
        );
        $handler = $this->handlerWith($db);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(410);
        $this->expectExceptionMessage('Invite link has expired');

        $handler->redeemInviteLink($this->validInviteJwt(), 'redeemer-1');
    }

    /** Unknown token hash is still rejected with the existing 404 error. */
    public function testRedemptionRejectedWhenNotFound(): void
    {
        $db = new SingleUseInviteConnection(exists: false);
        $handler = $this->handlerWith($db);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(404);

        $handler->redeemInviteLink($this->validInviteJwt(), 'redeemer-1');
    }

    /** The owner cannot redeem their own invite (existing 400 error). */
    public function testRedemptionRejectedWhenRedeemerIsOwner(): void
    {
        $db = new SingleUseInviteConnection(maxUses: 1, useCount: 0, ownerId: 'owner-1');
        $handler = $this->handlerWith($db);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(400);
        $this->expectExceptionMessage('Cannot redeem your own invite link');

        // redeemer == the invite row's owner_user_id
        $handler->redeemInviteLink($this->validInviteJwt(), 'owner-1');
    }

    /** An invalid JWT is rejected before any DB work (existing 400 error). */
    public function testRedemptionRejectedWhenTokenInvalid(): void
    {
        $db = new SingleUseInviteConnection();
        $handler = $this->handlerWith($db);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(400);

        $handler->redeemInviteLink('not-a-valid-jwt', 'redeemer-1');

        self::assertSame([], $db->calls);
    }
}
