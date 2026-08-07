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
        $vehicle = $this->getVehicleStatusData($vehicleId);

        if ($vehicle === null) {
            return null;
        }

        $checks = $this->buildDocumentChecks(
            'documente',
            'vehicle_id',
            $vehicleId,
            $this->getRequiredVehicleDocumentTypes((string) ($vehicle['tip_vehicul'] ?? ''))
        );

        return $this->buildEvaluation($checks);
    }

    public function syncAllVehicleStatuses(): void
    {
        foreach ($this->getAllIds('vehicule') as $vehicleId) {
            $this->syncVehicleStatus($vehicleId);
        }
    }

    public function syncVehicleStatusesByConfiguredType(string $vehicleType): void
    {
        $normalizedTargetType = $this->normalizeVehicleTypeForDocumentConfig($vehicleType);

        $stmt = $this->db->query('SELECT id, tip_vehicul FROM vehicule ORDER BY id ASC');
        foreach ($stmt->fetchAll() as $row) {
            if ($this->normalizeVehicleTypeForDocumentConfig((string) ($row['tip_vehicul'] ?? '')) !== $normalizedTargetType) {
                continue;
            }

            $vehicleId = (int) ($row['id'] ?? 0);
            if ($vehicleId > 0) {
                $this->syncVehicleStatus($vehicleId);
            }
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
        if (!$this->existsRecord('soferi', $driverId)) {
            return null;
        }

        if ($this->isTerminatedDriver($driverId)) {
            return [
                'status' => 'inactiv',
                'checks' => [[
                    'label' => 'Colaborare incheiata',
                    'state' => 'other',
                    'message' => 'Soferul are colaborarea incheiata.',
                    'date' => null,
                ]],
                'issues' => ['Soferul are colaborarea incheiata.'],
            ];
        }

        $checks = $this->buildDocumentChecks(
            'documente_soferi',
            'driver_id',
            $driverId,
            $this->getRequiredDriverDocumentTypes($driverId)
        );

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

    private function getRequiredVehicleDocumentTypes(string $vehicleType): array
    {
        $normalizedType = $this->normalizeVehicleTypeForDocumentConfig($vehicleType);

        try {
            $stmt = $this->db->prepare('
                SELECT document_type
                FROM configurare_costuri_documente_vehicule
                WHERE vehicle_type = :vehicle_type
                  AND requires_expiry = 1
                ORDER BY document_type ASC
            ');
            $stmt->bindValue(':vehicle_type', $normalizedType, PDO::PARAM_STR);
            $stmt->execute();
        } catch (Throwable) {
            return defined('VEHICLE_REQUIRED_DOCUMENT_TYPES') ? VEHICLE_REQUIRED_DOCUMENT_TYPES : [];
        }

        $documentTypes = [];
        foreach ($stmt->fetchAll() as $row) {
            $documentType = trim((string) ($row['document_type'] ?? ''));
            if ($documentType === '' || $this->isEmploymentContractDocumentType($documentType)) {
                continue;
            }

            $documentTypes[$documentType] = $documentType;
        }

        return array_values($documentTypes);
    }

    private function getRequiredDriverDocumentTypes(int $driverId): array
    {
        if ($driverId <= 0) {
            return [];
        }

        try {
            $stmt = $this->db->query('
                SELECT document_type
                FROM configurare_documente_obligatorii_soferi
                ORDER BY document_type ASC
            ');
        } catch (Throwable) {
            return defined('DRIVER_REQUIRED_DOCUMENT_TYPES') ? DRIVER_REQUIRED_DOCUMENT_TYPES : [];
        }

        $documentTypes = [];
        foreach ($stmt->fetchAll() as $row) {
            $documentType = trim((string) ($row['document_type'] ?? ''));
            if ($documentType === '') {
                continue;
            }

            $documentTypes[$documentType] = $documentType;
        }

        return array_values($documentTypes);
    }

    private function isEmploymentContractDocumentType(string $documentType): bool
    {
        $key = $this->normalizeStatusDocumentTypeKey($documentType);

        return in_array($key, ['contractdemunca', 'contractdeangajare'], true);
    }

    private function normalizeStatusDocumentTypeKey(string $documentType): string
    {
        $normalized = strtolower(trim($documentType));
        $normalized = strtr($normalized, [
            'Äƒ' => 'a',
            'Ã¢' => 'a',
            'Ã®' => 'i',
            'È™' => 's',
            'ÅŸ' => 's',
            'È›' => 't',
            'Å£' => 't',
            'Ä‚' => 'a',
            'Ã‚' => 'a',
            'ÃŽ' => 'i',
            'È˜' => 's',
            'Åž' => 's',
            'Èš' => 't',
            'Å¢' => 't',
        ]);

        $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized) : false;
        $base = is_string($ascii) && trim($ascii) !== '' ? $ascii : $normalized;

        return (string) preg_replace('/[^a-z0-9]+/', '', strtolower($base));
    }

    private function normalizeVehicleTypeForDocumentConfig(string $vehicleType): string
    {
        $normalized = strtolower(trim($vehicleType));

        if ($normalized === 'autoutilitara') {
            return 'autovehicul';
        }

        if (function_exists('normalize_vehicle_type_for_form_select')) {
            return normalize_vehicle_type_for_form_select($vehicleType);
        }

        return match ($normalized) {
            'autoturism', 'autovehicul' => 'autovehicul',
            'camion' => 'camion',
            'cap_tractor' => 'cap_tractor',
            'semiremorca', 'semiremorca_primar' => 'semiremorca_primar',
            'semiremorca_distributie' => 'semiremorca_distributie',
            default => 'autovehicul',
        };
    }

    private function buildDocumentChecks(string $table, string $foreignKey, int $recordId, array $requiredTypes): array
    {
        $documentsByType = $this->getLatestDocumentsByType($table, $foreignKey, $recordId);
        $checks = [];

        foreach ($requiredTypes as $requiredType) {
            $normalizedType = mb_strtolower(trim($requiredType), 'UTF-8');
            $document = $documentsByType[$normalizedType] ?? null;

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
            $requiresExpiry = $this->documentRequiresExpiry($table, $foreignKey, $recordId, $requiredType);

            if ($table === 'documente_soferi' && $foreignKey === 'driver_id' && !$requiresExpiry) {
                $customExpiryCheck = $this->buildDriverDocumentCustomExpiryCheck($requiredType, $document);
                if ($customExpiryCheck !== null) {
                    $checks[] = $customExpiryCheck;
                    continue;
                }
            }

            if (($expiryDate === null || trim((string) $expiryDate) === '') && !$requiresExpiry) {
                $checks[] = [
                    'label' => $requiredType,
                    'state' => 'valid',
                    'message' => $requiredType . ' nu necesita data de expirare.',
                    'date' => null,
                ];
                continue;
            }

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

        return $checks;
    }

    private function documentRequiresExpiry(string $table, string $foreignKey, int $recordId, string $documentType): bool
    {
        if ($recordId <= 0 || trim($documentType) === '') {
            return true;
        }

        if ($table === 'documente_soferi' && $foreignKey === 'driver_id') {
            try {
                $stmt = $this->db->prepare('
                    SELECT requires_expiry
                    FROM configurare_documente_obligatorii_soferi
                    WHERE document_type = :document_type
                    LIMIT 1
                ');
                $stmt->bindValue(':document_type', trim($documentType));
                $stmt->execute();
                $value = $stmt->fetchColumn();
            } catch (Throwable) {
                return true;
            }

            if ($value === false) {
                return true;
            }

            return (int) $value === 1;
        }

        if ($table !== 'documente' || $foreignKey !== 'vehicle_id') {
            return true;
        }

        $sql = '
            SELECT c.requires_expiry
            FROM vehicule v
            INNER JOIN configurare_costuri_documente_vehicule c
              ON c.vehicle_type = (
                    CASE
                        WHEN v.tip_vehicul = "autoturism" THEN "autovehicul"
                        WHEN v.tip_vehicul = "semiremorca" THEN "semiremorca_primar"
                        WHEN v.tip_vehicul = "autoutilitara" THEN "autovehicul"
                        ELSE v.tip_vehicul
                    END
                )
             AND c.document_type = :document_type
            WHERE v.id = :vehicle_id
            LIMIT 1
        ';

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':document_type', trim($documentType));
            $stmt->bindValue(':vehicle_id', $recordId, PDO::PARAM_INT);
            $stmt->execute();
            $value = $stmt->fetchColumn();
        } catch (Throwable) {
            return true;
        }

        if ($value === false) {
            return true;
        }

        return (int) $value === 1;
    }

    private function buildDriverDocumentCustomExpiryCheck(string $documentType, array $document): ?array
    {
        $configs = $this->getDriverDocumentCustomFieldConfigsForStatus($documentType);
        if ($configs === []) {
            return null;
        }

        $dateConfigs = [];
        $checkboxLabelsByKey = [];

        foreach ($configs as $config) {
            $fieldKey = (string) ($config['key'] ?? '');
            $fieldType = strtolower(trim((string) ($config['type'] ?? 'text')));
            $fieldLabel = trim((string) ($config['label'] ?? ''));

            if ($fieldKey === '' || $fieldLabel === '') {
                continue;
            }

            if ($fieldType === 'checkbox') {
                $checkboxLabelsByKey[$fieldKey] = $fieldLabel;
                continue;
            }

            if ($fieldType === 'date') {
                $dateConfigs[] = $config;
            }
        }

        if ($dateConfigs === []) {
            return null;
        }

        $savedRows = $this->decodeDriverDocumentCustomFieldValueSnapshot($document['custom_fields_json'] ?? null);
        $activeDateRows = [];
        $hasConditionalDateRows = false;

        foreach ($dateConfigs as $config) {
            $fieldKey = (string) ($config['key'] ?? '');
            $fieldLabel = trim((string) ($config['label'] ?? ''));
            $showWhenChecked = (string) ($config['show_when_checked'] ?? '');
            $contextLabel = $fieldLabel;

            if ($fieldKey === '' || $fieldLabel === '') {
                continue;
            }

            if ($showWhenChecked !== '') {
                $hasConditionalDateRows = true;
                $checkboxValue = $savedRows[$showWhenChecked]['value'] ?? '';

                if (!$this->isDriverDocumentCustomCheckboxChecked($checkboxValue)) {
                    continue;
                }

                $contextLabel = $checkboxLabelsByKey[$showWhenChecked] ?? $fieldLabel;
            }

            $activeDateRows[] = [
                'key' => $fieldKey,
                'label' => $contextLabel,
                'value' => trim((string) ($savedRows[$fieldKey]['value'] ?? '')),
            ];
        }

        if ($activeDateRows === []) {
            if (!$hasConditionalDateRows) {
                return null;
            }

            return [
                'label' => $documentType,
                'state' => 'missing',
                'message' => $documentType . ': selecteaza cel putin o categorie si completeaza data ei de expirare.',
                'date' => null,
            ];
        }

        $validDates = [];
        foreach ($activeDateRows as $activeDateRow) {
            $fieldLabel = (string) ($activeDateRow['label'] ?? '');
            $rawDate = trim((string) ($activeDateRow['value'] ?? ''));

            if ($rawDate === '') {
                return [
                    'label' => $documentType,
                    'state' => 'missing',
                    'message' => $documentType . ': lipseste data de expirare pentru ' . $fieldLabel . '.',
                    'date' => null,
                ];
            }

            $expiryDate = $this->parseStatusDate($rawDate);
            if ($expiryDate === null) {
                return [
                    'label' => $documentType,
                    'state' => 'expired',
                    'message' => $documentType . ': data de expirare pentru ' . $fieldLabel . ' este invalida.',
                    'date' => null,
                ];
            }

            $isoDate = $expiryDate->format('Y-m-d');
            if (!$this->isDateValid($isoDate)) {
                return [
                    'label' => $documentType,
                    'state' => 'expired',
                    'message' => $documentType . ': ' . $fieldLabel . ' este expirata din ' . format_date_ro($isoDate) . '.',
                    'date' => $isoDate,
                ];
            }

            $validDates[] = [
                'label' => $fieldLabel,
                'date' => $isoDate,
                'timestamp' => $expiryDate->getTimestamp(),
            ];
        }

        usort(
            $validDates,
            static fn(array $a, array $b): int => ($a['timestamp'] ?? 0) <=> ($b['timestamp'] ?? 0)
        );

        $soonest = $validDates[0] ?? null;
        if ($soonest === null) {
            return null;
        }

        $message = count($validDates) === 1
            ? $documentType . ' este valabil pana la ' . format_date_ro((string) $soonest['date']) . ' pentru ' . (string) $soonest['label'] . '.'
            : $documentType . ' este valabil pana la ' . format_date_ro((string) $soonest['date']) . ' (cea mai apropiata: ' . (string) $soonest['label'] . ').';

        return [
            'label' => $documentType,
            'state' => 'valid',
            'message' => $message,
            'date' => (string) $soonest['date'],
        ];
    }

    private function getDriverDocumentCustomFieldConfigsForStatus(string $documentType): array
    {
        $documentType = trim($documentType);
        if ($documentType === '') {
            return [];
        }

        try {
            $stmt = $this->db->prepare('
                SELECT custom_fields_json
                FROM configurare_documente_obligatorii_soferi
                WHERE document_type = :document_type
                LIMIT 1
            ');
            $stmt->bindValue(':document_type', $documentType, PDO::PARAM_STR);
            $stmt->execute();
            $rawValue = $stmt->fetchColumn();
        } catch (Throwable) {
            return [];
        }

        return $this->decodeDriverDocumentCustomFieldConfigs($rawValue);
    }

    private function decodeDriverDocumentCustomFieldConfigs(mixed $rawValue): array
    {
        if (!is_string($rawValue) || trim($rawValue) === '') {
            return [];
        }

        $decoded = json_decode($rawValue, true);
        if (!is_array($decoded)) {
            return [];
        }

        $allowedTypes = ['text', 'number', 'date', 'checkbox'];
        $rows = [];

        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }

            $key = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($item['key'] ?? ''));
            $label = trim((string) ($item['label'] ?? ''));
            $type = strtolower(trim((string) ($item['type'] ?? 'text')));
            $showWhenChecked = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($item['show_when_checked'] ?? ''));

            if (!is_string($key) || $key === '' || $label === '' || !in_array($type, $allowedTypes, true)) {
                continue;
            }

            $rows[] = [
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'show_when_checked' => is_string($showWhenChecked) ? $showWhenChecked : '',
            ];
        }

        return $rows;
    }

    private function decodeDriverDocumentCustomFieldValueSnapshot(mixed $rawValue): array
    {
        if (!is_string($rawValue) || trim($rawValue) === '') {
            return [];
        }

        $decoded = json_decode($rawValue, true);
        if (!is_array($decoded)) {
            return [];
        }

        $allowedTypes = ['text', 'number', 'date', 'checkbox'];
        $rows = [];

        foreach ($decoded as $fieldKey => $item) {
            if (!is_array($item)) {
                continue;
            }

            $key = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($item['key'] ?? $fieldKey));
            $label = trim((string) ($item['label'] ?? ''));
            $type = strtolower(trim((string) ($item['type'] ?? 'text')));
            $value = $item['value'] ?? '';

            if (!is_string($key) || $key === '' || $label === '' || !in_array($type, $allowedTypes, true) || !is_scalar($value)) {
                continue;
            }

            $rows[$key] = [
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'value' => trim((string) $value),
            ];
        }

        return $rows;
    }

    private function isDriverDocumentCustomCheckboxChecked(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (!is_scalar($value)) {
            return false;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'on', 'yes', 'da'], true);
    }

    private function parseStatusDate(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        foreach (['Y-m-d', 'd/m/Y', 'd.m.Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($date instanceof DateTimeImmutable && $date->format($format) === $value) {
                return $date;
            }
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }

    private function getLatestDocumentsByType(string $table, string $foreignKey, int $recordId): array
    {
        $selectColumns = $table === 'documente_soferi'
            ? 'tip_document, data_expirare, custom_fields_json'
            : 'tip_document, data_expirare';

        $sql = sprintf(
            'SELECT %s
             FROM %s
             WHERE %s = :record_id
             ORDER BY data_expirare DESC, id DESC',
            $selectColumns,
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

    private function getVehicleStatusData(int $vehicleId): ?array
    {
        $sql = 'SELECT tip_vehicul FROM vehicule WHERE id = :id LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $vehicleId, PDO::PARAM_INT);
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

    private function isTerminatedDriver(int $driverId): bool
    {
        if (!$this->columnExists('soferi', 'employment_status')) {
            return false;
        }

        $stmt = $this->db->prepare("
            SELECT employment_status
            FROM soferi
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->bindValue(':id', $driverId, PDO::PARAM_INT);
        $stmt->execute();

        return strtolower(trim((string) ($stmt->fetchColumn() ?: ''))) === 'terminated';
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
        $stmt->execute([
            ':table_name' => $table,
            ':column_name' => $column,
        ]);

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
