-- Registrul de interventii/achizitii piese in stil Excel (o linie = o piesa /
-- lucrare, in formatul fisierelor "REPARATII+INLOCUIRI+IMBUNATATIRI" per camion).
-- Randurile pot veni din OCR (legate de o factura) sau adaugate manual, ca in Excel.

CREATE TABLE IF NOT EXISTS ocr_piese_registru (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT UNSIGNED NULL,
    data_interventie DATE NULL,
    reparatii TEXT NULL,
    inlocuiri TEXT NULL,
    imbunatatiri TEXT NULL,
    pret DECIMAL(12,2) NULL,
    furnizor VARCHAR(190) NULL,
    pret_manopera DECIMAL(12,2) NULL,
    furnizor_manopera VARCHAR(190) NULL,
    km_bord INT UNSIGNED NULL,
    factura_id INT UNSIGNED NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_ocr_reg_vehicle (vehicle_id),
    INDEX idx_ocr_reg_data (data_interventie),
    INDEX idx_ocr_reg_factura (factura_id),
    CONSTRAINT fk_ocr_reg_vehicle FOREIGN KEY (vehicle_id)
        REFERENCES vehicule(id) ON DELETE SET NULL,
    CONSTRAINT fk_ocr_reg_factura FOREIGN KEY (factura_id)
        REFERENCES ocr_piese_facturi(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
