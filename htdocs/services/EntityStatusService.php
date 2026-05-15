<?php
declare(strict_types=1);

class EntityStatusService
{
    public function __construct(private PDO $db)
    {
    }

    public function syncVehicleStatus(int $vehicleId): ?array
    {
        $evaluation = $this->evaluateVehicleStatus($vehicleId);

        if ($evaluation === null) {
            return null;
        }

        $this->updateStatus('vehicule', $vehicleId, $evaluation['status']);

        return $evaluation;
    }

    public function syncDriverStatus(int $driverId): ?array
    {
        $evaluation = $this->evaluateDriverStatus($driverId);

        if ($evaluation === null) {
            return null;
        }

        $this->updateStatus('soferi', $driverId, $evaluation['status']);

        return $evaluation;
    }

    public function evaluateVehicleStatus(int $vehicleId): ?array
    {
        if (!$this->existsRecord('vehicule', $vehicleId)) {
            return null;
        }

        $checks = $this->buildDocumentChecks(
            'documente',
            'vehicle_id',
            $vehicleId,
            VEHICLE_REQUIRED_DOCUMENT_TYPES
        );

        return $this->buildEvaluation($checks);
    }

    public function syncAllVehicleStatuses(): void
    {
        foreach ($this->getAllIds('vehicule') as $vehicleId) {
            $this->syncVehicleStatus($vehicleId);
        }
    }

    public function syncAllDriverStatuses(): void
    {
        foreach ($this->getAllIds('soferi') as $driverId) {
            $this->syncDriverStatus($driverId);
        }
    }

    public function evaluateDriverStatus(int $driverId): ?array
    {
        $driver = $this->getDriverStatusData($driverId);

        if ($driver === null) {
            return null;
        }

        $checks = [];
        $checks[] = $this->buildDriverLicenseCheck($driver['permis_expira_la'] ?? null);

        $documentChecks = $this->buildDocumentChecks(
            'documente_soferi',
            'driver_id',
            $driverId,
            DRIVER_REQUIRED_DOCUMENT_TYPES
        );

        $checks = array_merge($checks, $documentChecks);

        return $this->buildEvaluation($checks);
    }

    private function buildEvaluation(array $checks): array
    {
        $issues = [];

        foreach ($checks as $check) {
            if (($check['state'] ?? 'valid') !== 'valid') {
                $issues[] = $check['message'];
            }
        }

        return [
            'status' => $issues === [] ? 'activ' : 'inactiv',
            'checks' => $checks,
            'issues' => $issues,
        ];
    }

    private function buildDriverLicenseCheck(?string $expiryDate): array
    {
        if ($expiryDate === null || trim($expiryDate) === '') {
            return [
                'label' => 'Permis de conducere',
                'state' => 'missing',
                'message' => 'Lipseste data de expirare pentru permisul de conducere.',
                'date' => null,
            ];
        }

        if ($this->isDateValid($expiryDate)) {
            return [
                'label' => 'Permis de conducere',
                'state' => 'valid',
                'message' => 'Permisul este valabil pana la ' . format_date_ro($expiryDate) . '.',
                'date' => $expiryDate,
            ];
        }

        return [
            'label' => 'Permis de conducere',
            'state' => 'expired',
            'message' => 'Permisul este expirat din ' . format_date_ro($expiryDate) . '.',
            'date' => $expiryDate,
        ];
    }

    private function buildDocumentChecks(string $table, string $foreignKey, int $recordId, array $requiredTypes): array
    {
        $documentsByType = $this->getLatestDocumentsByType($table, $foreignKey, $recordId);
        $checks = [];
        $handledTypes = [];

        foreach ($requiredTypes as $requiredType) {
            $normalizedType = mb_strtolower(trim($requiredType), 'UTF-8');
            $document = $documentsByType[$normalizedType] ?? null;
            $handledTypes[$normalizedType] = true;

            if ($document === null) {
                $checks[] = [
                    'label' => $requiredType,
                    'state' => 'missing',
                    'message' => 'Lipseste documentul obligatoriu: ' . $requiredType . '.',
                    'date' => null,
                ];
                continue;
            }

            $expiryDate = $document['data_expirare'] ?? null;
            if ($expiryDate !== null && $this->isDateValid((string) $expiryDate)) {
                $checks[] = [
                    'label' => $requiredType,
                    'state' => 'valid',
                    'message' => $requiredType . ' este valabil pana la ' . format_date_ro((string) $expiryDate) . '.',
                    'date' => (string) $expiryDate,
                ];
                continue;
            }

            $checks[] = [
                'label' => $requiredType,
                'state' => 'expired',
                'message' => $requiredType . ' este expirat din ' . format_date_ro((string) $expiryDate) . '.',
                'date' => (string) $expiryDate,
            ];
        }

        foreach ($documentsByType as $normalizedType => $document) {
            if (isset($handledTypes[$normalizedType])) {
                continue;
            }

            $documentType = (string) ($document['tip_document'] ?? 'Document suplimentar');
            $expiryDate = $document['data_expirare'] ?? null;

            if ($expiryDate !== null && $this->isDateValid((string) $expiryDate)) {
                $checks[] = [
                    'label' => $documentType,
                    'state' => 'valid',
                    'message' => $documentType . ' este valabil pana la ' . format_date_ro((string) $expiryDate) . '.',
                    'date' => (string) $expiryDate,
                ];
                continue;
            }

            $checks[] = [
                'label' => $documentType,
                'state' => 'expired',
                'message' => $documentType . ' este expirat din ' . format_date_ro((string) $expiryDate) . '.',
                'date' => (string) $expiryDate,
            ];
        }

        return $checks;
    }

    private function getLatestDocumentsByType(string $table, string $foreignKey, int $recordId): array
    {
        $sql = sprintf(
            'SELECT tip_document, data_expirare
             FROM %s
             WHERE %s = :record_id
             ORDER BY data_expirare DESC, id DESC',
            $table,
            $foreignKey
        );

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':record_id', $recordId, PDO::PARAM_INT);
        $stmt->execute();

        $documentsByType = [];

        foreach ($stmt->fetchAll() as $row) {
            $type = mb_strtolower(trim((string) ($row['tip_document'] ?? '')), 'UTF-8');
            if ($type === '' || isset($documentsByType[$type])) {
                continue;
            }

            $documentsByType[$type] = $row;
        }

        return $documentsByType;
    }

    private function getDriverStatusData(int $driverId): ?array
    {
        $sql = 'SELECT permis_expira_la FROM soferi WHERE id = :id LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $driverId, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch();

        return $row ?: null;
    }

    private function updateStatus(string $table, int $recordId, string $status): void
    {
        $sql = sprintf(
            'UPDATE %s SET status = :status_new, updated_at = :updated_at WHERE id = :id AND status <> :status_current',
            $table
        );

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':status_new', $status, PDO::PARAM_STR);
        $stmt->bindValue(':status_current', $status, PDO::PARAM_STR);
        $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':id', $recordId, PDO::PARAM_INT);
        $stmt->execute();
    }

    private function getAllIds(string $table): array
    {
        $sql = sprintf('SELECT id FROM %s ORDER BY id ASC', $table);
        $stmt = $this->db->query($sql);

        return array_map(
            static fn(array $row): int => (int) $row['id'],
            $stmt->fetchAll()
        );
    }

    private function existsRecord(string $table, int $recordId): bool
    {
        $sql = sprintf('SELECT COUNT(*) FROM %s WHERE id = :id', $table);
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $recordId, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn() > 0;
    }

    private function isDateValid(string $date): bool
    {
        try {
            $expiryDate = new DateTimeImmutable($date);
            $today = new DateTimeImmutable('today');

            return $expiryDate >= $today;
        } catch (Exception) {
            return false;
        }
    }
}
