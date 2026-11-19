<?php

/**
 * Phlix hub component: tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Common\Database;

use PDOException;
use Phlix\Hub\Common\Database\KeySweeper;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Workerman\MySQL\Connection;
use PHPUnit\Framework\Attributes\Group;

/**
 * The two-phase keyed-sweep kernel behind review hub Minor 42.
 *
 * Protocol under test (promoted verbatim from
 * {@see \Phlix\Hub\ServerReaper::sweepHeartbeats()}): non-locking
 * `SELECT id … ORDER BY id LIMIT n` then `DELETE … WHERE id IN (:id_0, …)`;
 * a deadlocked attempt re-runs the pair (the victim committed nothing), any
 * other PDO failure propagates, and identifiers fail fast at the constructor.
 *
 */
#[Group('unit')]
final class KeySweeperTest extends TestCase
{
    public function testSweepSelectsThenDeletesByPrimaryKeyInOrder(): void
    {
        /** @var list<string> $sqls */
        $sqls = [];
        /** @var list<mixed> $paramSets */
        $paramSets = [];

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            /** @param mixed $params */
            function (string $sql, $params = null) use (&$sqls, &$paramSets): mixed {
                $sqls[] = $sql;
                $paramSets[] = $params;
                if (str_starts_with($sql, 'SELECT')) {
                    return [['id' => 'aaa'], ['id' => 'bbb']];
                }
                return 2;
            },
        );

        $sweeper = new KeySweeper($db, 'mcp_tokens', 1000);

        self::assertSame(2, $sweeper->sweep('revoked_at IS NOT NULL'));
        self::assertSame([
            'SELECT id FROM mcp_tokens WHERE revoked_at IS NOT NULL ORDER BY id LIMIT 1000',
            'DELETE FROM mcp_tokens WHERE id IN (:id_0, :id_1)',
        ], $sqls);
        self::assertSame(
            [['id_0' => 'aaa', 'id_1' => 'bbb']],
            [$paramSets[1]],
            'phase 2 must bind exactly the collected PKs, colon-named',
        );
    }

    public function testWhereParamsAreForwardedToPhaseOneOnly(): void
    {
        /** @var list<mixed> $paramSets */
        $paramSets = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            /** @param mixed $params */
            function (string $sql, $params = null) use (&$paramSets): mixed {
                $paramSets[] = $params;
                return str_starts_with($sql, 'SELECT') ? [['id' => 'x']] : 1;
            },
        );

        $sweeper = new KeySweeper($db, 'servers', 50);
        $sweeper->sweep('retention_days < :days', ['days' => 7]);

        self::assertSame([['days' => 7], ['id_0' => 'x']], $paramSets);
    }

    public function testEmptySelectionShortCircuitsWithoutDelete(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('query')->willReturn([]);

        $sweeper = new KeySweeper($db, 'client_relay_tokens', 100);

        self::assertSame(0, $sweeper->sweep('revoked_at IS NOT NULL'));
    }

    public function testNonStringIdRowsAreFilteredOut(): void
    {
        // Driver variance (int PKs, nulls) must never reach the DELETE params.
        $deleteSqls = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            /** @param mixed $params */
            function (string $sql, $params = null) use (&$deleteSqls): mixed {
                if (str_starts_with($sql, 'SELECT')) {
                    return [['id' => 'good'], ['id' => 12345], ['id' => ''], ['id' => null], []];
                }
                $deleteSqls[] = $sql;
                return 1;
            },
        );

        $sweeper = new KeySweeper($db, 'servers', 10);

        self::assertSame(1, $sweeper->sweep('stale = 1'));
        self::assertSame(['DELETE FROM servers WHERE id IN (:id_0)'], $deleteSqls);
    }

    public function testNumericStringAffectedRowsIsCastToInt(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static fn (string $sql, $params = null): mixed => str_starts_with($sql, 'SELECT')
                ? [['id' => 'a'], ['id' => 'b']]
                : '2'
        );

        $sweeper = new KeySweeper($db, 'servers', 10);

        self::assertSame(2, $sweeper->sweep('x = 1'));
    }

    public function testDeadlockedAttemptRetriesTheWholePair(): void
    {
        $calls = 0;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql) use (&$calls): mixed {
                $calls++;
                if (str_starts_with($sql, 'SELECT')) {
                    return [['id' => 'a']];
                }
                // First DELETE deadlocks (rollback — nothing committed);
                // second DELETE succeeds.
                return $calls === 2 ? throw new PDOException('Deadlock found when trying to get lock') : 1;
            },
        );

        $sweeper = new KeySweeper($db, 'servers', 10);

        self::assertSame(1, $sweeper->sweep('x = 1'));
        self::assertSame(4, $calls, 'retry must re-run SELECT then DELETE');
    }

    public function testNonDeadlockPdoFailurePropagatesImmediately(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('query')->willThrowException(
            new PDOException("SQLSTATE[42000]: Syntax error or access violation")
        );

        $sweeper = new KeySweeper($db, 'servers', 10);

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('Syntax error');
        $sweeper->sweep('x = 1');
    }

    public function testLocalizedDeadlockRetriesOnWireIdentity(): void
    {
        // D-16 red-first conviction: a Turkish-localized wire deadlock —
        // errorInfo ['40001', 1213, ...] with NO English text and no digits
        // in the message. The old stripos belt saw no 'deadlock' and aborted
        // the sweep on the first attempt; the wire identity retries the pair.
        $deadlock = new PDOException('Kilit çarpışması, işlemi yeniden başlatın', 40001);
        $deadlock->errorInfo = ['40001', 1213, 'Kilit çarpışması, işlemi yeniden başlatın'];

        $calls = 0;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql) use (&$calls, $deadlock): mixed {
                $calls++;
                if (str_starts_with($sql, 'SELECT')) {
                    return [['id' => 'a']];
                }
                return $calls === 2 ? throw $deadlock : 1;
            },
        );

        $sweeper = new KeySweeper($db, 'servers', 10);

        self::assertSame(1, $sweeper->sweep('x = 1'));
        self::assertSame(4, $calls, 'wire deadlock must re-run SELECT then DELETE');
    }

    public function testWireCodeContradictingDeadlockTextPropagatesWithoutRetry(): void
    {
        // D-16 pin in the opposite direction: the English word 'Deadlock' in
        // the message CANNOT outvote a wire code that says otherwise (1062 —
        // a duplicate-key failure, which no amount of retrying will fix).
        // The old belt retried three times on the text alone (calls 6); the
        // wire-first read propagates after the first DELETE (calls 2).
        $contradiction = new PDOException(
            'SQL:DELETE FROM servers WHERE id IN (:id_0) '
            . 'SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry — '
            . 'reported as Deadlock found by a confused proxy',
            23,
        );
        $contradiction->errorInfo = ['23000', 1062, "Duplicate entry 'x' for key 'PRIMARY'"];

        $calls = 0;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql) use (&$calls, $contradiction): mixed {
                $calls++;
                if (str_starts_with($sql, 'SELECT')) {
                    return [['id' => 'a']];
                }
                throw $contradiction;
            },
        );

        $sweeper = new KeySweeper($db, 'servers', 10);

        try {
            $this->expectException(PDOException::class);
            $sweeper->sweep('x = 1');
        } finally {
            self::assertSame(2, $calls, 'no retry may ride a contradicting wire code');
        }
    }

    public function testPersistentDeadlockIsSurfacedAfterThreeAttempts(): void
    {
        $calls = 0;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql) use (&$calls): mixed {
                $calls++;
                if (str_starts_with($sql, 'SELECT')) {
                    return [['id' => 'a']];
                }
                throw new PDOException('Deadlock found when trying to get lock');
            },
        );

        $sweeper = new KeySweeper($db, 'servers', 10);

        $this->expectException(PDOException::class);
        try {
            $sweeper->sweep('x = 1');
        } finally {
            // 3 attempts × (SELECT + DELETE).
            self::assertSame(6, $calls);
        }
    }

    public function testConstructorRejectsNonIdentifierTable(): void
    {
        $db = $this->createMock(Connection::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('table must match');
        new KeySweeper($db, 'servers; DROP TABLE users', 10);
    }

    public function testConstructorRejectsBatchSizeBelowOne(): void
    {
        $db = $this->createMock(Connection::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('batchSize');
        new KeySweeper($db, 'servers', 0);
    }
}
