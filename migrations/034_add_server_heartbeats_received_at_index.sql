-- migration: 034_add_server_heartbeats_received_at_index
-- HB-2.6: Add index on server_heartbeats.received_at for retention DELETE queries.
--
-- The existing index ix_server_heartbeats_server_time (server_id, received_at)
-- has server_id as its leading column, so a plain WHERE received_at < ...
-- filter cannot use it as a range scan and falls back to a full table scan.
-- The sweepHeartbeats() DELETE in ServerReaper only filters on received_at,
-- so a dedicated index on received_at alone fixes this.
--
-- Idempotency: the runner records applied files in the `migrations` tracking
-- table and never re-runs them, so a plain CREATE INDEX suffices. (MySQL 8 does
-- not support `CREATE INDEX IF NOT EXISTS` — that is MariaDB-only.)

CREATE INDEX idx_server_heartbeats_received_at ON server_heartbeats (received_at);
