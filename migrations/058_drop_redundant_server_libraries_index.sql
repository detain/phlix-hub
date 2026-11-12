-- migration: 058_drop_redundant_server_libraries_index
--
-- HUB-S11 (HUB-23). `server_libraries` (031) carries KEY
-- idx_server_libraries_server_id (server_id) alongside UNIQUE KEY
-- unique_server_library (server_id, library_id). The unique key's leading
-- column IS server_id, so every `server_id = ?` lookup (the heartbeat prune
-- DELETE, ServerInfoHandler's COUNT / LEFT JOIN, LibrarySharingHandler's owner
-- listing) ranges the unique key's prefix exactly as it would the standalone
-- key; the standalone key holds no ordering the unique key does not. The FK
-- fk_server_libraries_server (server_id -> servers.id) is satisfied by the
-- unique key as its leftmost-prefix index, so dropping the standalone key does
-- not orphan the constraint.
--
-- WHAT IT COSTS TO KEEP: the heartbeat writers (HeartbeatHandler's batched
-- INSERT .. ON DUPLICATE KEY UPDATE and the NOT IN prune DELETE) maintain a
-- second full copy of the 36-byte server_id key plus its PK pointer on every
-- heartbeat, for zero read benefit.
--
-- WHY A NEW FILE: 031 is already applied elsewhere and immutable under the
-- checksum re-apply law (041). Replay-safe in both directions via the 054
-- shape probe: a non-unique index whose sole column is server_id (any name) ->
-- DROP INDEX under its real name; already gone -> `SELECT 0` no-op. The
-- NON_UNIQUE = 1 filter makes it structurally impossible to aim the DROP at
-- the unique key (which covers two columns anyway) or the primary key.
-- InnoDB DROP INDEX is INPLACE/metadata-only on MySQL 8.

SET @phlix_sl_redundant = (
    SELECT MIN(INDEX_NAME)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'server_libraries'
    AND NON_UNIQUE = 1
    GROUP BY INDEX_NAME
    HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME) = 'server_id'
    LIMIT 1
);

SET @phlix_sl_sql = IF(
    @phlix_sl_redundant IS NULL,
    'SELECT 0',
    CONCAT('ALTER TABLE server_libraries DROP INDEX `', @phlix_sl_redundant, '`')
);

PREPARE stmt FROM @phlix_sl_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
