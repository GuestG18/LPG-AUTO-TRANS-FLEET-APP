<?php
declare(strict_types=1);

class VehicleCouplingModel extends BaseModel
{
    public function getVehicleById(int $vehicleId): ?array
    {
        $stmt = $this->db->prepare('SELECT id, nr_inmatriculare, marca, model, status, tip_vehicul FROM vehicule WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $vehicleId, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function getActiveCouplingByTractor(int $tractorId): ?array
    {
        $sql = "
            SELECT
                vc.*,
                t.nr_inmatriculare AS tractor_nr,
                t.marca AS tractor_marca,
                t.model AS tractor_model,
                s.nr_inmatriculare AS semiremorca_nr,
                s.marca AS semiremorca_marca,
                s.model AS semiremorca_model
            FROM vehicule_cuplaje vc
            INNER JOIN vehicule t ON t.id = vc.tractor_id
            INNER JOIN vehicule s ON s.id = vc.semiremorca_id
            WHERE vc.tractor_id = :tractor_id
              AND vc.activ = 1
            ORDER BY vc.id DESC
            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':tractor_id', $tractorId, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function getActiveCouplingByTrailer(int $trailerId): ?array
    {
        $sql = "
            SELECT
                vc.*,
                t.nr_inmatriculare AS tractor_nr,
                t.marca AS tractor_marca,
                t.model AS tractor_model,
                s.nr_inmatriculare AS semiremorca_nr,
                s.marca AS semiremorca_marca,
                s.model AS semiremorca_model
            FROM vehicule_cuplaje vc
            INNER JOIN vehicule t ON t.id = vc.tractor_id
            INNER JOIN vehicule s ON s.id = vc.semiremorca_id
            WHERE vc.semiremorca_id = :semiremorca_id
              AND vc.activ = 1
            ORDER BY vc.id DESC
            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':semiremorca_id', $trailerId, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function getActiveCouplingLabelsForVehicleIds(array $vehicleIds): array
    {
        $vehicleIds = array_values(array_unique(array_filter(array_map(static fn(mixed $id): int => (int) $id, $vehicleIds))));
        if ($vehicleIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($vehicleIds), '?'));

        $sql = "
            SELECT
                vc.tractor_id,
                vc.semiremorca_id,
                t.nr_inmatriculare AS tractor_nr,
                s.nr_inmatriculare AS semiremorca_nr
            FROM vehicule_cuplaje vc
            INNER JOIN vehicule t ON t.id = vc.tractor_id
            INNER JOIN vehicule s ON s.id = vc.semiremorca_id
            WHERE vc.activ = 1
              AND (vc.tractor_id IN ($placeholders) OR vc.semiremorca_id IN ($placeholders))
            ORDER BY vc.id DESC
        ";

        $stmt = $this->db->prepare($sql);
        $bindIndex = 1;
        foreach ($vehicleIds as $vehicleId) {
            $stmt->bindValue($bindIndex, $vehicleId, PDO::PARAM_INT);
            $bindIndex++;
        }
        foreach ($vehicleIds as $vehicleId) {
            $stmt->bindValue($bindIndex, $vehicleId, PDO::PARAM_INT);
            $bindIndex++;
        }
        $stmt->execute();

        $labels = [];
        foreach ($stmt->fetchAll() as $row) {
            $tractorId = (int) ($row['tractor_id'] ?? 0);
            $trailerId = (int) ($row['semiremorca_id'] ?? 0);

            if ($tractorId > 0 && !isset($labels[$tractorId])) {
                $labels[$tractorId] = 'Semiremorca: ' . (string) ($row['semiremorca_nr'] ?? '-');
            }

            if ($trailerId > 0 && !isset($labels[$trailerId])) {
                $labels[$trailerId] = 'Tractor: ' . (string) ($row['tractor_nr'] ?? '-');
            }
        }

        return $labels;
    }

    public function getActiveAssemblyStatusByVehicleIds(array $vehicleIds): array
    {
        $vehicleIds = array_values(array_unique(array_filter(array_map(static fn(mixed $id): int => (int) $id, $vehicleIds))));
        if ($vehicleIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($vehicleIds), '?'));

        $sql = "
            SELECT
                vc.id,
                vc.tractor_id,
                vc.semiremorca_id,
                t.nr_inmatriculare AS tractor_nr,
                s.nr_inmatriculare AS semiremorca_nr,
                t.status AS tractor_status,
                s.status AS semiremorca_status
            FROM vehicule_cuplaje vc
            INNER JOIN vehicule t ON t.id = vc.tractor_id
            INNER JOIN vehicule s ON s.id = vc.semiremorca_id
            WHERE vc.activ = 1
              AND (vc.tractor_id IN ($placeholders) OR vc.semiremorca_id IN ($placeholders))
            ORDER BY vc.id DESC
        ";

        $stmt = $this->db->prepare($sql);
        $bindIndex = 1;
        foreach ($vehicleIds as $vehicleId) {
            $stmt->bindValue($bindIndex, $vehicleId, PDO::PARAM_INT);
            $bindIndex++;
        }
        foreach ($vehicleIds as $vehicleId) {
            $stmt->bindValue($bindIndex, $vehicleId, PDO::PARAM_INT);
            $bindIndex++;
        }
        $stmt->execute();

        $statusMap = [];

        foreach ($stmt->fetchAll() as $row) {
            $tractorId = (int) ($row['tractor_id'] ?? 0);
            $trailerId = (int) ($row['semiremorca_id'] ?? 0);

            if ($tractorId <= 0 || $trailerId <= 0) {
                continue;
            }

            $assemblyStatus = ((string) ($row['tractor_status'] ?? '') === 'activ' && (string) ($row['semiremorca_status'] ?? '') === 'activ')
                ? 'activ'
                : 'inactiv';

            if (!isset($statusMap[$tractorId])) {
                $statusMap[$tractorId] = [
                    'status' => $assemblyStatus,
                    'partner_id' => $trailerId,
                    'partner_nr' => (string) ($row['semiremorca_nr'] ?? '-'),
                    'tractor_id' => $tractorId,
                    'semiremorca_id' => $trailerId,
                ];
            }

            if (!isset($statusMap[$trailerId])) {
                $statusMap[$trailerId] = [
                    'status' => $assemblyStatus,
                    'partner_id' => $tractorId,
                    'partner_nr' => (string) ($row['tractor_nr'] ?? '-'),
                    'tractor_id' => $tractorId,
                    'semiremorca_id' => $trailerId,
                ];
            }
        }

        return $statusMap;
    }

    public function getActiveAssemblyStatusForVehicle(int $vehicleId): ?array
    {
        if ($vehicleId <= 0) {
            return null;
        }

        $map = $this->getActiveAssemblyStatusByVehicleIds([$vehicleId]);

        return $map[$vehicleId] ?? null;
    }

    public function getTrailerSelectOptions(): array
    {
        $sql = "
            SELECT
                v.id,
                v.nr_inmatriculare,
                v.marca,
                v.model
            FROM vehicule v
            LEFT JOIN vehicule_cuplaje vc ON vc.semiremorca_id = v.id AND vc.activ = 1
            WHERE v.tip_vehicul IN ('semiremorca', 'semiremorca_primar', 'semiremorca_distributie')
              AND v.status = 'activ'
              AND vc.id IS NULL
            ORDER BY v.nr_inmatriculare ASC
        ";

        $stmt = $this->db->query($sql);
        $options = [];

        foreach ($stmt->fetchAll() as $row) {
            $label = (string) $row['nr_inmatriculare'] . ' - ' . (string) $row['marca'] . ' ' . (string) $row['model'];

            $options[(int) $row['id']] = $label;
        }

        return $options;
    }

    public function getTractorSelectOptions(): array
    {
        $sql = "
            SELECT
                v.id,
                v.nr_inmatriculare,
                v.marca,
                v.model,
                v.status,
                s.nr_inmatriculare AS semiremorca_curenta
            FROM vehicule v
            LEFT JOIN vehicule_cuplaje vc ON vc.tractor_id = v.id AND vc.activ = 1
            LEFT JOIN vehicule s ON s.id = vc.semiremorca_id
            WHERE v.tip_vehicul = 'cap_tractor'
            ORDER BY v.nr_inmatriculare ASC
        ";

        $stmt = $this->db->query($sql);
        $options = [];

        foreach ($stmt->fetchAll() as $row) {
            $label = (string) $row['nr_inmatriculare'] . ' - ' . (string) $row['marca'] . ' ' . (string) $row['model'];

            if (!empty($row['semiremorca_curenta'])) {
                $label .= ' (cuplat cu ' . (string) $row['semiremorca_curenta'] . ')';
            }

            if ((string) ($row['status'] ?? '') !== 'activ') {
                $label .= ' [inactiv]';
            }

            $options[(int) $row['id']] = $label;
        }

        return $options;
    }

    public function assignTrailerToTractor(int $tractorId, int $trailerId, ?int $userId): void
    {
        $now = date('Y-m-d H:i:s');

        $this->db->beginTransaction();

        try {
            $closeSql = "
                UPDATE vehicule_cuplaje
                SET activ = 0,
                    data_end = :data_end,
                    updated_at = :updated_at
                WHERE activ = 1
                  AND (tractor_id = :tractor_id OR semiremorca_id = :semiremorca_id)
            ";
            $closeStmt = $this->db->prepare($closeSql);
            $closeStmt->bindValue(':data_end', $now, PDO::PARAM_STR);
            $closeStmt->bindValue(':updated_at', $now, PDO::PARAM_STR);
            $closeStmt->bindValue(':tractor_id', $tractorId, PDO::PARAM_INT);
            $closeStmt->bindValue(':semiremorca_id', $trailerId, PDO::PARAM_INT);
            $closeStmt->execute();

            $insertSql = "
                INSERT INTO vehicule_cuplaje (
                    tractor_id,
                    semiremorca_id,
                    activ,
                    data_start,
                    data_end,
                    created_by,
                    created_at,
                    updated_at
                ) VALUES (
                    :tractor_id,
                    :semiremorca_id,
                    1,
                    :data_start,
                    NULL,
                    :created_by,
                    :created_at,
                    :updated_at
                )
            ";

            $insertStmt = $this->db->prepare($insertSql);
            $insertStmt->bindValue(':tractor_id', $tractorId, PDO::PARAM_INT);
            $insertStmt->bindValue(':semiremorca_id', $trailerId, PDO::PARAM_INT);
            $insertStmt->bindValue(':data_start', $now, PDO::PARAM_STR);
            if ($userId === null || $userId <= 0) {
                $insertStmt->bindValue(':created_by', null, PDO::PARAM_NULL);
            } else {
                $insertStmt->bindValue(':created_by', $userId, PDO::PARAM_INT);
            }
            $insertStmt->bindValue(':created_at', $now, PDO::PARAM_STR);
            $insertStmt->bindValue(':updated_at', $now, PDO::PARAM_STR);
            $insertStmt->execute();

            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    public function detachByTractor(int $tractorId): bool
    {
        $now = date('Y-m-d H:i:s');

        $sql = "
            UPDATE vehicule_cuplaje
            SET activ = 0,
                data_end = :data_end,
                updated_at = :updated_at
            WHERE tractor_id = :tractor_id
              AND activ = 1
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':data_end', $now, PDO::PARAM_STR);
        $stmt->bindValue(':updated_at', $now, PDO::PARAM_STR);
        $stmt->bindValue(':tractor_id', $tractorId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    public function detachByTrailer(int $trailerId): bool
    {
        $now = date('Y-m-d H:i:s');

        $sql = "
            UPDATE vehicule_cuplaje
            SET activ = 0,
                data_end = :data_end,
                updated_at = :updated_at
            WHERE semiremorca_id = :semiremorca_id
              AND activ = 1
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':data_end', $now, PDO::PARAM_STR);
        $stmt->bindValue(':updated_at', $now, PDO::PARAM_STR);
        $stmt->bindValue(':semiremorca_id', $trailerId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    public function getHistoryForVehicle(int $vehicleId, int $limit = 10): array
    {
        $sql = "
            SELECT
                vc.id,
                vc.tractor_id,
                vc.semiremorca_id,
                vc.activ,
                vc.data_start,
                vc.data_end,
                t.nr_inmatriculare AS tractor_nr,
                s.nr_inmatriculare AS semiremorca_nr,
                u.nume AS creat_de
            FROM vehicule_cuplaje vc
            INNER JOIN vehicule t ON t.id = vc.tractor_id
            INNER JOIN vehicule s ON s.id = vc.semiremorca_id
            LEFT JOIN utilizatori u ON u.id = vc.created_by
            WHERE vc.tractor_id = :vehicle_id_tractor OR vc.semiremorca_id = :vehicle_id_semiremorca
            ORDER BY vc.id DESC
            LIMIT :limit_rows
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':vehicle_id_tractor', $vehicleId, PDO::PARAM_INT);
        $stmt->bindValue(':vehicle_id_semiremorca', $vehicleId, PDO::PARAM_INT);
        $stmt->bindValue(':limit_rows', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
