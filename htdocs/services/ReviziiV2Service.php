<?php
declare(strict_types=1);

require_once __DIR__ . '/ReviziiV2Engine.php';
require_once dirname(__DIR__) . '/models/ReviziiV2Model.php';

/**
 * Revizii V2 — spatiul de lucru unic: planuri X / Y (secventa calculata din km), scadente pe operatiuni,
 * lucrari (evenimente de revizie), executii independente, istoric, configurare, import.
 *
 * Surse de date (doar ce exista deja, fara apeluri API la incarcarea paginii):
 *  - km: vehicle_odometer_readings — CAN (raportul web SAS) are prioritate, GPS (kmIndex SAS)
 *    e fallback declarat; nu se aduna niciodata. Semi-remorcile folosesc contorul vehicule.km_bord;
 *  - ore motor / ralanti: revizii_sas_segmente (work_seconds = timp total cu motorul pornit,
 *    include ralantiul; idle_seconds = ralanti, submultime — NU se aduna la ore motor);
 *  - calendar: datele executiilor (functioneaza fara telemetrie);
 *  - stoc: ConsumabileStockService::operate (idempotent, tranzactional).
 */
final class ReviziiV2Service
{
    private ReviziiV2Model $model;
    /** @var array<string, mixed>|null */
    private ?array $cache = null;
    /** @var array<string, ?float> */
    private array $readingAtCache = [];

    public function __construct(private PDO $db)
    {
        $this->model = new ReviziiV2Model($db);
    }

    public function model(): ReviziiV2Model
    {
        return $this->model;
    }

    // =====================================================================
    // Incarcare
    // =====================================================================

    /** @return array<string, mixed> */
    private function load(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $vehicles = $this->model->vehicles();
        $ids = array_keys($vehicles);
        $this->cache = [
            'settings' => $this->model->settings(),
            'vehicles' => $vehicles,
            'assignments' => $this->model->assignments(),
            'vehicle_rules' => $this->model->vehicleRules(),
            'operations' => $this->model->operations(),
            'templates' => $this->model->templates(),
            'plans' => $this->model->plans(),
            'complete' => $this->model->completeRevisions(),
            'open' => $this->model->openWorkOrders(),
            'resets' => $this->model->lastResets(),
            'avg_costs' => $this->model->averageCosts(),
            'readings' => $this->latestReadings($ids),
            'daily_km' => $this->dailyKm($ids),
            'hours' => $this->hoursData(),
        ];

        return $this->cache;
    }

    public function reset(): void
    {
        $this->cache = null;
        $this->readingAtCache = [];
    }

    /** @return array<int, array<string, array{km: float, at: string}>> */
    private function latestReadings(array $vehicleIds): array
    {
        if ($vehicleIds === [] || !$this->tableExists('vehicle_odometer_readings')) {
            return [];
        }
        $in = implode(',', array_map('intval', $vehicleIds));
        $rows = $this->db->query(
            "SELECT r.vehicle_id, r.provider_code, r.odometer_km, r.measured_at
             FROM vehicle_odometer_readings r
             JOIN (SELECT vehicle_id, provider_code, MAX(measured_at) AS last_at FROM vehicle_odometer_readings
                   WHERE vehicle_id IN ($in) AND provider_code IN ('can', 'sas') AND validation_status = 'valid'
                   GROUP BY vehicle_id, provider_code) x
               ON x.vehicle_id = r.vehicle_id AND x.provider_code = r.provider_code AND x.last_at = r.measured_at
             WHERE r.validation_status = 'valid'"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['vehicle_id']][(string) $row['provider_code']] = ['km' => (float) $row['odometer_km'], 'at' => (string) $row['measured_at']];
        }

        return $result;
    }

    /** Km medii pe zi (ultimele 30 de zile, citiri valide) — doar pentru estimarea datei scadentei. */
    private function dailyKm(array $vehicleIds): array
    {
        if ($vehicleIds === [] || !$this->tableExists('vehicle_odometer_readings')) {
            return [];
        }
        $in = implode(',', array_map('intval', $vehicleIds));
        $stmt = $this->db->prepare(
            "SELECT vehicle_id, provider_code, MIN(measured_at) AS a, MAX(measured_at) AS b, MIN(odometer_km) AS kmin, MAX(odometer_km) AS kmax
             FROM vehicle_odometer_readings
             WHERE vehicle_id IN ($in) AND provider_code IN ('can', 'sas') AND validation_status = 'valid' AND measured_at >= :f
             GROUP BY vehicle_id, provider_code"
        );
        $stmt->execute([':f' => date('Y-m-d H:i:s', time() - 30 * 86400)]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $days = ((int) strtotime((string) $row['b']) - (int) strtotime((string) $row['a'])) / 86400;
            if ($days >= 3) {
                $rate = ((float) $row['kmax'] - (float) $row['kmin']) / $days;
                $current = $result[(int) $row['vehicle_id']] ?? null;
                if ($current === null || $row['provider_code'] === 'can') {
                    $result[(int) $row['vehicle_id']] = round($rate, 1);
                }
            }
        }

        return $result;
    }

    /**
     * Orele SAS pe zile (motor total + ralanti) si zilele acoperite, per vehicul.
     *
     * @return array{map: array<int, int>, days: array<int, array<string, array{0: int, 1: int}>>, first_ok: array<int, string>, ok: array<int, array<string, bool>>, last_sync: ?string}
     */
    private function hoursData(): array
    {
        $result = ['map' => [], 'days' => [], 'first_ok' => [], 'ok' => [], 'last_sync' => null];
        if (!$this->tableExists('revizii_sas_vehicule') || !$this->tableExists('revizii_sas_segmente')) {
            return $result;
        }
        foreach ($this->db->query('SELECT vehicle_id, sas_car_id FROM revizii_sas_vehicule')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result['map'][(int) $row['vehicle_id']] = (int) $row['sas_car_id'];
        }
        foreach ($this->db->query('SELECT sas_car_id, DATE(date_start) AS d, SUM(work_seconds) AS w, SUM(idle_seconds) AS i FROM revizii_sas_segmente GROUP BY sas_car_id, DATE(date_start)')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result['days'][(int) $row['sas_car_id']][(string) $row['d']] = [(int) $row['w'], (int) $row['i']];
        }
        if ($this->tableExists('revizii_sas_acoperire')) {
            foreach ($this->db->query("SELECT sas_car_id, zi FROM revizii_sas_acoperire WHERE status = 'ok' ORDER BY zi")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $car = (int) $row['sas_car_id'];
                $result['first_ok'][$car] ??= (string) $row['zi'];
                $result['ok'][$car][(string) $row['zi']] = true;
            }
            $last = $this->db->query("SELECT MAX(fetched_at) FROM revizii_sas_acoperire WHERE status = 'ok'")->fetchColumn();
            $result['last_sync'] = $last !== false && $last !== null ? (string) $last : null;
        }

        return $result;
    }

    // =====================================================================
    // Telemetria unui vehicul
    // =====================================================================

    /**
     * Km curent dupa politica de sursa: auto = CAN valid si proaspat, altfel GPS (declarat);
     * semi-remorca = contorul vehiculului. Nu se aduna CAN + GPS.
     *
     * @return array{value: ?float, source: ?string, at: ?string, stale: bool, note: ?string}
     */
    private function currentKm(array $vehicle, string $policy): array
    {
        $data = $this->load();
        $staleHours = max(1, (int) $data['settings']['date_vechi_ore']);
        $readings = $data['readings'][(int) $vehicle['id']] ?? [];
        $fresh = static fn (?array $r): bool => $r !== null && (time() - (int) strtotime($r['at'])) <= $staleHours * 3600;
        $can = $readings['can'] ?? null;
        $gps = $readings['sas'] ?? null;

        if ($vehicle['familie'] === 'semiremorca') {
            return ['value' => (float) $vehicle['km_bord'] > 0 ? (float) $vehicle['km_bord'] : null, 'source' => 'contor', 'at' => null, 'stale' => false,
                'note' => 'Semi-remorcă: contorul vehiculului (km tractorului cuplat).'];
        }
        $pick = match ($policy) {
            'can' => $can !== null ? ['can', $can, null] : null,
            'gps' => $gps !== null ? ['gps', $gps, null] : null,
            default => match (true) {
                $fresh($can) => ['can', $can, null],
                $fresh($gps) => ['gps', $gps, $can !== null ? 'CAN învechit: s-a folosit GPS (fallback).' : 'Fără CAN: s-a folosit GPS (fallback).'],
                $can !== null && ($gps === null || $can['at'] >= $gps['at']) => ['can', $can, null],
                $gps !== null => ['gps', $gps, 'Fără CAN: s-a folosit GPS (fallback).'],
                default => null,
            },
        };
        if ($pick === null) {
            return ['value' => null, 'source' => null, 'at' => null, 'stale' => true, 'note' => 'Nicio citire de odometru validă.'];
        }
        [$source, $reading, $note] = $pick;

        return ['value' => $reading['km'], 'source' => $source, 'at' => $reading['at'], 'stale' => !$fresh($reading), 'note' => $note];
    }

    /** Citirea odometrului la un moment (ultima valida in 7 zile inainte); pentru referinte fara km. */
    private function readingAt(int $vehicleId, string $source, string $at): ?float
    {
        $provider = $source === 'can' ? 'can' : 'sas';
        $key = $vehicleId . '|' . $provider . '|' . $at;
        if (array_key_exists($key, $this->readingAtCache)) {
            return $this->readingAtCache[$key];
        }
        $value = null;
        if ($this->tableExists('vehicle_odometer_readings')) {
            $stmt = $this->db->prepare(
                "SELECT odometer_km FROM vehicle_odometer_readings WHERE vehicle_id = :v AND provider_code = :p AND validation_status = 'valid'
                   AND measured_at <= :at AND measured_at >= :from ORDER BY measured_at DESC LIMIT 1"
            );
            $stmt->execute([':v' => $vehicleId, ':p' => $provider, ':at' => $at, ':from' => date('Y-m-d H:i:s', (int) strtotime($at) - 7 * 86400)]);
            $km = $stmt->fetchColumn();
            $value = $km !== false ? (float) $km : null;
        }

        return $this->readingAtCache[$key] = $value;
    }

    /**
     * Ore motor si ralanti SAS de la o data (zile intregi dupa ziua referintei).
     *
     * @return array{ore: ?float, idle: ?float, partial: bool, note: ?string}
     */
    private function hoursSince(int $vehicleId, ?string $since): array
    {
        $hours = $this->load()['hours'];
        $car = $hours['map'][$vehicleId] ?? null;
        if ($car === null) {
            return ['ore' => null, 'idle' => null, 'partial' => false, 'note' => 'Vehiculul nu are ore motor SAS sincronizate.'];
        }
        if ($since === null) {
            return ['ore' => null, 'idle' => null, 'partial' => false, 'note' => 'Fără referință pentru ore.'];
        }
        $first = $hours['first_ok'][$car] ?? null;
        $sinceDay = substr($since, 0, 10);
        if ($first === null || $first > date('Y-m-d', (int) strtotime($sinceDay . ' +1 day'))) {
            return ['ore' => null, 'idle' => null, 'partial' => false, 'note' => 'Orele SAS acoperă doar de la ' . ($first !== null ? date('d.m.Y', (int) strtotime($first)) : '—') . '.'];
        }
        $work = 0;
        $idle = 0;
        foreach ($hours['days'][$car] ?? [] as $day => [$w, $i]) {
            if ($day > $sinceDay) {
                $work += $w;
                $idle += $i;
            }
        }
        $missing = 0;
        $today = date('Y-m-d');
        for ($day = date('Y-m-d', (int) strtotime($sinceDay . ' +1 day')); $day < $today; $day = date('Y-m-d', (int) strtotime($day . ' +1 day'))) {
            if (empty($hours['ok'][$car][$day])) {
                $missing++;
            }
            if ($missing > 400) {
                break;
            }
        }

        return ['ore' => round($work / 3600, 1), 'idle' => round($idle / 3600, 1), 'partial' => $missing > 0,
            'note' => $missing > 0 ? $missing . ' zile fără date SAS: orele pot fi mai mari.' : null];
    }

    /** @return array{total: ?float, idle: ?float, since: ?string} ore SAS inregistrate de la inceputul acoperirii */
    private function hoursTotals(int $vehicleId): array
    {
        $hours = $this->load()['hours'];
        $car = $hours['map'][$vehicleId] ?? null;
        if ($car === null || empty($hours['days'][$car])) {
            return ['total' => null, 'idle' => null, 'since' => null];
        }
        $work = 0;
        $idle = 0;
        foreach ($hours['days'][$car] as [$w, $i]) {
            $work += $w;
            $idle += $i;
        }

        return ['total' => round($work / 3600, 1), 'idle' => round($idle / 3600, 1), 'since' => $hours['first_ok'][$car] ?? array_key_first($hours['days'][$car])];
    }

    // =====================================================================
    // Plan, operatiuni, reguli
    // =====================================================================

    /** Planul vehiculului: explicit pe vehicul, altfel cel mai specific plan activ care se potriveste. */
    public function resolvePlan(array $vehicle): ?array
    {
        $data = $this->load();
        $assignment = $data['assignments'][(int) $vehicle['id']] ?? null;
        if ($assignment !== null && $assignment['plan_id'] !== null && isset($data['plans'][(int) $assignment['plan_id']])) {
            $plan = $data['plans'][(int) $assignment['plan_id']];
            return (int) $plan['activ'] === 1 ? $plan + ['_match' => 'vehicul'] : null;
        }
        $best = null;
        $bestScore = -1;
        $norm = static fn (?string $v): string => mb_strtolower(trim((string) $v));
        foreach ($data['plans'] as $plan) {
            if ((int) $plan['activ'] !== 1) {
                continue;
            }
            $score = 0;
            if ($plan['tip_vehicul'] !== null && $plan['tip_vehicul'] !== '') {
                if ($plan['tip_vehicul'] !== $vehicle['familie']) {
                    continue;
                }
                $score += 1;
            }
            if ($plan['marca'] !== null && $plan['marca'] !== '') {
                if ($norm($plan['marca']) !== $norm($vehicle['marca'])) {
                    continue;
                }
                $score += 2;
            }
            if ($plan['model'] !== null && $plan['model'] !== '') {
                if ($norm($plan['model']) !== $norm($vehicle['model'])) {
                    continue;
                }
                $score += 4;
            }
            if ($score > $bestScore) {
                $best = $plan;
                $bestScore = $score;
            }
        }

        return $best !== null ? $best + ['_match' => 'familie'] : null;
    }

    /**
     * Operatiunile aplicabile vehiculului in plan, pe sectiuni: X (de baza) si Y (suplimentare).
     *
     * @return array<string, array<int, array<string, mixed>>> 'X'|'Y' => [operatie + regula efectiva]
     */
    public function applicableOperations(array $vehicle, array $plan): array
    {
        $data = $this->load();
        $templates = $data['templates'];
        $x = $templates[(int) $plan['sablon_x_id']] ?? null;
        $y = $plan['sablon_y_id'] !== null ? ($templates[(int) $plan['sablon_y_id']] ?? null) : null;
        $base = $x['operatii'] ?? [];
        if ($y !== null && $y['parinte_id'] !== null && isset($templates[$y['parinte_id']]) && $y['parinte_id'] !== (int) $plan['sablon_x_id']) {
            $base = $templates[$y['parinte_id']]['operatii'];
        }
        $sections = ['X' => $x['operatii'] ?? [], 'Y' => array_values(array_diff($y['operatii'] ?? [], $base))];
        $vehicleRules = $data['vehicle_rules'][(int) $vehicle['id']] ?? [];
        $result = ['X' => [], 'Y' => []];
        foreach ($sections as $section => $operationIds) {
            foreach ($operationIds as $operationId) {
                $operation = $data['operations'][$operationId] ?? null;
                if ($operation === null || (int) $operation['activ'] !== 1) {
                    continue;
                }
                if (!in_array($vehicle['familie'], array_map('trim', explode(',', (string) $operation['aplicabil'])), true)) {
                    continue;
                }
                $planRule = $plan['reguli'][$operationId] ?? null;
                $vehicleRule = $vehicleRules[$operationId] ?? null;
                if ($planRule !== null && (int) $planRule['activ'] === 0 && ($vehicleRule === null || $vehicleRule['activ'] === null || (int) $vehicleRule['activ'] === 0)) {
                    continue;
                }
                if ($vehicleRule !== null && $vehicleRule['activ'] !== null && (int) $vehicleRule['activ'] === 0) {
                    continue;
                }
                $pick = static fn (string $field) => $vehicleRule !== null && $vehicleRule[$field] !== null ? $vehicleRule[$field] : ($planRule[$field] ?? null);
                $result[$section][] = $operation + [
                    'sectiune' => $section,
                    'regula' => [
                        'interval_km' => $pick('interval_km'),
                        'interval_ore' => $pick('interval_ore'),
                        'interval_luni' => $pick('interval_luni'),
                        'logica' => $planRule['logica'] ?? 'or',
                        'coef_ralanti_km' => $planRule['coef_ralanti_km'] ?? null,
                        'cantitate' => $pick('cantitate'),
                        'um' => $planRule['um'] ?? null,
                        'produs_id' => $planRule['produs_id'] ?? null,
                        'cost_estimat' => $planRule['cost_estimat'] ?? null,
                        'exceptie_vehicul' => $vehicleRule !== null,
                    ],
                ];
            }
        }

        return $result;
    }

    // =====================================================================
    // Spatiul de lucru
    // =====================================================================

    /** @return array<string, mixed> */
    public function workspace(): array
    {
        $data = $this->load();
        $rows = [];
        foreach ($data['vehicles'] as $vehicle) {
            $rows[] = $this->vehicleSummary($vehicle);
        }
        $monthStart = date('Y-m-01 00:00:00');
        $monthEnd = date('Y-m-01 00:00:00', (int) strtotime('first day of next month'));
        $costs = $this->model->monthCosts($monthStart, $monthEnd);
        $costByVehicle = [];
        foreach ($costs['lucrari'] as $order) {
            $vid = (int) $order['vehicle_id'];
            $costByVehicle[$vid]['total'] = ($costByVehicle[$vid]['total'] ?? 0) + (float) $order['cost_total'];
            $costByVehicle[$vid]['manopera'] = ($costByVehicle[$vid]['manopera'] ?? 0) + (float) $order['cost_manopera'];
            $costByVehicle[$vid]['materiale'] = ($costByVehicle[$vid]['materiale'] ?? 0) + (float) $order['cost_materiale'];
            $costByVehicle[$vid]['rev_' . $order['tip']] = ($costByVehicle[$vid]['rev_' . $order['tip']] ?? 0) + (float) $order['cost_total'];
        }
        foreach ($costs['executii'] as $exec) {
            $vid = (int) $exec['vehicle_id'];
            $costByVehicle[$vid]['total'] = ($costByVehicle[$vid]['total'] ?? 0) + (float) $exec['cost'];
            $costByVehicle[$vid]['operatii'] = ($costByVehicle[$vid]['operatii'] ?? 0) + (float) $exec['cost'];
        }
        foreach ($rows as &$row) {
            $row['cost_luna'] = $costByVehicle[$row['id']] ?? null;
        }
        unset($row);

        return [
            'generated_at' => date('Y-m-d H:i:s'),
            'vehicles' => $rows,
            'sync' => $this->syncState(),
            'filters' => $this->filterOptions($rows),
            'labels' => [
                'status' => ReviziiV2Engine::STATUS_LABELS,
                'work' => ReviziiV2Model::WORK_STATUSES,
                'item' => ReviziiV2Model::ITEM_STATUSES,
                'op_types' => ReviziiV2Model::OPERATION_TYPES,
                'categories' => ReviziiV2Model::CATEGORIES,
            ],
            'plans_count' => count(array_filter($data['plans'], static fn (array $p): bool => (int) $p['activ'] === 1)),
        ];
    }

    /** @return array<string, mixed> */
    private function syncState(): array
    {
        $data = $this->load();
        $latest = null;
        foreach ($data['readings'] as $providers) {
            foreach ($providers as $reading) {
                if ($latest === null || $reading['at'] > $latest) {
                    $latest = $reading['at'];
                }
            }
        }
        $hoursSync = $data['hours']['last_sync'];
        $at = max((string) $latest, (string) $hoursSync) ?: null;
        $age = $at !== null ? time() - (int) strtotime($at) : null;
        $stale = max(1, (int) $data['settings']['date_vechi_ore']) * 3600;

        return [
            'at' => $at,
            'odometru_at' => $latest,
            'ore_at' => $hoursSync,
            'state' => $age === null ? 'indisponibil' : ($age <= 6 * 3600 ? 'actual' : ($age <= $stale ? 'intarziat' : 'indisponibil')),
        ];
    }

    /** @return array<string, mixed> */
    private function filterOptions(array $rows): array
    {
        $makes = [];
        $garages = [];
        foreach ($rows as $row) {
            $makes[trim($row['marca'] . ' ' . $row['model'])] = true;
            if ($row['garaj'] !== '') {
                $garages[$row['garaj']] = true;
            }
        }
        ksort($makes);
        ksort($garages);
        $components = [];
        foreach ($this->load()['operations'] as $operation) {
            if ((int) $operation['activ'] === 1) {
                $components[(string) $operation['componenta']] = true;
            }
        }

        return ['marci' => array_keys($makes), 'garaje' => array_keys($garages), 'componente' => array_keys($components)];
    }

    /** @return array<string, mixed> randul unui vehicul (rezumat + operatiunile, fara istoric) */
    public function vehicleSummary(array $vehicle): array
    {
        $data = $this->load();
        $vehicleId = (int) $vehicle['id'];
        $assignment = $data['assignments'][$vehicleId] ?? [];
        $plan = $this->resolvePlan($vehicle);
        $policy = (string) ($plan['sursa_km'] ?? $data['settings']['sursa_km_implicita']);
        $km = $this->currentKm($vehicle, $policy);
        $open = $data['open'][$vehicleId] ?? null;
        $hoursTotals = $vehicle['familie'] === 'semiremorca' ? ['total' => null, 'idle' => null, 'since' => null] : $this->hoursTotals($vehicleId);

        $row = [
            'id' => $vehicleId,
            'nr' => (string) $vehicle['nr_inmatriculare'],
            'marca' => (string) $vehicle['marca'],
            'model' => (string) $vehicle['model'],
            'tip' => (string) $vehicle['tip_vehicul'],
            'familie' => (string) $vehicle['familie'],
            'garaj' => (string) ($vehicle['garaj'] ?? ''),
            'vin' => (string) ($vehicle['serie_sasiu'] ?? ''),
            'an' => (int) ($vehicle['an_fabricatie'] ?? 0),
            'poza' => function_exists('vehicle_image_url') ? vehicle_image_url((string) ($vehicle['poza_stocata'] ?? '')) : null,
            'km' => $km,
            'km_zi' => $data['daily_km'][$vehicleId] ?? null,
            'ore' => $hoursTotals,
            'plan' => $plan !== null ? ['id' => (int) $plan['id'], 'nume' => (string) $plan['nume'], 'match' => $plan['_match']] : null,
            'workflow' => $open !== null ? [
                'id' => (int) $open['id'], 'status' => (string) $open['status'], 'tip' => (string) $open['tip'],
                'data_programata' => $open['data_programata'], 'label' => ReviziiV2Model::WORK_STATUSES[$open['status']] ?? $open['status'],
            ] : null,
            'ops' => [],
            'status' => ReviziiV2Engine::ST_NOT_CONFIGURED,
            'secventa' => null,
            'next' => null,
            'last' => null,
            'info' => null,
            'cost_estimat' => null,
            'notes' => array_values(array_filter([$km['note']])),
        ];
        if ($plan === null) {
            $row['notes'][] = 'Niciun plan de mentenanță nu se aplică acestui vehicul.';
            return $row;
        }

        // Istoricul reviziilor complete (X si Y) → referinte si secventa reala.
        $history = $data['complete'][$vehicleId] ?? [];
        $lastYIdx = null;
        foreach ($history as $i => $h) {
            if ($h['tip'] === 'Y') {
                $lastYIdx = $i;
            }
        }
        $lastRev = $history !== [] ? $history[count($history) - 1] : null;
        $lastY = $lastYIdx !== null ? $history[$lastYIdx] : null;
        $afterY = $lastYIdx !== null ? array_slice($history, $lastYIdx + 1) : $history;
        $initialX = (int) ($assignment['x_de_la_ultima_y'] ?? 0);
        $xDone = count(array_filter($afterY, static fn (array $h): bool => $h['tip'] === 'X')) + ($lastYIdx === null ? $initialX : 0);
        $yDone = count(array_filter($history, static fn (array $h): bool => $h['tip'] === 'Y')) + (int) ($assignment['y_efectuate'] ?? 0);

        $revBase = $lastRev !== null
            ? ['data' => (string) $lastRev['data_executie'], 'km' => $lastRev['km_executie'] !== null ? (float) $lastRev['km_executie'] : null,
                'label' => ReviziiV2Engine::revisionLabel((string) $lastRev['tip'], (int) $lastRev['nr_x']), 'sursa' => 'revizie']
            : (!empty($assignment['referinta_data'])
                ? ['data' => (string) $assignment['referinta_data'], 'km' => $assignment['referinta_km'] !== null ? (float) $assignment['referinta_km'] : null,
                    'label' => $initialX > 0 ? 'X' . $initialX : (!empty($assignment['ultima_y_data']) && $assignment['ultima_y_data'] === $assignment['referinta_data'] ? 'Y' : 'Referință'),
                    'sursa' => 'preluare']
                : null);
        $yBaseKm = $lastY !== null && $lastY['km_executie'] !== null ? (float) $lastY['km_executie']
            : ($lastY === null && $assignment !== [] && $assignment['ultima_y_km'] !== null ? (float) $assignment['ultima_y_km'] : null);
        $origin = $assignment !== [] && $assignment['origine_grila_km'] !== null ? (float) $assignment['origine_grila_km']
            : ($assignment !== [] && $assignment['referinta_km'] !== null ? (float) $assignment['referinta_km'] : ($history !== [] && $history[0]['km_executie'] !== null ? (float) $history[0]['km_executie'] : null));

        // Secventa efectuata de la ultima Y (inclusiv): din BD, precedata de ce s-a declarat la preluare.
        $done = [];
        if ($lastYIdx === null) {
            if ($assignment !== [] && ($assignment['ultima_y_km'] !== null || !empty($assignment['ultima_y_data']))) {
                $done[] = ['type' => 'Y', 'label' => 'Y', 'km' => $assignment['ultima_y_km'] !== null ? (float) $assignment['ultima_y_km'] : null, 'date' => $assignment['ultima_y_data'] ?: null, 'merged' => false];
            }
            for ($i = 1; $i <= $initialX; $i++) {
                $isLast = $i === $initialX;
                $done[] = ['type' => 'X', 'label' => 'X' . $i, 'km' => $isLast && $assignment['referinta_km'] !== null ? (float) $assignment['referinta_km'] : null,
                    'date' => $isLast ? ($assignment['referinta_data'] ?: null) : null, 'merged' => false];
            }
        }
        foreach ($lastYIdx !== null ? array_slice($history, $lastYIdx) : $history as $h) {
            $done[] = ['type' => (string) $h['tip'], 'label' => ReviziiV2Engine::revisionLabel((string) $h['tip'], (int) $h['nr_x']),
                'km' => $h['km_executie'] !== null ? (float) $h['km_executie'] : null, 'date' => (string) $h['data_executie'], 'merged' => false];
        }
        $done = array_slice($done, -8);

        $sched = ReviziiV2Engine::schedule([
            'current_km' => $km['value'],
            'x_interval_km' => $plan['interval_km'],
            'x_base_km' => $revBase['km'] ?? null,
            'x_origin_km' => $origin,
            'y_interval_km' => $plan['sablon_y_id'] !== null ? $plan['y_interval_km'] : null,
            'y_threshold_km' => $plan['sablon_y_id'] !== null ? $plan['y_prag_km'] : null,
            'y_base_km' => $yBaseKm,
            'tolerance_km' => $plan['y_toleranta_km'],
            'policy' => (string) $plan['politica_x'],
            'x_done_since_y' => $xDone,
            'done' => $done,
        ]);
        $nextType = $sched['type'] ?? 'X';
        $nextLabel = $sched['label'] ?? ReviziiV2Engine::revisionLabel('X', $xDone + 1);

        $thresholds = [
            'prag_km' => $plan['prag_km'] !== null ? (int) $plan['prag_km'] : null,
            'prag_ore' => $plan['prag_ore'] !== null ? (int) $plan['prag_ore'] : null,
            'prag_zile' => $plan['prag_zile'] !== null ? (int) $plan['prag_zile'] : (int) $data['settings']['prag_zile_implicit'],
        ];
        // Criteriul km al urmatoarei revizii = tinta din programarea pe km; ore / luni = intervalul X de la ultima revizie.
        $revUsage = $this->usage($vehicle, $revBase['data'] ?? null, $revBase['km'] ?? null, $km);
        $kmCriterion = $this->kmToTarget($sched['km_target'], $revBase['km'] ?? null, $km['value']);
        $revRule = ['interval_km' => $kmCriterion['interval'], 'interval_ore' => $plan['interval_ore'], 'interval_luni' => $plan['interval_luni'], 'logica' => $plan['logica']];
        $revEval = ReviziiV2Engine::evaluate($revRule, ['km_since' => $kmCriterion['used']] + $revUsage, $thresholds, time());
        // Progresul spre urmatoarea Y (operatiunile Y fara interval propriu).
        $yCriterion = $this->kmToTarget($sched['y_target'], $yBaseKm ?? ($revBase['km'] ?? null), $km['value']);
        $yEval = $sched['y_target'] !== null
            ? ReviziiV2Engine::evaluate(['interval_km' => $yCriterion['interval']], ['km_since' => $yCriterion['used']], $thresholds, time())
            : null;

        $info = null;
        if ($nextType === 'Y' && $sched['merged']) {
            $info = sprintf('Revizia X (%s km) și Revizia Y (%s km) coincid în toleranța de %s km: se face o singură Revizie Y (operațiunile X + Y).',
                ReviziiV2Engine::fmt($sched['x_target']), ReviziiV2Engine::fmt($sched['y_target']), ReviziiV2Engine::fmt($sched['tolerance_km']));
        } elseif ($nextType === 'Y' && $sched['y_between']) {
            $info = sprintf('Revizia Y (%s km) cade înaintea următoarei X (%s km): Y include operațiunile X, iar X-urile următoare se recalculează (%s).',
                ReviziiV2Engine::fmt($sched['y_target']), ReviziiV2Engine::fmt($sched['x_target']), mb_strtolower(ReviziiV2Engine::X_POLICIES[$sched['policy']]));
        }

        $sections = $this->applicableOperations($vehicle, $plan);
        $resets = $data['resets'][$vehicleId] ?? [];
        $opStatuses = [];
        $ops = [];
        $costSum = 0.0;
        foreach (['X', 'Y'] as $section) {
            foreach ($sections[$section] as $operation) {
                $evaluated = $this->evaluateOperation($vehicle, $plan, $operation, $resets[(int) $operation['id']] ?? null, $assignment, $km, $thresholds,
                    $revRule, $revEval, $nextType, $revBase['data'] ?? null, $yEval, $sched['y_target']);
                $ops[] = $evaluated;
                if ($evaluated['status'] !== ReviziiV2Engine::ST_NOT_CONFIGURED) {
                    $opStatuses[] = $evaluated['status'];
                }
                if (($section === 'X' || $nextType === 'Y') && $operation['regula']['cost_estimat'] !== null) {
                    $costSum += (float) $operation['regula']['cost_estimat'];
                }
            }
        }

        $revStatus = $revEval['status'];
        $status = ReviziiV2Engine::worst(array_merge([$revStatus], $opStatuses));
        $planCost = $nextType === 'Y' ? $plan['cost_estimat_y'] : $plan['cost_estimat_x'];
        $avg = $data['avg_costs'][(int) $plan['id'] . ':' . $nextType] ?? null;
        $kmLeft = $revEval['km_ramasi'];
        $estDate = $revEval['scadenta_data'];
        if ($kmLeft !== null && ($row['km_zi'] ?? 0) > 0) {
            $projected = date('Y-m-d', time() + (int) round(max(0, $kmLeft) / $row['km_zi'] * 86400));
            $estDate = $estDate === null || $projected < $estDate ? $projected : $estDate;
        }

        $row['status'] = $status;
        $row['secventa'] = [
            'x_done' => $xDone,
            'y_done' => $yDone,
            'x_before_y' => $sched['x_before_y'],
            'x_target' => $sched['x_target'],
            'y_target' => $sched['y_target'],
            'y_km_ramasi' => $sched['y_target'] !== null && $km['value'] !== null ? round($sched['y_target'] - $km['value']) : null,
            'policy' => $sched['policy'],
            'tolerance_km' => $sched['tolerance_km'],
            'sequence' => $sched['sequence'],
            'notes' => $sched['notes'],
        ];
        $row['info'] = $info;
        $row['last'] = $revBase;
        $row['next'] = [
            'type' => $nextType,
            'label' => $nextLabel,
            'x_number' => $sched['x_number'],
            'merged' => $sched['merged'],
            'between' => $sched['y_between'],
            'status' => $revStatus,
            'km_target' => $sched['km_target'],
            'km_ramasi' => $kmLeft,
            'ore_ramase' => $revEval['ore_ramase'],
            'zile_ramase' => $revEval['zile_ramase'],
            'scadenta_data' => $revEval['scadenta_data'],
            'data_estimata' => $estDate,
            'pct' => $revEval['pct'],
            'dominant' => $revEval['dominant'],
            'criteria' => $revEval['criteria'],
            'notes' => array_values(array_unique(array_merge($sched['notes'], $revEval['notes'], array_filter([$revUsage['note'] ?? null])))),
            'configurat' => $revEval['status'] !== ReviziiV2Engine::ST_NOT_CONFIGURED,
        ];
        $row['ops'] = $ops;
        $row['cost_estimat'] = $planCost !== null ? (float) $planCost : ($costSum > 0 ? $costSum : ($avg !== null ? round($avg, 2) : null));
        $row['cost_sursa'] = $planCost !== null ? 'plan' : ($costSum > 0 ? 'operatii' : ($avg !== null ? 'istoric' : null));

        return $row;
    }

    /**
     * Criteriul km spre o tinta absoluta (km odometru): interval = tinta − referinta, consumat =
     * odometru − referinta. Fara referinta, intervalul e masurat de la 0 (doar restul conteaza).
     *
     * @return array{interval: ?int, used: ?float}
     */
    private function kmToTarget(?float $target, ?float $baseKm, ?float $currentKm): array
    {
        if ($target === null) {
            return ['interval' => null, 'used' => null];
        }
        $base = $baseKm ?? 0.0;
        $interval = max(1, (int) round($target - $base));

        return ['interval' => $interval, 'used' => $currentKm !== null && $currentKm + 1 >= $base ? max(0.0, $currentKm - $base) : null];
    }

    /**
     * Uzura de la o referinta (data + km): km din odometru (diferenta, aceeasi politica de sursa),
     * ore motor / ralanti din SAS.
     *
     * @return array<string, mixed>
     */
    private function usage(array $vehicle, ?string $baselineDate, ?float $baselineKm, array $km): array
    {
        $note = null;
        $kmSince = null;
        if ($km['value'] !== null) {
            if ($baselineKm === null && $baselineDate !== null && $km['source'] !== 'contor') {
                $baselineKm = $this->readingAt((int) $vehicle['id'], (string) $km['source'], $baselineDate);
                if ($baselineKm !== null) {
                    $note = 'Km la referință luați din odometru (' . strtoupper((string) $km['source']) . ') la data referinței.';
                }
            }
            if ($baselineKm !== null) {
                if ($km['value'] + 1 < $baselineKm) {
                    $note = 'Odometrul curent e sub km la referință (resetare / corecție?): km nu se pot calcula.';
                } else {
                    $kmSince = $km['value'] - $baselineKm;
                }
            }
        }
        $hours = $vehicle['familie'] === 'semiremorca' ? ['ore' => null, 'idle' => null, 'partial' => false, 'note' => null] : $this->hoursSince((int) $vehicle['id'], $baselineDate);

        return [
            'km_since' => $kmSince,
            'ore_since' => $hours['ore'],
            'idle_since' => $hours['idle'],
            'ore_partial' => $hours['partial'],
            'baseline_date' => $baselineDate,
            'note' => $note ?? $hours['note'],
        ];
    }

    /** @return array<string, mixed> */
    private function evaluateOperation(array $vehicle, array $plan, array $operation, ?array $lastReset, array $assignment, array $km, array $thresholds,
        array $revRule, array $revEval, string $nextType, ?string $revisionSince = null, ?array $yEval = null, ?float $yTarget = null): array
    {
        $rule = $operation['regula'];
        $own = $rule['interval_km'] !== null || $rule['interval_ore'] !== null || $rule['interval_luni'] !== null;
        $baseline = $lastReset !== null
            ? ['data' => (string) $lastReset['data_executie'], 'km' => $lastReset['km'] !== null ? (float) $lastReset['km'] : null, 'sursa' => 'executie']
            : (!empty($assignment['referinta_data']) ? ['data' => (string) $assignment['referinta_data'], 'km' => $assignment['referinta_km'] !== null ? (float) $assignment['referinta_km'] : null, 'sursa' => 'preluare'] : null);
        $mode = 'proprie';
        $effective = $rule;
        if (!$own) {
            if ($operation['sectiune'] === 'Y') {
                // Fara interval propriu: urmeaza programarea pe km a Reviziei Y.
                $mode = 'la_y';
            } else {
                $mode = 'revizie';
                $effective = $revRule + ['coef_ralanti_km' => $rule['coef_ralanti_km']];
            }
        }

        $doneThisSlot = $lastReset !== null && ($revisionSince === null || (string) $lastReset['data_executie'] > $revisionSince);
        if ($mode === 'la_y') {
            // Revizia Y urmatoare: starea reviziei; altfel progresul spre tinta km a lui Y.
            // Deja efectuata de la ultima revizie completa (ex. intr-o Y partiala) = in termen.
            $from = static fn (array $e, array $notes = []): array => ['status' => $e['status'], 'criteria' => $e['criteria'], 'pct' => $e['pct'], 'dominant' => $e['dominant'],
                'km_ramasi' => $e['km_ramasi'], 'zile_ramase' => $e['zile_ramase'], 'ore_ramase' => $e['ore_ramase'], 'notes' => $notes, 'scadenta_data' => $e['scadenta_data']];
            if ($doneThisSlot) {
                $eval = ['status' => ReviziiV2Engine::ST_OK, 'criteria' => [], 'pct' => null, 'dominant' => null, 'km_ramasi' => null, 'zile_ramase' => null, 'ore_ramase' => null,
                    'notes' => ['Efectuată deja după ultima revizie completă (' . date('d.m.Y', (int) strtotime((string) $lastReset['data_executie'])) . ').'], 'scadenta_data' => null];
            } elseif ($nextType === 'Y') {
                $eval = $from($revEval);
            } elseif ($yEval !== null) {
                $eval = $from($yEval, ['Se efectuează la Revizia Y (ținta km a lui Y).']);
            } else {
                $eval = ['status' => $revEval['status'] === ReviziiV2Engine::ST_NOT_CONFIGURED ? ReviziiV2Engine::ST_NOT_CONFIGURED : ReviziiV2Engine::ST_NO_DATA,
                    'criteria' => [], 'pct' => null, 'dominant' => null, 'km_ramasi' => null, 'zile_ramase' => null, 'ore_ramase' => null,
                    'notes' => ['Se efectuează la Revizia Y, dar ținta km a lui Y nu se poate calcula (prag / interval / referință lipsă).'], 'scadenta_data' => null];
            }
        } else {
            $usage = $this->usage($vehicle, $baseline['data'] ?? null, $baseline['km'] ?? null, $km);
            $eval = ReviziiV2Engine::evaluate($effective, $usage, $thresholds, time());
            if (!empty($usage['note'])) {
                $eval['notes'][] = $usage['note'];
            }
        }

        $parts = [];
        if (($effective['interval_km'] ?? null) !== null) {
            $parts[] = ReviziiV2Engine::fmt((int) $effective['interval_km']) . ' km';
        }
        if (($effective['interval_ore'] ?? null) !== null) {
            $parts[] = ReviziiV2Engine::fmt((int) $effective['interval_ore']) . ' h';
        }
        if (($effective['interval_luni'] ?? null) !== null) {
            $parts[] = (int) $effective['interval_luni'] . ' luni';
        }
        $intervalText = $mode === 'la_y' ? 'La Revizia Y' . ($yTarget !== null ? ' (' . ReviziiV2Engine::fmt($yTarget) . ' km)' : '') : ($parts === [] ? '—' : implode(($effective['logica'] ?? 'or') === 'and' ? ' și ' : ' / ', $parts));
        $criteriaKeys = [];
        foreach (['km' => 'interval_km', 'ore' => 'interval_ore', 'calendar' => 'interval_luni'] as $key => $field) {
            if (($effective[$field] ?? null) !== null) {
                $criteriaKeys[] = $key;
            }
        }
        $nextDue = null;
        foreach ($eval['criteria'] as $criterion) {
            if ($criterion['tip'] === 'km' && $criterion['measured'] && $baseline !== null && $baseline['km'] !== null) {
                $nextDue = ReviziiV2Engine::fmt($baseline['km'] + $criterion['interval']) . ' km';
            } elseif ($criterion['tip'] === 'km' && $criterion['measured'] && $km['value'] !== null) {
                $nextDue = ReviziiV2Engine::fmt($km['value'] + $criterion['remaining']) . ' km';
            }
        }
        if ($nextDue === null && ($eval['scadenta_data'] ?? null) !== null) {
            $nextDue = date('d.m.Y', (int) strtotime((string) $eval['scadenta_data']));
        }

        return [
            'id' => (int) $operation['id'],
            'cod' => (string) $operation['cod'],
            'comp' => (string) $operation['componenta'],
            'op' => (string) $operation['operatie'],
            'tip' => (string) $operation['tip_operatie'],
            'cat' => (string) $operation['categorie'],
            'sect' => (string) $operation['sectiune'],
            'sistem' => $operation['sistem_fluid'],
            'factori' => (string) ($operation['factori_uzura'] ?? ''),
            'de_verificat' => (int) $operation['de_verificat'] === 1,
            'mode' => $mode,
            'interval' => $intervalText,
            'crit' => $criteriaKeys,
            'criteria' => $eval['criteria'],
            'status' => $eval['status'],
            'pct' => $eval['pct'],
            'dominant' => $eval['dominant'],
            'km_ramasi' => $eval['km_ramasi'],
            'ore_ramase' => $eval['ore_ramase'],
            'zile_ramase' => $eval['zile_ramase'],
            'next_due' => $mode === 'la_y' && $yTarget !== null && !$doneThisSlot ? ReviziiV2Engine::fmt($yTarget) . ' km' : ($nextDue ?? ($mode === 'la_y' ? 'Revizia Y' : '—')),
            'baseline' => $baseline,
            'cantitate' => $rule['cantitate'] !== null ? (float) $rule['cantitate'] : null,
            'um' => $rule['um'],
            'produs_id' => $rule['produs_id'] !== null ? (int) $rule['produs_id'] : null,
            'exceptie' => !empty($rule['exceptie_vehicul']),
            'notes' => array_values(array_unique(array_filter($eval['notes'] ?? []))),
        ];
    }

    // =====================================================================
    // Detaliul unui vehicul (incarcat la cerere)
    // =====================================================================

    /** @return array<string, mixed> */
    public function vehicleDetail(int $vehicleId): array
    {
        $data = $this->load();
        $vehicle = $data['vehicles'][$vehicleId] ?? throw new InvalidArgumentException('Vehicul inexistent sau inactiv.');
        $summary = $this->vehicleSummary($vehicle);
        $executions = $this->model->executions($vehicleId, null, 300);
        $orders = $this->db->prepare(
            'SELECT l.*, u.nume AS created_by_name, f.nume AS finalized_by_name FROM revizii_v2_lucrari l
             LEFT JOIN utilizatori u ON u.id = l.created_by LEFT JOIN utilizatori f ON f.id = l.finalized_by
             WHERE l.vehicle_id = :v ORDER BY COALESCE(l.data_executie, l.created_at) DESC, l.id DESC LIMIT 50'
        );
        $orders->execute([':v' => $vehicleId]);

        // Rata de completare (ulei): completari dupa ultimul schimb complet, pe km / ore masurate.
        $rates = [];
        foreach ($summary['ops'] as $op) {
            if ($op['sistem'] === null) {
                continue;
            }
            $since = $op['baseline']['data'] ?? null;
            $quantity = 0.0;
            foreach ($executions as $exec) {
                if ((int) $exec['operatie_id'] === $op['id'] && $exec['tip_executie'] === 'completare' && $exec['anulat_la'] === null
                    && ($since === null || (string) $exec['data_executie'] > $since) && $exec['cantitate'] !== null) {
                    $quantity += (float) $exec['cantitate'];
                }
            }
            if ($quantity > 0) {
                $kmCrit = null;
                $hourCrit = null;
                foreach ($op['criteria'] as $c) {
                    if ($c['tip'] === 'km' && $c['measured']) {
                        $kmCrit = (float) $c['used'];
                    }
                    if ($c['tip'] === 'ore' && $c['measured']) {
                        $hourCrit = (float) $c['used'];
                    }
                }
                $rates[$op['id']] = ['cantitate' => $quantity] + ReviziiV2Engine::topUpRate($quantity, $kmCrit, $hourCrit);
            }
        }

        return [
            'vehicle' => $summary,
            'executii' => $executions,
            'lucrari' => $orders->fetchAll(PDO::FETCH_ASSOC),
            'rate_completare' => $rates,
            'uzura' => $this->legacyWear($vehicle),
            'fluide' => $this->fluidSystems($vehicleId),
        ];
    }

    /** Componentele monitorizate de ecranele existente (ulei km echivalenti, placute, anvelope) — refolosite. */
    private function legacyWear(array $vehicle): array
    {
        $file = __DIR__ . '/ReviziiService.php';
        if (!is_file($file)) {
            return [];
        }
        require_once $file;
        try {
            $legacy = new ReviziiService($this->db);
            $result = [];
            $isTrailer = $vehicle['familie'] === 'semiremorca';
            foreach (ReviziiModel::TABS as $tab => $meta) {
                foreach (ReviziiModel::itemsFor($tab, $isTrailer) as $tip => $label) {
                    $component = $legacy->computeComponent((int) $vehicle['id'], time(), $tip, $label);
                    $result[] = [
                        'tab' => $tab,
                        'tip' => $tip,
                        'nume' => ReviziiModel::componentName($tip, (string) $vehicle['tip_vehicul']),
                        'status' => (string) $component['status'],
                        'interval_km' => $component['plan']['interval_km'] ?? null,
                        'km_ramasi' => $component['calc']['ramasi'] ?? null,
                        'progres' => $component['calc']['progres'] ?? null,
                        'url' => build_query_url(['page' => 'revizii', 'tip' => $tab]),
                    ];
                }
            }

            return $result;
        } catch (Throwable $exception) {
            error_log('[ReviziiV2][legacyWear] ' . $exception->getMessage());
            return [];
        }
    }

    /** @return array<int, array<string, mixed>> sistemele de fluide ale vehiculului (Consumabile) */
    private function fluidSystems(int $vehicleId): array
    {
        if (!$this->tableExists('vehicule_sisteme_fluide')) {
            return [];
        }
        $stmt = $this->db->prepare(
            'SELECT s.id, s.sistem, s.denumire, s.tip_fluid, s.capacitate_nominala, s.um, s.produs_id, p.denumire AS produs, p.vascozitate, p.specificatie, p.aprobari
             FROM vehicule_sisteme_fluide s LEFT JOIN consumabile_produse p ON p.id = s.produs_id WHERE s.vehicle_id = :v AND s.activ = 1'
        );
        $stmt->execute([':v' => $vehicleId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Produsele si recipientele cu stoc potrivite unei operatiuni cu fluid (dupa sistemul vehiculului
     * si tipul de fluid). Nu decide compatibilitatea doar dupa vascozitate.
     *
     * @return array<string, mixed>
     */
    public function stockOptions(int $vehicleId): array
    {
        if (!$this->tableExists('consumabile_recipiente')) {
            return ['recipiente' => [], 'sisteme' => []];
        }
        $systems = [];
        foreach ($this->fluidSystems($vehicleId) as $system) {
            $systems[(string) $system['sistem']] = $system;
        }
        $rows = $this->db->query(
            "SELECT r.id, r.cod, r.locatie, r.cantitate AS cantitate_curenta, r.produs_id, p.denumire AS produs, p.tip_fluid, p.vascozitate, p.specificatie, p.aprobari,
                    COALESCE(r.um, p.um) AS um, p.tipuri_vehicul, p.vehicule_ids
             FROM consumabile_recipiente r JOIN consumabile_produse p ON p.id = r.produs_id
             WHERE r.status <> 'inactiv' AND p.activ = 1 AND r.cantitate > 0
               AND (r.data_expirare IS NULL OR r.data_expirare >= CURDATE())
             ORDER BY p.denumire, r.cod"
        )->fetchAll(PDO::FETCH_ASSOC);

        return ['recipiente' => $rows, 'sisteme' => $systems];
    }

    // =====================================================================
    // Lucrari (evenimentul de revizie)
    // =====================================================================

    /**
     * Ciorna unei lucrari noi: tipul din programarea pe km, operatiunile X (+ Y la Revizia Y) si
     * operatiunile scadente din afara reviziei. Ce s-a efectuat deja dupa ultima revizie completa
     * (revizie partiala / executie independenta) vine marcat „Nu se aplica” cu motiv.
     *
     * @return array<string, mixed>
     */
    public function draftWorkOrder(int $vehicleId): array
    {
        $data = $this->load();
        $vehicle = $data['vehicles'][$vehicleId] ?? throw new InvalidArgumentException('Vehicul inexistent sau inactiv.');
        $summary = $this->vehicleSummary($vehicle);
        if ($summary['plan'] === null) {
            throw new InvalidArgumentException('Vehiculul nu are un plan de mentenanță: configurează întâi planul.');
        }
        $type = $summary['next']['type'];
        $revisionSince = $summary['last']['data'] ?? null;
        $items = [];
        foreach ($summary['ops'] as $op) {
            $inRevision = $op['sect'] === 'X' || $type === 'Y';
            if (!$inRevision && !ReviziiV2Engine::isUrgent($op['status']) && $op['status'] !== ReviziiV2Engine::ST_SOON) {
                continue;
            }
            $doneAt = (($op['baseline']['sursa'] ?? null) === 'executie' && $revisionSince !== null && ($op['baseline']['data'] ?? '') > $revisionSince)
                ? $op['baseline']['data'] : null;
            $items[] = [
                'operatie_id' => $op['id'],
                'sectiune' => $inRevision ? $op['sect'] : 'S',
                'componenta' => $op['comp'],
                'operatie' => $op['op'],
                'tip_operatie' => $op['tip'],
                'categorie' => $op['cat'],
                'sistem' => $op['sistem'],
                'status' => $doneAt !== null ? 'na' : 'pending',
                'motiv' => $doneAt !== null ? 'Efectuată deja la ' . date('d.m.Y', (int) strtotime($doneAt)) . ' (după ultima revizie).' : null,
                'cantitate_estimata' => $op['cantitate'],
                'um' => $op['um'] ?? ($op['sistem'] !== null ? 'L' : null),
                'produs_id' => $op['produs_id'],
                'urgenta' => $op['status'],
                'interval' => $op['interval'],
                'regula' => ['mode' => $op['mode'], 'criteria' => array_map(static fn (array $c): array => ['tip' => $c['tip'], 'interval' => $c['interval']], $op['criteria']), 'interval' => $op['interval']],
            ];
        }

        return [
            'id' => null,
            'vehicle_id' => $vehicleId,
            'plan_id' => $summary['plan']['id'],
            'tip' => $type,
            'nr_x' => $summary['next']['x_number'],
            'nr_y' => $summary['secventa']['y_done'] + 1,
            'km_tinta' => $summary['next']['km_target'],
            'label' => $summary['next']['label'],
            'status' => 'nou',
            'data_programata' => null,
            'data_executie' => null,
            'km_executie' => $summary['km']['value'],
            'km_sursa' => $summary['km']['source'],
            'ore_executie' => null,
            'tehnician' => null,
            'observatii' => null,
            'cost_manopera' => null,
            'items' => $items,
            'documente' => [],
            'info' => $summary['info'],
        ];
    }

    /** Lucrarea deschisa a vehiculului sau ciorna uneia noi (pentru formularul de executie). */
    public function workOrderForm(int $vehicleId, ?int $orderId = null): array
    {
        $open = $orderId !== null ? $this->model->workOrder($orderId) : null;
        if ($open === null) {
            $openRow = $this->load()['open'][$vehicleId] ?? null;
            $open = $openRow !== null ? $this->model->workOrder((int) $openRow['id']) : null;
        }
        $form = $open !== null ? $this->decorateOrder($open) : $this->draftWorkOrder($vehicleId);
        $form['stoc'] = $this->stockOptions($vehicleId);
        $form['vehicle'] = $this->vehicleSummary($this->load()['vehicles'][$vehicleId] ?? throw new InvalidArgumentException('Vehicul inexistent.'));

        return $form;
    }

    /** @return array<string, mixed> */
    private function decorateOrder(array $order): array
    {
        $order['label'] = ReviziiV2Engine::revisionLabel((string) $order['tip'], (int) $order['nr_x']);
        $order['id'] = (int) $order['id'];
        foreach ($order['items'] as &$item) {
            $item['regula'] = $item['regula_snapshot'] !== null ? json_decode((string) $item['regula_snapshot'], true) : null;
            $item['interval'] = $item['regula']['interval'] ?? '—';
        }
        unset($item);

        return $order;
    }

    /**
     * Creeaza / actualizeaza lucrarea (programare sau progres). Nu finalizeaza si nu
     * atinge stocul. O singura lucrare deschisa per vehicul (cheie unica activ_vehicle_id).
     *
     * @param array<string, mixed> $input
     */
    public function saveWorkOrder(array $input, ?int $userId): int
    {
        $vehicleId = (int) ($input['vehicle_id'] ?? 0);
        $orderId = (int) ($input['id'] ?? 0);
        $status = in_array($input['status'] ?? '', ['programata', 'in_desfasurare'], true) ? (string) $input['status'] : 'programata';

        return $this->transaction(function () use ($input, $vehicleId, $orderId, $status, $userId): int {
            if ($orderId > 0) {
                $order = $this->model->workOrder($orderId, true) ?? throw new InvalidArgumentException('Lucrarea nu mai există.');
                if (!in_array($order['status'], ReviziiV2Model::OPEN_STATUSES, true)) {
                    throw new InvalidArgumentException('Lucrarea este deja închisă și nu mai poate fi modificată.');
                }
                $this->updateOrderFields($orderId, $input, $status);
                $this->saveItems($orderId, $input['items'] ?? [], false);
                $this->model->audit($orderId, 'lucrare_actualizata', 'Lucrare revizie actualizată (' . $status . ')', null, ['status' => $status], $userId);

                return $orderId;
            }
            $draft = $this->draftWorkOrder($vehicleId);
            $check = $this->db->prepare("SELECT id FROM revizii_v2_lucrari WHERE vehicle_id = :v AND status IN ('programata', 'in_desfasurare') FOR UPDATE");
            $check->execute([':v' => $vehicleId]);
            if ($check->fetchColumn() !== false) {
                throw new InvalidArgumentException('Vehiculul are deja o lucrare de revizie deschisă.');
            }
            $plan = $this->load()['plans'][(int) $draft['plan_id']];
            $snapshot = json_encode([
                'plan' => array_intersect_key($plan, array_flip(['id', 'nume', 'interval_km', 'interval_ore', 'interval_luni', 'logica', 'y_prag_km', 'y_interval_km', 'y_toleranta_km', 'politica_x', 'sursa_km'])),
                'sabloane' => array_map(fn (int $id): array => array_intersect_key($this->load()['templates'][$id] ?? [], array_flip(['id', 'nume', 'tip', 'versiune', 'parinte_id'])),
                    array_values(array_filter([(int) $plan['sablon_x_id'], (int) ($plan['sablon_y_id'] ?? 0)]))),
                'info' => $draft['info'],
                'km_tinta' => $draft['km_tinta'],
            ], JSON_UNESCAPED_UNICODE);
            $insert = $this->db->prepare(
                'INSERT INTO revizii_v2_lucrari (vehicle_id, plan_id, tip, nr_x, nr_y, km_tinta, status, activ_vehicle_id, snapshot, created_by)
                 VALUES (:v, :p, :t, :nx, :ny, :kt, :s, :a, :snap, :u)'
            );
            try {
                $insert->execute([':v' => $vehicleId, ':p' => $draft['plan_id'], ':t' => $draft['tip'], ':nx' => $draft['nr_x'], ':ny' => $draft['nr_y'], ':kt' => $draft['km_tinta'],
                    ':s' => $status, ':a' => $vehicleId, ':snap' => $snapshot, ':u' => $userId]);
            } catch (PDOException $exception) {
                if ((string) $exception->getCode() === '23000') {
                    throw new InvalidArgumentException('Vehiculul are deja o lucrare de revizie deschisă.');
                }
                throw $exception;
            }
            $orderId = (int) $this->db->lastInsertId();
            $itemInsert = $this->db->prepare(
                'INSERT INTO revizii_v2_lucrari_operatii (lucrare_id, operatie_id, sectiune, componenta, operatie, tip_operatie, categorie, regula_snapshot, status, motiv,
                    cantitate_estimata, um, produs_id, ordine)
                 VALUES (:l, :o, :s, :c, :op, :t, :cat, :r, :st, :m, :q, :um, :p, :ord)'
            );
            foreach ($draft['items'] as $i => $item) {
                $itemInsert->execute([
                    ':l' => $orderId, ':o' => $item['operatie_id'], ':s' => $item['sectiune'], ':c' => $item['componenta'], ':op' => $item['operatie'],
                    ':t' => $item['tip_operatie'], ':cat' => $item['categorie'], ':r' => json_encode($item['regula'] + ['sistem' => $item['sistem']], JSON_UNESCAPED_UNICODE),
                    ':st' => $item['status'], ':m' => $item['motiv'], ':q' => $item['cantitate_estimata'], ':um' => $item['um'], ':p' => $item['produs_id'], ':ord' => ($i + 1) * 10,
                ]);
            }
            $this->updateOrderFields($orderId, $input, $status);
            $this->saveItems($orderId, $input['items'] ?? [], false);
            $this->model->audit($orderId, 'lucrare_noua', sprintf('Lucrare %s creată (%s)', $draft['label'], $status), null, ['vehicle_id' => $vehicleId, 'tip' => $draft['tip']], $userId);

            return $orderId;
        });
    }

    /** @param array<string, mixed> $input */
    private function updateOrderFields(int $orderId, array $input, ?string $status): void
    {
        $stmt = $this->db->prepare(
            'UPDATE revizii_v2_lucrari SET status = COALESCE(:s, status), data_programata = :dp, data_executie = :de, km_executie = :km, km_sursa = :ks,
                ore_executie = :ore, tehnician = :teh, observatii = :obs, cost_manopera = :cm WHERE id = :id'
        );
        $stmt->execute([
            ':s' => $status,
            ':dp' => self::date($input['data_programata'] ?? null),
            ':de' => self::dateTime($input['data_executie'] ?? null),
            ':km' => self::decimal($input['km_executie'] ?? null),
            ':ks' => in_array($input['km_sursa'] ?? '', ['can', 'gps', 'contor', 'manual'], true) ? $input['km_sursa'] : null,
            ':ore' => self::decimal($input['ore_executie'] ?? null),
            ':teh' => self::text($input['tehnician'] ?? null, 120),
            ':obs' => self::text($input['observatii'] ?? null, 4000),
            ':cm' => self::decimal($input['cost_manopera'] ?? null),
            ':id' => $orderId,
        ]);
    }

    /** @param array<int|string, mixed> $items */
    private function saveItems(int $orderId, array $items, bool $strict): void
    {
        $set = 'SET status = :s, motiv = :m, cantitate_reala = :q, um = COALESCE(:um, um), produs_id = :p, recipient_id = :r, cost_material = :c, observatii = :o';
        $byId = $this->db->prepare('UPDATE revizii_v2_lucrari_operatii ' . $set . ' WHERE id = :id AND lucrare_id = :l AND executie_id IS NULL');
        // Lucrare noua: liniile nu au inca id in formular; se identifica prin operatiune.
        $byOperation = $this->db->prepare('UPDATE revizii_v2_lucrari_operatii ' . $set . ' WHERE operatie_id = :id AND lucrare_id = :l AND executie_id IS NULL');
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $useOperation = (int) ($item['id'] ?? 0) <= 0;
            $update = $useOperation ? $byOperation : $byId;
            $key = $useOperation ? (int) ($item['operatie_id'] ?? 0) : (int) $item['id'];
            if ($key <= 0) {
                continue;
            }
            $status = (string) ($item['status'] ?? 'pending');
            if (!isset(ReviziiV2Model::ITEM_STATUSES[$status])) {
                throw new InvalidArgumentException('Stare necunoscută pentru o operațiune.');
            }
            $update->execute([
                ':s' => $status, ':m' => self::text($item['motiv'] ?? null, 255), ':q' => self::decimal($item['cantitate_reala'] ?? null),
                ':um' => self::text($item['um'] ?? null, 10), ':p' => self::posInt($item['produs_id'] ?? null), ':r' => self::posInt($item['recipient_id'] ?? null),
                ':c' => self::decimal($item['cost_material'] ?? null), ':o' => self::text($item['observatii'] ?? null, 500), ':id' => $key, ':l' => $orderId,
            ]);
        }
    }

    /**
     * Finalizarea lucrarii (confirmare explicita). Atomic:
     *  1. salveaza datele; 2. valideaza (nicio operatiune neinchisa; motiv la amanare / nu se aplica);
     *  3. stoc pentru fluidele cu recipient (idempotent); 4. executiile pe operatiuni (resetarea
     *  referintelor doar pentru cele efectuate); 5. revizia e „completa” (muta referintele reviziei si,
     *  la Y, tinta urmatoarei Y) doar daca TOATE sunt efectuate / nu se aplica; 6. audit.
     *
     * @param array<string, mixed> $input
     * @return array{status: string, completa: bool, executii: int}
     */
    public function finalizeWorkOrder(int $orderId, array $input, ?int $userId): array
    {
        return $this->transaction(function () use ($orderId, $input, $userId): array {
            $order = $this->model->workOrder($orderId, true) ?? throw new InvalidArgumentException('Lucrarea nu mai există.');
            if (!in_array($order['status'], ReviziiV2Model::OPEN_STATUSES, true)) {
                throw new InvalidArgumentException('Lucrarea este deja închisă.');
            }
            $this->updateOrderFields($orderId, $input, null);
            $this->saveItems($orderId, $input['items'] ?? [], true);
            $order = $this->model->workOrder($orderId, true);
            $vehicle = $this->load()['vehicles'][(int) $order['vehicle_id']] ?? throw new InvalidArgumentException('Vehiculul nu mai este activ.');

            $errors = [];
            if ($order['data_executie'] === null) {
                $errors[] = 'Completează data execuției.';
            } elseif ((string) $order['data_executie'] > date('Y-m-d H:i:s', time() + 600)) {
                $errors[] = 'Data execuției nu poate fi în viitor.';
            }
            if ($order['km_executie'] === null && $vehicle['familie'] !== 'semiremorca') {
                $errors[] = 'Completează km la execuție.';
            }
            if (trim((string) $order['tehnician']) === '') {
                $errors[] = 'Completează tehnicianul responsabil.';
            }
            $complete = true;
            $open = [];
            $noReason = [];
            foreach ($order['items'] as $item) {
                if (in_array($item['status'], ['pending', 'in_progress'], true)) {
                    $open[] = '„' . $item['operatie'] . '”';
                }
                if (in_array($item['status'], ['na', 'deferred', 'blocked'], true) && trim((string) $item['motiv']) === '') {
                    $noReason[] = '„' . $item['operatie'] . '”';
                }
                if ($item['recipient_id'] !== null && $item['status'] === 'completed' && $item['cantitate_reala'] === null) {
                    $errors[] = '„' . $item['operatie'] . '”: cantitatea folosită este obligatorie când se ia din stoc.';
                }
                if (in_array($item['status'], ['deferred', 'blocked'], true)) {
                    $complete = false;
                }
            }
            $list = static fn (array $names): string => implode(', ', array_slice($names, 0, 3)) . (count($names) > 3 ? ' și încă ' . (count($names) - 3) : '');
            if ($open !== []) {
                $errors[] = sprintf('%d operațiuni fără rezultat (efectuat / nu se aplică / amânat / blocat): %s.', count($open), $list($open));
            }
            if ($noReason !== []) {
                $errors[] = sprintf('Motivul este obligatoriu la „Nu se aplică / Amânat / Blocat”: %s.', $list($noReason));
            }
            if ($errors !== []) {
                throw new InvalidArgumentException(implode(' ', array_unique($errors)));
            }

            $at = (string) $order['data_executie'];
            $stock = null;
            $materials = 0.0;
            $created = 0;
            foreach ($order['items'] as $item) {
                if ($item['status'] !== 'completed') {
                    continue;
                }
                if ($item['executie_id'] !== null) {
                    // Deja efectuata printr-o inregistrare independenta legata de aceasta linie: aceeasi executie, fara dublare.
                    $materials += (float) $item['cost_material'];
                    continue;
                }
                $movementId = $item['miscare_id'] !== null ? (int) $item['miscare_id'] : null;
                $cost = $item['cost_material'] !== null ? (float) $item['cost_material'] : null;
                if ($item['recipient_id'] !== null && $movementId === null) {
                    $stock ??= $this->stockService();
                    $rule = $item['regula_snapshot'] !== null ? json_decode((string) $item['regula_snapshot'], true) : [];
                    $systemId = $this->systemIdFor((int) $order['vehicle_id'], (string) ($rule['sistem'] ?? ''));
                    $result = $stock->operate($item['tip_operatie'] === 'completare' ? 'completare' : 'schimb_complet', [
                        'idempotency_key' => 'rv2-item-' . $item['id'],
                        'efectuat_la' => $at,
                        'recipient_sursa_id' => (int) $item['recipient_id'],
                        'vehicul_id' => (int) $order['vehicle_id'],
                        'cantitate' => (string) $item['cantitate_reala'],
                        'km_vehicul' => $order['km_executie'] !== null ? (int) round((float) $order['km_executie']) : null,
                        'sistem_id' => $systemId,
                        'observatii' => 'Revizie #' . $orderId . ': ' . $item['operatie'],
                    ], $userId);
                    $movementId = (int) ($result['movement']['id'] ?? 0) ?: null;
                    if ($cost === null && isset($result['movement']['valoare']) && $result['movement']['valoare'] !== null) {
                        $cost = (float) $result['movement']['valoare'];
                    }
                }
                $executionId = $this->model->insertExecution([
                    'vehicle_id' => (int) $order['vehicle_id'], 'operatie_id' => (int) $item['operatie_id'], 'lucrare_id' => $orderId,
                    'tip_executie' => (string) $item['tip_operatie'], 'reseteaza' => ReviziiV2Engine::resetsBaseline((string) $item['tip_operatie']),
                    'data_executie' => $at, 'km' => $order['km_executie'], 'km_sursa' => $order['km_sursa'], 'ore' => $order['ore_executie'],
                    'cantitate' => $item['cantitate_reala'], 'um' => $item['um'], 'produs_id' => $item['produs_id'], 'miscare_id' => $movementId,
                    'cost' => $cost, 'tehnician' => $order['tehnician'], 'observatii' => $item['observatii'],
                    'idempotency_key' => 'rv2-item-' . $item['id'], 'created_by' => $userId,
                ]);
                $this->db->prepare('UPDATE revizii_v2_lucrari_operatii SET executie_id = :e, miscare_id = :m, cost_material = :c WHERE id = :id')
                    ->execute([':e' => $executionId, ':m' => $movementId, ':c' => $cost, ':id' => (int) $item['id']]);
                $materials += (float) $cost;
                $created++;
            }

            $status = $complete ? 'finalizata' : 'partiala';
            $labor = $order['cost_manopera'] !== null ? (float) $order['cost_manopera'] : 0.0;
            $this->db->prepare(
                'UPDATE revizii_v2_lucrari SET status = :s, activ_vehicle_id = NULL, completa = :a, cost_materiale = :cm, cost_total = :ct,
                    finalized_by = :u, finalized_at = NOW() WHERE id = :id'
            )->execute([':s' => $status, ':a' => $complete ? 1 : 0, ':cm' => round($materials, 2), ':ct' => round($materials + $labor, 2), ':u' => $userId, ':id' => $orderId]);
            $this->model->audit($orderId, 'lucrare_finalizata', sprintf('Revizie %s %s (%d operațiuni efectuate)', $order['tip'], $status, $created), null,
                ['status' => $status, 'completa' => $complete, 'nr_x' => (int) $order['nr_x'], 'nr_y' => (int) $order['nr_y']], $userId);
            $this->reset();

            return ['status' => $status, 'completa' => $complete, 'executii' => $created];
        });
    }

    public function cancelWorkOrder(int $orderId, string $reason, ?int $userId): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Motivul anulării este obligatoriu.');
        }
        $this->transaction(function () use ($orderId, $reason, $userId): void {
            $order = $this->model->workOrder($orderId, true) ?? throw new InvalidArgumentException('Lucrarea nu mai există.');
            if (!in_array($order['status'], ReviziiV2Model::OPEN_STATUSES, true)) {
                throw new InvalidArgumentException('Doar o lucrare deschisă poate fi anulată.');
            }
            $this->db->prepare("UPDATE revizii_v2_lucrari SET status = 'anulata', activ_vehicle_id = NULL, anulat_motiv = :m WHERE id = :id")
                ->execute([':m' => mb_substr($reason, 0, 255), ':id' => $orderId]);
            $this->model->audit($orderId, 'lucrare_anulata', 'Lucrare revizie anulată: ' . $reason, null, null, $userId);
        });
        $this->reset();
    }

    /**
     * Operatiune inregistrata independent (gresare, completare ulei, inlocuire filtru, inspectie).
     * Aceeasi istorie ca la revizii: daca vehiculul are o lucrare deschisa cu aceeasi operatiune
     * neefectuata, linia se marcheaza efectuata cu aceasta executie (fara dublare).
     *
     * @param array<string, mixed> $input
     */
    public function recordOperation(array $input, ?int $userId): int
    {
        $vehicleId = (int) ($input['vehicle_id'] ?? 0);
        $operationId = (int) ($input['operatie_id'] ?? 0);
        $data = $this->load();
        $vehicle = $data['vehicles'][$vehicleId] ?? throw new InvalidArgumentException('Vehicul inexistent sau inactiv.');
        $operation = $data['operations'][$operationId] ?? throw new InvalidArgumentException('Operațiune necunoscută.');
        $type = (string) ($input['tip_executie'] ?? $operation['tip_operatie']);
        if (!isset(ReviziiV2Model::OPERATION_TYPES[$type])) {
            throw new InvalidArgumentException('Tip de execuție necunoscut.');
        }
        $at = self::dateTime($input['data_executie'] ?? null) ?? throw new InvalidArgumentException('Completează data execuției.');
        if ($at > date('Y-m-d H:i:s', time() + 600)) {
            throw new InvalidArgumentException('Data execuției nu poate fi în viitor.');
        }
        $key = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($input['idempotency_key'] ?? '')) ?: ('rv2-op-' . bin2hex(random_bytes(8)));
        $key = substr($key, 0, 80);
        $quantity = self::decimal($input['cantitate'] ?? null);
        $recipient = self::posInt($input['recipient_id'] ?? null);
        if ($recipient !== null && $quantity === null) {
            throw new InvalidArgumentException('Cantitatea este obligatorie când se ia din stoc.');
        }
        $km = self::decimal($input['km'] ?? null);

        $id = $this->transaction(function () use ($vehicleId, $operationId, $operation, $type, $at, $key, $quantity, $recipient, $km, $input, $userId): int {
            $existing = $this->db->prepare('SELECT id FROM revizii_v2_executii WHERE idempotency_key = :k');
            $existing->execute([':k' => $key]);
            if (($found = $existing->fetchColumn()) !== false) {
                return (int) $found;
            }
            $movementId = null;
            $cost = self::decimal($input['cost'] ?? null);
            if ($recipient !== null) {
                $result = $this->stockService()->operate($type === 'completare' ? 'completare' : 'schimb_complet', [
                    'idempotency_key' => $key, 'efectuat_la' => $at, 'recipient_sursa_id' => $recipient, 'vehicul_id' => $vehicleId,
                    'cantitate' => $quantity, 'km_vehicul' => $km !== null ? (int) round((float) $km) : null,
                    'sistem_id' => $this->systemIdFor($vehicleId, (string) ($operation['sistem_fluid'] ?? '')),
                    'observatii' => 'Revizii: ' . $operation['operatie'],
                ], $userId);
                $movementId = (int) ($result['movement']['id'] ?? 0) ?: null;
            }
            $executionId = $this->model->insertExecution([
                'vehicle_id' => $vehicleId, 'operatie_id' => $operationId, 'lucrare_id' => null, 'tip_executie' => $type,
                'reseteaza' => ReviziiV2Engine::resetsBaseline($type), 'data_executie' => $at, 'km' => $km,
                'km_sursa' => in_array($input['km_sursa'] ?? '', ['can', 'gps', 'contor', 'manual'], true) ? $input['km_sursa'] : 'manual',
                'ore' => self::decimal($input['ore'] ?? null), 'cantitate' => $quantity, 'um' => self::text($input['um'] ?? null, 10),
                'produs_id' => self::posInt($input['produs_id'] ?? null), 'miscare_id' => $movementId, 'cost' => $cost,
                'tehnician' => self::text($input['tehnician'] ?? null, 120), 'observatii' => self::text($input['observatii'] ?? null, 500),
                'idempotency_key' => $key, 'created_by' => $userId,
            ]);
            if (ReviziiV2Engine::resetsBaseline($type)) {
                // Lucrare deschisa cu aceeasi operatiune: linia devine efectuata cu aceasta executie (fara dubla evidenta).
                $this->db->prepare(
                    "UPDATE revizii_v2_lucrari_operatii lo JOIN revizii_v2_lucrari l ON l.id = lo.lucrare_id
                     SET lo.status = 'completed', lo.executie_id = :e, lo.motiv = 'Înregistrată separat (aceeași execuție).'
                     WHERE l.vehicle_id = :v AND l.status IN ('programata', 'in_desfasurare') AND lo.operatie_id = :o AND lo.executie_id IS NULL"
                )->execute([':e' => $executionId, ':v' => $vehicleId, ':o' => $operationId]);
            }
            $this->model->audit($executionId, 'executie', ReviziiV2Model::OPERATION_TYPES[$type] . ': ' . $operation['operatie'], null, ['vehicle_id' => $vehicleId, 'data' => $at], $userId);

            return $executionId;
        });
        $this->reset();

        return $id;
    }

    /** Anulare logica a unei executii independente (corectie autorizata, cu motiv). */
    public function cancelExecution(int $executionId, string $reason, ?int $userId): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Motivul este obligatoriu.');
        }
        $stmt = $this->db->prepare('SELECT * FROM revizii_v2_executii WHERE id = :id');
        $stmt->execute([':id' => $executionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: throw new InvalidArgumentException('Execuția nu există.');
        if ($row['anulat_la'] !== null) {
            return;
        }
        if ($row['lucrare_id'] !== null) {
            throw new InvalidArgumentException('Execuțiile dintr-o revizie finalizată nu se anulează individual (istoricul reviziei rămâne neschimbat).');
        }
        $this->db->prepare('UPDATE revizii_v2_executii SET anulat_la = NOW(), anulat_motiv = :m WHERE id = :id')->execute([':m' => mb_substr($reason, 0, 255), ':id' => $executionId]);
        $this->model->audit($executionId, 'executie_anulata', 'Execuție anulată: ' . $reason, $row, null, $userId);
        $this->reset();
    }

    // =====================================================================
    // Istoric
    // =====================================================================

    /**
     * Istoricul: lucrarile inchise (cu operatiunile lor) + executiile independente.
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function history(array $filters, int $limit = 500): array
    {
        $where = ["l.status IN ('finalizata', 'partiala', 'anulata')"];
        $params = [];
        if (!empty($filters['vehicle_id'])) {
            $where[] = 'l.vehicle_id = :v';
            $params[':v'] = (int) $filters['vehicle_id'];
        }
        if (!empty($filters['tip']) && in_array($filters['tip'], ['X', 'Y'], true)) {
            $where[] = 'l.tip = :t';
            $params[':t'] = $filters['tip'];
        }
        if (!empty($filters['status']) && isset(ReviziiV2Model::WORK_STATUSES[$filters['status']])) {
            $where[] = 'l.status = :s';
            $params[':s'] = $filters['status'];
        }
        if (!empty($filters['de_la'])) {
            $where[] = 'COALESCE(l.data_executie, l.updated_at) >= :f';
            $params[':f'] = self::date($filters['de_la']) . ' 00:00:00';
        }
        if (!empty($filters['pana_la'])) {
            $where[] = 'COALESCE(l.data_executie, l.updated_at) <= :to';
            $params[':to'] = self::date($filters['pana_la']) . ' 23:59:59';
        }
        if (!empty($filters['tehnician'])) {
            $where[] = 'l.tehnician LIKE :teh';
            $params[':teh'] = '%' . $filters['tehnician'] . '%';
        }
        if (!empty($filters['operatie_id'])) {
            $where[] = 'EXISTS (SELECT 1 FROM revizii_v2_lucrari_operatii x WHERE x.lucrare_id = l.id AND x.operatie_id = :op)';
            $params[':op'] = (int) $filters['operatie_id'];
        }
        $stmt = $this->db->prepare(
            'SELECT l.id, l.vehicle_id, v.nr_inmatriculare AS nr, l.tip, l.nr_x, l.nr_y, l.km_tinta, l.status, l.data_executie, l.km_executie, l.km_sursa,
                    l.ore_executie, l.tehnician, l.observatii, l.cost_manopera, l.cost_materiale, l.cost_total, l.completa, l.anulat_motiv,
                    l.snapshot, f.nume AS finalized_by_name,
                    (SELECT COUNT(*) FROM revizii_v2_lucrari_operatii x WHERE x.lucrare_id = l.id AND x.status = \'completed\') AS efectuate,
                    (SELECT COUNT(*) FROM revizii_v2_lucrari_operatii x WHERE x.lucrare_id = l.id) AS total_operatii,
                    (SELECT COUNT(*) FROM revizii_v2_documente d WHERE d.lucrare_id = l.id AND d.deleted_at IS NULL) AS documente
             FROM revizii_v2_lucrari l JOIN vehicule v ON v.id = l.vehicle_id LEFT JOIN utilizatori f ON f.id = l.finalized_by
             WHERE ' . implode(' AND ', $where) . ' ORDER BY COALESCE(l.data_executie, l.updated_at) DESC, l.id DESC LIMIT ' . max(1, $limit)
        );
        $stmt->execute($params);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $snapshot = $row['snapshot'] !== null ? json_decode((string) $row['snapshot'], true) : [];
            $row['label'] = ReviziiV2Engine::revisionLabel((string) $row['tip'], (int) $row['nr_x']);
            $row['plan'] = $snapshot['plan']['nume'] ?? null;
            $row['kind'] = 'lucrare';
            unset($row['snapshot']);
            $rows[] = $row;
        }

        if (empty($filters['tip']) && (empty($filters['status']) || $filters['status'] === 'finalizata')) {
            $where = ['e.lucrare_id IS NULL'];
            $params = [];
            if (!empty($filters['vehicle_id'])) {
                $where[] = 'e.vehicle_id = :v';
                $params[':v'] = (int) $filters['vehicle_id'];
            }
            if (!empty($filters['operatie_id'])) {
                $where[] = 'e.operatie_id = :op';
                $params[':op'] = (int) $filters['operatie_id'];
            }
            if (!empty($filters['de_la'])) {
                $where[] = 'e.data_executie >= :f';
                $params[':f'] = self::date($filters['de_la']) . ' 00:00:00';
            }
            if (!empty($filters['pana_la'])) {
                $where[] = 'e.data_executie <= :to';
                $params[':to'] = self::date($filters['pana_la']) . ' 23:59:59';
            }
            if (!empty($filters['tehnician'])) {
                $where[] = 'e.tehnician LIKE :teh';
                $params[':teh'] = '%' . $filters['tehnician'] . '%';
            }
            $stmt = $this->db->prepare(
                'SELECT e.id, e.vehicle_id, v.nr_inmatriculare AS nr, e.tip_executie, e.data_executie, e.km AS km_executie, e.km_sursa, e.ore AS ore_executie,
                        e.tehnician, e.observatii, e.cost AS cost_total, e.cantitate, e.um, e.anulat_la, e.anulat_motiv, o.operatie, o.componenta, p.denumire AS produs
                 FROM revizii_v2_executii e JOIN vehicule v ON v.id = e.vehicle_id JOIN revizii_v2_operatii o ON o.id = e.operatie_id
                 LEFT JOIN consumabile_produse p ON p.id = e.produs_id
                 WHERE ' . implode(' AND ', $where) . ' ORDER BY e.data_executie DESC, e.id DESC LIMIT ' . max(1, $limit)
            );
            try {
                $stmt->execute($params);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $row['kind'] = 'operatie';
                    $row['label'] = ReviziiV2Model::OPERATION_TYPES[$row['tip_executie']] ?? $row['tip_executie'];
                    $row['status'] = $row['anulat_la'] !== null ? 'anulata' : 'finalizata';
                    $rows[] = $row;
                }
            } catch (PDOException $exception) {
                error_log('[ReviziiV2][history] ' . $exception->getMessage());
            }
        }
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $b['data_executie'], (string) $a['data_executie']));

        return array_slice($rows, 0, $limit);
    }

    /** Detaliul unei lucrari din istoric (instantaneul operatiunilor, neschimbat de sabloanele noi). */
    public function historyDetail(int $orderId): array
    {
        $order = $this->model->workOrder($orderId) ?? throw new InvalidArgumentException('Lucrarea nu există.');
        $order = $this->decorateOrder($order);
        $order['snapshot'] = $order['snapshot'] !== null ? json_decode((string) $order['snapshot'], true) : null;
        $vehicle = $this->db->prepare('SELECT nr_inmatriculare, marca, model FROM vehicule WHERE id = :id');
        $vehicle->execute([':id' => (int) $order['vehicle_id']]);
        $order['vehicul'] = $vehicle->fetch(PDO::FETCH_ASSOC) ?: null;
        $products = [];
        foreach ($order['items'] as $item) {
            if ($item['produs_id'] !== null) {
                $products[(int) $item['produs_id']] = true;
            }
        }
        if ($products !== [] && $this->tableExists('consumabile_produse')) {
            $rows = $this->db->query('SELECT id, denumire, vascozitate, specificatie FROM consumabile_produse WHERE id IN (' . implode(',', array_keys($products)) . ')')->fetchAll(PDO::FETCH_ASSOC);
            $map = [];
            foreach ($rows as $row) {
                $map[(int) $row['id']] = $row;
            }
            foreach ($order['items'] as &$item) {
                $item['produs'] = $item['produs_id'] !== null ? ($map[(int) $item['produs_id']] ?? null) : null;
            }
            unset($item);
        }

        return $order;
    }

    /** Export Excel al istoricului filtrat (SimpleXlsxWriter, ca restul aplicatiei). */
    public function exportHistory(array $filters, string $path): void
    {
        require_once __DIR__ . '/SimpleXlsxWriter.php';
        $rows = $this->history($filters, 5000);
        $xlsx = new SimpleXlsxWriter();
        $sheet = $xlsx->addSheet('Istoric revizii');
        $header = ['Data', 'Nr. auto', 'Tip', 'Revizie / operațiune', 'Stare', 'Km', 'Sursă km', 'Ore motor', 'Tehnician', 'Operațiuni efectuate', 'Manoperă (lei)', 'Materiale (lei)', 'Total (lei)', 'Observații'];
        $xlsx->addRow($sheet, $header);
        foreach ($rows as $row) {
            $xlsx->addRow($sheet, [
                $row['data_executie'] !== null ? date('d.m.Y H:i', (int) strtotime((string) $row['data_executie'])) : '',
                (string) $row['nr'],
                $row['kind'] === 'lucrare' ? 'Revizie ' . $row['tip'] : 'Operațiune',
                $row['kind'] === 'lucrare' ? 'Revizia ' . $row['label'] . ($row['km_tinta'] !== null ? ' (țintă ' . ReviziiV2Engine::fmt((float) $row['km_tinta']) . ' km)' : '') : $row['label'] . ': ' . $row['operatie'],
                ReviziiV2Model::WORK_STATUSES[$row['status']] ?? $row['status'],
                $row['km_executie'] !== null ? (float) $row['km_executie'] : '',
                strtoupper((string) ($row['km_sursa'] ?? '')),
                $row['ore_executie'] !== null ? (float) $row['ore_executie'] : '',
                (string) ($row['tehnician'] ?? ''),
                $row['kind'] === 'lucrare' ? $row['efectuate'] . ' / ' . $row['total_operatii'] : '1',
                isset($row['cost_manopera']) && $row['cost_manopera'] !== null ? (float) $row['cost_manopera'] : '',
                isset($row['cost_materiale']) && $row['cost_materiale'] !== null ? (float) $row['cost_materiale'] : '',
                $row['cost_total'] !== null ? (float) $row['cost_total'] : '',
                (string) ($row['observatii'] ?? ''),
            ]);
        }
        $xlsx->setColumnWidths($sheet, [16, 12, 12, 40, 16, 12, 9, 10, 20, 12, 12, 12, 12, 40]);
        $xlsx->freezeRows($sheet, 1);
        $xlsx->save($path);
    }

    // =====================================================================
    // Simulare (explicatie pe datele reale)
    // =====================================================================

    /** @return array<string, mixed> pasii calculului pentru urmatoarea revizie a vehiculului */
    public function explain(int $vehicleId): array
    {
        $data = $this->load();
        $vehicle = $data['vehicles'][$vehicleId] ?? throw new InvalidArgumentException('Vehicul inexistent sau inactiv.');
        $summary = $this->vehicleSummary($vehicle);
        $steps = [];
        $plan = $summary['plan'];
        $steps[] = ['titlu' => 'Plan aplicat', 'text' => $plan === null ? 'Niciun plan nu se potrivește (tip / marcă / model) și nu e asignat explicit: vehiculul rămâne „Neconfigurat”.'
            : sprintf('„%s” (%s).', $plan['nume'], $plan['match'] === 'vehicul' ? 'asignat explicit vehiculului' : 'cel mai specific plan după tip / marcă / model')];
        $km = $summary['km'];
        $steps[] = ['titlu' => 'Odometru', 'text' => $km['value'] === null ? 'Nicio citire validă: criteriile pe km nu se pot evalua.'
            : sprintf('%s km din %s (%s)%s.', ReviziiV2Engine::fmt($km['value']), strtoupper((string) $km['source']), $km['at'] !== null ? date('d.m.Y H:i', (int) strtotime($km['at'])) : 'contor',
                $km['stale'] ? ' — date învechite' : '') . ($km['note'] ? ' ' . $km['note'] : '')];
        if ($plan === null) {
            return ['vehicle' => $summary, 'steps' => $steps];
        }
        $plan = $this->load()['plans'][$plan['id']];
        $seq = $summary['secventa'];
        $next = $summary['next'];
        $steps[] = ['titlu' => 'Programarea pe km', 'text' => sprintf('Revizia X la fiecare %s km (%s); Revizia Y %s. Numărul de X dintre două Y rezultă din km, nu e fix.',
            $plan['interval_km'] !== null ? ReviziiV2Engine::fmt((int) $plan['interval_km']) : '—',
            mb_strtolower(ReviziiV2Engine::X_POLICIES[$seq['policy']]), $seq['y_target'] !== null ? 'la ' . ReviziiV2Engine::fmt($seq['y_target']) . ' km' : 'neprogramată (fără prag / interval / referință)')];
        $steps[] = ['titlu' => 'Secvența reală', 'text' => sprintf('%d revizii X de la ultima Y. Următoarea X la %s km, următoarea Y la %s km (toleranță de coincidență %s km) → urmează Revizia %s la %s km.%s',
            $seq['x_done'], ReviziiV2Engine::fmt($seq['x_target']), ReviziiV2Engine::fmt($seq['y_target']), ReviziiV2Engine::fmt($seq['tolerance_km']),
            $next['label'], ReviziiV2Engine::fmt($next['km_target']), $seq['x_before_y'] !== null ? ' Până la Y: ' . $seq['x_before_y'] . ' revizii X în total.' : '')];
        if ($summary['info'] !== null) {
            $steps[] = ['titlu' => 'X și Y', 'text' => $summary['info']];
        }
        $last = $summary['last'];
        $steps[] = ['titlu' => 'Referința reviziei', 'text' => $last === null ? 'Nicio revizie finalizată și nicio referință declarată la preluare: criteriile nu au punct de plecare.'
            : sprintf('%s din %s%s.', $last['label'], date('d.m.Y', (int) strtotime($last['data'])), $last['km'] !== null ? ' la ' . ReviziiV2Engine::fmt($last['km']) . ' km' : ' (fără km)')];
        foreach ($summary['next']['criteria'] as $c) {
            $label = ['km' => 'Kilometri', 'ore' => 'Ore motor', 'calendar' => 'Calendar'][$c['tip']];
            $steps[] = ['titlu' => $label, 'text' => !$c['measured'] ? sprintf('Interval %s %s — nemăsurat (lipsesc datele).', ReviziiV2Engine::fmt($c['interval']), $c['unit'])
                : sprintf('Consumat %s din %s %s (%s%%), rămas %s %s; prag avertizare %s → %s.', ReviziiV2Engine::fmt($c['used']), ReviziiV2Engine::fmt($c['interval']),
                    $c['tip'] === 'calendar' ? 'luni (în zile: ' . ReviziiV2Engine::fmt($c['used']) . ')' : $c['unit'], ReviziiV2Engine::fmt($c['pct'], 1),
                    ReviziiV2Engine::fmt($c['remaining']), $c['tip'] === 'calendar' ? 'zile (scadență ' . date('d.m.Y', (int) strtotime((string) $c['due_date'])) . ')' : $c['unit'],
                    ReviziiV2Engine::fmt($c['prag']), ReviziiV2Engine::STATUS_LABELS[$c['status']])];
        }
        $due = array_values(array_filter($summary['ops'], static fn (array $op): bool => ReviziiV2Engine::isUrgent($op['status'])));
        $steps[] = ['titlu' => 'Operațiuni cu scadență proprie', 'text' => $due === [] ? 'Nicio operațiune nu este scadentă independent de revizie.'
            : implode('; ', array_map(static fn (array $op): string => $op['op'] . ' (' . ReviziiV2Engine::STATUS_LABELS[$op['status']] . ')', $due)) . ' — nu se amână până la următoarea revizie.'];
        $steps[] = ['titlu' => 'Rezultat', 'text' => sprintf('Stare vehicul: %s. Revizia %s%s%s.', ReviziiV2Engine::STATUS_LABELS[$summary['status']], $summary['next']['label'],
            $summary['next']['km_target'] !== null ? ' la ' . ReviziiV2Engine::fmt($summary['next']['km_target']) . ' km' : '',
            $summary['next']['data_estimata'] !== null ? ', estimat ' . date('d.m.Y', (int) strtotime((string) $summary['next']['data_estimata'])) : '')];

        return ['vehicle' => $summary, 'steps' => $steps];
    }

    // =====================================================================
    // Configurare
    // =====================================================================

    /** @return array<string, mixed> */
    public function configData(): array
    {
        $data = $this->load();
        $vehicles = [];
        foreach ($data['vehicles'] as $vehicle) {
            $plan = $this->resolvePlan($vehicle);
            $vehicles[] = [
                'id' => (int) $vehicle['id'], 'nr' => $vehicle['nr_inmatriculare'], 'marca' => $vehicle['marca'], 'model' => $vehicle['model'],
                'familie' => $vehicle['familie'], 'plan_id' => $plan['id'] ?? null, 'plan' => $plan['nume'] ?? null, 'match' => $plan['_match'] ?? null,
                'asignare' => $data['assignments'][(int) $vehicle['id']] ?? null, 'exceptii' => array_values($data['vehicle_rules'][(int) $vehicle['id']] ?? []),
            ];
        }
        $products = [];
        if ($this->tableExists('consumabile_produse')) {
            $products = $this->db->query('SELECT id, denumire, tip_fluid, vascozitate, specificatie, um FROM consumabile_produse WHERE activ = 1 ORDER BY denumire')->fetchAll(PDO::FETCH_ASSOC);
        }
        $makes = [];
        foreach ($data['vehicles'] as $vehicle) {
            $makes[(string) $vehicle['marca']][(string) $vehicle['model']] = true;
        }

        return [
            'operatii' => array_values($data['operations']),
            'sabloane' => array_values($data['templates']),
            'planuri' => array_values(array_map(static function (array $plan): array {
                $plan['reguli'] = array_values($plan['reguli']);
                return $plan;
            }, $data['plans'])),
            'vehicule' => $vehicles,
            'setari' => $data['settings'],
            'produse' => $products,
            'marci' => array_map(static fn (array $models): array => array_keys($models), $makes),
            'tipuri' => ReviziiV2Model::OPERATION_TYPES,
            'categorii' => ReviziiV2Model::CATEGORIES,
            'familii' => ReviziiV2Model::VEHICLE_TYPES,
        ];
    }

    /** @param array<string, mixed> $input */
    public function saveOperationConfig(array $input, ?int $userId): int
    {
        $id = self::posInt($input['id'] ?? null);
        $component = self::text($input['componenta'] ?? null, 120);
        $operation = self::text($input['operatie'] ?? null, 190);
        $type = (string) ($input['tip_operatie'] ?? '');
        if ($component === null || $operation === null || !isset(ReviziiV2Model::OPERATION_TYPES[$type])) {
            throw new InvalidArgumentException('Componenta, operațiunea și tipul sunt obligatorii.');
        }
        $applies = array_values(array_intersect(array_keys(ReviziiV2Model::VEHICLE_TYPES), (array) ($input['aplicabil'] ?? [])));
        if ($applies === []) {
            throw new InvalidArgumentException('Alege cel puțin un tip de vehicul pe care se aplică operațiunea.');
        }
        $category = isset(ReviziiV2Model::CATEGORIES[$input['categorie'] ?? '']) ? (string) $input['categorie'] : 'altele';
        $data = [
            'componenta' => $component, 'tip_operatie' => $type, 'operatie' => $operation, 'categorie' => $category,
            'sistem_fluid' => in_array($input['sistem_fluid'] ?? '', ['motor', 'transmisie', 'diferential', 'servodirectie', 'hidraulic_pompa', 'racire', 'frana'], true) ? $input['sistem_fluid'] : null,
            'aplicabil' => implode(',', $applies), 'factori_uzura' => self::text($input['factori_uzura'] ?? null, 500),
            'criterii_candidate' => self::text($input['criterii_candidate'] ?? null, 120), 'valori_candidate' => self::text($input['valori_candidate'] ?? null, 255),
            'component_key' => self::text($input['component_key'] ?? null, 10), 'lubrifiant' => self::text($input['lubrifiant'] ?? null, 120),
            'puncte_gresare' => self::posInt($input['puncte_gresare'] ?? null), 'descriere' => self::text($input['descriere'] ?? null, 2000),
            'de_verificat' => !empty($input['de_verificat']) ? 1 : 0, 'activ' => !isset($input['activ']) || !empty($input['activ']) ? 1 : 0,
            'ordine' => (int) ($input['ordine'] ?? 500),
        ];
        if ($id === null) {
            $data['cod'] = substr(self::slug($operation), 0, 48) . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
        }
        $result = $this->model->saveOperation($data, $id, $userId);
        $this->reset();

        return $result;
    }

    /** @param array<string, mixed> $input */
    public function saveTemplateConfig(array $input, ?int $userId): int
    {
        $name = self::text($input['nume'] ?? null, 120) ?? throw new InvalidArgumentException('Numele șablonului este obligatoriu.');
        $type = ($input['tip'] ?? 'X') === 'Y' ? 'Y' : 'X';
        $operations = array_values(array_filter(array_map('intval', (array) ($input['operatii'] ?? [])), fn (int $id): bool => isset($this->load()['operations'][$id])));
        $parent = self::posInt($input['parinte_id'] ?? null);
        if ($type === 'Y' && ($parent === null || ($this->load()['templates'][$parent]['tip'] ?? '') !== 'X')) {
            throw new InvalidArgumentException('Alege șablonul X moștenit de Y.');
        }
        $id = $this->transaction(fn (): int => $this->model->saveTemplate(self::posInt($input['id'] ?? null), $name, $type, $parent, $operations, !isset($input['activ']) || !empty($input['activ']), $userId));
        $this->reset();

        return $id;
    }

    /** @param array<string, mixed> $input */
    public function savePlanConfig(array $input, ?int $userId): int
    {
        $templates = $this->load()['templates'];
        $name = self::text($input['nume'] ?? null, 120) ?? throw new InvalidArgumentException('Numele planului este obligatoriu.');
        $x = self::posInt($input['sablon_x_id'] ?? null);
        if ($x === null || ($templates[$x]['tip'] ?? '') !== 'X') {
            throw new InvalidArgumentException('Alege șablonul Revizia X.');
        }
        $y = self::posInt($input['sablon_y_id'] ?? null);
        if ($y !== null && ($templates[$y]['tip'] ?? '') !== 'Y') {
            throw new InvalidArgumentException('Șablonul Y ales nu este de tip Y.');
        }
        $yThreshold = self::boundedInt($input['y_prag_km'] ?? null, 1000, 10000000, 'Pragul km pentru Y');
        $yInterval = self::boundedInt($input['y_interval_km'] ?? null, 1000, 5000000, 'Intervalul km pentru Y');
        if ($y !== null && $yThreshold === null && $yInterval === null) {
            throw new InvalidArgumentException('Revizia Y are nevoie de un prag km sau de un interval recurent km.');
        }
        $data = [
            'nume' => $name,
            'tip_vehicul' => isset(ReviziiV2Model::VEHICLE_TYPES[$input['tip_vehicul'] ?? '']) ? $input['tip_vehicul'] : null,
            'marca' => self::text($input['marca'] ?? null, 50), 'model' => self::text($input['model'] ?? null, 50),
            'sablon_x_id' => $x, 'sablon_y_id' => $y,
            'interval_km' => self::boundedInt($input['interval_km'] ?? null, 100, 2000000, 'Intervalul în km'),
            'interval_ore' => self::boundedInt($input['interval_ore'] ?? null, 1, 100000, 'Intervalul în ore'),
            'interval_luni' => self::boundedInt($input['interval_luni'] ?? null, 1, 240, 'Intervalul în luni'),
            'logica' => ($input['logica'] ?? 'or') === 'and' ? 'and' : 'or',
            'y_prag_km' => $yThreshold,
            'y_interval_km' => $yInterval,
            'y_toleranta_km' => self::boundedInt($input['y_toleranta_km'] ?? null, 0, 500000, 'Toleranța de coincidență X / Y'),
            'politica_x' => ($input['politica_x'] ?? '') === 'grila' ? 'grila' : 'reset',
            'sursa_km' => in_array($input['sursa_km'] ?? '', ['auto', 'can', 'gps'], true) ? $input['sursa_km'] : 'auto',
            'prag_km' => self::boundedInt($input['prag_km'] ?? null, 0, 500000, 'Pragul km'),
            'prag_ore' => self::boundedInt($input['prag_ore'] ?? null, 0, 10000, 'Pragul ore'),
            'prag_zile' => self::boundedInt($input['prag_zile'] ?? null, 0, 365, 'Pragul zile'),
            'cost_estimat_x' => self::decimal($input['cost_estimat_x'] ?? null),
            'cost_estimat_y' => self::decimal($input['cost_estimat_y'] ?? null),
            'responsabili' => self::text($input['responsabili'] ?? null, 255),
            'activ' => !isset($input['activ']) || !empty($input['activ']) ? 1 : 0,
        ];
        $rules = [];
        foreach ((array) ($input['reguli'] ?? []) as $rule) {
            $operationId = self::posInt($rule['operatie_id'] ?? null);
            if ($operationId === null || !isset($this->load()['operations'][$operationId])) {
                continue;
            }
            $label = $this->load()['operations'][$operationId]['operatie'];
            $rules[$operationId] = [
                'activ' => !empty($rule['activ']) ? 1 : 0,
                'interval_km' => self::boundedInt($rule['interval_km'] ?? null, 100, 5000000, $label . ': km'),
                'interval_ore' => self::boundedInt($rule['interval_ore'] ?? null, 1, 100000, $label . ': ore'),
                'interval_luni' => self::boundedInt($rule['interval_luni'] ?? null, 1, 240, $label . ': luni'),
                'logica' => ($rule['logica'] ?? 'or') === 'and' ? 'and' : 'or',
                'coef_ralanti_km' => self::decimal($rule['coef_ralanti_km'] ?? null),
                'cantitate' => self::decimal($rule['cantitate'] ?? null),
                'um' => self::text($rule['um'] ?? null, 10),
                'produs_id' => self::posInt($rule['produs_id'] ?? null),
                'cost_estimat' => self::decimal($rule['cost_estimat'] ?? null),
            ];
        }
        $id = $this->transaction(fn (): int => $this->model->savePlan(self::posInt($input['id'] ?? null), $data, $rules, $userId));
        $this->reset();

        return $id;
    }

    /** @param array<string, mixed> $input */
    public function saveVehicleConfig(array $input, ?int $userId): void
    {
        $vehicleId = (int) ($input['vehicle_id'] ?? 0);
        $vehicle = $this->load()['vehicles'][$vehicleId] ?? throw new InvalidArgumentException('Vehicul inexistent sau inactiv.');
        $planId = self::posInt($input['plan_id'] ?? null);
        if ($planId !== null && !isset($this->load()['plans'][$planId])) {
            throw new InvalidArgumentException('Planul ales nu există.');
        }
        $xCount = (int) ($input['x_de_la_ultima_y'] ?? 0);
        $yCount = (int) ($input['y_efectuate'] ?? 0);
        if ($xCount < 0 || $xCount > 100 || $yCount < 0 || $yCount > 1000) {
            throw new InvalidArgumentException('Numărul de revizii X / Y declarate este invalid.');
        }
        $refDate = self::dateTime($input['referinta_data'] ?? null);
        $yDate = self::dateTime($input['ultima_y_data'] ?? null);
        foreach ([$refDate, $yDate] as $value) {
            if ($value !== null && $value > date('Y-m-d H:i:s')) {
                throw new InvalidArgumentException('Datele de referință nu pot fi în viitor.');
            }
        }
        $hasHistory = isset($this->load()['complete'][$vehicleId]);
        $before = $this->load()['assignments'][$vehicleId] ?? null;
        $refKm = self::decimal($input['referinta_km'] ?? null);
        $yKm = self::decimal($input['ultima_y_km'] ?? null);
        $changed = $before !== null && ((int) $before['x_de_la_ultima_y'] !== $xCount || (int) $before['y_efectuate'] !== $yCount
            || (string) $before['referinta_km'] !== (string) ($refKm !== null ? number_format((float) $refKm, 1, '.', '') : '')
            || (string) $before['ultima_y_km'] !== (string) ($yKm !== null ? number_format((float) $yKm, 1, '.', '') : ''));
        if ($hasHistory && $changed && trim((string) ($input['motiv'] ?? '')) === '') {
            throw new InvalidArgumentException('Vehiculul are revizii finalizate: corecția referințelor de preluare cere un motiv (se auditează).');
        }
        $this->transaction(function () use ($vehicleId, $planId, $xCount, $yCount, $refDate, $yDate, $refKm, $yKm, $input, $userId): void {
            $this->model->saveAssignment($vehicleId, [
                'plan_id' => $planId, 'x_de_la_ultima_y' => $xCount, 'y_efectuate' => $yCount,
                'referinta_data' => $refDate, 'referinta_km' => $refKm, 'referinta_ore' => self::decimal($input['referinta_ore'] ?? null),
                'ultima_y_data' => $yDate, 'ultima_y_km' => $yKm, 'origine_grila_km' => self::decimal($input['origine_grila_km'] ?? null),
                'observatii' => self::text($input['motiv'] ?? ($input['observatii'] ?? null), 500),
            ], $userId);
            if (isset($input['exceptii']) && is_array($input['exceptii'])) {
                $rules = [];
                foreach ($input['exceptii'] as $rule) {
                    $operationId = self::posInt($rule['operatie_id'] ?? null);
                    if ($operationId === null) {
                        continue;
                    }
                    $activ = ($rule['activ'] ?? '') === '' ? null : (int) !empty($rule['activ']);
                    $km = self::boundedInt($rule['interval_km'] ?? null, 100, 5000000, 'Interval km');
                    $ore = self::boundedInt($rule['interval_ore'] ?? null, 1, 100000, 'Interval ore');
                    $luni = self::boundedInt($rule['interval_luni'] ?? null, 1, 240, 'Interval luni');
                    $q = self::decimal($rule['cantitate'] ?? null);
                    $rules[$operationId] = $activ === null && $km === null && $ore === null && $luni === null && $q === null ? null
                        : ['activ' => $activ, 'interval_km' => $km, 'interval_ore' => $ore, 'interval_luni' => $luni, 'cantitate' => $q, 'motiv' => self::text($rule['motiv'] ?? null, 255)];
                }
                $this->model->saveVehicleRules($vehicleId, $rules, $userId);
            }
        });
        $this->reset();
    }

    /** @param array<string, mixed> $input */
    public function saveSettingsConfig(array $input, ?int $userId): void
    {
        $stale = self::boundedInt($input['date_vechi_ore'] ?? null, 1, 720, 'Vechimea datelor');
        $days = self::boundedInt($input['prag_zile_implicit'] ?? null, 0, 365, 'Pragul în zile');
        $source = in_array($input['sursa_km_implicita'] ?? '', ['auto', 'can', 'gps'], true) ? (string) $input['sursa_km_implicita'] : 'auto';
        $before = $this->model->settings();
        $this->transaction(function () use ($stale, $days, $source, $userId): void {
            $this->model->saveSetting('date_vechi_ore', (string) ($stale ?? 48), $userId);
            $this->model->saveSetting('prag_zile_implicit', (string) ($days ?? 14), $userId);
            $this->model->saveSetting('sursa_km_implicita', $source, $userId);
        });
        $this->model->audit(0, 'setari', 'Setări Revizii V2', $before, $this->model->settings(), $userId);
        $this->reset();
    }

    // =====================================================================
    // Import (Excel lipit / CSV) — previzualizare obligatorie
    // =====================================================================

    /**
     * Previzualizarea importului. Coloane asteptate (antet): Componentă, Tip operațiune, Operațiune,
     * Km, Ore, Interval, Model, Tip revizie. „Km / Ore / Interval” sunt doar criterii CANDIDATE:
     * nimic nu devine prag activ din import.
     *
     * @return array{rows: array<int, array<string, mixed>>, errors: int, new: int, existing: int}
     */
    public function importPreview(string $text): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($text)) ?: [];
        if (count($lines) < 2) {
            throw new InvalidArgumentException('Lipește tabelul cu antet și cel puțin un rând.');
        }
        $delimiter = str_contains($lines[0], "\t") ? "\t" : (substr_count($lines[0], ';') >= substr_count($lines[0], ',') ? ';' : ',');
        $header = array_map(static fn (string $h): string => self::slug($h), str_getcsv($lines[0], $delimiter));
        $map = [];
        $aliases = [
            'componenta' => ['componenta', 'component'], 'tip' => ['tip_operatiune', 'tip_operatie', 'tip'], 'operatie' => ['operatiune', 'operatie'],
            'km' => ['km'], 'ore' => ['ore', 'ore_motor'], 'interval' => ['interval', 'luni', 'interval_calendaristic'], 'model' => ['model', 'marca_model'],
            'revizie' => ['tip_revizie', 'revizie'],
        ];
        foreach ($aliases as $key => $names) {
            foreach ($header as $i => $name) {
                if (in_array($name, $names, true)) {
                    $map[$key] = $i;
                    break;
                }
            }
        }
        if (!isset($map['componenta'], $map['operatie'])) {
            throw new InvalidArgumentException('Antetul trebuie să conțină cel puțin coloanele „Componentă” și „Operațiune”.');
        }
        $existing = [];
        foreach ($this->load()['operations'] as $operation) {
            $existing[self::slug($operation['componenta'] . '|' . $operation['operatie'])] = (int) $operation['id'];
        }
        $typeMap = ['inlocuire' => 'inlocuire', 'schimb' => 'inlocuire', 'gresare' => 'gresare', 'ungere' => 'gresare', 'inspectie' => 'inspectie',
            'verificare' => 'inspectie', 'completare' => 'completare', 'curatare' => 'curatare', 'reglaj' => 'reglaj'];
        $rows = [];
        $counts = ['errors' => 0, 'new' => 0, 'existing' => 0];
        $seen = [];
        foreach (array_slice($lines, 1) as $n => $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line, $delimiter);
            $get = static fn (string $key): string => isset($map[$key]) ? trim((string) ($cells[$map[$key]] ?? '')) : '';
            $component = $get('componenta');
            $operation = $get('operatie');
            $typeRaw = self::slug($get('tip'));
            $type = $typeMap[$typeRaw] ?? ($typeRaw === '' ? (str_contains(self::slug($operation), 'gresare') ? 'gresare' : 'inlocuire') : null);
            $revision = strtoupper(substr(trim($get('revizie')), -1)) === 'Y' ? 'Y' : 'X';
            $errors = [];
            if ($component === '' || $operation === '') {
                $errors[] = 'Componenta și operațiunea sunt obligatorii.';
            }
            if ($type === null) {
                $errors[] = 'Tip operațiune necunoscut: „' . $get('tip') . '”.';
            }
            $candidates = [];
            $values = [];
            foreach (['km' => 'km', 'ore' => 'ore', 'interval' => 'calendar'] as $col => $label) {
                $raw = $get($col);
                if ($raw === '' || in_array(mb_strtolower($raw), ['-', 'nu', 'n/a'], true)) {
                    continue;
                }
                $candidates[] = $label;
                $number = preg_replace('/[^\d]/', '', $raw);
                if ($number !== '' && $number !== null && (int) $number > 0 && !in_array(mb_strtolower($raw), ['da', 'x'], true)) {
                    $values[] = $label . '=' . (int) $number;
                }
            }
            $key = self::slug($component . '|' . $operation);
            if (isset($seen[$key])) {
                $errors[] = 'Rând duplicat în import (rândul ' . ($seen[$key] + 2) . ').';
            }
            $seen[$key] = $n;
            $status = $errors !== [] ? 'eroare' : (isset($existing[$key]) ? 'existent' : 'nou');
            $counts[$status === 'eroare' ? 'errors' : ($status === 'nou' ? 'new' : 'existing')]++;
            $rows[] = ['rand' => $n + 2, 'componenta' => $component, 'operatie' => $operation, 'tip' => $type, 'revizie' => $revision, 'model' => $get('model'),
                'criterii' => implode(',', $candidates), 'valori' => implode(';', $values), 'status' => $status, 'erori' => $errors, 'operatie_id' => $existing[$key] ?? null];
        }

        return ['rows' => $rows] + $counts;
    }

    /**
     * Aplica importul (dupa previzualizare): operatiunile noi intra in catalog si in sablonul
     * X / Y ales; cele existente primesc doar criteriile candidate. Niciun interval activ.
     *
     * @return array{create: int, update: int}
     */
    public function importApply(string $text, int $templateX, ?int $templateY, ?int $userId): array
    {
        $preview = $this->importPreview($text);
        if ($preview['errors'] > 0) {
            throw new InvalidArgumentException('Importul are rânduri cu erori: corectează-le și reia previzualizarea.');
        }
        $templates = $this->load()['templates'];
        if (($templates[$templateX]['tip'] ?? '') !== 'X' || ($templateY !== null && ($templates[$templateY]['tip'] ?? '') !== 'Y')) {
            throw new InvalidArgumentException('Alege șabloanele X / Y în care intră operațiunile.');
        }
        $result = $this->transaction(function () use ($preview, $templates, $templateX, $templateY, $userId): array {
            $created = 0;
            $updated = 0;
            $add = ['X' => [], 'Y' => []];
            foreach ($preview['rows'] as $row) {
                if ($row['status'] === 'existent') {
                    $this->db->prepare('UPDATE revizii_v2_operatii SET criterii_candidate = :c, valori_candidate = COALESCE(NULLIF(:v, \'\'), valori_candidate) WHERE id = :id')
                        ->execute([':c' => $row['criterii'] ?: null, ':v' => $row['valori'], ':id' => $row['operatie_id']]);
                    $updated++;
                    $add[$row['revizie']][] = (int) $row['operatie_id'];
                    continue;
                }
                $id = $this->model->saveOperation([
                    'cod' => substr(self::slug($row['operatie']), 0, 48) . '_' . substr(bin2hex(random_bytes(3)), 0, 6),
                    'componenta' => $row['componenta'], 'tip_operatie' => $row['tip'], 'operatie' => $row['operatie'],
                    'categorie' => $row['tip'] === 'gresare' ? 'gresare' : (str_contains(self::slug($row['componenta']), 'filtru') ? 'filtre' : (str_contains(self::slug($row['componenta']), 'ulei') ? 'fluide' : 'altele')),
                    'aplicabil' => 'cap_tractor,camion', 'criterii_candidate' => $row['criterii'] ?: null, 'valori_candidate' => $row['valori'] ?: null,
                    'descriere' => $row['model'] !== '' ? 'Import: model ' . $row['model'] : null, 'de_verificat' => 1, 'activ' => 1, 'ordine' => 900,
                ], null, $userId);
                $created++;
                $add[$row['revizie']][] = $id;
            }
            foreach (['X' => $templateX, 'Y' => $templateY] as $type => $templateId) {
                if ($templateId === null || $add[$type] === []) {
                    continue;
                }
                $template = $templates[$templateId];
                $this->model->saveTemplate($templateId, (string) $template['nume'], $type, $template['parinte_id'], array_values(array_unique(array_merge($template['operatii'], $add[$type]))), (int) $template['activ'] === 1, $userId);
            }

            return ['create' => $created, 'update' => $updated];
        });
        $this->reset();

        return $result;
    }

    // =====================================================================
    // Documente
    // =====================================================================

    public function storeDocument(int $orderId, ?array $file, ?int $userId): int
    {
        require_once __DIR__ . '/InvoiceStorageService.php';
        if ($this->model->workOrder($orderId) === null) {
            throw new InvalidArgumentException('Lucrarea nu există.');
        }
        [$stored, $error] = (new InvoiceStorageService(null, 'storage/revizii/documente'))->storeUpload($file);
        if ($stored === null) {
            throw new InvalidArgumentException((string) $error);
        }
        $this->db->prepare('INSERT INTO revizii_v2_documente (lucrare_id, document_path, nume_original, mime, marime, uploaded_by) VALUES (:l, :p, :n, :m, :s, :u)')
            ->execute([':l' => $orderId, ':p' => $stored['document_path'], ':n' => $stored['document_original_name'], ':m' => $stored['document_mime'], ':s' => $stored['document_size'], ':u' => $userId]);
        $id = (int) $this->db->lastInsertId();
        $this->model->audit($orderId, 'document', 'Document atașat lucrării: ' . $stored['document_original_name'], null, null, $userId);

        return $id;
    }

    /** @return array{path: string, name: string, mime: string}|null */
    public function documentFile(int $documentId): ?array
    {
        require_once __DIR__ . '/InvoiceStorageService.php';
        $stmt = $this->db->prepare('SELECT * FROM revizii_v2_documente WHERE id = :id AND deleted_at IS NULL');
        $stmt->execute([':id' => $documentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $path = (new InvoiceStorageService(null, 'storage/revizii/documente'))->resolveReadablePath((string) $row['document_path']);

        return $path !== null ? ['path' => $path, 'name' => (string) $row['nume_original'], 'mime' => (string) ($row['mime'] ?: 'application/octet-stream')] : null;
    }

    // =====================================================================
    // Utilitare
    // =====================================================================

    private function stockService(): ConsumabileStockService
    {
        require_once __DIR__ . '/VehicleFluidService.php';
        require_once __DIR__ . '/ConsumabileStockService.php';

        return new ConsumabileStockService($this->db);
    }

    private function systemIdFor(int $vehicleId, string $system): ?int
    {
        if ($system === '' || !$this->tableExists('vehicule_sisteme_fluide')) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT id FROM vehicule_sisteme_fluide WHERE vehicle_id = :v AND sistem = :s AND activ = 1 LIMIT 1');
        $stmt->execute([':v' => $vehicleId, ':s' => $system]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function transaction(callable $work): mixed
    {
        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        } else {
            $this->db->exec('SAVEPOINT revizii_v2');
        }
        try {
            $result = $work();
            if ($own) {
                $this->db->commit();
            } else {
                $this->db->exec('RELEASE SAVEPOINT revizii_v2');
            }

            return $result;
        } catch (Throwable $exception) {
            if ($own) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
            } else {
                $this->db->exec('ROLLBACK TO SAVEPOINT revizii_v2');
            }
            $this->reset();
            throw $exception;
        }
    }

    /** @var array<string, bool> */
    private static array $tables = [];

    private function tableExists(string $table): bool
    {
        if (!isset(self::$tables[$table])) {
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t');
            $stmt->execute([':t' => $table]);
            self::$tables[$table] = (int) $stmt->fetchColumn() > 0;
        }

        return self::$tables[$table];
    }

    public static function slug(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't']);

        return trim((string) preg_replace('/[^a-z0-9|]+/', '_', $value), '_');
    }

    private static function text(mixed $value, int $max): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private static function posInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return (int) $value > 0 ? (int) $value : null;
    }

    private static function boundedInt(mixed $value, int $min, int $max, string $label): ?int
    {
        $raw = preg_replace('/[\s.]/', '', trim((string) ($value ?? ''))) ?? '';
        if ($raw === '') {
            return null;
        }
        if (!ctype_digit($raw) || (int) $raw < $min || (int) $raw > $max) {
            throw new InvalidArgumentException(sprintf('%s trebuie să fie între %s și %s.', $label, number_format($min, 0, ',', '.'), number_format($max, 0, ',', '.')));
        }

        return (int) $raw;
    }

    private static function decimal(mixed $value): ?string
    {
        $raw = str_replace([' ', ','], ['', '.'], trim((string) ($value ?? '')));
        if ($raw === '') {
            return null;
        }
        if (substr_count($raw, '.') > 1) {
            $raw = str_replace('.', '', substr($raw, 0, (int) strrpos($raw, '.'))) . substr($raw, (int) strrpos($raw, '.'));
        }
        if (!is_numeric($raw) || (float) $raw < 0) {
            throw new InvalidArgumentException('Valoare numerică invalidă: ' . (string) $value);
        }

        return (string) round((float) $raw, 3);
    }

    private static function date(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }
        $ts = strtotime(str_replace('T', ' ', $value));
        if ($ts === false) {
            throw new InvalidArgumentException('Dată invalidă.');
        }

        return date('Y-m-d', $ts);
    }

    private static function dateTime(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }
        $ts = strtotime(str_replace('T', ' ', $value));
        if ($ts === false) {
            throw new InvalidArgumentException('Dată / oră invalidă.');
        }

        return date('Y-m-d H:i:s', $ts);
    }
}
