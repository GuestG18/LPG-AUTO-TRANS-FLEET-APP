-- Migrare: interval cursa (data_inceput/data_sfarsit) pentru modul Dispecer curse
-- Rulare: in baza de date aplicatie_fleet

SET @has_data_inceput := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND COLUMN_NAME = 'data_inceput'
);
SET @sql_add_data_inceput := IF(
    @has_data_inceput = 0,
    'ALTER TABLE curse_dispecer ADD COLUMN data_inceput DATE NULL AFTER data_cursa',
    'SELECT 1'
);
PREPARE stmt_add_data_inceput FROM @sql_add_data_inceput;
EXECUTE stmt_add_data_inceput;
DEALLOCATE PREPARE stmt_add_data_inceput;

SET @has_data_sfarsit := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND COLUMN_NAME = 'data_sfarsit'
);
SET @sql_add_data_sfarsit := IF(
    @has_data_sfarsit = 0,
    'ALTER TABLE curse_dispecer ADD COLUMN data_sfarsit DATE NULL AFTER data_inceput',
    'SELECT 1'
);
PREPARE stmt_add_data_sfarsit FROM @sql_add_data_sfarsit;
EXECUTE stmt_add_data_sfarsit;
DEALLOCATE PREPARE stmt_add_data_sfarsit;

UPDATE curse_dispecer
SET data_inceput = data_cursa
WHERE data_inceput IS NULL;

UPDATE curse_dispecer
SET data_sfarsit = COALESCE(data_inceput, data_cursa)
WHERE data_sfarsit IS NULL;

ALTER TABLE curse_dispecer
MODIFY COLUMN data_inceput DATE NOT NULL,
MODIFY COLUMN data_sfarsit DATE NOT NULL;

SET @has_idx_data_inceput := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND INDEX_NAME = 'idx_curse_data_inceput'
);
SET @sql_add_idx_data_inceput := IF(
    @has_idx_data_inceput = 0,
    'ALTER TABLE curse_dispecer ADD INDEX idx_curse_data_inceput (data_inceput)',
    'SELECT 1'
);
PREPARE stmt_add_idx_data_inceput FROM @sql_add_idx_data_inceput;
EXECUTE stmt_add_idx_data_inceput;
DEALLOCATE PREPARE stmt_add_idx_data_inceput;

SET @has_idx_data_sfarsit := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND INDEX_NAME = 'idx_curse_data_sfarsit'
);
SET @sql_add_idx_data_sfarsit := IF(
    @has_idx_data_sfarsit = 0,
    'ALTER TABLE curse_dispecer ADD INDEX idx_curse_data_sfarsit (data_sfarsit)',
    'SELECT 1'
);
PREPARE stmt_add_idx_data_sfarsit FROM @sql_add_idx_data_sfarsit;
EXECUTE stmt_add_idx_data_sfarsit;
DEALLOCATE PREPARE stmt_add_idx_data_sfarsit;