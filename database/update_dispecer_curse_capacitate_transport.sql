-- Migrare: adauga capacitate_transport in curse_dispecer si sincronizeaza cu vehicule
-- Data: 2026-05-07

SET NAMES utf8mb4;

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

UPDATE curse_dispecer c
INNER JOIN vehicule v ON v.id = c.vehicle_id
SET c.capacitate_transport = v.capacitate_transport
WHERE c.capacitate_transport IS NULL;