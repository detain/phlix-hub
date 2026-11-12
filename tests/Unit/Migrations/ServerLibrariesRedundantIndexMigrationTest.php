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
 * Static pins on migration `058_drop_redundant_server_libraries_index.sql`
 * (HUB-S11, HUB-23): 031's standalone `(server_id)` key is a strict prefix of
 * UNIQUE `(server_id, library_id)`, so it is dropped under the 054 shape guard.
 * Apply/replay behaviour against a real InnoDB is
 * {@see \Phlix\Hub\Tests\Integration\Hub\ServerLibrariesRedundantIndexRealDbTest}.
 *
 * @package Phlix\Hub\Tests\Unit\Migrations
 */
final class ServerLibrariesRedundantIndexMigrationTest extends TestCase
{
    private const MIGRATION = __DIR__ . '/../../../migrations/058_drop_redundant_server_libraries_index.sql';
    private const BASE_TABLE = __DIR__ . '/../../../migrations/031_server_libraries.sql';

    private static function read(string $path): string
    {
        $sql = file_get_contents($path);
        self::assertNotFalse($sql, $path . ' must be readable');

        return $sql;
    }

    public function testBaseTableStillCarriesTheUniqueKeyThatSubsumesTheDroppedOne(): void
    {
        $base = self::read(self::BASE_TABLE);

        self::assertStringContainsString('UNIQUE KEY unique_server_library (server_id, library_id)', $base);
        self::assertStringContainsString('KEY idx_server_libraries_server_id (server_id)', $base);
    }

    public function testGuardProbesTheSingleColumnNonUniqueShapeOnly(): void
    {
        $sql = self::read(self::MIGRATION);

        self::assertStringStartsWith('-- migration: 058_drop_redundant_server_libraries_index', $sql);
        self::assertStringContainsString("AND TABLE_NAME = 'server_libraries'", $sql);
        self::assertStringContainsString('AND NON_UNIQUE = 1', $sql);
        self::assertStringContainsString(
            "HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME) = 'server_id'",
            $sql,
        );
    }

    public function testBothArmsAndLeastPrivilegeEnvelope(): void
    {
        $sql = self::read(self::MIGRATION);

        self::assertMatchesRegularExpression(
            "/IF\(\s*@phlix_sl_redundant IS NULL,\s*'SELECT 0',\s*CONCAT\('ALTER TABLE server_libraries DROP INDEX /",
            $sql,
        );
        self::assertStringContainsString('PREPARE stmt FROM @phlix_sl_sql;', $sql);
        self::assertStringContainsString('EXECUTE stmt;', $sql);
        self::assertStringContainsString('DEALLOCATE PREPARE stmt;', $sql);
        self::assertStringNotContainsString('DROP TABLE', $sql);
        self::assertStringNotContainsString('DROP PRIMARY KEY', $sql);
    }
}
