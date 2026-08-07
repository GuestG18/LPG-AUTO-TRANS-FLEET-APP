<?php
declare(strict_types=1);

class InactiveResourceApprovalModel extends BaseModel
{
    private const REVIEW_STATUSES = ['approved', 'rejected'];

    public function ensureSchema(): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS inactive_resource_approvals (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                resource_type ENUM('vehicle', 'driver') NOT NULL,
                resource_id INT UNSIGNED NOT NULL,
                trip_id INT UNSIGNED NULL,
                usage_context VARCHAR(120) NOT NULL DEFAULT 'dispecer_curse',
                resource_label VARCHAR(190) NOT NULL,
                inactive_reason VARCHAR(80) NOT NULL,
                inactive_reason_label VARCHAR(160) NOT NULL,
                inactive_since DATE NULL,
                status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
                requested_by_user_id INT UNSIGNED NULL,
                requested_at DATETIME NOT NULL,
                reviewed_by_user_id INT UNSIGNED NULL,
                reviewed_at DATETIME NULL,
                review_note TEXT NULL,
                snapshot_json LONGTEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_inactive_approvals_pending (status, resource_type, requested_at),
                INDEX idx_inactive_approvals_resource_trip (resource_type, resource_id, trip_id),
                INDEX idx_inactive_approvals_trip (trip_id),
                INDEX idx_inactive_approvals_requested_by (requested_by_user_id),
                INDEX idx_inactive_approvals_reviewed_by (reviewed_by_user_id),
                CONSTRAINT fk_inactive_approvals_trip FOREIGN KEY (trip_id) REFERENCES curse_dispecer(id) ON DELETE SET NULL,
                CONSTRAINT fk_inactive_approvals_requested_by FOREIGN KEY (requested_by_user_id) REFERENCES utilizatori(id) ON DELETE SET NULL,
                CONSTRAINT fk_inactive_approvals_reviewed_by FOREIGN KEY (reviewed_by_user_id) REFERENCES utilizatori(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS inactive_resource_approval_documents (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                approval_id BIGINT UNSIGNED NOT NULL,
                document_type VARCHAR(120) NOT NULL,
                document_id INT UNSIGNED NULL,
                document_name VARCHAR(160) NOT NULL,
                document_status ENUM('missing', 'expired') NOT NULL,
                expiry_date DATE NULL,
                source_table VARCHAR(80) NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_inactive_approval_documents_approval (approval_id),
                INDEX idx_inactive_approval_documents_status (document_status),
                CONSTRAINT fk_inactive_approval_documents_approval FOREIGN KEY (approval_id) REFERENCES inactive_resource_approvals(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $ensured = true;
    }

    public function getPendingSummary(int $limitPerType = 3): array
    {
        $this->ensureSchema();

        $counts = ['vehicle' => 0, 'driver' => 0];
        $stmt = $this->db->query("
            SELECT resource_type, COUNT(*) AS total
            FROM inactive_resource_approvals
            WHERE status = 'pending'
            GROUP BY resource_type
        ");

        foreach ($stmt->fetchAll() as $row) {
            $type = (string) ($row['resource_type'] ?? '');
            if (isset($counts[$type])) {
                $counts[$type] = (int) ($row['total'] ?? 0);
            }
        }

        return [
            'counts' => $counts,
            'total' => $counts['vehicle'] + $counts['driver'],
            'vehicles' => $this->getPendingRowsByType('vehicle', $limitPerType),
            'drivers' => $this->getPendingRowsByType('driver', $limitPerType),
        ];
    }

    public function getPendingRowsByType(string $resourceType, int $limit = 3): array
    {
        $this->ensureSchema();
        $resourceType = $this->normalizeResourceType($resourceType);
        if ($resourceType === '') {
            return [];
        }

        $stmt = $this->db->prepare($this->baseSelectSql() . "
            WHERE a.status = 'pending'
              AND a.resource_type = :resource_type
            ORDER BY a.requested_at DESC, a.id DESC
            LIMIT :limit_rows
        ");
        $stmt->bindValue(':resource_type', $resourceType);
        $stmt->bindValue(':limit_rows', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return $this->hydrateDocuments($stmt->fetchAll());
    }

    public function search(array $filters, int $page = 1, int $perPage = 20): array
    {
        $this->ensureSchema();

        $where = [];
        $params = [];

        $status = $this->normalizeStatus((string) ($filters['status'] ?? 'pending'), true);
        if ($status !== '') {
            $where[] = 'a.status = :status';
            $params[':status'] = $status;
        }

        $resourceType = $this->normalizeResourceType((string) ($filters['resource_type'] ?? ''));
        if ($resourceType !== '') {
            $where[] = 'a.resource_type = :resource_type';
            $params[':resource_type'] = $resourceType;
        }

        $reason = trim((string) ($filters['reason'] ?? ''));
        if ($reason !== '') {
            $where[] = 'a.inactive_reason = :reason';
            $params[':reason'] = $reason;
        }

        $dateStart = $this->normalizeDate($filters['date_start'] ?? null);
        if ($dateStart !== null) {
            $where[] = 'DATE(a.requested_at) >= :date_start';
            $params[':date_start'] = $dateStart;
        }

        $dateEnd = $this->normalizeDate($filters['date_end'] ?? null);
        if ($dateEnd !== null) {
            $where[] = 'DATE(a.requested_at) <= :date_end';
            $params[':date_end'] = $dateEnd;
        }

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $where[] = "(a.resource_label LIKE :q OR CAST(a.trip_id AS CHAR) LIKE :q OR a.inactive_reason_label LIKE :q)";
            $params[':q'] = '%' . $search . '%';
        }

        $whereSql = $where !== [] ? ('WHERE ' . implode(' AND ', $where)) : '';
        $page = max(1, $page);
        $perPage = max(5, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM inactive_resource_approvals a {$whereSql}");
        $this->bindParams($countStmt, $params);
        $countStmt->execute();
        $totalRows = (int) $countStmt->fetchColumn();

        $stmt = $this->db->prepare($this->baseSelectSql() . "
            {$whereSql}
            ORDER BY a.requested_at DESC, a.id DESC
            LIMIT :limit_rows OFFSET :offset_rows
        ");
        $this->bindParams($stmt, $params);
        $stmt->bindValue(':limit_rows', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset_rows', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'rows' => $this->hydrateDocuments($stmt->fetchAll()),
            'page' => $page,
            'per_page' => $perPage,
            'total_rows' => $totalRows,
            'total_pages' => max(1, (int) ceil($totalRows / $perPage)),
        ];
    }

    public function getById(int $id): ?array
    {
        $this->ensureSchema();
        if ($id <= 0) {
            return null;
        }

        $stmt = $this->db->prepare($this->baseSelectSql() . " WHERE a.id = :id LIMIT 1");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $this->hydrateDocuments($stmt->fetchAll());

        return $rows[0] ?? null;
    }

    public function approve(int $id, ?int $userId, string $note = ''): bool
    {
        return $this->review($id, 'approved', $userId, $note);
    }

    public function reject(int $id, ?int $userId, string $note = ''): bool
    {
        return $this->review($id, 'rejected', $userId, $note);
    }

    public function review(int $id, string $status, ?int $userId, string $note = ''): bool
    {
        $this->ensureSchema();
        if ($id <= 0 || !in_array($status, self::REVIEW_STATUSES, true)) {
            return false;
        }

        $stmt = $this->db->prepare("
            UPDATE inactive_resource_approvals
            SET status = :status,
                reviewed_by_user_id = :reviewed_by_user_id,
                reviewed_at = :reviewed_at,
                review_note = :review_note,
                updated_at = :updated_at
            WHERE id = :id
              AND status = 'pending'
        ");
        $now = date('Y-m-d H:i:s');
        $stmt->bindValue(':status', $status);
        $this->bindNullableInt($stmt, ':reviewed_by_user_id', $userId);
        $stmt->bindValue(':reviewed_at', $now);
        $this->bindNullableString($stmt, ':review_note', trim($note) !== '' ? trim($note) : null);
        $stmt->bindValue(':updated_at', $now);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    public function getExistingOpenStatusForResourceTrip(string $resourceType, int $resourceId, ?int $tripId): ?string
    {
        $this->ensureSchema();
        $row = $this->findExistingForResourceTrip($resourceType, $resourceId, $tripId, ['pending', 'approved']);

        return $row !== null ? (string) ($row['status'] ?? '') : null;
    }

    public function createForInactiveResources(array $resources, int $tripId, string $decision, ?int $userId): array
    {
        $createdIds = [];
        $status = $decision === 'approved' ? 'approved' : 'pending';

        foreach ($resources as $resource) {
            if (empty($resource['is_inactive'])) {
                continue;
            }

            $createdIds[] = $this->createOrReuseFromSnapshot($resource, $tripId, $status, $userId);
        }

        return array_values(array_filter($createdIds, static fn(int $id): bool => $id > 0));
    }

    public function createOrReuseFromSnapshot(array $resource, ?int $tripId, string $status, ?int $userId): int
    {
        $this->ensureSchema();

        $resourceType = $this->normalizeResourceType((string) ($resource['resource_type'] ?? ''));
        $resourceId = (int) ($resource['resource_id'] ?? 0);
        $status = $status === 'approved' ? 'approved' : 'pending';

        if ($resourceType === '' || $resourceId <= 0) {
            return 0;
        }

        $existing = $this->findExistingForResourceTrip($resourceType, $resourceId, $tripId, ['pending', 'approved']);
        if ($existing !== null) {
            $existingId = (int) ($existing['id'] ?? 0);
            if ((string) ($existing['status'] ?? '') === 'pending' && $status === 'approved') {
                $this->approve($existingId, $userId, 'Aprobat imediat din Dispecer curse.');
            }

            return $existingId;
        }

        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare("
            INSERT INTO inactive_resource_approvals (
                resource_type,
                resource_id,
                trip_id,
                usage_context,
                resource_label,
                inactive_reason,
                inactive_reason_label,
                inactive_since,
                status,
                requested_by_user_id,
                requested_at,
                reviewed_by_user_id,
                reviewed_at,
                review_note,
                snapshot_json,
                created_at,
                updated_at
            ) VALUES (
                :resource_type,
                :resource_id,
                :trip_id,
                :usage_context,
                :resource_label,
                :inactive_reason,
                :inactive_reason_label,
                :inactive_since,
                :status,
                :requested_by_user_id,
                :requested_at,
                :reviewed_by_user_id,
                :reviewed_at,
                :review_note,
                :snapshot_json,
                :created_at,
                :updated_at
            )
        ");

        $stmt->bindValue(':resource_type', $resourceType);
        $stmt->bindValue(':resource_id', $resourceId, PDO::PARAM_INT);
        $this->bindNullableInt($stmt, ':trip_id', $tripId);
        $stmt->bindValue(':usage_context', (string) ($resource['usage_context'] ?? 'Dispecer curse'));
        $stmt->bindValue(':resource_label', $this->limitString((string) ($resource['resource_label'] ?? ''), 190));
        $stmt->bindValue(':inactive_reason', $this->limitString((string) ($resource['reason_key'] ?? 'other'), 80));
        $stmt->bindValue(':inactive_reason_label', $this->limitString((string) ($resource['reason_label'] ?? 'Alt motiv'), 160));
        $this->bindNullableString($stmt, ':inactive_since', $this->normalizeDate($resource['inactive_since'] ?? null));
        $stmt->bindValue(':status', $status);
        $this->bindNullableInt($stmt, ':requested_by_user_id', $userId);
        $stmt->bindValue(':requested_at', $now);
        $this->bindNullableInt($stmt, ':reviewed_by_user_id', $status === 'approved' ? $userId : null);
        $this->bindNullableString($stmt, ':reviewed_at', $status === 'approved' ? $now : null);
        $this->bindNullableString($stmt, ':review_note', $status === 'approved' ? 'Aprobat imediat din Dispecer curse.' : null);
        $this->bindNullableString($stmt, ':snapshot_json', $this->encodeSnapshot($resource));
        $stmt->bindValue(':created_at', $now);
        $stmt->bindValue(':updated_at', $now);
        $stmt->execute();

        $approvalId = (int) $this->db->lastInsertId();
        $this->insertDocumentSnapshots($approvalId, is_array($resource['documents'] ?? null) ? $resource['documents'] : []);

        return $approvalId;
    }

    public function rejectPendingNoLongerUsed(int $tripId, array $currentResources, ?int $userId): void
    {
        $this->ensureSchema();
        if ($tripId <= 0) {
            return;
        }

        $currentKeys = [];
        foreach ($currentResources as $resource) {
            $type = $this->normalizeResourceType((string) ($resource['resource_type'] ?? ''));
            $id = (int) ($resource['resource_id'] ?? 0);
            if ($type !== '' && $id > 0) {
                $currentKeys[$type . ':' . $id] = true;
            }
        }

        $stmt = $this->db->prepare("
            SELECT id, resource_type, resource_id
            FROM inactive_resource_approvals
            WHERE trip_id = :trip_id
              AND status = 'pending'
        ");
        $stmt->bindValue(':trip_id', $tripId, PDO::PARAM_INT);
        $stmt->execute();

        foreach ($stmt->fetchAll() as $row) {
            $key = (string) ($row['resource_type'] ?? '') . ':' . (string) ((int) ($row['resource_id'] ?? 0));
            if (!isset($currentKeys[$key])) {
                $this->reject((int) ($row['id'] ?? 0), $userId, 'Resursa nu mai este asociata cursei.');
            }
        }
    }

    public function getReasonOptions(): array
    {
        return [
            'expired_documents' => 'Documente expirate',
            'missing_documents' => 'Documente lipsa',
            'documents_mixed' => 'Documente lipsa & expirate',
            'repair' => 'In reparatie',
            'manual_inactive' => 'Dezactivat manual / Inactiv',
            'medical_leave' => 'Concediu medical',
            'leave' => 'Concediu',
            'other' => 'Alt motiv',
        ];
    }

    private function baseSelectSql(): string
    {
        return "
            SELECT
                a.*,
                requester.nume AS requested_by_name,
                reviewer.nume AS reviewed_by_name,
                c.data_cursa AS trip_date,
                v.nr_inmatriculare AS current_vehicle_label,
                s.nume AS current_driver_label
            FROM inactive_resource_approvals a
            LEFT JOIN utilizatori requester ON requester.id = a.requested_by_user_id
            LEFT JOIN utilizatori reviewer ON reviewer.id = a.reviewed_by_user_id
            LEFT JOIN curse_dispecer c ON c.id = a.trip_id
            LEFT JOIN vehicule v ON v.id = a.resource_id AND a.resource_type = 'vehicle'
            LEFT JOIN soferi s ON s.id = a.resource_id AND a.resource_type = 'driver'
        ";
    }

    private function findExistingForResourceTrip(string $resourceType, int $resourceId, ?int $tripId, array $statuses): ?array
    {
        $resourceType = $this->normalizeResourceType($resourceType);
        if ($resourceType === '' || $resourceId <= 0 || $statuses === []) {
            return null;
        }

        $params = [
            ':resource_type' => $resourceType,
            ':resource_id' => $resourceId,
        ];
        $statusPlaceholders = [];
        foreach (array_values($statuses) as $index => $status) {
            $placeholder = ':status_' . $index;
            $statusPlaceholders[] = $placeholder;
            $params[$placeholder] = $status;
        }

        $tripCondition = 'trip_id IS NULL';
        if ($tripId !== null && $tripId > 0) {
            $tripCondition = 'trip_id = :trip_id';
            $params[':trip_id'] = $tripId;
        }

        $stmt = $this->db->prepare("
            SELECT id, status
            FROM inactive_resource_approvals
            WHERE resource_type = :resource_type
              AND resource_id = :resource_id
              AND {$tripCondition}
              AND status IN (" . implode(', ', $statusPlaceholders) . ")
            ORDER BY FIELD(status, 'pending', 'approved'), id DESC
            LIMIT 1
        ");
        $this->bindParams($stmt, $params);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row ?: null;
    }

    private function insertDocumentSnapshots(int $approvalId, array $documents): void
    {
        if ($approvalId <= 0 || $documents === []) {
            return;
        }

        $stmt = $this->db->prepare("
            INSERT INTO inactive_resource_approval_documents (
                approval_id,
                document_type,
                document_id,
                document_name,
                document_status,
                expiry_date,
                source_table,
                created_at
            ) VALUES (
                :approval_id,
                :document_type,
                :document_id,
                :document_name,
                :document_status,
                :expiry_date,
                :source_table,
                :created_at
            )
        ");

        $now = date('Y-m-d H:i:s');
        foreach ($documents as $document) {
            $documentType = trim((string) ($document['document_type'] ?? $document['document_name'] ?? ''));
            if ($documentType === '') {
                continue;
            }

            $status = (string) ($document['document_status'] ?? '');
            if (!in_array($status, ['missing', 'expired'], true)) {
                continue;
            }

            $stmt->bindValue(':approval_id', $approvalId, PDO::PARAM_INT);
            $stmt->bindValue(':document_type', $this->limitString($documentType, 120));
            $this->bindNullableInt($stmt, ':document_id', isset($document['document_id']) ? (int) $document['document_id'] : null);
            $stmt->bindValue(':document_name', $this->limitString((string) ($document['document_name'] ?? $documentType), 160));
            $stmt->bindValue(':document_status', $status);
            $this->bindNullableString($stmt, ':expiry_date', $this->normalizeDate($document['expiry_date'] ?? null));
            $this->bindNullableString($stmt, ':source_table', $this->limitString((string) ($document['source_table'] ?? ''), 80));
            $stmt->bindValue(':created_at', $now);
            $stmt->execute();
        }
    }

    private function hydrateDocuments(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        if ($ids === []) {
            return $rows;
        }

        $params = [];
        $placeholders = [];
        foreach (array_values($ids) as $index => $id) {
            $placeholder = ':approval_id_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $id;
        }

        $stmt = $this->db->prepare("
            SELECT *
            FROM inactive_resource_approval_documents
            WHERE approval_id IN (" . implode(', ', $placeholders) . ")
            ORDER BY document_status ASC, document_name ASC, id ASC
        ");
        $this->bindParams($stmt, $params);
        $stmt->execute();

        $documentsByApproval = [];
        foreach ($stmt->fetchAll() as $document) {
            $approvalId = (int) ($document['approval_id'] ?? 0);
            if ($approvalId <= 0) {
                continue;
            }
            $documentsByApproval[$approvalId][] = $document;
        }

        foreach ($rows as &$row) {
            $id = (int) ($row['id'] ?? 0);
            $row['documents'] = $documentsByApproval[$id] ?? [];
            $row['affected_document_names'] = $this->affectedDocumentNames($row['documents']);
        }
        unset($row);

        return $rows;
    }

    private function affectedDocumentNames(array $documents): array
    {
        $names = [];
        foreach ($documents as $document) {
            $name = trim((string) ($document['document_name'] ?? ''));
            if ($name !== '') {
                $names[$name] = $name;
            }
        }

        return array_values($names);
    }

    private function normalizeResourceType(string $resourceType): string
    {
        $resourceType = strtolower(trim($resourceType));
        return in_array($resourceType, ['vehicle', 'driver'], true) ? $resourceType : '';
    }

    private function normalizeStatus(string $status, bool $allowAll = false): string
    {
        $status = strtolower(trim($status));
        if ($allowAll && ($status === '' || $status === 'all')) {
            return '';
        }

        return in_array($status, ['pending', 'approved', 'rejected'], true) ? $status : 'pending';
    }

    private function normalizeDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '' || $raw === '0000-00-00' || $raw === '0000-00-00 00:00:00') {
            return null;
        }

        $candidate = substr($raw, 0, 10);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $candidate) ? $candidate : null;
    }

    private function encodeSnapshot(array $resource): ?string
    {
        $encoded = json_encode($resource, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : null;
    }

    private function limitString(string $value, int $limit): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return mb_strlen($value, 'UTF-8') > $limit ? mb_substr($value, 0, $limit, 'UTF-8') : $value;
    }

    private function bindParams(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $placeholder => $value) {
            if (is_int($value)) {
                $stmt->bindValue($placeholder, $value, PDO::PARAM_INT);
            } elseif ($value === null) {
                $stmt->bindValue($placeholder, null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue($placeholder, (string) $value);
            }
        }
    }

    private function bindNullableInt(PDOStatement $stmt, string $placeholder, ?int $value): void
    {
        if ($value === null || $value <= 0) {
            $stmt->bindValue($placeholder, null, PDO::PARAM_NULL);
            return;
        }

        $stmt->bindValue($placeholder, $value, PDO::PARAM_INT);
    }

    private function bindNullableString(PDOStatement $stmt, string $placeholder, ?string $value): void
    {
        if ($value === null || trim($value) === '') {
            $stmt->bindValue($placeholder, null, PDO::PARAM_NULL);
            return;
        }

        $stmt->bindValue($placeholder, $value);
    }
}
