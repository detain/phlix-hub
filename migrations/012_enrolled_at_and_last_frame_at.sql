-- migration: 012_enrolled_at_and_last_frame_at
--
-- Adds two columns that were referenced by application code but never
-- created by an earlier migration, so a fresh DB could not complete
-- a server claim or record relay activity:
--
--   - `servers.enrolled_at` — INT UNSIGNED unix timestamp written by
--     `Phlix\Hub\Hub\ClaimRequestHandler::handle()` when a paired
--     claim is promoted into a `servers` row. Earlier rows are
--     backfilled from `created_at` so dashboards and any
--     `enrolled_at`-keyed queries keep working.
--
--   - `relay_sessions.last_frame_at` — INT UNSIGNED unix timestamp
--     updated by `Phlix\Hub\Hub\RelaySessionManager::recordBytesIn()`
--     / `recordBytesOut()` every time a frame flows over a relay
--     tunnel. Nullable because sessions that opened before this
--     migration have no activity data; the application treats NULL
--     as "no frames yet".
--
-- `ADD COLUMN IF NOT EXISTS` keeps this re-runnable even though the
-- MigrationRunner tracking table already guards against double-apply,
-- and (more importantly) tolerates environments where a DBA has
-- already hand-patched `enrolled_at` to unblock migration 007.

ALTER TABLE servers
    ADD COLUMN IF NOT EXISTS enrolled_at INT UNSIGNED NULL AFTER last_seen_at;

UPDATE servers
   SET enrolled_at = UNIX_TIMESTAMP(created_at)
 WHERE enrolled_at IS NULL;

ALTER TABLE relay_sessions
    ADD COLUMN IF NOT EXISTS last_frame_at INT UNSIGNED NULL AFTER bytes_out;
