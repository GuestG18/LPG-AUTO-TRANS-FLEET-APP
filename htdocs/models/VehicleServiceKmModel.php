<?php
declare(strict_types=1);

/**
 * Km service: drumurile unui vehicul la / de la service si perioada in care a stat
 * acolo (Dispecer curse -> "Km service").
 *
 * Tabelul este separat de curse_dispecer intentionat: km-ii de service nu intra in
 * tarifare, facturare, diurne sau in km-ii vehiculului sincronizati din curse. Ei
 * servesc doar ca justificare: vehiculul a fost inactiv pentru ca era la reparat,
 * iar km-ii GPS rulati pana la service nu sunt km pierduti.
 *
 * Un rand fara data_intoarcere inseamna ca vehiculul este inca in service.
 */
class VehicleServiceKmModel extends BaseModel
{
    private static bool $schemaReady = false;

    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->ensureSchema();
    }

    /**
     * Intrarile afisate pe Dispecer curse: toate vehiculele aflate acum in service
     * plus cele intoarse in ultimele $days zile, cele mai noi primele.
     */
    public function getRecentEntries(int $days = 90): array
    {
        $stmt = $this->db->prepare("
            SELECT s.*, v.nr_inmatriculare, v.marca, v.model, d.nume AS sofer_nume, u.nume AS created_by_name
            FROM vehicule_service_km s
            INNER JOIN vehicule v ON v.id = s.vehicle_id
            LEFT JOIN soferi d ON d.id = s.driver_id
            LEFT JOIN utilizatori u ON u.id = s.created_by
            WHERE s.data_intoarcere IS NULL
               OR s.data_intoarcere >= :since
            ORDER BY (s.data_intoarcere IS NULL) DESC, s.data_plecare DESC, s.id DESC
        ");
        $stmt->execute([':since' => date('Y-m-d', strtotime('-' . max(1, $days) . ' days'))]);

        return array_map([self::class, 'withDuration'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM vehicule_service_km WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Km service per vehicul pentru Km pierduti. Ca la curse, o intrare intra in
     * perioada in care s-a incheiat (data intoarcerii; cea deschisa - data plecarii).
     *
     * @return array<int, array{nr: int, km: float, zile: int}>
     */
    public function kmByVehicleId(string $start, string $end): array
    {
        $stmt = $this->db->prepare("
            SELECT vehicle_id, COUNT(*) AS nr, COALESCE(SUM(km), 0) AS km,
                   COALESCE(SUM(DATEDIFF(COALESCE(data_intoarcere, CURDATE()), data_plecare) + 1), 0) AS zile
            FROM vehicule_service_km
            WHERE COALESCE(data_intoarcere, data_plecare) BETWEEN :start AND :end
            GROUP BY vehicle_id
        ");
        $stmt->execute([':start' => $start, ':end' => $end]);

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int) $row['vehicle_id']] = [
                'nr' => (int) $row['nr'],
                'km' => (float) $row['km'],
                'zile' => (int) $row['zile'],
            ];
        }

        return $map;
    }

    /**
     * O intrare noua sau modificarea uneia existente ($id > 0).
     * Cheile $data sunt deja validate de controller.
     */
    public function save(array $data, ?int $userId, int $id = 0): int
    {
        $params = [
            ':vehicle_id' => (int) $data['vehicle_id'],
            ':driver_id' => $data['driver_id'],
            ':data_plecare' => $data['data_plecare'],
            ':ora_plecare' => $data['ora_plecare'],
            ':data_intoarcere' => $data['data_intoarcere'],
            ':ora_intoarcere' => $data['ora_intoarcere'],
            ':km' => $data['km'],
            ':service_nume' => $data['service_nume'],
            ':motiv' => $data['motiv'],
            ':observatii' => $data['observatii'],
        ];

        if ($id > 0) {
            $stmt = $this->db->prepare('
                UPDATE vehicule_service_km
                SET vehicle_id = :vehicle_id, driver_id = :driver_id,
                    data_plecare = :data_plecare, ora_plecare = :ora_plecare,
                    data_intoarcere = :data_intoarcere, ora_intoarcere = :ora_intoarcere,
                    km = :km, service_nume = :service_nume, motiv = :motiv, observatii = :observatii,
                    updated_by = :updated_by, updated_at = :updated_at
                WHERE id = :id
            ');
            $params[':updated_by'] = $userId;
            $params[':updated_at'] = date('Y-m-d H:i:s');
            $params[':id'] = $id;
            $stmt->execute($params);

            return $id;
        }

        $stmt = $this->db->prepare('
            INSERT INTO vehicule_service_km
                (vehicle_id, driver_id, data_plecare, ora_plecare, data_intoarcere, ora_intoarcere,
                 km, service_nume, motiv, observatii, created_by, created_at)
            VALUES
                (:vehicle_id, :driver_id, :data_plecare, :ora_plecare, :data_intoarcere, :ora_intoarcere,
                 :km, :service_nume, :motiv, :observatii, :created_by, :created_at)
        ');
        $params[':created_by'] = $userId;
        $params[':created_at'] = date('Y-m-d H:i:s');
        $stmt->execute($params);

        return (int) $this->db->lastInsertId();
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM vehicule_service_km WHERE id = :id');
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /** Alta intrare a aceluiasi vehicul care se suprapune peste interval (null = nicio suprapunere). */
    public function findOverlap(int $vehicleId, string $from, ?string $to, int $excludeId = 0): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM vehicule_service_km
            WHERE vehicle_id = :vehicle_id
              AND id <> :exclude_id
              AND data_plecare <= :to_date
              AND COALESCE(data_intoarcere, '9999-12-31') >= :from_date
            ORDER BY data_plecare ASC
            LIMIT 1
        ");
        $stmt->execute([
            ':vehicle_id' => $vehicleId,
            ':exclude_id' => $excludeId,
            ':to_date' => $to ?? '9999-12-31',
            ':from_date' => $from,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** Zilele in service (inclusiv ziua plecarii si a intoarcerii); cele deschise se numara pana azi. */
    private static function withDuration(array $row): array
    {
        $from = strtotime((string) $row['data_plecare']);
        $to = $row['data_intoarcere'] !== null ? strtotime((string) $row['data_intoarcere']) : strtotime(date('Y-m-d'));
        $row['zile_service'] = $from !== false && $to !== false ? max(1, (int) round(($to - $from) / 86400) + 1) : null;
        $row['in_service'] = $row['data_intoarcere'] === null;

        return $row;
    }

    private function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS vehicule_service_km (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                vehicle_id INT UNSIGNED NOT NULL,
                driver_id INT UNSIGNED NULL,
                data_plecare DATE NOT NULL,
                ora_plecare TIME NULL,
                data_intoarcere DATE NULL,
                ora_intoarcere TIME NULL,
                km DECIMAL(10,2) NOT NULL DEFAULT 0,
                service_nume VARCHAR(150) NULL,
                motiv VARCHAR(255) NULL,
                observatii TEXT NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_by INT UNSIGNED NULL,
                updated_at DATETIME NULL,
                KEY idx_service_km_vehicle (vehicle_id, data_plecare),
                KEY idx_service_km_intoarcere (data_intoarcere),
                CONSTRAINT fk_service_km_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicule(id) ON DELETE CASCADE,
                CONSTRAINT fk_service_km_driver FOREIGN KEY (driver_id) REFERENCES soferi(id) ON DELETE SET NULL,
                CONSTRAINT fk_service_km_created_by FOREIGN KEY (created_by) REFERENCES utilizatori(id) ON DELETE SET NULL,
                CONSTRAINT fk_service_km_updated_by FOREIGN KEY (updated_by) REFERENCES utilizatori(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        self::$schemaReady = true;
    }
}
