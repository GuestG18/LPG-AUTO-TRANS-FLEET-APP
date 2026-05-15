-- Migrare: legatura anvelope -> mentenanta
-- Data: 2026-05-12

SET NAMES utf8mb4;

SET @db_name := DATABASE();

SET @has_mentenanta_id := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'anvelope'
      AND COLUMN_NAME = 'mentenanta_id'
);

SET @sql_add_mentenanta_id := IF(
    @has_mentenanta_id = 0,
    'ALTER TABLE anvelope ADD COLUMN mentenanta_id INT UNSIGNED NULL AFTER notes',
    'SELECT ''anvelope.mentenanta_id already exists'' AS message'
);
PREPARE stmt_add_mentenanta_id FROM @sql_add_mentenanta_id;
EXECUTE stmt_add_mentenanta_id;
DEALLOCATE PREPARE stmt_add_mentenanta_id;

SET @has_idx_mentenanta := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'anvelope'
      AND INDEX_NAME = 'idx_anvelope_mentenanta'
);

SET @sql_add_idx_mentenanta := IF(
    @has_idx_mentenanta = 0,
    'ALTER TABLE anvelope ADD INDEX idx_anvelope_mentenanta (mentenanta_id)',
    'SELECT ''idx_anvelope_mentenanta already exists'' AS message'
);
PREPARE stmt_add_idx_mentenanta FROM @sql_add_idx_mentenanta;
EXECUTE stmt_add_idx_mentenanta;
DEALLOCATE PREPARE stmt_add_idx_mentenanta;
