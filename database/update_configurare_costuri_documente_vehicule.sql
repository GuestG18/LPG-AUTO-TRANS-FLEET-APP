-- Migrare: configurare costuri documente pe tip de vehicul
-- Data: 2026-05-27

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS configurare_costuri_documente_vehicule (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_type ENUM('cap_tractor', 'semiremorca_distributie', 'semiremorca_primar', 'camion', 'autovehicul') NOT NULL,
    document_type VARCHAR(120) NOT NULL,
    document_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    validity_days INT UNSIGNED NOT NULL,
    requires_expiry TINYINT(1) NOT NULL DEFAULT 1,
    custom_fields_json LONGTEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_config_doc_vehicle_type_document (vehicle_type, document_type),
    INDEX idx_config_doc_vehicle_type (vehicle_type),
    INDEX idx_config_doc_document_type (document_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

SET @has_custom_fields_json := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'configurare_costuri_documente_vehicule'
      AND COLUMN_NAME = 'custom_fields_json'
);

SET @sql_custom_fields_json := IF(
    @has_custom_fields_json = 0,
    'ALTER TABLE configurare_costuri_documente_vehicule ADD COLUMN custom_fields_json LONGTEXT NULL AFTER requires_expiry',
    'SELECT 1'
);
PREPARE stmt_custom_fields_json FROM @sql_custom_fields_json;
EXECUTE stmt_custom_fields_json;
DEALLOCATE PREPARE stmt_custom_fields_json;

SET @now := NOW();

INSERT IGNORE INTO configurare_costuri_documente_vehicule
    (vehicle_type, document_type, document_cost, validity_days, created_at, updated_at)
VALUES
    ('cap_tractor', 'RCA', 0.00, 365, @now, @now),
    ('cap_tractor', 'ITP', 0.00, 365, @now, @now),
    ('cap_tractor', 'Rovinieta', 0.00, 365, @now, @now),

    ('semiremorca_primar', 'RCA', 0.00, 365, @now, @now),
    ('semiremorca_primar', 'ITP', 0.00, 365, @now, @now),
    ('semiremorca_primar', 'Rovinieta', 0.00, 365, @now, @now),
    ('semiremorca_primar', 'IPROCHIM', 0.00, 365, @now, @now),

    ('semiremorca_distributie', 'RCA', 0.00, 365, @now, @now),
    ('semiremorca_distributie', 'ITP', 0.00, 365, @now, @now),
    ('semiremorca_distributie', 'Rovinieta', 0.00, 365, @now, @now),
    ('semiremorca_distributie', 'IPROCHIM', 0.00, 365, @now, @now),

    ('camion', 'RCA', 0.00, 365, @now, @now),
    ('camion', 'ITP', 0.00, 365, @now, @now),
    ('camion', 'Rovinieta', 0.00, 365, @now, @now),
    ('camion', 'IPROCHIM', 0.00, 365, @now, @now),

    ('autovehicul', 'RCA', 0.00, 365, @now, @now),
    ('autovehicul', 'ITP', 0.00, 365, @now, @now),
    ('autovehicul', 'Rovinieta', 0.00, 365, @now, @now);
