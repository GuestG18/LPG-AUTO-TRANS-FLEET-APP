-- Migrare: an fabricatie rezervor pentru vehicule
-- Data: 2026-09-07

SET NAMES utf8mb4;

SET @has_an_fabricatie_rezervor := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'vehicule'
      AND COLUMN_NAME = 'an_fabricatie_rezervor'
);

SET @sql_add_an_fabricatie_rezervor := IF(
    @has_an_fabricatie_rezervor = 0,
    "ALTER TABLE vehicule ADD COLUMN an_fabricatie_rezervor SMALLINT UNSIGNED NULL AFTER nr_fabricatie",
    'SELECT 1'
);

PREPARE stmt_add_an_fabricatie_rezervor FROM @sql_add_an_fabricatie_rezervor;
EXECUTE stmt_add_an_fabricatie_rezervor;
DEALLOCATE PREPARE stmt_add_an_fabricatie_rezervor;
