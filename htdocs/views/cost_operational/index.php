<?php
/**
 * Pagina "Cost operațional / km" — reproduce mockup-ul 1:1 în shell-ul Fleet.
 * Toate valorile sunt calculate server-side (OperationalCostService) din date
 * reale; view-ul doar randează payload-ul JSON. Simularea nu scrie nimic.
 */
$schemaReady = !empty($schemaReady);
$canConfigure = !empty($canConfigure);
$beneficiaries = $beneficiaries ?? [];
$settings = $settings ?? [];
$dataUrl = build_query_url(['page' => 'cost_operational', 'action' => 'data']);
$detailsUrl = build_query_url(['page' => 'cost_operational', 'action' => 'details']);
$simulateUrl = build_query_url(['page' => 'cost_operational', 'action' => 'simulate']);
$exportUrl = build_query_url(['page' => 'cost_operational', 'action' => 'export']);
$elementSaveUrl = build_query_url(['page' => 'cost_operational', 'action' => 'element_save']);
$elementToggleUrl = build_query_url(['page' => 'cost_operational', 'action' => 'element_toggle']);
$elementDeleteUrl = build_query_url(['page' => 'cost_operational', 'action' => 'element_delete']);
$settingsSaveUrl = build_query_url(['page' => 'cost_operational', 'action' => 'settings_save']);
?>

<?php if (!$schemaReady): ?>
<div class="alert alert-warning">
    <strong>Configurarea lipsește.</strong> Rulați migrarea
    <code>database/update_cost_operational_km.sql</code> pentru a crea registrul
    elementelor financiare și parametrii de calcul. Pagina funcționează și fără,
    dar fără elementele configurabile.
</div>
<?php endif; ?>

<div class="copkm" id="copkm-root">

    <!-- ===================== HEADER PAGINĂ ===================== -->
    <div class="copkm-pagehead">
        <div>
            <h1 class="copkm-title">Cost opera&#539;ional / km
                <i class="bi bi-info-circle copkm-title-info" role="button" data-copkm-open="methodology" title="Despre metodologia de calcul"></i>
            </h1>
            <p class="copkm-subtitle">Calculeaz&#259; costurile fixe &#537;i variabile transpuse &#238;n lei/km &#537;i analizeaz&#259; activit&#259;&#539;ile pentru decizii corecte.</p>
        </div>
        <div class="copkm-pagehead-right">
            <span class="copkm-updated"><span id="copkm-updated-label">Actualizat: &#8212;</span>
                <i class="bi bi-arrow-clockwise" role="button" id="copkm-refresh" title="Re&#238;ncarc&#259;"></i></span>
            <button type="button" class="copkm-btn copkm-btn-outline" data-copkm-open="methodology">
                <i class="bi bi-info-circle"></i> Despre metodologia de calcul</button>
            <a class="copkm-btn copkm-btn-outline" id="copkm-export" href="<?= e($exportUrl) ?>">
                <i class="bi bi-download"></i> Export raport</a>
        </div>
    </div>

    <!-- ===================== FILTRE ===================== -->
    <div class="copkm-card copkm-filters">
        <div class="copkm-filter-row">
            <div class="copkm-filter">
                <label>Perioad&#259; analiz&#259;</label>
                <input type="month" id="f-period" class="copkm-input" value="<?= e(date('Y-m')) ?>">
            </div>
            <div class="copkm-filter copkm-filter-grow">
                <label>Vedere</label>
                <div class="copkm-seg" id="f-vedere">
                    <button type="button" class="copkm-seg-btn active" data-vedere="overall"><i class="bi bi-bar-chart-fill"></i> Overall (total flot&#259;)</button>
                    <button type="button" class="copkm-seg-btn" data-vedere="beneficiar"><i class="bi bi-person"></i> Pe beneficiar</button>
                    <button type="button" class="copkm-seg-btn" data-vedere="vehicul"><i class="bi bi-truck"></i> Pe vehicul</button>
                    <button type="button" class="copkm-seg-btn" data-vedere="sofer"><i class="bi bi-person-badge"></i> Pe &#537;ofer</button>
                    <button type="button" class="copkm-seg-btn" data-vedere="categorie"><i class="bi bi-grid"></i> Pe categorie</button>
                </div>
            </div>
            <div class="copkm-filter">
                <label>Categorie transport</label>
                <select id="f-categorie" class="copkm-input">
                    <option value="">Toate categoriile</option>
                </select>
            </div>
            <div class="copkm-filter">
                <label>Beneficiar</label>
                <select id="f-beneficiar" class="copkm-input">
                    <option value="">To&#539;i beneficiarii</option>
                    <?php foreach ($beneficiaries as $bid => $bname): ?>
                        <option value="<?= (int) $bid ?>"><?= e($bname) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="copkm-filter">
                <label>Unitate calcul</label>
                <select id="f-unit" class="copkm-input">
                    <option value="lei">lei / km</option>
                    <option value="eur">EUR / km</option>
                </select>
            </div>
            <div class="copkm-filter">
                <label>Curs EUR / RON</label>
                <div class="copkm-eur-wrap">
                    <span class="copkm-eur-sign">&#8364;</span>
                    <input type="text" id="f-eur" class="copkm-input copkm-eur-input" value="<?= e((string) ($settings['eur_ron_rate'] ?? '5.00')) ?>" <?= $canConfigure ? '' : 'readonly' ?>>
                    <i class="bi bi-pencil copkm-eur-edit" title="<?= $canConfigure ? 'Parametru de configurare (se salveaz&#259; la Ruleaz&#259;)' : 'Doar administratorul poate modifica' ?>"></i>
                </div>
            </div>
            <div class="copkm-filter">
                <label>Tip activitate</label>
                <select id="f-km-source" class="copkm-input">
                    <option value="curse_reali">Km parcur&#537;i (real)</option>
                    <option value="curse_facturati">Km factura&#539;i</option>
                </select>
            </div>
        </div>
        <div class="copkm-filter-actions">
            <div class="copkm-note-strip">
                <i class="bi bi-info-circle"></i>
                <span>Valorile sunt calculate pe baza datelor reale din aplica&#539;ie. Modific&#259;rile se aplic&#259; doar &#238;n modul de simulare.</span>
            </div>
            <div class="copkm-filter-buttons">
                <button type="button" class="copkm-btn copkm-btn-light" id="btn-reset"><i class="bi bi-arrow-counterclockwise"></i> Reseteaz&#259; filtrele</button>
                <button type="button" class="copkm-btn copkm-btn-primary" id="btn-run"><i class="bi bi-play-fill"></i> Ruleaz&#259; / Recalculeaz&#259;</button>
            </div>
        </div>
    </div>

    <div id="copkm-error" class="alert alert-danger d-none"></div>
    <div id="copkm-quality" class="copkm-quality d-none"></div>

    <!-- ===================== REZUMAT + BREAK-EVEN ===================== -->
    <div class="copkm-grid-2 copkm-summary-row">
        <div class="copkm-card">
            <div class="copkm-card-head"><span class="copkm-card-title">REZUMAT GENERAL</span><span class="copkm-card-sub" id="rg-scope-label">&#8212; Total flot&#259;</span></div>
            <div class="copkm-kpis">
                <div class="copkm-kpi copkm-kpi-blue">
                    <div class="copkm-kpi-head"><span class="copkm-kpi-ico"><i class="bi bi-bar-chart-fill"></i></span><span>Costuri totale (luna)</span></div>
                    <div class="copkm-kpi-value" id="kpi-total-cost">&#8212;</div>
                    <div class="copkm-kpi-foot" id="kpi-cost-split">Fixe: &#8212; &#183; Variabile: &#8212;</div>
                </div>
                <div class="copkm-kpi copkm-kpi-green">
                    <div class="copkm-kpi-head"><span class="copkm-kpi-ico"><i class="bi bi-graph-up-arrow"></i></span><span>Activitate actual&#259;</span></div>
                    <div class="copkm-kpi-value" id="kpi-activity">&#8212;</div>
                    <div class="copkm-kpi-foot" id="kpi-activity-sub">&#8212;</div>
                </div>
                <div class="copkm-kpi copkm-kpi-orange">
                    <div class="copkm-kpi-head"><span class="copkm-kpi-ico"><i class="bi bi-cash-coin"></i></span><span>Cost total / km</span></div>
                    <div class="copkm-kpi-value" id="kpi-cost-km">&#8212;</div>
                    <div class="copkm-kpi-foot" id="kpi-cost-km-split">Fix: &#8212; &#183; Var: &#8212;</div>
                </div>
                <div class="copkm-kpi copkm-kpi-red">
                    <div class="copkm-kpi-head"><span class="copkm-kpi-ico"><i class="bi bi-exclamation-octagon"></i></span><span>Rezultat actual</span></div>
                    <div class="copkm-kpi-value" id="kpi-result">&#8212;</div>
                    <div class="copkm-kpi-foot"><span id="kpi-result-km">&#8212;</span> <span class="copkm-badge" id="kpi-result-badge"></span></div>
                </div>
            </div>
        </div>

        <div class="copkm-card">
            <div class="copkm-card-head"><span class="copkm-card-title">RECUPERARE / BREAK-EVEN</span><span class="copkm-card-sub" id="be-scope-label">&#8212; Total flot&#259;</span></div>
            <div class="copkm-be-grid">
                <div class="copkm-be-list">
                    <div class="copkm-be-row"><span>Costuri fixe totale:</span><b id="be-fixed">&#8212;</b></div>
                    <div class="copkm-be-row"><span>Costuri variabile totale:</span><b id="be-variable">&#8212;</b></div>
                    <div class="copkm-be-row"><span>Cost total (luna):</span><b id="be-total">&#8212;</b></div>
                    <div class="copkm-be-row"><span>Activitate actual&#259;:</span><b id="be-km">&#8212;</b></div>
                    <div class="copkm-be-row"><span>Cost / km:</span><b id="be-cost-km">&#8212;</b></div>
                    <div class="copkm-be-row"><span>Venit / km:</span><b id="be-rev-km">&#8212;</b></div>
                    <div class="copkm-be-row"><span>Rezultat:</span><b id="be-result" class="copkm-neg">&#8212;</b></div>
                </div>
                <div class="copkm-be-box">
                    <div class="copkm-be-box-title">Break-even (recuperare&#8202;0)</div>
                    <div class="copkm-be-row"><span>Km necesari:</span><b id="be-km-needed">&#8212;</b></div>
                    <div class="copkm-be-row"><span>KM lips&#259;:</span><b id="be-km-missing" class="copkm-neg">&#8212;</b></div>
                    <div class="copkm-be-row"><span>Curse necesare:</span><b id="be-trips-needed">&#8212;</b></div>
                    <div class="copkm-be-row"><span>Venit necesar:</span><b id="be-rev-needed">&#8212;</b></div>
                    <div class="copkm-be-row"><span>Venit suplimentar necesar:</span><b id="be-rev-additional" class="copkm-pos">&#8212;</b></div>
                    <div class="copkm-be-row"><span>Cost / km la break-even:</span><b id="be-cost-km-be">&#8212;</b></div>
                    <div class="copkm-be-reason d-none" id="be-reason"></div>
                </div>
                <div class="copkm-be-ring">
                    <div class="copkm-ring-label">Progres recuperare</div>
                    <svg viewBox="0 0 120 120" class="copkm-ring" id="be-ring">
                        <circle cx="60" cy="60" r="50" class="copkm-ring-bg"></circle>
                        <circle cx="60" cy="60" r="50" class="copkm-ring-fg" id="be-ring-fg" stroke-dasharray="0 314"></circle>
                    </svg>
                    <div class="copkm-ring-center">
                        <div class="copkm-ring-pct" id="be-ring-pct">&#8212;</div>
                        <div class="copkm-ring-sub" id="be-ring-sub">&#8212;</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ===================== STRUCTURĂ + COMPOZIȚIE + ACTUAL vs BE ===================== -->
    <div class="copkm-grid-structure">
        <div class="copkm-card" id="sec-structure">
            <div class="copkm-card-head">
                <span class="copkm-card-title"><span class="copkm-num">1.</span> <span id="structure-title">STRUCTURA COSTURILOR PE CATEGORIE</span></span>
            </div>
            <div class="copkm-table-wrap" id="structure-table-wrap"></div>
            <div class="copkm-table-note" id="structure-note">
                <b>Not&#259;:</b> Costurile fixe sunt alocate pe categorii conform regulilor de alocare definite &#238;n Configur&#259;ri.
            </div>
        </div>

        <div class="copkm-col-right">
            <div class="copkm-card" id="sec-composition">
                <div class="copkm-card-head">
                    <span class="copkm-card-title"><span class="copkm-num">2.</span> COMPOZI&#538;IA COSTULUI / KM <span class="copkm-card-sub" id="comp-scope-label">&#8212; Total flot&#259;</span></span>
                </div>
                <div class="copkm-comp-grid">
                    <div class="copkm-donut-wrap">
                        <svg viewBox="0 0 180 180" id="comp-donut" class="copkm-donut"></svg>
                        <div class="copkm-donut-center">
                            <div class="copkm-donut-value" id="comp-center">&#8212;</div>
                            <div class="copkm-donut-sub" id="comp-center-sub">lei / km</div>
                        </div>
                    </div>
                    <div class="copkm-comp-legend" id="comp-legend"></div>
                </div>
                <a href="#" class="copkm-link" data-copkm-open="elements">Vezi detalierea complet&#259; costuri <i class="bi bi-arrow-right"></i></a>
            </div>

            <div class="copkm-card" id="sec-chart">
                <div class="copkm-card-head">
                    <span class="copkm-card-title"><span class="copkm-num">3.</span> ACTUALITATE vs BREAK-EVEN</span>
                </div>
                <div class="copkm-chart-legend">
                    <span><i style="background:#2563eb"></i> Cost / km (lei)</span>
                    <span><i style="background:#16a34a"></i> Venit / km (lei)</span>
                    <span><i style="background:#dc2626"></i> Rezultat / km (lei)</span>
                </div>
                <svg id="avb-chart" class="copkm-bars" viewBox="0 0 560 300" preserveAspectRatio="xMidYMid meet"></svg>
                <div class="copkm-chart-foot" id="avb-foot"></div>
            </div>
        </div>
    </div>

    <!-- ===================== SIMULARE + TABELE ===================== -->
    <div class="copkm-grid-bottom">
        <div class="copkm-card" id="sec-sim">
            <div class="copkm-card-head">
                <span class="copkm-card-title"><span class="copkm-num">4.</span> SIMULARE <span class="copkm-card-sub">&#8212; Cum pot recupera pierderea?</span></span>
            </div>
            <div class="copkm-sim-grid">
                <div class="copkm-sim-controls">
                    <div class="copkm-sim-label-head">Variabile ajustabile</div>
                    <div class="copkm-sim-field">
                        <label>Km / lun&#259; (total)</label>
                        <div class="copkm-sim-slider">
                            <input type="range" id="sim-km-range" min="0" max="100000" step="100" value="0">
                            <input type="text" id="sim-km" class="copkm-input copkm-sim-num" value="0">
                            <span class="copkm-sim-unit">km</span>
                        </div>
                    </div>
                    <div class="copkm-sim-field">
                        <label>Curse / lun&#259; (total)</label>
                        <div class="copkm-sim-slider">
                            <input type="range" id="sim-trips-range" min="0" max="500" step="1" value="0">
                            <input type="text" id="sim-trips" class="copkm-input copkm-sim-num" value="0">
                            <span class="copkm-sim-unit">curse</span>
                        </div>
                    </div>
                    <div class="copkm-sim-field">
                        <label>Venit mediu / km</label>
                        <div class="copkm-sim-slider">
                            <input type="range" id="sim-rev-range" min="0" max="30" step="0.05" value="0">
                            <input type="text" id="sim-rev" class="copkm-input copkm-sim-num" value="0">
                            <span class="copkm-sim-unit">lei</span>
                        </div>
                    </div>
                    <div class="copkm-sim-field">
                        <label>Mix beneficiari</label>
                        <select class="copkm-input" id="sim-mix" disabled title="Mixul de beneficiari se p&#259;streaz&#259; — alocarea pe beneficiari urmeaz&#259; km-ii reali">
                            <option>P&#259;streaz&#259; mixul actual</option>
                        </select>
                    </div>
                </div>
                <div class="copkm-sim-result">
                    <div class="copkm-sim-label-head">Rezultat simulare</div>
                    <div class="copkm-be-row"><span>Km simula&#539;i:</span><b id="simr-km" class="copkm-blue">&#8212;</b></div>
                    <div class="copkm-be-row"><span>Curse simulate:</span><b id="simr-trips">&#8212;</b></div>
                    <div class="copkm-be-row"><span>Cost total:</span><b id="simr-cost">&#8212;</b></div>
                    <div class="copkm-be-row"><span>Venit total:</span><b id="simr-rev">&#8212;</b></div>
                    <div class="copkm-be-row"><span>Cost / km:</span><b id="simr-cost-km">&#8212;</b></div>
                    <div class="copkm-be-row"><span>Venit / km:</span><b id="simr-rev-km">&#8212;</b></div>
                    <div class="copkm-be-row"><span>Rezultat:</span><b id="simr-result">&#8212;</b></div>
                </div>
                <div class="copkm-sim-ring">
                    <svg viewBox="0 0 120 120" class="copkm-ring">
                        <circle cx="60" cy="60" r="50" class="copkm-ring-bg"></circle>
                        <circle cx="60" cy="60" r="50" class="copkm-ring-fg copkm-ring-green" id="sim-ring-fg" stroke-dasharray="0 314"></circle>
                    </svg>
                    <div class="copkm-ring-center">
                        <div class="copkm-ring-pct" id="sim-ring-pct">&#8212;</div>
                        <div class="copkm-ring-sub">Recuperare</div>
                    </div>
                    <button type="button" class="copkm-btn copkm-btn-primary copkm-btn-sm" id="btn-apply-sim">Aplic&#259; simularea</button>
                    <div class="copkm-sim-note">Simularea NU modific&#259; datele reale.</div>
                </div>
            </div>
        </div>

        <div class="copkm-card" id="sec-vehicles">
            <div class="copkm-card-head">
                <span class="copkm-card-title"><span class="copkm-num">5.</span> VIZUALIZARE PE VEHICULE</span>
            </div>
            <div class="copkm-seg copkm-seg-sm" id="veh-tabs">
                <button type="button" class="copkm-seg-btn active" data-vtab="vehicul">Dup&#259; vehicul</button>
                <button type="button" class="copkm-seg-btn" data-vtab="categorie">Dup&#259; categorie</button>
                <button type="button" class="copkm-seg-btn" data-vtab="sofer">Dup&#259; &#537;ofer</button>
            </div>
            <div class="copkm-table-wrap" id="veh-table-wrap"></div>
            <a href="#" class="copkm-link" id="veh-more">Vezi toate vehiculele <i class="bi bi-arrow-right"></i></a>
        </div>

        <div class="copkm-card" id="sec-drivers">
            <div class="copkm-card-head">
                <span class="copkm-card-title"><span class="copkm-num">6.</span> VIZUALIZARE PE &#536;OFERI</span>
            </div>
            <div class="copkm-table-wrap" id="drv-table-wrap"></div>
            <a href="#" class="copkm-link" id="drv-more">Vezi to&#539;i &#537;oferii <i class="bi bi-arrow-right"></i></a>
        </div>
    </div>

    <!-- ===================== BENEFICIARI ===================== -->
    <div class="copkm-card" id="sec-beneficiaries">
        <div class="copkm-card-head">
            <span class="copkm-card-title"><span class="copkm-num">7.</span> VIZUALIZARE PE BENEFICIARI</span>
        </div>
        <div class="copkm-table-wrap" id="ben-table-wrap"></div>
    </div>

    <!-- ===================== NOTĂ FINALĂ ===================== -->
    <div class="copkm-bottom-note">
        <div><i class="bi bi-exclamation-triangle-fill"></i>
            Costurile fixe se aloc&#259; conform regulilor setate &#238;n Configur&#259;ri (ex: dup&#259; num&#259;r vehicule, pondere km, capacitate etc.).</div>
        <button type="button" class="copkm-btn copkm-btn-outline copkm-btn-sm" data-copkm-open="methodology">
            <i class="bi bi-info-circle"></i> Despre metodologia de calcul</button>
    </div>
</div>

<!-- ===================== MODAL: DETALII / TRASABILITATE ===================== -->
<div class="copkm-modal-backdrop d-none" id="modal-details">
    <div class="copkm-modal">
        <div class="copkm-modal-head">
            <span id="details-title">Detalii costuri</span>
            <i class="bi bi-x-lg" role="button" data-copkm-close="modal-details"></i>
        </div>
        <div class="copkm-modal-body" id="details-body"></div>
    </div>
</div>

<!-- ===================== MODAL: ELEMENTE FINANCIARE (CONFIGURARE) ===================== -->
<div class="copkm-modal-backdrop d-none" id="modal-elements">
    <div class="copkm-modal copkm-modal-wide">
        <div class="copkm-modal-head">
            <span>Elemente financiare &#8212; detaliere &#537;i configurare</span>
            <i class="bi bi-x-lg" role="button" data-copkm-close="modal-elements"></i>
        </div>
        <div class="copkm-modal-body">
            <div class="copkm-elements-toolbar">
                <div class="copkm-note-strip"><i class="bi bi-info-circle"></i>
                    <span>Configurarea define&#537;te CUM particip&#259; o surs&#259; la cost/km. Valorile tranzac&#539;ionale vin din sursele lor (CardOil, mentenan&#539;&#259;, salarii etc.).</span></div>
                <?php if ($canConfigure): ?>
                <button type="button" class="copkm-btn copkm-btn-primary copkm-btn-sm" id="btn-add-element">
                    <i class="bi bi-plus-lg"></i> Adaug&#259; element financiar</button>
                <?php endif; ?>
            </div>
            <div id="elements-body"></div>
            <div class="copkm-settings-block">
                <div class="copkm-sim-label-head">Parametri de calcul</div>
                <div class="copkm-settings-grid" id="settings-grid"></div>
            </div>
        </div>
    </div>
</div>

<!-- ===================== MODAL: FORMULAR ELEMENT ===================== -->
<div class="copkm-modal-backdrop d-none" id="modal-element-form">
    <div class="copkm-modal">
        <div class="copkm-modal-head">
            <span id="element-form-title">Element financiar</span>
            <i class="bi bi-x-lg" role="button" data-copkm-close="modal-element-form"></i>
        </div>
        <div class="copkm-modal-body">
            <form id="element-form" class="copkm-el-form">
                <input type="hidden" name="id" value="0">
                <input type="hidden" name="cod" value="">
                <div class="copkm-el-grid">
                    <div class="copkm-filter"><label>Denumire *</label><input class="copkm-input" name="nume" required maxlength="150"></div>
                    <div class="copkm-filter"><label>Tip *</label>
                        <select class="copkm-input" name="tip"><option value="fix">Cost FIX</option><option value="variabil">Cost VARIABIL</option></select></div>
                    <div class="copkm-filter"><label>Surs&#259;</label>
                        <select class="copkm-input" name="sursa_referinta" id="element-form-source">
                            <option value="manual">Valoare configurat&#259; manual</option>
                            <option value="documente_vehicule">Config documente vehicule (per tip document)</option>
                        </select></div>
                    <div class="copkm-filter" id="element-form-filtru-wrap"><label>Filtru surs&#259; (ex. tip document)</label><input class="copkm-input" name="sursa_filtru" maxlength="120"></div>
                    <div class="copkm-filter"><label>Scop</label>
                        <select class="copkm-input" name="scop">
                            <option value="vehicle">Per vehicul</option>
                            <option value="company">Firm&#259; (partajat)</option>
                            <option value="driver">Per &#537;ofer</option>
                        </select></div>
                    <div class="copkm-filter"><label>Periodicitate</label>
                        <select class="copkm-input" name="periodicitate">
                            <option value="anual">Anual&#259;</option>
                            <option value="lunar">Lunar&#259;</option>
                            <option value="per_100000km">Per 100.000 km</option>
                            <option value="per_km">Per km</option>
                        </select></div>
                    <div class="copkm-filter"><label>Valoare</label><input class="copkm-input" name="valoare_config" placeholder="ex: 1100"></div>
                    <div class="copkm-filter"><label>Moned&#259;</label>
                        <select class="copkm-input" name="valoare_moneda"><option value="RON">RON</option><option value="EUR">EUR</option></select></div>
                    <div class="copkm-filter"><label>Amortizare (ani, op&#539;ional)</label><input class="copkm-input" name="amortizare_ani" placeholder="ex: 6"></div>
                    <div class="copkm-filter"><label>Tipuri vehicul (CSV, gol = toate)</label><input class="copkm-input" name="tipuri_vehicul" placeholder="cap_tractor,camion"></div>
                    <div class="copkm-filter"><label>Regim TVA surs&#259;</label>
                        <select class="copkm-input" name="regim_tva">
                            <option value="net">Net (folosit ca atare)</option>
                            <option value="brut">Brut (se scoate TVA la normalizare)</option>
                            <option value="necunoscut_net">Necunoscut &#8212; tratat ca net</option>
                        </select></div>
                    <div class="copkm-filter"><label>Activ</label>
                        <select class="copkm-input" name="activ"><option value="1">Da</option><option value="0">Nu</option></select></div>
                </div>
                <div class="copkm-filter"><label>Observa&#539;ii</label><input class="copkm-input" name="observatii" maxlength="500"></div>
                <div class="copkm-el-form-actions">
                    <button type="button" class="copkm-btn copkm-btn-light" data-copkm-close="modal-element-form">Renun&#539;&#259;</button>
                    <button type="submit" class="copkm-btn copkm-btn-primary">Salveaz&#259; elementul</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===================== MODAL: METODOLOGIE ===================== -->
<div class="copkm-modal-backdrop d-none" id="modal-methodology">
    <div class="copkm-modal">
        <div class="copkm-modal-head">
            <span>Despre metodologia de calcul</span>
            <i class="bi bi-x-lg" role="button" data-copkm-close="modal-methodology"></i>
        </div>
        <div class="copkm-modal-body copkm-method">
            <p><b>Principiu:</b> fiecare element financiar este clasificat FIX sau VARIABIL, normalizat la perioada selectat&#259; &#537;i &#238;mp&#259;r&#539;it la km-ii ACELUIA&#536;I scop: <code>cost financiar &#247; km generați de acela&#537;i scop = lei/km</code>. Niciun cost nu se &#238;mparte la km-ii altui scop.</p>
            <ul>
                <li><b>Km (numitor):</b> km reali din curse (<code>km_totali</code>, fallback <code>km_cursa</code> &#8212; expresia „km efectivi” din Dashboard Analitic). Comutabil pe km factura&#539;i din filtrul „Tip activitate”.</li>
                <li><b>Venit:</b> <code>total_facturare</code> din cursele dispecer (RON, f&#259;r&#259; TVA).</li>
                <li><b>Carburant/AdBlue:</b> aliment&#259;rile CardOil (source_type=api), nete de TVA cu cota real&#259; per r&#226;nd (21% pe datele live) &#8212; de-TVA-izarea se aplic&#259; O SINGUR&#258; dat&#259;.</li>
                <li><b>Documente vehicule</b> (RCA, CASCO, ITP, Rovinieta, IPROCHIM, Tahograf, Metrologie): <code>cost &#215; 365 / validity_days / 12</code>, cu precedența override-per-vehicul.</li>
                <li><b>Salarii &#537;oferi:</b> <code>salariu lun&#259; (salary_history) &#215; multiplicator angajator (config)</code>, alocat vehiculului asociat &#537;oferului.</li>
                <li><b>Management/Office:</b> cheltuieli birou + administrative + salarii birou (automat), alocate pe vehiculele active (sau dup&#259; km &#8212; configurabil).</li>
                <li><b>Ansamblu CT+SR:</b> costurile semiremorcii cuplate se adun&#259; la tractor; km-ul r&#259;m&#226;ne al tractorului (f&#259;r&#259; dubl&#259; num&#259;rare).</li>
                <li><b>Break-even:</b> <code>km_BE = costuri fixe &#247; (venit/km &#8722; variabil/km)</code>; la marj&#259; negativ&#259; break-even-ul este raportat ca imposibil, nu inventat.</li>
                <li><b>LIPS&#258; &#8800; 0:</b> elementele f&#259;r&#259; surs&#259; sau f&#259;r&#259; valoare sunt raportate explicit ca LIPS&#258; &#537;i excluse din total &#8212; totalul afi&#537;at este atât de complet c&#226;t sunt datele, iar bannerul de calitate spune exact ce lipse&#537;te.</li>
                <li><b>Simulare:</b> costurile fixe r&#259;m&#226;n fixe (se &#238;mpart la mai mul&#539;i km), variabilele urmeaz&#259; rata lei/km. Nimic nu se scrie &#238;n date.</li>
            </ul>
        </div>
    </div>
</div>

<style>
.copkm { --blue:#2563eb; --blue-d:#1e40af; --bg:#f4f6fb; --card:#fff; --line:#e6eaf2; --text:#0f172a; --muted:#64748b;
         --green:#16a34a; --green-bg:#ecf9f1; --orange:#ea580c; --orange-bg:#fff4e8; --red:#dc2626; --red-bg:#fdefef;
         --blue-bg:#eef3fe; color:var(--text); font-size:.875rem; }
.copkm-pagehead { display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; flex-wrap:wrap; margin-bottom:1rem; }
.copkm-title { font-size:1.45rem; font-weight:700; margin:0; display:flex; align-items:center; gap:.5rem; }
.copkm-title-info { color:var(--muted); font-size:1rem; cursor:pointer; }
.copkm-subtitle { color:var(--muted); margin:.25rem 0 0; }
.copkm-pagehead-right { display:flex; align-items:center; gap:.6rem; flex-wrap:wrap; }
.copkm-updated { color:var(--muted); font-size:.8rem; display:flex; align-items:center; gap:.4rem; }
.copkm-updated i { cursor:pointer; }
.copkm-btn { display:inline-flex; align-items:center; gap:.4rem; border-radius:.55rem; padding:.45rem .85rem; font-size:.82rem; font-weight:600;
             border:1px solid var(--line); background:#fff; color:var(--text); cursor:pointer; text-decoration:none; transition:background .15s; }
.copkm-btn:hover { background:#f1f5f9; color:var(--text); }
.copkm-btn-primary { background:var(--blue); border-color:var(--blue); color:#fff; }
.copkm-btn-primary:hover { background:var(--blue-d); color:#fff; }
.copkm-btn-light { background:#f1f5f9; }
.copkm-btn-sm { padding:.3rem .65rem; font-size:.78rem; }
.copkm-card { background:var(--card); border:1px solid var(--line); border-radius:.9rem; padding:1rem 1.1rem; box-shadow:0 1px 3px rgba(15,23,42,.05); margin-bottom:1rem; }
.copkm-card-head { display:flex; align-items:baseline; gap:.5rem; margin-bottom:.75rem; flex-wrap:wrap; }
.copkm-card-title { font-weight:700; font-size:.82rem; letter-spacing:.04em; color:#1e293b; }
.copkm-card-sub { color:var(--muted); font-weight:500; font-size:.78rem; }
.copkm-num { color:var(--blue); }
/* filtre */
.copkm-filter-row { display:flex; gap:1rem; flex-wrap:wrap; align-items:flex-end; }
.copkm-filter label { display:block; font-size:.72rem; color:var(--muted); font-weight:600; margin-bottom:.3rem; }
.copkm-filter-grow { flex:1 1 auto; min-width:320px; }
.copkm-input { border:1px solid var(--line); border-radius:.55rem; padding:.42rem .6rem; font-size:.82rem; background:#fff; color:var(--text); width:100%; min-width:130px; }
.copkm-seg { display:inline-flex; background:#eef1f7; border-radius:.6rem; padding:.2rem; gap:.2rem; flex-wrap:wrap; }
.copkm-seg-btn { border:0; background:transparent; border-radius:.45rem; padding:.4rem .75rem; font-size:.78rem; font-weight:600; color:#475569; cursor:pointer; display:inline-flex; gap:.35rem; align-items:center; }
.copkm-seg-btn.active { background:var(--blue); color:#fff; box-shadow:0 1px 2px rgba(37,99,235,.4); }
.copkm-seg-sm { margin-bottom:.6rem; }
.copkm-seg-sm .copkm-seg-btn { padding:.28rem .6rem; font-size:.74rem; }
.copkm-eur-wrap { position:relative; display:flex; align-items:center; }
.copkm-eur-sign { position:absolute; left:.55rem; color:var(--muted); }
.copkm-eur-input { padding-left:1.5rem; padding-right:1.7rem; max-width:120px; }
.copkm-eur-edit { position:absolute; right:.55rem; color:var(--blue); font-size:.75rem; }
.copkm-filter-actions { display:flex; justify-content:space-between; align-items:center; gap:1rem; margin-top:.85rem; flex-wrap:wrap; }
.copkm-note-strip { background:var(--blue-bg); border-radius:.55rem; color:#3b5bd6; padding:.5rem .8rem; display:flex; gap:.5rem; align-items:center; font-size:.78rem; flex:1 1 380px; }
.copkm-filter-buttons { display:flex; gap:.6rem; }
.copkm-quality { background:#fff8e6; border:1px solid #f3dfa8; color:#8a6d1a; border-radius:.7rem; padding:.6rem .9rem; font-size:.78rem; margin-bottom:1rem; }
.copkm-quality ul { margin:.3rem 0 0; padding-left:1.2rem; }
/* rezumat */
.copkm-grid-2 { display:grid; grid-template-columns:minmax(0,53fr) minmax(0,47fr); gap:1rem; }
.copkm-kpis { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:.7rem; }
.copkm-kpi { border:1px solid var(--line); border-radius:.75rem; padding:.7rem .8rem; background:#fff; }
.copkm-kpi-blue { background:var(--blue-bg); border-color:#d8e3fb; }
.copkm-kpi-green { background:var(--green-bg); border-color:#d3eedd; }
.copkm-kpi-orange { background:var(--orange-bg); border-color:#f7e3cd; }
.copkm-kpi-red { background:var(--red-bg); border-color:#f6d6d6; }
.copkm-kpi-head { display:flex; gap:.4rem; align-items:center; color:var(--muted); font-size:.72rem; font-weight:600; margin-bottom:.35rem; }
.copkm-kpi-ico { width:1.5rem; height:1.5rem; display:inline-flex; align-items:center; justify-content:center; border-radius:.45rem; background:rgba(37,99,235,.12); color:var(--blue); font-size:.8rem; }
.copkm-kpi-green .copkm-kpi-ico { background:rgba(22,163,74,.14); color:var(--green); }
.copkm-kpi-orange .copkm-kpi-ico { background:rgba(234,88,12,.13); color:var(--orange); }
.copkm-kpi-red .copkm-kpi-ico { background:rgba(220,38,38,.12); color:var(--red); }
.copkm-kpi-value { font-size:1.25rem; font-weight:800; line-height:1.2; }
.copkm-kpi-red .copkm-kpi-value { color:var(--red); }
.copkm-kpi-foot { color:var(--muted); font-size:.72rem; margin-top:.3rem; }
.copkm-badge { display:inline-flex; align-items:center; gap:.25rem; font-size:.68rem; font-weight:700; border-radius:1rem; padding:.1rem .5rem; }
.copkm-badge-red { background:var(--red-bg); color:var(--red); }
.copkm-badge-green { background:var(--green-bg); color:var(--green); }
.copkm-badge-gray { background:#eef1f7; color:var(--muted); }
.copkm-badge-orange { background:var(--orange-bg); color:var(--orange); }
.copkm-badge-blue { background:var(--blue-bg); color:var(--blue); }
/* break-even */
.copkm-be-grid { display:grid; grid-template-columns:minmax(0,1.1fr) minmax(0,1.2fr) minmax(0,.9fr); gap:1rem; }
.copkm-be-row { display:flex; justify-content:space-between; gap:.75rem; padding:.22rem 0; font-size:.8rem; color:var(--muted); }
.copkm-be-row b { color:var(--text); font-weight:700; white-space:nowrap; }
.copkm-be-box { border:1px solid #d8e3fb; background:var(--blue-bg); border-radius:.7rem; padding:.6rem .8rem; }
.copkm-be-box-title { font-weight:700; font-size:.78rem; color:var(--blue-d); margin-bottom:.35rem; }
.copkm-be-reason { font-size:.74rem; color:var(--red); margin-top:.4rem; }
.copkm-neg { color:var(--red) !important; }
.copkm-pos { color:var(--green) !important; }
.copkm-blue { color:var(--blue) !important; }
.copkm-be-ring, .copkm-sim-ring { display:flex; flex-direction:column; align-items:center; justify-content:center; position:relative; gap:.4rem; }
.copkm-ring-label { font-size:.74rem; color:var(--muted); font-weight:600; }
.copkm-ring { width:120px; height:120px; transform:rotate(-90deg); }
.copkm-ring-bg { fill:none; stroke:#edf0f6; stroke-width:11; }
.copkm-ring-fg { fill:none; stroke:var(--red); stroke-width:11; stroke-linecap:round; transition:stroke-dasharray .5s; }
.copkm-ring-green { stroke:var(--green); }
.copkm-ring-center { position:absolute; text-align:center; top:50%; left:50%; transform:translate(-50%,-58%); }
.copkm-be-ring .copkm-ring-center { transform:translate(-50%,-42%); }
.copkm-ring-pct { font-size:1.15rem; font-weight:800; }
.copkm-ring-sub { font-size:.66rem; color:var(--muted); }
/* structura */
.copkm-grid-structure { display:grid; grid-template-columns:minmax(0,54fr) minmax(0,46fr); gap:1rem; align-items:start; }
.copkm-col-right { display:flex; flex-direction:column; }
.copkm-table-wrap { overflow-x:auto; }
.copkm-table { width:100%; border-collapse:collapse; font-size:.78rem; }
.copkm-table th { color:var(--muted); font-weight:600; font-size:.7rem; text-transform:none; padding:.45rem .55rem; border-bottom:1px solid var(--line); text-align:right; white-space:nowrap; }
.copkm-table th:first-child, .copkm-table td:first-child { text-align:left; }
.copkm-table td { padding:.5rem .55rem; border-bottom:1px solid #f1f4f9; text-align:right; white-space:nowrap; }
.copkm-table tbody tr:hover { background:#f8fafd; }
.copkm-table .copkm-group-head th { border-bottom:0; padding-bottom:0; text-align:center; }
.copkm-table tfoot td { font-weight:800; border-top:2px solid var(--line); background:#f8fafc; }
.copkm-cat-cell { display:flex; align-items:center; gap:.5rem; font-weight:600; }
.copkm-cat-ico { width:1.6rem; height:1.6rem; border-radius:.45rem; display:inline-flex; align-items:center; justify-content:center; background:var(--blue-bg); color:var(--blue); font-size:.85rem; flex:0 0 auto; }
.copkm-table-note { font-size:.72rem; color:var(--muted); margin-top:.5rem; }
.copkm-link { display:inline-flex; gap:.35rem; align-items:center; color:var(--blue); font-size:.78rem; font-weight:600; text-decoration:none; margin-top:.6rem; }
.copkm-link:hover { color:var(--blue-d); }
/* compozitie */
.copkm-comp-grid { display:grid; grid-template-columns:170px minmax(0,1fr); gap:1rem; align-items:center; }
.copkm-donut-wrap { position:relative; width:170px; height:170px; }
.copkm-donut { width:170px; height:170px; }
.copkm-donut-center { position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; pointer-events:none; }
.copkm-donut-value { font-size:1.3rem; font-weight:800; }
.copkm-donut-sub { font-size:.68rem; color:var(--muted); }
.copkm-comp-legend { display:flex; flex-direction:column; gap:.3rem; max-height:230px; overflow-y:auto; }
.copkm-leg-row { display:flex; align-items:center; gap:.5rem; font-size:.76rem; }
.copkm-leg-dot { width:.6rem; height:.6rem; border-radius:50%; flex:0 0 auto; }
.copkm-leg-label { flex:1 1 auto; color:var(--text); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.copkm-leg-val { color:var(--muted); white-space:nowrap; }
/* bare */
.copkm-chart-legend { display:flex; gap:1rem; font-size:.72rem; color:var(--muted); margin-bottom:.4rem; flex-wrap:wrap; }
.copkm-chart-legend i { display:inline-block; width:.7rem; height:.7rem; border-radius:.2rem; margin-right:.3rem; }
.copkm-bars { width:100%; height:auto; }
.copkm-chart-foot { display:flex; gap:.6rem; flex-wrap:wrap; margin-top:.5rem; }
.copkm-chart-foot .copkm-chip { border:1px solid var(--line); border-radius:.55rem; padding:.35rem .6rem; font-size:.72rem; color:var(--muted); background:#f8fafc; }
.copkm-chart-foot .copkm-chip b { color:var(--text); }
/* simulare */
.copkm-grid-bottom { display:grid; grid-template-columns:minmax(0,38fr) minmax(0,33fr) minmax(0,29fr); gap:1rem; align-items:start; }
.copkm-sim-grid { display:grid; grid-template-columns:minmax(0,1.2fr) minmax(0,1fr) minmax(0,.8fr); gap:1rem; }
.copkm-sim-label-head { font-weight:700; font-size:.76rem; margin-bottom:.5rem; color:#1e293b; }
.copkm-sim-field { margin-bottom:.7rem; }
.copkm-sim-field label { font-size:.72rem; color:var(--muted); font-weight:600; display:block; margin-bottom:.25rem; }
.copkm-sim-slider { display:flex; align-items:center; gap:.5rem; }
.copkm-sim-slider input[type=range] { flex:1 1 auto; accent-color:var(--blue); }
.copkm-sim-num { max-width:90px; text-align:right; }
.copkm-sim-unit { font-size:.72rem; color:var(--muted); }
.copkm-sim-result { border-left:1px solid var(--line); padding-left:1rem; }
.copkm-sim-note { font-size:.66rem; color:var(--muted); text-align:center; }
/* beneficiari + note */
.copkm-bottom-note { background:#fff8e6; border:1px solid #f3dfa8; border-radius:.8rem; padding:.7rem 1rem; display:flex; justify-content:space-between; align-items:center; gap:1rem; font-size:.78rem; color:#8a6d1a; flex-wrap:wrap; }
.copkm-bottom-note i { color:#d9a514; margin-right:.35rem; }
/* modale */
.copkm-modal-backdrop { position:fixed; inset:0; background:rgba(15,23,42,.45); z-index:1060; display:flex; align-items:flex-start; justify-content:center; padding:3rem 1rem; overflow-y:auto; }
.copkm-modal { background:#fff; border-radius:.9rem; width:min(760px,100%); box-shadow:0 20px 60px rgba(15,23,42,.25); }
.copkm-modal-wide { width:min(1040px,100%); }
.copkm-modal-head { display:flex; justify-content:space-between; align-items:center; padding: .9rem 1.1rem; border-bottom:1px solid var(--line); font-weight:700; }
.copkm-modal-head i { cursor:pointer; color:var(--muted); }
.copkm-modal-body { padding:1rem 1.1rem; font-size:.82rem; }
.copkm-method ul { padding-left:1.1rem; }
.copkm-method li { margin-bottom:.35rem; }
/* elemente */
.copkm-elements-toolbar { display:flex; justify-content:space-between; gap:1rem; align-items:center; margin-bottom:.8rem; flex-wrap:wrap; }
.copkm-el-group-title { font-weight:800; font-size:.78rem; margin:.8rem 0 .4rem; color:#1e293b; letter-spacing:.03em; }
.copkm-el-form .copkm-el-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:.7rem; margin-bottom:.7rem; }
.copkm-el-form-actions { display:flex; justify-content:flex-end; gap:.6rem; margin-top:.9rem; }
.copkm-settings-block { border-top:1px solid var(--line); margin-top:1rem; padding-top:.8rem; }
.copkm-settings-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:.7rem; }
.copkm-sort { cursor:pointer; user-select:none; }
.copkm-sort:hover { color:var(--blue); }
.copkm-details-sub { font-size:.7rem; color:var(--muted); }
/* responsive */
@media (max-width: 1500px) {
    .copkm-grid-bottom { grid-template-columns:minmax(0,1fr) minmax(0,1fr); }
    .copkm-grid-bottom > #sec-sim { grid-column:1 / -1; }
}
@media (max-width: 1250px) {
    .copkm-grid-2, .copkm-grid-structure { grid-template-columns:minmax(0,1fr); }
    .copkm-kpis { grid-template-columns:repeat(2,minmax(0,1fr)); }
}
@media (max-width: 860px) {
    .copkm-grid-bottom, .copkm-sim-grid, .copkm-be-grid, .copkm-comp-grid { grid-template-columns:minmax(0,1fr); }
    .copkm-kpis { grid-template-columns:minmax(0,1fr); }
    .copkm-sim-result { border-left:0; padding-left:0; }
    .copkm-el-form .copkm-el-grid, .copkm-settings-grid { grid-template-columns:minmax(0,1fr); }
}
</style>

<script>
(function () {
    'use strict';

    var DATA_URL = <?= json_encode($dataUrl, JSON_UNESCAPED_SLASHES) ?>;
    var DETAILS_URL = <?= json_encode($detailsUrl, JSON_UNESCAPED_SLASHES) ?>;
    var SIMULATE_URL = <?= json_encode($simulateUrl, JSON_UNESCAPED_SLASHES) ?>;
    var EXPORT_URL = <?= json_encode($exportUrl, JSON_UNESCAPED_SLASHES) ?>;
    var EL_SAVE_URL = <?= json_encode($elementSaveUrl, JSON_UNESCAPED_SLASHES) ?>;
    var EL_TOGGLE_URL = <?= json_encode($elementToggleUrl, JSON_UNESCAPED_SLASHES) ?>;
    var EL_DELETE_URL = <?= json_encode($elementDeleteUrl, JSON_UNESCAPED_SLASHES) ?>;
    var SETTINGS_URL = <?= json_encode($settingsSaveUrl, JSON_UNESCAPED_SLASHES) ?>;
    var CSRF = <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES) ?>;
    var CAN_CONFIGURE = <?= $canConfigure ? 'true' : 'false' ?>;

    var nf0 = new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 0 });
    var nf2 = new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    var nf1 = new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 1, maximumFractionDigits: 1 });

    var PALETTE = ['#2563eb', '#16a34a', '#f59e0b', '#8b5cf6', '#ec4899', '#06b6d4', '#ef4444', '#64748b', '#84cc16', '#f97316', '#0ea5e9', '#a855f7'];
    var CAT_ICONS = { ct_sr: 'bi-truck-front-fill', c7: 'bi-truck', c10: 'bi-truck-flatbed', c13: 'bi-box-seam', camion_necunoscut: 'bi-question-circle', semi_necuplata: 'bi-link-45deg' };

    var state = {
        data: null,
        vedere: 'overall',
        vehTab: 'vehicul',
        showAllVehicles: false,
        showAllDrivers: false,
        simActive: false,
        simScenario: null,
        simTimer: null,
        vehSort: { key: 'km', dir: -1 },
        drvSort: { key: 'km', dir: -1 }
    };

    function $(id) { return document.getElementById(id); }

    // ---------- unități de afișare (lei vs EUR) ----------
    function eurRate() {
        var v = parseFloat(($('f-eur').value || '').replace(',', '.'));
        return isFinite(v) && v > 0 ? v : 0;
    }
    function unitMode() { return $('f-unit').value; }
    function perKm(value) {
        if (value === null || value === undefined) { return null; }
        if (unitMode() === 'eur') {
            var r = eurRate();
            return r > 0 ? value / r : null;
        }
        return value;
    }
    function unitLabel() { return unitMode() === 'eur' ? '€/km' : 'lei/km'; }
    function fmtPerKm(value) {
        var v = perKm(value);
        return v === null ? 'n/a' : nf2.format(v) + ' ' + unitLabel();
    }
    function fmtPerKmShort(value) {
        var v = perKm(value);
        return v === null ? 'n/a' : nf2.format(v);
    }
    function fmtLei(value) {
        if (value === null || value === undefined) { return 'n/a'; }
        return nf0.format(Math.round(value)) + ' lei';
    }
    function fmtLei2(value) {
        if (value === null || value === undefined) { return 'n/a'; }
        return nf2.format(value) + ' lei';
    }
    function esc(text) {
        return String(text === null || text === undefined ? '' : text).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // ---------- filtre / fetch ----------
    function filterQuery() {
        var parts = [];
        parts.push('period=' + encodeURIComponent($('f-period').value));
        var ben = $('f-beneficiar').value;
        if (ben) { parts.push('beneficiar_id=' + encodeURIComponent(ben)); }
        var cat = $('f-categorie').value;
        if (cat) { parts.push('categorie=' + encodeURIComponent(cat)); }
        parts.push('km_source=' + encodeURIComponent($('f-km-source').value));
        return parts.join('&');
    }

    function load() {
        $('copkm-error').classList.add('d-none');
        fetch(DATA_URL + '&' + filterQuery(), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) { throw new Error(data.message || 'Eroare'); }
                state.data = data;
                state.simActive = false;
                state.simScenario = null;
                renderAll();
                initSimDefaults();
                $('copkm-updated-label').textContent = 'Actualizat: ' + new Date().toLocaleString('ro-RO', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
            })
            .catch(function (err) {
                var el = $('copkm-error');
                el.textContent = 'Nu s-a putut calcula modelul financiar: ' + err.message;
                el.classList.remove('d-none');
            });
    }

    // ---------- randare ----------
    function scopeLabel() {
        var parts = [];
        var ben = $('f-beneficiar');
        if (ben.value) { parts.push(ben.options[ben.selectedIndex].text); }
        var cat = $('f-categorie');
        if (cat.value) { parts.push(cat.options[cat.selectedIndex].text); }
        return parts.length ? '— ' + parts.join(' · ') : '— Total flotă';
    }

    function renderAll() {
        var d = state.data;
        if (!d) { return; }
        renderCategoryFilter(d);
        renderQuality(d);
        renderSummary(d);
        renderBreakEven(d.breakeven);
        renderStructure(d);
        renderComposition(d);
        renderAvbChart(d);
        renderVehicles(d);
        renderDrivers(d);
        renderBeneficiaries(d);
        var sl = scopeLabel();
        $('rg-scope-label').textContent = sl;
        $('be-scope-label').textContent = sl;
        $('comp-scope-label').textContent = sl;
    }

    function renderCategoryFilter(d) {
        var sel = $('f-categorie');
        var current = sel.value;
        var seen = {};
        // păstrează prima opțiune, reconstruiește restul din categoriile reale
        while (sel.options.length > 1) { sel.remove(1); }
        (d.categories || []).forEach(function (c) {
            if (seen[c.code]) { return; }
            seen[c.code] = true;
            var opt = document.createElement('option');
            opt.value = c.code;
            opt.textContent = c.label;
            sel.appendChild(opt);
        });
        sel.value = current && seen[current] ? current : (current === '' ? '' : '');
    }

    function renderQuality(d) {
        var box = $('copkm-quality');
        var missing = (d.summary && d.summary.missing) || [];
        if (d.summary.quality === 'complet' || missing.length === 0) {
            box.classList.add('d-none');
            box.innerHTML = '';
            return;
        }
        var badge = d.summary.quality === 'lipsa' ? 'LIPSĂ DATE' : 'PARȚIAL';
        var html = '<b><i class="bi bi-exclamation-triangle"></i> Calitate date: ' + badge + '.</b> ' +
            'Totalurile includ doar sursele populate — elementele de mai jos NU sunt tratate ca 0, ci raportate ca lipsă (' + missing.length + '):';
        html += '<ul>';
        missing.slice(0, 6).forEach(function (m) {
            html += '<li><b>' + esc(m.nume) + '</b> — ' + esc(m.nota) + '</li>';
        });
        if (missing.length > 6) {
            html += '<li>… și încă ' + (missing.length - 6) + ' elemente — vezi "Vezi detalierea completă costuri".</li>';
        }
        html += '</ul>';
        box.innerHTML = html;
        box.classList.remove('d-none');
    }

    function renderSummary(d) {
        var s = d.summary;
        $('kpi-total-cost').textContent = fmtLei(s.total);
        $('kpi-cost-split').innerHTML = 'Fixe: <b>' + fmtLei(s.fixed_total) + '</b> (' + nf1.format(s.fixed_pct) + '%) · Variabile: <b>' + fmtLei(s.variable_total) + '</b> (' + nf1.format(s.variable_pct) + '%)';
        $('kpi-activity').textContent = nf0.format(s.km) + ' km';
        $('kpi-activity-sub').innerHTML = '<b>' + nf0.format(s.trips) + ' curse</b> · Venit total: <b>' + fmtLei(s.revenue) + '</b>' + (s.revenue_per_km !== null ? ' · Venit mediu: <b>' + fmtPerKm(s.revenue_per_km) + '</b>' : '');
        $('kpi-cost-km').textContent = s.km > 0 ? fmtPerKm(s.cost_per_km) : 'indisponibil';
        $('kpi-cost-km-split').innerHTML = s.km > 0
            ? 'Fix: <b>' + fmtPerKmShort(s.fixed_per_km) + '</b> · Var: <b>' + fmtPerKmShort(s.variable_per_km) + '</b>'
            : 'Cost/km indisponibil — 0 km în perioada selectată';
        $('kpi-result').textContent = fmtLei(s.result_total);
        $('kpi-result').style.color = s.result_total >= 0 ? 'var(--green)' : 'var(--red)';
        $('kpi-result-km').textContent = s.km > 0 ? fmtPerKm(s.result_per_km) : '—';
        var badge = $('kpi-result-badge');
        if (s.km <= 0) { badge.className = 'copkm-badge copkm-badge-gray'; badge.textContent = 'Fără activitate'; }
        else if (s.result_total >= 0) { badge.className = 'copkm-badge copkm-badge-green'; badge.innerHTML = '<i class="bi bi-check-circle-fill"></i> Profit'; }
        else { badge.className = 'copkm-badge copkm-badge-red'; badge.innerHTML = '<i class="bi bi-record-fill"></i> Pierdere'; }
    }

    function renderBreakEven(be) {
        $('be-fixed').textContent = fmtLei(be.fixed_total);
        $('be-variable').textContent = fmtLei(be.variable_total);
        $('be-total').textContent = fmtLei(be.cost_total);
        $('be-km').textContent = nf0.format(be.km_current) + ' km';
        $('be-cost-km').textContent = be.cost_per_km !== null ? fmtPerKm(be.cost_per_km) : 'n/a';
        $('be-rev-km').textContent = be.revenue_per_km !== null ? fmtPerKm(be.revenue_per_km) : 'n/a';
        $('be-result').textContent = fmtLei(be.result);
        $('be-result').className = be.result >= 0 ? 'copkm-pos' : 'copkm-neg';
        var reason = $('be-reason');
        if (be.reachable) {
            reason.classList.add('d-none');
            $('be-km-needed').textContent = nf0.format(Math.round(be.break_even_km)) + ' km';
            $('be-km-missing').textContent = (be.km_missing > 0 ? '+' : '') + nf0.format(Math.round(be.km_missing)) + ' km';
            $('be-trips-needed').textContent = be.trips_needed !== null ? '~' + nf0.format(be.trips_needed) + ' curse' : 'n/a';
            $('be-rev-needed').textContent = fmtLei(be.revenue_needed);
            $('be-rev-additional').textContent = '+' + fmtLei(be.revenue_additional);
            $('be-cost-km-be').textContent = fmtPerKm(be.cost_per_km_at_breakeven);
        } else {
            ['be-km-needed', 'be-km-missing', 'be-trips-needed', 'be-rev-needed', 'be-rev-additional', 'be-cost-km-be'].forEach(function (id) { $(id).textContent = '—'; });
            reason.textContent = be.reason;
            reason.classList.remove('d-none');
        }
        var pct = be.recovery_pct !== null ? Math.max(0, Math.min(be.recovery_pct, 100)) : 0;
        var circ = 2 * Math.PI * 50;
        $('be-ring-fg').setAttribute('stroke-dasharray', (pct / 100 * circ).toFixed(1) + ' ' + circ.toFixed(1));
        $('be-ring-fg').style.stroke = pct >= 100 ? 'var(--green)' : (pct >= 60 ? 'var(--orange)' : 'var(--red)');
        $('be-ring-pct').textContent = be.recovery_pct !== null ? nf1.format(be.recovery_pct) + '%' : 'n/a';
        $('be-ring-sub').textContent = fmtLei(be.revenue) + ' / ' + fmtLei(be.cost_total);
    }

    // ---------- secțiunea 1: tabelul principal (comută după Vedere) ----------
    function renderStructure(d) {
        var vedere = state.vedere;
        var titles = {
            overall: 'STRUCTURA COSTURILOR PE CATEGORIE',
            categorie: 'STRUCTURA COSTURILOR PE CATEGORIE',
            beneficiar: 'STRUCTURA COSTURILOR PE BENEFICIAR',
            vehicul: 'STRUCTURA COSTURILOR PE VEHICUL',
            sofer: 'STRUCTURA COSTURILOR PE ȘOFER'
        };
        $('structure-title').textContent = titles[vedere] || titles.overall;
        var wrap = $('structure-table-wrap');
        if (vedere === 'beneficiar') { wrap.innerHTML = beneficiaryTableHtml(d, true); return; }
        if (vedere === 'vehicul') { wrap.innerHTML = vehicleTableHtml(d, true); return; }
        if (vedere === 'sofer') { wrap.innerHTML = driverTableHtml(d, true); return; }
        wrap.innerHTML = categoryTableHtml(d);
        wrap.querySelectorAll('[data-cat-details]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                openDetails('category', btn.getAttribute('data-cat-details'), btn.getAttribute('data-cat-label'));
            });
        });
    }

    function categoryTableHtml(d) {
        var s = d.summary;
        var html = '<table class="copkm-table"><thead>' +
            '<tr class="copkm-group-head"><th></th><th colspan="2">Costuri fixe (lei)</th><th colspan="2">Costuri variabile (lei)</th><th colspan="2">Cost total (lei)</th><th></th><th></th><th></th></tr>' +
            '<tr><th>Categorie transport</th><th>Total</th><th>' + unitLabel() + '</th><th>Total</th><th>' + unitLabel() + '</th><th>Total</th><th>' + unitLabel() + '</th><th>Km ' + (d.km_source === 'curse_facturati' ? 'facturați' : 'reali') + '</th><th>Pondere cost total</th><th>Acțiuni</th></tr>' +
            '</thead><tbody>';
        (d.categories || []).forEach(function (c) {
            var ico = CAT_ICONS[c.code] || 'bi-truck';
            html += '<tr>' +
                '<td><span class="copkm-cat-cell"><span class="copkm-cat-ico"><i class="bi ' + ico + '"></i></span>' + esc(c.label) + ' <span class="copkm-details-sub">(' + c.vehicles + ')</span></span></td>' +
                '<td>' + nf0.format(Math.round(c.fixed_total)) + '</td><td>' + fmtPerKmShort(c.fixed_per_km) + '</td>' +
                '<td>' + nf0.format(Math.round(c.variable_total)) + '</td><td>' + fmtPerKmShort(c.variable_per_km) + '</td>' +
                '<td><b>' + nf0.format(Math.round(c.total)) + '</b></td><td><b>' + fmtPerKmShort(c.total_per_km) + '</b></td>' +
                '<td>' + nf0.format(c.km) + '</td>' +
                '<td>' + nf1.format(c.share_pct) + '%</td>' +
                '<td><a href="#" class="copkm-link" style="margin:0" data-cat-details="' + esc(c.code) + '" data-cat-label="' + esc(c.label) + '">Detalii <i class="bi bi-chevron-right"></i></a></td>' +
                '</tr>';
        });
        html += '</tbody><tfoot><tr>' +
            '<td>TOTAL FLOTĂ</td>' +
            '<td>' + nf0.format(Math.round(s.fixed_total)) + '</td><td>' + fmtPerKmShort(s.fixed_per_km) + '</td>' +
            '<td>' + nf0.format(Math.round(s.variable_total)) + '</td><td>' + fmtPerKmShort(s.variable_per_km) + '</td>' +
            '<td>' + nf0.format(Math.round(s.total)) + '</td><td>' + fmtPerKmShort(s.cost_per_km) + '</td>' +
            '<td>' + nf0.format(s.km) + '</td><td>100%</td><td>–</td>' +
            '</tr></tfoot></table>';
        return html;
    }

    // ---------- compoziție (donut) ----------
    function renderComposition(d) {
        var comp = d.composition || [];
        var svg = $('comp-donut');
        var legend = $('comp-legend');
        var s = d.summary;
        $('comp-center').textContent = s.km > 0 && s.cost_per_km !== null ? fmtPerKmShort(s.cost_per_km) : 'n/a';
        $('comp-center-sub').textContent = s.km > 0 ? unitLabel() : '0 km în perioadă';
        svg.innerHTML = '';
        legend.innerHTML = '';
        if (!comp.length) {
            legend.innerHTML = '<div class="copkm-details-sub">Niciun element financiar cu valoare în perioada / scopul selectat.</div>';
            return;
        }
        var cx = 90, cy = 90, r = 70, width = 26;
        var total = comp.reduce(function (acc, e) { return acc + e.total; }, 0);
        var start = -Math.PI / 2;
        comp.forEach(function (e, i) {
            var frac = total > 0 ? e.total / total : 0;
            var ang = frac * Math.PI * 2;
            var end = start + ang;
            var color = PALETTE[i % PALETTE.length];
            if (frac >= 0.999) {
                var c = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
                c.setAttribute('cx', cx); c.setAttribute('cy', cy); c.setAttribute('r', r);
                c.setAttribute('fill', 'none'); c.setAttribute('stroke', color); c.setAttribute('stroke-width', width);
                svg.appendChild(c);
            } else if (ang > 0.001) {
                var x1 = cx + r * Math.cos(start), y1 = cy + r * Math.sin(start);
                var x2 = cx + r * Math.cos(end), y2 = cy + r * Math.sin(end);
                var large = ang > Math.PI ? 1 : 0;
                var p = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                p.setAttribute('d', 'M ' + x1.toFixed(2) + ' ' + y1.toFixed(2) + ' A ' + r + ' ' + r + ' 0 ' + large + ' 1 ' + x2.toFixed(2) + ' ' + y2.toFixed(2));
                p.setAttribute('fill', 'none'); p.setAttribute('stroke', color); p.setAttribute('stroke-width', width);
                svg.appendChild(p);
            }
            start = end;
            var row = document.createElement('div');
            row.className = 'copkm-leg-row';
            row.innerHTML = '<span class="copkm-leg-dot" style="background:' + color + '"></span>' +
                '<span class="copkm-leg-label" title="' + esc(e.label) + '">' + esc(e.label) + '</span>' +
                '<span class="copkm-leg-val"><b>' + (e.per_km !== null ? fmtPerKmShort(e.per_km) : fmtLei(e.total)) + '</b> (' + nf1.format(e.share_pct) + '%)</span>';
            legend.appendChild(row);
        });
    }

    // ---------- actual vs break-even (bare) ----------
    function renderAvbChart(d) {
        var be = d.breakeven;
        var svg = $('avb-chart');
        var groups = [
            { label: 'Actual', cost: perKm(be.cost_per_km), rev: perKm(be.revenue_per_km), res: perKm(be.result_per_km) },
            { label: 'Break-even', cost: be.reachable ? perKm(be.cost_per_km_at_breakeven) : null, rev: be.reachable ? perKm(be.revenue_per_km) : null, res: be.reachable ? 0 : null }
        ];
        if (state.simScenario) {
            var sim = state.simScenario;
            groups.push({ label: 'Simulare (' + nf0.format(sim.params.km) + ' km)', cost: perKm(sim.cost_per_km), rev: perKm(sim.revenue_per_km), res: perKm(sim.result_per_km) });
        } else {
            groups.push({ label: 'Simulare', cost: null, rev: null, res: null });
        }
        var values = [];
        groups.forEach(function (g) { ['cost', 'rev', 'res'].forEach(function (k) { if (g[k] !== null && isFinite(g[k])) { values.push(g[k]); } }); });
        var maxV = Math.max(1, values.reduce(function (a, b) { return Math.max(a, b); }, 0));
        var minV = Math.min(0, values.reduce(function (a, b) { return Math.min(a, b); }, 0));
        var W = 560, H = 300, padL = 46, padB = 34, padT = 14;
        var plotH = H - padB - padT;
        var range = maxV - minV || 1;
        function y(v) { return padT + (maxV - v) / range * plotH; }
        var zeroY = y(0);
        var html = '';
        // grilă
        for (var i = 0; i <= 4; i++) {
            var gv = minV + range * i / 4;
            var gy = y(gv);
            html += '<line x1="' + padL + '" y1="' + gy + '" x2="' + W + '" y2="' + gy + '" stroke="#eef1f7" stroke-width="1"/>';
            html += '<text x="' + (padL - 6) + '" y="' + (gy + 3) + '" text-anchor="end" font-size="9" fill="#94a3b8">' + nf1.format(gv) + '</text>';
        }
        var groupW = (W - padL) / groups.length;
        var barW = Math.min(34, groupW / 5);
        var colors = { cost: '#2563eb', rev: '#16a34a', res: '#dc2626' };
        groups.forEach(function (g, gi) {
            var gx = padL + gi * groupW + groupW / 2;
            ['cost', 'rev', 'res'].forEach(function (k, ki) {
                var v = g[k];
                var x = gx + (ki - 1) * (barW + 6) - barW / 2;
                if (v === null || !isFinite(v)) {
                    html += '<text x="' + (x + barW / 2) + '" y="' + (zeroY - 6) + '" text-anchor="middle" font-size="9" fill="#cbd5e1">—</text>';
                    return;
                }
                var by = v >= 0 ? y(v) : zeroY;
                var bh = Math.abs(y(v) - zeroY);
                html += '<rect x="' + x + '" y="' + by + '" width="' + barW + '" height="' + Math.max(bh, 1.5) + '" rx="3" fill="' + colors[k] + '"/>';
                html += '<text x="' + (x + barW / 2) + '" y="' + (v >= 0 ? by - 4 : by + bh + 10) + '" text-anchor="middle" font-size="9" font-weight="700" fill="#334155">' + nf2.format(v) + '</text>';
            });
            html += '<text x="' + gx + '" y="' + (H - 12) + '" text-anchor="middle" font-size="10" font-weight="600" fill="#475569">' + esc(g.label) + '</text>';
        });
        html += '<line x1="' + padL + '" y1="' + zeroY + '" x2="' + W + '" y2="' + zeroY + '" stroke="#94a3b8" stroke-width="1"/>';
        svg.innerHTML = html;

        var foot = $('avb-foot');
        var chips = '<span class="copkm-chip">Km actuali: <b>' + nf0.format(be.km_current) + ' km</b></span>';
        if (be.reachable) {
            chips += '<span class="copkm-chip">Km necesari (break-even): <b>' + nf0.format(Math.round(be.break_even_km)) + ' km</b></span>';
            chips += '<span class="copkm-chip">Km lipsă: <b class="copkm-neg">+' + nf0.format(Math.round(be.km_missing)) + ' km</b></span>';
        } else {
            chips += '<span class="copkm-chip">Break-even: <b class="copkm-neg">' + esc(be.reason || 'indisponibil') + '</b></span>';
        }
        foot.innerHTML = chips;
    }

    // ---------- tabele vehicule / șoferi / beneficiari ----------
    function statusBadge(status) {
        switch (status) {
            case 'activ': case 'ok': case 'acopera': return '<span class="copkm-badge copkm-badge-green">● Activ</span>';
            case 'pierdere': case 'critic': case 'nu_acopera': return '<span class="copkm-badge copkm-badge-red">● Critic</span>';
            case 'atentie': return '<span class="copkm-badge copkm-badge-orange">● Atenție</span>';
            case 'fara_activitate': return '<span class="copkm-badge copkm-badge-orange">○ Parțial</span>';
            default: return '<span class="copkm-badge copkm-badge-gray">○ Inactiv</span>';
        }
    }

    function sortRows(rows, sort) {
        var out = rows.slice();
        out.sort(function (a, b) {
            var av = a[sort.key], bv = b[sort.key];
            if (av === null || av === undefined) { av = -Infinity; }
            if (bv === null || bv === undefined) { bv = -Infinity; }
            if (typeof av === 'string') { return sort.dir * av.localeCompare(bv); }
            return sort.dir * (av - bv);
        });
        return out;
    }

    function vehicleTableHtml(d, full) {
        var rows = sortRows(d.vehicles || [], state.vehSort);
        if (!full && !state.showAllVehicles) { rows = rows.slice(0, 6); }
        var sortIcon = function (key) { return state.vehSort.key === key ? (state.vehSort.dir < 0 ? ' ↓' : ' ↑') : ''; };
        var html = '<table class="copkm-table"><thead><tr>' +
            '<th class="copkm-sort" data-veh-sort="label">Vehicul' + sortIcon('label') + '</th><th>Categorie</th>' +
            '<th class="copkm-sort" data-veh-sort="km">Km reali' + sortIcon('km') + '</th>' +
            '<th class="copkm-sort" data-veh-sort="fixed_per_km">Cost fix/km' + sortIcon('fixed_per_km') + '</th>' +
            '<th class="copkm-sort" data-veh-sort="variable_per_km">Cost var/km' + sortIcon('variable_per_km') + '</th>' +
            '<th class="copkm-sort" data-veh-sort="total_per_km">Cost total/km' + sortIcon('total_per_km') + '</th>' +
            '<th class="copkm-sort" data-veh-sort="total_cost">Cost total (lei)' + sortIcon('total_cost') + '</th>' +
            '<th class="copkm-sort" data-veh-sort="revenue_per_km">Venit/km' + sortIcon('revenue_per_km') + '</th>' +
            '<th class="copkm-sort" data-veh-sort="result_per_km">Rezultat/km' + sortIcon('result_per_km') + '</th>' +
            '<th>Status</th><th>Acțiuni</th></tr></thead><tbody>';
        if (!rows.length) { html += '<tr><td colspan="11" style="text-align:center;color:var(--muted)">Niciun vehicul în scopul selectat.</td></tr>'; }
        rows.forEach(function (v) {
            html += '<tr>' +
                '<td style="text-align:left"><b>' + esc(v.label) + '</b>' + (v.vehicle_status === 'inactiv' ? ' <span class="copkm-details-sub">(inactiv)</span>' : '') + '</td>' +
                '<td style="text-align:left">' + esc(v.category_label) + '</td>' +
                '<td>' + nf0.format(v.km) + '</td>' +
                '<td>' + fmtPerKmShort(v.fixed_per_km) + '</td>' +
                '<td>' + fmtPerKmShort(v.variable_per_km) + '</td>' +
                '<td><b>' + fmtPerKmShort(v.total_per_km) + '</b></td>' +
                '<td>' + nf0.format(Math.round(v.total_cost)) + '</td>' +
                '<td>' + fmtPerKmShort(v.revenue_per_km) + '</td>' +
                '<td class="' + (v.result_per_km !== null && v.result_per_km < 0 ? 'copkm-neg' : 'copkm-pos') + '">' + fmtPerKmShort(v.result_per_km) + '</td>' +
                '<td>' + statusBadge(v.status) + '</td>' +
                '<td><a href="#" class="copkm-link" style="margin:0" data-veh-details="' + v.vehicle_id + '" data-veh-label="' + esc(v.label) + '">Detalii</a></td>' +
                '</tr>';
        });
        html += '</tbody></table>';
        return html;
    }

    function driverTableHtml(d, full) {
        var rows = sortRows(d.drivers || [], state.drvSort);
        if (!full && !state.showAllDrivers) { rows = rows.slice(0, 6); }
        var sortIcon = function (key) { return state.drvSort.key === key ? (state.drvSort.dir < 0 ? ' ↓' : ' ↑') : ''; };
        var html = '<table class="copkm-table"><thead><tr>' +
            '<th class="copkm-sort" data-drv-sort="name">Șofer' + sortIcon('name') + '</th>' +
            '<th class="copkm-sort" data-drv-sort="km">Km reali' + sortIcon('km') + '</th>' +
            '<th class="copkm-sort" data-drv-sort="trips">Curse' + sortIcon('trips') + '</th>' +
            '<th class="copkm-sort" data-drv-sort="personnel_cost">Cost personal (lei)' + sortIcon('personnel_cost') + '</th>' +
            '<th class="copkm-sort" data-drv-sort="personnel_per_km">Cost personal/km' + sortIcon('personnel_per_km') + '</th>' +
            '<th class="copkm-sort" data-drv-sort="total_per_km">Cost total/km' + sortIcon('total_per_km') + '</th>' +
            '<th class="copkm-sort" data-drv-sort="revenue_per_km">Venit/km' + sortIcon('revenue_per_km') + '</th>' +
            '<th class="copkm-sort" data-drv-sort="result_per_km">Rezultat/km' + sortIcon('result_per_km') + '</th>' +
            '<th>Status</th><th>Acțiuni</th></tr></thead><tbody>';
        if (!rows.length) { html += '<tr><td colspan="10" style="text-align:center;color:var(--muted)">Niciun șofer în scopul selectat.</td></tr>'; }
        rows.forEach(function (r) {
            html += '<tr>' +
                '<td style="text-align:left"><b>' + esc(r.name) + '</b></td>' +
                '<td>' + nf0.format(r.km) + '</td>' +
                '<td>' + nf0.format(r.trips) + '</td>' +
                '<td>' + nf0.format(Math.round(r.personnel_cost)) + '</td>' +
                '<td>' + fmtPerKmShort(r.personnel_per_km) + '</td>' +
                '<td><b>' + fmtPerKmShort(r.total_per_km) + '</b></td>' +
                '<td>' + fmtPerKmShort(r.revenue_per_km) + '</td>' +
                '<td class="' + (r.result_per_km !== null && r.result_per_km < 0 ? 'copkm-neg' : 'copkm-pos') + '">' + fmtPerKmShort(r.result_per_km) + '</td>' +
                '<td>' + statusBadge(r.status) + '</td>' +
                '<td><a href="#" class="copkm-link" style="margin:0" data-drv-details="' + r.driver_id + '">Detalii</a></td>' +
                '</tr>';
        });
        html += '</tbody></table>';
        return html;
    }

    function beneficiaryTableHtml(d) {
        var rows = d.beneficiaries || [];
        var html = '<table class="copkm-table"><thead><tr>' +
            '<th>Beneficiar</th><th>Curse</th><th>Km reali</th><th>Km facturați</th><th>Vehicule</th>' +
            '<th>Fix/km</th><th>Var/km</th><th>Cost total/km</th><th>Cost total (lei)</th>' +
            '<th>Venit (lei)</th><th>Venit/km</th><th>Rezultat (lei)</th><th>Rezultat/km</th><th>Break-even</th></tr></thead><tbody>';
        if (!rows.length) { html += '<tr><td colspan="14" style="text-align:center;color:var(--muted)">Nicio activitate pe beneficiari în perioada selectată.</td></tr>'; }
        rows.forEach(function (b) {
            html += '<tr>' +
                '<td style="text-align:left"><b>' + esc(b.name) + '</b> <span class="copkm-details-sub">' + esc((b.types || []).join(', ')) + '</span></td>' +
                '<td>' + nf0.format(b.trips) + '</td>' +
                '<td>' + nf0.format(b.km) + '</td>' +
                '<td>' + nf0.format(b.km_billed) + '</td>' +
                '<td>' + nf0.format(b.vehicles) + '</td>' +
                '<td>' + fmtPerKmShort(b.fixed_per_km) + '</td>' +
                '<td>' + fmtPerKmShort(b.variable_per_km) + '</td>' +
                '<td><b>' + fmtPerKmShort(b.total_per_km) + '</b></td>' +
                '<td>' + nf0.format(Math.round(b.total_cost)) + '</td>' +
                '<td>' + nf0.format(Math.round(b.revenue)) + '</td>' +
                '<td>' + fmtPerKmShort(b.revenue_per_km) + '</td>' +
                '<td class="' + (b.result_total < 0 ? 'copkm-neg' : 'copkm-pos') + '">' + nf0.format(Math.round(b.result_total)) + '</td>' +
                '<td class="' + (b.result_per_km !== null && b.result_per_km < 0 ? 'copkm-neg' : 'copkm-pos') + '">' + fmtPerKmShort(b.result_per_km) + '</td>' +
                '<td>' + (b.breakeven === 'acopera'
                    ? '<span class="copkm-badge copkm-badge-green">Acoperă costurile</span>'
                    : '<span class="copkm-badge copkm-badge-red">Nu acoperă costurile</span>') + '</td>' +
                '</tr>';
        });
        html += '</tbody></table>';
        return html;
    }

    function bindTableEvents(container) {
        container.querySelectorAll('[data-veh-details]').forEach(function (a) {
            a.addEventListener('click', function (e) {
                e.preventDefault();
                openDetails('vehicle', a.getAttribute('data-veh-details'), a.getAttribute('data-veh-label'));
            });
        });
        container.querySelectorAll('[data-drv-details]').forEach(function (a) {
            a.addEventListener('click', function (e) {
                e.preventDefault();
                openDriverDetails(parseInt(a.getAttribute('data-drv-details'), 10));
            });
        });
        container.querySelectorAll('[data-veh-sort]').forEach(function (th) {
            th.addEventListener('click', function () {
                var key = th.getAttribute('data-veh-sort');
                state.vehSort = { key: key, dir: state.vehSort.key === key ? -state.vehSort.dir : -1 };
                renderVehicles(state.data); renderStructure(state.data);
            });
        });
        container.querySelectorAll('[data-drv-sort]').forEach(function (th) {
            th.addEventListener('click', function () {
                var key = th.getAttribute('data-drv-sort');
                state.drvSort = { key: key, dir: state.drvSort.key === key ? -state.drvSort.dir : -1 };
                renderDrivers(state.data); renderStructure(state.data);
            });
        });
    }

    function renderVehicles(d) {
        var wrap = $('veh-table-wrap');
        if (state.vehTab === 'categorie') {
            wrap.innerHTML = categoryTableHtml(d);
            wrap.querySelectorAll('[data-cat-details]').forEach(function (btn) {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    openDetails('category', btn.getAttribute('data-cat-details'), btn.getAttribute('data-cat-label'));
                });
            });
            $('veh-more').classList.add('d-none');
        } else if (state.vehTab === 'sofer') {
            wrap.innerHTML = driverTableHtml(d, false);
            bindTableEvents(wrap);
            $('veh-more').classList.add('d-none');
        } else {
            wrap.innerHTML = vehicleTableHtml(d, false);
            bindTableEvents(wrap);
            var more = $('veh-more');
            more.classList.toggle('d-none', (d.vehicles || []).length <= 6);
            more.innerHTML = (state.showAllVehicles ? 'Restrânge lista' : 'Vezi toate vehiculele (' + (d.vehicles || []).length + ')') + ' <i class="bi bi-arrow-right"></i>';
        }
    }

    function renderDrivers(d) {
        var wrap = $('drv-table-wrap');
        wrap.innerHTML = driverTableHtml(d, false);
        bindTableEvents(wrap);
        var more = $('drv-more');
        more.classList.toggle('d-none', (d.drivers || []).length <= 6);
        more.innerHTML = (state.showAllDrivers ? 'Restrânge lista' : 'Vezi toți șoferii (' + (d.drivers || []).length + ')') + ' <i class="bi bi-arrow-right"></i>';
    }

    function renderBeneficiaries(d) {
        $('ben-table-wrap').innerHTML = beneficiaryTableHtml(d);
    }

    // ---------- detalii / trasabilitate ----------
    function openModal(id) { $(id).classList.remove('d-none'); }
    function closeModal(id) { $(id).classList.add('d-none'); }

    function qualityBadge(clasa) {
        switch (clasa) {
            case 'auto': return '<span class="copkm-badge copkm-badge-green">AUTO</span>';
            case 'derived': return '<span class="copkm-badge copkm-badge-blue">DERIVED</span>';
            case 'config': return '<span class="copkm-badge copkm-badge-orange">CONFIG</span>';
            default: return '<span class="copkm-badge copkm-badge-red">LIPSĂ</span>';
        }
    }

    function openDetails(scope, id, label) {
        $('details-title').textContent = 'Detalii costuri — ' + (label || id);
        $('details-body').innerHTML = '<div class="copkm-details-sub">Se încarcă…</div>';
        openModal('modal-details');
        fetch(DETAILS_URL + '&' + filterQuery() + '&scope=' + encodeURIComponent(scope) + '&id=' + encodeURIComponent(id), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) { throw new Error(data.message || 'Eroare'); }
                var rows = data.rows || [];
                if (!rows.length) {
                    $('details-body').innerHTML = '<div class="copkm-details-sub">Niciun cost alocat acestui scop în perioada selectată.</div>';
                    return;
                }
                rows.sort(function (a, b) { return b.value - a.value; });
                var totalFix = 0, totalVar = 0;
                rows.forEach(function (r) { if (r.tip === 'fix') { totalFix += r.value; } else { totalVar += r.value; } });
                var km = data.km || 0;
                var html = '<div class="copkm-details-sub" style="margin-bottom:.5rem">Perioadă: <b>' + esc(state.data.period.label) + '</b> · Km scop: <b>' + nf0.format(km) + '</b>' +
                    ' · Fix: <b>' + fmtLei(totalFix) + '</b> · Variabil: <b>' + fmtLei(totalVar) + '</b> · Total: <b>' + fmtLei(totalFix + totalVar) + '</b></div>';
                html += '<div class="copkm-table-wrap"><table class="copkm-table"><thead><tr>' +
                    '<th>Componentă</th><th>Fix/Var</th><th>Sursă</th><th>Valoare alocată</th><th>' + unitLabel() + '</th></tr></thead><tbody>';
                rows.forEach(function (r) {
                    var srcRows = (r.detail || []).map(function (dd) {
                        return esc(dd.sursa || '') + (dd.normalizare ? ' · ' + esc(dd.normalizare) : '');
                    });
                    var srcHtml = srcRows.length ? '<div class="copkm-details-sub">' + srcRows.slice(0, 3).join('<br>') + (srcRows.length > 3 ? '<br>… (+' + (srcRows.length - 3) + ')' : '') + '</div>' : '';
                    html += '<tr>' +
                        '<td style="text-align:left"><b>' + esc(r.label) + '</b> ' + qualityBadge(r.clasa_sursa) + srcHtml + '</td>' +
                        '<td>' + (r.tip === 'fix' ? 'FIX' : 'VARIABIL') + '</td>' +
                        '<td style="text-align:left;max-width:180px;white-space:normal"><span class="copkm-details-sub">' + esc((r.detail && r.detail[0] && r.detail[0].sursa) || '') + '</span></td>' +
                        '<td><b>' + fmtLei2(r.value) + '</b></td>' +
                        '<td>' + (r.per_km !== null ? fmtPerKmShort(r.per_km) : '<span class="copkm-details-sub" title="0 km în perioada selectată">indisponibil</span>') + '</td>' +
                        '</tr>';
                });
                html += '</tbody></table></div>';
                html += '<div class="copkm-details-sub" style="margin-top:.5rem">Suma componentelor = totalul afișat în tabele (reconciliere 1:1). Elementele LIPSĂ nu sunt incluse în total — vezi bannerul de calitate a datelor.</div>';
                $('details-body').innerHTML = html;
            })
            .catch(function (err) {
                $('details-body').innerHTML = '<div class="alert alert-danger">' + esc(err.message) + '</div>';
            });
    }

    function openDriverDetails(driverId) {
        var d = state.data;
        var row = (d.drivers || []).find(function (r) { return r.driver_id === driverId; });
        if (!row) { return; }
        $('details-title').textContent = 'Detalii șofer — ' + row.name;
        var html = '<div class="copkm-details-sub" style="margin-bottom:.5rem">Perioadă: <b>' + esc(d.period.label) + '</b> · Km: <b>' + nf0.format(row.km) + '</b> · Curse: <b>' + row.trips + '</b> · Venit: <b>' + fmtLei(row.revenue) + '</b></div>';
        html += '<div class="copkm-table-wrap"><table class="copkm-table"><thead><tr><th>Componentă cost personal</th><th>Valoare (lei/lună)</th><th>' + unitLabel() + '</th></tr></thead><tbody>';
        (row.components || []).forEach(function (c) {
            html += '<tr><td style="text-align:left">' + esc(c.label) + '</td><td>' + fmtLei2(c.value) + '</td><td>' + (row.km > 0 ? fmtPerKmShort(c.value / row.km) : 'indisponibil') + '</td></tr>';
        });
        html += '<tr><td style="text-align:left"><b>Total cost personal</b></td><td><b>' + fmtLei2(row.personnel_cost) + '</b></td><td><b>' + fmtPerKmShort(row.personnel_per_km) + '</b></td></tr>';
        html += '<tr><td style="text-align:left">Costuri operaționale alocate (BY_KM, din vehiculele conduse)</td><td>' + fmtLei2(row.other_cost) + '</td><td>' + (row.km > 0 ? fmtPerKmShort(row.other_cost / row.km) : 'indisponibil') + '</td></tr>';
        html += '<tr><td style="text-align:left"><b>Cost total atribuibil</b></td><td><b>' + fmtLei2(row.total_cost) + '</b></td><td><b>' + fmtPerKmShort(row.total_per_km) + '</b></td></tr>';
        html += '</tbody></table></div>';
        html += '<div class="copkm-details-sub" style="margin-top:.5rem">Metrică = povara financiară atribuibilă șoferului ÷ activitatea asociată. Utilizarea scăzută produce cost/km ridicat — nu este automat "vina" șoferului.</div>';
        $('details-body').innerHTML = html;
        openModal('modal-details');
    }

    // ---------- elemente financiare (configurare) ----------
    function renderElements() {
        var d = state.data;
        var body = $('elements-body');
        var groups = { fix: [], variabil: [] };
        (d.elements || []).forEach(function (e) { groups[e.tip === 'fix' ? 'fix' : 'variabil'].push(e); });
        var qBadge = function (q) {
            if (q === 'complet') { return '<span class="copkm-badge copkm-badge-green">COMPLET</span>'; }
            if (q === 'partial') { return '<span class="copkm-badge copkm-badge-orange">PARȚIAL</span>'; }
            return '<span class="copkm-badge copkm-badge-red">LIPSĂ DATE</span>';
        };
        var html = '';
        [['fix', 'COSTURI FIXE'], ['variabil', 'COSTURI VARIABILE']].forEach(function (g) {
            html += '<div class="copkm-el-group-title">' + g[1] + '</div>';
            html += '<div class="copkm-table-wrap"><table class="copkm-table"><thead><tr>' +
                '<th>Element</th><th>Sursă</th><th>Stare</th><th>Scop / Alocare</th><th>Total perioadă (lei)</th><th>' + unitLabel() + '</th>' +
                (CAN_CONFIGURE ? '<th>Activ</th><th>Acțiuni</th>' : '') + '</tr></thead><tbody>';
            groups[g[0]].forEach(function (e) {
                var km = d.summary.km;
                html += '<tr style="' + (e.activ ? '' : 'opacity:.5') + '">' +
                    '<td style="text-align:left"><b>' + esc(e.nume) + '</b><div class="copkm-details-sub">' + esc(e.quality_note || e.observatii || '') + '</div></td>' +
                    '<td>' + qualityBadge(e.clasa_sursa) + '</td>' +
                    '<td>' + qBadge(e.quality) + '</td>' +
                    '<td style="text-align:left"><span class="copkm-details-sub">' + esc(e.scop) + ' · ' + esc(e.alocare) + ' · ' + esc(e.periodicitate) + '</span></td>' +
                    '<td>' + (e.total > 0 ? fmtLei2(e.total) : '—') + '</td>' +
                    '<td>' + (e.total > 0 && km > 0 ? fmtPerKmShort(e.total / km) : '—') + '</td>';
                if (CAN_CONFIGURE) {
                    html += '<td><input type="checkbox" data-el-toggle="' + e.id + '" ' + (e.activ ? 'checked' : '') + '></td>' +
                        '<td><a href="#" class="copkm-link" style="margin:0" data-el-edit="' + e.id + '">Editează</a>' +
                        (e.sursa === 'manual' ? ' · <a href="#" class="copkm-link copkm-neg" style="margin:0" data-el-delete="' + e.id + '">Șterge</a>' : '') + '</td>';
                }
                html += '</tr>';
            });
            html += '</tbody></table></div>';
        });
        body.innerHTML = html;

        if (CAN_CONFIGURE) {
            body.querySelectorAll('[data-el-toggle]').forEach(function (cb) {
                cb.addEventListener('change', function () {
                    postForm(EL_TOGGLE_URL, { id: cb.getAttribute('data-el-toggle'), activ: cb.checked ? '1' : '' })
                        .then(function () { load(); });
                });
            });
            body.querySelectorAll('[data-el-edit]').forEach(function (a) {
                a.addEventListener('click', function (e) {
                    e.preventDefault();
                    var el = (state.data.elements || []).find(function (x) { return x.id === parseInt(a.getAttribute('data-el-edit'), 10); });
                    if (el) { openElementForm(el); }
                });
            });
            body.querySelectorAll('[data-el-delete]').forEach(function (a) {
                a.addEventListener('click', function (e) {
                    e.preventDefault();
                    if (!window.confirm('Ștergi acest element financiar din configurare?')) { return; }
                    postForm(EL_DELETE_URL, { id: a.getAttribute('data-el-delete') })
                        .then(function () { load(); renderElementsSoon(); });
                });
            });
        }
        renderSettings();
    }

    function renderElementsSoon() { window.setTimeout(renderElements, 600); }

    function renderSettings() {
        var st = state.data.settings;
        var grid = $('settings-grid');
        var fields = [
            ['eur_ron_rate', 'Curs EUR/RON', st.eur_ron_rate],
            ['salariu_multiplicator', 'Multiplicator cost angajator', st.salariu_multiplicator],
            ['tva_carburant_fallback', 'TVA carburant fallback (%)', st.tva_carburant_fallback],
            ['management_alocare', 'Alocare Management/Office', st.management_alocare],
            ['km_source', 'Sursă km implicită', st.km_source]
        ];
        var html = '';
        fields.forEach(function (f) {
            if (f[0] === 'management_alocare') {
                html += '<div class="copkm-filter"><label>' + f[1] + '</label><select class="copkm-input" data-setting="' + f[0] + '" ' + (CAN_CONFIGURE ? '' : 'disabled') + '>' +
                    '<option value="vehicule_active"' + (f[2] === 'vehicule_active' ? ' selected' : '') + '>Egal pe vehicule active</option>' +
                    '<option value="km"' + (f[2] === 'km' ? ' selected' : '') + '>Proporțional cu km</option></select></div>';
            } else if (f[0] === 'km_source') {
                html += '<div class="copkm-filter"><label>' + f[1] + '</label><select class="copkm-input" data-setting="' + f[0] + '" ' + (CAN_CONFIGURE ? '' : 'disabled') + '>' +
                    '<option value="curse_reali"' + (f[2] === 'curse_reali' ? ' selected' : '') + '>Km reali (curse)</option>' +
                    '<option value="curse_facturati"' + (f[2] === 'curse_facturati' ? ' selected' : '') + '>Km facturați</option></select></div>';
            } else {
                html += '<div class="copkm-filter"><label>' + f[1] + '</label><input class="copkm-input" data-setting="' + f[0] + '" value="' + esc(f[2]) + '" ' + (CAN_CONFIGURE ? '' : 'readonly') + '></div>';
            }
        });
        if (CAN_CONFIGURE) {
            html += '<div class="copkm-filter" style="align-self:end"><button type="button" class="copkm-btn copkm-btn-primary copkm-btn-sm" id="btn-save-settings"><i class="bi bi-check-lg"></i> Salvează parametrii</button></div>';
        }
        grid.innerHTML = html;
        var btn = $('btn-save-settings');
        if (btn) {
            btn.addEventListener('click', function () {
                var payload = {};
                grid.querySelectorAll('[data-setting]').forEach(function (inp) { payload[inp.getAttribute('data-setting')] = inp.value; });
                postForm(SETTINGS_URL, payload).then(function (res) {
                    if (res.success) { load(); renderElementsSoon(); } else { window.alert(res.message || 'Eroare'); }
                });
            });
        }
    }

    function openElementForm(el) {
        var form = $('element-form');
        $('element-form-title').textContent = el ? 'Editează: ' + el.nume : 'Adaugă element financiar';
        form.elements.id.value = el ? el.id : 0;
        form.elements.cod.value = el ? el.cod : '';
        form.elements.nume.value = el ? el.nume : '';
        form.elements.tip.value = el ? el.tip : 'fix';
        form.elements.sursa_referinta.value = el ? (el.sursa === 'manual' || el.sursa === 'documente_vehicule' ? el.sursa : 'manual') : 'manual';
        form.elements.sursa_referinta.disabled = !!(el && el.sursa !== 'manual' && el.sursa !== 'documente_vehicule');
        if (el && el.sursa !== 'manual' && el.sursa !== 'documente_vehicule') {
            // sursă automată: păstrează referința, permite doar metadate + valoare fallback
            var opt = form.elements.sursa_referinta.querySelector('option[data-auto]');
            if (!opt) {
                opt = document.createElement('option');
                opt.setAttribute('data-auto', '1');
                form.elements.sursa_referinta.appendChild(opt);
            }
            opt.value = el.sursa; opt.textContent = 'Sursă automată: ' + el.sursa; opt.selected = true;
        }
        form.elements.sursa_filtru.value = el && el.sursa === 'documente_vehicule' ? '' : '';
        form.elements.scop.value = el ? el.scop : 'vehicle';
        form.elements.periodicitate.value = el ? el.periodicitate : 'anual';
        form.elements.valoare_config.value = el && el.valoare_config !== null ? el.valoare_config : '';
        form.elements.valoare_moneda.value = el ? el.valoare_moneda : 'RON';
        form.elements.amortizare_ani.value = el && el.amortizare_ani !== null ? el.amortizare_ani : '';
        form.elements.tipuri_vehicul.value = '';
        form.elements.regim_tva.value = el ? el.regim_tva : 'net';
        form.elements.activ.value = el ? (el.activ ? '1' : '0') : '1';
        form.elements.observatii.value = el ? (el.observatii || '') : '';
        openModal('modal-element-form');
    }

    // ---------- simulare ----------
    function initSimDefaults() {
        var be = state.data.breakeven;
        var kmDefault = be.reachable ? Math.round(be.break_even_km) : be.km_current;
        var maxKm = Math.max(60000, kmDefault * 3, be.km_current * 4);
        $('sim-km-range').max = String(maxKm);
        $('sim-km-range').value = String(kmDefault);
        $('sim-km').value = nf0.format(kmDefault);
        var tripsDefault = be.reachable && be.trips_needed !== null ? be.trips_needed : be.trips_current;
        $('sim-trips-range').max = String(Math.max(200, tripsDefault * 3));
        $('sim-trips-range').value = String(tripsDefault);
        $('sim-trips').value = String(tripsDefault);
        var rev = be.revenue_per_km !== null ? be.revenue_per_km : 0;
        $('sim-rev-range').max = String(Math.max(20, Math.ceil(rev * 4)));
        $('sim-rev-range').value = String(rev.toFixed(2));
        $('sim-rev').value = nf2.format(rev);
        runSimulation();
    }

    function parseNum(text) {
        var v = parseFloat(String(text).replace(/\./g, '').replace(',', '.'));
        return isFinite(v) ? v : 0;
    }

    function runSimulation() {
        if (!state.data) { return; }
        var km = parseInt($('sim-km-range').value, 10) || 0;
        var trips = parseInt($('sim-trips-range').value, 10) || 0;
        var rev = parseFloat($('sim-rev-range').value) || 0;
        window.clearTimeout(state.simTimer);
        state.simTimer = window.setTimeout(function () {
            fetch(SIMULATE_URL + '&' + filterQuery() + '&sim_km=' + km + '&sim_trips=' + trips + '&sim_revenue_km=' + rev, { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success) { return; }
                    renderSimResult(data.scenario);
                })
                .catch(function () { /* silențios — simularea e best-effort la tastare */ });
        }, 250);
    }

    function renderSimResult(sim) {
        $('simr-km').textContent = nf0.format(sim.params.km) + ' km';
        $('simr-trips').textContent = nf0.format(sim.params.trips) + ' curse';
        $('simr-cost').textContent = fmtLei(sim.cost_total);
        $('simr-rev').textContent = fmtLei(sim.revenue);
        $('simr-cost-km').textContent = sim.cost_per_km !== null ? fmtPerKm(sim.cost_per_km) : 'n/a';
        $('simr-rev-km').textContent = sim.revenue_per_km !== null ? fmtPerKm(sim.revenue_per_km) : 'n/a';
        $('simr-result').textContent = fmtLei(sim.result);
        $('simr-result').className = sim.result >= 0 ? 'copkm-pos' : 'copkm-neg';
        var pct = sim.recovery_pct !== null ? Math.max(0, Math.min(sim.recovery_pct, 100)) : 0;
        var circ = 2 * Math.PI * 50;
        $('sim-ring-fg').setAttribute('stroke-dasharray', (pct / 100 * circ).toFixed(1) + ' ' + circ.toFixed(1));
        $('sim-ring-pct').textContent = sim.recovery_pct !== null ? nf1.format(Math.min(sim.recovery_pct, 100)) + '%' : 'n/a';
        state.lastSim = sim;
    }

    // ---------- POST helper ----------
    function postForm(url, fields) {
        var fd = new FormData();
        fd.append('_token', CSRF);
        Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
        return fetch(url, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); });
    }

    // ---------- evenimente ----------
    $('btn-run').addEventListener('click', function () {
        if (CAN_CONFIGURE) {
            // cursul EUR/RON este parametru de configurare — se persistă la Rulează
            postForm(SETTINGS_URL, { eur_ron_rate: $('f-eur').value }).finally(load);
        } else {
            load();
        }
    });
    $('copkm-refresh').addEventListener('click', load);
    $('btn-reset').addEventListener('click', function () {
        $('f-period').value = new Date().toISOString().slice(0, 7);
        $('f-beneficiar').value = '';
        $('f-categorie').value = '';
        $('f-unit').value = 'lei';
        $('f-km-source').value = 'curse_reali';
        state.vedere = 'overall';
        document.querySelectorAll('#f-vedere .copkm-seg-btn').forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-vedere') === 'overall'); });
        load();
    });
    ['f-period', 'f-beneficiar', 'f-categorie', 'f-km-source'].forEach(function (id) {
        $(id).addEventListener('change', load);
    });
    $('f-unit').addEventListener('change', function () { renderAll(); });
    document.querySelectorAll('#f-vedere .copkm-seg-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            state.vedere = btn.getAttribute('data-vedere');
            document.querySelectorAll('#f-vedere .copkm-seg-btn').forEach(function (b) { b.classList.toggle('active', b === btn); });
            renderStructure(state.data);
        });
    });
    document.querySelectorAll('#veh-tabs .copkm-seg-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            state.vehTab = btn.getAttribute('data-vtab');
            document.querySelectorAll('#veh-tabs .copkm-seg-btn').forEach(function (b) { b.classList.toggle('active', b === btn); });
            renderVehicles(state.data);
        });
    });
    $('veh-more').addEventListener('click', function (e) {
        e.preventDefault();
        state.showAllVehicles = !state.showAllVehicles;
        renderVehicles(state.data);
    });
    $('drv-more').addEventListener('click', function (e) {
        e.preventDefault();
        state.showAllDrivers = !state.showAllDrivers;
        renderDrivers(state.data);
    });
    $('copkm-export').addEventListener('click', function (e) {
        e.preventDefault();
        window.location.href = EXPORT_URL + '&' + filterQuery();
    });

    // simulare: sincronizare range <-> input numeric
    [['sim-km-range', 'sim-km', nf0], ['sim-trips-range', 'sim-trips', nf0], ['sim-rev-range', 'sim-rev', nf2]].forEach(function (pair) {
        var range = $(pair[0]), num = $(pair[1]), fmt = pair[2];
        range.addEventListener('input', function () {
            num.value = fmt.format(parseFloat(range.value));
            runSimulation();
        });
        num.addEventListener('change', function () {
            var v = parseNum(num.value);
            if (v > parseFloat(range.max)) { range.max = String(Math.ceil(v * 1.5)); }
            range.value = String(v);
            num.value = fmt.format(v);
            runSimulation();
        });
    });
    $('btn-apply-sim').addEventListener('click', function () {
        if (!state.lastSim) { return; }
        state.simScenario = state.lastSim;
        renderAvbChart(state.data);
        $('sec-chart').scrollIntoView({ behavior: 'smooth', block: 'center' });
    });

    // modale
    document.querySelectorAll('[data-copkm-open]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            e.preventDefault();
            var target = el.getAttribute('data-copkm-open');
            if (target === 'elements') { renderElements(); openModal('modal-elements'); }
            else { openModal('modal-' + target); }
        });
    });
    document.querySelectorAll('[data-copkm-close]').forEach(function (el) {
        el.addEventListener('click', function () { closeModal(el.getAttribute('data-copkm-close')); });
    });
    document.querySelectorAll('.copkm-modal-backdrop').forEach(function (bg) {
        bg.addEventListener('click', function (e) { if (e.target === bg) { bg.classList.add('d-none'); } });
    });

    var addBtn = $('btn-add-element');
    if (addBtn) { addBtn.addEventListener('click', function () { openElementForm(null); }); }
    $('element-form').addEventListener('submit', function (e) {
        e.preventDefault();
        var form = e.target;
        var fields = {};
        ['id', 'cod', 'nume', 'tip', 'sursa_referinta', 'sursa_filtru', 'scop', 'periodicitate', 'valoare_config', 'valoare_moneda', 'amortizare_ani', 'tipuri_vehicul', 'regim_tva', 'activ', 'observatii'].forEach(function (k) {
            var input = form.elements[k];
            fields[k] = input && !input.disabled ? input.value : (input ? input.value : '');
        });
        fields.valoare_config = String(fields.valoare_config).replace(/\./g, '').replace(',', '.');
        if (fields.activ === '1') { fields.activ = '1'; } else { delete fields.activ; }
        // sursele automate păstrează referința chiar dacă select-ul e dezactivat
        var el = (state.data.elements || []).find(function (x) { return x.id === parseInt(fields.id, 10); });
        if (el && el.sursa !== 'manual' && el.sursa !== 'documente_vehicule') { fields.sursa_referinta = el.sursa; fields.clasa_sursa = el.clasa_sursa; }
        postForm(EL_SAVE_URL, fields).then(function (res) {
            if (res.success) {
                closeModal('modal-element-form');
                load();
                renderElementsSoon();
            } else {
                window.alert(res.message || 'Eroare la salvare');
            }
        });
    });

    // pornire
    load();
})();
</script>
