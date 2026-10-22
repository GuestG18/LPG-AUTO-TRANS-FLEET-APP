<?php
/**
 * Centru Kilometraj — continutul paginii (meniul si antetul aplicatiei raman cele
 * existente). Referinta vizuala: macheta „Centru Kilometraj” (V3).
 *
 * Antetul, KPI-urile si filtrele au scheletul aici; cardurile, odometrele, panoul T0
 * si istoricul sunt desenate de assets/js/centru-kilometraj.js din JSON-ul #kmPageData,
 * ca o actualizare sa poata anima doar odometrele care chiar s-au schimbat.
 *
 * Asteapta: $dashboard (OdometerCenterService::buildDashboard), $permissions.
 */
$pageJson = json_encode([
    'dashboard' => $dashboard,
    'permissions' => $permissions,
    'token' => csrf_token(),
    'urls' => [
        'data' => build_query_url(['page' => 'centru_kilometraj', 'action' => 'data']),
        'sync' => build_query_url(['page' => 'centru_kilometraj', 'action' => 'sync']),
        't0_lookup' => build_query_url(['page' => 'centru_kilometraj', 'action' => 't0_lookup']),
        't0_save' => build_query_url(['page' => 'centru_kilometraj', 'action' => 't0_save']),
        'history' => build_query_url(['page' => 'centru_kilometraj', 'action' => 'history']),
        'save_policy' => build_query_url(['page' => 'centru_kilometraj', 'action' => 'save_policy']),
        'fuel' => build_query_url(['page' => 'carburanti']),
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_PRESERVE_ZERO_FRACTION);
?>
<link rel="stylesheet" href="<?= e(url('assets/css/centru-kilometraj.css?v=' . (string) @filemtime(BASE_PATH . '/assets/css/centru-kilometraj.css'))) ?>">

<div class="kmc" id="kmCenter" data-panel="closed">
    <header class="kmc-head">
        <div class="kmc-head__title">
            <span class="kmc-head__icon" aria-hidden="true"><i class="bi bi-speedometer2"></i></span>
            <div>
                <h1>Centru Kilometraj
                    <button type="button" class="kmc-info" data-kmc-info aria-label="Cum se citesc sursele" title="Cum se citesc sursele"><i class="bi bi-info-circle"></i></button>
                </h1>
                <p>Monitorizează kilometrajul real al flotei din 2 surse: SAS Fleet și CardOil Refill, cu T0 individual și citiri din API.</p>
            </div>
        </div>
        <div class="kmc-head__actions">
            <button type="button" class="kmc-btn kmc-btn--primary" data-kmc-sync>
                <i class="bi bi-arrow-repeat" aria-hidden="true"></i><span>Actualizează datele</span>
            </button>
            <div class="kmc-lastsync" data-kmc-lastsync>
                <span class="kmc-dot" aria-hidden="true"></span>
                <span><small>Ultima sincronizare:</small><strong>—</strong></span>
            </div>
        </div>
    </header>

    <div class="kmc-infobox" data-kmc-infobox hidden>
        <p><strong>SAS Fleet</strong> = odometrul GPS (kmIndex) de la sfârșitul fiecărei deplasări. Nu e calibrat pe bord, deci valoarea absolută poate diferi de bord; km de la T0 sunt diferența a două citiri SAS.</p>
        <p><strong>CardOil Refill</strong> = odometrul tastat de șofer la pompă, la momentul alimentării. Între alimentări nu există citiri: ora afișată e ora alimentării, nu a sincronizării.</p>
        <p>Cele două surse nu se adună. Diferența dintre ele e doar un diagnostic, calculat între aceleași două alimentări. Semiremorcile nu au GPS SAS și nu alimentează: nu au citiri proprii.</p>
    </div>

    <section class="kmc-kpis" data-kmc-kpis aria-label="Indicatori"></section>

    <div class="kmc-layout">
        <div class="kmc-main">
            <div class="kmc-filters" role="search">
                <label class="kmc-search">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="search" data-kmc-filter="q" placeholder="Caută după număr de înmatriculare, model, categorie…" autocomplete="off">
                </label>
                <select data-kmc-filter="category" aria-label="Categorie de capacitate"><option value="">Toate categoriile</option></select>
                <select data-kmc-filter="provider" aria-label="Furnizor">
                    <option value="">Toți furnizorii API</option>
                    <option value="sas">Cu SAS Fleet</option>
                    <option value="cardoil">Cu CardOil Refill</option>
                    <option value="both">Cu ambele surse</option>
                    <option value="none">Fără nicio sursă</option>
                </select>
                <select data-kmc-filter="status" aria-label="Status">
                    <option value="activ">Active</option>
                    <option value="">Toate statusurile</option>
                    <option value="inactiv">Inactive</option>
                    <option value="stale">Date neactualizate</option>
                    <option value="anomaly">Cu anomalii</option>
                    <option value="no_t0">Fără T0</option>
                </select>
                <select data-kmc-filter="type" aria-label="Tip vehicul"><option value="">Toate vehiculele</option></select>
                <select data-kmc-filter="capacity" aria-label="Capacitate reală"><option value="">Capacitate reală</option></select>
                <div class="kmc-filters__tools">
                    <select data-kmc-filter="group" aria-label="Grupare">
                        <option value="category">Ordine după capacitate</option>
                        <option value="type">Grupare după tip vehicul</option>
                        <option value="none">Fără grupare</option>
                    </select>
                    <div class="kmc-seg" role="group" aria-label="Afișare">
                        <button type="button" data-kmc-view="grid" class="is-active" title="Carduri" aria-label="Carduri"><i class="bi bi-grid-3x2-gap"></i></button>
                        <button type="button" data-kmc-view="list" title="Listă" aria-label="Listă"><i class="bi bi-list-ul"></i></button>
                    </div>
                    <button type="button" class="kmc-iconbtn" data-kmc-toggle-all title="Restrânge / extinde grupele" aria-label="Restrânge / extinde grupele"><i class="bi bi-arrows-collapse"></i></button>
                </div>
            </div>

            <div class="kmc-groups" data-kmc-groups aria-live="polite"></div>
        </div>

        <aside class="kmc-panel" data-kmc-panel aria-label="Setează T0" hidden></aside>
    </div>

    <div class="kmc-toast" data-kmc-toast role="status" hidden></div>
</div>

<script type="application/json" id="kmPageData"><?= $pageJson ?: '{}' ?></script>
<script src="<?= e(url('assets/js/centru-kilometraj.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/centru-kilometraj.js'))) ?>"></script>
