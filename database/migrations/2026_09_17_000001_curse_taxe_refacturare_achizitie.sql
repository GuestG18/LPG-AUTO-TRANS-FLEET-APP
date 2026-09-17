-- "Treceri refacturate - confirma achizitia" din panoul de aprobari, pentru refacturarile
-- de trecere (taxa acces, port, trecere, taxe drum) fara factura atasata:
--   necumparata  = "Nu" - ramane in lista, cu motivul notat;
--   fara_factura = "Da" -> "Nu e cazul" - cumparata, fara factura; iese din lista.
-- Randul se sterge la atasarea facturii.
-- Tabelul este creat si la rulare de ReinvoiceFeeExpectationModel::ensureSchema().
CREATE TABLE IF NOT EXISTS curse_taxe_refacturare_achizitie (
    cheltuiala_id INT UNSIGNED NOT NULL PRIMARY KEY,
    status ENUM('necumparata', 'fara_factura') NOT NULL,
    motiv VARCHAR(255) NULL,
    marcata_de INT UNSIGNED NULL,
    marcata_la DATETIME NOT NULL,
    CONSTRAINT fk_taxe_achizitie_cheltuiala FOREIGN KEY (cheltuiala_id) REFERENCES curse_cheltuieli(id) ON DELETE CASCADE,
    CONSTRAINT fk_taxe_achizitie_user FOREIGN KEY (marcata_de) REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
