DELETE duplicate_document
FROM documente_soferi duplicate_document
INNER JOIN documente_soferi kept_document
    ON kept_document.driver_id = duplicate_document.driver_id
    AND kept_document.tip_document = duplicate_document.tip_document
    AND kept_document.id < duplicate_document.id;

SET @index_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'documente_soferi'
      AND INDEX_NAME = 'uk_documente_soferi_driver_type'
);

SET @sql := IF(
    @index_exists = 0,
    'ALTER TABLE documente_soferi ADD UNIQUE KEY uk_documente_soferi_driver_type (driver_id, tip_document)',
    'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
