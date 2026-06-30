<?php
declare(strict_types=1);

class DispecerCurseModel extends BaseModel
{
    private const DISTRIBUTION_ROUTE_SCOPE_DISTRIBUTIE = 'distributie';
    private const DISTRIBUTION_ROUTE_SCOPE_PRIMAR_DISTRIBUTIE = 'primar_distributie';
    private const DISTRIBUTION_ROUTE_TARIFF_MODE_BOTH = 'tona_km';
    private const DISTRIBUTION_ROUTE_TARIFF_MODE_TON = 'tona';
    private const DISTRIBUTION_ROUTE_TARIFF_MODE_KM = 'km';

    private bool $distributionRouteTableEnsured = false;
    private bool $primaryRouteTableEnsured = false;
    private bool $compressorVehicleAssignmentTableEnsured = false;
    private bool $raceCompressorLocationColumnsEnsured = false;
    private bool $raceCostPerKmColumnsEnsured = false;
    private bool $raceLoadingDateColumnEnsured = false;
    private bool $raceCreatedByColumnEnsured = false;
    private bool $raceExpenseStatusColumnEnsured = false;
    private bool $expenseRefacturareColumnEnsured = false;
    private bool $transportBeneficiaryColumnsEnsured = false;

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
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        $whereData = $this->buildRaceWhere($filters, $search);
        $from = $this->raceFromSql();

        $countSql = "SELECT COUNT(*)" . $from . $whereData['where'];
        $countStmt = $this->db->prepare($countSql);
        $this->bindParams($countStmt, $whereData['params']);
        $countStmt->execute();
        $totalRows = (int) $countStmt->fetchColumn();

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
                uc.nume AS creat_de_nume,
                li.nume AS loc_incarcare_nume,
                bt.nume AS beneficiar_nume,
                zd.nume AS zona_distributie_nume,
                COALESCE(exp.total_cheltuieli, 0) AS total_cheltuieli,
                COALESCE(exp.total_refacturare_facturata, 0) AS total_refacturare_facturata,
                COALESCE(exp.total_refacturare_pending, 0) AS total_refacturare_pending
            " . $from . $whereData['where'] . "
            ORDER BY c.data_inceput DESC, c.data_sfarsit DESC, c.id DESC
            LIMIT :limit_rows OFFSET :offset_rows
        ";

        $dataStmt = $this->db->prepare($dataSql);
        $this->bindParams($dataStmt, $whereData['params']);
        $dataStmt->bindValue(':limit_rows', $perPage, PDO::PARAM_INT);
        $dataStmt->bindValue(':offset_rows', $offset, PDO::PARAM_INT);
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

        $page = max(1, $page);
        $perPage = max(1, $perPage);

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

        $totalPages = max(1, (int) ceil($totalRows / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

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
            ORDER BY c.data_inceput DESC, c.data_sfarsit DESC, c.id DESC
            LIMIT :limit_rows OFFSET :offset_rows
        ";

        $dataStmt = $this->db->prepare($dataSql);
        $this->bindParams($dataStmt, $whereData['params']);
        $dataStmt->bindValue(':limit_rows', $perPage, PDO::PARAM_INT);
        $dataStmt->bindValue(':offset_rows', $offset, PDO::PARAM_INT);
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

    public function getOpenRacesOverview(int $limit = 25): array
    {
        $this->ensureRaceExpenseStatusColumn();

        $limit = max(1, min(100, $limit));

        $countSql = "
            SELECT
                SUM(CASE WHEN c.ora_sfarsit IS NULL THEN 1 ELSE 0 END) AS missing_end_time_count,
                SUM(
                    CASE
                        WHEN COALESCE(exp.expense_count, 0) = 0
                         AND COALESCE(c.cheltuieli_status, 'pending') <> 'not_applicable' THEN 1
                        ELSE 0
                    END
                ) AS missing_expenses_count,
                SUM(
                    CASE
                        WHEN c.ora_sfarsit IS NULL
                          OR (
                              COALESCE(exp.expense_count, 0) = 0
                              AND COALESCE(c.cheltuieli_status, 'pending') <> 'not_applicable'
                          ) THEN 1
                        ELSE 0
                    END
                ) AS total_missing_count
            FROM curse_dispecer c
            LEFT JOIN (
                SELECT cursa_id, COUNT(*) AS expense_count
                FROM curse_cheltuieli
                GROUP BY cursa_id
            ) exp ON exp.cursa_id = c.id
        ";
        $countStmt = $this->db->query($countSql);
        $countRow = $countStmt->fetch() ?: [];
        $totalMissingCount = max(0, (int) ($countRow['total_missing_count'] ?? 0));
        $missingEndTimeCount = max(0, (int) ($countRow['missing_end_time_count'] ?? 0));
        $missingExpensesCount = max(0, (int) ($countRow['missing_expenses_count'] ?? 0));

        if ($totalMissingCount <= 0) {
            return [
                'count' => 0,
                'rows' => [],
                'missing_end_time_count' => 0,
                'missing_expenses_count' => 0,
            ];
        }

        $listSql = "
            SELECT
                c.id,
                c.tip_transport,
                c.data_inceput,
                c.data_sfarsit,
                c.ora_inceput,
                c.ora_sfarsit,
                c.status_facturare,
                v.nr_inmatriculare,
                v.marca,
                v.model,
                COALESCE(s.nume, '') AS sofer_nume,
                COALESCE(bt.nume, '') AS beneficiar_nume,
                CASE WHEN c.ora_sfarsit IS NULL THEN 1 ELSE 0 END AS missing_end_time,
                CASE
                    WHEN COALESCE(exp.expense_count, 0) = 0
                     AND COALESCE(c.cheltuieli_status, 'pending') <> 'not_applicable' THEN 1
                    ELSE 0
                END AS missing_expenses,
                COALESCE(exp.expense_count, 0) AS expense_count
            FROM curse_dispecer c
            INNER JOIN vehicule v ON v.id = c.vehicle_id
            LEFT JOIN soferi s ON s.id = c.driver_id
            LEFT JOIN configurare_beneficiari_transport bt ON bt.id = c.beneficiar_id
            LEFT JOIN (
                SELECT cursa_id, COUNT(*) AS expense_count
                FROM curse_cheltuieli
                GROUP BY cursa_id
            ) exp ON exp.cursa_id = c.id
            WHERE c.ora_sfarsit IS NULL
               OR (
                   COALESCE(exp.expense_count, 0) = 0
                   AND COALESCE(c.cheltuieli_status, 'pending') <> 'not_applicable'
               )
            ORDER BY c.data_inceput ASC, c.id ASC
            LIMIT :limit_rows
        ";

        $listStmt = $this->db->prepare($listSql);
        $listStmt->bindValue(':limit_rows', $limit, PDO::PARAM_INT);
        $listStmt->execute();

        return [
            'count' => $totalMissingCount,
            'rows' => $listStmt->fetchAll(),
            'missing_end_time_count' => $missingEndTimeCount,
            'missing_expenses_count' => $missingExpensesCount,
        ];
    }

    public function getRaceById(int $id): ?array
    {
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
                COALESCE(exp.total_cheltuieli, 0) AS total_cheltuieli,
                COALESCE(exp.total_refacturare_facturata, 0) AS total_refacturare_facturata,
                COALESCE(exp.total_refacturare_pending, 0) AS total_refacturare_pending
            " . $this->raceFromSql() . "
            WHERE c.id = :id
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

    public function createRace(array $data): int
    {
        $this->ensureRaceCompressorLocationColumns();
        $this->ensureRaceCostPerKmColumns();
        $this->ensureRaceLoadingDateColumn();
        $this->ensureRaceCreatedByColumn();
        $this->ensureRaceExpenseStatusColumn();

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
                updated_at
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
                :updated_at
            )
        ";

        $stmt = $this->db->prepare($sql);
        $this->bindRaceMutationValues($stmt, $data);
        $stmt->execute();

        return (int) $this->db->lastInsertId();
    }

    public function createRaceAndSyncVehicleKm(array $data): array
    {
        $this->db->beginTransaction();

        try {
            $raceId = $this->createRace($data);
            $alerts = $this->syncVehicleKmForRaceChange(null, $data);

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
                updated_at = :updated_at
            WHERE id = :id
        ";

        $stmt = $this->db->prepare($sql);
        $this->bindRaceMutationValues($stmt, $data);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function updateRaceAndSyncVehicleKm(int $id, array $data): array
    {
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

    public function updateRaceBillingStatus(int $id, string $billingStatus, string $updatedAt): bool
    {
        $stmt = $this->db->prepare("
            UPDATE curse_dispecer
            SET status_facturare = :status_facturare,
                updated_at = :updated_at
            WHERE id = :id
        ");
        $stmt->bindValue(':status_facturare', $billingStatus, PDO::PARAM_STR);
        $stmt->bindValue(':updated_at', $updatedAt, PDO::PARAM_STR);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function updateRaceExpenseStatus(int $id, string $expenseStatus, string $updatedAt): bool
    {
        $this->ensureRaceExpenseStatusColumn();

        if (!in_array($expenseStatus, ['pending', 'not_applicable'], true)) {
            $expenseStatus = 'pending';
        }

        $stmt = $this->db->prepare("
            UPDATE curse_dispecer
            SET cheltuieli_status = :cheltuieli_status,
                updated_at = :updated_at
            WHERE id = :id
        ");
        $stmt->bindValue(':cheltuieli_status', $expenseStatus, PDO::PARAM_STR);
        $stmt->bindValue(':updated_at', $updatedAt, PDO::PARAM_STR);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function deleteRace(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM curse_dispecer WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function deleteRaceAndSyncVehicleKm(int $id): bool
    {
        $this->db->beginTransaction();

        try {
            $previousRace = $this->getRaceSnapshotForUpdate($id);
            if ($previousRace === null) {
                throw new RuntimeException('Cursa nu exista pentru stergere.');
            }

            $deleted = $this->deleteRace($id);
            if (!$deleted) {
                throw new RuntimeException('Stergerea cursei a esuat.');
            }
            $this->syncVehicleKmForRaceChange($previousRace, null);

            $this->db->commit();

            return $deleted;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    public function getRaceExpenses(int $raceId): array
    {
        $this->ensureExpenseRefacturareColumn();

        $sql = "
            SELECT
                e.*,
                d.file_path,
                d.original_name,
                d.mime_type,
                d.file_size
            FROM curse_cheltuieli e
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
        $this->ensureExpenseRefacturareColumn();

        $sql = "
            SELECT
                e.*,
                d.file_path,
                d.original_name,
                d.mime_type,
                d.file_size
            FROM curse_cheltuieli e
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
        $this->ensureExpenseRefacturareColumn();

        $sql = "
            INSERT INTO curse_cheltuieli (
                cursa_id,
                tip_cheltuiala,
                refacturare_tip_cheltuiala,
                refacturare_detalii,
                refacturare_suma,
                refacturare_data,
                refacturare_observatii,
                suma,
                data_cheltuiala,
                observatii,
                created_at,
                updated_at
            ) VALUES (
                :cursa_id,
                :tip_cheltuiala,
                :refacturare_tip_cheltuiala,
                :refacturare_detalii,
                :refacturare_suma,
                :refacturare_data,
                :refacturare_observatii,
                :suma,
                :data_cheltuiala,
                :observatii,
                :created_at,
                :updated_at
            )
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':cursa_id', (int) $data['cursa_id'], PDO::PARAM_INT);
        $stmt->bindValue(':tip_cheltuiala', (string) $data['tip_cheltuiala']);
        $this->bindNullableString($stmt, ':refacturare_tip_cheltuiala', $data['refacturare_tip_cheltuiala'] ?? null);
        $this->bindNullableString($stmt, ':refacturare_detalii', $data['refacturare_detalii'] ?? null);
        $this->bindNullableDecimal($stmt, ':refacturare_suma', $data['refacturare_suma'] ?? null);
        $this->bindNullableString($stmt, ':refacturare_data', $data['refacturare_data'] ?? null);
        $this->bindNullableString($stmt, ':refacturare_observatii', $data['refacturare_observatii'] ?? null);
        $stmt->bindValue(':suma', (float) $data['suma']);
        $stmt->bindValue(':data_cheltuiala', (string) $data['data_cheltuiala']);
        $this->bindNullableString($stmt, ':observatii', $data['observatii'] ?? null);
        $stmt->bindValue(':created_at', (string) $data['created_at']);
        $stmt->bindValue(':updated_at', (string) $data['updated_at']);
        $stmt->execute();

        return (int) $this->db->lastInsertId();
    }

    public function updateExpense(int $id, array $data): bool
    {
        $this->ensureExpenseRefacturareColumn();

        $sql = "
            UPDATE curse_cheltuieli
            SET
                tip_cheltuiala = :tip_cheltuiala,
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
            SELECT id, vehicle_id, status, nume
            FROM soferi
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row ?: null;
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
        return $this->existsById('curse_dispecer', $id);
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
            ORDER BY v.nr_inmatriculare ASC
        ");

        $driversStmt = $this->db->query("
            SELECT DISTINCT
                c.driver_id AS id,
                COALESCE(NULLIF(TRIM(s.nume), ''), 'Fara sofer') AS nume
            {$from}
            ORDER BY nume ASC
        ");

        $beneficiariesStmt = $this->db->query("
            SELECT DISTINCT
                c.beneficiar_id AS id,
                COALESCE(NULLIF(TRIM(bt.nume), ''), 'Fara beneficiar') AS nume
            {$from}
            ORDER BY nume ASC
        ");

        $transportTypesStmt = $this->db->query("
            SELECT DISTINCT c.tip_transport
            FROM curse_dispecer c
            WHERE COALESCE(TRIM(c.tip_transport), '') <> ''
            ORDER BY c.tip_transport ASC
        ");

        $statusesStmt = $this->db->query("
            SELECT DISTINCT c.status_facturare
            FROM curse_dispecer c
            WHERE COALESCE(TRIM(c.status_facturare), '') <> ''
            ORDER BY c.status_facturare ASC
        ");

        $capacitiesStmt = $this->db->query("
            SELECT DISTINCT c.capacitate_transport
            FROM curse_dispecer c
            WHERE c.capacitate_transport IS NOT NULL
              AND c.capacitate_transport > 0
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

    private function buildDashboardUtilizationWhere(array $filters, string $monthStart, string $monthEnd): array
    {
        $where = [
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
        $where = [];
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
        $where = [];
        $params = [];

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

    private function buildBillingCentralizerWhere(array $filters, string $search, bool $includeStatusFilter): array
    {
        $where = [];
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

        if (($filters['tip_transport'] ?? '') !== '') {
            $where[] = "c.tip_transport = :billing_tip_transport";
            $params[':billing_tip_transport'] = (string) $filters['tip_transport'];
        }

        if (($filters['vehicle_id'] ?? '') !== '') {
            $where[] = "c.vehicle_id = :billing_vehicle_id";
            $params[':billing_vehicle_id'] = (int) $filters['vehicle_id'];
        }

        if (($filters['beneficiar_id'] ?? '') !== '') {
            $where[] = "c.beneficiar_id = :billing_beneficiar_id";
            $params[':billing_beneficiar_id'] = (int) $filters['beneficiar_id'];
        }

        if (($filters['zona_distributie_id'] ?? '') !== '') {
            $where[] = "c.zona_distributie_id = :billing_zona_distributie_id";
            $params[':billing_zona_distributie_id'] = (int) $filters['zona_distributie_id'];
        }

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
        if (array_key_exists('created_by', $data)) {
            $this->bindNullableInt($stmt, ':created_by', $data['created_by']);
        }

        if (isset($data['created_at'])) {
            $stmt->bindValue(':created_at', (string) $data['created_at']);
        }
        $stmt->bindValue(':updated_at', (string) $data['updated_at']);
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

    private function getRaceSnapshotForUpdate(int $raceId): ?array
    {
        $sql = "
            SELECT id, vehicle_id, km_cursa, ore_functionare, ore_aspirare, km_totali
            FROM curse_dispecer
            WHERE id = :id
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
