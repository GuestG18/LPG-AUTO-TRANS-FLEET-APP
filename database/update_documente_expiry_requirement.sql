-- Migrare: permite documente fara data expirare si configureaza cerinta pe tip document
-- Data: 2026-06-04

SET NAMES utf8mb4;

SET @has_requires_expiry := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_costuri_documente_vehicule'
      AND COLUMN_NAME = 'requires_expiry'
);

SET @sql_requires_expiry := IF(
    @has_requires_expiry = 0,
    'ALTER TABLE configurare_costuri_documente_vehicule ADD COLUMN requires_expiry TINYINT(1) NOT NULL DEFAULT 1 AFTER validity_days',
    'SELECT 1'
);
PREPARE stmt_requires_expiry FROM @sql_requires_expiry;
EXECUTE stmt_requires_expiry;
DEALLOCATE PREPARE stmt_requires_expiry;

SET @document_expiry_nullable := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'documente'
      AND COLUMN_NAME = 'data_expirare'
      AND IS_NULLABLE = 'YES'
);

SET @sql_document_expiry_nullable := IF(
    @document_expiry_nullable = 0,
    'ALTER TABLE documente MODIFY COLUMN data_expirare DATE NULL',
    'SELECT 1'
);
PREPARE stmt_document_expiry_nullable FROM @sql_document_expiry_nullable;
EXECUTE stmt_document_expiry_nullable;
DEALLOCATE PREPARE stmt_document_expiry_nullable;
