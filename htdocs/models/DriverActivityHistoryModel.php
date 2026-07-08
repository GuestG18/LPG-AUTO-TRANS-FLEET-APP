<?php
declare(strict_types=1);

class DriverActivityHistoryModel extends BaseModel
{
    public const TRANSPORT_LABELS = [
        'primar' => 'Primar',
        'distributie' => 'Distributie',
        'primar_distributie' => 'Primar + Distributie',
        'compresor' => 'Compresor',
    ];

    private const TRANSPORT_ALIASES = [
        'primar' => ['primar', 'primar_tona', 'primar_km'],
        'distributie' => ['distributie'],
        'primar_distributie' => ['primar_distributie', 'mixt'],
        'compresor' => ['compresor'],
    ];

    private const REPAIR_CATEGORY_LABELS = [
        '1' => '1 Suspensie',
        '2' => '2 Rulare',
        '3' => '3 Franare',
        '4' => '4 Racire',
        '5' => '5 Electrica',
        '6' => '6 Motor',
        '7' => '7 Comfort',
        '8' => '8 Evacuare',
        '9' => '9 Directie',
        '10' => '10 Hidraulic',
        'gas_delivery' => '11-17 Livrare Gaz',
        'other' => 'Altele',
    ];

    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->ensureDriverVehicleAssignmentsSchema();
        $this->ensureEmploymentEndSchema();
    }

    public function getDefaultDriverId(): int
    {
        $stmt = $this->db->query("
            SELECT s.id
            FROM soferi s
            INNER JOIN curse_dispecer c ON c.driver_id = s.id
            GROUP BY s.id, s.status, s.nume
            ORDER BY
                MAX(COALESCE(c.data_inceput, c.data_cursa)) DESC,
                COUNT(c.id) DESC,
                CASE WHEN s.status = 'activ' THEN 0 ELSE 1 END,
                s.nume ASC
            LIMIT 1
        ");
        $driverWithTrips = (int) ($stmt->fetchColumn() ?: 0);
        if ($driverWithTrips > 0) {
            return $driverWithTrips;
        }

        $stmt = $this->db->query("
            SELECT id
            FROM soferi
            ORDER BY CASE WHEN status = 'activ' THEN 0 ELSE 1 END, nume ASC, id ASC
            LIMIT 1
        ");

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    public function getDriverOptions(): array
    {
        return $this->db->query("
            SELECT id, nume, status
            FROM soferi
            ORDER BY CASE WHEN status = 'activ' THEN 0 ELSE 1 END, nume ASC
        ")->fetchAll();
    }

    public function getVehicleOptionsForDriver(int $driverId): array
    {
        if ($driverId <= 0) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT DISTINCT v.id, v.nr_inmatriculare, v.marca, v.model, v.tip_vehicul
            FROM vehicule v
            LEFT JOIN soferi_vehicule sv ON sv.vehicle_id = v.id AND sv.driver_id = :driver_assignment
            LEFT JOIN curse_dispecer c ON c.vehicle_id = v.id AND c.driver_id = :driver_trip
            WHERE sv.driver_id IS NOT NULL OR c.driver_id IS NOT NULL
            ORDER BY v.nr_inmatriculare ASC
        ");
        $stmt->execute([
            ':driver_assignment' => $driverId,
            ':driver_trip' => $driverId,
        ]);

        return $stmt->fetchAll();
    }

    public function getDashboard(int $driverId, array $filters): array
    {
        $driver = $this->getDriver($driverId);
        if ($driver === null) {
            return $this->emptyDashboard($filters);
        }

        $trips = $this->getTripRows($driverId, $filters);
        $fuelRows = $this->getFuelRows($driverId, $filters, $trips);
        $usedVehicleIds = $this->resolveUsedVehicleIds($driverId, $filters, $trips, $fuelRows);
        $repairs = $this->getRepairRows($filters, $usedVehicleIds);
        $documents = $this->getDocumentRows($driverId, $filters, $usedVehicleIds);
        $vehicleRows = $this->buildVehicleRows($usedVehicleIds, $trips, $fuelRows, $repairs);
        $dailyRows = $this->buildDailyRows($filters, $trips, $fuelRows, $repairs);
        $repairAnalytics = $this->buildRepairCategoryAnalytics($repairs);
        $consumption = $this->buildConsumptionSummary($trips, $fuelRows);
        $kpis = $this->buildKpis($trips, $fuelRows, $repairs);

        return [
            'driver' => $driver,
            'filters' => $filters,
            'vehicleOptions' => $this->getVehicleOptionsForDriver($driverId),
            'trips' => $trips,
            'fuelRows' => $fuelRows,
            'repairs' => $repairs,
            'documents' => $documents,
            'vehicleRows' => $vehicleRows,
            'dailyRows' => $dailyRows,
            'repairAnalytics' => $repairAnalytics,
            'consumption' => $consumption,
            'kpis' => $kpis,
            'charts' => $this->buildCharts($filters, $trips, $fuelRows, $repairs),
            'updatedAt' => date('Y-m-d H:i:s'),
        ];
    }

    private function getDriver(int $driverId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT
                s.*,
                COALESCE(s.data_angajare, DATE(s.created_at)) AS data_angajare_calculata,
                GREATEST(0, DATEDIFF(
                    COALESCE(s.data_incetare, CASE WHEN s.status = 'inactiv' THEN DATE(s.updated_at) ELSE CURDATE() END),
                    COALESCE(s.data_angajare, DATE(s.created_at))
                ) + 1) AS active_days
            FROM soferi s
            WHERE s.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $driverId]);
        $driver = $stmt->fetch();
        if (!$driver) {
            return null;
        }

        $license = $this->getDriverLicenseInfo($driverId);
        $driver['license_number'] = $license['number'];
        $driver['license_category'] = $license['category'];

        return $driver;
    }

    private function getDriverLicenseInfo(int $driverId): array
    {
        $selectCustom = $this->columnExists('documente_soferi', 'custom_fields_json')
            ? 'custom_fields_json'
            : 'NULL AS custom_fields_json';

        $stmt = $this->db->prepare("
            SELECT tip_document, numar_document, {$selectCustom}
            FROM documente_soferi
            WHERE driver_id = :driver_id
              AND LOWER(tip_document) LIKE '%permis%'
            ORDER BY data_expirare DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([':driver_id' => $driverId]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['number' => '-', 'category' => '-'];
        }

        $custom = $this->decodeJson((string) ($row['custom_fields_json'] ?? ''));

        return [
            'number' => trim((string) ($row['numar_document'] ?? '')) !== '' ? (string) $row['numar_document'] : '-',
            'category' => $this->findCustomFieldValue($custom, ['categorie', 'categoria', 'category', 'cat']) ?: '-',
        ];
    }

    private function getTripRows(int $driverId, array $filters): array
    {
        $params = [
            ':driver_id' => $driverId,
            ':driver_assignment' => $driverId,
            ':date_start' => (string) $filters['date_start'],
            ':date_end' => (string) $filters['date_end'],
        ];

        $where = [
            'COALESCE(c.data_inceput, c.data_cursa) <= :date_end',
            'COALESCE(c.data_sfarsit, c.data_inceput, c.data_cursa) >= :date_start',
            "(c.driver_id = :driver_id OR (
                c.driver_id IS NULL
                AND EXISTS (
                    SELECT 1
                    FROM soferi_vehicule sv
                    WHERE sv.driver_id = :driver_assignment
                      AND sv.vehicle_id = c.vehicle_id
                )
            ))",
        ];

        if ((int) ($filters['vehicle_id'] ?? 0) > 0) {
            $where[] = 'c.vehicle_id = :vehicle_id';
            $params[':vehicle_id'] = (int) $filters['vehicle_id'];
        }

        $transportSql = $this->transportFilterSql('c.tip_transport', (string) ($filters['transport_type'] ?? ''), $params, 'trip_transport');
        if ($transportSql !== '') {
            $where[] = $transportSql;
        }

        $sql = "
            SELECT
                c.*,
                v.nr_inmatriculare,
                v.marca,
                v.model,
                v.tip_vehicul,
                bt.nume AS beneficiar_nume,
                li.nume AS loc_incarcare_nume,
                zd.nume AS zona_distributie_nume,
                COALESCE(exp.total_cheltuieli, 0) AS total_cheltuieli,
                COALESCE(exp.total_motorina, 0) AS total_motorina,
                COALESCE(exp.total_alte_cheltuieli, 0) AS total_alte_cheltuieli
            FROM curse_dispecer c
            INNER JOIN vehicule v ON v.id = c.vehicle_id
            LEFT JOIN configurare_beneficiari_transport bt ON bt.id = c.beneficiar_id
            LEFT JOIN configurare_locuri_incarcare li ON li.id = c.loc_incarcare_id
            LEFT JOIN configurare_zone_distributie zd ON zd.id = c.zona_distributie_id
            " . $this->expenseAggregateJoinSql() . "
            WHERE " . implode(' AND ', $where) . "
            ORDER BY c.data_inceput DESC, c.data_sfarsit DESC, c.id DESC
        ";

        $stmt = $this->db->prepare($sql);
        $this->bindParams($stmt, $params);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row = $this->decorateTrip($row);
        }
        unset($row);

        return $rows;
    }

    private function getFuelRows(int $driverId, array $filters, array $trips): array
    {
        $params = [
            ':date_start' => (string) $filters['date_start'],
            ':date_end' => (string) $filters['date_end'],
            ':fuel_exists_driver' => $driverId,
            ':fuel_exists_assignment' => $driverId,
        ];

        $where = [
            "a.tip_inregistrare = 'alimentare'",
            'a.data_alimentare BETWEEN :date_start AND :date_end',
        ];

        if ((int) ($filters['vehicle_id'] ?? 0) > 0) {
            $where[] = 'a.vehicle_id = :fuel_vehicle_id';
            $params[':fuel_vehicle_id'] = (int) $filters['vehicle_id'];
        }

        $association = [];
        $tripIds = array_values(array_unique(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $trips)));
        $tripIds = array_values(array_filter($tripIds, static fn (int $id): bool => $id > 0));
        if ($tripIds !== []) {
            $association[] = 'a.cursa_id IN (' . $this->inClause($params, 'fuel_trip', $tripIds) . ')';
        }

        if ((string) ($filters['transport_type'] ?? '') === '') {
            $association[] = 'a.driver_id = :driver_direct';
            $params[':driver_direct'] = $driverId;
        }

        $existsWhere = [
            'c2.vehicle_id = a.vehicle_id',
            'COALESCE(c2.data_inceput, c2.data_cursa) <= a.data_alimentare',
            'COALESCE(c2.data_sfarsit, c2.data_inceput, c2.data_cursa) >= a.data_alimentare',
            "(c2.driver_id = :fuel_exists_driver OR (
                c2.driver_id IS NULL
                AND EXISTS (
                    SELECT 1
                    FROM soferi_vehicule sv2
                    WHERE sv2.driver_id = :fuel_exists_assignment
                      AND sv2.vehicle_id = c2.vehicle_id
                )
            ))",
        ];

        $transportSql = $this->transportFilterSql('c2.tip_transport', (string) ($filters['transport_type'] ?? ''), $params, 'fuel_transport');
        if ($transportSql !== '') {
            $existsWhere[] = $transportSql;
        }

        if ((int) ($filters['vehicle_id'] ?? 0) > 0) {
            $existsWhere[] = 'c2.vehicle_id = :fuel_exists_vehicle_id';
            $params[':fuel_exists_vehicle_id'] = (int) $filters['vehicle_id'];
        }

        $association[] = 'EXISTS (SELECT 1 FROM curse_dispecer c2 WHERE ' . implode(' AND ', $existsWhere) . ')';
        $where[] = '(' . implode(' OR ', $association) . ')';

        $sql = "
            SELECT
                a.*,
                v.nr_inmatriculare,
                v.marca,
                v.model,
                v.tip_vehicul,
                sr.nume AS record_sofer_nume,
                c.id AS explicit_trip_id,
                c.tip_transport AS explicit_tip_transport,
                c.data_inceput AS explicit_data_inceput,
                c.data_sfarsit AS explicit_data_sfarsit,
                bt.nume AS explicit_beneficiar_nume
            FROM alimentari a
            INNER JOIN vehicule v ON v.id = a.vehicle_id
            LEFT JOIN soferi sr ON sr.id = a.driver_id
            LEFT JOIN curse_dispecer c ON c.id = a.cursa_id
            LEFT JOIN configurare_beneficiari_transport bt ON bt.id = c.beneficiar_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY a.vehicle_id ASC, a.data_alimentare ASC, a.km_bord ASC, a.id ASC
        ";

        $stmt = $this->db->prepare($sql);
        $this->bindParams($stmt, $params);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $tripsByVehicle = [];
        foreach ($trips as $trip) {
            $tripsByVehicle[(int) ($trip['vehicle_id'] ?? 0)][] = $trip;
        }

        foreach ($rows as &$row) {
            $linkedTrip = $this->findTripForFuel($row, $tripsByVehicle);
            $row = $this->decorateFuelRow($row, $linkedTrip);
        }
        unset($row);

        $rows = $this->attachFuelConsumption($rows);
        usort($rows, static function (array $a, array $b): int {
            return strcmp((string) ($b['data_alimentare'] ?? ''), (string) ($a['data_alimentare'] ?? ''))
                ?: ((int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0));
        });

        return $rows;
    }

    private function getRepairRows(array $filters, array $vehicleIds): array
    {
        if ($vehicleIds === []) {
            return [];
        }

        $params = [
            ':date_start' => (string) $filters['date_start'],
            ':date_end' => (string) $filters['date_end'],
        ];
        $where = [
            'm.data_interventie BETWEEN :date_start AND :date_end',
            'm.vehicle_id IN (' . $this->inClause($params, 'repair_vehicle', $vehicleIds) . ')',
        ];

        if ($this->columnExists('mentenanta', 'record_type')) {
            $where[] = "m.record_type = 'reparatie'";
        }

        $technicalSelect = 'NULL AS technical_category_name, NULL AS technical_category_order, NULL AS technical_component_name';
        $technicalJoin = '';
        if (
            $this->tableExists('technical_categories')
            && $this->tableExists('technical_components')
            && $this->columnExists('mentenanta', 'technical_category_id')
            && $this->columnExists('mentenanta', 'technical_component_id')
        ) {
            $technicalSelect = 'tc.name AS technical_category_name, tc.sort_order AS technical_category_order, tcomp.name AS technical_component_name';
            $technicalJoin = '
                LEFT JOIN technical_categories tc ON tc.id = m.technical_category_id
                LEFT JOIN technical_components tcomp ON tcomp.id = m.technical_component_id
            ';
        }

        $recordTypeSelect = $this->columnExists('mentenanta', 'record_type') ? 'm.record_type' : "'reparatie' AS record_type";
        $costLaborSelect = $this->columnExists('mentenanta', 'cost_manopera') ? 'm.cost_manopera' : '0 AS cost_manopera';
        $costPartsSelect = $this->columnExists('mentenanta', 'cost_piese') ? 'm.cost_piese' : '0 AS cost_piese';
        $descriptionSelect = $this->columnExists('mentenanta', 'descriere') ? 'm.descriere' : 'NULL AS descriere';
        $centerSelect = $this->columnExists('mentenanta', 'centru_cost') ? 'm.centru_cost' : 'NULL AS centru_cost';

        $sql = "
            SELECT
                m.id,
                m.vehicle_id,
                m.tip_interventie,
                {$recordTypeSelect},
                {$centerSelect},
                {$descriptionSelect},
                m.data_interventie,
                m.cost,
                {$costLaborSelect},
                {$costPartsSelect},
                m.atelier,
                m.furnizor_piesa,
                m.fisier_original,
                m.fisier_stocat,
                m.observatii,
                v.nr_inmatriculare,
                v.marca,
                v.model,
                v.tip_vehicul,
                " . ($this->columnExists('mentenanta', 'technical_category_id') ? 'm.technical_category_id' : 'NULL AS technical_category_id') . ",
                " . ($this->columnExists('mentenanta', 'technical_component_id') ? 'm.technical_component_id' : 'NULL AS technical_component_id') . ",
                {$technicalSelect}
            FROM mentenanta m
            INNER JOIN vehicule v ON v.id = m.vehicle_id
            {$technicalJoin}
            WHERE " . implode(' AND ', $where) . "
            ORDER BY m.data_interventie DESC, m.id DESC
        ";

        $stmt = $this->db->prepare($sql);
        $this->bindParams($stmt, $params);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row = $this->decorateRepair($row);
        }
        unset($row);

        return $rows;
    }

    private function getDocumentRows(int $driverId, array $filters, array $vehicleIds): array
    {
        $rows = [];
        $params = [
            ':driver_id' => $driverId,
            ':driver_doc_expire_start' => (string) $filters['date_start'],
            ':driver_doc_expire_end' => (string) $filters['date_end'],
            ':driver_doc_created_start' => (string) $filters['date_start'],
            ':driver_doc_created_end' => (string) $filters['date_end'],
            ':driver_doc_updated_start' => (string) $filters['date_start'],
            ':driver_doc_updated_end' => (string) $filters['date_end'],
        ];

        $driverStmt = $this->db->prepare("
            SELECT
                'driver' AS owner_type,
                d.id,
                d.driver_id AS owner_id,
                s.nume AS owner_label,
                d.tip_document,
                d.numar_document,
                d.data_expirare,
                d.fisier_original,
                d.fisier_stocat,
                d.observatii
            FROM documente_soferi d
            INNER JOIN soferi s ON s.id = d.driver_id
            WHERE d.driver_id = :driver_id
              AND (
                d.data_expirare BETWEEN :driver_doc_expire_start AND :driver_doc_expire_end
                OR DATE(d.created_at) BETWEEN :driver_doc_created_start AND :driver_doc_created_end
                OR DATE(d.updated_at) BETWEEN :driver_doc_updated_start AND :driver_doc_updated_end
              )
            ORDER BY d.data_expirare ASC, d.id DESC
        ");
        $driverStmt->execute($params);
        $rows = array_merge($rows, $driverStmt->fetchAll());

        if ($vehicleIds !== []) {
            $vehicleParams = [
                ':vehicle_doc_expire_start' => (string) $filters['date_start'],
                ':vehicle_doc_expire_end' => (string) $filters['date_end'],
                ':vehicle_doc_created_start' => (string) $filters['date_start'],
                ':vehicle_doc_created_end' => (string) $filters['date_end'],
                ':vehicle_doc_updated_start' => (string) $filters['date_start'],
                ':vehicle_doc_updated_end' => (string) $filters['date_end'],
            ];
            $vehicleSql = "
                SELECT
                    'vehicle' AS owner_type,
                    d.id,
                    d.vehicle_id AS owner_id,
                    v.nr_inmatriculare AS owner_label,
                    d.tip_document,
                    d.numar_document,
                    d.data_expirare,
                    d.fisier_original,
                    d.fisier_stocat,
                    d.observatii
                FROM documente d
                INNER JOIN vehicule v ON v.id = d.vehicle_id
                WHERE d.vehicle_id IN (" . $this->inClause($vehicleParams, 'document_vehicle', $vehicleIds) . ")
                  AND (
                    d.data_expirare BETWEEN :vehicle_doc_expire_start AND :vehicle_doc_expire_end
                    OR DATE(d.created_at) BETWEEN :vehicle_doc_created_start AND :vehicle_doc_created_end
                    OR DATE(d.updated_at) BETWEEN :vehicle_doc_updated_start AND :vehicle_doc_updated_end
                  )
                ORDER BY d.data_expirare ASC, d.id DESC
            ";
            $vehicleStmt = $this->db->prepare($vehicleSql);
            $this->bindParams($vehicleStmt, $vehicleParams);
            $vehicleStmt->execute();
            $rows = array_merge($rows, $vehicleStmt->fetchAll());
        }

        usort($rows, static function (array $a, array $b): int {
            return strcmp((string) ($a['data_expirare'] ?? '9999-12-31'), (string) ($b['data_expirare'] ?? '9999-12-31'));
        });

        return $rows;
    }

    private function resolveUsedVehicleIds(int $driverId, array $filters, array $trips, array $fuelRows): array
    {
        $ids = [];
        foreach ($trips as $row) {
            $vehicleId = (int) ($row['vehicle_id'] ?? 0);
            if ($vehicleId > 0) {
                $ids[$vehicleId] = $vehicleId;
            }
        }
        foreach ($fuelRows as $row) {
            $vehicleId = (int) ($row['vehicle_id'] ?? 0);
            if ($vehicleId > 0) {
                $ids[$vehicleId] = $vehicleId;
            }
        }

        if ($ids === []) {
            foreach ($this->getAssignedVehicleIds($driverId, (int) ($filters['vehicle_id'] ?? 0)) as $vehicleId) {
                $ids[$vehicleId] = $vehicleId;
            }
        }

        if ((int) ($filters['vehicle_id'] ?? 0) > 0) {
            $selected = (int) $filters['vehicle_id'];
            $ids = isset($ids[$selected]) ? [$selected => $selected] : [];
        }

        return array_values($ids);
    }

    private function getAssignedVehicleIds(int $driverId, int $vehicleFilter = 0): array
    {
        $params = [':driver_id' => $driverId];
        $where = ['sv.driver_id = :driver_id'];
        if ($vehicleFilter > 0) {
            $where[] = 'sv.vehicle_id = :vehicle_id';
            $params[':vehicle_id'] = $vehicleFilter;
        }

        $stmt = $this->db->prepare("
            SELECT sv.vehicle_id, MAX(COALESCE(sv.is_primary, 0)) AS primary_rank
            FROM soferi_vehicule sv
            WHERE " . implode(' AND ', $where) . "
            GROUP BY sv.vehicle_id
            ORDER BY primary_rank DESC, sv.vehicle_id ASC
        ");
        $stmt->execute($params);

        return array_map('intval', array_column($stmt->fetchAll(), 'vehicle_id'));
    }

    private function buildKpis(array $trips, array $fuelRows, array $repairs): array
    {
        $totalKm = array_sum(array_map(static fn (array $row): float => (float) ($row['effective_km'] ?? 0), $trips));
        $totalFuel = array_sum(array_map(static fn (array $row): float => (float) ($row['litri'] ?? 0), $fuelRows));
        $fuelCost = array_sum(array_map(static fn (array $row): float => (float) ($row['cost_total'] ?? 0), $fuelRows));
        $repairCost = array_sum(array_map(static fn (array $row): float => (float) ($row['cost'] ?? 0), $repairs));
        $tripCost = array_sum(array_map(static fn (array $row): float => (float) ($row['total_cheltuieli'] ?? 0), $trips));
        $durationMinutes = array_sum(array_map(static fn (array $row): int => (int) ($row['duration_minutes_effective'] ?? 0), $trips));

        return [
            'total_trips' => count($trips),
            'total_km' => $totalKm,
            'total_loaded_tons' => array_sum(array_map(static fn (array $row): float => (float) ($row['loaded_tons'] ?? 0), $trips)),
            'total_delivered_tons' => array_sum(array_map(static fn (array $row): float => (float) ($row['delivered_tons'] ?? 0), $trips)),
            'driving_minutes' => $durationMinutes,
            'total_fuel_liters' => $totalFuel,
            'average_consumption' => $totalKm > 0 ? ($totalFuel / $totalKm * 100) : null,
            'fuel_cost' => $fuelCost,
            'repair_cost' => $repairCost,
            'trip_cost' => $tripCost,
            'total_costs' => $fuelCost + $repairCost + $tripCost,
        ];
    }

    private function buildVehicleRows(array $vehicleIds, array $trips, array $fuelRows, array $repairs): array
    {
        if ($vehicleIds === []) {
            return [];
        }

        $params = [];
        $stmt = $this->db->prepare("
            SELECT id, nr_inmatriculare, marca, model, tip_vehicul
            FROM vehicule
            WHERE id IN (" . $this->inClause($params, 'vehicle_row', $vehicleIds) . ")
            ORDER BY nr_inmatriculare ASC
        ");
        $this->bindParams($stmt, $params);
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll() as $vehicle) {
            $id = (int) ($vehicle['id'] ?? 0);
            $rows[$id] = [
                'id' => $id,
                'nr_inmatriculare' => (string) ($vehicle['nr_inmatriculare'] ?? ''),
                'tip_vehicul' => (string) ($vehicle['tip_vehicul'] ?? ''),
                'trips' => 0,
                'kilometers' => 0.0,
                'loaded_tons' => 0.0,
                'delivered_tons' => 0.0,
                'fuel_cost' => 0.0,
                'repair_cost' => 0.0,
                'trip_cost' => 0.0,
                'total_cost' => 0.0,
                'usage_percentage' => 0.0,
            ];
        }

        foreach ($trips as $trip) {
            $id = (int) ($trip['vehicle_id'] ?? 0);
            if (!isset($rows[$id])) {
                continue;
            }
            $rows[$id]['trips']++;
            $rows[$id]['kilometers'] += (float) ($trip['effective_km'] ?? 0);
            $rows[$id]['loaded_tons'] += (float) ($trip['loaded_tons'] ?? 0);
            $rows[$id]['delivered_tons'] += (float) ($trip['delivered_tons'] ?? 0);
            $rows[$id]['trip_cost'] += (float) ($trip['total_cheltuieli'] ?? 0);
        }

        foreach ($fuelRows as $fuel) {
            $id = (int) ($fuel['vehicle_id'] ?? 0);
            if (isset($rows[$id])) {
                $rows[$id]['fuel_cost'] += (float) ($fuel['cost_total'] ?? 0);
            }
        }

        foreach ($repairs as $repair) {
            $id = (int) ($repair['vehicle_id'] ?? 0);
            if (isset($rows[$id])) {
                $rows[$id]['repair_cost'] += (float) ($repair['cost'] ?? 0);
            }
        }

        $totalKm = array_sum(array_map(static fn (array $row): float => (float) $row['kilometers'], $rows));
        foreach ($rows as &$row) {
            $row['total_cost'] = (float) $row['fuel_cost'] + (float) $row['repair_cost'] + (float) $row['trip_cost'];
            $row['usage_percentage'] = $totalKm > 0 ? ((float) $row['kilometers'] / $totalKm * 100) : 0.0;
        }
        unset($row);

        return array_values($rows);
    }

    private function buildDailyRows(array $filters, array $trips, array $fuelRows, array $repairs): array
    {
        $days = [];

        foreach ($trips as $trip) {
            $date = (string) ($trip['data_inceput'] ?? $trip['data_cursa'] ?? '');
            if ($date === '') {
                continue;
            }
            $row = &$days[$date];
            $row = $row ?? $this->emptyDailyRow($date);
            $row['trips']++;
            $row['kilometers'] += (float) ($trip['effective_km'] ?? 0);
            $row['loaded_tons'] += (float) ($trip['loaded_tons'] ?? 0);
            $row['delivered_tons'] += (float) ($trip['delivered_tons'] ?? 0);
            $row['trip_cost'] += (float) ($trip['total_cheltuieli'] ?? 0);
            $row['driving_minutes'] += (int) ($trip['duration_minutes_effective'] ?? 0);
            unset($row);
        }

        foreach ($fuelRows as $fuel) {
            $date = (string) ($fuel['data_alimentare'] ?? '');
            if ($date === '') {
                continue;
            }
            $row = &$days[$date];
            $row = $row ?? $this->emptyDailyRow($date);
            $row['fuel_used'] += (float) ($fuel['litri'] ?? 0);
            $row['fuel_cost'] += (float) ($fuel['cost_total'] ?? 0);
            unset($row);
        }

        foreach ($repairs as $repair) {
            $date = (string) ($repair['data_interventie'] ?? '');
            if ($date === '') {
                continue;
            }
            $row = &$days[$date];
            $row = $row ?? $this->emptyDailyRow($date);
            $row['repair_cost'] += (float) ($repair['cost'] ?? 0);
            unset($row);
        }

        foreach ($days as &$row) {
            $row['total_daily_cost'] = (float) $row['fuel_cost'] + (float) $row['repair_cost'] + (float) $row['trip_cost'];
        }
        unset($row);

        krsort($days);

        return array_values($days);
    }

    private function buildConsumptionSummary(array $trips, array $fuelRows): array
    {
        $totalKm = array_sum(array_map(static fn (array $row): float => (float) ($row['effective_km'] ?? 0), $trips));
        $totalFuel = array_sum(array_map(static fn (array $row): float => (float) ($row['litri'] ?? 0), $fuelRows));
        $fuelCost = array_sum(array_map(static fn (array $row): float => (float) ($row['cost_total'] ?? 0), $fuelRows));

        $kmByVehicle = [];
        $fuelByVehicle = [];
        $fuelByTransport = [];
        foreach ($trips as $trip) {
            $vehicleId = (int) ($trip['vehicle_id'] ?? 0);
            $kmByVehicle[$vehicleId] = ($kmByVehicle[$vehicleId] ?? 0.0) + (float) ($trip['effective_km'] ?? 0);
        }
        foreach ($fuelRows as $fuel) {
            $vehicleId = (int) ($fuel['vehicle_id'] ?? 0);
            $fuelByVehicle[$vehicleId]['label'] = (string) ($fuel['nr_inmatriculare'] ?? '-');
            $fuelByVehicle[$vehicleId]['liters'] = ($fuelByVehicle[$vehicleId]['liters'] ?? 0.0) + (float) ($fuel['litri'] ?? 0);
            $fuelByVehicle[$vehicleId]['cost'] = ($fuelByVehicle[$vehicleId]['cost'] ?? 0.0) + (float) ($fuel['cost_total'] ?? 0);

            $transport = (string) ($fuel['transport_bucket'] ?? '');
            if ($transport === '') {
                $transport = 'unknown';
            }
            $fuelByTransport[$transport]['label'] = self::TRANSPORT_LABELS[$transport] ?? 'Fara cursa';
            $fuelByTransport[$transport]['liters'] = ($fuelByTransport[$transport]['liters'] ?? 0.0) + (float) ($fuel['litri'] ?? 0);
            $fuelByTransport[$transport]['cost'] = ($fuelByTransport[$transport]['cost'] ?? 0.0) + (float) ($fuel['cost_total'] ?? 0);
        }

        foreach ($fuelByVehicle as $vehicleId => &$row) {
            $row['kilometers'] = $kmByVehicle[(int) $vehicleId] ?? 0.0;
            $row['consumption'] = $row['kilometers'] > 0 ? ((float) $row['liters'] / (float) $row['kilometers'] * 100) : null;
        }
        unset($row);

        $highest = null;
        $lowest = null;
        foreach ($fuelByVehicle as $row) {
            if ($row['consumption'] === null) {
                continue;
            }
            if ($highest === null || (float) $row['consumption'] > (float) $highest['consumption']) {
                $highest = $row;
            }
            if ($lowest === null || (float) $row['consumption'] < (float) $lowest['consumption']) {
                $lowest = $row;
            }
        }

        return [
            'total_fuel' => $totalFuel,
            'average_consumption' => $totalKm > 0 ? ($totalFuel / $totalKm * 100) : null,
            'fuel_cost' => $fuelCost,
            'cost_per_km' => $totalKm > 0 ? ($fuelCost / $totalKm) : null,
            'per_vehicle' => array_values($fuelByVehicle),
            'per_transport_type' => array_values($fuelByTransport),
            'highest_consumption' => $highest,
            'lowest_consumption' => $lowest,
        ];
    }

    private function buildRepairCategoryAnalytics(array $repairs): array
    {
        $rows = [];
        foreach (self::REPAIR_CATEGORY_LABELS as $key => $label) {
            $rows[$key] = [
                'key' => $key,
                'label' => $label,
                'count' => 0,
                'total_cost' => 0.0,
                'percentage' => 0.0,
            ];
        }

        foreach ($repairs as $repair) {
            $key = (string) ($repair['repair_category_key'] ?? 'other');
            if (!isset($rows[$key])) {
                $key = 'other';
            }
            $rows[$key]['count']++;
            $rows[$key]['total_cost'] += (float) ($repair['cost'] ?? 0);
        }

        $total = array_sum(array_map(static fn (array $row): float => (float) $row['total_cost'], $rows));
        foreach ($rows as &$row) {
            $row['percentage'] = $total > 0 ? ((float) $row['total_cost'] / $total * 100) : 0.0;
        }
        unset($row);

        return array_values($rows);
    }

    private function buildCharts(array $filters, array $trips, array $fuelRows, array $repairs): array
    {
        $tons = [];
        $transportDistribution = [];
        foreach (self::TRANSPORT_LABELS as $key => $label) {
            $tons[$key] = ['label' => $label, 'loaded' => 0.0, 'delivered' => 0.0];
            $transportDistribution[$key] = ['label' => $label, 'count' => 0];
        }

        foreach ($trips as $trip) {
            $bucket = (string) ($trip['transport_bucket'] ?? 'other');
            if (!isset($tons[$bucket])) {
                continue;
            }
            $tons[$bucket]['loaded'] += (float) ($trip['loaded_tons'] ?? 0);
            $tons[$bucket]['delivered'] += (float) ($trip['delivered_tons'] ?? 0);
            $transportDistribution[$bucket]['count']++;
        }

        $kmTimeline = [];
        foreach ($trips as $trip) {
            [$key, $label] = $this->groupDate((string) ($trip['data_inceput'] ?? ''), (string) $filters['grouping']);
            if ($key === '') {
                continue;
            }
            $kmTimeline[$key]['label'] = $label;
            $kmTimeline[$key]['value'] = ($kmTimeline[$key]['value'] ?? 0.0) + (float) ($trip['effective_km'] ?? 0);
        }
        ksort($kmTimeline);

        $fuelTimeline = [];
        foreach ($fuelRows as $fuel) {
            [$key, $label] = $this->groupDate((string) ($fuel['data_alimentare'] ?? ''), (string) $filters['grouping']);
            if ($key === '') {
                continue;
            }
            $fuelTimeline[$key]['label'] = $label;
            $fuelTimeline[$key]['value'] = ($fuelTimeline[$key]['value'] ?? 0.0) + (float) ($fuel['litri'] ?? 0);
        }
        ksort($fuelTimeline);

        $fuelCost = array_sum(array_map(static fn (array $row): float => (float) ($row['cost_total'] ?? 0), $fuelRows));
        $repairCost = array_sum(array_map(static fn (array $row): float => (float) ($row['cost'] ?? 0), $repairs));
        $tripCost = array_sum(array_map(static fn (array $row): float => (float) ($row['total_cheltuieli'] ?? 0), $trips));

        return [
            'tons' => [
                'labels' => array_column($tons, 'label'),
                'loaded' => array_map(static fn (array $row): float => round((float) $row['loaded'], 2), array_values($tons)),
                'delivered' => array_map(static fn (array $row): float => round((float) $row['delivered'], 2), array_values($tons)),
            ],
            'kilometers_timeline' => [
                'labels' => array_column($kmTimeline, 'label'),
                'values' => array_map(static fn (array $row): float => round((float) $row['value'], 2), array_values($kmTimeline)),
            ],
            'fuel_timeline' => [
                'labels' => array_column($fuelTimeline, 'label'),
                'values' => array_map(static fn (array $row): float => round((float) $row['value'], 2), array_values($fuelTimeline)),
            ],
            'cost_distribution' => [
                'labels' => ['Combustibil', 'Reparatii', 'Costuri curse'],
                'values' => [round($fuelCost, 2), round($repairCost, 2), round($tripCost, 2)],
            ],
            'transport_distribution' => [
                'labels' => array_column($transportDistribution, 'label'),
                'values' => array_map(static fn (array $row): int => (int) $row['count'], array_values($transportDistribution)),
            ],
        ];
    }

    private function decorateTrip(array $row): array
    {
        $row['transport_bucket'] = $this->normalizeTransportBucket((string) ($row['tip_transport'] ?? ''));
        $row['transport_label'] = self::TRANSPORT_LABELS[(string) $row['transport_bucket']] ?? (string) ($row['tip_transport'] ?? '-');
        $row['effective_km'] = $this->effectiveTripKm($row);
        $row['non_billable_km'] = $this->nonBillableKm($row);
        $row['loaded_tons'] = $this->normalizeTons($row['cantitate_incarcata'] ?? null, $row);
        $row['delivered_tons'] = $this->deliveredTons($row);
        $row['duration_minutes_effective'] = $this->durationMinutes($row);
        $row['beneficiary_label'] = trim((string) ($row['beneficiar_nume'] ?? '')) !== '' ? (string) $row['beneficiar_nume'] : '-';

        return $row;
    }

    private function decorateFuelRow(array $row, ?array $linkedTrip): array
    {
        $price = $row['pret_litru'] ?? null;
        if (($price === null || $price === '' || (float) $price <= 0) && (float) ($row['litri'] ?? 0) > 0) {
            $price = (float) ($row['cost_total'] ?? 0) / (float) $row['litri'];
        }

        $transportType = $linkedTrip !== null ? (string) ($linkedTrip['tip_transport'] ?? '') : (string) ($row['explicit_tip_transport'] ?? '');
        $row['pret_litru_calculat'] = $price !== null ? (float) $price : null;
        $row['fuel_type'] = 'Motorina';
        $row['linked_trip_id'] = $linkedTrip !== null ? (int) ($linkedTrip['id'] ?? 0) : (int) ($row['explicit_trip_id'] ?? 0);
        $row['transport_bucket'] = $this->normalizeTransportBucket($transportType);
        $row['transport_label'] = self::TRANSPORT_LABELS[(string) $row['transport_bucket']] ?? '-';
        $row['beneficiary_label'] = $linkedTrip !== null
            ? (string) ($linkedTrip['beneficiary_label'] ?? '-')
            : (trim((string) ($row['explicit_beneficiar_nume'] ?? '')) !== '' ? (string) $row['explicit_beneficiar_nume'] : '-');
        $row['calculated_consumption'] = null;
        $row['consumption_km'] = null;

        return $row;
    }

    private function decorateRepair(array $row): array
    {
        $categoryId = $this->resolveRepairCategoryId($row);
        $categoryKey = $this->repairCategoryKey($categoryId);
        $hierarchy = $this->repairHierarchy($row, $categoryId);

        $row['repair_category_id'] = $categoryId;
        $row['repair_category_key'] = $categoryKey;
        $row['repair_category_label'] = self::REPAIR_CATEGORY_LABELS[$categoryKey] ?? self::REPAIR_CATEGORY_LABELS['other'];
        $row['main_category'] = $hierarchy['main'];
        $row['subcategory'] = $hierarchy['subcategory'];
        $row['component_label'] = trim((string) ($row['technical_component_name'] ?? '')) !== ''
            ? (string) $row['technical_component_name']
            : (trim((string) ($row['tip_interventie'] ?? '')) !== '' ? (string) $row['tip_interventie'] : '-');

        return $row;
    }

    private function attachFuelConsumption(array $rows): array
    {
        $previousKm = [];
        foreach ($rows as &$row) {
            $vehicleId = (int) ($row['vehicle_id'] ?? 0);
            $km = (int) ($row['km_bord'] ?? 0);
            if ($vehicleId <= 0 || $km <= 0) {
                continue;
            }
            if (isset($previousKm[$vehicleId]) && $km > $previousKm[$vehicleId]) {
                $distance = $km - $previousKm[$vehicleId];
                $liters = (float) ($row['litri'] ?? 0);
                $row['consumption_km'] = $distance;
                $row['calculated_consumption'] = $distance > 0 ? ($liters / $distance * 100) : null;
            }
            $previousKm[$vehicleId] = $km;
        }
        unset($row);

        return $rows;
    }

    private function findTripForFuel(array $fuel, array $tripsByVehicle): ?array
    {
        $vehicleId = (int) ($fuel['vehicle_id'] ?? 0);
        $date = (string) ($fuel['data_alimentare'] ?? '');
        if ($vehicleId <= 0 || $date === '') {
            return null;
        }

        foreach ($tripsByVehicle[$vehicleId] ?? [] as $trip) {
            $start = (string) ($trip['data_inceput'] ?? $trip['data_cursa'] ?? '');
            $end = (string) ($trip['data_sfarsit'] ?? $start);
            if ($start !== '' && $end !== '' && $start <= $date && $end >= $date) {
                return $trip;
            }
        }

        return null;
    }

    private function emptyDailyRow(string $date): array
    {
        return [
            'date' => $date,
            'trips' => 0,
            'kilometers' => 0.0,
            'loaded_tons' => 0.0,
            'delivered_tons' => 0.0,
            'fuel_used' => 0.0,
            'fuel_cost' => 0.0,
            'repair_cost' => 0.0,
            'trip_cost' => 0.0,
            'total_daily_cost' => 0.0,
            'driving_minutes' => 0,
        ];
    }

    private function effectiveTripKm(array $row): float
    {
        $total = $this->positiveFloat($row['km_totali'] ?? null);
        if ($total > 0) {
            return $total;
        }

        $course = $this->positiveFloat($row['km_cursa'] ?? null);
        if ($course > 0) {
            return $course;
        }

        return $this->positiveFloat($row['km_dislocare'] ?? null);
    }

    private function nonBillableKm(array $row): float
    {
        $total = $this->positiveFloat($row['km_totali'] ?? null);
        $course = $this->positiveFloat($row['km_cursa'] ?? null);

        return $total > $course ? $total - $course : 0.0;
    }

    private function deliveredTons(array $row): float
    {
        if ((string) ($row['tip_transport'] ?? '') === 'compresor') {
            $delivered = $this->positiveFloat($row['tona_livrata'] ?? null);
            if ($delivered > 0) {
                return $delivered;
            }
        }

        $prelevata = $this->normalizeTons($row['cantitate_prelevata'] ?? null, $row);
        if ($prelevata > 0) {
            return $prelevata;
        }

        $delivered = $this->positiveFloat($row['tona_livrata'] ?? null);
        if ($delivered > 0) {
            return $delivered;
        }

        return $this->normalizeTons($row['cantitate_incarcata'] ?? null, $row);
    }

    private function normalizeTons(mixed $value, array $row = []): float
    {
        $quantity = $this->positiveFloat($value);
        if ($quantity <= 0) {
            return 0.0;
        }

        $capacity = $this->positiveFloat($row['capacitate_transport'] ?? null);
        if ($capacity > 0 && $quantity > ($capacity * 3)) {
            return $quantity / 1000;
        }

        if ($quantity >= 1000) {
            return $quantity / 1000;
        }

        return $quantity;
    }

    private function durationMinutes(array $row): int
    {
        $duration = (int) ($row['durata_cursa_minute'] ?? 0);
        if ($duration > 0) {
            return $duration;
        }

        $date = (string) ($row['data_inceput'] ?? $row['data_cursa'] ?? '');
        $start = trim((string) ($row['ora_inceput'] ?? ''));
        $end = trim((string) ($row['ora_sfarsit'] ?? ''));
        if ($date === '' || $start === '' || $end === '') {
            return 0;
        }

        try {
            $startAt = new DateTimeImmutable($date . ' ' . $start);
            $endAt = new DateTimeImmutable($date . ' ' . $end);
            if ($endAt < $startAt) {
                $endAt = $endAt->modify('+1 day');
            }
            return max(0, (int) round(($endAt->getTimestamp() - $startAt->getTimestamp()) / 60));
        } catch (Throwable) {
            return 0;
        }
    }

    private function resolveRepairCategoryId(array $row): int
    {
        $explicit = (int) ($row['technical_category_order'] ?? 0);
        if ($explicit > 0) {
            return $explicit;
        }

        $explicit = (int) ($row['technical_category_id'] ?? 0);
        if ($explicit > 0 && $explicit <= 18) {
            return $explicit;
        }

        $haystack = mb_strtolower(
            trim((string) ($row['technical_category_name'] ?? '') . ' ' . (string) ($row['centru_cost'] ?? '') . ' ' . (string) ($row['tip_interventie'] ?? '')),
            'UTF-8'
        );

        $map = [
            1 => ['suspens'],
            2 => ['rulare', 'anvelop', 'roata', 'rulment'],
            3 => ['fran', 'brak'],
            4 => ['racir', 'cool', 'radiator'],
            5 => ['electric', 'alternator', 'bater'],
            6 => ['motor', 'engine', 'inject', 'turbin'],
            7 => ['comfort', 'climat', 'scaun'],
            8 => ['evac', 'exhaust', 'adblue'],
            9 => ['direct', 'steer'],
            10 => ['hidraulic', 'hydraulic'],
            11 => ['livrare gaz', 'gaz', 'tank', 'rezervor'],
        ];

        foreach ($map as $id => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    return $id;
                }
            }
        }

        return 0;
    }

    private function repairCategoryKey(int $categoryId): string
    {
        if ($categoryId >= 1 && $categoryId <= 10) {
            return (string) $categoryId;
        }
        if ($categoryId >= 11 && $categoryId <= 17) {
            return 'gas_delivery';
        }

        return 'other';
    }

    private function repairHierarchy(array $row, int $categoryId): array
    {
        if ($categoryId >= 11 && $categoryId <= 17) {
            return ['main' => 'Cisterna', 'subcategory' => 'Livrare gaz'];
        }

        $main = in_array((string) ($row['tip_vehicul'] ?? ''), ['semiremorca', 'semiremorca_primar', 'semiremorca_distributie'], true)
            ? 'Cisterna'
            : 'Camion';

        if ($categoryId === 10) {
            return ['main' => $main, 'subcategory' => 'Hidraulic'];
        }

        if ($categoryId >= 1 && $categoryId <= 9) {
            return ['main' => $main, 'subcategory' => 'Sasiu'];
        }

        return ['main' => 'Altele', 'subcategory' => trim((string) ($row['centru_cost'] ?? '')) ?: 'Altele'];
    }

    private function groupDate(string $date, string $grouping): array
    {
        if ($date === '') {
            return ['', ''];
        }

        try {
            $dt = new DateTimeImmutable($date);
        } catch (Throwable) {
            return ['', ''];
        }

        return match ($grouping) {
            'weekly' => [$dt->format('o-W'), 'S' . $dt->format('W') . ' ' . $dt->format('o')],
            'monthly' => [$dt->format('Y-m'), $dt->format('m.Y')],
            default => [$dt->format('Y-m-d'), $dt->format('d.m')],
        };
    }

    private function normalizeTransportBucket(string $value): string
    {
        $value = strtolower(trim($value));
        foreach (self::TRANSPORT_ALIASES as $bucket => $aliases) {
            if (in_array($value, $aliases, true)) {
                return $bucket;
            }
        }

        return $value;
    }

    private function transportFilterSql(string $column, string $filter, array &$params, string $prefix): string
    {
        $filter = $this->normalizeTransportBucket($filter);
        if ($filter === '' || !isset(self::TRANSPORT_ALIASES[$filter])) {
            return '';
        }

        return $column . ' IN (' . $this->inClause($params, $prefix, self::TRANSPORT_ALIASES[$filter]) . ')';
    }

    private function expenseAggregateJoinSql(): string
    {
        $hasInvoiced = $this->columnExists('curse_cheltuieli', 'refacturare_facturata');
        $refacturedInvoiced = $hasInvoiced
            ? "SUM(CASE WHEN COALESCE(refacturare_facturata, 0) = 1 THEN COALESCE(refacturare_suma, 0) ELSE 0 END)"
            : '0';

        return "
            LEFT JOIN (
                SELECT
                    cursa_id,
                    GREATEST(0, SUM(COALESCE(suma, 0)) - {$refacturedInvoiced}) AS total_cheltuieli,
                    SUM(CASE WHEN tip_cheltuiala = 'motorina' THEN COALESCE(suma, 0) ELSE 0 END) AS total_motorina,
                    SUM(CASE WHEN tip_cheltuiala <> 'motorina' THEN COALESCE(suma, 0) ELSE 0 END) AS total_alte_cheltuieli
                FROM curse_cheltuieli
                GROUP BY cursa_id
            ) exp ON exp.cursa_id = c.id
        ";
    }

    private function inClause(array &$params, string $prefix, array $values): string
    {
        $placeholders = [];
        $index = 0;
        foreach (array_values($values) as $value) {
            $placeholder = ':' . $prefix . '_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = is_int($value) ? $value : (string) $value;
            $index++;
        }

        return implode(', ', $placeholders);
    }

    private function bindParams(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }

    private function positiveFloat(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        return max(0.0, (float) $value);
    }

    private function decodeJson(string $json): mixed
    {
        $json = trim($json);
        if ($json === '') {
            return null;
        }

        $decoded = json_decode($json, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    private function findCustomFieldValue(mixed $payload, array $needles): string
    {
        if (!is_array($payload)) {
            return '';
        }

        foreach ($payload as $key => $value) {
            $keyText = mb_strtolower((string) $key, 'UTF-8');
            foreach ($needles as $needle) {
                if (str_contains($keyText, $needle) && !is_array($value) && trim((string) $value) !== '') {
                    return trim((string) $value);
                }
            }

            if (is_array($value)) {
                $nested = $this->findCustomFieldValue($value, $needles);
                if ($nested !== '') {
                    return $nested;
                }
            }
        }

        return '';
    }

    private function ensureEmploymentEndSchema(): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        if (!$this->columnExists('soferi', 'data_incetare')) {
            $this->execSchemaChangeIgnoringDuplicateColumn('ALTER TABLE soferi ADD COLUMN data_incetare DATE NULL AFTER data_angajare');
        }

        if ($this->tableExists('staff_members') && !$this->columnExists('staff_members', 'data_incetare')) {
            $this->execSchemaChangeIgnoringDuplicateColumn('ALTER TABLE staff_members ADD COLUMN data_incetare DATE NULL AFTER data_angajare');
        }

        $ensured = true;
    }

    private function execSchemaChangeIgnoringDuplicateColumn(string $sql): void
    {
        try {
            $this->db->exec($sql);
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1060) {
                throw $exception;
            }
        }
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
        ");
        $stmt->execute([':table_name' => $table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND COLUMN_NAME = :column_name
        ");
        $stmt->execute([':table_name' => $table, ':column_name' => $column]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function emptyDashboard(array $filters): array
    {
        return [
            'driver' => null,
            'filters' => $filters,
            'vehicleOptions' => [],
            'trips' => [],
            'fuelRows' => [],
            'repairs' => [],
            'documents' => [],
            'vehicleRows' => [],
            'dailyRows' => [],
            'repairAnalytics' => [],
            'consumption' => [
                'total_fuel' => 0,
                'average_consumption' => null,
                'fuel_cost' => 0,
                'cost_per_km' => null,
                'per_vehicle' => [],
                'per_transport_type' => [],
                'highest_consumption' => null,
                'lowest_consumption' => null,
            ],
            'kpis' => [
                'total_trips' => 0,
                'total_km' => 0,
                'total_loaded_tons' => 0,
                'total_delivered_tons' => 0,
                'driving_minutes' => 0,
                'total_fuel_liters' => 0,
                'average_consumption' => null,
                'fuel_cost' => 0,
                'repair_cost' => 0,
                'trip_cost' => 0,
                'total_costs' => 0,
            ],
            'charts' => [
                'tons' => ['labels' => [], 'loaded' => [], 'delivered' => []],
                'kilometers_timeline' => ['labels' => [], 'values' => []],
                'fuel_timeline' => ['labels' => [], 'values' => []],
                'cost_distribution' => ['labels' => [], 'values' => []],
                'transport_distribution' => ['labels' => [], 'values' => []],
            ],
            'updatedAt' => date('Y-m-d H:i:s'),
        ];
    }
}
