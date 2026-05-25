<?php
declare(strict_types=1);

class CentralizatorFacturareController
{
    private DispecerCurseModel $model;

    private const TRANSPORT_TYPES = [
        'primar' => 'Primar km',
        'primar_tona' => 'Primar tone',
        'distributie' => 'Distributie',
        'primar_distributie' => 'Primar+Distributie',
        'compresor' => 'Compresor',
    ];

    private const BILLING_STATUSES = [
        'in_curs_facturare' => 'in curs de facturare',
        'nefacturat' => 'nefacturat',
        'facturat' => 'facturat',
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
        $page = max(1, (int) ($_GET['p'] ?? 1));

        try {
            $result = $this->model->getBillingCentralizer($filters, $search, $page, ITEMS_PER_PAGE);
            $vehicles = $this->model->getVehicleOptions();
            $beneficiaries = $this->model->getTransportBeneficiaries(false);
            $distributionZones = $this->model->getDistributionZones(false);
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
            $beneficiaries = [];
            $distributionZones = [];
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
                'per_page' => ITEMS_PER_PAGE,
            ],
            'transportTypes' => self::TRANSPORT_TYPES,
            'billingStatuses' => self::BILLING_STATUSES,
            'vehicles' => $vehicles,
            'beneficiaries' => $beneficiaries,
            'distributionZones' => $distributionZones,
        ]);
    }

    private function updateStatusAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'centralizator_facturare']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'centralizator_facturare']));

        $raceId = (int) ($_POST['id'] ?? 0);
        $billingStatus = $this->normalizeBillingStatus((string) ($_POST['status_facturare'] ?? ''));
        $returnUrl = trim((string) ($_POST['return_url'] ?? ''));

        if ($raceId <= 0 || !$this->model->existsRace($raceId)) {
            flash_set('warning', 'Cursa selectata nu exista.');
            $this->redirectToSafeCentralizerUrl($returnUrl);
        }

        if ($billingStatus === '') {
            flash_set('warning', 'Statusul de facturare selectat este invalid.');
            $this->redirectToSafeCentralizerUrl($returnUrl);
        }

        try {
            $updated = $this->model->updateRaceBillingStatus($raceId, $billingStatus, date('Y-m-d H:i:s'));
            flash_set($updated ? 'success' : 'warning', $updated ? 'Statusul cursei a fost actualizat.' : 'Statusul cursei nu a putut fi actualizat.');
        } catch (Throwable $exception) {
            error_log('[CentralizatorFacturareController][update_status] ' . $exception->getMessage());
            flash_set('danger', 'Nu s-a putut actualiza statusul de facturare.');
        }

        $this->redirectToSafeCentralizerUrl($returnUrl);
    }

    private function collectFilters(): array
    {
        $status = $this->normalizeBillingStatus((string) ($_GET['status_facturare'] ?? ''));
        $transportType = trim((string) ($_GET['tip_transport'] ?? ''));
        if (!array_key_exists($transportType, self::TRANSPORT_TYPES)) {
            $transportType = '';
        }

        return [
            'status_facturare' => $status,
            'tip_transport' => $transportType,
            'vehicle_id' => $this->normalizePositiveIntFilter($_GET['vehicle_id'] ?? ''),
            'beneficiar_id' => $this->normalizePositiveIntFilter($_GET['beneficiar_id'] ?? ''),
            'zona_distributie_id' => $this->normalizePositiveIntFilter($_GET['zona_distributie_id'] ?? ''),
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
}
