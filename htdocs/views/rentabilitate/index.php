<?php
/**
 * Rentabilitate Operationala — macheta aprobata, in shell-ul Fleet (fara antet / meniu propriu).
 * Toate valorile vin din ProfitabilityRegisterService (JSON); pagina doar randeaza.
 */
$config = [
    'urls' => [
        'base' => build_query_url(['page' => 'rentabilitate']),
        'trip' => build_query_url(['page' => 'dispecer_curse', 'action' => 'edit']),
        'istoric' => build_query_url(['page' => 'istoric_financiar']),
        'reparatii' => build_query_url(['page' => 'mentenanta', 'action' => 'repairs']),
        'mentenantaDoc' => build_query_url(['page' => 'mentenanta', 'action' => 'intervention_document', 'sursa' => 'inregistrare']),
        'carburanti' => build_query_url(['page' => 'carburanti']),
        'documente' => build_query_url(['page' => 'documente']),
        'contabilitate' => build_query_url(['page' => 'contabilitate_personal']),
        'soferi' => build_query_url(['page' => 'soferi']),
        'costKm' => build_query_url(['page' => 'cost_operational']),
        'lineView' => build_query_url(['page' => 'rentabilitate', 'action' => 'line_view']),
    ],
    'from' => $from,
    'to' => $to,
    'canExport' => !empty($canExport),
    'beneficiaries' => $beneficiaries ?? [],
];
$links = $links ?? [];
$cssVersion = (string) @filemtime(BASE_PATH . '/assets/css/rentabilitate.css');
$kpiCssVersion = (string) @filemtime(BASE_PATH . '/assets/css/rentabilitate-kpi.css');
$kpiJsVersion = (string) @filemtime(BASE_PATH . '/assets/js/rentabilitate-kpi.js');
$jsVersion = (string) @filemtime(BASE_PATH . '/assets/js/rentabilitate.js');
?>
<link rel="stylesheet" href="<?= e(url('assets/css/rentabilitate.css?v=' . $cssVersion)) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/morph-card.css?v=' . (string) @filemtime(BASE_PATH . '/assets/css/morph-card.css'))) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/rentabilitate-kpi.css?v=' . $kpiCssVersion)) ?>">

<div class="rop" id="ropRoot">
    <nav class="rop-crumbs" aria-label="breadcrumb">
        <span>Financiar</span><i class="bi bi-chevron-right" aria-hidden="true"></i><strong>Rentabilitate Operațională</strong>
    </nav>
    <h1 class="rop-title">Rentabilitate Operațională</h1>
    <p class="rop-subtitle">Analiză costuri, venituri și performanță pe vehicule, șoferi și combinații.</p>

    <div class="rop-toolbar">
        <div class="rop-mode" role="tablist" aria-label="Perspectivă">
            <button type="button" class="rop-mode-btn is-active" data-mode="vehicule" role="tab"><i class="bi bi-truck"></i>Vehicule</button>
            <button type="button" class="rop-mode-btn" data-mode="soferi" role="tab"><i class="bi bi-person"></i>Șoferi</button>
        </div>
        <label class="rop-search" for="ropSearch">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="search" id="ropSearch" placeholder="Caută vehicul, șofer, beneficiar..." autocomplete="off">
        </label>
        <label class="rop-select"><select id="ropTip" aria-label="Tip transport"><option value="">Tip transport</option></select></label>
        <label class="rop-select"><select id="ropBenef" aria-label="Beneficiar"><option value="">Beneficiar</option></select></label>
        <label class="rop-select rop-select-sm"><select id="ropStatus" aria-label="Status">
            <option value="">Status</option>
            <option value="profitabil">Profitabil</option>
            <option value="pierdere">Pe pierdere</option>
            <option value="incomplet">Date incomplete</option>
            <option value="fara_activitate">Fără activitate</option>
        </select></label>
        <div class="rop-range" id="ropRange">
            <button type="button" class="rop-range-btn" id="ropRangeBtn" aria-expanded="false"><i class="bi bi-calendar3"></i><span id="ropRangeLabel"></span><i class="bi bi-chevron-down rop-caret"></i></button>
            <div class="rop-range-pop" id="ropRangePop" hidden>
                <div class="rop-range-presets">
                    <button type="button" data-preset="luna">Luna curentă</button>
                    <button type="button" data-preset="3luni">Ultimele 3 luni</button>
                    <button type="button" data-preset="an">Anul curent</button>
                    <button type="button" data-preset="12luni">Ultimele 12 luni</button>
                    <button type="button" data-preset="tot">Tot istoricul</button>
                </div>
                <div class="rop-range-custom">
                    <label>De la<input type="date" id="ropFrom"></label>
                    <label>Până la<input type="date" id="ropTo"></label>
                    <button type="button" class="rop-btn-primary rop-btn-sm" id="ropRangeApply">Aplică</button>
                </div>
            </div>
        </div>
        <?php if (!empty($canExport)): ?>
        <button type="button" class="rop-btn-outline" id="ropExport"><i class="bi bi-download"></i><span>Exportă</span></button>
        <?php endif; ?>
        <div class="dropdown">
            <button type="button" class="rop-btn-primary" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-plus-lg"></i>Adaugă</button>
            <ul class="dropdown-menu dropdown-menu-end rop-menu">
                <?php if (!empty($links['cursa_noua'])): ?><li><a class="dropdown-item" href="<?= e($links['cursa_noua']) ?>"><i class="bi bi-truck"></i>Cursă (Dispecer curse)</a></li><?php endif; ?>
                <?php if (!empty($links['cheltuiala'])): ?><li><a class="dropdown-item" href="<?= e($links['cheltuiala']) ?>"><i class="bi bi-wallet2"></i>Cheltuială</a></li><?php endif; ?>
                <?php if (!empty($links['dosar'])): ?><li><a class="dropdown-item" href="<?= e($links['dosar']) ?>"><i class="bi bi-layers"></i>Dosar achiziție / investiție</a></li><?php endif; ?>
                <?php if (!empty($links['cost_km'])): ?><li><hr class="dropdown-divider"></li><li><a class="dropdown-item" href="<?= e($links['cost_km']) ?>"><i class="bi bi-sliders"></i>Configurare elemente de cost</a></li><?php endif; ?>
            </ul>
        </div>
    </div>

    <?php /* Cardurile KPI (assets/js/rentabilitate-kpi.js): filtrele lor sunt starea paginii (aceeasi ca bara de sus si tabelul). */ ?>
    <section id="ropKpis" aria-label="Indicatori de rentabilitate"></section>
    <div class="rop-active" id="ropActive" hidden></div>

    <div class="rop-quality" id="ropQuality" hidden></div>

    <div class="rop-card">
        <table class="rop-table" id="ropTable">
            <colgroup>
                <col style="width:3.4%"><col style="width:4%"><col style="width:12.2%"><col style="width:13.8%"><col style="width:10.6%"><col style="width:8.8%">
                <col style="width:8.2%"><col style="width:7.8%"><col style="width:7.6%"><col style="width:7.6%"><col style="width:7.6%"><col style="width:7%"><col style="width:5.4%">
            </colgroup>
            <thead id="ropHead"></thead>
            <tbody id="ropRows"><tr class="rop-state"><td colspan="13"><span class="rop-spinner"></span> Se calculează rentabilitatea…</td></tr></tbody>
        </table>
        <div class="rop-pager" id="ropPager"></div>
    </div>
</div>

<div class="rop-toast" id="ropToast" role="status" aria-live="polite"></div>
<script>window.ROP_CONFIG = <?= json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="<?= e(url('assets/js/morph-card.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/morph-card.js'))) ?>" defer></script>
<script src="<?= e(url('assets/js/rentabilitate-kpi.js?v=' . $kpiJsVersion)) ?>" defer></script>
<script src="<?= e(url('assets/js/rentabilitate.js?v=' . $jsVersion)) ?>" defer></script>
<?php /* Fisa inregistrarii de cost: acelasi panou ca fisa cursei din Dispecer curse. */ ?>
<link rel="stylesheet" href="<?= e(url('assets/css/race-view-overlay.css?v=' . (string) @filemtime(BASE_PATH . '/assets/css/race-view-overlay.css'))) ?>">
<script src="<?= e(url('assets/js/race-view-overlay.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/race-view-overlay.js'))) ?>"></script>
