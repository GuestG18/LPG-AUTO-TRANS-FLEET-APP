-- Urmarirea activitatii operatorilor (tab-ul "Operatori" din panoul de aprobari).
-- Momentul in care o cursa devine completa (fara informatii lipsa) si cine a
-- inchis-o. Tabelul este creat si la rulare de OperatorActivityModel::ensureSchema().
CREATE TABLE IF NOT EXISTS curse_inchidere_operator (
    cursa_id INT UNSIGNED NOT NULL PRIMARY KEY,
    inchisa_la DATETIME NOT NULL,
    inchisa_de INT UNSIGNED NULL,
    detectata_la DATETIME NOT NULL,
    INDEX idx_inchidere_data (inchisa_la),
    INDEX idx_inchidere_user (inchisa_de),
    CONSTRAINT fk_inchidere_cursa FOREIGN KEY (cursa_id) REFERENCES curse_dispecer(id) ON DELETE CASCADE,
    CONSTRAINT fk_inchidere_user FOREIGN KEY (inchisa_de) REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
