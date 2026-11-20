-- ANULARE pentru 2026_11_18_000001_fundatie_financiara.sql — NU se ruleaza automat.
--
-- Elimina DOAR obiectele adaugate de migrare. Datele istorice existente nu au fost modificate de
-- migrare (s-au scris doar coloanele noi), deci raman intacte. Se pierd: clasificarile, receptiile,
-- consumurile, dosarele de achizitie si proiectele introduse dupa migrare.
--
-- Inainte: backup (mysqldump) si oprirea aplicatiei. Codul nou citeste coloanele noi: dupa anulare,
-- reveniti si la commit-ul anterior al codului (altfel FinancialFoundationSchema le recreeaza).
-- Rulare: mysql -u... <baza> < 2026_11_18_000001_fundatie_financiara.rollback.sql

SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE mentenanta DROP FOREIGN KEY fk_mentenanta_proiect_inv;
ALTER TABLE mentenanta_interventii_programate DROP FOREIGN KEY fk_ment_prog_proiect_inv;
ALTER TABLE ocr_reparatii DROP FOREIGN KEY fk_ocr_rep_proiect_inv;
ALTER TABLE cheltuieli DROP FOREIGN KEY fk_cheltuieli_proiect_inv;
ALTER TABLE documente DROP FOREIGN KEY fk_documente_proiect_inv;

-- v3: categorii financiare ierarhice (coloanele de pe liniile / tipurile de cost dispar odata cu tabelele lor mai jos)
DROP TABLE IF EXISTS financiar_categorii;

DROP TABLE IF EXISTS mentenanta_piese_consumuri;
DROP TABLE IF EXISTS mentenanta_piese_receptii;
DROP TABLE IF EXISTS achizitii_vehicule_documente;
DROP TABLE IF EXISTS achizitii_vehicule_evenimente;
DROP TABLE IF EXISTS achizitii_vehicule_transport;
DROP TABLE IF EXISTS achizitii_vehicule_plati;
DROP TABLE IF EXISTS achizitii_vehicule_cheltuieli;
DROP TABLE IF EXISTS achizitii_categorii_cheltuieli; -- include coloanele v2 grup / grup_ordine
DROP TABLE IF EXISTS investitii_proiecte_stadii;
DROP TABLE IF EXISTS investitii_proiecte;
DROP TABLE IF EXISTS achizitii_vehicule;
DROP TABLE IF EXISTS financiar_audit;
DROP TABLE IF EXISTS financiar_migrari;

ALTER TABLE mentenanta
    DROP INDEX idx_mentenanta_natura, DROP INDEX idx_mentenanta_proiect, DROP INDEX idx_mentenanta_sursa,
    DROP COLUMN natura_cost, DROP COLUMN natura_cost_origine, DROP COLUMN natura_cost_de_revizuit,
    DROP COLUMN natura_cost_motiv_revizuire, DROP COLUMN tratament_contabil, DROP COLUMN proiect_investitie_id,
    DROP COLUMN sursa_tip, DROP COLUMN sursa_id;

ALTER TABLE mentenanta_interventii_programate
    DROP INDEX idx_ment_prog_proiect,
    DROP COLUMN natura_cost, DROP COLUMN tratament_contabil, DROP COLUMN proiect_investitie_id;

ALTER TABLE mentenanta_interventii_lucrari
    DROP INDEX idx_ment_lucr_proiect,
    DROP COLUMN natura_cost, DROP COLUMN proiect_investitie_id, DROP COLUMN sursa_piesa, DROP COLUMN stock_part_id,
    DROP COLUMN cantitate_piesa, DROP COLUMN transfer_usage_id, DROP COLUMN cost_piese_evaluat;

ALTER TABLE ocr_reparatii DROP INDEX idx_ocr_rep_proiect, DROP COLUMN proiect_investitie_id;
ALTER TABLE ocr_reparatii_articole
    DROP INDEX idx_ocr_art_proiect,
    DROP COLUMN natura_cost, DROP COLUMN proiect_investitie_id, DROP COLUMN mentenanta_receptie_id;

ALTER TABLE cheltuieli DROP INDEX idx_cheltuieli_proiect, DROP COLUMN proiect_investitie_id, DROP COLUMN natura_cost;
ALTER TABLE documente DROP INDEX idx_documente_proiect, DROP COLUMN proiect_investitie_id;

ALTER TABLE mentenanta_piese_utilizari
    DROP INDEX uq_ment_usage_idempotency, DROP INDEX idx_ment_usage_transfer,
    DROP COLUMN sursa_piesa, DROP COLUMN receptie_id, DROP COLUMN valoare_neverificata,
    DROP COLUMN transfer_din_utilizare_id, DROP COLUMN idempotency_key;

SET FOREIGN_KEY_CHECKS = 1;
