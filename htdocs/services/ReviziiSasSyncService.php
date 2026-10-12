<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/models/ReviziiModel.php';
require_once __DIR__ . '/ReviziiService.php';

/**
 * Sincronizarea telemetriei SasFleet pentru Revizii → Schimb ulei.
 *
 * Refoloseste clientul existent (SasFleetClient + sesiunea partajata din
 * storage/cache/sas_session.json) si maparea pe numar de inmatriculare din
 * FleetLivePositionService. Ruleaza din cron (scripts/sync_revizii_sas.php) sau
 * din butonul "Sincronizează" — pagina in sine nu face niciun apel API.
 *
 * Idempotenta: datele se aduc pe ferestre de zile intregi (max 7) si fiecare
 * fereastra INLOCUIESTE segmentele ei (ReviziiModel::storeWindow), deci rularile
 * repetate nu dubleaza km sau orele motor. O fereastra esuata se marcheaza
 * "eroare" (zile lipsa), niciodata ca 0 km.
 */
class ReviziiSasSyncService
{
    private const WINDOW_DAYS = 7;
    /** Pauza intre cereri: SAS limiteaza la ~60 cereri/minut. */
    private const PAUSE_MICROSECONDS = 650000;
    /** Dupa atatea erori consecutive (API cazut) rularea se opreste. */
    private const MAX_CONSECUTIVE_ERRORS = 3;

    private PDO $db;
    private ReviziiModel $model;
    private SasFleetClient $client;
    private string $cacheDir;
    /** @var callable|null */
    private $logger;

    public function __construct(PDO $db, ?callable $logger = null)
    {
        $this->db = $db;
        $this->model = new ReviziiModel($db);
        $this->client = new SasFleetClient();
        $this->cacheDir = dirname(BASE_PATH) . '/storage/cache';
        $this->logger = $logger;
    }

    public function credentialsAvailable(): bool
    {
        return $this->client->credentialsAvailable();
    }

    /**
     * @return array{calls:int, windows_ok:int, windows_error:int, segments:int, cars:int, pending:int, aborted:bool, locked:bool, message:string}
     */
    public function run(int $maxCalls = 500, int $maxSeconds = 0): array
    {
        $stats = ['calls' => 0, 'windows_ok' => 0, 'windows_error' => 0, 'segments' => 0, 'cars' => 0, 'pending' => 0, 'aborted' => false, 'locked' => false, 'message' => ''];
        if (!$this->credentialsAvailable()) {
            $stats['message'] = 'Credențialele SAS nu sunt configurate.';
            return $stats;
        }

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
        $lock = @fopen($this->cacheDir . '/revizii_sas_sync.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            $stats['locked'] = true;
            $stats['message'] = 'O altă sincronizare este în curs.';
            return $stats;
        }

        try {
            $startedAt = time();
            $this->refreshMapping();
            $needs = $this->computeNeeds();
            $stats['cars'] = count($needs);

            // Doua treceri: intai zilele recente (ieri/azi) pentru toate vehiculele,
            // apoi completarea istoricului — un buget mic tot aduce date proaspete.
            $recentFrom = date('Y-m-d', strtotime('-1 day'));
            $queue = [];
            foreach ($needs as $carId => $fromDate) {
                $days = $this->model->getDaysToFetch($carId, $fromDate, date('Y-m-d'));
                foreach (ReviziiService::chunkDays($days, self::WINDOW_DAYS) as $window) {
                    $queue[] = ['car' => $carId, 'from' => $window[0], 'to' => $window[1], 'recent' => $window[1] >= $recentFrom];
                }
            }
            usort($queue, static fn (array $a, array $b): int => [$b['recent'], $b['to']] <=> [$a['recent'], $a['to']]);

            $this->restoreSasSession();
            $consecutiveErrors = 0;
            foreach ($queue as $index => $job) {
                if ($stats['calls'] >= $maxCalls || ($maxSeconds > 0 && time() - $startedAt >= $maxSeconds)) {
                    $stats['pending'] = count($queue) - $index;
                    break;
                }
                if ($stats['calls'] > 0) {
                    usleep(self::PAUSE_MICROSECONDS);
                }
                $stats['calls']++;
                try {
                    $segments = $this->fetchWindow($job['car'], $job['from'], $job['to']);
                    $this->model->storeWindow($job['car'], $job['from'], $job['to'], $segments, $job['to'] < date('Y-m-d'));
                    $stats['windows_ok']++;
                    $stats['segments'] += count($segments);
                    $consecutiveErrors = 0;
                } catch (Throwable $exception) {
                    $stats['windows_error']++;
                    $consecutiveErrors++;
                    $this->model->markWindowError($job['car'], $job['from'], $job['to'], $exception->getMessage());
                    $this->log(sprintf('carId %d %s..%s: %s', $job['car'], $job['from'], $job['to'], $exception->getMessage()));
                    if ($consecutiveErrors >= self::MAX_CONSECUTIVE_ERRORS) {
                        $stats['aborted'] = true;
                        $stats['pending'] = count($queue) - $index - 1;
                        $this->log('Oprit dupa ' . self::MAX_CONSECUTIVE_ERRORS . ' erori consecutive (SasFleet indisponibil?).');
                        break;
                    }
                }
            }
            $this->persistSasSession();

            $stats['message'] = sprintf(
                '%d ferestre sincronizate, %d erori, %d segmente%s.',
                $stats['windows_ok'],
                $stats['windows_error'],
                $stats['segments'],
                $stats['pending'] > 0 ? ', ' . $stats['pending'] . ' ferestre rămase pentru rularea următoare' : ''
            );
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $stats;
    }

    /**
     * Maparea vehicul local ↔ carId SAS, prin numarul de inmatriculare (aceeasi
     * regula ca harta / dashboard-ul). La esec se pastreaza maparea existenta.
     */
    private function refreshMapping(): void
    {
        try {
            $cars = (new FleetLivePositionService($this->db, $this->client))->getFleetHierarchy()['cars'] ?? [];
        } catch (Throwable $exception) {
            $this->log('Lista vehiculelor SAS indisponibila, se foloseste maparea salvata: ' . $exception->getMessage());
            return;
        }
        $mapping = [];
        foreach ($cars as $car) {
            $localId = (int) ($car['local_vehicle_id'] ?? 0);
            $carId = (int) ($car['sas_vehicle_id'] ?? 0);
            if ($localId > 0 && $carId > 0 && empty($car['disabled'])) {
                $mapping[$localId] = ['sas_car_id' => $carId, 'plate' => (string) ($car['registration'] ?? '')];
            }
        }
        if ($mapping !== []) {
            $this->model->replaceSasMapping($mapping);
        }
    }

    /**
     * De la ce zi are nevoie fiecare carId: cel mai vechi baseline al planurilor care
     * il folosesc (propriul plan sau al remorcilor cuplate in perioada), limitat la
     * backfill_max_zile.
     *
     * @return array<int, string> carId => Y-m-d
     */
    private function computeNeeds(): array
    {
        $settings = $this->model->getSettings();
        $floor = date('Y-m-d', strtotime('-' . max(1, (int) $settings['backfill_max_zile']) . ' days'));
        $map = $this->model->getSasMapping();
        $vehicles = $this->model->getVehicles();
        $needs = [];
        $need = static function (int $carId, string $date) use (&$needs, $floor): void {
            $date = max($floor, $date);
            if (!isset($needs[$carId]) || $date < $needs[$carId]) {
                $needs[$carId] = $date;
            }
        };

        // Cel mai vechi T0 per vehicul, peste toate tipurile (ulei, placute, anvelope).
        $plans = [];
        foreach ($this->model->getAllPlans() as $plansOfType) {
            foreach ($plansOfType as $vehicleId => $plan) {
                $vehicle = $vehicles[$vehicleId] ?? null;
                $tab = ReviziiModel::tabOfTip((string) $plan['tip_revizie']);
                // Planuri care nu se mai aplica (ex. ulei pe semi-remorca) nu cer telemetrie.
                if ($vehicle === null || $tab === null
                    || !isset(ReviziiModel::itemsFor($tab, is_trailer_vehicle_type((string) $vehicle['tip_vehicul']))[(string) $plan['tip_revizie']])) {
                    continue;
                }
                if (!isset($plans[$vehicleId]) || $plan['baseline_at'] < $plans[$vehicleId]['baseline_at']) {
                    $plans[$vehicleId] = $plan;
                }
            }
        }
        $earliest = null;
        foreach ($plans as $vehicleId => $plan) {
            $baseline = substr((string) $plan['baseline_at'], 0, 10);
            $vehicle = $vehicles[$vehicleId] ?? null;
            if ($vehicle === null) {
                continue;
            }
            if (!is_trailer_vehicle_type((string) $vehicle['tip_vehicul'])) {
                if (isset($map[$vehicleId])) {
                    $need($map[$vehicleId], $baseline);
                }
                continue;
            }
            $earliest = $earliest === null || $plan['baseline_at'] < $earliest ? (string) $plan['baseline_at'] : $earliest;
        }

        if ($earliest !== null) {
            foreach ($this->model->getCouplings($earliest) as $coupling) {
                $plan = $plans[$coupling['semiremorca_id']] ?? null;
                $carId = $map[$coupling['tractor_id']] ?? null;
                if ($plan === null || $carId === null) {
                    continue;
                }
                if ($coupling['data_end'] !== null && $coupling['data_end'] <= $plan['baseline_at']) {
                    continue;
                }
                $need($carId, substr(max((string) $plan['baseline_at'], $coupling['data_start']), 0, 10));
            }
        }

        return $needs;
    }

    /**
     * Fereastra [$from 00:00, $to 23:59:59] pentru simulator, read-only (nu se salveaza),
     * in formatul motorului de calcul (timestamp-uri).
     *
     * @return array<int, array{start:int, end:int, km:float, idle:float}>
     */
    public function fetchForSimulation(int $carId, string $from, string $to): array
    {
        static $sessionRestored = false;
        if (!$sessionRestored) {
            $this->restoreSasSession();
            $sessionRestored = true;
        }
        $segments = $this->fetchWindow($carId, $from, $to);
        $this->persistSasSession();

        return array_map(static fn (array $s): array => [
            'start' => (int) strtotime($s['start']),
            'end' => (int) strtotime($s['end']),
            'km' => (float) $s['km'],
            'idle' => (float) $s['idle'],
        ], $segments);
    }

    /**
     * @return array<int, array{start:string, end:string, km:float, work:int, idle:int}>
     */
    private function fetchWindow(int $carId, string $from, string $to): array
    {
        $includesToday = $to >= date('Y-m-d');
        $sheet = $this->client->getTravelSheet($carId, $from . 'T00:00:00', $to . 'T23:59:59', !$includesToday);

        return self::parseTravelSheet($sheet);
    }

    /**
     * Segmentele dintr-un raspuns reports/travelsheet. Campuri folosite: segments[].dateStart,
     * dateEnd, distance, workSeconds, idleEngineSeconds. Un segment incomplet face fereastra
     * necunoscuta (exceptie), niciodata 0 km.
     *
     * @return array<int, array{start:string, end:string, km:float, work:int, idle:int}>
     */
    public static function parseTravelSheet(array $sheet): array
    {
        $rawSegments = $sheet['segments'] ?? null;
        if (!is_array($rawSegments)) {
            // Fara lista de segmente acceptam doar raspunsul explicit "nu a circulat".
            if (is_numeric($sheet['totalDistance'] ?? null) && (float) $sheet['totalDistance'] == 0.0) {
                return [];
            }
            throw new RuntimeException('Răspuns travelsheet fără segmente.');
        }

        $segments = [];
        foreach ($rawSegments as $segment) {
            if (!is_array($segment)) {
                continue;
            }
            $start = self::sasDate($segment['dateStart'] ?? null);
            $end = self::sasDate($segment['dateEnd'] ?? null);
            if ($start === null || $end === null || !is_numeric($segment['distance'] ?? null)) {
                // Un segment incomplet face fereastra necunoscuta, nu 0 km.
                throw new RuntimeException('Segment SAS incomplet (lipsesc data sau distanța).');
            }
            $segments[] = [
                'start' => $start,
                'end' => max($start, $end),
                'km' => round((float) $segment['distance'], 3),
                'work' => max(0, (int) ($segment['workSeconds'] ?? 0)),
                'idle' => max(0, (int) ($segment['idleEngineSeconds'] ?? 0)),
            ];
        }

        return $segments;
    }

    private static function sasDate(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $timestamp = strtotime(str_replace('T', ' ', substr(trim($value), 0, 19)));

        return $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : null;
    }

    private function restoreSasSession(): void
    {
        $file = $this->cacheDir . '/sas_session.json';
        $raw = is_file($file) ? @file_get_contents($file) : false;
        $session = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($session)) {
            $this->client->restoreState($session);
        }
    }

    private function persistSasSession(): void
    {
        @file_put_contents(
            $this->cacheDir . '/sas_session.json',
            json_encode($this->client->exportState(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    private function log(string $message): void
    {
        error_log('[ReviziiSasSync] ' . $message);
        if ($this->logger !== null) {
            ($this->logger)($message);
        }
    }
}
