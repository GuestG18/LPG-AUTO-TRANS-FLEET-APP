<?php
declare(strict_types=1);

class TireModel extends BaseModel
{
    private const STOCK_VEHICLE_PLATE = 'STOC-ANVELOPE';
    private const STOCK_VEHICLE_CHASSIS = 'STOCANVELOPE00001';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_SPARE = 'spare';
    public const STATUS_REMOVED = 'removed';
    public const STATUS_DAMAGED = 'damaged';
    public const STATUS_RETREADED = 'retreaded';

    private function normalizeVehicleType(string $vehicleType): string
    {
        $normalized = strtolower(trim($vehicleType));

        return match ($normalized) {
            'autovehicul', 'autoturism' => 'autovehicul',
            'camion' => 'camion',
            'cap_tractor' => 'cap_tractor',
            'semiremorca' => 'semiremorca',
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
            return '2x2';
        }

        if ($vehicleType === 'semiremorca') {
            $axleCount = $this->parseAxleCountFromLayout($layoutValueLower);
            if (!in_array($axleCount, [2, 3, 4, 6], true)) {
                $axleCount = 3;
            }

            return $axleCount . ' axe';
        }

        if (preg_match('/^(\d+)\s*x\s*(\d+)$/', $layoutValueLower, $matches) === 1) {
            $left = (int) $matches[1];
            $right = (int) $matches[2];
            if ($left >= 4 && $left <= 12 && $left % 2 === 0 && $right >= 2 && $right <= $left && $right % 2 === 0) {
                return $left . 'x' . $right;
            }
        }

        if ($vehicleType === 'cap_tractor') {
            return '4x2';
        }

        return '4x2';
    }

    public function getLayoutOptionsByVehicleType(string $vehicleType): array
    {
        $vehicleType = $this->normalizeVehicleType($vehicleType);

        if ($vehicleType === 'autovehicul') {
            return ['2x2' => 'Standard 2 axe (4 anvelope)'];
        }

        if ($vehicleType === 'semiremorca') {
            return [
                '2 axe' => '2 axe (8 anvelope)',
                '3 axe' => '3 axe (12 anvelope)',
                '4 axe' => '4 axe (16 anvelope)',
                '6 axe' => '6 axe (24 anvelope)',
            ];
        }

        if ($vehicleType === 'cap_tractor') {
            return [
                '4x2' => '4x2 (2 axe / 6 anvelope)',
                '6x2' => '6x2 (3 axe / 10 anvelope)',
                '6x4' => '6x4 (3 axe / 10 anvelope)',
                '8x4' => '8x4 (4 axe / 14 anvelope)',
            ];
        }

        return [
            '4x2' => '4x2 (2 axe / 6 anvelope)',
            '6x2' => '6x2 (3 axe / 10 anvelope)',
            '6x4' => '6x4 (3 axe / 10 anvelope)',
            '8x4' => '8x4 (4 axe / 14 anvelope)',
        ];
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

            for ($axle = 1; $axle <= $axleCount; $axle++) {
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
        } elseif ($vehicleType === 'semiremorca') {
            $axleCount = $this->parseAxleCountFromLayout($layout) ?? 3;
            if (!in_array($axleCount, [2, 3, 4, 6], true)) {
                $axleCount = 3;
            }

            for ($axle = 1; $axle <= $axleCount; $axle++) {
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
        } else {
            if (preg_match('/^(\d+)\s*x\s*(\d+)$/', $layout, $matches) === 1) {
                $axleCount = max(2, (int) ((int) $matches[1] / 2));
            } else {
                $axleCount = 2;
            }

            for ($axle = 1; $axle <= $axleCount; $axle++) {
                if ($axle === 1) {
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
                    continue;
                }

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
        }

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
        $activeCodes = [];

        foreach ($positions as $position) {
            $code = (string) $position['position_code'];
            $activeCodes[$code] = true;

            if (isset($existingByCode[$code])) {
                $update = $this->db->prepare(
                    'UPDATE vehicule_anvelope_pozitii
                     SET position_label = :position_label,
                         axle_no = :axle_no,
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
                (vehicle_id, position_code, position_label, axle_no, side_code, wheel_kind, position_order, is_active, created_at, updated_at)
                VALUES
                (:vehicle_id, :position_code, :position_label, :axle_no, :side_code, :wheel_kind, :position_order, 1, :created_at, :updated_at)'
            );
            $insert->execute([
                ':vehicle_id' => $vehicleId,
                ':position_code' => $position['position_code'],
                ':position_label' => $position['position_label'],
                ':axle_no' => $position['axle_no'],
                ':side_code' => $position['side_code'],
                ':wheel_kind' => $position['wheel_kind'],
                ':position_order' => $position['position_order'],
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
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
                continue;
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

    public function getAvailableTires(?string $vehicleType = null): array
    {
        $sql = 'SELECT t.*
                FROM anvelope t
                LEFT JOIN anvelope_alocari a ON a.tire_id = t.id AND a.data_end IS NULL
                WHERE a.id IS NULL
                  AND t.status IN ("spare", "retreaded", "active")';

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
        $stmt = $this->db->prepare('SELECT * FROM anvelope WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $tireId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function getVehiclePositionById(int $positionId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM vehicule_anvelope_pozitii WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $positionId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function createTire(array $data): int
    {
        $sql = 'INSERT INTO anvelope
            (brand, model, tire_size, dot_code, serial_number, target_vehicle_type, mount_date, km_initial, estimated_life_km, tread_depth_mm, min_tread_depth_mm, status, notes, created_at, updated_at)
            VALUES
            (:brand, :model, :tire_size, :dot_code, :serial_number, :target_vehicle_type, :mount_date, :km_initial, :estimated_life_km, :tread_depth_mm, :min_tread_depth_mm, :status, :notes, :created_at, :updated_at)';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':brand' => $data['brand'],
            ':model' => $data['model'],
            ':tire_size' => $data['tire_size'],
            ':dot_code' => $data['dot_code'],
            ':serial_number' => $data['serial_number'],
            ':target_vehicle_type' => $this->normalizeTargetVehicleType((string) ($data['target_vehicle_type'] ?? 'universal')),
            ':mount_date' => $data['mount_date'],
            ':km_initial' => $data['km_initial'],
            ':estimated_life_km' => $data['estimated_life_km'],
            ':tread_depth_mm' => $data['tread_depth_mm'],
            ':min_tread_depth_mm' => $data['min_tread_depth_mm'],
            ':status' => $data['status'],
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
        $quantity = max(1, (int) ($data['quantity'] ?? 1));
        $quantity = min($quantity, 1000);

        $brand = trim((string) ($data['brand'] ?? ''));
        if ($brand === '') {
            throw new RuntimeException('Brandul este obligatoriu pentru stocul de anvelope.');
        }

        $status = strtolower(trim((string) ($data['status'] ?? self::STATUS_SPARE)));
        if (!in_array($status, [self::STATUS_SPARE, self::STATUS_RETREADED, self::STATUS_REMOVED, self::STATUS_DAMAGED], true)) {
            $status = self::STATUS_SPARE;
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
                'mount_date' => $mountDate,
                'km_initial' => $kmInitial,
                'estimated_life_km' => $estimatedLifeKm,
                'tread_depth_mm' => $data['tread_depth_mm'] ?? null,
                'min_tread_depth_mm' => $data['min_tread_depth_mm'] ?? 2.0,
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
            self::STATUS_ACTIVE => 'Active',
            self::STATUS_RETREADED => 'Retreaded',
            self::STATUS_DAMAGED => 'Damaged',
            self::STATUS_REMOVED => 'Removed',
            default => 'Spare',
        };
    }

    private function ensureStockVehicleId(string $now): int
    {
        $findStmt = $this->db->prepare('SELECT id FROM vehicule WHERE nr_inmatriculare = :plate LIMIT 1');
        $findStmt->execute([':plate' => self::STOCK_VEHICLE_PLATE]);
        $existingId = $findStmt->fetchColumn();
        if (is_numeric((string) $existingId) && (int) $existingId > 0) {
            return (int) $existingId;
        }

        $insertStmt = $this->db->prepare(
            'INSERT INTO vehicule
            (nr_inmatriculare, marca, model, tip_vehicul, an_fabricatie, km_bord, km_revizie, serie_sasiu, nr_fabricatie, formula_axelor, status, observatii, created_at, updated_at)
            VALUES
            (:nr_inmatriculare, :marca, :model, :tip_vehicul, :an_fabricatie, :km_bord, :km_revizie, :serie_sasiu, :nr_fabricatie, :formula_axelor, :status, :observatii, :created_at, :updated_at)'
        );
        $insertStmt->execute([
            ':nr_inmatriculare' => self::STOCK_VEHICLE_PLATE,
            ':marca' => 'Stoc',
            ':model' => 'Anvelope',
            ':tip_vehicul' => 'autovehicul',
            ':an_fabricatie' => (int) date('Y'),
            ':km_bord' => 0,
            ':km_revizie' => 0,
            ':serie_sasiu' => self::STOCK_VEHICLE_CHASSIS,
            ':nr_fabricatie' => 'STOC-ANVELOPE',
            ':formula_axelor' => '2x2',
            ':status' => 'inactiv',
            ':observatii' => 'Vehicul tehnic folosit pentru inregistrarea anvelopelor din stocul general.',
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return (int) $this->db->lastInsertId();
    }

    private function resolveTireVehicleId(array $row, int $stockVehicleId): int
    {
        $activeVehicleId = (int) ($row['active_vehicle_id'] ?? 0);
        if ($activeVehicleId > 0) {
            return $activeVehicleId;
        }

        $lastVehicleId = (int) ($row['last_vehicle_id'] ?? 0);
        if ($lastVehicleId > 0) {
            return $lastVehicleId;
        }

        return $stockVehicleId;
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
        $stockVehicleId = $this->ensureStockVehicleId($now);
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

            $vehicleId = $this->resolveTireVehicleId($row, $stockVehicleId);
            $payload = $this->buildMaintenancePayload($row, $vehicleId, $now);
            $linkedMentenantaId = (int) ($row['mentenanta_id'] ?? 0);
            $linkedExists = (int) ($row['linked_mentenanta_id'] ?? 0) > 0;

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

        return $updatedEntries;
    }

    public function hasMaintenanceSyncGaps(): bool
    {
        $stmt = $this->db->query(
            'SELECT 1
             FROM anvelope t
             LEFT JOIN mentenanta m ON m.id = t.mentenanta_id
             WHERE t.mentenanta_id IS NULL OR m.id IS NULL
             LIMIT 1'
        );

        return $stmt->fetchColumn() !== false;
    }

    public function getStockTireById(int $tireId): ?array
    {
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
        $setParts = [];
        $params = [':id' => $tireId];

        $allowedColumns = [
            'brand',
            'model',
            'tire_size',
            'dot_code',
            'target_vehicle_type',
            'mount_date',
            'estimated_life_km',
            'status',
            'tread_depth_mm',
            'min_tread_depth_mm',
            'notes',
            'updated_at',
        ];

        foreach ($allowedColumns as $column) {
            if (!array_key_exists($column, $data)) {
                continue;
            }
            $setParts[] = $column . ' = :' . $column;
            $params[':' . $column] = $data[$column];
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
        $sql = 'SELECT COALESCE(target_vehicle_type, "universal") AS target_vehicle_type, COUNT(*) AS total_count
                FROM anvelope t
                LEFT JOIN anvelope_alocari a ON a.tire_id = t.id AND a.data_end IS NULL
                WHERE a.id IS NULL
                  AND t.status IN ("spare", "retreaded")
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
        $sql = 'SELECT t.status, COUNT(*) AS total_count
                FROM anvelope t
                LEFT JOIN anvelope_alocari a ON a.tire_id = t.id AND a.data_end IS NULL
                WHERE a.id IS NULL
                GROUP BY t.status';
        $stmt = $this->db->query($sql);

        $result = [
            self::STATUS_SPARE => 0,
            self::STATUS_RETREADED => 0,
            self::STATUS_REMOVED => 0,
            self::STATUS_DAMAGED => 0,
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

    public function buildMaintenanceStockContext(): array
    {
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
            'ready_mountable_total' => ($statusCounts[self::STATUS_SPARE] ?? 0) + ($statusCounts[self::STATUS_RETREADED] ?? 0),
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

        $stockPreviewStmt = $this->db->query(
            'SELECT
                t.id,
                t.brand,
                t.model,
                t.tire_size,
                t.dot_code,
                t.serial_number,
                t.target_vehicle_type,
                t.status,
                t.mount_date,
                t.estimated_life_km,
                t.tread_depth_mm,
                t.min_tread_depth_mm,
                t.notes,
                t.mentenanta_id,
                t.updated_at
             FROM anvelope t
             LEFT JOIN anvelope_alocari a ON a.tire_id = t.id AND a.data_end IS NULL
             WHERE a.id IS NULL
             ORDER BY t.updated_at DESC, t.id DESC
             LIMIT 50'
        );
        $stockPreview = $stockPreviewStmt->fetchAll();

        return [
            'totals' => $totals,
            'status_counts' => $statusCounts,
            'ready_stock_by_type' => $readyStockByType,
            'needs_by_type' => array_values($typeNeeds),
            'vehicle_needs' => $vehicleNeeds,
            'stock_preview' => $stockPreview,
            'target_type_options' => $this->getTargetVehicleTypeOptions(),
        ];
    }

    public function mountTire(
        int $tireId,
        int $vehicleId,
        int $positionId,
        int $vehicleKmBord,
        string $mountDate,
        string $mountDateTime,
        ?int $userId = null
    ): void {
        $this->db->beginTransaction();

        try {
            $position = $this->getVehiclePositionById($positionId);
            if ($position === null || (int) ($position['vehicle_id'] ?? 0) !== $vehicleId || (int) ($position['is_active'] ?? 0) !== 1) {
                throw new RuntimeException('Pozitia selectata nu este valida pentru acest vehicul.');
            }

            $activeTireAllocationStmt = $this->db->prepare(
                'SELECT * FROM anvelope_alocari WHERE tire_id = :tire_id AND data_end IS NULL LIMIT 1'
            );
            $activeTireAllocationStmt->execute([':tire_id' => $tireId]);
            $existingTireAllocation = $activeTireAllocationStmt->fetch() ?: null;

            if (is_array($existingTireAllocation)) {
                $allocationVehicleId = (int) ($existingTireAllocation['vehicle_id'] ?? 0);
                if ($allocationVehicleId !== $vehicleId || (int) ($existingTireAllocation['position_id'] ?? 0) !== $positionId) {
                    $kmEnd = $vehicleKmBord;
                    if ($allocationVehicleId > 0) {
                        $vehicleKmStmt = $this->db->prepare('SELECT km_bord FROM vehicule WHERE id = :id LIMIT 1');
                        $vehicleKmStmt->execute([':id' => $allocationVehicleId]);
                        $vehicleKmRaw = $vehicleKmStmt->fetchColumn();
                        if (is_numeric((string) $vehicleKmRaw)) {
                            $kmEnd = (int) $vehicleKmRaw;
                        }
                    }

                    $closeAllocation = $this->db->prepare(
                        'UPDATE anvelope_alocari
                         SET data_end = :data_end,
                             km_end = :km_end,
                             status_end = :status_end,
                             updated_at = :updated_at
                         WHERE id = :id'
                    );
                    $closeAllocation->execute([
                        ':data_end' => $mountDate,
                        ':km_end' => $kmEnd,
                        ':status_end' => 'moved',
                        ':updated_at' => $mountDateTime,
                        ':id' => (int) ($existingTireAllocation['id'] ?? 0),
                    ]);
                } else {
                    $this->db->commit();
                    return;
                }
            }

            $existingPositionAllocationStmt = $this->db->prepare(
                'SELECT * FROM anvelope_alocari WHERE position_id = :position_id AND data_end IS NULL LIMIT 1'
            );
            $existingPositionAllocationStmt->execute([':position_id' => $positionId]);
            $existingPositionAllocation = $existingPositionAllocationStmt->fetch() ?: null;

            if (is_array($existingPositionAllocation) && (int) ($existingPositionAllocation['tire_id'] ?? 0) !== $tireId) {
                $closePositionAllocation = $this->db->prepare(
                    'UPDATE anvelope_alocari
                     SET data_end = :data_end,
                         km_end = :km_end,
                         status_end = :status_end,
                         updated_at = :updated_at
                     WHERE id = :id'
                );
                $closePositionAllocation->execute([
                    ':data_end' => $mountDate,
                    ':km_end' => $vehicleKmBord,
                    ':status_end' => 'spare',
                    ':updated_at' => $mountDateTime,
                    ':id' => (int) ($existingPositionAllocation['id'] ?? 0),
                ]);

                $setSpare = $this->db->prepare('UPDATE anvelope SET status = :status, updated_at = :updated_at WHERE id = :id');
                $setSpare->execute([
                    ':status' => self::STATUS_SPARE,
                    ':updated_at' => $mountDateTime,
                    ':id' => (int) ($existingPositionAllocation['tire_id'] ?? 0),
                ]);
            }

            $insert = $this->db->prepare(
                'INSERT INTO anvelope_alocari
                (tire_id, vehicle_id, position_id, data_start, km_start, created_by, created_at, updated_at)
                VALUES
                (:tire_id, :vehicle_id, :position_id, :data_start, :km_start, :created_by, :created_at, :updated_at)'
            );
            $insert->execute([
                ':tire_id' => $tireId,
                ':vehicle_id' => $vehicleId,
                ':position_id' => $positionId,
                ':data_start' => $mountDate,
                ':km_start' => $vehicleKmBord,
                ':created_by' => $userId,
                ':created_at' => $mountDateTime,
                ':updated_at' => $mountDateTime,
            ]);

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
        string $updatedAt
    ): bool {
        $statusEnd = strtolower(trim($statusEnd));
        if (!in_array($statusEnd, [self::STATUS_SPARE, self::STATUS_REMOVED, self::STATUS_DAMAGED, self::STATUS_RETREADED], true)) {
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
                ':id' => (int) ($allocation['tire_id'] ?? 0),
            ]);

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
        $descriptor = $this->describeVehicleLayout($vehicleType, $layout);

        $stmt = $this->db->prepare(
            'SELECT
                p.id AS position_id,
                p.position_code,
                p.position_label,
                p.axle_no,
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
                t.estimated_life_km,
                t.tread_depth_mm,
                t.min_tread_depth_mm,
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
                    'km_initial' => $kmInitial,
                    'km_past' => $kmPast,
                    'km_current_segment' => $kmCurrentSegment,
                    'km_total_used' => $kmTotalUsed,
                    'estimated_life_km' => $estimatedLifeKm,
                    'km_remaining' => $kmRemaining,
                    'km_over' => $kmOver,
                    'tread_depth_mm' => $treadDepth,
                    'min_tread_depth_mm' => $minTreadDepth,
                    'dot_expiry_date' => $dotExpiry instanceof DateTime ? $dotExpiry->format('Y-m-d') : null,
                    'dot_days_left' => $dotDaysLeft,
                ];

                if ($treadDepth !== null && $treadDepth <= $minTreadDepth) {
                    $alerts[] = [
                        'type' => 'danger',
                        'title' => 'Banda de rulare scazuta',
                        'message' => $entry['position_code'] . ': ' . trim($entry['tire']['brand'] . ' ' . $entry['tire']['model']) . ' are ' . number_format($treadDepth, 2, ',', '.') . ' mm.',
                    ];
                }

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
