<?php

/**
 * Apply every SQL migration in `migrations/` against the hub database.
 *
 * Wraps {@see \Phlix\Hub\Common\Database\MigrationRunner}. The runner
 * tracks applied migrations in a `migrations` table and is therefore
 * idempotent: re-running this script after a successful apply is a
 * no-op. Set the `HUB_DB_*` environment variables to point at the
 * target database before invoking.
 *
 * HUB-5.1 (bug #12, S159 port): the exit verdict is single-sourced
 * through {@see MigrationRunner::exitCodeFor()} + the EXIT_* constants,
 * and a statement error whose driver errno is on the closed idempotent
 * list (already-applied object) is skipped-and-logged inside the runner
 * instead of failing the file — so a half-migrated database can no
 * longer redden every boot while a genuine failure still exits 1.
 * `HUB_MIGRATIONS_DIR` overrides the migrations directory (used by the
 * exit-code integration harness to plant scratch migrations; deliberately
 * NOT the server's `PHLIX_MIGRATIONS_DIR`, so a host running both products
 * cannot misdirect this one).
 *
 * @package Phlix\Hub
 */

declare(strict_types=1);

use Phlix\Hub\Common\Database\ConnectionPool;
use Phlix\Hub\Common\Database\MigrationRunner;

require_once __DIR__ . '/../vendor/autoload.php';

$configPath = __DIR__ . '/../config/database.php';
ConnectionPool::init($configPath);

$db = ConnectionPool::getConnection('mysql');
$migrationsDir = __DIR__ . '/../migrations';
$dirOverride = getenv('HUB_MIGRATIONS_DIR');
if (is_string($dirOverride) && $dirOverride !== '') {
    $migrationsDir = $dirOverride;
}

$runner = new MigrationRunner($db, $migrationsDir);

$files = $runner->discoverFiles();
if ($files === []) {
    echo "No SQL migrations found in {$migrationsDir}.\n";
    exit(MigrationRunner::EXIT_SUCCESS);
}

try {
    $ran = $runner->run();
} catch (\Throwable $e) {
    fwrite(STDERR, "Migration failed: " . $e->getMessage() . "\n");
    exit(MigrationRunner::exitCodeFor($e));
}

if ($ran === []) {
    echo "All migrations already applied. Nothing to do.\n";
    exit(MigrationRunner::EXIT_SUCCESS);
}

foreach ($ran as $filename) {
    echo "Applied: {$filename}\n";
}
echo "Migrations complete (" . count($ran) . " applied).\n";
exit(MigrationRunner::exitCodeFor(null));
