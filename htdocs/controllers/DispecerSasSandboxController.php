<?php
declare(strict_types=1);

/**
 * SANDBOX - Precompletare cursa din GPS (SAS).
 *
 * Pagina de test izolata de formularul real Dispecer curse: aici se valideaza
 * ce ar precompleta GPS-ul (data incarcare, data/ora sfarsit, km) inainte de a
 * integra logica in formularul real. Nu scrie nimic in baza de date.
 *
 * Rute:
 *   ?page=dispecer_sandbox                 -> pagina sandbox
 *   ?page=dispecer_sandbox&action=prefill  -> JSON cu sugestiile pentru vehicul + data
 *   ?page=dispecer_sandbox&action=trips_range -> JSON cu cursele detectate pe o perioada + cursele reale
 */
class DispecerSasSandboxController
{
    private PDO $db;
    private SasTripPrefillService $service;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->service = new SasTripPrefillService($db);
    }

    public function handle(string $action): void
    {
        if (function_exists('can') && !can('dispecer_sandbox')) {
            http_response_code(403);
            render('errors/403.php', ['pageTitle' => 'Acces interzis']);
            return;
        }

        if ($action === 'prefill') {
            $this->prefill();
            return;
        }

        if ($action === 'trips_range') {
            $this->tripsRange();
            return;
        }

        $this->index();
    }

    private function index(): void
    {
        $vehicles = [];
        $pois = [];
        $loadError = null;
        if ($this->service->credentialsAvailable()) {
            try {
                $vehicles = $this->service->getVehicleOptions();
                $pois = $this->service->getPoiOptions();
            } catch (Throwable $exception) {
                error_log('[DispecerSasSandboxController][vehicles] ' . $exception->getMessage());
                $loadError = 'Lista vehiculelor nu a putut fi incarcata din SAS: ' . $exception->getMessage();
            }
        }

        render('dispecer_sandbox/index.php', [
            'pageTitle' => 'Sandbox GPS curse',
            'currentPage' => 'dispecer_sandbox',
            'credentialsAvailable' => $this->service->credentialsAvailable(),
            'vehicles' => $vehicles,
            'pois' => $pois,
            'deliveryParkDefaultMinutes' => SasTripPrefillService::DELIVERY_PARK_DEFAULT_MINUTES,
            'loadError' => $loadError,
        ]);
    }

    private function prefill(): void
    {
        $vehicleId = (int) ($_GET['vehicle_id'] ?? 0);
        $date = trim((string) ($_GET['date'] ?? ''));
        $time = trim((string) ($_GET['time'] ?? ''));

        if ($vehicleId <= 0 || $date === '') {
            http_response_code(400);
            $this->sendJson(['error' => 'Parametri lipsa: vehicle_id si date (Y-m-d).']);
            return;
        }

        [$parkingPoiIds, $parkMinutes] = $this->closingRuleParams();

        try {
            $result = $this->service->prefill($vehicleId, $date, $time !== '' ? $time : null, $parkingPoiIds, $parkMinutes);
            $this->sendJson($result);
        } catch (InvalidArgumentException $exception) {
            http_response_code(400);
            $this->sendJson(['error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            error_log('[DispecerSasSandboxController][prefill] ' . $exception->getMessage());
            http_response_code(502);
            $this->sendJson(['error' => 'Interogarea SAS a esuat: ' . $exception->getMessage()]);
        }
    }

    /** Cursele detectate din GPS pe o perioada, inlantuite, alaturi de cursele reale. */
    private function tripsRange(): void
    {
        $vehicleId = (int) ($_GET['vehicle_id'] ?? 0);
        $from = trim((string) ($_GET['from'] ?? ''));
        $to = trim((string) ($_GET['to'] ?? ''));

        if ($vehicleId <= 0 || $from === '' || $to === '') {
            http_response_code(400);
            $this->sendJson(['error' => 'Parametri lipsa: vehicle_id, from si to (Y-m-d).']);
            return;
        }

        [$parkingPoiIds, $parkMinutes] = $this->closingRuleParams();

        try {
            $this->sendJson($this->service->detectTripsInRange($vehicleId, $from, $to, $parkingPoiIds, $parkMinutes));
        } catch (InvalidArgumentException $exception) {
            http_response_code(400);
            $this->sendJson(['error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            error_log('[DispecerSasSandboxController][trips_range] ' . $exception->getMessage());
            http_response_code(502);
            $this->sendJson(['error' => 'Interogarea SAS a esuat: ' . $exception->getMessage()]);
        }
    }

    /**
     * Regulile de inchidere trimise din pagina.
     * parking_poi_ids lipsa = parcarile implicite (dupa nume); prezent dar gol = niciuna.
     *
     * @return array{0: ?int[], 1: ?int}
     */
    private function closingRuleParams(): array
    {
        $parkingPoiIds = null;
        if (isset($_GET['parking_poi_ids'])) {
            $parkingPoiIds = array_values(array_filter(
                array_map('intval', explode(',', (string) $_GET['parking_poi_ids'])),
                static fn (int $id) => $id > 0
            ));
        }
        $parkMinutes = isset($_GET['park_minutes']) && is_numeric($_GET['park_minutes'])
            ? (int) $_GET['park_minutes']
            : null;

        return [$parkingPoiIds, $parkMinutes];
    }

    private function sendJson(array $payload): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
