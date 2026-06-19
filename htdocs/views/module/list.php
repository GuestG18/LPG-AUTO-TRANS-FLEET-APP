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
$isMaintenanceTireStockPage = (bool) ($isMaintenanceTireStockPage ?? false);
$isFuelList = $moduleKey === 'alimentari';
$isDocumentCostOverrideList = $moduleKey === 'configurare_costuri_documente_vehicule_override';
$fuelConsumptionSummary = is_array($fuelConsumptionSummary ?? null) ? $fuelConsumptionSummary : null;
$maintenanceTireStockContext = $maintenanceTireStockContext ?? null;
$documentTypeVehicleOptions = is_array($documentTypeVehicleOptions ?? null) ? $documentTypeVehicleOptions : [];
$driverDocumentCostModule = is_array($driverDocumentCostModule ?? null) ? $driverDocumentCostModule : null;
$driverDocumentCostRows = is_array($driverDocumentCostRows ?? null) ? $driverDocumentCostRows : [];
$driverDocumentCostPagination = is_array($driverDocumentCostPagination ?? null) ? $driverDocumentCostPagination : null;
$driverDocumentModule = is_array($driverDocumentModule ?? null) ? $driverDocumentModule : null;
$driverDocumentRows = is_array($driverDocumentRows ?? null) ? $driverDocumentRows : [];
$driverDocumentPagination = is_array($driverDocumentPagination ?? null) ? $driverDocumentPagination : null;
$mainPaginationBaseQuery = $baseQuery;
if ($isDocumentCostOverrideList && $driverDocumentCostPagination !== null) {
    $mainPaginationBaseQuery['driver_cost_p'] = (int) ($driverDocumentCostPagination['page'] ?? 1);
}
$hasMultiselectFilters = false;
foreach ($module['filters'] ?? [] as $filterMeta) {
    if ((string) ($filterMeta['type'] ?? '') === 'multiselect') {
        $hasMultiselectFilters = true;
        break;
    }
}
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3<?= $isDocumentCostOverrideList ? ' document-cost-header' : '' ?>">
    <h2 class="h4 mb-0"><?= e($module['title']) ?></h2>
    <div class="d-flex gap-2<?= $isDocumentCostOverrideList ? ' document-cost-actions' : '' ?>">
        <?php if ($isDocumentCostOverrideList): ?>
            <div class="document-cost-action-group document-cost-action-tabs">
        <?php endif; ?>
        <?php if ($isDocumentCostOverrideList && function_exists('is_admin') && is_admin()): ?>
            <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'configurare_costuri_documente_vehicule_override', 'action' => 'manage_document_type_config'])) ?>">
                Gestionare tipuri documente
            </a>
            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addDocumentTypeConfigModal">
                Tip document nou pe tip vehicul
            </button>
        <?php endif; ?>
        <?php if ($isMaintenanceList && !$isMaintenanceTireStockPage): ?>
            <a class="btn btn-outline-primary" href="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'tire_stock'])) ?>">Stoc anvelope</a>
        <?php endif; ?>
        <?php if ($isMaintenanceTireStockPage): ?>
            <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'mentenanta'])) ?>">Inapoi la Mentenanta</a>
        <?php else: ?>
            <a class="btn btn-outline-secondary<?= $isDocumentCostOverrideList ? ' document-cost-export-btn' : '' ?>" href="<?= e(build_query_url(array_merge($baseQuery, ['action' => 'export']))) ?>">Export CSV</a>
        <?php endif; ?>
        <?php if ($isDocumentCostOverrideList): ?>
            </div>
            <div class="document-cost-action-group document-cost-action-primary">
        <?php endif; ?>
        <?php if ($isDocumentCostOverrideList && function_exists('is_admin') && is_admin() && $driverDocumentCostModule !== null): ?>
            <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'configurare_costuri_documente_soferi', 'action' => 'manage_driver_document_type_config'])) ?>">
                Gestionare tipuri documente soferi
            </a>
            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addDriverDocumentTypeConfigModal">
                Tip document nou soferi
            </button>
            <a class="btn btn-primary" href="<?= e(build_query_url(['page' => 'configurare_costuri_documente_soferi', 'action' => 'create'])) ?>">Adauga configurare cost document sofer</a>
        <?php endif; ?>
        <?php if (!$isMaintenanceTireStockPage): ?>
            <a class="btn btn-primary" href="<?= e(build_query_url(['page' => $moduleKey, 'action' => 'create'])) ?>">Adaugă <?= e($module['singular']) ?></a>
        <?php endif; ?>
        <?php if ($isDocumentCostOverrideList): ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($isDocumentCostOverrideList && function_exists('is_admin') && is_admin()): ?>
    <div class="modal fade" id="addDocumentTypeConfigModal" tabindex="-1" aria-labelledby="addDocumentTypeConfigModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post" action="<?= e(build_query_url(['page' => 'configurare_costuri_documente_vehicule_override', 'action' => 'add_document_type_config'])) ?>">
                    <?= csrf_field() ?>
                    <div class="modal-header">
                        <h3 class="modal-title fs-5" id="addDocumentTypeConfigModalLabel">Adauga tip document pe tip vehicul</h3>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Inchide"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label" for="doc_cfg_vehicle_type">Tip vehicul *</label>
                            <select class="form-select" id="doc_cfg_vehicle_type" name="doc_cfg_vehicle_type" required>
                                <?php foreach ($documentTypeVehicleOptions as $typeValue => $typeLabel): ?>
                                    <option value="<?= e((string) $typeValue) ?>"><?= e((string) $typeLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="doc_cfg_document_type">Tip document *</label>
                            <input
                                type="text"
                                class="form-control"
                                id="doc_cfg_document_type"
                                name="doc_cfg_document_type"
                                maxlength="120"
                                placeholder="Ex: CASCO"
                                required
                            >
                        </div>

                        <div class="form-text mt-2">
                            Dupa salvare, noul tip document apare automat in formularele de adaugare documente.
                        </div>

                        <div class="form-check mt-3">
                            <input class="form-check-input" type="checkbox" value="1" id="doc_cfg_requires_expiry" name="doc_cfg_requires_expiry" checked>
                            <label class="form-check-label" for="doc_cfg_requires_expiry">
                                Cere data de expirare in formularul Documente
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Renunta</button>
                        <button type="submit" class="btn btn-primary">Salveaza tipul</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="addDriverDocumentTypeConfigModal" tabindex="-1" aria-labelledby="addDriverDocumentTypeConfigModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post" action="<?= e(build_query_url(['page' => 'configurare_costuri_documente_soferi', 'action' => 'add_driver_document_type_config'])) ?>">
                    <?= csrf_field() ?>
                    <div class="modal-header">
                        <h3 class="modal-title fs-5" id="addDriverDocumentTypeConfigModalLabel">Adauga tip document soferi</h3>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Inchide"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label" for="driver_doc_cfg_document_type_modal">Tip document *</label>
                            <input
                                type="text"
                                class="form-control"
                                id="driver_doc_cfg_document_type_modal"
                                name="driver_doc_cfg_document_type"
                                maxlength="100"
                                placeholder="Ex: Aviz medical"
                                required
                            >
                        </div>

                        <div class="form-check mb-3">
                            <input type="hidden" name="driver_doc_cfg_requires_expiry" value="0">
                            <input class="form-check-input" type="checkbox" value="1" id="driver_doc_cfg_requires_expiry_modal" name="driver_doc_cfg_requires_expiry" checked>
                            <label class="form-check-label" for="driver_doc_cfg_requires_expiry_modal">
                                Cere data principala
                            </label>
                        </div>

                        <div class="form-text mt-2">
                            Dupa salvare, tipul apare automat in formularul Documente soferi si devine necesar pentru toti soferii.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Renunta</button>
                        <button type="submit" class="btn btn-primary">Salveaza tipul</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

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

<?php if ($isMaintenanceList && $isMaintenanceTireStockPage && is_array($maintenanceTireStockContext)): ?>
    <?php
    $stockTotals = is_array($maintenanceTireStockContext['totals'] ?? null) ? $maintenanceTireStockContext['totals'] : [];
    $inventoryRows = is_array($maintenanceTireStockContext['inventory_rows'] ?? null) ? $maintenanceTireStockContext['inventory_rows'] : [];
    $inventoryPagination = is_array($maintenanceTireStockContext['pagination'] ?? null) ? $maintenanceTireStockContext['pagination'] : [];
    $tireFilters = is_array($maintenanceTireStockContext['filters'] ?? null) ? $maintenanceTireStockContext['filters'] : [];
    $targetTypeOptions = is_array($maintenanceTireStockContext['target_type_options'] ?? null) ? $maintenanceTireStockContext['target_type_options'] : [];
    $targetVehicleTypeOptions = $targetTypeOptions;
    unset($targetVehicleTypeOptions['universal']);
    $targetLayoutOptionsByType = is_array($maintenanceTireStockContext['target_layout_options_by_type'] ?? null) ? $maintenanceTireStockContext['target_layout_options_by_type'] : [];
    $tireTypeOptions = is_array($maintenanceTireStockContext['tire_type_options'] ?? null) ? $maintenanceTireStockContext['tire_type_options'] : [];
    $tireAxleTypeOptions = is_array($maintenanceTireStockContext['axle_type_options'] ?? null) ? $maintenanceTireStockContext['axle_type_options'] : [];
    $tireAxleTypeFormOptions = $tireAxleTypeOptions;
    unset($tireAxleTypeFormOptions['universal_balloon']);
    $tireTypeOptionsByAxleType = is_array($maintenanceTireStockContext['tire_type_options_by_axle_type'] ?? null) ? $maintenanceTireStockContext['tire_type_options_by_axle_type'] : [];
    $tireStatusOptions = is_array($maintenanceTireStockContext['status_options'] ?? null) ? $maintenanceTireStockContext['status_options'] : [];
    $conditionOptions = is_array($maintenanceTireStockContext['condition_options'] ?? null) ? $maintenanceTireStockContext['condition_options'] : [];
    $seasonOptions = is_array($maintenanceTireStockContext['season_options'] ?? null) ? $maintenanceTireStockContext['season_options'] : [];
    $locationOptions = is_array($maintenanceTireStockContext['location_options'] ?? null) ? $maintenanceTireStockContext['location_options'] : [];
    $axleConfigOptions = is_array($maintenanceTireStockContext['axle_config_options'] ?? null) ? $maintenanceTireStockContext['axle_config_options'] : [];
    $moveTargets = is_array($maintenanceTireStockContext['move_targets'] ?? null) ? $maintenanceTireStockContext['move_targets'] : [];
    $perPage = (int) ($inventoryPagination['per_page'] ?? 10);
    $pageNo = (int) ($inventoryPagination['page'] ?? 1);
    $totalPages = (int) ($inventoryPagination['total_pages'] ?? 1);
    $totalRows = (int) ($inventoryPagination['total_rows'] ?? 0);
    $vehiclePickerCounter = 0;
    $renderVehicleCompatibilityPicker = static function (string $fieldName, array $selectedValues, bool $required = false) use ($targetVehicleTypeOptions, &$vehiclePickerCounter): void {
        $vehiclePickerCounter++;
        $pickerId = 'tireVehiclePicker' . $vehiclePickerCounter;
        $selectedMap = [];
        foreach ($selectedValues as $selectedValue) {
            $selectedMap[(string) $selectedValue] = true;
        }
        ?>
        <div class="tire-vehicle-picker dropdown" data-tire-vehicle-picker data-required="<?= $required ? '1' : '0' ?>">
            <button class="form-select tire-vehicle-picker-toggle" type="button" id="<?= e($pickerId) ?>" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                <span data-tire-vehicle-picker-label>Selecteaza tip vehicul</span>
            </button>
            <div class="dropdown-menu tire-vehicle-picker-menu" aria-labelledby="<?= e($pickerId) ?>">
                <?php foreach ($targetVehicleTypeOptions as $value => $label): ?>
                    <label class="dropdown-item tire-vehicle-picker-option">
                        <input class="form-check-input" type="checkbox" name="<?= e($fieldName) ?>" value="<?= e((string) $value) ?>" data-tire-vehicle-option <?= isset($selectedMap[(string) $value]) ? 'checked' : '' ?>>
                        <span><?= e((string) $label) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    };
    $filterBase = ['page' => 'mentenanta', 'action' => 'tire_stock'];
    foreach (['q','vehicle_type','axle_config','tire_type','status','condition','location','mounted','per_page'] as $filterKey) {
        if (isset($tireFilters[$filterKey]) && (string) $tireFilters[$filterKey] !== '') {
            $filterBase[$filterKey] = (string) $tireFilters[$filterKey];
        }
    }
    $formatKm = static function ($value): string {
        if ($value === null || $value === '') {
            return '-';
        }
        return number_format((float) $value, 0, ',', '.') . ' km';
    };
    ?>

    <style>
        .tire-inventory-wrap{display:block}
        .tire-kpi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:1rem;margin-bottom:1rem}
        .tire-kpi-card{display:flex;align-items:center;gap:1rem;background:#fff;border:1px solid #e5ebf3;border-radius:8px;padding:1.15rem 1.25rem;box-shadow:0 6px 18px rgba(15,23,42,.05)}
        .tire-kpi-icon{width:52px;height:52px;border-radius:50%;display:grid;place-items:center;font-size:1.35rem}
        .tire-kpi-blue{background:#edf4ff;color:#0d6efd}.tire-kpi-green{background:#eaf8f0;color:#168753}.tire-kpi-yellow{background:#fff6dc;color:#d29a00}.tire-kpi-red{background:#fff0f0;color:#dc3545}
        .tire-work-card{background:#fff;border:1px solid #e5ebf3;border-radius:8px;box-shadow:0 6px 18px rgba(15,23,42,.05)}
        .tire-filter-bar{display:grid;grid-template-columns:minmax(240px,1.5fr) repeat(auto-fit,minmax(130px,1fr));gap:.75rem;padding:1rem;border-bottom:1px solid #e9eef5}
        .tire-table{font-size:.875rem}.tire-table thead th{background:#f8fafc;color:#52627a;font-size:.75rem;text-transform:none;border-bottom:1px solid #e5ebf3;white-space:nowrap}.tire-table td{vertical-align:middle;border-color:#edf1f6}
        .tire-thumb{width:54px;height:54px;border-radius:12px;background:radial-gradient(circle at 50% 50%,#eef2f7 0 22%,#1f2937 24% 30%,#475569 31% 38%,#111827 40% 48%,#64748b 50% 55%,#111827 58% 100%);box-shadow:inset 0 0 0 3px #eef2f7;overflow:hidden;flex:0 0 54px}
        .tire-thumb img{width:100%;height:100%;object-fit:cover;display:block}
        .tire-thumb-missing{background:#f8fafc;border:1px dashed #cbd5e1;display:grid;place-items:center;color:#94a3b8;box-shadow:none}
        .tire-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:.4rem}.tire-dot-green{background:#16a34a}.tire-dot-yellow{background:#f6b300}.tire-dot-orange{background:#f97316}.tire-dot-red{background:#ef4444}
        .tire-progress-track{height:5px;background:#e8edf5;border-radius:999px;overflow:hidden;width:118px}.tire-progress-track>span{display:block;height:100%;border-radius:999px}.tire-progress-green{background:#16a34a}.tire-progress-yellow{background:#f6b300}.tire-progress-orange{background:#f97316}.tire-progress-red{background:#ef4444}
        .tire-status-badge{display:inline-flex;align-items:center;border-radius:6px;padding:.22rem .55rem;font-size:.75rem;font-weight:700;border:1px solid transparent;white-space:nowrap}
        .tire-status-mounted{background:#e8f8ef;color:#168753;border-color:#bde8cd}.tire-status-spare{background:#eef4ff;color:#0d6efd;border-color:#bcd2ff}.tire-status-stock{background:#f8fafc;color:#334155;border-color:#d9e2ec}.tire-status-damaged,.tire-status-missing{background:#fff1f2;color:#dc3545;border-color:#ffc9cf}.tire-status-removed,.tire-status-scrapped{background:#f1f5f9;color:#475569;border-color:#d9e2ec}
        .tire-add-modal .form-label{font-size:.78rem;font-weight:700;color:#26364f}.tire-add-modal .form-control,.tire-add-modal .form-select{font-size:.86rem}
        .tire-vehicle-picker-toggle{width:100%;text-align:left;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .tire-vehicle-picker-menu{width:100%;max-height:230px;overflow:auto;padding:.35rem}
        .tire-vehicle-picker-option{display:flex;align-items:center;gap:.5rem;border-radius:6px;padding:.45rem .5rem;cursor:pointer;white-space:normal}
        .tire-vehicle-picker-option .form-check-input{margin:0;flex:0 0 auto}
        .tire-action-btn{width:38px;height:34px;display:inline-grid;place-items:center}.tire-pagination{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:1rem;border-top:1px solid #e9eef5}
        @media (max-width:1400px){.tire-filter-bar{grid-template-columns:1fr 1fr 1fr}}
        @media (max-width:900px){.tire-kpi-grid{grid-template-columns:1fr 1fr}.tire-filter-bar{grid-template-columns:1fr}.tire-table{min-width:980px}}
    </style>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const layoutOptionsByType = <?= json_encode($targetLayoutOptionsByType, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const tireTypeOptionsByAxleType = <?= json_encode($tireTypeOptionsByAxleType, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const defaultsByType = {
            autovehicul: '4x2',
            autoutilitara: '4x2',
            camion: '4x2',
            cap_tractor: '4x2',
            semiremorca: '3 axe',
            semiremorca_primar: '3 axe',
            semiremorca_distributie: '3 axe'
        };

        const pickerSelectedOptions = function (pickerEl) {
            return Array.from(pickerEl.querySelectorAll('[data-tire-vehicle-option]:checked'));
        };

        const refreshVehiclePicker = function (pickerEl) {
            const buttonEl = pickerEl.querySelector('[data-tire-vehicle-picker-label]');
            const toggleEl = pickerEl.querySelector('.tire-vehicle-picker-toggle');
            const selectedOptions = pickerSelectedOptions(pickerEl);
            const labels = selectedOptions.map(function (inputEl) {
                const optionEl = inputEl.closest('.tire-vehicle-picker-option');
                return optionEl ? optionEl.textContent.trim() : inputEl.value;
            });

            if (buttonEl) {
                if (labels.length === 0) {
                    buttonEl.textContent = 'Selecteaza tip vehicul';
                } else if (labels.length <= 2) {
                    buttonEl.textContent = labels.join(', ');
                } else {
                    buttonEl.textContent = labels[0] + ' +' + (labels.length - 1);
                }
            }

            if (toggleEl) {
                toggleEl.title = labels.join(', ');
                if (pickerEl.dataset.required === '1' && pickerEl.dataset.touched === '1') {
                    toggleEl.classList.toggle('is-invalid', labels.length === 0);
                } else {
                    toggleEl.classList.remove('is-invalid');
                }
            }
        };

        const initializeVehiclePicker = function (pickerEl) {
            if (!(pickerEl instanceof HTMLElement) || pickerEl.dataset.initialized === '1') {
                return;
            }

            pickerEl.dataset.initialized = '1';
            pickerEl.querySelectorAll('[data-tire-vehicle-option]').forEach(function (checkboxEl) {
                checkboxEl.addEventListener('change', function () {
                    pickerEl.dataset.touched = '1';
                    refreshVehiclePicker(pickerEl);
                    pickerEl.dispatchEvent(new CustomEvent('tire-vehicle-picker-change', { bubbles: true }));
                });
            });
            refreshVehiclePicker(pickerEl);
        };

        document.querySelectorAll('[data-tire-vehicle-picker]').forEach(initializeVehiclePicker);

        document.querySelectorAll('[data-tire-compatibility-form]').forEach(function (formEl) {
            const vehicleTypeEl = formEl.querySelector('[data-tire-vehicle-type]');
            const vehiclePickerEl = formEl.querySelector('[data-tire-vehicle-picker]');
            const axleConfigEl = formEl.querySelector('[data-tire-axle-config]');
            const tireAxleTypeEl = formEl.querySelector('[data-tire-axle-type]');
            const tireTypeEl = formEl.querySelector('[data-tire-type]');

            if (vehiclePickerEl instanceof HTMLElement) {
                initializeVehiclePicker(vehiclePickerEl);
            }

            if (tireAxleTypeEl instanceof HTMLSelectElement && tireTypeEl instanceof HTMLSelectElement) {
                const initialTireTypeValue = tireTypeEl.dataset.current || tireTypeEl.value || '';

                const refreshTireTypeOptions = function (preferExisting) {
                    const selectedAxleType = tireAxleTypeEl.value || '';
                    const typedOptions = selectedAxleType !== '' && tireTypeOptionsByAxleType[selectedAxleType] && typeof tireTypeOptionsByAxleType[selectedAxleType] === 'object'
                        ? tireTypeOptionsByAxleType[selectedAxleType]
                        : {};
                    const optionValues = Object.keys(typedOptions);
                    const previousValue = preferExisting ? (tireTypeEl.value || tireTypeEl.dataset.current || initialTireTypeValue) : '';

                    tireTypeEl.innerHTML = '';

                    if (selectedAxleType === '') {
                        const placeholderEl = document.createElement('option');
                        placeholderEl.value = '';
                        placeholderEl.textContent = 'Selecteaza mai intai tipul axei';
                        tireTypeEl.appendChild(placeholderEl);
                        tireTypeEl.value = '';
                        tireTypeEl.dataset.current = '';
                        tireTypeEl.disabled = true;
                        return;
                    }

                    const placeholderEl = document.createElement('option');
                    placeholderEl.value = '';
                    placeholderEl.textContent = 'Selecteaza tipul anvelopei';
                    tireTypeEl.appendChild(placeholderEl);

                    optionValues.forEach(function (optionValue) {
                        const optionEl = document.createElement('option');
                        optionEl.value = optionValue;
                        optionEl.textContent = String(typedOptions[optionValue]);
                        tireTypeEl.appendChild(optionEl);
                    });

                    tireTypeEl.disabled = optionValues.length === 0;
                    tireTypeEl.value = previousValue !== '' && optionValues.includes(previousValue) ? previousValue : '';
                    tireTypeEl.dataset.current = tireTypeEl.value;
                };

                tireAxleTypeEl.addEventListener('change', function () {
                    tireTypeEl.dataset.current = '';
                    refreshTireTypeOptions(false);
                });
                tireTypeEl.addEventListener('change', function () {
                    tireTypeEl.dataset.current = tireTypeEl.value;
                });
                formEl.addEventListener('reset', function () {
                    window.setTimeout(function () {
                        tireTypeEl.dataset.current = initialTireTypeValue;
                        refreshTireTypeOptions(true);
                    }, 0);
                });
                refreshTireTypeOptions(true);
            }

            formEl.addEventListener('submit', function (event) {
                let firstInvalidToggle = null;
                formEl.querySelectorAll('[data-tire-vehicle-picker][data-required="1"]').forEach(function (pickerEl) {
                    pickerEl.dataset.touched = '1';
                    refreshVehiclePicker(pickerEl);
                    if (pickerSelectedOptions(pickerEl).length === 0 && firstInvalidToggle === null) {
                        firstInvalidToggle = pickerEl.querySelector('.tire-vehicle-picker-toggle');
                    }
                });

                if (firstInvalidToggle instanceof HTMLElement) {
                    event.preventDefault();
                    firstInvalidToggle.focus();
                }
            });

            if (!(axleConfigEl instanceof HTMLSelectElement) || (!(vehicleTypeEl instanceof HTMLSelectElement) && !(vehiclePickerEl instanceof HTMLElement))) {
                return;
            }

            const currentVehicleType = function () {
                if (vehicleTypeEl instanceof HTMLSelectElement) {
                    return vehicleTypeEl.value || 'autovehicul';
                }

                if (vehiclePickerEl instanceof HTMLElement) {
                    const selectedOption = pickerSelectedOptions(vehiclePickerEl)[0] || null;
                    return selectedOption ? selectedOption.value : 'autovehicul';
                }

                return 'autovehicul';
            };

            const refreshAxleOptions = function (preferExisting) {
                const selectedType = currentVehicleType();
                const typedOptions = layoutOptionsByType[selectedType] && typeof layoutOptionsByType[selectedType] === 'object'
                    ? layoutOptionsByType[selectedType]
                    : {};
                const previousValue = preferExisting ? (axleConfigEl.value || axleConfigEl.dataset.current || '') : '';
                const defaultValue = defaultsByType[selectedType] || '';
                const optionValues = Object.keys(typedOptions);
                const selectedValue = previousValue !== '' && optionValues.includes(previousValue)
                    ? previousValue
                    : (defaultValue !== '' && optionValues.includes(defaultValue) ? defaultValue : (optionValues[0] || ''));

                axleConfigEl.innerHTML = '';
                optionValues.forEach(function (optionValue) {
                    const optionEl = document.createElement('option');
                    optionEl.value = optionValue;
                    optionEl.textContent = String(typedOptions[optionValue]);
                    if (optionValue === selectedValue) {
                        optionEl.selected = true;
                    }
                    axleConfigEl.appendChild(optionEl);
                });
                axleConfigEl.disabled = optionValues.length === 0;
                axleConfigEl.dataset.current = selectedValue;
            };

            const handleVehicleTypeChange = function () {
                refreshAxleOptions(false);
            };

            if (vehicleTypeEl instanceof HTMLSelectElement) {
                vehicleTypeEl.addEventListener('change', handleVehicleTypeChange);
            }
            if (vehiclePickerEl instanceof HTMLElement) {
                vehiclePickerEl.addEventListener('tire-vehicle-picker-change', handleVehicleTypeChange);
            }
            formEl.addEventListener('reset', function () {
                window.setTimeout(function () {
                    formEl.querySelectorAll('[data-tire-vehicle-picker]').forEach(function (pickerEl) {
                        pickerEl.dataset.touched = '0';
                        refreshVehiclePicker(pickerEl);
                    });
                    refreshAxleOptions(true);
                }, 0);
            });
            refreshAxleOptions(true);
        });
    });
    </script>

    <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
        <div>
            <h3 class="h4 mb-1">Anvelope</h3>
            <div class="text-muted">Vizualizeaza si gestioneaza anvelopele din flota.</div>
        </div>
        <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#addTireModal">
            <i class="bi bi-plus-lg me-1"></i> Adauga anvelopa
        </button>
    </div>

    <div class="tire-kpi-grid">
        <div class="tire-kpi-card">
            <div class="tire-kpi-icon tire-kpi-blue"><i class="bi bi-stack"></i></div>
            <div><div class="small text-muted">Total anvelope</div><div class="h3 mb-0"><?= e((string) ((int) ($stockTotals['total_tires'] ?? 0))) ?></div><div class="small text-muted">Toate vehiculele</div></div>
        </div>
        <div class="tire-kpi-card">
            <div class="tire-kpi-icon tire-kpi-green"><i class="bi bi-check-circle"></i></div>
            <div><div class="small text-muted">Montate</div><div class="h3 mb-0"><?= e((string) ((int) ($stockTotals['mounted_tires'] ?? 0))) ?></div><div class="small text-muted"><?= (int) ($stockTotals['total_tires'] ?? 0) > 0 ? e(number_format(((int) ($stockTotals['mounted_tires'] ?? 0) / max(1, (int) ($stockTotals['total_tires'] ?? 0))) * 100, 1, ',', '.')) . '% din total' : '0% din total' ?></div></div>
        </div>
        <div class="tire-kpi-card">
            <div class="tire-kpi-icon tire-kpi-yellow"><i class="bi bi-exclamation-triangle"></i></div>
            <div><div class="small text-muted">Lipsa / Necesare</div><div class="h3 mb-0"><?= e((string) ((int) ($stockTotals['missing_tires'] ?? 0))) ?></div><div class="small text-muted">Pozitii neacoperite</div></div>
        </div>
        <div class="tire-kpi-card">
            <div class="tire-kpi-icon tire-kpi-red"><i class="bi bi-exclamation-octagon"></i></div>
            <div><div class="small text-muted">Inlocuire necesara</div><div class="h3 mb-0"><?= e((string) ((int) ($stockTotals['replacement_required'] ?? 0))) ?></div><div class="small text-muted">Uzura / deteriorare</div></div>
        </div>
    </div>

    <div class="tire-inventory-wrap">
        <div class="tire-work-card">
            <form method="get" class="tire-filter-bar">
                <input type="hidden" name="page" value="mentenanta">
                <input type="hidden" name="action" value="tire_stock">
                <div>
                    <label class="visually-hidden" for="tire_q">Cautare</label>
                    <input class="form-control" id="tire_q" name="q" value="<?= e((string) ($tireFilters['q'] ?? '')) ?>" placeholder="Cauta dupa brand, model, dimensiune, vehicul...">
                </div>
                <select class="form-select" name="vehicle_type" aria-label="Tip vehicul">
                    <option value="">Toate vehiculele</option>
                    <?php foreach ($targetTypeOptions as $value => $label): ?>
                        <?php if ((string) $value === 'universal') { continue; } ?>
                        <option value="<?= e((string) $value) ?>" <?= (string) ($tireFilters['vehicle_type'] ?? '') === (string) $value ? 'selected' : '' ?>><?= e((string) $label) ?></option>
                    <?php endforeach; ?>
                </select>
                <select class="form-select" name="axle_config" aria-label="Configuratie axe">
                    <option value="">Toate configuratiile</option>
                    <?php foreach ($axleConfigOptions as $value => $label): ?>
                        <option value="<?= e((string) $value) ?>" <?= (string) ($tireFilters['axle_config'] ?? '') === (string) $value ? 'selected' : '' ?>><?= e((string) $label) ?></option>
                    <?php endforeach; ?>
                </select>
                <select class="form-select" name="tire_type" aria-label="Tip anvelopa">
                    <option value="">Toate tipurile</option>
                    <?php foreach ($tireTypeOptions as $value => $label): ?>
                        <option value="<?= e((string) $value) ?>" <?= (string) ($tireFilters['tire_type'] ?? '') === (string) $value ? 'selected' : '' ?>><?= e((string) $label) ?></option>
                    <?php endforeach; ?>
                </select>
                <select class="form-select" name="status" aria-label="Status">
                    <option value="">Toate statusurile</option>
                    <?php foreach ($tireStatusOptions as $value => $label): ?>
                        <option value="<?= e((string) $value) ?>" <?= (string) ($tireFilters['status'] ?? '') === (string) $value ? 'selected' : '' ?>><?= e((string) $label) ?></option>
                    <?php endforeach; ?>
                </select>
                <select class="form-select" name="condition" aria-label="Stare">
                    <option value="">Toate starile</option>
                    <?php foreach ($conditionOptions as $value => $label): ?>
                        <option value="<?= e((string) $value) ?>" <?= (string) ($tireFilters['condition'] ?? '') === (string) $value ? 'selected' : '' ?>><?= e((string) $label) ?></option>
                    <?php endforeach; ?>
                </select>
                <select class="form-select" name="location" aria-label="Locatie">
                    <option value="">Toate locatiile</option>
                    <?php foreach ($locationOptions as $value => $label): ?>
                        <option value="<?= e((string) $value) ?>" <?= (string) ($tireFilters['location'] ?? '') === (string) $value ? 'selected' : '' ?>><?= e((string) $label) ?></option>
                    <?php endforeach; ?>
                </select>
                <select class="form-select" name="mounted" aria-label="Montare">
                    <option value="">Montate si nemontate</option>
                    <option value="mounted" <?= (string) ($tireFilters['mounted'] ?? '') === 'mounted' ? 'selected' : '' ?>>Doar montate</option>
                    <option value="not_mounted" <?= (string) ($tireFilters['mounted'] ?? '') === 'not_mounted' ? 'selected' : '' ?>>Doar nemontate</option>
                </select>
                <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-funnel me-1"></i> Filtre</button>
            </form>

            <div class="table-responsive">
                <table class="table tire-table align-middle mb-0">
                    <thead>
                    <tr>
                        <th>Anvelopa</th>
                        <th>Locatie & Pozitie</th>
                        <th>Stare & Uzura</th>
                        <th>Km ramasi</th>
                        <th>Montata pe</th>
                        <th>Tip axa</th>
                        <th>Tip anvelopa</th>
                        <th>Status</th>
                        <th class="text-end pe-3">Actiuni</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($inventoryRows === []): ?>
                        <tr><td colspan="9" class="text-center text-muted py-4">Nu exista anvelope pentru filtrele selectate.</td></tr>
                    <?php else: ?>
                        <?php foreach ($inventoryRows as $tireRow): ?>
                            <?php
                            $tireId = (int) ($tireRow['id'] ?? 0);
                            $statusMeta = is_array($tireRow['status_meta'] ?? null) ? $tireRow['status_meta'] : ['label' => tire_status_label((string) ($tireRow['status'] ?? '')), 'badge_class' => 'tire-status-badge tire-status-stock'];
                            $conditionMeta = is_array($tireRow['condition_meta'] ?? null) ? $tireRow['condition_meta'] : ['label' => '-', 'dot_class' => 'tire-dot', 'progress_class' => ''];
                            $wearPercent = $tireRow['wear_percent'] ?? null;
                            $wearWidth = $wearPercent !== null ? max(2, min(100, (float) $wearPercent)) : 0;
                            $detailsModalId = 'tireDetails' . $tireId;
                            $editModalId = 'tireEdit' . $tireId;
                            $moveModalId = 'tireMove' . $tireId;
                            $purchasePriceDisplay = ($tireRow['purchase_price'] ?? null) !== null && $tireRow['purchase_price'] !== ''
                                ? number_format((float) $tireRow['purchase_price'], 2, ',', '.') . ' lei'
                                : '-';
                            $invoiceDocumentPath = trim((string) ($tireRow['invoice_document_path'] ?? ''));
                            $invoiceDocumentName = trim((string) ($tireRow['invoice_document_original_name'] ?? ''));
                            $invoiceDocumentUrl = $invoiceDocumentPath !== '' ? url('uploads/anvelope_facturi/' . rawurlencode(basename($invoiceDocumentPath))) : '';
                            $profilePhotoPath = trim((string) ($tireRow['profile_photo_path'] ?? ''));
                            $profilePhotoName = trim((string) ($tireRow['profile_photo_original_name'] ?? ''));
                            $profilePhotoUrl = $profilePhotoPath !== '' ? url('uploads/anvelope_profil/' . rawurlencode(basename($profilePhotoPath))) : '';
                            $locationPhotoPath = trim((string) ($tireRow['location_photo_path'] ?? ''));
                            $locationPhotoName = trim((string) ($tireRow['location_photo_original_name'] ?? ''));
                            $locationPhotoUrl = $locationPhotoPath !== '' ? url('uploads/anvelope_locatii/' . rawurlencode(basename($locationPhotoPath))) : '';
                            ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="tire-thumb<?= (string) ($tireRow['status'] ?? '') === 'missing' && $profilePhotoUrl === '' ? ' tire-thumb-missing' : '' ?>">
                                            <?php if ($profilePhotoUrl !== ''): ?><img src="<?= e($profilePhotoUrl) ?>" alt="<?= e($profilePhotoName !== '' ? $profilePhotoName : 'Poza profil anvelopa') ?>"><?php elseif ((string) ($tireRow['status'] ?? '') === 'missing'): ?><i class="bi bi-question-lg"></i><?php endif; ?>
                                        </div>
                                        <div>
                                            <div class="fw-semibold"><?= e(trim((string) (($tireRow['brand'] ?? '') . ' ' . ($tireRow['model'] ?? ''))) ?: '-') ?></div>
                                            <div class="small text-muted"><?= e((string) ($tireRow['tire_size'] ?? '-')) ?></div>
                                            <span class="badge text-bg-light border"><?= e((string) ($tireRow['season_label'] ?? 'All season')) ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?= e((string) ($tireRow['location_display'] ?? '-')) ?></div>
                                    <div class="small text-muted"><?= e((string) ($tireRow['position_display'] ?? '-')) ?></div>
                                    <div class="small text-primary"><?= e((string) ($tireRow['axle_display'] ?? '-')) ?></div>
                                </td>
                                <td>
                                    <div class="fw-semibold"><span class="<?= e((string) ($conditionMeta['dot_class'] ?? '')) ?>"></span><?= e((string) ($conditionMeta['label'] ?? '-')) ?></div>
                                    <div class="small text-muted"><?= $wearPercent !== null ? e(number_format((float) $wearPercent, 0, ',', '.')) . '% uzura' : 'Uzura necunoscuta' ?></div>
                                    <div class="tire-progress-track"><span class="<?= e((string) ($conditionMeta['progress_class'] ?? '')) ?>" style="width: <?= e(number_format($wearWidth, 2, '.', '')) ?>%"></span></div>
                                </td>
                                <td class="fw-semibold"><?= e($formatKm($tireRow['remaining_km'] ?? null)) ?></td>
                                <td>
                                    <?php if (!empty($tireRow['is_mounted'])): ?>
                                        <div class="fw-semibold"><?= e((string) ($tireRow['nr_inmatriculare'] ?? '-')) ?></div>
                                        <div class="small text-muted"><?= e(trim((string) (($tireRow['vehicle_marca'] ?? '') . ' ' . ($tireRow['vehicle_model'] ?? ''))) ?: '-') ?></div>
                                    <?php else: ?>
                                        <span class="fw-semibold">Nealocat</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?= e((string) ($tireRow['tire_axle_type_label'] ?? '-')) ?></div>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?= e((string) ($tireRow['tire_type_label'] ?? '-')) ?></div>
                                    <div class="small text-muted"><?= e((string) ($tireRow['compatibility_label'] ?? '-')) ?></div>
                                </td>
                                <td><span class="<?= e((string) ($statusMeta['badge_class'] ?? 'tire-status-badge')) ?>"><?= e((string) ($statusMeta['label'] ?? '-')) ?></span></td>
                                <td class="text-end pe-3">
                                    <div class="dropdown">
                                        <button class="btn btn-outline-secondary tire-action-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-three-dots"></i></button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li><button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#<?= e($detailsModalId) ?>"><i class="bi bi-eye me-2"></i>Vezi detalii</button></li>
                                            <li><button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#<?= e($editModalId) ?>"><i class="bi bi-pencil me-2"></i>Editeaza</button></li>
                                            <li><button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#<?= e($moveModalId) ?>"><i class="bi bi-truck me-2"></i>Monteaza pe vehicul</button></li>
                                            <li><button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#<?= e($moveModalId) ?>"><i class="bi bi-arrow-left-right me-2"></i>Muta intre axe</button></li>
                                            <?php foreach (['spare' => 'Muta in rezerva', 'damaged' => 'Marcheaza deteriorata', 'missing' => 'Marcheaza lipsa', 'removed' => 'Scoate din uz'] as $statusValue => $statusLabel): ?>
                                                <li>
                                                    <form method="post" action="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'change_tire_status'])) ?>">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="tire_id" value="<?= e((string) $tireId) ?>">
                                                        <input type="hidden" name="status" value="<?= e($statusValue) ?>">
                                                        <input type="hidden" name="reason" value="<?= e($statusLabel) ?>">
                                                        <button class="dropdown-item<?= $statusValue === 'missing' ? ' text-danger' : '' ?>" type="submit"><?= e($statusLabel) ?></button>
                                                    </form>
                                                </li>
                                            <?php endforeach; ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <form method="post" action="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'delete_tire_stock'])) ?>">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="stock_tire_id" value="<?= e((string) $tireId) ?>">
                                                    <button type="submit" class="dropdown-item text-danger" data-confirm="Sigur vrei sa stergi anvelopa?"><i class="bi bi-trash me-2"></i>Sterge</button>
                                                </form>
                                            </li>
                                        </ul>
                                    </div>

                                    <div class="modal fade text-start" id="<?= e($detailsModalId) ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
                                            <div class="modal-header"><h5 class="modal-title">Detalii anvelopa</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Inchide"></button></div>
                                            <div class="modal-body">
                                                <div class="row g-3">
                                                    <div class="col-md-6"><strong>Serie:</strong> <?= e((string) ($tireRow['serial_number'] ?? '-')) ?></div>
                                                    <div class="col-md-6"><strong>DOT:</strong> <?= e((string) ($tireRow['dot_code'] ?? '-')) ?></div>
                                                    <div class="col-md-6"><strong>Furnizor:</strong> <?= e((string) ($tireRow['supplier'] ?? '-')) ?></div>
                                                    <div class="col-md-6"><strong>Cost:</strong> <?= e($purchasePriceDisplay) ?></div>
                                                    <div class="col-md-6"><strong>Factura atasata:</strong>
                                                        <?php if ($invoiceDocumentUrl !== ''): ?>
                                                            <a href="<?= e($invoiceDocumentUrl) ?>" target="_blank" rel="noopener"><?= e($invoiceDocumentName !== '' ? $invoiceDocumentName : 'Deschide factura') ?></a>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="col-md-6"><strong>Poza profil:</strong>
                                                        <?php if ($profilePhotoUrl !== ''): ?>
                                                            <a href="<?= e($profilePhotoUrl) ?>" target="_blank" rel="noopener"><?= e($profilePhotoName !== '' ? $profilePhotoName : 'Deschide poza') ?></a>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="col-md-6"><strong>Poza locatie:</strong>
                                                        <?php if ($locationPhotoUrl !== ''): ?>
                                                            <a href="<?= e($locationPhotoUrl) ?>" target="_blank" rel="noopener"><?= e($locationPhotoName !== '' ? $locationPhotoName : 'Deschide poza') ?></a>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="col-md-6"><strong>Km folositi:</strong> <?= e($formatKm($tireRow['used_km'] ?? null)) ?></div>
                                                    <div class="col-md-6"><strong>Tip axa:</strong> <?= e((string) ($tireRow['tire_axle_type_label'] ?? '-')) ?></div>
                                                    <div class="col-md-6"><strong>Tip anvelopa:</strong> <?= e((string) ($tireRow['tire_type_label'] ?? '-')) ?></div>
                                                    <div class="col-md-6"><strong>Compatibilitate:</strong> <?= e((string) ($tireRow['compatibility_label'] ?? '-')) ?></div>
                                                    <div class="col-md-6"><strong>Tip vehicul:</strong> <?= e((string) ($tireRow['target_vehicle_type_label'] ?? '-')) ?></div>
                                                    <div class="col-md-6"><strong>Formula axelor:</strong> <?= trim((string) ($tireRow['target_axle_config_label'] ?? '')) !== '' ? e((string) ($tireRow['target_axle_config_label'] ?? '')) : '<span class="text-muted">-</span>' ?></div>
                                                    <div class="col-12"><strong>Observatii:</strong><br><?= trim((string) ($tireRow['notes'] ?? '')) !== '' ? nl2br(e((string) $tireRow['notes'])) : '<span class="text-muted">-</span>' ?></div>
                                                </div>
                                            </div>
                                        </div></div>
                                    </div>

                                    <div class="modal fade text-start" id="<?= e($moveModalId) ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog modal-lg"><div class="modal-content">
                                            <form method="post" action="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'move_tire'])) ?>">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="tire_id" value="<?= e((string) $tireId) ?>">
                                                <input type="hidden" name="source_vehicle_id" value="<?= e((string) ((int) ($tireRow['active_vehicle_id'] ?? 0))) ?>">
                                                <div class="modal-header"><h5 class="modal-title">Muta anvelopa</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Inchide"></button></div>
                                                <div class="modal-body">
                                                    <div class="row g-3">
                                                        <div class="col-md-4"><div class="small text-muted">Vehicul curent</div><div class="fw-semibold"><?= e((string) ($tireRow['vehicle_display'] ?? 'Nealocat')) ?></div></div>
                                                        <div class="col-md-4"><div class="small text-muted">Axa curenta</div><div class="fw-semibold"><?= e((string) ($tireRow['axle_display'] ?? '-')) ?></div></div>
                                                        <div class="col-md-4"><div class="small text-muted">Pozitie curenta</div><div class="fw-semibold"><?= e((string) ($tireRow['position_display'] ?? '-')) ?></div></div>
                                                        <div class="col-md-6">
                                                            <label class="form-label">Pozitie tinta</label>
                                                            <select class="form-select" name="target_position_id" required>
                                                                <option value="">Selecteaza pozitia</option>
                                                                <?php foreach ($moveTargets as $targetVehicle): ?>
                                                                    <optgroup label="<?= e((string) ($targetVehicle['label'] ?? '-')) ?>">
                                                                        <?php foreach (($targetVehicle['positions'] ?? []) as $targetPosition): ?>
                                                                            <?php $occupiedLabel = (int) ($targetPosition['mounted_tire_id'] ?? 0) > 0 ? ' - ocupata: ' . (string) ($targetPosition['mounted_tire_label'] ?? '') : ''; ?>
                                                                            <option value="<?= e((string) ((int) ($targetPosition['position_id'] ?? 0))) ?>">
                                                                                <?= e((string) ($targetPosition['label'] ?? '-') . ' / ' . (string) ($targetPosition['axle_type_label'] ?? '-') . $occupiedLabel) ?>
                                                                            </option>
                                                                        <?php endforeach; ?>
                                                                    </optgroup>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <div class="col-md-3"><label class="form-label">Data</label><input type="date" class="form-control" name="move_date" value="<?= e(date('Y-m-d')) ?>" required></div>
                                                        <div class="col-md-3"><label class="form-label">Motiv</label><input type="text" class="form-control" name="move_reason" value="Mutare anvelopa"></div>
                                                        <div class="col-12"><label class="form-label">Observatie</label><textarea class="form-control" name="move_observation" rows="2"></textarea></div>
                                                        <div class="col-12"><label class="form-check"><input class="form-check-input" type="checkbox" name="allow_swap" value="1"> <span class="form-check-label">Schimba pozitiile daca tinta este ocupata</span></label></div>
                                                    </div>
                                                </div>
                                                <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuleaza</button><button type="submit" class="btn btn-primary">Salveaza mutarea</button></div>
                                            </form>
                                        </div></div>
                                    </div>

                                    <div class="modal fade text-start" id="<?= e($editModalId) ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
                                            <form method="post" enctype="multipart/form-data" action="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'update_tire_stock'])) ?>" data-tire-compatibility-form>
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="stock_tire_id" value="<?= e((string) $tireId) ?>">
                                                <div class="modal-header"><h5 class="modal-title">Editeaza anvelopa</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Inchide"></button></div>
                                                <div class="modal-body"><div class="row g-3">
                                                    <div class="col-md-4"><label class="form-label">Brand *</label><input class="form-control" name="stock_edit_brand" value="<?= e((string) ($tireRow['brand'] ?? '')) ?>" required></div>
                                                    <div class="col-md-4"><label class="form-label">Model</label><input class="form-control" name="stock_edit_model" value="<?= e((string) ($tireRow['model'] ?? '')) ?>"></div>
                                                    <div class="col-md-4"><label class="form-label">Poza profil</label><input type="file" class="form-control" name="stock_edit_profile_photo_upload" accept=".jpg,.jpeg,.png,.webp"><div class="form-text">JPG, PNG, WEBP. Maxim 5 MB.<?php if ($profilePhotoUrl !== ''): ?> <a href="<?= e($profilePhotoUrl) ?>" target="_blank" rel="noopener">Poza curenta</a><?php endif; ?></div></div>
                                                    <div class="col-md-4"><label class="form-label">Dimensiune</label><input class="form-control" name="stock_edit_tire_size" value="<?= e((string) ($tireRow['tire_size'] ?? '')) ?>"></div>
                                                    <?php $currentTireAxleType = (string) ($tireRow['tire_axle_type'] ?? ''); ?>
                                                    <div class="col-md-4"><label class="form-label">Tip axa *</label><select class="form-select" name="stock_edit_axle_type" data-tire-axle-type required><option value="">Selecteaza tipul axei</option><?php foreach ($tireAxleTypeFormOptions as $value => $label): ?><option value="<?= e((string) $value) ?>" <?= $currentTireAxleType === (string) $value ? 'selected' : '' ?>><?= e((string) $label) ?></option><?php endforeach; ?></select></div>
                                                    <div class="col-md-4"><label class="form-label">Tip anvelopa *</label><select class="form-select" name="stock_edit_tire_type" data-tire-type data-current="<?= e((string) ($tireRow['tire_type'] ?? '')) ?>" required><option value="">Selecteaza mai intai tipul axei</option></select></div>
                                                    <?php
                                                    $currentTargetVehicleType = (string) ($tireRow['target_vehicle_type'] ?? 'universal');
                                                    $currentTargetVehicleTypes = is_array($tireRow['target_vehicle_types_values'] ?? null) ? $tireRow['target_vehicle_types_values'] : [$currentTargetVehicleType];
                                                    $currentTargetVehicleTypeLabel = (string) ($tireRow['target_vehicle_type_label'] ?? $currentTargetVehicleType);
                                                    $currentTargetAxleConfig = (string) ($tireRow['target_axle_config'] ?? '');
                                                    ?>
                                                    <div class="col-md-4"><label class="form-label">Compatibilitate vehicul</label><?php $renderVehicleCompatibilityPicker('stock_edit_target_vehicle_types[]', $currentTargetVehicleTypes); ?><?php if ($currentTargetVehicleType !== 'universal' && !array_key_exists($currentTargetVehicleType, $targetVehicleTypeOptions)): ?><input type="hidden" name="stock_edit_target_vehicle_types[]" value="<?= e($currentTargetVehicleType) ?>"><?php endif; ?></div>
                                                    <div class="col-md-4"><label class="form-label">Formula axelor</label><select class="form-select" name="stock_edit_target_axle_config" data-tire-axle-config data-current="<?= e($currentTargetAxleConfig) ?>"></select></div>
                                                    <div class="col-md-4"><label class="form-label">Status</label><select class="form-select" name="stock_edit_status"><?php foreach ($tireStatusOptions as $value => $label): ?><option value="<?= e((string) $value) ?>" <?= (string) ($tireRow['status'] ?? '') === (string) $value ? 'selected' : '' ?>><?= e((string) $label) ?></option><?php endforeach; ?></select></div>
                                                    <div class="col-md-4"><label class="form-label">Conditie</label><select class="form-select" name="stock_edit_condition_status"><?php foreach ($conditionOptions as $value => $label): ?><option value="<?= e((string) $value) ?>" <?= (string) ($tireRow['condition_status'] ?? '') === (string) $value ? 'selected' : '' ?>><?= e((string) $label) ?></option><?php endforeach; ?></select></div>
                                                    <div class="col-md-4"><label class="form-label">DOT</label><input class="form-control" name="stock_edit_dot_code" value="<?= e((string) ($tireRow['dot_code'] ?? '')) ?>"></div>
                                                    <div class="col-md-4"><label class="form-label">Km curent</label><input type="number" class="form-control" name="stock_edit_current_mileage" value="<?= e((string) ((int) ($tireRow['current_mileage'] ?? 0))) ?>"></div>
                                                    <div class="col-md-4"><label class="form-label">Km ramasi</label><input type="number" class="form-control" name="stock_edit_estimated_remaining_km" value="<?= e((string) ($tireRow['estimated_remaining_km'] ?? '')) ?>"></div>
                                                    <div class="col-md-6"><label class="form-label">Compatibilitate</label><input class="form-control" name="stock_edit_usage_compatibility" value="<?= e((string) ($tireRow['usage_compatibility'] ?? '')) ?>"></div>
                                                    <div class="col-md-6"><label class="form-label">Locatie</label><input class="form-control" name="stock_edit_location_label" value="<?= e((string) ($tireRow['location_label'] ?? '')) ?>"></div>
                                                    <div class="col-md-6"><label class="form-label">Poza locatie</label><input type="file" class="form-control" name="stock_edit_location_photo_upload" accept=".jpg,.jpeg,.png,.webp"><div class="form-text">JPG, PNG, WEBP. Maxim 5 MB.<?php if ($locationPhotoUrl !== ''): ?> <a href="<?= e($locationPhotoUrl) ?>" target="_blank" rel="noopener">Poza curenta</a><?php endif; ?></div></div>
                                                    <div class="col-md-4"><label class="form-label">Cost</label><input class="form-control" name="stock_edit_purchase_price" value="<?= e((string) ($tireRow['purchase_price'] ?? '')) ?>"></div>
                                                    <div class="col-md-4"><label class="form-label">Furnizor</label><input class="form-control" name="stock_edit_supplier" value="<?= e((string) ($tireRow['supplier'] ?? '')) ?>"></div>
                                                    <div class="col-md-4"><label class="form-label">Factura (upload optional)</label><input type="file" class="form-control" name="stock_edit_invoice_upload" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx"><div class="form-text">PDF, JPG, PNG, WEBP, DOC, DOCX. Maxim 5 MB.<?php if ($invoiceDocumentUrl !== ''): ?> <a href="<?= e($invoiceDocumentUrl) ?>" target="_blank" rel="noopener">Factura curenta</a><?php endif; ?></div></div>
                                                    <div class="col-12"><label class="form-label">Observatii</label><textarea class="form-control" name="stock_edit_notes" rows="3"><?= e((string) ($tireRow['notes'] ?? '')) ?></textarea></div>
                                                </div></div>
                                                <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuleaza</button><button type="submit" class="btn btn-primary">Salveaza</button></div>
                                            </form>
                                        </div></div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="tire-pagination">
                <div class="text-muted small">Afisare <?= e((string) min($totalRows, (($pageNo - 1) * $perPage) + 1)) ?>-<?= e((string) min($totalRows, $pageNo * $perPage)) ?> din <?= e((string) $totalRows) ?> anvelope</div>
                <div class="d-flex align-items-center gap-2">
                    <?php if ($pageNo > 1): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(build_query_url(array_merge($filterBase, ['p' => $pageNo - 1]))) ?>"><i class="bi bi-chevron-left"></i></a><?php endif; ?>
                    <span class="btn btn-sm btn-primary disabled"><?= e((string) $pageNo) ?></span>
                    <?php if ($pageNo < $totalPages): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(build_query_url(array_merge($filterBase, ['p' => $pageNo + 1]))) ?>"><i class="bi bi-chevron-right"></i></a><?php endif; ?>
                    <form method="get" class="ms-2">
                        <?php foreach ($filterBase as $key => $value): if ($key === 'per_page') { continue; } ?><input type="hidden" name="<?= e((string) $key) ?>" value="<?= e((string) $value) ?>"><?php endforeach; ?>
                        <select class="form-select form-select-sm" name="per_page" onchange="this.form.submit()">
                            <?php foreach ([5,10,25,50] as $option): ?><option value="<?= e((string) $option) ?>" <?= $perPage === $option ? 'selected' : '' ?>><?= e((string) $option) ?> / pagina</option><?php endforeach; ?>
                        </select>
                    </form>
                </div>
            </div>
        </div>

    </div>

    <div class="modal fade tire-add-modal" id="addTireModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <form method="post" enctype="multipart/form-data" action="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'add_tire_stock'])) ?>" data-tire-compatibility-form>
                    <?= csrf_field() ?>
                    <input type="hidden" name="stock_quantity" value="1">
                    <input type="hidden" name="stock_serial_prefix" value="ANV">
                    <input type="hidden" name="stock_mount_date" value="<?= e(date('Y-m-d')) ?>">
                    <input type="hidden" name="stock_require_vehicle_compatibility" value="1">
                    <div class="modal-header">
                        <h4 class="modal-title h6 mb-0">Adauga anvelopa noua</h4>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Inchide"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-4"><label class="form-label">Brand *</label><input class="form-control" name="stock_brand" placeholder="Ex: Michelin" required></div>
                            <div class="col-md-4"><label class="form-label">Model *</label><input class="form-control" name="stock_model" placeholder="Ex: Pilot Sport 4" required></div>
                            <div class="col-md-4"><label class="form-label">Poza profil</label><input type="file" class="form-control" name="stock_profile_photo_upload" accept=".jpg,.jpeg,.png,.webp"><div class="form-text">JPG, PNG, WEBP. Maxim 5 MB.</div></div>
                            <div class="col-md-3"><label class="form-label">Dimensiune *</label><input class="form-control" name="stock_tire_size" placeholder="Ex: 205/55 R16 91V" required></div>
                            <div class="col-md-3"><label class="form-label">An fabricatie</label><input class="form-control" name="stock_manufacturing_year" placeholder="2026"></div>
                            <div class="col-md-3"><label class="form-label">DOT</label><input class="form-control" name="stock_dot_code"></div>
                            <div class="col-md-3"><label class="form-label">Tip axa *</label><select class="form-select" name="stock_axle_type" data-tire-axle-type required><option value="">Selecteaza tipul axei</option><?php foreach ($tireAxleTypeFormOptions as $value => $label): ?><option value="<?= e((string) $value) ?>"><?= e((string) $label) ?></option><?php endforeach; ?></select></div>
                            <div class="col-md-6"><label class="form-label">Tip anvelopa *</label><select class="form-select" name="stock_tire_type" data-tire-type data-current="" required disabled><option value="">Selecteaza mai intai tipul axei</option></select></div>
                            <div class="col-md-6"><label class="form-label">Compatibilitate vehicul *</label><?php $renderVehicleCompatibilityPicker('stock_target_vehicle_types[]', ['autovehicul'], true); ?></div>
                            <div class="col-md-6"><label class="form-label">Locatie</label><input class="form-control" name="stock_location_label" placeholder="Depozit" value="Depozit"></div>
                            <div class="col-md-6"><label class="form-label">Poza locatie</label><input type="file" class="form-control" name="stock_location_photo_upload" accept=".jpg,.jpeg,.png,.webp"><div class="form-text">JPG, PNG, WEBP. Maxim 5 MB.</div></div>
                            <div class="col-md-3"><label class="form-label">Furnizor</label><input class="form-control" name="stock_supplier"></div>
                            <div class="col-md-3"><label class="form-label">Data achizitiei</label><input type="date" class="form-control" name="stock_purchase_date"></div>
                            <div class="col-md-3"><label class="form-label">Cost</label><input class="form-control" name="stock_purchase_price" placeholder="Ex: 1200"></div>
                            <div class="col-md-3"><label class="form-label">Factura</label><input type="file" class="form-control" name="stock_invoice_upload" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx"><div class="form-text">PDF, JPG, PNG, WEBP, DOC, DOCX. Maxim 5 MB.</div></div>
                            <div class="col-md-6"><label class="form-label">Km curent</label><input type="number" class="form-control" name="stock_current_mileage" value="0" min="0"></div>
                            <div class="col-md-6"><label class="form-label">Km ramasi</label><input type="number" class="form-control" name="stock_estimated_remaining_km" min="0"></div>
                            <div class="col-md-6"><label class="form-label">Conditie initiala</label><select class="form-select" name="stock_initial_condition"><?php foreach ($conditionOptions as $value => $label): ?><option value="<?= e((string) $value) ?>"><?= e((string) $label) ?></option><?php endforeach; ?></select></div>
                            <div class="col-12"><label class="form-label">Observatii</label><textarea class="form-control" name="stock_notes" rows="3" placeholder="Observatii optionale..."></textarea></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="reset" class="btn btn-outline-secondary">Reseteaza</button>
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuleaza</button>
                        <button type="submit" class="btn btn-primary">Salveaza</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php return; ?>
<?php endif; ?>

<?php if ($isMaintenanceList && $isMaintenanceTireStockPage && is_array($maintenanceTireStockContext)): ?>
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

<?php if ($isMaintenanceTireStockPage): ?>
    <?php return; ?>
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

<?php if ($isDocumentList || $isDocumentCostOverrideList): ?>
    <h3 class="h5 mt-4 mb-2">Documente mașini</h3>
<?php endif; ?>

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

            <?php if ($isDocumentCostOverrideList || (int) $pagination['total_pages'] > 1): ?>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <small class="text-muted">Pagina <?= e((string) $pagination['page']) ?> din <?= e((string) $pagination['total_pages']) ?></small>
                    <nav aria-label="Paginare documente mașini">
                        <ul class="pagination pagination-sm mb-0">
                            <?php
                            $prevPage = max(1, (int) $pagination['page'] - 1);
                            $nextPage = min((int) $pagination['total_pages'], (int) $pagination['page'] + 1);
                            ?>
                            <li class="page-item <?= (int) $pagination['page'] <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= e(build_query_url(array_merge($mainPaginationBaseQuery, ['p' => $prevPage]))) ?>" <?= (int) $pagination['page'] <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>>Anterior</a>
                            </li>

                            <?php for ($i = 1; $i <= (int) $pagination['total_pages']; $i++): ?>
                                <li class="page-item <?= (int) $pagination['page'] === $i ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= e(build_query_url(array_merge($mainPaginationBaseQuery, ['p' => $i]))) ?>"><?= e((string) $i) ?></a>
                                </li>
                            <?php endfor; ?>

                            <li class="page-item <?= (int) $pagination['page'] >= (int) $pagination['total_pages'] ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= e(build_query_url(array_merge($mainPaginationBaseQuery, ['p' => $nextPage]))) ?>" <?= (int) $pagination['page'] >= (int) $pagination['total_pages'] ? 'aria-disabled="true" tabindex="-1"' : '' ?>>Următor</a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($isDocumentList && $driverDocumentModule !== null && $driverDocumentPagination !== null): ?>
    <?php
    $driverDocumentBaseQuery = array_merge($baseQuery, [
        'p' => (int) ($pagination['page'] ?? 1),
    ]);
    ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-4 mb-2">
        <h3 class="h5 mb-0">Documente șoferi</h3>
        <a class="btn btn-sm btn-primary" href="<?= e(build_query_url(['page' => 'documente_soferi', 'action' => 'create'])) ?>">Adauga document sofer</a>
    </div>
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive module-list-table-wrap module-list-table-wrap-documente">
                <table class="table table-hover align-middle mb-0 module-list-table module-list-table-documente">
                    <thead>
                    <tr>
                        <?php foreach ($driverDocumentModule['list_columns'] as $column => $meta): ?>
                            <th><?= e($meta['label']) ?></th>
                        <?php endforeach; ?>
                        <th class="text-end pe-3">Acțiuni</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($driverDocumentRows === []): ?>
                        <tr>
                            <td colspan="<?= e((string) (count($driverDocumentModule['list_columns']) + 1)) ?>" class="text-center text-muted py-4">Nu exista inregistrari.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($driverDocumentRows as $row): ?>
                            <tr>
                                <?php foreach ($driverDocumentModule['list_columns'] as $column => $meta): ?>
                                    <td><?= format_value_html($row[$column] ?? null, $meta, $row) ?></td>
                                <?php endforeach; ?>
                                <td class="text-end pe-3">
                                    <div class="d-inline-flex gap-1">
                                        <?php if (!empty($row['fisier_stocat'])): ?>
                                            <a class="btn btn-sm btn-outline-dark" href="<?= e(build_query_url(['page' => 'documente_soferi', 'action' => 'preview', 'id' => (int) $row['id']])) ?>">Vezi in aplicatie</a>
                                        <?php endif; ?>
                                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(build_query_url(['page' => 'documente_soferi', 'action' => 'show', 'id' => (int) $row['id']])) ?>">Detalii</a>
                                        <a class="btn btn-sm btn-outline-primary" href="<?= e(build_query_url(['page' => 'documente_soferi', 'action' => 'edit', 'id' => (int) $row['id']])) ?>">Editează</a>
                                        <form method="post" action="<?= e(build_query_url(['page' => 'documente_soferi', 'action' => 'delete'])) ?>" class="d-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= e((string) $row['id']) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Sigur dorești să ștergi această înregistrare?">
                                                Șterge
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
                <small class="text-muted">Total rezultate: <?= e((string) $driverDocumentPagination['total_rows']) ?></small>

                <?php if ((int) $driverDocumentPagination['total_pages'] > 1): ?>
                    <nav aria-label="Paginare documente soferi">
                        <ul class="pagination pagination-sm mb-0">
                            <?php
                            $driverPrevPage = max(1, (int) $driverDocumentPagination['page'] - 1);
                            $driverNextPage = min((int) $driverDocumentPagination['total_pages'], (int) $driverDocumentPagination['page'] + 1);
                            ?>
                            <li class="page-item <?= (int) $driverDocumentPagination['page'] <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= e(build_query_url(array_merge($driverDocumentBaseQuery, ['driver_p' => $driverPrevPage]))) ?>">Anterior</a>
                            </li>

                            <?php for ($i = 1; $i <= (int) $driverDocumentPagination['total_pages']; $i++): ?>
                                <li class="page-item <?= (int) $driverDocumentPagination['page'] === $i ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= e(build_query_url(array_merge($driverDocumentBaseQuery, ['driver_p' => $i]))) ?>"><?= e((string) $i) ?></a>
                                </li>
                            <?php endfor; ?>

                            <li class="page-item <?= (int) $driverDocumentPagination['page'] >= (int) $driverDocumentPagination['total_pages'] ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= e(build_query_url(array_merge($driverDocumentBaseQuery, ['driver_p' => $driverNextPage]))) ?>">Urmator</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($isDocumentCostOverrideList && $driverDocumentCostModule !== null && $driverDocumentCostPagination !== null): ?>
    <?php
    $driverDocumentCostBaseQuery = array_merge($baseQuery, [
        'p' => (int) ($pagination['page'] ?? 1),
    ]);
    ?>
    <h3 class="h5 mt-4 mb-2">Documente șoferi</h3>
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive module-list-table-wrap">
                <table class="table table-hover align-middle mb-0 module-list-table">
                    <thead>
                    <tr>
                        <?php foreach ($driverDocumentCostModule['list_columns'] as $column => $meta): ?>
                            <th><?= e($meta['label']) ?></th>
                        <?php endforeach; ?>
                        <th class="text-end pe-3">Acțiuni</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($driverDocumentCostRows === []): ?>
                        <tr>
                            <td colspan="<?= e((string) (count($driverDocumentCostModule['list_columns']) + 1)) ?>" class="text-center text-muted py-4">Nu existÄƒ Ã®nregistrÄƒri.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($driverDocumentCostRows as $row): ?>
                            <tr>
                                <?php foreach ($driverDocumentCostModule['list_columns'] as $column => $meta): ?>
                                    <td><?= format_value_html($row[$column] ?? null, $meta, $row) ?></td>
                                <?php endforeach; ?>
                                <td class="text-end pe-3">
                                    <div class="d-inline-flex gap-1">
                                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(build_query_url(['page' => 'configurare_costuri_documente_soferi', 'action' => 'show', 'id' => (int) $row['id']])) ?>">Detalii</a>
                                        <a class="btn btn-sm btn-outline-primary" href="<?= e(build_query_url(['page' => 'configurare_costuri_documente_soferi', 'action' => 'edit', 'id' => (int) $row['id']])) ?>">Editează</a>
                                        <form method="post" action="<?= e(build_query_url(['page' => 'configurare_costuri_documente_soferi', 'action' => 'delete'])) ?>" class="d-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= e((string) $row['id']) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Sigur dorești să ștergi această înregistrare?">
                                                Șterge
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
                <small class="text-muted">Total rezultate: <?= e((string) $driverDocumentCostPagination['total_rows']) ?></small>

                <div class="d-flex flex-wrap align-items-center gap-2">
                    <small class="text-muted">Pagina <?= e((string) $driverDocumentCostPagination['page']) ?> din <?= e((string) $driverDocumentCostPagination['total_pages']) ?></small>
                    <nav aria-label="Paginare documente șoferi">
                        <ul class="pagination pagination-sm mb-0">
                            <?php
                            $driverCostPrevPage = max(1, (int) $driverDocumentCostPagination['page'] - 1);
                            $driverCostNextPage = min((int) $driverDocumentCostPagination['total_pages'], (int) $driverDocumentCostPagination['page'] + 1);
                            ?>
                            <li class="page-item <?= (int) $driverDocumentCostPagination['page'] <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= e(build_query_url(array_merge($driverDocumentCostBaseQuery, ['driver_cost_p' => $driverCostPrevPage]))) ?>" <?= (int) $driverDocumentCostPagination['page'] <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>>Anterior</a>
                            </li>

                            <?php for ($i = 1; $i <= (int) $driverDocumentCostPagination['total_pages']; $i++): ?>
                                <li class="page-item <?= (int) $driverDocumentCostPagination['page'] === $i ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= e(build_query_url(array_merge($driverDocumentCostBaseQuery, ['driver_cost_p' => $i]))) ?>"><?= e((string) $i) ?></a>
                                </li>
                            <?php endfor; ?>

                            <li class="page-item <?= (int) $driverDocumentCostPagination['page'] >= (int) $driverDocumentCostPagination['total_pages'] ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= e(build_query_url(array_merge($driverDocumentCostBaseQuery, ['driver_cost_p' => $driverCostNextPage]))) ?>" <?= (int) $driverDocumentCostPagination['page'] >= (int) $driverDocumentCostPagination['total_pages'] ? 'aria-disabled="true" tabindex="-1"' : '' ?>>Următor</a>
                            </li>
                        </ul>
                    </nav>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

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


