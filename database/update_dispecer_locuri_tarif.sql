-- Migrare: tarif pentru locuri incarcare + stergere locatie folosita in curse
-- Data: 2026-04-27

SET NAMES utf8mb4;

SET @has_loc_tarif := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_locuri_incarcare'
      AND COLUMN_NAME = 'tarif'
);
SET @sql_add_loc_tarif := IF(
    @has_loc_tarif = 0,
    'ALTER TABLE configurare_locuri_incarcare ADD COLUMN tarif DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER nume',
    'SELECT 1'
);
PREPARE stmt_add_loc_tarif FROM @sql_add_loc_tarif;
EXECUTE stmt_add_loc_tarif;
DEALLOCATE PREPARE stmt_add_loc_tarif;

SET @loc_incarcare_is_nullable := (
    SELECT CASE WHEN IS_NULLABLE = 'YES' THEN 1 ELSE 0 END
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'curse_dispecer'
      AND COLUMN_NAME = 'loc_incarcare_id'
    LIMIT 1
);
SET @sql_make_loc_incarcare_nullable := IF(
    COALESCE(@loc_incarcare_is_nullable, 0) = 0,
    'ALTER TABLE curse_dispecer MODIFY COLUMN loc_incarcare_id INT UNSIGNED NULL',
    'SELECT 1'
);
PREPARE stmt_make_loc_incarcare_nullable FROM @sql_make_loc_incarcare_nullable;
EXECUTE stmt_make_loc_incarcare_nullable;
DEALLOCATE PREPARE stmt_make_loc_incarcare_nullable;

