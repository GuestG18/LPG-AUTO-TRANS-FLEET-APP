<?php
declare(strict_types=1);

class DocumentModel extends BaseModel
{
    private function normalizeVehicleTypeForDocumentConfig(string $vehicleType): string
    {
        if (strtolower(trim($vehicleType)) === 'autoutilitara') {
            return 'autovehicul';
        }

        if (function_exists('normalize_vehicle_type_for_form_select')) {
            return normalize_vehicle_type_for_form_select($vehicleType);
        }

        $normalized = strtolower(trim($vehicleType));

        return match ($normalized) {
            'autoturism', 'autovehicul', 'autoutilitara' => 'autovehicul',
            'camion' => 'camion',
            'cap_tractor' => 'cap_tractor',
            'semiremorca', 'semiremorca_primar' => 'semiremorca_primar',
            'semiremorca_distributie' => 'semiremorca_distributie',
            default => 'autovehicul',
        };
    }

    private function normalizeDriverDocumentTypeKey(string $documentType): string
    {
        $normalized = strtolower(trim($documentType));
        $normalized = strtr($normalized, [
            'ă' => 'a',
            'â' => 'a',
            'î' => 'i',
            'ș' => 's',
            'ş' => 's',
            'ț' => 't',
            'ţ' => 't',
            'Ă' => 'a',
            'Â' => 'a',
            'Î' => 'i',
            'Ș' => 's',
            'Ş' => 's',
            'Ț' => 't',
            'Ţ' => 't',
        ]);

        $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized) : false;
        $base = is_string($ascii) && trim($ascii) !== '' ? $ascii : $normalized;

        return (string) preg_replace('/[^a-z0-9]+/', '', strtolower($base));
    }

    public function isDriverEmploymentContractDocumentType(string $documentType): bool
    {
        return in_array(
            $this->normalizeDriverDocumentTypeKey($documentType),
            ['contractdemunca', 'contractdeangajare'],
            true
        );
    }

    private function driverDocumentTypeCanonicalKey(string $documentType): string
    {
        $key = $this->normalizeDriverDocumentTypeKey($documentType);

        return match ($key) {
            'atestatprofesional',
            'certificatcompetentaprofesionala',
            'certificatdecompetentaprofesionala' => 'atestat_profesional',
            'avizmedical' => 'aviz_medical',
            'carteidentitate',
            'buletinci',
            'cibuletin',
            'buletin',
            'ci' => 'carte_identitate',
            default => $key,
        };
    }

    private function preferredDriverDocumentTypeLabel(string $documentType): string
    {
        return match ($this->driverDocumentTypeCanonicalKey($documentType)) {
            'atestat_profesional' => 'Atestat profesional',
            'aviz_medical' => 'Aviz medical',
            'carte_identitate' => 'Carte identitate',
            default => trim($documentType),
        };
    }

    private function driverDocumentTypeLookupKeys(string $documentType): array
    {
        $documentType = trim($documentType);
        if ($documentType === '') {
            return [];
        }

        $canonicalKey = $this->driverDocumentTypeCanonicalKey($documentType);
        $keys = [
            $documentType,
            $this->normalizeDriverDocumentTypeKey($documentType),
            $canonicalKey,
            $this->preferredDriverDocumentTypeLabel($documentType),
        ];

        $aliasesByCanonicalKey = [
            'atestat_profesional' => [
                'Atestat profesional',
                'CERTIFICAT COMPETENTA PROFESIONALA',
                'Certificat competenta profesionala',
            ],
            'aviz_medical' => [
                'Aviz medical',
                'AVIZ MEDICAL',
            ],
            'carte_identitate' => [
                'Carte identitate',
                'BULETIN (C.I.)',
                'CI / Buletin',
                'Buletin',
            ],
        ];

        foreach ($aliasesByCanonicalKey[$canonicalKey] ?? [] as $alias) {
            $keys[] = $alias;
            $keys[] = $this->normalizeDriverDocumentTypeKey($alias);
        }

        $uniqueKeys = [];
        foreach ($keys as $key) {
            $key = trim((string) $key);
            if ($key !== '') {
                $uniqueKeys[$key] = $key;
            }
        }

        return array_values($uniqueKeys);
    }

    public function getVehicleDocumentTypeOptionsByVehicleType(): array
    {
        $sql = '
            SELECT vehicle_type, document_type
            FROM configurare_costuri_documente_vehicule
            ORDER BY vehicle_type ASC, document_type ASC
        ';

        $stmt = $this->db->query($sql);
        $map = [];

        foreach ($stmt->fetchAll() as $row) {
            $vehicleType = $this->normalizeVehicleTypeForDocumentConfig((string) ($row['vehicle_type'] ?? 'autovehicul'));
            $documentType = trim((string) ($row['document_type'] ?? ''));
            if ($documentType === '') {
                continue;
            }

            if (!isset($map[$vehicleType])) {
                $map[$vehicleType] = [];
            }

            $map[$vehicleType][$documentType] = $documentType;
        }

        if (isset($map['semiremorca_primar']) && !isset($map['semiremorca'])) {
            // Backward compatibility for legacy vehicle rows still using semiremorca.
            $map['semiremorca'] = $map['semiremorca_primar'];
        }

        return $map;
    }

    public function getDriverDocumentTypeOptionsByDriverIds(array $driverIds): array
    {
        $driverIds = array_values(array_unique(array_filter(array_map(
            static fn(mixed $id): int => (int) $id,
            $driverIds
        ))));

        if ($driverIds === []) {
            return [];
        }

        $options = $this->getRequiredDriverDocumentTypeOptions();

        $map = [];
        foreach ($driverIds as $driverId) {
            $map[(string) $driverId] = $options;
        }

        return $map;
    }

    public function getDriverDocumentTypeOptionsForDriver(int $driverId): array
    {
        if ($driverId <= 0) {
            return [];
        }

        $map = $this->getDriverDocumentTypeOptionsByDriverIds([$driverId]);
        return $map[(string) $driverId] ?? [];
    }

    public function getAvailableDriverDocumentTypeOptionsByDriverIds(array $driverIds, ?int $currentDocumentId = null): array
    {
        $driverIds = array_values(array_unique(array_filter(array_map(
            static fn(mixed $id): int => (int) $id,
            $driverIds
        ))));

        if ($driverIds === []) {
            return [];
        }

        $requiredOptions = $this->getRequiredDriverDocumentTypeOptions();
        $map = [];

        foreach ($driverIds as $driverId) {
            $map[(string) $driverId] = $requiredOptions;
        }

        if ($requiredOptions === []) {
            return $map;
        }

        $currentDocumentId = $currentDocumentId !== null && $currentDocumentId > 0
            ? $currentDocumentId
            : null;
        $placeholders = implode(',', array_fill(0, count($driverIds), '?'));
        $sql = '
            SELECT id, driver_id, tip_document
            FROM documente_soferi
            WHERE driver_id IN (' . $placeholders . ')
            ORDER BY driver_id ASC, tip_document ASC, id DESC
        ';

        $stmt = $this->db->prepare($sql);
        foreach ($driverIds as $index => $driverId) {
            $stmt->bindValue($index + 1, $driverId, PDO::PARAM_INT);
        }
        $stmt->execute();

        foreach ($stmt->fetchAll() as $row) {
            $recordId = (int) ($row['id'] ?? 0);
            if ($currentDocumentId !== null && $recordId === $currentDocumentId) {
                continue;
            }

            $driverId = (int) ($row['driver_id'] ?? 0);
            $documentType = trim((string) ($row['tip_document'] ?? ''));
            $driverKey = (string) $driverId;

            if ($driverId <= 0 || $documentType === '' || !isset($map[$driverKey])) {
                continue;
            }

            foreach ($this->driverDocumentTypeLookupKeys($documentType) as $lookupKey) {
                unset($map[$driverKey][$lookupKey]);
            }
        }

        return $map;
    }

    public function getAvailableDriverDocumentTypeOptionsForDriver(int $driverId, ?int $currentDocumentId = null): array
    {
        if ($driverId <= 0) {
            return [];
        }

        $map = $this->getAvailableDriverDocumentTypeOptionsByDriverIds([$driverId], $currentDocumentId);
        return $map[(string) $driverId] ?? [];
    }

    public function getRequiredDriverDocumentTypeOptions(): array
    {
        try {
            $stmt = $this->db->query('
                SELECT document_type
                FROM configurare_documente_obligatorii_soferi
                ORDER BY document_type ASC
            ');
        } catch (Throwable) {
            $fallbackTypes = defined('DRIVER_REQUIRED_DOCUMENT_TYPES') ? DRIVER_REQUIRED_DOCUMENT_TYPES : [];
            $options = [];
            foreach ($fallbackTypes as $documentType) {
                $documentType = trim((string) $documentType);
                if ($documentType !== '') {
                    $options[$documentType] = $documentType;
                }
            }

            return $options;
        }

        $options = [];
        foreach ($stmt->fetchAll() as $row) {
            $documentType = trim((string) ($row['document_type'] ?? ''));
            if ($documentType === '' || $this->isDriverEmploymentContractDocumentType($documentType)) {
                continue;
            }

            $options[$documentType] = $documentType;
        }

        return $options;
    }

    public function getConfiguredDriverDocumentTypes(): array
    {
        $sqlWithRequirement = '
            SELECT
                id,
                document_type,
                requires_expiry,
                custom_fields_json,
                created_at,
                updated_at
            FROM configurare_documente_obligatorii_soferi
            ORDER BY document_type ASC
        ';

        $sqlFallback = '
            SELECT
                id,
                document_type,
                1 AS requires_expiry,
                custom_fields_json,
                created_at,
                updated_at
            FROM configurare_documente_obligatorii_soferi
            ORDER BY document_type ASC
        ';

        try {
            $stmt = $this->db->query($sqlWithRequirement);
        } catch (Throwable) {
            $stmt = $this->db->query($sqlFallback);
        }

        return array_values(array_filter(
            $stmt->fetchAll(),
            fn(array $row): bool => !$this->isDriverEmploymentContractDocumentType((string) ($row['document_type'] ?? ''))
        ));
    }

    public function getConfiguredDriverDocumentTypeById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $sqlWithRequirement = '
            SELECT
                id,
                document_type,
                requires_expiry,
                custom_fields_json,
                created_at,
                updated_at
            FROM configurare_documente_obligatorii_soferi
            WHERE id = :id
            LIMIT 1
        ';

        $sqlFallback = '
            SELECT
                id,
                document_type,
                1 AS requires_expiry,
                custom_fields_json,
                created_at,
                updated_at
            FROM configurare_documente_obligatorii_soferi
            WHERE id = :id
            LIMIT 1
        ';

        try {
            $stmt = $this->db->prepare($sqlWithRequirement);
        } catch (Throwable) {
            $stmt = $this->db->prepare($sqlFallback);
        }
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function updateConfiguredDriverDocumentTypeCustomFields(int $id, array $customFields): bool
    {
        if ($id <= 0) {
            return false;
        }

        $stmt = $this->db->prepare('
            UPDATE configurare_documente_obligatorii_soferi
            SET custom_fields_json = :custom_fields_json,
                updated_at = :updated_at
            WHERE id = :id
        ');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        $encoded = $this->encodeDriverDocumentCustomFieldConfigs($customFields);
        if ($encoded === null) {
            $stmt->bindValue(':custom_fields_json', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':custom_fields_json', $encoded, PDO::PARAM_STR);
        }

        $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'), PDO::PARAM_STR);

        return $stmt->execute();
    }

    public function updateConfiguredDriverDocumentTypeRequiresExpiry(int $id, bool $requiresExpiry): bool
    {
        if ($id <= 0) {
            return false;
        }

        $stmt = $this->db->prepare('
            UPDATE configurare_documente_obligatorii_soferi
            SET requires_expiry = :requires_expiry,
                updated_at = :updated_at
            WHERE id = :id
        ');
        $stmt->bindValue(':requires_expiry', $requiresExpiry ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function getDriverDocumentExpiryRequirementByType(): array
    {
        try {
            $stmt = $this->db->query('
                SELECT document_type, requires_expiry
                FROM configurare_documente_obligatorii_soferi
                ORDER BY document_type ASC
            ');
        } catch (Throwable) {
            $stmt = $this->db->query('
                SELECT document_type, 1 AS requires_expiry
                FROM configurare_documente_obligatorii_soferi
                ORDER BY document_type ASC
            ');
        }

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $documentType = trim((string) ($row['document_type'] ?? ''));
            if ($documentType === '') {
                continue;
            }

            $map[$documentType] = (int) ($row['requires_expiry'] ?? 1) === 1;
        }

        return $map;
    }

    public function driverDocumentTypeRequiresExpiry(string $documentType): bool
    {
        $documentType = trim($documentType);
        if ($documentType === '') {
            return true;
        }

        try {
            $stmt = $this->db->prepare('
                SELECT requires_expiry
                FROM configurare_documente_obligatorii_soferi
                WHERE document_type = :document_type
                LIMIT 1
            ');
            $stmt->bindValue(':document_type', $documentType, PDO::PARAM_STR);
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

    public function updateAllConfiguredDriverDocumentTypeCustomFields(array $customFields): bool
    {
        $stmt = $this->db->prepare('
            UPDATE configurare_documente_obligatorii_soferi
            SET custom_fields_json = :custom_fields_json,
                updated_at = :updated_at
        ');

        $encoded = $this->encodeDriverDocumentCustomFieldConfigs($customFields);
        if ($encoded === null) {
            $stmt->bindValue(':custom_fields_json', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':custom_fields_json', $encoded, PDO::PARAM_STR);
        }

        $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'), PDO::PARAM_STR);

        return $stmt->execute();
    }

    public function getSharedDriverDocumentCustomFieldConfigs(): array
    {
        $rows = $this->getConfiguredDriverDocumentTypes();
        $shared = [];

        foreach ($rows as $row) {
            $customFields = $this->decodeDriverDocumentCustomFieldConfigs($row['custom_fields_json'] ?? null);
            foreach ($customFields as $customField) {
                $fieldKey = (string) ($customField['key'] ?? '');
                if ($fieldKey === '' || isset($shared[$fieldKey])) {
                    continue;
                }

                $shared[$fieldKey] = $customField;
            }
        }

        return array_values($shared);
    }

    public function getDriverDocumentCustomFieldConfigsByType(): array
    {
        $rows = $this->getConfiguredDriverDocumentTypes();
        $map = [];

        foreach ($rows as $row) {
            $documentType = trim((string) ($row['document_type'] ?? ''));
            if ($documentType === '') {
                continue;
            }

            $map[$documentType] = $this->decodeDriverDocumentCustomFieldConfigs($row['custom_fields_json'] ?? null);
        }

        return $map;
    }

    public function getDriverDocumentCustomFieldConfigsForDocumentType(string $documentType): array
    {
        $documentType = trim($documentType);
        if ($documentType === '') {
            return [];
        }

        $stmt = $this->db->prepare('
            SELECT custom_fields_json
            FROM configurare_documente_obligatorii_soferi
            WHERE document_type = :document_type
            LIMIT 1
        ');
        $stmt->bindValue(':document_type', $documentType, PDO::PARAM_STR);
        $stmt->execute();

        $row = $stmt->fetch();
        if (!$row) {
            return [];
        }

        return $this->decodeDriverDocumentCustomFieldConfigs($row['custom_fields_json'] ?? null);
    }

    public function deleteConfiguredDriverDocumentType(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        $stmt = $this->db->prepare('DELETE FROM configurare_documente_obligatorii_soferi WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
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
        $items = [];

        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }

            $key = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($item['key'] ?? ''));
            $label = trim((string) ($item['label'] ?? ''));
            $type = strtolower(trim((string) ($item['type'] ?? 'text')));
            $showWhenChecked = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($item['show_when_checked'] ?? ''));

            if ($key === '' || $label === '' || !in_array($type, $allowedTypes, true)) {
                continue;
            }

            $items[$key] = [
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'show_when_checked' => is_string($showWhenChecked) ? $showWhenChecked : '',
            ];
        }

        $checkboxKeys = [];
        foreach ($items as $fieldKey => $item) {
            if (($item['type'] ?? 'text') === 'checkbox') {
                $checkboxKeys[$fieldKey] = true;
            }
        }

        foreach ($items as $fieldKey => &$item) {
            $showWhenChecked = (string) ($item['show_when_checked'] ?? '');
            if (
                $showWhenChecked === ''
                || $showWhenChecked === $fieldKey
                || !isset($checkboxKeys[$showWhenChecked])
            ) {
                unset($item['show_when_checked']);
            }
        }
        unset($item);

        return array_values($items);
    }

    private function encodeDriverDocumentCustomFieldConfigs(array $customFields): ?string
    {
        $normalized = [];
        $allowedTypes = ['text', 'number', 'date', 'checkbox'];

        foreach ($customFields as $item) {
            if (!is_array($item)) {
                continue;
            }

            $key = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($item['key'] ?? ''));
            $label = trim((string) ($item['label'] ?? ''));
            $type = strtolower(trim((string) ($item['type'] ?? 'text')));
            $showWhenChecked = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($item['show_when_checked'] ?? ''));

            if ($key === '' || $label === '' || !in_array($type, $allowedTypes, true)) {
                continue;
            }

            $normalized[$key] = [
                'key' => $key,
                'label' => $label,
                'type' => $type,
            ];

            if (is_string($showWhenChecked) && $showWhenChecked !== '' && $showWhenChecked !== $key) {
                $normalized[$key]['show_when_checked'] = $showWhenChecked;
            }
        }

        if ($normalized === []) {
            return null;
        }

        return json_encode(array_values($normalized), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function getExistingDriverDocumentTypeOptionsByDriverIds(array $driverIds): array
    {
        $driverIds = array_values(array_unique(array_filter(array_map(
            static fn(mixed $id): int => (int) $id,
            $driverIds
        ))));

        if ($driverIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($driverIds), '?'));
        $sql = '
            SELECT driver_id, tip_document
            FROM documente_soferi
            WHERE driver_id IN (' . $placeholders . ')
            ORDER BY driver_id ASC, tip_document ASC, id DESC
        ';

        $stmt = $this->db->prepare($sql);
        foreach ($driverIds as $index => $driverId) {
            $stmt->bindValue($index + 1, $driverId, PDO::PARAM_INT);
        }
        $stmt->execute();

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $driverId = (int) ($row['driver_id'] ?? 0);
            if ($driverId <= 0) {
                continue;
            }

            $documentType = trim((string) ($row['tip_document'] ?? ''));
            if ($documentType === '') {
                continue;
            }

            $driverKey = (string) $driverId;
            if (!isset($map[$driverKey])) {
                $map[$driverKey] = [];
            }

            $preferredLabel = $this->preferredDriverDocumentTypeLabel($documentType);
            $map[$driverKey][$preferredLabel] = $preferredLabel;
        }

        return $map;
    }

    public function getExistingDriverDocumentTypeOptionsForDriver(int $driverId): array
    {
        if ($driverId <= 0) {
            return [];
        }

        $map = $this->getExistingDriverDocumentTypeOptionsByDriverIds([$driverId]);
        return $map[(string) $driverId] ?? [];
    }

    public function getRemainingValidityDaysByDriverIds(array $driverIds): array
    {
        $driverIds = array_values(array_unique(array_filter(array_map(
            static fn(mixed $id): int => (int) $id,
            $driverIds
        ))));

        if ($driverIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($driverIds), '?'));
        $sql = '
            SELECT
                d.driver_id,
                d.tip_document,
                MAX(d.data_expirare) AS latest_expiry
            FROM documente_soferi d
            WHERE d.driver_id IN (' . $placeholders . ')
            GROUP BY d.driver_id, d.tip_document
            ORDER BY d.driver_id ASC, d.tip_document ASC
        ';

        $stmt = $this->db->prepare($sql);
        foreach ($driverIds as $index => $driverId) {
            $stmt->bindValue($index + 1, $driverId, PDO::PARAM_INT);
        }
        $stmt->execute();

        $today = new DateTime('today');
        $map = [];

        foreach ($stmt->fetchAll() as $row) {
            $driverId = (int) ($row['driver_id'] ?? 0);
            $documentType = trim((string) ($row['tip_document'] ?? ''));
            $latestExpiry = trim((string) ($row['latest_expiry'] ?? ''));
            if ($driverId <= 0 || $documentType === '' || $latestExpiry === '') {
                continue;
            }

            try {
                $expiryDate = new DateTime($latestExpiry);
                $days = (int) $today->diff($expiryDate)->format('%r%a');
            } catch (Exception) {
                continue;
            }

            $driverKey = (string) $driverId;
            if (!isset($map[$driverKey])) {
                $map[$driverKey] = [];
            }

            foreach ($this->driverDocumentTypeLookupKeys($documentType) as $lookupKey) {
                $remainingDays = max(1, $days);
                $map[$driverKey][$lookupKey] = max((int) ($map[$driverKey][$lookupKey] ?? 0), $remainingDays);
            }
        }

        return $map;
    }

    public function isDocumentTypeAllowedForDriver(int $driverId, string $documentType): bool
    {
        $documentType = trim($documentType);
        if ($driverId <= 0 || $documentType === '') {
            return false;
        }

        $options = $this->getDriverDocumentTypeOptionsForDriver($driverId);

        return array_key_exists($documentType, $options);
    }

    public function driverDocumentTypeExists(int $driverId, string $documentType, ?int $excludeDocumentId = null): bool
    {
        $documentType = trim($documentType);
        if ($driverId <= 0 || $documentType === '') {
            return false;
        }

        $sql = '
            SELECT id, tip_document
            FROM documente_soferi
            WHERE driver_id = :driver_id
        ';

        $excludeDocumentId = $excludeDocumentId !== null && $excludeDocumentId > 0
            ? $excludeDocumentId
            : null;

        if ($excludeDocumentId !== null) {
            $sql .= ' AND id <> :exclude_document_id';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':driver_id', $driverId, PDO::PARAM_INT);

        if ($excludeDocumentId !== null) {
            $stmt->bindValue(':exclude_document_id', $excludeDocumentId, PDO::PARAM_INT);
        }

        $stmt->execute();

        $targetKey = $this->driverDocumentTypeCanonicalKey($documentType);
        foreach ($stmt->fetchAll() as $row) {
            if ($targetKey === $this->driverDocumentTypeCanonicalKey((string) ($row['tip_document'] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    public function getConfiguredDocumentTypes(): array
    {
        $sqlWithRequirement = '
            SELECT
                id,
                vehicle_type,
                document_type,
                document_cost,
                validity_days,
                requires_expiry,
                custom_fields_json,
                created_at,
                updated_at
            FROM configurare_costuri_documente_vehicule
            ORDER BY vehicle_type ASC, document_type ASC
        ';

        $sqlFallback = '
            SELECT
                id,
                vehicle_type,
                document_type,
                document_cost,
                validity_days,
                1 AS requires_expiry,
                NULL AS custom_fields_json,
                created_at,
                updated_at
            FROM configurare_costuri_documente_vehicule
            ORDER BY vehicle_type ASC, document_type ASC
        ';

        try {
            $stmt = $this->db->query($sqlWithRequirement);
        } catch (Throwable) {
            $stmt = $this->db->query($sqlFallback);
        }

        return $stmt->fetchAll();
    }

    public function getConfiguredDocumentTypeById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $sqlWithCustomFields = '
            SELECT
                id,
                vehicle_type,
                document_type,
                requires_expiry,
                custom_fields_json,
                created_at,
                updated_at
            FROM configurare_costuri_documente_vehicule
            WHERE id = :id
            LIMIT 1
        ';

        $sqlFallback = '
            SELECT
                id,
                vehicle_type,
                document_type,
                1 AS requires_expiry,
                NULL AS custom_fields_json,
                created_at,
                updated_at
            FROM configurare_costuri_documente_vehicule
            WHERE id = :id
            LIMIT 1
        ';

        try {
            $stmt = $this->db->prepare($sqlWithCustomFields);
        } catch (Throwable) {
            $stmt = $this->db->prepare($sqlFallback);
        }
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function updateConfiguredDocumentTypeCustomFields(int $id, array $customFields): bool
    {
        if ($id <= 0) {
            return false;
        }

        $stmt = $this->db->prepare('
            UPDATE configurare_costuri_documente_vehicule
            SET custom_fields_json = :custom_fields_json,
                updated_at = :updated_at
            WHERE id = :id
        ');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        $encoded = $this->encodeDriverDocumentCustomFieldConfigs($customFields);
        if ($encoded === null) {
            $stmt->bindValue(':custom_fields_json', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':custom_fields_json', $encoded, PDO::PARAM_STR);
        }

        $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'), PDO::PARAM_STR);

        return $stmt->execute();
    }

    public function updateConfiguredDocumentTypeRequiresExpiry(int $id, bool $requiresExpiry): bool
    {
        if ($id <= 0) {
            return false;
        }

        $stmt = $this->db->prepare('
            UPDATE configurare_costuri_documente_vehicule
            SET requires_expiry = :requires_expiry,
                updated_at = :updated_at
            WHERE id = :id
        ');
        $stmt->bindValue(':requires_expiry', $requiresExpiry ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'));
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function deleteConfiguredDocumentType(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        $stmt = $this->db->prepare('DELETE FROM configurare_costuri_documente_vehicule WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function getVehicleDocumentCustomFieldConfigsByVehicleType(): array
    {
        $rows = $this->getConfiguredDocumentTypes();
        $map = [];

        foreach ($rows as $row) {
            $vehicleType = $this->normalizeVehicleTypeForDocumentConfig((string) ($row['vehicle_type'] ?? 'autovehicul'));
            $documentType = trim((string) ($row['document_type'] ?? ''));
            if ($documentType === '') {
                continue;
            }

            if (!isset($map[$vehicleType])) {
                $map[$vehicleType] = [];
            }

            $map[$vehicleType][$documentType] = $this->decodeDriverDocumentCustomFieldConfigs($row['custom_fields_json'] ?? null);
        }

        if (isset($map['semiremorca_primar']) && !isset($map['semiremorca'])) {
            $map['semiremorca'] = $map['semiremorca_primar'];
        }

        return $map;
    }

    public function getVehicleDocumentCustomFieldConfigsForVehicleType(string $vehicleType, string $documentType): array
    {
        $vehicleType = $this->normalizeVehicleTypeForDocumentConfig($vehicleType);
        $documentType = trim($documentType);
        if ($vehicleType === '' || $documentType === '') {
            return [];
        }

        $stmt = $this->db->prepare('
            SELECT custom_fields_json
            FROM configurare_costuri_documente_vehicule
            WHERE vehicle_type = :vehicle_type
              AND document_type = :document_type
            LIMIT 1
        ');
        $stmt->bindValue(':vehicle_type', $vehicleType, PDO::PARAM_STR);
        $stmt->bindValue(':document_type', $documentType, PDO::PARAM_STR);
        $stmt->execute();

        $row = $stmt->fetch();
        if (!$row) {
            return [];
        }

        return $this->decodeDriverDocumentCustomFieldConfigs($row['custom_fields_json'] ?? null);
    }

    public function getVehicleDocumentCustomFieldConfigsForVehicle(int $vehicleId, string $documentType): array
    {
        $documentType = trim($documentType);
        if ($vehicleId <= 0 || $documentType === '') {
            return [];
        }

        $stmt = $this->db->prepare('SELECT tip_vehicul FROM vehicule WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $vehicleId, PDO::PARAM_INT);
        $stmt->execute();

        $vehicleType = $stmt->fetchColumn();
        if (!is_string($vehicleType) || trim($vehicleType) === '') {
            return [];
        }

        return $this->getVehicleDocumentCustomFieldConfigsForVehicleType($vehicleType, $documentType);
    }

    public function getDocumentExpiryRequirementByVehicleType(): array
    {
        $sql = '
            SELECT vehicle_type, document_type, requires_expiry
            FROM configurare_costuri_documente_vehicule
            ORDER BY vehicle_type ASC, document_type ASC
        ';

        try {
            $stmt = $this->db->query($sql);
        } catch (Throwable) {
            return [];
        }

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $vehicleType = $this->normalizeVehicleTypeForDocumentConfig((string) ($row['vehicle_type'] ?? 'autovehicul'));
            $documentType = trim((string) ($row['document_type'] ?? ''));
            if ($documentType === '') {
                continue;
            }

            if (!isset($map[$vehicleType])) {
                $map[$vehicleType] = [];
            }

            $map[$vehicleType][$documentType] = (int) ($row['requires_expiry'] ?? 1) === 1;
        }

        if (isset($map['semiremorca_primar']) && !isset($map['semiremorca'])) {
            $map['semiremorca'] = $map['semiremorca_primar'];
        }

        return $map;
    }

    public function documentTypeRequiresExpiryForVehicle(int $vehicleId, string $documentType): bool
    {
        $documentType = trim($documentType);
        if ($vehicleId <= 0 || $documentType === '') {
            return true;
        }

        $stmt = $this->db->prepare('SELECT tip_vehicul FROM vehicule WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $vehicleId, PDO::PARAM_INT);
        $stmt->execute();

        $vehicleType = $stmt->fetchColumn();
        if (!is_string($vehicleType) || trim($vehicleType) === '') {
            return true;
        }

        $normalizedType = $this->normalizeVehicleTypeForDocumentConfig($vehicleType);
        $sql = '
            SELECT requires_expiry
            FROM configurare_costuri_documente_vehicule
            WHERE vehicle_type = :vehicle_type
              AND document_type = :document_type
            LIMIT 1
        ';

        try {
            $requirementStmt = $this->db->prepare($sql);
            $requirementStmt->bindValue(':vehicle_type', $normalizedType);
            $requirementStmt->bindValue(':document_type', $documentType);
            $requirementStmt->execute();
            $value = $requirementStmt->fetchColumn();
        } catch (Throwable) {
            return true;
        }

        if ($value === false) {
            return true;
        }

        return (int) $value === 1;
    }

    public function getVehicleTypeByVehicleIds(array $vehicleIds): array
    {
        $vehicleIds = array_values(array_unique(array_filter(array_map(
            static fn(mixed $id): int => (int) $id,
            $vehicleIds
        ))));

        if ($vehicleIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($vehicleIds), '?'));
        $sql = 'SELECT id, tip_vehicul FROM vehicule WHERE id IN (' . $placeholders . ')';
        $stmt = $this->db->prepare($sql);

        foreach ($vehicleIds as $index => $vehicleId) {
            $stmt->bindValue($index + 1, $vehicleId, PDO::PARAM_INT);
        }

        $stmt->execute();

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $map[(string) $id] = $this->normalizeVehicleTypeForDocumentConfig((string) ($row['tip_vehicul'] ?? 'autovehicul'));
        }

        return $map;
    }

    public function getDocumentTypeOptionsForVehicle(int $vehicleId): array
    {
        if ($vehicleId <= 0) {
            return [];
        }

        $stmt = $this->db->prepare('SELECT tip_vehicul FROM vehicule WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $vehicleId, PDO::PARAM_INT);
        $stmt->execute();

        $vehicleType = $stmt->fetchColumn();
        if (!is_string($vehicleType) || trim($vehicleType) === '') {
            return [];
        }

        $normalizedType = $this->normalizeVehicleTypeForDocumentConfig($vehicleType);
        $optionsByType = $this->getVehicleDocumentTypeOptionsByVehicleType();

        return $optionsByType[$normalizedType] ?? [];
    }

    public function getExistingDocumentTypeOptionsByVehicleIds(array $vehicleIds): array
    {
        $vehicleIds = array_values(array_unique(array_filter(array_map(
            static fn(mixed $id): int => (int) $id,
            $vehicleIds
        ))));

        if ($vehicleIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($vehicleIds), '?'));
        $sql = '
            SELECT d.vehicle_id, d.tip_document
            FROM documente d
            WHERE d.vehicle_id IN (' . $placeholders . ')
            ORDER BY d.vehicle_id ASC, d.tip_document ASC, d.id DESC
        ';

        $stmt = $this->db->prepare($sql);
        foreach ($vehicleIds as $index => $vehicleId) {
            $stmt->bindValue($index + 1, $vehicleId, PDO::PARAM_INT);
        }
        $stmt->execute();

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $vehicleId = (int) ($row['vehicle_id'] ?? 0);
            if ($vehicleId <= 0) {
                continue;
            }

            $documentType = trim((string) ($row['tip_document'] ?? ''));
            if ($documentType === '') {
                continue;
            }

            $vehicleKey = (string) $vehicleId;
            if (!isset($map[$vehicleKey])) {
                $map[$vehicleKey] = [];
            }

            $map[$vehicleKey][$documentType] = $documentType;
        }

        return $map;
    }

    public function getExistingDocumentTypeOptionsForVehicle(int $vehicleId): array
    {
        if ($vehicleId <= 0) {
            return [];
        }

        $map = $this->getExistingDocumentTypeOptionsByVehicleIds([$vehicleId]);
        return $map[(string) $vehicleId] ?? [];
    }

    public function getConfiguredValidityDaysByVehicleIds(array $vehicleIds): array
    {
        $vehicleIds = array_values(array_unique(array_filter(array_map(
            static fn(mixed $id): int => (int) $id,
            $vehicleIds
        ))));

        if ($vehicleIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($vehicleIds), '?'));
        $sql = '
            SELECT
                v.id AS vehicle_id,
                c.document_type,
                c.validity_days
            FROM vehicule v
            LEFT JOIN configurare_costuri_documente_vehicule c
              ON c.vehicle_type = (
                    CASE
                        WHEN v.tip_vehicul = "autoturism" THEN "autovehicul"
                        WHEN v.tip_vehicul = "semiremorca" THEN "semiremorca_primar"
                        ELSE v.tip_vehicul
                    END
                )
            WHERE v.id IN (' . $placeholders . ')
            ORDER BY v.id ASC, c.document_type ASC
        ';

        $stmt = $this->db->prepare($sql);
        foreach ($vehicleIds as $index => $vehicleId) {
            $stmt->bindValue($index + 1, $vehicleId, PDO::PARAM_INT);
        }
        $stmt->execute();

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $vehicleId = (int) ($row['vehicle_id'] ?? 0);
            $documentType = trim((string) ($row['document_type'] ?? ''));
            $validityDays = (int) ($row['validity_days'] ?? 0);
            if ($vehicleId <= 0 || $documentType === '' || $validityDays <= 0) {
                continue;
            }

            $vehicleKey = (string) $vehicleId;
            if (!isset($map[$vehicleKey])) {
                $map[$vehicleKey] = [];
            }

            $map[$vehicleKey][$documentType] = $validityDays;
        }

        return $map;
    }

    public function getRemainingValidityDaysByVehicleIds(array $vehicleIds): array
    {
        $vehicleIds = array_values(array_unique(array_filter(array_map(
            static fn(mixed $id): int => (int) $id,
            $vehicleIds
        ))));

        if ($vehicleIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($vehicleIds), '?'));
        $sql = '
            SELECT
                d.vehicle_id,
                d.tip_document,
                MAX(d.data_expirare) AS latest_expiry
            FROM documente d
            WHERE d.vehicle_id IN (' . $placeholders . ')
            GROUP BY d.vehicle_id, d.tip_document
            ORDER BY d.vehicle_id ASC, d.tip_document ASC
        ';

        $stmt = $this->db->prepare($sql);
        foreach ($vehicleIds as $index => $vehicleId) {
            $stmt->bindValue($index + 1, $vehicleId, PDO::PARAM_INT);
        }
        $stmt->execute();

        $today = new DateTime('today');
        $map = [];

        foreach ($stmt->fetchAll() as $row) {
            $vehicleId = (int) ($row['vehicle_id'] ?? 0);
            $documentType = trim((string) ($row['tip_document'] ?? ''));
            $latestExpiry = trim((string) ($row['latest_expiry'] ?? ''));
            if ($vehicleId <= 0 || $documentType === '' || $latestExpiry === '') {
                continue;
            }

            try {
                $expiryDate = new DateTime($latestExpiry);
                $days = (int) $today->diff($expiryDate)->format('%r%a');
            } catch (Exception) {
                continue;
            }

            $vehicleKey = (string) $vehicleId;
            if (!isset($map[$vehicleKey])) {
                $map[$vehicleKey] = [];
            }

            // Keep at least 1 day to fit numeric validation (min 1) in cost-config forms.
            $map[$vehicleKey][$documentType] = max(1, $days);
        }

        return $map;
    }

    public function isDocumentTypeAllowedForVehicle(int $vehicleId, string $documentType): bool
    {
        $documentType = trim($documentType);
        if ($documentType === '' || $vehicleId <= 0) {
            return false;
        }

        $options = $this->getDocumentTypeOptionsForVehicle($vehicleId);

        return array_key_exists($documentType, $options);
    }

    public function getVehicleDocumentDailyCost(int $vehicleId): float
    {
        if ($vehicleId <= 0) {
            return 0.0;
        }

        $sqlWithOverride = '
            SELECT COALESCE(SUM(
                CASE
                    WHEN COALESCE(o.validity_days, c.validity_days) > 0
                        THEN COALESCE(o.document_cost, c.document_cost) / COALESCE(o.validity_days, c.validity_days)
                    ELSE 0
                END
            ), 0) AS daily_cost
            FROM vehicule v
            LEFT JOIN configurare_costuri_documente_vehicule c
              ON c.vehicle_type = (
                    CASE
                        WHEN v.tip_vehicul = "autoturism" THEN "autovehicul"
                        WHEN v.tip_vehicul = "semiremorca" THEN "semiremorca_primar"
                        ELSE v.tip_vehicul
                    END
                )
            LEFT JOIN configurare_costuri_documente_vehicule_override o
              ON o.vehicle_id = v.id
             AND o.document_type = c.document_type
            WHERE v.id = :vehicle_id
        ';

        $sqlByVehicleTypeOnly = '
            SELECT COALESCE(SUM(
                CASE
                    WHEN c.validity_days > 0
                        THEN c.document_cost / c.validity_days
                    ELSE 0
                END
            ), 0) AS daily_cost
            FROM vehicule v
            LEFT JOIN configurare_costuri_documente_vehicule c
              ON c.vehicle_type = (
                    CASE
                        WHEN v.tip_vehicul = "autoturism" THEN "autovehicul"
                        WHEN v.tip_vehicul = "semiremorca" THEN "semiremorca_primar"
                        ELSE v.tip_vehicul
                    END
                )
            WHERE v.id = :vehicle_id
        ';

        try {
            $stmt = $this->db->prepare($sqlWithOverride);
            $stmt->bindValue(':vehicle_id', $vehicleId, PDO::PARAM_INT);
            $stmt->execute();
            $value = $stmt->fetchColumn();
        } catch (Throwable) {
            $stmt = $this->db->prepare($sqlByVehicleTypeOnly);
            $stmt->bindValue(':vehicle_id', $vehicleId, PDO::PARAM_INT);
            $stmt->execute();
            $value = $stmt->fetchColumn();
        }

        if (!is_numeric((string) $value)) {
            return 0.0;
        }

        return (float) $value;
    }

    public function getNotificationSummary(?int $vehicleId = null): array
    {
        $conditions = [];
        $params = [];

        if ($vehicleId !== null) {
            $conditions[] = 'd.vehicle_id = :vehicle_id';
            $params[':vehicle_id'] = $vehicleId;
        }

        $whereSql = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

        $sql = '
            SELECT
                SUM(CASE WHEN d.data_expirare < CURDATE() THEN 1 ELSE 0 END) AS expirate,
                SUM(CASE WHEN d.data_expirare BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS expira_7_zile,
                SUM(CASE WHEN d.data_expirare BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS expira_30_zile,
                SUM(CASE WHEN d.fisier_stocat IS NULL OR d.fisier_stocat = "" THEN 1 ELSE 0 END) AS fara_fisier
            FROM documente d
            ' . $whereSql . '
        ';

        $stmt = $this->db->prepare($sql);
        $this->bindAll($stmt, $params);
        $stmt->execute();

        $row = $stmt->fetch() ?: [];

        return [
            'expirate' => (int) ($row['expirate'] ?? 0),
            'expira_7_zile' => (int) ($row['expira_7_zile'] ?? 0),
            'expira_30_zile' => (int) ($row['expira_30_zile'] ?? 0),
            'fara_fisier' => (int) ($row['fara_fisier'] ?? 0),
        ];
    }

    public function getUrgentDocuments(?int $vehicleId = null, int $limit = 5): array
    {
        $sql = '
            SELECT d.id,
                   d.tip_document,
                   d.numar_document,
                   d.data_expirare,
                   d.fisier_original,
                   v.nr_inmatriculare AS vehicul_label
            FROM documente d
            INNER JOIN vehicule v ON v.id = d.vehicle_id
            WHERE d.data_expirare <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ';

        $params = [];

        if ($vehicleId !== null) {
            $sql .= ' AND d.vehicle_id = :vehicle_id';
            $params[':vehicle_id'] = $vehicleId;
        }

        $sql .= '
            ORDER BY d.data_expirare IS NULL ASC, d.data_expirare ASC, d.id DESC
            LIMIT :limit_rows
        ';

        $stmt = $this->db->prepare($sql);
        $this->bindAll($stmt, $params);
        $stmt->bindValue(':limit_rows', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getAuditLogsForDocument(int $documentId, int $limit = 15): array
    {
        $sql = '
            SELECT a.*,
                   u.nume AS utilizator_nume
            FROM audit_log a
            LEFT JOIN utilizatori u ON u.id = a.user_id
            WHERE a.modul = :modul
              AND a.record_id = :record_id
            ORDER BY a.created_at DESC, a.id DESC
            LIMIT :limit_rows
        ';

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':modul', 'documente', PDO::PARAM_STR);
        $stmt->bindValue(':record_id', $documentId, PDO::PARAM_INT);
        $stmt->bindValue(':limit_rows', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getDocumentsForVehicle(int $vehicleId): array
    {
        $sql = '
            SELECT d.id,
                   d.vehicle_id,
                   d.tip_document,
                   d.numar_document,
                   d.data_expirare,
                   d.fisier_original,
                   d.fisier_stocat,
                   d.custom_fields_json,
                   d.updated_at
            FROM documente d
            WHERE d.vehicle_id = :vehicle_id
            ORDER BY d.data_expirare IS NULL ASC, d.data_expirare ASC, d.id DESC
        ';

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':vehicle_id', $vehicleId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getDocumentsForDriver(int $driverId): array
    {
        $sql = '
            SELECT d.id,
                   d.driver_id,
                   d.tip_document,
                   d.numar_document,
                   d.data_expirare,
                   d.fisier_original,
                   d.fisier_stocat,
                   d.custom_fields_json,
                   d.updated_at,
                   s.nume AS sofer_label,
                   s.telefon AS sofer_telefon,
                   v.nr_inmatriculare AS vehicul_label
            FROM documente_soferi d
            INNER JOIN soferi s ON s.id = d.driver_id
            LEFT JOIN vehicule v ON v.id = s.vehicle_id
            WHERE d.driver_id = :driver_id
            ORDER BY d.data_expirare IS NULL ASC, d.data_expirare ASC, d.id DESC
        ';

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':driver_id', $driverId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function logAudit(string $modul, int $recordId, string $actiune, string $descriere, ?int $userId, ?array $beforeData, ?array $afterData): void
    {
        $sql = '
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
        ';

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':modul', $modul, PDO::PARAM_STR);
        $stmt->bindValue(':record_id', $recordId, PDO::PARAM_INT);
        $stmt->bindValue(':actiune', $actiune, PDO::PARAM_STR);
        $stmt->bindValue(':descriere', $descriere, PDO::PARAM_STR);
        $stmt->bindValue(':before_data', $beforeData !== null ? json_encode($beforeData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null, $beforeData !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':after_data', $afterData !== null ? json_encode($afterData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null, $afterData !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);

        if ($userId !== null && $userId > 0) {
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        } else {
            $stmt->bindValue(':user_id', null, PDO::PARAM_NULL);
        }

        $stmt->bindValue(':created_at', date('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->execute();
    }

    private function bindAll(PDOStatement $stmt, array $params): void
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
}
