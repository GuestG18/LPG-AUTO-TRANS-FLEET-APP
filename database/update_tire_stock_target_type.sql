-- Migrare: tip tinta pentru stoc anvelope
-- Data: 2026-05-12

SET NAMES utf8mb4;

SET @has_target_vehicle_type := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'anvelope'
      AND COLUMN_NAME = 'target_vehicle_type'
);

SET @sql_add_target_vehicle_type := IF(
    @has_target_vehicle_type = 0,
    "ALTER TABLE anvelope ADD COLUMN target_vehicle_type ENUM('autovehicul','autoutilitara','camion','cap_tractor','semiremorca','semiremorca_primar','semiremorca_distributie','universal') NOT NULL DEFAULT 'universal' AFTER serial_number",
    "SELECT 'target_vehicle_type already exists' AS message"
);

PREPARE stmt_add_target_vehicle_type FROM @sql_add_target_vehicle_type;
EXECUTE stmt_add_target_vehicle_type;
DEALLOCATE PREPARE stmt_add_target_vehicle_type;

SET @sql_modify_target_vehicle_type := IF(
    @has_target_vehicle_type = 1,
    "ALTER TABLE anvelope MODIFY COLUMN target_vehicle_type ENUM('autovehicul','autoutilitara','camion','cap_tractor','semiremorca','semiremorca_primar','semiremorca_distributie','universal') NOT NULL DEFAULT 'universal'",
    "SELECT 'target_vehicle_type created with current enum' AS message"
);

PREPARE stmt_modify_target_vehicle_type FROM @sql_modify_target_vehicle_type;
EXECUTE stmt_modify_target_vehicle_type;
DEALLOCATE PREPARE stmt_modify_target_vehicle_type;

SET @has_target_axle_config := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'anvelope'
      AND COLUMN_NAME = 'target_axle_config'
);

SET @sql_add_target_axle_config := IF(
    @has_target_axle_config = 0,
    "ALTER TABLE anvelope ADD COLUMN target_axle_config VARCHAR(20) NULL AFTER target_vehicle_type",
    "SELECT 'target_axle_config already exists' AS message"
);

PREPARE stmt_add_target_axle_config FROM @sql_add_target_axle_config;
EXECUTE stmt_add_target_axle_config;
DEALLOCATE PREPARE stmt_add_target_axle_config;

SET @has_axle_type := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'anvelope'
      AND COLUMN_NAME = 'axle_type'
);

SET @sql_add_axle_type := IF(
    @has_axle_type = 0,
    "ALTER TABLE anvelope ADD COLUMN axle_type VARCHAR(40) NULL AFTER target_axle_config",
    "SELECT 'axle_type already exists' AS message"
);

PREPARE stmt_add_axle_type FROM @sql_add_axle_type;
EXECUTE stmt_add_axle_type;
DEALLOCATE PREPARE stmt_add_axle_type;
