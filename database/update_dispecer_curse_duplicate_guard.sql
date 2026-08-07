-- Migrare: protectie impotriva curselor duplicate salvate simultan.
-- Valorile duplicate_key sunt calculate/backfill-uite in aplicatie pentru aceeasi normalizare la salvare.

SET @has_curse_duplicate_key := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND COLUMN_NAME = 'duplicate_key'
);
SET @sql_add_curse_duplicate_key := IF(
    @has_curse_duplicate_key = 0,
    'ALTER TABLE curse_dispecer ADD COLUMN duplicate_key CHAR(64) NULL AFTER updated_at',
    'SELECT 1'
);
PREPARE stmt_add_curse_duplicate_key FROM @sql_add_curse_duplicate_key;
EXECUTE stmt_add_curse_duplicate_key;
DEALLOCATE PREPARE stmt_add_curse_duplicate_key;

SET @has_uk_curse_duplicate_key := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND INDEX_NAME = 'uk_curse_dispecer_duplicate_key'
);
SET @sql_add_uk_curse_duplicate_key := IF(
    @has_uk_curse_duplicate_key = 0,
    'ALTER TABLE curse_dispecer ADD UNIQUE KEY uk_curse_dispecer_duplicate_key (duplicate_key)',
    'SELECT 1'
);
PREPARE stmt_add_uk_curse_duplicate_key FROM @sql_add_uk_curse_duplicate_key;
EXECUTE stmt_add_uk_curse_duplicate_key;
DEALLOCATE PREPARE stmt_add_uk_curse_duplicate_key;
