<?php
declare(strict_types=1);

class DashboardController
{
    private DashboardModel $dashboardModel;
    private InactiveResourceApprovalModel $approvalModel;
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->dashboardModel = new DashboardModel($db);
        $this->approvalModel = new InactiveResourceApprovalModel($db);
    }

    public function index(): void
    {
        $periodOptions = $this->getPeriodOptions();
        $vehicleCategoryOptions = $this->getVehicleCategoryOptions();
        $vehicleOptions = $this->dashboardModel->getVehicleOptions();
        $filters = $this->resolveFilters($periodOptions, $vehicleCategoryOptions, $vehicleOptions);

        $dashboardError = null;
        try {
            $dashboard = $this->dashboardModel->getDashboardOverview($filters);
        } catch (Throwable $exception) {
            error_log('[DashboardController][index] ' . $exception->getMessage());
            $dashboard = $this->dashboardModel->getEmptyDashboardOverview($filters);
            $dashboardError = 'Datele nu au putut fi încărcate. Încearcă din nou.';
        }

        $filters['period_range'] = $dashboard['period_range'] ?? $this->dashboardModel->getPeriodRangeForFilters($filters);
        $filters['period_range_label'] = $this->formatPeriodRangeLabel($filters['period_range']);
        $filters['vehicle_registration'] = $filters['vehicle_id'] !== null ? $filters['vehicle_label'] : null;

        // Cardul „Cost total operațional” (salarii, costuri, cheltuieli, documente, dotări) cere
        // dreptul „Date financiare” din Tablou de bord (implicit doar admin). Fără el nu se
        // calculează nimic, ca datele să nu ajungă nici în HTML, nici în reîmprospătarea live.
        $canFinancial = !function_exists('can') || can('dashboard', 'view_financial');
        $operationalCosts = null;
        $expenseBreakdown = null;
        $documentCosts = null;
        $equipmentCosts = null;
        if ($canFinancial) {
            // Cost total operațional = modelul complet din Cost operațional / km (fixe + variabile,
            // fără TVA). Modelul include salarii, deci cere același drept ca pagina Cost / km.
            // Fără drept sau fără motor, cardul rămâne pe carburant + mentenanță.
            $canViewCostModel = !function_exists('can') || can('cost_operational');
            if ($canViewCostModel) {
                try {
                    $operationalCosts = (new DashboardOperationalCostService($this->db))->build($filters, $filters['period_range']);
                    if (empty($operationalCosts['available'])) {
                        $operationalCosts = null;
                    }
                } catch (Throwable $exception) {
                    error_log('[DashboardController][operational_costs] ' . $exception->getMessage());
                }
            }

            // Cheltuielile din registrul page=cheltuieli (administrative / operaționale), cu logica
            // paginii: valoarea documentului (cu TVA) + carburantul. Informativ: nu se adună la
            // costul total, care conține deja carburantul și managementul (fără TVA).
            if (!function_exists('can') || can('cheltuieli')) {
                try {
                    $expenseBreakdown = (new ExpenseModel($this->db))->getCategoryTypeBreakdown([
                        'date_start' => (string) ($filters['period_range']['date_start'] ?? ''),
                        'date_end' => (string) ($filters['period_range']['date_end'] ?? ''),
                        'vehicul_id' => (int) ($filters['vehicle_id'] ?? 0),
                    ]);
                } catch (Throwable $exception) {
                    error_log('[DashboardController][cheltuieli] ' . $exception->getMessage());
                }
            }

            // Costul documentelor (vehicule + șoferi) din Configurare costuri, pe perioada filtrului.
            try {
                $documentCosts = $this->dashboardModel->getDocumentCostBreakdown($filters);
            } catch (Throwable $exception) {
                error_log('[DashboardController][document_costs] ' . $exception->getMessage());
            }

            try {
                $equipmentCosts = $this->dashboardModel->getEquipmentCostBreakdown($filters);
            } catch (Throwable $exception) {
                error_log('[DashboardController][equipment_costs] ' . $exception->getMessage());
            }
        }

        $canReviewInactiveApprovals = $this->canReviewInactiveApprovals();
        $approvalSummary = [
            'counts' => ['vehicle' => 0, 'driver' => 0, 'repair' => 0],
            'total' => 0,
            'vehicles' => [],
            'drivers' => [],
            'repairs' => [],
        ];
        if ($canReviewInactiveApprovals) {
            try {
                $approvalSummary = $this->approvalModel->getPendingSummary(3);
            } catch (Throwable $exception) {
                error_log('[DashboardController][inactive_approvals] ' . $exception->getMessage());
            }
        }

        render('dashboard/index.php', [
            'pageTitle' => 'Dashboard',
            'currentPage' => 'dashboard',
            'dashboard' => $dashboard,
            'dashboardError' => $dashboardError,
            'dashboardFilters' => $filters,
            'periodOptions' => $periodOptions,
            'vehicleCategoryOptions' => $vehicleCategoryOptions,
            'vehicleOptions' => $vehicleOptions,
            'approvalSummary' => $approvalSummary,
            'canReviewInactiveApprovals' => $canReviewInactiveApprovals,
            'operationalCosts' => $operationalCosts,
            'expenseBreakdown' => $expenseBreakdown,
            'documentCosts' => $documentCosts,
            'equipmentCosts' => $equipmentCosts,
            'canViewFinancial' => $canFinancial,
        ]);
    }

    private function canReviewInactiveApprovals(): bool
    {
        if (function_exists('can')) {
            return can('inactive_approvals', 'review');
        }

        return function_exists('is_admin') && is_admin();
    }

    private function resolveFilters(array $periodOptions, array $vehicleCategoryOptions, array $vehicleOptions): array
    {
        $period = isset($_GET['period']) && is_string($_GET['period'])
            ? trim($_GET['period'])
            : 'luna_curenta';

        if (!array_key_exists($period, $periodOptions)) {
            $period = 'luna_curenta';
        }

        $vehicleCategory = isset($_GET['vehicle_category']) && is_string($_GET['vehicle_category'])
            ? trim($_GET['vehicle_category'])
            : 'toate';

        if (!array_key_exists($vehicleCategory, $vehicleCategoryOptions)) {
            $vehicleCategory = 'toate';
        }

        $vehicleId = null;
        if (isset($_GET['vehicle_id']) && is_scalar($_GET['vehicle_id'])) {
            $rawVehicleId = trim((string) $_GET['vehicle_id']);
            if ($rawVehicleId !== '' && ctype_digit($rawVehicleId)) {
                $vehicleId = (int) $rawVehicleId;
            }
        }

        $vehicleLabel = "Toate vehiculele";
        $validVehicleIds = [];

        foreach ($vehicleOptions as $vehicle) {
            $currentVehicleId = (int) ($vehicle['id'] ?? 0);
            $validVehicleIds[] = $currentVehicleId;

            if ($vehicleId !== null && $currentVehicleId === $vehicleId) {
                $vehicleLabel = (string) $vehicle['nr_inmatriculare'];
            }
        }

        if ($vehicleId !== null && !in_array($vehicleId, $validVehicleIds, true)) {
            $vehicleId = null;
            $vehicleLabel = "Toate vehiculele";
        }

        // Perioada personalizata (butonul "Perioadă" din filtre): ambele capete valide,
        // altfel se revine la luna curenta.
        $dateFrom = null;
        $dateTo = null;
        if ($period === 'personalizat') {
            $dateFrom = $this->parseDate(is_string($_GET['date_from'] ?? null) ? trim($_GET['date_from']) : '');
            $dateTo = $this->parseDate(is_string($_GET['date_to'] ?? null) ? trim($_GET['date_to']) : '');
            if ($dateFrom === null || $dateTo === null) {
                $period = 'luna_curenta';
                $dateFrom = $dateTo = null;
            } elseif ($dateFrom > $dateTo) {
                [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
            }
        }

        return [
            'period' => $period,
            'date_from' => $dateFrom?->format('Y-m-d'),
            'date_to' => $dateTo?->format('Y-m-d'),
            'period_label' => $periodOptions[$period],
            'vehicle_category' => $vehicleCategory,
            'vehicle_category_label' => $vehicleCategoryOptions[$vehicleCategory],
            'vehicle_id' => $vehicleId,
            'vehicle_label' => $vehicleLabel,
        ];
    }

    private function getPeriodOptions(): array
    {
        return [
            'luna_curenta' => "Luna curent\u{0103}",
            'ultimele_30_zile' => "Ultimele 30 de zile",
            'an_curent' => "Anul curent",
            'personalizat' => "Personalizat",
        ];
    }

    private function getVehicleCategoryOptions(): array
    {
        return [
            'toate' => 'Toate',
            'grele' => 'Vehicule grele',
            'usoare' => 'Vehicule ușoare',
        ];
    }

    private function formatPeriodRangeLabel(array $periodRange): string
    {
        $start = $this->parseDate((string) ($periodRange['date_start'] ?? ''));
        $end = $this->parseDate((string) ($periodRange['date_end'] ?? ''));

        if ($start === null || $end === null) {
            return '';
        }

        $months = [
            1 => 'ianuarie',
            2 => 'februarie',
            3 => 'martie',
            4 => 'aprilie',
            5 => 'mai',
            6 => 'iunie',
            7 => 'iulie',
            8 => 'august',
            9 => 'septembrie',
            10 => 'octombrie',
            11 => 'noiembrie',
            12 => 'decembrie',
        ];

        $startDay = (int) $start->format('j');
        $endDay = (int) $end->format('j');
        $startMonth = $months[(int) $start->format('n')] ?? $start->format('m');
        $endMonth = $months[(int) $end->format('n')] ?? $end->format('m');
        $startYear = $start->format('Y');
        $endYear = $end->format('Y');

        if ($start->format('Y-m-d') === $end->format('Y-m-d')) {
            return $startDay . ' ' . $startMonth . ' ' . $startYear;
        }

        if ($startYear === $endYear && $startMonth === $endMonth) {
            return $startDay . ' – ' . $endDay . ' ' . $endMonth . ' ' . $endYear;
        }

        if ($startYear === $endYear) {
            return $startDay . ' ' . $startMonth . ' – ' . $endDay . ' ' . $endMonth . ' ' . $endYear;
        }

        return $startDay . ' ' . $startMonth . ' ' . $startYear . ' – ' . $endDay . ' ' . $endMonth . ' ' . $endYear;
    }

    private function parseDate(string $date): ?DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $date ? $parsed : null;
    }
}
