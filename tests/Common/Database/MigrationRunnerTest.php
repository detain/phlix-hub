<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Common\Database;

use Phlix\Hub\Common\Database\MigrationRunner;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Pure-PHP unit tests for {@see MigrationRunner}.
 *
 * Exercises file discovery, tracking-table SQL emission, idempotency
 * gating, and statement splitting without touching a real database.
 * The MySQL connection is fully mocked; the integration test in
 * `tests/Integration/Migrations/` covers the live-DB scenarios.
 *
 * @package Phlix\Hub\Tests\Common\Database
 * @since 0.2.0
 *
 * @covers \Phlix\Hub\Common\Database\MigrationRunner
 */
final class MigrationRunnerTest extends TestCase
{
    private string $migrationsDir;

    protected function setUp(): void
    {
        $this->migrationsDir = sys_get_temp_dir() . '/phlix-hub-mig-' . uniqid();
        mkdir($this->migrationsDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->migrationsDir)) {
            return;
        }
        foreach (glob($this->migrationsDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->migrationsDir);
    }

    public function testDiscoverFilesReturnsSortedSqlFiles(): void
    {
        file_put_contents($this->migrationsDir . '/002_b.sql', 'SELECT 1;');
        file_put_contents($this->migrationsDir . '/001_a.sql', 'SELECT 1;');
        file_put_contents($this->migrationsDir . '/notes.txt', 'ignore me');

        $db = $this->createMock(Connection::class);
        $runner = new MigrationRunner($db, $this->migrationsDir);

        $files = $runner->discoverFiles();
        self::assertCount(2, $files);
        self::assertStringEndsWith('001_a.sql', $files[0]);
        self::assertStringEndsWith('002_b.sql', $files[1]);
    }

    public function testDiscoverFilesEmptyDirectoryReturnsEmptyArray(): void
    {
        $db = $this->createMock(Connection::class);
        $runner = new MigrationRunner($db, $this->migrationsDir);

        self::assertSame([], $runner->discoverFiles());
    }

    public function testEnsureTrackingTableEmitsCreateTableIfNotExists(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())
            ->method('query')
            ->with(self::stringContains('CREATE TABLE IF NOT EXISTS `migrations`'));

        $runner = new MigrationRunner($db, $this->migrationsDir);
        $runner->ensureTrackingTable();
    }

    public function testListAppliedHandlesEmptyResult(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $runner = new MigrationRunner($db, $this->migrationsDir);
        self::assertSame([], $runner->listApplied());
    }

    public function testListAppliedExtractsFilenames(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            ['filename' => '001_users.sql'],
            ['filename' => '002_servers.sql'],
        ]);

        $runner = new MigrationRunner($db, $this->migrationsDir);
        self::assertSame(['001_users.sql', '002_servers.sql'], $runner->listApplied());
    }

    public function testListAppliedSkipsRowsWithoutFilename(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            ['filename' => '001_users.sql'],
            ['other_col' => 'noise'],
        ]);

        $runner = new MigrationRunner($db, $this->migrationsDir);
        self::assertSame(['001_users.sql'], $runner->listApplied());
    }

    public function testRunSkipsAlreadyAppliedFiles(): void
    {
        file_put_contents($this->migrationsDir . '/001_a.sql', 'CREATE TABLE foo (id INT);');
        file_put_contents($this->migrationsDir . '/002_b.sql', 'CREATE TABLE bar (id INT);');

        $db = $this->createMock(Connection::class);
        // ensureTrackingTable -> 1 query
        // listApplied         -> 1 query returning that 001 is done
        // applyFile(002_b)    -> 1 query
        // recordApplied(002_b)-> 1 query
        $callIndex = 0;
        $db->expects(self::exactly(4))
            ->method('query')
            ->willReturnCallback(function ($sql, $params = null) use (&$callIndex) {
                $callIndex++;
                if ($callIndex === 2) {
                    return [['filename' => '001_a.sql']];
                }
                return null;
            });

        $runner = new MigrationRunner($db, $this->migrationsDir);
        $applied = $runner->run();

        self::assertSame(['002_b.sql'], $applied);
    }

    public function testRunReturnsEmptyArrayWhenEverythingAlreadyApplied(): void
    {
        file_put_contents($this->migrationsDir . '/001_a.sql', 'CREATE TABLE foo (id INT);');

        $db = $this->createMock(Connection::class);
        $callIndex = 0;
        $db->expects(self::exactly(2))
            ->method('query')
            ->willReturnCallback(function ($sql, $params = null) use (&$callIndex) {
                $callIndex++;
                if ($callIndex === 2) {
                    return [['filename' => '001_a.sql']];
                }
                return null;
            });

        $runner = new MigrationRunner($db, $this->migrationsDir);
        self::assertSame([], $runner->run());
    }

    public function testRunSplitsMultipleStatementsAndStripsComments(): void
    {
        $sql = "-- migration: 001\n"
            . "CREATE TABLE foo (id INT);\n"
            . "-- another comment\n"
            . "CREATE TABLE bar (id INT);\n";
        file_put_contents($this->migrationsDir . '/001_a.sql', $sql);

        $db = $this->createMock(Connection::class);
        $statements = [];
        $callIndex = 0;
        $db->method('query')
            ->willReturnCallback(function ($sql, $params = null) use (&$statements, &$callIndex) {
                $callIndex++;
                if ($callIndex === 2) {
                    // listApplied
                    return [];
                }
                $statements[] = $sql;
                return null;
            });

        $runner = new MigrationRunner($db, $this->migrationsDir);
        $runner->run();

        $exec = array_values(array_filter(
            $statements,
            static fn ($s): bool => is_string($s)
                && (str_contains($s, 'CREATE TABLE foo') || str_contains($s, 'CREATE TABLE bar')),
        ));
        self::assertCount(2, $exec);
        self::assertStringContainsString('CREATE TABLE foo', $exec[0]);
        self::assertStringContainsString('CREATE TABLE bar', $exec[1]);
    }

    public function testRunRecordsAppliedFilename(): void
    {
        file_put_contents($this->migrationsDir . '/001_users.sql', 'CREATE TABLE foo (id INT);');

        $db = $this->createMock(Connection::class);
        $recordedFilename = null;
        $callIndex = 0;
        $db->method('query')
            ->willReturnCallback(function ($sql, $params = null) use (&$recordedFilename, &$callIndex) {
                $callIndex++;
                if ($callIndex === 2) {
                    return [];
                }
                if (is_string($sql) && str_contains($sql, 'INSERT INTO `migrations`')) {
                    $recordedFilename = is_array($params) ? ($params['filename'] ?? null) : null;
                }
                return null;
            });

        $runner = new MigrationRunner($db, $this->migrationsDir);
        $runner->run();

        self::assertSame('001_users.sql', $recordedFilename);
    }

    public function testRunWrapsStatementFailureWithFilename(): void
    {
        file_put_contents($this->migrationsDir . '/001_bad.sql', 'NOT VALID SQL;');

        $db = $this->createMock(Connection::class);
        $callIndex = 0;
        $db->method('query')
            ->willReturnCallback(function ($sql, $params = null) use (&$callIndex) {
                $callIndex++;
                if ($callIndex === 2) {
                    return [];
                }
                if ($callIndex >= 3) {
                    throw new \RuntimeException('syntax error');
                }
                return null;
            });

        $runner = new MigrationRunner($db, $this->migrationsDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('001_bad.sql');
        $runner->run();
    }

    public function testTrackingTableConstantIsStable(): void
    {
        self::assertSame('migrations', MigrationRunner::TRACKING_TABLE);
    }
}
