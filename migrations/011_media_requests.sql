-- migration: 011_media_requests
-- Step K.3 (hub): Jellyseerr-class media request UI.
-- Stores user-submitted media requests (movies/series). When an admin
-- approves a request, the hub talks to the relevant arr client
-- (Sonarr/Radarr) via Phlix\Shared\Arr to actually pull the title.
--
-- Owner: a hub `users.id` (CHAR(36) UUID).
-- Status enum mirrors the lifecycle: pending -> approved -> available,
-- or pending -> rejected.

CREATE TABLE IF NOT EXISTS requests (
    id                  CHAR(36) NOT NULL,
    user_id             CHAR(36) NOT NULL,
    type                ENUM('movie','series') NOT NULL,
    tmdb_id             INT NOT NULL,
    title               VARCHAR(255) NOT NULL,
    poster_url          VARCHAR(500) DEFAULT NULL,
    season              INT DEFAULT NULL,
    episode             INT DEFAULT NULL,
    status              ENUM('pending','approved','available','rejected') NOT NULL DEFAULT 'pending',
    rejection_reason    VARCHAR(255) DEFAULT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user_status (user_id, status),
    KEY idx_status (status),
    KEY idx_tmdb_id (tmdb_id),
    CONSTRAINT fk_requests_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
