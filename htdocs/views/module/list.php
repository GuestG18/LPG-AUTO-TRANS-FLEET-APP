<?php
$baseQuery = [
    'page' => $moduleKey,
    'action' => 'index',
    'q' => $search,
];

foreach ($filters as $filterKey => $filterValue) {
    $baseQuery[$filterKey] = $filterValue;
}

$documentSummary = $documentSummary ?? null;
$urgentDocuments = $urgentDocuments ?? [];
$isVehicleList = $moduleKey === 'vehicule';
$isDocumentList = $moduleKey === 'documente';
$isMaintenanceList = $moduleKey === 'mentenanta';
$isFuelList = $moduleKey === 'alimentari';
$fuelConsumptionSummary = is_array($fuelConsumptionSummary ?? null) ? $fuelConsumptionSummary : null;
$maintenanceTireStockContext = $maintenanceTireStockContext ?? null;
$hasMultiselectFilters = false;
foreach ($module['filters'] ?? [] as $filterMeta) {
    if ((string) ($filterMeta['type'] ?? '') === 'multiselect') {
        $hasMultiselectFilters = true;
        break;
    }
}
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h2 class="h4 mb-0"><?= e($module['title']) ?></h2>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="<?= e(build_query_url(array_merge($baseQuery, ['action' => 'export']))) ?>">Export CSV</a>
        <a class="btn btn-primary" href="<?= e(build_query_url(['page' => $moduleKey, 'action' => 'create'])) ?>">Adaugă <?= e($module['singular']) ?></a>
    </div>
</div>

<?php if ($moduleKey === 'documente' && is_array($documentSummary)): ?>
    <?php
    $documentQuickBase = [
        'page' => 'documente',
        'action' => 'index',
        'q' => $search,
        'vehicle_id' => $filters['vehicle_id'] ?? '',
        'are_fisier' => $filters['are_fisier'] ?? '',
        'data_start' => $filters['data_start'] ?? '',
        'data_end' => $filters['data_end'] ?? '',
    ];
    ?>
    <div class="row g-3 mb-3">
        <div class="col-12 col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm document-alert-card alert-expired h-100">
                <div class="card-body">
                    <div class="small text-uppercase fw-semibold mb-2">Expirate</div>
                    <div class="display-6 fw-bold mb-2"><?= e((string) ($documentSummary['expirate'] ?? 0)) ?></div>
                    <a class="btn btn-sm btn-outline-danger" href="<?= e(build_query_url(array_merge($documentQuickBase, ['stare_expirare' => 'expirate']))) ?>">Vezi documentele</a>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm document-alert-card alert-7days h-100">
                <div class="card-body">
                    <div class="small text-uppercase fw-semibold mb-2">Expiră în 7 zile</div>
                    <div class="display-6 fw-bold mb-2"><?= e((string) ($documentSummary['expira_7_zile'] ?? 0)) ?></div>
                    <a class="btn btn-sm btn-outline-warning" href="<?= e(build_query_url(array_merge($documentQuickBase, ['stare_expirare' => 'expira_7_zile']))) ?>">Filtru rapid</a>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm document-alert-card alert-30days h-100">
                <div class="card-body">
                    <div class="small text-uppercase fw-semibold mb-2">Expiră în 30 zile</div>
                    <div class="display-6 fw-bold mb-2"><?= e((string) ($documentSummary['expira_30_zile'] ?? 0)) ?></div>
                    <a class="btn btn-sm btn-outline-primary" href="<?= e(build_query_url(array_merge($documentQuickBase, ['stare_expirare' => 'expira_30_zile']))) ?>">Filtru rapid</a>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm document-alert-card alert-missing-file h-100">
                <div class="card-body">
                    <div class="small text-uppercase fw-semibold mb-2">Fără fișier</div>
                    <div class="display-6 fw-bold mb-2"><?= e((string) ($documentSummary['fara_fisier'] ?? 0)) ?></div>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(build_query_url(array_merge($documentQuickBase, ['are_fisier' => 'nu']))) ?>">Vezi lipsurile</a>
                </div>
            </div>
        </div>
    </div>

    <?php if ($urgentDocuments !== []): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white">
                <h3 class="h6 mb-0">Notificări documente</h3>
            </div>
            <div class="card-body">
                <div class="list-group list-group-flush">
                    <?php foreach ($urgentDocuments as $document): ?>
                        <div class="list-group-item px-0">
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <div>
                                    <div class="fw-semibold"><?= e($document['vehicul_label']) ?> - <?= e($document['tip_document']) ?></div>
                                    <div class="small text-muted">
                                        <?php if (!empty($document['numar_document'])): ?>
                                            <?= e($document['numar_document']) ?> |
                                        <?php endif; ?>
                                        <?= expiry_badge_html($document['data_expirare']) ?>
                                    </div>
                                </div>
                                <a class="btn btn-sm btn-outline-secondary" href="<?= e(build_query_url(['page' => 'documente', 'action' => 'show', 'id' => (int) $document['id']])) ?>">Detalii</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($isMaintenanceList && is_array($maintenanceTireStockContext)): ?>
    <?php
    $stockTotals = is_array($maintenanceTireStockContext['totals'] ?? null) ? $maintenanceTireStockContext['totals'] : [];
    $stockStatusCounts = is_array($maintenanceTireStockContext['status_counts'] ?? null) ? $maintenanceTireStockContext['status_counts'] : [];
    $stockNeedsByType = is_array($maintenanceTireStockContext['needs_by_type'] ?? null) ? $maintenanceTireStockContext['needs_by_type'] : [];
    $stockVehicleNeeds = is_array($maintenanceTireStockContext['vehicle_needs'] ?? null) ? $maintenanceTireStockContext['vehicle_needs'] : [];
    $stockPreview = is_array($maintenanceTireStockContext['stock_preview'] ?? null) ? $maintenanceTireStockContext['stock_preview'] : [];
    $targetTypeOptions = is_array($maintenanceTireStockContext['target_type_options'] ?? null) ? $maintenanceTireStockContext['target_type_options'] : [];
    ?>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h3 class="h6 mb-0">Stoc anvelope in Mentenanta</h3>
            <small class="text-muted">Flux recomandat: adaugi in stoc aici, apoi montezi din Detalii Vehicul.</small>
        </div>
        <div class="card-body">
            <div class="row g-3 mb-3">
                <div class="col-12 col-md-4 col-xl-2">
                    <div class="border rounded p-3 h-100">
                        <div class="small text-muted">Vehicule active</div>
                        <div class="h4 mb-0"><?= e((string) ((int) ($stockTotals['active_vehicles'] ?? 0))) ?></div>
                    </div>
                </div>
                <div class="col-12 col-md-4 col-xl-2">
                    <div class="border rounded p-3 h-100">
                        <div class="small text-muted">Anvelope necesare</div>
                        <div class="h4 mb-0"><?= e((string) ((int) ($stockTotals['expected_tires'] ?? 0))) ?></div>
                    </div>
                </div>
                <div class="col-12 col-md-4 col-xl-2">
                    <div class="border rounded p-3 h-100">
                        <div class="small text-muted">Montate acum</div>
                        <div class="h4 mb-0"><?= e((string) ((int) ($stockTotals['mounted_tires'] ?? 0))) ?></div>
                    </div>
                </div>
                <div class="col-12 col-md-4 col-xl-2">
                    <div class="border rounded p-3 h-100">
                        <div class="small text-muted">Lipsa totala</div>
                        <div class="h4 mb-0 text-danger"><?= e((string) ((int) ($stockTotals['missing_tires'] ?? 0))) ?></div>
                    </div>
                </div>
                <div class="col-12 col-md-4 col-xl-2">
                    <div class="border rounded p-3 h-100">
                        <div class="small text-muted">Stoc montabil</div>
                        <div class="h4 mb-0 text-success"><?= e((string) ((int) ($stockTotals['ready_mountable_total'] ?? 0))) ?></div>
                    </div>
                </div>
                <div class="col-12 col-md-4 col-xl-2">
                    <div class="border rounded p-3 h-100">
                        <div class="small text-muted">Stoc liber total</div>
                        <div class="h4 mb-0"><?= e((string) ((int) ($stockTotals['ready_stock_total'] ?? 0))) ?></div>
                    </div>
                </div>
            </div>

            <details class="maintenance-stock-details mb-3" open>
                <summary class="maintenance-stock-summary">Adaugare in stoc (simplificat)</summary>
                <div class="pt-3">
                    <div class="small text-muted mb-3">
                        Foloseste <strong>Adaugare rapida</strong> pentru loturi mici sau <strong>Generare bulk</strong> pentru completarea necesarului pe tipuri de vehicul.
                    </div>
                    <div class="row g-3">
                        <div class="col-12 col-xl-6">
                            <details class="maintenance-stock-form-details" open>
                                <summary class="maintenance-stock-form-summary">1) Adaugare rapida in stoc</summary>
                                <div class="pt-3">
                                    <form method="post" action="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'add_tire_stock'])) ?>" class="row g-2">
                                        <?= csrf_field() ?>
                                        <div class="col-12 col-md-6">
                                            <label class="form-label" for="stock_brand">Brand *</label>
                                            <input type="text" class="form-control" id="stock_brand" name="stock_brand" maxlength="100" required>
                                        </div>
                                        <div class="col-12 col-md-3">
                                            <label class="form-label" for="stock_quantity">Cantitate *</label>
                                            <input type="number" class="form-control" id="stock_quantity" name="stock_quantity" min="1" max="1000" value="1" required>
                                        </div>
                                        <div class="col-12 col-md-3">
                                            <label class="form-label" for="stock_mount_date">Data *</label>
                                            <input type="date" class="form-control" id="stock_mount_date" name="stock_mount_date" value="<?= e(date('Y-m-d')) ?>" required>
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <label class="form-label" for="stock_target_vehicle_type">Compatibil cu</label>
                                            <select class="form-select" id="stock_target_vehicle_type" name="stock_target_vehicle_type">
                                                <?php foreach ($targetTypeOptions as $typeValue => $typeLabel): ?>
                                                    <option value="<?= e((string) $typeValue) ?>"><?= e((string) $typeLabel) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <label class="form-label" for="stock_status">Stare anvelopa</label>
                                            <select class="form-select" id="stock_status" name="stock_status">
                                                <option value="spare">Rezerva</option>
                                                <option value="retreaded">Resapata</option>
                                                <option value="damaged">Deteriorata</option>
                                                <option value="removed">Scoasa din uz</option>
                                            </select>
                                        </div>

                                        <div class="col-12">
                                            <details class="maintenance-stock-form-details">
                                                <summary class="maintenance-stock-form-summary">Campuri optionale</summary>
                                                <div class="pt-3">
                                                    <div class="row g-2">
                                                        <div class="col-12 col-md-6">
                                                            <label class="form-label" for="stock_model">Model</label>
                                                            <input type="text" class="form-control" id="stock_model" name="stock_model" maxlength="120">
                                                        </div>
                                                        <div class="col-12 col-md-6">
                                                            <label class="form-label" for="stock_tire_size">Dimensiune</label>
                                                            <input type="text" class="form-control" id="stock_tire_size" name="stock_tire_size" maxlength="50" placeholder="Ex: 315/80 R22.5">
                                                        </div>
                                                        <div class="col-12 col-md-6">
                                                            <label class="form-label" for="stock_dot_code">DOT</label>
                                                            <input type="text" class="form-control" id="stock_dot_code" name="stock_dot_code" maxlength="20" placeholder="Ex: 3423">
                                                        </div>
                                                        <div class="col-12 col-md-6">
                                                            <label class="form-label" for="stock_serial_prefix">Prefix serie</label>
                                                            <input type="text" class="form-control" id="stock_serial_prefix" name="stock_serial_prefix" maxlength="36" value="STOC">
                                                        </div>
                                                        <div class="col-12 col-md-4">
                                                            <label class="form-label" for="stock_km_initial">Km la intrare</label>
                                                            <input type="number" class="form-control" id="stock_km_initial" name="stock_km_initial" min="0" step="1" value="0">
                                                        </div>
                                                        <div class="col-12 col-md-4">
                                                            <label class="form-label" for="stock_estimated_life_km">Durata de viata estimata (km)</label>
                                                            <input type="number" class="form-control" id="stock_estimated_life_km" name="stock_estimated_life_km" min="0" step="1" placeholder="Ex: 180000">
                                                        </div>
                                                        <div class="col-12">
                                                            <label class="form-label" for="stock_notes">Observatii</label>
                                                            <textarea class="form-control" id="stock_notes" name="stock_notes" rows="2" placeholder="Ex: lot rezerva pentru sezon iarna"></textarea>
                                                        </div>
                                                    </div>
                                                </div>
                                            </details>
                                        </div>

                                        <div class="col-12">
                                            <button type="submit" class="btn btn-primary">Adauga in stoc</button>
                                        </div>
                                    </form>
                                </div>
                            </details>
                        </div>

                        <div class="col-12 col-xl-6">
                            <details class="maintenance-stock-form-details">
                                <summary class="maintenance-stock-form-summary">2) Generare bulk pentru vehicule active</summary>
                                <div class="pt-3">
                                    <form method="post" action="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'bulk_tire_stock'])) ?>" class="row g-2">
                                        <?= csrf_field() ?>
                                        <div class="col-12 col-md-6">
                                            <label class="form-label" for="bulk_brand">Brand *</label>
                                            <input type="text" class="form-control" id="bulk_brand" name="bulk_brand" maxlength="100" required>
                                        </div>
                                        <div class="col-12 col-md-3">
                                            <label class="form-label" for="bulk_mount_date">Data *</label>
                                            <input type="date" class="form-control" id="bulk_mount_date" name="bulk_mount_date" value="<?= e(date('Y-m-d')) ?>" required>
                                        </div>
                                        <div class="col-12 col-md-3">
                                            <label class="form-label" for="bulk_status">Stare anvelopa</label>
                                            <select class="form-select" id="bulk_status" name="bulk_status">
                                                <option value="spare">Rezerva</option>
                                                <option value="retreaded">Resapata</option>
                                            </select>
                                        </div>
                                        <div class="col-12">
                                            <div class="small text-muted mb-2">Bifeaza tipurile si seteaza cate rezerve extra vrei peste lipsa curenta.</div>
                                            <div class="row g-2">
                                                <?php foreach ($stockNeedsByType as $needRow): ?>
                                                    <?php
                                                    $typeValue = (string) ($needRow['vehicle_type'] ?? '');
                                                    $typeLabel = (string) ($needRow['vehicle_type_label'] ?? $typeValue);
                                                    $missingCount = (int) ($needRow['missing_tires'] ?? 0);
                                                    $recommendedAdd = (int) ($needRow['recommended_to_add'] ?? 0);
                                                    ?>
                                                    <div class="col-12">
                                                        <div class="d-flex flex-wrap align-items-center gap-2 border rounded px-2 py-2">
                                                            <label class="form-check d-flex align-items-center gap-2 mb-0">
                                                                <input class="form-check-input mt-0" type="checkbox" name="bulk_vehicle_types[]" value="<?= e($typeValue) ?>" <?= $missingCount > 0 ? 'checked' : '' ?>>
                                                                <span class="fw-semibold"><?= e($typeLabel) ?></span>
                                                            </label>
                                                            <span class="badge text-bg-light border">Lipsa: <?= e((string) $missingCount) ?></span>
                                                            <span class="badge text-bg-light border">Recomandat: <?= e((string) $recommendedAdd) ?></span>
                                                            <div class="ms-auto d-flex align-items-center gap-2">
                                                                <label class="small text-muted mb-0" for="<?= e('bulk_spare_extra_' . $typeValue) ?>">Rezerve extra</label>
                                                                <input type="number" class="form-control form-control-sm" style="width:96px;" id="<?= e('bulk_spare_extra_' . $typeValue) ?>" name="bulk_spare_extra[<?= e($typeValue) ?>]" min="0" step="1" value="0">
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>

                                        <div class="col-12">
                                            <details class="maintenance-stock-form-details">
                                                <summary class="maintenance-stock-form-summary">Campuri optionale</summary>
                                                <div class="pt-3">
                                                    <div class="row g-2">
                                                        <div class="col-12 col-md-6">
                                                            <label class="form-label" for="bulk_model">Model</label>
                                                            <input type="text" class="form-control" id="bulk_model" name="bulk_model" maxlength="120">
                                                        </div>
                                                        <div class="col-12 col-md-6">
                                                            <label class="form-label" for="bulk_tire_size">Dimensiune</label>
                                                            <input type="text" class="form-control" id="bulk_tire_size" name="bulk_tire_size" maxlength="50" placeholder="Ex: 315/80 R22.5">
                                                        </div>
                                                        <div class="col-12 col-md-6">
                                                            <label class="form-label" for="bulk_serial_prefix">Prefix serie</label>
                                                            <input type="text" class="form-control" id="bulk_serial_prefix" name="bulk_serial_prefix" maxlength="36" value="BULK">
                                                        </div>
                                                        <div class="col-12 col-md-6">
                                                            <label class="form-label" for="bulk_estimated_life_km">Durata de viata estimata (km)</label>
                                                            <input type="number" class="form-control" id="bulk_estimated_life_km" name="bulk_estimated_life_km" min="0" step="1">
                                                        </div>
                                                        <div class="col-12">
                                                            <label class="form-label" for="bulk_notes">Observatii</label>
                                                            <textarea class="form-control" id="bulk_notes" name="bulk_notes" rows="2" placeholder="Ex: lot nou pentru sezon vara"></textarea>
                                                        </div>
                                                    </div>
                                                </div>
                                            </details>
                                        </div>

                                        <div class="col-12">
                                            <button type="submit" class="btn btn-primary">Genereaza lot bulk</button>
                                        </div>
                                    </form>
                                </div>
                            </details>
                        </div>
                    </div>
                </div>
            </details>

            <details class="maintenance-stock-details mb-3" open>
                <summary class="maintenance-stock-summary">Stoc liber + necesar rapid</summary>
                <div class="pt-3">
                    <div class="row g-3 mt-1">
                        <div class="col-12 col-xl-5">
                            <h4 class="h6 mt-1">Necesar pe tip vehicul</h4>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                    <tr>
                                        <th>Tip</th>
                                        <th>Vehicule</th>
                                        <th>Necesar</th>
                                        <th>Montate</th>
                                        <th>Lipsa</th>
                                        <th>Stoc tip</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php if ($stockNeedsByType === []): ?>
                                        <tr>
                                            <td colspan="6" class="text-muted text-center py-3">Nu exista vehicule active.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($stockNeedsByType as $needRow): ?>
                                            <tr>
                                                <td><?= e((string) ($needRow['vehicle_type_label'] ?? '-')) ?></td>
                                                <td><?= e((string) ((int) ($needRow['vehicles_count'] ?? 0))) ?></td>
                                                <td><?= e((string) ((int) ($needRow['expected_tires'] ?? 0))) ?></td>
                                                <td><?= e((string) ((int) ($needRow['mounted_tires'] ?? 0))) ?></td>
                                                <td class="<?= (int) ($needRow['missing_tires'] ?? 0) > 0 ? 'text-danger fw-semibold' : '' ?>">
                                                    <?= e((string) ((int) ($needRow['missing_tires'] ?? 0))) ?>
                                                </td>
                                                <td><?= e((string) ((int) ($needRow['ready_stock_for_type'] ?? 0))) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="col-12 col-xl-7">
                            <h4 class="h6 mt-1">Anvelope disponibile in stoc</h4>
                            <div class="small text-muted mb-2">
                                Rezerva: <?= e((string) ((int) ($stockStatusCounts['spare'] ?? 0))) ?> |
                                Resapata: <?= e((string) ((int) ($stockStatusCounts['retreaded'] ?? 0))) ?> |
                                Deteriorata: <?= e((string) ((int) ($stockStatusCounts['damaged'] ?? 0))) ?> |
                                Scoasa din uz: <?= e((string) ((int) ($stockStatusCounts['removed'] ?? 0))) ?>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                    <tr>
                                        <th>Serie</th>
                                        <th>Anvelopa</th>
                                        <th>Compatibil cu</th>
                                        <th>Stare</th>
                                        <th>Observatii</th>
                                        <th class="text-end">Actiuni</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php if ($stockPreview === []): ?>
                                        <tr>
                                            <td colspan="6" class="text-muted text-center py-3">Nu exista stoc liber.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($stockPreview as $stockRow): ?>
                                            <?php
                                            $stockTireId = (int) ($stockRow['id'] ?? 0);
                                            $editRowId = 'stock-edit-row-' . $stockTireId;
                                            $stockNotes = trim((string) ($stockRow['notes'] ?? ''));
                                            ?>
                                            <tr>
                                                <td class="small"><?= e((string) ($stockRow['serial_number'] ?? '-')) ?></td>
                                                <td>
                                                    <?= e(trim((string) (($stockRow['brand'] ?? '') . ' ' . ($stockRow['model'] ?? '')))) ?>
                                                    <?php if (!empty($stockRow['tire_size'])): ?>
                                                        <div class="small text-muted"><?= e((string) $stockRow['tire_size']) ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= e(vehicle_type_label((string) ($stockRow['target_vehicle_type'] ?? 'autovehicul'))) ?></td>
                                                <td><?= tire_status_badge_html((string) ($stockRow['status'] ?? '')) ?></td>
                                                <td class="small"><?= $stockNotes !== '' ? nl2br(e($stockNotes)) : '<span class="text-muted">-</span>' ?></td>
                                                <td class="text-end">
                                                    <div class="d-inline-flex gap-1">
                                                        <?php if ((int) ($stockRow['mentenanta_id'] ?? 0) > 0): ?>
                                                            <a class="btn btn-sm btn-outline-secondary" href="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'edit', 'id' => (int) $stockRow['mentenanta_id']])) ?>">
                                                                Cheltuiala
                                                            </a>
                                                        <?php endif; ?>
                                                        <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="<?= e('#' . $editRowId) ?>" aria-expanded="false" aria-controls="<?= e($editRowId) ?>">
                                                            Editeaza
                                                        </button>
                                                        <form method="post" action="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'delete_tire_stock'])) ?>" class="d-inline">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="stock_tire_id" value="<?= e((string) $stockTireId) ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Sigur vrei sa stergi anvelopa din stoc?">
                                                                Sterge
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                            <tr class="collapse" id="<?= e($editRowId) ?>">
                                                <td colspan="6" class="bg-light-subtle">
                                                    <form method="post" action="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'update_tire_stock'])) ?>" class="row g-2 p-2">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="stock_tire_id" value="<?= e((string) $stockTireId) ?>">

                                                        <div class="col-12 col-md-4">
                                                            <label class="form-label mb-1">Brand *</label>
                                                            <input type="text" class="form-control form-control-sm" name="stock_edit_brand" value="<?= e((string) ($stockRow['brand'] ?? '')) ?>" maxlength="100" required>
                                                        </div>
                                                        <div class="col-12 col-md-4">
                                                            <label class="form-label mb-1">Model</label>
                                                            <input type="text" class="form-control form-control-sm" name="stock_edit_model" value="<?= e((string) ($stockRow['model'] ?? '')) ?>" maxlength="120">
                                                        </div>
                                                        <div class="col-12 col-md-4">
                                                            <label class="form-label mb-1">Dimensiune</label>
                                                            <input type="text" class="form-control form-control-sm" name="stock_edit_tire_size" value="<?= e((string) ($stockRow['tire_size'] ?? '')) ?>" maxlength="50">
                                                        </div>
                                                        <div class="col-12 col-md-3">
                                                            <label class="form-label mb-1">DOT</label>
                                                            <input type="text" class="form-control form-control-sm" name="stock_edit_dot_code" value="<?= e((string) ($stockRow['dot_code'] ?? '')) ?>" maxlength="20">
                                                        </div>
                                                        <div class="col-12 col-md-3">
                                                            <label class="form-label mb-1">Stare anvelopa</label>
                                                            <select class="form-select form-select-sm" name="stock_edit_status">
                                                                <?php $currentStatus = strtolower(trim((string) ($stockRow['status'] ?? 'spare'))); ?>
                                                                <?php foreach (['spare' => 'Rezerva', 'retreaded' => 'Resapata', 'damaged' => 'Deteriorata', 'removed' => 'Scoasa din uz', 'active' => 'Montata'] as $statusValue => $statusLabel): ?>
                                                                    <option value="<?= e($statusValue) ?>" <?= $currentStatus === $statusValue ? 'selected' : '' ?>><?= e($statusLabel) ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <div class="col-12 col-md-3">
                                                            <label class="form-label mb-1">Compatibil cu</label>
                                                            <select class="form-select form-select-sm" name="stock_edit_target_vehicle_type">
                                                                <?php $currentTargetType = (string) ($stockRow['target_vehicle_type'] ?? 'universal'); ?>
                                                                <?php foreach ($targetTypeOptions as $typeValue => $typeLabel): ?>
                                                                    <option value="<?= e((string) $typeValue) ?>" <?= $currentTargetType === (string) $typeValue ? 'selected' : '' ?>><?= e((string) $typeLabel) ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <div class="col-12 col-md-3">
                                                            <label class="form-label mb-1">Data montaj</label>
                                                            <input type="date" class="form-control form-control-sm" name="stock_edit_mount_date" value="<?= e((string) ($stockRow['mount_date'] ?? date('Y-m-d'))) ?>">
                                                        </div>
                                                        <div class="col-12 col-md-3">
                                                            <label class="form-label mb-1">Durata estimata (km)</label>
                                                            <input type="number" class="form-control form-control-sm" name="stock_edit_estimated_life_km" min="0" step="1" value="<?= e((string) ($stockRow['estimated_life_km'] ?? '')) ?>">
                                                        </div>
                                                        <div class="col-12">
                                                            <label class="form-label mb-1">Observatii</label>
                                                            <textarea class="form-control form-control-sm" name="stock_edit_notes" rows="2"><?= e((string) ($stockRow['notes'] ?? '')) ?></textarea>
                                                        </div>
                                                        <div class="col-12">
                                                            <button type="submit" class="btn btn-sm btn-primary">Salveaza modificari</button>
                                                        </div>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </details>

            <h4 class="h6 mt-4">Vehicule active cu lipsa anvelope</h4>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                    <tr>
                        <th>Nr. inmatriculare</th>
                        <th>Tip</th>
                        <th>Configuratie</th>
                        <th>Necesar</th>
                        <th>Montate</th>
                        <th>Lipsa</th>
                        <th class="text-end">Actiune</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php
                    $visibleVehicleNeeds = array_values(array_filter($stockVehicleNeeds, static fn(array $row): bool => (int) ($row['missing_tires'] ?? 0) > 0));
                    ?>
                    <?php if ($visibleVehicleNeeds === []): ?>
                        <tr>
                            <td colspan="7" class="text-muted text-center py-3">Toate vehiculele active au pozitiile ocupate.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach (array_slice($visibleVehicleNeeds, 0, 25) as $vehicleNeed): ?>
                            <tr>
                                <td><?= e((string) ($vehicleNeed['nr_inmatriculare'] ?? '-')) ?></td>
                                <td><?= e((string) ($vehicleNeed['vehicle_type_label'] ?? '-')) ?></td>
                                <td><?= e((string) ($vehicleNeed['layout_value'] ?? '-')) ?></td>
                                <td><?= e((string) ((int) ($vehicleNeed['expected_tires'] ?? 0))) ?></td>
                                <td><?= e((string) ((int) ($vehicleNeed['mounted_tires'] ?? 0))) ?></td>
                                <td class="text-danger fw-semibold"><?= e((string) ((int) ($vehicleNeed['missing_tires'] ?? 0))) ?></td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-primary" href="<?= e(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => (int) ($vehicleNeed['vehicle_id'] ?? 0)])) ?>">Monteaza din stoc</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($isFuelList): ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div>
                    <h3 class="h6 mb-1">Consum mediu combustibil (filtre curente)</h3>
                    <?php if ($fuelConsumptionSummary === null): ?>
                        <div class="text-muted">Nu sunt suficiente date pentru calcul (ai nevoie de cel putin 2 alimentari pe vehicul, cu Km alimentare crescator).</div>
                    <?php else: ?>
                        <div class="display-6 fw-semibold mb-1">
                            <?= e(format_number_ro((float) ($fuelConsumptionSummary['average_l_per_100km'] ?? 0), 2)) ?> L/100km
                        </div>
                        <div class="text-muted small">
                            Distanta: <?= e(format_number_ro((float) ($fuelConsumptionSummary['total_distance_km'] ?? 0), 0)) ?> km |
                            Combustibil: <?= e(format_number_ro((float) ($fuelConsumptionSummary['total_fuel_liters'] ?? 0), 2)) ?> L |
                            Intervale: <?= e((string) ((int) ($fuelConsumptionSummary['interval_count'] ?? 0))) ?> |
                            Vehicule: <?= e((string) ((int) ($fuelConsumptionSummary['vehicle_count'] ?? 0))) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <input type="hidden" name="page" value="<?= e($moduleKey) ?>">
            <input type="hidden" name="action" value="index">

            <div class="col-12 col-md-4">
                <label for="q" class="form-label">Cautare</label>
                <?php $searchPlaceholder = $moduleKey === 'vehicule' ? 'Nr. inmatriculare (ex: B 677 NET, B 218 NET)' : 'Cauta in tabel...'; ?>
                <input type="text" name="q" id="q" class="form-control" value="<?= e($search) ?>" placeholder="<?= e($searchPlaceholder) ?>">
            </div>

            <?php foreach ($module['filters'] ?? [] as $filterKey => $filterMeta): ?>
                <div class="col-12 col-md-3">
                    <?php $filterType = (string) ($filterMeta['type'] ?? 'text'); ?>
                    <?php $filterLabelFor = $filterType === 'multiselect' ? $filterKey . '_toggle' : $filterKey; ?>
                    <label class="form-label" for="<?= e($filterLabelFor) ?>"><?= e($filterMeta['label']) ?></label>
                    <?php if ($filterType === 'select'): ?>
                        <select class="form-select" id="<?= e($filterKey) ?>" name="<?= e($filterKey) ?>">
                            <option value="">Toate</option>
                            <?php foreach (($filterOptions[$filterKey] ?? []) as $optionValue => $optionLabel): ?>
                                <option value="<?= e((string) $optionValue) ?>" <?= (string) ($filters[$filterKey] ?? '') === (string) $optionValue ? 'selected' : '' ?>>
                                    <?= e((string) $optionLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php elseif ($filterType === 'multiselect'): ?>
                        <?php
                        $selectedValues = is_array($filters[$filterKey] ?? null) ? array_map('strval', $filters[$filterKey]) : [];
                        $selectedLabels = [];
                        foreach (($filterOptions[$filterKey] ?? []) as $optionValue => $optionLabel) {
                            if (in_array((string) $optionValue, $selectedValues, true)) {
                                $selectedLabels[] = (string) $optionLabel;
                            }
                        }
                        $defaultLabel = 'Toate';
                        $buttonLabel = $selectedLabels === [] ? $defaultLabel : implode(', ', $selectedLabels);
                        ?>
                        <div class="dropdown vehicle-multiselect-dropdown module-filter-multiselect-dropdown" data-role="module-filter-multiselect">
                            <button class="btn btn-outline-secondary dropdown-toggle w-100 text-start vehicle-multiselect-toggle" type="button" id="<?= e($filterKey) ?>_toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                                <span class="vehicle-multiselect-label module-filter-multiselect-label" data-default-label="<?= e($defaultLabel) ?>"><?= e($buttonLabel) ?></span>
                            </button>
                            <div class="dropdown-menu w-100 p-2 vehicle-multiselect-menu module-filter-multiselect-menu" aria-labelledby="<?= e($filterKey) ?>_toggle">
                                <?php foreach (($filterOptions[$filterKey] ?? []) as $optionValue => $optionLabel): ?>
                                    <?php
                                    $optionValueString = (string) $optionValue;
                                    $optionLabelString = (string) $optionLabel;
                                    $checkboxId = $filterKey . '_opt_' . substr(md5($optionValueString), 0, 10);
                                    ?>
                                    <label class="dropdown-item d-flex align-items-center gap-2 px-2 py-1 vehicle-multiselect-option" for="<?= e($checkboxId) ?>">
                                        <input
                                            class="form-check-input m-0 module-filter-multiselect-input"
                                            type="checkbox"
                                            id="<?= e($checkboxId) ?>"
                                            name="<?= e($filterKey) ?>[]"
                                            value="<?= e($optionValueString) ?>"
                                            data-label="<?= e($optionLabelString) ?>"
                                            <?= in_array($optionValueString, $selectedValues, true) ? 'checked' : '' ?>
                                        >
                                        <span><?= e($optionLabelString) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php elseif ($filterType === 'date'): ?>
                        <input type="date" class="form-control" id="<?= e($filterKey) ?>" name="<?= e($filterKey) ?>" value="<?= e((string) ($filters[$filterKey] ?? '')) ?>">
                    <?php else: ?>
                        <input type="text" class="form-control" id="<?= e($filterKey) ?>" name="<?= e($filterKey) ?>" value="<?= e((string) ($filters[$filterKey] ?? '')) ?>">
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <div class="col-12 col-md-auto">
                <button type="submit" class="btn btn-primary">Aplică filtre</button>
            </div>
            <div class="col-12 col-md-auto">
                <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => $moduleKey])) ?>">Resetează</a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive module-list-table-wrap<?= $isVehicleList ? ' module-list-table-wrap-vehicule' : '' ?><?= $isDocumentList ? ' module-list-table-wrap-documente' : '' ?>">
            <table class="table table-hover align-middle mb-0 module-list-table<?= $isVehicleList ? ' module-list-table-vehicule' : '' ?><?= $isDocumentList ? ' module-list-table-documente' : '' ?>">
                <thead>
                <tr>
                    <?php foreach ($module['list_columns'] as $column => $meta): ?>
                        <th><?= e($meta['label']) ?></th>
                    <?php endforeach; ?>
                    <th class="text-end pe-3">Acțiuni</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="<?= e((string) (count($module['list_columns']) + 1)) ?>" class="text-center text-muted py-4">Nu există înregistrări.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <?php foreach ($module['list_columns'] as $column => $meta): ?>
                                <td><?= format_value_html($row[$column] ?? null, $meta, $row) ?></td>
                            <?php endforeach; ?>
                            <td class="text-end pe-3">
                                <div class="d-inline-flex gap-1">
                                    <?php if (in_array($moduleKey, ['documente', 'documente_soferi', 'mentenanta'], true) && !empty($row['fisier_stocat'])): ?>
                                        <a class="btn btn-sm btn-outline-dark" href="<?= e(build_query_url(['page' => $moduleKey, 'action' => 'preview', 'id' => (int) $row['id']])) ?>">
                                            <?= $moduleKey === 'mentenanta' ? 'Vezi factura' : 'Vezi in aplicatie' ?>
                                        </a>
                                    <?php endif; ?>
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(build_query_url(['page' => $moduleKey, 'action' => 'show', 'id' => (int) $row['id']])) ?>">Detalii</a>
                                    <a class="btn btn-sm btn-outline-primary" href="<?= e(build_query_url(['page' => $moduleKey, 'action' => 'edit', 'id' => (int) $row['id']])) ?>">Editează</a>

                                    <?php $isCurrentUser = ($moduleKey === 'utilizatori' && (int) ($row['id'] ?? 0) === (int) (current_user()['id'] ?? 0)); ?>
                                    <?php if (!$isCurrentUser): ?>
                                        <form method="post" action="<?= e(build_query_url(['page' => $moduleKey, 'action' => 'delete'])) ?>" class="d-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= e((string) $row['id']) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Sigur dorești să ștergi această înregistrare?">
                                                Șterge
                                            </button>
                                        </form>
                                    <?php endif; ?>
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
            <small class="text-muted">Total rezultate: <?= e((string) $pagination['total_rows']) ?></small>

            <?php if ($pagination['total_pages'] > 1): ?>
                <nav aria-label="Paginare">
                    <ul class="pagination pagination-sm mb-0">
                        <?php
                        $prevPage = max(1, (int) $pagination['page'] - 1);
                        $nextPage = min((int) $pagination['total_pages'], (int) $pagination['page'] + 1);
                        ?>
                        <li class="page-item <?= (int) $pagination['page'] <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= e(build_query_url(array_merge($baseQuery, ['p' => $prevPage]))) ?>">Anterior</a>
                        </li>

                        <?php for ($i = 1; $i <= (int) $pagination['total_pages']; $i++): ?>
                            <li class="page-item <?= (int) $pagination['page'] === $i ? 'active' : '' ?>">
                                <a class="page-link" href="<?= e(build_query_url(array_merge($baseQuery, ['p' => $i]))) ?>"><?= e((string) $i) ?></a>
                            </li>
                        <?php endfor; ?>

                        <li class="page-item <?= (int) $pagination['page'] >= (int) $pagination['total_pages'] ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= e(build_query_url(array_merge($baseQuery, ['p' => $nextPage]))) ?>">Următor</a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($hasMultiselectFilters): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-role="module-filter-multiselect"]').forEach(function (dropdownEl) {
        const labelEl = dropdownEl.querySelector('.module-filter-multiselect-label');
        if (!(labelEl instanceof HTMLElement)) {
            return;
        }

        const defaultLabel = labelEl.dataset.defaultLabel || 'Toate';
        const checkboxEls = dropdownEl.querySelectorAll('.module-filter-multiselect-input');

        const refreshLabel = function () {
            const selectedLabels = [];

            checkboxEls.forEach(function (checkboxEl) {
                if (!(checkboxEl instanceof HTMLInputElement) || !checkboxEl.checked) {
                    return;
                }

                const optionLabel = (checkboxEl.getAttribute('data-label') || '').trim();
                if (optionLabel !== '') {
                    selectedLabels.push(optionLabel);
                }
            });

            if (selectedLabels.length === 0) {
                labelEl.textContent = defaultLabel;
                labelEl.removeAttribute('title');
                return;
            }

            const joined = selectedLabels.join(', ');
            labelEl.textContent = joined;
            labelEl.setAttribute('title', joined);
        };

        checkboxEls.forEach(function (checkboxEl) {
            checkboxEl.addEventListener('change', refreshLabel);
        });

        refreshLabel();
    });
});
</script>
<?php endif; ?>


