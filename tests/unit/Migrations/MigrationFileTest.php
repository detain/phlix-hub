<?php

declare(strict_types=1);

namespace Phlex\Hub\Tests\unit\Migrations;

use PHPUnit\Framework\TestCase;

/**
 * Static checks on the migration files themselves: every `*.sql` file
 * must start with a `-- migration: NNN_name` header, must be non-empty,
 * must declare InnoDB + utf8mb4, and must have balanced parentheses.
 *
 * @package Phlex\Hub\Tests\unit\Migrations
 * @since 0.2.0
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

    public function testFiveExpectedMigrationsExist(): void
    {
        $expected = [
            '001_users.sql',
            '002_servers.sql',
            '003_shared_libraries.sql',
            '004_relay_sessions.sql',
            '005_webhooks.sql',
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
        self::assertMatchesRegularExpression(
            '/\bid\s+CHAR\(36\)\s+NOT NULL/i',
            $contents,
            basename($file) . ' must use CHAR(36) NOT NULL for primary keys',
        );
    }
}
