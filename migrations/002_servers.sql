-- migration: 002_servers
-- Creates three tables that together model a claimed Phlex media server
-- and its operational state.
--
--   servers           — one row per server claimed to the hub.
--   server_claims     — the pending/paired claim codes minted by the hub.
--   server_heartbeats — last-N heartbeat rows for liveness + dashboards.
--
-- All three depend on `users` (001).

CREATE TABLE IF NOT EXISTS servers (
    id                       CHAR(36) NOT NULL,
    user_id                  CHAR(36) NOT NULL,
    server_name              VARCHAR(128) NOT NULL,
    version                  VARCHAR(32) NOT NULL,
    jwks_json                TEXT NOT NULL,
    hostname_candidates_json TEXT NOT NULL,
    status                   ENUM('online','offline','claiming','disabled') NOT NULL DEFAULT 'claiming',
    last_seen_at             DATETIME DEFAULT NULL,
    created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_servers_user_id (user_id),
    KEY ix_servers_last_seen (last_seen_at),
    CONSTRAINT fk_servers_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS server_claims (
    id                       CHAR(36) NOT NULL,
    claim_code               VARCHAR(16) NOT NULL,
    user_id                  CHAR(36) DEFAULT NULL,
    server_name              VARCHAR(128) NOT NULL,
    version                  VARCHAR(32) NOT NULL,
    jwks_json                TEXT NOT NULL,
    hostname_candidates_json TEXT NOT NULL,
    status                   ENUM('pending','paired','expired','revoked') NOT NULL DEFAULT 'pending',
    expires_at               DATETIME NOT NULL,
    created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    paired_at                DATETIME DEFAULT NULL,
    paired_server_id         CHAR(36) DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_server_claims_code (claim_code),
    KEY ix_server_claims_status_expires (status, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS server_heartbeats (
    id                       CHAR(36) NOT NULL,
    server_id                CHAR(36) NOT NULL,
    version                  VARCHAR(32) NOT NULL,
    uptime_seconds           INT UNSIGNED NOT NULL,
    active_sessions          INT UNSIGNED NOT NULL DEFAULT 0,
    active_transcodes        INT UNSIGNED NOT NULL DEFAULT 0,
    hostname_candidates_json TEXT NOT NULL,
    received_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_server_heartbeats_server_time (server_id, received_at),
    CONSTRAINT fk_server_heartbeats_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
