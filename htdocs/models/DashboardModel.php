<?php
declare(strict_types=1);

class DashboardModel extends BaseModel
{
    private const LIGHT_VEHICLE_TYPES = [
        'autoturism',
        'autovehicul',
        'autoutilitara',
    ];

    private const VEHICLE_REASON_PRIORITY = [
        'repair' => 10,
        'expired_documents' => 20,
        'missing_documents' => 30,
        'manual_inactive' => 40,
        'other' => 50,
    ];

    private const DRIVER_REASON_PRIORITY = [
        'medical_leave' => 10,
        'leave' => 20,
        'expired_documents' => 30,
        'missing_documents' => 35,
        'manual_inactive' => 40,
        'other' => 50,
    ];

    private const VEHICLE_REASON_DEFINITIONS = [
        'expired_documents' => [
            'label' => 'Documente expirate',
            'icon' => 'bi-file-earmark-x',
            'tone' => 'danger',
            'show_when_zero' => true,
        ],
        'repair' => [
            'label' => 'În reparație',
            'icon' => 'bi-tools',
            'tone' => 'purple',
            'show_when_zero' => true,
        ],
        'missing_documents' => [
            'label' => 'Documente lipsă',
            'icon' => 'bi-file-earmark-excel',
            'tone' => 'danger',
            'show_when_zero' => true,
        ],
        'manual_inactive' => [
            'label' => 'Dezactivat manual',
            'icon' => 'bi-check-circle',
            'tone' => 'blue',
            'show_when_zero' => true,
        ],
        'other' => [
            'label' => 'Alt motiv',
            'icon' => 'bi-exclamation-circle',
            'tone' => 'muted',
            'show_when_zero' => false,
        ],
    ];

    private const DRIVER_REASON_DEFINITIONS = [
        'leave' => [
            'label' => 'Concediu',
            'icon' => 'bi-calendar2-check',
            'tone' => 'green',
            'show_when_zero' => true,
        ],
        'medical_leave' => [
            'label' => 'Concediu medical',
            'icon' => 'bi-prescription2',
            'tone' => 'danger',
            'show_when_zero' => true,
        ],
        'expired_documents' => [
            'label' => 'Documente expirate',
            'icon' => 'bi-file-earmark-x',
            'tone' => 'danger',
            'show_when_zero' => true,
        ],
        'manual_inactive' => [
            'label' => 'Inactiv',
            'icon' => 'bi-check-circle',
            'tone' => 'blue',
            'show_when_zero' => true,
        ],
        'missing_documents' => [
            'label' => 'Documente lipsă',
            'icon' => 'bi-file-earmark-excel',
            'tone' => 'danger',
            'show_when_zero' => false,
        ],
        'other' => [
            'label' => 'Alt motiv',
            'icon' => 'bi-exclamation-circle',
            'tone' => 'muted',
            'show_when_zero' => false,
        ],
    ];

    public function getVehicleOptions(): array
    {
        $sql = "
            SELECT id, nr_inmatriculare, tip_vehicul
            FROM vehicule
            WHERE nr_inmatriculare <> 'STOC-ANVELOPE'
              AND serie_sasiu <> 'STOCANVELOPE00001'
            ORDER BY nr_inmatriculare ASC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getDashboardOverview(array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);
        $periodRange = $this->getPeriodRange($filters['period']);
        $fuelCost = $this->getFuelCostBreakdown($filters);
        $maintenanceCost = $this->getMaintenanceCostBreakdown($filters);

        return [
            'period_range' => $periodRange,
            'vehicle_status' => $this->getVehicleDashboardStatus($filters),
            'driver_status' => $this->getDriverDashboardStatus($filters),
            'fuel_cost' => $fuelCost,
            'maintenance_cost' => $maintenanceCost,
            'operational_cost' => $this->buildOperationalCostBreakdown($fuelCost, $maintenanceCost, $periodRange),
        ];
    }

    public function getEmptyDashboardOverview(array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);
        $periodRange = $this->getPeriodRange($filters['period']);
        $fuelCost = $this->emptyFuelBreakdown($periodRange);
        $maintenanceCost = $this->emptyMaintenanceBreakdown($periodRange);

        return [
            'period_range' => $periodRange,
            'vehicle_status' => [
                'total' => 0,
                'active' => 0,
                'inactive' => 0,
                'reasons' => array_values($this->buildReasonCounts(self::VEHICLE_REASON_DEFINITIONS)),
                'inactive_rows' => [],
            ],
            'driver_status' => [
                'total' => 0,
                'active' => 0,
                'inactive' => 0,
                'reasons' => array_values($this->buildReasonCounts(self::DRIVER_REASON_DEFINITIONS)),
                'inactive_rows' => [],
            ],
            'fuel_cost' => $fuelCost,
            'maintenance_cost' => $maintenanceCost,
            'operational_cost' => $this->buildOperationalCostBreakdown($fuelCost, $maintenanceCost, $periodRange),
        ];
    }

    public function getPeriodRangeForFilters(array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);

        return $this->getPeriodRange($filters['period']);
    }

    public function getVehicleDashboardStatus(array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);
        $vehicles = $this->getDashboardVehicles($filters['vehicle_id'], $filters['vehicle_category']);
        $vehicleIds = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $vehicles);

        $repairReasons = $this->getVehicleRepairReasonMap($vehicleIds);
        $documentIssues = $this->getVehicleDocumentIssueMap($vehicleIds);
        $reasonCounts = $this->buildReasonCounts(self::VEHICLE_REASON_DEFINITIONS);
        $inactiveRows = [];
        $inactive = 0;

        foreach ($vehicles as $vehicle) {
            $vehicleId = (int) ($vehicle['id'] ?? 0);
            if ($vehicleId <= 0) {
                continue;
            }

            $reasons = [];
            if ((string) ($vehicle['status'] ?? 'activ') === 'inactiv') {
                $reasons[] = $this->buildReason('manual_inactive', $this->firstDate($vehicle['updated_at'] ?? null, $vehicle['created_at'] ?? null), self::VEHICLE_REASON_DEFINITIONS);
            }
            if (isset($repairReasons[$vehicleId])) {
                $reasons[] = $this->buildReason('repair', $repairReasons[$vehicleId], self::VEHICLE_REASON_DEFINITIONS);
            }
            if (isset($documentIssues['expired'][$vehicleId])) {
                $reasons[] = $this->buildReason('expired_documents', $documentIssues['expired'][$vehicleId], self::VEHICLE_REASON_DEFINITIONS);
            }
            if (isset($documentIssues['missing'][$vehicleId])) {
                $reasons[] = $this->buildReason(
                    'missing_documents',
                    $this->firstDate($documentIssues['missing'][$vehicleId], $vehicle['updated_at'] ?? null, $vehicle['created_at'] ?? null),
                    self::VEHICLE_REASON_DEFINITIONS
                );
            }

            $primaryReason = $this->pickPrimaryReason($reasons, self::VEHICLE_REASON_PRIORITY);
            if ($primaryReason === null) {
                continue;
            }

            $inactive++;
            $reasonKey = $primaryReason['key'];
            if (!isset($reasonCounts[$reasonKey])) {
                $reasonCounts[$reasonKey] = $this->buildReasonCountRow($reasonKey, self::VEHICLE_REASON_DEFINITIONS[$reasonKey] ?? self::VEHICLE_REASON_DEFINITIONS['other']);
            }
            $reasonCounts[$reasonKey]['count']++;

            $inactiveRows[] = [
                'id' => $vehicleId,
                'nr_inmatriculare' => (string) ($vehicle['nr_inmatriculare'] ?? ''),
                'marca' => (string) ($vehicle['marca'] ?? ''),
                'model' => (string) ($vehicle['model'] ?? ''),
                'reason_key' => $reasonKey,
                'reason' => $primaryReason['label'],
                'reason_icon' => $primaryReason['icon'],
                'reason_tone' => $primaryReason['tone'],
                'date' => $this->firstDate($primaryReason['date'] ?? null, $vehicle['updated_at'] ?? null, $vehicle['created_at'] ?? null),
                'sort_date' => $this->firstDate($primaryReason['date'] ?? null, $vehicle['updated_at'] ?? null, $vehicle['created_at'] ?? null) ?? '0000-00-00',
            ];
        }

        $this->sortInactiveRows($inactiveRows, self::VEHICLE_REASON_PRIORITY);
        $total = count($vehicles);

        return [
            'total' => $total,
            'active' => max(0, $total - $inactive),
            'inactive' => $inactive,
            'reasons' => array_values($reasonCounts),
            'inactive_rows' => array_slice($inactiveRows, 0, 5),
        ];
    }

    public function getDriverDashboardStatus(array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);
        $drivers = $this->getDashboardDrivers($filters['vehicle_id'], $filters['vehicle_category']);
        $driverIds = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $drivers);

        $leaveReasons = $this->getDriverLeaveReasonMap($driverIds);
        $documentIssues = $this->getDriverDocumentIssueMap($driverIds);
        $reasonCounts = $this->buildReasonCounts(self::DRIVER_REASON_DEFINITIONS);
        $inactiveRows = [];
        $inactive = 0;

        foreach ($drivers as $driver) {
            $driverId = (int) ($driver['id'] ?? 0);
            if ($driverId <= 0) {
                continue;
            }

            $reasons = [];
            if (isset($leaveReasons[$driverId])) {
                $reasons[] = $leaveReasons[$driverId];
            }

            $employmentStatus = (string) ($driver['employment_status'] ?? 'active');
            if ((string) ($driver['status'] ?? 'activ') === 'inactiv' || in_array($employmentStatus, ['temporarily_inactive', 'suspended', 'leave'], true)) {
                $manualReasonKey = $employmentStatus === 'leave' ? 'leave' : 'manual_inactive';
                $reasons[] = $this->buildReason($manualReasonKey, $this->firstDate($driver['updated_at'] ?? null, $driver['created_at'] ?? null), self::DRIVER_REASON_DEFINITIONS);
            }

            if (isset($documentIssues['expired'][$driverId])) {
                $reasons[] = $this->buildReason('expired_documents', $documentIssues['expired'][$driverId], self::DRIVER_REASON_DEFINITIONS);
            }
            if (isset($documentIssues['missing'][$driverId])) {
                $reasons[] = $this->buildReason(
                    'missing_documents',
                    $this->firstDate($documentIssues['missing'][$driverId], $driver['updated_at'] ?? null, $driver['created_at'] ?? null),
                    self::DRIVER_REASON_DEFINITIONS
                );
            }

            $primaryReason = $this->pickPrimaryReason($reasons, self::DRIVER_REASON_PRIORITY);
            if ($primaryReason === null) {
                continue;
            }

            $inactive++;
            $reasonKey = $primaryReason['key'];
            if (!isset($reasonCounts[$reasonKey])) {
                $reasonCounts[$reasonKey] = $this->buildReasonCountRow($reasonKey, self::DRIVER_REASON_DEFINITIONS[$reasonKey] ?? self::DRIVER_REASON_DEFINITIONS['other']);
            }
            $reasonCounts[$reasonKey]['count']++;

            $inactiveRows[] = [
                'id' => $driverId,
                'nume' => (string) ($driver['nume'] ?? ''),
                'reason_key' => $reasonKey,
                'reason' => $primaryReason['label'],
                'reason_icon' => $primaryReason['icon'],
                'reason_tone' => $primaryReason['tone'],
                'date' => $this->firstDate($primaryReason['date'] ?? null, $driver['updated_at'] ?? null, $driver['created_at'] ?? null),
                'sort_date' => $this->firstDate($primaryReason['date'] ?? null, $driver['updated_at'] ?? null, $driver['created_at'] ?? null) ?? '0000-00-00',
            ];
        }

        $this->sortInactiveRows($inactiveRows, self::DRIVER_REASON_PRIORITY);
        $total = count($drivers);

        return [
            'total' => $total,
            'active' => max(0, $total - $inactive),
            'inactive' => $inactive,
            'reasons' => array_values($reasonCounts),
            'inactive_rows' => array_slice($inactiveRows, 0, 5),
        ];
    }

    public function getFuelCostBreakdown(array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);
        $periodRange = $this->getPeriodRange($filters['period']);
        $breakdown = $this->emptyFuelBreakdown($periodRange);

        if (!$this->fuelFillupsTableExists()) {
            return $breakdown;
        }

        $needsVehicleJoin = $filters['vehicle_id'] !== null || $filters['vehicle_category'] !== 'toate';
        $vehicleJoin = $needsVehicleJoin ? "
            INNER JOIN vehicule v
                ON v.nr_inmatriculare = f.vehicle_registration
               AND v.nr_inmatriculare <> 'STOC-ANVELOPE'
               AND v.serie_sasiu <> 'STOCANVELOPE00001'
        " : '';

        $sql = "
            SELECT LOWER(f.fuel_type) AS fuel_type,
                   COALESCE(SUM(f.quantity_liters), 0) AS quantity,
                   COALESCE(SUM(f.total_value), 0) AS value
            FROM fuel_fillups f
            {$vehicleJoin}
            WHERE f.fillup_datetime BETWEEN :datetime_start AND :datetime_end
        ";
        $params = [
            ':datetime_start' => $periodRange['datetime_start'],
            ':datetime_end' => $periodRange['datetime_end'],
        ];

        if ($filters['vehicle_id'] !== null) {
            $sql .= $needsVehicleJoin ? ' AND v.id = :vehicle_id' : " AND f.vehicle_registration IN (SELECT nr_inmatriculare FROM vehicule WHERE id = :vehicle_id)";
            $params[':vehicle_id'] = $filters['vehicle_id'];
        }

        $vehicleCategoryCondition = $this->vehicleCategoryCondition('v.tip_vehicul', $filters['vehicle_category']);
        if ($vehicleCategoryCondition !== null) {
            $sql .= ' AND ' . $vehicleCategoryCondition;
        }

        $sql .= ' GROUP BY LOWER(f.fuel_type)';

        $stmt = $this->db->prepare($sql);
        $this->bindAll($stmt, $params);
        $stmt->execute();

        foreach ($stmt->fetchAll() as $row) {
            $key = (string) ($row['fuel_type'] ?? '');
            if (!isset($breakdown['rows'][$key])) {
                continue;
            }

            $breakdown['rows'][$key]['quantity'] = (float) ($row['quantity'] ?? 0);
            $breakdown['rows'][$key]['value'] = (float) ($row['value'] ?? 0);
        }

        foreach ($breakdown['rows'] as $row) {
            $breakdown['total_quantity'] += (float) ($row['quantity'] ?? 0);
            $breakdown['total_value'] += (float) ($row['value'] ?? 0);
        }

        return $breakdown;
    }

    public function getMaintenanceCostBreakdown(array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);
        $periodRange = $this->getPeriodRange($filters['period']);
        $breakdown = $this->emptyMaintenanceBreakdown($periodRange);

        if (!$this->tableExists('mentenanta')) {
            return $breakdown;
        }

        $needsVehicleJoin = $filters['vehicle_category'] !== 'toate';
        $vehicleJoin = $needsVehicleJoin ? ' INNER JOIN vehicule v ON v.id = m.vehicle_id' : '';

        $sql = "
            SELECT CASE WHEN m.record_type = 'reparatie' THEN 'reparatie' ELSE 'intretinere' END AS category,
                   COALESCE(SUM(m.cost), 0) AS value
            FROM mentenanta m
            {$vehicleJoin}
            WHERE m.data_interventie BETWEEN :date_start AND :date_end
              AND COALESCE(m.status_interventie, 'finalizata') <> 'anulata'
        ";
        $params = [
            ':date_start' => $periodRange['date_start'],
            ':date_end' => $periodRange['date_end'],
        ];

        if ($filters['vehicle_id'] !== null) {
            $sql .= ' AND m.vehicle_id = :vehicle_id';
            $params[':vehicle_id'] = $filters['vehicle_id'];
        }

        $vehicleCategoryCondition = $this->vehicleCategoryCondition('v.tip_vehicul', $filters['vehicle_category']);
        if ($vehicleCategoryCondition !== null) {
            $sql .= ' AND ' . $vehicleCategoryCondition;
        }

        $sql .= " GROUP BY CASE WHEN m.record_type = 'reparatie' THEN 'reparatie' ELSE 'intretinere' END";

        $stmt = $this->db->prepare($sql);
        $this->bindAll($stmt, $params);
        $stmt->execute();

        foreach ($stmt->fetchAll() as $row) {
            $key = (string) ($row['category'] ?? '');
            if (!isset($breakdown['rows'][$key])) {
                continue;
            }

            $breakdown['rows'][$key]['value'] = (float) ($row['value'] ?? 0);
        }

        foreach ($breakdown['rows'] as $row) {
            $breakdown['total_value'] += (float) ($row['value'] ?? 0);
        }

        return $breakdown;
    }

    public function getKpi(array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);
        $vehicleStatus = $this->getVehicleDashboardStatus($filters);
        $fuelCost = $this->getFuelCostBreakdown($filters);
        $maintenanceCost = $this->getMaintenanceCostBreakdown($filters);

        return [
            'total_vehicule' => (int) ($vehicleStatus['total'] ?? 0),
            'vehicule_active' => (int) ($vehicleStatus['active'] ?? 0),
            'cost_combustibil_luna' => (float) ($fuelCost['total_value'] ?? 0),
            'cost_mentenanta_luna' => (float) ($maintenanceCost['total_value'] ?? 0),
            'cost_mentenanta_30_zile' => $this->getMaintenanceCost($this->getPeriodRange('ultimele_30_zile'), $filters['vehicle_id']),
            'documente_expira_30' => $this->getExpiringDocumentCount($filters['vehicle_id']),
        ];
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

    private function getDashboardVehicles(?int $vehicleId = null, string $vehicleCategory = 'toate'): array
    {
        $vehicleCategory = $this->normalizeVehicleCategory($vehicleCategory);
        $sql = "
            SELECT id, nr_inmatriculare, marca, model, tip_vehicul, status, observatii, created_at, updated_at
            FROM vehicule
            WHERE nr_inmatriculare <> 'STOC-ANVELOPE'
              AND serie_sasiu <> 'STOCANVELOPE00001'
        ";
        $params = [];

        if ($vehicleId !== null) {
            $sql .= ' AND id = :vehicle_id';
            $params[':vehicle_id'] = $vehicleId;
        }

        $vehicleCategoryCondition = $this->vehicleCategoryCondition('tip_vehicul', $vehicleCategory);
        if ($vehicleCategoryCondition !== null) {
            $sql .= ' AND ' . $vehicleCategoryCondition;
        }

        $sql .= ' ORDER BY nr_inmatriculare ASC, id ASC';

        $stmt = $this->db->prepare($sql);
        $this->bindAll($stmt, $params);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    private function getDashboardDrivers(?int $vehicleId = null, string $vehicleCategory = 'toate'): array
    {
        $hasAssignmentTable = $this->tableExists('soferi_vehicule');
        $vehicleCategory = $this->normalizeVehicleCategory($vehicleCategory);
        $hasVehicleCategoryFilter = $vehicleCategory !== 'toate';

        $sql = "
            SELECT DISTINCT s.id, s.nume, s.status, s.employment_status, s.vehicle_id, s.created_at, s.updated_at
            FROM soferi s
            " . ($hasAssignmentTable ? 'LEFT JOIN soferi_vehicule sv ON sv.driver_id = s.id' : '') . "
            " . ($hasVehicleCategoryFilter ? 'LEFT JOIN vehicule direct_vehicle ON direct_vehicle.id = s.vehicle_id' : '') . "
            " . ($hasVehicleCategoryFilter && $hasAssignmentTable ? 'LEFT JOIN vehicule assigned_vehicle ON assigned_vehicle.id = sv.vehicle_id' : '') . "
            WHERE COALESCE(s.employment_status, 'active') <> 'terminated'
              AND s.data_incetare IS NULL
              AND s.termination_date IS NULL
        ";
        $params = [];

        if ($vehicleId !== null) {
            if ($hasAssignmentTable) {
                $sql .= ' AND (s.vehicle_id = :vehicle_id_direct OR sv.vehicle_id = :vehicle_id_assigned)';
                $params[':vehicle_id_direct'] = $vehicleId;
                $params[':vehicle_id_assigned'] = $vehicleId;
            } else {
                $sql .= ' AND s.vehicle_id = :vehicle_id';
                $params[':vehicle_id'] = $vehicleId;
            }
        }

        if ($hasVehicleCategoryFilter) {
            $directVehicleCondition = $this->vehicleCategoryCondition('direct_vehicle.tip_vehicul', $vehicleCategory);

            if ($hasAssignmentTable) {
                $assignedVehicleCondition = $this->vehicleCategoryCondition('assigned_vehicle.tip_vehicul', $vehicleCategory);
                $sql .= " AND (({$directVehicleCondition}) OR ({$assignedVehicleCondition}))";
            } elseif ($directVehicleCondition !== null) {
                $sql .= ' AND ' . $directVehicleCondition;
            }
        }

        $sql .= ' ORDER BY s.nume ASC, s.id ASC';

        $stmt = $this->db->prepare($sql);
        $this->bindAll($stmt, $params);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    private function getVehicleRepairReasonMap(array $vehicleIds): array
    {
        $vehicleIds = $this->positiveIds($vehicleIds);
        if ($vehicleIds === []) {
            return [];
        }

        $map = [];

        if ($this->tableExists('mentenanta_interventii_programate')) {
            $params = [];
            $condition = $this->inCondition('i.vehicle_id', $vehicleIds, $params, 'scheduled_vehicle');
            $sql = "
                SELECT i.vehicle_id, MIN(i.data_programata) AS start_date
                FROM mentenanta_interventii_programate i
                WHERE {$condition}
                  AND i.tip_interventie = 'reparatie'
                  AND i.status_interventie IN ('programata', 'confirmata', 'in_lucru')
                  AND i.data_programata <= CURDATE()
                GROUP BY i.vehicle_id
            ";

            $stmt = $this->db->prepare($sql);
            $this->bindAll($stmt, $params);
            $stmt->execute();
            foreach ($stmt->fetchAll() as $row) {
                $this->mergeReasonDate($map, (int) ($row['vehicle_id'] ?? 0), $row['start_date'] ?? null);
            }
        }

        if ($this->tableExists('mentenanta')) {
            $params = [];
            $condition = $this->inCondition('m.vehicle_id', $vehicleIds, $params, 'repair_vehicle');
            $sql = "
                SELECT m.vehicle_id, MIN(m.data_interventie) AS start_date
                FROM mentenanta m
                WHERE {$condition}
                  AND m.record_type = 'reparatie'
                  AND m.status_interventie IN ('in_lucru', 'in_asteptare')
                  AND m.data_interventie <= CURDATE()
                  AND (m.status_interventie = 'in_lucru' OR COALESCE(m.zile_imobilizare, 0) > 0)
                GROUP BY m.vehicle_id
            ";

            $stmt = $this->db->prepare($sql);
            $this->bindAll($stmt, $params);
            $stmt->execute();
            foreach ($stmt->fetchAll() as $row) {
                $this->mergeReasonDate($map, (int) ($row['vehicle_id'] ?? 0), $row['start_date'] ?? null);
            }
        }

        return $map;
    }

    private function getVehicleDocumentIssueMap(array $vehicleIds): array
    {
        $vehicleIds = $this->positiveIds($vehicleIds);
        if ($vehicleIds === [] || !$this->tableExists('configurare_costuri_documente_vehicule') || !$this->tableExists('documente')) {
            return ['missing' => [], 'expired' => []];
        }

        $params = [];
        $condition = $this->inCondition('v.id', $vehicleIds, $params, 'vehicle_doc');
        $sql = "
            SELECT v.id AS vehicle_id,
                   cfg.document_type,
                   COUNT(d.id) AS document_count,
                   MAX(d.data_expirare) AS latest_expiry
            FROM vehicule v
            INNER JOIN configurare_costuri_documente_vehicule cfg
                ON cfg.vehicle_type = (
                    CASE
                        WHEN v.tip_vehicul = 'autoturism' THEN 'autovehicul'
                        WHEN v.tip_vehicul = 'autoutilitara' THEN 'autovehicul'
                        WHEN v.tip_vehicul = 'semiremorca' THEN 'semiremorca_primar'
                        ELSE v.tip_vehicul
                    END
               )
               AND cfg.requires_expiry = 1
            LEFT JOIN documente d
                ON d.vehicle_id = v.id
               AND LOWER(TRIM(d.tip_document)) = LOWER(TRIM(cfg.document_type))
            WHERE {$condition}
              AND TRIM(cfg.document_type) <> ''
            GROUP BY v.id, cfg.document_type
        ";

        $stmt = $this->db->prepare($sql);
        $this->bindAll($stmt, $params);
        $stmt->execute();

        $today = (new DateTimeImmutable('today'))->format('Y-m-d');
        $issues = ['missing' => [], 'expired' => []];

        foreach ($stmt->fetchAll() as $row) {
            $vehicleId = (int) ($row['vehicle_id'] ?? 0);
            if ($vehicleId <= 0) {
                continue;
            }

            $documentCount = (int) ($row['document_count'] ?? 0);
            $latestExpiry = $this->normalizeDate($row['latest_expiry'] ?? null);

            if ($documentCount <= 0) {
                $this->mergeReasonDate($issues['missing'], $vehicleId, null);
                continue;
            }

            if ($latestExpiry === null || $latestExpiry < $today) {
                $this->mergeReasonDate($issues['expired'], $vehicleId, $latestExpiry);
            }
        }

        return $issues;
    }

    private function getDriverLeaveReasonMap(array $driverIds): array
    {
        $driverIds = $this->positiveIds($driverIds);
        if ($driverIds === [] || !$this->tableExists('concedii')) {
            return [];
        }

        $params = [];
        $condition = $this->inCondition('c.driver_id', $driverIds, $params, 'driver_leave');
        $sql = "
            SELECT c.driver_id, c.tip_concediu, MIN(c.data_inceput) AS start_date
            FROM concedii c
            WHERE {$condition}
              AND c.status = 'aprobat'
              AND CURDATE() BETWEEN c.data_inceput AND c.data_sfarsit
            GROUP BY c.driver_id, c.tip_concediu
        ";

        $stmt = $this->db->prepare($sql);
        $this->bindAll($stmt, $params);
        $stmt->execute();

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $driverId = (int) ($row['driver_id'] ?? 0);
            if ($driverId <= 0) {
                continue;
            }

            $key = (string) ($row['tip_concediu'] ?? '') === 'medical' ? 'medical_leave' : 'leave';
            $candidate = $this->buildReason($key, $row['start_date'] ?? null, self::DRIVER_REASON_DEFINITIONS);
            $existing = $map[$driverId] ?? null;
            if ($existing === null || (self::DRIVER_REASON_PRIORITY[$candidate['key']] ?? 999) < (self::DRIVER_REASON_PRIORITY[$existing['key']] ?? 999)) {
                $map[$driverId] = $candidate;
            }
        }

        return $map;
    }

    private function getDriverDocumentIssueMap(array $driverIds): array
    {
        $driverIds = $this->positiveIds($driverIds);
        if ($driverIds === [] || !$this->tableExists('configurare_documente_obligatorii_soferi') || !$this->tableExists('documente_soferi')) {
            return ['missing' => [], 'expired' => []];
        }

        $params = [];
        $condition = $this->inCondition('s.id', $driverIds, $params, 'driver_doc');
        $sql = "
            SELECT s.id AS driver_id,
                   cfg.document_type,
                   cfg.requires_expiry,
                   COUNT(d.id) AS document_count,
                   MAX(d.data_expirare) AS latest_expiry
            FROM soferi s
            CROSS JOIN configurare_documente_obligatorii_soferi cfg
            LEFT JOIN documente_soferi d
                ON d.driver_id = s.id
               AND LOWER(TRIM(d.tip_document)) = LOWER(TRIM(cfg.document_type))
            WHERE {$condition}
              AND TRIM(cfg.document_type) <> ''
            GROUP BY s.id, cfg.document_type, cfg.requires_expiry
        ";

        $stmt = $this->db->prepare($sql);
        $this->bindAll($stmt, $params);
        $stmt->execute();

        $today = (new DateTimeImmutable('today'))->format('Y-m-d');
        $issues = ['missing' => [], 'expired' => []];

        foreach ($stmt->fetchAll() as $row) {
            $driverId = (int) ($row['driver_id'] ?? 0);
            if ($driverId <= 0) {
                continue;
            }

            $documentCount = (int) ($row['document_count'] ?? 0);
            $requiresExpiry = (int) ($row['requires_expiry'] ?? 1) === 1;
            $latestExpiry = $this->normalizeDate($row['latest_expiry'] ?? null);

            if ($documentCount <= 0) {
                $this->mergeReasonDate($issues['missing'], $driverId, null);
                continue;
            }

            if ($requiresExpiry && ($latestExpiry === null || $latestExpiry < $today)) {
                $this->mergeReasonDate($issues['expired'], $driverId, $latestExpiry);
            }
        }

        return $issues;
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
            'vehicle_category' => $this->normalizeVehicleCategory((string) ($filters['vehicle_category'] ?? 'toate')),
        ];
    }

    private function normalizeVehicleCategory(string $vehicleCategory): string
    {
        $vehicleCategory = strtolower(trim($vehicleCategory));

        return in_array($vehicleCategory, ['toate', 'grele', 'usoare'], true) ? $vehicleCategory : 'toate';
    }

    private function vehicleCategoryCondition(string $column, string $vehicleCategory): ?string
    {
        $vehicleCategory = $this->normalizeVehicleCategory($vehicleCategory);
        if ($vehicleCategory === 'toate') {
            return null;
        }

        $lightTypes = "'" . implode("', '", self::LIGHT_VEHICLE_TYPES) . "'";

        if ($vehicleCategory === 'usoare') {
            return $column . ' IN (' . $lightTypes . ')';
        }

        return $column . ' NOT IN (' . $lightTypes . ')';
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
                $end = $today;
                break;

            case 'luna_curenta':
            default:
                $start = $today->modify('first day of this month');
                $end = $today;
                break;
        }

        return [
            'date_start' => $start->format('Y-m-d'),
            'date_end' => $end->format('Y-m-d'),
            'datetime_start' => $start->format('Y-m-d 00:00:00'),
            'datetime_end' => $end->format('Y-m-d 23:59:59'),
        ];
    }

    private function emptyFuelBreakdown(array $periodRange): array
    {
        return [
            'period_range' => $periodRange,
            'rows' => [
                'motorina' => ['label' => 'Motorină', 'quantity' => 0.0, 'value' => 0.0, 'tone' => 'green'],
                'adblue' => ['label' => 'AdBlue', 'quantity' => 0.0, 'value' => 0.0, 'tone' => 'blue'],
            ],
            'total_quantity' => 0.0,
            'total_value' => 0.0,
        ];
    }

    private function emptyMaintenanceBreakdown(array $periodRange): array
    {
        return [
            'period_range' => $periodRange,
            'rows' => [
                'intretinere' => ['label' => 'Revizii', 'value' => 0.0, 'icon' => 'bi-wrench-adjustable'],
                'reparatie' => ['label' => 'Reparații', 'value' => 0.0, 'icon' => 'bi-tools'],
            ],
            'total_value' => 0.0,
        ];
    }

    private function buildOperationalCostBreakdown(array $fuelCost, array $maintenanceCost, array $periodRange): array
    {
        $fuelTotal = (float) ($fuelCost['total_value'] ?? 0);
        $maintenanceTotal = (float) ($maintenanceCost['total_value'] ?? 0);

        return [
            'period_range' => $periodRange,
            'rows' => [
                'carburant' => [
                    'label' => 'Carburant',
                    'value' => $fuelTotal,
                    'tone' => 'orange',
                    'icon' => 'bi-fuel-pump',
                ],
                'mentenanta' => [
                    'label' => 'Mentenanță',
                    'value' => $maintenanceTotal,
                    'tone' => 'purple',
                    'icon' => 'bi-tools',
                ],
            ],
            'total_value' => $fuelTotal + $maintenanceTotal,
        ];
    }

    private function getMaintenanceCost(array $periodRange, ?int $vehicleId): float
    {
        if (!$this->tableExists('mentenanta')) {
            return 0.0;
        }

        $sql = '
            SELECT COALESCE(SUM(cost), 0)
            FROM mentenanta
            WHERE data_interventie BETWEEN :date_start AND :date_end
              AND COALESCE(status_interventie, "finalizata") <> "anulata"
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

    private function buildReason(string $key, mixed $date = null, ?array $definitions = null): array
    {
        $definitions ??= self::VEHICLE_REASON_DEFINITIONS;
        $definition = $definitions[$key] ?? self::VEHICLE_REASON_DEFINITIONS['other'];

        return [
            'key' => $key,
            'label' => $definition['label'],
            'icon' => $definition['icon'],
            'tone' => $definition['tone'],
            'date' => $this->normalizeDate($date),
        ];
    }

    private function buildReasonCounts(array $definitions): array
    {
        $rows = [];
        foreach ($definitions as $key => $definition) {
            $rows[$key] = $this->buildReasonCountRow($key, $definition);
        }

        return $rows;
    }

    private function buildReasonCountRow(string $key, array $definition): array
    {
        return [
            'key' => $key,
            'label' => (string) ($definition['label'] ?? 'Alt motiv'),
            'icon' => (string) ($definition['icon'] ?? 'bi-exclamation-circle'),
            'tone' => (string) ($definition['tone'] ?? 'muted'),
            'count' => 0,
            'show_when_zero' => (bool) ($definition['show_when_zero'] ?? false),
        ];
    }

    private function pickPrimaryReason(array $reasons, array $priority): ?array
    {
        if ($reasons === []) {
            return null;
        }

        usort($reasons, static function (array $a, array $b) use ($priority): int {
            $priorityA = $priority[$a['key'] ?? 'other'] ?? 999;
            $priorityB = $priority[$b['key'] ?? 'other'] ?? 999;

            if ($priorityA !== $priorityB) {
                return $priorityA <=> $priorityB;
            }

            return strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? ''));
        });

        return $reasons[0];
    }

    private function sortInactiveRows(array &$rows, array $priority): void
    {
        usort($rows, static function (array $a, array $b) use ($priority): int {
            $dateCompare = strcmp((string) ($b['sort_date'] ?? ''), (string) ($a['sort_date'] ?? ''));
            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            return ($priority[$a['reason_key'] ?? 'other'] ?? 999) <=> ($priority[$b['reason_key'] ?? 'other'] ?? 999);
        });
    }

    private function mergeReasonDate(array &$map, int $id, mixed $date): void
    {
        if ($id <= 0) {
            return;
        }

        $normalizedDate = $this->normalizeDate($date);
        if (!isset($map[$id]) || ($normalizedDate !== null && ($map[$id] === null || $normalizedDate < $map[$id]))) {
            $map[$id] = $normalizedDate;
        }
    }

    private function firstDate(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            $date = $this->normalizeDate($value);
            if ($date !== null) {
                return $date;
            }
        }

        return null;
    }

    private function normalizeDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '' || $raw === '0000-00-00' || $raw === '0000-00-00 00:00:00') {
            return null;
        }

        $candidate = substr($raw, 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $candidate)) {
            return null;
        }

        return $candidate;
    }

    private function positiveIds(array $ids): array
    {
        $normalized = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $normalized[$id] = $id;
            }
        }

        return array_values($normalized);
    }

    private function inCondition(string $column, array $ids, array &$params, string $prefix): string
    {
        $placeholders = [];
        foreach ($this->positiveIds($ids) as $index => $id) {
            $placeholder = ':' . $prefix . '_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $id;
        }

        if ($placeholders === []) {
            return '1 = 0';
        }

        return $column . ' IN (' . implode(', ', $placeholders) . ')';
    }

    private function fuelFillupsTableExists(): bool
    {
        return $this->tableExists('fuel_fillups');
    }

    private function tableExists(string $tableName): bool
    {
        static $cache = [];
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
            return false;
        }

        if (array_key_exists($tableName, $cache)) {
            return $cache[$tableName];
        }

        $sql = '
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
        ';

        return $cache[$tableName] = (int) $this->fetchScalar($sql, [':table_name' => $tableName]) > 0;
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
