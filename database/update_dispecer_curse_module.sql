-- Migrare: modul Dispecer curse
-- Data: 2026-04-23

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS configurare_locuri_incarcare (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    beneficiar_id INT UNSIGNED NOT NULL,
    nume VARCHAR(120) NOT NULL,
    tarif DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    activ TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_config_locuri_beneficiar_nume (beneficiar_id, nume),
    INDEX idx_config_locuri_beneficiar (beneficiar_id),
    INDEX idx_config_locuri_activ (activ)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS configurare_zone_distributie (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    beneficiar_id INT UNSIGNED NOT NULL,
    nume VARCHAR(120) NOT NULL,
    tarif_distributie DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    cost_extra_km DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    activ TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_config_zone_beneficiar_nume (beneficiar_id, nume),
    INDEX idx_config_zone_beneficiar (beneficiar_id),
    INDEX idx_config_zone_activ (activ)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS configurare_locuri_incarcare_vehicule (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    beneficiar_id INT UNSIGNED NULL,
    vehicle_id INT UNSIGNED NOT NULL,
    loc_incarcare_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_config_locuri_beneficiar_vehicle (beneficiar_id, vehicle_id),
    INDEX idx_config_locuri_vehicle_beneficiar (beneficiar_id),
    INDEX idx_config_locuri_vehicle_vehicle (vehicle_id),
    INDEX idx_config_locuri_vehicle_loc (loc_incarcare_id),
    CONSTRAINT fk_config_locuri_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicule(id) ON DELETE CASCADE,
    CONSTRAINT fk_config_locuri_loc FOREIGN KEY (loc_incarcare_id) REFERENCES configurare_locuri_incarcare(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS configurare_zone_distributie_vehicule (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    beneficiar_id INT UNSIGNED NULL,
    vehicle_id INT UNSIGNED NOT NULL,
    zona_distributie_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_config_zone_beneficiar_vehicle (beneficiar_id, vehicle_id),
    INDEX idx_config_zone_vehicle_beneficiar (beneficiar_id),
    INDEX idx_config_zone_vehicle_vehicle (vehicle_id),
    INDEX idx_config_zone_vehicle_zona (zona_distributie_id),
    CONSTRAINT fk_config_zone_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicule(id) ON DELETE CASCADE,
    CONSTRAINT fk_config_zone_zona FOREIGN KEY (zona_distributie_id) REFERENCES configurare_zone_distributie(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS configurare_beneficiari_transport (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nume VARCHAR(150) NOT NULL,
    tip_marfa VARCHAR(50) NULL,
    pret_tarifare DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    suporta_primar TINYINT(1) NOT NULL DEFAULT 1,
    suporta_distributie TINYINT(1) NOT NULL DEFAULT 1,
    suporta_primar_distributie TINYINT(1) NOT NULL DEFAULT 0,
    suporta_compresor TINYINT(1) NOT NULL DEFAULT 0,
    pret_km DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    pret_tona DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    pret_distributie_km DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    pret_distributie_tona DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    pret_ora_aspirare DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    pret_km_dislocare DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    pret_tona_livrata DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    pret_tona_aspirata_lichida DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    pret_tona_aspirata_gazoasa DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    activ TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_config_beneficiari_nume (nume),
    INDEX idx_config_beneficiari_activ (activ)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS configurare_rute_distributie (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    beneficiar_id INT UNSIGNED NOT NULL,
    loc_incarcare_id INT UNSIGNED NOT NULL,
    zona_distributie_id INT UNSIGNED NOT NULL,
    tarif_mod ENUM('tona_km', 'tona', 'km') NOT NULL DEFAULT 'tona_km',
    tarif_tona DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    cost_extra_km DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    km_tarifare INT UNSIGNED NOT NULL DEFAULT 0,
    cost_cursa DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    aplica_cost_cursa TINYINT(1) NOT NULL DEFAULT 0,
    vehicle_ids TEXT NULL,
    activ TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_config_rute_beneficiar (beneficiar_id),
    INDEX idx_config_rute_loc (loc_incarcare_id),
    INDEX idx_config_rute_zona (zona_distributie_id),
    INDEX idx_config_rute_activ (activ),
    CONSTRAINT fk_config_rute_beneficiar FOREIGN KEY (beneficiar_id) REFERENCES configurare_beneficiari_transport(id) ON DELETE CASCADE,
    CONSTRAINT fk_config_rute_loc FOREIGN KEY (loc_incarcare_id) REFERENCES configurare_locuri_incarcare(id) ON DELETE CASCADE,
    CONSTRAINT fk_config_rute_zona FOREIGN KEY (zona_distributie_id) REFERENCES configurare_zone_distributie(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS configurare_rute_primar (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    beneficiar_id INT UNSIGNED NOT NULL,
    loc_incarcare_id INT UNSIGNED NOT NULL,
    zona_distributie_id INT UNSIGNED NOT NULL,
    km_tarifare INT UNSIGNED NOT NULL DEFAULT 0,
    cost_cursa DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    aplica_cost_cursa TINYINT(1) NOT NULL DEFAULT 0,
    vehicle_ids TEXT NULL,
    km_agreati_manual TINYINT(1) NOT NULL DEFAULT 0,
    activ TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_config_rute_primar_beneficiar_loc_zona (beneficiar_id, loc_incarcare_id, zona_distributie_id),
    INDEX idx_config_rute_primar_beneficiar (beneficiar_id),
    INDEX idx_config_rute_primar_loc (loc_incarcare_id),
    INDEX idx_config_rute_primar_zona (zona_distributie_id),
    INDEX idx_config_rute_primar_activ (activ),
    CONSTRAINT fk_config_rute_primar_beneficiar FOREIGN KEY (beneficiar_id) REFERENCES configurare_beneficiari_transport(id) ON DELETE CASCADE,
    CONSTRAINT fk_config_rute_primar_loc FOREIGN KEY (loc_incarcare_id) REFERENCES configurare_locuri_incarcare(id) ON DELETE CASCADE,
    CONSTRAINT fk_config_rute_primar_zona FOREIGN KEY (zona_distributie_id) REFERENCES configurare_zone_distributie(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_primary_route_vehicle_ids := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_rute_primar'
      AND COLUMN_NAME = 'vehicle_ids'
);
SET @sql_add_primary_route_vehicle_ids := IF(
    @has_primary_route_vehicle_ids = 0,
    'ALTER TABLE configurare_rute_primar ADD COLUMN vehicle_ids TEXT NULL AFTER km_tarifare',
    'SELECT 1'
);
PREPARE stmt_add_primary_route_vehicle_ids FROM @sql_add_primary_route_vehicle_ids;
EXECUTE stmt_add_primary_route_vehicle_ids;
DEALLOCATE PREPARE stmt_add_primary_route_vehicle_ids;

SET @has_primary_route_manual_km := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_rute_primar'
      AND COLUMN_NAME = 'km_agreati_manual'
);
SET @sql_add_primary_route_manual_km := IF(
    @has_primary_route_manual_km = 0,
    'ALTER TABLE configurare_rute_primar ADD COLUMN km_agreati_manual TINYINT(1) NOT NULL DEFAULT 0 AFTER vehicle_ids',
    'SELECT 1'
);
PREPARE stmt_add_primary_route_manual_km FROM @sql_add_primary_route_manual_km;
EXECUTE stmt_add_primary_route_manual_km;
DEALLOCATE PREPARE stmt_add_primary_route_manual_km;

SET @has_primary_route_cost_cursa := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_rute_primar'
      AND COLUMN_NAME = 'cost_cursa'
);
SET @sql_add_primary_route_cost_cursa := IF(
    @has_primary_route_cost_cursa = 0,
    'ALTER TABLE configurare_rute_primar ADD COLUMN cost_cursa DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER km_tarifare',
    'SELECT 1'
);
PREPARE stmt_add_primary_route_cost_cursa FROM @sql_add_primary_route_cost_cursa;
EXECUTE stmt_add_primary_route_cost_cursa;
DEALLOCATE PREPARE stmt_add_primary_route_cost_cursa;

SET @has_primary_route_apply_cost_cursa := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_rute_primar'
      AND COLUMN_NAME = 'aplica_cost_cursa'
);
SET @sql_add_primary_route_apply_cost_cursa := IF(
    @has_primary_route_apply_cost_cursa = 0,
    'ALTER TABLE configurare_rute_primar ADD COLUMN aplica_cost_cursa TINYINT(1) NOT NULL DEFAULT 0 AFTER cost_cursa',
    'SELECT 1'
);
PREPARE stmt_add_primary_route_apply_cost_cursa FROM @sql_add_primary_route_apply_cost_cursa;
EXECUTE stmt_add_primary_route_apply_cost_cursa;
DEALLOCATE PREPARE stmt_add_primary_route_apply_cost_cursa;

SET @has_legacy_distribution_route_unique := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_rute_distributie'
      AND INDEX_NAME = 'uk_config_rute_beneficiar_loc_zona'
);
SET @sql_drop_legacy_distribution_route_unique := IF(
    @has_legacy_distribution_route_unique > 0,
    'ALTER TABLE configurare_rute_distributie DROP INDEX uk_config_rute_beneficiar_loc_zona',
    'SELECT 1'
);
PREPARE stmt_drop_legacy_distribution_route_unique FROM @sql_drop_legacy_distribution_route_unique;
EXECUTE stmt_drop_legacy_distribution_route_unique;
DEALLOCATE PREPARE stmt_drop_legacy_distribution_route_unique;

CREATE TABLE IF NOT EXISTS curse_dispecer (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT UNSIGNED NOT NULL,
    tip_transport ENUM('primar', 'primar_tona', 'distributie', 'primar_distributie', 'compresor') NOT NULL,
    data_cursa DATE NOT NULL,
    data_inceput DATE NOT NULL,
    data_sfarsit DATE NOT NULL,
    loc_incarcare_id INT UNSIGNED NULL,
    loc_plecare VARCHAR(255) NULL,
    loc_aspirare VARCHAR(255) NULL,
    loc_livrare VARCHAR(255) NULL,
    loc_livrare_cursa VARCHAR(255) NULL,
    beneficiar_id INT UNSIGNED NULL,
    tip_marfa VARCHAR(255) NULL,
    capacitate_transport DECIMAL(10,2) NULL,
    cantitate_incarcata DECIMAL(12,2) NULL,
    cantitate_prelevata DECIMAL(12,2) NULL,
    nr_clienti INT UNSIGNED NULL,
    km_cursa INT UNSIGNED NULL,
    ore_functionare DECIMAL(10,2) NULL,
    km_totali INT UNSIGNED NULL,
    ore_aspirare DECIMAL(12,2) NULL,
    km_dislocare DECIMAL(12,2) NULL,
    tona_livrata DECIMAL(12,2) NULL,
    tona_aspirata_lichida DECIMAL(12,2) NULL,
    tona_aspirata_gazoasa DECIMAL(12,2) NULL,
    zona_distributie_id INT UNSIGNED NULL,
    status_facturare ENUM('in_curs_facturare', 'facturat', 'nefacturat') NOT NULL DEFAULT 'in_curs_facturare',
    pret_tarifare DECIMAL(12,2) NOT NULL,
    total_facturare DECIMAL(12,2) NOT NULL,
    cost_km_primar DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    cost_km_distributie DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    cost_km_mixt DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    cost_km_compresor DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    observatii TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_curse_vehicle (vehicle_id),
    INDEX idx_curse_tip_transport (tip_transport),
    INDEX idx_curse_data (data_cursa),
    INDEX idx_curse_data_inceput (data_inceput),
    INDEX idx_curse_data_sfarsit (data_sfarsit),
    INDEX idx_curse_loc (loc_incarcare_id),
    INDEX idx_curse_beneficiar (beneficiar_id),
    INDEX idx_curse_zona (zona_distributie_id),
    CONSTRAINT fk_curse_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicule(id) ON DELETE RESTRICT,
    CONSTRAINT fk_curse_loc FOREIGN KEY (loc_incarcare_id) REFERENCES configurare_locuri_incarcare(id) ON DELETE RESTRICT,
    CONSTRAINT fk_curse_beneficiar FOREIGN KEY (beneficiar_id) REFERENCES configurare_beneficiari_transport(id) ON DELETE RESTRICT,
    CONSTRAINT fk_curse_zona FOREIGN KEY (zona_distributie_id) REFERENCES configurare_zone_distributie(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sql_update_tip_transport_enum := (
    SELECT IF(
        EXISTS (
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'curse_dispecer'
              AND COLUMN_NAME = 'tip_transport'
        ),
        'ALTER TABLE curse_dispecer MODIFY COLUMN tip_transport ENUM(''primar'', ''primar_tona'', ''distributie'', ''primar_distributie'', ''compresor'') NOT NULL',
        'SELECT 1'
    )
);
PREPARE stmt_update_tip_transport_enum FROM @sql_update_tip_transport_enum;
EXECUTE stmt_update_tip_transport_enum;
DEALLOCATE PREPARE stmt_update_tip_transport_enum;

SET @has_curse_data_inceput := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND COLUMN_NAME = 'data_inceput'
);
SET @sql_add_curse_data_inceput := IF(
    @has_curse_data_inceput = 0,
    'ALTER TABLE curse_dispecer ADD COLUMN data_inceput DATE NULL AFTER data_cursa',
    'SELECT 1'
);
PREPARE stmt_add_curse_data_inceput FROM @sql_add_curse_data_inceput;
EXECUTE stmt_add_curse_data_inceput;
DEALLOCATE PREPARE stmt_add_curse_data_inceput;

SET @has_curse_data_sfarsit := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND COLUMN_NAME = 'data_sfarsit'
);
SET @sql_add_curse_data_sfarsit := IF(
    @has_curse_data_sfarsit = 0,
    'ALTER TABLE curse_dispecer ADD COLUMN data_sfarsit DATE NULL AFTER data_inceput',
    'SELECT 1'
);
PREPARE stmt_add_curse_data_sfarsit FROM @sql_add_curse_data_sfarsit;
EXECUTE stmt_add_curse_data_sfarsit;
DEALLOCATE PREPARE stmt_add_curse_data_sfarsit;

UPDATE curse_dispecer
SET data_inceput = data_cursa
WHERE data_inceput IS NULL;

UPDATE curse_dispecer
SET data_sfarsit = COALESCE(data_inceput, data_cursa)
WHERE data_sfarsit IS NULL;

ALTER TABLE curse_dispecer
MODIFY COLUMN data_inceput DATE NOT NULL;

ALTER TABLE curse_dispecer
MODIFY COLUMN data_sfarsit DATE NOT NULL;

SET @has_idx_curse_data_inceput := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND INDEX_NAME = 'idx_curse_data_inceput'
);
SET @sql_add_idx_curse_data_inceput := IF(
    @has_idx_curse_data_inceput = 0,
    'ALTER TABLE curse_dispecer ADD INDEX idx_curse_data_inceput (data_inceput)',
    'SELECT 1'
);
PREPARE stmt_add_idx_curse_data_inceput FROM @sql_add_idx_curse_data_inceput;
EXECUTE stmt_add_idx_curse_data_inceput;
DEALLOCATE PREPARE stmt_add_idx_curse_data_inceput;

SET @has_idx_curse_data_sfarsit := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND INDEX_NAME = 'idx_curse_data_sfarsit'
);
SET @sql_add_idx_curse_data_sfarsit := IF(
    @has_idx_curse_data_sfarsit = 0,
    'ALTER TABLE curse_dispecer ADD INDEX idx_curse_data_sfarsit (data_sfarsit)',
    'SELECT 1'
);
PREPARE stmt_add_idx_curse_data_sfarsit FROM @sql_add_idx_curse_data_sfarsit;
EXECUTE stmt_add_idx_curse_data_sfarsit;
DEALLOCATE PREPARE stmt_add_idx_curse_data_sfarsit;

SET @has_curse_tip_marfa := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND COLUMN_NAME = 'tip_marfa'
);
SET @sql_add_curse_tip_marfa := IF(
    @has_curse_tip_marfa = 0,
    'ALTER TABLE curse_dispecer ADD COLUMN tip_marfa VARCHAR(255) NULL AFTER data_cursa',
    'SELECT 1'
);
PREPARE stmt_add_curse_tip_marfa FROM @sql_add_curse_tip_marfa;
EXECUTE stmt_add_curse_tip_marfa;
DEALLOCATE PREPARE stmt_add_curse_tip_marfa;

SET @sql_resize_curse_tip_marfa := IF(
    @has_curse_tip_marfa > 0,
    'ALTER TABLE curse_dispecer MODIFY COLUMN tip_marfa VARCHAR(255) NULL',
    'SELECT 1'
);
PREPARE stmt_resize_curse_tip_marfa FROM @sql_resize_curse_tip_marfa;
EXECUTE stmt_resize_curse_tip_marfa;
DEALLOCATE PREPARE stmt_resize_curse_tip_marfa;

SET @has_curse_capacitate_transport := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND COLUMN_NAME = 'capacitate_transport'
);
SET @sql_add_curse_capacitate_transport := IF(
    @has_curse_capacitate_transport = 0,
    'ALTER TABLE curse_dispecer ADD COLUMN capacitate_transport DECIMAL(10,2) NULL AFTER tip_marfa',
    'SELECT 1'
);
PREPARE stmt_add_curse_capacitate_transport FROM @sql_add_curse_capacitate_transport;
EXECUTE stmt_add_curse_capacitate_transport;
DEALLOCATE PREPARE stmt_add_curse_capacitate_transport;

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

SET @has_status_facturare := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND COLUMN_NAME = 'status_facturare'
);
SET @sql_add_status_facturare := IF(
    @has_status_facturare = 0,
    'ALTER TABLE curse_dispecer ADD COLUMN status_facturare ENUM(''in_curs_facturare'', ''facturat'', ''nefacturat'') NOT NULL DEFAULT ''in_curs_facturare'' AFTER zona_distributie_id',
    'SELECT 1'
);
PREPARE stmt_add_status_facturare FROM @sql_add_status_facturare;
EXECUTE stmt_add_status_facturare;
DEALLOCATE PREPARE stmt_add_status_facturare;

SET @sql_resize_status_facturare := IF(
    @has_status_facturare > 0,
    'ALTER TABLE curse_dispecer MODIFY COLUMN status_facturare ENUM(''in_curs_facturare'', ''facturat'', ''nefacturat'') NOT NULL DEFAULT ''in_curs_facturare''',
    'SELECT 1'
);
PREPARE stmt_resize_status_facturare FROM @sql_resize_status_facturare;
EXECUTE stmt_resize_status_facturare;
DEALLOCATE PREPARE stmt_resize_status_facturare;

SET @has_cost_km_primar := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND COLUMN_NAME = 'cost_km_primar'
);
SET @sql_add_cost_km_primar := IF(
    @has_cost_km_primar = 0,
    'ALTER TABLE curse_dispecer ADD COLUMN cost_km_primar DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER total_facturare',
    'SELECT 1'
);
PREPARE stmt_add_cost_km_primar FROM @sql_add_cost_km_primar;
EXECUTE stmt_add_cost_km_primar;
DEALLOCATE PREPARE stmt_add_cost_km_primar;

SET @has_cost_km_distributie := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND COLUMN_NAME = 'cost_km_distributie'
);
SET @sql_add_cost_km_distributie := IF(
    @has_cost_km_distributie = 0,
    'ALTER TABLE curse_dispecer ADD COLUMN cost_km_distributie DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER cost_km_primar',
    'SELECT 1'
);
PREPARE stmt_add_cost_km_distributie FROM @sql_add_cost_km_distributie;
EXECUTE stmt_add_cost_km_distributie;
DEALLOCATE PREPARE stmt_add_cost_km_distributie;

SET @has_cost_km_mixt := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND COLUMN_NAME = 'cost_km_mixt'
);
SET @sql_add_cost_km_mixt := IF(
    @has_cost_km_mixt = 0,
    'ALTER TABLE curse_dispecer ADD COLUMN cost_km_mixt DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER cost_km_distributie',
    'SELECT 1'
);
PREPARE stmt_add_cost_km_mixt FROM @sql_add_cost_km_mixt;
EXECUTE stmt_add_cost_km_mixt;
DEALLOCATE PREPARE stmt_add_cost_km_mixt;

SET @has_cost_km_compresor := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND COLUMN_NAME = 'cost_km_compresor'
);
SET @sql_add_cost_km_compresor := IF(
    @has_cost_km_compresor = 0,
    'ALTER TABLE curse_dispecer ADD COLUMN cost_km_compresor DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER cost_km_mixt',
    'SELECT 1'
);
PREPARE stmt_add_cost_km_compresor FROM @sql_add_cost_km_compresor;
EXECUTE stmt_add_cost_km_compresor;
DEALLOCATE PREPARE stmt_add_cost_km_compresor;

UPDATE curse_dispecer
SET cost_km_compresor = ROUND(total_facturare / km_dislocare, 2)
WHERE tip_transport = 'compresor'
  AND COALESCE(km_dislocare, 0) > 0
  AND COALESCE(cost_km_compresor, 0) <= 0;

UPDATE curse_dispecer
SET status_facturare = 'in_curs_facturare'
WHERE status_facturare IS NULL
   OR status_facturare = '';

SET @has_ore_functionare := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND COLUMN_NAME = 'ore_functionare'
);
SET @sql_add_ore_functionare := IF(
    @has_ore_functionare = 0,
    'ALTER TABLE curse_dispecer ADD COLUMN ore_functionare DECIMAL(10,2) NULL AFTER km_cursa',
    'SELECT 1'
);
PREPARE stmt_add_ore_functionare FROM @sql_add_ore_functionare;
EXECUTE stmt_add_ore_functionare;
DEALLOCATE PREPARE stmt_add_ore_functionare;

SET @has_ore_aspirare := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND COLUMN_NAME = 'ore_aspirare'
);
SET @sql_add_ore_aspirare := IF(
    @has_ore_aspirare = 0,
    'ALTER TABLE curse_dispecer ADD COLUMN ore_aspirare DECIMAL(12,2) NULL AFTER ore_functionare',
    'SELECT 1'
);
PREPARE stmt_add_ore_aspirare FROM @sql_add_ore_aspirare;
EXECUTE stmt_add_ore_aspirare;
DEALLOCATE PREPARE stmt_add_ore_aspirare;

SET @has_km_dislocare := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND COLUMN_NAME = 'km_dislocare'
);
SET @sql_add_km_dislocare := IF(
    @has_km_dislocare = 0,
    'ALTER TABLE curse_dispecer ADD COLUMN km_dislocare DECIMAL(12,2) NULL AFTER ore_aspirare',
    'SELECT 1'
);
PREPARE stmt_add_km_dislocare FROM @sql_add_km_dislocare;
EXECUTE stmt_add_km_dislocare;
DEALLOCATE PREPARE stmt_add_km_dislocare;

SET @has_tona_livrata := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND COLUMN_NAME = 'tona_livrata'
);
SET @sql_add_tona_livrata := IF(
    @has_tona_livrata = 0,
    'ALTER TABLE curse_dispecer ADD COLUMN tona_livrata DECIMAL(12,2) NULL AFTER km_dislocare',
    'SELECT 1'
);
PREPARE stmt_add_tona_livrata FROM @sql_add_tona_livrata;
EXECUTE stmt_add_tona_livrata;
DEALLOCATE PREPARE stmt_add_tona_livrata;

SET @has_tona_aspirata_lichida := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND COLUMN_NAME = 'tona_aspirata_lichida'
);
SET @sql_add_tona_aspirata_lichida := IF(
    @has_tona_aspirata_lichida = 0,
    'ALTER TABLE curse_dispecer ADD COLUMN tona_aspirata_lichida DECIMAL(12,2) NULL AFTER tona_livrata',
    'SELECT 1'
);
PREPARE stmt_add_tona_aspirata_lichida FROM @sql_add_tona_aspirata_lichida;
EXECUTE stmt_add_tona_aspirata_lichida;
DEALLOCATE PREPARE stmt_add_tona_aspirata_lichida;

SET @has_tona_aspirata_gazoasa := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND COLUMN_NAME = 'tona_aspirata_gazoasa'
);
SET @sql_add_tona_aspirata_gazoasa := IF(
    @has_tona_aspirata_gazoasa = 0,
    'ALTER TABLE curse_dispecer ADD COLUMN tona_aspirata_gazoasa DECIMAL(12,2) NULL AFTER tona_aspirata_lichida',
    'SELECT 1'
);
PREPARE stmt_add_tona_aspirata_gazoasa FROM @sql_add_tona_aspirata_gazoasa;
EXECUTE stmt_add_tona_aspirata_gazoasa;
DEALLOCATE PREPARE stmt_add_tona_aspirata_gazoasa;

SET @has_cantitate_prelevata := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND COLUMN_NAME = 'cantitate_prelevata'
);
SET @sql_add_cantitate_prelevata := IF(
    @has_cantitate_prelevata = 0,
    'ALTER TABLE curse_dispecer ADD COLUMN cantitate_prelevata DECIMAL(12,2) NULL AFTER cantitate_incarcata',
    'SELECT 1'
);
PREPARE stmt_add_cantitate_prelevata FROM @sql_add_cantitate_prelevata;
EXECUTE stmt_add_cantitate_prelevata;
DEALLOCATE PREPARE stmt_add_cantitate_prelevata;

CREATE TABLE IF NOT EXISTS curse_cheltuieli (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cursa_id INT UNSIGNED NOT NULL,
    tip_cheltuiala ENUM('motorina', 'taxe_drum', 'diurna', 'service', 'alte') NOT NULL,
    refacturare_tip_cheltuiala ENUM('motorina', 'taxe_drum', 'diurna', 'service', 'alte') NULL,
    refacturare_detalii TEXT NULL,
    refacturare_suma DECIMAL(12,2) NULL,
    refacturare_data DATE NULL,
    refacturare_observatii TEXT NULL,
    refacturare_document_path VARCHAR(255) NULL,
    refacturare_document_original_name VARCHAR(255) NULL,
    refacturare_document_mime_type VARCHAR(150) NULL,
    refacturare_document_file_size INT UNSIGNED NULL,
    suma DECIMAL(12,2) NOT NULL,
    data_cheltuiala DATE NOT NULL,
    observatii TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_curse_cheltuieli_cursa (cursa_id),
    INDEX idx_curse_cheltuieli_data (data_cheltuiala),
    CONSTRAINT fk_curse_cheltuieli_cursa FOREIGN KEY (cursa_id) REFERENCES curse_dispecer(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_expense_refacturare := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_cheltuieli'
      AND COLUMN_NAME = 'refacturare_tip_cheltuiala'
);
SET @sql_add_expense_refacturare := IF(
    @has_expense_refacturare = 0,
    'ALTER TABLE curse_cheltuieli ADD COLUMN refacturare_tip_cheltuiala ENUM(''motorina'', ''taxe_drum'', ''diurna'', ''service'', ''alte'') NULL AFTER tip_cheltuiala',
    'SELECT 1'
);
PREPARE stmt_add_expense_refacturare FROM @sql_add_expense_refacturare;
EXECUTE stmt_add_expense_refacturare;
DEALLOCATE PREPARE stmt_add_expense_refacturare;

SET @has_expense_refacturare_detalii := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_cheltuieli'
      AND COLUMN_NAME = 'refacturare_detalii'
);
SET @sql_add_expense_refacturare_detalii := IF(
    @has_expense_refacturare_detalii = 0,
    'ALTER TABLE curse_cheltuieli ADD COLUMN refacturare_detalii TEXT NULL AFTER refacturare_tip_cheltuiala',
    'SELECT 1'
);
PREPARE stmt_add_expense_refacturare_detalii FROM @sql_add_expense_refacturare_detalii;
EXECUTE stmt_add_expense_refacturare_detalii;
DEALLOCATE PREPARE stmt_add_expense_refacturare_detalii;

SET @has_expense_refacturare_suma := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'curse_cheltuieli' AND COLUMN_NAME = 'refacturare_suma'
);
SET @sql_add_expense_refacturare_suma := IF(
    @has_expense_refacturare_suma = 0,
    'ALTER TABLE curse_cheltuieli ADD COLUMN refacturare_suma DECIMAL(12,2) NULL AFTER refacturare_detalii',
    'SELECT 1'
);
PREPARE stmt_add_expense_refacturare_suma FROM @sql_add_expense_refacturare_suma;
EXECUTE stmt_add_expense_refacturare_suma;
DEALLOCATE PREPARE stmt_add_expense_refacturare_suma;

SET @has_expense_refacturare_data := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'curse_cheltuieli' AND COLUMN_NAME = 'refacturare_data'
);
SET @sql_add_expense_refacturare_data := IF(
    @has_expense_refacturare_data = 0,
    'ALTER TABLE curse_cheltuieli ADD COLUMN refacturare_data DATE NULL AFTER refacturare_suma',
    'SELECT 1'
);
PREPARE stmt_add_expense_refacturare_data FROM @sql_add_expense_refacturare_data;
EXECUTE stmt_add_expense_refacturare_data;
DEALLOCATE PREPARE stmt_add_expense_refacturare_data;

SET @has_expense_refacturare_observatii := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'curse_cheltuieli' AND COLUMN_NAME = 'refacturare_observatii'
);
SET @sql_add_expense_refacturare_observatii := IF(
    @has_expense_refacturare_observatii = 0,
    'ALTER TABLE curse_cheltuieli ADD COLUMN refacturare_observatii TEXT NULL AFTER refacturare_data',
    'SELECT 1'
);
PREPARE stmt_add_expense_refacturare_observatii FROM @sql_add_expense_refacturare_observatii;
EXECUTE stmt_add_expense_refacturare_observatii;
DEALLOCATE PREPARE stmt_add_expense_refacturare_observatii;

SET @has_expense_refacturare_document_path := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'curse_cheltuieli' AND COLUMN_NAME = 'refacturare_document_path'
);
SET @sql_add_expense_refacturare_document_path := IF(
    @has_expense_refacturare_document_path = 0,
    'ALTER TABLE curse_cheltuieli ADD COLUMN refacturare_document_path VARCHAR(255) NULL AFTER refacturare_observatii',
    'SELECT 1'
);
PREPARE stmt_add_expense_refacturare_document_path FROM @sql_add_expense_refacturare_document_path;
EXECUTE stmt_add_expense_refacturare_document_path;
DEALLOCATE PREPARE stmt_add_expense_refacturare_document_path;

SET @has_expense_refacturare_document_original_name := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'curse_cheltuieli' AND COLUMN_NAME = 'refacturare_document_original_name'
);
SET @sql_add_expense_refacturare_document_original_name := IF(
    @has_expense_refacturare_document_original_name = 0,
    'ALTER TABLE curse_cheltuieli ADD COLUMN refacturare_document_original_name VARCHAR(255) NULL AFTER refacturare_document_path',
    'SELECT 1'
);
PREPARE stmt_add_expense_refacturare_document_original_name FROM @sql_add_expense_refacturare_document_original_name;
EXECUTE stmt_add_expense_refacturare_document_original_name;
DEALLOCATE PREPARE stmt_add_expense_refacturare_document_original_name;

SET @has_expense_refacturare_document_mime_type := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'curse_cheltuieli' AND COLUMN_NAME = 'refacturare_document_mime_type'
);
SET @sql_add_expense_refacturare_document_mime_type := IF(
    @has_expense_refacturare_document_mime_type = 0,
    'ALTER TABLE curse_cheltuieli ADD COLUMN refacturare_document_mime_type VARCHAR(150) NULL AFTER refacturare_document_original_name',
    'SELECT 1'
);
PREPARE stmt_add_expense_refacturare_document_mime_type FROM @sql_add_expense_refacturare_document_mime_type;
EXECUTE stmt_add_expense_refacturare_document_mime_type;
DEALLOCATE PREPARE stmt_add_expense_refacturare_document_mime_type;

SET @has_expense_refacturare_document_file_size := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'curse_cheltuieli' AND COLUMN_NAME = 'refacturare_document_file_size'
);
SET @sql_add_expense_refacturare_document_file_size := IF(
    @has_expense_refacturare_document_file_size = 0,
    'ALTER TABLE curse_cheltuieli ADD COLUMN refacturare_document_file_size INT UNSIGNED NULL AFTER refacturare_document_mime_type',
    'SELECT 1'
);
PREPARE stmt_add_expense_refacturare_document_file_size FROM @sql_add_expense_refacturare_document_file_size;
EXECUTE stmt_add_expense_refacturare_document_file_size;
DEALLOCATE PREPARE stmt_add_expense_refacturare_document_file_size;

CREATE TABLE IF NOT EXISTS curse_cheltuieli_documente (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cheltuiala_id INT UNSIGNED NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(150) NULL,
    file_size INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_curse_doc_cheltuiala (cheltuiala_id),
    CONSTRAINT fk_curse_doc_cheltuiala FOREIGN KEY (cheltuiala_id) REFERENCES curse_cheltuieli(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Compatibilitate: adauga noile coloane pentru beneficiari daca lipsesc.
SET @has_benef_pret_tarifare := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_beneficiari_transport'
      AND COLUMN_NAME = 'pret_tarifare'
);
SET @sql_add_benef_pret_tarifare := IF(
    @has_benef_pret_tarifare = 0,
    'ALTER TABLE configurare_beneficiari_transport ADD COLUMN pret_tarifare DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER nume',
    'SELECT 1'
);
PREPARE stmt_add_benef_pret_tarifare FROM @sql_add_benef_pret_tarifare;
EXECUTE stmt_add_benef_pret_tarifare;
DEALLOCATE PREPARE stmt_add_benef_pret_tarifare;

SET @has_benef_tip_marfa := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_beneficiari_transport'
      AND COLUMN_NAME = 'tip_marfa'
);
SET @sql_add_benef_tip_marfa := IF(
    @has_benef_tip_marfa = 0,
    'ALTER TABLE configurare_beneficiari_transport ADD COLUMN tip_marfa VARCHAR(50) NULL AFTER nume',
    'SELECT 1'
);
PREPARE stmt_add_benef_tip_marfa FROM @sql_add_benef_tip_marfa;
EXECUTE stmt_add_benef_tip_marfa;
DEALLOCATE PREPARE stmt_add_benef_tip_marfa;

SET @has_benef_suporta_primar := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_beneficiari_transport'
      AND COLUMN_NAME = 'suporta_primar'
);
SET @sql_add_benef_suporta_primar := IF(
    @has_benef_suporta_primar = 0,
    'ALTER TABLE configurare_beneficiari_transport ADD COLUMN suporta_primar TINYINT(1) NOT NULL DEFAULT 1 AFTER pret_tarifare',
    'SELECT 1'
);
PREPARE stmt_add_benef_suporta_primar FROM @sql_add_benef_suporta_primar;
EXECUTE stmt_add_benef_suporta_primar;
DEALLOCATE PREPARE stmt_add_benef_suporta_primar;

SET @has_benef_suporta_distributie := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_beneficiari_transport'
      AND COLUMN_NAME = 'suporta_distributie'
);
SET @sql_add_benef_suporta_distributie := IF(
    @has_benef_suporta_distributie = 0,
    'ALTER TABLE configurare_beneficiari_transport ADD COLUMN suporta_distributie TINYINT(1) NOT NULL DEFAULT 1 AFTER suporta_primar',
    'SELECT 1'
);
PREPARE stmt_add_benef_suporta_distributie FROM @sql_add_benef_suporta_distributie;
EXECUTE stmt_add_benef_suporta_distributie;
DEALLOCATE PREPARE stmt_add_benef_suporta_distributie;

SET @has_benef_suporta_primar_distributie := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_beneficiari_transport'
      AND COLUMN_NAME = 'suporta_primar_distributie'
);
SET @sql_add_benef_suporta_primar_distributie := IF(
    @has_benef_suporta_primar_distributie = 0,
    'ALTER TABLE configurare_beneficiari_transport ADD COLUMN suporta_primar_distributie TINYINT(1) NOT NULL DEFAULT 0 AFTER suporta_distributie',
    'SELECT 1'
);
PREPARE stmt_add_benef_suporta_primar_distributie FROM @sql_add_benef_suporta_primar_distributie;
EXECUTE stmt_add_benef_suporta_primar_distributie;
DEALLOCATE PREPARE stmt_add_benef_suporta_primar_distributie;

UPDATE configurare_beneficiari_transport
SET suporta_primar_distributie = CASE
    WHEN COALESCE(suporta_primar, 0) = 1 AND COALESCE(suporta_distributie, 0) = 1 THEN 1
    ELSE COALESCE(suporta_primar_distributie, 0)
END
WHERE @has_benef_suporta_primar_distributie = 0;

SET @has_benef_suporta_compresor := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_beneficiari_transport'
      AND COLUMN_NAME = 'suporta_compresor'
);
SET @sql_add_benef_suporta_compresor := IF(
    @has_benef_suporta_compresor = 0,
    'ALTER TABLE configurare_beneficiari_transport ADD COLUMN suporta_compresor TINYINT(1) NOT NULL DEFAULT 0 AFTER suporta_primar_distributie',
    'SELECT 1'
);
PREPARE stmt_add_benef_suporta_compresor FROM @sql_add_benef_suporta_compresor;
EXECUTE stmt_add_benef_suporta_compresor;
DEALLOCATE PREPARE stmt_add_benef_suporta_compresor;

SET @has_benef_pret_km := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_beneficiari_transport'
      AND COLUMN_NAME = 'pret_km'
);
SET @sql_add_benef_pret_km := IF(
    @has_benef_pret_km = 0,
    'ALTER TABLE configurare_beneficiari_transport ADD COLUMN pret_km DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER suporta_compresor',
    'SELECT 1'
);
PREPARE stmt_add_benef_pret_km FROM @sql_add_benef_pret_km;
EXECUTE stmt_add_benef_pret_km;
DEALLOCATE PREPARE stmt_add_benef_pret_km;

SET @has_benef_pret_tona := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_beneficiari_transport'
      AND COLUMN_NAME = 'pret_tona'
);
SET @sql_add_benef_pret_tona := IF(
    @has_benef_pret_tona = 0,
    'ALTER TABLE configurare_beneficiari_transport ADD COLUMN pret_tona DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER pret_km',
    'SELECT 1'
);
PREPARE stmt_add_benef_pret_tona FROM @sql_add_benef_pret_tona;
EXECUTE stmt_add_benef_pret_tona;
DEALLOCATE PREPARE stmt_add_benef_pret_tona;

SET @has_benef_pret_distributie_km := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_beneficiari_transport'
      AND COLUMN_NAME = 'pret_distributie_km'
);
SET @sql_add_benef_pret_distributie_km := IF(
    @has_benef_pret_distributie_km = 0,
    'ALTER TABLE configurare_beneficiari_transport ADD COLUMN pret_distributie_km DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER pret_tona',
    'SELECT 1'
);
PREPARE stmt_add_benef_pret_distributie_km FROM @sql_add_benef_pret_distributie_km;
EXECUTE stmt_add_benef_pret_distributie_km;
DEALLOCATE PREPARE stmt_add_benef_pret_distributie_km;

SET @has_benef_pret_distributie_tona := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_beneficiari_transport'
      AND COLUMN_NAME = 'pret_distributie_tona'
);
SET @sql_add_benef_pret_distributie_tona := IF(
    @has_benef_pret_distributie_tona = 0,
    'ALTER TABLE configurare_beneficiari_transport ADD COLUMN pret_distributie_tona DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER pret_distributie_km',
    'SELECT 1'
);
PREPARE stmt_add_benef_pret_distributie_tona FROM @sql_add_benef_pret_distributie_tona;
EXECUTE stmt_add_benef_pret_distributie_tona;
DEALLOCATE PREPARE stmt_add_benef_pret_distributie_tona;

SET @has_benef_pret_ora_aspirare := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_beneficiari_transport'
      AND COLUMN_NAME = 'pret_ora_aspirare'
);
SET @sql_add_benef_pret_ora_aspirare := IF(
    @has_benef_pret_ora_aspirare = 0,
    'ALTER TABLE configurare_beneficiari_transport ADD COLUMN pret_ora_aspirare DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER pret_tona',
    'SELECT 1'
);
PREPARE stmt_add_benef_pret_ora_aspirare FROM @sql_add_benef_pret_ora_aspirare;
EXECUTE stmt_add_benef_pret_ora_aspirare;
DEALLOCATE PREPARE stmt_add_benef_pret_ora_aspirare;

SET @has_benef_pret_km_dislocare := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_beneficiari_transport'
      AND COLUMN_NAME = 'pret_km_dislocare'
);
SET @sql_add_benef_pret_km_dislocare := IF(
    @has_benef_pret_km_dislocare = 0,
    'ALTER TABLE configurare_beneficiari_transport ADD COLUMN pret_km_dislocare DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER pret_ora_aspirare',
    'SELECT 1'
);
PREPARE stmt_add_benef_pret_km_dislocare FROM @sql_add_benef_pret_km_dislocare;
EXECUTE stmt_add_benef_pret_km_dislocare;
DEALLOCATE PREPARE stmt_add_benef_pret_km_dislocare;

SET @has_benef_pret_tona_livrata := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_beneficiari_transport'
      AND COLUMN_NAME = 'pret_tona_livrata'
);
SET @sql_add_benef_pret_tona_livrata := IF(
    @has_benef_pret_tona_livrata = 0,
    'ALTER TABLE configurare_beneficiari_transport ADD COLUMN pret_tona_livrata DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER pret_km_dislocare',
    'SELECT 1'
);
PREPARE stmt_add_benef_pret_tona_livrata FROM @sql_add_benef_pret_tona_livrata;
EXECUTE stmt_add_benef_pret_tona_livrata;
DEALLOCATE PREPARE stmt_add_benef_pret_tona_livrata;

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

SET @has_loc_tarif := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_locuri_incarcare'
      AND COLUMN_NAME = 'tarif'
);
SET @sql_add_loc_tarif := IF(
    @has_loc_tarif = 0,
    'ALTER TABLE configurare_locuri_incarcare ADD COLUMN tarif DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER nume',
    'SELECT 1'
);
PREPARE stmt_add_loc_tarif FROM @sql_add_loc_tarif;
EXECUTE stmt_add_loc_tarif;
DEALLOCATE PREPARE stmt_add_loc_tarif;

SET @has_loc_beneficiar_id := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_locuri_incarcare'
      AND COLUMN_NAME = 'beneficiar_id'
);
SET @sql_add_loc_beneficiar_id := IF(
    @has_loc_beneficiar_id = 0,
    'ALTER TABLE configurare_locuri_incarcare ADD COLUMN beneficiar_id INT UNSIGNED NULL AFTER id',
    'SELECT 1'
);
PREPARE stmt_add_loc_beneficiar_id FROM @sql_add_loc_beneficiar_id;
EXECUTE stmt_add_loc_beneficiar_id;
DEALLOCATE PREPARE stmt_add_loc_beneficiar_id;

SET @has_zone_cost_extra_km := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_zone_distributie'
      AND COLUMN_NAME = 'cost_extra_km'
);
SET @sql_add_zone_cost_extra_km := IF(
    @has_zone_cost_extra_km = 0,
    'ALTER TABLE configurare_zone_distributie ADD COLUMN cost_extra_km DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER tarif_distributie',
    'SELECT 1'
);
PREPARE stmt_add_zone_cost_extra_km FROM @sql_add_zone_cost_extra_km;
EXECUTE stmt_add_zone_cost_extra_km;
DEALLOCATE PREPARE stmt_add_zone_cost_extra_km;

SET @has_zone_beneficiar_id := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_zone_distributie'
      AND COLUMN_NAME = 'beneficiar_id'
);
SET @sql_add_zone_beneficiar_id := IF(
    @has_zone_beneficiar_id = 0,
    'ALTER TABLE configurare_zone_distributie ADD COLUMN beneficiar_id INT UNSIGNED NULL AFTER id',
    'SELECT 1'
);
PREPARE stmt_add_zone_beneficiar_id FROM @sql_add_zone_beneficiar_id;
EXECUTE stmt_add_zone_beneficiar_id;
DEALLOCATE PREPARE stmt_add_zone_beneficiar_id;

SET @has_loc_assign_beneficiar_id := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_locuri_incarcare_vehicule'
      AND COLUMN_NAME = 'beneficiar_id'
);
SET @sql_add_loc_assign_beneficiar_id := IF(
    @has_loc_assign_beneficiar_id = 0,
    'ALTER TABLE configurare_locuri_incarcare_vehicule ADD COLUMN beneficiar_id INT UNSIGNED NULL AFTER id',
    'SELECT 1'
);
PREPARE stmt_add_loc_assign_beneficiar_id FROM @sql_add_loc_assign_beneficiar_id;
EXECUTE stmt_add_loc_assign_beneficiar_id;
DEALLOCATE PREPARE stmt_add_loc_assign_beneficiar_id;

SET @has_zone_assign_beneficiar_id := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_zone_distributie_vehicule'
      AND COLUMN_NAME = 'beneficiar_id'
);
SET @sql_add_zone_assign_beneficiar_id := IF(
    @has_zone_assign_beneficiar_id = 0,
    'ALTER TABLE configurare_zone_distributie_vehicule ADD COLUMN beneficiar_id INT UNSIGNED NULL AFTER id',
    'SELECT 1'
);
PREPARE stmt_add_zone_assign_beneficiar_id FROM @sql_add_zone_assign_beneficiar_id;
EXECUTE stmt_add_zone_assign_beneficiar_id;
DEALLOCATE PREPARE stmt_add_zone_assign_beneficiar_id;

UPDATE configurare_locuri_incarcare li
SET li.beneficiar_id = (
    SELECT bt.id
    FROM configurare_beneficiari_transport bt
    ORDER BY bt.id ASC
    LIMIT 1
)
WHERE li.beneficiar_id IS NULL;

UPDATE configurare_zone_distributie zd
SET zd.beneficiar_id = (
    SELECT bt.id
    FROM configurare_beneficiari_transport bt
    ORDER BY bt.id ASC
    LIMIT 1
)
WHERE zd.beneficiar_id IS NULL;

UPDATE configurare_locuri_incarcare_vehicule liv
INNER JOIN configurare_locuri_incarcare li ON li.id = liv.loc_incarcare_id
SET liv.beneficiar_id = li.beneficiar_id
WHERE liv.beneficiar_id IS NULL;

UPDATE configurare_zone_distributie_vehicule zdv
INNER JOIN configurare_zone_distributie zd ON zd.id = zdv.zona_distributie_id
SET zdv.beneficiar_id = zd.beneficiar_id
WHERE zdv.beneficiar_id IS NULL;

SET @has_idx_loc_vehicle_vehicle := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_locuri_incarcare_vehicule'
      AND INDEX_NAME = 'idx_config_locuri_vehicle_vehicle'
);
SET @sql_add_idx_loc_vehicle_vehicle := IF(
    @has_idx_loc_vehicle_vehicle = 0,
    'ALTER TABLE configurare_locuri_incarcare_vehicule ADD INDEX idx_config_locuri_vehicle_vehicle (vehicle_id)',
    'SELECT 1'
);
PREPARE stmt_add_idx_loc_vehicle_vehicle FROM @sql_add_idx_loc_vehicle_vehicle;
EXECUTE stmt_add_idx_loc_vehicle_vehicle;
DEALLOCATE PREPARE stmt_add_idx_loc_vehicle_vehicle;

SET @has_old_uk_loc_vehicle := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_locuri_incarcare_vehicule'
      AND INDEX_NAME = 'uk_config_locuri_vehicle'
);
SET @sql_drop_old_uk_loc_vehicle := IF(
    @has_old_uk_loc_vehicle > 0,
    'ALTER TABLE configurare_locuri_incarcare_vehicule DROP INDEX uk_config_locuri_vehicle',
    'SELECT 1'
);
PREPARE stmt_drop_old_uk_loc_vehicle FROM @sql_drop_old_uk_loc_vehicle;
EXECUTE stmt_drop_old_uk_loc_vehicle;
DEALLOCATE PREPARE stmt_drop_old_uk_loc_vehicle;

SET @has_new_uk_loc_vehicle := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_locuri_incarcare_vehicule'
      AND INDEX_NAME = 'uk_config_locuri_beneficiar_vehicle'
);
SET @sql_add_new_uk_loc_vehicle := IF(
    @has_new_uk_loc_vehicle = 0,
    'ALTER TABLE configurare_locuri_incarcare_vehicule ADD UNIQUE INDEX uk_config_locuri_beneficiar_vehicle (beneficiar_id, vehicle_id)',
    'SELECT 1'
);
PREPARE stmt_add_new_uk_loc_vehicle FROM @sql_add_new_uk_loc_vehicle;
EXECUTE stmt_add_new_uk_loc_vehicle;
DEALLOCATE PREPARE stmt_add_new_uk_loc_vehicle;

SET @has_idx_zone_vehicle_vehicle := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_zone_distributie_vehicule'
      AND INDEX_NAME = 'idx_config_zone_vehicle_vehicle'
);
SET @sql_add_idx_zone_vehicle_vehicle := IF(
    @has_idx_zone_vehicle_vehicle = 0,
    'ALTER TABLE configurare_zone_distributie_vehicule ADD INDEX idx_config_zone_vehicle_vehicle (vehicle_id)',
    'SELECT 1'
);
PREPARE stmt_add_idx_zone_vehicle_vehicle FROM @sql_add_idx_zone_vehicle_vehicle;
EXECUTE stmt_add_idx_zone_vehicle_vehicle;
DEALLOCATE PREPARE stmt_add_idx_zone_vehicle_vehicle;

SET @has_old_uk_zone_vehicle := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_zone_distributie_vehicule'
      AND INDEX_NAME = 'uk_config_zone_vehicle'
);
SET @sql_drop_old_uk_zone_vehicle := IF(
    @has_old_uk_zone_vehicle > 0,
    'ALTER TABLE configurare_zone_distributie_vehicule DROP INDEX uk_config_zone_vehicle',
    'SELECT 1'
);
PREPARE stmt_drop_old_uk_zone_vehicle FROM @sql_drop_old_uk_zone_vehicle;
EXECUTE stmt_drop_old_uk_zone_vehicle;
DEALLOCATE PREPARE stmt_drop_old_uk_zone_vehicle;

SET @has_new_uk_zone_vehicle := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_zone_distributie_vehicule'
      AND INDEX_NAME = 'uk_config_zone_beneficiar_vehicle'
);
SET @sql_add_new_uk_zone_vehicle := IF(
    @has_new_uk_zone_vehicle = 0,
    'ALTER TABLE configurare_zone_distributie_vehicule ADD UNIQUE INDEX uk_config_zone_beneficiar_vehicle (beneficiar_id, vehicle_id)',
    'SELECT 1'
);
PREPARE stmt_add_new_uk_zone_vehicle FROM @sql_add_new_uk_zone_vehicle;
EXECUTE stmt_add_new_uk_zone_vehicle;
DEALLOCATE PREPARE stmt_add_new_uk_zone_vehicle;

SET @has_old_uk_loc_nume := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_locuri_incarcare'
      AND INDEX_NAME = 'uk_config_locuri_nume'
);
SET @sql_drop_old_uk_loc_nume := IF(
    @has_old_uk_loc_nume > 0,
    'ALTER TABLE configurare_locuri_incarcare DROP INDEX uk_config_locuri_nume',
    'SELECT 1'
);
PREPARE stmt_drop_old_uk_loc_nume FROM @sql_drop_old_uk_loc_nume;
EXECUTE stmt_drop_old_uk_loc_nume;
DEALLOCATE PREPARE stmt_drop_old_uk_loc_nume;

SET @has_new_uk_loc_nume := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_locuri_incarcare'
      AND INDEX_NAME = 'uk_config_locuri_beneficiar_nume'
);
SET @sql_add_new_uk_loc_nume := IF(
    @has_new_uk_loc_nume = 0,
    'ALTER TABLE configurare_locuri_incarcare ADD UNIQUE INDEX uk_config_locuri_beneficiar_nume (beneficiar_id, nume)',
    'SELECT 1'
);
PREPARE stmt_add_new_uk_loc_nume FROM @sql_add_new_uk_loc_nume;
EXECUTE stmt_add_new_uk_loc_nume;
DEALLOCATE PREPARE stmt_add_new_uk_loc_nume;

SET @has_old_uk_zone_nume := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_zone_distributie'
      AND INDEX_NAME = 'uk_config_zone_nume'
);
SET @sql_drop_old_uk_zone_nume := IF(
    @has_old_uk_zone_nume > 0,
    'ALTER TABLE configurare_zone_distributie DROP INDEX uk_config_zone_nume',
    'SELECT 1'
);
PREPARE stmt_drop_old_uk_zone_nume FROM @sql_drop_old_uk_zone_nume;
EXECUTE stmt_drop_old_uk_zone_nume;
DEALLOCATE PREPARE stmt_drop_old_uk_zone_nume;

SET @has_new_uk_zone_nume := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_zone_distributie'
      AND INDEX_NAME = 'uk_config_zone_beneficiar_nume'
);
SET @sql_add_new_uk_zone_nume := IF(
    @has_new_uk_zone_nume = 0,
    'ALTER TABLE configurare_zone_distributie ADD UNIQUE INDEX uk_config_zone_beneficiar_nume (beneficiar_id, nume)',
    'SELECT 1'
);
PREPARE stmt_add_new_uk_zone_nume FROM @sql_add_new_uk_zone_nume;
EXECUTE stmt_add_new_uk_zone_nume;
DEALLOCATE PREPARE stmt_add_new_uk_zone_nume;

SET @has_fk_loc_beneficiar := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_locuri_incarcare'
      AND CONSTRAINT_NAME = 'fk_config_locuri_beneficiar'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql_add_fk_loc_beneficiar := IF(
    @has_fk_loc_beneficiar = 0,
    'ALTER TABLE configurare_locuri_incarcare ADD CONSTRAINT fk_config_locuri_beneficiar FOREIGN KEY (beneficiar_id) REFERENCES configurare_beneficiari_transport(id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE stmt_add_fk_loc_beneficiar FROM @sql_add_fk_loc_beneficiar;
EXECUTE stmt_add_fk_loc_beneficiar;
DEALLOCATE PREPARE stmt_add_fk_loc_beneficiar;

SET @has_fk_zone_beneficiar := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_zone_distributie'
      AND CONSTRAINT_NAME = 'fk_config_zone_beneficiar'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql_add_fk_zone_beneficiar := IF(
    @has_fk_zone_beneficiar = 0,
    'ALTER TABLE configurare_zone_distributie ADD CONSTRAINT fk_config_zone_beneficiar FOREIGN KEY (beneficiar_id) REFERENCES configurare_beneficiari_transport(id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE stmt_add_fk_zone_beneficiar FROM @sql_add_fk_zone_beneficiar;
EXECUTE stmt_add_fk_zone_beneficiar;
DEALLOCATE PREPARE stmt_add_fk_zone_beneficiar;

SET @has_fk_loc_assign_beneficiar := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_locuri_incarcare_vehicule'
      AND CONSTRAINT_NAME = 'fk_config_locuri_vehicle_beneficiar'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql_add_fk_loc_assign_beneficiar := IF(
    @has_fk_loc_assign_beneficiar = 0,
    'ALTER TABLE configurare_locuri_incarcare_vehicule ADD CONSTRAINT fk_config_locuri_vehicle_beneficiar FOREIGN KEY (beneficiar_id) REFERENCES configurare_beneficiari_transport(id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE stmt_add_fk_loc_assign_beneficiar FROM @sql_add_fk_loc_assign_beneficiar;
EXECUTE stmt_add_fk_loc_assign_beneficiar;
DEALLOCATE PREPARE stmt_add_fk_loc_assign_beneficiar;

SET @has_fk_zone_assign_beneficiar := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_zone_distributie_vehicule'
      AND CONSTRAINT_NAME = 'fk_config_zone_vehicle_beneficiar'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql_add_fk_zone_assign_beneficiar := IF(
    @has_fk_zone_assign_beneficiar = 0,
    'ALTER TABLE configurare_zone_distributie_vehicule ADD CONSTRAINT fk_config_zone_vehicle_beneficiar FOREIGN KEY (beneficiar_id) REFERENCES configurare_beneficiari_transport(id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE stmt_add_fk_zone_assign_beneficiar FROM @sql_add_fk_zone_assign_beneficiar;
EXECUTE stmt_add_fk_zone_assign_beneficiar;
DEALLOCATE PREPARE stmt_add_fk_zone_assign_beneficiar;

SET @has_route_vehicle_ids_column := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_rute_distributie'
      AND COLUMN_NAME = 'vehicle_ids'
);
SET @sql_add_route_vehicle_ids_column := IF(
    @has_route_vehicle_ids_column = 0,
    'ALTER TABLE configurare_rute_distributie ADD COLUMN vehicle_ids TEXT NULL AFTER cost_extra_km',
    'SELECT 1'
);
PREPARE stmt_add_route_vehicle_ids_column FROM @sql_add_route_vehicle_ids_column;
EXECUTE stmt_add_route_vehicle_ids_column;
DEALLOCATE PREPARE stmt_add_route_vehicle_ids_column;

SET @has_route_tarif_mod_column := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_rute_distributie'
      AND COLUMN_NAME = 'tarif_mod'
);
SET @sql_add_route_tarif_mod_column := IF(
    @has_route_tarif_mod_column = 0,
    'ALTER TABLE configurare_rute_distributie ADD COLUMN tarif_mod ENUM(''tona_km'', ''tona'', ''km'') NOT NULL DEFAULT ''tona_km'' AFTER zona_distributie_id',
    'SELECT 1'
);
PREPARE stmt_add_route_tarif_mod_column FROM @sql_add_route_tarif_mod_column;
EXECUTE stmt_add_route_tarif_mod_column;
DEALLOCATE PREPARE stmt_add_route_tarif_mod_column;

UPDATE configurare_rute_distributie
SET tarif_mod = 'tona_km'
WHERE tarif_mod IS NULL
   OR tarif_mod = ''
   OR tarif_mod NOT IN ('tona_km', 'tona', 'km');

SET @has_route_km_tarifare_column := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_rute_distributie'
      AND COLUMN_NAME = 'km_tarifare'
);
SET @sql_add_route_km_tarifare_column := IF(
    @has_route_km_tarifare_column = 0,
    'ALTER TABLE configurare_rute_distributie ADD COLUMN km_tarifare INT UNSIGNED NOT NULL DEFAULT 0 AFTER cost_extra_km',
    'SELECT 1'
);
PREPARE stmt_add_route_km_tarifare_column FROM @sql_add_route_km_tarifare_column;
EXECUTE stmt_add_route_km_tarifare_column;
DEALLOCATE PREPARE stmt_add_route_km_tarifare_column;

SET @has_route_cost_cursa_column := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_rute_distributie'
      AND COLUMN_NAME = 'cost_cursa'
);
SET @sql_add_route_cost_cursa_column := IF(
    @has_route_cost_cursa_column = 0,
    'ALTER TABLE configurare_rute_distributie ADD COLUMN cost_cursa DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER km_tarifare',
    'SELECT 1'
);
PREPARE stmt_add_route_cost_cursa_column FROM @sql_add_route_cost_cursa_column;
EXECUTE stmt_add_route_cost_cursa_column;
DEALLOCATE PREPARE stmt_add_route_cost_cursa_column;

SET @has_route_apply_cost_cursa_column := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_rute_distributie'
      AND COLUMN_NAME = 'aplica_cost_cursa'
);
SET @sql_add_route_apply_cost_cursa_column := IF(
    @has_route_apply_cost_cursa_column = 0,
    'ALTER TABLE configurare_rute_distributie ADD COLUMN aplica_cost_cursa TINYINT(1) NOT NULL DEFAULT 0 AFTER cost_cursa',
    'SELECT 1'
);
PREPARE stmt_add_route_apply_cost_cursa_column FROM @sql_add_route_apply_cost_cursa_column;
EXECUTE stmt_add_route_apply_cost_cursa_column;
DEALLOCATE PREPARE stmt_add_route_apply_cost_cursa_column;

SET @loc_incarcare_is_nullable := (
    SELECT CASE WHEN IS_NULLABLE = 'YES' THEN 1 ELSE 0 END
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND COLUMN_NAME = 'loc_incarcare_id'
    LIMIT 1
);
SET @sql_make_loc_incarcare_nullable := IF(
    COALESCE(@loc_incarcare_is_nullable, 0) = 0,
    'ALTER TABLE curse_dispecer MODIFY COLUMN loc_incarcare_id INT UNSIGNED NULL',
    'SELECT 1'
);
PREPARE stmt_make_loc_incarcare_nullable FROM @sql_make_loc_incarcare_nullable;
EXECUTE stmt_make_loc_incarcare_nullable;
DEALLOCATE PREPARE stmt_make_loc_incarcare_nullable;

UPDATE configurare_beneficiari_transport
SET
    pret_tarifare = 5.00,
    pret_km = 5.00,
    pret_tona = 2.80,
    pret_distributie_km = COALESCE(pret_distributie_km, 0),
    pret_distributie_tona = COALESCE(pret_distributie_tona, 0),
    pret_ora_aspirare = COALESCE(pret_ora_aspirare, 0),
    pret_km_dislocare = COALESCE(pret_km_dislocare, 0),
    pret_tona_livrata = COALESCE(pret_tona_livrata, 0),
    pret_tona_aspirata_lichida = COALESCE(pret_tona_aspirata_lichida, 0),
    pret_tona_aspirata_gazoasa = COALESCE(pret_tona_aspirata_gazoasa, 0),
    suporta_primar = COALESCE(suporta_primar, 1),
    suporta_distributie = COALESCE(suporta_distributie, 1),
    suporta_compresor = COALESCE(suporta_compresor, 0)
WHERE COALESCE(pret_tarifare, 0) = 0
  AND COALESCE(pret_km, 0) = 0
  AND COALESCE(pret_tona, 0) = 0;

UPDATE configurare_beneficiari_transport
SET tip_marfa = 'altele'
WHERE COALESCE(tip_marfa, '') = '';

-- Compatibilitate pentru baze deja existente: adauga coloana beneficiar_id daca lipseste.
SET @has_beneficiar_column := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND COLUMN_NAME = 'beneficiar_id'
);
SET @sql_add_beneficiar_column := IF(
    @has_beneficiar_column = 0,
    'ALTER TABLE curse_dispecer ADD COLUMN beneficiar_id INT UNSIGNED NULL AFTER loc_incarcare_id',
    'SELECT 1'
);
PREPARE stmt_add_beneficiar_column FROM @sql_add_beneficiar_column;
EXECUTE stmt_add_beneficiar_column;
DEALLOCATE PREPARE stmt_add_beneficiar_column;

SET @has_beneficiar_idx := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND INDEX_NAME = 'idx_curse_beneficiar'
);
SET @sql_add_beneficiar_idx := IF(
    @has_beneficiar_idx = 0,
    'ALTER TABLE curse_dispecer ADD INDEX idx_curse_beneficiar (beneficiar_id)',
    'SELECT 1'
);
PREPARE stmt_add_beneficiar_idx FROM @sql_add_beneficiar_idx;
EXECUTE stmt_add_beneficiar_idx;
DEALLOCATE PREPARE stmt_add_beneficiar_idx;

SET @has_beneficiar_fk := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
      AND CONSTRAINT_NAME = 'fk_curse_beneficiar'
);
SET @sql_add_beneficiar_fk := IF(
    @has_beneficiar_fk = 0,
    'ALTER TABLE curse_dispecer ADD CONSTRAINT fk_curse_beneficiar FOREIGN KEY (beneficiar_id) REFERENCES configurare_beneficiari_transport(id) ON DELETE RESTRICT',
    'SELECT 1'
);
PREPARE stmt_add_beneficiar_fk FROM @sql_add_beneficiar_fk;
EXECUTE stmt_add_beneficiar_fk;
DEALLOCATE PREPARE stmt_add_beneficiar_fk;

INSERT INTO configurare_beneficiari_transport (nume, tip_marfa, pret_tarifare, suporta_primar, suporta_distributie, suporta_compresor, pret_km, pret_tona, pret_distributie_km, pret_distributie_tona, pret_ora_aspirare, pret_km_dislocare, pret_tona_livrata, activ, created_at, updated_at)
SELECT 'LPG AUTO', 'gpl_vrac', 5.50, 1, 1, 0, 5.50, 2.85, 0.00, 0.00, 0.00, 0.00, 0.00, 1, NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM configurare_beneficiari_transport WHERE nume = 'LPG AUTO'
);

INSERT INTO configurare_beneficiari_transport (nume, tip_marfa, pret_tarifare, suporta_primar, suporta_distributie, suporta_compresor, pret_km, pret_tona, pret_distributie_km, pret_distributie_tona, pret_ora_aspirare, pret_km_dislocare, pret_tona_livrata, activ, created_at, updated_at)
SELECT 'Retail Client SRL', 'butelii', 5.20, 1, 1, 0, 5.20, 2.60, 0.00, 0.00, 0.00, 0.00, 0.00, 1, NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM configurare_beneficiari_transport WHERE nume = 'Retail Client SRL'
);

INSERT INTO configurare_beneficiari_transport (nume, tip_marfa, pret_tarifare, suporta_primar, suporta_distributie, suporta_compresor, pret_km, pret_tona, pret_distributie_km, pret_distributie_tona, pret_ora_aspirare, pret_km_dislocare, pret_tona_livrata, activ, created_at, updated_at)
SELECT 'Distrib Logistic SA', 'carburant', 5.80, 1, 1, 0, 5.80, 3.20, 0.00, 0.00, 0.00, 0.00, 0.00, 1, NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM configurare_beneficiari_transport WHERE nume = 'Distrib Logistic SA'
);

INSERT INTO configurare_locuri_incarcare (beneficiar_id, nume, tarif, activ, created_at, updated_at)
SELECT b.id, 'Depozit Central Bucuresti', 0.00, 1, NOW(), NOW()
FROM configurare_beneficiari_transport b
WHERE b.nume = 'LPG AUTO'
  AND NOT EXISTS (
      SELECT 1 FROM configurare_locuri_incarcare li WHERE li.beneficiar_id = b.id AND li.nume = 'Depozit Central Bucuresti'
  );

INSERT INTO configurare_locuri_incarcare (beneficiar_id, nume, tarif, activ, created_at, updated_at)
SELECT b.id, 'Terminal Brasov', 0.00, 1, NOW(), NOW()
FROM configurare_beneficiari_transport b
WHERE b.nume = 'Retail Client SRL'
  AND NOT EXISTS (
      SELECT 1 FROM configurare_locuri_incarcare li WHERE li.beneficiar_id = b.id AND li.nume = 'Terminal Brasov'
  );

INSERT INTO configurare_locuri_incarcare (beneficiar_id, nume, tarif, activ, created_at, updated_at)
SELECT b.id, 'Hub Cluj', 0.00, 1, NOW(), NOW()
FROM configurare_beneficiari_transport b
WHERE b.nume = 'LPG AUTO'
  AND NOT EXISTS (
      SELECT 1 FROM configurare_locuri_incarcare li WHERE li.beneficiar_id = b.id AND li.nume = 'Hub Cluj'
  );

INSERT INTO configurare_zone_distributie (beneficiar_id, nume, tarif_distributie, cost_extra_km, activ, created_at, updated_at)
SELECT b.id, 'Bucuresti', 2.60, 0.00, 1, NOW(), NOW()
FROM configurare_beneficiari_transport b
WHERE b.nume = 'Retail Client SRL'
  AND NOT EXISTS (
      SELECT 1 FROM configurare_zone_distributie zd WHERE zd.beneficiar_id = b.id AND zd.nume = 'Bucuresti'
  );

INSERT INTO configurare_zone_distributie (beneficiar_id, nume, tarif_distributie, cost_extra_km, activ, created_at, updated_at)
SELECT b.id, 'Ilfov', 2.85, 0.00, 1, NOW(), NOW()
FROM configurare_beneficiari_transport b
WHERE b.nume = 'LPG AUTO'
  AND NOT EXISTS (
      SELECT 1 FROM configurare_zone_distributie zd WHERE zd.beneficiar_id = b.id AND zd.nume = 'Ilfov'
  );

INSERT INTO configurare_zone_distributie (beneficiar_id, nume, tarif_distributie, cost_extra_km, activ, created_at, updated_at)
SELECT b.id, 'Regional', 3.20, 0.00, 1, NOW(), NOW()
FROM configurare_beneficiari_transport b
WHERE b.nume = 'LPG AUTO'
  AND NOT EXISTS (
      SELECT 1 FROM configurare_zone_distributie zd WHERE zd.beneficiar_id = b.id AND zd.nume = 'Regional'
  );
