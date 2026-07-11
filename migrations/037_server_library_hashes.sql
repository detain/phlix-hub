-- migration: 037_server_library_hashes
-- Stores the SHA-256 hash of the server's library list so the hub can skip
-- redundant library upserts when the list is unchanged between heartbeats.
--
-- Hash is computed over the canonical JSON of the sorted library list:
--   sort by libraryId → json_encode → SHA-256

CREATE TABLE IF NOT EXISTS server_library_hashes (
    server_id CHAR(36) NOT NULL,
    hash CHAR(64) NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (server_id),
    CONSTRAINT fk_server_library_hashes_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
