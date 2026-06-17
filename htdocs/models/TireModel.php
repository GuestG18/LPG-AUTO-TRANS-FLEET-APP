<?php
declare(strict_types=1);

class TireModel extends BaseModel
{
    private const STOCK_VEHICLE_PLATE = 'STOC-ANVELOPE';
    private const STOCK_VEHICLE_CHASSIS = 'STOCANVELOPE00001';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_IN_STOCK = 'in_stock';
    public const STATUS_SPARE = 'spare';
    public const STATUS_REMOVED = 'removed';
    public const STATUS_DAMAGED = 'damaged';
    public const STATUS_MISSING = 'missing';
    public const STATUS_SCRAPPED = 'scrapped';
    public const STATUS_RETREADED = 'retreaded';

    public const TIRE_TYPE_DIRECTION = 'direction';
    public const TIRE_TYPE_TRACTION = 'traction';
    public const TIRE_TYPE_TRAILER = 'trailer';
    public const TIRE_TYPE_BALLOON = 'balloon';
    public const TIRE_TYPE_BALLOON_DIRECTIONAL = 'balloon_directional';

    public const AXLE_STEERING = 'steering';
    public const AXLE_TRACTION = 'traction';
    public const AXLE_TRAILER = 'trailer';

    private bool $lifecycleSchemaEnsured = false;

    private function ensureLifecycleSchema(): void
    {
        if ($this->lifecycleSchemaEnsured) {
            return;
        }

        $this->lifecycleSchemaEnsured = true;

        $this->db->exec(
            "ALTER TABLE anvelope MODIFY COLUMN status ENUM('in_stock','active','spare','damaged','missing','removed','scrapped','retreaded') NOT NULL DEFAULT 'in_stock'"
        );

        $this->addColumnIfMissing('anvelope', 'tire_type', "ENUM('direction','traction','trailer','balloon','balloon_directional') NOT NULL DEFAULT 'trailer' AFTER target_vehicle_type");
        $this->addColumnIfMissing('anvelope', 'usage_compatibility', 'VARCHAR(190) NULL AFTER tire_type');
        $this->addColumnIfMissing('anvelope', 'location_label', 'VARCHAR(120) NULL AFTER usage_compatibility');
        $this->addColumnIfMissing('anvelope', 'manufacturing_year', 'SMALLINT UNSIGNED NULL AFTER dot_code');
        $this->addColumnIfMissing('anvelope', 'purchase_date', 'DATE NULL AFTER manufacturing_year');
        $this->addColumnIfMissing('anvelope', 'purchase_price', 'DECIMAL(12,2) NULL AFTER purchase_date');
        $this->addColumnIfMissing('anvelope', 'supplier', 'VARCHAR(190) NULL AFTER purchase_price');
        $this->addColumnIfMissing('anvelope', 'invoice_number', 'VARCHAR(120) NULL AFTER supplier');
        $this->addColumnIfMissing('anvelope', 'current_mileage', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER km_initial');
        $this->addColumnIfMissing('anvelope', 'estimated_remaining_km', 'INT UNSIGNED NULL AFTER estimated_life_km');
        $this->addColumnIfMissing('anvelope', 'initial_condition', "ENUM('good','acceptable','high_wear','critical','missing') NOT NULL DEFAULT 'good' AFTER min_tread_depth_mm");
        $this->addColumnIfMissing('anvelope', 'condition_status', "ENUM('good','acceptable','high_wear','critical','missing') NOT NULL DEFAULT 'good' AFTER initial_condition");
        $this->addColumnIfMissing('anvelope', 'season', "ENUM('summer','winter','all_season') NOT NULL DEFAULT 'all_season' AFTER condition_status");
        $this->addColumnIfMissing('anvelope', 'directional', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER season');
        $this->addColumnIfMissing('anvelope', 'rotation_direction', 'VARCHAR(20) NULL AFTER directional');
        $this->addColumnIfMissing('vehicule_anvelope_pozitii', 'axle_type', "ENUM('steering','traction','trailer') NOT NULL DEFAULT 'steering' AFTER axle_no");

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS anvelope_istoric (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tire_id INT UNSIGNED NOT NULL,
                old_vehicle_id INT UNSIGNED NULL,
                new_vehicle_id INT UNSIGNED NULL,
                old_position_id INT UNSIGNED NULL,
                new_position_id INT UNSIGNED NULL,
                old_axle_no TINYINT UNSIGNED NULL,
                new_axle_no TINYINT UNSIGNED NULL,
                old_position_label VARCHAR(120) NULL,
                new_position_label VARCHAR(120) NULL,
                old_status VARCHAR(40) NULL,
                new_status VARCHAR(40) NULL,
                reason VARCHAR(190) NULL,
                observation TEXT NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_anvelope_istoric_tire (tire_id),
                INDEX idx_anvelope_istoric_created_at (created_at),
                CONSTRAINT fk_anvelope_istoric_tire FOREIGN KEY (tire_id) REFERENCES anvelope(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS anvelope_tip_compatibilitate (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tire_type VARCHAR(40) NOT NULL,
                vehicle_type VARCHAR(40) NOT NULL DEFAULT "universal",
                axle_type VARCHAR(40) NOT NULL,
                is_allowed TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uniq_tire_compatibility (tire_type, vehicle_type, axle_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $this->seedDefaultCompatibilityRules();
    }

    private function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        if ($this->columnExists($table, $column)) {
            return;
        }

        $this->db->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND COLUMN_NAME = :column_name'
        );
        $stmt->execute([
            ':table_name' => $table,
            ':column_name' => $column,
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function seedDefaultCompatibilityRules(): void
    {
        $now = date('Y-m-d H:i:s');
        $rules = [
            [self::TIRE_TYPE_DIRECTION, 'universal', self::AXLE_STEERING],
            [self::TIRE_TYPE_TRACTION, 'universal', self::AXLE_TRACTION],
            [self::TIRE_TYPE_TRAILER, 'universal', self::AXLE_TRAILER],
            [self::TIRE_TYPE_BALLOON, 'cap_tractor', self::AXLE_STEERING],
            [self::TIRE_TYPE_BALLOON, 'cap_tractor', self::AXLE_TRACTION],
            [self::TIRE_TYPE_BALLOON, 'semiremorca', self::AXLE_TRAILER],
            [self::TIRE_TYPE_BALLOON_DIRECTIONAL, 'cap_tractor', self::AXLE_STEERING],
            [self::TIRE_TYPE_BALLOON_DIRECTIONAL, 'cap_tractor', self::AXLE_TRACTION],
            [self::TIRE_TYPE_BALLOON_DIRECTIONAL, 'semiremorca', self::AXLE_TRAILER],
        ];

        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO anvelope_tip_compatibilitate
                (tire_type, vehicle_type, axle_type, is_allowed, created_at, updated_at)
             VALUES
                (:tire_type, :vehicle_type, :axle_type, 1, :created_at, :updated_at)'
        );

        foreach ($rules as $rule) {
            $stmt->execute([
                ':tire_type' => $rule[0],
                ':vehicle_type' => $rule[1],
                ':axle_type' => $rule[2],
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        }
    }

    public function getTireStatusOptions(bool $includeLegacy = false): array
    {
        $options = [
            self::STATUS_IN_STOCK => 'In stoc',
            self::STATUS_ACTIVE => 'Montata',
            self::STATUS_SPARE => 'Rezerva',
            self::STATUS_DAMAGED => 'Deteriorata',
            self::STATUS_MISSING => 'Lipsa',
            self::STATUS_REMOVED => 'Scoasa din uz',
            self::STATUS_SCRAPPED => 'Casata',
        ];

        if ($includeLegacy) {
            $options[self::STATUS_RETREADED] = 'Resapata';
        }

        return $options;
    }

    public function getTireTypeOptions(): array
    {
        return [
            self::TIRE_TYPE_DIRECTION => 'Directie',
            self::TIRE_TYPE_TRACTION => 'Tractiune',
            self::TIRE_TYPE_TRAILER => 'Remorca',
            self::TIRE_TYPE_BALLOON => 'Balon',
            self::TIRE_TYPE_BALLOON_DIRECTIONAL => 'Balon directional',
        ];
    }

    public function getConditionOptions(): array
    {
        return [
            'good' => 'Buna',
            'acceptable' => 'Acceptabila',
            'high_wear' => 'Uzura mare',
            'critical' => 'Critica',
            'missing' => 'Lipsa',
        ];
    }

    public function getSeasonOptions(): array
    {
        return [
            'summer' => 'Vara',
            'winter' => 'Iarna',
            'all_season' => 'All season',
        ];
    }

    public function getAxleTypeOptions(): array
    {
        return [
            self::AXLE_STEERING => 'Directie',
            self::AXLE_TRACTION => 'Tractiune',
            self::AXLE_TRAILER => 'Remorca',
        ];
    }

    public function normalizeTireStatus(?string $status): string
    {
        $value = strtolower(trim((string) $status));

        return match ($value) {
            'mounted', 'montata', self::STATUS_ACTIVE => self::STATUS_ACTIVE,
            'stock', 'in stock', 'in_stoc', self::STATUS_IN_STOCK => self::STATUS_IN_STOCK,
            'reserve', 'rezerva', self::STATUS_SPARE, self::STATUS_RETREADED => self::STATUS_SPARE,
            'deteriorata', self::STATUS_DAMAGED => self::STATUS_DAMAGED,
            'lipsa', self::STATUS_MISSING => self::STATUS_MISSING,
            'scoasa_din_uz', self::STATUS_REMOVED => self::STATUS_REMOVED,
            'casata', self::STATUS_SCRAPPED => self::STATUS_SCRAPPED,
            default => self::STATUS_IN_STOCK,
        };
    }

    public function normalizeTireType(?string $type): string
    {
        $value = strtolower(trim((string) $type));

        return match ($value) {
            'direction', 'directie', 'directie_ax' => self::TIRE_TYPE_DIRECTION,
            'traction', 'tractiune' => self::TIRE_TYPE_TRACTION,
            'trailer', 'remorca' => self::TIRE_TYPE_TRAILER,
            'balloon', 'balon' => self::TIRE_TYPE_BALLOON,
            'balloon_directional', 'balon_directional', 'balon directie', 'balon directional' => self::TIRE_TYPE_BALLOON_DIRECTIONAL,
            default => self::TIRE_TYPE_TRAILER,
        };
    }

    public function normalizeCondition(?string $condition): string
    {
        $value = strtolower(trim((string) $condition));

        return match ($value) {
            'good', 'buna' => 'good',
            'acceptable', 'acceptabila' => 'acceptable',
            'high_wear', 'uzura_mare', 'uzura mare' => 'high_wear',
            'critical', 'critica' => 'critical',
            'missing', 'lipsa' => 'missing',
            default => 'good',
        };
    }

    public function tireStatusMeta(?string $status): array
    {
        $normalized = $this->normalizeTireStatus($status);

        return match ($normalized) {
            self::STATUS_ACTIVE => ['value' => $normalized, 'label' => 'Montata', 'badge_class' => 'tire-status-badge tire-status-mounted'],
            self::STATUS_SPARE => ['value' => $normalized, 'label' => 'Rezerva', 'badge_class' => 'tire-status-badge tire-status-spare'],
            self::STATUS_DAMAGED => ['value' => $normalized, 'label' => 'Deteriorata', 'badge_class' => 'tire-status-badge tire-status-damaged'],
            self::STATUS_MISSING => ['value' => $normalized, 'label' => 'Lipsa', 'badge_class' => 'tire-status-badge tire-status-missing'],
            self::STATUS_REMOVED => ['value' => $normalized, 'label' => 'Scoasa din uz', 'badge_class' => 'tire-status-badge tire-status-removed'],
            self::STATUS_SCRAPPED => ['value' => $normalized, 'label' => 'Casata', 'badge_class' => 'tire-status-badge tire-status-scrapped'],
            default => ['value' => self::STATUS_IN_STOCK, 'label' => 'In stoc', 'badge_class' => 'tire-status-badge tire-status-stock'],
        };
    }

    public function tireTypeLabel(?string $type): string
    {
        $normalized = $this->normalizeTireType($type);
        return $this->getTireTypeOptions()[$normalized] ?? 'Remorca';
    }

    public function conditionMeta(?string $condition, ?float $wearPercent = null): array
    {
        $normalized = $this->normalizeCondition($condition);

        if ($wearPercent !== null) {
            if ($wearPercent >= 81.0) {
                $normalized = 'critical';
            } elseif ($wearPercent >= 61.0 && $normalized !== 'critical') {
                $normalized = 'high_wear';
            } elseif ($wearPercent >= 31.0 && !in_array($normalized, ['critical', 'high_wear'], true)) {
                $normalized = 'acceptable';
            }
        }

        return match ($normalized) {
            'missing' => [
                'value' => 'missing',
                'label' => 'Lipsa',
                'dot_class' => 'tire-dot tire-dot-red',
                'progress_class' => 'tire-progress-red',
                'badge_class' => 'tire-condition-badge tire-condition-missing',
            ],
            'critical' => [
                'value' => 'critical',
                'label' => 'Critica',
                'dot_class' => 'tire-dot tire-dot-red',
                'progress_class' => 'tire-progress-red',
                'badge_class' => 'tire-condition-badge tire-condition-critical',
            ],
            'high_wear' => [
                'value' => 'high_wear',
                'label' => 'Uzura mare',
                'dot_class' => 'tire-dot tire-dot-orange',
                'progress_class' => 'tire-progress-orange',
                'badge_class' => 'tire-condition-badge tire-condition-high',
            ],
            'acceptable' => [
                'value' => 'acceptable',
                'label' => 'Acceptabila',
                'dot_class' => 'tire-dot tire-dot-yellow',
                'progress_class' => 'tire-progress-yellow',
                'badge_class' => 'tire-condition-badge tire-condition-acceptable',
            ],
            default => [
                'value' => 'good',
                'label' => 'Buna',
                'dot_class' => 'tire-dot tire-dot-green',
                'progress_class' => 'tire-progress-green',
                'badge_class' => 'tire-condition-badge tire-condition-good',
            ],
        };
    }

    private function normalizeVehicleType(string $vehicleType): string
    {
        $normalized = strtolower(trim($vehicleType));

        return match ($normalized) {
            'autovehicul', 'autoturism', 'autoutilitara' => 'autovehicul',
            'camion' => 'camion',
            'cap_tractor' => 'cap_tractor',
            'semiremorca', 'semiremorca_primar', 'semiremorca_distributie' => 'semiremorca',
            default => 'autovehicul',
        };
    }

    private function vehicleTypeLabel(string $vehicleType): string
    {
        return match ($this->normalizeVehicleType($vehicleType)) {
            'cap_tractor' => 'Cap tractor',
            'semiremorca' => 'Semi-remorca',
            'camion' => 'Camion',
            default => 'Autoturism',
        };
    }

    public function normalizeTargetVehicleType(?string $vehicleType): string
    {
        $value = strtolower(trim((string) $vehicleType));
        if ($value === '' || $value === 'all' || $value === 'toate' || $value === 'universal') {
            return 'universal';
        }

        $normalized = $this->normalizeVehicleType($value);
        if (!in_array($normalized, ['autovehicul', 'camion', 'cap_tractor', 'semiremorca'], true)) {
            return 'universal';
        }

        return $normalized;
    }

    public function getTargetVehicleTypeOptions(): array
    {
        return [
            'universal' => 'Universal (orice tip)',
            'autovehicul' => 'Autoturism',
            'camion' => 'Camion',
            'cap_tractor' => 'Cap tractor',
            'semiremorca' => 'Semi-remorca',
        ];
    }

    public function isTireCompatibleWithVehicleType(array $tire, string $vehicleType): bool
    {
        $targetType = $this->normalizeTargetVehicleType((string) ($tire['target_vehicle_type'] ?? 'universal'));
        $normalizedVehicleType = $this->normalizeVehicleType($vehicleType);

        return $targetType === 'universal' || $targetType === $normalizedVehicleType;
    }

    private function sanitizeSerialPrefix(string $prefix): string
    {
        $prefix = strtoupper(trim($prefix));
        if ($prefix === '') {
            $prefix = 'STOC';
        }

        $prefix = preg_replace('/[^A-Z0-9\-_]+/', '-', $prefix) ?? 'STOC';
        $prefix = trim($prefix, '-_');
        if ($prefix === '') {
            $prefix = 'STOC';
        }

        return substr($prefix, 0, 36);
    }

    private function serialExists(string $serialNumber): bool
    {
        $stmt = $this->db->prepare('SELECT id FROM anvelope WHERE serial_number = :serial_number LIMIT 1');
        $stmt->execute([':serial_number' => $serialNumber]);
        return $stmt->fetchColumn() !== false;
    }

    private function randomSerialToken(int $length = 6): string
    {
        $length = max(4, min(16, $length));

        try {
            $bytes = random_bytes((int) ceil($length / 2));
            return strtoupper(substr(bin2hex($bytes), 0, $length));
        } catch (Throwable) {
            return strtoupper(substr(sha1(uniqid((string) mt_rand(), true)), 0, $length));
        }
    }

    private function generateUniqueSerial(string $prefix, int $sequence): string
    {
        $safePrefix = $this->sanitizeSerialPrefix($prefix);
        $timestamp = date('YmdHis');
        $sequencePart = str_pad((string) max(1, $sequence), 4, '0', STR_PAD_LEFT);

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $candidate = $safePrefix
                . '-'
                . $timestamp
                . '-'
                . $this->randomSerialToken(6)
                . '-'
                . $sequencePart;

            if (!$this->serialExists($candidate)) {
                return substr($candidate, 0, 120);
            }
        }

        throw new RuntimeException('Nu s-a putut genera un numar de serie unic pentru stoc.');
    }

    private function parseAxleCountFromLayout(?string $layout): ?int
    {
        if (!is_string($layout) || trim($layout) === '') {
            return null;
        }

        $layout = strtolower(trim($layout));

        if (preg_match('/^(\d+)\s*x\s*(\d+)$/', $layout, $matches) === 1) {
            $totalWheelPositions = (int) $matches[1];
            if ($totalWheelPositions >= 4 && $totalWheelPositions % 2 === 0) {
                return max(2, (int) ($totalWheelPositions / 2));
            }
        }

        if (preg_match('/(\d+)\s*axe?/', $layout, $matches) === 1) {
            $axleCount = (int) $matches[1];
            if ($axleCount > 0) {
                return $axleCount;
            }
        }

        if (preg_match('/^\d+$/', $layout) === 1) {
            $axleCount = (int) $layout;
            if ($axleCount > 0) {
                return $axleCount;
            }
        }

        return null;
    }

    public function normalizeLayoutForType(string $vehicleType, ?string $layout): string
    {
        $vehicleType = $this->normalizeVehicleType($vehicleType);
        $layoutValue = is_string($layout) ? trim($layout) : '';
        $layoutValueLower = strtolower($layoutValue);

        if ($vehicleType === 'autovehicul') {
            return '4x2';
        }

        if ($vehicleType === 'camion') {
            if (preg_match('/^(\d+)\s*x\s*(\d+)$/', $layoutValueLower, $matches) === 1) {
                $formula = ((int) $matches[1]) . 'x' . ((int) $matches[2]);
                if (in_array($formula, ['4x2', '6x2', '8x2'], true)) {
                    return $formula;
                }
                if ($formula === '6x4') {
                    return '6x2';
                }
                if ($formula === '8x4') {
                    return '8x2';
                }
            }

            $axleCount = $this->parseAxleCountFromLayout($layoutValueLower);
            if ($axleCount === 3) {
                return '6x2';
            }
            if ($axleCount === 4) {
                return '8x2';
            }

            return '4x2';
        }

        if ($vehicleType === 'semiremorca') {
            $axleCount = $this->parseAxleCountFromLayout($layoutValueLower);
            if (!in_array($axleCount, [2, 3, 4, 6], true)) {
                $axleCount = 3;
            }

            return $axleCount . ' axe';
        }

        if (preg_match('/^(\d+)\s*x\s*(\d+)$/', $layoutValueLower, $matches) === 1) {
            $formula = ((int) $matches[1]) . 'x' . ((int) $matches[2]);
            if (in_array($formula, ['4x2', '6x2', '6x4', '8x4'], true)) {
                return $formula;
            }
        }

        $axleCount = $this->parseAxleCountFromLayout($layoutValueLower);
        if ($axleCount === 2) {
            return '4x2';
        }

        if ($axleCount === 3) {
            return '6x2';
        }

        if ($axleCount === 4) {
            return '8x4';
        }

        return '4x2';
    }

    public function getLayoutOptionsByVehicleType(string $vehicleType): array
    {
        $vehicleType = $this->normalizeVehicleType($vehicleType);

        if ($vehicleType === 'autovehicul') {
            return ['4x2' => '2 axe / 4x2 (4 anvelope)'];
        }

        if ($vehicleType === 'camion') {
            return [
                '4x2' => '4x2 (2 axe / 6 anvelope)',
                '6x2' => '6x2 (3 axe / 8 anvelope)',
                '8x2' => '8x2 (4 axe / 10 anvelope)',
            ];
        }

        if ($vehicleType === 'semiremorca') {
            return [
                '2 axe' => '2 axe (4 anvelope)',
                '3 axe' => '3 axe (6 anvelope)',
                '4 axe' => '4 axe (8 anvelope)',
                '6 axe' => '6 axe (12 anvelope)',
            ];
        }

        if ($vehicleType === 'cap_tractor') {
            return [
                '4x2' => '4x2 (2 axe / 6 anvelope)',
                '6x2' => '6x2 (3 axe / 10 anvelope)',
                '6x4' => '6x4 (3 axe / 10 anvelope)',
                '8x4' => '8x4 (4 axe / 12 anvelope)',
            ];
        }

        return ['4x2' => '2 axe / 4x2 (4 anvelope)'];
    }

    private function appendSingleAxlePositions(array &$positions, int $axle, int &$positionOrder): void
    {
        foreach (['L' => 'Stanga', 'R' => 'Dreapta'] as $sideCode => $sideLabel) {
            $positions[] = [
                'position_code' => 'A' . $axle . '-' . $sideCode,
                'position_label' => 'Axa ' . $axle . ' - ' . $sideLabel,
                'axle_no' => $axle,
                'side_code' => $sideCode,
                'wheel_kind' => 'single',
                'position_order' => $positionOrder++,
            ];
        }
    }

    private function appendDualAxlePositions(array &$positions, int $axle, int &$positionOrder): void
    {
        foreach (
            [
                'LO' => 'Stanga exterior',
                'LI' => 'Stanga interior',
                'RI' => 'Dreapta interior',
                'RO' => 'Dreapta exterior',
            ] as $sideCode => $sideLabel
        ) {
            $positions[] = [
                'position_code' => 'A' . $axle . '-' . $sideCode,
                'position_label' => 'Axa ' . $axle . ' - ' . $sideLabel,
                'axle_no' => $axle,
                'side_code' => $sideCode,
                'wheel_kind' => 'dual',
                'position_order' => $positionOrder++,
            ];
        }
    }

    private function resolveReplacementPositionCode(string $positionCode, array $activeCodes): ?string
    {
        $positionCode = strtoupper(trim($positionCode));

        if (preg_match('/^A(\d+)-L[OI]$/', $positionCode, $matches) === 1) {
            $candidate = 'A' . $matches[1] . '-L';
            return isset($activeCodes[$candidate]) ? $candidate : null;
        }

        if (preg_match('/^A(\d+)-R[IO]$/', $positionCode, $matches) === 1) {
            $candidate = 'A' . $matches[1] . '-R';
            return isset($activeCodes[$candidate]) ? $candidate : null;
        }

        if (preg_match('/^A(\d+)-L$/', $positionCode, $matches) === 1) {
            $candidate = 'A' . $matches[1] . '-LO';
            return isset($activeCodes[$candidate]) ? $candidate : null;
        }

        if (preg_match('/^A(\d+)-R$/', $positionCode, $matches) === 1) {
            $candidate = 'A' . $matches[1] . '-RO';
            return isset($activeCodes[$candidate]) ? $candidate : null;
        }

        return null;
    }

    private function getVehicleKmBordForTireSync(int $vehicleId): int
    {
        $stmt = $this->db->prepare('SELECT km_bord FROM vehicule WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $vehicleId]);
        $rawKm = (string) ($stmt->fetchColumn() ?: '');

        if (is_numeric($rawKm)) {
            return max(0, (int) $rawKm);
        }

        $normalizedKm = preg_replace('/[^\d-]+/', '', $rawKm) ?? '';
        if ($normalizedKm !== '' && is_numeric($normalizedKm)) {
            return max(0, (int) $normalizedKm);
        }

        return 0;
    }

    private function closeActiveAllocationsForObsoletePosition(
        int $positionId,
        int $vehicleId,
        int $vehicleKmBord,
        string $dataEnd,
        string $updatedAt
    ): void {
        $stmt = $this->db->prepare(
            'SELECT id, tire_id
             FROM anvelope_alocari
             WHERE position_id = :position_id
               AND vehicle_id = :vehicle_id
               AND data_end IS NULL'
        );
        $stmt->execute([
            ':position_id' => $positionId,
            ':vehicle_id' => $vehicleId,
        ]);
        $allocations = $stmt->fetchAll();

        if ($allocations === []) {
            return;
        }

        $close = $this->db->prepare(
            'UPDATE anvelope_alocari
             SET data_end = :data_end,
                 km_end = :km_end,
                 status_end = :status_end,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $updateTire = $this->db->prepare(
            'UPDATE anvelope
             SET status = :status,
                 updated_at = :updated_at
             WHERE id = :id'
        );

        foreach ($allocations as $allocation) {
            $close->execute([
                ':data_end' => $dataEnd,
                ':km_end' => $vehicleKmBord,
                ':status_end' => self::STATUS_SPARE,
                ':updated_at' => $updatedAt,
                ':id' => (int) ($allocation['id'] ?? 0),
            ]);

            $updateTire->execute([
                ':status' => self::STATUS_SPARE,
                ':updated_at' => $updatedAt,
                ':id' => (int) ($allocation['tire_id'] ?? 0),
            ]);
        }
    }

    public function describeVehicleLayout(string $vehicleType, ?string $layout): array
    {
        $vehicleType = $this->normalizeVehicleType($vehicleType);
        $layout = $this->normalizeLayoutForType($vehicleType, $layout);

        $positions = [];
        $positionOrder = 1;
        $axleCount = 2;

        if ($vehicleType === 'autovehicul') {
            $axleCount = 2;
            $this->appendSingleAxlePositions($positions, 1, $positionOrder);
            $this->appendSingleAxlePositions($positions, 2, $positionOrder);
        } elseif ($vehicleType === 'camion') {
            if ($layout === '4x2') {
                $axleCount = 2;
                $this->appendSingleAxlePositions($positions, 1, $positionOrder);
                $this->appendDualAxlePositions($positions, 2, $positionOrder);
            } elseif ($layout === '6x2') {
                $axleCount = 3;
                $this->appendSingleAxlePositions($positions, 1, $positionOrder);
                $this->appendSingleAxlePositions($positions, 2, $positionOrder);
                $this->appendDualAxlePositions($positions, 3, $positionOrder);
            } elseif ($layout === '8x2') {
                $axleCount = 4;
                $this->appendSingleAxlePositions($positions, 1, $positionOrder);
                $this->appendSingleAxlePositions($positions, 2, $positionOrder);
                $this->appendSingleAxlePositions($positions, 3, $positionOrder);
                $this->appendDualAxlePositions($positions, 4, $positionOrder);
            } else {
                $axleCount = 2;
                $this->appendSingleAxlePositions($positions, 1, $positionOrder);
                $this->appendDualAxlePositions($positions, 2, $positionOrder);
            }
        } elseif ($vehicleType === 'semiremorca') {
            $axleCount = $this->parseAxleCountFromLayout($layout) ?? 3;
            if (!in_array($axleCount, [2, 3, 4, 6], true)) {
                $axleCount = 3;
            }

            for ($axle = 1; $axle <= $axleCount; $axle++) {
                $this->appendSingleAxlePositions($positions, $axle, $positionOrder);
            }
        } else {
            if ($layout === '4x2') {
                $axleCount = 2;
                $this->appendSingleAxlePositions($positions, 1, $positionOrder);
                $this->appendDualAxlePositions($positions, 2, $positionOrder);
            } elseif (in_array($layout, ['6x2', '6x4'], true)) {
                $axleCount = 3;
                $this->appendSingleAxlePositions($positions, 1, $positionOrder);
                $this->appendDualAxlePositions($positions, 2, $positionOrder);
                $this->appendDualAxlePositions($positions, 3, $positionOrder);
            } elseif ($layout === '8x4') {
                $axleCount = 4;
                $this->appendSingleAxlePositions($positions, 1, $positionOrder);
                $this->appendSingleAxlePositions($positions, 2, $positionOrder);
                $this->appendDualAxlePositions($positions, 3, $positionOrder);
                $this->appendDualAxlePositions($positions, 4, $positionOrder);
            } else {
                $axleCount = 2;
                $this->appendSingleAxlePositions($positions, 1, $positionOrder);
                $this->appendDualAxlePositions($positions, 2, $positionOrder);
            }
        }

        foreach ($positions as &$position) {
            $position['axle_type'] = $this->resolveAxleTypeForPosition($vehicleType, $layout, (int) ($position['axle_no'] ?? 0));
        }
        unset($position);

        return [
            'vehicle_type' => $vehicleType,
            'layout_value' => $layout,
            'axle_count' => $axleCount,
            'expected_tires' => count($positions),
            'positions' => $positions,
        ];
    }

    public function syncVehiclePositions(int $vehicleId, string $vehicleType, ?string $layout): array
    {
        $this->ensureLifecycleSchema();

        $descriptor = $this->describeVehicleLayout($vehicleType, $layout);
        $positions = $descriptor['positions'];

        $stmt = $this->db->prepare('SELECT id, position_code FROM vehicule_anvelope_pozitii WHERE vehicle_id = :vehicle_id');
        $stmt->execute([':vehicle_id' => $vehicleId]);
        $existingRows = $stmt->fetchAll();

        $existingByCode = [];
        foreach ($existingRows as $row) {
            $existingByCode[(string) $row['position_code']] = (int) $row['id'];
        }

        $now = date('Y-m-d H:i:s');
        $today = date('Y-m-d');
        $activeCodes = [];
        $vehicleKmBord = null;

        foreach ($positions as $position) {
            $code = (string) $position['position_code'];
            $activeCodes[$code] = true;

            if (isset($existingByCode[$code])) {
                $update = $this->db->prepare(
                    'UPDATE vehicule_anvelope_pozitii
                     SET position_label = :position_label,
                         axle_no = :axle_no,
                         axle_type = :axle_type,
                         side_code = :side_code,
                         wheel_kind = :wheel_kind,
                         position_order = :position_order,
                         is_active = 1,
                         updated_at = :updated_at
                     WHERE id = :id'
                );
                $update->execute([
                    ':position_label' => $position['position_label'],
                    ':axle_no' => $position['axle_no'],
                    ':axle_type' => $position['axle_type'],
                    ':side_code' => $position['side_code'],
                    ':wheel_kind' => $position['wheel_kind'],
                    ':position_order' => $position['position_order'],
                    ':updated_at' => $now,
                    ':id' => $existingByCode[$code],
                ]);
                continue;
            }

            $insert = $this->db->prepare(
                'INSERT INTO vehicule_anvelope_pozitii
                (vehicle_id, position_code, position_label, axle_no, axle_type, side_code, wheel_kind, position_order, is_active, created_at, updated_at)
                VALUES
                (:vehicle_id, :position_code, :position_label, :axle_no, :axle_type, :side_code, :wheel_kind, :position_order, 1, :created_at, :updated_at)'
            );
            $insert->execute([
                ':vehicle_id' => $vehicleId,
                ':position_code' => $position['position_code'],
                ':position_label' => $position['position_label'],
                ':axle_no' => $position['axle_no'],
                ':axle_type' => $position['axle_type'],
                ':side_code' => $position['side_code'],
                ':wheel_kind' => $position['wheel_kind'],
                ':position_order' => $position['position_order'],
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            $existingByCode[$code] = (int) $this->db->lastInsertId();
        }

        foreach ($existingByCode as $code => $positionId) {
            if (isset($activeCodes[$code])) {
                continue;
            }

            $hasActiveAllocationStmt = $this->db->prepare(
                'SELECT COUNT(*) FROM anvelope_alocari WHERE position_id = :position_id AND data_end IS NULL'
            );
            $hasActiveAllocationStmt->execute([':position_id' => $positionId]);
            $hasActiveAllocation = (int) $hasActiveAllocationStmt->fetchColumn() > 0;

            if ($hasActiveAllocation) {
                $replacementCode = $this->resolveReplacementPositionCode($code, $activeCodes);
                $replacementPositionId = $replacementCode !== null ? (int) ($existingByCode[$replacementCode] ?? 0) : 0;

                if ($replacementPositionId > 0 && $replacementPositionId !== $positionId) {
                    $replacementActiveAllocationStmt = $this->db->prepare(
                        'SELECT COUNT(*) FROM anvelope_alocari WHERE position_id = :position_id AND data_end IS NULL'
                    );
                    $replacementActiveAllocationStmt->execute([':position_id' => $replacementPositionId]);
                    $replacementHasActiveAllocation = (int) $replacementActiveAllocationStmt->fetchColumn() > 0;

                    if (!$replacementHasActiveAllocation) {
                        $moveAllocation = $this->db->prepare(
                            'UPDATE anvelope_alocari
                             SET position_id = :replacement_position_id,
                                 updated_at = :updated_at
                             WHERE position_id = :position_id
                               AND data_end IS NULL'
                        );
                        $moveAllocation->execute([
                            ':replacement_position_id' => $replacementPositionId,
                            ':updated_at' => $now,
                            ':position_id' => $positionId,
                        ]);

                        $hasActiveAllocation = false;
                    }
                }

                if ($hasActiveAllocation) {
                    if ($vehicleKmBord === null) {
                        $vehicleKmBord = $this->getVehicleKmBordForTireSync($vehicleId);
                    }

                    // Obsolete position codes must not stay active after a layout change.
                    $this->closeActiveAllocationsForObsoletePosition(
                        $positionId,
                        $vehicleId,
                        $vehicleKmBord,
                        $today,
                        $now
                    );
                    $hasActiveAllocation = false;
                }
            }

            $deactivate = $this->db->prepare(
                'UPDATE vehicule_anvelope_pozitii SET is_active = 0, updated_at = :updated_at WHERE id = :id'
            );
            $deactivate->execute([
                ':updated_at' => $now,
                ':id' => $positionId,
            ]);
        }

        return $descriptor;
    }

    public function updateVehicleLayout(int $vehicleId, string $layout, string $updatedAt): void
    {
        $stmt = $this->db->prepare('UPDATE vehicule SET formula_axelor = :layout, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            ':layout' => $layout,
            ':updated_at' => $updatedAt,
            ':id' => $vehicleId,
        ]);
    }

    private function resolveAxleTypeForPosition(string $vehicleType, string $layout, int $axleNo): string
    {
        $vehicleType = $this->normalizeVehicleType($vehicleType);
        $layout = $this->normalizeLayoutForType($vehicleType, $layout);

        if ($vehicleType === 'semiremorca') {
            return self::AXLE_TRAILER;
        }

        if ($vehicleType === 'autovehicul') {
            return $axleNo <= 1 ? self::AXLE_STEERING : self::AXLE_TRACTION;
        }

        if ($vehicleType === 'camion') {
            if ($layout === '4x2') {
                return $axleNo <= 1 ? self::AXLE_STEERING : self::AXLE_TRACTION;
            }

            if ($layout === '6x2') {
                return $axleNo < 3 ? self::AXLE_STEERING : self::AXLE_TRACTION;
            }

            if ($layout === '8x2') {
                return $axleNo < 4 ? self::AXLE_STEERING : self::AXLE_TRACTION;
            }

            return $axleNo <= 1 ? self::AXLE_STEERING : self::AXLE_TRACTION;
        }

        if ($layout === '8x4') {
            return $axleNo <= 2 ? self::AXLE_STEERING : self::AXLE_TRACTION;
        }

        return $axleNo <= 1 ? self::AXLE_STEERING : self::AXLE_TRACTION;
    }

    public function countMountedTiresForVehicle(int $vehicleId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*)
             FROM anvelope_alocari
             WHERE vehicle_id = :vehicle_id
               AND data_end IS NULL'
        );
        $stmt->execute([':vehicle_id' => $vehicleId]);

        return (int) $stmt->fetchColumn();
    }

    public function getAvailableTires(?string $vehicleType = null): array
    {
        $this->ensureLifecycleSchema();

        $sql = 'SELECT t.*
                FROM anvelope t
                LEFT JOIN anvelope_alocari a ON a.tire_id = t.id AND a.data_end IS NULL
                WHERE a.id IS NULL
                  AND t.status IN ("in_stock", "spare", "retreaded")';

        $params = [];
        if ($vehicleType !== null && trim($vehicleType) !== '') {
            $normalizedVehicleType = $this->normalizeVehicleType($vehicleType);
            $sql .= ' AND (COALESCE(t.target_vehicle_type, "universal") = "universal" OR COALESCE(t.target_vehicle_type, "universal") = :vehicle_type)';
            $params[':vehicle_type'] = $normalizedVehicleType;
        }

        $sql .= ' ORDER BY t.updated_at DESC, t.id DESC';

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, (string) $value, PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getTireById(int $tireId): ?array
    {
        $this->ensureLifecycleSchema();

        $stmt = $this->db->prepare('SELECT * FROM anvelope WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $tireId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function getVehiclePositionById(int $positionId): ?array
    {
        $this->ensureLifecycleSchema();

        $stmt = $this->db->prepare('SELECT * FROM vehicule_anvelope_pozitii WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $positionId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function createTire(array $data): int
    {
        $this->ensureLifecycleSchema();

        $sql = 'INSERT INTO anvelope
            (brand, model, tire_size, dot_code, serial_number, target_vehicle_type, tire_type, usage_compatibility, location_label, manufacturing_year, purchase_date, purchase_price, supplier, invoice_number, mount_date, km_initial, current_mileage, estimated_life_km, estimated_remaining_km, tread_depth_mm, min_tread_depth_mm, initial_condition, condition_status, season, directional, rotation_direction, status, notes, created_at, updated_at)
            VALUES
            (:brand, :model, :tire_size, :dot_code, :serial_number, :target_vehicle_type, :tire_type, :usage_compatibility, :location_label, :manufacturing_year, :purchase_date, :purchase_price, :supplier, :invoice_number, :mount_date, :km_initial, :current_mileage, :estimated_life_km, :estimated_remaining_km, :tread_depth_mm, :min_tread_depth_mm, :initial_condition, :condition_status, :season, :directional, :rotation_direction, :status, :notes, :created_at, :updated_at)';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':brand' => $data['brand'],
            ':model' => $data['model'],
            ':tire_size' => $data['tire_size'],
            ':dot_code' => $data['dot_code'],
            ':serial_number' => $data['serial_number'],
            ':target_vehicle_type' => $this->normalizeTargetVehicleType((string) ($data['target_vehicle_type'] ?? 'universal')),
            ':tire_type' => $this->normalizeTireType((string) ($data['tire_type'] ?? self::TIRE_TYPE_TRAILER)),
            ':usage_compatibility' => $data['usage_compatibility'] ?? null,
            ':location_label' => $data['location_label'] ?? null,
            ':manufacturing_year' => $data['manufacturing_year'] ?? null,
            ':purchase_date' => $data['purchase_date'] ?? null,
            ':purchase_price' => $data['purchase_price'] ?? null,
            ':supplier' => $data['supplier'] ?? null,
            ':invoice_number' => $data['invoice_number'] ?? null,
            ':mount_date' => $data['mount_date'],
            ':km_initial' => $data['km_initial'],
            ':current_mileage' => max(0, (int) ($data['current_mileage'] ?? 0)),
            ':estimated_life_km' => $data['estimated_life_km'],
            ':estimated_remaining_km' => $data['estimated_remaining_km'] ?? null,
            ':tread_depth_mm' => $data['tread_depth_mm'],
            ':min_tread_depth_mm' => $data['min_tread_depth_mm'],
            ':initial_condition' => $this->normalizeCondition((string) ($data['initial_condition'] ?? 'good')),
            ':condition_status' => $this->normalizeCondition((string) ($data['condition_status'] ?? ($data['initial_condition'] ?? 'good'))),
            ':season' => in_array((string) ($data['season'] ?? 'all_season'), ['summer', 'winter', 'all_season'], true) ? (string) $data['season'] : 'all_season',
            ':directional' => !empty($data['directional']) ? 1 : 0,
            ':rotation_direction' => $data['rotation_direction'] ?? null,
            ':status' => $this->normalizeTireStatus((string) ($data['status'] ?? self::STATUS_IN_STOCK)),
            ':notes' => $data['notes'],
            ':created_at' => $data['created_at'],
            ':updated_at' => $data['updated_at'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function createStockTireBatch(array $data): int
    {
        return count($this->createStockTireBatchWithIds($data));
    }

    public function createStockTireBatchWithIds(array $data): array
    {
        $this->ensureLifecycleSchema();

        $quantity = max(1, (int) ($data['quantity'] ?? 1));
        $quantity = min($quantity, 1000);

        $brand = trim((string) ($data['brand'] ?? ''));
        if ($brand === '') {
            throw new RuntimeException('Brandul este obligatoriu pentru stocul de anvelope.');
        }

        $status = $this->normalizeTireStatus((string) ($data['status'] ?? self::STATUS_IN_STOCK));
        if (!in_array($status, [self::STATUS_IN_STOCK, self::STATUS_SPARE, self::STATUS_REMOVED, self::STATUS_DAMAGED, self::STATUS_MISSING, self::STATUS_SCRAPPED], true)) {
            $status = self::STATUS_IN_STOCK;
        }

        $prefix = $this->sanitizeSerialPrefix((string) ($data['serial_prefix'] ?? 'STOC'));
        $now = (string) ($data['now'] ?? date('Y-m-d H:i:s'));
        $mountDate = (string) ($data['mount_date'] ?? date('Y-m-d'));
        $kmInitial = max(0, (int) ($data['km_initial'] ?? 0));
        $estimatedLifeKm = isset($data['estimated_life_km']) && $data['estimated_life_km'] !== null
            ? max(0, (int) $data['estimated_life_km'])
            : null;
        $targetVehicleType = $this->normalizeTargetVehicleType((string) ($data['target_vehicle_type'] ?? 'universal'));

        $createdIds = [];
        for ($index = 1; $index <= $quantity; $index++) {
            $serial = $this->generateUniqueSerial($prefix, $index);
            $createdId = $this->createTire([
                'brand' => $brand,
                'model' => ($data['model'] ?? null) !== null && trim((string) $data['model']) !== '' ? trim((string) $data['model']) : null,
                'tire_size' => ($data['tire_size'] ?? null) !== null && trim((string) $data['tire_size']) !== '' ? trim((string) $data['tire_size']) : null,
                'dot_code' => ($data['dot_code'] ?? null) !== null && trim((string) $data['dot_code']) !== '' ? strtoupper(trim((string) $data['dot_code'])) : null,
                'serial_number' => $serial,
                'target_vehicle_type' => $targetVehicleType,
                'tire_type' => $data['tire_type'] ?? self::TIRE_TYPE_TRAILER,
                'usage_compatibility' => ($data['usage_compatibility'] ?? null) !== null && trim((string) $data['usage_compatibility']) !== '' ? trim((string) $data['usage_compatibility']) : null,
                'location_label' => ($data['location_label'] ?? null) !== null && trim((string) $data['location_label']) !== '' ? trim((string) $data['location_label']) : null,
                'manufacturing_year' => $data['manufacturing_year'] ?? null,
                'purchase_date' => $data['purchase_date'] ?? null,
                'purchase_price' => $data['purchase_price'] ?? null,
                'supplier' => ($data['supplier'] ?? null) !== null && trim((string) $data['supplier']) !== '' ? trim((string) $data['supplier']) : null,
                'invoice_number' => ($data['invoice_number'] ?? null) !== null && trim((string) $data['invoice_number']) !== '' ? trim((string) $data['invoice_number']) : null,
                'mount_date' => $mountDate,
                'km_initial' => $kmInitial,
                'current_mileage' => $data['current_mileage'] ?? $kmInitial,
                'estimated_life_km' => $estimatedLifeKm,
                'estimated_remaining_km' => $data['estimated_remaining_km'] ?? $estimatedLifeKm,
                'tread_depth_mm' => $data['tread_depth_mm'] ?? null,
                'min_tread_depth_mm' => $data['min_tread_depth_mm'] ?? 2.0,
                'initial_condition' => $data['initial_condition'] ?? 'good',
                'condition_status' => $data['condition_status'] ?? ($data['initial_condition'] ?? 'good'),
                'season' => $data['season'] ?? 'all_season',
                'directional' => $data['directional'] ?? 0,
                'rotation_direction' => $data['rotation_direction'] ?? null,
                'status' => $status,
                'notes' => ($data['notes'] ?? null) !== null && trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $createdIds[] = $createdId;
        }

        return $createdIds;
    }

    private function tireStatusLabel(string $status): string
    {
        return match (strtolower(trim($status))) {
            self::STATUS_ACTIVE => 'Montata',
            self::STATUS_IN_STOCK => 'In stoc',
            self::STATUS_RETREADED => 'Resapata',
            self::STATUS_SPARE => 'Rezerva',
            self::STATUS_DAMAGED => 'Deteriorata',
            self::STATUS_MISSING => 'Lipsa',
            self::STATUS_REMOVED => 'Scoasa din uz',
            self::STATUS_SCRAPPED => 'Casata',
            default => 'Rezerva',
        };
    }

    private function resolveTireVehicleId(array $row): ?int
    {
        $activeVehicleId = (int) ($row['active_vehicle_id'] ?? 0);
        if ($activeVehicleId > 0) {
            return $activeVehicleId;
        }

        $lastVehicleId = (int) ($row['last_vehicle_id'] ?? 0);
        if ($lastVehicleId > 0) {
            return $lastVehicleId;
        }

        return null;
    }

    private function isAutoGeneratedTireMaintenanceType(?string $tipInterventie): bool
    {
        $normalized = strtolower(trim((string) $tipInterventie));
        return str_starts_with($normalized, 'anvelopa -');
    }

    private function unlinkTireMaintenanceLink(int $tireId, string $now): void
    {
        if ($tireId <= 0) {
            return;
        }

        $unlinkStmt = $this->db->prepare(
            'UPDATE anvelope
             SET mentenanta_id = NULL,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $unlinkStmt->execute([
            ':updated_at' => $now,
            ':id' => $tireId,
        ]);
    }

    private function cleanupLegacyStockVehicleRow(): void
    {
        $findStmt = $this->db->prepare(
            'SELECT id
             FROM vehicule
             WHERE nr_inmatriculare = :plate
               AND serie_sasiu = :chassis
             LIMIT 1'
        );
        $findStmt->execute([
            ':plate' => self::STOCK_VEHICLE_PLATE,
            ':chassis' => self::STOCK_VEHICLE_CHASSIS,
        ]);
        $stockVehicleId = (int) ($findStmt->fetchColumn() ?: 0);
        if ($stockVehicleId <= 0) {
            return;
        }

        $hasMentenantaStmt = $this->db->prepare('SELECT 1 FROM mentenanta WHERE vehicle_id = :vehicle_id LIMIT 1');
        $hasMentenantaStmt->execute([':vehicle_id' => $stockVehicleId]);
        if ($hasMentenantaStmt->fetchColumn() !== false) {
            return;
        }

        $hasPositionsStmt = $this->db->prepare('SELECT 1 FROM vehicule_anvelope_pozitii WHERE vehicle_id = :vehicle_id LIMIT 1');
        $hasPositionsStmt->execute([':vehicle_id' => $stockVehicleId]);
        if ($hasPositionsStmt->fetchColumn() !== false) {
            return;
        }

        $hasAllocationsStmt = $this->db->prepare('SELECT 1 FROM anvelope_alocari WHERE vehicle_id = :vehicle_id LIMIT 1');
        $hasAllocationsStmt->execute([':vehicle_id' => $stockVehicleId]);
        if ($hasAllocationsStmt->fetchColumn() !== false) {
            return;
        }

        $deleteStmt = $this->db->prepare('DELETE FROM vehicule WHERE id = :id');
        $deleteStmt->execute([':id' => $stockVehicleId]);
    }

    private function cleanupUnallocatedStockMaintenanceLinks(?array $tireIds = null): int
    {
        $params = [];
        $filterSql = '';
        if (is_array($tireIds) && $tireIds !== []) {
            $normalizedIds = [];
            foreach ($tireIds as $tireId) {
                $id = (int) $tireId;
                if ($id > 0) {
                    $normalizedIds[$id] = $id;
                }
            }

            if ($normalizedIds !== []) {
                $placeholders = [];
                $index = 0;
                foreach ($normalizedIds as $id) {
                    $placeholder = ':cleanup_tire_id_' . $index;
                    $placeholders[] = $placeholder;
                    $params[$placeholder] = $id;
                    $index++;
                }
                $filterSql = ' AND t.id IN (' . implode(', ', $placeholders) . ')';
            }
        }

        $sql = 'SELECT
                    t.id AS tire_id,
                    t.mentenanta_id,
                    m.id AS linked_mentenanta_id,
                    m.tip_interventie
                FROM anvelope t
                LEFT JOIN mentenanta m ON m.id = t.mentenanta_id
                WHERE t.mentenanta_id IS NOT NULL
                  AND NOT EXISTS (
                      SELECT 1
                      FROM anvelope_alocari a
                      WHERE a.tire_id = t.id
                  )'
                . $filterSql . '
                ORDER BY t.id ASC';

        $stmt = $this->db->prepare($sql);
        foreach ($params as $placeholder => $value) {
            $stmt->bindValue($placeholder, (int) $value, PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll();

        if ($rows === []) {
            return 0;
        }

        $deleteMentenantaStmt = $this->db->prepare('DELETE FROM mentenanta WHERE id = :id');
        $now = date('Y-m-d H:i:s');
        $updated = 0;

        foreach ($rows as $row) {
            $tireId = (int) ($row['tire_id'] ?? 0);
            $mentenantaId = (int) ($row['mentenanta_id'] ?? 0);
            $linkedMentenantaId = (int) ($row['linked_mentenanta_id'] ?? 0);
            $tipInterventie = (string) ($row['tip_interventie'] ?? '');

            if ($mentenantaId > 0 && $linkedMentenantaId > 0 && $this->isAutoGeneratedTireMaintenanceType($tipInterventie)) {
                $deleteMentenantaStmt->execute([':id' => $mentenantaId]);
            }

            if ($tireId > 0) {
                $this->unlinkTireMaintenanceLink($tireId, $now);
                $updated++;
            }
        }

        return $updated;
    }

    private function buildMaintenanceObservation(array $row): string
    {
        $lines = [];

        $serial = trim((string) ($row['serial_number'] ?? ''));
        if ($serial !== '') {
            $lines[] = 'Serie anvelopa: ' . $serial;
        }

        $tireSize = trim((string) ($row['tire_size'] ?? ''));
        if ($tireSize !== '') {
            $lines[] = 'Dimensiune: ' . $tireSize;
        }

        $dotCode = trim((string) ($row['dot_code'] ?? ''));
        if ($dotCode !== '') {
            $lines[] = 'DOT: ' . $dotCode;
        }

        $targetType = $this->normalizeTargetVehicleType((string) ($row['target_vehicle_type'] ?? 'universal'));
        $lines[] = 'Compatibil: ' . $this->vehicleTypeLabel($targetType);

        $notes = trim((string) ($row['notes'] ?? ''));
        if ($notes !== '') {
            $lines[] = 'Observatii anvelopa: ' . $notes;
        }

        return implode(PHP_EOL, $lines);
    }

    private function buildMaintenancePayload(array $row, int $vehicleId, string $now): array
    {
        $brand = trim((string) ($row['brand'] ?? ''));
        $model = trim((string) ($row['model'] ?? ''));
        $statusLabel = $this->tireStatusLabel((string) ($row['status'] ?? self::STATUS_SPARE));
        $tipInterventie = 'Anvelopa - ' . $statusLabel;

        $atelier = trim($brand . ' ' . $model);
        if ($atelier === '') {
            $atelier = null;
        } elseif (strlen($atelier) > 120) {
            $atelier = substr($atelier, 0, 120);
        }

        $mountDate = trim((string) ($row['mount_date'] ?? ''));
        if ($mountDate === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $mountDate) !== 1) {
            $mountDate = date('Y-m-d');
        }

        return [
            'vehicle_id' => $vehicleId,
            'tip_interventie' => $tipInterventie,
            'data_interventie' => $mountDate,
            'cost' => 0.00,
            'atelier' => $atelier,
            'furnizor_piesa' => null,
            'observatii' => $this->buildMaintenanceObservation($row),
            'updated_at' => $now,
        ];
    }

    public function syncTireMaintenanceEntries(?array $tireIds = null): int
    {
        $normalizedTireIds = [];
        if (is_array($tireIds)) {
            foreach ($tireIds as $tireId) {
                $id = (int) $tireId;
                if ($id > 0) {
                    $normalizedTireIds[$id] = $id;
                }
            }
        }

        $whereSql = '';
        $params = [];
        if ($normalizedTireIds !== []) {
            $placeholders = [];
            $index = 0;
            foreach ($normalizedTireIds as $tireId) {
                $placeholder = ':tire_id_' . $index;
                $placeholders[] = $placeholder;
                $params[$placeholder] = $tireId;
                $index++;
            }
            $whereSql = 'WHERE t.id IN (' . implode(', ', $placeholders) . ')';
        }

        $sql = 'SELECT
                    t.*,
                    active_alloc.vehicle_id AS active_vehicle_id,
                    last_alloc.vehicle_id AS last_vehicle_id,
                    m.id AS linked_mentenanta_id
                FROM anvelope t
                LEFT JOIN anvelope_alocari active_alloc
                    ON active_alloc.tire_id = t.id
                    AND active_alloc.data_end IS NULL
                LEFT JOIN (
                    SELECT a1.tire_id, a1.vehicle_id
                    FROM anvelope_alocari a1
                    INNER JOIN (
                        SELECT tire_id, MAX(id) AS max_id
                        FROM anvelope_alocari
                        GROUP BY tire_id
                    ) latest ON latest.max_id = a1.id
                ) last_alloc ON last_alloc.tire_id = t.id
                LEFT JOIN mentenanta m ON m.id = t.mentenanta_id
                ' . $whereSql . '
                ORDER BY t.id ASC';

        $stmt = $this->db->prepare($sql);
        foreach ($params as $placeholder => $value) {
            $stmt->bindValue($placeholder, (int) $value, PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll();

        if ($rows === []) {
            return 0;
        }

        $now = date('Y-m-d H:i:s');
        $updatedEntries = 0;

        $insertStmt = $this->db->prepare(
            'INSERT INTO mentenanta
            (vehicle_id, tip_interventie, data_interventie, cost, atelier, furnizor_piesa, observatii, created_at, updated_at)
            VALUES
            (:vehicle_id, :tip_interventie, :data_interventie, :cost, :atelier, :furnizor_piesa, :observatii, :created_at, :updated_at)'
        );
        $updateStmt = $this->db->prepare(
            'UPDATE mentenanta
             SET vehicle_id = :vehicle_id,
                 tip_interventie = :tip_interventie,
                 data_interventie = :data_interventie,
                 atelier = :atelier,
                 furnizor_piesa = :furnizor_piesa,
                 observatii = :observatii,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $linkStmt = $this->db->prepare('UPDATE anvelope SET mentenanta_id = :mentenanta_id, updated_at = :updated_at WHERE id = :id');

        foreach ($rows as $row) {
            $tireId = (int) ($row['id'] ?? 0);
            if ($tireId <= 0) {
                continue;
            }

            $vehicleId = $this->resolveTireVehicleId($row);
            $linkedMentenantaId = (int) ($row['mentenanta_id'] ?? 0);
            $linkedExists = (int) ($row['linked_mentenanta_id'] ?? 0) > 0;

            if ($vehicleId === null) {
                if ($linkedMentenantaId > 0) {
                    $tipInterventie = null;
                    if ($linkedExists) {
                        $tipStmt = $this->db->prepare('SELECT tip_interventie FROM mentenanta WHERE id = :id LIMIT 1');
                        $tipStmt->execute([':id' => $linkedMentenantaId]);
                        $tipInterventie = (string) ($tipStmt->fetchColumn() ?: '');

                        if ($this->isAutoGeneratedTireMaintenanceType($tipInterventie)) {
                            $deleteStmt = $this->db->prepare('DELETE FROM mentenanta WHERE id = :id');
                            $deleteStmt->execute([':id' => $linkedMentenantaId]);
                        }
                    }

                    $this->unlinkTireMaintenanceLink($tireId, $now);
                    $updatedEntries++;
                }
                continue;
            }

            $payload = $this->buildMaintenancePayload($row, $vehicleId, $now);

            if ($linkedMentenantaId > 0 && $linkedExists) {
                $updateStmt->execute([
                    ':vehicle_id' => $payload['vehicle_id'],
                    ':tip_interventie' => $payload['tip_interventie'],
                    ':data_interventie' => $payload['data_interventie'],
                    ':atelier' => $payload['atelier'],
                    ':furnizor_piesa' => $payload['furnizor_piesa'],
                    ':observatii' => $payload['observatii'],
                    ':updated_at' => $payload['updated_at'],
                    ':id' => $linkedMentenantaId,
                ]);
                $updatedEntries++;
                continue;
            }

            $insertStmt->execute([
                ':vehicle_id' => $payload['vehicle_id'],
                ':tip_interventie' => $payload['tip_interventie'],
                ':data_interventie' => $payload['data_interventie'],
                ':cost' => $payload['cost'],
                ':atelier' => $payload['atelier'],
                ':furnizor_piesa' => $payload['furnizor_piesa'],
                ':observatii' => $payload['observatii'],
                ':created_at' => $now,
                ':updated_at' => $payload['updated_at'],
            ]);

            $newMentenantaId = (int) $this->db->lastInsertId();
            $linkStmt->execute([
                ':mentenanta_id' => $newMentenantaId,
                ':updated_at' => $now,
                ':id' => $tireId,
            ]);
            $updatedEntries++;
        }

        $updatedEntries += $this->cleanupUnallocatedStockMaintenanceLinks($tireIds);
        $this->cleanupLegacyStockVehicleRow();

        return $updatedEntries;
    }

    public function hasMaintenanceSyncGaps(): bool
    {
        $stmt = $this->db->query(
            'SELECT 1
             FROM anvelope t
             LEFT JOIN mentenanta m ON m.id = t.mentenanta_id
             WHERE EXISTS (
                    SELECT 1
                    FROM anvelope_alocari a
                    WHERE a.tire_id = t.id
                  )
               AND (t.mentenanta_id IS NULL OR m.id IS NULL)
             LIMIT 1'
        );

        return $stmt->fetchColumn() !== false;
    }

    public function getStockTireById(int $tireId): ?array
    {
        $this->ensureLifecycleSchema();

        $stmt = $this->db->prepare(
            'SELECT
                t.*,
                active_alloc.id AS active_allocation_id
             FROM anvelope t
             LEFT JOIN anvelope_alocari active_alloc
                ON active_alloc.tire_id = t.id
                AND active_alloc.data_end IS NULL
             WHERE t.id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $tireId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function updateStockTire(int $tireId, array $data): bool
    {
        $this->ensureLifecycleSchema();

        $setParts = [];
        $params = [':id' => $tireId];

        $allowedColumns = [
            'brand',
            'model',
            'tire_size',
            'dot_code',
            'target_vehicle_type',
            'tire_type',
            'usage_compatibility',
            'location_label',
            'manufacturing_year',
            'purchase_date',
            'purchase_price',
            'supplier',
            'invoice_number',
            'mount_date',
            'km_initial',
            'current_mileage',
            'estimated_life_km',
            'estimated_remaining_km',
            'status',
            'tread_depth_mm',
            'min_tread_depth_mm',
            'initial_condition',
            'condition_status',
            'season',
            'directional',
            'rotation_direction',
            'notes',
            'updated_at',
        ];

        foreach ($allowedColumns as $column) {
            if (!array_key_exists($column, $data)) {
                continue;
            }
            $setParts[] = $column . ' = :' . $column;
            $params[':' . $column] = match ($column) {
                'target_vehicle_type' => $this->normalizeTargetVehicleType((string) $data[$column]),
                'tire_type' => $this->normalizeTireType((string) $data[$column]),
                'status' => $this->normalizeTireStatus((string) $data[$column]),
                'initial_condition', 'condition_status' => $this->normalizeCondition((string) $data[$column]),
                'directional' => !empty($data[$column]) ? 1 : 0,
                default => $data[$column],
            };
        }

        if ($setParts === []) {
            return false;
        }

        $sql = 'UPDATE anvelope SET ' . implode(', ', $setParts) . ' WHERE id = :id';
        $stmt = $this->db->prepare($sql);

        foreach ($params as $placeholder => $value) {
            if ($value === null) {
                $stmt->bindValue($placeholder, null, PDO::PARAM_NULL);
                continue;
            }
            $stmt->bindValue($placeholder, $value);
        }

        return $stmt->execute();
    }

    public function deleteStockTire(int $tireId): bool
    {
        $row = $this->getStockTireById($tireId);
        if (!is_array($row)) {
            throw new RuntimeException('Anvelopa selectata nu exista.');
        }

        if ((int) ($row['active_allocation_id'] ?? 0) > 0) {
            throw new RuntimeException('Anvelopa este montata pe un vehicul. Demonteaza inainte de stergere.');
        }

        $this->db->beginTransaction();

        try {
            $mentenantaId = (int) ($row['mentenanta_id'] ?? 0);
            if ($mentenantaId > 0) {
                $mentStmt = $this->db->prepare('SELECT id, tip_interventie FROM mentenanta WHERE id = :id LIMIT 1');
                $mentStmt->execute([':id' => $mentenantaId]);
                $mentRow = $mentStmt->fetch() ?: null;

                if (is_array($mentRow)) {
                    $tipInterventie = strtolower(trim((string) ($mentRow['tip_interventie'] ?? '')));
                    if (str_starts_with($tipInterventie, 'anvelopa -')) {
                        $deleteMentenantaStmt = $this->db->prepare('DELETE FROM mentenanta WHERE id = :id');
                        $deleteMentenantaStmt->execute([':id' => $mentenantaId]);
                    } else {
                        $unlinkStmt = $this->db->prepare('UPDATE anvelope SET mentenanta_id = NULL WHERE id = :id');
                        $unlinkStmt->execute([':id' => $tireId]);
                    }
                }
            }

            $deleteStmt = $this->db->prepare('DELETE FROM anvelope WHERE id = :id');
            $deleteStmt->execute([':id' => $tireId]);

            $this->db->commit();
            return true;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    private function getMountedCountByVehicleId(): array
    {
        $sql = 'SELECT p.vehicle_id, COUNT(*) AS mounted_count
                FROM vehicule_anvelope_pozitii p
                INNER JOIN anvelope_alocari a ON a.position_id = p.id AND a.data_end IS NULL
                WHERE p.is_active = 1
                GROUP BY p.vehicle_id';
        $stmt = $this->db->query($sql);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) ($row['vehicle_id'] ?? 0)] = (int) ($row['mounted_count'] ?? 0);
        }

        return $result;
    }

    private function getReadyStockByType(): array
    {
        $this->ensureLifecycleSchema();

        $sql = 'SELECT COALESCE(target_vehicle_type, "universal") AS target_vehicle_type, COUNT(*) AS total_count
                FROM anvelope t
                LEFT JOIN anvelope_alocari a ON a.tire_id = t.id AND a.data_end IS NULL
                WHERE a.id IS NULL
                  AND t.status IN ("in_stock", "spare", "retreaded")
                GROUP BY COALESCE(target_vehicle_type, "universal")';
        $stmt = $this->db->query($sql);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $type = $this->normalizeTargetVehicleType((string) ($row['target_vehicle_type'] ?? 'universal'));
            $result[$type] = (int) ($row['total_count'] ?? 0);
        }

        return $result;
    }

    private function getUnallocatedStockStatusCounts(): array
    {
        $this->ensureLifecycleSchema();

        $sql = 'SELECT t.status, COUNT(*) AS total_count
                FROM anvelope t
                LEFT JOIN anvelope_alocari a ON a.tire_id = t.id AND a.data_end IS NULL
                WHERE a.id IS NULL
                GROUP BY t.status';
        $stmt = $this->db->query($sql);

        $result = [
            self::STATUS_IN_STOCK => 0,
            self::STATUS_SPARE => 0,
            self::STATUS_RETREADED => 0,
            self::STATUS_REMOVED => 0,
            self::STATUS_DAMAGED => 0,
            self::STATUS_MISSING => 0,
            self::STATUS_SCRAPPED => 0,
            self::STATUS_ACTIVE => 0,
        ];

        foreach ($stmt->fetchAll() as $row) {
            $status = strtolower(trim((string) ($row['status'] ?? '')));
            if (!array_key_exists($status, $result)) {
                continue;
            }
            $result[$status] = (int) ($row['total_count'] ?? 0);
        }

        return $result;
    }

    private function buildInventoryWhere(array $filters, array &$params): string
    {
        $conditions = ['1 = 1'];

        $query = trim((string) ($filters['q'] ?? ''));
        if ($query !== '') {
            $conditions[] = '(t.brand LIKE :q OR t.model LIKE :q OR t.tire_size LIKE :q OR t.serial_number LIKE :q OR t.status LIKE :q OR v.nr_inmatriculare LIKE :q OR v.marca LIKE :q OR v.model LIKE :q OR p.position_label LIKE :q)';
            $params[':q'] = '%' . $query . '%';
        }

        $vehicleType = trim((string) ($filters['vehicle_type'] ?? ''));
        if ($vehicleType !== '' && $vehicleType !== 'all') {
            $normalizedVehicleType = $this->normalizeTargetVehicleType($vehicleType);
            if ($normalizedVehicleType === 'semiremorca') {
                $conditions[] = 'v.tip_vehicul IN ("semiremorca", "semiremorca_primar", "semiremorca_distributie")';
            } else {
                $conditions[] = 'v.tip_vehicul = :vehicle_type';
                $params[':vehicle_type'] = $normalizedVehicleType;
            }
        }

        $axleConfig = trim((string) ($filters['axle_config'] ?? ''));
        if ($axleConfig !== '' && $axleConfig !== 'all') {
            $conditions[] = 'COALESCE(v.formula_axelor, "") = :axle_config';
            $params[':axle_config'] = $axleConfig;
        }

        $tireType = trim((string) ($filters['tire_type'] ?? ''));
        if ($tireType !== '' && $tireType !== 'all') {
            $conditions[] = 'COALESCE(t.tire_type, "trailer") = :tire_type';
            $params[':tire_type'] = $this->normalizeTireType($tireType);
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && $status !== 'all') {
            $conditions[] = 't.status = :status';
            $params[':status'] = $this->normalizeTireStatus($status);
        }

        $condition = trim((string) ($filters['condition'] ?? ''));
        if ($condition !== '' && $condition !== 'all') {
            $conditions[] = 'COALESCE(t.condition_status, "good") = :condition_status';
            $params[':condition_status'] = $this->normalizeCondition($condition);
        }

        $location = trim((string) ($filters['location'] ?? ''));
        if ($location !== '' && $location !== 'all') {
            if ($location === 'mounted') {
                $conditions[] = 'a.id IS NOT NULL';
            } elseif ($location === 'unallocated') {
                $conditions[] = 'a.id IS NULL';
            } else {
                $conditions[] = 'COALESCE(t.location_label, "") = :location_label';
                $params[':location_label'] = $location;
            }
        }

        $mounted = trim((string) ($filters['mounted'] ?? ''));
        if ($mounted === 'mounted') {
            $conditions[] = 'a.id IS NOT NULL';
        } elseif ($mounted === 'not_mounted') {
            $conditions[] = 'a.id IS NULL';
        }

        return implode(' AND ', $conditions);
    }

    private function compatibilityLabelForTire(array $row): string
    {
        $custom = trim((string) ($row['usage_compatibility'] ?? ''));
        if ($custom !== '') {
            return $custom;
        }

        $type = $this->normalizeTireType((string) ($row['tire_type'] ?? self::TIRE_TYPE_TRAILER));
        $targetType = $this->normalizeTargetVehicleType((string) ($row['target_vehicle_type'] ?? 'universal'));
        $targetLabel = $targetType === 'universal' ? 'Universal' : $this->vehicleTypeLabel($targetType);

        return match ($type) {
            self::TIRE_TYPE_DIRECTION => $targetLabel . ' / Directie',
            self::TIRE_TYPE_TRACTION => $targetLabel . ' / Tractiune',
            self::TIRE_TYPE_TRAILER => 'Semi-remorca / Axa remorca',
            self::TIRE_TYPE_BALLOON => 'Cap tractor / Semi-remorca',
            self::TIRE_TYPE_BALLOON_DIRECTIONAL => 'Cap tractor / Semi-remorca / sens controlat',
            default => $targetLabel,
        };
    }

    private function prepareInventoryRow(array $row): array
    {
        $status = $this->normalizeTireStatus((string) ($row['status'] ?? self::STATUS_IN_STOCK));
        $statusMeta = $this->tireStatusMeta($status);
        $isMounted = (int) ($row['active_allocation_id'] ?? 0) > 0;
        $estimatedLife = is_numeric((string) ($row['estimated_life_km'] ?? null)) ? (int) $row['estimated_life_km'] : null;
        $storedRemaining = is_numeric((string) ($row['estimated_remaining_km'] ?? null)) ? (int) $row['estimated_remaining_km'] : null;
        $currentMileage = max(0, (int) ($row['current_mileage'] ?? $row['km_initial'] ?? 0));
        $vehicleKm = is_numeric((string) ($row['vehicle_km_bord'] ?? null)) ? (int) $row['vehicle_km_bord'] : 0;
        $allocationKmStart = is_numeric((string) ($row['active_km_start'] ?? null)) ? (int) $row['active_km_start'] : $vehicleKm;
        $currentSegmentKm = $isMounted ? max(0, $vehicleKm - $allocationKmStart) : 0;
        $usedKm = $currentMileage + $currentSegmentKm;
        $remainingKm = $storedRemaining;

        if ($estimatedLife !== null && $estimatedLife > 0) {
            $remainingKm = max(0, $estimatedLife - $usedKm);
        }

        $wearPercent = null;
        if ($estimatedLife !== null && $estimatedLife > 0) {
            $wearPercent = min(100.0, max(0.0, ($usedKm / $estimatedLife) * 100.0));
        } elseif ($remainingKm !== null && $remainingKm > 0) {
            $wearPercent = 25.0;
        }

        $conditionValue = $this->normalizeCondition((string) ($row['condition_status'] ?? 'good'));
        if ($status === self::STATUS_MISSING) {
            $conditionValue = 'missing';
        } elseif ($status === self::STATUS_DAMAGED || $status === self::STATUS_SCRAPPED) {
            $conditionValue = 'critical';
        }
        $conditionMeta = $this->conditionMeta($conditionValue, $wearPercent);

        $location = trim((string) ($row['location_label'] ?? ''));
        if ($isMounted) {
            $location = trim((string) ($row['nr_inmatriculare'] ?? 'Vehicul')) ?: 'Vehicul';
        } elseif ($location === '') {
            $location = $status === self::STATUS_SPARE ? 'Rezerva' : 'Depozit';
        }

        $vehicleName = trim((string) (($row['vehicle_marca'] ?? '') . ' ' . ($row['vehicle_model'] ?? '')));
        $positionLabel = trim((string) ($row['position_label'] ?? ''));
        $axleNo = (int) ($row['axle_no'] ?? 0);
        $axleType = (string) ($row['axle_type'] ?? '');
        $axleLabel = $axleNo > 0 ? 'Axa ' . $axleNo : '';
        if ($axleType !== '') {
            $axleLabel .= $axleLabel !== '' ? ' (' . ($this->getAxleTypeOptions()[$axleType] ?? $axleType) . ')' : ($this->getAxleTypeOptions()[$axleType] ?? $axleType);
        }

        return array_merge($row, [
            'status' => $status,
            'status_meta' => $statusMeta,
            'tire_type' => $this->normalizeTireType((string) ($row['tire_type'] ?? self::TIRE_TYPE_TRAILER)),
            'tire_type_label' => $this->tireTypeLabel((string) ($row['tire_type'] ?? self::TIRE_TYPE_TRAILER)),
            'condition_meta' => $conditionMeta,
            'condition_value' => $conditionMeta['value'],
            'compatibility_label' => $this->compatibilityLabelForTire($row),
            'location_display' => $location,
            'is_mounted' => $isMounted,
            'vehicle_display' => trim((string) ($row['nr_inmatriculare'] ?? '') . ($vehicleName !== '' ? ' / ' . $vehicleName : '')),
            'position_display' => $positionLabel !== '' ? $positionLabel : ($isMounted ? 'Pozitie necunoscuta' : 'Nealocat'),
            'axle_display' => $axleLabel !== '' ? $axleLabel : 'Nealocat',
            'used_km' => $usedKm,
            'remaining_km' => $remainingKm,
            'wear_percent' => $wearPercent,
            'season_label' => $this->getSeasonOptions()[(string) ($row['season'] ?? 'all_season')] ?? 'All season',
        ]);
    }

    private function getInventoryRows(array $filters, int $page, int $perPage): array
    {
        $params = [];
        $whereSql = $this->buildInventoryWhere($filters, $params);

        $countSql = 'SELECT COUNT(*)
                     FROM anvelope t
                     LEFT JOIN anvelope_alocari a ON a.tire_id = t.id AND a.data_end IS NULL
                     LEFT JOIN vehicule v ON v.id = a.vehicle_id
                     LEFT JOIN vehicule_anvelope_pozitii p ON p.id = a.position_id
                     WHERE ' . $whereSql;
        $countStmt = $this->db->prepare($countSql);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();
        $totalRows = (int) $countStmt->fetchColumn();

        $offset = max(0, ($page - 1) * $perPage);
        $sql = 'SELECT
                    t.*,
                    a.id AS active_allocation_id,
                    a.vehicle_id AS active_vehicle_id,
                    a.position_id AS active_position_id,
                    a.km_start AS active_km_start,
                    a.data_start AS active_data_start,
                    v.nr_inmatriculare,
                    v.marca AS vehicle_marca,
                    v.model AS vehicle_model,
                    v.tip_vehicul AS vehicle_type,
                    v.formula_axelor AS vehicle_layout,
                    v.km_bord AS vehicle_km_bord,
                    p.position_code,
                    p.position_label,
                    p.axle_no,
                    p.axle_type
                FROM anvelope t
                LEFT JOIN anvelope_alocari a ON a.tire_id = t.id AND a.data_end IS NULL
                LEFT JOIN vehicule v ON v.id = a.vehicle_id
                LEFT JOIN vehicule_anvelope_pozitii p ON p.id = a.position_id
                WHERE ' . $whereSql . '
                ORDER BY t.updated_at DESC, t.id DESC
                LIMIT :limit OFFSET :offset';

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = $this->prepareInventoryRow($row);
        }

        return [
            'rows' => $rows,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total_rows' => $totalRows,
                'total_pages' => max(1, (int) ceil($totalRows / max(1, $perPage))),
            ],
        ];
    }

    private function getMoveTargetOptions(array $vehicles): array
    {
        $targets = [];

        foreach ($vehicles as $vehicle) {
            $vehicleId = (int) ($vehicle['id'] ?? 0);
            if ($vehicleId <= 0) {
                continue;
            }

            try {
                $this->syncVehiclePositions(
                    $vehicleId,
                    (string) ($vehicle['tip_vehicul'] ?? 'autovehicul'),
                    (string) ($vehicle['formula_axelor'] ?? '')
                );
            } catch (Throwable $exception) {
                error_log('[TireModel][move-target-sync] ' . $exception->getMessage());
            }
        }

        $stmt = $this->db->query(
            'SELECT
                v.id AS vehicle_id,
                v.nr_inmatriculare,
                v.marca,
                v.model,
                v.tip_vehicul,
                v.formula_axelor,
                p.id AS position_id,
                p.position_code,
                p.position_label,
                p.axle_no,
                p.axle_type,
                active_t.id AS mounted_tire_id,
                active_t.brand AS mounted_brand,
                active_t.model AS mounted_model
             FROM vehicule v
             INNER JOIN vehicule_anvelope_pozitii p ON p.vehicle_id = v.id AND p.is_active = 1
             LEFT JOIN anvelope_alocari active_alloc ON active_alloc.position_id = p.id AND active_alloc.data_end IS NULL
             LEFT JOIN anvelope active_t ON active_t.id = active_alloc.tire_id
             WHERE v.status = "activ"
             ORDER BY v.nr_inmatriculare ASC, p.position_order ASC, p.id ASC'
        );

        foreach ($stmt->fetchAll() as $row) {
            $vehicleId = (int) ($row['vehicle_id'] ?? 0);
            if (!isset($targets[$vehicleId])) {
                $vehicleName = trim((string) (($row['marca'] ?? '') . ' ' . ($row['model'] ?? '')));
                $targets[$vehicleId] = [
                    'vehicle_id' => $vehicleId,
                    'label' => trim((string) ($row['nr_inmatriculare'] ?? '-') . ($vehicleName !== '' ? ' / ' . $vehicleName : '')),
                    'vehicle_type' => $this->normalizeVehicleType((string) ($row['tip_vehicul'] ?? 'autovehicul')),
                    'layout' => (string) ($row['formula_axelor'] ?? ''),
                    'positions' => [],
                ];
            }

            $mountedTire = trim((string) (($row['mounted_brand'] ?? '') . ' ' . ($row['mounted_model'] ?? '')));
            $axleType = (string) ($row['axle_type'] ?? self::AXLE_STEERING);
            $targets[$vehicleId]['positions'][] = [
                'position_id' => (int) ($row['position_id'] ?? 0),
                'label' => trim('Axa ' . (int) ($row['axle_no'] ?? 0) . ' - ' . (string) ($row['position_label'] ?? '-')),
                'axle_type' => $axleType,
                'axle_type_label' => $this->getAxleTypeOptions()[$axleType] ?? $axleType,
                'mounted_tire_id' => (int) ($row['mounted_tire_id'] ?? 0),
                'mounted_tire_label' => $mountedTire,
            ];
        }

        return array_values($targets);
    }

    public function buildMaintenanceStockContext(array $filters = []): array
    {
        $this->ensureLifecycleSchema();
        $this->cleanupUnallocatedStockMaintenanceLinks();
        $this->cleanupLegacyStockVehicleRow();

        $mountedByVehicle = $this->getMountedCountByVehicleId();
        $readyStockByType = $this->getReadyStockByType();
        $statusCounts = $this->getUnallocatedStockStatusCounts();

        $vehiclesStmt = $this->db->query(
            'SELECT id, nr_inmatriculare, marca, model, tip_vehicul, formula_axelor, status
             FROM vehicule
             WHERE status = "activ"
             ORDER BY nr_inmatriculare ASC'
        );
        $vehicles = $vehiclesStmt->fetchAll();

        $vehicleNeeds = [];
        $typeNeeds = [];
        $totals = [
            'active_vehicles' => 0,
            'expected_tires' => 0,
            'mounted_tires' => 0,
            'missing_tires' => 0,
            'ready_stock_total' => array_sum($readyStockByType),
            'ready_mountable_total' => ($statusCounts[self::STATUS_IN_STOCK] ?? 0) + ($statusCounts[self::STATUS_SPARE] ?? 0) + ($statusCounts[self::STATUS_RETREADED] ?? 0),
            'total_tires' => 0,
            'replacement_required' => 0,
        ];

        foreach ($vehicles as $vehicle) {
            $vehicleId = (int) ($vehicle['id'] ?? 0);
            $vehicleType = (string) ($vehicle['tip_vehicul'] ?? 'autovehicul');
            $normalizedType = $this->normalizeVehicleType($vehicleType);
            $layout = (string) ($vehicle['formula_axelor'] ?? '');
            $descriptor = $this->describeVehicleLayout($normalizedType, $layout);

            $expected = (int) ($descriptor['expected_tires'] ?? 0);
            $mounted = max(0, (int) ($mountedByVehicle[$vehicleId] ?? 0));
            $missing = max(0, $expected - $mounted);

            $vehicleNeeds[] = [
                'vehicle_id' => $vehicleId,
                'nr_inmatriculare' => (string) ($vehicle['nr_inmatriculare'] ?? '-'),
                'vehicle_name' => trim((string) (($vehicle['marca'] ?? '') . ' ' . ($vehicle['model'] ?? ''))),
                'vehicle_type' => $normalizedType,
                'vehicle_type_label' => $this->vehicleTypeLabel($normalizedType),
                'layout_value' => (string) ($descriptor['layout_value'] ?? ''),
                'expected_tires' => $expected,
                'mounted_tires' => $mounted,
                'missing_tires' => $missing,
            ];

            if (!isset($typeNeeds[$normalizedType])) {
                $typeNeeds[$normalizedType] = [
                    'vehicle_type' => $normalizedType,
                    'vehicle_type_label' => $this->vehicleTypeLabel($normalizedType),
                    'vehicles_count' => 0,
                    'expected_tires' => 0,
                    'mounted_tires' => 0,
                    'missing_tires' => 0,
                    'ready_stock_for_type' => 0,
                    'recommended_to_add' => 0,
                ];
            }

            $typeNeeds[$normalizedType]['vehicles_count']++;
            $typeNeeds[$normalizedType]['expected_tires'] += $expected;
            $typeNeeds[$normalizedType]['mounted_tires'] += $mounted;
            $typeNeeds[$normalizedType]['missing_tires'] += $missing;

            $totals['active_vehicles']++;
            $totals['expected_tires'] += $expected;
            $totals['mounted_tires'] += $mounted;
            $totals['missing_tires'] += $missing;
        }

        foreach ($typeNeeds as $type => &$row) {
            $readyForType = (int) ($readyStockByType[$type] ?? 0) + (int) ($readyStockByType['universal'] ?? 0);
            $row['ready_stock_for_type'] = $readyForType;
            $row['recommended_to_add'] = max(0, (int) $row['missing_tires'] - $readyForType);
        }
        unset($row);

        usort($vehicleNeeds, static function (array $left, array $right): int {
            $missingComparison = (int) ($right['missing_tires'] ?? 0) <=> (int) ($left['missing_tires'] ?? 0);
            if ($missingComparison !== 0) {
                return $missingComparison;
            }
            return strcmp((string) ($left['nr_inmatriculare'] ?? ''), (string) ($right['nr_inmatriculare'] ?? ''));
        });

        $totals['total_tires'] = (int) $this->db->query('SELECT COUNT(*) FROM anvelope')->fetchColumn();
        $replacementStmt = $this->db->query(
            'SELECT COUNT(*)
             FROM anvelope t
             LEFT JOIN anvelope_alocari a ON a.tire_id = t.id AND a.data_end IS NULL
             LEFT JOIN vehicule v ON v.id = a.vehicle_id
             WHERE t.status IN ("damaged", "missing", "scrapped")
                OR COALESCE(t.condition_status, "good") IN ("high_wear", "critical", "missing")
                OR (
                    COALESCE(t.estimated_life_km, 0) > 0
                    AND (
                        COALESCE(t.current_mileage, 0)
                        + CASE
                            WHEN a.id IS NOT NULL THEN GREATEST(0, COALESCE(v.km_bord, 0) - COALESCE(a.km_start, COALESCE(v.km_bord, 0)))
                            ELSE 0
                          END
                    ) >= ROUND(COALESCE(t.estimated_life_km, 0) * 0.81)
                )'
        );
        $totals['replacement_required'] = (int) $replacementStmt->fetchColumn();

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = (int) ($filters['per_page'] ?? 10);
        $perPage = in_array($perPage, [5, 10, 25, 50], true) ? $perPage : 10;
        $inventory = $this->getInventoryRows($filters, $page, $perPage);

        $locationStmt = $this->db->query(
            'SELECT DISTINCT location_label
             FROM anvelope
             WHERE location_label IS NOT NULL AND TRIM(location_label) <> ""
             ORDER BY location_label ASC'
        );
        $locationOptions = [
            'mounted' => 'Montate pe vehicul',
            'unallocated' => 'Nemontate',
        ];
        foreach ($locationStmt->fetchAll(PDO::FETCH_COLUMN) as $locationLabel) {
            $label = trim((string) $locationLabel);
            if ($label !== '') {
                $locationOptions[$label] = $label;
            }
        }

        $layoutStmt = $this->db->query(
            'SELECT DISTINCT formula_axelor
             FROM vehicule
             WHERE formula_axelor IS NOT NULL AND TRIM(formula_axelor) <> ""
             ORDER BY formula_axelor ASC'
        );
        $axleConfigOptions = [];
        foreach ($layoutStmt->fetchAll(PDO::FETCH_COLUMN) as $layoutValue) {
            $layoutLabel = trim((string) $layoutValue);
            if ($layoutLabel !== '') {
                $axleConfigOptions[$layoutLabel] = $layoutLabel;
            }
        }

        return [
            'totals' => $totals,
            'status_counts' => $statusCounts,
            'ready_stock_by_type' => $readyStockByType,
            'needs_by_type' => array_values($typeNeeds),
            'vehicle_needs' => $vehicleNeeds,
            'stock_preview' => $inventory['rows'],
            'inventory_rows' => $inventory['rows'],
            'pagination' => $inventory['pagination'],
            'filters' => $filters,
            'move_targets' => $this->getMoveTargetOptions($vehicles),
            'target_type_options' => $this->getTargetVehicleTypeOptions(),
            'tire_type_options' => $this->getTireTypeOptions(),
            'status_options' => $this->getTireStatusOptions(),
            'condition_options' => $this->getConditionOptions(),
            'season_options' => $this->getSeasonOptions(),
            'axle_type_options' => $this->getAxleTypeOptions(),
            'location_options' => $locationOptions,
            'axle_config_options' => $axleConfigOptions,
        ];
    }

    public function isTireCompatibleWithPosition(array $tire, array $vehicle, array $position): bool
    {
        $this->ensureLifecycleSchema();

        if (!$this->isTireCompatibleWithVehicleType($tire, (string) ($vehicle['tip_vehicul'] ?? ''))) {
            return false;
        }

        $tireType = $this->normalizeTireType((string) ($tire['tire_type'] ?? self::TIRE_TYPE_TRAILER));
        $vehicleType = $this->normalizeVehicleType((string) ($vehicle['tip_vehicul'] ?? 'autovehicul'));
        $axleType = (string) ($position['axle_type'] ?? '');
        if (!array_key_exists($axleType, $this->getAxleTypeOptions())) {
            $axleType = $this->resolveAxleTypeForPosition(
                $vehicleType,
                (string) ($vehicle['formula_axelor'] ?? ''),
                (int) ($position['axle_no'] ?? 0)
            );
        }

        if ($tireType === self::TIRE_TYPE_BALLOON_DIRECTIONAL && trim((string) ($tire['rotation_direction'] ?? '')) === '') {
            return false;
        }

        $stmt = $this->db->prepare(
            'SELECT COUNT(*)
             FROM anvelope_tip_compatibilitate
             WHERE tire_type = :tire_type
               AND axle_type = :axle_type
               AND is_allowed = 1
               AND (vehicle_type = "universal" OR vehicle_type = :vehicle_type)'
        );
        $stmt->execute([
            ':tire_type' => $tireType,
            ':axle_type' => $axleType,
            ':vehicle_type' => $vehicleType,
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function getVehicleForTireOperation(int $vehicleId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM vehicule WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $vehicleId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    private function getActiveAllocationForTire(int $tireId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM anvelope_alocari WHERE tire_id = :tire_id AND data_end IS NULL LIMIT 1');
        $stmt->execute([':tire_id' => $tireId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    private function getActiveAllocationForPosition(int $positionId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM anvelope_alocari WHERE position_id = :position_id AND data_end IS NULL LIMIT 1');
        $stmt->execute([':position_id' => $positionId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    private function closeAllocation(array $allocation, int $kmEnd, string $dateEnd, string $statusEnd, string $updatedAt): void
    {
        $stmt = $this->db->prepare(
            'UPDATE anvelope_alocari
             SET data_end = :data_end,
                 km_end = :km_end,
                 status_end = :status_end,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            ':data_end' => $dateEnd,
            ':km_end' => $kmEnd,
            ':status_end' => $statusEnd,
            ':updated_at' => $updatedAt,
            ':id' => (int) ($allocation['id'] ?? 0),
        ]);
    }

    private function insertAllocation(int $tireId, int $vehicleId, int $positionId, int $kmStart, string $dateStart, string $createdAt, ?int $userId): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO anvelope_alocari
                (tire_id, vehicle_id, position_id, data_start, km_start, created_by, created_at, updated_at)
             VALUES
                (:tire_id, :vehicle_id, :position_id, :data_start, :km_start, :created_by, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':tire_id' => $tireId,
            ':vehicle_id' => $vehicleId,
            ':position_id' => $positionId,
            ':data_start' => $dateStart,
            ':km_start' => $kmStart,
            ':created_by' => $userId,
            ':created_at' => $createdAt,
            ':updated_at' => $createdAt,
        ]);
    }

    private function getPositionContext(?int $positionId): ?array
    {
        if ($positionId === null || $positionId <= 0) {
            return null;
        }

        $stmt = $this->db->prepare(
            'SELECT
                p.*,
                v.nr_inmatriculare,
                v.marca,
                v.model,
                v.tip_vehicul,
                v.formula_axelor,
                v.km_bord
             FROM vehicule_anvelope_pozitii p
             INNER JOIN vehicule v ON v.id = p.vehicle_id
             WHERE p.id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $positionId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    private function recordTireHistory(
        int $tireId,
        ?array $oldPosition,
        ?array $newPosition,
        ?string $oldStatus,
        string $newStatus,
        ?string $reason,
        ?string $observation,
        ?int $userId,
        string $createdAt
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO anvelope_istoric
                (tire_id, old_vehicle_id, new_vehicle_id, old_position_id, new_position_id, old_axle_no, new_axle_no, old_position_label, new_position_label, old_status, new_status, reason, observation, created_by, created_at)
             VALUES
                (:tire_id, :old_vehicle_id, :new_vehicle_id, :old_position_id, :new_position_id, :old_axle_no, :new_axle_no, :old_position_label, :new_position_label, :old_status, :new_status, :reason, :observation, :created_by, :created_at)'
        );
        $stmt->execute([
            ':tire_id' => $tireId,
            ':old_vehicle_id' => $oldPosition !== null ? (int) ($oldPosition['vehicle_id'] ?? 0) : null,
            ':new_vehicle_id' => $newPosition !== null ? (int) ($newPosition['vehicle_id'] ?? 0) : null,
            ':old_position_id' => $oldPosition !== null ? (int) ($oldPosition['id'] ?? $oldPosition['position_id'] ?? 0) : null,
            ':new_position_id' => $newPosition !== null ? (int) ($newPosition['id'] ?? $newPosition['position_id'] ?? 0) : null,
            ':old_axle_no' => $oldPosition !== null ? (int) ($oldPosition['axle_no'] ?? 0) : null,
            ':new_axle_no' => $newPosition !== null ? (int) ($newPosition['axle_no'] ?? 0) : null,
            ':old_position_label' => $oldPosition !== null ? (string) ($oldPosition['position_label'] ?? null) : null,
            ':new_position_label' => $newPosition !== null ? (string) ($newPosition['position_label'] ?? null) : null,
            ':old_status' => $oldStatus,
            ':new_status' => $newStatus,
            ':reason' => $reason,
            ':observation' => $observation,
            ':created_by' => $userId,
            ':created_at' => $createdAt,
        ]);
    }

    public function changeTireStatus(int $tireId, string $newStatus, ?string $reason, ?string $observation, ?int $userId): void
    {
        $this->ensureLifecycleSchema();

        $newStatus = $this->normalizeTireStatus($newStatus);
        $now = date('Y-m-d H:i:s');
        $today = date('Y-m-d');

        $this->db->beginTransaction();

        try {
            $tire = $this->getTireById($tireId);
            if ($tire === null) {
                throw new RuntimeException('Anvelopa selectata nu exista.');
            }

            $oldStatus = (string) ($tire['status'] ?? self::STATUS_IN_STOCK);
            $activeAllocation = $this->getActiveAllocationForTire($tireId);
            $oldPosition = null;
            if ($activeAllocation !== null) {
                $oldPosition = $this->getPositionContext((int) ($activeAllocation['position_id'] ?? 0));
                if ($newStatus !== self::STATUS_ACTIVE) {
                    $kmEnd = $oldPosition !== null ? max(0, (int) ($oldPosition['km_bord'] ?? 0)) : 0;
                    $this->closeAllocation($activeAllocation, $kmEnd, $today, $newStatus, $now);
                }
            }

            $stmt = $this->db->prepare('UPDATE anvelope SET status = :status, updated_at = :updated_at WHERE id = :id');
            $stmt->execute([
                ':status' => $newStatus,
                ':updated_at' => $now,
                ':id' => $tireId,
            ]);

            $newPosition = $newStatus === self::STATUS_ACTIVE && $oldPosition !== null ? $oldPosition : null;
            $this->recordTireHistory($tireId, $oldPosition, $newPosition, $oldStatus, $newStatus, $reason, $observation, $userId, $now);

            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    public function mountTire(
        int $tireId,
        int $vehicleId,
        int $positionId,
        int $vehicleKmBord,
        string $mountDate,
        string $mountDateTime,
        ?int $userId = null,
        bool $allowSwap = false,
        ?string $reason = null,
        ?string $observation = null
    ): void {
        $this->ensureLifecycleSchema();

        $this->db->beginTransaction();

        try {
            $position = $this->getVehiclePositionById($positionId);
            if ($position === null || (int) ($position['vehicle_id'] ?? 0) !== $vehicleId || (int) ($position['is_active'] ?? 0) !== 1) {
                throw new RuntimeException('Pozitia selectata nu este valida pentru acest vehicul.');
            }

            $vehicle = $this->getVehicleForTireOperation($vehicleId);
            if ($vehicle === null) {
                throw new RuntimeException('Vehiculul selectat nu exista.');
            }

            $tire = $this->getTireById($tireId);
            if ($tire === null) {
                throw new RuntimeException('Anvelopa selectata nu exista.');
            }

            if (!$this->isTireCompatibleWithPosition($tire, $vehicle, $position)) {
                throw new RuntimeException('Anvelopa selectata nu este compatibila cu axa aleasa.');
            }

            $existingTireAllocation = $this->getActiveAllocationForTire($tireId);
            $existingPositionAllocation = $this->getActiveAllocationForPosition($positionId);

            if (is_array($existingTireAllocation)) {
                $allocationVehicleId = (int) ($existingTireAllocation['vehicle_id'] ?? 0);
                if ($allocationVehicleId === $vehicleId && (int) ($existingTireAllocation['position_id'] ?? 0) === $positionId) {
                    $this->db->commit();
                    return;
                }
            }

            if (is_array($existingPositionAllocation) && (int) ($existingPositionAllocation['tire_id'] ?? 0) !== $tireId) {
                if (!$allowSwap) {
                    throw new RuntimeException('Pozitia aleasa este ocupata. Bifeaza optiunea Schimba pozitiile pentru a face schimbul.');
                }

                if (!is_array($existingTireAllocation)) {
                    throw new RuntimeException('Pozitia aleasa este ocupata si anvelopa curenta nu are o pozitie de schimb.');
                }

                $sourcePosition = $this->getPositionContext((int) ($existingTireAllocation['position_id'] ?? 0));
                if ($sourcePosition === null) {
                    throw new RuntimeException('Pozitia curenta a anvelopei nu mai exista.');
                }

                $targetTireId = (int) ($existingPositionAllocation['tire_id'] ?? 0);
                $targetTire = $this->getTireById($targetTireId);
                $sourceVehicle = $this->getVehicleForTireOperation((int) ($sourcePosition['vehicle_id'] ?? 0));
                if ($targetTire === null || $sourceVehicle === null || !$this->isTireCompatibleWithPosition($targetTire, $sourceVehicle, $sourcePosition)) {
                    throw new RuntimeException('Schimbul nu se poate face: anvelopa de pe pozitia tinta nu este compatibila cu pozitia curenta.');
                }

                $sourceKmEnd = max(0, (int) ($sourcePosition['km_bord'] ?? $vehicleKmBord));
                $this->closeAllocation($existingTireAllocation, $sourceKmEnd, $mountDate, 'moved', $mountDateTime);
                $this->closeAllocation($existingPositionAllocation, $vehicleKmBord, $mountDate, 'moved', $mountDateTime);

                $this->insertAllocation($tireId, $vehicleId, $positionId, $vehicleKmBord, $mountDate, $mountDateTime, $userId);
                $this->insertAllocation($targetTireId, (int) ($sourcePosition['vehicle_id'] ?? 0), (int) ($sourcePosition['id'] ?? 0), $sourceKmEnd, $mountDate, $mountDateTime, $userId);

                $updateTire = $this->db->prepare('UPDATE anvelope SET status = :status, mount_date = :mount_date, updated_at = :updated_at WHERE id = :id');
                foreach ([$tireId, $targetTireId] as $swapTireId) {
                    $updateTire->execute([
                        ':status' => self::STATUS_ACTIVE,
                        ':mount_date' => $mountDate,
                        ':updated_at' => $mountDateTime,
                        ':id' => $swapTireId,
                    ]);
                }

                $this->recordTireHistory($tireId, $sourcePosition, $position, (string) ($tire['status'] ?? self::STATUS_IN_STOCK), self::STATUS_ACTIVE, $reason ?? 'Schimb pozitii', $observation, $userId, $mountDateTime);
                $this->recordTireHistory($targetTireId, $position, $sourcePosition, (string) ($targetTire['status'] ?? self::STATUS_ACTIVE), self::STATUS_ACTIVE, $reason ?? 'Schimb pozitii', $observation, $userId, $mountDateTime);

                $this->db->commit();
                return;
            }

            $oldPosition = null;
            if (is_array($existingTireAllocation)) {
                $oldPosition = $this->getPositionContext((int) ($existingTireAllocation['position_id'] ?? 0));
                $kmEnd = $vehicleKmBord;
                if ($oldPosition !== null) {
                    $kmEnd = max(0, (int) ($oldPosition['km_bord'] ?? $vehicleKmBord));
                }
                $this->closeAllocation($existingTireAllocation, $kmEnd, $mountDate, 'moved', $mountDateTime);
            }

            $this->insertAllocation($tireId, $vehicleId, $positionId, $vehicleKmBord, $mountDate, $mountDateTime, $userId);

            $updateTire = $this->db->prepare(
                'UPDATE anvelope
                 SET status = :status,
                     mount_date = :mount_date,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $updateTire->execute([
                ':status' => self::STATUS_ACTIVE,
                ':mount_date' => $mountDate,
                ':updated_at' => $mountDateTime,
                ':id' => $tireId,
            ]);

            $this->recordTireHistory($tireId, $oldPosition, $position, (string) ($tire['status'] ?? self::STATUS_IN_STOCK), self::STATUS_ACTIVE, $reason ?? 'Montaj / mutare anvelopa', $observation, $userId, $mountDateTime);

            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    public function unmountTire(
        int $allocationId,
        int $vehicleId,
        int $vehicleKmBord,
        string $unmountDate,
        string $statusEnd,
        string $updatedAt,
        ?int $userId = null,
        ?string $reason = null,
        ?string $observation = null
    ): bool {
        $this->ensureLifecycleSchema();

        $statusEnd = $this->normalizeTireStatus($statusEnd);
        if (!in_array($statusEnd, [self::STATUS_IN_STOCK, self::STATUS_SPARE, self::STATUS_REMOVED, self::STATUS_DAMAGED, self::STATUS_MISSING, self::STATUS_SCRAPPED], true)) {
            $statusEnd = self::STATUS_SPARE;
        }

        $stmt = $this->db->prepare(
            'SELECT * FROM anvelope_alocari WHERE id = :id AND vehicle_id = :vehicle_id AND data_end IS NULL LIMIT 1'
        );
        $stmt->execute([
            ':id' => $allocationId,
            ':vehicle_id' => $vehicleId,
        ]);
        $allocation = $stmt->fetch();

        if (!$allocation) {
            return false;
        }

        $this->db->beginTransaction();

        try {
            $tireId = (int) ($allocation['tire_id'] ?? 0);
            $tire = $this->getTireById($tireId);
            $oldPosition = $this->getPositionContext((int) ($allocation['position_id'] ?? 0));

            $close = $this->db->prepare(
                'UPDATE anvelope_alocari
                 SET data_end = :data_end,
                     km_end = :km_end,
                     status_end = :status_end,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $close->execute([
                ':data_end' => $unmountDate,
                ':km_end' => $vehicleKmBord,
                ':status_end' => $statusEnd,
                ':updated_at' => $updatedAt,
                ':id' => $allocationId,
            ]);

            $updateTire = $this->db->prepare('UPDATE anvelope SET status = :status, updated_at = :updated_at WHERE id = :id');
            $updateTire->execute([
                ':status' => $statusEnd,
                ':updated_at' => $updatedAt,
                ':id' => $tireId,
            ]);

            $this->recordTireHistory(
                $tireId,
                $oldPosition,
                null,
                $tire !== null ? (string) ($tire['status'] ?? self::STATUS_ACTIVE) : self::STATUS_ACTIVE,
                $statusEnd,
                $reason ?? 'Demontare anvelopa',
                $observation,
                $userId,
                $updatedAt
            );

            $this->db->commit();
            return true;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    private function getPastUsageByTireIds(array $tireIds): array
    {
        if ($tireIds === []) {
            return [];
        }

        $placeholders = [];
        $params = [];

        foreach (array_values($tireIds) as $index => $tireId) {
            $placeholder = ':tire_id_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = (int) $tireId;
        }

        $sql = 'SELECT tire_id, COALESCE(SUM(GREATEST(0, COALESCE(km_end, 0) - COALESCE(km_start, 0))), 0) AS km_past
                FROM anvelope_alocari
                WHERE tire_id IN (' . implode(', ', $placeholders) . ')
                  AND data_end IS NOT NULL
                GROUP BY tire_id';

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->execute();

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) $row['tire_id']] = (int) $row['km_past'];
        }

        return $result;
    }

    private function getFirstAllocationStartByTireIds(array $tireIds): array
    {
        if ($tireIds === []) {
            return [];
        }

        $placeholders = [];
        $params = [];

        foreach (array_values($tireIds) as $index => $tireId) {
            $placeholder = ':tire_id_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = (int) $tireId;
        }

        $sql = 'SELECT tire_id, MIN(COALESCE(km_start, 0)) AS first_km_start
                FROM anvelope_alocari
                WHERE tire_id IN (' . implode(', ', $placeholders) . ')
                GROUP BY tire_id';

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->execute();

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) $row['tire_id']] = (int) $row['first_km_start'];
        }

        return $result;
    }

    private function dotManufactureDate(?string $dotCode): ?DateTime
    {
        if ($dotCode === null) {
            return null;
        }

        $dotCode = trim($dotCode);
        if ($dotCode === '') {
            return null;
        }

        if (preg_match('/(\d{2})(\d{2})$/', $dotCode, $matches) !== 1) {
            return null;
        }

        $week = (int) $matches[1];
        $yearShort = (int) $matches[2];

        if ($week < 1 || $week > 53) {
            return null;
        }

        $currentYearShort = (int) date('y');
        $year = $yearShort <= $currentYearShort + 1 ? 2000 + $yearShort : 1900 + $yearShort;

        $date = new DateTime();
        $date->setISODate($year, $week, 1);
        $date->setTime(0, 0, 0);

        return $date;
    }

    public function buildVehicleTireContext(int $vehicleId, int $vehicleKmBord, string $vehicleType, ?string $layout): array
    {
        $this->ensureLifecycleSchema();

        $descriptor = $this->describeVehicleLayout($vehicleType, $layout);

        $stmt = $this->db->prepare(
            'SELECT
                p.id AS position_id,
                p.position_code,
                p.position_label,
                p.axle_no,
                p.axle_type,
                p.side_code,
                p.wheel_kind,
                p.position_order,
                a.id AS allocation_id,
                a.data_start AS allocation_start_date,
                a.km_start AS allocation_km_start,
                t.id AS tire_id,
                t.brand,
                t.model,
                t.tire_size,
                t.dot_code,
                t.serial_number,
                t.mount_date,
                t.km_initial,
                t.current_mileage,
                t.estimated_life_km,
                t.estimated_remaining_km,
                t.tread_depth_mm,
                t.min_tread_depth_mm,
                t.tire_type,
                t.condition_status,
                t.season,
                t.rotation_direction,
                t.status AS tire_status
            FROM vehicule_anvelope_pozitii p
            LEFT JOIN anvelope_alocari a ON a.position_id = p.id AND a.data_end IS NULL
            LEFT JOIN anvelope t ON t.id = a.tire_id
            WHERE p.vehicle_id = :vehicle_id
              AND p.is_active = 1
            ORDER BY p.position_order ASC, p.id ASC'
        );
        $stmt->execute([':vehicle_id' => $vehicleId]);
        $rows = $stmt->fetchAll();

        $tireIds = [];
        foreach ($rows as $row) {
            $tireId = (int) ($row['tire_id'] ?? 0);
            if ($tireId > 0) {
                $tireIds[$tireId] = $tireId;
            }
        }

        $pastUsageByTire = $this->getPastUsageByTireIds(array_values($tireIds));
        $firstAllocationStartByTire = $this->getFirstAllocationStartByTireIds(array_values($tireIds));
        $positions = [];
        $alerts = [];
        $mountedCount = 0;

        foreach ($rows as $row) {
            $tireId = (int) ($row['tire_id'] ?? 0);
            $entry = [
                'position_id' => (int) ($row['position_id'] ?? 0),
                'position_code' => (string) ($row['position_code'] ?? '-'),
                'position_label' => (string) ($row['position_label'] ?? '-'),
                'axle_no' => (int) ($row['axle_no'] ?? 0),
                'axle_type' => (string) ($row['axle_type'] ?? ''),
                'side_code' => (string) ($row['side_code'] ?? ''),
                'wheel_kind' => (string) ($row['wheel_kind'] ?? 'single'),
                'position_order' => (int) ($row['position_order'] ?? 0),
                'allocation_id' => $row['allocation_id'] !== null ? (int) $row['allocation_id'] : null,
                'has_tire' => $tireId > 0,
                'tire' => null,
            ];

            if ($tireId > 0) {
                $mountedCount++;

                $kmInitial = max(0, (int) ($row['km_initial'] ?? 0));
                $kmPast = max(0, (int) ($pastUsageByTire[$tireId] ?? 0));
                $kmStart = is_numeric((string) ($row['allocation_km_start'] ?? null)) ? (int) $row['allocation_km_start'] : $vehicleKmBord;
                $kmCurrentSegment = max(0, $vehicleKmBord - $kmStart);
                $firstAllocationStart = array_key_exists($tireId, $firstAllocationStartByTire)
                    ? (int) $firstAllocationStartByTire[$tireId]
                    : null;
                $kmInitialUsage = ($firstAllocationStart !== null && $kmInitial === $firstAllocationStart) ? 0 : $kmInitial;
                $kmTotalUsed = $kmInitialUsage + $kmPast + $kmCurrentSegment;
                $estimatedLifeKm = is_numeric((string) ($row['estimated_life_km'] ?? null)) ? (int) $row['estimated_life_km'] : null;
                $kmRemaining = $estimatedLifeKm !== null && $estimatedLifeKm > 0 ? max(0, $estimatedLifeKm - $kmTotalUsed) : null;
                $kmOver = $estimatedLifeKm !== null && $estimatedLifeKm > 0 ? max(0, $kmTotalUsed - $estimatedLifeKm) : 0;

                $dotCode = (string) ($row['dot_code'] ?? '');
                $dotManufacture = $this->dotManufactureDate($dotCode !== '' ? $dotCode : null);
                $dotExpiry = null;
                $dotDaysLeft = null;
                if ($dotManufacture instanceof DateTime) {
                    $dotExpiry = (clone $dotManufacture)->modify('+5 years');
                    $today = new DateTime('today');
                    $dotDaysLeft = (int) $today->diff($dotExpiry)->format('%r%a');
                }

                $treadDepth = is_numeric((string) ($row['tread_depth_mm'] ?? null)) ? (float) $row['tread_depth_mm'] : null;
                $minTreadDepth = is_numeric((string) ($row['min_tread_depth_mm'] ?? null)) ? (float) $row['min_tread_depth_mm'] : 2.0;

                $entry['tire'] = [
                    'id' => $tireId,
                    'brand' => (string) ($row['brand'] ?? ''),
                    'model' => (string) ($row['model'] ?? ''),
                    'tire_size' => (string) ($row['tire_size'] ?? ''),
                    'dot_code' => $dotCode,
                    'serial_number' => (string) ($row['serial_number'] ?? ''),
                    'mount_date' => (string) ($row['mount_date'] ?? ''),
                    'allocation_start_date' => (string) ($row['allocation_start_date'] ?? ''),
                    'tire_status' => (string) ($row['tire_status'] ?? self::STATUS_ACTIVE),
                    'tire_type' => (string) ($row['tire_type'] ?? self::TIRE_TYPE_TRAILER),
                    'tire_type_label' => $this->tireTypeLabel((string) ($row['tire_type'] ?? self::TIRE_TYPE_TRAILER)),
                    'condition_status' => (string) ($row['condition_status'] ?? 'good'),
                    'season' => (string) ($row['season'] ?? 'all_season'),
                    'rotation_direction' => (string) ($row['rotation_direction'] ?? ''),
                    'km_initial' => $kmInitial,
                    'current_mileage' => (int) ($row['current_mileage'] ?? 0),
                    'km_past' => $kmPast,
                    'km_current_segment' => $kmCurrentSegment,
                    'km_total_used' => $kmTotalUsed,
                    'estimated_life_km' => $estimatedLifeKm,
                    'estimated_remaining_km' => is_numeric((string) ($row['estimated_remaining_km'] ?? null)) ? (int) $row['estimated_remaining_km'] : null,
                    'km_remaining' => $kmRemaining,
                    'km_over' => $kmOver,
                    'tread_depth_mm' => $treadDepth,
                    'min_tread_depth_mm' => $minTreadDepth,
                    'dot_expiry_date' => $dotExpiry instanceof DateTime ? $dotExpiry->format('Y-m-d') : null,
                    'dot_days_left' => $dotDaysLeft,
                ];

                if ($dotDaysLeft !== null && $dotDaysLeft < 0) {
                    $alerts[] = [
                        'type' => 'danger',
                        'title' => 'DOT expirat',
                        'message' => $entry['position_code'] . ': anvelopa ' . trim($entry['tire']['brand'] . ' ' . $entry['tire']['model']) . ' are DOT expirat.',
                    ];
                } elseif ($dotDaysLeft !== null && $dotDaysLeft <= 90) {
                    $alerts[] = [
                        'type' => 'warning',
                        'title' => 'DOT aproape de expirare',
                        'message' => $entry['position_code'] . ': DOT expira in ' . $dotDaysLeft . ' zile.',
                    ];
                }

                if ($estimatedLifeKm !== null && $estimatedLifeKm > 0 && $kmTotalUsed >= $estimatedLifeKm) {
                    $alerts[] = [
                        'type' => 'danger',
                        'title' => 'Durata km depasita',
                        'message' => $entry['position_code'] . ': anvelopa a depasit durata setata cu ' . number_format((float) $kmOver, 0, ',', '.') . ' km.',
                    ];
                } elseif ($estimatedLifeKm !== null && $estimatedLifeKm > 0 && $kmTotalUsed >= (int) round($estimatedLifeKm * 0.9)) {
                    $alerts[] = [
                        'type' => 'warning',
                        'title' => 'Recomandare inlocuire',
                        'message' => $entry['position_code'] . ': anvelopa este peste 90% din durata estimata.',
                    ];
                }
            }

            $positions[] = $entry;
        }

        $historyStmt = $this->db->prepare(
            'SELECT
                a.id,
                a.tire_id,
                a.vehicle_id,
                a.position_id,
                a.data_start,
                a.data_end,
                a.km_start,
                a.km_end,
                a.status_end,
                a.created_at,
                p.position_code,
                p.position_label,
                t.brand,
                t.model,
                t.serial_number
            FROM anvelope_alocari a
            LEFT JOIN vehicule_anvelope_pozitii p ON p.id = a.position_id
            LEFT JOIN anvelope t ON t.id = a.tire_id
            WHERE a.vehicle_id = :vehicle_id
            ORDER BY a.created_at DESC
            LIMIT 80'
        );
        $historyStmt->execute([':vehicle_id' => $vehicleId]);
        $historyRows = $historyStmt->fetchAll();

        return [
            'layout' => [
                'vehicle_type' => $descriptor['vehicle_type'],
                'layout_value' => $descriptor['layout_value'],
                'axle_count' => $descriptor['axle_count'],
                'expected_tires' => $descriptor['expected_tires'],
                'mounted_tires' => $mountedCount,
                'unmounted_positions' => max(0, $descriptor['expected_tires'] - $mountedCount),
            ],
            'positions' => $positions,
            'alerts' => $alerts,
            'available_tires' => $this->getAvailableTires($vehicleType),
            'history' => $historyRows,
        ];
    }
}
