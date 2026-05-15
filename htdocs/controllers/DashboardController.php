<?php
declare(strict_types=1);

class DashboardController
{
    private DashboardModel $dashboardModel;

    public function __construct(PDO $db)
    {
        $this->dashboardModel = new DashboardModel($db);
    }

    public function index(): void
    {
        $periodOptions = $this->getPeriodOptions();
        $vehicleOptions = $this->dashboardModel->getVehicleOptions();
        $filters = $this->resolveFilters($periodOptions, $vehicleOptions);

        $kpi = $this->dashboardModel->getKpi($filters);
        $expiringDocuments = $this->dashboardModel->getExpiringDocuments(8, $filters['vehicle_id']);
        $recentActivity = $this->dashboardModel->getRecentActivity(10, $filters);

        render('dashboard/index.php', [
            'pageTitle' => "Tablou de bord",
            'currentPage' => 'dashboard',
            'kpi' => $kpi,
            'expiringDocuments' => $expiringDocuments,
            'recentActivity' => $recentActivity,
            'dashboardFilters' => $filters,
            'periodOptions' => $periodOptions,
            'vehicleOptions' => $vehicleOptions,
        ]);
    }

    private function resolveFilters(array $periodOptions, array $vehicleOptions): array
    {
        $period = isset($_GET['period']) && is_string($_GET['period'])
            ? trim($_GET['period'])
            : 'luna_curenta';

        if (!array_key_exists($period, $periodOptions)) {
            $period = 'luna_curenta';
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
}
