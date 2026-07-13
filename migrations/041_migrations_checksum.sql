-- migration: 041_migrations_checksum
-- HB-4.11: add a checksum column to the migration tracking table so the
-- MigrationRunner can detect when an already-applied migration file is later
-- EDITED (a rewrite-class migration) and safely re-apply it, matching the
-- behaviour of phlix-server's schema_migrations ledger (SV-4.9).
--
-- Before this migration the `migrations` table recorded applied files by name
-- only (filename PK + applied_at), so editing an already-applied `.sql` was
-- invisible to the runner and never re-run. The checksum is a comment-normalised
-- md5 of the file contents (see MigrationRunner::checksum()): a doc-only edit
-- does not flip it, but any real SQL change diverges it and triggers a
-- WARNING + re-apply + refreshed checksum.
--
-- Nullable on purpose: the hub `migrations` table already has real production
-- rows (~40 applied migrations) with no checksum. Those rows land here as
-- checksum IS NULL and are treated as "recorded, no baseline yet" — the runner
-- backfills each from its current on-disk file WITHOUT re-executing it (the
-- row's existence already proves it was applied). Only a subsequent edit against
-- a now-populated baseline triggers a real divergence re-apply, so this column
-- must NOT force a day-one mass re-apply.
--
-- Plain ALTER (no `IF NOT EXISTS` on the column — MariaDB-only, rejected by the
-- MySQL 8 deploy target with a 1064). Idempotency comes from the runner tracking
-- table: this file is applied exactly once. On a fresh database the column is
-- absent until this migration runs, so the plain ADD COLUMN succeeds; the runner
-- degrades gracefully (records name-only) for the earlier files applied in the
-- same run and backfills their checksums on the next run.

ALTER TABLE migrations
    ADD COLUMN checksum CHAR(32) NULL COMMENT 'Comment-normalised md5 of the migration file when applied; NULL for pre-HB-4.11 rows awaiting backfill';
