-- Migrare: adaugare camp salariu in soferi
-- Data: 2026-05-15

SET NAMES utf8mb4;

SET @has_salariu := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'soferi'
      AND COLUMN_NAME = 'salariu'
);

SET @sql_add_salariu := IF(
    @has_salariu = 0,
    "ALTER TABLE soferi ADD COLUMN salariu DECIMAL(10,2) NULL AFTER telefon",
    'SELECT 1'
);

PREPARE stmt_add_salariu FROM @sql_add_salariu;
EXECUTE stmt_add_salariu;
DEALLOCATE PREPARE stmt_add_salariu;
