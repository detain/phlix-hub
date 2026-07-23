<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Integration\Migrations;

use Phlix\Hub\Common\Database\MigrationRunner;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Integration tests for the migration runner against a real MySQL test
 * database. Skipped automatically when the `HUB_TEST_DB_*` environment
 * variables are not set, so the suite stays green in environments
 * without MySQL.
 *
 * Required env vars to enable: `HUB_TEST_DB_HOST`, `HUB_TEST_DB_PORT`,
 * `HUB_TEST_DB_USER`, `HUB_TEST_DB_PASSWORD`, `HUB_TEST_DB_NAME`. The
 * named database **must already exist** and the user must have full
 * privileges on it — every table is dropped at setUp().
 *
 * @package Phlix\Hub\Tests\Integration\Migrations
 *
 * @covers \Phlix\Hub\Common\Database\MigrationRunner
 *
 * @group integration
 */
final class MigrationRunnerIntegrationTest extends TestCase
{
    private Connection $db;
    private MigrationRunner $runner;

    protected function setUp(): void
    {
        $host = getenv('HUB_TEST_DB_HOST');
        $name = getenv('HUB_TEST_DB_NAME');
        if ($host === false || $host === '' || $name === false || $name === '') {
            self::markTestSkipped(
                'HUB_TEST_DB_* environment variables not set — skipping integration suite.',
            );
        }

        $port = (int) (getenv('HUB_TEST_DB_PORT') ?: '3306');
        $user = (string) (getenv('HUB_TEST_DB_USER') ?: 'root');
        $pass = (string) (getenv('HUB_TEST_DB_PASSWORD') ?: '');

        $this->db = new Connection($host, $port, $user, $pass, $name);
        $this->skipOnIncompatibleCluster();
        $this->dropAllTables();
        $this->runner = new MigrationRunner(
            $this->db,
            dirname(__DIR__, 3) . '/migrations',
        );
    }

    /**
     * MySQL Group Replication in multi-primary mode rejects tables with
     * `ON DELETE CASCADE` foreign keys (`group_replication_enforce_update_everywhere_checks=ON`).
     * Skip the integration tests on such a cluster — the schema is
     * designed for a single-primary deployment.
     */
    private function skipOnIncompatibleCluster(): void
    {
        try {
            $rows = $this->db->query(
                "SHOW VARIABLES LIKE 'group_replication_enforce_update_everywhere_checks'",
            );
        } catch (\Throwable) {
            return;
        }
        if (!is_array($rows) || $rows === []) {
            return;
        }
        $row = $rows[0];
        $rawValue = is_array($row) && isset($row['Value']) ? $row['Value'] : '';
        $value = is_string($rawValue) ? $rawValue : '';
        if (strtoupper($value) === 'ON') {
            self::markTestSkipped(
                'Test DB runs Group Replication multi-primary (enforce_update_everywhere_checks=ON), '
                . 'which forbids CASCADE foreign keys. Schema targets a single-primary deployment.',
            );
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->dropAllTables();
        }
    }

    public function testRunsAllMigrationsInOrderAgainstEmptyDb(): void
    {
        $applied = $this->runner->run();

        self::assertSame(
            [
                '001_users.sql',
                '002_servers.sql',
                '003_shared_libraries.sql',
                '004_relay_sessions.sql',
                '005_webhooks.sql',
                '006_server_heartbeats_sent_at.sql',
            ],
            $applied,
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
        $first = $this->runner->run();
        self::assertCount(6, $first);

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
        $this->db->query(
            "INSERT INTO servers (id, user_id, server_name, version, jwks_json, hostname_candidates_json, status)"
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

        $name = (string) getenv('HUB_TEST_DB_NAME');
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
        $this->runner->run();

        // Migration 041 adds the checksum column; a second run backfills the
        // earlier files that were recorded before the column existed.
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

    public function testDivergedChecksumTriggersReapplyAndRefresh(): void
    {
        // Fully apply + backfill checksums.
        $this->runner->run();
        $this->runner->run();

        // Simulate a rewrite-class edit: corrupt the recorded checksum so it no
        // longer matches the on-disk file. 001_users.sql is CREATE TABLE IF NOT
        // EXISTS, so re-applying it is a safe no-op.
        $stale = str_repeat('0', 32);
        $this->db->query(
            'UPDATE `migrations` SET checksum = :c WHERE filename = :f',
            ['c' => $stale, 'f' => '001_users.sql'],
        );

        $applied = $this->runner->run();
        self::assertContains('001_users.sql', $applied, 'A diverged file must be re-applied');

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
        $this->runner->run();
        $this->runner->run();

        // Simulate the day-one state: a recorded row with no checksum baseline.
        $this->db->query(
            'UPDATE `migrations` SET checksum = NULL WHERE filename = :f',
            ['f' => '001_users.sql'],
        );

        $applied = $this->runner->run();
        self::assertNotContains(
            '001_users.sql',
            $applied,
            'A NULL-checksum row must be backfilled, not re-applied',
        );

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

        // The initial run() applied the data migration when quotas was empty (a
        // no-op). Re-run ONLY the data-migration statement from the shipped 043
        // file against the now-seeded rollup — this exercises the actual SQL.
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
        $name = (string) getenv('HUB_TEST_DB_NAME');
        $rows = $this->db->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :tbl",
            ['schema' => $name, 'tbl' => $table],
        );
        return is_array($rows) && count($rows) === 1;
    }

    private function dropAllTables(): void
    {
        $name = (string) getenv('HUB_TEST_DB_NAME');
        $rows = $this->db->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = :schema",
            ['schema' => $name],
        );
        if (!is_array($rows)) {
            return;
        }
        $this->db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['TABLE_NAME']) || !is_string($row['TABLE_NAME'])) {
                continue;
            }
            $table = $row['TABLE_NAME'];
            $this->db->query("DROP TABLE IF EXISTS `{$table}`");
        }
        $this->db->query('SET FOREIGN_KEY_CHECKS=1');
    }
}
