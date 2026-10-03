<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use InvalidArgumentException;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Hub\LibrarySharingHandler;
use Phlix\Hub\Hub\LibraryShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * W5 enforcement tests for `server.max_users_per_server` at the single share
 * choke ({@see LibrarySharingHandler::shareLibrary()} — the path BOTH direct
 * email shares and invite redemption converge on).
 *
 * Pins: defaults preservation (no resolver / cap 0 => no counting SELECT at
 * all), returning-collaborator reactivation exemption, DISTINCT projection
 * math, and the exact SERVER_SHARE_CAP_REACHED / 409 throw the controllers
 * map to `quota.exceeded`.
 *
 * @package Phlix\Hub\Tests\Unit\Hub
 */
final class LibraryShareCapTest extends TestCase
{
    private Connection&MockObject $db;

    private UserRepository&MockObject $users;

    /** @var list<string> */
    private array $statements = [];

    /** @var callable(string, array<string, mixed>): mixed */
    private $responder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->statements = [];
        $this->responder  = static fn (string $sql, array $params): array => [];

        $this->db = $this->createMock(Connection::class);
        $this->db->method('query')->willReturnCallback(function (string $sql, $params = null) {
            $this->statements[] = $sql;

            return ($this->responder)($sql, (array) $params);
        });

        $this->users            = $this->createMock(UserRepository::class);
        $this->users->method('findByEmail')->willReturn(['id' => 'collab-1', 'email' => 'friend@example.com']);
    }

    private function handler(?callable $maxUsersResolver): LibrarySharingHandler
    {
        return new LibrarySharingHandler(
            $this->db,
            $this->users,
            $this->createMock(StructuredLogger::class),
            $maxUsersResolver,
        );
    }

    /**
     * Ownership OK, no existing tuple, cap query answers total=$total /
     * present=$present.
     */
    private function seedFlow(int $total, int $present): void
    {
        $this->responder = static function (string $sql, array $params) use ($total, $present) {
            if (str_contains($sql, 'SELECT id FROM servers')) {
                return [['id' => 'server-1']];
            }
            if (str_contains($sql, 'SELECT * FROM library_shares')) {
                return [];
            }
            if (str_contains($sql, 'COUNT(DISTINCT collaborator_user_id)')) {
                return [['total' => $total, 'present' => $present]];
            }

            return [];
        };
    }

    /** @return list<string> Statements that are the W5 share-cap COUNT query. */
    private function capQueries(): array
    {
        return array_values(array_filter(
            $this->statements,
            static fn (string $sql): bool => str_contains($sql, 'COUNT(DISTINCT collaborator_user_id)'),
        ));
    }

    private function share(?callable $maxUsersResolver): LibraryShare
    {
        return $this->handler($maxUsersResolver)->shareLibrary(
            ownerId: 'owner-1',
            collaboratorEmail: 'friend@example.com',
            serverId: 'server-1',
            libraryId: 'lib-1',
            libraryName: 'My Movies',
            permission: LibraryShare::PERMISSION_READ,
        );
    }

    public function testNoResolverIssuesNoCountQuery(): void
    {
        $this->seedFlow(0, 0);

        $share = $this->share(null);

        self::assertSame([], $this->capQueries(), 'pre-W5 statement set preserved at default');
        self::assertSame('collab-1', $share->collaboratorUserId);
    }

    public function testUnlimitedCapZeroShortCircuitsBeforeAnyQuery(): void
    {
        $this->seedFlow(999, 0);

        $this->share(static fn (): int => 0);

        self::assertSame([], $this->capQueries());
    }

    public function testPHPTimeNotMysqlNowGovernsExpiryPredicate(): void
    {
        // TZ-skew law: the :now bound must be the PHP clock, never NOW().
        $this->seedFlow(1, 0);
        $captured = null;
        $this->responder = function (string $sql, array $params) use (&$captured) {
            if (str_contains($sql, 'COUNT(DISTINCT collaborator_user_id)')) {
                $captured = $params['now'] ?? null;

                return [['total' => 1, 'present' => 0]];
            }
            if (str_contains($sql, 'SELECT id FROM servers')) {
                return [['id' => 'server-1']];
            }

            return [];
        };

        $before = time();
        $this->share(static fn (): int => 5);


        self::assertIsInt($captured);
        self::assertGreaterThanOrEqual($before, $captured);
        self::assertLessThanOrEqual(time(), $captured);
        foreach ($this->statements as $sql) {
            if (str_contains($sql, 'COUNT(DISTINCT collaborator_user_id)')) {
                self::assertStringNotContainsString('NOW()', $sql);
            }
        }
    }

    public function testCapReachedRefusesNewCollaborator(): void
    {
        // total=2 distinct actives, newcomer not among them -> projected 3 > 2.
        $this->seedFlow(2, 0);

        try {
            $this->share(static fn (): int => 2);
            self::fail('expected SERVER_SHARE_CAP_REACHED');
        } catch (InvalidArgumentException $e) {
            self::assertSame('SERVER_SHARE_CAP_REACHED', $e->getMessage());
            self::assertSame(409, $e->getCode());
        }

        self::assertCount(1, $this->capQueries());
        foreach ($this->statements as $sql) {
            self::assertStringNotContainsString('INSERT INTO library_shares', $sql);
        }
    }

    public function testAlreadyCountedCollaboratorDoesNotDoubleProject(): void
    {
        // total=2 includes THIS collaborator (active on another library):
        // projected stays 2, cap 2 -> allowed.
        $this->seedFlow(2, 1);

        $share = $this->share(static fn (): int => 2);

        self::assertSame('collab-1', $share->collaboratorUserId);
    }

    public function testBelowCapInserts(): void
    {
        $this->seedFlow(1, 0);

        $share = $this->share(static fn (): int => 3);

        self::assertSame(LibraryShare::PERMISSION_READ, $share->permissionLevel);
        $inserts = array_filter(
            $this->statements,
            static fn (string $sql): bool => str_contains($sql, 'INSERT INTO library_shares'),
        );
        self::assertCount(1, $inserts);
    }

    public function testReactivationOfReturnedCollaboratorIsExempt(): void
    {
        // Existing REVOKED tuple -> reactivate branch runs BEFORE the cap:
        // a returning collaborator never re-consumes quota, and the count
        // query is never issued.
        $revokedRow = [
            'id'                     => 'old-share',
            'owner_user_id'          => 'owner-1',
            'collaborator_user_id'   => 'collab-1',
            'server_id'              => 'server-1',
            'library_id'             => 'lib-1',
            'library_name'           => 'My Movies',
            'permission_level'       => 'read',
            'granted_by'             => 'owner-1',
            'created_at'             => time() - 86400,
            'expires_at'             => null,
            'revoked_at'             => time() - 3600,
        ];
        $this->responder = static function (string $sql) use ($revokedRow) {
            if (str_contains($sql, 'SELECT id FROM servers')) {
                return [['id' => 'server-1']];
            }
            if (str_contains($sql, 'SELECT * FROM library_shares')) {
                return [$revokedRow];
            }
            if (str_contains($sql, 'UPDATE library_shares')) {
                return 1;
            }

            return [];
        };

        $handler = new LibrarySharingHandler(
            $this->db,
            $this->users,
            $this->createMock(StructuredLogger::class),
            static fn (): int => 1, // tiny cap: irrelevant, exemption must hold
        );

        $share = $handler->shareLibrary(
            ownerId: 'owner-1',
            collaboratorEmail: 'friend@example.com',
            serverId: 'server-1',
            libraryId: 'lib-1',
            libraryName: 'My Movies',
        );

        self::assertSame('old-share', $share->id);
        self::assertSame([], $this->capQueries(), 'reactivation must bypass the quota read entirely');
    }
}
