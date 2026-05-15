-- Seed demo: 20 curse pentru modulul Dispecer curse
-- Ruleaza dupa update_dispecer_curse_module.sql

SET NAMES utf8mb4;

-- Asigura configurari minime
INSERT INTO configurare_beneficiari_transport (
    nume,
    tip_marfa,
    pret_tarifare,
    suporta_primar,
    suporta_distributie,
    suporta_compresor,
    pret_km,
    pret_tona,
    pret_distributie_km,
    pret_distributie_tona,
    activ,
    created_at,
    updated_at
)
SELECT 'LPG AUTO', 'gpl_vrac', 5.50, 1, 1, 0, 5.50, 2.85, 0.00, 0.00, 1, NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM configurare_beneficiari_transport WHERE nume = 'LPG AUTO'
);

INSERT INTO configurare_beneficiari_transport (
    nume,
    tip_marfa,
    pret_tarifare,
    suporta_primar,
    suporta_distributie,
    suporta_compresor,
    pret_km,
    pret_tona,
    pret_distributie_km,
    pret_distributie_tona,
    activ,
    created_at,
    updated_at
)
SELECT 'Retail Client SRL', 'butelii', 5.20, 1, 1, 0, 5.20, 2.60, 0.00, 0.00, 1, NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM configurare_beneficiari_transport WHERE nume = 'Retail Client SRL'
);

INSERT INTO configurare_beneficiari_transport (
    nume,
    tip_marfa,
    pret_tarifare,
    suporta_primar,
    suporta_distributie,
    suporta_compresor,
    pret_km,
    pret_tona,
    pret_distributie_km,
    pret_distributie_tona,
    activ,
    created_at,
    updated_at
)
SELECT 'Distrib Logistic SA', 'carburant', 5.80, 1, 1, 0, 5.80, 3.20, 0.00, 0.00, 1, NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM configurare_beneficiari_transport WHERE nume = 'Distrib Logistic SA'
);

INSERT INTO configurare_locuri_incarcare (beneficiar_id, nume, activ, created_at, updated_at)
SELECT b.id, 'Depozit Central Bucuresti', 1, NOW(), NOW()
FROM configurare_beneficiari_transport b
WHERE b.nume = 'LPG AUTO'
  AND NOT EXISTS (
      SELECT 1 FROM configurare_locuri_incarcare li WHERE li.beneficiar_id = b.id AND li.nume = 'Depozit Central Bucuresti'
  );

INSERT INTO configurare_locuri_incarcare (beneficiar_id, nume, activ, created_at, updated_at)
SELECT b.id, 'Terminal Brasov', 1, NOW(), NOW()
FROM configurare_beneficiari_transport b
WHERE b.nume = 'Retail Client SRL'
  AND NOT EXISTS (
      SELECT 1 FROM configurare_locuri_incarcare li WHERE li.beneficiar_id = b.id AND li.nume = 'Terminal Brasov'
  );

INSERT INTO configurare_locuri_incarcare (beneficiar_id, nume, activ, created_at, updated_at)
SELECT b.id, 'Hub Cluj', 1, NOW(), NOW()
FROM configurare_beneficiari_transport b
WHERE b.nume = 'LPG AUTO'
  AND NOT EXISTS (
      SELECT 1 FROM configurare_locuri_incarcare li WHERE li.beneficiar_id = b.id AND li.nume = 'Hub Cluj'
  );

INSERT INTO configurare_zone_distributie (beneficiar_id, nume, tarif_distributie, cost_extra_km, activ, created_at, updated_at)
SELECT b.id, 'Bucuresti', 2.60, 0.00, 1, NOW(), NOW()
FROM configurare_beneficiari_transport b
WHERE b.nume = 'Retail Client SRL'
  AND NOT EXISTS (
      SELECT 1 FROM configurare_zone_distributie zd WHERE zd.beneficiar_id = b.id AND zd.nume = 'Bucuresti'
  );

INSERT INTO configurare_zone_distributie (beneficiar_id, nume, tarif_distributie, cost_extra_km, activ, created_at, updated_at)
SELECT b.id, 'Ilfov', 2.85, 0.00, 1, NOW(), NOW()
FROM configurare_beneficiari_transport b
WHERE b.nume = 'LPG AUTO'
  AND NOT EXISTS (
      SELECT 1 FROM configurare_zone_distributie zd WHERE zd.beneficiar_id = b.id AND zd.nume = 'Ilfov'
  );

INSERT INTO configurare_zone_distributie (beneficiar_id, nume, tarif_distributie, cost_extra_km, activ, created_at, updated_at)
SELECT b.id, 'Regional', 3.20, 0.00, 1, NOW(), NOW()
FROM configurare_beneficiari_transport b
WHERE b.nume = 'LPG AUTO'
  AND NOT EXISTS (
      SELECT 1 FROM configurare_zone_distributie zd WHERE zd.beneficiar_id = b.id AND zd.nume = 'Regional'
  );

UPDATE configurare_beneficiari_transport
SET
    pret_tarifare = CASE
        WHEN nume = 'LPG AUTO' THEN 5.50
        WHEN nume = 'Retail Client SRL' THEN 5.20
        WHEN nume = 'Distrib Logistic SA' THEN 5.80
        ELSE COALESCE(pret_tarifare, 5.00)
    END,
    suporta_primar = 1,
    suporta_distributie = 1,
    pret_km = CASE
        WHEN nume = 'LPG AUTO' THEN 5.50
        WHEN nume = 'Retail Client SRL' THEN 5.20
        WHEN nume = 'Distrib Logistic SA' THEN 5.80
        ELSE COALESCE(pret_km, 5.00)
    END,
    pret_tona = CASE
        WHEN nume = 'LPG AUTO' THEN 2.85
        WHEN nume = 'Retail Client SRL' THEN 2.60
        WHEN nume = 'Distrib Logistic SA' THEN 3.20
        ELSE COALESCE(pret_tona, 2.80)
    END,
    pret_distributie_km = COALESCE(pret_distributie_km, 0),
    pret_distributie_tona = COALESCE(pret_distributie_tona, 0),
    suporta_compresor = COALESCE(suporta_compresor, 0),
    pret_ora_aspirare = COALESCE(pret_ora_aspirare, 0),
    pret_km_dislocare = COALESCE(pret_km_dislocare, 0),
    pret_tona_livrata = COALESCE(pret_tona_livrata, 0)
WHERE nume IN ('LPG AUTO', 'Retail Client SRL', 'Distrib Logistic SA');

-- Selectie dinamica de vehicule disponibile (fara semiremorca)
SET @veh1 := (
    SELECT id
    FROM vehicule
    WHERE tip_vehicul <> 'semiremorca'
    ORDER BY id
    LIMIT 1
);

SET @veh2 := (
    SELECT id
    FROM vehicule
    WHERE tip_vehicul <> 'semiremorca'
    ORDER BY id
    LIMIT 1 OFFSET 1
);

SET @veh3 := (
    SELECT id
    FROM vehicule
    WHERE tip_vehicul <> 'semiremorca'
    ORDER BY id
    LIMIT 1 OFFSET 2
);

SET @veh2 := COALESCE(@veh2, @veh1);
SET @veh3 := COALESCE(@veh3, @veh1);

SET @benef1 := (SELECT id FROM configurare_beneficiari_transport ORDER BY id LIMIT 1);
SET @benef2 := (SELECT id FROM configurare_beneficiari_transport ORDER BY id LIMIT 1 OFFSET 1);
SET @benef3 := (SELECT id FROM configurare_beneficiari_transport ORDER BY id LIMIT 1 OFFSET 2);
SET @benef2 := COALESCE(@benef2, @benef1);
SET @benef3 := COALESCE(@benef3, @benef1);

-- Selectie dinamica configurari transport (scopate pe beneficiar)
SET @loc1 := (SELECT id FROM configurare_locuri_incarcare WHERE beneficiar_id = @benef1 ORDER BY id LIMIT 1);
SET @loc2 := (SELECT id FROM configurare_locuri_incarcare WHERE beneficiar_id = @benef2 ORDER BY id LIMIT 1);
SET @loc3 := (SELECT id FROM configurare_locuri_incarcare WHERE beneficiar_id = @benef3 ORDER BY id LIMIT 1);
SET @loc1 := COALESCE(@loc1, (SELECT id FROM configurare_locuri_incarcare ORDER BY id LIMIT 1));
SET @loc2 := COALESCE(@loc2, @loc1);
SET @loc3 := COALESCE(@loc3, @loc1);

SET @zona1 := (SELECT id FROM configurare_zone_distributie WHERE beneficiar_id = @benef2 ORDER BY id LIMIT 1);
SET @zona2 := (SELECT id FROM configurare_zone_distributie WHERE beneficiar_id = @benef3 ORDER BY id LIMIT 1);
SET @zona3 := (SELECT id FROM configurare_zone_distributie WHERE beneficiar_id = @benef1 ORDER BY id LIMIT 1);
SET @zona1 := COALESCE(@zona1, (SELECT id FROM configurare_zone_distributie ORDER BY id LIMIT 1));
SET @zona2 := COALESCE(@zona2, @zona1);
SET @zona3 := COALESCE(@zona3, @zona1);

SET @tarif1 := (SELECT tarif_distributie FROM configurare_zone_distributie WHERE id = @zona1);
SET @tarif2 := (SELECT tarif_distributie FROM configurare_zone_distributie WHERE id = @zona2);
SET @tarif3 := (SELECT tarif_distributie FROM configurare_zone_distributie WHERE id = @zona3);

SET @before_last_id := COALESCE((SELECT MAX(id) FROM curse_dispecer), 0);

-- 20 curse: 10 primar + 10 distributie
INSERT INTO curse_dispecer (
    vehicle_id,
    tip_transport,
    data_cursa,
    loc_incarcare_id,
    beneficiar_id,
    tip_marfa,
    cantitate_incarcata,
    nr_clienti,
    km_cursa,
    zona_distributie_id,
    status_facturare,
    pret_tarifare,
    total_facturare,
    observatii,
    created_at,
    updated_at
) VALUES
(@veh1, 'primar',      DATE_SUB(CURDATE(), INTERVAL 20 DAY), @loc1, @benef1, 'gpl_vrac', NULL, 1, 210, NULL, 'in_curs_facturare', 5.10, 210 * 5.10, 'Cursa primara demo #1', NOW(), NOW()),
(@veh2, 'primar',      DATE_SUB(CURDATE(), INTERVAL 19 DAY), @loc2, @benef2, 'butelii', NULL, 1, 235, NULL, 'facturat', 5.10, 235 * 5.10, 'Cursa primara demo #2', NOW(), NOW()),
(@veh3, 'primar',      DATE_SUB(CURDATE(), INTERVAL 18 DAY), @loc3, @benef3, 'carburant', NULL, 1, 260, NULL, 'nefacturat', 5.20, 260 * 5.20, 'Cursa primara demo #3', NOW(), NOW()),
(@veh1, 'primar',      DATE_SUB(CURDATE(), INTERVAL 17 DAY), @loc1, @benef1, 'gpl_vrac', NULL, 2, 280, NULL, 'in_curs_facturare', 5.30, 280 * 5.30, 'Cursa primara demo #4', NOW(), NOW()),
(@veh2, 'primar',      DATE_SUB(CURDATE(), INTERVAL 16 DAY), @loc2, @benef2, 'butelii', NULL, 2, 305, NULL, 'facturat', 5.20, 305 * 5.20, 'Cursa primara demo #5', NOW(), NOW()),
(@veh3, 'primar',      DATE_SUB(CURDATE(), INTERVAL 15 DAY), @loc3, @benef3, 'carburant', NULL, 2, 330, NULL, 'nefacturat', 5.25, 330 * 5.25, 'Cursa primara demo #6', NOW(), NOW()),
(@veh1, 'primar',      DATE_SUB(CURDATE(), INTERVAL 14 DAY), @loc1, @benef1, 'gpl_vrac', NULL, 1, 350, NULL, 'in_curs_facturare', 5.35, 350 * 5.35, 'Cursa primara demo #7', NOW(), NOW()),
(@veh2, 'primar',      DATE_SUB(CURDATE(), INTERVAL 13 DAY), @loc2, @benef2, 'butelii', NULL, 1, 370, NULL, 'facturat', 5.40, 370 * 5.40, 'Cursa primara demo #8', NOW(), NOW()),
(@veh3, 'primar',      DATE_SUB(CURDATE(), INTERVAL 12 DAY), @loc3, @benef3, 'carburant', NULL, 1, 395, NULL, 'nefacturat', 5.45, 395 * 5.45, 'Cursa primara demo #9', NOW(), NOW()),
(@veh1, 'primar',      DATE_SUB(CURDATE(), INTERVAL 11 DAY), @loc1, @benef1, 'gpl_vrac', NULL, 1, 420, NULL, 'in_curs_facturare', 5.50, 420 * 5.50, 'Cursa primara demo #10', NOW(), NOW()),

(@veh2, 'distributie', DATE_SUB(CURDATE(), INTERVAL 10 DAY), @loc2, @benef2, 'butelii',  8.20,  5, NULL, @zona1, 'in_curs_facturare', @tarif1,  8.20 * @tarif1, 'Cursa distributie demo #11', NOW(), NOW()),
(@veh3, 'distributie', DATE_SUB(CURDATE(), INTERVAL 9 DAY),  @loc3, @benef3, 'carburant',  9.10,  6, NULL, @zona2, 'facturat', @tarif2,  9.10 * @tarif2, 'Cursa distributie demo #12', NOW(), NOW()),
(@veh1, 'distributie', DATE_SUB(CURDATE(), INTERVAL 8 DAY),  @loc1, @benef1, 'gpl_vrac', 10.50,  7, NULL, @zona3, 'nefacturat', @tarif3, 10.50 * @tarif3, 'Cursa distributie demo #13', NOW(), NOW()),
(@veh2, 'distributie', DATE_SUB(CURDATE(), INTERVAL 7 DAY),  @loc2, @benef2, 'butelii', 11.40,  8, NULL, @zona1, 'in_curs_facturare', @tarif1, 11.40 * @tarif1, 'Cursa distributie demo #14', NOW(), NOW()),
(@veh3, 'distributie', DATE_SUB(CURDATE(), INTERVAL 6 DAY),  @loc3, @benef3, 'carburant', 12.30,  9, NULL, @zona2, 'facturat', @tarif2, 12.30 * @tarif2, 'Cursa distributie demo #15', NOW(), NOW()),
(@veh1, 'distributie', DATE_SUB(CURDATE(), INTERVAL 5 DAY),  @loc1, @benef1, 'gpl_vrac', 13.20, 10, NULL, @zona3, 'nefacturat', @tarif3, 13.20 * @tarif3, 'Cursa distributie demo #16', NOW(), NOW()),
(@veh2, 'distributie', DATE_SUB(CURDATE(), INTERVAL 4 DAY),  @loc2, @benef2, 'butelii', 14.00, 11, NULL, @zona1, 'in_curs_facturare', @tarif1, 14.00 * @tarif1, 'Cursa distributie demo #17', NOW(), NOW()),
(@veh3, 'distributie', DATE_SUB(CURDATE(), INTERVAL 3 DAY),  @loc3, @benef3, 'carburant', 15.30, 12, NULL, @zona2, 'facturat', @tarif2, 15.30 * @tarif2, 'Cursa distributie demo #18', NOW(), NOW()),
(@veh1, 'distributie', DATE_SUB(CURDATE(), INTERVAL 2 DAY),  @loc1, @benef1, 'gpl_vrac', 16.40, 13, NULL, @zona3, 'nefacturat', @tarif3, 16.40 * @tarif3, 'Cursa distributie demo #19', NOW(), NOW()),
(@veh2, 'distributie', DATE_SUB(CURDATE(), INTERVAL 1 DAY),  @loc2, @benef2, 'butelii', 17.10, 14, NULL, @zona1, 'in_curs_facturare', @tarif1, 17.10 * @tarif1, 'Cursa distributie demo #20', NOW(), NOW());

-- Cheltuieli demo pentru o parte din cursele nou inserate
SET @first_new_race_id := @before_last_id + 1;

INSERT INTO curse_cheltuieli (cursa_id, tip_cheltuiala, suma, data_cheltuiala, observatii, created_at, updated_at) VALUES
(@first_new_race_id + 0,  'motorina', 420.00, DATE_SUB(CURDATE(), INTERVAL 20 DAY), 'Motorina cursa #1', NOW(), NOW()),
(@first_new_race_id + 1,  'taxe_drum', 95.00, DATE_SUB(CURDATE(), INTERVAL 19 DAY), 'Taxe drum cursa #2', NOW(), NOW()),
(@first_new_race_id + 2,  'diurna', 80.00, DATE_SUB(CURDATE(), INTERVAL 18 DAY), 'Diurna cursa #3', NOW(), NOW()),
(@first_new_race_id + 4,  'service', 260.00, DATE_SUB(CURDATE(), INTERVAL 16 DAY), 'Service rapid cursa #5', NOW(), NOW()),
(@first_new_race_id + 6,  'alte', 60.00, DATE_SUB(CURDATE(), INTERVAL 14 DAY), 'Alte costuri cursa #7', NOW(), NOW()),
(@first_new_race_id + 10, 'motorina', 300.00, DATE_SUB(CURDATE(), INTERVAL 10 DAY), 'Motorina distributie #11', NOW(), NOW()),
(@first_new_race_id + 11, 'taxe_drum', 70.00, DATE_SUB(CURDATE(), INTERVAL 9 DAY), 'Taxe distributie #12', NOW(), NOW()),
(@first_new_race_id + 14, 'diurna', 110.00, DATE_SUB(CURDATE(), INTERVAL 6 DAY), 'Diurna distributie #15', NOW(), NOW()),
(@first_new_race_id + 17, 'service', 190.00, DATE_SUB(CURDATE(), INTERVAL 3 DAY), 'Service distributie #18', NOW(), NOW()),
(@first_new_race_id + 19, 'alte', 45.00, DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'Costuri diverse #20', NOW(), NOW());
