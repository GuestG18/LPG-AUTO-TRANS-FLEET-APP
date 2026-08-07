<?php
declare(strict_types=1);

class InactiveResourceStatusService
{
    private const VEHICLE_REASON_DEFINITIONS = [
        'documents_mixed' => ['label' => 'Documente lipsa & expirate', 'tone' => 'danger', 'icon' => 'bi-file-earmark-x'],
        'expired_documents' => ['label' => 'Documente expirate', 'tone' => 'danger', 'icon' => 'bi-file-earmark-x'],
        'missing_documents' => ['label' => 'Documente lipsa', 'tone' => 'danger', 'icon' => 'bi-file-earmark-excel'],
        'repair' => ['label' => 'In reparatie', 'tone' => 'warning', 'icon' => 'bi-tools'],
        'manual_inactive' => ['label' => 'Dezactivat manual', 'tone' => 'muted', 'icon' => 'bi-slash-circle'],
        'other' => ['label' => 'Alt motiv', 'tone' => 'muted', 'icon' => 'bi-exclamation-circle'],
    ];

    private const DRIVER_REASON_DEFINITIONS = [
        'documents_mixed' => ['label' => 'Documente lipsa & expirate', 'tone' => 'danger', 'icon' => 'bi-file-earmark-x'],
        'expired_documents' => ['label' => 'Documente expirate', 'tone' => 'danger', 'icon' => 'bi-file-earmark-x'],
        'missing_documents' => ['label' => 'Documente lipsa', 'tone' => 'danger', 'icon' => 'bi-file-earmark-excel'],
        'medical_leave' => ['label' => 'Concediu medical', 'tone' => 'warning', 'icon' => 'bi-prescription2'],
        'leave' => ['label' => 'Concediu', 'tone' => 'success', 'icon' => 'bi-calendar2-check'],
        'manual_inactive' => ['label' => 'Inactiv', 'tone' => 'muted', 'icon' => 'bi-slash-circle'],
        'other' => ['label' => 'Alt motiv', 'tone' => 'muted', 'icon' => 'bi-exclamation-circle'],
    ];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function getResourcesStatus(?int $vehicleId, ?int $driverId): array
    {
        $resources = [];

        if ($vehicleId !== null && $vehicleId > 0) {
            $vehicleStatus = $this->getVehicleStatus($vehicleId);
            if ($vehicleStatus !== null) {
                $resources[] = $vehicleStatus;
            }
        }

        if ($driverId !== null && $driverId > 0) {
            $driverStatus = $this->getDriverStatus($driverId);
            if ($driverStatus !== null) {
                $resources[] = $driverStatus;
            }
        }

        $inactiveResources = array_values(array_filter(
            $resources,
            static fn(array $resource): bool => !empty($resource['is_inactive'])
        ));

        return [
            'resources' => $resources,
            'inactive_resources' => $inactiveResources,
            'has_inactive_resources' => $inactiveResources !== [],
        ];
    }

    public function getVehicleStatus(int $vehicleId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, nr_inmatriculare, marca, model, tip_vehicul, status, observatii, created_at, updated_at
            FROM vehicule
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->bindValue(':id', $vehicleId, PDO::PARAM_INT);
        $stmt->execute();
        $vehicle = $stmt->fetch();

        if (!$vehicle) {
            return null;
        }

        $documents = $this->getVehicleDocumentIssues($vehicle);
        $documentReasonKey = $this->resolveDocumentReasonKey($documents);
        $reasons = [];

        if ($documentReasonKey !== null) {
            $reasons[] = $this->buildReason(
                $documentReasonKey,
                $this->resolveDocumentInactiveSince($documents, $vehicle),
                self::VEHICLE_REASON_DEFINITIONS
            );
        }

        $repair = $this->getVehicleRepairIssue($vehicleId);
        if ($repair !== null) {
            $reasons[] = $this->buildReason('repair', $repair['start_date'] ?? null, self::VEHICLE_REASON_DEFINITIONS);
        }

        if ((string) ($vehicle['status'] ?? 'activ') === 'inactiv') {
            $reasons[] = $this->buildReason(
                'manual_inactive',
                $this->firstDate($vehicle['updated_at'] ?? null, $vehicle['created_at'] ?? null),
                self::VEHICLE_REASON_DEFINITIONS
            );
        }

        return $this->buildResourceStatus([
            'resource_type' => 'vehicle',
            'resource_type_label' => 'Vehicul',
            'resource_id' => $vehicleId,
            'resource_label' => trim((string) ($vehicle['nr_inmatriculare'] ?? '')),
            'resource_subtitle' => trim((string) (($vehicle['marca'] ?? '') . ' ' . ($vehicle['model'] ?? ''))),
            'current_status' => (string) ($vehicle['status'] ?? ''),
            'reasons' => $reasons,
            'documents' => $documents,
            'detail' => $repair['detail'] ?? '',
            'repair' => $repair,
            'definitions' => self::VEHICLE_REASON_DEFINITIONS,
        ]);
    }

    public function getDriverStatus(int $driverId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, nume, status, employment_status, observatii, created_at, updated_at
            FROM soferi
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->bindValue(':id', $driverId, PDO::PARAM_INT);
        $stmt->execute();
        $driver = $stmt->fetch();

        if (!$driver) {
            return null;
        }

        $documents = $this->getDriverDocumentIssues($driverId);
        $documentReasonKey = $this->resolveDocumentReasonKey($documents);
        $reasons = [];

        if ($documentReasonKey !== null) {
            $reasons[] = $this->buildReason(
                $documentReasonKey,
                $this->resolveDocumentInactiveSince($documents, $driver),
                self::DRIVER_REASON_DEFINITIONS
            );
        }

        $leave = $this->getDriverLeaveIssue($driverId);
        if ($leave !== null) {
            $reasons[] = $this->buildReason(
                (string) ($leave['reason_key'] ?? 'leave'),
                $leave['start_date'] ?? null,
                self::DRIVER_REASON_DEFINITIONS
            );
        }

        $employmentStatus = (string) ($driver['employment_status'] ?? 'active');
        if ((string) ($driver['status'] ?? 'activ') === 'inactiv' || in_array($employmentStatus, ['temporarily_inactive', 'suspended', 'leave'], true)) {
            $manualKey = $employmentStatus === 'leave' ? 'leave' : 'manual_inactive';
            $reasons[] = $this->buildReason(
                $manualKey,
                $this->firstDate($driver['updated_at'] ?? null, $driver['created_at'] ?? null),
                self::DRIVER_REASON_DEFINITIONS
            );
        }

        return $this->buildResourceStatus([
            'resource_type' => 'driver',
            'resource_type_label' => 'Sofer',
            'resource_id' => $driverId,
            'resource_label' => trim((string) ($driver['nume'] ?? '')),
            'resource_subtitle' => '',
            'current_status' => (string) ($driver['status'] ?? ''),
            'reasons' => $reasons,
            'documents' => $documents,
            'detail' => $leave['detail'] ?? '',
            'leave' => $leave,
            'definitions' => self::DRIVER_REASON_DEFINITIONS,
        ]);
    }

    private function buildResourceStatus(array $payload): array
    {
        $reasons = is_array($payload['reasons'] ?? null) ? array_values($payload['reasons']) : [];
        $documents = is_array($payload['documents'] ?? null) ? array_values($payload['documents']) : [];
        $primaryReason = $reasons[0] ?? null;
        $definitions = is_array($payload['definitions'] ?? null) ? $payload['definitions'] : [];

        if ($primaryReason === null) {
            $primaryReason = $this->buildReason('other', null, $definitions);
        }

        $resourceLabel = trim((string) ($payload['resource_label'] ?? ''));
        if ($resourceLabel === '') {
            $resourceLabel = (string) ($payload['resource_type_label'] ?? 'Resursa') . ' #' . (string) ($payload['resource_id'] ?? '');
        }

        return [
            'resource_type' => (string) ($payload['resource_type'] ?? ''),
            'resource_type_label' => (string) ($payload['resource_type_label'] ?? ''),
            'resource_id' => (int) ($payload['resource_id'] ?? 0),
            'resource_label' => $resourceLabel,
            'resource_subtitle' => trim((string) ($payload['resource_subtitle'] ?? '')),
            'current_status' => (string) ($payload['current_status'] ?? ''),
            'is_inactive' => $reasons !== [],
            'reason_key' => (string) ($primaryReason['key'] ?? 'other'),
            'reason_label' => (string) ($primaryReason['label'] ?? 'Alt motiv'),
            'reason_tone' => (string) ($primaryReason['tone'] ?? 'muted'),
            'reason_icon' => (string) ($primaryReason['icon'] ?? 'bi-exclamation-circle'),
            'inactive_since' => $this->normalizeDate($primaryReason['date'] ?? null),
            'documents' => $documents,
            'affected_document_names' => $this->uniqueDocumentNames($documents),
            'detail' => trim((string) ($payload['detail'] ?? '')),
            'repair' => $payload['repair'] ?? null,
            'leave' => $payload['leave'] ?? null,
            'all_reasons' => $reasons,
            'usage_context' => 'Dispecer curse',
        ];
    }

    private function getVehicleDocumentIssues(array $vehicle): array
    {
        $vehicleId = (int) ($vehicle['id'] ?? 0);
        if (
            $vehicleId <= 0
            || !$this->tableExists('configurare_costuri_documente_vehicule')
            || !$this->tableExists('documente')
        ) {
            return [];
        }

        $vehicleType = $this->normalizeVehicleDocumentType((string) ($vehicle['tip_vehicul'] ?? ''));
        $stmt = $this->db->prepare("
            SELECT DISTINCT document_type
            FROM configurare_costuri_documente_vehicule
            WHERE vehicle_type = :vehicle_type
              AND requires_expiry = 1
              AND TRIM(document_type) <> ''
            ORDER BY document_type ASC
        ");
        $stmt->bindValue(':vehicle_type', $vehicleType);
        $stmt->execute();

        $issues = [];
        $today = (new DateTimeImmutable('today'))->format('Y-m-d');

        foreach ($stmt->fetchAll() as $row) {
            $documentType = trim((string) ($row['document_type'] ?? ''));
            if ($documentType === '') {
                continue;
            }

            $document = $this->getLatestVehicleDocumentByType($vehicleId, $documentType);
            if ($document === null) {
                $issues[] = $this->buildDocumentIssue($documentType, null, 'missing', null, 'documente');
                continue;
            }

            $expiryDate = $this->normalizeDate($document['data_expirare'] ?? null);
            if ($expiryDate === null || $expiryDate < $today) {
                $issues[] = $this->buildDocumentIssue(
                    $documentType,
                    (int) ($document['id'] ?? 0),
                    'expired',
                    $expiryDate,
                    'documente'
                );
            }
        }

        return $issues;
    }

    private function getDriverDocumentIssues(int $driverId): array
    {
        if (
            $driverId <= 0
            || !$this->tableExists('configurare_documente_obligatorii_soferi')
            || !$this->tableExists('documente_soferi')
        ) {
            return [];
        }

        $stmt = $this->db->query("
            SELECT document_type, requires_expiry
            FROM configurare_documente_obligatorii_soferi
            WHERE TRIM(document_type) <> ''
            ORDER BY document_type ASC
        ");

        $issues = [];
        $today = (new DateTimeImmutable('today'))->format('Y-m-d');

        foreach ($stmt->fetchAll() as $row) {
            $documentType = trim((string) ($row['document_type'] ?? ''));
            if ($documentType === '') {
                continue;
            }

            $document = $this->getLatestDriverDocumentByType($driverId, $documentType);
            if ($document === null) {
                $issues[] = $this->buildDocumentIssue($documentType, null, 'missing', null, 'documente_soferi');
                continue;
            }

            $requiresExpiry = (int) ($row['requires_expiry'] ?? 1) === 1;
            $expiryDate = $this->normalizeDate($document['data_expirare'] ?? null);
            if ($requiresExpiry && ($expiryDate === null || $expiryDate < $today)) {
                $issues[] = $this->buildDocumentIssue(
                    $documentType,
                    (int) ($document['id'] ?? 0),
                    'expired',
                    $expiryDate,
                    'documente_soferi'
                );
            }
        }

        return $issues;
    }

    private function getLatestVehicleDocumentByType(int $vehicleId, string $documentType): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, tip_document, data_expirare
            FROM documente
            WHERE vehicle_id = :vehicle_id
              AND LOWER(TRIM(tip_document)) = LOWER(TRIM(:document_type))
            ORDER BY
                CASE WHEN data_expirare IS NULL THEN 1 ELSE 0 END ASC,
                data_expirare DESC,
                id DESC
            LIMIT 1
        ");
        $stmt->bindValue(':vehicle_id', $vehicleId, PDO::PARAM_INT);
        $stmt->bindValue(':document_type', $documentType);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row ?: null;
    }

    private function getLatestDriverDocumentByType(int $driverId, string $documentType): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, tip_document, data_expirare
            FROM documente_soferi
            WHERE driver_id = :driver_id
              AND LOWER(TRIM(tip_document)) = LOWER(TRIM(:document_type))
            ORDER BY
                CASE WHEN data_expirare IS NULL THEN 1 ELSE 0 END ASC,
                data_expirare DESC,
                id DESC
            LIMIT 1
        ");
        $stmt->bindValue(':driver_id', $driverId, PDO::PARAM_INT);
        $stmt->bindValue(':document_type', $documentType);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row ?: null;
    }

    private function getVehicleRepairIssue(int $vehicleId): ?array
    {
        $candidates = [];

        if ($this->tableExists('mentenanta_interventii_programate')) {
            $stmt = $this->db->prepare("
                SELECT id,
                       data_programata AS start_date,
                       status_interventie,
                       furnizor,
                       descriere
                FROM mentenanta_interventii_programate
                WHERE vehicle_id = :vehicle_id
                  AND tip_interventie = 'reparatie'
                  AND status_interventie IN ('programata', 'confirmata', 'in_lucru')
                  AND data_programata <= CURDATE()
                ORDER BY data_programata ASC, id ASC
                LIMIT 1
            ");
            $stmt->bindValue(':vehicle_id', $vehicleId, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch();
            if ($row) {
                $candidates[] = [
                    'source' => 'mentenanta_interventii_programate',
                    'source_id' => (int) ($row['id'] ?? 0),
                    'start_date' => $this->normalizeDate($row['start_date'] ?? null),
                    'status' => (string) ($row['status_interventie'] ?? ''),
                    'supplier' => trim((string) ($row['furnizor'] ?? '')),
                    'detail' => trim((string) ($row['descriere'] ?? '')),
                ];
            }
        }

        if ($this->tableExists('mentenanta')) {
            $stmt = $this->db->prepare("
                SELECT id,
                       data_interventie AS start_date,
                       status_interventie,
                       atelier,
                       furnizor_piesa,
                       tip_interventie,
                       descriere,
                       observatii,
                       zile_imobilizare
                FROM mentenanta
                WHERE vehicle_id = :vehicle_id
                  AND record_type = 'reparatie'
                  AND status_interventie IN ('in_lucru', 'in_asteptare')
                  AND data_interventie <= CURDATE()
                  AND (status_interventie = 'in_lucru' OR COALESCE(zile_imobilizare, 0) > 0)
                ORDER BY data_interventie ASC, id ASC
                LIMIT 1
            ");
            $stmt->bindValue(':vehicle_id', $vehicleId, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch();
            if ($row) {
                $detail = trim((string) ($row['descriere'] ?? ''));
                if ($detail === '') {
                    $detail = trim((string) ($row['observatii'] ?? ''));
                }
                if ($detail === '') {
                    $detail = trim((string) ($row['tip_interventie'] ?? ''));
                }

                $candidates[] = [
                    'source' => 'mentenanta',
                    'source_id' => (int) ($row['id'] ?? 0),
                    'start_date' => $this->normalizeDate($row['start_date'] ?? null),
                    'status' => (string) ($row['status_interventie'] ?? ''),
                    'supplier' => trim((string) (($row['atelier'] ?? '') !== '' ? $row['atelier'] : ($row['furnizor_piesa'] ?? ''))),
                    'detail' => $detail,
                    'immobilization_days' => (float) ($row['zile_imobilizare'] ?? 0),
                ];
            }
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static function (array $a, array $b): int {
            return strcmp((string) ($a['start_date'] ?? '9999-12-31'), (string) ($b['start_date'] ?? '9999-12-31'));
        });

        return $candidates[0];
    }

    private function getDriverLeaveIssue(int $driverId): ?array
    {
        if (!$this->tableExists('concedii')) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT id, tip_concediu, data_inceput, data_sfarsit, note
            FROM concedii
            WHERE driver_id = :driver_id
              AND status = 'aprobat'
              AND CURDATE() BETWEEN data_inceput AND data_sfarsit
            ORDER BY data_inceput ASC, id ASC
            LIMIT 1
        ");
        $stmt->bindValue(':driver_id', $driverId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        $type = (string) ($row['tip_concediu'] ?? '');

        return [
            'source' => 'concedii',
            'source_id' => (int) ($row['id'] ?? 0),
            'reason_key' => $type === 'medical' ? 'medical_leave' : 'leave',
            'start_date' => $this->normalizeDate($row['data_inceput'] ?? null),
            'end_date' => $this->normalizeDate($row['data_sfarsit'] ?? null),
            'detail' => trim((string) ($row['note'] ?? '')),
        ];
    }

    private function buildDocumentIssue(string $documentType, ?int $documentId, string $status, ?string $expiryDate, string $sourceTable): array
    {
        return [
            'document_type' => $documentType,
            'document_id' => $documentId !== null && $documentId > 0 ? $documentId : null,
            'document_name' => $documentType,
            'document_status' => $status,
            'document_status_label' => $status === 'missing' ? 'Lipsa' : 'Expirat',
            'expiry_date' => $expiryDate,
            'source_table' => $sourceTable,
        ];
    }

    private function resolveDocumentReasonKey(array $documents): ?string
    {
        $hasExpired = false;
        $hasMissing = false;

        foreach ($documents as $document) {
            $status = (string) ($document['document_status'] ?? '');
            $hasExpired = $hasExpired || $status === 'expired';
            $hasMissing = $hasMissing || $status === 'missing';
        }

        if ($hasExpired && $hasMissing) {
            return 'documents_mixed';
        }
        if ($hasExpired) {
            return 'expired_documents';
        }
        if ($hasMissing) {
            return 'missing_documents';
        }

        return null;
    }

    private function resolveDocumentInactiveSince(array $documents, array $resourceRow): ?string
    {
        $dates = [];
        $hasMissing = false;

        foreach ($documents as $document) {
            $status = (string) ($document['document_status'] ?? '');
            if ($status === 'missing') {
                $hasMissing = true;
                continue;
            }

            $date = $this->normalizeDate($document['expiry_date'] ?? null);
            if ($date !== null) {
                $dates[] = $date;
            }
        }

        sort($dates);
        if ($dates !== []) {
            return $dates[0];
        }

        if ($hasMissing) {
            return $this->firstDate($resourceRow['updated_at'] ?? null, $resourceRow['created_at'] ?? null);
        }

        return null;
    }

    private function buildReason(string $key, mixed $date, array $definitions): array
    {
        $definition = $definitions[$key] ?? ($definitions['other'] ?? []);

        return [
            'key' => $key,
            'label' => (string) ($definition['label'] ?? 'Alt motiv'),
            'tone' => (string) ($definition['tone'] ?? 'muted'),
            'icon' => (string) ($definition['icon'] ?? 'bi-exclamation-circle'),
            'date' => $this->normalizeDate($date),
        ];
    }

    private function uniqueDocumentNames(array $documents): array
    {
        $names = [];
        foreach ($documents as $document) {
            $name = trim((string) ($document['document_name'] ?? $document['document_type'] ?? ''));
            if ($name !== '') {
                $names[$name] = $name;
            }
        }

        return array_values($names);
    }

    private function normalizeVehicleDocumentType(string $vehicleType): string
    {
        $vehicleType = trim($vehicleType);
        if ($vehicleType === 'autoturism' || $vehicleType === 'autoutilitara') {
            return 'autovehicul';
        }
        if ($vehicleType === 'semiremorca') {
            return 'semiremorca_primar';
        }

        return $vehicleType !== '' ? $vehicleType : 'autovehicul';
    }

    private function firstDate(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            $date = $this->normalizeDate($value);
            if ($date !== null) {
                return $date;
            }
        }

        return null;
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

    private function tableExists(string $tableName): bool
    {
        static $cache = [];
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
            return false;
        }

        if (array_key_exists($tableName, $cache)) {
            return $cache[$tableName];
        }

        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
        ");
        $stmt->bindValue(':table_name', $tableName);
        $stmt->execute();

        return $cache[$tableName] = (int) $stmt->fetchColumn() > 0;
    }
}
