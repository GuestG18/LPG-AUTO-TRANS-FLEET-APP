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

    /**
     * Limita documentata CardOil: maximum 1000 de inregistrari per cerere;
     * ce depaseste NU este returnat. O fereastra care atinge pragul este
     * considerata potential trunchiata si se imparte in doua.
     */
    private const API_MAX_RECORDS_PER_REQUEST = 1000;

    /** Limita documentata CardOil: o cerere acopera cel mult 31 de zile. */
    private const API_MAX_WINDOW_DAYS = 31;

    /**
     * Plaja de consum fizic posibil (L/100 km) pentru un interval intre doua
     * alimentari consecutive. In afara ei, kilometrajul e considerat tastat
     * gresit la pompa si intervalul este exclus din medii (randul ramane
     * vizibil in tabel, cu avertisment, si poate fi corectat manual).
     */
    private const MIN_PLAUSIBLE_L100 = 4.0;
    private const MAX_PLAUSIBLE_L100 = 120.0;

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

        // Modul "Administrare tarife transport": pretul unitar autoritar din CardOil
        // si provenienta randului. Ambele sunt aditive si nullable.
        if (!$this->columnExists('fuel_fillups', 'unit_price')) {
            $this->db->exec('ALTER TABLE fuel_fillups ADD COLUMN unit_price DECIMAL(12,4) NULL AFTER quantity_liters');
        }
        if (!$this->columnExists('fuel_fillups', 'unit_price_source')) {
            $this->db->exec("ALTER TABLE fuel_fillups ADD COLUMN unit_price_source ENUM('api','derived') NULL AFTER unit_price");
        }
        if (!$this->columnExists('fuel_fillups', 'source_type')) {
            $this->db->exec("ALTER TABLE fuel_fillups ADD COLUMN source_type ENUM('api','manual','test','demo') NOT NULL DEFAULT 'api' AFTER raw_payload");
        }

        // Mecanismul FULL / T0: decizia operatorului este pastrata separat de
        // valoarea efectiva folosita in calcule, ca sa nu poata fi suprascrisa
        // de sincronizarea CardOil (API-ul nu furnizeaza informatia de plin).
        //   is_full_manual: NULL = nicio decizie manuala, 1 = FULL, 0 = Partial
        //   full_source:    provenienta valorii curente din is_full
        if (!$this->columnExists('fuel_fillups', 'is_full_manual')) {
            $this->db->exec('ALTER TABLE fuel_fillups ADD COLUMN is_full_manual TINYINT(1) NULL DEFAULT NULL AFTER is_full');
            // Backfill non-distructiv: API-ul nu trimite niciodata is_full = 1,
            // deci orice FULL deja existent este o decizie manuala si trebuie
            // protejat de la primul sync de dupa deploy.
            $this->db->exec('UPDATE fuel_fillups SET is_full_manual = 1 WHERE is_full = 1 AND is_full_manual IS NULL');
        }
        if (!$this->columnExists('fuel_fillups', 'full_source')) {
            $this->db->exec("ALTER TABLE fuel_fillups ADD COLUMN full_source ENUM('api','manual') NULL DEFAULT NULL AFTER is_full_manual");
            $this->db->exec("UPDATE fuel_fillups SET full_source = 'manual' WHERE is_full_manual IS NOT NULL AND full_source IS NULL");
            $this->db->exec("UPDATE fuel_fillups SET full_source = 'api' WHERE full_source IS NULL");
        }

        // Corectia manuala a odometrului: soferii mai tasteaza gresit km la
        // pompa, iar valoarea vine asa din CardOil. Aceeasi arhitectura ca la
        // FULL: odometer_km ramane valoarea EFECTIVA folosita in calcule, iar
        // odometer_km_manual (NULL = fara corectie) marcheaza si protejeaza
        // decizia operatorului la sincronizarile urmatoare.
        if (!$this->columnExists('fuel_fillups', 'odometer_km_manual')) {
            $this->db->exec('ALTER TABLE fuel_fillups ADD COLUMN odometer_km_manual INT UNSIGNED NULL DEFAULT NULL AFTER odometer_km');
        }

        $this->backfillDriverNamesFromRawPayload();

        // T0 = ROLUL unei alimentari pentru o luna anume, distinct de
        // proprietatea is_full a alimentarii. Se persista exclusiv deciziile
        // manuale; selectia automata ramane calculata la runtime.
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS fuel_month_t0 (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                vehicle_key VARCHAR(40) NOT NULL,
                month_start DATE NOT NULL,
                fillup_id INT UNSIGNED NOT NULL,
                mode ENUM('manual') NOT NULL DEFAULT 'manual',
                note VARCHAR(255) NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uk_fuel_month_t0 (vehicle_key, month_start),
                INDEX idx_fuel_month_t0_fillup (fillup_id),
                CONSTRAINT fk_fuel_month_t0_fillup
                    FOREIGN KEY (fillup_id) REFERENCES fuel_fillups(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

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

        // Capacitatea, marca si modelul vin din fisa vehiculului (daca exista),
        // pentru gruparea pe tonaj din selectorul de vehicule.
        //
        // Capetele tractor nu au capacitate proprie: capacitatea lor este cea a
        // semiremorcii cuplate (vehicule_cuplaje, activ = 1). Semiremorcile nu
        // apar in selector — nu alimenteaza niciodata; lista din vehicule este
        // restransa la vehiculele care pot avea alimentari, dar orice numar
        // prezent in fuel_fillups ramane selectabil indiferent de tip.
        $stmt = $this->db->query("
            SELECT
                -- acelasi numar poate veni scris diferit din CardOil (fara
                -- spatii); afisam o singura optiune, cu scrierea din flota
                COALESCE(MAX(veh.nr_inmatriculare), MAX(vehicles.vehicle_registration)) AS vehicle_registration,
                MAX(
                    CASE
                        WHEN COALESCE(veh.capacitate_transport, 0) > 0 THEN veh.capacitate_transport
                        ELSE semi.capacitate_transport
                    END
                ) AS capacitate_transport,
                MAX(veh.marca) AS marca,
                MAX(veh.model) AS model
            FROM (
                SELECT DISTINCT TRIM(nr_inmatriculare) AS vehicle_registration
                FROM vehicule
                WHERE COALESCE(TRIM(nr_inmatriculare), '') <> ''
                  AND tip_vehicul NOT IN ('semiremorca', 'semiremorca_primar', 'semiremorca_distributie')
                UNION
                SELECT DISTINCT TRIM(vehicle_registration) AS vehicle_registration
                FROM fuel_fillups
                WHERE COALESCE(TRIM(vehicle_registration), '') <> ''
            ) vehicles
            LEFT JOIN vehicule veh
              ON REPLACE(UPPER(veh.nr_inmatriculare), ' ', '') = REPLACE(UPPER(vehicles.vehicle_registration), ' ', '')
            LEFT JOIN vehicule_cuplaje cuplaj
              ON cuplaj.tractor_id = veh.id
             AND cuplaj.activ = 1
            LEFT JOIN vehicule semi
              ON semi.id = cuplaj.semiremorca_id
            GROUP BY REPLACE(UPPER(vehicles.vehicle_registration), ' ', '')
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
            $this->refreshFuelTariffMonitoring();

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

    /**
     * Dupa un import CardOil reusit: completeaza preturile unitare exacte si
     * reevalueaza starea de monitorizare a tarifelor.
     *
     * NU modifica niciun tarif comercial — doar starea recomandarilor.
     */
    /**
     * Sincronizare pe interval oricat de lung, fara pierderi de date:
     *
     *  - intervalul este spart in ferestre de maximum 31 de zile (limita
     *    documentata a API-ului CardOil);
     *  - o fereastra al carei raspuns atinge 1000 de inregistrari (limita per
     *    cerere, restul NU se mai returneaza) este considerata trunchiata si
     *    se imparte in doua, recursiv, pana la nivel de o singura zi;
     *  - trunchierea se detecteaza si din meta `nr_inregistrari`, nu doar din
     *    numarul de randuri primite;
     *  - upsert-ul e idempotent pe api_id, deci suprapunerile si re-rularile
     *    sunt sigure; deciziile manuale (FULL, odometru) raman protejate.
     *
     * Nu injecteaza date demo: este calea de productie pentru aducerea la zi.
     */
    public function syncRangeFromApi(DateTimeInterface $dateFrom, DateTimeInterface $dateTo, CardOilApiClient $client): array
    {
        $this->ensureSchema();

        $from = (new DateTimeImmutable($dateFrom->format('Y-m-d')));
        $to = (new DateTimeImmutable($dateTo->format('Y-m-d')));
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        $logId = $this->createSyncLog(date('Y-m-d H:i:s'), $from, $to);

        // Ferestre initiale de maximum 31 de zile.
        $windows = [];
        $cursor = $from;
        while ($cursor <= $to) {
            $windowEnd = $cursor->modify('+' . (self::API_MAX_WINDOW_DAYS - 1) . ' days');
            if ($windowEnd > $to) {
                $windowEnd = $to;
            }
            $windows[] = [$cursor, $windowEnd];
            $cursor = $windowEnd->modify('+1 day');
        }

        $recordsByApiId = [];
        $warnings = [];
        $errors = [];
        $requests = 0;
        $splits = 0;
        $apiReportedTotal = 0;
        $lastMeta = [];

        while ($windows !== []) {
            [$windowFrom, $windowTo] = array_shift($windows);
            $windowDays = ((int) $windowFrom->diff($windowTo)->format('%a')) + 1;
            $windowLabel = $windowFrom->format('d.m.Y') . '-' . $windowTo->format('d.m.Y');

            try {
                $result = $client->fetchFillups($windowFrom, $windowTo);
                $requests++;
            } catch (Throwable $exception) {
                $errors[] = $windowLabel . ': ' . $exception->getMessage();
                continue;
            }

            $source = (string) ($result['source'] ?? 'api');
            if ($source !== 'api') {
                $errors[] = $windowLabel . ': ' . (string) ($result['error'] ?? ('sursa ' . $source));
                continue;
            }

            $records = (array) ($result['records'] ?? []);
            $meta = is_array($result['meta'] ?? null) ? $result['meta'] : [];
            $metaCount = (int) ($meta['nr_inregistrari'] ?? 0);
            $received = count($records);

            // Fereastra potential trunchiata: imparte si reia ambele jumatati.
            if (max($received, $metaCount) >= self::API_MAX_RECORDS_PER_REQUEST) {
                if ($windowDays > 1) {
                    $middle = $windowFrom->modify('+' . intdiv($windowDays, 2) . ' days')->modify('-1 day');
                    array_unshift($windows, [$middle->modify('+1 day'), $windowTo]);
                    array_unshift($windows, [$windowFrom, $middle]);
                    $splits++;
                    continue;
                }
                // O singura zi cu >= 1000 inregistrari nu mai poate fi sparta.
                $warnings[] = 'Ziua ' . $windowFrom->format('d.m.Y') . ' atinge limita API de '
                    . self::API_MAX_RECORDS_PER_REQUEST . ' inregistrari - posibile date lipsa.';
            }

            if ($metaCount > $received) {
                // Diferenta normala: produse non-carburant filtrate la import.
                $warnings[] = $windowLabel . ': API raporteaza ' . $metaCount . ', importabile ' . $received
                    . ' (' . ($metaCount - $received) . ' produse non-carburant ignorate).';
            }

            $apiReportedTotal += max($metaCount, $received);
            $lastMeta = $meta !== [] ? $meta : $lastMeta;

            foreach ($records as $record) {
                if (is_array($record) && !empty($record['api_id'])) {
                    $recordsByApiId[(string) $record['api_id']] = $record;
                }
            }
        }

        $upsert = $this->upsertFillups(array_values($recordsByApiId));
        $this->refreshAutomaticAssociations($from->format('Y-m-d'), $to->format('Y-m-d'));
        if ($lastMeta !== []) {
            $this->storeSyncMeta($lastMeta);
        }
        $this->refreshFuelTariffMonitoring();

        $status = $errors === [] ? 'success' : ($recordsByApiId === [] ? 'error' : 'partial');
        $infoParts = array_merge(
            ['cereri=' . $requests, 'ferestre_sparte=' . $splits],
            $warnings,
            $errors !== [] ? array_map(static fn (string $e): string => 'EROARE ' . $e, $errors) : []
        );

        $this->finishSyncLog($logId, [
            'status' => $status,
            'records_received' => count($recordsByApiId),
            'records_inserted' => $upsert['inserted'],
            'records_updated' => $upsert['updated'],
            'error_message' => $infoParts !== [] ? implode(' | ', $infoParts) : null,
        ]);

        return [
            'status' => $status,
            'source' => 'api',
            'date_from' => $from->format('Y-m-d'),
            'date_to' => $to->format('Y-m-d'),
            'requests' => $requests,
            'splits' => $splits,
            'api_reported_total' => $apiReportedTotal,
            'records_received' => count($recordsByApiId),
            'records_inserted' => $upsert['inserted'],
            'records_updated' => $upsert['updated'],
            'warnings' => $warnings,
            'errors' => $errors,
            'error_message' => $errors !== [] ? implode(' | ', $errors) : null,
        ];
    }

    /**
     * Sincronizare incrementala dupa ID (modul recomandat pentru rularea
     * periodica): cere alimentarile cu id_alimentare strict mai mare decat
     * cel mai mare api_id numeric din baza si pagineaza avansand cursorul
     * cat timp raspunsul atinge limita de 1000 de inregistrari.
     *
     * Nu poate pierde nimic: ID-urile CardOil sunt crescatoare, iar cursorul
     * avanseaza pe ID-ul maxim BRUT din raspuns (inclusiv randurile
     * non-carburant filtrate la import).
     */
    public function syncIncrementalFromApi(CardOilApiClient $client, int $maxRequests = 20): array
    {
        $this->ensureSchema();

        $cursor = (int) $this->db->query("
            SELECT COALESCE(MAX(CAST(api_id AS UNSIGNED)), 0)
            FROM fuel_fillups
            WHERE api_id REGEXP '^[0-9]+$'
        ")->fetchColumn();

        if ($cursor <= 0) {
            // Baza nu are inca niciun rand din API: nu exista punct de pornire.
            return ['status' => 'no_cursor', 'records_received' => 0, 'records_inserted' => 0, 'records_updated' => 0];
        }

        $startCursor = $cursor;
        $today = new DateTimeImmutable('today');
        $logId = $this->createSyncLog(date('Y-m-d H:i:s'), $today, $today);

        $recordsByApiId = [];
        $warnings = [];
        $errors = [];
        $requests = 0;

        while ($requests < $maxRequests) {
            try {
                $result = $client->fetchFillupsSinceId($cursor);
                $requests++;
            } catch (Throwable $exception) {
                $errors[] = 'id_minim=' . $cursor . ': ' . $exception->getMessage();
                break;
            }

            if ((string) ($result['source'] ?? 'api') !== 'api') {
                $errors[] = (string) ($result['error'] ?? 'sursa ' . (string) ($result['source'] ?? '?'));
                break;
            }

            foreach ((array) ($result['records'] ?? []) as $record) {
                if (is_array($record) && !empty($record['api_id'])) {
                    $recordsByApiId[(string) $record['api_id']] = $record;
                }
            }

            $rawCount = (int) ($result['raw_count'] ?? 0);
            $maxId = (int) ($result['max_id'] ?? 0);

            if ($rawCount < self::API_MAX_RECORDS_PER_REQUEST) {
                break; // pagina incompleta = am ajuns la zi
            }
            if ($maxId <= $cursor) {
                $warnings[] = 'Cursorul nu a avansat (id ' . $cursor . '), paginare oprita preventiv.';
                break;
            }
            $cursor = $maxId;
        }
        if ($requests >= $maxRequests) {
            $warnings[] = 'Atins plafonul de ' . $maxRequests . ' cereri; se continua la urmatoarea rulare.';
        }

        $records = array_values($recordsByApiId);
        $upsert = $this->upsertFillups($records);

        // Cursorul final real: ID-ul maxim adus (bucla se opreste inainte de a
        // avansa cursorul pe ultima pagina, deci $cursor singur ar fi in urma).
        $endId = max($cursor, $startCursor);
        foreach ($records as $record) {
            $recordId = (int) $record['api_id'];
            if ($recordId > $endId) {
                $endId = $recordId;
            }
        }

        // Reimprospatam asocierile doar pe intervalul efectiv adus.
        if ($records !== []) {
            $dates = array_map(static fn (array $r): string => substr((string) $r['fillup_datetime'], 0, 10), $records);
            $this->refreshAutomaticAssociations(min($dates), max($dates));
            $this->refreshFuelTariffMonitoring();
        }
        $this->storeSyncMeta(['last_id' => (string) $endId]);

        $status = $errors === [] ? 'success' : ($records === [] ? 'error' : 'partial');
        $infoParts = array_merge(
            ['incremental id>' . $startCursor, 'cereri=' . $requests],
            $warnings,
            array_map(static fn (string $e): string => 'EROARE ' . $e, $errors)
        );

        $this->finishSyncLog($logId, [
            'status' => $status,
            'records_received' => count($records),
            'records_inserted' => $upsert['inserted'],
            'records_updated' => $upsert['updated'],
            'error_message' => implode(' | ', $infoParts),
        ]);

        return [
            'status' => $status,
            'mode' => 'incremental',
            'start_id' => $startCursor,
            'end_id' => $endId,
            'requests' => $requests,
            'records_received' => count($records),
            'records_inserted' => $upsert['inserted'],
            'records_updated' => $upsert['updated'],
            'warnings' => $warnings,
            'errors' => $errors,
            'error_message' => $errors !== [] ? implode(' | ', $errors) : null,
        ];
    }

    /**
     * Aducerea la zi cu o singura comanda: incremental dupa ID daca baza are
     * deja date din API, altfel fereastra rulanta de 31 de zile (bootstrap).
     */
    public function syncLatestFromApi(CardOilApiClient $client): array
    {
        $result = $this->syncIncrementalFromApi($client);
        if ((string) ($result['status'] ?? '') !== 'no_cursor') {
            return $result;
        }

        $today = new DateTimeImmutable('today');

        return $this->syncRangeFromApi($today->modify('-30 days'), $today, $client) + ['mode' => 'range'];
    }

    private function refreshFuelTariffMonitoring(): void
    {
        if (!class_exists('FuelPriceIndexService') || !class_exists('TariffReviewService')) {
            return;
        }

        try {
            (new FuelPriceIndexService($this->db))->backfillUnitPrices();
            (new TariffReviewService($this->db))->evaluateActiveVersions(null);
        } catch (Throwable $exception) {
            error_log('[FuelModel][tariff_monitoring] ' . $exception->getMessage());
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
                unit_price,
                unit_price_source,
                odometer_km,
                total_value,
                station_name,
                fillup_datetime,
                is_full,
                is_full_manual,
                full_source,
                raw_payload,
                source_type,
                created_at,
                updated_at
            ) VALUES (
                :api_id,
                :vehicle_registration,
                :driver_name,
                :fuel_type,
                :quantity_liters,
                :unit_price,
                :unit_price_source,
                :odometer_km,
                :total_value,
                :station_name,
                :fillup_datetime,
                :is_full,
                :is_full_manual,
                :full_source,
                :raw_payload,
                :source_type,
                :created_at,
                :updated_at
            )
            ON DUPLICATE KEY UPDATE
                vehicle_registration = VALUES(vehicle_registration),
                driver_name = VALUES(driver_name),
                fuel_type = VALUES(fuel_type),
                quantity_liters = VALUES(quantity_liters),
                unit_price = VALUES(unit_price),
                unit_price_source = VALUES(unit_price_source),
                -- Odometrul corectat manual are prioritate: importul nu are voie
                -- sa readuca o valoare gresita tastata la pompa.
                odometer_km = IF(
                    fuel_fillups.odometer_km_manual IS NOT NULL,
                    fuel_fillups.odometer_km_manual,
                    VALUES(odometer_km)
                ),
                total_value = VALUES(total_value),
                station_name = VALUES(station_name),
                fillup_datetime = VALUES(fillup_datetime),
                -- Decizia manuala a operatorului are prioritate absoluta.
                -- API-ul CardOil nu furnizeaza informatia de plin, deci nu are
                -- voie sa reseteze un FULL setat din interfata.
                is_full_manual = IF(
                    VALUES(is_full_manual) IS NOT NULL,
                    VALUES(is_full_manual),
                    fuel_fillups.is_full_manual
                ),
                is_full = IF(
                    COALESCE(VALUES(is_full_manual), fuel_fillups.is_full_manual) IS NOT NULL,
                    COALESCE(VALUES(is_full_manual), fuel_fillups.is_full_manual),
                    VALUES(is_full)
                ),
                full_source = IF(
                    COALESCE(VALUES(is_full_manual), fuel_fillups.is_full_manual) IS NOT NULL,
                    'manual',
                    'api'
                ),
                raw_payload = VALUES(raw_payload),
                -- Randurile create manual sau de teste isi pastreaza provenienta.
                source_type = IF(
                    fuel_fillups.source_type IN ('manual', 'test'),
                    fuel_fillups.source_type,
                    VALUES(source_type)
                ),
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
            $quantityLiters = max(0.0, (float) ($record['quantity_liters'] ?? 0));
            $stmt->bindValue(':quantity_liters', $quantityLiters);

            // Pretul unitar: valoarea din API este autoritara; derivarea este ultima solutie.
            $unitPrice = (float) ($record['unit_price'] ?? 0);
            $unitPriceSource = 'api';
            if ($unitPrice <= 0.0) {
                $unitPrice = $quantityLiters > 0
                    ? round(max(0.0, (float) ($record['total_value'] ?? 0)) / $quantityLiters, 4)
                    : 0.0;
                $unitPriceSource = 'derived';
            }
            if ($unitPrice > 0.0) {
                $stmt->bindValue(':unit_price', $unitPrice);
                $stmt->bindValue(':unit_price_source', $unitPriceSource);
            } else {
                $stmt->bindValue(':unit_price', null, PDO::PARAM_NULL);
                $stmt->bindValue(':unit_price_source', null, PDO::PARAM_NULL);
            }

            $sourceType = (string) ($record['source_type'] ?? 'api');
            if (!in_array($sourceType, ['api', 'manual', 'test', 'demo'], true)) {
                $sourceType = 'api';
            }
            $stmt->bindValue(':source_type', $sourceType);
            $odometerKm = (int) round((float) ($record['odometer_km'] ?? 0));
            if ($odometerKm > 0) {
                $stmt->bindValue(':odometer_km', $odometerKm, PDO::PARAM_INT);
            } else {
                $stmt->bindValue(':odometer_km', null, PDO::PARAM_NULL);
            }
            $stmt->bindValue(':total_value', max(0.0, (float) ($record['total_value'] ?? 0)));
            $this->bindNullableString($stmt, ':station_name', isset($record['station_name']) ? (string) $record['station_name'] : null);
            $stmt->bindValue(':fillup_datetime', (string) ($record['fillup_datetime'] ?? $now));
            // Un import care poarta explicit o decizie manuala (import manual,
            // fixture de test) o pastreaza; importul CardOil trimite mereu NULL,
            // deci nu poate atinge deciziile deja luate de operator.
            $manualFlag = array_key_exists('is_full_manual', $record) && $record['is_full_manual'] !== null
                ? (!empty($record['is_full_manual']) ? 1 : 0)
                : null;
            if ($manualFlag === null) {
                $stmt->bindValue(':is_full', !empty($record['is_full']) ? 1 : 0, PDO::PARAM_INT);
                $stmt->bindValue(':is_full_manual', null, PDO::PARAM_NULL);
                $stmt->bindValue(':full_source', 'api');
            } else {
                $stmt->bindValue(':is_full', $manualFlag, PDO::PARAM_INT);
                $stmt->bindValue(':is_full_manual', $manualFlag, PDO::PARAM_INT);
                $stmt->bindValue(':full_source', 'manual');
            }
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

    /**
     * Marcheaza o alimentare Full/Partial ca DECIZIE MANUALA a operatorului.
     *
     * Valoarea este scrisa si in is_full (folosita de calcule) si in
     * is_full_manual (folosita de upsertFillups pentru a bloca suprascrierea
     * de catre sincronizarea CardOil).
     */
    public function setFillupFull(int $fillupId, bool $isFull): bool
    {
        $this->ensureSchema();

        $stmt = $this->db->prepare("
            UPDATE fuel_fillups
            SET is_full = :is_full,
                is_full_manual = :is_full_manual,
                full_source = 'manual',
                updated_at = :updated_at
            WHERE id = :id
        ");
        $stmt->bindValue(':is_full', $isFull ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':is_full_manual', $isFull ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'));
        $stmt->bindValue(':id', $fillupId, PDO::PARAM_INT);
        $stmt->execute();

        // rowCount() = 0 cand valoarea era deja identica; verificam existenta
        // randului ca sa nu raportam esec pentru o operatie idempotenta.
        if ($stmt->rowCount() > 0) {
            return true;
        }

        $check = $this->db->prepare('SELECT COUNT(*) FROM fuel_fillups WHERE id = :id');
        $check->bindValue(':id', $fillupId, PDO::PARAM_INT);
        $check->execute();

        return (int) $check->fetchColumn() > 0;
    }

    /**
     * Corecteaza manual odometrul unei alimentari (km tastati gresit la pompa).
     *
     * odometer_km devine valoarea corectata (toate calculele o folosesc direct),
     * iar odometer_km_manual pastreaza decizia ca sa nu poata fi suprascrisa
     * de sincronizarea CardOil. Functioneaza si pentru a COMPLETA un odometru
     * lipsa din API.
     */
    public function setFillupOdometer(int $fillupId, int $odometerKm): bool
    {
        $this->ensureSchema();

        if ($fillupId <= 0 || $odometerKm <= 0) {
            return false;
        }

        $stmt = $this->db->prepare('
            UPDATE fuel_fillups
            SET odometer_km = :odometer_km,
                odometer_km_manual = :odometer_km_manual,
                updated_at = :updated_at
            WHERE id = :id
        ');
        $stmt->bindValue(':odometer_km', $odometerKm, PDO::PARAM_INT);
        $stmt->bindValue(':odometer_km_manual', $odometerKm, PDO::PARAM_INT);
        $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'));
        $stmt->bindValue(':id', $fillupId, PDO::PARAM_INT);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            return true;
        }

        $check = $this->db->prepare('SELECT COUNT(*) FROM fuel_fillups WHERE id = :id');
        $check->bindValue(':id', $fillupId, PDO::PARAM_INT);
        $check->execute();

        return (int) $check->fetchColumn() > 0;
    }

    /**
     * Renunta la corectia manuala: odometrul revine la valoarea bruta din API
     * (extrasa din raw_payload) sau la NULL daca API-ul nu a trimis km.
     */
    public function clearFillupOdometerOverride(int $fillupId): bool
    {
        $this->ensureSchema();

        $rowStmt = $this->db->prepare('SELECT raw_payload FROM fuel_fillups WHERE id = :id');
        $rowStmt->bindValue(':id', $fillupId, PDO::PARAM_INT);
        $rowStmt->execute();
        $row = $rowStmt->fetch();
        if ($row === false) {
            return false;
        }

        $apiOdometer = $this->apiOdometerFromRawPayload((string) ($row['raw_payload'] ?? ''));

        $stmt = $this->db->prepare('
            UPDATE fuel_fillups
            SET odometer_km = :odometer_km,
                odometer_km_manual = NULL,
                updated_at = :updated_at
            WHERE id = :id
        ');
        if ($apiOdometer !== null && $apiOdometer > 0) {
            $stmt->bindValue(':odometer_km', $apiOdometer, PDO::PARAM_INT);
        } else {
            $stmt->bindValue(':odometer_km', null, PDO::PARAM_NULL);
        }
        $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'));
        $stmt->bindValue(':id', $fillupId, PDO::PARAM_INT);

        return $stmt->execute();
    }

    /** Km asa cum au venit de la API, din payload-ul brut pastrat la import. */
    public function apiOdometerFromRawPayload(string $rawPayload): ?int
    {
        if ($rawPayload === '') {
            return null;
        }

        $payload = json_decode($rawPayload, true);
        if (!is_array($payload)) {
            return null;
        }

        foreach (['odometer_km', 'km_alimentare', 'kilometraj', 'km'] as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key])) {
                $value = (int) round((float) str_replace([' ', ','], ['', '.'], (string) $payload[$key]));
                if ($value > 0) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * Renunta la decizia manuala si redevine controlata de import.
     * Nu este expusa inca in UI, dar mentine simetria modelului.
     */
    public function clearFillupFullOverride(int $fillupId): bool
    {
        $this->ensureSchema();

        $stmt = $this->db->prepare("
            UPDATE fuel_fillups
            SET is_full_manual = NULL,
                full_source = 'api',
                updated_at = :updated_at
            WHERE id = :id
        ");
        $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'));
        $stmt->bindValue(':id', $fillupId, PDO::PARAM_INT);

        return $stmt->execute();
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
                " . $this->effectiveKmExpr('c') . " AS trip_km,
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

        // Parcurgere secventiala per vehicul, cu baza de km resetata dupa orice
        // citire respinsa. Vechiul cod folosea orbeste citirea precedenta:
        // un odometru tastat gresit (ex. 1.236.604 in loc de ~1.326.000) era
        // exclus ca interval negativ, dar ramanea BAZA intervalului urmator,
        // care iesea +90.000 km si distrugea media flotei.
        $rows = [];
        $baselines = [];
        foreach ($stmt->fetchAll() as $row) {
            $vehicleKey = str_replace(' ', '', strtoupper((string) ($row['vehicle_registration'] ?? '')));
            $currentKm = (float) ($row['odometer_km'] ?? 0);

            if (!array_key_exists($vehicleKey, $baselines)) {
                // Prima citire din perioada: baza vine din ultima alimentare
                // dinaintea perioadei (subinterogarea), ca sa nu pierdem
                // intervalul de la granita de luna.
                $baselines[$vehicleKey] = (float) ($row['previous_odometer_km'] ?? 0);
            }

            $baselineKm = $baselines[$vehicleKey];
            // Indiferent de verdict, citirea curenta devine noua baza: daca a
            // fost un typo, intervalul urmator pica la garda de plauzibilitate
            // si baza se re-sincronizeaza; se pierd cel mult 1-2 intervale.
            $baselines[$vehicleKey] = $currentKm;

            if ($baselineKm <= 0.0) {
                continue;
            }

            $intervalKm = $currentKm - $baselineKm;
            if ($intervalKm <= 0.0) {
                continue;
            }

            // Garda de plauzibilitate fizica: consumul implicat de interval
            // (litri alimentati / km parcursi) trebuie sa fie intr-o plaja
            // posibila pentru un vehicul real. In afara ei, odometrul e gresit
            // (cifre lipsa/in plus la pompa), nu consumul.
            $liters = (float) ($row['quantity_liters'] ?? 0);
            $impliedL100 = ($liters / $intervalKm) * 100;
            if ($impliedL100 < self::MIN_PLAUSIBLE_L100 || $impliedL100 > self::MAX_PLAUSIBLE_L100) {
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

        $monthStart = $this->monthStartFor((string) $filters['date_from']);
        $window = $this->t0Window($monthStart);
        $base = [
            'vehicle' => $vehicle,
            'month_start' => $monthStart->format('Y-m-d'),
            'status' => 'missing_t0',
            'message' => 'T0 lipsă — necesită setare manuală',
            'start_full' => null,
            'next_full' => null,
            'km' => 0.0,
            'motorina_liters' => 0.0,
            'adblue_liters' => 0.0,
            'norm_l100' => 0.0,
            'adblue_percent' => 0.0,
            'km_source' => '',
            // Metadate T0 pentru UI.
            't0_mode' => 'missing',
            't0_message' => 'T0 lipsă — necesită setare manuală',
            't0_window_start' => $window['start']->format('Y-m-d H:i:s'),
            't0_window_end' => $window['end']->format('Y-m-d H:i:s'),
            't0_candidate_count' => 0,
            't0_manual_note' => null,
            't0_manual_set_at' => null,
            't0_requires_manual' => true,
            'candidates' => [],
        ];

        if ($vehicle === '') {
            $base['t0_mode'] = 'no_vehicle';
            $base['status'] = 'invalid';
            $base['message'] = 'Nu exista vehicul pentru calcul.';
            $base['t0_message'] = 'Nu există vehicul pentru calcul.';
            $base['t0_requires_manual'] = false;
            return $base;
        }

        $t0 = $this->resolveT0($vehicle, $monthStart);
        $base['t0_mode'] = $t0['mode'];
        $base['t0_message'] = $t0['message'];
        $base['t0_candidate_count'] = $t0['candidate_count'];
        $base['t0_manual_note'] = $t0['manual_note'];
        $base['t0_manual_set_at'] = $t0['manual_set_at'];
        $base['candidates'] = $this->getT0Candidates($vehicle, $monthStart);

        $startFull = $t0['fillup'];
        if ($startFull === null) {
            return $base;
        }

        $base['t0_requires_manual'] = false;

        $nextFull = $this->findNextFull($vehicle, (string) $startFull['fillup_datetime'], (int) $startFull['id']);
        if ($nextFull === null) {
            $base['start_full'] = $startFull;
            $base['status'] = 'invalid';
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

        // Formula FULL -> intermediare -> FULL ramane neschimbata; se schimba
        // doar provenienta lui T0. Metadatele T0 din $base sunt pastrate.
        return array_merge($base, [
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
            'odometer_warning' => $this->odometerSequenceWarning($vehicle, $startDateTime, $endDateTime),
        ]);
    }

    /**
     * Detecteaza odometru ne-monoton in intervalul T0 -> T1 (km tastati gresit
     * la pompa). Nu blocheaza calculul: doar semnaleaza ca baza de km poate fi
     * distorsionata, iar operatorul poate corecta valoarea din tabel.
     */
    private function odometerSequenceWarning(string $vehicle, string $startDateTime, string $endDateTime): ?string
    {
        $stmt = $this->db->prepare("
            SELECT fillup_datetime, odometer_km
            FROM fuel_fillups
            WHERE REPLACE(UPPER(vehicle_registration), ' ', '') = REPLACE(UPPER(:vehicle), ' ', '')
              AND fuel_type = 'motorina'
              AND odometer_km IS NOT NULL
              AND odometer_km > 0
              AND fillup_datetime >= :start_datetime
              AND fillup_datetime <= :end_datetime
            ORDER BY fillup_datetime ASC, id ASC
        ");
        $stmt->bindValue(':vehicle', $vehicle);
        $stmt->bindValue(':start_datetime', $startDateTime);
        $stmt->bindValue(':end_datetime', $endDateTime);
        $stmt->execute();

        $formatDate = static fn (string $value): string =>
            (new DateTimeImmutable($value))->format('d.m.Y H:i');

        $previous = null;
        foreach ($stmt->fetchAll() as $row) {
            if ($previous !== null && (int) $row['odometer_km'] < (int) $previous['odometer_km']) {
                return sprintf(
                    'Odometru inconsistent în interval: %s are %s km, mai puțin decât %s (%s km). Corectează valoarea din tabelul de alimentări.',
                    $formatDate((string) $row['fillup_datetime']),
                    number_format((int) $row['odometer_km'], 0, ',', '.'),
                    $formatDate((string) $previous['fillup_datetime']),
                    number_format((int) $previous['odometer_km'], 0, ',', '.')
                );
            }
            $previous = $row;
        }

        return null;
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

    // =================================================================
    // Mecanismul T0
    //
    // Distinctia esentiala:
    //   - is_full  este o PROPRIETATE a alimentarii;
    //   - T0       este ROLUL unei alimentari pentru o anumita luna.
    // O alimentare FULL din 30 aprilie poate fi T0 pentru mai, dar poate
    // la fel de bine sa nu fie T0 pentru nicio luna.
    // =================================================================

    /** Numarul de zile calendaristice ale ferestrei, de o parte si de alta. */
    private const T0_WINDOW_DAYS = 4;

    /**
     * Prima zi (00:00:00) a lunii careia ii apartine data primita.
     */
    public function monthStartFor(string $date): DateTimeImmutable
    {
        try {
            $reference = new DateTimeImmutable($date !== '' ? $date : 'today');
        } catch (Throwable) {
            $reference = new DateTimeImmutable('today');
        }

        return $reference->modify('first day of this month')->setTime(0, 0);
    }

    /**
     * Fereastra de eligibilitate T0 pentru luna care incepe la $monthStart:
     * ultimele 4 zile calendaristice ale lunii precedente + zilele 1..4 ale
     * lunii analizate.
     *
     * Totul este derivat din aritmetica de calendar a lui DateTimeImmutable,
     * deci functioneaza identic pentru februarie (28/29 zile), luni de 30 sau
     * 31 de zile, ani bisecti si trecerea decembrie -> ianuarie. Nicio luna si
     * niciun numar de zile nu este hardcodat.
     *
     * Exemplu august 2026: 28,29,30,31 iulie + 1,2,3,4 august.
     * Exemplu martie 2027 (nebisect): 25,26,27,28 februarie + 1,2,3,4 martie.
     * Exemplu martie 2028 (bisect):   26,27,28,29 februarie + 1,2,3,4 martie.
     * Exemplu ianuarie 2027: 28,29,30,31 decembrie 2026 + 1,2,3,4 ianuarie.
     *
     * @return array{start: DateTimeImmutable, end: DateTimeImmutable}
     */
    public function t0Window(DateTimeImmutable $monthStart): array
    {
        $days = self::T0_WINDOW_DAYS;

        return [
            // Prima zi a lunii minus 4 zile = a 4-a zi de la sfarsitul lunii precedente.
            'start' => $monthStart->modify('-' . $days . ' days')->setTime(0, 0, 0),
            // Ziua 4 a lunii analizate, pana la finalul ei.
            'end' => $monthStart->modify('+' . ($days - 1) . ' days')->setTime(23, 59, 59),
        ];
    }

    /**
     * Determina T0 pentru un vehicul si o luna.
     *
     * Ordinea de precedenta:
     *   1. T0 stabilit MANUAL pentru (vehicul, luna) — nu se recalculeaza
     *      niciodata automat, nici la refresh, nici la sync;
     *   2. selectia automata din fereastra ±4 zile, doar dintre alimentarile
     *      FULL de motorina;
     *   3. T0 lipsa — necesita interventie manuala. Nu exista fallback.
     *
     * @return array{
     *     mode: string, fillup: ?array, window_start: string, window_end: string,
     *     candidate_count: int, message: string, manual_note: ?string,
     *     manual_set_at: ?string, stale_manual: bool
     * }
     */
    public function resolveT0(string $vehicle, DateTimeImmutable $monthStart): array
    {
        $this->ensureSchema();

        $window = $this->t0Window($monthStart);
        $result = [
            'mode' => 'missing',
            'fillup' => null,
            'window_start' => $window['start']->format('Y-m-d H:i:s'),
            'window_end' => $window['end']->format('Y-m-d H:i:s'),
            'candidate_count' => 0,
            'message' => 'T0 lipsă — necesită setare manuală',
            'manual_note' => null,
            'manual_set_at' => null,
            'stale_manual' => false,
        ];

        if (trim($vehicle) === '') {
            $result['message'] = 'Nu există vehicul pentru calcul.';
            return $result;
        }

        // (1) Decizia manuala are prioritate absoluta.
        $manual = $this->getManualT0($vehicle, $monthStart);
        if ($manual !== null) {
            $result['mode'] = 'manual';
            $result['fillup'] = $manual['fillup'];
            $result['manual_note'] = $manual['note'];
            $result['manual_set_at'] = $manual['updated_at'];
            $result['message'] = 'Setat manual';

            return $result;
        }

        // (2) Selectia automata, strict din fereastra.
        $candidates = $this->findT0CandidatesInWindow($vehicle, $window['start'], $window['end']);
        $result['candidate_count'] = count($candidates);
        if ($candidates !== []) {
            $result['mode'] = 'auto';
            $result['fillup'] = $candidates[0];
            $result['message'] = 'Determinat automat';

            return $result;
        }

        // (3) Fara fallback in afara ferestrei.
        return $result;
    }

    /**
     * Alimentarile FULL de motorina eligibile ca T0, ordonate dupa regula
     * determinista de selectie:
     *   1. cea mai mica distanta temporala absoluta fata de prima zi a lunii;
     *   2. la egalitate reala de distanta, FULL-ul ANTERIOR inceputului lunii;
     *   3. la egalitate in continuare, fillup_datetime apoi id (rezultat stabil).
     *
     * Exemplu regula (2): FULL 31.07 22:00 si FULL 01.08 02:00 sunt ambele la
     * 7200 s de 01.08 00:00 -> se alege 31.07 22:00.
     *
     * @return list<array<string, mixed>>
     */
    private function findT0CandidatesInWindow(
        string $vehicle,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd
    ): array {
        $monthStartSql = $windowStart->modify('+' . self::T0_WINDOW_DAYS . ' days')->setTime(0, 0, 0);

        $stmt = $this->db->prepare("
            SELECT
                f.*,
                ABS(TIMESTAMPDIFF(SECOND, f.fillup_datetime, :month_start_distance)) AS t0_distance_seconds
            FROM fuel_fillups f
            WHERE REPLACE(UPPER(f.vehicle_registration), ' ', '') = REPLACE(UPPER(:vehicle), ' ', '')
              AND f.fuel_type = 'motorina'
              AND f.is_full = 1
              AND f.fillup_datetime BETWEEN :window_start AND :window_end
            ORDER BY
                ABS(TIMESTAMPDIFF(SECOND, f.fillup_datetime, :month_start_order)) ASC,
                CASE WHEN f.fillup_datetime < :month_start_side THEN 0 ELSE 1 END ASC,
                f.fillup_datetime ASC,
                f.id ASC
        ");
        $stmt->bindValue(':vehicle', $vehicle);
        $stmt->bindValue(':window_start', $windowStart->format('Y-m-d H:i:s'));
        $stmt->bindValue(':window_end', $windowEnd->format('Y-m-d H:i:s'));
        $stmt->bindValue(':month_start_distance', $monthStartSql->format('Y-m-d H:i:s'));
        $stmt->bindValue(':month_start_order', $monthStartSql->format('Y-m-d H:i:s'));
        $stmt->bindValue(':month_start_side', $monthStartSql->format('Y-m-d H:i:s'));
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Expune candidatii automati din fereastra (pentru UI si teste).
     *
     * @return list<array<string, mixed>>
     */
    public function getT0WindowCandidates(string $vehicle, DateTimeImmutable $monthStart): array
    {
        $this->ensureSchema();
        if (trim($vehicle) === '') {
            return [];
        }

        $window = $this->t0Window($monthStart);

        return $this->findT0CandidatesInWindow($vehicle, $window['start'], $window['end']);
    }

    /**
     * T0 manual stocat pentru (vehicul, luna), impreuna cu alimentarea tinta.
     *
     * @return array{fillup: array<string, mixed>, note: ?string, updated_at: string}|null
     */
    public function getManualT0(string $vehicle, DateTimeImmutable $monthStart): ?array
    {
        $this->ensureSchema();

        $stmt = $this->db->prepare('
            SELECT t.note, t.updated_at, f.*
            FROM fuel_month_t0 t
            INNER JOIN fuel_fillups f ON f.id = t.fillup_id
            WHERE t.vehicle_key = :vehicle_key
              AND t.month_start = :month_start
            LIMIT 1
        ');
        $stmt->bindValue(':vehicle_key', $this->vehicleKey($vehicle));
        $stmt->bindValue(':month_start', $monthStart->format('Y-m-d'));
        $stmt->execute();
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $note = $row['note'] !== null ? (string) $row['note'] : null;
        $updatedAt = (string) $row['updated_at'];
        unset($row['note'], $row['updated_at']);

        return ['fillup' => $row, 'note' => $note, 'updated_at' => $updatedAt];
    }

    /**
     * Valideaza o alimentare inainte de a o accepta ca T0 manual.
     *
     * Blocantele opresc operatia; avertismentele sunt afisate operatorului dar
     * permit continuarea (ex. odometru lipsa, pentru care calculul FULL->FULL
     * are deja fallback pe km din dispecer).
     *
     * @return array{ok: bool, errors: list<string>, warnings: list<string>, fillup: ?array}
     */
    public function validateT0Candidate(int $fillupId, string $vehicle, DateTimeImmutable $monthStart): array
    {
        $this->ensureSchema();

        $errors = [];
        $warnings = [];

        if ($fillupId <= 0) {
            return ['ok' => false, 'errors' => ['Alimentarea selectată nu este validă.'], 'warnings' => [], 'fillup' => null];
        }
        if (trim($vehicle) === '') {
            return ['ok' => false, 'errors' => ['Nu a fost transmis vehiculul.'], 'warnings' => [], 'fillup' => null];
        }

        $stmt = $this->db->prepare('SELECT * FROM fuel_fillups WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $fillupId, PDO::PARAM_INT);
        $stmt->execute();
        $fillup = $stmt->fetch();

        if (!$fillup) {
            return ['ok' => false, 'errors' => ['Alimentarea nu mai există în baza de date.'], 'warnings' => [], 'fillup' => null];
        }

        // Vehiculul corect.
        if ($this->vehicleKey((string) $fillup['vehicle_registration']) !== $this->vehicleKey($vehicle)) {
            $errors[] = sprintf(
                'Alimentarea aparține vehiculului %s, nu %s.',
                (string) $fillup['vehicle_registration'],
                $vehicle
            );
        }

        // Doar motorina poate defini T0.
        if ((string) $fillup['fuel_type'] !== 'motorina') {
            $errors[] = 'T0 poate fi stabilit doar pe o alimentare de motorină.';
        }

        // Data valida.
        $fillupDateTime = null;
        $rawDateTime = (string) ($fillup['fillup_datetime'] ?? '');
        if ($rawDateTime === '' || str_starts_with($rawDateTime, '0000')) {
            $errors[] = 'Alimentarea nu are o dată validă.';
        } else {
            try {
                $fillupDateTime = new DateTimeImmutable($rawDateTime);
            } catch (Throwable) {
                $errors[] = 'Data alimentării nu poate fi interpretată.';
            }
        }

        // Cantitate coerenta.
        if ((float) ($fillup['quantity_liters'] ?? 0) <= 0.0) {
            $errors[] = 'Alimentarea are cantitate zero — record inconsistent.';
        }

        // T0 nu poate fi dupa sfarsitul lunii analizate.
        $monthEnd = $monthStart->modify('last day of this month')->setTime(23, 59, 59);
        if ($fillupDateTime instanceof DateTimeImmutable && $fillupDateTime > $monthEnd) {
            $errors[] = sprintf(
                'Alimentarea (%s) este ulterioară lunii analizate (%s).',
                $fillupDateTime->format('d.m.Y H:i'),
                $monthStart->format('m.Y')
            );
        }

        // Odometru: nu blocheaza, dar consumul va cadea pe km din dispecer.
        if ((int) ($fillup['odometer_km'] ?? 0) <= 0) {
            $warnings[] = 'Alimentarea nu are odometru. Km-ii intervalului vor fi preluați din Dispecer curse, dacă există.';
        }

        // In afara ferestrei automate: permis, dar semnalat.
        if ($fillupDateTime instanceof DateTimeImmutable) {
            $window = $this->t0Window($monthStart);
            if ($fillupDateTime < $window['start'] || $fillupDateTime > $window['end']) {
                $warnings[] = sprintf(
                    'Alimentarea este în afara ferestrei automate (%s – %s).',
                    $window['start']->format('d.m.Y'),
                    $window['end']->format('d.m.Y')
                );
            }
        }

        // FULL: nu blocheaza, dar impune confirmare explicita in controller.
        if ((int) ($fillup['is_full'] ?? 0) !== 1) {
            $warnings[] = 'Alimentarea este marcată Parțial și va fi transformată în FULL.';
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'fillup' => $fillup,
        ];
    }

    /**
     * Stabileste T0 manual pentru (vehicul, luna).
     *
     * Nu modifica data originala a alimentarii si nu creeaza date fictive —
     * se pastreaza referinta catre alimentarea reala selectata. Daca
     * alimentarea nu era FULL, este marcata FULL ca decizie manuala
     * (persistenta la sync), dar numai cand apelantul a confirmat explicit.
     *
     * @return array{ok: bool, message: string, warnings: list<string>}
     */
    public function setManualT0(
        int $fillupId,
        string $vehicle,
        DateTimeImmutable $monthStart,
        bool $confirmMarkFull,
        ?int $userId = null,
        ?string $note = null
    ): array {
        $validation = $this->validateT0Candidate($fillupId, $vehicle, $monthStart);
        if (!$validation['ok']) {
            return ['ok' => false, 'message' => implode(' ', $validation['errors']), 'warnings' => []];
        }

        $fillup = $validation['fillup'];
        $needsFull = (int) ($fillup['is_full'] ?? 0) !== 1;
        if ($needsFull && !$confirmMarkFull) {
            return [
                'ok' => false,
                'message' => 'Alimentarea selectată este Parțială. Confirmă transformarea ei în FULL pentru a o folosi ca T0.',
                'warnings' => $validation['warnings'],
            ];
        }

        $this->db->beginTransaction();
        try {
            if ($needsFull) {
                $this->setFillupFull($fillupId, true);
            }

            $now = date('Y-m-d H:i:s');
            $stmt = $this->db->prepare("
                INSERT INTO fuel_month_t0
                    (vehicle_key, month_start, fillup_id, mode, note, created_by, created_at, updated_at)
                VALUES
                    (:vehicle_key, :month_start, :fillup_id, 'manual', :note, :created_by, :created_at, :updated_at)
                ON DUPLICATE KEY UPDATE
                    fillup_id = VALUES(fillup_id),
                    mode = 'manual',
                    note = VALUES(note),
                    created_by = VALUES(created_by),
                    updated_at = VALUES(updated_at)
            ");
            $stmt->bindValue(':vehicle_key', $this->vehicleKey($vehicle));
            $stmt->bindValue(':month_start', $monthStart->format('Y-m-d'));
            $stmt->bindValue(':fillup_id', $fillupId, PDO::PARAM_INT);
            $this->bindNullableString($stmt, ':note', $note);
            if ($userId !== null && $userId > 0) {
                $stmt->bindValue(':created_by', $userId, PDO::PARAM_INT);
            } else {
                $stmt->bindValue(':created_by', null, PDO::PARAM_NULL);
            }
            $stmt->bindValue(':created_at', $now);
            $stmt->bindValue(':updated_at', $now);
            $stmt->execute();

            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        return [
            'ok' => true,
            'message' => 'T0 a fost stabilit manual pentru ' . $monthStart->format('m.Y') . '.',
            'warnings' => $validation['warnings'],
        ];
    }

    /**
     * Sterge T0 manual si readuce luna la selectia automata.
     * Nu atinge alimentarea si nu revoca marcajul FULL.
     */
    public function clearManualT0(string $vehicle, DateTimeImmutable $monthStart): bool
    {
        $this->ensureSchema();

        $stmt = $this->db->prepare('
            DELETE FROM fuel_month_t0
            WHERE vehicle_key = :vehicle_key
              AND month_start = :month_start
        ');
        $stmt->bindValue(':vehicle_key', $this->vehicleKey($vehicle));
        $stmt->bindValue(':month_start', $monthStart->format('Y-m-d'));
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * Alimentarile din care operatorul poate alege T0 manual.
     *
     * Fereastra de listare este intentionat mai larga decat cea automata
     * (o luna inainte de inceputul lunii pana la finalul lunii analizate),
     * exact pentru cazul in care regula ±4 nu gaseste niciun FULL.
     *
     * @return list<array<string, mixed>>
     */
    public function getT0Candidates(string $vehicle, DateTimeImmutable $monthStart, int $limit = 120): array
    {
        $this->ensureSchema();
        if (trim($vehicle) === '') {
            return [];
        }

        $window = $this->t0Window($monthStart);
        $from = $monthStart->modify('-1 month')->setTime(0, 0, 0);
        $to = $monthStart->modify('last day of this month')->setTime(23, 59, 59);

        $stmt = $this->db->prepare("
            SELECT *
            FROM fuel_fillups
            WHERE REPLACE(UPPER(vehicle_registration), ' ', '') = REPLACE(UPPER(:vehicle), ' ', '')
              AND fuel_type = 'motorina'
              AND fillup_datetime BETWEEN :date_from AND :date_to
            ORDER BY fillup_datetime DESC, id DESC
            LIMIT :limit_rows
        ");
        $stmt->bindValue(':vehicle', $vehicle);
        $stmt->bindValue(':date_from', $from->format('Y-m-d H:i:s'));
        $stmt->bindValue(':date_to', $to->format('Y-m-d H:i:s'));
        $stmt->bindValue(':limit_rows', max(1, min(300, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rawDateTime = (string) ($row['fillup_datetime'] ?? '');
            $inWindow = false;
            if ($rawDateTime !== '') {
                try {
                    $moment = new DateTimeImmutable($rawDateTime);
                    $inWindow = $moment >= $window['start'] && $moment <= $window['end'];
                } catch (Throwable) {
                    $inWindow = false;
                }
            }
            $row['in_t0_window'] = $inWindow;
            $rows[] = $row;
        }

        return $rows;
    }

    /** Cheia normalizata folosita pentru identificarea vehiculului. */
    private function vehicleKey(string $registration): string
    {
        return str_replace(' ', '', strtoupper(trim($registration)));
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

        // Filtrul de marca: alimentarea apartine unui vehicul din flota cu
        // marca respectiva (numerele doar-din-CardOil, fara fisa, sunt excluse
        // cat timp filtrul e activ).
        $brand = trim((string) ($filters['brand'] ?? ''));
        if ($brand !== '') {
            $where[] = "EXISTS (
                SELECT 1 FROM vehicule vb
                WHERE REPLACE(UPPER(vb.nr_inmatriculare), ' ', '') = REPLACE(UPPER(f.vehicle_registration), ' ', '')
                  AND UPPER(TRIM(vb.marca)) = UPPER(:{$prefix}_brand)
            )";
            $params[":{$prefix}_brand"] = $brand;
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
                'source_type' => 'demo',
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
