<?php
declare(strict_types=1);

/**
 * Mentenanta -> Interventii V2: KPI, filtre, tabel si panoul lateral.
 * Randurile, KPI-urile si panoul se deseneaza din JSON (assets/js/interventii-v2.js),
 * aceleasi date ca endpoint-ul intervention_list, deci un singur randator.
 *
 * @var array $interventionBoot
 * @var array $vehicles
 */
$boot = is_array($interventionBoot ?? null) ? $interventionBoot : [];
$ivFilters = is_array($boot['filters'] ?? null) ? $boot['filters'] : [];
$ivCoordinators = is_array($boot['coordinators'] ?? null) ? $boot['coordinators'] : [];
$ivCan = is_array($boot['can'] ?? null) ? $boot['can'] : [];
$ivStatusLabels = InterventionWorkflowModel::STATUS_LABELS;
$ivKpis = [
    ['status' => 'de_verificat', 'label' => 'De verificat', 'icon' => 'bi-exclamation-circle', 'tone' => 'blue'],
    ['status' => 'programata', 'label' => 'Programate', 'icon' => 'bi-calendar3', 'tone' => 'indigo'],
    ['status' => 'in_service', 'label' => 'În service', 'icon' => 'bi-tools', 'tone' => 'purple'],
    ['status' => 'in_asteptare', 'label' => 'În așteptare', 'icon' => 'bi-pause-circle', 'tone' => 'orange'],
    ['status' => 'finalizata', 'label' => 'Finalizate', 'icon' => 'bi-check-lg', 'tone' => 'green'],
];
$ivKpiValues = is_array($boot['list']['kpis'] ?? null) ? $boot['list']['kpis'] : [];
$ivVehicles = [];
foreach ($vehicles as $vehicle) {
    $ivVehicles[(int) $vehicle['id']] = [
        'plate' => (string) $vehicle['nr_inmatriculare'],
        'model' => trim((string) ($vehicle['marca'] ?? '') . ' ' . (string) ($vehicle['model'] ?? '')),
        'type' => (string) ($vehicle['tip_vehicul'] ?? ''),
        'km' => $vehicle['km_bord'] !== null ? (int) $vehicle['km_bord'] : null,
    ];
}
$ivBootJson = [
    'endpoint' => url('index.php'),
    'token' => csrf_token(),
    'list' => $boot['list'] ?? ['rows' => [], 'total' => 0, 'kpis' => [], 'page' => 1, 'per_page' => 10],
    'filters' => $ivFilters,
    'open_id' => (int) ($boot['open_id'] ?? 0),
    'coordinators' => $ivCoordinators,
    'catalog' => $boot['catalog'] ?? [],
    'vehicles' => $ivVehicles,
    'can' => $ivCan,
    'labels' => [
        'status' => $ivStatusLabels,
        'priority' => InterventionWorkflowModel::PRIORITY_LABELS,
        'assessment' => InterventionWorkflowModel::ASSESSMENT_LABELS,
        'operation' => InterventionWorkflowModel::OPERATION_LABELS,
        'progress' => InterventionWorkflowModel::PROGRESS_LABELS,
        'result' => InterventionWorkflowModel::RESULT_LABELS,
        'document' => InterventionWorkflowModel::DOCUMENT_LABELS,
        'availability' => InterventionWorkflowModel::AVAILABILITY_LABELS,
    ],
    'today' => date('Y-m-d'),
];
?>
<div class="iv2" data-iv2>
    <div class="iv2-kpis" role="group" aria-label="Sumar intervenții pe status">
        <?php foreach ($ivKpis as $kpi): ?>
            <button class="iv2-kpi <?= ($ivFilters['status'] ?? '') === $kpi['status'] ? 'is-active' : '' ?>" type="button"
                    data-iv2-kpi="<?= e($kpi['status']) ?>" aria-pressed="<?= ($ivFilters['status'] ?? '') === $kpi['status'] ? 'true' : 'false' ?>"
                    title="Filtrează: <?= e($kpi['label']) ?>">
                <span class="iv2-kpi-icon tone-<?= e($kpi['tone']) ?>"><i class="bi <?= e($kpi['icon']) ?>" aria-hidden="true"></i></span>
                <span>
                    <span class="iv2-kpi-value" data-iv2-kpi-value><?= e((string) (int) ($ivKpiValues[$kpi['status']] ?? 0)) ?></span>
                    <span class="iv2-kpi-label"><?= e($kpi['label']) ?></span>
                </span>
            </button>
        <?php endforeach; ?>
    </div>

    <form class="iv2-filters" data-iv2-filters autocomplete="off" role="search">
        <div class="iv2-search">
            <label class="visually-hidden" for="iv2-search">Caută</label>
            <i class="bi bi-search" aria-hidden="true"></i>
            <input class="form-control" id="iv2-search" type="search" name="search" value="<?= e((string) ($ivFilters['search'] ?? '')) ?>"
                   placeholder="Caută după vehicul, piesă, ID, service, descriere..." aria-describedby="iv2-search-help">
            <span class="visually-hidden" id="iv2-search-help">Nr. înmatriculare, model, ID intervenție, componentă, cod piesă, furnizor sau problema raportată.</span>
        </div>
        <div>
            <label for="iv2-f-vehicle">Vehicul</label>
            <select class="form-select" id="iv2-f-vehicle" name="vehicle_id">
                <option value="">Toate</option>
                <?php foreach ($vehicles as $vehicle): ?>
                    <option value="<?= e((string) $vehicle['id']) ?>" <?= (int) ($ivFilters['vehicle_id'] ?? 0) === (int) $vehicle['id'] ? 'selected' : '' ?>><?= e((string) $vehicle['nr_inmatriculare']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="iv2-f-type">Tip intervenție</label>
            <select class="form-select" id="iv2-f-type" name="tip">
                <option value="">Toate</option>
                <option value="intretinere" <?= ($ivFilters['tip'] ?? '') === 'intretinere' ? 'selected' : '' ?>>Întreținere</option>
                <option value="reparatie" <?= ($ivFilters['tip'] ?? '') === 'reparatie' ? 'selected' : '' ?>>Reparație</option>
            </select>
        </div>
        <div>
            <label for="iv2-f-status">Status</label>
            <select class="form-select" id="iv2-f-status" name="status">
                <option value="">Toate</option>
                <?php foreach ($ivStatusLabels as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= ($ivFilters['status'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="iv2-f-from">Perioadă</label>
            <div class="iv2-period" title="Perioada programării">
                <i class="bi bi-calendar3" aria-hidden="true"></i>
                <input id="iv2-f-from" type="date" name="date_from" value="<?= e((string) ($ivFilters['date_from'] ?? '')) ?>" aria-label="De la">
                <span aria-hidden="true">–</span>
                <input type="date" name="date_to" value="<?= e((string) ($ivFilters['date_to'] ?? '')) ?>" aria-label="Până la">
            </div>
        </div>
        <div>
            <label for="iv2-f-resp">Responsabil</label>
            <select class="form-select" id="iv2-f-resp" name="responsabil">
                <option value="">Toți</option>
                <option value="nealocat" <?= ($ivFilters['responsabil'] ?? '') === 'nealocat' ? 'selected' : '' ?>>Nealocat</option>
                <?php foreach ($ivCoordinators as $coordinator): ?>
                    <option value="<?= e((string) $coordinator['id']) ?>" <?= (string) ($ivFilters['responsabil'] ?? '') === (string) $coordinator['id'] ? 'selected' : '' ?>><?= e((string) $coordinator['nume']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <button class="btn btn-outline-secondary iv2-reset w-100" type="reset" data-iv2-reset>Resetează</button>
        </div>
    </form>

    <article class="iv2-table-card" data-iv2-table-card aria-busy="false">
        <div class="iv2-table-head">
            <h2>Intervenții planificate și în derulare</h2>
            <span data-iv2-total aria-live="polite"></span>
        </div>
        <div class="table-responsive">
            <table class="iv2-table">
                <thead>
                    <tr>
                        <th class="iv2-chevron"><span class="visually-hidden">Selectat</span></th>
                        <th>ID / Sursă</th>
                        <th title="Data înregistrării sesizării">Data</th>
                        <th>Vehicul</th>
                        <th>Problemă raportată</th>
                        <th class="text-center">Componente</th>
                        <th>Programare / Service</th>
                        <th>Responsabil</th>
                        <th>Prioritate</th>
                        <th>Status</th>
                        <th>Acțiuni</th>
                    </tr>
                </thead>
                <tbody data-iv2-rows></tbody>
            </table>
        </div>
        <div class="iv2-table-foot">
            <label>Afișează
                <select class="form-select" data-iv2-per-page aria-label="Înregistrări pe pagină">
                    <?php foreach ([10, 25, 50, 100] as $size): ?>
                        <option value="<?= $size ?>" <?= (int) ($boot['list']['per_page'] ?? 10) === $size ? 'selected' : '' ?>><?= $size ?></option>
                    <?php endforeach; ?>
                </select>înregistrări</label>
            <nav class="iv2-pager" data-iv2-pager aria-label="Paginare"></nav>
        </div>
    </article>
</div>

<aside class="iv2-drawer" data-iv2-drawer role="dialog" aria-modal="false" aria-labelledby="iv2-drawer-title" aria-hidden="true" tabindex="-1">
    <div class="iv2-resizer" data-iv2-resizer role="separator" aria-orientation="vertical" aria-label="Redimensionează panoul" tabindex="0"></div>
    <header class="iv2-drawer-header">
        <div class="iv2-drawer-topline">
            <span class="iv2-drawer-id" data-iv2-d-id></span>
            <span class="iv2-dot" aria-hidden="true"></span>
            <span data-iv2-d-source></span>
            <div class="ms-auto">
                <span data-iv2-d-status></span>
                <div class="dropdown" data-iv2-d-menu-wrap>
                    <button class="iv2-close" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Mai multe acțiuni"><i class="bi bi-three-dots-vertical"></i></button>
                    <ul class="dropdown-menu dropdown-menu-end iv2-menu" data-iv2-d-menu></ul>
                </div>
                <button class="iv2-close" type="button" data-iv2-close aria-label="Închide panoul"><i class="bi bi-x-lg"></i></button>
            </div>
        </div>
        <h2 class="iv2-drawer-title" id="iv2-drawer-title" data-iv2-d-title></h2>
        <div class="iv2-drawer-subtitle" data-iv2-d-subtitle></div>
        <nav class="iv2-drawer-tabs" role="tablist" aria-label="Secțiuni intervenție">
            <?php foreach (['sumar' => 'Sumar', 'lucrari' => 'Componente și lucrări', 'costuri' => 'Costuri', 'documente' => 'Documente', 'istoric' => 'Istoric'] as $tabKey => $tabLabel): ?>
                <button class="iv2-drawer-tab" type="button" role="tab" data-iv2-tab="<?= e($tabKey) ?>" aria-selected="false" aria-controls="iv2-panel-<?= e($tabKey) ?>"><?= e($tabLabel) ?><span data-iv2-tab-count="<?= e($tabKey) ?>"></span></button>
            <?php endforeach; ?>
        </nav>
    </header>
    <div class="iv2-drawer-body" data-iv2-d-body aria-live="polite"></div>
    <footer class="iv2-drawer-foot" data-iv2-d-foot></footer>
</aside>

<!-- Lucrare pe componenta (adaugare / editare) -->
<div class="modal fade iv2-modal" id="iv2ItemModal" tabindex="-1" aria-labelledby="iv2ItemModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form class="modal-content" data-iv2-item-form novalidate>
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="iv2ItemModalTitle">Componentă și lucrare</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
            </div>
            <div class="modal-body" data-iv2-item-body></div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Renunță</button>
                <button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1"></i>Salvează lucrarea</button>
            </div>
        </form>
    </div>
</div>

<!-- Selectare multipla din Registru piese & lucrari -->
<div class="modal fade iv2-modal" id="iv2PickerModal" tabindex="-1" aria-labelledby="iv2PickerModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <form class="modal-content" data-iv2-picker-form novalidate>
            <div class="modal-header">
                <div>
                    <h2 class="modal-title fs-5" id="iv2PickerModalTitle">Selectează din registru</h2>
                    <p class="text-muted small mb-0">Componentele din Registru piese &amp; lucrări (Reparații Auto), filtrate pentru vehiculul intervenției.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
            </div>
            <div class="modal-body" data-iv2-picker-host></div>
            <div class="modal-footer">
                <span class="me-auto text-muted small" data-iv2-picker-count></span>
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Renunță</button>
                <button class="btn btn-primary" type="submit"><i class="bi bi-plus-lg me-1"></i>Adaugă componentele</button>
            </div>
        </form>
    </div>
</div>

<!-- Finalizare controlata -->
<div class="modal fade iv2-modal" id="iv2FinalizeModal" tabindex="-1" aria-labelledby="iv2FinalizeModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <form class="modal-content" data-iv2-finalize-form novalidate>
            <div class="modal-header">
                <div>
                    <h2 class="modal-title fs-5" id="iv2FinalizeModalTitle">Finalizează intervenția</h2>
                    <p class="text-muted small mb-0">Confirmă rezultatul fiecărei lucrări. Doar înlocuirile confirmate intră în istoricul componentei și resetează monitorizarea uzurii.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
            </div>
            <div class="modal-body" data-iv2-finalize-body></div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Renunță</button>
                <button class="btn btn-success" type="submit"><i class="bi bi-check2-circle me-1"></i>Confirmă finalizarea</button>
            </div>
        </form>
    </div>
</div>

<script type="application/json" id="iv2-boot"><?= json_encode($ivBootJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
