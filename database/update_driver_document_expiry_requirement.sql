-- Migrare: permite documente sofer fara data expirare si configureaza cerinta pe tip document
-- Data: 2026-06-10

SET NAMES utf8mb4;

SET @has_driver_requires_expiry := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_documente_obligatorii_soferi'
      AND COLUMN_NAME = 'requires_expiry'
);

SET @sql_driver_requires_expiry := IF(
    @has_driver_requires_expiry = 0,
    'ALTER TABLE configurare_documente_obligatorii_soferi ADD COLUMN requires_expiry TINYINT(1) NOT NULL DEFAULT 1 AFTER document_type',
    'SELECT 1'
);
PREPARE stmt_driver_requires_expiry FROM @sql_driver_requires_expiry;
EXECUTE stmt_driver_requires_expiry;
DEALLOCATE PREPARE stmt_driver_requires_expiry;

SET @driver_document_expiry_nullable := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'documente_soferi'
      AND COLUMN_NAME = 'data_expirare'
      AND IS_NULLABLE = 'YES'
);

SET @sql_driver_document_expiry_nullable := IF(
    @driver_document_expiry_nullable = 0,
    'ALTER TABLE documente_soferi MODIFY COLUMN data_expirare DATE NULL',
    'SELECT 1'
);
PREPARE stmt_driver_document_expiry_nullable FROM @sql_driver_document_expiry_nullable;
EXECUTE stmt_driver_document_expiry_nullable;
DEALLOCATE PREPARE stmt_driver_document_expiry_nullable;
