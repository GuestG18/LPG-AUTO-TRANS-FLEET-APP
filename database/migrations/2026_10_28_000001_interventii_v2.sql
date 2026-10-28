-- Mentenanta -> Interventii V2 (flux hibrid dispecer / coordonator tehnic).
-- Strict aditiva: nu redenumeste si nu sterge nimic; ID-urile interventiilor raman.
-- Aceeasi schema se aplica si la runtime (InterventionWorkflowModel::ensureSchema),
-- cu verificare in information_schema, deci rularea manuala e optionala.

-- 1. Statusuri noi (cele vechi raman valabile: confirmata = Programata, in_lucru = In service).
ALTER TABLE mentenanta_interventii_programate
    MODIFY status_interventie ENUM('de_verificat','programata','confirmata','in_lucru','in_asteptare','finalizata','anulata')
        NOT NULL DEFAULT 'programata';

-- 2. Campuri noi pe interventie.
ALTER TABLE mentenanta_interventii_programate
    ADD COLUMN prioritate ENUM('scazuta','normala','ridicata','critica') NOT NULL DEFAULT 'normala' AFTER tip_interventie,
    ADD COLUMN ora_programata TIME NULL AFTER data_programata,
    ADD COLUMN cost_suplimentar DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER cost_estimat,
    ADD COLUMN responsabil_user_id INT UNSIGNED NULL AFTER driver_id,
    ADD COLUMN sesizare_initiala TEXT NULL AFTER descriere,
    ADD COLUMN sesizare_la DATETIME NULL AFTER sesizare_initiala,
    ADD COLUMN sesizare_de INT UNSIGNED NULL AFTER sesizare_la,
    ADD COLUMN diagnostic_tehnic TEXT NULL AFTER sesizare_de,
    ADD COLUMN diagnostic_la DATETIME NULL AFTER diagnostic_tehnic,
    ADD COLUMN diagnostic_de INT UNSIGNED NULL AFTER diagnostic_la,
    ADD COLUMN disponibilitate_vehicul ENUM('disponibil','indisponibil') NULL AFTER status_interventie,
    ADD COLUMN interventie_origine_id INT UNSIGNED NULL AFTER service_km_id,
    ADD COLUMN data_executie DATE NULL AFTER interventie_origine_id,
    ADD COLUMN finalizat_la DATETIME NULL AFTER data_executie,
    ADD COLUMN finalizat_de INT UNSIGNED NULL AFTER finalizat_la,
    ADD COLUMN updated_by INT UNSIGNED NULL AFTER created_by,
    ADD INDEX idx_ment_prog_responsabil (responsabil_user_id),
    ADD INDEX idx_ment_prog_origine (interventie_origine_id),
    ADD CONSTRAINT fk_ment_prog_responsabil FOREIGN KEY (responsabil_user_id) REFERENCES utilizatori (id) ON DELETE SET NULL;

-- 3. Lucrari pe componente (mai multe pe aceeasi interventie / componenta).
CREATE TABLE IF NOT EXISTS mentenanta_interventii_lucrari (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    intervention_id INT UNSIGNED NOT NULL,
    component_key VARCHAR(10) COLLATE utf8mb4_unicode_ci NULL,
    ramura_principala VARCHAR(20) NULL,
    denumire_libera VARCHAR(190) NULL,
    pozitie VARCHAR(80) NULL,
    stare_evaluare ENUM('suspectata','confirmata','exclusa') NOT NULL DEFAULT 'suspectata',
    operatiune ENUM('verificare','diagnosticare','reglare','reparatie','inlocuire') NOT NULL DEFAULT 'verificare',
    diagnostic TEXT NULL,
    progres ENUM('neinceputa','in_lucru','terminata') NOT NULL DEFAULT 'neinceputa',
    rezultat ENUM('executata','amanata','respinsa','nenecesara') NULL,
    piesa_montata VARCHAR(190) NULL,
    piesa_demontata VARCHAR(190) NULL,
    data_montare DATE NULL,
    km_montare INT UNSIGNED NULL,
    cost_piese DECIMAL(12,2) NOT NULL DEFAULT 0,
    cost_manopera DECIMAL(12,2) NOT NULL DEFAULT 0,
    garantie_luni SMALLINT UNSIGNED NULL,
    garantie_pana_la DATE NULL,
    observatii TEXT NULL,
    maintenance_id INT UNSIGNED NULL,
    usage_id BIGINT UNSIGNED NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_ment_lucr_interventie (intervention_id),
    INDEX idx_ment_lucr_component (component_key),
    UNIQUE KEY uq_ment_lucr_usage (usage_id),
    CONSTRAINT fk_ment_lucr_interventie FOREIGN KEY (intervention_id)
        REFERENCES mentenanta_interventii_programate (id) ON DELETE CASCADE,
    CONSTRAINT fk_ment_lucr_component FOREIGN KEY (component_key)
        REFERENCES mentenanta_auto_componente (component_key) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Istoricul interventiei (cronologic, cu autor). Nu se completeaza retroactiv.
CREATE TABLE IF NOT EXISTS mentenanta_interventii_istoric (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    intervention_id INT UNSIGNED NOT NULL,
    tip VARCHAR(40) NOT NULL,
    descriere VARCHAR(500) NOT NULL,
    detalii JSON NULL,
    user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_ment_ist_interventie (intervention_id, created_at),
    CONSTRAINT fk_ment_ist_interventie FOREIGN KEY (intervention_id)
        REFERENCES mentenanta_interventii_programate (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Documentele interventiei (devize, facturi, comenzi de lucru, poze, rapoarte).
CREATE TABLE IF NOT EXISTS mentenanta_interventii_documente (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    intervention_id INT UNSIGNED NOT NULL,
    tip ENUM('deviz','factura','comanda','foto','raport','altul') NOT NULL DEFAULT 'altul',
    fisier_original VARCHAR(255) NOT NULL,
    fisier_stocat VARCHAR(255) NOT NULL,
    marime INT UNSIGNED NOT NULL DEFAULT 0,
    uploaded_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_ment_doc_interventie (intervention_id),
    CONSTRAINT fk_ment_doc_interventie FOREIGN KEY (intervention_id)
        REFERENCES mentenanta_interventii_programate (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
