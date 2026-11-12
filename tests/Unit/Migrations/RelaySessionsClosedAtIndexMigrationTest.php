<?php

/**
 * Phlix hub component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Migrations;

use PHPUnit\Framework\TestCase;

/**
 * Static pins on migration `057_relay_sessions_closed_at_index.sql` (HUB-S10,
 * HUB-1): the server-agnostic open-set predicates on `relay_sessions`
 * (`WHERE closed_at IS NULL` with no `server_id = ?` prefix) get a leading
 * `closed_at` index, applied under the 051 replay-safe shape guard.
 *
 * Runs in the unit suite (no DB) so the index column, the guard's shape string
 * and the least-privilege DDL envelope cannot drift silently. The apply/replay
 * BEHAVIOUR against a real InnoDB is
 * {@see \Phlix\Hub\Tests\Integration\Hub\RelaySessionsClosedAtIndexRealDbTest}.
 *
 * @package Phlix\Hub\Tests\Unit\Migrations
 */
final class RelaySessionsClosedAtIndexMigrationTest extends TestCase
{
    private const MIGRATION = __DIR__ . '/../../../migrations/057_relay_sessions_closed_at_index.sql';
    private const BASE_TABLE = __DIR__ . '/../../../migrations/004_relay_sessions.sql';

    private static function read(string $path): string
    {
        $sql = file_get_contents($path);
        self::assertNotFalse($sql, $path . ' must be readable');

        return $sql;
    }

    public function testAddsASingleColumnClosedAtIndexOnRelaySessions(): void
    {
        $sql = self::read(self::MIGRATION);

        self::assertStringStartsWith('-- migration: 057_relay_sessions_closed_at_index', $sql);
        self::assertStringContainsString(
            "'ALTER TABLE relay_sessions ADD INDEX idx_relay_sessions_closed_at (closed_at)'",
            $sql,
            'the index must lead on closed_at ALONE — the open-set predicates carry no server_id',
        );
    }

    public function testGuardIsThe051SingleColumnShapeProbe(): void
    {
        $sql = self::read(self::MIGRATION);

        self::assertStringContainsString('FROM information_schema.STATISTICS', $sql);
        self::assertStringContainsString("AND TABLE_NAME = 'relay_sessions'", $sql);
        self::assertStringContainsString(
            "HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME) = 'closed_at'",
            $sql,
        );
        // Present → no-op arm; missing → the ADD INDEX arm. Both branches of the IF.
        self::assertMatchesRegularExpression(
            "/IF\(\s*@phlix_rs_closed_index > 0,\s*'SELECT 0',\s*'ALTER TABLE relay_sessions ADD INDEX/",
            $sql,
        );
        self::assertStringContainsString('PREPARE stmt FROM @phlix_rs_closed_sql;', $sql);
        self::assertStringContainsString('EXECUTE stmt;', $sql);
        self::assertStringContainsString('DEALLOCATE PREPARE stmt;', $sql);
    }

    /**
     * The guard must NOT be satisfied by 004's `ix_relay_sessions_open
     * (server_id, closed_at)` — that key cannot range a predicate without
     * server_id, which is the whole defect. Its sorted column concatenation
     * (the probe's own GROUP_CONCAT ordering) is computed from 004 itself.
     */
    public function testThe004OpenKeyDoesNotSatisfyTheProbeShape(): void
    {
        $base = self::read(self::BASE_TABLE);

        self::assertSame(
            1,
            preg_match('/KEY\s+ix_relay_sessions_open\s*\(([^)]*)\)/', $base, $m),
            '004 must still declare ix_relay_sessions_open',
        );
        if (!isset($m[1])) {
            self::fail('004 ix_relay_sessions_open column list must capture');
        }
        $columns = array_map('trim', explode(',', $m[1]));
        sort($columns);

        self::assertSame(['closed_at', 'server_id'], $columns);
        self::assertNotSame('closed_at', implode(',', $columns));
    }

    /**
     * Least-privilege envelope: the README grant set is SELECT, INSERT, UPDATE,
     * DELETE, CREATE, ALTER, INDEX, REFERENCES. The file may only issue the
     * ALTER TABLE ... ADD INDEX that rides those grants — no DROP, no
     * MariaDB-only `IF NOT EXISTS` on the index, no table-copy algorithm that
     * would block relay accounting writes for the length of the rebuild.
     */
    public function testStaysInsideTheLeastPrivilegeOnlineDdlEnvelope(): void
    {
        $sql = self::read(self::MIGRATION);
        $code = (string) preg_replace('/^\s*--.*$/m', '', $sql);

        self::assertDoesNotMatchRegularExpression('/\bDROP\b/i', $code);
        self::assertDoesNotMatchRegularExpression('/\bIF\s+(NOT\s+)?EXISTS\b/i', $code);
        self::assertDoesNotMatchRegularExpression('/ALGORITHM\s*=\s*COPY|LOCK\s*=\s*(SHARED|EXCLUSIVE)/i', $code);
        self::assertDoesNotMatchRegularExpression('/\b(GRANT|SUPER|PARTITION)\b/i', $code);
    }
}
