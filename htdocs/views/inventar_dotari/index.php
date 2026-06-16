<?php
$filters = is_array($filters ?? null) ? $filters : [];
$rows = is_array($rows ?? null) ? $rows : [];
$pagination = is_array($pagination ?? null) ? $pagination : ['page' => 1, 'total_pages' => 1, 'total_rows' => 0, 'per_page' => 10];
$stats = is_array($stats ?? null) ? $stats : [];
$vehicleTypeOptions = is_array($vehicleTypeOptions ?? null) ? $vehicleTypeOptions : [];
$statusOptions = is_array($statusOptions ?? null) ? $statusOptions : [];
$catalogItems = is_array($catalogItems ?? null) ? $catalogItems : [];
$sort = (string) ($sort ?? 'nr_inmatriculare');
$direction = strtolower((string) ($direction ?? 'asc')) === 'desc' ? 'desc' : 'asc';

$baseQuery = [
    'page' => 'inventar_dotari_vehicule',
    'q' => (string) ($filters['q'] ?? ''),
    'tip_vehicul' => (string) ($filters['tip_vehicul'] ?? ''),
    'status' => (string) ($filters['status'] ?? ''),
    'missing' => (string) ($filters['missing'] ?? ''),
    'expired' => (string) ($filters['expired'] ?? ''),
    'sort' => $sort,
    'dir' => $direction,
];

$hiddenQueryFields = function () use ($baseQuery, $pagination): void {
    foreach (array_merge($baseQuery, ['p' => (int) ($pagination['page'] ?? 1)]) as $key => $value) {
        if ($value === '' || $key === 'page') {
            continue;
        }
        echo '<input type="hidden" name="' . e((string) $key) . '" value="' . e((string) $value) . '">' . PHP_EOL;
    }
};

$sortUrl = function (string $field) use ($baseQuery, $sort, $direction): string {
    $nextDirection = ($sort === $field && $direction === 'asc') ? 'desc' : 'asc';
    return build_query_url(array_merge($baseQuery, [
        'sort' => $field,
        'dir' => $nextDirection,
        'p' => 1,
    ]));
};

$sortLabel = function (string $field, string $label) use ($sort, $direction, $sortUrl): string {
    $indicator = $sort === $field ? ($direction === 'asc' ? ' ↑' : ' ↓') : '';
    return '<a class="link-dark text-decoration-none" href="' . e($sortUrl($field)) . '">' . e($label . $indicator) . '</a>';
};

$imageThumb = static function (?string $storedFile, ?string $originalFile = null): string {
    $url = inventory_equipment_image_url($storedFile);
    if ($url === null) {
        return '<span class="inventory-equipment-thumb inventory-equipment-thumb-empty">Fără poză</span>';
    }

    $safeUrl = e($url);
    $safeAlt = e($originalFile !== null && trim($originalFile) !== '' ? $originalFile : 'Poză produs');

    return '<a href="' . $safeUrl . '" target="_blank" rel="noopener" class="inventory-equipment-thumb">'
        . '<img src="' . $safeUrl . '" alt="' . $safeAlt . '" loading="lazy">'
        . '</a>';
};

$catalogSelectOptions = function (?int $selectedId = null) use ($catalogItems): void {
    echo '<option value="">-- Selectează dotarea --</option>' . PHP_EOL;
    foreach ($catalogItems as $item) {
        $itemId = (int) ($item['id'] ?? 0);
        $imageUrl = inventory_equipment_image_url((string) ($item['poza_stocata'] ?? ''));
        echo '<option value="' . e((string) $itemId) . '"'
            . ($selectedId !== null && $selectedId === $itemId ? ' selected' : '')
            . ' data-category="' . e((string) ($item['categorie'] ?? '')) . '"'
            . ' data-cost="' . e((string) ($item['cost_implicit'] ?? '0')) . '"'
            . ' data-image="' . e((string) ($imageUrl ?? '')) . '"'
            . ' data-image-label="' . e((string) ($item['poza_original'] ?? $item['nume'] ?? '')) . '"'
            . ' data-inspection-interval="' . e((string) ($item['interval_implicit_inspectie_luni'] ?? '')) . '"'
            . '>'
            . e((string) ($item['nume'] ?? ''))
            . '</option>' . PHP_EOL;
    }
};

$assignmentForm = function (array $vehicle, ?array $assignment = null) use ($catalogSelectOptions, $hiddenQueryFields, $imageThumb): void {
    $isEdit = $assignment !== null;
    $vehicleId = $isEdit
        ? (int) ($assignment['vehicle_id'] ?? 0)
        : (int) ($vehicle['vehicle_id'] ?? $vehicle['id'] ?? 0);
    $assignmentId = (int) ($assignment['id'] ?? 0);
    $modalId = $isEdit ? 'inventory-edit-' . $assignmentId : 'inventory-add-' . $vehicleId;
    $title = $isEdit ? 'Editează dotare' : 'Adaugă dotare';
    $action = $isEdit ? 'update_assignment' : 'create_assignment';
    $catalogId = $isEdit ? (int) ($assignment['catalog_id'] ?? 0) : null;
    $imageStored = $isEdit ? (string) ($assignment['effective_poza_stocata'] ?? '') : '';
    $imageOriginal = $isEdit ? (string) ($assignment['effective_poza_original'] ?? '') : '';
    $displayVehicle = $isEdit
        ? (string) ($assignment['assigned_vehicle_label'] ?? $assignment['nr_inmatriculare'] ?? '')
        : (string) ($vehicle['nr_inmatriculare'] ?? '');
    $displayType = $isEdit
        ? (string) ($assignment['assigned_vehicle_type'] ?? $assignment['tip_vehicul'] ?? '')
        : (string) ($vehicle['tip_vehicul'] ?? '');
    $displayRole = !$isEdit && trim((string) ($vehicle['unit_role'] ?? '')) !== '' ? ' (' . (string) $vehicle['unit_role'] . ')' : '';
    ?>
    <div class="modal fade" id="<?= e($modalId) ?>" tabindex="-1" aria-labelledby="<?= e($modalId) ?>Label" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <form method="post" action="<?= e(build_query_url(['page' => 'inventar_dotari_vehicule', 'action' => $action])) ?>" enctype="multipart/form-data" data-inventory-assignment-form>
                    <?= csrf_field() ?>
                    <?php $hiddenQueryFields(); ?>
                    <input type="hidden" name="vehicle_id" value="<?= e((string) $vehicleId) ?>">
                    <?php if ($isEdit): ?>
                        <input type="hidden" name="assignment_id" value="<?= e((string) $assignmentId) ?>">
                    <?php endif; ?>
                    <div class="modal-header">
                        <h3 class="modal-title fs-5" id="<?= e($modalId) ?>Label"><?= e($title) ?></h3>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <label class="form-label">Vehicul</label>
                                <input class="form-control" value="<?= e($displayVehicle . $displayRole) ?>" readonly>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Tip Vehicul</label>
                                <input class="form-control" value="<?= e(vehicle_type_label($displayType)) ?>" readonly>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="<?= e($modalId) ?>_catalog">Dotare / Produs *</label>
                                <select class="form-select" id="<?= e($modalId) ?>_catalog" name="catalog_id" required data-inventory-catalog-select>
                                    <?php $catalogSelectOptions($catalogId); ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Categorie</label>
                                <input class="form-control" value="<?= e((string) ($assignment['catalog_categorie'] ?? '')) ?>" readonly data-inventory-category>
                            </div>
                            <div class="col-12 col-lg-4">
                                <label class="form-label">Poză Produs</label>
                                <input class="form-control" type="file" name="poza_produs" accept=".jpg,.jpeg,.png,.webp" data-inventory-image-input>
                                <div class="form-text">JPG, PNG, WEBP. Maxim 5 MB.</div>
                                <?php if ($isEdit && trim((string) ($assignment['poza_stocata'] ?? '')) !== ''): ?>
                                    <div class="form-check mt-2">
                                        <input class="form-check-input" type="checkbox" name="sterge_poza" value="1" id="<?= e($modalId) ?>_sterge_poza">
                                        <label class="form-check-label" for="<?= e($modalId) ?>_sterge_poza">Șterge poza specifică vehiculului</label>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="col-12 col-lg-2">
                                <label class="form-label">Preview</label>
                                <div data-inventory-image-preview>
                                    <?= $imageThumb($imageStored, $imageOriginal) ?>
                                </div>
                            </div>
                            <div class="col-12 col-md-3">
                                <label class="form-label">Cost</label>
                                <input class="form-control" type="number" name="cost" min="0" step="0.01" value="<?= e((string) ($assignment['cost'] ?? '')) ?>" data-inventory-cost>
                            </div>
                            <div class="col-12 col-md-3">
                                <label class="form-label">Data Achiziției</label>
                                <input class="form-control" type="date" name="data_achizitiei" value="<?= e((string) ($assignment['data_achizitiei'] ?? '')) ?>">
                            </div>
                            <div class="col-12 col-md-3">
                                <label class="form-label">Data Fabricației</label>
                                <input class="form-control" type="date" name="data_fabricatiei" value="<?= e((string) ($assignment['data_fabricatiei'] ?? '')) ?>">
                            </div>
                            <div class="col-12 col-md-3">
                                <label class="form-label">Data Ultimei Inspecții</label>
                                <input class="form-control" type="date" name="data_ultimei_inspectii" value="<?= e((string) ($assignment['data_ultimei_inspectii'] ?? '')) ?>" data-inventory-last-inspection>
                            </div>
                            <div class="col-12 col-md-3">
                                <label class="form-label">Interval Inspecție (luni)</label>
                                <input class="form-control" type="number" name="interval_inspectie_luni" min="1" step="1" value="<?= e((string) ($assignment['interval_inspectie_luni'] ?? '')) ?>" data-inventory-inspection-interval>
                            </div>
                            <div class="col-12 col-md-3">
                                <label class="form-label">Data Următoarei Inspecții</label>
                                <input class="form-control" type="date" value="<?= e((string) ($assignment['data_urmatoarei_inspectii'] ?? '')) ?>" readonly data-inventory-next-inspection>
                            </div>
                            <div class="col-12 col-md-3">
                                <label class="form-label">Data Expirării</label>
                                <input class="form-control" type="date" name="data_expirarii" value="<?= e((string) ($assignment['data_expirarii'] ?? '')) ?>">
                            </div>
                            <div class="col-12 col-md-3">
                                <label class="form-label">Serie / Cod Produs</label>
                                <input class="form-control" name="serie_cod_produs" maxlength="120" value="<?= e((string) ($assignment['serie_cod_produs'] ?? '')) ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Observații</label>
                                <textarea class="form-control" name="observatii" rows="3"><?= e((string) ($assignment['observatii'] ?? '')) ?></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Renunță</button>
                        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Salvează modificările' : 'Adaugă dotare' ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php
};
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
    <div>
        <h2 class="h4 mb-1">Inventar Dotări Vehicule</h2>
        <div class="text-muted">Gestionează dotări, consumabile și costuri fixe asignate vehiculelor existente.</div>
    </div>
    <div class="inventory-toolbar">
        <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'inventar_dotari_vehicule', 'action' => 'catalog'])) ?>">Catalog Dotări</a>
        <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'inventar_dotari_vehicule', 'action' => 'rules'])) ?>">Reguli Dotări</a>
        <a class="btn btn-outline-secondary" href="<?= e(build_query_url(array_merge($baseQuery, ['action' => 'export']))) ?>">Export</a>
        <a class="btn btn-outline-primary" href="<?= e(build_query_url(['page' => 'inventar_dotari_vehicule'])) ?>">Refresh</a>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm inventory-stat-card">
            <div class="card-body">
                <div class="text-muted small text-uppercase">Cost total flotă</div>
                <div class="fs-3 fw-semibold"><?= e(format_number_ro((float) ($stats['fleet_cost'] ?? 0), 2)) ?> lei</div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm inventory-stat-card">
            <div class="card-body">
                <div class="text-muted small text-uppercase">Dotări asignate</div>
                <div class="fs-3 fw-semibold"><?= e((string) ((int) ($stats['assigned_count'] ?? 0))) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm inventory-stat-card">
            <div class="card-body">
                <div class="text-muted small text-uppercase">Vehicule cu lipsuri</div>
                <div class="fs-3 fw-semibold"><?= e((string) ((int) ($stats['missing_vehicle_count'] ?? 0))) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm inventory-stat-card">
            <div class="card-body">
                <div class="text-muted small text-uppercase">Expirate / curând</div>
                <div class="fs-3 fw-semibold"><?= e((string) ((int) ($stats['expired_count'] ?? 0))) ?> / <?= e((string) ((int) ($stats['expiring_soon_count'] ?? 0))) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <input type="hidden" name="page" value="inventar_dotari_vehicule">
            <div class="col-12 col-lg-3">
                <label class="form-label" for="inventory_q">Caută nr. înmatriculare</label>
                <input class="form-control" id="inventory_q" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>" placeholder="Ex: B 123 ABC">
            </div>
            <div class="col-12 col-md-6 col-lg-2">
                <label class="form-label" for="inventory_tip_vehicul">Tip vehicul</label>
                <select class="form-select" id="inventory_tip_vehicul" name="tip_vehicul">
                    <option value="">Toate</option>
                    <?php foreach ($vehicleTypeOptions as $value => $label): ?>
                        <option value="<?= e((string) $value) ?>" <?= (string) ($filters['tip_vehicul'] ?? '') === (string) $value ? 'selected' : '' ?>><?= e((string) $label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-6 col-lg-2">
                <label class="form-label" for="inventory_status">Status</label>
                <select class="form-select" id="inventory_status" name="status">
                    <option value="">Toate</option>
                    <?php foreach ($statusOptions as $value => $label): ?>
                        <option value="<?= e((string) $value) ?>" <?= (string) ($filters['status'] ?? '') === (string) $value ? 'selected' : '' ?>><?= e((string) $label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-6 col-lg-2">
                <label class="form-label" for="inventory_missing">Dotări lipsă</label>
                <select class="form-select" id="inventory_missing" name="missing">
                    <option value="">Toate</option>
                    <option value="1" <?= (string) ($filters['missing'] ?? '') === '1' ? 'selected' : '' ?>>Doar cu lipsuri</option>
                </select>
            </div>
            <div class="col-12 col-md-6 col-lg-2">
                <label class="form-label" for="inventory_expired">Dotări expirate</label>
                <select class="form-select" id="inventory_expired" name="expired">
                    <option value="">Toate</option>
                    <option value="1" <?= (string) ($filters['expired'] ?? '') === '1' ? 'selected' : '' ?>>Doar expirate</option>
                </select>
            </div>
            <div class="col-12 col-lg-auto">
                <button type="submit" class="btn btn-primary">Aplică filtre</button>
            </div>
            <div class="col-12 col-lg-auto">
                <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'inventar_dotari_vehicule'])) ?>">Resetează</a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 module-list-table inventory-table">
                <thead>
                <tr>
                    <th><?= $sortLabel('nr_inmatriculare', 'Nr. Înmatriculare') ?></th>
                    <th><?= $sortLabel('tip_vehicul', 'Tip Vehicul') ?></th>
                    <th><?= $sortLabel('assigned_count', 'Dotări Asignate') ?></th>
                    <th><?= $sortLabel('missing_count', 'Dotări Lipsă') ?></th>
                    <th><?= $sortLabel('total_cost', 'Cost Total Dotări') ?></th>
                    <th><?= $sortLabel('expiring_soon_count', 'Expiră Curând') ?></th>
                    <th><?= $sortLabel('expired_count', 'Expirate') ?></th>
                    <th><?= $sortLabel('status', 'Status General') ?></th>
                    <th class="text-end pe-3">Acțiuni</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted py-4">Nu există vehicule pentru filtrele selectate.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $vehicleId = (int) ($row['vehicle_id'] ?? 0);
                        $isAssembly = !empty($row['is_assembly']);
                        $unitVehicles = is_array($row['unit_vehicles'] ?? null) ? $row['unit_vehicles'] : [$row];
                        $unitVehicleIds = is_array($row['unit_vehicle_ids'] ?? null) ? $row['unit_vehicle_ids'] : [$vehicleId];
                        ?>
                        <tr>
                            <td class="fw-semibold"><?= e((string) ($row['nr_inmatriculare'] ?? '-')) ?></td>
                            <td><?= e((string) ($row['tip_vehicul_label'] ?? '-')) ?></td>
                            <td><?= e((string) ((int) ($row['assigned_count'] ?? 0))) ?></td>
                            <td>
                                <?php if ((int) ($row['missing_count'] ?? 0) > 0): ?>
                                    <span class="badge text-bg-secondary"><?= e((string) ((int) $row['missing_count'])) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">0</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e(format_number_ro((float) ($row['total_cost'] ?? 0), 2)) ?> lei</td>
                            <td><?= e((string) ((int) ($row['expiring_soon_count'] ?? 0))) ?></td>
                            <td><?= e((string) ((int) ($row['expired_count'] ?? 0))) ?></td>
                            <td><?= inventory_equipment_status_badge_html((string) ($row['status'] ?? 'lipsa_date')) ?></td>
                            <td class="text-end pe-3">
                                <div class="d-inline-flex flex-wrap justify-content-end gap-1">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#inventory-view-<?= e((string) $vehicleId) ?>">Vezi Dotări</button>
                                    <?php if ($isAssembly): ?>
                                        <?php foreach ($unitVehicles as $unitVehicle): ?>
                                            <?php $unitVehicleId = (int) ($unitVehicle['vehicle_id'] ?? $unitVehicle['id'] ?? 0); ?>
                                            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#inventory-add-<?= e((string) $unitVehicleId) ?>">
                                                Adaugă <?= e((string) ($unitVehicle['unit_role'] ?? 'Dotare')) ?>
                                            </button>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#inventory-add-<?= e((string) $vehicleId) ?>">Adaugă Dotare</button>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#inventory-view-<?= e((string) $vehicleId) ?>">Editează</button>
                                    <form method="post" action="<?= e(build_query_url(['page' => 'inventar_dotari_vehicule', 'action' => 'delete_vehicle_assignments'])) ?>" class="d-inline">
                                        <?= csrf_field() ?>
                                        <?php $hiddenQueryFields(); ?>
                                        <?php foreach ($unitVehicleIds as $unitVehicleId): ?>
                                            <input type="hidden" name="vehicle_ids[]" value="<?= e((string) ((int) $unitVehicleId)) ?>">
                                        <?php endforeach; ?>
                                        <input type="hidden" name="vehicle_id" value="<?= e((string) $vehicleId) ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Ștergi toate dotările asignate <?= $isAssembly ? 'acestui ansamblu' : 'acestui vehicul' ?>?">Șterge</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer bg-white">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <small class="text-muted">Total rezultate: <?= e((string) ((int) $pagination['total_rows'])) ?></small>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <small class="text-muted">Pagina <?= e((string) ((int) $pagination['page'])) ?> din <?= e((string) ((int) $pagination['total_pages'])) ?></small>
                <nav aria-label="Paginare inventar dotări">
                    <ul class="pagination pagination-sm mb-0">
                        <?php
                        $prevPage = max(1, (int) $pagination['page'] - 1);
                        $nextPage = min((int) $pagination['total_pages'], (int) $pagination['page'] + 1);
                        ?>
                        <li class="page-item <?= (int) $pagination['page'] <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= e(build_query_url(array_merge($baseQuery, ['p' => $prevPage]))) ?>" <?= (int) $pagination['page'] <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>>Anterior</a>
                        </li>
                        <?php for ($i = 1; $i <= (int) $pagination['total_pages']; $i++): ?>
                            <li class="page-item <?= (int) $pagination['page'] === $i ? 'active' : '' ?>">
                                <a class="page-link" href="<?= e(build_query_url(array_merge($baseQuery, ['p' => $i]))) ?>"><?= e((string) $i) ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= (int) $pagination['page'] >= (int) $pagination['total_pages'] ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= e(build_query_url(array_merge($baseQuery, ['p' => $nextPage]))) ?>" <?= (int) $pagination['page'] >= (int) $pagination['total_pages'] ? 'aria-disabled="true" tabindex="-1"' : '' ?>>Următor</a>
                        </li>
                    </ul>
                </nav>
            </div>
        </div>
    </div>
</div>

<?php foreach ($rows as $row): ?>
    <?php
    $vehicleId = (int) ($row['vehicle_id'] ?? 0);
    $assignments = is_array($row['assignments'] ?? null) ? $row['assignments'] : [];
    $missingItems = is_array($row['missing_items'] ?? null) ? $row['missing_items'] : [];
    $isAssembly = !empty($row['is_assembly']);
    $unitVehicles = is_array($row['unit_vehicles'] ?? null) ? $row['unit_vehicles'] : [$row];
    ?>
    <div class="modal fade" id="inventory-view-<?= e((string) $vehicleId) ?>" tabindex="-1" aria-labelledby="inventory-view-<?= e((string) $vehicleId) ?>Label" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title fs-5" id="inventory-view-<?= e((string) $vehicleId) ?>Label">Dotări <?= $isAssembly ? 'ansamblu' : 'vehicul' ?> <?= e((string) ($row['nr_inmatriculare'] ?? '')) ?></h3>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
                </div>
                <div class="modal-body">
                    <div class="card border-0 bg-light mb-3">
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-6 col-lg-3"><span class="text-muted small d-block"><?= $isAssembly ? 'Ansamblu' : 'Nr. Înmatriculare' ?></span><strong><?= e((string) ($row['nr_inmatriculare'] ?? '-')) ?></strong></div>
                                <div class="col-6 col-lg-3"><span class="text-muted small d-block">Tip Vehicul</span><strong><?= e((string) ($row['tip_vehicul_label'] ?? '-')) ?></strong></div>
                                <div class="col-6 col-lg-2"><span class="text-muted small d-block">Total Dotări</span><strong><?= e((string) ((int) ($row['assigned_count'] ?? 0))) ?></strong></div>
                                <div class="col-6 col-lg-2"><span class="text-muted small d-block">Cost Total</span><strong><?= e(format_number_ro((float) ($row['total_cost'] ?? 0), 2)) ?> lei</strong></div>
                                <div class="col-6 col-lg-1"><span class="text-muted small d-block">Lipsă</span><strong><?= e((string) ((int) ($row['missing_count'] ?? 0))) ?></strong></div>
                                <div class="col-6 col-lg-1"><span class="text-muted small d-block">Expirate</span><strong><?= e((string) ((int) ($row['expired_count'] ?? 0))) ?></strong></div>
                                <div class="col-6 col-lg-2"><span class="text-muted small d-block">Expiră Curând</span><strong><?= e((string) ((int) ($row['expiring_soon_count'] ?? 0))) ?></strong></div>
                            </div>
                        </div>
                    </div>

                    <?php if ($missingItems !== []): ?>
                        <div class="alert alert-secondary">
                            <strong>Dotări lipsă:</strong>
                            <?= e(implode(', ', array_map(static fn(array $item): string => (string) ($item['display_name'] ?? $item['nume'] ?? ''), $missingItems))) ?>
                        </div>
                    <?php endif; ?>

                    <div class="table-responsive">
                        <table class="table table-sm align-middle inventory-modal-table">
                            <thead>
                            <tr>
                                <th>Poză</th>
                                <th>Dotare</th>
                                <th>Categorie</th>
                                <th>Cost</th>
                                <th>Data Fabricației</th>
                                <th>Ultima Inspecție</th>
                                <th>Următoarea Inspecție</th>
                                <th>Data Expirării</th>
                                <th>Status</th>
                                <th class="text-end">Acțiuni</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if ($assignments === []): ?>
                                <tr><td colspan="10" class="text-center text-muted py-4">Nu există dotări asignate acestui vehicul.</td></tr>
                            <?php else: ?>
                                <?php foreach ($assignments as $assignment): ?>
                                    <tr>
                                        <td><?= $imageThumb((string) ($assignment['effective_poza_stocata'] ?? ''), (string) ($assignment['effective_poza_original'] ?? '')) ?></td>
                                        <td class="fw-semibold">
                                            <?= e((string) ($assignment['catalog_nume'] ?? '-')) ?>
                                            <?php if ($isAssembly && trim((string) ($assignment['assigned_vehicle_label'] ?? '')) !== ''): ?>
                                                <span class="d-block small text-muted"><?= e((string) ($assignment['assigned_vehicle_label'] ?? '')) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= e((string) ($assignment['catalog_categorie'] ?? '-')) ?></td>
                                        <td><?= e(format_number_ro((float) ($assignment['cost'] ?? 0), 2)) ?> lei</td>
                                        <td><?= e(format_date_ro($assignment['data_fabricatiei'] ?? null)) ?></td>
                                        <td><?= e(format_date_ro($assignment['data_ultimei_inspectii'] ?? null)) ?></td>
                                        <td><?= e(format_date_ro($assignment['data_urmatoarei_inspectii'] ?? null)) ?></td>
                                        <td><?= e(format_date_ro($assignment['data_expirarii'] ?? null)) ?></td>
                                        <td><?= inventory_equipment_status_badge_html((string) ($assignment['status'] ?? 'lipsa_date')) ?></td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#inventory-edit-<?= e((string) ((int) $assignment['id'])) ?>">Editează</button>
                                            <form method="post" action="<?= e(build_query_url(['page' => 'inventar_dotari_vehicule', 'action' => 'delete_assignment'])) ?>" class="d-inline">
                                                <?= csrf_field() ?>
                                                <?php $hiddenQueryFields(); ?>
                                                <input type="hidden" name="assignment_id" value="<?= e((string) ((int) $assignment['id'])) ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Ștergi această dotare?">Șterge</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Închide</button>
                    <?php foreach ($unitVehicles as $unitVehicle): ?>
                        <?php $unitVehicleId = (int) ($unitVehicle['vehicle_id'] ?? $unitVehicle['id'] ?? 0); ?>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#inventory-add-<?= e((string) $unitVehicleId) ?>">
                            Adaugă <?= $isAssembly ? e((string) ($unitVehicle['unit_role'] ?? 'Dotare')) : 'Dotare' ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <?php foreach ($unitVehicles as $unitVehicle): ?>
        <?php $assignmentForm($unitVehicle, null); ?>
    <?php endforeach; ?>
    <?php foreach ($assignments as $assignment): ?>
        <?php $assignmentForm($row, $assignment); ?>
    <?php endforeach; ?>
<?php endforeach; ?>

<?php $inventoryScriptVersion = (string) @filemtime(BASE_PATH . '/assets/js/inventar-dotari.js'); ?>
<script src="<?= e(url('assets/js/inventar-dotari.js?v=' . $inventoryScriptVersion)) ?>"></script>
