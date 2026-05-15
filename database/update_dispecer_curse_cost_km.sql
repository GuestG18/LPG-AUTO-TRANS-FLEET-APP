-- Migrare: adauga coloanele cost/km pentru Dispecer curse
-- Data: 2026-05-14

SET NAMES utf8mb4;

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
