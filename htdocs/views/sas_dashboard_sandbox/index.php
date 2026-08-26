<?php
$credentialsAvailable = !empty($credentialsAvailable);
$dataUrl = build_query_url(['page' => 'sas_dashboard_sandbox', 'action' => 'data']);
$vehicleUrl = build_query_url(['page' => 'sas_dashboard_sandbox', 'action' => 'vehicle']);
$routeUrl = build_query_url(['page' => 'sas_dashboard_sandbox', 'action' => 'route']);
?>

<?php if (!$credentialsAvailable): ?>
    <div class="alert alert-warning">
        Credentialele SAS nu sunt configurate. Completeaza <code>SAS_API_USERNAME</code> si
        <code>SAS_API_PASSWORD</code> in fisierul <code>.env</code>, apoi reincarca pagina.
    </div>
<?php else: ?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<div class="fdash">
    <!-- Bara de titlu -->
    <div class="fdash-topbar">
        <div class="fdash-title">
            <span class="fdash-live-dot" id="fdash-live-dot"></span>
            <div>
                <h1>Flota live</h1>
                <div class="fdash-subtitle">Dashboard SAS experimental &middot; doar citire</div>
            </div>
        </div>
        <div class="fdash-topbar-right">
            <div class="fdash-seg" id="fdash-mode">
                <button type="button" class="active" data-mode="live"><i class="bi bi-broadcast me-1"></i>Live</button>
                <button type="button" data-mode="history"><i class="bi bi-clock-history me-1"></i>Istoric</button>
            </div>
            <span class="fdash-updated" id="fdash-updated">se conecteaza...</span>
            <button type="button" class="fdash-btn" id="fdash-pause" title="Pauza / reia actualizarea automata">
                <i class="bi bi-pause-fill" id="fdash-pause-icon"></i><span id="fdash-countdown">30</span>s
            </button>
            <span class="fdash-sandbox-badge">SANDBOX</span>
        </div>
    </div>

    <!-- Controale istoric -->
    <div class="fdash-history-bar" id="fdash-history-bar" hidden>
        <span class="fdash-history-label"><i class="bi bi-calendar-range me-1"></i>Perioada:</span>
        <div class="fdash-seg" id="fdash-presets">
            <button type="button" data-preset="today">Azi</button>
            <button type="button" data-preset="yesterday">Ieri</button>
            <button type="button" class="active" data-preset="7d">7 zile</button>
            <button type="button" data-preset="month">Luna aceasta</button>
            <button type="button" data-preset="prev-month">Luna trecuta</button>
        </div>
        <input type="date" id="fdash-hist-start" class="fdash-date">
        <span class="fdash-muted">&rarr;</span>
        <input type="date" id="fdash-hist-end" class="fdash-date">
        <button type="button" class="fdash-btn fdash-btn-primary" id="fdash-hist-apply"><i class="bi bi-search me-1"></i>Aplica</button>
        <span class="fdash-muted small" id="fdash-hist-progress"></span>
    </div>

    <div class="fdash-alert" id="fdash-error" hidden></div>

    <!-- Banda KPI -->
    <div class="fdash-kpis">
        <button type="button" class="fdash-kpi fdash-kpi-filter" data-filter="moving">
            <span class="fdash-kpi-value fdash-c-green" id="kpi-moving">–</span>
            <span class="fdash-kpi-label"><i class="bi bi-truck me-1"></i>In miscare</span>
        </button>
        <button type="button" class="fdash-kpi fdash-kpi-filter" data-filter="idle">
            <span class="fdash-kpi-value fdash-c-amber" id="kpi-idle">–</span>
            <span class="fdash-kpi-label"><i class="bi bi-pause-circle me-1"></i>Oprite recent</span>
        </button>
        <button type="button" class="fdash-kpi fdash-kpi-filter" data-filter="parked">
            <span class="fdash-kpi-value fdash-c-slate" id="kpi-parked">–</span>
            <span class="fdash-kpi-label"><i class="bi bi-p-circle me-1"></i>Parcate</span>
        </button>
        <button type="button" class="fdash-kpi fdash-kpi-filter" data-filter="offline">
            <span class="fdash-kpi-value fdash-c-red" id="kpi-offline">–</span>
            <span class="fdash-kpi-label"><i class="bi bi-wifi-off me-1"></i>Offline &gt;24h</span>
        </button>
        <div class="fdash-kpi-sep"></div>
        <div class="fdash-kpi">
            <span class="fdash-kpi-value" id="kpi-fleet-km">–</span>
            <span class="fdash-kpi-label"><i class="bi bi-signpost-2 me-1"></i><span id="kpi-km-label">Km flota azi</span> <span id="kpi-coverage" class="fdash-kpi-note"></span></span>
        </div>
        <div class="fdash-kpi">
            <span class="fdash-kpi-value" id="kpi-fleet-fuel">–</span>
            <span class="fdash-kpi-label"><i class="bi bi-fuel-pump me-1"></i><span id="kpi-fuel-label">Motorina CAN azi</span></span>
        </div>
        <div class="fdash-kpi" id="kpi-top-speed-box">
            <span class="fdash-kpi-value" id="kpi-top-speed">–</span>
            <span class="fdash-kpi-label"><i class="bi bi-speedometer2 me-1"></i><span id="kpi-top-speed-label">Viteza maxima</span></span>
        </div>
    </div>

    <div class="row g-3">
        <!-- Tabelul flotei -->
        <div class="col-12 col-xxl-8">
            <div class="fdash-panel">
                <div class="fdash-panel-head">
                    <span class="fdash-panel-title"><i class="bi bi-truck-front me-1"></i>Vehicule <span class="fdash-count" id="fdash-count">0</span></span>
                    <div class="fdash-panel-tools">
                        <div class="fdash-seg" id="fdash-filters">
                            <button type="button" class="active" data-filter="all">Toate</button>
                            <button type="button" data-filter="moving">Miscare</button>
                            <button type="button" data-filter="idle">Oprite</button>
                            <button type="button" data-filter="parked">Parcate</button>
                            <button type="button" data-filter="offline">Offline</button>
                        </div>
                        <input type="search" id="fdash-search" placeholder="Cauta numar / sofer...">
                    </div>
                </div>
                <div class="fdash-tablewrap">
                    <table class="fdash-table">
                        <thead>
                            <tr>
                                <th><i class="bi bi-truck me-1"></i>Vehicul</th>
                                <th><i class="bi bi-person me-1"></i>Sofer</th>
                                <th class="ta-r"><i class="bi bi-speedometer2 me-1"></i>Viteza</th>
                                <th><i class="bi bi-geo-alt me-1"></i>Locatie</th>
                                <th class="ta-r"><i class="bi bi-signpost-2 me-1"></i><span id="th-day">Azi</span></th>
                                <th class="ta-r"><i class="bi bi-fuel-pump me-1"></i><span id="th-can">CAN azi</span></th>
                                <th class="ta-r"><i class="bi bi-speedometer me-1"></i>Odometru</th>
                                <th class="ta-r"><i class="bi bi-clock me-1"></i>Raport</th>
                            </tr>
                        </thead>
                        <tbody id="fdash-rows">
                            <tr><td colspan="8" class="fdash-empty">Se incarca pozitiile din SAS...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Feed activitate -->
        <div class="col-12 col-xxl-4">
            <div class="fdash-panel">
                <div class="fdash-panel-head">
                    <span class="fdash-panel-title"><i class="bi bi-lightning-charge me-1"></i>Activitate live</span>
                    <span class="fdash-muted small">porniri / opriri detectate</span>
                </div>
                <div class="fdash-feed" id="fdash-feed">
                    <div class="fdash-empty">Evenimentele apar cand un vehicul porneste sau se opreste.</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Detaliu vehicul -->
<div class="modal fade" id="fdash-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content fdash-modal">
            <div class="modal-header">
                <div class="fdash-modal-head">
                    <div class="fdash-modal-photo" id="fdash-modal-photo"><i class="bi bi-truck"></i></div>
                    <h5 class="modal-title" id="fdash-modal-title">Vehicul</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Inchide"></button>
            </div>
            <div class="modal-body">
                <!-- Perioada -->
                <div class="fdash-history-bar mb-3">
                    <span class="fdash-history-label"><i class="bi bi-calendar-range me-1"></i>Perioada:</span>
                    <div class="fdash-seg" id="fdash-modal-presets">
                        <button type="button" class="active" data-preset="today">Azi</button>
                        <button type="button" data-preset="yesterday">Ieri</button>
                        <button type="button" data-preset="7d">7 zile</button>
                        <button type="button" data-preset="month">Luna aceasta</button>
                        <button type="button" data-preset="prev-month">Luna trecuta</button>
                    </div>
                    <input type="date" id="fdash-modal-start" class="fdash-date">
                    <span class="fdash-muted">&rarr;</span>
                    <input type="date" id="fdash-modal-end" class="fdash-date">
                    <button type="button" class="fdash-btn fdash-btn-primary" id="fdash-modal-apply"><i class="bi bi-search me-1"></i>Aplica</button>
                </div>

                <div class="fdash-alert" id="fdash-modal-error" hidden></div>

                <!-- Harta + statistici -->
                <div class="fdash-detail-grid">
                    <div class="fdash-map-wrap">
                        <div id="fdash-map"></div>
                        <div class="fdash-map-note" id="fdash-map-note" hidden></div>
                    </div>
                    <div class="fdash-tiles fdash-tiles-side" id="fdash-modal-tiles">
                        <div class="fdash-empty" id="fdash-modal-loading">Se incarca din SAS...</div>
                    </div>
                </div>

                <div class="fdash-panel-title mb-2 mt-3"><i class="bi bi-signpost-2 me-1"></i>Segmente in perioada <span class="fdash-count" id="fdash-seg-count"></span></div>
                <div class="fdash-tablewrap" style="max-height: 34vh;">
                    <table class="fdash-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th><i class="bi bi-clock me-1"></i>Interval</th>
                                <th><i class="bi bi-geo me-1"></i>De la</th>
                                <th><i class="bi bi-geo-alt me-1"></i>Pana la</th>
                                <th class="ta-r">Km</th>
                                <th class="ta-r">V. medie</th>
                                <th class="ta-r">Ralanti</th>
                                <th class="ta-r">Odometru</th>
                            </tr>
                        </thead>
                        <tbody id="fdash-modal-segments"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .fdash, .fdash-modal {
        --bg: #f4f6fa;
        --panel: #ffffff;
        --panel-2: #f1f4f9;
        --line: #dfe5ef;
        --text: #1f2937;
        --muted: #64748b;
        --green: #198754;
        --amber: #d97706;
        --slate: #94a3b8;
        --red: #dc3545;
        --blue: #0d6efd;
    }
    .fdash {
        background: var(--bg);
        color: var(--text);
        border-radius: .9rem;
        padding: 1.1rem 1.2rem 1.2rem;
        font-variant-numeric: tabular-nums;
    }
    .fdash .fdash-muted, .fdash-modal .fdash-muted { color: var(--muted); }
    .fdash-c-green { color: var(--green); }
    .fdash-c-amber { color: var(--amber); }
    .fdash-c-slate { color: var(--slate); }
    .fdash-c-red { color: var(--red); }

    /* Topbar */
    .fdash-topbar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: .6rem; margin-bottom: 1rem; }
    .fdash-title { display: flex; align-items: center; gap: .7rem; }
    .fdash-title h1 { font-size: 1.15rem; font-weight: 700; margin: 0; letter-spacing: .01em; }
    .fdash-subtitle { color: var(--muted); font-size: .78rem; }
    .fdash-live-dot { width: .7rem; height: .7rem; border-radius: 50%; background: var(--green); animation: fdash-pulse 2s infinite; flex: none; }
    .fdash-live-dot.paused { background: var(--amber); animation: none; }
    .fdash-topbar-right { display: flex; align-items: center; gap: .6rem; flex-wrap: wrap; }
    .fdash-updated { color: var(--muted); font-size: .8rem; }
    .fdash-btn { background: var(--panel-2); color: var(--text); border: 1px solid var(--line); border-radius: .5rem; padding: .25rem .6rem; font-size: .8rem; }
    .fdash-btn:hover { border-color: var(--blue); }
    .fdash-btn-primary { background: rgba(13, 110, 253, .1); border-color: rgba(13, 110, 253, .45); color: var(--blue); }
    .fdash-sandbox-badge { background: #ffc107; color: #664d03; font-weight: 700; font-size: .7rem; letter-spacing: .06em; border-radius: .4rem; padding: .3rem .5rem; }

    .fdash-alert { background: #fdecec; border: 1px solid #f5c2c7; color: #842029; border-radius: .5rem; padding: .5rem .8rem; font-size: .85rem; margin-bottom: .8rem; }

    /* Bara istoric */
    .fdash-history-bar { display: flex; align-items: center; flex-wrap: wrap; gap: .5rem; background: var(--panel); border: 1px solid var(--line); border-radius: .7rem; padding: .55rem .8rem; margin-bottom: 1rem; }
    .fdash-modal .fdash-history-bar { margin-bottom: 0; }
    .fdash-history-label { font-size: .82rem; color: var(--muted); }
    .fdash-date { background: var(--panel); border: 1px solid var(--line); color: var(--text); border-radius: .5rem; font-size: .8rem; padding: .25rem .45rem; }
    .fdash-date:focus { outline: none; border-color: var(--blue); }

    /* KPI strip */
    .fdash-kpis { display: flex; flex-wrap: wrap; align-items: stretch; gap: .25rem 0; background: var(--panel); border: 1px solid var(--line); border-radius: .7rem; padding: .7rem .4rem; margin-bottom: 1rem; box-shadow: 0 1px 2px rgba(16, 24, 40, .05); }
    .fdash-kpi { flex: 1 1 0; min-width: 110px; display: flex; flex-direction: column; align-items: center; gap: .1rem; padding: .2rem .5rem; background: none; border: 0; border-radius: .5rem; color: inherit; }
    .fdash-kpi-value { font-size: 1.45rem; font-weight: 700; line-height: 1.15; }
    .fdash-kpi-label { color: var(--muted); font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; text-align: center; }
    .fdash-kpi-note { text-transform: none; letter-spacing: 0; }
    .fdash-kpi-sep { width: 1px; background: var(--line); margin: .2rem .3rem; }
    .fdash-kpi-filter { cursor: pointer; }
    .fdash-kpi-filter:hover { background: var(--panel-2); }
    .fdash-kpi-filter.active { background: var(--panel-2); box-shadow: inset 0 -2px 0 var(--blue); }

    /* Panouri */
    .fdash-panel { background: var(--panel); border: 1px solid var(--line); border-radius: .7rem; overflow: hidden; box-shadow: 0 1px 2px rgba(16, 24, 40, .05); }
    .fdash-panel-head { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: .5rem; padding: .65rem .9rem; border-bottom: 1px solid var(--line); }
    .fdash-panel-title { font-weight: 600; font-size: .9rem; }
    .fdash-count { color: var(--muted); font-weight: 400; font-size: .8rem; margin-left: .2rem; }
    .fdash-panel-tools { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
    .fdash-seg { display: flex; border: 1px solid var(--line); border-radius: .5rem; overflow: hidden; }
    .fdash-seg button { background: none; border: 0; color: var(--muted); font-size: .77rem; padding: .3rem .6rem; white-space: nowrap; }
    .fdash-seg button:hover { color: var(--text); }
    .fdash-seg button.active { background: var(--panel-2); color: var(--text); font-weight: 600; }
    .fdash-panel-tools input[type="search"] { background: var(--panel-2); border: 1px solid var(--line); color: var(--text); border-radius: .5rem; font-size: .8rem; padding: .3rem .6rem; width: 175px; }
    .fdash-panel-tools input[type="search"]::placeholder { color: var(--muted); }
    .fdash-panel-tools input[type="search"]:focus { outline: none; border-color: var(--blue); }

    /* Tabel */
    .fdash-tablewrap { overflow: auto; max-height: 62vh; }
    .fdash-table { width: 100%; border-collapse: collapse; font-size: .84rem; }
    .fdash-table th { position: sticky; top: 0; background: var(--panel-2); color: var(--muted); font-size: .7rem; text-transform: uppercase; letter-spacing: .06em; font-weight: 600; text-align: left; padding: .5rem .7rem; white-space: nowrap; z-index: 1; }
    .fdash-table td { padding: .48rem .7rem; border-top: 1px solid var(--line); white-space: nowrap; vertical-align: middle; }
    .fdash-table .ta-r { text-align: right; }
    .fdash-row { cursor: pointer; }
    .fdash-row:hover td { background: var(--panel-2); }
    .fdash-empty { color: var(--muted); text-align: center; padding: 1.6rem .8rem; font-size: .85rem; }

    .fdash-veh-cell { display: flex; align-items: center; gap: .55rem; }
    .fdash-thumb { width: 34px; height: 34px; border-radius: .45rem; object-fit: cover; border: 1px solid var(--line); flex: none; }
    .fdash-thumb-fallback { width: 34px; height: 34px; border-radius: .45rem; border: 1px solid var(--line); background: var(--panel-2); color: var(--muted); display: flex; align-items: center; justify-content: center; font-size: 1rem; flex: none; }
    .fdash-plate { font-weight: 700; letter-spacing: .02em; }
    .fdash-model { color: var(--muted); font-size: .72rem; }
    .fdash-sub { color: var(--muted); font-size: .74rem; }
    .fdash-pill { display: inline-flex; align-items: center; gap: .35rem; font-size: .72rem; border-radius: 99px; padding: .1rem .5rem .1rem .35rem; border: 1px solid var(--line); color: var(--muted); }
    .fdash-pill::before { content: ''; width: .5rem; height: .5rem; border-radius: 50%; background: var(--slate); }
    .fdash-pill.moving { color: var(--green); border-color: rgba(25, 135, 84, .35); background: rgba(25, 135, 84, .07); }
    .fdash-pill.moving::before { background: var(--green); animation: fdash-pulse 2s infinite; }
    .fdash-pill.idle { color: var(--amber); border-color: rgba(217, 119, 6, .35); background: rgba(217, 119, 6, .07); }
    .fdash-pill.idle::before { background: var(--amber); }
    .fdash-pill.offline { color: var(--red); border-color: rgba(220, 53, 69, .35); background: rgba(220, 53, 69, .06); }
    .fdash-pill.offline::before { background: var(--red); }
    .fdash-speed { font-weight: 700; }
    .fdash-poi { color: var(--blue); }
    .fdash-l100 { display: inline-block; background: var(--panel-2); border: 1px solid var(--line); color: var(--muted); border-radius: .35rem; font-size: .68rem; padding: 0 .3rem; margin-left: .35rem; }
    .fdash-pending { color: var(--muted); opacity: .55; }

    tr.fdash-flash > td { animation: fdash-flashrow 2.5s ease-out; }

    /* Feed */
    .fdash-feed { overflow: auto; max-height: 62vh; }
    .fdash-feed-item { display: flex; justify-content: space-between; gap: .6rem; padding: .55rem .9rem; border-top: 1px solid var(--line); border-left: 3px solid transparent; font-size: .84rem; }
    .fdash-feed-item:first-child { border-top: 0; }
    .fdash-feed-item.started_moving { border-left-color: var(--green); }
    .fdash-feed-item.stopped { border-left-color: var(--amber); }
    .fdash-feed-item.went_offline { border-left-color: var(--red); }
    .fdash-feed-item.back_online { border-left-color: var(--blue); }
    .fdash-feed-item.fdash-feed-new { animation: fdash-flashrow 2.5s ease-out; }
    .fdash-feed-time { color: var(--muted); font-size: .75rem; white-space: nowrap; }

    /* Modal */
    .fdash-modal { background: var(--panel); color: var(--text); border: 1px solid var(--line); font-variant-numeric: tabular-nums; }
    .fdash-modal .modal-header { border-bottom-color: var(--line); }
    .fdash-modal .modal-title { font-size: 1rem; }
    .fdash-modal-head { display: flex; align-items: center; gap: .7rem; min-width: 0; }
    .fdash-modal-photo { width: 46px; height: 46px; border-radius: .55rem; border: 1px solid var(--line); background: var(--panel-2); color: var(--muted); display: flex; align-items: center; justify-content: center; font-size: 1.3rem; overflow: hidden; flex: none; }
    .fdash-modal-photo img { width: 100%; height: 100%; object-fit: cover; }

    .fdash-detail-grid { display: grid; grid-template-columns: minmax(0, 1.6fr) minmax(230px, 1fr); gap: .8rem; }
    @media (max-width: 900px) { .fdash-detail-grid { grid-template-columns: 1fr; } }
    .fdash-map-wrap { position: relative; border: 1px solid var(--line); border-radius: .6rem; overflow: hidden; min-height: 340px; }
    #fdash-map { position: absolute; inset: 0; background: #e9edf3; }
    .fdash-map-note { position: absolute; inset: auto .6rem .6rem .6rem; z-index: 500; background: rgba(255, 255, 255, .94); border: 1px solid var(--line); border-radius: .5rem; color: var(--muted); font-size: .8rem; padding: .45rem .7rem; text-align: center; }
    .fdash-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: .6rem; }
    .fdash-tiles-side { grid-template-columns: repeat(2, 1fr); align-content: start; }
    .fdash-tile { background: var(--panel-2); border: 1px solid var(--line); border-radius: .6rem; padding: .55rem .7rem; text-align: center; }
    .fdash-tile-value { font-size: 1.1rem; font-weight: 700; }
    .fdash-tile-label { color: var(--muted); font-size: .68rem; text-transform: uppercase; letter-spacing: .05em; }

    /* Leaflet pe tema deschisa */
    .fdash-map-wrap .leaflet-control-attribution { background: rgba(255, 255, 255, .85); color: var(--muted); }
    .fdash-map-wrap .leaflet-control-attribution a { color: var(--blue); }
    .fdash-map-wrap .leaflet-control-zoom a { background: var(--panel); color: var(--text); border-color: var(--line); }

    @keyframes fdash-pulse {
        0% { box-shadow: 0 0 0 0 rgba(25, 135, 84, .45); }
        70% { box-shadow: 0 0 0 .45rem rgba(25, 135, 84, 0); }
        100% { box-shadow: 0 0 0 0 rgba(25, 135, 84, 0); }
    }
    @keyframes fdash-flashrow {
        0% { background-color: rgba(13, 110, 253, .14); }
        100% { background-color: transparent; }
    }
</style>

<script>
(function () {
    'use strict';

    var DATA_URL = <?= json_encode($dataUrl, JSON_UNESCAPED_SLASHES) ?>;
    var VEHICLE_URL = <?= json_encode($vehicleUrl, JSON_UNESCAPED_SLASHES) ?>;
    var ROUTE_URL = <?= json_encode($routeUrl, JSON_UNESCAPED_SLASHES) ?>;
    var REFRESH_SECONDS = 30;
    var NF = new Intl.NumberFormat('ro-RO');

    var state = {
        vehicles: [],
        feed: [],
        filter: 'all',
        search: '',
        paused: false,
        countdown: REFRESH_SECONDS,
        prevStatuses: {},
        feedSeen: {},
        loading: false,
        mode: 'live',
        histStart: null,
        histEnd: null,
        history: {},
        histToken: 0,
        histDone: 0,
        histTotal: 0,
        // URL-uri de poze care au dat 404 (fisier lipsa local) -> fallback la icon,
        // ca sa nu se re-ceara imaginea lipsa la fiecare refresh de tabel.
        photoFailed: {}
    };

    var modal = {
        carId: null,
        start: null,
        end: null,
        map: null,
        routeLayer: null,
        markers: []
    };

    var STATUS_META = {
        moving: { label: 'Miscare', pill: 'moving' },
        idle: { label: 'Oprit', pill: 'idle' },
        parked: { label: 'Parcat', pill: 'parked' },
        offline: { label: 'Offline', pill: 'offline' }
    };

    var FEED_META = {
        started_moving: { icon: 'bi-play-circle-fill fdash-c-green', label: 'a pornit' },
        stopped: { icon: 'bi-stop-circle-fill fdash-c-amber', label: 's-a oprit' },
        went_offline: { icon: 'bi-wifi-off fdash-c-red', label: 'a iesit offline' },
        back_online: { icon: 'bi-wifi', label: 'a revenit online' }
    };

    function $(id) { return document.getElementById(id); }

    function esc(value) {
        var div = document.createElement('div');
        div.textContent = value === null || value === undefined ? '' : String(value);
        return div.innerHTML;
    }

    function ymd(date) {
        var y = date.getFullYear();
        var m = ('0' + (date.getMonth() + 1)).slice(-2);
        var d = ('0' + date.getDate()).slice(-2);
        return y + '-' + m + '-' + d;
    }

    function presetRange(preset) {
        var now = new Date();
        var today = ymd(now);
        if (preset === 'today') { return [today, today]; }
        if (preset === 'yesterday') {
            var y = new Date(now.getTime() - 86400000);
            return [ymd(y), ymd(y)];
        }
        if (preset === '7d') {
            var s = new Date(now.getTime() - 6 * 86400000);
            return [ymd(s), today];
        }
        if (preset === 'month') {
            return [ymd(new Date(now.getFullYear(), now.getMonth(), 1)), today];
        }
        if (preset === 'prev-month') {
            var first = new Date(now.getFullYear(), now.getMonth() - 1, 1);
            var last = new Date(now.getFullYear(), now.getMonth(), 0);
            return [ymd(first), ymd(last)];
        }
        return [today, today];
    }

    function rangeLabel(start, end) {
        return start === end ? start : start + ' → ' + end;
    }

    function agoLabel(seconds) {
        if (seconds === null || seconds === undefined) { return '-'; }
        if (seconds < 90) { return seconds + 's'; }
        if (seconds < 5400) { return Math.round(seconds / 60) + ' min'; }
        if (seconds < 172800) { return Math.round(seconds / 3600) + ' h'; }
        return Math.round(seconds / 86400) + ' zile';
    }

    function timeLabel(iso) {
        if (!iso) { return '-'; }
        var d = new Date(iso);
        return isNaN(d.getTime()) ? '-' : d.toLocaleTimeString('ro-RO', { hour: '2-digit', minute: '2-digit' });
    }

    function dateTimeLabel(iso, withDate) {
        if (!iso) { return '-'; }
        var d = new Date(iso);
        if (isNaN(d.getTime())) { return '-'; }
        var t = d.toLocaleTimeString('ro-RO', { hour: '2-digit', minute: '2-digit' });
        if (!withDate) { return t; }
        return ('0' + d.getDate()).slice(-2) + '.' + ('0' + (d.getMonth() + 1)).slice(-2) + ' ' + t;
    }

    function durationLabel(seconds) {
        if (seconds === null || seconds === undefined) { return '-'; }
        var h = Math.floor(seconds / 3600);
        var m = Math.round((seconds % 3600) / 60);
        return h > 0 ? h + 'h ' + m + 'm' : m + 'm';
    }

    // ------- Randare -------

    function renderKpis(kpis) {
        $('kpi-moving').textContent = kpis.moving;
        $('kpi-idle').textContent = kpis.idle;
        $('kpi-parked').textContent = kpis.parked;
        $('kpi-offline').textContent = kpis.offline;

        if (state.mode === 'history') {
            var sumKm = 0, sumFuel = 0, loaded = 0;
            Object.keys(state.history).forEach(function (id) {
                var h = state.history[id];
                if (h && h.total_km !== null) { sumKm += h.total_km; loaded++; }
                if (h && h.can_fuel_l !== null) { sumFuel += h.can_fuel_l; }
            });
            $('kpi-fleet-km').textContent = NF.format(Math.round(sumKm));
            $('kpi-fleet-fuel').textContent = NF.format(Math.round(sumFuel)) + ' L';
            $('kpi-km-label').textContent = 'Km perioada';
            $('kpi-fuel-label').textContent = 'Motorina CAN perioada';
            $('kpi-coverage').textContent = loaded < state.histTotal ? '(' + loaded + '/' + state.histTotal + ')' : '';
            $('kpi-top-speed').textContent = '–';
            $('kpi-top-speed-label').textContent = 'Viteza maxima';
        } else {
            $('kpi-fleet-km').textContent = kpis.fleet_day_km !== undefined ? NF.format(kpis.fleet_day_km) : '–';
            $('kpi-fleet-fuel').textContent = kpis.fleet_can_fuel_l !== undefined ? NF.format(kpis.fleet_can_fuel_l) + ' L' : '–';
            $('kpi-km-label').textContent = 'Km flota azi';
            $('kpi-fuel-label').textContent = 'Motorina CAN azi';
            var coverage = $('kpi-coverage');
            if (kpis.stats_covered !== undefined && kpis.stats_covered < kpis.total) {
                coverage.textContent = '(' + kpis.stats_covered + '/' + kpis.total + ')';
                coverage.title = 'Statisticile de zi se incarca esalonat, cateva vehicule per refresh.';
            } else {
                coverage.textContent = '';
            }
            if (kpis.top_speed) {
                $('kpi-top-speed').textContent = kpis.top_speed.speed + ' km/h';
                $('kpi-top-speed-label').textContent = kpis.top_speed.registration;
            } else {
                $('kpi-top-speed').textContent = '–';
                $('kpi-top-speed-label').textContent = 'Viteza maxima';
            }
        }
    }

    function matchesFilter(vehicle) {
        if (state.filter !== 'all' && vehicle.status !== state.filter) { return false; }
        if (state.search !== '') {
            var haystack = ((vehicle.registration || '') + ' ' + (vehicle.driver || '') + ' ' + (vehicle.local_label || '')).toLowerCase();
            if (haystack.indexOf(state.search) === -1) { return false; }
        }
        return true;
    }

    function statValues(vehicle) {
        if (state.mode === 'history') {
            var h = state.history[vehicle.sas_vehicle_id];
            if (h === undefined) { return { pending: true }; }
            if (h === null) { return { pending: false, km: null, fuel: null, l100: null, hasCan: vehicle.has_can, odo: null }; }
            return { pending: false, km: h.total_km, fuel: h.can_fuel_l, l100: h.can_l100, hasCan: h.has_can, odo: h.odometer_km };
        }
        return { pending: vehicle.day_km === null || vehicle.day_km === undefined, km: vehicle.day_km, fuel: vehicle.can_fuel_l, l100: vehicle.can_l100, hasCan: vehicle.has_can, odo: vehicle.odometer_km };
    }

    function canCellHtml(s) {
        if (s.pending) { return '<span class="fdash-pending">...</span>'; }
        if (s.hasCan === false) { return '<span class="fdash-pending" title="Vehicul fara CAN">fara CAN</span>'; }
        if (s.fuel === null || s.fuel === undefined) { return '<span class="fdash-pending">-</span>'; }
        var html = NF.format(Math.round(s.fuel)) + ' L';
        if (s.l100 !== null && s.l100 !== undefined) {
            html += '<span class="fdash-l100">' + s.l100 + ' L/100</span>';
        }
        return html;
    }

    function renderVehicles(changedIds) {
        var visible = state.vehicles.filter(matchesFilter);
        $('fdash-count').textContent = visible.length + ' / ' + state.vehicles.length;

        if (visible.length === 0) {
            $('fdash-rows').innerHTML = '<tr><td colspan="8" class="fdash-empty">Niciun vehicul pentru filtrul curent.</td></tr>';
            return;
        }

        var rows = visible.map(function (vehicle) {
            var meta = STATUS_META[vehicle.status] || STATUS_META.parked;
            var flash = changedIds && changedIds[vehicle.sas_vehicle_id] ? ' fdash-flash' : '';
            var speed = vehicle.status === 'moving'
                ? '<span class="fdash-speed">' + Math.round(vehicle.speed || 0) + '</span> <span class="fdash-sub">km/h</span>'
                : '<span class="fdash-pending">-</span>';
            var place = esc(vehicle.place || '-');
            if (vehicle.poi) { place = '<i class="bi bi-geo-alt-fill fdash-poi me-1" title="Punct de lucru SAS"></i><span class="fdash-poi">' + place + '</span>'; }
            var thumb = vehicle.photo_url && !state.photoFailed[vehicle.photo_url]
                ? '<img class="fdash-thumb" src="' + esc(vehicle.photo_url) + '" alt="" loading="lazy">'
                : '<span class="fdash-thumb-fallback"><i class="bi bi-truck"></i></span>';
            var s = statValues(vehicle);
            var km = s.pending
                ? '<span class="fdash-pending" title="Se incarca...">...</span>'
                : (s.km === null || s.km === undefined ? '<span class="fdash-pending">-</span>' : NF.format(Math.round(s.km)) + ' <span class="fdash-sub">km</span>');
            var odometer = s.pending
                ? '<span class="fdash-pending">...</span>'
                : (s.odo === null || s.odo === undefined ? '<span class="fdash-pending">-</span>' : NF.format(s.odo) + ' <span class="fdash-sub">km</span>');
            return '<tr class="fdash-row' + flash + '" data-car-id="' + vehicle.sas_vehicle_id + '">'
                + '<td><div class="fdash-veh-cell">' + thumb + '<div>'
                + '<div class="fdash-plate">' + esc(vehicle.registration) + '</div>'
                + '<div class="fdash-model">' + esc(vehicle.local_label || '') + ' <span class="fdash-pill ' + meta.pill + '">' + meta.label + '</span></div>'
                + '</div></div></td>'
                + '<td class="fdash-sub">' + esc(vehicle.driver || '-') + '</td>'
                + '<td class="ta-r">' + speed + '</td>'
                + '<td class="fdash-sub" style="white-space: normal; min-width: 150px;">' + place + '</td>'
                + '<td class="ta-r">' + km + '</td>'
                + '<td class="ta-r">' + canCellHtml(s) + '</td>'
                + '<td class="ta-r">' + odometer + '</td>'
                + '<td class="ta-r fdash-sub" title="' + esc(vehicle.timestamp || '') + '">' + agoLabel(vehicle.age_seconds) + '</td>'
                + '</tr>';
        });

        $('fdash-rows').innerHTML = rows.join('');
    }

    function renderFeed() {
        var container = $('fdash-feed');
        if (!state.feed.length) {
            container.innerHTML = '<div class="fdash-empty">Nicio pornire/oprire detectata inca.<br>Evenimentele apar comparand doua interogari consecutive.</div>';
            return;
        }

        container.innerHTML = state.feed.map(function (entry) {
            var meta = FEED_META[entry.type] || { icon: 'bi-question-circle', label: entry.type };
            var key = entry.at + '|' + entry.sas_vehicle_id + '|' + entry.type;
            var isNew = !state.feedSeen[key];
            state.feedSeen[key] = true;
            var detail = [];
            if (entry.place) { detail.push(esc(entry.place)); }
            if (entry.type === 'started_moving' && entry.speed) { detail.push(entry.speed + ' km/h'); }
            return '<div class="fdash-feed-item ' + entry.type + (isNew ? ' fdash-feed-new' : '') + '">'
                + '<div><i class="bi ' + meta.icon + ' me-2"></i><strong>' + esc(entry.registration) + '</strong> ' + meta.label
                + (entry.driver ? ' <span class="fdash-sub">(' + esc(entry.driver) + ')</span>' : '')
                + (detail.length ? '<div class="fdash-sub" style="margin-left: 1.55rem;">' + detail.join(' &middot; ') + '</div>' : '')
                + '</div>'
                + '<span class="fdash-feed-time">' + timeLabel(entry.at) + '</span>'
                + '</div>';
        }).join('');
    }

    var lastKpis = {};

    function applyData(data) {
        var changedIds = {};
        (data.vehicles || []).forEach(function (vehicle) {
            var prev = state.prevStatuses[vehicle.sas_vehicle_id];
            if (prev && prev !== vehicle.status) { changedIds[vehicle.sas_vehicle_id] = true; }
            state.prevStatuses[vehicle.sas_vehicle_id] = vehicle.status;
        });

        state.vehicles = data.vehicles || [];
        state.feed = data.feed || [];
        lastKpis = data.kpis || {};

        renderKpis(lastKpis);
        renderVehicles(changedIds);
        renderFeed();

        var meta = data.meta || {};
        var label = 'actualizat ' + timeLabel(meta.generated_at);
        if (meta.from_cache) { label += ' (cache)'; }
        $('fdash-updated').textContent = label;

        var errorBox = $('fdash-error');
        if (meta.error) {
            errorBox.textContent = meta.error;
            errorBox.hidden = false;
        } else {
            errorBox.hidden = true;
        }
    }

    function refresh() {
        if (state.loading) { return; }
        state.loading = true;
        fetch(DATA_URL, { headers: { 'Accept': 'application/json' } })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data.error && !data.vehicles) { throw new Error(data.error); }
                applyData(data);
            })
            .catch(function (error) {
                var errorBox = $('fdash-error');
                errorBox.textContent = 'Actualizarea a esuat: ' + error.message;
                errorBox.hidden = false;
            })
            .finally(function () {
                state.loading = false;
                state.countdown = REFRESH_SECONDS;
            });
    }

    setInterval(function () {
        if (state.paused || state.mode === 'history') { return; }
        state.countdown -= 1;
        if (state.countdown <= 0) { refresh(); }
        $('fdash-countdown').textContent = Math.max(0, state.countdown);
    }, 1000);

    $('fdash-pause').addEventListener('click', function () {
        state.paused = !state.paused;
        $('fdash-pause-icon').className = state.paused ? 'bi bi-play-fill' : 'bi bi-pause-fill';
        $('fdash-live-dot').classList.toggle('paused', state.paused);
    });

    // ------- Filtre status -------

    function setFilter(filter) {
        state.filter = state.filter === filter ? 'all' : filter;
        document.querySelectorAll('#fdash-filters button').forEach(function (button) {
            button.classList.toggle('active', button.getAttribute('data-filter') === state.filter);
        });
        document.querySelectorAll('.fdash-kpi-filter').forEach(function (button) {
            button.classList.toggle('active', button.getAttribute('data-filter') === state.filter);
        });
        renderVehicles();
    }
    document.querySelectorAll('#fdash-filters button').forEach(function (button) {
        button.addEventListener('click', function () {
            state.filter = 'all';
            setFilter(button.getAttribute('data-filter') === 'all' ? 'all' : button.getAttribute('data-filter'));
        });
    });
    document.querySelectorAll('.fdash-kpi-filter').forEach(function (button) {
        button.addEventListener('click', function () { setFilter(button.getAttribute('data-filter')); });
    });

    $('fdash-search').addEventListener('input', function () {
        state.search = this.value.trim().toLowerCase();
        renderVehicles();
    });

    // ------- Mod istoric flota -------

    function setMode(mode) {
        state.mode = mode;
        document.querySelectorAll('#fdash-mode button').forEach(function (button) {
            button.classList.toggle('active', button.getAttribute('data-mode') === mode);
        });
        $('fdash-history-bar').hidden = mode !== 'history';
        $('fdash-live-dot').classList.toggle('paused', mode === 'history');
        $('th-day').textContent = mode === 'history' ? 'Perioada' : 'Azi';
        $('th-can').textContent = mode === 'history' ? 'CAN perioada' : 'CAN azi';
        if (mode === 'history') {
            if (!state.histStart) {
                var range = presetRange('7d');
                state.histStart = range[0];
                state.histEnd = range[1];
                $('fdash-hist-start').value = range[0];
                $('fdash-hist-end').value = range[1];
            }
            loadHistory();
        } else {
            state.histToken++;
            $('fdash-hist-progress').textContent = '';
            renderKpis(lastKpis);
            renderVehicles();
        }
    }
    document.querySelectorAll('#fdash-mode button').forEach(function (button) {
        button.addEventListener('click', function () { setMode(button.getAttribute('data-mode')); });
    });

    document.querySelectorAll('#fdash-presets button').forEach(function (button) {
        button.addEventListener('click', function () {
            document.querySelectorAll('#fdash-presets button').forEach(function (b) { b.classList.remove('active'); });
            button.classList.add('active');
            var range = presetRange(button.getAttribute('data-preset'));
            $('fdash-hist-start').value = range[0];
            $('fdash-hist-end').value = range[1];
            state.histStart = range[0];
            state.histEnd = range[1];
            loadHistory();
        });
    });
    $('fdash-hist-apply').addEventListener('click', function () {
        var start = $('fdash-hist-start').value;
        var end = $('fdash-hist-end').value;
        if (!start) { return; }
        document.querySelectorAll('#fdash-presets button').forEach(function (b) { b.classList.remove('active'); });
        state.histStart = start;
        state.histEnd = end || start;
        loadHistory();
    });

    function loadHistory() {
        var token = ++state.histToken;
        state.history = {};
        state.histDone = 0;
        state.histTotal = state.vehicles.length;
        renderVehicles();
        renderKpis(lastKpis);

        var queue = state.vehicles.map(function (v) { return v.sas_vehicle_id; });
        var progress = $('fdash-hist-progress');
        progress.textContent = 'se incarca 0 / ' + state.histTotal + '...';

        function worker() {
            if (token !== state.histToken) { return; }
            var carId = queue.shift();
            if (carId === undefined) { return; }
            fetch(VEHICLE_URL + '&car_id=' + carId + '&start=' + state.histStart + '&end=' + state.histEnd, { headers: { 'Accept': 'application/json' } })
                .then(function (response) { return response.json(); })
                .then(function (day) {
                    if (token !== state.histToken) { return; }
                    state.history[carId] = day.error ? null : day;
                })
                .catch(function () {
                    if (token === state.histToken) { state.history[carId] = null; }
                })
                .finally(function () {
                    if (token !== state.histToken) { return; }
                    state.histDone++;
                    progress.textContent = state.histDone < state.histTotal
                        ? 'se incarca ' + state.histDone + ' / ' + state.histTotal + '...'
                        : 'perioada ' + rangeLabel(state.histStart, state.histEnd) + ' incarcata';
                    renderVehicles();
                    renderKpis(lastKpis);
                    worker();
                });
        }
        worker();
        worker();
    }

    // ------- Detaliu vehicul (modal cu harta) -------

    $('fdash-rows').addEventListener('click', function (event) {
        var row = event.target.closest('tr.fdash-row');
        if (!row) { return; }
        openVehicle(parseInt(row.getAttribute('data-car-id'), 10));
    });

    // Pozele lipsa (404) se inlocuiesc cu iconul de camion si nu se mai recer.
    $('fdash-rows').addEventListener('error', function (event) {
        var img = event.target;
        if (!img || !img.classList || !img.classList.contains('fdash-thumb')) { return; }
        state.photoFailed[img.getAttribute('src')] = true;
        var fallback = document.createElement('span');
        fallback.className = 'fdash-thumb-fallback';
        fallback.innerHTML = '<i class="bi bi-truck"></i>';
        img.replaceWith(fallback);
    }, true);

    var modalEl = $('fdash-modal');
    modalEl.addEventListener('shown.bs.modal', function () {
        ensureMap();
        if (modal.map) { modal.map.invalidateSize(); }
    });

    function ensureMap() {
        if (modal.map || typeof L === 'undefined') { return; }
        modal.map = L.map('fdash-map', {
            zoomControl: true,
            attributionControl: true,
            // Traseele au pana la 3000 de puncte: pe canvas redesenarea la zoom e
            // mult mai rapida decat pe SVG (implicit), altfel zoom-ul se blocheaza.
            preferCanvas: true,
            // Zoom imediat si controlabil din rotita: fara debounce lung si cu
            // pasi mai mici, ca sa nu "sara" harta peste nivelul dorit.
            wheelDebounceTime: 15,
            wheelPxPerZoomLevel: 90,
            zoomSnap: 0.5,
            zoomDelta: 0.5,
            // Fara fade-in la tile-uri: apar instant in loc sa clipeasca gri.
            fadeAnimation: false
        });
        // Acelasi server de tile-uri ca pagina Harta Flota (CARTO e blocat in unele retele).
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            // Buffer mai mare de tile-uri in jurul ecranului: harta ramane
            // "plina" in timpul zoom/pan in loc sa ramana gri.
            keepBuffer: 6,
            updateWhenIdle: false,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
        }).addTo(modal.map);
        modal.map.setView([45.9432, 24.9668], 6);
    }

    function clearMapLayers() {
        if (!modal.map) { return; }
        if (modal.routeLayer) { modal.map.removeLayer(modal.routeLayer); modal.routeLayer = null; }
        modal.markers.forEach(function (m) { modal.map.removeLayer(m); });
        modal.markers = [];
    }

    function daysBetween(start, end) {
        return Math.round((new Date(end) - new Date(start)) / 86400000) + 1;
    }

    function openVehicle(carId) {
        modal.carId = carId;
        if (state.mode === 'history' && state.histStart) {
            modal.start = state.histStart;
            modal.end = state.histEnd;
            document.querySelectorAll('#fdash-modal-presets button').forEach(function (b) { b.classList.remove('active'); });
        } else {
            var range = presetRange('today');
            modal.start = range[0];
            modal.end = range[1];
            document.querySelectorAll('#fdash-modal-presets button').forEach(function (b) {
                b.classList.toggle('active', b.getAttribute('data-preset') === 'today');
            });
        }
        $('fdash-modal-start').value = modal.start;
        $('fdash-modal-end').value = modal.end;

        var vehicle = state.vehicles.find(function (v) { return v.sas_vehicle_id === carId; });
        $('fdash-modal-title').innerHTML = esc(vehicle ? vehicle.registration : carId)
            + (vehicle && vehicle.local_label ? ' <span class="fdash-sub">' + esc(vehicle.local_label) + '</span>' : '')
            + (vehicle && vehicle.driver ? ' <span class="fdash-sub">&middot; <i class="bi bi-person"></i> ' + esc(vehicle.driver) + '</span>' : '');
        var photoBox = $('fdash-modal-photo');
        if (vehicle && vehicle.photo_url && !state.photoFailed[vehicle.photo_url]) {
            photoBox.innerHTML = '<img src="' + esc(vehicle.photo_url) + '" alt="' + esc(vehicle.registration) + '">';
            photoBox.querySelector('img').addEventListener('error', function () {
                state.photoFailed[vehicle.photo_url] = true;
                photoBox.innerHTML = '<i class="bi bi-truck"></i>';
            });
        } else {
            photoBox.innerHTML = '<i class="bi bi-truck"></i>';
        }

        bootstrap.Modal.getOrCreateInstance(modalEl).show();
        loadVehicleDetail();
    }

    document.querySelectorAll('#fdash-modal-presets button').forEach(function (button) {
        button.addEventListener('click', function () {
            document.querySelectorAll('#fdash-modal-presets button').forEach(function (b) { b.classList.remove('active'); });
            button.classList.add('active');
            var range = presetRange(button.getAttribute('data-preset'));
            modal.start = range[0];
            modal.end = range[1];
            $('fdash-modal-start').value = range[0];
            $('fdash-modal-end').value = range[1];
            loadVehicleDetail();
        });
    });
    $('fdash-modal-apply').addEventListener('click', function () {
        var start = $('fdash-modal-start').value;
        var end = $('fdash-modal-end').value;
        if (!start) { return; }
        document.querySelectorAll('#fdash-modal-presets button').forEach(function (b) { b.classList.remove('active'); });
        modal.start = start;
        modal.end = end || start;
        loadVehicleDetail();
    });

    function loadVehicleDetail() {
        $('fdash-modal-error').hidden = true;
        $('fdash-modal-tiles').innerHTML = '<div class="fdash-empty">Se incarca din SAS...</div>';
        $('fdash-modal-segments').innerHTML = '';
        $('fdash-seg-count').textContent = '';

        var query = '&car_id=' + modal.carId + '&start=' + modal.start + '&end=' + modal.end;

        fetch(VEHICLE_URL + query, { headers: { 'Accept': 'application/json' } })
            .then(function (response) { return response.json(); })
            .then(function (day) {
                if (day.error) { throw new Error(day.error); }
                renderVehicleDetail(day);
            })
            .catch(function (error) {
                $('fdash-modal-tiles').innerHTML = '';
                var box = $('fdash-modal-error');
                box.textContent = error.message;
                box.hidden = false;
            });

        loadRoute(query);
    }

    function loadRoute(query) {
        clearMapLayers();
        var note = $('fdash-map-note');
        var days = daysBetween(modal.start, modal.end);
        if (days > 7) {
            note.textContent = 'Traseul se afiseaza doar pentru perioade de maxim 7 zile (limita SAS). Statisticile raman valabile.';
            note.hidden = false;
            return;
        }
        note.textContent = 'Se incarca traseul...';
        note.hidden = false;

        fetch(ROUTE_URL + query, { headers: { 'Accept': 'application/json' } })
            .then(function (response) { return response.json(); })
            .then(function (route) {
                if (route.error) { throw new Error(route.error); }
                drawRoute(route);
            })
            .catch(function (error) {
                note.textContent = 'Traseul nu a putut fi incarcat: ' + error.message;
                note.hidden = false;
            });
    }

    function drawRoute(route) {
        ensureMap();
        if (!modal.map) { return; }
        clearMapLayers();
        var note = $('fdash-map-note');
        var points = route.points || [];

        // Marker cu pozitia live cand perioada include ziua curenta.
        var vehicle = state.vehicles.find(function (v) { return v.sas_vehicle_id === modal.carId; });
        var includesToday = modal.end >= ymd(new Date());
        var liveMarker = null;
        if (includesToday && vehicle && vehicle.latitude && vehicle.longitude) {
            liveMarker = L.circleMarker([vehicle.latitude, vehicle.longitude], {
                radius: 8, color: '#198754', weight: 2, fillColor: '#198754', fillOpacity: .6
            }).bindTooltip('Pozitia curenta' + (vehicle.place ? ': ' + vehicle.place : ''));
            modal.markers.push(liveMarker);
        }

        if (!points.length) {
            if (liveMarker) {
                liveMarker.addTo(modal.map);
                modal.map.setView(liveMarker.getLatLng(), 12);
                note.textContent = 'Fara traseu in perioada selectata; se arata pozitia curenta.';
            } else {
                note.textContent = 'Fara traseu in perioada selectata.';
            }
            note.hidden = false;
            return;
        }

        var latlngs = points.map(function (p) { return [p.lat, p.lng]; });
        var matched = route.matched && route.matched.length ? route.matched : null;
        var layers = [];

        if (matched) {
            // Traseul potrivit pe drumuri (OSRM) e linia principala; punctele GPS
            // brute raman ca linie discreta punctata, pentru control.
            layers.push(L.polyline(latlngs, { color: '#94a3b8', weight: 2, opacity: .45, dashArray: '4 6' }));
            matched.forEach(function (segment) {
                layers.push(L.polyline(segment, { color: '#0d6efd', weight: 4, opacity: .85 }));
            });
        } else {
            layers.push(L.polyline(latlngs, { color: '#0d6efd', weight: 3, opacity: .8 }));
        }
        modal.routeLayer = L.featureGroup(layers).addTo(modal.map);

        // Punctele GPS reale (esantioanele SAS, ~1 punct/minut in mers).
        if (points.length <= 1500) {
            points.forEach(function (p) {
                var dot = L.circleMarker([p.lat, p.lng], {
                    radius: 2.5, color: matched ? '#94a3b8' : '#0d6efd', weight: 1, fillColor: '#ffffff', fillOpacity: 1
                });
                if (p.ts) {
                    dot.bindTooltip(dateTimeLabel(p.ts, true) + (p.speed !== null ? ' · ' + p.speed + ' km/h' : ''));
                }
                dot.addTo(modal.map);
                modal.markers.push(dot);
            });
        }

        var start = L.circleMarker(latlngs[0], { radius: 6, color: '#198754', weight: 2, fillColor: '#198754', fillOpacity: .9 })
            .bindTooltip('Start: ' + dateTimeLabel(points[0].ts, true));
        var end = L.circleMarker(latlngs[latlngs.length - 1], { radius: 6, color: '#dc3545', weight: 2, fillColor: '#dc3545', fillOpacity: .9 })
            .bindTooltip('Final: ' + dateTimeLabel(points[points.length - 1].ts, true));
        start.addTo(modal.map);
        end.addTo(modal.map);
        modal.markers.push(start, end);
        if (liveMarker) { liveMarker.addTo(modal.map); }

        modal.map.fitBounds(modal.routeLayer.getBounds(), { padding: [24, 24] });
        note.hidden = true;
    }

    function renderVehicleDetail(day) {
        var multiDay = (day.days || 1) > 1;
        var tiles = [
            { icon: 'bi-signpost-2', label: multiDay ? 'Km perioada' : 'Km azi', value: day.total_km !== null ? NF.format(Math.round(day.total_km)) + ' km' : '0 km' },
            { icon: 'bi-speedometer', label: 'Odometru', value: day.odometer_km !== null ? NF.format(day.odometer_km) + ' km' : '-' },
            { icon: 'bi-speedometer2', label: 'Viteza medie', value: day.average_speed !== null ? day.average_speed + ' km/h' : '-' },
            { icon: 'bi-clock', label: 'Timp condus', value: durationLabel(day.work_seconds) },
            { icon: 'bi-hourglass-split', label: 'Ralanti', value: durationLabel(day.idle_seconds) },
            { icon: 'bi-fuel-pump', label: 'Motorina CAN', value: day.has_can ? (day.can_fuel_l !== null ? NF.format(Math.round(day.can_fuel_l)) + ' L' : '-') : 'fara CAN' },
            { icon: 'bi-droplet', label: 'Consum CAN', value: day.can_l100 !== null ? day.can_l100 + ' L/100km' : '-' },
            { icon: 'bi-calendar-range', label: 'Perioada', value: (day.days || 1) + (day.days > 1 ? ' zile' : ' zi') }
        ];
        $('fdash-modal-tiles').innerHTML = tiles.map(function (tile) {
            return '<div class="fdash-tile">'
                + '<div class="fdash-tile-value">' + esc(tile.value) + '</div>'
                + '<div class="fdash-tile-label"><i class="bi ' + tile.icon + ' me-1"></i>' + esc(tile.label) + '</div>'
                + '</div>';
        }).join('');

        var segments = day.segments || [];
        $('fdash-seg-count').textContent = segments.length ? String(segments.length) : '';
        if (!segments.length) {
            $('fdash-modal-segments').innerHTML = '<tr><td colspan="8" class="fdash-empty">Niciun segment in perioada selectata (vehiculul nu a circulat).</td></tr>';
            return;
        }
        $('fdash-modal-segments').innerHTML = segments.map(function (segment) {
            return '<tr>'
                + '<td class="fdash-sub">' + segment.index + '</td>'
                + '<td>' + dateTimeLabel(segment.start_time, multiDay) + ' - ' + dateTimeLabel(segment.end_time, multiDay) + '</td>'
                + '<td class="fdash-sub" style="white-space: normal;">' + esc(segment.from || '-') + '</td>'
                + '<td class="fdash-sub" style="white-space: normal;">' + esc(segment.to || '-') + '</td>'
                + '<td class="ta-r">' + (segment.distance_km !== null ? NF.format(segment.distance_km) : '-') + '</td>'
                + '<td class="ta-r">' + (segment.average_speed !== null ? Math.round(segment.average_speed) : '-') + '</td>'
                + '<td class="ta-r">' + durationLabel(segment.idle_engine_seconds) + '</td>'
                + '<td class="ta-r">' + (segment.km_index !== null ? NF.format(segment.km_index) : '-') + '</td>'
                + '</tr>';
        }).join('');
    }

    refresh();
})();
</script>

<?php endif; ?>
