<?php
declare(strict_types=1);

class DashboardController
{
    private DashboardModel $dashboardModel;
    private InactiveResourceApprovalModel $approvalModel;

    public function __construct(PDO $db)
    {
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

        $canReviewInactiveApprovals = $this->canReviewInactiveApprovals();
        $approvalSummary = [
            'counts' => ['vehicle' => 0, 'driver' => 0],
            'total' => 0,
            'vehicles' => [],
            'drivers' => [],
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

        return [
            'period' => $period,
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

        return $parsed instanceof DateTimeImmutable ? $parsed : null;
    }
}
