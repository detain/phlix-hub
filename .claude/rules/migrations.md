---
paths:
  - migrations/**
---

# Migration Files (enforced by tests/Unit/Migrations/MigrationFileTest.php)

- Name `NNN_snake_name.sql`; first line `-- migration: NNN_snake_name`.
- New tables: `CREATE TABLE IF NOT EXISTS` (valid on both MySQL 8 and MariaDB), `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`, `id CHAR(36) NOT NULL` PK.
- Column/index changes: use **plain DDL** — `ALTER TABLE … ADD COLUMN …`, `CREATE INDEX …`, `DROP INDEX …`. Do **NOT** write `IF [NOT] EXISTS` on a column, index, or key: that is MariaDB-only and MySQL 8 (the deploy target) rejects it with a 1064 syntax error. (`CREATE TABLE`/`DROP TABLE IF [NOT] EXISTS` are fine — the restriction is columns/indexes/keys only.)
- **Idempotency comes from the tracking table** in `src/Common/Database/MigrationRunner.php`: it records every applied file by name and never re-runs it, so plain non-guarded DDL is correct — you do not need (and must not use) `IF [NOT] EXISTS` guards on columns/indexes to make re-runs safe. Apply via `php bin/phlix migrate`.
- **Editing an already-applied file re-applies it.** The ledger also stores a comment-normalized `checksum` (migration `041_migrations_checksum`); when a `.sql` body changes after it ran, `MigrationRunner` warns and re-executes that file, so keep DDL re-run-safe (comment-only edits are ignored).
- Balanced parentheses; keep portable across MySQL 8 / MariaDB 10.6 (plain DDL satisfies both).
- Add the filename to the `testExpectedMigrationsExist` list when adding a migration.
