-- Segmente de cursa: o cursa oprita si reluata ramane O SINGURA cursa (un singur
-- tarif, o singura inregistrare in centralizator), iar schimbarile de sofer/vehicul
-- din timpul ei se inregistreaza ca segmente.
--
-- Inlocuieste mecanismul vechi "Reia cursa (segment nou)", care crea o cursa noua
-- legata prin parent_cursa_id si dubla astfel valoarea facturata.

CREATE TABLE IF NOT EXISTS curse_segmente (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cursa_id INT UNSIGNED NOT NULL,
    ordine SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    vehicle_id INT UNSIGNED NULL,
    driver_id INT UNSIGNED NULL,
    data_inceput DATE NULL,
    ora_inceput TIME NULL,
    data_sfarsit DATE NULL,
    ora_sfarsit TIME NULL,
    km INT UNSIGNED NULL,
    observatii VARCHAR(255) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_segment_cursa_ordine (cursa_id, ordine),
    KEY idx_segment_vehicle (vehicle_id),
    KEY idx_segment_driver (driver_id),
    KEY idx_segment_interval (data_inceput, data_sfarsit),
    CONSTRAINT fk_segment_cursa FOREIGN KEY (cursa_id) REFERENCES curse_dispecer(id) ON DELETE CASCADE,
    CONSTRAINT fk_segment_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicule(id) ON DELETE SET NULL,
    CONSTRAINT fk_segment_driver FOREIGN KEY (driver_id) REFERENCES soferi(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cursele-copil create de mecanismul vechi raman in baza, dar nu mai sunt generate.
-- Conversia lor in segmente se face controlat, cu:
--   php scripts/convert_resume_children_to_segments.php --apply
