-- migration: 049_invite_token_hash_unique
--
-- R19-08 (MEDIUM). invite_links.token_hash — the ONLY lookup key the redemption
-- path uses — was declared VARCHAR(255) with a plain (non-unique) INDEX. Two
-- defects ride that shape:
--
--  1. Over-consumption. The atomic single-use claim is
--
--       UPDATE invite_links SET use_count = use_count + 1
--        WHERE token_hash = ? AND use_count < max_uses AND (expiry ok)
--
--     which relies on "exactly one row can match the hash". With a non-unique
--     index, a database holding two rows with the same token_hash increments
--     BOTH in one statement: the handler's `affected === 1` check then rejects
--     the redeemer (2 != 1) while two invites have already burned a use —
--     over-consumption with no beneficiary. Uniqueness is not an optimisation
--     here, it is the invariant the redemption protocol is stated on.
--  2. Width theater. Every writer stores hash('sha256', $token) — exactly 64
--     lowercase hex chars — and the redeemer refuses anything that is not
--     /\A[0-9a-f]{64}\z/ before hashing, so no non-64 value can EVER match.
--     VARCHAR(255) just makes the index key longer than the domain.
--
-- THE FIX: token_hash CHAR(64) NOT NULL + UNIQUE KEY uk_invite_links_token_hash,
-- dropping the plain idx_token_hash (the UNIQUE key serves every equality
-- lookup it did). The charset stays utf8mb4 per table default; hex is ASCII,
-- so the practical storage is 64 bytes either way, but CHAR(64) makes the
-- invariant visible in the live table definition (SHOW CREATE output).
--
-- THE GUARD (hub 048 / server 096 idiom). A duplicate token_hash can exist
-- today only via out-of-band writes (a DB restore, raw SQL) — sha256 does not
-- collide — and a row wider than 64 chars likewise cannot come from the
-- handler. On such a database the ALTER would fail with an opaque 1062/1406
-- and ABORT THE WHOLE hub chain (the hub MigrationRunner throws on the first
-- failed statement). Both states are data corruption the operator must see
-- described, so the guard PREPAREs (never EXECUTEs) a long backticked
-- identifier carrying the remedy — the error channel server 096 established,
-- including the reuse of the `stmt` name so a failed PREPARE cascades exactly
-- ONE error. Outcomes:
--
--   a. UNIQUE over {token_hash} present AND column already CHAR(64) → SELECT 0
--      (idempotent under the checksum re-apply law; healthy DBs and fresh
--      installs land here after the first pass).
--   b. duplicates → loud failure, nothing altered, file stays un-recorded.
--   c. width violation (any stored hash not exactly 64 chars) → loud failure.
--   d. UNIQUE present but column still VARCHAR (hand-patched) → MODIFY only;
--      the constraint itself proves there are no duplicates, so (b)/(c) are
--      skipped by construction.
--   e. constraint missing on a clean table → one atomic ALTER: narrow the
--      column if needed, drop the plain index (by whatever non-unique name it
--      actually carries — S161's shape-not-name lesson), add the UNIQUE key.
--      A 1062 racing in between rolls the whole ALTER back, so the table is
--      never left with the plain index gone and no constraint in place.
--
-- The application needs NO change: the writer already emits exactly 64 hex
-- chars and the reader already pre-rejects foreign shapes; MODIFY/ADD is
-- backward-compatible with both.

-- (a)/(e) probe: a UNIQUE index covering exactly {token_hash}, under ANY name.
SET @phlix_invite_unique = (
    SELECT COUNT(*) FROM (
        SELECT INDEX_NAME
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'invite_links'
        AND NON_UNIQUE = 0
        GROUP BY INDEX_NAME
        HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME) = 'token_hash'
    ) phlix_invite_unique_shapes
);

-- The column contract: CHAR (not VARCHAR) of exactly 64.
SET @phlix_invite_char64 = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'invite_links'
    AND COLUMN_NAME = 'token_hash'
    AND DATA_TYPE = 'char'
    AND CHARACTER_MAXIMUM_LENGTH = 64
);

-- Name of the plain non-unique index to drop, if one exists (the 010 name is
-- idx_token_hash, but shape-not-name: honour whatever is really there).
SET @phlix_invite_plain = (
    SELECT MIN(INDEX_NAME)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'invite_links'
    AND NON_UNIQUE = 1
    GROUP BY INDEX_NAME
    HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME) = 'token_hash'
    LIMIT 1
);

-- (b) Duplicates — scanned only when the constraint is missing (the UNIQUE
-- index is itself the proof there are none).
SET @phlix_invite_dupes = IF(
    @phlix_invite_unique > 0,
    0,
    (
        SELECT EXISTS (
            SELECT 1 FROM invite_links
            GROUP BY token_hash
            HAVING COUNT(*) > 1
        )
    )
);

-- (c) Rows outside the 64-hex domain — same skip condition as (b).
SET @phlix_invite_width = IF(
    @phlix_invite_unique > 0 OR @phlix_invite_dupes = 1,
    0,
    (
        SELECT EXISTS (
            SELECT 1 FROM invite_links
            WHERE CHAR_LENGTH(token_hash) <> 64
        )
    )
);

-- Guard (outcomes b and c). Prepared, never executed.
SET @phlix_invite_guard_sql = IF(
    @phlix_invite_dupes = 1,
    'SELECT `invite_links: duplicate token_hash rows - investigate restore`',
    IF(
        @phlix_invite_width = 1,
        'SELECT `invite_links: token_hash not 64 hex chars - investigate rows`',
        'SELECT 0'
    )
);

PREPARE stmt FROM @phlix_invite_guard_sql;

-- Outcomes (a), (d), (e). Assembled with CONCAT so the DROP INDEX only appears
-- when a plain index actually exists, under its real name.
SET @phlix_invite_sql = IF(
    @phlix_invite_unique > 0 AND @phlix_invite_char64 > 0,
    'SELECT 0',
    IF(
        @phlix_invite_unique > 0,
        -- (d): constraint already holds, only the width needs narrowing.
        'ALTER TABLE invite_links MODIFY COLUMN token_hash CHAR(64) NOT NULL',
        CONCAT(
            'ALTER TABLE invite_links',
            IF(@phlix_invite_char64 = 0, ' MODIFY COLUMN token_hash CHAR(64) NOT NULL', ''),
            IF(@phlix_invite_plain IS NULL, '', CONCAT(', DROP INDEX `', @phlix_invite_plain, '`')),
            ', ADD UNIQUE KEY uk_invite_links_token_hash (token_hash)'
        )
    )
);

PREPARE stmt FROM @phlix_invite_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
