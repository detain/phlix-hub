<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Integration\Migrations;

use Phlix\Hub\Common\Database\MigrationRunner;
use Phlix\Hub\Tests\Support\RealDatabaseTestCase;

/**
 * Integration tests for the migration runner against a real MySQL test
 * database. Skipped automatically when the `HUB_TEST_DB_*` environment
 * variables are not set, so the suite stays green in environments
 * without MySQL.
 *
 * Required env vars to enable: `HUB_TEST_DB_HOST`, `HUB_TEST_DB_PORT`,
 * `HUB_TEST_DB_USER`, `HUB_TEST_DB_PASSWORD`, `HUB_TEST_DB_NAME`. The
 * named database **must already exist** and the user must have full
 * privileges on it — every table in it is dropped or emptied.
 *
 * ## S185 — which tests here need an EMPTY database, and which need a SCHEMA
 *
 * This file is the one place where "re-apply the whole chain" is the SUBJECT of
 * the test rather than a way to get a schema, so it does not get the shared
 * once-per-process schema for free. The split is explicit and per test:
 *
 *  - a test that asserts on what `run()` RETURNS, or on the ledger a CLEAN apply
 *    produces, calls {@see dropAllTables()} as its first statement. That is
 *    exactly the old `setUp()` behaviour, kept verbatim where it is load-bearing:
 *    {@see testRunsAllMigrationsInOrderAgainstEmptyDb()},
 *    {@see testRerunningIsIdempotent()},
 *    {@see testCleanApplyPopulatesChecksumColumn()},
 *    {@see testChainApplyLeavesEveryLedgerRowWithItsOwnOnDiskChecksum()};
 *  - every other test here asserts on the SCHEMA the chain produces (a UNIQUE
 *    key, a CASCADE, the FK inventory) or on the runner's behaviour against an
 *    ALREADY-applied ledger (checksum divergence, NULL-checksum backfill). Those
 *    keep `$this->runner->run()` where they had it — it is now a 0.02 s replay
 *    against the schema {@see RealDatabaseTestCase} already built, which leaves
 *    the identical state a fresh apply would (asserted by
 *    {@see testChainApplyLeavesEveryLedgerRowWithItsOwnOnDiskChecksum()}, whose
 *    replay must apply nothing and warn nothing).
 *
 * The `migrations` ledger is deliberately NOT emptied between tests — see
 * {@see RealDatabaseTestCase::resetAllTableData()}. The two tests that
 * deliberately corrupt a ledger row both leave it repaired, and a run against a
 * diverged or NULL checksum self-heals by design, so neither can hand a broken
 * ledger to a sibling.
 *
 * @package Phlix\Hub\Tests\Integration\Migrations
 *
 * @group integration
 */
final class MigrationRunnerIntegrationTest extends RealDatabaseTestCase
{
    private MigrationRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runner = new MigrationRunner($this->db, self::migrationsDirectory());
    }

    public function testRunsAllMigrationsInOrderAgainstEmptyDb(): void
    {
        // The subject of this test IS the apply against an empty database.
        $this->dropAllTables();

        $applied = $this->runner->run();

        // Assert the runner applies EVERY migration file, in ascending filename
        // order, against an empty DB — derived from the on-disk set rather than a
        // hard-coded prefix so the test does not rot as new migrations land (it
        // previously pinned only the first six files, authored when the repo had
        // six migrations, and broke once 007+ were added).
        $expected = array_values(array_map(
            'basename',
            glob(dirname(__DIR__, 3) . '/migrations/*.sql') ?: [],
        ));
        sort($expected);
        self::assertNotEmpty($expected, 'migrations directory must contain SQL files');
        self::assertSame(
            $expected,
            $applied,
            'Every migration file must be applied, in ascending filename order',
        );

        foreach (
            [
                'users',
                'servers',
                'server_claims',
                'server_heartbeats',
                'shared_libraries',
                'relay_sessions',
                'webhooks',
            ] as $table
        ) {
            self::assertTrue($this->tableExists($table), "Table '{$table}' must exist after migration");
        }
    }

    public function testRerunningIsIdempotent(): void
    {
        // "The FIRST run against an empty DB applies every file" — so start empty.
        $this->dropAllTables();

        $migrationFiles = glob(dirname(__DIR__, 3) . '/migrations/*.sql') ?: [];
        $first = $this->runner->run();
        self::assertCount(
            count($migrationFiles),
            $first,
            'The first run against an empty DB applies every migration file',
        );
        self::assertNotEmpty($first);

        $second = $this->runner->run();
        self::assertSame([], $second, 'Re-running migrations should apply nothing new');
    }

    public function testUsersUniqueEmailConstraint(): void
    {
        $this->runner->run();

        $this->insertUser('u-1', 'alice', 'a@example.com');

        $this->expectException(\Throwable::class);
        $this->insertUser('u-2', 'alice2', 'a@example.com');
    }

    public function testServerForeignKeyCascadesOnUserDelete(): void
    {
        $this->runner->run();

        $this->insertUser('u-100', 'bob', 'b@example.com');
        // `public_key_jwk` (migration 007) is NOT NULL with no default; include it
        // so the fixture matches the current servers schema (the original INSERT
        // predated that column and now trips a 1364 "no default value").
        $this->db->query(
            "INSERT INTO servers"
            . " (id, user_id, server_name, version, public_key_jwk, hostname_candidates_json, status)"
            . " VALUES ('s-1', 'u-100', 'srv', '0.1.0', '{}', '[]', 'online')",
        );

        $rowsBefore = $this->db->query("SELECT id FROM servers WHERE id='s-1'");
        self::assertIsArray($rowsBefore);
        self::assertCount(1, $rowsBefore);

        $this->db->query("DELETE FROM users WHERE id='u-100'");

        $rowsAfter = $this->db->query("SELECT id FROM servers WHERE id='s-1'");
        self::assertIsArray($rowsAfter);
        self::assertCount(0, $rowsAfter, 'Server row should cascade-delete when its user is deleted');
    }

    public function testForeignKeyConstraintsArePresent(): void
    {
        $this->runner->run();

        $name = self::testDatabaseName();
        $rows = $this->db->query(
            "SELECT TABLE_NAME, CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS"
            . " WHERE CONSTRAINT_SCHEMA = :schema AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
            ['schema' => $name],
        );
        self::assertIsArray($rows);

        $names = array_map(
            static function ($r): string {
                if (!is_array($r) || !isset($r['CONSTRAINT_NAME'])) {
                    return '';
                }
                $val = $r['CONSTRAINT_NAME'];
                return is_string($val) ? $val : '';
            },
            $rows,
        );

        foreach (
            [
                'fk_servers_user',
                'fk_server_heartbeats_server',
                'fk_shared_libraries_owner',
                'fk_shared_libraries_grantee',
                'fk_shared_libraries_server',
                'fk_relay_sessions_server',
                'fk_webhooks_user',
            ] as $expected
        ) {
            self::assertContains($expected, $names, "Missing FK: {$expected}");
        }
    }

    public function testCleanApplyPopulatesChecksumColumn(): void
    {
        // ONE run is enough. Migration 041 adds the checksum column part-way
        // through the chain, and run()'s post-loop flush stamps the files that were
        // recorded before it existed — no second invocation required. (S199: this
        // previously needed two run() calls, which is exactly why a
        // freshly-provisioned hub sat with 26 of 29 rows at checksum IS NULL until
        // some later deploy happened to backfill them.)
        //
        // S185: "clean apply" is the whole point — the ledger row under test must
        // be written by THIS run, not inherited from a warm schema — so empty the
        // database first, exactly as the old per-test setUp() did.
        $this->dropAllTables();
        $this->runner->run();

        $rows = $this->db->query(
            'SELECT filename, checksum FROM `migrations` WHERE filename = :f',
            ['f' => '001_users.sql'],
        );
        self::assertIsArray($rows);
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertIsArray($row);
        self::assertIsString($row['checksum'] ?? null, 'checksum must be backfilled to a non-null value');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $row['checksum']);
    }

    /**
     * S199 — the ledger invariant. After the chain applies, EVERY recorded
     * migration must carry the comment-normalised md5 of its own on-disk file:
     * no NULL (a NULL row cannot detect a later edit any more than a bogus one
     * can), no all-zero sentinel, and no value belonging to some other content.
     *
     * The all-zero check is the one the step names. It is worth stating explicitly
     * because `00000000000000000000000000000000` is a well-formed CHAR(32) that
     * passes a `/^[0-9a-f]{32}$/` shape assertion while being able to match
     * nothing, so a shape-only test cannot see it.
     */
    public function testChainApplyLeavesEveryLedgerRowWithItsOwnOnDiskChecksum(): void
    {
        // S185: this asserts on a CHAIN APPLY against an empty database (and then
        // on the silence of the replay that follows it), so it drops first.
        $this->dropAllTables();

        [$applied, $warnings] = $this->runCapturingWarnings();
        self::assertNotEmpty($applied, 'the chain must actually apply against the empty DB');
        self::assertSame(
            '',
            $warnings,
            'A clean chain apply must emit NO checksum warning. Emitted: ' . $warnings,
        );

        $rows = $this->db->query('SELECT filename, checksum FROM `migrations` ORDER BY filename ASC');
        self::assertIsArray($rows);

        $files = glob(dirname(__DIR__, 3) . '/migrations/*.sql') ?: [];
        self::assertCount(
            count($files),
            $rows,
            'every migration file must have a ledger row after the chain applies',
        );

        foreach ($rows as $row) {
            self::assertIsArray($row);
            $filename = (string) ($row['filename'] ?? '');
            $recorded = $row['checksum'] ?? null;

            self::assertIsString(
                $recorded,
                "migration {$filename} carries a NULL checksum, so an edit to it cannot be detected",
            );
            self::assertNotSame(
                str_repeat('0', 32),
                $recorded,
                "migration {$filename} carries an all-zero checksum, which can never match any file",
            );

            $path = dirname(__DIR__, 3) . '/migrations/' . $filename;
            self::assertFileExists($path);
            self::assertSame(
                MigrationRunner::checksum((string) file_get_contents($path)),
                $recorded,
                "migration {$filename}'s recorded checksum must be the checksum of its own file",
            );
        }

        // Steady state: the very next run executes nothing and says nothing.
        [$replay, $replayWarnings] = $this->runCapturingWarnings();
        self::assertSame([], $replay);
        self::assertSame('', $replayWarnings, 'A replay must stay silent. Emitted: ' . $replayWarnings);
    }

    public function testDivergedChecksumTriggersReapplyAndRefresh(): void
    {
        // Reach the fully-applied, checksum-backfilled state. S185: on the shared
        // schema this is a 0.02 s replay that applies nothing; the state it leaves
        // is asserted to be identical to a fresh apply's by
        // testChainApplyLeavesEveryLedgerRowWithItsOwnOnDiskChecksum().
        $this->runner->run();

        // Simulate a rewrite-class edit: corrupt the recorded checksum so it no
        // longer matches the on-disk file. 001_users.sql is CREATE TABLE IF NOT
        // EXISTS, so re-applying it is a safe no-op — that is why THIS file is the
        // one corrupted, and it stays true whether the `users` table already
        // exists (shared schema) or not (clean apply). Do not repoint this at a
        // migration whose DDL is not re-run-safe.
        $stale = str_repeat('0', 32);
        $this->db->query(
            'UPDATE `migrations` SET checksum = :c WHERE filename = :f',
            ['c' => $stale, 'f' => '001_users.sql'],
        );

        // S199: this run PROVOKES the divergence warning on purpose, so capture it
        // and assert it instead of letting error_log() dump it on the suite's
        // stderr. That stray line is what got read as a production defect
        // ("001_users.sql's recorded checksum is all zeros") when the zeros are in
        // fact planted three lines above. Note this only redirects where PHP sends
        // error_log() for the duration of the call — MigrationRunner's warning is
        // untouched and is now ASSERTED rather than merely emitted.
        [$applied, $warnings] = $this->runCapturingWarnings();
        self::assertContains('001_users.sql', $applied, 'A diverged file must be re-applied');
        self::assertStringContainsString('checksum diverged', $warnings);
        self::assertStringContainsString('001_users.sql', $warnings);
        self::assertStringContainsString('recorded=' . $stale, $warnings);
        self::assertStringContainsString(
            'current=' . MigrationRunner::checksum(
                (string) file_get_contents(dirname(__DIR__, 3) . '/migrations/001_users.sql'),
            ),
            $warnings,
            'the warning must name the on-disk checksum it compared against',
        );

        $rows = $this->db->query(
            'SELECT checksum FROM `migrations` WHERE filename = :f',
            ['f' => '001_users.sql'],
        );
        self::assertIsArray($rows);
        $row = $rows[0] ?? null;
        self::assertIsArray($row);
        self::assertNotSame($stale, $row['checksum'] ?? null, 'The stale checksum must be refreshed');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) ($row['checksum'] ?? ''));
    }

    public function testNullChecksumBackfillsWithoutReapplying(): void
    {
        // S185: a replay on the shared schema; the assertion is about what the
        // NEXT run does to a NULL-checksum row, not about this call.
        $this->runner->run();

        // Simulate the day-one state: a recorded row with no checksum baseline.
        $this->db->query(
            'UPDATE `migrations` SET checksum = NULL WHERE filename = :f',
            ['f' => '001_users.sql'],
        );

        [$applied, $warnings] = $this->runCapturingWarnings();
        self::assertNotContains(
            '001_users.sql',
            $applied,
            'A NULL-checksum row must be backfilled, not re-applied',
        );
        self::assertSame('', $warnings, 'A NULL baseline is not a divergence. Emitted: ' . $warnings);

        $rows = $this->db->query(
            'SELECT checksum FROM `migrations` WHERE filename = :f',
            ['f' => '001_users.sql'],
        );
        self::assertIsArray($rows);
        $row = $rows[0] ?? null;
        self::assertIsArray($row);
        self::assertIsString($row['checksum'] ?? null, 'NULL checksum must be backfilled to a value');
    }

    /**
     * Run the migrator with PHP's `error_log` destination pointed at a temp file,
     * so anything {@see MigrationRunner} warns about is captured and assertable
     * rather than escaping onto the test suite's stderr.
     *
     * @return array{0: list<string>, 1: string} [files executed, captured log text]
     */
    private function runCapturingWarnings(): array
    {
        $logFile = (string) tempnam(sys_get_temp_dir(), 'phlix-hub-mig-log-');
        $previous = ini_set('error_log', $logFile);

        try {
            $applied = $this->runner->run();
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }

        $log = trim((string) @file_get_contents($logFile));
        @unlink($logFile);

        return [$applied, $log];
    }

    /**
     * S42 review fix (043_relay_user_settings): the per-user relay THROTTLE moves
     * off the period-scoped `relay_user_quotas` rollup into the durable
     * `relay_user_settings` store, and the data migration preserves each user's
     * most-recent NON-DEFAULT throttle so no admin setting is lost — proving the
     * "throttle silently reverts to 3 Mbps on the 1st of the month" bug is fixed.
     */
    public function testThrottleSettingDataMigratesFromQuotasToDurableStore(): void
    {
        $this->runner->run();

        self::assertTrue(
            $this->tableExists('relay_user_settings'),
            'relay_user_settings must exist after migration 043',
        );

        // Seed a realistic pre-existing state on the OLD period-scoped rollup:
        //  u-A: admin set 50 Mbps in an old month, then a NEW-month default row
        //       was auto-created (the exact monthly-revert scenario) — 50 Mbps
        //       must survive because we take the most-recent NON-default value.
        //  u-B: admin set Unlimited (0) — must survive as 0, not the 3 Mbps default.
        //  u-C: only ever default rows — nothing to preserve (no durable row).
        $this->insertUser('u-A', 'alice', 'a@example.com');
        $this->insertUser('u-B', 'bob', 'b@example.com');
        $this->insertUser('u-C', 'carol', 'c@example.com');

        $this->seedQuotaThrottle('u-A', '2026-05-01', 50000000);
        $this->seedQuotaThrottle('u-A', '2026-06-01', 3000000);
        $this->seedQuotaThrottle('u-B', '2026-06-01', 0);
        $this->seedQuotaThrottle('u-C', '2026-06-01', 3000000);

        // Run ONLY the data-migration statement from the shipped 043 file against
        // the now-seeded rollup — this exercises the actual SQL. (When 043 ran as
        // part of the chain it saw an empty relay_user_quotas and was a no-op, so
        // it is this explicit invocation that is under test either way.)
        $this->db->query($this->dataMigrationStatement());

        self::assertSame(
            50000000,
            $this->settingsThrottle('u-A'),
            'admin-set high cap must survive a calendar-month rollover',
        );
        self::assertSame(
            0,
            $this->settingsThrottle('u-B'),
            'Unlimited (0) must be preserved, not coerced to the 3 Mbps default',
        );
        self::assertNull(
            $this->settingsThrottle('u-C'),
            'a default-only user needs no durable row (the read falls back to the default)',
        );
    }

    private function seedQuotaThrottle(string $userId, string $periodStart, int $throttleBps): void
    {
        $this->db->query(
            'INSERT INTO relay_user_quotas (user_id, period_start, throttle_bps)'
            . ' VALUES (:user_id, :period_start, :throttle_bps)',
            ['user_id' => $userId, 'period_start' => $periodStart, 'throttle_bps' => $throttleBps],
        );
    }

    /**
     * Read a user's throttle from the durable store, or null when no row exists.
     */
    private function settingsThrottle(string $userId): ?int
    {
        $rows = $this->db->query(
            'SELECT throttle_bps FROM relay_user_settings WHERE user_id = :user_id',
            ['user_id' => $userId],
        );
        if (!is_array($rows) || $rows === []) {
            return null;
        }
        $row = $rows[0];
        if (!is_array($row) || !isset($row['throttle_bps']) || !is_numeric($row['throttle_bps'])) {
            return null;
        }
        return (int) $row['throttle_bps'];
    }

    /**
     * Extract the data-migration `INSERT INTO relay_user_settings ... SELECT ...`
     * statement from the shipped 043 file, so the test runs the real SQL rather
     * than a hand-copied duplicate that could drift.
     */
    private function dataMigrationStatement(): string
    {
        $sql = file_get_contents(dirname(__DIR__, 3) . '/migrations/043_relay_user_settings.sql');
        self::assertNotFalse($sql, 'migration 043 must be readable');

        $lines = preg_split('/\r\n|\r|\n/', $sql) ?: [];
        $kept = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*--/', $line) === 1) {
                continue; // drop full-line comments
            }
            $kept[] = $line;
        }
        $body = implode("\n", $kept);

        foreach (explode(';', $body) as $statement) {
            $trimmed = trim($statement);
            if (str_contains($trimmed, 'INSERT INTO relay_user_settings')) {
                return $trimmed;
            }
        }

        self::fail('migration 043 must contain an INSERT INTO relay_user_settings data-migration statement');
    }

    private function insertUser(string $id, string $username, string $email): void
    {
        $this->db->query(
            "INSERT INTO users (id, username, email, password_hash) VALUES (:id, :username, :email, :pwd)",
            ['id' => $id, 'username' => $username, 'email' => $email, 'pwd' => 'argon2id-placeholder'],
        );
    }

    private function tableExists(string $table): bool
    {
        $name = self::testDatabaseName();
        $rows = $this->db->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :tbl",
            ['schema' => $name, 'tbl' => $table],
        );
        return is_array($rows) && count($rows) === 1;
    }
}
