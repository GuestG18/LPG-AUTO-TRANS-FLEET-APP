-- Migrare: adaugare camp km_alimentare in alimentari
-- Data: 2026-05-18

SET NAMES utf8mb4;

SET @has_km_alimentare := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'alimentari'
      AND COLUMN_NAME = 'km_alimentare'
);

SET @sql_add_km_alimentare := IF(
    @has_km_alimentare = 0,
    'ALTER TABLE alimentari ADD COLUMN km_alimentare INT UNSIGNED NULL AFTER km_bord',
    'SELECT 1'
);
PREPARE stmt_add_km_alimentare FROM @sql_add_km_alimentare;
EXECUTE stmt_add_km_alimentare;
DEALLOCATE PREPARE stmt_add_km_alimentare;

UPDATE alimentari
SET km_alimentare = COALESCE(km_bord, 0)
WHERE km_alimentare IS NULL;

ALTER TABLE alimentari
    MODIFY COLUMN km_alimentare INT UNSIGNED NOT NULL;
