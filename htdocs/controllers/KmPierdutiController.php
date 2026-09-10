<?php
declare(strict_types=1);

/**
 * "Km pierduti" — raport de km rulati de flota dar neacoperiti de curse.
 *
 * Idee: masina consuma anvelope, motorina si uzura chiar si cand ruleaza fara o
 * cursa inregistrata. Km pierduti = km reali GPS (SAS travelsheet) minus km
 * acoperiti de curse (km_cursa = ruta facturata, km_totali = odometru cursa)
 * pentru acelasi vehicul si interval. Diferenta pozitiva = exploatare fara venit.
 *
 * Rute:
 *   ?page=km_pierduti                                   -> pagina raport (schelet + km inregistrati)
 *   ?page=km_pierduti&action=gps&car_id=N&start=&end=   -> JSON: km GPS pentru un vehicul (incarcare esalonata)
 *
 * Doar citire: nu scrie nimic in baza de date.
 */
class KmPierdutiController
{
    private PDO $db;
    private SasDashboardService $service;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->service = new SasDashboardService($db);
    }

    public function handle(string $action): void
    {
        if (function_exists('can') && !can('km_pierduti')) {
            http_response_code(403);
            render('errors/403.php', ['pageTitle' => 'Acces interzis']);
            return;
        }

        if ($action === 'gps') {
            $this->gpsAction();
            return;
        }

        $this->index();
    }

    private function index(): void
    {
        [$start, $end] = $this->resolvePeriod();

        $vehicles = [];
        $loadError = null;
        if ($this->service->credentialsAvailable()) {
            try {
                $vehicles = $this->buildVehicleRows($start, $end);
            } catch (Throwable $exception) {
                error_log('[KmPierdutiController][index] ' . $exception->getMessage());
                $loadError = 'Structura flotei nu a putut fi incarcata din SAS: ' . $exception->getMessage();
            }
        }

        render('km_pierduti/index.php', [
            'pageTitle' => 'Km pierduti',
            'currentPage' => 'km_pierduti',
            'credentialsAvailable' => $this->service->credentialsAvailable(),
            'vehicles' => $vehicles,
            'periodStart' => $start,
            'periodEnd' => $end,
            'loadError' => $loadError,
        ]);
    }

    /**
     * Randurile raportului: fiecare vehicul SAS activ, cu km inregistrati (din
     * curse_dispecer) deja completati; km GPS raman de incarcat esalonat din
     * frontend. Vehiculele fara carId SAS apar fara GPS (nu sunt urmarite).
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildVehicleRows(string $start, string $end): array
    {
        $registered = $this->registeredKmByVehicleId($start, $end);

        $rows = [];
        foreach ($this->service->getFleetVehicles() as $car) {
            if (!empty($car['disabled'])) {
                continue;
            }
            $localId = (int) ($car['local_vehicle_id'] ?? 0);
            $reg = $localId > 0 ? ($registered[$localId] ?? null) : null;

            $rows[] = [
                'sas_vehicle_id' => (int) ($car['sas_vehicle_id'] ?? 0),
                'plate' => (string) ($car['registration'] ?? ''),
                'label' => $car['local_label'] ?? null,
                'nr_curse' => $reg !== null ? (int) $reg['nr'] : 0,
                'km_cursa' => $reg !== null ? (float) $reg['km_cursa'] : 0.0,
                'km_totali' => $reg !== null ? (float) $reg['km_totali'] : 0.0,
            ];
        }

        // Ordonare implicita: dupa numarul de curse (cele active primele), apoi alfabetic.
        usort($rows, static function (array $a, array $b): int {
            $byRuns = ($b['nr_curse'] <=> $a['nr_curse']);
            return $byRuns !== 0 ? $byRuns : strcmp($a['plate'], $b['plate']);
        });

        return $rows;
    }

    /**
     * @return array<int, array{nr: int, km_cursa: float, km_totali: float}>
     */
    private function registeredKmByVehicleId(string $start, string $end): array
    {
        $statement = $this->db->prepare(
            "SELECT vehicle_id,
                    COUNT(*) AS nr,
                    COALESCE(SUM(km_cursa), 0) AS km_cursa,
                    COALESCE(SUM(km_totali), 0) AS km_totali
             FROM curse_dispecer
             WHERE deleted_at IS NULL
               AND data_inceput BETWEEN :start AND :end
             GROUP BY vehicle_id"
        );
        $statement->execute([':start' => $start, ':end' => $end]);

        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['vehicle_id']] = [
                'nr' => (int) $row['nr'],
                'km_cursa' => (float) $row['km_cursa'],
                'km_totali' => (float) $row['km_totali'],
            ];
        }

        return $result;
    }

    private function gpsAction(): void
    {
        $carId = (int) ($_GET['car_id'] ?? 0);
        if ($carId <= 0) {
            http_response_code(400);
            $this->sendJson(['error' => 'Parametru lipsa: car_id.']);
            return;
        }

        [$start, $end] = $this->resolvePeriod();

        try {
            $this->sendJson($this->service->getVehicleKm($carId, $start, $end));
        } catch (InvalidArgumentException $exception) {
            http_response_code(400);
            $this->sendJson(['error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            error_log('[KmPierdutiController][gps] ' . $exception->getMessage());
            http_response_code(502);
            $this->sendJson(['error' => 'Km GPS nu au putut fi incarcati din SAS.']);
        }
    }

    /**
     * Intervalul raportului (implicit: luna curenta pana azi). Validat Y-m-d,
     * ordonat crescator, maxim 92 de zile.
     *
     * @return array{0: string, 1: string}
     */
    private function resolvePeriod(): array
    {
        $start = trim((string) ($_GET['start'] ?? ''));
        $end = trim((string) ($_GET['end'] ?? ''));

        $startObj = DateTimeImmutable::createFromFormat('!Y-m-d', $start);
        $endObj = DateTimeImmutable::createFromFormat('!Y-m-d', $end);

        if (!$startObj instanceof DateTimeImmutable || $startObj->format('Y-m-d') !== $start) {
            $startObj = new DateTimeImmutable('first day of this month');
        }
        if (!$endObj instanceof DateTimeImmutable || $endObj->format('Y-m-d') !== $end) {
            $endObj = new DateTimeImmutable('today');
        }
        if ($startObj > $endObj) {
            [$startObj, $endObj] = [$endObj, $startObj];
        }
        if ((int) $startObj->diff($endObj)->days > 92) {
            $startObj = $endObj->modify('-92 days');
        }

        return [$startObj->format('Y-m-d'), $endObj->format('Y-m-d')];
    }

    private function sendJson(array $payload): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
