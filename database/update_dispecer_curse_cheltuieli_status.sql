-- Migrare: marcheaza daca o cursa are cheltuieli in asteptare sau nu e cazul
-- Data: 2026-06-02

SET NAMES utf8mb4;

SET @has_curse_cheltuieli_status := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND COLUMN_NAME = 'cheltuieli_status'
);
SET @sql_add_curse_cheltuieli_status := IF(
    @has_curse_cheltuieli_status = 0,
    'ALTER TABLE curse_dispecer ADD COLUMN cheltuieli_status ENUM(''pending'', ''not_applicable'') NOT NULL DEFAULT ''pending'' AFTER observatii',
    'SELECT 1'
);
PREPARE stmt_add_curse_cheltuieli_status FROM @sql_add_curse_cheltuieli_status;
EXECUTE stmt_add_curse_cheltuieli_status;
DEALLOCATE PREPARE stmt_add_curse_cheltuieli_status;
