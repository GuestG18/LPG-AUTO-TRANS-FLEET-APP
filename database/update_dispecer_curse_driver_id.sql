-- Migrare: adauga legatura sofer (driver_id) in curse_dispecer
-- Data: 2026-05-11

SET NAMES utf8mb4;

SET @has_curse_driver_id := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND COLUMN_NAME = 'driver_id'
);
SET @sql_add_curse_driver_id := IF(
    @has_curse_driver_id = 0,
    'ALTER TABLE curse_dispecer ADD COLUMN driver_id INT UNSIGNED NULL AFTER vehicle_id',
    'SELECT 1'
);
PREPARE stmt_add_curse_driver_id FROM @sql_add_curse_driver_id;
EXECUTE stmt_add_curse_driver_id;
DEALLOCATE PREPARE stmt_add_curse_driver_id;

SET @has_idx_curse_driver := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND INDEX_NAME = 'idx_curse_driver'
);
SET @sql_add_idx_curse_driver := IF(
    @has_idx_curse_driver = 0,
    'ALTER TABLE curse_dispecer ADD INDEX idx_curse_driver (driver_id)',
    'SELECT 1'
);
PREPARE stmt_add_idx_curse_driver FROM @sql_add_idx_curse_driver;
EXECUTE stmt_add_idx_curse_driver;
DEALLOCATE PREPARE stmt_add_idx_curse_driver;

SET @has_fk_curse_driver := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND CONSTRAINT_NAME = 'fk_curse_driver'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql_add_fk_curse_driver := IF(
    @has_fk_curse_driver = 0,
    'ALTER TABLE curse_dispecer ADD CONSTRAINT fk_curse_driver FOREIGN KEY (driver_id) REFERENCES soferi(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt_add_fk_curse_driver FROM @sql_add_fk_curse_driver;
EXECUTE stmt_add_fk_curse_driver;
DEALLOCATE PREPARE stmt_add_fk_curse_driver;
