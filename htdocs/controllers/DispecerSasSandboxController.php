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

        $this->index();
    }

    private function index(): void
    {
        $vehicles = [];
        $loadError = null;
        if ($this->service->credentialsAvailable()) {
            try {
                $vehicles = $this->service->getVehicleOptions();
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

        try {
            $result = $this->service->prefill($vehicleId, $date, $time !== '' ? $time : null);
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

    private function sendJson(array $payload): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
