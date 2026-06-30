-- Migrare: alocare multipla vehicule pentru soferi
-- Pastreaza soferi.vehicle_id ca vehicul principal pentru compatibilitate.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS soferi_vehicule (
    driver_id INT UNSIGNED NOT NULL,
    vehicle_id INT UNSIGNED NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (driver_id, vehicle_id),
    INDEX idx_soferi_vehicule_vehicle (vehicle_id),
    INDEX idx_soferi_vehicule_driver_primary (driver_id, is_primary),
    CONSTRAINT fk_soferi_vehicule_driver FOREIGN KEY (driver_id) REFERENCES soferi(id) ON DELETE CASCADE,
    CONSTRAINT fk_soferi_vehicule_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicule(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO soferi_vehicule (driver_id, vehicle_id, is_primary, created_at, updated_at)
SELECT id, vehicle_id, 1, COALESCE(created_at, NOW()), COALESCE(updated_at, NOW())
FROM soferi
WHERE vehicle_id IS NOT NULL;
