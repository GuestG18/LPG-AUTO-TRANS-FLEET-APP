-- Fleet Management MVP local reset SQL
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS curse_cheltuieli_documente;
DROP TABLE IF EXISTS curse_cheltuieli;
DROP TABLE IF EXISTS curse_dispecer;
DROP TABLE IF EXISTS concedii;
DROP TABLE IF EXISTS configurare_zone_distributie_vehicule;
DROP TABLE IF EXISTS configurare_zone_distributie;
DROP TABLE IF EXISTS configurare_beneficiari_transport;
DROP TABLE IF EXISTS configurare_locuri_incarcare_vehicule;
DROP TABLE IF EXISTS configurare_locuri_incarcare;
DROP TABLE IF EXISTS audit_log;
DROP TABLE IF EXISTS documente_soferi;
DROP TABLE IF EXISTS documente;
DROP TABLE IF EXISTS mentenanta;
DROP TABLE IF EXISTS alimentari;
DROP TABLE IF EXISTS vehicule_cuplaje;
DROP TABLE IF EXISTS soferi;
DROP TABLE IF EXISTS vehicule;
DROP TABLE IF EXISTS utilizatori;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE utilizatori (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nume VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    telefon VARCHAR(20) NULL,
    parola VARCHAR(255) NOT NULL,
    rol ENUM('admin', 'utilizator') NOT NULL DEFAULT 'utilizator',
    status ENUM('activ', 'inactiv') NOT NULL DEFAULT 'activ',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_utilizatori_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE vehicule (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nr_inmatriculare VARCHAR(20) NOT NULL UNIQUE,
    marca VARCHAR(50) NOT NULL,
    model VARCHAR(50) NOT NULL,
    tip_vehicul ENUM('autovehicul', 'camion', 'cap_tractor', 'semiremorca') NOT NULL DEFAULT 'autovehicul',
    an_fabricatie SMALLINT UNSIGNED NOT NULL,
    km_bord INT UNSIGNED NOT NULL DEFAULT 0,
    km_revizie INT UNSIGNED NOT NULL DEFAULT 0,
    serie_sasiu VARCHAR(17) NOT NULL,
    nr_fabricatie VARCHAR(100) NULL,
    capacitate_transport DECIMAL(10,2) NULL,
    formula_axelor VARCHAR(20) NULL,
    capacitate_rezervor DECIMAL(10,2) NULL,
    mma DECIMAL(10,2) NULL,
    organism_notificat VARCHAR(150) NULL,
    garaj VARCHAR(120) NULL,
    poza_original VARCHAR(255) NULL,
    poza_stocata VARCHAR(255) NULL,
    consum_mediu DECIMAL(5,2) NULL,
    status ENUM('activ', 'inactiv') NOT NULL DEFAULT 'activ',
    observatii TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_vehicule_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE vehicule_cuplaje (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tractor_id INT UNSIGNED NOT NULL,
    semiremorca_id INT UNSIGNED NOT NULL,
    activ TINYINT(1) NOT NULL DEFAULT 1,
    data_start DATETIME NOT NULL,
    data_end DATETIME NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_vehicule_cuplaje_tractor_activ (tractor_id, activ),
    INDEX idx_vehicule_cuplaje_semiremorca_activ (semiremorca_id, activ),
    INDEX idx_vehicule_cuplaje_start (data_start),
    CONSTRAINT fk_vehicule_cuplaje_tractor FOREIGN KEY (tractor_id) REFERENCES vehicule(id) ON DELETE CASCADE,
    CONSTRAINT fk_vehicule_cuplaje_semiremorca FOREIGN KEY (semiremorca_id) REFERENCES vehicule(id) ON DELETE CASCADE,
    CONSTRAINT fk_vehicule_cuplaje_created_by FOREIGN KEY (created_by) REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE soferi (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nume VARCHAR(100) NOT NULL,
    telefon VARCHAR(20) NOT NULL,
    vehicle_id INT UNSIGNED NULL,
    permis_expira_la DATE NOT NULL,
    status ENUM('activ', 'inactiv') NOT NULL DEFAULT 'activ',
    observatii TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_soferi_status (status),
    INDEX idx_soferi_vehicle (vehicle_id),
    CONSTRAINT fk_soferi_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicule(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE concedii (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    driver_id INT UNSIGNED NOT NULL,
    tip_concediu ENUM('odihna', 'personal', 'medical', 'fara_plata') NOT NULL,
    data_inceput DATE NOT NULL,
    data_sfarsit DATE NOT NULL,
    inlocuitor_id INT UNSIGNED NULL,
    note TEXT NULL,
    status ENUM('aprobat', 'respins', 'in_asteptare', 'in_asteptare_aprobare') NOT NULL DEFAULT 'in_asteptare',
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_concedii_driver (driver_id),
    INDEX idx_concedii_inlocuitor (inlocuitor_id),
    INDEX idx_concedii_status (status),
    INDEX idx_concedii_perioada (data_inceput, data_sfarsit),
    CONSTRAINT fk_concedii_driver FOREIGN KEY (driver_id) REFERENCES soferi(id) ON DELETE RESTRICT,
    CONSTRAINT fk_concedii_inlocuitor FOREIGN KEY (inlocuitor_id) REFERENCES soferi(id) ON DELETE SET NULL,
    CONSTRAINT fk_concedii_created_by FOREIGN KEY (created_by) REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE alimentari (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT UNSIGNED NOT NULL,
    driver_id INT UNSIGNED NULL,
    data_alimentare DATE NOT NULL,
    litri DECIMAL(8,2) NOT NULL,
    cost_total DECIMAL(10,2) NOT NULL,
    km_bord INT UNSIGNED NOT NULL,
    observatii TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_alimentari_vehicle (vehicle_id),
    INDEX idx_alimentari_driver (driver_id),
    INDEX idx_alimentari_data (data_alimentare),
    CONSTRAINT fk_alimentari_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicule(id) ON DELETE CASCADE,
    CONSTRAINT fk_alimentari_driver FOREIGN KEY (driver_id) REFERENCES soferi(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE mentenanta (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT UNSIGNED NOT NULL,
    tip_interventie VARCHAR(150) NOT NULL,
    data_interventie DATE NOT NULL,
    cost DECIMAL(10,2) NOT NULL,
    atelier VARCHAR(120) NULL,
    furnizor_piesa VARCHAR(120) NULL,
    fisier_original VARCHAR(255) NULL,
    fisier_stocat VARCHAR(255) NULL,
    observatii TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_mentenanta_vehicle (vehicle_id),
    INDEX idx_mentenanta_data (data_interventie),
    CONSTRAINT fk_mentenanta_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicule(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE documente (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT UNSIGNED NOT NULL,
    tip_document VARCHAR(100) NOT NULL,
    numar_document VARCHAR(100) NOT NULL,
    data_expirare DATE NOT NULL,
    fisier_original VARCHAR(255) NULL,
    fisier_stocat VARCHAR(255) NULL,
    observatii TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_documente_vehicle (vehicle_id),
    INDEX idx_documente_expirare (data_expirare),
    CONSTRAINT fk_documente_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicule(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE documente_soferi (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    driver_id INT UNSIGNED NOT NULL,
    tip_document VARCHAR(100) NOT NULL,
    numar_document VARCHAR(100) NULL,
    data_expirare DATE NOT NULL,
    fisier_original VARCHAR(255) NULL,
    fisier_stocat VARCHAR(255) NULL,
    observatii TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_documente_soferi_driver (driver_id),
    INDEX idx_documente_soferi_expirare (data_expirare),
    CONSTRAINT fk_documente_soferi_driver FOREIGN KEY (driver_id) REFERENCES soferi(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE configurare_locuri_incarcare (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    beneficiar_id INT UNSIGNED NOT NULL,
    nume VARCHAR(120) NOT NULL,
    tarif DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    activ TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_config_locuri_beneficiar_nume (beneficiar_id, nume),
    INDEX idx_config_locuri_beneficiar (beneficiar_id),
    INDEX idx_config_locuri_activ (activ)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE configurare_zone_distributie (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    beneficiar_id INT UNSIGNED NOT NULL,
    nume VARCHAR(120) NOT NULL,
    tarif_distributie DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    cost_extra_km DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    activ TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_config_zone_beneficiar_nume (beneficiar_id, nume),
    INDEX idx_config_zone_beneficiar (beneficiar_id),
    INDEX idx_config_zone_activ (activ)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE configurare_locuri_incarcare_vehicule (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    beneficiar_id INT UNSIGNED NULL,
    vehicle_id INT UNSIGNED NOT NULL,
    loc_incarcare_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_config_locuri_beneficiar_vehicle (beneficiar_id, vehicle_id),
    INDEX idx_config_locuri_vehicle_beneficiar (beneficiar_id),
    INDEX idx_config_locuri_vehicle_vehicle (vehicle_id),
    INDEX idx_config_locuri_vehicle_loc (loc_incarcare_id),
    CONSTRAINT fk_config_locuri_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicule(id) ON DELETE CASCADE,
    CONSTRAINT fk_config_locuri_loc FOREIGN KEY (loc_incarcare_id) REFERENCES configurare_locuri_incarcare(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE configurare_zone_distributie_vehicule (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    beneficiar_id INT UNSIGNED NULL,
    vehicle_id INT UNSIGNED NOT NULL,
    zona_distributie_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_config_zone_beneficiar_vehicle (beneficiar_id, vehicle_id),
    INDEX idx_config_zone_vehicle_beneficiar (beneficiar_id),
    INDEX idx_config_zone_vehicle_vehicle (vehicle_id),
    INDEX idx_config_zone_vehicle_zona (zona_distributie_id),
    CONSTRAINT fk_config_zone_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicule(id) ON DELETE CASCADE,
    CONSTRAINT fk_config_zone_zona FOREIGN KEY (zona_distributie_id) REFERENCES configurare_zone_distributie(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE configurare_beneficiari_transport (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nume VARCHAR(150) NOT NULL,
    tip_marfa VARCHAR(50) NULL,
    pret_tarifare DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    suporta_primar TINYINT(1) NOT NULL DEFAULT 1,
    suporta_distributie TINYINT(1) NOT NULL DEFAULT 1,
    suporta_compresor TINYINT(1) NOT NULL DEFAULT 0,
    pret_km DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    pret_tona DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    pret_distributie_km DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    pret_distributie_tona DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    pret_ora_aspirare DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    pret_km_dislocare DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    pret_tona_livrata DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    activ TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_config_beneficiari_nume (nume),
    INDEX idx_config_beneficiari_activ (activ)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE configurare_locuri_incarcare
    ADD CONSTRAINT fk_config_locuri_beneficiar FOREIGN KEY (beneficiar_id) REFERENCES configurare_beneficiari_transport(id) ON DELETE CASCADE;

ALTER TABLE configurare_zone_distributie
    ADD CONSTRAINT fk_config_zone_beneficiar FOREIGN KEY (beneficiar_id) REFERENCES configurare_beneficiari_transport(id) ON DELETE CASCADE;

ALTER TABLE configurare_locuri_incarcare_vehicule
    ADD CONSTRAINT fk_config_locuri_vehicle_beneficiar FOREIGN KEY (beneficiar_id) REFERENCES configurare_beneficiari_transport(id) ON DELETE CASCADE;

ALTER TABLE configurare_zone_distributie_vehicule
    ADD CONSTRAINT fk_config_zone_vehicle_beneficiar FOREIGN KEY (beneficiar_id) REFERENCES configurare_beneficiari_transport(id) ON DELETE CASCADE;

CREATE TABLE curse_dispecer (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT UNSIGNED NOT NULL,
    tip_transport ENUM('primar', 'distributie', 'compresor') NOT NULL,
    data_cursa DATE NOT NULL,
    loc_incarcare_id INT UNSIGNED NULL,
    beneficiar_id INT UNSIGNED NULL,
    tip_marfa VARCHAR(50) NULL,
    cantitate_incarcata DECIMAL(12,2) NULL,
    cantitate_prelevata DECIMAL(12,2) NULL,
    nr_clienti INT UNSIGNED NULL,
    km_cursa INT UNSIGNED NULL,
    ore_functionare DECIMAL(10,2) NULL,
    ore_aspirare DECIMAL(12,2) NULL,
    km_dislocare DECIMAL(12,2) NULL,
    tona_livrata DECIMAL(12,2) NULL,
    zona_distributie_id INT UNSIGNED NULL,
    status_facturare ENUM('in_curs_facturare', 'facturat', 'nefacturat') NOT NULL DEFAULT 'in_curs_facturare',
    pret_tarifare DECIMAL(12,2) NOT NULL,
    total_facturare DECIMAL(12,2) NOT NULL,
    cost_km_primar DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    cost_km_distributie DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    cost_km_mixt DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    observatii TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_curse_vehicle (vehicle_id),
    INDEX idx_curse_tip_transport (tip_transport),
    INDEX idx_curse_data (data_cursa),
    INDEX idx_curse_loc (loc_incarcare_id),
    INDEX idx_curse_beneficiar (beneficiar_id),
    INDEX idx_curse_zona (zona_distributie_id),
    CONSTRAINT fk_curse_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicule(id) ON DELETE RESTRICT,
    CONSTRAINT fk_curse_loc FOREIGN KEY (loc_incarcare_id) REFERENCES configurare_locuri_incarcare(id) ON DELETE RESTRICT,
    CONSTRAINT fk_curse_beneficiar FOREIGN KEY (beneficiar_id) REFERENCES configurare_beneficiari_transport(id) ON DELETE RESTRICT,
    CONSTRAINT fk_curse_zona FOREIGN KEY (zona_distributie_id) REFERENCES configurare_zone_distributie(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE curse_cheltuieli (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cursa_id INT UNSIGNED NOT NULL,
    tip_cheltuiala ENUM('motorina', 'taxe_drum', 'diurna', 'service', 'alte') NOT NULL,
    suma DECIMAL(12,2) NOT NULL,
    data_cheltuiala DATE NOT NULL,
    observatii TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_curse_cheltuieli_cursa (cursa_id),
    INDEX idx_curse_cheltuieli_data (data_cheltuiala),
    CONSTRAINT fk_curse_cheltuieli_cursa FOREIGN KEY (cursa_id) REFERENCES curse_dispecer(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE curse_cheltuieli_documente (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cheltuiala_id INT UNSIGNED NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(150) NULL,
    file_size INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_curse_doc_cheltuiala (cheltuiala_id),
    CONSTRAINT fk_curse_doc_cheltuiala FOREIGN KEY (cheltuiala_id) REFERENCES curse_cheltuieli(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    modul VARCHAR(50) NOT NULL,
    record_id INT UNSIGNED NOT NULL,
    actiune VARCHAR(50) NOT NULL,
    descriere VARCHAR(255) NOT NULL,
    before_data LONGTEXT NULL,
    after_data LONGTEXT NULL,
    user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_audit_modul_record (modul, record_id),
    INDEX idx_audit_created_at (created_at),
    CONSTRAINT fk_audit_log_user FOREIGN KEY (user_id) REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO utilizatori (nume, email, telefon, parola, rol, status, created_at, updated_at) VALUES
('Administrator Sistem', 'admin@example.com', '0722000001', '$2y$10$GWugLSIk7.dnwlxTjcT0Dec4JrE0QSLHSsW59JvT2sBIw9YFlUzhu', 'admin', 'activ', NOW(), NOW()),
('Operator Flota', 'user@example.com', '0722000002', '$2y$10$GWugLSIk7.dnwlxTjcT0Dec4JrE0QSLHSsW59JvT2sBIw9YFlUzhu', 'utilizator', 'activ', NOW(), NOW());

INSERT INTO vehicule (nr_inmatriculare, marca, model, tip_vehicul, an_fabricatie, km_bord, km_revizie, serie_sasiu, garaj, poza_original, poza_stocata, consum_mediu, status, observatii, created_at, updated_at) VALUES
('B-101-FLT', 'Dacia', 'Duster', 'autovehicul', 2021, 85420, 90000, 'UU1HSDACIA0001001', 'Garaj Bucuresti', NULL, NULL, NULL, 'activ', 'Vehicul pentru livrari locale', NOW(), NOW()),
('B-202-FLT', 'Ford', 'Transit', 'camion', 2020, 120300, 130000, 'WF0XXXTTGXLA02021', 'Garaj Ilfov', NULL, NULL, NULL, 'activ', 'Autoutilitara transport marfa', NOW(), NOW()),
('B-303-FLT', 'Renault', 'Clio', 'autovehicul', 2019, 142210, 150000, 'VF1RCLIOFLEET3030', 'Garaj Brasov', NULL, NULL, NULL, 'inactiv', 'In service prelungit', NOW(), NOW());

INSERT INTO soferi (nume, telefon, vehicle_id, permis_expira_la, status, observatii, created_at, updated_at) VALUES
('Ionescu Mihai', '0722000001', 1, DATE_ADD(CURDATE(), INTERVAL 300 DAY), 'activ', 'Disponibil full-time', NOW(), NOW()),
('Popescu Andrei', '0722000002', 2, DATE_ADD(CURDATE(), INTERVAL 120 DAY), 'activ', 'Route urban', NOW(), NOW()),
('Marin Elena', '0722000003', NULL, DATE_ADD(CURDATE(), INTERVAL 45 DAY), 'inactiv', 'Concediu medical', NOW(), NOW());

INSERT INTO concedii (
    driver_id,
    tip_concediu,
    data_inceput,
    data_sfarsit,
    inlocuitor_id,
    note,
    status,
    created_by,
    created_at,
    updated_at
) VALUES
(1, 'odihna', DATE_ADD(CURDATE(), INTERVAL 2 DAY), DATE_ADD(CURDATE(), INTERVAL 5 DAY), 2, 'Concediu planificat pentru odihnă.', 'aprobat', 1, NOW(), NOW()),
(2, 'personal', DATE_ADD(CURDATE(), INTERVAL 10 DAY), DATE_ADD(CURDATE(), INTERVAL 12 DAY), 1, 'Eveniment personal.', 'in_asteptare', 1, NOW(), NOW()),
(1, 'medical', DATE_ADD(CURDATE(), INTERVAL 16 DAY), DATE_ADD(CURDATE(), INTERVAL 18 DAY), NULL, 'Consultații și recuperare.', 'in_asteptare_aprobare', 2, NOW(), NOW()),
(2, 'fara_plata', DATE_ADD(CURDATE(), INTERVAL 25 DAY), DATE_ADD(CURDATE(), INTERVAL 26 DAY), NULL, 'Rezolvare urgentă familie.', 'respins', 2, NOW(), NOW());

INSERT INTO alimentari (vehicle_id, driver_id, data_alimentare, litri, cost_total, km_bord, observatii, created_at, updated_at) VALUES
(1, 1, DATE_SUB(CURDATE(), INTERVAL 3 DAY), 45.50, 355.40, 85420, 'Motorina statia A', NOW(), NOW()),
(2, 2, DATE_SUB(CURDATE(), INTERVAL 2 DAY), 60.00, 468.00, 120300, 'Motorina statia B', NOW(), NOW()),
(1, 1, DATE_SUB(CURDATE(), INTERVAL 15 DAY), 42.00, 327.60, 84920, 'Alimentare traseu Brasov', NOW(), NOW()),
(3, NULL, DATE_SUB(CURDATE(), INTERVAL 20 DAY), 30.00, 240.00, 142210, 'Alimentare inainte de intrare service', NOW(), NOW());

INSERT INTO mentenanta (vehicle_id, tip_interventie, data_interventie, cost, atelier, furnizor_piesa, fisier_original, fisier_stocat, observatii, created_at, updated_at) VALUES
(1, 'Schimb ulei si filtre', DATE_SUB(CURDATE(), INTERVAL 12 DAY), 780.00, 'Service Rapid SRL', 'Piese Auto Nord', NULL, NULL, 'Revizie periodica', NOW(), NOW()),
(2, 'Placute frana fata', DATE_SUB(CURDATE(), INTERVAL 5 DAY), 540.00, 'AutoFix', 'Auto Partener SRL', NULL, NULL, 'Uzura normala', NOW(), NOW()),
(3, 'Diagnoza electrica', DATE_SUB(CURDATE(), INTERVAL 1 MONTH), 320.00, 'ElectroCar', 'Electro Parts', NULL, NULL, 'Investigatie martor bord', NOW(), NOW());

INSERT INTO documente (vehicle_id, tip_document, numar_document, data_expirare, observatii, created_at, updated_at) VALUES
(1, 'RCA', 'RCA-001-2026', DATE_ADD(CURDATE(), INTERVAL 15 DAY), 'Prioritate mare pentru reinnoire', NOW(), NOW()),
(1, 'ITP', 'ITP-001-2026', DATE_ADD(CURDATE(), INTERVAL 55 DAY), 'Programare deja facuta', NOW(), NOW()),
(2, 'Rovinieta', 'ROV-7788', DATE_ADD(CURDATE(), INTERVAL 8 DAY), 'Verificare online recomandata', NOW(), NOW()),
(3, 'RCA', 'RCA-003-2026', DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'Expirat - nu circula', NOW(), NOW());

INSERT INTO documente_soferi (driver_id, tip_document, numar_document, data_expirare, observatii, created_at, updated_at) VALUES
(1, 'Carte identitate', 'CI-ION-2026', DATE_ADD(CURDATE(), INTERVAL 420 DAY), 'Document personal incarcat pentru evidenta interna', NOW(), NOW()),
(1, 'Atestat profesional', 'ATP-101', DATE_ADD(CURDATE(), INTERVAL 18 DAY), 'Necesita verificare pentru reinnoire', NOW(), NOW()),
(2, 'Aviz medical', 'MED-2026-02', DATE_ADD(CURDATE(), INTERVAL 65 DAY), 'Valabil pentru cursele curente', NOW(), NOW());

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
    pret_ora_aspirare,
    pret_km_dislocare,
    pret_tona_livrata,
    activ,
    created_at,
    updated_at
) VALUES
('LPG AUTO', 'gpl_vrac', 5.50, 1, 1, 0, 5.50, 2.85, 0.00, 0.00, 0.00, 0.00, 0.00, 1, NOW(), NOW()),
('Retail Client SRL', 'butelii', 5.20, 1, 1, 0, 5.20, 2.60, 0.00, 0.00, 0.00, 0.00, 0.00, 1, NOW(), NOW()),
('Distrib Logistic SA', 'carburant', 5.80, 1, 1, 0, 5.80, 3.20, 0.00, 0.00, 0.00, 0.00, 0.00, 1, NOW(), NOW());

INSERT INTO configurare_locuri_incarcare (beneficiar_id, nume, tarif, activ, created_at, updated_at) VALUES
(1, 'Depozit Central Bucuresti', 0.00, 1, NOW(), NOW()),
(2, 'Terminal Brasov', 0.00, 1, NOW(), NOW()),
(1, 'Hub Cluj', 0.00, 1, NOW(), NOW());

INSERT INTO configurare_zone_distributie (beneficiar_id, nume, tarif_distributie, cost_extra_km, activ, created_at, updated_at) VALUES
(2, 'Bucuresti', 2.60, 0.00, 1, NOW(), NOW()),
(1, 'Ilfov', 2.85, 0.00, 1, NOW(), NOW()),
(1, 'Regional', 3.20, 0.00, 1, NOW(), NOW());

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
(1, 'primar', DATE_SUB(CURDATE(), INTERVAL 2 DAY), 1, 1, 'gpl_vrac', NULL, 1, 320, NULL, 'in_curs_facturare', 5.10, 1632.00, 'Cursa primara catre depozit regional.', NOW(), NOW()),
(2, 'distributie', DATE_SUB(CURDATE(), INTERVAL 1 DAY), 2, 2, 'butelii', 12.50, 6, NULL, 1, 'nefacturat', 2.60, 32.50, 'Distributie urbana pentru clienti retail.', NOW(), NOW()),
(1, 'distributie', CURDATE(), 1, 1, 'gpl_vrac', 18.20, 9, NULL, 2, 'facturat', 2.85, 51.87, 'Runda zilnica zona Ilfov.', NOW(), NOW());

INSERT INTO curse_cheltuieli (cursa_id, tip_cheltuiala, suma, data_cheltuiala, observatii, created_at, updated_at) VALUES
(1, 'motorina', 520.00, DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'Alimentare pentru cursa primara.', NOW(), NOW()),
(1, 'taxe_drum', 130.00, DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'Taxe pod si autostrada.', NOW(), NOW()),
(2, 'diurna', 90.00, DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'Diurna sofer distributie.', NOW(), NOW());

