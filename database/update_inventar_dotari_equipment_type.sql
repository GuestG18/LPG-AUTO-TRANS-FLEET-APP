SET NAMES utf8mb4;

SET @has_equipment_type := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'inventar_dotari_catalog'
      AND COLUMN_NAME = 'equipment_type'
);

SET @sql_equipment_type := IF(
    @has_equipment_type = 0,
    'ALTER TABLE inventar_dotari_catalog ADD COLUMN equipment_type ENUM(''mandatory'', ''optional'') NOT NULL DEFAULT ''mandatory'' AFTER categorie',
    'SELECT 1'
);
PREPARE stmt_equipment_type FROM @sql_equipment_type;
EXECUTE stmt_equipment_type;
DEALLOCATE PREPARE stmt_equipment_type;

UPDATE inventar_dotari_catalog
SET equipment_type = 'mandatory'
WHERE equipment_type IS NULL
   OR equipment_type NOT IN ('mandatory', 'optional');

SET @has_equipment_type_index := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'inventar_dotari_catalog'
      AND INDEX_NAME = 'idx_inventar_catalog_equipment_type'
);

SET @sql_equipment_type_index := IF(
    @has_equipment_type_index = 0,
    'ALTER TABLE inventar_dotari_catalog ADD INDEX idx_inventar_catalog_equipment_type (equipment_type)',
    'SELECT 1'
);
PREPARE stmt_equipment_type_index FROM @sql_equipment_type_index;
EXECUTE stmt_equipment_type_index;
DEALLOCATE PREPARE stmt_equipment_type_index;
