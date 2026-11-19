<?php

/**
 * Phlix hub component: Database.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
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
 * Comments (`--`, `#` and C-style block) are stripped by a quote-aware
 * scanner before splitting; the checksum, by contrast, drops only
 * whole-line comments. The tracking table is the sole idempotency mechanism;
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
 * missing so a fresh boot still works. Any checksum that could not be written for
 * that reason is DEFERRED and re-written at the end of the same {@see run()} — by
 * then migration 041 has added the column — so a SINGLE chain apply leaves every
 * ledger row carrying a real checksum. Before that flush existed, a fresh (or
 * newly-upgraded) hub finished its first apply with 26 of 29 rows at
 * `checksum IS NULL`, so divergence detection was dead for all of them until some
 * later run happened to backfill: nothing in the deploy path guarantees a second
 * run, and an edit landing inside that window is swallowed by the NULL branch
 * (backfilled as the new baseline, never re-applied).
 *
 * @package Phlix\Hub\Common\Database
 *
 * ## Idempotent replay law (HUB-5.1, bug #12 — S159 port)
 *
 * The server's S159 law, mirrored here: a statement error whose driver
 * errno is one of the closed {@see self::IDEMPOTENT_ERROR_CODES} set —
 * "the object this statement creates is already there" — is NOT a failed
 * migration. {@see self::applyFile()} classifies such errors through
 * {@see self::isAlreadyAppliedNote()} (statement-exact message parsing,
 * never raw phrase matching), logs the skip loudly via error_log, and
 * continues, so the file completes and its ledger row (and checksum)
 * records normally. Every other error keeps today's throw-stop contract:
 * the file stays unrecorded and the failure surfaces. This is the law
 * that makes the documented "re-run-safe" divergence re-apply actually
 * re-run-safe — before it, a recorded file whose edited DDL repeated an
 * already-present column threw 1060 on every boot, could never refresh
 * its checksum, and reddened the hub permanently (the exact opposite
 * polarity of the pre-S159 server bug, where the same failure was
 * silently reported as success).
 *
 * The process verdict itself is single-sourced through
 * {@see self::EXIT_SUCCESS}/{@see self::EXIT_FAILURE} +
 * {@see self::exitCodeFor()} so `scripts/run-migrations.php` and
 * `bin/phlix migrate` cannot drift apart.
 */
class MigrationRunner
{
    public const string TRACKING_TABLE = 'migrations';

    /**
     * Process verdict constants — the single source of truth both migration
     * entry points exit with. Values match `Symfony\Component\Console\Command\Command::SUCCESS`/
     * `::FAILURE` (0/1) by design; the names, not the numerals, are the law.
     */
    public const int EXIT_SUCCESS = 0;
    public const int EXIT_FAILURE = 1;

    /**
     * CLOSED list of MySQL driver errnos that mean "the object this
     * statement was creating already exists" — an idempotent replay, not a
     * failure. Ported verbatim (list + reasoning) from the server's S159
     * classifier; every member is scoped per-table, so a collision can only
     * be with the object the failing statement itself was creating:
     *
     *  - 1050 table exists          (`CREATE TABLE` without IF NOT EXISTS)
     *  - 1060 duplicate column      (`ALTER TABLE ... ADD COLUMN`)
     *  - 1061 duplicate key name    (`ALTER TABLE ... ADD KEY` / `CREATE INDEX`)
     *  - 1091 can't drop            (`ALTER TABLE ... DROP` of a gone field/key)
     *
     * Deliberately EXCLUDED, with the server's measured reasons (MySQL 8.0.46):
     *  - 1062 duplicate entry — a UNIQUE-index/data violation inside a
     *    migration is a real failure and must stop the chain;
     *  - 1068 multiple primary keys — the message carries no object name, so
     *    an already-applied replay is indistinguishable from a bad DDL edit;
     *  - 1826/3822 duplicate FK/CHECK constraint names — scoped to the whole
     *    SCHEMA, not this table: a `CREATE TABLE` that collides on a
     *    constraint name could be recorded as applied while having created
     *    nothing. Considered and rejected.
     *
     * @var list<int>
     */
    private const array IDEMPOTENT_ERROR_CODES = [1050, 1060, 1061, 1091];

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
     * Checksum writes that the schema could not accept yet (the `checksum` column
     * does not exist until migration `041_migrations_checksum.sql` runs, which
     * happens part-way through this very loop on a fresh database) are collected and
     * flushed once after the loop, so the ledger is fully populated by the time this
     * method returns rather than one run later. The flush only writes the `checksum`
     * column of rows that are already recorded, so it cannot mark anything applied
     * that was not.
     *
     * @return list<string> Filenames (basename only) of migrations executed this run.
     */
    public function run(): array
    {
        $this->ensureTrackingTable();
        $ledger = $this->loadLedger();
        $files = $this->discoverFiles();
        $ranNow = [];
        /** @var array<string, string> $deferredChecksums filename => checksum */
        $deferredChecksums = [];

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
                    if (!$this->backfillChecksum($basename, $checksum)) {
                        $deferredChecksums[$basename] = $checksum;
                    }
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
            if (!$this->recordApplied($basename, $checksum)) {
                $deferredChecksums[$basename] = $checksum;
            }
            $ranNow[] = $basename;
        }

        // Second pass for the checksums the schema could not take during the loop.
        // By now migration 041 has added the column, so these succeed. Ordered
        // after applyFile()/recordApplied() on purpose: a row is only ever stamped
        // with a checksum once its migration is already recorded as applied, so a
        // migration that failed mid-loop (and therefore threw, never reaching here)
        // can still be re-run by the next invocation.
        foreach ($deferredChecksums as $basename => $checksum) {
            $this->backfillChecksum($basename, $checksum);
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
                if (self::isAlreadyAppliedNote($e->getMessage(), $statement)) {
                    // HUB-5.1 (S159 law): the object this statement creates is
                    // already there — an idempotent replay, not a failure.
                    // Log it LOUDLY and continue so the file still completes
                    // and records (refreshing its checksum on the divergence
                    // path); any other error keeps the throw-stop contract.
                    $this->noteAlreadyApplied(basename($file), $statement, $e->getMessage());
                    continue;
                }

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
     * run are recorded before it), fall back to a name-only insert and report
     * `false` so {@see run()} re-stamps the checksum after the loop, once 041 has
     * added the column.
     *
     * @return bool `true` when the checksum was stored, `false` when the row was
     *              recorded name-only and its checksum still needs writing.
     */
    private function recordApplied(string $filename, ?string $checksum): bool
    {
        // workerman/mysql `bindMore` keys on the array keys; use named
        // placeholders (colon-free array keys) to avoid the 0-based positional
        // binding mismatch — see .claude/rules/database-queries.md.
        try {
            $this->db->query(
                UpsertSyntax::adapt('INSERT INTO `' . self::TRACKING_TABLE . '` (filename, checksum)'
                . ' VALUES (:filename, :checksum)'
                . ' AS new_row ON DUPLICATE KEY UPDATE checksum = new_row.checksum'),
                ['filename' => $filename, 'checksum' => $checksum],
            );

            return true;
        } catch (Throwable $e) {
            if (!self::isMissingChecksumColumnError($e)) {
                throw $e;
            }
            // Pre-041 schema: record the filename only. run()'s post-loop flush
            // writes the checksum once the column exists.
            $this->db->query(
                UpsertSyntax::adapt('INSERT INTO `' . self::TRACKING_TABLE . '` (filename) VALUES (:filename)'
                . ' AS new_row ON DUPLICATE KEY UPDATE filename = new_row.filename'),
                ['filename' => $filename],
            );

            return false;
        }
    }

    /**
     * Backfill the checksum of an already-recorded migration WITHOUT
     * re-executing it. Used for pre-HB-4.11 rows whose checksum is NULL: the row
     * already proves the migration was applied, so we only stamp the current
     * on-disk checksum as the baseline for future divergence detection.
     *
     * Failure-tolerant: if the checksum column is not present yet (a fresh
     * database before migration `041` runs this same pass), the backfill is a no-op
     * and reports `false` so {@see run()} retries it after the loop.
     *
     * @return bool `true` when the UPDATE was accepted, `false` when it failed and
     *              the checksum still needs writing.
     */
    private function backfillChecksum(string $filename, string $checksum): bool
    {
        try {
            $this->db->query(
                'UPDATE `' . self::TRACKING_TABLE . '` SET checksum = :checksum'
                . ' WHERE filename = :filename',
                ['filename' => $filename, 'checksum' => $checksum],
            );

            return true;
        } catch (Throwable) {
            // Column not present yet, or a transient write failure — the row is
            // already recorded, so a missed backfill only means it is retried
            // (still without re-executing) by run()'s post-loop flush or, failing
            // that, on the next run.
            return false;
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
     * Loud record of one statement skipped by the HUB-5.1 idempotent-replay
     * law ({@see self::isAlreadyAppliedNote()}). Same channel as the
     * divergence WARNING above (error_log surfaces on CLI stderr and the
     * journald unit alike) — a skip must never be silent, but it must also
     * never fail the chain.
     */
    private function noteAlreadyApplied(string $filename, string $statement, string $driverMessage): void
    {
        error_log(sprintf(
            '[phlix-hub][MigrationRunner] %s: statement skipped (already applied): %s | %s',
            $filename,
            $driverMessage,
            $statement,
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
     *
     * The classifier must not self-match. `Workerman\MySQL\Connection` prefixes the
     * FAILING SQL onto the message — measured shape:
     * `SQL:INSERT INTO ... checksum ... SQLSTATE[42S22]: Column not found: 1054
     * Unknown column 'checksum' in 'field list'` — and every statement this class
     * guards mentions `checksum` in its own SQL. A test for "unknown column" plus
     * "checksum" anywhere in that string therefore also matched an unknown-column
     * error about a DIFFERENT column, swallowing a genuine schema fault as a
     * "pre-041 database" and silently degrading to name-only recording. So: drop the
     * echoed SQL (everything before the last `SQLSTATE[`) and require the column
     * name to appear QUOTED, as MySQL renders it in error 1054.
     */
    private static function isMissingChecksumColumnError(Throwable $e): bool
    {
        $message = $e->getMessage();
        $sqlState = strrpos($message, 'SQLSTATE[');
        if ($sqlState !== false) {
            $message = substr($message, $sqlState);
        }

        return str_contains(strtolower($message), "unknown column 'checksum'");
    }

    /**
     * Map a migration outcome to the process exit verdict — the single
     * source both entry points (`scripts/run-migrations.php` and
     * `bin/phlix migrate`) must use.
     *
     * Hub-shaped on purpose: the server's runner reports in-band
     * (`exitCodeFor(array $result)` over its errors channel) because its
     * loop CONTINUES past failures; the hub's runner keeps the documented
     * throw-stop contract, so a failure travels as a `Throwable` and its
     * ABSENCE is the success proof. Same law either way: only a genuine
     * failure reddens the process — idempotent skips, an empty directory,
     * and an already-complete ledger all exit
     * {@see self::EXIT_SUCCESS}.
     */
    public static function exitCodeFor(?Throwable $failure): int
    {
        return $failure === null ? self::EXIT_SUCCESS : self::EXIT_FAILURE;
    }

    /**
     * Whether a statement error means "the schema object is already there"
     * — the idempotent-replay class of {@see self::IDEMPOTENT_ERROR_CODES}.
     *
     * Ported semantics from the server's S159 classifier, including the
     * anti-forgery rule that decided that fix: the verdict is taken from
     * the parsed DRIVER segment's errno against the closed list, never from
     * raw phrase matching over the whole message. `Workerman\MySQL\Connection`
     * (and {@see PhlixMySQLConnection::query()}) prefix the failing SQL onto
     * the error text, and migration SQL is free to contain the words
     * 'already exists' (a COMMENT, a table name) — measured server-side, a
     * genuine 1064/1146 failure whose echoed SQL carried the phrase was
     * silently classed idempotent by the naive classifier, which is worse
     * than the pre-S159 bug it replaced. When the driver errno is present it
     * rules; the phrase test only runs on segment shapes that carry no
     * parseable errno (e.g. the bare 'Unknown column … in 'field list''
     * variants and the two unprefixed rethrow paths).
     *
     * @param string      $message   The exception message as thrown.
     * @param string|null $statement The statement that produced it, when the
     *                               caller knows it — always true from
     *                               {@see self::applyFile()}, which gets it
     *                               verbatim from its own loop.
     */
    public static function isAlreadyAppliedNote(string $message, ?string $statement = null): bool
    {
        $segment = self::errorSegment($message, $statement);
        if ($segment === null) {
            return false;
        }

        $code = self::driverErrorCode($segment);
        if ($code !== null) {
            return in_array($code, self::IDEMPOTENT_ERROR_CODES, true);
        }

        return str_contains($segment, 'Duplicate column name')
            || str_contains($segment, 'Duplicate key name')
            || str_contains($segment, 'check that column/key exists')
            || str_contains($segment, 'already exists');
    }

    /**
     * Strip the `'SQL:' . $statement . ' '` prefix the connection classes
     * prepend, leaving only the driver's own error text. Two routes, in
     * order of trust (ported verbatim from the server):
     *
     *  1. EXACT prefix strip: when the statement is known, the literal
     *     `'SQL:' . $statement . ' '` is what both rethrow sites build —
     *     `Connection::query()` trims the query and stores it as `lastSQL()`,
     *     and {@see self::splitStatements()} trims every statement it
     *     yields, so the two are byte-identical. Everything after the prefix
     *     is driver text with no delimiter left for a payload to forge; if
     *     what follows does NOT open with `SQLSTATE[` the segment is
     *     unparseable and the caller must NOT squelch (return null).
     *  2. FALLBACK last `SQLSTATE[`: the only route for legitimately
     *     unprefixed shapes (unit doubles, raw PDOException rethrows,
     *     `SQLSTATE[HY000] [2002] Connection refused`, the gone-away retry
     *     path). A guess, and forgeable when misapplied — which is exactly
     *     why route 1 must win whenever the prefix is present: a message
     *     that STARTS with `SQL:` but yields no segment returns null, never
     *     a fallback read of the echoed statement.
     */
    private static function errorSegment(string $message, ?string $statement = null): ?string
    {
        if ($statement !== null) {
            $prefix = 'SQL:' . $statement . ' ';
            if (str_starts_with($message, $prefix)) {
                $rest = substr($message, strlen($prefix));

                return str_starts_with($rest, 'SQLSTATE[') ? $rest : null;
            }
        }

        $pos = strrpos($message, 'SQLSTATE[');
        if ($pos !== false) {
            return substr($message, $pos);
        }

        if (str_starts_with($message, 'SQL:')) {
            return null;
        }

        return $message;
    }

    /**
     * Parse the MySQL error number out of a driver error segment, e.g.
     * `SQLSTATE[42S21]: Column already exists: 1060 Duplicate column
     * name 'v'` → 1060. The errno cannot come from the exception object:
     * both rethrow sites build a fresh `PDOException` with `(int)`-cast
     * SQLSTATE and no `errorInfo` (server-verified by execution), so the
     * rendered segment is the only place the code survives. Returns null
     * for segments without a parseable code (connection failures), where
     * the caller falls through to the phrase test.
     */
    private static function driverErrorCode(string $segment): ?int
    {
        // D-16 (refactor): the parse is the canonical one; the segment handed
        // in here is already prefix-stripped and anchored (errorSegment()'s
        // contract), which is exactly the shape MysqlError::driverCode()
        // parses first. The multi-occurrence refusal additionally hardens the
        // decoy class this file's safety docblock condemned.
        return MysqlError::driverCode($segment);
    }
}
