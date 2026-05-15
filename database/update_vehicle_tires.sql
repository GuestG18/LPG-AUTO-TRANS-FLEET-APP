-- Migrare: adaugare campuri pentru urmarire anvelope vehicul
-- Data: 2026-05-12

SET NAMES utf8mb4;

SET @has_anvelope_model := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'vehicule'
      AND COLUMN_NAME = 'anvelope_model'
);

SET @sql_add_anvelope_model := IF(
    @has_anvelope_model = 0,
    "ALTER TABLE vehicule ADD COLUMN anvelope_model VARCHAR(120) NULL AFTER garaj",
    'SELECT 1'
);

PREPARE stmt_add_anvelope_model FROM @sql_add_anvelope_model;
EXECUTE stmt_add_anvelope_model;
DEALLOCATE PREPARE stmt_add_anvelope_model;

SET @has_anvelope_km_durata := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'vehicule'
      AND COLUMN_NAME = 'anvelope_km_durata'
);

SET @sql_add_anvelope_km_durata := IF(
    @has_anvelope_km_durata = 0,
    "ALTER TABLE vehicule ADD COLUMN anvelope_km_durata INT UNSIGNED NULL AFTER anvelope_model",
    'SELECT 1'
);

PREPARE stmt_add_anvelope_km_durata FROM @sql_add_anvelope_km_durata;
EXECUTE stmt_add_anvelope_km_durata;
DEALLOCATE PREPARE stmt_add_anvelope_km_durata;

SET @has_anvelope_km_montaj := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'vehicule'
      AND COLUMN_NAME = 'anvelope_km_montaj'
);

SET @sql_add_anvelope_km_montaj := IF(
    @has_anvelope_km_montaj = 0,
    "ALTER TABLE vehicule ADD COLUMN anvelope_km_montaj INT UNSIGNED NULL AFTER anvelope_km_durata",
    'SELECT 1'
);

PREPARE stmt_add_anvelope_km_montaj FROM @sql_add_anvelope_km_montaj;
EXECUTE stmt_add_anvelope_km_montaj;
DEALLOCATE PREPARE stmt_add_anvelope_km_montaj;
