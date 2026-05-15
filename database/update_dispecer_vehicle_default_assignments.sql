-- Migrare: alocari implicite vehicul pentru Loc incarcare si Zona distributie
-- Data: 2026-04-30

SET NAMES utf8mb4;

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

SET @has_loc_assign_beneficiar := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_locuri_incarcare_vehicule'
      AND COLUMN_NAME = 'beneficiar_id'
);
SET @sql_add_loc_assign_beneficiar := IF(
    @has_loc_assign_beneficiar = 0,
    'ALTER TABLE configurare_locuri_incarcare_vehicule ADD COLUMN beneficiar_id INT UNSIGNED NULL AFTER id',
    'SELECT 1'
);
PREPARE stmt_add_loc_assign_beneficiar FROM @sql_add_loc_assign_beneficiar;
EXECUTE stmt_add_loc_assign_beneficiar;
DEALLOCATE PREPARE stmt_add_loc_assign_beneficiar;

SET @has_zone_assign_beneficiar := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_zone_distributie_vehicule'
      AND COLUMN_NAME = 'beneficiar_id'
);
SET @sql_add_zone_assign_beneficiar := IF(
    @has_zone_assign_beneficiar = 0,
    'ALTER TABLE configurare_zone_distributie_vehicule ADD COLUMN beneficiar_id INT UNSIGNED NULL AFTER id',
    'SELECT 1'
);
PREPARE stmt_add_zone_assign_beneficiar FROM @sql_add_zone_assign_beneficiar;
EXECUTE stmt_add_zone_assign_beneficiar;
DEALLOCATE PREPARE stmt_add_zone_assign_beneficiar;

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
