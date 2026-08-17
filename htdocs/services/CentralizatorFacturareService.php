<?php
declare(strict_types=1);

class CentralizatorFacturareService
{
    private const TRANSPORT_TYPES = [
        'primar' => ['label' => 'Primar km', 'short' => 'Primar km', 'color' => '#2f7df4'],
        'primar_tona' => ['label' => 'Primar tone', 'short' => 'Primar tone', 'color' => '#10b981'],
        'primar_distributie' => ['label' => 'P+D (Primar+Distribuție)', 'short' => 'P+D', 'color' => '#f97316'],
        'distributie' => ['label' => 'Distribuție', 'short' => 'Distribuție', 'color' => '#8b5cf6'],
        'compresor' => ['label' => 'Compresor', 'short' => 'Compresor', 'color' => '#06b6d4'],
    ];

    private const ACTIVITY_OPTIONS = [
        '' => 'Toate',
        'primar' => 'Primar km',
        'primar_tona' => 'Primar tone',
        'primar_distributie' => 'P+D',
        'distributie' => 'Distribuție',
        'compresor' => 'Compresor',
    ];

    private const DISTRIBUTION_TYPES = ['distributie', 'primar_distributie'];
    private const PRIMARY_ROUTE_TYPES = ['primar', 'primar_distributie'];
    private const PER_PAGE_OPTIONS = [10, 25, 50];
    private const ROAD_TAX_LABELS = [
        'taxa_acces' => 'Taxă acces',
        'port' => 'Port',
        'trece' => 'Trecere',
    ];
    private const DONUT_COLORS = ['#2f7df4', '#10b981', '#f97316', '#8b5cf6', '#06b6d4', '#ef4444', '#64748b'];

    public function __construct(private PDO $db)
    {
    }

    public function getReport(array $input): array
    {
        $filters = $this->normalizeFilters($input);
        $core = $this->buildCore($filters);

        $previousFilters = $filters;
        $previousMonth = (new DateTimeImmutable((string) $filters['date_start']))->modify('-1 month')->format('Y-m');
        $previousFilters['month'] = $previousMonth;
        $bounds = $this->monthBounds($previousMonth);
        $previousFilters['month_label'] = $this->formatMonthLabel($previousMonth);
        $previousFilters['date_start'] = $bounds['start'];
        $previousFilters['date_end'] = $bounds['end'];
        $previousFilters['date_next'] = $bounds['next'];
        $previousCore = $this->buildCore($previousFilters);

        $kpis = $this->buildKpis($filters, $core, $previousCore);

        return [
            'filters' => $filters,
            'kpis' => $kpis,
            'summary' => [
                'trip_count' => $core['trip_count'],
                'primary_km' => $core['activity']['by_type']['primar']['km'] ?? 0,
                'distribution_tone' => $core['distribution']['total_tone'] ?? 0,
                'refacturari_ron' => $core['refacturari']['total_amount'] ?? 0,
                'total_value' => $core['total_value'],
            ],
            'activity' => $core['activity'],
            'primary_routes' => $core['primary_routes'],
            'distribution' => $core['distribution'],
            'vehicles' => $core['vehicles'],
            'refacturari' => $core['refacturari'],
            'visibility' => $this->buildVisibility($filters, $core),
            'lookups' => $this->buildLookups($filters),
            'warnings' => array_values(array_unique(array_filter(array_merge(
                $core['distribution']['warnings'] ?? [],
                $core['primary_routes']['warnings'] ?? [],
                $core['vehicles']['warnings'] ?? [],
                $core['refacturari']['warnings'] ?? []
            )))),
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }

    public function getExportData(array $input): array
    {
        return $this->getReport(array_merge($input, [
            'page_no' => 1,
            'per_page' => 100000,
        ]));
    }

    public function normalizeFilters(array $input): array
    {
        $month = $this->normalizeMonth((string) ($input['month'] ?? ''));
        if ($month === '') {
            $month = $this->findDefaultMonth();
        }

        $bounds = $this->monthBounds($month);
        /*
         * beneficiar_id <= 0 (sau lipsa) inseamna "toti beneficiarii", adica
         * situatia generala - acesta este si comportamentul implicit al paginii.
         * Un id pozitiv restrange raportul la un singur client.
         */
        $beneficiaryId = $this->normalizePositiveInt($input['beneficiar_id'] ?? null);

        $activity = trim(strtolower((string) ($input['tip_activitate'] ?? '')));
        if (!array_key_exists($activity, self::ACTIVITY_OPTIONS)) {
            $activity = '';
        }

        $perPage = $this->normalizePositiveInt($input['per_page'] ?? ($input['ref_per_page'] ?? 10));
        if (!in_array($perPage, self::PER_PAGE_OPTIONS, true) && $perPage !== 100000) {
            $perPage = 10;
        }

        $vehicleSort = trim((string) ($input['vehicle_sort'] ?? 'capacity_asc'));
        if (!array_key_exists($vehicleSort, $this->vehicleSortOptions())) {
            $vehicleSort = 'capacity_asc';
        }

        return [
            'month' => $month,
            'month_label' => $this->formatMonthLabel($month),
            'date_start' => $bounds['start'],
            'date_end' => $bounds['end'],
            'date_next' => $bounds['next'],
            'beneficiar_id' => $beneficiaryId,
            'tip_activitate' => $activity,
            'tip_marfa' => $this->normalizeCargoKey((string) ($input['tip_marfa'] ?? '')),
            'loc_incarcare_id' => $this->normalizePositiveInt($input['loc_incarcare_id'] ?? null),
            'zona_distributie_id' => $this->normalizePositiveInt($input['zona_distributie_id'] ?? null),
            'ruta' => $this->normalizeRouteKey((string) ($input['ruta'] ?? '')),
            'vehicle_id' => $this->normalizePositiveInt($input['vehicle_id'] ?? null),
            'vehicle_sort' => $vehicleSort,
            'page_no' => max(1, $this->normalizePositiveInt($input['page_no'] ?? ($input['p'] ?? 1))),
            'per_page' => $perPage,
        ];
    }

    private function buildCore(array $filters): array
    {
        $tripRows = $this->fetchTripRows($filters, 'trip');
        $refRows = $this->fetchRefacturareRows($filters, 'ref');

        $activity = $this->buildActivitySummary($tripRows);
        $distribution = $this->buildDistributionSection($tripRows, $filters);
        $primaryRoutes = $this->buildPrimaryRoutes($tripRows, $filters);
        $vehicles = $this->buildVehicleSection($tripRows, $filters);
        $refacturari = $this->buildRefacturareSection($refRows, $filters);

        return [
            'trip_rows' => $tripRows,
            'ref_rows' => $refRows,
            'trip_count' => count($tripRows),
            'total_value' => round(array_sum(array_map(fn (array $row): float => $this->rowValue($row), $tripRows)), 2),
            'activity' => $activity,
            'distribution' => $distribution,
            'primary_routes' => $primaryRoutes,
            'vehicles' => $vehicles,
            'refacturari' => $refacturari,
        ];
    }

    private function buildKpis(array $filters, array $core, array $previousCore): array
    {
        $mode = (string) $filters['tip_activitate'];
        $activity = $core['activity']['by_type'];
        $previous = $previousCore['activity']['by_type'];
        $cards = [];

        $add = function (string $key, string $title, float $value, string $unit, string $theme, string $icon, float $previousValue = 0.0) use (&$cards): void {
            $cards[] = [
                'key' => $key,
                'title' => $title,
                'value' => $value,
                'unit' => $unit,
                'theme' => $theme,
                'icon' => $icon,
                'comparison' => $this->comparisonValue($previousValue, $value),
            ];
        };

        if ($mode === 'primar') {
            $add('primary_km', 'Total km Primar', (float) ($activity['primar']['km'] ?? 0), 'km', 'blue', 'bi-signpost-split-fill', (float) ($previous['primar']['km'] ?? 0));
            $add('primary_trips', 'Total curse Primar km', (float) ($activity['primar']['trips'] ?? 0), 'curse', 'green', 'bi-truck-front-fill', (float) ($previous['primar']['trips'] ?? 0));
            $add('primary_value', 'Valoare Primar', (float) ($activity['primar']['value'] ?? 0), 'RON', 'purple', 'bi-cash-stack', (float) ($previous['primar']['value'] ?? 0));
        } elseif ($mode === 'primar_tona') {
            $add('primary_tone', 'Total tone Primar', (float) ($activity['primar_tona']['tone'] ?? 0), 'tone', 'blue', 'bi-box-seam-fill', (float) ($previous['primar_tona']['tone'] ?? 0));
            $add('primary_tone_trips', 'Total curse Primar tone', (float) ($activity['primar_tona']['trips'] ?? 0), 'curse', 'green', 'bi-truck-front-fill', (float) ($previous['primar_tona']['trips'] ?? 0));
            $add('primary_tone_value', 'Valoare Primar tone', (float) ($activity['primar_tona']['value'] ?? 0), 'RON', 'purple', 'bi-cash-stack', (float) ($previous['primar_tona']['value'] ?? 0));
        } elseif ($mode === 'primar_distributie') {
            $add('pd_km', 'Km Primar P+D', (float) ($activity['primar_distributie']['km'] ?? 0), 'km', 'blue', 'bi-signpost-split-fill', (float) ($previous['primar_distributie']['km'] ?? 0));
            $add('pd_tone', 'Tone Distribuție P+D', (float) ($activity['primar_distributie']['tone'] ?? 0), 'tone', 'purple', 'bi-fuel-pump-fill', (float) ($previous['primar_distributie']['tone'] ?? 0));
            $add('pd_trips', 'Total curse P+D', (float) ($activity['primar_distributie']['trips'] ?? 0), 'curse', 'green', 'bi-truck-front-fill', (float) ($previous['primar_distributie']['trips'] ?? 0));
        } elseif ($mode === 'distributie') {
            $add('distribution_tone', 'Total tone Distribuție', (float) ($activity['distributie']['tone'] ?? 0), 'tone', 'purple', 'bi-fuel-pump-fill', (float) ($previous['distributie']['tone'] ?? 0));
            $add('distribution_trips', 'Total curse Distribuție', (float) ($activity['distributie']['trips'] ?? 0), 'curse', 'green', 'bi-truck-front-fill', (float) ($previous['distributie']['trips'] ?? 0));
            $add('distribution_value', 'Valoare Distribuție', (float) ($activity['distributie']['value'] ?? 0), 'RON', 'blue', 'bi-cash-stack', (float) ($previous['distributie']['value'] ?? 0));
        } elseif ($mode === 'compresor') {
            $add('compressor_trips', 'Total curse Compresor', (float) ($activity['compresor']['trips'] ?? 0), 'curse', 'green', 'bi-truck-front-fill', (float) ($previous['compresor']['trips'] ?? 0));
            $add('compressor_activity', 'Activitate Compresor', (float) ($activity['compresor']['activity'] ?? 0), (string) ($activity['compresor']['activity_unit'] ?? 'activ.'), 'blue', 'bi-speedometer2', (float) ($previous['compresor']['activity'] ?? 0));
            $add('compressor_value', 'Valoare Compresor', (float) ($activity['compresor']['value'] ?? 0), 'RON', 'purple', 'bi-cash-stack', (float) ($previous['compresor']['value'] ?? 0));
        } else {
            $add('primary_km', 'Total km (Primar km)', (float) ($activity['primar']['km'] ?? 0), 'km', 'blue', 'bi-signpost-split-fill', (float) ($previous['primar']['km'] ?? 0));
            $add('all_trips', 'Total curse', (float) ($core['trip_count'] ?? 0), 'curse', 'green', 'bi-truck-front-fill', (float) ($previousCore['trip_count'] ?? 0));
            $add('distribution_tone', 'Total tone (Distribuție)', (float) ($core['distribution']['total_tone'] ?? 0), 'tone', 'purple', 'bi-fuel-pump-fill', (float) ($previousCore['distribution']['total_tone'] ?? 0));
        }

        return [
            'cards' => $cards,
            'refacturari' => [
                'key' => 'refacturari',
                'title' => 'Total refacturări',
                'value' => (float) ($core['refacturari']['total_amount'] ?? 0),
                'unit' => 'RON',
                'theme' => 'orange',
                'icon' => 'bi-receipt-cutoff',
                'comparison' => $this->comparisonValue((float) ($previousCore['refacturari']['total_amount'] ?? 0), (float) ($core['refacturari']['total_amount'] ?? 0)),
            ],
        ];
    }

    private function buildActivitySummary(array $rows): array
    {
        $summary = [];
        foreach (self::TRANSPORT_TYPES as $type => $meta) {
            $summary[$type] = [
                'key' => $type,
                'label' => $meta['label'],
                'short_label' => $meta['short'],
                'color' => $meta['color'],
                'trips' => 0,
                'km' => 0.0,
                'tone' => 0.0,
                'activity' => 0.0,
                'activity_unit' => $type === 'compresor' ? 'activ.' : '',
                'value' => 0.0,
                'share_percent' => 0.0,
            ];
        }

        foreach ($rows as $row) {
            $type = (string) ($row['tip_transport'] ?? '');
            if (!isset($summary[$type])) {
                continue;
            }
            $summary[$type]['trips']++;
            $summary[$type]['value'] += $this->rowValue($row);

            if ($type === 'primar') {
                $summary[$type]['km'] += $this->rowKm($row);
            } elseif ($type === 'primar_tona') {
                $summary[$type]['tone'] += $this->normalizedLoadedTons($row);
            } elseif ($type === 'primar_distributie') {
                $summary[$type]['km'] += $this->rowKm($row);
                $summary[$type]['tone'] += $this->normalizedLoadedTons($row);
            } elseif ($type === 'distributie') {
                $summary[$type]['tone'] += $this->normalizedLoadedTons($row);
            } elseif ($type === 'compresor') {
                $summary[$type]['activity'] += $this->compressorActivityValue($row);
                $summary[$type]['activity_unit'] = $this->compressorActivityUnit($row);
                $summary[$type]['km'] += max(0.0, (float) ($row['km_dislocare'] ?? 0));
            }
        }

        $totalTrips = array_sum(array_column($summary, 'trips'));
        foreach ($summary as &$row) {
            $row['km'] = round((float) $row['km'], 2);
            $row['tone'] = round((float) $row['tone'], 4);
            $row['activity'] = round((float) $row['activity'], 4);
            $row['value'] = round((float) $row['value'], 2);
            $row['share_percent'] = $totalTrips > 0 ? round(((int) $row['trips'] / $totalTrips) * 100, 2) : 0.0;
        }
        unset($row);

        return [
            'by_type' => $summary,
            'rows' => array_values($summary),
            'totals' => [
                'trips' => $totalTrips,
                'km' => round(array_sum(array_column($summary, 'km')), 2),
                'tone' => round(array_sum(array_column($summary, 'tone')), 4),
                'value' => round(array_sum(array_column($summary, 'value')), 2),
            ],
            'chart' => $this->buildDonutChart(array_values($summary), 'trips'),
        ];
    }

    private function buildDistributionSection(array $rows, array $filters): array
    {
        $types = $this->distributionTypesForMode((string) $filters['tip_activitate']);
        $rows = array_values(array_filter($rows, static fn (array $row): bool => in_array((string) ($row['tip_transport'] ?? ''), $types, true)));

        $warnings = [];
        $tariffs = [];
        foreach ($rows as $row) {
            $tariff = round((float) ($row['pret_tarifare'] ?? 0), 4);
            if ($tariff > 0) {
                $tariffs[] = $tariff;
            }
        }

        $buckets = $this->classifyTariffs($tariffs, $warnings);
        $unknownKey = 'unknown';
        $hasUnknown = false;
        $totalTone = 0.0;
        $cargoTotals = [];
        $matrix = [];

        foreach ($rows as $row) {
            $tone = $this->normalizedLoadedTons($row);
            if ($tone <= 0) {
                continue;
            }
            $totalTone += $tone;
            $tariff = round((float) ($row['pret_tarifare'] ?? 0), 4);
            $bucketKey = $tariff > 0 ? $this->rateKey($tariff) : $unknownKey;
            if ($bucketKey === $unknownKey || !isset($buckets[$bucketKey])) {
                $hasUnknown = true;
                $bucketKey = $unknownKey;
            }

            if ($hasUnknown && !isset($buckets[$unknownKey])) {
                $buckets[$unknownKey] = [
                    'key' => $unknownKey,
                    'label' => 'Tarif neidentificat',
                    'tariff' => null,
                    'tone' => 0.0,
                    'value' => 0.0,
                    'percent' => 0.0,
                    'color' => '#64748b',
                ];
            }

            $value = $this->rowValue($row);
            $buckets[$bucketKey]['tone'] += $tone;
            $buckets[$bucketKey]['value'] += $value;

            $cargoKeys = $this->parseCargoKeys((string) ($row['tip_marfa'] ?? ''));
            $cargoKey = 'nespecificat';
            $cargoLabel = 'Nespecificat';
            $isUnresolved = false;
            if (count($cargoKeys) === 1) {
                $cargoKey = $cargoKeys[0];
                $cargoLabel = $this->cargoLabel($cargoKey);
            } elseif (count($cargoKeys) > 1) {
                $cargoKey = 'multi_marfa_nealocat';
                $cargoLabel = 'Multi-marfă nealocat';
                $isUnresolved = true;
                $warnings[] = 'Există curse cu mai multe tipuri de marfă fără alocare exactă pe tonaj; tonajul este păstrat separat ca nealocat.';
            }

            $cargoTotals[$cargoKey] ??= [
                'key' => $cargoKey,
                'label' => $cargoLabel,
                'tone' => 0.0,
                'percent' => 0.0,
                'is_unresolved' => $isUnresolved,
            ];
            $cargoTotals[$cargoKey]['tone'] += $tone;
            $cargoTotals[$cargoKey]['is_unresolved'] = $cargoTotals[$cargoKey]['is_unresolved'] || $isUnresolved;

            $matrix[$cargoKey] ??= [
                'key' => $cargoKey,
                'label' => $cargoLabel,
                'buckets' => [],
                'total_tone' => 0.0,
                'percent' => 0.0,
                'is_unresolved' => $isUnresolved,
            ];
            $matrix[$cargoKey]['buckets'][$bucketKey] = ($matrix[$cargoKey]['buckets'][$bucketKey] ?? 0.0) + $tone;
            $matrix[$cargoKey]['total_tone'] += $tone;
            $matrix[$cargoKey]['is_unresolved'] = $matrix[$cargoKey]['is_unresolved'] || $isUnresolved;
        }

        if ($hasUnknown) {
            $warnings[] = 'Există curse de distribuție fără pret_tarifare istoric valid; au fost grupate la Tarif neidentificat.';
        }

        foreach ($buckets as &$bucket) {
            $bucket['tone'] = round((float) $bucket['tone'], 4);
            $bucket['value'] = round((float) $bucket['value'], 2);
            $bucket['percent'] = $totalTone > 0 ? round(((float) $bucket['tone'] / $totalTone) * 100, 2) : 0.0;
        }
        unset($bucket);

        foreach ($cargoTotals as &$cargo) {
            $cargo['tone'] = round((float) $cargo['tone'], 4);
            $cargo['percent'] = $totalTone > 0 ? round(((float) $cargo['tone'] / $totalTone) * 100, 2) : 0.0;
        }
        unset($cargo);

        foreach ($matrix as &$row) {
            foreach ($buckets as $bucketKey => $_bucket) {
                $row['buckets'][$bucketKey] = round((float) ($row['buckets'][$bucketKey] ?? 0), 4);
            }
            $row['total_tone'] = round((float) $row['total_tone'], 4);
            $row['percent'] = $totalTone > 0 ? round(((float) $row['total_tone'] / $totalTone) * 100, 2) : 0.0;
        }
        unset($row);

        $cargoTotals = array_values($cargoTotals);
        usort($cargoTotals, static fn (array $a, array $b): int => ((float) $b['tone'] <=> (float) $a['tone']) ?: strcmp((string) $a['label'], (string) $b['label']));
        $matrixRows = array_values($matrix);
        usort($matrixRows, static fn (array $a, array $b): int => ((float) $b['total_tone'] <=> (float) $a['total_tone']) ?: strcmp((string) $a['label'], (string) $b['label']));

        return [
            'total_tone' => round($totalTone, 4),
            'total_value' => round(array_sum(array_column($buckets, 'value')), 2),
            'cargo_totals' => $cargoTotals,
            'tariff_buckets' => array_values($buckets),
            'cargo_by_tariff' => $matrixRows,
            'matrix_totals' => $this->matrixTotals($matrixRows, $buckets, $totalTone),
            'chart' => $this->buildDonutChart(array_values($buckets), 'tone'),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    private function buildPrimaryRoutes(array $rows, array $filters): array
    {
        $mode = (string) $filters['tip_activitate'];
        if ($mode === '') {
            $types = ['primar'];
        } elseif (in_array($mode, self::PRIMARY_ROUTE_TYPES, true)) {
            $types = [$mode];
        } else {
            return ['routes' => [], 'totals' => ['trips' => 0, 'km' => 0.0, 'value' => 0.0], 'warnings' => []];
        }

        $warnings = [];
        $groups = [];
        foreach ($rows as $row) {
            if (!in_array((string) ($row['tip_transport'] ?? ''), $types, true)) {
                continue;
            }
            $key = ((int) ($row['loc_incarcare_id'] ?? 0)) . ':' . ((int) ($row['zona_distributie_id'] ?? 0));
            $groups[$key] ??= [
                'key' => $key,
                'route_label' => $this->routeLabel($row),
                'route_short' => $this->routeShort($row),
                'trips' => 0,
                'km' => 0.0,
                'value' => 0.0,
                'rates' => [],
                'share_percent' => 0.0,
            ];
            $km = $this->rowKm($row);
            $value = $this->primaryRouteValue($row, $warnings);
            $rate = $this->primaryRouteRate($row, $value, $km);
            $groups[$key]['trips']++;
            $groups[$key]['km'] += $km;
            $groups[$key]['value'] += $value;
            if ($rate !== null) {
                $groups[$key]['rates'][$this->rateKey($rate)] = $rate;
            }
        }

        $totalTrips = array_sum(array_column($groups, 'trips'));
        foreach ($groups as &$group) {
            $rates = array_values($group['rates']);
            sort($rates, SORT_NUMERIC);
            $group['km'] = round((float) $group['km'], 2);
            $group['value'] = round((float) $group['value'], 2);
            $group['share_percent'] = $totalTrips > 0 ? round(((int) $group['trips'] / $totalTrips) * 100, 2) : 0.0;
            $group['rate_label'] = count($rates) === 1 ? $this->formatNumber($rates[0], 2) : (count($rates) > 1 ? 'tarife multiple' : '-');
            unset($group['rates']);
        }
        unset($group);

        $routes = array_values($groups);
        usort($routes, static fn (array $a, array $b): int => ((int) $b['trips'] <=> (int) $a['trips']) ?: strcmp((string) $a['route_label'], (string) $b['route_label']));
        foreach ($routes as $index => &$route) {
            $route['color'] = self::DONUT_COLORS[$index % count(self::DONUT_COLORS)];
        }
        unset($route);

        return [
            'routes' => $routes,
            'totals' => [
                'trips' => $totalTrips,
                'km' => round(array_sum(array_column($routes, 'km')), 2),
                'value' => round(array_sum(array_column($routes, 'value')), 2),
            ],
            'chart' => $this->buildDonutChart($routes, 'trips'),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    private function buildVehicleSection(array $rows, array $filters): array
    {
        $warnings = [];
        $vehicles = [];
        $detailMode = (string) $filters['tip_activitate'];
        $tariffBuckets = $this->vehicleTariffBuckets($rows, $detailMode);
        foreach ($rows as $row) {
            $vehicleId = (int) ($row['vehicle_id'] ?? 0);
            $hasVehicle = $vehicleId > 0 && trim((string) ($row['nr_inmatriculare'] ?? '')) !== '';
            $key = $hasVehicle ? (string) $vehicleId : 'unassigned';
            $vehicles[$key] ??= $this->emptyVehicleBucket($key, $hasVehicle ? $row : null);

            if (!$hasVehicle) {
                $warnings[] = 'Există curse fără vehicul alocat; sunt incluse în Vehicul nealocat pentru reconciliere.';
            }

            $type = (string) ($row['tip_transport'] ?? '');
            $km = $this->rowKm($row);
            $tone = $this->normalizedLoadedTons($row);
            $value = $this->rowValue($row);
            $vehicles[$key]['trips']++;
            $vehicles[$key]['total_value'] += $value;
            $routeKey = $this->routeKeyFromRow($row);
            $vehicles[$key]['routes'][$routeKey] ??= [
                'key' => $routeKey,
                'label' => $this->routeAuditLabel($row),
            ];
            $vehicles[$key]['detail_rows'][] = $this->vehicleTripDetailRow($row, $detailMode, $tariffBuckets);

            if ($type === 'primar') {
                $vehicles[$key]['primar']['_trips']++;
                $vehicles[$key]['primar']['km'] += $km;
                $vehicles[$key]['primar']['value'] += $value;
            } elseif ($type === 'primar_tona') {
                $vehicles[$key]['primar_tona']['_trips']++;
                $vehicles[$key]['primar_tona']['tone'] += $tone;
                $vehicles[$key]['primar_tona']['value'] += $value;
            } elseif ($type === 'distributie') {
                $vehicles[$key]['distributie']['_trips']++;
                $vehicles[$key]['distributie']['tone'] += $tone;
                $vehicles[$key]['distributie']['value'] += $value;
            } elseif ($type === 'primar_distributie') {
                $vehicles[$key]['primar_distributie']['_trips']++;
                $vehicles[$key]['primar_distributie']['km'] += $km;
                $vehicles[$key]['primar_distributie']['tone'] += $tone;
                $vehicles[$key]['primar_distributie']['value'] += $value;
            } elseif ($type === 'compresor') {
                $vehicles[$key]['compresor']['_trips']++;
                $vehicles[$key]['compresor']['activity'] += $this->compressorActivityValue($row);
                $vehicles[$key]['compresor']['tone'] += $tone;
                $vehicles[$key]['compresor']['unit'] = $this->compressorActivityUnit($row);
                $vehicles[$key]['compresor']['value'] += $value;
            }
        }

        foreach ($vehicles as &$vehicle) {
            $this->roundVehicleBucket($vehicle);
            $vehicle['route_summary'] = $this->compactRouteSummary((array) ($vehicle['routes'] ?? []));
            $vehicle['route_count'] = count((array) ($vehicle['routes'] ?? []));
            usort($vehicle['detail_rows'], static fn (array $a, array $b): int => strcmp((string) $a['sort_date'], (string) $b['sort_date']) ?: ((int) $a['trip_id'] <=> (int) $b['trip_id']));
        }
        unset($vehicle);

        $vehicleRows = array_values($vehicles);
        $this->sortVehicleRows($vehicleRows, (string) $filters['vehicle_sort']);

        $matrixTotals = $this->vehicleMatrixTotals($vehicleRows);
        $detail = $this->vehicleDetailRows($vehicleRows, $detailMode);
        $warnings = array_merge($warnings, $this->validateVehicleReconciliation($vehicleRows, $rows));

        return [
            'sort' => (string) $filters['vehicle_sort'],
            'rows' => $vehicleRows,
            'totals' => $matrixTotals,
            'detail_mode' => $detailMode,
            'detail_columns' => $this->vehicleTripDetailColumns($detailMode),
            'detail' => $detail,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    private function buildRefacturareSection(array $expenseRows, array $filters): array
    {
        $warnings = [];
        $groups = [];
        $typeGroups = [];
        $tripRows = [];
        $total = 0.0;

        foreach ($expenseRows as $expense) {
            $amount = round(max(0.0, (float) ($expense['refacturare_suma'] ?? 0)), 2);
            if ($amount <= 0) {
                continue;
            }
            $total += $amount;
            $this->accumulateRefacturareGroups($groups, $expense, $amount, $warnings);
            $this->accumulateRefacturareTypeGroups($typeGroups, $expense, $amount);
            $this->accumulateRefacturareTripRows($tripRows, $expense, $amount);
        }

        $groups = array_values($groups);
        usort($groups, static fn (array $a, array $b): int => ((float) $b['amount'] <=> (float) $a['amount']) ?: strcmp((string) $a['label'], (string) $b['label']));
        $typeGroups = array_values($typeGroups);
        usort($typeGroups, static fn (array $a, array $b): int => ((float) $b['amount'] <=> (float) $a['amount']) ?: strcmp((string) $a['label'], (string) $b['label']));
        $tripRows = array_values($tripRows);
        usort($tripRows, static fn (array $a, array $b): int => strcmp((string) $b['sort_date'], (string) $a['sort_date']) ?: ((int) $b['cursa_id'] <=> (int) $a['cursa_id']));

        $totalRows = count($tripRows);
        $page = (int) $filters['page_no'];
        $perPage = (int) $filters['per_page'];
        $totalPages = max(1, (int) ceil($totalRows / max(1, $perPage)));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;
        $pagedRows = array_slice($tripRows, $offset, $perPage);

        return [
            'total_amount' => round($total, 2),
            'record_count' => count($expenseRows),
            'summary_groups' => $groups,
            'type_groups' => $typeGroups,
            'rows' => $pagedRows,
            'all_rows' => $tripRows,
            'quantity_total' => round(array_sum(array_column($groups, 'quantity')), 4),
            'totals_by_table' => [
                'trip_value' => round(array_sum(array_column($tripRows, 'trip_value')), 2),
                'refacturare' => round(array_sum(array_column($tripRows, 'refacturare_amount')), 2),
            ],
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total_rows' => $totalRows,
                'total_pages' => $totalPages,
                'from' => $totalRows === 0 ? 0 : $offset + 1,
                'to' => min($totalRows, $offset + count($pagedRows)),
            ],
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    private function fetchTripRows(array $filters, string $prefix): array
    {
        $where = $this->buildTripWhere($filters, $this->typesForMode((string) $filters['tip_activitate']), $prefix);
        $sql = "
            SELECT
                c.id,
                c.vehicle_id,
                c.tip_transport,
                c.data_cursa,
                c.data_inceput,
                c.data_sfarsit,
                c.tip_marfa,
                c.capacitate_transport AS cursa_capacitate_transport,
                c.loc_incarcare_id,
                c.zona_distributie_id,
                c.beneficiar_id,
                c.cantitate_incarcata,
                c.km_cursa,
                c.km_totali,
                c.ore_functionare,
                c.km_dislocare,
                c.tona_livrata,
                c.tona_aspirata_lichida,
                c.tona_aspirata_gazoasa,
                c.ore_aspirare,
                c.pret_tarifare,
                c.total_facturare,
                c.cost_km_primar,
                c.cost_km_distributie,
                c.cost_km_mixt,
                c.cost_km_compresor,
                li.nume AS loc_incarcare_nume,
                zd.nume AS zona_distributie_nume,
                bt.nume AS beneficiar_nume,
                v.nr_inmatriculare,
                v.capacitate_transport AS vehicle_capacitate_transport
            FROM curse_dispecer c
            LEFT JOIN configurare_locuri_incarcare li ON li.id = c.loc_incarcare_id
            LEFT JOIN configurare_zone_distributie zd ON zd.id = c.zona_distributie_id
            LEFT JOIN configurare_beneficiari_transport bt ON bt.id = c.beneficiar_id
            LEFT JOIN vehicule v ON v.id = c.vehicle_id
            " . $where['where'] . "
            ORDER BY COALESCE(c.data_inceput, c.data_cursa) ASC, c.id ASC
        ";

        $stmt = $this->db->prepare($sql);
        $this->bindParams($stmt, $where['params']);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    private function fetchRefacturareRows(array $filters, string $prefix): array
    {
        $where = $this->buildRefacturareWhere($filters, $prefix);
        $sql = "
            SELECT
                e.id AS expense_id,
                e.cursa_id,
                e.refacturare_tip_cheltuiala,
                e.tip_cheltuiala,
                e.refacturare_detalii,
                e.refacturare_suma,
                e.refacturare_data,
                e.refacturare_observatii,
                e.data_cheltuiala,
                e.observatii AS expense_observatii,
                c.id AS cursa_id_real,
                c.vehicle_id,
                c.tip_transport,
                c.data_cursa,
                c.data_inceput,
                c.tip_marfa,
                c.capacitate_transport AS cursa_capacitate_transport,
                c.loc_incarcare_id,
                c.zona_distributie_id,
                c.beneficiar_id,
                c.cantitate_incarcata,
                c.km_cursa,
                c.km_totali,
                c.ore_functionare,
                c.km_dislocare,
                c.tona_livrata,
                c.tona_aspirata_lichida,
                c.tona_aspirata_gazoasa,
                c.ore_aspirare,
                c.pret_tarifare,
                c.total_facturare,
                li.nume AS loc_incarcare_nume,
                zd.nume AS zona_distributie_nume,
                bt.nume AS beneficiar_nume,
                v.nr_inmatriculare,
                v.capacitate_transport AS vehicle_capacitate_transport
            FROM curse_cheltuieli e
            INNER JOIN curse_dispecer c ON c.id = e.cursa_id
            LEFT JOIN configurare_locuri_incarcare li ON li.id = c.loc_incarcare_id
            LEFT JOIN configurare_zone_distributie zd ON zd.id = c.zona_distributie_id
            LEFT JOIN configurare_beneficiari_transport bt ON bt.id = c.beneficiar_id
            LEFT JOIN vehicule v ON v.id = c.vehicle_id
            " . $where['where'] . "
            ORDER BY COALESCE(e.refacturare_data, e.data_cheltuiala) DESC, e.id DESC
        ";

        $stmt = $this->db->prepare($sql);
        $this->bindParams($stmt, $where['params']);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    private function buildTripWhere(array $filters, array $types, string $prefix): array
    {
        $where = [
            'c.deleted_at IS NULL',
            'COALESCE(c.data_inceput, c.data_cursa) >= :' . $prefix . '_date_start',
            'COALESCE(c.data_inceput, c.data_cursa) < :' . $prefix . '_date_next',
        ];
        $params = [
            ':' . $prefix . '_date_start' => $filters['date_start'],
            ':' . $prefix . '_date_next' => $filters['date_next'],
        ];

        if ((int) $filters['beneficiar_id'] > 0) {
            $where[] = 'c.beneficiar_id = :' . $prefix . '_beneficiar_id';
            $params[':' . $prefix . '_beneficiar_id'] = (int) $filters['beneficiar_id'];
        }

        if ($types !== []) {
            $placeholders = [];
            foreach (array_values($types) as $index => $type) {
                $placeholder = ':' . $prefix . '_type_' . $index;
                $placeholders[] = $placeholder;
                $params[$placeholder] = $type;
            }
            $where[] = 'c.tip_transport IN (' . implode(', ', $placeholders) . ')';
        } else {
            $where[] = '1 = 0';
        }

        if ((string) $filters['tip_marfa'] !== '') {
            $where[] = "FIND_IN_SET(:" . $prefix . "_cargo, REPLACE(REPLACE(REPLACE(LOWER(COALESCE(c.tip_marfa, '')), ' ', ''), ';', ','), '|', ',')) > 0";
            $params[':' . $prefix . '_cargo'] = (string) $filters['tip_marfa'];
        }

        if ((int) ($filters['loc_incarcare_id'] ?? 0) > 0) {
            $where[] = 'c.loc_incarcare_id = :' . $prefix . '_loc_filter_id';
            $params[':' . $prefix . '_loc_filter_id'] = (int) $filters['loc_incarcare_id'];
        }

        if ((int) ($filters['zona_distributie_id'] ?? 0) > 0) {
            $where[] = 'c.zona_distributie_id = :' . $prefix . '_zone_filter_id';
            $params[':' . $prefix . '_zone_filter_id'] = (int) $filters['zona_distributie_id'];
        }

        $route = $this->parseRouteKey((string) $filters['ruta']);
        if ($route !== null) {
            if ($route['loc'] > 0) {
                $where[] = 'c.loc_incarcare_id = :' . $prefix . '_loc_id';
                $params[':' . $prefix . '_loc_id'] = $route['loc'];
            } else {
                $where[] = 'c.loc_incarcare_id IS NULL';
            }
            if ($route['zone'] > 0) {
                $where[] = 'c.zona_distributie_id = :' . $prefix . '_zone_id';
                $params[':' . $prefix . '_zone_id'] = $route['zone'];
            } else {
                $where[] = 'c.zona_distributie_id IS NULL';
            }
        }

        if ((int) $filters['vehicle_id'] > 0) {
            $where[] = 'c.vehicle_id = :' . $prefix . '_vehicle_id';
            $params[':' . $prefix . '_vehicle_id'] = (int) $filters['vehicle_id'];
        }

        return ['where' => 'WHERE ' . implode(' AND ', $where), 'params' => $params];
    }

    private function buildRefacturareWhere(array $filters, string $prefix): array
    {
        $where = [
            'c.deleted_at IS NULL',
            'COALESCE(e.refacturare_suma, 0) > 0',
            'COALESCE(e.refacturare_data, e.data_cheltuiala) >= :' . $prefix . '_date_start',
            'COALESCE(e.refacturare_data, e.data_cheltuiala) < :' . $prefix . '_date_next',
        ];
        $params = [
            ':' . $prefix . '_date_start' => $filters['date_start'],
            ':' . $prefix . '_date_next' => $filters['date_next'],
        ];

        if ((int) $filters['beneficiar_id'] > 0) {
            $where[] = 'c.beneficiar_id = :' . $prefix . '_beneficiar_id';
            $params[':' . $prefix . '_beneficiar_id'] = (int) $filters['beneficiar_id'];
        }

        $types = $this->typesForMode((string) $filters['tip_activitate']);
        if ($types !== []) {
            $placeholders = [];
            foreach (array_values($types) as $index => $type) {
                $placeholder = ':' . $prefix . '_type_' . $index;
                $placeholders[] = $placeholder;
                $params[$placeholder] = $type;
            }
            $where[] = 'c.tip_transport IN (' . implode(', ', $placeholders) . ')';
        }

        if ((string) $filters['tip_marfa'] !== '') {
            $where[] = "FIND_IN_SET(:" . $prefix . "_cargo, REPLACE(REPLACE(REPLACE(LOWER(COALESCE(c.tip_marfa, '')), ' ', ''), ';', ','), '|', ',')) > 0";
            $params[':' . $prefix . '_cargo'] = (string) $filters['tip_marfa'];
        }

        if ((int) ($filters['loc_incarcare_id'] ?? 0) > 0) {
            $where[] = 'c.loc_incarcare_id = :' . $prefix . '_loc_filter_id';
            $params[':' . $prefix . '_loc_filter_id'] = (int) $filters['loc_incarcare_id'];
        }

        if ((int) ($filters['zona_distributie_id'] ?? 0) > 0) {
            $where[] = 'c.zona_distributie_id = :' . $prefix . '_zone_filter_id';
            $params[':' . $prefix . '_zone_filter_id'] = (int) $filters['zona_distributie_id'];
        }

        $route = $this->parseRouteKey((string) $filters['ruta']);
        if ($route !== null) {
            if ($route['loc'] > 0) {
                $where[] = 'c.loc_incarcare_id = :' . $prefix . '_loc_id';
                $params[':' . $prefix . '_loc_id'] = $route['loc'];
            } else {
                $where[] = 'c.loc_incarcare_id IS NULL';
            }
            if ($route['zone'] > 0) {
                $where[] = 'c.zona_distributie_id = :' . $prefix . '_zone_id';
                $params[':' . $prefix . '_zone_id'] = $route['zone'];
            } else {
                $where[] = 'c.zona_distributie_id IS NULL';
            }
        }

        if ((int) $filters['vehicle_id'] > 0) {
            $where[] = 'c.vehicle_id = :' . $prefix . '_vehicle_id';
            $params[':' . $prefix . '_vehicle_id'] = (int) $filters['vehicle_id'];
        }

        return ['where' => 'WHERE ' . implode(' AND ', $where), 'params' => $params];
    }

    private function buildLookups(array $filters): array
    {
        return [
            'months' => $this->monthOptions((string) $filters['month']),
            'beneficiaries' => $this->beneficiaryOptions(),
            'activity_types' => self::ACTIVITY_OPTIONS,
            'cargo' => $this->cargoOptions($filters),
            'loading_locations' => $this->loadingLocationOptions($filters),
            'unloading_zones' => $this->unloadingZoneOptions($filters),
            'routes' => $this->routeOptions($filters),
            'vehicles' => $this->vehicleOptions($filters),
            'vehicle_sort_options' => $this->vehicleSortOptions(),
            'per_page_options' => self::PER_PAGE_OPTIONS,
        ];
    }

    private function monthOptions(string $selected): array
    {
        $sql = "
            SELECT DISTINCT DATE_FORMAT(activity_date, '%Y-%m') AS ym
            FROM (
                SELECT COALESCE(data_inceput, data_cursa) AS activity_date
                FROM curse_dispecer
                WHERE deleted_at IS NULL
                UNION ALL
                SELECT COALESCE(e.refacturare_data, e.data_cheltuiala) AS activity_date
                FROM curse_cheltuieli e
                INNER JOIN curse_dispecer c ON c.id = e.cursa_id
                WHERE c.deleted_at IS NULL AND COALESCE(e.refacturare_suma, 0) > 0
            ) x
            WHERE activity_date IS NOT NULL
            ORDER BY ym DESC
            LIMIT 36
        ";
        $rows = $this->db->query($sql)->fetchAll() ?: [];
        $options = [];
        foreach ($rows as $row) {
            $ym = (string) ($row['ym'] ?? '');
            if ($ym !== '') {
                $options[$ym] = ['value' => $ym, 'label' => $this->formatMonthLabel($ym)];
            }
        }
        if ($selected !== '' && !isset($options[$selected])) {
            $options[$selected] = ['value' => $selected, 'label' => $this->formatMonthLabel($selected)];
        }
        krsort($options);

        return array_values($options);
    }

    private function beneficiaryOptions(): array
    {
        $stmt = $this->db->query("SELECT id, nume FROM configurare_beneficiari_transport WHERE activ = 1 ORDER BY nume ASC");
        return $stmt->fetchAll() ?: [];
    }

    private function cargoOptions(array $filters): array
    {
        $lookupFilters = array_merge($filters, ['tip_marfa' => '']);
        $where = $this->buildTripWhere($lookupFilters, $this->typesForMode((string) $filters['tip_activitate']), 'cargo');
        $stmt = $this->db->prepare("SELECT DISTINCT c.tip_marfa FROM curse_dispecer c " . $where['where'] . " AND COALESCE(TRIM(c.tip_marfa), '') <> '' ORDER BY c.tip_marfa ASC");
        $this->bindParams($stmt, $where['params']);
        $stmt->execute();
        $options = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            foreach ($this->parseCargoKeys((string) ($row['tip_marfa'] ?? '')) as $key) {
                $options[$key] = ['value' => $key, 'label' => $this->cargoLabel($key)];
            }
        }
        uasort($options, static fn (array $a, array $b): int => strcmp((string) $a['label'], (string) $b['label']));

        return array_values($options);
    }

    private function routeOptions(array $filters): array
    {
        $lookupFilters = array_merge($filters, ['ruta' => '']);
        $where = $this->buildTripWhere($lookupFilters, $this->typesForMode((string) $filters['tip_activitate']), 'route');
        $sql = "
            SELECT
                c.loc_incarcare_id,
                c.zona_distributie_id,
                li.nume AS loc_incarcare_nume,
                zd.nume AS zona_distributie_nume,
                COUNT(*) AS total_curse
            FROM curse_dispecer c
            LEFT JOIN configurare_locuri_incarcare li ON li.id = c.loc_incarcare_id
            LEFT JOIN configurare_zone_distributie zd ON zd.id = c.zona_distributie_id
            " . $where['where'] . "
            GROUP BY c.loc_incarcare_id, c.zona_distributie_id, li.nume, zd.nume
            ORDER BY total_curse DESC, loc_incarcare_nume ASC, zona_distributie_nume ASC
        ";
        $stmt = $this->db->prepare($sql);
        $this->bindParams($stmt, $where['params']);
        $stmt->execute();
        $options = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $key = ((int) ($row['loc_incarcare_id'] ?? 0)) . ':' . ((int) ($row['zona_distributie_id'] ?? 0));
            $options[] = ['value' => $key, 'label' => $this->routeAuditLabel($row)];
        }
        usort($options, static fn (array $a, array $b): int => strcmp((string) $a['label'], (string) $b['label']) ?: strcmp((string) $a['value'], (string) $b['value']));

        return $options;
    }

    private function loadingLocationOptions(array $filters): array
    {
        $lookupFilters = array_merge($filters, ['loc_incarcare_id' => 0, 'ruta' => '']);
        $where = $this->buildTripWhere($lookupFilters, $this->typesForMode((string) $filters['tip_activitate']), 'loc_lookup');
        $sql = "
            SELECT
                c.loc_incarcare_id AS id,
                COALESCE(NULLIF(TRIM(li.nume), ''), 'Necunoscut') AS label,
                COUNT(*) AS total_curse
            FROM curse_dispecer c
            LEFT JOIN configurare_locuri_incarcare li ON li.id = c.loc_incarcare_id
            " . $where['where'] . "
            AND c.loc_incarcare_id IS NOT NULL
            AND c.loc_incarcare_id > 0
            GROUP BY c.loc_incarcare_id, li.nume
            ORDER BY label ASC, id ASC
        ";
        $stmt = $this->db->prepare($sql);
        $this->bindParams($stmt, $where['params']);
        $stmt->execute();

        return array_map(static fn (array $row): array => [
            'id' => (int) ($row['id'] ?? 0),
            'label' => (string) ($row['label'] ?? 'Necunoscut'),
            'total_curse' => (int) ($row['total_curse'] ?? 0),
        ], $stmt->fetchAll() ?: []);
    }

    private function unloadingZoneOptions(array $filters): array
    {
        $lookupFilters = array_merge($filters, ['zona_distributie_id' => 0, 'ruta' => '']);
        $where = $this->buildTripWhere($lookupFilters, $this->typesForMode((string) $filters['tip_activitate']), 'zone_lookup');
        $sql = "
            SELECT
                c.zona_distributie_id AS id,
                COALESCE(NULLIF(TRIM(zd.nume), ''), 'Necunoscut') AS label,
                COUNT(*) AS total_curse
            FROM curse_dispecer c
            LEFT JOIN configurare_zone_distributie zd ON zd.id = c.zona_distributie_id
            " . $where['where'] . "
            AND c.zona_distributie_id IS NOT NULL
            AND c.zona_distributie_id > 0
            GROUP BY c.zona_distributie_id, zd.nume
            ORDER BY label ASC, id ASC
        ";
        $stmt = $this->db->prepare($sql);
        $this->bindParams($stmt, $where['params']);
        $stmt->execute();

        return array_map(static fn (array $row): array => [
            'id' => (int) ($row['id'] ?? 0),
            'label' => (string) ($row['label'] ?? 'Necunoscut'),
            'total_curse' => (int) ($row['total_curse'] ?? 0),
        ], $stmt->fetchAll() ?: []);
    }

    private function vehicleOptions(array $filters): array
    {
        $lookupFilters = array_merge($filters, ['vehicle_id' => 0]);
        $where = $this->buildTripWhere($lookupFilters, $this->typesForMode((string) $filters['tip_activitate']), 'vehicle');
        $sql = "
            SELECT DISTINCT v.id, v.nr_inmatriculare, v.capacitate_transport
            FROM curse_dispecer c
            INNER JOIN vehicule v ON v.id = c.vehicle_id
            " . $where['where'] . "
            ORDER BY v.nr_inmatriculare ASC
        ";
        $stmt = $this->db->prepare($sql);
        $this->bindParams($stmt, $where['params']);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    private function vehicleSortOptions(): array
    {
        return [
            'capacity_asc' => 'Capacitate crescător',
            'capacity_desc' => 'Capacitate descrescător',
            'plate_asc' => 'Nr. înmatriculare A-Z',
            'plate_desc' => 'Nr. înmatriculare Z-A',
        ];
    }

    /*
     * Panourile specifice unui tip de transport apar doar cand tipul respectiv
     * este selectat in filtru. In situatia generala ($mode === '') raman doar
     * panourile transversale: sumarul pe tipuri, vehiculele si refacturarile -
     * altfel pagina "generala" ar afisa detalii de distributie sau de primar
     * fara ca utilizatorul sa fi cerut acel tip.
     */
    private function buildVisibility(array $filters, array $core): array
    {
        $mode = (string) $filters['tip_activitate'];

        return [
            'activity_summary' => $mode === '',
            'primary_routes' => in_array($mode, ['primar', 'primar_distributie'], true),
            'distribution' => in_array($mode, ['distributie', 'primar_distributie'], true),
            'distribution_matrix' => in_array($mode, ['distributie', 'primar_distributie'], true),
            'vehicle_matrix' => $mode === '',
            'vehicle_detail' => true,
            'refacturari' => true,
        ];
    }

    private function typesForMode(string $mode): array
    {
        if ($mode === '') {
            return array_keys(self::TRANSPORT_TYPES);
        }

        return isset(self::TRANSPORT_TYPES[$mode]) ? [$mode] : [];
    }

    private function distributionTypesForMode(string $mode): array
    {
        if ($mode === '') {
            return self::DISTRIBUTION_TYPES;
        }
        if ($mode === 'distributie') {
            return ['distributie'];
        }
        if ($mode === 'primar_distributie') {
            return ['primar_distributie'];
        }

        return [];
    }

    private function classifyTariffs(array $tariffs, array &$warnings): array
    {
        $tariffs = array_values(array_unique(array_map(static fn ($value): float => round((float) $value, 4), $tariffs)));
        sort($tariffs, SORT_NUMERIC);
        $count = count($tariffs);
        $buckets = [];
        foreach ($tariffs as $index => $tariff) {
            $key = $this->rateKey($tariff);
            if ($count === 1) {
                $label = 'Preț unic';
            } elseif ($count === 2 && $index === 0) {
                $label = 'Preț mic';
            } elseif ($count === 2 && $index === 1) {
                $label = 'Preț mare';
            } elseif ($count > 2 && $index === 0) {
                $label = 'Preț mic';
            } elseif ($count > 2 && $index === $count - 1) {
                $label = 'Preț mare';
            } else {
                $label = 'Tarif ' . $this->formatNumber($tariff, 2) . ' RON/t';
            }
            $buckets[$key] = [
                'key' => $key,
                'label' => $label,
                'tariff' => $tariff,
                'tone' => 0.0,
                'value' => 0.0,
                'percent' => 0.0,
                'color' => $index === 0 ? '#2f7df4' : ($index === $count - 1 ? '#fb923c' : self::DONUT_COLORS[$index % count(self::DONUT_COLORS)]),
            ];
        }
        if ($count > 2) {
            $warnings[] = 'Există mai mult de două tarife istorice în filtrul curent; toate tarifele sunt afișate separat.';
        }

        return $buckets;
    }

    private function matrixTotals(array $rows, array $buckets, float $totalTone): array
    {
        $totals = ['buckets' => [], 'total_tone' => round($totalTone, 4), 'percent' => $totalTone > 0 ? 100.0 : 0.0];
        foreach ($buckets as $key => $_bucket) {
            $totals['buckets'][$key] = round(array_sum(array_map(static fn (array $row): float => (float) ($row['buckets'][$key] ?? 0), $rows)), 4);
        }

        return $totals;
    }

    private function emptyVehicleBucket(string $key, ?array $row): array
    {
        $capacity = $row !== null ? $this->nullableFloat($row['vehicle_capacitate_transport'] ?? null) : null;

        return [
            'key' => $key,
            'vehicle_id' => $row !== null ? (int) ($row['vehicle_id'] ?? 0) : 0,
            'nr_inmatriculare' => $row !== null ? (string) ($row['nr_inmatriculare'] ?? 'Vehicul nealocat') : 'Vehicul nealocat',
            'capacity' => $capacity,
            'trips' => 0,
            'primar' => ['_trips' => 0, 'km' => 0.0, 'value' => 0.0],
            'primar_tona' => ['_trips' => 0, 'tone' => 0.0, 'value' => 0.0],
            'distributie' => ['_trips' => 0, 'tone' => 0.0, 'value' => 0.0],
            'primar_distributie' => ['_trips' => 0, 'km' => 0.0, 'tone' => 0.0, 'value' => 0.0],
            'compresor' => ['_trips' => 0, 'activity' => 0.0, 'tone' => 0.0, 'unit' => 'activ.', 'value' => 0.0],
            'total_value' => 0.0,
            'route_summary' => '-',
            'route_count' => 0,
            'routes' => [],
            'detail_rows' => [],
            'is_unassigned' => $row === null,
        ];
    }

    private function roundVehicleBucket(array &$vehicle): void
    {
        foreach (['primar', 'primar_tona', 'distributie', 'primar_distributie', 'compresor'] as $type) {
            foreach ($vehicle[$type] as $key => $value) {
                if (is_numeric($value)) {
                    $vehicle[$type][$key] = round((float) $value, $key === 'value' ? 2 : 4);
                }
            }
        }
        $vehicle['total_value'] = round((float) $vehicle['total_value'], 2);
    }

    private function sortVehicleRows(array &$rows, string $sort): void
    {
        usort($rows, static function (array $a, array $b) use ($sort): int {
            $plateA = (string) ($a['nr_inmatriculare'] ?? '');
            $plateB = (string) ($b['nr_inmatriculare'] ?? '');
            $capA = $a['capacity'] ?? null;
            $capB = $b['capacity'] ?? null;
            $hasA = $capA !== null;
            $hasB = $capB !== null;

            if ($sort === 'plate_asc') {
                return strcmp($plateA, $plateB);
            }
            if ($sort === 'plate_desc') {
                return strcmp($plateB, $plateA);
            }

            if ($hasA !== $hasB) {
                return $hasA ? -1 : 1;
            }
            if ($hasA && $capA !== $capB) {
                return $sort === 'capacity_desc' ? ((float) $capB <=> (float) $capA) : ((float) $capA <=> (float) $capB);
            }

            return strcmp($plateA, $plateB);
        });
    }

    private function vehicleMatrixTotals(array $rows): array
    {
        $totals = $this->emptyVehicleBucket('total', null);
        $totals['nr_inmatriculare'] = 'TOTAL';
        $totals['capacity'] = null;
        foreach ($rows as $row) {
            $totals['trips'] += (int) $row['trips'];
            $totals['total_value'] += (float) $row['total_value'];
            foreach (['primar', 'primar_tona', 'distributie', 'primar_distributie', 'compresor'] as $type) {
                foreach ($totals[$type] as $key => $value) {
                    if (is_numeric($value)) {
                        $totals[$type][$key] += (float) ($row[$type][$key] ?? 0);
                    }
                }
            }
        }
        $this->roundVehicleBucket($totals);

        return $totals;
    }

    private function vehicleDetailRows(array $vehicleRows, string $mode): array
    {
        $columns = [
            ['key' => 'toggle', 'label' => '', 'align' => 'left'],
            ['key' => 'vehicle', 'label' => 'Vehicul', 'align' => 'left'],
            ['key' => 'capacity', 'label' => 'Capacitate', 'align' => 'right'],
            ['key' => 'trips', 'label' => 'Nr. curse', 'align' => 'right'],
            ['key' => 'route_summary', 'label' => 'Rută', 'align' => 'left'],
        ];
        if ($mode === 'primar') {
            $columns[] = ['key' => 'km', 'label' => 'Km', 'align' => 'right'];
            $columns[] = ['key' => 'value', 'label' => 'Valoare (RON)', 'align' => 'right'];
        } elseif ($mode === 'primar_tona') {
            $columns[] = ['key' => 'tone', 'label' => 'Tone', 'align' => 'right'];
            $columns[] = ['key' => 'value', 'label' => 'Valoare (RON)', 'align' => 'right'];
        } elseif ($mode === 'primar_distributie') {
            $columns[] = ['key' => 'km', 'label' => 'Km', 'align' => 'right'];
            $columns[] = ['key' => 'tone', 'label' => 'Tone', 'align' => 'right'];
            $columns[] = ['key' => 'value', 'label' => 'Valoare (RON)', 'align' => 'right'];
        } elseif ($mode === 'compresor') {
            $columns[] = ['key' => 'activity', 'label' => 'Tone/Activ.', 'align' => 'right'];
            $columns[] = ['key' => 'value', 'label' => 'Valoare (RON)', 'align' => 'right'];
        } elseif ($mode === 'distributie') {
            $columns[] = ['key' => 'tone', 'label' => 'Tone', 'align' => 'right'];
            $columns[] = ['key' => 'value', 'label' => 'Valoare (RON)', 'align' => 'right'];
        } else {
            $columns[] = ['key' => 'km', 'label' => 'Km', 'align' => 'right'];
            $columns[] = ['key' => 'tone', 'label' => 'Tone', 'align' => 'right'];
            $columns[] = ['key' => 'value', 'label' => 'Valoare (RON)', 'align' => 'right'];
        }

        $rows = [];
        foreach ($vehicleRows as $vehicle) {
            $metric = $this->vehicleMetricForMode($vehicle, $mode);
            if ((int) $metric['trips'] <= 0) {
                continue;
            }
            $rows[] = array_merge([
                'key' => (string) ($vehicle['key'] ?? ''),
                'vehicle_id' => (int) ($vehicle['vehicle_id'] ?? 0),
                'vehicle' => (string) $vehicle['nr_inmatriculare'],
                'capacity' => $vehicle['capacity'],
                'route_summary' => (string) ($vehicle['route_summary'] ?? '-'),
                'detail_rows' => (array) ($vehicle['detail_rows'] ?? []),
                'detail_columns' => $this->vehicleTripDetailColumns($mode),
            ], $metric);
        }

        $totals = ['vehicle' => 'TOTAL', 'capacity' => null, 'trips' => array_sum(array_column($rows, 'trips'))];
        foreach (['km', 'tone', 'activity', 'value'] as $key) {
            $totals[$key] = round(array_sum(array_map(static fn (array $row): float => (float) ($row[$key] ?? 0), $rows)), $key === 'value' ? 2 : 4);
        }

        return [
            'mode' => $mode,
            'label' => self::ACTIVITY_OPTIONS[$mode] ?? 'Distribuție',
            'columns' => $columns,
            'rows' => $rows,
            'totals' => $totals,
        ];
    }

    private function vehicleMetricForMode(array $vehicle, string $mode): array
    {
        if ($mode === 'primar') {
            return ['trips' => (float) (($vehicle['primar']['km'] ?? 0) > 0 || ($vehicle['primar']['value'] ?? 0) > 0 ? $this->estimateVehicleTrips($vehicle, 'primar') : 0), 'km' => (float) $vehicle['primar']['km'], 'value' => (float) $vehicle['primar']['value']];
        }
        if ($mode === 'primar_tona') {
            return ['trips' => (float) (($vehicle['primar_tona']['tone'] ?? 0) > 0 || ($vehicle['primar_tona']['value'] ?? 0) > 0 ? $this->estimateVehicleTrips($vehicle, 'primar_tona') : 0), 'tone' => (float) $vehicle['primar_tona']['tone'], 'value' => (float) $vehicle['primar_tona']['value']];
        }
        if ($mode === 'primar_distributie') {
            return ['trips' => (float) (($vehicle['primar_distributie']['km'] ?? 0) > 0 || ($vehicle['primar_distributie']['tone'] ?? 0) > 0 ? $this->estimateVehicleTrips($vehicle, 'primar_distributie') : 0), 'km' => (float) $vehicle['primar_distributie']['km'], 'tone' => (float) $vehicle['primar_distributie']['tone'], 'value' => (float) $vehicle['primar_distributie']['value']];
        }
        if ($mode === 'compresor') {
            return ['trips' => (float) (($vehicle['compresor']['activity'] ?? 0) > 0 || ($vehicle['compresor']['value'] ?? 0) > 0 ? $this->estimateVehicleTrips($vehicle, 'compresor') : 0), 'activity' => (float) $vehicle['compresor']['activity'], 'value' => (float) $vehicle['compresor']['value']];
        }
        if ($mode === '') {
            return [
                'trips' => (float) ($vehicle['trips'] ?? 0),
                'km' => (float) (($vehicle['primar']['km'] ?? 0) + ($vehicle['primar_distributie']['km'] ?? 0)),
                'tone' => (float) (($vehicle['primar_tona']['tone'] ?? 0) + ($vehicle['distributie']['tone'] ?? 0) + ($vehicle['primar_distributie']['tone'] ?? 0) + ($vehicle['compresor']['tone'] ?? 0)),
                'value' => (float) ($vehicle['total_value'] ?? 0),
            ];
        }

        return ['trips' => (float) (($vehicle['distributie']['tone'] ?? 0) > 0 || ($vehicle['distributie']['value'] ?? 0) > 0 ? $this->estimateVehicleTrips($vehicle, 'distributie') : 0), 'tone' => (float) $vehicle['distributie']['tone'], 'value' => (float) $vehicle['distributie']['value']];
    }

    private function vehicleTariffBuckets(array $rows, string $mode): array
    {
        $types = $this->distributionTypesForMode($mode);
        $tariffs = [];
        $hasUnknown = false;
        foreach ($rows as $row) {
            if (!in_array((string) ($row['tip_transport'] ?? ''), $types, true)) {
                continue;
            }
            if ($this->normalizedLoadedTons($row) <= 0) {
                continue;
            }
            $tariff = round((float) ($row['pret_tarifare'] ?? 0), 4);
            if ($tariff > 0) {
                $tariffs[] = $tariff;
            } else {
                $hasUnknown = true;
            }
        }

        $warnings = [];
        $buckets = $this->classifyTariffs($tariffs, $warnings);
        if ($hasUnknown) {
            $buckets['unknown'] = [
                'key' => 'unknown',
                'label' => 'Tarif neidentificat',
                'tariff' => null,
                'tone' => 0.0,
                'value' => 0.0,
                'percent' => 0.0,
                'color' => '#64748b',
            ];
        }

        return $buckets;
    }

    private function vehicleTripDetailRow(array $row, string $mode, array $tariffBuckets): array
    {
        $tripId = (int) ($row['id'] ?? 0);
        $type = (string) ($row['tip_transport'] ?? '');
        $date = (string) (($row['data_inceput'] ?? '') ?: ($row['data_cursa'] ?? ''));
        $km = $this->rowKm($row);
        $tone = $this->normalizedLoadedTons($row);
        $value = $this->rowValue($row);
        $tariff = round((float) ($row['pret_tarifare'] ?? 0), 4);
        $tariffKey = $tariff > 0 ? $this->rateKey($tariff) : 'unknown';
        $isDistributionTrip = in_array($type, self::DISTRIBUTION_TYPES, true);
        $tariffClass = '-';
        if ($isDistributionTrip) {
            $tariffClass = (string) ($tariffBuckets[$tariffKey]['label'] ?? 'Tarif neidentificat');
        }
        $compressorActivity = $this->compressorActivityValue($row);
        $compressorUnit = $this->compressorActivityUnit($row);

        return [
            'trip_id' => $tripId,
            'sort_date' => $date,
            'date_label' => $this->formatDateLabel($date),
            'race_no' => $this->formatRaceNumber($tripId, $date),
            'type' => $type,
            'type_label' => self::TRANSPORT_TYPES[$type]['label'] ?? ($type !== '' ? $type : '-'),
            'vehicle_label' => trim((string) ($row['nr_inmatriculare'] ?? '')) !== '' ? (string) $row['nr_inmatriculare'] : 'Vehicul nealocat',
            'loc_label' => $this->loadingLabel($row),
            'zone_label' => $this->unloadingLabel($row),
            'route_key' => $this->routeKeyFromRow($row),
            'route_label' => $this->routeAuditLabel($row),
            'cargo_label' => $this->cargoDisplayLabel((string) ($row['tip_marfa'] ?? '')),
            'km' => in_array($type, ['primar', 'primar_distributie'], true) ? $km : 0.0,
            'tone' => in_array($type, ['primar_tona', 'distributie', 'primar_distributie'], true) || ($type === 'compresor' && $tone > 0) ? $tone : 0.0,
            'price_km' => $this->tripPricePerKm($row, $km, $value),
            'tariff' => $tariff > 0 ? $tariff : null,
            'tariff_class' => $tariffClass,
            'compressor_activity' => $compressorActivity,
            'compressor_unit' => $compressorUnit,
            'compressor_activity_label' => $compressorActivity > 0 ? $this->formatNumber($compressorActivity, abs($compressorActivity - round($compressorActivity)) < 0.0001 ? 0 : 2) . ' ' . $compressorUnit : '-',
            'value' => $value,
        ];
    }

    private function tripPricePerKm(array $row, float $km, float $value): ?float
    {
        $saved = max(0.0, (float) ($row['cost_km_primar'] ?? 0));
        if ($saved > 0) {
            return round($saved, 4);
        }
        if ($km > 0 && $value > 0) {
            return round($value / $km, 4);
        }

        return null;
    }

    private function routeKeyFromRow(array $row): string
    {
        return ((int) ($row['loc_incarcare_id'] ?? 0)) . ':' . ((int) ($row['zona_distributie_id'] ?? 0));
    }

    private function loadingLabel(array $row): string
    {
        $label = trim((string) ($row['loc_incarcare_nume'] ?? ''));
        return $label !== '' ? $label : 'Necunoscut';
    }

    private function unloadingLabel(array $row): string
    {
        $label = trim((string) ($row['zona_distributie_nume'] ?? ''));
        return $label !== '' ? $label : 'Necompletat';
    }

    /*
     * Traseul este acelasi camp pentru toate tipurile de transport (loc_incarcare_id
     * + zona_distributie_id, exact perechea pe care o folosesc si configurare_rute_primar
     * si configurare_rute_distributie). Cand zona lipseste pe cursa, nu inventam o
     * destinatie "Necunoscut" - spunem explicit ca nu a fost completata.
     */
    private function routeAuditLabel(array $row): string
    {
        $destination = trim((string) ($row['zona_distributie_nume'] ?? ''));
        if ($destination === '') {
            return $this->loadingLabel($row) . ' → destinație necompletată';
        }

        return $this->loadingLabel($row) . ' → ' . $destination;
    }

    private function compactRouteSummary(array $routes): string
    {
        if ($routes === []) {
            return '-';
        }
        uasort($routes, static fn (array $a, array $b): int => strcmp((string) $a['label'], (string) $b['label']) ?: strcmp((string) $a['key'], (string) $b['key']));
        $routes = array_values($routes);
        $first = (string) ($routes[0]['label'] ?? '-');
        $extra = count($routes) - 1;

        return $extra > 0 ? $first . ' +' . $extra . ' ' . ($extra === 1 ? 'rută' : 'rute') : $first;
    }

    private function vehicleTripDetailColumns(string $mode): array
    {
        if ($mode === 'primar') {
            return [
                ['key' => 'date_label', 'label' => 'Data', 'align' => 'left'],
                ['key' => 'race_no', 'label' => 'Nr. cursă', 'align' => 'left'],
                ['key' => 'loc_label', 'label' => 'Loc încărcare', 'align' => 'left'],
                ['key' => 'zone_label', 'label' => 'Zonă descărcare', 'align' => 'left'],
                ['key' => 'route_label', 'label' => 'Rută', 'align' => 'left'],
                ['key' => 'km', 'label' => 'Km', 'align' => 'right', 'format' => 'km'],
                ['key' => 'price_km', 'label' => 'Preț / km', 'align' => 'right', 'format' => 'rate_km'],
                ['key' => 'value', 'label' => 'Valoare (RON)', 'align' => 'right', 'format' => 'money'],
            ];
        }
        if ($mode === 'primar_tona') {
            return [
                ['key' => 'date_label', 'label' => 'Data', 'align' => 'left'],
                ['key' => 'race_no', 'label' => 'Nr. cursă', 'align' => 'left'],
                ['key' => 'loc_label', 'label' => 'Loc încărcare', 'align' => 'left'],
                ['key' => 'zone_label', 'label' => 'Zonă descărcare', 'align' => 'left'],
                ['key' => 'route_label', 'label' => 'Rută', 'align' => 'left'],
                ['key' => 'cargo_label', 'label' => 'Tip marfă', 'align' => 'left'],
                ['key' => 'tone', 'label' => 'Tone', 'align' => 'right', 'format' => 'tone'],
                ['key' => 'tariff', 'label' => 'Preț / tonă', 'align' => 'right', 'format' => 'tariff'],
                ['key' => 'value', 'label' => 'Valoare (RON)', 'align' => 'right', 'format' => 'money'],
            ];
        }
        if ($mode === 'distributie') {
            return [
                ['key' => 'date_label', 'label' => 'Data', 'align' => 'left'],
                ['key' => 'race_no', 'label' => 'Nr. cursă', 'align' => 'left'],
                ['key' => 'loc_label', 'label' => 'Loc încărcare', 'align' => 'left'],
                ['key' => 'zone_label', 'label' => 'Zonă descărcare', 'align' => 'left'],
                ['key' => 'route_label', 'label' => 'Rută / Zonă', 'align' => 'left'],
                ['key' => 'cargo_label', 'label' => 'Tip marfă', 'align' => 'left'],
                ['key' => 'tone', 'label' => 'Tone', 'align' => 'right', 'format' => 'tone'],
                ['key' => 'tariff', 'label' => 'Tarif', 'align' => 'right', 'format' => 'tariff'],
                ['key' => 'tariff_class', 'label' => 'Clasificare', 'align' => 'left'],
                ['key' => 'value', 'label' => 'Valoare (RON)', 'align' => 'right', 'format' => 'money'],
            ];
        }
        if ($mode === 'primar_distributie') {
            return [
                ['key' => 'date_label', 'label' => 'Data', 'align' => 'left'],
                ['key' => 'race_no', 'label' => 'Nr. cursă', 'align' => 'left'],
                ['key' => 'loc_label', 'label' => 'Loc încărcare', 'align' => 'left'],
                ['key' => 'zone_label', 'label' => 'Zonă descărcare', 'align' => 'left'],
                ['key' => 'route_label', 'label' => 'Rută', 'align' => 'left'],
                ['key' => 'cargo_label', 'label' => 'Tip marfă', 'align' => 'left'],
                ['key' => 'km', 'label' => 'Km Primar', 'align' => 'right', 'format' => 'km'],
                ['key' => 'tone', 'label' => 'Tone Distribuție', 'align' => 'right', 'format' => 'tone'],
                ['key' => 'tariff', 'label' => 'Tarif', 'align' => 'right', 'format' => 'tariff'],
                ['key' => 'value', 'label' => 'Valoare (RON)', 'align' => 'right', 'format' => 'money'],
            ];
        }
        if ($mode === 'compresor') {
            return [
                ['key' => 'date_label', 'label' => 'Data', 'align' => 'left'],
                ['key' => 'race_no', 'label' => 'Nr. cursă', 'align' => 'left'],
                ['key' => 'vehicle_label', 'label' => 'Vehicul', 'align' => 'left'],
                ['key' => 'loc_label', 'label' => 'Loc încărcare', 'align' => 'left'],
                ['key' => 'zone_label', 'label' => 'Zonă descărcare', 'align' => 'left'],
                ['key' => 'route_label', 'label' => 'Rută', 'align' => 'left'],
                ['key' => 'compressor_activity_label', 'label' => 'Activitate compresor', 'align' => 'right'],
                ['key' => 'tone', 'label' => 'Tone', 'align' => 'right', 'format' => 'tone'],
                ['key' => 'value', 'label' => 'Valoare (RON)', 'align' => 'right', 'format' => 'money'],
            ];
        }

        return [
            ['key' => 'date_label', 'label' => 'Data', 'align' => 'left'],
            ['key' => 'race_no', 'label' => 'Nr. cursă', 'align' => 'left'],
            ['key' => 'type_label', 'label' => 'Tip activitate', 'align' => 'left'],
            ['key' => 'loc_label', 'label' => 'Loc încărcare', 'align' => 'left'],
            ['key' => 'zone_label', 'label' => 'Zonă descărcare', 'align' => 'left'],
            ['key' => 'route_label', 'label' => 'Rută', 'align' => 'left'],
            ['key' => 'cargo_label', 'label' => 'Tip marfă', 'align' => 'left'],
            ['key' => 'km', 'label' => 'Km', 'align' => 'right', 'format' => 'km'],
            ['key' => 'tone', 'label' => 'Tone', 'align' => 'right', 'format' => 'tone'],
            ['key' => 'value', 'label' => 'Valoare (RON)', 'align' => 'right', 'format' => 'money'],
        ];
    }

    private function estimateVehicleTrips(array $vehicle, string $type): int
    {
        return max(1, (int) ($vehicle[$type]['_trips'] ?? 0));
    }

    private function validateVehicleReconciliation(array $vehicleRows, array $tripRows): array
    {
        $warnings = [];
        $expectedPrimaryKm = 0.0;
        $expectedDistributionTone = 0.0;
        $expectedPrimaryTone = 0.0;
        $expectedPdKm = 0.0;
        $expectedPdTone = 0.0;
        foreach ($tripRows as $row) {
            $type = (string) ($row['tip_transport'] ?? '');
            if ($type === 'primar') {
                $expectedPrimaryKm += $this->rowKm($row);
            } elseif ($type === 'primar_tona') {
                $expectedPrimaryTone += $this->normalizedLoadedTons($row);
            } elseif ($type === 'distributie') {
                $expectedDistributionTone += $this->normalizedLoadedTons($row);
            } elseif ($type === 'primar_distributie') {
                $expectedPdKm += $this->rowKm($row);
                $expectedPdTone += $this->normalizedLoadedTons($row);
            }
        }

        $actualPrimaryKm = array_sum(array_map(static fn (array $v): float => (float) ($v['primar']['km'] ?? 0), $vehicleRows));
        $actualDistributionTone = array_sum(array_map(static fn (array $v): float => (float) ($v['distributie']['tone'] ?? 0), $vehicleRows));
        $actualPrimaryTone = array_sum(array_map(static fn (array $v): float => (float) ($v['primar_tona']['tone'] ?? 0), $vehicleRows));
        $actualPdKm = array_sum(array_map(static fn (array $v): float => (float) ($v['primar_distributie']['km'] ?? 0), $vehicleRows));
        $actualPdTone = array_sum(array_map(static fn (array $v): float => (float) ($v['primar_distributie']['tone'] ?? 0), $vehicleRows));

        if (!$this->nearlyEqual($expectedPrimaryKm, $actualPrimaryKm)) {
            $warnings[] = 'Reconcilierea km Primar pe vehicule nu este exactă.';
        }
        if (!$this->nearlyEqual($expectedDistributionTone, $actualDistributionTone)) {
            $warnings[] = 'Reconcilierea tonajului Distribuție pe vehicule nu este exactă.';
        }
        if (!$this->nearlyEqual($expectedPrimaryTone, $actualPrimaryTone)) {
            $warnings[] = 'Reconcilierea tonajului Primar tone pe vehicule nu este exactă.';
        }
        if (!$this->nearlyEqual($expectedPdKm, $actualPdKm) || !$this->nearlyEqual($expectedPdTone, $actualPdTone)) {
            $warnings[] = 'Reconcilierea P+D pe vehicule nu este exactă.';
        }

        return $warnings;
    }

    private function accumulateRefacturareGroups(array &$groups, array $expense, float $amount, array &$warnings): void
    {
        $details = json_decode((string) ($expense['refacturare_detalii'] ?? ''), true);
        $hasStructuredDetails = is_array($details) && $details !== [];
        $componentTotal = 0.0;

        if ($hasStructuredDetails) {
            foreach (self::ROAD_TAX_LABELS as $detailKey => $typeLabel) {
                $detail = $details[$detailKey] ?? null;
                if (!is_array($detail)) {
                    continue;
                }
                $qty = max(0.0, (float) ($detail['bucati'] ?? 0));
                $lineTotal = round(max(0.0, (float) ($detail['total'] ?? 0)), 2);
                if ($qty <= 0 && $lineTotal <= 0) {
                    continue;
                }
                $componentTotal += $lineTotal;
                $location = $this->normalizeObservationLabel((string) ($expense['refacturare_observatii'] ?? ''));
                $label = $this->refacturareGroupLabel($detailKey, $typeLabel, $location);
                $groupKey = $detailKey . '|' . $this->normalizeGroupKey($location);
                $groups[$groupKey] ??= [
                    'key' => $groupKey,
                    'label' => $label,
                    'type' => $detailKey,
                    'quantity' => 0.0,
                    'amount' => 0.0,
                ];
                $groups[$groupKey]['quantity'] += $qty;
                $groups[$groupKey]['amount'] += $lineTotal;
            }

            $difference = round($amount - $componentTotal, 2);
            if (abs($difference) > 0.01) {
                $location = $this->normalizeObservationLabel((string) ($expense['refacturare_observatii'] ?? ''));
                $groupKey = 'difference|' . (int) ($expense['expense_id'] ?? 0);
                $groups[$groupKey] = [
                    'key' => $groupKey,
                    'label' => trim('Diferență nealocată ' . $location),
                    'type' => 'difference',
                    'quantity' => 0.0,
                    'amount' => $difference,
                ];
                $warnings[] = 'Refacturarea #' . (int) ($expense['expense_id'] ?? 0) . ' are diferență între suma părinte și detaliile JSON.';
            }

            return;
        }

        $type = (string) (($expense['refacturare_tip_cheltuiala'] ?? '') ?: ($expense['tip_cheltuiala'] ?? 'alte'));
        $typeLabel = $this->expenseTypeLabel($type);
        $location = $this->normalizeObservationLabel((string) ($expense['refacturare_observatii'] ?? ''));
        $label = trim($typeLabel . ($location !== '' ? ' ' . $location : ''));
        $groupKey = $type . '|' . $this->normalizeGroupKey($location);
        $groups[$groupKey] ??= [
            'key' => $groupKey,
            'label' => $label !== '' ? $label : $typeLabel,
            'type' => $type,
            'quantity' => 0.0,
            'amount' => 0.0,
        ];
        $groups[$groupKey]['quantity'] += 1;
        $groups[$groupKey]['amount'] += $amount;
    }

    private function accumulateRefacturareTypeGroups(array &$groups, array $expense, float $amount): void
    {
        $type = (string) ($expense['tip_transport'] ?? '');
        $label = self::TRANSPORT_TYPES[$type]['label'] ?? ($type !== '' ? $type : 'Nespecificat');
        $groups[$type] ??= ['key' => $type, 'label' => $label, 'records' => 0, 'amount' => 0.0];
        $groups[$type]['records']++;
        $groups[$type]['amount'] += $amount;
    }

    private function accumulateRefacturareTripRows(array &$tripRows, array $expense, float $amount): void
    {
        $tripId = (int) ($expense['cursa_id'] ?? 0);
        if ($tripId <= 0) {
            return;
        }
        if (!isset($tripRows[$tripId])) {
            $date = (string) (($expense['refacturare_data'] ?? '') ?: ($expense['data_cheltuiala'] ?? '') ?: ($expense['data_inceput'] ?? '') ?: ($expense['data_cursa'] ?? ''));
            $tripRows[$tripId] = [
                'cursa_id' => $tripId,
                'race_no' => $this->formatRaceNumber($tripId, (string) ($expense['data_inceput'] ?? $date)),
                'date' => $date,
                'date_label' => $this->formatDateLabel($date),
                'sort_date' => $date,
                'tip_transport' => (string) ($expense['tip_transport'] ?? ''),
                'tip_transport_label' => self::TRANSPORT_TYPES[(string) ($expense['tip_transport'] ?? '')]['label'] ?? '-',
                'route_label' => $this->routeLabel($expense),
                'vehicle_label' => trim((string) ($expense['nr_inmatriculare'] ?? '')) !== '' ? (string) $expense['nr_inmatriculare'] : 'Vehicul nealocat',
                'tip_marfa_label' => $this->cargoDisplayLabel((string) ($expense['tip_marfa'] ?? '')),
                'tone' => $this->normalizedLoadedTons($expense),
                'km' => $this->rowKm($expense),
                'trip_value' => $this->rowValue($expense),
                'refacturare_amount' => 0.0,
                'refacturare_types' => [],
                'observations' => [],
            ];
        }
        $tripRows[$tripId]['refacturare_amount'] = round((float) $tripRows[$tripId]['refacturare_amount'] + $amount, 2);
        /*
         * Randul este un sumar pe cursa, iar o cursa poate avea mai multe
         * refacturari de tipuri diferite: pastram tipurile distincte.
         */
        $expenseType = (string) (($expense['refacturare_tip_cheltuiala'] ?? '') ?: ($expense['tip_cheltuiala'] ?? ''));
        if ($expenseType !== '') {
            $tripRows[$tripId]['refacturare_types'][$expenseType] = $this->expenseTypeLabel($expenseType);
        }
        $obs = trim((string) ($expense['refacturare_observatii'] ?? ''));
        if ($obs !== '') {
            $tripRows[$tripId]['observations'][$this->normalizeGroupKey($obs)] = $obs;
        }
    }

    private function primaryRouteValue(array $row, array &$warnings): float
    {
        $type = (string) ($row['tip_transport'] ?? '');
        $km = $this->rowKm($row);
        if ($type === 'primar_distributie') {
            $cost = max(0.0, (float) ($row['cost_km_primar'] ?? 0));
            if ($cost > 0 && $km > 0) {
                return round($cost * $km, 2);
            }
            $warnings[] = 'Există P+D fără cost_km_primar istoric; valoarea rutei folosește total_facturare salvat.';
        }

        return $this->rowValue($row);
    }

    private function primaryRouteRate(array $row, float $value, float $km): ?float
    {
        $saved = max(0.0, (float) ($row['cost_km_primar'] ?? 0));
        if ($saved > 0) {
            return round($saved, 4);
        }
        if ($km > 0 && $value > 0) {
            return round($value / $km, 4);
        }

        return null;
    }

    private function normalizedLoadedTons(array $row): float
    {
        $qty = (float) ($row['cantitate_incarcata'] ?? 0);
        $capacity = (float) (($row['cursa_capacitate_transport'] ?? null) ?: ($row['capacitate_transport'] ?? 0));
        if ($qty <= 0) {
            return 0.0;
        }
        if ($capacity > 0 && $qty > ($capacity * 3)) {
            return round($qty / 1000, 4);
        }
        if ($qty >= 1000) {
            return round($qty / 1000, 4);
        }

        return round($qty, 4);
    }

    private function rowKm(array $row): float
    {
        return max(0.0, (float) (($row['km_cursa'] ?? null) ?: ($row['km_totali'] ?? 0)));
    }

    private function rowValue(array $row): float
    {
        return round(max(0.0, (float) ($row['total_facturare'] ?? 0)), 2);
    }

    private function compressorActivityValue(array $row): float
    {
        $hours = max(0.0, (float) ($row['ore_functionare'] ?? 0));
        if ($hours > 0) {
            return $hours;
        }
        $tons = max(0.0, (float) ($row['tona_livrata'] ?? 0))
            + max(0.0, (float) ($row['tona_aspirata_lichida'] ?? 0))
            + max(0.0, (float) ($row['tona_aspirata_gazoasa'] ?? 0));
        if ($tons > 0) {
            return $tons;
        }

        return $this->normalizedLoadedTons($row);
    }

    private function compressorActivityUnit(array $row): string
    {
        return max(0.0, (float) ($row['ore_functionare'] ?? 0)) > 0 ? 'ore' : 'tone/activ.';
    }

    private function buildDonutChart(array $rows, string $valueKey): array
    {
        $total = array_sum(array_map(static fn (array $row): float => max(0.0, (float) ($row[$valueKey] ?? 0)), $rows));
        if ($total <= 0) {
            return ['gradient' => '#e5e7eb', 'segments' => []];
        }
        $cursor = 0.0;
        $parts = [];
        $segments = [];
        foreach (array_values($rows) as $index => $row) {
            $value = max(0.0, (float) ($row[$valueKey] ?? 0));
            if ($value <= 0) {
                continue;
            }
            $degrees = ($value / $total) * 360;
            $start = $cursor;
            $end = $cursor + $degrees;
            $color = (string) ($row['color'] ?? self::DONUT_COLORS[$index % count(self::DONUT_COLORS)]);
            $parts[] = $color . ' ' . round($start, 2) . 'deg ' . round($end, 2) . 'deg';
            $segments[] = ['color' => $color, 'value' => $value, 'start' => $start, 'end' => $end];
            $cursor = $end;
        }

        return ['gradient' => 'conic-gradient(' . implode(', ', $parts) . ')', 'segments' => $segments];
    }

    private function comparisonValue(float $previous, float $current): ?array
    {
        if ($previous <= 0) {
            return null;
        }
        $change = (($current - $previous) / $previous) * 100;

        return [
            'previous' => round($previous, 2),
            'current' => round($current, 2),
            'percent' => round($change, 2),
            'direction' => $change >= 0 ? 'up' : 'down',
        ];
    }

    private function findDefaultMonth(): string
    {
        $sql = "
            SELECT MAX(activity_date) AS max_date
            FROM (
                SELECT COALESCE(data_inceput, data_cursa) AS activity_date
                FROM curse_dispecer
                WHERE deleted_at IS NULL
                UNION ALL
                SELECT COALESCE(e.refacturare_data, e.data_cheltuiala) AS activity_date
                FROM curse_cheltuieli e
                INNER JOIN curse_dispecer c ON c.id = e.cursa_id
                WHERE c.deleted_at IS NULL AND COALESCE(e.refacturare_suma, 0) > 0
            ) x
            WHERE activity_date <= CURDATE()
        ";
        $value = (string) ($this->db->query($sql)->fetchColumn() ?: '');

        return $value !== '' ? substr($value, 0, 7) : date('Y-m');
    }

    private function normalizeMonth(string $value): string
    {
        $value = trim($value);
        return preg_match('/^\d{4}-\d{2}$/', $value) ? $value : '';
    }

    private function monthBounds(string $month): array
    {
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01') ?: new DateTimeImmutable('first day of this month');
        $next = $start->modify('first day of next month');

        return [
            'start' => $start->format('Y-m-d'),
            'end' => $next->modify('-1 day')->format('Y-m-d'),
            'next' => $next->format('Y-m-d'),
        ];
    }

    private function formatMonthLabel(string $month): string
    {
        $names = [
            1 => 'Ianuarie', 2 => 'Februarie', 3 => 'Martie', 4 => 'Aprilie',
            5 => 'Mai', 6 => 'Iunie', 7 => 'Iulie', 8 => 'August',
            9 => 'Septembrie', 10 => 'Octombrie', 11 => 'Noiembrie', 12 => 'Decembrie',
        ];
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01');
        if (!$date) {
            return $month;
        }

        return ($names[(int) $date->format('n')] ?? $date->format('m')) . ' ' . $date->format('Y');
    }

    private function normalizePositiveInt(mixed $value): int
    {
        $value = trim((string) $value);
        return $value !== '' && ctype_digit($value) ? max(0, (int) $value) : 0;
    }

    private function normalizeCargoKey(string $value): string
    {
        $value = $this->normalizeGroupKey($value);
        return mb_substr($value, 0, 80);
    }

    private function normalizeRouteKey(string $value): string
    {
        $value = trim($value);
        return preg_match('/^\d+:\d+$/', $value) ? $value : '';
    }

    private function parseRouteKey(string $value): ?array
    {
        if (!preg_match('/^(\d+):(\d+)$/', $value, $matches)) {
            return null;
        }

        return ['loc' => (int) $matches[1], 'zone' => (int) $matches[2]];
    }

    private function parseCargoKeys(string $value): array
    {
        $parts = preg_split('/[,;|]+/', $value) ?: [];
        $keys = [];
        foreach ($parts as $part) {
            $key = $this->normalizeCargoKey($part);
            if ($key !== '') {
                $keys[$key] = $key;
            }
        }

        return array_values($keys);
    }

    private function cargoLabel(string $key): string
    {
        $known = ['butan' => 'Butan', 'propan' => 'Propan', 'autogaz' => 'Autogaz', 'gpl' => 'GPL', 'mixt' => 'Mixt'];
        return $known[$key] ?? mb_convert_case(str_replace('_', ' ', $key), MB_CASE_TITLE, 'UTF-8');
    }

    private function cargoDisplayLabel(string $value): string
    {
        $keys = $this->parseCargoKeys($value);
        if ($keys === []) {
            return '-';
        }

        return implode(', ', array_map(fn (string $key): string => $this->cargoLabel($key), $keys));
    }

    private function routeLabel(array $row): string
    {
        $loc = trim((string) ($row['loc_incarcare_nume'] ?? ''));
        $zone = trim((string) ($row['zona_distributie_nume'] ?? ''));
        if ($loc !== '' && $zone !== '') {
            return $loc . ' - ' . $zone;
        }
        if ($loc !== '') {
            return $loc;
        }
        if ($zone !== '') {
            return $zone;
        }

        return 'Rută nespecificată';
    }

    private function routeShort(array $row): string
    {
        $loc = trim((string) ($row['loc_incarcare_nume'] ?? ''));
        $zone = trim((string) ($row['zona_distributie_nume'] ?? ''));
        $left = $loc !== '' ? mb_substr($loc, 0, 1, 'UTF-8') : '-';
        $right = $zone !== '' ? mb_substr($zone, 0, 1, 'UTF-8') : '';

        return mb_strtoupper($right !== '' ? $left . '-' . $right : $left, 'UTF-8');
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $float = (float) $value;
        return $float > 0 ? $float : null;
    }

    private function rateKey(float $rate): string
    {
        return 'rate_' . str_replace(['.', '-'], ['_', 'm'], number_format($rate, 4, '.', ''));
    }

    private function formatNumber(float $value, int $decimals = 2): string
    {
        return number_format($value, $decimals, ',', '.');
    }

    private function normalizeObservationLabel(string $value): string
    {
        return preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    }

    private function normalizeGroupKey(string $value): string
    {
        $value = mb_strtolower($this->normalizeObservationLabel($value), 'UTF-8');
        $value = str_replace(["\t", "\r", "\n"], ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function refacturareGroupLabel(string $detailKey, string $typeLabel, string $location): string
    {
        $location = $this->normalizeObservationLabel($location);
        $lower = mb_strtolower($location, 'UTF-8');
        if ($detailKey === 'port' && str_starts_with($lower, 'port ')) {
            return $location;
        }
        if ($detailKey === 'trece' && str_starts_with($lower, 'pod ')) {
            return $location;
        }
        if ($location === '') {
            return $typeLabel;
        }

        return $typeLabel . ' ' . $location;
    }

    private function expenseTypeLabel(string $type): string
    {
        return [
            'motorina' => 'Motorină',
            'taxe_drum' => 'Taxe drum',
            'diurna' => 'Diurnă',
            'service' => 'Service',
            'alte' => 'Alte treceri',
        ][$type] ?? mb_convert_case(str_replace('_', ' ', $type), MB_CASE_TITLE, 'UTF-8');
    }

    private function formatRaceNumber(int $id, string $date): string
    {
        $year = '0000';
        if ($date !== '') {
            $timestamp = strtotime($date);
            if ($timestamp !== false) {
                $year = date('Y', $timestamp);
            }
        }

        return 'C-' . $year . '-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
    }

    private function formatDateLabel(string $date): string
    {
        $timestamp = strtotime($date);
        return $timestamp !== false ? date('d.m.Y', $timestamp) : '-';
    }

    private function nearlyEqual(float $a, float $b, float $epsilon = 0.01): bool
    {
        return abs($a - $b) <= $epsilon;
    }

    private function bindParams(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }
}
