<?php
declare(strict_types=1);

class ProgramareConcediiModel extends BaseModel
{
    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->ensureDriverVehicleAssignmentsSchema();
        $this->ensureAvailabilityRulesSchema();
    }

    public function getActiveDrivers(): array
    {
        $sql = "
            SELECT
                s.id,
                s.nume,
                s.telefon,
                s.vehicle_id,
                GROUP_CONCAT(
                    DISTINCT v.nr_inmatriculare
                    ORDER BY sv.is_primary DESC, v.nr_inmatriculare ASC
                    SEPARATOR ', '
                ) AS nr_inmatriculare,
                CASE
                    WHEN EXISTS (
                        SELECT 1
                        FROM concedii c
                        WHERE c.driver_id = s.id
                          AND c.status = 'aprobat'
                          AND CURDATE() BETWEEN c.data_inceput AND c.data_sfarsit
                    ) THEN 1
                    ELSE 0
                END AS indisponibil_astazi
            FROM soferi s
            LEFT JOIN soferi_vehicule sv ON sv.driver_id = s.id
            LEFT JOIN vehicule v ON v.id = sv.vehicle_id
            WHERE s.status = 'activ'
            GROUP BY s.id, s.nume, s.telefon, s.vehicle_id
            ORDER BY s.nume ASC
        ";

        $stmt = $this->db->query($sql);

        return $stmt->fetchAll();
    }

    public function driverExistsAndActive(int $driverId): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM soferi
            WHERE id = :id
              AND status = 'activ'
        ");
        $stmt->bindValue(':id', $driverId, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn() > 0;
    }

    public function getRequestById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT
                c.*,
                d.nume AS sofer_nume,
                d.telefon AS sofer_telefon,
                v.nr_inmatriculare AS sofer_nr_inmatriculare,
                r.nume AS inlocuitor_nume,
                rv.nr_inmatriculare AS inlocuitor_nr_inmatriculare,
                u.nume AS creat_de_nume
            FROM concedii c
            INNER JOIN soferi d ON d.id = c.driver_id
            LEFT JOIN vehicule v ON v.id = d.vehicle_id
            LEFT JOIN soferi r ON r.id = c.inlocuitor_id
            LEFT JOIN vehicule rv ON rv.id = r.vehicle_id
            LEFT JOIN utilizatori u ON u.id = c.created_by
            WHERE c.id = :id
            LIMIT 1
        ");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function getPaginatedRequests(array $filters, string $search, int $page, int $perPage): array
    {
        $whereData = $this->buildRequestWhere($filters, $search);

        $countSql = "
            SELECT COUNT(*)
            FROM concedii c
            INNER JOIN soferi d ON d.id = c.driver_id
            LEFT JOIN soferi r ON r.id = c.inlocuitor_id
            " . $whereData['where'];
        $countStmt = $this->db->prepare($countSql);
        $this->bindParams($countStmt, $whereData['params']);
        $countStmt->execute();

        $totalRows = (int) $countStmt->fetchColumn();
        $totalPages = max(1, (int) ceil($totalRows / max(1, $perPage)));
        $page = min(max(1, $page), $totalPages);
        $offset = ($page - 1) * $perPage;

        $sql = "
            SELECT
                c.*,
                d.nume AS sofer_nume,
                d.telefon AS sofer_telefon,
                v.nr_inmatriculare AS sofer_nr_inmatriculare,
                r.nume AS inlocuitor_nume,
                rv.nr_inmatriculare AS inlocuitor_nr_inmatriculare,
                u.nume AS creat_de_nume,
                CASE
                    WHEN EXISTS (
                        SELECT 1
                        FROM concedii ca
                        WHERE ca.id <> c.id
                          AND ca.driver_id = c.driver_id
                          AND ca.status = 'aprobat'
                          AND NOT (ca.data_sfarsit < c.data_inceput OR ca.data_inceput > c.data_sfarsit)
                    ) THEN 1
                    ELSE 0
                END AS conflict_cu_aprobat
            FROM concedii c
            INNER JOIN soferi d ON d.id = c.driver_id
            LEFT JOIN vehicule v ON v.id = d.vehicle_id
            LEFT JOIN soferi r ON r.id = c.inlocuitor_id
            LEFT JOIN vehicule rv ON rv.id = r.vehicle_id
            LEFT JOIN utilizatori u ON u.id = c.created_by
            " . $whereData['where'] . "
            ORDER BY c.data_inceput DESC, c.id DESC
            LIMIT :limit_rows OFFSET :offset_rows
        ";

        $stmt = $this->db->prepare($sql);
        $this->bindParams($stmt, $whereData['params']);
        $stmt->bindValue(':limit_rows', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset_rows', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'rows' => $stmt->fetchAll(),
            'total_rows' => $totalRows,
            'total_pages' => $totalPages,
            'page' => $page,
        ];
    }

    public function getCalendarEvents(string $rangeStart, string $rangeEnd, string $driverSearch = ''): array
    {
        $sql = "
            SELECT
                c.id,
                c.driver_id,
                c.tip_concediu,
                c.data_inceput,
                c.data_sfarsit,
                c.inlocuitor_id,
                c.note,
                c.status,
                d.nume AS sofer_nume,
                d.telefon AS sofer_telefon,
                v.nr_inmatriculare AS sofer_nr_inmatriculare,
                r.nume AS inlocuitor_nume,
                rv.nr_inmatriculare AS inlocuitor_nr_inmatriculare
            FROM concedii c
            INNER JOIN soferi d ON d.id = c.driver_id
            LEFT JOIN vehicule v ON v.id = d.vehicle_id
            LEFT JOIN soferi r ON r.id = c.inlocuitor_id
            LEFT JOIN vehicule rv ON rv.id = r.vehicle_id
            WHERE c.data_inceput <= :range_end
              AND c.data_sfarsit >= :range_start
              AND c.status <> 'respins'
        ";

        $params = [
            ':range_start' => $rangeStart,
            ':range_end' => $rangeEnd,
        ];

        if ($driverSearch !== '') {
            $sql .= "
              AND (
                    d.nume LIKE :driver_search
                    OR COALESCE(v.nr_inmatriculare, '') LIKE :driver_search
              )
            ";
            $params[':driver_search'] = '%' . $driverSearch . '%';
        }

        $sql .= " ORDER BY c.data_inceput ASC, c.id ASC";

        $stmt = $this->db->prepare($sql);
        $this->bindParams($stmt, $params);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getStatsSnapshot(): array
    {
        $totalDriversStmt = $this->db->query("
            SELECT COUNT(*)
            FROM soferi
            WHERE status = 'activ'
        ");
        $totalDrivers = (int) $totalDriversStmt->fetchColumn();

        $onLeaveStmt = $this->db->query("
            SELECT COUNT(DISTINCT c.driver_id)
            FROM concedii c
            INNER JOIN soferi s ON s.id = c.driver_id
            WHERE s.status = 'activ'
              AND c.status = 'aprobat'
              AND CURDATE() BETWEEN c.data_inceput AND c.data_sfarsit
        ");
        $onLeave = (int) $onLeaveStmt->fetchColumn();

        $pendingStmt = $this->db->query("
            SELECT COUNT(*)
            FROM concedii
            WHERE status IN ('in_asteptare', 'in_asteptare_aprobare')
        ");
        $pending = (int) $pendingStmt->fetchColumn();

        $conflictsStmt = $this->db->query("
            SELECT COUNT(*)
            FROM concedii c
            WHERE c.status IN ('in_asteptare', 'in_asteptare_aprobare')
              AND EXISTS (
                    SELECT 1
                    FROM concedii ca
                    WHERE ca.id <> c.id
                      AND ca.driver_id = c.driver_id
                      AND ca.status = 'aprobat'
                      AND NOT (ca.data_sfarsit < c.data_inceput OR ca.data_inceput > c.data_sfarsit)
              )
        ");
        $conflicts = (int) $conflictsStmt->fetchColumn();

        $available = max(0, $totalDrivers - $onLeave);
        $availablePercentage = $totalDrivers > 0
            ? (int) round(($available / $totalDrivers) * 100)
            : 0;

        return [
            'total_drivers' => $totalDrivers,
            'available_drivers' => $available,
            'available_percentage' => $availablePercentage,
            'on_leave' => $onLeave,
            'pending' => $pending,
            'conflicts' => $conflicts,
        ];
    }

    public function createRequest(array $data): int
    {
        $sql = "
            INSERT INTO concedii (
                driver_id,
                tip_concediu,
                data_inceput,
                data_sfarsit,
                inlocuitor_id,
                note,
                status,
                created_by,
                created_at,
                updated_at
            ) VALUES (
                :driver_id,
                :tip_concediu,
                :data_inceput,
                :data_sfarsit,
                :inlocuitor_id,
                :note,
                :status,
                :created_by,
                :created_at,
                :updated_at
            )
        ";

        $stmt = $this->db->prepare($sql);
        $this->bindMutationValues($stmt, $data);
        $stmt->execute();

        return (int) $this->db->lastInsertId();
    }

    public function updateRequest(int $id, array $data): bool
    {
        $sql = "
            UPDATE concedii
            SET
                driver_id = :driver_id,
                tip_concediu = :tip_concediu,
                data_inceput = :data_inceput,
                data_sfarsit = :data_sfarsit,
                inlocuitor_id = :inlocuitor_id,
                note = :note,
                status = :status,
                updated_at = :updated_at
            WHERE id = :id
        ";

        $stmt = $this->db->prepare($sql);
        $this->bindMutationValues($stmt, $data);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function updateStatus(int $id, string $status): bool
    {
        $stmt = $this->db->prepare("
            UPDATE concedii
            SET status = :status, updated_at = :updated_at
            WHERE id = :id
        ");
        $stmt->bindValue(':status', $status, PDO::PARAM_STR);
        $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function deleteRequest(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM concedii WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function isDeleteAllowed(int $id): bool
    {
        $stmt = $this->db->prepare("
            SELECT status
            FROM concedii
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $status = (string) ($stmt->fetchColumn() ?: '');

        return in_array($status, ['in_asteptare', 'in_asteptare_aprobare', 'respins', 'aprobat'], true);
    }

    public function getOverlappingApprovedRequests(int $driverId, string $startDate, string $endDate, ?int $excludeId = null): array
    {
        $sql = "
            SELECT id, data_inceput, data_sfarsit, status
            FROM concedii
            WHERE driver_id = :driver_id
              AND status = 'aprobat'
              AND NOT (data_sfarsit < :start_date OR data_inceput > :end_date)
        ";

        $params = [
            ':driver_id' => $driverId,
            ':start_date' => $startDate,
            ':end_date' => $endDate,
        ];

        if ($excludeId !== null && $excludeId > 0) {
            $sql .= ' AND id <> :exclude_id';
            $params[':exclude_id'] = $excludeId;
        }

        $sql .= ' ORDER BY data_inceput ASC';

        $stmt = $this->db->prepare($sql);
        $this->bindParams($stmt, $params);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getMaxUnavailableDriversInRange(string $startDate, string $endDate): int
    {
        $stmt = $this->db->prepare("
            SELECT driver_id, data_inceput, data_sfarsit
            FROM concedii
            WHERE status = 'aprobat'
              AND data_inceput <= :end_date
              AND data_sfarsit >= :start_date
        ");
        $stmt->bindValue(':start_date', $startDate, PDO::PARAM_STR);
        $stmt->bindValue(':end_date', $endDate, PDO::PARAM_STR);
        $stmt->execute();

        $intervals = $stmt->fetchAll();
        if ($intervals === []) {
            return 0;
        }

        $start = new DateTime($startDate);
        $end = new DateTime($endDate);
        $maxUnavailable = 0;

        for ($cursor = clone $start; $cursor <= $end; $cursor->modify('+1 day')) {
            $day = $cursor->format('Y-m-d');
            $unavailableDriverMap = [];

            foreach ($intervals as $interval) {
                $from = (string) ($interval['data_inceput'] ?? '');
                $to = (string) ($interval['data_sfarsit'] ?? '');
                $driverId = (int) ($interval['driver_id'] ?? 0);

                if ($driverId <= 0 || $from === '' || $to === '') {
                    continue;
                }

                if ($day >= $from && $day <= $to) {
                    $unavailableDriverMap[$driverId] = true;
                }
            }

            $count = count($unavailableDriverMap);
            if ($count > $maxUnavailable) {
                $maxUnavailable = $count;
            }
        }

        return $maxUnavailable;
    }

    public function getAvailabilityRules(): array
    {
        $this->ensureAvailabilityRulesSchema();

        $stmt = $this->db->query("
            SELECT *
            FROM concedii_reguli_disponibilitate
            ORDER BY garaj ASC, categorie_vehicul ASC, capacitate_transport IS NULL ASC, capacitate_transport ASC, id DESC
        ");

        return $stmt->fetchAll();
    }

    public function getAvailabilityRuleOptions(): array
    {
        $capacityExpr = $this->transportCapacityExpression();
        $sql = "
            SELECT DISTINCT
                NULLIF(TRIM(v.garaj), '') AS garaj,
                CASE
                    WHEN v.tip_vehicul = 'camion' THEN 'camion'
                    WHEN v.tip_vehicul = 'cap_tractor' THEN 'ansamblu'
                    ELSE NULL
                END AS categorie_vehicul,
                {$capacityExpr} AS capacitate_transport
            FROM vehicule v
            " . $this->latestActiveCouplingJoin() . "
            LEFT JOIN vehicule tr ON tr.id = vc.semiremorca_id
            WHERE v.status = 'activ'
              AND v.tip_vehicul IN ('camion', 'cap_tractor')
              AND NULLIF(TRIM(v.garaj), '') IS NOT NULL
            ORDER BY garaj ASC, categorie_vehicul ASC, capacitate_transport ASC
        ";

        $stmt = $this->db->query($sql);
        $rows = $stmt->fetchAll();

        $garageMap = [];
        $capacityMap = [];
        foreach ($rows as $row) {
            $garage = trim((string) ($row['garaj'] ?? ''));
            if ($garage !== '') {
                $garageMap[$this->normalizeGarageKey($garage)] = $garage;
            }

            $category = (string) ($row['categorie_vehicul'] ?? '');
            $capacity = $this->normalizeCapacityValue($row['capacitate_transport'] ?? null);
            if ($category !== '' && $capacity !== null) {
                $capacityMap[$category . ':' . $capacity] = [
                    'categorie_vehicul' => $category,
                    'capacitate_transport' => $capacity,
                ];
            }
        }

        return [
            'garages' => array_values($garageMap),
            'capacities' => array_values($capacityMap),
        ];
    }

    public function saveAvailabilityRule(array $data): int
    {
        $this->ensureAvailabilityRulesSchema();

        $garage = trim((string) ($data['garaj'] ?? ''));
        $category = (string) ($data['categorie_vehicul'] ?? '');
        $capacity = $this->normalizeCapacityValue($data['capacitate_transport'] ?? null);
        $minimum = max(1, (int) ($data['min_soferi_disponibili'] ?? 1));
        $active = !empty($data['activ']) ? 1 : 0;
        $now = date('Y-m-d H:i:s');

        $stmt = $this->db->prepare("
            INSERT INTO concedii_reguli_disponibilitate (
                garaj,
                categorie_vehicul,
                capacitate_transport,
                min_soferi_disponibili,
                activ,
                created_at,
                updated_at
            ) VALUES (
                :garaj,
                :categorie_vehicul,
                :capacitate_transport,
                :min_soferi_disponibili,
                :activ,
                :created_at,
                :updated_at
            )
        ");
        $stmt->bindValue(':garaj', $garage, PDO::PARAM_STR);
        $stmt->bindValue(':categorie_vehicul', $category, PDO::PARAM_STR);
        if ($capacity === null) {
            $stmt->bindValue(':capacitate_transport', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':capacitate_transport', $capacity, PDO::PARAM_STR);
        }
        $stmt->bindValue(':min_soferi_disponibili', $minimum, PDO::PARAM_INT);
        $stmt->bindValue(':activ', $active, PDO::PARAM_INT);
        $stmt->bindValue(':created_at', $now, PDO::PARAM_STR);
        $stmt->bindValue(':updated_at', $now, PDO::PARAM_STR);
        $stmt->execute();

        return (int) $this->db->lastInsertId();
    }

    public function deleteAvailabilityRule(int $id): bool
    {
        $this->ensureAvailabilityRulesSchema();

        $stmt = $this->db->prepare("
            DELETE FROM concedii_reguli_disponibilitate
            WHERE id = :id
        ");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function getAvailabilityRuleViolations(int $driverId, string $startDate, string $endDate, ?int $excludeRequestId = null): array
    {
        $this->ensureAvailabilityRulesSchema();
        $this->ensureDriverVehicleAssignmentsSchema();

        $contexts = $this->getDriverTransportContexts($driverId);
        if ($contexts === []) {
            return [];
        }

        $rules = $this->getActiveAvailabilityRules();
        if ($rules === []) {
            return [];
        }

        $matchedRules = [];
        foreach ($contexts as $context) {
            foreach ($rules as $rule) {
                if ($this->ruleMatchesContext($rule, $context)) {
                    $matchedRules[(int) $rule['id']] = $rule;
                }
            }
        }

        if ($matchedRules === []) {
            return [];
        }

        $rangeStart = new DateTimeImmutable($startDate);
        $rangeEnd = new DateTimeImmutable($endDate);
        $violations = [];

        foreach ($matchedRules as $rule) {
            $eligibleDriverIds = $this->getEligibleDriverIdsForRule($rule);
            if (!in_array($driverId, $eligibleDriverIds, true)) {
                continue;
            }

            $eligibleDriverMap = array_fill_keys($eligibleDriverIds, true);
            $approvedIntervals = $this->getApprovedIntervalsForDriverIds(
                $eligibleDriverIds,
                $startDate,
                $endDate,
                $excludeRequestId
            );
            $minimum = max(1, (int) ($rule['min_soferi_disponibili'] ?? 1));

            for ($cursor = $rangeStart; $cursor <= $rangeEnd; $cursor = $cursor->modify('+1 day')) {
                $day = $cursor->format('Y-m-d');
                $unavailableDriverMap = [$driverId => true];

                foreach ($approvedIntervals as $interval) {
                    $intervalDriverId = (int) ($interval['driver_id'] ?? 0);
                    if ($intervalDriverId <= 0 || !isset($eligibleDriverMap[$intervalDriverId])) {
                        continue;
                    }

                    $from = (string) ($interval['data_inceput'] ?? '');
                    $to = (string) ($interval['data_sfarsit'] ?? '');
                    if ($from !== '' && $to !== '' && $day >= $from && $day <= $to) {
                        $unavailableDriverMap[$intervalDriverId] = true;
                    }
                }

                $available = count($eligibleDriverMap) - count($unavailableDriverMap);
                if ($available < $minimum) {
                    $violations[] = [
                        'rule_id' => (int) ($rule['id'] ?? 0),
                        'date' => $day,
                        'garaj' => (string) ($rule['garaj'] ?? ''),
                        'categorie_vehicul' => (string) ($rule['categorie_vehicul'] ?? ''),
                        'capacitate_transport' => $this->normalizeCapacityValue($rule['capacitate_transport'] ?? null),
                        'min_soferi_disponibili' => $minimum,
                        'soferi_disponibili' => max(0, $available),
                        'soferi_eligibili' => count($eligibleDriverMap),
                    ];
                }
            }
        }

        return $violations;
    }

    public function logAudit(string $action, int $recordId, string $description, ?int $userId, ?array $beforeData, ?array $afterData): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO audit_log (
                modul,
                record_id,
                actiune,
                descriere,
                before_data,
                after_data,
                user_id,
                created_at
            ) VALUES (
                :modul,
                :record_id,
                :actiune,
                :descriere,
                :before_data,
                :after_data,
                :user_id,
                :created_at
            )
        ");

        $stmt->bindValue(':modul', 'concedii', PDO::PARAM_STR);
        $stmt->bindValue(':record_id', $recordId, PDO::PARAM_INT);
        $stmt->bindValue(':actiune', $action, PDO::PARAM_STR);
        $stmt->bindValue(':descriere', $description, PDO::PARAM_STR);
        $stmt->bindValue(
            ':before_data',
            $beforeData !== null ? json_encode($beforeData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            $beforeData !== null ? PDO::PARAM_STR : PDO::PARAM_NULL
        );
        $stmt->bindValue(
            ':after_data',
            $afterData !== null ? json_encode($afterData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            $afterData !== null ? PDO::PARAM_STR : PDO::PARAM_NULL
        );

        if ($userId !== null && $userId > 0) {
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        } else {
            $stmt->bindValue(':user_id', null, PDO::PARAM_NULL);
        }

        $stmt->bindValue(':created_at', date('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->execute();
    }

    private function ensureAvailabilityRulesSchema(): void
    {
        static $ensured = false;

        if ($ensured) {
            return;
        }

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS concedii_reguli_disponibilitate (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                garaj VARCHAR(120) NOT NULL,
                categorie_vehicul ENUM('camion', 'ansamblu') NOT NULL,
                capacitate_transport DECIMAL(10,2) NULL,
                min_soferi_disponibili SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                activ TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_concedii_reguli_lookup (activ, garaj, categorie_vehicul, capacitate_transport),
                INDEX idx_concedii_reguli_scope (garaj, categorie_vehicul)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $ensured = true;
    }

    private function getActiveAvailabilityRules(): array
    {
        $stmt = $this->db->query("
            SELECT *
            FROM concedii_reguli_disponibilitate
            WHERE activ = 1
            ORDER BY id ASC
        ");

        return $stmt->fetchAll();
    }

    private function getDriverTransportContexts(int $driverId): array
    {
        $capacityExpr = $this->transportCapacityExpression();
        $sql = "
            SELECT DISTINCT
                sv.driver_id,
                v.id AS vehicle_id,
                NULLIF(TRIM(v.garaj), '') AS garaj,
                CASE
                    WHEN v.tip_vehicul = 'camion' THEN 'camion'
                    WHEN v.tip_vehicul = 'cap_tractor' THEN 'ansamblu'
                    ELSE NULL
                END AS categorie_vehicul,
                {$capacityExpr} AS capacitate_transport
            FROM soferi_vehicule sv
            INNER JOIN soferi d ON d.id = sv.driver_id AND d.status = 'activ'
            INNER JOIN vehicule v ON v.id = sv.vehicle_id AND v.status = 'activ'
            " . $this->latestActiveCouplingJoin() . "
            LEFT JOIN vehicule tr ON tr.id = vc.semiremorca_id
            WHERE sv.driver_id = :driver_id
              AND v.tip_vehicul IN ('camion', 'cap_tractor')
              AND NULLIF(TRIM(v.garaj), '') IS NOT NULL
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':driver_id', $driverId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    private function getEligibleDriverIdsForRule(array $rule): array
    {
        $capacityExpr = $this->transportCapacityExpression();
        $vehicleType = (string) ($rule['categorie_vehicul'] ?? '') === 'ansamblu' ? 'cap_tractor' : 'camion';
        $garageKey = $this->normalizeGarageKey((string) ($rule['garaj'] ?? ''));
        $capacity = $this->normalizeCapacityValue($rule['capacitate_transport'] ?? null);

        $sql = "
            SELECT DISTINCT sv.driver_id
            FROM soferi_vehicule sv
            INNER JOIN soferi d ON d.id = sv.driver_id AND d.status = 'activ'
            INNER JOIN vehicule v ON v.id = sv.vehicle_id AND v.status = 'activ'
            " . $this->latestActiveCouplingJoin() . "
            LEFT JOIN vehicule tr ON tr.id = vc.semiremorca_id
            WHERE v.tip_vehicul = :vehicle_type
              AND UPPER(TRIM(COALESCE(v.garaj, ''))) = :garaj
        ";

        if ($capacity !== null) {
            $sql .= "
              AND {$capacityExpr} IS NOT NULL
              AND ABS(({$capacityExpr}) - :capacitate_transport) < 0.01
            ";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':vehicle_type', $vehicleType, PDO::PARAM_STR);
        $stmt->bindValue(':garaj', $garageKey, PDO::PARAM_STR);
        if ($capacity !== null) {
            $stmt->bindValue(':capacitate_transport', $capacity, PDO::PARAM_STR);
        }
        $stmt->execute();

        $ids = [];
        foreach ($stmt->fetchAll() as $row) {
            $id = (int) ($row['driver_id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    private function getApprovedIntervalsForDriverIds(array $driverIds, string $startDate, string $endDate, ?int $excludeRequestId): array
    {
        $driverIds = array_values(array_unique(array_filter(array_map('intval', $driverIds))));
        if ($driverIds === []) {
            return [];
        }

        $placeholders = [];
        $params = [
            ':start_date' => $startDate,
            ':end_date' => $endDate,
        ];
        foreach ($driverIds as $index => $driverId) {
            $placeholder = ':driver_id_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $driverId;
        }

        $sql = "
            SELECT driver_id, data_inceput, data_sfarsit
            FROM concedii
            WHERE status = 'aprobat'
              AND data_inceput <= :end_date
              AND data_sfarsit >= :start_date
              AND driver_id IN (" . implode(', ', $placeholders) . ")
        ";

        if ($excludeRequestId !== null && $excludeRequestId > 0) {
            $sql .= " AND id <> :exclude_id";
            $params[':exclude_id'] = $excludeRequestId;
        }

        $stmt = $this->db->prepare($sql);
        $this->bindParams($stmt, $params);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    private function ruleMatchesContext(array $rule, array $context): bool
    {
        if ((string) ($rule['categorie_vehicul'] ?? '') !== (string) ($context['categorie_vehicul'] ?? '')) {
            return false;
        }

        if ($this->normalizeGarageKey((string) ($rule['garaj'] ?? '')) !== $this->normalizeGarageKey((string) ($context['garaj'] ?? ''))) {
            return false;
        }

        $ruleCapacity = $this->normalizeCapacityValue($rule['capacitate_transport'] ?? null);
        if ($ruleCapacity === null) {
            return true;
        }

        return $ruleCapacity === $this->normalizeCapacityValue($context['capacitate_transport'] ?? null);
    }

    private function normalizeGarageKey(string $garage): string
    {
        return strtoupper(trim($garage));
    }

    private function normalizeCapacityValue(mixed $capacity): ?string
    {
        if ($capacity === null) {
            return null;
        }

        $raw = str_replace(',', '.', trim((string) $capacity));
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }

        $value = (float) $raw;
        if ($value <= 0) {
            return null;
        }

        return number_format($value, 2, '.', '');
    }

    private function transportCapacityExpression(): string
    {
        return "CASE
                    WHEN v.tip_vehicul = 'cap_tractor' THEN COALESCE(tr.capacitate_transport, v.capacitate_transport)
                    ELSE v.capacitate_transport
                END";
    }

    private function latestActiveCouplingJoin(): string
    {
        return "
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
        ";
    }

    private function buildRequestWhere(array $filters, string $search): array
    {
        $conditions = [];
        $params = [];

        if ($search !== '') {
            $conditions[] = "(
                d.nume LIKE :search
                OR COALESCE(v.nr_inmatriculare, '') LIKE :search
                OR COALESCE(r.nume, '') LIKE :search
                OR COALESCE(c.note, '') LIKE :search
            )";
            $params[':search'] = '%' . $search . '%';
        }

        if (($filters['status'] ?? '') !== '') {
            $conditions[] = 'c.status = :status';
            $params[':status'] = (string) $filters['status'];
        }

        if (($filters['tip'] ?? '') !== '') {
            $conditions[] = 'c.tip_concediu = :tip_concediu';
            $params[':tip_concediu'] = (string) $filters['tip'];
        }

        if (($filters['only_pending'] ?? false) === true) {
            $conditions[] = "c.status IN ('in_asteptare', 'in_asteptare_aprobare')";
        }

        if (($filters['created_by'] ?? null) !== null) {
            $conditions[] = 'c.created_by = :created_by';
            $params[':created_by'] = (int) $filters['created_by'];
        }

        if (($filters['date_from'] ?? '') !== '') {
            $conditions[] = 'c.data_inceput >= :date_from';
            $params[':date_from'] = (string) $filters['date_from'];
        }

        if (($filters['date_to'] ?? '') !== '') {
            $conditions[] = 'c.data_sfarsit <= :date_to';
            $params[':date_to'] = (string) $filters['date_to'];
        }

        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

        return [
            'where' => $where,
            'params' => $params,
        ];
    }

    private function bindParams(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $placeholder => $value) {
            if (is_int($value)) {
                $stmt->bindValue($placeholder, $value, PDO::PARAM_INT);
                continue;
            }

            if ($value === null) {
                $stmt->bindValue($placeholder, null, PDO::PARAM_NULL);
                continue;
            }

            $stmt->bindValue($placeholder, (string) $value, PDO::PARAM_STR);
        }
    }

    private function bindMutationValues(PDOStatement $stmt, array $data): void
    {
        $stmt->bindValue(':driver_id', (int) $data['driver_id'], PDO::PARAM_INT);
        $stmt->bindValue(':tip_concediu', (string) $data['tip_concediu'], PDO::PARAM_STR);
        $stmt->bindValue(':data_inceput', (string) $data['data_inceput'], PDO::PARAM_STR);
        $stmt->bindValue(':data_sfarsit', (string) $data['data_sfarsit'], PDO::PARAM_STR);

        $inlocuitorId = $data['inlocuitor_id'] ?? null;
        if ($inlocuitorId === null || (int) $inlocuitorId <= 0) {
            $stmt->bindValue(':inlocuitor_id', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':inlocuitor_id', (int) $inlocuitorId, PDO::PARAM_INT);
        }

        $note = trim((string) ($data['note'] ?? ''));
        if ($note === '') {
            $stmt->bindValue(':note', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':note', $note, PDO::PARAM_STR);
        }

        $stmt->bindValue(':status', (string) $data['status'], PDO::PARAM_STR);

        if (array_key_exists('created_by', $data)) {
            $createdBy = $data['created_by'];
            if ($createdBy === null || (int) $createdBy <= 0) {
                $stmt->bindValue(':created_by', null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue(':created_by', (int) $createdBy, PDO::PARAM_INT);
            }
        }

        if (array_key_exists('created_at', $data)) {
            $stmt->bindValue(':created_at', (string) $data['created_at'], PDO::PARAM_STR);
        }

        $stmt->bindValue(':updated_at', (string) $data['updated_at'], PDO::PARAM_STR);
    }
}
