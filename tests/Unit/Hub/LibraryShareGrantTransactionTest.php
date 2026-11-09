<?php

/**
 * Phlix hub component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use InvalidArgumentException;
use Phlix\Hub\Auth\UserRepository;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Hub\LibrarySharingHandler;
use PDOException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

use function array_slice;
use function file_get_contents;
use function strpos;
use function str_contains;

/**
 * SH-052 (review hub__dba-code): shareLibrary's check-then-write window must
 * run INSIDE one transaction behind the `servers` PK-row FOR UPDATE anchor —
 * the ClaimRequestHandler serialisation idiom, now spanning the share tuple
 * read, the collaborator-cap count, and the write.
 *
 * The pre-existing LibrarySharingHandlerTest/LibaryShareCapTest fakes
 * classify SQL by keyword and stub beginTrans/commitTrans silently, so they
 * ABSORBED the protocol change without noticing — exactly the toothless-pin
 * class this file exists to close: a recording fake that tracks the
 * transaction STATE (writes outside an open transaction fail the test) and
 * pins the full statement sequence per branch. Red pre-cure: the old code
 * never opened a transaction at all, so every BEGIN/ANCHOR/COMMIT pin below
 * failed against it.
 *
 * @package Phlix\Hub\Tests\Unit\Hub
 */
final class LibraryShareGrantTransactionTest extends TestCase
{
    /** @var list<string> */
    private array $events = [];

    private bool $inTxn = false;

    /** @param array<string,mixed>|null $tupleRow @param (callable():int)|null $resolver */
    private function handler(?array $tupleRow, ?callable $resolver, ?Connection $db = null): LibrarySharingHandler
    {
        $db ??= $this->recordingDb($tupleRow);

        $users = $this->createMock(UserRepository::class);
        $users->method('findByEmail')->willReturn(['id' => 'collab-1', 'email' => 'friend@example.com']);

        return new LibrarySharingHandler(
            $db,
            $users,
            $this->createMock(StructuredLogger::class),
            $resolver,
        );
    }

    /**
     * The txn-state law, armed on any fake this file builds: BEGIN only from
     * closed state, COMMIT/ROLLBACK only from open state. Every fake below
     * routes through here so a handler cannot dodge the bookkeeping.
     */
    private function armTxnGuards(Connection&MockObject $db): void
    {
        $db->method('beginTrans')->willReturnCallback(function () {
            if ($this->inTxn) {
                TestCase::fail('double beginTrans');
            }
            $this->inTxn = true;
            $this->events[] = 'BEGIN';
            return true;
        });
        $db->method('commitTrans')->willReturnCallback(function () {
            if (!$this->inTxn) {
                TestCase::fail('commit without an open transaction');
            }
            $this->inTxn = false;
            $this->events[] = 'COMMIT';
            return true;
        });
        $db->method('rollBackTrans')->willReturnCallback(function () {
            if (!$this->inTxn) {
                TestCase::fail('rollback without an open transaction');
            }
            $this->inTxn = false;
            $this->events[] = 'ROLLBACK';
            return true;
        });
    }

    /**
     * @param array<string,mixed>|null $tupleRow
     */
    private function recordingDb(?array $tupleRow): Connection
    {
        $db = $this->createMock(Connection::class);
        $this->armTxnGuards($db);

        $db->method('query')->willReturnCallback(
            /** @param array<string,mixed>|null $params */
            function (string $sql, ?array $params = null) use ($tupleRow) {
                $event = $this->classify($sql);

                // The law itself: no branch of shareLibrary may write outside
                // the opened transaction.
                if (($event === 'INSERT' || $event === 'REACT') && !$this->inTxn) {
                    TestCase::fail('share grant write (' . $event . ') ran OUTSIDE the transaction');
                }

                $this->events[] = $event;

                return match ($event) {
                    'OWNCHECK' => [['id' => 'srv-1']],
                    'ANCHOR' => [['id' => 'srv-1']],
                    'TUPLE' => $tupleRow === null ? [] : [$tupleRow],
                    'COUNT' => [['total' => 0, 'present' => 0]],
                    'INSERT', 'REACT' => 1,
                    default => $this->fail('unexpected SQL in fake: ' . $sql),
                };
            },
        );

        return $db;
    }

    /**
     * Recording db whose INSERT answers like MySQL's UNIQUE index: a 1062
     * PDOException (the pathological cross-server tuple case no anchor can
     * span — the UNIQUE keys the bare owner/collaborator/library triple).
     */
    private function duplicateKeyDb(): Connection
    {
        $db = $this->createMock(Connection::class);
        $this->armTxnGuards($db);
        $db->method('query')->willReturnCallback(
            function (string $sql) {
                $event = $this->classify($sql);
                $this->events[] = $event;
                if ($event === 'INSERT') {
                    throw new PDOException(
                        'SQLSTATE[23000]: Integrity constraint violation: 1062 '
                        . "Duplicate entry for key 'library_shares.uk_library_share'",
                    );
                }
                return match ($event) {
                    'OWNCHECK', 'ANCHOR' => [['id' => 'srv-1']],
                    'TUPLE' => [],
                    default => $this->fail('unexpected: ' . $sql),
                };
            },
        );

        return $db;
    }

    private function classify(string $sql): string
    {
        if (str_contains($sql, 'FOR UPDATE') && str_contains($sql, 'FROM servers')) {
            return 'ANCHOR';
        }
        if (str_contains($sql, 'FROM servers')) {
            return 'OWNCHECK';
        }
        if (str_contains($sql, 'INSERT INTO library_shares')) {
            return 'INSERT';
        }
        if (str_contains($sql, 'UPDATE library_shares')) {
            return 'REACT';
        }
        if (str_contains($sql, 'COUNT(DISTINCT collaborator_user_id)')) {
            return 'COUNT';
        }
        if (str_contains($sql, 'FROM library_shares')) {
            return 'TUPLE';
        }

        return 'OTHER';
    }

    /** @return array<string,mixed> */
    private function liveRow(): array
    {
        return [
            'id' => 'share-live', 'owner_user_id' => 'owner-1', 'collaborator_user_id' => 'collab-1',
            'server_id' => 'srv-1', 'library_id' => 'lib-1', 'library_name' => 'Movies',
            'permission_level' => 'read', 'granted_by' => 'owner-1', 'created_at' => 1,
            'expires_at' => null, 'revoked_at' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function revokedRow(): array
    {
        // Spread, not `+`: the union operator keeps the left-hand null
        // (revoked_at) and would silently hand back a LIVE row.
        return [...$this->liveRow(), 'id' => 'share-dead', 'revoked_at' => 2];
    }

    public function testFreshGrantRunsBehindTheAnchorInOneTransaction(): void
    {
        $handler = $this->handler(null, null);

        $share = $handler->shareLibrary('owner-1', 'friend@example.com', 'srv-1', 'lib-1', 'Movies');

        self::assertSame('collab-1', $share->collaboratorUserId);
        self::assertSame(
            ['OWNCHECK', 'BEGIN', 'ANCHOR', 'TUPLE', 'INSERT', 'COMMIT'],
            $this->events,
            'fresh path: ownership pre-lock, then ONE transaction anchoring the server row',
        );
        self::assertFalse($this->inTxn, 'the transaction must be closed on success');
    }

    public function testCapCountSitsInsideTheWindowWhenTheCapIsLive(): void
    {
        $handler = $this->handler(null, static fn (): int => 5);

        $handler->shareLibrary('owner-1', 'friend@example.com', 'srv-1', 'lib-1', 'Movies');

        self::assertSame(
            ['OWNCHECK', 'BEGIN', 'ANCHOR', 'TUPLE', 'COUNT', 'INSERT', 'COMMIT'],
            $this->events,
            'the cap count must ride the SAME locked window as the write it protects',
        );
    }

    public function testAnchorIsTakenEvenWithTheCapDisabled(): void
    {
        // cap 0 (unlimited) removes the COUNT, never the ANCHOR: the tuple
        // check-then-insert race is guarded independently of the quota.
        $handler = $this->handler(null, static fn (): int => 0);

        $handler->shareLibrary('owner-1', 'friend@example.com', 'srv-1', 'lib-1', 'Movies');

        self::assertContains('ANCHOR', $this->events);
        self::assertNotContains('COUNT', $this->events);
    }

    public function testReactivatePathKeepsTheSameWindowShape(): void
    {
        $handler = $this->handler($this->revokedRow(), static fn (): int => 5);

        $share = $handler->shareLibrary('owner-1', 'friend@example.com', 'srv-1', 'lib-1', 'Movies');

        self::assertSame('share-dead', $share->id);
        self::assertSame(
            ['OWNCHECK', 'BEGIN', 'ANCHOR', 'TUPLE', 'COUNT', 'REACT', 'COMMIT'],
            $this->events,
        );
    }

    public function testLiveTupleRejectionRollsBackWithZeroWrites(): void
    {
        $handler = $this->handler($this->liveRow(), null);

        try {
            $handler->shareLibrary('owner-1', 'friend@example.com', 'srv-1', 'lib-1', 'Movies');
            TestCase::fail('expected 409');
        } catch (InvalidArgumentException $e) {
            self::assertSame('Share already exists', $e->getMessage());
            self::assertSame(409, $e->getCode());
        }

        self::assertSame(['OWNCHECK', 'BEGIN', 'ANCHOR', 'TUPLE', 'ROLLBACK'], $this->events);
    }

    public function testCapRejectionRollsBackWithZeroWrites(): void
    {
        // Cap 3 with 3 active distinct collaborators (total=3, the newcomer
        // not among them): projected 4 > 3 → refusal, and the transaction must
        // close with a ROLLBACK — the ANCHOR already taken has to be released.
        $db = $this->createMock(Connection::class);
        $this->armTxnGuards($db);
        $db->method('query')->willReturnCallback(
            function (string $sql) {
                $event = $this->classify($sql);
                $this->events[] = $event;
                return match ($event) {
                    'OWNCHECK', 'ANCHOR' => [['id' => 'srv-1']],
                    'TUPLE' => [],
                    'COUNT' => [['total' => 3, 'present' => 0]],
                    default => $this->fail('no write may run past the cap refusal: ' . $sql),
                };
            },
        );

        $users = $this->createMock(UserRepository::class);
        $users->method('findByEmail')->willReturn(['id' => 'collab-1']);
        $handler = new LibrarySharingHandler(
            $db,
            $users,
            $this->createMock(StructuredLogger::class),
            static fn (): int => 3,
        );

        try {
            $handler->shareLibrary('owner-1', 'friend@example.com', 'srv-1', 'lib-1', 'Movies');
            TestCase::fail('expected 409');
        } catch (InvalidArgumentException $e) {
            self::assertSame('SERVER_SHARE_CAP_REACHED', $e->getMessage());
            self::assertSame(409, $e->getCode());
        }

        self::assertSame(['OWNCHECK', 'BEGIN', 'ANCHOR', 'TUPLE', 'COUNT', 'ROLLBACK'], $this->events);
    }

    public function testCrossServerTupleCollisionMapsToTheConflictAnswerNotA500(): void
    {
        $db = $this->duplicateKeyDb();
        $handler = $this->handler(null, null, $db);

        try {
            $handler->shareLibrary('owner-1', 'friend@example.com', 'srv-1', 'lib-1', 'Movies');
            TestCase::fail('expected the mapped 409');
        } catch (InvalidArgumentException $e) {
            self::assertSame(
                'Share already exists',
                $e->getMessage(),
                'the 1062 must answer byte-identical to the live-tuple 409',
            );
            self::assertSame(409, $e->getCode());
        }

        self::assertSame(['OWNCHECK', 'BEGIN', 'ANCHOR', 'TUPLE', 'INSERT', 'ROLLBACK'], $this->events);
    }

    public function testNonDuplicateSqlErrorsRethrowUntouched(): void
    {
        $db = $this->createMock(Connection::class);
        $this->armTxnGuards($db);
        $db->method('query')->willReturnCallback(
            function (string $sql) {
                $event = $this->classify($sql);
                $this->events[] = $event;
                if ($event === 'INSERT') {
                    throw new PDOException('SQLSTATE[42S02]: Base table or view not found: 1146 Table gone');
                }
                return match ($event) {
                    'OWNCHECK', 'ANCHOR' => [['id' => 'srv-1']],
                    'TUPLE' => [],
                    default => $this->fail('unexpected: ' . $sql),
                };
            },
        );

        $users = $this->createMock(UserRepository::class);
        $users->method('findByEmail')->willReturn(['id' => 'collab-1']);
        $handler = new LibrarySharingHandler($db, $users, $this->createMock(StructuredLogger::class), null);

        $this->expectException(PDOException::class);
        $handler->shareLibrary('owner-1', 'friend@example.com', 'srv-1', 'lib-1', 'Movies');
    }

    public function testProviderWiresTheHandlerOnTheDedicatedTxnHandle(): void
    {
        // Static source-pin (the repo tripwire idiom): the CLAIM precedent
        // documents that explicit-transaction handlers must NOT ride the
        // 'mysql' pool — a cid<0 reaper sharing that socket trips ERROR 2014
        // mid-transaction. The binding lives only in the provider text, so the
        // pin reads the provider text.
        $provider = file_get_contents(
            __DIR__ . '/../../../src/Common/Container/Providers/HubServicesProvider.php',
        );
        self::assertIsString($provider);

        $start = strpos($provider, "LibrarySharingHandler::class => factory");
        self::assertNotFalse($start, 'the provider binding must exist');
        $slice = array_slice(
            explode("\n", substr($provider, $start)),
            0,
            24,
        );
        $block = implode("\n", $slice);

        self::assertStringContainsString("ConnectionPool::getConnection('txn')", $block, $slice[0]);
        self::assertStringNotContainsString("parameter('db', get(Connection::class))", $block);
    }
}
