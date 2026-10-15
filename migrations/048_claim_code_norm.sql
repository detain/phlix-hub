-- migration: 048_claim_code_norm
--
-- R19-01 (HIGH). Server pairing locked the WHOLE table. The claim lookup in
-- ClaimRequestHandler::handleClaimCode() is a locking read written as:
--
--     SELECT * FROM server_claims WHERE REPLACE(claim_code, '-', '') = ? FOR UPDATE
--
-- Wrapping the column in REPLACE() makes the predicate non-sargable: the
-- `uk_server_claims_code` UNIQUE index on claim_code cannot be used, so InnoDB
-- full-scans `server_claims` and — because the statement is FOR UPDATE under
-- the default REPEATABLE READ isolation — takes NEXT-KEY locks across every
-- record and gap it examines. Every concurrent pairing therefore serialises
-- against every other one, including lookups for entirely different codes and
-- plain INSERTs of new claims (insert intention meets a locked gap). A pairing
-- burst is a self-inflicted lock storm on a table whose reads should all be
-- single-row unique-index hits.
--
-- THE FIX. A STORED generated column holding the de-dashed code, with its own
-- UNIQUE index:
--
--     claim_code_norm CHAR(8) GENERATED ALWAYS AS (REPLACE(claim_code, '-', '')) STORED
--     UNIQUE KEY uk_server_claims_code_norm (claim_code_norm)
--
-- The handler then matches `WHERE claim_code_norm = :code` — an equality
-- against an indexed column, an exact single-record unique-index hit under
-- FOR UPDATE, locking only that row (plus the ordinary duplicate-protection
-- gap when the code does not exist yet). Generated columns backfill
-- automatically: the ALTER computes the value for every existing row, and
-- every later INSERT/UPDATE of claim_code derives it — no application-side
-- double-write, so the two columns cannot drift. That is the whole point of
-- the constraint living in the schema rather than in each writer.
--
-- WHY CHAR(8) FITS. generateClaimCode() mints exactly `XXXX-XXXX` over a
-- 32-char unambiguous uppercase alphabet — 4 + dash + 4, so REPLACE() of the
-- dash yields exactly 8 characters. claim_code itself stays VARCHAR(16) with
-- its own UNIQUE key; the display form remains the stored truth and the wire
-- form (unchanged responses, unchanged INSERT). The generated column inherits
-- the table's utf8mb4_unicode_ci collation, which is PAD SPACE and
-- case-insensitive — matching the handler's own normaliser (strip
-- non-alphanumerics, uppercase), so lenient input like "dr4q 7axb" still hits.
--
-- THE GUARD (server migration 096 idiom, ported). A bare ALTER would fail
-- opaquely on exactly the databases that most need attention:
--
--   * `uk_server_claims_code` is UNIQUE over the DISPLAY form, so a legacy row
--     stored as `ABCD-1234` and another stored as `ABCD1234` coexist today and
--     collide the moment the normalised key is added (error 1062 with a
--     message that names only a uuid pair).
--   * A row whose code normalises to more than 8 characters overflows CHAR(8)
--     under strict mode (error 1406 string truncation).
--
-- Both cases abort the whole hub migration chain at this file (the hub
-- MigrationRunner throws on the first failed statement), so the failure must
-- CARRY ITS OWN REMEDY. MySQL has no portable custom-error statement in a
-- plain .sql file executed through the prepared-statement protocol, so the
-- unknown-identifier channel from server 096 is reused: the guard PREPAREs —
-- but never EXECUTEs — a SELECT of a long backticked identifier that MySQL
-- reports verbatim (identifiers are capped at 64 characters; both messages
-- below fit). Outcome table:
--
--   a. UNIQUE index of the right shape already present (every healthy DB,
--      and every checksum re-apply of this file) → `SELECT 0`. True no-op:
--      idempotent under the re-apply law, no 1061, no table rebuild.
--   b. duplicates → guard prepare fails with the fix instructions; nothing is
--      altered; the file stays un-recorded and retries after remediation.
--   c. bad width → same, with the width remedy.
--   d. column already added but index missing (a crash between the two in the
--      combined ALTER is impossible — one statement — but a hand-patched DB
--      is not) → add just the UNIQUE KEY.
--   e. clean and absent → the combined ADD COLUMN + ADD UNIQUE KEY.
--
-- The duplicate/width scans are skipped entirely once the constraint exists:
-- the index itself is the proof.

-- (a) Is a UNIQUE index covering exactly {claim_code_norm} already there,
-- under any name? Shape, not name — a same-named non-unique imposter must not
-- satisfy this file (server 096's S161 lesson, applied pre-emptively).
SET @phlix_norm_unique = (
    SELECT COUNT(*) FROM (
        SELECT INDEX_NAME
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'server_claims'
        AND NON_UNIQUE = 0
        GROUP BY INDEX_NAME
        HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME) = 'claim_code_norm'
    ) phlix_norm_index_shapes
);

-- Does the generated column itself exist (index absent but column present)?
SET @phlix_norm_column = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'server_claims'
    AND COLUMN_NAME = 'claim_code_norm'
);

-- (b) Normalised collisions — only scanned when the constraint is missing.
SET @phlix_norm_dupes = IF(
    @phlix_norm_unique > 0,
    0,
    (
        SELECT EXISTS (
            SELECT 1 FROM server_claims
            GROUP BY REPLACE(claim_code, '-', '')
            HAVING COUNT(*) > 1
        )
    )
);

-- (c) Codes that normalise past CHAR(8) — same skip condition; the width
-- guard also catches NULL-free garbage rows pre-ALTER instead of letting
-- strict mode answer with a bare 1406.
SET @phlix_norm_width = IF(
    @phlix_norm_unique > 0 OR @phlix_norm_dupes = 1,
    0,
    (
        SELECT EXISTS (
            SELECT 1 FROM server_claims
            WHERE CHAR_LENGTH(REPLACE(claim_code, '-', '')) > 8
        )
    )
);

-- Guard (outcomes b and c). Prepared, never executed: on a dirty table the
-- PREPARE itself fails and the remedy travels in the error text. Re-using
-- the name `stmt` below means a failed PREPARE leaves nothing dangling to
-- cascade 1243s on top of the one error that matters.
SET @phlix_guard_sql = IF(
    @phlix_norm_dupes = 1,
    'SELECT `server_claims: de-dashed code collision - fix duplicate codes`',
    IF(
        @phlix_norm_width = 1,
        'SELECT `server_claims: claim_code normalises past 8 chars - fix rows`',
        'SELECT 0'
    )
);

PREPARE stmt FROM @phlix_guard_sql;

-- Outcomes (a), (d), (e). The combined statement (add column + add key) is
-- one ALTER, so a clean DB can never be left with the column but no index.
SET @phlix_norm_sql = IF(
    @phlix_norm_unique > 0,
    'SELECT 0',
    IF(
        @phlix_norm_column = 1,
        'ALTER TABLE server_claims ADD UNIQUE KEY uk_server_claims_code_norm (claim_code_norm)',
        'ALTER TABLE server_claims ADD COLUMN claim_code_norm CHAR(8) GENERATED ALWAYS AS (REPLACE(claim_code, ''-'', '''')) STORED AFTER claim_code, ADD UNIQUE KEY uk_server_claims_code_norm (claim_code_norm)'
    )
);

PREPARE stmt FROM @phlix_norm_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
