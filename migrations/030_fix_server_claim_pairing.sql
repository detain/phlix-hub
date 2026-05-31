-- migration: 030_fix_server_claim_pairing
--
-- The server↔hub pairing flow never worked end-to-end. Two schema issues:
--
--  1. server_claims.expires_at / created_at (and paired_at) were created as
--     DATETIME (002), but every code path writes Unix-int timestamps via
--     time() — matching the claimed_at INT UNSIGNED column added in 007.
--     Writing an int into a DATETIME column raised
--     SQLSTATE[22007] 1292 "Incorrect datetime value" on the claim INSERT.
--     Normalise them to INT UNSIGNED so the existing code is correct.
--
--  2. Both server_claims and servers still carry a vestigial NOT NULL
--     `jwks_json` column (002) that the current code never populates (it
--     uses public_key_jwk, added in 007). On a strict-mode server that
--     NOT NULL with no default blocks the INSERT. Make it nullable.
--
-- Both tables are empty for this not-yet-functional flow, so the direct
-- type change is safe (no datetime→int value corruption to worry about).

ALTER TABLE server_claims
    MODIFY COLUMN expires_at INT UNSIGNED NOT NULL,
    MODIFY COLUMN created_at INT UNSIGNED NOT NULL,
    MODIFY COLUMN paired_at  INT UNSIGNED NULL,
    MODIFY COLUMN jwks_json  TEXT NULL;

ALTER TABLE servers
    MODIFY COLUMN jwks_json TEXT NULL;
