<?php
declare(strict_types=1);

require_once __DIR__ . '/../models/DriverWhatsAppModel.php';
require_once __DIR__ . '/InvoiceTripMatcher.php';
require_once __DIR__ . '/DriverWhatsAppConversationService.php';
require_once __DIR__ . '/EtollSessionService.php';

/**
 * Prima miscare a zilei (> 1 km) pe vehicul, din GPS-ul SAS, si notificarea WhatsApp a soferului.
 *
 * Ruleaza in fundal (scripts/driver_first_movement.php, cron), nu depinde de cineva care
 * deschide "Curse in desfasurare". La fiecare rulare:
 *   1. o singura cerere SAS pentru toata flota: FleetLivePositionService::getLivePositions()
 *      (acelasi cache de 20 s ca harta) -> raman doar vehiculele cu o pozitie de azi;
 *   2. pentru fiecare, cel mult o data la DRIVER_WA_RECHECK_MINUTES, foaia de parcurs SAS
 *      (SasDashboardService::getIntervalTrips) de ieri 12:00 pana acum;
 *   3. firstMovement(): segmentele sub 0,5 km sunt zgomot GPS / manevre (acelasi prag ca
 *      SasTripPrefillService::MIN_MOVING_SEGMENT_KM), un segment care trece de miezul noptii
 *      se numara doar cu partea din ziua curenta (proportional cu timpul);
 *   4. la prag atins: vehicle_movement_days = 'triggered' (o data pe zi) si cate o notificare
 *      pe soferul care conduce vehiculul in ziua respectiva (UNIQUE sofer + vehicul + zi);
 *   5. verificarea eToll (DRIVER_WA_ETOLL_CHECK, implicit activa): camionul merge (SAS) si
 *      are sesiune eToll in curs -> nu se trimite nimic; nu are -> soferul nu a pornit sesiunea
 *      in aplicatie, primeste mesajul cu nr. auto; eToll indisponibil -> mesajul de dinainte.
 *      Rezultatul se salveaza in trigger_json['etoll'] si in detail.
 *
 * Mod de test (DRIVER_WA_TEST_MODE, implicit activ): se verifica doar vehiculele soferilor din
 * DRIVER_WA_TEST_DRIVER_IDS si se notifica doar acesti soferi.
 */
class DriverFirstMovementService
{
    /** Acelasi prag ca SasTripPrefillService::MIN_MOVING_SEGMENT_KM. */
    public const MIN_SEGMENT_KM = 0.5;

    /** Pauza intre cererile SAS (aceeasi ca ReviziiSasSyncService: ~60 cereri / minut). */
    private const SAS_PACE_MICROSECONDS = 650000;

    /** @var callable(string): void */
    private $log;

    /**
     * @param array<string, mixed>|null $config null = din .env (self::config())
     * @param SasDashboardService|null $sas null = nu se apeleaza SAS (doar simulari / teste)
     * @param EtollSessionService|null $etoll null = din .env la prima nevoie (teste: EtollSessionService::fixed())
     */
    public function __construct(
        private PDO $db,
        private DriverWhatsAppModel $model,
        private ?SasDashboardService $sas = null,
        private ?FleetLivePositionService $positions = null,
        private ?array $config = null,
        ?callable $log = null,
        private ?EtollSessionService $etoll = null,
    ) {
        $this->config ??= self::config();
        $this->log = $log ?? static function (string $message): void {
        };
    }

    /** @return array{enabled: bool, test_mode: bool, test_driver_ids: array<int, int>, min_km: float, recheck_minutes: int, etoll_check: bool} */
    public static function config(): array
    {
        $env = static function (string $name, string $default = ''): string {
            $value = getenv($name);

            return is_string($value) && trim($value) !== '' ? trim($value) : $default;
        };
        $flag = static fn(string $value): bool => in_array(strtolower($value), ['1', 'true', 'yes', 'on', 'da'], true);

        return [
            'enabled' => $flag($env('DRIVER_WA_ENABLED', '0')),
            // Implicit test: nimeni in afara listei nu primeste nimic pana nu se dezactiveaza explicit.
            'test_mode' => $flag($env('DRIVER_WA_TEST_MODE', '1')),
            'test_driver_ids' => array_values(array_filter(array_map('intval', preg_split('/[\s,]+/', $env('DRIVER_WA_TEST_DRIVER_IDS')) ?: []))),
            'min_km' => max(0.1, (float) $env('DRIVER_WA_MIN_KM', '1')),
            'recheck_minutes' => max(1, (int) $env('DRIVER_WA_RECHECK_MINUTES', '3')),
            // Notifica doar camioanele care merg fara sesiune eToll in curs (cheile ETOLL_* din .env).
            'etoll_check' => $flag($env('DRIVER_WA_ETOLL_CHECK', '1')),
        ];
    }

    // -------------------------------------------------------------------------
    // Nucleul pur (testat fara SAS: scripts/test_driver_whatsapp.php)
    // -------------------------------------------------------------------------

    /**
     * Prima miscare a zilei $date din segmentele foii de parcurs.
     *
     * Reguli:
     *   - segment real = distanta segmentului >= MIN_SEGMENT_KM (zgomotul GPS si manevrele raman afara);
     *   - doar partea din ziua locala [00:00, 24:00): un segment de peste miezul noptii contribuie
     *     proportional cu timpul petrecut in ziua curenta;
     *   - segmentele repetate (acelasi start + sfarsit) se numara o data;
     *   - pragul se atinge cand suma km reali din zi depaseste $thresholdKm.
     *
     * @param array<int, array{start: ?string, end: ?string, km: mixed}> $segments
     * @return array{reached: bool, km: float, first_movement_at: ?string, threshold_reached_at: ?string, segments: array<int, array<string, mixed>>}
     */
    public static function firstMovement(array $segments, string $date, float $thresholdKm, float $minSegmentKm = self::MIN_SEGMENT_KM, ?int $nowTs = null): array
    {
        $dayStart = strtotime($date . ' 00:00:00');
        $dayEnd = strtotime($date . ' 00:00:00 +1 day');
        $nowTs ??= time();

        $clean = [];
        $seen = [];
        foreach ($segments as $segment) {
            $start = strtotime((string) ($segment['start'] ?? ''));
            // Segmentul in curs (foaie de parcurs deschisa) poate veni fara sfarsit.
            $end = !empty($segment['end']) ? strtotime((string) $segment['end']) : $nowTs;
            $km = is_numeric($segment['km'] ?? null) ? (float) $segment['km'] : 0.0;
            if ($start === false || $end === false || $end < $start) {
                continue;
            }
            $key = $start . '|' . $end;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $clean[] = ['start' => $start, 'end' => $end, 'km' => $km];
        }
        usort($clean, static fn(array $a, array $b): int => $a['start'] <=> $b['start']);

        $total = 0.0;
        $first = null;
        $used = [];
        foreach ($clean as $segment) {
            if ($segment['km'] < $minSegmentKm || $segment['end'] <= $dayStart || $segment['start'] >= $dayEnd) {
                continue;
            }
            $inDayStart = max($segment['start'], $dayStart);
            $inDayEnd = min($segment['end'], $dayEnd);
            $duration = $segment['end'] - $segment['start'];
            $share = $duration > 0 ? ($inDayEnd - $inDayStart) / $duration : 1.0;
            $kmToday = round($segment['km'] * $share, 3);
            if ($kmToday <= 0) {
                continue;
            }

            $first ??= $inDayStart;
            $total += $kmToday;
            $used[] = [
                'start' => date('Y-m-d H:i:s', $segment['start']),
                'end' => date('Y-m-d H:i:s', $segment['end']),
                'km' => $segment['km'],
                'km_in_day' => $kmToday,
            ];

            if ($total > $thresholdKm) {
                // Momentul aproximativ al pragului: interpolat liniar in segmentul care il depaseste.
                $needed = $thresholdKm - ($total - $kmToday);
                $reachedTs = (int) round($inDayStart + ($inDayEnd - $inDayStart) * max(0.0, min(1.0, $needed / $kmToday)));

                return [
                    'reached' => true,
                    'km' => round($total, 2),
                    'first_movement_at' => date('Y-m-d H:i:s', $first),
                    'threshold_reached_at' => date('Y-m-d H:i:s', max($reachedTs, $first)),
                    'segments' => $used,
                ];
            }
        }

        return [
            'reached' => false,
            'km' => round($total, 2),
            'first_movement_at' => $first !== null ? date('Y-m-d H:i:s', $first) : null,
            'threshold_reached_at' => null,
            'segments' => $used,
        ];
    }

    // -------------------------------------------------------------------------
    // Cine conduce vehiculul
    // -------------------------------------------------------------------------

    /**
     * Soferul (soferii) vehiculului in ziua $date:
     *   1. cursele din Dispecer care acopera ziua, cu segmentele lor (aceeasi regula ca la
     *      asocierea facturilor: InvoiceTripMatcher::participantsOnDate) -> in ziua de predare
     *      pot fi doi soferi, fiecare primeste propria notificare;
     *   2. fara cursa: alocarea implicita (soferi_vehicule) - un singur sofer activ, sau un singur
     *      sofer principal; altfel ambiguu (nu se trimite nimic).
     *
     * @return array{driver_ids: array<int, int>, source: string, detail: ?string}
     */
    public function resolveDrivers(int $vehicleId, string $date): array
    {
        $matcher = new InvoiceTripMatcher($this->db);
        $driverIds = [];
        foreach ($matcher->loadCandidateTrips($date, 0, 0) as $trip) {
            foreach ($matcher->participantsOnDate($trip, $date) as $participant) {
                if ((int) ($participant['vehicle_id'] ?? 0) === $vehicleId && (int) ($participant['driver_id'] ?? 0) > 0) {
                    $driverIds[(int) $participant['driver_id']] = true;
                }
            }
        }
        if ($driverIds !== []) {
            return ['driver_ids' => array_keys($driverIds), 'source' => 'cursa', 'detail' => null];
        }

        $defaults = $this->model->getDefaultDriversForVehicle($vehicleId);
        if (count($defaults) === 1) {
            return ['driver_ids' => [$defaults[0]['driver_id']], 'source' => 'alocare', 'detail' => null];
        }
        $primary = array_values(array_filter($defaults, static fn(array $d): bool => $d['is_primary']));
        if (count($primary) === 1) {
            return ['driver_ids' => [$primary[0]['driver_id']], 'source' => 'alocare_principal', 'detail' => null];
        }
        if ($defaults === []) {
            return ['driver_ids' => [], 'source' => 'none', 'detail' => 'Vehicul fara sofer alocat si fara cursa in ziua respectiva.'];
        }

        return ['driver_ids' => [], 'source' => 'ambiguous', 'detail' => count($defaults) . ' soferi alocati, niciunul unic principal; nu se trimite nimic.'];
    }

    // -------------------------------------------------------------------------
    // Rularea in fundal
    // -------------------------------------------------------------------------

    /** @return array<string, mixed> rezumatul rularii */
    public function run(?string $date = null): array
    {
        $date ??= date('Y-m-d');
        $summary = ['date' => $date, 'candidates' => 0, 'checked' => 0, 'triggered' => 0, 'notifications' => 0, 'skipped' => [], 'errors' => []];

        if (!$this->config['enabled']) {
            $summary['skipped_reason'] = 'DRIVER_WA_ENABLED nu este activat.';

            return $summary;
        }
        if ($this->config['test_mode'] && $this->config['test_driver_ids'] === []) {
            $summary['skipped_reason'] = 'Mod test fara DRIVER_WA_TEST_DRIVER_IDS: nu se notifica nimeni.';

            return $summary;
        }
        if ($this->sas === null || $this->positions === null) {
            $summary['skipped_reason'] = 'SAS indisponibil in aceasta rulare.';

            return $summary;
        }

        $live = $this->positions->getLivePositions();
        if (!empty($live['error']) && empty($live['positions'])) {
            $summary['errors'][] = 'SAS: ' . $live['error'];

            return $summary;
        }

        $allowedVehicles = $this->config['test_mode'] ? $this->testVehicleIds($date) : null;
        $sasCalls = 0;
        foreach ($live['positions'] ?? [] as $position) {
            $vehicleId = (int) ($position['local_vehicle_id'] ?? 0);
            $carId = (int) ($position['sas_vehicle_id'] ?? 0);
            $timestamp = strtotime((string) ($position['timestamp'] ?? ''));
            // Fara o pozitie de azi vehiculul nu s-a miscat azi (datele intarziate vin cu ora lor).
            if ($vehicleId <= 0 || $carId <= 0 || $timestamp === false || date('Y-m-d', $timestamp) !== $date) {
                continue;
            }
            if ($allowedVehicles !== null && !isset($allowedVehicles[$vehicleId])) {
                continue;
            }
            $summary['candidates']++;

            $day = $this->model->getMovementDay($vehicleId, $date);
            if ($day !== null && (string) $day['status'] === 'triggered') {
                continue;
            }
            if ($day !== null && strtotime((string) $day['last_checked_at']) > time() - 60 * $this->config['recheck_minutes']) {
                continue;
            }

            try {
                if ($sasCalls++ > 0) {
                    usleep(self::SAS_PACE_MICROSECONDS);
                }
                // De ieri 12:00: un segment inceput inainte de miezul noptii apare in raspuns
                // indiferent daca SAS il taie la capatul intervalului.
                $trips = $this->sas->getIntervalTrips($carId, date('Y-m-d H:i:s', strtotime($date . ' 00:00:00') - 12 * 3600), date('Y-m-d H:i:s'));
                $summary['checked']++;
                $result = $this->processVehicle($vehicleId, $carId, $date, $trips['trips'] ?? [], false);
                if ($result['triggered']) {
                    $summary['triggered']++;
                }
                $summary['notifications'] += $result['notifications'];
                foreach ($result['skipped'] as $skip) {
                    $summary['skipped'][] = $skip;
                }
            } catch (Throwable $exception) {
                $summary['errors'][] = 'Vehicul #' . $vehicleId . ': ' . $exception->getMessage();
                ($this->log)('EROARE vehicul #' . $vehicleId . ': ' . $exception->getMessage());
            }
        }

        return $summary;
    }

    /**
     * Evalueaza un vehicul pentru ziua $date si, la prag, notifica soferii.
     *
     * @param array<int, array<string, mixed>> $segments ['start','end','km'] (din SAS sau simulate)
     * @param int|null $onlyDriverId simulare: notifica doar acest sofer (trebuie sa fie in lista de test)
     * @return array{triggered: bool, notifications: int, movement: array<string, mixed>, skipped: array<int, string>, etoll?: ?array<string, mixed>}
     */
    public function processVehicle(int $vehicleId, ?int $carId, string $date, array $segments, bool $isSimulation, ?int $onlyDriverId = null): array
    {
        $movement = self::firstMovement($segments, $date, $this->config['min_km']);
        $existing = $this->model->getMovementDay($vehicleId, $date);
        $alreadyTriggered = $existing !== null && (string) $existing['status'] === 'triggered';

        if (!$movement['reached'] && !$alreadyTriggered) {
            $this->model->saveMovementDay($vehicleId, $date, [
                'status' => 'below_threshold', 'movement_km' => $movement['km'], 'first_movement_at' => $movement['first_movement_at'],
                'sas_car_id' => $carId, 'detail' => null, 'is_simulation' => $isSimulation,
            ]);

            return ['triggered' => false, 'notifications' => 0, 'movement' => $movement, 'skipped' => []];
        }

        $trigger = [
            'km' => $movement['km'],
            'prag_km' => $this->config['min_km'],
            'first_movement_at' => $movement['first_movement_at'],
            'threshold_reached_at' => $movement['threshold_reached_at'],
            'segments' => array_slice($movement['segments'], 0, 10),
            'sas_car_id' => $carId,
            'simulare' => $isSimulation,
        ];
        $notifying = !$alreadyTriggered || $onlyDriverId !== null;
        $etoll = $notifying ? $this->etollStatus($vehicleId) : null;
        if ($etoll !== null) {
            $trigger['etoll'] = $etoll;
        }
        $dayId = $this->model->saveMovementDay($vehicleId, $date, [
            'status' => 'triggered', 'movement_km' => $movement['km'], 'first_movement_at' => $movement['first_movement_at'],
            'threshold_reached_at' => $movement['threshold_reached_at'], 'trigger' => $trigger, 'sas_car_id' => $carId,
            'is_simulation' => $isSimulation,
        ]);
        if (!$notifying) {
            return ['triggered' => false, 'notifications' => 0, 'movement' => $movement, 'skipped' => []];
        }

        // Camionul merge si sesiunea eToll e pornita: soferul a facut ce trebuia, nu-l deranjam.
        if ($etoll['stare'] === 'activa') {
            $detail = 'Sesiune eToll activa pentru ' . $etoll['nr_auto'] . ($etoll['din'] !== null ? ' (din ' . date('d.m H:i', strtotime($etoll['din'])) . ')' : '') . '; nu se trimite notificare.';
            $this->model->saveMovementDay($vehicleId, $date, ['status' => 'triggered', 'detail' => $detail]);
            ($this->log)('Vehicul #' . $vehicleId . ': ' . $detail);

            return ['triggered' => true, 'notifications' => 0, 'movement' => $movement, 'skipped' => ['Vehicul #' . $vehicleId . ': ' . $detail], 'etoll' => $etoll];
        }
        // Fara sesiune: mesajul spune explicit; eToll necunoscut / dezactivat: mesajul de dinainte.
        $prompt = $etoll['stare'] === 'inactiva'
            ? DriverWhatsAppConversationService::noSessionPrompt($etoll['nr_auto'])
            : DriverWhatsAppConversationService::MOVEMENT_PROMPT;

        $skipped = [];
        if ($onlyDriverId !== null) {
            $resolved = ['driver_ids' => [$onlyDriverId], 'source' => 'simulare', 'detail' => null];
        } else {
            $resolved = $this->resolveDrivers($vehicleId, $date);
        }
        if ($resolved['driver_ids'] === []) {
            $skipped[] = 'Vehicul #' . $vehicleId . ': ' . $resolved['detail'];
            $this->model->saveMovementDay($vehicleId, $date, ['status' => 'triggered', 'detail' => $resolved['detail']]);
        }

        $sent = 0;
        foreach ($resolved['driver_ids'] as $driverId) {
            $reason = $this->ineligibleReason($driverId);
            if ($reason !== null) {
                $skipped[] = 'Sofer #' . $driverId . ' / vehicul #' . $vehicleId . ': ' . $reason;
                continue;
            }
            $id = $this->model->createNotificationWithOutbox(
                $driverId,
                $vehicleId,
                $date,
                $dayId,
                $trigger + ['sofer_din' => $resolved['source']],
                $prompt,
                DriverWhatsAppConversationService::movementOptions(),
                $isSimulation
            );
            if ($id !== null) {
                $sent++;
                ($this->log)(sprintf('Notificare #%d: sofer #%d, vehicul #%d, %.2f km de la %s, eToll: %s%s', $id, $driverId, $vehicleId, $movement['km'], $movement['first_movement_at'], $etoll['stare'], $isSimulation ? ' (simulare)' : ''));
            }
        }

        return ['triggered' => true, 'notifications' => $sent, 'movement' => $movement, 'skipped' => $skipped, 'etoll' => $etoll];
    }

    /**
     * Are camionul o sesiune eToll in curs?
     *
     * @return array{stare: string, nr_auto: string, din: ?string, motiv: ?string}
     *         stare: activa | inactiva | necunoscut (eToll indisponibil, vehicul fara nr.) | dezactivata
     */
    public function etollStatus(int $vehicleId): array
    {
        $plate = EtollSessionService::plateOfVehicle($this->db, $vehicleId);
        if (empty($this->config['etoll_check'])) {
            return ['stare' => 'dezactivata', 'nr_auto' => $plate, 'din' => null, 'motiv' => 'DRIVER_WA_ETOLL_CHECK=0'];
        }
        if ($plate === '') {
            return ['stare' => 'necunoscut', 'nr_auto' => '', 'din' => null, 'motiv' => 'vehicul fara nr. de inmatriculare'];
        }

        $this->etoll ??= EtollSessionService::fromEnv();
        $active = $this->etoll->isActive($plate);
        if ($active === null) {
            return ['stare' => 'necunoscut', 'nr_auto' => $plate, 'din' => null, 'motiv' => mb_substr((string) $this->etoll->error(), 0, 150)];
        }

        return ['stare' => $active ? 'activa' : 'inactiva', 'nr_auto' => $plate, 'din' => $this->etoll->activeSince($plate), 'motiv' => null];
    }

    /** De ce soferul nu poate fi notificat, sau null. */
    public function ineligibleReason(int $driverId): ?string
    {
        if ($this->config['test_mode'] && !in_array($driverId, $this->config['test_driver_ids'], true)) {
            return 'nu e in lista de test (DRIVER_WA_TEST_DRIVER_IDS)';
        }
        $driver = $this->model->getActiveDriver($driverId);
        if ($driver === null) {
            return 'sofer inexistent sau inactiv';
        }
        if ($driver['phone_e164'] === null) {
            return 'fara numar de telefon valid';
        }

        return null;
    }

    /**
     * In modul de test: vehiculele soferilor din lista (alocare implicita + cursele din ziua data),
     * ca SAS sa nu fie interogat pentru restul flotei.
     *
     * @return array<int, bool>
     */
    private function testVehicleIds(string $date): array
    {
        $vehicles = [];
        foreach ($this->config['test_driver_ids'] as $driverId) {
            foreach ($this->model->getDefaultVehicleIdsForDriver($driverId) as $vehicleId) {
                $vehicles[$vehicleId] = true;
            }
        }
        $matcher = new InvoiceTripMatcher($this->db);
        foreach ($matcher->loadCandidateTrips($date, 0, 0) as $trip) {
            foreach ($matcher->participantsOnDate($trip, $date) as $participant) {
                if (in_array((int) ($participant['driver_id'] ?? 0), $this->config['test_driver_ids'], true) && (int) ($participant['vehicle_id'] ?? 0) > 0) {
                    $vehicles[(int) $participant['vehicle_id']] = true;
                }
            }
        }

        return $vehicles;
    }
}
