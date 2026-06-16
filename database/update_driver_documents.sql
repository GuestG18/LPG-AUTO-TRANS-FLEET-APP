CREATE TABLE IF NOT EXISTS documente_soferi (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    driver_id INT UNSIGNED NOT NULL,
    tip_document VARCHAR(100) NOT NULL,
    numar_document VARCHAR(100) NULL,
    data_expirare DATE NULL,
    fisier_original VARCHAR(255) NULL,
    fisier_stocat VARCHAR(255) NULL,
    observatii TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_documente_soferi_driver (driver_id),
    INDEX idx_documente_soferi_expirare (data_expirare),
    CONSTRAINT fk_documente_soferi_driver FOREIGN KEY (driver_id) REFERENCES soferi(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO documente_soferi (driver_id, tip_document, numar_document, data_expirare, observatii, created_at, updated_at)
SELECT 1, 'Carte identitate', 'CI-ION-2026', DATE_ADD(CURDATE(), INTERVAL 420 DAY), 'Document personal incarcat pentru evidenta interna', NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM documente_soferi WHERE driver_id = 1 AND tip_document = 'Carte identitate'
);

INSERT INTO documente_soferi (driver_id, tip_document, numar_document, data_expirare, observatii, created_at, updated_at)
SELECT 1, 'Atestat profesional', 'ATP-101', DATE_ADD(CURDATE(), INTERVAL 18 DAY), 'Necesita verificare pentru reinnoire', NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM documente_soferi WHERE driver_id = 1 AND tip_document = 'Atestat profesional'
);

INSERT INTO documente_soferi (driver_id, tip_document, numar_document, data_expirare, observatii, created_at, updated_at)
SELECT 2, 'Aviz medical', 'MED-2026-02', DATE_ADD(CURDATE(), INTERVAL 65 DAY), 'Valabil pentru cursele curente', NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM documente_soferi WHERE driver_id = 2 AND tip_document = 'Aviz medical'
);
