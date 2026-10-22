-- Refacturare: tip nou "Toll" (taxa de drum cu locatie, bucati si pret unitar, ca Taxa acces / Port / Trecere).
-- "Reparatii" (service) si "Alte cheltuieli" (alte) nu se mai refactureaza: raman in ENUM
-- pentru cheltuielile de cursa si pentru refacturarile deja salvate.
-- Aplicatia face aceeasi modificare automat la prima deschidere a unei curse (DispecerCurseModel).
ALTER TABLE curse_cheltuieli
    MODIFY COLUMN tip_cheltuiala
        ENUM('motorina', 'taxa_acces', 'port', 'trece', 'taxe_drum', 'diurna', 'service', 'alte', 'toll') NOT NULL,
    MODIFY COLUMN refacturare_tip_cheltuiala
        ENUM('motorina', 'taxa_acces', 'port', 'trece', 'taxe_drum', 'diurna', 'service', 'alte', 'toll') NULL;

-- Categoria "Toll": o foloseste refacturarea si pagina Facturi (factura de toll citita prin OCR
-- devine cheltuiala Toll pe cursa, apoi se refactureaza).
INSERT INTO categorii_cheltuieli_curse (nume, descriere, activ, legacy_key, created_at, updated_at)
VALUES ('Toll', 'Categorie implicita pentru cheltuieli curse.', 1, 'toll', NOW(), NOW())
ON DUPLICATE KEY UPDATE nume = VALUES(nume), activ = 1, updated_at = VALUES(updated_at);
