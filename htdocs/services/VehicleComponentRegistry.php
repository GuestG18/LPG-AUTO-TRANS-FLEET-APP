<?php
declare(strict_types=1);

/**
 * Registrul de componente al exploratorului din Stoc Anvelope (vederea pe vehicule).
 *
 * - Categoriile vizuale (Anvelope, Franare, Motor, Cupla sa...) sunt definite aici, o singura
 *   data: eticheta, pictograma, culoarea, unde se aplica si cum se leaga de catalogul existent
 *   din Reparatii Auto (mentenanta_auto_componente). Nu exista un al doilea catalog.
 * - Locurile (sloturile) unei categorii pe un vehicul se deduc din datele vehiculului: tipul,
 *   axele si tipul fiecarei axe (directie / tractiune / remorca), rotile duble. Diferentialul sta
 *   doar pe axele motoare ale unui vehicul cu motor; saua doar pe capul tractor; pivotul doar pe
 *   semiremorca.
 * - Sistemele optionale (hidraulic, livrare gaz, confort) apar doar pe vehiculele care au deja
 *   inregistrari pentru componentele lor (configurare, montare, lucrare, factura).
 * - Starea unei componente vine numai din datele existente (configurare + montare, vezi
 *   AutoComponentStateService); registrul nu inventeaza stari.
 */
final class VehicleComponentRegistry
{
    /**
     * scope: wheel (pozitie de roata), wheel_end (capat de axa: stanga / dreapta), axle (axa),
     *        vehicle (vehiculul intreg, intr-o zona a lui).
     * roles: tractor (cap tractor), truck (camion), car (autovehicul), trailer (semiremorca).
     * catalog: [categorie Reparatii Auto, lista de cuvinte din nume | null = toata categoria].
     * optional: apare doar cand vehiculul are inregistrari pentru componentele categoriei.
     */
    public const CATEGORIES = [
        'anvelope' => ['label' => 'Anvelope', 'icon' => 'bi-life-preserver', 'color' => '#334155', 'scope' => 'wheel', 'roles' => ['tractor', 'truck', 'car', 'trailer'], 'catalog' => []],
        'franare' => ['label' => 'Frânare', 'icon' => 'bi-disc', 'color' => '#e5383b', 'scope' => 'wheel_end', 'roles' => ['tractor', 'truck', 'car', 'trailer'], 'catalog' => [[3, null]]],
        'suspensie' => ['label' => 'Suspensie', 'icon' => 'bi-arrows-collapse-vertical', 'color' => '#eab308', 'scope' => 'axle', 'roles' => ['tractor', 'truck', 'car', 'trailer'], 'catalog' => [[1, null]]],
        'rulare' => ['label' => 'Rulare', 'icon' => 'bi-record-circle', 'color' => '#2563eb', 'scope' => 'wheel_end', 'roles' => ['tractor', 'truck', 'car', 'trailer'], 'catalog' => [[2, null]]],
        'directie' => ['label' => 'Direcție', 'icon' => 'bi-sign-turn-right', 'color' => '#16a34a', 'scope' => 'vehicle', 'roles' => ['tractor', 'truck', 'car'], 'catalog' => [[9, null]]],
        'motor' => ['label' => 'Motor', 'icon' => 'bi-gear-wide-connected', 'color' => '#f97316', 'scope' => 'vehicle', 'roles' => ['tractor', 'truck', 'car'], 'catalog' => [[6, null]]],
        'transmisie' => ['label' => 'Cutie de viteze / Transmisie', 'short' => 'Transmisie', 'icon' => 'bi-gear', 'color' => '#7c3aed', 'scope' => 'vehicle', 'roles' => ['tractor', 'truck', 'car'], 'catalog' => [[10, ['pompapecutie', 'pto']]]],
        'diferential' => ['label' => 'Grup diferențial', 'icon' => 'bi-bullseye', 'color' => '#9333ea', 'scope' => 'axle', 'roles' => ['tractor', 'truck', 'car'], 'catalog' => []],
        'racire' => ['label' => 'Răcire', 'icon' => 'bi-thermometer-half', 'color' => '#dc2626', 'scope' => 'vehicle', 'roles' => ['tractor', 'truck', 'car'], 'catalog' => [[4, null]]],
        'evacuare' => ['label' => 'Evacuare', 'icon' => 'bi-wind', 'color' => '#64748b', 'scope' => 'vehicle', 'roles' => ['tractor', 'truck', 'car'], 'catalog' => [[8, null]]],
        'electrica' => ['label' => 'Electrică', 'icon' => 'bi-lightning-charge-fill', 'color' => '#2563eb', 'scope' => 'vehicle', 'roles' => ['tractor', 'truck', 'car', 'trailer'], 'catalog' => [[5, ['acumulator', 'unitateadr']], [6, ['alternator', 'electromotor']], [3, ['senzorabs', 'supapaabs']]]],
        'lumini' => ['label' => 'Lumini / Semnalistică', 'short' => 'Lumini', 'icon' => 'bi-lightbulb', 'color' => '#ca8a04', 'scope' => 'vehicle', 'roles' => ['tractor', 'truck', 'car', 'trailer'], 'catalog' => [[5, ['far', 'stop', 'lumini', 'girofar']]]],
        'plastice' => ['label' => 'Plastice (aripi, apărători, carenaje)', 'short' => 'Plastice', 'icon' => 'bi-shield', 'color' => '#0891b2', 'scope' => 'axle', 'roles' => ['tractor', 'truck', 'trailer'], 'catalog' => []],
        'rezervor_combustibil' => ['label' => 'Rezervoare combustibil', 'short' => 'Rezervor combustibil', 'icon' => 'bi-fuel-pump', 'color' => '#0d9488', 'scope' => 'vehicle', 'roles' => ['tractor', 'truck', 'car'], 'catalog' => []],
        'hidraulic' => ['label' => 'Rezervoare hidraulice', 'short' => 'Hidraulic', 'icon' => 'bi-droplet-half', 'color' => '#0284c7', 'scope' => 'vehicle', 'roles' => ['tractor', 'truck', 'trailer'], 'catalog' => [[10, null]], 'optional' => true],
        'pneumatica' => ['label' => 'Pneumatică', 'icon' => 'bi-wind', 'color' => '#0ea5e9', 'scope' => 'vehicle', 'roles' => ['tractor', 'truck', 'trailer'], 'catalog' => [[6, ['compresoraer']], [1, ['perneaer']], [3, ['camerafranare']]]],
        'cupla_sa' => ['label' => 'Cuplă șa (Fifth Wheel)', 'short' => 'Cuplă șa', 'icon' => 'bi-circle-square', 'color' => '#475569', 'scope' => 'vehicle', 'roles' => ['tractor'], 'catalog' => []],
        'kingpin' => ['label' => 'Kingpin', 'icon' => 'bi-pin-angle', 'color' => '#475569', 'scope' => 'vehicle', 'roles' => ['trailer'], 'catalog' => []],
        'blocare' => ['label' => 'Mecanism blocare', 'icon' => 'bi-lock', 'color' => '#475569', 'scope' => 'vehicle', 'roles' => ['tractor'], 'catalog' => []],
        'alte' => ['label' => 'Alte sisteme', 'icon' => 'bi-grid', 'color' => '#6366f1', 'scope' => 'vehicle', 'roles' => ['tractor', 'truck', 'car', 'trailer'], 'catalog' => [[7, null], [11, null], [12, null], [13, null], [14, null], [15, null], [16, null], [17, null]], 'optional' => true],
    ];

    /** Categoriile afisate direct in bara exploratorului; restul stau in „Alte sisteme”. */
    public const TOOLBAR = ['anvelope', 'suspensie', 'rulare', 'franare', 'directie', 'motor'];

    /** Randurile fixe ale legendei (ca in macheta); restul categoriilor prezente se deschid la cerere. */
    public const LEGEND = ['anvelope', 'suspensie', 'franare', 'rulare', 'directie', 'motor', 'electrica', 'racire'];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public static function label(string $category): string
    {
        return (string) (self::CATEGORIES[$category]['label'] ?? $category);
    }

    /**
     * Definitiile pentru interfata (fara regulile de catalog), cu componentele din catalog ale
     * fiecarei categorii: subcomponentele afisate la detalierea unui ansamblu.
     *
     * @param array<string,list<string>> $catalogMap catalogByCategory()
     * @param array<string,array> $catalog AutoComponentCatalogService::components()
     */
    public static function clientCategories(array $catalogMap = [], array $catalog = []): array
    {
        $result = [];
        foreach (self::CATEGORIES as $key => $category) {
            $components = [];
            foreach ($catalogMap[$key] ?? [] as $componentKey) {
                if (isset($catalog[$componentKey])) {
                    $components[] = ['key' => $componentKey, 'name' => (string) $catalog[$componentKey]['name']];
                }
            }
            $result[] = [
                'key' => $key,
                'label' => $category['label'],
                'short' => $category['short'] ?? $category['label'],
                'icon' => $category['icon'],
                'color' => $category['color'],
                'scope' => $category['scope'],
                'toolbar' => in_array($key, self::TOOLBAR, true),
                'legend' => in_array($key, self::LEGEND, true),
                'components' => $components,
            ];
        }
        return $result;
    }

    public static function roleForVehicleType(string $vehicleType): string
    {
        return match ($vehicleType) {
            'cap_tractor' => 'tractor',
            'camion' => 'truck',
            'semiremorca' => 'trailer',
            default => 'car',
        };
    }

    /**
     * Componentele catalogului Reparatii Auto pe categoriile registrului. O componenta poate
     * aparea in doua categorii (ex. Compresor aer: Motor si Pneumatica); ce nu se potriveste
     * nicaieri ajunge in „Alte sisteme”, ca nimic din catalog sa nu dispara din explorator.
     *
     * @param array<string,array{key:string,category_id:int,category:string,name:string,code:string}> $components
     * @return array<string,list<string>> categorie -> chei de componenta
     */
    public static function catalogByCategory(array $components): array
    {
        $result = array_fill_keys(array_keys(self::CATEGORIES), []);
        foreach ($components as $component) {
            $nameKey = AutoComponentStateService::lookupKey((string) $component['name']);
            $matched = false;
            foreach (self::CATEGORIES as $categoryKey => $category) {
                foreach ($category['catalog'] as [$catalogCategory, $keywords]) {
                    if ((int) $component['category_id'] !== $catalogCategory) {
                        continue;
                    }
                    $hit = $keywords === null;
                    foreach ($keywords ?? [] as $keyword) {
                        if (str_contains($nameKey, $keyword)) {
                            $hit = true;
                            break;
                        }
                    }
                    if ($hit) {
                        $result[$categoryKey][] = (string) $component['key'];
                        $matched = true;
                        break;
                    }
                }
            }
            if (!$matched) {
                $result['alte'][] = (string) $component['key'];
            }
        }
        return $result;
    }

    /**
     * Componentele (chei de catalog) cu cel putin o inregistrare pe fiecare vehicul: configurare
     * in Reparatii Auto, montare de piesa, lucrare dintr-o interventie sau articol din registrul
     * de piese. Folosit pentru sistemele optionale si pentru marcajul „are istoric”.
     *
     * @return array<int,array<string,true>>
     */
    public function componentKeysWithRecords(array $vehicleIds, array $components): array
    {
        $vehicleIds = array_values(array_unique(array_filter(array_map('intval', $vehicleIds))));
        if ($vehicleIds === []) {
            return [];
        }
        $in = implode(',', $vehicleIds);
        $result = [];
        $add = static function (array $rows) use (&$result): void {
            foreach ($rows as $row) {
                $key = (string) ($row['component_key'] ?? '');
                if ($key !== '') {
                    $result[(int) $row['vehicle_id']][$key] = true;
                }
            }
        };

        $queries = [
            "SELECT DISTINCT vehicle_id, component_key FROM mentenanta_auto_configurari WHERE vehicle_id IN ($in)",
            "SELECT DISTINCT COALESCE(l.vehicle_id, i.vehicle_id) AS vehicle_id, l.component_key
               FROM mentenanta_interventii_lucrari l
               INNER JOIN mentenanta_interventii_programate i ON i.id = l.intervention_id
              WHERE l.component_key IS NOT NULL AND COALESCE(l.vehicle_id, i.vehicle_id) IN ($in)",
            "SELECT DISTINCT vehicle_id, auto_component_key AS component_key FROM ocr_reparatii_articole
              WHERE auto_component_key IS NOT NULL AND vehicle_id IN ($in)",
        ];
        foreach ($queries as $sql) {
            try {
                $add($this->db->query($sql)->fetchAll());
            } catch (PDOException $exception) {
                // Tabel lipsa pe o instalare veche: sursa respectiva nu contribuie.
                error_log('[VehicleComponentRegistry] ' . $exception->getMessage());
            }
        }

        // Montarile de piese se leaga de componenta prin piesa ei din stoc (categorie + nume).
        try {
            $rows = $this->db->query(
                "SELECT DISTINCT u.vehicle_id, p.categorie, p.denumire
                   FROM mentenanta_piese_utilizari u
                   INNER JOIN mentenanta_piese p ON p.id = u.part_id
                  WHERE u.vehicle_id IN ($in)"
            )->fetchAll();
            $byName = [];
            foreach ($components as $component) {
                $byName[AutoComponentStateService::lookupKey((string) $component['category']) . '|' . AutoComponentStateService::lookupKey((string) $component['name'])] = (string) $component['key'];
            }
            foreach ($rows as $row) {
                $key = $byName[AutoComponentStateService::lookupKey((string) $row['categorie']) . '|' . AutoComponentStateService::lookupKey((string) $row['denumire'])] ?? '';
                if ($key !== '') {
                    $result[(int) $row['vehicle_id']][$key] = true;
                }
            }
        } catch (PDOException $exception) {
            error_log('[VehicleComponentRegistry] ' . $exception->getMessage());
        }

        return $result;
    }

    /**
     * Locurile componentelor pe un vehicul, din axele lui reale. Fiecare loc are un id stabil
     * (categorie:loc), folosit la selectie si in URL.
     *
     * @param array $member un membru din TireModel::fleetUnitClientData (type, axles, positions)
     * @param array<string,true> $recordKeys componentKeysWithRecords()[vehicul]
     * @param array<string,list<string>> $catalogMap catalogByCategory()
     * @param array{systems:array<string,true>,components:array<string,?array>}|null $configured
     *        VehicleStructureService::schemeFilter: doar ce e configurat ajunge pe schema; null = toate
     */
    public static function memberSlots(array $member, array $recordKeys, array $catalogMap, ?array $configured = null): array
    {
        $role = self::roleForVehicleType((string) ($member['type'] ?? ''));
        $axles = [];
        foreach ($member['positions'] ?? [] as $position) {
            $no = (int) $position['axle'];
            $axles[$no] ??= ['no' => $no, 'type' => (string) ($position['axle_type'] ?? ''), 'dual' => false];
            $axles[$no]['dual'] = $axles[$no]['dual'] || !empty($position['dual']);
        }
        ksort($axles);

        $slots = [];
        $hasRecords = static function (string $category) use ($recordKeys, $catalogMap): bool {
            foreach ($catalogMap[$category] ?? [] as $key) {
                if (isset($recordKeys[$key])) {
                    return true;
                }
            }
            return false;
        };
        $add = static function (string $category, string $location, string $zone, string $label, array $extra = []) use (&$slots, $hasRecords): void {
            $slots[] = [
                'id' => $category . ':' . $location,
                'category' => $category,
                'location' => $location,
                'zone' => $zone,
                'label' => $label,
                'records' => $hasRecords($category),
            ] + $extra;
        };

        foreach (self::CATEGORIES as $category => $definition) {
            if (!in_array($role, $definition['roles'], true) || $category === 'anvelope') {
                continue;
            }
            if ($configured !== null && !isset($configured['systems'][$category])) {
                continue;
            }
            if ($configured === null && !empty($definition['optional']) && !$hasRecords($category)) {
                continue;
            }
            $label = $definition['label'];
            // Componentele sistemului prezente pe vehicul si axele pe care se aplica fiecare.
            $present = [];
            if ($configured !== null) {
                foreach ($catalogMap[$category] ?? [] as $key) {
                    if (array_key_exists($key, $configured['components'])) {
                        $present[$key] = $configured['components'][$key];
                    }
                }
            }
            // Un sistem fara limitari (sau inca fara componente) sta pe toate axele.
            $onAxle = static function (int $axleNo) use ($configured, $present): bool {
                if ($configured === null || $present === []) {
                    return true;
                }
                foreach ($present as $allowed) {
                    if ($allowed === null || in_array($axleNo, $allowed, true)) {
                        return true;
                    }
                }
                return false;
            };
            $extraFor = static function (?int $axleNo) use ($configured, $present): array {
                if ($configured === null) {
                    return [];
                }
                $keys = [];
                foreach ($present as $key => $allowed) {
                    if ($axleNo === null || $allowed === null || in_array($axleNo, $allowed, true)) {
                        $keys[] = $key;
                    }
                }
                return ['components' => $keys];
            };
            $slotCountBefore = count($slots);
            switch ($category) {
                case 'franare':
                case 'rulare':
                    foreach ($axles as $axle) {
                        if (!$onAxle($axle['no'])) {
                            continue;
                        }
                        foreach (['L' => 'stânga', 'R' => 'dreapta'] as $side => $sideLabel) {
                            $add($category, 'A' . $axle['no'] . '-' . $side, 'wheel_end', $label . ' · Axa ' . $axle['no'] . ' ' . $sideLabel, ['axle' => $axle['no'], 'side' => $side] + $extraFor($axle['no']));
                        }
                    }
                    break;
                case 'suspensie':
                case 'plastice':
                    foreach ($axles as $axle) {
                        if ($onAxle($axle['no'])) {
                            $add($category, 'A' . $axle['no'], $category === 'plastice' ? 'fender' : 'axle_center', $label . ' · Axa ' . $axle['no'], ['axle' => $axle['no']] + $extraFor($axle['no']));
                        }
                    }
                    break;
                case 'diferential':
                    // Doar axele motoare ale unui vehicul cu motor.
                    foreach ($axles as $axle) {
                        if ($axle['type'] === TireModel::AXLE_TRACTION && $onAxle($axle['no'])) {
                            $add($category, 'A' . $axle['no'], 'differential', $label . ' · Axa ' . $axle['no'], ['axle' => $axle['no']] + $extraFor($axle['no']));
                        }
                    }
                    break;
                case 'directie':
                    $steering = array_values(array_filter($axles, static fn (array $axle): bool => $axle['type'] === TireModel::AXLE_STEERING));
                    $first = $steering[0]['no'] ?? (array_key_first($axles) ?? 1);
                    $add($category, 'caseta', 'steering', $label . ' · casetă și bare', ['axle' => $first]);
                    break;
                case 'lumini':
                    if ($role !== 'trailer') {
                        $add($category, 'fata', 'lights_front', $label . ' · față');
                    }
                    // Capul tractor are lampile din spate pe aripi, sub semiremorca: un singur loc (fata).
                    if ($role !== 'tractor') {
                        $add($category, 'spate', 'lights_rear', $label . ' · spate');
                    }
                    break;
                default:
                    $zones = [
                        'motor' => 'engine', 'transmisie' => 'gearbox', 'racire' => 'radiator', 'evacuare' => 'exhaust',
                        'electrica' => $role === 'trailer' ? 'trailer_ecu' : 'battery', 'rezervor_combustibil' => 'fuel_tank',
                        'hidraulic' => 'hydraulic_tank', 'pneumatica' => 'air_tanks', 'cupla_sa' => 'fifth_wheel',
                        'kingpin' => 'kingpin', 'blocare' => 'fifth_wheel_lock', 'alte' => 'body',
                    ];
                    $add($category, 'vehicul', $zones[$category] ?? 'body', $label);
            }
            // Locurile fara axa primesc toate componentele prezente ale sistemului.
            if ($configured !== null) {
                for ($index = $slotCountBefore, $total = count($slots); $index < $total; $index++) {
                    $slots[$index] += $extraFor(null);
                }
            }
        }

        return $slots;
    }
}
