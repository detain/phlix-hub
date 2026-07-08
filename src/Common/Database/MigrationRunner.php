<?php

/**
 * Phlix hub component: Database.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Common\Database;

use RuntimeException;
use Throwable;
use Workerman\MySQL\Connection;

/**
 * Applies SQL migrations in lexicographic order against a MySQL database.
 *
 * Migration files live under `migrations/` and use the
 * `NNN_description.sql` naming convention. The runner is idempotent: it
 * records every applied file in a tracking table (`migrations`) keyed by
 * filename, and skips files that already appear there on subsequent
 * runs.
 *
 * Each `.sql` file may contain multiple statements separated by `;`.
 * Single-line `--` comments and blank lines are stripped before
 * splitting. The tracking table is the sole idempotency mechanism;
 * migrations themselves use plain MySQL 8 / MariaDB-portable DDL
 * (e.g. `CREATE TABLE IF NOT EXISTS` for tables, plain `ALTER` for
 * column/index changes — MariaDB's `ADD COLUMN IF NOT EXISTS` is
 * not portable to MySQL 8).
 *
 * @package Phlix\Hub\Common\Database
 */
class MigrationRunner
{
    public const string TRACKING_TABLE = 'migrations';

    /**
     * @param Connection $db             Live MySQL connection.
     * @param string     $migrationsDir  Absolute path to the migrations directory.
     */
    public function __construct(
        private readonly Connection $db,
        private readonly string $migrationsDir,
    ) {
    }

    /**
     * Apply every migration file that has not yet been recorded in the
     * tracking table. Returns the list of files that were applied (in
     * order). Skipped files do not appear in the return value.
     *
     * @return list<string> Filenames (basename only) of newly applied migrations.
     */
    public function run(): array
    {
        $this->ensureTrackingTable();
        $applied = $this->listApplied();
        $files = $this->discoverFiles();
        $ranNow = [];

        foreach ($files as $file) {
            $basename = basename($file);
            if (in_array($basename, $applied, true)) {
                continue;
            }
            $this->applyFile($file);
            $this->recordApplied($basename);
            $ranNow[] = $basename;
        }

        return $ranNow;
    }

    /**
     * Discover migration files in lexicographic order.
     *
     * @return list<string> Absolute paths to `*.sql` files.
     */
    public function discoverFiles(): array
    {
        $files = glob($this->migrationsDir . '/*.sql');
        if ($files === false) {
            return [];
        }
        sort($files);
        return $files;
    }

    /**
     * Return the basenames of every migration recorded in the tracking
     * table.
     *
     * @return list<string>
     */
    public function listApplied(): array
    {
        /** @var mixed $rows */
        $rows = $this->db->query(
            'SELECT filename FROM `' . self::TRACKING_TABLE . '` ORDER BY filename ASC'
        );
        if (!is_array($rows)) {
            return [];
        }
        $names = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['filename']) || !is_string($row['filename'])) {
                continue;
            }
            $names[] = $row['filename'];
        }
        return $names;
    }

    /**
     * Create the tracking table if it does not exist yet.
     */
    public function ensureTrackingTable(): void
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . self::TRACKING_TABLE . '` ('
            . ' filename    VARCHAR(255) NOT NULL,'
            . ' applied_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . ' PRIMARY KEY (filename)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $this->db->query($sql);
    }

    /**
     * Apply every non-comment statement in a single SQL file.
     *
     * @param string $file Absolute path to the migration file.
     */
    private function applyFile(string $file): void
    {
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException("Unable to read migration: {$file}");
        }
        $statements = $this->splitStatements($sql);
        foreach ($statements as $statement) {
            try {
                $this->db->query($statement);
            } catch (Throwable $e) {
                throw new RuntimeException(
                    sprintf(
                        "Migration %s failed: %s\nStatement:\n%s",
                        basename($file),
                        $e->getMessage(),
                        $statement,
                    ),
                    0,
                    $e,
                );
            }
        }
    }

    /**
     * Split a SQL blob into individual executable statements on `;`
     * boundaries, correctly ignoring any `;` that appears inside a string
     * literal (single/double quoted), a backtick-quoted identifier, a line
     * comment (`-- ...` or `# ...`) or a C-style block comment.
     *
     * A naive `explode(';', $sql)` shreds statements whose column COMMENT text
     * contains a semicolon — e.g. migration 032's
     * `COMMENT 'Hard expiry; the token is invalid once this passes'` — leaving
     * the DDL truncated mid-string and failing with a 1064 syntax error. This
     * single-pass scanner is quote/comment aware so such files
     * apply cleanly. Comment text is stripped; string/identifier contents
     * (including any embedded `;`) are preserved verbatim.
     *
     * @return list<string>
     */
    private function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $len = strlen($sql);
        // Current lexical context: '' (top level), "'"/'"'/'`' (in quote),
        // '--' (line comment) or '/*' (block comment).
        $context = '';

        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';

            switch ($context) {
                case "'":
                case '"':
                case '`':
                    $buffer .= $ch;
                    if ($ch === $context && $next === $context) {
                        // Doubled quote ('' / "" / ``) — an escaped quote.
                        $buffer .= $next;
                        $i++;
                    } elseif ($ch === '\\' && $context !== '`' && $next !== '') {
                        // Backslash escape inside a string literal.
                        $buffer .= $next;
                        $i++;
                    } elseif ($ch === $context) {
                        $context = '';
                    }
                    break;

                case '--':
                    // Line comment: drop characters until end of line.
                    if ($ch === "\n") {
                        $buffer .= $ch;
                        $context = '';
                    }
                    break;

                case '/*':
                    // Block comment: drop characters until the closing */.
                    if ($ch === '*' && $next === '/') {
                        $i++;
                        $context = '';
                    }
                    break;

                default:
                    if ($ch === '-' && $next === '-') {
                        $context = '--';
                        $i++;
                    } elseif ($ch === '#') {
                        // MySQL '#' line comment.
                        $context = '--';
                    } elseif ($ch === '/' && $next === '*') {
                        $context = '/*';
                        $i++;
                    } elseif ($ch === "'" || $ch === '"' || $ch === '`') {
                        $context = $ch;
                        $buffer .= $ch;
                    } elseif ($ch === ';') {
                        $trimmed = trim($buffer);
                        if ($trimmed !== '') {
                            $statements[] = $trimmed;
                        }
                        $buffer = '';
                    } else {
                        $buffer .= $ch;
                    }
                    break;
            }
        }

        $trimmed = trim($buffer);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }

    /**
     * Record the given migration filename as applied.
     */
    private function recordApplied(string $filename): void
    {
        // workerman/mysql `bindMore` keys on the array keys; use named
        // placeholders to avoid the 0-based positional binding mismatch.
        $this->db->query(
            'INSERT INTO `' . self::TRACKING_TABLE . '` (filename) VALUES (:filename)',
            ['filename' => $filename],
        );
    }
}
