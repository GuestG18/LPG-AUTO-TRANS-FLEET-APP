-- Consumabile: compatibilitate pe numere de inmatriculare (id-uri vehicul, separate prin virgula); NULL = se aplica tipuri_vehicul.
ALTER TABLE consumabile_produse ADD COLUMN vehicule_ids TEXT NULL AFTER tipuri_vehicul;
