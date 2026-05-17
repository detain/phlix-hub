-- migration: 003_shared_libraries
-- Creates the `shared_libraries` table — grants from a server owner to
-- another hub user, scoped to a specific (server, library) pair. The
-- `library_id` is the server-side library UUID, opaque to the hub. The
-- denormalised `library_name` lets the dashboard render the share list
-- without hitting the remote server.

CREATE TABLE IF NOT EXISTS shared_libraries (
    id                 CHAR(36) NOT NULL,
    owner_user_id      CHAR(36) NOT NULL,
    grantee_user_id    CHAR(36) NOT NULL,
    server_id          CHAR(36) NOT NULL,
    library_id         CHAR(36) NOT NULL,
    library_name       VARCHAR(128) NOT NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at         DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_shared_libraries (server_id, library_id, grantee_user_id),
    KEY ix_shared_libraries_grantee (grantee_user_id),
    KEY ix_shared_libraries_owner (owner_user_id),
    CONSTRAINT fk_shared_libraries_owner   FOREIGN KEY (owner_user_id)   REFERENCES users(id)   ON DELETE CASCADE,
    CONSTRAINT fk_shared_libraries_grantee FOREIGN KEY (grantee_user_id) REFERENCES users(id)   ON DELETE CASCADE,
    CONSTRAINT fk_shared_libraries_server  FOREIGN KEY (server_id)       REFERENCES servers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
