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
 * ## Checksum-divergence detection (HB-4.11)
 *
 * Beyond name-only tracking, the runner records a comment-normalised md5
 * `checksum` (see {@see self::checksum()}) of every applied file. On a later
 * run each already-recorded file is compared against its on-disk checksum:
 *
 *   - recorded + checksum matches → skipped without executing (steady state);
 *   - recorded + checksum DIVERGED (the `.sql` was edited after it was applied,
 *     a "rewrite-class" migration) → a WARNING is emitted and the file is
 *     re-applied, then its recorded checksum is refreshed. This matches the
 *     project's "re-run safe" migration contract;
 *   - recorded but checksum IS NULL (a row that predates HB-4.11's `checksum`
 *     column — hub has ~40 such production rows) → treated as "recorded, no
 *     baseline yet": the checksum is BACKFILLED from the current on-disk file
 *     WITHOUT re-executing the migration (the row's mere existence proves it was
 *     already applied), so the day-one column addition never forces a mass
 *     re-apply;
 *   - un-recorded → applied and recorded with its checksum.
 *
 * The `checksum` column (migration `041_migrations_checksum.sql`) is nullable and
 * may be absent entirely on a fresh database until that migration runs; the
 * read/record helpers below degrade to name-only behaviour when the column is
 * missing so a fresh boot still works, backfilling checksums on the next run.
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
     * tracking table, plus re-apply any recorded file whose checksum has
     * diverged (HB-4.11). Returns the list of files whose statements were
     * EXECUTED this run (newly applied + re-applied), in order.
     *
     * A recorded file that is unchanged (checksum matches) is skipped without
     * executing; a recorded file with a NULL checksum (pre-HB-4.11 row) has its
     * checksum backfilled from disk WITHOUT re-executing. Neither appears in the
     * return value.
     *
     * @return list<string> Filenames (basename only) of migrations executed this run.
     */
    public function run(): array
    {
        $this->ensureTrackingTable();
        $ledger = $this->loadLedger();
        $files = $this->discoverFiles();
        $ranNow = [];

        foreach ($files as $file) {
            $basename = basename($file);
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException("Unable to read migration: {$file}");
            }
            $checksum = self::checksum($sql);

            // Use array_key_exists (not isset): a recorded row may have a NULL
            // checksum and isset() would wrongly report it as un-recorded.
            if (array_key_exists($basename, $ledger)) {
                $recorded = $ledger[$basename];

                if ($recorded === null) {
                    // Recorded but no baseline checksum yet — a pre-HB-4.11 row.
                    // The row's existence already proves the migration was
                    // applied, so backfill its checksum from disk WITHOUT
                    // re-executing. This is what keeps the day-one column
                    // addition from forcing a mass re-apply of hub's existing
                    // migrations.
                    $this->backfillChecksum($basename, $checksum);
                    continue;
                }

                if ($recorded === $checksum) {
                    // Recorded and unchanged — skip without executing.
                    continue;
                }

                // Recorded but the file content changed since it was applied
                // (a rewrite-class edit). Per the "re-run safe" contract we do
                // NOT hard-fail: warn and re-apply, then refresh the checksum
                // (the ON DUPLICATE KEY UPDATE in recordApplied() below).
                $this->warnDivergence($basename, $recorded, $checksum);
            }

            $this->applyFile($file);
            $this->recordApplied($basename, $checksum);
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
     * Load the applied-migrations ledger as a `filename => checksum` map.
     *
     * The checksum may be `null` for a row recorded before HB-4.11 added the
     * column (or for any row whose checksum has not been backfilled yet). If the
     * `checksum` column does not exist at all — a fresh database before
     * migration `041_migrations_checksum.sql` has run — the read degrades to
     * name-only (every recorded file maps to `null`), so those files are
     * recognised as applied and NOT re-run. Any other read failure degrades to
     * an empty map.
     *
     * @return array<string, string|null>
     */
    private function loadLedger(): array
    {
        try {
            /** @var mixed $rows */
            $rows = $this->db->query(
                'SELECT filename, checksum FROM `' . self::TRACKING_TABLE . '`'
                . ' ORDER BY filename ASC',
            );
        } catch (Throwable $e) {
            if (!self::isMissingChecksumColumnError($e)) {
                // Genuine read failure — treat the ledger as empty. The tracking
                // table was just ensured above, so this is highly unlikely.
                return [];
            }
            // Pre-041 schema: the checksum column is absent. Fall back to a
            // name-only read so recorded files are still recognised (mapped to
            // a null checksum → backfilled once the column lands), never
            // re-applied wholesale.
            /** @var mixed $rows */
            $rows = $this->db->query(
                'SELECT filename FROM `' . self::TRACKING_TABLE . '` ORDER BY filename ASC',
            );
        }

        if (!is_array($rows)) {
            return [];
        }

        $ledger = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['filename']) || !is_string($row['filename'])) {
                continue;
            }
            $checksum = null;
            if (array_key_exists('checksum', $row) && is_string($row['checksum'])) {
                $checksum = $row['checksum'];
            }
            $ledger[$row['filename']] = $checksum;
        }

        return $ledger;
    }

    /**
     * Record (or, on divergence, refresh) the given migration filename and its
     * checksum. Uses `ON DUPLICATE KEY UPDATE` so a re-applied rewrite-class
     * migration refreshes its stored checksum in place.
     *
     * If the `checksum` column does not exist yet (a fresh database before
     * migration `041_migrations_checksum.sql` runs — earlier files in the same
     * run are recorded before it), fall back to a name-only insert; the checksum
     * is backfilled on the next run once the column exists.
     */
    private function recordApplied(string $filename, ?string $checksum): void
    {
        // workerman/mysql `bindMore` keys on the array keys; use named
        // placeholders (colon-free array keys) to avoid the 0-based positional
        // binding mismatch — see .claude/rules/database-queries.md.
        try {
            $this->db->query(
                'INSERT INTO `' . self::TRACKING_TABLE . '` (filename, checksum)'
                . ' VALUES (:filename, :checksum)'
                . ' ON DUPLICATE KEY UPDATE checksum = VALUES(checksum)',
                ['filename' => $filename, 'checksum' => $checksum],
            );
        } catch (Throwable $e) {
            if (!self::isMissingChecksumColumnError($e)) {
                throw $e;
            }
            // Pre-041 schema: record the filename only; the checksum backfills
            // on the next run once the column exists.
            $this->db->query(
                'INSERT INTO `' . self::TRACKING_TABLE . '` (filename) VALUES (:filename)'
                . ' ON DUPLICATE KEY UPDATE filename = VALUES(filename)',
                ['filename' => $filename],
            );
        }
    }

    /**
     * Backfill the checksum of an already-recorded migration WITHOUT
     * re-executing it. Used for pre-HB-4.11 rows whose checksum is NULL: the row
     * already proves the migration was applied, so we only stamp the current
     * on-disk checksum as the baseline for future divergence detection.
     *
     * Failure-tolerant: if the checksum column is not present yet (a fresh
     * database before migration `041` runs this same pass), the backfill is a
     * no-op and simply happens on the next run.
     */
    private function backfillChecksum(string $filename, string $checksum): void
    {
        try {
            $this->db->query(
                'UPDATE `' . self::TRACKING_TABLE . '` SET checksum = :checksum'
                . ' WHERE filename = :filename',
                ['filename' => $filename, 'checksum' => $checksum],
            );
        } catch (Throwable) {
            // Column not present yet, or a transient write failure — the row is
            // already recorded, so a missing backfill only means it is retried
            // (still without re-executing) on the next run.
        }
    }

    /**
     * Emit a warning that an already-applied migration's checksum diverged and
     * it is being re-applied. Written via `error_log()` to keep this class's
     * minimal-dependency style — migrations run one-shot from the CLI
     * (`bin/phlix migrate` / `scripts/run-migrations.php`), which do not
     * initialise {@see \Phlix\Hub\Common\Logger\LoggerFactory}; in that context
     * `error_log()` surfaces the warning on stderr (or the configured log).
     */
    private function warnDivergence(string $filename, string $recorded, string $current): void
    {
        error_log(sprintf(
            '[phlix-hub][MigrationRunner] WARNING: migration %s checksum diverged '
            . '(recorded=%s current=%s); re-applying (re-run-safe).',
            $filename,
            $recorded,
            $current,
        ));
    }

    /**
     * Compute the ledger checksum of a migration file's contents.
     *
     * The hash is taken over a NORMALISED form of the file: full-line `--` / `#`
     * comments and per-line trailing whitespace are stripped before hashing.
     * This means a documentation-only edit to a `.sql` (e.g. tweaking a header
     * comment) does NOT flip the checksum and trigger a spurious one-time
     * re-apply on the next boot.
     *
     * The normalisation is deliberately narrow and CANNOT mask a real SQL
     * change: it only drops lines that are ENTIRELY a comment (after leading
     * whitespace) and per-line trailing whitespace. Any change to an actual SQL
     * token — including an inline `-- ...` comment appended to a real statement
     * line — is preserved in the hash, so a genuine edit still diverges the
     * checksum and re-applies (safely, migrations are re-run-safe).
     *
     * Ported verbatim (algorithm) from phlix-server's MigrationRunner (SV-4.9).
     */
    public static function checksum(string $sql): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $sql);
        if ($lines === false) {
            return md5($sql);
        }

        $kept = [];
        foreach ($lines as $line) {
            // Drop full-line `--` / `#` comments (after any leading whitespace).
            if (preg_match('/^\s*(--|#)/', $line) === 1) {
                continue;
            }
            $kept[] = rtrim($line);
        }

        return md5(implode("\n", $kept));
    }

    /**
     * Whether a thrown error indicates the `migrations.checksum` column does not
     * exist yet — the transient state on a fresh database (or an existing hub
     * before migration `041_migrations_checksum.sql` runs). MySQL reports this
     * as "Unknown column 'checksum' in 'field list'". Used to degrade the
     * checksum read/write to name-only rather than aborting.
     */
    private static function isMissingChecksumColumnError(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'unknown column') && str_contains($message, 'checksum');
    }
}
