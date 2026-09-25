<?php
declare(strict_types=1);

abstract class BaseModel
{
    protected PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Schema pentru separarea capacitate reala / categorie de capacitate.
     *
     * `vehicule.capacitate_transport`     = capacitatea tehnica REALA (calcule).
     * `vehicule.categorie_capacitate_id`  = eticheta de grupare (dropdown-uri, filtre).
     * `vehicule.capacitate_transport_confirmata` = a verificat cineva capacitatea reala?
     * `curse_dispecer.capacitate_transport_confirmata` = acelasi marcaj, salvat pe cursa.
     *
     * Migrarea oficiala este
     * database/migrations/2026_09_18_000001_categorii_capacitate_vehicule.sql;
     * metoda asta doar tine paginile in picioare pe o baza inca nemigrata.
     */
    protected function ensureVehicleCapacityCategorySchema(): void
    {
        static $ensured = false;

        if ($ensured) {
            return;
        }

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS vehicule_categorii_capacitate (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                nume VARCHAR(80) NOT NULL,
                descriere VARCHAR(255) NULL,
                ordine_afisare INT UNSIGNED NOT NULL DEFAULT 0,
                activ TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uk_categorii_capacitate_nume (nume),
                INDEX idx_categorii_capacitate_ordine (activ, ordine_afisare, nume)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        foreach ([
            ['vehicule', 'categorie_capacitate_id', 'ALTER TABLE vehicule ADD COLUMN categorie_capacitate_id INT UNSIGNED NULL AFTER capacitate_transport'],
            ['vehicule', 'capacitate_transport_confirmata', 'ALTER TABLE vehicule ADD COLUMN capacitate_transport_confirmata TINYINT(1) NOT NULL DEFAULT 0 AFTER categorie_capacitate_id'],
            ['curse_dispecer', 'capacitate_transport_confirmata', 'ALTER TABLE curse_dispecer ADD COLUMN capacitate_transport_confirmata TINYINT(1) NOT NULL DEFAULT 0 AFTER capacitate_transport'],
        ] as [$table, $column, $ddl]) {
            $stmt = $this->db->prepare("
                SELECT COUNT(*)
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = :t
                  AND COLUMN_NAME = :c
            ");
            $stmt->bindValue(':t', $table);
            $stmt->bindValue(':c', $column);
            $stmt->execute();

            if ((int) $stmt->fetchColumn() === 0) {
                $this->db->exec($ddl);
            }
        }

        $ensured = true;
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
