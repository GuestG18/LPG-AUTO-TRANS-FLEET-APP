<?php
/**
 * Pagina "Management capacitati" (?page=categorii_capacitate).
 *
 * Categoria este DOAR o eticheta de grupare pentru selectoarele de vehicule.
 * Capacitatea tehnica reala sta in fisa vehiculului si este singura folosita
 * in calcule. Pagina are patru zone:
 *   1. sumar (distributie pe categorii + total vehicule);
 *   2. catalogul categoriilor (CRUD in modale) + previzualizarea selectorului
 *      grupat, construit cu acelasi build_vehicle_capacity_groups() ca in Carburanti;
 *   3. alocarea vehiculelor pe categorii (tabel / grupare, pagini, modificari
 *      nesalvate trimise la action=assign — doar randurile schimbate);
 *   4. raportul "capacitati reale de verificat" + jurnalul de audit.
 *
 * Toate scrierile folosesc endpoint-urile existente (store/update/delete/assign).
 */
$categories = is_array($categories ?? null) ? $categories : [];
$migrationReport = is_array($migrationReport ?? null) ? $migrationReport : [];
$verificationRows = is_array($verificationRows ?? null) ? $verificationRows : [];
$verificationSummary = is_array($verificationSummary ?? null) ? $verificationSummary : [];
$auditTrail = is_array($auditTrail ?? null) ? $auditTrail : [];
$formData = is_array($formData ?? null) ? $formData : [];
$formErrors = is_array($formErrors ?? null) ? $formErrors : [];
$transportUnits = is_array($transportUnits ?? null) ? $transportUnits : [];
$editingId = (int) ($editingId ?? 0);
$canManage = !empty($canManage);

$categoryNames = [];
$activeCategories = [];
$categoryById = [];
foreach ($categories as $category) {
    $categoryById[(int) $category['id']] = $category;
    $categoryNames[(int) $category['id']] = (string) $category['nume'];
    if ($category['activ']) {
        $activeCategories[(int) $category['id']] = (string) $category['nume'];
    }
}

// Pagina lucreaza pe UNITATI DE TRANSPORT, ca Dispecer curse: camion = o unitate,
// cap tractor + semiremorca cuplata = o unitate (categoria si capacitatea reala sunt
// ale semiremorcii). Vehiculele usoare nu transporta marfa si nu apar aici.
$unitTypeLabels = [
    'camion' => 'Camion',
    'ansamblu' => 'Cap tractor + semiremorcă',
    'semiremorca' => 'Semiremorcă necuplată',
    'cap_tractor' => 'Cap tractor fără semiremorcă',
];
$unitTypeLabel = static function (array $unit) use ($unitTypeLabels): string {
    return $unitTypeLabels[$unit['unit_type']] ?? vehicle_type_label((string) $unit['tip_vehicul']);
};

$assignmentTypes = [];
$assignmentGarages = [];
$unitByTargetId = [];
$unitsActive = 0;
$unitsActiveByType = ['camion' => 0, 'ansamblu' => 0, 'semiremorca' => 0, 'cap_tractor' => 0];
$unitsActiveWithoutCategory = 0;
$unitsByCategory = [];
foreach ($transportUnits as $unit) {
    $unitByTargetId[(int) $unit['target_id']] = $unit;
    $assignmentTypes[$unit['unit_type']] = $unitTypeLabel($unit);
    $garage = trim((string) ($unit['garaj'] ?? ''));
    if ($garage !== '') {
        $assignmentGarages[$garage] = $garage;
    }
    $isActiveUnit = $unit['status'] === 'activ';
    $categoryKey = $unit['categorie_capacitate_id'] ?? 0;
    $unitsByCategory[$categoryKey] ??= ['total' => 0, 'active' => 0];
    $unitsByCategory[$categoryKey]['total']++;
    if ($isActiveUnit) {
        $unitsActive++;
        $unitsActiveByType[$unit['unit_type']]++;
        $unitsByCategory[$categoryKey]['active']++;
        if ($unit['categorie_capacitate_id'] === null) {
            $unitsActiveWithoutCategory++;
        }
    }
}
// Ordinea din filtrul "Tip": camion, ansamblu, apoi cazurile rare.
$assignmentTypes = array_intersect_key($unitTypeLabels, $assignmentTypes);
ksort($assignmentGarages);
$unitsTotal = count($transportUnits);
$unitsInactive = $unitsTotal - $unitsActive;
$verificationTotal = (int) ($verificationSummary['total'] ?? count($verificationRows));
$verificationContradicted = (int) ($verificationSummary['contrazise_de_curse'] ?? 0);

// Filtrele raportului de verificare: doar valorile prezente in raport.
$verificationTypes = [];
$verificationCategories = [];
$verificationGarages = [];
foreach ($verificationRows as $row) {
    $verificationTypes[(string) $row['tip_vehicul']] = vehicle_type_label((string) $row['tip_vehicul']);
    if ($row['categorie'] !== null) {
        $verificationCategories[(string) $row['categorie']] = (string) $row['categorie'];
    }
    // Semiremorca cuplata sta in garajul capului tractor.
    $garage = trim((string) ($unitByTargetId[(int) $row['id']]['garaj'] ?? ''));
    if ($garage !== '') {
        $verificationGarages[$garage] = $garage;
    }
}
asort($verificationTypes);
ksort($verificationGarages);

// Previzualizarea selectorului: aceeasi grupare ca in Configurare transport /
// Dispecer (build_vehicle_capacity_groups): unitatile active, pe asignarile
// SALVATE; ansamblul apare sub numarul capului tractor, cu capacitatea reala a
// semiremorcii.
$previewCandidates = [];
foreach ($transportUnits as $unit) {
    if ($unit['status'] !== 'activ') {
        continue;
    }
    $categoryId = $unit['categorie_capacitate_id'];
    $previewCandidates[] = [
        'id' => $unit['vehicle_id'],
        'nr_inmatriculare' => $unit['nr_inmatriculare'],
        'marca' => $unit['marca'],
        'model' => $unit['model'],
        'semi_nr' => $unit['semi_nr'],
        'capacitate_transport' => $unit['capacitate_transport'],
        'categorie_capacitate_id' => $categoryId,
        'categorie_capacitate' => $categoryId !== null ? ($categoryNames[$categoryId] ?? '') : '',
        'categorie_capacitate_ordine' => $categoryId !== null ? (int) ($categoryById[$categoryId]['ordine_afisare'] ?? 0) : 0,
    ];
}
$previewGroups = build_vehicle_capacity_groups($previewCandidates, ['fallback_label' => 'Fără categorie']);

$tons = static function (mixed $value): string {
    if ($value === null || $value === '' || (float) $value <= 0) {
        return '-';
    }

    return format_number_ro((float) $value, 2) . ' t';
};
$priorityBadge = [
    'contrazisa' => ['danger', 'Contrazisă de curse'],
    'lipsa' => ['muted', 'Fără capacitate'],
    'de_confirmat' => ['warning', 'De confirmat'],
];
// Fisa vehiculului este singurul loc in care se corecteaza capacitatea reala.
$vehicleRoute = static function (string $type): string {
    return in_array($type, ['autovehicul', 'autoutilitara'], true) ? 'vehicule_usoare' : 'vehicule_grele';
};
$vehicleLink = static function (int $vehicleId, string $type) use ($vehicleRoute): ?string {
    $route = $vehicleRoute($type);
    if (can($route, 'edit')) {
        return build_query_url(['page' => $route, 'action' => 'edit', 'id' => $vehicleId]);
    }
    if (can($route)) {
        return build_query_url(['page' => $route, 'action' => 'show', 'id' => $vehicleId]);
    }

    return null;
};

// Culorile blocurilor din "Distributie" se rotesc in ordinea categoriilor.
$kpiTones = ['blue', 'purple', 'green', 'orange', 'slate', 'teal', 'pink'];

$categoryJson = [];
foreach ($categories as $category) {
    $categoryJson[] = [
        'id' => (int) $category['id'],
        'name' => (string) $category['nume'],
        'order' => (int) $category['ordine_afisare'],
        'active' => (bool) $category['activ'],
    ];
}

// Modala de categorie se redeschide singura dupa o eroare de validare (422).
$field = static fn(string $key): string => (string) ($formData[$key] ?? '');
$invalid = static fn(string $key): string => isset($formErrors[$key]) ? ' is-invalid' : '';
$isActiveForm = $formData === [] || (string) ($formData['activ'] ?? '1') === '1';
$reopenCategoryModal = $canManage && ($formErrors !== [] || $editingId > 0);
$storeUrl = build_query_url(['page' => 'categorii_capacitate', 'action' => 'store']);
$updateUrl = build_query_url(['page' => 'categorii_capacitate', 'action' => 'update']);
$categoryPreviewLimit = 6;
?>
<link rel="stylesheet" href="<?= e(url('assets/css/categorii-capacitate.css?v=' . (string) @filemtime(BASE_PATH . '/assets/css/categorii-capacitate.css'))) ?>">

<div class="cmc-page" data-cmc>
    <header class="cmc-header">
        <div>
            <h1 class="cmc-title">Management capacități</h1>
            <p class="cmc-subtitle">Configurare categorii, alocare vehicule și verificare capacități reale pe baza activității din curse.</p>
        </div>
        <aside class="cmc-notice" role="note">
            <span class="cmc-notice-label"><i class="bi bi-info-circle" aria-hidden="true"></i> Important</span>
            <span class="cmc-notice-text">Categoria este doar o grupare. Capacitatea tehnică reală rămâne neschimbată și este folosită în calcule (grad de umplere, KPI).</span>
        </aside>
    </header>

    <nav class="cmc-tabs" aria-label="Secțiuni Management capacități">
        <a class="cmc-tab is-active" href="#asignare-vehicule" data-cmc-nav="asignare-vehicule">
            <i class="bi bi-truck cmc-tab-icon" aria-hidden="true"></i>
            <span>
                <span class="cmc-tab-title">Alocare vehicule</span>
                <span class="cmc-tab-sub">Setează categoria pentru fiecare vehicul</span>
            </span>
        </a>
        <a class="cmc-tab" href="#categorii" data-cmc-nav="categorii">
            <i class="bi bi-stack cmc-tab-icon" aria-hidden="true"></i>
            <span>
                <span class="cmc-tab-title">Categorii de capacitate</span>
                <span class="cmc-tab-sub">Gestionează categoriile de grupare</span>
            </span>
        </a>
        <a class="cmc-tab" href="#verificare-capacitati" data-cmc-nav="verificare-capacitati">
            <i class="bi bi-shield-check cmc-tab-icon" aria-hidden="true"></i>
            <span>
                <span class="cmc-tab-title">Verificare capacități <?php if ($verificationTotal > 0): ?><span class="cmc-count-badge"><?= e((string) $verificationTotal) ?></span><?php endif; ?></span>
                <span class="cmc-tab-sub">Vehicule care necesită verificare</span>
            </span>
        </a>
    </nav>

    <div class="cmc-grid cmc-grid-summary">
        <section class="cmc-card">
            <h2 class="cmc-card-title"><i class="bi bi-bus-front cmc-title-icon" aria-hidden="true"></i> Distribuție vehicule pe categorii</h2>
            <div class="cmc-kpis">
                <?php $kpiIndex = 0; ?>
                <?php foreach ($categories as $category): ?>
                    <?php $categoryActiveUnits = (int) ($unitsByCategory[(int) $category['id']]['active'] ?? 0); ?>
                    <?php if (!$category['activ'] && $categoryActiveUnits === 0) { continue; } ?>
                    <button type="button" class="cmc-kpi cmc-tone-<?= e($kpiTones[$kpiIndex++ % count($kpiTones)]) ?>"
                            data-cmc-kpi-filter="<?= e((string) $category['id']) ?>"
                            title="Arată unitățile active din <?= e((string) $category['nume']) ?>">
                        <span class="cmc-kpi-value"><?= e((string) $categoryActiveUnits) ?></span>
                        <span class="cmc-kpi-label"><?= e((string) $category['nume']) ?></span>
                    </button>
                <?php endforeach; ?>
                <?php if ($unitsActiveWithoutCategory > 0): ?>
                    <button type="button" class="cmc-kpi cmc-tone-none" data-cmc-kpi-filter="none" title="Arată unitățile active fără categorie">
                        <span class="cmc-kpi-value"><?= e((string) $unitsActiveWithoutCategory) ?></span>
                        <span class="cmc-kpi-label">Fără categorie</span>
                    </button>
                <?php endif; ?>
                <?php if ($categories === [] && $unitsActiveWithoutCategory === 0): ?>
                    <p class="cmc-muted mb-0">Nu există încă nicio categorie.</p>
                <?php endif; ?>
            </div>
        </section>

        <section class="cmc-card">
            <h2 class="cmc-card-title">Total vehicule</h2>
            <div class="cmc-totals">
                <div class="cmc-total-main">
                    <span class="cmc-total-value"><?= e((string) $unitsActive) ?></span>
                    <span class="cmc-total-label">vehicule active</span>
                </div>
                <button type="button" class="cmc-total-tile cmc-total-active" data-cmc-type-filter="camion" title="Arată camioanele active">
                    <span class="cmc-total-tile-value"><?= e((string) $unitsActiveByType['camion']) ?></span>
                    <span class="cmc-total-tile-label">camioane</span>
                </button>
                <button type="button" class="cmc-total-tile cmc-total-assembly" data-cmc-type-filter="ansamblu" title="Arată ansamblurile active (cap tractor + semiremorcă)">
                    <span class="cmc-total-tile-value"><?= e((string) $unitsActiveByType['ansamblu']) ?></span>
                    <span class="cmc-total-tile-label">cap tractor + semi</span>
                </button>
                <a class="cmc-total-tile cmc-total-verify" href="#verificare-capacitati" data-cmc-nav-link="verificare-capacitati">
                    <span class="cmc-total-tile-value"><?= e((string) $verificationTotal) ?></span>
                    <span class="cmc-total-tile-label">necesită verificare</span>
                </a>
            </div>
            <p class="cmc-muted cmc-small mt-2 mb-0">
                Cap tractor + semiremorca cuplată = 1 vehicul.
                <?= e((string) $unitsTotal) ?> în total, <?= e((string) $unitsInactive) ?> inactive<?php
                $unitsOther = $unitsActiveByType['semiremorca'] + $unitsActiveByType['cap_tractor'];
                if ($unitsOther > 0): ?>, <?= e((string) $unitsOther) ?> active necuplate<?php endif; ?>.
                Autoturismele și autoutilitarele nu intră aici.
            </p>
        </section>
    </div>

    <div class="cmc-grid cmc-grid-categories">
        <section class="cmc-card cmc-card-flush" id="categorii" data-cmc-section>
            <div class="cmc-card-head">
                <h2 class="cmc-card-title mb-0"><i class="bi bi-tags cmc-title-icon" aria-hidden="true"></i> Categorii de capacitate</h2>
                <?php if (count($categories) > $categoryPreviewLimit): ?>
                    <button type="button" class="cmc-link-btn" data-cmc-categories-toggle aria-expanded="false">
                        <span data-cmc-categories-toggle-label>Vezi toate (<?= e((string) count($categories)) ?>)</span> <i class="bi bi-arrow-right" aria-hidden="true"></i>
                    </button>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table class="cmc-table cmc-table-categories" data-cmc-categories-table>
                    <thead>
                        <tr>
                            <th>Categorie</th>
                            <th>Descriere</th>
                            <th class="text-center">Vehicule</th>
                            <th class="text-center">Ordine</th>
                            <th>Stare</th>
                            <?php if ($canManage): ?><th class="text-center">Acțiuni</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($categories === []): ?>
                        <tr><td colspan="<?= $canManage ? 6 : 5 ?>" class="cmc-muted">Nu există încă nicio categorie.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($categories as $index => $category): ?>
                        <tr<?= $index >= $categoryPreviewLimit ? ' class="cmc-row-extra" hidden' : '' ?>>
                            <td class="cmc-strong"><?= e((string) $category['nume']) ?></td>
                            <td class="cmc-muted cmc-ellipsis" title="<?= e((string) ($category['descriere'] ?? '')) ?>"><?= e((string) ($category['descriere'] ?? '')) ?></td>
                            <?php $categoryUnits = $unitsByCategory[(int) $category['id']] ?? ['total' => 0, 'active' => 0]; ?>
                            <td class="text-center">
                                <?= e((string) $categoryUnits['total']) ?>
                                <?php if ($categoryUnits['total'] !== $categoryUnits['active']): ?>
                                    <span class="cmc-muted cmc-small" title="vehicule active">(<?= e((string) $categoryUnits['active']) ?> active)</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center"><?= e((string) $category['ordine_afisare']) ?></td>
                            <td>
                                <?php if ($category['activ']): ?>
                                    <span class="cmc-pill cmc-pill-success">Activă</span>
                                <?php else: ?>
                                    <span class="cmc-pill cmc-pill-muted">Inactivă</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($canManage): ?>
                                <td class="text-center cmc-nowrap">
                                    <button type="button" class="cmc-icon-btn" title="Editează categoria" aria-label="Editează <?= e((string) $category['nume']) ?>"
                                            data-cmc-edit-category
                                            data-id="<?= e((string) $category['id']) ?>"
                                            data-name="<?= e((string) $category['nume']) ?>"
                                            data-description="<?= e((string) ($category['descriere'] ?? '')) ?>"
                                            data-order="<?= e((string) $category['ordine_afisare']) ?>"
                                            data-active="<?= $category['activ'] ? '1' : '0' ?>">
                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                    </button>
                                    <button type="button" class="cmc-icon-btn cmc-icon-btn-danger" title="Șterge categoria" aria-label="Șterge <?= e((string) $category['nume']) ?>"
                                            data-cmc-delete-category
                                            data-id="<?= e((string) $category['id']) ?>"
                                            data-name="<?= e((string) $category['nume']) ?>"
                                            data-vehicles="<?= e((string) $category['vehicule']) ?>">
                                        <i class="bi bi-trash3" aria-hidden="true"></i>
                                    </button>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="cmc-card-foot">
                <?php if ($canManage): ?>
                    <button type="button" class="btn btn-outline-primary cmc-btn" data-cmc-add-category>
                        <i class="bi bi-plus-lg" aria-hidden="true"></i> Adaugă categorie
                    </button>
                <?php endif; ?>
                <span class="cmc-muted cmc-small">
                    Migrare: <?= e((string) ($migrationReport['vehicule_procesate'] ?? 0)) ?> vehicule procesate,
                    <?= e((string) ($migrationReport['vehicule_asignate'] ?? 0)) ?> asignate,
                    <?= e((string) ($migrationReport['capacitati_confirmate'] ?? 0)) ?> capacități confirmate.
                </span>
            </div>
        </section>

        <section class="cmc-card" data-cmc-preview>
            <h2 class="cmc-card-title mb-1">Previzualizare filtrare (exemplu Carburanti)</h2>
            <p class="cmc-muted cmc-small mb-2">Așa vor apărea vehiculele în selectoarele din aplicație.</p>
            <button type="button" class="cmc-preview-trigger" data-cmc-preview-trigger aria-expanded="true">
                <span data-cmc-preview-summary>Selectare vehicule după categorie</span>
                <i class="bi bi-chevron-down" aria-hidden="true"></i>
            </button>
            <div class="cmc-preview-panel" data-cmc-preview-panel>
                <?php if ($previewGroups === []): ?>
                    <p class="cmc-muted cmc-small m-2">Nu există vehicule active.</p>
                <?php endif; ?>
                <?php foreach ($previewGroups as $groupIndex => $group): ?>
                    <?php $groupCount = count($group['vehicles']); ?>
                    <div class="cmc-preview-group<?= $groupIndex === 0 ? '' : ' is-collapsed' ?>" data-cmc-preview-group>
                        <div class="cmc-preview-group-head" data-cmc-preview-head>
                            <input class="form-check-input m-0" type="checkbox" data-cmc-preview-group-check aria-label="Selectează toate vehiculele: <?= e((string) $group['label']) ?>">
                            <span class="cmc-strong"><?= e((string) $group['label']) ?></span>
                            <span class="cmc-muted">(<?= e((string) $groupCount) ?> <?= $groupCount === 1 ? 'vehicul' : 'vehicule' ?>)</span>
                            <i class="bi bi-chevron-down cmc-preview-chevron" aria-hidden="true"></i>
                        </div>
                        <div class="cmc-preview-options">
                            <?php foreach ($group['vehicles'] as $vehicle): ?>
                                <?php
                                $brand = trim((string) $vehicle['marca'] . ' ' . (string) $vehicle['model']);
                                $realCapacity = vehicle_capacity_format_tons($vehicle['capacitate_transport'] ?? null);
                                ?>
                                <label class="cmc-preview-option">
                                    <input type="checkbox" class="form-check-input m-0" data-cmc-preview-check>
                                    <span><?= e((string) $vehicle['nr_inmatriculare'] . ($brand !== '' ? ' - ' . mb_strtoupper($brand) : '')) ?><?= $realCapacity !== null ? ' <span class="cmc-muted">(' . e($realCapacity) . ')</span>' : '' ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="cmc-muted cmc-small mt-2 mb-0"><i class="bi bi-eye" aria-hidden="true"></i> Doar previzualizare pe asignările salvate; bifele nu modifică nimic. Valoarea din paranteză este capacitatea reală.</p>
        </section>
    </div>

    <section class="cmc-card cmc-card-flush cmc-assign" id="asignare-vehicule" data-cmc-section data-capacity-assign>
        <script type="application/json" data-cmc-categories-json><?= json_encode($categoryJson, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
        <div class="cmc-assign-head">
            <div class="cmc-assign-heading">
                <i class="bi bi-truck cmc-assign-icon" aria-hidden="true"></i>
                <div>
                    <h2 class="cmc-section-title">Alocare vehicule pe categorii</h2>
                    <p class="cmc-muted mb-0">
                        <?php if ($canManage): ?>
                            Selectează categoria pentru fiecare vehicul. Capacitatea tehnică reală rămâne neschimbată.
                            <span class="cmc-small">Cap tractor + semiremorcă = un rând; categoria se salvează pe semiremorcă, ca în Dispecer curse.</span>
                        <?php else: ?>
                            Categoria fiecărui vehicul. Doar utilizatorii cu drept de administrare pot schimba asignările.
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            <div class="cmc-view-switch">
                <span class="cmc-view-switch-label">Mod afișare:</span>
                <div class="cmc-segmented" role="group" aria-label="Mod afișare">
                    <button type="button" class="is-active" data-cmc-view="table" aria-pressed="true">Tabel</button>
                    <button type="button" data-cmc-view="group" aria-pressed="false"><i class="bi bi-grid" aria-hidden="true"></i> Grupare</button>
                </div>
            </div>
        </div>

        <div class="cmc-filters">
            <div class="cmc-filter cmc-filter-search">
                <label class="visually-hidden" for="cap_assign_search">Caută vehicul</label>
                <div class="cmc-search">
                    <input type="search" class="form-control" id="cap_assign_search" placeholder="Caută vehicul..." data-assign-filter="search" autocomplete="off">
                    <i class="bi bi-search" aria-hidden="true"></i>
                </div>
            </div>
            <div class="cmc-filter">
                <label for="cap_assign_type">Tip vehicul</label>
                <select class="form-select" id="cap_assign_type" data-assign-filter="type">
                    <option value="">Toate</option>
                    <?php foreach ($assignmentTypes as $typeKey => $typeLabel): ?>
                        <option value="<?= e((string) $typeKey) ?>"><?= e($typeLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="cmc-filter">
                <label for="cap_assign_garage">Garaj</label>
                <select class="form-select" id="cap_assign_garage" data-assign-filter="garage">
                    <option value="">Toate</option>
                    <?php foreach ($assignmentGarages as $garage): ?>
                        <option value="<?= e($garage) ?>"><?= e($garage) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="cmc-filter">
                <label for="cap_assign_category">Categorie</label>
                <select class="form-select" id="cap_assign_category" data-assign-filter="category">
                    <option value="">Toate</option>
                    <option value="none">Fără categorie</option>
                    <?php foreach ($categoryNames as $categoryId => $categoryName): ?>
                        <option value="<?= e((string) $categoryId) ?>"><?= e($categoryName) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="cmc-filter">
                <label for="cap_assign_status">Status</label>
                <select class="form-select" id="cap_assign_status" data-assign-filter="status">
                    <option value="activ">Active</option>
                    <option value="">Toate</option>
                    <option value="inactiv">Inactive</option>
                </select>
            </div>
        </div>

        <div class="cmc-bulkbar">
            <?php if ($canManage): ?>
                <span class="cmc-bulk-count"><strong data-assign-selected-count>0</strong> selectate <span class="cmc-muted cmc-small" data-assign-selected-hint></span></span>
                <select class="form-select cmc-bulk-select" data-assign-bulk-category aria-label="Categoria de aplicat">
                    <option value="" selected>-- Alege categoria --</option>
                    <option value="none">Fără categorie</option>
                    <?php foreach ($activeCategories as $categoryId => $categoryName): ?>
                        <option value="<?= e((string) $categoryId) ?>"><?= e($categoryName) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn cmc-btn cmc-btn-soft" data-assign-bulk-apply disabled>
                    <i class="bi bi-check2-square" aria-hidden="true"></i> Aplică pe selectate
                </button>
                <button type="button" class="cmc-link-btn" data-assign-clear-selection>Deselectează</button>
                <span class="cmc-bulk-feedback" data-assign-feedback role="status" aria-live="polite"></span>
            <?php else: ?>
                <span class="cmc-muted cmc-small">Vizualizare: nu ai dreptul de a modifica asignările.</span>
            <?php endif; ?>
            <button type="button" class="btn btn-outline-primary cmc-btn ms-auto" data-cmc-export="assign">
                <i class="bi bi-download" aria-hidden="true"></i> Exportă
            </button>
        </div>

        <form method="post" action="<?= e(build_query_url(['page' => 'categorii_capacitate', 'action' => 'assign'])) ?>" data-assign-form>
            <?= csrf_field() ?>
            <div class="cmc-table-wrap" data-cmc-table-view>
                <table class="cmc-table cmc-table-assign">
                    <colgroup>
                        <?php if ($canManage): ?><col style="width: 44px"><?php endif; ?>
                        <col style="width: 17%">
                        <col style="width: 15%">
                        <col style="width: 15%">
                        <col style="width: 12%">
                        <col style="width: 19%">
                        <col style="width: 11%">
                        <col style="width: 76px">
                    </colgroup>
                    <thead>
                        <tr>
                            <?php if ($canManage): ?>
                                <th class="cmc-col-check">
                                    <input class="form-check-input" type="checkbox" data-assign-select-all aria-label="Selectează toate vehiculele de pe pagină">
                                </th>
                            <?php endif; ?>
                            <th>Vehicul</th>
                            <th>Tip</th>
                            <th>Garaj</th>
                            <th class="text-end">Capacitate reală</th>
                            <th class="cmc-col-category">Categorie actuală</th>
                            <th>Status</th>
                            <th class="text-center">Acțiuni</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($transportUnits === []): ?>
                        <tr><td colspan="<?= $canManage ? 8 : 7 ?>" class="cmc-muted">Nu există vehicule.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($transportUnits as $unit): ?>
                        <?php
                        // Ansamblu: randul poarta numarul capului tractor, dar categoria se
                        // scrie pe semiremorca (target_id) si capacitatea este a ei.
                        $isAssembly = $unit['unit_type'] === 'ansamblu';
                        $targetId = (int) $unit['target_id'];
                        $currentCategoryId = $unit['categorie_capacitate_id'];
                        $brand = trim($unit['marca'] . ' ' . $unit['model']);
                        $subLine = $isAssembly ? trim(($brand !== '' ? $brand . ' · ' : '') . '+ ' . $unit['semi_nr']) : ($brand !== '' ? $brand : '-');
                        $searchText = mb_strtolower(trim($unit['nr_inmatriculare'] . ' ' . $brand . ' ' . ($unit['semi_nr'] ?? '') . ' ' . ($unit['semi_marca'] ?? '')));
                        $isActive = $unit['status'] === 'activ';
                        $semiInactive = $isAssembly && $unit['semi_status'] !== 'activ';
                        $capacityText = $tons($unit['capacitate_transport']);
                        $link = $vehicleLink($targetId, (string) $unit['target_tip']);
                        $typeLabel = $unitTypeLabel($unit);
                        ?>
                        <tr data-assign-row
                            data-id="<?= e((string) $targetId) ?>"
                            data-plate="<?= e($unit['nr_inmatriculare']) ?>"
                            data-semi="<?= e((string) ($unit['semi_nr'] ?? '')) ?>"
                            data-brand="<?= e($subLine) ?>"
                            data-type-label="<?= e($typeLabel) ?>"
                            data-capacity="<?= e($capacityText) ?>"
                            data-search="<?= e($searchText) ?>"
                            data-type="<?= e($unit['unit_type']) ?>"
                            data-garage="<?= e(trim((string) ($unit['garaj'] ?? ''))) ?>"
                            data-status="<?= $isActive ? 'activ' : 'inactiv' ?>"
                            data-category="<?= $currentCategoryId === null ? 'none' : e((string) $currentCategoryId) ?>">
                            <?php if ($canManage): ?>
                                <td class="cmc-col-check"><input class="form-check-input" type="checkbox" data-assign-check aria-label="Selectează <?= e($unit['nr_inmatriculare']) ?>"></td>
                            <?php endif; ?>
                            <td>
                                <span class="cmc-plate"><?= e($unit['nr_inmatriculare']) ?></span>
                                <span class="cmc-sub" title="<?= $isAssembly ? e('Semiremorcă cuplată: ' . $unit['semi_nr'] . ' ' . $unit['semi_marca']) : '' ?>"><?= e($subLine) ?></span>
                            </td>
                            <td><?= e($typeLabel) ?></td>
                            <td><?= e((string) ($unit['garaj'] ?? '') !== '' ? (string) $unit['garaj'] : '-') ?></td>
                            <td class="text-end cmc-num">
                                <?= e($capacityText) ?>
                                <?php if ($unit['capacitate_transport'] !== null && !$unit['capacitate_transport_confirmata']): ?>
                                    <i class="bi bi-exclamation-circle cmc-warn-icon" title="Capacitate neverificată" aria-label="Capacitate neverificată"></i>
                                <?php endif; ?>
                            </td>
                            <td class="cmc-col-category">
                                <?php if ($canManage): ?>
                                    <select class="form-select cmc-cat-select" name="assignments[<?= e((string) $targetId) ?>]" data-assign-select data-original="<?= $currentCategoryId === null ? '' : e((string) $currentCategoryId) ?>"
                                        <?php if ($isAssembly && $unit['tractor_categorie_id'] !== null): ?>
                                            data-tractor-id="<?= e((string) $unit['vehicle_id']) ?>"
                                        <?php endif; ?>
                                        aria-label="Categoria pentru <?= e($unit['nr_inmatriculare']) ?>">
                                        <option value="">-- Fără categorie --</option>
                                        <?php foreach ($activeCategories as $categoryId => $categoryName): ?>
                                            <option value="<?= e((string) $categoryId) ?>"<?= $currentCategoryId === $categoryId ? ' selected' : '' ?>><?= e($categoryName) ?></option>
                                        <?php endforeach; ?>
                                        <?php if ($currentCategoryId !== null && !isset($activeCategories[$currentCategoryId])): ?>
                                            <option value="<?= e((string) $currentCategoryId) ?>" selected><?= e(($categoryNames[$currentCategoryId] ?? 'Categorie #' . $currentCategoryId) . ' (inactivă)') ?></option>
                                        <?php endif; ?>
                                    </select>
                                <?php else: ?>
                                    <?= $currentCategoryId !== null ? e($categoryNames[$currentCategoryId] ?? '-') : '<span class="cmc-muted">-</span>' ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="cmc-status <?= $isActive ? 'is-active' : 'is-inactive' ?>"><?= $isActive ? 'Activ' : 'Inactiv' ?></span>
                                <?php if ($semiInactive): ?>
                                    <span class="cmc-sub cmc-text-warning" title="Semiremorca <?= e((string) $unit['semi_nr']) ?> este inactivă">semi inactivă</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($link !== null): ?>
                                    <a class="cmc-icon-btn cmc-icon-btn-primary" href="<?= e($link) ?>"
                                       title="<?= $isAssembly ? e('Deschide fișa semiremorcii ' . $unit['semi_nr'] . ' (capacitatea reală este a ei)') : 'Deschide fișa vehiculului (capacitate reală, tip, garaj)' ?>"
                                       aria-label="Fișa vehiculului <?= e($isAssembly ? (string) $unit['semi_nr'] : $unit['nr_inmatriculare']) ?>">
                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr data-assign-empty hidden><td colspan="<?= $canManage ? 8 : 7 ?>" class="cmc-muted text-center py-4">Niciun vehicul nu corespunde filtrelor.</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="cmc-group-view" data-cmc-group-view hidden></div>

            <div class="cmc-pagination" data-cmc-pagination>
                <span class="cmc-muted" data-cmc-page-range>-</span>
                <div class="cmc-pagination-controls">
                    <select class="form-select cmc-page-size" data-cmc-page-size aria-label="Vehicule pe pagină">
                        <option value="10">10 / pagină</option>
                        <option value="25" selected>25 / pagină</option>
                        <option value="50">50 / pagină</option>
                        <option value="100">100 / pagină</option>
                        <option value="0">Toate</option>
                    </select>
                    <nav class="cmc-pager" aria-label="Pagini vehicule" data-cmc-pager></nav>
                </div>
            </div>

            <?php if ($canManage): ?>
                <div class="cmc-savebar" data-cmc-savebar hidden>
                    <span class="cmc-savebar-text">
                        <i class="bi bi-pencil-square" aria-hidden="true"></i>
                        <span data-assign-dirty-label>Nicio modificare nesalvată.</span>
                        <span class="cmc-muted cmc-small">Capacitatea reală nu se modifică.</span>
                    </span>
                    <div class="cmc-savebar-actions">
                        <button type="button" class="btn btn-outline-secondary cmc-btn" data-assign-reset disabled>Anulează modificările</button>
                        <button type="submit" class="btn btn-primary cmc-btn" data-assign-save disabled>
                            <i class="bi bi-save" aria-hidden="true"></i> Salvează modificările
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        </form>
    </section>

    <section class="cmc-card cmc-card-flush cmc-verify" id="verificare-capacitati" data-cmc-section data-cmc-verify>
        <div class="cmc-verify-head">
            <div class="cmc-assign-heading">
                <i class="bi bi-shield-exclamation cmc-verify-icon" aria-hidden="true"></i>
                <div>
                    <h2 class="cmc-section-title cmc-section-title-sm">Capacități reale de verificat</h2>
                    <p class="cmc-muted mb-0">Vehiculele a căror capacitate stocată este posibil depășită de valorile din curse și trebuie verificate.</p>
                </div>
            </div>
            <div class="cmc-verify-note">
                Valorile din curse sunt doar dovezi pentru verificare.<br>
                Nu se actualizează automat capacitatea reală.
            </div>
        </div>

        <div class="cmc-filters cmc-filters-verify">
            <div class="cmc-filter cmc-filter-search">
                <label class="visually-hidden" for="cap_verify_search">Caută vehicul</label>
                <div class="cmc-search">
                    <input type="search" class="form-control" id="cap_verify_search" placeholder="Caută vehicul..." data-verify-filter="search" autocomplete="off">
                    <i class="bi bi-search" aria-hidden="true"></i>
                </div>
            </div>
            <div class="cmc-filter">
                <label for="cap_verify_category">Categorie</label>
                <select class="form-select" id="cap_verify_category" data-verify-filter="category">
                    <option value="">Toate</option>
                    <option value="__none">Fără categorie</option>
                    <?php foreach ($verificationCategories as $categoryName): ?>
                        <option value="<?= e($categoryName) ?>"><?= e($categoryName) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="cmc-filter">
                <label for="cap_verify_type">Tip vehicul</label>
                <select class="form-select" id="cap_verify_type" data-verify-filter="type">
                    <option value="">Toate</option>
                    <?php foreach ($verificationTypes as $typeKey => $typeLabel): ?>
                        <option value="<?= e((string) $typeKey) ?>"><?= e($typeLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="cmc-filter">
                <label for="cap_verify_garage">Garaj</label>
                <select class="form-select" id="cap_verify_garage" data-verify-filter="garage">
                    <option value="">Toate</option>
                    <?php foreach ($verificationGarages as $garage): ?>
                        <option value="<?= e($garage) ?>"><?= e($garage) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="cmc-filter cmc-filter-actions">
                <button type="button" class="btn cmc-btn cmc-btn-danger-outline" data-verify-contradicted aria-pressed="false" title="Doar vehiculele contrazise de curse">
                    <span class="cmc-count-badge"><?= e((string) $verificationContradicted) ?></span> Doar cu contrazise
                </button>
                <button type="button" class="btn btn-outline-primary cmc-btn" data-cmc-export="verify">
                    <i class="bi bi-download" aria-hidden="true"></i> Exportă
                </button>
            </div>
        </div>

        <div class="cmc-table-wrap cmc-table-wrap-verify">
            <table class="cmc-table cmc-table-verify">
                <thead>
                    <tr>
                        <th>Vehicul</th>
                        <th>Tip</th>
                        <th>Categorie</th>
                        <th class="text-end">Capacitate stocată</th>
                        <th class="text-end">Max. din curse</th>
                        <th class="text-center">Curse</th>
                        <th>Prioritate</th>
                        <th class="text-end">Acțiuni</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($verificationRows === []): ?>
                    <tr><td colspan="8" class="cmc-muted text-center py-4">Toate capacitățile reale sunt verificate.</td></tr>
                <?php endif; ?>
                <?php foreach ($verificationRows as $row): ?>
                    <?php
                    [$badgeClass, $badgeLabel] = $priorityBadge[$row['prioritate']] ?? ['muted', '-'];
                    $brand = trim((string) $row['marca'] . ' ' . (string) $row['model']);
                    $rowUnit = $unitByTargetId[(int) $row['id']] ?? null;
                    // Semiremorca cuplata: aratam si capul tractor (numarul folosit in curse).
                    $coupledTractor = $rowUnit !== null && $rowUnit['unit_type'] === 'ansamblu' ? $rowUnit['nr_inmatriculare'] : null;
                    $garage = trim((string) ($rowUnit['garaj'] ?? ''));
                    $link = $vehicleLink((int) $row['id'], (string) $row['tip_vehicul']);
                    ?>
                    <tr data-verify-row
                        data-search="<?= e(mb_strtolower((string) $row['nr_inmatriculare'] . ' ' . $brand . ' ' . ($coupledTractor ?? ''))) ?>"
                        data-category="<?= e($row['categorie'] !== null ? (string) $row['categorie'] : '__none') ?>"
                        data-type="<?= e((string) $row['tip_vehicul']) ?>"
                        data-garage="<?= e($garage) ?>"
                        data-priority="<?= e((string) $row['prioritate']) ?>"
                        data-export="<?= e(json_encode([
                            (string) $row['nr_inmatriculare'],
                            $brand,
                            vehicle_type_label((string) $row['tip_vehicul']),
                            (string) ($row['categorie'] ?? ''),
                            $tons($row['capacitate_transport']),
                            $tons($row['tone_max_inregistrate']),
                            (string) $row['curse'],
                            $badgeLabel,
                        ], JSON_UNESCAPED_UNICODE)) ?>">
                        <td>
                            <span class="cmc-plate"><?= e((string) $row['nr_inmatriculare']) ?></span>
                            <span class="cmc-sub"><?= e($brand !== '' ? $brand : '-') ?><?= $coupledTractor !== null ? ' · cuplată cu ' . e($coupledTractor) : '' ?><?= (string) $row['status'] !== 'activ' ? ' · inactiv' : '' ?></span>
                        </td>
                        <td><?= e(vehicle_type_label((string) $row['tip_vehicul'])) ?></td>
                        <td><?= $row['categorie'] !== null ? e((string) $row['categorie']) : '<span class="cmc-muted">-</span>' ?></td>
                        <td class="text-end cmc-num"><?= e($tons($row['capacitate_transport'])) ?></td>
                        <td class="text-end cmc-num<?= $row['prioritate'] === 'contrazisa' ? ' cmc-text-danger' : '' ?>"><?= e($tons($row['tone_max_inregistrate'])) ?></td>
                        <td class="text-center"><?= e((string) $row['curse']) ?></td>
                        <td><span class="cmc-pill cmc-pill-<?= e($badgeClass) ?>"><?= e($badgeLabel) ?></span></td>
                        <td class="text-end">
                            <?php if ($link !== null): ?>
                                <a class="btn btn-outline-primary cmc-btn cmc-btn-sm" href="<?= e($link) ?>" title="Deschide fișa vehiculului pentru a corecta / confirma capacitatea reală">Verifică</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr data-verify-empty hidden><td colspan="8" class="cmc-muted text-center py-4">Niciun vehicul nu corespunde filtrelor.</td></tr>
                </tbody>
            </table>
        </div>
        <div class="cmc-verify-foot cmc-muted cmc-small">
            <span data-verify-count><?= e((string) count($verificationRows)) ?></span> din <?= e((string) count($verificationRows)) ?> vehicule ·
            <?= e((string) $verificationContradicted) ?> contrazise de curse,
            <?= e((string) ($verificationSummary['fara_capacitate'] ?? 0)) ?> fără capacitate,
            <?= e((string) ($verificationSummary['doar_de_confirmat'] ?? 0)) ?> așteaptă doar confirmarea.
            Corectarea se face în fișa vehiculului; categoria nu se schimbă.
        </div>
    </section>

    <?php if ($auditTrail !== []): ?>
        <details class="cmc-card cmc-card-flush cmc-audit">
            <summary class="cmc-audit-summary">
                <span><i class="bi bi-clock-history" aria-hidden="true"></i> Ultimele modificări de capacitate / categorie</span>
                <span class="cmc-muted cmc-small">Jurnal de audit · <?= e((string) count($auditTrail)) ?> intrări</span>
            </summary>
            <div class="cmc-table-wrap">
                <table class="cmc-table">
                    <thead>
                        <tr>
                            <th>Când</th>
                            <th>Vehicul</th>
                            <th>Capacitate reală</th>
                            <th>Categorie</th>
                            <th>Verificare</th>
                            <th>Cine</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($auditTrail as $entry): ?>
                        <tr>
                            <td class="cmc-muted"><?= e(format_datetime_ro((string) $entry['created_at'])) ?></td>
                            <td class="cmc-strong"><?= e((string) $entry['nr_inmatriculare']) ?></td>
                            <td>
                                <?php if ((string) $entry['capacitate_veche'] === (string) $entry['capacitate_noua']): ?>
                                    <span class="cmc-muted">neschimbată (<?= e($tons($entry['capacitate_noua'])) ?>)</span>
                                <?php else: ?>
                                    <?= e($tons($entry['capacitate_veche'])) ?> &rarr; <strong><?= e($tons($entry['capacitate_noua'])) ?></strong>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((string) ($entry['categorie_veche'] ?? '') === (string) ($entry['categorie_noua'] ?? '')): ?>
                                    <span class="cmc-muted">neschimbată</span>
                                <?php else: ?>
                                    <?= e((string) ($entry['categorie_veche'] ?? '-')) ?> &rarr; <strong><?= e((string) ($entry['categorie_noua'] ?? '-')) ?></strong>
                                <?php endif; ?>
                            </td>
                            <td><?= (int) $entry['confirmata_noua'] === 1 ? 'verificată' : 'de verificat' ?></td>
                            <td class="cmc-muted"><?= e((string) ($entry['utilizator'] ?? '-')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="cmc-muted cmc-small px-3 py-2 mb-0">Snapshot-ul dinainte de migrare este păstrat separat și permite revenirea la valorile inițiale.</p>
        </details>
    <?php endif; ?>
</div>

<?php if ($canManage): ?>
    <div class="modal fade" id="cmcCategoryModal" tabindex="-1" aria-labelledby="cmcCategoryModalTitle" aria-hidden="true"
         data-cmc-category-modal
         data-store-url="<?= e($storeUrl) ?>"
         data-update-url="<?= e($updateUrl) ?>"
         data-reopen="<?= $reopenCategoryModal ? '1' : '0' ?>">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content cmc-modal">
                <form method="post" action="<?= e($editingId > 0 ? $updateUrl : $storeUrl) ?>" novalidate data-cmc-category-form>
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= e((string) $editingId) ?>" data-cmc-category-id <?= $editingId > 0 ? '' : 'disabled' ?>>
                    <div class="modal-header">
                        <h2 class="modal-title fs-6" id="cmcCategoryModalTitle" data-cmc-category-title><?= $editingId > 0 ? 'Editează categoria' : 'Adaugă categorie' ?></h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label" for="cap_cat_nume">Nume categorie <span class="text-danger">*</span></label>
                            <input type="text" class="form-control<?= $invalid('nume') ?>" id="cap_cat_nume" name="nume" maxlength="80" value="<?= e($field('nume')) ?>" placeholder="Ex: 18.5 TONE" required>
                            <div class="invalid-feedback" data-cmc-error="nume"><?= e((string) ($formErrors['nume'] ?? 'Numele categoriei este obligatoriu.')) ?></div>
                            <div class="form-text">Text liber: „18.5 TONE”, „7+”, „Capacitate mare”. Redenumirea păstrează vehiculele asignate.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="cap_cat_descriere">Descriere</label>
                            <input type="text" class="form-control<?= $invalid('descriere') ?>" id="cap_cat_descriere" name="descriere" maxlength="255" value="<?= e($field('descriere')) ?>">
                            <?php if (isset($formErrors['descriere'])): ?><div class="invalid-feedback"><?= e($formErrors['descriere']) ?></div><?php endif; ?>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="cap_cat_ordine">Ordine de afișare</label>
                            <input type="number" min="0" step="1" class="form-control<?= $invalid('ordine_afisare') ?>" id="cap_cat_ordine" name="ordine_afisare" value="<?= e($field('ordine_afisare') !== '' ? $field('ordine_afisare') : '0') ?>">
                            <?php if (isset($formErrors['ordine_afisare'])): ?><div class="invalid-feedback"><?= e($formErrors['ordine_afisare']) ?></div><?php endif; ?>
                            <div class="form-text">Număr mai mic = mai sus în dropdown-uri.</div>
                        </div>
                        <div class="form-check form-switch">
                            <?php // Bifa debifata nu trimite nimic, iar validarea ar considera lipsa ca "activ": trimitem explicit 0. ?>
                            <input type="hidden" name="activ" value="0">
                            <input class="form-check-input" type="checkbox" role="switch" id="cap_cat_activ" name="activ" value="1" <?= $isActiveForm ? 'checked' : '' ?>>
                            <label class="form-check-label" for="cap_cat_activ">Categorie activă</label>
                            <div class="form-text">Categoriile inactive nu mai apar în fișa vehiculului, dar vehiculele deja asignate rămân asignate.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary cmc-btn" data-bs-dismiss="modal">Renunță</button>
                        <button type="submit" class="btn btn-primary cmc-btn" data-cmc-category-submit><?= $editingId > 0 ? 'Salvează modificările' : 'Adaugă categoria' ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="cmcDeleteModal" tabindex="-1" aria-labelledby="cmcDeleteModalTitle" aria-hidden="true" data-cmc-delete-modal>
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content cmc-modal">
                <form method="post" action="<?= e(build_query_url(['page' => 'categorii_capacitate', 'action' => 'delete'])) ?>" data-cmc-delete-form>
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="" data-cmc-delete-id>
                    <div class="modal-header">
                        <h2 class="modal-title fs-6" id="cmcDeleteModalTitle">Șterge categoria <span data-cmc-delete-name></span></h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-2">Capacitățile reale ale vehiculelor <strong>nu se modifică</strong>.</p>
                        <div data-cmc-delete-reassign hidden>
                            <p class="small mb-2">Categoria are <strong data-cmc-delete-count></strong>. Alege unde se mută înainte de ștergere:</p>
                            <label class="form-label small" for="cmc_reassign_to">Mută vehiculele în</label>
                            <select class="form-select" id="cmc_reassign_to" name="reassign_to" data-cmc-delete-target>
                                <option value="">-- Alege destinația --</option>
                                <option value="0">Fără categorie</option>
                                <?php foreach ($categories as $target): ?>
                                    <option value="<?= e((string) $target['id']) ?>"><?= e((string) $target['nume']) ?><?= $target['activ'] ? '' : ' (inactivă)' ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">Alege o destinație pentru vehicule.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary cmc-btn" data-bs-dismiss="modal">Renunță</button>
                        <button type="submit" class="btn btn-danger cmc-btn"><i class="bi bi-trash3" aria-hidden="true"></i> Șterge categoria</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<script src="<?= e(url('assets/js/categorii-capacitate.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/categorii-capacitate.js'))) ?>"></script>
