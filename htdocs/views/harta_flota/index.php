<?php
$credentialsAvailable = !empty($credentialsAvailable);
$dataUrl = build_query_url(['page' => 'harta_flota', 'action' => 'data']);
$hierarchyUrl = build_query_url(['page' => 'harta_flota', 'action' => 'hierarchy']);
$routeUrl = build_query_url(['page' => 'harta_flota', 'action' => 'route']);
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css">

<div class="d-flex justify-content-between align-items-center mb-2">
    <div>
        <h1 class="h5 mb-0">Harta Flota</h1>
        <div class="text-muted small" id="fleet-map-updated">Pozitii live SAS, actualizare automata la 30s.</div>
    </div>
    <button type="button" id="fleet-panel-toggle" class="btn btn-sm btn-outline-secondary" title="Ascunde / arata panoul">
        <i class="bi bi-layout-sidebar" aria-hidden="true"></i>
    </button>
</div>

<?php if (!$credentialsAvailable): ?>
    <div class="alert alert-warning">
        Credentialele SAS nu sunt configurate. Completeaza <code>SAS_API_USERNAME</code> si
        <code>SAS_API_PASSWORD</code> in fisierul <code>.env</code>, apoi reincarca pagina.
    </div>
<?php endif; ?>

<div id="fleet-map-error" class="alert alert-danger d-none py-2"></div>

<div class="fleet-map-shell d-flex gap-2">
    <div class="fleet-side-panel d-flex flex-column gap-2" id="fleet-side-panel">

        <!-- Sectiunea: Vehicule -->
        <div class="card flex-shrink-1 d-flex flex-column" style="min-height: 0;">
            <button class="card-header py-2 d-flex justify-content-between align-items-center btn text-start"
                    type="button" data-bs-toggle="collapse" data-bs-target="#fleet-vehicles-body">
                <span class="fw-semibold"><i class="bi bi-truck me-1"></i>Vehicule</span>
                <span class="badge text-bg-secondary" id="fleet-veh-counter">0/0</span>
            </button>
            <div class="collapse show d-flex flex-column" id="fleet-vehicles-body" style="min-height: 0;">
                <div class="p-2 border-bottom">
                    <input type="search" id="fleet-map-search" class="form-control form-control-sm mb-2"
                           placeholder="Cauta..." autocomplete="off">
                    <div class="d-flex flex-wrap gap-2 small">
                        <label class="form-check form-check-inline m-0">
                            <input class="form-check-input" type="checkbox" id="flt-moving" checked>
                            <span class="form-check-label"><span class="fleet-tri" style="border-bottom-color:#198754"></span> In miscare</span>
                        </label>
                        <label class="form-check form-check-inline m-0">
                            <input class="form-check-input" type="checkbox" id="flt-stopped" checked>
                            <span class="form-check-label"><span class="fleet-sq" style="background:#dc3545"></span> Oprite</span>
                        </label>
                        <label class="form-check form-check-inline m-0">
                            <input class="form-check-input" type="checkbox" id="flt-stale" checked>
                            <span class="form-check-label"><i class="bi bi-exclamation-triangle-fill text-warning"></i> Fara semnal</span>
                        </label>
                    </div>
                </div>
                <div class="overflow-auto p-2 fleet-tree" id="fleet-tree" style="min-height: 0;"></div>
            </div>
        </div>

        <!-- Sectiunea: Traseu / zi -->
        <div class="card flex-shrink-0 d-flex flex-column" style="min-height: 0; max-height: 55%;">
            <button class="card-header py-2 d-flex justify-content-between align-items-center btn text-start collapsed"
                    type="button" data-bs-toggle="collapse" data-bs-target="#fleet-route-body">
                <span class="fw-semibold"><i class="bi bi-signpost-2 me-1"></i>Traseu / cursa</span>
                <i class="bi bi-chevron-expand" aria-hidden="true"></i>
            </button>
            <div class="collapse d-flex flex-column" id="fleet-route-body" style="min-height: 0;">
                <div class="p-2 border-bottom">
                    <select id="route-vehicle" class="form-select form-select-sm mb-2"></select>
                    <div class="input-group input-group-sm mb-2">
                        <input type="date" id="route-date" class="form-control" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>">
                        <button type="button" id="route-load" class="btn btn-primary">Arata traseul</button>
                    </div>
                    <div class="d-flex justify-content-between align-items-center small">
                        <span id="route-summary" class="text-muted"></span>
                        <button type="button" id="route-clear" class="btn btn-sm btn-outline-secondary d-none">Ascunde</button>
                    </div>
                </div>
                <div class="overflow-auto" id="route-events" style="min-height: 0;"></div>
            </div>
        </div>
    </div>

    <div class="card flex-grow-1">
        <div class="card-body p-0">
            <div id="fleet-map" class="h-100 w-100" style="border-radius: inherit;"></div>
        </div>
    </div>
</div>

<style>
    .fleet-map-shell { height: calc(100vh - 170px); min-height: 520px; }
    .fleet-side-panel { width: 300px; min-width: 300px; min-height: 0; transition: margin-left .2s ease; }
    .fleet-side-panel.hidden { display: none !important; }
    .fleet-tri { display: inline-block; width: 0; height: 0; border-left: 5px solid transparent;
        border-right: 5px solid transparent; border-bottom: 9px solid #198754; vertical-align: baseline; }
    .fleet-sq { display: inline-block; width: 9px; height: 9px; vertical-align: baseline; }
    .fleet-tree { font-size: .85rem; }
    .fleet-tree ul { list-style: none; padding-left: 1rem; margin: 0; }
    .fleet-tree > ul { padding-left: 0; }
    .fleet-tree .tree-toggle { cursor: pointer; width: 1rem; display: inline-block; text-align: center; }
    .fleet-tree .tree-label { cursor: pointer; }
    .fleet-tree .veh-row { white-space: nowrap; }
    .fleet-tree .veh-row .veh-name { cursor: pointer; }
    .fleet-tree .veh-row .veh-name:hover { text-decoration: underline; }
    .fleet-tree .veh-row.selected .veh-name { font-weight: 600; color: var(--bs-primary); }
    .fleet-marker-wrap { position: relative; }
    .fleet-marker-label {
        position: absolute; top: 16px; left: 50%; transform: translateX(-50%);
        background: rgba(255,255,255,.92); border: 1px solid #adb5bd; border-radius: 3px;
        font-size: 10px; line-height: 1.15; padding: 1px 4px; text-align: center;
        white-space: nowrap; box-shadow: 0 1px 2px rgba(0,0,0,.25); font-family: var(--bs-font-sans-serif);
    }
    .fleet-marker-label .plate { font-weight: 700; }
    .fleet-arrow { position: absolute; top: 0; left: 50%; margin-left: -7px;
        width: 0; height: 0; border-left: 7px solid transparent; border-right: 7px solid transparent;
        border-bottom: 15px solid #198754; filter: drop-shadow(0 1px 1px rgba(0,0,0,.4)); }
    .fleet-square { position: absolute; top: 2px; left: 50%; margin-left: -6px;
        width: 12px; height: 12px; border: 2px solid #fff; box-shadow: 0 1px 3px rgba(0,0,0,.4); }
    .route-event { border-bottom: 1px solid var(--bs-border-color); padding: .35rem .6rem; cursor: pointer; }
    .route-event:hover { background: var(--bs-tertiary-bg); }
    .route-event .re-head { display: flex; justify-content: space-between; font-size: .78rem; }
    .route-event .re-addr { font-size: .8rem; color: var(--bs-secondary-color); }
    .route-flag { background: #198754; color: #fff; border: 1px solid #fff; border-radius: 3px;
        font-size: 11px; font-weight: 700; text-align: center; line-height: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.4); }
    .leaflet-popup-content { margin: 10px 14px; }
    .fleet-popup-table td { padding: 0 .4rem 0 0; font-size: .82rem; vertical-align: top; }
    .fleet-popup-table td:first-child { font-weight: 600; white-space: nowrap; }
</style>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
<script>
(function () {
    'use strict';

    var DATA_URL = <?= json_encode($dataUrl, JSON_UNESCAPED_SLASHES) ?>;
    var HIERARCHY_URL = <?= json_encode($hierarchyUrl, JSON_UNESCAPED_SLASHES) ?>;
    var ROUTE_URL = <?= json_encode($routeUrl, JSON_UNESCAPED_SLASHES) ?>;
    var REFRESH_MS = 30000;
    var CREDENTIALS_OK = <?= $credentialsAvailable ? 'true' : 'false' ?>;

    var EVENT_LABELS = {
        0: 'Obisnuit', 1: 'Alarma', 3: 'Panica', 6: 'Pornire motor', 7: 'Oprire motor',
        9: 'Repornire', 10: 'Viteza depasita', 12: 'GPS deconectat', 13: 'GPS scurtcircuit',
        26: 'Dispozitiv deconectat', 27: 'Accelerare brusca', 28: 'Franare brusca',
        29: 'Iesire geofence', 30: 'Intrare geofence', 31: 'GPS bruiat',
        38: 'Crestere combustibil', 39: 'Scadere combustibil', 40: 'Stationare excesiva', 41: 'Viteza normala'
    };

    function eventLabel(code) {
        if (code === null || code === undefined) { return '-'; }
        return EVENT_LABELS[code] || ('Eveniment ' + code);
    }

    // ---------------------------------------------------------------- harta
    var map = L.map('fleet-map', { zoomControl: true });
    var layerStandard = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
    });
    var layerHot = L.tileLayer('https://{s}.tile.openstreetmap.fr/hot/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors, Tiles style &copy; Humanitarian OpenStreetMap Team'
    });
    var layerSat = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
        maxZoom: 19,
        attribution: 'Tiles &copy; Esri &mdash; Source: Esri, Maxar, Earthstar Geographics'
    });
    layerStandard.addTo(map);
    L.control.layers({ 'Standard': layerStandard, 'Umanitar': layerHot, 'Satelit': layerSat }, null, { position: 'topright' }).addTo(map);
    L.control.scale({ imperial: false }).addTo(map);
    map.setView([45.9432, 24.9668], 7);

    var clusterGroup = L.markerClusterGroup({
        maxClusterRadius: 45,
        showCoverageOnHover: false,
        disableClusteringAtZoom: 12
    });
    map.addLayer(clusterGroup);

    var routeLayer = L.layerGroup().addTo(map);

    // ---------------------------------------------------------------- stare
    var lastPositions = [];
    var hierarchy = null;
    var markersById = {};
    var visibleCarIds = null;     // null = tot; Set de sas_vehicle_id vizibile
    var selectedCarId = null;
    var searchTerm = '';
    var didInitialFit = false;
    var activeRoute = null;

    var elError = document.getElementById('fleet-map-error');
    var elUpdated = document.getElementById('fleet-map-updated');
    var elTree = document.getElementById('fleet-tree');
    var elCounter = document.getElementById('fleet-veh-counter');
    var elRouteVehicle = document.getElementById('route-vehicle');
    var elRouteEvents = document.getElementById('route-events');
    var elRouteSummary = document.getElementById('route-summary');
    var elRouteClear = document.getElementById('route-clear');

    function esc(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function statusOf(p) {
        if (p.is_stale) { return 'stale'; }
        return p.is_moving ? 'moving' : 'stopped';
    }

    function statusColor(p) {
        var s = statusOf(p);
        if (s === 'stale') { return '#fd7e14'; }
        if (s === 'moving') { return '#198754'; }
        return '#dc3545';
    }

    function statusFilterAllows(p) {
        var s = statusOf(p);
        if (s === 'moving') { return document.getElementById('flt-moving').checked; }
        if (s === 'stopped') { return document.getElementById('flt-stopped').checked; }
        return document.getElementById('flt-stale').checked;
    }

    function matchesSearch(p) {
        if (searchTerm === '') { return true; }
        var haystack = ((p.registration || '') + ' ' + (p.driver || '') + ' ' + (p.local_label || '')).toLowerCase();
        return haystack.indexOf(searchTerm) !== -1;
    }

    function isVisible(p) {
        if (visibleCarIds !== null && !visibleCarIds.has(p.sas_vehicle_id)) { return false; }
        return statusFilterAllows(p) && matchesSearch(p);
    }

    function timeOf(iso) {
        if (!iso) { return '-'; }
        var m = String(iso).match(/T(\d{2}:\d{2}(:\d{2})?)/);
        return m ? m[1] : String(iso);
    }

    // ---------------------------------------------------------------- markere
    function markerIcon(p) {
        var color = statusColor(p);
        var shape;
        if (statusOf(p) === 'moving' && p.heading !== null && p.heading !== undefined) {
            shape = '<div class="fleet-arrow" style="border-bottom-color:' + color + ';' +
                'transform: rotate(' + Math.round(p.heading) + 'deg); transform-origin: 50% 60%;"></div>';
        } else if (statusOf(p) === 'stale') {
            shape = '<div class="fleet-square" style="background:' + color + '; border-radius: 50%;"></div>';
        } else {
            shape = '<div class="fleet-square" style="background:' + color + ';"></div>';
        }
        var label = '<div class="fleet-marker-label"><span class="plate">' + esc(p.registration || ('#' + p.sas_vehicle_id)) +
            '</span><br>' + esc(timeOf(p.timestamp)) + '</div>';
        return L.divIcon({
            className: 'fleet-marker-wrap',
            html: shape + label,
            iconSize: [16, 16],
            iconAnchor: [8, 8],
            popupAnchor: [0, -8]
        });
    }

    function popupHtml(p) {
        var rows = [
            ['Eveniment:', eventLabel(p.trigger_event)],
            ['Judet:', p.county || '-'],
            ['Oras:', p.city || '-'],
            ['Adresa:', p.address || '-'],
            ['Localizare:', (p.latitude !== null ? p.latitude.toFixed(7) : '-') + ' ' + (p.longitude !== null ? p.longitude.toFixed(7) : '-')],
            ['Data:', p.timestamp ? String(p.timestamp).replace('T', ' ') : '-'],
            ['Viteza:', p.speed === null ? '-' : Math.round(p.speed) + ' km/h']
        ];
        if (p.driver) { rows.splice(1, 0, ['Sofer:', p.driver]); }
        if (p.local_label) { rows.splice(1, 0, ['Vehicul:', p.local_label]); }
        if (p.poi) { rows.push(['POI:', p.poi]); }
        var html = '<div class="fw-bold mb-1">' + esc(p.registration || ('#' + p.sas_vehicle_id)) + '</div>' +
            '<table class="fleet-popup-table">';
        rows.forEach(function (r) { html += '<tr><td>' + esc(r[0]) + '</td><td>' + esc(r[1]) + '</td></tr>'; });
        html += '</table>';
        if (p.is_stale) { html += '<div class="small text-danger mt-1"><i class="bi bi-exclamation-triangle"></i> Fara semnal recent</div>'; }
        return html;
    }

    function renderMarkers() {
        var openPopupCarId = null;
        Object.keys(markersById).forEach(function (carId) {
            if (markersById[carId].isPopupOpen()) { openPopupCarId = carId; }
        });

        clusterGroup.clearLayers();
        markersById = {};
        var bounds = [];
        var shown = 0;

        lastPositions.forEach(function (p) {
            if (!isVisible(p)) { return; }
            shown++;
            var marker = L.marker([p.latitude, p.longitude], { icon: markerIcon(p) }).bindPopup(popupHtml(p));
            marker.on('click', function () { setSelected(String(p.sas_vehicle_id)); });
            clusterGroup.addLayer(marker);
            markersById[p.sas_vehicle_id] = marker;
            bounds.push([p.latitude, p.longitude]);
        });

        elCounter.textContent = shown + '/' + lastPositions.length;

        if (!didInitialFit && bounds.length > 0) {
            map.fitBounds(bounds, { padding: [30, 30], maxZoom: 12 });
            didInitialFit = true;
        }

        if (openPopupCarId !== null && markersById[openPopupCarId]) {
            markersById[openPopupCarId].openPopup();
        }
    }

    function setSelected(carId) {
        selectedCarId = carId;
        elTree.querySelectorAll('.veh-row.selected').forEach(function (el) { el.classList.remove('selected'); });
        var row = elTree.querySelector('.veh-row[data-car="' + carId + '"]');
        if (row) { row.classList.add('selected'); }
    }

    function zoomToCar(carId) {
        var marker = markersById[carId];
        if (!marker) { return; }
        map.setView(marker.getLatLng(), Math.max(map.getZoom(), 14));
        marker.openPopup();
        setSelected(carId);
    }

    // ---------------------------------------------------------------- ierarhie
    function renderTree() {
        if (!hierarchy) { return; }
        var byBranch = {}, byWp = {};
        hierarchy.work_points.forEach(function (wp) { (byBranch[wp.branch_id] = byBranch[wp.branch_id] || []).push(wp); });
        hierarchy.cars.forEach(function (c) { (byWp[c.work_point_id] = byWp[c.work_point_id] || []).push(c); });

        function vehRow(c) {
            return '<li class="veh-row" data-car="' + esc(c.sas_vehicle_id) + '">' +
                '<input type="checkbox" class="form-check-input form-check-input-sm me-1 veh-check" checked data-car="' + esc(c.sas_vehicle_id) + '">' +
                '<span class="veh-status" data-car-status="' + esc(c.sas_vehicle_id) + '"></span> ' +
                '<span class="veh-name" data-car-zoom="' + esc(c.sas_vehicle_id) + '">' + esc(c.registration) + '</span>' +
                '</li>';
        }

        function group(label, name, inner, level) {
            return '<li><span class="tree-toggle" data-toggle>&#9662;</span>' +
                '<input type="checkbox" class="form-check-input form-check-input-sm me-1 grp-check" checked>' +
                '<span class="tree-label text-muted">[' + esc(label) + ']</span> <span class="tree-label fw-semibold">' + esc(name) + '</span>' +
                '<ul>' + inner + '</ul></li>';
        }

        var html = '<ul>';
        hierarchy.companies.forEach(function (co) {
            var brHtml = '';
            (hierarchy.branches || []).filter(function (b) { return b.company_id === co.id; }).forEach(function (br) {
                var wpHtml = '';
                (byBranch[br.id] || []).forEach(function (wp) {
                    var cars = (byWp[wp.id] || []).slice().sort(function (a, b) {
                        return String(a.registration).localeCompare(String(b.registration));
                    });
                    if (cars.length === 0) { return; }
                    wpHtml += group('Punct lucru', wp.name, cars.map(vehRow).join(''), 3);
                });
                brHtml += group('Sucursala', br.name, wpHtml, 2);
            });
            html += group('Companie', co.name, brHtml, 1);
        });
        html += '</ul>';
        elTree.innerHTML = html;
        updateTreeStatusIcons();

        // Optiunile pentru selectorul de traseu.
        var options = hierarchy.cars.slice().sort(function (a, b) {
            return String(a.registration).localeCompare(String(b.registration));
        }).map(function (c) {
            return '<option value="' + esc(c.sas_vehicle_id) + '">' + esc(c.registration) + '</option>';
        });
        elRouteVehicle.innerHTML = '<option value="">-- Alege vehiculul --</option>' + options.join('');
    }

    function updateTreeStatusIcons() {
        var byId = {};
        lastPositions.forEach(function (p) { byId[p.sas_vehicle_id] = p; });
        elTree.querySelectorAll('[data-car-status]').forEach(function (el) {
            var p = byId[el.getAttribute('data-car-status')];
            if (!p) { el.innerHTML = '<span class="fleet-sq" style="background:#adb5bd"></span>'; return; }
            var s = statusOf(p);
            if (s === 'moving') { el.innerHTML = '<span class="fleet-tri"></span>'; }
            else if (s === 'stopped') { el.innerHTML = '<span class="fleet-sq" style="background:#dc3545"></span>'; }
            else { el.innerHTML = '<i class="bi bi-exclamation-triangle-fill text-warning"></i>'; }
        });
    }

    function recomputeVisibleFromTree() {
        var checks = elTree.querySelectorAll('.veh-check');
        if (checks.length === 0) { visibleCarIds = null; return; }
        var set = new Set();
        var allChecked = true;
        checks.forEach(function (cb) {
            if (cb.checked) { set.add(parseInt(cb.getAttribute('data-car'), 10)); }
            else { allChecked = false; }
        });
        visibleCarIds = allChecked ? null : set;
    }

    elTree.addEventListener('change', function (event) {
        var target = event.target;
        if (target.classList.contains('grp-check')) {
            target.closest('li').querySelectorAll('ul input[type="checkbox"]').forEach(function (cb) { cb.checked = target.checked; });
        }
        if (target.classList.contains('veh-check') || target.classList.contains('grp-check')) {
            recomputeVisibleFromTree();
            renderMarkers();
        }
    });
    elTree.addEventListener('click', function (event) {
        var toggle = event.target.closest('[data-toggle]');
        if (toggle) {
            var ul = toggle.parentElement.querySelector('ul');
            if (ul) {
                var hidden = ul.style.display === 'none';
                ul.style.display = hidden ? '' : 'none';
                toggle.innerHTML = hidden ? '&#9662;' : '&#9656;';
            }
            return;
        }
        var zoom = event.target.closest('[data-car-zoom]');
        if (zoom) { zoomToCar(zoom.getAttribute('data-car-zoom')); }
    });

    // ---------------------------------------------------------------- traseu
    function clearRoute() {
        routeLayer.clearLayers();
        activeRoute = null;
        elRouteEvents.innerHTML = '';
        elRouteSummary.textContent = '';
        elRouteClear.classList.add('d-none');
    }

    function drawRoute(route) {
        routeLayer.clearLayers();
        activeRoute = route;

        var latlngs = route.points.map(function (pt) { return [pt.latitude, pt.longitude]; });
        if (latlngs.length === 0) {
            elRouteSummary.textContent = 'Niciun punct GPS in ziua selectata.';
            elRouteClear.classList.remove('d-none');
            return;
        }

        L.polyline(latlngs, { color: '#e11', weight: 3, opacity: .9 }).addTo(routeLayer);

        function flag(latlng, text) {
            return L.marker(latlng, {
                icon: L.divIcon({ className: 'route-flag', html: esc(text), iconSize: [18, 18], iconAnchor: [9, 17] })
            }).addTo(routeLayer);
        }
        flag(latlngs[0], '1');
        if (latlngs.length > 1) { flag(latlngs[latlngs.length - 1], '2'); }

        map.fitBounds(latlngs, { padding: [30, 30] });

        var kmText = route.summary && route.summary.total_km !== null && route.summary.total_km !== undefined
            ? route.summary.total_km + ' km' : '';
        var avgText = route.summary && route.summary.average_speed ? ' · medie ' + route.summary.average_speed + ' km/h' : '';
        elRouteSummary.textContent = ('Traseu parcurs: ' + (kmText || '-') + avgText);
        elRouteClear.classList.remove('d-none');

        // Lista de evenimente (cele mai recente sus), doar punctele cu eveniment sau schimbare relevanta.
        var interesting = route.points.filter(function (pt, idx) {
            return pt.trigger_event !== 0 || idx === 0 || idx === route.points.length - 1 ||
                (pt.speed !== null && pt.speed > 0);
        });
        var listHtml = interesting.slice().reverse().map(function (pt) {
            var idx = route.points.indexOf(pt);
            var place = [pt.address, pt.city, pt.county].filter(function (x) { return x && x !== '-'; }).join(' / ');
            return '<div class="route-event" data-point="' + idx + '">' +
                '<div class="re-head"><span>' + esc(timeOf(pt.timestamp)) + ' <span class="text-muted">' + esc(eventLabel(pt.trigger_event)) + '</span></span>' +
                '<span>' + (pt.speed === null ? '-' : Math.round(pt.speed) + ' km/h') + '</span></div>' +
                '<div class="re-addr">' + esc(place || '-') + '</div>' +
                '</div>';
        }).join('');
        elRouteEvents.innerHTML = listHtml;
    }

    elRouteEvents.addEventListener('click', function (event) {
        var item = event.target.closest('[data-point]');
        if (!item || !activeRoute) { return; }
        var pt = activeRoute.points[parseInt(item.getAttribute('data-point'), 10)];
        if (!pt) { return; }
        map.setView([pt.latitude, pt.longitude], Math.max(map.getZoom(), 15));
        L.popup().setLatLng([pt.latitude, pt.longitude])
            .setContent('<b>' + esc(timeOf(pt.timestamp)) + '</b> · ' + esc(eventLabel(pt.trigger_event)) +
                '<br>' + esc([pt.address, pt.city].filter(Boolean).join(', ')) +
                '<br>' + (pt.speed === null ? '-' : Math.round(pt.speed) + ' km/h'))
            .openOn(map);
    });

    document.getElementById('route-load').addEventListener('click', function () {
        var carId = elRouteVehicle.value;
        var date = document.getElementById('route-date').value;
        if (!carId || !date) { return; }
        elRouteSummary.textContent = 'Se incarca traseul...';
        fetch(ROUTE_URL + '&car_id=' + encodeURIComponent(carId) + '&date=' + encodeURIComponent(date),
            { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (route) {
                if (route.error) {
                    elRouteSummary.textContent = route.error;
                    elRouteClear.classList.remove('d-none');
                    return;
                }
                drawRoute(route);
            })
            .catch(function () { elRouteSummary.textContent = 'Traseul nu a putut fi incarcat.'; });
    });
    elRouteClear.addEventListener('click', clearRoute);

    // ---------------------------------------------------------------- refresh live
    function showError(message) {
        if (message) { elError.textContent = message; elError.classList.remove('d-none'); }
        else { elError.classList.add('d-none'); }
    }

    function refresh() {
        if (!CREDENTIALS_OK) { return; }
        fetch(DATA_URL, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (payload) {
                lastPositions = Array.isArray(payload.positions) ? payload.positions : [];
                renderMarkers();
                updateTreeStatusIcons();
                showError(payload.error || null);
                if (payload.fetched_at) {
                    elUpdated.textContent = 'Ultima actualizare SAS: ' + payload.fetched_at + (payload.from_cache ? ' (cache)' : '');
                }
            })
            .catch(function () { showError('Nu s-au putut incarca pozitiile. Se reincearca automat.'); });
    }

    function loadHierarchy() {
        if (!CREDENTIALS_OK) { return; }
        fetch(HIERARCHY_URL, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (payload) {
                if (payload && Array.isArray(payload.cars)) {
                    hierarchy = payload;
                    renderTree();
                    updateTreeStatusIcons();
                }
            })
            .catch(function () { /* panoul ramane gol; harta functioneaza oricum */ });
    }

    // ---------------------------------------------------------------- UI diverse
    document.getElementById('fleet-map-search').addEventListener('input', function (event) {
        searchTerm = String(event.target.value || '').trim().toLowerCase();
        renderMarkers();
    });
    ['flt-moving', 'flt-stopped', 'flt-stale'].forEach(function (id) {
        document.getElementById(id).addEventListener('change', renderMarkers);
    });
    document.getElementById('fleet-panel-toggle').addEventListener('click', function () {
        document.getElementById('fleet-side-panel').classList.toggle('hidden');
        setTimeout(function () { map.invalidateSize(); }, 220);
    });

    loadHierarchy();
    refresh();
    setInterval(refresh, REFRESH_MS);
})();
</script>
