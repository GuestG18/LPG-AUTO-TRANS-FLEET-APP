-- Revizii → Schimb ulei (page=revizii). Tabelele se creeaza si automat la prima
-- accesare (ReviziiModel::ensureSchema); scriptul e pentru instalare explicita.

CREATE TABLE IF NOT EXISTS revizii_setari (
    setting_key VARCHAR(60) NOT NULL PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    updated_by INT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS revizii_planuri (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT UNSIGNED NOT NULL,
    tip_revizie VARCHAR(40) NOT NULL DEFAULT 'schimb_ulei',
    interval_km INT UNSIGNED NOT NULL,
    km_ultima_revizie INT UNSIGNED NULL,
    prag_avertizare_km INT UNSIGNED NULL,
    baseline_at DATETIME NOT NULL,
    consum_initial_km DECIMAL(10,1) NOT NULL DEFAULT 0,
    consum_initial_sursa VARCHAR(20) NOT NULL DEFAULT 'niciunul',
    updated_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_revizii_plan (vehicle_id, tip_revizie)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS revizii_istoric (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT UNSIGNED NOT NULL,
    tip_revizie VARCHAR(40) NOT NULL DEFAULT 'schimb_ulei',
    data_revizie DATETIME NOT NULL,
    km_bord INT UNSIGNED NULL,
    interval_km INT UNSIGNED NOT NULL,
    km_efectuati DECIMAL(10,1) NULL,
    ore_motor DECIMAL(10,2) NULL,
    km_echivalenti DECIMAL(10,1) NULL,
    factor_km_ora DECIMAL(6,2) NOT NULL,
    sursa_ore VARCHAR(20) NULL,
    date_complete TINYINT(1) NOT NULL DEFAULT 1,
    observatii VARCHAR(500) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_revizii_istoric_vehicul (vehicle_id, tip_revizie, data_revizie)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS revizii_sas_vehicule (
    vehicle_id INT UNSIGNED NOT NULL PRIMARY KEY,
    sas_car_id INT UNSIGNED NOT NULL,
    plate VARCHAR(30) NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_revizii_sas_car (sas_car_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS revizii_sas_segmente (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sas_car_id INT UNSIGNED NOT NULL,
    date_start DATETIME NOT NULL,
    date_end DATETIME NOT NULL,
    distance_km DECIMAL(10,3) NOT NULL DEFAULT 0,
    work_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    idle_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    fetched_at DATETIME NOT NULL,
    UNIQUE KEY uq_revizii_segment (sas_car_id, date_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS revizii_sas_acoperire (
    sas_car_id INT UNSIGNED NOT NULL,
    zi DATE NOT NULL,
    status VARCHAR(10) NOT NULL,
    inchisa TINYINT(1) NOT NULL DEFAULT 0,
    segmente INT UNSIGNED NOT NULL DEFAULT 0,
    eroare VARCHAR(500) NULL,
    fetched_at DATETIME NOT NULL,
    PRIMARY KEY (sas_car_id, zi)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Instalari create inainte de pragul per componenta / sursa orelor (MySQL 8+: IF NOT EXISTS
-- nu e suportat la ADD COLUMN pe toate versiunile; ReviziiModel le adauga si automat).
-- ALTER TABLE revizii_planuri ADD COLUMN prag_avertizare_km INT UNSIGNED NULL AFTER km_ultima_revizie;
-- ALTER TABLE revizii_istoric ADD COLUMN sursa_ore VARCHAR(20) NULL AFTER factor_km_ora;
