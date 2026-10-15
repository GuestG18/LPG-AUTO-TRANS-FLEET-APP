<?php
/**
 * Rapoarte activitate utilizatori — continutul paginii (sub bara aplicatiei).
 * Tot ce depinde de filtre (KPI, grafice, tab-uri, tabel) e desenat de
 * assets/js/rapoarte-activitate.js din JSON-ul de mai jos si apoi din ?action=data.
 *
 * @var array|null $report @var string|null $error @var array $views @var array|null $activeView
 * @var array $state @var bool $canExport
 */
$report = is_array($report ?? null) ? $report : null;
$views = is_array($views ?? null) ? $views : [];
$state = is_array($state ?? null) ? $state : [];
$canExport = (bool) ($canExport ?? true);
$cssPath = BASE_PATH . '/assets/css/rapoarte-activitate.css';
$jsPath = BASE_PATH . '/assets/js/rapoarte-activitate.js';
$boot = [
    'report'    => $report,
    'views'     => $views,
    'state'     => $state,
    'canExport' => $canExport,
    'csrf'      => csrf_token(),
    'urls'      => [
        'data'       => build_query_url(['page' => 'rapoarte_activitate', 'action' => 'data']),
        'detail'     => build_query_url(['page' => 'rapoarte_activitate', 'action' => 'detail']),
        'export'     => build_query_url(['page' => 'rapoarte_activitate', 'action' => 'export']),
        'viewSave'   => build_query_url(['page' => 'rapoarte_activitate', 'action' => 'view_save']),
        'viewDelete' => build_query_url(['page' => 'rapoarte_activitate', 'action' => 'view_delete']),
        'page'       => build_query_url(['page' => 'rapoarte_activitate']),
        'chronology' => build_query_url(['page' => 'activitate_utilizatori']),
    ],
];
$bootJson = json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE);
?>
<link rel="stylesheet" href="<?= e(url('assets/css/rapoarte-activitate.css?v=' . (string) @filemtime($cssPath))) ?>">

<div class="activity-reports-page" id="arpRoot">
  <header class="arp-head">
    <div class="arp-head-title">
      <span class="arp-head-icon" aria-hidden="true"><i></i><i></i><i></i></span>
      <div>
        <h1>Rapoarte activitate utilizatori</h1>
        <p>Pagină dedicată pentru investigații și rapoarte detaliate privind acțiunile utilizatorilor pe fiecare modul și pagină din aplicație.</p>
      </div>
    </div>
    <div class="arp-head-side">
      <nav class="arp-crumbs" aria-label="breadcrumb">
        <span>Administrare</span><i class="bi bi-chevron-right" aria-hidden="true"></i>
        <a href="<?= e(build_query_url(['page' => 'activitate_utilizatori'])) ?>" title="Deschide cronologia (Activitate utilizatori)">Rapoarte activitate</a>
      </nav>
      <div class="arp-head-actions">
        <div class="arp-dd" data-arp-dd>
          <button type="button" class="arp-btn arp-btn-outline-primary" data-arp-dd-toggle id="arpSaveViewBtn">
            <i class="bi bi-bookmark"></i><span>Salvează vizualizarea</span>
          </button>
          <div class="arp-dd-menu arp-views-menu" id="arpViewsMenu" role="menu"></div>
        </div>
        <?php if ($canExport): ?>
          <button type="button" class="arp-btn" data-arp-export="csv"><i class="bi bi-download"></i><span>Export CSV</span></button>
          <button type="button" class="arp-btn" data-arp-export="xlsx"><i class="bi bi-file-earmark-spreadsheet"></i><span>Export Excel</span></button>
          <button type="button" class="arp-btn" data-arp-export="pdf"><i class="bi bi-file-earmark-text"></i><span>Export PDF</span></button>
        <?php endif; ?>
      </div>
    </div>
  </header>

  <?php if (!empty($error)): ?>
    <div class="arp-alert"><i class="bi bi-exclamation-triangle"></i> <?= e((string) $error) ?></div>
  <?php endif; ?>

  <form class="arp-card arp-filters" id="arpFilters" autocomplete="off" novalidate>
    <div class="arp-frow arp-frow-1">
      <label class="arp-fld">
        <span>Modul / Pagină</span>
        <select class="arp-input" name="module" id="arpModule"></select>
      </label>
      <label class="arp-fld">
        <span>Submodul / Secțiune</span>
        <select class="arp-input" name="section" id="arpSection"></select>
      </label>
      <label class="arp-fld">
        <span>Utilizator</span>
        <select class="arp-input" name="user" id="arpUser"></select>
      </label>
      <label class="arp-fld">
        <span>Rol</span>
        <select class="arp-input" name="role" id="arpRole"></select>
      </label>
      <div class="arp-fld arp-fld-actions">
        <span>Tip acțiune</span>
        <div class="arp-chips-box" id="arpActionChips" role="group" aria-label="Tip acțiune">
          <div class="arp-chips"></div>
          <button type="button" class="arp-chips-caret" id="arpChipsCaret" title="Selectează / deselectează toate"><i class="bi bi-chevron-down"></i></button>
        </div>
      </div>
      <div class="arp-fld arp-fld-period">
        <span>Perioadă</span>
        <div class="arp-period">
          <select class="arp-input" id="arpPreset" aria-label="Perioadă predefinită">
            <option value="custom">Custom</option>
            <option value="today">Azi</option>
            <option value="yesterday">Ieri</option>
            <option value="7">7 zile</option>
            <option value="30">30 zile</option>
            <option value="month">Luna</option>
            <option value="last_month">Luna trec.</option>
            <option value="year">Anul</option>
          </select>
          <button type="button" class="arp-pbtn" data-preset="7">7 zile</button>
          <button type="button" class="arp-pbtn" data-preset="30">30 zile</button>
          <button type="button" class="arp-pbtn" data-preset="custom">Personalizat</button>
        </div>
      </div>
    </div>
    <div class="arp-frow arp-frow-2">
      <label class="arp-fld">
        <span>De la</span>
        <input type="date" class="arp-input" name="from" id="arpFrom">
      </label>
      <label class="arp-fld">
        <span>Până la</span>
        <input type="date" class="arp-input" name="to" id="arpTo">
      </label>
      <label class="arp-fld">
        <span>ID înregistrare / Entitate</span>
        <span class="arp-input-icon"><i class="bi bi-search"></i><input type="text" class="arp-input" name="entity" id="arpEntity" placeholder="Ex: #976, B 123 ABC, număr cursă..."></span>
      </label>
      <label class="arp-fld">
        <span>Câmp modificat</span>
        <select class="arp-input" name="field" id="arpField"></select>
      </label>
      <div class="arp-fld arp-fld-toggle">
        <span>Doar acțiuni sensibile</span>
        <label class="arp-switch" title="Ștergeri, restaurări, acțiuni în bloc, conturi de utilizator, facturare, prețuri, autentificări eșuate">
          <input type="checkbox" name="sensitive" id="arpSensitive"><i></i>
        </label>
      </div>
      <div class="arp-fld arp-fld-toggle">
        <span>Include autentificări</span>
        <label class="arp-switch"><input type="checkbox" name="logins" id="arpLogins" checked><i></i></label>
      </div>
      <label class="arp-fld arp-fld-search">
        <span class="arp-sr">Căutare globală</span>
        <span class="arp-input-icon"><i class="bi bi-search"></i><input type="search" class="arp-input" name="q" id="arpQ" placeholder="Caută în descriere, utilizator, număr cursă, vehicul..."></span>
      </label>
      <div class="arp-fld arp-fld-btns">
        <button type="submit" class="arp-btn arp-btn-primary"><i class="bi bi-search"></i><span>Aplică filtre</span></button>
        <button type="button" class="arp-btn" id="arpReset"><i class="bi bi-sliders2"></i><span>Resetează</span></button>
      </div>
    </div>
  </form>

  <div class="arp-body">
    <div class="arp-main">
      <section class="arp-kpis" id="arpKpis" aria-label="Indicatori"></section>

      <section class="arp-analytics">
        <article class="arp-card arp-panel">
          <div class="arp-panel-head">
            <h2 id="arpModulesTitle">Activitate pe modul</h2>
            <select class="arp-input arp-input-sm" id="arpModulesLimit" aria-label="Module afișate">
              <option value="all">Toate modulele</option>
              <option value="5">Top 5</option>
            </select>
          </div>
          <div class="arp-bars" id="arpModuleBars"></div>
        </article>
        <article class="arp-card arp-panel">
          <div class="arp-panel-head"><h2>Distribuție tipuri de acțiuni</h2></div>
          <div class="arp-donut-wrap">
            <div class="arp-donut"><canvas id="arpDonut" width="128" height="128" aria-label="Distribuție tipuri de acțiuni"></canvas><div class="arp-donut-center"><strong id="arpDonutTotal">0</strong><span>acțiuni</span></div></div>
            <ul class="arp-legend" id="arpDonutLegend"></ul>
          </div>
        </article>
        <article class="arp-card arp-panel">
          <div class="arp-panel-head">
            <h2 id="arpHeatTitle">Activitate pe utilizatori (top 5)</h2>
            <select class="arp-input arp-input-sm" id="arpHeatMode" aria-label="Coloane heatmap">
              <option value="auto">Toate modulele</option>
              <option value="modules">Doar module</option>
            </select>
          </div>
          <div class="arp-heat" id="arpHeat"></div>
        </article>
        <article class="arp-card arp-panel">
          <div class="arp-panel-head">
            <h2>Tendință modificări</h2>
            <select class="arp-input arp-input-sm" id="arpTrendDays" aria-label="Interval tendință">
              <option value="7">Ultimele 7 zile</option>
              <option value="14">Ultimele 14 zile</option>
              <option value="30">Ultimele 30 zile</option>
              <option value="0">Perioada selectată</option>
            </select>
          </div>
          <div class="arp-trend"><canvas id="arpTrend" aria-label="Tendință modificări"></canvas></div>
          <ul class="arp-legend arp-legend-row" id="arpTrendLegend"></ul>
        </article>
      </section>

      <section class="arp-card arp-report" id="arpReport">
        <div class="arp-report-head">
          <h2><i class="bi bi-file-earmark-bar-graph" aria-hidden="true"></i> Raport detaliat pe module și pagini</h2>
          <div class="arp-report-tools">
            <div class="arp-seg" role="tablist" aria-label="Mod de prezentare">
              <button type="button" class="arp-seg-btn" data-mode="table"><i class="bi bi-table"></i> Tabel</button>
              <button type="button" class="arp-seg-btn" data-mode="chart"><i class="bi bi-bar-chart"></i> Grafic</button>
              <button type="button" class="arp-seg-btn" data-mode="pivot"><i class="bi bi-grid-3x3"></i> Pivot</button>
            </div>
            <span class="arp-input-icon arp-tq"><i class="bi bi-search"></i><input type="search" class="arp-input" id="arpTq" placeholder="Caută în rezultate..."></span>
            <label class="arp-group-select"><span>Grupat după:</span>
              <select class="arp-input" id="arpGroup">
                <option value="none">Fără grupare</option>
                <option value="user">Utilizator</option>
                <option value="action">Tip acțiune</option>
                <option value="module">Modul</option>
                <option value="section">Secțiune</option>
                <option value="entity">Entitate</option>
                <option value="day">Zi</option>
              </select>
            </label>
          </div>
        </div>

        <div class="arp-tabs-bar">
          <div class="arp-tabs" id="arpTabs" role="tablist" aria-label="Module"></div>
          <div class="arp-dd arp-tabs-more" data-arp-dd>
            <button type="button" class="arp-tab arp-tab-more" data-arp-dd-toggle id="arpTabsMoreBtn">Mai multe <i class="bi bi-chevron-down"></i></button>
            <div class="arp-dd-menu arp-dd-menu-right" id="arpTabsMore" role="menu"></div>
          </div>
        </div>

        <div class="arp-report-body" id="arpReportBody">
          <div class="arp-table-wrap" id="arpTableWrap">
            <table class="arp-table" id="arpTable">
              <thead><tr id="arpThead"></tr></thead>
              <tbody id="arpTbody"></tbody>
            </table>
          </div>
          <div class="arp-chart-view" id="arpChartView" hidden><canvas id="arpResultsChart"></canvas></div>
          <div class="arp-pivot-view" id="arpPivotView" hidden></div>
          <div class="arp-loading" id="arpLoading" hidden><span class="arp-spinner"></span> Se încarcă…</div>
        </div>

        <footer class="arp-table-foot">
          <span id="arpShown">Afișate 0 din 0 rezultate</span>
          <div class="arp-pager-wrap">
            <select class="arp-input arp-input-sm" id="arpPerPage" aria-label="Rânduri pe pagină">
              <option value="10">10 pe pagină</option>
              <option value="25">25 pe pagină</option>
              <option value="50">50 pe pagină</option>
              <option value="100">100 pe pagină</option>
            </select>
            <nav class="arp-pager" id="arpPager" aria-label="Paginare"></nav>
          </div>
        </footer>
      </section>
    </div>

    <aside class="arp-aside">
      <section class="arp-card arp-side arp-side-pages">
        <div class="arp-side-head">
          <span class="arp-side-icon"><i class="bi bi-box"></i></span>
          <h2>Top pagini investigate</h2>
          <button type="button" class="arp-link" data-arp-showall="pages">Vezi toate</button>
        </div>
        <ol class="arp-rank" id="arpTopPages"></ol>
      </section>
      <section class="arp-card arp-side">
        <div class="arp-side-head">
          <i class="bi bi-calendar-week arp-side-glyph"></i>
          <h2>Top utilizatori în perioadă</h2>
          <button type="button" class="arp-link" data-arp-showall="users">Vezi toți</button>
        </div>
        <ol class="arp-rank arp-rank-users" id="arpTopUsers"></ol>
      </section>
      <section class="arp-card arp-side">
        <div class="arp-side-head">
          <i class="bi bi-ui-checks arp-side-glyph"></i>
          <h2>Modificări pe câmpuri</h2>
          <button type="button" class="arp-link" data-arp-showall="fields">Vezi toate</button>
        </div>
        <ul class="arp-fields" id="arpFieldStats"></ul>
      </section>
      <section class="arp-card arp-side">
        <div class="arp-side-head">
          <i class="bi bi-shield-check arp-side-glyph"></i>
          <h2>Audit securitate / risc</h2>
          <button type="button" class="arp-link" data-arp-showall="risk">Vezi detalii</button>
        </div>
        <div class="arp-risk" id="arpRisk"></div>
      </section>
    </aside>
  </div>

  <div class="arp-drawer-backdrop" id="arpBackdrop" hidden></div>
  <aside class="arp-drawer" id="arpDrawer" aria-hidden="true" aria-labelledby="arpDrawerTitle" role="dialog">
    <header class="arp-drawer-head">
      <h2 id="arpDrawerTitle">Detalii acțiune</h2>
      <button type="button" class="arp-icon-btn" data-arp-close aria-label="Închide"><i class="bi bi-x-lg"></i></button>
    </header>
    <div class="arp-drawer-body" id="arpDrawerBody"></div>
  </aside>

  <div class="arp-toast" id="arpToast" role="status" aria-live="polite" hidden></div>
</div>

<script type="application/json" id="arpBoot"><?= $bootJson ?: '{}' ?></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="<?= e(url('assets/js/rapoarte-activitate.js?v=' . (string) @filemtime($jsPath))) ?>"></script>
