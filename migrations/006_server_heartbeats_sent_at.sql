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
-- Idempotency is provided by the MigrationRunner tracking table; plain
-- `ALTER` keeps the SQL portable across MySQL 8 and MariaDB.

ALTER TABLE server_heartbeats
    ADD COLUMN sent_at DATETIME NULL AFTER received_at;
