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
use PHPUnit\Framework\Attributes\Group;

/**
 * HUB-S11 (HUB-23) against a real InnoDB: migration 058 drops 031's redundant
 * standalone `(server_id)` key, leaving UNIQUE (server_id, library_id) and the
 * FK intact, and both arms of its replay guard behave.
 *
 */
#[Group('integration')]
final class ServerLibrariesRedundantIndexRealDbTest extends RealDatabaseTestCase
{
    private const MIGRATION = '058_drop_redundant_server_libraries_index.sql';
    private const INDEX = 'idx_server_libraries_server_id';

    public function testChainDropsStandaloneKeyAndKeepsUniqueKey(): void
    {
        self::assertSame([], $this->serverIdOnlyIndexes());
        self::assertSame(
            ['server_id', 'library_id'],
            $this->columns('unique_server_library'),
        );
    }

    public function testServerIdLookupsStillListTheUniqueKey(): void
    {
        foreach (
            [
                "SELECT COUNT(*) FROM server_libraries WHERE server_id = 'x'",
                "DELETE FROM server_libraries WHERE server_id = 'x' AND library_id NOT IN ('a')",
            ] as $sql
        ) {
            /** @var array<string, mixed>|false|null $plan */
            $plan = $this->db->row('EXPLAIN ' . $sql);
            self::assertIsArray($plan);
            $keys = $plan['possible_keys'] ?? '';
            self::assertContains(
                'unique_server_library',
                explode(',', is_string($keys) ? $keys : ''),
                $sql,
            );
        }
    }

    public function testReplayOverAnAbsentIndexIsANoOp(): void
    {
        $this->forgetLedgerRow();

        $executed = (new MigrationRunner($this->db, self::migrationsDirectory()))->run();

        self::assertSame([self::MIGRATION], $executed);
        self::assertSame([], $this->serverIdOnlyIndexes());
    }

    public function testReplayOverAPresentIndexDropsIt(): void
    {
        $this->db->query('ALTER TABLE server_libraries ADD INDEX ' . self::INDEX . ' (server_id)');
        try {
            self::assertSame([self::INDEX], $this->serverIdOnlyIndexes());
            $this->forgetLedgerRow();

            $executed = (new MigrationRunner($this->db, self::migrationsDirectory()))->run();

            self::assertSame([self::MIGRATION], $executed);
            self::assertSame([], $this->serverIdOnlyIndexes());
        } finally {
            foreach ($this->serverIdOnlyIndexes() as $name) {
                $this->db->query('ALTER TABLE server_libraries DROP INDEX `' . $name . '`');
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
     * @return list<string>
     */
    private function serverIdOnlyIndexes(): array
    {
        /** @var list<array<string, string>> $rows */
        $rows = $this->db->query(
            'SELECT INDEX_NAME FROM information_schema.STATISTICS'
                . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'server_libraries'"
                . ' GROUP BY INDEX_NAME'
                . " HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME) = 'server_id'"
                . ' ORDER BY INDEX_NAME',
        );

        return array_values(array_map(static fn (array $r): string => (string) $r['INDEX_NAME'], $rows));
    }

    /**
     * @return list<string>
     */
    private function columns(string $index): array
    {
        /** @var list<array<string, string>> $rows */
        $rows = $this->db->query(
            'SELECT COLUMN_NAME FROM information_schema.STATISTICS'
                . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'server_libraries'"
                . ' AND INDEX_NAME = :i ORDER BY SEQ_IN_INDEX',
            ['i' => $index],
        );

        return array_values(array_map(static fn (array $r): string => (string) $r['COLUMN_NAME'], $rows));
    }
}
