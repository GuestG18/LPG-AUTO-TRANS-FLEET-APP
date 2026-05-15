<?php
declare(strict_types=1);

class ProgramareConcediiModel extends BaseModel
{
    public function getActiveDrivers(): array
    {
        $sql = "
            SELECT
                s.id,
                s.nume,
                s.telefon,
                s.vehicle_id,
                v.nr_inmatriculare,
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
            LEFT JOIN vehicule v ON v.id = s.vehicle_id
            WHERE s.status = 'activ'
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
