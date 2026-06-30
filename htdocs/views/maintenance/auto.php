<?php
declare(strict_types=1);

$catalog = is_array($catalog ?? null) ? $catalog : [];
$activePath = is_array($catalog['active_path'] ?? null) ? $catalog['active_path'] : [];
$categories = is_array($catalog['categories'] ?? null) ? $catalog['categories'] : [];
$categoryGroups = is_array($catalog['category_groups'] ?? null) ? $catalog['category_groups'] : [];
$tree = is_array($catalog['tree'] ?? null) ? $catalog['tree'] : [];
$vehicles = is_array($catalog['vehicles'] ?? null) ? $catalog['vehicles'] : [];
$selectedVehicleFromCatalog = is_array($catalog['selected_vehicle'] ?? null) ? $catalog['selected_vehicle'] : null;
$vehicleOptions = is_array($catalog['vehicle_types'] ?? null) ? $catalog['vehicle_types'] : [
    'camion' => 'Camion',
    'cap_tractor' => 'Cap tractor',
    'semiremorca' => 'Semiremorca',
    'ansamblu' => 'Ansamblu',
];
$categoryGroups += [
    'sasiu' => [1, 2, 3, 4, 5, 6, 7, 8, 9],
    'hidraulic' => [10],
    'livrare_gaz' => [11, 12, 13, 14, 15, 16, 17],
];

$vehicle = (string) ($_GET['vehicle_type'] ?? ($activePath['vehicle'] ?? 'camion'));
if (!array_key_exists($vehicle, $vehicleOptions)) {
    $vehicle = 'camion';
}
$vehicleId = max(0, (int) ($_GET['vehicle_id'] ?? ($activePath['vehicle_id'] ?? 0)));
$vehiclesById = [];
foreach ($vehicles as $vehicleRow) {
    $id = (int) ($vehicleRow['id'] ?? 0);
    if ($id > 0) {
        $vehiclesById[$id] = $vehicleRow;
    }
}
$selectedVehicle = $vehicleId > 0 && isset($vehiclesById[$vehicleId])
    ? $vehiclesById[$vehicleId]
    : $selectedVehicleFromCatalog;
if (is_array($selectedVehicle)) {
    $vehicleId = (int) ($selectedVehicle['id'] ?? $vehicleId);
}

$primaryOptions = $vehicle === 'cap_tractor'
    ? ['sasiu' => 'Sasiu']
    : ['rezervor' => 'Rezervor', 'sasiu' => 'Sasiu'];
$primary = (string) ($_GET['primary_category'] ?? ($activePath['primary'] ?? 'rezervor'));
if (!array_key_exists($primary, $primaryOptions)) {
    $primary = array_key_first($primaryOptions) ?: 'sasiu';
}

$subcategoryOptions = $primary === 'sasiu'
    ? ['sasiu' => 'Sasiu', 'hidraulic' => 'Hidraulic']
    : ['sasiu' => 'Sasiu', 'hidraulic' => 'Hidraulic', 'livrare_gaz' => 'Livrare Gaz'];
$subcategory = (string) ($_GET['subcategory'] ?? ($activePath['subcategory'] ?? 'livrare_gaz'));
if (!array_key_exists($subcategory, $subcategoryOptions)) {
    $subcategory = array_key_first($subcategoryOptions) ?: 'sasiu';
}

$currentCategoryIds = array_values(array_filter((array) ($categoryGroups[$subcategory] ?? $categoryGroups['livrare_gaz']), static fn ($id): bool => isset($categories[(int) $id])));
$componentPage = (($_GET['view'] ?? '') === 'components') || isset($_GET['category_id']);
$selectedCategoryId = (int) ($_GET['category_id'] ?? ($activePath['category_id'] ?? ($currentCategoryIds[0] ?? 11)));
if (!in_array($selectedCategoryId, $currentCategoryIds, true)) {
    $selectedCategoryId = (int) ($currentCategoryIds[0] ?? 11);
}
$selectedCategory = is_array($categories[$selectedCategoryId] ?? null) ? $categories[$selectedCategoryId] : [];
$selectedComponents = array_values(is_array($selectedCategory['components'] ?? null) ? $selectedCategory['components'] : []);
$selectedComponentId = (string) ($_GET['component_id'] ?? '');
$selectedComponent = $selectedComponents[0] ?? [];
foreach ($selectedComponents as $componentCandidate) {
    if ((string) ($componentCandidate['id'] ?? '') === $selectedComponentId) {
        $selectedComponent = $componentCandidate;
        break;
    }
}

$vehicleLabel = (string) ($vehicleOptions[$vehicle] ?? 'Camion');
$selectedVehicleLabel = is_array($selectedVehicle)
    ? trim((string) ($selectedVehicle['nr_inmatriculare'] ?? '') . ' - ' . (string) ($selectedVehicle['marca'] ?? '') . ' ' . (string) ($selectedVehicle['model'] ?? ''))
    : 'Selecteaza vehicul';
$selectedVehicleKm = is_array($selectedVehicle) ? (int) ($selectedVehicle['km_bord'] ?? 0) : 0;
$primaryLabel = (string) ($primaryOptions[$primary] ?? 'Rezervor');
$subcategoryLabel = (string) ($subcategoryOptions[$subcategory] ?? 'Livrare Gaz');
$searchValue = (string) ($_GET['search'] ?? '');
$monitoringMethodsDefault = ['Kilometri'];

$categoryIdListForNode = static function (array $node): string {
    $meta = (string) ($node['meta'] ?? '');
    if (str_contains($meta, '11-17')) {
        return '11,12,13,14,15,16,17';
    }
    if (str_contains($meta, 'Categorie 10')) {
        return '10';
    }
    if (str_contains($meta, '1-9')) {
        return '1,2,3,4,5,6,7,8,9';
    }

    return '';
};

$treeRouteForIds = static function (string $ids): array {
    return match ($ids) {
        '1,2,3,4,5,6,7,8,9' => ['primary' => 'sasiu', 'subcategory' => 'sasiu'],
        '10' => ['primary' => 'rezervor', 'subcategory' => 'hidraulic'],
        '11,12,13,14,15,16,17' => ['primary' => 'rezervor', 'subcategory' => 'livrare_gaz'],
        default => ['primary' => '', 'subcategory' => ''],
    };
};

$renderTree = static function (array $nodes) use (&$renderTree, $categoryIdListForNode, $treeRouteForIds): void {
    echo '<ul class="repair-auto-tree-list">';
    foreach ($nodes as $node) {
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        $hasChildren = $children !== [];
        $classes = ['repair-auto-tree-row'];
        if (!empty($node['active'])) {
            $classes[] = 'is-active';
        }
        if (!empty($node['selected'])) {
            $classes[] = 'is-selected';
        }

        $categoryIds = $categoryIdListForNode($node);
        $route = [
            'primary' => (string) ($node['primary'] ?? ''),
            'subcategory' => (string) ($node['subcategory'] ?? ''),
        ];
        if ($route['primary'] === '' || $route['subcategory'] === '') {
            $route = $treeRouteForIds($categoryIds);
        }
        $label = (string) ($node['label'] ?? '');
        $itemClasses = ['repair-auto-tree-item'];
        if ($hasChildren && empty($node['expanded'])) {
            $itemClasses[] = 'is-collapsed';
        }
        $expanded = $hasChildren && !empty($node['expanded']);

        echo '<li class="' . e(implode(' ', $itemClasses)) . '">';
        echo '<button type="button" class="' . e(implode(' ', $classes)) . '" data-tree-label="' . e($label) . '" data-category-ids="' . e($categoryIds) . '" data-tree-primary="' . e($route['primary']) . '" data-tree-subcategory="' . e($route['subcategory']) . '" data-tree-has-children="' . ($hasChildren ? '1' : '0') . '"' . ($hasChildren ? ' aria-expanded="' . ($expanded ? 'true' : 'false') . '"' : '') . '>';
        echo '<span class="repair-auto-tree-caret' . ($hasChildren ? '' : ' is-empty') . '"><i class="bi ' . ($hasChildren ? 'bi-chevron-down' : 'bi-dot') . '"></i></span>';
        echo '<span class="repair-auto-tree-icon"><i class="bi ' . e((string) ($node['icon'] ?? 'bi-circle')) . '"></i></span>';
        echo '<span class="repair-auto-tree-copy"><strong>' . e($label) . '</strong>';
        if (!empty($node['meta'])) {
            echo '<small>' . e((string) $node['meta']) . '</small>';
        }
        echo '</span>';
        echo '</button>';

        if ($hasChildren) {
            echo '<div class="repair-auto-tree-children">';
            $renderTree($children);
            echo '</div>';
        }
        echo '</li>';
    }
    echo '</ul>';
};

$buildAutoUrl = static function (array $extra = []) use ($vehicle, $vehicleId, $primary, $subcategory): string {
    return build_query_url(array_merge([
        'page' => 'mentenanta',
        'action' => 'auto',
        'vehicle_type' => $vehicle,
        'vehicle_id' => $vehicleId > 0 ? $vehicleId : null,
        'primary_category' => $primary,
        'subcategory' => $subcategory,
    ], $extra));
};

$resolveMonitoring = static function (array $component, string $vehicle, string $primary): array {
    $monitoringByVehicle = is_array($component['monitoring_by_vehicle'] ?? null) ? $component['monitoring_by_vehicle'] : [];
    $key = $primary === 'rezervor' ? 'rezervor' : ($vehicle === 'ansamblu' ? 'semiremorca' : $vehicle);
    $raw = trim((string) ($monitoringByVehicle[$key] ?? ''));
    if ($raw === '' || $raw === '-') {
        foreach ($monitoringByVehicle as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '' && $candidate !== '-') {
                $raw = $candidate;
                break;
            }
        }
    }

    $rawLower = mb_strtolower($raw, 'UTF-8');
    if (str_contains($rawLower, 'km') && str_contains($rawLower, 'timp')) {
        return [
            'type' => 'Combinatie',
            'methods' => ['Kilometri', 'Timp (luni)'],
            'unit' => 'km',
            'interval' => (string) ($component['interval'] ?? '30.000'),
            'warning' => (string) ($component['warning'] ?? '25.000'),
            'critical' => (string) ($component['critical'] ?? '28.000'),
            'lifetime' => (string) ($component['lifetime'] ?? '35.000'),
        ];
    }
    if (str_contains($rawLower, 'data') || str_contains($rawLower, 'timp')) {
        return [
            'type' => 'Timp luni/ani',
            'methods' => ['Timp (luni)'],
            'unit' => 'luni',
            'interval' => '12',
            'warning' => '10',
            'critical' => '11',
            'lifetime' => '24',
        ];
    }

    return [
        'type' => 'Kilometri',
        'methods' => ['Kilometri'],
        'unit' => 'km',
        'interval' => (string) ($component['interval'] ?? '30.000'),
        'warning' => (string) ($component['warning'] ?? '25.000'),
        'critical' => (string) ($component['critical'] ?? '28.000'),
        'lifetime' => (string) ($component['lifetime'] ?? '35.000'),
    ];
};

$componentPayload = static function (array $component) use ($resolveMonitoring, $vehicle, $primary): array {
    $monitoring = $resolveMonitoring($component, $vehicle, $primary);
    if (!empty($component['config_id'])) {
        if (trim((string) ($component['monitoring_type'] ?? '')) !== '') {
            $monitoring['type'] = (string) $component['monitoring_type'];
        }
        if (is_array($component['monitoring_methods'] ?? null) && $component['monitoring_methods'] !== []) {
            $monitoring['methods'] = $component['monitoring_methods'];
        }
        if (trim((string) ($component['unit'] ?? '')) !== '') {
            $monitoring['unit'] = (string) $component['unit'];
        }
        foreach (['interval', 'warning', 'critical', 'lifetime'] as $field) {
            if (trim((string) ($component[$field] ?? '')) !== '') {
                $monitoring[$field] = (string) $component[$field];
            }
        }
    }
    $notes = trim((string) ($component['notes'] ?? ''));
    return array_merge($component, $monitoring, [
        'notes' => $notes !== '' ? $notes : 'Verificare periodica in functie de intervalele configurate.',
        'configuration_status' => !empty($component['configured']) ? 'Configurat' : 'Neconfigurat',
    ]);
};

$selectedPayload = $selectedComponent !== [] ? $componentPayload($selectedComponent) : [
    'name' => '',
    'code' => '',
    'description' => '',
    'type' => 'Kilometri',
    'methods' => $monitoringMethodsDefault,
    'unit' => 'km',
    'interval' => '',
    'warning' => '',
    'critical' => '',
    'lifetime' => '',
    'notes' => '',
    'photo_url' => '',
    'photo_original' => '',
    'warranty_status' => 'red',
    'warranty_label' => 'Fara garantie',
    'garantie_piesa' => '',
    'garantie_manopera' => '',
    'stock_part_id' => 0,
    'repairable' => 1,
    'repair_resets_lifetime' => 0,
    'requires_calibration' => 0,
];
$selectedCode = trim((string) ($selectedPayload['stock_code'] ?? '')) !== ''
    ? (string) $selectedPayload['stock_code']
    : (string) ($selectedPayload['code'] ?? '');
$selectedDescription = trim((string) ($selectedPayload['stock_description'] ?? '')) !== ''
    ? (string) $selectedPayload['stock_description']
    : (string) ($selectedPayload['description'] ?? '');
$selectedPhotoAlt = trim((string) ($selectedPayload['photo_original'] ?? '')) !== ''
    ? (string) $selectedPayload['photo_original']
    : (string) ($selectedPayload['name'] ?? 'Fotografie componenta');

$warrantyClass = static function (string $status): string {
    return match ($status) {
        'green' => 'is-green',
        'yellow' => 'is-yellow',
        default => 'is-red',
    };
};

$breadcrumbItems = [
    ['label' => 'Reparatii', 'url' => build_query_url(['page' => 'mentenanta', 'action' => 'repairs'])],
    ['label' => 'Auto', 'url' => build_query_url(['page' => 'mentenanta', 'action' => 'auto'])],
    ['label' => $vehicleLabel, 'url' => $buildAutoUrl(['primary_category' => null, 'subcategory' => null])],
    ['label' => $primaryLabel, 'url' => $buildAutoUrl(['subcategory' => null])],
];
if ($subcategory !== 'sasiu') {
    $breadcrumbItems[] = ['label' => $subcategoryLabel, 'url' => $buildAutoUrl()];
}
if ($componentPage && $selectedCategory !== []) {
    $selectedCategoryLabel = (string) ($selectedCategory['name'] ?? 'Categorie');
    if (mb_strtolower($selectedCategoryLabel, 'UTF-8') !== mb_strtolower($subcategoryLabel, 'UTF-8')) {
        $breadcrumbItems[] = ['label' => $selectedCategoryLabel, 'url' => $buildAutoUrl(['view' => 'components', 'category_id' => $selectedCategoryId])];
    }
    $breadcrumbItems[] = ['label' => 'Componente', 'url' => $buildAutoUrl(['view' => 'components', 'category_id' => $selectedCategoryId])];
}
?>

<div class="repair-auto-page" data-repair-auto-page data-repair-mode="<?= $componentPage ? 'components' : 'overview' ?>">
    <nav class="repair-auto-breadcrumb" aria-label="Breadcrumb">
        <?php foreach ($breadcrumbItems as $index => $item): ?>
            <?php if ($index > 0): ?><i class="bi bi-chevron-right"></i><?php endif; ?>
            <a href="<?= e((string) $item['url']) ?>" data-breadcrumb-part="<?= e((string) $index) ?>"><?= e((string) $item['label']) ?></a>
        <?php endforeach; ?>
    </nav>

    <form class="repair-auto-filterbar" method="get" data-repair-auto-filter>
        <input type="hidden" name="page" value="mentenanta">
        <input type="hidden" name="action" value="auto">
        <label class="repair-auto-filter-field">
            <span>Tip vehicul</span>
            <i class="bi bi-truck"></i>
            <select name="vehicle_type" data-repair-vehicle>
                <?php foreach ($vehicleOptions as $value => $label): ?>
                    <option value="<?= e((string) $value) ?>"<?= $vehicle === $value ? ' selected' : '' ?>><?= e((string) $label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="repair-auto-filter-field">
            <span>Vehicul</span>
            <i class="bi bi-123"></i>
            <select name="vehicle_id" data-repair-vehicle-id>
                <?php foreach ($vehicles as $vehicleRow): ?>
                    <?php
                    $rowId = (int) ($vehicleRow['id'] ?? 0);
                    $rowType = (string) ($vehicleRow['auto_type'] ?? 'camion');
                    $rowLabel = trim((string) ($vehicleRow['nr_inmatriculare'] ?? '') . ' - ' . (string) ($vehicleRow['marca'] ?? '') . ' ' . (string) ($vehicleRow['model'] ?? ''));
                    ?>
                    <option value="<?= e((string) $rowId) ?>" data-vehicle-type="<?= e($rowType) ?>"<?= $vehicleId === $rowId ? ' selected' : '' ?>><?= e($rowLabel !== '' ? $rowLabel : ('Vehicul #' . $rowId)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="repair-auto-filter-field">
            <span>Categorie principala</span>
            <i class="bi bi-hdd-network"></i>
            <select name="primary_category" data-repair-primary>
                <?php foreach (['rezervor' => 'Rezervor', 'sasiu' => 'Sasiu'] as $value => $label): ?>
                    <option value="<?= e((string) $value) ?>"<?= $primary === $value ? ' selected' : '' ?><?= $vehicle === 'cap_tractor' && $value === 'rezervor' ? ' disabled' : '' ?>><?= e((string) $label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="repair-auto-filter-field">
            <span>Subcategorie</span>
            <i class="bi bi-fire"></i>
            <select name="subcategory" data-repair-subcategory>
                <?php foreach ($subcategoryOptions as $value => $label): ?>
                    <option value="<?= e((string) $value) ?>"<?= $subcategory === $value ? ' selected' : '' ?>><?= e((string) $label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="repair-auto-search-field">
            <span class="visually-hidden">Cauta componenta</span>
            <input type="search" name="search" placeholder="Cauta componenta..." value="<?= e($searchValue) ?>" data-repair-search>
            <button type="submit" aria-label="Cauta"><i class="bi bi-search"></i></button>
        </label>
    </form>

    <main class="repair-auto-layout <?= $componentPage ? 'is-component-page' : 'is-category-overview' ?>">
        <section class="repair-auto-panel repair-auto-tree-panel">
            <div class="repair-auto-panel-title">STRUCTURA VEHICUL</div>
            <?php $renderTree($tree); ?>
        </section>

        <?php if (!$componentPage): ?>
            <section class="repair-auto-panel repair-auto-category-panel">
                <div class="repair-auto-section-heading">
                    <h1 data-repair-category-heading><?= e($subcategoryLabel) ?></h1>
                    <i class="bi bi-chevron-right"></i>
                    <span>Selecteaza o categorie</span>
                </div>
                <p class="repair-auto-section-note">Selecteaza o categorie pentru a vedea componentele.</p>

                <div class="repair-auto-card-grid" data-repair-card-grid>
                    <?php foreach ($currentCategoryIds as $id): ?>
                        <?php $category = is_array($categories[(int) $id] ?? null) ? $categories[(int) $id] : []; ?>
                        <a
                            class="repair-auto-category-card"
                            href="<?= e($buildAutoUrl(['view' => 'components', 'category_id' => (int) $id, 'search' => null])) ?>"
                            data-category-id="<?= e((string) $id) ?>"
                        >
                            <span class="repair-auto-category-number"><?= e((string) $id) ?></span>
                            <span class="repair-auto-category-icon">
                                <i class="bi <?= e((string) ($category['icon'] ?? 'bi-circle')) ?>"></i>
                            </span>
                            <span class="repair-auto-category-title"><?= e((string) $id) ?>. <?= e((string) ($category['title'] ?? '')) ?></span>
                            <span class="repair-auto-category-count"><?= e((string) ($category['count'] ?? 0)) ?> componente</span>
                        </a>
                    <?php endforeach; ?>
                </div>

                <div class="repair-auto-info">
                    <i class="bi bi-info-circle-fill"></i>
                    <div>
                        <strong>Informatii</strong>
                        <p>Categoriile sunt grupuri parinte. Selecteaza un card pentru a deschide lista componentelor aferente.</p>
                    </div>
                </div>
            </section>
        <?php else: ?>
            <section class="repair-auto-panel repair-auto-component-panel">
                <div class="repair-auto-section-heading repair-auto-component-heading">
                    <div>
                        <h1><?= e((string) ($selectedCategory['name'] ?? $subcategoryLabel)) ?></h1>
                        <span><?= e((string) count($selectedComponents)) ?> componente</span>
                    </div>
                    <i class="bi bi-chevron-right"></i>
                    <strong>Componente</strong>
                    <div class="repair-auto-vehicle-context">
                        <i class="bi bi-truck"></i>
                        <span><?= e($selectedVehicleLabel) ?></span>
                        <?php if ($selectedVehicleKm > 0): ?><em><?= e(format_number_ro((float) $selectedVehicleKm, 0)) ?> km</em><?php endif; ?>
                    </div>
                </div>

                <div class="repair-auto-components-table" data-repair-components-table>
                    <div class="repair-auto-component-row is-header">
                        <span>Nr.</span>
                        <span>Nume componenta</span>
                        <span>Tip monitorizare</span>
                        <span>Stare</span>
                        <span>Uzura</span>
                        <span>Actiuni</span>
                    </div>
                    <?php foreach ($selectedComponents as $index => $component): ?>
                        <?php
                        $payload = $componentPayload($component);
                        $methods = implode('|', (array) ($payload['methods'] ?? []));
                        $isSelected = (string) ($component['id'] ?? '') === (string) ($selectedComponent['id'] ?? '');
                        $wearRaw = $payload['wear'] ?? null;
                        $wear = is_numeric($wearRaw) ? (int) $wearRaw : null;
                        $wearTone = $wear === null ? 'is-empty' : ($wear >= 60 ? 'is-orange' : 'is-green');
                        $payloadCode = trim((string) ($payload['stock_code'] ?? '')) !== ''
                            ? (string) $payload['stock_code']
                            : (string) ($payload['code'] ?? '');
                        $payloadDescription = trim((string) ($payload['stock_description'] ?? '')) !== ''
                            ? (string) $payload['stock_description']
                            : (string) ($payload['description'] ?? '');
                        ?>
                        <div
                            class="repair-auto-component-row <?= $isSelected ? 'is-selected' : '' ?>"
                            role="button"
                            tabindex="0"
                            data-component-row
                            data-component-id="<?= e((string) ($payload['id'] ?? '')) ?>"
                            data-stock-part-id="<?= e((string) ($payload['stock_part_id'] ?? 0)) ?>"
                            data-name="<?= e((string) ($payload['name'] ?? '')) ?>"
                            data-code="<?= e($payloadCode) ?>"
                            data-description="<?= e($payloadDescription) ?>"
                            data-photo-url="<?= e((string) ($payload['photo_url'] ?? '')) ?>"
                            data-photo-original="<?= e((string) ($payload['photo_original'] ?? '')) ?>"
                            data-monitoring-type="<?= e((string) ($payload['type'] ?? 'Kilometri')) ?>"
                            data-methods="<?= e($methods) ?>"
                            data-unit="<?= e((string) ($payload['unit'] ?? 'km')) ?>"
                            data-interval="<?= e((string) ($payload['interval'] ?? '')) ?>"
                            data-warning="<?= e((string) ($payload['warning'] ?? '')) ?>"
                            data-critical="<?= e((string) ($payload['critical'] ?? '')) ?>"
                            data-lifetime="<?= e((string) ($payload['lifetime'] ?? '')) ?>"
                            data-notes="<?= e((string) ($payload['notes'] ?? '')) ?>"
                            data-warranty-status="<?= e((string) ($payload['warranty_status'] ?? 'red')) ?>"
                            data-warranty-label="<?= e((string) ($payload['warranty_label'] ?? 'Fara garantie')) ?>"
                            data-garantie-piesa="<?= e((string) ($payload['garantie_piesa'] ?? '')) ?>"
                            data-garantie-manopera="<?= e((string) ($payload['garantie_manopera'] ?? '')) ?>"
                            data-repairable="<?= e((string) ($payload['repairable'] ?? 1)) ?>"
                            data-repair-resets-lifetime="<?= e((string) ($payload['repair_resets_lifetime'] ?? 0)) ?>"
                            data-requires-calibration="<?= e((string) ($payload['requires_calibration'] ?? 0)) ?>"
                        >
                            <span><i class="repair-auto-row-radio"></i><?= e((string) ($index + 1)) ?></span>
                            <strong><?= e((string) ($payload['name'] ?? '')) ?></strong>
                            <span><em class="repair-auto-monitor-pill"><?= e((string) ($payload['type'] ?? 'Kilometri')) ?></em></span>
                            <span><em class="repair-auto-config-pill <?= !empty($payload['configured']) ? 'is-configured' : 'is-missing' ?>"><?= !empty($payload['configured']) ? 'Configurat' : 'Neconfigurat' ?></em></span>
                            <span>
                                <em class="repair-auto-wear <?= e($wearTone) ?>" style="--wear: <?= e((string) ($wear === null ? 0 : max(0, min(100, $wear)))) ?>"><?= $wear === null ? '-' : e((string) $wear) . '%' ?></em>
                            </span>
                            <span class="repair-auto-row-actions">
                                <button type="button" data-component-view title="Vezi configurarea"><i class="bi bi-eye"></i></button>
                                <button type="button" data-component-open title="Deschide componenta"><i class="bi bi-chevron-right"></i></button>
                            </span>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($selectedComponents === []): ?>
                        <div class="repair-auto-empty">Nu exista componente in categoria selectata.</div>
                    <?php endif; ?>
                </div>

                <div class="repair-auto-info">
                    <i class="bi bi-info-circle-fill"></i>
                    <div>
                        <strong>Informatii</strong>
                        <p>Uzura este calculata pe baza intervalelor configurate. Selecteaza o componenta pentru a vedea sau edita configurarea acesteia.</p>
                    </div>
                </div>
            </section>

            <aside class="repair-auto-panel repair-auto-config-panel">
                <div class="repair-auto-config-title">
                    <h2 data-repair-config-component><?= e((string) ($selectedPayload['name'] ?? '')) ?></h2>
                    <i class="bi bi-chevron-right"></i>
                    <span class="repair-auto-warranty-badge <?= e($warrantyClass((string) ($selectedPayload['warranty_status'] ?? 'red'))) ?>" data-repair-warranty-badge><?= e((string) ($selectedPayload['warranty_label'] ?? 'Fara garantie')) ?></span>
                    <div class="repair-auto-config-nav">
                        <button type="button" aria-label="Componenta anterioara" data-component-prev><i class="bi bi-chevron-left"></i></button>
                        <button type="button" aria-label="Componenta urmatoare" data-component-next><i class="bi bi-chevron-right"></i></button>
                        <a href="<?= e($buildAutoUrl()) ?>" aria-label="Inchide"><i class="bi bi-x-lg"></i></a>
                    </div>
                </div>

                <div class="repair-auto-tabs" role="tablist">
                    <button class="active" type="button" data-repair-tab="details">Detalii</button>
                    <button type="button" data-repair-tab="wear">Factori uzura</button>
                    <button type="button" data-repair-tab="history">Istoric inlocuiri</button>
                    <button type="button" data-repair-tab="documents">Documente</button>
                </div>

                <form class="repair-auto-detail-form" method="post" enctype="multipart/form-data" action="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'save_auto_component_config'])) ?>" data-repair-detail-form>
                    <?= csrf_field() ?>
                    <input type="hidden" name="vehicle_id" value="<?= e((string) $vehicleId) ?>" data-repair-hidden="vehicle_id">
                    <input type="hidden" name="vehicle_type" value="<?= e($vehicle) ?>" data-repair-hidden="vehicle_type">
                    <input type="hidden" name="primary_category" value="<?= e($primary) ?>" data-repair-hidden="primary_category">
                    <input type="hidden" name="subcategory" value="<?= e($subcategory) ?>" data-repair-hidden="subcategory">
                    <input type="hidden" name="category_id" value="<?= e((string) $selectedCategoryId) ?>" data-repair-hidden="category_id">
                    <input type="hidden" name="component_id" value="<?= e((string) ($selectedPayload['id'] ?? '')) ?>" data-repair-hidden="component_id">
                    <input type="hidden" name="stock_part_id" value="<?= e((string) ($selectedPayload['stock_part_id'] ?? 0)) ?>" data-repair-hidden="stock_part_id">
                    <input type="hidden" name="component_name" value="<?= e((string) ($selectedPayload['name'] ?? '')) ?>" data-repair-hidden="component_name">
                    <input type="hidden" name="monitoring_type" value="<?= e((string) ($selectedPayload['type'] ?? 'Kilometri')) ?>" data-repair-hidden="monitoring_type">
                    <input type="hidden" name="monitoring_methods" value="<?= e(implode('|', (array) ($selectedPayload['methods'] ?? $monitoringMethodsDefault))) ?>" data-repair-hidden="monitoring_methods">
                    <div class="repair-auto-tab-panel active" data-repair-tab-panel="details">
                        <h3>Detalii componenta</h3>
                        <div class="repair-auto-form-grid two">
                            <label>
                                <span>Nume componenta</span>
                                <input type="text" value="<?= e((string) ($selectedPayload['name'] ?? '')) ?>" data-repair-field="name" readonly>
                            </label>
                            <label>
                                <span>Cod componenta</span>
                                <input type="text" name="component_code" value="<?= e($selectedCode) ?>" data-repair-field="code">
                            </label>
                        </div>
                        <label>
                            <span>Descriere</span>
                            <textarea rows="3" name="description" data-repair-field="description"><?= e($selectedDescription) ?></textarea>
                        </label>

                        <div class="repair-auto-photo-section">
                            <label>Fotografie componenta</label>
                            <div class="repair-auto-photo-grid">
                                <div class="repair-auto-photo-preview" data-repair-photo-preview>
                                    <?php if (!empty($selectedPayload['photo_url'])): ?>
                                        <img src="<?= e((string) $selectedPayload['photo_url']) ?>" alt="<?= e($selectedPhotoAlt) ?>">
                                    <?php else: ?>
                                        <span><i class="bi bi-image"></i>Fara fotografie</span>
                                    <?php endif; ?>
                                </div>
                                <label class="repair-auto-photo-upload">
                                    <input type="file" name="component_photo" accept="image/png,image/jpeg,image/webp">
                                    <i class="bi bi-upload"></i>
                                    <strong>Incarca fotografie</strong>
                                    <span>PNG, JPG, WebP (max. 5MB)</span>
                                </label>
                            </div>
                        </div>

                        <h3>Garantie</h3>
                        <div class="repair-auto-form-grid two">
                            <label>
                                <span>Garantie piesa</span>
                                <input type="text" name="garantie_piesa" value="<?= e((string) ($selectedPayload['garantie_piesa'] ?? '')) ?>" data-repair-field="garantie_piesa">
                            </label>
                            <label>
                                <span>Garantie manopera</span>
                                <input type="text" name="garantie_manopera" value="<?= e((string) ($selectedPayload['garantie_manopera'] ?? '')) ?>" data-repair-field="garantie_manopera">
                            </label>
                        </div>

                        <h3>Monitorizare</h3>
                        <label>
                            <span>Tip monitorizare</span>
                            <div class="repair-auto-monitor-buttons" data-repair-monitor-buttons>
                                <?php foreach (['Kilometri' => 'bi-signpost-2', 'Ore functionare' => 'bi-clock', 'Timp luni/ani' => 'bi-calendar3', 'Combinatie' => 'bi-intersect'] as $label => $icon): ?>
                                    <button class="<?= ($selectedPayload['type'] ?? '') === $label ? 'active' : '' ?>" type="button" data-monitor-type="<?= e($label) ?>"><i class="bi <?= e($icon) ?>"></i><?= e($label) ?></button>
                                <?php endforeach; ?>
                            </div>
                        </label>

                        <label>
                            <span>Metode de monitorizare</span>
                            <div class="repair-auto-chips" data-repair-methods>
                                <?php foreach ((array) ($selectedPayload['methods'] ?? $monitoringMethodsDefault) as $method): ?>
                                    <button type="button"><?= e((string) $method) ?> <i class="bi bi-x"></i></button>
                                <?php endforeach; ?>
                            </div>
                        </label>

                        <div class="repair-auto-form-grid three">
                            <label>
                                <span>Interval de inlocuire</span>
                                <div class="repair-auto-unit-input"><input type="text" name="interval_value" value="<?= e((string) ($selectedPayload['interval'] ?? '')) ?>" data-repair-field="interval"><em data-repair-unit><?= e((string) ($selectedPayload['unit'] ?? 'km')) ?></em></div>
                            </label>
                            <label>
                                <span>Atentionare la</span>
                                <div class="repair-auto-unit-input"><input type="text" name="warning_value" value="<?= e((string) ($selectedPayload['warning'] ?? '')) ?>" data-repair-field="warning"><em data-repair-unit><?= e((string) ($selectedPayload['unit'] ?? 'km')) ?></em></div>
                            </label>
                            <label>
                                <span>Critic la</span>
                                <div class="repair-auto-unit-input"><input type="text" name="critical_value" value="<?= e((string) ($selectedPayload['critical'] ?? '')) ?>" data-repair-field="critical"><em data-repair-unit><?= e((string) ($selectedPayload['unit'] ?? 'km')) ?></em></div>
                            </label>
                        </div>

                        <div class="repair-auto-form-grid one-short">
                            <label>
                                <span>Durata medie de viata</span>
                                <div class="repair-auto-unit-input"><input type="text" name="lifetime_value" value="<?= e((string) ($selectedPayload['lifetime'] ?? '')) ?>" data-repair-field="lifetime"><em data-repair-unit><?= e((string) ($selectedPayload['unit'] ?? 'km')) ?></em></div>
                            </label>
                        </div>

                        <h3>Alte setari</h3>
                        <div class="repair-auto-switch-list">
                            <label><span>Poate fi reparata</span><input type="checkbox" name="repairable" value="1" data-repair-switch="repairable" <?= !empty($selectedPayload['repairable']) ? 'checked' : '' ?>><i></i></label>
                            <label><span>Reparatia reseteaza durata de viata</span><input type="checkbox" name="repair_resets_lifetime" value="1" data-repair-switch="repair_resets_lifetime" <?= !empty($selectedPayload['repair_resets_lifetime']) ? 'checked' : '' ?>><i></i></label>
                            <label><span>Necesita calibrare dupa inlocuire</span><input type="checkbox" name="requires_calibration" value="1" data-repair-switch="requires_calibration" <?= !empty($selectedPayload['requires_calibration']) ? 'checked' : '' ?>><i></i></label>
                        </div>

                        <label>
                            <span>Observatii</span>
                            <textarea rows="4" name="notes" data-repair-field="notes"><?= e((string) ($selectedPayload['notes'] ?? '')) ?></textarea>
                        </label>
                    </div>

                    <div class="repair-auto-tab-panel" data-repair-tab-panel="wear">
                        <h3>Factori uzura</h3>
                        <div class="repair-auto-mini-list">
                            <span><i class="bi bi-check2-circle"></i> Kilometri parcursi peste interval</span>
                            <span><i class="bi bi-check2-circle"></i> Timp de exploatare ridicat</span>
                            <span><i class="bi bi-circle"></i> Reparatii repetate</span>
                            <span><i class="bi bi-circle"></i> Mediu de lucru sever</span>
                        </div>
                    </div>

                    <div class="repair-auto-tab-panel" data-repair-tab-panel="history">
                        <h3>Istoric inlocuiri</h3>
                        <div class="repair-auto-history">
                            <div><strong>17.04.2026</strong><span>Inlocuire componenta - interval complet</span></div>
                            <div><strong>29.03.2026</strong><span>Verificare preventiva</span></div>
                            <div><strong>03.03.2026</strong><span>Inspectie vizuala</span></div>
                        </div>
                    </div>

                    <div class="repair-auto-tab-panel" data-repair-tab-panel="documents">
                        <h3>Documente</h3>
                        <div class="repair-auto-document-empty">Documentele si fotografia se pastreaza pe piesa din stoc asociata acestei componente.</div>
                    </div>

                    <div class="repair-auto-form-status" data-repair-status aria-live="polite"></div>
                    <div class="repair-auto-form-actions">
                        <button type="button" class="secondary" data-repair-cancel>Anuleaza</button>
                        <button type="submit" class="primary">Salveaza</button>
                    </div>
                </form>
            </aside>
        <?php endif; ?>
    </main>
</div>

<script>
(() => {
    const page = document.querySelector('[data-repair-auto-page]');
    if (!page) {
        return;
    }

    const filter = page.querySelector('[data-repair-auto-filter]');
    const vehicleSelect = page.querySelector('[data-repair-vehicle]');
    const vehicleIdSelect = page.querySelector('[data-repair-vehicle-id]');
    const primarySelect = page.querySelector('[data-repair-primary]');
    const subcategorySelect = page.querySelector('[data-repair-subcategory]');
    const searchInput = page.querySelector('[data-repair-search]');
    const status = page.querySelector('[data-repair-status]');
    const fields = {};
    const hidden = {};
    const monitorPresets = {
        'Kilometri': { methods: ['Kilometri'], unit: 'km', interval: '30.000', warning: '25.000', critical: '28.000', lifetime: '35.000' },
        'Ore functionare': { methods: ['Ore functionare'], unit: 'ore', interval: '5.000', warning: '4.000', critical: '4.500', lifetime: '5.500' },
        'Timp luni/ani': { methods: ['Timp (luni)'], unit: 'luni', interval: '12', warning: '10', critical: '11', lifetime: '24' },
        'Combinatie': { methods: ['Kilometri', 'Ore functionare', 'Timp (luni)'], unit: 'km', interval: '30.000', warning: '25.000', critical: '28.000', lifetime: '35.000' },
    };

    page.querySelectorAll('[data-repair-field]').forEach((field) => {
        fields[field.dataset.repairField] = field;
    });
    page.querySelectorAll('[data-repair-hidden]').forEach((field) => {
        hidden[field.dataset.repairHidden] = field;
    });

    function submitFilter() {
        if (filter && typeof filter.requestSubmit === 'function') {
            filter.requestSubmit();
        } else if (filter) {
            filter.submit();
        }
    }

    function syncFilterOptions() {
        const vehicle = vehicleSelect ? vehicleSelect.value : 'camion';
        const capTractor = vehicle === 'cap_tractor';

        if (vehicleIdSelect) {
            let hasSelectedVehicle = false;
            Array.from(vehicleIdSelect.options).forEach((option) => {
                const optionType = option.dataset.vehicleType || 'camion';
                const matches = optionType === vehicle || vehicle === 'ansamblu';
                option.hidden = !matches;
                option.disabled = !matches;
                if (option.selected && matches) {
                    hasSelectedVehicle = true;
                }
            });
            if (!hasSelectedVehicle) {
                const firstMatch = Array.from(vehicleIdSelect.options).find((option) => !option.disabled);
                if (firstMatch) {
                    vehicleIdSelect.value = firstMatch.value;
                }
            }
        }

        if (primarySelect) {
            const rezervorOption = primarySelect.querySelector('option[value="rezervor"]');
            if (rezervorOption) {
                rezervorOption.disabled = capTractor;
            }
            if (capTractor) {
                primarySelect.value = 'sasiu';
            }
        }

        if (!subcategorySelect) {
            return;
        }

        const currentPrimary = primarySelect ? primarySelect.value : 'rezervor';
        const currentValue = subcategorySelect.value;
        subcategorySelect.innerHTML = '';
        if (currentPrimary === 'sasiu') {
            [['sasiu', 'Sasiu'], ['hidraulic', 'Hidraulic']].forEach(([value, label]) => {
                subcategorySelect.append(new Option(label, value, currentValue === value, currentValue === value));
            });
            if (!subcategorySelect.value) {
                subcategorySelect.value = 'sasiu';
            }
            return;
        }

        [['sasiu', 'Sasiu'], ['hidraulic', 'Hidraulic'], ['livrare_gaz', 'Livrare Gaz']].forEach(([value, label]) => {
            subcategorySelect.append(new Option(label, value, currentValue === value, currentValue === value));
        });
        if (!subcategorySelect.value) {
            subcategorySelect.value = 'livrare_gaz';
        }
    }

    function buildAutoUrl(primary, subcategory) {
        const params = new URLSearchParams();
        params.set('page', 'mentenanta');
        params.set('action', 'auto');
        params.set('vehicle_type', vehicleSelect ? vehicleSelect.value : 'camion');
        if (vehicleIdSelect && vehicleIdSelect.value) {
            params.set('vehicle_id', vehicleIdSelect.value);
        }
        params.set('primary_category', primary || (primarySelect ? primarySelect.value : 'rezervor'));
        params.set('subcategory', subcategory || (subcategorySelect ? subcategorySelect.value : 'livrare_gaz'));
        return `${window.location.pathname}?${params.toString()}`;
    }

    function toggleTreeBranch(treeRow) {
        const item = treeRow.closest('.repair-auto-tree-item');
        if (!item || treeRow.dataset.treeHasChildren !== '1') {
            return;
        }

        const collapsed = item.classList.toggle('is-collapsed');
        treeRow.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        const icon = treeRow.querySelector('.repair-auto-tree-caret i');
        if (icon) {
            icon.classList.toggle('bi-chevron-down', !collapsed);
            icon.classList.toggle('bi-chevron-right', collapsed);
        }
    }

    function updateWarrantyBadge(label, statusValue) {
        const badge = page.querySelector('[data-repair-warranty-badge]');
        if (!badge) {
            return;
        }
        badge.classList.remove('is-green', 'is-yellow', 'is-red');
        badge.classList.add(statusValue === 'green' ? 'is-green' : (statusValue === 'yellow' ? 'is-yellow' : 'is-red'));
        badge.textContent = label || 'Fara garantie';
    }

    function syncMethodsHidden() {
        const methods = Array.from(page.querySelectorAll('[data-repair-methods] button')).map((chip) => chip.dataset.method || chip.textContent.replace('×', '').trim()).filter(Boolean);
        if (hidden.monitoring_methods) {
            hidden.monitoring_methods.value = methods.join('|') || 'Kilometri';
        }
    }

    function updateMethods(value) {
        const target = page.querySelector('[data-repair-methods]');
        if (!target) {
            return;
        }
        const methods = Array.isArray(value) ? value : String(value || 'Kilometri').split('|').filter(Boolean);
        target.innerHTML = '';
        methods.forEach((method) => {
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.dataset.method = method;
            chip.innerHTML = `${method} <i class="bi bi-x"></i>`;
            target.appendChild(chip);
        });
        syncMethodsHidden();
    }

    function applyMonitoringType(type, overwriteValues = true) {
        const preset = monitorPresets[type] || monitorPresets.Kilometri;
        page.querySelectorAll('[data-monitor-type]').forEach((button) => {
            button.classList.toggle('active', button.dataset.monitorType === type);
        });
        if (hidden.monitoring_type) {
            hidden.monitoring_type.value = type;
        }
        page.querySelectorAll('[data-repair-unit]').forEach((unit) => {
            unit.textContent = preset.unit;
        });
        updateMethods(preset.methods);
        if (overwriteValues) {
            ['interval', 'warning', 'critical', 'lifetime'].forEach((key) => {
                if (fields[key]) {
                    fields[key].value = preset[key] || '';
                }
            });
        }
    }

    function updatePhoto(row) {
        const preview = page.querySelector('[data-repair-photo-preview]');
        if (!preview) {
            return;
        }
        const photoUrl = row.dataset.photoUrl || '';
        const photoAlt = row.dataset.photoOriginal || row.dataset.name || 'Fotografie componenta';
        preview.innerHTML = photoUrl
            ? `<img src="${photoUrl}" alt="${photoAlt.replace(/"/g, '&quot;')}">`
            : '<span><i class="bi bi-image"></i>Fara fotografie</span>';
    }

    function selectComponent(row) {
        if (!row) {
            return;
        }
        page.querySelectorAll('[data-component-row]').forEach((item) => item.classList.remove('is-selected'));
        row.classList.add('is-selected');

        const title = page.querySelector('[data-repair-config-component]');
        if (title) {
            title.textContent = row.dataset.name || '';
        }

        if (hidden.component_id) hidden.component_id.value = row.dataset.componentId || '';
        if (hidden.stock_part_id) hidden.stock_part_id.value = row.dataset.stockPartId || '0';
        if (hidden.component_name) hidden.component_name.value = row.dataset.name || '';

        Object.entries(fields).forEach(([key, field]) => {
            const datasetKey = key.replace(/_([a-z])/g, (_, letter) => letter.toUpperCase());
            if (row.dataset[datasetKey] !== undefined) {
                field.value = row.dataset[datasetKey];
            }
        });
        if (fields.code && fields.code.form) {
            const codeInput = fields.code.form.querySelector('[name="component_code"]');
            if (codeInput) {
                codeInput.value = row.dataset.code || '';
            }
        }

        const selectedType = row.dataset.monitoringType || 'Kilometri';
        if (hidden.monitoring_type) {
            hidden.monitoring_type.value = selectedType;
        }
        page.querySelectorAll('[data-monitor-type]').forEach((button) => {
            button.classList.toggle('active', button.dataset.monitorType === selectedType);
        });
        page.querySelectorAll('[data-repair-unit]').forEach((unit) => {
            unit.textContent = row.dataset.unit || 'km';
        });
        updateMethods(row.dataset.methods || 'Kilometri');
        page.querySelectorAll('[data-repair-switch]').forEach((toggle) => {
            const key = toggle.dataset.repairSwitch.replace(/_([a-z])/g, (_, letter) => letter.toUpperCase());
            toggle.checked = row.dataset[key] === '1';
        });
        updatePhoto(row);
        updateWarrantyBadge(row.dataset.warrantyLabel || 'Fara garantie', row.dataset.warrantyStatus || 'red');
        if (status) {
            status.textContent = '';
        }
    }

    function componentUrl(row) {
        const params = new URLSearchParams(window.location.search);
        params.set('page', 'mentenanta');
        params.set('action', 'auto');
        params.set('view', 'components');
        params.set('vehicle_type', vehicleSelect ? vehicleSelect.value : 'camion');
        if (vehicleIdSelect && vehicleIdSelect.value) {
            params.set('vehicle_id', vehicleIdSelect.value);
        }
        params.set('primary_category', primarySelect ? primarySelect.value : 'rezervor');
        params.set('subcategory', subcategorySelect ? subcategorySelect.value : 'livrare_gaz');
        params.set('category_id', hidden.category_id ? hidden.category_id.value : '0');
        params.set('component_id', row.dataset.componentId || '');
        return `${window.location.pathname}?${params.toString()}`;
    }

    function focusConfigPanel() {
        const panel = page.querySelector('.repair-auto-config-panel');
        if (panel) {
            panel.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' });
        }
    }

    function selectAdjacentComponent(direction) {
        const rows = Array.from(page.querySelectorAll('[data-component-row]')).filter((row) => !row.hidden);
        const current = page.querySelector('[data-component-row].is-selected');
        const index = rows.indexOf(current);
        if (index === -1 || rows.length === 0) {
            return;
        }
        const nextIndex = Math.max(0, Math.min(rows.length - 1, index + direction));
        const next = rows[nextIndex];
        selectComponent(next);
        window.history.replaceState({}, '', componentUrl(next));
    }

    page.addEventListener('click', (event) => {
        const treeRow = event.target.closest('.repair-auto-tree-row');
        if (treeRow && page.contains(treeRow)) {
            toggleTreeBranch(treeRow);
            const primary = treeRow.dataset.treePrimary || '';
            const subcategory = treeRow.dataset.treeSubcategory || '';
            if (primary && subcategory) {
                window.location.href = buildAutoUrl(primary, subcategory);
            }
            return;
        }

        const componentRow = event.target.closest('[data-component-row]');
        if (componentRow && page.contains(componentRow)) {
            selectComponent(componentRow);
            if (event.target.closest('[data-component-open]')) {
                window.history.replaceState({}, '', componentUrl(componentRow));
                focusConfigPanel();
            } else if (event.target.closest('[data-component-view]')) {
                focusConfigPanel();
            }
            return;
        }

        const tab = event.target.closest('[data-repair-tab]');
        if (tab && page.contains(tab)) {
            page.querySelectorAll('[data-repair-tab]').forEach((button) => button.classList.remove('active'));
            page.querySelectorAll('[data-repair-tab-panel]').forEach((panel) => panel.classList.remove('active'));
            tab.classList.add('active');
            const panel = page.querySelector(`[data-repair-tab-panel="${tab.dataset.repairTab}"]`);
            if (panel) {
                panel.classList.add('active');
            }
            return;
        }

        const monitorButton = event.target.closest('.repair-auto-monitor-buttons button');
        if (monitorButton && page.contains(monitorButton)) {
            applyMonitoringType(monitorButton.dataset.monitorType || 'Kilometri');
            return;
        }

        const chip = event.target.closest('.repair-auto-chips button');
        if (chip && page.contains(chip)) {
            chip.remove();
            syncMethodsHidden();
            return;
        }

        if (event.target.closest('[data-component-prev]')) {
            selectAdjacentComponent(-1);
            return;
        }

        if (event.target.closest('[data-component-next]')) {
            selectAdjacentComponent(1);
        }
    });

    page.addEventListener('keydown', (event) => {
        const componentRow = event.target.closest('[data-component-row]');
        if (componentRow && (event.key === 'Enter' || event.key === ' ')) {
            event.preventDefault();
            selectComponent(componentRow);
            focusConfigPanel();
        }
    });

    [vehicleSelect, primarySelect].forEach((select) => {
        if (select) {
            select.addEventListener('change', () => {
                syncFilterOptions();
                submitFilter();
            });
        }
    });

    if (vehicleIdSelect) {
        vehicleIdSelect.addEventListener('change', () => {
            const selected = vehicleIdSelect.selectedOptions[0];
            if (selected && vehicleSelect && selected.dataset.vehicleType) {
                vehicleSelect.value = selected.dataset.vehicleType;
            }
            syncFilterOptions();
            submitFilter();
        });
    }

    if (subcategorySelect) {
        subcategorySelect.addEventListener('change', submitFilter);
    }

    if (searchInput && page.dataset.repairMode === 'components') {
        searchInput.addEventListener('input', () => {
            const query = searchInput.value.trim().toLowerCase();
            page.querySelectorAll('[data-component-row]').forEach((row) => {
                const name = (row.dataset.name || '').toLowerCase();
                row.hidden = query !== '' && !name.includes(query);
            });
        });
    }

    const detailForm = page.querySelector('[data-repair-detail-form]');
    if (detailForm) {
        detailForm.addEventListener('submit', (event) => {
            syncMethodsHidden();
            if (fields.code && hidden.component_name) {
                const selected = page.querySelector('[data-component-row].is-selected');
                hidden.component_name.value = selected ? (selected.dataset.name || '') : hidden.component_name.value;
            }
            if (status) {
                status.textContent = 'Se salveaza configuratia pentru vehiculul selectat...';
            }
        });
    }

    const cancel = page.querySelector('[data-repair-cancel]');
    if (cancel) {
        cancel.addEventListener('click', () => {
            selectComponent(page.querySelector('[data-component-row].is-selected'));
            if (status) {
                status.textContent = 'Modificarile nesalvate au fost anulate.';
            }
        });
    }

    syncFilterOptions();
})();
</script>
