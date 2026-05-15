-- Migrare: split tarifare/cantitati compresor pe lichid + gazos
-- Data: 2026-05-13

SET NAMES utf8mb4;

SET @has_benef_pret_tona_aspirata_lichida := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_beneficiari_transport'
      AND COLUMN_NAME = 'pret_tona_aspirata_lichida'
);
SET @sql_add_benef_pret_tona_aspirata_lichida := IF(
    @has_benef_pret_tona_aspirata_lichida = 0,
    'ALTER TABLE configurare_beneficiari_transport ADD COLUMN pret_tona_aspirata_lichida DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER pret_tona_livrata',
    'SELECT 1'
);
PREPARE stmt_add_benef_pret_tona_aspirata_lichida FROM @sql_add_benef_pret_tona_aspirata_lichida;
EXECUTE stmt_add_benef_pret_tona_aspirata_lichida;
DEALLOCATE PREPARE stmt_add_benef_pret_tona_aspirata_lichida;

SET @has_benef_pret_tona_aspirata_gazoasa := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_beneficiari_transport'
      AND COLUMN_NAME = 'pret_tona_aspirata_gazoasa'
);
SET @sql_add_benef_pret_tona_aspirata_gazoasa := IF(
    @has_benef_pret_tona_aspirata_gazoasa = 0,
    'ALTER TABLE configurare_beneficiari_transport ADD COLUMN pret_tona_aspirata_gazoasa DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER pret_tona_aspirata_lichida',
    'SELECT 1'
);
PREPARE stmt_add_benef_pret_tona_aspirata_gazoasa FROM @sql_add_benef_pret_tona_aspirata_gazoasa;
EXECUTE stmt_add_benef_pret_tona_aspirata_gazoasa;
DEALLOCATE PREPARE stmt_add_benef_pret_tona_aspirata_gazoasa;

SET @has_cursa_tona_aspirata_lichida := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND COLUMN_NAME = 'tona_aspirata_lichida'
);
SET @sql_add_cursa_tona_aspirata_lichida := IF(
    @has_cursa_tona_aspirata_lichida = 0,
    'ALTER TABLE curse_dispecer ADD COLUMN tona_aspirata_lichida DECIMAL(12,2) NULL AFTER tona_livrata',
    'SELECT 1'
);
PREPARE stmt_add_cursa_tona_aspirata_lichida FROM @sql_add_cursa_tona_aspirata_lichida;
EXECUTE stmt_add_cursa_tona_aspirata_lichida;
DEALLOCATE PREPARE stmt_add_cursa_tona_aspirata_lichida;

SET @has_cursa_tona_aspirata_gazoasa := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND COLUMN_NAME = 'tona_aspirata_gazoasa'
);
SET @sql_add_cursa_tona_aspirata_gazoasa := IF(
    @has_cursa_tona_aspirata_gazoasa = 0,
    'ALTER TABLE curse_dispecer ADD COLUMN tona_aspirata_gazoasa DECIMAL(12,2) NULL AFTER tona_aspirata_lichida',
    'SELECT 1'
);
PREPARE stmt_add_cursa_tona_aspirata_gazoasa FROM @sql_add_cursa_tona_aspirata_gazoasa;
EXECUTE stmt_add_cursa_tona_aspirata_gazoasa;
DEALLOCATE PREPARE stmt_add_cursa_tona_aspirata_gazoasa;
