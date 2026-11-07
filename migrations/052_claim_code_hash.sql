-- migration: 052_claim_code_hash
--
-- SH-049. Server pairing codes were stored in PLAINTEXT. The 8-character
-- claim code is the bearer secret that attaches a headless server to a hub
-- account: `server_claims.claim_code` held it verbatim (with
-- `claim_code_norm`, migration 048's generated lookup twin), so any row dump
-- — backup, replica, an operator's SELECT — carried live pairing material for
-- the whole 600 s window. Every other bearer family in this hub stores its
-- secret one-way at rest: invite links, client relay tokens, MCP PATs, OAuth
-- codes and refresh tokens are all sha256-hashed, claim codes were the odd
-- ones out. This file makes them equal: `claim_code_hash CHAR(64)`, storing
-- sha256 over the canonical normalised form (non-alphanumerics stripped,
-- uppercased — the exact normalisation the R20-04 fingerprint and the old
-- generated column shared), UNIQUE for the handler's FOR UPDATE equality.
--
-- Case-insensitive matching moves application-side: the hash is taken over
-- the normalised input before binding, so lenient display variants
-- ("dr4q 7axb", "DR4Q-7AXB") still hit the same row — pinned by the handler
-- law tests. The column itself pins BINARY semantics (ascii/ascii_bin) so no
-- table collation can ever fold or pad-compare two hashes.
--
-- THE PURGE (every outcome except (a)). `server_claims` is transient by
-- doctrine — a pending row is useful for CLAIM_TTL_SECONDS = 600 s and
-- assertRoomForNewClaim() reaps expired rows as reclaimable garbage. In-flight
-- pairings on upgrade DO die here, honestly: the headless server's next
-- POST /api/v1/server-claims mints a FRESH code for the same keypair (the
-- rotate-on-retry arm, same claim id, remaining TTL), and the console
-- re-reads it. Keeping the rows would have meant copying plaintext into the
-- hash column mid-window — the very exposure this file closes.
--
-- GUARD (048's idiom: probe information_schema shape, drive every mutation
-- through PREPARE, never a raw ALTER). The half-states a crash-window replay
-- or a hand-patched DB can leave each decide their own remedy:
--
--   a. UNIQUE index of exactly {claim_code_hash} present AND no legacy code
--      columns → `SELECT 0`. True no-op: replay-safe under the checksum law,
--      rows untouched.
--   b. UNIQUE hash index present but legacy `claim_code` and/or
--      `claim_code_norm` survive (a hand-patched twin-store) → purge, then
--      drop the leftovers only.
--   c. hash column present but its UNIQUE missing → purge, then add just the
--      UNIQUE KEY (+ any surviving legacy drops).
--   d. legacy shape (code columns, no hash) → purge, then ONE combined
--      ALTER: DROP `claim_code_norm` FIRST (MySQL refuses to drop a base
--      column while a STORED generated column still derives from it), DROP
--      `claim_code`, ADD the hash column, ADD the UNIQUE KEY.
--   e. neither shape present (defensive arm) → purge + the fresh-add half of
--      (d)'s statement.
--
-- The purge rides its own PREPARE because the prepared-statement protocol
-- takes one statement per slot; `stmt` is reused after every DEALLOCATE so a
-- failed arm leaves nothing dangling to cascade 1243s on top of the error
-- that matters. The data-level collision worries that needed a remedy-naming
-- guard in 048 (duplicate norms, over-width codes) cannot exist here: the
-- purge deletes every row before any shape decision executes, and a 64-hex
-- column has no truncation path.

-- (Probe 1) Is a UNIQUE index covering exactly {claim_code_hash} present?
-- Shape, not name — a same-named non-unique imposter must not satisfy this
-- file (the server 096 / hub 048 lesson, applied at the new key).
SET @phlix_hash_unique = (
    SELECT COUNT(*) FROM (
        SELECT INDEX_NAME
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'server_claims'
        AND NON_UNIQUE = 0
        GROUP BY INDEX_NAME
        HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME) = 'claim_code_hash'
    ) phlix_hash_index_shapes
);

-- (Probe 2) Does the hash column itself exist (index absent but column
-- present — outcome (c))?
SET @phlix_hash_column = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'server_claims'
    AND COLUMN_NAME = 'claim_code_hash'
);

-- (Probes 3/4) Do the legacy plaintext and its generated twin survive?
SET @phlix_code_column = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'server_claims'
    AND COLUMN_NAME = 'claim_code'
);
SET @phlix_norm_column = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'server_claims'
    AND COLUMN_NAME = 'claim_code_norm'
);

-- Purge everything except the healthy already-migrated table (outcome (a)).
SET @phlix_purge_sql = IF(
    @phlix_hash_unique = 1 AND @phlix_code_column = 0 AND @phlix_norm_column = 0,
    'SELECT 0',
    'DELETE FROM server_claims'
);

PREPARE stmt FROM @phlix_purge_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Outcomes (a)–(e). Clause fragments each carry their own leading ', ' and
-- the assembled list is TRIM(LEADING ', ' FROM ...) so an arm that drops
-- nothing still starts its ADD cleanly. Legacy drops are emitted
-- dependents-first (norm before code) in every arm that carries them; the
-- fresh-add arm lands the column AFTER id — beside where the plaintext used
-- to live, and where the handler's INSERT now writes.
SET @phlix_ddl_sql = CASE
    WHEN @phlix_hash_unique = 1 AND @phlix_code_column = 0 AND @phlix_norm_column = 0
        THEN 'SELECT 0'
    WHEN @phlix_hash_unique = 1
        THEN CONCAT(
            'ALTER TABLE server_claims ',
            TRIM(LEADING ', ' FROM CONCAT(
                IF(@phlix_norm_column = 1, ', DROP COLUMN claim_code_norm', ''),
                IF(@phlix_code_column = 1, ', DROP COLUMN claim_code', '')
            ))
        )
    WHEN @phlix_hash_column = 1
        THEN CONCAT(
            'ALTER TABLE server_claims ',
            TRIM(LEADING ', ' FROM CONCAT(
                IF(@phlix_norm_column = 1, ', DROP COLUMN claim_code_norm', ''),
                IF(@phlix_code_column = 1, ', DROP COLUMN claim_code', ''),
                ', ADD UNIQUE KEY uk_server_claims_code_hash (claim_code_hash)'
            ))
        )
    ELSE CONCAT(
        'ALTER TABLE server_claims ',
        TRIM(LEADING ', ' FROM CONCAT(
            IF(@phlix_norm_column = 1, ', DROP COLUMN claim_code_norm', ''),
            IF(@phlix_code_column = 1, ', DROP COLUMN claim_code', ''),
            ', ADD COLUMN claim_code_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL AFTER id',
            ', ADD UNIQUE KEY uk_server_claims_code_hash (claim_code_hash)'
        ))
    )
END;

PREPARE stmt FROM @phlix_ddl_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
