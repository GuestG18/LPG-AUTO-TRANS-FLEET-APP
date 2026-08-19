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
            case 'set_odometer':
                $this->setOdometerAction();
                return;
            case 'set_t0':
                $this->setT0Action();
                return;
            case 'clear_t0':
                $this->clearT0Action();
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
            'canManageFull' => $this->canManageFull(),
        ]);
    }

    private function syncNowAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'carburanti']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'carburanti']));

        try {
            // La zi: incremental dupa ultimul ID din baza (fara limita celor
            // 31 de zile), cu fallback pe fereastra rulanta cand baza e goala.
            $result = $this->model->syncLatestFromApi(new CardOilApiClient());
            $status = (string) ($result['status'] ?? 'success');
            $message = sprintf(
                'Sincronizare CardOil (%s) finalizata: %d primite, %d inserate, %d actualizate, %d cereri.',
                (string) ($result['mode'] ?? 'range') === 'incremental' ? 'incremental, dupa ID' : 'ultimele 31 zile',
                (int) ($result['records_received'] ?? 0),
                (int) ($result['records_inserted'] ?? 0),
                (int) ($result['records_updated'] ?? 0),
                (int) ($result['requests'] ?? 1)
            );
            flash_set($status === 'error' ? 'danger' : ($status === 'partial' ? 'warning' : 'success'), $message);
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

    /**
     * Corecteaza manual odometrul unei alimentari (km gresiti din CardOil)
     * sau revine la valoarea bruta din API. Corectia este persistenta la sync.
     */
    private function setOdometerAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'carburanti']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'carburanti']));
        $this->requireFullManagement();

        $fillupId = (int) ($_POST['fillup_id'] ?? 0);
        if ($fillupId <= 0) {
            flash_set('warning', 'Alimentarea selectata nu este valida.');
            redirect($this->safeReturnUrl($_POST['return_url'] ?? null));
        }

        try {
            if (isset($_POST['reset_api'])) {
                if ($this->model->clearFillupOdometerOverride($fillupId)) {
                    flash_set('success', 'Odometrul a revenit la valoarea primita din CardOil.');
                } else {
                    flash_set('warning', 'Odometrul nu a putut fi resetat.');
                }
                redirect($this->safeReturnUrl($_POST['return_url'] ?? null));
            }

            $rawValue = str_replace([' ', '.', ','], '', trim((string) ($_POST['odometer_km'] ?? '')));
            if ($rawValue === '' || !ctype_digit($rawValue)) {
                flash_set('warning', 'Introdu un kilometraj valid (numar intreg pozitiv).');
                redirect($this->safeReturnUrl($_POST['return_url'] ?? null));
            }

            $odometerKm = (int) $rawValue;
            if ($odometerKm <= 0 || $odometerKm > 5000000) {
                flash_set('warning', 'Kilometrajul trebuie sa fie intre 1 si 5.000.000 km.');
                redirect($this->safeReturnUrl($_POST['return_url'] ?? null));
            }

            if ($this->model->setFillupOdometer($fillupId, $odometerKm)) {
                flash_set('success', sprintf('Odometrul a fost corectat manual la %s km. Valoarea este protejata la sincronizarile CardOil.', number_format($odometerKm, 0, ',', '.')));
            } else {
                flash_set('warning', 'Odometrul nu a putut fi actualizat.');
            }
        } catch (Throwable $exception) {
            error_log('[FuelController][set_odometer] ' . $exception->getMessage());
            flash_set('danger', 'A aparut o eroare la corectarea odometrului.');
        }

        redirect($this->safeReturnUrl($_POST['return_url'] ?? null));
    }

    /**
     * Stabileste manual T0 pentru (vehicul, luna).
     *
     * Este actiunea prevazuta explicit pentru cazul in care regula automata
     * ±4 zile nu gaseste niciun FULL eligibil.
     */
    private function setT0Action(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'carburanti']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'carburanti']));
        $this->requireFullManagement();

        $returnUrl = $this->safeReturnUrl($_POST['return_url'] ?? null);
        $fillupId = (int) ($_POST['fillup_id'] ?? 0);
        $vehicle = trim((string) ($_POST['vehicle'] ?? ''));
        $monthStart = $this->parseMonthStart((string) ($_POST['month_start'] ?? ''));
        $confirmMarkFull = (int) ($_POST['confirm_mark_full'] ?? 0) === 1;
        $note = trim((string) ($_POST['note'] ?? ''));

        if ($fillupId <= 0 || $vehicle === '' || $monthStart === null) {
            flash_set('warning', 'Selectează vehiculul, luna și alimentarea pentru a stabili T0.');
            redirect($returnUrl);
        }

        try {
            $result = $this->model->setManualT0(
                $fillupId,
                $vehicle,
                $monthStart,
                $confirmMarkFull,
                $this->currentUserId(),
                $note !== '' ? $note : null
            );

            if (!$result['ok']) {
                flash_set('warning', $result['message']);
                redirect($returnUrl);
            }

            $message = $result['message'];
            if ($result['warnings'] !== []) {
                $message .= ' Atenție: ' . implode(' ', $result['warnings']);
            }
            flash_set('success', $message);
        } catch (Throwable $exception) {
            error_log('[FuelController][set_t0] ' . $exception->getMessage());
            flash_set('danger', 'A aparut o eroare la stabilirea manuala a T0.');
        }

        redirect($returnUrl);
    }

    /** Revine la selectia automata a T0 pentru (vehicul, luna). */
    private function clearT0Action(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'carburanti']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'carburanti']));
        $this->requireFullManagement();

        $returnUrl = $this->safeReturnUrl($_POST['return_url'] ?? null);
        $vehicle = trim((string) ($_POST['vehicle'] ?? ''));
        $monthStart = $this->parseMonthStart((string) ($_POST['month_start'] ?? ''));

        if ($vehicle === '' || $monthStart === null) {
            flash_set('warning', 'Nu s-a putut identifica vehiculul sau luna.');
            redirect($returnUrl);
        }

        try {
            if ($this->model->clearManualT0($vehicle, $monthStart)) {
                flash_set('success', 'T0 manual a fost eliminat. Luna revine la selecția automată.');
            } else {
                flash_set('info', 'Nu exista un T0 manual pentru vehiculul si luna selectate.');
            }
        } catch (Throwable $exception) {
            error_log('[FuelController][clear_t0] ' . $exception->getMessage());
            flash_set('danger', 'A aparut o eroare la eliminarea T0 manual.');
        }

        redirect($returnUrl);
    }

    /**
     * Deciziile FULL/T0 reutilizeaza permisiunea existenta `carburanti.set_full`,
     * ca sa nu fie nevoie de reconfigurarea drepturilor dupa deploy.
     */
    private function requireFullManagement(): void
    {
        if ($this->canManageFull()) {
            return;
        }

        flash_set('danger', 'Nu ai dreptul sa modifici marcajul Full / T0.');
        redirect(build_query_url(['page' => 'carburanti']));
    }

    public function canManageFull(): bool
    {
        if (function_exists('is_admin') && is_admin()) {
            return true;
        }

        return function_exists('can') && can('carburanti', 'set_full');
    }

    private function currentUserId(): ?int
    {
        if (function_exists('current_user')) {
            $user = current_user();
            $id = (int) ($user['id'] ?? 0);
            if ($id > 0) {
                return $id;
            }
        }

        $id = (int) ($_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? 0);

        return $id > 0 ? $id : null;
    }

    /** Accepta `Y-m-d` sau `Y-m` si normalizeaza la prima zi a lunii. */
    private function parseMonthStart(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}$/', $value) === 1) {
            $value .= '-01';
        }

        try {
            return (new DateTimeImmutable($value))->modify('first day of this month')->setTime(0, 0);
        } catch (Throwable) {
            return null;
        }
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

        $brand = trim((string) ($input['brand'] ?? ''));
        if (mb_strlen($brand) > 60) {
            $brand = '';
        }

        return [
            'date_from' => $dateFrom->format('Y-m-d'),
            'date_to' => $dateTo->format('Y-m-d'),
            'period' => $dateFrom->format('d.m.Y') . ' - ' . $dateTo->format('d.m.Y'),
            'vehicle' => $vehicles[0] ?? '',
            'vehicles' => $vehicles,
            'transport_group' => $transportGroup,
            'fuel_type' => $fuelType,
            'brand' => $brand,
        ];
    }

    private function collectCompare(array $input, array $filters): array
    {
        $mode = (string) ($input['compare_mode'] ?? '');
        $mode = $mode === 'vehicles' ? 'vehicles' : 'periods';

        $baseFrom = new DateTimeImmutable((string) $filters['date_from']);
        $baseTo = new DateTimeImmutable((string) $filters['date_to']);
        $days = max(1, ((int) $baseFrom->diff($baseTo)->format('%a')) + 1);
        if ($baseFrom->format('j') === '1'
            && $baseTo->format('Y-m-d') === $baseFrom->modify('last day of this month')->format('Y-m-d')) {
            // Perioada analizata este o luna calendaristica intreaga: perioada
            // B implicita devine LUNA PRECEDENTA, nu "acelasi numar de zile
            // inapoi" (care producea intervale de tip 31.05 - 30.06).
            $previousFrom = $baseFrom->modify('first day of previous month');
            $previousTo = $previousFrom->modify('last day of this month');
        } else {
            $previousTo = $baseFrom->modify('-1 day');
            $previousFrom = $previousTo->modify('-' . ($days - 1) . ' days');
        }

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
