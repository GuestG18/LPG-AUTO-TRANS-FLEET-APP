-- "Taxe de refacturat lipsa" din panoul de aprobari: cursele pe care operatorul le-a
-- marcat "Nu se aplica" pentru un tip de taxa (taxa acces, port, trecere, taxe drum).
-- Tabelul este creat si la rulare de ReinvoiceFeeExpectationModel::ensureSchema().
CREATE TABLE IF NOT EXISTS curse_taxe_refacturare_ignorate (
    cursa_id INT UNSIGNED NOT NULL,
    tip_cheltuiala VARCHAR(30) NOT NULL,
    ignorata_de INT UNSIGNED NULL,
    ignorata_la DATETIME NOT NULL,
    PRIMARY KEY (cursa_id, tip_cheltuiala),
    CONSTRAINT fk_taxe_ignorate_cursa FOREIGN KEY (cursa_id) REFERENCES curse_dispecer(id) ON DELETE CASCADE,
    CONSTRAINT fk_taxe_ignorate_user FOREIGN KEY (ignorata_de) REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
