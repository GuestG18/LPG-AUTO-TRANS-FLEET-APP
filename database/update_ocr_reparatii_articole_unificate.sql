-- Restructurare v2: factura = parinte multi-vehicul, articole unificate.
-- Un articol = piesa SAU manopera, cu tip_lucrare, garantie (durata + data sfarsit
-- calculata din data de business, nu created_at), destinatie (vehicul / stoc) si
-- alocare per vehicul. Non-destructiv: tabelele vechi raman pe loc.

CREATE TABLE IF NOT EXISTS ocr_reparatii_articole (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reparatie_id INT UNSIGNED NOT NULL,
    tip ENUM('piesa','manopera') NOT NULL DEFAULT 'piesa',
    denumire VARCHAR(255) NOT NULL DEFAULT '',
    cod_piesa VARCHAR(80) NULL,
    cantitate DECIMAL(10,2) NOT NULL DEFAULT 1,
    pret_unitar DECIMAL(12,2) NOT NULL DEFAULT 0,
    tip_lucrare VARCHAR(30) NOT NULL DEFAULT 'reparatie',
    garantie_luni SMALLINT UNSIGNED NULL,
    -- Data de sfarsit se calculeaza din data montarii/receptiei (sau data facturii),
    -- NICIODATA din created_at. garantie_manuala=1 => operatorul a ales manual data.
    garantie_pana_la DATE NULL,
    garantie_manuala TINYINT(1) NOT NULL DEFAULT 0,
    destinatie ENUM('vehicul','stoc') NOT NULL DEFAULT 'vehicul',
    vehicle_id INT UNSIGNED NULL,
    data_referinta DATE NULL,
    km_bord INT UNSIGNED NULL,
    depozit VARCHAR(120) NULL,
    cant_alocata DECIMAL(10,2) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_ocr_art_reparatie (reparatie_id),
    INDEX idx_ocr_art_vehicle (vehicle_id),
    CONSTRAINT fk_ocr_art_reparatie2 FOREIGN KEY (reparatie_id)
        REFERENCES ocr_reparatii(id) ON DELETE CASCADE,
    CONSTRAINT fk_ocr_art_vehicle FOREIGN KEY (vehicle_id)
        REFERENCES vehicule(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Asocierea explicita factura <-> vehicule (N:N), pe langa cea derivata din articole,
-- ca "+ Adauga vehicul" sa persiste si inainte de a avea articole alocate.
CREATE TABLE IF NOT EXISTS ocr_reparatii_vehicule (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reparatie_id INT UNSIGNED NOT NULL,
    vehicle_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uk_ocr_rv (reparatie_id, vehicle_id),
    CONSTRAINT fk_ocr_rv_reparatie FOREIGN KEY (reparatie_id)
        REFERENCES ocr_reparatii(id) ON DELETE CASCADE,
    CONSTRAINT fk_ocr_rv_vehicle FOREIGN KEY (vehicle_id)
        REFERENCES vehicule(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
