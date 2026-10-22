<?php
declare(strict_types=1);

/**
 * Centru Kilometraj — accesul la date.
 *
 * Refoloseste ce exista deja:
 *   - vehicule (marca, model, poza_stocata, tip_vehicul, capacitate_transport = capacitatea REALA,
 *     categorie_capacitate_id = eticheta de grupare) si vehicule_categorii_capacitate (ordinea configurata);
 *   - vehicule_cuplaje (ultimul cuplaj activ per cap tractor, ca in Dispecer curse);
 *   - fuel_fillups pentru CardOil (vehicle_registration si odometer_km sunt valorile EFECTIVE,
 *     deja corectate manual in Carburanti) si fuel_sync_logs pentru starea sincronizarii.
 * Tabelele proprii: database/migrations/2026_10_22_000002_centru_kilometraj.sql (recreate aici la rulare).
 */
class OdometerCenterModel extends BaseModel
{
    public const DEFAULT_SETTINGS = [
        // '' = neconfigurata (nu se alege nicio sursa), 'sas', 'cardoil', 'sas_cardoil' (SAS, altfel CardOil)
        'politica_total' => '',
        'sas_toleranta_minute' => '15',
        'sas_prospetime_ore' => '2',
        'sas_istoric_zile' => '31',
        'cardoil_varsta_max_ore' => '72',
        'cardoil_sync_max_ore' => '26',
    ];

    private bool $schemaEnsured = false;

    public function ensureSchema(): void
    {
        if ($this->schemaEnsured) {
            return;
        }
        $statements = [
            "CREATE TABLE IF NOT EXISTS vehicle_odometer_sources (
                vehicle_id INT UNSIGNED NOT NULL,
                provider_code ENUM('sas','cardoil') NOT NULL,
                external_provider_vehicle_id VARCHAR(60) NOT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                last_checked_at DATETIME NULL,
                last_status VARCHAR(20) NULL,
                last_error VARCHAR(500) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (vehicle_id, provider_code),
                KEY idx_odo_sources_external (provider_code, external_provider_vehicle_id),
                CONSTRAINT fk_odo_sources_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicule(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS vehicle_odometer_readings (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                vehicle_id INT UNSIGNED NOT NULL,
                provider_code ENUM('sas','cardoil') NOT NULL,
                source_reading_id VARCHAR(80) NOT NULL,
                segment_started_at DATETIME NULL,
                measured_at DATETIME NOT NULL,
                received_at DATETIME NOT NULL,
                odometer_km DECIMAL(12,1) NOT NULL,
                original_value DECIMAL(16,4) NOT NULL,
                original_unit VARCHAR(8) NOT NULL DEFAULT 'km',
                measurement_type VARCHAR(30) NOT NULL,
                validation_status ENUM('valid','anomaly') NOT NULL DEFAULT 'valid',
                validation_note VARCHAR(255) NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uk_odo_reading_source (provider_code, vehicle_id, source_reading_id),
                KEY idx_odo_reading_time (vehicle_id, provider_code, measured_at),
                CONSTRAINT fk_odo_readings_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicule(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS vehicle_odometer_baselines (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                vehicle_id INT UNSIGNED NOT NULL,
                provider_code ENUM('sas','cardoil') NOT NULL,
                requested_t0_at DATETIME NOT NULL,
                measured_t0_at DATETIME NULL,
                baseline_value_km DECIMAL(12,1) NULL,
                original_value DECIMAL(16,4) NULL,
                original_unit VARCHAR(8) NULL,
                measurement_type VARCHAR(30) NULL,
                source_reading_id VARCHAR(80) NULL,
                retrieval_status VARCHAR(24) NOT NULL,
                status_note VARCHAR(255) NULL,
                baseline_version INT UNSIGNED NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                superseded_at DATETIME NULL,
                UNIQUE KEY uk_odo_baseline_version (vehicle_id, provider_code, baseline_version),
                KEY idx_odo_baseline_vehicle (vehicle_id, provider_code, is_active),
                CONSTRAINT fk_odo_baselines_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicule(id) ON DELETE CASCADE,
                CONSTRAINT fk_odo_baselines_user FOREIGN KEY (created_by) REFERENCES utilizatori(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS vehicle_odometer_audit (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                vehicle_id INT UNSIGNED NULL,
                user_id INT UNSIGNED NULL,
                action VARCHAR(40) NOT NULL,
                old_values LONGTEXT NULL,
                new_values LONGTEXT NULL,
                reason VARCHAR(255) NULL,
                created_at DATETIME NOT NULL,
                KEY idx_odo_audit_vehicle (vehicle_id, created_at),
                CONSTRAINT fk_odo_audit_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicule(id) ON DELETE SET NULL,
                CONSTRAINT fk_odo_audit_user FOREIGN KEY (user_id) REFERENCES utilizatori(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS km_center_setari (
                setting_key VARCHAR(60) NOT NULL PRIMARY KEY,
                setting_value VARCHAR(255) NOT NULL,
                updated_by INT UNSIGNED NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
        foreach ($statements as $sql) {
            $this->db->exec($sql);
        }
        $this->ensureVehicleCapacityCategorySchema();
        $this->schemaEnsured = true;
    }

    // ---------------------------------------------------------------- setari

    /** @return array<string, string> */
    public function getSettings(): array
    {
        $this->ensureSchema();
        $settings = self::DEFAULT_SETTINGS;
        foreach ($this->db->query('SELECT setting_key, setting_value FROM km_center_setari')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (array_key_exists((string) $row['setting_key'], $settings)) {
                $settings[(string) $row['setting_key']] = (string) $row['setting_value'];
            }
        }

        return $settings;
    }

    public function saveSetting(string $key, string $value, ?int $userId): void
    {
        if (!array_key_exists($key, self::DEFAULT_SETTINGS)) {
            throw new InvalidArgumentException('Setare necunoscută: ' . $key);
        }
        $this->ensureSchema();
        $stmt = $this->db->prepare(
            'INSERT INTO km_center_setari (setting_key, setting_value, updated_by) VALUES (:k, :v, :u)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)'
        );
        $stmt->execute([':k' => $key, ':v' => $value, ':u' => $userId]);
    }

    // ---------------------------------------------------------------- vehicule

    /**
     * Toate vehiculele flotei (fara pseudo-vehiculul stocului de anvelope), cu categoria
     * de capacitate in ordinea configurata. Capacitatea reala si categoria raman separate.
     *
     * @return array<int, array<string, mixed>> indexat dupa id
     */
    public function getVehicles(): array
    {
        $this->ensureSchema();
        $rows = $this->db->query("
            SELECT v.id, v.nr_inmatriculare, v.marca, v.model, v.tip_vehicul, v.status, v.garaj,
                   v.poza_stocata, v.capacitate_transport, v.capacitate_transport_confirmata,
                   v.categorie_capacitate_id, c.nume AS categorie_nume, c.ordine_afisare AS categorie_ordine,
                   c.activ AS categorie_activa
            FROM vehicule v
            LEFT JOIN vehicule_categorii_capacitate c ON c.id = v.categorie_capacitate_id
            WHERE v.nr_inmatriculare <> 'STOC-ANVELOPE'
              AND COALESCE(v.serie_sasiu, '') <> 'STOCANVELOPE00001'
            ORDER BY v.nr_inmatriculare ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $vehicles = [];
        foreach ($rows as $row) {
            $vehicles[(int) $row['id']] = $row;
        }

        return $vehicles;
    }

    /**
     * Ultimul cuplaj activ per cap tractor (aceeasi regula ca Dispecer curse si
     * Categorii capacitate): tractor_id => semiremorca_id.
     *
     * @return array<int, int>
     */
    public function getActiveCouplings(): array
    {
        $rows = $this->db->query("
            SELECT vc1.tractor_id, vc1.semiremorca_id
            FROM vehicule_cuplaje vc1
            INNER JOIN (
                SELECT tractor_id, MAX(id) AS max_id
                FROM vehicule_cuplaje
                WHERE activ = 1
                GROUP BY tractor_id
            ) latest ON latest.max_id = vc1.id
        ")->fetchAll(PDO::FETCH_ASSOC);

        $couplings = [];
        foreach ($rows as $row) {
            $couplings[(int) $row['tractor_id']] = (int) $row['semiremorca_id'];
        }

        return $couplings;
    }

    // ---------------------------------------------------------------- surse

    /** @return array<int, array<string, array<string, mixed>>> [vehicle_id][provider] */
    public function getSources(): array
    {
        $this->ensureSchema();
        $sources = [];
        foreach ($this->db->query('SELECT * FROM vehicle_odometer_sources')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sources[(int) $row['vehicle_id']][(string) $row['provider_code']] = $row;
        }

        return $sources;
    }

    /**
     * Maparea vehicul -> identificatorul la furnizor. Se actualizeaza doar
     * identificatorul (o mapare dezactivata manual ramane dezactivata).
     */
    public function upsertSource(int $vehicleId, string $provider, string $externalId): void
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'INSERT INTO vehicle_odometer_sources (vehicle_id, provider_code, external_provider_vehicle_id, created_at, updated_at)
             VALUES (:v, :p, :e, :c, :u)
             ON DUPLICATE KEY UPDATE
                updated_at = IF(external_provider_vehicle_id = VALUES(external_provider_vehicle_id), updated_at, VALUES(updated_at)),
                external_provider_vehicle_id = VALUES(external_provider_vehicle_id)'
        );
        $stmt->execute([':v' => $vehicleId, ':p' => $provider, ':e' => $externalId, ':c' => $now, ':u' => $now]);
    }

    /** Vehiculele care nu mai apar la furnizor raman in tabel, dar dezactivate. */
    public function disableMissingSources(string $provider, array $keepVehicleIds): void
    {
        $keep = array_values(array_unique(array_map('intval', $keepVehicleIds)));
        $sql = 'UPDATE vehicle_odometer_sources SET enabled = 0, updated_at = :u WHERE provider_code = :p AND enabled = 1';
        $params = [':u' => date('Y-m-d H:i:s'), ':p' => $provider];
        if ($keep !== []) {
            $sql .= ' AND vehicle_id NOT IN (' . implode(',', $keep) . ')';
        }
        $this->db->prepare($sql)->execute($params);
        if ($keep !== []) {
            $this->db->prepare(
                'UPDATE vehicle_odometer_sources SET enabled = 1 WHERE provider_code = :p AND enabled = 0
                 AND vehicle_id IN (' . implode(',', $keep) . ')'
            )->execute([':p' => $provider]);
        }
    }

    public function markSourceChecked(int $vehicleId, string $provider, string $status, ?string $error, ?string $checkedAt = null): void
    {
        $stmt = $this->db->prepare(
            'UPDATE vehicle_odometer_sources
             SET last_status = :s, last_error = :e,
                 last_checked_at = IF(:s2 = \'ok\', :t, last_checked_at), updated_at = :u
             WHERE vehicle_id = :v AND provider_code = :p'
        );
        $now = date('Y-m-d H:i:s');
        $stmt->execute([
            ':s' => $status, ':s2' => $status, ':e' => $error !== null ? mb_substr($error, 0, 500) : null,
            ':t' => $checkedAt ?? $now, ':u' => $now, ':v' => $vehicleId, ':p' => $provider,
        ]);
    }

    // ---------------------------------------------------------------- citiri SAS

    /**
     * Salveaza citirile normalizate (idempotent: aceeasi citire de la furnizor nu se
     * dubleaza si nu se rescrie). Intoarce cate citiri noi au intrat.
     *
     * @param list<array<string, mixed>> $readings
     */
    public function storeReadings(int $vehicleId, string $provider, array $readings): int
    {
        if ($readings === []) {
            return 0;
        }
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO vehicle_odometer_readings
                (vehicle_id, provider_code, source_reading_id, segment_started_at, measured_at, received_at,
                 odometer_km, original_value, original_unit, measurement_type, validation_status, created_at)
             VALUES (:v, :p, :sid, :ss, :m, :r, :km, :ov, :ou, :mt, \'valid\', :c)'
        );
        $inserted = 0;
        foreach ($readings as $reading) {
            $stmt->execute([
                ':v' => $vehicleId, ':p' => $provider, ':sid' => (string) $reading['source_reading_id'],
                ':ss' => $reading['segment_started_at'] ?? null, ':m' => $reading['measured_at'], ':r' => $now,
                ':km' => round((float) $reading['odometer_km'], 1), ':ov' => (float) $reading['original_value'],
                ':ou' => (string) ($reading['original_unit'] ?? 'km'), ':mt' => (string) $reading['measurement_type'], ':c' => $now,
            ]);
            $inserted += $stmt->rowCount();
        }

        return $inserted;
    }

    /**
     * Seria de citiri a unui vehicul / furnizor (cele mai recente $limit, intoarse crescator).
     *
     * @return list<array<string, mixed>>
     */
    public function getReadings(int $vehicleId, string $provider, ?string $from = null, ?string $to = null, int $limit = 2000): array
    {
        $where = ['vehicle_id = :v', 'provider_code = :p'];
        $params = [':v' => $vehicleId, ':p' => $provider];
        if ($from !== null) {
            $where[] = 'measured_at >= :f';
            $params[':f'] = $from;
        }
        if ($to !== null) {
            $where[] = 'measured_at <= :t';
            $params[':t'] = $to;
        }
        $stmt = $this->db->prepare(
            'SELECT id, source_reading_id, segment_started_at, measured_at, received_at, odometer_km,
                    original_value, original_unit, measurement_type, validation_status, validation_note
             FROM vehicle_odometer_readings WHERE ' . implode(' AND ', $where) . '
             ORDER BY measured_at DESC, id DESC LIMIT ' . max(1, min(20000, $limit))
        );
        $stmt->execute($params);

        return array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Citirea valida dinainte (<= $at) si cea de dupa $at, pentru odometrul la un
     * moment dat (comparatia surselor pe aceeasi fereastra). Crescator, maxim 2 randuri.
     *
     * @return list<array<string, mixed>>
     */
    public function getReadingsAround(int $vehicleId, string $provider, string $at): array
    {
        $before = $this->db->prepare(
            "SELECT source_reading_id AS id, segment_started_at, measured_at, odometer_km AS km FROM vehicle_odometer_readings
             WHERE vehicle_id = :v AND provider_code = :p AND measured_at <= :t AND validation_status = 'valid'
             ORDER BY measured_at DESC LIMIT 1"
        );
        $after = $this->db->prepare(
            "SELECT source_reading_id AS id, segment_started_at, measured_at, odometer_km AS km FROM vehicle_odometer_readings
             WHERE vehicle_id = :v AND provider_code = :p AND measured_at > :t AND validation_status = 'valid'
             ORDER BY measured_at ASC LIMIT 1"
        );
        $rows = [];
        foreach ([$before, $after] as $stmt) {
            $stmt->execute([':v' => $vehicleId, ':p' => $provider, ':t' => $at]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $row['km'] = (float) $row['km'];
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** Ultimele citiri SAS ale tuturor vehiculelor, cate $perVehicle per vehicul (crescator). */
    public function getRecentReadingsByVehicle(string $provider, int $perVehicle = 60): array
    {
        $stmt = $this->db->prepare(
            'SELECT vehicle_id, source_reading_id, segment_started_at, measured_at, odometer_km, validation_status, validation_note
             FROM (
                SELECT r.*, ROW_NUMBER() OVER (PARTITION BY vehicle_id ORDER BY measured_at DESC, id DESC) AS rn
                FROM vehicle_odometer_readings r WHERE provider_code = :p
             ) x WHERE rn <= :n ORDER BY vehicle_id, measured_at ASC'
        );
        $stmt->bindValue(':p', $provider);
        $stmt->bindValue(':n', $perVehicle, PDO::PARAM_INT);
        $stmt->execute();
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['vehicle_id']][] = $row;
        }

        return $result;
    }

    /** Marcheaza (sau curata) anomalia unei citiri salvate. */
    public function setReadingValidation(int $vehicleId, string $provider, string $sourceReadingId, ?string $note): void
    {
        $stmt = $this->db->prepare(
            'UPDATE vehicle_odometer_readings SET validation_status = :s, validation_note = :n
             WHERE vehicle_id = :v AND provider_code = :p AND source_reading_id = :sid
               AND (validation_status <> :s2 OR COALESCE(validation_note, \'\') <> COALESCE(:n2, \'\'))'
        );
        $status = $note === null ? 'valid' : 'anomaly';
        $stmt->execute([':s' => $status, ':s2' => $status, ':n' => $note, ':n2' => $note, ':v' => $vehicleId, ':p' => $provider, ':sid' => $sourceReadingId]);
    }

    // ---------------------------------------------------------------- CardOil

    /**
     * Alimentarile cu odometru, grupate pe numarul de inmatriculare normalizat
     * (doar litere si cifre, majuscule). Toate tipurile de carburant: fiecare e o citire
     * a bordului tastata la pompa. Valorile sunt cele efective (corectiile manuale
     * din Carburanti sunt deja aplicate in odometer_km / vehicle_registration).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function getCardOilFillups(?array $plateKeys = null, ?string $from = null): array
    {
        $where = ['f.odometer_km IS NOT NULL', 'f.odometer_km > 0', "f.source_type IN ('api', 'manual')"];
        $params = [];
        if ($from !== null) {
            $where[] = 'f.fillup_datetime >= :f';
            $params[':f'] = $from;
        }
        $stmt = $this->db->prepare(
            "SELECT f.id, f.vehicle_registration, f.fillup_datetime,
                    f.odometer_km, f.odometer_km_manual, f.fuel_type, f.quantity_liters, f.station_name, f.driver_name,
                    f.vehicle_registration_manual IS NOT NULL AS vehicle_corrected
             FROM fuel_fillups f
             WHERE " . implode(' AND ', $where) . "
             ORDER BY f.fillup_datetime ASC, f.id ASC"
        );
        $stmt->execute($params);
        $wanted = $plateKeys !== null ? array_flip($plateKeys) : null;
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            // Aceeasi cheie ca maparea vehiculelor: doar litere si cifre, majuscule.
            $key = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $row['vehicle_registration']) ?? '');
            if ($wanted !== null && !isset($wanted[$key])) {
                continue;
            }
            $result[$key][] = $row;
        }

        return $result;
    }

    /** Ultima sincronizare CardOil reusita (fuel_sync_logs, aceeasi ca in Carburanti). */
    public function getLastCardOilSync(): ?array
    {
        try {
            $row = $this->db->query(
                "SELECT sync_started_at, sync_finished_at, status, error_message FROM fuel_sync_logs
                 ORDER BY sync_started_at DESC, id DESC LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC);
            $success = $this->db->query(
                "SELECT MAX(COALESCE(sync_finished_at, sync_started_at)) FROM fuel_sync_logs WHERE status = 'success'"
            )->fetchColumn();
        } catch (Throwable) {
            return null;
        }
        if (!$row) {
            return null;
        }
        $row['last_success_at'] = $success ?: null;

        return $row;
    }

    // ---------------------------------------------------------------- T0

    /** @return array<int, array<string, array<string, mixed>>> [vehicle_id][provider] => baseline activ */
    public function getActiveBaselines(): array
    {
        $this->ensureSchema();
        $result = [];
        $rows = $this->db->query(
            'SELECT b.*, u.nume AS created_by_name FROM vehicle_odometer_baselines b
             LEFT JOIN utilizatori u ON u.id = b.created_by WHERE b.is_active = 1'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $result[(int) $row['vehicle_id']][(string) $row['provider_code']] = $row;
        }

        return $result;
    }

    /** Toate versiunile T0 ale unui vehicul, cele mai noi primele. */
    public function getBaselineHistory(int $vehicleId): array
    {
        $stmt = $this->db->prepare(
            'SELECT b.*, u.nume AS created_by_name FROM vehicle_odometer_baselines b
             LEFT JOIN utilizatori u ON u.id = b.created_by
             WHERE b.vehicle_id = :v ORDER BY b.baseline_version DESC, b.provider_code ASC'
        );
        $stmt->execute([':v' => $vehicleId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * O noua versiune T0 pentru un vehicul: cate un rand per furnizor (si cel fara
     * citire valida, cu motivul), versiunile vechi raman in istoric (is_active = 0).
     * Tranzactie proprie; numarul de versiune e unic, deci doua salvari simultane
     * nu pot lasa doua referinte active.
     *
     * @param array<string, array<string, mixed>> $results [provider] => rezultatul cautarii
     * @return int versiunea noua
     */
    public function saveBaselineVersion(int $vehicleId, string $requestedT0, array $results, ?int $userId, ?string $reason): int
    {
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $lock = $this->db->prepare('SELECT id FROM vehicule WHERE id = :v FOR UPDATE');
            $lock->execute([':v' => $vehicleId]);

            $versionStmt = $this->db->prepare('SELECT COALESCE(MAX(baseline_version), 0) FROM vehicle_odometer_baselines WHERE vehicle_id = :v');
            $versionStmt->execute([':v' => $vehicleId]);
            $version = (int) $versionStmt->fetchColumn() + 1;

            $old = $this->db->prepare('SELECT * FROM vehicle_odometer_baselines WHERE vehicle_id = :v AND is_active = 1');
            $old->execute([':v' => $vehicleId]);
            $oldRows = $old->fetchAll(PDO::FETCH_ASSOC);

            $now = date('Y-m-d H:i:s');
            $this->db->prepare('UPDATE vehicle_odometer_baselines SET is_active = 0, superseded_at = :n WHERE vehicle_id = :v AND is_active = 1')
                ->execute([':n' => $now, ':v' => $vehicleId]);

            $insert = $this->db->prepare(
                'INSERT INTO vehicle_odometer_baselines
                    (vehicle_id, provider_code, requested_t0_at, measured_t0_at, baseline_value_km, original_value, original_unit,
                     measurement_type, source_reading_id, retrieval_status, status_note, baseline_version, is_active, created_by, created_at)
                 VALUES (:v, :p, :rt, :mt, :km, :ov, :ou, :type, :sid, :st, :note, :ver, 1, :u, :c)'
            );
            $newRows = [];
            foreach ($results as $provider => $result) {
                $ok = ($result['status'] ?? '') === OdometerCalculator::STATUS_OK && is_array($result['reading'] ?? null);
                $reading = $ok ? $result['reading'] : null;
                $row = [
                    ':v' => $vehicleId, ':p' => $provider, ':rt' => $requestedT0,
                    ':mt' => $reading['measured_at'] ?? null,
                    ':km' => $reading !== null ? round((float) $reading['km'], 1) : null,
                    ':ov' => $reading !== null ? (float) ($reading['original_value'] ?? $reading['km']) : null,
                    ':ou' => $reading !== null ? 'km' : null,
                    ':type' => $reading !== null ? ($provider === 'sas' ? 'gps_km_index' : 'pump_odometer') : null,
                    ':sid' => $reading['id'] ?? null,
                    ':st' => (string) ($result['status'] ?? OdometerCalculator::STATUS_UNAVAILABLE),
                    ':note' => isset($result['note']) ? mb_substr((string) $result['note'], 0, 255) : null,
                    ':ver' => $version, ':u' => $userId, ':c' => $now,
                ];
                $insert->execute($row);
                $newRows[$provider] = [
                    'status' => $row[':st'], 'measured_at' => $row[':mt'], 'km' => $row[':km'], 'source_reading_id' => $row[':sid'],
                ];
            }

            $this->logAudit($vehicleId, $userId, 'set_t0', $oldRows === [] ? null : $oldRows, [
                'requested_t0_at' => $requestedT0, 'version' => $version, 'providers' => $newRows,
            ], $reason);

            if ($ownTransaction) {
                $this->db->commit();
            }

            return $version;
        } catch (Throwable $exception) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    // ---------------------------------------------------------------- audit

    public function logAudit(?int $vehicleId, ?int $userId, string $action, mixed $old, mixed $new, ?string $reason = null): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO vehicle_odometer_audit (vehicle_id, user_id, action, old_values, new_values, reason, created_at)
             VALUES (:v, :u, :a, :o, :n, :r, :c)'
        );
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $stmt->execute([
            ':v' => $vehicleId, ':u' => $userId, ':a' => $action,
            ':o' => $old === null ? null : json_encode($old, $flags),
            ':n' => $new === null ? null : json_encode($new, $flags),
            ':r' => $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 255) : null,
            ':c' => date('Y-m-d H:i:s'),
        ]);
    }

    public function getAudit(?int $vehicleId, int $limit = 100): array
    {
        $sql = 'SELECT a.*, u.nume AS user_name, v.nr_inmatriculare FROM vehicle_odometer_audit a
                LEFT JOIN utilizatori u ON u.id = a.user_id LEFT JOIN vehicule v ON v.id = a.vehicle_id';
        $params = [];
        if ($vehicleId !== null) {
            $sql .= ' WHERE a.vehicle_id = :v';
            $params[':v'] = $vehicleId;
        }
        $stmt = $this->db->prepare($sql . ' ORDER BY a.created_at DESC, a.id DESC LIMIT ' . max(1, min(500, $limit)));
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
