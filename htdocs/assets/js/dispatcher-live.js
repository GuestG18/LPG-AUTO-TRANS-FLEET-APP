/*
 * Banda "Curse in desfasurare (GPS live)" din Dispecer curse.
 *
 * GPS (SAS) spune ce face fizic vehiculul acum; aplicatia spune daca exista o
 * cursa inregistrata. Starea per vehicul vine gata calculata din backend
 * (?page=dispecer_curse&action=live_gps):
 *   trip    = in miscare + cursa asociata      (verde)
 *   no_trip = in miscare fara cursa             (portocaliu, cere atentie)
 *   stale   = oprit recent / pozitie intarziata (neutru)
 *
 * Tot ce e interactiv foloseste delegare de evenimente pe containerul
 * randurilor, legata o singura data; refresh-ul reface doar HTML-ul randurilor.
 * Harta din detalii (Leaflet, aceleasi tile-uri OSM ca Harta Flota) se incarca
 * lenes, la prima expandare, si se refoloseste intre refresh-uri.
 */
(function () {
    'use strict';

    var REFRESH_MS = 60000;
    var TICK_MS = 1000;
    var LEAFLET_CSS = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
    var LEAFLET_JS = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
    var LEAFLET_CSS_SRI = 'sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=';
    var LEAFLET_JS_SRI = 'sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=';

    function init() {
        var panel = document.getElementById('dispatcher-live');
        if (!panel || panel.getAttribute('data-dl-ready') === '1') { return; }
        panel.setAttribute('data-dl-ready', '1');

        var endpoint = panel.getAttribute('data-endpoint') || '';
        var rowsEl = panel.querySelector('[data-dl-rows]');
        var emptyEl = panel.querySelector('[data-dl-empty]');
        var errorEl = panel.querySelector('[data-dl-error]');
        var countEl = panel.querySelector('[data-dl-count]');
        var updatedEl = panel.querySelector('[data-dl-updated]');
        var refreshBtn = panel.querySelector('[data-dl-refresh]');
        var searchEl = panel.querySelector('[data-dl-search]');
        var selectEl = panel.querySelector('[data-dl-filter-select]');
        var toggleEl = document.getElementById('dispatcher-live-toggle');

        var state = {
            data: null,
            vehicles: [],
            byKey: {},
            expandedKey: null,
            search: '',
            filter: 'all',
            loading: false,
            everShown: false,
            clockOffset: 0,        // secunde: ceasul serverului - ceasul browserului
            pendingRender: false
        };

        // ------------------------------------------------------------ utilitare
        function esc(value) {
            if (value === null || value === undefined) { return ''; }
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function normalize(value) {
            return String(value || '')
                .toLowerCase()
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .replace(/[^a-z0-9]+/g, ' ')
                .trim();
        }

        function nowServer() {
            return Math.floor(Date.now() / 1000) + state.clockOffset;
        }

        function ageSeconds(vehicle) {
            if (vehicle.position_ts) { return Math.max(0, nowServer() - vehicle.position_ts); }
            return typeof vehicle.age_seconds === 'number' ? vehicle.age_seconds : null;
        }

        function agoShort(seconds) {
            if (seconds === null || seconds === undefined) { return ''; }
            if (seconds < 60) { return 'acum ' + seconds + ' sec'; }
            if (seconds < 3600) { return 'acum ' + Math.floor(seconds / 60) + ' min'; }
            return 'acum ' + Math.floor(seconds / 3600) + ' h';
        }

        function agoLong(seconds) {
            if (seconds === null || seconds === undefined) { return ''; }
            if (seconds < 60) { return seconds + ' sec în urmă'; }
            if (seconds < 3600) { return Math.floor(seconds / 60) + ' min în urmă'; }
            return Math.floor(seconds / 3600) + ' h în urmă';
        }

        function initials(name) {
            var parts = String(name || '').trim().split(/\s+/).filter(Boolean);
            if (!parts.length) { return ''; }
            var first = parts[0].charAt(0);
            var last = parts.length > 1 ? parts[parts.length - 1].charAt(0) : '';
            return (first + last).toUpperCase();
        }

        function compassLabel(degrees) {
            var names = ['N', 'NE', 'E', 'SE', 'S', 'SV', 'V', 'NV'];
            return names[Math.round(((degrees % 360) + 360) % 360 / 45) % 8];
        }

        function formatCapacity(value) {
            return Number(value).toLocaleString('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' t';
        }

        function rowId(vehicle) {
            return 'dispatcher-live-row-' + String(vehicle.key).replace(/[^A-Za-z0-9_-]/g, '');
        }

        function mapHref(vehicle) {
            var base = state.data && state.data.map_url ? state.data.map_url : '';
            if (!base || !vehicle.sas_vehicle_id) { return ''; }
            return base + (base.indexOf('?') === -1 ? '?' : '&') + 'car=' + encodeURIComponent(vehicle.sas_vehicle_id);
        }

        function canCreate(vehicle) {
            return !!(state.data && state.data.permissions && state.data.permissions.create)
                && !!vehicle.local_vehicle_id
                && !!document.getElementById('add-race-form');
        }

        function canMap(vehicle) {
            return !!(state.data && state.data.permissions && state.data.permissions.map) && mapHref(vehicle) !== '';
        }

        // ------------------------------------------------------------ filtrare
        function matchesFilter(vehicle) {
            switch (state.filter) {
                case 'with_trip': return !!vehicle.race;
                case 'without_trip': return !vehicle.race;
                case 'stationary': return vehicle.state === 'stale';
                default: return true;
            }
        }

        function matchesSearch(vehicle) {
            if (!state.search) { return true; }
            var haystack = normalize([
                vehicle.plate,
                vehicle.vehicle_label,
                vehicle.driver ? vehicle.driver.name : '',
                vehicle.place,
                vehicle.race ? '#' + vehicle.race.id + ' ' + (vehicle.race.route || '') : ''
            ].join(' '));
            var compactHaystack = haystack.replace(/ /g, '');
            return state.search.split(' ').every(function (token) {
                return haystack.indexOf(token) !== -1 || compactHaystack.indexOf(token) !== -1;
            });
        }

        // ------------------------------------------------------------ randare
        function statusPill(vehicle) {
            if (vehicle.state === 'trip') {
                return '<span class="dispatcher-live-pill dispatcher-live-pill--trip"><i class="bi bi-cursor-fill" aria-hidden="true"></i>În mișcare</span>';
            }
            if (vehicle.state === 'no_trip') {
                return '<span class="dispatcher-live-pill dispatcher-live-pill--no_trip"><i class="bi bi-clock-history" aria-hidden="true"></i>În mișcare · fără cursă</span>';
            }
            var label;
            if (vehicle.status === 'moving') {
                var minutes = Math.round((state.data && state.data.fresh_seconds ? state.data.fresh_seconds : 180) / 60);
                label = 'Ultima poziție > ' + minutes + ' min';
            } else {
                label = 'Staționare';
            }
            return '<span class="dispatcher-live-pill dispatcher-live-pill--stale"><i class="bi bi-clock-fill" aria-hidden="true"></i>' + esc(label) + '</span>';
        }

        function vehiclePhoto(vehicle, variant, className) {
            var photo = vehicle.vehicle_photo && vehicle.vehicle_photo[variant];
            if (photo) {
                return '<span class="' + className + '"><img src="' + esc(photo) + '" alt="' + esc('Vehicul ' + vehicle.plate) + '" loading="lazy" decoding="async" data-dl-img></span>';
            }
            return '<span class="' + className + '" aria-hidden="true"><i class="bi bi-truck"></i></span>';
        }

        function avatar(driver) {
            if (!driver) {
                return '<span class="dispatcher-live-avatar dispatcher-live-avatar--empty" aria-hidden="true"><i class="bi bi-person"></i></span>';
            }
            if (driver.photo) {
                return '<span class="dispatcher-live-avatar"><img src="' + esc(driver.photo) + '" alt="' + esc(driver.name) + '" loading="lazy" decoding="async" data-dl-img data-dl-initials="' + esc(initials(driver.name)) + '"></span>';
            }
            var text = initials(driver.name);
            return text
                ? '<span class="dispatcher-live-avatar" aria-hidden="true">' + esc(text) + '</span>'
                : '<span class="dispatcher-live-avatar dispatcher-live-avatar--empty" aria-hidden="true"><i class="bi bi-person"></i></span>';
        }

        function driverSourceLabel(driver) {
            if (!driver) { return ''; }
            if (driver.source === 'race') { return 'Șofer cursă'; }
            if (driver.source === 'gps') { return 'Raportat de GPS'; }
            if (driver.source === 'vehicle') { return 'Alocat vehiculului'; }
            return '';
        }

        function speedCell(vehicle) {
            if (vehicle.speed === null || vehicle.speed === undefined || (vehicle.state === 'stale' && !(vehicle.speed > 0))) {
                return '<span class="dispatcher-live-speed is-muted">—</span>';
            }
            var muted = vehicle.state === 'stale' ? ' is-muted' : '';
            var title = vehicle.state === 'stale' ? ' title="Ultima viteză raportată"' : '';
            return '<span class="dispatcher-live-speed' + muted + '"' + title + '>' + esc(vehicle.speed) + ' km/h</span>'
                + (vehicle.state !== 'stale' ? '<i class="bi bi-speedometer2 dispatcher-live-speed-gauge" aria-hidden="true" data-dl-expanded-only></i>' : '');
        }

        function tripCell(vehicle, expanded) {
            var race = vehicle.race;
            if (!race) { return '<span class="dispatcher-live-none">—</span>'; }
            var lines = '';
            if (expanded && race.route) {
                lines += '<span class="dispatcher-live-trip-line">' + esc(race.route).replace(' → ', ' <i class="bi bi-arrow-right" aria-hidden="true"></i> ') + '</span>';
            }
            if (expanded && race.tip_transport) {
                lines += '<span class="dispatcher-live-trip-line"><i class="bi bi-truck" aria-hidden="true"></i>Tip: ' + esc(race.tip_transport) + '</span>';
            }
            if (race.start_label) {
                lines += '<span class="dispatcher-live-trip-line">' + (expanded ? '<i class="bi bi-calendar3" aria-hidden="true"></i>' : '') + 'Început: ' + esc(race.start_label) + '</span>';
            }
            return '<div class="dispatcher-live-trip">'
                + '<i class="bi bi-geo-alt-fill dispatcher-live-pin" aria-hidden="true"></i>'
                + '<div class="dispatcher-live-cell"><span class="dispatcher-live-trip-title">Cursă #' + esc(race.id) + '</span>' + lines + '</div>'
                + '</div>';
        }

        function actionsCell(vehicle) {
            var html = '';
            if (vehicle.race && vehicle.race.url) {
                html += '<a class="dispatcher-live-btn" href="' + esc(vehicle.race.url) + '">Vezi cursa</a>';
            } else if (canCreate(vehicle)) {
                html += '<button type="button" class="dispatcher-live-btn" data-dl-add="' + esc(vehicle.key) + '"><i class="bi bi-plus-lg" aria-hidden="true"></i>Adaugă cursă</button>';
            }

            if (canMap(vehicle)) {
                html += '<a class="dispatcher-live-icon-btn" href="' + esc(mapHref(vehicle)) + '" title="Vezi pe hartă" aria-label="Vezi ' + esc(vehicle.plate) + ' pe hartă"><i class="bi bi-map" aria-hidden="true"></i></a>';
            }

            var menu = '<li><button type="button" class="dropdown-item" data-dl-toggle-row="' + esc(vehicle.key) + '"><i class="bi bi-layout-text-sidebar-reverse" aria-hidden="true"></i>'
                + (state.expandedKey === vehicle.key ? 'Ascunde detaliile' : 'Detalii vehicul') + '</button></li>';
            if (canMap(vehicle)) {
                menu += '<li><a class="dropdown-item" href="' + esc(mapHref(vehicle)) + '"><i class="bi bi-map" aria-hidden="true"></i>Vezi pe hartă</a></li>';
            }
            if (vehicle.latitude !== null && vehicle.longitude !== null) {
                menu += '<li><button type="button" class="dropdown-item" data-dl-copy="' + esc(vehicle.latitude.toFixed(5) + ', ' + vehicle.longitude.toFixed(5)) + '"><i class="bi bi-clipboard" aria-hidden="true"></i>Copiază coordonatele</button></li>';
            }
            html += '<div class="dropdown">'
                + '<button type="button" class="dispatcher-live-icon-btn dispatcher-live-kebab" data-bs-toggle="dropdown" data-bs-popper-config=\'{"strategy":"fixed"}\' aria-expanded="false" aria-label="Mai multe acțiuni pentru ' + esc(vehicle.plate) + '"><i class="bi bi-three-dots-vertical" aria-hidden="true"></i></button>'
                + '<ul class="dropdown-menu dropdown-menu-end">' + menu + '</ul>'
                + '</div>';

            return '<div class="dispatcher-live-actions">' + html + '</div>';
        }

        function detailHtml(vehicle) {
            var age = ageSeconds(vehicle);
            var driver = vehicle.driver;

            // A. Vehicul (capacitatea e cea tehnica din fisa vehiculului, nu cantitatea transportata)
            var vehicleFacts = '<li><i class="bi bi-truck-front" aria-hidden="true"></i><span>' + esc(vehicle.plate) + '</span></li>';
            if (vehicle.vehicle_label) { vehicleFacts += '<li><i class="bi bi-truck" aria-hidden="true"></i><span>' + esc(vehicle.vehicle_label) + '</span></li>'; }
            if (vehicle.vehicle_type) { vehicleFacts += '<li><i class="bi bi-tag" aria-hidden="true"></i><span>' + esc(vehicle.vehicle_type) + '</span></li>'; }
            if (vehicle.capacity_t) { vehicleFacts += '<li><i class="bi bi-box-seam" aria-hidden="true"></i><span>Capacitate: ' + esc(formatCapacity(vehicle.capacity_t)) + '</span></li>'; }
            if (!vehicle.local_vehicle_id) { vehicleFacts += '<li><i class="bi bi-info-circle" aria-hidden="true"></i><span>Nu există în fișa vehiculelor Fleet</span></li>'; }
            var sectionVehicle = '<div class="dispatcher-live-section">'
                + '<h3 class="dispatcher-live-section-title">Informații vehicul</h3>'
                + '<div class="dispatcher-live-vehicle-info"><ul class="dispatcher-live-facts">' + vehicleFacts + '</ul>'
                + vehiclePhoto(vehicle, 'large', 'dispatcher-live-photo-lg') + '</div>'
                + '</div>';

            // B. Sofer (telefonul vine doar daca utilizatorul are drept pe Soferi)
            var driverFacts;
            if (driver) {
                driverFacts = '<li><i class="bi bi-person" aria-hidden="true"></i><span>' + esc(driver.name) + '</span></li>';
                if (driver.id) { driverFacts += '<li><i class="bi bi-person-vcard" aria-hidden="true"></i><span>ID ' + esc(driver.id) + '</span></li>'; }
                driverFacts += '<li><i class="bi bi-info-circle" aria-hidden="true"></i><span>' + esc(driverSourceLabel(driver)) + '</span></li>';
                if (driver.phone) { driverFacts += '<li><i class="bi bi-telephone" aria-hidden="true"></i><span><a href="tel:' + esc(String(driver.phone).replace(/[^0-9+]/g, '')) + '" class="link-secondary text-decoration-none">' + esc(driver.phone) + '</a></span></li>'; }
            } else {
                driverFacts = '<li><i class="bi bi-person" aria-hidden="true"></i><span>Șofer neidentificat</span></li>';
            }
            var sectionDriver = '<div class="dispatcher-live-section">'
                + '<h3 class="dispatcher-live-section-title">Informații șofer</h3>'
                + '<div class="dispatcher-live-driver-info">' + avatar(driver) + '<ul class="dispatcher-live-facts">' + driverFacts + '</ul></div>'
                + '</div>';

            // C. Pozitie curenta (harta se ataseaza dupa randare)
            var hasCoords = vehicle.latitude !== null && vehicle.longitude !== null;
            var mapInner;
            if (hasCoords) {
                mapInner = '<div class="dispatcher-live-map-slot" data-dl-map-slot></div>'
                    + '<div class="dispatcher-live-map-card"><strong>' + esc(vehicle.place || 'Locație fără denumire') + '</strong>'
                    + '<span data-dl-age="short">' + esc(agoShort(age)) + '</span><br>'
                    + esc(vehicle.latitude.toFixed(4) + ', ' + vehicle.longitude.toFixed(4)) + '</div>';
            } else {
                mapInner = '<div class="dispatcher-live-map-empty"><i class="bi bi-geo" aria-hidden="true"></i>Locație indisponibilă</div>';
            }
            if (canMap(vehicle)) {
                mapInner += '<a class="dispatcher-live-btn dispatcher-live-btn--plain dispatcher-live-map-btn" href="' + esc(mapHref(vehicle)) + '"><i class="bi bi-map" aria-hidden="true"></i>Vezi pe hartă</a>';
            }
            var sectionMap = '<div class="dispatcher-live-section dispatcher-live-section--map">'
                + '<div class="dispatcher-live-map" aria-label="Poziția curentă">' + mapInner + '</div>'
                + '</div>';

            // D. Cursa (doar din aplicatie)
            var tripBody;
            if (vehicle.race) {
                var tripFacts = '<li><i class="bi bi-signpost-2" aria-hidden="true"></i><span><strong>#' + esc(vehicle.race.id) + '</strong></span></li>';
                if (vehicle.race.route) { tripFacts += '<li><i class="bi bi-geo-alt" aria-hidden="true"></i><span>' + esc(vehicle.race.route) + '</span></li>'; }
                if (vehicle.race.tip_transport) { tripFacts += '<li><i class="bi bi-truck" aria-hidden="true"></i><span>Tip: ' + esc(vehicle.race.tip_transport) + '</span></li>'; }
                if (vehicle.race.beneficiar) { tripFacts += '<li><i class="bi bi-building" aria-hidden="true"></i><span>' + esc(vehicle.race.beneficiar) + '</span></li>'; }
                if (vehicle.race.start_label) { tripFacts += '<li><i class="bi bi-calendar3" aria-hidden="true"></i><span>Început: ' + esc(vehicle.race.start_label) + '</span></li>'; }
                tripBody = '<ul class="dispatcher-live-facts">' + tripFacts + '</ul>'
                    + '<a class="dispatcher-live-btn dispatcher-live-btn--plain" href="' + esc(vehicle.race.url) + '"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>Deschide cursa</a>';
            } else {
                tripBody = '<div class="dispatcher-live-warning"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i><span>Nicio cursă activă asociată acestui vehicul.</span></div>';
                if (canCreate(vehicle)) {
                    tripBody += '<button type="button" class="dispatcher-live-btn" data-dl-add="' + esc(vehicle.key) + '"><i class="bi bi-plus-lg" aria-hidden="true"></i>Adaugă cursă</button>';
                }
            }
            var sectionTrip = '<div class="dispatcher-live-section dispatcher-live-detail-trip">'
                + '<h3 class="dispatcher-live-section-title">Detalii cursă</h3>' + tripBody + '</div>';

            // E. Viteza & directie (directia doar daca SAS o raporteaza)
            var speedHtml = vehicle.speed !== null && vehicle.speed !== undefined && vehicle.state !== 'stale'
                ? '<div class="dispatcher-live-speed-lg"><i class="bi bi-speedometer2" aria-hidden="true"></i>' + esc(vehicle.speed) + ' km/h</div>'
                : '<div class="dispatcher-live-speed-lg is-muted"><i class="bi bi-speedometer2" aria-hidden="true"></i>' + (vehicle.status === 'moving' ? 'Viteză neconfirmată' : 'Oprit') + '</div>';
            var motion = '';
            if (vehicle.heading !== null && vehicle.heading !== undefined) {
                motion += '<li><i class="bi bi-compass" aria-hidden="true"></i><span>Direcție ' + esc(compassLabel(vehicle.heading)) + ' (' + esc(vehicle.heading) + '°)</span></li>';
            }
            motion += '<li><i class="bi bi-clock" aria-hidden="true"></i><span>Ultima actualizare<small data-dl-age="long">' + esc(age === null ? 'necunoscută' : agoLong(age)) + '</small></span></li>';
            var sectionMotion = '<div class="dispatcher-live-section">'
                + '<h3 class="dispatcher-live-section-title">Viteză &amp; direcție</h3>'
                + speedHtml + '<ul class="dispatcher-live-motion">' + motion + '</ul></div>';

            return '<div class="dispatcher-live-detail-grid">' + sectionVehicle + sectionDriver + sectionMap + sectionTrip + sectionMotion + '</div>';
        }

        function rowHtml(vehicle) {
            var expanded = state.expandedKey === vehicle.key;
            var id = rowId(vehicle);
            var age = ageSeconds(vehicle);
            var driver = vehicle.driver;
            var driverSecondary = driverSourceLabel(driver);

            var vehicleCell = '<div class="dispatcher-live-vehicle">'
                + '<button type="button" class="dispatcher-live-chevron" data-dl-toggle-row="' + esc(vehicle.key) + '" aria-expanded="' + (expanded ? 'true' : 'false') + '" aria-controls="' + id + '-detail" aria-label="' + (expanded ? 'Ascunde' : 'Arată') + ' detaliile pentru ' + esc(vehicle.plate) + '"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>'
                + vehiclePhoto(vehicle, 'thumb', 'dispatcher-live-thumb')
                + '<span class="dispatcher-live-dot" aria-hidden="true"></span>'
                + '<span class="dispatcher-live-plate-wrap"><span class="dispatcher-live-plate">' + esc(vehicle.plate || '—') + '</span>'
                + (vehicle.vehicle_label ? '<span class="dispatcher-live-sub">' + esc(vehicle.vehicle_label) + '</span>' : '')
                + '</span></div>';

            var driverCell = '<div class="dispatcher-live-person">' + avatar(driver)
                + '<span class="dispatcher-live-cell">'
                + (driver
                    ? '<span class="dispatcher-live-name">' + esc(driver.name) + '</span>' + (driverSecondary ? '<span class="dispatcher-live-sub">' + esc(driverSecondary) + '</span>' : '')
                    : '<span class="dispatcher-live-name is-unknown">Șofer neidentificat</span>')
                + '</span></div>';

            var positionCell = '<div class="dispatcher-live-position">'
                + '<i class="bi bi-geo-alt-fill dispatcher-live-pin" aria-hidden="true"></i>'
                + '<span class="dispatcher-live-cell">'
                + (vehicle.place
                    ? '<span class="dispatcher-live-place" title="' + esc(vehicle.place) + '">' + esc(vehicle.place) + '</span>'
                    : '<span class="dispatcher-live-place is-unknown">Locație indisponibilă</span>')
                + (age !== null ? '<span class="dispatcher-live-sub" data-dl-age="short">' + esc(agoShort(age)) + '</span>' : '')
                + '</span></div>';

            return '<div class="dispatcher-live-row' + (expanded ? ' is-expanded' : '') + '" id="' + id + '" role="row" data-dl-key="' + esc(vehicle.key) + '" data-state="' + esc(vehicle.state) + '">'
                + '<div class="dispatcher-live-row-main" data-dl-row-main>'
                + '<div class="dispatcher-live-cell dispatcher-live-col-vehicle" role="cell">' + vehicleCell + '</div>'
                + '<div class="dispatcher-live-cell dispatcher-live-col-driver" role="cell">' + driverCell + '</div>'
                + '<div class="dispatcher-live-cell dispatcher-live-col-position" role="cell">' + positionCell + '</div>'
                + '<div class="dispatcher-live-cell dispatcher-live-col-speed" role="cell">' + speedCell(vehicle) + '</div>'
                + '<div class="dispatcher-live-cell dispatcher-live-col-status" role="cell">' + statusPill(vehicle) + '</div>'
                + '<div class="dispatcher-live-cell dispatcher-live-col-trip" role="cell">' + tripCell(vehicle, expanded) + '</div>'
                + '<div class="dispatcher-live-cell dispatcher-live-col-actions" role="cell">' + actionsCell(vehicle) + '</div>'
                + '</div>'
                + '<div class="dispatcher-live-detail" id="' + id + '-detail" role="region" aria-label="Detalii ' + esc(vehicle.plate) + '"' + (expanded ? '' : ' hidden') + '>'
                + '<div class="dispatcher-live-detail-inner">' + (expanded ? detailHtml(vehicle) : '') + '</div>'
                + '</div>'
                + '</div>';
        }

        function render() {
            if (!state.data) { return; }
            if (panel.querySelector('.dropdown-menu.show')) {
                // Un meniu e deschis: redesenarea l-ar inchide sub cursor.
                state.pendingRender = true;
                return;
            }
            state.pendingRender = false;
            detachMap();

            var visible = state.vehicles.filter(function (v) { return matchesFilter(v) && matchesSearch(v); });
            if (state.expandedKey !== null && !state.byKey[state.expandedKey]) { state.expandedKey = null; }

            rowsEl.innerHTML = visible.map(rowHtml).join('');

            if (!state.vehicles.length) {
                emptyEl.textContent = 'Niciun vehicul în mișcare acum.';
                emptyEl.classList.remove('d-none');
            } else if (!visible.length) {
                emptyEl.textContent = 'Niciun vehicul nu corespunde filtrului sau căutării.';
                emptyEl.classList.remove('d-none');
            } else {
                emptyEl.classList.add('d-none');
            }

            attachMap();
        }

        function renderCounters() {
            var counts = state.data.counts || {};
            countEl.textContent = String(counts.active || 0);
            ['active', 'with_trip', 'without_trip', 'stationary'].forEach(function (key) {
                var el = panel.querySelector('[data-dl-counter="' + key + '"]');
                if (el) { el.textContent = String(counts[key] || 0); }
            });
        }

        function applyFilter(filter) {
            state.filter = filter;
            if (selectEl) { selectEl.value = filter; }
            panel.querySelectorAll('[data-dl-filter]').forEach(function (chip) {
                chip.setAttribute('aria-pressed', chip.getAttribute('data-dl-filter') === filter ? 'true' : 'false');
            });
            render();
        }

        // ------------------------------------------------------------ expandare
        function toggleRow(key) {
            var rowEl = rowsEl.querySelector('[data-dl-key="' + cssEscape(key) + '"]');
            var previousKey = state.expandedKey;
            state.expandedKey = previousKey === key ? null : key;

            // Doar randurile afectate se redeseneaza (fara reincarcare de date).
            detachMap();
            [previousKey, state.expandedKey].forEach(function (k) {
                if (k === null || !state.byKey[k]) { return; }
                var el = rowsEl.querySelector('[data-dl-key="' + cssEscape(k) + '"]');
                if (el) { el.outerHTML = rowHtml(state.byKey[k]); }
            });
            attachMap();

            if (state.expandedKey !== null) {
                var detail = document.getElementById(rowId(state.byKey[state.expandedKey]) + '-detail');
                // Tranzitia de inaltime porneste din starea inchisa.
                if (detail) {
                    var expandedRow = detail.parentElement;
                    expandedRow.classList.remove('is-expanded');
                    void expandedRow.offsetWidth;
                    expandedRow.classList.add('is-expanded');
                }
            }
            if (rowEl && state.expandedKey === null) {
                var chevron = rowsEl.querySelector('[data-dl-key="' + cssEscape(key) + '"] .dispatcher-live-chevron');
                if (chevron) { chevron.focus({ preventScroll: true }); }
            }
        }

        function cssEscape(value) {
            return window.CSS && typeof window.CSS.escape === 'function'
                ? window.CSS.escape(String(value))
                : String(value).replace(/["\\]/g, '\\$&');
        }

        // ------------------------------------------------------------ harta (lenesa)
        var leafletPromise = null;
        var mapState = { el: null, map: null, marker: null };

        function loadLeaflet() {
            if (window.L && typeof window.L.map === 'function') { return Promise.resolve(window.L); }
            if (leafletPromise) { return leafletPromise; }
            leafletPromise = new Promise(function (resolve, reject) {
                if (!document.querySelector('link[href="' + LEAFLET_CSS + '"]')) {
                    var link = document.createElement('link');
                    link.rel = 'stylesheet';
                    link.href = LEAFLET_CSS;
                    link.integrity = LEAFLET_CSS_SRI;
                    link.crossOrigin = '';
                    document.head.appendChild(link);
                }
                var script = document.createElement('script');
                script.src = LEAFLET_JS;
                script.integrity = LEAFLET_JS_SRI;
                script.crossOrigin = '';
                script.onload = function () { resolve(window.L); };
                script.onerror = function () { leafletPromise = null; reject(new Error('leaflet')); };
                document.head.appendChild(script);
            });
            return leafletPromise;
        }

        function detachMap() {
            if (mapState.el && mapState.el.parentNode) {
                mapState.el.parentNode.removeChild(mapState.el);
            }
        }

        function attachMap() {
            if (state.expandedKey === null) { return; }
            var vehicle = state.byKey[state.expandedKey];
            var slot = rowsEl.querySelector('[data-dl-key="' + cssEscape(state.expandedKey) + '"] [data-dl-map-slot]');
            if (!vehicle || !slot || vehicle.latitude === null || vehicle.longitude === null) { return; }

            loadLeaflet().then(function (L) {
                // Randul poate fi fost inchis intre timp.
                if (state.expandedKey !== vehicle.key || !slot.isConnected) { return; }
                if (!mapState.el) {
                    mapState.el = document.createElement('div');
                    mapState.el.className = 'dispatcher-live-map-canvas';
                }
                slot.appendChild(mapState.el);
                var latLng = [vehicle.latitude, vehicle.longitude];
                if (!mapState.map) {
                    mapState.map = L.map(mapState.el, { zoomControl: false, attributionControl: true, scrollWheelZoom: false });
                    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        maxZoom: 19,
                        attribution: '&copy; OpenStreetMap'
                    }).addTo(mapState.map);
                }
                var icon = L.divIcon({
                    className: '',
                    html: '<div class="dispatcher-live-map-marker dispatcher-live-map-marker--' + esc(vehicle.state) + '"></div>',
                    iconSize: [22, 22],
                    iconAnchor: [11, 22]
                });
                if (mapState.marker) {
                    mapState.marker.setLatLng(latLng).setIcon(icon);
                } else {
                    mapState.marker = L.marker(latLng, { icon: icon, keyboard: false }).addTo(mapState.map);
                }
                mapState.map.invalidateSize(false);
                mapState.map.setView(latLng, 12, { animate: false });
                // Dupa tranzitia de deschidere dimensiunea finala e cunoscuta.
                setTimeout(function () {
                    if (mapState.map) {
                        mapState.map.invalidateSize(false);
                        mapState.map.setView(latLng, 12, { animate: false });
                    }
                }, 260);
            }).catch(function () {
                if (slot.isConnected) {
                    slot.innerHTML = '<div class="dispatcher-live-map-empty"><i class="bi bi-map" aria-hidden="true"></i>Harta nu a putut fi încărcată</div>';
                }
            });
        }

        // ------------------------------------------------------------ date
        function setUpdatedLabel(text, isError) {
            if (!updatedEl) { return; }
            updatedEl.textContent = text;
            updatedEl.classList.toggle('is-error', !!isError);
        }

        function refresh(manual) {
            if (state.loading || (!manual && document.hidden)) { return; }
            state.loading = true;
            if (refreshBtn) { refreshBtn.classList.add('is-loading'); }

            fetch(endpoint, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (!data || !data.success) { throw new Error('payload'); }
                    // Panoul apare doar daca SAS e configurat.
                    if (data.credentials === false) { panel.classList.add('d-none'); return; }
                    panel.classList.remove('d-none');
                    state.everShown = true;

                    state.data = data;
                    state.vehicles = Array.isArray(data.vehicles) ? data.vehicles : [];
                    state.byKey = {};
                    state.vehicles.forEach(function (v) { state.byKey[v.key] = v; });
                    if (typeof data.server_ts === 'number') {
                        state.clockOffset = data.server_ts - Math.floor(Date.now() / 1000);
                    }

                    setUpdatedLabel(data.fetched_at ? 'Actualizat ' + String(data.fetched_at).substring(11, 16) : '', false);
                    if (errorEl) {
                        errorEl.textContent = data.error ? 'GPS indisponibil momentan — se afișează ultimele poziții cunoscute.' : '';
                        errorEl.classList.toggle('d-none', !data.error);
                    }
                    renderCounters();
                    render();
                })
                .catch(function () {
                    if (state.everShown) { setUpdatedLabel('Actualizare eșuată, se reîncearcă...', true); }
                })
                .finally(function () {
                    state.loading = false;
                    if (refreshBtn) { refreshBtn.classList.remove('is-loading'); }
                });
        }

        // "acum X sec" avanseaza local intre refresh-uri, fara redesenare.
        function tick() {
            if (document.hidden || !state.data) { return; }
            rowsEl.querySelectorAll('[data-dl-key]').forEach(function (rowEl) {
                var vehicle = state.byKey[rowEl.getAttribute('data-dl-key')];
                if (!vehicle) { return; }
                var age = ageSeconds(vehicle);
                if (age === null) { return; }
                rowEl.querySelectorAll('[data-dl-age]').forEach(function (el) {
                    el.textContent = el.getAttribute('data-dl-age') === 'long' ? agoLong(age) : agoShort(age);
                });
            });
        }

        // ------------------------------------------------------------ "Adauga cursa"
        // Precompleteaza formularul existent in ordinea ceruta de cascada lui:
        // beneficiar -> tip transport -> vehicul -> sofer. Sursa principala e
        // Configurare Transport (window.dispecerVehicleCombos); ultima cursa a
        // vehiculului doar departajeaza, respectiv e ultima solutie.
        function setSelectValue(select, value) {
            if (!(select instanceof HTMLSelectElement) || value === null || value === undefined || value === '') { return false; }
            var target = String(value);
            var hasOption = Array.prototype.some.call(select.options, function (option) {
                return option.value === target && !option.disabled;
            });
            if (!hasOption) { return false; }
            if (select.value !== target) {
                select.value = target;
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }
            return true;
        }

        function prefillAddForm(vehicle) {
            var formEl = document.getElementById('add-race-form');
            if (!formEl || !vehicle || !vehicle.local_vehicle_id) { return; }
            var beneficiarEl = document.getElementById('race_beneficiar_id');
            var tipEl = document.getElementById('race_tip_transport');
            var vehicleEl = document.getElementById('race_vehicle_id');
            var driverEl = document.getElementById('race_driver_id');

            var vehicleId = String(vehicle.local_vehicle_id);
            var prefill = vehicle.prefill || {};
            var lastBen = prefill.beneficiar_id ? String(prefill.beneficiar_id) : '';
            var lastTip = prefill.tip_transport ? String(prefill.tip_transport) : '';
            var combos = typeof window.dispecerVehicleCombos === 'function' ? window.dispecerVehicleCombos(vehicleId) : [];

            var chosen = null;
            if (combos.length) {
                chosen = combos.find(function (c) { return c.beneficiar_id === lastBen && c.tip_transport === lastTip; })
                    || combos.find(function (c) { return c.beneficiar_id === lastBen; })
                    || combos.find(function (c) { return c.tip_transport === lastTip; })
                    || combos[0];
            }

            setSelectValue(beneficiarEl, chosen ? chosen.beneficiar_id : lastBen);
            setSelectValue(tipEl, chosen ? chosen.tip_transport : lastTip);
            var vehicleSet = setSelectValue(vehicleEl, vehicleId);
            if (!vehicleSet && setSelectValue(vehicleEl, '__show_all_vehicles__')) {
                // Vehicul neconfigurat pe combinatie: formularul isi afiseaza
                // dialogul nativ "Vehicul neconfigurat pe ruta".
                vehicleSet = setSelectValue(vehicleEl, vehicleId);
            }
            if (vehicleSet && driverEl instanceof HTMLSelectElement) {
                var driverId = prefill.driver_id ? String(prefill.driver_id) : '';
                if (!setSelectValue(driverEl, driverId)) {
                    var realOptions = Array.prototype.filter.call(driverEl.options, function (option) {
                        return option.value !== '' && !option.disabled && option.value.indexOf('__') !== 0;
                    });
                    if (realOptions.length === 1) { setSelectValue(driverEl, realOptions[0].value); }
                }
            }

            formEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
            formEl.classList.remove('dispatcher-live-form-flash');
            void formEl.offsetWidth;
            formEl.classList.add('dispatcher-live-form-flash');
        }

        // ------------------------------------------------------------ evenimente (o singura data)
        rowsEl.addEventListener('click', function (event) {
            var target = event.target;
            var addEl = target.closest('[data-dl-add]');
            if (addEl) {
                prefillAddForm(state.byKey[addEl.getAttribute('data-dl-add')]);
                return;
            }
            var toggleRowEl = target.closest('[data-dl-toggle-row]');
            if (toggleRowEl) {
                toggleRow(toggleRowEl.getAttribute('data-dl-toggle-row'));
                return;
            }
            var copyEl = target.closest('[data-dl-copy]');
            if (copyEl) {
                var text = copyEl.getAttribute('data-dl-copy') || '';
                if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).catch(function () {}); }
                return;
            }
            // Click pe zona libera a randului = expandare (nu pe linkuri/butoane/meniuri).
            if (target.closest('a, button, input, select, .dropdown-menu')) { return; }
            var mainEl = target.closest('[data-dl-row-main]');
            if (mainEl) {
                var rowEl = mainEl.closest('[data-dl-key]');
                if (rowEl) { toggleRow(rowEl.getAttribute('data-dl-key')); }
            }
        });

        // Poza lipsa pe disc -> placeholder, fara <img> rupt.
        rowsEl.addEventListener('error', function (event) {
            var img = event.target;
            if (!(img instanceof HTMLImageElement) || !img.hasAttribute('data-dl-img')) { return; }
            var holder = img.parentElement;
            var fallbackInitials = img.getAttribute('data-dl-initials');
            if (!holder) { return; }
            if (fallbackInitials !== null) {
                holder.textContent = fallbackInitials;
            } else {
                holder.innerHTML = '<i class="bi bi-truck" aria-hidden="true"></i>';
            }
        }, true);

        rowsEl.addEventListener('hidden.bs.dropdown', function () {
            if (state.pendingRender) { render(); }
        });

        panel.querySelectorAll('[data-dl-filter]').forEach(function (chip) {
            chip.addEventListener('click', function () {
                var filter = chip.getAttribute('data-dl-filter');
                applyFilter(state.filter === filter && filter !== 'all' ? 'all' : filter);
            });
        });

        if (selectEl) {
            selectEl.addEventListener('change', function () { applyFilter(selectEl.value || 'all'); });
        }

        if (searchEl) {
            var searchTimer = null;
            searchEl.addEventListener('input', function () {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(function () {
                    state.search = normalize(searchEl.value);
                    render();
                }, 120);
            });
        }

        if (refreshBtn) {
            refreshBtn.addEventListener('click', function () { refresh(true); });
        }

        if (toggleEl) {
            var applyCollapsed = function (collapsed) {
                panel.classList.toggle('is-collapsed', collapsed);
                toggleEl.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            };
            // Starea pliat/depliat se pastreaza per browser (aceeasi cheie ca inainte).
            try { applyCollapsed(localStorage.getItem('dlgCollapsed') === '1'); } catch (e) { /* stocare indisponibila */ }
            toggleEl.addEventListener('click', function () {
                var willCollapse = !panel.classList.contains('is-collapsed');
                applyCollapsed(willCollapse);
                try { localStorage.setItem('dlgCollapsed', willCollapse ? '1' : '0'); } catch (e) { /* stocare indisponibila */ }
                if (!willCollapse && mapState.map) { setTimeout(function () { mapState.map.invalidateSize(false); }, 50); }
            });
        }

        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) { refresh(false); }
        });

        // Prima incarcare se face oricum (si intr-un tab deschis in fundal);
        // ciclurile urmatoare se opresc cat timp tab-ul e ascuns.
        refresh(true);
        setInterval(function () { refresh(false); }, REFRESH_MS);
        setInterval(tick, TICK_MS);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
