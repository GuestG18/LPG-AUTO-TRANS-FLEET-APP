<?php
// Documente obligatorii (?page=documente&action=required_documents).
// Pagina e randata integral din `state` (aceeasi structura ca raspunsurile JSON ale
// actiunii required_documents_api): tabelul, sidebar-ul, KPI-urile si matricea citesc
// acelasi set de date, deci nu pot iesi din sincron.
$state = is_array($state ?? null) ? $state : null;
$canManage = (bool) ($canManage ?? false);

$pageConfig = [
    'state' => $state,
    'canManage' => $canManage,
    'csrf' => csrf_token(),
    'urls' => [
        'state' => build_query_url(['page' => 'documente', 'action' => 'required_documents', 'format' => 'json']),
        'api' => build_query_url(['page' => 'documente', 'action' => 'required_documents_api']),
        'history' => build_query_url(['page' => 'documente', 'action' => 'required_documents_history']),
        'costs' => build_query_url(['page' => 'configurare_costuri_documente_vehicule_override']),
        'types' => build_query_url(['page' => 'configurare_costuri_documente_vehicule_override', 'action' => 'manage_document_type_config']),
    ],
];
?>
<link rel="stylesheet" href="<?= e(url('assets/css/documente-obligatorii.css?v=' . (string) @filemtime(BASE_PATH . '/assets/css/documente-obligatorii.css'))) ?>">

<?php $vehicleDocumentsActiveTab = 'obligatorii'; require __DIR__ . '/_vehicle_documents_tabs.php'; ?>

<div class="rdc-page" data-rdc-root>
    <?php if ($state === null): ?>
        <div class="rdc-card rdc-empty-state">Configurarea documentelor obligatorii nu a putut fi încărcată.</div>
    <?php else: ?>

    <!-- A. Antet -->
    <header class="rdc-header">
        <div class="rdc-header-text">
            <h1 class="rdc-title">Documente obligatorii</h1>
            <p class="rdc-subtitle">Configurează tipurile de documente obligatorii pentru fiecare categorie de vehicule. Un vehicul fără un document obligatoriu (sau cu el expirat) devine <strong>inactiv</strong> și apare în alertele din Dashboard.</p>
        </div>
        <div class="rdc-header-actions">
            <?php if ($canManage): ?>
                <button type="button" class="rdc-btn rdc-btn-primary" data-rdc-action="create-type"><i class="bi bi-plus-lg" aria-hidden="true"></i>Adaugă tip document</button>
            <?php endif; ?>
            <button type="button" class="rdc-btn rdc-btn-outline-primary" data-rdc-action="show-matrix"><i class="bi bi-grid" aria-hidden="true"></i>Matrice comparativă</button>
            <button type="button" class="rdc-btn rdc-btn-outline" data-rdc-action="history"><i class="bi bi-clock-history" aria-hidden="true"></i>Istoric modificări</button>
        </div>
    </header>

    <!-- B. KPI -->
    <section class="rdc-kpis" aria-label="Rezumat">
        <div class="rdc-kpi rdc-kpi-blue" title="Tipuri de vehicul care au cel puțin un document configurat.">
            <span class="rdc-kpi-icon"><i class="bi bi-truck-front-fill" aria-hidden="true"></i></span>
            <div><div class="rdc-kpi-label">Categorii vehicule</div><div class="rdc-kpi-value" data-rdc-kpi="categories">0</div><div class="rdc-kpi-sub">categorii configurate</div></div>
        </div>
        <div class="rdc-kpi rdc-kpi-green" title="Tipuri de document distincte configurate pe cel puțin o categorie.">
            <span class="rdc-kpi-icon"><i class="bi bi-file-earmark-text-fill" aria-hidden="true"></i></span>
            <div><div class="rdc-kpi-label">Tipuri documente</div><div class="rdc-kpi-value" data-rdc-kpi="document_types">0</div><div class="rdc-kpi-sub">tipuri configurate</div></div>
        </div>
        <div class="rdc-kpi rdc-kpi-orange" title="Perechi document × categorie marcate obligatorii.">
            <span class="rdc-kpi-icon"><i class="bi bi-check-circle-fill" aria-hidden="true"></i></span>
            <div><div class="rdc-kpi-label">Documente obligatorii active</div><div class="rdc-kpi-value" data-rdc-kpi="required_assignments">0</div><div class="rdc-kpi-sub">asignări active în total</div></div>
        </div>
        <div class="rdc-kpi rdc-kpi-red" title="Vehicule cărora le lipsește sau le-a expirat cel puțin un document obligatoriu (aceeași regulă ca alertele din Dashboard).">
            <span class="rdc-kpi-icon"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i></span>
            <div><div class="rdc-kpi-label">Necesită atenție</div><div class="rdc-kpi-value" data-rdc-kpi="attention_vehicles">0</div><div class="rdc-kpi-sub" data-rdc-kpi-attention-sub>vehicule cu documente lipsă sau expirate</div></div>
        </div>
    </section>

    <!-- C. Categorii -->
    <section class="rdc-groups" data-rdc-groups aria-label="Categorii de vehicule"></section>

    <!-- D. Configurare + sidebar -->
    <div class="rdc-main">
        <section class="rdc-card rdc-config">
            <div class="rdc-card-head">
                <i class="bi bi-gear-fill rdc-head-icon" aria-hidden="true"></i>
                <div>
                    <h2 class="rdc-card-title">Configurare pe categorie</h2>
                    <p class="rdc-card-desc">Selectează o categorie de vehicule pentru a configura documentele obligatorii.</p>
                </div>
            </div>

            <div class="rdc-cat-tabs" role="tablist" aria-label="Categorie vehicule" data-rdc-cat-tabs></div>

            <div class="rdc-toolbar">
                <label class="rdc-search">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="search" placeholder="Caută document..." aria-label="Caută document" data-rdc-search>
                </label>
                <div class="rdc-filter">
                    <span class="rdc-filter-label">Afișează:</span>
                    <div class="rdc-segmented" role="group" aria-label="Filtru documente" data-rdc-filters>
                        <button type="button" class="is-active" data-rdc-filter="all" aria-pressed="true">Toate</button>
                        <button type="button" data-rdc-filter="required" aria-pressed="false">Obligatorii</button>
                        <button type="button" data-rdc-filter="unconfigured" aria-pressed="false">Neconfigurate</button>
                    </div>
                </div>
            </div>

            <div class="rdc-table-wrap">
                <table class="rdc-table">
                    <colgroup>
                        <col style="width:44px"><col style="width:26%"><col style="width:12%"><col style="width:18%"><col style="width:19%"><col style="width:14%"><col style="width:108px">
                    </colgroup>
                    <thead>
                        <tr>
                            <th></th>
                            <th>Document</th>
                            <th>Obligatoriu <i class="bi bi-info-circle" title="Activ = vehiculul din această categorie trebuie să aibă documentul." aria-hidden="true"></i></th>
                            <th>Data expirare necesară <i class="bi bi-info-circle" title="Dacă formularul Documente cere data de expirare pentru această categorie." aria-hidden="true"></i></th>
                            <th>Cost / valabilitate <i class="bi bi-info-circle" title="Cost și valabilitate implicite; prețurile pe vehicul se setează în Configurare Costuri." aria-hidden="true"></i></th>
                            <th>Status</th>
                            <th class="rdc-col-actions">Acțiuni</th>
                        </tr>
                    </thead>
                    <tbody data-rdc-rows></tbody>
                </table>
            </div>

            <?php if ($canManage): ?>
                <div class="rdc-add-row">
                    <span class="rdc-add-plus" aria-hidden="true"><i class="bi bi-plus-lg"></i></span>
                    <div class="rdc-add-text">
                        <div class="rdc-add-title">Adaugă document în această categorie</div>
                        <div class="rdc-add-desc">Selectează un tip document din listă sau adaugă unul nou.</div>
                    </div>
                    <button type="button" class="rdc-btn rdc-btn-outline-primary" data-rdc-action="add-to-category"><i class="bi bi-plus-lg" aria-hidden="true"></i>Adaugă document</button>
                </div>
            <?php endif; ?>
        </section>

        <aside class="rdc-side">
            <section class="rdc-card">
                <div class="rdc-card-head rdc-card-head-tight">
                    <i class="bi bi-truck-front-fill rdc-head-icon" aria-hidden="true"></i>
                    <h2 class="rdc-card-title">Detalii categorie selectată</h2>
                </div>
                <div class="rdc-detail-banner">
                    <span class="rdc-detail-icon" data-rdc-detail-icon><i class="bi bi-truck" aria-hidden="true"></i></span>
                    <div><div class="rdc-detail-name" data-rdc-detail-name></div><div class="rdc-detail-sub" data-rdc-detail-sub></div></div>
                </div>
                <div class="rdc-metrics">
                    <div class="rdc-metric"><span class="rdc-metric-icon rdc-m-slate"><i class="bi bi-file-earmark-text" aria-hidden="true"></i></span><div><div class="rdc-metric-value" data-rdc-detail="total">0</div><div class="rdc-metric-label">Total documente</div></div></div>
                    <div class="rdc-metric"><span class="rdc-metric-icon rdc-m-green"><i class="bi bi-check-circle" aria-hidden="true"></i></span><div><div class="rdc-metric-value" data-rdc-detail="required">0</div><div class="rdc-metric-label">Obligatorii</div></div></div>
                    <div class="rdc-metric"><span class="rdc-metric-icon rdc-m-slate"><i class="bi bi-slash-circle" aria-hidden="true"></i></span><div><div class="rdc-metric-value" data-rdc-detail="optional">0</div><div class="rdc-metric-label">Opționale</div></div></div>
                    <div class="rdc-metric"><span class="rdc-metric-icon rdc-m-blue"><i class="bi bi-people" aria-hidden="true"></i></span><div><div class="rdc-metric-value" data-rdc-detail="vehicles">0</div><div class="rdc-metric-label">Vehicule</div></div></div>
                </div>
            </section>

            <section class="rdc-card">
                <div class="rdc-card-head rdc-card-head-split">
                    <div class="rdc-card-head">
                        <i class="bi bi-file-earmark-text-fill rdc-head-icon" aria-hidden="true"></i>
                        <div>
                            <h2 class="rdc-card-title">Gestionare tipuri documente</h2>
                            <p class="rdc-card-desc">Adaugă, editează sau șterge tipurile de documente disponibile în sistem.</p>
                        </div>
                    </div>
                    <?php if ($canManage): ?>
                        <button type="button" class="rdc-btn rdc-btn-outline-primary rdc-btn-sm" data-rdc-action="create-type"><i class="bi bi-plus-lg" aria-hidden="true"></i>Tip document nou</button>
                    <?php endif; ?>
                </div>
                <ul class="rdc-type-list" data-rdc-types></ul>
                <button type="button" class="rdc-link-btn" data-rdc-action="toggle-types" aria-expanded="false"></button>
            </section>

            <section class="rdc-card">
                <div class="rdc-card-head rdc-card-head-tight">
                    <i class="bi bi-info-circle-fill rdc-head-icon" aria-hidden="true"></i>
                    <h2 class="rdc-card-title">Reguli &amp; impact</h2>
                </div>
                <ul class="rdc-rules">
                    <li><i class="bi bi-exclamation-triangle-fill rdc-c-red" aria-hidden="true"></i><span>Un vehicul fără un document obligatoriu sau cu document expirat devine <strong>inactiv</strong> în sistem.</span></li>
                    <li><i class="bi bi-bell-fill rdc-c-amber" aria-hidden="true"></i><span>Vehiculele inactive apar în alertele din Dashboard.</span></li>
                    <li><i class="bi bi-gear-fill rdc-c-blue" aria-hidden="true"></i><span>Costurile și perioada de valabilitate se configurează din <a href="<?= e($pageConfig['urls']['costs']) ?>">Configurare Costuri</a>.</span></li>
                </ul>
            </section>
        </aside>
    </div>

    <!-- E. Matrice comparativa -->
    <section class="rdc-card rdc-matrix" id="rdc-matrix" data-rdc-matrix>
        <div class="rdc-matrix-head">
            <div class="rdc-matrix-title-wrap">
                <i class="bi bi-bar-chart-fill rdc-head-icon" aria-hidden="true"></i>
                <h2 class="rdc-card-title">Matrice comparativă</h2>
                <span class="rdc-matrix-desc">Compară rapid documentele obligatorii între toate categoriile de vehicule. Folosește această vizualizare pentru o imagine de ansamblu.</span>
            </div>
            <button type="button" class="rdc-btn rdc-btn-outline-primary rdc-btn-sm" data-rdc-action="toggle-matrix" aria-expanded="false" aria-controls="rdc-matrix-table"><i class="bi bi-chevron-right" aria-hidden="true"></i><span>Afișează matricea completă</span></button>
        </div>
        <div class="rdc-matrix-wrap" data-rdc-matrix-wrap>
            <table class="rdc-matrix-table" id="rdc-matrix-table">
                <thead data-rdc-matrix-head></thead>
                <tbody data-rdc-matrix-body></tbody>
            </table>
        </div>
    </section>

    <?php endif; ?>
</div>

<!-- Modale (in afara containerului paginii, ca sa nu mosteneasca overflow-ul tabelelor) -->
<div class="modal fade rdc-modal" id="rdcFormModal" tabindex="-1" aria-labelledby="rdcFormModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" data-rdc-form novalidate>
            <div class="modal-header">
                <h3 class="modal-title" id="rdcFormModalTitle" data-rdc-form-title></h3>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
            </div>
            <div class="modal-body" data-rdc-form-body></div>
            <div class="modal-footer" data-rdc-form-footer>
                <button type="button" class="rdc-btn rdc-btn-outline" data-bs-dismiss="modal">Renunță</button>
                <button type="submit" class="rdc-btn rdc-btn-primary" data-rdc-form-submit>Salvează</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade rdc-modal" id="rdcConfirmModal" tabindex="-1" aria-labelledby="rdcConfirmModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="rdcConfirmModalTitle" data-rdc-confirm-title></h3>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
            </div>
            <div class="modal-body" data-rdc-confirm-body></div>
            <div class="modal-footer">
                <button type="button" class="rdc-btn rdc-btn-outline" data-bs-dismiss="modal">Renunță</button>
                <button type="button" class="rdc-btn rdc-btn-danger" data-rdc-confirm-ok>Șterge</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade rdc-modal" id="rdcHistoryModal" tabindex="-1" aria-labelledby="rdcHistoryModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="rdcHistoryModalTitle">Istoric modificări</h3>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
            </div>
            <div class="modal-body" data-rdc-history-body></div>
        </div>
    </div>
</div>

<div class="rdc-toasts" aria-live="polite" aria-atomic="false" data-rdc-toasts></div>

<script type="application/json" id="rdc-config"><?= json_encode($pageConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="<?= e(url('assets/js/documente-obligatorii.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/documente-obligatorii.js'))) ?>"></script>
