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

$runner = new MigrationRunner($db, $migrationsDir);

$files = $runner->discoverFiles();
if ($files === []) {
    echo "No SQL migrations found in {$migrationsDir}.\n";
    exit(0);
}

try {
    $ran = $runner->run();
} catch (\Throwable $e) {
    fwrite(STDERR, "Migration failed: " . $e->getMessage() . "\n");
    exit(1);
}

if ($ran === []) {
    echo "All migrations already applied. Nothing to do.\n";
    exit(0);
}

foreach ($ran as $filename) {
    echo "Applied: {$filename}\n";
}
echo "Migrations complete (" . count($ran) . " applied).\n";
