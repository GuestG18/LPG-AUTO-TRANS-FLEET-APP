SET NAMES utf8mb4;

SET @db_name := DATABASE();

SET @has_record_type := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'mentenanta' AND COLUMN_NAME = 'record_type'
);
SET @sql := IF(@has_record_type = 0,
    "ALTER TABLE mentenanta ADD COLUMN record_type ENUM('intretinere','reparatie') NOT NULL DEFAULT 'intretinere' AFTER tip_interventie",
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_cost_center := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'mentenanta' AND COLUMN_NAME = 'centru_cost'
);
SET @sql := IF(@has_cost_center = 0,
    "ALTER TABLE mentenanta ADD COLUMN centru_cost VARCHAR(80) NULL AFTER record_type",
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_description := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'mentenanta' AND COLUMN_NAME = 'descriere'
);
SET @sql := IF(@has_description = 0,
    "ALTER TABLE mentenanta ADD COLUMN descriere TEXT NULL AFTER centru_cost",
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_status := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'mentenanta' AND COLUMN_NAME = 'status_interventie'
);
SET @sql := IF(@has_status = 0,
    "ALTER TABLE mentenanta ADD COLUMN status_interventie ENUM('in_asteptare','in_lucru','finalizata','anulata') NOT NULL DEFAULT 'finalizata' AFTER descriere",
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_km := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'mentenanta' AND COLUMN_NAME = 'km_interventie'
);
SET @sql := IF(@has_km = 0,
    "ALTER TABLE mentenanta ADD COLUMN km_interventie INT UNSIGNED NULL AFTER data_interventie",
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_parts_used := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'mentenanta' AND COLUMN_NAME = 'piese_utilizate'
);
SET @sql := IF(@has_parts_used = 0,
    "ALTER TABLE mentenanta ADD COLUMN piese_utilizate TEXT NULL AFTER furnizor_piesa",
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_labor_cost := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'mentenanta' AND COLUMN_NAME = 'cost_manopera'
);
SET @sql := IF(@has_labor_cost = 0,
    "ALTER TABLE mentenanta ADD COLUMN cost_manopera DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER cost",
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_parts_cost := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'mentenanta' AND COLUMN_NAME = 'cost_piese'
);
SET @sql := IF(@has_parts_cost = 0,
    "ALTER TABLE mentenanta ADD COLUMN cost_piese DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER cost_manopera",
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_immobilization := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'mentenanta' AND COLUMN_NAME = 'zile_imobilizare'
);
SET @sql := IF(@has_immobilization = 0,
    "ALTER TABLE mentenanta ADD COLUMN zile_imobilizare DECIMAL(6,2) NOT NULL DEFAULT 0 AFTER cost_piese",
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_source_intervention := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'mentenanta' AND COLUMN_NAME = 'source_intervention_id'
);
SET @sql := IF(@has_source_intervention = 0,
    "ALTER TABLE mentenanta ADD COLUMN source_intervention_id INT UNSIGNED NULL AFTER zile_imobilizare",
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS mentenanta_interventii_programate (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT UNSIGNED NOT NULL,
    tip_interventie ENUM('intretinere','reparatie') NOT NULL,
    data_programata DATE NOT NULL,
    cost_estimat DECIMAL(12,2) NOT NULL DEFAULT 0,
    furnizor VARCHAR(190) NULL,
    driver_id INT UNSIGNED NULL,
    client VARCHAR(190) NULL,
    centru_cost VARCHAR(80) NULL,
    descriere TEXT NOT NULL,
    status_interventie ENUM('programata','confirmata','in_lucru','finalizata','anulata') NOT NULL DEFAULT 'programata',
    converted_maintenance_id INT UNSIGNED NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_ment_prog_vehicle (vehicle_id),
    INDEX idx_ment_prog_date (data_programata),
    INDEX idx_ment_prog_status (status_interventie),
    CONSTRAINT fk_ment_prog_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicule(id) ON DELETE CASCADE,
    CONSTRAINT fk_ment_prog_driver FOREIGN KEY (driver_id) REFERENCES soferi(id) ON DELETE SET NULL,
    CONSTRAINT fk_ment_prog_record FOREIGN KEY (converted_maintenance_id) REFERENCES mentenanta(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mentenanta_piese (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cod_piesa VARCHAR(80) NOT NULL,
    denumire VARCHAR(190) NOT NULL,
    categorie VARCHAR(100) NOT NULL,
    producator VARCHAR(120) NULL,
    cod_oem VARCHAR(120) NULL,
    unitate_masura VARCHAR(30) NOT NULL DEFAULT 'buc',
    descriere TEXT NULL,
    mod_utilizare ENUM('stoc','direct') NOT NULL DEFAULT 'stoc',
    stoc_curent DECIMAL(12,2) NOT NULL DEFAULT 0,
    stoc_minim DECIMAL(12,2) NOT NULL DEFAULT 0,
    pret_achizitie DECIMAL(12,2) NOT NULL DEFAULT 0,
    furnizor VARCHAR(190) NULL,
    locatie_depozit VARCHAR(190) NULL,
    tipuri_vehicul TEXT NULL,
    modele_vehicul TEXT NULL,
    sisteme_componente TEXT NULL,
    pentru_mentenanta TINYINT(1) NOT NULL DEFAULT 0,
    interval_km INT UNSIGNED NULL,
    interval_luni SMALLINT UNSIGNED NULL,
    avertizare_km INT UNSIGNED NULL,
    avertizare_zile SMALLINT UNSIGNED NULL,
    factura_original VARCHAR(255) NULL,
    factura_stocata VARCHAR(255) NULL,
    fisa_original VARCHAR(255) NULL,
    fisa_stocata VARCHAR(255) NULL,
    imagine_original VARCHAR(255) NULL,
    imagine_stocata VARCHAR(255) NULL,
    status_piesa ENUM('activa','inactiva') NOT NULL DEFAULT 'activa',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_ment_piese_cod (cod_piesa),
    INDEX idx_ment_piese_categorie (categorie),
    INDEX idx_ment_piese_status (status_piesa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mentenanta_piese_utilizari (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    part_id INT UNSIGNED NOT NULL,
    maintenance_id INT UNSIGNED NULL,
    scheduled_intervention_id INT UNSIGNED NULL,
    vehicle_id INT UNSIGNED NOT NULL,
    cantitate DECIMAL(12,2) NOT NULL DEFAULT 1,
    cost_unitar DECIMAL(12,2) NOT NULL DEFAULT 0,
    data_montare DATE NOT NULL,
    km_montare INT UNSIGNED NULL,
    montata_de VARCHAR(190) NULL,
    observatii TEXT NULL,
    direct_mount TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    INDEX idx_ment_usage_part (part_id),
    INDEX idx_ment_usage_vehicle (vehicle_id),
    INDEX idx_ment_usage_maintenance (maintenance_id),
    CONSTRAINT fk_ment_usage_part FOREIGN KEY (part_id) REFERENCES mentenanta_piese(id) ON DELETE CASCADE,
    CONSTRAINT fk_ment_usage_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicule(id) ON DELETE CASCADE,
    CONSTRAINT fk_ment_usage_maintenance FOREIGN KEY (maintenance_id) REFERENCES mentenanta(id) ON DELETE SET NULL,
    CONSTRAINT fk_ment_usage_schedule FOREIGN KEY (scheduled_intervention_id) REFERENCES mentenanta_interventii_programate(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mentenanta_grupe_componente (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_type VARCHAR(40) NOT NULL DEFAULT 'universal',
    nume VARCHAR(120) NOT NULL,
    componente TEXT NULL,
    activ TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_ment_grupe_vehicle_name (vehicle_type, nume),
    INDEX idx_ment_grupe_vehicle_active (vehicle_type, activ)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO mentenanta_grupe_componente (vehicle_type, nume, componente, activ, created_at, updated_at) VALUES
('universal', 'Motor', NULL, 1, NOW(), NOW()),
('universal', 'Transmisie', NULL, 1, NOW(), NOW()),
('universal', 'Sistem franare', NULL, 1, NOW(), NOW()),
('universal', 'Sistem electric', NULL, 1, NOW(), NOW()),
('universal', 'Suspensie', NULL, 1, NOW(), NOW()),
('universal', 'Sistem pneumatic', NULL, 1, NOW(), NOW()),
('universal', 'Sistem hidraulic', NULL, 1, NOW(), NOW()),
('universal', 'Sistem racire', NULL, 1, NOW(), NOW()),
('universal', 'Caroserie', NULL, 1, NOW(), NOW()),
('universal', 'Consumabile', NULL, 1, NOW(), NOW()),
('universal', 'Altele', NULL, 1, NOW(), NOW());

UPDATE mentenanta
SET record_type = CASE
        WHEN LOWER(CONCAT_WS(' ', tip_interventie, observatii)) REGEXP 'defect|repar|diagnoz|alternator|turbin|fran|cutie|pierdere|suspensie' THEN 'reparatie'
        ELSE 'intretinere'
    END,
    centru_cost = COALESCE(NULLIF(centru_cost, ''), CASE
        WHEN LOWER(CONCAT_WS(' ', tip_interventie, observatii)) REGEXP 'fran' THEN 'Sistem frânare'
        WHEN LOWER(CONCAT_WS(' ', tip_interventie, observatii)) REGEXP 'electr|alternator' THEN 'Sistem electric'
        WHEN LOWER(CONCAT_WS(' ', tip_interventie, observatii)) REGEXP 'cutie|transmis' THEN 'Transmisie'
        WHEN LOWER(CONCAT_WS(' ', tip_interventie, observatii)) REGEXP 'suspens|pern.*aer' THEN 'Suspensie'
        WHEN LOWER(CONCAT_WS(' ', tip_interventie, observatii)) REGEXP 'anvelop' THEN 'Consumabile'
        ELSE 'Motor'
    END),
    descriere = COALESCE(NULLIF(descriere, ''), NULLIF(observatii, ''), tip_interventie),
    cost_manopera = CASE WHEN cost_manopera = 0 AND cost_piese = 0 THEN cost ELSE cost_manopera END;
