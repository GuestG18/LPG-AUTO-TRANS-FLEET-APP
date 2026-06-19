ALTER TABLE anvelope
    MODIFY COLUMN status ENUM('in_stock','active','spare','damaged','missing','removed','scrapped','retreaded') NOT NULL DEFAULT 'in_stock';

ALTER TABLE anvelope
    ADD COLUMN IF NOT EXISTS target_vehicle_type ENUM('autovehicul','autoutilitara','camion','cap_tractor','semiremorca','semiremorca_primar','semiremorca_distributie','universal') NOT NULL DEFAULT 'universal' AFTER serial_number;

ALTER TABLE anvelope
    MODIFY COLUMN target_vehicle_type ENUM('autovehicul','autoutilitara','camion','cap_tractor','semiremorca','semiremorca_primar','semiremorca_distributie','universal') NOT NULL DEFAULT 'universal';

ALTER TABLE anvelope
    ADD COLUMN IF NOT EXISTS target_axle_config VARCHAR(20) NULL AFTER target_vehicle_type;

ALTER TABLE anvelope
    ADD COLUMN IF NOT EXISTS target_vehicle_types TEXT NULL AFTER target_vehicle_type,
    ADD COLUMN IF NOT EXISTS axle_type VARCHAR(40) NULL AFTER target_axle_config,
    ADD COLUMN IF NOT EXISTS tire_type ENUM('direction','traction','trailer','balloon','balloon_directional') NOT NULL DEFAULT 'trailer' AFTER axle_type,
    ADD COLUMN IF NOT EXISTS usage_compatibility VARCHAR(190) NULL AFTER tire_type,
    ADD COLUMN IF NOT EXISTS location_label VARCHAR(120) NULL AFTER usage_compatibility,
    ADD COLUMN IF NOT EXISTS profile_photo_original_name VARCHAR(190) NULL AFTER location_label,
    ADD COLUMN IF NOT EXISTS profile_photo_path VARCHAR(190) NULL AFTER profile_photo_original_name,
    ADD COLUMN IF NOT EXISTS location_photo_original_name VARCHAR(190) NULL AFTER profile_photo_path,
    ADD COLUMN IF NOT EXISTS location_photo_path VARCHAR(190) NULL AFTER location_photo_original_name,
    ADD COLUMN IF NOT EXISTS manufacturing_year SMALLINT UNSIGNED NULL AFTER dot_code,
    ADD COLUMN IF NOT EXISTS purchase_date DATE NULL AFTER manufacturing_year,
    ADD COLUMN IF NOT EXISTS purchase_price DECIMAL(12,2) NULL AFTER purchase_date,
    ADD COLUMN IF NOT EXISTS supplier VARCHAR(190) NULL AFTER purchase_price,
    ADD COLUMN IF NOT EXISTS invoice_number VARCHAR(120) NULL AFTER supplier,
    ADD COLUMN IF NOT EXISTS invoice_document_original_name VARCHAR(190) NULL AFTER invoice_number,
    ADD COLUMN IF NOT EXISTS invoice_document_path VARCHAR(190) NULL AFTER invoice_document_original_name,
    ADD COLUMN IF NOT EXISTS current_mileage INT UNSIGNED NOT NULL DEFAULT 0 AFTER km_initial,
    ADD COLUMN IF NOT EXISTS estimated_remaining_km INT UNSIGNED NULL AFTER estimated_life_km,
    ADD COLUMN IF NOT EXISTS initial_condition ENUM('good','acceptable','high_wear','critical','missing') NOT NULL DEFAULT 'good' AFTER min_tread_depth_mm,
    ADD COLUMN IF NOT EXISTS condition_status ENUM('good','acceptable','high_wear','critical','missing') NOT NULL DEFAULT 'good' AFTER initial_condition,
    ADD COLUMN IF NOT EXISTS season ENUM('summer','winter','all_season') NOT NULL DEFAULT 'all_season' AFTER condition_status,
    ADD COLUMN IF NOT EXISTS directional TINYINT(1) NOT NULL DEFAULT 0 AFTER season,
    ADD COLUMN IF NOT EXISTS rotation_direction VARCHAR(20) NULL AFTER directional;

UPDATE anvelope
SET axle_type = CASE COALESCE(tire_type, 'trailer')
    WHEN 'direction' THEN 'steering'
    WHEN 'balloon_directional' THEN 'steering'
    WHEN 'traction' THEN 'traction'
    WHEN 'balloon' THEN 'universal_balloon'
    ELSE 'trailer'
END
WHERE axle_type IS NULL OR TRIM(axle_type) = '';

ALTER TABLE vehicule_anvelope_pozitii
    ADD COLUMN IF NOT EXISTS axle_type ENUM('steering','traction','trailer') NOT NULL DEFAULT 'steering' AFTER axle_no;

CREATE TABLE IF NOT EXISTS anvelope_istoric (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tire_id INT UNSIGNED NOT NULL,
    old_vehicle_id INT UNSIGNED NULL,
    new_vehicle_id INT UNSIGNED NULL,
    old_position_id INT UNSIGNED NULL,
    new_position_id INT UNSIGNED NULL,
    old_axle_no TINYINT UNSIGNED NULL,
    new_axle_no TINYINT UNSIGNED NULL,
    old_position_label VARCHAR(120) NULL,
    new_position_label VARCHAR(120) NULL,
    old_status VARCHAR(40) NULL,
    new_status VARCHAR(40) NULL,
    reason VARCHAR(190) NULL,
    observation TEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_anvelope_istoric_tire (tire_id),
    INDEX idx_anvelope_istoric_created_at (created_at),
    CONSTRAINT fk_anvelope_istoric_tire FOREIGN KEY (tire_id) REFERENCES anvelope(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS anvelope_tip_compatibilitate (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tire_type VARCHAR(40) NOT NULL,
    vehicle_type VARCHAR(40) NOT NULL DEFAULT 'universal',
    axle_type VARCHAR(40) NOT NULL,
    is_allowed TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_tire_compatibility (tire_type, vehicle_type, axle_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO anvelope_tip_compatibilitate (tire_type, vehicle_type, axle_type, is_allowed, created_at, updated_at) VALUES
('direction', 'universal', 'steering', 1, NOW(), NOW()),
('traction', 'universal', 'traction', 1, NOW(), NOW()),
('trailer', 'universal', 'trailer', 1, NOW(), NOW()),
('balloon', 'universal', 'trailer', 1, NOW(), NOW()),
('balloon', 'universal', 'universal_balloon', 1, NOW(), NOW()),
('balloon_directional', 'universal', 'steering', 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE
is_allowed = 1,
updated_at = VALUES(updated_at);

UPDATE anvelope_tip_compatibilitate
SET is_allowed = 0, updated_at = NOW()
WHERE NOT (
    (tire_type = 'direction' AND axle_type = 'steering')
    OR (tire_type = 'balloon_directional' AND axle_type = 'steering')
    OR (tire_type = 'traction' AND axle_type = 'traction')
    OR (tire_type = 'trailer' AND axle_type = 'trailer')
    OR (tire_type = 'balloon' AND axle_type IN ('trailer', 'universal_balloon'))
);
