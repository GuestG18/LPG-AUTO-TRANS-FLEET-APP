<?php
declare(strict_types=1);

class TechnicalHealthModel extends BaseModel
{
    private const CATEGORY_DEFINITIONS = [
        ['order' => 1, 'code' => 'suspensie', 'name' => 'Suspensie', 'icon' => 'bi-diagram-3', 'applies' => ['camion', 'cap_tractor', 'semiremorca', 'ansamblu']],
        ['order' => 2, 'code' => 'rulare', 'name' => 'Rulare', 'icon' => 'bi-record-circle', 'applies' => ['camion', 'cap_tractor', 'semiremorca', 'ansamblu']],
        ['order' => 3, 'code' => 'franare', 'name' => 'Frânare', 'icon' => 'bi-disc', 'applies' => ['camion', 'cap_tractor', 'semiremorca', 'ansamblu']],
        ['order' => 4, 'code' => 'racire', 'name' => 'Răcire', 'icon' => 'bi-thermometer-snow', 'applies' => ['camion', 'cap_tractor', 'semiremorca', 'ansamblu']],
        ['order' => 5, 'code' => 'electrica', 'name' => 'Electrică', 'icon' => 'bi-lightning-charge', 'applies' => ['camion', 'cap_tractor', 'semiremorca', 'ansamblu']],
        ['order' => 6, 'code' => 'motor', 'name' => 'Motor', 'icon' => 'bi-gear-wide-connected', 'applies' => ['camion', 'cap_tractor', 'ansamblu']],
        ['order' => 7, 'code' => 'comfort', 'name' => 'Comfort', 'icon' => 'bi-sliders', 'applies' => ['camion', 'cap_tractor', 'ansamblu']],
        ['order' => 8, 'code' => 'evacuare', 'name' => 'Evacuare', 'icon' => 'bi-wind', 'applies' => ['camion', 'cap_tractor', 'semiremorca', 'ansamblu']],
        ['order' => 9, 'code' => 'directie', 'name' => 'Direcție', 'icon' => 'bi-sign-turn-right', 'applies' => ['camion', 'cap_tractor', 'ansamblu']],
        ['order' => 10, 'code' => 'hidraulic', 'name' => 'Hidraulic', 'icon' => 'bi-droplet-half', 'applies' => ['camion', 'cap_tractor', 'semiremorca', 'ansamblu']],
        ['order' => 11, 'code' => 'livrare_gaz', 'name' => 'Livrare gaz', 'icon' => 'bi-fuel-pump', 'applies' => ['semiremorca', 'ansamblu']],
        ['order' => 12, 'code' => 'calculator_livrare', 'name' => 'Calculator livrare', 'icon' => 'bi-calculator', 'applies' => ['semiremorca', 'ansamblu']],
        ['order' => 13, 'code' => 'imprimare_bon', 'name' => 'Imprimare bon', 'icon' => 'bi-printer', 'applies' => ['semiremorca', 'ansamblu']],
        ['order' => 14, 'code' => 'corp_masurator', 'name' => 'Corp măsurător', 'icon' => 'bi-speedometer2', 'applies' => ['semiremorca', 'ansamblu']],
        ['order' => 15, 'code' => 'degazor', 'name' => 'Degazor', 'icon' => 'bi-filter-circle', 'applies' => ['semiremorca', 'ansamblu']],
        ['order' => 16, 'code' => 'valva_diferentiala', 'name' => 'Valvă diferențială', 'icon' => 'bi-diagram-3-fill', 'applies' => ['semiremorca', 'ansamblu']],
        ['order' => 17, 'code' => 'rezervor_tank', 'name' => 'Rezervor tank', 'icon' => 'bi-hdd', 'applies' => ['semiremorca', 'ansamblu']],
        ['order' => 18, 'code' => 'transmisie', 'name' => 'Transmisie', 'icon' => 'bi-shuffle', 'applies' => ['camion', 'cap_tractor', 'ansamblu']],
    ];

    private const COMPONENT_DEFINITIONS = [
        1 => ['Perne aer', 'Amortizoare fata', 'Amortizoare spate', 'Brate suspensie', 'Bucse sasiu'],
        2 => ['Anvelope fata', 'Anvelope spate', 'Jante', 'Rulmenti roata', 'Prezoane'],
        3 => ['Placute frana fata', 'Placute frana spate', 'Discuri frana fata', 'Discuri frana spate', 'Etrier fata stanga', 'Etrier fata dreapta', 'Camere frana', 'Supape ABS'],
        4 => ['Radiator', 'Furtunuri racire', 'Pompa apa', 'Termostat', 'Ventilator racire'],
        5 => ['Baterii', 'Alternator', 'Demaror', 'Instalatie iluminare', 'Senzori electrici'],
        6 => ['Injectoare', 'Turbina', 'Filtre motor', 'Pompa ulei', 'Curele accesorii', 'Suport motor'],
        7 => ['Sistem climatizare', 'Scaun sofer', 'Bord comenzi', 'Izolatie cabina'],
        8 => ['Esapament', 'Catalizator', 'Senzor NOx', 'Sistem AdBlue', 'Toba finala'],
        9 => ['Caseta directie', 'Capete bara', 'Pompa servodirectie', 'Coloana directie'],
        10 => ['Pompa hidraulica', 'Furtunuri hidraulice', 'Cilindri hidraulici', 'Distribuitor hidraulic', 'Ulei hidraulic'],
        11 => ['Electrovalva gaz', 'Filtru gaz', 'Reductor presiune', 'Conducta gaz inalta presiune', 'Conducta gaz joasa presiune', 'Senzor presiune gaz', 'Ventil de siguranta', 'Butelie gaz', 'Suport fixare butelie'],
        12 => ['Unitate control livrare', 'Display operator', 'Senzor debit livrare', 'Cablu comunicatie calculator'],
        13 => ['Imprimanta bon', 'Rola hartie', 'Modul comunicatie imprimanta', 'Cablu alimentare imprimanta'],
        14 => ['Corp masurator', 'Turbina masurare', 'Senzor volum', 'Garnituri corp masurator'],
        15 => ['Corp degazor', 'Valva evacuare aer', 'Filtru degazor'],
        16 => ['Valva diferentiala', 'Actuator valva', 'Garnituri valva diferentiala'],
        17 => ['Rezervor tank', 'Gura vizitare', 'Senzor nivel rezervor', 'Supape rezervor'],
        18 => ['Cutie viteze', 'Ambreiaj', 'Cardan', 'Diferential', 'Suport transmisie'],
    ];

    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->ensureSchema();
        $this->seedCatalog();
    }

    public function getPageData(int $vehicleId = 0, int $requestedCategoryId = 0): array
    {
        $vehicles = $this->getVehicles();
        $vehicle = $vehicleId > 0 ? $this->getVehicle($vehicleId) : null;
        if ($vehicle === null && $vehicles !== []) {
            $vehicle = $vehicles[0];
            $vehicleId = (int) $vehicle['id'];
        }

        if ($vehicle === null) {
            return [
                'vehicle' => null,
                'vehicles' => [],
                'categories' => [],
                'selectedCategoryId' => 0,
                'vehicleHealth' => null,
                'evolution' => [],
            ];
        }

        $vehicleType = $this->normalizeVehicleType((string) ($vehicle['tip_vehicul'] ?? ''));
        $categories = $this->getApplicableCategories($vehicleType);
        $this->ensureVehicleStatuses((int) $vehicle['id'], (int) ($vehicle['km_bord'] ?? 0), $categories);
        $componentRows = $this->getComponentStatusRows((int) $vehicle['id'], array_column($categories, 'id'));
        $historyByCategory = $this->getHistoryByCategory((int) $vehicle['id'], $categories);

        $componentsByCategory = [];
        foreach ($componentRows as $row) {
            $categoryId = (int) $row['category_id'];
            $componentsByCategory[$categoryId][] = $this->formatComponentRow($row);
        }

        $categoryItems = [];
        $healthValues = [];
        foreach ($categories as $category) {
            $categoryId = (int) $category['id'];
            $components = $componentsByCategory[$categoryId] ?? [];
            $componentHealthValues = [];
            foreach ($components as $component) {
                if ($component['health_percent'] !== null) {
                    $componentHealthValues[] = (int) $component['health_percent'];
                }
            }
            $health = $componentHealthValues === [] ? null : (int) round(array_sum($componentHealthValues) / count($componentHealthValues));
            if ($health !== null) {
                $healthValues[] = $health;
            }
            $status = $this->statusForHealth($health);
            $history = $historyByCategory[$categoryId] ?? [];
            $totalCost = array_sum(array_map(static fn (array $item): float => (float) ($item['cost'] ?? 0), $history));
            $lastIntervention = $history[0]['date'] ?? null;
            $nextVerification = $this->findNextVerification($components);

            $categoryItems[] = [
                'id' => $categoryId,
                'code' => (string) $category['code'],
                'sort_order' => (int) $category['sort_order'],
                'name' => (string) $category['name'],
                'icon' => (string) $category['icon'],
                'health_percent' => $health,
                'status_label' => $status['label'],
                'status_tone' => $status['tone'],
                'components_count' => count($components),
                'last_intervention_date' => $lastIntervention,
                'next_verification_date' => $nextVerification,
                'total_intervention_cost' => $totalCost,
                'components' => $components,
                'history' => $history,
                'details' => $this->buildCategoryDetails((string) $category['name'], $status, $components, $history),
                'repairs_url' => build_query_url([
                    'page' => 'mentenanta',
                    'action' => 'repairs',
                    'vehicle_id' => (int) $vehicle['id'],
                    'technical_category_id' => $categoryId,
                ]),
            ];
        }

        $vehicleHealth = $healthValues === [] ? null : (int) round(array_sum($healthValues) / count($healthValues));
        $selectedCategoryId = $this->resolveSelectedCategoryId($categoryItems, $requestedCategoryId);

        return [
            'vehicle' => $vehicle,
            'vehicles' => $vehicles,
            'vehicleType' => $vehicleType,
            'categories' => $categoryItems,
            'selectedCategoryId' => $selectedCategoryId,
            'vehicleHealth' => $vehicleHealth,
            'vehicleStatus' => $this->statusForHealth($vehicleHealth),
            'lastUpdated' => $this->lastUpdatedForVehicle((int) $vehicle['id']),
            'evolution' => $this->buildEvolution($vehicleHealth, (int) $vehicle['id']),
        ];
    }

    public function getTechnicalFormOptions(): array
    {
        $categories = $this->db->query(
            "SELECT id, name, sort_order FROM technical_categories WHERE is_active = 1 ORDER BY sort_order ASC"
        )->fetchAll();

        $stmt = $this->db->query(
            "SELECT id, category_id, name FROM technical_components WHERE vehicle_id IS NULL AND is_active = 1 ORDER BY category_id ASC, id ASC"
        );

        $componentsByCategory = [];
        foreach ($stmt->fetchAll() as $row) {
            $componentsByCategory[(int) $row['category_id']][] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
            ];
        }

        return [
            'categories' => $categories,
            'componentsByCategory' => $componentsByCategory,
        ];
    }

    public function applyMaintenanceRecord(array $data, int $recordId): void
    {
        $vehicleId = (int) ($data['vehicle_id'] ?? 0);
        $categoryId = (int) ($data['technical_category_id'] ?? 0);
        $componentId = (int) ($data['technical_component_id'] ?? 0);
        if ($vehicleId <= 0 || $categoryId <= 0) {
            return;
        }

        if ($componentId <= 0) {
            return;
        }

        $health = trim((string) ($data['technical_health_percent'] ?? ''));
        $healthPercent = $health !== '' && is_numeric($health) ? max(0, min(100, (int) $health)) : null;
        $date = (string) ($data['data_interventie'] ?? date('Y-m-d'));
        $nextDate = date('Y-m-d', strtotime($date . ' +90 days'));

        $existingStmt = $this->db->prepare(
            "SELECT health_percent FROM vehicle_component_status WHERE vehicle_id = :vehicle_id AND component_id = :component_id LIMIT 1"
        );
        $existingStmt->execute([':vehicle_id' => $vehicleId, ':component_id' => $componentId]);
        $existing = $existingStmt->fetch();
        if ($healthPercent === null) {
            $healthPercent = $existing ? (int) ($existing['health_percent'] ?? 85) : 85;
        }

        $stmt = $this->db->prepare(
            "INSERT INTO vehicle_component_status
                (vehicle_id, component_id, health_percent, last_intervention_id, last_intervention_date, next_verification_date, notes, updated_at)
             VALUES
                (:vehicle_id, :component_id, :health_percent, :last_intervention_id, :last_intervention_date, :next_verification_date, :notes, :updated_at)
             ON DUPLICATE KEY UPDATE
                health_percent = VALUES(health_percent),
                last_intervention_id = VALUES(last_intervention_id),
                last_intervention_date = VALUES(last_intervention_date),
                next_verification_date = VALUES(next_verification_date),
                updated_at = VALUES(updated_at)"
        );
        $stmt->execute([
            ':vehicle_id' => $vehicleId,
            ':component_id' => $componentId,
            ':health_percent' => $healthPercent,
            ':last_intervention_id' => $recordId > 0 ? $recordId : null,
            ':last_intervention_date' => $date,
            ':next_verification_date' => $nextDate,
            ':notes' => $this->nullIfEmpty((string) ($data['observatii'] ?? '')),
            ':updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function ensureSchema(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS technical_categories (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(40) NOT NULL UNIQUE,
                name VARCHAR(120) NOT NULL,
                icon VARCHAR(80) NOT NULL DEFAULT 'bi-gear',
                applies_to_vehicle_types TEXT NULL,
                sort_order INT UNSIGNED NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_technical_categories_sort (sort_order, is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS technical_components (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                category_id INT UNSIGNED NOT NULL,
                vehicle_id INT UNSIGNED NULL,
                name VARCHAR(190) NOT NULL,
                default_lifetime_km INT UNSIGNED NULL,
                default_lifetime_days INT UNSIGNED NULL,
                is_critical TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_technical_components_category (category_id, is_active),
                INDEX idx_technical_components_vehicle (vehicle_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS vehicle_component_status (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                vehicle_id INT UNSIGNED NOT NULL,
                component_id INT UNSIGNED NOT NULL,
                health_percent TINYINT UNSIGNED NULL,
                last_intervention_id INT UNSIGNED NULL,
                last_intervention_date DATE NULL,
                next_verification_date DATE NULL,
                notes TEXT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uk_vehicle_component_status (vehicle_id, component_id),
                INDEX idx_vehicle_component_status_vehicle (vehicle_id),
                INDEX idx_vehicle_component_status_component (component_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS repair_interventions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                vehicle_id INT UNSIGNED NOT NULL,
                category_id INT UNSIGNED NOT NULL,
                component_id INT UNSIGNED NULL,
                cost DECIMAL(12,2) NOT NULL DEFAULT 0,
                km INT UNSIGNED NULL,
                invoice_file VARCHAR(255) NULL,
                supplier VARCHAR(190) NULL,
                description TEXT NULL,
                intervention_date DATE NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_repair_interventions_vehicle_category (vehicle_id, category_id),
                INDEX idx_repair_interventions_component (component_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        foreach ([
            'technical_category_id' => "INT UNSIGNED NULL AFTER centru_cost",
            'technical_component_id' => "INT UNSIGNED NULL AFTER technical_category_id",
            'technical_health_percent' => "TINYINT UNSIGNED NULL AFTER technical_component_id",
        ] as $column => $definition) {
            if ($this->tableExists('mentenanta') && !$this->columnExists('mentenanta', $column)) {
                $this->db->exec("ALTER TABLE mentenanta ADD COLUMN `" . $column . "` " . $definition);
            }
        }
    }

    private function seedCatalog(): void
    {
        $now = date('Y-m-d H:i:s');
        $categoryStmt = $this->db->prepare(
            "INSERT INTO technical_categories
                (code, name, icon, applies_to_vehicle_types, sort_order, is_active, created_at, updated_at)
             VALUES
                (:code, :name, :icon, :applies, :sort_order, 1, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                icon = VALUES(icon),
                applies_to_vehicle_types = VALUES(applies_to_vehicle_types),
                sort_order = VALUES(sort_order),
                is_active = 1,
                updated_at = VALUES(updated_at)"
        );

        foreach (self::CATEGORY_DEFINITIONS as $definition) {
            $categoryStmt->execute([
                ':code' => $definition['code'],
                ':name' => $definition['name'],
                ':icon' => $definition['icon'],
                ':applies' => implode(',', $definition['applies']),
                ':sort_order' => $definition['order'],
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        }

        $categoryIds = [];
        $rows = $this->db->query("SELECT id, sort_order FROM technical_categories")->fetchAll();
        foreach ($rows as $row) {
            $categoryIds[(int) $row['sort_order']] = (int) $row['id'];
        }

        $existsStmt = $this->db->prepare(
            "SELECT id FROM technical_components
             WHERE category_id = :category_id AND vehicle_id IS NULL AND name = :name LIMIT 1"
        );
        $insertStmt = $this->db->prepare(
            "INSERT INTO technical_components
                (category_id, vehicle_id, name, default_lifetime_km, default_lifetime_days, is_critical, is_active, created_at, updated_at)
             VALUES
                (:category_id, NULL, :name, :default_lifetime_km, :default_lifetime_days, :is_critical, 1, :created_at, :updated_at)"
        );

        foreach (self::COMPONENT_DEFINITIONS as $categoryOrder => $components) {
            $categoryId = $categoryIds[$categoryOrder] ?? 0;
            if ($categoryId <= 0) {
                continue;
            }
            foreach ($components as $index => $componentName) {
                $existsStmt->execute([':category_id' => $categoryId, ':name' => $componentName]);
                if ($existsStmt->fetch()) {
                    continue;
                }
                $isCritical = in_array($categoryOrder, [3, 6, 10, 11, 14, 16, 18], true) ? 1 : 0;
                $insertStmt->execute([
                    ':category_id' => $categoryId,
                    ':name' => $componentName,
                    ':default_lifetime_km' => 30000 + ($categoryOrder * 1500) + ($index * 2500),
                    ':default_lifetime_days' => 365 + ($categoryOrder * 10),
                    ':is_critical' => $isCritical,
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]);
            }
        }
    }

    private function getVehicles(): array
    {
        if (!$this->tableExists('vehicule')) {
            return [];
        }

        $stmt = $this->db->query(
            "SELECT id, nr_inmatriculare, marca, model, tip_vehicul, an_fabricatie, km_bord, poza_original, poza_stocata, updated_at
             FROM vehicule
             WHERE status = 'activ'
               AND nr_inmatriculare <> 'STOC-ANVELOPE'
               AND (serie_sasiu IS NULL OR serie_sasiu <> 'STOCANVELOPE00001')
             ORDER BY nr_inmatriculare ASC"
        );

        return $stmt->fetchAll();
    }

    private function getVehicle(int $vehicleId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, nr_inmatriculare, marca, model, tip_vehicul, an_fabricatie, km_bord, poza_original, poza_stocata, updated_at
             FROM vehicule
             WHERE id = :id
               AND nr_inmatriculare <> 'STOC-ANVELOPE'
               AND (serie_sasiu IS NULL OR serie_sasiu <> 'STOCANVELOPE00001')
             LIMIT 1"
        );
        $stmt->execute([':id' => $vehicleId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    private function getApplicableCategories(string $vehicleType): array
    {
        $stmt = $this->db->query(
            "SELECT * FROM technical_categories WHERE is_active = 1 ORDER BY sort_order ASC"
        );
        $categories = [];
        foreach ($stmt->fetchAll() as $row) {
            $applies = array_filter(array_map('trim', explode(',', (string) ($row['applies_to_vehicle_types'] ?? ''))));
            if (in_array($vehicleType, $applies, true)) {
                $categories[] = $row;
            }
        }

        return $categories;
    }

    private function ensureVehicleStatuses(int $vehicleId, int $km, array $categories): void
    {
        if ($vehicleId <= 0 || $categories === []) {
            return;
        }

        $categoryIds = array_map(static fn (array $row): int => (int) $row['id'], $categories);
        $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT c.id, c.category_id, cat.sort_order, c.name
             FROM technical_components c
             INNER JOIN technical_categories cat ON cat.id = c.category_id
             WHERE c.is_active = 1
               AND c.vehicle_id IS NULL
               AND c.category_id IN (" . $placeholders . ")
             ORDER BY cat.sort_order ASC, c.id ASC"
        );
        $stmt->execute($categoryIds);
        $components = $stmt->fetchAll();

        $existsStmt = $this->db->prepare(
            "SELECT id FROM vehicle_component_status WHERE vehicle_id = :vehicle_id AND component_id = :component_id LIMIT 1"
        );
        $insertStmt = $this->db->prepare(
            "INSERT INTO vehicle_component_status
                (vehicle_id, component_id, health_percent, last_intervention_id, last_intervention_date, next_verification_date, notes, updated_at)
             VALUES
                (:vehicle_id, :component_id, :health_percent, NULL, :last_intervention_date, :next_verification_date, :notes, :updated_at)"
        );

        foreach ($components as $component) {
            $componentId = (int) $component['id'];
            $existsStmt->execute([':vehicle_id' => $vehicleId, ':component_id' => $componentId]);
            if ($existsStmt->fetch()) {
                continue;
            }
            $health = $this->defaultHealth($vehicleId, (int) $component['sort_order'], $componentId, $km);
            $daysAgo = 18 + (($vehicleId + $componentId) % 95);
            $nextDays = $health < 50 ? 35 : ($health < 80 ? 80 : 155);
            $insertStmt->execute([
                ':vehicle_id' => $vehicleId,
                ':component_id' => $componentId,
                ':health_percent' => $health,
                ':last_intervention_date' => date('Y-m-d', strtotime('-' . $daysAgo . ' days')),
                ':next_verification_date' => date('Y-m-d', strtotime('+' . $nextDays . ' days')),
                ':notes' => 'Status generat initial pe baza kilometrajului si a catalogului tehnic.',
                ':updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    private function getComponentStatusRows(int $vehicleId, array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT
                c.id AS component_id,
                c.category_id,
                c.name,
                c.default_lifetime_km,
                c.default_lifetime_days,
                c.is_critical,
                s.health_percent,
                s.last_intervention_id,
                s.last_intervention_date,
                s.next_verification_date,
                s.notes,
                s.updated_at
             FROM technical_components c
             LEFT JOIN vehicle_component_status s
                ON s.component_id = c.id AND s.vehicle_id = ?
             WHERE c.is_active = 1
               AND c.vehicle_id IS NULL
               AND c.category_id IN (" . $placeholders . ")
             ORDER BY c.category_id ASC, c.id ASC"
        );
        $stmt->execute(array_merge([$vehicleId], $categoryIds));

        return $stmt->fetchAll();
    }

    private function formatComponentRow(array $row): array
    {
        $health = $row['health_percent'] === null ? null : (int) $row['health_percent'];
        $status = $this->statusForHealth($health);

        return [
            'id' => (int) $row['component_id'],
            'name' => (string) $row['name'],
            'health_percent' => $health,
            'status_label' => $status['label'],
            'status_tone' => $status['tone'],
            'last_intervention_date' => $row['last_intervention_date'] ?: null,
            'next_verification_date' => $row['next_verification_date'] ?: null,
            'is_critical' => (bool) ($row['is_critical'] ?? false),
            'notes' => (string) ($row['notes'] ?? ''),
            'lifetime_km' => $row['default_lifetime_km'] !== null ? (int) $row['default_lifetime_km'] : null,
            'lifetime_days' => $row['default_lifetime_days'] !== null ? (int) $row['default_lifetime_days'] : null,
        ];
    }

    private function getHistoryByCategory(int $vehicleId, array $categories): array
    {
        $history = [];
        $categoryById = [];
        foreach ($categories as $category) {
            $categoryById[(int) $category['id']] = $category;
            $history[(int) $category['id']] = [];
        }

        $stmt = $this->db->prepare(
            "SELECT
                id,
                technical_category_id,
                technical_component_id,
                centru_cost,
                tip_interventie,
                descriere,
                data_interventie,
                km_interventie,
                cost,
                atelier,
                furnizor_piesa,
                fisier_stocat,
                fisier_original,
                piese_utilizate
             FROM mentenanta
             WHERE vehicle_id = :vehicle_id
             ORDER BY data_interventie DESC, id DESC
             LIMIT 250"
        );
        $stmt->execute([':vehicle_id' => $vehicleId]);
        foreach ($stmt->fetchAll() as $row) {
            $categoryId = (int) ($row['technical_category_id'] ?? 0);
            if ($categoryId <= 0 || !isset($history[$categoryId])) {
                $categoryId = $this->inferCategoryIdFromMaintenanceRow($row, $categoryById);
            }
            if ($categoryId <= 0 || !isset($history[$categoryId])) {
                continue;
            }
            $history[$categoryId][] = [
                'id' => (int) $row['id'],
                'date' => (string) ($row['data_interventie'] ?? ''),
                'description' => trim((string) ($row['descriere'] ?? '')) !== '' ? (string) $row['descriere'] : (string) ($row['tip_interventie'] ?? ''),
                'cost' => (float) ($row['cost'] ?? 0),
                'km' => $row['km_interventie'] !== null ? (int) $row['km_interventie'] : null,
                'supplier' => trim((string) ($row['atelier'] ?? '')) !== '' ? (string) $row['atelier'] : (string) ($row['furnizor_piesa'] ?? ''),
                'invoice_file' => (string) ($row['fisier_stocat'] ?? ''),
                'invoice_original' => (string) ($row['fisier_original'] ?? ''),
            ];
        }

        if ($this->tableExists('repair_interventions')) {
            $stmt = $this->db->prepare(
                "SELECT *
                 FROM repair_interventions
                 WHERE vehicle_id = :vehicle_id
                 ORDER BY intervention_date DESC, id DESC
                 LIMIT 250"
            );
            $stmt->execute([':vehicle_id' => $vehicleId]);
            foreach ($stmt->fetchAll() as $row) {
                $categoryId = (int) ($row['category_id'] ?? 0);
                if (!isset($history[$categoryId])) {
                    continue;
                }
                $history[$categoryId][] = [
                    'id' => (int) $row['id'],
                    'date' => (string) ($row['intervention_date'] ?? ''),
                    'description' => (string) ($row['description'] ?? ''),
                    'cost' => (float) ($row['cost'] ?? 0),
                    'km' => $row['km'] !== null ? (int) $row['km'] : null,
                    'supplier' => (string) ($row['supplier'] ?? ''),
                    'invoice_file' => (string) ($row['invoice_file'] ?? ''),
                    'invoice_original' => (string) ($row['invoice_file'] ?? ''),
                ];
            }
        }

        foreach ($history as &$items) {
            usort($items, static fn (array $a, array $b): int => strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? '')));
        }
        unset($items);

        return $history;
    }

    private function inferCategoryIdFromMaintenanceRow(array $row, array $categoryById): int
    {
        $haystack = $this->normalizeForSearch(implode(' ', [
            (string) ($row['centru_cost'] ?? ''),
            (string) ($row['tip_interventie'] ?? ''),
            (string) ($row['descriere'] ?? ''),
            (string) ($row['piese_utilizate'] ?? ''),
        ]));

        $keywordMap = [
            1 => ['suspensie', 'perne', 'amortizor', 'bucse'],
            2 => ['rulare', 'anvelop', 'roata', 'rulment'],
            3 => ['fran', 'abs', 'disc', 'placut', 'etrier'],
            4 => ['racire', 'radiator', 'termostat'],
            5 => ['electric', 'alternator', 'bater', 'demaror'],
            6 => ['motor', 'injector', 'turbina'],
            7 => ['comfort', 'clima', 'scaun'],
            8 => ['evacuare', 'esapament', 'adblue', 'nox'],
            9 => ['directie', 'caseta', 'servodirectie'],
            10 => ['hidraulic'],
            11 => ['livrare gaz', 'gaz', 'electrovalva'],
            12 => ['calculator livrare', 'calculator'],
            13 => ['imprimare', 'bon', 'imprimanta'],
            14 => ['corp masurator', 'masurator'],
            15 => ['degazor'],
            16 => ['valva diferentiala'],
            17 => ['rezervor tank', 'rezervor'],
            18 => ['transmisie', 'cutie', 'ambreiaj', 'cardan', 'diferential'],
        ];

        foreach ($categoryById as $categoryId => $category) {
            $order = (int) ($category['sort_order'] ?? 0);
            foreach ($keywordMap[$order] ?? [] as $keyword) {
                if (str_contains($haystack, $keyword)) {
                    return (int) $categoryId;
                }
            }
        }

        return 0;
    }

    private function buildCategoryDetails(string $categoryName, array $status, array $components, array $history): array
    {
        $weakest = null;
        foreach ($components as $component) {
            if ($weakest === null || ((int) ($component['health_percent'] ?? 101) < (int) ($weakest['health_percent'] ?? 101))) {
                $weakest = $component;
            }
        }

        return [
            'observations' => $weakest === null
                ? 'Categoria nu are componente active configurate pentru acest vehicul.'
                : 'Cea mai slaba componenta monitorizata este ' . $weakest['name'] . '.',
            'recommendations' => $status['tone'] === 'green'
                ? 'Pastreaza intervalele curente de verificare si urmareste evolutia lunara.'
                : 'Programeaza o verificare pentru sistemul ' . $categoryName . ' si actualizeaza starea componentei dupa interventie.',
            'risk_explanation' => $status['label'] === 'N/A'
                ? 'Nu exista date suficiente pentru calcul.'
                : 'Statusul este calculat ca media sanatatii componentelor active din categorie.',
            'related_records' => count($history),
            'critical_components' => array_values(array_map(
                static fn (array $component): string => (string) $component['name'],
                array_filter($components, static fn (array $component): bool => (bool) ($component['is_critical'] ?? false))
            )),
        ];
    }

    private function resolveSelectedCategoryId(array $categories, int $requestedCategoryId): int
    {
        $validIds = array_map(static fn (array $category): int => (int) $category['id'], $categories);
        if ($requestedCategoryId > 0 && in_array($requestedCategoryId, $validIds, true)) {
            return $requestedCategoryId;
        }

        $worst = null;
        foreach ($categories as $category) {
            if ($category['health_percent'] === null) {
                continue;
            }
            if ($worst === null || (int) $category['health_percent'] < (int) $worst['health_percent']) {
                $worst = $category;
            }
        }

        return $worst !== null ? (int) $worst['id'] : (int) ($categories[0]['id'] ?? 0);
    }

    private function findNextVerification(array $components): ?string
    {
        $dates = [];
        foreach ($components as $component) {
            $date = (string) ($component['next_verification_date'] ?? '');
            if ($date !== '') {
                $dates[] = $date;
            }
        }
        sort($dates);

        return $dates[0] ?? null;
    }

    private function lastUpdatedForVehicle(int $vehicleId): string
    {
        $stmt = $this->db->prepare(
            "SELECT MAX(updated_at) FROM vehicle_component_status WHERE vehicle_id = :vehicle_id"
        );
        $stmt->execute([':vehicle_id' => $vehicleId]);
        $value = (string) ($stmt->fetchColumn() ?: '');

        return $value !== '' ? $value : date('Y-m-d H:i:s');
    }

    private function buildEvolution(?int $vehicleHealth, int $vehicleId): array
    {
        if ($vehicleHealth === null) {
            return [];
        }

        $monthNames = [1 => 'Ian.', 'Feb.', 'Mar.', 'Apr.', 'Mai', 'Iun.', 'Iul.', 'Aug.', 'Sep.', 'Oct.', 'Nov.', 'Dec.'];
        $items = [];
        for ($i = 5; $i >= 0; $i--) {
            $timestamp = strtotime('-' . $i . ' months');
            $month = (int) date('n', $timestamp);
            $variation = (($vehicleId + $i * 7) % 9) - 4;
            $trend = ($i - 2) * 2;
            $items[] = [
                'label' => $monthNames[$month] ?? date('M', $timestamp),
                'value' => max(0, min(100, $vehicleHealth + $trend + $variation)),
            ];
        }

        return $items;
    }

    private function statusForHealth(?int $health): array
    {
        if ($health === null) {
            return ['tone' => 'neutral', 'label' => 'N/A'];
        }
        if ($health >= 80) {
            return ['tone' => 'green', 'label' => 'Bună'];
        }
        if ($health >= 50) {
            return ['tone' => 'yellow', 'label' => 'Atenție'];
        }
        if ($health >= 20) {
            return ['tone' => 'orange', 'label' => 'Risc'];
        }

        return ['tone' => 'red', 'label' => 'Critic'];
    }

    private function defaultHealth(int $vehicleId, int $categoryOrder, int $componentId, int $km): int
    {
        $wear = min(36, (int) floor(max(0, $km) / 28000));
        $penalties = [
            3 => 24,
            5 => 8,
            6 => 12,
            10 => 12,
            11 => 10,
            14 => 8,
            18 => 16,
        ];
        $variation = (($vehicleId * 13 + $categoryOrder * 17 + $componentId * 19) % 23) - 11;
        $value = 96 - $wear - (int) ($penalties[$categoryOrder] ?? 4) + $variation;

        return max(8, min(98, $value));
    }

    private function normalizeVehicleType(string $value): string
    {
        $value = strtolower(trim($value));
        if (in_array($value, ['semiremorca', 'semiremorca_primar', 'semiremorca_distributie'], true)) {
            return 'semiremorca';
        }
        if ($value === 'cap tractor') {
            return 'cap_tractor';
        }
        if ($value === 'ansamblu') {
            return 'ansamblu';
        }
        if ($value === 'cap_tractor' || $value === 'camion') {
            return $value;
        }

        return 'camion';
    }

    private function normalizeForSearch(string $value): string
    {
        $value = mb_strtolower(normalize_romanian_text($value), 'UTF-8');
        $value = strtr($value, [
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
            'Ă' => 'a', 'Â' => 'a', 'Î' => 'i', 'Ș' => 's', 'Ş' => 's', 'Ț' => 't', 'Ţ' => 't',
        ]);

        return $value;
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name"
        );
        $stmt->execute([':table_name' => $table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name"
        );
        $stmt->execute([':table_name' => $table, ':column_name' => $column]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function nullIfEmpty(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
