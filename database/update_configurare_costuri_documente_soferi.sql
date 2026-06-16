CREATE TABLE IF NOT EXISTS configurare_costuri_documente_soferi (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    driver_id INT UNSIGNED NULL,
    document_type VARCHAR(100) NOT NULL,
    document_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    validity_days INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_config_doc_driver_driver (driver_id),
    INDEX idx_config_doc_driver_type (document_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_driver_id := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_costuri_documente_soferi'
      AND COLUMN_NAME = 'driver_id'
);
SET @sql := IF(
    @has_driver_id = 0,
    'ALTER TABLE configurare_costuri_documente_soferi ADD COLUMN driver_id INT UNSIGNED NULL AFTER id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_old_unique := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_costuri_documente_soferi'
      AND INDEX_NAME = 'uk_config_doc_driver_type'
);
SET @sql := IF(
    @has_old_unique > 0,
    'ALTER TABLE configurare_costuri_documente_soferi DROP INDEX uk_config_doc_driver_type',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

DROP TEMPORARY TABLE IF EXISTS tmp_config_doc_driver_rows;
CREATE TEMPORARY TABLE tmp_config_doc_driver_rows AS
SELECT
    d.driver_id,
    d.tip_document AS document_type,
    COALESCE(MAX(c.document_cost), 0.00) AS document_cost,
    COALESCE(MAX(c.validity_days), GREATEST(1, DATEDIFF(MAX(d.data_expirare), CURDATE())), 365) AS validity_days
FROM documente_soferi d
LEFT JOIN configurare_costuri_documente_soferi c
  ON c.driver_id IS NULL
 AND c.document_type = d.tip_document
GROUP BY d.driver_id, d.tip_document;

INSERT INTO configurare_costuri_documente_soferi
    (driver_id, document_type, document_cost, validity_days, created_at, updated_at)
SELECT
    tmp.driver_id,
    tmp.document_type,
    tmp.document_cost,
    tmp.validity_days,
    NOW(),
    NOW()
FROM tmp_config_doc_driver_rows tmp
LEFT JOIN configurare_costuri_documente_soferi existing
  ON existing.driver_id = tmp.driver_id
 AND existing.document_type = tmp.document_type
WHERE existing.id IS NULL;

DROP TEMPORARY TABLE IF EXISTS tmp_config_doc_driver_rows;

DELETE FROM configurare_costuri_documente_soferi WHERE driver_id IS NULL;

ALTER TABLE configurare_costuri_documente_soferi
    MODIFY driver_id INT UNSIGNED NOT NULL;

SET @has_driver_index := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_costuri_documente_soferi'
      AND INDEX_NAME = 'idx_config_doc_driver_driver'
);
SET @sql := IF(
    @has_driver_index = 0,
    'ALTER TABLE configurare_costuri_documente_soferi ADD INDEX idx_config_doc_driver_driver (driver_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_unique := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_costuri_documente_soferi'
      AND INDEX_NAME = 'uk_config_doc_driver_document'
);
SET @sql := IF(
    @has_unique = 0,
    'ALTER TABLE configurare_costuri_documente_soferi ADD UNIQUE KEY uk_config_doc_driver_document (driver_id, document_type)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_fk := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_costuri_documente_soferi'
      AND CONSTRAINT_NAME = 'fk_config_doc_driver'
);
SET @sql := IF(
    @has_fk = 0,
    'ALTER TABLE configurare_costuri_documente_soferi ADD CONSTRAINT fk_config_doc_driver FOREIGN KEY (driver_id) REFERENCES soferi(id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
