<?php
declare(strict_types=1);

/**
 * Consumabile & Fluide Tehnice — etapa 3: AdBlue din CardOil + motorina CAN din SAS.
 *
 * Surse:
 *   - Alimentari AdBlue: fuel_fillups (fuel_type = 'adblue'), asociate vehiculului prin numarul
 *     efectiv (inclusiv corectiile manuale din Carburanti, protejate la resincronizare).
 *   - Motorina consumata: totalul CAN „CANFuelUsed” din foaia de parcurs SAS pe intervalul dintre
 *     doua zile cu alimentare AdBlue. Se cere O SINGURA DATA per interval inchis si se pastreaza in
 *     consumabile_can_intervale (+ cache-ul pe disc al SasDashboardService). Alimentarile cu motorina
 *     NU sunt folosite drept motorina consumata.
 *   - Km: odometrul CAN la momentul alimentarilor; altfel suma km GPS din foile de parcurs; altfel
 *     odometrul tastat la pompa (marcat ca atare).
 *   - Nivel AdBlue CAN: SAS nu il transmite — „indisponibil”, nu se inventeaza.
 *
 * Model: AdBlue consumat intre prima si ultima zi cu alimentare ≈ suma alimentarilor de dupa prima zi
 * (fiecare alimentare inlocuieste ce s-a consumat). Eroarea e marginita de capacitatea rezervorului
 * (nivelul la capete poate diferi), de aici gradul de incredere. Raportul AdBlue/motorina e al fiecarui
 * vehicul (istoricul lui), nu un procent generic.
 */
final class AdBlueService
{
    public const DEFAULT_DAYS = 180;

    /** Abaterea (fata de istoricul vehiculului) peste care ultimele intervale sunt semnalate. */
    public const DEVIATION = 0.40;

    /** Intervalul deschis (ultima alimentare → azi) se reciteste cel mult o data la atat. */
    private const OPEN_TTL_SECONDS = 21600;

    private const MAX_RANGE_DAYS = 92;

    private static bool $schemaReady = false;

    public function __construct(private PDO $db)
    {
    }

    public function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }
        (new ConsumabileStockService($this->db))->ensureSchema();
        $exists = static function (PDO $db, string $sql): bool {
            return (int) $db->query($sql)->fetchColumn() > 0;
        };
        $needTable = !$exists($this->db, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consumabile_can_intervale'");
        $needColumn = !$exists($this->db, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vehicule_sisteme_fluide' AND COLUMN_NAME = 'alimentare_la_plin'");
        if (($needTable || $needColumn) && $this->db->inTransaction()) {
            throw new RuntimeException('Schema AdBlue lipsește; nu poate fi creată în interiorul unei tranzacții.');
        }
        if ($needTable) {
            $this->db->exec(self::schemaStatements()[0]);
        }
        if ($needColumn) {
            $this->db->exec(self::schemaStatements()[1]);
        }
        self::$schemaReady = true;
    }

    /** @return string[] aceleasi instructiuni ca migratia 2026_11_10_000003_consumabile_adblue.sql */
    public static function schemaStatements(): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS consumabile_can_intervale (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                vehicle_id INT NOT NULL,
                de_la DATE NOT NULL,
                pana_la DATE NOT NULL,
                can_fuel_l DECIMAL(10,1) NULL,
                km_gps DECIMAL(10,1) NULL,
                has_can TINYINT(1) NOT NULL DEFAULT 0,
                status VARCHAR(12) NOT NULL,
                eroare VARCHAR(255) NULL,
                inchis TINYINT(1) NOT NULL DEFAULT 1,
                fetched_at DATETIME NOT NULL,
                UNIQUE KEY uq_cci_interval (vehicle_id, de_la, pana_la),
                KEY idx_cci_vehicle (vehicle_id, de_la)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "ALTER TABLE vehicule_sisteme_fluide ADD COLUMN alimentare_la_plin TINYINT(1) NOT NULL DEFAULT 0 AFTER circuit_inchis",
        ];
    }

    // =========================================================================
    // Date
    // =========================================================================

    /**
     * Alimentarile AdBlue din perioada, pe vehicul (dupa numarul efectiv) + cele neasociate.
     *
     * @return array{by_vehicle: array<int, list<array<string, mixed>>>, unmatched: list<array<string, mixed>>, vehicles: array<int, array<string, mixed>>}
     */
    public function fillups(string $from, string $to): array
    {
        $vehicles = [];
        $byPlate = [];
        foreach ($this->db->query(
            "SELECT v.id, v.nr_inmatriculare, v.marca, v.model, v.tip_vehicul, v.status,
                    (SELECT s.external_provider_vehicle_id FROM vehicle_odometer_sources s WHERE s.vehicle_id = v.id AND s.provider_code = 'sas' AND s.enabled = 1 LIMIT 1) AS sas_car_id
             FROM vehicule v WHERE v.nr_inmatriculare <> 'STOC-ANVELOPE'
             ORDER BY v.status = 'activ'"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [] as $vehicle) {
            $vehicles[(int) $vehicle['id']] = $vehicle;
            $byPlate[self::plateKey((string) $vehicle['nr_inmatriculare'])] = (int) $vehicle['id'];
        }
        $stmt = $this->db->prepare(
            "SELECT id, vehicle_registration, vehicle_registration_manual, vehicle_registration_api, quantity_liters, odometer_km, fillup_datetime,
                    is_full, station_name, driver_name
             FROM fuel_fillups
             WHERE fuel_type = 'adblue' AND quantity_liters > 0 AND fillup_datetime BETWEEN :f AND :t
             ORDER BY fillup_datetime, id"
        );
        $stmt->execute([':f' => $from . ' 00:00:00', ':t' => $to . ' 23:59:59']);
        $result = ['by_vehicle' => [], 'unmatched' => [], 'vehicles' => $vehicles];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $vehicleId = $byPlate[self::plateKey((string) $row['vehicle_registration'])] ?? null;
            if ($vehicleId === null) {
                $result['unmatched'][] = $row;
                continue;
            }
            $result['by_vehicle'][$vehicleId][] = $row;
        }

        return $result;
    }

    /** @return array<string, array<string, mixed>> cache-ul CAN al unui vehicul, cheie „de_la|pana_la” */
    public function cachedIntervals(int $vehicleId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM consumabile_can_intervale WHERE vehicle_id = :v');
        $stmt->execute([':v' => $vehicleId]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $result[$row['de_la'] . '|' . $row['pana_la']] = $row;
        }

        return $result;
    }

    // =========================================================================
    // Analiza
    // =========================================================================

    /**
     * Tabelul AdBlue: un rand per vehicul cu alimentari in perioada.
     *
     * @return array{rows: list<array<string, mixed>>, unmatched: list<array<string, mixed>>, from: string, to: string, missing_can: int}
     */
    public function analysis(array $filters = [], ?DateTimeImmutable $now = null): array
    {
        $this->ensureSchema();
        $now ??= new DateTimeImmutable('now');
        $to = !empty($filters['to']) ? (string) $filters['to'] : $now->format('Y-m-d');
        $from = !empty($filters['from']) ? (string) $filters['from'] : $now->modify('-' . self::DEFAULT_DAYS . ' days')->format('Y-m-d');
        $data = $this->fillups($from, $to);
        $only = array_values(array_filter(array_map('intval', (array) ($filters['vehicle_ids'] ?? []))));
        $systems = $this->adblueSystems();
        $fluids = new VehicleFluidService($this->db);
        $latest = $fluids->latestReadings(array_keys($data['by_vehicle']));
        $rows = [];
        $missing = 0;
        foreach ($data['by_vehicle'] as $vehicleId => $fills) {
            if ($only !== [] && !in_array($vehicleId, $only, true)) {
                continue;
            }
            $vehicle = $data['vehicles'][$vehicleId];
            $system = $systems[$vehicleId] ?? null;
            $odometer = [];
            foreach ($fills as $fill) {
                $odometer[(int) $fill['id']] = [
                    'can' => $fluids->readingAt($vehicleId, 'can', (string) $fill['fillup_datetime']),
                    'gps' => $fluids->readingAt($vehicleId, 'sas', (string) $fill['fillup_datetime']),
                ];
            }
            $row = self::analyzeVehicle($fills, $this->cachedIntervals($vehicleId), [
                'capacity' => $system !== null && $system['capacitate_nominala'] !== null ? (float) $system['capacitate_nominala'] : null,
                'fills_to_full' => $system !== null && (int) ($system['alimentare_la_plin'] ?? 0) === 1,
                'odometer' => $odometer,
                'current_can' => $latest[$vehicleId]['can'] ?? null,
                'today' => min($now->format('Y-m-d'), $to),
            ]);
            $missing += $row['can_missing'];
            $rows[] = $row + [
                'vehicle_id' => $vehicleId,
                'nr_inmatriculare' => (string) $vehicle['nr_inmatriculare'],
                'vehicul' => trim((string) ($vehicle['marca'] ?? '') . ' ' . (string) ($vehicle['model'] ?? '')),
                'sas_car_id' => $vehicle['sas_car_id'] !== null ? (int) $vehicle['sas_car_id'] : null,
                'system_id' => $system !== null ? (int) $system['id'] : null,
                'inactive' => (string) ($vehicle['status'] ?? 'activ') !== 'activ',
            ];
        }
        usort($rows, static fn (array $a, array $b): int => [count($b['anomalies']) > 0, $a['nr_inmatriculare']] <=> [count($a['anomalies']) > 0, $b['nr_inmatriculare']]);

        return ['rows' => $rows, 'unmatched' => $data['unmatched'], 'from' => $from, 'to' => $to, 'missing_can' => $missing,
            'vehicles' => $data['vehicles']];
    }

    /**
     * Analiza unui vehicul — functie pura (testabila).
     *
     * @param list<array<string, mixed>> $fills alimentarile AdBlue crescator
     * @param array<string, array<string, mixed>> $cache intervale CAN salvate („de_la|pana_la”)
     * @param array{capacity: ?float, fills_to_full: bool, odometer: array<int, array{can: ?string, gps: ?string}>, current_can: ?array, today: string} $ctx
     */
    public static function analyzeVehicle(array $fills, array $cache, array $ctx): array
    {
        $days = self::fillDays($fills);
        $intervals = self::intervals($days, (string) $ctx['today']);
        $capacity = $ctx['capacity'] ?? null;
        $result = [
            'fills' => count($fills),
            'liters' => round(array_sum(array_map(static fn ($f) => (float) $f['quantity_liters'], $fills)), 2),
            'last_fill' => $fills !== [] ? $fills[count($fills) - 1] : null,
            'fills_list' => $fills,
            'consumed' => null, 'km' => null, 'km_source' => null, 'diesel' => null,
            'l100' => null, 'ratio' => null, 'confidence' => null,
            'remaining' => null, 'remaining_note' => '', 'autonomy_km' => null,
            'can_missing' => 0, 'anomalies' => [], 'history' => [], 'level_can' => 'indisponibil',
        ];
        foreach ($fills as $fill) {
            if ($capacity !== null && (float) $fill['quantity_liters'] > $capacity * 1.05) {
                $result['anomalies'][] = 'Alimentare de ' . self::fmt((float) $fill['quantity_liters']) . ' L pe ' . date('d.m.Y', (int) strtotime((string) $fill['fillup_datetime']))
                    . ' peste capacitatea rezervorului (' . self::fmt($capacity) . ' L): verifică vehiculul asociat.';
            }
        }
        // Istoric pe intervale (pentru tabel si anomalii).
        foreach ($intervals as $interval) {
            $cached = $cache[$interval['from'] . '|' . $interval['to']] ?? null;
            if ($cached === null && !$interval['open']) {
                $result['can_missing']++;
            }
            $result['history'][] = $interval + [
                'diesel' => $cached !== null && (int) $cached['has_can'] === 1 && $cached['can_fuel_l'] !== null ? (float) $cached['can_fuel_l'] : null,
                'km_gps' => $cached !== null && $cached['km_gps'] !== null ? (float) $cached['km_gps'] : null,
                'can_status' => $cached['status'] ?? null,
            ];
        }
        if (count($days) < 2) {
            $result['confidence'] = null;
            $result['remaining_note'] = 'Sunt necesare cel puțin două zile cu alimentare AdBlue.';

            return $result;
        }

        // AdBlue consumat in fereastra [prima zi, ultima zi): alimentarile de dupa prima zi.
        $consumed = 0.0;
        foreach (array_slice($days, 1) as $day) {
            $consumed += $day['liters'];
        }
        $result['consumed'] = round($consumed, 2);

        // Km in fereastra: CAN la alimentari > suma km GPS din foile de parcurs > odometrul de la pompa.
        $firstFill = $days[0]['last'];
        $lastFill = $days[count($days) - 1]['last'];
        $odo = $ctx['odometer'] ?? [];
        $canStart = $odo[(int) $firstFill['id']]['can'] ?? null;
        $canEnd = $odo[(int) $lastFill['id']]['can'] ?? null;
        $history = array_values(array_filter($result['history'], static fn ($h) => !$h['open']));
        $gpsKm = array_map(static fn ($h) => $h['km_gps'], $history);
        if ($canStart !== null && $canEnd !== null && (float) $canEnd > (float) $canStart) {
            $result['km'] = round((float) $canEnd - (float) $canStart);
            $result['km_source'] = 'KM CAN';
        } elseif ($history !== [] && !in_array(null, $gpsKm, true)) {
            $result['km'] = round(array_sum($gpsKm));
            $result['km_source'] = 'KM GPS (SAS)';
        } elseif ((float) ($firstFill['odometer_km'] ?? 0) > 0 && (float) ($lastFill['odometer_km'] ?? 0) > (float) $firstFill['odometer_km']) {
            $result['km'] = round((float) $lastFill['odometer_km'] - (float) $firstFill['odometer_km']);
            $result['km_source'] = 'odometru pompă (tastat)';
        }
        if ($result['km'] !== null && $result['km'] >= 300) {
            $result['l100'] = round($consumed / $result['km'] * 100, 2);
        }

        // Motorina CAN in aceeasi fereastra: doar daca toate intervalele au date CAN.
        $diesel = array_map(static fn ($h) => $h['diesel'], $history);
        if ($history !== [] && !in_array(null, $diesel, true) && array_sum($diesel) > 0) {
            $result['diesel'] = round(array_sum($diesel), 1);
            $result['ratio'] = round($consumed / $result['diesel'] * 100, 2);
        }

        // Increderea: eroarea posibila (nivel diferit la capete) raportata la cantitatea masurata.
        $firstFull = (int) ($firstFill['is_full'] ?? 0) === 1 || !empty($ctx['fills_to_full']);
        $lastFull = (int) ($lastFill['is_full'] ?? 0) === 1 || !empty($ctx['fills_to_full']);
        if ($firstFull && $lastFull) {
            $result['confidence'] = 'ridicat';
        } elseif ($capacity !== null && $consumed > 0) {
            $uncertainty = $capacity / $consumed;
            $result['confidence'] = $uncertainty <= 0.15 ? 'ridicat' : ($uncertainty <= 0.35 ? 'mediu' : 'scazut');
        } else {
            $result['confidence'] = count($days) >= 8 ? 'mediu' : 'scazut';
        }

        // Anomalii: ultimele intervale fata de istoricul vehiculului (nu fata de un procent generic).
        $ratios = [];
        foreach ($history as $index => $h) {
            $next = $days[$index + 1]['liters'] ?? null;
            if ($h['diesel'] !== null && $h['diesel'] > 0 && $next !== null) {
                $ratios[] = ['adblue' => $next, 'diesel' => $h['diesel']];
            }
        }
        if (count($ratios) >= 6 && $result['ratio'] !== null) {
            $recent = array_slice($ratios, -3);
            $recentRatio = array_sum(array_column($recent, 'adblue')) / max(0.1, array_sum(array_column($recent, 'diesel'))) * 100;
            $deviation = ($recentRatio - $result['ratio']) / $result['ratio'];
            if (abs($deviation) > self::DEVIATION) {
                $result['anomalies'][] = 'Ultimele 3 intervale: AdBlue/motorină ' . self::fmt($recentRatio) . '% față de ' . self::fmt($result['ratio'])
                    . '% în istoricul vehiculului (' . ($deviation > 0 ? '+' : '') . self::fmt($deviation * 100, 0) . '%). Verifică alimentările / asocierea cardului'
                    . ($deviation < 0 ? ' sau funcționarea sistemului SCR.' : '.');
            }
        }

        // Rest si autonomie estimate: doar cu capacitate cunoscuta si ultima alimentare la plin.
        $open = array_values(array_filter($result['history'], static fn ($h) => $h['open']))[0] ?? null;
        if ($capacity === null) {
            $result['remaining_note'] = 'Capacitatea rezervorului AdBlue nu e configurată (sistemul „AdBlue” al vehiculului).';
        } elseif (!$lastFull) {
            $result['remaining_note'] = 'Ultima alimentare nu e marcată „la plin”: nivelul de pornire e necunoscut.';
        } else {
            $usedSince = null;
            if ($result['ratio'] !== null && $open !== null && $open['diesel'] !== null) {
                $usedSince = $open['diesel'] * $result['ratio'] / 100;
            } elseif ($result['l100'] !== null) {
                $currentCan = $ctx['current_can']['km'] ?? null;
                $kmSince = $currentCan !== null && $canEnd !== null ? max(0.0, (float) $currentCan - (float) $canEnd) : ($open['km_gps'] ?? null);
                $usedSince = $kmSince !== null ? $kmSince * $result['l100'] / 100 : null;
            }
            if ($usedSince === null) {
                $result['remaining_note'] = 'Lipsesc datele de consum de la ultima alimentare (actualizează motorina CAN).';
            } else {
                $result['remaining'] = round(max(0.0, $capacity - $usedSince), 1);
                if ($result['l100'] !== null && $result['l100'] > 0) {
                    $result['autonomy_km'] = (int) round($result['remaining'] / $result['l100'] * 100);
                }
                if ($capacity - $usedSince < 0) {
                    $result['anomalies'][] = 'Consumul estimat de la ultima alimentare depășește capacitatea rezervorului: lipsesc alimentări (alt card / alt vehicul?).';
                }
            }
        }

        return $result;
    }

    /**
     * Alimentarile grupate pe zi (doua alimentari in aceeasi zi = o singura zi de referinta).
     *
     * @return list<array{date: string, liters: float, last: array<string, mixed>}>
     */
    public static function fillDays(array $fills): array
    {
        $days = [];
        foreach ($fills as $fill) {
            $date = substr((string) $fill['fillup_datetime'], 0, 10);
            if (!isset($days[$date])) {
                $days[$date] = ['date' => $date, 'liters' => 0.0, 'last' => $fill];
            }
            $days[$date]['liters'] += (float) $fill['quantity_liters'];
            $days[$date]['last'] = $fill;
        }

        return array_values($days);
    }

    /**
     * Intervalele de zile dintre alimentari: [ziua i, ziua i+1 - 1] (inchise) + [ultima zi, azi] (deschis).
     *
     * @return list<array{from: string, to: string, open: bool}>
     */
    public static function intervals(array $days, string $today): array
    {
        $result = [];
        for ($i = 0, $n = count($days); $i < $n; $i++) {
            if ($i + 1 < $n) {
                $result[] = ['from' => $days[$i]['date'], 'to' => date('Y-m-d', strtotime($days[$i + 1]['date'] . ' -1 day')), 'open' => false];
            } elseif ($days[$i]['date'] <= $today
                && (int) (new DateTimeImmutable($days[$i]['date']))->diff(new DateTimeImmutable($today))->days < self::MAX_RANGE_DAYS) {
                // Intervalul deschis doar daca ultima alimentare e recenta (altfel restul estimat nu are sens
                // si s-ar cere din SAS o perioada foarte lunga).
                $result[] = ['from' => $days[$i]['date'], 'to' => $today, 'open' => true];
            }
        }

        return $result;
    }

    // =========================================================================
    // Sincronizarea motorinei CAN (cereri SAS doar pentru intervalele lipsa)
    // =========================================================================

    /**
     * @return array{calls: int, stored: int, errors: int, pending: int, locked: bool, message: string}
     */
    public function syncCanDiesel(array $filters = [], int $maxCalls = 30, ?callable $fetch = null): array
    {
        $this->ensureSchema();
        $stats = ['calls' => 0, 'stored' => 0, 'errors' => 0, 'pending' => 0, 'locked' => false, 'message' => ''];
        $cacheDir = dirname(BASE_PATH) . '/storage/cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }
        $lock = @fopen($cacheDir . '/consumabile_adblue_can.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            $stats['locked'] = true;
            $stats['message'] = 'O altă actualizare rulează deja.';

            return $stats;
        }
        try {
            if ($fetch === null) {
                $sas = new SasDashboardService($this->db);
                if (!$sas->credentialsAvailable()) {
                    $stats['message'] = 'Credențialele SAS Fleet nu sunt configurate.';

                    return $stats;
                }
                $fetch = static fn (int $carId, string $from, string $to): array => $sas->getVehicleRange($carId, $from, $to);
            }
            $today = date('Y-m-d');
            $from = !empty($filters['from']) ? (string) $filters['from'] : date('Y-m-d', strtotime('-' . self::DEFAULT_DAYS . ' days'));
            $to = !empty($filters['to']) ? (string) $filters['to'] : $today;
            $data = $this->fillups($from, $to);
            $only = array_values(array_filter(array_map('intval', (array) ($filters['vehicle_ids'] ?? []))));
            $upsert = $this->db->prepare(
                'INSERT INTO consumabile_can_intervale (vehicle_id, de_la, pana_la, can_fuel_l, km_gps, has_can, status, eroare, inchis, fetched_at)
                 VALUES (:v, :f, :t, :fuel, :km, :can, :st, :err, :closed, NOW())
                 ON DUPLICATE KEY UPDATE can_fuel_l = VALUES(can_fuel_l), km_gps = VALUES(km_gps), has_can = VALUES(has_can), status = VALUES(status),
                    eroare = VALUES(eroare), inchis = VALUES(inchis), fetched_at = VALUES(fetched_at)'
            );
            foreach ($data['by_vehicle'] as $vehicleId => $fills) {
                if ($only !== [] && !in_array($vehicleId, $only, true)) {
                    continue;
                }
                $carId = (int) ($data['vehicles'][$vehicleId]['sas_car_id'] ?? 0);
                $intervals = self::intervals(self::fillDays($fills), min($today, $to));
                $cache = $this->cachedIntervals($vehicleId);
                foreach ($intervals as $interval) {
                    $cached = $cache[$interval['from'] . '|' . $interval['to']] ?? null;
                    $fresh = $cached !== null && ((int) $cached['inchis'] === 1 && $cached['status'] !== 'eroare'
                        || (time() - (int) strtotime((string) $cached['fetched_at'])) < self::OPEN_TTL_SECONDS);
                    if ($fresh) {
                        continue;
                    }
                    if ($carId <= 0) {
                        $upsert->execute([':v' => $vehicleId, ':f' => $interval['from'], ':t' => $interval['to'], ':fuel' => null, ':km' => null,
                            ':can' => 0, ':st' => 'fara_sas', ':err' => 'Vehiculul nu e legat la SAS (Centru kilometraj).', ':closed' => $interval['open'] ? 0 : 1]);
                        continue;
                    }
                    if ($stats['calls'] >= $maxCalls) {
                        $stats['pending']++;
                        continue;
                    }
                    try {
                        $fuel = 0.0;
                        $km = 0.0;
                        $hasCan = true;
                        $chunkStart = $interval['from'];
                        while ($chunkStart <= $interval['to']) {
                            $chunkEnd = min($interval['to'], date('Y-m-d', strtotime($chunkStart . ' +' . (self::MAX_RANGE_DAYS - 1) . ' days')));
                            $stats['calls']++;
                            $range = $fetch($carId, $chunkStart, $chunkEnd);
                            if (empty($range['has_can']) || ($range['can_fuel_l'] ?? null) === null) {
                                $hasCan = false;
                            }
                            $fuel += (float) ($range['can_fuel_l'] ?? 0);
                            $km += (float) ($range['total_km'] ?? 0);
                            $chunkStart = date('Y-m-d', strtotime($chunkEnd . ' +1 day'));
                        }
                        $upsert->execute([':v' => $vehicleId, ':f' => $interval['from'], ':t' => $interval['to'], ':fuel' => $hasCan ? round($fuel, 1) : null,
                            ':km' => round($km, 1), ':can' => $hasCan ? 1 : 0, ':st' => $hasCan ? 'ok' : 'fara_can', ':err' => null, ':closed' => $interval['open'] ? 0 : 1]);
                        $stats['stored']++;
                    } catch (Throwable $exception) {
                        $stats['errors']++;
                        $upsert->execute([':v' => $vehicleId, ':f' => $interval['from'], ':t' => $interval['to'], ':fuel' => null, ':km' => null,
                            ':can' => 0, ':st' => 'eroare', ':err' => mb_substr($exception->getMessage(), 0, 255), ':closed' => $interval['open'] ? 0 : 1]);
                    }
                }
            }
            $stats['message'] = $stats['stored'] . ' intervale actualizate din SAS (' . $stats['calls'] . ' cereri)'
                . ($stats['pending'] > 0 ? ', ' . $stats['pending'] . ' rămase pentru rularea următoare' : '')
                . ($stats['errors'] > 0 ? ', ' . $stats['errors'] . ' erori' : '') . '.';
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $stats;
    }

    /** @return array<int, array<string, mixed>> sistemul „adblue” al fiecarui vehicul (capacitate, la plin) */
    private function adblueSystems(): array
    {
        $result = [];
        foreach ($this->db->query("SELECT * FROM vehicule_sisteme_fluide WHERE sistem = 'adblue' AND activ = 1")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $result[(int) $row['vehicle_id']] = $row;
        }

        return $result;
    }

    public static function plateKey(string $plate): string
    {
        return strtoupper(str_replace([' ', '-'], '', trim($plate)));
    }

    public static function fmt(?float $value, int $decimals = 2): string
    {
        return VehicleFluidService::fmt($value, $decimals);
    }
}
