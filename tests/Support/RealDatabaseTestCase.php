<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support;

use Phlix\Hub\Common\Database\MigrationRunner;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Shared harness for the `tests/Integration/**` cases that need the REAL hub
 * schema in a REAL MySQL database.
 *
 * ## What this replaces, and why (S185)
 *
 * Every integration case used to drop every table and re-apply the whole
 * migration chain in its own `setUp()`. That is *total* isolation, and it was
 * free right up until S173 gave CI a MySQL service — from that point it sits on
 * every pull request's critical path. Measured on the dev box against MySQL
 * 8.0.46:
 *
 * | step                                     | cost      |
 * |------------------------------------------|-----------|
 * | `dropAllTables()` (29 tables)             | 3.31 s    |
 * | `MigrationRunner::run()`, empty database  | 13.3 s    |
 * | `MigrationRunner::run()`, replay          | **0.02 s**|
 * | `TRUNCATE` every table                    | 8.7 s     |
 * | `DELETE FROM` every table                 | **0.03 s**|
 *
 * So the schema is built ONCE per process and each test starts from an empty
 * data set instead of an empty database. Two numbers in that table decide the
 * design:
 *
 *  - a replay of the chain costs 0.02 s, so it is cheap enough to be part of the
 *    normal path rather than something to be avoided;
 *  - `TRUNCATE` is **not** the cheap way to empty a table. InnoDB implements it
 *    by dropping and recreating the tablespace, which is DDL and pays the same
 *    fsync as `DROP TABLE`; it is 280× more expensive here than `DELETE FROM`.
 *    The hub schema has no `AUTO_INCREMENT` column (every table is keyed by a
 *    `CHAR(36)` UUID), so the one semantic difference between the two — the
 *    counter reset — is unobservable. Verified: `SELECT TABLE_NAME FROM
 *    information_schema.TABLES WHERE TABLE_SCHEMA = … AND AUTO_INCREMENT IS NOT
 *    NULL` returns no rows after a full apply.
 *
 * ## How isolation is kept
 *
 * 🔴 The reason per-test re-application was safe is that it was total, so
 * reusing a schema has to earn that back rather than assume it:
 *
 *  1. **Data is reset before AND after every test** ({@see resetAllTableData()}),
 *     so no test can see another's rows. The `migrations` ledger is the sole
 *     exception — it is schema bookkeeping, not test data, and emptying it would
 *     make the next `MigrationRunner::run()` re-apply a chain that is already
 *     applied.
 *  2. **The cached schema is re-validated against the live database on every
 *     single `setUp()`**, not merely assumed. {@see schemaFingerprint()} hashes
 *     every column of every table out of `information_schema` (275 rows, ~2 ms)
 *     and any difference at all — a dropped table, a dropped column, a changed
 *     type — forces a full drop-and-re-apply. This is what makes reuse safe in
 *     the face of `executionOrder="random"`: sibling suites legitimately destroy
 *     the schema — `MigrationRunnerIntegrationTest` empties the database because
 *     applying the chain to an empty database is its subject — and the next test
 *     that needs a schema rebuilds it instead of running against a mutilated one.
 *  3. **Emptiness is ASSERTED, not assumed** ({@see assertTestDatabaseIsEmpty()}),
 *     so a future leak fails in the test that inherited it, by name, instead of
 *     surfacing as an intermittent red somewhere else.
 *
 * The `static` cache below is deliberately a single 32-character string. It is
 * per-PHPUnit-process, bounded, and — the point of item 2 — never trusted on its
 * own: it is compared against a freshly-read fingerprint every time, so a stale
 * value costs one rebuild rather than a wrong test result.
 *
 * ## Environment
 *
 * Gated on `HUB_TEST_DB_HOST` / `HUB_TEST_DB_NAME`; the database must already
 * exist and the user must hold full privileges on it, because every table in it
 * is dropped. ⚠ A skip here is NOT a pass — `scripts/assert-integration-tests-ran.php`
 * reds the build on any skipped test, which is exactly the S173 defect.
 *
 * @package Phlix\Hub\Tests\Support
 */
abstract class RealDatabaseTestCase extends TestCase
{
    /**
     * Fingerprint of the schema as it stood immediately after this process last
     * applied the full migration chain. `null` until the first apply.
     *
     * Never a source of truth on its own — see the class docblock, item 2.
     */
    private static ?string $migratedSchemaFingerprint = null;

    /** Live connection to the test database, opened fresh for every test. */
    protected Connection $db;

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('HUB_TEST_DB_HOST');
        $name = getenv('HUB_TEST_DB_NAME');
        if ($host === false || $host === '' || $name === false || $name === '') {
            self::markTestSkipped(
                'HUB_TEST_DB_* environment variables not set — skipping integration suite.',
            );
        }

        $this->db = new Connection(
            $host,
            (int) (getenv('HUB_TEST_DB_PORT') ?: '3306'),
            (string) (getenv('HUB_TEST_DB_USER') ?: 'root'),
            (string) (getenv('HUB_TEST_DB_PASSWORD') ?: ''),
            $name,
        );

        $this->skipOnIncompatibleCluster();
        $this->ensureMigratedSchema();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->resetAllTableData();
        }

        parent::tearDown();
    }

    /**
     * Absolute path to the repository's `migrations/` directory.
     */
    final protected static function migrationsDirectory(): string
    {
        return dirname(__DIR__, 2) . '/migrations';
    }

    /**
     * Name of the database under test (the `HUB_TEST_DB_NAME` schema).
     */
    final protected static function testDatabaseName(): string
    {
        return (string) getenv('HUB_TEST_DB_NAME');
    }

    /**
     * Guarantee that the test database holds the schema the migration chain
     * produces, and that every table in it is empty.
     *
     * Costs ~0.03 s when the schema is already intact (the common case) and a
     * full drop-and-apply — ~16 s — only when it is not, i.e. on the first test
     * of the process or after a sibling suite destroyed it.
     */
    final protected function ensureMigratedSchema(): void
    {
        if (
            self::$migratedSchemaFingerprint === null
            || $this->schemaFingerprint() !== self::$migratedSchemaFingerprint
        ) {
            $this->applyMigrationChainToEmptyDatabase();
        }

        $this->resetAllTableData();
        $this->assertTestDatabaseIsEmpty();
    }

    /**
     * Fail the test — by NAME, at its start — if any table still holds a row.
     *
     * 🔴 This is the guard the S185 landmine asks for. Reusing a schema is only
     * safe while "every test starts from an empty data set" holds, and the way
     * that assumption normally breaks is silently: a leak shows up as an
     * intermittent failure in some unrelated test on someone else's pull request,
     * because `executionOrder="random"` decides who inherits the rows. Checking
     * the precondition here turns that into a deterministic, self-describing
     * failure in the test that was actually mis-set-up.
     *
     * One round trip (a `UNION ALL` of `COUNT(*)`), ~2 ms against the empty
     * schema, so it is affordable on every single test. Table names are
     * interpolated because they come from this schema's own `information_schema`
     * catalogue, not from input, and are backtick-quoted.
     */
    final protected function assertTestDatabaseIsEmpty(): void
    {
        $tables = array_values(array_filter(
            $this->tableNames(),
            static fn (string $table): bool => $table !== MigrationRunner::TRACKING_TABLE,
        ));
        if ($tables === []) {
            return;
        }

        $selects = array_map(
            static fn (string $table): string
                => "SELECT '{$table}' AS tbl, COUNT(*) AS rows_left FROM `{$table}`",
            $tables,
        );

        $rows = $this->db->query(
            'SELECT tbl, rows_left FROM (' . implode(' UNION ALL ', $selects)
            . ') AS counts WHERE rows_left > 0 ORDER BY tbl ASC',
        );

        $leaked = [];
        foreach ((array) $rows as $row) {
            if (is_array($row)) {
                $leaked[] = (string) ($row['tbl'] ?? '?') . '=' . (string) ($row['rows_left'] ?? '?');
            }
        }

        self::assertSame(
            [],
            $leaked,
            'A real-database test must start from an EMPTY data set. These tables still hold rows: '
            . implode(', ', $leaked)
            . '. Something wrote to the test database outside a test, or a table cannot be emptied by '
            . 'RealDatabaseTestCase::resetAllTableData().',
        );
    }

    /**
     * Drop every table and re-apply the whole migration chain from scratch, then
     * record the resulting schema fingerprint as this process's reference.
     *
     * This is the ORIGINAL per-test behaviour, kept intact and still used by the
     * cases whose subject IS a clean apply.
     */
    final protected function applyMigrationChainToEmptyDatabase(): void
    {
        $this->dropAllTables();
        (new MigrationRunner($this->db, self::migrationsDirectory()))->run();
        self::$migratedSchemaFingerprint = $this->schemaFingerprint();
    }

    /**
     * md5 over every column of every table in the test schema.
     *
     * Column-level rather than table-level on purpose: a table-name list cannot
     * see a dropped column or a changed type, and "the schema looked right" is
     * precisely the assumption that has to be checked rather than believed.
     */
    final protected function schemaFingerprint(): string
    {
        $rows = $this->db->query(
            'SELECT TABLE_NAME, ORDINAL_POSITION, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE,'
            . ' COLUMN_KEY, EXTRA, COLUMN_DEFAULT'
            . ' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :schema'
            . ' ORDER BY TABLE_NAME ASC, ORDINAL_POSITION ASC',
            ['schema' => self::testDatabaseName()],
        );

        if (!is_array($rows)) {
            return '';
        }

        $canonical = '';
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($row as $key => $value) {
                $canonical .= (string) $key . '=' . (is_scalar($value) ? (string) $value : 'NULL') . "\x1f";
            }
            $canonical .= "\x1e";
        }

        return md5($canonical);
    }

    /**
     * Empty every table except the `migrations` ledger.
     *
     * `DELETE FROM` rather than `TRUNCATE`: see the cost table in the class
     * docblock. Foreign keys are disabled for the duration so the tables can be
     * emptied in arbitrary order.
     */
    final protected function resetAllTableData(): void
    {
        $tables = $this->tableNames();
        if ($tables === []) {
            return;
        }

        $this->db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $table) {
            if ($table === MigrationRunner::TRACKING_TABLE) {
                continue;
            }
            $this->db->query("DELETE FROM `{$table}`");
        }
        $this->db->query('SET FOREIGN_KEY_CHECKS=1');
    }

    /**
     * Drop every table in the test schema, leaving an empty database.
     */
    final protected function dropAllTables(): void
    {
        $tables = $this->tableNames();
        if ($tables === []) {
            return;
        }

        $this->db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $table) {
            $this->db->query("DROP TABLE IF EXISTS `{$table}`");
        }
        $this->db->query('SET FOREIGN_KEY_CHECKS=1');
    }

    /**
     * Every table currently present in the test schema.
     *
     * @return list<string>
     */
    final protected function tableNames(): array
    {
        $rows = $this->db->query(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = :schema',
            ['schema' => self::testDatabaseName()],
        );
        if (!is_array($rows)) {
            return [];
        }

        $names = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['TABLE_NAME']) || !is_string($row['TABLE_NAME'])) {
                continue;
            }
            $names[] = $row['TABLE_NAME'];
        }
        sort($names);

        return $names;
    }

    /**
     * MySQL Group Replication in multi-primary mode rejects tables with
     * `ON DELETE CASCADE` foreign keys
     * (`group_replication_enforce_update_everywhere_checks=ON`). Skip on such a
     * cluster — the hub schema targets a single-primary deployment.
     *
     * On a stock MySQL 8 server (including CI's `mysql:8.0` service) the variable
     * does not exist at all, so `SHOW VARIABLES` returns no rows and nothing is
     * skipped.
     */
    final protected function skipOnIncompatibleCluster(): void
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
}
