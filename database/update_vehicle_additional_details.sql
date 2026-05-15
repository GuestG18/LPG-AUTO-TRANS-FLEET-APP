-- Migrare: campuri suplimentare pentru detalii vehicul
-- Data: 2026-04-20

SET NAMES utf8mb4;

SET @has_nr_fabricatie := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'vehicule'
      AND COLUMN_NAME = 'nr_fabricatie'
);

SET @sql_add_nr_fabricatie := IF(
    @has_nr_fabricatie = 0,
    "ALTER TABLE vehicule ADD COLUMN nr_fabricatie VARCHAR(100) NULL AFTER serie_sasiu",
    'SELECT 1'
);

PREPARE stmt_add_nr_fabricatie FROM @sql_add_nr_fabricatie;
EXECUTE stmt_add_nr_fabricatie;
DEALLOCATE PREPARE stmt_add_nr_fabricatie;

SET @has_capacitate_transport := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'vehicule'
      AND COLUMN_NAME = 'capacitate_transport'
);

SET @sql_add_capacitate_transport := IF(
    @has_capacitate_transport = 0,
    "ALTER TABLE vehicule ADD COLUMN capacitate_transport DECIMAL(10,2) NULL AFTER nr_fabricatie",
    'SELECT 1'
);

PREPARE stmt_add_capacitate_transport FROM @sql_add_capacitate_transport;
EXECUTE stmt_add_capacitate_transport;
DEALLOCATE PREPARE stmt_add_capacitate_transport;

SET @has_formula_axelor := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'vehicule'
      AND COLUMN_NAME = 'formula_axelor'
);

SET @sql_add_formula_axelor := IF(
    @has_formula_axelor = 0,
    "ALTER TABLE vehicule ADD COLUMN formula_axelor VARCHAR(20) NULL AFTER capacitate_transport",
    'SELECT 1'
);

PREPARE stmt_add_formula_axelor FROM @sql_add_formula_axelor;
EXECUTE stmt_add_formula_axelor;
DEALLOCATE PREPARE stmt_add_formula_axelor;

SET @has_capacitate_rezervor := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'vehicule'
      AND COLUMN_NAME = 'capacitate_rezervor'
);

SET @sql_add_capacitate_rezervor := IF(
    @has_capacitate_rezervor = 0,
    "ALTER TABLE vehicule ADD COLUMN capacitate_rezervor DECIMAL(10,2) NULL AFTER formula_axelor",
    'SELECT 1'
);

PREPARE stmt_add_capacitate_rezervor FROM @sql_add_capacitate_rezervor;
EXECUTE stmt_add_capacitate_rezervor;
DEALLOCATE PREPARE stmt_add_capacitate_rezervor;

SET @has_mma := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'vehicule'
      AND COLUMN_NAME = 'mma'
);

SET @sql_add_mma := IF(
    @has_mma = 0,
    "ALTER TABLE vehicule ADD COLUMN mma DECIMAL(10,2) NULL AFTER capacitate_rezervor",
    'SELECT 1'
);

PREPARE stmt_add_mma FROM @sql_add_mma;
EXECUTE stmt_add_mma;
DEALLOCATE PREPARE stmt_add_mma;

SET @has_organism_notificat := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'vehicule'
      AND COLUMN_NAME = 'organism_notificat'
);

SET @sql_add_organism_notificat := IF(
    @has_organism_notificat = 0,
    "ALTER TABLE vehicule ADD COLUMN organism_notificat VARCHAR(150) NULL AFTER mma",
    'SELECT 1'
);

PREPARE stmt_add_organism_notificat FROM @sql_add_organism_notificat;
EXECUTE stmt_add_organism_notificat;
DEALLOCATE PREPARE stmt_add_organism_notificat;
