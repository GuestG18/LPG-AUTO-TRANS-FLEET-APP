-- Migrare: adauga campurile de orar pentru Dispecer curse
-- Data: 2026-05-11

SET NAMES utf8mb4;

SET @has_curse_ora_inceput := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND COLUMN_NAME = 'ora_inceput'
);
SET @sql_add_curse_ora_inceput := IF(
    @has_curse_ora_inceput = 0,
    'ALTER TABLE curse_dispecer ADD COLUMN ora_inceput TIME NULL AFTER data_sfarsit',
    'SELECT 1'
);
PREPARE stmt_add_curse_ora_inceput FROM @sql_add_curse_ora_inceput;
EXECUTE stmt_add_curse_ora_inceput;
DEALLOCATE PREPARE stmt_add_curse_ora_inceput;

SET @has_curse_ora_sfarsit := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND COLUMN_NAME = 'ora_sfarsit'
);
SET @sql_add_curse_ora_sfarsit := IF(
    @has_curse_ora_sfarsit = 0,
    'ALTER TABLE curse_dispecer ADD COLUMN ora_sfarsit TIME NULL AFTER ora_inceput',
    'SELECT 1'
);
PREPARE stmt_add_curse_ora_sfarsit FROM @sql_add_curse_ora_sfarsit;
EXECUTE stmt_add_curse_ora_sfarsit;
DEALLOCATE PREPARE stmt_add_curse_ora_sfarsit;

SET @has_curse_durata_minute := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND COLUMN_NAME = 'durata_cursa_minute'
);
SET @sql_add_curse_durata_minute := IF(
    @has_curse_durata_minute = 0,
    'ALTER TABLE curse_dispecer ADD COLUMN durata_cursa_minute INT UNSIGNED NULL AFTER ora_sfarsit',
    'SELECT 1'
);
PREPARE stmt_add_curse_durata_minute FROM @sql_add_curse_durata_minute;
EXECUTE stmt_add_curse_durata_minute;
DEALLOCATE PREPARE stmt_add_curse_durata_minute;
