-- migration: 036_relay_observability_metrics
-- Step H-W4 HB-4.1: Relay observability metrics
-- Adds relay-specific metric columns to the existing metrics_rollup table:
--   - pending requests gauge (high-water mark across the flush window)
--   - reply-drop counter (HTTP_RESPONSE frames for unknown/closed requests)
--   - relay-latency histogram (time from HTTP_REQUEST sent to first response byte)
--   - error counters for 503 (tunnel unavailable) and 504 (request timeout)
--   - decode-buffer size gauge (current FrameDecoder buffer bytes)
--
-- Idempotency comes from the `migrations` tracking table (the runner applies
-- each file once); MySQL 8 does not support `ADD COLUMN IF NOT EXISTS`, so use
-- plain ADD COLUMN. InnoDB + utf8mb4_unicode_ci to match the rest of the schema.

ALTER TABLE metrics_rollup
    ADD COLUMN relay_pending_requests INT NOT NULL DEFAULT 0 AFTER h_gt_5000,
    ADD COLUMN relay_reply_drops INT NOT NULL DEFAULT 0 AFTER relay_pending_requests,
    ADD COLUMN relay_latency_h_le_10 INT NOT NULL DEFAULT 0 AFTER relay_reply_drops,
    ADD COLUMN relay_latency_h_le_50 INT NOT NULL DEFAULT 0 AFTER relay_latency_h_le_10,
    ADD COLUMN relay_latency_h_le_100 INT NOT NULL DEFAULT 0 AFTER relay_latency_h_le_50,
    ADD COLUMN relay_latency_h_le_250 INT NOT NULL DEFAULT 0 AFTER relay_latency_h_le_100,
    ADD COLUMN relay_latency_h_le_500 INT NOT NULL DEFAULT 0 AFTER relay_latency_h_le_250,
    ADD COLUMN relay_latency_h_le_1000 INT NOT NULL DEFAULT 0 AFTER relay_latency_h_le_500,
    ADD COLUMN relay_latency_h_le_2500 INT NOT NULL DEFAULT 0 AFTER relay_latency_h_le_1000,
    ADD COLUMN relay_latency_h_le_5000 INT NOT NULL DEFAULT 0 AFTER relay_latency_h_le_2500,
    ADD COLUMN relay_latency_h_gt_5000 INT NOT NULL DEFAULT 0 AFTER relay_latency_h_le_5000,
    ADD COLUMN relay_error_503 INT NOT NULL DEFAULT 0 AFTER relay_latency_h_gt_5000,
    ADD COLUMN relay_error_504 INT NOT NULL DEFAULT 0 AFTER relay_error_503,
    ADD COLUMN relay_decode_buffer_bytes INT NOT NULL DEFAULT 0 AFTER relay_error_504;
