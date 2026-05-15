-- Migrare: modul dinamic anvelope pe vehicul
-- Data: 2026-05-12

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS anvelope (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    brand VARCHAR(100) NOT NULL,
    model VARCHAR(120) NULL,
    tire_size VARCHAR(50) NULL,
    dot_code VARCHAR(20) NULL,
    serial_number VARCHAR(120) NOT NULL UNIQUE,
    target_vehicle_type ENUM('autovehicul', 'camion', 'cap_tractor', 'semiremorca', 'universal') NOT NULL DEFAULT 'universal',
    mount_date DATE NULL,
    km_initial INT UNSIGNED NOT NULL DEFAULT 0,
    estimated_life_km INT UNSIGNED NULL,
    tread_depth_mm DECIMAL(5,2) NULL,
    min_tread_depth_mm DECIMAL(5,2) NOT NULL DEFAULT 2.00,
    status ENUM('active', 'spare', 'removed', 'damaged', 'retreaded') NOT NULL DEFAULT 'spare',
    notes TEXT NULL,
    mentenanta_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_anvelope_status (status),
    INDEX idx_anvelope_dot (dot_code),
    INDEX idx_anvelope_mentenanta (mentenanta_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vehicule_anvelope_pozitii (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT UNSIGNED NOT NULL,
    position_code VARCHAR(20) NOT NULL,
    position_label VARCHAR(120) NOT NULL,
    axle_no TINYINT UNSIGNED NOT NULL,
    side_code VARCHAR(8) NOT NULL,
    wheel_kind ENUM('single', 'dual') NOT NULL DEFAULT 'single',
    position_order SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_vehicule_anvelope_pozitii_vehicle_code (vehicle_id, position_code),
    INDEX idx_vehicule_anvelope_pozitii_vehicle_active (vehicle_id, is_active),
    CONSTRAINT fk_vehicule_anvelope_pozitii_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicule(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS anvelope_alocari (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tire_id INT UNSIGNED NOT NULL,
    vehicle_id INT UNSIGNED NOT NULL,
    position_id INT UNSIGNED NOT NULL,
    data_start DATE NOT NULL,
    data_end DATE NULL,
    km_start INT UNSIGNED NULL,
    km_end INT UNSIGNED NULL,
    status_end ENUM('spare', 'removed', 'damaged', 'retreaded', 'moved') NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_anvelope_alocari_tire (tire_id),
    INDEX idx_anvelope_alocari_vehicle (vehicle_id),
    INDEX idx_anvelope_alocari_position (position_id),
    INDEX idx_anvelope_alocari_active (data_end),
    CONSTRAINT fk_anvelope_alocari_tire FOREIGN KEY (tire_id) REFERENCES anvelope(id) ON DELETE CASCADE,
    CONSTRAINT fk_anvelope_alocari_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicule(id) ON DELETE CASCADE,
    CONSTRAINT fk_anvelope_alocari_position FOREIGN KEY (position_id) REFERENCES vehicule_anvelope_pozitii(id) ON DELETE CASCADE,
    CONSTRAINT fk_anvelope_alocari_created_by FOREIGN KEY (created_by) REFERENCES utilizatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
