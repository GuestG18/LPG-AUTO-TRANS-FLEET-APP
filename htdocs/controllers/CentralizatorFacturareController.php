<?php
declare(strict_types=1);

class CentralizatorFacturareController
{
    private DispecerCurseModel $model;
    private CentralizatorFacturareService $centralizerService;
    private string $routePage;

    private const TRANSPORT_TYPES = [
        'primar' => 'Primar',
        'distributie' => 'Distribuție',
        'primar_distributie' => 'P+D',
        'compresor' => 'Compresor',
    ];

    private const BILLING_STATUSES = [
        'facturat' => 'Facturat',
        'in_curs_facturare' => 'În curs',
        'nefacturat' => 'Nefacturat',
    ];

    public function __construct(PDO $db, string $routePage = 'istoric_activitate')
    {
        $this->model = new DispecerCurseModel($db);
        $this->centralizerService = new CentralizatorFacturareService($db);
        $this->routePage = $routePage === 'centralizator_facturare' ? 'centralizator_facturare' : 'istoric_activitate';
    }

    public function handle(string $action): void
    {
        switch ($action) {
            case 'index':
            case 'list':
                if ($this->routePage === 'centralizator_facturare') {
                    $this->centralizerIndexAction();
                    return;
                }
                $this->activityHistoryIndexAction();
                return;

            case 'export':
                if ($this->routePage !== 'centralizator_facturare') {
                    $this->notFound();
                    return;
                }
                $this->exportAction();
                return;

            case 'update_status':
                $this->updateStatusAction();
                return;

            default:
                $this->notFound();
                return;
        }
    }

    private function centralizerIndexAction(): void
    {
        $filters = $this->collectReportFilters();

        try {
            $report = $this->centralizerService->getReport($filters);
        } catch (Throwable $exception) {
            error_log('[CentralizatorFacturareController][centralizer] ' . $exception->getMessage());
            flash_set('danger', 'Nu s-a putut încărca Centralizatorul Facturare.');
            $report = $this->centralizerService->getReport([]);
        }

        render('centralizator_facturare/dashboard.php', [
            'pageTitle' => 'Centralizator facturare',
            'currentPage' => 'centralizator_facturare',
            'report' => $report,
        ]);
    }

    private function activityHistoryIndexAction(): void
    {
        $search = trim((string) ($_GET['q'] ?? ''));
        $filters = $this->collectFilters();

        try {
            $result = $this->model->getBillingCentralizer($filters, $search, 1, 0);
            $vehicles = $this->model->getVehicleOptions();
            $drivers = $this->model->getDriverOptions(false);
            $beneficiaries = $this->model->getTransportBeneficiaries(false);
            $distributionZones = $this->model->getDistributionZones(false);
            $locationOptions = $this->model->getBillingOperationalLocationOptions($filters, $search);
        } catch (Throwable $exception) {
            error_log('[CentralizatorFacturareController][index] ' . $exception->getMessage());
            flash_set('danger', 'Nu s-a putut incarca centralizatorul de facturare.');

            $result = [
                'rows' => [],
                'summary_by_status' => [],
                'summary_by_status_transport' => [],
                'summary_totals' => [],
                'vehicle_km' => [],
                'expense_type_totals' => [],
                'refacturare_type_totals' => [],
                'page' => 1,
                'total_pages' => 1,
                'total_rows' => 0,
            ];
            $vehicles = [];
            $drivers = [];
            $beneficiaries = [];
            $distributionZones = [];
            $locationOptions = [];
        }

        render('centralizator_facturare/index.php', [
            'pageTitle' => 'Istoric activitate',
            'currentPage' => $this->routePage,
            'activityPageKey' => $this->routePage,
            'rows' => $result['rows'],
            'summaryByStatus' => $result['summary_by_status'],
            'summaryByStatusTransport' => $result['summary_by_status_transport'] ?? [],
            'summaryTotals' => $result['summary_totals'] ?? [],
            'vehicleKmRows' => $result['vehicle_km'] ?? [],
            'expenseTypeTotals' => $result['expense_type_totals'] ?? [],
            'refacturareTypeTotals' => $result['refacturare_type_totals'] ?? [],
            'search' => $search,
            'filters' => $filters,
            'pagination' => [
                'page' => $result['page'],
                'total_pages' => $result['total_pages'],
                'total_rows' => $result['total_rows'],
                'per_page' => (int) ($result['total_rows'] ?? 0),
            ],
            'transportTypes' => self::TRANSPORT_TYPES,
            'billingStatuses' => self::BILLING_STATUSES,
            'vehicles' => $vehicles,
            'drivers' => $drivers,
            'beneficiaries' => $beneficiaries,
            'distributionZones' => $distributionZones,
            'locationOptions' => $locationOptions,
        ]);
    }

    private function exportAction(): void
    {
        $report = $this->centralizerService->getExportData($this->collectReportFilters());
        $filters = $report['filters'] ?? [];
        $fileMonth = preg_replace('/[^0-9\-]/', '', (string) ($filters['month'] ?? date('Y-m')));
        $filename = 'centralizator_facturare_' . $fileMonth . '_' . date('Ymd_His') . '.csv';

        header_remove('Content-Type');
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        if ($out === false) {
            exit;
        }

        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Centralizator facturare'], ';');
        fputcsv($out, ['Luna', (string) ($filters['month_label'] ?? '')], ';');
        fputcsv($out, ['Beneficiar ID', (string) ($filters['beneficiar_id'] ?? '')], ';');
        fputcsv($out, ['Tip activitate', (string) ($filters['tip_activitate'] ?? 'toate')], ';');
        fputcsv($out, ['Loc incarcare ID', (string) ($filters['loc_incarcare_id'] ?? '')], ';');
        fputcsv($out, ['Zona descarcare ID', (string) ($filters['zona_distributie_id'] ?? '')], ';');
        fputcsv($out, ['Ruta', (string) ($filters['ruta'] ?? '')], ';');
        fputcsv($out, [], ';');

        fputcsv($out, ['Summary'], ';');
        foreach ((array) ($report['kpis']['cards'] ?? []) as $card) {
            fputcsv($out, [(string) ($card['title'] ?? ''), (string) ($card['value'] ?? 0), (string) ($card['unit'] ?? '')], ';');
        }
        $refCard = (array) ($report['kpis']['refacturari'] ?? []);
        fputcsv($out, [(string) ($refCard['title'] ?? 'Total refacturări'), (string) ($refCard['value'] ?? 0), 'RON'], ';');
        fputcsv($out, [], ';');

        fputcsv($out, ['Activity by transport'], ';');
        fputcsv($out, ['Tip transport', 'Curse', 'Km', 'Tone/Activ.', 'Valoare RON', '% curse'], ';');
        foreach ((array) ($report['activity']['rows'] ?? []) as $row) {
            fputcsv($out, [
                (string) ($row['label'] ?? ''),
                (string) ($row['trips'] ?? 0),
                (string) ($row['km'] ?? 0),
                (string) (($row['tone'] ?? 0) ?: ($row['activity'] ?? 0)),
                (string) ($row['value'] ?? 0),
                (string) ($row['share_percent'] ?? 0),
            ], ';');
        }
        fputcsv($out, [], ';');

        fputcsv($out, ['Activity by vehicle'], ';');
        fputcsv($out, ['Vehicul', 'Capacitate', 'Nr. curse', 'Primar km', 'Primar valoare', 'Primar tone', 'Distribuție tone', 'P+D km', 'P+D tone', 'Compresor activ.', 'Total valoare'], ';');
        foreach ((array) ($report['vehicles']['rows'] ?? []) as $row) {
            fputcsv($out, [
                (string) ($row['nr_inmatriculare'] ?? ''),
                (string) ($row['capacity'] ?? ''),
                (string) ($row['trips'] ?? 0),
                (string) ($row['primar']['km'] ?? 0),
                (string) ($row['primar']['value'] ?? 0),
                (string) ($row['primar_tona']['tone'] ?? 0),
                (string) ($row['distributie']['tone'] ?? 0),
                (string) ($row['primar_distributie']['km'] ?? 0),
                (string) ($row['primar_distributie']['tone'] ?? 0),
                (string) ($row['compresor']['activity'] ?? 0),
                (string) ($row['total_value'] ?? 0),
            ], ';');
        }
        fputcsv($out, [], ';');

        fputcsv($out, ['Vehicle Trip Details'], ';');
        fputcsv($out, ['Vehicul', 'Data', 'Nr. cursa', 'Tip activitate', 'Loc incarcare', 'Zona descarcare', 'Ruta', 'Tip marfa', 'Km', 'Tone', 'Tarif', 'Clasificare tarif', 'Activitate compresor', 'Valoare RON'], ';');
        foreach ((array) ($report['vehicles']['rows'] ?? []) as $vehicle) {
            foreach ((array) ($vehicle['detail_rows'] ?? []) as $detailRow) {
                fputcsv($out, [
                    (string) ($vehicle['nr_inmatriculare'] ?? ''),
                    (string) ($detailRow['date_label'] ?? ''),
                    (string) ($detailRow['race_no'] ?? ''),
                    (string) ($detailRow['type_label'] ?? ''),
                    (string) ($detailRow['loc_label'] ?? ''),
                    (string) ($detailRow['zone_label'] ?? ''),
                    (string) ($detailRow['route_label'] ?? ''),
                    (string) ($detailRow['cargo_label'] ?? ''),
                    (string) ($detailRow['km'] ?? 0),
                    (string) ($detailRow['tone'] ?? 0),
                    (string) ($detailRow['tariff'] ?? ''),
                    (string) ($detailRow['tariff_class'] ?? ''),
                    (string) ($detailRow['compressor_activity_label'] ?? ''),
                    (string) ($detailRow['value'] ?? 0),
                ], ';');
            }
        }
        fputcsv($out, [], ';');

        fputcsv($out, ['Primar routes'], ';');
        fputcsv($out, ['Rută', 'Curse', 'Km parcurși', 'Preț/km', 'Valoare RON'], ';');
        foreach ((array) ($report['primary_routes']['routes'] ?? []) as $route) {
            fputcsv($out, [
                (string) ($route['route_label'] ?? ''),
                (string) ($route['trips'] ?? 0),
                (string) ($route['km'] ?? 0),
                (string) ($route['rate_label'] ?? ''),
                (string) ($route['value'] ?? 0),
            ], ';');
        }
        fputcsv($out, [], ';');

        fputcsv($out, ['Distribution cargo/tariff'], ';');
        $bucketHeaders = array_map(static fn (array $bucket): string => (string) ($bucket['label'] ?? ''), (array) ($report['distribution']['tariff_buckets'] ?? []));
        fputcsv($out, array_merge(['Tip marfă'], $bucketHeaders, ['Total tone', '% din total']), ';');
        foreach ((array) ($report['distribution']['cargo_by_tariff'] ?? []) as $row) {
            $line = [(string) ($row['label'] ?? '')];
            foreach ((array) ($report['distribution']['tariff_buckets'] ?? []) as $bucket) {
                $line[] = (string) (($row['buckets'][$bucket['key'] ?? ''] ?? 0));
            }
            $line[] = (string) ($row['total_tone'] ?? 0);
            $line[] = (string) ($row['percent'] ?? 0);
            fputcsv($out, $line, ';');
        }
        fputcsv($out, [], ';');

        fputcsv($out, ['Refacturări'], ';');
        fputcsv($out, ['Nr. cursă', 'Data', 'Tip activitate', 'Rută / Zonă', 'Vehicul', 'Tip marfă', 'Tone', 'Km', 'Valoare cursă RON', 'Refacturare RON', 'Observații'], ';');
        foreach ((array) ($report['refacturari']['all_rows'] ?? []) as $row) {
            fputcsv($out, [
                (string) ($row['race_no'] ?? ''),
                (string) ($row['date_label'] ?? ''),
                (string) ($row['tip_transport_label'] ?? ''),
                (string) ($row['route_label'] ?? ''),
                (string) ($row['vehicle_label'] ?? ''),
                (string) ($row['tip_marfa_label'] ?? ''),
                (string) ($row['tone'] ?? 0),
                (string) ($row['km'] ?? 0),
                (string) ($row['trip_value'] ?? 0),
                (string) ($row['refacturare_amount'] ?? 0),
                implode(' | ', array_values((array) ($row['observations'] ?? []))),
            ], ';');
        }

        fclose($out);
        exit;
    }

    private function updateStatusAction(): void
    {
        $isJsonRequest = $this->isJsonRequest();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            if ($isJsonRequest) {
                $this->jsonResponse(405, ['ok' => false, 'message' => 'Metodă invalidă.']);
            }
            redirect(build_query_url(['page' => $this->routePage]));
        }

        if (!verify_csrf_token($_POST['_token'] ?? null)) {
            if ($isJsonRequest) {
                $this->jsonResponse(419, ['ok' => false, 'message' => 'Token CSRF invalid. Reîncearcă operațiunea.']);
            }
            ensure_csrf_or_redirect(build_query_url(['page' => $this->routePage]));
        }

        $raceId = (int) ($_POST['id'] ?? 0);
        $billingStatus = $this->normalizeBillingStatus((string) ($_POST['status_facturare'] ?? ''));
        $returnUrl = trim((string) ($_POST['return_url'] ?? ''));

        if ($raceId <= 0 || !$this->model->existsRace($raceId)) {
            if ($isJsonRequest) {
                $this->jsonResponse(404, ['ok' => false, 'message' => 'Cursa selectată nu există.']);
            }
            flash_set('warning', 'Cursa selectata nu exista.');
            $this->redirectToSafeCentralizerUrl($returnUrl);
        }

        if ($billingStatus === '') {
            if ($isJsonRequest) {
                $this->jsonResponse(422, ['ok' => false, 'message' => 'Statusul de facturare selectat este invalid.']);
            }
            flash_set('warning', 'Statusul de facturare selectat este invalid.');
            $this->redirectToSafeCentralizerUrl($returnUrl);
        }

        try {
            $updated = $this->model->updateRaceBillingStatus($raceId, $billingStatus, date('Y-m-d H:i:s'));
            if ($isJsonRequest) {
                $this->jsonResponse($updated ? 200 : 409, [
                    'ok' => $updated,
                    'message' => $updated ? 'Statusul cursei a fost actualizat.' : 'Statusul cursei nu a putut fi actualizat.',
                    'status' => $billingStatus,
                    'label' => self::BILLING_STATUSES[$billingStatus] ?? $billingStatus,
                ]);
            }
            flash_set($updated ? 'success' : 'warning', $updated ? 'Statusul cursei a fost actualizat.' : 'Statusul cursei nu a putut fi actualizat.');
        } catch (Throwable $exception) {
            error_log('[CentralizatorFacturareController][update_status] ' . $exception->getMessage());
            if ($isJsonRequest) {
                $this->jsonResponse(500, ['ok' => false, 'message' => 'Nu s-a putut actualiza statusul de facturare.']);
            }
            flash_set('danger', 'Nu s-a putut actualiza statusul de facturare.');
        }

        $this->redirectToSafeCentralizerUrl($returnUrl);
    }

    private function collectFilters(): array
    {
        $status = $this->normalizeBillingStatus((string) ($_GET['status_facturare'] ?? ''));
        $transportType = trim((string) ($_GET['tip_transport'] ?? ''));
        if ($transportType === 'primar_tona') {
            $transportType = 'primar';
        }
        if (!array_key_exists($transportType, self::TRANSPORT_TYPES)) {
            $transportType = '';
        }

        return [
            'status_facturare' => $status,
            'tip_transport' => $transportType,
            'nr_inmatriculare' => $this->normalizeTextFilter($_GET['nr_inmatriculare'] ?? ''),
            'vehicle_id' => $this->normalizePositiveIntFilter($_GET['vehicle_id'] ?? ''),
            'driver_id' => $this->normalizePositiveIntFilter($_GET['driver_id'] ?? ''),
            'beneficiar_id' => $this->normalizePositiveIntFilter($_GET['beneficiar_id'] ?? ''),
            'tip_marfa' => $this->normalizeGoodsFilter($_GET['tip_marfa'] ?? ''),
            'zona_distributie_id' => $this->normalizePositiveIntFilter($_GET['zona_distributie_id'] ?? ''),
            'locatie_operationala' => $this->normalizeTextArrayFilter($_GET['locatie_operationala'] ?? []),
            'loc_incarcare' => $this->normalizeTextArrayFilter($_GET['loc_incarcare'] ?? []),
            'zona_distributie' => $this->normalizeTextArrayFilter($_GET['zona_distributie'] ?? []),
            'data_start' => $this->normalizeDateFilter($_GET['data_start'] ?? ''),
            'data_end' => $this->normalizeDateFilter($_GET['data_end'] ?? ''),
        ];
    }

    private function collectReportFilters(): array
    {
        return [
            'month' => trim((string) ($_GET['month'] ?? '')),
            'beneficiar_id' => $this->normalizePositiveIntFilter($_GET['beneficiar_id'] ?? ''),
            'tip_activitate' => trim((string) ($_GET['tip_activitate'] ?? '')),
            'tip_marfa' => trim((string) ($_GET['tip_marfa'] ?? '')),
            'loc_incarcare_id' => $this->normalizePositiveIntFilter($_GET['loc_incarcare_id'] ?? ''),
            'zona_distributie_id' => $this->normalizePositiveIntFilter($_GET['zona_distributie_id'] ?? ''),
            'ruta' => trim((string) ($_GET['ruta'] ?? '')),
            'vehicle_id' => $this->normalizePositiveIntFilter($_GET['vehicle_id'] ?? ''),
            'vehicle_sort' => trim((string) ($_GET['vehicle_sort'] ?? 'capacity_asc')),
            'page_no' => $this->normalizePositiveIntFilter($_GET['p'] ?? ($_GET['page_no'] ?? '1')),
            'per_page' => $this->normalizePositiveIntFilter($_GET['per_page'] ?? '10'),
        ];
    }

    private function normalizeBillingStatus(string $value): string
    {
        $key = trim(strtolower($value));

        return array_key_exists($key, self::BILLING_STATUSES) ? $key : '';
    }

    private function normalizePositiveIntFilter(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '' || !ctype_digit($value) || (int) $value <= 0) {
            return '';
        }

        return $value;
    }

    private function normalizeTextFilter(mixed $value): string
    {
        return mb_substr(trim((string) $value), 0, 80);
    }

    private function normalizeGoodsFilter(mixed $value): string
    {
        $value = trim((string) $value);
        return in_array($value, ['butan', 'propan', 'autogaz'], true) ? $value : '';
    }

    private function normalizeTextArrayFilter(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];
        $normalized = [];

        foreach ($values as $item) {
            $item = mb_substr(trim((string) $item), 0, 120);
            if ($item === '') {
                continue;
            }
            $normalized[$item] = $item;
        }

        $result = array_values($normalized);
        natcasesort($result);

        return array_values($result);
    }

    private function normalizeDateFilter(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return '';
        }

        return $value;
    }

    private function redirectToSafeCentralizerUrl(string $returnUrl): void
    {
        if ($returnUrl !== '') {
            $parsed = parse_url($returnUrl);
            $path = (string) ($parsed['path'] ?? '');
            $query = (string) ($parsed['query'] ?? '');
            $isIndexPath = $path === ''
                || $path === 'index.php'
                || str_ends_with($path, '/index.php');

            if (
                $isIndexPath
                && (
                    str_contains($query, 'page=istoric_activitate')
                    || str_contains($query, 'page=centralizator_facturare')
                )
            ) {
                redirect($returnUrl);
            }
        }

        redirect(build_query_url(['page' => $this->routePage]));
    }

    private function isJsonRequest(): bool
    {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));

        return $requestedWith === 'xmlhttprequest' || str_contains($accept, 'application/json');
    }

    private function jsonResponse(int $statusCode, array $payload): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function notFound(): void
    {
        http_response_code(404);
        render('errors/404.php', [
            'pageTitle' => 'Actiune inexistenta',
            'currentPage' => $this->routePage,
        ]);
    }
}
