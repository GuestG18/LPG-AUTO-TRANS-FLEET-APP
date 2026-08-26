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

-- 7. Import cheltuielile legacy. Valoarea preluata este suma neta (fara TVA)
--    cand exista, altfel totalul; detaliile complete (net/TVA/total/metoda de
--    plata) sunt pastrate in observatii, iar randul original ramane in tabela
--    legacy ca arhiva.
INSERT INTO cheltuieli (categorie, tip_id, data_cheltuiala, furnizor, valoare, numar_document, observatii,
                        alocare_tip, distribuire, legacy_source, legacy_id, added_by, updated_by, created_at, updated_at)
SELECT 'administrativa', t.id, e.expense_date, e.supplier,
       CASE WHEN e.amount_net > 0 THEN e.amount_net ELSE e.amount_total END,
       e.invoice_number,
       TRIM(CONCAT_WS('\n',
           NULLIF(e.description, ''),
           NULLIF(e.notes, ''),
           CONCAT('[Migrat din Cheltuieli Birou: net ', e.amount_net, ' lei, TVA ', e.vat_amount,
                  ' lei, total ', e.amount_total, ' lei, plată ', e.payment_method, ']')
       )),
       'companie', 'egal', 'office', e.id, e.added_by, e.updated_by, e.created_at, e.updated_at
FROM office_expenses e
INNER JOIN cheltuieli_tipuri t ON t.legacy_source = 'office_cat' AND t.legacy_id = e.category_id
WHERE NOT EXISTS (SELECT 1 FROM cheltuieli n WHERE n.legacy_source = 'office' AND n.legacy_id = e.id);

INSERT INTO cheltuieli (categorie, tip_id, data_cheltuiala, furnizor, valoare, numar_document, observatii,
                        alocare_tip, distribuire, legacy_source, legacy_id, added_by, updated_by, created_at, updated_at)
SELECT 'administrativa', t.id, e.expense_date, e.supplier,
       CASE WHEN e.amount_net > 0 THEN e.amount_net ELSE e.amount_total END,
       e.invoice_number,
       TRIM(CONCAT_WS('\n',
           NULLIF(e.description, ''),
           NULLIF(e.notes, ''),
           CONCAT('[Migrat din Cheltuieli Administrative: net ', e.amount_net, ' lei, TVA ', e.vat_amount,
                  ' lei, total ', e.amount_total, ' lei, plată ', e.payment_method, ']')
       )),
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
