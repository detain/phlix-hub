<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Integration\Common\Database;

use Phlix\Hub\Tests\Support\Narrow;
use Phlix\Hub\Tests\Support\RealDatabaseTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * HUB-5.1 (bug #12): the migration exit-code law, proven end-to-end in a
 * REAL child process against a REAL database — the hub mirror of the
 * server's `MigrationFailureVisibilityTest` (S159).
 *
 * The law has both polarities:
 *
 *  - a genuine statement failure exits NON-ZERO, names the failing file,
 *    and leaves it UNRECORDED (and the hub contract additionally stops the
 *    chain: unlike the server's continue-and-report loop, the hub runner
 *    throws on the first real failure — the behavior `scripts/run-migrations.php`
 *    and the hub CI comment both promise);
 *  - an already-applied idempotent code (the closed 1050/1060/1061/1091
 *    list) exits ZERO with the file recorded, its skip LOUDLY logged — the
 *    divergence-replay re-apply completes instead of reddening every boot
 *    with a 1060 it can never get past.
 *
 * The forged-phrase trap is the anti-regression that decided S159 itself:
 * a real 1064 whose echoed SQL text merely CONTAINS 'already exists' must
 * exit 1, not be swallowed by phrase matching.
 *
 * Why a subprocess: the exit code is the artifact under test. Only a
 * child process run of the actual entry point can prove that
 * `scripts/run-migrations.php` hands the operator the verdict — the bug
 * was never inside `MigrationRunner::run()`, it was in how the outcome
 * crossed the process boundary.
 *
 * The child gets an EXPLICIT env (no inheritance): the scratch migrations
 * dir via `HUB_MIGRATIONS_DIR`, and the database coordinates mapped from
 * this test's `HUB_TEST_DB_*` venue onto the operator-facing `HUB_DB_*`
 * names the config reads. Pooling is switched off for the harness — the
 * child is a single sequential CLI apply, and one connection is the
 * cheapest honest shape.
 *
 * Every scratch file is named `hub5s159_<tag>_…` and every table it
 * creates is prefixed likewise; tearDown removes both, so repeated runs
 * are deterministic.
 *
 * @package Phlix\Hub\Tests\Integration\Common\Database
 */
#[Group('integration')]
final class MigrationFailureVisibilityTest extends RealDatabaseTestCase
{
    /** @var list<string> */
    private array $scratchDirs = [];

    /** @var list<string> */
    private array $createdTables = [];

    protected function tearDown(): void
    {
        foreach ($this->createdTables as $table) {
            /** @var string $table */
            $this->db->query('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $this->createdTables = [];

        $this->db->query(
            'DELETE FROM `migrations` WHERE filename LIKE :pattern',
            ['pattern' => 'hub5s159\\_%']
        );

        foreach ($this->scratchDirs as $dir) {
            foreach (glob($dir . '/*.sql') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
        $this->scratchDirs = [];

        parent::tearDown();
    }

    public function testCleanApplyExitsZeroAndRecordsThenSteadyRerunExitsZero(): void
    {
        $tag = uniqid();
        $t1 = 'hub5s159_t1_' . $tag;
        $t2 = 'hub5s159_t2_' . $tag;
        $this->createdTables[] = $t1;
        $this->createdTables[] = $t2;

        $dir = $this->scratchDir([
            "hub5s159_{$tag}_01.sql" => "CREATE TABLE {$t1} (id INT PRIMARY KEY);\n",
            "hub5s159_{$tag}_02.sql" => "CREATE TABLE {$t2} (id INT PRIMARY KEY);\n",
        ]);

        $first = $this->runMigrationsScript($dir);
        self::assertSame(
            0,
            $first['exit'],
            'a clean apply must exit 0 — stdout: ' . $first['out'] . ' stderr: ' . $first['err']
        );
        self::assertStringContainsString('Migrations complete (2 applied)', $first['out']);
        self::assertSame(
            ["hub5s159_{$tag}_01.sql", "hub5s159_{$tag}_02.sql"],
            $this->ledgerRows(),
            'both files must be recorded'
        );
        self::assertContains($t1, $this->tableNames());
        self::assertContains($t2, $this->tableNames());

        $second = $this->runMigrationsScript($dir);
        self::assertSame(0, $second['exit']);
        self::assertStringContainsString('All migrations already applied', $second['out']);
    }

    public function testGenuineFailureExitsOneStopsTheChainAndLeavesFilesUnrecorded(): void
    {
        $tag = uniqid();
        $t1 = 'hub5s159_ok_' . $tag;
        $t2 = 'hub5s159_after_' . $tag;
        $this->createdTables[] = $t1;
        $this->createdTables[] = $t2;

        $dir = $this->scratchDir([
            "hub5s159_{$tag}_01_fails.sql" =>
                "CREATE TABLE {$t1} (id INT PRIMARY KEY);\nINSERT INTO {$t1} (missing_col) VALUES (1);\n",
            "hub5s159_{$tag}_02_after.sql" => "CREATE TABLE {$t2} (id INT PRIMARY KEY);\n",
        ]);

        $run = $this->runMigrationsScript($dir);
        self::assertSame(1, $run['exit'], 'a genuine statement error must exit 1 — stdout: ' . $run['out']);
        self::assertStringContainsString('Migration failed', $run['err']);
        self::assertStringContainsString('missing_col', $run['err'], 'the driver text must reach the operator');

        self::assertSame([], $this->ledgerRows(), 'a failed file is never recorded (contract (c))');
        $tables = $this->tableNames();
        self::assertContains(
            $t1,
            $tables,
            'statements that succeeded inside a failed file stay applied (no transaction wrapper around a hub '
            . 'migration) — the LAW is that the file itself never records and the chain stops, which is what '
            . 'forces the operator to fix and re-run'
        );
        self::assertNotContains(
            $t2,
            $tables,
            'the file after a failure must not run (stop-on-first, per the hub entry-point contract)'
        );
    }

    public function testAlreadyAppliedIdempotentCodeExitsZeroRecordsAndLogsLoudly(): void
    {
        $tag = uniqid();
        $t1 = 'hub5s159_dup_' . $tag;
        $this->createdTables[] = $t1;

        $column = 'c_' . $tag;

        $dir = $this->scratchDir([
            "hub5s159_{$tag}_01_create.sql" => "CREATE TABLE {$t1} (id INT PRIMARY KEY);\n",
            "hub5s159_{$tag}_02_addcol.sql" => "ALTER TABLE {$t1} ADD COLUMN {$column} INT;\n",
        ]);

        $first = $this->runMigrationsScript($dir);
        self::assertSame(0, $first['exit'], 'setup apply: stdout: ' . $first['out'] . ' stderr: ' . $first['err']);

        // A THIRD file re-adds the SAME column under a NEW name — a genuine
        // 1060 on an un-recorded file (the fresh-replay shape of the
        // diverged re-apply path). Under the S159 law this is a skip-and-log,
        // not a failure: exit 0, file recorded, loud note on stderr.
        $extra = $this->scratchDir([
            "hub5s159_{$tag}_03_replay.sql" => "ALTER TABLE {$t1} ADD COLUMN {$column} INT;\n",
        ]);
        $replay = $this->runMigrationsScript($extra);
        self::assertSame(
            0,
            $replay['exit'],
            'the 1060 replay must exit 0 — stdout: ' . $replay['out'] . ' stderr: ' . $replay['err']
        );
        self::assertStringContainsString('Migrations complete (1 applied)', $replay['out']);
        self::assertStringContainsString(
            'already applied',
            $replay['err'],
            'the skip must be loud on the operator channel'
        );
        self::assertStringContainsString(
            '1060',
            $replay['err'],
            'the loud skip must name the driver code it classified'
        );
        self::assertSame(
            ["hub5s159_{$tag}_01_create.sql", "hub5s159_{$tag}_02_addcol.sql", "hub5s159_{$tag}_03_replay.sql"],
            $this->ledgerRows(),
            'the squelched file records normally'
        );
    }

    public function testDivergedReplayCompletesRefreshesChecksumAndExitsZero(): void
    {
        $tag = uniqid();
        $t1 = 'hub5s159_div_' . $tag;
        $this->createdTables[] = $t1;

        // D1: the file first ships as a create + add-column (both apply, recorded).
        $dir = $this->scratchDir([
            "hub5s159_{$tag}_01.sql" =>
                "CREATE TABLE {$t1} (id INT PRIMARY KEY);\nALTER TABLE {$t1} ADD COLUMN col_x INT;\n",
        ]);
        $first = $this->runMigrationsScript($dir);
        self::assertSame(0, $first['exit'], 'setup apply: stdout: ' . $first['out'] . ' stderr: ' . $first['err']);

        // D2: the file is then EDITED in place (a rewrite-class divergence)
        // keeping the already-present ALTER. The next boot warns, re-applies,
        // meets 1060 on the replayed statement — and must complete: skip
        // loudly, exit 0, refresh the checksum. Pre-law this permanent-red
        // loop was exactly what every boot re-failed.
        file_put_contents(
            $dir . "/hub5s159_{$tag}_01.sql",
            "CREATE TABLE {$t1} (id INT PRIMARY KEY);\n"
            . "ALTER TABLE {$t1} ADD COLUMN col_x INT;\n"
            . "ALTER TABLE {$t1} ADD COLUMN col_y_{$tag} INT;\n"
        );

        $second = $this->runMigrationsScript($dir);
        self::assertSame(
            0,
            $second['exit'],
            'diverged replay must exit 0 — stdout: ' . $second['out'] . ' stderr: ' . $second['err']
        );
        self::assertStringContainsString('diverged', $second['err'], 'the divergence warning must fire');
        self::assertStringContainsString(
            'already applied',
            $second['err'],
            'the 1060 replayed statement must be skipped loudly'
        );
        self::assertStringContainsString('Migrations complete (1 applied)', $second['out']);

        // The file recorded; the new column exists (later statements ran);
        // and the ledger checksum equals the current on-disk checksum.
        self::assertSame(["hub5s159_{$tag}_01.sql"], $this->ledgerRows());
        self::assertStringContainsString('col_y_' . $tag, $this->createTableSql($t1));
        $third = $this->runMigrationsScript($dir);
        self::assertSame(0, $third['exit']);
        self::assertStringContainsString(
            'All migrations already applied',
            $third['out'],
            'the refreshed checksum makes the third run a clean skip'
        );
    }

    public function testForgedAlreadyExistsPhraseExitsOneAndStaysUnrecorded(): void
    {
        $tag = uniqid();
        $bad = 'hub5s159_forge_' . $tag;

        $dir = $this->scratchDir([
            // 1064 syntax failure whose echoed SQL carries the literal
            // phrase 'already exists'. The statement-exact strip leaves the
            // real segment (errno 1064) — outside the closed list — so this
            // must FAIL, proving the classifier reads the driver verdict and
            // not the payload's vocabulary. (Measured server-side, the naive
            // phrase match once classed a 1146 of this exact shape as
            // idempotent and RECORDED it: worse than the bug S159 replaced.)
            "hub5s159_{$tag}_01.sql" =>
                "CREATE TABLE {$bad} (already exists INT) COMMENT 'this table already exists';\n",
        ]);

        $run = $this->runMigrationsScript($dir);
        self::assertSame(
            1,
            $run['exit'],
            'a 1064 wearing an "already exists" costume must still exit 1 — '
            . 'stdout: ' . $run['out'] . ' stderr: ' . $run['err']
        );
        self::assertStringContainsString('1064', $run['err']);
        self::assertSame([], $this->ledgerRows(), 'the decoyed file must not be recorded');
    }

    /**
     * @param array<string,string> $files filename => SQL content
     */
    private function scratchDir(array $files): string
    {
        $dir = sys_get_temp_dir() . '/phlix-hub-mig-harness-' . uniqid();
        mkdir($dir, 0o777, true);
        $this->scratchDirs[] = $dir;
        foreach ($files as $name => $sql) {
            file_put_contents($dir . '/' . $name, $sql);
        }

        return $dir;
    }

    /**
     * Run the real entry point in a real child process and capture its
     * verdict.
     *
     * @return array{exit: int, out: string, err: string}
     */
    private function runMigrationsScript(string $migrationsDir): array
    {
        $root = dirname(__DIR__, 4);
        $env = [
            'PATH' => (string) (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
            'HOME' => (string) (getenv('HOME') ?: '/tmp'),
            'HUB_MIGRATIONS_DIR' => $migrationsDir,
            'HUB_DB_HOST' => (string) (getenv('HUB_TEST_DB_HOST') ?: '127.0.0.1'),
            'HUB_DB_PORT' => (string) (getenv('HUB_TEST_DB_PORT') ?: '3306'),
            'HUB_DB_USER' => (string) (getenv('HUB_TEST_DB_USER') ?: 'root'),
            'HUB_DB_PASSWORD' => (string) (getenv('HUB_TEST_DB_PASSWORD') ?: ''),
            'HUB_DB_NAME' => $this->testDatabaseName(),
            'DB_POOL_ENABLED' => '0',
        ];

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open([PHP_BINARY, 'scripts/run-migrations.php'], $descriptors, $pipes, $root, $env);
        self::assertIsResource($process, 'the migration entry point must be launchable for the exit-code proof');

        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return ['exit' => $exit, 'out' => $out, 'err' => $err];
    }

    /**
     * Basenames of this harness's ledger rows, in apply order.
     *
     * @return list<string>
     */
    private function ledgerRows(): array
    {
        $rows = Narrow::rows(
            $this->db->query(
                'SELECT filename FROM `migrations` WHERE filename LIKE :pattern ORDER BY filename',
                ['pattern' => 'hub5s159\\_%']
            )
        );

        return array_values(array_map(
            static fn (array $row): string => Narrow::string($row['filename']),
            $rows
        ));
    }

    /**
     * The live CREATE TABLE definition of a probe table (to prove which
     * statements of a replayed file actually landed).
     */
    private function createTableSql(string $table): string
    {
        $rows = Narrow::rows($this->db->query('SHOW CREATE TABLE `' . $table . '`'));
        self::assertNotEmpty($rows, 'SHOW CREATE TABLE must return the definition');
        $row = Narrow::arr($rows[0]);

        return Narrow::castString($row['Create Table'] ?? reset($row));
    }
}
