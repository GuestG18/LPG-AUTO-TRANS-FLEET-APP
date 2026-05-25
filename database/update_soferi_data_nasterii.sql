-- Migrare: adaugare camp data_nasterii in soferi
-- Data: 2026-05-20

SET NAMES utf8mb4;

SET @has_data_nasterii := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'soferi'
      AND COLUMN_NAME = 'data_nasterii'
);

SET @sql_add_data_nasterii := IF(
    @has_data_nasterii = 0,
    "ALTER TABLE soferi ADD COLUMN data_nasterii DATE NULL AFTER nume",
    'SELECT 1'
);

PREPARE stmt_add_data_nasterii FROM @sql_add_data_nasterii;
EXECUTE stmt_add_data_nasterii;
DEALLOCATE PREPARE stmt_add_data_nasterii;
