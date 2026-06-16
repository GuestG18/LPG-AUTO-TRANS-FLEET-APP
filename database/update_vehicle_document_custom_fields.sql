-- Migrare: campuri personalizate pentru tipurile de documente ale vehiculelor
-- Data: 2026-06-10

SET NAMES utf8mb4;

SET @has_vehicle_doc_type_custom_fields := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_costuri_documente_vehicule'
      AND COLUMN_NAME = 'custom_fields_json'
);

SET @sql_vehicle_doc_type_custom_fields := IF(
    @has_vehicle_doc_type_custom_fields = 0,
    'ALTER TABLE configurare_costuri_documente_vehicule ADD COLUMN custom_fields_json LONGTEXT NULL AFTER requires_expiry',
    'SELECT 1'
);
PREPARE stmt_vehicle_doc_type_custom_fields FROM @sql_vehicle_doc_type_custom_fields;
EXECUTE stmt_vehicle_doc_type_custom_fields;
DEALLOCATE PREPARE stmt_vehicle_doc_type_custom_fields;

SET @has_vehicle_document_values_custom_fields := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'documente'
      AND COLUMN_NAME = 'custom_fields_json'
);

SET @sql_vehicle_document_values_custom_fields := IF(
    @has_vehicle_document_values_custom_fields = 0,
    'ALTER TABLE documente ADD COLUMN custom_fields_json LONGTEXT NULL AFTER observatii',
    'SELECT 1'
);
PREPARE stmt_vehicle_document_values_custom_fields FROM @sql_vehicle_document_values_custom_fields;
EXECUTE stmt_vehicle_document_values_custom_fields;
DEALLOCATE PREPARE stmt_vehicle_document_values_custom_fields;
