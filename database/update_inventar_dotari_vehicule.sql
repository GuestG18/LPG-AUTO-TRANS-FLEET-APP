SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS inventar_dotari_catalog (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nume VARCHAR(150) NOT NULL,
    categorie VARCHAR(120) NOT NULL,
    equipment_type ENUM('mandatory', 'optional') NOT NULL DEFAULT 'mandatory',
    poza_original VARCHAR(255) NULL,
    poza_stocata VARCHAR(255) NULL,
    cost_implicit DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    necesita_data_fabricatie TINYINT(1) NOT NULL DEFAULT 0,
    necesita_inspectie TINYINT(1) NOT NULL DEFAULT 0,
    interval_implicit_inspectie_luni INT UNSIGNED NULL,
    necesita_data_expirarii TINYINT(1) NOT NULL DEFAULT 0,
    activ TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_inventar_catalog_nume (nume),
    INDEX idx_inventar_catalog_activ (activ),
    INDEX idx_inventar_catalog_categorie (categorie),
    INDEX idx_inventar_catalog_equipment_type (equipment_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventar_dotari_reguli (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_type ENUM('autovehicul', 'autoutilitara', 'camion', 'cap_tractor', 'semiremorca', 'semiremorca_primar', 'semiremorca_distributie') NOT NULL,
    catalog_id INT UNSIGNED NOT NULL,
    activ TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_inventar_reguli_type_catalog (vehicle_type, catalog_id),
    INDEX idx_inventar_reguli_type_active (vehicle_type, activ),
    INDEX idx_inventar_reguli_catalog (catalog_id),
    CONSTRAINT fk_inventar_reguli_catalog FOREIGN KEY (catalog_id) REFERENCES inventar_dotari_catalog(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventar_dotari_vehicule (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT UNSIGNED NOT NULL,
    catalog_id INT UNSIGNED NOT NULL,
    poza_original VARCHAR(255) NULL,
    poza_stocata VARCHAR(255) NULL,
    cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    data_achizitiei DATE NULL,
    data_fabricatiei DATE NULL,
    data_ultimei_inspectii DATE NULL,
    interval_inspectie_luni INT UNSIGNED NULL,
    data_urmatoarei_inspectii DATE NULL,
    data_expirarii DATE NULL,
    serie_cod_produs VARCHAR(120) NULL,
    observatii TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_inventar_dotari_vehicle (vehicle_id),
    INDEX idx_inventar_dotari_catalog (catalog_id),
    INDEX idx_inventar_dotari_expirare (data_expirarii),
    INDEX idx_inventar_dotari_inspectie (data_urmatoarei_inspectii),
    CONSTRAINT fk_inventar_dotari_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicule(id) ON DELETE CASCADE,
    CONSTRAINT fk_inventar_dotari_catalog FOREIGN KEY (catalog_id) REFERENCES inventar_dotari_catalog(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO inventar_dotari_catalog
    (nume, categorie, equipment_type, cost_implicit, necesita_data_fabricatie, necesita_inspectie, interval_implicit_inspectie_luni, necesita_data_expirarii, activ, created_at, updated_at)
VALUES
    ('Extinctor', 'Siguranță', 'mandatory', 120.00, 1, 1, 12, 1, 1, NOW(), NOW()),
    ('Trusă ADR', 'ADR', 'mandatory', 250.00, 0, 1, 12, 1, 1, NOW(), NOW()),
    ('Trusă Medicală', 'Siguranță', 'mandatory', 90.00, 0, 0, NULL, 1, 1, NOW(), NOW()),
    ('Apă Ochi', 'ADR', 'mandatory', 45.00, 0, 0, NULL, 1, 1, NOW(), NOW()),
    ('Mască', 'Protecție', 'mandatory', 80.00, 0, 0, NULL, 0, 1, NOW(), NOW()),
    ('Filtru Mască', 'Protecție', 'mandatory', 35.00, 0, 0, NULL, 1, 1, NOW(), NOW()),
    ('Baterii', 'Consumabile', 'mandatory', 20.00, 0, 0, NULL, 1, 1, NOW(), NOW()),
    ('Lanternă', 'Siguranță', 'mandatory', 65.00, 0, 1, 12, 0, 1, NOW(), NOW()),
    ('Vestă reflectorizantă', 'Siguranță', 'mandatory', 30.00, 0, 0, NULL, 0, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE
    categorie = VALUES(categorie),
    cost_implicit = VALUES(cost_implicit),
    necesita_data_fabricatie = VALUES(necesita_data_fabricatie),
    necesita_inspectie = VALUES(necesita_inspectie),
    interval_implicit_inspectie_luni = VALUES(interval_implicit_inspectie_luni),
    necesita_data_expirarii = VALUES(necesita_data_expirarii),
    activ = VALUES(activ),
    updated_at = NOW();

INSERT INTO inventar_dotari_reguli
    (vehicle_type, catalog_id, activ, created_at, updated_at)
SELECT required.vehicle_type, c.id, 1, NOW(), NOW()
FROM (
    SELECT 'cap_tractor' AS vehicle_type, 'Extinctor' AS nume UNION ALL
    SELECT 'cap_tractor', 'Trusă Medicală' UNION ALL
    SELECT 'cap_tractor', 'Trusă ADR' UNION ALL
    SELECT 'semiremorca_primar', 'Extinctor' UNION ALL
    SELECT 'semiremorca_primar', 'Trusă ADR' UNION ALL
    SELECT 'semiremorca_distributie', 'Extinctor' UNION ALL
    SELECT 'semiremorca_distributie', 'Trusă ADR' UNION ALL
    SELECT 'camion', 'Extinctor' UNION ALL
    SELECT 'camion', 'Trusă Medicală' UNION ALL
    SELECT 'camion', 'Trusă ADR' UNION ALL
    SELECT 'camion', 'Apă Ochi'
) required
INNER JOIN inventar_dotari_catalog c ON c.nume = required.nume
ON DUPLICATE KEY UPDATE
    activ = VALUES(activ),
    updated_at = NOW();
