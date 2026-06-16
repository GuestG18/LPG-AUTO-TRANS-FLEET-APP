-- Migrare: configurare costuri documente individuale pe vehicul
-- Data: 2026-05-27

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS configurare_costuri_documente_vehicule_override (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT UNSIGNED NOT NULL,
    document_type VARCHAR(120) NOT NULL,
    document_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    validity_days INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_config_doc_vehicle_override (vehicle_id, document_type),
    INDEX idx_config_doc_override_vehicle (vehicle_id),
    INDEX idx_config_doc_override_type (document_type),
    CONSTRAINT fk_config_doc_override_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicule(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

