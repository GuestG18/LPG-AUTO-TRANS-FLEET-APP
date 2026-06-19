-- Migrare: adauga campul Data incarcare pentru Dispecer curse
-- Data: 2026-06-18

SET NAMES utf8mb4;

SET @has_curse_data_incarcare := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND COLUMN_NAME = 'data_incarcare'
);
SET @sql_add_curse_data_incarcare := IF(
    @has_curse_data_incarcare = 0,
    'ALTER TABLE curse_dispecer ADD COLUMN data_incarcare DATE NULL AFTER data_cursa',
    'SELECT 1'
);
PREPARE stmt_add_curse_data_incarcare FROM @sql_add_curse_data_incarcare;
EXECUTE stmt_add_curse_data_incarcare;
DEALLOCATE PREPARE stmt_add_curse_data_incarcare;
