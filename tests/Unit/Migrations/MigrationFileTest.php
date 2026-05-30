<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Migrations;

use PHPUnit\Framework\TestCase;

/**
 * Static checks on the migration files themselves: every `*.sql` file
 * must start with a `-- migration: NNN_name` header, must be non-empty,
 * must declare InnoDB + utf8mb4, and must have balanced parentheses.
 *
 * @package Phlix\Hub\Tests\Unit\Migrations
 */
final class MigrationFileTest extends TestCase
{
    private const MIGRATIONS_DIR = __DIR__ . '/../../../migrations';

    /**
     * @return list<array{0: string}>
     */
    public static function migrationFileProvider(): array
    {
        $files = glob(self::MIGRATIONS_DIR . '/*.sql') ?: [];
        sort($files);
        return array_map(static fn (string $f): array => [$f], $files);
    }

    public function testMigrationsDirectoryHasAtLeastOneFile(): void
    {
        $files = glob(self::MIGRATIONS_DIR . '/*.sql') ?: [];
        self::assertNotEmpty($files, 'migrations/ must contain at least one .sql file');
    }

    public function testExpectedMigrationsExist(): void
    {
        $expected = [
            '001_users.sql',
            '002_servers.sql',
            '003_shared_libraries.sql',
            '004_relay_sessions.sql',
            '005_webhooks.sql',
            '006_server_heartbeats_sent_at.sql',
            '007_server_claims_and_servers.sql',
            '008_subdomain_allocation.sql',
            '009_library_shares.sql',
            '010_invite_links.sql',
            '011_media_requests.sql',
            '012_enrolled_at_and_last_frame_at.sql',
            '027_hub_settings.sql',
        ];
        $files = array_map('basename', glob(self::MIGRATIONS_DIR . '/*.sql') ?: []);
        sort($files);
        self::assertSame($expected, $files);
    }

    /**
     * @dataProvider migrationFileProvider
     */
    public function testFileIsNonEmpty(string $file): void
    {
        $contents = file_get_contents($file);
        self::assertNotFalse($contents);
        self::assertNotSame('', trim($contents), basename($file) . ' must not be empty');
    }

    /**
     * @dataProvider migrationFileProvider
     */
    public function testFileStartsWithMigrationHeader(string $file): void
    {
        $contents = file_get_contents($file);
        self::assertNotFalse($contents);
        self::assertMatchesRegularExpression(
            '/^-- migration: \d{3}_[a-z_]+/',
            ltrim($contents),
            basename($file) . ' must start with "-- migration: NNN_name"',
        );
    }

    /**
     * @dataProvider migrationFileProvider
     */
    public function testFileDeclaresInnodbAndUtf8mb4(string $file): void
    {
        $contents = file_get_contents($file);
        self::assertNotFalse($contents);
        if (!self::definesNewTable($contents)) {
            // ALTER-only migration: InnoDB/utf8mb4 was set by the original CREATE TABLE.
            $this->addToAssertionCount(1);
            return;
        }
        self::assertStringContainsString(
            'ENGINE=InnoDB',
            $contents,
            basename($file) . ' must declare ENGINE=InnoDB',
        );
        self::assertStringContainsString(
            'utf8mb4',
            $contents,
            basename($file) . ' must use utf8mb4 charset',
        );
    }

    /**
     * @dataProvider migrationFileProvider
     */
    public function testFileUsesCreateTableIfNotExists(string $file): void
    {
        $contents = file_get_contents($file);
        self::assertNotFalse($contents);
        if (!self::definesNewTable($contents)) {
            // ALTER-only migration: idempotency comes from `ALTER ... IF NOT EXISTS`.
            $this->addToAssertionCount(1);
            return;
        }
        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS',
            $contents,
            basename($file) . ' must use CREATE TABLE IF NOT EXISTS for idempotency',
        );
    }

    /**
     * @dataProvider migrationFileProvider
     */
    public function testParenthesesAreBalanced(string $file): void
    {
        $contents = file_get_contents($file);
        self::assertNotFalse($contents);
        $opens = substr_count($contents, '(');
        $closes = substr_count($contents, ')');
        self::assertSame(
            $opens,
            $closes,
            basename($file) . ' has mismatched parentheses (' . $opens . ' open, ' . $closes . ' close)',
        );
    }

    /**
     * @dataProvider migrationFileProvider
     */
    public function testFileUsesCharThirtySixForPrimaryKeys(string $file): void
    {
        $contents = file_get_contents($file);
        self::assertNotFalse($contents);
        if (!self::definesNewTable($contents)) {
            // ALTER-only migration: PK type was set by the original CREATE TABLE.
            $this->addToAssertionCount(1);
            return;
        }
        self::assertMatchesRegularExpression(
            '/\bid\s+CHAR\(36\)\s+NOT NULL/i',
            $contents,
            basename($file) . ' must use CHAR(36) NOT NULL for primary keys',
        );
    }

    /**
     * Whether this migration file creates one or more new tables (as
     * opposed to ALTER-only edits against tables defined by earlier
     * migrations). Table-shape checks like InnoDB/utf8mb4 declaration
     * and CHAR(36) primary keys only apply to files that introduce a
     * fresh CREATE TABLE.
     */
    private static function definesNewTable(string $contents): bool
    {
        return stripos($contents, 'CREATE TABLE') !== false;
    }
}
