<?php
declare(strict_types=1);

require_once __DIR__ . '/OdometerCalculator.php';
require_once __DIR__ . '/OdometerProviders.php';

/**
 * Centru Kilometraj — construieste starea paginii si executa sincronizarea SAS,
 * cautarea si salvarea T0.
 *
 * Identitate: fiecare vehicul fizic (cap tractor, semiremorca, camion, autoturism)
 * apare O SINGURA DATA. Un ansamblu e doar relatia de cuplaj (vehicule_cuplaje):
 * cardul lui arata cele doua componente, cu sursele si T0-ul fiecareia; semiremorca
 * cuplata nu mai apare separat. Km tractorului si ai semiremorcii nu se aduna.
 *
 * Total oficial: doar dupa politica aleasa explicit in setari (politica_total).
 * Fara politica, se afiseaza totalurile per sursa ca diagnostic, niciodata insumate.
 */
final class OdometerCenterService
{
    public const TYPE_LABELS = [
        'cap_tractor' => 'Cap tractor',
        'semiremorca_primar' => 'Semiremorcă primar',
        'semiremorca_distributie' => 'Semiremorcă distribuție',
        'semiremorca' => 'Semiremorcă',
        'camion' => 'Camion',
        'autovehicul' => 'Autoturism',
        'autoutilitara' => 'Autoutilitară',
    ];

    public const POLICY_LABELS = [
        '' => 'Neconfigurată',
        'sas' => 'SAS Fleet',
        'cardoil' => 'CardOil Refill',
        'sas_cardoil' => 'SAS Fleet, altfel CardOil',
    ];

    /** Dupa atatea erori SAS consecutive (API cazut) sincronizarea se opreste. */
    private const MAX_CONSECUTIVE_ERRORS = 3;
    /** Rezultatul unei cautari T0 ramane valabil pentru salvare atatea secunde. */
    private const LOOKUP_TTL_SECONDS = 900;
    /** Un vehicul verificat in SAS mai recent de atat nu se mai cere (butonul + cron-ul la 5 min). */
    private const SAS_RECHECK_SECONDS = 240;
    /** Fereastra in care o anomalie de citire se numara la KPI „Anomalii”. */
    private const ANOMALY_WINDOW_DAYS = 30;

    private PDO $db;
    private OdometerCenterModel $model;
    private SasOdometerSource $sas;
    private CardOilOdometerSource $cardOil;
    private string $cacheDir;
    /** @var callable|null */
    private $logger;
    private ?int $nowTs;

    public function __construct(
        PDO $db,
        ?SasOdometerSource $sas = null,
        ?CardOilOdometerSource $cardOil = null,
        ?callable $logger = null,
        ?int $nowTs = null
    ) {
        $this->db = $db;
        $this->model = new OdometerCenterModel($db);
        $this->model->ensureSchema();
        $this->sas = $sas ?? new SasFleetOdometerSource($db);
        $this->cardOil = $cardOil ?? new FuelFillupOdometerSource($this->model);
        $this->cacheDir = dirname(BASE_PATH) . '/storage/cache';
        $this->logger = $logger;
        $this->nowTs = $nowTs;
    }

    public function model(): OdometerCenterModel
    {
        return $this->model;
    }

    private function now(): int
    {
        return $this->nowTs ?? time();
    }

    private function nowSql(): string
    {
        return date('Y-m-d H:i:s', $this->now());
    }

    // ================================================================ pagina

    /**
     * Toata starea paginii: unitati (carduri), grupele de capacitate in ordinea
     * configurata, KPI-urile si setarile.
     *
     * @return array<string, mixed>
     */
    public function buildDashboard(): array
    {
        $settings = $this->model->getSettings();
        $vehicles = $this->model->getVehicles();
        $couplings = $this->model->getActiveCouplings();
        $sources = $this->model->getSources();
        $baselines = $this->model->getActiveBaselines();
        $sasReadings = $this->model->getRecentReadingsByVehicle('sas', 80);
        $fillups = $this->cardOil->fillupsByPlate();
        $cardOilSync = $this->cardOil->lastSync();

        $components = [];
        foreach ($vehicles as $id => $vehicle) {
            $components[$id] = $this->buildComponent(
                $vehicle,
                $settings,
                $sources[$id] ?? [],
                $baselines[$id] ?? [],
                $sasReadings[$id] ?? [],
                $fillups[self::plateKey((string) $vehicle['nr_inmatriculare'])] ?? [],
                $cardOilSync
            );
        }

        $units = $this->buildUnits($vehicles, $couplings, $components);
        $categories = (new VehicleCapacityCategoryModel($this->db))->getCategories();

        return [
            'generated_at' => $this->nowSql(),
            'settings' => $settings,
            'policy' => [
                'code' => $settings['politica_total'],
                'label' => self::POLICY_LABELS[$settings['politica_total']] ?? self::POLICY_LABELS[''],
                'options' => self::POLICY_LABELS,
            ],
            'categories' => array_map(static fn (array $c): array => [
                'id' => (int) $c['id'],
                'name' => (string) $c['nume'],
                'order' => (int) $c['ordine_afisare'],
                'active' => (bool) $c['activ'],
            ], $categories),
            'type_labels' => self::TYPE_LABELS,
            'units' => $units,
            'kpis' => $this->buildKpis($components, $settings),
            'sync' => [
                'sas_credentials' => $this->sas->credentialsAvailable(),
                'sas_last_checked_at' => $this->maxSasCheck($sources),
                'cardoil' => $cardOilSync,
            ],
        ];
    }

    /**
     * Starea unui vehicul fizic, cu cele doua surse independente.
     *
     * @return array<string, mixed>
     */
    private function buildComponent(
        array $vehicle,
        array $settings,
        array $sources,
        array $baselines,
        array $sasRows,
        array $fillups,
        ?array $cardOilSync
    ): array {
        $id = (int) $vehicle['id'];
        $type = (string) $vehicle['tip_vehicul'];
        $photo = (string) ($vehicle['poza_stocata'] ?? '');
        $thumb = function_exists('upload_image_thumb_url') ? upload_image_thumb_url('vehicule', $photo, 480) : null;
        $capacity = is_numeric($vehicle['capacitate_transport'] ?? null) && (float) $vehicle['capacitate_transport'] > 0
            ? (float) $vehicle['capacitate_transport'] : null;

        $sas = $this->buildSasSource($sources['sas'] ?? null, $baselines['sas'] ?? null, $sasRows, $settings);
        $cardOil = $this->buildCardOilSource($fillups, $baselines['cardoil'] ?? null, $cardOilSync, $settings, $type);

        $sasKm = $sas['distance']['km'] ?? null;
        $cardKm = $cardOil['distance']['km'] ?? null;
        $official = OdometerCalculator::officialDistance((string) $settings['politica_total'], $sasKm, $cardKm);

        $requested = null;
        $version = null;
        $okCount = 0;
        foreach ($baselines as $baseline) {
            $requested = (string) $baseline['requested_t0_at'];
            $version = (int) $baseline['baseline_version'];
            if ($baseline['retrieval_status'] === OdometerCalculator::STATUS_OK) {
                $okCount++;
            }
        }
        // O sursa conteaza doar daca are cel putin o citire reala (maparea singura nu ajunge).
        $availableCount = (int) ($sas['available'] && $sas['current'] !== null)
            + (int) ($cardOil['available'] && $cardOil['current'] !== null);

        $comparison = $this->compareSources($id, $sas, $cardOil, $sources['sas'] ?? null, $settings);

        $anomalies = array_merge($sas['anomalies'], $cardOil['anomalies']);
        if ($comparison['alert']) {
            $anomalies[] = ['source' => 'diferenta', 'at' => null, 'note' => sprintf(
                'Diferență SAS − CardOil de %s km (%s%%) pe aceeași fereastră.',
                number_format((float) $comparison['diff_km'], 0, ',', '.'),
                $comparison['diff_pct'] === null ? '—' : number_format((float) $comparison['diff_pct'], 1, ',', '.')
            )];
        }

        return [
            'id' => $id,
            'plate' => (string) $vehicle['nr_inmatriculare'],
            'make' => trim((string) ($vehicle['marca'] ?? '')),
            'model' => trim((string) ($vehicle['model'] ?? '')),
            'label' => trim((string) ($vehicle['marca'] ?? '') . ' ' . (string) ($vehicle['model'] ?? '')),
            'type' => $type,
            'type_label' => self::TYPE_LABELS[$type] ?? $type,
            'status' => (string) $vehicle['status'],
            'garage' => $vehicle['garaj'] ?? null,
            'capacity_t' => $capacity,
            'capacity_confirmed' => (int) ($vehicle['capacitate_transport_confirmata'] ?? 0) === 1,
            'category_id' => $vehicle['categorie_capacitate_id'] !== null ? (int) $vehicle['categorie_capacitate_id'] : null,
            'photo' => $thumb,
            'sources' => ['sas' => $sas, 'cardoil' => $cardOil],
            't0' => [
                'requested_at' => $requested,
                'version' => $version,
                'configured' => $okCount > 0,
                'partial' => $okCount > 0 && $okCount < $availableCount,
            ],
            'official' => $official,
            'comparison' => $comparison,
            'anomalies' => $anomalies,
            'monitored' => $availableCount > 0,
        ];
    }

    private function buildSasSource(?array $source, ?array $baseline, array $rows, array $settings): array
    {
        $result = $this->emptySource('sas', $baseline);
        if ($source === null || (int) $source['enabled'] !== 1) {
            $result['reason'] = $source === null
                ? 'Vehiculul nu este în flota SAS Fleet (fără mapare pe numărul de înmatriculare).'
                : 'Vehiculul nu mai apare activ în SAS Fleet.';
            return $result;
        }

        $series = OdometerCalculator::flagAnomalies(array_map(static fn (array $row): array => [
            'id' => (string) $row['source_reading_id'],
            'measured_at' => (string) $row['measured_at'],
            'segment_started_at' => $row['segment_started_at'],
            'km' => (float) $row['odometer_km'],
        ], $rows));

        $current = null;
        foreach ($series as $reading) {
            if ($reading['anomaly'] === null) {
                $current = $reading;
            }
        }

        $result['available'] = true;
        $result['external_id'] = (string) $source['external_provider_vehicle_id'];
        $result['last_checked_at'] = $source['last_checked_at'];
        $result['last_status'] = $source['last_status'];
        $result['last_error'] = $source['last_error'];
        $result['freshness'] = OdometerCalculator::sasFreshness(
            $source['last_checked_at'],
            $source['last_status'],
            $this->now(),
            (int) $settings['sas_prospetime_ore']
        );
        $result['current'] = $current !== null ? ['km' => $current['km'], 'measured_at' => $current['measured_at'], 'id' => $current['id']] : null;
        if ($current === null) {
            $result['reason'] = $source['last_checked_at'] === null
                ? 'Încă nesincronizat din SAS Fleet.'
                : 'SAS Fleet nu raportează odometru (kmIndex) pentru acest vehicul.';
        }
        $result['anomalies'] = $this->recentAnomalies('sas', $series);
        $result['distance'] = $this->distanceFor($result['current'], $result['baseline']);
        if ($result['distance']['anomaly'] !== null) {
            $result['anomalies'][] = ['source' => 'sas', 'at' => null, 'note' => $result['distance']['anomaly']];
        }

        return $result;
    }

    private function buildCardOilSource(array $fillups, ?array $baseline, ?array $sync, array $settings, string $type): array
    {
        $result = $this->emptySource('cardoil', $baseline);
        if ($fillups === []) {
            $result['reason'] = str_starts_with($type, 'semiremorca')
                ? 'Date indisponibile pentru această sursă: semiremorcile nu alimentează.'
                : 'Nicio alimentare CardOil cu odometru pentru acest număr.';
            return $result;
        }

        $series = OdometerCalculator::flagAnomalies($fillups);
        $current = null;
        foreach ($series as $reading) {
            if ($reading['anomaly'] === null) {
                $current = $reading;
            }
        }

        $syncAt = $sync['last_success_at'] ?? null;
        $syncFresh = $syncAt !== null
            && ($this->now() - OdometerCalculator::ts((string) $syncAt)) <= (int) $settings['cardoil_sync_max_ore'] * 3600;

        $result['available'] = true;
        $result['last_checked_at'] = $syncAt;
        $result['freshness'] = $syncFresh ? 'eveniment' : 'neactualizat';
        $result['current'] = $current !== null ? [
            'km' => $current['km'], 'measured_at' => $current['measured_at'], 'id' => $current['id'],
            'fuel_type' => $current['fuel_type'] ?? null, 'corrected' => $current['corrected'] ?? false,
        ] : null;
        if ($current === null) {
            $result['reason'] = 'Toate alimentările au odometru marcat ca anomalie.';
        }
        $result['anomalies'] = $this->recentAnomalies('cardoil', $series);
        $result['distance'] = $this->distanceFor($result['current'], $result['baseline']);
        if ($result['distance']['anomaly'] !== null) {
            $result['anomalies'][] = ['source' => 'cardoil', 'at' => null, 'note' => $result['distance']['anomaly']];
        }

        return $result;
    }

    private function emptySource(string $provider, ?array $baseline): array
    {
        return [
            'provider' => $provider,
            'available' => false,
            'reason' => null,
            'external_id' => null,
            'current' => null,
            'freshness' => null,
            'last_checked_at' => null,
            'last_status' => null,
            'last_error' => null,
            'baseline' => $baseline === null ? null : [
                'requested_at' => (string) $baseline['requested_t0_at'],
                'measured_at' => $baseline['measured_t0_at'],
                'km' => $baseline['baseline_value_km'] !== null ? (float) $baseline['baseline_value_km'] : null,
                'status' => (string) $baseline['retrieval_status'],
                'note' => $baseline['status_note'],
                'version' => (int) $baseline['baseline_version'],
                'source_reading_id' => $baseline['source_reading_id'],
                'created_by' => $baseline['created_by_name'] ?? null,
                'created_at' => (string) $baseline['created_at'],
            ],
            'distance' => ['km' => null, 'note' => null, 'anomaly' => null],
            'anomalies' => [],
        ];
    }

    private function distanceFor(?array $current, ?array $baseline): array
    {
        $usable = $baseline !== null && $baseline['status'] === OdometerCalculator::STATUS_OK && $baseline['km'] !== null
            ? ['km' => $baseline['km'], 'measured_at' => (string) $baseline['measured_at']]
            : null;
        if ($baseline !== null && $usable === null) {
            return ['km' => null, 'note' => (string) ($baseline['note'] ?? 'Referința T0 nu are citire validă.'), 'anomaly' => null];
        }

        return OdometerCalculator::distanceSinceT0($current, $usable);
    }

    /** @return list<array{source:string, at:?string, note:string}> */
    private function recentAnomalies(string $provider, array $series): array
    {
        $since = $this->now() - self::ANOMALY_WINDOW_DAYS * 86400;
        $result = [];
        foreach ($series as $reading) {
            if ($reading['anomaly'] !== null && OdometerCalculator::ts((string) $reading['measured_at']) >= $since) {
                $result[] = ['source' => $provider, 'at' => (string) $reading['measured_at'], 'note' => (string) $reading['anomaly']];
            }
        }

        return $result;
    }

    /**
     * SAS vs CardOil pe fereastra CardOil (alimentarea T0 -> ultima alimentare): odometrul
     * SAS la cele doua momente, din citirile SAS salvate, doar daca e exact (parcat) sau in toleranta.
     */
    private function compareSources(int $vehicleId, array $sas, array $cardOil, ?array $sasSource, array $settings): array
    {
        $cardBaseline = $cardOil['baseline'];
        $cardKm = $cardOil['distance']['km'];
        if (!$sas['available'] || $cardKm === null || $cardBaseline === null || $cardOil['current'] === null) {
            return OdometerCalculator::sourceDifference(null, null);
        }
        $tolerance = (int) $settings['sas_toleranta_minute'];
        $coverage = $sasSource['last_checked_at'] ?? null;
        $at = [];
        foreach (['from' => (string) $cardBaseline['measured_at'], 'to' => (string) $cardOil['current']['measured_at']] as $key => $moment) {
            $match = OdometerCalculator::sasReadingAt($this->model->getReadingsAround($vehicleId, 'sas', $moment), $moment, $tolerance, $coverage);
            if ($match['status'] !== OdometerCalculator::STATUS_OK) {
                return OdometerCalculator::sourceDifference(null, null);
            }
            $at[$key] = (float) $match['reading']['km'];
        }

        return OdometerCalculator::sourceDifference(round($at['to'] - $at['from'], 1), $cardKm);
    }

    /**
     * Cardurile: ansamblu = cap tractor + semiremorca cuplata activ (o singura data);
     * restul vehiculelor apar singure. Categoria ansamblului e a semiremorcii, cu
     * fallback pe a tractorului (regula din Dispecer curse / Categorii capacitate).
     */
    private function buildUnits(array $vehicles, array $couplings, array $components): array
    {
        $coupledTrailers = [];
        foreach ($couplings as $tractorId => $trailerId) {
            if (isset($vehicles[$tractorId], $vehicles[$trailerId]) && $vehicles[$tractorId]['tip_vehicul'] === 'cap_tractor') {
                $coupledTrailers[$trailerId] = $tractorId;
            }
        }

        $units = [];
        foreach ($vehicles as $id => $vehicle) {
            if (isset($coupledTrailers[$id])) {
                continue;
            }
            $trailerId = $vehicle['tip_vehicul'] === 'cap_tractor' ? ($couplings[$id] ?? null) : null;
            if ($trailerId !== null && !isset($coupledTrailers[$trailerId])) {
                $trailerId = null;
            }
            $main = $components[$id];
            if ($trailerId !== null) {
                $trailer = $components[$trailerId];
                $categoryId = $trailer['category_id'] ?? $main['category_id'];
                $units[] = [
                    'key' => 'a' . $id,
                    'kind' => 'ansamblu',
                    'status' => $main['status'] === 'activ' || $trailer['status'] === 'activ' ? 'activ' : 'inactiv',
                    'category_id' => $categoryId,
                    'capacity_t' => $trailer['capacity_t'],
                    'type' => 'ansamblu',
                    'components' => [$main, $trailer],
                ];
                continue;
            }
            $units[] = [
                'key' => 'v' . $id,
                'kind' => 'vehicul',
                'status' => $main['status'],
                'category_id' => $main['category_id'],
                'capacity_t' => $main['capacity_t'],
                'type' => $main['type'],
                'components' => [$main],
            ];
        }

        return $units;
    }

    /** KPI-urile: fiecare vehicul fizic activ o singura data; surse niciodata insumate intre ele. */
    private function buildKpis(array $components, array $settings): array
    {
        $active = array_filter($components, static fn (array $c): bool => $c['status'] === 'activ');
        $monitored = array_filter($active, static fn (array $c): bool => $c['monitored']);
        $withT0 = array_filter($monitored, static fn (array $c): bool => $c['t0']['configured']);
        $partial = array_filter($withT0, static fn (array $c): bool => $c['t0']['partial']);

        $official = 0.0;
        $officialCount = 0;
        $excluded = 0;
        $perSource = ['sas' => 0.0, 'cardoil' => 0.0];
        $perSourceCount = ['sas' => 0, 'cardoil' => 0];
        $stale = 0;
        $oldestStaleTs = null;
        $anomalies = 0;
        foreach ($monitored as $component) {
            if ($component['official']['km'] !== null) {
                $official += (float) $component['official']['km'];
                $officialCount++;
            } elseif ($component['t0']['configured']) {
                $excluded++;
            }
            foreach (['sas', 'cardoil'] as $provider) {
                $km = $component['sources'][$provider]['distance']['km'];
                if ($km !== null) {
                    $perSource[$provider] += (float) $km;
                    $perSourceCount[$provider]++;
                }
            }
            $isStale = false;
            foreach (['sas', 'cardoil'] as $provider) {
                $source = $component['sources'][$provider];
                if ($source['available'] && $source['current'] !== null
                    && in_array($source['freshness'], ['neactualizat', 'eroare', 'nesincronizat'], true)) {
                    $isStale = true;
                    $checked = $source['last_checked_at'] !== null ? OdometerCalculator::ts((string) $source['last_checked_at']) : null;
                    if ($checked !== null && ($oldestStaleTs === null || $checked < $oldestStaleTs)) {
                        $oldestStaleTs = $checked;
                    }
                }
            }
            $stale += $isStale ? 1 : 0;
            $anomalies += $component['anomalies'] !== [] ? 1 : 0;
        }

        $policy = (string) $settings['politica_total'];

        return [
            'monitored' => count($monitored),
            'active' => count($active),
            'with_t0' => count($withT0),
            'with_t0_partial' => count($partial),
            'with_t0_pct' => count($monitored) > 0 ? round(count($withT0) / count($monitored) * 100, 1) : 0.0,
            'without_t0' => count($monitored) - count($withT0),
            'without_t0_pct' => count($monitored) > 0 ? round((count($monitored) - count($withT0)) / count($monitored) * 100, 1) : 0.0,
            'official_policy' => $policy,
            'official_km' => $policy !== '' ? round($official, 1) : null,
            'official_count' => $officialCount,
            'official_excluded' => $excluded,
            'per_source_km' => array_map(static fn (float $v): float => round($v, 1), $perSource),
            'per_source_count' => $perSourceCount,
            'stale' => $stale,
            'stale_oldest_hours' => $oldestStaleTs !== null ? (int) floor(($this->now() - $oldestStaleTs) / 3600) : null,
            'anomalies' => $anomalies,
        ];
    }

    private function maxSasCheck(array $sources): ?string
    {
        $max = null;
        foreach ($sources as $bySource) {
            $checked = $bySource['sas']['last_checked_at'] ?? null;
            if ($checked !== null && ($max === null || $checked > $max)) {
                $max = (string) $checked;
            }
        }

        return $max;
    }

    // ================================================================ sincronizare SAS

    /**
     * Aduce citirile SAS noi. Idempotenta (citirile au identitate stabila), cu lacat
     * (o singura rulare odata), pauza intre cereri, oprire dupa erori consecutive.
     * Prioritate: vehiculele verificate cel mai demult. O eroare pastreaza ultima
     * citire, dar vehiculul nu mai e prezentat ca actual.
     * $onlyVehicleIds restrange rularea (si maparea) la acele vehicule; restul raman neatinse.
     *
     * @param list<int>|null $onlyVehicleIds
     * @return array<string, mixed>
     */
    public function syncSas(int $maxCalls = 200, int $maxSeconds = 0, ?array $onlyVehicleIds = null): array
    {
        $stats = ['calls' => 0, 'updated' => 0, 'errors' => 0, 'new_readings' => 0, 'pending' => 0,
            'aborted' => false, 'locked' => false, 'changed' => [], 'message' => ''];
        if (!$this->sas->credentialsAvailable()) {
            $stats['message'] = 'Credențialele SAS nu sunt configurate.';
            return $stats;
        }
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
        $lock = @fopen($this->cacheDir . '/centru_kilometraj_sync.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            $stats['locked'] = true;
            $stats['message'] = 'O altă sincronizare este în curs.';
            return $stats;
        }

        try {
            $startedAt = time();
            $this->refreshMappings($onlyVehicleIds);
            $settings = $this->model->getSettings();
            $only = $onlyVehicleIds !== null ? array_flip(array_map('intval', $onlyVehicleIds)) : null;
            $historyDays = max(1, (int) $settings['sas_istoric_zile']);

            $queue = [];
            foreach ($this->model->getSources() as $vehicleId => $bySource) {
                $source = $bySource['sas'] ?? null;
                if ($source === null || (int) $source['enabled'] !== 1 || ($only !== null && !isset($only[$vehicleId]))) {
                    continue;
                }
                // Doar vehiculele „scadente”: astfel „rămase” scade la 0 intre loturi.
                $due = $source['last_checked_at'] === null
                    || $source['last_status'] === 'eroare'
                    || $this->now() - OdometerCalculator::ts((string) $source['last_checked_at']) >= self::SAS_RECHECK_SECONDS;
                if ($due) {
                    $queue[] = $source + ['vehicle_id' => $vehicleId];
                }
            }
            usort($queue, static fn (array $a, array $b): int => strcmp((string) $a['last_checked_at'], (string) $b['last_checked_at']));

            $consecutiveErrors = 0;
            foreach ($queue as $index => $source) {
                if ($stats['calls'] >= $maxCalls || ($maxSeconds > 0 && time() - $startedAt >= $maxSeconds)) {
                    $stats['pending'] = count($queue) - $index;
                    break;
                }
                $vehicleId = (int) $source['vehicle_id'];
                $carId = (int) $source['external_provider_vehicle_id'];
                $to = $this->nowSql();
                $earliest = date('Y-m-d H:i:s', $this->now() - $historyDays * 86400);
                $from = $source['last_checked_at'] !== null
                    ? max($earliest, date('Y-m-d H:i:s', OdometerCalculator::ts((string) $source['last_checked_at']) - 7200))
                    : $earliest;
                // SAS intoarce kmIndex doar cand fereastra cuprinde mai mult de o zi calendaristica
                // (verificat 22.10.2026: de la azi 00:00 -> kmIndex null; de la ieri -> prezent).
                $from = min($from, date('Y-m-d 00:00:00', $this->now() - 86400));

                $stats['calls']++;
                try {
                    $data = $this->sas->fetchReadings($carId, $from, $to);
                    $inserted = $this->model->storeReadings($vehicleId, 'sas', $data['readings']);
                    $this->revalidateStored($vehicleId);
                    $this->model->markSourceChecked($vehicleId, 'sas', 'ok', null, $to);
                    $stats['updated']++;
                    $stats['new_readings'] += $inserted;
                    if ($inserted > 0) {
                        $stats['changed'][] = $vehicleId;
                    }
                    $consecutiveErrors = 0;
                } catch (Throwable $exception) {
                    $this->model->markSourceChecked($vehicleId, 'sas', 'eroare', $exception->getMessage());
                    $stats['errors']++;
                    $this->log('carId=' . $carId . ': ' . $exception->getMessage());
                    if (++$consecutiveErrors >= self::MAX_CONSECUTIVE_ERRORS) {
                        $stats['aborted'] = true;
                        $stats['pending'] = count($queue) - $index - 1;
                        $stats['message'] = 'SAS Fleet nu răspunde (' . $exception->getMessage() . '). Ultimele citiri au fost păstrate.';
                        break;
                    }
                }
            }
            if ($stats['message'] === '') {
                $stats['message'] = $queue === []
                    ? 'Toate vehiculele au fost verificate în SAS în ultimele minute.'
                    : sprintf('%d vehicule verificate în SAS, %d citiri noi%s.', $stats['updated'], $stats['new_readings'],
                        $stats['pending'] > 0 ? ', ' . $stats['pending'] . ' rămase' : '');
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $stats;
    }

    /**
     * Maparea vehicul -> furnizor. SAS: dupa numarul de inmatriculare (FleetLivePositionService,
     * ca in restul aplicatiei). CardOil: numarul de inmatriculare normalizat al alimentarilor.
     * Cu $onlyVehicleIds se actualizeaza doar acele vehicule si nu se dezactiveaza nimic.
     *
     * @param list<int>|null $onlyVehicleIds
     */
    public function refreshMappings(?array $onlyVehicleIds = null): void
    {
        $only = $onlyVehicleIds !== null ? array_flip(array_map('intval', $onlyVehicleIds)) : null;
        try {
            $cars = $this->sas->fleetCars();
        } catch (Throwable $exception) {
            $this->log('Lista vehiculelor SAS indisponibilă, se folosește maparea salvată: ' . $exception->getMessage());
            $cars = null;
        }
        if ($cars !== null) {
            $kept = [];
            foreach ($cars as $car) {
                if ($car['local_vehicle_id'] !== null && $car['local_vehicle_id'] > 0 && $car['sas_vehicle_id'] > 0 && !$car['disabled']
                    && ($only === null || isset($only[$car['local_vehicle_id']]))) {
                    $this->model->upsertSource($car['local_vehicle_id'], 'sas', (string) $car['sas_vehicle_id']);
                    $kept[] = $car['local_vehicle_id'];
                }
            }
            if ($kept !== [] && $only === null) {
                $this->model->disableMissingSources('sas', $kept);
            }
        }

        $vehicleByPlate = [];
        foreach ($this->model->getVehicles() as $id => $vehicle) {
            $vehicleByPlate[self::plateKey((string) $vehicle['nr_inmatriculare'])] = $id;
        }
        foreach (array_keys($this->cardOil->fillupsByPlate()) as $plateKey) {
            if (isset($vehicleByPlate[$plateKey]) && ($only === null || isset($only[$vehicleByPlate[$plateKey]]))) {
                $this->model->upsertSource($vehicleByPlate[$plateKey], 'cardoil', $plateKey);
            }
        }
    }

    /** Recalculeaza anomaliile pe seria SAS salvata (o corectie de date poate anula o anomalie). */
    private function revalidateStored(int $vehicleId): void
    {
        $rows = $this->model->getReadings($vehicleId, 'sas', null, null, 400);
        $series = OdometerCalculator::flagAnomalies(array_map(static fn (array $row): array => [
            'id' => (string) $row['source_reading_id'],
            'measured_at' => (string) $row['measured_at'],
            'km' => (float) $row['odometer_km'],
            'stored_note' => $row['validation_note'],
        ], $rows));
        foreach ($series as $reading) {
            if ($reading['anomaly'] !== $reading['stored_note']) {
                $this->model->setReadingValidation($vehicleId, 'sas', $reading['id'], $reading['anomaly']);
            }
        }
    }

    // ================================================================ T0

    /**
     * Cauta, pentru fiecare vehicul si fiecare sursa disponibila, citirea istorica de
     * la T0. Sursele se cauta independent; o sursa lipsa nu blocheaza cealalta.
     * Citirile SAS aduse raman salvate (istoric), fara sa schimbe vreo referinta.
     *
     * @param list<int> $vehicleIds
     * @return array<int, array<string, mixed>> [vehicle_id] => ['sas' => rezultat, 'cardoil' => rezultat]
     */
    public function lookupT0(array $vehicleIds, string $t0): array
    {
        $t0 = self::normalizeT0($t0, $this->now());
        $settings = $this->model->getSettings();
        $vehicles = $this->model->getVehicles();
        $sources = $this->model->getSources();
        $results = [];

        foreach (array_values(array_unique(array_map('intval', $vehicleIds))) as $vehicleId) {
            if (!isset($vehicles[$vehicleId])) {
                throw new InvalidArgumentException('Vehicul inexistent: ' . $vehicleId);
            }
            $plateKey = self::plateKey((string) $vehicles[$vehicleId]['nr_inmatriculare']);
            $results[$vehicleId] = [
                'sas' => $this->lookupSasT0($vehicleId, $sources[$vehicleId]['sas'] ?? null, $t0, $settings),
                'cardoil' => $this->lookupCardOilT0($plateKey, (string) $vehicles[$vehicleId]['tip_vehicul'], $t0, $settings),
            ];
            $this->rememberLookup($vehicleId, $t0, $results[$vehicleId]);
        }

        return $results;
    }

    private function lookupSasT0(int $vehicleId, ?array $source, string $t0, array $settings): array
    {
        if ($source === null || (int) $source['enabled'] !== 1) {
            return ['status' => OdometerCalculator::STATUS_NO_SOURCE, 'reading' => null, 'precision' => null,
                'note' => 'Date indisponibile pentru această sursă: vehiculul nu este în SAS Fleet.'];
        }
        if (!$this->sas->credentialsAvailable()) {
            return ['status' => OdometerCalculator::STATUS_UNAVAILABLE, 'reading' => null, 'precision' => null,
                'note' => 'Credențialele SAS nu sunt configurate.'];
        }
        $t0Ts = OdometerCalculator::ts($t0);
        $from = date('Y-m-d H:i:s', $t0Ts - max(1, (int) $settings['sas_istoric_zile']) * 86400);
        $to = date('Y-m-d H:i:s', min($this->now(), $t0Ts + 86400));
        try {
            $data = $this->sas->fetchReadings((int) $source['external_provider_vehicle_id'], $from, $to);
        } catch (Throwable $exception) {
            $this->log('T0 carId=' . $source['external_provider_vehicle_id'] . ': ' . $exception->getMessage());
            return ['status' => OdometerCalculator::STATUS_UNAVAILABLE, 'reading' => null, 'precision' => null,
                'note' => 'SAS Fleet nu a răspuns: ' . $exception->getMessage()];
        }
        $this->model->storeReadings($vehicleId, 'sas', $data['readings']);

        $readings = array_map(static fn (array $r): array => [
            'id' => (string) $r['source_reading_id'],
            'measured_at' => (string) $r['measured_at'],
            'segment_started_at' => $r['segment_started_at'],
            'km' => (float) $r['odometer_km'],
            'original_value' => (float) $r['original_value'],
        ], $data['readings']);
        $valid = array_values(array_filter(
            OdometerCalculator::flagAnomalies($readings),
            static fn (array $r): bool => $r['anomaly'] === null
        ));

        return OdometerCalculator::sasReadingAt($valid, $t0, (int) $settings['sas_toleranta_minute'], $to, $data['movements']);
    }

    private function lookupCardOilT0(string $plateKey, string $type, string $t0, array $settings): array
    {
        $fillups = $this->cardOil->fillupsByPlate([$plateKey])[$plateKey] ?? [];
        if ($fillups === []) {
            return ['status' => OdometerCalculator::STATUS_NO_SOURCE, 'reading' => null, 'precision' => null,
                'note' => str_starts_with($type, 'semiremorca')
                    ? 'Date indisponibile pentru această sursă: semiremorcile nu alimentează.'
                    : 'Date indisponibile pentru această sursă: nicio alimentare CardOil cu odometru.'];
        }

        return OdometerCalculator::cardOilReadingAt(
            OdometerCalculator::flagAnomalies($fillups),
            $t0,
            (int) $settings['cardoil_varsta_max_ore']
        );
    }

    /**
     * Salveaza T0 (versiune noua) pentru fiecare vehicul. Citirile se iau din cautarea
     * facuta in fereastra (aceeasi sesiune, ultimele 15 min) sau se cauta din nou —
     * niciodata din valorile trimise de browser. Sursele fara date raman inregistrate cu motivul.
     *
     * @param list<int> $vehicleIds
     * @return array<int, array{version:?int, results:array, skipped:?string}>
     */
    public function saveT0(array $vehicleIds, string $t0, ?int $userId, ?string $reason): array
    {
        $t0 = self::normalizeT0($t0, $this->now());
        $saved = [];
        foreach (array_values(array_unique(array_map('intval', $vehicleIds))) as $vehicleId) {
            $results = $this->recallLookup($vehicleId, $t0) ?? ($this->lookupT0([$vehicleId], $t0)[$vehicleId]);
            $toStore = array_filter($results, static fn (array $r): bool => $r['status'] !== OdometerCalculator::STATUS_NO_SOURCE);
            if ($toStore === []) {
                $saved[$vehicleId] = ['version' => null, 'results' => $results,
                    'skipped' => 'Nicio sursă de kilometraj pentru acest vehicul: T0 nu are ce referință să păstreze.'];
                continue;
            }
            $saved[$vehicleId] = [
                'version' => $this->model->saveBaselineVersion($vehicleId, $t0, $toStore, $userId, $reason),
                'results' => $results,
                'skipped' => null,
            ];
        }

        return $saved;
    }

    public function savePolicy(string $policy, ?int $userId): void
    {
        if (!array_key_exists($policy, self::POLICY_LABELS)) {
            throw new InvalidArgumentException('Politică necunoscută.');
        }
        $old = $this->model->getSettings()['politica_total'];
        if ($old === $policy) {
            return;
        }
        $this->model->saveSetting('politica_total', $policy, $userId);
        $this->model->logAudit(null, $userId, 'set_policy', ['politica_total' => $old], ['politica_total' => $policy]);
    }

    public static function normalizeT0(string $t0, int $nowTs): string
    {
        $ts = strtotime(str_replace('T', ' ', trim($t0)));
        if ($ts === false || $ts < strtotime('2020-01-01')) {
            throw new InvalidArgumentException('Data/ora T0 este invalidă.');
        }
        if ($ts > $nowTs) {
            throw new InvalidArgumentException('T0 nu poate fi în viitor.');
        }

        return date('Y-m-d H:i:00', $ts);
    }

    private function rememberLookup(int $vehicleId, string $t0, array $result): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['km_center_t0_lookup'][$vehicleId . '|' . $t0] = ['at' => time(), 'result' => $result];
        }
    }

    private function recallLookup(int $vehicleId, string $t0): ?array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }
        $entry = $_SESSION['km_center_t0_lookup'][$vehicleId . '|' . $t0] ?? null;
        if (!is_array($entry) || time() - (int) $entry['at'] > self::LOOKUP_TTL_SECONDS) {
            return null;
        }

        return $entry['result'];
    }

    // ================================================================ detalii

    /**
     * Istoricul unui vehicul: citirile SAS (ultimele $days zile) si CardOil (toate
     * alimentarile cu odometru), fiecare cu anomaliile ei, versiunile T0 si auditul.
     *
     * @return array<string, mixed>
     */
    public function vehicleHistory(int $vehicleId, int $days, bool $withAudit): array
    {
        $vehicles = $this->model->getVehicles();
        if (!isset($vehicles[$vehicleId])) {
            throw new InvalidArgumentException('Vehicul inexistent.');
        }
        $vehicle = $vehicles[$vehicleId];
        $from = date('Y-m-d H:i:s', $this->now() - max(1, $days) * 86400);

        $sas = OdometerCalculator::flagAnomalies(array_map(static fn (array $row): array => [
            'id' => (string) $row['source_reading_id'],
            'measured_at' => (string) $row['measured_at'],
            'received_at' => (string) $row['received_at'],
            'km' => (float) $row['odometer_km'],
        ], $this->model->getReadings($vehicleId, 'sas', $from)));

        $plateKey = self::plateKey((string) $vehicle['nr_inmatriculare']);
        $cardOil = OdometerCalculator::flagAnomalies($this->cardOil->fillupsByPlate([$plateKey])[$plateKey] ?? []);

        return [
            'vehicle_id' => $vehicleId,
            'plate' => (string) $vehicle['nr_inmatriculare'],
            'days' => $days,
            'sas' => array_values(array_reverse($sas)),
            'cardoil' => array_values(array_reverse($cardOil)),
            'baselines' => $this->model->getBaselineHistory($vehicleId),
            'audit' => $withAudit ? $this->model->getAudit($vehicleId, 100) : null,
        ];
    }

    public static function plateKey(string $plate): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $plate) ?? '');
    }

    private function log(string $message): void
    {
        error_log('[CentruKilometraj] ' . $message);
        if ($this->logger !== null) {
            ($this->logger)($message);
        }
    }
}
