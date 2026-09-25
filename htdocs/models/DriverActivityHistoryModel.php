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
        $activeRaceCondition = $this->activeRaceCondition('c');
        $stmt = $this->db->query("
            SELECT s.id
            FROM soferi s
            INNER JOIN curse_dispecer c ON c.driver_id = s.id
            WHERE {$activeRaceCondition}
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

    /**
     * Soferii care pot fi alesi in filtru. Cu filtre (perioada, vehicul, tip transport)
     * raman doar cei care chiar au activitate inregistrata acolo: un sofer fara nicio
     * cursa in perioada nu are ce cauta nici in lista, nici in comparatie.
     * Activitate = o cursa a lui sau o faza condusa de el (curse_segmente).
     */
    public function getDriverOptions(array $filters = []): array
    {
        $dateStart = (string) ($filters['date_start'] ?? '');
        $dateEnd = (string) ($filters['date_end'] ?? '');
        if ($dateStart === '' || $dateEnd === '') {
            return $this->db->query("
                SELECT id, nume, status
                FROM soferi
                ORDER BY CASE WHEN status = 'activ' THEN 0 ELSE 1 END, nume ASC
            ")->fetchAll();
        }

        // Parametrii nu se pot repeta intr-o interogare (EMULATE_PREPARES=false),
        // deci fiecare subinterogare are prefixul ei.
        $params = [
            ':trip_start' => $dateStart,
            ':trip_end' => $dateEnd,
        ];
        $tripWhere = [
            $this->activeRaceCondition('c'),
            'COALESCE(c.data_inceput, c.data_cursa) <= :trip_end',
            'COALESCE(c.data_sfarsit, c.data_inceput, c.data_cursa) >= :trip_start',
            "(c.driver_id = s.id OR (
                c.driver_id IS NULL
                AND EXISTS (
                    SELECT 1 FROM soferi_vehicule sv
                    WHERE sv.driver_id = s.id AND sv.vehicle_id = c.vehicle_id
                )
            ))",
        ];
        if ((int) ($filters['vehicle_id'] ?? 0) > 0) {
            $tripWhere[] = 'c.vehicle_id = :trip_vehicle_id';
            $params[':trip_vehicle_id'] = (int) $filters['vehicle_id'];
        }
        $optBeneficiarySql = $this->beneficiaryFilterSql('c.beneficiar_id', $filters, $params, 'opt_beneficiar');
        if ($optBeneficiarySql !== '') {
            $tripWhere[] = $optBeneficiarySql;
        }
        $tripTransport = $this->transportFilterSql('c.tip_transport', (string) ($filters['transport_type'] ?? ''), $params, 'trip_transport');
        if ($tripTransport !== '') {
            $tripWhere[] = $tripTransport;
        }
        $exists = ['EXISTS (SELECT 1 FROM curse_dispecer c WHERE ' . implode(' AND ', $tripWhere) . ')'];

        if ($this->tableExists('curse_segmente')) {
            $params[':seg_start'] = $dateStart;
            $params[':seg_end'] = $dateEnd;
            $segmentDeleted = $this->columnExists('curse_segmente', 'deleted_at') ? 'AND seg.deleted_at IS NULL' : '';
            $segWhere = [
                $this->activeRaceCondition('sc'),
                'COALESCE(sc.data_inceput, sc.data_cursa) <= :seg_end',
                'COALESCE(sc.data_sfarsit, sc.data_inceput, sc.data_cursa) >= :seg_start',
                "EXISTS (SELECT 1 FROM curse_segmente seg WHERE seg.cursa_id = sc.id AND seg.driver_id = s.id {$segmentDeleted})",
            ];
            if ((int) ($filters['vehicle_id'] ?? 0) > 0) {
                $segWhere[] = 'sc.vehicle_id = :seg_vehicle_id';
                $params[':seg_vehicle_id'] = (int) $filters['vehicle_id'];
            }
            $optSegBeneficiarySql = $this->beneficiaryFilterSql('sc.beneficiar_id', $filters, $params, 'optseg_beneficiar');
            if ($optSegBeneficiarySql !== '') {
                $segWhere[] = $optSegBeneficiarySql;
            }
            $segTransport = $this->transportFilterSql('sc.tip_transport', (string) ($filters['transport_type'] ?? ''), $params, 'seg_transport');
            if ($segTransport !== '') {
                $segWhere[] = $segTransport;
            }
            $exists[] = 'EXISTS (SELECT 1 FROM curse_dispecer sc WHERE ' . implode(' AND ', $segWhere) . ')';
        }

        $stmt = $this->db->prepare("
            SELECT s.id, s.nume, s.status
            FROM soferi s
            WHERE " . implode(' OR ', $exists) . "
            ORDER BY CASE WHEN s.status = 'activ' THEN 0 ELSE 1 END, s.nume ASC
        ");
        $this->bindParams($stmt, $params);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    /** Beneficiarii pentru filtrul din bara de sus (aceiasi ca in Configurare transport). */
    public function getBeneficiaryOptions(): array
    {
        return $this->db->query("
            SELECT id, nume
            FROM configurare_beneficiari_transport
            WHERE activ = 1
            ORDER BY nume ASC
        ")->fetchAll() ?: [];
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
            LEFT JOIN curse_dispecer c ON c.vehicle_id = v.id AND c.driver_id = :driver_trip AND " . $this->activeRaceCondition('c') . "
            WHERE sv.driver_id IS NOT NULL OR c.driver_id IS NOT NULL
            ORDER BY v.nr_inmatriculare ASC
        ");
        $stmt->execute([
            ':driver_assignment' => $driverId,
            ':driver_trip' => $driverId,
        ]);

        return $stmt->fetchAll();
    }

    /**
     * Comparatia mai multor soferi pe aceleasi filtre. Fiecare sofer este
     * calculat cu getDashboard, deci cifrele lui sunt identice cu cele din
     * pagina lui individuala; aici doar se aseaza alaturi si se combina listele.
     */
    /**
     * $preloaded: dashboard-uri deja calculate (driver_id => dashboard). Pagina unui
     * singur sofer isi trimite dashboard-ul aici, ca tabelul de sumar sa nu-l recalculeze.
     */
    public function getComparison(array $driverIds, array $filters, array $preloaded = []): array
    {
        $drivers = [];
        $trips = [];
        $fuelRows = [];
        $diurneRows = [];
        $vehicleOptions = [];
        $timeline = [];

        foreach ($driverIds as $driverId) {
            $dashboard = $preloaded[(int) $driverId] ?? $this->getDashboard((int) $driverId, $filters);
            $driver = $dashboard['driver'] ?? null;
            if ($driver === null) {
                continue;
            }

            $id = (int) $driver['id'];
            $name = (string) ($driver['nume'] ?? '-');
            $tag = static function (array $row) use ($id, $name): array {
                $row['driver_id_compare'] = $id;
                $row['driver_name_compare'] = $name;
                return $row;
            };

            foreach ((array) $dashboard['trips'] as $trip) {
                $trips[] = $tag($trip);
                [$key, $label] = $this->groupDate((string) ($trip['data_inceput'] ?? ''), (string) $filters['grouping']);
                if ($key !== '') {
                    $timeline[$key]['label'] = $label;
                    $timeline[$key]['values'][$id] = ($timeline[$key]['values'][$id] ?? 0.0) + (float) ($trip['effective_km'] ?? 0);
                }
            }
            foreach ((array) $dashboard['fuelRows'] as $fuel) {
                $fuelRows[] = $tag($fuel);
            }
            foreach ((array) ($dashboard['diurneRows'] ?? []) as $row) {
                $diurneRows[] = $tag($row);
            }
            foreach ((array) $dashboard['vehicleOptions'] as $vehicle) {
                $vehicleOptions[(int) $vehicle['id']] = $vehicle;
            }

            $kpis = (array) $dashboard['kpis'];
            $totalKm = (float) ($kpis['total_km'] ?? 0);
            $drivers[] = [
                'id' => $id,
                'nume' => $name,
                'status' => (string) ($driver['status'] ?? ''),
                'kpis' => $kpis,
                'cost_per_km' => $totalKm > 0 ? (float) ($kpis['total_costs'] ?? 0) / $totalKm : null,
                'trip_ids' => array_values(array_filter(array_map(static fn (array $trip): int => (int) ($trip['id'] ?? 0), (array) $dashboard['trips']))),
            ];
        }

        $byDate = static fn (string $field): Closure => static fn (array $a, array $b): int => strcmp((string) ($b[$field] ?? ''), (string) ($a[$field] ?? ''));
        usort($trips, $byDate('data_inceput'));
        usort($fuelRows, $byDate('fillup_datetime'));
        usort($diurneRows, $byDate('data_inceput'));
        usort($vehicleOptions, static fn (array $a, array $b): int => strcmp((string) $a['nr_inmatriculare'], (string) $b['nr_inmatriculare']));
        ksort($timeline);

        $metric = static fn (string $key): array => array_map(static fn (array $row): float => round((float) ($row['kpis'][$key] ?? 0), 2), $drivers);

        return [
            'drivers' => $drivers,
            // Cardurile KPI ale selectiei: aceleasi totaluri ca randul "Total" din tabel.
            'kpis' => $this->combineKpis($drivers),
            'trips' => $trips,
            'fuelRows' => $fuelRows,
            'diurneRows' => $diurneRows,
            'vehicleOptions' => array_values($vehicleOptions),
            'charts' => [
                'compare' => [
                    'drivers' => array_column($drivers, 'nume'),
                    'km' => $metric('total_km'),
                    'transported_tons' => $metric('total_transported_tons'),
                    'delivered_tons' => $metric('total_delivered_tons'),
                    'consumption' => array_map(static fn (array $row): ?float => ($row['kpis']['average_consumption'] ?? null) !== null ? round((float) $row['kpis']['average_consumption'], 2) : null, $drivers),
                    'fuel_cost' => $metric('fuel_cost'),
                    'repair_cost' => $metric('repair_cost'),
                    'trip_cost' => $metric('trip_cost'),
                    'salary_cost' => $metric('salary_cost'),
                    'diurne_cost' => $metric('diurne_value_in_total'),
                    'trip_value' => $metric('trip_value'),
                    'profit' => $metric('profit'),
                    'diurne' => $metric('diurne'),
                    'timeline' => [
                        'labels' => array_column($timeline, 'label'),
                        'series' => array_map(static fn (array $driver): array => [
                            'label' => $driver['nume'],
                            'values' => array_map(static fn (array $bucket): float => round((float) ($bucket['values'][$driver['id']] ?? 0), 2), array_values($timeline)),
                        ], $drivers),
                    ],
                ],
            ],
            'updatedAt' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Totalurile selectiei pentru cardurile KPI din comparatie: se aduna valorile
     * soferilor, exact ca randul "Total" din tabelul de sumar (un vehicul condus de
     * mai multi soferi isi aduce reparatiile la fiecare dintre ei), iar defalcarea pe
     * tip de transport se aduna pe aceleasi tipuri.
     */
    private function combineKpis(array $drivers): array
    {
        $sumKeys = [
            'total_trips', 'total_km', 'total_transported_tons', 'total_delivered_tons',
            'driving_minutes', 'total_fuel_liters', 'fuel_cost', 'repair_cost', 'trip_cost',
            'total_costs', 'salary_cost', 'worked_days', 'diurne', 'diurne_trips', 'diurne_missing',
            'diurne_unvalued', 'diurne_not_eligible', 'diurne_value', 'diurne_value_in_total',
            'trip_value', 'refacturare_total', 'refacturare_recovered', 'profit', 'clients_total',
        ];
        $combined = array_fill_keys($sumKeys, 0.0);
        $breakdown = [];
        $breakdownTripIds = [];
        $breakdownFuelLinks = [];
        $breakdownRepairLink = ['vehicle_ids' => [], 'from' => null, 'to' => null];
        $salaryMissing = false;

        foreach ($drivers as $driver) {
            $kpis = (array) ($driver['kpis'] ?? []);
            foreach ($sumKeys as $key) {
                $combined[$key] += (float) ($kpis[$key] ?? 0);
            }
            $salaryMissing = $salaryMissing || !empty($kpis['salary_missing']);

            foreach ((array) ($kpis['breakdown']['trip_ids'] ?? []) as $bucket => $ids) {
                $breakdownTripIds[$bucket] = array_merge($breakdownTripIds[$bucket] ?? [], (array) $ids);
            }
            $repairLink = (array) ($kpis['breakdown']['repair_link'] ?? []);
            $breakdownRepairLink['vehicle_ids'] = array_values(array_unique(array_merge($breakdownRepairLink['vehicle_ids'], (array) ($repairLink['vehicle_ids'] ?? []))));
            foreach (['from' => 'min', 'to' => 'max'] as $edge => $pick) {
                $value = $repairLink[$edge] ?? null;
                if ($value !== null) {
                    $breakdownRepairLink[$edge] = $breakdownRepairLink[$edge] === null ? $value : $pick($breakdownRepairLink[$edge], $value);
                }
            }
            foreach ((array) ($kpis['breakdown']['fuel_links'] ?? []) as $bucket => $link) {
                $current = $breakdownFuelLinks[$bucket] ?? ['vehicles' => [], 'from' => null, 'to' => null];
                $current['vehicles'] = array_values(array_unique(array_merge($current['vehicles'], (array) ($link['vehicles'] ?? []))));
                foreach (['from' => 'min', 'to' => 'max'] as $edge => $pick) {
                    $value = $link[$edge] ?? null;
                    if ($value !== null) {
                        $current[$edge] = $current[$edge] === null ? $value : $pick($current[$edge], $value);
                    }
                }
                $breakdownFuelLinks[$bucket] = $current;
            }
            foreach ((array) ($kpis['breakdown']['metrics'] ?? []) as $metricKey => $metric) {
                $breakdown[$metricKey]['format'] = (string) ($metric['format'] ?? 'number');
                foreach (['unallocated_label', 'note'] as $textKey) {
                    if (isset($metric[$textKey])) {
                        $breakdown[$metricKey][$textKey] = $metric[$textKey];
                    }
                }
                foreach ((array) ($metric['values'] ?? []) as $bucket => $value) {
                    $breakdown[$metricKey]['values'][$bucket] = ($breakdown[$metricKey]['values'][$bucket] ?? 0.0) + (float) $value;
                }
                $breakdown[$metricKey]['unallocated'] = ($breakdown[$metricKey]['unallocated'] ?? 0.0) + (float) ($metric['unallocated'] ?? 0);
            }
        }

        foreach ($breakdown as $metricKey => $metric) {
            $breakdown[$metricKey]['values'] = (array) ($metric['values'] ?? []);
        }

        $combined['total_trips'] = (int) $combined['total_trips'];
        $combined['driving_minutes'] = (int) $combined['driving_minutes'];
        $combined['worked_days'] = (int) $combined['worked_days'];
        $combined['diurne'] = (int) $combined['diurne'];
        $combined['average_consumption'] = $combined['total_km'] > 0
            ? $combined['total_fuel_liters'] / $combined['total_km'] * 100
            : null;
        $combined['operational_costs'] = $combined['fuel_cost'] + $combined['repair_cost'] + $combined['trip_cost'];
        $combined['salary_missing'] = $salaryMissing;
        $combined['breakdown'] = [
            'labels' => self::TRANSPORT_LABELS,
            'trip_ids' => array_map(static fn (array $ids): array => array_values(array_unique($ids)), $breakdownTripIds),
            'fuel_links' => $breakdownFuelLinks,
            'repair_link' => $breakdownRepairLink,
            'metrics' => $breakdown,
        ];

        return $combined;
    }

    public function getDashboard(int $driverId, array $filters): array
    {
        $driver = $this->getDriver($driverId);
        if ($driver === null) {
            return $this->emptyDashboard($filters);
        }

        $trips = $this->getTripRows($driverId, $filters);
        $diurne = $this->attachDiurne($driverId, $filters, $trips);
        $fuelRows = $this->getFuelRows($driverId, $filters, $trips);
        $usedVehicleIds = $this->resolveUsedVehicleIds($driverId, $filters, $trips, $fuelRows);
        $repairs = $this->getRepairRows($filters, $usedVehicleIds);
        $documents = $this->getDocumentRows($driverId, $filters, $usedVehicleIds);
        $vehicleRows = $this->buildVehicleRows($usedVehicleIds, $trips, $fuelRows, $repairs);
        $dailyRows = $this->buildDailyRows($filters, $trips, $fuelRows, $repairs);
        $repairAnalytics = $this->buildRepairCategoryAnalytics($repairs);
        $consumption = $this->buildConsumptionSummary($trips, $fuelRows);
        $kpis = $this->buildKpis($trips, $fuelRows, $repairs);
        $kpis['diurne'] = $diurne['total'];
        $kpis['diurne_trips'] = $diurne['trips'];
        $kpis['diurne_missing'] = $diurne['missing'];
        $kpis['diurne_not_eligible'] = $diurne['not_eligible'];
        $kpis['diurne_value'] = $diurne['value'];
        $kpis['diurne_unvalued'] = $diurne['unvalued'];
        $kpis['diurna_policy'] = $diurne['policy'];
        $diurneRows = $diurne['rows'];

        // Salariul intra in costul total, dupa zilele lucrate din curse.
        $salary = $this->buildSalaryCost($driverId, $diurne['worked_dates'], $diurne['worked_date_buckets']);
        $kpis['salary_cost'] = $salary['cost'];
        $kpis['worked_days'] = $salary['worked_days'];
        $kpis['salary_months'] = $salary['months'];
        $kpis['salary_missing'] = $salary['missing_salary'];
        $kpis['total_costs'] += $salary['cost'];

        // Diurna in lei intra in costul total, fara cursele pe care diurna e deja
        // trecuta ca cheltuiala (aceea e deja in costul curselor).
        $kpis['diurne_value_in_total'] = $diurne['value_in_total'];
        $kpis['diurne_value_recorded'] = $diurne['value_recorded'];
        $kpis['total_costs'] += $diurne['value_in_total'];

        // Profit: valoarea curselor (tariful facturat) + refacturarile trecute in
        // "Refacturat" (bani recuperati) - costul total. Pana la refacturare,
        // suma ramane doar in costul cursei.
        $kpis['trip_value'] = array_sum(array_map(static fn (array $trip): float => (float) ($trip['total_facturare'] ?? 0), $trips));
        $kpis['refacturare_total'] = array_sum(array_map(static fn (array $trip): float => (float) ($trip['total_refacturare'] ?? 0), $trips));
        $kpis['refacturare_recovered'] = array_sum(array_map(static fn (array $trip): float => (float) ($trip['total_refacturare_facturata'] ?? 0), $trips));
        $kpis['profit'] = $kpis['trip_value'] + $kpis['refacturare_recovered'] - $kpis['total_costs'];
        $kpis += $this->buildClientStats($trips);

        // Defalcarea pe tip de transport a cardurilor KPI: aceleasi curse / alimentari /
        // reparatii filtrate din care ies si valorile de pe carduri.
        $kpis['breakdown'] = $this->buildKpiBreakdown($trips, $fuelRows, $repairs, $diurneRows, (float) $salary['cost'], (array) $salary['by_bucket']);

        $charts = $this->buildCharts($filters, $trips, $fuelRows, $repairs);
        $charts['cost_distribution']['labels'][] = 'Salariu';
        $charts['cost_distribution']['values'][] = round($salary['cost'], 2);
        $charts['cost_distribution']['labels'][] = 'Diurne';
        $charts['cost_distribution']['values'][] = round($diurne['value_in_total'], 2);

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
            'charts' => $charts,
            'diurneRows' => $diurneRows,
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
            $this->activeRaceCondition('c'),
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

        $beneficiarySql = $this->beneficiaryFilterSql('c.beneficiar_id', $filters, $params, 'trip_beneficiar');
        if ($beneficiarySql !== '') {
            $where[] = $beneficiarySql;
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
                COALESCE(exp.total_alte_cheltuieli, 0) AS total_alte_cheltuieli,
                COALESCE(exp.total_diurna, 0) AS total_diurna,
                COALESCE(exp.total_refacturare, 0) AS total_refacturare,
                COALESCE(exp.total_refacturare_facturata, 0) AS total_refacturare_facturata
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

    /**
     * Diurnele soferului si zilele lucrate, din cursele lui.
     *
     * Numarul de diurne al unei curse: aceeasi regula ca in Desfasurator
     * (dispatcher_diurna_for_interval). La o cursa reluata cu alt sofer diurnele
     * se impart pe soferii fazelor dupa timpul petrecut pe drum
     * (dispatcher_diurna_split), deci se numara si cursele pe care soferul a
     * condus doar o faza, chiar daca pe cursa figureaza alt sofer.
     *
     * Daca soferul primeste diurna si cat valoreaza o zi se stabileste per sofer
     * in Contabilitate Personal (DriverDiurnaModel), cu regula valabila la data
     * inceperii cursei: "fara diurna" = 0 diurne platite, "nesetat" = diurnele se
     * numara dar nu au valoare in lei.
     *
     * Zilele lucrate (pentru salariu) sunt zilele calendaristice acoperite de
     * curse, fiecare numarata o data; la o cursa cu mai multe faze conteaza doar
     * fazele soferului. Se pastreaza doar zilele din perioada filtrata.
     *
     * Pune pe fiecare cursa din lista 'diurne' / 'diurne_value' si intoarce totalurile.
     */
    private function attachDiurne(int $driverId, array $filters, array &$trips): array
    {
        $races = [];
        foreach ($trips as $trip) {
            $races[(int) $trip['id']] = $trip;
        }
        foreach ($this->getSegmentOnlyTrips($driverId, $filters, array_keys($races)) as $trip) {
            $races[(int) $trip['id']] = $trip;
        }

        // Modificarile de diurna aprobate in Dispecer curse inlocuiesc valoarea calculata.
        $raceList = array_values($races);
        dispatcher_attach_diurna_adjustments($this->db, $raceList);
        foreach ($raceList as $race) {
            $races[(int) $race['id']] = $race;
        }

        $segments = $this->getActiveSegments(array_keys($races));
        $diurnaHistory = (new DriverDiurnaModel($this->db))->getHistoryForDrivers([$driverId])[$driverId] ?? [];
        $perRace = [];
        $rows = [];
        $workedDates = [];
        $workedDateBuckets = [];
        $summary = [
            'total' => 0,            // diurne platite (soferul primeste diurna)
            'trips' => 0,
            'missing' => 0,          // curse fara data/ora completa
            'not_eligible' => 0,     // diurne calculate, dar soferul nu primeste diurna
            'value' => 0.0,          // lei, doar unde valoarea pe zi e stabilita
            'value_in_total' => 0.0, // partea adaugata in costul total
            'value_recorded' => 0.0, // curse cu diurna deja trecuta ca cheltuiala (nu se adauga)
            'unvalued' => 0,         // diurne fara valoare stabilita
            'minutes' => 0,
            'rows' => [],
        ];

        foreach ($races as $raceId => $race) {
            $raceSegments = $segments[$raceId] ?? [];
            $ownSegments = count($raceSegments) > 1
                ? array_filter($raceSegments, static fn (array $segment): bool => (int) ($segment['driver_id'] ?? 0) === $driverId)
                : [];
            // Tipul de transport al cursei: pe ce tip se trec zilele lucrate (cardul Salariu).
            $raceBucket = (string) ($race['transport_bucket'] ?? $this->normalizeTransportBucket((string) ($race['tip_transport'] ?? '')));
            foreach (count($raceSegments) > 1 ? $ownSegments : [$race] as $span) {
                foreach ($this->spanDates($span, $filters) as $date) {
                    $workedDates[$date] = true;
                    if (isset(self::TRANSPORT_LABELS[$raceBucket])) {
                        $workedDateBuckets[$date][$raceBucket] = true;
                    }
                }
            }

            $interval = dispatcher_diurna_for_interval($race);
            $policy = DriverDiurnaModel::policyAt($diurnaHistory, (string) ($race['data_inceput'] ?? $race['data_cursa'] ?? ''));
            $row = [
                'id' => $raceId,
                'data_inceput' => $race['data_inceput'] ?? null,
                'ora_inceput' => $race['ora_inceput'] ?? null,
                'data_sfarsit' => $race['data_sfarsit'] ?? null,
                'ora_sfarsit' => $race['ora_sfarsit'] ?? null,
                'nr_inmatriculare' => $race['nr_inmatriculare'] ?? null,
                'status' => $interval['status'],
                'minutes' => $interval['minute'],
                'trip_diurne' => $interval['diurne'],
                'diurne' => null,
                'diurne_value' => null,
                'diurna_recorded' => null,
                'policy' => $policy,
                'split' => '',
                'segment_only' => !isset($race['beneficiary_label']),
            ];

            if ($interval['status'] !== 'ok') {
                $perRace[$raceId] = ['days' => null, 'value' => null];
                $summary['missing']++;
                $rows[] = $row;
                continue;
            }

            $days = (int) $interval['diurne'];
            if (count($raceSegments) > 1) {
                $share = 0;
                foreach (dispatcher_diurna_split($days, $raceSegments) as $splitRow) {
                    if ((int) $splitRow['driver_id'] === $driverId) {
                        $share += (int) $splitRow['zile'];
                    }
                }
                $row['split'] = dispatcher_diurna_summary($days, $raceSegments);
                $days = $share;
            }

            if ($policy['status'] === DriverDiurnaModel::STATUS_NONE) {
                $summary['not_eligible'] += $days;
                $days = 0;
            } elseif ($policy['rate'] !== null) {
                $row['diurne_value'] = $days * (float) $policy['rate'];
                $summary['value'] += $row['diurne_value'];
                // Diurna deja trecuta ca cheltuiala pe cursa (tip "diurna") este in
                // costul curselor; valoarea calculata nu se mai adauga, ca sa nu se
                // numere de doua ori. Doar cursele din lista soferului au costurile
                // in totalul lui, deci doar acolo poate aparea dublarea.
                $recorded = isset($race['beneficiary_label']) ? (float) ($race['total_diurna'] ?? 0) : 0.0;
                if ($recorded > 0) {
                    $row['diurna_recorded'] = $recorded;
                    $summary['value_recorded'] += $row['diurne_value'];
                } else {
                    $summary['value_in_total'] += $row['diurne_value'];
                }
            } else {
                $summary['unvalued'] += $days;
            }

            $row['diurne'] = $days;
            $rows[] = $row;
            $perRace[$raceId] = ['days' => $days, 'value' => $row['diurne_value']];
            $summary['total'] += $days;
            $summary['minutes'] += (int) $interval['minute'];
            if ($days > 0) {
                $summary['trips']++;
            }
        }

        foreach ($trips as &$trip) {
            $trip['diurne'] = $perRace[(int) $trip['id']]['days'] ?? null;
            $trip['diurne_value'] = $perRace[(int) $trip['id']]['value'] ?? null;
        }
        unset($trip);

        usort($rows, static fn (array $a, array $b): int => strcmp(
            (string) $b['data_inceput'] . ' ' . (string) $b['ora_inceput'],
            (string) $a['data_inceput'] . ' ' . (string) $a['ora_inceput']
        ));
        ksort($workedDates);
        $summary['rows'] = $rows;
        $summary['policy'] = DriverDiurnaModel::policyAt($diurnaHistory, (string) $filters['date_end']);
        $summary['worked_dates'] = array_keys($workedDates);
        $summary['worked_date_buckets'] = $workedDateBuckets;

        return $summary;
    }

    /** Zilele calendaristice ale unei curse / faze, taiate la perioada filtrata. */
    private function spanDates(array $span, array $filters): array
    {
        $start = (string) ($span['data_inceput'] ?? $span['data_cursa'] ?? '');
        $end = (string) ($span['data_sfarsit'] ?? '') !== '' ? (string) $span['data_sfarsit'] : $start;
        if ($start === '') {
            return [];
        }
        $start = max(substr($start, 0, 10), (string) $filters['date_start']);
        $end = min(substr($end, 0, 10), (string) $filters['date_end']);
        if ($end < $start) {
            return [];
        }

        $dates = [];
        for ($day = new DateTimeImmutable($start); $day->format('Y-m-d') <= $end; $day = $day->modify('+1 day')) {
            $dates[] = $day->format('Y-m-d');
        }

        return $dates;
    }

    /**
     * Costul salarial al soferului in perioada, dupa zilele lucrate.
     *
     * Pe fiecare luna: salariul lunii (salary_history valabil la sfarsitul lunii,
     * altfel salariul din fisa soferului) / zilele lucratoare ale lunii (luni-vineri)
     * x zilele lucrate in luna. Zilele de weekend lucrate se adauga peste.
     *
     * Defalcarea pe tip de transport (cardul Salariu) foloseste aceeasi valoare pe zi:
     * fiecare zi lucrata merge pe tipul curselor din ziua respectiva; o zi cu curse de
     * doua tipuri se imparte egal intre ele, deci sumele raman egale cu totalul.
     *
     * @return array{cost: float, worked_days: int, months: array, missing_salary: bool, by_bucket: array}
     */
    private function buildSalaryCost(int $driverId, array $workedDates, array $workedDateBuckets = []): array
    {
        $byBucket = [
            'days' => array_fill_keys(array_keys(self::TRANSPORT_LABELS), 0.0),
            'cost' => array_fill_keys(array_keys(self::TRANSPORT_LABELS), 0.0),
        ];
        $byMonth = [];
        foreach ($workedDates as $date) {
            $byMonth[substr($date, 0, 7)][] = $date;
        }

        $months = [];
        $total = 0.0;
        $missingSalary = false;
        foreach ($byMonth as $month => $dates) {
            $monthStart = new DateTimeImmutable($month . '-01');
            $monthEnd = $monthStart->modify('last day of this month');
            $salary = $this->salaryAt($driverId, $monthEnd->format('Y-m-d'));
            $workingDays = 0;
            for ($day = $monthStart; $day <= $monthEnd; $day = $day->modify('+1 day')) {
                if ((int) $day->format('N') <= 5) {
                    $workingDays++;
                }
            }

            $cost = $salary !== null && $workingDays > 0 ? $salary / $workingDays * count($dates) : null;
            if ($cost === null) {
                $missingSalary = true;
            }
            $daily = $salary !== null && $workingDays > 0 ? $salary / $workingDays : 0.0;
            foreach ($dates as $date) {
                $dateBuckets = array_keys((array) ($workedDateBuckets[$date] ?? []));
                foreach ($dateBuckets as $bucket) {
                    $share = 1 / count($dateBuckets);
                    $byBucket['days'][$bucket] += $share;
                    $byBucket['cost'][$bucket] += $daily * $share;
                }
            }
            $total += (float) $cost;
            $months[] = [
                'month' => $month,
                'worked_days' => count($dates),
                'working_days' => $workingDays,
                'salary' => $salary,
                'daily' => $salary !== null && $workingDays > 0 ? $salary / $workingDays : null,
                'cost' => $cost,
            ];
        }

        return [
            'cost' => $total,
            'worked_days' => count($workedDates),
            'months' => $months,
            'missing_salary' => $missingSalary,
            'by_bucket' => $byBucket,
        ];
    }

    /** Salariul soferului la o data: ultimul din istoric, altfel cel din fisa. */
    private function salaryAt(int $driverId, string $date): ?float
    {
        if ($this->tableExists('salary_history')) {
            $stmt = $this->db->prepare('
                SELECT current_salary
                FROM salary_history
                WHERE driver_id = :driver_id AND effective_date <= :date
                ORDER BY effective_date DESC, id DESC
                LIMIT 1
            ');
            $stmt->execute([':driver_id' => $driverId, ':date' => $date]);
            $value = $stmt->fetchColumn();
            if ($value !== false && $value !== null) {
                return (float) $value;
            }
        }

        $stmt = $this->db->prepare('SELECT salariu FROM soferi WHERE id = :id');
        $stmt->execute([':id' => $driverId]);
        $value = $stmt->fetchColumn();

        return $value !== false && $value !== null && (float) $value > 0 ? (float) $value : null;
    }

    /**
     * Cursele din perioada pe care soferul a condus o faza, dar pe care figureaza
     * alt sofer (deci nu sunt in lista de curse a soferului).
     */
    private function getSegmentOnlyTrips(int $driverId, array $filters, array $excludeIds): array
    {
        if (!$this->tableExists('curse_segmente')) {
            return [];
        }

        $params = [
            ':seg_driver_id' => $driverId,
            ':seg_date_start' => (string) $filters['date_start'],
            ':seg_date_end' => (string) $filters['date_end'],
        ];
        $segmentDeleted = $this->columnExists('curse_segmente', 'deleted_at') ? 'AND seg.deleted_at IS NULL' : '';
        $where = [
            $this->activeRaceCondition('c'),
            'COALESCE(c.data_inceput, c.data_cursa) <= :seg_date_end',
            'COALESCE(c.data_sfarsit, c.data_inceput, c.data_cursa) >= :seg_date_start',
            "EXISTS (
                SELECT 1 FROM curse_segmente seg
                WHERE seg.cursa_id = c.id AND seg.driver_id = :seg_driver_id {$segmentDeleted}
            )",
        ];
        if ((int) ($filters['vehicle_id'] ?? 0) > 0) {
            $where[] = 'c.vehicle_id = :seg_vehicle_id';
            $params[':seg_vehicle_id'] = (int) $filters['vehicle_id'];
        }
        $segBeneficiarySql = $this->beneficiaryFilterSql('c.beneficiar_id', $filters, $params, 'seg_beneficiar');
        if ($segBeneficiarySql !== '') {
            $where[] = $segBeneficiarySql;
        }
        $transportSql = $this->transportFilterSql('c.tip_transport', (string) ($filters['transport_type'] ?? ''), $params, 'seg_transport');
        if ($transportSql !== '') {
            $where[] = $transportSql;
        }
        if ($excludeIds !== []) {
            $where[] = 'c.id NOT IN (' . $this->inClause($params, 'seg_exclude', array_map('intval', $excludeIds)) . ')';
        }

        $stmt = $this->db->prepare("
            SELECT c.id, c.driver_id, c.data_inceput, c.ora_inceput, c.data_sfarsit, c.ora_sfarsit, c.tip_transport, v.nr_inmatriculare
            FROM curse_dispecer c
            LEFT JOIN vehicule v ON v.id = c.vehicle_id
            WHERE " . implode(' AND ', $where));
        $this->bindParams($stmt, $params);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** Fazele active ale curselor, grupate pe cursa. */
    private function getActiveSegments(array $raceIds): array
    {
        if ($raceIds === [] || !$this->tableExists('curse_segmente')) {
            return [];
        }

        $params = [];
        $segmentDeleted = $this->columnExists('curse_segmente', 'deleted_at') ? 'AND seg.deleted_at IS NULL' : '';
        $stmt = $this->db->prepare("
            SELECT seg.cursa_id, seg.driver_id, seg.data_inceput, seg.ora_inceput, seg.data_sfarsit, seg.ora_sfarsit, s.nume AS sofer_nume
            FROM curse_segmente seg
            LEFT JOIN soferi s ON s.id = seg.driver_id
            WHERE seg.cursa_id IN (" . $this->inClause($params, 'diurna_race', array_map('intval', $raceIds)) . ")
              {$segmentDeleted}
            ORDER BY seg.cursa_id ASC, seg.ordine ASC, seg.id ASC
        ");
        $this->bindParams($stmt, $params);
        $stmt->execute();

        $grouped = [];
        foreach ($stmt->fetchAll() as $segment) {
            $grouped[(int) $segment['cursa_id']][] = $segment;
        }

        return $grouped;
    }

    /**
     * Alimentarile soferului, din aceeasi sursa ca pagina Carburanti
     * (fuel_fillups, sincronizat din CardOil + introduse manual). Tabelul vechi
     * `alimentari` nu mai este alimentat, de aceea costul carburantului aparea 0.
     *
     * O alimentare este a soferului cand:
     *  1. este legata (fuel_trip_links, automat sau manual in Carburanti) de una
     *     dintre cursele lui din perioada filtrata; sau
     *  2. nu este legata de nicio cursa, este in perioada, iar soferul de pe card
     *     este acest sofer (doar cand nu se filtreaza pe tip de transport, fiindca
     *     fara cursa tipul de transport nu se cunoaste).
     * Se numara doar motorina; AdBlue nu intra in consum si nici in costul carburantului.
     */
    private function getFuelRows(int $driverId, array $filters, array $trips): array
    {
        if (!$this->tableExists('fuel_fillups')) {
            return [];
        }

        $tripsById = [];
        foreach ($trips as $trip) {
            $tripsById[(int) ($trip['id'] ?? 0)] = $trip;
        }
        unset($tripsById[0]);
        $hasLinks = $this->tableExists('fuel_trip_links');

        $select = "
            SELECT
                f.id,
                f.fillup_datetime,
                DATE(f.fillup_datetime) AS data_alimentare,
                f.quantity_liters AS litri,
                f.unit_price AS pret_litru,
                f.total_value AS cost_total,
                COALESCE(f.odometer_km_manual, f.odometer_km) AS km_bord,
                f.station_name AS observatii,
                f.driver_name AS record_sofer_nume,
                f.source_type,
                f.receipt_path,
                f.vehicle_registration,
                v.id AS vehicle_id,
                COALESCE(v.nr_inmatriculare, f.vehicle_registration) AS nr_inmatriculare,
                v.marca,
                v.model,
                v.tip_vehicul,
                " . ($hasLinks ? 'l.trip_id' : 'NULL') . " AS explicit_trip_id
            FROM fuel_fillups f
            LEFT JOIN vehicule v ON REPLACE(UPPER(v.nr_inmatriculare), ' ', '') = REPLACE(UPPER(f.vehicle_registration), ' ', '')
            " . ($hasLinks ? 'LEFT JOIN fuel_trip_links l ON l.fillup_id = f.id' : '') . "
            WHERE f.fuel_type = 'motorina'
        ";

        $rows = [];

        // 1. Alimentari legate de cursele soferului.
        if ($hasLinks && $tripsById !== []) {
            $params = [];
            $stmt = $this->db->prepare($select . ' AND l.trip_id IN (' . $this->inClause($params, 'fuel_trip', array_keys($tripsById)) . ')');
            $this->bindParams($stmt, $params);
            $stmt->execute();
            foreach ($stmt->fetchAll() as $row) {
                $rows[(int) $row['id']] = $row;
            }
        }

        // 2. Alimentari fara cursa, dupa soferul de pe card.
        $driverTokens = $this->nameTokens((string) ($this->db->query('SELECT nume FROM soferi WHERE id = ' . (int) $driverId)->fetchColumn() ?: ''));
        $beneficiaryFiltered = (int) ($filters['beneficiar_id'] ?? 0) > 0;
        if ((string) ($filters['transport_type'] ?? '') === '' && !$beneficiaryFiltered && $driverTokens !== []) {
            $params = [
                ':fuel_date_start' => (string) $filters['date_start'] . ' 00:00:00',
                ':fuel_date_end' => (string) $filters['date_end'] . ' 23:59:59',
            ];
            $sql = $select . ' AND f.fillup_datetime BETWEEN :fuel_date_start AND :fuel_date_end';
            if ($hasLinks) {
                $sql .= ' AND l.id IS NULL';
            }
            if ((int) ($filters['vehicle_id'] ?? 0) > 0) {
                $sql .= ' AND v.id = :fuel_vehicle_id';
                $params[':fuel_vehicle_id'] = (int) $filters['vehicle_id'];
            }
            $stmt = $this->db->prepare($sql);
            $this->bindParams($stmt, $params);
            $stmt->execute();
            foreach ($stmt->fetchAll() as $row) {
                $cardTokens = $this->nameTokens((string) ($row['record_sofer_nume'] ?? ''));
                // "Causanu Mihai" (card) = "Causanu Mihai Nicusor" (sofer): toate
                // cuvintele de pe card se regasesc in numele soferului.
                if (count($cardTokens) >= 2 && array_diff($cardTokens, $driverTokens) === []) {
                    $rows[(int) $row['id']] = $row;
                }
            }
        }

        $rows = array_values($rows);
        foreach ($rows as &$row) {
            $linkedTrip = $tripsById[(int) ($row['explicit_trip_id'] ?? 0)] ?? null;
            $row = $this->decorateFuelRow($row, $linkedTrip);
        }
        unset($row);

        usort($rows, static fn (array $a, array $b): int => [(int) $a['vehicle_id'], (string) $a['fillup_datetime']] <=> [(int) $b['vehicle_id'], (string) $b['fillup_datetime']]);
        $rows = $this->attachFuelConsumption($rows);
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $b['fillup_datetime'], (string) $a['fillup_datetime']));

        return $rows;
    }

    /** Cuvintele unui nume, fara diacritice si majuscule, pentru comparare. */
    private function nameTokens(string $name): array
    {
        $name = strtr(mb_strtolower(trim($name)), ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't', '-' => ' ', '.' => ' ']);
        $tokens = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique($tokens));
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

    /**
     * Defalcarea pe tip de transport pentru cardurile KPI (ce se vede la hover).
     * Se calculeaza din aceleasi randuri filtrate ca valorile afisate, deci se schimba
     * odata cu ele la fiecare filtrare (pagina se re-randeaza la "Filtreaza").
     *
     * Ce nu tine de o cursa nu se poate imparti pe tip de transport si intra in
     * "nealocat": reparatiile (sunt ale vehiculului), salariul (e pe zile lucrate) si
     * alimentarile nelegate de o cursa. Sumele raman egale cu totalul de pe card.
     */
    private function buildKpiBreakdown(array $trips, array $fuelRows, array $repairs, array $diurneRows, float $salaryCost, array $salaryByBucket = []): array
    {
        $buckets = array_keys(self::TRANSPORT_LABELS);
        $empty = array_fill_keys($buckets, 0.0);
        $metrics = [
            'trips' => $empty, 'km' => $empty, 'tons' => $empty, 'minutes' => $empty,
            'liters' => $empty, 'costs' => $empty, 'diurne' => $empty, 'profit' => $empty,
            // Valoarea diurnelor (aceeasi ca pe card, 'diurne_value'), afisata langa numar.
            'diurne_value' => $empty,
            // Costul operational (fara salariu si diurne) - cardul celor fara drept financiar.
            'operational' => $empty,
        ];
        $tripsById = [];
        // Cursele fiecarui tip: randurile cardului "Total curse" deschid exact aceste
        // curse in Desfasurator curse.
        $tripIds = array_fill_keys($buckets, []);

        foreach ($trips as $trip) {
            $bucket = (string) ($trip['transport_bucket'] ?? '');
            $tripsById[(int) ($trip['id'] ?? 0)] = $bucket;
            // '__all': toate cursele, pentru randul "Curse" din Cost total.
            if ((int) ($trip['id'] ?? 0) > 0) {
                $tripIds['__all'][] = (int) $trip['id'];
            }
            if (!isset($metrics['trips'][$bucket])) {
                continue;
            }
            if ((int) ($trip['id'] ?? 0) > 0) {
                $tripIds[$bucket][] = (int) $trip['id'];
            }
            $tripCost = (float) ($trip['total_cheltuieli'] ?? 0);
            $metrics['trips'][$bucket] += 1;
            $metrics['km'][$bucket] += (float) ($trip['effective_km'] ?? 0);
            $metrics['tons'][$bucket] += (float) ($trip['transported_tons'] ?? 0) + (float) ($trip['delivered_tons'] ?? 0);
            $metrics['minutes'][$bucket] += (int) ($trip['duration_minutes_effective'] ?? 0);
            $metrics['costs'][$bucket] += $tripCost;
            $metrics['operational'][$bucket] += $tripCost;
            $metrics['profit'][$bucket] += (float) ($trip['total_facturare'] ?? 0)
                + (float) ($trip['total_refacturare_facturata'] ?? 0) - $tripCost;
        }

        // Alimentarile intra pe tipul cursei de care sunt legate; cele fara cursa raman nealocate.
        // Pentru fiecare tip se retin si vehiculele si datele alimentarilor: randurile
        // cardului "Consum total" deschid Carburanti pe acestea (acolo nu exista filtru de sofer).
        $fuelUnallocatedLiters = 0.0;
        $fuelUnallocatedCost = 0.0;
        $fuelLinks = [];
        foreach ($fuelRows as $fuel) {
            $bucket = (string) ($fuel['transport_bucket'] ?? '');
            $liters = (float) ($fuel['litri'] ?? 0);
            $cost = (float) ($fuel['cost_total'] ?? 0);
            // '__all': toate alimentarile (si cele fara cursa), pentru randul "Carburant" din Cost total.
            $registration = trim((string) ($fuel['vehicle_registration'] ?? ''));
            $fuelDate = substr((string) ($fuel['fillup_datetime'] ?? ''), 0, 10);
            foreach (isset($metrics['liters'][$bucket]) ? [$bucket, '__all'] : ['__all'] as $linkKey) {
                if ($registration !== '') {
                    $fuelLinks[$linkKey]['vehicles'][$registration] = $registration;
                }
                if ($fuelDate !== '') {
                    $fuelLinks[$linkKey]['from'] = min($fuelLinks[$linkKey]['from'] ?? $fuelDate, $fuelDate);
                    $fuelLinks[$linkKey]['to'] = max($fuelLinks[$linkKey]['to'] ?? $fuelDate, $fuelDate);
                }
            }
            if (isset($metrics['liters'][$bucket])) {
                $metrics['liters'][$bucket] += $liters;
                $metrics['costs'][$bucket] += $cost;
                $metrics['operational'][$bucket] += $cost;
                $metrics['profit'][$bucket] -= $cost;
                continue;
            }
            $fuelUnallocatedLiters += $liters;
            $fuelUnallocatedCost += $cost;
        }

        // Diurnele: numarul si valoarea care intra in costul total, pe tipul cursei.
        $diurneUnallocated = 0.0;
        $diurneValueUnallocated = 0.0;
        foreach ($diurneRows as $row) {
            $bucket = $tripsById[(int) ($row['id'] ?? 0)] ?? '';
            $count = (float) ($row['diurne'] ?? 0);
            // Diurna deja trecuta ca cheltuiala pe cursa este in costul cursei.
            $value = ($row['diurna_recorded'] ?? null) === null ? (float) ($row['diurne_value'] ?? 0) : 0.0;
            if (isset($metrics['diurne'][$bucket])) {
                $metrics['diurne'][$bucket] += $count;
                $metrics['diurne_value'][$bucket] += (float) ($row['diurne_value'] ?? 0);
                $metrics['costs'][$bucket] += $value;
                $metrics['profit'][$bucket] -= $value;
                continue;
            }
            $diurneUnallocated += $count;
            $diurneValueUnallocated += $value;
        }

        $repairCost = array_sum(array_map(static fn (array $row): float => (float) ($row['cost'] ?? 0), $repairs));
        $costsUnallocated = $repairCost + $salaryCost + $fuelUnallocatedCost + $diurneValueUnallocated;

        // Vehiculele si datele reparatiilor: randul "Reparatii" din Cost total deschide Mentenanta pe ele.
        $repairLink = ['vehicle_ids' => [], 'from' => null, 'to' => null];
        foreach ($repairs as $repair) {
            $repairVehicleId = (int) ($repair['vehicle_id'] ?? 0);
            if ($repairVehicleId > 0) {
                $repairLink['vehicle_ids'][$repairVehicleId] = $repairVehicleId;
            }
            $repairDate = substr((string) ($repair['data_interventie'] ?? ''), 0, 10);
            if ($repairDate !== '') {
                $repairLink['from'] = $repairLink['from'] === null ? $repairDate : min($repairLink['from'], $repairDate);
                $repairLink['to'] = $repairLink['to'] === null ? $repairDate : max($repairLink['to'], $repairDate);
            }
        }
        $repairLink['vehicle_ids'] = array_values($repairLink['vehicle_ids']);

        return [
            'labels' => self::TRANSPORT_LABELS,
            'trip_ids' => $tripIds,
            'repair_link' => $repairLink,
            'fuel_links' => array_map(static fn (array $link): array => [
                'vehicles' => array_values((array) ($link['vehicles'] ?? [])),
                'from' => $link['from'] ?? null,
                'to' => $link['to'] ?? null,
            ], $fuelLinks),
            'metrics' => [
                'trips' => ['values' => $metrics['trips'], 'unallocated' => 0.0, 'format' => 'int'],
                'km' => ['values' => $metrics['km'], 'unallocated' => 0.0, 'format' => 'km'],
                'tons' => ['values' => $metrics['tons'], 'unallocated' => 0.0, 'format' => 'tons'],
                'minutes' => ['values' => $metrics['minutes'], 'unallocated' => 0.0, 'format' => 'duration'],
                'liters' => [
                    'values' => $metrics['liters'],
                    'unallocated' => $fuelUnallocatedLiters,
                    'unallocated_label' => 'Alimentari fara cursa',
                    'format' => 'liters',
                ],
                'costs' => [
                    'values' => $metrics['costs'],
                    'unallocated' => $costsUnallocated,
                    'unallocated_label' => 'Nealocat (reparatii, salariu, alimentari fara cursa)',
                    'format' => 'money',
                ],
                'operational' => [
                    'values' => $metrics['operational'],
                    'unallocated' => $repairCost + $fuelUnallocatedCost,
                    'unallocated_label' => 'Nealocat (reparatii, alimentari fara cursa)',
                    'format' => 'money',
                ],
                // Salariul pe zilele lucrate pe fiecare tip (vezi buildSalaryCost).
                'salary' => [
                    'values' => (array) ($salaryByBucket['cost'] ?? []),
                    'unallocated' => max(0.0, $salaryCost - array_sum((array) ($salaryByBucket['cost'] ?? []))),
                    'format' => 'money',
                ],
                'salary_days' => ['values' => (array) ($salaryByBucket['days'] ?? []), 'unallocated' => 0.0, 'format' => 'days'],
                'diurne' => [
                    'values' => $metrics['diurne'],
                    'unallocated' => $diurneUnallocated,
                    'unallocated_label' => 'Curse fara tip (faze)',
                    'format' => 'int',
                ],
                'diurne_value' => ['values' => $metrics['diurne_value'], 'unallocated' => 0.0, 'format' => 'money'],
                'profit' => [
                    'values' => $metrics['profit'],
                    'unallocated' => -$costsUnallocated,
                    'unallocated_label' => 'Nealocat (reparatii, salariu, alimentari fara cursa)',
                    'format' => 'money',
                ],
            ],
        ];
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
            'total_transported_tons' => array_sum(array_map(static fn (array $row): float => (float) ($row['transported_tons'] ?? 0), $trips)),
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
                'transported_tons' => 0.0,
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
            $rows[$id]['transported_tons'] += (float) ($trip['transported_tons'] ?? 0);
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
            $row['transported_tons'] += (float) ($trip['transported_tons'] ?? 0);
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
            $tons[$key] = ['label' => $label, 'transported' => 0.0, 'delivered' => 0.0];
            $transportDistribution[$key] = ['label' => $label, 'count' => 0];
        }

        foreach ($trips as $trip) {
            $bucket = (string) ($trip['transport_bucket'] ?? 'other');
            if (!isset($tons[$bucket])) {
                continue;
            }
            $tons[$bucket]['transported'] += (float) ($trip['transported_tons'] ?? 0);
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
                'transported' => array_map(static fn (array $row): float => round((float) $row['transported'], 2), array_values($tons)),
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
        [$row['transported_tons'], $row['delivered_tons']] = $this->tripTons($row, (string) $row['transport_bucket']);
        $row['clients'] = max(0, (int) ($row['nr_clienti'] ?? 0));
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

    private function emptyDailyRow(string $date): array
    {
        return [
            'date' => $date,
            'trips' => 0,
            'kilometers' => 0.0,
            'transported_tons' => 0.0,
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

    /**
     * Clientii si tonele livrate pe client. Doar cursele de Distributie si
     * Primar + Distributie au clienti; Primar si Compresor transporta fara clienti.
     *
     * Raportul se face doar pe cursele care au numarul de clienti completat:
     * o cursa cu tone dar fara clienti ar umfla artificial tonele pe client.
     */
    private function buildClientStats(array $trips): array
    {
        $clients = 0;
        $tons = 0.0;
        $withoutClients = 0;
        foreach ($trips as $trip) {
            if (!in_array((string) ($trip['transport_bucket'] ?? ''), ['distributie', 'primar_distributie'], true)) {
                continue;
            }
            $tripClients = (int) ($trip['clients'] ?? 0);
            $tripTons = (float) ($trip['delivered_tons'] ?? 0);
            if ($tripClients > 0) {
                $clients += $tripClients;
                $tons += $tripTons;
            } elseif ($tripTons > 0) {
                $withoutClients++;
            }
        }

        return [
            'clients_total' => $clients,
            'clients_delivered' => $clients,
            'tons_delivered_with_clients' => $tons,
            'delivered_per_client' => $clients > 0 ? $tons / $clients : null,
            'delivered_trips_without_clients' => $withoutClients,
        ];
    }

    /**
     * Tonele unei curse, dupa tipul de transport:
     *  - Primar si Compresor transporta marfa: Tone transportate = cantitatea incarcata;
     *  - Distributie si Primar + Distributie livreaza la clienti: Tone livrate =
     *    tona livrata cand e completata (poate fi mai mica decat incarcatura),
     *    altfel cantitatea incarcata.
     * O cursa intra intr-o singura coloana, deci cele doua nu se aduna de doua ori.
     *
     * @return array{0: float, 1: float} [tone transportate, tone livrate]
     */
    private function tripTons(array $row, string $bucket): array
    {
        $loaded = $this->normalizeTons($row['cantitate_incarcata'] ?? null, $row);

        if ($bucket === 'distributie' || $bucket === 'primar_distributie') {
            $delivered = $this->normalizeTons($row['tona_livrata'] ?? null, $row);

            return [0.0, $delivered > 0 ? $delivered : $loaded];
        }

        if ($bucket === 'compresor' && $loaded <= 0) {
            $loaded = $this->normalizeTons($row['cantitate_prelevata'] ?? null, $row);
            if ($loaded <= 0) {
                $loaded = $this->normalizeTons($row['tona_livrata'] ?? null, $row);
            }
        }

        return [$loaded, 0.0];
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

    /** Filtrul de beneficiar din bara de sus (un singur beneficiar, ca la Tip transport). */
    private function beneficiaryFilterSql(string $column, array $filters, array &$params, string $prefix): string
    {
        $beneficiaryId = (int) ($filters['beneficiar_id'] ?? 0);
        if ($beneficiaryId <= 0) {
            return '';
        }
        $params[':' . $prefix] = $beneficiaryId;

        return $column . ' = :' . $prefix;
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
        // Costul cursei = tot ce s-a inregistrat pe cursa: cheltuielile platite
        // (suma) plus cele de refacturat (refacturare_suma), fiindca firma le
        // plateste intai. Un rand este fie una, fie alta, deci nu se dubleaza.
        // Refacturarile trecute in "Refacturat" raman cost, dar sunt si bani
        // recuperati: se aduna separat (total_refacturare_facturata) si intra in
        // profit, nu se scad din cost.
        $hasInvoiced = $this->columnExists('curse_cheltuieli', 'refacturare_facturata');
        $invoicedFlag = $hasInvoiced ? 'COALESCE(refacturare_facturata, 0) = 1' : '0 = 1';

        return "
            LEFT JOIN (
                SELECT
                    cursa_id,
                    SUM(COALESCE(suma, 0)) + SUM(COALESCE(refacturare_suma, 0)) AS total_cheltuieli,
                    SUM(COALESCE(suma, 0)) AS total_cheltuieli_platite,
                    SUM(COALESCE(refacturare_suma, 0)) AS total_refacturare,
                    SUM(CASE WHEN {$invoicedFlag} THEN COALESCE(refacturare_suma, 0) ELSE 0 END) AS total_refacturare_facturata,
                    SUM(CASE WHEN tip_cheltuiala = 'motorina' THEN COALESCE(suma, 0) ELSE 0 END) AS total_motorina,
                    SUM(CASE WHEN tip_cheltuiala <> 'motorina' THEN COALESCE(suma, 0) ELSE 0 END) AS total_alte_cheltuieli,
                    SUM(CASE WHEN tip_cheltuiala = 'diurna' THEN COALESCE(suma, 0) ELSE 0 END)
                        + SUM(CASE WHEN COALESCE(refacturare_tip_cheltuiala, tip_cheltuiala) = 'diurna' THEN COALESCE(refacturare_suma, 0) ELSE 0 END) AS total_diurna
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

    private function activeRaceCondition(string $alias = 'c'): string
    {
        static $hasDeletedAt = null;

        if ($hasDeletedAt === null) {
            $hasDeletedAt = $this->columnExists('curse_dispecer', 'deleted_at');
        }

        return $hasDeletedAt ? $alias . '.deleted_at IS NULL' : '1=1';
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
                'total_transported_tons' => 0,
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
                'tons' => ['labels' => [], 'transported' => [], 'delivered' => []],
                'kilometers_timeline' => ['labels' => [], 'values' => []],
                'fuel_timeline' => ['labels' => [], 'values' => []],
                'cost_distribution' => ['labels' => [], 'values' => []],
                'transport_distribution' => ['labels' => [], 'values' => []],
            ],
            'updatedAt' => date('Y-m-d H:i:s'),
        ];
    }
}
