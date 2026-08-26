-- Migrare: pagina "Cost operațional / km"
-- Data: 2026-08-26
--
-- Creează infrastructura de CONFIGURARE a motorului de cost/km:
--   1. cost_operational_settings  — parametri globali (curs EUR/RON, multiplicator salarial etc.)
--   2. cost_operational_elemente  — registrul elementelor financiare (fix/variabil, sursă, alocare)
--
-- IMPORTANT: aceste tabele NU duplică date tranzacționale. Ele definesc DOAR
-- modul în care sursele existente (fuel_fillups, mentenanta, soferi.salariu,
-- configurare_costuri_documente_vehicule, inventar_dotari_*, office_expenses,
-- administrative_expenses, curse_cheltuieli, vehicle_authorizations) participă
-- la calculul lei/km. Valorile tranzacționale continuă să vină din sursele lor.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS cost_operational_settings (
    setting_key VARCHAR(80) NOT NULL PRIMARY KEY,
    setting_value VARCHAR(255) DEFAULT NULL,
    updated_by INT UNSIGNED DEFAULT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_cost_op_settings_updated_by (updated_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Parametri impliciți (aliniați cu modelul de referință + ANALIZA_SURSE_COST_KM.md).
-- eur_ron_rate: NU există sursă dinamică în aplicație (§L din analiză) => CONFIG explicit.
-- salariu_multiplicator: multiplicatorul de cost angajator (Excel 1,75) — fără sursă transacțională.
-- tva_carburant_fallback: cota TVA folosită DOAR dacă raw_payload.cota_tva lipsește (datele live au 21.00).
-- management_alocare: cum se împarte costul birou/administrativ pe vehicule (vehicule_active | km).
-- km_source: sursa numitorului de km (curse_reali = COALESCE(km_totali, km_cursa); curse_facturati = km_cursa).
INSERT INTO cost_operational_settings (setting_key, setting_value, updated_by, updated_at) VALUES
    ('eur_ron_rate', '5.00', NULL, NOW()),
    ('salariu_multiplicator', '1.75', NULL, NOW()),
    ('tva_carburant_fallback', '21.00', NULL, NOW()),
    ('management_alocare', 'vehicule_active', NULL, NOW()),
    ('diurna_tarif_zi', '', NULL, NOW()),
    ('km_source', 'curse_reali', NULL, NOW())
ON DUPLICATE KEY UPDATE setting_key = setting_key;

CREATE TABLE IF NOT EXISTS cost_operational_elemente (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cod VARCHAR(60) NOT NULL,
    nume VARCHAR(150) NOT NULL,
    tip ENUM('fix','variabil') NOT NULL,
    -- Clasa sursei (din analiză): auto = date reale citibile azi; derived = calculabil din istoric;
    -- config = tabelul există dar valoarea e de configurare; missing = fără sursă (necesită valoare manuală).
    clasa_sursa ENUM('auto','derived','config','missing') NOT NULL,
    -- Cheia resolver-ului din motor (FinancialSourceResolver). 'manual' = folosește valoare_config.
    sursa_referinta VARCHAR(60) NOT NULL DEFAULT 'manual',
    -- Filtru opțional pentru resolver (ex. tipul documentului în configurare_costuri_documente_vehicule).
    sursa_filtru VARCHAR(120) DEFAULT NULL,
    scop ENUM('company','vehicle_category','vehicle','driver','beneficiary') NOT NULL DEFAULT 'vehicle',
    periodicitate ENUM('lunar','anual','per_eveniment','per_km','per_100000km','per_zi') NOT NULL DEFAULT 'anual',
    alocare ENUM('direct','by_km','by_vehicle_count','by_category','by_driver','by_beneficiary') NOT NULL DEFAULT 'direct',
    -- Valoare manuală (pentru clasa 'missing'/'config' fără sursă): interpretată prin periodicitate.
    valoare_config DECIMAL(14,2) DEFAULT NULL,
    valoare_moneda ENUM('RON','EUR') NOT NULL DEFAULT 'RON',
    -- Ani de amortizare pentru valorile one-time (Excel: vehicul 6, metrologie 3, trusă ADR 3). NULL = fără amortizare.
    amortizare_ani DECIMAL(5,2) DEFAULT NULL,
    -- Regimul TVA al sursei (§K din analiză): net = se folosește ca atare; brut = se scoate TVA la normalizare.
    regim_tva ENUM('net','brut','necunoscut_net') NOT NULL DEFAULT 'net',
    -- Tipurile de vehicule cărora li se aplică (CSV din enum vehicule.tip_vehicul); NULL = toate vehiculele grele.
    tipuri_vehicul VARCHAR(255) DEFAULT NULL,
    activ TINYINT(1) NOT NULL DEFAULT 1,
    ordine INT UNSIGNED NOT NULL DEFAULT 0,
    observatii VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_cost_op_elemente_cod (cod),
    INDEX idx_cost_op_elemente_tip (tip),
    INDEX idx_cost_op_elemente_activ (activ)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Seed: elementele financiare mapate în ANALIZA_SURSE_COST_KM.md (§C, §D).
-- clasa_sursa reflectă EXACT clasificarea din raport. Elementele 'missing'
-- rămân active dar fără valoare => motorul le raportează ca LIPSĂ (nu 0).
-- ---------------------------------------------------------------------------
INSERT INTO cost_operational_elemente
    (cod, nume, tip, clasa_sursa, sursa_referinta, sursa_filtru, scop, periodicitate, alocare, valoare_config, valoare_moneda, amortizare_ani, regim_tva, tipuri_vehicul, activ, ordine, observatii, created_at, updated_at)
VALUES
    -- ------------------- COSTURI FIXE -------------------
    ('impozit_auto',      'Impozit auto',                    'fix', 'missing', 'manual',              NULL,             'vehicle', 'anual', 'direct', NULL, 'RON', NULL, 'net', NULL, 1, 10, 'Fără sursă în aplicație (§M.1). Se completează manual per an/vehicul.', NOW(), NOW()),
    ('iprochim',          'Iprochim',                        'fix', 'config',  'documente_vehicule',  'IPROCHIM',       'vehicle', 'anual', 'direct', NULL, 'RON', NULL, 'net', 'semiremorca_primar,semiremorca_distributie,camion', 1, 20, 'configurare_costuri_documente_vehicule (cost×365/validity_days).', NOW(), NOW()),
    ('copie_conforma',    'Copie conformă',                  'fix', 'config',  'documente_vehicule',  'Copie conforma', 'vehicle', 'anual', 'direct', NULL, 'RON', NULL, 'net', 'cap_tractor,camion', 1, 30, NULL, NOW(), NOW()),
    ('asigurare_risc',    'Asigurare risc',                  'fix', 'missing', 'manual',              NULL,             'vehicle', 'anual', 'direct', NULL, 'RON', NULL, 'net', NULL, 1, 40, 'Fără tip de document dedicat (§M.2).', NOW(), NOW()),
    ('itp',               'ITP',                             'fix', 'config',  'documente_vehicule',  'ITP',            'vehicle', 'anual', 'direct', NULL, 'RON', NULL, 'net', NULL, 1, 50, NULL, NOW(), NOW()),
    ('agreare',           'Agreare',                         'fix', 'missing', 'manual',              NULL,             'vehicle', 'anual', 'direct', NULL, 'RON', NULL, 'net', NULL, 1, 60, 'Componenta "agreare" din "ITP + agreare" nu are sursă (§M.3).', NOW(), NOW()),
    ('rovinieta',         'Rovinietă',                       'fix', 'config',  'documente_vehicule',  'Rovinieta',      'vehicle', 'anual', 'direct', NULL, 'RON', NULL, 'net', NULL, 1, 70, NULL, NOW(), NOW()),
    ('taxe_drum_autorizatii', 'Taxe drum (autorizații zone)','fix', 'auto',    'autorizatii_vehicule', NULL,            'vehicle', 'anual', 'direct', NULL, 'RON', NULL, 'net', NULL, 1, 80, 'vehicle_authorizations.cost normalizat pe fereastra autorizației.', NOW(), NOW()),
    ('casco',             'CASCO',                           'fix', 'config',  'documente_vehicule',  'CASCO',          'vehicle', 'anual', 'direct', NULL, 'RON', NULL, 'net', NULL, 1, 90, NULL, NOW(), NOW()),
    ('rca',               'RCA',                             'fix', 'config',  'documente_vehicule',  'RCA',            'vehicle', 'anual', 'direct', NULL, 'RON', NULL, 'net', NULL, 1, 100, NULL, NOW(), NOW()),
    ('amortizare',        'Amortizare vehicul',              'fix', 'missing', 'manual',              NULL,             'vehicle', 'anual', 'direct', NULL, 'EUR', 6.00, 'net', NULL, 1, 110, 'Nu există valoare de achiziție pe vehicule; leasing_contracts gol (§M.4). Model Excel: valoare EUR / 6 ani × curs.', NOW(), NOW()),
    ('telefon',           'Telefon',                         'fix', 'missing', 'manual',              NULL,             'vehicle', 'lunar', 'direct', NULL, 'RON', NULL, 'net', NULL, 1, 120, 'Excel: 35 lei/lună/vehicul. Fără sursă per vehicul (§M.5).', NOW(), NOW()),
    ('tped',              'TPED',                            'fix', 'missing', 'manual',              NULL,             'vehicle', 'anual', 'direct', NULL, 'RON', NULL, 'net', 'semiremorca_primar,semiremorca_distributie,camion', 1, 130, 'Zero apariții în aplicație (§M.6).', NOW(), NOW()),
    ('metrologie',        'Metrologie / BRML',               'fix', 'config',  'documente_vehicule',  'METROLOGIE',     'vehicle', 'anual', 'direct', NULL, 'RON', NULL, 'net', 'semiremorca_distributie,camion', 1, 140, 'Excel amortizează 3 ani în EUR; aici costul vine din config documente (validity_days).', NOW(), NOW()),
    ('salariu_soferi',    'Salarii șoferi',                  'fix', 'auto',    'salarii_soferi',      NULL,             'driver',  'lunar', 'by_driver', NULL, 'RON', NULL, 'net', NULL, 1, 150, 'soferi.salariu × multiplicator (setare salariu_multiplicator). Alocat vehiculului prin activitatea din curse.', NOW(), NOW()),
    ('gps',               'GPS (abonament)',                 'fix', 'missing', 'manual',              NULL,             'vehicle', 'anual', 'direct', NULL, 'RON', NULL, 'net', NULL, 1, 160, 'SAS e doar telemetrie, fără cost de abonament (§M.9).', NOW(), NOW()),
    ('dotari_vehicule',   'Dotări & echipamente (ADR, extinctoare, protecție)', 'fix', 'config', 'dotari_vehicule', NULL, 'vehicle', 'anual', 'direct', NULL, 'RON', NULL, 'net', NULL, 1, 170, 'inventar_dotari_vehicule / catalog: cost pe interval de inspecție.', NOW(), NOW()),
    ('tahograf',          'Tahograf (verificare)',           'fix', 'config',  'documente_vehicule',  'Tahograf',       'vehicle', 'anual', 'direct', NULL, 'RON', NULL, 'net', 'cap_tractor,camion', 1, 180, NULL, NOW(), NOW()),
    ('analize_soferi',    'Analize medicale + psihologice',  'fix', 'config',  'documente_soferi',    NULL,             'driver',  'anual', 'by_driver', NULL, 'RON', NULL, 'net', NULL, 1, 190, 'configurare_costuri_documente_soferi (azi 0 rânduri => LIPSĂ).', NOW(), NOW()),
    ('ssm_su',            'SSM / SU',                        'fix', 'missing', 'manual',              NULL,             'driver',  'anual', 'by_driver', NULL, 'RON', NULL, 'net', NULL, 1, 200, 'Fără sursă (§M.7).', NOW(), NOW()),
    ('management_office', 'Management / Office',             'fix', 'auto',    'management_office',   NULL,             'company', 'lunar', 'by_vehicle_count', NULL, 'RON', NULL, 'net', NULL, 1, 210, 'office_expenses + administrative_expenses + salarii birou (automat). Alocare firm-level pe vehicule active.', NOW(), NOW()),
    ('cursuri_soferi',    'Cursuri șoferi',                  'fix', 'missing', 'manual',              NULL,             'driver',  'anual', 'by_driver', NULL, 'RON', NULL, 'net', NULL, 1, 220, 'Doar categorie administrativă goală (§M.8).', NOW(), NOW()),
    -- ------------------- COSTURI VARIABILE -------------------
    ('carburant',         'Carburant (motorină)',            'variabil', 'auto',    'carburant',          NULL, 'vehicle', 'per_eveniment', 'direct', NULL, 'RON', NULL, 'brut', NULL, 1, 300, 'fuel_fillups (CardOil API), de-TVA cu cota reală din raw_payload (21% live).', NOW(), NOW()),
    ('adblue',            'AdBlue',                          'variabil', 'auto',    'adblue',             NULL, 'vehicle', 'per_eveniment', 'direct', NULL, 'RON', NULL, 'brut', NULL, 1, 310, 'fuel_fillups fuel_type=adblue.', NOW(), NOW()),
    ('revizii',           'Revizii (întreținere)',           'variabil', 'derived', 'mentenanta_intretinere', NULL, 'vehicle', 'per_eveniment', 'direct', NULL, 'RON', NULL, 'necunoscut_net', NULL, 1, 320, 'mentenanta record_type=intretinere, exclus "Anvelopa - %".', NOW(), NOW()),
    ('reparatii',         'Reparații',                       'variabil', 'derived', 'mentenanta_reparatii', NULL, 'vehicle', 'per_eveniment', 'direct', NULL, 'RON', NULL, 'necunoscut_net', NULL, 1, 330, 'mentenanta record_type=reparatie.', NOW(), NOW()),
    ('piese_ocr',         'Piese din facturi (registru OCR)','variabil', 'derived', 'ocr_piese',          NULL, 'vehicle', 'per_eveniment', 'direct', NULL, 'RON', NULL, 'necunoscut_net', NULL, 0, 340, 'DEZACTIVAT implicit: registru paralel cu mentenanța — risc dublă numărare (§D reparații). Activați doar dacă fluxul OCR nu se copiază în mentenanță.', NOW(), NOW()),
    ('anvelope',          'Anvelope',                        'variabil', 'derived', 'anvelope',           NULL, 'vehicle', 'per_eveniment', 'direct', NULL, 'RON', NULL, 'necunoscut_net', NULL, 1, 350, 'anvelope.purchase_price pe alocări (azi NULL pe toate => LIPSĂ).', NOW(), NOW()),
    ('diurna',            'Diurnă',                          'variabil', 'auto',    'diurna',             NULL, 'driver',  'per_eveniment', 'by_driver', NULL, 'RON', NULL, 'net', NULL, 1, 360, 'curse_cheltuieli tip_cheltuiala=diurna (sume realizate pe cursă).', NOW(), NOW()),
    ('taxe_drum_realizate', 'Taxe drum realizate (pe cursă)','variabil', 'auto',    'taxe_drum_curse',    NULL, 'vehicle', 'per_eveniment', 'direct', NULL, 'RON', NULL, 'net', NULL, 1, 370, 'curse_cheltuieli tip_cheltuiala=taxe_drum.', NOW(), NOW())
ON DUPLICATE KEY UPDATE cod = cod;
