<?php
/**
 * Dashboard Analitic V2.
 *
 * Structura paginii este un schelet: KPI-urile, graficele si tabelele sunt
 * randate din JS pe baza raspunsului de la page=dashboard_analytic_v2_data.
 */

$transportTypeLabels = (array) ($filterOptions['transport_type_labels'] ?? []);
$statusLabels = (array) ($filterOptions['status_labels'] ?? []);

/** Randeaza un filtru cu selectie multipla (checkbox-uri + cautare). */
$multiSelect = static function (
    string $name,
    string $label,
    string $icon,
    array $options,
    array $selected
): void {
    $selectedMap = [];
    foreach ($selected as $value) {
        $selectedMap[(string) $value] = true;
    }
    ?>
    <div class="da2-ms" data-ms data-name="<?= e($name) ?>" data-label="<?= e($label) ?>">
        <button type="button" class="da2-ms-toggle" data-ms-toggle aria-expanded="false">
            <i class="bi <?= e($icon) ?>" aria-hidden="true"></i>
            <span class="da2-ms-label"><?= e($label) ?></span>
            <span class="da2-ms-value" data-ms-value>Toate</span>
            <i class="bi bi-chevron-down da2-ms-caret" aria-hidden="true"></i>
        </button>
        <div class="da2-ms-panel" data-ms-panel hidden>
            <div class="da2-ms-search">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="search" placeholder="Caută…" data-ms-search autocomplete="off">
            </div>
            <div class="da2-ms-actions">
                <button type="button" data-ms-all>Selectează tot</button>
                <button type="button" data-ms-none>Golește</button>
            </div>
            <div class="da2-ms-list" data-ms-list>
                <?php if ($options === []): ?>
                    <p class="da2-ms-empty">Nu există opțiuni disponibile.</p>
                <?php endif; ?>
                <?php foreach ($options as $option): ?>
                    <?php $value = (string) $option['value']; ?>
                    <label class="da2-ms-option" data-ms-option data-text="<?= e(mb_strtolower((string) $option['label'])) ?>">
                        <input
                            type="checkbox"
                            name="<?= e($name) ?>[]"
                            value="<?= e($value) ?>"
                            <?= isset($selectedMap[$value]) ? 'checked' : '' ?>
                        >
                        <span><?= e((string) $option['label']) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php
};

// --- optiunile pentru fiecare filtru ---------------------------------------

$vehicleOptions = [];
foreach ((array) ($filterOptions['vehicles'] ?? []) as $row) {
    $id = (int) ($row['id'] ?? 0);
    if ($id > 0) {
        $vehicleOptions[] = ['value' => (string) $id, 'label' => (string) ($row['nr_inmatriculare'] ?? 'Necunoscut')];
    }
}

$driverOptions = [];
foreach ((array) ($filterOptions['drivers'] ?? []) as $row) {
    $id = (int) ($row['id'] ?? 0);
    if ($id > 0) {
        $driverOptions[] = ['value' => (string) $id, 'label' => (string) ($row['nume'] ?? 'Fără șofer')];
    }
}

$beneficiaryOptions = [];
foreach ((array) ($filterOptions['beneficiaries'] ?? []) as $row) {
    $id = (int) ($row['id'] ?? 0);
    if ($id > 0) {
        $beneficiaryOptions[] = ['value' => (string) $id, 'label' => (string) ($row['nume'] ?? 'Fără beneficiar')];
    }
}

$transportOptions = [];
foreach ((array) ($filterOptions['transport_types'] ?? []) as $row) {
    $value = trim((string) ($row['tip_transport'] ?? ''));
    if ($value !== '') {
        $transportOptions[] = [
            'value' => $value,
            'label' => (string) ($transportTypeLabels[$value] ?? ucfirst(str_replace('_', ' ', $value))),
        ];
    }
}

$capacityOptions = [];
foreach ((array) ($filterOptions['transport_capacities'] ?? []) as $row) {
    $raw = $row['capacitate_transport'] ?? null;
    if ($raw !== null && (float) $raw > 0) {
        $capacityOptions[] = [
            'value' => number_format((float) $raw, 2, '.', ''),
            'label' => format_number_ro((float) $raw, 2) . ' t',
        ];
    }
}

$statusOptions = [];
foreach ((array) ($filterOptions['statuses'] ?? []) as $row) {
    $value = trim((string) ($row['status_facturare'] ?? ''));
    if ($value !== '') {
        $statusOptions[] = [
            'value' => $value,
            'label' => (string) ($statusLabels[$value] ?? ucfirst(str_replace('_', ' ', $value))),
        ];
    }
}

$pageConfig = [
    'endpoint' => build_query_url(['page' => 'dashboard_analytic_v2_data']),
    'entityEndpoint' => build_query_url(['page' => 'dashboard_analytic_v2_entity']),
    'resetUrl' => build_query_url(['page' => 'dashboard_analitic_v2']),
    'transportTypeLabels' => $transportTypeLabels,
    'statusLabels' => $statusLabels,
];
?>

<link rel="stylesheet" href="<?= e(url('assets/css/dashboard-analitic-v2.css?v=' . (string) @filemtime(BASE_PATH . '/assets/css/dashboard-analitic-v2.css'))) ?>">

<div class="da2" id="da2-root">
    <script type="application/json" id="da2-config"><?= json_encode($pageConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

    <header class="da2-hero">
        <div class="da2-hero-text">
            <h1 class="da2-hero-title">Dashboard Analitic</h1>
            <p class="da2-hero-sub" id="da2-period-label">Se încarcă perioada…</p>
        </div>
        <div class="da2-hero-actions">
            <span class="da2-refresh-info" id="da2-last-refresh">Neactualizat</span>
            <label class="da2-switch">
                <input type="checkbox" id="da2-auto-refresh">
                <span>Auto 60s</span>
            </label>
            <button type="button" class="da2-btn" id="da2-refresh-btn">
                <i class="bi bi-arrow-clockwise" aria-hidden="true"></i><span>Reîmprospătează</span>
            </button>
            <button type="button" class="da2-btn" id="da2-export-btn">
                <i class="bi bi-filetype-csv" aria-hidden="true"></i><span>Export CSV</span>
            </button>
            <button type="button" class="da2-btn" id="da2-print-btn">
                <i class="bi bi-printer" aria-hidden="true"></i><span>Tipărește</span>
            </button>
        </div>
    </header>

    <form class="da2-filters" id="da2-filters" autocomplete="off">
        <div class="da2-filters-row da2-filters-row-top">
            <div class="da2-presets" role="group" aria-label="Perioade rapide">
                <button type="button" class="da2-preset" data-preset="luna_curenta">Luna curentă</button>
                <button type="button" class="da2-preset" data-preset="luna_trecuta">Luna trecută</button>
                <button type="button" class="da2-preset" data-preset="ultimele_30">Ultimele 30 zile</button>
                <button type="button" class="da2-preset" data-preset="trimestru">Trimestrul curent</button>
                <button type="button" class="da2-preset" data-preset="an">Anul curent</button>
            </div>
            <div class="da2-dates">
                <label class="da2-date">
                    <span>De la</span>
                    <input type="date" id="da2-date-start" name="date_start" value="<?= e((string) ($filters['date_start'] ?? '')) ?>">
                </label>
                <label class="da2-date">
                    <span>Până la</span>
                    <input type="date" id="da2-date-end" name="date_end" value="<?= e((string) ($filters['date_end'] ?? '')) ?>">
                </label>
            </div>
        </div>

        <div class="da2-filters-row da2-filters-row-selects">
            <?php $multiSelect('vehicle_ids', 'Vehicule', 'bi-truck', $vehicleOptions, (array) ($filters['vehicle_ids'] ?? [])); ?>
            <?php $multiSelect('driver_ids', 'Șoferi', 'bi-person-badge', $driverOptions, (array) ($filters['driver_ids'] ?? [])); ?>
            <?php $multiSelect('beneficiary_ids', 'Beneficiari', 'bi-building', $beneficiaryOptions, (array) ($filters['beneficiary_ids'] ?? [])); ?>
            <?php $multiSelect('transport_types', 'Tip transport', 'bi-diagram-3', $transportOptions, (array) ($filters['transport_types'] ?? [])); ?>
            <?php $multiSelect('transport_capacities', 'Capacitate', 'bi-box-seam', $capacityOptions, (array) ($filters['transport_capacities'] ?? [])); ?>
            <?php $multiSelect('statuses', 'Status facturare', 'bi-receipt', $statusOptions, (array) ($filters['statuses'] ?? [])); ?>
        </div>

        <div class="da2-filters-row da2-filters-row-chips">
            <div class="da2-chips" id="da2-chips"></div>
            <button type="button" class="da2-btn da2-btn-ghost da2-btn-sm" id="da2-reset-btn">
                <i class="bi bi-x-circle" aria-hidden="true"></i><span>Resetează filtrele</span>
            </button>
        </div>
    </form>

    <div class="da2-alert da2-alert-error" id="da2-error" hidden></div>

    <div class="da2-loading" id="da2-loading" hidden>
        <span class="da2-spinner" aria-hidden="true"></span>
        <span>Se încarcă datele…</span>
    </div>

    <section class="da2-kpis" id="da2-kpis" aria-label="Indicatori principali"></section>

    <?php // Panoul de detaliu al KPI-ului apasat; continutul este randat din JS. ?>
    <div class="da2-kpi-detail" id="da2-kpi-detail" role="region" aria-live="polite"></div>

    <nav class="da2-tabs" id="da2-tabs" role="tablist">
        <button type="button" class="da2-tab is-active" data-tab="general" role="tab"><i class="bi bi-grid-1x2" aria-hidden="true"></i>Prezentare generală</button>
        <button type="button" class="da2-tab" data-tab="comparatie" role="tab"><i class="bi bi-bar-chart-steps" aria-hidden="true"></i>Comparație</button>
        <button type="button" class="da2-tab" data-tab="raport" role="tab"><i class="bi bi-clipboard-data" aria-hidden="true"></i>Raport sumar</button>
        <button type="button" class="da2-tab" data-tab="vehicule" role="tab"><i class="bi bi-truck" aria-hidden="true"></i>Vehicule</button>
        <button type="button" class="da2-tab" data-tab="soferi" role="tab"><i class="bi bi-person-badge" aria-hidden="true"></i>Șoferi</button>
        <button type="button" class="da2-tab" data-tab="beneficiari" role="tab"><i class="bi bi-building" aria-hidden="true"></i>Beneficiari</button>
        <button type="button" class="da2-tab" data-tab="alerte" role="tab"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i>Alerte<span class="da2-tab-badge" id="da2-alerts-count" hidden>0</span></button>
    </nav>

    <!-- ============================ Prezentare generală ==================== -->
    <section class="da2-panel is-active" data-panel="general">
        <article class="da2-card da2-card-wide">
            <header class="da2-card-head">
                <div>
                    <h2>Evoluție zilnică</h2>
                    <p class="da2-card-sub">Bifează metricile pe care vrei să le vezi suprapuse.</p>
                </div>
                <div class="da2-card-tools">
                    <div class="da2-seg" data-seg="evolutionType">
                        <button type="button" data-value="line" class="is-active">Linie</button>
                        <button type="button" data-value="bar">Bare</button>
                    </div>
                    <label class="da2-check">
                        <input type="checkbox" id="da2-evolution-cumulative">
                        <span>Cumulat</span>
                    </label>
                </div>
            </header>
            <div class="da2-metric-toggles" id="da2-evolution-metrics"></div>
            <div class="da2-chart da2-chart-lg"><canvas id="da2-chart-evolution"></canvas></div>
        </article>

        <div class="da2-grid-2">
            <article class="da2-card">
                <header class="da2-card-head">
                    <div>
                        <h2>Distribuție pe tip de transport</h2>
                        <p class="da2-card-sub">Click pe un segment pentru a filtra pagina.</p>
                    </div>
                    <div class="da2-card-tools">
                        <div class="da2-seg" data-seg="distributionMetric">
                            <button type="button" data-value="curse" class="is-active">Curse</button>
                            <button type="button" data-value="km">Km</button>
                            <button type="button" data-value="tone">Tone</button>
                            <button type="button" data-value="profit">Profit</button>
                        </div>
                    </div>
                </header>
                <div class="da2-chart"><canvas id="da2-chart-transport"></canvas></div>
            </article>

            <article class="da2-card">
                <header class="da2-card-head">
                    <div>
                        <h2>Km facturați vs. nefacturați</h2>
                        <p class="da2-card-sub">Plus km salvați și km în exces față de ruta tarifată.</p>
                    </div>
                </header>
                <div class="da2-chart"><canvas id="da2-chart-km"></canvas></div>
                <div class="da2-mini-stats" id="da2-km-stats"></div>
            </article>
        </div>

        <article class="da2-card da2-card-wide">
            <header class="da2-card-head">
                <div>
                    <h2>Clasament</h2>
                    <p class="da2-card-sub">Click pe o bară pentru a adăuga entitatea în comparație.</p>
                </div>
                <div class="da2-card-tools">
                    <div class="da2-seg" data-seg="rankDimension">
                        <button type="button" data-value="vehicles" class="is-active">Vehicule</button>
                        <button type="button" data-value="drivers">Șoferi</button>
                        <button type="button" data-value="beneficiaries">Beneficiari</button>
                    </div>
                    <select class="da2-select" id="da2-rank-metric"></select>
                    <select class="da2-select da2-select-sm" id="da2-rank-limit">
                        <option value="5">Top 5</option>
                        <option value="10" selected>Top 10</option>
                        <option value="15">Top 15</option>
                        <option value="0">Toate</option>
                    </select>
                </div>
            </header>
            <div class="da2-chart da2-chart-lg"><canvas id="da2-chart-rank"></canvas></div>
        </article>

        <article class="da2-card da2-card-wide">
            <header class="da2-card-head">
                <div>
                    <h2>Km vs. profit</h2>
                    <p class="da2-card-sub">Mărimea bulei = numărul de curse. Click pe o bulă pentru comparație.</p>
                </div>
                <div class="da2-card-tools">
                    <div class="da2-seg" data-seg="scatterDimension">
                        <button type="button" data-value="vehicles" class="is-active">Vehicule</button>
                        <button type="button" data-value="drivers">Șoferi</button>
                        <button type="button" data-value="beneficiaries">Beneficiari</button>
                    </div>
                </div>
            </header>
            <div class="da2-chart da2-chart-lg"><canvas id="da2-chart-scatter"></canvas></div>
        </article>
    </section>

    <!-- ================================ Comparație ========================= -->
    <section class="da2-panel" data-panel="comparatie">
        <article class="da2-card da2-card-wide">
            <header class="da2-card-head">
                <div>
                    <h2>Comparație directă</h2>
                    <p class="da2-card-sub">Alege dimensiunea, bifează entitățile și metricile de comparat.</p>
                </div>
                <div class="da2-card-tools">
                    <div class="da2-seg" data-seg="compareDimension">
                        <button type="button" data-value="vehicles" class="is-active">Vehicule</button>
                        <button type="button" data-value="drivers">Șoferi</button>
                        <button type="button" data-value="beneficiaries">Beneficiari</button>
                    </div>
                    <button type="button" class="da2-btn da2-btn-sm" id="da2-compare-top">Adaugă top 5</button>
                    <button type="button" class="da2-btn da2-btn-sm da2-btn-ghost" id="da2-compare-clear">Golește</button>
                </div>
            </header>

            <div class="da2-compare-grid">
                <div class="da2-compare-picker">
                    <div class="da2-ms-search da2-ms-search-inline">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <input type="search" id="da2-compare-search" placeholder="Caută…" autocomplete="off">
                    </div>
                    <div class="da2-compare-list" id="da2-compare-list"></div>
                </div>
                <div class="da2-compare-main">
                    <div class="da2-metric-toggles" id="da2-compare-metrics"></div>
                    <div class="da2-chart da2-chart-lg"><canvas id="da2-chart-compare"></canvas></div>
                </div>
            </div>
        </article>

        <div class="da2-grid-2">
            <article class="da2-card">
                <header class="da2-card-head">
                    <div>
                        <h2>Profil normalizat</h2>
                        <p class="da2-card-sub">Fiecare axă este scalată 0–100 față de cea mai mare valoare din selecție.</p>
                    </div>
                </header>
                <div class="da2-chart da2-chart-lg"><canvas id="da2-chart-radar"></canvas></div>
            </article>

            <article class="da2-card">
                <header class="da2-card-head">
                    <div>
                        <h2>Structura pe tip de transport</h2>
                        <p class="da2-card-sub">Km per tip de transport, pentru entitățile selectate.</p>
                    </div>
                </header>
                <div class="da2-chart da2-chart-lg"><canvas id="da2-chart-compare-mix"></canvas></div>
            </article>
        </div>

        <article class="da2-card da2-card-wide">
            <header class="da2-card-head">
                <div>
                    <h2>Tabel comparativ</h2>
                    <p class="da2-card-sub">Δ este diferența față de media entităților selectate.</p>
                </div>
            </header>
            <div class="da2-table-wrap"><table class="da2-table" id="da2-compare-table"></table></div>
        </article>
    </section>

    <!-- =============================== Raport sumar ======================== -->
    <section class="da2-panel" data-panel="raport">
        <div class="da2-report-head" id="da2-report-head"></div>

        <article class="da2-card da2-card-wide">
            <header class="da2-card-head">
                <div>
                    <h2>Medii pe tip de transport</h2>
                    <p class="da2-card-sub">
                        Media pe cursă, media pe client (total ÷ numărul de clienți care au avut acel tip)
                        și media pe punct de livrare.
                    </p>
                </div>
            </header>
            <div class="da2-table-wrap"><table class="da2-table" id="da2-summary-transport"></table></div>
        </article>

        <article class="da2-card da2-card-wide">
            <header class="da2-card-head">
                <div>
                    <h2>Matrice client × tip de transport</h2>
                    <p class="da2-card-sub">Ultimul rând este media pe client.</p>
                </div>
                <div class="da2-card-tools">
                    <div class="da2-seg" data-seg="matrixMetric">
                        <button type="button" data-value="km" class="is-active">Km</button>
                        <button type="button" data-value="tone">Tone</button>
                        <button type="button" data-value="km_per_cursa">Km / cursă</button>
                        <button type="button" data-value="tone_per_cursa">Tone / cursă</button>
                        <button type="button" data-value="curse">Curse</button>
                    </div>
                </div>
            </header>
            <div class="da2-table-wrap"><table class="da2-table da2-table-matrix" id="da2-summary-matrix"></table></div>
        </article>

        <article class="da2-card da2-card-wide">
            <header class="da2-card-head">
                <div>
                    <h2>Totaluri pe client</h2>
                    <p class="da2-card-sub">Rândul evidențiat este media pe client pentru fiecare coloană.</p>
                </div>
            </header>
            <div class="da2-table-wrap"><table class="da2-table" id="da2-summary-clients"></table></div>
        </article>
    </section>

    <!-- ============================ Tabele entități ======================== -->
    <section class="da2-panel" data-panel="vehicule">
        <article class="da2-card da2-card-wide">
            <header class="da2-card-head">
                <div>
                    <h2>Profitabilitate vehicule</h2>
                    <p class="da2-card-sub">Click pe rând deschide detaliul complet. Click pe antet sortează, iar butonul din dreptul numelui (sau Ctrl + click) adaugă în comparație.</p>
                </div>
                <div class="da2-card-tools">
                    <div class="da2-ms-search da2-ms-search-inline">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <input type="search" data-table-search="vehicles" placeholder="Caută vehicul…" autocomplete="off">
                    </div>
                </div>
            </header>
            <div class="da2-table-wrap"><table class="da2-table" data-table="vehicles"></table></div>
        </article>
    </section>

    <section class="da2-panel" data-panel="soferi">
        <article class="da2-card da2-card-wide">
            <header class="da2-card-head">
                <div>
                    <h2>Performanță șoferi</h2>
                    <p class="da2-card-sub">Click pe rând deschide detaliul complet. Click pe antet sortează, iar butonul din dreptul numelui (sau Ctrl + click) adaugă în comparație.</p>
                </div>
                <div class="da2-card-tools">
                    <div class="da2-ms-search da2-ms-search-inline">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <input type="search" data-table-search="drivers" placeholder="Caută șofer…" autocomplete="off">
                    </div>
                </div>
            </header>
            <div class="da2-table-wrap"><table class="da2-table" data-table="drivers"></table></div>
        </article>
    </section>

    <section class="da2-panel" data-panel="beneficiari">
        <article class="da2-card da2-card-wide">
            <header class="da2-card-head">
                <div>
                    <h2>Performanță beneficiari</h2>
                    <p class="da2-card-sub">Click pe rând deschide detaliul complet. Click pe antet sortează, iar butonul din dreptul numelui (sau Ctrl + click) adaugă în comparație.</p>
                </div>
                <div class="da2-card-tools">
                    <div class="da2-ms-search da2-ms-search-inline">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <input type="search" data-table-search="beneficiaries" placeholder="Caută beneficiar…" autocomplete="off">
                    </div>
                </div>
            </header>
            <div class="da2-table-wrap"><table class="da2-table" data-table="beneficiaries"></table></div>
        </article>
    </section>

    <section class="da2-panel" data-panel="alerte">
        <article class="da2-card da2-card-wide">
            <header class="da2-card-head">
                <div>
                    <h2>Alerte</h2>
                    <p class="da2-card-sub">
                        Praguri: profit negativ, profit/km ≤ 0, km nefacturați &gt; 20%, grad de încărcare efectiv &lt; 50%,
                        grad de folosință mult sub media flotei, marjă client &lt; 10%.
                    </p>
                </div>
                <div class="da2-card-tools">
                    <div class="da2-seg" data-seg="alertSeverity">
                        <button type="button" data-value="" class="is-active">Toate</button>
                        <button type="button" data-value="danger">Critice</button>
                        <button type="button" data-value="warning">Avertizări</button>
                        <button type="button" data-value="info">Informative</button>
                    </div>
                </div>
            </header>
            <div class="da2-alerts" id="da2-alerts"></div>
        </article>
    </section>

    <?php // Panoul de detaliu pentru un vehicul / sofer / beneficiar; continut randat din JS. ?>
    <div class="da2-drawer" id="da2-drawer" hidden>
        <div class="da2-drawer-backdrop" data-drawer-close></div>
        <aside class="da2-drawer-panel" role="dialog" aria-modal="true" aria-labelledby="da2-drawer-title">
            <div class="da2-drawer-content" id="da2-drawer-content"></div>
        </aside>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="<?= e(url('assets/js/dashboard-analitic-v2.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/dashboard-analitic-v2.js'))) ?>"></script>
