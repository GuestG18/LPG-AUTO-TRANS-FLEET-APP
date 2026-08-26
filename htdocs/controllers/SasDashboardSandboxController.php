<?php
declare(strict_types=1);

/**
 * SANDBOX - Dashboard live flota (SAS).
 *
 * Pagina experimentala pentru designul viitorului dashboard dinamic: KPI-uri,
 * feed de evenimente de miscare si sumarul zilei per vehicul. Doar citire,
 * nu scrie nimic in baza de date.
 *
 * Rute:
 *   ?page=sas_dashboard_sandbox                                    -> pagina dashboard
 *   ?page=sas_dashboard_sandbox&action=data                        -> JSON snapshot (polling ~30s)
 *   ?page=sas_dashboard_sandbox&action=vehicle&car_id=N[&start=Y-m-d&end=Y-m-d]
 *                                                                  -> JSON sumar vehicul pe interval (implicit azi)
 *   ?page=sas_dashboard_sandbox&action=route&car_id=N[&start=&end=] -> JSON traseu GPS pentru harta (max 7 zile)
 */
class SasDashboardSandboxController
{
    private SasDashboardService $service;

    public function __construct(PDO $db)
    {
        $this->service = new SasDashboardService($db);
    }

    public function handle(string $action): void
    {
        if (function_exists('can') && !can('sas_dashboard_sandbox')) {
            http_response_code(403);
            render('errors/403.php', ['pageTitle' => 'Acces interzis']);
            return;
        }

        if ($action === 'data') {
            $this->data();
            return;
        }
        if ($action === 'vehicle') {
            $this->vehicle();
            return;
        }
        if ($action === 'route') {
            $this->route();
            return;
        }

        $this->index();
    }

    private function index(): void
    {
        render('sas_dashboard_sandbox/index.php', [
            'pageTitle' => 'Sandbox Dashboard Flota',
            'currentPage' => 'sas_dashboard_sandbox',
            'credentialsAvailable' => $this->service->credentialsAvailable(),
        ]);
    }

    private function data(): void
    {
        try {
            $snapshot = $this->service->getSnapshot();
            if ($snapshot['meta']['error'] !== null && $snapshot['vehicles'] === []) {
                http_response_code(502);
            }
            $this->sendJson($snapshot);
        } catch (Throwable $exception) {
            error_log('[SasDashboardSandboxController][data] ' . $exception->getMessage());
            http_response_code(502);
            $this->sendJson(['error' => 'Interogarea SAS a esuat: ' . $exception->getMessage()]);
        }
    }

    private function vehicle(): void
    {
        $carId = (int) ($_GET['car_id'] ?? 0);
        if ($carId <= 0) {
            http_response_code(400);
            $this->sendJson(['error' => 'Parametru lipsa: car_id.']);
            return;
        }

        try {
            $this->sendJson($this->service->getVehicleRange(
                $carId,
                isset($_GET['start']) ? (string) $_GET['start'] : null,
                isset($_GET['end']) ? (string) $_GET['end'] : null
            ));
        } catch (InvalidArgumentException $exception) {
            http_response_code(400);
            $this->sendJson(['error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            error_log('[SasDashboardSandboxController][vehicle] ' . $exception->getMessage());
            http_response_code(502);
            $this->sendJson(['error' => 'Sumarul nu a putut fi incarcat din SAS: ' . $exception->getMessage()]);
        }
    }

    private function route(): void
    {
        $carId = (int) ($_GET['car_id'] ?? 0);
        if ($carId <= 0) {
            http_response_code(400);
            $this->sendJson(['error' => 'Parametru lipsa: car_id.']);
            return;
        }

        try {
            $this->sendJson($this->service->getRoute(
                $carId,
                isset($_GET['start']) ? (string) $_GET['start'] : null,
                isset($_GET['end']) ? (string) $_GET['end'] : null
            ));
        } catch (InvalidArgumentException $exception) {
            http_response_code(400);
            $this->sendJson(['error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            error_log('[SasDashboardSandboxController][route] ' . $exception->getMessage());
            http_response_code(502);
            $this->sendJson(['error' => 'Traseul nu a putut fi incarcat din SAS: ' . $exception->getMessage()]);
        }
    }

    private function sendJson(array $payload): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
