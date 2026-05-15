-- Migrare: adauga camp Km revizie pentru vehicule
-- Data: 2026-04-21

SET NAMES utf8mb4;

SET @has_km_revizie := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'vehicule'
      AND COLUMN_NAME = 'km_revizie'
);

SET @sql_add_km_revizie := IF(
    @has_km_revizie = 0,
    "ALTER TABLE vehicule ADD COLUMN km_revizie INT UNSIGNED NOT NULL DEFAULT 0 AFTER km_bord",
    'SELECT 1'
);

PREPARE stmt_add_km_revizie FROM @sql_add_km_revizie;
EXECUTE stmt_add_km_revizie;
DEALLOCATE PREPARE stmt_add_km_revizie;

UPDATE vehicule
SET km_revizie = CASE
    WHEN km_revizie IS NULL OR km_revizie < km_bord THEN km_bord
    ELSE km_revizie
END;

ALTER TABLE vehicule
    MODIFY COLUMN km_revizie INT UNSIGNED NOT NULL DEFAULT 0;
