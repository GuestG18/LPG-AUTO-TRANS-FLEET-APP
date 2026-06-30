<?php
declare(strict_types=1);

abstract class BaseModel
{
    protected PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    protected function ensureDriverVehicleAssignmentsSchema(): void
    {
        static $ensured = false;

        if ($ensured) {
            return;
        }

        $this->db->exec("
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->exec("
            INSERT IGNORE INTO soferi_vehicule (driver_id, vehicle_id, is_primary, created_at, updated_at)
            SELECT id, vehicle_id, 1, COALESCE(created_at, NOW()), COALESCE(updated_at, NOW())
            FROM soferi
            WHERE vehicle_id IS NOT NULL
        ");

        $ensured = true;
    }
}
