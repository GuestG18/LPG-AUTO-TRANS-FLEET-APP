-- Migrare: adauga campuri text de locatie pentru cursele Compresor
-- Data: 2026-05-20

SET NAMES utf8mb4;

SET @has_curse_loc_plecare := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND COLUMN_NAME = 'loc_plecare'
);
SET @sql_add_curse_loc_plecare := IF(
    @has_curse_loc_plecare = 0,
    'ALTER TABLE curse_dispecer ADD COLUMN loc_plecare VARCHAR(255) NULL AFTER loc_incarcare_id',
    'SELECT 1'
);
PREPARE stmt_add_curse_loc_plecare FROM @sql_add_curse_loc_plecare;
EXECUTE stmt_add_curse_loc_plecare;
DEALLOCATE PREPARE stmt_add_curse_loc_plecare;

SET @has_curse_loc_aspirare := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND COLUMN_NAME = 'loc_aspirare'
);
SET @sql_add_curse_loc_aspirare := IF(
    @has_curse_loc_aspirare = 0,
    'ALTER TABLE curse_dispecer ADD COLUMN loc_aspirare VARCHAR(255) NULL AFTER loc_plecare',
    'SELECT 1'
);
PREPARE stmt_add_curse_loc_aspirare FROM @sql_add_curse_loc_aspirare;
EXECUTE stmt_add_curse_loc_aspirare;
DEALLOCATE PREPARE stmt_add_curse_loc_aspirare;

SET @has_curse_loc_livrare := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND COLUMN_NAME = 'loc_livrare'
);
SET @sql_add_curse_loc_livrare := IF(
    @has_curse_loc_livrare = 0,
    'ALTER TABLE curse_dispecer ADD COLUMN loc_livrare VARCHAR(255) NULL AFTER loc_aspirare',
    'SELECT 1'
);
PREPARE stmt_add_curse_loc_livrare FROM @sql_add_curse_loc_livrare;
EXECUTE stmt_add_curse_loc_livrare;
DEALLOCATE PREPARE stmt_add_curse_loc_livrare;

SET @has_curse_loc_livrare_cursa := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND COLUMN_NAME = 'loc_livrare_cursa'
);
SET @sql_add_curse_loc_livrare_cursa := IF(
    @has_curse_loc_livrare_cursa = 0,
    'ALTER TABLE curse_dispecer ADD COLUMN loc_livrare_cursa VARCHAR(255) NULL AFTER loc_livrare',
    'SELECT 1'
);
PREPARE stmt_add_curse_loc_livrare_cursa FROM @sql_add_curse_loc_livrare_cursa;
EXECUTE stmt_add_curse_loc_livrare_cursa;
DEALLOCATE PREPARE stmt_add_curse_loc_livrare_cursa;
