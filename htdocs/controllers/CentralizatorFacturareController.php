<?php
declare(strict_types=1);

class CentralizatorFacturareController
{
    private DispecerCurseModel $model;

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

    public function __construct(PDO $db)
    {
        $this->model = new DispecerCurseModel($db);
    }

    public function handle(string $action): void
    {
        switch ($action) {
            case 'index':
            case 'list':
                $this->indexAction();
                return;

            case 'update_status':
                $this->updateStatusAction();
                return;

            default:
                http_response_code(404);
                render('errors/404.php', [
                    'pageTitle' => 'Actiune inexistenta',
                    'currentPage' => 'centralizator_facturare',
                ]);
                return;
        }
    }

    private function indexAction(): void
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
            'pageTitle' => 'Centralizator Facturare',
            'currentPage' => 'centralizator_facturare',
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

    private function updateStatusAction(): void
    {
        $isJsonRequest = $this->isJsonRequest();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            if ($isJsonRequest) {
                $this->jsonResponse(405, ['ok' => false, 'message' => 'Metodă invalidă.']);
            }
            redirect(build_query_url(['page' => 'centralizator_facturare']));
        }

        if (!verify_csrf_token($_POST['_token'] ?? null)) {
            if ($isJsonRequest) {
                $this->jsonResponse(419, ['ok' => false, 'message' => 'Token CSRF invalid. Reîncearcă operațiunea.']);
            }
            ensure_csrf_or_redirect(build_query_url(['page' => 'centralizator_facturare']));
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

            if ($isIndexPath && str_contains($query, 'page=centralizator_facturare')) {
                redirect($returnUrl);
            }
        }

        redirect(build_query_url(['page' => 'centralizator_facturare']));
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
}
