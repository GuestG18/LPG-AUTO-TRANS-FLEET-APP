CREATE TABLE IF NOT EXISTS fuel_fillups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    api_id VARCHAR(160) NOT NULL,
    vehicle_registration VARCHAR(40) NOT NULL,
    driver_name VARCHAR(180) NULL,
    fuel_type ENUM('motorina', 'adblue') NOT NULL,
    quantity_liters DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    odometer_km INT UNSIGNED NULL,
    total_value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    station_name VARCHAR(180) NULL,
    fillup_datetime DATETIME NOT NULL,
    is_full TINYINT(1) NOT NULL DEFAULT 0,
    raw_payload LONGTEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_fuel_fillups_api_id (api_id),
    INDEX idx_fuel_fillups_vehicle_datetime (vehicle_registration, fillup_datetime),
    INDEX idx_fuel_fillups_fuel_type (fuel_type),
    INDEX idx_fuel_fillups_full (vehicle_registration, fuel_type, is_full, fillup_datetime)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE fuel_fillups
    ADD COLUMN IF NOT EXISTS odometer_km INT UNSIGNED NULL AFTER quantity_liters;

ALTER TABLE fuel_fillups
    ADD COLUMN IF NOT EXISTS driver_name VARCHAR(180) NULL AFTER vehicle_registration;

UPDATE fuel_fillups
SET driver_name = NULLIF(TRIM(COALESCE(
    JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.sofer_card')),
    JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.driver_name')),
    JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.nume_sofer')),
    JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.sofer')),
    JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.driver'))
)), '')
WHERE (driver_name IS NULL OR TRIM(driver_name) = '')
  AND raw_payload IS NOT NULL
  AND JSON_VALID(raw_payload)
  AND COALESCE(
    JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.sofer_card')),
    JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.driver_name')),
    JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.nume_sofer')),
    JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.sofer')),
    JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.driver'))
  ) IS NOT NULL;

CREATE TABLE IF NOT EXISTS fuel_trip_links (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fillup_id INT UNSIGNED NOT NULL,
    trip_id INT UNSIGNED NOT NULL,
    match_type ENUM('automatic', 'manual') NOT NULL DEFAULT 'automatic',
    confidence DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uk_fuel_trip_links_fillup (fillup_id),
    INDEX idx_fuel_trip_links_trip (trip_id),
    CONSTRAINT fk_fuel_trip_links_fillup FOREIGN KEY (fillup_id) REFERENCES fuel_fillups(id) ON DELETE CASCADE,
    CONSTRAINT fk_fuel_trip_links_trip FOREIGN KEY (trip_id) REFERENCES curse_dispecer(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fuel_sync_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sync_started_at DATETIME NOT NULL,
    sync_finished_at DATETIME NULL,
    date_from DATE NOT NULL,
    date_to DATE NOT NULL,
    status VARCHAR(30) NOT NULL,
    records_received INT UNSIGNED NOT NULL DEFAULT 0,
    records_inserted INT UNSIGNED NOT NULL DEFAULT 0,
    records_updated INT UNSIGNED NOT NULL DEFAULT 0,
    error_message TEXT NULL,
    INDEX idx_fuel_sync_logs_started (sync_started_at),
    INDEX idx_fuel_sync_logs_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fuel_sync_state (
    state_key VARCHAR(80) NOT NULL PRIMARY KEY,
    state_value VARCHAR(255) NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
