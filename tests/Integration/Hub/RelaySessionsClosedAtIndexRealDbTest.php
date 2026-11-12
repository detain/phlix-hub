<?php

/**
 * Phlix hub component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Integration\Hub;

use Phlix\Hub\Common\Database\MigrationRunner;
use Phlix\Hub\Tests\Support\RealDatabaseTestCase;

/**
 * HUB-S10 (HUB-1) against a real InnoDB: migration 057's leading `closed_at`
 * index on `relay_sessions`, and both arms of its replay guard.
 *
 *  - the full chain leaves exactly ONE index whose sole column is closed_at,
 *    next to (not instead of) 004's (server_id, closed_at) key;
 *  - the server-agnostic open-set predicates (StatsRepository's COUNT, the
 *    ServerReaper DISTINCT server_id subquery) list the new index in
 *    EXPLAIN's `possible_keys` — the structural fact the defect was missing;
 *  - PRESENT arm: re-running 057 through the real runner over a schema that
 *    already has the index is a no-op (no duplicate key, no error);
 *  - MISSING arm: with the index gone, re-running 057 recreates it.
 *
 * Deliberately NOT asserted: the optimizer's chosen `key` — at test row counts
 * a full scan of an empty table is a legitimate choice and says nothing about
 * the index's production value (TokenPruneRealDbTest precedent).
 *
 * Skipped when `HUB_TEST_DB_*` env vars are not set (CI runs them against a
 * MySQL service; scripts/assert-integration-tests-ran.php reds on skips).
 *
 * @group integration
 */
final class RelaySessionsClosedAtIndexRealDbTest extends RealDatabaseTestCase
{
    private const MIGRATION = '057_relay_sessions_closed_at_index.sql';
    private const INDEX = 'idx_relay_sessions_closed_at';

    public function testTheChainLeavesOneLeadingClosedAtIndexBesideTheServerScopedKey(): void
    {
        self::assertSame([self::INDEX], $this->singleColumnClosedAtIndexes());

        /** @var list<array<string, string>> $rows */
        $rows = $this->db->query(
            'SELECT INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX, NON_UNIQUE FROM information_schema.STATISTICS'
                . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'relay_sessions'"
                . " AND INDEX_NAME IN ('" . self::INDEX . "', 'ix_relay_sessions_open')"
                . ' ORDER BY INDEX_NAME, SEQ_IN_INDEX',
        );
        $shape = array_map(
            static fn (array $r): string => sprintf(
                '%s:%s:%s:%s',
                (string) $r['INDEX_NAME'],
                (string) $r['SEQ_IN_INDEX'],
                (string) $r['COLUMN_NAME'],
                (string) $r['NON_UNIQUE'],
            ),
            $rows,
        );
        self::assertSame(
            [
                self::INDEX . ':1:closed_at:1',
                'ix_relay_sessions_open:1:server_id:1',
                'ix_relay_sessions_open:2:closed_at:1',
            ],
            $shape,
        );
    }

    public function testServerAgnosticOpenSetPredicatesCanUseTheIndex(): void
    {
        foreach (
            [
                'SELECT COUNT(*) AS cnt FROM relay_sessions WHERE closed_at IS NULL',
                'SELECT DISTINCT server_id FROM relay_sessions WHERE closed_at IS NULL',
            ] as $select
        ) {
            // row(), not query(): the pooled driver's query() only fetches for
            // SELECT/SHOW and would return null for EXPLAIN.
            /** @var array<string, mixed>|false|null $plan */
            $plan = $this->db->row('EXPLAIN ' . $select);
            self::assertIsArray($plan);
            $keys = $plan['possible_keys'] ?? '';
            $possible = explode(',', is_string($keys) ? $keys : '');
            self::assertContains(self::INDEX, $possible, 'possible_keys for: ' . $select);
        }
    }

    public function testReplayOverAPresentIndexIsANoOp(): void
    {
        $this->forgetLedgerRow();

        $executed = (new MigrationRunner($this->db, self::migrationsDirectory()))->run();

        self::assertSame([self::MIGRATION], $executed);
        self::assertSame([self::INDEX], $this->singleColumnClosedAtIndexes());
    }

    public function testReplayOverAMissingIndexRecreatesIt(): void
    {
        $this->db->query('ALTER TABLE relay_sessions DROP INDEX ' . self::INDEX);
        try {
            self::assertSame([], $this->singleColumnClosedAtIndexes());
            $this->forgetLedgerRow();

            $executed = (new MigrationRunner($this->db, self::migrationsDirectory()))->run();

            self::assertSame([self::MIGRATION], $executed);
            self::assertSame([self::INDEX], $this->singleColumnClosedAtIndexes());
        } finally {
            // The harness fingerprint hashes columns, not indexes, so a lost
            // index would NOT trigger a rebuild for the next test — restore it.
            if ($this->singleColumnClosedAtIndexes() === []) {
                $this->db->query(
                    'ALTER TABLE relay_sessions ADD INDEX ' . self::INDEX . ' (closed_at)',
                );
            }
        }
    }

    private function forgetLedgerRow(): void
    {
        $this->db->query(
            'DELETE FROM `' . MigrationRunner::TRACKING_TABLE . '` WHERE filename = :f',
            ['f' => self::MIGRATION],
        );
    }

    /**
     * Index names on relay_sessions whose column set is exactly {closed_at} —
     * the same shape 057's guard probes.
     *
     * @return list<string>
     */
    private function singleColumnClosedAtIndexes(): array
    {
        /** @var list<array<string, string>> $rows */
        $rows = $this->db->query(
            'SELECT INDEX_NAME FROM information_schema.STATISTICS'
                . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'relay_sessions'"
                . ' GROUP BY INDEX_NAME'
                . " HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME) = 'closed_at'"
                . ' ORDER BY INDEX_NAME',
        );

        return array_values(array_map(static fn (array $r): string => (string) $r['INDEX_NAME'], $rows));
    }
}
