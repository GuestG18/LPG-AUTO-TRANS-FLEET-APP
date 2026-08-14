<?php
declare(strict_types=1);

/**
 * Pagina "Harta Flota" - pozitiile live ale vehiculelor pe OpenStreetMap.
 *
 * Rute:
 *   ?page=harta_flota              -> pagina cu harta (Leaflet + OSM)
 *   ?page=harta_flota&action=data  -> JSON cu pozitiile normalizate (polling la ~30s)
 */
class FleetMapController
{
    private PDO $db;
    private FleetLivePositionService $service;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->service = new FleetLivePositionService($db);
    }

    public function handle(string $action): void
    {
        if ($action === 'data') {
            $this->data();
            return;
        }
        if ($action === 'hierarchy') {
            $this->hierarchy();
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
        render('harta_flota/index.php', [
            'pageTitle' => 'Harta Flota',
            'currentPage' => 'harta_flota',
            'credentialsAvailable' => $this->service->credentialsAvailable(),
        ]);
    }

    private function hierarchy(): void
    {
        try {
            $this->sendJson($this->service->getFleetHierarchy());
        } catch (Throwable $exception) {
            error_log('[FleetMapController][hierarchy] ' . $exception->getMessage());
            http_response_code(502);
            $this->sendJson(['error' => 'Structura flotei nu a putut fi incarcata din SAS.']);
        }
    }

    private function route(): void
    {
        $carId = (int) ($_GET['car_id'] ?? 0);
        $date = trim((string) ($_GET['date'] ?? ''));

        if ($carId <= 0 || $date === '') {
            http_response_code(400);
            $this->sendJson(['error' => 'Parametri lipsa: car_id si date (Y-m-d).']);
            return;
        }

        try {
            $this->sendJson($this->service->getRouteForDay($carId, $date));
        } catch (InvalidArgumentException $exception) {
            http_response_code(400);
            $this->sendJson(['error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            error_log('[FleetMapController][route] ' . $exception->getMessage());
            http_response_code(502);
            $this->sendJson(['error' => 'Traseul nu a putut fi incarcat din SAS.']);
        }
    }

    private function sendJson(array $payload): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function data(): void
    {
        $payload = $this->service->getLivePositions();
        $payload['meta'] = [
            'generated_at' => date('c'),
            'refresh_seconds' => 30,
        ];

        if ($payload['error'] !== null && $payload['positions'] === []) {
            http_response_code(502);
        }

        $this->sendJson($payload);
    }
}
