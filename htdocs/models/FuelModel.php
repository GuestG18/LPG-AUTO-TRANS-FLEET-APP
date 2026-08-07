<?php
declare(strict_types=1);

class FuelModel extends BaseModel
{
    private const TRANSPORT_GROUPS = [
        'primar' => ['primar', 'primar_tona'],
        'distributie' => ['distributie'],
        'compresor' => ['compresor'],
        'primar_distributie' => ['primar_distributie'],
    ];

    private const TRANSPORT_LABELS = [
        'primar' => 'Primar',
        'distributie' => 'Distributie',
        'compresor' => 'Compresor',
        'primar_distributie' => 'Primar + Distributie',
        'neasociat' => 'Neasociat',
    ];

    private bool $schemaEnsured = false;
    private ?bool $raceSoftDeleteAvailable = null;

    public function ensureSchema(): void
    {
        if ($this->schemaEnsured) {
            return;
        }

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS fuel_fillups (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                api_id VARCHAR(160) NOT NULL,
                vehicle_registration VARCHAR(40) NOT NULL,
                driver_name VARCHAR(180) NULL,
                fuel_type ENUM('motorina', 'adblue') NOT NULL,
                quantity_liters DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                odometer_km INT UNSIGNED NULL,
                total_value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                station_name VARCHAR(180) NULL,
                fillup_datetime DATETIME NOT NULL,
                is_full TINYINT(1) NOT NULL DEFAULT 0,
                raw_payload LONGTEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uk_fuel_fillups_api_id (api_id),
                INDEX idx_fuel_fillups_vehicle_datetime (vehicle_registration, fillup_datetime),
                INDEX idx_fuel_fillups_fuel_type (fuel_type),
                INDEX idx_fuel_fillups_full (vehicle_registration, fuel_type, is_full, fillup_datetime)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        if (!$this->columnExists('fuel_fillups', 'odometer_km')) {
            $this->db->exec('ALTER TABLE fuel_fillups ADD COLUMN odometer_km INT UNSIGNED NULL AFTER quantity_liters');
        }

        if (!$this->columnExists('fuel_fillups', 'driver_name')) {
            $this->db->exec('ALTER TABLE fuel_fillups ADD COLUMN driver_name VARCHAR(180) NULL AFTER vehicle_registration');
        }

        $this->backfillDriverNamesFromRawPayload();

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS fuel_trip_links (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                fillup_id INT UNSIGNED NOT NULL,
                trip_id INT UNSIGNED NOT NULL,
                match_type ENUM('automatic', 'manual') NOT NULL DEFAULT 'automatic',
                confidence DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uk_fuel_trip_links_fillup (fillup_id),
                INDEX idx_fuel_trip_links_trip (trip_id),
                CONSTRAINT fk_fuel_trip_links_fillup FOREIGN KEY (fillup_id) REFERENCES fuel_fillups(id) ON DELETE CASCADE,
                CONSTRAINT fk_fuel_trip_links_trip FOREIGN KEY (trip_id) REFERENCES curse_dispecer(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS fuel_sync_logs (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                sync_started_at DATETIME NOT NULL,
                sync_finished_at DATETIME NULL,
                date_from DATE NOT NULL,
                date_to DATE NOT NULL,
                status VARCHAR(30) NOT NULL,
                records_received INT UNSIGNED NOT NULL DEFAULT 0,
                records_inserted INT UNSIGNED NOT NULL DEFAULT 0,
                records_updated INT UNSIGNED NOT NULL DEFAULT 0,
                error_message TEXT NULL,
                INDEX idx_fuel_sync_logs_started (sync_started_at),
                INDEX idx_fuel_sync_logs_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS fuel_sync_state (
                state_key VARCHAR(80) NOT NULL PRIMARY KEY,
                state_value VARCHAR(255) NULL,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->schemaEnsured = true;
    }

    public function getTransportLabels(): array
    {
        return self::TRANSPORT_LABELS;
    }

    public function getVehicleOptions(): array
    {
        $this->ensureSchema();

        $stmt = $this->db->query("
            SELECT vehicle_registration
            FROM (
                SELECT DISTINCT TRIM(nr_inmatriculare) AS vehicle_registration
                FROM vehicule
                WHERE COALESCE(TRIM(nr_inmatriculare), '') <> ''
                UNION
                SELECT DISTINCT TRIM(vehicle_registration) AS vehicle_registration
                FROM fuel_fillups
                WHERE COALESCE(TRIM(vehicle_registration), '') <> ''
            ) vehicles
            ORDER BY vehicle_registration ASC
        ");

        return $stmt->fetchAll();
    }

    public function syncFromApi(DateTimeInterface $dateFrom, DateTimeInterface $dateTo, CardOilApiClient $client): array
    {
        $this->ensureSchema();

        $startedAt = date('Y-m-d H:i:s');
        $logId = $this->createSyncLog($startedAt, $dateFrom, $dateTo);
        $status = 'success';
        $errorMessage = null;
        $records = [];
        $source = 'api';
        $meta = [];

        try {
            $result = $client->fetchFillups($dateFrom, $dateTo);
            $records = (array) ($result['records'] ?? []);
            $source = (string) ($result['source'] ?? 'api');
            $meta = is_array($result['meta'] ?? null) ? $result['meta'] : [];
            $errorMessage = is_string($result['error'] ?? null) ? $result['error'] : null;

            if ($records === [] && $this->shouldUseDemoData()) {
                $records = $this->buildDemoRecords($dateFrom, $dateTo);
                $source = 'demo';
                $status = 'demo';
                $errorMessage = $errorMessage !== null ? $errorMessage . ' Date demo locale inserate.' : 'API fara date. Date demo locale inserate.';
            } elseif ($source !== 'api') {
                $status = $source === 'missing_credentials' ? 'missing_credentials' : $source;
            }

            $upsert = $this->upsertFillups($records);
            $this->refreshAutomaticAssociations($dateFrom->format('Y-m-d'), $dateTo->format('Y-m-d'));
            $this->storeSyncMeta($meta);

            $this->finishSyncLog($logId, [
                'status' => $status,
                'records_received' => count($records),
                'records_inserted' => $upsert['inserted'],
                'records_updated' => $upsert['updated'],
                'error_message' => $errorMessage,
            ]);

            return [
                'status' => $status,
                'source' => $source,
                'records_received' => count($records),
                'records_inserted' => $upsert['inserted'],
                'records_updated' => $upsert['updated'],
                'error_message' => $errorMessage,
            ];
        } catch (Throwable $exception) {
            if ($this->shouldUseDemoData()) {
                $records = $this->buildDemoRecords($dateFrom, $dateTo);
                $upsert = $this->upsertFillups($records);
                $this->refreshAutomaticAssociations($dateFrom->format('Y-m-d'), $dateTo->format('Y-m-d'));

                $message = $exception->getMessage() . ' Date demo locale inserate.';
                $this->finishSyncLog($logId, [
                    'status' => 'demo',
                    'records_received' => count($records),
                    'records_inserted' => $upsert['inserted'],
                    'records_updated' => $upsert['updated'],
                    'error_message' => $message,
                ]);

                return [
                    'status' => 'demo',
                    'source' => 'demo',
                    'records_received' => count($records),
                    'records_inserted' => $upsert['inserted'],
                    'records_updated' => $upsert['updated'],
                    'error_message' => $message,
                ];
            }

            $this->finishSyncLog($logId, [
                'status' => 'error',
                'records_received' => 0,
                'records_inserted' => 0,
                'records_updated' => 0,
                'error_message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    public function deleteFillups(?DateTimeInterface $dateFrom = null, ?DateTimeInterface $dateTo = null): int
    {
        $this->ensureSchema();

        if ($dateFrom === null && $dateTo === null) {
            return (int) $this->db->exec('DELETE FROM fuel_fillups');
        }

        $where = [];
        $params = [];
        if ($dateFrom !== null) {
            $where[] = 'fillup_datetime >= :date_from';
            $params[':date_from'] = $dateFrom->format('Y-m-d 00:00:00');
        }
        if ($dateTo !== null) {
            $where[] = 'fillup_datetime <= :date_to';
            $params[':date_to'] = $dateTo->format('Y-m-d 23:59:59');
        }

        $stmt = $this->db->prepare('DELETE FROM fuel_fillups WHERE ' . implode(' AND ', $where));
        $this->bindParams($stmt, $params);
        $stmt->execute();

        return $stmt->rowCount();
    }

    public function ensureLocalDemoData(array $filters): void
    {
        $this->ensureSchema();
        if (!$this->shouldUseDemoData()) {
            return;
        }

        $where = $this->buildFillupWhere($filters, 'demo_check', false);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM fuel_fillups f " . $where['where'] . " AND f.api_id NOT LIKE 'demo-cardoil-%'");
        $this->bindParams($stmt, $where['params']);
        $stmt->execute();
        if ((int) $stmt->fetchColumn() > 0) {
            return;
        }

        $dateFrom = new DateTimeImmutable((string) $filters['date_from']);
        $dateTo = new DateTimeImmutable((string) $filters['date_to']);
        $this->deleteDemoFillupsForPeriod($dateFrom, $dateTo);
        $this->upsertFillups($this->buildDemoRecords($dateFrom, $dateTo));
        $this->refreshAutomaticAssociations($dateFrom->format('Y-m-d'), $dateTo->format('Y-m-d'));
    }

    public function upsertFillups(array $records): array
    {
        $this->ensureSchema();

        if ($records === []) {
            return ['inserted' => 0, 'updated' => 0];
        }

        $existsStmt = $this->db->prepare('SELECT id FROM fuel_fillups WHERE api_id = :api_id LIMIT 1');
        $stmt = $this->db->prepare("
            INSERT INTO fuel_fillups (
                api_id,
                vehicle_registration,
                driver_name,
                fuel_type,
                quantity_liters,
                odometer_km,
                total_value,
                station_name,
                fillup_datetime,
                is_full,
                raw_payload,
                created_at,
                updated_at
            ) VALUES (
                :api_id,
                :vehicle_registration,
                :driver_name,
                :fuel_type,
                :quantity_liters,
                :odometer_km,
                :total_value,
                :station_name,
                :fillup_datetime,
                :is_full,
                :raw_payload,
                :created_at,
                :updated_at
            )
            ON DUPLICATE KEY UPDATE
                vehicle_registration = VALUES(vehicle_registration),
                driver_name = VALUES(driver_name),
                fuel_type = VALUES(fuel_type),
                quantity_liters = VALUES(quantity_liters),
                odometer_km = VALUES(odometer_km),
                total_value = VALUES(total_value),
                station_name = VALUES(station_name),
                fillup_datetime = VALUES(fillup_datetime),
                is_full = VALUES(is_full),
                raw_payload = VALUES(raw_payload),
                updated_at = VALUES(updated_at)
        ");

        $inserted = 0;
        $updated = 0;
        $now = date('Y-m-d H:i:s');

        foreach ($records as $record) {
            if (!is_array($record) || empty($record['api_id'])) {
                continue;
            }

            $existsStmt->bindValue(':api_id', (string) $record['api_id']);
            $existsStmt->execute();
            $exists = (int) $existsStmt->fetchColumn() > 0;

            $rawPayload = $record['raw_payload'] ?? $record;
            $rawPayloadJson = json_encode($rawPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($rawPayloadJson)) {
                $rawPayloadJson = null;
            }

            $stmt->bindValue(':api_id', (string) $record['api_id']);
            $stmt->bindValue(':vehicle_registration', $this->normalizeRegistration((string) ($record['vehicle_registration'] ?? '')));
            $this->bindNullableString($stmt, ':driver_name', isset($record['driver_name']) ? (string) $record['driver_name'] : null);
            $stmt->bindValue(':fuel_type', (string) ($record['fuel_type'] ?? 'motorina'));
            $stmt->bindValue(':quantity_liters', max(0.0, (float) ($record['quantity_liters'] ?? 0)));
            $odometerKm = (int) round((float) ($record['odometer_km'] ?? 0));
            if ($odometerKm > 0) {
                $stmt->bindValue(':odometer_km', $odometerKm, PDO::PARAM_INT);
            } else {
                $stmt->bindValue(':odometer_km', null, PDO::PARAM_NULL);
            }
            $stmt->bindValue(':total_value', max(0.0, (float) ($record['total_value'] ?? 0)));
            $this->bindNullableString($stmt, ':station_name', isset($record['station_name']) ? (string) $record['station_name'] : null);
            $stmt->bindValue(':fillup_datetime', (string) ($record['fillup_datetime'] ?? $now));
            $stmt->bindValue(':is_full', !empty($record['is_full']) ? 1 : 0, PDO::PARAM_INT);
            $this->bindNullableString($stmt, ':raw_payload', $rawPayloadJson);
            $stmt->bindValue(':created_at', $now);
            $stmt->bindValue(':updated_at', $now);
            $stmt->execute();

            if ($exists) {
                $updated++;
            } else {
                $inserted++;
            }
        }

        return ['inserted' => $inserted, 'updated' => $updated];
    }

    public function refreshAutomaticAssociations(?string $dateFrom = null, ?string $dateTo = null): array
    {
        $this->ensureSchema();

        $where = [];
        $params = [];
        if ($dateFrom !== null && $dateFrom !== '') {
            $where[] = 'f.fillup_datetime >= :date_from';
            $params[':date_from'] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== null && $dateTo !== '') {
            $where[] = 'f.fillup_datetime <= :date_to';
            $params[':date_to'] = $dateTo . ' 23:59:59';
        }

        $sql = "
            SELECT f.*
            FROM fuel_fillups f
            LEFT JOIN fuel_trip_links manual_link
              ON manual_link.fillup_id = f.id
             AND manual_link.match_type = 'manual'
            WHERE manual_link.id IS NULL
            " . ($where !== [] ? ' AND ' . implode(' AND ', $where) : '') . "
            ORDER BY f.fillup_datetime ASC, f.id ASC
        ";

        $stmt = $this->db->prepare($sql);
        $this->bindParams($stmt, $params);
        $stmt->execute();
        $fillups = $stmt->fetchAll();

        $deleteAutoStmt = $this->db->prepare("
            DELETE FROM fuel_trip_links
            WHERE fillup_id = :fillup_id
              AND match_type = 'automatic'
        ");
        $insertStmt = $this->db->prepare("
            INSERT INTO fuel_trip_links (fillup_id, trip_id, match_type, confidence, created_at)
            VALUES (:fillup_id, :trip_id, 'automatic', :confidence, :created_at)
            ON DUPLICATE KEY UPDATE
                trip_id = VALUES(trip_id),
                match_type = VALUES(match_type),
                confidence = VALUES(confidence),
                created_at = VALUES(created_at)
        ");

        $matched = 0;
        $unmatched = 0;
        foreach ($fillups as $fillup) {
            $fillupId = (int) ($fillup['id'] ?? 0);
            if ($fillupId <= 0) {
                continue;
            }

            $deleteAutoStmt->bindValue(':fillup_id', $fillupId, PDO::PARAM_INT);
            $deleteAutoStmt->execute();

            $trip = $this->findMatchingTrip($fillup);
            if ($trip === null) {
                $unmatched++;
                continue;
            }

            $insertStmt->bindValue(':fillup_id', $fillupId, PDO::PARAM_INT);
            $insertStmt->bindValue(':trip_id', (int) $trip['id'], PDO::PARAM_INT);
            $insertStmt->bindValue(':confidence', (float) ($trip['confidence'] ?? 0.95));
            $insertStmt->bindValue(':created_at', date('Y-m-d H:i:s'));
            $insertStmt->execute();
            $matched++;
        }

        return ['matched' => $matched, 'unmatched' => $unmatched];
    }

    public function linkFillupToTrip(int $fillupId, int $tripId): bool
    {
        $this->ensureSchema();
        if ($fillupId <= 0 || $tripId <= 0) {
            return false;
        }

        $stmt = $this->db->prepare("
            INSERT INTO fuel_trip_links (fillup_id, trip_id, match_type, confidence, created_at)
            VALUES (:fillup_id, :trip_id, 'manual', 1.00, :created_at)
            ON DUPLICATE KEY UPDATE
                trip_id = VALUES(trip_id),
                match_type = 'manual',
                confidence = 1.00,
                created_at = VALUES(created_at)
        ");
        $stmt->bindValue(':fillup_id', $fillupId, PDO::PARAM_INT);
        $stmt->bindValue(':trip_id', $tripId, PDO::PARAM_INT);
        $stmt->bindValue(':created_at', date('Y-m-d H:i:s'));

        return $stmt->execute();
    }

    public function setFillupFull(int $fillupId, bool $isFull): bool
    {
        $this->ensureSchema();

        $stmt = $this->db->prepare('
            UPDATE fuel_fillups
            SET is_full = :is_full, updated_at = :updated_at
            WHERE id = :id
        ');
        $stmt->bindValue(':is_full', $isFull ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'));
        $stmt->bindValue(':id', $fillupId, PDO::PARAM_INT);

        return $stmt->execute() && $stmt->rowCount() > 0;
    }

    public function getDashboardData(array $filters): array
    {
        $this->ensureSchema();

        $summary = $this->getKpiSummary($filters);
        $previousSummary = $this->getKpiSummary($this->previousPeriodFilters($filters));
        $summary['changes'] = [
            'motorina_liters' => $this->percentageChange($summary['motorina_liters'], $previousSummary['motorina_liters']),
            'adblue_liters' => $this->percentageChange($summary['adblue_liters'], $previousSummary['adblue_liters']),
            'motorina_avg_l100' => $this->percentageChange($summary['motorina_avg_l100'], $previousSummary['motorina_avg_l100']),
            'adblue_percent' => $this->percentageChange($summary['adblue_percent'], $previousSummary['adblue_percent']),
            'total_value' => $this->percentageChange($summary['total_value'], $previousSummary['total_value']),
        ];

        return [
            'kpis' => $summary,
            'daily_chart' => $this->getDailyConsumptionChart($filters, $summary['motorina_avg_l100']),
            'transport_chart' => $this->getTransportBreakdown($filters),
            'normative' => $this->getNormativeInterval($filters),
            'latest_fillups' => $this->getFillups($filters, 5),
            'all_fillups' => $this->getFillups($filters, 200),
            'unassociated_fillups' => $this->getUnassociatedFillups($filters, 100),
            'trip_consumption' => $this->getConsumptionByTrip($filters),
            'transport_consumption' => $this->getConsumptionByTransport($filters),
            'trip_options' => $this->getTripOptions($filters),
            'sync_logs' => $this->getSyncLogs(10),
            'last_sync' => $this->getLastSyncLog(),
            'vehicle_options' => $this->getVehicleOptions(),
        ];
    }

    public function getConsumptionByVehicle(array $filters): array
    {
        $this->ensureSchema();

        // Cu 2+ vehicule selectate se compara doar selectia; altfel toata flota.
        if (count($this->selectedVehicles($filters)) < 2) {
            $filters['vehicle'] = '';
            $filters['vehicles'] = [];
        }

        $where = $this->buildFillupWhere($filters, 'veh_cmp', false);
        $stmt = $this->db->prepare("
            SELECT
                REPLACE(UPPER(f.vehicle_registration), ' ', '') AS vehicle_key,
                MAX(f.vehicle_registration) AS vehicle_registration,
                COUNT(*) AS fillup_count,
                COALESCE(SUM(CASE WHEN f.fuel_type = 'motorina' THEN f.quantity_liters ELSE 0 END), 0) AS motorina,
                COALESCE(SUM(CASE WHEN f.fuel_type = 'adblue' THEN f.quantity_liters ELSE 0 END), 0) AS adblue,
                COALESCE(SUM(f.total_value), 0) AS total_value
            FROM fuel_fillups f
            " . $where['where'] . "
            GROUP BY vehicle_key
        ");
        $this->bindParams($stmt, $where['params']);
        $stmt->execute();
        $vehicles = [];
        foreach ($stmt->fetchAll() as $row) {
            $vehicles[(string) $row['vehicle_key']] = $row;
        }

        if ($vehicles === []) {
            return [];
        }

        $odometerByVehicle = [];
        foreach ($this->getOdometerConsumptionRows($filters) as $row) {
            $key = str_replace(' ', '', strtoupper((string) ($row['vehicle_registration'] ?? '')));
            if ($key === '') {
                continue;
            }
            $odometerByVehicle[$key]['km'] = ($odometerByVehicle[$key]['km'] ?? 0.0) + (float) ($row['km'] ?? 0);
            $odometerByVehicle[$key]['liters'] = ($odometerByVehicle[$key]['liters'] ?? 0.0) + (float) ($row['quantity_liters'] ?? 0);
        }

        $tripKmByVehicle = [];
        $tripFilters = $filters;
        if (($tripFilters['fuel_type'] ?? '') !== 'adblue') {
            $tripFilters['fuel_type'] = 'motorina';
            $tripWhere = $this->buildFillupWhere($tripFilters, 'veh_km', true);
            $tripStmt = $this->db->prepare("
                SELECT vehicle_key, COALESCE(SUM(trip_km), 0) AS km
                FROM (
                    SELECT
                        REPLACE(UPPER(f.vehicle_registration), ' ', '') AS vehicle_key,
                        c.id,
                        MAX(" . $this->effectiveKmExpr('c') . ") AS trip_km
                    FROM fuel_fillups f
                    INNER JOIN fuel_trip_links l ON l.fillup_id = f.id
                    INNER JOIN curse_dispecer c ON c.id = l.trip_id
                    " . $tripWhere['where'] . "
                    GROUP BY vehicle_key, c.id
                ) linked_trips
                GROUP BY vehicle_key
            ");
            $this->bindParams($tripStmt, $tripWhere['params']);
            $tripStmt->execute();
            foreach ($tripStmt->fetchAll() as $row) {
                $tripKmByVehicle[(string) $row['vehicle_key']] = (float) ($row['km'] ?? 0);
            }
        }

        $rows = [];
        foreach ($vehicles as $key => $vehicle) {
            $motorina = (float) ($vehicle['motorina'] ?? 0);
            $adblue = (float) ($vehicle['adblue'] ?? 0);
            $totalValue = (float) ($vehicle['total_value'] ?? 0);

            $km = (float) ($odometerByVehicle[$key]['km'] ?? 0);
            $litersForAverage = (float) ($odometerByVehicle[$key]['liters'] ?? 0);
            $kmSource = 'alimentari';
            if ($km <= 0.0 || $litersForAverage <= 0.0) {
                $km = (float) ($tripKmByVehicle[$key] ?? 0);
                $litersForAverage = $motorina;
                $kmSource = $km > 0.0 ? 'dispecer' : '';
            }

            $rows[] = [
                'vehicle_registration' => (string) ($vehicle['vehicle_registration'] ?? ''),
                'fillup_count' => (int) ($vehicle['fillup_count'] ?? 0),
                'motorina' => round($motorina, 2),
                'adblue' => round($adblue, 2),
                'total_value' => round($totalValue, 2),
                'km' => round($km, 2),
                'km_source' => $kmSource,
                'consum_motorina' => $km > 0 && $litersForAverage > 0 ? round(($litersForAverage / $km) * 100, 2) : 0.0,
                'consum_adblue' => $motorina > 0 ? round(($adblue / $motorina) * 100, 2) : 0.0,
                'cost_per_km' => $km > 0 ? round($totalValue / $km, 2) : 0.0,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            if ($a['consum_motorina'] === $b['consum_motorina']) {
                return $b['motorina'] <=> $a['motorina'];
            }
            if ($a['consum_motorina'] <= 0.0) {
                return 1;
            }
            if ($b['consum_motorina'] <= 0.0) {
                return -1;
            }

            return $b['consum_motorina'] <=> $a['consum_motorina'];
        });

        return $rows;
    }

    public function getVehicleDailyCharts(array $filters, int $maxVehicles = 6): array
    {
        $this->ensureSchema();

        $vehicles = array_slice($this->selectedVehicles($filters), 0, max(2, $maxVehicles));
        if (count($vehicles) < 2) {
            return [];
        }

        $charts = [];
        foreach ($vehicles as $vehicle) {
            $vehicleFilters = $filters;
            $vehicleFilters['vehicle'] = $vehicle;
            $vehicleFilters['vehicles'] = [$vehicle];
            $summary = $this->getKpiSummary($vehicleFilters);
            $charts[] = [
                'vehicle' => $vehicle,
                'average' => (float) ($summary['motorina_avg_l100'] ?? 0),
                'chart' => $this->getDailyConsumptionChart($vehicleFilters, (float) ($summary['motorina_avg_l100'] ?? 0)),
            ];
        }

        return $charts;
    }

    private function selectedVehicles(array $filters): array
    {
        $vehicles = [];
        $input = isset($filters['vehicles']) && is_array($filters['vehicles']) ? $filters['vehicles'] : [];
        if ($input === [] && trim((string) ($filters['vehicle'] ?? '')) !== '') {
            $input = [(string) $filters['vehicle']];
        }

        foreach ($input as $vehicle) {
            $vehicle = trim((string) $vehicle);
            if ($vehicle !== '' && !in_array($vehicle, $vehicles, true)) {
                $vehicles[] = $vehicle;
            }
        }

        return $vehicles;
    }

    public function getComparisonData(array $filtersA, array $filtersB): array
    {
        $this->ensureSchema();

        $summaryA = $this->getKpiSummary($filtersA);
        $summaryB = $this->getKpiSummary($filtersB);

        return [
            'summary_a' => $summaryA,
            'summary_b' => $summaryB,
            'chart_a' => $this->getDailyConsumptionChart($filtersA, $summaryA['motorina_avg_l100']),
            'chart_b' => $this->getDailyConsumptionChart($filtersB, $summaryB['motorina_avg_l100']),
            'metrics' => $this->buildComparisonMetrics($summaryA, $summaryB),
        ];
    }

    private function buildComparisonMetrics(array $summaryA, array $summaryB): array
    {
        $costPerKm = static function (array $summary): float {
            $km = (float) ($summary['linked_km'] ?? 0);
            return $km > 0 ? round(((float) ($summary['total_value'] ?? 0)) / $km, 2) : 0.0;
        };

        $definitions = [
            ['key' => 'motorina_liters', 'label' => 'Motorină consumată', 'unit' => 'L', 'decimals' => 2, 'better' => 'lower'],
            ['key' => 'adblue_liters', 'label' => 'AdBlue consumat', 'unit' => 'L', 'decimals' => 2, 'better' => 'lower'],
            ['key' => 'total_value', 'label' => 'Cost total carburant', 'unit' => 'lei', 'decimals' => 2, 'better' => 'lower'],
            ['key' => 'linked_km', 'label' => 'Km parcurși', 'unit' => 'km', 'decimals' => 0, 'better' => 'neutral'],
            ['key' => 'motorina_avg_l100', 'label' => 'Consum mediu Motorină', 'unit' => 'L/100 km', 'decimals' => 2, 'better' => 'lower'],
            ['key' => 'adblue_percent', 'label' => 'Consum mediu AdBlue', 'unit' => '%', 'decimals' => 2, 'better' => 'lower'],
            ['key' => 'cost_per_km', 'label' => 'Cost pe km', 'unit' => 'lei/km', 'decimals' => 2, 'better' => 'lower'],
        ];

        $summaryA['cost_per_km'] = $costPerKm($summaryA);
        $summaryB['cost_per_km'] = $costPerKm($summaryB);

        $metrics = [];
        foreach ($definitions as $definition) {
            $valueA = (float) ($summaryA[$definition['key']] ?? 0);
            $valueB = (float) ($summaryB[$definition['key']] ?? 0);
            $metrics[] = [
                'key' => $definition['key'],
                'label' => $definition['label'],
                'unit' => $definition['unit'],
                'decimals' => $definition['decimals'],
                'better' => $definition['better'],
                'value_a' => $valueA,
                'value_b' => $valueB,
                'delta' => round($valueA - $valueB, $definition['decimals'] > 0 ? $definition['decimals'] : 2),
                'percent' => $this->percentageChange($valueA, $valueB),
            ];
        }

        return $metrics;
    }

    public function getFillups(array $filters, int $limit = 200): array
    {
        $where = $this->buildFillupWhere($filters, 'fillups', true);
        $limit = max(1, min(500, $limit));
        $sql = "
            SELECT
                f.*,
                l.trip_id,
                l.match_type,
                c.tip_transport,
                c.data_inceput,
                c.data_sfarsit,
                c.ora_inceput,
                c.ora_sfarsit,
                " . $this->effectiveKmExpr('c') . " AS trip_km
            FROM fuel_fillups f
            LEFT JOIN fuel_trip_links l ON l.fillup_id = f.id
            LEFT JOIN curse_dispecer c ON c.id = l.trip_id
            " . $where['where'] . "
            ORDER BY f.fillup_datetime DESC, f.id DESC
            LIMIT :limit_rows
        ";

        $stmt = $this->db->prepare($sql);
        $this->bindParams($stmt, $where['params']);
        $stmt->bindValue(':limit_rows', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getUnassociatedFillups(array $filters, int $limit = 100): array
    {
        $where = $this->buildFillupWhere($filters, 'unassoc', false);
        $limit = max(1, min(300, $limit));

        $sql = "
            SELECT f.*
            FROM fuel_fillups f
            LEFT JOIN fuel_trip_links l ON l.fillup_id = f.id
            " . $where['where'] . "
              AND l.id IS NULL
            ORDER BY f.fillup_datetime DESC, f.id DESC
            LIMIT :limit_rows
        ";

        $stmt = $this->db->prepare($sql);
        $this->bindParams($stmt, $where['params']);
        $stmt->bindValue(':limit_rows', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getSyncLogs(int $limit = 10): array
    {
        $this->ensureSchema();
        $stmt = $this->db->prepare("
            SELECT *
            FROM fuel_sync_logs
            ORDER BY sync_started_at DESC, id DESC
            LIMIT :limit_rows
        ");
        $stmt->bindValue(':limit_rows', max(1, min(100, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getLastSyncLog(): ?array
    {
        $this->ensureSchema();
        $stmt = $this->db->query("
            SELECT *
            FROM fuel_sync_logs
            ORDER BY sync_started_at DESC, id DESC
            LIMIT 1
        ");
        $row = $stmt->fetch();

        return $row ?: null;
    }

    private function getKpiSummary(array $filters): array
    {
        $where = $this->buildFillupWhere($filters, 'kpi', true);
        $stmt = $this->db->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN f.fuel_type = 'motorina' THEN f.quantity_liters ELSE 0 END), 0) AS motorina_liters,
                COALESCE(SUM(CASE WHEN f.fuel_type = 'adblue' THEN f.quantity_liters ELSE 0 END), 0) AS adblue_liters,
                COALESCE(SUM(f.total_value), 0) AS total_value
            FROM fuel_fillups f
            LEFT JOIN fuel_trip_links l ON l.fillup_id = f.id
            LEFT JOIN curse_dispecer c ON c.id = l.trip_id
            " . $where['where'] . "
        ");
        $this->bindParams($stmt, $where['params']);
        $stmt->execute();
        $row = $stmt->fetch() ?: [];

        $motorina = (float) ($row['motorina_liters'] ?? 0);
        $adblue = (float) ($row['adblue_liters'] ?? 0);
        $odometerSummary = $this->getOdometerConsumptionSummary($filters);
        $linkedFuel = $this->getLinkedFuelTotals($filters);
        $linkedMotorina = (float) ($linkedFuel['motorina'] ?? 0);
        $km = (float) ($odometerSummary['km'] ?? 0);
        $motorinaForAverage = (float) ($odometerSummary['motorina'] ?? 0);
        $kmSource = $km > 0.0 ? 'alimentari' : '';

        if ($km <= 0.0 || $motorinaForAverage <= 0.0) {
            $km = $this->getDistinctLinkedKm($filters);
            $motorinaForAverage = $linkedMotorina;
            $kmSource = $km > 0.0 ? 'dispecer' : '';
        }

        return [
            'motorina_liters' => round($motorina, 2),
            'adblue_liters' => round($adblue, 2),
            'motorina_avg_l100' => $km > 0 ? round(($motorinaForAverage / $km) * 100, 2) : 0.0,
            'adblue_percent' => $motorina > 0 ? round(($adblue / $motorina) * 100, 2) : 0.0,
            'total_value' => round((float) ($row['total_value'] ?? 0), 2),
            'linked_km' => round($km, 2),
            'consumption_liters' => round($motorinaForAverage, 2),
            'consumption_km_source' => $kmSource,
            'changes' => [],
        ];
    }

    private function getLinkedFuelTotals(array $filters): array
    {
        $where = $this->buildFillupWhere($filters, 'linked_fuel', true);
        $stmt = $this->db->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN f.fuel_type = 'motorina' THEN f.quantity_liters ELSE 0 END), 0) AS motorina,
                COALESCE(SUM(CASE WHEN f.fuel_type = 'adblue' THEN f.quantity_liters ELSE 0 END), 0) AS adblue
            FROM fuel_fillups f
            INNER JOIN fuel_trip_links l ON l.fillup_id = f.id
            INNER JOIN curse_dispecer c ON c.id = l.trip_id
            " . $where['where'] . "
        ");
        $this->bindParams($stmt, $where['params']);
        $stmt->execute();

        return $stmt->fetch() ?: ['motorina' => 0, 'adblue' => 0];
    }

    private function getDistinctLinkedKm(array $filters): float
    {
        $kmFilters = $filters;
        if (($kmFilters['fuel_type'] ?? '') === 'adblue') {
            return 0.0;
        }
        $kmFilters['fuel_type'] = 'motorina';
        $where = $this->buildFillupWhere($kmFilters, 'km', true);

        $sql = "
            SELECT COALESCE(SUM(trip_km), 0)
            FROM (
                SELECT c.id, MAX(" . $this->effectiveKmExpr('c') . ") AS trip_km
                FROM fuel_fillups f
                INNER JOIN fuel_trip_links l ON l.fillup_id = f.id
                INNER JOIN curse_dispecer c ON c.id = l.trip_id
                " . $where['where'] . "
                GROUP BY c.id
            ) linked_trips
        ";

        $stmt = $this->db->prepare($sql);
        $this->bindParams($stmt, $where['params']);
        $stmt->execute();

        return max(0.0, (float) $stmt->fetchColumn());
    }

    private function getOdometerConsumptionSummary(array $filters): array
    {
        $rows = $this->getOdometerConsumptionRows($filters);
        $motorina = 0.0;
        $km = 0.0;

        foreach ($rows as $row) {
            $motorina += (float) ($row['quantity_liters'] ?? 0);
            $km += (float) ($row['km'] ?? 0);
        }

        return [
            'motorina' => $motorina,
            'km' => $km,
            'intervals' => count($rows),
        ];
    }

    private function getOdometerConsumptionRows(array $filters): array
    {
        if (!$this->canUseOdometerConsumption($filters)) {
            return [];
        }

        $where = $this->buildFillupWhere($filters, 'odo', false);
        $stmt = $this->db->prepare("
            SELECT
                f.id,
                f.vehicle_registration,
                f.fillup_datetime,
                DATE(f.fillup_datetime) AS day_key,
                f.quantity_liters,
                f.odometer_km,
                (
                    SELECT fp.odometer_km
                    FROM fuel_fillups fp
                    WHERE REPLACE(UPPER(fp.vehicle_registration), ' ', '') = REPLACE(UPPER(f.vehicle_registration), ' ', '')
                      AND fp.fuel_type = 'motorina'
                      AND fp.odometer_km IS NOT NULL
                      AND fp.odometer_km > 0
                      AND (
                          fp.fillup_datetime < f.fillup_datetime
                          OR (fp.fillup_datetime = f.fillup_datetime AND fp.id < f.id)
                      )
                    ORDER BY fp.fillup_datetime DESC, fp.id DESC
                    LIMIT 1
                ) AS previous_odometer_km
            FROM fuel_fillups f
            " . $where['where'] . "
              AND f.fuel_type = 'motorina'
              AND f.odometer_km IS NOT NULL
              AND f.odometer_km > 0
            ORDER BY f.vehicle_registration ASC, f.fillup_datetime ASC, f.id ASC
        ");
        $this->bindParams($stmt, $where['params']);
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $currentKm = (float) ($row['odometer_km'] ?? 0);
            $previousKm = (float) ($row['previous_odometer_km'] ?? 0);
            $intervalKm = $currentKm - $previousKm;
            if ($previousKm <= 0.0 || $intervalKm <= 0.0) {
                continue;
            }

            $row['km'] = $intervalKm;
            $rows[] = $row;
        }

        return $rows;
    }

    private function canUseOdometerConsumption(array $filters): bool
    {
        if (($filters['fuel_type'] ?? '') === 'adblue') {
            return false;
        }

        $transportGroup = trim((string) ($filters['transport_group'] ?? ''));
        if ($transportGroup !== '' && isset(self::TRANSPORT_GROUPS[$transportGroup])) {
            return false;
        }

        return true;
    }

    private function getDailyConsumptionChart(array $filters, float $periodAverage): array
    {
        if (($filters['fuel_type'] ?? '') === 'adblue') {
            return ['points' => $this->emptyDailyPoints($filters), 'average' => 0.0];
        }

        $odometerRows = $this->getOdometerConsumptionRows($filters);
        if ($odometerRows !== []) {
            $litersByDay = [];
            $kmByDay = [];
            foreach ($odometerRows as $row) {
                $dayKey = (string) ($row['day_key'] ?? '');
                if ($dayKey === '') {
                    continue;
                }

                $litersByDay[$dayKey] = ($litersByDay[$dayKey] ?? 0.0) + (float) ($row['quantity_liters'] ?? 0);
                $kmByDay[$dayKey] = ($kmByDay[$dayKey] ?? 0.0) + (float) ($row['km'] ?? 0);
            }

            $points = [];
            foreach ($this->dateKeys($filters['date_from'], $filters['date_to']) as $dateKey) {
                $liters = $litersByDay[$dateKey] ?? 0.0;
                $km = $kmByDay[$dateKey] ?? 0.0;
                $points[] = [
                    'date' => $dateKey,
                    'label' => (new DateTimeImmutable($dateKey))->format('d.m'),
                    'value' => $liters > 0 && $km > 0 ? round(($liters / $km) * 100, 2) : 0.0,
                    'liters' => round($liters, 2),
                    'km' => round($km, 2),
                ];
            }

            return ['points' => $points, 'average' => round($periodAverage, 2)];
        }

        $chartFilters = $filters;
        $chartFilters['fuel_type'] = 'motorina';
        $where = $this->buildFillupWhere($chartFilters, 'daily', true);

        $litersStmt = $this->db->prepare("
            SELECT DATE(f.fillup_datetime) AS day_key, COALESCE(SUM(f.quantity_liters), 0) AS liters
            FROM fuel_fillups f
            INNER JOIN fuel_trip_links l ON l.fillup_id = f.id
            INNER JOIN curse_dispecer c ON c.id = l.trip_id
            " . $where['where'] . "
            GROUP BY DATE(f.fillup_datetime)
        ");
        $this->bindParams($litersStmt, $where['params']);
        $litersStmt->execute();

        $litersByDay = [];
        foreach ($litersStmt->fetchAll() as $row) {
            $litersByDay[(string) $row['day_key']] = (float) $row['liters'];
        }

        $kmStmt = $this->db->prepare("
            SELECT day_key, COALESCE(SUM(trip_km), 0) AS km
            FROM (
                SELECT DATE(f.fillup_datetime) AS day_key, c.id, MAX(" . $this->effectiveKmExpr('c') . ") AS trip_km
                FROM fuel_fillups f
                INNER JOIN fuel_trip_links l ON l.fillup_id = f.id
                INNER JOIN curse_dispecer c ON c.id = l.trip_id
                " . $where['where'] . "
                GROUP BY DATE(f.fillup_datetime), c.id
            ) daily_trips
            GROUP BY day_key
        ");
        $this->bindParams($kmStmt, $where['params']);
        $kmStmt->execute();

        $kmByDay = [];
        foreach ($kmStmt->fetchAll() as $row) {
            $kmByDay[(string) $row['day_key']] = (float) $row['km'];
        }

        $points = [];
        foreach ($this->dateKeys($filters['date_from'], $filters['date_to']) as $dateKey) {
            $liters = $litersByDay[$dateKey] ?? 0.0;
            $km = $kmByDay[$dateKey] ?? 0.0;
            $value = $liters > 0 && $km > 0 ? round(($liters / $km) * 100, 2) : 0.0;
            $points[] = [
                'date' => $dateKey,
                'label' => (new DateTimeImmutable($dateKey))->format('d.m'),
                'value' => $value,
                'liters' => round($liters, 2),
                'km' => round($km, 2),
            ];
        }

        return ['points' => $points, 'average' => round($periodAverage, 2)];
    }

    private function getTransportBreakdown(array $filters): array
    {
        if (($filters['fuel_type'] ?? '') === 'adblue') {
            return ['items' => [], 'total' => 0.0];
        }

        $transportFilters = $filters;
        $transportFilters['fuel_type'] = 'motorina';
        $where = $this->buildFillupWhere($transportFilters, 'transport_chart', true);
        $groupExpr = $this->transportGroupSql('c.tip_transport');

        $stmt = $this->db->prepare("
            SELECT {$groupExpr} AS transport_group, COALESCE(SUM(f.quantity_liters), 0) AS liters
            FROM fuel_fillups f
            INNER JOIN fuel_trip_links l ON l.fillup_id = f.id
            INNER JOIN curse_dispecer c ON c.id = l.trip_id
            " . $where['where'] . "
            GROUP BY {$groupExpr}
            ORDER BY liters DESC
        ");
        $this->bindParams($stmt, $where['params']);
        $stmt->execute();

        $items = [];
        $total = 0.0;
        foreach ($stmt->fetchAll() as $row) {
            $key = (string) ($row['transport_group'] ?? 'neasociat');
            $liters = round((float) ($row['liters'] ?? 0), 2);
            $total += $liters;
            $items[] = [
                'key' => $key,
                'label' => self::TRANSPORT_LABELS[$key] ?? $key,
                'liters' => $liters,
                'percent' => 0.0,
            ];
        }

        foreach ($items as &$item) {
            $item['percent'] = $total > 0 ? round((((float) $item['liters']) / $total) * 100, 1) : 0.0;
        }
        unset($item);

        return ['items' => $items, 'total' => round($total, 2)];
    }

    private function getNormativeInterval(array $filters): array
    {
        $vehicle = trim((string) ($filters['vehicle'] ?? ''));
        if ($vehicle === '') {
            $vehicle = $this->firstVehicleForNormative($filters);
        }

        $monthStart = (new DateTimeImmutable((string) $filters['date_from']))->modify('first day of this month')->setTime(0, 0);
        $base = [
            'vehicle' => $vehicle,
            'month_start' => $monthStart->format('Y-m-d'),
            'status' => 'invalid',
            'message' => 'Lipsește full început lună',
            'start_full' => null,
            'next_full' => null,
            'km' => 0.0,
            'motorina_liters' => 0.0,
            'adblue_liters' => 0.0,
            'norm_l100' => 0.0,
            'adblue_percent' => 0.0,
            'km_source' => '',
        ];

        if ($vehicle === '') {
            $base['message'] = 'Nu exista vehicul pentru calcul.';
            return $base;
        }

        $startFull = $this->findStartFull($vehicle, $monthStart);
        if ($startFull === null) {
            return $base;
        }

        $nextFull = $this->findNextFull($vehicle, (string) $startFull['fillup_datetime'], (int) $startFull['id']);
        if ($nextFull === null) {
            $base['start_full'] = $startFull;
            $base['message'] = 'Minimum două alimentări FULL necesare';
            return $base;
        }

        $startDateTime = (string) $startFull['fillup_datetime'];
        $endDateTime = (string) $nextFull['fillup_datetime'];
        $km = $this->getOdometerKmBetweenFulls($startFull, $nextFull);
        $kmSource = 'alimentari';
        if ($km <= 0.0) {
            $km = $this->getTripKmForVehicleInterval($vehicle, $startDateTime, $endDateTime);
            $kmSource = 'dispecer';
        }
        $fuel = $this->getFuelForVehicleInterval($vehicle, $startDateTime, $endDateTime);
        $motorina = (float) ($fuel['motorina'] ?? 0);
        $adblue = (float) ($fuel['adblue'] ?? 0);
        $valid = $km > 0 && $motorina > 0;

        return [
            'vehicle' => $vehicle,
            'month_start' => $monthStart->format('Y-m-d'),
            'status' => $valid ? 'valid' : 'invalid',
            'message' => $valid ? 'Interval valid' : 'Interval invalid',
            'start_full' => $startFull,
            'next_full' => $nextFull,
            'km' => round($km, 2),
            'motorina_liters' => round($motorina, 2),
            'adblue_liters' => round($adblue, 2),
            'norm_l100' => $km > 0 ? round(($motorina / $km) * 100, 2) : 0.0,
            'adblue_percent' => $motorina > 0 ? round(($adblue / $motorina) * 100, 2) : 0.0,
            'km_source' => $kmSource,
        ];
    }

    private function getConsumptionByTrip(array $filters): array
    {
        $where = $this->buildFillupWhere($filters, 'trip_consumption', true);
        $kmExpr = $this->effectiveKmExpr('c');
        $sql = "
            SELECT
                c.id AS trip_id,
                f.vehicle_registration,
                c.tip_transport,
                c.data_inceput,
                c.data_sfarsit,
                {$kmExpr} AS km,
                COALESCE(SUM(CASE WHEN f.fuel_type = 'motorina' THEN f.quantity_liters ELSE 0 END), 0) AS motorina,
                COALESCE(SUM(CASE WHEN f.fuel_type = 'adblue' THEN f.quantity_liters ELSE 0 END), 0) AS adblue,
                COALESCE(SUM(f.total_value), 0) AS total_value
            FROM fuel_fillups f
            INNER JOIN fuel_trip_links l ON l.fillup_id = f.id
            INNER JOIN curse_dispecer c ON c.id = l.trip_id
            " . $where['where'] . "
            GROUP BY c.id, f.vehicle_registration, c.tip_transport, c.data_inceput, c.data_sfarsit, km
            ORDER BY c.data_inceput DESC, c.id DESC
            LIMIT 200
        ";

        $stmt = $this->db->prepare($sql);
        $this->bindParams($stmt, $where['params']);
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $motorina = (float) ($row['motorina'] ?? 0);
            $adblue = (float) ($row['adblue'] ?? 0);
            $km = (float) ($row['km'] ?? 0);
            $row['consum_motorina'] = $km > 0 ? round(($motorina / $km) * 100, 2) : 0.0;
            $row['consum_adblue'] = $motorina > 0 ? round(($adblue / $motorina) * 100, 2) : 0.0;
            $rows[] = $row;
        }

        return $rows;
    }

    private function getConsumptionByTransport(array $filters): array
    {
        $where = $this->buildFillupWhere($filters, 'transport_consumption', true);
        $groupExpr = $this->transportGroupSql('c.tip_transport');
        $kmExpr = $this->effectiveKmExpr('c');

        $sql = "
            SELECT
                transport_group,
                COALESCE(SUM(motorina), 0) AS motorina,
                COALESCE(SUM(adblue), 0) AS adblue,
                COALESCE(SUM(total_value), 0) AS total_value,
                COALESCE(SUM(km), 0) AS km
            FROM (
                SELECT
                    c.id,
                    {$groupExpr} AS transport_group,
                    COALESCE(SUM(CASE WHEN f.fuel_type = 'motorina' THEN f.quantity_liters ELSE 0 END), 0) AS motorina,
                    COALESCE(SUM(CASE WHEN f.fuel_type = 'adblue' THEN f.quantity_liters ELSE 0 END), 0) AS adblue,
                    COALESCE(SUM(f.total_value), 0) AS total_value,
                    MAX({$kmExpr}) AS km
                FROM fuel_fillups f
                INNER JOIN fuel_trip_links l ON l.fillup_id = f.id
                INNER JOIN curse_dispecer c ON c.id = l.trip_id
                " . $where['where'] . "
                GROUP BY c.id, transport_group
            ) trips
            GROUP BY transport_group
            ORDER BY motorina DESC
        ";

        $stmt = $this->db->prepare($sql);
        $this->bindParams($stmt, $where['params']);
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $motorina = (float) ($row['motorina'] ?? 0);
            $adblue = (float) ($row['adblue'] ?? 0);
            $km = (float) ($row['km'] ?? 0);
            $key = (string) ($row['transport_group'] ?? 'neasociat');
            $row['label'] = self::TRANSPORT_LABELS[$key] ?? $key;
            $row['consum_motorina'] = $km > 0 ? round(($motorina / $km) * 100, 2) : 0.0;
            $row['consum_adblue'] = $motorina > 0 ? round(($adblue / $motorina) * 100, 2) : 0.0;
            $rows[] = $row;
        }

        return $rows;
    }

    private function getTripOptions(array $filters): array
    {
        $dateFrom = (new DateTimeImmutable((string) $filters['date_from']))->modify('-7 days')->format('Y-m-d 00:00:00');
        $dateTo = (new DateTimeImmutable((string) $filters['date_to']))->modify('+7 days')->format('Y-m-d 23:59:59');
        $where = [
            $this->activeRaceCondition('c'),
            $this->tripIntervalEndExpr('c') . ' >= :date_from',
            $this->tripIntervalStartExpr('c') . ' <= :date_to',
        ];
        $params = [
            ':date_from' => $dateFrom,
            ':date_to' => $dateTo,
        ];

        if (trim((string) ($filters['vehicle'] ?? '')) !== '') {
            $where[] = "REPLACE(UPPER(v.nr_inmatriculare), ' ', '') = REPLACE(UPPER(:vehicle), ' ', '')";
            $params[':vehicle'] = (string) $filters['vehicle'];
        }

        $sql = "
            SELECT
                c.id,
                v.nr_inmatriculare,
                c.tip_transport,
                c.data_inceput,
                c.data_sfarsit,
                c.ora_inceput,
                c.ora_sfarsit,
                " . $this->effectiveKmExpr('c') . " AS km
            FROM curse_dispecer c
            INNER JOIN vehicule v ON v.id = c.vehicle_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY c.data_inceput DESC, c.id DESC
            LIMIT 500
        ";

        $stmt = $this->db->prepare($sql);
        $this->bindParams($stmt, $params);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    private function findMatchingTrip(array $fillup): ?array
    {
        $registration = $this->normalizeRegistration((string) ($fillup['vehicle_registration'] ?? ''));
        $datetime = (string) ($fillup['fillup_datetime'] ?? '');
        if ($registration === '' || $datetime === '') {
            return null;
        }

        $startExpr = $this->tripIntervalStartExpr('c');
        $endExpr = $this->tripIntervalEndExpr('c');
        $stmt = $this->db->prepare("
            SELECT
                c.id,
                0.95 AS confidence,
                ABS(TIMESTAMPDIFF(SECOND, {$startExpr}, :fillup_datetime_order)) AS start_distance,
                TIMESTAMPDIFF(SECOND, {$startExpr}, {$endExpr}) AS interval_seconds
            FROM curse_dispecer c
            INNER JOIN vehicule v ON v.id = c.vehicle_id
            WHERE REPLACE(UPPER(v.nr_inmatriculare), ' ', '') = REPLACE(UPPER(:registration), ' ', '')
              AND " . $this->activeRaceCondition('c') . "
              AND :fillup_datetime BETWEEN {$startExpr} AND {$endExpr}
            ORDER BY interval_seconds ASC, start_distance ASC, c.id DESC
            LIMIT 1
        ");
        $stmt->bindValue(':registration', $registration);
        $stmt->bindValue(':fillup_datetime', $datetime);
        $stmt->bindValue(':fillup_datetime_order', $datetime);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row ?: null;
    }

    private function findStartFull(string $vehicle, DateTimeImmutable $monthStart): ?array
    {
        $windowStart = $monthStart->modify('-3 days')->format('Y-m-d 00:00:00');
        $windowEnd = $monthStart->modify('+3 days')->format('Y-m-d 23:59:59');
        $stmt = $this->db->prepare("
            SELECT *
            FROM fuel_fillups
            WHERE REPLACE(UPPER(vehicle_registration), ' ', '') = REPLACE(UPPER(:vehicle), ' ', '')
              AND fuel_type = 'motorina'
              AND is_full = 1
              AND fillup_datetime BETWEEN :window_start AND :window_end
            ORDER BY
                ABS(TIMESTAMPDIFF(SECOND, fillup_datetime, :month_start)) ASC,
                CASE WHEN fillup_datetime >= :month_start_after THEN 0 ELSE 1 END ASC,
                fillup_datetime ASC,
                id ASC
            LIMIT 1
        ");
        $stmt->bindValue(':vehicle', $vehicle);
        $stmt->bindValue(':window_start', $windowStart);
        $stmt->bindValue(':window_end', $windowEnd);
        $stmt->bindValue(':month_start', $monthStart->format('Y-m-d 00:00:00'));
        $stmt->bindValue(':month_start_after', $monthStart->format('Y-m-d 00:00:00'));
        $stmt->execute();
        $row = $stmt->fetch();

        return $row ?: null;
    }

    private function findNextFull(string $vehicle, string $afterDateTime, int $excludeId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT *
            FROM fuel_fillups
            WHERE REPLACE(UPPER(vehicle_registration), ' ', '') = REPLACE(UPPER(:vehicle), ' ', '')
              AND fuel_type = 'motorina'
              AND is_full = 1
              AND fillup_datetime > :after_datetime
              AND id <> :exclude_id
            ORDER BY fillup_datetime ASC, id ASC
            LIMIT 1
        ");
        $stmt->bindValue(':vehicle', $vehicle);
        $stmt->bindValue(':after_datetime', $afterDateTime);
        $stmt->bindValue(':exclude_id', $excludeId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row ?: null;
    }

    private function getOdometerKmBetweenFulls(array $startFull, array $nextFull): float
    {
        $startKm = (float) ($startFull['odometer_km'] ?? 0);
        $nextKm = (float) ($nextFull['odometer_km'] ?? 0);
        if ($startKm <= 0.0 || $nextKm <= 0.0 || $nextKm <= $startKm) {
            return 0.0;
        }

        return $nextKm - $startKm;
    }

    private function getTripKmForVehicleInterval(string $vehicle, string $startDateTime, string $endDateTime): float
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(" . $this->effectiveKmExpr('c') . "), 0)
            FROM curse_dispecer c
            INNER JOIN vehicule v ON v.id = c.vehicle_id
            WHERE REPLACE(UPPER(v.nr_inmatriculare), ' ', '') = REPLACE(UPPER(:vehicle), ' ', '')
              AND " . $this->activeRaceCondition('c') . "
              AND " . $this->tripIntervalStartExpr('c') . " >= :start_datetime
              AND " . $this->tripIntervalStartExpr('c') . " <= :end_datetime
        ");
        $stmt->bindValue(':vehicle', $vehicle);
        $stmt->bindValue(':start_datetime', $startDateTime);
        $stmt->bindValue(':end_datetime', $endDateTime);
        $stmt->execute();

        return max(0.0, (float) $stmt->fetchColumn());
    }

    private function getFuelForVehicleInterval(string $vehicle, string $startDateTime, string $endDateTime): array
    {
        $stmt = $this->db->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN fuel_type = 'motorina' THEN quantity_liters ELSE 0 END), 0) AS motorina,
                COALESCE(SUM(CASE WHEN fuel_type = 'adblue' THEN quantity_liters ELSE 0 END), 0) AS adblue
            FROM fuel_fillups
            WHERE REPLACE(UPPER(vehicle_registration), ' ', '') = REPLACE(UPPER(:vehicle), ' ', '')
              AND fillup_datetime > :start_datetime
              AND fillup_datetime <= :end_datetime
        ");
        $stmt->bindValue(':vehicle', $vehicle);
        $stmt->bindValue(':start_datetime', $startDateTime);
        $stmt->bindValue(':end_datetime', $endDateTime);
        $stmt->execute();

        return $stmt->fetch() ?: ['motorina' => 0, 'adblue' => 0];
    }

    private function firstVehicleForNormative(array $filters): string
    {
        $where = $this->buildFillupWhere($filters, 'norm_vehicle', false);
        $stmt = $this->db->prepare("
            SELECT vehicle_registration
            FROM fuel_fillups f
            " . $where['where'] . "
              AND f.fuel_type = 'motorina'
            GROUP BY vehicle_registration
            ORDER BY SUM(f.quantity_liters) DESC, vehicle_registration ASC
            LIMIT 1
        ");
        $this->bindParams($stmt, $where['params']);
        $stmt->execute();

        return trim((string) $stmt->fetchColumn());
    }

    private function buildFillupWhere(array $filters, string $prefix, bool $includeTransport): array
    {
        $where = [
            "f.fillup_datetime >= :{$prefix}_date_from",
            "f.fillup_datetime <= :{$prefix}_date_to",
        ];
        $params = [
            ":{$prefix}_date_from" => (string) $filters['date_from'] . ' 00:00:00',
            ":{$prefix}_date_to" => (string) $filters['date_to'] . ' 23:59:59',
        ];

        $vehicles = [];
        if (isset($filters['vehicles']) && is_array($filters['vehicles'])) {
            foreach ($filters['vehicles'] as $vehicleValue) {
                $vehicleValue = trim((string) $vehicleValue);
                if ($vehicleValue !== '' && !in_array($vehicleValue, $vehicles, true)) {
                    $vehicles[] = $vehicleValue;
                }
            }
        }
        if ($vehicles === []) {
            $vehicle = trim((string) ($filters['vehicle'] ?? ''));
            if ($vehicle !== '') {
                $vehicles = [$vehicle];
            }
        }
        if ($vehicles !== []) {
            $placeholders = [];
            foreach ($vehicles as $index => $vehicleValue) {
                $placeholder = ":{$prefix}_vehicle_{$index}";
                $placeholders[] = "REPLACE(UPPER({$placeholder}), ' ', '')";
                $params[$placeholder] = $vehicleValue;
            }
            $where[] = "REPLACE(UPPER(f.vehicle_registration), ' ', '') IN (" . implode(', ', $placeholders) . ")";
        }

        $fuelType = trim((string) ($filters['fuel_type'] ?? ''));
        if (in_array($fuelType, ['motorina', 'adblue'], true)) {
            $where[] = "f.fuel_type = :{$prefix}_fuel_type";
            $params[":{$prefix}_fuel_type"] = $fuelType;
        }

        $transportGroup = trim((string) ($filters['transport_group'] ?? ''));
        if ($includeTransport) {
            $where[] = $this->activeRaceCondition('c');
        }

        if ($includeTransport && isset(self::TRANSPORT_GROUPS[$transportGroup])) {
            $placeholders = [];
            foreach (self::TRANSPORT_GROUPS[$transportGroup] as $index => $transportType) {
                $placeholder = ":{$prefix}_transport_{$index}";
                $placeholders[] = $placeholder;
                $params[$placeholder] = $transportType;
            }
            $where[] = 'c.tip_transport IN (' . implode(', ', $placeholders) . ')';
        }

        return [
            'where' => 'WHERE ' . implode(' AND ', $where),
            'params' => $params,
        ];
    }

    private function previousPeriodFilters(array $filters): array
    {
        $dateFrom = new DateTimeImmutable((string) $filters['date_from']);
        $dateTo = new DateTimeImmutable((string) $filters['date_to']);
        $days = max(1, ((int) $dateFrom->diff($dateTo)->format('%a')) + 1);

        $previousTo = $dateFrom->modify('-1 day');
        $previousFrom = $previousTo->modify('-' . ($days - 1) . ' days');

        $previous = $filters;
        $previous['date_from'] = $previousFrom->format('Y-m-d');
        $previous['date_to'] = $previousTo->format('Y-m-d');

        return $previous;
    }

    private function percentageChange(float $current, float $previous): ?float
    {
        if ($previous <= 0.0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    private function createSyncLog(string $startedAt, DateTimeInterface $dateFrom, DateTimeInterface $dateTo): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO fuel_sync_logs (
                sync_started_at,
                sync_finished_at,
                date_from,
                date_to,
                status,
                records_received,
                records_inserted,
                records_updated,
                error_message
            ) VALUES (
                :sync_started_at,
                NULL,
                :date_from,
                :date_to,
                'running',
                0,
                0,
                0,
                NULL
            )
        ");
        $stmt->bindValue(':sync_started_at', $startedAt);
        $stmt->bindValue(':date_from', $dateFrom->format('Y-m-d'));
        $stmt->bindValue(':date_to', $dateTo->format('Y-m-d'));
        $stmt->execute();

        return (int) $this->db->lastInsertId();
    }

    private function finishSyncLog(int $logId, array $data): void
    {
        $stmt = $this->db->prepare("
            UPDATE fuel_sync_logs
            SET
                sync_finished_at = :sync_finished_at,
                status = :status,
                records_received = :records_received,
                records_inserted = :records_inserted,
                records_updated = :records_updated,
                error_message = :error_message
            WHERE id = :id
        ");
        $stmt->bindValue(':sync_finished_at', date('Y-m-d H:i:s'));
        $stmt->bindValue(':status', (string) ($data['status'] ?? 'success'));
        $stmt->bindValue(':records_received', (int) ($data['records_received'] ?? 0), PDO::PARAM_INT);
        $stmt->bindValue(':records_inserted', (int) ($data['records_inserted'] ?? 0), PDO::PARAM_INT);
        $stmt->bindValue(':records_updated', (int) ($data['records_updated'] ?? 0), PDO::PARAM_INT);
        $this->bindNullableString($stmt, ':error_message', isset($data['error_message']) ? (string) $data['error_message'] : null);
        $stmt->bindValue(':id', $logId, PDO::PARAM_INT);
        $stmt->execute();
    }

    private function storeSyncMeta(array $meta): void
    {
        if ($meta === []) {
            return;
        }

        $stmt = $this->db->prepare("
            INSERT INTO fuel_sync_state (state_key, state_value, updated_at)
            VALUES (:state_key, :state_value, :updated_at)
            ON DUPLICATE KEY UPDATE
                state_value = VALUES(state_value),
                updated_at = VALUES(updated_at)
        ");

        foreach ($meta as $key => $value) {
            $normalizedKey = 'cardoil_' . preg_replace('/[^a-z0-9_]/', '_', strtolower((string) $key));
            $stmt->bindValue(':state_key', $normalizedKey);
            $stmt->bindValue(':state_value', (string) $value);
            $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'));
            $stmt->execute();
        }
    }

    private function buildDemoRecords(DateTimeInterface $dateFrom, DateTimeInterface $dateTo): array
    {
        $vehicle = $this->firstVehicleRegistration($dateFrom, $dateTo) ?: '128 NET';
        $monthStart = (new DateTimeImmutable($dateFrom->format('Y-m-d')))->modify('first day of this month');
        $records = [
            [$monthStart->modify('+1 day')->setTime(7, 45), 'motorina', 1256.00, 6908.00, 'OMV Pitesti', 1],
            [$monthStart->modify('+2 days')->setTime(14, 18), 'adblue', 10.50, 52.50, 'Lukoil', 0],
            [$monthStart->modify('+3 days')->setTime(11, 22), 'motorina', 180.00, 990.00, 'Petrom', 0],
            [$monthStart->modify('+17 days')->setTime(6, 30), 'motorina', 1312.50, 7218.75, 'OMV Pitesti', 1],
            [$monthStart->modify('+19 days')->setTime(9, 20), 'motorina', 205.60, 1130.80, 'Petrom', 0],
            [$monthStart->modify('+20 days')->setTime(16, 5), 'adblue', 10.80, 54.00, 'OMV', 0],
            [$monthStart->modify('+24 days')->setTime(11, 35), 'motorina', 185.00, 1017.50, 'Rompetrol', 0],
        ];

        foreach ($this->demoTripFillups($vehicle, $dateFrom, $dateTo) as $record) {
            $records[] = $record;
        }

        $result = [];
        foreach ($records as $index => $record) {
            [$datetime, $fuelType, $quantity, $value, $station, $isFull] = $record;
            if (!$datetime instanceof DateTimeInterface) {
                continue;
            }
            if ($datetime < $dateFrom || $datetime > (new DateTimeImmutable($dateTo->format('Y-m-d')))->setTime(23, 59, 59)) {
                continue;
            }

            $vehicleKey = preg_replace('/[^A-Z0-9]/', '', strtoupper($vehicle));
            $fingerprint = substr(sha1(implode('|', [
                $vehicleKey,
                $datetime->format('Y-m-d H:i:s'),
                $fuelType,
                number_format((float) $quantity, 3, '.', ''),
                (string) $station,
            ])), 0, 12);

            $result[] = [
                'api_id' => 'demo-cardoil-' . $vehicleKey . '-' . $datetime->format('YmdHis') . '-' . $fuelType . '-' . $fingerprint,
                'vehicle_registration' => $vehicle,
                'fuel_type' => $fuelType,
                'quantity_liters' => $quantity,
                'total_value' => $value,
                'station_name' => $station,
                'fillup_datetime' => $datetime->format('Y-m-d H:i:s'),
                'is_full' => $isFull,
                'raw_payload' => ['source' => 'demo', 'sequence' => $index + 1],
            ];
        }

        return $result;
    }

    private function demoTripFillups(string $vehicle, DateTimeInterface $dateFrom, DateTimeInterface $dateTo): array
    {
        $startExpr = $this->tripIntervalStartExpr('c');
        $endExpr = $this->tripIntervalEndExpr('c');
        $stmt = $this->db->prepare("
            SELECT
                c.id,
                {$startExpr} AS interval_start,
                {$endExpr} AS interval_end
            FROM curse_dispecer c
            INNER JOIN vehicule v ON v.id = c.vehicle_id
            WHERE REPLACE(UPPER(v.nr_inmatriculare), ' ', '') = REPLACE(UPPER(:vehicle), ' ', '')
              AND " . $this->activeRaceCondition('c') . "
              AND c.data_sfarsit >= :date_from
              AND c.data_inceput <= :date_to
            ORDER BY c.data_inceput ASC, c.id ASC
            LIMIT 5
        ");
        $stmt->bindValue(':vehicle', $vehicle);
        $stmt->bindValue(':date_from', $dateFrom->format('Y-m-d'));
        $stmt->bindValue(':date_to', $dateTo->format('Y-m-d'));
        $stmt->execute();

        $records = [];
        $index = 0;
        foreach ($stmt->fetchAll() as $trip) {
            $start = new DateTimeImmutable((string) $trip['interval_start']);
            $end = new DateTimeImmutable((string) $trip['interval_end']);
            if ($end <= $start) {
                continue;
            }

            $middleTimestamp = (int) floor(($start->getTimestamp() + $end->getTimestamp()) / 2);
            $middle = (new DateTimeImmutable('@' . $middleTimestamp))->setTimezone(new DateTimeZone(date_default_timezone_get()));
            $index++;

            $records[] = [
                $middle,
                'motorina',
                34.00 + ($index * 4.5),
                187.00 + ($index * 24.75),
                $index % 2 === 0 ? 'Rompetrol' : 'OMV Pitesti',
                0,
            ];

            if ($index % 2 === 1) {
                $adblueTime = $middle->modify('+25 minutes');
                if ($adblueTime < $end) {
                    $records[] = [
                        $adblueTime,
                        'adblue',
                        9.80 + $index,
                        49.00 + ($index * 5),
                        'Lukoil',
                        0,
                    ];
                }
            }
        }

        return $records;
    }

    private function deleteDemoFillupsForPeriod(DateTimeInterface $dateFrom, DateTimeInterface $dateTo): void
    {
        $stmt = $this->db->prepare("
            DELETE FROM fuel_fillups
            WHERE api_id LIKE 'demo-cardoil-%'
              AND fillup_datetime >= :date_from
              AND fillup_datetime <= :date_to
        ");
        $stmt->bindValue(':date_from', $dateFrom->format('Y-m-d 00:00:00'));
        $stmt->bindValue(':date_to', $dateTo->format('Y-m-d 23:59:59'));
        $stmt->execute();
    }

    private function firstVehicleRegistration(?DateTimeInterface $dateFrom = null, ?DateTimeInterface $dateTo = null): string
    {
        try {
            if ($dateFrom !== null && $dateTo !== null) {
                $stmt = $this->db->prepare("
                    SELECT v.nr_inmatriculare
                    FROM curse_dispecer c
                    INNER JOIN vehicule v ON v.id = c.vehicle_id
                    WHERE c.data_sfarsit >= :date_from
                      AND c.data_inceput <= :date_to
                      AND " . $this->activeRaceCondition('c') . "
                      AND COALESCE(TRIM(v.nr_inmatriculare), '') <> ''
                    GROUP BY v.nr_inmatriculare
                    ORDER BY COUNT(c.id) DESC, v.nr_inmatriculare ASC
                    LIMIT 1
                ");
                $stmt->bindValue(':date_from', $dateFrom->format('Y-m-d'));
                $stmt->bindValue(':date_to', $dateTo->format('Y-m-d'));
                $stmt->execute();
                $plate = trim((string) $stmt->fetchColumn());
                if ($plate !== '') {
                    return $plate;
                }
            }

            $stmt = $this->db->query("
                SELECT nr_inmatriculare
                FROM vehicule
                WHERE COALESCE(TRIM(nr_inmatriculare), '') <> ''
                  AND tip_vehicul NOT IN ('semiremorca', 'semiremorca_primar', 'semiremorca_distributie')
                ORDER BY CASE WHEN status = 'activ' THEN 0 ELSE 1 END, nr_inmatriculare ASC
                LIMIT 1
            ");
            return trim((string) $stmt->fetchColumn());
        } catch (Throwable) {
            return '';
        }
    }

    private function shouldUseDemoData(): bool
    {
        $raw = strtolower(trim((string) (getenv('CARDOIL_DEMO_MODE') ?: 'off')));
        if (in_array($raw, ['0', 'false', 'off', 'nu', 'no'], true)) {
            return false;
        }
        if (in_array($raw, ['1', 'true', 'on', 'da', 'yes'], true)) {
            return true;
        }

        return false;
    }

    private function dateKeys(string $dateFrom, string $dateTo): array
    {
        $start = new DateTimeImmutable($dateFrom);
        $end = (new DateTimeImmutable($dateTo))->modify('+1 day');
        $period = new DatePeriod($start, new DateInterval('P1D'), $end);
        $keys = [];
        foreach ($period as $date) {
            $keys[] = $date->format('Y-m-d');
        }

        return $keys;
    }

    private function emptyDailyPoints(array $filters): array
    {
        $points = [];
        foreach ($this->dateKeys((string) $filters['date_from'], (string) $filters['date_to']) as $dateKey) {
            $points[] = [
                'date' => $dateKey,
                'label' => (new DateTimeImmutable($dateKey))->format('d.m'),
                'value' => 0.0,
                'liters' => 0.0,
                'km' => 0.0,
            ];
        }

        return $points;
    }

    private function effectiveKmExpr(string $alias): string
    {
        return "
            CASE
                WHEN {$alias}.tip_transport = 'compresor' AND COALESCE({$alias}.km_dislocare, 0) > 0 THEN COALESCE({$alias}.km_dislocare, 0)
                WHEN COALESCE({$alias}.km_totali, 0) > 0 THEN COALESCE({$alias}.km_totali, 0)
                WHEN COALESCE({$alias}.km_cursa, 0) > 0 THEN COALESCE({$alias}.km_cursa, 0)
                ELSE 0
            END
        ";
    }

    private function tripIntervalStartExpr(string $alias): string
    {
        return "CAST(CONCAT(COALESCE({$alias}.data_incarcare, {$alias}.data_inceput, {$alias}.data_cursa), ' ', COALESCE({$alias}.ora_inceput, '00:00:00')) AS DATETIME)";
    }

    private function tripIntervalEndExpr(string $alias): string
    {
        return "CAST(CONCAT(COALESCE({$alias}.data_sfarsit, {$alias}.data_inceput, {$alias}.data_cursa), ' ', COALESCE({$alias}.ora_sfarsit, '23:59:59')) AS DATETIME)";
    }

    private function transportGroupSql(string $column): string
    {
        return "
            CASE
                WHEN {$column} IN ('primar', 'primar_tona') THEN 'primar'
                WHEN {$column} = 'distributie' THEN 'distributie'
                WHEN {$column} = 'compresor' THEN 'compresor'
                WHEN {$column} = 'primar_distributie' THEN 'primar_distributie'
                ELSE 'neasociat'
            END
        ";
    }

    private function backfillDriverNamesFromRawPayload(): void
    {
        try {
            $this->db->exec("
                UPDATE fuel_fillups
                SET driver_name = NULLIF(TRIM(COALESCE(
                    JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.sofer_card')),
                    JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.driver_name')),
                    JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.nume_sofer')),
                    JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.sofer')),
                    JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.driver'))
                )), '')
                WHERE (driver_name IS NULL OR TRIM(driver_name) = '')
                  AND raw_payload IS NOT NULL
                  AND JSON_VALID(raw_payload)
                  AND COALESCE(
                    JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.sofer_card')),
                    JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.driver_name')),
                    JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.nume_sofer')),
                    JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.sofer')),
                    JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.driver'))
                  ) IS NOT NULL
            ");
        } catch (Throwable $exception) {
            error_log('[FuelModel][backfillDriverNamesFromRawPayload] ' . $exception->getMessage());
        }
    }

    private function bindParams(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
    }

    private function bindNullableString(PDOStatement $stmt, string $placeholder, ?string $value): void
    {
        if ($value === null || trim($value) === '') {
            $stmt->bindValue($placeholder, null, PDO::PARAM_NULL);
            return;
        }

        $stmt->bindValue($placeholder, $value);
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND COLUMN_NAME = :column_name
        ");
        $stmt->bindValue(':table_name', $table);
        $stmt->bindValue(':column_name', $column);
        $stmt->execute();

        return (int) $stmt->fetchColumn() > 0;
    }

    private function activeRaceCondition(string $alias = 'c'): string
    {
        if ($this->raceSoftDeleteAvailable === null) {
            $this->raceSoftDeleteAvailable = $this->columnExists('curse_dispecer', 'deleted_at');
        }

        return $this->raceSoftDeleteAvailable ? $alias . '.deleted_at IS NULL' : '1=1';
    }

    private function normalizeRegistration(string $registration): string
    {
        $registration = strtoupper(trim($registration));
        return preg_replace('/\s+/', ' ', $registration) ?: $registration;
    }
}
