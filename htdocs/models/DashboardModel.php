<?php
declare(strict_types=1);

class DashboardModel extends BaseModel
{
    public function getVehicleOptions(): array
    {
        $sql = '
            SELECT id, nr_inmatriculare
            FROM vehicule
            ORDER BY nr_inmatriculare ASC
        ';

        $stmt = $this->db->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getKpi(array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);
        $periodRange = $this->getPeriodRange($filters['period']);

        $kpi = [];
        $kpi['total_vehicule'] = $this->getVehicleCount($filters['vehicle_id']);
        $kpi['vehicule_active'] = $this->getVehicleCount($filters['vehicle_id'], 'activ');
        $kpi['cost_combustibil_luna'] = $this->getFuelCost($periodRange, $filters['vehicle_id']);
        $kpi['cost_mentenanta_luna'] = $this->getMaintenanceCost($periodRange, $filters['vehicle_id']);
        $kpi['cost_mentenanta_30_zile'] = $this->getMaintenanceCost(
            $this->getPeriodRange('ultimele_30_zile'),
            $filters['vehicle_id']
        );
        $kpi['documente_expira_30'] = $this->getExpiringDocumentCount($filters['vehicle_id']);

        return $kpi;
    }

    public function getExpiringDocuments(int $limit = 8, ?int $vehicleId = null): array
    {
        $sql = '
            SELECT d.id,
                   d.tip_document,
                   d.numar_document,
                   d.data_expirare,
                   v.nr_inmatriculare
            FROM documente d
            INNER JOIN vehicule v ON v.id = d.vehicle_id
            WHERE d.data_expirare BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ';

        $params = [];

        if ($vehicleId !== null) {
            $sql .= ' AND d.vehicle_id = :vehicle_id';
            $params[':vehicle_id'] = $vehicleId;
        }

        $sql .= '
            ORDER BY d.data_expirare ASC
            LIMIT :limit_rows
        ';

        $stmt = $this->db->prepare($sql);
        $this->bindAll($stmt, $params);
        $stmt->bindValue(':limit_rows', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getRecentActivity(int $limit = 10, array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);
        $periodRange = $this->getPeriodRange($filters['period']);

        $sql = '
            SELECT activity.tip,
                   activity.descriere,
                   activity.data_eveniment
            FROM (
                SELECT \'Alimentare\' AS tip,
                       CONCAT(\'Alimentare vehicul \', v.nr_inmatriculare, \' - \', a.cost_total, \' lei\') AS descriere,
                       CONCAT(a.data_alimentare, \' 00:00:00\') AS data_eveniment,
                       a.vehicle_id
                FROM alimentari a
                INNER JOIN vehicule v ON v.id = a.vehicle_id

                UNION ALL

                SELECT \'Mentenanta\' AS tip,
                       CONCAT(\'Interventie \', m.tip_interventie, \' pentru \', v.nr_inmatriculare, \' - \', m.cost, \' lei\') AS descriere,
                       CONCAT(m.data_interventie, \' 00:00:00\') AS data_eveniment,
                       m.vehicle_id
                FROM mentenanta m
                INNER JOIN vehicule v ON v.id = m.vehicle_id

                UNION ALL

                SELECT \'Document\' AS tip,
                       CONCAT(\'Document \', d.tip_document, \' pentru \', v.nr_inmatriculare, \' expira la \', d.data_expirare) AS descriere,
                       d.created_at AS data_eveniment,
                       d.vehicle_id
                FROM documente d
                INNER JOIN vehicule v ON v.id = d.vehicle_id
            ) AS activity
            WHERE activity.data_eveniment BETWEEN :datetime_start AND :datetime_end
        ';

        $params = [
            ':datetime_start' => $periodRange['datetime_start'],
            ':datetime_end' => $periodRange['datetime_end'],
        ];

        if ($filters['vehicle_id'] !== null) {
            $sql .= ' AND activity.vehicle_id = :vehicle_id';
            $params[':vehicle_id'] = $filters['vehicle_id'];
        }

        $sql .= '
            ORDER BY activity.data_eveniment DESC
            LIMIT :limit_rows
        ';

        $stmt = $this->db->prepare($sql);
        $this->bindAll($stmt, $params);
        $stmt->bindValue(':limit_rows', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    private function normalizeFilters(array $filters): array
    {
        $allowedPeriods = ['luna_curenta', 'ultimele_30_zile', 'an_curent'];
        $period = (string) ($filters['period'] ?? 'luna_curenta');

        if (!in_array($period, $allowedPeriods, true)) {
            $period = 'luna_curenta';
        }

        $vehicleId = $filters['vehicle_id'] ?? null;
        if (!is_int($vehicleId) || $vehicleId <= 0) {
            $vehicleId = null;
        }

        return [
            'period' => $period,
            'vehicle_id' => $vehicleId,
        ];
    }

    private function getPeriodRange(string $period): array
    {
        $today = new DateTimeImmutable('today');

        switch ($period) {
            case 'ultimele_30_zile':
                $start = $today->modify('-29 days');
                $end = $today;
                break;

            case 'an_curent':
                $year = (int) $today->format('Y');
                $start = $today->setDate($year, 1, 1);
                $end = $today->setDate($year, 12, 31);
                break;

            case 'luna_curenta':
            default:
                $start = $today->modify('first day of this month');
                $end = $today->modify('last day of this month');
                break;
        }

        return [
            'date_start' => $start->format('Y-m-d'),
            'date_end' => $end->format('Y-m-d'),
            'datetime_start' => $start->format('Y-m-d 00:00:00'),
            'datetime_end' => $end->format('Y-m-d 23:59:59'),
        ];
    }

    private function getVehicleCount(?int $vehicleId, ?string $status = null): int
    {
        $sql = 'SELECT COUNT(*) FROM vehicule WHERE 1 = 1';
        $params = [];

        if ($vehicleId !== null) {
            $sql .= ' AND id = :vehicle_id';
            $params[':vehicle_id'] = $vehicleId;
        }

        if ($status !== null) {
            $sql .= ' AND status = :status';
            $params[':status'] = $status;
        }

        return (int) $this->fetchScalar($sql, $params);
    }

    private function getFuelCost(array $periodRange, ?int $vehicleId): float
    {
        $sql = '
            SELECT COALESCE(SUM(cost_total), 0)
            FROM alimentari
            WHERE data_alimentare BETWEEN :date_start AND :date_end
        ';

        $params = [
            ':date_start' => $periodRange['date_start'],
            ':date_end' => $periodRange['date_end'],
        ];

        if ($vehicleId !== null) {
            $sql .= ' AND vehicle_id = :vehicle_id';
            $params[':vehicle_id'] = $vehicleId;
        }

        return (float) $this->fetchScalar($sql, $params);
    }

    private function getMaintenanceCost(array $periodRange, ?int $vehicleId): float
    {
        $sql = '
            SELECT COALESCE(SUM(cost), 0)
            FROM mentenanta
            WHERE data_interventie BETWEEN :date_start AND :date_end
        ';

        $params = [
            ':date_start' => $periodRange['date_start'],
            ':date_end' => $periodRange['date_end'],
        ];

        if ($vehicleId !== null) {
            $sql .= ' AND vehicle_id = :vehicle_id';
            $params[':vehicle_id'] = $vehicleId;
        }

        return (float) $this->fetchScalar($sql, $params);
    }

    private function getExpiringDocumentCount(?int $vehicleId): int
    {
        $sql = '
            SELECT COUNT(*)
            FROM documente
            WHERE data_expirare BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ';

        $params = [];

        if ($vehicleId !== null) {
            $sql .= ' AND vehicle_id = :vehicle_id';
            $params[':vehicle_id'] = $vehicleId;
        }

        return (int) $this->fetchScalar($sql, $params);
    }

    private function fetchScalar(string $sql, array $params = []): mixed
    {
        $stmt = $this->db->prepare($sql);
        $this->bindAll($stmt, $params);
        $stmt->execute();

        return $stmt->fetchColumn();
    }

    private function bindAll(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $placeholder => $value) {
            if (is_int($value)) {
                $stmt->bindValue($placeholder, $value, PDO::PARAM_INT);
                continue;
            }

            if ($value === null) {
                $stmt->bindValue($placeholder, null, PDO::PARAM_NULL);
                continue;
            }

            $stmt->bindValue($placeholder, (string) $value, PDO::PARAM_STR);
        }
    }
}
