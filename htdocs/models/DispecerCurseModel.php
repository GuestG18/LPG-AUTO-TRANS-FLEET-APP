<?php
declare(strict_types=1);

class DispecerCurseModel extends BaseModel
{
    private const DISTRIBUTION_ROUTE_SCOPE_DISTRIBUTIE = 'distributie';
    private const DISTRIBUTION_ROUTE_SCOPE_PRIMAR_DISTRIBUTIE = 'primar_distributie';
    private const DISTRIBUTION_ROUTE_TARIFF_MODE_BOTH = 'tona_km';
    private const DISTRIBUTION_ROUTE_TARIFF_MODE_TON = 'tona';
    private const DISTRIBUTION_ROUTE_TARIFF_MODE_KM = 'km';
    private const DEFAULT_BILLING_STATUS = 'in_curs_facturare';

    private bool $distributionRouteTableEnsured = false;
    private bool $primaryRouteTableEnsured = false;
    private bool $compressorVehicleAssignmentTableEnsured = false;
    private bool $raceCompressorLocationColumnsEnsured = false;
    private bool $raceCostPerKmColumnsEnsured = false;
    private bool $raceLoadingDateColumnEnsured = false;
    private bool $raceCreatedByColumnEnsured = false;
    private bool $raceExpenseStatusColumnEnsured = false;
    private bool $raceDuplicateKeySchemaEnsured = false;
    private bool $raceSoftDeleteSchemaEnsured = false;
    private bool $raceAuditLogSchemaEnsured = false;
    private bool $expenseRefacturareColumnEnsured = false;
    private bool $expenseCategorySchemaEnsured = false;
    private bool $transportBeneficiaryColumnsEnsured = false;

    private const RACE_DUPLICATE_KEY_FIELDS = [
        'vehicle_id',
        'driver_id',
        'tip_transport',
        'data_cursa',
        'data_incarcare',
        'data_inceput',
        'data_sfarsit',
        'ora_inceput',
        'ora_sfarsit',
        'durata_cursa_minute',
        'loc_incarcare_id',
        'loc_plecare',
        'loc_aspirare',
        'loc_livrare',
        'loc_livrare_cursa',
        'beneficiar_id',
        'tip_marfa',
        'capacitate_transport',
        'cantitate_incarcata',
        'cantitate_prelevata',
        'nr_clienti',
        'km_cursa',
        'ore_functionare',
        'km_totali',
        'ore_aspirare',
        'km_dislocare',
        'tona_livrata',
        'tona_aspirata_lichida',
        'tona_aspirata_gazoasa',
        'zona_distributie_id',
        'status_facturare',
        'pret_tarifare',
        'total_facturare',
        'cost_km_primar',
        'cost_km_distributie',
        'cost_km_mixt',
        'cost_km_compresor',
        'observatii',
    ];

    private const RACE_DUPLICATE_INT_FIELDS = [
        'vehicle_id',
        'driver_id',
        'durata_cursa_minute',
        'loc_incarcare_id',
        'beneficiar_id',
        'nr_clienti',
        'km_cursa',
        'km_totali',
        'zona_distributie_id',
    ];

    private const RACE_DUPLICATE_DECIMAL_FIELDS = [
        'capacitate_transport',
        'cantitate_incarcata',
        'cantitate_prelevata',
        'ore_functionare',
        'ore_aspirare',
        'km_dislocare',
        'tona_livrata',
        'tona_aspirata_lichida',
        'tona_aspirata_gazoasa',
        'pret_tarifare',
        'total_facturare',
        'cost_km_primar',
        'cost_km_distributie',
        'cost_km_mixt',
        'cost_km_compresor',
    ];

    private const DEFAULT_EXPENSE_CATEGORIES = [
        'taxe_drum' => 'Taxe drum',
        'diurna' => 'Diurna',
        'service' => 'Reparatii',
        'alte' => 'Alte cheltuieli',
    ];

    public function getVehicleOptions(bool $onlyActive = false): array
    {
        $sql = "
            SELECT
                v.id,
                v.nr_inmatriculare,
                v.marca,
                v.model,
                v.tip_vehicul,
                CASE
                    WHEN v.tip_vehicul = 'cap_tractor' THEN s.capacitate_transport
                    ELSE v.capacitate_transport
                END AS capacitate_transport
            FROM vehicule v
            LEFT JOIN (
                SELECT vc1.tractor_id, vc1.semiremorca_id
                FROM vehicule_cuplaje vc1
                INNER JOIN (
                    SELECT tractor_id, MAX(id) AS max_id
                    FROM vehicule_cuplaje
                    WHERE activ = 1
                    GROUP BY tractor_id
                ) latest ON latest.max_id = vc1.id
            ) vc ON vc.tractor_id = v.id
            LEFT JOIN vehicule s ON s.id = vc.semiremorca_id
            WHERE v.tip_vehicul NOT IN ('semiremorca', 'semiremorca_primar', 'semiremorca_distributie')
            " . ($onlyActive ? "AND v.status = 'activ'" : "") . "
            ORDER BY v.nr_inmatriculare ASC
        ";
        $stmt = $this->db->query($sql);

        return $stmt->fetchAll();
    }

    public function getActiveVehicleIdsWithAssignedDriver(bool $onlyActiveDrivers = false): array
    {
        $this->ensureDriverVehicleAssignmentsSchema();

        $sql = "
            SELECT DISTINCT v.id
            FROM vehicule v
            INNER JOIN soferi_vehicule sv ON sv.vehicle_id = v.id
            INNER JOIN soferi s ON s.id = sv.driver_id
            WHERE v.tip_vehicul NOT IN ('semiremorca', 'semiremorca_primar', 'semiremorca_distributie')
              AND v.status = 'activ'
              " . ($onlyActiveDrivers ? "AND s.status = 'activ'" : "") . "
            ORDER BY v.id ASC
        ";
        $stmt = $this->db->query($sql);
        $rows = $stmt->fetchAll();

        $ids = [];
        foreach ($rows as $row) {
            $vehicleId = (int) ($row['id'] ?? 0);
            if ($vehicleId <= 0) {
                continue;
            }
            $ids[$vehicleId] = $vehicleId;
        }

        return array_values($ids);
    }

    public function getDriversGroupedByVehicle(bool $onlyActiveDrivers = false): array
    {
        $this->ensureDriverVehicleAssignmentsSchema();

        $sql = "
            SELECT s.id, sv.vehicle_id, s.nume, s.status, sv.is_primary
            FROM soferi_vehicule sv
            INNER JOIN soferi s ON s.id = sv.driver_id
            WHERE sv.vehicle_id IS NOT NULL
              " . ($onlyActiveDrivers ? "AND s.status = 'activ'" : "") . "
            ORDER BY
                sv.vehicle_id ASC,
                sv.is_primary DESC,
                CASE WHEN s.status = 'activ' THEN 0 ELSE 1 END,
                s.nume ASC,
                s.id ASC
        ";
        $stmt = $this->db->query($sql);
        $rows = $stmt->fetchAll();

        $grouped = [];
        foreach ($rows as $row) {
            $vehicleId = (int) ($row['vehicle_id'] ?? 0);
            $driverId = (int) ($row['id'] ?? 0);
            if ($vehicleId <= 0 || $driverId <= 0) {
                continue;
            }

            if (!isset($grouped[$vehicleId])) {
                $grouped[$vehicleId] = [];
            }

            $grouped[$vehicleId][] = [
                'id' => $driverId,
                'nume' => trim((string) ($row['nume'] ?? '')),
                'status' => trim((string) ($row['status'] ?? '')),
            ];
        }

        return $grouped;
    }

    public function getDriverOptions(bool $onlyActive = false): array
    {
        $sql = "
            SELECT id, nume, status
            FROM soferi
            WHERE 1 = 1
            " . ($onlyActive ? "AND status = 'activ'" : "") . "
            ORDER BY
                CASE WHEN status = 'activ' THEN 0 ELSE 1 END,
                nume ASC
        ";
        $stmt = $this->db->query($sql);

        return $stmt->fetchAll();
    }

    public function getVehicleGarageMap(bool $onlyActive = false): array
    {
        $sql = "
            SELECT id, garaj
            FROM vehicule
            WHERE tip_vehicul NOT IN ('semiremorca', 'semiremorca_primar', 'semiremorca_distributie')
            " . ($onlyActive ? "AND status = 'activ'" : "") . "
            ORDER BY id ASC
        ";
        $stmt = $this->db->query($sql);
        $rows = $stmt->fetchAll();

        $map = [];
        foreach ($rows as $row) {
            $vehicleId = (int) ($row['id'] ?? 0);
            if ($vehicleId <= 0) {
                continue;
            }

            $map[$vehicleId] = trim((string) ($row['garaj'] ?? ''));
        }

        return $map;
    }

    public function getLoadLocations(bool $onlyActive = true, ?int $beneficiaryId = null): array
    {
        $sql = "
            SELECT id, beneficiar_id, nume, tarif, activ
            FROM configurare_locuri_incarcare
            WHERE 1 = 1
            " . ($onlyActive ? " AND activ = 1" : "") . "
            " . ($beneficiaryId !== null && $beneficiaryId > 0 ? " AND beneficiar_id = :beneficiar_id" : "") . "
            ORDER BY nume ASC
        ";

        $stmt = $this->db->prepare($sql);
        if ($beneficiaryId !== null && $beneficiaryId > 0) {
            $stmt->bindValue(':beneficiar_id', $beneficiaryId, PDO::PARAM_INT);
        }
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getLoadLocationsGroupedByBeneficiary(bool $onlyActive = true): array
    {
        $grouped = [];

        foreach ($this->getLoadLocations($onlyActive) as $location) {
            $beneficiaryId = (int) ($location['beneficiar_id'] ?? 0);
            if ($beneficiaryId <= 0) {
                continue;
            }

            if (!isset($grouped[$beneficiaryId])) {
                $grouped[$beneficiaryId] = [];
            }

            $grouped[$beneficiaryId][] = [
                'id' => (int) ($location['id'] ?? 0),
                'beneficiar_id' => $beneficiaryId,
                'nume' => (string) ($location['nume'] ?? ''),
                'tarif' => (float) ($location['tarif'] ?? 0),
                'activ' => !empty($location['activ']),
            ];
        }

        return $grouped;
    }

    public function getLoadLocationTariffs(bool $onlyActive = true, ?int $beneficiaryId = null): array
    {
        $tariffs = [];

        foreach ($this->getLoadLocations($onlyActive, $beneficiaryId) as $location) {
            $locationId = (int) ($location['id'] ?? 0);
            if ($locationId <= 0) {
                continue;
            }

            $tariffs[$locationId] = (float) ($location['tarif'] ?? 0);
        }

        return $tariffs;
    }

    public function getVehicleDefaultLoadLocationMap(?int $beneficiaryId = null): array
    {
        $sql = "
            SELECT vehicle_id, loc_incarcare_id
            FROM configurare_locuri_incarcare_vehicule
            WHERE loc_incarcare_id IS NOT NULL
            " . ($beneficiaryId !== null && $beneficiaryId > 0 ? " AND beneficiar_id = :beneficiar_id" : "") . "
            ORDER BY updated_at DESC, id DESC
        ";

        $stmt = $this->db->prepare($sql);
        if ($beneficiaryId !== null && $beneficiaryId > 0) {
            $stmt->bindValue(':beneficiar_id', $beneficiaryId, PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $map = [];
        foreach ($rows as $row) {
            $vehicleId = (int) ($row['vehicle_id'] ?? 0);
            $locationId = (int) ($row['loc_incarcare_id'] ?? 0);

            if ($vehicleId <= 0 || $locationId <= 0 || isset($map[$vehicleId])) {
                continue;
            }

            $map[$vehicleId] = $locationId;
        }

        return $map;
    }

    public function getVehicleDefaultLoadLocationMapByBeneficiary(): array
    {
        $stmt = $this->db->query("
            SELECT beneficiar_id, vehicle_id, loc_incarcare_id
            FROM configurare_locuri_incarcare_vehicule
            WHERE beneficiar_id IS NOT NULL
            ORDER BY updated_at DESC, id DESC
        ");
        $rows = $stmt->fetchAll();

        $map = [];
        foreach ($rows as $row) {
            $beneficiaryId = (int) ($row['beneficiar_id'] ?? 0);
            $vehicleId = (int) ($row['vehicle_id'] ?? 0);
            $locationId = (int) ($row['loc_incarcare_id'] ?? 0);

            if ($beneficiaryId <= 0 || $vehicleId <= 0 || $locationId <= 0) {
                continue;
            }
            if (!isset($map[$beneficiaryId])) {
                $map[$beneficiaryId] = [];
            }
            if (!isset($map[$beneficiaryId][$vehicleId])) {
                $map[$beneficiaryId][$vehicleId] = $locationId;
            }
        }

        return $map;
    }

    public function assignVehicleDefaultLoadLocation(int $vehicleId, int $locationId, ?int $beneficiaryId = null): bool
    {
        if ($beneficiaryId === null || $beneficiaryId <= 0) {
            $location = $this->getLoadLocationById($locationId);
            $beneficiaryId = (int) ($location['beneficiar_id'] ?? 0);
        }

        $now = date('Y-m-d H:i:s');
        $this->db->beginTransaction();

        try {
            if ($beneficiaryId > 0) {
                $deleteExistingStmt = $this->db->prepare("
                    DELETE FROM configurare_locuri_incarcare_vehicule
                    WHERE vehicle_id = :vehicle_id
                      AND beneficiar_id = :beneficiar_id
                ");
                $deleteExistingStmt->bindValue(':vehicle_id', $vehicleId, PDO::PARAM_INT);
                $deleteExistingStmt->bindValue(':beneficiar_id', $beneficiaryId, PDO::PARAM_INT);
                $deleteExistingStmt->execute();
            }

            $insertStmt = $this->db->prepare("
                INSERT INTO configurare_locuri_incarcare_vehicule (
                    beneficiar_id,
                    vehicle_id,
                    loc_incarcare_id,
                    created_at,
                    updated_at
                ) VALUES (
                    :beneficiar_id,
                    :vehicle_id,
                    :loc_incarcare_id,
                    :created_at,
                    :updated_at
                )
            ");
            $this->bindNullableInt($insertStmt, ':beneficiar_id', $beneficiaryId > 0 ? $beneficiaryId : null);
            $insertStmt->bindValue(':vehicle_id', $vehicleId, PDO::PARAM_INT);
            $insertStmt->bindValue(':loc_incarcare_id', $locationId, PDO::PARAM_INT);
            $insertStmt->bindValue(':created_at', $now);
            $insertStmt->bindValue(':updated_at', $now);
            $insertStmt->execute();

            $this->db->commit();

            return true;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    public function syncLoadLocationVehicleAssignments(int $locationId, array $vehicleIds, ?int $beneficiaryId = null): bool
    {
        if ($locationId <= 0) {
            return false;
        }

        if ($beneficiaryId === null || $beneficiaryId <= 0) {
            $location = $this->getLoadLocationById($locationId);
            $beneficiaryId = (int) ($location['beneficiar_id'] ?? 0);
        }

        $this->db->beginTransaction();

        try {
            $deleteStmt = $this->db->prepare("
                DELETE FROM configurare_locuri_incarcare_vehicule
                WHERE loc_incarcare_id = :loc_incarcare_id
            ");
            $deleteStmt->bindValue(':loc_incarcare_id', $locationId, PDO::PARAM_INT);
            $deleteStmt->execute();

            if ($vehicleIds !== []) {
                $now = date('Y-m-d H:i:s');
                $deleteExistingStmt = null;
                if ($beneficiaryId > 0) {
                    $deleteExistingStmt = $this->db->prepare("
                        DELETE FROM configurare_locuri_incarcare_vehicule
                        WHERE vehicle_id = :vehicle_id
                          AND beneficiar_id = :beneficiar_id
                    ");
                }

                $insertStmt = $this->db->prepare("
                    INSERT INTO configurare_locuri_incarcare_vehicule (
                        beneficiar_id,
                        vehicle_id,
                        loc_incarcare_id,
                        created_at,
                        updated_at
                    ) VALUES (
                        :beneficiar_id,
                        :vehicle_id,
                        :loc_incarcare_id,
                        :created_at,
                        :updated_at
                    )
                ");

                foreach (array_values(array_unique(array_map('intval', $vehicleIds))) as $vehicleId) {
                    if ($vehicleId <= 0) {
                        continue;
                    }

                    if ($deleteExistingStmt !== null) {
                        $deleteExistingStmt->bindValue(':vehicle_id', $vehicleId, PDO::PARAM_INT);
                        $deleteExistingStmt->bindValue(':beneficiar_id', $beneficiaryId, PDO::PARAM_INT);
                        $deleteExistingStmt->execute();
                    }

                    $this->bindNullableInt($insertStmt, ':beneficiar_id', $beneficiaryId > 0 ? $beneficiaryId : null);
                    $insertStmt->bindValue(':vehicle_id', $vehicleId, PDO::PARAM_INT);
                    $insertStmt->bindValue(':loc_incarcare_id', $locationId, PDO::PARAM_INT);
                    $insertStmt->bindValue(':created_at', $now);
                    $insertStmt->bindValue(':updated_at', $now);
                    $insertStmt->execute();
                }
            }

            $this->db->commit();

            return true;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    public function getVehicleDefaultDistributionZoneMap(?int $beneficiaryId = null): array
    {
        $sql = "
            SELECT vehicle_id, zona_distributie_id
            FROM configurare_zone_distributie_vehicule
            WHERE zona_distributie_id IS NOT NULL
            " . ($beneficiaryId !== null && $beneficiaryId > 0 ? " AND beneficiar_id = :beneficiar_id" : "") . "
            ORDER BY updated_at DESC, id DESC
        ";

        $stmt = $this->db->prepare($sql);
        if ($beneficiaryId !== null && $beneficiaryId > 0) {
            $stmt->bindValue(':beneficiar_id', $beneficiaryId, PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $map = [];
        foreach ($rows as $row) {
            $vehicleId = (int) ($row['vehicle_id'] ?? 0);
            $zoneId = (int) ($row['zona_distributie_id'] ?? 0);

            if ($vehicleId <= 0 || $zoneId <= 0 || isset($map[$vehicleId])) {
                continue;
            }

            $map[$vehicleId] = $zoneId;
        }

        return $map;
    }

    public function getVehicleDefaultDistributionZoneMapByBeneficiary(): array
    {
        $stmt = $this->db->query("
            SELECT beneficiar_id, vehicle_id, zona_distributie_id
            FROM configurare_zone_distributie_vehicule
            WHERE beneficiar_id IS NOT NULL
            ORDER BY updated_at DESC, id DESC
        ");
        $rows = $stmt->fetchAll();

        $map = [];
        foreach ($rows as $row) {
            $beneficiaryId = (int) ($row['beneficiar_id'] ?? 0);
            $vehicleId = (int) ($row['vehicle_id'] ?? 0);
            $zoneId = (int) ($row['zona_distributie_id'] ?? 0);

            if ($beneficiaryId <= 0 || $vehicleId <= 0 || $zoneId <= 0) {
                continue;
            }
            if (!isset($map[$beneficiaryId])) {
                $map[$beneficiaryId] = [];
            }
            if (!isset($map[$beneficiaryId][$vehicleId])) {
                $map[$beneficiaryId][$vehicleId] = $zoneId;
            }
        }

        return $map;
    }

    public function assignVehicleDefaultDistributionZone(int $vehicleId, int $zoneId, ?int $beneficiaryId = null): bool
    {
        if ($beneficiaryId === null || $beneficiaryId <= 0) {
            $zone = $this->getDistributionZoneById($zoneId);
            $beneficiaryId = (int) ($zone['beneficiar_id'] ?? 0);
        }

        $now = date('Y-m-d H:i:s');
        $this->db->beginTransaction();

        try {
            if ($beneficiaryId > 0) {
                $deleteExistingStmt = $this->db->prepare("
                    DELETE FROM configurare_zone_distributie_vehicule
                    WHERE vehicle_id = :vehicle_id
                      AND beneficiar_id = :beneficiar_id
                ");
                $deleteExistingStmt->bindValue(':vehicle_id', $vehicleId, PDO::PARAM_INT);
                $deleteExistingStmt->bindValue(':beneficiar_id', $beneficiaryId, PDO::PARAM_INT);
                $deleteExistingStmt->execute();
            }

            $insertStmt = $this->db->prepare("
                INSERT INTO configurare_zone_distributie_vehicule (
                    beneficiar_id,
                    vehicle_id,
                    zona_distributie_id,
                    created_at,
                    updated_at
                ) VALUES (
                    :beneficiar_id,
                    :vehicle_id,
                    :zona_distributie_id,
                    :created_at,
                    :updated_at
                )
            ");
            $this->bindNullableInt($insertStmt, ':beneficiar_id', $beneficiaryId > 0 ? $beneficiaryId : null);
            $insertStmt->bindValue(':vehicle_id', $vehicleId, PDO::PARAM_INT);
            $insertStmt->bindValue(':zona_distributie_id', $zoneId, PDO::PARAM_INT);
            $insertStmt->bindValue(':created_at', $now);
            $insertStmt->bindValue(':updated_at', $now);
            $insertStmt->execute();

            $this->db->commit();

            return true;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    public function syncDistributionZoneVehicleAssignments(int $zoneId, array $vehicleIds, ?int $beneficiaryId = null): bool
    {
        if ($zoneId <= 0) {
            return false;
        }

        if ($beneficiaryId === null || $beneficiaryId <= 0) {
            $zone = $this->getDistributionZoneById($zoneId);
            $beneficiaryId = (int) ($zone['beneficiar_id'] ?? 0);
        }

        $this->db->beginTransaction();

        try {
            $deleteStmt = $this->db->prepare("
                DELETE FROM configurare_zone_distributie_vehicule
                WHERE zona_distributie_id = :zona_distributie_id
            ");
            $deleteStmt->bindValue(':zona_distributie_id', $zoneId, PDO::PARAM_INT);
            $deleteStmt->execute();

            if ($vehicleIds !== []) {
                $now = date('Y-m-d H:i:s');
                $deleteExistingStmt = null;
                if ($beneficiaryId > 0) {
                    $deleteExistingStmt = $this->db->prepare("
                        DELETE FROM configurare_zone_distributie_vehicule
                        WHERE vehicle_id = :vehicle_id
                          AND beneficiar_id = :beneficiar_id
                    ");
                }

                $insertStmt = $this->db->prepare("
                    INSERT INTO configurare_zone_distributie_vehicule (
                        beneficiar_id,
                        vehicle_id,
                        zona_distributie_id,
                        created_at,
                        updated_at
                    ) VALUES (
                        :beneficiar_id,
                        :vehicle_id,
                        :zona_distributie_id,
                        :created_at,
                        :updated_at
                    )
                ");

                foreach (array_values(array_unique(array_map('intval', $vehicleIds))) as $vehicleId) {
                    if ($vehicleId <= 0) {
                        continue;
                    }

                    if ($deleteExistingStmt !== null) {
                        $deleteExistingStmt->bindValue(':vehicle_id', $vehicleId, PDO::PARAM_INT);
                        $deleteExistingStmt->bindValue(':beneficiar_id', $beneficiaryId, PDO::PARAM_INT);
                        $deleteExistingStmt->execute();
                    }

                    $this->bindNullableInt($insertStmt, ':beneficiar_id', $beneficiaryId > 0 ? $beneficiaryId : null);
                    $insertStmt->bindValue(':vehicle_id', $vehicleId, PDO::PARAM_INT);
                    $insertStmt->bindValue(':zona_distributie_id', $zoneId, PDO::PARAM_INT);
                    $insertStmt->bindValue(':created_at', $now);
                    $insertStmt->bindValue(':updated_at', $now);
                    $insertStmt->execute();
                }
            }

            $this->db->commit();

            return true;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    public function getCompressorVehicleMapByBeneficiary(): array
    {
        $this->ensureCompressorVehicleAssignmentTable();

        $stmt = $this->db->query("
            SELECT beneficiar_id, vehicle_id
            FROM configurare_compresor_vehicule
            ORDER BY updated_at DESC, id DESC
        ");
        $rows = $stmt->fetchAll();

        $map = [];
        foreach ($rows as $row) {
            $beneficiaryId = (int) ($row['beneficiar_id'] ?? 0);
            $vehicleId = (int) ($row['vehicle_id'] ?? 0);
            if ($beneficiaryId <= 0 || $vehicleId <= 0) {
                continue;
            }

            if (!isset($map[$beneficiaryId])) {
                $map[$beneficiaryId] = [];
            }
            if (!isset($map[$beneficiaryId][$vehicleId])) {
                $map[$beneficiaryId][$vehicleId] = $vehicleId;
            }
        }

        return $map;
    }

    public function getVehicleIdsForCompressorBeneficiary(int $beneficiaryId): array
    {
        if ($beneficiaryId <= 0) {
            return [];
        }

        $this->ensureCompressorVehicleAssignmentTable();

        $stmt = $this->db->prepare("
            SELECT vehicle_id
            FROM configurare_compresor_vehicule
            WHERE beneficiar_id = :beneficiar_id
            ORDER BY vehicle_id ASC
        ");
        $stmt->bindValue(':beneficiar_id', $beneficiaryId, PDO::PARAM_INT);
        $stmt->execute();

        $ids = [];
        foreach ($stmt->fetchAll() as $row) {
            $vehicleId = (int) ($row['vehicle_id'] ?? 0);
            if ($vehicleId > 0) {
                $ids[$vehicleId] = $vehicleId;
            }
        }

        return array_values($ids);
    }

    public function syncCompressorVehicleAssignmentsForBeneficiary(int $beneficiaryId, array $vehicleIds): bool
    {
        if ($beneficiaryId <= 0) {
            return false;
        }

        $this->ensureCompressorVehicleAssignmentTable();

        $normalizedVehicleIds = [];
        foreach ($vehicleIds as $vehicleIdRaw) {
            $vehicleId = (int) $vehicleIdRaw;
            if ($vehicleId > 0) {
                $normalizedVehicleIds[$vehicleId] = $vehicleId;
            }
        }
        $normalizedVehicleIds = array_values($normalizedVehicleIds);
        sort($normalizedVehicleIds);

        $this->db->beginTransaction();

        try {
            $deleteStmt = $this->db->prepare("
                DELETE FROM configurare_compresor_vehicule
                WHERE beneficiar_id = :beneficiar_id
            ");
            $deleteStmt->bindValue(':beneficiar_id', $beneficiaryId, PDO::PARAM_INT);
            $deleteStmt->execute();

            if ($normalizedVehicleIds !== []) {
                $now = date('Y-m-d H:i:s');
                $insertStmt = $this->db->prepare("
                    INSERT INTO configurare_compresor_vehicule (
                        beneficiar_id,
                        vehicle_id,
                        created_at,
                        updated_at
                    ) VALUES (
                        :beneficiar_id,
                        :vehicle_id,
                        :created_at,
                        :updated_at
                    )
                ");

                foreach ($normalizedVehicleIds as $vehicleId) {
                    $insertStmt->bindValue(':beneficiar_id', $beneficiaryId, PDO::PARAM_INT);
                    $insertStmt->bindValue(':vehicle_id', $vehicleId, PDO::PARAM_INT);
                    $insertStmt->bindValue(':created_at', $now);
                    $insertStmt->bindValue(':updated_at', $now);
                    $insertStmt->execute();
                }
            }

            $this->db->commit();

            return true;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    public function getDistributionZones(bool $onlyActive = true, ?int $beneficiaryId = null): array
    {
        $sql = "
            SELECT id, beneficiar_id, nume, tarif_distributie, cost_extra_km, activ
            FROM configurare_zone_distributie
            WHERE 1 = 1
            " . ($onlyActive ? " AND activ = 1" : "") . "
            " . ($beneficiaryId !== null && $beneficiaryId > 0 ? " AND beneficiar_id = :beneficiar_id" : "") . "
            ORDER BY nume ASC
        ";
        $stmt = $this->db->prepare($sql);
        if ($beneficiaryId !== null && $beneficiaryId > 0) {
            $stmt->bindValue(':beneficiar_id', $beneficiaryId, PDO::PARAM_INT);
        }
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getDistributionZonesGroupedByBeneficiary(bool $onlyActive = true): array
    {
        $grouped = [];

        foreach ($this->getDistributionZones($onlyActive) as $zone) {
            $beneficiaryId = (int) ($zone['beneficiar_id'] ?? 0);
            if ($beneficiaryId <= 0) {
                continue;
            }

            if (!isset($grouped[$beneficiaryId])) {
                $grouped[$beneficiaryId] = [];
            }

            $grouped[$beneficiaryId][] = [
                'id' => (int) ($zone['id'] ?? 0),
                'beneficiar_id' => $beneficiaryId,
                'nume' => (string) ($zone['nume'] ?? ''),
                'tarif_distributie' => (float) ($zone['tarif_distributie'] ?? 0),
                'cost_extra_km' => (float) ($zone['cost_extra_km'] ?? 0),
                'activ' => !empty($zone['activ']),
            ];
        }

        return $grouped;
    }

    public function getDistributionZoneTariffs(bool $onlyActive = true, ?int $beneficiaryId = null): array
    {
        $tariffs = [];

        foreach ($this->getDistributionZones($onlyActive, $beneficiaryId) as $zone) {
            $zoneId = (int) ($zone['id'] ?? 0);
            if ($zoneId <= 0) {
                continue;
            }

            $tariffs[$zoneId] = (float) ($zone['tarif_distributie'] ?? 0);
        }

        return $tariffs;
    }

    public function getDistributionZoneExtraKmCosts(bool $onlyActive = true, ?int $beneficiaryId = null): array
    {
        $costs = [];

        foreach ($this->getDistributionZones($onlyActive, $beneficiaryId) as $zone) {
            $zoneId = (int) ($zone['id'] ?? 0);
            if ($zoneId <= 0) {
                continue;
            }

            $costs[$zoneId] = (float) ($zone['cost_extra_km'] ?? 0);
        }

        return $costs;
    }

    public function saveDistributionRouteRule(
        int $beneficiaryId,
        int $locationId,
        int $zoneId,
        float $tariffPerTon,
        float $extraKmCost,
        int $kmTariff,
        bool $active,
        array $vehicleIds = [],
        float $rideCost = 0.0,
        bool $applyRideCost = false,
        ?string $transportScope = null,
        ?string $tariffMode = null
    ): bool {
        if ($beneficiaryId <= 0 || $locationId <= 0 || $zoneId <= 0) {
            return false;
        }

        $this->ensureDistributionRouteTable();
        $normalizedVehicleIds = $this->normalizeRouteVehicleIds($vehicleIds);
        $vehicleIdsCsv = $normalizedVehicleIds === [] ? null : implode(',', $normalizedVehicleIds);
        $normalizedTransportScope = $this->normalizeDistributionRouteScope($transportScope);
        $normalizedTariffMode = $this->normalizeDistributionRouteTariffMode($tariffMode);

        $sql = "
            INSERT INTO configurare_rute_distributie (
                beneficiar_id,
                loc_incarcare_id,
                zona_distributie_id,
                transport_scope,
                tarif_mod,
                tarif_tona,
                cost_extra_km,
                km_tarifare,
                cost_cursa,
                aplica_cost_cursa,
                vehicle_ids,
                activ,
                created_at,
                updated_at
            ) VALUES (
                :beneficiar_id,
                :loc_incarcare_id,
                :zona_distributie_id,
                :transport_scope,
                :tarif_mod,
                :tarif_tona,
                :cost_extra_km,
                :km_tarifare,
                :cost_cursa,
                :aplica_cost_cursa,
                :vehicle_ids,
                :activ,
                :created_at,
                :updated_at
            )
        ";

        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':beneficiar_id', $beneficiaryId, PDO::PARAM_INT);
        $stmt->bindValue(':loc_incarcare_id', $locationId, PDO::PARAM_INT);
        $stmt->bindValue(':zona_distributie_id', $zoneId, PDO::PARAM_INT);
        $stmt->bindValue(':transport_scope', $normalizedTransportScope);
        $stmt->bindValue(':tarif_mod', $normalizedTariffMode);
        $stmt->bindValue(':tarif_tona', max(0, $tariffPerTon));
        $stmt->bindValue(':cost_extra_km', max(0, $extraKmCost));
        $stmt->bindValue(':km_tarifare', max(0, $kmTariff), PDO::PARAM_INT);
        $stmt->bindValue(':cost_cursa', max(0, $rideCost));
        $stmt->bindValue(':aplica_cost_cursa', $applyRideCost ? 1 : 0, PDO::PARAM_INT);
        if ($vehicleIdsCsv === null) {
            $stmt->bindValue(':vehicle_ids', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':vehicle_ids', $vehicleIdsCsv);
        }
        $stmt->bindValue(':activ', $active ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':created_at', $now);
        $stmt->bindValue(':updated_at', $now);

        return $stmt->execute();
    }

    public function getDistributionRouteRuleById(int $id, ?int $beneficiaryId = null): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $this->ensureDistributionRouteTable();
        $sql = "
            SELECT
                id,
                beneficiar_id,
                loc_incarcare_id,
                zona_distributie_id,
                transport_scope,
                tarif_mod,
                tarif_tona,
                cost_extra_km,
                km_tarifare,
                cost_cursa,
                aplica_cost_cursa,
                vehicle_ids,
                activ
            FROM configurare_rute_distributie
            WHERE id = :id
            " . ($beneficiaryId !== null && $beneficiaryId > 0 ? " AND beneficiar_id = :beneficiar_id" : "") . "
            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        if ($beneficiaryId !== null && $beneficiaryId > 0) {
            $stmt->bindValue(':beneficiar_id', $beneficiaryId, PDO::PARAM_INT);
        }
        $stmt->execute();
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function updateDistributionRouteRuleById(
        int $id,
        int $beneficiaryId,
        int $locationId,
        int $zoneId,
        float $tariffPerTon,
        float $extraKmCost,
        int $kmTariff,
        bool $active,
        array $vehicleIds = [],
        float $rideCost = 0.0,
        bool $applyRideCost = false,
        ?string $transportScope = null,
        ?string $tariffMode = null
    ): bool {
        if ($id <= 0 || $locationId <= 0 || $zoneId <= 0) {
            return false;
        }

        $this->ensureDistributionRouteTable();
        $normalizedVehicleIds = $this->normalizeRouteVehicleIds($vehicleIds);
        $vehicleIdsCsv = $normalizedVehicleIds === [] ? null : implode(',', $normalizedVehicleIds);
        $normalizedTransportScope = $this->normalizeDistributionRouteScope($transportScope);
        $normalizedTariffMode = $this->normalizeDistributionRouteTariffMode($tariffMode);

        $sql = "
            UPDATE configurare_rute_distributie
            SET
                loc_incarcare_id = :loc_incarcare_id,
                zona_distributie_id = :zona_distributie_id,
                transport_scope = :transport_scope,
                tarif_mod = :tarif_mod,
                tarif_tona = :tarif_tona,
                cost_extra_km = :cost_extra_km,
                km_tarifare = :km_tarifare,
                cost_cursa = :cost_cursa,
                aplica_cost_cursa = :aplica_cost_cursa,
                vehicle_ids = :vehicle_ids,
                activ = :activ,
                updated_at = :updated_at
            WHERE id = :id
              AND beneficiar_id = :beneficiar_id
            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':beneficiar_id', $beneficiaryId, PDO::PARAM_INT);
        $stmt->bindValue(':loc_incarcare_id', $locationId, PDO::PARAM_INT);
        $stmt->bindValue(':zona_distributie_id', $zoneId, PDO::PARAM_INT);
        $stmt->bindValue(':transport_scope', $normalizedTransportScope);
        $stmt->bindValue(':tarif_mod', $normalizedTariffMode);
        $stmt->bindValue(':tarif_tona', max(0, $tariffPerTon));
        $stmt->bindValue(':cost_extra_km', max(0, $extraKmCost));
        $stmt->bindValue(':km_tarifare', max(0, $kmTariff), PDO::PARAM_INT);
        $stmt->bindValue(':cost_cursa', max(0, $rideCost));
        $stmt->bindValue(':aplica_cost_cursa', $applyRideCost ? 1 : 0, PDO::PARAM_INT);
        if ($vehicleIdsCsv === null) {
            $stmt->bindValue(':vehicle_ids', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':vehicle_ids', $vehicleIdsCsv);
        }
        $stmt->bindValue(':activ', $active ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'));

        return $stmt->execute();
    }

    public function getDistributionRouteRuleForBeneficiary(
        int $beneficiaryId,
        int $locationId,
        int $zoneId,
        bool $onlyActive = true,
        ?int $vehicleId = null,
        ?string $transportScope = null
    ): ?array {
        if ($locationId <= 0 || $zoneId <= 0) {
            return null;
        }

        $this->ensureDistributionRouteTable();
        $filterByVehicle = $vehicleId !== null && $vehicleId > 0;
        $hasBeneficiaryPriority = $beneficiaryId > 0;
        $normalizedTransportScope = $this->normalizeDistributionRouteScopeFilter($transportScope);

        $sql = "
            SELECT
                id,
                beneficiar_id,
                loc_incarcare_id,
                zona_distributie_id,
                transport_scope,
                tarif_mod,
                tarif_tona,
                cost_extra_km,
                km_tarifare,
                cost_cursa,
                aplica_cost_cursa,
                vehicle_ids,
                activ
            FROM configurare_rute_distributie
            WHERE loc_incarcare_id = :loc_incarcare_id
              AND zona_distributie_id = :zona_distributie_id
              " . ($onlyActive ? " AND activ = 1" : "") . "
              " . ($normalizedTransportScope !== null ? " AND transport_scope = :transport_scope" : "") . "
              " . ($filterByVehicle ? " AND (COALESCE(TRIM(vehicle_ids), '') = '' OR FIND_IN_SET(:vehicle_id_filter, vehicle_ids) > 0)" : "") . "
            ORDER BY
              " . ($hasBeneficiaryPriority ? "CASE WHEN beneficiar_id = :beneficiar_id_priority THEN 1 ELSE 0 END DESC," : "") . "
              " . ($filterByVehicle ? "CASE WHEN FIND_IN_SET(:vehicle_id_order, vehicle_ids) > 0 THEN 1 ELSE 0 END DESC," : "") . "
              id DESC
            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':loc_incarcare_id', $locationId, PDO::PARAM_INT);
        $stmt->bindValue(':zona_distributie_id', $zoneId, PDO::PARAM_INT);
        if ($normalizedTransportScope !== null) {
            $stmt->bindValue(':transport_scope', $normalizedTransportScope);
        }
        if ($hasBeneficiaryPriority) {
            $stmt->bindValue(':beneficiar_id_priority', $beneficiaryId, PDO::PARAM_INT);
        }
        if ($filterByVehicle) {
            $stmt->bindValue(':vehicle_id_filter', $vehicleId, PDO::PARAM_INT);
            $stmt->bindValue(':vehicle_id_order', $vehicleId, PDO::PARAM_INT);
        }
        $stmt->execute();
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function deleteDistributionRouteRule(int $id, ?int $beneficiaryId = null): bool
    {
        if ($id <= 0) {
            return false;
        }

        $this->ensureDistributionRouteTable();
        $sql = "
            DELETE FROM configurare_rute_distributie
            WHERE id = :id
            " . ($beneficiaryId !== null && $beneficiaryId > 0 ? " AND beneficiar_id = :beneficiar_id" : "") . "
            LIMIT 1
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        if ($beneficiaryId !== null && $beneficiaryId > 0) {
            $stmt->bindValue(':beneficiar_id', $beneficiaryId, PDO::PARAM_INT);
        }

        return $stmt->execute();
    }

    public function getDistributionRouteRules(bool $onlyActive = true, ?int $beneficiaryId = null, ?string $transportScope = null): array
    {
        $this->ensureDistributionRouteTable();
        $normalizedTransportScope = $this->normalizeDistributionRouteScopeFilter($transportScope);

        $sql = "
            SELECT
                r.id,
                r.beneficiar_id,
                r.loc_incarcare_id,
                r.zona_distributie_id,
                r.transport_scope,
                r.tarif_mod,
                r.tarif_tona,
                r.cost_extra_km,
                r.km_tarifare,
                r.cost_cursa,
                r.aplica_cost_cursa,
                r.vehicle_ids,
                r.activ,
                l.nume AS loc_nume,
                z.nume AS zona_nume
            FROM configurare_rute_distributie r
            INNER JOIN configurare_locuri_incarcare l ON l.id = r.loc_incarcare_id
            INNER JOIN configurare_zone_distributie z ON z.id = r.zona_distributie_id
            WHERE 1 = 1
              " . ($onlyActive ? " AND r.activ = 1" : "") . "
              " . ($beneficiaryId !== null && $beneficiaryId > 0 ? " AND r.beneficiar_id = :beneficiar_id" : "") . "
              " . ($normalizedTransportScope !== null ? " AND r.transport_scope = :transport_scope" : "") . "
            ORDER BY l.nume ASC, z.nume ASC, r.id DESC
        ";

        $stmt = $this->db->prepare($sql);
        if ($beneficiaryId !== null && $beneficiaryId > 0) {
            $stmt->bindValue(':beneficiar_id', $beneficiaryId, PDO::PARAM_INT);
        }
        if ($normalizedTransportScope !== null) {
            $stmt->bindValue(':transport_scope', $normalizedTransportScope);
        }
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getDistributionRouteTariffMap(bool $onlyActive = true, ?string $transportScope = null): array
    {
        $map = [];
        foreach ($this->getDistributionRouteRules($onlyActive, null, $transportScope) as $rule) {
            $locationId = (int) ($rule['loc_incarcare_id'] ?? 0);
            $zoneId = (int) ($rule['zona_distributie_id'] ?? 0);
            if ($locationId <= 0 || $zoneId <= 0) {
                continue;
            }

            $key = $locationId . '|' . $zoneId;
            $vehicleIdsRaw = trim((string) ($rule['vehicle_ids'] ?? ''));
            $vehicleIds = [];
            if ($vehicleIdsRaw !== '') {
                foreach (explode(',', $vehicleIdsRaw) as $vehicleIdRaw) {
                    $vehicleIdRaw = trim($vehicleIdRaw);
                    if ($vehicleIdRaw === '' || !ctype_digit($vehicleIdRaw)) {
                        continue;
                    }

                    $vehicleId = (int) $vehicleIdRaw;
                    if ($vehicleId > 0) {
                        $vehicleIds[] = $vehicleId;
                    }
                }
                $vehicleIds = array_values(array_unique($vehicleIds));
            }
            if (!isset($map[$key]) || !is_array($map[$key])) {
                $map[$key] = [];
            }

            $map[$key][] = [
                'id' => (int) ($rule['id'] ?? 0),
                'beneficiar_id' => (int) ($rule['beneficiar_id'] ?? 0),
                'transport_scope' => $this->normalizeDistributionRouteScopeFilter((string) ($rule['transport_scope'] ?? '')) ?? self::DISTRIBUTION_ROUTE_SCOPE_PRIMAR_DISTRIBUTIE,
                'tarif_mod' => $this->normalizeDistributionRouteTariffMode((string) ($rule['tarif_mod'] ?? '')),
                'tarif_tona' => (float) ($rule['tarif_tona'] ?? 0),
                'cost_extra_km' => (float) ($rule['cost_extra_km'] ?? 0),
                'km_tarifare' => (int) max(0, (int) ($rule['km_tarifare'] ?? 0)),
                'cost_cursa' => (float) ($rule['cost_cursa'] ?? 0),
                'aplica_cost_cursa' => !empty($rule['aplica_cost_cursa']),
                'vehicle_ids' => $vehicleIds,
                'activ' => !empty($rule['activ']),
            ];
        }

        return $map;
    }

    public function savePrimaryRouteRule(
        int $beneficiaryId,
        int $locationId,
        int $zoneId,
        int $kmTariff,
        array $vehicleIds,
        bool $manualAgreedKm,
        bool $active,
        float $rideCost = 0.0,
        bool $applyRideCost = false
    ): bool {
        if ($beneficiaryId <= 0 || $locationId <= 0 || $zoneId <= 0 || $kmTariff < 0) {
            return false;
        }

        $this->ensurePrimaryRouteTable();
        $normalizedVehicleIds = $this->normalizeRouteVehicleIds($vehicleIds);
        $vehicleIdsCsv = $normalizedVehicleIds === [] ? null : implode(',', $normalizedVehicleIds);

        $sql = "
            INSERT INTO configurare_rute_primar (
                beneficiar_id,
                loc_incarcare_id,
                zona_distributie_id,
                km_tarifare,
                cost_cursa,
                aplica_cost_cursa,
                vehicle_ids,
                km_agreati_manual,
                activ,
                created_at,
                updated_at
            ) VALUES (
                :beneficiar_id,
                :loc_incarcare_id,
                :zona_distributie_id,
                :km_tarifare,
                :cost_cursa,
                :aplica_cost_cursa,
                :vehicle_ids,
                :km_agreati_manual,
                :activ,
                :created_at,
                :updated_at
            )
        ";

        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':beneficiar_id', $beneficiaryId, PDO::PARAM_INT);
        $stmt->bindValue(':loc_incarcare_id', $locationId, PDO::PARAM_INT);
        $stmt->bindValue(':zona_distributie_id', $zoneId, PDO::PARAM_INT);
        $stmt->bindValue(':km_tarifare', max(0, $kmTariff), PDO::PARAM_INT);
        $stmt->bindValue(':cost_cursa', max(0, $rideCost));
        $stmt->bindValue(':aplica_cost_cursa', $applyRideCost ? 1 : 0, PDO::PARAM_INT);
        if ($vehicleIdsCsv === null) {
            $stmt->bindValue(':vehicle_ids', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':vehicle_ids', $vehicleIdsCsv);
        }
        $stmt->bindValue(':km_agreati_manual', $manualAgreedKm ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':activ', $active ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':created_at', $now);
        $stmt->bindValue(':updated_at', $now);

        return $stmt->execute();
    }

    public function getPrimaryRouteRuleById(int $id, ?int $beneficiaryId = null): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $this->ensurePrimaryRouteTable();
        $sql = "
            SELECT
                id,
                beneficiar_id,
                loc_incarcare_id,
                zona_distributie_id,
                km_tarifare,
                cost_cursa,
                aplica_cost_cursa,
                vehicle_ids,
                km_agreati_manual,
                activ
            FROM configurare_rute_primar
            WHERE id = :id
            " . ($beneficiaryId !== null && $beneficiaryId > 0 ? " AND beneficiar_id = :beneficiar_id" : "") . "
            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        if ($beneficiaryId !== null && $beneficiaryId > 0) {
            $stmt->bindValue(':beneficiar_id', $beneficiaryId, PDO::PARAM_INT);
        }
        $stmt->execute();
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function updatePrimaryRouteRuleById(
        int $id,
        int $beneficiaryId,
        int $locationId,
        int $zoneId,
        int $kmTariff,
        array $vehicleIds,
        bool $manualAgreedKm,
        bool $active,
        float $rideCost = 0.0,
        bool $applyRideCost = false
    ): bool {
        if ($id <= 0 || $beneficiaryId <= 0 || $locationId <= 0 || $zoneId <= 0 || $kmTariff < 0) {
            return false;
        }

        $this->ensurePrimaryRouteTable();
        $normalizedVehicleIds = $this->normalizeRouteVehicleIds($vehicleIds);
        $vehicleIdsCsv = $normalizedVehicleIds === [] ? null : implode(',', $normalizedVehicleIds);

        $sql = "
            UPDATE configurare_rute_primar
            SET
                loc_incarcare_id = :loc_incarcare_id,
                zona_distributie_id = :zona_distributie_id,
                km_tarifare = :km_tarifare,
                cost_cursa = :cost_cursa,
                aplica_cost_cursa = :aplica_cost_cursa,
                vehicle_ids = :vehicle_ids,
                km_agreati_manual = :km_agreati_manual,
                activ = :activ,
                updated_at = :updated_at
            WHERE id = :id
              AND beneficiar_id = :beneficiar_id
            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':beneficiar_id', $beneficiaryId, PDO::PARAM_INT);
        $stmt->bindValue(':loc_incarcare_id', $locationId, PDO::PARAM_INT);
        $stmt->bindValue(':zona_distributie_id', $zoneId, PDO::PARAM_INT);
        $stmt->bindValue(':km_tarifare', max(0, $kmTariff), PDO::PARAM_INT);
        $stmt->bindValue(':cost_cursa', max(0, $rideCost));
        $stmt->bindValue(':aplica_cost_cursa', $applyRideCost ? 1 : 0, PDO::PARAM_INT);
        if ($vehicleIdsCsv === null) {
            $stmt->bindValue(':vehicle_ids', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':vehicle_ids', $vehicleIdsCsv);
        }
        $stmt->bindValue(':km_agreati_manual', $manualAgreedKm ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':activ', $active ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'));

        return $stmt->execute();
    }

    public function getPrimaryRouteRuleForBeneficiary(
        int $beneficiaryId,
        int $locationId,
        int $zoneId,
        bool $onlyActive = true
    ): ?array {
        if ($beneficiaryId <= 0 || $locationId <= 0 || $zoneId <= 0) {
            return null;
        }

        $this->ensurePrimaryRouteTable();
        $sql = "
            SELECT
                id,
                beneficiar_id,
                loc_incarcare_id,
                zona_distributie_id,
                km_tarifare,
                cost_cursa,
                aplica_cost_cursa,
                vehicle_ids,
                km_agreati_manual,
                activ
            FROM configurare_rute_primar
            WHERE beneficiar_id = :beneficiar_id
              AND loc_incarcare_id = :loc_incarcare_id
              AND zona_distributie_id = :zona_distributie_id
              " . ($onlyActive ? " AND activ = 1" : "") . "
            ORDER BY id DESC
            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':beneficiar_id', $beneficiaryId, PDO::PARAM_INT);
        $stmt->bindValue(':loc_incarcare_id', $locationId, PDO::PARAM_INT);
        $stmt->bindValue(':zona_distributie_id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function deletePrimaryRouteRule(int $id, ?int $beneficiaryId = null): bool
    {
        if ($id <= 0) {
            return false;
        }

        $this->ensurePrimaryRouteTable();
        $sql = "
            DELETE FROM configurare_rute_primar
            WHERE id = :id
            " . ($beneficiaryId !== null && $beneficiaryId > 0 ? " AND beneficiar_id = :beneficiar_id" : "") . "
            LIMIT 1
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        if ($beneficiaryId !== null && $beneficiaryId > 0) {
            $stmt->bindValue(':beneficiar_id', $beneficiaryId, PDO::PARAM_INT);
        }

        return $stmt->execute();
    }

    public function getPrimaryRouteRules(bool $onlyActive = true, ?int $beneficiaryId = null): array
    {
        $this->ensurePrimaryRouteTable();

        $sql = "
            SELECT
                r.id,
                r.beneficiar_id,
                r.loc_incarcare_id,
                r.zona_distributie_id,
                r.km_tarifare,
                r.cost_cursa,
                r.aplica_cost_cursa,
                r.vehicle_ids,
                r.km_agreati_manual,
                r.activ,
                l.nume AS loc_nume,
                z.nume AS zona_nume
            FROM configurare_rute_primar r
            INNER JOIN configurare_locuri_incarcare l ON l.id = r.loc_incarcare_id
            INNER JOIN configurare_zone_distributie z ON z.id = r.zona_distributie_id
            WHERE 1 = 1
              " . ($onlyActive ? " AND r.activ = 1" : "") . "
              " . ($beneficiaryId !== null && $beneficiaryId > 0 ? " AND r.beneficiar_id = :beneficiar_id" : "") . "
            ORDER BY l.nume ASC, z.nume ASC, r.id DESC
        ";

        $stmt = $this->db->prepare($sql);
        if ($beneficiaryId !== null && $beneficiaryId > 0) {
            $stmt->bindValue(':beneficiar_id', $beneficiaryId, PDO::PARAM_INT);
        }
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getPrimaryRouteKmMap(bool $onlyActive = true): array
    {
        $map = [];
        foreach ($this->getPrimaryRouteRules($onlyActive) as $rule) {
            $beneficiaryId = (int) ($rule['beneficiar_id'] ?? 0);
            $locationId = (int) ($rule['loc_incarcare_id'] ?? 0);
            $zoneId = (int) ($rule['zona_distributie_id'] ?? 0);
            if ($beneficiaryId <= 0 || $locationId <= 0 || $zoneId <= 0) {
                continue;
            }

            $key = $beneficiaryId . '|' . $locationId . '|' . $zoneId;
            $map[$key] = [
                'id' => (int) ($rule['id'] ?? 0),
                'km_tarifare' => (int) max(0, (int) ($rule['km_tarifare'] ?? 0)),
                'cost_cursa' => (float) max(0, (float) ($rule['cost_cursa'] ?? 0)),
                'aplica_cost_cursa' => !empty($rule['aplica_cost_cursa']),
                'vehicle_ids' => $this->normalizeRouteVehicleIds(
                    explode(',', (string) ($rule['vehicle_ids'] ?? ''))
                ),
                'km_agreati_manual' => !empty($rule['km_agreati_manual']),
                'activ' => !empty($rule['activ']),
            ];
        }

        return $map;
    }

    private function ensureDistributionRouteTable(): void
    {
        if ($this->distributionRouteTableEnsured) {
            return;
        }

        $sql = "
            CREATE TABLE IF NOT EXISTS configurare_rute_distributie (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                beneficiar_id INT UNSIGNED NOT NULL,
                loc_incarcare_id INT UNSIGNED NOT NULL,
                zona_distributie_id INT UNSIGNED NOT NULL,
                transport_scope ENUM('distributie', 'primar_distributie') NOT NULL DEFAULT 'primar_distributie',
                tarif_mod ENUM('tona_km', 'tona', 'km') NOT NULL DEFAULT 'tona_km',
                tarif_tona DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                cost_extra_km DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                km_tarifare INT UNSIGNED NOT NULL DEFAULT 0,
                cost_cursa DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                aplica_cost_cursa TINYINT(1) NOT NULL DEFAULT 0,
                vehicle_ids TEXT NULL,
                activ TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uk_config_rute_beneficiar_loc_zona_scope (beneficiar_id, loc_incarcare_id, zona_distributie_id, transport_scope),
                INDEX idx_config_rute_beneficiar (beneficiar_id),
                INDEX idx_config_rute_loc (loc_incarcare_id),
                INDEX idx_config_rute_zona (zona_distributie_id),
                INDEX idx_config_rute_activ (activ),
                CONSTRAINT fk_config_rute_beneficiar FOREIGN KEY (beneficiar_id) REFERENCES configurare_beneficiari_transport(id) ON DELETE CASCADE,
                CONSTRAINT fk_config_rute_loc FOREIGN KEY (loc_incarcare_id) REFERENCES configurare_locuri_incarcare(id) ON DELETE CASCADE,
                CONSTRAINT fk_config_rute_zona FOREIGN KEY (zona_distributie_id) REFERENCES configurare_zone_distributie(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";

        $this->db->exec($sql);
        $scopeColumnCheckStmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'configurare_rute_distributie'
              AND COLUMN_NAME = 'transport_scope'
        ");
        $scopeColumnCheckStmt->execute();
        $hasScopeColumn = (int) $scopeColumnCheckStmt->fetchColumn() > 0;
        if (!$hasScopeColumn) {
            $this->db->exec(
                "ALTER TABLE configurare_rute_distributie
                 ADD COLUMN transport_scope ENUM('distributie', 'primar_distributie')
                 NOT NULL DEFAULT 'primar_distributie'
                 AFTER zona_distributie_id"
            );
        }
        $this->db->exec(
            "UPDATE configurare_rute_distributie
             SET transport_scope = 'primar_distributie'
             WHERE transport_scope IS NULL
                OR transport_scope = ''
                OR transport_scope NOT IN ('distributie', 'primar_distributie')"
        );
        $tariffModeColumnCheckStmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'configurare_rute_distributie'
              AND COLUMN_NAME = 'tarif_mod'
        ");
        $tariffModeColumnCheckStmt->execute();
        $hasTariffModeColumn = (int) $tariffModeColumnCheckStmt->fetchColumn() > 0;
        if (!$hasTariffModeColumn) {
            $this->db->exec("
                ALTER TABLE configurare_rute_distributie
                ADD COLUMN tarif_mod ENUM('tona_km', 'tona', 'km') NOT NULL DEFAULT 'tona_km'
                AFTER transport_scope
            ");
        }
        $this->db->exec("
            UPDATE configurare_rute_distributie
            SET tarif_mod = 'tona_km'
            WHERE tarif_mod IS NULL
               OR tarif_mod = ''
               OR tarif_mod NOT IN ('tona_km', 'tona', 'km')
        ");
        $columnCheckStmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'configurare_rute_distributie'
              AND COLUMN_NAME = 'vehicle_ids'
        ");
        $columnCheckStmt->execute();
        $hasVehicleIdsColumn = (int) $columnCheckStmt->fetchColumn() > 0;
        if (!$hasVehicleIdsColumn) {
            $this->db->exec("ALTER TABLE configurare_rute_distributie ADD COLUMN vehicle_ids TEXT NULL AFTER cost_extra_km");
        }
        $kmTariffColumnCheckStmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'configurare_rute_distributie'
              AND COLUMN_NAME = 'km_tarifare'
        ");
        $kmTariffColumnCheckStmt->execute();
        $hasKmTariffColumn = (int) $kmTariffColumnCheckStmt->fetchColumn() > 0;
        if (!$hasKmTariffColumn) {
            $this->db->exec("ALTER TABLE configurare_rute_distributie ADD COLUMN km_tarifare INT UNSIGNED NOT NULL DEFAULT 0 AFTER cost_extra_km");
        }
        $rideCostColumnCheckStmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'configurare_rute_distributie'
              AND COLUMN_NAME = 'cost_cursa'
        ");
        $rideCostColumnCheckStmt->execute();
        $hasRideCostColumn = (int) $rideCostColumnCheckStmt->fetchColumn() > 0;
        if (!$hasRideCostColumn) {
            $this->db->exec("ALTER TABLE configurare_rute_distributie ADD COLUMN cost_cursa DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER km_tarifare");
        }
        $applyRideCostColumnCheckStmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'configurare_rute_distributie'
              AND COLUMN_NAME = 'aplica_cost_cursa'
        ");
        $applyRideCostColumnCheckStmt->execute();
        $hasApplyRideCostColumn = (int) $applyRideCostColumnCheckStmt->fetchColumn() > 0;
        if (!$hasApplyRideCostColumn) {
            $this->db->exec("ALTER TABLE configurare_rute_distributie ADD COLUMN aplica_cost_cursa TINYINT(1) NOT NULL DEFAULT 0 AFTER cost_cursa");
        }
        $legacyUniqueIndexCheckStmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'configurare_rute_distributie'
              AND INDEX_NAME = 'uk_config_rute_beneficiar_loc_zona'
        ");
        $legacyUniqueIndexCheckStmt->execute();
        $hasLegacyUniqueIndex = (int) $legacyUniqueIndexCheckStmt->fetchColumn() > 0;
        if ($hasLegacyUniqueIndex) {
            $this->db->exec("ALTER TABLE configurare_rute_distributie DROP INDEX uk_config_rute_beneficiar_loc_zona");
        }
        $scopedUniqueIndexCheckStmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'configurare_rute_distributie'
              AND INDEX_NAME = 'uk_config_rute_beneficiar_loc_zona_scope'
        ");
        $scopedUniqueIndexCheckStmt->execute();
        $hasScopedUniqueIndex = (int) $scopedUniqueIndexCheckStmt->fetchColumn() > 0;
        if (!$hasScopedUniqueIndex) {
            $this->db->exec("
                DELETE duplicate_rule
                FROM configurare_rute_distributie duplicate_rule
                INNER JOIN configurare_rute_distributie newer_rule
                    ON newer_rule.beneficiar_id = duplicate_rule.beneficiar_id
                   AND newer_rule.loc_incarcare_id = duplicate_rule.loc_incarcare_id
                   AND newer_rule.zona_distributie_id = duplicate_rule.zona_distributie_id
                   AND newer_rule.transport_scope = duplicate_rule.transport_scope
                   AND newer_rule.id > duplicate_rule.id
            ");
            $this->db->exec("
                ALTER TABLE configurare_rute_distributie
                ADD UNIQUE KEY uk_config_rute_beneficiar_loc_zona_scope (beneficiar_id, loc_incarcare_id, zona_distributie_id, transport_scope)
            ");
        }
        $this->distributionRouteTableEnsured = true;
    }

    private function normalizeDistributionRouteScope(?string $scope, string $fallback = self::DISTRIBUTION_ROUTE_SCOPE_PRIMAR_DISTRIBUTIE): string
    {
        $normalizedScope = trim((string) $scope);
        if (
            $normalizedScope === self::DISTRIBUTION_ROUTE_SCOPE_DISTRIBUTIE
            || $normalizedScope === self::DISTRIBUTION_ROUTE_SCOPE_PRIMAR_DISTRIBUTIE
        ) {
            return $normalizedScope;
        }

        return $fallback;
    }

    private function normalizeDistributionRouteTariffMode(?string $mode): string
    {
        $normalizedMode = trim((string) $mode);
        if (
            $normalizedMode === self::DISTRIBUTION_ROUTE_TARIFF_MODE_BOTH
            || $normalizedMode === self::DISTRIBUTION_ROUTE_TARIFF_MODE_TON
            || $normalizedMode === self::DISTRIBUTION_ROUTE_TARIFF_MODE_KM
        ) {
            return $normalizedMode;
        }

        return self::DISTRIBUTION_ROUTE_TARIFF_MODE_BOTH;
    }

    private function normalizeDistributionRouteScopeFilter(?string $scope): ?string
    {
        $normalizedScope = trim((string) $scope);
        if ($normalizedScope === '') {
            return null;
        }

        return $this->normalizeDistributionRouteScope($normalizedScope);
    }

    private function ensurePrimaryRouteTable(): void
    {
        if ($this->primaryRouteTableEnsured) {
            return;
        }

        $sql = "
            CREATE TABLE IF NOT EXISTS configurare_rute_primar (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                beneficiar_id INT UNSIGNED NOT NULL,
                loc_incarcare_id INT UNSIGNED NOT NULL,
                zona_distributie_id INT UNSIGNED NOT NULL,
                km_tarifare INT UNSIGNED NOT NULL DEFAULT 0,
                cost_cursa DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                aplica_cost_cursa TINYINT(1) NOT NULL DEFAULT 0,
                vehicle_ids TEXT NULL,
                km_agreati_manual TINYINT(1) NOT NULL DEFAULT 0,
                activ TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uk_config_rute_primar_beneficiar_loc_zona (beneficiar_id, loc_incarcare_id, zona_distributie_id),
                INDEX idx_config_rute_primar_beneficiar (beneficiar_id),
                INDEX idx_config_rute_primar_loc (loc_incarcare_id),
                INDEX idx_config_rute_primar_zona (zona_distributie_id),
                INDEX idx_config_rute_primar_activ (activ),
                CONSTRAINT fk_config_rute_primar_beneficiar FOREIGN KEY (beneficiar_id) REFERENCES configurare_beneficiari_transport(id) ON DELETE CASCADE,
                CONSTRAINT fk_config_rute_primar_loc FOREIGN KEY (loc_incarcare_id) REFERENCES configurare_locuri_incarcare(id) ON DELETE CASCADE,
                CONSTRAINT fk_config_rute_primar_zona FOREIGN KEY (zona_distributie_id) REFERENCES configurare_zone_distributie(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";

        $this->db->exec($sql);
        $columnCheckStmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'configurare_rute_primar'
              AND COLUMN_NAME = 'vehicle_ids'
        ");
        $columnCheckStmt->execute();
        if ((int) $columnCheckStmt->fetchColumn() === 0) {
            $this->db->exec("ALTER TABLE configurare_rute_primar ADD COLUMN vehicle_ids TEXT NULL AFTER km_tarifare");
        }
        $manualKmColumnCheckStmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'configurare_rute_primar'
              AND COLUMN_NAME = 'km_agreati_manual'
        ");
        $manualKmColumnCheckStmt->execute();
        if ((int) $manualKmColumnCheckStmt->fetchColumn() === 0) {
            $this->db->exec("ALTER TABLE configurare_rute_primar ADD COLUMN km_agreati_manual TINYINT(1) NOT NULL DEFAULT 0 AFTER vehicle_ids");
        }
        $rideCostColumnCheckStmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'configurare_rute_primar'
              AND COLUMN_NAME = 'cost_cursa'
        ");
        $rideCostColumnCheckStmt->execute();
        if ((int) $rideCostColumnCheckStmt->fetchColumn() === 0) {
            $this->db->exec("ALTER TABLE configurare_rute_primar ADD COLUMN cost_cursa DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER km_tarifare");
        }
        $applyRideCostColumnCheckStmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'configurare_rute_primar'
              AND COLUMN_NAME = 'aplica_cost_cursa'
        ");
        $applyRideCostColumnCheckStmt->execute();
        if ((int) $applyRideCostColumnCheckStmt->fetchColumn() === 0) {
            $this->db->exec("ALTER TABLE configurare_rute_primar ADD COLUMN aplica_cost_cursa TINYINT(1) NOT NULL DEFAULT 0 AFTER cost_cursa");
        }
        $this->primaryRouteTableEnsured = true;
    }

    private function ensureCompressorVehicleAssignmentTable(): void
    {
        if ($this->compressorVehicleAssignmentTableEnsured) {
            return;
        }

        $sql = "
            CREATE TABLE IF NOT EXISTS configurare_compresor_vehicule (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                beneficiar_id INT UNSIGNED NOT NULL,
                vehicle_id INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uk_config_compresor_beneficiar_vehicle (beneficiar_id, vehicle_id),
                INDEX idx_config_compresor_beneficiar (beneficiar_id),
                INDEX idx_config_compresor_vehicle (vehicle_id),
                CONSTRAINT fk_config_compresor_beneficiar FOREIGN KEY (beneficiar_id) REFERENCES configurare_beneficiari_transport(id) ON DELETE CASCADE,
                CONSTRAINT fk_config_compresor_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicule(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";

        $this->db->exec($sql);
        $this->compressorVehicleAssignmentTableEnsured = true;
    }

    private function ensureRaceCostPerKmColumns(): void
    {
        if ($this->raceCostPerKmColumnsEnsured) {
            return;
        }

        $columnsToEnsure = [
            'cost_km_primar' => "ALTER TABLE curse_dispecer ADD COLUMN cost_km_primar DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER total_facturare",
            'cost_km_distributie' => "ALTER TABLE curse_dispecer ADD COLUMN cost_km_distributie DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER cost_km_primar",
            'cost_km_mixt' => "ALTER TABLE curse_dispecer ADD COLUMN cost_km_mixt DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER cost_km_distributie",
            'cost_km_compresor' => "ALTER TABLE curse_dispecer ADD COLUMN cost_km_compresor DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER cost_km_mixt",
        ];

        $columnCheckStmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'curse_dispecer'
              AND COLUMN_NAME = :column_name
        ");

        foreach ($columnsToEnsure as $columnName => $alterSql) {
            $columnCheckStmt->bindValue(':column_name', $columnName, PDO::PARAM_STR);
            $columnCheckStmt->execute();
            $hasColumn = (int) $columnCheckStmt->fetchColumn() > 0;
            if ($hasColumn) {
                continue;
            }

            $this->db->exec($alterSql);
        }

        $this->db->exec("
            UPDATE curse_dispecer
            SET cost_km_compresor = ROUND(total_facturare / km_dislocare, 2)
            WHERE tip_transport = 'compresor'
              AND COALESCE(km_dislocare, 0) > 0
              AND COALESCE(cost_km_compresor, 0) <= 0
        ");

        $this->raceCostPerKmColumnsEnsured = true;
    }

    private function ensureRaceLoadingDateColumn(): void
    {
        if ($this->raceLoadingDateColumnEnsured) {
            return;
        }

        $columnCheckStmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'curse_dispecer'
              AND COLUMN_NAME = 'data_incarcare'
        ");
        $columnCheckStmt->execute();
        $hasColumn = (int) $columnCheckStmt->fetchColumn() > 0;

        if (!$hasColumn) {
            $this->db->exec("ALTER TABLE curse_dispecer ADD COLUMN data_incarcare DATE NULL AFTER data_cursa");
        }

        $this->raceLoadingDateColumnEnsured = true;
    }

    private function ensureRaceCompressorLocationColumns(): void
    {
        if ($this->raceCompressorLocationColumnsEnsured) {
            return;
        }

        $columnsToEnsure = [
            'loc_plecare' => "ALTER TABLE curse_dispecer ADD COLUMN loc_plecare VARCHAR(255) NULL AFTER loc_incarcare_id",
            'loc_aspirare' => "ALTER TABLE curse_dispecer ADD COLUMN loc_aspirare VARCHAR(255) NULL AFTER loc_plecare",
            'loc_livrare' => "ALTER TABLE curse_dispecer ADD COLUMN loc_livrare VARCHAR(255) NULL AFTER loc_aspirare",
            'loc_livrare_cursa' => "ALTER TABLE curse_dispecer ADD COLUMN loc_livrare_cursa VARCHAR(255) NULL AFTER loc_livrare",
        ];

        $columnCheckStmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'curse_dispecer'
              AND COLUMN_NAME = :column_name
        ");

        foreach ($columnsToEnsure as $columnName => $alterSql) {
            $columnCheckStmt->bindValue(':column_name', $columnName, PDO::PARAM_STR);
            $columnCheckStmt->execute();
            $hasColumn = (int) $columnCheckStmt->fetchColumn() > 0;
            if ($hasColumn) {
                continue;
            }

            $this->db->exec($alterSql);
        }

        $this->raceCompressorLocationColumnsEnsured = true;
    }

    private function ensureRaceCreatedByColumn(): void
    {
        if ($this->raceCreatedByColumnEnsured) {
            return;
        }

        $columnCheckStmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'curse_dispecer'
              AND COLUMN_NAME = 'created_by'
        ");
        $columnCheckStmt->execute();
        $hasColumn = (int) $columnCheckStmt->fetchColumn() > 0;

        if (!$hasColumn) {
            $this->db->exec("ALTER TABLE curse_dispecer ADD COLUMN created_by INT UNSIGNED NULL AFTER observatii");
        }

        $indexCheckStmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'curse_dispecer'
              AND INDEX_NAME = 'idx_curse_created_by'
        ");
        $indexCheckStmt->execute();
        $hasIndex = (int) $indexCheckStmt->fetchColumn() > 0;
        if (!$hasIndex) {
            $this->db->exec("ALTER TABLE curse_dispecer ADD INDEX idx_curse_created_by (created_by)");
        }

        $fkCheckStmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'curse_dispecer'
              AND COLUMN_NAME = 'created_by'
              AND REFERENCED_TABLE_NAME = 'utilizatori'
              AND REFERENCED_COLUMN_NAME = 'id'
        ");
        $fkCheckStmt->execute();
        $hasForeignKey = (int) $fkCheckStmt->fetchColumn() > 0;
        if (!$hasForeignKey) {
            $this->db->exec("
                ALTER TABLE curse_dispecer
                ADD CONSTRAINT fk_curse_created_by
                FOREIGN KEY (created_by) REFERENCES utilizatori(id) ON DELETE SET NULL
            ");
        }

        $this->raceCreatedByColumnEnsured = true;
    }

    private function ensureRaceSoftDeleteSchema(): void
    {
        if ($this->raceSoftDeleteSchemaEnsured) {
            return;
        }

        $this->ensureRaceCreatedByColumn();

        $columnCheckStmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'curse_dispecer'
              AND COLUMN_NAME = :column_name
        ");

        $columnCheckStmt->bindValue(':column_name', 'duplicate_key', PDO::PARAM_STR);
        $columnCheckStmt->execute();
        $deletedAtPosition = (int) $columnCheckStmt->fetchColumn() > 0 ? 'AFTER duplicate_key' : 'AFTER updated_at';

        $columnsToEnsure = [
            'deleted_at' => "ALTER TABLE curse_dispecer ADD COLUMN deleted_at DATETIME NULL " . $deletedAtPosition,
            'deleted_by' => "ALTER TABLE curse_dispecer ADD COLUMN deleted_by INT UNSIGNED NULL AFTER deleted_at",
        ];

        foreach ($columnsToEnsure as $columnName => $alterSql) {
            $columnCheckStmt->bindValue(':column_name', $columnName, PDO::PARAM_STR);
            $columnCheckStmt->execute();
            if ((int) $columnCheckStmt->fetchColumn() === 0) {
                $this->db->exec($alterSql);
            }
        }

        $indexCheckStmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'curse_dispecer'
              AND INDEX_NAME = :index_name
        ");

        foreach ([
            'idx_curse_deleted_at' => 'ALTER TABLE curse_dispecer ADD INDEX idx_curse_deleted_at (deleted_at)',
            'idx_curse_deleted_by' => 'ALTER TABLE curse_dispecer ADD INDEX idx_curse_deleted_by (deleted_by)',
        ] as $indexName => $alterSql) {
            $indexCheckStmt->bindValue(':index_name', $indexName, PDO::PARAM_STR);
            $indexCheckStmt->execute();
            if ((int) $indexCheckStmt->fetchColumn() === 0) {
                $this->db->exec($alterSql);
            }
        }

        $fkCheckStmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'curse_dispecer'
              AND COLUMN_NAME = 'deleted_by'
              AND REFERENCED_TABLE_NAME = 'utilizatori'
              AND REFERENCED_COLUMN_NAME = 'id'
        ");
        $fkCheckStmt->execute();
        if ((int) $fkCheckStmt->fetchColumn() === 0) {
            $this->db->exec("
                ALTER TABLE curse_dispecer
                ADD CONSTRAINT fk_curse_deleted_by
                FOREIGN KEY (deleted_by) REFERENCES utilizatori(id) ON DELETE SET NULL
            ");
        }

        $this->raceSoftDeleteSchemaEnsured = true;
    }

    private function ensureRaceAuditLogSchema(): void
    {
        if ($this->raceAuditLogSchemaEnsured) {
            return;
        }

        $this->ensureRaceSoftDeleteSchema();

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS cursa_audit_log (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                cursa_id INT UNSIGNED NOT NULL,
                action ENUM('created', 'updated', 'deleted', 'restored', 'status_changed') NOT NULL,
                performed_by INT UNSIGNED NULL,
                performed_at DATETIME NOT NULL,
                details_json LONGTEXT NULL,
                INDEX idx_cursa_audit_cursa (cursa_id, performed_at),
                INDEX idx_cursa_audit_action (action),
                INDEX idx_cursa_audit_user (performed_by),
                CONSTRAINT fk_cursa_audit_cursa FOREIGN KEY (cursa_id) REFERENCES curse_dispecer(id) ON DELETE CASCADE,
                CONSTRAINT fk_cursa_audit_user FOREIGN KEY (performed_by) REFERENCES utilizatori(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->raceAuditLogSchemaEnsured = true;
    }

    public function ensureDeletedRacesSupport(): void
    {
        $this->ensureRaceAuditLogSchema();
    }

    private function ensureRaceExpenseStatusColumn(): void
    {
        if ($this->raceExpenseStatusColumnEnsured) {
            return;
        }

        $columnCheckStmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'curse_dispecer'
              AND COLUMN_NAME = 'cheltuieli_status'
        ");
        $columnCheckStmt->execute();
        $hasColumn = (int) $columnCheckStmt->fetchColumn() > 0;

        if (!$hasColumn) {
            $this->db->exec("
                ALTER TABLE curse_dispecer
                ADD COLUMN cheltuieli_status ENUM('pending', 'not_applicable') NOT NULL DEFAULT 'pending'
                AFTER observatii
            ");
        }

        $this->raceExpenseStatusColumnEnsured = true;
    }

    private function ensureRaceDuplicateKeySchema(): void
    {
        if ($this->raceDuplicateKeySchemaEnsured) {
            return;
        }

        $this->ensureRaceCompressorLocationColumns();
        $this->ensureRaceCostPerKmColumns();
        $this->ensureRaceLoadingDateColumn();
        $this->ensureRaceCreatedByColumn();
        $this->ensureRaceExpenseStatusColumn();

        $columnCheckStmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'curse_dispecer'
              AND COLUMN_NAME = 'duplicate_key'
        ");
        $columnCheckStmt->execute();
        $hasColumn = (int) $columnCheckStmt->fetchColumn() > 0;

        if (!$hasColumn) {
            $this->db->exec("ALTER TABLE curse_dispecer ADD COLUMN duplicate_key CHAR(64) NULL AFTER updated_at");
        }

        $this->ensureRaceSoftDeleteSchema();

        $hasIndex = $this->raceDuplicateKeyIndexExists();
        $this->backfillRaceDuplicateKeys(!$hasIndex);

        if (!$hasIndex) {
            $this->db->exec("ALTER TABLE curse_dispecer ADD UNIQUE KEY uk_curse_dispecer_duplicate_key (duplicate_key)");
        }

        $this->raceDuplicateKeySchemaEnsured = true;
    }

    private function raceDuplicateKeyIndexExists(): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'curse_dispecer'
              AND INDEX_NAME = 'uk_curse_dispecer_duplicate_key'
        ");
        $stmt->execute();

        return (int) $stmt->fetchColumn() > 0;
    }

    private function backfillRaceDuplicateKeys(bool $rebuildAll): void
    {
        $usedKeys = [];
        if (!$rebuildAll) {
            $existingStmt = $this->db->query("
                SELECT id, duplicate_key
                FROM curse_dispecer
                WHERE duplicate_key IS NOT NULL
                  AND duplicate_key <> ''
                  AND deleted_at IS NULL
                ORDER BY id ASC
            ");
            foreach ($existingStmt->fetchAll() as $row) {
                $existingKey = trim((string) ($row['duplicate_key'] ?? ''));
                if ($existingKey !== '') {
                    $usedKeys[$existingKey] = (int) ($row['id'] ?? 0);
                }
            }
        }

        $selectFields = implode(', ', array_map(
            static fn (string $field): string => '`' . $field . '`',
            self::RACE_DUPLICATE_KEY_FIELDS
        ));
        $where = $rebuildAll
            ? 'WHERE deleted_at IS NULL'
            : "WHERE deleted_at IS NULL AND (duplicate_key IS NULL OR duplicate_key = '')";
        $stmt = $this->db->query("
            SELECT id, duplicate_key, {$selectFields}
            FROM curse_dispecer
            {$where}
            ORDER BY id ASC
        ");
        $rows = $stmt->fetchAll();
        if ($rows === []) {
            return;
        }

        $updateStmt = $this->db->prepare("
            UPDATE curse_dispecer
            SET duplicate_key = :duplicate_key
            WHERE id = :id
        ");

        foreach ($rows as $row) {
            $raceId = (int) ($row['id'] ?? 0);
            if ($raceId <= 0) {
                continue;
            }

            $canonicalKey = $this->buildRaceDuplicateKey($row);
            $duplicateKey = $canonicalKey;
            if (isset($usedKeys[$duplicateKey])) {
                $duplicateKey = hash('sha256', 'legacy-duplicate:' . $raceId . ':' . $canonicalKey);
            }
            while (isset($usedKeys[$duplicateKey])) {
                $duplicateKey = hash('sha256', 'legacy-duplicate:' . $raceId . ':' . $duplicateKey);
            }

            $usedKeys[$duplicateKey] = $raceId;
            if (trim((string) ($row['duplicate_key'] ?? '')) === $duplicateKey) {
                continue;
            }

            $updateStmt->bindValue(':duplicate_key', $duplicateKey, PDO::PARAM_STR);
            $updateStmt->bindValue(':id', $raceId, PDO::PARAM_INT);
            $updateStmt->execute();
        }
    }

    private function ensureExpenseRefacturareColumn(): void
    {
        if ($this->expenseRefacturareColumnEnsured) {
            return;
        }

        $columnCheckStmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'curse_cheltuieli'
              AND COLUMN_NAME = :column_name
        ");
        $columnCheckStmt->bindValue(':column_name', 'refacturare_tip_cheltuiala', PDO::PARAM_STR);
        $columnCheckStmt->execute();
        $hasColumn = (int) $columnCheckStmt->fetchColumn() > 0;

        if (!$hasColumn) {
            $this->db->exec("
                ALTER TABLE curse_cheltuieli
                ADD COLUMN refacturare_tip_cheltuiala ENUM('motorina', 'taxe_drum', 'diurna', 'service', 'alte') NULL
                AFTER tip_cheltuiala
            ");
        }

        $columnCheckStmt->bindValue(':column_name', 'refacturare_detalii', PDO::PARAM_STR);
        $columnCheckStmt->execute();
        $hasDetailsColumn = (int) $columnCheckStmt->fetchColumn() > 0;

        if (!$hasDetailsColumn) {
            $this->db->exec("
                ALTER TABLE curse_cheltuieli
                ADD COLUMN refacturare_detalii TEXT NULL
                AFTER refacturare_tip_cheltuiala
            ");
        }

        $columnsToEnsure = [
            'refacturare_suma' => "ALTER TABLE curse_cheltuieli ADD COLUMN refacturare_suma DECIMAL(12,2) NULL AFTER refacturare_detalii",
            'refacturare_data' => "ALTER TABLE curse_cheltuieli ADD COLUMN refacturare_data DATE NULL AFTER refacturare_suma",
            'refacturare_observatii' => "ALTER TABLE curse_cheltuieli ADD COLUMN refacturare_observatii TEXT NULL AFTER refacturare_data",
            'refacturare_document_path' => "ALTER TABLE curse_cheltuieli ADD COLUMN refacturare_document_path VARCHAR(255) NULL AFTER refacturare_observatii",
            'refacturare_document_original_name' => "ALTER TABLE curse_cheltuieli ADD COLUMN refacturare_document_original_name VARCHAR(255) NULL AFTER refacturare_document_path",
            'refacturare_document_mime_type' => "ALTER TABLE curse_cheltuieli ADD COLUMN refacturare_document_mime_type VARCHAR(150) NULL AFTER refacturare_document_original_name",
            'refacturare_document_file_size' => "ALTER TABLE curse_cheltuieli ADD COLUMN refacturare_document_file_size INT UNSIGNED NULL AFTER refacturare_document_mime_type",
            'refacturare_facturata' => "ALTER TABLE curse_cheltuieli ADD COLUMN refacturare_facturata TINYINT(1) NOT NULL DEFAULT 0 AFTER refacturare_document_file_size",
            'refacturare_facturata_at' => "ALTER TABLE curse_cheltuieli ADD COLUMN refacturare_facturata_at DATETIME NULL AFTER refacturare_facturata",
        ];

        foreach ($columnsToEnsure as $columnName => $alterSql) {
            $columnCheckStmt->bindValue(':column_name', $columnName, PDO::PARAM_STR);
            $columnCheckStmt->execute();
            if ((int) $columnCheckStmt->fetchColumn() === 0) {
                $this->db->exec($alterSql);
            }
        }

        $this->expenseRefacturareColumnEnsured = true;
    }

    public function ensureExpenseCategorySchema(): void
    {
        if ($this->expenseCategorySchemaEnsured) {
            return;
        }

        $this->ensureExpenseRefacturareColumn();

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS categorii_cheltuieli_curse (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                nume VARCHAR(150) NOT NULL,
                descriere TEXT NULL,
                activ TINYINT(1) NOT NULL DEFAULT 1,
                legacy_key VARCHAR(50) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                UNIQUE KEY uk_categorii_cheltuieli_curse_nume (nume),
                UNIQUE KEY uk_categorii_cheltuieli_curse_legacy (legacy_key),
                INDEX idx_categorii_cheltuieli_curse_activ (activ)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $categoryColumnCheck = $this->db->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'categorii_cheltuieli_curse'
              AND COLUMN_NAME = :column_name
        ");

        $categoryColumnCheck->bindValue(':column_name', 'legacy_key', PDO::PARAM_STR);
        $categoryColumnCheck->execute();
        if ((int) $categoryColumnCheck->fetchColumn() === 0) {
            $this->db->exec("ALTER TABLE categorii_cheltuieli_curse ADD COLUMN legacy_key VARCHAR(50) NULL AFTER activ");
        }

        $categoryColumnCheck->bindValue(':column_name', 'created_at', PDO::PARAM_STR);
        $categoryColumnCheck->execute();
        if ((int) $categoryColumnCheck->fetchColumn() === 0) {
            $this->db->exec("ALTER TABLE categorii_cheltuieli_curse ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER legacy_key");
        }

        $categoryColumnCheck->bindValue(':column_name', 'updated_at', PDO::PARAM_STR);
        $categoryColumnCheck->execute();
        if ((int) $categoryColumnCheck->fetchColumn() === 0) {
            $this->db->exec("ALTER TABLE categorii_cheltuieli_curse ADD COLUMN updated_at DATETIME NULL AFTER created_at");
        }

        $this->ensureIndexExists('categorii_cheltuieli_curse', 'idx_categorii_cheltuieli_curse_activ', 'ALTER TABLE categorii_cheltuieli_curse ADD INDEX idx_categorii_cheltuieli_curse_activ (activ)');
        $this->ensureIndexExists('categorii_cheltuieli_curse', 'uk_categorii_cheltuieli_curse_nume', 'ALTER TABLE categorii_cheltuieli_curse ADD UNIQUE KEY uk_categorii_cheltuieli_curse_nume (nume)');
        $this->ensureIndexExists('categorii_cheltuieli_curse', 'uk_categorii_cheltuieli_curse_legacy', 'ALTER TABLE categorii_cheltuieli_curse ADD UNIQUE KEY uk_categorii_cheltuieli_curse_legacy (legacy_key)');

        $expenseColumnCheck = $this->db->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'curse_cheltuieli'
              AND COLUMN_NAME = :column_name
        ");

        $expenseColumnCheck->bindValue(':column_name', 'categorie_id', PDO::PARAM_STR);
        $expenseColumnCheck->execute();
        if ((int) $expenseColumnCheck->fetchColumn() === 0) {
            $this->db->exec("ALTER TABLE curse_cheltuieli ADD COLUMN categorie_id INT UNSIGNED NULL AFTER tip_cheltuiala");
        }

        $expenseColumnCheck->bindValue(':column_name', 'added_by', PDO::PARAM_STR);
        $expenseColumnCheck->execute();
        if ((int) $expenseColumnCheck->fetchColumn() === 0) {
            $this->db->exec("ALTER TABLE curse_cheltuieli ADD COLUMN added_by INT UNSIGNED NULL AFTER observatii");
        }

        $this->ensureIndexExists('curse_cheltuieli', 'idx_curse_cheltuieli_categorie', 'ALTER TABLE curse_cheltuieli ADD INDEX idx_curse_cheltuieli_categorie (categorie_id)');
        $this->ensureIndexExists('curse_cheltuieli', 'idx_curse_cheltuieli_added_by', 'ALTER TABLE curse_cheltuieli ADD INDEX idx_curse_cheltuieli_added_by (added_by)');

        $now = date('Y-m-d H:i:s');
        $defaultStmt = $this->db->prepare("
            INSERT INTO categorii_cheltuieli_curse (nume, descriere, activ, legacy_key, created_at, updated_at)
            VALUES (:nume, :descriere, 1, :legacy_key, :created_at, :updated_at)
            ON DUPLICATE KEY UPDATE
                nume = VALUES(nume),
                activ = 1,
                legacy_key = VALUES(legacy_key),
                updated_at = VALUES(updated_at)
        ");

        foreach (self::DEFAULT_EXPENSE_CATEGORIES as $legacyKey => $label) {
            $defaultStmt->bindValue(':nume', $label, PDO::PARAM_STR);
            $defaultStmt->bindValue(':descriere', 'Categorie implicita pentru cheltuieli curse.', PDO::PARAM_STR);
            $defaultStmt->bindValue(':legacy_key', $legacyKey, PDO::PARAM_STR);
            $defaultStmt->bindValue(':created_at', $now, PDO::PARAM_STR);
            $defaultStmt->bindValue(':updated_at', $now, PDO::PARAM_STR);
            $defaultStmt->execute();
        }

        $this->db->exec("
            UPDATE curse_cheltuieli e
            INNER JOIN categorii_cheltuieli_curse c ON c.legacy_key = e.tip_cheltuiala
            SET e.categorie_id = c.id
            WHERE e.categorie_id IS NULL
              AND e.tip_cheltuiala <> 'motorina'
        ");

        $this->expenseCategorySchemaEnsured = true;
    }

    private function ensureIndexExists(string $tableName, string $indexName, string $alterSql): void
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND INDEX_NAME = :index_name
        ");
        $stmt->bindValue(':table_name', $tableName, PDO::PARAM_STR);
        $stmt->bindValue(':index_name', $indexName, PDO::PARAM_STR);
        $stmt->execute();

        if ((int) $stmt->fetchColumn() === 0) {
            $this->db->exec($alterSql);
        }
    }

    private function normalizeRouteVehicleIds(array $vehicleIds): array
    {
        $normalized = [];
        foreach ($vehicleIds as $vehicleIdRaw) {
            $vehicleIdRaw = trim((string) $vehicleIdRaw);
            if ($vehicleIdRaw === '' || !ctype_digit($vehicleIdRaw)) {
                continue;
            }

            $vehicleId = (int) $vehicleIdRaw;
            if ($vehicleId > 0) {
                $normalized[$vehicleId] = $vehicleId;
            }
        }

        $normalized = array_values($normalized);
        sort($normalized);

        return $normalized;
    }

    private function ensureTransportBeneficiaryColumns(): void
    {
        if ($this->transportBeneficiaryColumnsEnsured) {
            return;
        }

        $columnCheckStmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'configurare_beneficiari_transport'
              AND COLUMN_NAME = :column_name
        ");
        $columnCheckStmt->bindValue(':column_name', 'suporta_primar_distributie', PDO::PARAM_STR);
        $columnCheckStmt->execute();
        $hasPrimaryDistributionColumn = (int) $columnCheckStmt->fetchColumn() > 0;
        if (!$hasPrimaryDistributionColumn) {
            $this->db->exec("
                ALTER TABLE configurare_beneficiari_transport
                ADD COLUMN suporta_primar_distributie TINYINT(1) NOT NULL DEFAULT 0 AFTER suporta_distributie
            ");
            $this->db->exec("
                UPDATE configurare_beneficiari_transport
                SET suporta_primar_distributie = CASE
                    WHEN COALESCE(suporta_primar, 0) = 1 AND COALESCE(suporta_distributie, 0) = 1 THEN 1
                    ELSE 0
                END
            ");
        }

        $this->transportBeneficiaryColumnsEnsured = true;
    }

    public function getTransportBeneficiaries(bool $onlyActive = true): array
    {
        $this->ensureTransportBeneficiaryColumns();

        $sql = "
            SELECT
                id,
                nume,
                tip_marfa,
                pret_tarifare,
                suporta_primar,
                suporta_distributie,
                suporta_primar_distributie,
                suporta_compresor,
                pret_km,
                pret_tona,
                pret_distributie_km,
                pret_distributie_tona,
                pret_ora_aspirare,
                pret_km_dislocare,
                pret_tona_livrata,
                pret_tona_aspirata_lichida,
                pret_tona_aspirata_gazoasa,
                activ
            FROM configurare_beneficiari_transport
            " . ($onlyActive ? "WHERE activ = 1" : "") . "
            ORDER BY nume ASC
        ";
        $stmt = $this->db->query($sql);

        return $stmt->fetchAll();
    }

    public function getTransportBeneficiaryById(int $id): ?array
    {
        $this->ensureTransportBeneficiaryColumns();

        $sql = "
            SELECT
                id,
                nume,
                tip_marfa,
                pret_tarifare,
                suporta_primar,
                suporta_distributie,
                suporta_primar_distributie,
                suporta_compresor,
                pret_km,
                pret_tona,
                pret_distributie_km,
                pret_distributie_tona,
                pret_ora_aspirare,
                pret_km_dislocare,
                pret_tona_livrata,
                pret_tona_aspirata_lichida,
                pret_tona_aspirata_gazoasa,
                activ
            FROM configurare_beneficiari_transport
            WHERE id = :id
            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function getPaginatedRaces(array $filters, string $search, int $page, int $perPage): array
    {
        $fetchAllRows = $perPage <= 0;
        $page = max(1, $page);
        $perPage = $fetchAllRows ? 0 : max(1, $perPage);

        $whereData = $this->buildRaceWhere($filters, $search);
        $from = $this->raceFromSql();

        $countSql = "SELECT COUNT(*)" . $from . $whereData['where'];
        $countStmt = $this->db->prepare($countSql);
        $this->bindParams($countStmt, $whereData['params']);
        $countStmt->execute();
        $totalRows = (int) $countStmt->fetchColumn();

        $totalPages = $fetchAllRows ? 1 : max(1, (int) ceil($totalRows / $perPage));
        $page = $fetchAllRows ? 1 : min($page, $totalPages);
        $offset = $fetchAllRows ? 0 : (($page - 1) * $perPage);

        $dataSql = "
            SELECT
                c.*,
                v.nr_inmatriculare,
                v.marca,
                v.model,
                s.nume AS sofer_nume,
                uc.nume AS creat_de_nume,
                uu.nume AS actualizat_de_nume,
                lua.performed_at AS actualizat_la,
                li.nume AS loc_incarcare_nume,
                bt.nume AS beneficiar_nume,
                zd.nume AS zona_distributie_nume,
                COALESCE(exp.total_cheltuieli, 0) AS total_cheltuieli,
                COALESCE(exp.total_refacturare_facturata, 0) AS total_refacturare_facturata,
                COALESCE(exp.total_refacturare_pending, 0) AS total_refacturare_pending
            " . $from . $whereData['where'] . "
            ORDER BY c.data_inceput DESC, c.data_sfarsit DESC, c.id DESC
            " . ($fetchAllRows ? '' : 'LIMIT :limit_rows OFFSET :offset_rows') . "
        ";

        $dataStmt = $this->db->prepare($dataSql);
        $this->bindParams($dataStmt, $whereData['params']);
        if (!$fetchAllRows) {
            $dataStmt->bindValue(':limit_rows', $perPage, PDO::PARAM_INT);
            $dataStmt->bindValue(':offset_rows', $offset, PDO::PARAM_INT);
        }
        $dataStmt->execute();

        return [
            'rows' => $dataStmt->fetchAll(),
            'total_rows' => $totalRows,
            'total_pages' => $totalPages,
            'page' => $page,
        ];
    }

    public function getBillingCentralizer(array $filters, string $search, int $page, int $perPage): array
    {
        $this->ensureRaceLoadingDateColumn();

        $fetchAllRows = $perPage <= 0;
        $page = max(1, $page);
        $perPage = $fetchAllRows ? 0 : max(1, $perPage);

        $whereData = $this->buildBillingCentralizerWhere($filters, $search, true);
        $from = $this->raceFromSql();
        $facturareWithInvoicedExpr = "(COALESCE(c.total_facturare, 0) + COALESCE(exp.total_refacturare_facturata, 0))";
        $refacturarePendingExpr = "COALESCE(exp.total_refacturare_pending, 0)";
        $refacturarePendingCountExpr = "COALESCE(exp.refacturare_pending_count, 0)";
        $kmDoneExpr = "
            CASE
                WHEN c.tip_transport = 'compresor' AND COALESCE(c.km_dislocare, 0) > 0 THEN COALESCE(c.km_dislocare, 0)
                WHEN COALESCE(c.km_totali, 0) > 0 THEN COALESCE(c.km_totali, 0)
                WHEN COALESCE(c.km_cursa, 0) > 0 THEN COALESCE(c.km_cursa, 0)
                ELSE 0
            END
        ";
        $loadedTonsExpr = "
            CASE
                WHEN c.cantitate_incarcata IS NULL OR c.cantitate_incarcata <= 0 THEN 0
                WHEN c.capacitate_transport IS NOT NULL
                     AND c.capacitate_transport > 0
                     AND c.cantitate_incarcata > (c.capacitate_transport * 3)
                THEN c.cantitate_incarcata / 1000
                WHEN c.cantitate_incarcata >= 1000 THEN c.cantitate_incarcata / 1000
                ELSE c.cantitate_incarcata
            END
        ";
        $prelevataTonsExpr = "
            CASE
                WHEN c.cantitate_prelevata IS NULL OR c.cantitate_prelevata <= 0 THEN 0
                WHEN c.capacitate_transport IS NOT NULL
                     AND c.capacitate_transport > 0
                     AND c.cantitate_prelevata > (c.capacitate_transport * 3)
                THEN c.cantitate_prelevata / 1000
                WHEN c.cantitate_prelevata >= 1000 THEN c.cantitate_prelevata / 1000
                ELSE c.cantitate_prelevata
            END
        ";
        $deliveredTonsExpr = "
            CASE
                WHEN c.tip_transport = 'compresor' THEN COALESCE(c.tona_livrata, 0)
                WHEN c.tona_livrata IS NOT NULL AND c.tona_livrata > 0 THEN c.tona_livrata
                ELSE (" . $loadedTonsExpr . ")
            END
        ";

        $countSql = "SELECT COUNT(*)" . $from . $whereData['where'];
        $countStmt = $this->db->prepare($countSql);
        $this->bindParams($countStmt, $whereData['params']);
        $countStmt->execute();
        $totalRows = (int) $countStmt->fetchColumn();

        $totalPages = $fetchAllRows ? 1 : max(1, (int) ceil($totalRows / $perPage));
        $page = $fetchAllRows ? 1 : min($page, $totalPages);
        $offset = $fetchAllRows ? 0 : (($page - 1) * $perPage);

        $summarySql = "
            SELECT
                COALESCE(NULLIF(TRIM(c.status_facturare), ''), 'in_curs_facturare') AS status_facturare,
                COUNT(*) AS total_curse,
                COALESCE(SUM(c.total_facturare), 0) AS total_facturare,
                COALESCE(SUM(COALESCE(exp.total_refacturare_facturata, 0)), 0) AS total_refacturare_facturata,
                COALESCE(SUM(" . $refacturarePendingExpr . "), 0) AS total_refacturare,
                COALESCE(SUM(COALESCE(exp.total_cheltuieli, 0)), 0) AS total_cheltuieli,
                COALESCE(SUM(COALESCE(exp.expense_count, 0)), 0) AS expense_count,
                COALESCE(SUM(" . $refacturarePendingCountExpr . "), 0) AS refacturare_count,
                COALESCE(SUM(CASE WHEN " . $refacturarePendingExpr . " > 0 THEN 1 ELSE 0 END), 0) AS curse_de_refacturat,
                COALESCE(SUM(" . $kmDoneExpr . "), 0) AS total_km,
                COALESCE(SUM(COALESCE(c.km_cursa, 0)), 0) AS total_km_facturati,
                COALESCE(SUM(" . $loadedTonsExpr . "), 0) AS total_tone_incarcate,
                COALESCE(SUM(" . $prelevataTonsExpr . "), 0) AS total_tone_prelevate,
                COALESCE(SUM(" . $deliveredTonsExpr . "), 0) AS total_tone_livrate,
                COALESCE(SUM(" . $facturareWithInvoicedExpr . " + " . $refacturarePendingExpr . " - COALESCE(exp.total_cheltuieli, 0)), 0) AS sold_estimativ
            " . $from . $whereData['where'] . "
            GROUP BY COALESCE(NULLIF(TRIM(c.status_facturare), ''), 'in_curs_facturare')
        ";
        $summaryStmt = $this->db->prepare($summarySql);
        $this->bindParams($summaryStmt, $whereData['params']);
        $summaryStmt->execute();

        $summaryByStatus = [];
        foreach ($summaryStmt->fetchAll() as $summaryRow) {
            $statusKey = (string) ($summaryRow['status_facturare'] ?? '');
            if ($statusKey === '') {
                $statusKey = 'in_curs_facturare';
            }
            $summaryByStatus[$statusKey] = $summaryRow;
        }

        $summaryTotalsSql = "
            SELECT
                COUNT(*) AS total_curse,
                COALESCE(SUM(c.total_facturare), 0) AS total_facturare,
                COALESCE(SUM(COALESCE(exp.total_refacturare_facturata, 0)), 0) AS total_refacturare_facturata,
                COALESCE(SUM(" . $refacturarePendingExpr . "), 0) AS total_refacturare,
                COALESCE(SUM(COALESCE(exp.total_cheltuieli, 0)), 0) AS total_cheltuieli,
                COALESCE(SUM(COALESCE(exp.expense_count, 0)), 0) AS expense_count,
                COALESCE(SUM(" . $refacturarePendingCountExpr . "), 0) AS refacturare_count,
                COALESCE(SUM(CASE WHEN " . $refacturarePendingExpr . " > 0 THEN 1 ELSE 0 END), 0) AS curse_de_refacturat,
                COALESCE(SUM(" . $kmDoneExpr . "), 0) AS total_km,
                COALESCE(SUM(COALESCE(c.km_cursa, 0)), 0) AS total_km_facturati,
                COALESCE(SUM(COALESCE(c.km_totali, 0)), 0) AS total_km_totali,
                COALESCE(SUM(COALESCE(c.km_dislocare, 0)), 0) AS total_km_dislocare,
                COALESCE(SUM(" . $loadedTonsExpr . "), 0) AS total_tone_incarcate,
                COALESCE(SUM(" . $prelevataTonsExpr . "), 0) AS total_tone_prelevate,
                COALESCE(SUM(" . $deliveredTonsExpr . "), 0) AS total_tone_livrate,
                COALESCE(SUM(" . $facturareWithInvoicedExpr . " + " . $refacturarePendingExpr . " - COALESCE(exp.total_cheltuieli, 0)), 0) AS sold_estimativ
            " . $from . $whereData['where'];
        $summaryTotalsStmt = $this->db->prepare($summaryTotalsSql);
        $this->bindParams($summaryTotalsStmt, $whereData['params']);
        $summaryTotalsStmt->execute();
        $summaryTotals = $summaryTotalsStmt->fetch() ?: [];

        $summaryByTransportSql = "
            SELECT
                COALESCE(NULLIF(TRIM(c.status_facturare), ''), 'in_curs_facturare') AS status_facturare,
                COALESCE(NULLIF(TRIM(c.tip_transport), ''), '-') AS tip_transport,
                COUNT(*) AS total_curse,
                COALESCE(SUM(c.total_facturare), 0) AS total_facturare,
                COALESCE(SUM(COALESCE(exp.total_refacturare_facturata, 0)), 0) AS total_refacturare_facturata,
                COALESCE(SUM(" . $refacturarePendingExpr . "), 0) AS total_refacturare,
                COALESCE(SUM(COALESCE(exp.total_cheltuieli, 0)), 0) AS total_cheltuieli,
                COALESCE(SUM(COALESCE(exp.expense_count, 0)), 0) AS expense_count,
                COALESCE(SUM(" . $refacturarePendingCountExpr . "), 0) AS refacturare_count,
                COALESCE(SUM(CASE WHEN " . $refacturarePendingExpr . " > 0 THEN 1 ELSE 0 END), 0) AS curse_de_refacturat,
                COALESCE(SUM(" . $kmDoneExpr . "), 0) AS total_km,
                COALESCE(SUM(COALESCE(c.km_cursa, 0)), 0) AS total_km_facturati,
                COALESCE(SUM(COALESCE(c.km_totali, 0)), 0) AS total_km_totali,
                COALESCE(SUM(COALESCE(c.km_dislocare, 0)), 0) AS total_km_dislocare,
                COALESCE(SUM(COALESCE(c.ore_aspirare, 0)), 0) AS total_ore_aspirare,
                COALESCE(SUM(" . $loadedTonsExpr . "), 0) AS total_tone_incarcate,
                COALESCE(SUM(" . $prelevataTonsExpr . "), 0) AS total_tone_prelevate,
                COALESCE(SUM(" . $deliveredTonsExpr . "), 0) AS total_tone_livrate,
                COALESCE(SUM(COALESCE(c.tona_aspirata_lichida, 0)), 0) AS total_tone_lichid,
                COALESCE(SUM(COALESCE(c.tona_aspirata_gazoasa, 0)), 0) AS total_tone_gazos
            " . $from . $whereData['where'] . "
            GROUP BY
                COALESCE(NULLIF(TRIM(c.status_facturare), ''), 'in_curs_facturare'),
                COALESCE(NULLIF(TRIM(c.tip_transport), ''), '-')
            ORDER BY status_facturare ASC, total_facturare DESC, tip_transport ASC
        ";
        $summaryByTransportStmt = $this->db->prepare($summaryByTransportSql);
        $this->bindParams($summaryByTransportStmt, $whereData['params']);
        $summaryByTransportStmt->execute();

        $summaryByStatusTransport = [];
        foreach ($summaryByTransportStmt->fetchAll() as $transportSummaryRow) {
            $statusKey = (string) ($transportSummaryRow['status_facturare'] ?? '');
            if ($statusKey === '') {
                $statusKey = 'in_curs_facturare';
            }

            if (!isset($summaryByStatusTransport[$statusKey])) {
                $summaryByStatusTransport[$statusKey] = [];
            }

            $summaryByStatusTransport[$statusKey][] = $transportSummaryRow;
        }

        $vehicleKmSql = "
            SELECT
                c.vehicle_id,
                COALESCE(NULLIF(TRIM(v.nr_inmatriculare), ''), '-') AS nr_inmatriculare,
                COUNT(*) AS total_curse,
                COALESCE(SUM(" . $kmDoneExpr . "), 0) AS total_km,
                COALESCE(SUM(COALESCE(c.km_cursa, 0)), 0) AS km_facturati,
                COALESCE(SUM(COALESCE(c.km_totali, 0)), 0) AS km_totali,
                COALESCE(SUM(COALESCE(c.km_dislocare, 0)), 0) AS km_dislocare,
                COALESCE(SUM(COALESCE(c.total_facturare, 0)), 0) AS total_facturare,
                COALESCE(SUM(COALESCE(exp.total_cheltuieli, 0)), 0) AS total_cheltuieli,
                COALESCE(SUM(" . $refacturarePendingExpr . "), 0) AS total_refacturare,
                COALESCE(SUM(" . $refacturarePendingCountExpr . "), 0) AS refacturare_count
            " . $from . $whereData['where'] . "
            GROUP BY c.vehicle_id, v.nr_inmatriculare
            ORDER BY total_km DESC, v.nr_inmatriculare ASC
        ";
        $vehicleKmStmt = $this->db->prepare($vehicleKmSql);
        $this->bindParams($vehicleKmStmt, $whereData['params']);
        $vehicleKmStmt->execute();
        $vehicleKmRows = $vehicleKmStmt->fetchAll();

        $expenseTypeTotalsSql = "
            SELECT
                e.tip_cheltuiala,
                COUNT(*) AS total_linii,
                COALESCE(SUM(e.suma), 0) AS total_suma
            FROM curse_cheltuieli e
            INNER JOIN curse_dispecer c ON c.id = e.cursa_id
            INNER JOIN vehicule v ON v.id = c.vehicle_id
            LEFT JOIN soferi s ON s.id = c.driver_id
            LEFT JOIN configurare_locuri_incarcare li ON li.id = c.loc_incarcare_id
            LEFT JOIN configurare_beneficiari_transport bt ON bt.id = c.beneficiar_id
            LEFT JOIN configurare_zone_distributie zd ON zd.id = c.zona_distributie_id
            " . $whereData['where'] . "
            GROUP BY e.tip_cheltuiala
            ORDER BY total_suma DESC, e.tip_cheltuiala ASC
        ";
        $expenseTypeTotalsStmt = $this->db->prepare($expenseTypeTotalsSql);
        $this->bindParams($expenseTypeTotalsStmt, $whereData['params']);
        $expenseTypeTotalsStmt->execute();
        $expenseTypeTotals = $expenseTypeTotalsStmt->fetchAll();

        $refacturareTypeTotalsSql = "
            SELECT
                e.refacturare_tip_cheltuiala,
                COUNT(*) AS total_linii,
                COALESCE(SUM(e.refacturare_suma), 0) AS total_suma
            FROM curse_cheltuieli e
            INNER JOIN curse_dispecer c ON c.id = e.cursa_id
            INNER JOIN vehicule v ON v.id = c.vehicle_id
            LEFT JOIN soferi s ON s.id = c.driver_id
            LEFT JOIN configurare_locuri_incarcare li ON li.id = c.loc_incarcare_id
            LEFT JOIN configurare_beneficiari_transport bt ON bt.id = c.beneficiar_id
            LEFT JOIN configurare_zone_distributie zd ON zd.id = c.zona_distributie_id
            " . $whereData['where'] . "
              " . ($whereData['where'] === '' ? 'WHERE' : 'AND') . " COALESCE(e.refacturare_suma, 0) > 0
              AND COALESCE(e.refacturare_facturata, 0) = 0
            GROUP BY e.refacturare_tip_cheltuiala
            ORDER BY total_suma DESC, e.refacturare_tip_cheltuiala ASC
        ";
        $refacturareTypeTotalsStmt = $this->db->prepare($refacturareTypeTotalsSql);
        $this->bindParams($refacturareTypeTotalsStmt, $whereData['params']);
        $refacturareTypeTotalsStmt->execute();
        $refacturareTypeTotals = $refacturareTypeTotalsStmt->fetchAll();

        $dataSql = "
            SELECT
                c.id,
                c.vehicle_id,
                c.driver_id,
                c.beneficiar_id,
                c.loc_incarcare_id,
                c.tip_transport,
                c.data_cursa,
                c.data_incarcare,
                c.data_inceput,
                c.data_sfarsit,
                c.ora_inceput,
                c.ora_sfarsit,
                c.durata_cursa_minute,
                c.status_facturare,
                c.loc_plecare,
                c.loc_aspirare,
                c.loc_livrare,
                c.loc_livrare_cursa,
                c.tip_marfa,
                c.capacitate_transport,
                c.km_cursa,
                c.km_totali,
                c.km_dislocare,
                c.cantitate_incarcata,
                c.cantitate_prelevata,
                c.nr_clienti,
                c.ore_functionare,
                c.ore_aspirare,
                c.tona_livrata,
                c.tona_aspirata_lichida,
                c.tona_aspirata_gazoasa,
                c.pret_tarifare,
                c.total_facturare,
                c.cost_km_primar,
                c.cost_km_distributie,
                c.cost_km_mixt,
                c.cost_km_compresor,
                c.observatii,
                c.created_at,
                c.updated_at,
                v.nr_inmatriculare,
                v.marca,
                v.model,
                s.nume AS sofer_nume,
                li.nume AS loc_incarcare_nume,
                bt.nume AS beneficiar_nume,
                c.zona_distributie_id,
                zd.nume AS zona_distributie_nume,
                (" . $kmDoneExpr . ") AS km_rulati,
                COALESCE(exp.expense_count, 0) AS expense_count,
                COALESCE(exp.refacturare_pending_count, 0) AS refacturare_count,
                COALESCE(exp.total_refacturare_pending, 0) AS total_refacturare,
                COALESCE(exp.total_refacturare_facturata, 0) AS total_refacturare_facturata,
                COALESCE(exp.total_cheltuieli, 0) AS total_cheltuieli
            " . $from . $whereData['where'] . "
            ORDER BY v.nr_inmatriculare ASC, c.data_inceput DESC, c.data_sfarsit DESC, c.id DESC
            " . ($fetchAllRows ? "" : "LIMIT :limit_rows OFFSET :offset_rows") . "
        ";

        $dataStmt = $this->db->prepare($dataSql);
        $this->bindParams($dataStmt, $whereData['params']);
        if (!$fetchAllRows) {
            $dataStmt->bindValue(':limit_rows', $perPage, PDO::PARAM_INT);
            $dataStmt->bindValue(':offset_rows', $offset, PDO::PARAM_INT);
        }
        $dataStmt->execute();
        $rows = $dataStmt->fetchAll();
        $raceIds = [];
        foreach ($rows as $row) {
            $raceId = (int) ($row['id'] ?? 0);
            if ($raceId > 0) {
                $raceIds[$raceId] = $raceId;
            }
        }
        if ($raceIds !== []) {
            $expenseRows = $this->getBillingCentralizerExpenses(array_values($raceIds));
            foreach ($rows as &$row) {
                $raceId = (int) ($row['id'] ?? 0);
                $row['expenses_breakdown'] = $expenseRows[$raceId] ?? [];
            }
            unset($row);
        }

        return [
            'rows' => $rows,
            'summary_by_status' => $summaryByStatus,
            'summary_by_status_transport' => $summaryByStatusTransport,
            'summary_totals' => $summaryTotals,
            'vehicle_km' => $vehicleKmRows,
            'expense_type_totals' => $expenseTypeTotals,
            'refacturare_type_totals' => $refacturareTypeTotals,
            'total_rows' => $totalRows,
            'total_pages' => $totalPages,
            'page' => $page,
        ];
    }

    public function getBillingOperationalLocationOptions(array $filters, string $search): array
    {
        $optionFilters = $filters;
        $optionFilters['locatie_operationala'] = [];
        $optionFilters['loc_incarcare'] = [];
        $optionFilters['zona_distributie'] = [];

        $whereData = $this->buildBillingCentralizerWhere($optionFilters, $search, true);
        $from = $this->raceFromSql();

        $sql = "
            SELECT
                c.tip_transport,
                li.nume AS loc_incarcare_nume,
                c.loc_plecare,
                c.loc_aspirare,
                c.loc_livrare,
                c.loc_livrare_cursa,
                zd.nume AS zona_distributie_nume
            " . $from . $whereData['where'] . "
            ORDER BY c.tip_transport ASC, li.nume ASC, c.loc_plecare ASC, c.loc_livrare ASC, zd.nume ASC
        ";

        $stmt = $this->db->prepare($sql);
        $this->bindParams($stmt, $whereData['params']);
        $stmt->execute();

        $options = [
            'all' => [],
            'primar' => [],
            'distributie' => [],
            'compresor' => [],
            'loc_incarcare' => [],
            'zona_distributie' => [],
        ];

        $addOption = static function (array &$bucket, mixed $value): void {
            $label = trim((string) ($value ?? ''));
            if ($label === '' || $label === '-') {
                return;
            }

            $key = function_exists('mb_strtolower') ? mb_strtolower($label, 'UTF-8') : strtolower($label);
            $bucket[$key] = $label;
        };

        foreach ($stmt->fetchAll() as $row) {
            $transportType = (string) ($row['tip_transport'] ?? '');
            $allValues = [
                $row['loc_incarcare_nume'] ?? '',
                $row['loc_plecare'] ?? '',
                $row['loc_aspirare'] ?? '',
                $row['loc_livrare'] ?? '',
                $row['loc_livrare_cursa'] ?? '',
                $row['zona_distributie_nume'] ?? '',
            ];

            foreach ($allValues as $value) {
                $addOption($options['all'], $value);
            }

            if (in_array($transportType, ['primar', 'primar_tona'], true)) {
                foreach ([$row['loc_incarcare_nume'] ?? '', $row['loc_plecare'] ?? ''] as $value) {
                    $addOption($options['primar'], $value);
                    $addOption($options['loc_incarcare'], $value);
                }
                continue;
            }

            if ($transportType === 'distributie') {
                foreach ([$row['loc_plecare'] ?? '', $row['loc_livrare'] ?? '', $row['loc_livrare_cursa'] ?? '', $row['zona_distributie_nume'] ?? ''] as $value) {
                    $addOption($options['distributie'], $value);
                    $addOption($options['zona_distributie'], $value);
                }
                continue;
            }

            if ($transportType === 'primar_distributie') {
                foreach ([$row['loc_incarcare_nume'] ?? '', $row['loc_plecare'] ?? ''] as $value) {
                    $addOption($options['loc_incarcare'], $value);
                }
                foreach ([$row['loc_livrare'] ?? '', $row['loc_livrare_cursa'] ?? '', $row['zona_distributie_nume'] ?? ''] as $value) {
                    $addOption($options['distributie'], $value);
                    $addOption($options['zona_distributie'], $value);
                }
                continue;
            }

            if ($transportType === 'compresor') {
                foreach ($allValues as $value) {
                    $addOption($options['compresor'], $value);
                }
            }
        }

        $finalize = static function (array $bucket): array {
            natcasesort($bucket);
            $rows = [];
            foreach ($bucket as $label) {
                $rows[] = [
                    'value' => $label,
                    'label' => $label,
                ];
            }

            return $rows;
        };

        foreach ($options as $key => $bucket) {
            $options[$key] = $finalize($bucket);
        }

        return $options;
    }

    private function getBillingCentralizerExpenses(array $raceIds): array
    {
        $raceIds = array_values(array_unique(array_filter(array_map('intval', $raceIds), static fn (int $raceId): bool => $raceId > 0)));
        if ($raceIds === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($raceIds as $index => $raceId) {
            $placeholder = ':billing_expense_race_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $raceId;
        }

        $this->ensureExpenseRefacturareColumn();
        $sql = "
            SELECT
                id,
                cursa_id,
                tip_cheltuiala,
                suma,
                data_cheltuiala,
                observatii,
                refacturare_tip_cheltuiala,
                refacturare_detalii,
                refacturare_suma,
                refacturare_facturata,
                refacturare_data,
                refacturare_observatii
            FROM curse_cheltuieli
            WHERE cursa_id IN (" . implode(', ', $placeholders) . ")
            ORDER BY data_cheltuiala ASC, id ASC
        ";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $placeholder => $raceId) {
            $stmt->bindValue($placeholder, $raceId, PDO::PARAM_INT);
        }
        $stmt->execute();

        $grouped = [];
        foreach ($stmt->fetchAll() as $row) {
            $raceId = (int) ($row['cursa_id'] ?? 0);
            if ($raceId <= 0) {
                continue;
            }

            if (!isset($grouped[$raceId])) {
                $grouped[$raceId] = [];
            }
            $grouped[$raceId][] = $row;
        }

        return $grouped;
    }

    /**
     * Candidatii pentru popup-ul "curse cu informatii lipsa": toate cursele active
     * inca "in curs de facturare", cu toate coloanele necesare detectiei per tip de
     * transport. Detectia efectiva (campuri lipsa + severitate) se face in controller
     * (buildRaceMissingInformation) pentru a nu duplica regulile de business in SQL.
     * O singura interogare — fara N+1.
     */
    public function getOpenRacesOverview(int $limit = 25): array
    {
        $this->ensureRaceExpenseStatusColumn();
        $this->ensureRaceSoftDeleteSchema();

        $limit = max(1, min(500, $limit));
        $billingStatusExpr = $this->defaultBillingStatusExpression();

        $listSql = "
            SELECT
                c.id,
                c.tip_transport,
                c.data_incarcare,
                c.data_inceput,
                c.data_sfarsit,
                c.ora_inceput,
                c.ora_sfarsit,
                c.status_facturare,
                c.updated_at,
                c.km_cursa,
                c.km_totali,
                c.nr_clienti,
                c.cantitate_incarcata,
                c.cantitate_prelevata,
                c.ore_aspirare,
                c.km_dislocare,
                c.tona_livrata,
                c.tona_aspirata_lichida,
                c.tona_aspirata_gazoasa,
                c.zona_distributie_id,
                c.loc_incarcare_id,
                c.tip_marfa,
                c.capacitate_transport,
                c.loc_plecare,
                c.loc_livrare,
                c.pret_tarifare,
                c.total_facturare,
                c.cost_km_distributie,
                c.cost_km_mixt,
                c.cheltuieli_status,
                v.nr_inmatriculare,
                v.marca,
                v.model,
                v.poza_original,
                v.poza_stocata,
                COALESCE(s.nume, '') AS sofer_nume,
                COALESCE(bt.nume, '') AS beneficiar_nume,
                COALESCE(li.nume, '') AS loc_incarcare_nume,
                COALESCE(zd.nume, '') AS zona_distributie_nume,
                COALESCE(exp.expense_count, 0) AS expense_count
            FROM curse_dispecer c
            INNER JOIN vehicule v ON v.id = c.vehicle_id
            LEFT JOIN soferi s ON s.id = c.driver_id
            LEFT JOIN configurare_beneficiari_transport bt ON bt.id = c.beneficiar_id
            LEFT JOIN configurare_locuri_incarcare li ON li.id = c.loc_incarcare_id
            LEFT JOIN configurare_zone_distributie zd ON zd.id = c.zona_distributie_id
            LEFT JOIN (
                SELECT cursa_id, COUNT(*) AS expense_count
                FROM curse_cheltuieli
                GROUP BY cursa_id
            ) exp ON exp.cursa_id = c.id
            WHERE c.deleted_at IS NULL
              AND " . $billingStatusExpr . " = :open_races_billing_status
            ORDER BY c.data_inceput ASC, c.id ASC
            LIMIT :limit_rows
        ";

        $listStmt = $this->db->prepare($listSql);
        $listStmt->bindValue(':open_races_billing_status', self::DEFAULT_BILLING_STATUS, PDO::PARAM_STR);
        $listStmt->bindValue(':limit_rows', $limit, PDO::PARAM_INT);
        $listStmt->execute();

        return [
            'rows' => $listStmt->fetchAll(),
        ];
    }

    public function getRaceById(int $id): ?array
    {
        $this->ensureRaceExpenseStatusColumn();
        $this->ensureRaceSoftDeleteSchema();

        $sql = "
            SELECT
                c.*,
                v.nr_inmatriculare,
                v.marca,
                v.model,
                s.nume AS sofer_nume,
                uc.nume AS creat_de_nume,
                li.nume AS loc_incarcare_nume,
                bt.nume AS beneficiar_nume,
                zd.nume AS zona_distributie_nume,
                COALESCE(exp.expense_count, 0) AS expense_count,
                COALESCE(exp.total_cheltuieli, 0) AS total_cheltuieli,
                COALESCE(exp.total_refacturare_facturata, 0) AS total_refacturare_facturata,
                COALESCE(exp.total_refacturare_pending, 0) AS total_refacturare_pending
            " . $this->raceFromSql() . "
            WHERE c.id = :id
              AND c.deleted_at IS NULL
            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function getRefacturareRaceOptions(int $limit = 500): array
    {
        $this->ensureExpenseRefacturareColumn();
        $this->ensureRaceSoftDeleteSchema();
        $limit = max(1, min(1000, $limit));

        $sql = "
            SELECT
                c.id,
                c.tip_transport,
                c.data_inceput,
                c.data_sfarsit,
                c.ora_inceput,
                c.ora_sfarsit,
                c.total_facturare,
                v.nr_inmatriculare,
                v.marca,
                v.model,
                s.nume AS sofer_nume,
                bt.nume AS beneficiar_nume,
                COALESCE(exp.total_refacturare, 0) AS total_refacturare,
                COALESCE(exp.total_refacturare_facturata, 0) AS total_refacturare_facturata,
                COALESCE(exp.total_refacturare_nefacturata, 0) AS total_refacturare_nefacturata
            FROM curse_dispecer c
            INNER JOIN vehicule v ON v.id = c.vehicle_id
            LEFT JOIN soferi s ON s.id = c.driver_id
            LEFT JOIN configurare_beneficiari_transport bt ON bt.id = c.beneficiar_id
            LEFT JOIN (
                SELECT
                    cursa_id,
                    SUM(COALESCE(refacturare_suma, 0)) AS total_refacturare,
                    SUM(CASE WHEN COALESCE(refacturare_facturata, 0) = 1 THEN COALESCE(refacturare_suma, 0) ELSE 0 END) AS total_refacturare_facturata,
                    SUM(CASE WHEN COALESCE(refacturare_facturata, 0) = 1 THEN 0 ELSE COALESCE(refacturare_suma, 0) END) AS total_refacturare_nefacturata
                FROM curse_cheltuieli
                GROUP BY cursa_id
            ) exp ON exp.cursa_id = c.id
            WHERE c.deleted_at IS NULL
            ORDER BY c.data_inceput DESC, c.id DESC
            LIMIT :limit_rows
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':limit_rows', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getRefacturareEntries(?int $raceId = null, int $limit = 250): array
    {
        $this->ensureExpenseRefacturareColumn();
        $this->ensureRaceSoftDeleteSchema();
        $limit = max(1, min(1000, $limit));

        $sql = "
            SELECT
                e.id,
                e.cursa_id,
                e.tip_cheltuiala,
                e.refacturare_tip_cheltuiala,
                e.refacturare_detalii,
                e.refacturare_suma,
                e.refacturare_data,
                e.refacturare_observatii,
                e.refacturare_document_path,
                e.refacturare_document_original_name,
                e.refacturare_facturata,
                e.refacturare_facturata_at,
                e.created_at,
                c.tip_transport,
                c.data_inceput,
                c.ora_inceput,
                c.data_sfarsit,
                c.ora_sfarsit,
                v.nr_inmatriculare,
                s.nume AS sofer_nume,
                bt.nume AS beneficiar_nume
            FROM curse_cheltuieli e
            INNER JOIN curse_dispecer c ON c.id = e.cursa_id
            INNER JOIN vehicule v ON v.id = c.vehicle_id
            LEFT JOIN soferi s ON s.id = c.driver_id
            LEFT JOIN configurare_beneficiari_transport bt ON bt.id = c.beneficiar_id
            WHERE COALESCE(e.refacturare_suma, 0) > 0
              AND c.deleted_at IS NULL
        ";

        if ($raceId !== null && $raceId > 0) {
            $sql .= " AND e.cursa_id = :cursa_id";
        }

        $sql .= "
            ORDER BY COALESCE(e.refacturare_data, e.data_cheltuiala) DESC, e.id DESC
            LIMIT :limit_rows
        ";

        $stmt = $this->db->prepare($sql);
        if ($raceId !== null && $raceId > 0) {
            $stmt->bindValue(':cursa_id', $raceId, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit_rows', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getRefacturarePlateOptions(): array
    {
        $this->ensureExpenseRefacturareColumn();
        $this->ensureRaceSoftDeleteSchema();

        $stmt = $this->db->prepare("
            SELECT
                v.nr_inmatriculare,
                MIN(v.marca) AS marca,
                MIN(v.model) AS model,
                COUNT(DISTINCT c.id) AS race_count
            FROM curse_dispecer c
            INNER JOIN vehicule v ON v.id = c.vehicle_id
            WHERE COALESCE(TRIM(v.nr_inmatriculare), '') <> ''
              AND c.deleted_at IS NULL
            GROUP BY v.nr_inmatriculare
            ORDER BY v.nr_inmatriculare ASC
        ");
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getRefacturareHistory(array $filters, string $sort, string $direction, int $page, int $perPage): array
    {
        $this->ensureRaceCompressorLocationColumns();
        $this->ensureExpenseRefacturareColumn();

        $page = max(1, $page);
        $perPage = max(5, min(100, $perPage));
        $direction = strtolower($direction) === 'asc' ? 'ASC' : 'DESC';

        $mainSql = $this->buildRefacturareHistoryMainSql($filters, 'refhist');

        $summaryStmt = $this->db->prepare("
            SELECT
                COUNT(*) AS total_count,
                COALESCE(SUM(COALESCE(e.refacturare_suma, 0)), 0) AS total_amount,
                SUM(CASE WHEN COALESCE(e.refacturare_facturata, 0) = 0 THEN 1 ELSE 0 END) AS pending_count,
                COALESCE(SUM(CASE WHEN COALESCE(e.refacturare_facturata, 0) = 0 THEN COALESCE(e.refacturare_suma, 0) ELSE 0 END), 0) AS pending_amount,
                SUM(CASE WHEN COALESCE(e.refacturare_facturata, 0) = 1 THEN 1 ELSE 0 END) AS invoiced_count,
                COALESCE(SUM(CASE WHEN COALESCE(e.refacturare_facturata, 0) = 1 THEN COALESCE(e.refacturare_suma, 0) ELSE 0 END), 0) AS invoiced_amount
            " . $mainSql['from'] . $mainSql['where'] . "
        ");
        $this->bindParams($summaryStmt, $mainSql['params']);
        $summaryStmt->execute();
        $summaryRow = $summaryStmt->fetch() ?: [];

        $totalRows = (int) ($summaryRow['total_count'] ?? 0);
        $totalPages = max(1, (int) ceil($totalRows / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $sortMap = [
            'date' => 'COALESCE(e.refacturare_data, e.data_cheltuiala)',
            'race' => 'v.nr_inmatriculare',
            'type' => 'COALESCE(e.refacturare_tip_cheltuiala, e.tip_cheltuiala)',
            'amount' => 'COALESCE(e.refacturare_suma, 0)',
            'status' => 'COALESCE(e.refacturare_facturata, 0)',
        ];
        $orderColumn = $sortMap[$sort] ?? $sortMap['date'];

        $dataSql = "
            SELECT
                e.id,
                e.cursa_id,
                e.tip_cheltuiala,
                e.refacturare_tip_cheltuiala,
                e.refacturare_detalii,
                e.refacturare_suma,
                e.refacturare_data,
                e.refacturare_observatii,
                e.refacturare_document_path,
                e.refacturare_document_original_name,
                e.refacturare_facturata,
                e.refacturare_facturata_at,
                e.data_cheltuiala,
                e.observatii,
                e.created_at,
                e.updated_at,
                c.tip_transport,
                c.data_cursa,
                c.data_inceput,
                c.ora_inceput,
                c.data_sfarsit,
                c.ora_sfarsit,
                c.loc_plecare,
                c.loc_aspirare,
                c.loc_livrare,
                c.loc_livrare_cursa,
                v.nr_inmatriculare,
                v.marca,
                v.model,
                s.nume AS sofer_nume,
                bt.nume AS beneficiar_nume,
                li.nume AS loc_incarcare_nume,
                zd.nume AS zona_distributie_nume,
                cc.nume AS categorie_nume
            " . $mainSql['from'] . $mainSql['where'] . "
            ORDER BY " . $orderColumn . " " . $direction . ", e.id " . $direction . "
            LIMIT :limit_rows OFFSET :offset_rows
        ";

        $dataStmt = $this->db->prepare($dataSql);
        $this->bindParams($dataStmt, $mainSql['params']);
        $dataStmt->bindValue(':limit_rows', $perPage, PDO::PARAM_INT);
        $dataStmt->bindValue(':offset_rows', $offset, PDO::PARAM_INT);
        $dataStmt->execute();

        return [
            'rows' => $dataStmt->fetchAll(),
            'summary' => [
                'total_count' => $totalRows,
                'total_amount' => round((float) ($summaryRow['total_amount'] ?? 0), 2),
                'pending_count' => (int) ($summaryRow['pending_count'] ?? 0),
                'pending_amount' => round((float) ($summaryRow['pending_amount'] ?? 0), 2),
                'invoiced_count' => (int) ($summaryRow['invoiced_count'] ?? 0),
                'invoiced_amount' => round((float) ($summaryRow['invoiced_amount'] ?? 0), 2),
            ],
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total_rows' => $totalRows,
                'total_pages' => $totalPages,
            ],
        ];
    }

    private function buildRefacturareHistoryMainSql(array $filters, string $prefix): array
    {
        $this->ensureRaceSoftDeleteSchema();

        $where = [
            'COALESCE(e.refacturare_suma, 0) > 0',
            'c.deleted_at IS NULL',
        ];
        $params = [];
        $dateExpr = 'COALESCE(e.refacturare_data, e.data_cheltuiala)';

        if (($filters['data_start'] ?? '') !== '') {
            $where[] = $dateExpr . ' >= :' . $prefix . '_data_start';
            $params[':' . $prefix . '_data_start'] = (string) $filters['data_start'];
        }

        if (($filters['data_end'] ?? '') !== '') {
            $where[] = $dateExpr . ' <= :' . $prefix . '_data_end';
            $params[':' . $prefix . '_data_end'] = (string) $filters['data_end'];
        }

        if (($filters['nr_inmatriculare'] ?? '') !== '') {
            $where[] = 'v.nr_inmatriculare = :' . $prefix . '_plate';
            $params[':' . $prefix . '_plate'] = (string) $filters['nr_inmatriculare'];
        }

        if (($filters['tip_refacturare'] ?? '') !== '') {
            $where[] = 'COALESCE(e.refacturare_tip_cheltuiala, e.tip_cheltuiala) = :' . $prefix . '_type';
            $params[':' . $prefix . '_type'] = (string) $filters['tip_refacturare'];
        }

        if (($filters['status_factura'] ?? '') === 'in_asteptare') {
            $where[] = 'COALESCE(e.refacturare_facturata, 0) = 0';
        } elseif (($filters['status_factura'] ?? '') === 'factura_emisa') {
            $where[] = 'COALESCE(e.refacturare_facturata, 0) = 1';
        }

        if (($filters['document'] ?? '') === 'cu_document') {
            $where[] = "COALESCE(TRIM(e.refacturare_document_path), '') <> ''";
        } elseif (($filters['document'] ?? '') === 'fara_document') {
            $where[] = "COALESCE(TRIM(e.refacturare_document_path), '') = ''";
        }

        if (($filters['q'] ?? '') !== '') {
            $where[] = "(
                COALESCE(e.refacturare_observatii, '') LIKE :" . $prefix . "_search_ref_obs
                OR COALESCE(e.refacturare_detalii, '') LIKE :" . $prefix . "_search_ref_details
                OR REPLACE(COALESCE(e.refacturare_detalii, ''), '_', ' ') LIKE :" . $prefix . "_search_ref_details_readable
                OR COALESCE(e.observatii, '') LIKE :" . $prefix . "_search_obs
                OR COALESCE(cc.nume, '') LIKE :" . $prefix . "_search_category
                OR COALESCE(e.refacturare_tip_cheltuiala, '') LIKE :" . $prefix . "_search_type
            )";
            $searchValue = '%' . (string) $filters['q'] . '%';
            $params[':' . $prefix . '_search_ref_obs'] = $searchValue;
            $params[':' . $prefix . '_search_ref_details'] = $searchValue;
            $params[':' . $prefix . '_search_ref_details_readable'] = $searchValue;
            $params[':' . $prefix . '_search_obs'] = $searchValue;
            $params[':' . $prefix . '_search_category'] = $searchValue;
            $params[':' . $prefix . '_search_type'] = $searchValue;
        }

        return [
            'from' => "
                FROM curse_cheltuieli e
                INNER JOIN curse_dispecer c ON c.id = e.cursa_id
                INNER JOIN vehicule v ON v.id = c.vehicle_id
                LEFT JOIN soferi s ON s.id = c.driver_id
                LEFT JOIN configurare_beneficiari_transport bt ON bt.id = c.beneficiar_id
                LEFT JOIN configurare_locuri_incarcare li ON li.id = c.loc_incarcare_id
                LEFT JOIN configurare_zone_distributie zd ON zd.id = c.zona_distributie_id
                LEFT JOIN categorii_cheltuieli_curse cc ON cc.id = e.categorie_id
            ",
            'where' => ' WHERE ' . implode(' AND ', $where),
            'params' => $params,
        ];
    }

    public function getExpenseCategories(bool $onlyActive = false): array
    {
        $this->ensureExpenseCategorySchema();

        $sql = "
            SELECT
                c.id,
                c.nume,
                c.descriere,
                c.activ,
                c.legacy_key,
                c.created_at,
                c.updated_at,
                COALESCE(u.usage_count, 0) AS usage_count
            FROM categorii_cheltuieli_curse c
            LEFT JOIN (
                SELECT categorie_id, COUNT(*) AS usage_count
                FROM curse_cheltuieli
                WHERE categorie_id IS NOT NULL
                GROUP BY categorie_id
            ) u ON u.categorie_id = c.id
            " . ($onlyActive ? "WHERE c.activ = 1" : "") . "
            ORDER BY c.activ DESC, c.nume ASC
        ";

        $stmt = $this->db->query($sql);

        return $stmt->fetchAll();
    }

    public function getExpenseCategoryById(int $id): ?array
    {
        $this->ensureExpenseCategorySchema();

        $stmt = $this->db->prepare("
            SELECT id, nume, descriere, activ, legacy_key, created_at, updated_at
            FROM categorii_cheltuieli_curse
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function resolveExpenseCategorySelection(string $selection, bool $onlyActive = true): ?array
    {
        $this->ensureExpenseCategorySchema();

        $selection = trim($selection);
        if ($selection === '') {
            return null;
        }

        if (is_numeric($selection)) {
            $sql = "
                SELECT id, nume, descriere, activ, legacy_key
                FROM categorii_cheltuieli_curse
                WHERE id = :id
                " . ($onlyActive ? "AND activ = 1" : "") . "
                LIMIT 1
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':id', (int) $selection, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch();

            return $row ?: null;
        }

        $sql = "
            SELECT id, nume, descriere, activ, legacy_key
            FROM categorii_cheltuieli_curse
            WHERE legacy_key = :legacy_key
            " . ($onlyActive ? "AND activ = 1" : "") . "
            LIMIT 1
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':legacy_key', $selection, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function legacyExpenseTypeForCategory(?array $category): string
    {
        $legacyKey = trim((string) ($category['legacy_key'] ?? ''));
        if (array_key_exists($legacyKey, self::DEFAULT_EXPENSE_CATEGORIES)) {
            return $legacyKey;
        }

        return 'alte';
    }

    public function createExpenseCategory(string $name, ?string $description, bool $active): int
    {
        $this->ensureExpenseCategorySchema();

        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare("
            INSERT INTO categorii_cheltuieli_curse (nume, descriere, activ, legacy_key, created_at, updated_at)
            VALUES (:nume, :descriere, :activ, NULL, :created_at, :updated_at)
        ");
        $stmt->bindValue(':nume', $name, PDO::PARAM_STR);
        $this->bindNullableString($stmt, ':descriere', $description);
        $stmt->bindValue(':activ', $active ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':created_at', $now, PDO::PARAM_STR);
        $stmt->bindValue(':updated_at', $now, PDO::PARAM_STR);
        $stmt->execute();

        return (int) $this->db->lastInsertId();
    }

    public function updateExpenseCategory(int $id, string $name, ?string $description, bool $active): bool
    {
        $this->ensureExpenseCategorySchema();

        $stmt = $this->db->prepare("
            UPDATE categorii_cheltuieli_curse
            SET nume = :nume,
                descriere = :descriere,
                activ = :activ,
                updated_at = :updated_at
            WHERE id = :id
        ");
        $stmt->bindValue(':nume', $name, PDO::PARAM_STR);
        $this->bindNullableString($stmt, ':descriere', $description);
        $stmt->bindValue(':activ', $active ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function archiveExpenseCategory(int $id): bool
    {
        $this->ensureExpenseCategorySchema();

        $stmt = $this->db->prepare("
            UPDATE categorii_cheltuieli_curse
            SET activ = 0, updated_at = :updated_at
            WHERE id = :id
        ");
        $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function getExpenseCategoryUsageCount(int $id): int
    {
        $this->ensureExpenseCategorySchema();

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM curse_cheltuieli WHERE categorie_id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    public function deleteExpenseCategoryIfUnused(int $id): bool
    {
        $this->ensureExpenseCategorySchema();

        if ($this->getExpenseCategoryUsageCount($id) > 0) {
            return false;
        }

        $stmt = $this->db->prepare("DELETE FROM categorii_cheltuieli_curse WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function getCourseExpenseHistoryOptions(): array
    {
        $this->ensureExpenseCategorySchema();
        $this->ensureRaceSoftDeleteSchema();

        $vehicles = $this->getVehicleOptions();

        $driverStmt = $this->db->query("
            SELECT id, nume
            FROM soferi
            ORDER BY nume ASC
        ");

        $raceStmt = $this->db->query("
            SELECT
                c.id,
                c.data_inceput,
                c.total_facturare,
                v.nr_inmatriculare,
                COALESCE(s.nume, '') AS sofer_nume
            FROM curse_dispecer c
            INNER JOIN vehicule v ON v.id = c.vehicle_id
            LEFT JOIN soferi s ON s.id = c.driver_id
            WHERE c.deleted_at IS NULL
              AND EXISTS (
                SELECT 1
                FROM curse_cheltuieli e
                WHERE e.cursa_id = c.id
                  AND e.tip_cheltuiala <> 'motorina'
                  AND COALESCE(e.suma, 0) > 0
            )
            ORDER BY c.data_inceput DESC, c.id DESC
            LIMIT 700
        ");

        $addExpenseRaceStmt = $this->db->query("
            SELECT
                c.id,
                c.vehicle_id,
                c.driver_id,
                c.data_inceput,
                c.total_facturare,
                v.nr_inmatriculare,
                COALESCE(s.nume, '') AS sofer_nume
            FROM curse_dispecer c
            INNER JOIN vehicule v ON v.id = c.vehicle_id
            LEFT JOIN soferi s ON s.id = c.driver_id
            WHERE c.deleted_at IS NULL
            ORDER BY c.data_inceput DESC, c.id DESC
            LIMIT 700
        ");

        return [
            'categories' => $this->getExpenseCategories(false),
            'active_categories' => $this->getExpenseCategories(true),
            'vehicles' => $vehicles,
            'drivers' => $driverStmt->fetchAll(),
            'races' => $raceStmt->fetchAll(),
            'add_expense_races' => $addExpenseRaceStmt->fetchAll(),
        ];
    }

    public function getCourseExpenseHistoryData(array $filters, int $page = 1, int $perPage = 10): array
    {
        $this->ensureExpenseCategorySchema();

        $page = max(1, $page);
        $perPage = max(5, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $mainSql = $this->buildCourseExpenseHistoryMainSql($filters, 'hist');

        $countStmt = $this->db->prepare("SELECT COUNT(*) " . $mainSql['from'] . $mainSql['where']);
        $this->bindParams($countStmt, $mainSql['params']);
        $countStmt->execute();
        $totalRows = (int) $countStmt->fetchColumn();

        $summaryStmt = $this->db->prepare("
            SELECT
                COUNT(*) AS numar_curse,
                COALESCE(SUM(COALESCE(c.total_facturare, 0)), 0) AS venit_total,
                COALESCE(SUM(COALESCE(exp.total_cheltuieli, 0)), 0) AS cheltuieli_totale
            " . $mainSql['from'] . $mainSql['where'] . "
        ");
        $this->bindParams($summaryStmt, $mainSql['params']);
        $summaryStmt->execute();
        $summary = $summaryStmt->fetch() ?: [];

        $dataSql = "
            SELECT
                c.id,
                c.data_inceput AS data_cursa,
                c.total_facturare AS venit_cursa,
                v.nr_inmatriculare,
                COALESCE(s.nume, '') AS sofer_nume,
                exp.total_cheltuieli,
                exp.expense_count
            " . $mainSql['from'] . $mainSql['where'] . "
            ORDER BY c.data_inceput DESC, c.id DESC
            LIMIT :limit_rows OFFSET :offset_rows
        ";
        $dataStmt = $this->db->prepare($dataSql);
        $this->bindParams($dataStmt, $mainSql['params']);
        $dataStmt->bindValue(':limit_rows', $perPage, PDO::PARAM_INT);
        $dataStmt->bindValue(':offset_rows', $offset, PDO::PARAM_INT);
        $dataStmt->execute();
        $rows = $dataStmt->fetchAll();

        $raceIds = [];
        foreach ($rows as $row) {
            $raceId = (int) ($row['id'] ?? 0);
            if ($raceId > 0) {
                $raceIds[] = $raceId;
            }
        }

        $topCategoriesByRace = $this->getCourseExpenseTopCategoriesForRaceIds($raceIds, $filters);
        $detailsByRace = $this->getCourseExpenseDetailsForRaceIds($raceIds);

        foreach ($rows as &$row) {
            $raceId = (int) ($row['id'] ?? 0);
            $revenue = (float) ($row['venit_cursa'] ?? 0);
            $expenses = (float) ($row['total_cheltuieli'] ?? 0);
            $profit = $revenue - $expenses;
            $row['profit'] = round($profit, 2);
            $row['marja_profit'] = $revenue > 0 ? round(($profit / $revenue) * 100, 2) : 0.0;
            $row['top_categorie'] = $topCategoriesByRace[$raceId] ?? null;
            $row['expenses'] = $detailsByRace[$raceId] ?? [];
        }
        unset($row);

        $venitTotal = (float) ($summary['venit_total'] ?? 0);
        $cheltuieliTotale = (float) ($summary['cheltuieli_totale'] ?? 0);
        $profitTotal = $venitTotal - $cheltuieliTotale;

        return [
            'rows' => $rows,
            'summary' => [
                'venit_total' => round($venitTotal, 2),
                'cheltuieli_totale' => round($cheltuieliTotale, 2),
                'profit_total' => round($profitTotal, 2),
                'marja_profit' => $venitTotal > 0 ? round(($profitTotal / $venitTotal) * 100, 2) : 0.0,
                'numar_curse' => (int) ($summary['numar_curse'] ?? 0),
            ],
            'charts' => $this->getCourseExpenseHistoryCharts($filters),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total_rows' => $totalRows,
                'total_pages' => max(1, (int) ceil($totalRows / $perPage)),
            ],
        ];
    }

    public function getCourseExpenseHistoryExportRows(array $filters): array
    {
        $this->ensureExpenseCategorySchema();

        $mainSql = $this->buildCourseExpenseHistoryMainSql($filters, 'export');
        $stmt = $this->db->prepare("
            SELECT
                c.id,
                c.data_inceput AS data_cursa,
                c.total_facturare AS venit_cursa,
                v.nr_inmatriculare,
                COALESCE(s.nume, '') AS sofer_nume,
                exp.total_cheltuieli
            " . $mainSql['from'] . $mainSql['where'] . "
            ORDER BY c.data_inceput DESC, c.id DESC
        ");
        $this->bindParams($stmt, $mainSql['params']);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $raceIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows);
        $topCategoriesByRace = $this->getCourseExpenseTopCategoriesForRaceIds($raceIds, $filters);

        foreach ($rows as &$row) {
            $raceId = (int) ($row['id'] ?? 0);
            $revenue = (float) ($row['venit_cursa'] ?? 0);
            $expenses = (float) ($row['total_cheltuieli'] ?? 0);
            $profit = $revenue - $expenses;
            $row['profit'] = round($profit, 2);
            $row['marja_profit'] = $revenue > 0 ? round(($profit / $revenue) * 100, 2) : 0.0;
            $row['top_categorie'] = $topCategoriesByRace[$raceId] ?? null;
        }
        unset($row);

        return $rows;
    }

    public function updateRefacturareInvoicedStatus(int $expenseId, bool $isInvoiced, ?string $invoicedAt = null): bool
    {
        $this->ensureExpenseRefacturareColumn();

        $sql = "
            UPDATE curse_cheltuieli
            SET
                refacturare_facturata = :is_invoiced,
                refacturare_facturata_at = :invoiced_at,
                updated_at = :updated_at
            WHERE id = :id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':is_invoiced', $isInvoiced ? 1 : 0, PDO::PARAM_INT);
        if ($isInvoiced && $invoicedAt !== null && trim($invoicedAt) !== '') {
            $stmt->bindValue(':invoiced_at', trim($invoicedAt), PDO::PARAM_STR);
        } else {
            $stmt->bindValue(':invoiced_at', null, PDO::PARAM_NULL);
        }
        $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':id', $expenseId, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function findDuplicateRaceId(array $data, ?int $excludeRaceId = null): ?int
    {
        $this->ensureRaceDuplicateKeySchema();
        $this->ensureRaceSoftDeleteSchema();

        $sql = "
            SELECT id
            FROM curse_dispecer
            WHERE duplicate_key = :duplicate_key
              AND deleted_at IS NULL
            " . ($excludeRaceId !== null && $excludeRaceId > 0 ? " AND id <> :exclude_id" : "") . "
            ORDER BY id ASC
            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':duplicate_key', $this->buildRaceDuplicateKey($data), PDO::PARAM_STR);
        if ($excludeRaceId !== null && $excludeRaceId > 0) {
            $stmt->bindValue(':exclude_id', $excludeRaceId, PDO::PARAM_INT);
        }
        $stmt->execute();

        $duplicateId = (int) $stmt->fetchColumn();
        return $duplicateId > 0 ? $duplicateId : null;
    }

    public function createRace(array $data): int
    {
        $this->ensureRaceCompressorLocationColumns();
        $this->ensureRaceCostPerKmColumns();
        $this->ensureRaceLoadingDateColumn();
        $this->ensureRaceCreatedByColumn();
        $this->ensureRaceExpenseStatusColumn();
        $this->ensureRaceDuplicateKeySchema();
        $data['duplicate_key'] = $this->buildRaceDuplicateKey($data);

        $sql = "
            INSERT INTO curse_dispecer (
                vehicle_id,
                driver_id,
                tip_transport,
                data_cursa,
                data_incarcare,
                data_inceput,
                data_sfarsit,
                ora_inceput,
                ora_sfarsit,
                durata_cursa_minute,
                loc_incarcare_id,
                loc_plecare,
                loc_aspirare,
                loc_livrare,
                loc_livrare_cursa,
                beneficiar_id,
                tip_marfa,
                capacitate_transport,
                cantitate_incarcata,
                cantitate_prelevata,
                nr_clienti,
                km_cursa,
                ore_functionare,
                km_totali,
                ore_aspirare,
                km_dislocare,
                tona_livrata,
                tona_aspirata_lichida,
                tona_aspirata_gazoasa,
                zona_distributie_id,
                status_facturare,
                pret_tarifare,
                total_facturare,
                cost_km_primar,
                cost_km_distributie,
                cost_km_mixt,
                cost_km_compresor,
                observatii,
                created_by,
                created_at,
                updated_at,
                duplicate_key
            ) VALUES (
                :vehicle_id,
                :driver_id,
                :tip_transport,
                :data_cursa,
                :data_incarcare,
                :data_inceput,
                :data_sfarsit,
                :ora_inceput,
                :ora_sfarsit,
                :durata_cursa_minute,
                :loc_incarcare_id,
                :loc_plecare,
                :loc_aspirare,
                :loc_livrare,
                :loc_livrare_cursa,
                :beneficiar_id,
                :tip_marfa,
                :capacitate_transport,
                :cantitate_incarcata,
                :cantitate_prelevata,
                :nr_clienti,
                :km_cursa,
                :ore_functionare,
                :km_totali,
                :ore_aspirare,
                :km_dislocare,
                :tona_livrata,
                :tona_aspirata_lichida,
                :tona_aspirata_gazoasa,
                :zona_distributie_id,
                :status_facturare,
                :pret_tarifare,
                :total_facturare,
                :cost_km_primar,
                :cost_km_distributie,
                :cost_km_mixt,
                :cost_km_compresor,
                :observatii,
                :created_by,
                :created_at,
                :updated_at,
                :duplicate_key
            )
        ";

        $stmt = $this->db->prepare($sql);
        $this->bindRaceMutationValues($stmt, $data);
        $stmt->execute();

        return (int) $this->db->lastInsertId();
    }

    public function createRaceAndSyncVehicleKm(array $data): array
    {
        $this->ensureRaceAuditLogSchema();
        $this->ensureRaceDuplicateKeySchema();
        $this->db->beginTransaction();

        try {
            $raceId = $this->createRace($data);
            $alerts = $this->syncVehicleKmForRaceChange(null, $data);
            $this->logRaceAudit($raceId, 'created', isset($data['created_by']) ? (int) $data['created_by'] : null);

            $this->db->commit();

            return [
                'race_id' => $raceId,
                'maintenance_alerts' => $alerts,
            ];
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    public function updateRace(int $id, array $data): bool
    {
        $this->ensureRaceCompressorLocationColumns();
        $this->ensureRaceCostPerKmColumns();
        $this->ensureRaceLoadingDateColumn();
        $this->ensureRaceDuplicateKeySchema();
        $this->ensureRaceSoftDeleteSchema();
        $data['duplicate_key'] = $this->buildRaceDuplicateKey($data);

        $sql = "
            UPDATE curse_dispecer
            SET
                vehicle_id = :vehicle_id,
                driver_id = :driver_id,
                tip_transport = :tip_transport,
                data_cursa = :data_cursa,
                data_incarcare = :data_incarcare,
                data_inceput = :data_inceput,
                data_sfarsit = :data_sfarsit,
                ora_inceput = :ora_inceput,
                ora_sfarsit = :ora_sfarsit,
                durata_cursa_minute = :durata_cursa_minute,
                loc_incarcare_id = :loc_incarcare_id,
                loc_plecare = :loc_plecare,
                loc_aspirare = :loc_aspirare,
                loc_livrare = :loc_livrare,
                loc_livrare_cursa = :loc_livrare_cursa,
                beneficiar_id = :beneficiar_id,
                tip_marfa = :tip_marfa,
                capacitate_transport = :capacitate_transport,
                cantitate_incarcata = :cantitate_incarcata,
                cantitate_prelevata = :cantitate_prelevata,
                nr_clienti = :nr_clienti,
                km_cursa = :km_cursa,
                ore_functionare = :ore_functionare,
                km_totali = :km_totali,
                ore_aspirare = :ore_aspirare,
                km_dislocare = :km_dislocare,
                tona_livrata = :tona_livrata,
                tona_aspirata_lichida = :tona_aspirata_lichida,
                tona_aspirata_gazoasa = :tona_aspirata_gazoasa,
                zona_distributie_id = :zona_distributie_id,
                status_facturare = :status_facturare,
                pret_tarifare = :pret_tarifare,
                total_facturare = :total_facturare,
                cost_km_primar = :cost_km_primar,
                cost_km_distributie = :cost_km_distributie,
                cost_km_mixt = :cost_km_mixt,
                cost_km_compresor = :cost_km_compresor,
                observatii = :observatii,
                duplicate_key = :duplicate_key,
                updated_at = :updated_at
            WHERE id = :id
              AND deleted_at IS NULL
        ";

        $stmt = $this->db->prepare($sql);
        $this->bindRaceMutationValues($stmt, $data);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function updateRaceAndSyncVehicleKm(int $id, array $data, ?int $userId = null): array
    {
        $this->ensureRaceAuditLogSchema();
        $this->ensureRaceDuplicateKeySchema();
        $this->db->beginTransaction();

        try {
            $previousRace = $this->getRaceSnapshotForUpdate($id);
            if ($previousRace === null) {
                throw new RuntimeException('Cursa nu exista pentru actualizare.');
            }

            if (!$this->updateRace($id, $data)) {
                throw new RuntimeException('Actualizarea cursei a esuat.');
            }
            $alerts = $this->syncVehicleKmForRaceChange($previousRace, $data);
            $this->logRaceAudit($id, 'updated', $userId);

            $this->db->commit();

            return [
                'maintenance_alerts' => $alerts,
            ];
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    public function updateRaceBillingStatus(int $id, string $billingStatus, string $updatedAt, ?int $userId = null): bool
    {
        $this->ensureRaceAuditLogSchema();

        $stmt = $this->db->prepare("
            UPDATE curse_dispecer
            SET status_facturare = :status_facturare,
                updated_at = :updated_at
            WHERE id = :id
              AND deleted_at IS NULL
        ");
        $stmt->bindValue(':status_facturare', $billingStatus, PDO::PARAM_STR);
        $stmt->bindValue(':updated_at', $updatedAt, PDO::PARAM_STR);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        $updated = $stmt->execute();
        if ($updated && $stmt->rowCount() > 0) {
            $this->logRaceAudit($id, 'status_changed', $userId, [
                'status_facturare' => $billingStatus,
            ]);
        }

        return $updated;
    }

    public function updateRaceExpenseStatus(int $id, string $expenseStatus, string $updatedAt, ?int $userId = null): bool
    {
        $this->ensureRaceExpenseStatusColumn();
        $this->ensureRaceAuditLogSchema();

        if (!in_array($expenseStatus, ['pending', 'not_applicable'], true)) {
            $expenseStatus = 'pending';
        }

        $stmt = $this->db->prepare("
            UPDATE curse_dispecer
            SET cheltuieli_status = :cheltuieli_status,
                updated_at = :updated_at
            WHERE id = :id
              AND deleted_at IS NULL
        ");
        $stmt->bindValue(':cheltuieli_status', $expenseStatus, PDO::PARAM_STR);
        $stmt->bindValue(':updated_at', $updatedAt, PDO::PARAM_STR);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        $updated = $stmt->execute();
        if ($updated && $stmt->rowCount() > 0) {
            $this->logRaceAudit($id, 'status_changed', $userId, [
                'cheltuieli_status' => $expenseStatus,
            ]);
        }

        return $updated;
    }

    public function deleteRace(int $id, ?int $userId = null): bool
    {
        $this->ensureRaceSoftDeleteSchema();

        $deletedAt = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare("
            UPDATE curse_dispecer
            SET deleted_at = :deleted_at,
                deleted_by = :deleted_by,
                duplicate_key = NULL,
                updated_at = :updated_at
            WHERE id = :id
              AND deleted_at IS NULL
        ");
        $stmt->bindValue(':deleted_at', $deletedAt, PDO::PARAM_STR);
        if ($userId !== null && $userId > 0) {
            $stmt->bindValue(':deleted_by', $userId, PDO::PARAM_INT);
        } else {
            $stmt->bindValue(':deleted_by', null, PDO::PARAM_NULL);
        }
        $stmt->bindValue(':updated_at', $deletedAt, PDO::PARAM_STR);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    public function deleteRaceAndSyncVehicleKm(int $id, ?int $userId = null): bool
    {
        $this->ensureRaceAuditLogSchema();
        $this->db->beginTransaction();

        try {
            $previousRace = $this->getRaceSnapshotForUpdate($id);
            if ($previousRace === null) {
                throw new RuntimeException('Cursa nu exista pentru stergere.');
            }

            $deleted = $this->deleteRace($id, $userId);
            if (!$deleted) {
                throw new RuntimeException('Stergerea cursei a esuat.');
            }
            $this->syncVehicleKmForRaceChange($previousRace, null);
            $this->logRaceAudit((int) $previousRace['id'], 'deleted', $userId, [
                'nr_inmatriculare' => $previousRace['nr_inmatriculare'] ?? null,
                'data_cursa' => $previousRace['data_cursa'] ?? null,
                'duplicate_key' => $previousRace['duplicate_key'] ?? null,
            ]);

            $this->db->commit();

            return $deleted;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    public function restoreDeletedRaceAndSyncVehicleKm(int $id, ?int $userId = null): bool
    {
        $this->ensureRaceAuditLogSchema();
        $this->ensureRaceDuplicateKeySchema();
        $this->db->beginTransaction();

        try {
            $deletedRace = $this->getRaceSnapshotForUpdate($id, true);
            if ($deletedRace === null || trim((string) ($deletedRace['deleted_at'] ?? '')) === '') {
                throw new RuntimeException('Cursa nu exista in lista de curse sterse.');
            }

            $duplicateRaceId = $this->findDuplicateRaceId($deletedRace, $id);
            if ($duplicateRaceId !== null) {
                throw new RuntimeException('Exista deja o cursa activa cu aceleasi date. Restaurarea a fost oprita.');
            }

            $duplicateKey = $this->buildRaceDuplicateKey($deletedRace);
            $updatedAt = date('Y-m-d H:i:s');
            $stmt = $this->db->prepare("
                UPDATE curse_dispecer
                SET deleted_at = NULL,
                    duplicate_key = :duplicate_key,
                    updated_at = :updated_at
                WHERE id = :id
                  AND deleted_at IS NOT NULL
            ");
            $stmt->bindValue(':duplicate_key', $duplicateKey, PDO::PARAM_STR);
            $stmt->bindValue(':updated_at', $updatedAt, PDO::PARAM_STR);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();

            if ($stmt->rowCount() === 0) {
                throw new RuntimeException('Restaurarea cursei a esuat.');
            }

            $restoredRace = $deletedRace;
            $restoredRace['deleted_at'] = null;
            $restoredRace['duplicate_key'] = $duplicateKey;
            $this->syncVehicleKmForRaceChange(null, $restoredRace);
            $this->logRaceAudit($id, 'restored', $userId, [
                'deleted_at' => $deletedRace['deleted_at'] ?? null,
                'deleted_by' => $deletedRace['deleted_by'] ?? null,
            ]);

            $this->db->commit();

            return true;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    public function permanentlyDeleteDeletedRace(int $id): bool
    {
        $this->ensureRaceSoftDeleteSchema();
        $this->ensureRaceAuditLogSchema();
        $this->ensureExpenseRefacturareColumn();
        $this->db->beginTransaction();

        try {
            $deletedRace = $this->getRaceSnapshotForUpdate($id, true);
            if ($deletedRace === null || trim((string) ($deletedRace['deleted_at'] ?? '')) === '') {
                throw new RuntimeException('Cursa nu exista in lista de curse sterse.');
            }

            $deleteExpenseDocumentsStmt = $this->db->prepare("
                DELETE FROM curse_cheltuieli_documente
                WHERE cheltuiala_id IN (
                    SELECT id
                    FROM curse_cheltuieli
                    WHERE cursa_id = :id_docs
                )
            ");
            $deleteExpenseDocumentsStmt->bindValue(':id_docs', $id, PDO::PARAM_INT);
            $deleteExpenseDocumentsStmt->execute();

            $deleteExpensesStmt = $this->db->prepare("
                DELETE FROM curse_cheltuieli
                WHERE cursa_id = :id_expenses
            ");
            $deleteExpensesStmt->bindValue(':id_expenses', $id, PDO::PARAM_INT);
            $deleteExpensesStmt->execute();

            $deleteAuditStmt = $this->db->prepare("
                DELETE FROM cursa_audit_log
                WHERE cursa_id = :id_audit
            ");
            $deleteAuditStmt->bindValue(':id_audit', $id, PDO::PARAM_INT);
            $deleteAuditStmt->execute();

            $deleteRaceStmt = $this->db->prepare("
                DELETE FROM curse_dispecer
                WHERE id = :id
                  AND deleted_at IS NOT NULL
            ");
            $deleteRaceStmt->bindValue(':id', $id, PDO::PARAM_INT);
            $deleteRaceStmt->execute();

            if ($deleteRaceStmt->rowCount() === 0) {
                throw new RuntimeException('Stergerea definitiva a cursei a esuat.');
            }

            $this->db->commit();

            return true;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    private function logRaceAudit(int $raceId, string $action, ?int $userId, array $details = []): void
    {
        $this->ensureRaceAuditLogSchema();

        if ($raceId <= 0) {
            return;
        }

        if (!in_array($action, ['created', 'updated', 'deleted', 'restored', 'status_changed'], true)) {
            $action = 'updated';
        }

        $detailsJson = $details === []
            ? null
            : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $stmt = $this->db->prepare("
            INSERT INTO cursa_audit_log (
                cursa_id,
                action,
                performed_by,
                performed_at,
                details_json
            ) VALUES (
                :cursa_id,
                :action,
                :performed_by,
                :performed_at,
                :details_json
            )
        ");
        $stmt->bindValue(':cursa_id', $raceId, PDO::PARAM_INT);
        $stmt->bindValue(':action', $action, PDO::PARAM_STR);
        if ($userId !== null && $userId > 0) {
            $stmt->bindValue(':performed_by', $userId, PDO::PARAM_INT);
        } else {
            $stmt->bindValue(':performed_by', null, PDO::PARAM_NULL);
        }
        $stmt->bindValue(':performed_at', date('Y-m-d H:i:s'), PDO::PARAM_STR);
        if ($detailsJson !== null && $detailsJson !== false) {
            $stmt->bindValue(':details_json', $detailsJson, PDO::PARAM_STR);
        } else {
            $stmt->bindValue(':details_json', null, PDO::PARAM_NULL);
        }
        $stmt->execute();
    }

    public function getDeletedRaceFilterOptions(): array
    {
        $this->ensureRaceSoftDeleteSchema();

        $vehicleStmt = $this->db->query("
            SELECT
                v.id,
                v.nr_inmatriculare,
                v.marca,
                v.model,
                COUNT(*) AS total_curse
            FROM curse_dispecer c
            INNER JOIN vehicule v ON v.id = c.vehicle_id
            WHERE c.deleted_at IS NOT NULL
            GROUP BY v.id, v.nr_inmatriculare, v.marca, v.model
            ORDER BY v.nr_inmatriculare ASC
        ");

        $driverStmt = $this->db->query("
            SELECT
                s.id,
                s.nume,
                COUNT(*) AS total_curse
            FROM curse_dispecer c
            INNER JOIN soferi s ON s.id = c.driver_id
            WHERE c.deleted_at IS NOT NULL
            GROUP BY s.id, s.nume
            ORDER BY s.nume ASC
        ");

        $beneficiaryStmt = $this->db->query("
            SELECT
                bt.id,
                bt.nume,
                COUNT(*) AS total_curse
            FROM curse_dispecer c
            INNER JOIN configurare_beneficiari_transport bt ON bt.id = c.beneficiar_id
            WHERE c.deleted_at IS NOT NULL
            GROUP BY bt.id, bt.nume
            ORDER BY bt.nume ASC
        ");

        $deletedByStmt = $this->db->query("
            SELECT
                u.id,
                u.nume,
                u.rol,
                COUNT(*) AS total_curse
            FROM curse_dispecer c
            INNER JOIN utilizatori u ON u.id = c.deleted_by
            WHERE c.deleted_at IS NOT NULL
            GROUP BY u.id, u.nume, u.rol
            ORDER BY u.nume ASC
        ");

        return [
            'vehicles' => $vehicleStmt->fetchAll(),
            'drivers' => $driverStmt->fetchAll(),
            'beneficiaries' => $beneficiaryStmt->fetchAll(),
            'deleted_by_users' => $deletedByStmt->fetchAll(),
        ];
    }

    public function getDeletedRacesPage(array $filters, int $page, int $perPage): array
    {
        $this->ensureRaceAuditLogSchema();

        $page = max(1, $page);
        $perPage = in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 10;
        $whereData = $this->buildDeletedRaceWhere($filters, 'delrace');
        $from = $this->deletedRaceFromSql();

        $monthStart = (new DateTimeImmutable('first day of this month'))->format('Y-m-d');
        $monthEnd = (new DateTimeImmutable('last day of this month'))->format('Y-m-d');
        $summaryParams = array_merge($whereData['params'], [
            ':deleted_month_start' => $monthStart,
            ':deleted_month_end' => $monthEnd,
        ]);

        $summarySql = "
            SELECT
                COUNT(*) AS total_deleted,
                SUM(CASE WHEN DATE(c.deleted_at) BETWEEN :deleted_month_start AND :deleted_month_end THEN 1 ELSE 0 END) AS deleted_this_month,
                COUNT(DISTINCT c.deleted_by) AS users_involved
            " . $from . $whereData['where'];
        $summaryStmt = $this->db->prepare($summarySql);
        $this->bindParams($summaryStmt, $summaryParams);
        $summaryStmt->execute();
        $summaryRow = $summaryStmt->fetch() ?: [];

        $totalRows = max(0, (int) ($summaryRow['total_deleted'] ?? 0));
        $totalPages = max(1, (int) ceil($totalRows / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $dataSql = "
            SELECT
                c.*,
                v.nr_inmatriculare,
                v.marca,
                v.model,
                s.nume AS sofer_nume,
                bt.nume AS beneficiar_nume,
                li.nume AS loc_incarcare_nume,
                zd.nume AS zona_distributie_nume,
                ud.nume AS deleted_by_nume,
                ud.rol AS deleted_by_rol,
                uc.nume AS created_by_nume,
                uc.rol AS created_by_rol
            " . $from . $whereData['where'] . "
            ORDER BY c.deleted_at DESC, c.id DESC
            LIMIT :limit_rows OFFSET :offset_rows
        ";
        $dataStmt = $this->db->prepare($dataSql);
        $this->bindParams($dataStmt, $whereData['params']);
        $dataStmt->bindValue(':limit_rows', $perPage, PDO::PARAM_INT);
        $dataStmt->bindValue(':offset_rows', $offset, PDO::PARAM_INT);
        $dataStmt->execute();

        return [
            'rows' => $dataStmt->fetchAll(),
            'summary' => [
                'total_deleted' => $totalRows,
                'deleted_this_month' => max(0, (int) ($summaryRow['deleted_this_month'] ?? 0)),
                'users_involved' => max(0, (int) ($summaryRow['users_involved'] ?? 0)),
                'month_start' => $monthStart,
                'month_end' => $monthEnd,
            ],
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total_rows' => $totalRows,
                'total_pages' => $totalPages,
            ],
        ];
    }

    public function getDeletedRaceDetails(int $id): ?array
    {
        $this->ensureRaceAuditLogSchema();

        $stmt = $this->db->prepare("
            SELECT
                c.*,
                v.nr_inmatriculare,
                v.marca,
                v.model,
                s.nume AS sofer_nume,
                bt.nume AS beneficiar_nume,
                li.nume AS loc_incarcare_nume,
                zd.nume AS zona_distributie_nume,
                ud.nume AS deleted_by_nume,
                ud.rol AS deleted_by_rol,
                uc.nume AS created_by_nume,
                uc.rol AS created_by_rol
            " . $this->deletedRaceFromSql() . "
            WHERE c.id = :id
              AND c.deleted_at IS NOT NULL
            LIMIT 1
        ");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $race = $stmt->fetch();
        if (!$race) {
            return null;
        }

        return [
            'race' => $race,
            'timeline' => $this->buildDeletedRaceTimeline($race),
        ];
    }

    private function deletedRaceFromSql(): string
    {
        $this->ensureRaceCompressorLocationColumns();
        $this->ensureRaceSoftDeleteSchema();

        return "
            FROM curse_dispecer c
            INNER JOIN vehicule v ON v.id = c.vehicle_id
            LEFT JOIN soferi s ON s.id = c.driver_id
            LEFT JOIN configurare_locuri_incarcare li ON li.id = c.loc_incarcare_id
            LEFT JOIN configurare_beneficiari_transport bt ON bt.id = c.beneficiar_id
            LEFT JOIN configurare_zone_distributie zd ON zd.id = c.zona_distributie_id
            LEFT JOIN utilizatori ud ON ud.id = c.deleted_by
            LEFT JOIN utilizatori uc ON uc.id = c.created_by
        ";
    }

    private function buildDeletedRaceWhere(array $filters, string $prefix): array
    {
        $where = [
            'c.deleted_at IS NOT NULL',
        ];
        $params = [];
        $raceDateExpr = 'COALESCE(c.data_inceput, c.data_cursa)';

        if (($filters['vehicle_id'] ?? '') !== '') {
            $where[] = 'c.vehicle_id = :' . $prefix . '_vehicle_id';
            $params[':' . $prefix . '_vehicle_id'] = (int) $filters['vehicle_id'];
        }

        if (($filters['tip_transport'] ?? '') !== '') {
            $where[] = 'c.tip_transport = :' . $prefix . '_tip_transport';
            $params[':' . $prefix . '_tip_transport'] = (string) $filters['tip_transport'];
        }

        if (($filters['driver_id'] ?? '') !== '') {
            $where[] = 'c.driver_id = :' . $prefix . '_driver_id';
            $params[':' . $prefix . '_driver_id'] = (int) $filters['driver_id'];
        }

        if (($filters['beneficiar_id'] ?? '') !== '') {
            $where[] = 'c.beneficiar_id = :' . $prefix . '_beneficiar_id';
            $params[':' . $prefix . '_beneficiar_id'] = (int) $filters['beneficiar_id'];
        }

        if (($filters['deleted_by'] ?? '') !== '') {
            $where[] = 'c.deleted_by = :' . $prefix . '_deleted_by';
            $params[':' . $prefix . '_deleted_by'] = (int) $filters['deleted_by'];
        }

        if (($filters['data_cursa_start'] ?? '') !== '') {
            $where[] = $raceDateExpr . ' >= :' . $prefix . '_data_cursa_start';
            $params[':' . $prefix . '_data_cursa_start'] = (string) $filters['data_cursa_start'];
        }

        if (($filters['data_cursa_end'] ?? '') !== '') {
            $where[] = $raceDateExpr . ' <= :' . $prefix . '_data_cursa_end';
            $params[':' . $prefix . '_data_cursa_end'] = (string) $filters['data_cursa_end'];
        }

        if (($filters['deleted_start'] ?? '') !== '') {
            $where[] = 'DATE(c.deleted_at) >= :' . $prefix . '_deleted_start';
            $params[':' . $prefix . '_deleted_start'] = (string) $filters['deleted_start'];
        }

        if (($filters['deleted_end'] ?? '') !== '') {
            $where[] = 'DATE(c.deleted_at) <= :' . $prefix . '_deleted_end';
            $params[':' . $prefix . '_deleted_end'] = (string) $filters['deleted_end'];
        }

        return [
            'where' => ' WHERE ' . implode(' AND ', $where),
            'params' => $params,
        ];
    }

    private function buildDeletedRaceTimeline(array $race): array
    {
        $raceId = (int) ($race['id'] ?? 0);
        if ($raceId <= 0) {
            return [];
        }

        $auditStmt = $this->db->prepare("
            SELECT
                a.action,
                a.performed_at,
                a.details_json,
                u.nume AS user_nume,
                u.rol AS user_rol
            FROM cursa_audit_log a
            LEFT JOIN utilizatori u ON u.id = a.performed_by
            WHERE a.cursa_id = :cursa_id
            ORDER BY a.performed_at ASC, a.id ASC
        ");
        $auditStmt->bindValue(':cursa_id', $raceId, PDO::PARAM_INT);
        $auditStmt->execute();

        $timeline = [];
        $createdAt = trim((string) ($race['created_at'] ?? ''));
        if ($createdAt !== '') {
            $timeline[] = [
                'action' => 'created',
                'performed_at' => $createdAt,
                'user_nume' => trim((string) ($race['created_by_nume'] ?? '')),
                'user_rol' => trim((string) ($race['created_by_rol'] ?? '')),
            ];
        }

        $updatedAt = trim((string) ($race['updated_at'] ?? ''));
        $deletedAt = trim((string) ($race['deleted_at'] ?? ''));
        if ($updatedAt !== '' && $updatedAt !== $createdAt && $updatedAt !== $deletedAt) {
            $timeline[] = [
                'action' => 'updated',
                'performed_at' => $updatedAt,
                'user_nume' => '',
                'user_rol' => '',
            ];
        }

        foreach ($auditStmt->fetchAll() as $row) {
            $timeline[] = [
                'action' => (string) ($row['action'] ?? 'updated'),
                'performed_at' => (string) ($row['performed_at'] ?? ''),
                'user_nume' => (string) ($row['user_nume'] ?? ''),
                'user_rol' => (string) ($row['user_rol'] ?? ''),
                'details_json' => (string) ($row['details_json'] ?? ''),
            ];
        }

        usort($timeline, static function (array $a, array $b): int {
            $dateCompare = strcmp((string) ($a['performed_at'] ?? ''), (string) ($b['performed_at'] ?? ''));
            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            $order = [
                'created' => 0,
                'updated' => 1,
                'status_changed' => 2,
                'deleted' => 3,
                'restored' => 4,
            ];

            return ($order[(string) ($a['action'] ?? '')] ?? 9) <=> ($order[(string) ($b['action'] ?? '')] ?? 9);
        });

        return $timeline;
    }

    public function getRaceExpenses(int $raceId): array
    {
        $this->ensureExpenseCategorySchema();

        $sql = "
            SELECT
                e.*,
                cc.nume AS categorie_nume,
                cc.legacy_key AS categorie_legacy_key,
                d.file_path,
                d.original_name,
                d.mime_type,
                d.file_size
            FROM curse_cheltuieli e
            LEFT JOIN categorii_cheltuieli_curse cc ON cc.id = e.categorie_id
            LEFT JOIN (
                SELECT d1.*
                FROM curse_cheltuieli_documente d1
                INNER JOIN (
                    SELECT cheltuiala_id, MAX(id) AS max_id
                    FROM curse_cheltuieli_documente
                    GROUP BY cheltuiala_id
                ) latest ON latest.max_id = d1.id
            ) d ON d.cheltuiala_id = e.id
            WHERE e.cursa_id = :cursa_id
              AND NOT (
                  COALESCE(e.refacturare_facturata, 0) = 1
                  AND COALESCE(e.refacturare_suma, 0) > 0
                  AND COALESCE(e.suma, 0) = 0
              )
            ORDER BY e.data_cheltuiala DESC, e.id DESC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':cursa_id', $raceId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getExpenseById(int $expenseId): ?array
    {
        $this->ensureExpenseCategorySchema();

        $sql = "
            SELECT
                e.*,
                cc.nume AS categorie_nume,
                cc.legacy_key AS categorie_legacy_key,
                d.file_path,
                d.original_name,
                d.mime_type,
                d.file_size
            FROM curse_cheltuieli e
            LEFT JOIN categorii_cheltuieli_curse cc ON cc.id = e.categorie_id
            LEFT JOIN (
                SELECT d1.*
                FROM curse_cheltuieli_documente d1
                INNER JOIN (
                    SELECT cheltuiala_id, MAX(id) AS max_id
                    FROM curse_cheltuieli_documente
                    GROUP BY cheltuiala_id
                ) latest ON latest.max_id = d1.id
            ) d ON d.cheltuiala_id = e.id
            WHERE e.id = :id
            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $expenseId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function createExpense(array $data): int
    {
        $this->ensureExpenseCategorySchema();

        $sql = "
            INSERT INTO curse_cheltuieli (
                cursa_id,
                tip_cheltuiala,
                categorie_id,
                refacturare_tip_cheltuiala,
                refacturare_detalii,
                refacturare_suma,
                refacturare_data,
                refacturare_observatii,
                suma,
                data_cheltuiala,
                observatii,
                added_by,
                created_at,
                updated_at
            ) VALUES (
                :cursa_id,
                :tip_cheltuiala,
                :categorie_id,
                :refacturare_tip_cheltuiala,
                :refacturare_detalii,
                :refacturare_suma,
                :refacturare_data,
                :refacturare_observatii,
                :suma,
                :data_cheltuiala,
                :observatii,
                :added_by,
                :created_at,
                :updated_at
            )
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':cursa_id', (int) $data['cursa_id'], PDO::PARAM_INT);
        $stmt->bindValue(':tip_cheltuiala', (string) $data['tip_cheltuiala']);
        $this->bindNullableInt($stmt, ':categorie_id', $data['categorie_id'] ?? null);
        $this->bindNullableString($stmt, ':refacturare_tip_cheltuiala', $data['refacturare_tip_cheltuiala'] ?? null);
        $this->bindNullableString($stmt, ':refacturare_detalii', $data['refacturare_detalii'] ?? null);
        $this->bindNullableDecimal($stmt, ':refacturare_suma', $data['refacturare_suma'] ?? null);
        $this->bindNullableString($stmt, ':refacturare_data', $data['refacturare_data'] ?? null);
        $this->bindNullableString($stmt, ':refacturare_observatii', $data['refacturare_observatii'] ?? null);
        $stmt->bindValue(':suma', (float) $data['suma']);
        $stmt->bindValue(':data_cheltuiala', (string) $data['data_cheltuiala']);
        $this->bindNullableString($stmt, ':observatii', $data['observatii'] ?? null);
        $this->bindNullableInt($stmt, ':added_by', $data['added_by'] ?? null);
        $stmt->bindValue(':created_at', (string) $data['created_at']);
        $stmt->bindValue(':updated_at', (string) $data['updated_at']);
        $stmt->execute();

        return (int) $this->db->lastInsertId();
    }

    public function updateExpense(int $id, array $data): bool
    {
        $this->ensureExpenseCategorySchema();

        $sql = "
            UPDATE curse_cheltuieli
            SET
                tip_cheltuiala = :tip_cheltuiala,
                categorie_id = :categorie_id,
                refacturare_tip_cheltuiala = :refacturare_tip_cheltuiala,
                refacturare_detalii = :refacturare_detalii,
                refacturare_suma = :refacturare_suma,
                refacturare_data = :refacturare_data,
                refacturare_observatii = :refacturare_observatii,
                suma = :suma,
                data_cheltuiala = :data_cheltuiala,
                observatii = :observatii,
                updated_at = :updated_at
            WHERE id = :id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':tip_cheltuiala', (string) $data['tip_cheltuiala']);
        $this->bindNullableInt($stmt, ':categorie_id', $data['categorie_id'] ?? null);
        $this->bindNullableString($stmt, ':refacturare_tip_cheltuiala', $data['refacturare_tip_cheltuiala'] ?? null);
        $this->bindNullableString($stmt, ':refacturare_detalii', $data['refacturare_detalii'] ?? null);
        $this->bindNullableDecimal($stmt, ':refacturare_suma', $data['refacturare_suma'] ?? null);
        $this->bindNullableString($stmt, ':refacturare_data', $data['refacturare_data'] ?? null);
        $this->bindNullableString($stmt, ':refacturare_observatii', $data['refacturare_observatii'] ?? null);
        $stmt->bindValue(':suma', (float) $data['suma']);
        $stmt->bindValue(':data_cheltuiala', (string) $data['data_cheltuiala']);
        $this->bindNullableString($stmt, ':observatii', $data['observatii'] ?? null);
        $stmt->bindValue(':updated_at', (string) $data['updated_at']);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function deleteExpense(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM curse_cheltuieli WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function updateExpenseRefacturareDocument(int $id, ?array $document): bool
    {
        $this->ensureExpenseRefacturareColumn();

        $sql = "
            UPDATE curse_cheltuieli
            SET
                refacturare_document_path = :file_path,
                refacturare_document_original_name = :original_name,
                refacturare_document_mime_type = :mime_type,
                refacturare_document_file_size = :file_size
            WHERE id = :id
        ";

        $stmt = $this->db->prepare($sql);
        $this->bindNullableString($stmt, ':file_path', $document['file_path'] ?? null);
        $this->bindNullableString($stmt, ':original_name', $document['original_name'] ?? null);
        $this->bindNullableString($stmt, ':mime_type', $document['mime_type'] ?? null);
        if ($document !== null && isset($document['file_size']) && is_numeric((string) $document['file_size'])) {
            $stmt->bindValue(':file_size', (int) $document['file_size'], PDO::PARAM_INT);
        } else {
            $stmt->bindValue(':file_size', null, PDO::PARAM_NULL);
        }
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function addExpenseDocument(array $data): int
    {
        $sql = "
            INSERT INTO curse_cheltuieli_documente (
                cheltuiala_id,
                file_path,
                original_name,
                mime_type,
                file_size,
                created_at
            ) VALUES (
                :cheltuiala_id,
                :file_path,
                :original_name,
                :mime_type,
                :file_size,
                :created_at
            )
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':cheltuiala_id', (int) $data['cheltuiala_id'], PDO::PARAM_INT);
        $stmt->bindValue(':file_path', (string) $data['file_path']);
        $stmt->bindValue(':original_name', (string) $data['original_name']);
        $stmt->bindValue(':mime_type', (string) $data['mime_type']);
        $stmt->bindValue(':file_size', (int) $data['file_size'], PDO::PARAM_INT);
        $stmt->bindValue(':created_at', (string) $data['created_at']);
        $stmt->execute();

        return (int) $this->db->lastInsertId();
    }

    public function getExpenseDocumentsByExpenseId(int $expenseId): array
    {
        $sql = "
            SELECT id, cheltuiala_id, file_path, original_name, mime_type, file_size, created_at
            FROM curse_cheltuieli_documente
            WHERE cheltuiala_id = :cheltuiala_id
            ORDER BY id DESC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':cheltuiala_id', $expenseId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getExpenseDocumentsByRaceId(int $raceId): array
    {
        $this->ensureExpenseRefacturareColumn();

        $sql = "
            SELECT d.id, d.file_path
            FROM curse_cheltuieli_documente d
            INNER JOIN curse_cheltuieli e ON e.id = d.cheltuiala_id
            WHERE e.cursa_id = :cursa_id_docs
            UNION ALL
            SELECT 0 AS id, e.refacturare_document_path AS file_path
            FROM curse_cheltuieli e
            WHERE e.cursa_id = :cursa_id_ref
              AND e.refacturare_document_path IS NOT NULL
              AND e.refacturare_document_path <> ''
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':cursa_id_docs', $raceId, PDO::PARAM_INT);
        $stmt->bindValue(':cursa_id_ref', $raceId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function clearExpenseDocuments(int $expenseId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM curse_cheltuieli_documente WHERE cheltuiala_id = :cheltuiala_id");
        $stmt->bindValue(':cheltuiala_id', $expenseId, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function createLoadLocation(int $beneficiaryId, string $name, float $tariff, bool $active): int
    {
        $sql = "
            INSERT INTO configurare_locuri_incarcare (beneficiar_id, nume, tarif, activ, created_at, updated_at)
            VALUES (:beneficiar_id, :nume, :tarif, :activ, :created_at, :updated_at)
        ";

        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare($sql);
        $this->bindNullableInt($stmt, ':beneficiar_id', $beneficiaryId > 0 ? $beneficiaryId : null);
        $stmt->bindValue(':nume', $name);
        $stmt->bindValue(':tarif', $tariff);
        $stmt->bindValue(':activ', $active ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':created_at', $now);
        $stmt->bindValue(':updated_at', $now);
        $stmt->execute();

        return (int) $this->db->lastInsertId();
    }

    public function getLoadLocationById(int $id): ?array
    {
        $sql = "
            SELECT id, beneficiar_id, nume, tarif, activ
            FROM configurare_locuri_incarcare
            WHERE id = :id
            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function getLoadLocationByNameForBeneficiary(int $beneficiaryId, string $name): ?array
    {
        $sql = "
            SELECT id, beneficiar_id, nume, tarif, activ
            FROM configurare_locuri_incarcare
            WHERE beneficiar_id = :beneficiar_id
              AND LOWER(TRIM(nume)) = LOWER(TRIM(:nume))
            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':beneficiar_id', $beneficiaryId, PDO::PARAM_INT);
        $stmt->bindValue(':nume', $name);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function getVehicleIdsForLoadLocation(int $locationId, int $beneficiaryId): array
    {
        $sql = "
            SELECT vehicle_id
            FROM configurare_locuri_incarcare_vehicule
            WHERE loc_incarcare_id = :loc_incarcare_id
              AND beneficiar_id = :beneficiar_id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':loc_incarcare_id', $locationId, PDO::PARAM_INT);
        $stmt->bindValue(':beneficiar_id', $beneficiaryId, PDO::PARAM_INT);
        $stmt->execute();

        $ids = [];
        foreach ($stmt->fetchAll() as $row) {
            $vehicleId = (int) ($row['vehicle_id'] ?? 0);
            if ($vehicleId > 0) {
                $ids[] = $vehicleId;
            }
        }

        return array_values(array_unique($ids));
    }

    public function updateLoadLocation(int $id, int $beneficiaryId, string $name, float $tariff, bool $active): bool
    {
        $sql = "
            UPDATE configurare_locuri_incarcare
            SET
                beneficiar_id = :beneficiar_id,
                nume = :nume,
                tarif = :tarif,
                activ = :activ,
                updated_at = :updated_at
            WHERE id = :id
            " . ($beneficiaryId > 0 ? " AND beneficiar_id = :where_beneficiar_id" : "") . "
        ";

        $stmt = $this->db->prepare($sql);
        $this->bindNullableInt($stmt, ':beneficiar_id', $beneficiaryId > 0 ? $beneficiaryId : null);
        $stmt->bindValue(':nume', $name);
        $stmt->bindValue(':tarif', $tariff);
        $stmt->bindValue(':activ', $active ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'));
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        if ($beneficiaryId > 0) {
            $stmt->bindValue(':where_beneficiar_id', $beneficiaryId, PDO::PARAM_INT);
        }

        return $stmt->execute();
    }

    public function deleteLoadLocation(int $id): bool
    {
        $this->db->beginTransaction();

        try {
            $unlinkVehicleMapStmt = $this->db->prepare("
                DELETE FROM configurare_locuri_incarcare_vehicule
                WHERE loc_incarcare_id = :id
            ");
            $unlinkVehicleMapStmt->bindValue(':id', $id, PDO::PARAM_INT);
            $unlinkVehicleMapStmt->execute();

            // Daca locul este deja folosit in curse, scoatem referinta pentru a permite stergerea.
            $unlinkStmt = $this->db->prepare("
                UPDATE curse_dispecer
                SET loc_incarcare_id = NULL,
                    updated_at = :updated_at
                WHERE loc_incarcare_id = :id
            ");
            $unlinkStmt->bindValue(':updated_at', date('Y-m-d H:i:s'));
            $unlinkStmt->bindValue(':id', $id, PDO::PARAM_INT);
            $unlinkStmt->execute();

            $deleteStmt = $this->db->prepare("DELETE FROM configurare_locuri_incarcare WHERE id = :id");
            $deleteStmt->bindValue(':id', $id, PDO::PARAM_INT);
            $deleted = $deleteStmt->execute();

            $this->db->commit();

            return $deleted;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    public function createDistributionZone(int $beneficiaryId, string $name, float $tariff, float $extraKmCost, bool $active): int
    {
        $sql = "
            INSERT INTO configurare_zone_distributie (beneficiar_id, nume, tarif_distributie, cost_extra_km, activ, created_at, updated_at)
            VALUES (:beneficiar_id, :nume, :tarif_distributie, :cost_extra_km, :activ, :created_at, :updated_at)
        ";

        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare($sql);
        $this->bindNullableInt($stmt, ':beneficiar_id', $beneficiaryId > 0 ? $beneficiaryId : null);
        $stmt->bindValue(':nume', $name);
        $stmt->bindValue(':tarif_distributie', $tariff);
        $stmt->bindValue(':cost_extra_km', $extraKmCost);
        $stmt->bindValue(':activ', $active ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':created_at', $now);
        $stmt->bindValue(':updated_at', $now);
        $stmt->execute();

        return (int) $this->db->lastInsertId();
    }

    public function getDistributionZoneById(int $id): ?array
    {
        $sql = "
            SELECT id, beneficiar_id, nume, tarif_distributie, cost_extra_km, activ
            FROM configurare_zone_distributie
            WHERE id = :id
            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function getDistributionZoneByNameForBeneficiary(int $beneficiaryId, string $name): ?array
    {
        $sql = "
            SELECT id, beneficiar_id, nume, tarif_distributie, cost_extra_km, activ
            FROM configurare_zone_distributie
            WHERE beneficiar_id = :beneficiar_id
              AND LOWER(TRIM(nume)) = LOWER(TRIM(:nume))
            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':beneficiar_id', $beneficiaryId, PDO::PARAM_INT);
        $stmt->bindValue(':nume', $name);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function getVehicleIdsForDistributionZone(int $zoneId, int $beneficiaryId): array
    {
        $sql = "
            SELECT vehicle_id
            FROM configurare_zone_distributie_vehicule
            WHERE zona_distributie_id = :zona_distributie_id
              AND beneficiar_id = :beneficiar_id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':zona_distributie_id', $zoneId, PDO::PARAM_INT);
        $stmt->bindValue(':beneficiar_id', $beneficiaryId, PDO::PARAM_INT);
        $stmt->execute();

        $ids = [];
        foreach ($stmt->fetchAll() as $row) {
            $vehicleId = (int) ($row['vehicle_id'] ?? 0);
            if ($vehicleId > 0) {
                $ids[] = $vehicleId;
            }
        }

        return array_values(array_unique($ids));
    }

    public function updateDistributionZone(int $id, int $beneficiaryId, string $name, float $tariff, float $extraKmCost, bool $active): bool
    {
        $sql = "
            UPDATE configurare_zone_distributie
            SET
                beneficiar_id = :beneficiar_id,
                nume = :nume,
                tarif_distributie = :tarif_distributie,
                cost_extra_km = :cost_extra_km,
                activ = :activ,
                updated_at = :updated_at
            WHERE id = :id
            " . ($beneficiaryId > 0 ? " AND beneficiar_id = :where_beneficiar_id" : "") . "
        ";

        $stmt = $this->db->prepare($sql);
        $this->bindNullableInt($stmt, ':beneficiar_id', $beneficiaryId > 0 ? $beneficiaryId : null);
        $stmt->bindValue(':nume', $name);
        $stmt->bindValue(':tarif_distributie', $tariff);
        $stmt->bindValue(':cost_extra_km', $extraKmCost);
        $stmt->bindValue(':activ', $active ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'));
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        if ($beneficiaryId > 0) {
            $stmt->bindValue(':where_beneficiar_id', $beneficiaryId, PDO::PARAM_INT);
        }

        return $stmt->execute();
    }

    public function createTransportBeneficiary(
        string $name,
        string $goodsType,
        float $baseRate,
        bool $supportsPrimary,
        bool $supportsDistribution,
        bool $supportsPrimaryDistribution,
        bool $supportsCompressor,
        float $pricePerKm,
        float $pricePerTon,
        float $distributionPricePerKm,
        float $distributionPricePerTon,
        float $pricePerHourSuction,
        float $pricePerKmRelocation,
        float $pricePerDeliveredTon,
        float $pricePerSuctionLiquidTon,
        float $pricePerSuctionGasTon,
        bool $active
    ): int
    {
        $this->ensureTransportBeneficiaryColumns();

        $sql = "
            INSERT INTO configurare_beneficiari_transport (
                nume,
                tip_marfa,
                pret_tarifare,
                suporta_primar,
                suporta_distributie,
                suporta_primar_distributie,
                suporta_compresor,
                pret_km,
                pret_tona,
                pret_distributie_km,
                pret_distributie_tona,
                pret_ora_aspirare,
                pret_km_dislocare,
                pret_tona_livrata,
                pret_tona_aspirata_lichida,
                pret_tona_aspirata_gazoasa,
                activ,
                created_at,
                updated_at
            )
            VALUES (
                :nume,
                :tip_marfa,
                :pret_tarifare,
                :suporta_primar,
                :suporta_distributie,
                :suporta_primar_distributie,
                :suporta_compresor,
                :pret_km,
                :pret_tona,
                :pret_distributie_km,
                :pret_distributie_tona,
                :pret_ora_aspirare,
                :pret_km_dislocare,
                :pret_tona_livrata,
                :pret_tona_aspirata_lichida,
                :pret_tona_aspirata_gazoasa,
                :activ,
                :created_at,
                :updated_at
            )
        ";

        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':nume', $name);
        $this->bindNullableString($stmt, ':tip_marfa', $goodsType);
        $stmt->bindValue(':pret_tarifare', $baseRate);
        $stmt->bindValue(':suporta_primar', $supportsPrimary ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':suporta_distributie', $supportsDistribution ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':suporta_primar_distributie', $supportsPrimaryDistribution ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':suporta_compresor', $supportsCompressor ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':pret_km', $pricePerKm);
        $stmt->bindValue(':pret_tona', $pricePerTon);
        $stmt->bindValue(':pret_distributie_km', $distributionPricePerKm);
        $stmt->bindValue(':pret_distributie_tona', $distributionPricePerTon);
        $stmt->bindValue(':pret_ora_aspirare', $pricePerHourSuction);
        $stmt->bindValue(':pret_km_dislocare', $pricePerKmRelocation);
        $stmt->bindValue(':pret_tona_livrata', $pricePerDeliveredTon);
        $stmt->bindValue(':pret_tona_aspirata_lichida', $pricePerSuctionLiquidTon);
        $stmt->bindValue(':pret_tona_aspirata_gazoasa', $pricePerSuctionGasTon);
        $stmt->bindValue(':activ', $active ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':created_at', $now);
        $stmt->bindValue(':updated_at', $now);
        $stmt->execute();

        return (int) $this->db->lastInsertId();
    }

    public function updateTransportBeneficiary(
        int $id,
        string $name,
        string $goodsType,
        float $baseRate,
        bool $supportsPrimary,
        bool $supportsDistribution,
        bool $supportsPrimaryDistribution,
        bool $supportsCompressor,
        float $pricePerKm,
        float $pricePerTon,
        float $distributionPricePerKm,
        float $distributionPricePerTon,
        float $pricePerHourSuction,
        float $pricePerKmRelocation,
        float $pricePerDeliveredTon,
        float $pricePerSuctionLiquidTon,
        float $pricePerSuctionGasTon,
        bool $active
    ): bool
    {
        $this->ensureTransportBeneficiaryColumns();

        $sql = "
            UPDATE configurare_beneficiari_transport
            SET
                nume = :nume,
                tip_marfa = :tip_marfa,
                pret_tarifare = :pret_tarifare,
                suporta_primar = :suporta_primar,
                suporta_distributie = :suporta_distributie,
                suporta_primar_distributie = :suporta_primar_distributie,
                suporta_compresor = :suporta_compresor,
                pret_km = :pret_km,
                pret_tona = :pret_tona,
                pret_distributie_km = :pret_distributie_km,
                pret_distributie_tona = :pret_distributie_tona,
                pret_ora_aspirare = :pret_ora_aspirare,
                pret_km_dislocare = :pret_km_dislocare,
                pret_tona_livrata = :pret_tona_livrata,
                pret_tona_aspirata_lichida = :pret_tona_aspirata_lichida,
                pret_tona_aspirata_gazoasa = :pret_tona_aspirata_gazoasa,
                activ = :activ,
                updated_at = :updated_at
            WHERE id = :id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':nume', $name);
        $this->bindNullableString($stmt, ':tip_marfa', $goodsType);
        $stmt->bindValue(':pret_tarifare', $baseRate);
        $stmt->bindValue(':suporta_primar', $supportsPrimary ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':suporta_distributie', $supportsDistribution ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':suporta_primar_distributie', $supportsPrimaryDistribution ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':suporta_compresor', $supportsCompressor ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':pret_km', $pricePerKm);
        $stmt->bindValue(':pret_tona', $pricePerTon);
        $stmt->bindValue(':pret_distributie_km', $distributionPricePerKm);
        $stmt->bindValue(':pret_distributie_tona', $distributionPricePerTon);
        $stmt->bindValue(':pret_ora_aspirare', $pricePerHourSuction);
        $stmt->bindValue(':pret_km_dislocare', $pricePerKmRelocation);
        $stmt->bindValue(':pret_tona_livrata', $pricePerDeliveredTon);
        $stmt->bindValue(':pret_tona_aspirata_lichida', $pricePerSuctionLiquidTon);
        $stmt->bindValue(':pret_tona_aspirata_gazoasa', $pricePerSuctionGasTon);
        $stmt->bindValue(':activ', $active ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'));
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function deleteDistributionZone(int $id): bool
    {
        $this->db->beginTransaction();

        try {
            $unlinkVehicleMapStmt = $this->db->prepare("
                DELETE FROM configurare_zone_distributie_vehicule
                WHERE zona_distributie_id = :id
            ");
            $unlinkVehicleMapStmt->bindValue(':id', $id, PDO::PARAM_INT);
            $unlinkVehicleMapStmt->execute();

            // Daca zona este deja folosita in curse, decuplam referinta ca sa permitem stergerea.
            $unlinkStmt = $this->db->prepare("
                UPDATE curse_dispecer
                SET zona_distributie_id = NULL,
                    updated_at = :updated_at
                WHERE zona_distributie_id = :id
            ");
            $unlinkStmt->bindValue(':updated_at', date('Y-m-d H:i:s'));
            $unlinkStmt->bindValue(':id', $id, PDO::PARAM_INT);
            $unlinkStmt->execute();

            $deleteStmt = $this->db->prepare("DELETE FROM configurare_zone_distributie WHERE id = :id");
            $deleteStmt->bindValue(':id', $id, PDO::PARAM_INT);
            $deleted = $deleteStmt->execute();

            $this->db->commit();

            return $deleted;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    public function deleteTransportBeneficiary(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM configurare_beneficiari_transport WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function existsVehicle(int $id): bool
    {
        return $this->existsById('vehicule', $id);
    }

    public function getDriverById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, status, nume
            FROM soferi
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function getDriverVehicleAssignmentStatus(int $driverId, int $vehicleId): array
    {
        $this->ensureDriverVehicleAssignmentsSchema();

        if ($driverId <= 0) {
            return [
                'assignment_count' => 0,
                'assigned_to_vehicle' => false,
            ];
        }

        $stmt = $this->db->prepare("
            SELECT
                COUNT(*) AS assignment_count,
                SUM(CASE WHEN vehicle_id = :vehicle_id THEN 1 ELSE 0 END) AS selected_assignment_count
            FROM soferi_vehicule
            WHERE driver_id = :driver_id
        ");
        $stmt->bindValue(':driver_id', $driverId, PDO::PARAM_INT);
        $stmt->bindValue(':vehicle_id', $vehicleId, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch() ?: [];
        $assignmentCount = (int) ($row['assignment_count'] ?? 0);
        $selectedAssignmentCount = (int) ($row['selected_assignment_count'] ?? 0);

        return [
            'assignment_count' => $assignmentCount,
            'assigned_to_vehicle' => $vehicleId > 0 && $selectedAssignmentCount > 0,
        ];
    }

    public function getVehicleTransportCapacity(int $vehicleId): ?float
    {
        $stmt = $this->db->prepare("
            SELECT
                CASE
                    WHEN v.tip_vehicul = 'cap_tractor' THEN s.capacitate_transport
                    ELSE v.capacitate_transport
                END AS capacitate_transport
            FROM vehicule v
            LEFT JOIN (
                SELECT vc1.tractor_id, vc1.semiremorca_id
                FROM vehicule_cuplaje vc1
                INNER JOIN (
                    SELECT tractor_id, MAX(id) AS max_id
                    FROM vehicule_cuplaje
                    WHERE activ = 1
                    GROUP BY tractor_id
                ) latest ON latest.max_id = vc1.id
            ) vc ON vc.tractor_id = v.id
            LEFT JOIN vehicule s ON s.id = vc.semiremorca_id
            WHERE v.id = :id
            LIMIT 1
        ");
        $stmt->bindValue(':id', $vehicleId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        $capacity = $row['capacitate_transport'] ?? null;
        if ($capacity === null || $capacity === '') {
            return null;
        }

        return (float) $capacity;
    }

    public function existsActiveVehicle(int $id): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM vehicule
            WHERE id = :id
              AND status = 'activ'
              AND tip_vehicul NOT IN ('semiremorca', 'semiremorca_primar', 'semiremorca_distributie')
        ");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn() > 0;
    }

    public function existsRace(int $id): bool
    {
        $this->ensureRaceSoftDeleteSchema();

        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM curse_dispecer
            WHERE id = :id
              AND deleted_at IS NULL
        ");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn() > 0;
    }

    public function existsDeletedRace(int $id): bool
    {
        $this->ensureRaceSoftDeleteSchema();

        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM curse_dispecer
            WHERE id = :id
              AND deleted_at IS NOT NULL
        ");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn() > 0;
    }

    public function existsLoadLocation(int $id): bool
    {
        return $this->existsById('configurare_locuri_incarcare', $id);
    }

    public function existsLoadLocationForBeneficiary(int $id, int $beneficiaryId): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM configurare_locuri_incarcare
            WHERE id = :id
              AND beneficiar_id = :beneficiar_id
        ");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':beneficiar_id', $beneficiaryId, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn() > 0;
    }

    public function existsDistributionZone(int $id): bool
    {
        return $this->existsById('configurare_zone_distributie', $id);
    }

    public function existsDistributionZoneForBeneficiary(int $id, int $beneficiaryId): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM configurare_zone_distributie
            WHERE id = :id
              AND beneficiar_id = :beneficiar_id
        ");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':beneficiar_id', $beneficiaryId, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn() > 0;
    }

    public function existsTransportBeneficiary(int $id): bool
    {
        return $this->existsById('configurare_beneficiari_transport', $id);
    }

    public function getDashboardAnalyticFilterOptions(): array
    {
        $from = $this->dashboardFromSql();

        $vehiclesStmt = $this->db->query("
            SELECT DISTINCT
                c.vehicle_id AS id,
                v.nr_inmatriculare
            {$from}
            WHERE c.vehicle_id IS NOT NULL
              AND c.deleted_at IS NULL
            ORDER BY v.nr_inmatriculare ASC
        ");

        $driversStmt = $this->db->query("
            SELECT DISTINCT
                c.driver_id AS id,
                COALESCE(NULLIF(TRIM(s.nume), ''), 'Fara sofer') AS nume
            {$from}
            WHERE c.deleted_at IS NULL
            ORDER BY nume ASC
        ");

        $beneficiariesStmt = $this->db->query("
            SELECT DISTINCT
                c.beneficiar_id AS id,
                COALESCE(NULLIF(TRIM(bt.nume), ''), 'Fara beneficiar') AS nume
            {$from}
            WHERE c.deleted_at IS NULL
            ORDER BY nume ASC
        ");

        $transportTypesStmt = $this->db->query("
            SELECT DISTINCT c.tip_transport
            FROM curse_dispecer c
            WHERE COALESCE(TRIM(c.tip_transport), '') <> ''
              AND c.deleted_at IS NULL
            ORDER BY c.tip_transport ASC
        ");

        $statusesStmt = $this->db->query("
            SELECT DISTINCT c.status_facturare
            FROM curse_dispecer c
            WHERE COALESCE(TRIM(c.status_facturare), '') <> ''
              AND c.deleted_at IS NULL
            ORDER BY c.status_facturare ASC
        ");

        $capacitiesStmt = $this->db->query("
            SELECT DISTINCT c.capacitate_transport
            FROM curse_dispecer c
            WHERE c.capacitate_transport IS NOT NULL
              AND c.capacitate_transport > 0
              AND c.deleted_at IS NULL
            ORDER BY c.capacitate_transport ASC
        ");

        return [
            'vehicles' => $vehiclesStmt->fetchAll(),
            'drivers' => $driversStmt->fetchAll(),
            'beneficiaries' => $beneficiariesStmt->fetchAll(),
            'transport_types' => $transportTypesStmt->fetchAll(),
            'transport_capacities' => $capacitiesStmt->fetchAll(),
            'statuses' => $statusesStmt->fetchAll(),
        ];
    }

    private function dashboardTransportBuckets(): array
    {
        return [
            'distributie' => 'Distributie',
            'primar' => 'Primar',
            'primar_distributie' => 'Primar+Distributie',
            'compresor' => 'Compresor',
        ];
    }

    private function dashboardTransportBucketSql(): string
    {
        return "
            CASE
                WHEN c.tip_transport IN ('primar', 'primar_tona', 'primar_km') THEN 'primar'
                WHEN c.tip_transport IN ('primar_distributie', 'mixt') THEN 'primar_distributie'
                WHEN c.tip_transport = 'distributie' THEN 'distributie'
                WHEN c.tip_transport = 'compresor' THEN 'compresor'
                ELSE COALESCE(NULLIF(TRIM(c.tip_transport), ''), 'necunoscut')
            END
        ";
    }

    private function emptyDashboardTransportBreakdown(): array
    {
        $breakdown = [];
        foreach ($this->dashboardTransportBuckets() as $key => $label) {
            $breakdown[$key] = [
                'key' => $key,
                'label' => $label,
                'curse' => 0,
                'km' => 0.0,
                'tone' => 0.0,
            ];
        }

        return $breakdown;
    }

    public function getDashboardAnalyticData(array $filters): array
    {
        $from = $this->dashboardFromSql();
        $whereData = $this->buildDashboardWhere($filters);
        $fleetUtilizare = $this->calculateFleetUtilizationKpi($filters);

        $kmEffectiveExpr = "
            CASE
                WHEN c.km_totali IS NOT NULL AND c.km_totali > 0 THEN c.km_totali
                WHEN c.km_cursa IS NOT NULL AND c.km_cursa > 0 THEN c.km_cursa
                ELSE 0
            END
        ";
        $kmNefacturatiExpr = "
            CASE
                WHEN c.km_totali IS NOT NULL
                     AND c.km_totali > 0
                     AND c.km_cursa IS NOT NULL
                     AND c.km_cursa > 0
                     AND c.km_totali >= c.km_cursa
                THEN c.km_totali - c.km_cursa
                ELSE 0
            END
        ";
        $kmBilledExpr = "
            CASE
                WHEN c.km_cursa IS NOT NULL AND c.km_cursa > 0 THEN c.km_cursa
                WHEN c.km_totali IS NOT NULL AND c.km_totali > 0 THEN c.km_totali
                ELSE 0
            END
        ";
        $kmDistributieMixedExpr = "
            CASE
                WHEN c.km_totali IS NOT NULL
                     AND c.km_cursa IS NOT NULL
                THEN c.km_totali - c.km_cursa
                WHEN c.km_totali IS NOT NULL
                THEN c.km_totali
                WHEN c.km_cursa IS NOT NULL
                THEN -c.km_cursa
                ELSE 0
            END
        ";
        $kmDistributieMixedPositiveExpr = "GREATEST(0, (" . $kmDistributieMixedExpr . "))";
        $kmPrimarDashboardExpr = "
            CASE
                WHEN c.tip_transport IN ('primar', 'primar_tona', 'primar_km', 'primar_distributie', 'mixt')
                THEN (" . $kmBilledExpr . ")
                ELSE 0
            END
        ";
        $kmDistributieDashboardExpr = "
            CASE
                WHEN c.tip_transport = 'distributie'
                THEN (" . $kmBilledExpr . ")
                WHEN c.tip_transport IN ('primar_distributie', 'mixt')
                THEN (" . $kmDistributieMixedPositiveExpr . ")
                ELSE 0
            END
        ";
        $kmSavedExpr = "
            CASE
                WHEN c.tip_transport IN ('primar', 'primar_tona', 'primar_km')
                     AND c.km_cursa IS NOT NULL
                     AND c.km_cursa > 0
                     AND c.km_totali IS NOT NULL
                     AND c.km_totali > 0
                     AND c.km_cursa > c.km_totali
                THEN c.km_cursa - c.km_totali
                ELSE 0
            END
        ";
        $kmExcessExpr = "
            CASE
                WHEN c.tip_transport IN ('primar', 'primar_tona', 'primar_km')
                     AND c.km_cursa IS NOT NULL
                     AND c.km_cursa > 0
                     AND c.km_totali IS NOT NULL
                     AND c.km_totali > 0
                     AND c.km_totali > c.km_cursa
                THEN c.km_totali - c.km_cursa
                ELSE 0
            END
        ";
        $loadedTonsExpr = "
            CASE
                WHEN c.cantitate_incarcata IS NULL OR c.cantitate_incarcata <= 0 THEN 0
                WHEN c.capacitate_transport IS NOT NULL
                     AND c.capacitate_transport > 0
                     AND c.cantitate_incarcata > (c.capacitate_transport * 3)
                THEN c.cantitate_incarcata / 1000
                WHEN c.cantitate_incarcata >= 1000 THEN c.cantitate_incarcata / 1000
                ELSE c.cantitate_incarcata
            END
        ";
        $deliveredTonsExpr = "
            CASE
                WHEN c.tip_transport = 'compresor' THEN COALESCE(c.tona_livrata, 0)
                WHEN c.tona_livrata IS NOT NULL AND c.tona_livrata > 0 THEN c.tona_livrata
                ELSE (" . $loadedTonsExpr . ")
            END
        ";
        $gradIncarcareExpr = "
            CASE
                WHEN c.capacitate_transport IS NOT NULL AND c.capacitate_transport > 0
                THEN LEAST(100, GREATEST(0, ((" . $loadedTonsExpr . ") / c.capacitate_transport) * 100))
                ELSE 0
            END
        ";
        $facturareWithInvoicedExpr = "(COALESCE(c.total_facturare, 0) + COALESCE(exp.total_refacturare_facturata, 0))";
        $refacturarePendingExpr = "COALESCE(exp.total_refacturare_pending, 0)";
        $transportBucketExpr = $this->dashboardTransportBucketSql();

        $fleetSql = "
            SELECT
                COUNT(*) AS total_curse,
                COALESCE(SUM(" . $facturareWithInvoicedExpr . "), 0) AS total_facturare,
                COALESCE(SUM(" . $refacturarePendingExpr . "), 0) AS total_refacturare,
                COALESCE(SUM(COALESCE(exp.total_cheltuieli, 0)), 0) AS total_cheltuieli,
                COALESCE(SUM(" . $kmEffectiveExpr . "), 0) AS total_km,
                COALESCE(SUM(" . $kmPrimarDashboardExpr . "), 0) AS km_primar,
                COALESCE(SUM(" . $kmDistributieDashboardExpr . "), 0) AS km_distributie,
                COALESCE(SUM(" . $kmBilledExpr . "), 0) AS km_facturati,
                COALESCE(SUM(" . $kmSavedExpr . "), 0) AS km_salvati,
                COALESCE(SUM(" . $kmExcessExpr . "), 0) AS km_exces,
                COALESCE(SUM(" . $deliveredTonsExpr . "), 0) AS tone_livrate,
                COALESCE(SUM(
                    CASE
                        WHEN c.tip_transport IN ('primar', 'primar_tona')
                        THEN (" . $deliveredTonsExpr . ")
                        ELSE 0
                    END
                ), 0) AS tone_primar,
                COALESCE(SUM(
                    CASE
                        WHEN c.tip_transport IN ('distributie', 'primar_distributie')
                        THEN (" . $deliveredTonsExpr . ")
                        ELSE 0
                    END
                ), 0) AS tone_distributie,
                COALESCE(SUM(" . $kmNefacturatiExpr . "), 0) AS km_nefacturati,
                COALESCE(AVG(" . $gradIncarcareExpr . "), 0) AS grad_incarcare_mediu
            {$from}
            {$whereData['where']}
        ";
        $fleetStmt = $this->db->prepare($fleetSql);
        $this->bindParams($fleetStmt, $whereData['params']);
        $fleetStmt->execute();
        $fleetRow = $fleetStmt->fetch() ?: [];

        $transportBreakdownSql = "
            SELECT
                " . $transportBucketExpr . " AS transport_bucket,
                COUNT(*) AS total_curse,
                COALESCE(SUM(" . $kmEffectiveExpr . "), 0) AS total_km,
                COALESCE(SUM(" . $deliveredTonsExpr . "), 0) AS total_tone
            {$from}
            {$whereData['where']}
            GROUP BY transport_bucket
        ";
        $transportBreakdownStmt = $this->db->prepare($transportBreakdownSql);
        $this->bindParams($transportBreakdownStmt, $whereData['params']);
        $transportBreakdownStmt->execute();
        $transportBreakdownRows = $transportBreakdownStmt->fetchAll();

        $transportBreakdownMap = $this->emptyDashboardTransportBreakdown();
        foreach ($transportBreakdownRows as $row) {
            $bucket = (string) ($row['transport_bucket'] ?? '');
            if (!array_key_exists($bucket, $transportBreakdownMap)) {
                continue;
            }

            $transportBreakdownMap[$bucket]['curse'] = (int) ($row['total_curse'] ?? 0);
            $transportBreakdownMap[$bucket]['km'] = round(max(0.0, (float) ($row['total_km'] ?? 0)), 2);
            $transportBreakdownMap[$bucket]['tone'] = round(max(0.0, (float) ($row['total_tone'] ?? 0)), 2);
        }

        $transportKmBreakdownSql = "
            SELECT
                COALESCE(SUM(" . $kmDistributieDashboardExpr . "), 0) AS distributie,
                COALESCE(SUM(" . $kmPrimarDashboardExpr . "), 0) AS primar,
                0 AS primar_distributie,
                COALESCE(SUM(
                    CASE
                        WHEN c.tip_transport = 'compresor'
                        THEN (" . $kmEffectiveExpr . ")
                        ELSE 0
                    END
                ), 0) AS compresor
            {$from}
            {$whereData['where']}
        ";
        $transportKmBreakdownStmt = $this->db->prepare($transportKmBreakdownSql);
        $this->bindParams($transportKmBreakdownStmt, $whereData['params']);
        $transportKmBreakdownStmt->execute();
        $transportKmBreakdownRow = $transportKmBreakdownStmt->fetch() ?: [];
        foreach (array_keys($transportBreakdownMap) as $bucketKey) {
            $transportBreakdownMap[$bucketKey]['km'] = round(max(0.0, (float) ($transportKmBreakdownRow[$bucketKey] ?? 0)), 2);
        }
        $transportBreakdown = array_values($transportBreakdownMap);

        $totalCurse = (int) ($fleetRow['total_curse'] ?? 0);
        $totalFacturare = (float) ($fleetRow['total_facturare'] ?? 0);
        $totalRefacturare = (float) ($fleetRow['total_refacturare'] ?? 0);
        $totalIncasare = $totalFacturare + $totalRefacturare;
        $totalCheltuieli = (float) ($fleetRow['total_cheltuieli'] ?? 0);
        $profitTotal = $totalFacturare - $totalCheltuieli;
        $totalKm = max(0.0, (float) ($fleetRow['total_km'] ?? 0));
        $totalToneTransportate = max(0.0, (float) ($fleetRow['tone_livrate'] ?? 0));
        $totalKmBilled = max(0.0, (float) ($fleetRow['km_facturati'] ?? 0));
        $kmNefacturatiTotal = max(0.0, (float) ($fleetRow['km_nefacturati'] ?? 0));
        $kmFacturatiTotal = $totalKmBilled > 0 ? $totalKmBilled : max(0.0, $totalKm - $kmNefacturatiTotal);
        $profitPerKm = $kmFacturatiTotal > 0 ? $profitTotal / $kmFacturatiTotal : 0.0;
        $venitPerTo = $totalToneTransportate > 0 ? $profitTotal / $totalToneTransportate : 0.0;
        $kmPerTo = $totalToneTransportate > 0 ? $totalKm / $totalToneTransportate : 0.0;
        $toPerKm = $totalKm > 0 ? $totalToneTransportate / $totalKm : 0.0;

        $profitEvolutionSql = "
            SELECT
                c.data_inceput AS data_zi,
                COALESCE(SUM(" . $facturareWithInvoicedExpr . "), 0) AS facturare,
                COALESCE(SUM(" . $refacturarePendingExpr . "), 0) AS refacturare,
                COALESCE(SUM(COALESCE(exp.total_cheltuieli, 0)), 0) AS cheltuieli
            {$from}
            {$whereData['where']}
            GROUP BY c.data_inceput
            ORDER BY c.data_inceput ASC
        ";
        $profitEvolutionStmt = $this->db->prepare($profitEvolutionSql);
        $this->bindParams($profitEvolutionStmt, $whereData['params']);
        $profitEvolutionStmt->execute();
        $profitEvolutionRows = $profitEvolutionStmt->fetchAll();

        $profitEvolutionLabels = [];
        $profitEvolutionFacturare = [];
        $profitEvolutionRefacturare = [];
        $profitEvolutionCheltuieli = [];
        $profitEvolutionProfit = [];
        foreach ($profitEvolutionRows as $row) {
            $labelDate = (string) ($row['data_zi'] ?? '');
            $facturareValue = (float) ($row['facturare'] ?? 0);
            $refacturareValue = (float) ($row['refacturare'] ?? 0);
            $cheltuieliValue = (float) ($row['cheltuieli'] ?? 0);
            $profitValue = $facturareValue - $cheltuieliValue;

            $profitEvolutionLabels[] = $labelDate;
            $profitEvolutionFacturare[] = round($facturareValue, 2);
            $profitEvolutionRefacturare[] = round($refacturareValue, 2);
            $profitEvolutionCheltuieli[] = round($cheltuieliValue, 2);
            $profitEvolutionProfit[] = round($profitValue, 2);
        }

        $transportDistributionSql = "
            SELECT
                c.tip_transport,
                COUNT(*) AS total_curse
            {$from}
            {$whereData['where']}
            GROUP BY c.tip_transport
            ORDER BY total_curse DESC, c.tip_transport ASC
        ";
        $transportDistributionStmt = $this->db->prepare($transportDistributionSql);
        $this->bindParams($transportDistributionStmt, $whereData['params']);
        $transportDistributionStmt->execute();
        $transportDistributionRows = $transportDistributionStmt->fetchAll();

        $transportDistributionLabels = [];
        $transportDistributionValues = [];
        foreach ($transportDistributionRows as $row) {
            $transportDistributionLabels[] = (string) ($row['tip_transport'] ?? '-');
            $transportDistributionValues[] = (int) ($row['total_curse'] ?? 0);
        }

        $vehicleSql = "
            SELECT
                c.vehicle_id,
                COALESCE(NULLIF(TRIM(v.nr_inmatriculare), ''), 'Necunoscut') AS nr_inmatriculare,
                COUNT(*) AS curse,
                COALESCE(SUM(" . $kmEffectiveExpr . "), 0) AS km_totali,
                COALESCE(SUM(" . $kmBilledExpr . "), 0) AS km_facturati,
                COALESCE(SUM(" . $deliveredTonsExpr . "), 0) AS tone_livrate,
                COALESCE(SUM(" . $facturareWithInvoicedExpr . "), 0) AS facturare,
                COALESCE(SUM(" . $refacturarePendingExpr . "), 0) AS refacturare,
                COALESCE(SUM(COALESCE(exp.total_cheltuieli, 0)), 0) AS cheltuieli,
                COALESCE(SUM(" . $kmNefacturatiExpr . "), 0) AS km_nefacturati,
                COALESCE(AVG(" . $gradIncarcareExpr . "), 0) AS grad_incarcare_mediu
            {$from}
            {$whereData['where']}
            GROUP BY c.vehicle_id, v.nr_inmatriculare
            ORDER BY v.nr_inmatriculare ASC
        ";
        $vehicleStmt = $this->db->prepare($vehicleSql);
        $this->bindParams($vehicleStmt, $whereData['params']);
        $vehicleStmt->execute();
        $vehicleRows = $vehicleStmt->fetchAll();

        $vehicles = [];
        $topVehicleProfit = [];
        $topVehicleProfitPerKm = [];
        $alerts = [];

        foreach ($vehicleRows as $row) {
            $km = max(0.0, (float) ($row['km_totali'] ?? 0));
            $kmBilled = max(0.0, (float) ($row['km_facturati'] ?? 0));
            $facturare = (float) ($row['facturare'] ?? 0);
            $refacturare = (float) ($row['refacturare'] ?? 0);
            $cheltuieli = (float) ($row['cheltuieli'] ?? 0);
            $profit = $facturare - $cheltuieli;
            $kmRatioBase = $kmBilled > 0 ? $kmBilled : $km;
            $venitKm = $kmRatioBase > 0 ? $facturare / $kmRatioBase : 0.0;
            $costKm = $kmRatioBase > 0 ? $cheltuieli / $kmRatioBase : 0.0;
            $profitKm = $kmRatioBase > 0 ? $profit / $kmRatioBase : 0.0;
            $kmNefPercent = $km > 0 ? (((float) ($row['km_nefacturati'] ?? 0)) / $km) * 100 : 0.0;
            $gradMediu = (float) ($row['grad_incarcare_mediu'] ?? 0);
            $plate = (string) ($row['nr_inmatriculare'] ?? 'Necunoscut');

            $vehicleItem = [
                'nr_inmatriculare' => $plate,
                'curse' => (int) ($row['curse'] ?? 0),
                'km_totali' => round($km, 2),
                'tone_livrate' => round((float) ($row['tone_livrate'] ?? 0), 2),
                'facturare' => round($facturare, 2),
                'refacturare' => round($refacturare, 2),
                'cheltuieli' => round($cheltuieli, 2),
                'profit' => round($profit, 2),
                'venit_km' => round($venitKm, 4),
                'cost_km' => round($costKm, 4),
                'profit_km' => round($profitKm, 4),
                'km_nefacturati_percent' => round($kmNefPercent, 2),
                'grad_incarcare_mediu' => round($gradMediu, 2),
            ];
            $vehicles[] = $vehicleItem;

            $topVehicleProfit[] = [
                'label' => $plate,
                'value' => (float) $vehicleItem['profit'],
            ];
            $topVehicleProfitPerKm[] = [
                'label' => $plate,
                'value' => (float) $vehicleItem['profit_km'],
            ];

            if ($profit < 0) {
                $alerts[] = [
                    'severity' => 'danger',
                    'type' => 'vehicle',
                    'target' => $plate,
                    'message' => 'Profit negativ',
                    'value' => round($profit, 2),
                ];
            }

            if ($profitKm <= 0) {
                $alerts[] = [
                    'severity' => 'warning',
                    'type' => 'vehicle',
                    'target' => $plate,
                    'message' => 'Profit/km este sub sau egal cu 0',
                    'value' => round($profitKm, 4),
                ];
            }

            if ($kmNefPercent > 20) {
                $alerts[] = [
                    'severity' => 'warning',
                    'type' => 'vehicle',
                    'target' => $plate,
                    'message' => 'Km nefacturati peste pragul de 20%',
                    'value' => round($kmNefPercent, 2),
                ];
            }

            if ($gradMediu < 50) {
                $alerts[] = [
                    'severity' => 'warning',
                    'type' => 'vehicle',
                    'target' => $plate,
                    'message' => 'Grad incarcare mediu sub 50%',
                    'value' => round($gradMediu, 2),
                ];
            }
        }

        usort($topVehicleProfit, static function (array $a, array $b): int {
            return $b['value'] <=> $a['value'];
        });
        usort($topVehicleProfitPerKm, static function (array $a, array $b): int {
            return $b['value'] <=> $a['value'];
        });

        $topVehicleProfit = array_slice($topVehicleProfit, 0, 12);
        $topVehicleProfitPerKm = array_slice($topVehicleProfitPerKm, 0, 12);

        $driverSql = "
            SELECT
                c.driver_id,
                COALESCE(NULLIF(TRIM(s.nume), ''), 'Fara sofer') AS sofer_nume,
                COUNT(*) AS curse,
                COALESCE(SUM(" . $kmEffectiveExpr . "), 0) AS km_totali,
                COALESCE(SUM(" . $deliveredTonsExpr . "), 0) AS tone_livrate,
                COALESCE(SUM(" . $facturareWithInvoicedExpr . "), 0) AS facturare,
                COALESCE(SUM(" . $refacturarePendingExpr . "), 0) AS refacturare,
                COALESCE(SUM(COALESCE(exp.total_cheltuieli, 0)), 0) AS cheltuieli,
                COALESCE(AVG(" . $gradIncarcareExpr . "), 0) AS grad_incarcare_mediu
            {$from}
            {$whereData['where']}
            GROUP BY c.driver_id, COALESCE(NULLIF(TRIM(s.nume), ''), 'Fara sofer')
            ORDER BY sofer_nume ASC
        ";
        $driverStmt = $this->db->prepare($driverSql);
        $this->bindParams($driverStmt, $whereData['params']);
        $driverStmt->execute();
        $driverRows = $driverStmt->fetchAll();

        $drivers = [];
        $ridesPerDriver = [];
        $tonsPerDriver = [];
        $driverActivityPoints = [];

        foreach ($driverRows as $row) {
            $driverName = (string) ($row['sofer_nume'] ?? 'Fara sofer');
            $curse = (int) ($row['curse'] ?? 0);
            $km = max(0.0, (float) ($row['km_totali'] ?? 0));
            $tones = max(0.0, (float) ($row['tone_livrate'] ?? 0));
            $facturare = (float) ($row['facturare'] ?? 0);
            $refacturare = (float) ($row['refacturare'] ?? 0);
            $cheltuieli = (float) ($row['cheltuieli'] ?? 0);
            $profit = $facturare - $cheltuieli;
            $tonePerCursa = $curse > 0 ? $tones / $curse : 0.0;
            $kmPerCursa = $curse > 0 ? $km / $curse : 0.0;
            $gradMediu = (float) ($row['grad_incarcare_mediu'] ?? 0);

            $driverItem = [
                'sofer' => $driverName,
                'curse' => $curse,
                'km_totali' => round($km, 2),
                'tone_livrate' => round($tones, 2),
                'facturare_generata' => round($facturare, 2),
                'refacturare_generata' => round($refacturare, 2),
                'profit_generat' => round($profit, 2),
                'tone_per_cursa' => round($tonePerCursa, 2),
                'km_per_cursa' => round($kmPerCursa, 2),
                'grad_incarcare_mediu' => round($gradMediu, 2),
            ];
            $drivers[] = $driverItem;

            $ridesPerDriver[] = [
                'label' => $driverName,
                'value' => $curse,
            ];
            $tonsPerDriver[] = [
                'label' => $driverName,
                'value' => (float) $driverItem['tone_livrate'],
            ];
            $driverActivityPoints[] = [
                'label' => $driverName,
                'x' => $curse,
                'y' => (float) $driverItem['tone_livrate'],
                'r' => (float) max(5, min(26, 6 + (abs($profit) / 4000))),
                'billing' => (float) $driverItem['facturare_generata'],
                'profit' => (float) $driverItem['profit_generat'],
            ];
        }

        usort($ridesPerDriver, static function (array $a, array $b): int {
            return $b['value'] <=> $a['value'];
        });
        usort($tonsPerDriver, static function (array $a, array $b): int {
            return $b['value'] <=> $a['value'];
        });

        $ridesPerDriver = array_slice($ridesPerDriver, 0, 12);
        $tonsPerDriver = array_slice($tonsPerDriver, 0, 12);

        return [
            'fleet' => [
                'total_curse' => $totalCurse,
                'total_facturare' => round($totalFacturare, 2),
                'total_refacturare' => round($totalRefacturare, 2),
                'total_incasare' => round($totalIncasare, 2),
                'total_cheltuieli' => round($totalCheltuieli, 2),
                'profit_total' => round($profitTotal, 2),
                'total_km' => round($totalKm, 2),
                'km_primar' => round((float) ($fleetRow['km_primar'] ?? 0), 2),
                'km_distributie' => round((float) ($fleetRow['km_distributie'] ?? 0), 2),
                'km_salvati' => round((float) ($fleetRow['km_salvati'] ?? 0), 2),
                'km_exces' => round((float) ($fleetRow['km_exces'] ?? 0), 2),
                'tone_livrate' => round($totalToneTransportate, 2),
                'tone_primar' => round((float) ($fleetRow['tone_primar'] ?? 0), 2),
                'tone_distributie' => round((float) ($fleetRow['tone_distributie'] ?? 0), 2),
                'profit_km' => round($profitPerKm, 4),
                'venit_tona' => round($venitPerTo, 4),
                'km_tona' => round($kmPerTo, 4),
                'tona_km' => round($toPerKm, 4),
                'grad_incarcare_mediu' => round((float) ($fleetRow['grad_incarcare_mediu'] ?? 0), 2),
                'km_nefacturati' => round($kmNefacturatiTotal, 2),
                'km_facturati' => round($kmFacturatiTotal, 2),
                'grad_utilizare_flota_percent' => round((float) ($fleetUtilizare['grad_utilizare_flota_percent'] ?? 0), 2),
                'total_zile_active' => (int) ($fleetUtilizare['total_zile_active'] ?? 0),
                'total_zile_disponibile' => (int) ($fleetUtilizare['total_zile_disponibile'] ?? 0),
                'numar_vehicule_active' => (int) ($fleetUtilizare['numar_vehicule_active'] ?? 0),
                'luna_selectata' => (int) ($fleetUtilizare['luna_selectata'] ?? 0),
                'an_selectat' => (int) ($fleetUtilizare['an_selectat'] ?? 0),
                'transport_breakdown' => $transportBreakdown,
            ],
            'vehicles' => $vehicles,
            'drivers' => $drivers,
            'charts' => [
                'profit_evolution' => [
                    'labels' => $profitEvolutionLabels,
                    'facturare' => $profitEvolutionFacturare,
                    'refacturare' => $profitEvolutionRefacturare,
                    'cheltuieli' => $profitEvolutionCheltuieli,
                    'profit' => $profitEvolutionProfit,
                ],
                'km_billed_vs_unbilled' => [
                    'labels' => ['Km'],
                    'km_facturati' => [round($kmFacturatiTotal, 2)],
                    'km_nefacturati' => [round($kmNefacturatiTotal, 2)],
                ],
                'transport_distribution' => [
                    'labels' => $transportDistributionLabels,
                    'values' => $transportDistributionValues,
                ],
                'top_vehicle_profit' => [
                    'labels' => array_column($topVehicleProfit, 'label'),
                    'values' => array_column($topVehicleProfit, 'value'),
                ],
                'vehicle_profit_per_km' => [
                    'labels' => array_column($topVehicleProfitPerKm, 'label'),
                    'values' => array_column($topVehicleProfitPerKm, 'value'),
                ],
                'rides_per_driver' => [
                    'labels' => array_column($ridesPerDriver, 'label'),
                    'values' => array_column($ridesPerDriver, 'value'),
                ],
                'tons_per_driver' => [
                    'labels' => array_column($tonsPerDriver, 'label'),
                    'values' => array_column($tonsPerDriver, 'value'),
                ],
                'driver_activity_matrix' => [
                    'points' => $driverActivityPoints,
                ],
            ],
            'alerts' => $alerts,
        ];
    }

    private function calculateFleetUtilizationKpi(array $filters): array
    {
        $selectedMonth = $this->resolveDashboardSelectedMonth($filters);
        $monthStart = $selectedMonth['start'];
        $monthEnd = $selectedMonth['end'];

        $whereData = $this->buildDashboardUtilizationWhere(
            $filters,
            $monthStart->format('Y-m-d'),
            $monthEnd->format('Y-m-d')
        );

        $sql = "
            SELECT
                c.vehicle_id,
                COALESCE(c.data_inceput, c.data_cursa) AS interval_start,
                COALESCE(c.data_sfarsit, c.data_inceput, c.data_cursa) AS interval_end
            FROM curse_dispecer c
            {$whereData['where']}
            ORDER BY c.vehicle_id ASC, interval_start ASC
        ";

        $stmt = $this->db->prepare($sql);
        $this->bindParams($stmt, $whereData['params']);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $distinctVehicleDays = [];
        $activeVehicles = [];

        foreach ($rows as $row) {
            $vehicleId = (int) ($row['vehicle_id'] ?? 0);
            if ($vehicleId <= 0) {
                continue;
            }

            $intervalStartRaw = trim((string) ($row['interval_start'] ?? ''));
            $intervalEndRaw = trim((string) ($row['interval_end'] ?? ''));
            if ($intervalStartRaw === '' || $intervalEndRaw === '') {
                continue;
            }

            $intervalStart = DateTimeImmutable::createFromFormat('Y-m-d', $intervalStartRaw);
            $intervalEnd = DateTimeImmutable::createFromFormat('Y-m-d', $intervalEndRaw);
            if (!$intervalStart instanceof DateTimeImmutable || !$intervalEnd instanceof DateTimeImmutable) {
                continue;
            }

            if ($intervalStart->format('Y-m-d') !== $intervalStartRaw || $intervalEnd->format('Y-m-d') !== $intervalEndRaw) {
                continue;
            }

            if ($intervalEnd < $intervalStart) {
                [$intervalStart, $intervalEnd] = [$intervalEnd, $intervalStart];
            }

            if ($intervalStart < $monthStart) {
                $intervalStart = $monthStart;
            }

            if ($intervalEnd > $monthEnd) {
                $intervalEnd = $monthEnd;
            }

            if ($intervalEnd < $intervalStart) {
                continue;
            }

            $activeVehicles[$vehicleId] = $vehicleId;

            for ($cursor = $intervalStart; $cursor <= $intervalEnd; $cursor = $cursor->modify('+1 day')) {
                $distinctVehicleDays[$vehicleId . '|' . $cursor->format('Y-m-d')] = true;
            }
        }

        $totalZileActive = count($distinctVehicleDays);
        $numarVehiculeActive = count($activeVehicles);
        $zileLucratoare = $this->countWeekdaysInRange($monthStart, $monthEnd);
        $totalZileDisponibile = $numarVehiculeActive * $zileLucratoare;
        $gradUtilizare = $totalZileDisponibile > 0
            ? ($totalZileActive / $totalZileDisponibile) * 100
            : 0.0;

        return [
            'grad_utilizare_flota_percent' => round($gradUtilizare, 2),
            'total_zile_active' => $totalZileActive,
            'total_zile_disponibile' => $totalZileDisponibile,
            'numar_vehicule_active' => $numarVehiculeActive,
            'luna_selectata' => (int) $selectedMonth['month'],
            'an_selectat' => (int) $selectedMonth['year'],
        ];
    }

    private function buildCourseExpenseHistoryMainSql(array $filters, string $prefix): array
    {
        $aggregate = $this->buildCourseExpenseHistoryAggregateSql($filters, $prefix . '_agg');
        $raceWhere = $this->buildCourseExpenseHistoryRaceWhere($filters, $prefix);

        return [
            'from' => "
                FROM curse_dispecer c
                INNER JOIN vehicule v ON v.id = c.vehicle_id
                LEFT JOIN soferi s ON s.id = c.driver_id
                INNER JOIN (" . $aggregate['sql'] . ") exp ON exp.cursa_id = c.id
            ",
            'where' => $raceWhere['where'],
            'params' => array_merge($aggregate['params'], $raceWhere['params']),
        ];
    }

    private function buildCourseExpenseHistoryAggregateSql(array $filters, string $prefix): array
    {
        $expenseWhere = $this->buildCourseExpenseHistoryExpenseWhere($filters, $prefix, false, false);
        $categoryLabelExpr = $this->courseExpenseCategoryLabelSql('cc', 'e');

        return [
            'sql' => "
                SELECT
                    e.cursa_id,
                    COUNT(*) AS expense_count,
                    COALESCE(SUM(COALESCE(e.suma, 0)), 0) AS total_cheltuieli,
                    GROUP_CONCAT(DISTINCT CONCAT_WS(' ', " . $categoryLabelExpr . ", e.observatii, d.original_name) SEPARATOR ' ') AS expense_search
                FROM curse_cheltuieli e
                LEFT JOIN categorii_cheltuieli_curse cc ON cc.id = e.categorie_id
                " . $this->courseExpenseLatestDocumentJoinSql('d') . "
                " . $expenseWhere['where'] . "
                GROUP BY e.cursa_id
            ",
            'params' => $expenseWhere['params'],
        ];
    }

    private function buildCourseExpenseHistoryRaceWhere(array $filters, string $prefix): array
    {
        $this->ensureRaceSoftDeleteSchema();

        $where = [
            'c.deleted_at IS NULL',
        ];
        $params = [];

        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        if ($dateFrom !== '') {
            $where[] = 'COALESCE(c.data_inceput, c.data_cursa) >= :' . $prefix . '_date_from';
            $params[':' . $prefix . '_date_from'] = $dateFrom;
        }

        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        if ($dateTo !== '') {
            $where[] = 'COALESCE(c.data_inceput, c.data_cursa) <= :' . $prefix . '_date_to';
            $params[':' . $prefix . '_date_to'] = $dateTo;
        }

        $vehicleId = (int) ($filters['vehicle_id'] ?? 0);
        if ($vehicleId > 0) {
            $where[] = 'c.vehicle_id = :' . $prefix . '_vehicle_id';
            $params[':' . $prefix . '_vehicle_id'] = $vehicleId;
        }

        $driverId = (int) ($filters['driver_id'] ?? 0);
        if ($driverId > 0) {
            $where[] = 'c.driver_id = :' . $prefix . '_driver_id';
            $params[':' . $prefix . '_driver_id'] = $driverId;
        }

        $raceId = (int) ($filters['race_id'] ?? 0);
        if ($raceId > 0) {
            $where[] = 'c.id = :' . $prefix . '_race_id';
            $params[':' . $prefix . '_race_id'] = $raceId;
        }

        $keyword = trim((string) ($filters['q'] ?? ''));
        if ($keyword !== '') {
            $keywordExpressions = [
                'CAST(c.id AS CHAR)',
                "CONCAT('CURS-', YEAR(COALESCE(c.data_inceput, c.data_cursa)), '-', LPAD(c.id, 4, '0'))",
                "COALESCE(v.nr_inmatriculare, '')",
                "COALESCE(v.marca, '')",
                "COALESCE(v.model, '')",
                "COALESCE(s.nume, '')",
                "COALESCE(c.observatii, '')",
                "COALESCE(exp.expense_search, '')",
            ];
            $keywordClauses = [];
            $keywordValue = '%' . $keyword . '%';
            foreach ($keywordExpressions as $index => $expression) {
                $paramName = ':' . $prefix . '_keyword_' . $index;
                $keywordClauses[] = $expression . ' LIKE ' . $paramName;
                $params[$paramName] = $keywordValue;
            }
            $where[] = '(' . implode(' OR ', $keywordClauses) . ')';
        }

        return [
            'where' => $where === [] ? '' : ' WHERE ' . implode(' AND ', $where),
            'params' => $params,
        ];
    }

    private function buildCourseExpenseHistoryExpenseWhere(
        array $filters,
        string $prefix,
        bool $includeRaceFilters,
        bool $includeKeyword
    ): array {
        $where = [
            "e.tip_cheltuiala <> 'motorina'",
            'COALESCE(e.suma, 0) > 0',
        ];
        $params = [];

        if ($includeRaceFilters || $includeKeyword) {
            $this->ensureRaceSoftDeleteSchema();
            $where[] = 'c.deleted_at IS NULL';
        }

        $categoryId = (int) ($filters['category_id'] ?? 0);
        if ($categoryId > 0) {
            $where[] = 'e.categorie_id = :' . $prefix . '_category_id';
            $params[':' . $prefix . '_category_id'] = $categoryId;
        }

        $documentState = trim((string) ($filters['document_state'] ?? ''));
        if ($documentState === 'cu') {
            $where[] = "EXISTS (
                SELECT 1
                FROM curse_cheltuieli_documente edoc
                WHERE edoc.cheltuiala_id = e.id
            )";
        } elseif ($documentState === 'fara') {
            $where[] = "NOT EXISTS (
                SELECT 1
                FROM curse_cheltuieli_documente edoc
                WHERE edoc.cheltuiala_id = e.id
            )";
        }

        if ($includeRaceFilters) {
            $dateFrom = trim((string) ($filters['date_from'] ?? ''));
            if ($dateFrom !== '') {
                $where[] = 'COALESCE(c.data_inceput, c.data_cursa) >= :' . $prefix . '_date_from';
                $params[':' . $prefix . '_date_from'] = $dateFrom;
            }

            $dateTo = trim((string) ($filters['date_to'] ?? ''));
            if ($dateTo !== '') {
                $where[] = 'COALESCE(c.data_inceput, c.data_cursa) <= :' . $prefix . '_date_to';
                $params[':' . $prefix . '_date_to'] = $dateTo;
            }

            $vehicleId = (int) ($filters['vehicle_id'] ?? 0);
            if ($vehicleId > 0) {
                $where[] = 'c.vehicle_id = :' . $prefix . '_vehicle_id';
                $params[':' . $prefix . '_vehicle_id'] = $vehicleId;
            }

            $driverId = (int) ($filters['driver_id'] ?? 0);
            if ($driverId > 0) {
                $where[] = 'c.driver_id = :' . $prefix . '_driver_id';
                $params[':' . $prefix . '_driver_id'] = $driverId;
            }

            $raceId = (int) ($filters['race_id'] ?? 0);
            if ($raceId > 0) {
                $where[] = 'c.id = :' . $prefix . '_race_id';
                $params[':' . $prefix . '_race_id'] = $raceId;
            }
        }

        if ($includeKeyword) {
            $keyword = trim((string) ($filters['q'] ?? ''));
            if ($keyword !== '') {
                $categoryLabelExpr = $this->courseExpenseCategoryLabelSql('cc', 'e');
                $keywordExpressions = [
                    'CAST(c.id AS CHAR)',
                    "CONCAT('CURS-', YEAR(COALESCE(c.data_inceput, c.data_cursa)), '-', LPAD(c.id, 4, '0'))",
                    "COALESCE(v.nr_inmatriculare, '')",
                    "COALESCE(v.marca, '')",
                    "COALESCE(v.model, '')",
                    "COALESCE(s.nume, '')",
                    $categoryLabelExpr,
                    "COALESCE(e.observatii, '')",
                    "COALESCE(d.original_name, '')",
                ];
                $keywordClauses = [];
                $keywordValue = '%' . $keyword . '%';
                foreach ($keywordExpressions as $index => $expression) {
                    $paramName = ':' . $prefix . '_keyword_' . $index;
                    $keywordClauses[] = $expression . ' LIKE ' . $paramName;
                    $params[$paramName] = $keywordValue;
                }
                $where[] = '(' . implode(' OR ', $keywordClauses) . ')';
            }
        }

        return [
            'where' => ' WHERE ' . implode(' AND ', $where),
            'params' => $params,
        ];
    }

    private function getCourseExpenseTopCategoriesForRaceIds(array $raceIds, array $filters): array
    {
        $raceIds = array_values(array_unique(array_filter(array_map('intval', $raceIds), static fn (int $id): bool => $id > 0)));
        if ($raceIds === []) {
            return [];
        }

        $expenseWhere = $this->buildCourseExpenseHistoryExpenseWhere($filters, 'topcat', false, false);
        $whereSql = $expenseWhere['where'];
        $params = $expenseWhere['params'];
        $racePlaceholders = [];
        foreach ($raceIds as $index => $raceId) {
            $placeholder = ':topcat_race_' . $index;
            $racePlaceholders[] = $placeholder;
            $params[$placeholder] = $raceId;
        }
        $whereSql .= ' AND e.cursa_id IN (' . implode(', ', $racePlaceholders) . ')';

        $categoryLabelExpr = $this->courseExpenseCategoryLabelSql('cc', 'e');
        $stmt = $this->db->prepare("
            SELECT
                e.cursa_id,
                " . $categoryLabelExpr . " AS categorie_nume,
                COALESCE(SUM(e.suma), 0) AS total_suma
            FROM curse_cheltuieli e
            LEFT JOIN categorii_cheltuieli_curse cc ON cc.id = e.categorie_id
            " . $whereSql . "
            GROUP BY e.cursa_id, categorie_nume
            ORDER BY e.cursa_id ASC, total_suma DESC, categorie_nume ASC
        ");
        $this->bindParams($stmt, $params);
        $stmt->execute();

        $top = [];
        foreach ($stmt->fetchAll() as $row) {
            $raceId = (int) ($row['cursa_id'] ?? 0);
            if ($raceId <= 0 || isset($top[$raceId])) {
                continue;
            }

            $top[$raceId] = [
                'nume' => (string) ($row['categorie_nume'] ?? '-'),
                'suma' => round((float) ($row['total_suma'] ?? 0), 2),
            ];
        }

        return $top;
    }

    private function getCourseExpenseDetailsForRaceIds(array $raceIds): array
    {
        $raceIds = array_values(array_unique(array_filter(array_map('intval', $raceIds), static fn (int $id): bool => $id > 0)));
        if ($raceIds === []) {
            return [];
        }

        $params = [];
        $placeholders = [];
        foreach ($raceIds as $index => $raceId) {
            $placeholder = ':detail_race_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $raceId;
        }

        $categoryLabelExpr = $this->courseExpenseCategoryLabelSql('cc', 'e');
        $stmt = $this->db->prepare("
            SELECT
                e.id,
                e.cursa_id,
                e.suma,
                e.data_cheltuiala,
                e.observatii,
                e.created_at,
                " . $categoryLabelExpr . " AS categorie_nume,
                d.file_path,
                d.original_name,
                d.mime_type,
                d.file_size,
                COALESCE(u.nume, '') AS added_by_nume
            FROM curse_cheltuieli e
            LEFT JOIN categorii_cheltuieli_curse cc ON cc.id = e.categorie_id
            " . $this->courseExpenseLatestDocumentJoinSql('d') . "
            LEFT JOIN utilizatori u ON u.id = e.added_by
            WHERE e.cursa_id IN (" . implode(', ', $placeholders) . ")
              AND e.tip_cheltuiala <> 'motorina'
              AND COALESCE(e.suma, 0) > 0
            ORDER BY e.cursa_id ASC, e.data_cheltuiala DESC, e.id DESC
        ");
        $this->bindParams($stmt, $params);
        $stmt->execute();

        $grouped = [];
        foreach ($stmt->fetchAll() as $row) {
            $raceId = (int) ($row['cursa_id'] ?? 0);
            if ($raceId <= 0) {
                continue;
            }

            if (!isset($grouped[$raceId])) {
                $grouped[$raceId] = [];
            }

            $grouped[$raceId][] = $row;
        }

        return $grouped;
    }

    private function getCourseExpenseHistoryCharts(array $filters): array
    {
        $categoryWhere = $this->buildCourseExpenseHistoryExpenseWhere($filters, 'chartcat', true, true);
        $categoryLabelExpr = $this->courseExpenseCategoryLabelSql('cc', 'e');
        $categoryStmt = $this->db->prepare("
            SELECT
                " . $categoryLabelExpr . " AS label,
                COALESCE(SUM(e.suma), 0) AS total
            FROM curse_cheltuieli e
            INNER JOIN curse_dispecer c ON c.id = e.cursa_id
            INNER JOIN vehicule v ON v.id = c.vehicle_id
            LEFT JOIN soferi s ON s.id = c.driver_id
            LEFT JOIN categorii_cheltuieli_curse cc ON cc.id = e.categorie_id
            " . $this->courseExpenseLatestDocumentJoinSql('d') . "
            " . $categoryWhere['where'] . "
            GROUP BY label
            ORDER BY total DESC, label ASC
        ");
        $this->bindParams($categoryStmt, $categoryWhere['params']);
        $categoryStmt->execute();
        $categoryRows = $categoryStmt->fetchAll();

        $mainSql = $this->buildCourseExpenseHistoryMainSql($filters, 'chartmain');

        $topStmt = $this->db->prepare("
            SELECT
                c.id,
                c.data_inceput AS data_cursa,
                COALESCE(c.total_facturare, 0) - COALESCE(exp.total_cheltuieli, 0) AS profit
            " . $mainSql['from'] . $mainSql['where'] . "
            ORDER BY profit DESC, c.data_inceput DESC, c.id DESC
            LIMIT 5
        ");
        $this->bindParams($topStmt, $mainSql['params']);
        $topStmt->execute();
        $topRows = $topStmt->fetchAll();

        $dailyStmt = $this->db->prepare("
            SELECT
                COALESCE(c.data_inceput, c.data_cursa) AS data_zi,
                COALESCE(SUM(COALESCE(c.total_facturare, 0)), 0) AS venit,
                COALESCE(SUM(COALESCE(exp.total_cheltuieli, 0)), 0) AS cheltuieli
            " . $mainSql['from'] . $mainSql['where'] . "
            GROUP BY COALESCE(c.data_inceput, c.data_cursa)
            ORDER BY data_zi ASC
        ");
        $this->bindParams($dailyStmt, $mainSql['params']);
        $dailyStmt->execute();
        $dailyRows = $dailyStmt->fetchAll();

        $categoryLabels = [];
        $categoryValues = [];
        $categoryTotal = 0.0;
        foreach ($categoryRows as $row) {
            $categoryLabels[] = (string) ($row['label'] ?? '-');
            $value = round((float) ($row['total'] ?? 0), 2);
            $categoryValues[] = $value;
            $categoryTotal += $value;
        }

        $categoryPercentages = [];
        foreach ($categoryValues as $value) {
            $categoryPercentages[] = $categoryTotal > 0 ? round(($value / $categoryTotal) * 100, 2) : 0.0;
        }

        $topLabels = [];
        $topValues = [];
        foreach ($topRows as $row) {
            $topLabels[] = $this->formatRaceCode((int) ($row['id'] ?? 0), (string) ($row['data_cursa'] ?? ''));
            $topValues[] = round((float) ($row['profit'] ?? 0), 2);
        }

        $dailyLabels = [];
        $dailyProfit = [];
        foreach ($dailyRows as $row) {
            $date = trim((string) ($row['data_zi'] ?? ''));
            $dailyLabels[] = $date !== '' ? date('d/m', strtotime($date)) : '-';
            $dailyProfit[] = round((float) ($row['venit'] ?? 0) - (float) ($row['cheltuieli'] ?? 0), 2);
        }

        return [
            'categories' => [
                'labels' => $categoryLabels,
                'values' => $categoryValues,
                'percentages' => $categoryPercentages,
                'total' => round($categoryTotal, 2),
            ],
            'top_profit' => [
                'labels' => $topLabels,
                'values' => $topValues,
            ],
            'daily_profit' => [
                'labels' => $dailyLabels,
                'values' => $dailyProfit,
            ],
        ];
    }

    private function courseExpenseCategoryLabelSql(string $categoryAlias, string $expenseAlias): string
    {
        return "COALESCE(
            NULLIF(TRIM(" . $categoryAlias . ".nume), ''),
            CASE " . $expenseAlias . ".tip_cheltuiala
                WHEN 'taxe_drum' THEN 'Taxe drum'
                WHEN 'diurna' THEN 'Diurna'
                WHEN 'service' THEN 'Reparatii'
                WHEN 'alte' THEN 'Alte cheltuieli'
                ELSE " . $expenseAlias . ".tip_cheltuiala
            END
        )";
    }

    private function courseExpenseLatestDocumentJoinSql(string $alias): string
    {
        return "
            LEFT JOIN (
                SELECT d1.*
                FROM curse_cheltuieli_documente d1
                INNER JOIN (
                    SELECT cheltuiala_id, MAX(id) AS max_id
                    FROM curse_cheltuieli_documente
                    GROUP BY cheltuiala_id
                ) latest_doc ON latest_doc.max_id = d1.id
            ) " . $alias . " ON " . $alias . ".cheltuiala_id = e.id
        ";
    }

    public function formatRaceCode(int $raceId, string $raceDate = ''): string
    {
        $year = date('Y');
        $date = trim($raceDate);
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $date)) {
            $year = substr($date, 0, 4);
        }

        return 'CURS-' . $year . '-' . str_pad((string) $raceId, 4, '0', STR_PAD_LEFT);
    }

    private function buildDashboardUtilizationWhere(array $filters, string $monthStart, string $monthEnd): array
    {
        $where = [
            'c.deleted_at IS NULL',
            'c.vehicle_id IS NOT NULL',
            'COALESCE(c.data_inceput, c.data_cursa) <= :util_month_end',
            'COALESCE(c.data_sfarsit, c.data_inceput, c.data_cursa) >= :util_month_start',
        ];
        $params = [
            ':util_month_start' => $monthStart,
            ':util_month_end' => $monthEnd,
        ];

        $this->appendDashboardIntFilter($where, $params, 'c.vehicle_id', (array) ($filters['vehicle_ids'] ?? []), 'util_vehicle_id');
        $this->appendDashboardIntFilter($where, $params, 'c.driver_id', (array) ($filters['driver_ids'] ?? []), 'util_driver_id');
        $this->appendDashboardIntFilter($where, $params, 'c.beneficiar_id', (array) ($filters['beneficiary_ids'] ?? []), 'util_beneficiary_id');
        $this->appendDashboardStringFilter($where, $params, 'c.tip_transport', (array) ($filters['transport_types'] ?? []), 'util_transport_type');
        $this->appendDashboardDecimalFilter($where, $params, 'c.capacitate_transport', (array) ($filters['transport_capacities'] ?? []), 'util_transport_capacity');
        $this->appendDashboardStringFilter($where, $params, 'c.status_facturare', (array) ($filters['statuses'] ?? []), 'util_status');

        return [
            'where' => ' WHERE ' . implode(' AND ', $where),
            'params' => $params,
        ];
    }

    private function resolveDashboardSelectedMonth(array $filters): array
    {
        $referenceDate = trim((string) ($filters['date_start'] ?? ''));
        if ($referenceDate === '') {
            $referenceDate = trim((string) ($filters['date_end'] ?? ''));
        }

        $reference = DateTimeImmutable::createFromFormat('Y-m-d', $referenceDate);
        if (!$reference instanceof DateTimeImmutable || $reference->format('Y-m-d') !== $referenceDate) {
            $reference = new DateTimeImmutable('today');
        }

        $monthStart = $reference->modify('first day of this month');
        $monthEnd = $reference->modify('last day of this month');

        return [
            'start' => $monthStart,
            'end' => $monthEnd,
            'month' => (int) $reference->format('n'),
            'year' => (int) $reference->format('Y'),
        ];
    }

    private function countWeekdaysInRange(DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        if ($end < $start) {
            return 0;
        }

        $count = 0;
        for ($cursor = $start; $cursor <= $end; $cursor = $cursor->modify('+1 day')) {
            if ((int) $cursor->format('N') <= 5) {
                $count++;
            }
        }

        return $count;
    }

    private function dashboardFromSql(): string
    {
        $this->ensureExpenseRefacturareColumn();
        $this->ensureRaceSoftDeleteSchema();

        return "
            FROM curse_dispecer c
            INNER JOIN vehicule v ON v.id = c.vehicle_id
            LEFT JOIN soferi s ON s.id = c.driver_id
            LEFT JOIN configurare_beneficiari_transport bt ON bt.id = c.beneficiar_id
            LEFT JOIN (
                SELECT
                    cursa_id,
                    GREATEST(
                        0,
                        SUM(COALESCE(suma, 0)) - SUM(
                            CASE
                                WHEN COALESCE(refacturare_facturata, 0) = 1 THEN COALESCE(refacturare_suma, 0)
                                ELSE 0
                            END
                        )
                    ) AS total_cheltuieli,
                    SUM(COALESCE(refacturare_suma, 0)) AS total_refacturare,
                    SUM(CASE WHEN COALESCE(refacturare_facturata, 0) = 1 THEN COALESCE(refacturare_suma, 0) ELSE 0 END) AS total_refacturare_facturata,
                    SUM(CASE WHEN COALESCE(refacturare_facturata, 0) = 1 THEN 0 ELSE COALESCE(refacturare_suma, 0) END) AS total_refacturare_pending
                FROM curse_cheltuieli
                GROUP BY cursa_id
            ) exp ON exp.cursa_id = c.id
        ";
    }

    private function buildDashboardWhere(array $filters): array
    {
        $where = [
            'c.deleted_at IS NULL',
        ];
        $params = [];

        if (($filters['date_start'] ?? null) !== null) {
            $where[] = 'c.data_inceput >= :dash_date_start';
            $params[':dash_date_start'] = (string) $filters['date_start'];
        }

        if (($filters['date_end'] ?? null) !== null) {
            $where[] = 'c.data_inceput <= :dash_date_end';
            $params[':dash_date_end'] = (string) $filters['date_end'];
        }

        $this->appendDashboardIntFilter($where, $params, 'c.vehicle_id', (array) ($filters['vehicle_ids'] ?? []), 'dash_vehicle_id');
        $this->appendDashboardIntFilter($where, $params, 'c.driver_id', (array) ($filters['driver_ids'] ?? []), 'dash_driver_id');
        $this->appendDashboardIntFilter($where, $params, 'c.beneficiar_id', (array) ($filters['beneficiary_ids'] ?? []), 'dash_beneficiary_id');
        $this->appendDashboardStringFilter($where, $params, 'c.tip_transport', (array) ($filters['transport_types'] ?? []), 'dash_transport_type');
        $this->appendDashboardDecimalFilter($where, $params, 'c.capacitate_transport', (array) ($filters['transport_capacities'] ?? []), 'dash_transport_capacity');
        $this->appendDashboardStringFilter($where, $params, 'c.status_facturare', (array) ($filters['statuses'] ?? []), 'dash_status');

        return [
            'where' => $where === [] ? '' : ' WHERE ' . implode(' AND ', $where),
            'params' => $params,
        ];
    }

    private function appendDashboardIntFilter(
        array &$where,
        array &$params,
        string $column,
        array $values,
        string $prefix
    ): void {
        $normalized = [];
        foreach ($values as $value) {
            if (!is_numeric((string) $value)) {
                continue;
            }
            $number = (int) $value;
            if ($number <= 0) {
                continue;
            }
            $normalized[$number] = $number;
        }

        if ($normalized === []) {
            return;
        }

        $placeholders = [];
        $index = 0;
        foreach (array_values($normalized) as $value) {
            $placeholder = ':' . $prefix . '_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $value;
            $index++;
        }

        $where[] = $column . ' IN (' . implode(', ', $placeholders) . ')';
    }

    private function appendDashboardDecimalFilter(
        array &$where,
        array &$params,
        string $column,
        array $values,
        string $prefix
    ): void {
        $normalized = [];
        foreach ($values as $value) {
            $item = str_replace(',', '.', trim((string) $value));
            if ($item === '' || !is_numeric($item)) {
                continue;
            }

            $number = (float) $item;
            if ($number <= 0) {
                continue;
            }

            $key = number_format($number, 2, '.', '');
            $normalized[$key] = $key;
        }

        if ($normalized === []) {
            return;
        }

        $placeholders = [];
        $index = 0;
        foreach (array_values($normalized) as $value) {
            $placeholder = ':' . $prefix . '_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $value;
            $index++;
        }

        $where[] = $column . ' IN (' . implode(', ', $placeholders) . ')';
    }

    private function appendDashboardStringFilter(
        array &$where,
        array &$params,
        string $column,
        array $values,
        string $prefix
    ): void {
        $normalized = [];
        foreach ($values as $value) {
            $item = trim((string) $value);
            if ($item === '') {
                continue;
            }
            $normalized[$item] = $item;
        }

        if ($normalized === []) {
            return;
        }

        $placeholders = [];
        $index = 0;
        foreach (array_values($normalized) as $value) {
            $placeholder = ':' . $prefix . '_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $value;
            $index++;
        }

        $where[] = $column . ' IN (' . implode(', ', $placeholders) . ')';
    }

    private function raceFromSql(): string
    {
        $this->ensureRaceCompressorLocationColumns();
        $this->ensureRaceCreatedByColumn();
        $this->ensureRaceSoftDeleteSchema();
        $this->ensureRaceAuditLogSchema();
        $this->ensureExpenseRefacturareColumn();

        return "
            FROM curse_dispecer c
            INNER JOIN vehicule v ON v.id = c.vehicle_id
            LEFT JOIN soferi s ON s.id = c.driver_id
            LEFT JOIN configurare_locuri_incarcare li ON li.id = c.loc_incarcare_id
            LEFT JOIN configurare_beneficiari_transport bt ON bt.id = c.beneficiar_id
            LEFT JOIN configurare_zone_distributie zd ON zd.id = c.zona_distributie_id
            LEFT JOIN utilizatori uc ON uc.id = c.created_by
            LEFT JOIN (
                SELECT
                    a.cursa_id,
                    a.performed_by,
                    a.performed_at
                FROM cursa_audit_log a
                INNER JOIN (
                    SELECT cursa_id, MAX(id) AS last_update_id
                    FROM cursa_audit_log
                    WHERE action = 'updated'
                    GROUP BY cursa_id
                ) latest_update ON latest_update.last_update_id = a.id
            ) lua ON lua.cursa_id = c.id
            LEFT JOIN utilizatori uu ON uu.id = lua.performed_by
            LEFT JOIN (
                SELECT
                    cursa_id,
                    COUNT(*) AS expense_count,
                    SUM(CASE WHEN COALESCE(refacturare_suma, 0) > 0 THEN 1 ELSE 0 END) AS refacturare_count,
                    SUM(
                        CASE
                            WHEN COALESCE(refacturare_suma, 0) > 0
                             AND COALESCE(refacturare_facturata, 0) = 0 THEN 1
                            ELSE 0
                        END
                    ) AS refacturare_pending_count,
                    GREATEST(
                        0,
                        SUM(COALESCE(suma, 0)) - SUM(
                            CASE
                                WHEN COALESCE(refacturare_facturata, 0) = 1 THEN COALESCE(refacturare_suma, 0)
                                ELSE 0
                            END
                        )
                    ) AS total_cheltuieli,
                    SUM(COALESCE(refacturare_suma, 0)) AS total_refacturare,
                    SUM(CASE WHEN COALESCE(refacturare_facturata, 0) = 1 THEN COALESCE(refacturare_suma, 0) ELSE 0 END) AS total_refacturare_facturata,
                    SUM(CASE WHEN COALESCE(refacturare_facturata, 0) = 1 THEN 0 ELSE COALESCE(refacturare_suma, 0) END) AS total_refacturare_pending
                FROM curse_cheltuieli
                GROUP BY cursa_id
            ) exp ON exp.cursa_id = c.id
        ";
    }

    private function buildRaceWhere(array $filters, string $search): array
    {
        $where = [
            'c.deleted_at IS NULL',
            $this->defaultBillingStatusExpression() . ' = :dispatcher_billing_status',
        ];
        $params = [
            ':dispatcher_billing_status' => self::DEFAULT_BILLING_STATUS,
        ];

        if ($search !== '') {
            $where[] = "(
                v.nr_inmatriculare LIKE :search
                OR v.marca LIKE :search
                OR v.model LIKE :search
                OR COALESCE(li.nume, '') LIKE :search
                OR COALESCE(c.loc_plecare, '') LIKE :search
                OR COALESCE(c.loc_aspirare, '') LIKE :search
                OR COALESCE(c.loc_livrare, '') LIKE :search
                OR COALESCE(c.loc_livrare_cursa, '') LIKE :search
                OR COALESCE(bt.nume, '') LIKE :search
                OR COALESCE(zd.nume, '') LIKE :search
                OR COALESCE(c.observatii, '') LIKE :search
            )";
            $params[':search'] = '%' . $search . '%';
        }

        if (($filters['tip_transport'] ?? '') !== '') {
            $where[] = "c.tip_transport = :tip_transport";
            $params[':tip_transport'] = (string) $filters['tip_transport'];
        }

        if (($filters['vehicle_id'] ?? '') !== '') {
            $where[] = "c.vehicle_id = :vehicle_id";
            $params[':vehicle_id'] = (int) $filters['vehicle_id'];
        }

        if (($filters['loc_incarcare_id'] ?? '') !== '') {
            $where[] = "c.loc_incarcare_id = :loc_incarcare_id";
            $params[':loc_incarcare_id'] = (int) $filters['loc_incarcare_id'];
        }

        if (($filters['beneficiar_id'] ?? '') !== '') {
            $where[] = "c.beneficiar_id = :beneficiar_id";
            $params[':beneficiar_id'] = (int) $filters['beneficiar_id'];
        }

        if (($filters['zona_distributie_id'] ?? '') !== '') {
            $where[] = "c.zona_distributie_id = :zona_distributie_id";
            $params[':zona_distributie_id'] = (int) $filters['zona_distributie_id'];
        }

        if (($filters['data_start'] ?? '') !== '') {
            $where[] = "c.data_inceput >= :data_start";
            $params[':data_start'] = (string) $filters['data_start'];
        }

        if (($filters['data_end'] ?? '') !== '') {
            $where[] = "c.data_sfarsit <= :data_end";
            $params[':data_end'] = (string) $filters['data_end'];
        }

        return [
            'where' => $where === [] ? '' : ' WHERE ' . implode(' AND ', $where),
            'params' => $params,
        ];
    }

    private function defaultBillingStatusExpression(string $column = 'c.status_facturare'): string
    {
        return "COALESCE(NULLIF(TRIM(" . $column . "), ''), '" . self::DEFAULT_BILLING_STATUS . "')";
    }

    private function buildBillingCentralizerWhere(array $filters, string $search, bool $includeStatusFilter): array
    {
        $where = [
            'c.deleted_at IS NULL',
        ];
        $params = [];

        if ($search !== '') {
            $searchValue = '%' . $search . '%';
            $where[] = "(
                v.nr_inmatriculare LIKE :billing_search_plate
                OR v.marca LIKE :billing_search_make
                OR v.model LIKE :billing_search_model
                OR COALESCE(s.nume, '') LIKE :billing_search_driver
                OR COALESCE(li.nume, '') LIKE :billing_search_loading
                OR COALESCE(bt.nume, '') LIKE :billing_search_beneficiary
                OR COALESCE(zd.nume, '') LIKE :billing_search_zone
                OR COALESCE(c.observatii, '') LIKE :billing_search_notes
            )";
            $params[':billing_search_plate'] = $searchValue;
            $params[':billing_search_make'] = $searchValue;
            $params[':billing_search_model'] = $searchValue;
            $params[':billing_search_driver'] = $searchValue;
            $params[':billing_search_loading'] = $searchValue;
            $params[':billing_search_beneficiary'] = $searchValue;
            $params[':billing_search_zone'] = $searchValue;
            $params[':billing_search_notes'] = $searchValue;
        }

        if ($includeStatusFilter && ($filters['status_facturare'] ?? '') !== '') {
            $where[] = "c.status_facturare = :billing_status";
            $params[':billing_status'] = (string) $filters['status_facturare'];
        }

        if (($filters['nr_inmatriculare'] ?? '') !== '') {
            $where[] = "v.nr_inmatriculare LIKE :billing_plate";
            $params[':billing_plate'] = '%' . (string) $filters['nr_inmatriculare'] . '%';
        }

        if (($filters['tip_transport'] ?? '') !== '') {
            if ((string) $filters['tip_transport'] === 'primar') {
                $where[] = "c.tip_transport IN (:billing_tip_transport_primar, :billing_tip_transport_primar_tona)";
                $params[':billing_tip_transport_primar'] = 'primar';
                $params[':billing_tip_transport_primar_tona'] = 'primar_tona';
            } else {
                $where[] = "c.tip_transport = :billing_tip_transport";
                $params[':billing_tip_transport'] = (string) $filters['tip_transport'];
            }
        }

        if (($filters['vehicle_id'] ?? '') !== '') {
            $where[] = "c.vehicle_id = :billing_vehicle_id";
            $params[':billing_vehicle_id'] = (int) $filters['vehicle_id'];
        }

        if (($filters['driver_id'] ?? '') !== '') {
            $where[] = "c.driver_id = :billing_driver_id";
            $params[':billing_driver_id'] = (int) $filters['driver_id'];
        }

        if (($filters['beneficiar_id'] ?? '') !== '') {
            $where[] = "c.beneficiar_id = :billing_beneficiar_id";
            $params[':billing_beneficiar_id'] = (int) $filters['beneficiar_id'];
        }

        if (($filters['tip_marfa'] ?? '') !== '') {
            $where[] = "FIND_IN_SET(:billing_tip_marfa, REPLACE(COALESCE(c.tip_marfa, ''), ' ', '')) > 0";
            $params[':billing_tip_marfa'] = (string) $filters['tip_marfa'];
        }

        if (($filters['zona_distributie_id'] ?? '') !== '') {
            $where[] = "c.zona_distributie_id = :billing_zona_distributie_id";
            $params[':billing_zona_distributie_id'] = (int) $filters['zona_distributie_id'];
        }

        $this->appendBillingLocationFilter(
            $where,
            $params,
            (array) ($filters['locatie_operationala'] ?? []),
            [
                'li.nume',
                'c.loc_plecare',
                'c.loc_aspirare',
                'c.loc_livrare',
                'c.loc_livrare_cursa',
                'zd.nume',
            ],
            'billing_location'
        );

        $this->appendBillingLocationFilter(
            $where,
            $params,
            (array) ($filters['loc_incarcare'] ?? []),
            [
                'li.nume',
                'c.loc_plecare',
            ],
            'billing_load_location'
        );

        $this->appendBillingLocationFilter(
            $where,
            $params,
            (array) ($filters['zona_distributie'] ?? []),
            [
                'c.loc_plecare',
                'c.loc_livrare',
                'c.loc_livrare_cursa',
                'zd.nume',
            ],
            'billing_distribution_location'
        );

        if (($filters['data_start'] ?? '') !== '') {
            $where[] = "COALESCE(c.data_inceput, c.data_cursa) >= :billing_data_start";
            $params[':billing_data_start'] = (string) $filters['data_start'];
        }

        if (($filters['data_end'] ?? '') !== '') {
            $where[] = "COALESCE(c.data_inceput, c.data_cursa) <= :billing_data_end";
            $params[':billing_data_end'] = (string) $filters['data_end'];
        }

        return [
            'where' => $where === [] ? '' : ' WHERE ' . implode(' AND ', $where),
            'params' => $params,
        ];
    }

    private function appendBillingLocationFilter(
        array &$where,
        array &$params,
        array $values,
        array $columns,
        string $prefix
    ): void {
        $normalized = [];
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            $key = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
            $normalized[$key] = $key;
        }

        if ($normalized === [] || $columns === []) {
            return;
        }

        $clauses = [];
        $index = 0;
        foreach (array_values($normalized) as $value) {
            $placeholder = ':' . $prefix . '_' . $index;
            $params[$placeholder] = $value;

            foreach ($columns as $column) {
                $clauses[] = "LOWER(TRIM(COALESCE(" . $column . ", ''))) = " . $placeholder;
            }
            $index++;
        }

        if ($clauses !== []) {
            $where[] = '(' . implode(' OR ', $clauses) . ')';
        }
    }

    private function bindParams(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            if (is_int($value)) {
                $stmt->bindValue($key, $value, PDO::PARAM_INT);
                continue;
            }

            if ($value === null) {
                $stmt->bindValue($key, null, PDO::PARAM_NULL);
                continue;
            }

            $stmt->bindValue($key, (string) $value, PDO::PARAM_STR);
        }
    }

    private function bindRaceMutationValues(PDOStatement $stmt, array $data): void
    {
        $stmt->bindValue(':vehicle_id', (int) $data['vehicle_id'], PDO::PARAM_INT);
        $this->bindNullableInt($stmt, ':driver_id', $data['driver_id'] ?? null);
        $stmt->bindValue(':tip_transport', (string) $data['tip_transport']);
        $stmt->bindValue(':data_cursa', (string) $data['data_cursa']);
        $this->bindNullableString($stmt, ':data_incarcare', $data['data_incarcare'] ?? null);
        $stmt->bindValue(':data_inceput', (string) $data['data_inceput']);
        $stmt->bindValue(':data_sfarsit', (string) $data['data_sfarsit']);
        $this->bindNullableString($stmt, ':ora_inceput', $data['ora_inceput'] ?? null);
        $this->bindNullableString($stmt, ':ora_sfarsit', $data['ora_sfarsit'] ?? null);
        $this->bindNullableInt($stmt, ':durata_cursa_minute', $data['durata_cursa_minute'] ?? null);
        $this->bindNullableInt($stmt, ':loc_incarcare_id', $data['loc_incarcare_id'] ?? null);
        $this->bindNullableString($stmt, ':loc_plecare', $data['loc_plecare'] ?? null);
        $this->bindNullableString($stmt, ':loc_aspirare', $data['loc_aspirare'] ?? null);
        $this->bindNullableString($stmt, ':loc_livrare', $data['loc_livrare'] ?? null);
        $this->bindNullableString($stmt, ':loc_livrare_cursa', $data['loc_livrare_cursa'] ?? null);
        $this->bindNullableInt($stmt, ':beneficiar_id', $data['beneficiar_id'] ?? null);
        $this->bindNullableString($stmt, ':tip_marfa', $data['tip_marfa'] ?? null);
        $this->bindNullableDecimal($stmt, ':capacitate_transport', $data['capacitate_transport'] ?? null);
        $this->bindNullableDecimal($stmt, ':cantitate_incarcata', $data['cantitate_incarcata'] ?? null);
        $this->bindNullableDecimal($stmt, ':cantitate_prelevata', $data['cantitate_prelevata'] ?? null);
        $this->bindNullableInt($stmt, ':nr_clienti', $data['nr_clienti'] ?? null);
        $this->bindNullableInt($stmt, ':km_cursa', $data['km_cursa'] ?? null);
        $this->bindNullableDecimal($stmt, ':ore_functionare', $data['ore_functionare'] ?? null);
        $this->bindNullableInt($stmt, ':km_totali', $data['km_totali'] ?? null);
        $this->bindNullableDecimal($stmt, ':ore_aspirare', $data['ore_aspirare'] ?? null);
        $this->bindNullableDecimal($stmt, ':km_dislocare', $data['km_dislocare'] ?? null);
        $this->bindNullableDecimal($stmt, ':tona_livrata', $data['tona_livrata'] ?? null);
        $this->bindNullableDecimal($stmt, ':tona_aspirata_lichida', $data['tona_aspirata_lichida'] ?? null);
        $this->bindNullableDecimal($stmt, ':tona_aspirata_gazoasa', $data['tona_aspirata_gazoasa'] ?? null);
        $this->bindNullableInt($stmt, ':zona_distributie_id', $data['zona_distributie_id'] ?? null);
        $stmt->bindValue(':status_facturare', (string) ($data['status_facturare'] ?? 'in_curs_facturare'));
        $stmt->bindValue(':pret_tarifare', (float) $data['pret_tarifare']);
        $stmt->bindValue(':total_facturare', (float) $data['total_facturare']);
        $stmt->bindValue(':cost_km_primar', (float) ($data['cost_km_primar'] ?? 0));
        $stmt->bindValue(':cost_km_distributie', (float) ($data['cost_km_distributie'] ?? 0));
        $stmt->bindValue(':cost_km_mixt', (float) ($data['cost_km_mixt'] ?? 0));
        $stmt->bindValue(':cost_km_compresor', (float) ($data['cost_km_compresor'] ?? 0));
        $this->bindNullableString($stmt, ':observatii', $data['observatii'] ?? null);
        if (array_key_exists('duplicate_key', $data)) {
            $stmt->bindValue(':duplicate_key', (string) $data['duplicate_key'], PDO::PARAM_STR);
        }
        if (array_key_exists('created_by', $data)) {
            $this->bindNullableInt($stmt, ':created_by', $data['created_by']);
        }

        if (isset($data['created_at'])) {
            $stmt->bindValue(':created_at', (string) $data['created_at']);
        }
        $stmt->bindValue(':updated_at', (string) $data['updated_at']);
    }

    private function buildRaceDuplicateKey(array $data): string
    {
        $payload = [];
        foreach (self::RACE_DUPLICATE_KEY_FIELDS as $field) {
            $payload[$field] = $this->normalizeRaceDuplicateValue($field, $data[$field] ?? null);
        }

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            $encoded = serialize($payload);
        }

        return hash('sha256', $encoded);
    }

    private function normalizeRaceDuplicateValue(string $field, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return null;
            }
        }

        if (in_array($field, self::RACE_DUPLICATE_INT_FIELDS, true)) {
            if (!is_numeric((string) $value)) {
                return null;
            }

            return (string) (int) $value;
        }

        if (in_array($field, self::RACE_DUPLICATE_DECIMAL_FIELDS, true)) {
            if (!is_numeric((string) $value)) {
                return null;
            }

            return number_format(round((float) $value, 2), 2, '.', '');
        }

        if ($field === 'tip_marfa') {
            $items = [];
            foreach (explode(',', (string) $value) as $item) {
                $item = trim($item);
                if ($item !== '') {
                    $items[$item] = $item;
                }
            }
            $items = array_values($items);
            sort($items, SORT_STRING);

            return $items === [] ? null : implode(',', $items);
        }

        return (string) $value;
    }

    private function bindNullableString(PDOStatement $stmt, string $placeholder, mixed $value): void
    {
        if ($value === null || trim((string) $value) === '') {
            $stmt->bindValue($placeholder, null, PDO::PARAM_NULL);
            return;
        }

        $stmt->bindValue($placeholder, (string) $value, PDO::PARAM_STR);
    }

    private function bindNullableInt(PDOStatement $stmt, string $placeholder, mixed $value): void
    {
        if ($value === null || $value === '' || !is_numeric((string) $value)) {
            $stmt->bindValue($placeholder, null, PDO::PARAM_NULL);
            return;
        }

        $stmt->bindValue($placeholder, (int) $value, PDO::PARAM_INT);
    }

    private function bindNullableDecimal(PDOStatement $stmt, string $placeholder, mixed $value): void
    {
        if ($value === null || $value === '') {
            $stmt->bindValue($placeholder, null, PDO::PARAM_NULL);
            return;
        }

        $stmt->bindValue($placeholder, (float) $value);
    }

    private function existsById(string $table, int $id): bool
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM {$table} WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn() > 0;
    }

    private function getRaceSnapshotForUpdate(int $raceId, bool $includeDeleted = false): ?array
    {
        $this->ensureRaceSoftDeleteSchema();

        $sql = "
            SELECT *
            FROM curse_dispecer
            WHERE id = :id
            " . ($includeDeleted ? "" : " AND deleted_at IS NULL") . "
            LIMIT 1
            FOR UPDATE
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $raceId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row ?: null;
    }

    private function syncVehicleKmForRaceChange(?array $oldRace, ?array $newRace): array
    {
        $deltaByVehicle = [];

        $oldVehicleId = $oldRace !== null ? (int) ($oldRace['vehicle_id'] ?? 0) : 0;
        $oldKmBord = $this->getRaceEffectiveKmForSync($oldRace);
        $oldKmRevizie = $this->getRaceEffectiveMaintenanceKmForSync($oldRace);
        if ($oldVehicleId > 0 && ($oldKmBord > 0 || $oldKmRevizie > 0)) {
            foreach ($this->getKmSyncVehicleIds($oldVehicleId) as $vehicleId) {
                if (!isset($deltaByVehicle[$vehicleId])) {
                    $deltaByVehicle[$vehicleId] = [
                        'km_bord' => 0,
                        'km_revizie' => 0,
                    ];
                }
                $deltaByVehicle[$vehicleId]['km_bord'] -= $oldKmBord;
                $deltaByVehicle[$vehicleId]['km_revizie'] -= $oldKmRevizie;
            }
        }

        $newVehicleId = $newRace !== null ? (int) ($newRace['vehicle_id'] ?? 0) : 0;
        $newKmBord = $this->getRaceEffectiveKmForSync($newRace);
        $newKmRevizie = $this->getRaceEffectiveMaintenanceKmForSync($newRace);
        if ($newVehicleId > 0 && ($newKmBord > 0 || $newKmRevizie > 0)) {
            foreach ($this->getKmSyncVehicleIds($newVehicleId) as $vehicleId) {
                if (!isset($deltaByVehicle[$vehicleId])) {
                    $deltaByVehicle[$vehicleId] = [
                        'km_bord' => 0,
                        'km_revizie' => 0,
                    ];
                }
                $deltaByVehicle[$vehicleId]['km_bord'] += $newKmBord;
                $deltaByVehicle[$vehicleId]['km_revizie'] += $newKmRevizie;
            }
        }

        $alerts = [];
        foreach ($deltaByVehicle as $vehicleId => $deltaValues) {
            $vehicleId = (int) $vehicleId;
            if ($vehicleId <= 0) {
                continue;
            }

            $deltaKmBord = (int) ($deltaValues['km_bord'] ?? 0);
            $deltaKmRevizie = (int) ($deltaValues['km_revizie'] ?? 0);
            if ($deltaKmBord === 0 && $deltaKmRevizie === 0) {
                continue;
            }

            $alert = $this->applyKmDeltaToVehicle($vehicleId, $deltaKmBord, $deltaKmRevizie);
            if ($alert !== null) {
                $alerts[] = $alert;
            }
        }

        return $alerts;
    }

    private function getRaceEffectiveKmForSync(?array $race): int
    {
        if ($race === null) {
            return 0;
        }

        $kmTotal = isset($race['km_totali']) && $race['km_totali'] !== null && $race['km_totali'] !== ''
            ? max(0, (int) $race['km_totali'])
            : 0;
        if ($kmTotal > 0) {
            return $kmTotal;
        }

        return isset($race['km_cursa']) && $race['km_cursa'] !== null && $race['km_cursa'] !== ''
            ? max(0, (int) $race['km_cursa'])
            : 0;
    }

    private function getRaceEffectiveMaintenanceKmForSync(?array $race): int
    {
        if ($race === null) {
            return 0;
        }

        $kmFromRace = $this->getRaceEffectiveKmForSync($race);
        $hoursRaw = $race['ore_aspirare'] ?? null;
        if ($hoursRaw === null || $hoursRaw === '') {
            $hoursRaw = $race['ore_functionare'] ?? null;
        }
        $hoursValue = $hoursRaw !== null && $hoursRaw !== ''
            ? max(0, (float) $hoursRaw)
            : 0.0;
        $hoursEquivalentKm = (int) max(0, round($hoursValue * 40));

        return $kmFromRace + $hoursEquivalentKm;
    }

    private function getKmSyncVehicleIds(int $vehicleId): array
    {
        if ($vehicleId <= 0) {
            return [];
        }

        $ids = [$vehicleId => true];

        $tractorStmt = $this->db->prepare("
            SELECT tractor_id, semiremorca_id
            FROM vehicule_cuplaje
            WHERE tractor_id = :vehicle_id_tractor
              AND (activ = 1 OR data_end IS NULL)
            ORDER BY
                CASE WHEN activ = 1 THEN 0 ELSE 1 END ASC,
                id DESC
            LIMIT 1
        ");
        $tractorStmt->bindValue(':vehicle_id_tractor', $vehicleId, PDO::PARAM_INT);
        $tractorStmt->execute();
        $tractorRow = $tractorStmt->fetch();
        if (is_array($tractorRow)) {
            $tractorId = (int) ($tractorRow['tractor_id'] ?? 0);
            $trailerId = (int) ($tractorRow['semiremorca_id'] ?? 0);
            if ($tractorId > 0) {
                $ids[$tractorId] = true;
            }
            if ($trailerId > 0) {
                $ids[$trailerId] = true;
            }
        }

        $trailerStmt = $this->db->prepare("
            SELECT tractor_id, semiremorca_id
            FROM vehicule_cuplaje
            WHERE semiremorca_id = :vehicle_id_semiremorca
              AND (activ = 1 OR data_end IS NULL)
            ORDER BY
                CASE WHEN activ = 1 THEN 0 ELSE 1 END ASC,
                id DESC
            LIMIT 1
        ");
        $trailerStmt->bindValue(':vehicle_id_semiremorca', $vehicleId, PDO::PARAM_INT);
        $trailerStmt->execute();
        $trailerRow = $trailerStmt->fetch();
        if (is_array($trailerRow)) {
            $tractorId = (int) ($trailerRow['tractor_id'] ?? 0);
            $trailerId = (int) ($trailerRow['semiremorca_id'] ?? 0);
            if ($tractorId > 0) {
                $ids[$tractorId] = true;
            }
            if ($trailerId > 0) {
                $ids[$trailerId] = true;
            }
        }

        return array_map('intval', array_keys($ids));
    }

    private function applyKmDeltaToVehicle(int $vehicleId, int $deltaKmBord, int $deltaKmRevizie): ?array
    {
        if ($vehicleId <= 0 || ($deltaKmBord === 0 && $deltaKmRevizie === 0)) {
            return null;
        }

        $fetch = $this->db->prepare("
            SELECT id, nr_inmatriculare, COALESCE(km_bord, 0) AS km_bord, COALESCE(km_revizie, 0) AS km_revizie
            FROM vehicule
            WHERE id = :id
            LIMIT 1
            FOR UPDATE
        ");
        $fetch->bindValue(':id', $vehicleId, PDO::PARAM_INT);
        $fetch->execute();
        $vehicle = $fetch->fetch();

        if (!$vehicle) {
            return null;
        }

        $oldKmBord = max(0, (int) ($vehicle['km_bord'] ?? 0));
        $oldKmRevizie = max(0, (int) ($vehicle['km_revizie'] ?? 0));

        $newKmBord = max(0, $oldKmBord + $deltaKmBord);
        if ($deltaKmRevizie < 0 && $oldKmRevizie <= 0) {
            $newKmRevizie = 0;
        } else {
            $newKmRevizie = max(0, $oldKmRevizie - $deltaKmRevizie);
        }

        $update = $this->db->prepare("
            UPDATE vehicule
            SET km_bord = :km_bord, km_revizie = :km_revizie, updated_at = :updated_at
            WHERE id = :id
        ");
        $update->bindValue(':km_bord', $newKmBord, PDO::PARAM_INT);
        $update->bindValue(':km_revizie', $newKmRevizie, PDO::PARAM_INT);
        $update->bindValue(':updated_at', date('Y-m-d H:i:s'));
        $update->bindValue(':id', $vehicleId, PDO::PARAM_INT);
        $update->execute();

        $crossedToZero = $deltaKmRevizie > 0 && $oldKmRevizie > 0 && $newKmRevizie <= 0;
        if (!$crossedToZero) {
            return null;
        }

        return [
            'vehicle_id' => $vehicleId,
            'nr_inmatriculare' => (string) ($vehicle['nr_inmatriculare'] ?? ''),
            'km_bord' => $newKmBord,
            'km_revizie' => $newKmRevizie,
        ];
    }
}
