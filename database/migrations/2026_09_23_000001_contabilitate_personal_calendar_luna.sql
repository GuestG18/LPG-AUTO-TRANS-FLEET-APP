-- Contabilitate Personal: calendar legal RO, regim de lucru si inregistrari lunare.
--
-- 1. legal_holidays / legal_holiday_sync
--    Cache local pentru sarbatorile legale descarcate de la Nager.Date
--    (https://date.nager.at/api/v3/PublicHolidays/{AN}/RO). Sincronizarea e pe AN:
--    pagina nu mai cheama API-ul cat timp anul are un sync reusit. Zilele lucratoare
--    le calculeaza aplicatia (Luni-Vineri minus sarbatorile), nu API-ul.
--    Un sync esuat nu sterge niciodata cache-ul existent.
--
-- 2. soferi.regim_lucru / staff_members.regim_lucru
--    Regimul de lucru al angajatului (5 zile, 6 zile, Luni-Vineri, personalizat).
--    NU se foloseste pentru a deduce zilele lucrate din calendarul legal.
--
-- 3. personal_luna
--    Inregistrarea lunara a unui angajat: zile lucrate (pontaj), absente, componentele
--    costului salarial. La finalizare se ingheata si regimul, zilele lucratoare RO si
--    zilele CO/CM din Programare concedii, ca modificarile ulterioare sa nu rescrie luna.
--    CO-ul soferilor NU se introduce aici: vine read-only din tabela `concedii`.

CREATE TABLE IF NOT EXISTS legal_holidays (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    holiday_date DATE NOT NULL,
    name VARCHAR(190) NOT NULL,
    local_name VARCHAR(190) NOT NULL,
    country_code CHAR(2) NOT NULL,
    year SMALLINT UNSIGNED NOT NULL,
    source VARCHAR(30) NOT NULL DEFAULT 'nager_date',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_legal_holidays_country_date (country_code, holiday_date),
    KEY idx_legal_holidays_country_year (country_code, year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS legal_holiday_sync (
    country_code CHAR(2) NOT NULL,
    year SMALLINT UNSIGNED NOT NULL,
    status ENUM('ok','error') NOT NULL,
    holiday_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    synced_at DATETIME NULL,
    last_attempt_at DATETIME NOT NULL,
    last_error VARCHAR(255) NULL,
    PRIMARY KEY (country_code, year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'soferi' AND COLUMN_NAME = 'regim_lucru');
SET @sql := IF(@has_col = 0, 'ALTER TABLE soferi ADD COLUMN regim_lucru VARCHAR(20) NULL AFTER tip_colaborare, ADD COLUMN regim_lucru_detalii VARCHAR(120) NULL AFTER regim_lucru', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_members' AND COLUMN_NAME = 'regim_lucru');
SET @sql := IF(@has_col = 0, 'ALTER TABLE staff_members ADD COLUMN regim_lucru VARCHAR(20) NULL AFTER status, ADD COLUMN regim_lucru_detalii VARCHAR(120) NULL AFTER regim_lucru', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS personal_luna (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subject_type ENUM('driver','staff') NOT NULL,
    driver_id INT UNSIGNED NULL,
    staff_member_id INT UNSIGNED NULL,
    -- = driver_id sau staff_member_id; coloana simpla (nu generata), pentru ca MySQL
    -- nu permite ON DELETE CASCADE pe coloanele de baza ale unei coloane generate.
    subject_id INT UNSIGNED NOT NULL,
    perioada DATE NOT NULL,
    zile_lucrate DECIMAL(4,1) NULL,
    zile_co DECIMAL(4,1) NULL,
    zile_cm DECIMAL(4,1) NULL,
    zile_absente DECIMAL(4,1) NULL,
    salariu_baza DECIMAL(10,2) NULL,
    sporuri DECIMAL(10,2) NULL,
    retineri DECIMAL(10,2) NULL,
    alte_ajustari DECIMAL(10,2) NULL,
    cost_total DECIMAL(10,2) NULL,
    observatii TEXT NULL,
    status ENUM('ciorna','finalizat') NOT NULL DEFAULT 'ciorna',
    snapshot_regim_lucru VARCHAR(160) NULL,
    snapshot_zile_lucratoare_ro TINYINT UNSIGNED NULL,
    snapshot_zile_co_planificare DECIMAL(4,1) NULL,
    snapshot_zile_cm_planificare DECIMAL(4,1) NULL,
    finalized_at DATETIME NULL,
    finalized_by INT UNSIGNED NULL,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_personal_luna_subject_perioada (subject_type, subject_id, perioada),
    KEY idx_personal_luna_perioada (perioada),
    CONSTRAINT fk_personal_luna_driver FOREIGN KEY (driver_id) REFERENCES soferi(id) ON DELETE CASCADE,
    CONSTRAINT fk_personal_luna_staff FOREIGN KEY (staff_member_id) REFERENCES staff_members(id) ON DELETE CASCADE,
    CONSTRAINT fk_personal_luna_finalized_by FOREIGN KEY (finalized_by) REFERENCES utilizatori(id) ON DELETE SET NULL,
    CONSTRAINT fk_personal_luna_created_by FOREIGN KEY (created_by) REFERENCES utilizatori(id) ON DELETE SET NULL,
    CONSTRAINT fk_personal_luna_updated_by FOREIGN KEY (updated_by) REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
