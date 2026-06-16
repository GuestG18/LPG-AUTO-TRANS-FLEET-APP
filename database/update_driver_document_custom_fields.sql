-- Migrare: campuri personalizate pentru tipurile de documente ale soferilor
-- Data: 2026-06-09

SET NAMES utf8mb4;

SET @has_driver_doc_type_custom_fields := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_documente_obligatorii_soferi'
      AND COLUMN_NAME = 'custom_fields_json'
);

SET @sql_driver_doc_type_custom_fields := IF(
    @has_driver_doc_type_custom_fields = 0,
    'ALTER TABLE configurare_documente_obligatorii_soferi ADD COLUMN custom_fields_json LONGTEXT NULL AFTER document_type',
    'SELECT 1'
);
PREPARE stmt_driver_doc_type_custom_fields FROM @sql_driver_doc_type_custom_fields;
EXECUTE stmt_driver_doc_type_custom_fields;
DEALLOCATE PREPARE stmt_driver_doc_type_custom_fields;

SET @has_driver_document_values_custom_fields := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'documente_soferi'
      AND COLUMN_NAME = 'custom_fields_json'
);

SET @sql_driver_document_values_custom_fields := IF(
    @has_driver_document_values_custom_fields = 0,
    'ALTER TABLE documente_soferi ADD COLUMN custom_fields_json LONGTEXT NULL AFTER observatii',
    'SELECT 1'
);
PREPARE stmt_driver_document_values_custom_fields FROM @sql_driver_document_values_custom_fields;
EXECUTE stmt_driver_document_values_custom_fields;
DEALLOCATE PREPARE stmt_driver_document_values_custom_fields;
