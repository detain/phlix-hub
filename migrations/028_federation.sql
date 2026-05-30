-- migration: 028_federation
-- Creates the federation hub tables: federation_hubs (self),
-- federation_peers, federation_sessions, federation_library_shares,
-- federation_incoming_share_offers, federation_admin_delegations.

CREATE TABLE IF NOT EXISTS federation_hubs (
    id               CHAR(36) NOT NULL,
    name             VARCHAR(128) NOT NULL,
    url              VARCHAR(255) NOT NULL,
    public_key        TEXT NOT NULL,
    role             ENUM('master', 'leaf') NOT NULL DEFAULT 'leaf',
    is_master         TINYINT(1) NOT NULL DEFAULT 0,
    is_active         TINYINT(1) NOT NULL DEFAULT 1,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_federation_hubs_url (url)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS federation_peers (
    id               CHAR(36) NOT NULL,
    name             VARCHAR(128) NOT NULL,
    url              VARCHAR(255) NOT NULL,
    public_key        TEXT NOT NULL,
    relay_enabled    TINYINT(1) NOT NULL DEFAULT 0,
    admin_delegation_enabled TINYINT(1) NOT NULL DEFAULT 0,
    status           ENUM('pending', 'connected', 'suspended', 'disconnected') NOT NULL DEFAULT 'pending',
    last_seen_at     TIMESTAMP NULL,
    last_connected_at TIMESTAMP NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_federation_peers_url (url),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS federation_sessions (
    id               CHAR(36) NOT NULL,
    peer_id          CHAR(36) NOT NULL,
    established_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_heartbeat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    bytes_sent       BIGINT UNSIGNED NOT NULL DEFAULT 0,
    bytes_received   BIGINT UNSIGNED NOT NULL DEFAULT 0,
    alive            TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    FOREIGN KEY (peer_id) REFERENCES federation_peers(id) ON DELETE CASCADE,
    INDEX idx_peer_alive (peer_id, alive),
    INDEX idx_last_heartbeat (last_heartbeat_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS federation_library_shares (
    id              CHAR(36) NOT NULL,
    library_id      CHAR(36) NOT NULL,
    library_name    VARCHAR(255) NOT NULL,
    peer_id         CHAR(36) NOT NULL,
    permission     ENUM('read', 'readwrite') NOT NULL DEFAULT 'read',
    status          ENUM('pending', 'active', 'revoked') NOT NULL DEFAULT 'pending',
    shared_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at      TIMESTAMP NULL,
    PRIMARY KEY (id),
    FOREIGN KEY (peer_id) REFERENCES federation_peers(id) ON DELETE CASCADE,
    UNIQUE KEY uq_federation_library_shares_lib_peer (library_id, peer_id),
    INDEX idx_peer_status (peer_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS federation_incoming_share_offers (
    id              CHAR(36) NOT NULL,
    peer_id         CHAR(36) NOT NULL,
    library_id      CHAR(36) NOT NULL,
    library_name    VARCHAR(255) NOT NULL,
    permission     ENUM('read', 'readwrite') NOT NULL DEFAULT 'read',
    status          ENUM('pending', 'accepted', 'rejected') NOT NULL DEFAULT 'pending',
    offered_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    responded_at    TIMESTAMP NULL,
    accepted_by     CHAR(36) NULL,
    PRIMARY KEY (id),
    FOREIGN KEY (peer_id) REFERENCES federation_peers(id) ON DELETE CASCADE,
    INDEX idx_peer_status (peer_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS federation_admin_delegations (
    id              CHAR(36) NOT NULL,
    peer_id         CHAR(36) NOT NULL,
    user_id         CHAR(36) NOT NULL,
    granted_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at     TIMESTAMP NULL,
    PRIMARY KEY (id),
    FOREIGN KEY (peer_id) REFERENCES federation_peers(id) ON DELETE CASCADE,
    UNIQUE KEY uq_delegation_peer_user (peer_id, user_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
