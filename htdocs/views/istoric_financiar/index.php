<?php
/**
 * Istoric Financiar Vehicul — macheta aprobata, implementata 1:1 in shell-ul Fleet.
 * Toate valorile vin din backend (VehicleFinancialRegisterService); view-ul doar randeaza.
 */
$permissions = $permissions ?? ['manage' => false, 'clasificare' => false, 'proiecte' => false];
$config = [
    'urls' => [
        'list' => build_query_url(['page' => 'istoric_financiar', 'action' => 'list']),
        'detail' => build_query_url(['page' => 'istoric_financiar', 'action' => 'detail']),
        'op' => build_query_url(['page' => 'istoric_financiar', 'action' => 'op']),
        'file' => build_query_url(['page' => 'istoric_financiar', 'action' => 'file']),
        'kpiBreakdown' => build_query_url(['page' => 'istoric_financiar']),
        'lineView' => build_query_url(['page' => 'istoric_financiar', 'action' => 'line_view']),
        'documente' => build_query_url(['page' => 'documente']),
    ],
    'csrf' => csrf_token(),
    'can' => $permissions,
    'categories' => $categories ?? [],
    'drivers' => $drivers ?? [],
    'vehicles' => $vehicles ?? [],
];
$cssVersion = (string) @filemtime(BASE_PATH . '/assets/css/istoric-financiar.css');
$jsVersion = (string) @filemtime(BASE_PATH . '/assets/js/istoric-financiar.js');
?>
<link rel="stylesheet" href="<?= e(url('assets/css/morph-card.css?v=' . (string) @filemtime(BASE_PATH . '/assets/css/morph-card.css'))) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/istoric-financiar.css?v=' . $cssVersion)) ?>">

<div class="ifv" id="ifvRoot">
    <!-- A. Antet -->
    <nav class="ifv-crumbs" aria-label="breadcrumb">
        <span>Financiar</span><i class="bi bi-chevron-right" aria-hidden="true"></i><strong>Istoric Vehicul</strong>
    </nav>
    <div class="ifv-head">
        <div>
            <h1 class="ifv-title">Istoric Vehicul</h1>
            <p class="ifv-subtitle">Dosare de achiziție, investiții, costuri și evoluția valorii fiecărui vehicul.</p>
        </div>
        <div class="ifv-head-actions">
            <label class="ifv-search" for="ifvSearch">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="search" id="ifvSearch" placeholder="Caută vehicul, VIN, vânzător, document…" autocomplete="off">
                <kbd>Ctrl + K</kbd>
            </label>
            <?php if ($permissions['manage']): ?>
            <button type="button" class="ifv-btn-primary" data-ifv-action="create-dossier"><i class="bi bi-plus-lg" aria-hidden="true"></i>Dosar achiziție</button>
            <?php endif; ?>
        </div>
    </div>

    <!-- B. Carduri -->
    <?php /* Cardurile KPI: dimensiuni fixe, navigare internă (assets/js/ifv-kpi-cards.js pe MorphCard). */ ?>
    <div class="ifv-kpis" id="ifvKpis">
        <div class="ifv-kpi ifv-kpi-morph" data-ifv-card="vehicule" role="group" aria-label="Total vehicule"><div class="mc-stage" data-stage></div></div>
        <div class="ifv-kpi ifv-kpi-morph" data-ifv-card="valoare_initiala" role="group" aria-label="Valoare inițială (total)"><div class="mc-stage" data-stage></div></div>
        <div class="ifv-kpi ifv-kpi-morph is-green" data-ifv-card="capitalizat" role="group" aria-label="Valoare capitalizată"><div class="mc-stage" data-stage></div></div>
        <div class="ifv-kpi ifv-kpi-morph is-orange" data-ifv-card="necapitalizabile" role="group" aria-label="Cheltuieli necapitalizabile"><div class="mc-stage" data-stage></div></div>
        <div class="ifv-kpi ifv-kpi-morph" data-ifv-card="derulare" role="group" aria-label="Dosare în derulare"><div class="mc-stage" data-stage></div></div>
    </div>

    <!-- C. Filtre -->
    <div class="ifv-toolbar">
        <div class="ifv-seg" role="tablist">
            <button type="button" class="ifv-seg-btn is-active" data-tab="toate"><span>Toate (<b data-count="toate">0</b>)</span></button>
            <button type="button" class="ifv-seg-btn" data-tab="derulare"><span class="ifv-dot is-amber"></span><span>În derulare (<b data-count="derulare">0</b>)</span></button>
            <button type="button" class="ifv-seg-btn" data-tab="finalizate"><span class="ifv-dot is-green"></span><span>Finalizate (<b data-count="finalizate">0</b>)</span></button>
        </div>
        <span class="ifv-toolbar-sep" aria-hidden="true"></span>
        <label class="ifv-select"><span>Status:</span>
            <select id="ifvStatus">
                <option value="">Toate</option>
                <option value="in_analiza">În analiză</option>
                <option value="contractat">Contractat</option>
                <option value="in_plata">În plată</option>
                <option value="in_transport">În transport</option>
                <option value="receptionat">Recepționat</option>
                <option value="inmatriculat">Înmatriculat</option>
                <option value="in_adaptare">În adaptare</option>
                <option value="pus_in_functiune">Finalizat</option>
                <option value="anulat">Anulat</option>
                <option value="fara_dosar">Fără dosar</option>
            </select>
        </label>
        <label class="ifv-select"><span>Țară proveniență:</span>
            <select id="ifvTara"><option value="">Toate</option></select>
        </label>
        <label class="ifv-select ifv-select-period"><i class="bi bi-calendar3" aria-hidden="true"></i><span>Perioadă:</span>
            <select id="ifvPerioada">
                <option value="">Total</option>
                <option value="12_luni">Ultimele 12 luni</option>
                <option value="3_ani">Ultimii 3 ani</option>
                <?php for ($y = (int) date('Y'); $y >= (int) date('Y') - 6; $y--): ?><option value="<?= $y ?>"><?= $y ?></option><?php endfor; ?>
            </select>
        </label>
        <div class="dropdown">
            <button type="button" class="ifv-more" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Acțiuni"><i class="bi bi-three-dots" aria-hidden="true"></i></button>
            <ul class="dropdown-menu dropdown-menu-end ifv-menu">
                <li><button class="dropdown-item" type="button" data-ifv-action="refresh"><i class="bi bi-arrow-clockwise"></i>Reîmprospătează</button></li>
                <li><button class="dropdown-item" type="button" data-ifv-action="reset"><i class="bi bi-x-circle"></i>Resetează filtrele</button></li>
                <li><button class="dropdown-item" type="button" data-ifv-action="collapse-all"><i class="bi bi-arrows-collapse"></i>Restrânge toate rândurile</button></li>
            </ul>
        </div>
    </div>

    <!-- D. Tabel vehicule -->
    <div class="ifv-table-card">
        <table class="ifv-table">
            <colgroup>
                <col style="width:4.2%"><col style="width:15%"><col style="width:13.2%"><col style="width:11%"><col style="width:9.6%">
                <col style="width:9.6%"><col style="width:9.6%"><col style="width:9.8%"><col style="width:10%"><col style="width:8%">
            </colgroup>
            <thead>
                <tr>
                    <th></th><th>Vehicul</th><th>VIN</th><th>Proveniență</th><th>Data achiziției</th>
                    <th class="is-num">Preț achiziție</th><th class="is-num">Costuri aducere</th><th class="is-num">Cost inițial</th><th class="is-center">Status</th><th class="is-right">Acțiuni</th>
                </tr>
            </thead>
            <tbody id="ifvRows">
                <tr class="ifv-loading-row"><td colspan="10"><span class="ifv-spinner"></span> Se încarcă…</td></tr>
            </tbody>
        </table>
        <div class="ifv-pager" id="ifvPager"></div>
    </div>
</div>

<!-- Formular generic (creare / editare) -->
<div class="modal fade" id="ifvFormModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form class="modal-content ifv-modal" id="ifvForm" novalidate>
            <div class="modal-header">
                <h5 class="modal-title" id="ifvFormTitle">Formular</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
            </div>
            <div class="modal-body">
                <div class="ifv-form-hint" id="ifvFormHint" hidden></div>
                <div class="ifv-form-grid" id="ifvFormFields"></div>
                <div class="ifv-form-error" id="ifvFormError" hidden></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="ifv-btn-ghost" data-bs-dismiss="modal">Renunță</button>
                <button type="submit" class="ifv-btn-primary" id="ifvFormSubmit">Salvează</button>
            </div>
        </form>
    </div>
</div>

<!-- Previzualizare document -->
<div class="modal fade" id="ifvPreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content ifv-modal">
            <div class="modal-header">
                <h5 class="modal-title" id="ifvPreviewTitle">Document</h5>
                <a class="ifv-btn-ghost ms-auto me-2" id="ifvPreviewOpen" href="#" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> Deschide</a>
                <button type="button" class="btn-close ms-0" data-bs-dismiss="modal" aria-label="Închide"></button>
            </div>
            <div class="modal-body p-0"><iframe id="ifvPreviewFrame" class="ifv-preview-frame" title="Document"></iframe></div>
        </div>
    </div>
</div>

<div class="ifv-toast" id="ifvToast" role="status" aria-live="polite"></div>

<script>window.IFV_CONFIG = <?= json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="<?= e(url('assets/js/morph-card.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/morph-card.js'))) ?>" defer></script>
<script src="<?= e(url('assets/js/ifv-kpi-cards.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/ifv-kpi-cards.js'))) ?>" defer></script>
<script src="<?= e(url('assets/js/istoric-financiar.js?v=' . $jsVersion)) ?>" defer></script>
<?php /* Fisa inregistrarii de cost: acelasi panou ca fisa cursei din Dispecer curse. */ ?>
<link rel="stylesheet" href="<?= e(url('assets/css/race-view-overlay.css?v=' . (string) @filemtime(BASE_PATH . '/assets/css/race-view-overlay.css'))) ?>">
<script src="<?= e(url('assets/js/race-view-overlay.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/race-view-overlay.js'))) ?>"></script>
