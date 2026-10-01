-- Pagina globala "Facturi": registrul facturilor de cheltuieli de cursa (orice tip),
-- cu asociere automata la cursa. Aditiv: nu modifica tabele existente.
--
-- Copie de referinta: schema se aplica si singura la runtime prin
-- InvoiceModel::ensureFacturiSchema().

CREATE TABLE IF NOT EXISTS facturi (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tip VARCHAR(30) NOT NULL,
    furnizor VARCHAR(255) NULL,
    numar_document VARCHAR(100) NULL,
    data_document DATE NULL,
    valoare_fara_tva DECIMAL(12,2) NULL,
    valoare_cu_tva DECIMAL(12,2) NULL,
    moneda CHAR(3) NOT NULL DEFAULT 'RON',
    nr_inmatriculare_extras VARCHAR(30) NULL,
    sofer_extras VARCHAR(150) NULL,
    vehicle_id INT UNSIGNED NULL,
    driver_id INT UNSIGNED NULL,
    cursa_id INT UNSIGNED NULL,
    curse_cheltuiala_id INT UNSIGNED NULL,
    status ENUM('in_procesare', 'asociata_auto', 'asociata_manual', 'de_verificat', 'neasociata', 'respinsa') NOT NULL DEFAULT 'in_procesare',
    match_candidates JSON NULL,
    match_reason VARCHAR(255) NULL,
    -- 1 = operatorul a dezasociat factura: reverificarea automata o ocoleste
    -- pana la "Reruleaza asocierea" sau o asociere manuala.
    fara_asociere_auto TINYINT(1) NOT NULL DEFAULT 0,
    sursa ENUM('scan', 'manual', 'legacy_cazare') NOT NULL,
    sursa_key VARCHAR(120) NOT NULL,
    legacy_cazare_id INT UNSIGNED NULL,
    document_path VARCHAR(255) NULL,
    document_original_name VARCHAR(255) NULL,
    document_mime VARCHAR(150) NULL,
    document_size INT UNSIGNED NULL,
    document_sha256 CHAR(64) NULL,
    ocr_raw JSON NULL,
    observatii TEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    UNIQUE KEY uk_facturi_sursa_key (sursa_key),
    UNIQUE KEY uk_facturi_legacy_cazare (legacy_cazare_id),
    KEY idx_facturi_status (status, deleted_at),
    KEY idx_facturi_tip (tip),
    KEY idx_facturi_data (data_document),
    KEY idx_facturi_cursa (cursa_id),
    KEY idx_facturi_vehicle (vehicle_id),
    KEY idx_facturi_driver (driver_id),
    KEY idx_facturi_cheltuiala (curse_cheltuiala_id),
    KEY idx_facturi_sha (document_sha256),
    KEY idx_facturi_created_by (created_by),
    CONSTRAINT fk_facturi_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicule(id) ON DELETE SET NULL,
    CONSTRAINT fk_facturi_driver FOREIGN KEY (driver_id) REFERENCES soferi(id) ON DELETE SET NULL,
    CONSTRAINT fk_facturi_cursa FOREIGN KEY (cursa_id) REFERENCES curse_dispecer(id) ON DELETE SET NULL,
    CONSTRAINT fk_facturi_cheltuiala FOREIGN KEY (curse_cheltuiala_id) REFERENCES curse_cheltuieli(id) ON DELETE SET NULL,
    CONSTRAINT fk_facturi_created_by FOREIGN KEY (created_by) REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Categorii noi de cheltuieli de cursa (tip_cheltuiala = 'alte' + categorie, ca la Cazare).
-- Reversibil: UPDATE categorii_cheltuieli_curse SET activ = 0 WHERE nume IN (...).
INSERT IGNORE INTO categorii_cheltuieli_curse (nume, descriere, activ, legacy_key, created_at, updated_at) VALUES
('Spalatorie', 'Spalare vehicul. Se introduce de regula din pagina Facturi.', 1, NULL, NOW(), NOW()),
('Vulcanizare', 'Vulcanizare / anvelope pe cursa. Se introduce de regula din pagina Facturi.', 1, NULL, NOW(), NOW());
