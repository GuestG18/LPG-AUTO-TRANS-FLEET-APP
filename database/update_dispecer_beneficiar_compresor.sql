-- Migrare punctuala: extinde suportul pentru tip transport "compresor" la beneficiari
-- Data: 2026-05-13 (actualizat)

SET NAMES utf8mb4;

SET @has_suporta_compresor := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_beneficiari_transport'
      AND COLUMN_NAME = 'suporta_compresor'
);

SET @sql_add_suporta_compresor := IF(
    @has_suporta_compresor = 0,
    'ALTER TABLE configurare_beneficiari_transport ADD COLUMN suporta_compresor TINYINT(1) NOT NULL DEFAULT 0 AFTER suporta_distributie',
    'SELECT 1'
);

PREPARE stmt_add_suporta_compresor FROM @sql_add_suporta_compresor;
EXECUTE stmt_add_suporta_compresor;
DEALLOCATE PREPARE stmt_add_suporta_compresor;

UPDATE configurare_beneficiari_transport
SET suporta_compresor = COALESCE(suporta_compresor, 0);

SET @has_pret_ora_aspirare := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_beneficiari_transport'
      AND COLUMN_NAME = 'pret_ora_aspirare'
);

SET @sql_add_pret_ora_aspirare := IF(
    @has_pret_ora_aspirare = 0,
    'ALTER TABLE configurare_beneficiari_transport ADD COLUMN pret_ora_aspirare DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER pret_tona',
    'SELECT 1'
);

PREPARE stmt_add_pret_ora_aspirare FROM @sql_add_pret_ora_aspirare;
EXECUTE stmt_add_pret_ora_aspirare;
DEALLOCATE PREPARE stmt_add_pret_ora_aspirare;

SET @has_pret_km_dislocare := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_beneficiari_transport'
      AND COLUMN_NAME = 'pret_km_dislocare'
);

SET @sql_add_pret_km_dislocare := IF(
    @has_pret_km_dislocare = 0,
    'ALTER TABLE configurare_beneficiari_transport ADD COLUMN pret_km_dislocare DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER pret_ora_aspirare',
    'SELECT 1'
);

PREPARE stmt_add_pret_km_dislocare FROM @sql_add_pret_km_dislocare;
EXECUTE stmt_add_pret_km_dislocare;
DEALLOCATE PREPARE stmt_add_pret_km_dislocare;

SET @has_pret_tona_livrata := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_beneficiari_transport'
      AND COLUMN_NAME = 'pret_tona_livrata'
);

SET @sql_add_pret_tona_livrata := IF(
    @has_pret_tona_livrata = 0,
    'ALTER TABLE configurare_beneficiari_transport ADD COLUMN pret_tona_livrata DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER pret_km_dislocare',
    'SELECT 1'
);

PREPARE stmt_add_pret_tona_livrata FROM @sql_add_pret_tona_livrata;
EXECUTE stmt_add_pret_tona_livrata;
DEALLOCATE PREPARE stmt_add_pret_tona_livrata;

SET @has_pret_tona_aspirata_lichida := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_beneficiari_transport'
      AND COLUMN_NAME = 'pret_tona_aspirata_lichida'
);

SET @sql_add_pret_tona_aspirata_lichida := IF(
    @has_pret_tona_aspirata_lichida = 0,
    'ALTER TABLE configurare_beneficiari_transport ADD COLUMN pret_tona_aspirata_lichida DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER pret_tona_livrata',
    'SELECT 1'
);

PREPARE stmt_add_pret_tona_aspirata_lichida FROM @sql_add_pret_tona_aspirata_lichida;
EXECUTE stmt_add_pret_tona_aspirata_lichida;
DEALLOCATE PREPARE stmt_add_pret_tona_aspirata_lichida;

SET @has_pret_tona_aspirata_gazoasa := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_beneficiari_transport'
      AND COLUMN_NAME = 'pret_tona_aspirata_gazoasa'
);

SET @sql_add_pret_tona_aspirata_gazoasa := IF(
    @has_pret_tona_aspirata_gazoasa = 0,
    'ALTER TABLE configurare_beneficiari_transport ADD COLUMN pret_tona_aspirata_gazoasa DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER pret_tona_aspirata_lichida',
    'SELECT 1'
);

PREPARE stmt_add_pret_tona_aspirata_gazoasa FROM @sql_add_pret_tona_aspirata_gazoasa;
EXECUTE stmt_add_pret_tona_aspirata_gazoasa;
DEALLOCATE PREPARE stmt_add_pret_tona_aspirata_gazoasa;
