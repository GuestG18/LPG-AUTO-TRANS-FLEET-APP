-- Consumabile & Fluide Tehnice (etapa 3): AdBlue din CardOil + motorina CAN (SAS).
-- consumabile_can_intervale pastreaza totalul CAN „CANFuelUsed” al foii de parcurs SAS pe intervalul
-- dintre doua zile cu alimentare AdBlue: un interval inchis se cere din SAS o singura data.
-- Aplicata si la rulare de AdBlueService::ensureSchema() (de aceea IF NOT EXISTS / coloana verificata).

CREATE TABLE IF NOT EXISTS consumabile_can_intervale (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT NOT NULL,
    de_la DATE NOT NULL,
    pana_la DATE NOT NULL,
    can_fuel_l DECIMAL(10,1) NULL,
    km_gps DECIMAL(10,1) NULL,
    has_can TINYINT(1) NOT NULL DEFAULT 0,
    status VARCHAR(12) NOT NULL,
    eroare VARCHAR(255) NULL,
    inchis TINYINT(1) NOT NULL DEFAULT 1,
    fetched_at DATETIME NOT NULL,
    UNIQUE KEY uq_cci_interval (vehicle_id, de_la, pana_la),
    KEY idx_cci_vehicle (vehicle_id, de_la)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sistemul „AdBlue” al vehiculului: alimentarile se fac la plin (permite estimarea restului / autonomiei).
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vehicule_sisteme_fluide' AND COLUMN_NAME = 'alimentare_la_plin');
SET @ddl = IF(@col_exists = 0,
    'ALTER TABLE vehicule_sisteme_fluide ADD COLUMN alimentare_la_plin TINYINT(1) NOT NULL DEFAULT 0 AFTER circuit_inchis',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
