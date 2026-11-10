-- Consumabile & Fluide Tehnice (etapa 1): arborele de categorii administrabil, tipul articolului
-- pe componente si stocul fizic de consumabile (produse, recipiente, miscari).
-- Aplicata si la rulare: AutoComponentCatalogService::taxonomy() / ensureTable() si
-- ConsumabileStockService::ensureSchema() (de aceea CREATE TABLE IF NOT EXISTS / INSERT IGNORE).

-- 1. Arborele catalogului de componente (ramuri → subcategorii → categorii). Elementele initiale
--    (Sasiu, Rezervor, categoriile 1-17) au sistem = 1: se pot redenumi, nu dezactiva.
CREATE TABLE IF NOT EXISTS mentenanta_auto_ramuri (
    cheie VARCHAR(40) NOT NULL PRIMARY KEY,
    nume VARCHAR(120) NOT NULL,
    icon VARCHAR(40) NULL,
    ordine SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    tipuri_vehicul VARCHAR(120) NOT NULL DEFAULT 'camion,cap_tractor,semiremorca',
    subcategorii VARCHAR(1000) NOT NULL DEFAULT '',
    sistem TINYINT(1) NOT NULL DEFAULT 0,
    activ TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mentenanta_auto_subcategorii (
    cheie VARCHAR(40) NOT NULL PRIMARY KEY,
    nume VARCHAR(120) NOT NULL,
    icon VARCHAR(40) NULL,
    ordine SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    sistem TINYINT(1) NOT NULL DEFAULT 0,
    activ TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mentenanta_auto_categorii (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    subcategorie VARCHAR(40) NOT NULL,
    nume VARCHAR(120) NOT NULL,
    icon VARCHAR(40) NULL,
    ordine SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    tip_articol_implicit VARCHAR(20) NOT NULL DEFAULT 'componenta',
    sistem TINYINT(1) NOT NULL DEFAULT 0,
    activ TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_mac_sub (subcategorie, activ)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO mentenanta_auto_ramuri (cheie, nume, icon, ordine, tipuri_vehicul, subcategorii, sistem, activ, created_at, updated_at) VALUES
    ('sasiu', 'Sasiu', 'bi-truck-front', 1, 'camion,cap_tractor,semiremorca', 'sasiu,hidraulic', 1, 1, NOW(), NOW()),
    ('rezervor', 'Rezervor', 'bi-droplet-half', 2, 'camion,semiremorca', 'sasiu,hidraulic,livrare_gaz', 1, 1, NOW(), NOW()),
    ('consumabile', 'Consumabile & Fluide Tehnice', 'bi-droplet', 3, 'camion,cap_tractor,semiremorca', 'uleiuri_lubrifianti,lichide_tehnice,vaseline_unsori,filtre_consumabile,garnituri_etansari', 0, 1, NOW(), NOW());

INSERT IGNORE INTO mentenanta_auto_subcategorii (cheie, nume, icon, ordine, sistem, activ, created_at, updated_at) VALUES
    ('sasiu', 'Sasiu', 'bi-truck-front', 1, 1, 1, NOW(), NOW()),
    ('hidraulic', 'Hidraulic', 'bi-droplet', 2, 1, 1, NOW(), NOW()),
    ('livrare_gaz', 'Livrare Gaz', 'bi-fire', 3, 1, 1, NOW(), NOW()),
    ('uleiuri_lubrifianti', 'Uleiuri și lubrifianți', 'bi-droplet-fill', 10, 0, 1, NOW(), NOW()),
    ('lichide_tehnice', 'Lichide tehnice', 'bi-moisture', 11, 0, 1, NOW(), NOW()),
    ('vaseline_unsori', 'Vaseline și unsori', 'bi-brush', 12, 0, 1, NOW(), NOW()),
    ('filtre_consumabile', 'Filtre și consumabile de service', 'bi-funnel', 13, 0, 1, NOW(), NOW()),
    ('garnituri_etansari', 'Garnituri și etanșări', 'bi-record2', 14, 0, 1, NOW(), NOW());

INSERT IGNORE INTO mentenanta_auto_categorii (id, subcategorie, nume, icon, ordine, tip_articol_implicit, sistem, activ, created_at, updated_at) VALUES
    (1, 'sasiu', 'Suspensie', 'bi-truck-flatbed', 1, 'componenta', 1, 1, NOW(), NOW()),
    (2, 'sasiu', 'Rulare', 'bi-disc', 2, 'componenta', 1, 1, NOW(), NOW()),
    (3, 'sasiu', 'Franare', 'bi-record-circle', 3, 'componenta', 1, 1, NOW(), NOW()),
    (4, 'sasiu', 'Racire', 'bi-thermometer-snow', 4, 'componenta', 1, 1, NOW(), NOW()),
    (5, 'sasiu', 'Electrica', 'bi-lightning-charge', 5, 'componenta', 1, 1, NOW(), NOW()),
    (6, 'sasiu', 'Motor', 'bi-gear-wide-connected', 6, 'componenta', 1, 1, NOW(), NOW()),
    (7, 'sasiu', 'Comfort', 'bi-sliders', 7, 'componenta', 1, 1, NOW(), NOW()),
    (8, 'sasiu', 'Evacuare', 'bi-wind', 8, 'componenta', 1, 1, NOW(), NOW()),
    (9, 'sasiu', 'Directie', 'bi-sign-turn-right', 9, 'componenta', 1, 1, NOW(), NOW()),
    (10, 'hidraulic', 'Hidraulic', 'bi-droplet-half', 10, 'componenta', 1, 1, NOW(), NOW()),
    (11, 'livrare_gaz', 'Livrare Gaz', 'bi-truck', 11, 'componenta', 1, 1, NOW(), NOW()),
    (12, 'livrare_gaz', 'Calculator Livrare', 'bi-calculator', 12, 'componenta', 1, 1, NOW(), NOW()),
    (13, 'livrare_gaz', 'Imprimare Bon', 'bi-printer-fill', 13, 'componenta', 1, 1, NOW(), NOW()),
    (14, 'livrare_gaz', 'Corp Masurator', 'bi-speedometer2', 14, 'componenta', 1, 1, NOW(), NOW()),
    (15, 'livrare_gaz', 'Degazor', 'bi-filter-circle-fill', 15, 'componenta', 1, 1, NOW(), NOW()),
    (16, 'livrare_gaz', 'Valva Diferentiala', 'bi-diagram-3-fill', 16, 'componenta', 1, 1, NOW(), NOW()),
    (17, 'livrare_gaz', 'Rezervor Tank', 'bi-hdd-fill', 17, 'componenta', 1, 1, NOW(), NOW()),
    (18, 'uleiuri_lubrifianti', 'Uleiuri și lubrifianți', 'bi-droplet-fill', 18, 'fluid', 0, 1, NOW(), NOW()),
    (19, 'lichide_tehnice', 'Lichide tehnice', 'bi-moisture', 19, 'fluid', 0, 1, NOW(), NOW()),
    (20, 'vaseline_unsori', 'Vaseline și unsori', 'bi-brush', 20, 'consumabil', 0, 1, NOW(), NOW()),
    (21, 'filtre_consumabile', 'Filtre și consumabile de service', 'bi-funnel', 21, 'consumabil', 0, 1, NOW(), NOW()),
    (22, 'garnituri_etansari', 'Garnituri și etanșări', 'bi-record2', 22, 'consumabil', 0, 1, NOW(), NOW());

-- 2. Tipul articolului pe componente (independent de categorie). Implicit „componenta”: componentele
--    existente raman neschimbate. Adaugata doar daca lipseste (aplicatia o poate fi creat deja).
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mentenanta_auto_componente' AND COLUMN_NAME = 'tip_articol');
SET @ddl = IF(@col_exists = 0,
    'ALTER TABLE mentenanta_auto_componente ADD COLUMN tip_articol VARCHAR(20) NOT NULL DEFAULT ''componenta'' AFTER monitorizare',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. Stocul fizic de consumabile: produse, compatibilitati explicite, recipiente, miscari (jurnal imuabil).
CREATE TABLE IF NOT EXISTS consumabile_produse (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    component_key VARCHAR(10) NULL,
    denumire VARCHAR(190) NOT NULL,
    producator VARCHAR(120) NULL,
    cod_produs VARCHAR(80) NULL,
    tip_articol VARCHAR(20) NOT NULL DEFAULT 'fluid',
    tip_fluid VARCHAR(40) NULL,
    vascozitate VARCHAR(40) NULL,
    specificatie VARCHAR(255) NULL,
    aprobari TEXT NULL,
    um VARCHAR(10) NOT NULL DEFAULT 'L',
    sistem_mecanic VARCHAR(80) NULL,
    tipuri_vehicul VARCHAR(120) NULL,
    compatibilitate_note TEXT NULL,
    interval_km INT UNSIGNED NULL,
    interval_luni SMALLINT UNSIGNED NULL,
    interval_ore INT UNSIGNED NULL,
    sursa_monitorizare VARCHAR(120) NULL,
    prag_alerta DECIMAL(12,3) NULL,
    critic TINYINT(1) NOT NULL DEFAULT 0,
    circuit_inchis TINYINT(1) NOT NULL DEFAULT 0,
    permite_retur TINYINT(1) NOT NULL DEFAULT 0,
    activ TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_cp_component (component_key),
    KEY idx_cp_tip (tip_fluid, activ)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS consumabile_compatibilitate (
    produs_a INT UNSIGNED NOT NULL,
    produs_b INT UNSIGNED NOT NULL,
    note VARCHAR(255) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (produs_a, produs_b),
    CONSTRAINT fk_cc_a FOREIGN KEY (produs_a) REFERENCES consumabile_produse (id),
    CONSTRAINT fk_cc_b FOREIGN KEY (produs_b) REFERENCES consumabile_produse (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS consumabile_recipiente (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cod VARCHAR(40) NOT NULL,
    tip VARCHAR(20) NOT NULL,
    tip_personalizat VARCHAR(60) NULL,
    produs_id INT UNSIGNED NULL,
    um VARCHAR(10) NULL,
    capacitate DECIMAL(12,3) NOT NULL,
    cantitate DECIMAL(12,3) NOT NULL DEFAULT 0,
    prag_minim DECIMAL(12,3) NULL,
    locatie VARCHAR(120) NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'activ',
    lot VARCHAR(60) NULL,
    data_expirare DATE NULL,
    observatii TEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_cr_cod (cod),
    KEY idx_cr_produs (produs_id, status),
    CONSTRAINT fk_cr_produs FOREIGN KEY (produs_id) REFERENCES consumabile_produse (id),
    CONSTRAINT chk_cr_cantitate CHECK (cantitate >= 0 AND cantitate <= capacitate)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS consumabile_miscari (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tip VARCHAR(20) NOT NULL,
    produs_id INT UNSIGNED NOT NULL,
    cantitate DECIMAL(12,3) NOT NULL,
    um VARCHAR(10) NOT NULL,
    recipient_sursa_id INT UNSIGNED NULL,
    sursa_inainte DECIMAL(12,3) NULL,
    sursa_dupa DECIMAL(12,3) NULL,
    recipient_dest_id INT UNSIGNED NULL,
    dest_inainte DECIMAL(12,3) NULL,
    dest_dupa DECIMAL(12,3) NULL,
    vehicul_id INT NULL,
    km_vehicul INT UNSIGNED NULL,
    sistem VARCHAR(80) NULL,
    cantitate_scoasa DECIMAL(12,3) NULL,
    furnizor VARCHAR(120) NULL,
    document VARCHAR(120) NULL,
    pret_unitar DECIMAL(12,4) NULL,
    lot VARCHAR(60) NULL,
    data_expirare DATE NULL,
    motiv VARCHAR(255) NULL,
    observatii TEXT NULL,
    referinta_id BIGINT UNSIGNED NULL,
    stornata_de_id BIGINT UNSIGNED NULL,
    idempotency_key VARCHAR(64) NOT NULL,
    efectuat_la DATETIME NOT NULL,
    user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_cm_key (idempotency_key),
    KEY idx_cm_produs (produs_id, efectuat_la),
    KEY idx_cm_vehicul (vehicul_id, efectuat_la),
    KEY idx_cm_sursa (recipient_sursa_id, efectuat_la),
    KEY idx_cm_dest (recipient_dest_id, efectuat_la),
    KEY idx_cm_tip (tip, efectuat_la),
    KEY idx_cm_ref (referinta_id),
    CONSTRAINT fk_cm_produs FOREIGN KEY (produs_id) REFERENCES consumabile_produse (id),
    CONSTRAINT fk_cm_sursa FOREIGN KEY (recipient_sursa_id) REFERENCES consumabile_recipiente (id),
    CONSTRAINT fk_cm_dest FOREIGN KEY (recipient_dest_id) REFERENCES consumabile_recipiente (id),
    CONSTRAINT chk_cm_cantitate CHECK (cantitate > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
