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
        $content001 = 'CREATE TABLE foo (id INT);';
        file_put_contents($this->migrationsDir . '/001_a.sql', $content001);
        file_put_contents($this->migrationsDir . '/002_b.sql', 'CREATE TABLE bar (id INT);');

        $db = $this->createMock(Connection::class);
        // ensureTrackingTable -> 1 query
        // loadLedger          -> 1 query; 001 recorded WITH a matching checksum
        //                        so it is skipped without executing (no backfill)
        // applyFile(002_b)    -> 1 query
        // recordApplied(002_b)-> 1 query
        $callIndex = 0;
        $db->expects(self::exactly(4))
            ->method('query')
            ->willReturnCallback(function ($sql, $params = null) use (&$callIndex, $content001) {
                $callIndex++;
                if ($callIndex === 2) {
                    return [['filename' => '001_a.sql', 'checksum' => MigrationRunner::checksum($content001)]];
                }
                return null;
            });

        $runner = new MigrationRunner($db, $this->migrationsDir);
        $applied = $runner->run();

        self::assertSame(['002_b.sql'], $applied);
    }

    public function testRunReturnsEmptyArrayWhenEverythingAlreadyApplied(): void
    {
        $content001 = 'CREATE TABLE foo (id INT);';
        file_put_contents($this->migrationsDir . '/001_a.sql', $content001);

        $db = $this->createMock(Connection::class);
        $callIndex = 0;
        $db->expects(self::exactly(2))
            ->method('query')
            ->willReturnCallback(function ($sql, $params = null) use (&$callIndex, $content001) {
                $callIndex++;
                if ($callIndex === 2) {
                    return [['filename' => '001_a.sql', 'checksum' => MigrationRunner::checksum($content001)]];
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

    public function testRunDoesNotSplitOnSemicolonInsideStringLiteral(): void
    {
        // Regression: a `;` inside a column COMMENT string literal previously
        // shredded the CREATE TABLE into two broken fragments (1064 syntax
        // error). The statement must reach the DB intact, comment and all.
        $sql = "-- migration: 001\n"
            . "CREATE TABLE foo (\n"
            . "    id INT COMMENT 'Hard expiry; the token is invalid once this passes'\n"
            . ");\n";
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
            static fn ($s): bool => is_string($s) && str_contains($s, 'CREATE TABLE foo'),
        ));
        self::assertCount(1, $exec, 'CREATE TABLE must be a single un-split statement');
        self::assertStringContainsString(
            "COMMENT 'Hard expiry; the token is invalid once this passes'",
            $exec[0],
            'The full COMMENT string (including its embedded semicolon) must survive splitting',
        );
    }

    public function testRunRecordsAppliedFilename(): void
    {
        $content = 'CREATE TABLE foo (id INT);';
        file_put_contents($this->migrationsDir . '/001_users.sql', $content);

        $db = $this->createMock(Connection::class);
        $recordedFilename = null;
        $recordedChecksum = null;
        $callIndex = 0;
        $db->method('query')
            ->willReturnCallback(
                function ($sql, $params = null) use (&$recordedFilename, &$recordedChecksum, &$callIndex) {
                    $callIndex++;
                    if ($callIndex === 2) {
                        return [];
                    }
                    if (is_string($sql) && str_contains($sql, 'INSERT INTO `migrations`')) {
                        $recordedFilename = is_array($params) ? ($params['filename'] ?? null) : null;
                        $recordedChecksum = is_array($params) ? ($params['checksum'] ?? null) : null;
                    }
                    return null;
                },
            );

        $runner = new MigrationRunner($db, $this->migrationsDir);
        $runner->run();

        self::assertSame('001_users.sql', $recordedFilename);
        // HB-4.11: the ledger row is recorded with the comment-normalised md5.
        self::assertSame(MigrationRunner::checksum($content), $recordedChecksum);
    }

    public function testChecksumIsCommentNormalisedMd5(): void
    {
        // Full-line `--`/`#` comments and per-line trailing whitespace are
        // stripped before hashing, so a doc-only edit does not flip the hash…
        $withComment = "-- migration: 001_x\nCREATE TABLE foo (id INT);   \n";
        $docOnlyEdit = "-- migration: 001_x (renamed header)\nCREATE TABLE foo (id INT);\n";
        self::assertSame(
            MigrationRunner::checksum($withComment),
            MigrationRunner::checksum($docOnlyEdit),
            'A documentation-only edit must not change the checksum',
        );

        // …but a real SQL change DOES flip it.
        $realEdit = "-- migration: 001_x\nCREATE TABLE foo (id BIGINT);\n";
        self::assertNotSame(
            MigrationRunner::checksum($withComment),
            MigrationRunner::checksum($realEdit),
            'A real SQL change must change the checksum',
        );

        // Deterministic 32-char md5.
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', MigrationRunner::checksum($withComment));
    }

    public function testRunRecordsChecksumOnCleanApply(): void
    {
        $content = 'CREATE TABLE foo (id INT);';
        file_put_contents($this->migrationsDir . '/001_a.sql', $content);

        $db = $this->createMock(Connection::class);
        $recordedChecksum = null;
        $db->method('query')
            ->willReturnCallback(function ($sql, $params = null) use (&$recordedChecksum) {
                if (is_string($sql) && str_contains($sql, 'SELECT filename')) {
                    return []; // empty ledger — un-recorded
                }
                if (is_string($sql) && str_contains($sql, 'INSERT INTO `migrations`')) {
                    $recordedChecksum = is_array($params) ? ($params['checksum'] ?? null) : null;
                }
                return null;
            });

        $runner = new MigrationRunner($db, $this->migrationsDir);
        self::assertSame(['001_a.sql'], $runner->run());
        self::assertSame(MigrationRunner::checksum($content), $recordedChecksum);
    }

    public function testRunSkipsRecordedFileWithMatchingChecksumWithoutExecuting(): void
    {
        $content = 'CREATE TABLE foo (id INT);';
        file_put_contents($this->migrationsDir . '/001_a.sql', $content);

        $db = $this->createMock(Connection::class);
        $executed = [];
        $db->method('query')
            ->willReturnCallback(function ($sql, $params = null) use (&$executed, $content) {
                if (is_string($sql) && str_contains($sql, 'SELECT filename')) {
                    return [['filename' => '001_a.sql', 'checksum' => MigrationRunner::checksum($content)]];
                }
                $executed[] = is_string($sql) ? $sql : '';
                return null;
            });

        $runner = new MigrationRunner($db, $this->migrationsDir);
        self::assertSame([], $runner->run(), 'A matching-checksum file must not be re-applied');

        $ranCreate = array_filter($executed, static fn (string $s): bool => str_contains($s, 'CREATE TABLE foo'));
        self::assertCount(0, $ranCreate, 'The migration statement must not be executed on a checksum match');
    }

    public function testRunWarnsAndReappliesAndRefreshesOnDivergedChecksum(): void
    {
        $content = 'CREATE TABLE foo (id INT);';
        file_put_contents($this->migrationsDir . '/001_a.sql', $content);

        $db = $this->createMock(Connection::class);
        $executed = [];
        $recordedChecksum = null;
        $db->method('query')
            ->willReturnCallback(function ($sql, $params = null) use (&$executed, &$recordedChecksum) {
                if (is_string($sql) && str_contains($sql, 'SELECT filename')) {
                    // Recorded with a stale (non-matching) checksum → diverged.
                    return [['filename' => '001_a.sql', 'checksum' => str_repeat('0', 32)]];
                }
                if (is_string($sql) && str_contains($sql, 'INSERT INTO `migrations`')) {
                    $recordedChecksum = is_array($params) ? ($params['checksum'] ?? null) : null;
                }
                $executed[] = is_string($sql) ? $sql : '';
                return null;
            });

        // Capture the divergence WARNING (emitted via error_log()).
        $logFile = (string) tempnam(sys_get_temp_dir(), 'phlix-hub-mig-log-');
        $previous = ini_set('error_log', $logFile);

        $runner = new MigrationRunner($db, $this->migrationsDir);
        $applied = $runner->run();

        ini_set('error_log', $previous === false ? '' : $previous);
        $log = (string) @file_get_contents($logFile);
        @unlink($logFile);

        self::assertSame(['001_a.sql'], $applied, 'A diverged file must be re-applied');

        $ranCreate = array_filter($executed, static fn (string $s): bool => str_contains($s, 'CREATE TABLE foo'));
        self::assertCount(1, $ranCreate, 'The migration statement must be executed on divergence');

        // Checksum refreshed to the current on-disk value.
        self::assertSame(MigrationRunner::checksum($content), $recordedChecksum);

        // A warning was emitted naming the file.
        self::assertStringContainsString('diverged', $log);
        self::assertStringContainsString('001_a.sql', $log);
    }

    public function testRunBackfillsNullChecksumWithoutReapplying(): void
    {
        // THE backfill-safety scenario: a row recorded before HB-4.11 added the
        // checksum column (checksum IS NULL). It must NOT be re-executed — its
        // mere existence proves it was applied — but its checksum IS backfilled
        // from the current on-disk file for future divergence detection.
        $content = 'CREATE TABLE foo (id INT);';
        file_put_contents($this->migrationsDir . '/001_a.sql', $content);

        $db = $this->createMock(Connection::class);
        $executed = [];
        $backfillHappened = false;
        $backfilledChecksum = null;
        $db->method('query')
            ->willReturnCallback(
                function ($sql, $params = null) use (&$executed, &$backfillHappened, &$backfilledChecksum) {
                    if (is_string($sql) && str_contains($sql, 'SELECT filename')) {
                        return [['filename' => '001_a.sql', 'checksum' => null]];
                    }
                    if (is_string($sql) && str_contains($sql, 'UPDATE `migrations` SET checksum')) {
                        $backfillHappened = true;
                        $backfilledChecksum = is_array($params) ? ($params['checksum'] ?? null) : null;
                    }
                    $executed[] = is_string($sql) ? $sql : '';
                    return null;
                },
            );

        $runner = new MigrationRunner($db, $this->migrationsDir);
        $applied = $runner->run();

        self::assertSame([], $applied, 'A NULL-checksum row must NOT be re-applied (backfill safety)');

        $ranCreate = array_filter($executed, static fn (string $s): bool => str_contains($s, 'CREATE TABLE foo'));
        self::assertCount(0, $ranCreate, 'Backfill must not re-execute the migration statements');

        self::assertTrue($backfillHappened, 'The NULL checksum must be backfilled');
        self::assertSame(
            MigrationRunner::checksum($content),
            $backfilledChecksum,
            'Backfill must stamp the current on-disk checksum',
        );
    }

    public function testRunDoesNotMassReapplyWhenChecksumColumnMissing(): void
    {
        // Pre-041 existing production: the checksum column does not exist yet.
        // The ledger read must degrade to name-only so already-recorded files
        // are recognised and NOT re-applied wholesale.
        $content = 'CREATE TABLE foo (id INT);';
        file_put_contents($this->migrationsDir . '/001_a.sql', $content);

        $db = $this->createMock(Connection::class);
        $executed = [];
        $db->method('query')
            ->willReturnCallback(function ($sql, $params = null) use (&$executed) {
                if (is_string($sql) && str_contains($sql, 'SELECT filename, checksum')) {
                    throw new \RuntimeException("Unknown column 'checksum' in 'field list'");
                }
                if (is_string($sql) && str_contains($sql, 'SELECT filename FROM')) {
                    return [['filename' => '001_a.sql']]; // name-only fallback read
                }
                $executed[] = is_string($sql) ? $sql : '';
                return null;
            });

        $runner = new MigrationRunner($db, $this->migrationsDir);
        self::assertSame([], $runner->run(), 'Pre-041 recorded files must not be re-applied');

        $ranCreate = array_filter($executed, static fn (string $s): bool => str_contains($s, 'CREATE TABLE foo'));
        self::assertCount(0, $ranCreate);
    }

    public function testRecordAppliedFallsBackToNameOnlyWhenChecksumColumnMissing(): void
    {
        // Fresh database: earlier files are recorded before migration 041 adds
        // the checksum column. recordApplied() must degrade to a name-only
        // insert instead of aborting the run — and, S199, run() must then come back
        // after the loop (once 041 has added the column) and stamp the checksum, so
        // the ledger is not left with a NULL that no later run is guaranteed to fix.
        //
        // The thrown message uses the shape `Workerman\MySQL\Connection` really
        // produces (measured against MySQL 8.0.46): the FAILING SQL is prefixed onto
        // the PDOException message, ahead of the SQLSTATE.
        $content = 'CREATE TABLE foo (id INT);';
        file_put_contents($this->migrationsDir . '/001_a.sql', $content);

        $db = $this->createMock(Connection::class);
        $nameOnlyInsertParams = null;
        $deferredBackfill = null;
        $db->method('query')
            ->willReturnCallback(
                function ($sql, $params = null) use (&$nameOnlyInsertParams, &$deferredBackfill) {
                    if (is_string($sql) && str_contains($sql, 'SELECT filename')) {
                        return []; // empty ledger — un-recorded
                    }
                    if (
                        is_string($sql)
                        && str_contains($sql, 'INSERT INTO `migrations`')
                        && str_contains($sql, 'checksum')
                    ) {
                        throw new \RuntimeException(
                            'SQL:' . $sql . " SQLSTATE[42S22]: Column not found: 1054"
                            . " Unknown column 'checksum' in 'field list'",
                        );
                    }
                    if (is_string($sql) && str_contains($sql, 'INSERT INTO `migrations`')) {
                        $nameOnlyInsertParams = is_array($params) ? $params : null;
                    }
                    if (is_string($sql) && str_contains($sql, 'UPDATE `migrations` SET checksum')) {
                        $deferredBackfill = is_array($params) ? $params : null;
                    }
                    return null;
                },
            );

        $runner = new MigrationRunner($db, $this->migrationsDir);
        self::assertSame(['001_a.sql'], $runner->run());

        self::assertIsArray($nameOnlyInsertParams);
        self::assertSame('001_a.sql', $nameOnlyInsertParams['filename'] ?? null);
        self::assertArrayNotHasKey('checksum', $nameOnlyInsertParams);

        // The deferred flush ran, for that file, with that file's checksum.
        self::assertIsArray(
            $deferredBackfill,
            'a name-only record must be followed by a deferred checksum write in the SAME run',
        );
        self::assertSame('001_a.sql', $deferredBackfill['filename'] ?? null);
        self::assertSame(MigrationRunner::checksum($content), $deferredBackfill['checksum'] ?? null);
    }

    /**
     * S199 guard: `isMissingChecksumColumnError()` must not match itself.
     *
     * The driver prefixes the failing SQL onto the exception message, and every
     * statement guarded by that classifier mentions `checksum` in its own SQL. A
     * classifier that looked for "unknown column" plus "checksum" anywhere in the
     * message therefore also matched an unknown-column error about a DIFFERENT
     * column, silently degrading a genuine schema fault to name-only recording
     * instead of surfacing it.
     */
    public function testUnknownColumnErrorAboutAnotherColumnIsNotTreatedAsMissingChecksum(): void
    {
        file_put_contents($this->migrationsDir . '/001_a.sql', 'CREATE TABLE foo (id INT);');

        $db = $this->createMock(Connection::class);
        $nameOnlyInsertAttempted = false;
        $db->method('query')
            ->willReturnCallback(function ($sql, $params = null) use (&$nameOnlyInsertAttempted) {
                if (is_string($sql) && str_contains($sql, 'SELECT filename')) {
                    return [];
                }
                if (is_string($sql) && str_contains($sql, 'INSERT INTO `migrations`')) {
                    if (str_contains($sql, 'checksum')) {
                        // Note the SQL echoed into the message mentions `checksum`
                        // three times, while the unknown column is `filename`.
                        throw new \RuntimeException(
                            'SQL:' . $sql . " SQLSTATE[42S22]: Column not found: 1054"
                            . " Unknown column 'filename' in 'field list'",
                        );
                    }
                    $nameOnlyInsertAttempted = true;
                }
                return null;
            });

        $runner = new MigrationRunner($db, $this->migrationsDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Unknown column 'filename'");
        try {
            $runner->run();
        } finally {
            self::assertFalse(
                $nameOnlyInsertAttempted,
                'an unrelated unknown-column fault must not be degraded to a name-only insert',
            );
        }
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
