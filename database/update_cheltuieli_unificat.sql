-- =============================================================================
-- Modul unificat "Cheltuieli" - inlocuieste Cheltuieli Birou + Cheltuieli
-- Administrative cu o singura pagina (categorii: administrativa / operationala,
-- alocare independenta pe vehicul / sofer / companie, beneficiar optional).
--
-- Scriptul este idempotent: poate fi rulat de mai multe ori fara duplicate.
-- Tabelele legacy (office_expenses*, administrative_expenses*) NU sunt sterse -
-- raman ca arhiva; datele lor sunt copiate in noua structura prin perechea
-- (legacy_source, legacy_id).
--
-- Nota: aplicatia creeaza/migreaza automat aceste tabele la prima accesare a
-- paginii Cheltuieli (ExpenseModel::ensureSchema). Scriptul exista pentru
-- rulare manuala pe VPS / medii unde se prefera migrarea explicita.
-- =============================================================================

-- 1. Tipuri de cheltuieli (nomenclator, per categorie)
CREATE TABLE IF NOT EXISTS cheltuieli_tipuri (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    categorie ENUM('administrativa', 'operationala') NOT NULL,
    nume VARCHAR(150) NOT NULL,
    slug VARCHAR(160) NOT NULL,
    status ENUM('activ', 'inactiv') NOT NULL DEFAULT 'activ',
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    legacy_source VARCHAR(40) NULL,
    legacy_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_cheltuieli_tipuri_slug (slug),
    UNIQUE KEY uk_cheltuieli_tipuri_legacy (legacy_source, legacy_id),
    INDEX idx_cheltuieli_tipuri_categorie (categorie, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Cheltuiala (documentul principal - o factura = o inregistrare)
CREATE TABLE IF NOT EXISTS cheltuieli (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    categorie ENUM('administrativa', 'operationala') NOT NULL,
    tip_id INT UNSIGNED NOT NULL,
    data_cheltuiala DATE NOT NULL,
    furnizor VARCHAR(190) NULL,
    valoare DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    numar_document VARCHAR(120) NULL,
    observatii TEXT NULL,
    beneficiar_id INT UNSIGNED NULL,
    alocare_tip ENUM('vehicul', 'sofer', 'companie', 'mixt') NOT NULL DEFAULT 'companie',
    distribuire ENUM('egal', 'manual') NOT NULL DEFAULT 'egal',
    legacy_source VARCHAR(40) NULL,
    legacy_id INT UNSIGNED NULL,
    added_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_cheltuieli_legacy (legacy_source, legacy_id),
    INDEX idx_cheltuieli_data (data_cheltuiala),
    INDEX idx_cheltuieli_categorie_data (categorie, data_cheltuiala),
    INDEX idx_cheltuieli_tip (tip_id),
    INDEX idx_cheltuieli_beneficiar (beneficiar_id),
    INDEX idx_cheltuieli_alocare (alocare_tip),
    CONSTRAINT fk_cheltuieli_tip FOREIGN KEY (tip_id) REFERENCES cheltuieli_tipuri(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cheltuieli_beneficiar FOREIGN KEY (beneficiar_id) REFERENCES configurare_beneficiari_transport(id) ON DELETE SET NULL,
    CONSTRAINT fk_cheltuieli_added_by FOREIGN KEY (added_by) REFERENCES utilizatori(id) ON DELETE SET NULL,
    CONSTRAINT fk_cheltuieli_updated_by FOREIGN KEY (updated_by) REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Alocarile cheltuielii (1..n randuri; suma lor = valoarea cheltuielii)
CREATE TABLE IF NOT EXISTS cheltuieli_alocari (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cheltuiala_id INT UNSIGNED NOT NULL,
    tip_alocare ENUM('vehicul', 'sofer', 'companie') NOT NULL,
    vehicul_id INT UNSIGNED NULL,
    sofer_id INT UNSIGNED NULL,
    eticheta VARCHAR(150) NULL,
    suma DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_cheltuieli_alocari_cheltuiala (cheltuiala_id),
    INDEX idx_cheltuieli_alocari_tip (tip_alocare),
    INDEX idx_cheltuieli_alocari_vehicul (vehicul_id),
    INDEX idx_cheltuieli_alocari_sofer (sofer_id),
    CONSTRAINT fk_cheltuieli_alocari_cheltuiala FOREIGN KEY (cheltuiala_id) REFERENCES cheltuieli(id) ON DELETE CASCADE,
    CONSTRAINT fk_cheltuieli_alocari_vehicul FOREIGN KEY (vehicul_id) REFERENCES vehicule(id) ON DELETE SET NULL,
    CONSTRAINT fk_cheltuieli_alocari_sofer FOREIGN KEY (sofer_id) REFERENCES soferi(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Documentele cheltuielii (fisierele raman in uploads/documente)
CREATE TABLE IF NOT EXISTS cheltuieli_documente (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cheltuiala_id INT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NULL,
    stored_name VARCHAR(255) NULL,
    uploaded_by INT UNSIGNED NULL,
    legacy_source VARCHAR(40) NULL,
    legacy_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_cheltuieli_documente_legacy (legacy_source, legacy_id),
    INDEX idx_cheltuieli_documente_cheltuiala (cheltuiala_id),
    CONSTRAINT fk_cheltuieli_documente_cheltuiala FOREIGN KEY (cheltuiala_id) REFERENCES cheltuieli(id) ON DELETE CASCADE,
    CONSTRAINT fk_cheltuieli_documente_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4b. Extinderea documentului: tip document, descriere, CUI, net/TVA (Total
--     ramane in coloana `valoare`), moneda, plata si sursa inregistrarii.
--     Idempotent prin information_schema; ruleaza INAINTE de importul legacy.
SET @doc_cols := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cheltuieli' AND COLUMN_NAME = 'sursa'
);
SET @ddl_doc := IF(@doc_cols = 0,
    'ALTER TABLE cheltuieli
        ADD COLUMN tip_document ENUM(''factura'', ''bon_fiscal'', ''chitanta'', ''alt_document'') NOT NULL DEFAULT ''factura'' AFTER tip_id,
        ADD COLUMN descriere VARCHAR(255) NULL AFTER furnizor,
        ADD COLUMN cui VARCHAR(20) NULL AFTER descriere,
        ADD COLUMN valoare_neta DECIMAL(12,2) NULL AFTER valoare,
        ADD COLUMN tva DECIMAL(12,2) NULL AFTER valoare_neta,
        ADD COLUMN moneda CHAR(3) NOT NULL DEFAULT ''RON'' AFTER tva,
        ADD COLUMN modalitate_plata ENUM(''cash'', ''card'', ''transfer_bancar'', ''alte'') NULL AFTER moneda,
        ADD COLUMN status_plata ENUM(''platita'', ''neplatita'', ''partial'') NULL AFTER modalitate_plata,
        ADD COLUMN data_platii DATE NULL AFTER status_plata,
        ADD COLUMN scadenta DATE NULL AFTER data_platii,
        ADD COLUMN sursa ENUM(''manual'', ''spv'', ''ocr'', ''import'') NOT NULL DEFAULT ''manual'' AFTER scadenta,
        ADD INDEX idx_cheltuieli_sursa (sursa),
        ADD INDEX idx_cheltuieli_tip_document (tip_document)',
    'SELECT 1'
);
PREPARE stmt_doc FROM @ddl_doc;
EXECUTE stmt_doc;
DEALLOCATE PREPARE stmt_doc;

-- 5. Seed tipuri operationale (nomenclator de pornire)
INSERT INTO cheltuieli_tipuri (categorie, nume, slug, status, sort_order, created_at, updated_at)
SELECT seed.categorie, seed.nume, seed.slug, 'activ', seed.sort_order, NOW(), NOW()
FROM (
    SELECT 'operationala' AS categorie, 'Motorină' AS nume, 'motorina' AS slug, 10 AS sort_order UNION ALL
    SELECT 'operationala', 'AdBlue', 'adblue', 20 UNION ALL
    SELECT 'operationala', 'Taxe drum', 'taxe-drum', 30 UNION ALL
    SELECT 'operationala', 'Diurnă', 'diurna', 40 UNION ALL
    SELECT 'operationala', 'Cazare', 'cazare', 50 UNION ALL
    SELECT 'operationala', 'Reparații', 'reparatii', 60 UNION ALL
    SELECT 'operationala', 'Piese auto', 'piese-auto', 70 UNION ALL
    SELECT 'operationala', 'Anvelope', 'anvelope-operational', 80 UNION ALL
    SELECT 'operationala', 'Asigurări', 'asigurari-operational', 90 UNION ALL
    SELECT 'operationala', 'Spălătorie', 'spalatorie', 100 UNION ALL
    SELECT 'operationala', 'Parcare', 'parcare', 110 UNION ALL
    SELECT 'operationala', 'Amenzi', 'amenzi', 120 UNION ALL
    SELECT 'operationala', 'Alte cheltuieli operaționale', 'alte-cheltuieli-operationale', 130
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM cheltuieli_tipuri t WHERE t.slug = seed.slug);

-- 6. Import tipuri administrative din nomenclatoarele legacy (daca exista).
--    Categoriile automate (Salarii birou) devin inactive: nu se mai adauga
--    manual, dar inregistrarile istorice isi pastreaza tipul.
INSERT INTO cheltuieli_tipuri (categorie, nume, slug, status, sort_order, legacy_source, legacy_id, created_at, updated_at)
SELECT 'administrativa', c.name, CONCAT('birou-', c.slug),
       CASE WHEN c.is_automatic = 1 THEN 'inactiv' ELSE c.status END,
       c.sort_order, 'office_cat', c.id, NOW(), NOW()
FROM office_expense_categories c
WHERE NOT EXISTS (
    SELECT 1 FROM cheltuieli_tipuri t WHERE t.legacy_source = 'office_cat' AND t.legacy_id = c.id
);

INSERT INTO cheltuieli_tipuri (categorie, nume, slug, status, sort_order, legacy_source, legacy_id, created_at, updated_at)
SELECT 'administrativa', c.name, CONCAT('admin-', c.slug), c.status, c.sort_order, 'admin_cat', c.id, NOW(), NOW()
FROM administrative_expense_categories c
WHERE NOT EXISTS (
    SELECT 1 FROM cheltuieli_tipuri t WHERE t.legacy_source = 'admin_cat' AND t.legacy_id = c.id
);

-- 7. Import cheltuielile legacy. `valoare` = totalul cu TVA (sau netul daca
--    totalul lipseste); net/TVA/metoda de plata pe coloanele dedicate;
--    descrierea legacy pe campul descriere. Randul original ramane arhivat.
INSERT INTO cheltuieli (categorie, tip_id, tip_document, data_cheltuiala, furnizor, descriere,
                        valoare, valoare_neta, tva, modalitate_plata, numar_document, observatii,
                        alocare_tip, distribuire, legacy_source, legacy_id, added_by, updated_by, created_at, updated_at)
SELECT 'administrativa', t.id, 'factura', e.expense_date, e.supplier, NULLIF(e.description, ''),
       CASE WHEN e.amount_total > 0 THEN e.amount_total ELSE e.amount_net END,
       NULLIF(e.amount_net, 0), NULLIF(e.vat_amount, 0), e.payment_method,
       e.invoice_number,
       TRIM(CONCAT_WS('\n', NULLIF(e.notes, ''), '[Migrat din Cheltuieli Birou]')),
       'companie', 'egal', 'office', e.id, e.added_by, e.updated_by, e.created_at, e.updated_at
FROM office_expenses e
INNER JOIN cheltuieli_tipuri t ON t.legacy_source = 'office_cat' AND t.legacy_id = e.category_id
WHERE NOT EXISTS (SELECT 1 FROM cheltuieli n WHERE n.legacy_source = 'office' AND n.legacy_id = e.id);

INSERT INTO cheltuieli (categorie, tip_id, tip_document, data_cheltuiala, furnizor, descriere,
                        valoare, valoare_neta, tva, modalitate_plata, numar_document, observatii,
                        alocare_tip, distribuire, legacy_source, legacy_id, added_by, updated_by, created_at, updated_at)
SELECT 'administrativa', t.id, 'factura', e.expense_date, e.supplier, NULLIF(e.description, ''),
       CASE WHEN e.amount_total > 0 THEN e.amount_total ELSE e.amount_net END,
       NULLIF(e.amount_net, 0), NULLIF(e.vat_amount, 0), e.payment_method,
       e.invoice_number,
       TRIM(CONCAT_WS('\n', NULLIF(e.notes, ''), '[Migrat din Cheltuieli Administrative]')),
       'companie', 'egal', 'administrative', e.id, e.added_by, e.updated_by, e.created_at, e.updated_at
FROM administrative_expenses e
INNER JOIN cheltuieli_tipuri t ON t.legacy_source = 'admin_cat' AND t.legacy_id = e.category_id
WHERE NOT EXISTS (SELECT 1 FROM cheltuieli n WHERE n.legacy_source = 'administrative' AND n.legacy_id = e.id);

-- 8. Alocarea implicita "Companie" pentru cheltuielile migrate (100% din valoare)
INSERT INTO cheltuieli_alocari (cheltuiala_id, tip_alocare, vehicul_id, sofer_id, eticheta, suma, created_at, updated_at)
SELECT n.id, 'companie', NULL, NULL, 'Companie', n.valoare, NOW(), NOW()
FROM cheltuieli n
WHERE n.legacy_source IN ('office', 'administrative')
  AND NOT EXISTS (SELECT 1 FROM cheltuieli_alocari a WHERE a.cheltuiala_id = n.id);

-- 9. Documentele legacy (fisierele fizice raman in uploads/documente)
INSERT INTO cheltuieli_documente (cheltuiala_id, original_name, stored_name, uploaded_by, legacy_source, legacy_id, created_at, updated_at)
SELECT n.id, d.original_name, d.stored_name, d.uploaded_by, 'office_doc', d.id, d.created_at, d.updated_at
FROM office_expense_documents d
INNER JOIN cheltuieli n ON n.legacy_source = 'office' AND n.legacy_id = d.expense_id
WHERE NOT EXISTS (SELECT 1 FROM cheltuieli_documente x WHERE x.legacy_source = 'office_doc' AND x.legacy_id = d.id);

INSERT INTO cheltuieli_documente (cheltuiala_id, original_name, stored_name, uploaded_by, legacy_source, legacy_id, created_at, updated_at)
SELECT n.id, d.original_name, d.stored_name, d.uploaded_by, 'admin_doc', d.id, d.created_at, d.updated_at
FROM administrative_expense_documents d
INNER JOIN cheltuieli n ON n.legacy_source = 'administrative' AND n.legacy_id = d.expense_id
WHERE NOT EXISTS (SELECT 1 FROM cheltuieli_documente x WHERE x.legacy_source = 'admin_doc' AND x.legacy_id = d.id);

-- 10. Drepturi de acces: drepturile de pe paginile legacy devin drepturi pe
--     pagina unificata "cheltuieli", apoi intrarile legacy sunt eliminate.
INSERT IGNORE INTO access_permissions (user_id, page_key, action_key, created_at)
SELECT user_id, 'cheltuieli', action_key, NOW()
FROM access_permissions
WHERE page_key IN ('cheltuieli_birou', 'cheltuieli_administrative');

DELETE FROM access_permissions WHERE page_key IN ('cheltuieli_birou', 'cheltuieli_administrative');

INSERT IGNORE INTO access_template_permissions (template_id, page_key, action_key)
SELECT template_id, 'cheltuieli', action_key
FROM access_template_permissions
WHERE page_key IN ('cheltuieli_birou', 'cheltuieli_administrative');

DELETE FROM access_template_permissions WHERE page_key IN ('cheltuieli_birou', 'cheltuieli_administrative');

-- 11. Sofer responsabil (informativ): cine a generat cheltuiala, fara sa
--     preia din valoare (alocarea banilor ramane pe vehicul/companie).
--     Adaugare idempotenta prin information_schema.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cheltuieli' AND COLUMN_NAME = 'sofer_responsabil_id'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE cheltuieli ADD COLUMN sofer_responsabil_id INT UNSIGNED NULL AFTER beneficiar_id, ADD INDEX idx_cheltuieli_sofer_resp (sofer_responsabil_id), ADD CONSTRAINT fk_cheltuieli_sofer_resp FOREIGN KEY (sofer_responsabil_id) REFERENCES soferi(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
