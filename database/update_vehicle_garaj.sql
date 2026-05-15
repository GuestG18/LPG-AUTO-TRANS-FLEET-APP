-- Migrare: adauga camp Garaj pentru vehicule
-- Data: 2026-04-24

SET NAMES utf8mb4;

SET @has_garaj := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'vehicule'
      AND COLUMN_NAME = 'garaj'
);

SET @sql_add_garaj := IF(
    @has_garaj = 0,
    "ALTER TABLE vehicule ADD COLUMN garaj VARCHAR(120) NULL",
    'SELECT 1'
);

PREPARE stmt_add_garaj FROM @sql_add_garaj;
EXECUTE stmt_add_garaj;
DEALLOCATE PREPARE stmt_add_garaj;

