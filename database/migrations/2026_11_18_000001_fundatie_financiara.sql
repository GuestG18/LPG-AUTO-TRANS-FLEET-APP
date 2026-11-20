-- Fundatie financiara: natura costului, tratament contabil, receptii de stoc, consum din receptii,
-- dosar de achizitie vehicul, proiecte de investitii (adaptare GPL). Vezi IMPLEMENTARE_FINANCIARA_FLEET.md.
--
-- STRICT ADITIV: doar CREATE TABLE / ADD COLUMN / ADD INDEX / ADD CONSTRAINT si UPDATE-uri de
-- completare care scriu DOAR in coloanele noi. Nicio valoare istorica existenta nu se modifica.
--
-- Se aplica prin FinancialFoundationSchema::ensure() (din MaintenanceModel / OperationalCostModel)
-- sau explicit: php scripts/migrate_financial_foundation.php
-- Fiecare instructiune ruleaza o singura data:
--   - CREATE TABLE      -> doar daca tabela lipseste;
--   - ADD COLUMN        -> doar daca coloana lipseste (o coloana per instructiune);
--   - ADD INDEX / KEY   -> doar daca indexul lipseste;
--   - ADD CONSTRAINT    -> doar daca constrangerea lipseste;
--   - "-- @once:<cheie>" -> instructiunea urmatoare (UPDATE / INSERT de date) ruleaza o singura data,
--                           marcata in financiar_migrari.
-- Anulare: database/migrations/2026_11_18_000001_fundatie_financiara.rollback.sql

CREATE TABLE IF NOT EXISTS financiar_migrari (
    cheie VARCHAR(80) NOT NULL PRIMARY KEY,
    aplicat_la DATETIME NOT NULL,
    randuri INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Jurnal de audit pentru clasificari si date financiare (cine, cand, ce valoare veche / noua, de ce).
CREATE TABLE IF NOT EXISTS financiar_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entitate VARCHAR(40) NOT NULL,
    entitate_id BIGINT UNSIGNED NOT NULL,
    actiune VARCHAR(40) NOT NULL,
    camp VARCHAR(60) NULL,
    valoare_veche VARCHAR(255) NULL,
    valoare_noua VARCHAR(255) NULL,
    motiv VARCHAR(500) NULL,
    detalii JSON NULL,
    user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_fin_audit_entitate (entitate, entitate_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================================
-- FAZA 4 (antet, creat inaintea FK-urilor din faza 1): proiecte de investitii pe vehiculul fizic
-- =====================================================================================
CREATE TABLE IF NOT EXISTS investitii_proiecte (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT UNSIGNED NOT NULL,
    denumire VARCHAR(190) NOT NULL,
    categorie ENUM('adaptare_gpl','echipare_initiala','modernizare','extindere_capacitate','alte_investitii') NOT NULL,
    natura_cost ENUM('investitie','echipare_initiala') NOT NULL DEFAULT 'investitie',
    tratament_contabil ENUM('de_confirmat','capitalizabil','cheltuiala') NOT NULL DEFAULT 'de_confirmat',
    buget_planificat DECIMAL(14,2) NULL,
    moneda_buget CHAR(3) NOT NULL DEFAULT 'RON',
    data_start DATE NULL,
    status ENUM('planificat','in_executie','finalizat','anulat') NOT NULL DEFAULT 'planificat',
    responsabil_user_id INT UNSIGNED NULL,
    data_punere_in_functiune DATE NULL,
    durata_utila_luni SMALLINT UNSIGNED NULL,
    durata_utila_aprobata_de INT UNSIGNED NULL,
    durata_utila_aprobata_la DATETIME NULL,
    achizitie_id INT UNSIGNED NULL,
    observatii TEXT NULL,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_inv_proiect_vehicul (vehicle_id, status),
    INDEX idx_inv_proiect_achizitie (achizitie_id),
    CONSTRAINT fk_inv_proiect_vehicul FOREIGN KEY (vehicle_id) REFERENCES vehicule (id),
    CONSTRAINT fk_inv_proiect_responsabil FOREIGN KEY (responsabil_user_id) REFERENCES utilizatori (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pregatirea pentru GPL: stadii separate; finalizarea financiara NU inseamna aptitudine legala.
CREATE TABLE IF NOT EXISTS investitii_proiecte_stadii (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    proiect_id INT UNSIGNED NOT NULL,
    stadiu ENUM('lucrari_finalizate','echipamente_montate','inspectie_tehnica','documente_disponibile','aprobat_activitate','pus_in_functiune') NOT NULL,
    confirmat_la DATE NOT NULL,
    confirmat_de INT UNSIGNED NULL,
    document_id INT UNSIGNED NULL,
    observatii VARCHAR(500) NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_inv_stadiu (proiect_id, stadiu),
    CONSTRAINT fk_inv_stadiu_proiect FOREIGN KEY (proiect_id) REFERENCES investitii_proiecte (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================================
-- FAZA 1: natura costului (operational) + tratament contabil (contabilitate)
-- =====================================================================================
ALTER TABLE mentenanta ADD COLUMN natura_cost ENUM('reparatie','mentenanta','investitie','echipare_initiala') NULL AFTER record_type;
ALTER TABLE mentenanta ADD COLUMN natura_cost_origine ENUM('explicit','derivat','istoric') NULL AFTER natura_cost;
ALTER TABLE mentenanta ADD COLUMN natura_cost_de_revizuit TINYINT(1) NOT NULL DEFAULT 0 AFTER natura_cost_origine;
ALTER TABLE mentenanta ADD COLUMN natura_cost_motiv_revizuire VARCHAR(255) NULL AFTER natura_cost_de_revizuit;
ALTER TABLE mentenanta ADD COLUMN tratament_contabil ENUM('de_confirmat','capitalizabil','cheltuiala') NOT NULL DEFAULT 'de_confirmat' AFTER natura_cost_motiv_revizuire;
ALTER TABLE mentenanta ADD COLUMN proiect_investitie_id INT UNSIGNED NULL AFTER tratament_contabil;
ALTER TABLE mentenanta ADD COLUMN sursa_tip VARCHAR(30) NULL AFTER source_intervention_id;
ALTER TABLE mentenanta ADD COLUMN sursa_id BIGINT UNSIGNED NULL AFTER sursa_tip;
ALTER TABLE mentenanta ADD INDEX idx_mentenanta_natura (natura_cost, tratament_contabil);
ALTER TABLE mentenanta ADD INDEX idx_mentenanta_proiect (proiect_investitie_id);
ALTER TABLE mentenanta ADD INDEX idx_mentenanta_sursa (sursa_tip, sursa_id);
ALTER TABLE mentenanta ADD CONSTRAINT fk_mentenanta_proiect_inv FOREIGN KEY (proiect_investitie_id) REFERENCES investitii_proiecte (id) ON DELETE SET NULL;

ALTER TABLE mentenanta_interventii_programate ADD COLUMN natura_cost ENUM('reparatie','mentenanta','investitie','echipare_initiala') NULL AFTER tip_interventie;
ALTER TABLE mentenanta_interventii_programate ADD COLUMN tratament_contabil ENUM('de_confirmat','capitalizabil','cheltuiala') NULL AFTER natura_cost;
ALTER TABLE mentenanta_interventii_programate ADD COLUMN proiect_investitie_id INT UNSIGNED NULL AFTER tratament_contabil;
ALTER TABLE mentenanta_interventii_programate ADD INDEX idx_ment_prog_proiect (proiect_investitie_id);
ALTER TABLE mentenanta_interventii_programate ADD CONSTRAINT fk_ment_prog_proiect_inv FOREIGN KEY (proiect_investitie_id) REFERENCES investitii_proiecte (id) ON DELETE SET NULL;

-- Pe lucrare: NULL = mosteneste natura interventiei. Sursa piesei separa stocul de achizitia directa.
ALTER TABLE mentenanta_interventii_lucrari ADD COLUMN natura_cost ENUM('reparatie','mentenanta','investitie','echipare_initiala') NULL AFTER operatiune;
ALTER TABLE mentenanta_interventii_lucrari ADD COLUMN proiect_investitie_id INT UNSIGNED NULL AFTER natura_cost;
ALTER TABLE mentenanta_interventii_lucrari ADD COLUMN sursa_piesa ENUM('stoc','achizitie_directa','atelier_extern','transfer_vehicul') NULL AFTER piesa_demontata;
ALTER TABLE mentenanta_interventii_lucrari ADD COLUMN stock_part_id INT UNSIGNED NULL AFTER sursa_piesa;
ALTER TABLE mentenanta_interventii_lucrari ADD COLUMN cantitate_piesa DECIMAL(12,2) NULL AFTER stock_part_id;
ALTER TABLE mentenanta_interventii_lucrari ADD COLUMN transfer_usage_id BIGINT UNSIGNED NULL AFTER cantitate_piesa;
ALTER TABLE mentenanta_interventii_lucrari ADD COLUMN cost_piese_evaluat DECIMAL(12,2) NULL AFTER cost_piese;
ALTER TABLE mentenanta_interventii_lucrari ADD INDEX idx_ment_lucr_proiect (proiect_investitie_id);

ALTER TABLE ocr_reparatii ADD COLUMN proiect_investitie_id INT UNSIGNED NULL AFTER tip_lucrare;
ALTER TABLE ocr_reparatii ADD INDEX idx_ocr_rep_proiect (proiect_investitie_id);
ALTER TABLE ocr_reparatii ADD CONSTRAINT fk_ocr_rep_proiect_inv FOREIGN KEY (proiect_investitie_id) REFERENCES investitii_proiecte (id) ON DELETE SET NULL;
-- Pe articol: NULL = derivata din tip_lucrare (imbunatatire -> investitie). Facturile mixte raman mixte.
ALTER TABLE ocr_reparatii_articole ADD COLUMN natura_cost ENUM('reparatie','mentenanta','investitie','echipare_initiala') NULL AFTER tip_lucrare;
ALTER TABLE ocr_reparatii_articole ADD COLUMN proiect_investitie_id INT UNSIGNED NULL AFTER natura_cost;
ALTER TABLE ocr_reparatii_articole ADD COLUMN mentenanta_receptie_id BIGINT UNSIGNED NULL AFTER mentenanta_stoc_cant;
ALTER TABLE ocr_reparatii_articole ADD INDEX idx_ocr_art_proiect (proiect_investitie_id);

ALTER TABLE cheltuieli ADD COLUMN proiect_investitie_id INT UNSIGNED NULL AFTER alocare_tip;
ALTER TABLE cheltuieli ADD COLUMN natura_cost ENUM('reparatie','mentenanta','investitie','echipare_initiala') NULL AFTER proiect_investitie_id;
ALTER TABLE cheltuieli ADD INDEX idx_cheltuieli_proiect (proiect_investitie_id);
ALTER TABLE cheltuieli ADD CONSTRAINT fk_cheltuieli_proiect_inv FOREIGN KEY (proiect_investitie_id) REFERENCES investitii_proiecte (id) ON DELETE SET NULL;

ALTER TABLE documente ADD COLUMN proiect_investitie_id INT UNSIGNED NULL AFTER vehicle_id;
ALTER TABLE documente ADD INDEX idx_documente_proiect (proiect_investitie_id);
ALTER TABLE documente ADD CONSTRAINT fk_documente_proiect_inv FOREIGN KEY (proiect_investitie_id) REFERENCES investitii_proiecte (id) ON DELETE SET NULL;

-- Completare istoric (o singura data): natura derivata din record_type, marcata „istoric”.
-- Reparatia / intretinerea sunt tratate azi drept cheltuiala; doar coloanele noi se scriu.
-- @once:mentenanta_natura_istoric
UPDATE mentenanta
   SET natura_cost = IF(record_type = 'intretinere', 'mentenanta', 'reparatie'),
       natura_cost_origine = 'istoric',
       tratament_contabil = 'cheltuiala'
 WHERE natura_cost IS NULL;

-- Facturile OCR cu articole „imbunatatire” trimise ca reparatie inainte de clasificare: de revizuit,
-- tratament nedecis. NU se declara automat investitie / capitalizabil.
-- @once:mentenanta_ocr_imbunatatire_revizuire
UPDATE mentenanta m
  JOIN (SELECT DISTINCT mentenanta_id FROM ocr_reparatii_articole
         WHERE tip_lucrare = 'imbunatatire' AND mentenanta_id IS NOT NULL) a ON a.mentenanta_id = m.id
   SET m.natura_cost_de_revizuit = 1,
       m.natura_cost_motiv_revizuire = 'Factura OCR conține articole „îmbunătățire” trimise ca reparație înainte de clasificare',
       m.tratament_contabil = 'de_confirmat'
 WHERE m.natura_cost_origine = 'istoric';

-- Sursa randurilor existente, ca sa poata fi deduplicate (intervetie / OCR / anvelope).
-- @once:mentenanta_sursa_interventie
UPDATE mentenanta SET sursa_tip = 'interventie', sursa_id = source_intervention_id
 WHERE sursa_tip IS NULL AND source_intervention_id IS NOT NULL;
-- @once:mentenanta_sursa_ocr
UPDATE mentenanta m
  JOIN (SELECT mentenanta_id, MIN(reparatie_id) AS reparatie_id FROM ocr_reparatii_articole
         WHERE mentenanta_id IS NOT NULL GROUP BY mentenanta_id) a ON a.mentenanta_id = m.id
   SET m.sursa_tip = 'ocr_reparatie', m.sursa_id = a.reparatie_id
 WHERE m.sursa_tip IS NULL;
-- @once:mentenanta_sursa_anvelope
UPDATE mentenanta SET sursa_tip = 'anvelope' WHERE sursa_tip IS NULL AND tip_interventie LIKE 'Anvelopa - %';

-- =====================================================================================
-- FAZA 2: receptii de stoc (costul original per intrare) + consum din receptii
-- =====================================================================================
CREATE TABLE IF NOT EXISTS mentenanta_piese_receptii (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    part_id INT UNSIGNED NOT NULL,
    data_receptie DATE NOT NULL,
    cantitate_receptionata DECIMAL(12,2) NOT NULL,
    cantitate_ramasa DECIMAL(12,2) NOT NULL,
    cost_unitar DECIMAL(14,4) NULL COMMENT 'fara TVA, in moneda receptiei; NULL = necunoscut',
    cost_unitar_referinta DECIMAL(14,4) NULL COMMENT 'pretul de catalog la deschidere: NU dovedeste o achizitie',
    moneda CHAR(3) NOT NULL DEFAULT 'RON',
    curs_schimb DECIMAL(12,6) NULL,
    cota_tva DECIMAL(5,2) NULL,
    tva_unitar DECIMAL(14,4) NULL,
    furnizor VARCHAR(190) NULL,
    cui_furnizor VARCHAR(20) NULL,
    factura_numar VARCHAR(120) NULL,
    factura_data DATE NULL,
    ocr_factura_id INT UNSIGNED NULL,
    ocr_articol_id INT UNSIGNED NULL,
    sursa_tip ENUM('stoc_initial','receptie_manuala','ocr_factura','retur_montaj','ajustare') NOT NULL,
    sursa_id BIGINT UNSIGNED NULL,
    status_evaluare ENUM('confirmat_document','declarat_manual','neverificat_istoric') NOT NULL,
    idempotency_key VARCHAR(120) NULL,
    observatii VARCHAR(500) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_receptie_idempotency (idempotency_key),
    UNIQUE KEY uq_receptie_ocr_articol (ocr_articol_id),
    INDEX idx_receptie_fifo (part_id, data_receptie, id),
    CONSTRAINT fk_receptie_part FOREIGN KEY (part_id) REFERENCES mentenanta_piese (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE mentenanta_piese_utilizari ADD COLUMN sursa_piesa ENUM('stoc','achizitie_directa','atelier_extern','transfer_vehicul') NULL AFTER direct_mount;
ALTER TABLE mentenanta_piese_utilizari ADD COLUMN receptie_id BIGINT UNSIGNED NULL AFTER sursa_piesa;
ALTER TABLE mentenanta_piese_utilizari ADD COLUMN valoare_neverificata TINYINT(1) NOT NULL DEFAULT 0 AFTER receptie_id;
ALTER TABLE mentenanta_piese_utilizari ADD COLUMN transfer_din_utilizare_id BIGINT UNSIGNED NULL AFTER valoare_neverificata;
ALTER TABLE mentenanta_piese_utilizari ADD COLUMN idempotency_key VARCHAR(120) NULL AFTER transfer_din_utilizare_id;
ALTER TABLE mentenanta_piese_utilizari ADD UNIQUE KEY uq_ment_usage_idempotency (idempotency_key);
ALTER TABLE mentenanta_piese_utilizari ADD INDEX idx_ment_usage_transfer (transfer_din_utilizare_id);

CREATE TABLE IF NOT EXISTS mentenanta_piese_consumuri (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    usage_id BIGINT UNSIGNED NOT NULL,
    receptie_id BIGINT UNSIGNED NOT NULL,
    part_id INT UNSIGNED NOT NULL,
    cantitate DECIMAL(12,2) NOT NULL,
    cost_unitar DECIMAL(14,4) NULL,
    cantitate_returnata DECIMAL(12,2) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_consum_usage_receptie (usage_id, receptie_id),
    INDEX idx_consum_receptie (receptie_id),
    CONSTRAINT fk_consum_usage FOREIGN KEY (usage_id) REFERENCES mentenanta_piese_utilizari (id) ON DELETE CASCADE,
    CONSTRAINT fk_consum_receptie FOREIGN KEY (receptie_id) REFERENCES mentenanta_piese_receptii (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sursa montarilor existente, din campul direct_mount (fara a schimba direct_mount).
-- @once:utilizari_sursa_piesa
UPDATE mentenanta_piese_utilizari SET sursa_piesa = IF(direct_mount = 1, 'achizitie_directa', 'stoc') WHERE sursa_piesa IS NULL;

-- Stocul existent fara receptii: o receptie de deschidere cu evaluare NEVERIFICATA (cost necunoscut,
-- pretul de catalog doar ca referinta). Nu se inventeaza factura, furnizor sau pret.
-- @once:receptii_stoc_initial
INSERT INTO mentenanta_piese_receptii
    (part_id, data_receptie, cantitate_receptionata, cantitate_ramasa, cost_unitar, cost_unitar_referinta,
     moneda, sursa_tip, status_evaluare, observatii, created_at)
SELECT p.id, CURDATE(), p.stoc_curent, p.stoc_curent, NULL, NULLIF(p.pret_achizitie, 0),
       'RON', 'stoc_initial', 'neverificat_istoric',
       'Sold de deschidere la introducerea receptiilor; costul istoric nu este documentat', NOW()
  FROM mentenanta_piese p
 WHERE p.mod_utilizare = 'stoc' AND p.stoc_curent > 0
   AND NOT EXISTS (SELECT 1 FROM mentenanta_piese_receptii r WHERE r.part_id = p.id);

-- =====================================================================================
-- FAZA 3: dosar de achizitie vehicul (poate exista inainte de inmatriculare)
-- =====================================================================================
CREATE TABLE IF NOT EXISTS achizitii_vehicule (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    cod_dosar VARCHAR(40) NOT NULL,
    vehicle_id INT UNSIGNED NULL,
    vin VARCHAR(17) NULL,
    tip_vehicul VARCHAR(40) NOT NULL,
    marca VARCHAR(60) NULL,
    model VARCHAR(60) NULL,
    an_fabricatie SMALLINT UNSIGNED NULL,
    stare ENUM('nou','rulat') NOT NULL,
    vanzator_nume VARCHAR(190) NULL,
    vanzator_cod_fiscal VARCHAR(40) NULL,
    vanzator_furnizor_id INT UNSIGNED NULL,
    vanzator_tara CHAR(2) NULL,
    cumparator_entitate VARCHAR(190) NULL COMMENT 'NULL = compania proprie (nu se duplica datele firmei)',
    locatie_vehicul VARCHAR(255) NULL,
    tara_achizitie CHAR(2) NULL,
    tara_inmatriculare CHAR(2) NOT NULL DEFAULT 'RO',
    modalitate_achizitie ENUM('plata_integrala','plata_esalonata','leasing_financiar','leasing_operational','credit','altul') NOT NULL,
    leasing_contract_id INT UNSIGNED NULL,
    modalitate_livrare ENUM('condus','transportator') NULL,
    data_contract DATE NULL,
    data_factura DATE NULL,
    data_transfer_proprietate DATE NULL,
    pret_achizitie DECIMAL(14,2) NULL,
    moneda CHAR(3) NOT NULL DEFAULT 'RON',
    curs_schimb DECIMAL(12,6) NULL,
    curs_data DATE NULL,
    curs_sursa VARCHAR(40) NULL,
    regim_tva ENUM('normal','taxare_inversa','intracomunitar','import','regim_marja','scutit','de_confirmat') NOT NULL DEFAULT 'de_confirmat',
    status ENUM('in_analiza','contractat','in_plata','in_transport','receptionat','inmatriculat','in_adaptare','pus_in_functiune','anulat') NOT NULL DEFAULT 'in_analiza',
    utilizare_prevazuta VARCHAR(255) NULL,
    observatii TEXT NULL,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_achizitie_cod (cod_dosar),
    INDEX idx_achizitie_vehicul (vehicle_id),
    INDEX idx_achizitie_vin (vin),
    CONSTRAINT fk_achizitie_vehicul FOREIGN KEY (vehicle_id) REFERENCES vehicule (id) ON DELETE SET NULL,
    CONSTRAINT fk_achizitie_furnizor FOREIGN KEY (vanzator_furnizor_id) REFERENCES cheltuieli_furnizori (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE investitii_proiecte ADD CONSTRAINT fk_inv_proiect_achizitie FOREIGN KEY (achizitie_id) REFERENCES achizitii_vehicule (id) ON DELETE SET NULL;

-- Categoriile de cheltuieli configurabile (nu toate se aplica fiecarei achizitii).
CREATE TABLE IF NOT EXISTS achizitii_categorii_cheltuieli (
    cod VARCHAR(40) NOT NULL PRIMARY KEY,
    denumire VARCHAR(120) NOT NULL,
    clasificare_implicita ENUM('pret_achizitie','cost_direct_atribuibil','echipare_initiala','cheltuiala_perioada','tva') NOT NULL,
    context_livrare ENUM('oricare','condus','transportator') NOT NULL DEFAULT 'oricare',
    doar_import TINYINT(1) NOT NULL DEFAULT 0,
    activ TINYINT(1) NOT NULL DEFAULT 1,
    ordine SMALLINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @once:achizitii_categorii_seed
INSERT IGNORE INTO achizitii_categorii_cheltuieli (cod, denumire, clasificare_implicita, context_livrare, doar_import, ordine) VALUES
 ('pret_achizitie', 'Preț de achiziție', 'pret_achizitie', 'oricare', 0, 10),
 ('comision_achizitie', 'Comisioane aferente achiziției', 'cost_direct_atribuibil', 'oricare', 0, 20),
 ('transport_extern', 'Transport prin transportator', 'cost_direct_atribuibil', 'transportator', 0, 30),
 ('numere_provizorii', 'Înmatriculare temporară / export', 'cost_direct_atribuibil', 'oricare', 0, 40),
 ('asigurare_tranzit', 'Asigurare de tranzit', 'cost_direct_atribuibil', 'condus', 0, 50),
 ('combustibil_preluare', 'Combustibil pentru preluare', 'cost_direct_atribuibil', 'condus', 0, 60),
 ('taxe_drum', 'Taxe de drum', 'cost_direct_atribuibil', 'condus', 0, 70),
 ('deplasare_sofer', 'Deplasare șofer', 'cost_direct_atribuibil', 'condus', 0, 80),
 ('cazare_sofer', 'Cazare șofer', 'cost_direct_atribuibil', 'condus', 0, 90),
 ('taxe_vamale', 'Taxe vamale', 'cost_direct_atribuibil', 'oricare', 1, 100),
 ('tva_import', 'TVA de import', 'tva', 'oricare', 1, 110),
 ('rar_verificare', 'RAR / verificare tehnică', 'cost_direct_atribuibil', 'oricare', 0, 120),
 ('inmatriculare', 'Formalități de înmatriculare', 'cost_direct_atribuibil', 'oricare', 0, 130),
 ('pregatire_tehnica', 'Pregătire tehnică inițială', 'cost_direct_atribuibil', 'oricare', 0, 140),
 ('echipare_initiala', 'Echipare inițială', 'echipare_initiala', 'oricare', 0, 150),
 ('alte_directe', 'Alte cheltuieli direct atribuibile', 'cost_direct_atribuibil', 'oricare', 0, 160);

CREATE TABLE IF NOT EXISTS achizitii_vehicule_cheltuieli (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    achizitie_id INT UNSIGNED NOT NULL,
    categorie VARCHAR(40) NOT NULL,
    descriere VARCHAR(255) NULL,
    furnizor VARCHAR(190) NULL,
    furnizor_id INT UNSIGNED NULL,
    cui_furnizor VARCHAR(20) NULL,
    data_tranzactie DATE NULL,
    suma_neta DECIMAL(14,2) NOT NULL,
    moneda CHAR(3) NOT NULL DEFAULT 'RON',
    curs_schimb DECIMAL(12,6) NULL,
    cota_tva DECIMAL(5,2) NULL,
    tva DECIMAL(14,2) NULL,
    tva_deductibil ENUM('da','nu','partial','de_confirmat') NOT NULL DEFAULT 'de_confirmat',
    tva_recuperabil DECIMAL(14,2) NULL,
    document_tip VARCHAR(40) NULL,
    document_numar VARCHAR(120) NULL,
    document_fisier VARCHAR(255) NULL,
    cheltuiala_id INT UNSIGNED NULL COMMENT 'legatura cu pagina Cheltuieli (aceeasi factura, nu o a doua)',
    clasificare ENUM('pret_achizitie','cost_direct_atribuibil','echipare_initiala','cheltuiala_perioada','tva') NOT NULL,
    tratament_contabil ENUM('de_confirmat','capitalizabil','cheltuiala') NOT NULL DEFAULT 'de_confirmat',
    status_verificare ENUM('estimat','confirmat','verificat_contabil') NOT NULL DEFAULT 'estimat',
    inclus_in_linie_id BIGINT UNSIGNED NULL COMMENT 'deja inclus in alta linie (ex. factura transportatorului): nu se aduna',
    sursa_tip VARCHAR(30) NOT NULL DEFAULT 'manual',
    sursa_id BIGINT UNSIGNED NULL,
    observatii VARCHAR(500) NULL,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_ach_chelt_achizitie (achizitie_id, categorie),
    UNIQUE KEY uq_ach_chelt_cheltuiala (cheltuiala_id),
    CONSTRAINT fk_ach_chelt_achizitie FOREIGN KEY (achizitie_id) REFERENCES achizitii_vehicule (id),
    CONSTRAINT fk_ach_chelt_categorie FOREIGN KEY (categorie) REFERENCES achizitii_categorii_cheltuieli (cod) ON UPDATE CASCADE,
    CONSTRAINT fk_ach_chelt_cheltuiala FOREIGN KEY (cheltuiala_id) REFERENCES cheltuieli (id) ON DELETE SET NULL,
    CONSTRAINT fk_ach_chelt_inclus FOREIGN KEY (inclus_in_linie_id) REFERENCES achizitii_vehicule_cheltuieli (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS achizitii_vehicule_plati (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    achizitie_id INT UNSIGNED NOT NULL,
    cheltuiala_linie_id BIGINT UNSIGNED NULL,
    data_plata DATE NOT NULL,
    suma DECIMAL(14,2) NOT NULL,
    moneda CHAR(3) NOT NULL DEFAULT 'RON',
    curs_schimb DECIMAL(12,6) NULL,
    metoda VARCHAR(40) NULL,
    referinta VARCHAR(120) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_ach_plati_achizitie (achizitie_id),
    CONSTRAINT fk_ach_plati_achizitie FOREIGN KEY (achizitie_id) REFERENCES achizitii_vehicule (id),
    CONSTRAINT fk_ach_plati_linie FOREIGN KEY (cheltuiala_linie_id) REFERENCES achizitii_vehicule_cheltuieli (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS achizitii_vehicule_transport (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    achizitie_id INT UNSIGNED NOT NULL,
    modalitate ENUM('condus','transportator') NOT NULL,
    sofer_id INT UNSIGNED NULL,
    sofer_nume VARCHAR(190) NULL,
    transportator VARCHAR(190) NULL,
    transportator_furnizor_id INT UNSIGNED NULL,
    comanda_transport VARCHAR(120) NULL,
    loc_preluare VARCHAR(255) NULL,
    loc_destinatie VARCHAR(255) NULL,
    numere_provizorii VARCHAR(60) NULL,
    asigurare_tranzit_ref VARCHAR(120) NULL,
    servicii_incluse VARCHAR(500) NULL COMMENT 'coduri de categorie incluse in pretul transportatorului (CSV)',
    linie_cost_id BIGINT UNSIGNED NULL,
    data_plecare DATE NULL,
    data_sosire DATE NULL,
    livrare_confirmata_la DATE NULL,
    livrare_confirmata_de INT UNSIGNED NULL,
    observatii VARCHAR(500) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_ach_transport_achizitie (achizitie_id),
    CONSTRAINT fk_ach_transport_achizitie FOREIGN KEY (achizitie_id) REFERENCES achizitii_vehicule (id),
    CONSTRAINT fk_ach_transport_sofer FOREIGN KEY (sofer_id) REFERENCES soferi (id) ON DELETE SET NULL,
    CONSTRAINT fk_ach_transport_linie FOREIGN KEY (linie_cost_id) REFERENCES achizitii_vehicule_cheltuieli (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Evenimente separate: receptia NU este punere in functiune.
CREATE TABLE IF NOT EXISTS achizitii_vehicule_evenimente (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    achizitie_id INT UNSIGNED NOT NULL,
    tip ENUM('achizitie','transfer_proprietate','preluare','sosire_ro','receptie_tehnica','inmatriculare','adaptare_tehnica','punere_in_functiune') NOT NULL,
    data_eveniment DATE NOT NULL,
    document_ref VARCHAR(255) NULL,
    observatii VARCHAR(500) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_ach_eveniment (achizitie_id, tip),
    CONSTRAINT fk_ach_eveniment_achizitie FOREIGN KEY (achizitie_id) REFERENCES achizitii_vehicule (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Documentele dosarului (inainte de inmatriculare nu exista vehicle_id pentru `documente`).
CREATE TABLE IF NOT EXISTS achizitii_vehicule_documente (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    achizitie_id INT UNSIGNED NOT NULL,
    tip VARCHAR(60) NOT NULL,
    numar VARCHAR(120) NULL,
    fisier_original VARCHAR(255) NULL,
    fisier_stocat VARCHAR(255) NULL,
    document_vehicul_id INT UNSIGNED NULL COMMENT 'legatura cu `documente` dupa inmatriculare',
    cheltuiala_linie_id BIGINT UNSIGNED NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_ach_doc_achizitie (achizitie_id),
    CONSTRAINT fk_ach_doc_achizitie FOREIGN KEY (achizitie_id) REFERENCES achizitii_vehicule (id),
    CONSTRAINT fk_ach_doc_document FOREIGN KEY (document_vehicul_id) REFERENCES documente (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================================
-- v2 (pagina Istoric Financiar Vehicul): grupurile categoriilor de cheltuieli (lista din stanga).
-- =====================================================================================
ALTER TABLE achizitii_categorii_cheltuieli ADD COLUMN grup VARCHAR(60) NOT NULL DEFAULT 'Alte cheltuieli' AFTER denumire;
ALTER TABLE achizitii_categorii_cheltuieli ADD COLUMN grup_ordine SMALLINT UNSIGNED NOT NULL DEFAULT 90 AFTER grup;
-- @once:achizitii_categorii_grupuri
UPDATE achizitii_categorii_cheltuieli
   SET grup = CASE
           WHEN cod IN ('pret_achizitie','comision_achizitie') THEN 'Achiziție vehicul'
           WHEN cod IN ('transport_extern','asigurare_tranzit','combustibil_preluare','taxe_drum','deplasare_sofer','cazare_sofer') THEN 'Transport & aducere'
           WHEN cod IN ('numere_provizorii','taxe_vamale','tva_import','rar_verificare','inmatriculare') THEN 'Taxe & formalități'
           WHEN cod = 'pregatire_tehnica' THEN 'Pregătire inițială'
           WHEN cod = 'echipare_initiala' THEN 'Echipare & accesorii'
           ELSE 'Alte cheltuieli' END,
       grup_ordine = CASE
           WHEN cod IN ('pret_achizitie','comision_achizitie') THEN 10
           WHEN cod IN ('transport_extern','asigurare_tranzit','combustibil_preluare','taxe_drum','deplasare_sofer','cazare_sofer') THEN 20
           WHEN cod IN ('numere_provizorii','taxe_vamale','tva_import','rar_verificare','inmatriculare') THEN 30
           WHEN cod = 'pregatire_tehnica' THEN 40
           WHEN cod = 'echipare_initiala' THEN 50
           ELSE 90 END;

-- =====================================================================================
-- v3 (Istoric Financiar Vehicul → Costuri): categorii financiare IERARHICE.
-- Categoria parinte este doar organizatorica: totalul ei se calculeaza din tranzactiile
-- frunzelor descendente (FinancialCategoryTree), nu exista tranzactii pe parinte.
-- `achizitii_categorii_cheltuieli` (tipul tranzactiei, cu clasificarea implicita) ramane neschimbat;
-- fiecare tip primeste subcategoria implicita, iar fiecare linie de cost isi refera frunza.
-- =====================================================================================
CREATE TABLE IF NOT EXISTS financiar_categorii (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    parent_id INT UNSIGNED NULL,
    cod VARCHAR(60) NOT NULL,
    denumire VARCHAR(120) NOT NULL,
    descriere VARCHAR(255) NULL,
    ordine SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    activ TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_fin_categorii_cod (cod),
    INDEX idx_fin_categorii_parent (parent_id, ordine),
    CONSTRAINT fk_fin_categorii_parent FOREIGN KEY (parent_id) REFERENCES financiar_categorii (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Radacini: doar „Achizitie si punere in exploatare” este activa; celelalte sunt pregatite (inactive),
-- fara mutarea automata a vreunei inregistrari existente.
-- @once:fin_categorii_radacini
INSERT IGNORE INTO financiar_categorii (parent_id, cod, denumire, descriere, ordine, activ) VALUES
 (NULL, 'achizitie_exploatare', 'Achiziție și punere în exploatare', 'Costul inițial al vehiculului: preț, aducere, formalități, pregătire, echipare', 10, 1),
 (NULL, 'investitii_gpl', 'Investiții și adaptări GPL', 'Pregătit — proiectele rămân legate de vehiculul fizic și de sursele lor', 20, 0),
 (NULL, 'reparatii_mentenanta', 'Reparații și mentenanță', 'Pregătit — fără mutare automată', 30, 0),
 (NULL, 'taxe_obligatii', 'Taxe și obligații', 'Pregătit — fără mutare automată', 40, 0),
 (NULL, 'alte_cheltuieli', 'Alte cheltuieli', 'Pregătit — fără mutare automată', 90, 0);

-- @once:fin_categorii_achizitie_sub
INSERT IGNORE INTO financiar_categorii (parent_id, cod, denumire, descriere, ordine, activ)
SELECT p.id, s.cod, s.denumire, s.descriere, s.ordine, 1
  FROM financiar_categorii p
  JOIN (SELECT 'ach_pret_cumparare' AS cod, 'Preț cumpărare' AS denumire, 'Prețul vehiculului și comisioanele aferente achiziției' AS descriere, 10 AS ordine
        UNION ALL SELECT 'ach_transport_aducere', 'Transport și aducere', 'Transportator sau aducere cu șofer propriu', 20
        UNION ALL SELECT 'ach_taxe_formalitati', 'Taxe și formalități', 'Vamă, TVA import, RAR, înmatriculare, numere provizorii', 30
        UNION ALL SELECT 'ach_pregatire_initiala', 'Pregătire inițială', 'Pregătire tehnică înainte de exploatare', 40
        UNION ALL SELECT 'ach_echipare_accesorii', 'Echipare și accesorii', 'GPS, tahograf, accesorii montate inițial', 50
        UNION ALL SELECT 'ach_alte_directe', 'Alte costuri directe', 'Alte cheltuieli direct atribuibile achiziției', 90) s
 WHERE p.cod = 'achizitie_exploatare';

-- Tipul de tranzactie -> subcategoria implicita (pastreaza codurile existente).
ALTER TABLE achizitii_categorii_cheltuieli ADD COLUMN financiar_categorie_id INT UNSIGNED NULL AFTER grup_ordine;
-- @once:fin_categorii_mapare_tipuri
UPDATE achizitii_categorii_cheltuieli t
  JOIN financiar_categorii f ON f.cod = CASE
           WHEN t.cod IN ('pret_achizitie','comision_achizitie') THEN 'ach_pret_cumparare'
           WHEN t.cod IN ('transport_extern','asigurare_tranzit','combustibil_preluare','taxe_drum','deplasare_sofer','cazare_sofer') THEN 'ach_transport_aducere'
           WHEN t.cod IN ('numere_provizorii','taxe_vamale','tva_import','rar_verificare','inmatriculare') THEN 'ach_taxe_formalitati'
           WHEN t.cod = 'pregatire_tehnica' THEN 'ach_pregatire_initiala'
           WHEN t.cod = 'echipare_initiala' THEN 'ach_echipare_accesorii'
           ELSE 'ach_alte_directe' END
   SET t.financiar_categorie_id = f.id
 WHERE t.financiar_categorie_id IS NULL;
ALTER TABLE achizitii_categorii_cheltuieli ADD CONSTRAINT fk_ach_cat_fin_categorie FOREIGN KEY (financiar_categorie_id) REFERENCES financiar_categorii (id);

-- Fiecare linie de cost refera frunza ei (fara linii noi / duplicate: doar se completeaza coloana).
ALTER TABLE achizitii_vehicule_cheltuieli ADD COLUMN financiar_categorie_id INT UNSIGNED NULL AFTER categorie;
ALTER TABLE achizitii_vehicule_cheltuieli ADD INDEX idx_ach_chelt_fin_categorie (financiar_categorie_id);
-- @once:fin_categorii_mapare_linii
UPDATE achizitii_vehicule_cheltuieli l
  JOIN achizitii_categorii_cheltuieli t ON t.cod = l.categorie
   SET l.financiar_categorie_id = t.financiar_categorie_id
 WHERE l.financiar_categorie_id IS NULL;
ALTER TABLE achizitii_vehicule_cheltuieli ADD CONSTRAINT fk_ach_chelt_fin_categorie FOREIGN KEY (financiar_categorie_id) REFERENCES financiar_categorii (id);

-- Anulare (stornare) linie: ramane in istoric, iese din toate totalurile.
ALTER TABLE achizitii_vehicule_cheltuieli ADD COLUMN anulat_la DATETIME NULL;
ALTER TABLE achizitii_vehicule_cheltuieli ADD COLUMN anulat_de INT UNSIGNED NULL;
ALTER TABLE achizitii_vehicule_cheltuieli ADD COLUMN anulat_motiv VARCHAR(500) NULL;
