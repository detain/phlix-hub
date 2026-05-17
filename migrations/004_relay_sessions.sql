-- migration: 004_relay_sessions
-- Creates the `relay_sessions` table — one row per WebSocket relay
-- session the hub holds open on behalf of a server. `worker_node`
-- identifies which hub worker terminates the WS (multi-node deployment).
-- Byte counters are updated periodically; `closed_at` + `close_reason`
-- are populated on disconnect.

CREATE TABLE IF NOT EXISTS relay_sessions (
    id            CHAR(36) NOT NULL,
    server_id     CHAR(36) NOT NULL,
    worker_node   VARCHAR(128) NOT NULL,
    opened_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at     DATETIME DEFAULT NULL,
    bytes_in      BIGINT UNSIGNED NOT NULL DEFAULT 0,
    bytes_out     BIGINT UNSIGNED NOT NULL DEFAULT 0,
    close_reason  VARCHAR(64) DEFAULT NULL,
    PRIMARY KEY (id),
    KEY ix_relay_sessions_server (server_id, opened_at),
    KEY ix_relay_sessions_open (server_id, closed_at),
    CONSTRAINT fk_relay_sessions_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
