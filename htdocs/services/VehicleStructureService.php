<?php
declare(strict_types=1);

/**
 * Configuratiile de vehicul din pagina Auto (?page=mentenanta&action=auto).
 *
 * - O configuratie generala (mentenanta_auto_structuri) spune ce sisteme si ce componente are un tip
 *   de vehicul cu un anumit numar de axe. Vehiculele o primesc automat dupa tip + axe (implicita) sau
 *   prin asignare manuala (vehicule_structura_asignare).
 * - Un vehicul pastreaza doar diferentele: ce i s-a adaugat / eliminat (vehicule_structura_diferente)
 *   si valorile proprii (randul lui din mentenanta_auto_configurari). Restul urmeaza configuratia
 *   generala, deci o modificare acolo ajunge la toate vehiculele care nu au suprascris-o.
 * - Schema vehiculului deseneaza exact rezultatul (effective / schemeFilter). Axele raman cele din
 *   vehicule_anvelope_pozitii; o configuratie nu le schimba.
 */
final class VehicleStructureService
{
    public const VEHICLE_TYPES = ['camion' => 'Camion', 'cap_tractor' => 'Cap tractor', 'semiremorca' => 'Semiremorcă'];

    /** Sistemele legate de axe: componentele lor pot fi limitate la anumite axe. */
    public const AXLE_SCOPED = ['franare', 'rulare', 'suspensie', 'plastice', 'diferential'];

    public const MONITORING_TYPES = [
        'Kilometri' => ['methods' => ['Kilometri'], 'unit' => 'km'],
        'Ore functionare' => ['methods' => ['Ore functionare'], 'unit' => 'ore'],
        'Timp luni/ani' => ['methods' => ['Timp (luni)'], 'unit' => 'luni'],
        'Combinatie' => ['methods' => ['Kilometri', 'Ore functionare', 'Timp (luni)'], 'unit' => 'km'],
    ];

    private const AXLE_LABELS = ['steering' => 'Direcție', 'traction' => 'Tracțiune', 'trailer' => 'Remorcă'];
    private const MAX_AXLES = 6;
    private const PREPARED_LAYOUTS = [['semiremorca', 4], ['semiremorca', 6]];

    private PDO $db;
    private ?array $profiles = null;
    private ?array $catalog = null;
    private ?array $catalogMap = null;
    /** @var array<int,array> */
    private array $structureCache = [];
    private static ?bool $installed = null;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /* ------------------------------------------------------------------ instalare */

    /**
     * Tabelele + configuratiile implicite pentru combinatiile tip/axe din flota. Se apeleaza doar la
     * deschiderea paginii Auto (contine DDL): cititorii din alte module folosesc isInstalled().
     */
    public function prepare(): void
    {
        $this->ensureSchema();
        new VehicleComponentModel($this->db); // coloana tip_axa, citita de effective()
        $this->repairFourAxleTrucks();
        $this->ensureDefaults();
    }

    /**
     * Camioanele 8x2 salvate cu rotile duble pe axa 4: puntea motoare e axa 3. Rescrie pozitiile prin
     * sincronizarea obisnuita; un camion cu anvelope montate pe axele 3-4 ramane pentru Configuratie Axe.
     */
    private function repairFourAxleTrucks(): void
    {
        $ids = $this->db->query(
            "SELECT DISTINCT v.id
             FROM vehicule v
             INNER JOIN vehicule_anvelope_pozitii p ON p.vehicle_id = v.id AND p.position_code = 'A4-LO' AND p.is_active = 1
             WHERE v.tip_vehicul = 'camion' AND v.formula_axelor = '8x2'"
        )->fetchAll(PDO::FETCH_COLUMN);
        if ($ids === []) {
            return;
        }

        $mounted = $this->db->prepare(
            'SELECT COUNT(*)
             FROM anvelope_alocari a
             INNER JOIN vehicule_anvelope_pozitii p ON p.id = a.position_id
             WHERE p.vehicle_id = :v AND p.axle_no >= 3 AND a.data_end IS NULL'
        );
        $tires = new TireModel($this->db);
        foreach ($ids as $id) {
            $mounted->execute([':v' => (int) $id]);
            if ((int) $mounted->fetchColumn() > 0) {
                error_log('[VehicleStructureService] camion 8x2 #' . $id . ': anvelope montate pe axele 3-4, axele nu au fost rescrise.');
                continue;
            }
            $tires->syncVehiclePositions((int) $id, 'camion', '8x2');
        }
        $this->profiles = null;
    }

    public function isInstalled(): bool
    {
        if (self::$installed === true) {
            return true;
        }
        try {
            $stmt = $this->db->query(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vehicule_structura_diferente'"
            );
            self::$installed = (int) $stmt->fetchColumn() > 0;
        } catch (PDOException $exception) {
            error_log('[VehicleStructureService] ' . $exception->getMessage());
            self::$installed = false;
        }

        return self::$installed;
    }

    private function ensureSchema(): void
    {
        if (!$this->isInstalled()) {
            $options = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS mentenanta_auto_structuri (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    nume VARCHAR(120) NOT NULL,
                    vehicle_type VARCHAR(20) NOT NULL,
                    axle_count TINYINT UNSIGNED NOT NULL,
                    axe_json TEXT NOT NULL,
                    implicita_cheie VARCHAR(30) NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    UNIQUE KEY uk_structuri_nume (nume),
                    UNIQUE KEY uk_structuri_implicita (implicita_cheie),
                    KEY idx_structuri_tip (vehicle_type, axle_count)
                ) $options"
            );
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS mentenanta_auto_structuri_sisteme (
                    structura_id INT UNSIGNED NOT NULL,
                    category VARCHAR(40) NOT NULL,
                    PRIMARY KEY (structura_id, category),
                    CONSTRAINT fk_structuri_sisteme FOREIGN KEY (structura_id) REFERENCES mentenanta_auto_structuri (id) ON DELETE CASCADE
                ) $options"
            );
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS mentenanta_auto_structuri_componente (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    structura_id INT UNSIGNED NOT NULL,
                    component_key VARCHAR(80) NOT NULL,
                    axle_kinds VARCHAR(60) NULL,
                    monitoring_type VARCHAR(40) NULL,
                    interval_value VARCHAR(40) NULL,
                    warning_value VARCHAR(40) NULL,
                    critical_value VARCHAR(40) NULL,
                    lifetime_value VARCHAR(40) NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    UNIQUE KEY uk_structuri_componenta (structura_id, component_key),
                    CONSTRAINT fk_structuri_componente FOREIGN KEY (structura_id) REFERENCES mentenanta_auto_structuri (id) ON DELETE CASCADE
                ) $options"
            );
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS vehicule_structura_asignare (
                    vehicle_id INT UNSIGNED NOT NULL PRIMARY KEY,
                    structura_id INT UNSIGNED NOT NULL,
                    created_at DATETIME NOT NULL,
                    CONSTRAINT fk_structura_asignare_vehicul FOREIGN KEY (vehicle_id) REFERENCES vehicule (id) ON DELETE CASCADE,
                    CONSTRAINT fk_structura_asignare_structura FOREIGN KEY (structura_id) REFERENCES mentenanta_auto_structuri (id) ON DELETE CASCADE
                ) $options"
            );
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS vehicule_structura_diferente (
                    vehicle_id INT UNSIGNED NOT NULL,
                    tip VARCHAR(12) NOT NULL,
                    element VARCHAR(80) NOT NULL,
                    actiune VARCHAR(10) NOT NULL,
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY (vehicle_id, tip, element),
                    CONSTRAINT fk_structura_diferente_vehicul FOREIGN KEY (vehicle_id) REFERENCES vehicule (id) ON DELETE CASCADE
                ) $options"
            );
            self::$installed = true;
        }

        $column = $this->db->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mentenanta_auto_configurari' AND COLUMN_NAME = 'axle_kinds'"
        )->fetchColumn();
        if ((int) $column === 0) {
            $this->db->exec('ALTER TABLE mentenanta_auto_configurari ADD COLUMN axle_kinds VARCHAR(60) NULL AFTER category_id');
        }
    }

    private function ensureDefaults(): void
    {
        $existing = $this->db->query('SELECT implicita_cheie FROM mentenanta_auto_structuri WHERE implicita_cheie IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);
        $existing = array_flip($existing);
        $missing = [];
        foreach ($this->profiles() as $profile) {
            // Si pentru vehiculele inactive: tipul de camion trebuie sa existe cand revine in flota.
            $key = $this->defaultKey($profile['type'], $profile['axles']);
            if (!isset($existing[$key])) {
                $missing[$key] = $profile;
            }
        }
        // Tipurile pregatite din timp, chiar daca flota nu are inca un astfel de vehicul.
        foreach (self::PREPARED_LAYOUTS as [$type, $axles]) {
            $key = $this->defaultKey($type, $axles);
            if (!isset($existing[$key])) {
                $missing[$key] = ['type' => $type, 'axles' => $axles];
            }
        }
        if ($missing === []) {
            return;
        }

        $this->db->beginTransaction();
        try {
            foreach ($missing as $key => $profile) {
                $name = $this->uniqueName(self::VEHICLE_TYPES[$profile['type']] . ' ' . $profile['axles'] . ' axe');
                $id = $this->insertStructure($name, $profile['type'], $profile['axles'], $this->commonLayout($profile['type'], $profile['axles']), $key);
                $this->seedStructure($id, $profile['type']);
            }
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    private function defaultKey(string $type, int $axles): string
    {
        return $type . '|' . $axles;
    }

    private function uniqueName(string $name): string
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM mentenanta_auto_structuri WHERE nume = :n');
        $candidate = $name;
        for ($suffix = 2; $suffix < 100; $suffix++) {
            $stmt->execute([':n' => $candidate]);
            if ((int) $stmt->fetchColumn() === 0) {
                break;
            }
            $candidate = $name . ' (' . $suffix . ')';
        }

        return $candidate;
    }

    /* ------------------------------------------------------------------ date de baza */

    /** Catalogul Reparatii Auto (cheie => componenta). */
    public function catalog(): array
    {
        return $this->catalog ??= (new AutoComponentCatalogService($this->db))->components();
    }

    /** @return array<string,list<string>> sistem => chei de componenta */
    public function catalogMap(): array
    {
        return $this->catalogMap ??= VehicleComponentRegistry::catalogByCategory($this->catalog());
    }

    public static function normalizeType(string $type): string
    {
        return match ($type) {
            'camion' => 'camion',
            'cap_tractor' => 'cap_tractor',
            'semiremorca', 'semiremorca_primar', 'semiremorca_distributie' => 'semiremorca',
            default => '',
        };
    }

    /**
     * Tipul si axele fiecarui vehicul (din pozitiile reale de anvelope).
     *
     * @return array<int,array{id:int,type:string,axles:int,active:bool,layout:list<array{no:int,type:string,dual:bool}>}>
     */
    private function profiles(): array
    {
        if ($this->profiles !== null) {
            return $this->profiles;
        }
        $rows = $this->db->query(
            "SELECT v.id, v.tip_vehicul, v.status, p.axle_no, p.axle_type, MAX(p.wheel_kind = 'dual') AS dual_wheels
             FROM vehicule v
             INNER JOIN vehicule_anvelope_pozitii p ON p.vehicle_id = v.id AND p.is_active = 1
             WHERE v.nr_inmatriculare <> 'STOC-ANVELOPE'
             GROUP BY v.id, v.tip_vehicul, v.status, p.axle_no, p.axle_type
             ORDER BY v.id, p.axle_no"
        )->fetchAll(PDO::FETCH_ASSOC);

        $profiles = [];
        foreach ($rows as $row) {
            $type = self::normalizeType((string) $row['tip_vehicul']);
            if ($type === '') {
                continue;
            }
            $id = (int) $row['id'];
            $profiles[$id] ??= ['id' => $id, 'type' => $type, 'axles' => 0, 'active' => (string) $row['status'] === 'activ', 'layout' => []];
            $profiles[$id]['layout'][] = ['no' => (int) $row['axle_no'], 'type' => (string) $row['axle_type'], 'dual' => (int) $row['dual_wheels'] === 1];
            $profiles[$id]['axles'] = count($profiles[$id]['layout']);
        }

        return $this->profiles = $profiles;
    }

    public function profile(int $vehicleId): ?array
    {
        return $this->profiles()[$vehicleId] ?? null;
    }

    /** Dispunerea axelor dupa regulile din Configuratie Axe (TireModel), daca tipul are una pentru acel numar de axe. */
    private function canonicalLayout(string $type, int $axles): ?array
    {
        $formula = match ($type) {
            'semiremorca' => $axles . ' axe',
            'camion' => [2 => '4x2', 3 => '6x2', 4 => '8x2'][$axles] ?? null,
            'cap_tractor' => [2 => '4x2', 3 => '6x4', 4 => '8x4'][$axles] ?? null,
            default => null,
        };
        if ($formula === null) {
            return null;
        }
        $descriptor = (new TireModel($this->db))->describeVehicleLayout($type, $formula);
        if ((int) $descriptor['axle_count'] !== $axles) {
            return null;
        }
        $layout = [];
        foreach ($descriptor['positions'] as $position) {
            $no = (int) $position['axle_no'];
            $layout[$no] = ['no' => $no, 'type' => (string) $position['axle_type'], 'dual' => ($layout[$no]['dual'] ?? false) || $position['wheel_kind'] === 'dual'];
        }
        ksort($layout);

        return array_values($layout);
    }

    /** Dispunerea regulata a axelor; altfel cea mai des intalnita in flota sau una tipica. */
    private function commonLayout(string $type, int $axles): array
    {
        $canonical = $this->canonicalLayout($type, $axles);
        if ($canonical !== null) {
            return $canonical;
        }

        $counts = [];
        foreach ($this->profiles() as $profile) {
            if ($profile['type'] === $type && $profile['axles'] === $axles) {
                $signature = json_encode($profile['layout']);
                $counts[$signature] = ($counts[$signature] ?? 0) + 1;
            }
        }
        if ($counts !== []) {
            arsort($counts);
            return json_decode((string) array_key_first($counts), true);
        }

        $layout = [];
        for ($no = 1; $no <= $axles; $no++) {
            if ($type === 'semiremorca') {
                $layout[] = ['no' => $no, 'type' => 'trailer', 'dual' => false];
            } elseif ($no === 1) {
                $layout[] = ['no' => $no, 'type' => 'steering', 'dual' => false];
            } else {
                $layout[] = ['no' => $no, 'type' => 'traction', 'dual' => true];
            }
        }

        return $layout;
    }

    /* ------------------------------------------------------------------ configuratii generale */

    /** Toate configuratiile generale, cu numarul de vehicule care le urmeaza. */
    public function structures(): array
    {
        $rows = $this->db->query('SELECT * FROM mentenanta_auto_structuri ORDER BY vehicle_type, axle_count, implicita_cheie IS NULL, nume')->fetchAll(PDO::FETCH_ASSOC);
        $defaults = [];
        foreach ($rows as $row) {
            if ($row['implicita_cheie'] !== null) {
                $defaults[(string) $row['implicita_cheie']] = (int) $row['id'];
            }
        }
        $assigned = $this->db->query('SELECT vehicle_id, structura_id FROM vehicule_structura_asignare')->fetchAll(PDO::FETCH_KEY_PAIR);
        $usage = [];
        foreach ($this->profiles() as $profile) {
            if (!$profile['active']) {
                continue;
            }
            $id = isset($assigned[$profile['id']]) ? (int) $assigned[$profile['id']] : ($defaults[$this->defaultKey($profile['type'], $profile['axles'])] ?? 0);
            $usage[$id] = ($usage[$id] ?? 0) + 1;
        }

        return array_map(fn (array $row): array => $this->structureHeader($row) + ['vehicles' => $usage[(int) $row['id']] ?? 0], $rows);
    }

    /** @return array<int,string> axa -> tipul ei intr-o configuratie generala */
    private function layoutKinds(string $type, array $layout): array
    {
        $kinds = [];
        foreach ($layout as $axle) {
            $kinds[(int) $axle['no']] = VehicleAxleTypeService::defaultKind($type, count($layout), (int) $axle['no'], (string) $axle['type']);
        }

        return $kinds;
    }

    private function structureHeader(array $row): array
    {
        $layout = json_decode((string) $row['axe_json'], true) ?: [];

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['nume'],
            'type' => (string) $row['vehicle_type'],
            'type_label' => self::VEHICLE_TYPES[(string) $row['vehicle_type']] ?? (string) $row['vehicle_type'],
            'axles' => (int) $row['axle_count'],
            'layout' => $layout,
            'axle_kinds' => $this->layoutKinds((string) $row['vehicle_type'], $layout),
            'is_default' => $row['implicita_cheie'] !== null,
        ];
    }

    /** O configuratie generala, cu sistemele si componentele ei. */
    public function structure(int $id): ?array
    {
        if (isset($this->structureCache[$id])) {
            return $this->structureCache[$id];
        }
        $stmt = $this->db->prepare('SELECT * FROM mentenanta_auto_structuri WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $structure = $this->structureHeader($row);

        $stmt = $this->db->prepare('SELECT category FROM mentenanta_auto_structuri_sisteme WHERE structura_id = :id');
        $stmt->execute([':id' => $id]);
        $structure['systems'] = array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);

        $stmt = $this->db->prepare('SELECT * FROM mentenanta_auto_structuri_componente WHERE structura_id = :id');
        $stmt->execute([':id' => $id]);
        $structure['components'] = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $component) {
            $structure['components'][(string) $component['component_key']] = [
                'kinds' => $this->decodeKinds($component['axle_kinds']),
                'values' => $this->values($component),
            ];
        }

        return $this->structureCache[$id] = $structure;
    }

    /** Configuratia generala pe care o urmeaza un vehicul: cea asignata, altfel implicita tip + axe. */
    private function resolve(array $profile): ?array
    {
        $stmt = $this->db->prepare('SELECT structura_id FROM vehicule_structura_asignare WHERE vehicle_id = :v');
        $stmt->execute([':v' => $profile['id']]);
        $assigned = (int) ($stmt->fetchColumn() ?: 0);
        if ($assigned > 0) {
            $structure = $this->structure($assigned);
            if ($structure !== null) {
                return $structure + ['assigned' => true];
            }
        }
        $id = $this->defaultStructureId($profile['type'], $profile['axles']);

        return $id > 0 ? ($this->structure($id) ?? null) : null;
    }

    private function defaultStructureId(string $type, int $axles): int
    {
        $stmt = $this->db->prepare('SELECT id FROM mentenanta_auto_structuri WHERE implicita_cheie = :k');
        $stmt->execute([':k' => $this->defaultKey($type, $axles)]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function values(array $row): array
    {
        return [
            'type' => (string) ($row['monitoring_type'] ?? ''),
            'interval' => trim((string) ($row['interval_value'] ?? '')),
            'warning' => trim((string) ($row['warning_value'] ?? '')),
            'critical' => trim((string) ($row['critical_value'] ?? '')),
            'lifetime' => trim((string) ($row['lifetime_value'] ?? '')),
        ];
    }

    /** @return list<string>|null tipurile de axa pe care sta componenta; null = toate */
    private function decodeKinds(mixed $value): ?array
    {
        $value = trim((string) $value);
        return $value === '' ? null : explode(',', $value);
    }

    private function differences(int $vehicleId): array
    {
        $stmt = $this->db->prepare('SELECT tip, element, actiune FROM vehicule_structura_diferente WHERE vehicle_id = :v');
        $stmt->execute([':v' => $vehicleId]);
        $result = ['sistem' => [], 'componenta' => []];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(string) $row['tip']][(string) $row['element']] = (string) $row['actiune'];
        }

        return $result;
    }

    private function vehicleRows(int $vehicleId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM mentenanta_auto_configurari WHERE vehicle_id = :v');
        $stmt->execute([':v' => $vehicleId]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[(string) $row['component_key']] = $row;
        }

        return $rows;
    }

    /* ------------------------------------------------------------------ rezultatul pe vehicul */

    /**
     * Ce are un vehicul: configuratia generala + diferentele lui. Un sistem eliminat ramane eliminat
     * chiar daca vehiculul are valori proprii pe el.
     *
     * @return array{structure:array,systems:array<string,true>,components:array<string,array>,differences:array}|null
     */
    public function effective(int $vehicleId): ?array
    {
        if (!$this->isInstalled()) {
            return null;
        }
        $profile = $this->profile($vehicleId);
        $structure = $profile !== null ? $this->resolve($profile) : null;
        if ($structure === null) {
            return null;
        }

        $differences = $this->differences($vehicleId);
        $components = [];
        foreach ($structure['components'] as $key => $component) {
            if (($differences['componenta'][$key] ?? '') !== 'eliminat') {
                $components[$key] = $component + ['origin' => 'general'];
            }
        }
        foreach ($differences['componenta'] as $key => $action) {
            if ($action === 'adaugat' && !isset($components[$key])) {
                $components[$key] = ['kinds' => null, 'values' => $this->values([]), 'origin' => 'added'];
            }
        }
        foreach ($this->vehicleRows($vehicleId) as $key => $row) {
            if (($differences['componenta'][$key] ?? '') === 'eliminat') {
                continue;
            }
            $components[$key] = [
                'kinds' => $this->decodeKinds($row['axle_kinds'] ?? null),
                'values' => $this->values($row),
                'origin' => isset($structure['components'][$key]) ? 'custom' : 'added',
            ];
        }

        $systems = $structure['systems'];
        foreach ($differences['sistem'] as $category => $action) {
            if ($action === 'adaugat') {
                $systems[$category] = true;
            } else {
                unset($systems[$category]);
            }
        }

        // O componenta proprie vehiculului isi aduce sistemul, daca nu e deja vizibila in altul.
        $role = VehicleComponentRegistry::roleForVehicleType($profile['type']);
        foreach ($components as $key => $component) {
            if ($component['origin'] === 'general') {
                continue;
            }
            $categories = $this->categoriesOf($key, $role);
            $visible = array_filter($categories, static fn (string $category): bool => isset($systems[$category]));
            if ($visible === [] && $categories !== [] && !isset($differences['sistem'][$categories[0]])) {
                $systems[$categories[0]] = true;
            }
        }

        return [
            'structure' => $structure,
            'systems' => $systems,
            'components' => $components,
            'differences' => $differences,
            'axle_kinds' => VehicleAxleTypeService::resolveKinds($this->db, $vehicleId, $profile['type'], $profile['layout']),
        ];
    }

    /** @return list<string> sistemele (permise rolului) in care apare componenta */
    private function categoriesOf(string $componentKey, string $role): array
    {
        $result = [];
        foreach ($this->catalogMap() as $category => $keys) {
            if (in_array($componentKey, $keys, true) && $this->categoryAllowed($category, $role)) {
                $result[] = $category;
            }
        }

        return $result;
    }

    private function categoryAllowed(string $category, string $role): bool
    {
        return $category !== 'anvelope'
            && isset(VehicleComponentRegistry::CATEGORIES[$category])
            && in_array($role, VehicleComponentRegistry::CATEGORIES[$category]['roles'], true);
    }

    /**
     * Ce deseneaza schema: sistemele si componentele (cu axele lor) unui vehicul.
     *
     * @return array{systems:array<string,true>,components:array<string,?array>}|null null = fara configuratie
     */
    public function schemeFilter(int $vehicleId): ?array
    {
        $effective = $this->effective($vehicleId);
        return $effective === null ? null : $this->filterFrom($effective['systems'], $effective['components'], $effective['axle_kinds']);
    }

    /** @return array<int,string> axa -> tip, pentru un vehicul real */
    public function axleKinds(int $vehicleId): array
    {
        $profile = $this->isInstalled() ? $this->profile($vehicleId) : null;
        return $profile === null ? [] : VehicleAxleTypeService::resolveKinds($this->db, $vehicleId, $profile['type'], $profile['layout']);
    }

    /** Tipurile de axa (coloanele tabelului de piese); pe un vehicul doar cele pe care le are. */
    public function axleColumns(string $vehicleType, array $axleKinds, bool $onlyPresent): array
    {
        $columns = [];
        foreach (VehicleAxleTypeService::kindsFor($vehicleType) as $kind) {
            $axles = array_keys($axleKinds, $kind, true);
            if ($axles !== [] || !$onlyPresent) {
                $columns[] = ['kind' => $kind, 'label' => VehicleAxleTypeService::KINDS[$kind], 'axles' => $axles];
            }
        }

        return $columns;
    }

    /** Tipurile de axa ale fiecarei componente se traduc in axele concrete ale vehiculului. */
    private function filterFrom(array $systems, array $components, array $axleKinds): array
    {
        return ['systems' => $systems, 'components' => array_map(
            static fn (array $component): ?array => $component['kinds'] === null
                ? null
                : array_keys(array_filter($axleKinds, static fn (string $kind): bool => in_array($kind, $component['kinds'], true))),
            $components
        )];
    }

    /**
     * Valorile configuratiei generale, peste randurile proprii ale vehiculului, pentru cititorii din
     * alte module (uzura, interventii, WhatsApp). Doar componentele cu interval setat conteaza ca
     * „configurate”; `inherited` spune ca randul nu e al vehiculului.
     */
    public function mergeInheritedConfigs(int $vehicleId, array $configs): array
    {
        try {
            $effective = $this->effective($vehicleId);
        } catch (Throwable $exception) {
            error_log('[VehicleStructureService] ' . $exception->getMessage());
            return $configs;
        }
        if ($effective === null) {
            return $configs;
        }
        foreach ($effective['differences']['componenta'] as $key => $action) {
            if ($action === 'eliminat') {
                unset($configs[$key]);
            }
        }
        foreach ($effective['components'] as $key => $component) {
            if ($component['origin'] !== 'general' || $component['values']['interval'] === '' || isset($configs[$key])) {
                continue;
            }
            $configs[$key] = $this->inheritedRow($vehicleId, $key, $component['values']);
        }

        return $configs;
    }

    private function inheritedRow(int $vehicleId, string $key, array $values): array
    {
        $type = $values['type'] !== '' ? $values['type'] : 'Kilometri';
        $meta = self::MONITORING_TYPES[$type] ?? self::MONITORING_TYPES['Kilometri'];

        return [
            'id' => 0,
            'vehicle_id' => $vehicleId,
            'component_key' => $key,
            'inherited' => true,
            'component_code' => '',
            'description' => '',
            'monitoring_type' => $type,
            'monitoring_methods' => $meta['methods'],
            'interval_value' => $values['interval'],
            'warning_value' => $values['warning'],
            'critical_value' => $values['critical'],
            'lifetime_value' => $values['lifetime'],
            'unit' => $meta['unit'],
            'repairable' => 1,
            'repair_resets_lifetime' => 0,
            'requires_calibration' => 0,
            'notes' => '',
            'created_at' => '',
        ];
    }

    /* ------------------------------------------------------------------ date pentru pagina */

    /** Sectiunile paginii: sistemele prezente, in ordinea registrului, si ce se mai poate adauga. */
    public function sections(string $vehicleType, array $systems, array $components): array
    {
        $role = VehicleComponentRegistry::roleForVehicleType($vehicleType);
        $catalog = $this->catalog();
        $sections = [];
        $addable = [];
        foreach ($this->catalogMap() as $category => $keys) {
            if (!$this->categoryAllowed($category, $role)) {
                continue;
            }
            $definition = VehicleComponentRegistry::CATEGORIES[$category];
            $meta = ['key' => $category, 'label' => $definition['label'], 'icon' => $definition['icon'], 'color' => $definition['color']];
            if (!isset($systems[$category])) {
                $addable[] = $meta;
                continue;
            }
            $rows = [];
            $available = [];
            foreach ($keys as $key) {
                if (!isset($catalog[$key])) {
                    continue;
                }
                if (!isset($components[$key])) {
                    $available[] = ['key' => $key, 'name' => (string) $catalog[$key]['name']];
                    continue;
                }
                $component = $components[$key];
                $type = $component['values']['type'] !== '' ? $component['values']['type'] : 'Kilometri';
                $rows[] = [
                    'key' => $key,
                    'name' => (string) $catalog[$key]['name'],
                    'code' => (string) $catalog[$key]['code'],
                    'kinds' => $component['kinds'],
                    'origin' => $component['origin'],
                    'monitoring' => $type,
                    'unit' => (self::MONITORING_TYPES[$type] ?? self::MONITORING_TYPES['Kilometri'])['unit'],
                ] + $component['values'];
            }
            $sections[] = $meta + ['axle_scoped' => in_array($category, self::AXLE_SCOPED, true), 'components' => $rows, 'available' => $available];
        }

        return ['sections' => $sections, 'addable' => $addable];
    }

    /** Ce a eliminat vehiculul fata de configuratia generala (pentru Restaurare). */
    public function removedItems(array $effective): array
    {
        $catalog = $this->catalog();
        $result = [];
        foreach ($effective['differences']['sistem'] as $category => $action) {
            if ($action === 'eliminat') {
                $result[] = ['tip' => 'sistem', 'element' => $category, 'label' => VehicleComponentRegistry::label($category), 'kind' => 'Sistem'];
            }
        }
        foreach ($effective['differences']['componenta'] as $key => $action) {
            if ($action === 'eliminat' && isset($catalog[$key])) {
                $result[] = ['tip' => 'componenta', 'element' => $key, 'label' => (string) $catalog[$key]['name'], 'kind' => 'Componentă'];
            }
        }

        return $result;
    }

    /**
     * Unitatea desenata pentru o configuratie generala: acelasi format ca TireModel::fleetUnitClientData,
     * fara vehicul real in spate (vehicle_id 0, fara anvelope).
     */
    public function templateUnit(array $structure): array
    {
        $positions = [];
        $traction = 0;
        foreach ($structure['layout'] as $axle) {
            $no = (int) $axle['no'];
            $dual = !empty($axle['dual']);
            $traction += $axle['type'] === 'traction' ? 1 : 0;
            foreach ($dual ? ['LO' => 'Stânga exterior', 'LI' => 'Stânga interior', 'RI' => 'Dreapta interior', 'RO' => 'Dreapta exterior'] : ['L' => 'Stânga', 'R' => 'Dreapta'] as $side => $sideLabel) {
                $positions[] = [
                    'id' => -(count($positions) + 1),
                    'code' => 'A' . $no . '-' . (['LO' => 'L-OUT', 'LI' => 'L-IN', 'RI' => 'R-IN', 'RO' => 'R-OUT'][$side] ?? $side),
                    'axle' => $no,
                    'axle_type' => (string) $axle['type'],
                    'axle_label' => self::AXLE_LABELS[(string) $axle['type']] ?? 'Axă',
                    'side' => $side,
                    'side_label' => $sideLabel,
                    'dual' => $dual,
                    'status' => 'empty',
                    'tire' => null,
                ];
            }
        }
        $member = [
            'vehicle_id' => 0,
            'role' => VehicleComponentRegistry::roleForVehicleType($structure['type']),
            'role_label' => $structure['type_label'],
            'plate' => $structure['name'],
            'make' => '',
            'year' => null,
            'mma' => null,
            'type' => $structure['type'],
            'layout' => $structure['type'] === 'semiremorca' ? $structure['axles'] . ' axe' : ($structure['axles'] * 2) . 'x' . (max(1, $traction) * 2),
            'axles' => $structure['axles'],
            'configured' => true,
            'positions' => $positions,
            'axle_attrs' => (object) [],
            'axle_kinds' => (object) $structure['axle_kinds'],
        ];
        $filter = $this->filterFrom($structure['systems'], $structure['components'], $structure['axle_kinds']);
        $member['slots'] = array_map(
            static fn (array $slot): array => $slot + ['state' => null],
            VehicleComponentRegistry::memberSlots($member, [], $this->catalogMap(), $filter)
        );

        return ['key' => 'structura-' . $structure['id'], 'kind' => $structure['type'], 'template' => true, 'members' => [$member]];
    }

    /* ------------------------------------------------------------------ modificari */

    /**
     * Aplica o modificare din pagina. Tinta e o configuratie generala (structura) sau un vehicul
     * (vehicle_id); pe vehicul se scriu doar diferentele.
     *
     * @return array{structura?:int,vehicle_id?:int,anchor?:string,open?:string,message:string}
     */
    public function apply(array $post): array
    {
        $op = (string) ($post['op'] ?? '');
        $this->db->beginTransaction();
        try {
            $result = match ($op) {
                'create' => $this->opCreate($post),
                'rename' => $this->opRename($post),
                'delete' => $this->opDelete($post),
                'set_default' => $this->opSetDefault($post),
                'assign' => $this->opAssign($post),
                'system_add', 'system_remove', 'component_add', 'component_remove', 'component_save', 'component_reset', 'restore', 'axle_kinds_save' => $this->opItem($op, $post),
                default => throw new InvalidArgumentException('Operație necunoscută.'),
            };
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        return $result;
    }

    private function requireStructure(array $post): array
    {
        $structure = $this->structure((int) ($post['structura'] ?? 0));
        if ($structure === null) {
            throw new InvalidArgumentException('Configurația nu mai există.');
        }

        return $structure;
    }

    private function cleanName(array $post): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) ($post['name'] ?? '')) ?? '');
        if ($name === '' || mb_strlen($name, 'UTF-8') > 120) {
            throw new InvalidArgumentException('Numele configurației este obligatoriu (maximum 120 de caractere).');
        }

        return $name;
    }

    private function assertNameFree(string $name, int $exceptId = 0): void
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM mentenanta_auto_structuri WHERE nume = :n AND id <> :id');
        $stmt->execute([':n' => $name, ':id' => $exceptId]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new InvalidArgumentException('Există deja o configurație cu acest nume.');
        }
    }

    private function insertStructure(string $name, string $type, int $axles, array $layout, ?string $defaultKey): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->prepare(
            'INSERT INTO mentenanta_auto_structuri (nume, vehicle_type, axle_count, axe_json, implicita_cheie, created_at, updated_at)
             VALUES (:n, :t, :a, :j, :k, :c, :u)'
        )->execute([':n' => $name, ':t' => $type, ':a' => $axles, ':j' => json_encode($layout), ':k' => $defaultKey, ':c' => $now, ':u' => $now]);

        return (int) $this->db->lastInsertId();
    }

    /** O configuratie noua porneste cu ce arata azi schema pentru acel tip de vehicul. */
    private function seedStructure(int $id, string $type): void
    {
        $role = VehicleComponentRegistry::roleForVehicleType($type);
        $catalog = $this->catalog();
        foreach ($this->catalogMap() as $category => $keys) {
            if (!$this->categoryAllowed($category, $role) || !empty(VehicleComponentRegistry::CATEGORIES[$category]['optional'])) {
                continue;
            }
            $this->insertSystem($id, $category);
            foreach ($keys as $key) {
                if (isset($catalog[$key])) {
                    $this->insertComponent($id, $key);
                }
            }
        }
    }

    private function insertSystem(int $structureId, string $category): void
    {
        $this->db->prepare('INSERT IGNORE INTO mentenanta_auto_structuri_sisteme (structura_id, category) VALUES (:s, :c)')
            ->execute([':s' => $structureId, ':c' => $category]);
    }

    private function insertComponent(int $structureId, string $key): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->prepare('INSERT IGNORE INTO mentenanta_auto_structuri_componente (structura_id, component_key, created_at, updated_at) VALUES (:s, :k, :c, :u)')
            ->execute([':s' => $structureId, ':k' => $key, ':c' => $now, ':u' => $now]);
    }

    private function opCreate(array $post): array
    {
        $name = $this->cleanName($post);
        $this->assertNameFree($name);
        $type = (string) ($post['vehicle_type'] ?? '');
        $axles = (int) ($post['axle_count'] ?? 0);
        if (!isset(self::VEHICLE_TYPES[$type]) || $axles < 1 || $axles > self::MAX_AXLES) {
            throw new InvalidArgumentException('Alege tipul de vehicul și numărul de axe (1-' . self::MAX_AXLES . ').');
        }
        $key = $this->defaultKey($type, $axles);
        $id = $this->insertStructure($name, $type, $axles, $this->commonLayout($type, $axles), $this->defaultStructureId($type, $axles) > 0 ? null : $key);

        $source = (int) ($post['copy_from'] ?? 0) > 0 ? $this->structure((int) $post['copy_from']) : null;
        if ($source === null) {
            $this->seedStructure($id, $type);
        } else {
            $this->copyInto($id, $type, $source);
        }

        return ['structura' => $id, 'message' => 'Configurația „' . $name . '” a fost creată.'];
    }

    private function copyInto(int $id, string $type, array $source): void
    {
        $role = VehicleComponentRegistry::roleForVehicleType($type);
        foreach (array_keys($source['systems']) as $category) {
            if ($this->categoryAllowed($category, $role)) {
                $this->insertSystem($id, $category);
            }
        }
        foreach ($source['components'] as $key => $component) {
            if ($this->categoriesOf($key, $role) === []) {
                continue;
            }
            $this->insertComponent($id, $key);
            $this->updateComponent($id, $key, $component['values'], $source['type'] === $type ? $component['kinds'] : null);
        }
    }

    private function updateComponent(int $structureId, string $key, array $values, ?array $kinds): void
    {
        $this->db->prepare(
            'UPDATE mentenanta_auto_structuri_componente
             SET axle_kinds = :kinds, monitoring_type = :mt, interval_value = :iv, warning_value = :wv, critical_value = :cv, lifetime_value = :lv, updated_at = :u
             WHERE structura_id = :s AND component_key = :k'
        )->execute([
            ':kinds' => $kinds === null ? null : implode(',', $kinds),
            ':mt' => $values['type'] !== '' ? $values['type'] : null,
            ':iv' => $values['interval'] !== '' ? $values['interval'] : null,
            ':wv' => $values['warning'] !== '' ? $values['warning'] : null,
            ':cv' => $values['critical'] !== '' ? $values['critical'] : null,
            ':lv' => $values['lifetime'] !== '' ? $values['lifetime'] : null,
            ':u' => date('Y-m-d H:i:s'),
            ':s' => $structureId,
            ':k' => $key,
        ]);
    }

    private function opRename(array $post): array
    {
        $structure = $this->requireStructure($post);
        $name = $this->cleanName($post);
        $this->assertNameFree($name, $structure['id']);
        $this->db->prepare('UPDATE mentenanta_auto_structuri SET nume = :n, updated_at = :u WHERE id = :id')
            ->execute([':n' => $name, ':u' => date('Y-m-d H:i:s'), ':id' => $structure['id']]);

        return ['structura' => $structure['id'], 'message' => 'Configurația a fost redenumită.'];
    }

    private function opDelete(array $post): array
    {
        $structure = $this->requireStructure($post);
        if ($structure['is_default']) {
            throw new InvalidArgumentException('Configurația implicită nu se poate șterge. Setează întâi alta drept implicită pentru ' . $structure['type_label'] . ' ' . $structure['axles'] . ' axe.');
        }
        $this->db->prepare('DELETE FROM mentenanta_auto_structuri WHERE id = :id')->execute([':id' => $structure['id']]);
        $id = $this->defaultStructureId($structure['type'], $structure['axles']);

        return ($id > 0 ? ['structura' => $id] : []) + ['message' => 'Configurația „' . $structure['name'] . '” a fost ștearsă. Vehiculele asignate au revenit la cea implicită.'];
    }

    private function opSetDefault(array $post): array
    {
        $structure = $this->requireStructure($post);
        $key = $this->defaultKey($structure['type'], $structure['axles']);
        $this->db->prepare('UPDATE mentenanta_auto_structuri SET implicita_cheie = NULL WHERE implicita_cheie = :k')->execute([':k' => $key]);
        $this->db->prepare('UPDATE mentenanta_auto_structuri SET implicita_cheie = :k WHERE id = :id')->execute([':k' => $key, ':id' => $structure['id']]);

        return ['structura' => $structure['id'], 'message' => 'Vehiculele ' . $structure['type_label'] . ' ' . $structure['axles'] . ' axe urmează acum „' . $structure['name'] . '”.'];
    }

    private function opAssign(array $post): array
    {
        $vehicleId = (int) ($post['vehicle_id'] ?? 0);
        $profile = $this->profile($vehicleId);
        if ($profile === null) {
            throw new InvalidArgumentException('Vehiculul nu are axe configurate.');
        }
        $target = (int) ($post['target'] ?? 0);
        if ($target === 0 || $target === $this->defaultStructureId($profile['type'], $profile['axles'])) {
            $this->db->prepare('DELETE FROM vehicule_structura_asignare WHERE vehicle_id = :v')->execute([':v' => $vehicleId]);
            return ['vehicle_id' => $vehicleId, 'message' => 'Vehiculul urmează configurația implicită.'];
        }
        $structure = $this->structure($target);
        if ($structure === null || $structure['type'] !== $profile['type'] || $structure['axles'] !== $profile['axles']) {
            throw new InvalidArgumentException('Configurația aleasă nu se potrivește cu tipul și axele vehiculului.');
        }
        $this->db->prepare(
            'INSERT INTO vehicule_structura_asignare (vehicle_id, structura_id, created_at) VALUES (:v, :s, :c)
             ON DUPLICATE KEY UPDATE structura_id = VALUES(structura_id)'
        )->execute([':v' => $vehicleId, ':s' => $target, ':c' => date('Y-m-d H:i:s')]);

        return ['vehicle_id' => $vehicleId, 'message' => 'Vehiculul urmează configurația „' . $structure['name'] . '”.'];
    }

    /** Operatiile pe sisteme si componente, pe o configuratie generala sau pe un vehicul. */
    private function opItem(string $op, array $post): array
    {
        $vehicleId = (int) ($post['vehicle_id'] ?? 0);
        $profile = $vehicleId > 0 ? $this->profile($vehicleId) : null;
        if ($vehicleId > 0 && $profile === null) {
            throw new InvalidArgumentException('Vehiculul nu are axe configurate.');
        }
        $structure = $profile !== null ? $this->resolve($profile) : $this->requireStructure($post);
        if ($structure === null) {
            throw new InvalidArgumentException('Vehiculul nu are o configurație generală.');
        }
        $scope = $profile !== null ? ['vehicle_id' => $vehicleId] : ['structura' => $structure['id']];
        $type = $profile['type'] ?? $structure['type'];
        $role = VehicleComponentRegistry::roleForVehicleType($type);
        $category = (string) ($post['category'] ?? '');
        $key = (string) ($post['component_key'] ?? '');
        if (in_array($op, ['system_add', 'system_remove'], true) && !$this->categoryAllowed($category, $role)) {
            throw new InvalidArgumentException('Sistemul nu se aplică acestui tip de vehicul.');
        }
        if (in_array($op, ['component_remove', 'component_save', 'component_reset'], true) && !isset($this->catalog()[$key])) {
            throw new InvalidArgumentException('Componenta nu există în catalog.');
        }
        $anchor = $category !== '' ? ['anchor' => 'sistem-' . $category] : [];

        switch ($op) {
            case 'system_add':
                if ($profile === null) {
                    $this->insertSystem($structure['id'], $category);
                } else {
                    $this->addDifference($vehicleId, 'sistem', $category, isset($structure['systems'][$category]));
                }
                return $scope + $anchor + ['open' => $category, 'message' => 'Sistemul a fost adăugat. Alege componentele lui.'];

            case 'system_remove':
                if ($profile === null) {
                    $this->removeSystemFromStructure($structure, $category, $role);
                } else {
                    $this->removeDifference($vehicleId, 'sistem', $category, isset($structure['systems'][$category]));
                }
                return $scope + ['message' => 'Sistemul a fost scos de pe schemă.'];

            case 'component_add':
                $added = 0;
                foreach ((array) ($post['keys'] ?? []) as $candidate) {
                    $candidate = (string) $candidate;
                    if (!isset($this->catalog()[$candidate]) || $this->categoriesOf($candidate, $role) === []) {
                        continue;
                    }
                    if ($profile === null) {
                        $this->insertComponent($structure['id'], $candidate);
                    } else {
                        $this->addDifference($vehicleId, 'componenta', $candidate, isset($structure['components'][$candidate]));
                    }
                    $added++;
                }
                if ($added === 0) {
                    throw new InvalidArgumentException('Bifează cel puțin o componentă.');
                }
                return $scope + $anchor + ['message' => $added === 1 ? 'Componenta a fost adăugată.' : $added . ' componente au fost adăugate.'];

            case 'component_remove':
                if ($profile === null) {
                    $this->db->prepare('DELETE FROM mentenanta_auto_structuri_componente WHERE structura_id = :s AND component_key = :k')
                        ->execute([':s' => $structure['id'], ':k' => $key]);
                } else {
                    $this->removeDifference($vehicleId, 'componenta', $key, isset($structure['components'][$key]) || isset($this->vehicleRows($vehicleId)[$key]));
                }
                return $scope + $anchor + ['message' => 'Componenta a fost eliminată.'];

            case 'component_save':
                $values = $this->readValues($post);
                if ($profile === null) {
                    $current = $structure['components'][$key] ?? null;
                    if ($current === null) {
                        throw new InvalidArgumentException('Componenta nu este în această configurație.');
                    }
                    $this->updateComponent($structure['id'], $key, $values, $current['kinds']);
                } else {
                    $this->saveVehicleValues($vehicleId, $type, $key, $values, [], $structure);
                }
                return $scope + $anchor + ['message' => 'Valorile componentei au fost salvate.'];

            case 'axle_kinds_save':
                $changed = $this->saveAxleKindMatrix($post, $profile, $structure, $vehicleId, $type);
                return $scope + ['anchor' => 'piese-pe-tip-axa', 'message' => $changed === 0 ? 'Nu a fost nimic de modificat.' : 'Piesele pe tipuri de axă au fost salvate (' . $changed . ' componente).'];

            case 'component_reset':
                if (!isset($structure['components'][$key])) {
                    throw new InvalidArgumentException('Componenta nu există în configurația generală.');
                }
                $this->db->prepare('DELETE FROM mentenanta_auto_configurari WHERE vehicle_id = :v AND component_key = :k')
                    ->execute([':v' => $vehicleId, ':k' => $key]);
                return $scope + $anchor + ['message' => 'Componenta urmează din nou configurația generală.'];

            case 'restore':
                $this->db->prepare("DELETE FROM vehicule_structura_diferente WHERE vehicle_id = :v AND tip = :t AND element = :e AND actiune = 'eliminat'")
                    ->execute([':v' => $vehicleId, ':t' => (string) ($post['tip'] ?? ''), ':e' => (string) ($post['element'] ?? '')]);
                return $scope + ['message' => 'Elementul a fost restaurat.'];
        }

        throw new InvalidArgumentException('Operație necunoscută.');
    }

    /**
     * Tabelul „Piese pe tip de axa”. Pe un vehicul se vad doar tipurile de axa pe care le are; ce e
     * setat pentru celelalte tipuri ramane neatins.
     */
    private function saveAxleKindMatrix(array $post, ?array $profile, array $structure, int $vehicleId, string $type): int
    {
        $allowed = VehicleAxleTypeService::kindsFor($type);
        if ($profile !== null) {
            $effective = $this->effective($vehicleId);
            $components = $effective['components'];
            $columns = array_values(array_intersect($allowed, array_values($effective['axle_kinds'])));
        } else {
            $components = $structure['components'];
            $columns = $allowed;
        }

        $changed = 0;
        $matrix = is_array($post['matrix'] ?? null) ? $post['matrix'] : [];
        foreach ((array) ($post['matrix_keys'] ?? []) as $key) {
            $key = (string) $key;
            $component = $components[$key] ?? null;
            if ($component === null || !isset($this->catalog()[$key])) {
                continue;
            }
            $chosen = array_intersect($columns, array_map('strval', (array) ($matrix[$key] ?? [])));
            if ($chosen === []) {
                throw new InvalidArgumentException((string) $this->catalog()[$key]['name'] . ': bifează cel puțin un tip de axă sau elimină componenta.');
            }
            $kept = array_diff($component['kinds'] ?? $allowed, $columns);
            $final = array_values(array_intersect($allowed, array_merge($chosen, $kept)));
            $final = count($final) === count($allowed) ? null : $final;
            if ($final === $component['kinds']) {
                continue;
            }
            if ($profile === null) {
                $this->updateComponent($structure['id'], $key, $component['values'], $final);
            } else {
                $values = $component['values'];
                $values['type'] = isset(self::MONITORING_TYPES[$values['type']]) ? $values['type'] : 'Kilometri';
                $this->saveVehicleValues($vehicleId, $type, $key, $values, ['kinds' => $final], $structure);
            }
            $changed++;
        }

        return $changed;
    }

    /** Adaugare pe vehicul: anuleaza o eliminare sau noteaza ca element in plus fata de general. */
    private function addDifference(int $vehicleId, string $tip, string $element, bool $inStructure): void
    {
        if ($inStructure) {
            $this->db->prepare('DELETE FROM vehicule_structura_diferente WHERE vehicle_id = :v AND tip = :t AND element = :e')
                ->execute([':v' => $vehicleId, ':t' => $tip, ':e' => $element]);
            return;
        }
        $this->writeDifference($vehicleId, $tip, $element, 'adaugat');
    }

    /** Eliminare pe vehicul: valorile lui raman salvate, ca o restaurare sa le aduca inapoi. */
    private function removeDifference(int $vehicleId, string $tip, string $element, bool $keepsTrace): void
    {
        if ($keepsTrace) {
            $this->writeDifference($vehicleId, $tip, $element, 'eliminat');
            return;
        }
        $this->db->prepare('DELETE FROM vehicule_structura_diferente WHERE vehicle_id = :v AND tip = :t AND element = :e')
            ->execute([':v' => $vehicleId, ':t' => $tip, ':e' => $element]);
    }

    private function writeDifference(int $vehicleId, string $tip, string $element, string $action): void
    {
        $this->db->prepare(
            'INSERT INTO vehicule_structura_diferente (vehicle_id, tip, element, actiune, created_at) VALUES (:v, :t, :e, :a, :c)
             ON DUPLICATE KEY UPDATE actiune = VALUES(actiune), created_at = VALUES(created_at)'
        )->execute([':v' => $vehicleId, ':t' => $tip, ':e' => $element, ':a' => $action, ':c' => date('Y-m-d H:i:s')]);
    }

    /** Scoate sistemul si componentele care nu mai apar in niciun alt sistem al configuratiei. */
    private function removeSystemFromStructure(array $structure, string $category, string $role): void
    {
        $this->db->prepare('DELETE FROM mentenanta_auto_structuri_sisteme WHERE structura_id = :s AND category = :c')
            ->execute([':s' => $structure['id'], ':c' => $category]);
        $delete = $this->db->prepare('DELETE FROM mentenanta_auto_structuri_componente WHERE structura_id = :s AND component_key = :k');
        foreach ($this->catalogMap()[$category] ?? [] as $key) {
            $others = array_filter(
                $this->categoriesOf($key, $role),
                static fn (string $other): bool => $other !== $category && isset($structure['systems'][$other])
            );
            if ($others === []) {
                $delete->execute([':s' => $structure['id'], ':k' => $key]);
            }
        }
    }

    /**
     * Valorile trimise din editorul unei componente.
     */
    private function readValues(array $post): array
    {
        $type = (string) ($post['monitoring_type'] ?? 'Kilometri');
        if (!isset(self::MONITORING_TYPES[$type])) {
            throw new InvalidArgumentException('Tip de monitorizare necunoscut.');
        }
        $values = ['type' => $type];
        $numbers = [];
        foreach (['interval' => 'Interval', 'warning' => 'Atenționare', 'critical' => 'Critic', 'lifetime' => 'Durată de viață'] as $field => $label) {
            $value = trim((string) ($post[$field] ?? ''));
            if ($value !== '' && preg_match('/^\d[\d.,\s]*$/', $value) !== 1) {
                throw new InvalidArgumentException($label . ': introdu un număr.');
            }
            $values[$field] = $value;
            $numbers[$field] = $value === '' ? null : (int) preg_replace('/\D/', '', $value);
        }
        if ($numbers['warning'] !== null && $numbers['critical'] !== null && $numbers['warning'] > $numbers['critical']) {
            throw new InvalidArgumentException('Pragul de atenționare nu poate depăși pragul critic.');
        }
        if ($numbers['critical'] !== null && $numbers['interval'] !== null && $numbers['critical'] > $numbers['interval']) {
            throw new InvalidArgumentException('Pragul critic nu poate depăși intervalul.');
        }

        return $values;
    }

    /** Valori proprii pe vehicul; daca sunt identice cu cele generale nu se creeaza nicio diferenta. */
    private function saveVehicleValues(int $vehicleId, string $vehicleType, string $key, array $values, array $newKinds, array $structure): void
    {
        $row = $this->vehicleRows($vehicleId)[$key] ?? null;
        $general = $structure['components'][$key] ?? null;
        $currentKinds = $row !== null ? $this->decodeKinds($row['axle_kinds'] ?? null) : ($general['kinds'] ?? null);
        $finalKinds = array_key_exists('kinds', $newKinds) ? $newKinds['kinds'] : $currentKinds;
        if ($row === null && $general !== null && $general['kinds'] === $finalKinds && $this->sameValues($general['values'], $values)) {
            return;
        }

        $catalog = $this->catalog()[$key];
        $categoryId = (int) $catalog['category_id'];
        $subcategory = AutoComponentCatalogService::subcategoryFor($categoryId) ?? 'sasiu';
        $meta = self::MONITORING_TYPES[$values['type']];
        $now = date('Y-m-d H:i:s');
        $this->db->prepare(
            'INSERT INTO mentenanta_auto_configurari
                (vehicle_id, vehicle_type, primary_category, subcategory, category_id, axle_kinds, component_key, component_name, component_code,
                 monitoring_type, monitoring_methods, interval_value, warning_value, critical_value, lifetime_value, unit, created_at, updated_at)
             VALUES
                (:vehicle, :type, :primary_cat, :sub, :cat, :axles, :comp_key, :comp_name, :code, :mt, :methods, :iv, :wv, :cv, :lv, :unit, :created, :updated)
             ON DUPLICATE KEY UPDATE
                axle_kinds = VALUES(axle_kinds), monitoring_type = VALUES(monitoring_type), monitoring_methods = VALUES(monitoring_methods),
                interval_value = VALUES(interval_value), warning_value = VALUES(warning_value), critical_value = VALUES(critical_value),
                lifetime_value = VALUES(lifetime_value), unit = VALUES(unit), updated_at = VALUES(updated_at)'
        )->execute([
            ':vehicle' => $vehicleId,
            ':type' => $vehicleType,
            ':primary_cat' => $subcategory === 'sasiu' ? 'sasiu' : 'rezervor',
            ':sub' => $subcategory,
            ':cat' => $categoryId,
            ':axles' => $finalKinds === null ? null : implode(',', $finalKinds),
            ':comp_key' => $key,
            ':comp_name' => (string) $catalog['name'],
            ':code' => (string) $catalog['code'],
            ':mt' => $values['type'],
            ':methods' => json_encode($meta['methods'], JSON_UNESCAPED_UNICODE),
            ':iv' => $values['interval'],
            ':wv' => $values['warning'],
            ':cv' => $values['critical'],
            ':lv' => $values['lifetime'],
            ':unit' => $meta['unit'],
            ':created' => $now,
            ':updated' => $now,
        ]);
    }

    private function sameValues(array $general, array $values): bool
    {
        $generalType = $general['type'] !== '' ? $general['type'] : 'Kilometri';
        return $generalType === $values['type']
            && $general['interval'] === $values['interval']
            && $general['warning'] === $values['warning']
            && $general['critical'] === $values['critical']
            && $general['lifetime'] === $values['lifetime'];
    }
}
