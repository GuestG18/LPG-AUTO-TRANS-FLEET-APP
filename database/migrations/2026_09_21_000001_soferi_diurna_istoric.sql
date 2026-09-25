-- Diurna se stabileste per sofer, din Contabilitate Personal, nu global.
-- Unii soferi nu primesc diurna deloc, iar valoarea pe zi difera de la sofer
-- la sofer si se poate schimba in timp. Fiecare schimbare este un rand nou
-- (ca la salary_history): pentru o cursa se foloseste randul valabil la data
-- inceperii ei, deci modificarile noi nu rescriu lunile trecute.
--
-- Sofer fara niciun rand = "nesetat": diurnele se numara, dar nu au valoare in lei.

CREATE TABLE IF NOT EXISTS soferi_diurna_istoric (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    driver_id INT UNSIGNED NOT NULL,
    primeste_diurna TINYINT(1) NOT NULL DEFAULT 1,
    valoare_zi DECIMAL(10,2) NULL,
    data_aplicare DATE NOT NULL,
    observatii VARCHAR(255) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    KEY idx_soferi_diurna_driver (driver_id, data_aplicare),
    CONSTRAINT fk_soferi_diurna_driver FOREIGN KEY (driver_id) REFERENCES soferi(id) ON DELETE CASCADE,
    CONSTRAINT fk_soferi_diurna_user FOREIGN KEY (created_by) REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
