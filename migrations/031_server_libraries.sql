-- migration: 031_server_libraries
-- Stores libraries reported by servers via heartbeat.

CREATE TABLE IF NOT EXISTS server_libraries (
    id CHAR(36) NOT NULL PRIMARY KEY,
    server_id CHAR(36) NOT NULL,
    library_id CHAR(36) NOT NULL,
    library_name VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_server_library (server_id, library_id),
    KEY idx_server_libraries_server_id (server_id),
    CONSTRAINT fk_server_libraries_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
