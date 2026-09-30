<?php
declare(strict_types=1);

/**
 * "Km pierduti" — raport de km rulati de flota dar neacoperiti de curse.
 *
 * Idee: masina consuma anvelope, motorina si uzura chiar si cand ruleaza fara o
 * cursa inregistrata. Km pierduti = km reali GPS (SAS travelsheet) minus km
 * efectuati pe curse (km_totali cand e completat, altfel km_cursa) pentru
 * acelasi vehicul si interval; km_cursa (agreati/facturati) apare separat. Diferenta pozitiva = exploatare fara venit.
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
    private static ?bool $segmentsTableExists = null;

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
        // Drumurile la service justifica km GPS rulati fara cursa (nu sunt km pierduti).
        $serviceKm = (new VehicleServiceKmModel($this->db))->kmByVehicleId($start, $end);

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
                // Cursele numarate aici: coloana "Curse" le deschide in Desfasurator curse.
                'curse_ids' => $reg !== null ? $reg['ids'] : '',
                'km_cursa' => $reg !== null ? (float) $reg['km_cursa'] : 0.0,
                'km_totali' => $reg !== null ? (float) $reg['km_totali'] : 0.0,
                'km_efectuati' => $reg !== null ? (float) $reg['km_efectuati'] : 0.0,
                'km_service' => $localId > 0 ? (float) ($serviceKm[$localId]['km'] ?? 0) : 0.0,
                'zile_service' => $localId > 0 ? (int) ($serviceKm[$localId]['zile'] ?? 0) : 0,
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
     * @return array<int, array{nr: int, ids: string, km_cursa: float, km_totali: float, km_efectuati: float}>
     */
    private function registeredKmByVehicleId(string $start, string $end): array
    {
        // Cursele oprite si reluate raman o singura cursa, dar km-ii lor se impart
        // intre vehiculele segmentelor (proportional cu km-ii fiecarui segment),
        // ca sa se compare cu km-ii GPS ai vehiculului potrivit. Cursa se numara
        // o singura data, pe primul segment.
        // Cursa intra in perioada dupa data la care s-a INCHIS (ca in Dashboard
        // Analitic V2): una inceputa pe 31 iulie si incheiata pe 3 august e in august.
        //
        // km_efectuati = km rulati efectiv pe cursa (ca "km realizati" din Dashboard):
        // km_totali cand e completat (la Primar km = "Km efectuati"), altfel km_cursa
        // (la Distributie = "Km efectuati"); la compresor km_dislocare. km_cursa ramane
        // separat ca "km agreati / facturati".
        $effective = static fn (string $p): string => "CASE
                WHEN {$p}tip_transport = 'compresor' AND COALESCE({$p}km_dislocare, 0) > 0 THEN {$p}km_dislocare
                WHEN COALESCE({$p}km_totali, 0) > 0 THEN {$p}km_totali
                ELSE COALESCE({$p}km_cursa, 0)
            END";
        $hasSegments = $this->segmentsTableExists();
        $sql = $hasSegments
            ? "SELECT COALESCE(s.vehicle_id, c.vehicle_id) AS vehicle_id,
                      SUM(CASE WHEN seg.cursa_id IS NULL OR s.ordine = 1 THEN 1 ELSE 0 END) AS nr,
                      GROUP_CONCAT(DISTINCT c.id ORDER BY c.id) AS ids,
                      COALESCE(SUM(COALESCE(c.km_cursa, 0) * COALESCE(COALESCE(s.km, 0) / seg.km_total, 1)), 0) AS km_cursa,
                      COALESCE(SUM(COALESCE(c.km_totali, 0) * COALESCE(COALESCE(s.km, 0) / seg.km_total, 1)), 0) AS km_totali,
                      COALESCE(SUM((" . $effective('c.') . ") * COALESCE(COALESCE(s.km, 0) / seg.km_total, 1)), 0) AS km_efectuati
               FROM curse_dispecer c
               LEFT JOIN (
                     SELECT cursa_id, SUM(COALESCE(km, 0)) AS km_total
                       FROM curse_segmente
                      GROUP BY cursa_id
                     HAVING COUNT(*) >= 2 AND SUM(COALESCE(km, 0)) > 0
               ) seg ON seg.cursa_id = c.id
               LEFT JOIN curse_segmente s ON s.cursa_id = seg.cursa_id
               WHERE c.deleted_at IS NULL
                 AND COALESCE(c.data_sfarsit, c.data_inceput, c.data_cursa) BETWEEN :start AND :end
               GROUP BY COALESCE(s.vehicle_id, c.vehicle_id)"
            : "SELECT vehicle_id,
                      COUNT(*) AS nr,
                      GROUP_CONCAT(id ORDER BY id) AS ids,
                      COALESCE(SUM(km_cursa), 0) AS km_cursa,
                      COALESCE(SUM(km_totali), 0) AS km_totali,
                      COALESCE(SUM(" . $effective('') . "), 0) AS km_efectuati
               FROM curse_dispecer
               WHERE deleted_at IS NULL
                 AND COALESCE(data_sfarsit, data_inceput, data_cursa) BETWEEN :start AND :end
               GROUP BY vehicle_id";

        // Lista de id-uri (link spre Desfasurator) poate depasi limita implicita de 1024 caractere.
        $this->db->exec('SET SESSION group_concat_max_len = 65535');
        $statement = $this->db->prepare($sql);
        $statement->execute([':start' => $start, ':end' => $end]);

        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['vehicle_id']] = [
                'nr' => (int) $row['nr'],
                'ids' => (string) ($row['ids'] ?? ''),
                'km_cursa' => (float) $row['km_cursa'],
                'km_totali' => (float) $row['km_totali'],
                'km_efectuati' => (float) $row['km_efectuati'],
            ];
        }

        return $result;
    }

    /** Segmentele exista doar dupa migrarea 2026_09_18_000002. */
    private function segmentsTableExists(): bool
    {
        if (self::$segmentsTableExists !== null) {
            return self::$segmentsTableExists;
        }

        try {
            $statement = $this->db->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "curse_segmente"'
            );
            $statement->execute();
            self::$segmentsTableExists = ((int) $statement->fetchColumn()) > 0;
        } catch (Throwable $exception) {
            self::$segmentsTableExists = false;
        }

        return self::$segmentsTableExists;
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
