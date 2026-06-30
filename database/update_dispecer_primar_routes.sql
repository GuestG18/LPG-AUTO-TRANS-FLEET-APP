SET NAMES utf8mb4;

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

SET @has_km_totali := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND COLUMN_NAME = 'km_totali'
);
SET @sql_add_km_totali := IF(
    @has_km_totali = 0,
    'ALTER TABLE curse_dispecer ADD COLUMN km_totali INT UNSIGNED NULL AFTER km_cursa',
    'SELECT 1'
);
PREPARE stmt_add_km_totali FROM @sql_add_km_totali;
EXECUTE stmt_add_km_totali;
DEALLOCATE PREPARE stmt_add_km_totali;
