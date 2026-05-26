-- migration: 006_server_heartbeats_sent_at
-- Adds `sent_at` to `server_heartbeats` so the hub can persist the
-- server-side send timestamp (HeartbeatDto::$timestamp) alongside the
-- hub-side `received_at`. The pair powers clock-skew detection in the
-- heartbeat handler.
--
-- `sent_at` is nullable because rows written before this migration
-- (and any heartbeat where the sender omits the field) carry no
-- server timestamp.
--
-- `ALTER ... IF NOT EXISTS` keeps this re-runnable even though the
-- MigrationRunner tracking table already guards against double-apply.

ALTER TABLE server_heartbeats
    ADD COLUMN IF NOT EXISTS sent_at DATETIME NULL AFTER received_at;
