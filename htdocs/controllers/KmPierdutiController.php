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
 *   ?page=km_pierduti&action=detail&car_id=N&vehicle_id=M&start=&end=
 *                                                       -> JSON: de unde vin km pierduti ai unui vehicul
 *                                                          (deplasari GPS puse pe ferestrele curselor / service)
 *
 * Doar citire: nu scrie nimic in baza de date.
 */
class KmPierdutiController
{
    private PDO $db;
    private SasDashboardService $service;
    private static ?bool $segmentsTableExists = null;
    /** @var array<int, array<int, array<string, mixed>>>|null ferestrele curselor deschise, pe vehicul (memo pe cerere) */
    private ?array $openWindows = null;

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

        // Actiunile JSON doar citesc sesiunea: o eliberam inainte de apelurile SAS, ca cererile
        // paralele ale cardului (gps x3, detail) si restul paginii sa nu astepte lacatul sesiunii.
        if (in_array($action, ['gps', 'detail', 'gps_cached', 'vehicles', 'open_trip'], true) && session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        if ($action === 'gps') {
            $this->gpsAction();
            return;
        }
        if ($action === 'detail') {
            $this->detailAction();
            return;
        }
        if ($action === 'gps_cached') {
            $this->gpsCachedAction();
            return;
        }
        if ($action === 'vehicles') {
            $this->vehiclesAction();
            return;
        }
        if ($action === 'open_trip') {
            $this->openTripAction();
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
     * JSON cu randurile raportului (fara km GPS), pentru cardul "Km pierduti" din Dispecer curse:
     * cardul incarca apoi km GPS per vehicul prin action=gps, ca pagina.
     */
    private function vehiclesAction(): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        [$start, $end] = $this->resolvePeriod();
        if (!$this->service->credentialsAvailable()) {
            echo json_encode(['success' => false, 'error' => 'Credențialele SAS nu sunt configurate.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        try {
            // Ca banda "Curse in desfasurare": vehiculele scoase din formularul Harta Flota nu apar in card.
            $hidden = array_flip(FleetMapController::hiddenCarIdsForCurrentUser($this->db));
            $rows = array_values(array_filter(
                $this->buildVehicleRows($start, $end),
                static fn (array $row): bool => !isset($hidden[(int) $row['sas_vehicle_id']])
            ));
            // Garaj + categorie capacitate (ca in Management capacitati), pentru filtrele din card.
            $capacityMeta = (new VehicleCapacityCategoryModel($this->db))->getCapacityMetaByVehicleId();
            foreach ($rows as &$row) {
                $row += $capacityMeta[(int) $row['vehicle_id']] ?? ['garaj' => null, 'garaje' => [], 'combinatie' => null, 'categorie_id' => null, 'categorie' => null, 'categorie_ordine' => null, 'capacitate' => null];
            }
            unset($row);
            echo json_encode([
                'success' => true,
                'start' => $start,
                'end' => $end,
                'vehicles' => $rows,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $exception) {
            error_log('[KmPierdutiController][vehicles] ' . $exception->getMessage());
            echo json_encode(['success' => false, 'error' => 'Structura flotei nu a putut fi încărcată din SAS.'], JSON_UNESCAPED_UNICODE);
        }
        exit;
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

        $periodFrom = (int) strtotime($start . ' 00:00:00');
        $openWindows = $this->openTripWindowsByVehicle();

        $rows = [];
        foreach ($this->service->getFleetVehicles() as $car) {
            if (!empty($car['disabled'])) {
                continue;
            }
            $localId = (int) ($car['local_vehicle_id'] ?? 0);
            $reg = $localId > 0 ? ($registered[$localId] ?? null) : null;

            $rows[] = [
                'sas_vehicle_id' => (int) ($car['sas_vehicle_id'] ?? 0),
                'vehicle_id' => $localId,
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
                // Cursele deschise care ating perioada (km GPS rulati in ele vin cu action=gps, in open_km).
                'curse_deschise' => $localId > 0 ? array_values(array_map(
                    static fn (array $w): array => [
                        'id' => $w['id'],
                        'beneficiar' => $w['beneficiar'],
                        'tip' => $w['tip'],
                        'from' => date('Y-m-d H:i', $w['from_ts']),
                        'to' => date('Y-m-d H:i', $w['to_ts']),
                        // false = vehiculul a inceput intre timp o alta cursa / un service: fereastra s-a oprit acolo.
                        'ongoing' => $w['ongoing'],
                    ],
                    array_filter($openWindows[$localId] ?? [], static fn (array $w): bool => $w['to_ts'] >= $periodFrom)
                )) : [],
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
        // Cursele inca deschise (fara ora de sfarsit) nu au km efectuati definitivi: km-ii lor nu
        // acopera nimic aici; km GPS rulati in ele apar separat, ca "Km in desfasurare" (open_km).
        $closed = static fn (string $p): string => "(CASE WHEN {$p}ora_sfarsit IS NULL THEN 0 ELSE 1 END)";
        $hasSegments = $this->segmentsTableExists();
        $sql = $hasSegments
            ? "SELECT COALESCE(s.vehicle_id, c.vehicle_id) AS vehicle_id,
                      SUM(CASE WHEN seg.cursa_id IS NULL OR s.ordine = 1 THEN 1 ELSE 0 END) AS nr,
                      GROUP_CONCAT(DISTINCT c.id ORDER BY c.id) AS ids,
                      COALESCE(SUM(COALESCE(c.km_cursa, 0) * COALESCE(COALESCE(s.km, 0) / seg.km_total, 1) * " . $closed('c.') . "), 0) AS km_cursa,
                      COALESCE(SUM(COALESCE(c.km_totali, 0) * COALESCE(COALESCE(s.km, 0) / seg.km_total, 1) * " . $closed('c.') . "), 0) AS km_totali,
                      COALESCE(SUM((" . $effective('c.') . ") * COALESCE(COALESCE(s.km, 0) / seg.km_total, 1) * " . $closed('c.') . "), 0) AS km_efectuati
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
                      COALESCE(SUM(COALESCE(km_cursa, 0) * " . $closed('') . "), 0) AS km_cursa,
                      COALESCE(SUM(COALESCE(km_totali, 0) * " . $closed('') . "), 0) AS km_totali,
                      COALESCE(SUM((" . $effective('') . ") * " . $closed('') . "), 0) AS km_efectuati
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

    /**
     * De unde vin km pierduti ai unui vehicul: fiecare deplasare GPS (segment din
     * travelsheet-ul SAS, acelasi cache ca la coloana Km GPS) se pune pe
     * ferestrele de timp ale curselor si ale drumurilor la service din Dispecer.
     *
     *  - km GPS in afara oricarei ferestre = rulati fara cursa, grupati pe golurile
     *    dintre curse (de cand pana cand, intre ce curse, pe unde);
     *  - km GPS in fereastra unei curse peste km declarati pe ea = cursa rulata
     *    mai mult decat s-a trecut in Dispecer;
     *  - restul pana la cifra din tabel = marginea perioadei: curse incepute
     *    inainte de ea (km declarati si pentru zilele fara GPS aici) sau inchise
     *    dupa ea (rulate in perioada, dar numarate in urmatoarea).
     *
     * Ferestrele se iau exact cum sunt trecute (ora lipsa = toata ziua).
     */
    private function detailAction(): void
    {
        $carId = (int) ($_GET['car_id'] ?? 0);
        $vehicleId = (int) ($_GET['vehicle_id'] ?? 0);
        if ($carId <= 0) {
            http_response_code(400);
            $this->sendJson(['error' => 'Parametru lipsa: car_id.']);
            return;
        }

        [$start, $end] = $this->resolvePeriod();

        try {
            $range = (array) $this->service->getVehicleRangeIncremental($carId, $start, $end);
        } catch (InvalidArgumentException $exception) {
            http_response_code(400);
            $this->sendJson(['error' => $exception->getMessage()]);
            return;
        } catch (Throwable $exception) {
            error_log('[KmPierdutiController][detail] ' . $exception->getMessage());
            http_response_code(502);
            $this->sendJson(['error' => 'Deplasarile GPS nu au putut fi incarcate din SAS.']);
            return;
        }

        $periodFrom = (int) strtotime($start . ' 00:00:00');
        $periodTo = min((int) strtotime($end . ' 23:59:59'), time());

        $trips = $vehicleId > 0 ? $this->tripWindows($vehicleId, $start, $end) : [];
        $services = $vehicleId > 0 ? $this->serviceWindows($vehicleId, $start, $end) : [];
        // Cursele au prioritate cand se suprapun cu un drum la service.
        $windows = array_merge($trips, $services);

        $gaps = $this->gapsBetween($windows, $periodFrom, $periodTo);

        foreach ((array) ($range['segments'] ?? []) as $segment) {
            $km = (float) ($segment['distance_km'] ?? 0);
            $segStart = strtotime((string) ($segment['start_time'] ?? ''));
            $segEnd = strtotime((string) ($segment['end_time'] ?? ''));
            if ($km <= 0 || $segStart === false) {
                continue;
            }
            if ($segEnd === false || $segEnd < $segStart) {
                $segEnd = $segStart;
            }

            foreach ($this->splitSegment($segStart, $segEnd, $km, $windows) as [$windowIndex, $partKm, $partFrom, $partTo]) {
                if ($windowIndex !== null) {
                    $windows[$windowIndex]['gps_km'] += $partKm;
                    $windows[$windowIndex]['gps_segments']++;
                    continue;
                }
                $mid = intdiv($partFrom + $partTo, 2);
                foreach ($gaps as $gapIndex => $gap) {
                    if ($mid >= $gap['from_ts'] && $mid <= $gap['to_ts']) {
                        $gaps[$gapIndex]['km'] += $partKm;
                        $gaps[$gapIndex]['segments'][] = [
                            'start' => date('Y-m-d H:i', $partFrom),
                            'end' => date('Y-m-d H:i', $partTo),
                            'from' => $segment['from'] ?? null,
                            'to' => $segment['to'] ?? null,
                            'km' => round($partKm, 1),
                            'partial' => $partKm < $km - 0.05,
                        ];
                        continue 2;
                    }
                }
                // Deplasare in afara perioadei (ex. dupa "acum") - nu are gol in care sa intre.
            }
        }

        $windowLabel = static function (?array $window): ?string {
            if ($window === null) {
                return null;
            }
            return $window['kind'] === 'trip'
                ? 'cursa #' . $window['id'] . ($window['beneficiar'] ? ' (' . $window['beneficiar'] . ')' : '')
                : 'service' . ($window['service_nume'] ? ' ' . $window['service_nume'] : '');
        };

        $gapRows = [];
        $outsideKm = 0.0;
        foreach ($gaps as $gap) {
            if ($gap['km'] < 0.05) {
                continue;
            }
            $outsideKm += $gap['km'];
            $gapRows[] = [
                // Rotunjit in sus la minut: o cursa fara ora se termina la 23:59:59, iar alocarea golului nu are voie sa o atinga.
                'from' => date('Y-m-d H:i', (int) (ceil($gap['from_ts'] / 60) * 60)),
                'to' => date('Y-m-d H:i', $gap['to_ts']),
                'after' => $windowLabel($gap['after'] !== null ? $windows[$gap['after']] : null),
                'before' => $windowLabel($gap['before'] !== null ? $windows[$gap['before']] : null),
                // Pentru alegerea "Km service / Adauga cursa" de pe #cursa: golul de dupa / dinaintea ei.
                'after_trip_id' => $gap['after'] !== null && $windows[$gap['after']]['kind'] === 'trip' ? (int) $windows[$gap['after']]['id'] : null,
                'before_trip_id' => $gap['before'] !== null && $windows[$gap['before']]['kind'] === 'trip' ? (int) $windows[$gap['before']]['id'] : null,
                'km' => round($gap['km'], 1),
                'segments' => $gap['segments'],
            ];
        }

        $tripRows = [];
        $serviceRows = [];
        $openKm = 0.0;
        $tripsOverKm = 0.0;
        $serviceOverKm = 0.0;
        foreach ($windows as $window) {
            $row = [
                'id' => $window['id'],
                'from' => date('Y-m-d H:i', $window['from_ts']),
                'to' => date('Y-m-d H:i', $window['to_ts']),
                'gps_km' => round($window['gps_km'], 1),
                'declared_km' => round($window['declared_km'], 1),
                'counted' => $window['counted'],
                'starts_before' => $window['from_ts'] < $periodFrom,
            ];
            if ($window['kind'] === 'trip') {
                $row += [
                    'tip' => $window['tip'],
                    'beneficiar' => $window['beneficiar'],
                    'segment' => $window['segment'],
                ];
                $row['open'] = $window['open'];
                $tripRows[] = $row;
                if ($window['open']) {
                    // Cursa in desfasurare: km GPS ai ei nu sunt pierduti, apar separat (open_km).
                    $openKm += $window['gps_km'];
                    continue;
                }
                // O cursa inceputa inainte de perioada are km declarati si pentru zilele
                // dinainte (fara GPS aici): diferenta ei ramane la "marginea perioadei".
                if ($window['counted'] && !$row['starts_before']) {
                    $tripsOverKm += $window['gps_km'] - $window['declared_km'];
                }
            } else {
                $row += [
                    'service_nume' => $window['service_nume'],
                    'open' => $window['open'],
                ];
                $serviceRows[] = $row;
                if ($window['counted'] && !$row['starts_before']) {
                    $serviceOverKm += $window['gps_km'] - $window['declared_km'];
                }
            }
        }

        // Aceleasi cifre ca in tabel, ca reconcilierea sa iasa pe valoarea pe care s-a dat click.
        $declared = $vehicleId > 0 ? ($this->registeredKmByVehicleId($start, $end)[$vehicleId]['km_efectuati'] ?? 0.0) : 0.0;
        $serviceDeclared = $vehicleId > 0
            ? (float) ((new VehicleServiceKmModel($this->db))->kmByVehicleId($start, $end)[$vehicleId]['km'] ?? 0)
            : 0.0;
        $gpsTotal = (float) ($range['total_km'] ?? 0);
        $lost = $gpsTotal - (float) $declared - $serviceDeclared - $openKm;

        $this->sendJson([
            'period' => ['start' => $start, 'end' => $end],
            'gps_km' => round($gpsTotal, 1),
            'declared_trips_km' => round((float) $declared, 1),
            'declared_service_km' => round($serviceDeclared, 1),
            'open_km' => round($openKm, 1),
            'lost_km' => round($lost, 1),
            'breakdown' => [
                'outside_km' => round($outsideKm, 1),
                'trips_over_km' => round($tripsOverKm, 1),
                'service_over_km' => round($serviceOverKm, 1),
                'other_km' => round($lost - $outsideKm - $tripsOverKm - $serviceOverKm, 1),
            ],
            'gaps' => $gapRows,
            'trips' => $tripRows,
            'services' => $serviceRows,
        ]);
    }

    /**
     * Ferestrele de timp ale curselor vehiculului care ating perioada. La o cursa
     * reluata pe alt vehicul (segmente) conteaza doar segmentele acestui vehicul,
     * cu km declarati impartiti ca in registeredKmByVehicleId().
     *
     * @return array<int, array<string, mixed>>
     */
    private function tripWindows(int $vehicleId, string $start, string $end): array
    {
        $effective = "CASE
                WHEN c.tip_transport = 'compresor' AND COALESCE(c.km_dislocare, 0) > 0 THEN c.km_dislocare
                WHEN COALESCE(c.km_totali, 0) > 0 THEN c.km_totali
                ELSE COALESCE(c.km_cursa, 0)
            END";

        if ($this->segmentsTableExists()) {
            $sql = "SELECT c.id, c.tip_transport, bt.nume AS beneficiar,
                           CASE WHEN s.id IS NULL THEN COALESCE(c.data_inceput, c.data_cursa) ELSE COALESCE(s.data_inceput, c.data_inceput, c.data_cursa) END AS d_from,
                           CASE WHEN s.id IS NULL THEN c.ora_inceput ELSE s.ora_inceput END AS t_from,
                           CASE WHEN s.id IS NULL THEN COALESCE(c.data_sfarsit, c.data_inceput, c.data_cursa) ELSE COALESCE(s.data_sfarsit, s.data_inceput, c.data_sfarsit) END AS d_to,
                           CASE WHEN s.id IS NULL THEN c.ora_sfarsit ELSE s.ora_sfarsit END AS t_to,
                           COALESCE(c.data_sfarsit, c.data_inceput, c.data_cursa) AS d_closed,
                           s.ordine AS segment,
                           (c.ora_sfarsit IS NULL AND (s.id IS NULL OR s.ora_sfarsit IS NULL)) AS is_open,
                           ({$effective}) * COALESCE(COALESCE(s.km, 0) / seg.km_total, 1) AS declared_km
                      FROM curse_dispecer c
                      LEFT JOIN (
                            SELECT cursa_id, SUM(COALESCE(km, 0)) AS km_total
                              FROM curse_segmente
                             GROUP BY cursa_id
                            HAVING COUNT(*) >= 2 AND SUM(COALESCE(km, 0)) > 0
                      ) seg ON seg.cursa_id = c.id
                      LEFT JOIN curse_segmente s ON s.cursa_id = seg.cursa_id
                      LEFT JOIN configurare_beneficiari_transport bt ON bt.id = c.beneficiar_id
                     WHERE c.deleted_at IS NULL
                       AND COALESCE(s.vehicle_id, c.vehicle_id) = :vehicle
                       AND COALESCE(c.data_inceput, c.data_cursa) <= :end
                       AND (COALESCE(c.data_sfarsit, c.data_inceput, c.data_cursa) >= :start OR c.ora_sfarsit IS NULL)";
        } else {
            $sql = "SELECT c.id, c.tip_transport, bt.nume AS beneficiar,
                           COALESCE(c.data_inceput, c.data_cursa) AS d_from, c.ora_inceput AS t_from,
                           COALESCE(c.data_sfarsit, c.data_inceput, c.data_cursa) AS d_to, c.ora_sfarsit AS t_to,
                           COALESCE(c.data_sfarsit, c.data_inceput, c.data_cursa) AS d_closed,
                           NULL AS segment,
                           (c.ora_sfarsit IS NULL) AS is_open,
                           {$effective} AS declared_km
                      FROM curse_dispecer c
                      LEFT JOIN configurare_beneficiari_transport bt ON bt.id = c.beneficiar_id
                     WHERE c.deleted_at IS NULL
                       AND c.vehicle_id = :vehicle
                       AND COALESCE(c.data_inceput, c.data_cursa) <= :end
                       AND (COALESCE(c.data_sfarsit, c.data_inceput, c.data_cursa) >= :start OR c.ora_sfarsit IS NULL)";
        }

        $statement = $this->db->prepare($sql);
        $statement->execute([':vehicle' => $vehicleId, ':start' => $start, ':end' => $end]);

        $periodFrom = (int) strtotime($start . ' 00:00:00');
        $openById = [];
        foreach ($this->openTripWindowsByVehicle()[$vehicleId] ?? [] as $openWindow) {
            $openById[$openWindow['id']] = $openWindow;
        }

        $windows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            [$fromTs, $toTs] = $this->windowBounds($row['d_from'], $row['t_from'], $row['d_to'], $row['t_to']);
            $open = (int) $row['is_open'] === 1;
            if ($open) {
                $openWindow = $openById[(int) $row['id']] ?? null;
                // Cursa deschisa ruleaza pana acum sau pana la urmatoarea cursa / service al vehiculului.
                if ($openWindow === null || $openWindow['to_ts'] < $periodFrom) {
                    continue;
                }
                $fromTs = $openWindow['from_ts'];
                $toTs = $openWindow['to_ts'];
            }
            $windows[] = [
                'kind' => 'trip',
                'id' => (int) $row['id'],
                'tip' => (string) $row['tip_transport'],
                'beneficiar' => $row['beneficiar'] !== null ? (string) $row['beneficiar'] : null,
                'segment' => $row['segment'] !== null ? (int) $row['segment'] : null,
                'from_ts' => $fromTs,
                'to_ts' => $toTs,
                'declared_km' => $open ? 0.0 : (float) $row['declared_km'],
                'open' => $open,
                // Ca in tabel: cursa intra in perioada in care s-a incheiat (cea deschisa nu s-a incheiat).
                'counted' => !$open && $row['d_closed'] >= $start && $row['d_closed'] <= $end,
                'gps_km' => 0.0,
                'gps_segments' => 0,
            ];
        }
        usort($windows, static fn (array $a, array $b): int => $a['from_ts'] <=> $b['from_ts']);

        return $windows;
    }

    /**
     * Drumurile la service ale vehiculului care ating perioada (intoarcere lipsa =
     * inca in service, pana acum).
     *
     * @return array<int, array<string, mixed>>
     */
    private function serviceWindows(int $vehicleId, string $start, string $end): array
    {
        $statement = $this->db->prepare(
            'SELECT id, data_plecare, ora_plecare, data_intoarcere, ora_intoarcere, km, service_nume
               FROM vehicule_service_km
              WHERE vehicle_id = :vehicle
                AND data_plecare <= :end
                AND COALESCE(data_intoarcere, CURDATE()) >= :start'
        );
        try {
            $statement->execute([':vehicle' => $vehicleId, ':start' => $start, ':end' => $end]);
        } catch (Throwable $exception) {
            // Tabela apare la prima folosire a formularului Km service.
            return [];
        }

        $windows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $open = $row['data_intoarcere'] === null;
            [$fromTs, $toTs] = $this->windowBounds($row['data_plecare'], $row['ora_plecare'], $row['data_intoarcere'], $row['ora_intoarcere']);
            if ($open) {
                $toTs = time();
            }
            $closed = (string) ($row['data_intoarcere'] ?? $row['data_plecare']);
            $windows[] = [
                'kind' => 'service',
                'id' => (int) $row['id'],
                'service_nume' => $row['service_nume'] !== null ? (string) $row['service_nume'] : null,
                'open' => $open,
                'from_ts' => $fromTs,
                'to_ts' => $toTs,
                'declared_km' => (float) $row['km'],
                'counted' => $closed >= $start && $closed <= $end,
                'gps_km' => 0.0,
                'gps_segments' => 0,
            ];
        }

        return $windows;
    }

    /**
     * Cursele deschise (ora_sfarsit lipsa - ca la banda "Curse in desfasurare") ca ferestre de timp,
     * pe vehicul: de la inceputul cursei (al ultimei faze, cu vehiculul ei) pana acum. Daca vehiculul a
     * inceput intre timp o alta cursa / faza / un drum la service, fereastra se opreste acolo - altfel o
     * cursa uitata deschisa ar acoperi tot ce a urmat (inclusiv cursele inchise de dupa ea).
     *
     * @return array<int, array<int, array{id: int, vehicle_id: int, beneficiar: ?string, tip: string, from_ts: int, to_ts: int, ongoing: bool}>>
     */
    private function openTripWindowsByVehicle(): array
    {
        if ($this->openWindows !== null) {
            return $this->openWindows;
        }
        $this->openWindows = [];
        $now = time();
        $segments = $this->segmentsTableExists();

        $sql = $segments
            ? "SELECT c.id, c.tip_transport, bt.nume AS beneficiar,
                      COALESCE(ls.vehicle_id, c.vehicle_id) AS vehicle_id,
                      COALESCE(ls.data_inceput, c.data_inceput, c.data_cursa) AS d_from,
                      CASE WHEN ls.id IS NULL THEN c.ora_inceput ELSE ls.ora_inceput END AS t_from
                 FROM curse_dispecer c
                 LEFT JOIN curse_segmente ls ON ls.id = (
                       SELECT s2.id FROM curse_segmente s2
                        WHERE s2.cursa_id = c.id AND s2.deleted_at IS NULL
                        ORDER BY s2.ordine DESC LIMIT 1)
                 LEFT JOIN configurare_beneficiari_transport bt ON bt.id = c.beneficiar_id
                WHERE c.deleted_at IS NULL AND c.ora_sfarsit IS NULL"
            : "SELECT c.id, c.tip_transport, bt.nume AS beneficiar, c.vehicle_id,
                      COALESCE(c.data_inceput, c.data_cursa) AS d_from, c.ora_inceput AS t_from
                 FROM curse_dispecer c
                 LEFT JOIN configurare_beneficiari_transport bt ON bt.id = c.beneficiar_id
                WHERE c.deleted_at IS NULL AND c.ora_sfarsit IS NULL";

        $open = [];
        foreach ($this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $vehicleId = (int) $row['vehicle_id'];
            $fromTs = (int) strtotime($row['d_from'] . ' ' . ($row['t_from'] ?: '00:00:00'));
            // Cursele programate in viitor nu au inceput inca.
            if ($vehicleId <= 0 || $fromTs <= 0 || $fromTs > $now) {
                continue;
            }
            $open[] = [
                'id' => (int) $row['id'],
                'vehicle_id' => $vehicleId,
                'tip' => (string) $row['tip_transport'],
                'beneficiar' => $row['beneficiar'] !== null ? (string) $row['beneficiar'] : null,
                'from_ts' => $fromTs,
            ];
        }
        if ($open === []) {
            return $this->openWindows;
        }

        // Inceputurile tuturor curselor / fazelor / drumurilor la service ale acestor vehicule
        // (id-uri intregi, puse direct in IN).
        $ids = implode(',', array_unique(array_map(static fn (array $o): int => $o['vehicle_id'], $open)));
        $starts = [];
        $startsSql = $segments
            ? "SELECT COALESCE(s.vehicle_id, c.vehicle_id) AS vehicle_id, c.id AS cursa_id,
                      COALESCE(s.data_inceput, c.data_inceput, c.data_cursa) AS d,
                      CASE WHEN s.id IS NULL THEN c.ora_inceput ELSE s.ora_inceput END AS t
                 FROM curse_dispecer c
                 LEFT JOIN curse_segmente s ON s.cursa_id = c.id AND s.deleted_at IS NULL
                WHERE c.deleted_at IS NULL AND COALESCE(s.vehicle_id, c.vehicle_id) IN ({$ids})"
            : "SELECT vehicle_id, id AS cursa_id, COALESCE(data_inceput, data_cursa) AS d, ora_inceput AS t
                 FROM curse_dispecer WHERE deleted_at IS NULL AND vehicle_id IN ({$ids})";
        foreach ($this->db->query($startsSql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $starts[(int) $row['vehicle_id']][] = [(int) $row['cursa_id'], (int) strtotime($row['d'] . ' ' . ($row['t'] ?: '00:00:00'))];
        }
        try {
            $serviceSql = "SELECT vehicle_id, data_plecare AS d, ora_plecare AS t FROM vehicule_service_km WHERE vehicle_id IN ({$ids})";
            foreach ($this->db->query($serviceSql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $starts[(int) $row['vehicle_id']][] = [0, (int) strtotime($row['d'] . ' ' . ($row['t'] ?: '00:00:00'))];
            }
        } catch (Throwable $exception) {
            // Tabela Km service apare la prima folosire a formularului.
        }

        foreach ($open as $trip) {
            $toTs = $now;
            foreach ($starts[$trip['vehicle_id']] ?? [] as [$tripId, $ts]) {
                if ($tripId !== $trip['id'] && $ts > $trip['from_ts'] && $ts < $toTs) {
                    $toTs = $ts;
                }
            }
            $this->openWindows[$trip['vehicle_id']][] = $trip + ['to_ts' => $toTs, 'ongoing' => $toTs === $now];
        }

        return $this->openWindows;
    }

    /**
     * Km GPS ai unei curse deschise, de la INCEPUTUL ei (nu doar din perioada raportului), pentru
     * inchiderea ei din cardul "Km in desfasurare": fisa cursei se precompleteaza cu km-ii acestia si
     * cu sfarsitul ferestrei. Cel mult 92 de zile inapoi (limita SAS); peste, `partial` = true.
     */
    private function openTripAction(): void
    {
        $tripId = (int) ($_GET['trip_id'] ?? 0);
        $window = null;
        foreach ($this->openTripWindowsByVehicle() as $list) {
            foreach ($list as $candidate) {
                if ($candidate['id'] === $tripId) {
                    $window = $candidate;
                }
            }
        }
        if ($window === null) {
            http_response_code(404);
            $this->sendJson(['success' => false, 'error' => 'Cursa nu mai este deschisă (are deja ora de sfârșit).']);
            return;
        }

        $carId = 0;
        foreach ($this->service->getFleetVehicles() as $car) {
            if ((int) ($car['local_vehicle_id'] ?? 0) === $window['vehicle_id']) {
                $carId = (int) ($car['sas_vehicle_id'] ?? 0);
            }
        }
        $payload = [
            'success' => true,
            'trip_id' => $tripId,
            'from' => date('Y-m-d H:i', $window['from_ts']),
            'to' => date('Y-m-d H:i', $window['to_ts']),
            'ongoing' => $window['ongoing'],
            'km' => null,
            'partial' => false,
        ];
        if ($carId <= 0) {
            $payload['error'] = 'Vehiculul nu are GPS în SAS.';
            $this->sendJson($payload);
            return;
        }

        $end = new DateTimeImmutable(date('Y-m-d', $window['to_ts']));
        $start = new DateTimeImmutable(date('Y-m-d', $window['from_ts']));
        if ((int) $start->diff($end)->days > 91) {
            $start = $end->modify('-91 days');
            $payload['partial'] = true;
        }
        try {
            $range = (array) $this->service->getVehicleRangeIncremental($carId, $start->format('Y-m-d'), $end->format('Y-m-d'));
            $payload['km'] = round(SasDashboardService::kmInsideWindows((array) ($range['segments'] ?? []), [$window]), 1);
        } catch (Throwable $exception) {
            error_log('[KmPierdutiController][open_trip] ' . $exception->getMessage());
            http_response_code(502);
            $payload = ['success' => false, 'error' => 'Km GPS nu au putut fi încărcați din SAS.'];
        }
        $this->sendJson($payload);
    }

    /**
     * Ferestrele curselor deschise pentru un carId SAS (prin vehiculul local asociat).
     *
     * @return array<int, array<string, mixed>>
     */
    private function openWindowsForCar(int $carId): array
    {
        $windows = $this->openTripWindowsByVehicle();
        if ($windows === []) {
            return [];
        }
        foreach ($this->service->getFleetVehicles() as $car) {
            if ((int) ($car['sas_vehicle_id'] ?? 0) === $carId) {
                return $windows[(int) ($car['local_vehicle_id'] ?? 0)] ?? [];
            }
        }

        return [];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function windowBounds(?string $dateFrom, ?string $timeFrom, ?string $dateTo, ?string $timeTo): array
    {
        $dateTo = $dateTo ?: $dateFrom;
        $fromTs = (int) strtotime($dateFrom . ' ' . ($timeFrom ?: '00:00:00'));
        $toTs = (int) strtotime($dateTo . ' ' . ($timeTo ?: '23:59:59'));
        if ($toTs < $fromTs) {
            $toTs = $fromTs;
        }

        return [$fromTs, $toTs];
    }

    /**
     * Intervalele din perioada neacoperite de nicio fereastra, fiecare cu
     * fereastra dinainte si de dupa (indecsi in $windows), pentru context.
     *
     * @param array<int, array<string, mixed>> $windows
     * @return array<int, array<string, mixed>>
     */
    private function gapsBetween(array $windows, int $periodFrom, int $periodTo): array
    {
        $order = array_keys($windows);
        usort($order, static fn (int $a, int $b): int => $windows[$a]['from_ts'] <=> $windows[$b]['from_ts']);

        $gaps = [];
        $cursor = $periodFrom;
        $previous = null;
        foreach ($order as $index) {
            $window = $windows[$index];
            if ($window['from_ts'] > $cursor && $cursor < $periodTo) {
                $gaps[] = ['from_ts' => $cursor, 'to_ts' => min($window['from_ts'], $periodTo), 'after' => $previous, 'before' => $index, 'km' => 0.0, 'segments' => []];
            }
            if ($window['to_ts'] >= $cursor) {
                $cursor = $window['to_ts'];
                $previous = $index;
            }
        }
        if ($cursor < $periodTo) {
            $gaps[] = ['from_ts' => $cursor, 'to_ts' => $periodTo, 'after' => $previous, 'before' => null, 'km' => 0.0, 'segments' => []];
        }

        return $gaps;
    }

    /**
     * Imparte o deplasare GPS pe ferestre, proportional cu timpul petrecut in
     * fiecare. Prima fereastra care acopera un moment castiga (cursele inaintea
     * service-ului). O deplasare fara durata merge dupa momentul de start.
     *
     * @param array<int, array<string, mixed>> $windows
     * @return array<int, array{0: ?int, 1: float, 2: int, 3: int}> [fereastra|null, km, de la, pana la]
     */
    private function splitSegment(int $segStart, int $segEnd, float $km, array $windows): array
    {
        $findWindow = static function (int $ts) use ($windows): ?int {
            foreach ($windows as $index => $window) {
                if ($ts >= $window['from_ts'] && $ts <= $window['to_ts']) {
                    return $index;
                }
            }
            return null;
        };

        if ($segEnd <= $segStart) {
            return [[$findWindow($segStart), $km, $segStart, $segEnd]];
        }

        $cuts = [$segStart, $segEnd];
        foreach ($windows as $window) {
            foreach ([$window['from_ts'], $window['to_ts']] as $edge) {
                if ($edge > $segStart && $edge < $segEnd) {
                    $cuts[] = $edge;
                }
            }
        }
        $cuts = array_values(array_unique($cuts));
        sort($cuts);

        $duration = $segEnd - $segStart;
        $parts = [];
        for ($i = 0; $i < count($cuts) - 1; $i++) {
            $from = $cuts[$i];
            $to = $cuts[$i + 1];
            $index = $findWindow(intdiv($from + $to, 2));
            $partKm = $km * ($to - $from) / $duration;
            $last = count($parts) - 1;
            // Bucatile consecutive in acelasi loc se lipesc la loc.
            if ($last >= 0 && $parts[$last][0] === $index) {
                $parts[$last][1] += $partKm;
                $parts[$last][3] = $to;
                continue;
            }
            $parts[] = [$index, $partKm, $from, $to];
        }

        return $parts;
    }

    /**
     * O singura cerere pentru toate vehiculele: km GPS deja pe disc (fara niciun apel SAS).
     * Frontendul cere apoi prin action=gps doar vehiculele din `missing`.
     */
    private function gpsCachedAction(): void
    {
        [$start, $end] = $this->resolvePeriod();
        // allow_stale=1 (cardul din Dispecer): ziua de azi expirata vine tot din cache, in `stale`
        // (carId => varsta in secunde); frontendul o arata ca "in actualizare" si o cere apoi prin
        // action=gps. Fara parametru raspunsul e cel de pana acum (pagina raportului).
        $allowStale = ($_GET['allow_stale'] ?? '') === '1';
        $cached = [];
        $open = [];
        $missing = [];
        $stale = [];
        foreach (array_unique(array_map('intval', explode(',', (string) ($_GET['car_ids'] ?? '')))) as $carId) {
            if ($carId <= 0) {
                continue;
            }
            $windows = $this->openWindowsForCar($carId);
            try {
                $km = $this->service->getCachedVehicleKm($carId, $start, $end, $windows);
                if ($km === null && $allowStale) {
                    $old = $this->service->getStaleCachedVehicleKm($carId, $start, $end, $windows);
                    if ($old !== null) {
                        $km = $old['km'];
                        $stale[(string) $carId] = $old['today_age'];
                    }
                }
            } catch (Throwable $exception) {
                $km = null;
            }
            if ($km === null) {
                $missing[] = $carId;
            } else {
                $cached[(string) $carId] = $km['total_km'];
                if (isset($km['open_km'])) {
                    $open[(string) $carId] = $km['open_km'];
                }
            }
        }
        // open: km GPS rulati pe cursele inca deschise ("Km in desfasurare"), doar vehiculele care au asa ceva.
        $payload = ['cached' => (object) $cached, 'open' => (object) $open, 'missing' => $missing, 'today_ttl' => SasDashboardService::TODAY_TTL_SECONDS];
        if ($allowStale) {
            $payload['stale'] = (object) $stale;
        }
        $this->sendJson($payload);
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
            $this->sendJson($this->service->getVehicleKm($carId, $start, $end, $this->openWindowsForCar($carId)));
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
