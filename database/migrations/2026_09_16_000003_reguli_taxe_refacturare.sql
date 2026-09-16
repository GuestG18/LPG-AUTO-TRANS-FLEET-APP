-- Pagina "Reguli taxe refacturare": pe ce rute se cere taxa acces / port / trecere / taxe drum.
-- Tabelul este creat si la rulare de ReinvoiceFeeExpectationModel::ensureSchema(), care il
-- completeaza atunci o singura data cu tiparele gasite in istoricul curselor.
CREATE TABLE IF NOT EXISTS reguli_taxe_refacturare (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    zona_distributie_id INT UNSIGNED NOT NULL,
    loc_incarcare_id INT UNSIGNED NULL,
    tip_transport VARCHAR(30) NULL,
    tip_cheltuiala VARCHAR(30) NOT NULL,
    suma_uzuala DECIMAL(12,2) NULL,
    observatii VARCHAR(255) NULL,
    activ TINYINT(1) NOT NULL DEFAULT 1,
    sursa ENUM('manual', 'istoric') NOT NULL DEFAULT 'manual',
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_reguli_taxe_zona (zona_distributie_id),
    CONSTRAINT fk_reguli_taxe_zona FOREIGN KEY (zona_distributie_id) REFERENCES configurare_zone_distributie(id) ON DELETE CASCADE,
    CONSTRAINT fk_reguli_taxe_loc FOREIGN KEY (loc_incarcare_id) REFERENCES configurare_locuri_incarcare(id) ON DELETE CASCADE,
    CONSTRAINT fk_reguli_taxe_user FOREIGN KEY (created_by) REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
