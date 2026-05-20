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
 * @since 0.2.0
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
