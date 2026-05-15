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
    "ALTER TABLE anvelope ADD COLUMN target_vehicle_type ENUM('autovehicul', 'camion', 'cap_tractor', 'semiremorca', 'universal') NOT NULL DEFAULT 'universal' AFTER serial_number",
    "SELECT 'target_vehicle_type already exists' AS message"
);

PREPARE stmt_add_target_vehicle_type FROM @sql_add_target_vehicle_type;
EXECUTE stmt_add_target_vehicle_type;
DEALLOCATE PREPARE stmt_add_target_vehicle_type;

