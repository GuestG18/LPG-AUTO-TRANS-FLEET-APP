CREATE TABLE IF NOT EXISTS configurare_documente_obligatorii_soferi (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_type VARCHAR(100) NOT NULL,
    requires_expiry TINYINT(1) NOT NULL DEFAULT 1,
    custom_fields_json LONGTEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_required_driver_document_type (document_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO configurare_documente_obligatorii_soferi
    (document_type, created_at, updated_at)
SELECT DISTINCT
    document_type,
    NOW(),
    NOW()
FROM configurare_costuri_documente_soferi
WHERE document_type IS NOT NULL
  AND TRIM(document_type) <> '';

INSERT IGNORE INTO configurare_documente_obligatorii_soferi
    (document_type, created_at, updated_at)
VALUES
    ('Carte identitate', NOW(), NOW()),
    ('Atestat profesional', NOW(), NOW()),
    ('Aviz medical', NOW(), NOW());
