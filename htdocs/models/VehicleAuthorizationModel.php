<?php
declare(strict_types=1);

class VehicleAuthorizationModel extends BaseModel
{
    private bool $schemaEnsured = false;

    public function ensureSchema(): void
    {
        if ($this->schemaEnsured) {
            return;
        }

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS authorization_zones (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uk_authorization_zones_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS vehicle_authorizations (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $this->schemaEnsured = true;
        $this->seedDefaultZones();
    }

    public function getVehicleOptions(): array
    {
        $this->ensureSchema();

        $stmt = $this->db->query(
            'SELECT id, nr_inmatriculare, marca, model
             FROM vehicule
             WHERE status = "activ"
               AND nr_inmatriculare <> "STOC-ANVELOPE"
               AND serie_sasiu <> "STOCANVELOPE00001"
             ORDER BY nr_inmatriculare ASC'
        );

        return $stmt->fetchAll();
    }

    public function getZones(): array
    {
        $this->ensureSchema();

        $stmt = $this->db->query(
            'SELECT z.*,
                    (SELECT COUNT(*) FROM vehicle_authorizations a WHERE a.zone_id = z.id) AS usage_count
             FROM authorization_zones z
             ORDER BY z.name ASC'
        );

        return $stmt->fetchAll();
    }

    public function getAuthorization(int $id): ?array
    {
        $this->ensureSchema();

        $stmt = $this->db->prepare(
            'SELECT *
             FROM vehicle_authorizations
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function getAuthorizationList(string $search, int $page, int $perPage): array
    {
        $this->ensureSchema();

        $params = [];
        $where = '1 = 1';
        $search = trim($search);
        if ($search !== '') {
            $where = '(
                v.nr_inmatriculare LIKE :q_vehicle
                OR a.authorization_type LIKE :q_type
                OR z.name LIKE :q_zone
            )';
            $params[':q_vehicle'] = '%' . $search . '%';
            $params[':q_type'] = '%' . $search . '%';
            $params[':q_zone'] = '%' . $search . '%';
        }

        $countStmt = $this->db->prepare(
            'SELECT COUNT(*)
             FROM vehicle_authorizations a
             INNER JOIN vehicule v ON v.id = a.vehicle_id
             INNER JOIN authorization_zones z ON z.id = a.zone_id
             WHERE ' . $where
        );
        foreach ($params as $placeholder => $value) {
            $countStmt->bindValue($placeholder, $value);
        }
        $countStmt->execute();
        $totalRows = (int) $countStmt->fetchColumn();

        $totalPages = max(1, (int) ceil($totalRows / max(1, $perPage)));
        $page = min(max(1, $page), $totalPages);
        $offset = ($page - 1) * $perPage;

        $stmt = $this->db->prepare(
            'SELECT a.*,
                    v.nr_inmatriculare,
                    v.marca,
                    v.model,
                    z.name AS zone_name
             FROM vehicle_authorizations a
             INNER JOIN vehicule v ON v.id = a.vehicle_id
             INNER JOIN authorization_zones z ON z.id = a.zone_id
             WHERE ' . $where . '
             ORDER BY a.start_date DESC, a.id DESC
             LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $placeholder => $value) {
            $stmt->bindValue($placeholder, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'rows' => $stmt->fetchAll(),
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
            'total_rows' => $totalRows,
        ];
    }

    public function createAuthorization(array $data): int
    {
        $this->ensureSchema();
        $now = date('Y-m-d H:i:s');

        $stmt = $this->db->prepare(
            'INSERT INTO vehicle_authorizations
                (vehicle_id, authorization_type, zone_id, start_date, end_date, cost, created_at, updated_at)
             VALUES
                (:vehicle_id, :authorization_type, :zone_id, :start_date, :end_date, :cost, :created_at, :updated_at)'
        );
        $this->bindAuthorizationData($stmt, $data);
        $stmt->bindValue(':created_at', $now);
        $stmt->bindValue(':updated_at', $now);
        $stmt->execute();

        return (int) $this->db->lastInsertId();
    }

    public function updateAuthorization(int $id, array $data): bool
    {
        $this->ensureSchema();

        $stmt = $this->db->prepare(
            'UPDATE vehicle_authorizations
             SET vehicle_id = :vehicle_id,
                 authorization_type = :authorization_type,
                 zone_id = :zone_id,
                 start_date = :start_date,
                 end_date = :end_date,
                 cost = :cost,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $this->bindAuthorizationData($stmt, $data);
        $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'));
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function deleteAuthorization(int $id): bool
    {
        $this->ensureSchema();

        $stmt = $this->db->prepare('DELETE FROM vehicle_authorizations WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function addZone(string $name): int
    {
        $this->ensureSchema();
        $name = $this->normalizeZoneName($name);
        $now = date('Y-m-d H:i:s');

        $stmt = $this->db->prepare(
            'INSERT INTO authorization_zones (name, created_at, updated_at)
             VALUES (:name, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)'
        );
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':created_at', $now);
        $stmt->bindValue(':updated_at', $now);
        $stmt->execute();

        $id = (int) $this->db->lastInsertId();
        if ($id > 0) {
            return $id;
        }

        $existing = $this->db->prepare('SELECT id FROM authorization_zones WHERE name = :name LIMIT 1');
        $existing->bindValue(':name', $name);
        $existing->execute();

        return (int) $existing->fetchColumn();
    }

    public function deleteZone(int $id): bool
    {
        $this->ensureSchema();

        if ($this->zoneUsageCount($id) > 0) {
            throw new RuntimeException('Zona este folosita de autorizatii existente si nu poate fi stearsa.');
        }

        $stmt = $this->db->prepare('DELETE FROM authorization_zones WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function zoneExists(int $id): bool
    {
        $this->ensureSchema();

        $stmt = $this->db->prepare('SELECT 1 FROM authorization_zones WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    public function vehicleExists(int $id): bool
    {
        $this->ensureSchema();

        $stmt = $this->db->prepare(
            'SELECT 1
             FROM vehicule
             WHERE id = :id
               AND status = "activ"
               AND nr_inmatriculare <> "STOC-ANVELOPE"
               AND serie_sasiu <> "STOCANVELOPE00001"
             LIMIT 1'
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    public function normalizeZoneName(string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    }

    private function bindAuthorizationData(PDOStatement $stmt, array $data): void
    {
        $stmt->bindValue(':vehicle_id', (int) $data['vehicle_id'], PDO::PARAM_INT);
        $stmt->bindValue(':authorization_type', trim((string) $data['authorization_type']));
        $stmt->bindValue(':zone_id', (int) $data['zone_id'], PDO::PARAM_INT);
        $stmt->bindValue(':start_date', (string) $data['start_date']);
        $stmt->bindValue(':end_date', (string) $data['end_date']);
        $stmt->bindValue(':cost', number_format((float) $data['cost'], 2, '.', ''));
    }

    private function zoneUsageCount(int $id): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM vehicle_authorizations WHERE zone_id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    private function seedDefaultZones(): void
    {
        $count = (int) $this->db->query('SELECT COUNT(*) FROM authorization_zones')->fetchColumn();
        if ($count > 0) {
            return;
        }

        foreach (['România', 'Bulgaria', 'Ungaria', 'Polonia', 'Cehia'] as $zoneName) {
            $this->addZone($zoneName);
        }
    }
}
