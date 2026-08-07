<?php
declare(strict_types=1);

class FuelController
{
    private FuelModel $model;

    public function __construct(PDO $db)
    {
        $this->model = new FuelModel($db);
    }

    public function handle(string $action): void
    {
        switch ($action) {
            case 'index':
            case 'list':
                $this->indexAction();
                return;
            case 'sync_now':
                $this->syncNowAction();
                return;
            case 'link_fillup':
                $this->linkFillupAction();
                return;
            case 'set_full':
                $this->setFullAction();
                return;
            default:
                http_response_code(404);
                render('errors/404.php', [
                    'pageTitle' => 'Actiune inexistenta',
                    'currentPage' => 'carburanti',
                ]);
                return;
        }
    }

    private function indexAction(): void
    {
        $filters = $this->collectFilters($_GET);
        $compare = $this->collectCompare($_GET, $filters);

        try {
            $this->model->ensureSchema();
            $this->model->refreshAutomaticAssociations((string) $filters['date_from'], (string) $filters['date_to']);
            $data = $this->model->getDashboardData($filters);
            $data['comparison'] = $this->model->getComparisonData($compare['filters_a'], $compare['filters_b']);
            $data['vehicle_comparison'] = $this->model->getConsumptionByVehicle($filters);
            $data['vehicle_daily_charts'] = $this->model->getVehicleDailyCharts($filters);
        } catch (Throwable $exception) {
            error_log('[FuelController][index] ' . $exception->getMessage());
            flash_set('danger', 'Nu s-au putut incarca datele pentru carburanti.');
            $data = [
                'kpis' => [
                    'motorina_liters' => 0,
                    'adblue_liters' => 0,
                    'motorina_avg_l100' => 0,
                    'adblue_percent' => 0,
                    'total_value' => 0,
                    'linked_km' => 0,
                    'changes' => [],
                ],
                'daily_chart' => ['points' => [], 'average' => 0],
                'transport_chart' => ['items' => [], 'total' => 0],
                'normative' => [],
                'latest_fillups' => [],
                'all_fillups' => [],
                'unassociated_fillups' => [],
                'trip_consumption' => [],
                'transport_consumption' => [],
                'trip_options' => [],
                'sync_logs' => [],
                'last_sync' => null,
                'vehicle_options' => [],
                'comparison' => null,
                'vehicle_comparison' => [],
                'vehicle_daily_charts' => [],
            ];
        }

        render('carburanti/index.php', [
            'pageTitle' => 'Carburanti',
            'currentPage' => 'carburanti',
            'filters' => $filters,
            'compare' => $compare,
            'transportLabels' => $this->model->getTransportLabels(),
            'fuelData' => $data,
        ]);
    }

    private function syncNowAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'carburanti']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'carburanti']));

        [$dateFrom, $dateTo] = $this->currentMonthInterval();

        try {
            $result = $this->model->syncFromApi($dateFrom, $dateTo, new CardOilApiClient());
            $status = (string) ($result['status'] ?? 'success');
            $message = sprintf(
                'Sincronizare CardOil finalizata pentru %s - %s: %d primite, %d inserate, %d actualizate.',
                $dateFrom->format('d.m.Y'),
                $dateTo->format('d.m.Y'),
                (int) ($result['records_received'] ?? 0),
                (int) ($result['records_inserted'] ?? 0),
                (int) ($result['records_updated'] ?? 0)
            );
            flash_set($status === 'error' ? 'danger' : ($status === 'demo' ? 'warning' : 'success'), $message);
        } catch (Throwable $exception) {
            error_log('[FuelController][sync_now] ' . $exception->getMessage());
            flash_set('danger', 'Sincronizarea CardOil a esuat: ' . $exception->getMessage());
        }

        redirect($this->safeReturnUrl($_POST['return_url'] ?? null));
    }

    private function linkFillupAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'carburanti']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'carburanti']));

        $fillupId = (int) ($_POST['fillup_id'] ?? 0);
        $tripId = (int) ($_POST['trip_id'] ?? 0);

        if ($fillupId <= 0 || $tripId <= 0) {
            flash_set('warning', 'Selecteaza alimentarea si cursa pentru asociere manuala.');
            redirect($this->safeReturnUrl($_POST['return_url'] ?? null));
        }

        try {
            if ($this->model->linkFillupToTrip($fillupId, $tripId)) {
                flash_set('success', 'Alimentarea a fost asociata manual cu cursa selectata.');
            } else {
                flash_set('warning', 'Alimentarea nu a putut fi asociata.');
            }
        } catch (Throwable $exception) {
            error_log('[FuelController][link_fillup] ' . $exception->getMessage());
            flash_set('danger', 'A aparut o eroare la asocierea manuala.');
        }

        redirect($this->safeReturnUrl($_POST['return_url'] ?? null));
    }

    private function setFullAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'carburanti']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'carburanti']));

        $fillupId = (int) ($_POST['fillup_id'] ?? 0);
        $isFull = (int) ($_POST['is_full'] ?? 0) === 1;

        if ($fillupId <= 0) {
            flash_set('warning', 'Alimentarea selectata nu este valida.');
            redirect($this->safeReturnUrl($_POST['return_url'] ?? null));
        }

        try {
            if ($this->model->setFillupFull($fillupId, $isFull)) {
                flash_set('success', $isFull ? 'Alimentarea a fost marcata Full.' : 'Alimentarea a fost marcata Partial.');
            } else {
                flash_set('warning', 'Alimentarea nu a putut fi actualizata.');
            }
        } catch (Throwable $exception) {
            error_log('[FuelController][set_full] ' . $exception->getMessage());
            flash_set('danger', 'A aparut o eroare la actualizarea tipului de alimentare.');
        }

        redirect($this->safeReturnUrl($_POST['return_url'] ?? null));
    }

    private function collectFilters(array $input): array
    {
        $today = new DateTimeImmutable('today');
        $defaultFrom = $today->modify('first day of this month');
        $defaultTo = $today->modify('last day of this month');
        $dateFrom = $defaultFrom;
        $dateTo = $defaultTo;

        $period = trim((string) ($input['period'] ?? ''));
        if ($period !== '' && preg_match('/(\d{2}\.\d{2}\.\d{4})\s*-\s*(\d{2}\.\d{2}\.\d{4})/', $period, $matches) === 1) {
            $parsedFrom = DateTimeImmutable::createFromFormat('d.m.Y', $matches[1]);
            $parsedTo = DateTimeImmutable::createFromFormat('d.m.Y', $matches[2]);
            if ($parsedFrom instanceof DateTimeImmutable && $parsedTo instanceof DateTimeImmutable) {
                $dateFrom = $parsedFrom;
                $dateTo = $parsedTo;
            }
        } elseif (!empty($input['date_from']) || !empty($input['date_to'])) {
            $parsedFrom = $this->parseDate((string) ($input['date_from'] ?? ''));
            $parsedTo = $this->parseDate((string) ($input['date_to'] ?? ''));
            if ($parsedFrom instanceof DateTimeImmutable) {
                $dateFrom = $parsedFrom;
            }
            if ($parsedTo instanceof DateTimeImmutable) {
                $dateTo = $parsedTo;
            }
        }

        if ($dateTo < $dateFrom) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        $vehiclesInput = $input['vehicles'] ?? [];
        if (!is_array($vehiclesInput)) {
            $vehiclesInput = trim((string) $vehiclesInput) !== '' ? [(string) $vehiclesInput] : [];
        }
        $legacyVehicle = trim((string) ($input['vehicle'] ?? ''));
        if ($vehiclesInput === [] && $legacyVehicle !== '') {
            $vehiclesInput = [$legacyVehicle];
        }
        $vehicles = [];
        foreach ($vehiclesInput as $vehicleValue) {
            $vehicleValue = trim((string) $vehicleValue);
            if ($vehicleValue !== '' && !in_array($vehicleValue, $vehicles, true)) {
                $vehicles[] = $vehicleValue;
            }
        }

        $transportGroup = trim((string) ($input['transport_group'] ?? ''));
        if (!in_array($transportGroup, ['primar', 'distributie', 'compresor', 'primar_distributie'], true)) {
            $transportGroup = '';
        }

        $fuelType = trim((string) ($input['fuel_type'] ?? ''));
        if (!in_array($fuelType, ['motorina', 'adblue'], true)) {
            $fuelType = '';
        }

        return [
            'date_from' => $dateFrom->format('Y-m-d'),
            'date_to' => $dateTo->format('Y-m-d'),
            'period' => $dateFrom->format('d.m.Y') . ' - ' . $dateTo->format('d.m.Y'),
            'vehicle' => $vehicles[0] ?? '',
            'vehicles' => $vehicles,
            'transport_group' => $transportGroup,
            'fuel_type' => $fuelType,
        ];
    }

    private function collectCompare(array $input, array $filters): array
    {
        $mode = (string) ($input['compare_mode'] ?? '');
        $mode = $mode === 'vehicles' ? 'vehicles' : 'periods';

        $baseFrom = new DateTimeImmutable((string) $filters['date_from']);
        $baseTo = new DateTimeImmutable((string) $filters['date_to']);
        $days = max(1, ((int) $baseFrom->diff($baseTo)->format('%a')) + 1);
        $previousTo = $baseFrom->modify('-1 day');
        $previousFrom = $previousTo->modify('-' . ($days - 1) . ' days');

        $parsePeriod = static function (string $value, DateTimeImmutable $defaultFrom, DateTimeImmutable $defaultTo): array {
            if (preg_match('/(\d{2}\.\d{2}\.\d{4})\s*-\s*(\d{2}\.\d{2}\.\d{4})/', $value, $matches) === 1) {
                $from = DateTimeImmutable::createFromFormat('d.m.Y', $matches[1]);
                $to = DateTimeImmutable::createFromFormat('d.m.Y', $matches[2]);
                if ($from instanceof DateTimeImmutable && $to instanceof DateTimeImmutable) {
                    if ($to < $from) {
                        [$from, $to] = [$to, $from];
                    }

                    return [$from, $to];
                }
            }

            return [$defaultFrom, $defaultTo];
        };

        $formatPeriod = static fn (DateTimeImmutable $from, DateTimeImmutable $to): string =>
            $from->format('d.m.Y') . ' - ' . $to->format('d.m.Y');

        $selectedVehicles = isset($filters['vehicles']) && is_array($filters['vehicles']) ? $filters['vehicles'] : [];

        if ($mode === 'vehicles') {
            $vehicleA = trim((string) ($input['compare_vehicle_a'] ?? ''));
            if ($vehicleA === '') {
                $vehicleA = (string) ($selectedVehicles[0] ?? '');
            }
            $vehicleB = trim((string) ($input['compare_vehicle_b'] ?? ''));
            if ($vehicleB === '') {
                $vehicleB = (string) ($selectedVehicles[1] ?? '');
            }

            $filtersA = $filters;
            $filtersA['vehicle'] = $vehicleA;
            $filtersA['vehicles'] = $vehicleA !== '' ? [$vehicleA] : [];
            $filtersB = $filters;
            $filtersB['vehicle'] = $vehicleB;
            $filtersB['vehicles'] = $vehicleB !== '' ? [$vehicleB] : [];

            return [
                'mode' => 'vehicles',
                'filters_a' => $filtersA,
                'filters_b' => $filtersB,
                'label_a' => $vehicleA !== '' ? $vehicleA : 'Toate vehiculele',
                'label_b' => $vehicleB !== '' ? $vehicleB : 'Toate vehiculele',
                'vehicle_a' => $vehicleA,
                'vehicle_b' => $vehicleB,
                'period_a' => (string) $filters['period'],
                'period_b' => $formatPeriod($previousFrom, $previousTo),
                'subtitle' => 'Perioada: ' . (string) $filters['period'],
            ];
        }

        [$fromA, $toA] = $parsePeriod((string) ($input['compare_period_a'] ?? ''), $baseFrom, $baseTo);
        [$fromB, $toB] = $parsePeriod((string) ($input['compare_period_b'] ?? ''), $previousFrom, $previousTo);

        $filtersA = $filters;
        $filtersA['date_from'] = $fromA->format('Y-m-d');
        $filtersA['date_to'] = $toA->format('Y-m-d');
        $filtersA['period'] = $formatPeriod($fromA, $toA);

        $filtersB = $filters;
        $filtersB['date_from'] = $fromB->format('Y-m-d');
        $filtersB['date_to'] = $toB->format('Y-m-d');
        $filtersB['period'] = $formatPeriod($fromB, $toB);

        $vehicleLabel = $selectedVehicles !== [] ? implode(', ', $selectedVehicles) : 'Toate vehiculele';
        $vehicle = trim((string) ($filters['vehicle'] ?? ''));

        return [
            'mode' => 'periods',
            'filters_a' => $filtersA,
            'filters_b' => $filtersB,
            'label_a' => $formatPeriod($fromA, $toA),
            'label_b' => $formatPeriod($fromB, $toB),
            'vehicle_a' => $vehicle,
            'vehicle_b' => $vehicle,
            'period_a' => $formatPeriod($fromA, $toA),
            'period_b' => $formatPeriod($fromB, $toB),
            'subtitle' => 'Vehicule: ' . $vehicleLabel,
        ];
    }

    private function currentMonthInterval(): array
    {
        $today = new DateTimeImmutable('today');

        return [
            $today->modify('first day of this month'),
            $today->modify('last day of this month'),
        ];
    }

    private function parseDate(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        foreach (['Y-m-d', 'd.m.Y', 'd/m/Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            if ($date instanceof DateTimeImmutable) {
                return $date;
            }
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function safeReturnUrl(mixed $value): string
    {
        $fallback = build_query_url(['page' => 'carburanti']);
        if (!is_string($value) || trim($value) === '') {
            return $fallback;
        }

        $value = trim($value);
        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://') || str_starts_with($value, '//')) {
            return $fallback;
        }

        return $value;
    }
}
