-- Migrare: adauga campul ore_functionare in curse_dispecer
-- Data: 2026-05-11

SET NAMES utf8mb4;

SET @has_curse_ore_functionare := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND COLUMN_NAME = 'ore_functionare'
);
SET @sql_add_curse_ore_functionare := IF(
    @has_curse_ore_functionare = 0,
    'ALTER TABLE curse_dispecer ADD COLUMN ore_functionare DECIMAL(10,2) NULL AFTER km_cursa',
    'SELECT 1'
);
PREPARE stmt_add_curse_ore_functionare FROM @sql_add_curse_ore_functionare;
EXECUTE stmt_add_curse_ore_functionare;
DEALLOCATE PREPARE stmt_add_curse_ore_functionare;
