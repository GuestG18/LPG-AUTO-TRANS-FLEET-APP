<?php
declare(strict_types=1);

/**
 * Pagina "Harta Flota" - pozitiile live ale vehiculelor pe OpenStreetMap.
 *
 * Rute:
 *   ?page=harta_flota              -> pagina cu harta (Leaflet + OSM)
 *   ?page=harta_flota&action=data  -> JSON cu pozitiile normalizate (polling la ~30s)
 *   ?page=harta_flota&action=selection (POST) -> salveaza vehiculele ascunse de utilizator
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
        if ($action === 'selection' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $this->saveSelection();
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
            'hiddenCarIds' => self::hiddenCarIdsForCurrentUser($this->db),
        ]);
    }

    /**
     * Vehiculele SAS scoase de utilizatorul curent din formularul Harta Flota.
     * Folosit si de banda "Curse in desfasurare" din Dispecer curse.
     *
     * @return list<int>
     */
    public static function hiddenCarIdsForCurrentUser(PDO $db): array
    {
        try {
            $stmt = $db->prepare('SELECT harta_flota_ascunse FROM utilizatori WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => (int) (current_user()['id'] ?? 0)]);
            $decoded = json_decode((string) $stmt->fetchColumn(), true);
        } catch (Throwable $exception) {
            error_log('[FleetMapController][selection] ' . $exception->getMessage());
            return [];
        }

        return is_array($decoded) ? self::normalizeCarIds($decoded) : [];
    }

    /**
     * Body JSON: {csrf, hidden: [sas_vehicle_id...]}; lista goala = toate vizibile.
     */
    private function saveSelection(): void
    {
        $body = json_decode((string) file_get_contents('php://input'), true);
        $body = is_array($body) ? $body : [];
        if (!verify_csrf_token((string) ($body['csrf'] ?? ''))) {
            http_response_code(403);
            $this->sendJson(['ok' => false, 'error' => 'Token CSRF invalid. Reincarca pagina.']);
        }

        $hidden = self::normalizeCarIds(is_array($body['hidden'] ?? null) ? $body['hidden'] : []);
        try {
            $stmt = $this->db->prepare('UPDATE utilizatori SET harta_flota_ascunse = :v WHERE id = :id');
            $stmt->execute([
                ':v' => $hidden === [] ? null : json_encode($hidden),
                ':id' => (int) current_user()['id'],
            ]);
        } catch (Throwable $exception) {
            error_log('[FleetMapController][selection] ' . $exception->getMessage());
            http_response_code(500);
            $this->sendJson(['ok' => false, 'error' => 'Selectia nu a putut fi salvata.']);
        }

        $this->sendJson(['ok' => true, 'hidden' => $hidden]);
    }

    /** @return list<int> */
    private static function normalizeCarIds(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            $id = (int) $value;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        sort($ids);

        return array_values($ids);
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
