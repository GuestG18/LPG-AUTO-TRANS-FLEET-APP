-- Vehicle authorizations module
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS authorization_zones (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_authorization_zones_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vehicle_authorizations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT UNSIGNED NOT NULL,
    authorization_type VARCHAR(120) NOT NULL,
    zone_id INT UNSIGNED NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_vehicle_authorizations_vehicle (vehicle_id),
    INDEX idx_vehicle_authorizations_zone (zone_id),
    INDEX idx_vehicle_authorizations_dates (start_date, end_date),
    CONSTRAINT fk_vehicle_authorizations_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicule(id) ON DELETE CASCADE,
    CONSTRAINT fk_vehicle_authorizations_zone FOREIGN KEY (zone_id) REFERENCES authorization_zones(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO authorization_zones (name, created_at, updated_at) VALUES
('România', NOW(), NOW()),
('Bulgaria', NOW(), NOW()),
('Ungaria', NOW(), NOW()),
('Polonia', NOW(), NOW()),
('Cehia', NOW(), NOW())
ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at);
