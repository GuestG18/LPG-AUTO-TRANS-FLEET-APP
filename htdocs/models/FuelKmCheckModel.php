<?php
declare(strict_types=1);

/**
 * Verificarea alimentarilor cu datele GPS / CAN din SAS (tabul „Verificare
 * km (GPS/CAN)" din Carburanti).
 *
 * Pentru fiecare alimentare de motorina se compara:
 *  - km declarati de sofer (odometrul de la pompa minus odometrul alimentarii
 *    precedente a aceluiasi vehicul) cu km GPS parcursi intre cele doua ore;
 *  - litrii de pe bon cu cresterea de nivel din rezervor vazuta de sonda / CAN;
 *  - motorina consumata dupa CAN in acelasi interval (informativ);
 *  - odometrul declarat cu odometrul GPS la ora alimentarii, CALIBRAT in
 *    aplicatie: o citire de incredere de pe bord (fuel_odometer_calibrations)
 *    + km GPS parcursi intre citire si alimentare. SAS nu expune odometrul CAN,
 *    iar kmIndex-ul SAS porneste din citiri gresite (01.02.2026), deci nu se foloseste.
 *
 * Rezultatele SAS se salveaza in `fuel_fillup_telemetry`: intervalul dintre doua
 * alimentari e inchis, deci datele nu se mai schimba. Randul se invalideaza
 * singur daca alimentarea precedenta se schimba (ex. import intarziat), pentru
 * ca join-ul cere acelasi prev_fillup_id.
 */
class FuelKmCheckModel extends BaseModel
{
    /** Datele SAS ale unei alimentari recente se mai completeaza (evenimente dupa bon). */
    private const SETTLE_SECONDS = 2 * 3600;
    /** Vehiculele fara GPS se reverifica zilnic (pot fi adaugate in SAS intre timp). */
    private const NO_GPS_RETRY_SECONDS = 86400;

    public function ensureSchema(): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS fuel_fillup_telemetry (
                fillup_id INT UNSIGNED NOT NULL PRIMARY KEY,
                prev_fillup_id INT UNSIGNED NULL,
                sas_car_id INT UNSIGNED NULL,
                status VARCHAR(20) NOT NULL,
                gps_km DECIMAL(10,1) NULL,
                can_fuel_l DECIMAL(10,1) NULL,
                has_can TINYINT(1) NOT NULL DEFAULT 0,
                gps_odometer_km INT UNSIGNED NULL,
                fuel_level_before DECIMAL(8,1) NULL,
                fuel_level_after DECIMAL(8,1) NULL,
                fuel_detected_l DECIMAL(8,1) NULL,
                error_message VARCHAR(255) NULL,
                calibration_id INT UNSIGNED NULL,
                gps_km_since_calibration DECIMAL(12,1) NULL,
                gps_first_at DATETIME NULL,
                gps_last_at DATETIME NULL,
                gps_segments INT UNSIGNED NULL,
                fetched_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        // Tabela a existat o zi fara coloanele de calibrare.
        foreach ([
            'calibration_id' => 'INT UNSIGNED NULL',
            'gps_km_since_calibration' => 'DECIMAL(12,1) NULL',
            'gps_first_at' => 'DATETIME NULL',
            'gps_last_at' => 'DATETIME NULL',
            'gps_segments' => 'INT UNSIGNED NULL',
        ] as $column => $definition) {
            $check = $this->db->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fuel_fillup_telemetry' AND COLUMN_NAME = :column_name"
            );
            $check->execute([':column_name' => $column]);
            if ((int) $check->fetchColumn() === 0) {
                $this->db->exec("ALTER TABLE fuel_fillup_telemetry ADD COLUMN {$column} {$definition} AFTER error_message");
            }
        }

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS fuel_odometer_calibrations (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                plate_key VARCHAR(20) NOT NULL,
                vehicle_registration VARCHAR(30) NOT NULL,
                reading_km INT UNSIGNED NOT NULL,
                reading_datetime DATETIME NOT NULL,
                source VARCHAR(40) NOT NULL,
                note VARCHAR(255) NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                KEY idx_fuel_odo_calib_plate (plate_key, reading_datetime)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $ensured = true;
    }

    /**
     * Alimentarile de motorina din perioada (si vehiculele filtrate), cu
     * alimentarea precedenta a vehiculului (chiar si din afara perioadei) si
     * datele SAS deja salvate.
     */
    public function getRows(string $dateFrom, string $dateTo, array $vehicles = []): array
    {
        $this->ensureSchema();

        $params = [
            ':kc_from' => $dateFrom . ' 00:00:00',
            ':kc_to' => $dateTo . ' 23:59:59',
        ];
        $vehicleSql = '';
        $placeholders = [];
        foreach (array_values(array_unique(array_filter(array_map('trim', $vehicles)))) as $index => $plate) {
            $placeholders[] = "REPLACE(UPPER(:kc_vehicle_{$index}), ' ', '')";
            $params[":kc_vehicle_{$index}"] = $plate;
        }
        if ($placeholders !== []) {
            $vehicleSql = ' AND x.plate_key IN (' . implode(', ', $placeholders) . ')';
        }

        $stmt = $this->db->prepare("
            SELECT x.*, t.status AS t_status, t.gps_km, t.can_fuel_l, t.has_can, t.gps_odometer_km,
                   t.fuel_level_before, t.fuel_level_after, t.fuel_detected_l, t.error_message, t.fetched_at,
                   t.calibration_id AS t_calibration_id, t.gps_km_since_calibration,
                   t.gps_first_at, t.gps_last_at, t.gps_segments
            FROM (" . $this->fillupsWithPreviousSql() . ") x
            LEFT JOIN fuel_fillup_telemetry t
                   ON t.fillup_id = x.id AND t.prev_fillup_id <=> x.prev_id
            WHERE x.fillup_datetime >= :kc_from AND x.fillup_datetime <= :kc_to
            {$vehicleSql}
            ORDER BY x.fillup_datetime DESC, x.id DESC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $calibrations = $this->calibrationsByPlate();

        return array_map(function (array $row) use ($calibrations): array {
            $row['calibration'] = $this->pickCalibration($calibrations[(string) $row['plate_key']] ?? [], (string) $row['fillup_datetime']);
            return $this->present($row);
        }, $rows);
    }

    // ------------------------------------------------------------- calibrari

    /** Toate citirile de incredere, cele mai noi primele. */
    public function getCalibrations(): array
    {
        $this->ensureSchema();

        return $this->db->query("
            SELECT id, vehicle_registration, reading_km, reading_datetime, source, note, created_at
            FROM fuel_odometer_calibrations
            ORDER BY reading_datetime DESC, id DESC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function addCalibration(string $registration, int $readingKm, string $readingDatetime, string $source, ?string $note, ?int $userId): void
    {
        $this->ensureSchema();

        $stmt = $this->db->prepare("
            INSERT INTO fuel_odometer_calibrations
                (plate_key, vehicle_registration, reading_km, reading_datetime, source, note, created_by, created_at)
            VALUES (:plate_key, :registration, :reading_km, :reading_datetime, :source, :note, :created_by, :created_at)
        ");
        $stmt->execute([
            ':plate_key' => strtoupper(str_replace(' ', '', $registration)),
            ':registration' => $registration,
            ':reading_km' => $readingKm,
            ':reading_datetime' => $readingDatetime,
            ':source' => $source,
            ':note' => $note !== null && $note !== '' ? mb_substr($note, 0, 255) : null,
            ':created_by' => $userId,
            ':created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function deleteCalibration(int $id): bool
    {
        $this->ensureSchema();

        $stmt = $this->db->prepare('DELETE FROM fuel_odometer_calibrations WHERE id = :id');
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /** Calibrarea folosita pentru o alimentare (aceeasi regula ca in lista). */
    public function calibrationFor(string $plateKey, string $fillupDatetime): ?array
    {
        $this->ensureSchema();

        return $this->pickCalibration($this->calibrationsByPlate()[$plateKey] ?? [], $fillupDatetime);
    }

    /** @return array<string, array<int, array<string, mixed>>> citirile pe placuta, crescator dupa data */
    private function calibrationsByPlate(): array
    {
        $grouped = [];
        $rows = $this->db->query("
            SELECT id, plate_key, reading_km, reading_datetime, source
            FROM fuel_odometer_calibrations
            ORDER BY reading_datetime ASC, id ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $grouped[(string) $row['plate_key']][] = $row;
        }

        return $grouped;
    }

    /**
     * Cea mai recenta citire de dinaintea alimentarii (eroarea GPS creste cu
     * distanta, deci cea mai apropiata e cea mai buna); daca alimentarea e mai
     * veche decat toate citirile, prima citire de dupa ea (se scad km GPS).
     */
    private function pickCalibration(array $calibrations, string $fillupDatetime): ?array
    {
        $before = null;
        foreach ($calibrations as $calibration) {
            if ((string) $calibration['reading_datetime'] <= $fillupDatetime) {
                $before = $calibration;
                continue;
            }
            return $before ?? $calibration;
        }

        return $before;
    }

    /** O singura alimentare (cu precedenta ei), pentru completarea din SAS. */
    public function getFillup(int $fillupId): ?array
    {
        $this->ensureSchema();

        $stmt = $this->db->prepare("
            SELECT x.* FROM (" . $this->fillupsWithPreviousSql() . ") x WHERE x.id = :kc_id
        ");
        $stmt->execute([':kc_id' => $fillupId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function saveTelemetry(array $fillup, ?int $sasCarId, string $status, array $data = [], ?string $error = null): array
    {
        $this->ensureSchema();

        $stmt = $this->db->prepare("
            REPLACE INTO fuel_fillup_telemetry
                (fillup_id, prev_fillup_id, sas_car_id, status, gps_km, can_fuel_l, has_can, gps_odometer_km,
                 fuel_level_before, fuel_level_after, fuel_detected_l, error_message,
                 calibration_id, gps_km_since_calibration, gps_first_at, gps_last_at, gps_segments, fetched_at)
            VALUES
                (:fillup_id, :prev_fillup_id, :sas_car_id, :status, :gps_km, :can_fuel_l, :has_can, :gps_odometer_km,
                 :fuel_level_before, :fuel_level_after, :fuel_detected_l, :error_message,
                 :calibration_id, :gps_km_since_calibration, :gps_first_at, :gps_last_at, :gps_segments, :fetched_at)
        ");
        $prevId = (int) ($fillup['prev_id'] ?? 0);
        $stmt->execute([
            ':fillup_id' => (int) $fillup['id'],
            ':prev_fillup_id' => $prevId > 0 ? $prevId : null,
            ':sas_car_id' => $sasCarId,
            ':status' => $status,
            ':gps_km' => $data['gps_km'] ?? null,
            ':can_fuel_l' => $data['can_fuel_l'] ?? null,
            ':has_can' => !empty($data['has_can']) ? 1 : 0,
            ':gps_odometer_km' => $data['gps_odometer_km'] ?? null,
            ':fuel_level_before' => $data['fuel_level_before'] ?? null,
            ':fuel_level_after' => $data['fuel_level_after'] ?? null,
            ':fuel_detected_l' => $data['fuel_detected_l'] ?? null,
            ':error_message' => $error !== null ? mb_substr($error, 0, 255) : null,
            ':calibration_id' => $data['calibration_id'] ?? null,
            ':gps_km_since_calibration' => $data['gps_km_since_calibration'] ?? null,
            ':gps_first_at' => $data['gps_first_at'] ?? null,
            ':gps_last_at' => $data['gps_last_at'] ?? null,
            ':gps_segments' => $data['gps_segments'] ?? null,
            ':fetched_at' => date('Y-m-d H:i:s'),
        ]);

        $row = $fillup + [
            't_status' => $status,
            'gps_km' => $data['gps_km'] ?? null,
            'can_fuel_l' => $data['can_fuel_l'] ?? null,
            'has_can' => !empty($data['has_can']) ? 1 : 0,
            'gps_odometer_km' => $data['gps_odometer_km'] ?? null,
            'fuel_level_before' => $data['fuel_level_before'] ?? null,
            'fuel_level_after' => $data['fuel_level_after'] ?? null,
            'fuel_detected_l' => $data['fuel_detected_l'] ?? null,
            'error_message' => $error,
            't_calibration_id' => $data['calibration_id'] ?? null,
            'gps_km_since_calibration' => $data['gps_km_since_calibration'] ?? null,
            'gps_first_at' => $data['gps_first_at'] ?? null,
            'gps_last_at' => $data['gps_last_at'] ?? null,
            'gps_segments' => $data['gps_segments'] ?? null,
            'calibration' => $fillup['calibration'] ?? null,
            'fetched_at' => date('Y-m-d H:i:s'),
        ];

        return $this->present($row);
    }

    /**
     * Alimentarile de motorina valide cu alimentarea precedenta a aceluiasi
     * vehicul (placuta normalizata). Fara filtre de perioada: precedenta poate
     * fi din luna anterioara.
     */
    private function fillupsWithPreviousSql(): string
    {
        return "
            SELECT f.id, f.vehicle_registration, f.driver_name, f.station_name, f.fillup_datetime,
                   f.quantity_liters, f.odometer_km, f.odometer_km_manual, f.is_full,
                   REPLACE(UPPER(f.vehicle_registration), ' ', '') AS plate_key,
                   LAG(f.id) OVER w AS prev_id,
                   LAG(f.fillup_datetime) OVER w AS prev_datetime,
                   LAG(f.odometer_km) OVER w AS prev_odometer_km
            FROM fuel_fillups f
            WHERE f.fuel_type = 'motorina'
              AND f.source_type NOT IN ('test', 'demo')
              AND f.quantity_liters > 0
            WINDOW w AS (PARTITION BY REPLACE(UPPER(f.vehicle_registration), ' ', '') ORDER BY f.fillup_datetime, f.id)
        ";
    }

    /** Randul pentru UI: valori numerice + comparatii + verdicte. */
    private function present(array $row): array
    {
        $num = static fn ($value): ?float => is_numeric($value) ? (float) $value : null;

        $odometer = $num($row['odometer_km'] ?? null);
        $prevOdometer = $num($row['prev_odometer_km'] ?? null);
        $declaredKm = ($odometer !== null && $odometer > 0 && $prevOdometer !== null && $prevOdometer > 0)
            ? $odometer - $prevOdometer
            : null;

        $status = $row['t_status'] ?? null;
        $fetchedAt = isset($row['fetched_at']) ? strtotime((string) $row['fetched_at']) : false;
        $fillupTs = strtotime((string) $row['fillup_datetime']);
        $needsFetch = $status === null
            || $status === 'error'
            || ($status === 'no_gps' && $fetchedAt !== false && time() - $fetchedAt > self::NO_GPS_RETRY_SECONDS)
            // Evenimentele de dupa bon (nivelul din rezervor) apar abia dupa fereastra de o ora.
            || ($status === 'ok' && $fetchedAt !== false && $fillupTs !== false && $fetchedAt - $fillupTs < self::SETTLE_SECONDS
                && time() - $fillupTs >= self::SETTLE_SECONDS);

        // Calibrarea s-a schimbat (adaugata / stearsa) -> km GPS de la citire trebuie recalculati.
        $calibration = is_array($row['calibration'] ?? null) ? $row['calibration'] : null;
        $cachedCalibrationId = (int) ($row['t_calibration_id'] ?? 0);
        if ($status === 'ok' && (int) ($calibration['id'] ?? 0) !== $cachedCalibrationId) {
            $needsFetch = true;
        }
        // Randuri salvate inainte sa se retina momentele GPS (gps_segments NULL).
        if ($status === 'ok' && ($row['gps_segments'] ?? null) === null && ($row['prev_id'] ?? null) !== null) {
            $needsFetch = true;
        }

        $gpsOdometer = null;
        $gpsKmSinceCalibration = null;
        if ($status === 'ok' && $calibration !== null && $cachedCalibrationId === (int) $calibration['id']
            && is_numeric($row['gps_km_since_calibration'] ?? null)
        ) {
            $gpsKmSinceCalibration = (float) $row['gps_km_since_calibration'];
            $afterFillup = (string) $calibration['reading_datetime'] > (string) $row['fillup_datetime'];
            $gpsOdometer = (float) $calibration['reading_km'] + ($afterFillup ? -$gpsKmSinceCalibration : $gpsKmSinceCalibration);
        }
        $odometerDiff = ($gpsOdometer !== null && $odometer !== null && $odometer > 0) ? $odometer - $gpsOdometer : null;

        $gpsKm = $status === 'ok' ? $num($row['gps_km'] ?? null) : null;
        $kmDiff = ($declaredKm !== null && $gpsKm !== null) ? $declaredKm - $gpsKm : null;

        $liters = (float) ($row['quantity_liters'] ?? 0);
        $detected = $status === 'ok' ? $num($row['fuel_detected_l'] ?? null) : null;
        $fuelDiff = $detected !== null ? $liters - $detected : null;

        $canFuel = $status === 'ok' ? $num($row['can_fuel_l'] ?? null) : null;
        $canL100 = ($canFuel !== null && $gpsKm !== null && $gpsKm >= 5) ? $canFuel / $gpsKm * 100 : null;
        if ($canL100 !== null && ($canL100 < 4 || $canL100 > 120)) {
            $canL100 = null;
        }

        return [
            'id' => (int) $row['id'],
            'vehicle' => (string) $row['vehicle_registration'],
            'driver' => trim((string) ($row['driver_name'] ?? '')),
            'station' => (string) ($row['station_name'] ?? ''),
            'datetime' => (string) $row['fillup_datetime'],
            'liters' => round($liters, 2),
            'is_full' => !empty($row['is_full']),
            'odometer' => $odometer,
            'odometer_corrected' => ($row['odometer_km_manual'] ?? null) !== null,
            'prev_datetime' => $row['prev_datetime'] ?? null,
            'prev_odometer' => $prevOdometer,
            'declared_km' => $declaredKm,
            'status' => $status,
            'needs_fetch' => $needsFetch,
            'error' => $status === 'error' ? (string) ($row['error_message'] ?? '') : null,
            'gps_km' => $gpsKm,
            'gps_first_at' => $status === 'ok' ? ($row['gps_first_at'] ?? null) : null,
            'gps_last_at' => $status === 'ok' ? ($row['gps_last_at'] ?? null) : null,
            'gps_segments' => $status === 'ok' && ($row['gps_segments'] ?? null) !== null ? (int) $row['gps_segments'] : null,
            'calibration' => $calibration !== null ? [
                'id' => (int) $calibration['id'],
                'reading_km' => (int) $calibration['reading_km'],
                'reading_datetime' => (string) $calibration['reading_datetime'],
                'source' => (string) $calibration['source'],
            ] : null,
            'gps_odometer' => $gpsOdometer !== null ? round($gpsOdometer) : null,
            'gps_km_since_calibration' => $gpsKmSinceCalibration,
            'odometer_diff' => $odometerDiff !== null ? round($odometerDiff) : null,
            'odometer_verdict' => $this->odometerVerdict($odometerDiff, $gpsKmSinceCalibration),
            'km_diff' => $kmDiff !== null ? round($kmDiff, 1) : null,
            'km_diff_pct' => ($kmDiff !== null && $gpsKm > 0) ? round($kmDiff / $gpsKm * 100, 1) : null,
            'km_verdict' => $this->kmVerdict($declaredKm, $gpsKm, $prevOdometer !== null),
            'can_fuel_l' => $canFuel,
            'can_l100' => $canL100 !== null ? round($canL100, 1) : null,
            'fuel_before' => $status === 'ok' ? $num($row['fuel_level_before'] ?? null) : null,
            'fuel_after' => $status === 'ok' ? $num($row['fuel_level_after'] ?? null) : null,
            'fuel_detected' => $detected,
            'fuel_diff' => $fuelDiff !== null ? round($fuelDiff, 1) : null,
            'fuel_verdict' => $this->fuelVerdict($liters, $detected),
        ];
    }

    /**
     * ok / warn / bad / null (nu se poate compara). Toleranta: GPS-ul pierde
     * cativa km la fiecare pornire (fix-ul), deci pragurile sunt si absolute, si relative.
     */
    private function kmVerdict(?float $declared, ?float $gps, bool $hasPrevious): ?string
    {
        if (!$hasPrevious || $gps === null) {
            return null;
        }
        if ($declared === null || $declared < 0) {
            return 'bad';
        }
        $diff = abs($declared - $gps);
        if ($diff <= max(20.0, $gps * 0.03)) {
            return 'ok';
        }
        if ($diff <= max(50.0, $gps * 0.10)) {
            return 'warn';
        }

        return 'bad';
    }

    /**
     * Declarat vs odometru GPS calibrat. Eroarea GPS se aduna cu distanta de la
     * citire, deci toleranta creste cu ea: 30 km + 2% din km GPS de la calibrare.
     */
    private function odometerVerdict(?float $diff, ?float $kmSinceCalibration): ?string
    {
        if ($diff === null || $kmSinceCalibration === null) {
            return null;
        }
        $tolerance = 30.0 + abs($kmSinceCalibration) * 0.02;
        if (abs($diff) <= $tolerance) {
            return 'ok';
        }
        if (abs($diff) <= $tolerance * 2.5) {
            return 'warn';
        }

        return 'bad';
    }

    /** Sonda de nivel are o eroare de cativa %, deci si aici pragul e dublu. */
    private function fuelVerdict(float $liters, ?float $detected): ?string
    {
        if ($detected === null) {
            return null;
        }
        $diff = abs($liters - $detected);
        if ($diff <= max(15.0, $liters * 0.05)) {
            return 'ok';
        }
        if ($diff <= max(40.0, $liters * 0.15)) {
            return 'warn';
        }

        return 'bad';
    }
}
