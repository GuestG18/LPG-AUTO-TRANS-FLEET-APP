<?php
declare(strict_types=1);

class InactiveResourceApprovalModel extends BaseModel
{
    private const RESOURCE_TYPES = ['vehicle', 'driver', 'repair'];
    private const REVIEW_STATUSES = ['approved', 'rejected'];
    private const TRANSPORT_TYPE_LABELS = [
        'primar' => 'Primar km',
        'primar_tona' => 'Primar tone',
        'distributie' => 'Distributie',
        'primar_distributie' => 'Primar+Distributie',
        'compresor' => 'Compresor',
    ];

    public function ensureSchema(): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS inactive_resource_approvals (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                resource_type ENUM('vehicle', 'driver', 'repair') NOT NULL,
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

        $this->ensureRepairResourceTypeColumn();
        $this->migrateRepairCategoryRows();

        $ensured = true;
    }

    public function getPendingSummary(int $limitPerType = 3): array
    {
        $this->ensureSchema();

        $counts = ['vehicle' => 0, 'driver' => 0, 'repair' => 0];
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
            'total' => $counts['vehicle'] + $counts['driver'] + $counts['repair'],
            'vehicles' => $this->getPendingRowsByType('vehicle', $limitPerType),
            'drivers' => $this->getPendingRowsByType('driver', $limitPerType),
            'repairs' => $this->getPendingRowsByType('repair', $limitPerType),
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

        $requestedByUserId = (int) ($filters['requested_by_user_id'] ?? 0);
        if ($requestedByUserId > 0) {
            $where[] = 'a.requested_by_user_id = :requested_by_user_id';
            $params[':requested_by_user_id'] = $requestedByUserId;
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

    public function getRequesterSummary(int $userId, int $limitPerStatus = 20): array
    {
        $this->ensureSchema();

        $counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
        if ($userId <= 0) {
            return [
                'counts' => $counts,
                'total' => 0,
                'pending' => [],
                'approved' => [],
                'rejected' => [],
            ];
        }

        $stmt = $this->db->prepare("
            SELECT status, COUNT(*) AS total
            FROM inactive_resource_approvals
            WHERE requested_by_user_id = :requested_by_user_id
            GROUP BY status
        ");
        $stmt->bindValue(':requested_by_user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();

        foreach ($stmt->fetchAll() as $row) {
            $status = (string) ($row['status'] ?? '');
            if (isset($counts[$status])) {
                $counts[$status] = (int) ($row['total'] ?? 0);
            }
        }

        return [
            'counts' => $counts,
            'total' => $counts['pending'] + $counts['approved'] + $counts['rejected'],
            'pending' => $this->getRequesterRowsByStatus($userId, 'pending', $limitPerStatus),
            'approved' => $this->getRequesterRowsByStatus($userId, 'approved', $limitPerStatus),
            'rejected' => $this->getRequesterRowsByStatus($userId, 'rejected', $limitPerStatus),
        ];
    }

    public function getRequesterRowsByStatus(int $userId, string $status, int $limit = 20): array
    {
        $this->ensureSchema();
        if ($userId <= 0) {
            return [];
        }

        $status = $this->normalizeStatus($status);
        $stmt = $this->db->prepare($this->baseSelectSql() . "
            WHERE a.requested_by_user_id = :requested_by_user_id
              AND a.status = :status
            ORDER BY a.requested_at DESC, a.id DESC
            LIMIT :limit_rows
        ");
        $stmt->bindValue(':requested_by_user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':status', $status);
        $stmt->bindValue(':limit_rows', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return $this->hydrateDocuments($stmt->fetchAll());
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

    /**
     * Readuce o cerere deja decisa in starea 'pending'.
     *
     * Decizia anterioara se sterge complet (cine si cand a decis), pentru ca
     * altfel cererea ar arata ca fiind in asteptare dar cu un aprobator trecut
     * in dreptul ei. Motivul repunerii ramane in review_note ca urma.
     */
    public function reopen(int $id, ?int $userId, string $note = ''): bool
    {
        $this->ensureSchema();
        if ($id <= 0) {
            return false;
        }

        $trace = trim($note);
        if ($trace === '') {
            $trace = 'Repusa in asteptare.';
        }
        if ($userId !== null && $userId > 0) {
            $trace .= ' (repusa de utilizator #' . $userId . ' la ' . date('d.m.Y H:i') . ')';
        }

        $stmt = $this->db->prepare("
            UPDATE inactive_resource_approvals
            SET status = 'pending',
                reviewed_by_user_id = NULL,
                reviewed_at = NULL,
                review_note = :review_note,
                updated_at = :updated_at
            WHERE id = :id
              AND status IN ('approved', 'rejected')
        ");
        $stmt->bindValue(':review_note', $trace);
        $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'));
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

    public function getPendingForRequesterResourceContext(int $userId, string $resourceType, int $resourceId, string $usageContext = 'Dispecer curse'): ?array
    {
        $this->ensureSchema();
        $resourceType = $this->normalizeResourceType($resourceType);
        if ($userId <= 0 || $resourceType === '' || $resourceId <= 0) {
            return null;
        }

        $stmt = $this->db->prepare($this->baseSelectSql() . "
            WHERE a.status = 'pending'
              AND a.requested_by_user_id = :requested_by_user_id
              AND a.resource_type = :resource_type
              AND a.resource_id = :resource_id
              AND LOWER(REPLACE(a.usage_context, ' ', '_')) = LOWER(REPLACE(:usage_context, ' ', '_'))
            ORDER BY a.requested_at DESC, a.id DESC
            LIMIT 1
        ");
        $stmt->bindValue(':requested_by_user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':resource_type', $resourceType);
        $stmt->bindValue(':resource_id', $resourceId, PDO::PARAM_INT);
        $stmt->bindValue(':usage_context', trim($usageContext) !== '' ? trim($usageContext) : 'Dispecer curse');
        $stmt->execute();

        $rows = $this->hydrateDocuments($stmt->fetchAll());

        return $rows[0] ?? null;
    }

    public function createPendingForRequesterResource(array $resource, int $userId): ?array
    {
        $this->ensureSchema();

        $resourceType = $this->approvalResourceTypeForSnapshot($resource);
        $resourceId = (int) ($resource['resource_id'] ?? 0);
        $usageContext = (string) ($resource['usage_context'] ?? 'Dispecer curse');
        if ($userId <= 0 || $resourceType === '' || $resourceId <= 0 || empty($resource['is_inactive'])) {
            return null;
        }

        $existing = $this->getPendingForRequesterResourceContext($userId, $resourceType, $resourceId, $usageContext);
        if ($existing !== null) {
            return $existing;
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
                NULL,
                :usage_context,
                :resource_label,
                :inactive_reason,
                :inactive_reason_label,
                :inactive_since,
                'pending',
                :requested_by_user_id,
                :requested_at,
                NULL,
                NULL,
                NULL,
                :snapshot_json,
                :created_at,
                :updated_at
            )
        ");

        $snapshot = $resource;
        $snapshot['approval_resource_type'] = $resourceType;

        $stmt->bindValue(':resource_type', $resourceType);
        $stmt->bindValue(':resource_id', $resourceId, PDO::PARAM_INT);
        $stmt->bindValue(':usage_context', trim($usageContext) !== '' ? trim($usageContext) : 'Dispecer curse');
        $stmt->bindValue(':resource_label', $this->limitString((string) ($resource['resource_label'] ?? ''), 190));
        $stmt->bindValue(':inactive_reason', $this->limitString((string) ($resource['reason_key'] ?? 'other'), 80));
        $stmt->bindValue(':inactive_reason_label', $this->limitString((string) ($resource['reason_label'] ?? 'Alt motiv'), 160));
        $this->bindNullableString($stmt, ':inactive_since', $this->normalizeDate($resource['inactive_since'] ?? null));
        $stmt->bindValue(':requested_by_user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':requested_at', $now);
        $this->bindNullableString($stmt, ':snapshot_json', $this->encodeSnapshot($snapshot));
        $stmt->bindValue(':created_at', $now);
        $stmt->bindValue(':updated_at', $now);
        $stmt->execute();

        $approvalId = (int) $this->db->lastInsertId();
        $this->insertDocumentSnapshots($approvalId, is_array($resource['documents'] ?? null) ? $resource['documents'] : []);

        return $this->getById($approvalId);
    }

    public function cancelPendingByRequester(int $approvalId, int $userId): bool
    {
        $this->ensureSchema();
        if ($approvalId <= 0 || $userId <= 0) {
            return false;
        }

        $stmt = $this->db->prepare("
            DELETE FROM inactive_resource_approvals
            WHERE id = :id
              AND requested_by_user_id = :requested_by_user_id
              AND status = 'pending'
        ");
        $stmt->bindValue(':id', $approvalId, PDO::PARAM_INT);
        $stmt->bindValue(':requested_by_user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0;
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

        $resourceType = $this->approvalResourceTypeForSnapshot($resource);
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

        $snapshot = $resource;
        $snapshot['approval_resource_type'] = $resourceType;

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
        $this->bindNullableString($stmt, ':snapshot_json', $this->encodeSnapshot($snapshot));
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
            $type = $this->approvalResourceTypeForSnapshot($resource);
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
                c.data_incarcare AS trip_loading_date,
                c.data_inceput AS trip_start_date,
                c.data_sfarsit AS trip_end_date,
                c.tip_transport AS trip_transport_type,
                c.vehicle_id AS trip_vehicle_id,
                c.driver_id AS trip_driver_id,
                c.beneficiar_id AS trip_beneficiary_id,
                c.loc_incarcare_id AS trip_load_location_id,
                c.zona_distributie_id AS trip_unload_zone_id,
                c.loc_plecare AS trip_departure_text,
                c.loc_aspirare AS trip_suction_text,
                c.loc_livrare AS trip_delivery_text,
                c.loc_livrare_cursa AS trip_closing_location_text,
                current_vehicle.nr_inmatriculare AS current_vehicle_label,
                current_driver.nume AS current_driver_label,
                trip_vehicle.nr_inmatriculare AS trip_vehicle_label,
                trip_driver.nume AS trip_driver_label,
                beneficiary.nume AS trip_beneficiary_name,
                load_location.nume AS trip_load_location_name,
                unload_zone.nume AS trip_unload_zone_name
            FROM inactive_resource_approvals a
            LEFT JOIN utilizatori requester ON requester.id = a.requested_by_user_id
            LEFT JOIN utilizatori reviewer ON reviewer.id = a.reviewed_by_user_id
            LEFT JOIN curse_dispecer c ON c.id = a.trip_id
            LEFT JOIN vehicule current_vehicle ON current_vehicle.id = a.resource_id AND a.resource_type IN ('vehicle', 'repair')
            LEFT JOIN soferi current_driver ON current_driver.id = a.resource_id AND a.resource_type = 'driver'
            LEFT JOIN vehicule trip_vehicle ON trip_vehicle.id = c.vehicle_id
            LEFT JOIN soferi trip_driver ON trip_driver.id = c.driver_id
            LEFT JOIN configurare_beneficiari_transport beneficiary ON beneficiary.id = c.beneficiar_id
            LEFT JOIN configurare_locuri_incarcare load_location ON load_location.id = c.loc_incarcare_id
            LEFT JOIN configurare_zone_distributie unload_zone ON unload_zone.id = c.zona_distributie_id
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
            $row['approval_context'] = $this->buildApprovalContext($row);
        }
        unset($row);

        return $rows;
    }

    private function buildApprovalContext(array $approval): array
    {
        $snapshot = $this->decodeSnapshot((string) ($approval['snapshot_json'] ?? ''));
        $documents = is_array($approval['documents'] ?? null) ? $approval['documents'] : [];
        $resourceType = (string) ($approval['resource_type'] ?? '');
        $tripId = (int) ($approval['trip_id'] ?? 0);
        $primaryLabel = $this->approvalPrimaryLabel($approval);
        $problemTitle = $this->approvalProblemTitle($approval, $documents, $snapshot);
        $operationTitle = $this->approvalOperationTitle($approval);
        $usageDate = $this->approvalUsageDate($approval);
        $usageLabel = trim((string) ($approval['trip_loading_date'] ?? '')) !== '' ? 'Data incarcarii' : 'Data utilizarii';
        $inactiveDate = $this->approvalInactiveDate($approval, $documents);
        $inactiveLabel = $this->approvalInactiveDateLabel($approval, $documents);

        $summaryRows = [];
        $this->appendContextRow($summaryRows, $usageLabel, $this->formatContextDate($usageDate));
        $this->appendContextRow($summaryRows, $inactiveLabel, $this->formatContextDate($inactiveDate));
        $this->appendContextRow($summaryRows, 'Depasire', $this->approvalOverdueLabel($usageDate, $documents));

        $detailRows = [];
        $this->appendContextRow($detailRows, 'Beneficiar', $this->stringValue($approval['trip_beneficiary_name'] ?? ''));
        $this->appendContextRow($detailRows, 'Tip transport', $this->transportTypeLabel((string) ($approval['trip_transport_type'] ?? '')));
        $this->appendContextRow($detailRows, 'Vehicul cursa', $this->stringValue($approval['trip_vehicle_label'] ?? ''));
        $this->appendContextRow($detailRows, 'Sofer cursa', $this->stringValue($approval['trip_driver_label'] ?? ''));
        $this->appendContextRow($detailRows, 'Loc incarcare', $this->firstNonEmpty(
            $approval['trip_load_location_name'] ?? '',
            $approval['trip_departure_text'] ?? '',
            $approval['trip_suction_text'] ?? ''
        ));
        $this->appendContextRow($detailRows, 'Descarcare / zona', $this->firstNonEmpty(
            $approval['trip_unload_zone_name'] ?? '',
            $approval['trip_delivery_text'] ?? '',
            $approval['trip_closing_location_text'] ?? ''
        ));
        $this->appendContextRow($detailRows, 'Ruta', $this->approvalRouteLabel($approval));
        $this->appendContextRow($detailRows, 'Data incarcarii', $this->formatContextDate($approval['trip_loading_date'] ?? ''));
        $this->appendContextRow($detailRows, 'Interval cursa', $this->approvalIntervalLabel($approval));
        $this->appendDocumentContextRows($detailRows, $documents);
        $this->appendRepairContextRows($detailRows, $snapshot);
        $this->appendLeaveContextRows($detailRows, $snapshot);
        $this->appendContextRow($detailRows, 'Solicitat de', $this->stringValue($approval['requested_by_name'] ?? ''));
        $this->appendContextRow($detailRows, 'Solicitat la', $this->formatContextDateTime($approval['requested_at'] ?? ''));
        $this->appendContextRow($detailRows, 'Modul', $this->usageContextLabel((string) ($approval['usage_context'] ?? '')));

        return [
            'request_type_label' => $this->stringValue($approval['inactive_reason_label'] ?? 'Alt motiv'),
            'resource_type' => $resourceType,
            'resource_type_label' => $this->resourceTypeLabel($resourceType),
            'primary_label' => $primaryLabel,
            'problem_title' => $problemTitle,
            'operation_title' => $operationTitle,
            'operation_has_trip' => $tripId > 0,
            'operation_url' => $this->approvalOperationUrl($tripId),
            'operation_link_label' => $tripId > 0 ? 'Vezi cursa' : '',
            'usage_date' => $usageDate,
            'usage_date_label' => $usageLabel,
            'inactive_date' => $inactiveDate,
            'inactive_date_label' => $inactiveLabel,
            'summary_rows' => $summaryRows,
            'detail_rows' => $detailRows,
            'scope_message' => $this->approvalScopeMessage($approval, $primaryLabel, $problemTitle),
            'scope_kind' => $tripId > 0 ? 'trip' : 'request',
            'module_label' => $this->usageContextLabel((string) ($approval['usage_context'] ?? '')),
        ];
    }

    private function approvalPrimaryLabel(array $approval): string
    {
        return $this->firstNonEmpty(
            $approval['resource_label'] ?? '',
            $approval['current_vehicle_label'] ?? '',
            $approval['current_driver_label'] ?? '',
            $this->resourceTypeLabel((string) ($approval['resource_type'] ?? ''))
        );
    }

    private function approvalProblemTitle(array $approval, array $documents, array $snapshot): string
    {
        $reason = (string) ($approval['inactive_reason'] ?? 'other');
        $documentNames = $this->affectedDocumentNames($documents);
        $documentLabel = implode(', ', $documentNames);

        if ($documentNames !== []) {
            $expired = [];
            $missing = [];
            foreach ($documents as $document) {
                $name = trim((string) ($document['document_name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                if ((string) ($document['document_status'] ?? '') === 'missing') {
                    $missing[$name] = $name;
                } else {
                    $expired[$name] = $name;
                }
            }

            if ($expired !== [] && $missing !== []) {
                return 'Documente lipsa/expirate: ' . $documentLabel;
            }
            if ($expired !== []) {
                return (count($expired) === 1 ? 'Document expirat: ' : 'Documente expirate: ') . implode(', ', array_values($expired));
            }
            if ($missing !== []) {
                return (count($missing) === 1 ? 'Document lipsa: ' : 'Documente lipsa: ') . implode(', ', array_values($missing));
            }
        }

        if ($reason === 'repair') {
            return 'Reparatie activa/programata';
        }

        $detail = trim((string) ($snapshot['detail'] ?? ''));
        if ($detail !== '') {
            return (string) ($approval['inactive_reason_label'] ?? 'Alt motiv') . ': ' . $detail;
        }

        return (string) ($approval['inactive_reason_label'] ?? 'Alt motiv');
    }

    private function approvalOperationTitle(array $approval): string
    {
        $tripId = (int) ($approval['trip_id'] ?? 0);
        if ($tripId <= 0) {
            return 'Solicitare fara cursa asociata';
        }

        $transportLabel = $this->transportTypeLabel((string) ($approval['trip_transport_type'] ?? ''));

        return trim('Cursa #' . $tripId . ($transportLabel !== '' ? ' · ' . $transportLabel : ''));
    }

    private function approvalUsageDate(array $approval): string
    {
        return $this->firstNonEmpty(
            $approval['trip_loading_date'] ?? '',
            $approval['trip_start_date'] ?? '',
            $approval['trip_date'] ?? ''
        );
    }

    private function approvalInactiveDate(array $approval, array $documents): string
    {
        $expiry = $this->firstDocumentExpiry($documents);
        if ($expiry !== '') {
            return $expiry;
        }

        return $this->normalizeDate($approval['inactive_since'] ?? null) ?? '';
    }

    private function approvalInactiveDateLabel(array $approval, array $documents): string
    {
        foreach ($documents as $document) {
            if ((string) ($document['document_status'] ?? '') === 'expired') {
                return 'Expirat din';
            }
        }

        $reason = (string) ($approval['inactive_reason'] ?? '');
        return $reason === 'missing_documents' ? 'Lipsa din' : 'Inactiv din';
    }

    private function approvalOverdueLabel(string $usageDate, array $documents): string
    {
        $expiry = $this->firstDocumentExpiry($documents);
        if ($usageDate === '' || $expiry === '') {
            return '';
        }

        try {
            $usage = new DateTimeImmutable($usageDate);
            $expires = new DateTimeImmutable($expiry);
        } catch (Throwable) {
            return '';
        }

        if ($usage <= $expires) {
            return '';
        }

        $days = $expires->diff($usage)->days;
        if ($days <= 0) {
            return '';
        }

        return $days === 1 ? '1 zi' : $days . ' zile';
    }

    private function firstDocumentExpiry(array $documents): string
    {
        $dates = [];
        foreach ($documents as $document) {
            if ((string) ($document['document_status'] ?? '') !== 'expired') {
                continue;
            }
            $date = $this->normalizeDate($document['expiry_date'] ?? null);
            if ($date !== null) {
                $dates[] = $date;
            }
        }

        sort($dates);
        return $dates[0] ?? '';
    }

    private function appendDocumentContextRows(array &$rows, array $documents): void
    {
        foreach ($documents as $document) {
            $name = trim((string) ($document['document_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $status = (string) ($document['document_status'] ?? '');
            $label = $status === 'missing' ? 'Document lipsa' : 'Document expirat';
            $value = $name;
            $expiry = $this->normalizeDate($document['expiry_date'] ?? null);
            if ($expiry !== null && $status === 'expired') {
                $value .= ' · expira la ' . $this->formatContextDate($expiry);
            }
            $this->appendContextRow($rows, $label, $value);
        }
    }

    private function appendRepairContextRows(array &$rows, array $snapshot): void
    {
        $repair = is_array($snapshot['repair'] ?? null) ? $snapshot['repair'] : [];
        if ($repair === []) {
            return;
        }

        $this->appendContextRow($rows, 'Status reparatie', $this->stringValue($repair['status'] ?? ''));
        $this->appendContextRow($rows, 'Data reparatie', $this->formatContextDate($repair['start_date'] ?? ''));
        $this->appendContextRow($rows, 'Service', $this->stringValue($repair['supplier'] ?? ''));
        $this->appendContextRow($rows, 'Detaliu reparatie', $this->stringValue($repair['detail'] ?? ''));
    }

    private function appendLeaveContextRows(array &$rows, array $snapshot): void
    {
        $leave = is_array($snapshot['leave'] ?? null) ? $snapshot['leave'] : [];
        if ($leave === []) {
            return;
        }

        $start = $this->formatContextDate($leave['start_date'] ?? '');
        $end = $this->formatContextDate($leave['end_date'] ?? '');
        $period = $start;
        if ($end !== '' && $end !== $start) {
            $period .= ' - ' . $end;
        }
        $this->appendContextRow($rows, 'Perioada indisponibila', $period);
        $this->appendContextRow($rows, 'Detaliu indisponibilitate', $this->stringValue($leave['detail'] ?? ''));
    }

    private function approvalRouteLabel(array $approval): string
    {
        $start = $this->firstNonEmpty(
            $approval['trip_load_location_name'] ?? '',
            $approval['trip_departure_text'] ?? '',
            $approval['trip_suction_text'] ?? ''
        );
        $end = $this->firstNonEmpty(
            $approval['trip_unload_zone_name'] ?? '',
            $approval['trip_delivery_text'] ?? '',
            $approval['trip_closing_location_text'] ?? ''
        );

        if ($start !== '' && $end !== '' && mb_strtolower($start, 'UTF-8') !== mb_strtolower($end, 'UTF-8')) {
            return $start . ' -> ' . $end;
        }

        return $start !== '' ? $start : $end;
    }

    private function approvalIntervalLabel(array $approval): string
    {
        $start = $this->formatContextDate($approval['trip_start_date'] ?? '');
        $end = $this->formatContextDate($approval['trip_end_date'] ?? '');
        if ($start === '') {
            return $end;
        }
        if ($end === '' || $end === $start) {
            return $start;
        }

        return $start . ' - ' . $end;
    }

    private function approvalScopeMessage(array $approval, string $primaryLabel, string $problemTitle): string
    {
        $resourceType = (string) ($approval['resource_type'] ?? '');
        $tripId = (int) ($approval['trip_id'] ?? 0);
        $resourceText = match ($resourceType) {
            'driver' => 'soferului',
            'repair' => 'vehiculului',
            default => 'vehiculului',
        };
        $resourceSubjectText = match ($resourceType) {
            'driver' => 'soferul',
            'repair' => 'vehiculul',
            default => 'vehiculul',
        };
        $targetText = $primaryLabel !== '' ? $resourceText . ' ' . $primaryLabel : $resourceText;
        $requestTargetText = $primaryLabel !== '' ? $resourceSubjectText . ' ' . $primaryLabel : $resourceSubjectText;
        $problem = trim($problemTitle);
        $problemSuffix = $problem !== '' ? ', desi exista motivul: ' . $problem . '.' : '.';

        if ($tripId > 0) {
            return 'Prin aprobare permiti utilizarea ' . $targetText . ' in cursa #' . $tripId . $problemSuffix;
        }

        return 'Prin aprobare confirmi aceasta solicitare din Dispecer curse pentru ' . $requestTargetText . $problemSuffix . ' Resursa nu este reactivata global.';
    }

    private function approvalOperationUrl(int $tripId): string
    {
        if ($tripId <= 0) {
            return '';
        }

        if (function_exists('build_query_url')) {
            return build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $tripId]);
        }

        return 'index.php?page=dispecer_curse&action=edit&id=' . $tripId;
    }

    private function resourceTypeLabel(string $resourceType): string
    {
        return match ($resourceType) {
            'driver' => 'Sofer',
            'repair' => 'Reparatie',
            default => 'Vehicul',
        };
    }

    private function transportTypeLabel(string $transportType): string
    {
        $transportType = trim($transportType);
        return self::TRANSPORT_TYPE_LABELS[$transportType] ?? $transportType;
    }

    private function usageContextLabel(string $usageContext): string
    {
        $usageContext = trim($usageContext);
        if ($usageContext === '' || $usageContext === 'dispecer_curse') {
            return 'Dispecer curse';
        }

        return ucfirst(str_replace('_', ' ', $usageContext));
    }

    private function appendContextRow(array &$rows, string $label, string $value): void
    {
        $label = trim($label);
        $value = trim($value);
        if ($label === '' || $value === '') {
            return;
        }

        $rows[] = [
            'label' => $label,
            'value' => $value,
        ];
    }

    private function firstNonEmpty(mixed ...$values): string
    {
        foreach ($values as $value) {
            $string = $this->stringValue($value);
            if ($string !== '') {
                return $string;
            }
        }

        return '';
    }

    private function stringValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }

    private function formatContextDate(mixed $value): string
    {
        $date = $this->normalizeDate($value);
        if ($date === null) {
            return '';
        }

        try {
            return (new DateTimeImmutable($date))->format('d.m.Y');
        } catch (Throwable) {
            return $date;
        }
    }

    private function formatContextDateTime(mixed $value): string
    {
        $raw = trim((string) $value);
        if ($raw === '' || $raw === '0000-00-00 00:00:00') {
            return '';
        }

        try {
            return (new DateTimeImmutable($raw))->format('d.m.Y H:i');
        } catch (Throwable) {
            return $raw;
        }
    }

    private function decodeSnapshot(string $json): array
    {
        $snapshot = json_decode($json, true);

        return is_array($snapshot) ? $snapshot : [];
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
        return in_array($resourceType, self::RESOURCE_TYPES, true) ? $resourceType : '';
    }

    private function approvalResourceTypeForSnapshot(array $resource): string
    {
        $resourceType = $this->normalizeResourceType((string) ($resource['approval_resource_type'] ?? ''));
        if ($resourceType !== '') {
            return $resourceType;
        }

        $resourceType = $this->normalizeResourceType((string) ($resource['resource_type'] ?? ''));
        $reasonKey = strtolower(trim((string) ($resource['reason_key'] ?? $resource['inactive_reason'] ?? '')));
        if ($resourceType === 'vehicle' && $reasonKey === 'repair') {
            return 'repair';
        }

        return $resourceType;
    }

    private function ensureRepairResourceTypeColumn(): void
    {
        $stmt = $this->db->query("
            SELECT COLUMN_TYPE
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'inactive_resource_approvals'
              AND COLUMN_NAME = 'resource_type'
            LIMIT 1
        ");
        $columnType = (string) $stmt->fetchColumn();
        if ($columnType !== '' && !str_contains($columnType, "'repair'")) {
            $this->db->exec("ALTER TABLE inactive_resource_approvals MODIFY COLUMN resource_type ENUM('vehicle','driver','repair') NOT NULL");
        }
    }

    private function migrateRepairCategoryRows(): void
    {
        $this->db->exec("
            UPDATE inactive_resource_approvals
            SET resource_type = 'repair'
            WHERE resource_type = 'vehicle'
              AND inactive_reason = 'repair'
        ");
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
