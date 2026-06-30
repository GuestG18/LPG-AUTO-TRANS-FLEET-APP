<?php
declare(strict_types=1);

class FuelModel extends BaseModel
{
    public const RECORD_REFUEL = 'alimentare';
    public const RECORD_T0 = 't0';

    public const TRANSPORT_LABELS = [
        'primar' => 'Primar',
        'primar_tona' => 'Primar',
        'distributie' => 'Distributie',
        'primar_distributie' => 'Primar + Distributie',
        'compresor' => 'Compresor',
    ];

    private bool $schemaEnsured = false;

    public function ensureSchema(): void
    {
        if ($this->schemaEnsured) {
            return;
        }

        $this->addColumnIfMissing('alimentari', 'tip_inregistrare', "ENUM('alimentare','t0') NOT NULL DEFAULT 'alimentare' AFTER id");
        $this->addColumnIfMissing('alimentari', 'cursa_id', 'INT UNSIGNED NULL AFTER driver_id');
        $this->addColumnIfMissing('alimentari', 'pret_litru', 'DECIMAL(10,2) NULL AFTER litri');
        $this->addColumnIfMissing('alimentari', 'furnizor', 'VARCHAR(190) NULL AFTER cost_total');
        $this->addColumnIfMissing('alimentari', 'fuel_state', 'DECIMAL(10,2) NULL AFTER km_alimentare');
        $this->addColumnIfMissing('alimentari', 'full_flag', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER fuel_state');
        $this->addColumnIfMissing('alimentari', 't0_manual', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER full_flag');
        $this->addColumnIfMissing('alimentari', 'factura_original', 'VARCHAR(255) NULL AFTER observatii');
        $this->addColumnIfMissing('alimentari', 'factura_stocata', 'VARCHAR(255) NULL AFTER factura_original');
        $this->addColumnIfMissing('alimentari', 'factura_mime_type', 'VARCHAR(150) NULL AFTER factura_stocata');
        $this->addColumnIfMissing('alimentari', 'factura_file_size', 'INT UNSIGNED NULL AFTER factura_mime_type');
        $this->addIndexIfMissing('alimentari', 'idx_alimentari_tip_data', 'ALTER TABLE alimentari ADD INDEX idx_alimentari_tip_data (tip_inregistrare, data_alimentare)');
        $this->addIndexIfMissing('alimentari', 'idx_alimentari_cursa', 'ALTER TABLE alimentari ADD INDEX idx_alimentari_cursa (cursa_id)');
        $this->addIndexIfMissing('alimentari', 'idx_alimentari_furnizor', 'ALTER TABLE alimentari ADD INDEX idx_alimentari_furnizor (furnizor)');

        $this->db->exec("
            UPDATE alimentari
            SET pret_litru = ROUND(cost_total / NULLIF(litri, 0), 2)
            WHERE tip_inregistrare = 'alimentare'
              AND (pret_litru IS NULL OR pret_litru = 0)
              AND litri > 0
              AND cost_total > 0
        ");

        $this->schemaEnsured = true;
    }

    public function normalizeFilters(array $input): array
    {
        $month = (int) ($input['month'] ?? date('n'));
        if ($month < 1 || $month > 12) {
            $month = (int) date('n');
        }

        $year = (int) ($input['year'] ?? date('Y'));
        if ($year < 2000 || $year > 2100) {
            $year = (int) date('Y');
        }

        return [
            'vehicle_id' => max(0, (int) ($input['vehicle_id'] ?? 0)),
            'month' => $month,
            'year' => $year,
            'record_type' => $this->normalizeRecordType((string) ($input['record_type'] ?? '')),
            'supplier' => trim((string) ($input['supplier'] ?? '')),
            'transport_type' => $this->normalizeTransportType((string) ($input['transport_type'] ?? '')),
            'trip_filter' => $this->normalizeTripFilter((string) ($input['trip_filter'] ?? '')),
            'sort' => $this->normalizeSort((string) ($input['sort'] ?? 'date_desc')),
            'page' => max(1, (int) ($input['p'] ?? 1)),
        ];
    }

    public function getPeriodRange(array $filters): array
    {
        $year = (int) $filters['year'];
        $month = (int) $filters['month'];
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = (new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');

        return [$start, $end];
    }

    public function getVehicleOptions(): array
    {
        $stmt = $this->db->query("
            SELECT id, nr_inmatriculare, marca, model, consum_mediu, status
            FROM vehicule
            WHERE status = 'activ'
              AND tip_vehicul NOT IN ('semiremorca', 'semiremorca_primar', 'semiremorca_distributie')
            ORDER BY nr_inmatriculare ASC
        ");

        return $stmt->fetchAll();
    }

    public function getT0VehicleOptions(): array
    {
        $stmt = $this->db->query("
            SELECT DISTINCT v.id, v.nr_inmatriculare, v.marca, v.model, v.consum_mediu,
                   v.capacitate_rezervor, v.status, v.km_bord
            FROM vehicule v
            INNER JOIN curse_dispecer c ON c.vehicle_id = v.id
            WHERE v.status = 'activ'
              AND v.tip_vehicul NOT IN ('semiremorca', 'semiremorca_primar', 'semiremorca_distributie')
            ORDER BY v.nr_inmatriculare ASC
        ");

        return $stmt->fetchAll();
    }

    public function getSupplierOptions(array $filters): array
    {
        [$start, $end] = $this->getPeriodRange($filters);
        $params = [':start' => $start, ':end' => $end];
        $vehicleWhere = '';
        if ((int) $filters['vehicle_id'] > 0) {
            $vehicleWhere = ' AND vehicle_id = :vehicle_id';
            $params[':vehicle_id'] = (int) $filters['vehicle_id'];
        }

        $stmt = $this->db->prepare("
            SELECT DISTINCT furnizor
            FROM alimentari
            WHERE data_alimentare BETWEEN :start AND :end
              AND COALESCE(furnizor, '') <> ''
              {$vehicleWhere}
            ORDER BY furnizor ASC
        ");
        $this->bindParams($stmt, $params);
        $stmt->execute();

        return array_map(static fn (array $row): string => (string) $row['furnizor'], $stmt->fetchAll());
    }

    public function getTripOptions(array $filters): array
    {
        [$start, $end] = $this->getPeriodRange($filters);
        $params = [':start' => $start, ':end' => $end];
        $where = "WHERE c.data_inceput <= :end AND c.data_sfarsit >= :start";
        if ((int) $filters['vehicle_id'] > 0) {
            $where .= ' AND c.vehicle_id = :vehicle_id';
            $params[':vehicle_id'] = (int) $filters['vehicle_id'];
        }
        if ((string) $filters['transport_type'] !== '') {
            $where .= ' AND c.tip_transport = :transport_type';
            $params[':transport_type'] = (string) $filters['transport_type'];
        }

        $stmt = $this->db->prepare("
            SELECT c.id, c.data_inceput, c.data_sfarsit, c.tip_transport, v.nr_inmatriculare, b.nume AS beneficiar
            FROM curse_dispecer c
            INNER JOIN vehicule v ON v.id = c.vehicle_id
            LEFT JOIN configurare_beneficiari_transport b ON b.id = c.beneficiar_id
            {$where}
            ORDER BY c.data_inceput DESC, c.id DESC
            LIMIT 200
        ");
        $this->bindParams($stmt, $params);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getDashboardData(array $filters, int $perPage = 25): array
    {
        $this->ensureSchema();
        $filters = $this->normalizeFilters($filters);
        [$start, $end] = $this->getPeriodRange($filters);

        $totalRows = $this->countRecords($filters);
        $totalPages = max(1, (int) ceil($totalRows / $perPage));
        $page = min((int) $filters['page'], $totalPages);
        $offset = ($page - 1) * $perPage;

        $rows = $this->getRecords($filters, $perPage, $offset);
        $metricRows = $this->getRecords($filters, 0, 0);
        $timelineRows = $this->getTimelineRows($filters);
        $calculatedMetricRows = $this->attachCalculations($metricRows, $timelineRows);
        $calculationById = [];
        foreach ($calculatedMetricRows as $row) {
            $calculationById[(int) $row['id']] = $row;
        }

        foreach ($rows as &$row) {
            $id = (int) $row['id'];
            if (isset($calculationById[$id])) {
                $row = array_merge($row, $this->calculationOnly($calculationById[$id]));
            } else {
                $row = array_merge($row, $this->emptyCalculation());
            }
        }
        unset($row);

        $missingT0 = $this->getVehiclesMissingT0($filters);
        $kpis = $this->buildKpis($calculatedMetricRows, $filters, $missingT0);
        $transportMetrics = $this->buildTransportMetrics($calculatedMetricRows);

        return [
            'filters' => $filters,
            'period' => ['start' => $start, 'end' => $end],
            'rows' => $rows,
            'kpis' => $kpis,
            'transportMetrics' => $transportMetrics,
            'missingT0Vehicles' => $missingT0,
            'pagination' => [
                'page' => $page,
                'total_pages' => $totalPages,
                'total_rows' => $totalRows,
                'per_page' => $perPage,
            ],
        ];
    }

    public function getRecordById(int $id): ?array
    {
        $this->ensureSchema();
        $stmt = $this->db->prepare($this->baseRecordSql() . " WHERE a.id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row ? $this->decorateRecord($row) : null;
    }

    public function saveRecord(array $data): int
    {
        $this->ensureSchema();
        $id = max(0, (int) ($data['id'] ?? 0));
        $type = $this->normalizeRecordType((string) ($data['tip_inregistrare'] ?? self::RECORD_REFUEL));
        if ($type === '') {
            $type = self::RECORD_REFUEL;
        }

        $vehicleId = max(0, (int) ($data['vehicle_id'] ?? 0));
        $date = (string) ($data['data_alimentare'] ?? '');
        $km = max(0, (int) ($data['km_bord'] ?? 0));
        if ($vehicleId <= 0 || !$this->vehicleExists($vehicleId)) {
            throw new InvalidArgumentException('Selecteaza un vehicul valid.');
        }
        if (!$this->isValidDate($date)) {
            throw new InvalidArgumentException('Data este invalida.');
        }
        if ($km <= 0) {
            throw new InvalidArgumentException('Km bord trebuie sa fie mai mare decat 0.');
        }

        $invoice = is_array($data['invoice'] ?? null) ? $data['invoice'] : [];
        $removeInvoice = !empty($data['remove_invoice']);
        $now = date('Y-m-d H:i:s');
        $existing = $id > 0 ? $this->getRecordById($id) : null;
        if ($id > 0 && $existing === null) {
            throw new InvalidArgumentException('Inregistrarea nu exista.');
        }

        if ($type === self::RECORD_T0) {
            if ($this->hasT0ForVehicleMonth($vehicleId, $date, $id)) {
                throw new InvalidArgumentException('Exista deja un T0 pentru acest vehicul in luna selectata.');
            }

            $fuelState = $this->normalizeDecimal($data['fuel_state'] ?? null);
            $fullFlag = !empty($data['full_flag']) ? 1 : 0;
            if ($fullFlag === 1) {
                $capacity = $this->getVehicleReservoirCapacity($vehicleId);
                if ($capacity === null || $capacity <= 0) {
                    throw new InvalidArgumentException('Completeaza Capacitate rezervor in Detalii Vehicul pentru a salva T0 ca FULL.');
                }
                $fuelState = round($capacity, 2);
            }
            if ($fuelState === null && $fullFlag === 0) {
                throw new InvalidArgumentException('Completeaza starea initiala combustibil sau marcheaza FULL.');
            }

            $payload = [
                ':tip_inregistrare' => self::RECORD_T0,
                ':vehicle_id' => $vehicleId,
                ':driver_id' => null,
                ':cursa_id' => null,
                ':data_alimentare' => $date,
                ':litri' => 0.0,
                ':pret_litru' => null,
                ':cost_total' => 0.0,
                ':furnizor' => null,
                ':km_bord' => $km,
                ':km_alimentare' => $km,
                ':fuel_state' => $fuelState,
                ':full_flag' => $fullFlag,
                ':t0_manual' => 1,
                ':observatii' => $this->nullIfEmpty((string) ($data['observatii'] ?? '')),
                ':updated_at' => $now,
            ];
        } else {
            $liters = $this->normalizeDecimal($data['litri'] ?? null);
            $price = $this->normalizeDecimal($data['pret_litru'] ?? null);
            if ($liters === null || $liters <= 0) {
                throw new InvalidArgumentException('Litri alimentati trebuie sa fie mai mare decat 0.');
            }
            if ($price === null || $price <= 0) {
                throw new InvalidArgumentException('Pretul pe litru trebuie sa fie mai mare decat 0.');
            }

            $tripId = max(0, (int) ($data['cursa_id'] ?? 0));
            $trip = $tripId > 0 ? $this->getTripById($tripId) : null;
            if ($tripId > 0 && ($trip === null || (int) $trip['vehicle_id'] !== $vehicleId)) {
                throw new InvalidArgumentException('Cursa asociata nu este valida pentru vehiculul selectat.');
            }

            $driverId = $trip !== null ? (int) ($trip['driver_id'] ?? 0) : max(0, (int) ($data['driver_id'] ?? 0));
            $payload = [
                ':tip_inregistrare' => self::RECORD_REFUEL,
                ':vehicle_id' => $vehicleId,
                ':driver_id' => $driverId > 0 ? $driverId : null,
                ':cursa_id' => $tripId > 0 ? $tripId : null,
                ':data_alimentare' => $date,
                ':litri' => round((float) $liters, 2),
                ':pret_litru' => round((float) $price, 2),
                ':cost_total' => round((float) $liters * (float) $price, 2),
                ':furnizor' => $this->nullIfEmpty((string) ($data['furnizor'] ?? '')),
                ':km_bord' => $km,
                ':km_alimentare' => $km,
                ':fuel_state' => null,
                ':full_flag' => 0,
                ':t0_manual' => 0,
                ':observatii' => $this->nullIfEmpty((string) ($data['observatii'] ?? '')),
                ':updated_at' => $now,
            ];
        }

        if ($existing !== null && ($removeInvoice || $invoice !== [])) {
            $this->deleteInvoiceFile((string) ($existing['factura_stocata'] ?? ''));
        }

        $invoiceColumns = [
            ':factura_original' => $existing['factura_original'] ?? null,
            ':factura_stocata' => $existing['factura_stocata'] ?? null,
            ':factura_mime_type' => $existing['factura_mime_type'] ?? null,
            ':factura_file_size' => $existing['factura_file_size'] ?? null,
        ];
        if ($type === self::RECORD_T0) {
            if ($existing !== null) {
                $this->deleteInvoiceFile((string) ($existing['factura_stocata'] ?? ''));
            }
            $invoiceColumns = [
                ':factura_original' => null,
                ':factura_stocata' => null,
                ':factura_mime_type' => null,
                ':factura_file_size' => null,
            ];
        } elseif ($removeInvoice) {
            $invoiceColumns = [
                ':factura_original' => null,
                ':factura_stocata' => null,
                ':factura_mime_type' => null,
                ':factura_file_size' => null,
            ];
        }
        if ($type !== self::RECORD_T0 && $invoice !== []) {
            $invoiceColumns = [
                ':factura_original' => $invoice['original'] ?? null,
                ':factura_stocata' => $invoice['stored'] ?? null,
                ':factura_mime_type' => $invoice['mime'] ?? null,
                ':factura_file_size' => $invoice['size'] ?? null,
            ];
        }

        $payload = array_merge($payload, $invoiceColumns);

        if ($id > 0) {
            $sql = "
                UPDATE alimentari
                SET tip_inregistrare = :tip_inregistrare, vehicle_id = :vehicle_id, driver_id = :driver_id,
                    cursa_id = :cursa_id, data_alimentare = :data_alimentare, litri = :litri,
                    pret_litru = :pret_litru, cost_total = :cost_total, furnizor = :furnizor,
                    km_bord = :km_bord, km_alimentare = :km_alimentare, fuel_state = :fuel_state,
                    full_flag = :full_flag, t0_manual = :t0_manual, observatii = :observatii,
                    factura_original = :factura_original, factura_stocata = :factura_stocata,
                    factura_mime_type = :factura_mime_type, factura_file_size = :factura_file_size,
                    updated_at = :updated_at
                WHERE id = :id
            ";
            $stmt = $this->db->prepare($sql);
            $payload[':id'] = $id;
            $this->bindPayload($stmt, $payload);
            $stmt->execute();

            return $id;
        }

        $sql = "
            INSERT INTO alimentari (
                tip_inregistrare, vehicle_id, driver_id, cursa_id, data_alimentare, litri,
                pret_litru, cost_total, furnizor, km_bord, km_alimentare, fuel_state,
                full_flag, t0_manual, observatii, factura_original, factura_stocata,
                factura_mime_type, factura_file_size, created_at, updated_at
            ) VALUES (
                :tip_inregistrare, :vehicle_id, :driver_id, :cursa_id, :data_alimentare, :litri,
                :pret_litru, :cost_total, :furnizor, :km_bord, :km_alimentare, :fuel_state,
                :full_flag, :t0_manual, :observatii, :factura_original, :factura_stocata,
                :factura_mime_type, :factura_file_size, :created_at, :updated_at
            )
        ";
        $stmt = $this->db->prepare($sql);
        $payload[':created_at'] = $now;
        $this->bindPayload($stmt, $payload);
        $stmt->execute();

        return (int) $this->db->lastInsertId();
    }

    public function deleteRecord(int $id): void
    {
        $this->ensureSchema();
        $record = $this->getRecordById($id);
        if ($record === null) {
            return;
        }

        $stmt = $this->db->prepare('DELETE FROM alimentari WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $this->deleteInvoiceFile((string) ($record['factura_stocata'] ?? ''));
    }

    public function detectTrip(int $vehicleId, string $date): ?array
    {
        if ($vehicleId <= 0 || !$this->isValidDate($date)) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT c.*, b.nume AS beneficiar_nume, s.nume AS sofer_nume,
                   li.nume AS loc_incarcare_nume, zd.nume AS zona_distributie_nume
            FROM curse_dispecer c
            LEFT JOIN configurare_beneficiari_transport b ON b.id = c.beneficiar_id
            LEFT JOIN soferi s ON s.id = c.driver_id
            LEFT JOIN configurare_locuri_incarcare li ON li.id = c.loc_incarcare_id
            LEFT JOIN configurare_zone_distributie zd ON zd.id = c.zona_distributie_id
            WHERE c.vehicle_id = :vehicle_id
              AND c.data_inceput <= :date_start
              AND c.data_sfarsit >= :date_end
            ORDER BY c.data_inceput DESC, c.id DESC
            LIMIT 1
        ");
        $stmt->bindValue(':vehicle_id', $vehicleId, PDO::PARAM_INT);
        $stmt->bindValue(':date_start', $date);
        $stmt->bindValue(':date_end', $date);
        $stmt->execute();
        $trip = $stmt->fetch();

        return $trip ? $this->formatTripPayload($trip) : null;
    }

    public function getTripsForVehicleMonth(int $vehicleId, int $year, int $month): array
    {
        if ($vehicleId <= 0 || $year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
            return [];
        }

        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = (new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');

        $stmt = $this->db->prepare("
            SELECT c.*, b.nume AS beneficiar_nume, s.nume AS sofer_nume,
                   li.nume AS loc_incarcare_nume, zd.nume AS zona_distributie_nume
            FROM curse_dispecer c
            LEFT JOIN configurare_beneficiari_transport b ON b.id = c.beneficiar_id
            LEFT JOIN soferi s ON s.id = c.driver_id
            LEFT JOIN configurare_locuri_incarcare li ON li.id = c.loc_incarcare_id
            LEFT JOIN configurare_zone_distributie zd ON zd.id = c.zona_distributie_id
            WHERE c.vehicle_id = :vehicle_id
              AND c.data_inceput <= :end_date
              AND c.data_sfarsit >= :start_date
            ORDER BY c.data_inceput ASC, c.data_sfarsit ASC, c.id ASC
        ");
        $stmt->bindValue(':vehicle_id', $vehicleId, PDO::PARAM_INT);
        $stmt->bindValue(':start_date', $start);
        $stmt->bindValue(':end_date', $end);
        $stmt->execute();

        $trips = [];
        foreach ($stmt->fetchAll() as $trip) {
            $payload = $this->formatTripPayload($trip);
            $payload['start_date'] = (string) ($trip['data_inceput'] ?? '');
            $payload['end_date'] = (string) ($trip['data_sfarsit'] ?? '');
            $payload['dates'] = [];

            $firstDate = max($start, (string) ($trip['data_inceput'] ?? $start));
            $lastDate = min($end, (string) ($trip['data_sfarsit'] ?? $end));
            if ($this->isValidDate($firstDate) && $this->isValidDate($lastDate)) {
                $cursor = new DateTimeImmutable($firstDate);
                $last = new DateTimeImmutable($lastDate);
                while ($cursor <= $last) {
                    $payload['dates'][] = $cursor->format('Y-m-d');
                    $cursor = $cursor->modify('+1 day');
                }
            }

            $trips[] = $payload;
        }

        return $trips;
    }

    public function suggestT0Km(int $vehicleId, string $date): ?array
    {
        if ($vehicleId <= 0 || !$this->isValidDate($date)) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT id, nr_inmatriculare, marca, model, COALESCE(km_bord, 0) AS km_bord
            FROM vehicule
            WHERE id = :vehicle_id
            LIMIT 1
        ");
        $stmt->bindValue(':vehicle_id', $vehicleId, PDO::PARAM_INT);
        $stmt->execute();
        $vehicle = $stmt->fetch();
        if (!$vehicle) {
            return null;
        }

        $tripsStmt = $this->db->prepare("
            SELECT COUNT(*) AS trip_count,
                   COALESCE(SUM(
                       CASE
                           WHEN c.km_totali IS NOT NULL AND c.km_totali > 0 THEN c.km_totali
                           WHEN c.km_cursa IS NOT NULL AND c.km_cursa > 0 THEN c.km_cursa
                           ELSE 0
                       END
                   ), 0) AS later_km
            FROM curse_dispecer c
            WHERE c.vehicle_id = :vehicle_id
              AND c.data_sfarsit >= :selected_date
        ");
        $tripsStmt->bindValue(':vehicle_id', $vehicleId, PDO::PARAM_INT);
        $tripsStmt->bindValue(':selected_date', $date);
        $tripsStmt->execute();
        $aggregate = $tripsStmt->fetch() ?: ['trip_count' => 0, 'later_km' => 0];

        $currentKm = max(0, (int) ($vehicle['km_bord'] ?? 0));
        $laterKm = max(0, (int) round((float) ($aggregate['later_km'] ?? 0)));
        $suggestedKm = max(0, $currentKm - $laterKm);

        $end = (new DateTimeImmutable($date))->modify('last day of this month')->format('Y-m-d');
        $monthTripStmt = $this->db->prepare("
            SELECT c.*, b.nume AS beneficiar_nume, s.nume AS sofer_nume,
                   li.nume AS loc_incarcare_nume, zd.nume AS zona_distributie_nume
            FROM curse_dispecer c
            LEFT JOIN configurare_beneficiari_transport b ON b.id = c.beneficiar_id
            LEFT JOIN soferi s ON s.id = c.driver_id
            LEFT JOIN configurare_locuri_incarcare li ON li.id = c.loc_incarcare_id
            LEFT JOIN configurare_zone_distributie zd ON zd.id = c.zona_distributie_id
            WHERE c.vehicle_id = :vehicle_id
              AND c.data_inceput <= :month_end
              AND c.data_sfarsit >= :selected_date
            ORDER BY c.data_inceput ASC, c.id ASC
            LIMIT 1
        ");
        $monthTripStmt->bindValue(':vehicle_id', $vehicleId, PDO::PARAM_INT);
        $monthTripStmt->bindValue(':selected_date', $date);
        $monthTripStmt->bindValue(':month_end', $end);
        $monthTripStmt->execute();
        $firstTrip = $monthTripStmt->fetch();

        return [
            'vehicle_id' => $vehicleId,
            'date' => $date,
            'current_km' => $currentKm,
            'later_trip_count' => (int) ($aggregate['trip_count'] ?? 0),
            'later_km' => $laterKm,
            'suggested_km' => $suggestedKm,
            'first_month_trip' => $firstTrip ? $this->formatTripPayload($firstTrip) : null,
            'source' => $laterKm > 0 ? 'vehicle_km_minus_later_dispecer_trips' : 'vehicle_km',
        ];
    }

    public function suggestRefuelKm(int $vehicleId, string $date, int $tripId = 0): ?array
    {
        if ($vehicleId <= 0 || !$this->isValidDate($date)) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT id, nr_inmatriculare, marca, model, COALESCE(km_bord, 0) AS km_bord
            FROM vehicule
            WHERE id = :vehicle_id
            LIMIT 1
        ");
        $stmt->bindValue(':vehicle_id', $vehicleId, PDO::PARAM_INT);
        $stmt->execute();
        $vehicle = $stmt->fetch();
        if (!$vehicle) {
            return null;
        }

        $trip = null;
        if ($tripId > 0) {
            $tripStmt = $this->db->prepare("
                SELECT c.*, b.nume AS beneficiar_nume, s.nume AS sofer_nume,
                       li.nume AS loc_incarcare_nume, zd.nume AS zona_distributie_nume
                FROM curse_dispecer c
                LEFT JOIN configurare_beneficiari_transport b ON b.id = c.beneficiar_id
                LEFT JOIN soferi s ON s.id = c.driver_id
                LEFT JOIN configurare_locuri_incarcare li ON li.id = c.loc_incarcare_id
                LEFT JOIN configurare_zone_distributie zd ON zd.id = c.zona_distributie_id
                WHERE c.id = :trip_id
                  AND c.vehicle_id = :vehicle_id
                LIMIT 1
            ");
            $tripStmt->bindValue(':trip_id', $tripId, PDO::PARAM_INT);
            $tripStmt->bindValue(':vehicle_id', $vehicleId, PDO::PARAM_INT);
            $tripStmt->execute();
            $trip = $tripStmt->fetch() ?: null;
        }
        if ($trip === null) {
            $detectStmt = $this->db->prepare("
                SELECT c.*, b.nume AS beneficiar_nume, s.nume AS sofer_nume,
                       li.nume AS loc_incarcare_nume, zd.nume AS zona_distributie_nume
                FROM curse_dispecer c
                LEFT JOIN configurare_beneficiari_transport b ON b.id = c.beneficiar_id
                LEFT JOIN soferi s ON s.id = c.driver_id
                LEFT JOIN configurare_locuri_incarcare li ON li.id = c.loc_incarcare_id
                LEFT JOIN configurare_zone_distributie zd ON zd.id = c.zona_distributie_id
                WHERE c.vehicle_id = :vehicle_id
                  AND c.data_inceput <= :date_start
                  AND c.data_sfarsit >= :date_end
                ORDER BY c.data_inceput DESC, c.id DESC
                LIMIT 1
            ");
            $detectStmt->bindValue(':vehicle_id', $vehicleId, PDO::PARAM_INT);
            $detectStmt->bindValue(':date_start', $date);
            $detectStmt->bindValue(':date_end', $date);
            $detectStmt->execute();
            $trip = $detectStmt->fetch() ?: null;
        }

        $cutoffDate = $date;
        if ($trip !== null && $this->isValidDate((string) ($trip['data_sfarsit'] ?? ''))) {
            $cutoffDate = (string) $trip['data_sfarsit'];
        }

        $tripsStmt = $this->db->prepare("
            SELECT COUNT(*) AS trip_count,
                   COALESCE(SUM(
                       CASE
                           WHEN c.km_totali IS NOT NULL AND c.km_totali > 0 THEN c.km_totali
                           WHEN c.km_cursa IS NOT NULL AND c.km_cursa > 0 THEN c.km_cursa
                           ELSE 0
                       END
                   ), 0) AS later_km
            FROM curse_dispecer c
            WHERE c.vehicle_id = :vehicle_id
              AND c.data_sfarsit > :cutoff_date
        ");
        $tripsStmt->bindValue(':vehicle_id', $vehicleId, PDO::PARAM_INT);
        $tripsStmt->bindValue(':cutoff_date', $cutoffDate);
        $tripsStmt->execute();
        $aggregate = $tripsStmt->fetch() ?: ['trip_count' => 0, 'later_km' => 0];

        $currentKm = max(0, (int) ($vehicle['km_bord'] ?? 0));
        $laterKm = max(0, (int) round((float) ($aggregate['later_km'] ?? 0)));
        $suggestedKm = max(0, $currentKm - $laterKm);
        $tripPayload = $trip !== null ? $this->formatTripPayload($trip) : null;

        return [
            'vehicle_id' => $vehicleId,
            'date' => $date,
            'cutoff_date' => $cutoffDate,
            'current_km' => $currentKm,
            'later_trip_count' => (int) ($aggregate['trip_count'] ?? 0),
            'later_km' => $laterKm,
            'suggested_km' => $suggestedKm,
            'detected_trip' => $tripPayload,
            'detected_trip_km' => $trip !== null ? $this->effectiveTripKm($trip) : 0,
            'source' => $laterKm > 0 ? 'vehicle_km_minus_later_dispecer_trips' : 'vehicle_km',
        ];
    }

    public function getTripById(int $tripId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM curse_dispecer WHERE id = :id');
        $stmt->bindValue(':id', $tripId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row ?: null;
    }

    private function getRecords(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildRecordWhere($filters);
        $orderBy = $this->sortSql((string) $filters['sort']);
        $limitSql = $limit > 0 ? " LIMIT " . (int) $limit . " OFFSET " . (int) $offset : '';

        $stmt = $this->db->prepare($this->baseRecordSql() . " {$where} {$orderBy} {$limitSql}");
        $this->bindParams($stmt, $params);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row = $this->decorateRecord($row);
        }
        unset($row);

        return $rows;
    }

    private function countRecords(array $filters): int
    {
        [$where, $params] = $this->buildRecordWhere($filters);
        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM alimentari a
            LEFT JOIN curse_dispecer c ON c.id = a.cursa_id
            {$where}
        ");
        $this->bindParams($stmt, $params);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    private function getTimelineRows(array $filters): array
    {
        [$start, $end] = $this->getPeriodRange($filters);
        $params = [':start' => $start, ':end' => $end];
        $where = "WHERE a.data_alimentare BETWEEN :start AND :end";
        if ((int) $filters['vehicle_id'] > 0) {
            $where .= ' AND a.vehicle_id = :vehicle_id';
            $params[':vehicle_id'] = (int) $filters['vehicle_id'];
        }

        $stmt = $this->db->prepare($this->baseRecordSql() . " {$where} ORDER BY a.vehicle_id ASC, a.data_alimentare ASC, a.id ASC");
        $this->bindParams($stmt, $params);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row = $this->decorateRecord($row);
        }
        unset($row);

        return $rows;
    }

    private function attachCalculations(array $rows, array $timelineRows): array
    {
        $timelineByVehicle = [];
        $hasT0ByVehicle = [];
        foreach ($timelineRows as $timelineRow) {
            $vehicleId = (int) $timelineRow['vehicle_id'];
            $timelineByVehicle[$vehicleId][] = $timelineRow;
            if ((string) $timelineRow['tip_inregistrare'] === self::RECORD_T0) {
                $hasT0ByVehicle[$vehicleId] = true;
            }
        }

        $previousKmByRecord = [];
        foreach ($timelineByVehicle as $vehicleId => $vehicleRows) {
            $previousKm = null;
            foreach ($vehicleRows as $timelineRow) {
                $recordId = (int) $timelineRow['id'];
                $previousKmByRecord[$recordId] = $previousKm;
                $currentKm = (int) ($timelineRow['km_bord'] ?? 0);
                if ($currentKm > 0) {
                    $previousKm = $currentKm;
                }
            }
        }

        foreach ($rows as &$row) {
            $row = array_merge($row, $this->emptyCalculation());
            $vehicleId = (int) $row['vehicle_id'];
            if ((string) $row['tip_inregistrare'] !== self::RECORD_REFUEL || empty($hasT0ByVehicle[$vehicleId])) {
                continue;
            }

            $previousKm = $previousKmByRecord[(int) $row['id']] ?? null;
            $currentKm = (int) ($row['km_bord'] ?? 0);
            if ($previousKm === null || $currentKm <= $previousKm) {
                continue;
            }

            $distance = $currentKm - (int) $previousKm;
            $liters = (float) ($row['litri'] ?? 0);
            $norm = (float) ($row['consum_mediu'] ?? 0);
            $normLiters = $norm > 0 ? ($distance * $norm / 100) : 0.0;
            $realConsumption = $distance > 0 ? ($liters / $distance * 100) : null;
            $row['km_parcursi_calcul'] = $distance;
            $row['consum_calculat'] = $realConsumption;
            $row['consum_normat'] = $norm > 0 ? $norm : null;
            $row['consum_normat_litri'] = $normLiters;
            $row['diferenta_litri'] = $norm > 0 ? ($liters - $normLiters) : null;
        }
        unset($row);

        return $rows;
    }

    private function buildKpis(array $rows, array $filters, array $missingT0): array
    {
        $normalRows = array_values(array_filter($rows, static fn (array $row): bool => (string) $row['tip_inregistrare'] === self::RECORD_REFUEL));
        $totalLiters = array_sum(array_map(static fn (array $row): float => (float) $row['litri'], $normalRows));
        $totalCost = array_sum(array_map(static fn (array $row): float => (float) $row['cost_total'], $normalRows));
        $totalKm = array_sum(array_map(static fn (array $row): float => (float) ($row['km_parcursi_calcul'] ?? 0), $normalRows));
        $diff = 0.0;
        $hasDiff = false;
        $vehicles = [];
        foreach ($normalRows as $row) {
            $vehicles[(int) $row['vehicle_id']] = true;
            if ($row['diferenta_litri'] !== null) {
                $diff += (float) $row['diferenta_litri'];
                $hasDiff = true;
            }
        }

        $vehicleTotal = count($this->getVehicleOptions());
        return [
            'total_liters' => $totalLiters,
            'total_cost' => $totalCost,
            'fleet_consumption' => $totalKm > 0 ? ($totalLiters / $totalKm * 100) : null,
            'vehicles_refueled' => count($vehicles),
            'vehicles_total' => $vehicleTotal,
            'vehicles_missing_t0' => count($missingT0),
            'norm_diff' => $hasDiff ? $diff : null,
            'month_label' => $this->monthName((int) $filters['month']) . ' ' . (int) $filters['year'],
        ];
    }

    private function buildTransportMetrics(array $rows): array
    {
        $metrics = [];
        foreach (['primar', 'distributie', 'primar_distributie', 'compresor'] as $key) {
            $metrics[$key] = [
                'key' => $key,
                'label' => self::TRANSPORT_LABELS[$key],
                'liters' => 0.0,
                'km' => 0.0,
                'diff' => 0.0,
                'has_diff' => false,
                'consumption' => null,
            ];
        }

        foreach ($rows as $row) {
            if ((string) $row['tip_inregistrare'] !== self::RECORD_REFUEL) {
                continue;
            }
            $transport = (string) ($row['tip_transport'] ?? '');
            if ($transport === 'primar_tona') {
                $transport = 'primar';
            }
            if (!isset($metrics[$transport])) {
                continue;
            }

            $metrics[$transport]['liters'] += (float) ($row['litri'] ?? 0);
            $metrics[$transport]['km'] += (float) ($row['km_parcursi_calcul'] ?? 0);
            if ($row['diferenta_litri'] !== null) {
                $metrics[$transport]['diff'] += (float) $row['diferenta_litri'];
                $metrics[$transport]['has_diff'] = true;
            }
        }

        foreach ($metrics as &$metric) {
            $metric['consumption'] = $metric['km'] > 0 ? ($metric['liters'] / $metric['km'] * 100) : null;
            $metric['diff'] = $metric['has_diff'] ? $metric['diff'] : null;
        }
        unset($metric);

        return $metrics;
    }

    public function getVehiclesMissingT0(array $filters): array
    {
        [$start, $end] = $this->getPeriodRange($filters);
        $params = [':start' => $start, ':end' => $end];
        $where = "WHERE c.data_inceput <= :end AND c.data_sfarsit >= :start";
        if ((int) $filters['vehicle_id'] > 0) {
            $where .= ' AND c.vehicle_id = :vehicle_id';
            $params[':vehicle_id'] = (int) $filters['vehicle_id'];
        }
        if ((string) $filters['transport_type'] !== '') {
            $where .= ' AND c.tip_transport = :transport_type';
            $params[':transport_type'] = (string) $filters['transport_type'];
        }

        $stmt = $this->db->prepare("
            SELECT DISTINCT v.id, v.nr_inmatriculare, v.marca, v.model
            FROM curse_dispecer c
            INNER JOIN vehicule v ON v.id = c.vehicle_id
            {$where}
              AND NOT EXISTS (
                  SELECT 1
                  FROM alimentari t0
                  WHERE t0.vehicle_id = c.vehicle_id
                    AND t0.tip_inregistrare = 't0'
                    AND t0.data_alimentare BETWEEN :start_t0 AND :end_t0
              )
            ORDER BY v.nr_inmatriculare ASC
        ");
        $params[':start_t0'] = $start;
        $params[':end_t0'] = $end;
        $this->bindParams($stmt, $params);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    private function buildRecordWhere(array $filters): array
    {
        [$start, $end] = $this->getPeriodRange($filters);
        $where = ['a.data_alimentare BETWEEN :start AND :end'];
        $params = [':start' => $start, ':end' => $end];

        if ((int) $filters['vehicle_id'] > 0) {
            $where[] = 'a.vehicle_id = :vehicle_id';
            $params[':vehicle_id'] = (int) $filters['vehicle_id'];
        }
        if ((string) $filters['record_type'] !== '') {
            $where[] = 'a.tip_inregistrare = :record_type';
            $params[':record_type'] = (string) $filters['record_type'];
        }
        if ((string) $filters['supplier'] !== '') {
            $where[] = 'a.furnizor = :supplier';
            $params[':supplier'] = (string) $filters['supplier'];
        }
        if ((string) $filters['transport_type'] !== '') {
            $where[] = "(c.tip_transport = :transport_type OR (a.tip_inregistrare = 't0' AND EXISTS (
                SELECT 1 FROM curse_dispecer ct
                WHERE ct.vehicle_id = a.vehicle_id
                  AND ct.tip_transport = :transport_type_t0
                  AND ct.data_inceput <= :end_t0_transport
                  AND ct.data_sfarsit >= :start_t0_transport
            )))";
            $params[':transport_type'] = (string) $filters['transport_type'];
            $params[':transport_type_t0'] = (string) $filters['transport_type'];
            $params[':start_t0_transport'] = $start;
            $params[':end_t0_transport'] = $end;
        }
        if ((string) $filters['trip_filter'] === 'with_trip') {
            $where[] = 'a.cursa_id IS NOT NULL';
        } elseif ((string) $filters['trip_filter'] === 'without_trip') {
            $where[] = 'a.tip_inregistrare = :trip_refuel_type_without AND a.cursa_id IS NULL';
            $params[':trip_refuel_type_without'] = self::RECORD_REFUEL;
        }

        return ['WHERE ' . implode(' AND ', $where), $params];
    }

    private function baseRecordSql(): string
    {
        return "
            SELECT a.*,
                   v.nr_inmatriculare, v.marca, v.model, v.consum_mediu, v.capacitate_rezervor,
                   sr.nume AS record_sofer_nume,
                   c.tip_transport, c.data_inceput, c.data_sfarsit, c.loc_plecare, c.loc_aspirare,
                   c.loc_livrare, c.loc_livrare_cursa, c.km_cursa, c.km_totali, c.km_dislocare,
                   b.nume AS beneficiar_nume,
                   st.nume AS trip_sofer_nume,
                   li.nume AS loc_incarcare_nume,
                   zd.nume AS zona_distributie_nume
            FROM alimentari a
            INNER JOIN vehicule v ON v.id = a.vehicle_id
            LEFT JOIN soferi sr ON sr.id = a.driver_id
            LEFT JOIN curse_dispecer c ON c.id = a.cursa_id
            LEFT JOIN soferi st ON st.id = c.driver_id
            LEFT JOIN configurare_beneficiari_transport b ON b.id = c.beneficiar_id
            LEFT JOIN configurare_locuri_incarcare li ON li.id = c.loc_incarcare_id
            LEFT JOIN configurare_zone_distributie zd ON zd.id = c.zona_distributie_id
        ";
    }

    private function decorateRecord(array $row): array
    {
        $row['transport_label'] = self::TRANSPORT_LABELS[(string) ($row['tip_transport'] ?? '')] ?? '-';
        $row['interval_label'] = $this->intervalLabel($row);
        $row['route_label'] = $this->routeLabel($row);
        $row['driver_label'] = (string) ($row['trip_sofer_nume'] ?? $row['record_sofer_nume'] ?? '');
        $row['driver_label'] = trim($row['driver_label']) !== '' ? $row['driver_label'] : '-';
        $row['beneficiar_label'] = trim((string) ($row['beneficiar_nume'] ?? '')) !== '' ? (string) $row['beneficiar_nume'] : '-';
        if ((string) ($row['tip_inregistrare'] ?? '') === self::RECORD_T0 && (string) ($row['tip_transport'] ?? '') === '') {
            $firstTrip = $this->firstTripForVehicleMonth((int) $row['vehicle_id'], (string) $row['data_alimentare']);
            if ($firstTrip !== null) {
                $row['tip_transport'] = (string) $firstTrip['tip_transport'];
                $row['transport_label'] = self::TRANSPORT_LABELS[(string) $firstTrip['tip_transport']] ?? '-';
                $row['beneficiar_label'] = trim((string) ($firstTrip['beneficiar_nume'] ?? '')) !== '' ? (string) $firstTrip['beneficiar_nume'] : '-';
                $row['driver_label'] = trim((string) ($firstTrip['sofer_nume'] ?? '')) !== '' ? (string) $firstTrip['sofer_nume'] : '-';
            }
        }

        return $row;
    }

    private function firstTripForVehicleMonth(int $vehicleId, string $date): ?array
    {
        if ($vehicleId <= 0 || !$this->isValidDate($date)) {
            return null;
        }
        $start = (new DateTimeImmutable($date))->modify('first day of this month')->format('Y-m-d');
        $end = (new DateTimeImmutable($date))->modify('last day of this month')->format('Y-m-d');
        $stmt = $this->db->prepare("
            SELECT c.tip_transport, b.nume AS beneficiar_nume, s.nume AS sofer_nume
            FROM curse_dispecer c
            LEFT JOIN configurare_beneficiari_transport b ON b.id = c.beneficiar_id
            LEFT JOIN soferi s ON s.id = c.driver_id
            WHERE c.vehicle_id = :vehicle_id
              AND c.data_inceput <= :end_date
              AND c.data_sfarsit >= :start_date
            ORDER BY c.data_inceput ASC, c.id ASC
            LIMIT 1
        ");
        $stmt->bindValue(':vehicle_id', $vehicleId, PDO::PARAM_INT);
        $stmt->bindValue(':start_date', $start);
        $stmt->bindValue(':end_date', $end);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row ?: null;
    }

    private function formatTripPayload(array $trip): array
    {
        return [
            'id' => (int) $trip['id'],
            'interval' => $this->intervalLabel($trip),
            'tip_transport' => (string) ($trip['tip_transport'] ?? ''),
            'tip_transport_label' => self::TRANSPORT_LABELS[(string) ($trip['tip_transport'] ?? '')] ?? '-',
            'beneficiar' => (string) ($trip['beneficiar_nume'] ?? '-'),
            'sofer' => (string) ($trip['sofer_nume'] ?? '-'),
            'traseu' => $this->routeLabel($trip),
            'km_efectivi' => $this->effectiveTripKm($trip),
        ];
    }

    private function effectiveTripKm(array $trip): int
    {
        $kmTotal = isset($trip['km_totali']) && $trip['km_totali'] !== null && $trip['km_totali'] !== ''
            ? max(0, (int) $trip['km_totali'])
            : 0;
        if ($kmTotal > 0) {
            return $kmTotal;
        }

        return isset($trip['km_cursa']) && $trip['km_cursa'] !== null && $trip['km_cursa'] !== ''
            ? max(0, (int) $trip['km_cursa'])
            : 0;
    }

    private function intervalLabel(array $row): string
    {
        $start = (string) ($row['data_inceput'] ?? '');
        $end = (string) ($row['data_sfarsit'] ?? '');
        if ($start === '' || $end === '') {
            return '-';
        }

        return (new DateTimeImmutable($start))->format('d.m') . ' - ' . (new DateTimeImmutable($end))->format('d.m');
    }

    private function routeLabel(array $row): string
    {
        $parts = [];
        foreach (['loc_incarcare_nume', 'loc_plecare', 'loc_aspirare', 'loc_livrare', 'loc_livrare_cursa', 'zona_distributie_nume'] as $field) {
            $value = trim((string) ($row[$field] ?? ''));
            if ($value !== '') {
                $parts[$value] = $value;
            }
        }

        return $parts !== [] ? implode(' → ', array_values($parts)) : '-';
    }

    private function sortSql(string $sort): string
    {
        return match ($sort) {
            'date_asc' => 'ORDER BY a.data_alimentare ASC, a.id ASC',
            'vehicle_asc' => 'ORDER BY v.nr_inmatriculare ASC, a.data_alimentare DESC, a.id DESC',
            'liters_desc' => 'ORDER BY a.litri DESC, a.data_alimentare DESC',
            'cost_desc' => 'ORDER BY a.cost_total DESC, a.data_alimentare DESC',
            default => 'ORDER BY a.data_alimentare DESC, a.id DESC',
        };
    }

    private function normalizeSort(string $value): string
    {
        return in_array($value, ['date_desc', 'date_asc', 'vehicle_asc', 'liters_desc', 'cost_desc'], true) ? $value : 'date_desc';
    }

    private function normalizeRecordType(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, [self::RECORD_REFUEL, self::RECORD_T0], true) ? $value : '';
    }

    private function normalizeTransportType(string $value): string
    {
        $value = strtolower(trim($value));
        return array_key_exists($value, self::TRANSPORT_LABELS) ? $value : '';
    }

    private function normalizeTripFilter(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, ['with_trip', 'without_trip'], true) ? $value : '';
    }

    private function calculationOnly(array $row): array
    {
        return [
            'km_parcursi_calcul' => $row['km_parcursi_calcul'] ?? null,
            'consum_calculat' => $row['consum_calculat'] ?? null,
            'consum_normat' => $row['consum_normat'] ?? null,
            'consum_normat_litri' => $row['consum_normat_litri'] ?? null,
            'diferenta_litri' => $row['diferenta_litri'] ?? null,
        ];
    }

    private function emptyCalculation(): array
    {
        return [
            'km_parcursi_calcul' => null,
            'consum_calculat' => null,
            'consum_normat' => null,
            'consum_normat_litri' => null,
            'diferenta_litri' => null,
        ];
    }

    private function monthName(int $month): string
    {
        $names = [
            1 => 'Ianuarie',
            2 => 'Februarie',
            3 => 'Martie',
            4 => 'Aprilie',
            5 => 'Mai',
            6 => 'Iunie',
            7 => 'Iulie',
            8 => 'August',
            9 => 'Septembrie',
            10 => 'Octombrie',
            11 => 'Noiembrie',
            12 => 'Decembrie',
        ];

        return $names[$month] ?? (string) $month;
    }

    private function hasT0ForVehicleMonth(int $vehicleId, string $date, int $excludeId = 0): bool
    {
        $start = (new DateTimeImmutable($date))->modify('first day of this month')->format('Y-m-d');
        $end = (new DateTimeImmutable($date))->modify('last day of this month')->format('Y-m-d');
        $sql = "
            SELECT COUNT(*)
            FROM alimentari
            WHERE vehicle_id = :vehicle_id
              AND tip_inregistrare = 't0'
              AND data_alimentare BETWEEN :start_date AND :end_date
        ";
        if ($excludeId > 0) {
            $sql .= ' AND id <> :exclude_id';
        }
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':vehicle_id', $vehicleId, PDO::PARAM_INT);
        $stmt->bindValue(':start_date', $start);
        $stmt->bindValue(':end_date', $end);
        if ($excludeId > 0) {
            $stmt->bindValue(':exclude_id', $excludeId, PDO::PARAM_INT);
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn() > 0;
    }

    private function vehicleExists(int $vehicleId): bool
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM vehicule WHERE id = :id");
        $stmt->bindValue(':id', $vehicleId, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn() > 0;
    }

    private function getVehicleReservoirCapacity(int $vehicleId): ?float
    {
        if ($vehicleId <= 0) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT capacitate_rezervor FROM vehicule WHERE id = :id LIMIT 1");
        $stmt->bindValue(':id', $vehicleId, PDO::PARAM_INT);
        $stmt->execute();
        $value = $stmt->fetchColumn();
        if ($value === false || $value === null || $value === '') {
            return null;
        }

        return max(0.0, (float) $value);
    }

    private function normalizeDecimal(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }
        $value = trim(str_replace(',', '.', (string) $value));
        if ($value === '' || !is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function isValidDate(string $date): bool
    {
        $dateTime = DateTime::createFromFormat('!Y-m-d', $date);
        return $dateTime !== false && $dateTime->format('Y-m-d') === $date;
    }

    private function nullIfEmpty(string $value): ?string
    {
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private function bindParams(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }

    private function bindPayload(PDOStatement $stmt, array $payload): void
    {
        foreach ($payload as $key => $value) {
            if ($value === null) {
                $stmt->bindValue($key, null, PDO::PARAM_NULL);
            } elseif (is_int($value)) {
                $stmt->bindValue($key, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($key, $value);
            }
        }
    }

    private function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND COLUMN_NAME = :column_name
        ");
        $stmt->execute([':table_name' => $table, ':column_name' => $column]);
        if ((int) $stmt->fetchColumn() === 0) {
            $this->db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    }

    private function addIndexIfMissing(string $table, string $index, string $sql): void
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND INDEX_NAME = :index_name
        ");
        $stmt->execute([':table_name' => $table, ':index_name' => $index]);
        if ((int) $stmt->fetchColumn() === 0) {
            $this->db->exec($sql);
        }
    }

    private function deleteInvoiceFile(string $stored): void
    {
        $stored = basename(trim($stored));
        if ($stored === '') {
            return;
        }
        $path = BASE_PATH . '/uploads/alimentari_facturi/' . $stored;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
