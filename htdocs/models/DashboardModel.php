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
        $periodRange = $this->getPeriodRange($filters['period'], $filters['date_from'], $filters['date_to']);
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
        $periodRange = $this->getPeriodRange($filters['period'], $filters['date_from'], $filters['date_to']);
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

        return $this->getPeriodRange($filters['period'], $filters['date_from'], $filters['date_to']);
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
        $activeBreakdown = [];
        $totalBreakdown = [];

        $units = $this->groupVehiclesIntoFleetUnits($vehicles);

        foreach ($units as $unit) {
            $unitReasons = [];
            foreach ($unit as $member) {
                $memberId = (int) ($member['id'] ?? 0);
                if ($memberId <= 0) {
                    continue;
                }

                foreach ($this->buildVehicleReasons($member, $repairReasons, $documentIssues) as $reason) {
                    $reason['vehicle_id'] = $memberId;
                    $reason['subject_id'] = $memberId;
                    $reason['vehicle_plate'] = (string) ($member['nr_inmatriculare'] ?? '');
                    $unitReasons[] = $reason;
                }
            }

            $primaryReason = $this->pickPrimaryReason($unitReasons, self::VEHICLE_REASON_PRIORITY);
            $this->addUnitToBreakdown($totalBreakdown, $unit, $primaryReason === null);
            if ($primaryReason === null) {
                $this->addUnitToBreakdown($activeBreakdown, $unit, true);
                continue;
            }

            $inactive++;
            $reasonKey = $primaryReason['key'];
            if (!isset($reasonCounts[$reasonKey])) {
                $reasonCounts[$reasonKey] = $this->buildReasonCountRow($reasonKey, self::VEHICLE_REASON_DEFINITIONS[$reasonKey] ?? self::VEHICLE_REASON_DEFINITIONS['other']);
            }
            $reasonCounts[$reasonKey]['count']++;

            $reasonVehicleId = (int) ($primaryReason['vehicle_id'] ?? 0);
            $primaryVehicle = $unit[0];
            foreach ($unit as $member) {
                if ((int) ($member['id'] ?? 0) === $reasonVehicleId) {
                    $primaryVehicle = $member;
                    break;
                }
            }

            $rowDate = $this->firstDate(
                $primaryReason['date'] ?? null,
                $primaryVehicle['updated_at'] ?? null,
                $primaryVehicle['created_at'] ?? null
            );

            $inactiveRows[] = [
                'id' => (int) ($primaryVehicle['id'] ?? 0),
                'nr_inmatriculare' => $this->buildFleetUnitLabel($unit),
                'members' => array_map(static fn(array $member): array => [
                    'id' => (int) ($member['id'] ?? 0),
                    'nr_inmatriculare' => (string) ($member['nr_inmatriculare'] ?? ''),
                    'tip_vehicul' => (string) ($member['tip_vehicul'] ?? ''),
                ], $unit),
                'reason_vehicle_id' => $reasonVehicleId,
                'reason_vehicle' => (string) ($primaryReason['vehicle_plate'] ?? ''),
                'marca' => (string) ($primaryVehicle['marca'] ?? ''),
                'model' => (string) ($primaryVehicle['model'] ?? ''),
                'reason_key' => $reasonKey,
                'reason' => $primaryReason['label'],
                'reason_icon' => $primaryReason['icon'],
                'reason_tone' => $primaryReason['tone'],
                'date' => $rowDate,
                'sort_date' => $rowDate ?? '0000-00-00',
                'issues' => $this->buildIssueDetails($unitReasons, $documentIssues['documents'] ?? [], self::VEHICLE_REASON_PRIORITY),
            ];
        }

        $this->sortInactiveRows($inactiveRows, self::VEHICLE_REASON_PRIORITY);
        $total = count($units);

        return [
            'total' => $total,
            'active' => max(0, $total - $inactive),
            'inactive' => $inactive,
            'reasons' => array_values($reasonCounts),
            'inactive_rows' => array_slice($inactiveRows, 0, 5),
            'inactive_details' => $inactiveRows,
            'active_breakdown' => $this->finalizeBreakdown($activeBreakdown),
            'total_breakdown' => $this->finalizeBreakdown($totalBreakdown),
        ];
    }

    private const ACTIVE_UNIT_TYPES = [
        'ansamblu' => ['label' => 'Cap tractor + semiremorcă', 'icon' => 'bi-truck-flatbed'],
        'cap_tractor' => ['label' => 'Cap tractor (necuplat)', 'icon' => 'bi-truck-front'],
        'camion' => ['label' => 'Camion', 'icon' => 'bi-truck'],
        'semiremorca_primar' => ['label' => 'Semiremorcă primar (necuplată)', 'icon' => 'bi-truck-flatbed'],
        'semiremorca_distributie' => ['label' => 'Semiremorcă distribuție (necuplată)', 'icon' => 'bi-truck-flatbed'],
        'autovehicul' => ['label' => 'Autoturism', 'icon' => 'bi-car-front'],
        'autoutilitara' => ['label' => 'Autoutilitară', 'icon' => 'bi-truck-front'],
        'other' => ['label' => 'Alt tip', 'icon' => 'bi-question-circle'],
    ];

    /**
     * O unitate de flota intra intr-un singur tip, ca suma pe tipuri sa fie egala cu
     * contoarele "Total" / "Active": ansamblul cap tractor + semiremorca se numara o data.
     */
    private function addUnitToBreakdown(array &$breakdown, array $unit, bool $isActive): void
    {
        if (count($unit) > 1) {
            $type = 'ansamblu';
        } else {
            $type = strtolower(trim((string) ($unit[0]['tip_vehicul'] ?? '')));
            $type = match ($type) {
                'autoturism' => 'autovehicul',
                'semiremorca' => 'semiremorca_primar',
                default => $type,
            };
            if (!isset(self::ACTIVE_UNIT_TYPES[$type])) {
                $type = 'other';
            }
        }

        // Capacitatea ansamblului vine de la semiremorca (capul tractor nu are categorie).
        $capacitySource = $unit[0];
        foreach ($unit as $member) {
            if (str_starts_with((string) ($member['tip_vehicul'] ?? ''), 'semiremorca')) {
                $capacitySource = $member;
                break;
            }
        }
        $capacityId = (int) ($capacitySource['categorie_capacitate_id'] ?? 0);
        $capacityLabel = trim((string) ($capacitySource['categorie_capacitate'] ?? ''));
        if ($capacityId <= 0 || $capacityLabel === '') {
            $capacityId = 0;
            $capacityLabel = 'Fără categorie';
        }

        $breakdown[$type] ??= ['key' => $type] + self::ACTIVE_UNIT_TYPES[$type] + ['count' => 0, 'active' => 0, 'inactive' => 0, 'units' => [], 'capacities' => []];
        $breakdown[$type]['count']++;
        $breakdown[$type][$isActive ? 'active' : 'inactive']++;

        $breakdown[$type]['capacities'][$capacityId] ??= [
            'key' => (string) $capacityId,
            'label' => $capacityLabel,
            'order' => $capacityId > 0 ? (int) ($capacitySource['categorie_capacitate_ordine'] ?? 0) : PHP_INT_MAX,
            'count' => 0,
        ];
        $breakdown[$type]['capacities'][$capacityId]['count']++;

        $realCapacity = $capacitySource['capacitate_transport'] ?? null;
        $breakdown[$type]['units'][] = [
            'active' => $isActive,
            'capacity_key' => (string) $capacityId,
            'capacity_label' => $capacityLabel,
            'real_capacity' => is_numeric($realCapacity) && (float) $realCapacity > 0 ? (float) $realCapacity : null,
            'members' => array_map(static fn(array $member): array => [
                'id' => (int) ($member['id'] ?? 0),
                'nr_inmatriculare' => (string) ($member['nr_inmatriculare'] ?? ''),
                'tip_vehicul' => (string) ($member['tip_vehicul'] ?? ''),
            ], $unit),
        ];
    }

    private function finalizeBreakdown(array $breakdown): array
    {
        $order = array_flip(array_keys(self::ACTIVE_UNIT_TYPES));
        foreach ($breakdown as &$row) {
            usort($row['units'], static fn(array $a, array $b): int =>
                strnatcasecmp($a['members'][0]['nr_inmatriculare'] ?? '', $b['members'][0]['nr_inmatriculare'] ?? ''));
            // Categoriile in ordinea din Categorii capacitate, apoi natural dupa nume.
            usort($row['capacities'], static fn(array $a, array $b): int =>
                ($a['order'] <=> $b['order']) ?: strnatcasecmp($a['label'], $b['label']));
        }
        unset($row);

        uasort($breakdown, static fn(array $a, array $b): int =>
            ($b['count'] <=> $a['count']) ?: (($order[$a['key']] ?? 99) <=> ($order[$b['key']] ?? 99)));

        return array_values($breakdown);
    }

    /**
     * Motivele de inactivitate pentru un singur vehicul, inainte de agregarea pe ansamblu.
     */
    private function buildVehicleReasons(array $vehicle, array $repairReasons, array $documentIssues): array
    {
        $vehicleId = (int) ($vehicle['id'] ?? 0);
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

        return $reasons;
    }

    /**
     * Toate problemele unui vehicul / ansamblu / sofer: motivele de documente se desfac
     * pe documentul concret (ITP, RCA, permis...) cu data expirarii. `subject_id` din
     * fiecare motiv spune in ce set de documente se cauta.
     */
    private function buildIssueDetails(array $reasons, array $documentsBySubject, array $priority): array
    {
        $issues = [];

        foreach ($reasons as $reason) {
            $subjectId = (int) ($reason['subject_id'] ?? 0);
            $base = [
                'vehicle_id' => (int) ($reason['vehicle_id'] ?? 0),
                'vehicle_plate' => (string) ($reason['vehicle_plate'] ?? ''),
                'key' => $reason['key'],
                'reason' => $reason['label'],
                'icon' => $reason['icon'],
                'tone' => $reason['tone'],
                'document' => null,
                'date' => $reason['date'] ?? null,
            ];

            if (!in_array($reason['key'], ['expired_documents', 'missing_documents'], true)) {
                $issues[] = $base;
                continue;
            }

            foreach ($documentsBySubject[$subjectId] ?? [] as $document) {
                if ($document['key'] === $reason['key']) {
                    $issues[] = array_merge($base, ['document' => $document['document'], 'date' => $document['date']]);
                }
            }
        }

        usort($issues, static fn(array $a, array $b): int =>
            ($priority[$a['key']] ?? 999) <=> ($priority[$b['key']] ?? 999)
            ?: strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? '')));

        return $issues;
    }

    /**
     * Grupeaza vehiculele in unitati de flota: un cap tractor cuplat activ cu o
     * semiremorca formeaza o singura unitate, restul raman individuale.
     *
     * @return array<int, array<int, array>>
     */
    private function groupVehiclesIntoFleetUnits(array $vehicles): array
    {
        $vehiclesById = [];
        foreach ($vehicles as $vehicle) {
            $vehicleId = (int) ($vehicle['id'] ?? 0);
            if ($vehicleId > 0) {
                $vehiclesById[$vehicleId] = $vehicle;
            }
        }

        $trailerByTractor = [];
        $tractorByTrailer = [];
        foreach ($this->getActiveCouplingPairs(array_keys($vehiclesById)) as $pair) {
            $tractorId = $pair['tractor_id'];
            $trailerId = $pair['semiremorca_id'];

            if (isset($trailerByTractor[$tractorId]) || isset($tractorByTrailer[$trailerId])) {
                continue;
            }

            $trailerByTractor[$tractorId] = $trailerId;
            $tractorByTrailer[$trailerId] = $tractorId;
        }

        $units = [];
        $consumed = [];

        foreach ($vehicles as $vehicle) {
            $vehicleId = (int) ($vehicle['id'] ?? 0);
            if ($vehicleId <= 0) {
                $units[] = [$vehicle];
                continue;
            }
            if (isset($consumed[$vehicleId])) {
                continue;
            }

            $tractorId = $tractorByTrailer[$vehicleId] ?? $vehicleId;
            $trailerId = $trailerByTractor[$tractorId] ?? null;

            if ($trailerId === null || !isset($vehiclesById[$tractorId], $vehiclesById[$trailerId])) {
                $consumed[$vehicleId] = true;
                $units[] = [$vehicle];
                continue;
            }

            $consumed[$tractorId] = true;
            $consumed[$trailerId] = true;
            $units[] = [$vehiclesById[$tractorId], $vehiclesById[$trailerId]];
        }

        return $units;
    }

    /**
     * Cuplajele active in care ambii membri fac parte din setul filtrat.
     *
     * @return array<int, array{tractor_id: int, semiremorca_id: int}>
     */
    private function getActiveCouplingPairs(array $vehicleIds): array
    {
        $vehicleIds = $this->positiveIds($vehicleIds);
        if ($vehicleIds === [] || !$this->tableExists('vehicule_cuplaje')) {
            return [];
        }

        $params = [];
        $tractorCondition = $this->inCondition('vc.tractor_id', $vehicleIds, $params, 'coupling_tractor');
        $trailerCondition = $this->inCondition('vc.semiremorca_id', $vehicleIds, $params, 'coupling_trailer');

        $sql = "
            SELECT vc.tractor_id, vc.semiremorca_id
            FROM vehicule_cuplaje vc
            WHERE vc.activ = 1
              AND {$tractorCondition}
              AND {$trailerCondition}
            ORDER BY vc.id ASC
        ";

        $stmt = $this->db->prepare($sql);
        $this->bindAll($stmt, $params);
        $stmt->execute();

        $pairs = [];
        foreach ($stmt->fetchAll() as $row) {
            $tractorId = (int) ($row['tractor_id'] ?? 0);
            $trailerId = (int) ($row['semiremorca_id'] ?? 0);
            if ($tractorId > 0 && $trailerId > 0 && $tractorId !== $trailerId) {
                $pairs[] = ['tractor_id' => $tractorId, 'semiremorca_id' => $trailerId];
            }
        }

        return $pairs;
    }

    private function buildFleetUnitLabel(array $unit): string
    {
        $plates = [];
        foreach ($unit as $member) {
            $plate = trim((string) ($member['nr_inmatriculare'] ?? ''));
            if ($plate !== '') {
                $plates[] = $plate;
            }
        }

        return $plates === [] ? '' : implode(' + ', $plates);
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

            $reasons = array_map(static fn(array $reason): array => $reason + ['subject_id' => $driverId], $reasons);

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
                'issues' => $this->buildIssueDetails($reasons, $documentIssues['documents'] ?? [], self::DRIVER_REASON_PRIORITY),
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
            'inactive_details' => $inactiveRows,
        ];
    }

    private const DOCUMENT_VEHICLE_TYPE_LABELS = [
        'cap_tractor' => 'Cap tractor',
        'camion' => 'Camion',
        'semiremorca_primar' => 'Semiremorcă primar',
        'semiremorca_distributie' => 'Semiremorcă distribuție',
        'autoutilitara' => 'Autoutilitară',
        'autovehicul' => 'Autoturism',
    ];

    /**
     * Costul documentelor din Configurare costuri, pe perioada Dashboard-ului, cu logica
     * aplicației (DocumentModel::getVehicleDocumentDailyCost): lei/zi = cost / valabilitate,
     * override-ul per vehicul are prioritate față de prețul pe tip de vehicul; perioada =
     * zilele din intervalul filtrului. Vehiculele și șoferii sunt cei numărați în cardurile
     * Status (aceleași filtre), doar cei activi. Documentele fără preț se raportează separat.
     */
    public function getDocumentCostBreakdown(array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);
        $range = $this->getPeriodRange($filters['period'], $filters['date_from'], $filters['date_to']);
        $days = (int) (new DateTimeImmutable($range['date_start']))->diff(new DateTimeImmutable($range['date_end']))->format('%a') + 1;

        return [
            'days' => $days,
            'vehicles' => $this->getVehicleDocumentCosts($filters, $days),
            'drivers' => $this->getDriverDocumentCosts($filters, $days),
        ];
    }

    /**
     * Dotările montate pe vehiculele din filtrul Dashboard-ului (inventar_dotari_vehicule),
     * pe categorie din catalog și pe produs. Valoarea = costul unității (costul propriu
     * sau costul implicit din catalog); costul lunar = valoare ÷ interval de inspecție
     * (implicit 12 luni) — aceeași formulă ca elementul "Dotări" din Cost operațional / km.
     * Costul perioadei = costul lunar × 12 ÷ 365 × zilele din filtru (și pentru o singură zi).
     */
    public function getEquipmentCostBreakdown(array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);
        $range = $this->getPeriodRange($filters['period'], $filters['date_from'], $filters['date_to']);
        $days = (int) (new DateTimeImmutable($range['date_start']))->diff(new DateTimeImmutable($range['date_end']))->format('%a') + 1;
        $perDay = 12 / 365;
        $result = ['available' => false, 'total' => 0.0, 'monthly' => 0.0, 'period_total' => 0.0, 'days' => $days, 'units' => 0, 'vehicles' => 0, 'categories' => []];
        if (!$this->tableExists('inventar_dotari_vehicule') || !$this->tableExists('inventar_dotari_catalog')) {
            return $result;
        }
        $result['available'] = true;

        $vehicleIds = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0),
            $this->getDashboardVehicles($filters['vehicle_id'], $filters['vehicle_category']));
        $vehicleIds = $this->positiveIds($vehicleIds);
        if ($vehicleIds === []) {
            return $result;
        }

        $params = [];
        $condition = $this->inCondition('idv.vehicle_id', $vehicleIds, $params, 'equipment_vehicle');
        $stmt = $this->db->prepare("
            SELECT idv.vehicle_id,
                   cat.id AS catalog_id,
                   COALESCE(NULLIF(TRIM(cat.nume), ''), 'Dotare fără catalog') AS nume,
                   COALESCE(NULLIF(TRIM(cat.categorie), ''), 'Fără categorie') AS categorie,
                   CASE WHEN idv.cost > 0 THEN idv.cost ELSE COALESCE(cat.cost_implicit, 0) END AS cost,
                   GREATEST(COALESCE(idv.interval_inspectie_luni, cat.interval_implicit_inspectie_luni, 12), 1) AS interval_luni
            FROM inventar_dotari_vehicule idv
            LEFT JOIN inventar_dotari_catalog cat ON cat.id = idv.catalog_id
            WHERE {$condition}
        ");
        $this->bindAll($stmt, $params);
        $stmt->execute();

        $categories = [];
        $allVehicles = [];
        foreach ($stmt->fetchAll() as $row) {
            $cost = (float) $row['cost'];
            $monthly = $cost / (int) $row['interval_luni'];
            $periodCost = $monthly * $perDay * $days;
            $vehicleId = (int) $row['vehicle_id'];
            $catKey = mb_strtoupper((string) $row['categorie'], 'UTF-8');
            $itemKey = (string) ($row['catalog_id'] ?? ('x' . $row['nume']));

            $categories[$catKey] ??= ['label' => (string) $row['categorie'], 'total' => 0.0, 'monthly' => 0.0, 'period_total' => 0.0, 'units' => 0, 'vehicle_ids' => [], 'items' => []];
            $category = &$categories[$catKey];
            $category['total'] += $cost;
            $category['monthly'] += $monthly;
            $category['period_total'] += $periodCost;
            $category['units']++;
            $category['vehicle_ids'][$vehicleId] = true;
            $category['items'][$itemKey] ??= ['label' => (string) $row['nume'], 'total' => 0.0, 'monthly' => 0.0, 'period_total' => 0.0, 'units' => 0, 'vehicle_ids' => []];
            $category['items'][$itemKey]['total'] += $cost;
            $category['items'][$itemKey]['monthly'] += $monthly;
            $category['items'][$itemKey]['period_total'] += $periodCost;
            $category['items'][$itemKey]['units']++;
            $category['items'][$itemKey]['vehicle_ids'][$vehicleId] = true;
            unset($category);

            $allVehicles[$vehicleId] = true;
            $result['total'] += $cost;
            $result['monthly'] += $monthly;
            $result['period_total'] += $periodCost;
            $result['units']++;
        }

        foreach ($categories as &$category) {
            foreach ($category['items'] as &$item) {
                $item['vehicles'] = count($item['vehicle_ids']);
                unset($item['vehicle_ids']);
            }
            unset($item);
            $category['items'] = array_values($category['items']);
            usort($category['items'], static fn(array $a, array $b): int => $b['period_total'] <=> $a['period_total']);
            $category['vehicles'] = count($category['vehicle_ids']);
            unset($category['vehicle_ids']);
        }
        unset($category);
        uasort($categories, static fn(array $a, array $b): int => $b['period_total'] <=> $a['period_total']);

        $result['categories'] = array_values($categories);
        $result['vehicles'] = count($allVehicles);

        return $result;
    }

    private function getVehicleDocumentCosts(array $filters, int $days): array
    {
        $result = ['total' => 0.0, 'groups' => [], 'unpriced' => 0, 'available' => false];
        if (!$this->tableExists('configurare_costuri_documente_vehicule')) {
            return $result;
        }
        $result['available'] = true;

        $vehicles = [];
        foreach ($this->getDashboardVehicles($filters['vehicle_id'], $filters['vehicle_category']) as $vehicle) {
            if ((string) ($vehicle['status'] ?? 'activ') === 'activ') {
                $vehicles[(int) $vehicle['id']] = $vehicle;
            }
        }
        if ($vehicles === []) {
            return $result;
        }

        $hasOverride = $this->tableExists('configurare_costuri_documente_vehicule_override');
        $params = [];
        $condition = $this->inCondition('v.id', array_keys($vehicles), $params, 'doc_cost_vehicle');
        $typeMatch = "c.vehicle_type = (CASE WHEN v.tip_vehicul = 'autoturism' THEN 'autovehicul'
                                           WHEN v.tip_vehicul = 'semiremorca' THEN 'semiremorca_primar'
                                           ELSE v.tip_vehicul END)";
        $sql = "
            SELECT v.id AS vehicle_id, c.document_type,
                   " . ($hasOverride ? 'COALESCE(o.document_cost, c.document_cost)' : 'c.document_cost') . " AS cost,
                   " . ($hasOverride ? 'COALESCE(o.validity_days, c.validity_days)' : 'c.validity_days') . " AS validity_days
            FROM vehicule v
            INNER JOIN configurare_costuri_documente_vehicule c ON {$typeMatch}
            " . ($hasOverride ? 'LEFT JOIN configurare_costuri_documente_vehicule_override o
                ON o.vehicle_id = v.id AND UPPER(TRIM(o.document_type)) = UPPER(TRIM(c.document_type))' : '') . "
            WHERE {$condition}
        ";
        $stmt = $this->db->prepare($sql);
        $this->bindAll($stmt, $params);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        // Override-uri pentru documente care nu există în configurarea tipului de vehicul.
        if ($hasOverride) {
            $params = [];
            $condition = $this->inCondition('v.id', array_keys($vehicles), $params, 'doc_cost_override');
            $stmt = $this->db->prepare("
                SELECT v.id AS vehicle_id, o.document_type, o.document_cost AS cost, o.validity_days
                FROM vehicule v
                INNER JOIN configurare_costuri_documente_vehicule_override o ON o.vehicle_id = v.id
                WHERE {$condition}
                  AND NOT EXISTS (
                      SELECT 1 FROM configurare_costuri_documente_vehicule c
                      WHERE {$typeMatch} AND UPPER(TRIM(c.document_type)) = UPPER(TRIM(o.document_type))
                  )
            ");
            $this->bindAll($stmt, $params);
            $stmt->execute();
            $rows = array_merge($rows, $stmt->fetchAll());
        }

        $groups = [];
        $unpricedTypes = [];
        foreach ($rows as $row) {
            $vehicle = $vehicles[(int) $row['vehicle_id']] ?? null;
            if ($vehicle === null) {
                continue;
            }
            $type = match ((string) $vehicle['tip_vehicul']) {
                'autoturism' => 'autovehicul',
                'semiremorca' => 'semiremorca_primar',
                default => (string) $vehicle['tip_vehicul'],
            };
            $capacityLabel = trim((string) ($vehicle['categorie_capacitate'] ?? ''));
            $groupKey = $type . '|' . (int) ($vehicle['categorie_capacitate_id'] ?? 0);
            $groups[$groupKey] ??= [
                'key' => $groupKey,
                'label' => (self::DOCUMENT_VEHICLE_TYPE_LABELS[$type] ?? ucfirst(str_replace('_', ' ', $type)))
                    . ($capacityLabel !== '' ? ' · ' . $capacityLabel : ''),
                'icon' => match ($type) {
                    'autovehicul' => 'bi-car-front',
                    'semiremorca_primar', 'semiremorca_distributie' => 'bi-truck-flatbed',
                    'cap_tractor' => 'bi-truck-front',
                    default => 'bi-truck',
                },
                'capacity_order' => $capacityLabel !== '' ? (int) ($vehicle['categorie_capacitate_ordine'] ?? 0) : PHP_INT_MAX,
                'capacity_sum' => 0.0,
                'capacity_count' => 0,
                'vehicle_ids' => [],
                'total' => 0.0,
                'documents' => [],
                'unpriced' => [],
            ];
            $group = &$groups[$groupKey];
            if (!isset($group['vehicle_ids'][(int) $row['vehicle_id']])) {
                $group['vehicle_ids'][(int) $row['vehicle_id']] = true;
                if (is_numeric($vehicle['capacitate_transport'] ?? null) && (float) $vehicle['capacitate_transport'] > 0) {
                    $group['capacity_sum'] += (float) $vehicle['capacitate_transport'];
                    $group['capacity_count']++;
                }
            }

            $docLabel = trim((string) $row['document_type']);
            $docKey = mb_strtoupper($docLabel, 'UTF-8');
            $cost = (float) ($row['cost'] ?? 0);
            $validity = (int) ($row['validity_days'] ?? 0);
            if ($cost <= 0 || $validity <= 0) {
                $group['unpriced'][$docKey] = $docLabel;
                $unpricedTypes[$docKey] = true;
                unset($group);
                continue;
            }

            $value = $cost / $validity * $days;
            $group['documents'][$docKey] ??= ['label' => $docLabel, 'total' => 0.0, 'vehicles' => 0];
            $group['documents'][$docKey]['total'] += $value;
            $group['documents'][$docKey]['vehicles']++;
            $group['total'] += $value;
            $result['total'] += $value;
            unset($group);
        }

        foreach ($groups as &$group) {
            // tipul de document e "fără preț" doar dacă niciun vehicul din grupă nu îl are cu preț
            $group['unpriced'] = array_values(array_diff_key($group['unpriced'], $group['documents']));
            $group['documents'] = array_values($group['documents']);
            usort($group['documents'], static fn(array $a, array $b): int => $b['total'] <=> $a['total']);
            $group['vehicles'] = count($group['vehicle_ids']);
            $group['capacity'] = $group['capacity_count'] > 0 ? $group['capacity_sum'] / $group['capacity_count'] : null;
            unset($group['vehicle_ids'], $group['capacity_sum'], $group['capacity_count']);
        }
        unset($group);

        // Ordonat după capacitate (ordinea din Categorii capacitate, apoi capacitatea medie).
        uasort($groups, static fn(array $a, array $b): int =>
            ($a['capacity_order'] <=> $b['capacity_order'])
            ?: (($a['capacity'] ?? PHP_FLOAT_MAX) <=> ($b['capacity'] ?? PHP_FLOAT_MAX))
            ?: strnatcasecmp($a['label'], $b['label']));

        $result['groups'] = array_values($groups);
        $result['unpriced'] = count($unpricedTypes);

        return $result;
    }

    private function getDriverDocumentCosts(array $filters, int $days): array
    {
        $result = ['total' => 0.0, 'drivers' => [], 'documents' => [], 'drivers_without_cost' => 0, 'available' => false];
        if (!$this->tableExists('configurare_costuri_documente_soferi')) {
            return $result;
        }
        $result['available'] = true;

        $drivers = [];
        foreach ($this->getDashboardDrivers($filters['vehicle_id'], $filters['vehicle_category']) as $driver) {
            if ((string) ($driver['status'] ?? 'activ') === 'activ') {
                $drivers[(int) $driver['id']] = $driver;
            }
        }
        if ($drivers === []) {
            return $result;
        }

        $params = [];
        $condition = $this->inCondition('c.driver_id', array_keys($drivers), $params, 'doc_cost_driver');
        $stmt = $this->db->prepare("
            SELECT c.driver_id, c.document_type, c.document_cost, c.validity_days
            FROM configurare_costuri_documente_soferi c
            WHERE {$condition}
        ");
        $this->bindAll($stmt, $params);
        $stmt->execute();

        $byDriver = [];
        foreach ($stmt->fetchAll() as $row) {
            $cost = (float) ($row['document_cost'] ?? 0);
            $validity = (int) ($row['validity_days'] ?? 0);
            if ($cost <= 0 || $validity <= 0) {
                continue;
            }
            $driverId = (int) $row['driver_id'];
            $docLabel = trim((string) $row['document_type']);
            $docKey = mb_strtoupper($docLabel, 'UTF-8');
            $value = $cost / $validity * $days;

            $byDriver[$driverId] ??= [
                'id' => $driverId,
                'nume' => (string) ($drivers[$driverId]['nume'] ?? ''),
                'total' => 0.0,
                'documents' => [],
            ];
            $byDriver[$driverId]['total'] += $value;
            $byDriver[$driverId]['documents'][] = [
                'label' => $docLabel,
                'total' => $value,
                'cost' => $cost,
                'validity_days' => $validity,
            ];

            $result['documents'][$docKey] ??= ['label' => $docLabel, 'total' => 0.0, 'drivers' => 0];
            $result['documents'][$docKey]['total'] += $value;
            $result['documents'][$docKey]['drivers']++;
            $result['total'] += $value;
        }

        foreach ($byDriver as &$driver) {
            usort($driver['documents'], static fn(array $a, array $b): int => $b['total'] <=> $a['total']);
        }
        unset($driver);
        uasort($byDriver, static fn(array $a, array $b): int => ($b['total'] <=> $a['total']) ?: strnatcasecmp($a['nume'], $b['nume']));

        $result['drivers'] = array_values($byDriver);
        $result['documents'] = array_values($result['documents']);
        usort($result['documents'], static fn(array $a, array $b): int => $b['total'] <=> $a['total']);
        $result['drivers_without_cost'] = count($drivers) - count($byDriver);

        return $result;
    }

    public function getFuelCostBreakdown(array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);
        $periodRange = $this->getPeriodRange($filters['period'], $filters['date_from'], $filters['date_to']);
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
        $periodRange = $this->getPeriodRange($filters['period'], $filters['date_from'], $filters['date_to']);
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
        $periodRange = $this->getPeriodRange($filters['period'], $filters['date_from'], $filters['date_to']);

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
        // Categoria de capacitate (vehicule_categorii_capacitate) e doar pentru grupare;
        // capacitatea reala ramane v.capacitate_transport (vezi fata "pe tip" din dashboard).
        $hasCapacityCategories = $this->tableExists('vehicule_categorii_capacitate');
        $capacitySelect = $hasCapacityCategories
            ? ', v.categorie_capacitate_id, vcc.nume AS categorie_capacitate, vcc.ordine_afisare AS categorie_capacitate_ordine'
            : ', NULL AS categorie_capacitate_id, NULL AS categorie_capacitate, NULL AS categorie_capacitate_ordine';
        $capacityJoin = $hasCapacityCategories
            ? 'LEFT JOIN vehicule_categorii_capacitate vcc ON vcc.id = v.categorie_capacitate_id'
            : '';
        $sql = "
            SELECT v.id, v.nr_inmatriculare, v.marca, v.model, v.tip_vehicul, v.status, v.observatii,
                   v.created_at, v.updated_at, v.capacitate_transport{$capacitySelect}
            FROM vehicule v
            {$capacityJoin}
            WHERE v.nr_inmatriculare <> 'STOC-ANVELOPE'
              AND v.serie_sasiu <> 'STOCANVELOPE00001'
        ";
        $params = [];

        if ($vehicleId !== null) {
            $sql .= ' AND v.id = :vehicle_id';
            $params[':vehicle_id'] = $vehicleId;
        }

        $vehicleCategoryCondition = $this->vehicleCategoryCondition('v.tip_vehicul', $vehicleCategory);
        if ($vehicleCategoryCondition !== null) {
            $sql .= ' AND ' . $vehicleCategoryCondition;
        }

        $sql .= ' ORDER BY v.nr_inmatriculare ASC, v.id ASC';

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
            return ['missing' => [], 'expired' => [], 'documents' => []];
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
        // 'documents' pastreaza documentul concret cu problema, pentru lista din card.
        $issues = ['missing' => [], 'expired' => [], 'documents' => []];

        foreach ($stmt->fetchAll() as $row) {
            $vehicleId = (int) ($row['vehicle_id'] ?? 0);
            if ($vehicleId <= 0) {
                continue;
            }

            $documentCount = (int) ($row['document_count'] ?? 0);
            $latestExpiry = $this->normalizeDate($row['latest_expiry'] ?? null);
            $documentType = trim((string) ($row['document_type'] ?? ''));

            if ($documentCount <= 0) {
                $this->mergeReasonDate($issues['missing'], $vehicleId, null);
                $issues['documents'][$vehicleId][] = ['key' => 'missing_documents', 'document' => $documentType, 'date' => null];
                continue;
            }

            if ($latestExpiry === null || $latestExpiry < $today) {
                $this->mergeReasonDate($issues['expired'], $vehicleId, $latestExpiry);
                $issues['documents'][$vehicleId][] = ['key' => 'expired_documents', 'document' => $documentType, 'date' => $latestExpiry];
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
            return ['missing' => [], 'expired' => [], 'documents' => []];
        }

        $params = [];
        $condition = $this->inCondition('s.id', $driverIds, $params, 'driver_doc');
        // Soferii colaboratori au documentele la firma care ii angajeaza: nu le verificam.
        $collaboratorFilter = (int) $this->fetchScalar("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'soferi' AND COLUMN_NAME = 'tip_colaborare'
        ") > 0 ? "AND s.tip_colaborare <> 'colaborator'" : '';
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
              {$collaboratorFilter}
            GROUP BY s.id, cfg.document_type, cfg.requires_expiry
        ";

        $stmt = $this->db->prepare($sql);
        $this->bindAll($stmt, $params);
        $stmt->execute();

        $today = (new DateTimeImmutable('today'))->format('Y-m-d');
        $issues = ['missing' => [], 'expired' => [], 'documents' => []];

        foreach ($stmt->fetchAll() as $row) {
            $driverId = (int) ($row['driver_id'] ?? 0);
            if ($driverId <= 0) {
                continue;
            }

            $documentCount = (int) ($row['document_count'] ?? 0);
            $requiresExpiry = (int) ($row['requires_expiry'] ?? 1) === 1;
            $latestExpiry = $this->normalizeDate($row['latest_expiry'] ?? null);

            $documentType = trim((string) ($row['document_type'] ?? ''));

            if ($documentCount <= 0) {
                $this->mergeReasonDate($issues['missing'], $driverId, null);
                $issues['documents'][$driverId][] = ['key' => 'missing_documents', 'document' => $documentType, 'date' => null];
                continue;
            }

            if ($requiresExpiry && ($latestExpiry === null || $latestExpiry < $today)) {
                $this->mergeReasonDate($issues['expired'], $driverId, $latestExpiry);
                $issues['documents'][$driverId][] = ['key' => 'expired_documents', 'document' => $documentType, 'date' => $latestExpiry];
            }
        }

        return $issues;
    }

    private function normalizeFilters(array $filters): array
    {
        $allowedPeriods = ['luna_curenta', 'ultimele_30_zile', 'an_curent', 'personalizat'];
        $period = (string) ($filters['period'] ?? 'luna_curenta');

        if (!in_array($period, $allowedPeriods, true)) {
            $period = 'luna_curenta';
        }

        // Perioada personalizata cere ambele capete valide, altfel revine la luna curenta.
        $dateFrom = self::validDate($filters['date_from'] ?? null);
        $dateTo = self::validDate($filters['date_to'] ?? null);
        if ($period !== 'personalizat' || $dateFrom === null || $dateTo === null) {
            $dateFrom = null;
            $dateTo = null;
            if ($period === 'personalizat') {
                $period = 'luna_curenta';
            }
        } elseif ($dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        $vehicleId = $filters['vehicle_id'] ?? null;
        if (!is_int($vehicleId) || $vehicleId <= 0) {
            $vehicleId = null;
        }

        return [
            'period' => $period,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'vehicle_id' => $vehicleId,
            'vehicle_category' => $this->normalizeVehicleCategory((string) ($filters['vehicle_category'] ?? 'toate')),
        ];
    }

    private static function validDate(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value ? $value : null;
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

    private function getPeriodRange(string $period, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $today = new DateTimeImmutable('today');

        switch ($period) {
            case 'personalizat':
                if ($dateFrom !== null && $dateTo !== null) {
                    $start = new DateTimeImmutable($dateFrom);
                    $end = new DateTimeImmutable($dateTo);
                    break;
                }
                $start = $today->modify('first day of this month');
                $end = $today;
                break;

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
                'benzina' => ['label' => 'Benzină', 'quantity' => 0.0, 'value' => 0.0, 'tone' => 'orange'],
                'gpl' => ['label' => 'GPL', 'quantity' => 0.0, 'value' => 0.0, 'tone' => 'purple'],
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
