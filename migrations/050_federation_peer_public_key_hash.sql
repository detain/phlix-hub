-- migration: 050_federation_peer_public_key_hash
--
-- Review hub Major 35 (dba2 M1). FederationHubRepository::getPeerByPublicKey()
-- is on the inbound handshake security-gate path (FederationFrameHandler
-- hub_hello, FederationController registration uniqueness probe) and matched
--
--     ... FROM federation_peers WHERE public_key = :public_key LIMIT 1
--
-- against a TEXT column with no index (028 gives the table only PK, uq_url and
-- idx_status; 046 adds uq leaf_hub_id), so every handshake full-scans the
-- table. The table is small today (manually registered peers) — impact is
-- bounded — but this is exactly the pattern the estate cured for claim codes
-- (048_claim_code_norm): a STORED generated hash column with its own index
-- turns the scan into a single index hit.
--
--     public_key_hash CHAR(64) GENERATED ALWAYS AS (LOWER(SHA2(public_key, 256))) STORED
--     UNIQUE KEY uq_federation_peers_public_key_hash (public_key_hash)
--
-- WHY HASH AND NOT A PREFIX INDEX: SHA-256 is byte-exact. The old predicate
-- compared through the table's utf8mb4_unicode_ci collation — PAD SPACE and
-- case-insensitive — so it could (a) false-match two keys differing only in
-- case or trailing space (the doomed peer then failed its signature check
-- anyway, but via a confusing longer route) and (b) never match a key the
-- collation deemed distinct-but-equal... nothing is lost: the repository now
-- queries by hash('sha256', $publicKey) (lowercase hex — the same bytes
-- LOWER(SHA2(...)) yields in MySQL) and the registered-key semantics tighten
-- to exact byte identity. Signature verification is unchanged; only the lookup
-- path is.
--
-- WHY UNIQUE: two peers registered with the same Ed25519 public key are a
-- registration bug the handshake cannot disambiguate (LIMIT 1 picks an
-- arbitrary row). The constraint makes that impossible at the schema level —
-- the same "constraint in the schema, not in each writer" law as 048.
--
-- THE GUARD (048/096 idiom). The UNIQUE ALTER fails opaquely (1062) on a
-- legacy database that ALREADY holds case-variant or exact duplicate keys,
-- which would abort the whole migration chain at boot. So the duplicate scan
-- runs BEFORE any DDL — and on a dirty table the guard PREPAREs (but never
-- EXECUTEs) a long backticked identifier carrying the remedy in the error
-- text, leaving the file un-recorded and retryable after remediation.
-- Outcome table:
--   a. UNIQUE index of the right shape present (healthy DB, and every
--      checksum re-apply) → `SELECT 0` — true no-op.
--   b. duplicate hashes among rows → guard prepare fails with fix
--      instructions; nothing altered.
--   c. column present, index missing (hand-patched DB) → add just the KEY.
--   d. clean and absent → combined ADD COLUMN + ADD UNIQUE KEY (one ALTER,
--      so a crash can never leave the column without its index).

-- (a) UNIQUE index covering exactly {public_key_hash}, under any name?
SET @phlix_pkh_unique = (
    SELECT COUNT(*) FROM (
        SELECT INDEX_NAME
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'federation_peers'
        AND NON_UNIQUE = 0
        GROUP BY INDEX_NAME
        HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME) = 'public_key_hash'
    ) phlix_pkh_index_shapes
);

-- Does the generated column itself exist (index absent but column present)?
SET @phlix_pkh_column = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'federation_peers'
    AND COLUMN_NAME = 'public_key_hash'
);

-- (b) Colliding hashes — only scanned when the constraint is missing. Exact
-- duplicates AND collation-variant duplicates (case/space) both land here,
-- because the scan groups by the byte-exact expression the column will hold.
SET @phlix_pkh_dupes = IF(
    @phlix_pkh_unique > 0,
    0,
    (
        SELECT EXISTS (
            SELECT 1 FROM federation_peers
            GROUP BY LOWER(SHA2(public_key, 256))
            HAVING COUNT(*) > 1
        )
    )
);

-- Guard (outcome b). Prepared, never executed: on a dirty table the PREPARE
-- itself fails and the remedy travels in the error text.
SET @phlix_guard_sql = IF(
    @phlix_pkh_dupes = 1,
    'SELECT `federation_peers: duplicate peer public keys - de-duplicate rows first`',
    'SELECT 0'
);

PREPARE stmt FROM @phlix_guard_sql;

-- Outcomes (a), (c), (d).
SET @phlix_pkh_sql = IF(
    @phlix_pkh_unique > 0,
    'SELECT 0',
    IF(
        @phlix_pkh_column = 1,
        'ALTER TABLE federation_peers ADD UNIQUE KEY uq_federation_peers_public_key_hash (public_key_hash)',
        'ALTER TABLE federation_peers ADD COLUMN public_key_hash CHAR(64) GENERATED ALWAYS AS (LOWER(SHA2(public_key, 256))) STORED AFTER public_key, ADD UNIQUE KEY uq_federation_peers_public_key_hash (public_key_hash)'
    )
);

PREPARE stmt FROM @phlix_pkh_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
