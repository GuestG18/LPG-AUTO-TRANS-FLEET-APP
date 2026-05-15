-- Migrare: adauga campul cantitate_prelevata in curse_dispecer
-- Data: 2026-05-08

SET NAMES utf8mb4;

SET @has_curse_cantitate_prelevata := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND COLUMN_NAME = 'cantitate_prelevata'
);
SET @sql_add_curse_cantitate_prelevata := IF(
    @has_curse_cantitate_prelevata = 0,
    'ALTER TABLE curse_dispecer ADD COLUMN cantitate_prelevata DECIMAL(12,2) NULL AFTER cantitate_incarcata',
    'SELECT 1'
);
PREPARE stmt_add_curse_cantitate_prelevata FROM @sql_add_curse_cantitate_prelevata;
EXECUTE stmt_add_curse_cantitate_prelevata;
DEALLOCATE PREPARE stmt_add_curse_cantitate_prelevata;
