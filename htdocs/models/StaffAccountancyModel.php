<?php
declare(strict_types=1);

class StaffAccountancyModel extends BaseModel
{
    private const EMPLOYMENT_CONTRACT_DOCUMENT = 'Contract de muncă';

    private const DEFAULT_DOCUMENT_TYPES = [
        'Contract de muncă',
        'Act adițional',
        'CI / Buletin',
        'Permis conducere',
        'Medicina muncii',
        'Aviz medical',
        'Certificat profesional',
        'Alte documente',
    ];

    public const TERMINATION_REASONS = [
        'Demisie',
        'Incetare contract',
        'Concediere',
        'Pensionare',
        'Reorganizare',
        'Abandon / Neprezentare',
        'Acordul partilor',
        'Alte motive',
    ];

    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->ensureEmploymentLifecycleSchema();
        $this->ensureEmploymentContractRequirements();
    }

    public function getSummary(): array
    {
        $sql = 'SELECT
                    COUNT(*) AS total_personal,
                    COALESCE(SUM(COALESCE(salariu, 0)), 0) AS total_salarii,
                    SUM(CASE WHEN category = "operational" THEN 1 ELSE 0 END) AS personal_operational,
                    SUM(CASE WHEN category = "office" THEN 1 ELSE 0 END) AS personal_birou
                FROM (' . $this->baseStaffUnionSql() . ') staff
                WHERE staff.employment_status <> "terminated"';

        $row = $this->db->query($sql)->fetch() ?: [];

        return [
            'total_personal' => (int) ($row['total_personal'] ?? 0),
            'total_salarii' => (float) ($row['total_salarii'] ?? 0),
            'personal_operational' => (int) ($row['personal_operational'] ?? 0),
            'personal_birou' => (int) ($row['personal_birou'] ?? 0),
        ];
    }

    public function getPaginatedStaff(array $filters, string $sort, string $direction, int $page, int $perPage): array
    {
        [$whereSql, $params] = $this->buildStaffWhere($filters);
        $orderBy = $this->resolveStaffOrderBy($sort, $direction);

        $countSql = 'SELECT COUNT(*) FROM (' . $this->baseStaffUnionSql() . ') staff ' . $whereSql;
        $countStmt = $this->db->prepare($countSql);
        $this->bindParams($countStmt, $params);
        $countStmt->execute();

        $totalRows = (int) $countStmt->fetchColumn();
        $totalPages = max(1, (int) ceil($totalRows / max(1, $perPage)));
        $page = min(max(1, $page), $totalPages);
        $offset = ($page - 1) * $perPage;

        $sql = 'SELECT *
                FROM (' . $this->baseStaffUnionSql() . ') staff
                ' . $whereSql . '
                ORDER BY ' . $orderBy . '
                LIMIT :limit_rows OFFSET :offset_rows';
        $stmt = $this->db->prepare($sql);
        $this->bindParams($stmt, $params);
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

    public function getAllStaffForExport(array $filters, string $sort, string $direction): array
    {
        [$whereSql, $params] = $this->buildStaffWhere($filters);
        $sql = 'SELECT *
                FROM (' . $this->baseStaffUnionSql() . ') staff
                ' . $whereSql . '
                ORDER BY ' . $this->resolveStaffOrderBy($sort, $direction);

        $stmt = $this->db->prepare($sql);
        $this->bindParams($stmt, $params);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getStaffTypesWithRequirements(): array
    {
        $sql = '
            SELECT
                st.*,
                CASE
                    WHEN st.is_driver_linked = 1 THEN (SELECT COUNT(*) FROM soferi WHERE employment_status <> "terminated")
                    ELSE (SELECT COUNT(*) FROM staff_members sm WHERE sm.staff_type_id = st.id AND sm.employment_status <> "terminated")
                END AS employee_count
            FROM staff_types st
            ORDER BY st.is_driver_linked DESC, st.category ASC, st.name ASC
        ';

        $rows = $this->db->query($sql)->fetchAll();
        $requirements = $this->getRequirementsGroupedByType();

        foreach ($rows as &$row) {
            $row['requirements'] = $requirements[(int) ($row['id'] ?? 0)] ?? [];
        }
        unset($row);

        return $rows;
    }

    public function getStaffTypeOptions(bool $onlyActive = false): array
    {
        $sql = 'SELECT id, name, category, is_driver_linked, can_create_employees FROM staff_types';
        if ($onlyActive) {
            $sql .= " WHERE status = 'activ'";
        }
        $sql .= ' ORDER BY is_driver_linked DESC, category ASC, name ASC';

        return $this->db->query($sql)->fetchAll();
    }

    public function getDriverOptions(): array
    {
        $sql = '
            SELECT
                s.id,
                s.nume,
                s.telefon,
                s.salariu,
                s.data_angajare,
                s.status,
                v.nr_inmatriculare AS vehicle_label
            FROM soferi s
            LEFT JOIN vehicule v ON v.id = s.vehicle_id
            WHERE s.employment_status <> "terminated"
            ORDER BY s.nume ASC
        ';

        return $this->db->query($sql)->fetchAll();
    }

    public function findStaffType(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT * FROM staff_types WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findDriverStaffType(): ?array
    {
        $stmt = $this->db->query("SELECT * FROM staff_types WHERE slug = 'sofer' LIMIT 1");
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function createStaffType(array $data, ?int $userId): int
    {
        $now = date('Y-m-d H:i:s');
        $name = trim((string) ($data['name'] ?? ''));
        $slug = $this->uniqueSlug($this->slugify($name));

        $stmt = $this->db->prepare('
            INSERT INTO staff_types (
                name, slug, category, description, status, is_system, is_driver_linked,
                salary_required, vehicle_required, mandatory_documents_enabled,
                can_create_employees, can_delete_employees, document_warning_days,
                created_by, updated_by, created_at, updated_at
            ) VALUES (
                :name, :slug, :category, :description, :status, 0, 0,
                :salary_required, :vehicle_required, :mandatory_documents_enabled,
                :can_create_employees, :can_delete_employees, :document_warning_days,
                :created_by, :updated_by, :created_at, :updated_at
            )
        ');

        $this->bindStaffTypeStatement($stmt, $data + [
            'slug' => $slug,
            'is_system' => 0,
            'is_driver_linked' => 0,
        ], $userId, $now);
        $stmt->execute();

        return (int) $this->db->lastInsertId();
    }

    public function createStaffTypeWithRequirements(array $data, array $requirements, ?int $userId): int
    {
        $startedTransaction = !$this->db->inTransaction();
        if ($startedTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $typeId = $this->createStaffType($data, $userId);
            $this->addRequirement($typeId, self::EMPLOYMENT_CONTRACT_DOCUMENT, false, 30);
            foreach ($requirements as $requirement) {
                $documentType = trim((string) ($requirement['document_type'] ?? ''));
                if ($documentType === '') {
                    continue;
                }

                $saved = $this->addRequirement(
                    $typeId,
                    $documentType,
                    (bool) ($requirement['requires_expiry'] ?? false),
                    (int) ($requirement['warning_days'] ?? 30)
                );
                if (!$saved) {
                    throw new RuntimeException('Nu am putut salva documentele obligatorii pentru tipul de personal.');
                }
            }

            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->commit();
            }

            return $typeId;
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    public function updateStaffType(int $id, array $data, ?int $userId): bool
    {
        $type = $this->findStaffType($id);
        if ($type === null) {
            return false;
        }

        $isDriverLinked = (int) ($type['is_driver_linked'] ?? 0) === 1;
        $isSystem = (int) ($type['is_system'] ?? 0) === 1;
        $now = date('Y-m-d H:i:s');

        $payload = [
            'name' => trim((string) ($data['name'] ?? $type['name'] ?? '')),
            'category' => $this->normalizeCategory((string) ($data['category'] ?? $type['category'] ?? 'operational')),
            'description' => trim((string) ($data['description'] ?? '')),
            'status' => $isSystem ? 'activ' : $this->normalizeStatus((string) ($data['status'] ?? 'activ')),
            'salary_required' => !empty($data['salary_required']) ? 1 : 0,
            'vehicle_required' => !empty($data['vehicle_required']) ? 1 : 0,
            'mandatory_documents_enabled' => !empty($data['mandatory_documents_enabled']) ? 1 : 0,
            'can_create_employees' => $isDriverLinked ? 0 : (!empty($data['can_create_employees']) ? 1 : 0),
            'can_delete_employees' => $isDriverLinked ? 0 : (!empty($data['can_delete_employees']) ? 1 : 0),
            'document_warning_days' => $this->normalizeWarningDays((int) ($data['document_warning_days'] ?? 30)),
            'updated_by' => $userId,
            'updated_at' => $now,
            'id' => $id,
        ];

        if ($isDriverLinked) {
            $payload['name'] = 'Șofer';
            $payload['category'] = 'operational';
            $payload['vehicle_required'] = 1;
        }

        $stmt = $this->db->prepare('
            UPDATE staff_types
            SET name = :name,
                category = :category,
                description = :description,
                status = :status,
                salary_required = :salary_required,
                vehicle_required = :vehicle_required,
                mandatory_documents_enabled = :mandatory_documents_enabled,
                can_create_employees = :can_create_employees,
                can_delete_employees = :can_delete_employees,
                document_warning_days = :document_warning_days,
                updated_by = :updated_by,
                updated_at = :updated_at
            WHERE id = :id
        ');
        $this->bindParams($stmt, [
            ':name' => $payload['name'],
            ':category' => $payload['category'],
            ':description' => $payload['description'] !== '' ? $payload['description'] : null,
            ':status' => $payload['status'],
            ':salary_required' => $payload['salary_required'],
            ':vehicle_required' => $payload['vehicle_required'],
            ':mandatory_documents_enabled' => $payload['mandatory_documents_enabled'],
            ':can_create_employees' => $payload['can_create_employees'],
            ':can_delete_employees' => $payload['can_delete_employees'],
            ':document_warning_days' => $payload['document_warning_days'],
            ':updated_by' => $payload['updated_by'],
            ':updated_at' => $payload['updated_at'],
            ':id' => $payload['id'],
        ]);

        return $stmt->execute();
    }

    public function addRequirement(int $staffTypeId, string $documentType, bool $requiresExpiry, int $warningDays): bool
    {
        $type = $this->findStaffType($staffTypeId);
        if ($type === null) {
            return false;
        }

        $documentType = trim($documentType);
        if ($documentType === '') {
            return false;
        }
        if ($this->isEmploymentContractDocument($documentType)) {
            $documentType = self::EMPLOYMENT_CONTRACT_DOCUMENT;
            $requiresExpiry = false;
            $warningDays = 30;
        }

        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare('
            INSERT INTO staff_document_requirements (
                staff_type_id, document_type, requires_expiry, warning_days, created_at, updated_at
            ) VALUES (
                :staff_type_id, :document_type, :requires_expiry, :warning_days, :created_at, :updated_at
            )
            ON DUPLICATE KEY UPDATE
                requires_expiry = VALUES(requires_expiry),
                warning_days = VALUES(warning_days),
                updated_at = VALUES(updated_at)
        ');
        $stmt->bindValue(':staff_type_id', $staffTypeId, PDO::PARAM_INT);
        $stmt->bindValue(':document_type', $documentType, PDO::PARAM_STR);
        $stmt->bindValue(':requires_expiry', $requiresExpiry ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':warning_days', $this->normalizeWarningDays($warningDays), PDO::PARAM_INT);
        $stmt->bindValue(':created_at', $now, PDO::PARAM_STR);
        $stmt->bindValue(':updated_at', $now, PDO::PARAM_STR);
        $ok = $stmt->execute();

        if ($ok && (int) ($type['is_driver_linked'] ?? 0) === 1) {
            $this->upsertDriverRequiredDocument($documentType, $requiresExpiry);
        }

        return $ok;
    }

    public function deleteRequirement(int $requirementId): ?array
    {
        if ($requirementId <= 0) {
            return null;
        }

        $stmt = $this->db->prepare('
            SELECT r.*, st.is_driver_linked
            FROM staff_document_requirements r
            INNER JOIN staff_types st ON st.id = r.staff_type_id
            WHERE r.id = :id
            LIMIT 1
        ');
        $stmt->bindValue(':id', $requirementId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }
        if ($this->isEmploymentContractDocument((string) ($row['document_type'] ?? ''))) {
            throw new InvalidArgumentException('Contractul de angajare este obligatoriu pentru fiecare tip de personal si nu poate fi eliminat.');
        }

        $deleteStmt = $this->db->prepare('DELETE FROM staff_document_requirements WHERE id = :id');
        $deleteStmt->bindValue(':id', $requirementId, PDO::PARAM_INT);
        $deleteStmt->execute();

        if ((int) ($row['is_driver_linked'] ?? 0) === 1) {
            $driverStmt = $this->db->prepare('DELETE FROM configurare_documente_obligatorii_soferi WHERE document_type = :document_type');
            $driverStmt->bindValue(':document_type', (string) ($row['document_type'] ?? ''), PDO::PARAM_STR);
            $driverStmt->execute();
        }

        return $row;
    }

    public function createDirectStaff(array $data, ?int $userId): int
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare('
            INSERT INTO staff_members (
                staff_type_id, nume_complet, telefon, email, functie, salariu,
                data_angajare, status, observatii, created_by, updated_by, created_at, updated_at
            ) VALUES (
                :staff_type_id, :nume_complet, :telefon, :email, :functie, :salariu,
                :data_angajare, :status, :observatii, :created_by, :updated_by, :created_at, :updated_at
            )
        ');
        $this->bindStaffMemberStatement($stmt, $data, $userId, $now);
        $stmt->execute();
        $id = (int) $this->db->lastInsertId();

        if (array_key_exists('salariu', $data) && $data['salariu'] !== null) {
            $this->createSalaryHistory('staff', $id, null, (float) $data['salariu'], (string) ($data['data_angajare'] ?? date('Y-m-d')), $userId, 'Salariu initial.');
        }

        return $id;
    }

    public function createDirectStaffWithDocuments(array $data, array $documents, ?int $userId): int
    {
        $startedTransaction = !$this->db->inTransaction();
        if ($startedTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $staffId = $this->createDirectStaff($data, $userId);
            foreach ($documents as $document) {
                $payload = is_array($document['data'] ?? null) ? $document['data'] : [];
                $fileData = is_array($document['fileData'] ?? null) ? $document['fileData'] : null;
                if ($payload === []) {
                    continue;
                }

                if (!$this->saveStaffDocument($staffId, $payload, $fileData, $userId)) {
                    throw new RuntimeException('Nu am putut salva documentele initiale pentru angajat.');
                }
            }

            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->commit();
            }

            return $staffId;
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    public function updateDirectStaff(int $id, array $data, ?int $userId): bool
    {
        $existing = $this->findDirectStaff($id);
        if ($existing === null) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare('
            UPDATE staff_members
            SET staff_type_id = :staff_type_id,
                nume_complet = :nume_complet,
                telefon = :telefon,
                email = :email,
                functie = :functie,
                data_angajare = :data_angajare,
                status = :status,
                observatii = :observatii,
                updated_by = :updated_by,
                updated_at = :updated_at
            WHERE id = :id
        ');
        $payload = $data + ['salariu' => $existing['salariu'] ?? null];
        $this->bindStaffMemberStatement($stmt, $payload, $userId, $now, false);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function deleteDirectStaff(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM staff_members WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function findDirectStaff(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT * FROM staff_members WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findDriver(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT * FROM soferi WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function updateDriverAccounting(int $driverId, ?float $salary, ?string $hireDate, ?string $notes, ?int $userId): bool
    {
        $driver = $this->findDriver($driverId);
        if ($driver === null) {
            return false;
        }

        $previousSalary = $driver['salariu'] !== null ? (float) $driver['salariu'] : null;
        $data = [
            ':salariu' => $salary,
            ':data_angajare' => $hireDate,
            ':observatii' => $notes !== null ? trim($notes) : ($driver['observatii'] ?? null),
            ':updated_at' => date('Y-m-d H:i:s'),
            ':id' => $driverId,
        ];

        $stmt = $this->db->prepare('
            UPDATE soferi
            SET salariu = :salariu,
                data_angajare = :data_angajare,
                observatii = :observatii,
                updated_at = :updated_at
            WHERE id = :id
        ');
        $this->bindParams($stmt, $data);
        $ok = $stmt->execute();

        if ($ok && $salary !== null && $previousSalary !== $salary) {
            $this->createSalaryHistory('driver', $driverId, $previousSalary, $salary, $hireDate ?: date('Y-m-d'), $userId, 'Actualizare din Contabilitate Personal.');
        }

        return $ok;
    }

    public function endEmployment(string $subjectType, int $subjectId, array $termination, ?int $userId): bool
    {
        $endDate = (string) ($termination['termination_date'] ?? '');
        $lastWorkingDay = $termination['last_working_day'] ?? null;
        $notes = isset($termination['termination_notes']) ? (string) $termination['termination_notes'] : null;

        if ($subjectType === 'driver') {
            $existing = $this->findDriver($subjectId);
            if ($existing === null) {
                return false;
            }

            $this->assertEndDateAfterHireDate($existing, $endDate);
            $this->assertLastWorkingDayIsValid($existing, $endDate, is_string($lastWorkingDay) ? $lastWorkingDay : null);
            return $this->terminateSubject($subjectType, $subjectId, $existing, $termination, $userId);
        }

        $existing = $this->findDirectStaff($subjectId);
        if ($existing === null) {
            return false;
        }

        $this->assertEndDateAfterHireDate($existing, $endDate);
        $this->assertLastWorkingDayIsValid($existing, $endDate, is_string($lastWorkingDay) ? $lastWorkingDay : null);
        return $this->terminateSubject($subjectType, $subjectId, $existing, $termination, $userId);
    }

    public function updateSalary(string $subjectType, int $subjectId, float $salary, string $effectiveDate, ?string $notes, ?int $userId): bool
    {
        if ($subjectType === 'driver') {
            $existing = $this->findDriver($subjectId);
            if ($existing === null) {
                return false;
            }

            $previous = $existing['salariu'] !== null ? (float) $existing['salariu'] : null;
            $stmt = $this->db->prepare('UPDATE soferi SET salariu = :salariu, updated_at = :updated_at WHERE id = :id');
        } else {
            $existing = $this->findDirectStaff($subjectId);
            if ($existing === null) {
                return false;
            }

            $previous = $existing['salariu'] !== null ? (float) $existing['salariu'] : null;
            $stmt = $this->db->prepare('UPDATE staff_members SET salariu = :salariu, updated_by = :updated_by, updated_at = :updated_at WHERE id = :id');
            $stmt->bindValue(':updated_by', $userId, $userId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        }

        $stmt->bindValue(':salariu', (string) $salary);
        $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':id', $subjectId, PDO::PARAM_INT);
        $ok = $stmt->execute();

        if ($ok) {
            $this->createSalaryHistory($subjectType, $subjectId, $previous, $salary, $effectiveDate, $userId, $notes);
        }

        return $ok;
    }

    public function getFormerSummary(): array
    {
        $sql = 'SELECT
                    COUNT(*) AS total_former,
                    SUM(CASE WHEN category = "operational" THEN 1 ELSE 0 END) AS former_operational,
                    SUM(CASE WHEN category = "office" THEN 1 ELSE 0 END) AS former_office,
                    SUM(CASE
                        WHEN termination_effective_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) THEN 1
                        ELSE 0
                    END) AS former_last_12_months
                FROM (' . $this->baseStaffUnionSql() . ') staff
                WHERE ' . $this->formerConditionSql();

        $row = $this->db->query($sql)->fetch() ?: [];

        return [
            'total_former' => (int) ($row['total_former'] ?? 0),
            'former_operational' => (int) ($row['former_operational'] ?? 0),
            'former_office' => (int) ($row['former_office'] ?? 0),
            'former_last_12_months' => (int) ($row['former_last_12_months'] ?? 0),
        ];
    }

    public function getPaginatedFormerEmployees(array $filters, string $sort, string $direction, int $page, int $perPage): array
    {
        [$whereSql, $params] = $this->buildFormerWhere($filters);
        $orderBy = $this->resolveFormerOrderBy($sort, $direction);

        $countSql = 'SELECT COUNT(*) FROM (' . $this->baseStaffUnionSql() . ') staff ' . $whereSql;
        $countStmt = $this->db->prepare($countSql);
        $this->bindParams($countStmt, $params);
        $countStmt->execute();

        $totalRows = (int) $countStmt->fetchColumn();
        $totalPages = max(1, (int) ceil($totalRows / max(1, $perPage)));
        $page = min(max(1, $page), $totalPages);
        $offset = ($page - 1) * $perPage;

        $sql = 'SELECT *
                FROM (' . $this->baseStaffUnionSql() . ') staff
                ' . $whereSql . '
                ORDER BY ' . $orderBy . '
                LIMIT :limit_rows OFFSET :offset_rows';
        $stmt = $this->db->prepare($sql);
        $this->bindParams($stmt, $params);
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

    public function getAllFormerEmployeesForExport(array $filters, string $sort, string $direction): array
    {
        [$whereSql, $params] = $this->buildFormerWhere($filters);
        $sql = 'SELECT *
                FROM (' . $this->baseStaffUnionSql() . ') staff
                ' . $whereSql . '
                ORDER BY ' . $this->resolveFormerOrderBy($sort, $direction);

        $stmt = $this->db->prepare($sql);
        $this->bindParams($stmt, $params);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getTerminationReasons(): array
    {
        $reasons = [];
        foreach (self::TERMINATION_REASONS as $reason) {
            $reasons[$reason] = $reason;
        }

        $stmt = $this->db->query('
            SELECT DISTINCT termination_reason
            FROM (' . $this->baseStaffUnionSql() . ') staff
            WHERE ' . $this->formerConditionSql() . '
              AND COALESCE(TRIM(termination_reason), "") <> ""
            ORDER BY termination_reason ASC
        ');

        foreach ($stmt->fetchAll() as $row) {
            $reason = trim((string) ($row['termination_reason'] ?? ''));
            if ($reason !== '') {
                $reasons[$reason] = $reason;
            }
        }

        return $reasons;
    }

    public function getFormerProfile(string $sourceType, int $sourceId): ?array
    {
        $row = $this->findSubjectInUnion($sourceType, $sourceId, true);
        if ($row === null) {
            return null;
        }

        $documents = $this->getDocumentsForRows([$row]);
        $salaryHistory = $this->getSalaryHistoryForRows([$row]);
        $key = (string) ($row['source_type'] ?? '') . '-' . (int) ($row['source_id'] ?? 0);

        return [
            'row' => $row,
            'documents' => $documents[$key] ?? [],
            'salary_history' => $salaryHistory[$key] ?? [],
            'employment_periods' => $this->getEmploymentPeriodsForSubject($sourceType, $sourceId),
            'driver_history' => $sourceType === 'driver' ? $this->getDriverOperationalHistory($sourceId) : $this->emptyDriverOperationalHistory(),
        ];
    }

    public function updateTermination(string $subjectType, int $subjectId, array $data, ?array $fileData, ?int $userId): bool
    {
        $existing = $subjectType === 'driver' ? $this->findDriver($subjectId) : $this->findDirectStaff($subjectId);
        if ($existing === null) {
            return false;
        }

        $terminationDate = (string) ($data['termination_date'] ?? '');
        $lastWorkingDay = $data['last_working_day'] ?? null;
        $this->assertEndDateAfterHireDate($existing, $terminationDate);
        $this->assertLastWorkingDayIsValid($existing, $terminationDate, is_string($lastWorkingDay) ? $lastWorkingDay : null);

        $startedTransaction = !$this->db->inTransaction();
        if ($startedTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $data['append_observatii'] = false;
            $this->updateSubjectTerminationColumns($subjectType, $subjectId, $data, $fileData, $userId);
            $this->syncLatestTerminatedEmploymentPeriod($subjectType, $subjectId, $data, $userId);

            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->commit();
            }

            return true;
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    public function rehireEmployee(string $subjectType, int $subjectId, array $data, ?int $userId): bool
    {
        $existing = $subjectType === 'driver' ? $this->findDriver($subjectId) : $this->findDirectStaff($subjectId);
        if ($existing === null) {
            return false;
        }

        $hireDate = (string) ($data['hire_date'] ?? '');
        $salary = $data['salary'] ?? null;
        $now = date('Y-m-d H:i:s');
        $startedTransaction = !$this->db->inTransaction();
        if ($startedTransaction) {
            $this->db->beginTransaction();
        }

        try {
            if ($subjectType === 'driver') {
                $stmt = $this->db->prepare('
                    UPDATE soferi
                    SET status = "activ",
                        employment_status = "active",
                        data_angajare = :data_angajare,
                        data_incetare = NULL,
                        termination_date = NULL,
                        termination_reason = NULL,
                        termination_notes = NULL,
                        last_working_day = NULL,
                        termination_document_original = NULL,
                        termination_document_path = NULL,
                        termination_assets_returned = 0,
                        terminated_by = NULL,
                        terminated_at = NULL,
                        salariu = :salariu,
                        observatii = :observatii,
                        updated_at = :updated_at
                    WHERE id = :id
                ');
                $this->bindParams($stmt, [
                    ':data_angajare' => $hireDate,
                    ':salariu' => $salary,
                    ':observatii' => $this->appendRehireNote($existing['observatii'] ?? null, $hireDate, $data['rehire_notes'] ?? null),
                    ':updated_at' => $now,
                    ':id' => $subjectId,
                ]);
                $stmt->execute();
            } else {
                $stmt = $this->db->prepare('
                    UPDATE staff_members
                    SET staff_type_id = :staff_type_id,
                        functie = :functie,
                        status = "activ",
                        employment_status = "active",
                        data_angajare = :data_angajare,
                        data_incetare = NULL,
                        termination_date = NULL,
                        termination_reason = NULL,
                        termination_notes = NULL,
                        last_working_day = NULL,
                        termination_document_original = NULL,
                        termination_document_path = NULL,
                        termination_assets_returned = 0,
                        terminated_by = NULL,
                        terminated_at = NULL,
                        salariu = :salariu,
                        observatii = :observatii,
                        updated_by = :updated_by,
                        updated_at = :updated_at
                    WHERE id = :id
                ');
                $this->bindParams($stmt, [
                    ':staff_type_id' => (int) ($data['staff_type_id'] ?? 0),
                    ':functie' => trim((string) ($data['function_name'] ?? '')),
                    ':data_angajare' => $hireDate,
                    ':salariu' => $salary,
                    ':observatii' => $this->appendRehireNote($existing['observatii'] ?? null, $hireDate, $data['rehire_notes'] ?? null),
                    ':updated_by' => $userId,
                    ':updated_at' => $now,
                    ':id' => $subjectId,
                ]);
                $stmt->execute();
            }

            $previousSalary = $existing['salariu'] !== null ? (float) $existing['salariu'] : null;
            if ($salary !== null && $previousSalary !== (float) $salary) {
                $this->createSalaryHistory($subjectType, $subjectId, $previousSalary, (float) $salary, $hireDate, $userId, 'Reangajare.');
            }

            $driverStaffType = $subjectType === 'driver' ? $this->findDriverStaffType() : null;
            $this->createEmploymentPeriod($subjectType, $subjectId, [
                'source_module' => $subjectType === 'driver' ? 'soferi' : 'contabilitate_personal',
                'personnel_type' => $subjectType === 'driver' ? 'operational' : $this->categoryForStaffType((int) ($data['staff_type_id'] ?? 0)),
                'staff_type_id' => $subjectType === 'driver' ? (int) ($driverStaffType['id'] ?? 0) : (int) ($data['staff_type_id'] ?? 0),
                'function_name' => $subjectType === 'driver' ? 'Șofer' : trim((string) ($data['function_name'] ?? '')),
                'salary' => $salary,
                'hire_date' => $hireDate,
                'status' => 'active',
                'rehire_eligible' => 1,
            ], $userId);

            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->commit();
            }

            return true;
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    public function getSalaryHistoryForRows(array $rows): array
    {
        $driverIds = [];
        $staffIds = [];

        foreach ($rows as $row) {
            if (($row['source_type'] ?? '') === 'driver') {
                $driverIds[] = (int) ($row['source_id'] ?? 0);
            } elseif (($row['source_type'] ?? '') === 'staff') {
                $staffIds[] = (int) ($row['source_id'] ?? 0);
            }
        }

        $history = [];

        if ($driverIds !== []) {
            $this->appendSalaryHistory($history, 'driver', array_values(array_unique($driverIds)));
        }

        if ($staffIds !== []) {
            $this->appendSalaryHistory($history, 'staff', array_values(array_unique($staffIds)));
        }

        return $history;
    }

    public function getDocumentsForRows(array $rows): array
    {
        $driverIds = [];
        $staffIds = [];

        foreach ($rows as $row) {
            if (($row['source_type'] ?? '') === 'driver') {
                $driverIds[] = (int) ($row['source_id'] ?? 0);
            } elseif (($row['source_type'] ?? '') === 'staff') {
                $staffIds[] = (int) ($row['source_id'] ?? 0);
            }
        }

        $documents = [];

        if ($driverIds !== []) {
            $placeholders = implode(',', array_fill(0, count(array_unique($driverIds)), '?'));
            $ids = array_values(array_unique($driverIds));
            $stmt = $this->db->prepare('
                SELECT
                    d.id,
                    "driver" AS source_type,
                    d.driver_id AS source_id,
                    d.tip_document,
                    d.numar_document,
                    d.data_emitere,
                    d.data_expirare,
                    d.fisier_original,
                    d.fisier_stocat,
                    d.observatii,
                    d.updated_at
                FROM documente_soferi d
                WHERE d.driver_id IN (' . $placeholders . ')
                ORDER BY d.data_expirare IS NULL ASC, d.data_expirare ASC, d.id DESC
            ');
            foreach ($ids as $index => $id) {
                $stmt->bindValue($index + 1, $id, PDO::PARAM_INT);
            }
            $stmt->execute();
            $this->groupDocuments($documents, $stmt->fetchAll());
        }

        if ($staffIds !== []) {
            $placeholders = implode(',', array_fill(0, count(array_unique($staffIds)), '?'));
            $ids = array_values(array_unique($staffIds));
            $stmt = $this->db->prepare('
                SELECT
                    d.id,
                    "staff" AS source_type,
                    d.staff_member_id AS source_id,
                    d.tip_document,
                    d.numar_document,
                    d.data_emitere,
                    d.data_expirare,
                    d.fisier_original,
                    d.fisier_stocat,
                    d.observatii,
                    d.updated_at
                FROM staff_documents d
                WHERE d.staff_member_id IN (' . $placeholders . ')
                ORDER BY d.data_expirare IS NULL ASC, d.data_expirare ASC, d.id DESC
            ');
            foreach ($ids as $index => $id) {
                $stmt->bindValue($index + 1, $id, PDO::PARAM_INT);
            }
            $stmt->execute();
            $this->groupDocuments($documents, $stmt->fetchAll());
        }

        return $documents;
    }

    public function getDocumentTypeOptionsByStaffType(): array
    {
        $map = [];
        $stmt = $this->db->query('
            SELECT st.id AS staff_type_id, r.document_type
            FROM staff_types st
            INNER JOIN staff_document_requirements r ON r.staff_type_id = st.id
            ORDER BY st.id ASC, r.document_type ASC
        ');

        foreach ($stmt->fetchAll() as $row) {
            $typeId = (int) ($row['staff_type_id'] ?? 0);
            $documentType = trim((string) ($row['document_type'] ?? ''));
            if ($typeId <= 0 || $documentType === '') {
                continue;
            }

            if (!isset($map[$typeId])) {
                $map[$typeId] = [];
            }
            $map[$typeId][$documentType] = $documentType;
        }

        return $map;
    }

    public function getDocumentTypeOptionsForSubject(string $sourceType, int $sourceId): array
    {
        $staffTypeId = $this->resolveSubjectStaffTypeId($sourceType, $sourceId);
        if ($staffTypeId <= 0) {
            return [];
        }

        $stmt = $this->db->prepare('
            SELECT document_type
            FROM staff_document_requirements
            WHERE staff_type_id = :staff_type_id
            ORDER BY document_type ASC
        ');
        $stmt->bindValue(':staff_type_id', $staffTypeId, PDO::PARAM_INT);
        $stmt->execute();

        $options = [];
        foreach ($stmt->fetchAll() as $row) {
            $documentType = trim((string) ($row['document_type'] ?? ''));
            if ($documentType !== '') {
                $options[$documentType] = $documentType;
            }
        }

        return $options;
    }

    public function isDocumentTypeAllowedForSubject(string $sourceType, int $sourceId, string $documentType): bool
    {
        $requestedKey = $this->normalizeDocumentTypeKey($documentType);
        if ($requestedKey === '') {
            return false;
        }

        foreach ($this->getDocumentTypeOptionsForSubject($sourceType, $sourceId) as $allowedType) {
            if ($this->normalizeDocumentTypeKey((string) $allowedType) === $requestedKey) {
                return true;
            }
        }

        return false;
    }

    private function resolveSubjectStaffTypeId(string $sourceType, int $sourceId): int
    {
        if ($sourceId <= 0) {
            return 0;
        }

        if ($sourceType === 'driver') {
            $stmt = $this->db->query('
                SELECT id
                FROM staff_types
                WHERE slug = "sofer"
                LIMIT 1
            ');

            return max(0, (int) ($stmt->fetchColumn() ?: 0));
        }

        if ($sourceType === 'staff') {
            $stmt = $this->db->prepare('
                SELECT staff_type_id
                FROM staff_members
                WHERE id = :id
                LIMIT 1
            ');
            $stmt->bindValue(':id', $sourceId, PDO::PARAM_INT);
            $stmt->execute();

            return max(0, (int) ($stmt->fetchColumn() ?: 0));
        }

        return 0;
    }

    public function saveDocument(string $sourceType, int $sourceId, array $data, ?array $fileData, ?int $userId): bool
    {
        if ($sourceType === 'driver') {
            return $this->saveDriverDocument($sourceId, $data, $fileData);
        }

        return $this->saveStaffDocument($sourceId, $data, $fileData, $userId);
    }

    public function deleteDocument(string $sourceType, int $documentId): ?array
    {
        if ($sourceType === 'driver') {
            $stmt = $this->db->prepare('SELECT * FROM documente_soferi WHERE id = :id LIMIT 1');
            $table = 'documente_soferi';
        } else {
            $stmt = $this->db->prepare('SELECT * FROM staff_documents WHERE id = :id LIMIT 1');
            $table = 'staff_documents';
        }

        $stmt->bindValue(':id', $documentId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $deleteStmt = $this->db->prepare('DELETE FROM ' . $table . ' WHERE id = :id');
        $deleteStmt->bindValue(':id', $documentId, PDO::PARAM_INT);
        $deleteStmt->execute();

        return $row;
    }

    public function subjectExists(string $sourceType, int $sourceId): bool
    {
        if ($sourceType === 'driver') {
            return $this->findDriver($sourceId) !== null;
        }

        return $this->findDirectStaff($sourceId) !== null;
    }

    private function baseStaffUnionSql(): string
    {
        $driverDocumentStatus = '
            CASE
                WHEN EXISTS (
                    SELECT 1 FROM documente_soferi dd
                    WHERE dd.driver_id = s.id
                      AND dd.data_expirare IS NOT NULL
                      AND dd.data_expirare < CURDATE()
                ) THEN "expirat"
                WHEN EXISTS (
                    SELECT 1 FROM documente_soferi dd
                    WHERE dd.driver_id = s.id
                      AND dd.data_expirare IS NOT NULL
                      AND dd.data_expirare BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL st.document_warning_days DAY)
                ) THEN "expira_curand"
                WHEN EXISTS (SELECT 1 FROM documente_soferi dd WHERE dd.driver_id = s.id) THEN "valid"
                ELSE "fara_documente"
            END
        ';

        $staffDocumentStatus = '
            CASE
                WHEN EXISTS (
                    SELECT 1 FROM staff_documents sd
                    WHERE sd.staff_member_id = sm.id
                      AND sd.data_expirare IS NOT NULL
                      AND sd.data_expirare < CURDATE()
                ) THEN "expirat"
                WHEN EXISTS (
                    SELECT 1 FROM staff_documents sd
                    WHERE sd.staff_member_id = sm.id
                      AND sd.data_expirare IS NOT NULL
                      AND sd.data_expirare BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL st.document_warning_days DAY)
                ) THEN "expira_curand"
                WHEN EXISTS (SELECT 1 FROM staff_documents sd WHERE sd.staff_member_id = sm.id) THEN "valid"
                ELSE "fara_documente"
            END
        ';

        return '
            SELECT
                "driver" AS source_type,
                "soferi" AS source_module,
                "Șoferi" AS source_label,
                s.id AS source_id,
                CONCAT("driver-", s.id) AS row_key,
                st.id AS staff_type_id,
                st.name AS staff_type_name,
                st.category AS category,
                s.nume AS nume,
                s.telefon AS telefon,
                s.poza_original AS poza_original,
                s.poza_stocata AS poza_stocata,
                NULL AS email,
                "Șofer" AS functie,
                s.salariu AS salariu,
                COALESCE(s.data_angajare, DATE(s.created_at)) AS data_angajare,
                s.data_incetare AS data_incetare,
                s.employment_status AS employment_status,
                COALESCE(s.termination_date, s.data_incetare) AS termination_effective_date,
                s.termination_reason AS termination_reason,
                s.termination_notes AS termination_notes,
                s.last_working_day AS last_working_day,
                s.termination_document_original AS termination_document_original,
                s.termination_document_path AS termination_document_path,
                s.rehire_eligible AS rehire_eligible,
                s.termination_assets_returned AS termination_assets_returned,
                s.terminated_by AS terminated_by,
                s.terminated_at AS terminated_at,
                GREATEST(0, DATEDIFF(
                    COALESCE(s.termination_date, s.data_incetare, CASE WHEN s.status = "inactiv" THEN DATE(s.updated_at) ELSE CURDATE() END),
                    COALESCE(s.data_angajare, DATE(s.created_at))
                ) + 1) AS active_days,
                s.status AS status,
                CASE
                    WHEN v.id IS NULL THEN "-"
                    WHEN vs.id IS NULL THEN v.nr_inmatriculare
                    ELSE CONCAT(v.nr_inmatriculare, " + ", vs.nr_inmatriculare)
                END AS vehicle_label,
                s.observatii AS observatii,
                (
                    (SELECT COUNT(*) FROM documente_soferi dd WHERE dd.driver_id = s.id)
                    + CASE WHEN COALESCE(s.termination_document_path, "") <> "" THEN 1 ELSE 0 END
                ) AS document_count,
                ' . $driverDocumentStatus . ' AS document_status,
                0 AS can_delete,
                s.updated_at AS updated_at
            FROM soferi s
            INNER JOIN staff_types st ON st.slug = "sofer"
            LEFT JOIN vehicule v ON v.id = s.vehicle_id
            LEFT JOIN (SELECT tractor_id, MAX(id) AS last_id FROM vehicule_cuplaje WHERE activ = 1 GROUP BY tractor_id) vc_latest ON vc_latest.tractor_id = v.id
            LEFT JOIN vehicule_cuplaje vc ON vc.id = vc_latest.last_id
            LEFT JOIN vehicule vs ON vs.id = vc.semiremorca_id
            UNION ALL
            SELECT
                "staff" AS source_type,
                "contabilitate_personal" AS source_module,
                "Contabilitate Personal" AS source_label,
                sm.id AS source_id,
                CONCAT("staff-", sm.id) AS row_key,
                st.id AS staff_type_id,
                st.name AS staff_type_name,
                st.category AS category,
                sm.nume_complet AS nume,
                sm.telefon AS telefon,
                NULL AS poza_original,
                NULL AS poza_stocata,
                sm.email AS email,
                sm.functie AS functie,
                sm.salariu AS salariu,
                COALESCE(sm.data_angajare, DATE(sm.created_at)) AS data_angajare,
                sm.data_incetare AS data_incetare,
                sm.employment_status AS employment_status,
                COALESCE(sm.termination_date, sm.data_incetare) AS termination_effective_date,
                sm.termination_reason AS termination_reason,
                sm.termination_notes AS termination_notes,
                sm.last_working_day AS last_working_day,
                sm.termination_document_original AS termination_document_original,
                sm.termination_document_path AS termination_document_path,
                sm.rehire_eligible AS rehire_eligible,
                sm.termination_assets_returned AS termination_assets_returned,
                sm.terminated_by AS terminated_by,
                sm.terminated_at AS terminated_at,
                GREATEST(0, DATEDIFF(
                    COALESCE(sm.termination_date, sm.data_incetare, CASE WHEN sm.status = "inactiv" THEN DATE(sm.updated_at) ELSE CURDATE() END),
                    COALESCE(sm.data_angajare, DATE(sm.created_at))
                ) + 1) AS active_days,
                sm.status AS status,
                "-" AS vehicle_label,
                sm.observatii AS observatii,
                (
                    (SELECT COUNT(*) FROM staff_documents sd WHERE sd.staff_member_id = sm.id)
                    + CASE WHEN COALESCE(sm.termination_document_path, "") <> "" THEN 1 ELSE 0 END
                ) AS document_count,
                ' . $staffDocumentStatus . ' AS document_status,
                st.can_delete_employees AS can_delete,
                sm.updated_at AS updated_at
            FROM staff_members sm
            INNER JOIN staff_types st ON st.id = sm.staff_type_id
            WHERE st.is_driver_linked = 0
        ';
    }

    private function buildStaffWhere(array $filters): array
    {
        $conditions = ['staff.employment_status <> "terminated"'];
        $params = [];

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $conditions[] = '(
                staff.nume LIKE :search_0
                OR COALESCE(staff.telefon, "") LIKE :search_1
                OR COALESCE(staff.email, "") LIKE :search_2
                OR staff.functie LIKE :search_3
                OR staff.staff_type_name LIKE :search_4
                OR staff.vehicle_label LIKE :search_5
            )';
            for ($i = 0; $i <= 5; $i++) {
                $params[':search_' . $i] = '%' . $search . '%';
            }
        }

        $staffTypeId = (int) ($filters['staff_type_id'] ?? 0);
        if ($staffTypeId > 0) {
            $conditions[] = 'staff.staff_type_id = :staff_type_id';
            $params[':staff_type_id'] = $staffTypeId;
        }

        $category = $this->normalizeCategory((string) ($filters['category'] ?? ''));
        if ($category !== '') {
            $conditions[] = 'staff.category = :category';
            $params[':category'] = $category;
        }

        $status = $this->normalizeStatus((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $conditions[] = 'staff.status = :status';
            $params[':status'] = $status;
        }

        $function = trim((string) ($filters['functie'] ?? ''));
        if ($function !== '') {
            $conditions[] = 'staff.functie LIKE :functie';
            $params[':functie'] = '%' . $function . '%';
        }

        $salaryMin = $this->nullableFloat($filters['salary_min'] ?? null);
        if ($salaryMin !== null) {
            $conditions[] = 'COALESCE(staff.salariu, 0) >= :salary_min';
            $params[':salary_min'] = $salaryMin;
        }

        $salaryMax = $this->nullableFloat($filters['salary_max'] ?? null);
        if ($salaryMax !== null) {
            $conditions[] = 'COALESCE(staff.salariu, 0) <= :salary_max';
            $params[':salary_max'] = $salaryMax;
        }

        $documentStatus = trim((string) ($filters['document_status'] ?? ''));
        if (in_array($documentStatus, ['valid', 'expira_curand', 'expirat', 'fara_documente'], true)) {
            $conditions[] = 'staff.document_status = :document_status';
            $params[':document_status'] = $documentStatus;
        }

        return [
            $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions),
            $params,
        ];
    }

    private function formerConditionSql(): string
    {
        return '(staff.employment_status = "terminated" OR staff.termination_effective_date IS NOT NULL)';
    }

    private function buildFormerWhere(array $filters): array
    {
        $conditions = [$this->formerConditionSql()];
        $params = [];

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $conditions[] = '(
                staff.nume LIKE :former_search_0
                OR staff.functie LIKE :former_search_1
                OR staff.staff_type_name LIKE :former_search_2
                OR COALESCE(staff.termination_reason, "") LIKE :former_search_3
                OR staff.source_label LIKE :former_search_4
            )';
            for ($i = 0; $i <= 4; $i++) {
                $params[':former_search_' . $i] = '%' . $search . '%';
            }
        }

        $tab = trim((string) ($filters['tab'] ?? 'all'));
        $personnelType = $this->normalizeCategory((string) ($filters['personnel_type'] ?? ''));
        if ($tab === 'operational') {
            $personnelType = 'operational';
        } elseif ($tab === 'office') {
            $personnelType = 'office';
        }

        if ($personnelType !== '') {
            $conditions[] = 'staff.category = :former_category';
            $params[':former_category'] = $personnelType;
        }

        $reason = trim((string) ($filters['reason'] ?? ''));
        if ($reason !== '') {
            $conditions[] = 'staff.termination_reason = :former_reason';
            $params[':former_reason'] = $reason;
        }

        [$periodStart, $periodEnd] = $this->resolveFormerPeriodRange((string) ($filters['period'] ?? ''), $filters);
        if ($periodStart !== null) {
            $conditions[] = 'staff.termination_effective_date >= :former_date_start';
            $params[':former_date_start'] = $periodStart;
        }
        if ($periodEnd !== null) {
            $conditions[] = 'staff.termination_effective_date <= :former_date_end';
            $params[':former_date_end'] = $periodEnd;
        }

        return [
            'WHERE ' . implode(' AND ', $conditions),
            $params,
        ];
    }

    private function resolveFormerPeriodRange(string $period, array $filters): array
    {
        $period = trim($period);
        $today = new DateTimeImmutable('today');

        if ($period === 'last_30_days') {
            return [$today->modify('-30 days')->format('Y-m-d'), $today->format('Y-m-d')];
        }
        if ($period === 'last_3_months') {
            return [$today->modify('-3 months')->format('Y-m-d'), $today->format('Y-m-d')];
        }
        if ($period === 'last_6_months') {
            return [$today->modify('-6 months')->format('Y-m-d'), $today->format('Y-m-d')];
        }
        if ($period === 'last_12_months') {
            return [$today->modify('-12 months')->format('Y-m-d'), $today->format('Y-m-d')];
        }
        if ($period === 'current_year') {
            return [$today->format('Y') . '-01-01', $today->format('Y-m-d')];
        }
        if ($period === 'previous_year') {
            $year = ((int) $today->format('Y')) - 1;
            return [$year . '-01-01', $year . '-12-31'];
        }
        if ($period === 'custom') {
            $start = $this->normalizeDate((string) ($filters['date_start'] ?? ''));
            $end = $this->normalizeDate((string) ($filters['date_end'] ?? ''));
            return [$start, $end];
        }

        return [null, null];
    }

    private function resolveFormerOrderBy(string $sort, string $direction): string
    {
        $columns = [
            'nume' => 'staff.nume',
            'tip' => 'staff.staff_type_name',
            'functie' => 'staff.functie',
            'data_angajare' => 'staff.data_angajare',
            'data_plecare' => 'staff.termination_effective_date',
            'vechime' => 'staff.active_days',
            'motiv' => 'staff.termination_reason',
            'sursa' => 'staff.source_label',
            'documente' => 'staff.document_count',
        ];

        $column = $columns[$sort] ?? 'staff.termination_effective_date';
        $dir = strtolower($direction) === 'asc' ? 'ASC' : 'DESC';

        return $column . ' ' . $dir . ', staff.nume ASC';
    }

    private function resolveStaffOrderBy(string $sort, string $direction): string
    {
        $columns = [
            'nume' => 'staff.nume',
            'tip' => 'staff.staff_type_name',
            'categorie' => 'staff.category',
            'functie' => 'staff.functie',
            'telefon' => 'staff.telefon',
            'salariu' => 'staff.salariu',
            'data_angajare' => 'staff.data_angajare',
            'data_incetare' => 'staff.data_incetare',
            'active_days' => 'staff.active_days',
            'documente' => 'staff.document_count',
            'status' => 'staff.status',
            'updated_at' => 'staff.updated_at',
        ];

        $column = $columns[$sort] ?? 'staff.updated_at';
        $dir = strtolower($direction) === 'asc' ? 'ASC' : 'DESC';

        return $column . ' ' . $dir . ', staff.nume ASC';
    }

    private function getRequirementsGroupedByType(): array
    {
        $stmt = $this->db->query('
            SELECT *
            FROM staff_document_requirements
            ORDER BY staff_type_id ASC, document_type ASC
        ');
        $map = [];

        foreach ($stmt->fetchAll() as $row) {
            $typeId = (int) ($row['staff_type_id'] ?? 0);
            if ($typeId <= 0) {
                continue;
            }

            if (!isset($map[$typeId])) {
                $map[$typeId] = [];
            }

            $map[$typeId][] = $row;
        }

        return $map;
    }

    private function bindStaffTypeStatement(PDOStatement $stmt, array $data, ?int $userId, string $now): void
    {
        $params = [
            ':name' => trim((string) ($data['name'] ?? '')),
            ':slug' => (string) ($data['slug'] ?? ''),
            ':category' => $this->normalizeCategory((string) ($data['category'] ?? 'operational')) ?: 'operational',
            ':description' => trim((string) ($data['description'] ?? '')) !== '' ? trim((string) ($data['description'] ?? '')) : null,
            ':status' => $this->normalizeStatus((string) ($data['status'] ?? 'activ')) ?: 'activ',
            ':salary_required' => !empty($data['salary_required']) ? 1 : 0,
            ':vehicle_required' => !empty($data['vehicle_required']) ? 1 : 0,
            ':mandatory_documents_enabled' => !empty($data['mandatory_documents_enabled']) ? 1 : 0,
            ':can_create_employees' => !empty($data['can_create_employees']) ? 1 : 0,
            ':can_delete_employees' => !empty($data['can_delete_employees']) ? 1 : 0,
            ':document_warning_days' => $this->normalizeWarningDays((int) ($data['document_warning_days'] ?? 30)),
            ':created_by' => $userId,
            ':updated_by' => $userId,
            ':created_at' => $now,
            ':updated_at' => $now,
        ];

        $this->bindParams($stmt, $params);
    }

    private function bindStaffMemberStatement(PDOStatement $stmt, array $data, ?int $userId, string $now, bool $includeSalary = true): void
    {
        $params = [
            ':staff_type_id' => (int) ($data['staff_type_id'] ?? 0),
            ':nume_complet' => trim((string) ($data['nume_complet'] ?? '')),
            ':telefon' => trim((string) ($data['telefon'] ?? '')) !== '' ? trim((string) ($data['telefon'] ?? '')) : null,
            ':email' => trim((string) ($data['email'] ?? '')) !== '' ? trim((string) ($data['email'] ?? '')) : null,
            ':functie' => trim((string) ($data['functie'] ?? '')),
            ':data_angajare' => trim((string) ($data['data_angajare'] ?? '')) !== '' ? trim((string) ($data['data_angajare'] ?? '')) : null,
            ':status' => $this->normalizeStatus((string) ($data['status'] ?? 'activ')) ?: 'activ',
            ':observatii' => trim((string) ($data['observatii'] ?? '')) !== '' ? trim((string) ($data['observatii'] ?? '')) : null,
            ':updated_by' => $userId,
            ':updated_at' => $now,
        ];

        if ($includeSalary) {
            $params[':salariu'] = $data['salariu'] ?? null;
            $params[':created_by'] = $userId;
            $params[':created_at'] = $now;
        }

        $this->bindParams($stmt, $params);
    }

    private function createSalaryHistory(string $subjectType, int $subjectId, ?float $previousSalary, float $currentSalary, string $effectiveDate, ?int $userId, ?string $notes): void
    {
        $stmt = $this->db->prepare('
            INSERT INTO salary_history (
                subject_type, driver_id, staff_member_id, previous_salary, current_salary,
                effective_date, updated_by, notes, created_at
            ) VALUES (
                :subject_type, :driver_id, :staff_member_id, :previous_salary, :current_salary,
                :effective_date, :updated_by, :notes, :created_at
            )
        ');
        $stmt->bindValue(':subject_type', $subjectType, PDO::PARAM_STR);
        $stmt->bindValue(':driver_id', $subjectType === 'driver' ? $subjectId : null, $subjectType === 'driver' ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':staff_member_id', $subjectType === 'staff' ? $subjectId : null, $subjectType === 'staff' ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':previous_salary', $previousSalary !== null ? (string) $previousSalary : null, $previousSalary !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':current_salary', (string) $currentSalary, PDO::PARAM_STR);
        $stmt->bindValue(':effective_date', $effectiveDate, PDO::PARAM_STR);
        $stmt->bindValue(':updated_by', $userId, $userId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':notes', $notes !== null && trim($notes) !== '' ? trim($notes) : null, $notes !== null && trim($notes) !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':created_at', date('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->execute();
    }

    private function appendSalaryHistory(array &$history, string $subjectType, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $column = $subjectType === 'driver' ? 'driver_id' : 'staff_member_id';
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare('
            SELECT h.*, u.nume AS updated_by_name
            FROM salary_history h
            LEFT JOIN utilizatori u ON u.id = h.updated_by
            WHERE h.subject_type = ?
              AND h.' . $column . ' IN (' . $placeholders . ')
            ORDER BY h.effective_date DESC, h.id DESC
        ');
        $stmt->bindValue(1, $subjectType, PDO::PARAM_STR);
        foreach ($ids as $index => $id) {
            $stmt->bindValue($index + 2, $id, PDO::PARAM_INT);
        }
        $stmt->execute();

        foreach ($stmt->fetchAll() as $row) {
            $sourceId = (int) ($row[$column] ?? 0);
            $history[$subjectType . '-' . $sourceId][] = $row;
        }
    }

    private function groupDocuments(array &$documents, array $rows): void
    {
        foreach ($rows as $row) {
            $sourceType = (string) ($row['source_type'] ?? '');
            $sourceId = (int) ($row['source_id'] ?? 0);
            if ($sourceType === '' || $sourceId <= 0) {
                continue;
            }

            $row['expiration_status'] = $this->documentExpirationStatus($row['data_expirare'] ?? null);
            $documents[$sourceType . '-' . $sourceId][] = $row;
        }
    }

    private function documentExpirationStatus(mixed $date): string
    {
        if (!is_string($date) || trim($date) === '') {
            return 'valid';
        }

        try {
            $today = new DateTime('today');
            $expiry = new DateTime($date);
            $days = (int) $today->diff($expiry)->format('%r%a');
        } catch (Throwable) {
            return 'valid';
        }

        if ($days < 0) {
            return 'expirat';
        }

        if ($days <= 30) {
            return 'expira_curand';
        }

        return 'valid';
    }

    private function saveDriverDocument(int $driverId, array $data, ?array $fileData): bool
    {
        $existingStmt = $this->db->prepare('
            SELECT id, fisier_stocat
            FROM documente_soferi
            WHERE driver_id = :driver_id
              AND tip_document = :tip_document
            LIMIT 1
        ');
        $existingStmt->bindValue(':driver_id', $driverId, PDO::PARAM_INT);
        $existingStmt->bindValue(':tip_document', (string) $data['tip_document'], PDO::PARAM_STR);
        $existingStmt->execute();
        $existing = $existingStmt->fetch();

        $payload = [
            ':driver_id' => $driverId,
            ':tip_document' => (string) $data['tip_document'],
            ':numar_document' => $data['numar_document'] ?? null,
            ':data_emitere' => $data['data_emitere'] ?? null,
            ':data_expirare' => $data['data_expirare'] ?? null,
            ':observatii' => $data['observatii'] ?? null,
            ':updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($existing) {
            $sql = '
                UPDATE documente_soferi
                SET numar_document = :numar_document,
                    data_emitere = :data_emitere,
                    data_expirare = :data_expirare,
                    observatii = :observatii,
                    updated_at = :updated_at';
            if ($fileData !== null) {
                $sql .= ', fisier_original = :fisier_original, fisier_stocat = :fisier_stocat';
                $payload[':fisier_original'] = $fileData['fisier_original'] ?? null;
                $payload[':fisier_stocat'] = $fileData['fisier_stocat'] ?? null;
            }
            $sql .= ' WHERE id = :id';
            unset($payload[':driver_id'], $payload[':tip_document']);
            $payload[':id'] = (int) ($existing['id'] ?? 0);
            $stmt = $this->db->prepare($sql);
        } else {
            $sql = '
                INSERT INTO documente_soferi (
                    driver_id, tip_document, numar_document, data_emitere, data_expirare,
                    fisier_original, fisier_stocat, observatii, created_at, updated_at
                ) VALUES (
                    :driver_id, :tip_document, :numar_document, :data_emitere, :data_expirare,
                    :fisier_original, :fisier_stocat, :observatii, :created_at, :updated_at
                )
            ';
            $payload[':fisier_original'] = $fileData['fisier_original'] ?? null;
            $payload[':fisier_stocat'] = $fileData['fisier_stocat'] ?? null;
            $payload[':created_at'] = date('Y-m-d H:i:s');
            $stmt = $this->db->prepare($sql);
        }

        $this->bindParams($stmt, $payload);
        return $stmt->execute();
    }

    private function saveStaffDocument(int $staffId, array $data, ?array $fileData, ?int $userId): bool
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare('
            INSERT INTO staff_documents (
                staff_member_id, tip_document, numar_document, data_emitere, data_expirare,
                fisier_original, fisier_stocat, observatii, created_by, updated_by, created_at, updated_at
            ) VALUES (
                :staff_member_id, :tip_document, :numar_document, :data_emitere, :data_expirare,
                :fisier_original, :fisier_stocat, :observatii, :created_by, :updated_by, :created_at, :updated_at
            )
        ');
        $this->bindParams($stmt, [
            ':staff_member_id' => $staffId,
            ':tip_document' => (string) $data['tip_document'],
            ':numar_document' => $data['numar_document'] ?? null,
            ':data_emitere' => $data['data_emitere'] ?? null,
            ':data_expirare' => $data['data_expirare'] ?? null,
            ':fisier_original' => $fileData['fisier_original'] ?? null,
            ':fisier_stocat' => $fileData['fisier_stocat'] ?? null,
            ':observatii' => $data['observatii'] ?? null,
            ':created_by' => $userId,
            ':updated_by' => $userId,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return $stmt->execute();
    }

    private function upsertDriverRequiredDocument(string $documentType, bool $requiresExpiry): void
    {
        if ($this->isEmploymentContractDocument($documentType)) {
            return;
        }

        $stmt = $this->db->prepare('
            INSERT INTO configurare_documente_obligatorii_soferi (
                document_type, requires_expiry, created_at, updated_at
            ) VALUES (
                :document_type, :requires_expiry, :created_at, :updated_at
            )
            ON DUPLICATE KEY UPDATE
                requires_expiry = VALUES(requires_expiry),
                updated_at = VALUES(updated_at)
        ');
        $stmt->bindValue(':document_type', $documentType, PDO::PARAM_STR);
        $stmt->bindValue(':requires_expiry', $requiresExpiry ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':created_at', date('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->execute();
    }

    private function deleteDriverEmploymentContractRequirement(): void
    {
        $stmt = $this->db->prepare('
            DELETE FROM configurare_documente_obligatorii_soferi
            WHERE document_type IN (:document_type, :document_type_ascii, :document_type_legacy)
        ');
        $stmt->execute([
            ':document_type' => self::EMPLOYMENT_CONTRACT_DOCUMENT,
            ':document_type_ascii' => 'Contract de munca',
            ':document_type_legacy' => 'Contract de angajare',
        ]);
    }

    private function ensureEmploymentLifecycleSchema(): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        if ($this->tableExists('soferi') && !$this->columnExists('soferi', 'data_incetare')) {
            $this->execSchemaChangeIgnoringDuplicateColumn('ALTER TABLE soferi ADD COLUMN data_incetare DATE NULL AFTER data_angajare');
        }

        if ($this->tableExists('staff_members') && !$this->columnExists('staff_members', 'data_incetare')) {
            $this->execSchemaChangeIgnoringDuplicateColumn('ALTER TABLE staff_members ADD COLUMN data_incetare DATE NULL AFTER data_angajare');
        }

        foreach (['soferi', 'staff_members'] as $table) {
            if (!$this->tableExists($table)) {
                continue;
            }

            $this->ensureLifecycleColumn($table, 'employment_status', "ENUM('active','temporarily_inactive','suspended','leave','terminated') NOT NULL DEFAULT 'active'", 'data_incetare');
            $this->ensureLifecycleColumn($table, 'termination_date', 'DATE NULL', 'employment_status');
            $this->ensureLifecycleColumn($table, 'termination_reason', 'VARCHAR(120) NULL', 'termination_date');
            $this->ensureLifecycleColumn($table, 'termination_notes', 'TEXT NULL', 'termination_reason');
            $this->ensureLifecycleColumn($table, 'last_working_day', 'DATE NULL', 'termination_notes');
            $this->ensureLifecycleColumn($table, 'termination_document_original', 'VARCHAR(255) NULL', 'last_working_day');
            $this->ensureLifecycleColumn($table, 'termination_document_path', 'VARCHAR(255) NULL', 'termination_document_original');
            $this->ensureLifecycleColumn($table, 'rehire_eligible', 'TINYINT(1) NOT NULL DEFAULT 1', 'termination_document_path');
            $this->ensureLifecycleColumn($table, 'termination_assets_returned', 'TINYINT(1) NOT NULL DEFAULT 0', 'rehire_eligible');
            $this->ensureLifecycleColumn($table, 'terminated_by', 'INT UNSIGNED NULL', 'termination_assets_returned');
            $this->ensureLifecycleColumn($table, 'terminated_at', 'DATETIME NULL', 'terminated_by');

            $this->db->exec("
                UPDATE {$table}
                SET employment_status = 'terminated',
                    termination_date = COALESCE(termination_date, data_incetare)
                WHERE data_incetare IS NOT NULL
            ");
            $this->db->exec("
                UPDATE {$table}
                SET employment_status = 'active'
                WHERE data_incetare IS NULL
                  AND employment_status = 'terminated'
            ");
        }

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS employee_employment_periods (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                subject_type ENUM('driver', 'staff') NOT NULL,
                driver_id INT UNSIGNED NULL,
                staff_member_id INT UNSIGNED NULL,
                source_module ENUM('soferi', 'contabilitate_personal') NOT NULL,
                personnel_type ENUM('operational', 'office') NOT NULL DEFAULT 'operational',
                staff_type_id INT UNSIGNED NULL,
                function_name VARCHAR(120) NULL,
                salary DECIMAL(10,2) NULL,
                hire_date DATE NULL,
                last_working_day DATE NULL,
                termination_date DATE NULL,
                termination_reason VARCHAR(120) NULL,
                termination_notes TEXT NULL,
                status ENUM('active', 'terminated') NOT NULL DEFAULT 'active',
                rehire_eligible TINYINT(1) NOT NULL DEFAULT 1,
                created_by INT UNSIGNED NULL,
                updated_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_employee_period_driver (driver_id, status, hire_date),
                INDEX idx_employee_period_staff (staff_member_id, status, hire_date),
                INDEX idx_employee_period_subject (subject_type, status),
                CONSTRAINT fk_employee_period_driver FOREIGN KEY (driver_id) REFERENCES soferi(id) ON DELETE CASCADE,
                CONSTRAINT fk_employee_period_staff FOREIGN KEY (staff_member_id) REFERENCES staff_members(id) ON DELETE CASCADE,
                CONSTRAINT fk_employee_period_staff_type FOREIGN KEY (staff_type_id) REFERENCES staff_types(id) ON DELETE SET NULL,
                CONSTRAINT fk_employee_period_created_by FOREIGN KEY (created_by) REFERENCES utilizatori(id) ON DELETE SET NULL,
                CONSTRAINT fk_employee_period_updated_by FOREIGN KEY (updated_by) REFERENCES utilizatori(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->backfillEmploymentPeriods();
        $ensured = true;
    }

    private function ensureLifecycleColumn(string $table, string $column, string $definition, string $afterColumn): void
    {
        if ($this->columnExists($table, $column)) {
            return;
        }

        $afterSql = $this->columnExists($table, $afterColumn) ? ' AFTER ' . $afterColumn : '';
        $this->execSchemaChangeIgnoringDuplicateColumn('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition . $afterSql);
    }

    private function backfillEmploymentPeriods(): void
    {
        if (!$this->tableExists('employee_employment_periods') || !$this->tableExists('staff_types')) {
            return;
        }

        $this->db->exec("
            INSERT INTO employee_employment_periods (
                subject_type, driver_id, staff_member_id, source_module, personnel_type, staff_type_id,
                function_name, salary, hire_date, last_working_day, termination_date, termination_reason,
                termination_notes, status, rehire_eligible, created_at, updated_at
            )
            SELECT
                'driver',
                s.id,
                NULL,
                'soferi',
                'operational',
                st.id,
                'Șofer',
                s.salariu,
                COALESCE(s.data_angajare, DATE(s.created_at)),
                s.last_working_day,
                COALESCE(s.termination_date, s.data_incetare),
                s.termination_reason,
                s.termination_notes,
                CASE WHEN s.employment_status = 'terminated' OR s.data_incetare IS NOT NULL THEN 'terminated' ELSE 'active' END,
                s.rehire_eligible,
                COALESCE(s.created_at, NOW()),
                COALESCE(s.updated_at, NOW())
            FROM soferi s
            INNER JOIN staff_types st ON st.slug = 'sofer'
            WHERE NOT EXISTS (
                SELECT 1
                FROM employee_employment_periods ep
                WHERE ep.subject_type = 'driver'
                  AND ep.driver_id = s.id
            )
        ");

        $this->db->exec("
            INSERT INTO employee_employment_periods (
                subject_type, driver_id, staff_member_id, source_module, personnel_type, staff_type_id,
                function_name, salary, hire_date, last_working_day, termination_date, termination_reason,
                termination_notes, status, rehire_eligible, created_at, updated_at
            )
            SELECT
                'staff',
                NULL,
                sm.id,
                'contabilitate_personal',
                st.category,
                sm.staff_type_id,
                sm.functie,
                sm.salariu,
                COALESCE(sm.data_angajare, DATE(sm.created_at)),
                sm.last_working_day,
                COALESCE(sm.termination_date, sm.data_incetare),
                sm.termination_reason,
                sm.termination_notes,
                CASE WHEN sm.employment_status = 'terminated' OR sm.data_incetare IS NOT NULL THEN 'terminated' ELSE 'active' END,
                sm.rehire_eligible,
                COALESCE(sm.created_at, NOW()),
                COALESCE(sm.updated_at, NOW())
            FROM staff_members sm
            INNER JOIN staff_types st ON st.id = sm.staff_type_id
            WHERE st.is_driver_linked = 0
              AND NOT EXISTS (
                SELECT 1
                FROM employee_employment_periods ep
                WHERE ep.subject_type = 'staff'
                  AND ep.staff_member_id = sm.id
            )
        ");
    }

    private function ensureEmploymentContractRequirements(): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }
        if (!$this->tableExists('staff_types') || !$this->tableExists('staff_document_requirements')) {
            return;
        }

        $stmt = $this->db->prepare('
            INSERT INTO staff_document_requirements (
                staff_type_id, document_type, requires_expiry, warning_days, created_at, updated_at
            )
            SELECT st.id, :document_type_insert, 0, 30, NOW(), NOW()
            FROM staff_types st
            ON DUPLICATE KEY UPDATE
                requires_expiry = 0
        ');
        $stmt->execute([
            ':document_type_insert' => self::EMPLOYMENT_CONTRACT_DOCUMENT,
        ]);

        if ($this->tableExists('configurare_documente_obligatorii_soferi')) {
            $this->deleteDriverEmploymentContractRequirement();
        }
        $ensured = true;
    }

    private function assertEndDateAfterHireDate(array $row, string $endDate): void
    {
        if (trim($endDate) === '') {
            throw new InvalidArgumentException('Data plecării este obligatorie.');
        }

        $hireDate = $this->effectiveHireDate($row);
        if ($hireDate !== '' && $endDate < $hireDate) {
            throw new InvalidArgumentException('Data încetării nu poate fi înainte de data angajării.');
        }
    }

    private function effectiveHireDate(array $row): string
    {
        return trim((string) ($row['data_angajare'] ?? ''));
    }

    private function appendEmploymentEndNote(mixed $existingNotes, string $endDate, ?string $notes): string
    {
        $existing = trim((string) ($existingNotes ?? ''));
        $line = 'Incetare activitate: ' . $endDate . '.';
        if ($notes !== null && trim($notes) !== '') {
            $line .= ' ' . trim($notes);
        }

        return $existing !== '' ? $existing . "\n" . $line : $line;
    }

    private function appendRehireNote(mixed $existingNotes, string $hireDate, mixed $notes): string
    {
        $existing = trim((string) ($existingNotes ?? ''));
        $line = 'Reangajare: ' . $hireDate . '.';
        if (is_scalar($notes) && trim((string) $notes) !== '') {
            $line .= ' ' . trim((string) $notes);
        }

        return $existing !== '' ? $existing . "\n" . $line : $line;
    }

    private function terminateSubject(string $subjectType, int $subjectId, array $existing, array $termination, ?int $userId): bool
    {
        $startedTransaction = !$this->db->inTransaction();
        if ($startedTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $this->closeCurrentEmploymentPeriod($subjectType, $subjectId, $existing, $termination, $userId);
            $this->updateSubjectTerminationColumns($subjectType, $subjectId, $termination, $termination['fileData'] ?? null, $userId);

            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->commit();
            }

            return true;
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    private function updateSubjectTerminationColumns(string $subjectType, int $subjectId, array $data, ?array $fileData, ?int $userId): void
    {
        $terminationDate = (string) ($data['termination_date'] ?? '');
        $terminationNotes = isset($data['termination_notes']) ? trim((string) $data['termination_notes']) : '';
        $params = [
            ':employment_status' => 'terminated',
            ':data_incetare' => $terminationDate,
            ':termination_date' => $terminationDate,
            ':termination_reason' => trim((string) ($data['termination_reason'] ?? '')),
            ':termination_notes' => $terminationNotes !== '' ? $terminationNotes : null,
            ':last_working_day' => trim((string) ($data['last_working_day'] ?? '')) !== '' ? trim((string) ($data['last_working_day'] ?? '')) : null,
            ':rehire_eligible' => !empty($data['rehire_eligible']) ? 1 : 0,
            ':termination_assets_returned' => !empty($data['termination_assets_returned']) ? 1 : 0,
            ':terminated_by' => $userId,
            ':terminated_at' => date('Y-m-d H:i:s'),
            ':updated_at' => date('Y-m-d H:i:s'),
            ':id' => $subjectId,
        ];

        $documentSql = '';
        if (is_array($fileData)) {
            $documentSql = ',
                termination_document_original = :termination_document_original,
                termination_document_path = :termination_document_path';
            $params[':termination_document_original'] = $fileData['fisier_original'] ?? null;
            $params[':termination_document_path'] = $fileData['fisier_stocat'] ?? null;
        }

        if ($subjectType === 'driver') {
            $existing = $this->findDriver($subjectId) ?? [];
            $sql = '
                UPDATE soferi
                SET status = "inactiv",
                    employment_status = :employment_status,
                    data_incetare = :data_incetare,
                    termination_date = :termination_date,
                    termination_reason = :termination_reason,
                    termination_notes = :termination_notes,
                    last_working_day = :last_working_day,
                    rehire_eligible = :rehire_eligible,
                    termination_assets_returned = :termination_assets_returned,
                    terminated_by = :terminated_by,
                    terminated_at = :terminated_at,
                    observatii = :observatii,
                    updated_at = :updated_at' . $documentSql . '
                WHERE id = :id
            ';
            $params[':observatii'] = ($data['append_observatii'] ?? true)
                ? $this->appendEmploymentEndNote($existing['observatii'] ?? null, $terminationDate, $terminationNotes)
                : ($existing['observatii'] ?? null);
        } else {
            $existing = $this->findDirectStaff($subjectId) ?? [];
            $sql = '
                UPDATE staff_members
                SET status = "inactiv",
                    employment_status = :employment_status,
                    data_incetare = :data_incetare,
                    termination_date = :termination_date,
                    termination_reason = :termination_reason,
                    termination_notes = :termination_notes,
                    last_working_day = :last_working_day,
                    rehire_eligible = :rehire_eligible,
                    termination_assets_returned = :termination_assets_returned,
                    terminated_by = :terminated_by,
                    terminated_at = :terminated_at,
                    observatii = :observatii,
                    updated_by = :updated_by,
                    updated_at = :updated_at' . $documentSql . '
                WHERE id = :id
            ';
            $params[':observatii'] = ($data['append_observatii'] ?? true)
                ? $this->appendEmploymentEndNote($existing['observatii'] ?? null, $terminationDate, $terminationNotes)
                : ($existing['observatii'] ?? null);
            $params[':updated_by'] = $userId;
        }

        $stmt = $this->db->prepare($sql);
        $this->bindParams($stmt, $params);
        $stmt->execute();
    }

    private function closeCurrentEmploymentPeriod(string $subjectType, int $subjectId, array $existing, array $termination, ?int $userId): void
    {
        $column = $subjectType === 'driver' ? 'driver_id' : 'staff_member_id';
        $stmt = $this->db->prepare('
            SELECT id
            FROM employee_employment_periods
            WHERE subject_type = :subject_type
              AND ' . $column . ' = :subject_id
              AND status = "active"
            ORDER BY COALESCE(hire_date, "1000-01-01") DESC, id DESC
            LIMIT 1
        ');
        $this->bindParams($stmt, [
            ':subject_type' => $subjectType,
            ':subject_id' => $subjectId,
        ]);
        $stmt->execute();
        $periodId = (int) ($stmt->fetchColumn() ?: 0);

        if ($periodId <= 0) {
            $periodId = $this->createEmploymentPeriod($subjectType, $subjectId, $this->periodPayloadFromSubject($subjectType, $existing, $termination), $userId);
        }

        $updateStmt = $this->db->prepare('
            UPDATE employee_employment_periods
            SET last_working_day = :last_working_day,
                termination_date = :termination_date,
                termination_reason = :termination_reason,
                termination_notes = :termination_notes,
                status = "terminated",
                rehire_eligible = :rehire_eligible,
                updated_by = :updated_by,
                updated_at = :updated_at
            WHERE id = :id
        ');
        $this->bindParams($updateStmt, [
            ':last_working_day' => trim((string) ($termination['last_working_day'] ?? '')) !== '' ? trim((string) ($termination['last_working_day'] ?? '')) : null,
            ':termination_date' => (string) ($termination['termination_date'] ?? ''),
            ':termination_reason' => trim((string) ($termination['termination_reason'] ?? '')),
            ':termination_notes' => trim((string) ($termination['termination_notes'] ?? '')) !== '' ? trim((string) ($termination['termination_notes'] ?? '')) : null,
            ':rehire_eligible' => !empty($termination['rehire_eligible']) ? 1 : 0,
            ':updated_by' => $userId,
            ':updated_at' => date('Y-m-d H:i:s'),
            ':id' => $periodId,
        ]);
        $updateStmt->execute();
    }

    private function syncLatestTerminatedEmploymentPeriod(string $subjectType, int $subjectId, array $data, ?int $userId): void
    {
        $column = $subjectType === 'driver' ? 'driver_id' : 'staff_member_id';
        $stmt = $this->db->prepare('
            SELECT id
            FROM employee_employment_periods
            WHERE subject_type = :subject_type
              AND ' . $column . ' = :subject_id
              AND status = "terminated"
            ORDER BY COALESCE(termination_date, "1000-01-01") DESC, id DESC
            LIMIT 1
        ');
        $this->bindParams($stmt, [
            ':subject_type' => $subjectType,
            ':subject_id' => $subjectId,
        ]);
        $stmt->execute();
        $periodId = (int) ($stmt->fetchColumn() ?: 0);
        if ($periodId <= 0) {
            return;
        }

        $updateStmt = $this->db->prepare('
            UPDATE employee_employment_periods
            SET last_working_day = :last_working_day,
                termination_date = :termination_date,
                termination_reason = :termination_reason,
                termination_notes = :termination_notes,
                rehire_eligible = :rehire_eligible,
                updated_by = :updated_by,
                updated_at = :updated_at
            WHERE id = :id
        ');
        $this->bindParams($updateStmt, [
            ':last_working_day' => trim((string) ($data['last_working_day'] ?? '')) !== '' ? trim((string) ($data['last_working_day'] ?? '')) : null,
            ':termination_date' => (string) ($data['termination_date'] ?? ''),
            ':termination_reason' => trim((string) ($data['termination_reason'] ?? '')),
            ':termination_notes' => trim((string) ($data['termination_notes'] ?? '')) !== '' ? trim((string) ($data['termination_notes'] ?? '')) : null,
            ':rehire_eligible' => !empty($data['rehire_eligible']) ? 1 : 0,
            ':updated_by' => $userId,
            ':updated_at' => date('Y-m-d H:i:s'),
            ':id' => $periodId,
        ]);
        $updateStmt->execute();
    }

    private function createEmploymentPeriod(string $subjectType, int $subjectId, array $payload, ?int $userId): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO employee_employment_periods (
                subject_type, driver_id, staff_member_id, source_module, personnel_type, staff_type_id,
                function_name, salary, hire_date, last_working_day, termination_date, termination_reason,
                termination_notes, status, rehire_eligible, created_by, updated_by, created_at, updated_at
            ) VALUES (
                :subject_type, :driver_id, :staff_member_id, :source_module, :personnel_type, :staff_type_id,
                :function_name, :salary, :hire_date, :last_working_day, :termination_date, :termination_reason,
                :termination_notes, :status, :rehire_eligible, :created_by, :updated_by, :created_at, :updated_at
            )
        ');
        $staffTypeId = (int) ($payload['staff_type_id'] ?? 0);
        $this->bindParams($stmt, [
            ':subject_type' => $subjectType,
            ':driver_id' => $subjectType === 'driver' ? $subjectId : null,
            ':staff_member_id' => $subjectType === 'staff' ? $subjectId : null,
            ':source_module' => (string) ($payload['source_module'] ?? ($subjectType === 'driver' ? 'soferi' : 'contabilitate_personal')),
            ':personnel_type' => $this->normalizeCategory((string) ($payload['personnel_type'] ?? 'operational')) ?: 'operational',
            ':staff_type_id' => $staffTypeId > 0 ? $staffTypeId : null,
            ':function_name' => trim((string) ($payload['function_name'] ?? '')) !== '' ? trim((string) ($payload['function_name'] ?? '')) : null,
            ':salary' => $payload['salary'] ?? null,
            ':hire_date' => trim((string) ($payload['hire_date'] ?? '')) !== '' ? trim((string) ($payload['hire_date'] ?? '')) : null,
            ':last_working_day' => trim((string) ($payload['last_working_day'] ?? '')) !== '' ? trim((string) ($payload['last_working_day'] ?? '')) : null,
            ':termination_date' => trim((string) ($payload['termination_date'] ?? '')) !== '' ? trim((string) ($payload['termination_date'] ?? '')) : null,
            ':termination_reason' => trim((string) ($payload['termination_reason'] ?? '')) !== '' ? trim((string) ($payload['termination_reason'] ?? '')) : null,
            ':termination_notes' => trim((string) ($payload['termination_notes'] ?? '')) !== '' ? trim((string) ($payload['termination_notes'] ?? '')) : null,
            ':status' => (string) ($payload['status'] ?? 'active') === 'terminated' ? 'terminated' : 'active',
            ':rehire_eligible' => !empty($payload['rehire_eligible']) ? 1 : 0,
            ':created_by' => $userId,
            ':updated_by' => $userId,
            ':created_at' => date('Y-m-d H:i:s'),
            ':updated_at' => date('Y-m-d H:i:s'),
        ]);
        $stmt->execute();

        return (int) $this->db->lastInsertId();
    }

    private function periodPayloadFromSubject(string $subjectType, array $existing, array $termination = []): array
    {
        $staffTypeId = null;
        $category = 'operational';
        $functionName = 'Șofer';
        if ($subjectType === 'driver') {
            $driverType = $this->findDriverStaffType();
            $staffTypeId = $driverType !== null ? (int) ($driverType['id'] ?? 0) : null;
        } else {
            $staffTypeId = (int) ($existing['staff_type_id'] ?? 0);
            $category = $this->categoryForStaffType($staffTypeId);
            $functionName = trim((string) ($existing['functie'] ?? ''));
        }

        return [
            'source_module' => $subjectType === 'driver' ? 'soferi' : 'contabilitate_personal',
            'personnel_type' => $category,
            'staff_type_id' => $staffTypeId,
            'function_name' => $functionName,
            'salary' => $existing['salariu'] ?? null,
            'hire_date' => $this->effectiveHireDate($existing),
            'last_working_day' => $termination['last_working_day'] ?? null,
            'termination_date' => $termination['termination_date'] ?? null,
            'termination_reason' => $termination['termination_reason'] ?? null,
            'termination_notes' => $termination['termination_notes'] ?? null,
            'status' => !empty($termination['termination_date']) ? 'terminated' : 'active',
            'rehire_eligible' => !array_key_exists('rehire_eligible', $termination) || !empty($termination['rehire_eligible']) ? 1 : 0,
        ];
    }

    private function findSubjectInUnion(string $sourceType, int $sourceId, bool $formerOnly = false): ?array
    {
        if (!in_array($sourceType, ['driver', 'staff'], true) || $sourceId <= 0) {
            return null;
        }

        $conditions = [
            'staff.source_type = :source_type',
            'staff.source_id = :source_id',
        ];
        if ($formerOnly) {
            $conditions[] = $this->formerConditionSql();
        }

        $stmt = $this->db->prepare('
            SELECT *
            FROM (' . $this->baseStaffUnionSql() . ') staff
            WHERE ' . implode(' AND ', $conditions) . '
            LIMIT 1
        ');
        $this->bindParams($stmt, [
            ':source_type' => $sourceType,
            ':source_id' => $sourceId,
        ]);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row ?: null;
    }

    private function getEmploymentPeriodsForSubject(string $sourceType, int $sourceId): array
    {
        $column = $sourceType === 'driver' ? 'driver_id' : 'staff_member_id';
        if (!in_array($sourceType, ['driver', 'staff'], true) || $sourceId <= 0) {
            return [];
        }

        $stmt = $this->db->prepare('
            SELECT ep.*, st.name AS staff_type_name, u.nume AS updated_by_name
            FROM employee_employment_periods ep
            LEFT JOIN staff_types st ON st.id = ep.staff_type_id
            LEFT JOIN utilizatori u ON u.id = ep.updated_by
            WHERE ep.subject_type = :subject_type
              AND ep.' . $column . ' = :subject_id
            ORDER BY COALESCE(ep.hire_date, "1000-01-01") DESC, ep.id DESC
        ');
        $this->bindParams($stmt, [
            ':subject_type' => $sourceType,
            ':subject_id' => $sourceId,
        ]);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    private function getDriverOperationalHistory(int $driverId): array
    {
        $history = $this->emptyDriverOperationalHistory();
        if ($driverId <= 0) {
            return $history;
        }

        if ($this->tableExists('curse_dispecer')) {
            try {
                $stmt = $this->db->prepare('
                    SELECT c.id, COALESCE(c.data_inceput, c.data_cursa) AS data_start,
                           COALESCE(c.data_sfarsit, c.data_inceput, c.data_cursa) AS data_end,
                           c.tip_transport, c.status_facturare, v.nr_inmatriculare AS vehicle_label
                    FROM curse_dispecer c
                    LEFT JOIN vehicule v ON v.id = c.vehicle_id
                    WHERE c.driver_id = :driver_id
                      AND ' . $this->activeRaceCondition('c') . '
                    ORDER BY COALESCE(c.data_inceput, c.data_cursa) DESC, c.id DESC
                    LIMIT 8
                ');
                $stmt->bindValue(':driver_id', $driverId, PDO::PARAM_INT);
                $stmt->execute();
                $history['trips'] = $stmt->fetchAll();
            } catch (Throwable $exception) {
                error_log('[StaffAccountancyModel][driver_trips_history] ' . $exception->getMessage());
            }
        }

        if ($this->tableExists('concedii')) {
            try {
                $stmt = $this->db->prepare('
                    SELECT id, tip_concediu, data_inceput, data_sfarsit, status
                    FROM concedii
                    WHERE driver_id = :driver_id
                    ORDER BY data_inceput DESC, id DESC
                    LIMIT 8
                ');
                $stmt->bindValue(':driver_id', $driverId, PDO::PARAM_INT);
                $stmt->execute();
                $history['leaves'] = $stmt->fetchAll();
            } catch (Throwable $exception) {
                error_log('[StaffAccountancyModel][driver_leave_history] ' . $exception->getMessage());
            }
        }

        if ($this->tableExists('soferi_vehicule')) {
            try {
                $stmt = $this->db->prepare('
                    SELECT sv.vehicle_id, sv.is_primary, sv.created_at, sv.updated_at,
                           v.nr_inmatriculare, v.marca, v.model
                    FROM soferi_vehicule sv
                    LEFT JOIN vehicule v ON v.id = sv.vehicle_id
                    WHERE sv.driver_id = :driver_id
                    ORDER BY sv.is_primary DESC, sv.updated_at DESC, sv.vehicle_id ASC
                ');
                $stmt->bindValue(':driver_id', $driverId, PDO::PARAM_INT);
                $stmt->execute();
                $history['vehicle_assignments'] = $stmt->fetchAll();
            } catch (Throwable $exception) {
                error_log('[StaffAccountancyModel][driver_vehicle_history] ' . $exception->getMessage());
            }
        }

        return $history;
    }

    private function emptyDriverOperationalHistory(): array
    {
        return [
            'trips' => [],
            'leaves' => [],
            'vehicle_assignments' => [],
        ];
    }

    private function categoryForStaffType(int $staffTypeId): string
    {
        $type = $this->findStaffType($staffTypeId);
        $category = is_array($type) ? $this->normalizeCategory((string) ($type['category'] ?? '')) : '';

        return $category !== '' ? $category : 'office';
    }

    private function assertLastWorkingDayIsValid(array $row, string $endDate, ?string $lastWorkingDay): void
    {
        if ($lastWorkingDay === null || trim($lastWorkingDay) === '') {
            return;
        }

        $hireDate = $this->effectiveHireDate($row);
        if ($hireDate !== '' && $lastWorkingDay < $hireDate) {
            throw new InvalidArgumentException('Ultima zi lucrată nu poate fi înainte de data angajării.');
        }
        if ($lastWorkingDay > $endDate) {
            throw new InvalidArgumentException('Ultima zi lucrată nu poate fi după data plecării.');
        }
    }

    private function isEmploymentContractDocument(string $documentType): bool
    {
        $normalized = $this->normalizeDocumentTypeKey($documentType);

        return in_array($normalized, ['contractdemunca', 'contractdeangajare'], true);
    }

    private function normalizeDocumentTypeKey(string $documentType): string
    {
        $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $documentType) : false;
        $base = is_string($ascii) && trim($ascii) !== '' ? $ascii : $documentType;
        return (string) preg_replace('/[^a-z0-9]+/', '', strtolower($base));
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->db->prepare('
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
        ');
        $stmt->execute([':table_name' => $table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function activeRaceCondition(string $alias = 'c'): string
    {
        static $hasDeletedAt = null;

        if ($hasDeletedAt === null) {
            $hasDeletedAt = $this->columnExists('curse_dispecer', 'deleted_at');
        }

        return $hasDeletedAt ? $alias . '.deleted_at IS NULL' : '1=1';
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->db->prepare('
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND COLUMN_NAME = :column_name
        ');
        $stmt->execute([':table_name' => $table, ':column_name' => $column]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function execSchemaChangeIgnoringDuplicateColumn(string $sql): void
    {
        try {
            $this->db->exec($sql);
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1060) {
                throw $exception;
            }
        }
    }

    private function normalizeCategory(string $category): string
    {
        $category = strtolower(trim($category));
        return in_array($category, ['operational', 'office'], true) ? $category : '';
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        return in_array($status, ['activ', 'inactiv'], true) ? $status : '';
    }

    private function normalizeWarningDays(int $days): int
    {
        return in_array($days, [30, 60, 90], true) ? $days : 30;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $raw = trim(str_replace(',', '.', (string) $value));
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }

        return (float) $raw;
    }

    private function slugify(string $name): string
    {
        $name = trim($name);
        $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) : false;
        $base = is_string($ascii) && trim($ascii) !== '' ? $ascii : $name;
        $base = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $base));
        $base = trim($base, '-');

        return $base !== '' ? $base : 'tip-personal';
    }

    private function uniqueSlug(string $slug): string
    {
        $base = $slug;
        $index = 2;

        while ($this->slugExists($slug)) {
            $slug = $base . '-' . $index;
            $index++;
        }

        return $slug;
    }

    private function slugExists(string $slug): bool
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM staff_types WHERE slug = :slug');
        $stmt->bindValue(':slug', $slug, PDO::PARAM_STR);
        $stmt->execute();

        return (int) $stmt->fetchColumn() > 0;
    }

    private function bindParams(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            if (is_int($value)) {
                $stmt->bindValue($key, $value, PDO::PARAM_INT);
            } elseif ($value === null) {
                $stmt->bindValue($key, null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue($key, (string) $value, PDO::PARAM_STR);
            }
        }
    }
}
