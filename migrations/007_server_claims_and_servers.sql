-- migration: 007_server_claims_and_servers
--
-- Extends server_claims and servers tables for the pairing protocol
--
-- Background: migration 002 created the initial schema with jwks_json (text blob)
-- and hostname_candidates_json (text blob). This migration:
--   - Adds public_key_jwk (JSON, single JWK object) to both tables
--   - Adds protocol_version to server_claims
--   - Adds claimed_by / claimed_at to server_claims (for atomic single-use claim codes)
--   - Adds heartbeat_interval and capabilities to servers
--   - Retains jwks_json and hostname_candidates_json columns for backward compat
--     (they are dropped in a future migration once all servers have re-enrolled)
--
-- `ADD COLUMN IF NOT EXISTS` keeps this re-runnable even though the
-- MigrationRunner tracking table already guards against double-apply.
-- Column ordering is cosmetic; we deliberately do NOT use `AFTER enrolled_at`
-- here because that column is created later (see migration 012).

ALTER TABLE server_claims
    ADD COLUMN IF NOT EXISTS claimed_by CHAR(36) NULL AFTER expires_at,
    ADD COLUMN IF NOT EXISTS claimed_at INT UNSIGNED NULL AFTER claimed_by,
    ADD COLUMN IF NOT EXISTS protocol_version VARCHAR(10) NOT NULL DEFAULT 'v1' AFTER hostname_candidates_json,
    ADD COLUMN IF NOT EXISTS public_key_jwk JSON NOT NULL AFTER protocol_version;

ALTER TABLE servers
    ADD COLUMN IF NOT EXISTS public_key_jwk JSON NOT NULL AFTER version,
    ADD COLUMN IF NOT EXISTS heartbeat_interval INT UNSIGNED NOT NULL DEFAULT 60 AFTER public_key_jwk,
    ADD COLUMN IF NOT EXISTS capabilities JSON NULL AFTER heartbeat_interval;
