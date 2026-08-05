---
name: sql-migration
description: Creates an idempotent migrations/NNN_name.sql in phlix-hub matching MigrationFileTest (header comment, CREATE TABLE IF NOT EXISTS, ENGINE=InnoDB utf8mb4, CHAR(36) PK) or plain ALTER for column changes, then updates the expected-files list in the test. Use when the user says 'add migration', 'new table', 'alter schema', 'add column', or 'create table'. Capabilities: picks the next sequence number, writes the migration header MigrationFileTest enforces, applies via `php bin/phlix migrate`, and keeps the test's $expected array in sync. Do NOT use for runtime/application queries (use the hub-handler / db-query patterns) — this is schema-change DDL only.
paths:
  - migrations/*.sql
  - tests/Unit/Migrations/MigrationFileTest.php
---
# SQL Migration (phlix-hub)

Create a new file in `migrations/` (named `NNN_name.sql`). Every `*.sql` file in `migrations/` is statically validated by `tests/Unit/Migrations/MigrationFileTest.php` and applied in lexicographic order by `src/Common/Database/MigrationRunner.php`. Get both the file shape and the test's expected-files list right, or PHPUnit fails.

## Critical

- **Idempotency is NOT in the SQL.** The `migrations` tracking table (keyed by filename) is the *sole* idempotency mechanism — `MigrationRunner` skips any file already recorded. Do NOT use MariaDB's `ADD COLUMN IF NOT EXISTS` — it is not portable to MySQL 8. Use plain `ALTER TABLE ... ADD COLUMN`. New tables DO use `CREATE TABLE IF NOT EXISTS` (this is what `MigrationFileTest::testFileUsesCreateTableIfNotExists` asserts).
- **First line must match** `/^-- migration: \d{3}_[a-z_]+/` exactly: `-- migration: 031_my_change`. Name is lowercase + underscores only, prefixed by the 3-digit number matching the filename.
- **Every new file MUST be added to the `$expected` array** in `MigrationFileTest::testExpectedMigrationsExist()` or that test fails. This is a hard gate — do not skip it.
- **Balanced parentheses.** `testParenthesesAreBalanced` counts `(` vs `)` across the whole file (comments included) — keep them equal.
- Statements are split on `;` after stripping `--` comment lines (`MigrationRunner::splitStatements`). No stored-routine bodies. Multiple statements per file are fine; end each with `;`.

## Instructions

1. **Pick the next number.** Run `ls migrations/` and take the highest `NNN` + 1, zero-padded to 3 digits. (Current highest is `030`, so the next is `031`. Note the existing gap 012→027 — never reuse a number.) Verify: the number you chose does not already exist as a file. This number is used in BOTH the filename and the header line.

2. **Choose table-create vs ALTER.** New table → use the `CREATE TABLE IF NOT EXISTS` template (Step 3). Adding/changing columns or indexes on an existing table → use the plain `ALTER` template (Step 4). Verify which one applies before writing.

3. **New table** — create the new file in `migrations/` from this exact shape (mirrors `migrations/009_library_shares.sql`):
   ```sql
   -- migration: 031_my_things
   -- One or more comment lines explaining WHAT this table is for and WHY,
   -- referencing the application class that reads/writes it (e.g.
   -- `Phlix\Hub\Hub\SomeHandler`). Keep the prose grounded in real code.

   CREATE TABLE IF NOT EXISTS my_things (
       id            CHAR(36) NOT NULL,
       user_id       CHAR(36) NOT NULL,
       name          VARCHAR(255) NOT NULL,
       status        ENUM('active', 'revoked') NOT NULL DEFAULT 'active',
       created_at    INT UNSIGNED NOT NULL,
       expires_at    INT UNSIGNED NULL,
       PRIMARY KEY (id),
       UNIQUE KEY uk_my_things_name (user_id, name),
       INDEX idx_user (user_id),
       CONSTRAINT fk_mt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
   ```
   Rules pulled from existing migrations + `MigrationFileTest`:
   - PK column MUST be `id CHAR(36) NOT NULL` (regex `/\bid\s+CHAR\(36\)\s+NOT NULL/i` — `testFileUsesCharThirtyForPrimaryKeys`). All UUID FKs/owner columns are also `CHAR(36)`.
   - Timestamps are stored as `INT UNSIGNED` unix seconds (see `migrations/009_library_shares.sql`, `migrations/012_enrolled_at_and_last_frame_at.sql`), NOT `DATETIME`, when written by application code. Use `DATETIME` only when the column is DB-defaulted.
   - MUST contain literal `ENGINE=InnoDB` and `utf8mb4` (`testFileDeclaresInnodbAndUtf8mb4`). Always close with `) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;`.
   - FK constraint names are short and prefixed (`fk_ls_owner`, `fk_mt_user`); index names `idx_*`; unique keys `uk_*`.
   Verify: file starts with the header line, has `id CHAR(36) NOT NULL`, contains `CREATE TABLE IF NOT EXISTS`, `ENGINE=InnoDB`, `utf8mb4`, and parentheses balance.

4. **ALTER an existing table** — create the new file in `migrations/` from this shape (mirrors `migrations/006_server_heartbeats_sent_at.sql` and `migrations/012_enrolled_at_and_last_frame_at.sql`):
   ```sql
   -- migration: 031_servers_add_region
   -- Adds `region` to `servers`, written by <ClassName>::<method>(). Nullable
   -- because rows created before this migration have no region.
   --
   -- Idempotency is provided by the MigrationRunner tracking table; plain
   -- `ADD COLUMN` keeps the SQL portable across MySQL 8 and MariaDB.

   ALTER TABLE servers
       ADD COLUMN region VARCHAR(64) NULL AFTER last_seen_at;
   ```
   - Use plain `ADD COLUMN` (no `IF NOT EXISTS`). New columns are typically `NULL` so existing rows stay valid; document why in the comment.
   - Backfill in the SAME file with a following `UPDATE ... WHERE col IS NULL;` statement when a default value is needed (see `migrations/012_enrolled_at_and_last_frame_at.sql`).
   - ALTER-only files are exempt from the InnoDB/CHAR(36)/CREATE-TABLE checks (the test skips them via `definesNewTable()`), but STILL need the header line and balanced parens.
   Verify: header line present, uses `ALTER TABLE`, parentheses balance.

5. **Register the file in the test.** This step uses the filename from Step 1. Edit `tests/Unit/Migrations/MigrationFileTest.php`, adding the new basename to the END of the `$expected` array in `testExpectedMigrationsExist()`, preserving order (the test sorts both lists and asserts `assertSame`):
   ```php
           '030_fix_server_claim_pairing.sql',
           '031_my_things.sql',
   ```
   Verify: the array entry string exactly equals your filename.

6. **Run the migration test first** (fast, no DB needed — it's pure static file checks):
   ```bash
   ./vendor/bin/phpunit --filter MigrationFileTest
   ```
   Verify ALL assertions pass. If `testExpectedMigrationsExist` fails, the `$expected` array (Step 5) is out of sync. Do NOT proceed until green.

7. **Apply against the database:**
   ```bash
   php bin/phlix migrate
   ```
   The runner prints newly-applied filenames. It is safe to re-run — already-applied files are skipped via the tracking table.

8. **Run static analysis + full suite** (project gate: PHPStan level 9 + Psalm errorLevel 1, no baselines):
   ```bash
   ./vendor/bin/phpunit
   ```
   Verify green before considering the task done.

## Examples

**User says:** "Add a migration for an `api_tokens` table — id, user_id, token hash, created/expiry timestamps."

**Actions taken:**
1. `ls migrations/` → highest is `030` → next number `031`.
2. New table → use Step 3 template. Create `migrations/031_api_tokens.sql`:
   ```sql
   -- migration: 031_api_tokens
   -- Personal access tokens minted for a user. `token_hash` stores a
   -- SHA-256 of the secret (never the plaintext). Read by AuthManager.

   CREATE TABLE IF NOT EXISTS api_tokens (
       id            CHAR(36) NOT NULL,
       user_id       CHAR(36) NOT NULL,
       token_hash    CHAR(64) NOT NULL,
       created_at    INT UNSIGNED NOT NULL,
       expires_at    INT UNSIGNED NULL,
       PRIMARY KEY (id),
       UNIQUE KEY uk_api_tokens_hash (token_hash),
       INDEX idx_user (user_id),
       CONSTRAINT fk_at_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
   ```
3. Add `'031_api_tokens.sql',` to `$expected` in `tests/Unit/Migrations/MigrationFileTest.php`.
4. `./vendor/bin/phpunit --filter MigrationFileTest` → green.
5. `php bin/phlix migrate` → prints `031_api_tokens.sql`.

**Result:** New idempotent table, validated by the static migration test and applied to the DB.

## Common Issues

- **`Failed asserting that two arrays are identical` in `testExpectedMigrationsExist`** — You created the `.sql` file but didn't add it to the `$expected` array (or added it with a typo / wrong order). Fix: add the exact basename to the array in `MigrationFileTest::testExpectedMigrationsExist()` (Step 5). The test sorts both lists, so the entry's string must match the filename character-for-character.
- **`must start with "-- migration: NNN_name"`** — First non-whitespace line doesn't match `/^-- migration: \d{3}_[a-z_]+/`. Header number/name must be lowercase letters + underscores only, 3-digit number. No uppercase, no hyphens, no digits in the name part.
- **`must use CREATE TABLE IF NOT EXISTS` / `must declare ENGINE=InnoDB` / `must use CHAR(36)`** — Your file contains `CREATE TABLE` (so the test treats it as a new-table migration) but is missing one of those tokens. Either add the missing token, or — if you meant an ALTER-only change — remove the `CREATE TABLE` text entirely so `definesNewTable()` skips the table-shape checks.
- **`has mismatched parentheses`** — Unbalanced `(`/`)` somewhere (often a `(` inside a comment, or a missing closing paren on the `ENGINE=...` line). Count is across the WHOLE file including comments.
- **`Migration NNN_... failed: ... Statement: ...` from `php bin/phlix migrate`** — A statement errored at runtime. Common causes: (1) a referenced `REFERENCES other(id)` table doesn't exist yet — ensure its migration has a lower number; (2) you used `ADD COLUMN IF NOT EXISTS` which MySQL 8 rejects — use plain `ADD COLUMN`. The runner already recorded nothing for the failed file, so fix the SQL and re-run `php bin/phlix migrate`.
- **Re-running adds nothing / column already exists** — The file was already recorded in the `migrations` tracking table. To re-apply during local dev, delete its row: `DELETE FROM migrations WHERE filename = '031_....sql';` then re-run migrate. Never edit an already-applied migration in place on a shared DB — write a new follow-up migration instead.
- **`positional placeholders` / `bindMore()` errors** — Only relevant if your migration includes parameterized statements (rare). Migrations are static DDL and use no placeholders; application queries that do MUST use named `:param` placeholders (see CLAUDE.md), not `?`.
