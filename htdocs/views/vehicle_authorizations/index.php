<?php
$search = (string) ($search ?? '');
$vehicles = is_array($vehicles ?? null) ? $vehicles : [];
$zones = is_array($zones ?? null) ? $zones : [];
$rows = is_array($rows ?? null) ? $rows : [];
$pagination = is_array($pagination ?? null) ? $pagination : ['page' => 1, 'total_pages' => 1, 'total_rows' => 0, 'per_page' => 5];

$baseQuery = [
    'page' => 'autorizatii_vehicule',
    'q' => $search,
];

$totalRows = (int) ($pagination['total_rows'] ?? 0);
$perPage = max(1, (int) ($pagination['per_page'] ?? 5));
$currentPageNo = max(1, (int) ($pagination['page'] ?? 1));
$totalPages = max(1, (int) ($pagination['total_pages'] ?? 1));
$fromRow = $totalRows === 0 ? 0 : (($currentPageNo - 1) * $perPage) + 1;
$toRow = $totalRows === 0 ? 0 : min($totalRows, ($currentPageNo - 1) * $perPage + count($rows));

$hiddenReturnFields = function () use ($search, $currentPageNo): void {
    if ($search !== '') {
        echo '<input type="hidden" name="q" value="' . e($search) . '">' . PHP_EOL;
    }
    echo '<input type="hidden" name="p" value="' . e((string) $currentPageNo) . '">' . PHP_EOL;
};

$vehicleOptions = function (?int $selectedId = null) use ($vehicles): void {
    echo '<option value="">Selectează vehiculul</option>' . PHP_EOL;
    foreach ($vehicles as $vehicle) {
        $vehicleId = (int) ($vehicle['id'] ?? 0);
        $label = trim((string) ($vehicle['nr_inmatriculare'] ?? ''));
        $details = trim((string) (($vehicle['marca'] ?? '') . ' ' . ($vehicle['model'] ?? '')));
        if ($details !== '') {
            $label .= ' - ' . $details;
        }
        echo '<option value="' . e((string) $vehicleId) . '"' . ($selectedId === $vehicleId ? ' selected' : '') . '>' . e($label) . '</option>' . PHP_EOL;
    }
};

$zoneOptions = function (?int $selectedId = null) use ($zones): void {
    echo '<option value="">Selectează zona</option>' . PHP_EOL;
    foreach ($zones as $zone) {
        $zoneId = (int) ($zone['id'] ?? 0);
        echo '<option value="' . e((string) $zoneId) . '"' . ($selectedId === $zoneId ? ' selected' : '') . '>' . e((string) ($zone['name'] ?? '')) . '</option>' . PHP_EOL;
    }
};

$editModal = function (array $row) use ($hiddenReturnFields, $vehicleOptions, $zoneOptions): void {
    $rowId = (int) ($row['id'] ?? 0);
    $modalId = 'authorizationEditModal' . $rowId;
    ?>
    <div class="modal fade" id="<?= e($modalId) ?>" tabindex="-1" aria-labelledby="<?= e($modalId) ?>Label" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <form method="post" action="<?= e(build_query_url(['page' => 'autorizatii_vehicule', 'action' => 'update'])) ?>">
                    <?= csrf_field() ?>
                    <?php $hiddenReturnFields(); ?>
                    <input type="hidden" name="authorization_id" value="<?= e((string) $rowId) ?>">
                    <div class="modal-header">
                        <h3 class="modal-title fs-5" id="<?= e($modalId) ?>Label">Editează autorizație</h3>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="<?= e($modalId) ?>_vehicle">Nr. înmatriculare *</label>
                                <select class="form-select" id="<?= e($modalId) ?>_vehicle" name="vehicle_id" required>
                                    <?php $vehicleOptions((int) ($row['vehicle_id'] ?? 0)); ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="<?= e($modalId) ?>_type">Tip autorizație *</label>
                                <input class="form-control" id="<?= e($modalId) ?>_type" name="authorization_type" maxlength="120" required value="<?= e((string) ($row['authorization_type'] ?? '')) ?>">
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="<?= e($modalId) ?>_start">Data început *</label>
                                <input class="form-control" id="<?= e($modalId) ?>_start" type="date" name="start_date" required value="<?= e((string) ($row['start_date'] ?? '')) ?>">
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="<?= e($modalId) ?>_zone">Zona *</label>
                                <select class="form-select" id="<?= e($modalId) ?>_zone" name="zone_id" required>
                                    <?php $zoneOptions((int) ($row['zone_id'] ?? 0)); ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="<?= e($modalId) ?>_end">Data sfârșit *</label>
                                <input class="form-control" id="<?= e($modalId) ?>_end" type="date" name="end_date" required value="<?= e((string) ($row['end_date'] ?? '')) ?>">
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="<?= e($modalId) ?>_cost">Cost (RON) *</label>
                                <input class="form-control" id="<?= e($modalId) ?>_cost" type="number" name="cost" min="0" step="0.01" required value="<?= e(number_format((float) ($row['cost'] ?? 0), 2, '.', '')) ?>">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anulează</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>
                            Salvează modificările
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php
};
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h2 class="h4 mb-1">Autorizații Vehicule</h2>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?= e(build_query_url(['page' => 'dashboard'])) ?>">Dashboard</a></li>
                <li class="breadcrumb-item active" aria-current="page">Autorizații Vehicule</li>
            </ol>
        </nav>
    </div>
    <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#authorizationZoneModal">
        <i class="bi bi-geo-alt me-1" aria-hidden="true"></i>
        Adaugă zonă
    </button>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3">
        <h3 class="h6 mb-0">
            <i class="bi bi-file-earmark-plus text-primary me-2" aria-hidden="true"></i>
            Adaugă autorizație
        </h3>
    </div>
    <div class="card-body">
        <form method="post" action="<?= e(build_query_url(['page' => 'autorizatii_vehicule', 'action' => 'store'])) ?>">
            <?= csrf_field() ?>
            <?php $hiddenReturnFields(); ?>
            <div class="row g-3 align-items-end">
                <div class="col-12 col-md-6 col-xl-2">
                    <label class="form-label" for="authorization_vehicle_id">Nr. înmatriculare <span class="text-danger">*</span></label>
                    <select class="form-select" id="authorization_vehicle_id" name="vehicle_id" required>
                        <?php $vehicleOptions(); ?>
                    </select>
                </div>
                <div class="col-12 col-md-6 col-xl-2">
                    <label class="form-label" for="authorization_type">Tip autorizație <span class="text-danger">*</span></label>
                    <input class="form-control" id="authorization_type" name="authorization_type" maxlength="120" required placeholder="Ex: DN1;DN2">
                </div>
                <div class="col-12 col-md-6 col-xl-2">
                    <label class="form-label" for="authorization_start_date">Data început <span class="text-danger">*</span></label>
                    <input class="form-control" id="authorization_start_date" type="date" name="start_date" required>
                </div>
                <div class="col-12 col-md-6 col-xl-2">
                    <label class="form-label" for="authorization_zone_id">Zona <span class="text-danger">*</span></label>
                    <select class="form-select" id="authorization_zone_id" name="zone_id" required>
                        <?php $zoneOptions(); ?>
                    </select>
                </div>
                <div class="col-12 col-md-6 col-xl-2">
                    <label class="form-label" for="authorization_end_date">Data sfârșit <span class="text-danger">*</span></label>
                    <input class="form-control" id="authorization_end_date" type="date" name="end_date" required>
                </div>
                <div class="col-12 col-md-6 col-xl-2">
                    <label class="form-label" for="authorization_cost">Cost (RON) <span class="text-danger">*</span></label>
                    <input class="form-control" id="authorization_cost" type="number" name="cost" min="0" step="0.01" required placeholder="1200.00">
                </div>
            </div>
            <div class="text-end mt-4">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>
                    Adaugă autorizație
                </button>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <h3 class="h6 mb-0">
                <i class="bi bi-table text-primary me-2" aria-hidden="true"></i>
                Istoric autorizații
            </h3>
            <form method="get" class="d-flex gap-2">
                <input type="hidden" name="page" value="autorizatii_vehicule">
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="bi bi-search" aria-hidden="true"></i></span>
                    <input class="form-control" name="q" value="<?= e($search) ?>" placeholder="Caută...">
                </div>
                <button type="submit" class="btn btn-outline-secondary">Caută</button>
            </form>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 module-list-table">
                <thead>
                <tr>
                    <th>Nr. înmatriculare</th>
                    <th>Tip autorizație</th>
                    <th>Data început</th>
                    <th>Zona</th>
                    <th>Data sfârșit</th>
                    <th>Cost (RON)</th>
                    <th class="text-end pe-3">Acțiuni</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">Nu există autorizații pentru criteriile selectate.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <?php $rowId = (int) ($row['id'] ?? 0); ?>
                        <tr>
                            <td class="fw-semibold"><?= e((string) ($row['nr_inmatriculare'] ?? '-')) ?></td>
                            <td><?= e((string) ($row['authorization_type'] ?? '-')) ?></td>
                            <td><?= e(format_date_ro($row['start_date'] ?? null)) ?></td>
                            <td><?= e((string) ($row['zone_name'] ?? '-')) ?></td>
                            <td><?= e(format_date_ro($row['end_date'] ?? null)) ?></td>
                            <td><?= e(format_number_ro((float) ($row['cost'] ?? 0), 2)) ?></td>
                            <td class="text-end pe-3">
                                <div class="d-inline-flex justify-content-end gap-1">
                                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#authorizationEditModal<?= e((string) $rowId) ?>" title="Editează" aria-label="Editează">
                                        <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                    </button>
                                    <form method="post" action="<?= e(build_query_url(['page' => 'autorizatii_vehicule', 'action' => 'delete'])) ?>" class="d-inline">
                                        <?= csrf_field() ?>
                                        <?php $hiddenReturnFields(); ?>
                                        <input type="hidden" name="authorization_id" value="<?= e((string) $rowId) ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" data-confirm="Ștergi această autorizație?" title="Șterge" aria-label="Șterge">
                                            <i class="bi bi-trash" aria-hidden="true"></i>
                                        </button>
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
            <small class="text-muted">Afișând <?= e((string) $fromRow) ?> - <?= e((string) $toRow) ?> din <?= e((string) $totalRows) ?> înregistrări</small>
            <nav aria-label="Paginare autorizații vehicule">
                <ul class="pagination pagination-sm mb-0">
                    <?php
                    $prevPage = max(1, $currentPageNo - 1);
                    $nextPage = min($totalPages, $currentPageNo + 1);
                    ?>
                    <li class="page-item <?= $currentPageNo <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= e(build_query_url(array_merge($baseQuery, ['p' => $prevPage]))) ?>" <?= $currentPageNo <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>>
                            <i class="bi bi-chevron-left" aria-hidden="true"></i>
                        </a>
                    </li>
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <li class="page-item <?= $currentPageNo === $i ? 'active' : '' ?>">
                            <a class="page-link" href="<?= e(build_query_url(array_merge($baseQuery, ['p' => $i]))) ?>"><?= e((string) $i) ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $currentPageNo >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= e(build_query_url(array_merge($baseQuery, ['p' => $nextPage]))) ?>" <?= $currentPageNo >= $totalPages ? 'aria-disabled="true" tabindex="-1"' : '' ?>>
                            <i class="bi bi-chevron-right" aria-hidden="true"></i>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
    </div>
</div>

<div class="modal fade" id="authorizationZoneModal" tabindex="-1" aria-labelledby="authorizationZoneModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title fs-5" id="authorizationZoneModalLabel">Zone autorizații</h3>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
            </div>
            <div class="modal-body">
                <form method="post" action="<?= e(build_query_url(['page' => 'autorizatii_vehicule', 'action' => 'store_zone'])) ?>" class="row g-3 align-items-end mb-4">
                    <?= csrf_field() ?>
                    <?php $hiddenReturnFields(); ?>
                    <div class="col-12 col-md">
                        <label class="form-label" for="authorization_zone_name">Zonă nouă *</label>
                        <input class="form-control" id="authorization_zone_name" name="zone_name" maxlength="120" required placeholder="Ex: România">
                    </div>
                    <div class="col-12 col-md-auto">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>
                            Adaugă zonă
                        </button>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Zonă</th>
                            <th>Utilizări</th>
                            <th class="text-end">Acțiuni</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($zones === []): ?>
                            <tr>
                                <td colspan="3" class="text-center text-muted py-3">Nu există zone definite.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($zones as $zone): ?>
                                <?php
                                $zoneId = (int) ($zone['id'] ?? 0);
                                $usageCount = (int) ($zone['usage_count'] ?? 0);
                                ?>
                                <tr>
                                    <td class="fw-semibold"><?= e((string) ($zone['name'] ?? '-')) ?></td>
                                    <td><?= e((string) $usageCount) ?></td>
                                    <td class="text-end">
                                        <?php if ($usageCount > 0): ?>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" disabled title="Zona este folosită de autorizații existente">
                                                <i class="bi bi-lock" aria-hidden="true"></i>
                                            </button>
                                        <?php else: ?>
                                            <form method="post" action="<?= e(build_query_url(['page' => 'autorizatii_vehicule', 'action' => 'delete_zone'])) ?>" class="d-inline">
                                                <?= csrf_field() ?>
                                                <?php $hiddenReturnFields(); ?>
                                                <input type="hidden" name="zone_id" value="<?= e((string) $zoneId) ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Ștergi această zonă?" title="Șterge zona" aria-label="Șterge zona">
                                                    <i class="bi bi-trash" aria-hidden="true"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
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
            </div>
        </div>
    </div>
</div>

<?php foreach ($rows as $row): ?>
    <?php $editModal($row); ?>
<?php endforeach; ?>
