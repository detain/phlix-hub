-- migration: 008_subdomain_allocation
--
-- Adds subdomain column to servers table for public hostname claim.
-- Also creates dns_challenges table for Let's Encrypt ACME DNS-01 challenges.
--
-- Background: Each server gets a unique subdomain (e.g. abc12345.phlix.media)
-- when it enrolls. The hub operator configures wildcard DNS for *.phlix.media.
-- TLS certificates are provisioned via Let's Encrypt ACME DNS-01 challenge.

-- `ADD COLUMN IF NOT EXISTS` / `ADD INDEX IF NOT EXISTS` keep this
-- re-runnable (matching the idempotency convention in migrations 007
-- and 012) even though the MigrationRunner tracking table already
-- guards against double-apply.
ALTER TABLE servers
    ADD COLUMN IF NOT EXISTS subdomain VARCHAR(63) NULL AFTER capabilities,
    ADD INDEX IF NOT EXISTS ix_servers_subdomain (subdomain);

CREATE TABLE IF NOT EXISTS dns_challenges (
    id                       CHAR(36) NOT NULL,
    server_id                CHAR(36) NOT NULL,
    subdomain                VARCHAR(63) NOT NULL,
    challenge_token          VARCHAR(128) NOT NULL,
    challenge_response       VARCHAR(256) NOT NULL,
    status                   ENUM('pending', 'verified', 'failed', 'expired') NOT NULL DEFAULT 'pending',
    expires_at               DATETIME NOT NULL,
    created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    verified_at              DATETIME NULL,
    PRIMARY KEY (id),
    INDEX ix_dns_challenges_server (server_id),
    INDEX ix_dns_challenges_status (status, expires_at),
    CONSTRAINT fk_dns_challenges_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
