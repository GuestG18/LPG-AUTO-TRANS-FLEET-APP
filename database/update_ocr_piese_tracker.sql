-- Tracker EXPERIMENTAL pentru receptia pieselor auto din facturi citite cu OCR.
-- Complet separat de stocul de productie (mentenanta_piese): nimic de aici nu
-- atinge stocul real. Pagina: ?page=ocr_piese (doar admin).

CREATE TABLE IF NOT EXISTS ocr_piese_facturi (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    numar_factura VARCHAR(80) NULL,
    data_facturii DATE NULL,
    furnizor VARCHAR(190) NULL,
    cui_furnizor VARCHAR(20) NULL,
    moneda VARCHAR(10) NOT NULL DEFAULT 'RON',
    total_factura DECIMAL(12,2) NULL,
    fisier_original VARCHAR(255) NULL,
    fisier_stocat VARCHAR(255) NULL,
    -- Textul OCR brut, pastrat ca sa putem compara ulterior ce a citit OCR-ul
    -- cu ce a confirmat omul (tuning parser).
    ocr_text MEDIUMTEXT NULL,
    ocr_durata_ms INT UNSIGNED NULL,
    observatii TEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_ocr_fact_data (data_facturii),
    INDEX idx_ocr_fact_furnizor (furnizor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ocr_piese_articole (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    factura_id INT UNSIGNED NOT NULL,
    denumire VARCHAR(255) NOT NULL,
    cod_piesa VARCHAR(80) NULL,
    categorie VARCHAR(100) NULL,
    unitate_masura VARCHAR(30) NOT NULL DEFAULT 'buc',
    cantitate DECIMAL(12,2) NOT NULL DEFAULT 1,
    pret_unitar DECIMAL(12,2) NOT NULL DEFAULT 0,
    valoare DECIMAL(12,2) NOT NULL DEFAULT 0,
    -- 1 = randul a venit din propunerea OCR, 0 = adaugat manual in formular.
    din_ocr TINYINT(1) NOT NULL DEFAULT 0,
    observatii TEXT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_ocr_art_factura (factura_id),
    INDEX idx_ocr_art_denumire (denumire),
    CONSTRAINT fk_ocr_art_factura FOREIGN KEY (factura_id)
        REFERENCES ocr_piese_facturi(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
