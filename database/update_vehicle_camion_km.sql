-- Migrare: adauga tip vehicul CAMION + camp Km bord
-- Data: 2026-04-20

SET NAMES utf8mb4;

SET @has_tip_vehicul := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'vehicule'
      AND COLUMN_NAME = 'tip_vehicul'
);

SET @sql_add_tip_vehicul := IF(
    @has_tip_vehicul = 0,
    "ALTER TABLE vehicule ADD COLUMN tip_vehicul ENUM('autovehicul', 'camion', 'cap_tractor', 'semiremorca') NOT NULL DEFAULT 'autovehicul' AFTER model",
    'SELECT 1'
);

PREPARE stmt_add_tip_vehicul FROM @sql_add_tip_vehicul;
EXECUTE stmt_add_tip_vehicul;
DEALLOCATE PREPARE stmt_add_tip_vehicul;

UPDATE vehicule
SET tip_vehicul = 'autovehicul'
WHERE tip_vehicul IS NULL OR tip_vehicul = '';

ALTER TABLE vehicule
    MODIFY COLUMN tip_vehicul ENUM('autovehicul', 'camion', 'cap_tractor', 'semiremorca') NOT NULL DEFAULT 'autovehicul';

SET @has_km_bord := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'vehicule'
      AND COLUMN_NAME = 'km_bord'
);

SET @sql_add_km_bord := IF(
    @has_km_bord = 0,
    "ALTER TABLE vehicule ADD COLUMN km_bord INT UNSIGNED NOT NULL DEFAULT 0 AFTER an_fabricatie",
    'SELECT 1'
);

PREPARE stmt_add_km_bord FROM @sql_add_km_bord;
EXECUTE stmt_add_km_bord;
DEALLOCATE PREPARE stmt_add_km_bord;

UPDATE vehicule
SET km_bord = 0
WHERE km_bord IS NULL;

ALTER TABLE vehicule
    MODIFY COLUMN km_bord INT UNSIGNED NOT NULL DEFAULT 0;
