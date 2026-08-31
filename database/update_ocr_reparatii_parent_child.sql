-- Restructurare registru piese: model parinte/copil.
-- Un rand parinte = un eveniment de reparatie / o factura, cu piese[] si manopera[].
-- Non-destructiv: tabelele vechi (ocr_piese_registru, ocr_piese_articole) raman pe loc.

CREATE TABLE IF NOT EXISTS ocr_reparatii (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT UNSIGNED NULL,
    data_interventie DATE NULL,
    document VARCHAR(120) NULL,
    furnizor VARCHAR(190) NULL,
    tip_lucrare VARCHAR(30) NOT NULL DEFAULT 'reparatie',
    km_bord INT UNSIGNED NULL,
    observatii TEXT NULL,
    factura_id INT UNSIGNED NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_ocr_rep_vehicle (vehicle_id),
    INDEX idx_ocr_rep_data (data_interventie),
    CONSTRAINT fk_ocr_rep_vehicle FOREIGN KEY (vehicle_id)
        REFERENCES vehicule(id) ON DELETE SET NULL,
    CONSTRAINT fk_ocr_rep_factura FOREIGN KEY (factura_id)
        REFERENCES ocr_piese_facturi(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ocr_reparatii_piese (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reparatie_id INT UNSIGNED NOT NULL,
    denumire VARCHAR(255) NOT NULL DEFAULT '',
    cod_piesa VARCHAR(80) NULL,
    cantitate DECIMAL(10,2) NOT NULL DEFAULT 1,
    pret_unitar DECIMAL(12,2) NOT NULL DEFAULT 0,
    -- Garantia piesei (oferita de furnizor), in luni; NULL = "--".
    garantie_luni SMALLINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_ocr_rp_reparatie (reparatie_id),
    CONSTRAINT fk_ocr_rp_reparatie FOREIGN KEY (reparatie_id)
        REFERENCES ocr_reparatii(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ocr_reparatii_manopera (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reparatie_id INT UNSIGNED NOT NULL,
    denumire VARCHAR(255) NOT NULL DEFAULT '',
    norma_ore DECIMAL(8,2) NOT NULL DEFAULT 0,
    pret_ora DECIMAL(12,2) NOT NULL DEFAULT 0,
    -- Garantia manoperei (oferita de service), in luni; NULL = "--".
    garantie_luni SMALLINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_ocr_rm_reparatie (reparatie_id),
    CONSTRAINT fk_ocr_rm_reparatie FOREIGN KEY (reparatie_id)
        REFERENCES ocr_reparatii(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
