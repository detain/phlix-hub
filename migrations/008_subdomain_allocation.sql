-- migration: 008_subdomain_allocation
--
-- Adds subdomain column to servers table for public hostname claim.
-- Also creates dns_challenges table for Let's Encrypt ACME DNS-01 challenges.
--
-- Background: Each server gets a unique subdomain (e.g. abc12345.phlix.media)
-- when it enrolls. The hub operator configures wildcard DNS for *.phlix.media.
-- TLS certificates are provisioned via Let's Encrypt ACME DNS-01 challenge.

-- Idempotency is provided by the MigrationRunner tracking table; plain
-- `ADD COLUMN` / `ADD INDEX` keep the SQL portable across MySQL 8 and
-- MariaDB.
-- idempotency guard (review hub M4, 2026-11-02): the runner re-applies a file
-- whose checksum diverged (README *Database migrations*). A bare ADD would then raise
-- 1060/1061, the checksum would never refresh, and the chain would wedge. The probe
-- below skips the statement when its object already exists; fresh applies are
-- unaffected (probe returns 0 → the ALTER runs verbatim).
--
-- PAIRED probe (review minor, 2026-11-03): this ALTER creates TWO objects — the
-- `subdomain` column and the `ix_servers_subdomain` index — so a probe that names
-- only one of them can skip work the other object still needs. The column half and
-- the index half each carry their own information_schema probe (the 034 STATISTICS
-- idiom), so a replay heals whichever one is missing instead of trusting the other
-- to have travelled with it. Order matters: the index probe runs after the column
-- is guaranteed present.
SET @phlix_guard_sql_column = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
       WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = 'servers'
         AND COLUMN_NAME = 'subdomain') = 0,
    'ALTER TABLE servers ADD COLUMN subdomain VARCHAR(63) NULL AFTER capabilities',
    'SELECT 1');
PREPARE phlix_guard_column FROM @phlix_guard_sql_column;
EXECUTE phlix_guard_column;
DEALLOCATE PREPARE phlix_guard_column;

SET @phlix_guard_sql_index = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
       WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = 'servers'
         AND INDEX_NAME = 'ix_servers_subdomain') = 0,
    'ALTER TABLE servers ADD INDEX ix_servers_subdomain (subdomain)',
    'SELECT 1');
PREPARE phlix_guard_index FROM @phlix_guard_sql_index;
EXECUTE phlix_guard_index;
DEALLOCATE PREPARE phlix_guard_index;

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
